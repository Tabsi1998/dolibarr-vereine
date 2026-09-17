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

		$sql = "SELECT rowid, code, label, board, represents, auditor, min_count, max_count, position, active FROM ".MAIN_DB_PREFIX."vereine_function";
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
				'position' => (int) $obj->position, 'active' => (int) $obj->active === 1);
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
		return $id;
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
