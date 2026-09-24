<?php
/* Copyright (C) 2026 IT-Tabelander <https://it.tabelander.co.at>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    class/portal/vereine.controller.class.php
 * \ingroup vereine
 * \brief   The page "My association" in Dolibarr's web portal (#25, #257, #258): documents and statutes, meetings with motions, ballots, events, consents, own data and accounts.
 *
 * Loaded by Dolibarr's web portal through the hook initController (Dolibarr 23 and later). It holds no logic
 * of its own: documents come from VereinePublications, meetings from VereineMeetingPortal, ballots from
 * VereineBallots, as for an application through the API. A vote here uses the same voting right; one cast
 * through an application cannot be cast here again.
 */

dol_include_once('/vereine/class/vereineportal.class.php');

/**
 * The page of the association in the web portal.
 */
class VereinePortalController extends Controller
{
	/**
	 * @var int The member the portal acts for
	 */
	private $memberId = 0;

	/**
	 * @var string[] Abilities the association switched on for the portal
	 */
	private $abilities = array();

	/**
	 * Only a member logged in, and only with at least one ability switched on.
	 *
	 * @return bool
	 */
	public function checkAccess()
	{
		$context = Context::getInstance();
		$this->memberId = isModEnabled('vereine') && !empty($context->logged_member) && (int) $context->logged_member->id > 0 ? (int) $context->logged_member->id : 0;
		$this->abilities = VereinePortal::capabilities();
		$this->accessRight = $this->memberId > 0 && $this->abilities !== array();
		return parent::checkAccess();
	}

