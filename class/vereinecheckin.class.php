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
 * \file    class/vereinecheckin.class.php
 * \ingroup vereine
 * \brief   Check-in at a general assembly through an application (#272): present or taken back, in the name of the board.
 *
 * Every request is kept with the application's id, who on the board it acted for, when and why, so the meeting shows
 * where its attendance came from. The answer tells the application at once whether the member may vote and how far
 * the assembly is from its quorum.
 */

require_once __DIR__.'/vereinecheckinrules.class.php';
require_once __DIR__.'/vereinemeetings.class.php';
require_once __DIR__.'/vereineballotrules.class.php';
require_once __DIR__.'/vereinestatutes.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Check-in at general assemblies.
 */
class VereineCheckIn
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
	 * @var string Why the last request was refused, see VereineCheckInRules::status()
	 */
	public $problem = '';

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
	 * Mark a member present, or take a check-in back, in the name of somebody on the board.
	 *
	 * @param int    $meetingId  Assembly
	 * @param int    $memberId   Member who came in
	 * @param bool   $present    True to mark present, false to take it back
	 * @param int    $actorId    Member on the board in whose name it happens
	 * @param string $externalId The application's id of the request
	 * @param string $arrived    Time of arrival HH:MM, empty for now
	 * @param string $reason     Why a check-in is taken back
	 * @param string $client     The application, by the login of its user
	 * @param User   $user       Who calls
	 * @return array<string,mixed>|null The answer, null when refused (see problem) or on error (see error)
	 */
	public function record($meetingId, $memberId, $present, $actorId, $externalId, $arrived, $reason, $client, $user)
	{
		global $conf;

		$this->problem = '';
		$this->error = '';
		$entity = (int) $conf->entity;
		$meetings = new VereineMeetings($this->db);
		$meeting = $meetings->fetch((int) $meetingId);
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$attendance = $meeting !== null ? $meetings->attendance((int) $meetingId) : array('rows' => array(), 'voting' => array(), 'names' => array());
		$onBoard = false;
		foreach ($meetings->members($today) as $member) {
			$onBoard = $onBoard || ((int) $member['id'] === (int) $actorId && $member['board'] && (int) $member['status'] === 1);
		}
		$this->problem = VereineCheckInRules::problem($meeting, $today, isset($attendance['rows'][(int) $memberId]), $onBoard);
		if ($this->problem !== '') {
			return null;
		}
		$externalId = VereineCheckInRules::externalId($externalId);
		if ($externalId === '') {
			$this->problem = 'external_id_missing';
			return null;
		}
		$action = $present ? VereineCheckInRules::ACTION_PRESENT : VereineCheckInRules::ACTION_REVOKED;
		$known = $this->known($client, $externalId);
		if ($known !== null) {
			// The same request again changes nothing; another change under the same id is refused.
			if (!VereineCheckInRules::sameRequest($known, $meetingId, $memberId, $action)) {
				$this->problem = 'external_id';
				return null;
			}
			return $this->answer($meeting, (int) $memberId);
		}
		$reason = mb_substr(trim((string) $reason), 0, VereineCheckInRules::REASON_MAX, 'UTF-8');
		if (!$present && $reason === '') {
			$this->problem = 'reason_missing';
			return null;
		}
		$time = $present ? VereineCheckInRules::arrival($arrived, dol_print_date(dol_now(), '%H:%M', 'tzserver')) : '';
		if ($time === null) {
			$this->problem = 'time';
			return null;
		}
		$this->db->begin();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_checkin (entity, fk_meeting, fk_adherent, external_id, client, fk_actor, action, arrived, reason, fk_user, datec)";
		$sql .= " VALUES (".$entity.", ".((int) $meetingId).", ".((int) $memberId).", '".$this->db->escape($externalId)."', '".$this->db->escape(mb_substr((string) $client, 0, 64, 'UTF-8'))."',";
		$sql .= " ".((int) $actorId).", '".$action."', ".($time !== '' ? "'".$this->db->escape($time)."'" : "NULL").", '".$this->db->escape($reason)."', ".((int) $user->id).",";
		$sql .= " '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			// Two applications with the same id at the same moment: the second finds the first.
			if ($this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				$this->problem = 'external_id';
				return null;
			}
			$this->error = $this->db->lasterror();
			return null;
		}
		$exists = isset($attendance['rows'][(int) $memberId]) && $this->stored((int) $meetingId, (int) $memberId);
		if ($present) {
			$sql = $exists
				? "UPDATE ".MAIN_DB_PREFIX."vereine_meeting_attendance SET state = '".VereineAttendanceRules::STATE_PRESENT."', fk_holder = 0, arrived = '".$this->db->escape($time)."', left_at = NULL, fk_user_modif = ".((int) $user->id)
					." WHERE fk_meeting = ".((int) $meetingId)." AND fk_adherent = ".((int) $memberId)." AND entity = ".$entity
				: "INSERT INTO ".MAIN_DB_PREFIX."vereine_meeting_attendance (entity, fk_meeting, fk_adherent, state, fk_holder, arrived, left_at, fk_user_modif) VALUES (".$entity.", ".((int) $meetingId).", ".((int) $memberId).", '"
					.VereineAttendanceRules::STATE_PRESENT."', 0, '".$this->db->escape($time)."', NULL, ".((int) $user->id).")";
		} else {
			$sql = $exists
				? "UPDATE ".MAIN_DB_PREFIX."vereine_meeting_attendance SET state = '".VereineAttendanceRules::STATE_ABSENT."', fk_holder = 0, arrived = NULL, left_at = NULL, fk_user_modif = ".((int) $user->id)
					." WHERE fk_meeting = ".((int) $meetingId)." AND fk_adherent = ".((int) $memberId)." AND entity = ".$entity
				: '';
		}
		if ($sql !== '' && !$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return null;
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::MEETING_ATTENDANCE, (int) $memberId, 0,
			$meeting['title'].': '.$action.' through '.$client.' for board member '.((int) $actorId).($reason !== '' ? ' - '.$reason : ''));
		return $this->answer($meetings->fetch((int) $meetingId), (int) $memberId);
	}

	/**
	 * What came in through applications for an assembly, newest first, for the meeting in Dolibarr.
	 *
	 * @param int $meetingId Assembly
	 * @return array<int,array{at:int,member_id:int,actor_id:int,action:string,arrived:string,reason:string,client:string}>
	 */
	public function history($meetingId)
	{
		global $conf;

		$list = array();
		$sql = "SELECT fk_adherent, fk_actor, action, arrived, reason, client, datec FROM ".MAIN_DB_PREFIX."vereine_checkin";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_meeting = ".((int) $meetingId)." ORDER BY rowid DESC";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$list[] = array('at' => (int) $this->db->jdate($obj->datec), 'member_id' => (int) $obj->fk_adherent, 'actor_id' => (int) $obj->fk_actor,
				'action' => (string) $obj->action, 'arrived' => (string) $obj->arrived, 'reason' => (string) $obj->reason, 'client' => (string) $obj->client);
		}
		return $list;
	}

	/**
	 * The answer for a member: state, voting right with its reason, and the numbers of the assembly now.
	 *
	 * @param array<string,mixed> $meeting  Assembly
	 * @param int                 $memberId Member
	 * @return array<string,mixed>
	 */
	private function answer(array $meeting, $memberId)
	{
		global $conf;

		$meetings = new VereineMeetings($this->db);
		$attendance = $meetings->attendance((int) $meeting['id']);
		$row = $attendance['rows'][(int) $memberId];
		$status = 0;
		$resql = $this->db->query("SELECT statut FROM ".MAIN_DB_PREFIX."adherent WHERE rowid = ".((int) $memberId));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		$status = $obj ? (int) $obj->statut : 0;
		$lastDay = '';
		$resql = $this->db->query("SELECT last_day FROM ".MAIN_DB_PREFIX."vereine_member_exit WHERE entity = ".((int) $conf->entity)." AND fk_adherent = ".((int) $memberId)." AND status <> 'cancelled'");
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		$lastDay = $obj ? substr((string) $obj->last_day, 0, 10) : '';
		$right = VereineBallotRules::right(array('member_id' => (int) $memberId, 'voting' => !empty($attendance['voting'][(int) $memberId])), array('status' => $status),
			$lastDay, 0, (string) $meeting['day']);
		$quorum = VereineAttendanceRules::quorum((string) $meeting['kind'], $attendance['rows'], $attendance['voting'], (new VereineStatutes($this->db))->rules(),
			dol_print_date(dol_now(), '%H:%M', 'tzserver'));
		return VereineCheckInRules::answer((int) $memberId, $row, $right, $quorum);
	}

	/**
	 * A request of an application seen before, by its id.
	 *
	 * @param string $client     The application
	 * @param string $externalId Its id of the request
	 * @return array{meeting_id:int,member_id:int,action:string}|null
	 */
	private function known($client, $externalId)
	{
		global $conf;

		$sql = "SELECT fk_meeting, fk_adherent, action FROM ".MAIN_DB_PREFIX."vereine_checkin WHERE entity = ".((int) $conf->entity);
		$sql .= " AND client = '".$this->db->escape((string) $client)."' AND external_id = '".$this->db->escape((string) $externalId)."'";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? array('meeting_id' => (int) $obj->fk_meeting, 'member_id' => (int) $obj->fk_adherent, 'action' => (string) $obj->action) : null;
	}

	/**
	 * Whether the attendance of a member is stored already, not only suggested from the invitation.
	 *
	 * @param int $meetingId Assembly
	 * @param int $memberId  Member
	 * @return bool
	 */
	private function stored($meetingId, $memberId)
	{
		global $conf;

		$resql = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_meeting_attendance WHERE fk_meeting = ".((int) $meetingId)." AND fk_adherent = ".((int) $memberId)
			." AND entity = ".((int) $conf->entity));
		return $resql && $this->db->fetch_object($resql);
	}
}
