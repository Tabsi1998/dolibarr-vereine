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
 * \file    class/vereinemeetingportal.class.php
 * \ingroup vereine
 * \brief   Meetings for a member through an application (#159): the ones they were invited to, an answer, a motion.
 *
 * A member sees a meeting because they are on its list of invitations, never because they are a member:
 * a board meeting stays with the board. The binding invitation still goes the way the statutes want;
 * this is one more way to look at it. The same service can serve a member portal later (#25).
 */

require_once __DIR__.'/vereinemotionrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Meetings for members through an application.
 */
class VereineMeetingPortal
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * @var string[] Messages for the caller
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
	 * The meetings a member was invited to, newest first, with their own answer and motions.
	 *
	 * @param int $memberId Member
	 * @return array<int,array<string,mixed>>
	 */
	public function meetingsFor($memberId)
	{
		global $conf;

		require_once __DIR__.'/vereinestatutes.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

		$rules = (new VereineStatutes($this->db))->rules();
		$p = MAIN_DB_PREFIX;
		$sql = "SELECT m.rowid, m.kind, m.title, m.meeting_day, m.meeting_time, m.place, m.format, m.access, m.agenda, m.status, i.voting, r.response, r.responded_at";
		$sql .= " FROM ".$p."vereine_meeting as m INNER JOIN ".$p."vereine_meeting_invitation as i ON i.fk_meeting = m.rowid AND i.fk_adherent = ".((int) $memberId);
		$sql .= " LEFT JOIN ".$p."vereine_meeting_response as r ON r.fk_meeting = m.rowid AND r.fk_adherent = ".((int) $memberId);
		$sql .= " WHERE m.entity = ".((int) $conf->entity)." AND m.status IN ('invited', 'held', 'cancelled')";
		$sql .= " GROUP BY m.rowid, m.kind, m.title, m.meeting_day, m.meeting_time, m.place, m.format, m.access, m.agenda, m.status, i.voting, r.response, r.responded_at";
		$sql .= " ORDER BY m.meeting_day DESC, m.meeting_time DESC, m.rowid DESC";
		$meetings = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$agenda = json_decode((string) $obj->agenda, true);
			$general = (string) $obj->kind !== 'board';
			$day = substr((string) $obj->meeting_day, 0, 10);
			$meetings[] = array('id' => (int) $obj->rowid, 'kind' => (string) $obj->kind, 'title' => (string) $obj->title, 'day' => $day,
				'time' => (string) $obj->meeting_time, 'timezone' => (string) getServerTimeZoneString(), 'format' => (string) $obj->format,
				'place' => (string) $obj->format !== 'virtual' ? (string) $obj->place : '', 'access' => (string) $obj->format !== 'physical' ? (string) $obj->access : '',
				'status' => (string) $obj->status, 'agenda' => is_array($agenda) ? array_values($agenda) : array(), 'voting' => (int) $obj->voting === 1,
				'response' => (string) $obj->response, 'responded_at' => (string) $obj->responded_at !== '' ? dol_print_date($this->db->jdate($obj->responded_at), 'dayhourrfc') : '',
				'motion_deadline' => $general ? VereineMotionRules::deadline($day, (int) $rules['motion_days']) : '', 'motions' => $this->motionsOf((int) $obj->rowid, (int) $memberId));
		}
		return $meetings;
	}

	/**
	 * Keep whether a member means to come. The same answer again changes nothing.
	 *
	 * @param int    $memberId  Member
	 * @param int    $meetingId Meeting
	 * @param string $response  yes, no or maybe
	 * @param string $client    The application
	 * @param string $today     Today
	 * @return int 1 when kept, 0 when refused (see errors), -1 on error
	 */
	public function respond($memberId, $meetingId, $response, $client, $today)
	{
		global $conf;

		$this->errors = array();
		$meeting = $this->openMeeting($memberId, $meetingId, $today);
		if ($meeting === null) {
			return 0;
		}
		if (!in_array($response, VereineMotionRules::RESPONSES, true)) {
			$this->errors[] = 'response must be one of: '.implode(', ', VereineMotionRules::RESPONSES);
			return 0;
		}
		// The same answer again changes nothing, not even the time.
		if ($meeting['response'] === $response) {
			return 1;
		}
		$p = MAIN_DB_PREFIX;
		$now = $this->db->idate(dol_now());
		if ($meeting['response'] !== '') {
			$sql = "UPDATE ".$p."vereine_meeting_response SET response = '".$this->db->escape($response)."', client = '".$this->db->escape($client)."', responded_at = '".$now."'";
			$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_meeting = ".((int) $meetingId)." AND fk_adherent = ".((int) $memberId);
		} else {
			$sql = "INSERT INTO ".$p."vereine_meeting_response (entity, fk_meeting, fk_adherent, response, client, responded_at) VALUES (".((int) $conf->entity).",";
			$sql .= " ".((int) $meetingId).", ".((int) $memberId).", '".$this->db->escape($response)."', '".$this->db->escape($client)."', '".$now."')";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, null, VereineLog::MEETING_RESPONSE, (int) $memberId, 0, 'meeting '.((int) $meetingId).': '.$response);
		return 1;
	}

	/**
	 * Take a motion for the agenda of a general assembly. The same motion again is the same; another under the same id is refused.
	 *
	 * @param int                 $memberId  Member
	 * @param int                 $meetingId Meeting
	 * @param array<string,mixed> $sent      external_id, title, text
	 * @param string              $client    The application
	 * @param string              $today     Today
	 * @return array<string,mixed>|null The motion; null when refused (see errors, conflict when the id is taken)
	 */
	public function submitMotion($memberId, $meetingId, $sent, $client, $today)
	{
		global $conf;

		$this->errors = array();
		$meeting = $this->openMeeting($memberId, $meetingId, $today);
		if ($meeting === null) {
			return null;
		}
		if ($meeting['kind'] === 'board') {
			$this->errors[] = 'motions are for general assemblies';
			return null;
		}
		$checked = VereineMotionRules::check($sent);
		if ($checked['errors']) {
			$this->errors = $checked['errors'];
			return null;
		}
		$motion = $checked['motion'];
		$fingerprint = VereineMotionRules::fingerprint($motion);
		$p = MAIN_DB_PREFIX;
		$where = " WHERE entity = ".((int) $conf->entity)." AND client = '".$this->db->escape($client)."' AND external_id = '".$this->db->escape($motion['external_id'])."'";
		$resql = $this->db->query("SELECT rowid, fk_meeting, fk_adherent, fingerprint FROM ".$p."vereine_motion".$where);
		$known = $resql ? $this->db->fetch_object($resql) : null;
		if ($known) {
			if ((string) $known->fingerprint !== $fingerprint || (int) $known->fk_meeting !== (int) $meetingId || (int) $known->fk_adherent !== (int) $memberId) {
				$this->errors[] = 'conflict';
				return null;
			}
			return $this->motion((int) $known->rowid);
		}
		$late = $today > $meeting['motion_deadline'] ? 1 : 0;
		$sql = "INSERT INTO ".$p."vereine_motion (entity, fk_meeting, fk_adherent, client, external_id, title, text, fingerprint, received_at, late, status) VALUES (";
		$sql .= ((int) $conf->entity).", ".((int) $meetingId).", ".((int) $memberId).", '".$this->db->escape($client)."', '".$this->db->escape($motion['external_id'])."',";
		$sql .= " '".$this->db->escape($motion['title'])."', '".$this->db->escape($motion['text'])."', '".$fingerprint."', '".$this->db->idate(dol_now())."', ".$late.", 'received')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$id = (int) $this->db->last_insert_id($p.'vereine_motion');
		VereineLog::add($this->db, null, VereineLog::MOTION, (int) $memberId, 0, 'meeting '.((int) $meetingId).($late ? ', late' : ''));
		return $this->motion($id);
	}

	/**
	 * The answers to a meeting, counted, and its motions, for the board in Dolibarr.
	 *
	 * @param int $meetingId Meeting
	 * @return array{responses:array<string,int>,motions:array<int,array<string,mixed>>}
	 */
	public function forMeeting($meetingId)
	{
		global $conf;

		$responses = array_fill_keys(VereineMotionRules::RESPONSES, 0);
		$resql = $this->db->query("SELECT response, COUNT(*) as n FROM ".MAIN_DB_PREFIX."vereine_meeting_response WHERE entity = ".((int) $conf->entity)
			." AND fk_meeting = ".((int) $meetingId)." GROUP BY response");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$responses[(string) $obj->response] = (int) $obj->n;
		}
		$motions = array();
		$resql = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_motion WHERE entity = ".((int) $conf->entity)." AND fk_meeting = ".((int) $meetingId)." ORDER BY rowid");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$motions[] = $this->motion((int) $obj->rowid, true);
		}
		return array('responses' => $responses, 'motions' => $motions);
	}

	/**
	 * The board decides on a motion; accepted, it goes on the agenda as its last item.
	 *
	 * @param int    $motionId Motion
	 * @param string $state    accepted or rejected
	 * @param User   $user     Who
	 * @return int 1 when decided, 0 when there was nothing to decide, -1 on error
	 */
	public function decide($motionId, $state, $user)
	{
		global $conf, $langs;

		$motion = $this->motion((int) $motionId, true);
		if ($motion === null || $motion['status'] !== 'received' || !in_array($state, array('accepted', 'rejected'), true)) {
			return 0;
		}
		$p = MAIN_DB_PREFIX;
		$this->db->begin();
		$ok = (bool) $this->db->query("UPDATE ".$p."vereine_motion SET status = '".$this->db->escape($state)."', decided_at = '".$this->db->idate(dol_now())."', fk_user_decided = ".((int) $user->id)
			." WHERE rowid = ".((int) $motionId)." AND entity = ".((int) $conf->entity)." AND status = 'received'");
		if ($ok && $state === 'accepted') {
			$resql = $this->db->query("SELECT agenda FROM ".$p."vereine_meeting WHERE rowid = ".((int) $motion['meeting_id']));
			$obj = $resql ? $this->db->fetch_object($resql) : null;
			$agenda = $obj ? json_decode((string) $obj->agenda, true) : array();
			$agenda = is_array($agenda) ? $agenda : array();
			$agenda[] = mb_substr($langs->transnoentities('VereineMotionAgendaItem', $motion['name'], $motion['title']), 0, 255, 'UTF-8');
			$ok = (bool) $this->db->query("UPDATE ".$p."vereine_meeting SET agenda = '".$this->db->escape((string) json_encode($agenda))."' WHERE rowid = ".((int) $motion['meeting_id']));
		}
		if (!$ok) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::MOTION, $motion['member_id'], 0, 'meeting '.$motion['meeting_id'].': '.$state);
		return 1;
	}

	/**
	 * A meeting the member was invited to that is still ahead, or null with the reason in errors.
	 *
	 * @param int    $memberId  Member
	 * @param int    $meetingId Meeting
	 * @param string $today     Today
	 * @return array<string,mixed>|null
	 */
	private function openMeeting($memberId, $meetingId, $today)
	{
		foreach ($this->meetingsFor($memberId) as $meeting) {
			if ($meeting['id'] !== (int) $meetingId) {
				continue;
			}
			if ($meeting['status'] !== 'invited' || $meeting['day'] < $today) {
				$this->errors[] = 'the meeting is over or cancelled';
				return null;
			}
			return $meeting;
		}
		$this->errors[] = 'not found';
		return null;
	}

	/**
	 * The motions of a member for a meeting, as the member may see them.
	 *
	 * @param int $meetingId Meeting
	 * @param int $memberId  Member
	 * @return array<int,array<string,mixed>>
	 */
	private function motionsOf($meetingId, $memberId)
	{
		$list = array();
		$resql = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_motion WHERE fk_meeting = ".((int) $meetingId)." AND fk_adherent = ".((int) $memberId)." ORDER BY rowid");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$list[] = $this->motion((int) $obj->rowid);
		}
		return $list;
	}

	/**
	 * One motion: what the member may see, and for the board also who and which meeting.
	 *
	 * @param int  $id    Motion
	 * @param bool $board Whether the board looks at it
	 * @return array<string,mixed>|null
	 */
	private function motion($id, $board = false)
	{
		$sql = "SELECT m.rowid, m.fk_meeting, m.fk_adherent, m.external_id, m.title, m.text, m.received_at, m.late, m.status, a.firstname, a.lastname";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_motion as m LEFT JOIN ".MAIN_DB_PREFIX."adherent as a ON a.rowid = m.fk_adherent WHERE m.rowid = ".((int) $id);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		$motion = array('external_id' => (string) $obj->external_id, 'title' => (string) $obj->title, 'text' => (string) $obj->text,
			'received_at' => dol_print_date($this->db->jdate($obj->received_at), 'dayhourrfc'), 'late' => (int) $obj->late === 1, 'status' => (string) $obj->status);
		if ($board) {
			$motion += array('id' => (int) $obj->rowid, 'meeting_id' => (int) $obj->fk_meeting, 'member_id' => (int) $obj->fk_adherent, 'name' => trim($obj->firstname.' '.$obj->lastname));
		}
		return $motion;
	}
}