	/**
	 * What the member does on the page: a PDF, an answer to an invitation, a vote.
	 *
	 * @return int Return integer < 0 on error, > 0 on success
	 */
	public function action()
	{
		global $langs;

		$context = Context::getInstance();
		if (!$context->controllerInstance->checkAccess()) {
			return -1;
		}
		$langs->loadLangs(array('members', 'companies', 'vereine@vereine'));
		$context->title = $langs->trans('VereinePortalTitle');
		$context->desc = $langs->trans('VereinePortalDesc');
		$context->menu_active[] = 'vereine';
		$action = GETPOST('action', 'aZ09');
		if ($action === 'pdf' && $this->allows('documents')) {
			$this->pdf(GETPOSTINT('document'));
		}
		if ($action === 'statute' && $this->allows('documents')) {
			$this->statutePdf(GETPOSTINT('statute'));
		}
		$done = null;
		if ($action === 'respond' && $this->allows('meetings')) {
			dol_include_once('/vereine/class/vereinemeetingportal.class.php');
			$portal = new VereineMeetingPortal($this->db);
			$done = $portal->respond($this->memberId, GETPOSTINT('meeting'), GETPOST('response', 'aZ09'), VereinePortal::CLIENT,
				dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver')) > 0 ? '' : implode(' ', $portal->errors);
		} elseif ($action === 'vote' && $this->allows('votes')) {
			dol_include_once('/vereine/class/vereineballots.class.php');
			$ballots = new VereineBallots($this->db);
			$result = $ballots->cast(GETPOSTINT('ballot'), GETPOSTINT('right'), GETPOST('option', 'aZ09'), $this->memberId, VereineBallotRules::CHANNEL_APP, VereinePortal::CLIENT,
				'', $this->actor());
			$done = $result > 0 ? '' : $langs->trans('VereineBallotRefused_'.($ballots->reason !== '' ? $ballots->reason : 'not_found'));
		} elseif ($action === 'motion' && $this->allows('meetings')) {
			dol_include_once('/vereine/class/vereinemeetingportal.class.php');
			$portal = new VereineMeetingPortal($this->db);
			$motion = $portal->submitMotion($this->memberId, GETPOSTINT('meeting'), array('external_id' => self::requestId(), 'title' => GETPOST('title', 'alphanohtml'),
				'text' => GETPOST('text', 'alphanohtml')), VereinePortal::CLIENT, dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'));
			$done = $motion !== null ? '' : $langs->trans('VereinePortalRefused');
		} elseif ($action === 'account' && $this->allows('accounts')) {
			// Set in the portal is set by the member, not checked at the network: only an application can confirm (#258).
			dol_include_once('/vereine/class/vereinesocial.class.php');
			$social = new VereineSocial($this->db);
			$done = $social->setAccount($this->member(), GETPOST('network', 'aZ09'), GETPOST('handle', 'alphanohtml'), false, '', VereinePortal::CLIENT, $this->actor()) > 0
				? '' : $langs->trans('VereinePortalRefused');
		} elseif ($action === 'consent' && $this->allows('consents')) {
			// The same decision as through a website, with the portal as its proof (#257).
			dol_include_once('/vereine/class/vereineconsents.class.php');
			$consents = new VereineConsents($this->db);
			$texts = array();
			foreach ($consents->currentTexts() as $code => $text) {
				$texts[$code] = (int) $text['version'];
			}
			$checked = VereineConsentRules::decision(array('code' => GETPOST('code', 'aZ09'), 'decision' => GETPOST('decision', 'aZ09'), 'version' => GETPOSTINT('version'),
				'granted_at' => dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S', 'tzserver'), 'form' => $langs->transnoentitiesnoconv('VereinePortalProof'), 'reference' => self::requestId()),
				$texts, VereineConsentRules::current($consents->history($this->memberId)));
			$done = !$checked['errors'] && $consents->decide($this->memberId, $checked['decision'], $this->actor()) !== null ? '' : $langs->trans('VereinePortalRefused');
		} elseif ($action === 'change' && $this->allows('profile')) {
			dol_include_once('/vereine/class/vereineprofiles.class.php');
			$changes = array();
			foreach (array_keys(VereineProfileRules::FIELDS) as $field) {
				$changes[$field] = GETPOST($field, 'alphanohtml');
			}
			$profiles = new VereineProfiles($this->db);
			$request = $profiles->submitChange($this->member(), array('external_id' => self::requestId(), 'version' => GETPOST('version', 'alphanohtml'), 'changes' => $changes),
				VereinePortal::CLIENT, $this->actor());
			$done = $request !== null ? '' : $langs->trans(in_array('conflict', $profiles->errors, true) ? 'VereinePortalConflict' : 'VereinePortalRefused');
		} elseif ($action === 'website' && $this->allows('profile')) {
			// The member keeps the own website profile; the website shows it only with the consent (#260).
			dol_include_once('/vereine/class/vereinewebsiteprofiles.class.php');
			$fields = array();
			foreach (array_keys(VereineWebsiteProfileRules::FIELDS) as $field) {
				$fields[$field] = GETPOST($field, 'alphanohtml');
			}
			$done = (new VereineWebsiteProfiles($this->db))->change($this->member(), $fields, $this->actor()) > 0 ? '' : $langs->trans('VereinePortalRefused');
		} elseif ($action === 'exit' && $this->allows('profile') && GETPOST('confirm', 'aZ09') === '1') {
			dol_include_once('/vereine/class/vereineprofiles.class.php');
			$profiles = new VereineProfiles($this->db);
			$notice = $profiles->submitExit($this->member(), array('external_id' => self::requestId(), 'wished_last_day' => GETPOST('wished_last_day', 'alphanohtml')),
				VereinePortal::CLIENT, dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'), $this->actor());
			$done = $notice !== null ? '' : $langs->trans('VereinePortalRefused');
		} elseif ($action === 'shift' && $this->allows('events')) {
			dol_include_once('/vereine/class/vereineeventportal.class.php');
			$portal = new VereineEventPortal($this->db);
			$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
			$result = GETPOST('do', 'aZ09') === 'withdraw'
				? $portal->withdraw($this->memberId, GETPOSTINT('event'), GETPOSTINT('shift'), $today, $this->actor())
				: $portal->ask($this->memberId, GETPOSTINT('event'), GETPOSTINT('shift'), VereinePortal::CLIENT, $today, $this->actor());
			$done = $result > 0 ? '' : $langs->trans('VereinePortalRefused');
		}
		if ($done !== null) {
			$context->setEventMessages($done === '' ? $langs->trans('VereinePortalSaved') : $done, null, $done === '' ? 'mesgs' : 'errors');
			header('Location: '.$context->getControllerUrl('vereine', '', false));
			exit;
		}
		return 1;
	}

	/**
	 * The page: the documents, meetings and ballots the member may see, as far as the association switched them on.
	 *
	 * @return void
	 */
	public function display()
	{
		$context = Context::getInstance();
		if (!$context->controllerInstance->checkAccess()) {
			$this->display404();
			return;
		}
		$this->loadTemplate('header');
		$this->loadTemplate('menu');
		$this->loadTemplate('hero-header-banner');
		print '<main class="container" data-vereine-portal="'.$this->memberId.'">';
		if ($this->allows('documents')) {
			$this->documents();
			$this->statutes();
		}
		if ($this->allows('meetings')) {
			$this->meetings();
		}
		if ($this->allows('votes')) {
			$this->ballots();
		}
		if ($this->allows('events')) {
			$this->events();
		}
		if ($this->allows('consents')) {
			$this->consents();
		}
		if ($this->allows('profile')) {
			$this->profile();
		}
		if ($this->allows('accounts')) {
			$this->accounts();
		}
		print '</main>';
		$this->loadTemplate('footer');
	}

	/**
	 * Whether the association switched an ability on for the portal.
	 *
	 * @param string $ability Ability
	 * @return bool
	 */
	private function allows($ability)
	{
		return in_array($ability, $this->abilities, true);
	}

	/**
	 * Who acts in Dolibarr for the portal: the user the portal is set up with.
	 *
	 * @return User
	 */
	private function actor()
	{
		global $user;

		$context = Context::getInstance();
		return !empty($context->logged_user) ? $context->logged_user : $user;
	}

	/**
	 * The documents published for the member: the public ones, those for members while a member, those for the member alone.
	 *
	 * @return void
	 */
	private function documents()
	{
		global $langs;

		dol_include_once('/vereine/class/vereinepublications.class.php');
		$context = Context::getInstance();
		$publications = new VereinePublications($this->db);
		$catalog = $publications->catalog($publications->actorFor($this->memberId));
		print '<article data-vereine-portal-section="documents"><header><strong>'.$langs->trans('VereinePortalDocuments').'</strong></header>';
		if (!$catalog) {
			print '<p>'.$langs->trans('VereinePortalNothing').'</p>';
		}
		foreach ($catalog as $document) {
			print '<p data-vereine-portal-document="'.$document['document_id'].'"><a href="'.$context->getControllerUrl('vereine', array('action' => 'pdf', 'document' => $document['document_id'])).'">'
				.dol_escape_htmltag($document['title']).'</a> <small>'.dol_escape_htmltag($langs->trans('VereineArchiveKind_'.$document['kind']).' · '.$document['code']).'</small></p>';
		}
		print '</article>';
	}

	/**
	 * The meetings the member was invited to, with the member's answer.
	 *
	 * @return void
	 */
	private function meetings()
	{
		global $langs;

		dol_include_once('/vereine/class/vereinemeetingportal.class.php');
		require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
		$context = Context::getInstance();
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$meetings = (new VereineMeetingPortal($this->db))->meetingsFor($this->memberId);
		print '<article data-vereine-portal-section="meetings"><header><strong>'.$langs->trans('VereinePortalMeetings').'</strong></header>';
		if (!$meetings) {
			print '<p>'.$langs->trans('VereinePortalNothing').'</p>';
		}
		foreach ($meetings as $meeting) {
			print '<div data-vereine-portal-meeting="'.$meeting['id'].'" data-vereine-portal-response="'.dol_escape_htmltag($meeting['response']).'"><p><strong>'
				.dol_escape_htmltag($meeting['title']).'</strong> · '.dol_print_date(dol_stringtotime($meeting['day']), 'day').' '.dol_escape_htmltag($meeting['time']).'</p>';
			if ($meeting['status'] === 'invited' && $meeting['day'] >= $today) {
				print '<form method="POST" action="'.$context->getControllerUrl('vereine', '', false).'" name="vereineportalrespond'.$meeting['id'].'"><input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="respond"><input type="hidden" name="meeting" value="'.$meeting['id'].'"><select name="response">';
				foreach (array('yes', 'no', 'maybe') as $response) {
					print '<option value="'.$response.'"'.($meeting['response'] === $response ? ' selected' : '').'>'.$langs->trans('VereinePortalResponse_'.$response).'</option>';
				}
				print '</select><button type="submit">'.$langs->trans('VereinePortalAnswer').'</button></form>';
			}
			foreach ($meeting['motions'] as $motion) {
				print '<p data-vereine-portal-motion="'.dol_escape_htmltag($motion['status']).'">'.$langs->trans('VereineMotion').': '.dol_escape_htmltag($motion['title']).' · '
					.$langs->trans($motion['status'] === 'received' ? 'VereineMotionReceived' : 'VereineMotionState_'.$motion['status']).($motion['late'] ? ' · '.$langs->trans('VereineMotionLate') : '').'</p>';
			}
			if ($meeting['kind'] !== 'board' && $meeting['status'] === 'invited' && $meeting['day'] >= $today) {
				// A motion for the agenda of a general assembly, with the deadline of the statutes (#258).
				print '<details><summary>'.$langs->trans('VereinePortalMotion').($meeting['motion_deadline'] !== '' ? ' · '.$langs->trans('VereinePortalMotionBy', dol_escape_htmltag($meeting['motion_deadline'])) : '')
					.'</summary><form method="POST" action="'.$context->getControllerUrl('vereine', '', false).'" name="vereineportalmotion'.$meeting['id'].'"><input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="motion"><input type="hidden" name="meeting" value="'.$meeting['id'].'">';
				print '<label>'.$langs->trans('VereinePortalMotionTitle').'<input type="text" name="title" maxlength="255" required></label>';
				print '<label>'.$langs->trans('VereinePortalMotionText').'<textarea name="text" rows="4"></textarea></label>';
				print '<button type="submit">'.$langs->trans('VereinePortalMotionSend').'</button></form></details>';
			}
			print '</div>';
		}
		print '</article>';
	}

	/**
	 * The ballots of the assemblies the member was invited to, with the voting rights the member may use now.
	 *
	 * @return void
	 */
	private function ballots()
	{
		global $langs;

		dol_include_once('/vereine/class/vereineballots.class.php');
		$context = Context::getInstance();
		$ballots = (new VereineBallots($this->db))->forMember($this->memberId);
		print '<article data-vereine-portal-section="votes"><header><strong>'.$langs->trans('VereinePortalBallots').'</strong></header>';
		if (!$ballots) {
			print '<p>'.$langs->trans('VereinePortalNothing').'</p>';
		}
		foreach ($ballots as $ballot) {
			print '<div data-vereine-portal-ballot="'.$ballot['id'].'" data-vereine-portal-ballot-status="'.$ballot['status'].'"><p><strong>'.dol_escape_htmltag($ballot['question']).'</strong> · '
				.$langs->trans('VereineBallotStatus_'.$ballot['status']).'</p>';
			$labels = array_column($ballot['options'], 'label', 'code');
			foreach ($ballot['rights'] as $right) {
				$for = $right['for'] === 'proxy' ? $langs->trans('VereinePortalFor', dol_escape_htmltag($right['name'])) : $langs->trans('VereinePortalOwn');
				if ($right['state'] === 'used') {
					print '<p data-vereine-portal-right="'.$right['right_id'].'" data-vereine-portal-right-state="used">'.$for.': '.dol_escape_htmltag(isset($labels[$right['option']]) ? $labels[$right['option']] : '').'</p>';
				} elseif ($right['state'] === 'open' && $ballot['status'] === 'open') {
					print '<form method="POST" action="'.$context->getControllerUrl('vereine', '', false).'" name="vereineportalvote'.$right['right_id'].'"><input type="hidden" name="token" value="'.newToken().'">';
					print '<input type="hidden" name="action" value="vote"><input type="hidden" name="ballot" value="'.$ballot['id'].'"><input type="hidden" name="right" value="'.$right['right_id'].'">';
					print '<label>'.$for.' <select name="option">';
					foreach ($ballot['options'] as $option) {
						print '<option value="'.dol_escape_htmltag($option['code']).'">'.dol_escape_htmltag($option['label']).'</option>';
					}
					print '</select></label><button type="submit">'.$langs->trans('VereinePortalVote').'</button></form>';
				} elseif ($right['state'] === 'none') {
					print '<p data-vereine-portal-right-state="none">'.$langs->trans('VereinePortalNoRight_'.$right['reason']).'</p>';
				}
			}
			if (!empty($ballot['result'])) {
				print '<p data-vereine-portal-result="'.dol_escape_htmltag($ballot['result']['outcome']).'">'.$langs->trans('VereineBallotOutcome_'.$ballot['result']['outcome']).'</p>';
			}
			print '</div>';
		}
		print '</article>';
	}

	/**
	 * The member's consents: the state per purpose; agree with the full text of the version in force, withdraw with one click (#257).
	 *
	 * @return void
	 */
	private function consents()
	{
		global $langs;

		dol_include_once('/vereine/class/vereineconsents.class.php');
		$context = Context::getInstance();
		$consents = new VereineConsents($this->db);
		$texts = $consents->currentTexts();
		print '<article data-vereine-portal-section="consents"><header><strong>'.$langs->trans('VereinePortalConsents').'</strong></header>';
		$state = $consents->stateFor($this->memberId);
		if (!$state) {
			print '<p>'.$langs->trans('VereinePortalNothing').'</p>';
		}
		foreach ($state as $purpose) {
			print '<div data-vereine-portal-consent="'.dol_escape_htmltag($purpose['code']).'" data-vereine-portal-consent-state="'.$purpose['state'].'"><p><strong>'
				.dol_escape_htmltag($purpose['label']).'</strong> · '.$langs->trans('VereinePortalConsentState_'.$purpose['state']).'</p>';
			if ($purpose['can_give'] && isset($texts[$purpose['code']])) {
				$text = $texts[$purpose['code']];
				print '<details><summary>'.$langs->trans('VereinePortalConsentRead').'</summary><p>'.nl2br(dol_escape_htmltag((string) $text['text'])).'</p></details>';
				print '<form method="POST" action="'.$context->getControllerUrl('vereine', '', false).'" name="vereineportalgive'.dol_escape_htmltag($purpose['code']).'"><input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="consent"><input type="hidden" name="decision" value="given"><input type="hidden" name="code" value="'.dol_escape_htmltag($purpose['code']).'">';
				print '<input type="hidden" name="version" value="'.((int) $text['version']).'"><button type="submit">'.$langs->trans('VereinePortalConsentGive').'</button></form>';
			}
			if ($purpose['can_withdraw']) {
				print '<form method="POST" action="'.$context->getControllerUrl('vereine', '', false).'" name="vereineportalwithdraw'.dol_escape_htmltag($purpose['code']).'"><input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="consent"><input type="hidden" name="decision" value="withdrawn"><input type="hidden" name="code" value="'.dol_escape_htmltag($purpose['code']).'">';
				print '<button type="submit" class="secondary">'.$langs->trans('VereinePortalConsentWithdraw').'</button></form>';
			}
			print '</div>';
		}
		print '</article>';
	}

	/**
	 * The member's own data: the contact data, a change asked for, the requests so far, the notice of the exit (#257).
	 *
	 * @return void
	 */
	private function profile()
	{
		global $langs;

		dol_include_once('/vereine/class/vereineprofiles.class.php');
		$context = Context::getInstance();
		$profiles = new VereineProfiles($this->db);
		$member = $this->member();
		$profile = $profiles->profile($member);
		$self = $context->getControllerUrl('vereine', '', false);
		print '<article data-vereine-portal-section="profile" data-vereine-portal-profile-version="'.dol_escape_htmltag($profile['version']).'"><header><strong>'
			.$langs->trans('VereinePortalProfile').'</strong></header>';
		print '<p>'.dol_escape_htmltag(trim($profile['firstname'].' '.$profile['lastname'])).' · '.dol_escape_htmltag($profile['member_type']).'</p>';
		print '<form method="POST" action="'.$self.'" name="vereineportalprofile"><input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="change"><input type="hidden" name="version" value="'.dol_escape_htmltag($profile['version']).'">';
		foreach (VereineProfileRules::FIELDS as $field => $length) {
			print '<label>'.$langs->trans('VereineProfileField_'.$field).'<input type="text" name="'.$field.'" maxlength="'.((int) $length).'" value="'
				.dol_escape_htmltag((string) $profile[$field]).'"></label>';
		}
		print '<small>'.$langs->trans('VereinePortalProfileHint').'</small><button type="submit">'.$langs->trans('VereinePortalProfileSend').'</button></form>';
		$requests = $profiles->requests($this->memberId);
		if ($requests) {
			print '<p><strong>'.$langs->trans('VereinePortalRequests').'</strong></p>';
		}
		foreach ($requests as $request) {
			$what = $request['kind'] === 'exit' ? $langs->trans('VereinePortalExitTitle') : implode(', ', array_map(function ($field) use ($langs) {
				return $langs->trans('VereineProfileField_'.$field);
			}, array_keys((array) $request['changes'])));
			print '<p data-vereine-portal-request="'.dol_escape_htmltag($request['kind']).'" data-vereine-portal-request-status="'.dol_escape_htmltag($request['status']).'">'
				.dol_escape_htmltag($what).' · '.$langs->trans('VereinePortalRequest_'.$request['status'])
				.(!empty($request['reason']) ? ' · '.dol_escape_htmltag($request['reason']) : '').'</p>';
		}
		$this->websiteProfile($self);
		if ($profile['exit'] === null && $profile['status'] === 'active') {
			print '<details><summary>'.$langs->trans('VereinePortalExitTitle').'</summary>';
			print '<form method="POST" action="'.$self.'" name="vereineportalexit"><input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="exit"><label>'.$langs->trans('VereinePortalExitDay').'<input type="date" name="wished_last_day"></label>';
			print '<label><input type="checkbox" name="confirm" value="1" required> '.$langs->trans('VereinePortalExitConfirm').'</label>';
			print '<button type="submit" class="secondary">'.$langs->trans('VereinePortalExitSend').'</button></form></details>';
		} elseif ($profile['exit'] !== null) {
			print '<p data-vereine-portal-exit="'.dol_escape_htmltag($profile['exit']['last_day']).'">'.$langs->trans('VereinePortalExitPlanned', dol_escape_htmltag($profile['exit']['last_day'])).'</p>';
		}
		print '</article>';
	}

	/**
	 * The own website profile: gamertag, short text, games and platforms, and whether the website may show them (#260).
	 *
	 * @param string $self Address of the page
	 * @return void
	 */
	private function websiteProfile($self)
	{
		global $langs;

		dol_include_once('/vereine/class/vereinewebsiteprofiles.class.php');
		$view = (new VereineWebsiteProfiles($this->db))->ownView($this->member());
		print '<details data-vereine-portal-website="'.($view['given'] ? 'shown' : 'hidden').'"><summary>'.$langs->trans('VereinePortalWebsiteProfile').'</summary>';
		print '<p><small>'.$langs->trans($view['consent'] === '' ? 'VereinePortalWebsiteOff' : ($view['given'] ? 'VereinePortalWebsiteShown' : 'VereinePortalWebsiteHidden')).'</small></p>';
		print '<form method="POST" action="'.$self.'" name="vereineportalwebsite"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="website">';
		foreach (VereineWebsiteProfileRules::FIELDS as $field => $length) {
			$value = is_array($view[$field]) ? implode(', ', $view[$field]) : (string) $view[$field];
			print '<label>'.$langs->trans('VereineWebsiteProfile'.ucfirst($field));
			print $field === 'bio' ? '<textarea name="bio" rows="3" maxlength="'.((int) $length).'">'.dol_escape_htmltag($value).'</textarea>'
				: '<input type="text" name="'.$field.'" maxlength="'.((int) $length).'" value="'.dol_escape_htmltag($value).'">';
			print '</label>';
		}
		print '<button type="submit">'.$langs->trans('VereinePortalWebsiteSave').'</button></form></details>';
	}

	/**
	 * Events of the member with their helper shifts: ask for a shift, take back an unconfirmed request (#257).
	 *
	 * @return void
	 */
	private function events()
	{
		global $langs;

		dol_include_once('/vereine/class/vereineeventportal.class.php');
		$context = Context::getInstance();
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$events = (new VereineEventPortal($this->db))->events($today, $this->memberId);
		print '<article data-vereine-portal-section="events"><header><strong>'.$langs->trans('VereinePortalEvents').'</strong></header>';
		if (!$events) {
			print '<p>'.$langs->trans('VereinePortalNothing').'</p>';
		}
		foreach ($events as $event) {
			print '<div data-vereine-portal-event="'.$event['id'].'"><p><strong>'.dol_escape_htmltag($event['label']).'</strong> · '.dol_escape_htmltag($event['day'])
				.($event['place'] !== '' ? ' · '.dol_escape_htmltag($event['place']) : '').'</p>';
			foreach ($event['shifts'] as $shift) {
				print '<form method="POST" action="'.$context->getControllerUrl('vereine', '', false).'" name="vereineportalshift'.$shift['id'].'" data-vereine-portal-shift="'.$shift['id']
					.'" data-vereine-portal-shift-mine="'.dol_escape_htmltag($shift['mine']).'"><input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="shift"><input type="hidden" name="event" value="'.$event['id'].'"><input type="hidden" name="shift" value="'.$shift['id'].'">';
				print dol_escape_htmltag($shift['label'].' · '.$shift['start'].'–'.$shift['end']).' · '.$langs->trans('VereinePortalShiftPlaces', $shift['taken'], $shift['capacity']);
				if ($shift['mine'] === 'requested') {
					print ' <input type="hidden" name="do" value="withdraw"><button type="submit" class="secondary">'.$langs->trans('VereinePortalShiftWithdraw').'</button>';
				} elseif (in_array($shift['mine'], array('', 'cancelled'), true) && !$shift['full'] && $event['status'] !== 'cancelled') {
					print ' <input type="hidden" name="do" value="ask"><button type="submit">'.$langs->trans('VereinePortalShiftAsk').'</button>';
				} elseif ($shift['mine'] !== '') {
					print ' · '.$langs->trans('VereinePortalShift_'.$shift['mine']);
				}
				print '</form>';
			}
			print '</div>';
		}
		print '</article>';
	}

	/**
	 * The member the portal acts for, loaded as Dolibarr's member.
	 *
	 * @return Adherent
	 */
	private function member()
	{
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
		$member = new Adherent($this->db);
		$member->fetch($this->memberId);
		return $member;
	}

	/**
	 * An id of a request of the portal, so a request sent twice is recognised.
	 *
	 * @return string
	 */
	private static function requestId()
	{
		return VereinePortal::CLIENT.'-'.bin2hex(random_bytes(8));
	}

	/**
	 * The member's accounts at Discord, Twitch, YouTube & Co.: those the association asks for and the member's own; set, change, remove (#258).
	 *
	 * @return void
	 */
	private function accounts()
	{
		global $langs;

		dol_include_once('/vereine/class/vereinesocial.class.php');
		$context = Context::getInstance();
		$accounts = (new VereineSocial($this->db))->accounts($this->member());
		print '<article data-vereine-portal-section="accounts"><header><strong>'.$langs->trans('VereinePortalAccounts').'</strong></header>';
		print '<p><small>'.$langs->trans('VereinePortalAccountsHint').'</small></p>';
		if (!$accounts) {
			print '<p>'.$langs->trans('VereinePortalNothing').'</p>';
		}
		foreach ($accounts as $account) {
			print '<form method="POST" action="'.$context->getControllerUrl('vereine', '', false).'" name="vereineportalaccount'.dol_escape_htmltag($account['network'])
				.'" data-vereine-portal-account="'.dol_escape_htmltag($account['network']).'" data-vereine-portal-account-confirmed="'.($account['confirmed'] ? 1 : 0).'">';
			print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="account"><input type="hidden" name="network" value="'
				.dol_escape_htmltag($account['network']).'">';
			print '<label>'.dol_escape_htmltag($account['label']).($account['asked'] === 'required' ? ' *' : '').($account['confirmed'] ? ' · '.$langs->trans('VereinePortalAccountConfirmed') : '')
				.'<input type="text" name="handle" maxlength="128" value="'.dol_escape_htmltag($account['handle']).'"></label>';
			print '<button type="submit">'.$langs->trans('VereinePortalAccountSave').'</button></form>';
		}
		print '</article>';
	}

	/**
	 * The statutes as the association published them: the version in force and the archive, each as PDF (#258).
	 *
	 * @return void
	 */
	private function statutes()
	{
		global $langs;

		dol_include_once('/vereine/class/vereinestatutes.class.php');
		dol_include_once('/vereine/class/vereinepublications.class.php');
		$context = Context::getInstance();
		$actor = (new VereinePublications($this->db))->actorFor($this->memberId);
		$statutes = (new VereineStatutes($this->db))->published($actor, dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'));
		print '<article data-vereine-portal-section="statutes" data-vereine-portal-statutes="'.dol_escape_htmltag($statutes['state']).'"><header><strong>'
			.$langs->trans('VereinePortalStatutes').'</strong></header>';
		if (!$statutes['versions']) {
			print '<p>'.$langs->trans('VereinePortalNothing').'</p>';
		}
		foreach ($statutes['versions'] as $version) {
			$current = $statutes['current'] !== null && $statutes['current']['id'] === $version['id'];
			print '<p data-vereine-portal-statute="'.$version['id'].'"><a href="'.$context->getControllerUrl('vereine', array('action' => 'statute', 'statute' => $version['id'])).'">'
				.$langs->trans('VereinePortalStatuteVersion', $version['version'], dol_escape_htmltag($version['valid_from'])).'</a>'
				.($current ? ' · <strong>'.$langs->trans('VereinePortalStatuteCurrent').'</strong>' : '').'</p>';
		}
		print '</article>';
	}

	/**
	 * Hand out the PDF of a version of the statutes the member may read, checked against its checksum.
	 *
	 * @param int $id Version
	 * @return void
	 */
	private function statutePdf($id)
	{
		dol_include_once('/vereine/class/vereinestatutes.class.php');
		dol_include_once('/vereine/class/vereinepublications.class.php');
		$pdf = (new VereineStatutes($this->db))->publishedFile((int) $id, (new VereinePublications($this->db))->actorFor($this->memberId));
		if (!is_array($pdf)) {
			http_response_code($pdf === false ? 500 : 404);
			exit;
		}
		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="'.$pdf['filename'].'"');
		header('Content-Length: '.strlen($pdf['bytes']));
		print $pdf['bytes'];
		exit;
	}

	/**
	 * Hand out the PDF of a document the member may see, checked against the checksum of the association's files; nothing else.
	 *
	 * @param int $documentId Document
	 * @return void
	 */
	private function pdf($documentId)
	{
		dol_include_once('/vereine/class/vereinepublications.class.php');
		$publications = new VereinePublications($this->db);
		$pdf = $publications->file((int) $documentId, 0, $publications->actorFor($this->memberId));
		if (!is_array($pdf)) {
			http_response_code($pdf === false ? 500 : 404);
			exit;
		}
		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="'.$pdf['filename'].'"');
		header('Content-Length: '.strlen($pdf['bytes']));
		print $pdf['bytes'];
		exit;
	}
}
