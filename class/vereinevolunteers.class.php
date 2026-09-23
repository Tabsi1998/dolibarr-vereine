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
 * \file    class/vereinevolunteers.class.php
 * \ingroup vereine
 * \brief   Days of voluntary work and their allowances (#7): recorded, checked, listed for the report.
 *
 * Every entry is checked against the limits the moment it is stored, together with the other entries
 * of the same person, and what goes over them is marked there and then. The confirmed helper shifts of
 * the events (#23) are offered for recording, so a shift that really happened is paid once and nothing
 * is typed twice. What comes out at the end of the year is the list the reports are prepared from.
 */

require_once __DIR__.'/vereinevolunteerrules.class.php';
require_once __DIR__.'/vereinefunctions.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * The days of voluntary work of the association.
 */
class VereineVolunteers
{
	/** The functions that record allowances: the money of the association is their business. */
	const RECORDERS = array('kassier', 'kassier_stv', 'obmann');

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
	 * @var string[] What the last stored entry goes over
	 */
	public $findings = array();

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
	 * Whether someone may record allowances: administrators, the treasurer and the chair.
	 *
	 * @param User   $user  Who looks
	 * @param string $today Today, YYYY-MM-DD
	 * @return bool
	 */
	public function mayRecord($user, $today)
	{
		if (!empty($user->admin)) {
			return true;
		}
		if ((int) $user->fk_member < 1) {
			return false;
		}
		$functions = new VereineFunctions($this->db);
		foreach ($functions->holdersByCode($today) as $code => $holders) {
			if (!in_array($code, self::RECORDERS, true)) {
				continue;
			}
			foreach ($holders as $holder) {
				if ((int) $holder['member_id'] === (int) $user->fk_member) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The entries of a calendar year, or of one person in it.
	 *
	 * @param int $year     Calendar year
	 * @param int $memberId Member, 0 for everybody
	 * @return array<int,array<string,mixed>> By day
	 */
	public function entries($year, $memberId = 0)
	{
		global $conf;

		$sql = "SELECT v.rowid, v.fk_adherent, v.duty_day, v.activity, v.kind, v.amount, v.hours, v.fk_shift_entry, v.fk_payout, v.paid_on, v.note,";
		$sql .= " d.firstname, d.lastname FROM ".MAIN_DB_PREFIX."vereine_volunteer as v";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent as d ON d.rowid = v.fk_adherent";
		$sql .= " WHERE v.entity = ".((int) $conf->entity)." AND v.duty_day >= '".sprintf('%04d', (int) $year)."-01-01'";
		$sql .= " AND v.duty_day <= '".sprintf('%04d', (int) $year)."-12-31'";
		if ((int) $memberId > 0) {
			$sql .= " AND v.fk_adherent = ".((int) $memberId);
		}
		$sql .= " ORDER BY v.duty_day, v.rowid";
		// The table exists only after the module was enabled with 0.9.0.
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array('id' => (int) $obj->rowid, 'member_id' => (int) $obj->fk_adherent,
				'name' => trim((string) $obj->firstname.' '.(string) $obj->lastname), 'day' => substr((string) $obj->duty_day, 0, 10),
				'activity' => (string) $obj->activity, 'kind' => (string) $obj->kind, 'amount' => round((float) $obj->amount, 2),
				'hours' => $obj->hours === null ? 0.0 : (float) $obj->hours, 'shift_entry_id' => (int) $obj->fk_shift_entry,
				'payout_id' => (int) $obj->fk_payout,
				'paid_on' => $obj->paid_on ? substr((string) $obj->paid_on, 0, 10) : '', 'note' => (string) $obj->note);
		}
		$this->db->free($resql);
		// What each entry goes over, measured with the other entries of the same person.
		foreach ($rows as $index => $row) {
			$others = array();
			foreach ($rows as $other) {
				if ($other['member_id'] === $row['member_id'] && $other['id'] !== $row['id']
					&& ($other['day'] < $row['day'] || ($other['day'] === $row['day'] && $other['id'] < $row['id']))) {
					$others[] = $other;
				}
			}
			$rows[$index]['check'] = VereineVolunteerRules::check($row, $others);
		}
		return $rows;
	}

	/**
	 * Record a day of voluntary work.
	 *
	 * @param array<string,mixed> $entry Keys member_id, day, activity, kind, amount, hours, shift_entry_id, note
	 * @param User                $user  Who records
	 * @return int The entry, 0 when refused (see errors), -1 on error; findings holds what it goes over
	 */
	public function record(array $entry, $user)
	{
		global $conf;

		$this->errors = VereineVolunteerRules::validate($entry);
		$this->findings = array();
		if ($this->errors) {
			return 0;
		}
		$amount = round((float) str_replace(',', '.', (string) $entry['amount']), 2);
		$day = (string) $entry['day'];
		$others = $this->entries((int) substr($day, 0, 4), (int) $entry['member_id']);
		$check = VereineVolunteerRules::check(array('day' => $day, 'kind' => (string) $entry['kind'], 'amount' => $amount), $others);
		$shift = (int) (isset($entry['shift_entry_id']) ? $entry['shift_entry_id'] : 0);
		$hours = isset($entry['hours']) && is_numeric($entry['hours']) ? (float) $entry['hours'] : null;
		$note = trim((string) (isset($entry['note']) ? $entry['note'] : ''));
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_volunteer (entity, fk_adherent, duty_day, activity, kind, amount, hours,";
		$sql .= " fk_shift_entry, note, datec, fk_user_modif) VALUES (".((int) $conf->entity).", ".((int) $entry['member_id']).",";
		$sql .= " '".$this->db->escape($day)."', '".$this->db->escape(trim((string) $entry['activity']))."',";
		$sql .= " '".$this->db->escape((string) $entry['kind'])."', ".$amount.", ".($hours !== null ? $hours : "NULL").",";
		$sql .= " ".($shift > 0 ? $shift : "NULL").", ".($note !== '' ? "'".$this->db->escape($note)."'" : "NULL").",";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			if ($this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				$this->errors[] = 'VereineVolunteerErrorShiftTwice';
				return 0;
			}
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_volunteer');
		$this->findings = $check['findings'];
		VereineLog::add($this->db, $user, VereineLog::VOLUNTEER_RECORDED, (int) $entry['member_id'], 0,
			$day.' '.$entry['kind'].' '.number_format($amount, 2, '.', '').($check['findings'] ? ' ('.implode(', ', $check['findings']).')' : ''));
		return $id;
	}

	/**
	 * Remove an entry that was recorded by mistake. A paid entry stays: it belongs to the books.
	 *
	 * @param int  $id   Entry
	 * @param User $user Who removes
	 * @return int 1 when removed, 0 when refused, -1 on error
	 */
	public function remove($id, $user)
	{
		global $conf;

		$sql = "SELECT fk_adherent, duty_day, paid_on, fk_payout FROM ".MAIN_DB_PREFIX."vereine_volunteer WHERE rowid = ".((int) $id);
		$sql .= " AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			$this->errors[] = 'VereineVolunteerErrorUnknown';
			return 0;
		}
		if ($obj->paid_on) {
			$this->errors[] = 'VereineVolunteerErrorPaid';
			return 0;
		}
		// An entry on a list that is being signed stays; take the list back first.
		if ((int) $obj->fk_payout > 0) {
			$this->errors[] = 'VereineVolunteerErrorOnPayout';
			return 0;
		}
		if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_volunteer WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity))) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::VOLUNTEER_REMOVED, (int) $obj->fk_adherent, 0, substr((string) $obj->duty_day, 0, 10));
		return 1;
	}

	/**
	 * Confirmed helper shifts of a year that have no allowance yet, to record them without typing twice.
	 *
	 * @param int $year Calendar year
	 * @return array<int,array{entry_id:int,member_id:int,name:string,day:string,activity:string,hours:float}>
	 */
	public function openShifts($year)
	{
		global $conf;

		$sql = "SELECT e.rowid, e.fk_adherent, e.hours, s.shift_day, s.label, v.label as event_label, d.firstname, d.lastname";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_event_shift_entry as e";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_event_shift as s ON s.rowid = e.fk_shift";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_event as v ON v.rowid = s.fk_event";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent as d ON d.rowid = e.fk_adherent";
		$sql .= " WHERE e.entity = ".((int) $conf->entity)." AND e.status = 'done'";
		$sql .= " AND s.shift_day >= '".sprintf('%04d', (int) $year)."-01-01' AND s.shift_day <= '".sprintf('%04d', (int) $year)."-12-31'";
		$sql .= " AND e.rowid NOT IN (SELECT fk_shift_entry FROM ".MAIN_DB_PREFIX."vereine_volunteer";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_shift_entry IS NOT NULL)";
		$sql .= " ORDER BY s.shift_day, e.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array('entry_id' => (int) $obj->rowid, 'member_id' => (int) $obj->fk_adherent,
				'name' => trim((string) $obj->firstname.' '.(string) $obj->lastname), 'day' => substr((string) $obj->shift_day, 0, 10),
				'activity' => trim((string) $obj->event_label.': '.(string) $obj->label), 'hours' => $obj->hours === null ? 0.0 : (float) $obj->hours);
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * The year as a table for the reports, one line per person.
	 *
	 * @param int $year Calendar year
	 * @return string CSV with a header line, separated by semicolons as a spreadsheet in Austria expects
	 */
	public function csv($year)
	{
		$lines = array(implode(';', array('Mitglied', 'Name', 'Einsatztage', 'Freiwilligenpauschale klein', 'Freiwilligenpauschale groß',
			'PRAE', 'Hinweise')));
		foreach (VereineVolunteerRules::yearList($this->entries($year), $year) as $person) {
			$lines[] = implode(';', array((string) $person['member_id'], '"'.str_replace('"', '""', $person['name']).'"', (string) $person['days'],
				number_format($person['small'], 2, ',', ''), number_format($person['large'], 2, ',', ''),
				number_format($person['prae'], 2, ',', ''), implode(' ', $person['findings'])));
		}
		return implode("\r\n", $lines)."\r\n";
	}
}
