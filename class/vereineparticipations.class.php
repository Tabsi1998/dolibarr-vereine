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
 * \file    class/vereineparticipations.class.php
 * \ingroup vereine
 * \brief   Participations of members (#273): recorded in Dolibarr or by an application, helper shifts read from the shifts.
 *
 * The association needs them for honours, its annual report and grant applications that ask for active
 * members. An application records a participation under its own id: the same request twice is one entry,
 * and it takes back only what it recorded itself.
 */

require_once __DIR__.'/vereineparticipationrules.class.php';
require_once __DIR__.'/vereineshiftrules.class.php';
require_once __DIR__.'/vereineeventrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Participations of members.
 */
class VereineParticipations
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
	 * @var string[] Fields refused, or 'external_id' when the id holds another participation
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
	 * Put the suggested kinds into the dictionary; a kind the association changed or switched off stays as it is.
	 *
	 * @return int 1 when done, -1 on error
	 */
	public function ensureStandard()
	{
		global $conf;

		foreach (VereineParticipationRules::STANDARD as $code => $kind) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_vereine_participation_kind (entity, code, label, position, active) SELECT ".((int) $conf->entity).", '".$this->db->escape($code)."',";
			$sql .= " '".$this->db->escape($kind[0])."', ".((int) $kind[1]).", 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."c_vereine_participation_kind";
			$sql .= " WHERE entity = ".((int) $conf->entity)." AND code = '".$this->db->escape($code)."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		return 1;
	}

	/**
	 * The kinds of the dictionary.
	 *
	 * @param bool $activeOnly Only those switched on, for new entries
	 * @return array<string,string> Code => name, in the order of the dictionary
	 */
	public function kinds($activeOnly = true)
	{
		$kinds = array();
		$sql = "SELECT code, label FROM ".MAIN_DB_PREFIX."c_vereine_participation_kind WHERE entity IN (".getEntity('c_vereine_participation_kind').")";
		$resql = $this->db->query($sql.($activeOnly ? " AND active = 1" : "")." ORDER BY position, label");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$kinds[(string) $obj->code] = (string) $obj->label;
		}
		return $kinds;
	}

	/**
	 * The participations of a member, newest first: those recorded and the confirmed helper shifts up to today.
	 *
	 * @param int    $memberId Member
	 * @param string $today    Today, YYYY-MM-DD
	 * @param string $client   The application asking, to show its own ids; empty in Dolibarr
	 * @return array<int,array{id:int,member_id:int,kind:string,kind_label:string,title:string,day:string,hours:float|null,source:string,external_id:string}>
	 */
	public function forMember($memberId, $today, $client = '')
	{
		if ((int) $memberId < 1) {
			return array();
		}
		$list = $this->listing(" AND p.fk_adherent = ".((int) $memberId), " AND e.fk_adherent = ".((int) $memberId), $today, $client);
		usort($list, function ($a, $b) {
			return strcmp($b['day'], $a['day']) ?: $b['id'] - $a['id'];
		});
		return $list;
	}

	/**
	 * Every participation of a year, for the report of active members.
	 *
	 * @param int    $year  Year
	 * @param string $today Today, YYYY-MM-DD
	 * @return array<int,array{id:int,member_id:int,kind:string,kind_label:string,title:string,day:string,hours:float|null,source:string,external_id:string}>
	 */
	public function ofYear($year, $today)
	{
		$from = sprintf('%04d-01-01', (int) $year);
		$to = sprintf('%04d-12-31', (int) $year);
		return $this->listing(" AND p.day BETWEEN '".$from."' AND '".$to."'", " AND s.shift_day BETWEEN '".$from."' AND '".$to."'", $today, '');
	}

	/**
	 * Who was active in a year, with names, as the report shows it.
	 *
	 * @param int    $year  Year
	 * @param string $today Today, YYYY-MM-DD
	 * @return array{total:int,members:array<int,array{name:string,count:int,hours:float,kinds:array<string,array{count:int,hours:float}>}>,kinds:array<string,array{members:int,count:int,hours:float}>,labels:array<string,string>}
	 */
	public function report($year, $today)
	{
		$active = VereineParticipationRules::active($this->ofYear($year, $today));
		$names = array();
		if ($active['members']) {
			$resql = $this->db->query("SELECT rowid, firstname, lastname FROM ".MAIN_DB_PREFIX."adherent WHERE rowid IN (".implode(',', array_map('intval', array_keys($active['members']))).")");
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$names[(int) $obj->rowid] = trim($obj->firstname.' '.$obj->lastname);
			}
		}
		foreach ($active['members'] as $member => $numbers) {
			$active['members'][$member]['name'] = isset($names[$member]) ? $names[$member] : '#'.$member;
		}
		uasort($active['members'], function ($a, $b) {
			return strcoll($a['name'], $b['name']);
		});
		$labels = $this->kinds(false);
		foreach (array_keys($active['kinds']) as $kind) {
			if (!isset($labels[$kind])) {
				$labels[$kind] = $kind;
			}
		}
		return $active + array('labels' => $labels);
	}

	/**
	 * Keep a participation recorded in Dolibarr for one or several members.
	 *
	 * @param int[]               $memberIds Members
	 * @param array<string,mixed> $entered   kind, title, day, hours
	 * @param string              $today     Today, YYYY-MM-DD
	 * @param User                $user      Who
	 * @return int How many were kept, 0 when refused (see errors), -1 on error
	 */
	public function record(array $memberIds, array $entered, $today, $user)
	{
		$this->errors = array();
		$memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds), function ($id) {
			return $id > 0;
		})));
		$checked = VereineParticipationRules::entered($entered, array_keys($this->kinds()), $today);
		$this->errors = $checked['errors'];
		if (!$memberIds) {
			$this->errors[] = 'members';
		}
		if ($this->errors) {
			return 0;
		}
		$this->db->begin();
		foreach ($memberIds as $memberId) {
			if ($this->insert($memberId, $checked['participation'], VereineParticipationRules::SOURCE_DOLIBARR, '', '', $user) < 0) {
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::PARTICIPATION, count($memberIds) === 1 ? $memberIds[0] : 0, 0,
			$checked['participation']['kind'].' '.$checked['participation']['day'].': '.$checked['participation']['title'].' ('.count($memberIds).')');
		return count($memberIds);
	}

	/**
	 * Keep a participation an application sends under its own id: the same request again changes nothing.
	 *
	 * @param int    $memberId Member
	 * @param mixed  $sent     kind, title, day, hours, external_id
	 * @param string $client   The application, by the login of its user
	 * @param string $today    Today, YYYY-MM-DD
	 * @param User   $user     Who calls
	 * @return array<string,mixed>|null The participation, null when refused (see errors) or on error (see error)
	 */
	public function receive($memberId, $sent, $client, $today, $user)
	{
		$this->errors = array();
		$this->error = '';
		$sent = is_array($sent) ? $sent : array();
		$external = isset($sent['external_id']) && is_scalar($sent['external_id']) ? trim((string) $sent['external_id']) : '';
		$checked = VereineParticipationRules::entered($sent, array_keys($this->kinds()), $today);
		if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $external)) {
			array_unshift($checked['errors'], 'external_id');
		}
		if ($checked['errors']) {
			$this->errors = $checked['errors'];
			return null;
		}
		$known = $this->byExternal($client, $external);
		if ($known !== null) {
			if (!VereineParticipationRules::sameRequest($known, $memberId, $checked['participation'])) {
				$this->errors = array('conflict');
				return null;
			}
			return $this->view($known['id'], $client);
		}
		$id = $this->insert((int) $memberId, $checked['participation'], VereineParticipationRules::SOURCE_API, $client, $external, $user);
		if ($id < 0) {
			// Two calls with the same id at the same moment: the second finds the first.
			$known = $this->byExternal($client, $external);
			if ($known !== null && VereineParticipationRules::sameRequest($known, $memberId, $checked['participation'])) {
				return $this->view($known['id'], $client);
			}
			return null;
		}
		VereineLog::add($this->db, $user, VereineLog::PARTICIPATION, (int) $memberId, 0, $checked['participation']['kind'].' '.$checked['participation']['day'].' through '.$client);
		return $this->view($id, $client);
	}

	/**
	 * Take back a participation an application recorded under its id, for that member only.
	 *
	 * @param int    $memberId Member
	 * @param string $external The application's id
	 * @param string $client   The application
	 * @param User   $user     Who calls
	 * @return int 1 when taken back, 0 when there is none, -1 on error
	 */
	public function takeBack($memberId, $external, $client, $user)
	{
		global $conf;

		$known = $this->byExternal($client, (string) $external);
		if ($known === null || $known['member_id'] !== (int) $memberId) {
			return 0;
		}
		if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_participation WHERE rowid = ".((int) $known['id'])." AND entity = ".((int) $conf->entity))) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::PARTICIPATION, (int) $memberId, 0, 'taken back by '.$client);
		return 1;
	}

	/**
	 * Remove a recorded participation in Dolibarr; a helper shift is changed at its event, not here.
	 *
	 * @param int  $id       Participation
	 * @param int  $memberId Member it must belong to
	 * @param User $user     Who
	 * @return int 1 when removed, 0 when there is none, -1 on error
	 */
	public function remove($id, $memberId, $user)
	{
		global $conf;

		$resql = $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_participation WHERE rowid = ".((int) $id)." AND fk_adherent = ".((int) $memberId)." AND entity = ".((int) $conf->entity));
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ((int) $this->db->affected_rows($resql) !== 1) {
			return 0;
		}
		VereineLog::add($this->db, $user, VereineLog::PARTICIPATION, (int) $memberId, 0, 'removed '.((int) $id));
		return 1;
	}

	/**
	 * A participation as an application sees it.
	 *
	 * @param array<string,mixed> $participation From listing()
	 * @return array{kind:string,kind_label:string,title:string,day:string,hours:float|null,source:string,external_id:string}
	 */
	public static function apiView(array $participation)
	{
		return array('kind' => $participation['kind'], 'kind_label' => $participation['kind_label'], 'title' => $participation['title'], 'day' => $participation['day'],
			'hours' => $participation['hours'], 'source' => $participation['source'], 'external_id' => $participation['external_id']);
	}

	/**
	 * Participations recorded and helper shifts, filtered.
	 *
	 * @param string $recorded Condition on the recorded ones (alias p)
	 * @param string $shifts   Condition on the shift entries (aliases e and s)
	 * @param string $today    Today: a shift counts once its day has come
	 * @param string $client   The application asking, to show its own ids
	 * @return array<int,array{id:int,member_id:int,kind:string,kind_label:string,title:string,day:string,hours:float|null,source:string,external_id:string}>
	 */
	private function listing($recorded, $shifts, $today, $client)
	{
		global $conf;

		$labels = $this->kinds(false);
		$label = function ($kind) use ($labels) {
			return isset($labels[$kind]) ? $labels[$kind] : $kind;
		};
		$list = array();
		$sql = "SELECT p.rowid, p.fk_adherent, p.kind, p.title, p.day, p.hours, p.source, p.client, p.external_id FROM ".MAIN_DB_PREFIX."vereine_participation as p";
		$resql = $this->db->query($sql." WHERE p.entity = ".((int) $conf->entity).$recorded." ORDER BY p.day, p.rowid");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$own = $client !== '' && (string) $obj->client === (string) $client;
			$list[] = array('id' => (int) $obj->rowid, 'member_id' => (int) $obj->fk_adherent, 'kind' => (string) $obj->kind, 'kind_label' => $label((string) $obj->kind),
				'title' => (string) $obj->title, 'day' => substr((string) $obj->day, 0, 10), 'hours' => $obj->hours !== null ? round((float) $obj->hours, 2) : null,
				'source' => (string) $obj->source, 'external_id' => $own ? (string) $obj->external_id : '');
		}
		// Helper shifts the association took the member for, of events that took place, once their day has come.
		$sql = "SELECT e.rowid, e.fk_adherent, e.hours, s.label, s.shift_day, s.start_time, s.end_time, v.label as event_label FROM ".MAIN_DB_PREFIX."vereine_event_shift_entry as e";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_event_shift as s ON s.rowid = e.fk_shift INNER JOIN ".MAIN_DB_PREFIX."vereine_event as v ON v.rowid = s.fk_event";
		$sql .= " WHERE e.entity = ".((int) $conf->entity)." AND e.fk_adherent > 0 AND e.status IN ('".implode("', '", VereineShiftRules::TAKING)."')";
		$sql .= " AND v.status <> '".VereineEventRules::STATUS_CANCELLED."' AND s.shift_day <= '".$this->db->escape((string) $today)."'".$shifts;
		$resql = $this->db->query($sql." ORDER BY s.shift_day, e.rowid");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$shift = VereineParticipationRules::fromShift(array('event' => (string) $obj->event_label, 'label' => (string) $obj->label, 'shift_day' => substr((string) $obj->shift_day, 0, 10),
				'start_time' => (string) $obj->start_time, 'end_time' => (string) $obj->end_time, 'hours' => $obj->hours));
			$list[] = array('id' => 0, 'member_id' => (int) $obj->fk_adherent, 'kind' => $shift['kind'], 'kind_label' => $label($shift['kind']), 'title' => $shift['title'],
				'day' => $shift['day'], 'hours' => $shift['hours'], 'source' => $shift['source'], 'external_id' => '');
		}
		return $list;
	}

	/**
	 * Keep one participation.
	 *
	 * @param int                                                       $memberId      Member
	 * @param array{kind:string,title:string,day:string,hours:float|null} $participation Checked
	 * @param string                                                    $source        dolibarr or api
	 * @param string                                                    $client        The application, empty in Dolibarr
	 * @param string                                                    $external      Its id, empty in Dolibarr
	 * @param User                                                      $user          Who
	 * @return int Id, -1 on error
	 */
	private function insert($memberId, array $participation, $source, $client, $external, $user)
	{
		global $conf;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_participation (entity, fk_adherent, kind, title, day, hours, source, client, external_id, datec, fk_user) VALUES (";
		$sql .= ((int) $conf->entity).", ".((int) $memberId).", '".$this->db->escape($participation['kind'])."', '".$this->db->escape($participation['title'])."',";
		$sql .= " '".$this->db->escape($participation['day'])."', ".($participation['hours'] !== null ? number_format((float) $participation['hours'], 2, '.', '') : "NULL").",";
		$sql .= " '".$this->db->escape($source)."', ".($client !== '' ? "'".$this->db->escape(mb_substr($client, 0, 64, 'UTF-8'))."'" : "NULL").",";
		$sql .= " ".($external !== '' ? "'".$this->db->escape($external)."'" : "NULL").", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_participation');
	}

	/**
	 * A participation an application recorded under its id.
	 *
	 * @param string $client   The application
	 * @param string $external Its id
	 * @return array{id:int,member_id:int,kind:string,title:string,day:string,hours:float|null}|null
	 */
	private function byExternal($client, $external)
	{
		global $conf;

		$sql = "SELECT rowid, fk_adherent, kind, title, day, hours FROM ".MAIN_DB_PREFIX."vereine_participation WHERE entity = ".((int) $conf->entity);
		$sql .= " AND client = '".$this->db->escape(mb_substr((string) $client, 0, 64, 'UTF-8'))."' AND external_id = '".$this->db->escape((string) $external)."'";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? array('id' => (int) $obj->rowid, 'member_id' => (int) $obj->fk_adherent, 'kind' => (string) $obj->kind, 'title' => (string) $obj->title,
			'day' => substr((string) $obj->day, 0, 10), 'hours' => $obj->hours !== null ? round((float) $obj->hours, 2) : null) : null;
	}

	/**
	 * One recorded participation as an application sees it.
	 *
	 * @param int    $id     Participation
	 * @param string $client The application asking
	 * @return array<string,mixed>
	 */
	private function view($id, $client)
	{
		foreach ($this->listing(" AND p.rowid = ".((int) $id), " AND 1 = 0", '0000-00-00', $client) as $participation) {
			return self::apiView($participation);
		}
		return array();
	}
}
