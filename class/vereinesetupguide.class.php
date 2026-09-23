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
 * \file    class/vereinesetupguide.class.php
 * \ingroup vereine
 * \brief   The setup guide (#126): what a new association still has to set up, read from its data.
 *
 * Every step points at the page that already exists for it; the guide keeps no settings of its own
 * except which steps were left out, whether its hint is hidden, and that a test e-mail went out.
 */

require_once __DIR__.'/vereinesetupguiderules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * The setup guide.
 */
class VereineSetupGuide
{
	/** Steps left out on purpose, comma separated. */
	const SKIPPED = 'VEREINE_SETUP_SKIPPED';
	/** The hint on the overview and the setup is hidden. */
	const HIDDEN = 'VEREINE_SETUP_HIDDEN';
	/** Day a test e-mail of the guide went out. */
	const MAIL_TESTED = 'VEREINE_SETUP_MAIL_TESTED';

	/** Dolibarr modules the association cannot do without, with the language key of their name. */
	const REQUIRED_MODULES = array('adherent' => 'Members', 'societe' => 'ThirdParties', 'categorie' => 'Categories');
	/** Dolibarr modules that add to it, each with what it is for. */
	const OPTIONAL_MODULES = array('banque', 'facture', 'prelevement', 'agenda', 'mailing', 'don', 'api', 'webportal');

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * What the installation holds, for the rules to judge.
	 *
	 * @param string $today Today, YYYY-MM-DD
	 * @return array<string,mixed>
	 */
	public function facts($today)
	{
		global $conf, $mysoc;

		require_once __DIR__.'/vereineorganization.class.php';
		require_once __DIR__.'/vereinestatutes.class.php';
		require_once __DIR__.'/vereinefunctions.class.php';
		require_once __DIR__.'/vereinefunctionrules.class.php';
		require_once __DIR__.'/vereineconsents.class.php';
		require_once __DIR__.'/vereinesignatures.class.php';

		$entity = (int) $conf->entity;
		$organization = VereineOrganization::load($mysoc);
		$facts = array('name' => $organization['name'], 'town' => $organization['address']['town'], 'zvr' => $organization['register']['number'],
			'purpose' => trim((string) getDolGlobalString('VEREINE_PURPOSE')));

		$facts['modules_missing'] = array();
		foreach (array_keys(self::REQUIRED_MODULES) as $module) {
			if (!isModEnabled($module)) {
				$facts['modules_missing'][] = $module;
			}
		}
		$facts['modules_optional'] = array();
		foreach (self::OPTIONAL_MODULES as $module) {
			$facts['modules_optional'][$module] = isModEnabled($module);
		}

		$statutes = new VereineStatutes($this->db);
		$facts['statute_rules'] = $statutes->stored();
		$facts['statute_versions'] = count($statutes->versions());

		// The board: every function of the catalogue held, and somebody of it can log in to Dolibarr.
		$functions = new VereineFunctions($this->db);
		$missing = 0;
		foreach (VereineFunctionRules::check($functions->fetchAll(true), $functions->terms(), $today)['problems'] as $problem) {
			if ($problem['kind'] === VereineFunctionRules::PROBLEM_MISSING) {
				$missing++;
			}
		}
		$facts['functions_missing'] = $missing;
		$sql = "SELECT COUNT(DISTINCT u.rowid) as users FROM ".MAIN_DB_PREFIX."user as u INNER JOIN ".MAIN_DB_PREFIX."vereine_function_term as t ON t.fk_adherent = u.fk_member";
		$sql .= " WHERE u.statut = 1 AND t.entity = ".$entity." AND t.date_start <= '".$this->db->escape($today)."'";
		$sql .= " AND (t.date_end IS NULL OR t.date_end >= '".$this->db->escape($today)."')";
		$facts['board_users'] = $this->count($sql);

		$facts['member_types'] = $this->count("SELECT COUNT(*) as n FROM ".MAIN_DB_PREFIX."adherent_type WHERE statut = 1 AND entity IN (".getEntity('member_type').")");
		$facts['consents'] = count((new VereineConsents($this->db))->currentTexts());
		$facts['mail_tested'] = getDolGlobalString(self::MAIL_TESTED) !== '';
		$facts['mail_mode'] = getDolGlobalString('MAIN_MAIL_SENDMODE', 'mail');
		$facts['signature_rules'] = getDolGlobalString(VereineSignatures::CONST_RULES) !== '';
		$facts['meeting_templates'] = $this->count("SELECT COUNT(*) as n FROM ".MAIN_DB_PREFIX."vereine_meeting_template WHERE entity = ".$entity);

		// A website or app reads through a user with an API key and the right for member summaries.
		$facts['api'] = isModEnabled('api');
		$sql = "SELECT COUNT(DISTINCT u.rowid) as n FROM ".MAIN_DB_PREFIX."user as u INNER JOIN ".MAIN_DB_PREFIX."user_rights as r ON r.fk_user = u.rowid";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."rights_def as d ON d.id = r.fk_id WHERE d.module = 'vereine' AND d.perms = 'website' AND d.subperms = 'read'";
		$sql .= " AND u.statut = 1 AND u.api_key IS NOT NULL AND u.api_key <> ''";
		$facts['api_users'] = $this->count($sql);
		return $facts;
	}

	/**
	 * The first number a query answers, 0 when it cannot.
	 *
	 * @param string $sql Query
	 * @return int
	 */
	private function count($sql)
	{
		$resql = $this->db->query($sql);
		$row = $resql ? $this->db->fetch_row($resql) : null;
		return $row ? (int) $row[0] : 0;
	}

	/**
	 * Every step with where it stands.
	 *
	 * @param string $today Today, YYYY-MM-DD
	 * @return array{facts:array<string,mixed>,states:array<string,string>,progress:array{finished:int,total:int,complete:bool}}
	 */
	public function overview($today)
	{
		$facts = $this->facts($today);
		$states = VereineSetupGuideRules::states($facts, VereineSetupGuideRules::skipped(getDolGlobalString(self::SKIPPED)));
		return array('facts' => $facts, 'states' => $states, 'progress' => VereineSetupGuideRules::progress($states));
	}

	/**
	 * Leave a step out, or take it back in.
	 *
	 * @param string $step One of VereineSetupGuideRules::STEPS
	 * @param bool   $skip Whether to leave it out
	 * @param User   $user Who decides
	 * @return int 1 when stored, 0 for an unknown step, -1 on error
	 */
	public function skip($step, $skip, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		if (!in_array($step, VereineSetupGuideRules::STEPS, true)) {
			return 0;
		}
		$skipped = array_diff(VereineSetupGuideRules::skipped(getDolGlobalString(self::SKIPPED)), array($step));
		if ($skip) {
			$skipped[] = $step;
		}
		$result = $skipped ? dolibarr_set_const($this->db, self::SKIPPED, implode(',', $skipped), 'chaine', 0, '', $conf->entity)
			: dolibarr_del_const($this->db, self::SKIPPED, $conf->entity);
		if ($result < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::SETUP_GUIDE, 0, 0, $step.($skip ? ' skipped' : ' taken back in'));
		return 1;
	}

	/**
	 * Hide or show the hint on the overview and the setup.
	 *
	 * @param bool $hidden Whether it is hidden
	 * @return int 1 when stored, -1 on error
	 */
	public function hide($hidden)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$result = $hidden ? dolibarr_set_const($this->db, self::HIDDEN, '1', 'chaine', 0, '', $conf->entity) : dolibarr_del_const($this->db, self::HIDDEN, $conf->entity);
		return $result < 0 ? -1 : 1;
	}

	/**
	 * Send a test e-mail to the one who asks, through the sender of the association.
	 *
	 * @param User      $user        Who asks, the address it goes to
	 * @param Translate $outputlangs Language of the e-mail
	 * @return int 1 when it went out, 0 without an address, -1 when sending failed (see error)
	 */
	public function sendTest($user, $outputlangs)
	{
		global $conf, $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		require_once __DIR__.'/vereinemail.class.php';

		$to = trim((string) $user->email);
		if ($to === '' || !isValidEmail($to)) {
			return 0;
		}
		$mail = new VereineMail($this->db);
		$sent = $mail->send($outputlangs->transnoentities('VereineSetupMailSubject', (string) $mysoc->name),
			$to, $outputlangs->transnoentities('VereineSetupMailBody', (string) $mysoc->name), 'setupguide');
		if (!$sent) {
			$this->error = $mail->error;
			return -1;
		}
		dolibarr_set_const($this->db, self::MAIL_TESTED, dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'), 'chaine', 0, '', $conf->entity);
		VereineLog::add($this->db, $user, VereineLog::SETUP_GUIDE, 0, 0, 'test e-mail sent');
		return 1;
	}

	/**
	 * The hint for the overview and the setup: how far the setup is, with the way to the guide.
	 *
	 * @param string $today Today, YYYY-MM-DD
	 * @return string HTML, empty when everything is done or the hint is hidden
	 */
	public function hint($today)
	{
		global $langs;

		if (getDolGlobalString(self::HIDDEN) !== '') {
			return '';
		}
		$progress = $this->overview($today)['progress'];
		if ($progress['complete']) {
			return '';
		}
		$langs->load('vereine@vereine');
		return '<div class="info" data-setup-hint="'.$progress['finished'].'/'.$progress['total'].'">'
			.$langs->trans('VereineSetupHint', $progress['finished'], $progress['total'])
			.' <a href="'.dol_buildpath('/vereine/admin/start.php', 1).'">'.img_picto('', 'fa-route', 'class="pictofixedwidth"').$langs->trans('VereineSetupHintLink').'</a></div>';
	}
}
