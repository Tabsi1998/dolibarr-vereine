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
 * \brief   The page "My association" in Dolibarr's web portal (#25): documents, meetings and ballots of the member logged in.
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
		$langs->loadLangs(array('members', 'vereine@vereine'));
		$context->title = $langs->trans('VereinePortalTitle');
		$context->desc = $langs->trans('VereinePortalDesc');
		$context->menu_active[] = 'vereine';
		$action = GETPOST('action', 'aZ09');
		if ($action === 'pdf' && $this->allows('documents')) {
			$this->pdf(GETPOSTINT('document'));
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
		}
		if ($this->allows('meetings')) {
			$this->meetings();
		}
		if ($this->allows('votes')) {
			$this->ballots();
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
