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
 * \file    class/vereinefunctions.class.php
 * \ingroup vereine
 * \brief   Function catalogue in llx_vereine_function and terms of office in llx_vereine_function_term.
 */

require_once __DIR__.'/vereinefunctionrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Stores functions and terms of office.
 */
class VereineFunctions
{
	/** Member field: place of birth, needed to report a representative. */
	const FIELD_BIRTH_PLACE = 'vereine_birth_place';
	/** Whose names the website may show, see VereineFunctionRules::NAMES_*. */
	const CONST_BOARD_NAMES = 'VEREINE_BOARD_NAMES';
	/** Code of the consent text "shown on the website". */
	const CONST_BOARD_CONSENT = 'VEREINE_BOARD_CONSENT';

	/**
	 * How the website shows names of function holders.
	 *
	 * @return array{mode:string,consent:string}
	 */
	public function boardSetting()
	{
		$mode = getDolGlobalString(self::CONST_BOARD_NAMES) === VereineFunctionRules::NAMES_DISCLOSURE ? VereineFunctionRules::NAMES_DISCLOSURE : VereineFunctionRules::NAMES_CONSENT;
		return array('mode' => $mode, 'consent' => getDolGlobalString(self::CONST_BOARD_CONSENT));
	}

	/**
	 * Store how the website shows names of function holders.
	 *
	 * @param string $mode    One of VereineFunctionRules::NAMES_*
	 * @param string $consent Code of a consent text, empty for none
	 * @return int 1 if stored, 0 when refused (see $errors), <0 on error
	 */
	public function saveBoardSetting($mode, $consent)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$this->errors = array();
		if (!in_array($mode, array(VereineFunctionRules::NAMES_CONSENT, VereineFunctionRules::NAMES_DISCLOSURE), true)) {
			$this->errors[] = 'VereineBoardErrorMode';
		}
		if ((string) $consent !== '' && !preg_match('/^[a-z][a-z0-9_]{1,31}$/', (string) $consent)) {
			$this->errors[] = 'VereineBoardErrorConsent';
		}
		if ($this->errors) {
			return 0;
		}
		if (dolibarr_set_const($this->db, self::CONST_BOARD_NAMES, $mode, 'chaine', 0, '', $conf->entity) < 0
			|| dolibarr_set_const($this->db, self::CONST_BOARD_CONSENT, (string) $consent, 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * The functions of the association with their holders on a day, names as the website may show them.
	 *
	 * @param string $day Day
	 * @return array<int,array{code:string,label:string,board:bool,represents:bool,auditor:bool,holders:array<int,array{name:string|null,since:string}>}>
	 */
	public function board($day)
	{
		require_once __DIR__.'/vereineconsents.class.php';

		$setting = $this->boardSetting();
		$functions = $this->fetchAll(true);
		$terms = array_reverse($this->terms());
		$consented = array();
		if ($setting['consent'] !== '') {
			$consents = new VereineConsents($this->db);
			$consented = $consents->givenBy(array_column($terms, 'member_id'), $setting['consent']);
		}
		$board = array();
		foreach ($functions as $function) {
			$holders = array();
			foreach ($terms as $term) {
				if ($term['function_id'] !== $function['id'] || $term['member_status'] !== 1 || !VereineFunctionRules::isActive($term, $day)) {
					continue;
				}
				$show = VereineFunctionRules::showName($setting['mode'], $function['board'], !empty($consented[$term['member_id']]));
				$holders[] = array('name' => $show ? $term['member_name'] : null, 'since' => $term['start']);
			}
			$board[] = array('code' => $function['code'], 'label' => $function['label'], 'board' => $function['board'], 'represents' => $function['represents'],
				'auditor' => $function['auditor'], 'holders' => $holders);
		}
		return $board;
	}

	/**
	 * Holders of every function on a day, by function code.
	 *
	 * @param string $day Day, YYYY-MM-DD
	 * @return array<string,array<int,array{member_id:int,name:string}>> By function code
	 */
	public function holdersByCode($day)
	{
		$byId = array();
		foreach ($this->fetchAll() as $function) {
			$byId[$function['id']] = $function['code'];
		}
		$holders = array();
		foreach ($this->terms() as $term) {
			if (!isset($byId[$term['function_id']]) || (int) $term['member_status'] !== 1 || !VereineFunctionRules::isActive($term, $day)) {
				continue;
			}
			$holders[$byId[$term['function_id']]][] = array('member_id' => (int) $term['member_id'], 'name' => (string) $term['member_name']);
		}
		return $holders;
	}

	/**
	 * User groups of this entity.
	 *
	 * @return array<int,string> Name by id
	 */
	public function userGroups()
	{
		global $conf;

		$resql = $this->db->query("SELECT rowid, nom FROM ".MAIN_DB_PREFIX."usergroup WHERE entity IN (0, ".((int) $conf->entity).") ORDER BY nom");
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$groups = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$groups[(int) $obj->rowid] = (string) $obj->nom;
		}
		$this->db->free($resql);
		return $groups;
	}

	/**
	 * Dolibarr users linked to members, their login and groups.
	 *
	 * @return array{by_member:array<int,int>,logins:array<int,string>,groups:array<int,int[]>}
	 */
	public function memberUsers()
	{
		global $conf;

		$result = array('by_member' => array(), 'logins' => array(), 'groups' => array());
		$resql = $this->db->query("SELECT rowid, login, fk_member FROM ".MAIN_DB_PREFIX."user WHERE fk_member > 0 AND statut = 1 AND entity IN (0, ".((int) $conf->entity).")");
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $result;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$result['by_member'][(int) $obj->fk_member] = (int) $obj->rowid;
			$result['logins'][(int) $obj->rowid] = (string) $obj->login;
		}
		$this->db->free($resql);
		if ($result['logins']) {
			$sql = "SELECT fk_user, fk_usergroup FROM ".MAIN_DB_PREFIX."usergroup_user WHERE entity IN (0, ".((int) $conf->entity).")";
			$sql .= " AND fk_user IN (".implode(', ', array_keys($result['logins'])).")";
			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					$result['groups'][(int) $obj->fk_user][] = (int) $obj->fk_usergroup;
				}
				$this->db->free($resql);
			}
		}
		return $result;
	}

	/**
	 * Changes of user groups the functions ask for today, see VereineFunctionRules::groupChanges().
	 *
	 * @param string $day Day
	 * @return array<int,array{action:string,user_id:int,member_id:int,group_id:int}>
	 */
	public function groupChanges($day)
	{
		$users = $this->memberUsers();
		return VereineFunctionRules::groupChanges($this->fetchAll(true), $this->terms(), $users['by_member'], $users['groups'], $day);
	}

	/**
	 * Carry out one change the functions ask for, after an administrator confirmed it.
	 *
	 * @param string $action  'add' or 'remove'
	 * @param int    $userId  Dolibarr user
	 * @param int    $groupId User group
	 * @param string $day     Day the change is asked for
	 * @param User   $user    Administrator
	 * @return int 1 if done, 0 when the functions do not ask for it (any more), <0 on error
	 */
	public function applyGroupChange($action, $userId, $groupId, $day, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

		$asked = null;
		foreach ($this->groupChanges($day) as $change) {
			if ($change['action'] === $action && $change['user_id'] === (int) $userId && $change['group_id'] === (int) $groupId) {
				$asked = $change;
			}
		}
		if ($asked === null) {
			return 0;
		}
		$target = new User($this->db);
		if ($target->fetch((int) $userId) <= 0) {
			$this->error = 'user '.$userId.': '.$target->error;
			return -1;
		}
		$result = $action === 'add' ? $target->SetInGroup((int) $groupId, (int) $conf->entity) : $target->RemoveFromGroup((int) $groupId, (int) $conf->entity);
		if ($result < 0) {
			$this->error = 'user '.$userId.': '.$target->error;
			return -1;
		}
		VereineLog::add($this->db, $user, $action === 'add' ? VereineLog::FUNCTION_GROUP_ADD : VereineLog::FUNCTION_GROUP_REMOVE, $asked['member_id'], 0, $target->login.' / '.$groupId);
		return 1;
	}

	/**
	 * The functions a member holds on a day.
	 *
	 * @param int    $memberId Member
	 * @param string $day      Day
	 * @return array<int,array{code:string,label:string,since:string}>
	 */
	public function memberFunctions($memberId, $day)
	{
		$functions = array();
		foreach ($this->fetchAll(true) as $function) {
			$functions[$function['id']] = $function;
		}
		$result = array();
		foreach (array_reverse($this->terms((int) $memberId)) as $term) {
			if (isset($functions[$term['function_id']]) && VereineFunctionRules::isActive($term, $day)) {
				$result[] = array('code' => $functions[$term['function_id']]['code'], 'label' => $functions[$term['function_id']]['label'], 'since' => $term['start']);
			}
		}
		return $result;
	}

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last database error
	 */
	public $error = '';

	/**
	 * @var string[] Language keys of the last refused input
	 */
	public $errors = array();

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
	 * Add the suggested functions whose code this entity does not have yet. Changed or switched off ones stay as they are.
	 *
	 * @return int Number added, <0 on error
	 */
	public function ensureStandard()
	{
		global $conf;

		$existing = array();
		foreach ($this->fetchAll() as $function) {
			$existing[$function['code']] = true;
		}
		if ($this->error !== '') {
			return -1;
		}
		$added = 0;
		foreach (VereineFunctionRules::suggestedAt() as $position => $function) {
			if (isset($existing[$function['code']])) {
				continue;
			}
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_function (entity, code, label, board, represents, auditor, min_count, max_count, position, active, datec)";
			$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($function['code'])."', '".$this->db->escape($function['label'])."',";
			$sql .= " ".($function['board'] ? 1 : 0).", ".($function['represents'] ? 1 : 0).", ".($function['auditor'] ? 1 : 0).",";
			$sql .= " ".((int) $function['min']).", ".((int) $function['max']).", ".(($position + 1) * 10).", 1, '".$this->db->idate(dol_now())."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$added++;
		}
		return $added;
	}

	/**
	 * Functions of this entity in their order.
	 *
	 * @param bool $activeOnly Only active functions
	 * @return array<int,array{id:int,code:string,label:string,board:bool,represents:bool,auditor:bool,min:int,max:int,position:int,active:bool}>
	 */
	public function fetchAll($activeOnly = false)
	{
		global $conf;

		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."vereine_function";
		$sql .= " WHERE entity = ".((int) $conf->entity).($activeOnly ? " AND active = 1" : "")." ORDER BY position, rowid";
		// The table exists only after the module was enabled with 0.4.0.
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$functions = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$functions[] = array('id' => (int) $obj->rowid, 'code' => (string) $obj->code, 'label' => (string) $obj->label, 'board' => (int) $obj->board === 1,
				'represents' => (int) $obj->represents === 1, 'auditor' => (int) $obj->auditor === 1, 'min' => (int) $obj->min_count, 'max' => (int) $obj->max_count,
				'position' => (int) $obj->position, 'active' => (int) $obj->active === 1,
				// The columns exist only after the module was enabled with 0.4.3 and 0.5.0.
				'group_id' => isset($obj->fk_usergroup) ? (int) $obj->fk_usergroup : 0, 'term_years' => isset($obj->term_years) ? (int) $obj->term_years : 0);
		}
		$this->db->free($resql);
		return $functions;
	}

	/**
	 * Store a new function or change one. The code of an existing function stays.
	 *
	 * @param int                 $id   Function id, 0 for a new one
	 * @param array<string,mixed> $data Keys code, label, board, represents, auditor, min, max, position, active
	 * @param User                $user User
	 * @return int Id, 0 when refused (see $errors), <0 on error
	 */
	public function save($id, array $data, $user)
	{
		global $conf;

		$current = null;
		foreach ($this->fetchAll() as $function) {
			if ($function['id'] === (int) $id) {
				$current = $function;
			} elseif ((int) $id === 0 && $function['code'] === (string) $data['code']) {
				$this->errors = array('VereineFunctionErrorCodeTaken');
				return 0;
			}
		}
		if ($current !== null) {
			$data['code'] = $current['code'];
		}
		$this->errors = VereineFunctionRules::validate($data);
		if ($this->errors) {
			return 0;
		}
		$fields = "label = '".$this->db->escape(trim((string) $data['label']))."', board = ".(empty($data['board']) ? 0 : 1).", represents = ".(empty($data['represents']) ? 0 : 1);
		$fields .= ", auditor = ".(empty($data['auditor']) ? 0 : 1).", min_count = ".((int) $data['min']).", max_count = ".((int) $data['max']);
		$fields .= ", position = ".((int) $data['position']).", active = ".(empty($data['active']) ? 0 : 1).", fk_user_modif = ".((int) $user->id);
		if (isset($data['group_id'])) {
			$fields .= ", fk_usergroup = ".max(0, (int) $data['group_id']);
		}
		if ($current !== null) {
			if (!$this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_function SET ".$fields." WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity))) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			return (int) $id;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_function (entity, code, label, datec) VALUES (".((int) $conf->entity).", '".$this->db->escape($data['code'])."', '', '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$newId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_function');
		if (!$this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_function SET ".$fields." WHERE rowid = ".$newId)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return $newId;
	}

	/**
	 * Terms of office, newest first.
	 *
	 * @param int|null $memberId Only this member, null for all
	 * @return array<int,array{id:int,function_id:int,member_id:int,member_name:string,member_status:int,start:string,end:string,note:string}>
	 */
	public function terms($memberId = null)
	{
		global $conf;

		$sql = "SELECT t.rowid, t.fk_function, t.fk_adherent, t.date_start, t.date_end, t.note, d.firstname, d.lastname, d.societe, d.morphy, d.statut";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_function_term as t INNER JOIN ".MAIN_DB_PREFIX."adherent as d ON d.rowid = t.fk_adherent";
		$sql .= " WHERE t.entity = ".((int) $conf->entity).($memberId !== null ? " AND t.fk_adherent = ".((int) $memberId) : "");
		$sql .= " ORDER BY t.date_start DESC, t.rowid DESC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$terms = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$terms[] = array('id' => (int) $obj->rowid, 'function_id' => (int) $obj->fk_function, 'member_id' => (int) $obj->fk_adherent,
				'member_name' => $obj->morphy === 'mor' && (string) $obj->societe !== '' ? (string) $obj->societe : trim($obj->firstname.' '.$obj->lastname),
				'member_status' => (int) $obj->statut, 'start' => substr((string) $obj->date_start, 0, 10), 'end' => $obj->date_end ? substr((string) $obj->date_end, 0, 10) : '',
				'note' => (string) $obj->note);
		}
		$this->db->free($resql);
		return $terms;
	}

	/**
	 * Give a member a function from a day on.
	 *
	 * @param Adherent $member     Validated member
	 * @param int      $functionId Active function
	 * @param string   $start      First day
	 * @param string   $end        Last day, empty while open
	 * @param string   $note       Note, such as the assembly that elected the member
	 * @param User     $user       User
	 * @return int Term id, 0 when refused (see $errors), <0 on error
	 */
	public function addTerm($member, $functionId, $start, $end, $note, $user)
	{
		global $conf;

		// Without an end the term ends as the catalogue says; an entered end always wins.
		if ((string) $end === '') {
			foreach ($this->fetchAll() as $candidate) {
				if ($candidate['id'] === (int) $functionId) {
					$end = VereineFunctionRules::endOfTerm((string) $start, (int) $candidate['term_years']);
				}
			}
		}

		$this->errors = VereineFunctionRules::validateTerm($start, $end);
		if ((int) $member->statut !== 1) {
			$this->errors[] = 'VereineFunctionErrorMember';
		}
		$function = null;
		foreach ($this->fetchAll(true) as $candidate) {
			if ($candidate['id'] === (int) $functionId) {
				$function = $candidate;
			}
		}
		if ($function === null) {
			$this->errors[] = 'VereineFunctionErrorFunction';
		}
		if ($this->errors) {
			return 0;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_function_term (entity, fk_function, fk_adherent, date_start, date_end, note, datec, fk_user_modif)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $functionId).", ".((int) $member->id).", '".$this->db->escape($start)."',";
		$sql .= " ".($end === '' ? "NULL" : "'".$this->db->escape($end)."'").", '".$this->db->escape(dol_trunc(trim((string) $note), 255, 'right', 'UTF-8', 1))."',";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_function_term');
		VereineLog::add($this->db, $user, VereineLog::FUNCTION_START, (int) $member->id, 0, $function['code'].' / '.$start.($end !== '' ? ' - '.$end : ''));
		if ($function['represents'] && $this->createReport($id, $member, $function, $start, $user) < 0) {
			return -1;
		}
		return $id;
	}

	/**
	 * Register the member field for the place of birth, which a report to the association authority needs.
	 *
	 * @return int 1 if OK, <0 on error
	 */
	public function ensureFields()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$result = $extrafields->addExtraField(self::FIELD_BIRTH_PLACE, 'VereineReportBirthPlace', 'varchar', 1230, '64', 'adherent', 0, 0, '', '', 1, '', '1',
			'VereineReportBirthPlaceHelp', '', '', 'vereine@vereine', 'isModEnabled("vereine")');
		if ($result <= 0) {
			$this->error = 'Extra field '.self::FIELD_BIRTH_PLACE.': '.$extrafields->error;
			return -1;
		}
		return 1;
	}

	/**
	 * Reports to the association authority, the deadline first.
	 *
	 * @param bool $openOnly Only those not reported yet
	 * @return array<int,array{id:int,term_id:int,function:string,member_id:int,member_name:string,start:string,deadline:string,reported_on:string,actioncomm_id:int}>
	 */
	public function reports($openOnly = true)
	{
		global $conf;

		$sql = "SELECT r.rowid, r.fk_term, r.deadline, r.reported_on, r.fk_actioncomm, t.fk_adherent, t.date_start, f.label, d.firstname, d.lastname, d.societe, d.morphy";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_function_report as r";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_function_term as t ON t.rowid = r.fk_term";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_function as f ON f.rowid = t.fk_function";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent as d ON d.rowid = t.fk_adherent";
		$sql .= " WHERE r.entity = ".((int) $conf->entity).($openOnly ? " AND r.reported_on IS NULL" : "")." ORDER BY r.deadline, r.rowid";
		// The table exists only after the module was enabled with 0.4.1.
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$reports = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$reports[] = array('id' => (int) $obj->rowid, 'term_id' => (int) $obj->fk_term, 'function' => (string) $obj->label, 'member_id' => (int) $obj->fk_adherent,
				'member_name' => $obj->morphy === 'mor' && (string) $obj->societe !== '' ? (string) $obj->societe : trim($obj->firstname.' '.$obj->lastname),
				'start' => substr((string) $obj->date_start, 0, 10), 'deadline' => substr((string) $obj->deadline, 0, 10),
				'reported_on' => $obj->reported_on ? substr((string) $obj->reported_on, 0, 10) : '', 'actioncomm_id' => (int) $obj->fk_actioncomm);
		}
		$this->db->free($resql);
		return $reports;
	}

	/**
	 * The representatives of the association on a day, with what a report needs.
	 *
	 * @param string $day Day
	 * @return array<int,array<string,mixed>> Keys function, start, member_id, name, birth, birth_place, address, zip, town, country, reported, missing
	 */
	public function representatives($day)
	{
		$represents = array();
		foreach ($this->fetchAll(true) as $function) {
			if ($function['represents']) {
				$represents[$function['id']] = $function;
			}
		}
		$reported = array();
		foreach ($this->reports(false) as $report) {
			$reported[$report['term_id']] = $report['reported_on'] !== '';
		}
		$terms = array();
		foreach (array_reverse($this->terms()) as $term) {
			if (isset($represents[$term['function_id']]) && VereineFunctionRules::isActive($term, $day)) {
				$terms[] = $term;
			}
		}
		$people = $this->people(array_column($terms, 'member_id'));
		$result = array();
		foreach ($terms as $term) {
			$person = isset($people[$term['member_id']]) ? $people[$term['member_id']] : array();
			$result[] = array(
				'function' => $represents[$term['function_id']]['label'],
				'position' => $represents[$term['function_id']]['position'],
				'start' => $term['start'],
				'member_id' => $term['member_id'],
				'name' => $term['member_name'],
				'reported' => isset($reported[$term['id']]) ? $reported[$term['id']] : true,
				'missing' => VereineFunctionRules::missingForReport($person),
			) + $person + array('birth' => '', 'birth_place' => '', 'address' => '', 'zip' => '', 'town' => '', 'country' => '');
		}
		usort($result, function ($left, $right) {
			return ($left['position'] - $right['position']) ?: strcmp($left['start'], $right['start']);
		});
		return $result;
	}

	/**
	 * Note the open reports as reported on a day and close their agenda events.
	 *
	 * @param string $day  Day of the report
	 * @param User   $user User
	 * @return int Number of reports noted, <0 on error
	 */
	public function markReported($day, $user)
	{
		global $conf;

		if (!VereineFunctionRules::isDate($day)) {
			$this->errors = array('VereineReportErrorDay');
			return 0;
		}
		$open = $this->reports(true);
		foreach ($open as $report) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_function_report SET reported_on = '".$this->db->escape($day)."', fk_user_modif = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $report['id'])." AND entity = ".((int) $conf->entity);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			if ($report['actioncomm_id'] > 0 && isModEnabled('agenda')) {
				require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
				$event = new ActionComm($this->db);
				if ($event->fetch($report['actioncomm_id']) > 0) {
					$event->percentage = 100;
					$event->update($user);
				}
			}
			VereineLog::add($this->db, $user, VereineLog::FUNCTION_REPORTED, $report['member_id'], 0, $report['function'].' / '.$day);
		}
		return count($open);
	}

	/**
	 * Write the report of the representatives to the association authority as PDF.
	 *
	 * @param string    $day          Day the representatives are listed for
	 * @param Translate $outputlangs  Language of the letter
	 * @return string Path of the PDF, empty on error
	 */
	public function buildReportPdf($day, $outputlangs)
	{
		require_once __DIR__.'/vereineauthorityletters.class.php';

		$letters = new VereineAuthorityLetters($this->db);
		$file = $letters->build(VereineAuthorityRules::KIND_REPRESENTATIVES, array('date' => $day), $this->representatives($day), $outputlangs);
		$this->error = $letters->error;
		return $file;
	}

	/**
	 * A report and, with Dolibarr's agenda, an event on its deadline for a new representative.
	 *
	 * @param int                 $termId   Term
	 * @param Adherent            $member   Member
	 * @param array<string,mixed> $function Function
	 * @param string              $start    First day of the term
	 * @param User                $user     User
	 * @return int 1 if OK, <0 on error
	 */
	private function createReport($termId, $member, array $function, $start, $user)
	{
		global $conf, $langs;

		$deadline = VereineFunctionRules::reportDeadline($start);
		$eventId = 0;
		if (isModEnabled('agenda')) {
			require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
			$event = new ActionComm($this->db);
			$event->type_code = 'AC_OTH';
			$event->label = $langs->transnoentities('VereineReportAgendaLabel', $function['label'], $member->getFullName($langs));
			$event->note_private = $langs->transnoentities('VereineReportAgendaNote');
			$event->datep = dol_mktime(0, 0, 0, (int) substr($deadline, 5, 2), (int) substr($deadline, 8, 2), (int) substr($deadline, 0, 4));
			$event->datef = $event->datep;
			$event->fulldayevent = 1;
			$event->percentage = 0;
			$event->userownerid = (int) $user->id;
			$event->elementtype = 'member';
			$event->fk_element = (int) $member->id;
			$eventId = (int) $event->create($user);
			if ($eventId <= 0) {
				dol_syslog(__METHOD__.' agenda event: '.$event->error, LOG_WARNING);
				$eventId = 0;
			}
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_function_report (entity, fk_term, deadline, fk_actioncomm, datec, fk_user_modif)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $termId).", '".$this->db->escape($deadline)."', ".($eventId > 0 ? $eventId : "NULL").",";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * What reports need about members: birth date, place of birth, address.
	 *
	 * @param int[] $memberIds Members
	 * @return array<int,array{birth:string,birth_place:string,address:string,zip:string,town:string,country:string}>
	 */
	private function people(array $memberIds)
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
		if (!$ids) {
			return array();
		}
		// e.* instead of the field name: the column exists only after the module was enabled with 0.4.1.
		$sql = "SELECT d.rowid as member_id, d.birth, d.address, d.zip, d.town, c.label as country_label, e.*";
		$sql .= " FROM ".MAIN_DB_PREFIX."adherent as d";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_country as c ON c.rowid = d.country";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."adherent_extrafields as e ON e.fk_object = d.rowid";
		$sql .= " WHERE d.rowid IN (".implode(', ', $ids).")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$people = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$people[(int) $obj->member_id] = array(
				'birth' => $obj->birth ? substr((string) $obj->birth, 0, 10) : '',
				'birth_place' => isset($obj->{self::FIELD_BIRTH_PLACE}) ? trim((string) $obj->{self::FIELD_BIRTH_PLACE}) : '',
				'address' => (string) $obj->address,
				'zip' => (string) $obj->zip,
				'town' => (string) $obj->town,
				'country' => (string) $obj->country_label,
			);
		}
		$this->db->free($resql);
		return $people;
	}

	/**
	 * End an open term on a day.
	 *
	 * @param int    $termId Term
	 * @param string $end    Last day, not before the first
	 * @param User   $user   User
	 * @return int 1 if ended, 0 when refused (see $errors), <0 on error
	 */
	public function endTerm($termId, $end, $user)
	{
		global $conf;

		$term = null;
		foreach ($this->terms() as $candidate) {
			if ($candidate['id'] === (int) $termId && $candidate['end'] === '') {
				$term = $candidate;
			}
		}
		if ($term === null) {
			$this->errors = array('VereineFunctionErrorTerm');
			return 0;
		}
		$this->errors = VereineFunctionRules::validateTerm($term['start'], $end);
		if ($end === '' || $this->errors) {
			$this->errors = $this->errors ?: array('VereineFunctionErrorEnd');
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_function_term SET date_end = '".$this->db->escape($end)."', fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $termId)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::FUNCTION_END, $term['member_id'], 0, $term['function_id'].' / '.$end);
		return 1;
	}
}
