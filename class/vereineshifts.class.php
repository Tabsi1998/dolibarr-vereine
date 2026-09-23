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
 * \file    class/vereineshifts.class.php
 * \ingroup vereine
 * \brief   Helper shifts of an event (#23): who asked, who was taken, who really was there.
 *
 * A member asking for a shift is a request, nothing more. The association takes people, and after the
 * event notes who really was there, with the hours. Only that last note is a fact about work done, and
 * only it may ever carry a volunteer allowance (#7). Asking through a website or an app writes the same
 * request (#165); where it came from is kept, so nothing is counted twice.
 */

require_once __DIR__.'/vereineshiftrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * The helper shifts of the events.
 */
class VereineShifts
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
	 * The shifts of an event with their entries and how many places are left.
	 *
	 * @param int $eventId Event
	 * @return array<int,array<string,mixed>> In the order of the day and the time
	 */
	public function forEvent($eventId)
	{
		global $conf;

		$sql = "SELECT rowid, label, shift_day, start_time, end_time, capacity, function_code, note, position";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_event_shift WHERE entity = ".((int) $conf->entity)." AND fk_event = ".((int) $eventId);
		$sql .= " ORDER BY shift_day, start_time, position, rowid";
		// The table exists only after the module was enabled with 0.8.0.
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$shifts = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$shifts[] = array('id' => (int) $obj->rowid, 'event_id' => (int) $eventId, 'label' => (string) $obj->label,
				'shift_day' => substr((string) $obj->shift_day, 0, 10), 'start_time' => (string) $obj->start_time,
				'end_time' => (string) $obj->end_time, 'capacity' => (int) $obj->capacity,
				'function_code' => (string) $obj->function_code, 'note' => (string) $obj->note, 'position' => (int) $obj->position);
		}
		$this->db->free($resql);
		$entries = $this->entriesOf(array_column($shifts, 'id'));
		foreach ($shifts as $index => $shift) {
			$own = isset($entries[$shift['id']]) ? $entries[$shift['id']] : array();
			$shifts[$index]['entries'] = $own;
			$shifts[$index]['places'] = VereineShiftRules::places($shift['capacity'], $own);
			$shifts[$index]['hours'] = VereineShiftRules::hours($shift);
		}
		return $shifts;
	}

	/**
	 * The entries of some shifts, by shift.
	 *
	 * @param int[] $shiftIds Shifts
	 * @return array<int,array<int,array{id:int,shift_id:int,member_id:int,name:string,status:string,source:string,hours:float,note:string}>>
	 */
	public function entriesOf(array $shiftIds)
	{
		global $conf;

		$ids = array();
		foreach ($shiftIds as $id) {
			if ((int) $id > 0) {
				$ids[] = (int) $id;
			}
		}
		if (!$ids) {
			return array();
		}
		$sql = "SELECT e.rowid, e.fk_shift, e.fk_adherent, e.status, e.source, e.hours, e.note, d.firstname, d.lastname";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_event_shift_entry as e";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent as d ON d.rowid = e.fk_adherent";
		$sql .= " WHERE e.entity = ".((int) $conf->entity)." AND e.fk_shift IN (".implode(', ', $ids).") ORDER BY e.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$entries = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$entries[(int) $obj->fk_shift][] = array('id' => (int) $obj->rowid, 'shift_id' => (int) $obj->fk_shift,
				'member_id' => (int) $obj->fk_adherent, 'name' => trim((string) $obj->firstname.' '.(string) $obj->lastname),
				'status' => (string) $obj->status, 'source' => (string) $obj->source,
				'hours' => $obj->hours === null ? 0.0 : (float) $obj->hours, 'note' => (string) $obj->note);
		}
		$this->db->free($resql);
		return $entries;
	}

	/**
	 * One shift, null when there is none.
	 *
	 * @param int $id Shift
	 * @return array<string,mixed>|null
	 */
	public function fetch($id)
	{
		global $conf;

		$sql = "SELECT fk_event FROM ".MAIN_DB_PREFIX."vereine_event_shift WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		foreach ($this->forEvent((int) $obj->fk_event) as $shift) {
			if ($shift['id'] === (int) $id) {
				return $shift;
			}
		}
		return null;
	}

	/**
	 * Store a shift, a new one when there is no id.
	 *
	 * @param int                 $id      Shift, 0 for a new one
	 * @param int                 $eventId Event
	 * @param array<string,mixed> $data    Keys label, shift_day, start_time, end_time, capacity, function_code, note
	 * @param User                $user    Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function save($id, $eventId, array $data, $user)
	{
		global $conf;

		$this->errors = VereineShiftRules::validate($data);
		if ($this->errors) {
			return 0;
		}
		$entity = (int) $conf->entity;
		$note = (string) (isset($data['note']) ? $data['note'] : '');
		$values = array(
			'label' => "'".$this->db->escape(trim((string) $data['label']))."'",
			'shift_day' => "'".$this->db->escape(trim((string) $data['shift_day']))."'",
			'start_time' => "'".$this->db->escape(trim((string) (isset($data['start_time']) ? $data['start_time'] : '')))."'",
			'end_time' => "'".$this->db->escape(trim((string) (isset($data['end_time']) ? $data['end_time'] : '')))."'",
			'capacity' => (string) max(1, (int) $data['capacity']),
			'function_code' => "'".$this->db->escape(trim((string) (isset($data['function_code']) ? $data['function_code'] : '')))."'",
			'note' => $note !== '' ? "'".$this->db->escape($note)."'" : "NULL",
			'fk_user_modif' => (string) (int) $user->id,
		);
		if ((int) $id > 0) {
			$set = array();
			foreach ($values as $column => $value) {
				$set[] = $column.' = '.$value;
			}
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_event_shift SET ".implode(', ', $set);
			$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".$entity;
		} else {
			$values['entity'] = (string) $entity;
			$values['fk_event'] = (string) (int) $eventId;
			$values['position'] = (string) $this->nextPosition((int) $eventId);
			$values['datec'] = "'".$this->db->idate(dol_now())."'";
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_event_shift (".implode(', ', array_keys($values)).")";
			$sql .= " VALUES (".implode(', ', array_values($values)).")";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::SHIFT_SAVED, 0, 0, trim((string) $data['label']).' '.trim((string) $data['shift_day']));
		return 1;
	}

	/**
	 * Remove a shift with everyone on it.
	 *
	 * @param int  $id   Shift
	 * @param User $user Who removes
	 * @return int 1 when removed, -1 on error
	 */
	public function remove($id, $user)
	{
		global $conf;

		$entity = (int) $conf->entity;
		$this->db->begin();
		foreach (array('vereine_event_shift_entry WHERE fk_shift = '.((int) $id), 'vereine_event_shift WHERE rowid = '.((int) $id)) as $what) {
			if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX.$what." AND entity = ".$entity)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::SHIFT_SAVED, 0, 0, 'shift '.((int) $id).' removed');
		return 1;
	}

	/**
	 * The next free place among the shifts of an event.
	 *
	 * @param int $eventId Event
	 * @return int
	 */
	private function nextPosition($eventId)
	{
		global $conf;

		$sql = "SELECT MAX(position) as last FROM ".MAIN_DB_PREFIX."vereine_event_shift WHERE entity = ".((int) $conf->entity);
		$sql .= " AND fk_event = ".((int) $eventId);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return ($obj ? (int) $obj->last : 0) + 10;
	}

	/**
	 * Put a member on a shift: as a request, or taken straight away when the association enters it.
	 *
	 * @param int    $shiftId  Shift
	 * @param int    $memberId Member
	 * @param string $status   VereineShiftRules::STATUS_REQUESTED or STATUS_CONFIRMED
	 * @param string $source   Where the request came from: dolibarr, or the name of a client (#165)
	 * @param User   $user     Who enters it
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function signUp($shiftId, $memberId, $status, $source, $user)
	{
		global $conf;

		$shift = $this->fetch($shiftId);
		if ($shift === null) {
			$this->errors[] = 'VereineShiftErrorUnknown';
			return 0;
		}
		$elsewhere = array();
		foreach ($this->forEvent((int) $shift['event_id']) as $other) {
			if ($other['id'] === (int) $shiftId) {
				continue;
			}
			foreach ($other['entries'] as $entry) {
				if ((int) $entry['member_id'] === (int) $memberId && $entry['status'] !== VereineShiftRules::STATUS_CANCELLED) {
					$elsewhere[] = $other;
				}
			}
		}
		$refused = VereineShiftRules::refuse($shift, $shift['entries'], $elsewhere, (int) $memberId, (string) $status);
		if ($refused !== '') {
			$this->errors[] = $refused;
			return 0;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_event_shift_entry (entity, fk_shift, fk_adherent, status, source, datec, fk_user_modif)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $shiftId).", ".((int) $memberId).", '".$this->db->escape((string) $status)."',";
		$sql .= " '".$this->db->escape((string) $source !== '' ? (string) $source : 'dolibarr')."', '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::SHIFT_SIGNUP, (int) $memberId, 0, $shift['label'].': '.$status);
		return 1;
	}

	/**
	 * Change what an entry says: taken, really there with hours, or off the shift again.
	 *
	 * @param int    $entryId Entry
	 * @param string $status  One of VereineShiftRules::STATUSES
	 * @param string $hours   Hours really worked, empty for the hours of the shift
	 * @param User   $user    Who says so
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function setStatus($entryId, $status, $hours, $user)
	{
		global $conf;

		if (!in_array((string) $status, VereineShiftRules::STATUSES, true)) {
			$this->errors[] = 'VereineShiftErrorStatus';
			return 0;
		}
		$entity = (int) $conf->entity;
		$sql = "SELECT e.fk_shift, e.fk_adherent, s.capacity, s.label, s.start_time, s.end_time FROM ".MAIN_DB_PREFIX."vereine_event_shift_entry as e";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_event_shift as s ON s.rowid = e.fk_shift";
		$sql .= " WHERE e.rowid = ".((int) $entryId)." AND e.entity = ".$entity;
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			$this->errors[] = 'VereineShiftErrorUnknown';
			return 0;
		}
		// Taking somebody may not push the shift over what it holds; the others already on it count.
		if (in_array((string) $status, VereineShiftRules::TAKING, true)) {
			$others = array();
			foreach ($this->entriesOf(array((int) $obj->fk_shift)) as $entries) {
				foreach ($entries as $entry) {
					if ($entry['id'] !== (int) $entryId) {
						$others[] = $entry;
					}
				}
			}
			if (VereineShiftRules::places((int) $obj->capacity, $others)['full']) {
				$this->errors[] = 'VereineShiftErrorFull';
				return 0;
			}
		}
		$worked = trim((string) $hours);
		if ($worked === '' && (string) $status === VereineShiftRules::STATUS_DONE) {
			$worked = (string) VereineShiftRules::hours(array('start_time' => (string) $obj->start_time, 'end_time' => (string) $obj->end_time));
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_event_shift_entry SET status = '".$this->db->escape((string) $status)."'";
		$sql .= ", hours = ".($worked !== '' && is_numeric($worked) ? ((float) $worked) : "NULL");
		$sql .= ", fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $entryId)." AND entity = ".$entity;
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, $status === VereineShiftRules::STATUS_DONE ? VereineLog::SHIFT_DONE : VereineLog::SHIFT_SIGNUP,
			(int) $obj->fk_adherent, 0, (string) $obj->label.': '.$status);
		return 1;
	}

	/**
	 * What the shifts of an event add up to: places, people taken and hours really worked.
	 *
	 * @param int $eventId Event
	 * @return array{shifts:int,capacity:int,taken:int,requested:int,done:int,hours:float}
	 */
	public function summary($eventId)
	{
		$summary = array('shifts' => 0, 'capacity' => 0, 'taken' => 0, 'requested' => 0, 'done' => 0, 'hours' => 0.0);
		foreach ($this->forEvent($eventId) as $shift) {
			$summary['shifts']++;
			$summary['capacity'] += (int) $shift['capacity'];
			$summary['taken'] += (int) $shift['places']['taken'];
			$summary['requested'] += (int) $shift['places']['requested'];
			$summary['done'] += (int) $shift['places']['done'];
			foreach ($shift['entries'] as $entry) {
				if ($entry['status'] === VereineShiftRules::STATUS_DONE) {
					$summary['hours'] += (float) $entry['hours'];
				}
			}
		}
		$summary['hours'] = round($summary['hours'], 2);
		return $summary;
	}

	/**
	 * The shifts one member is on, for their own view.
	 *
	 * @param int    $memberId Member
	 * @param string $from     Day from which to look, empty for all
	 * @return array<int,array{shift_id:int,event_id:int,event:string,label:string,shift_day:string,start_time:string,end_time:string,status:string}>
	 */
	public function forMember($memberId, $from = '')
	{
		global $conf;

		if ((int) $memberId < 1) {
			return array();
		}
		$sql = "SELECT s.rowid, s.fk_event, s.label, s.shift_day, s.start_time, s.end_time, e.status, v.label as event_label";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_event_shift_entry as e";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_event_shift as s ON s.rowid = e.fk_shift";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_event as v ON v.rowid = s.fk_event";
		$sql .= " WHERE e.entity = ".((int) $conf->entity)." AND e.fk_adherent = ".((int) $memberId);
		$sql .= " AND e.status <> '".VereineShiftRules::STATUS_CANCELLED."'";
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $from)) {
			$sql .= " AND s.shift_day >= '".$this->db->escape((string) $from)."'";
		}
		$sql .= " ORDER BY s.shift_day, s.start_time";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$mine = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$mine[] = array('shift_id' => (int) $obj->rowid, 'event_id' => (int) $obj->fk_event, 'event' => (string) $obj->event_label,
				'label' => (string) $obj->label, 'shift_day' => substr((string) $obj->shift_day, 0, 10),
				'start_time' => (string) $obj->start_time, 'end_time' => (string) $obj->end_time, 'status' => (string) $obj->status);
		}
		$this->db->free($resql);
		return $mine;
	}
}
