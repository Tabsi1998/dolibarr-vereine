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
 * \file    class/vereineduties.class.php
 * \ingroup vereine
 * \brief   The calendar of duties (#24): what comes back every year, who does it and when it is due.
 *
 * The catalogue starts with what Austrian law asks of every association and the association adds its
 * own. For a year the module works out the day of every duty from the association's year, and puts it
 * into Dolibarr's agenda as a task of whoever holds the function today, with Dolibarr's own reminder.
 * When the function changes hands, the open tasks wait for a word and then move to the successor, so
 * nothing stays with someone who is no longer in charge.
 */

require_once __DIR__.'/vereinedutyrules.class.php';
require_once __DIR__.'/vereinefunctions.class.php';
require_once __DIR__.'/vereineauditrules.class.php';
require_once __DIR__.'/vereinestatutes.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * The duties of the association and their tasks.
 */
class VereineDuties
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
	 * @var string[] Language keys of what was refused
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
	 * Add the duties of the law this entity does not carry yet. Changed or switched off ones stay as they are.
	 *
	 * @return int Number added, <0 on error
	 */
	public function ensureStandard()
	{
		global $conf;

		$existing = array();
		foreach ($this->fetchAll() as $duty) {
			$existing[$duty['code']] = true;
		}
		if ($this->error !== '') {
			return -1;
		}
		$added = 0;
		$now = $this->db->idate(dol_now());
		foreach (VereineDutyRules::standard() as $position => $duty) {
			if (isset($existing[$duty['code']])) {
				continue;
			}
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_duty (entity, code, label, function_code, basis, offset_months, due_month, due_day,";
			$sql .= " every_years, first_year, lead_days, source, standard, position, active, datec) VALUES (";
			$sql .= ((int) $conf->entity).", '".$this->db->escape($duty['code'])."', '".$this->db->escape($duty['label'])."',";
			$sql .= " '".$this->db->escape($duty['function_code'])."', '".$this->db->escape($duty['basis'])."', ".((int) $duty['offset_months']).",";
			$sql .= " ".((int) $duty['due_month']).", ".((int) $duty['due_day']).", ".((int) $duty['every_years']).", 0, ".VereineDutyRules::LEAD_DAYS.",";
			$sql .= " '".$this->db->escape($duty['source'])."', 1, ".(($position + 1) * 10).", ".((int) $duty['active']).", '".$now."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$added++;
		}
		return $added;
	}

	/**
	 * The catalogue of this entity in its order.
	 *
	 * @param bool $activeOnly Only duties that are switched on
	 * @return array<int,array<string,mixed>> Keys id, code, label, function_code, basis, offset_months, due_month, due_day,
	 *                                        every_years, first_year, lead_days, source, note, standard, position, active
	 */
	public function fetchAll($activeOnly = false)
	{
		global $conf;

		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."vereine_duty WHERE entity = ".((int) $conf->entity);
		$sql .= ($activeOnly ? " AND active = 1" : "")." ORDER BY position, rowid";
		// The table exists only after the module was enabled with 0.8.0.
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$duties = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$duties[] = array('id' => (int) $obj->rowid, 'code' => (string) $obj->code, 'label' => (string) $obj->label,
				'function_code' => (string) $obj->function_code, 'basis' => (string) $obj->basis, 'offset_months' => (int) $obj->offset_months,
				'due_month' => (int) $obj->due_month, 'due_day' => (int) $obj->due_day, 'every_years' => (int) $obj->every_years,
				'first_year' => (int) $obj->first_year, 'lead_days' => (int) $obj->lead_days, 'source' => (string) $obj->source,
				'note' => (string) $obj->note, 'standard' => (bool) $obj->standard, 'position' => (int) $obj->position, 'active' => (bool) $obj->active);
		}
		$this->db->free($resql);
		return $duties;
	}

	/**
	 * Store an entry of the catalogue, a new one when there is no id.
	 *
	 * @param int                 $id   Entry, 0 for a new one
	 * @param array<string,mixed> $data Keys code, label, function_code, basis, offset_months, due_month, due_day,
	 *                                  every_years, first_year, lead_days, note, active
	 * @param User                $user Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function save($id, array $data, $user)
	{
		global $conf;

		$this->errors = VereineDutyRules::validate($data);
		if ($this->errors) {
			return 0;
		}
		$entity = (int) $conf->entity;
		$note = (string) (isset($data['note']) ? $data['note'] : '');
		$values = array(
			'label' => "'".$this->db->escape(trim((string) $data['label']))."'",
			'function_code' => "'".$this->db->escape(trim((string) (isset($data['function_code']) ? $data['function_code'] : '')))."'",
			'basis' => "'".$this->db->escape((string) $data['basis'])."'",
			'offset_months' => (string) (int) (isset($data['offset_months']) ? $data['offset_months'] : 0),
			'due_month' => (string) (int) (isset($data['due_month']) ? $data['due_month'] : 0),
			'due_day' => (string) (int) (isset($data['due_day']) ? $data['due_day'] : 0),
			'every_years' => (string) max(1, (int) (isset($data['every_years']) ? $data['every_years'] : 1)),
			'first_year' => (string) (int) (isset($data['first_year']) ? $data['first_year'] : 0),
			'lead_days' => (string) (int) (isset($data['lead_days']) && $data['lead_days'] !== '' ? $data['lead_days'] : VereineDutyRules::LEAD_DAYS),
			'note' => $note !== '' ? "'".$this->db->escape($note)."'" : "NULL",
			'active' => !empty($data['active']) ? '1' : '0',
			'fk_user_modif' => (string) (int) $user->id,
		);
		if ((int) $id > 0) {
			$set = array();
			foreach ($values as $column => $value) {
				$set[] = $column.' = '.$value;
			}
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_duty SET ".implode(', ', $set)." WHERE rowid = ".((int) $id)." AND entity = ".$entity;
		} else {
			$values['entity'] = (string) $entity;
			$values['code'] = "'".$this->db->escape(trim((string) $data['code']))."'";
			$values['standard'] = '0';
			$values['position'] = (string) $this->nextPosition();
			$values['datec'] = "'".$this->db->idate(dol_now())."'";
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_duty (".implode(', ', array_keys($values)).")";
			$sql .= " VALUES (".implode(', ', array_values($values)).")";
		}
		if (!$this->db->query($sql)) {
			// The unique key says the code is taken; everything else is a real error.
			if ($this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				$this->errors[] = 'VereineDutyErrorCodeTaken';
				return 0;
			}
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::DUTY_SAVED, 0, 0, trim((string) $data['label']));
		return 1;
	}

	/**
	 * Remove an entry the association added itself, with the tasks that came from it. What the law asks
	 * for stays and is only switched off.
	 *
	 * @param int  $id   Entry
	 * @param User $user Who removes
	 * @return int 1 when removed, 0 when refused, -1 on error
	 */
	public function remove($id, $user)
	{
		global $conf;

		$entity = (int) $conf->entity;
		$found = null;
		foreach ($this->fetchAll() as $duty) {
			if ($duty['id'] === (int) $id) {
				$found = $duty;
			}
		}
		if ($found === null || $found['standard']) {
			$this->errors[] = 'VereineDutyErrorStandardStays';
			return 0;
		}
		$this->db->begin();
		foreach (array('vereine_duty_task WHERE fk_duty = '.((int) $id), 'vereine_duty WHERE rowid = '.((int) $id)) as $what) {
			if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX.$what." AND entity = ".$entity)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::DUTY_REMOVED, 0, 0, $found['label']);
		return 1;
	}

	/**
	 * The next free place in the catalogue.
	 *
	 * @return int
	 */
	private function nextPosition()
	{
		global $conf;

		$resql = $this->db->query("SELECT MAX(position) as last FROM ".MAIN_DB_PREFIX."vereine_duty WHERE entity = ".((int) $conf->entity));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return ($obj ? (int) $obj->last : 0) + 10;
	}

	/**
	 * What is due in an association's year: every duty of the catalogue with its day, who looks after it
	 * and how it stands today.
	 *
	 * @param int    $year  Year the association's year starts in
	 * @param string $today Today, YYYY-MM-DD
	 * @return array<int,array<string,mixed>> Keys duty, due, state, task, holders, label, source
	 */
	public function plan($year, $today)
	{
		$period = VereineAuditRules::period((int) $year, getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1));
		$made = $this->accountMade((int) $year);
		$fallback = VereineDutyRules::addMonths($period['end'], 5);
		$functions = new VereineFunctions($this->db);
		$holders = $functions->holdersByCode($today);
		$tasks = $this->tasks((int) $year);
		$plan = array();
		foreach ($this->fetchAll(true) as $duty) {
			if (!VereineDutyRules::planned($duty, (int) $year)) {
				continue;
			}
			$due = VereineDutyRules::due($duty, $period['end'], $made, $fallback);
			$task = isset($tasks[$duty['id']]) ? $tasks[$duty['id']] : null;
			$plan[] = array(
				'duty' => $duty,
				'due' => $task !== null && $task['due_on'] !== '' ? $task['due_on'] : $due,
				'state' => VereineDutyRules::state($task !== null && $task['due_on'] !== '' ? $task['due_on'] : $due, $today,
					$task !== null && $task['done_on'] !== '', $duty['lead_days']),
				'task' => $task,
				'holders' => isset($holders[$duty['function_code']]) ? $holders[$duty['function_code']] : array(),
			);
		}
		usort($plan, function ($left, $right) {
			return strcmp($left['due'] !== '' ? $left['due'] : '9999', $right['due'] !== '' ? $right['due'] : '9999');
		});
		return $plan;
	}

	/**
	 * The day the income and expenditure account of a year was made, empty while it was not.
	 *
	 * @param int $year Year
	 * @return string YYYY-MM-DD or empty
	 */
	public function accountMade($year)
	{
		global $conf;

		$sql = "SELECT made_on FROM ".MAIN_DB_PREFIX."vereine_account WHERE entity = ".((int) $conf->entity)." AND fiscal_year = ".((int) $year);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj && $obj->made_on ? substr((string) $obj->made_on, 0, 10) : '';
	}

	/**
	 * The tasks of an association's year by duty.
	 *
	 * @param int $year Year
	 * @return array<int,array{id:int,duty_id:int,due_on:string,member_id:int,event_id:int,done_on:string,note:string}>
	 */
	public function tasks($year)
	{
		global $conf;

		$sql = "SELECT rowid, fk_duty, due_on, fk_adherent, fk_actioncomm, done_on, note FROM ".MAIN_DB_PREFIX."vereine_duty_task";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND fiscal_year = ".((int) $year);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$tasks = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$tasks[(int) $obj->fk_duty] = array('id' => (int) $obj->rowid, 'duty_id' => (int) $obj->fk_duty,
				'due_on' => $obj->due_on ? substr((string) $obj->due_on, 0, 10) : '', 'member_id' => (int) $obj->fk_adherent,
				'event_id' => (int) $obj->fk_actioncomm, 'done_on' => $obj->done_on ? substr((string) $obj->done_on, 0, 10) : '',
				'note' => (string) $obj->note);
		}
		$this->db->free($resql);
		return $tasks;
	}

	/**
	 * Put the duties of a year into the calendar: one task per duty that has none yet, as an agenda event
	 * of whoever holds the function today. Running it again changes nothing.
	 *
	 * @param int    $year  Year the association's year starts in
	 * @param string $today Today, YYYY-MM-DD
	 * @param User   $user  Who plans
	 * @return int Number of tasks written, -1 on error
	 */
	public function createTasks($year, $today, $user)
	{
		global $conf;

		$written = 0;
		foreach ($this->plan((int) $year, $today) as $entry) {
			if ($entry['task'] !== null || $entry['due'] === '') {
				continue;
			}
			$member = $entry['holders'] ? (int) $entry['holders'][0]['member_id'] : 0;
			$eventId = $this->addEvent($entry['duty'], $entry['due'], $member, $user);
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_duty_task (entity, fk_duty, fiscal_year, due_on, fk_adherent, fk_actioncomm, datec, fk_user_modif)";
			$sql .= " VALUES (".((int) $conf->entity).", ".((int) $entry['duty']['id']).", ".((int) $year).", '".$this->db->escape($entry['due'])."',";
			$sql .= " ".$member.", ".($eventId > 0 ? $eventId : "NULL").", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$written++;
		}
		if ($written > 0) {
			VereineLog::add($this->db, $user, VereineLog::DUTY_PLANNED, 0, 0, $year.': '.$written);
		}
		return $written;
	}

	/**
	 * The agenda event of a duty, owned by the person who holds the function, with Dolibarr's reminder.
	 *
	 * @param array<string,mixed> $duty   Entry of the catalogue
	 * @param string              $due    Day it is due
	 * @param int                 $member Member who holds the function, 0 when nobody does
	 * @param User                $user   Who plans
	 * @return int Event, 0 when there is none
	 */
	private function addEvent(array $duty, $due, $member, $user)
	{
		global $langs;

		if (!isModEnabled('agenda')) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

		$langs->load('vereine@vereine');
		$event = new ActionComm($this->db);
		$event->type_code = 'AC_OTH';
		$event->label = $langs->transnoentities('VereineDutyAgendaLabel', $duty['label']);
		$event->note_private = $langs->transnoentities('VereineDutyAgendaNote', $duty['source'] !== '' ? $duty['source'] : $duty['label']);
		$event->datep = dol_mktime(0, 0, 0, (int) substr($due, 5, 2), (int) substr($due, 8, 2), (int) substr($due, 0, 4));
		$event->datef = $event->datep;
		$event->fulldayevent = 1;
		$event->percentage = 0;
		$event->userownerid = $this->userOfMember($member) ?: (int) $user->id;
		if ((int) $member > 0) {
			$event->fk_element = (int) $member;
			$event->elementtype = 'member';
		}
		$eventId = (int) $event->create($user);
		if ($eventId <= 0) {
			dol_syslog(__METHOD__.' agenda event: '.$event->error, LOG_WARNING);
			return 0;
		}
		$this->addReminder($eventId, $duty, $event->userownerid);
		return $eventId;
	}

	/**
	 * Dolibarr's own reminder of an event, so many days before its day.
	 *
	 * @param int                 $eventId Event
	 * @param array<string,mixed> $duty    Entry of the catalogue
	 * @param int                 $ownerId Who gets the reminder
	 * @return void
	 */
	private function addReminder($eventId, array $duty, $ownerId)
	{
		global $conf;

		if (!isModEnabled('agenda') || (int) $ownerId < 1 || (int) $duty['lead_days'] < 1) {
			return;
		}
		// The table is Dolibarr's; the module writes the same row the agenda card writes.
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."actioncomm_reminder (dateremind, typeremind, fk_actioncomm, fk_user, offsetvalue, offsetunit, status, entity)";
		$sql .= " VALUES ('".$this->db->idate(dol_now())."', 'email', ".((int) $eventId).", ".((int) $ownerId).", ".((int) $duty['lead_days']).", 'd', 0, ".((int) $conf->entity).")";
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' reminder: '.$this->db->lasterror(), LOG_WARNING);
		}
	}

	/**
	 * The Dolibarr user of a member, 0 when there is none.
	 *
	 * @param int $member Member
	 * @return int
	 */
	private function userOfMember($member)
	{
		global $conf;

		if ((int) $member < 1) {
			return 0;
		}
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."user WHERE fk_member = ".((int) $member)." AND statut = 1 AND entity IN (0, ".((int) $conf->entity).")";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Tasks that still sit with someone who no longer holds the function, with the person they belong to
	 * now. Nothing moves by itself: the association says the word (#24).
	 *
	 * @param string $today Today, YYYY-MM-DD
	 * @return array<int,array{task_id:int,fiscal_year:int,label:string,due_on:string,from_id:int,from_name:string,to_id:int,to_name:string}>
	 */
	public function handovers($today)
	{
		global $conf;

		$functions = new VereineFunctions($this->db);
		$holders = $functions->holdersByCode($today);
		$names = $this->memberNames();
		$duties = array();
		foreach ($this->fetchAll() as $duty) {
			$duties[$duty['id']] = $duty;
		}
		$sql = "SELECT rowid, fk_duty, fiscal_year, due_on, fk_adherent FROM ".MAIN_DB_PREFIX."vereine_duty_task";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND done_on IS NULL ORDER BY due_on, rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$open = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$duty = isset($duties[(int) $obj->fk_duty]) ? $duties[(int) $obj->fk_duty] : null;
			if ($duty === null) {
				continue;
			}
			$now = isset($holders[$duty['function_code']]) ? $holders[$duty['function_code']] : array();
			$current = array();
			foreach ($now as $holder) {
				$current[] = (int) $holder['member_id'];
			}
			// While nobody holds the function there is nobody to hand over to; that is a gap of its own (#124).
			if (!$current || in_array((int) $obj->fk_adherent, $current, true)) {
				continue;
			}
			$open[] = array('task_id' => (int) $obj->rowid, 'fiscal_year' => (int) $obj->fiscal_year, 'label' => $duty['label'],
				'due_on' => $obj->due_on ? substr((string) $obj->due_on, 0, 10) : '', 'from_id' => (int) $obj->fk_adherent,
				'from_name' => isset($names[(int) $obj->fk_adherent]) ? $names[(int) $obj->fk_adherent] : '',
				'to_id' => $current[0], 'to_name' => (string) $now[0]['name']);
		}
		$this->db->free($resql);
		return $open;
	}

	/**
	 * Hand an open task over to whoever holds the function now, in the module and in Dolibarr's agenda.
	 *
	 * @param int    $taskId Task
	 * @param string $today  Today, YYYY-MM-DD
	 * @param User   $user   Who hands over
	 * @return int 1 when handed over, 0 when there is nothing to hand over, -1 on error
	 */
	public function handover($taskId, $today, $user)
	{
		global $conf;

		$found = null;
		foreach ($this->handovers($today) as $entry) {
			if ($entry['task_id'] === (int) $taskId) {
				$found = $entry;
			}
		}
		if ($found === null) {
			$this->errors[] = 'VereineDutyErrorNoHandover';
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_duty_task SET fk_adherent = ".((int) $found['to_id']).", fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $taskId)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->moveEvent((int) $taskId, (int) $found['to_id'], $user);
		VereineLog::add($this->db, $user, VereineLog::DUTY_HANDOVER, (int) $found['to_id'], 0,
			$found['label'].': '.$found['from_name'].' -> '.$found['to_name']);
		return 1;
	}

	/**
	 * Move the agenda event of a task to the new holder, so the reminder reaches the right person.
	 *
	 * @param int  $taskId Task
	 * @param int  $member New holder
	 * @param User $user   Who hands over
	 * @return void
	 */
	private function moveEvent($taskId, $member, $user)
	{
		global $conf;

		$resql = $this->db->query("SELECT fk_actioncomm FROM ".MAIN_DB_PREFIX."vereine_duty_task WHERE rowid = ".((int) $taskId)
			." AND entity = ".((int) $conf->entity));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		$eventId = $obj ? (int) $obj->fk_actioncomm : 0;
		$owner = $this->userOfMember($member);
		if ($eventId < 1 || !isModEnabled('agenda')) {
			return;
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

		$event = new ActionComm($this->db);
		if ($event->fetch($eventId) <= 0) {
			return;
		}
		// The event always follows the member; its owner only when that member has a Dolibarr user.
		$event->fk_element = (int) $member;
		$event->elementtype = 'member';
		if ($owner > 0) {
			$event->userownerid = $owner;
		}
		if ($event->update($user) < 0) {
			dol_syslog(__METHOD__.' agenda event: '.$event->error, LOG_WARNING);
		}
		if ($owner > 0) {
			// The reminder belongs to the old holder; it goes with the task.
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."actioncomm_reminder SET fk_user = ".$owner." WHERE fk_actioncomm = ".$eventId." AND status = 0");
		}
	}

	/**
	 * Say that a duty of a year is done, or take it back.
	 *
	 * @param int    $taskId Task
	 * @param string $day    Day it was done, empty to take it back
	 * @param User   $user   Who says so
	 * @return int 1 when stored, -1 on error
	 */
	public function markDone($taskId, $day, $user)
	{
		global $conf;

		$done = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $day) ? (string) $day : '';
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_duty_task SET done_on = ".($done !== '' ? "'".$this->db->escape($done)."'" : "NULL");
		$sql .= ", fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $taskId)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$sql = "SELECT d.label, t.fk_actioncomm FROM ".MAIN_DB_PREFIX."vereine_duty_task as t";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_duty as d ON d.rowid = t.fk_duty";
		$sql .= " WHERE t.rowid = ".((int) $taskId)." AND t.entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if ($obj && (int) $obj->fk_actioncomm > 0 && isModEnabled('agenda')) {
			require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

			$event = new ActionComm($this->db);
			if ($event->fetch((int) $obj->fk_actioncomm) > 0) {
				$event->percentage = $done !== '' ? 100 : 0;
				if ($event->update($user) < 0) {
					dol_syslog(__METHOD__.' agenda event: '.$event->error, LOG_WARNING);
				}
			}
		}
		VereineLog::add($this->db, $user, VereineLog::DUTY_DONE, 0, 0, ($obj ? (string) $obj->label : '').($done !== '' ? ': '.$done : ''));
		return 1;
	}

	/**
	 * Whether someone may plan duties, hand them over and tick them off: administrators and whoever
	 * holds a function of the association. Reading is enough for everyone else.
	 *
	 * @param User   $user  Who looks
	 * @param string $today Today, YYYY-MM-DD
	 * @return bool
	 */
	public function mayManage($user, $today)
	{
		if (!empty($user->admin)) {
			return true;
		}
		if ((int) $user->fk_member < 1) {
			return false;
		}
		$functions = new VereineFunctions($this->db);
		foreach ($functions->holdersByCode($today) as $holders) {
			foreach ($holders as $holder) {
				if ((int) $holder['member_id'] === (int) $user->fk_member) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The duties that wait for one member, for their own view.
	 *
	 * @param int    $memberId Member
	 * @param string $today    Today, YYYY-MM-DD
	 * @return array<int,array{task_id:int,label:string,due_on:string,state:string,fiscal_year:int}> Most urgent first
	 */
	public function forMember($memberId, $today)
	{
		global $conf;

		if ((int) $memberId < 1) {
			return array();
		}
		$sql = "SELECT t.rowid, t.fiscal_year, t.due_on, d.label, d.lead_days FROM ".MAIN_DB_PREFIX."vereine_duty_task as t";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_duty as d ON d.rowid = t.fk_duty";
		$sql .= " WHERE t.entity = ".((int) $conf->entity)." AND t.fk_adherent = ".((int) $memberId)." AND t.done_on IS NULL";
		$sql .= " ORDER BY t.due_on, t.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$mine = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$due = $obj->due_on ? substr((string) $obj->due_on, 0, 10) : '';
			$mine[] = array('task_id' => (int) $obj->rowid, 'label' => (string) $obj->label, 'due_on' => $due,
				'state' => VereineDutyRules::state($due, $today, false, (int) $obj->lead_days), 'fiscal_year' => (int) $obj->fiscal_year);
		}
		$this->db->free($resql);
		return $mine;
	}

	/**
	 * Names of the members, for the lists.
	 *
	 * @return array<int,string> Name by member
	 */
	private function memberNames()
	{
		global $conf;

		$resql = $this->db->query("SELECT rowid, firstname, lastname FROM ".MAIN_DB_PREFIX."adherent WHERE entity = ".((int) $conf->entity));
		if (!$resql) {
			return array();
		}
		$names = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$names[(int) $obj->rowid] = trim((string) $obj->firstname.' '.(string) $obj->lastname);
		}
		$this->db->free($resql);
		return $names;
	}
}
