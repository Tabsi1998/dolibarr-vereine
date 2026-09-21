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
 * \file    class/vereinemeetings.class.php
 * \ingroup vereine
 * \brief   Stores meetings and sends their invitations by e-mail and letter, with proof per person.
 */

require_once __DIR__.'/vereinemeetingrules.class.php';
require_once __DIR__.'/vereineattendancerules.class.php';
require_once __DIR__.'/vereinevoterules.class.php';
require_once __DIR__.'/vereineminutesrules.class.php';
require_once __DIR__.'/vereinestatutes.class.php';
require_once __DIR__.'/vereinefunctions.class.php';
require_once __DIR__.'/vereineresolutions.class.php';
require_once __DIR__.'/vereinemail.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Meetings of the association.
 */
class VereineMeetings
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
	 * @var array<int,string[]> Language keys of the last refused attendance, by member id
	 */
	public $rowErrors = array();

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
	 * Meetings, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function fetchAll()
	{
		global $conf;

		$sql = "SELECT rowid, kind, title, meeting_day, meeting_time, place, format, access, agenda, status, invited_at, fk_actioncomm, fk_chair, fk_keeper FROM ".MAIN_DB_PREFIX."vereine_meeting";
		$sql .= " WHERE entity = ".((int) $conf->entity)." ORDER BY meeting_day DESC, meeting_time DESC, rowid DESC";
		// The table exists only after the module was enabled with 0.5.4.
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$meetings = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$agenda = json_decode((string) $obj->agenda, true);
			$meetings[] = array('id' => (int) $obj->rowid, 'kind' => (string) $obj->kind, 'title' => (string) $obj->title, 'day' => (string) $obj->meeting_day,
				'time' => (string) $obj->meeting_time, 'place' => (string) $obj->place, 'format' => (string) $obj->format, 'access' => (string) $obj->access,
				'agenda' => is_array($agenda) ? $agenda : array(), 'status' => (string) $obj->status, 'invited_at' => $obj->invited_at ? $this->db->jdate($obj->invited_at) : 0,
				'actioncomm_id' => (int) $obj->fk_actioncomm, 'chair_id' => (int) $obj->fk_chair, 'keeper_id' => (int) $obj->fk_keeper);
		}
		$this->db->free($resql);
		return $meetings;
	}

	/**
	 * One meeting.
	 *
	 * @param int $id Meeting
	 * @return array<string,mixed>|null
	 */
	public function fetch($id)
	{
		foreach ($this->fetchAll() as $meeting) {
			if ($meeting['id'] === (int) $id) {
				return $meeting;
			}
		}
		return null;
	}

	/**
	 * Store a new meeting or change a planned one.
	 *
	 * @param int                 $id      Meeting, 0 for a new one
	 * @param array<string,mixed> $entered Entered data
	 * @param User                $user    Who stores
	 * @return int Id, 0 when refused (see errors), -1 on error
	 */
	public function save($id, array $entered, $user)
	{
		global $conf;

		$statutes = new VereineStatutes($this->db);
		$meeting = VereineMeetingRules::normalize($entered);
		$this->errors = VereineMeetingRules::validate($meeting, $statutes->rules());
		$current = (int) $id > 0 ? $this->fetch($id) : null;
		if ((int) $id > 0 && ($current === null || $current['status'] !== VereineMeetingRules::STATUS_PLANNED)) {
			$this->errors[] = 'VereineMeetingErrorNotPlanned';
		}
		if ($this->errors) {
			return 0;
		}
		$fields = "kind = '".$this->db->escape($meeting['kind'])."', title = '".$this->db->escape($meeting['title'])."', meeting_day = '".$this->db->escape($meeting['day'])."',";
		$fields .= " meeting_time = '".$this->db->escape($meeting['time'])."', place = '".$this->db->escape($meeting['place'])."', format = '".$this->db->escape($meeting['format'])."',";
		$fields .= " access = '".$this->db->escape($meeting['access'])."', agenda = '".$this->db->escape(json_encode($meeting['agenda']))."', fk_user_modif = ".((int) $user->id);
		if ($current !== null) {
			if (!$this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_meeting SET ".$fields." WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity))) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			// Texts prepared for agenda items follow their title when the agenda is reordered.
			if ($current['agenda'] !== $meeting['agenda'] && $this->moveNotes($id, VereineMinutesRules::remap($current['agenda'], $meeting['agenda'])) < 0) {
				return -1;
			}
			return (int) $id;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_meeting (entity, kind, title, meeting_day, meeting_time, format, status, datec)";
		$sql .= " VALUES (".((int) $conf->entity).", '', '', '".$this->db->escape($meeting['day'])."', '', '', '".VereineMeetingRules::STATUS_PLANNED."', '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$newId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_meeting');
		if (!$this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_meeting SET ".$fields." WHERE rowid = ".$newId)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::MEETING_CREATED, 0, 0, $meeting['kind'].' '.$meeting['day'].' '.$meeting['title']);
		return $newId;
	}

	/**
	 * Members with what an invitation needs, and whether they are on the board on a day.
	 *
	 * @param string $day Day of the meeting
	 * @return array<int,array<string,mixed>> Keys id, status, type_id, email, name, board, address, zip, town
	 */
	public function members($day)
	{
		$sql = "SELECT d.rowid, d.statut, d.fk_adherent_type, d.email, d.firstname, d.lastname, d.societe, d.morphy, d.address, d.zip, d.town FROM ".MAIN_DB_PREFIX."adherent as d";
		$sql .= " WHERE d.entity IN (".getEntity('member').") ORDER BY d.lastname, d.firstname, d.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$members = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$members[(int) $obj->rowid] = array('id' => (int) $obj->rowid, 'status' => (int) $obj->statut, 'type_id' => (int) $obj->fk_adherent_type, 'email' => (string) $obj->email,
				'name' => $obj->morphy === 'mor' && (string) $obj->societe !== '' ? (string) $obj->societe : trim($obj->firstname.' '.$obj->lastname),
				'board' => false, 'address' => (string) $obj->address, 'zip' => (string) $obj->zip, 'town' => (string) $obj->town);
		}
		$this->db->free($resql);
		$functions = new VereineFunctions($this->db);
		$board = array();
		foreach ($functions->fetchAll(true) as $function) {
			$board[$function['id']] = $function['board'];
		}
		foreach ($functions->terms() as $term) {
			if (!empty($board[$term['function_id']]) && isset($members[$term['member_id']]) && VereineFunctionRules::isActive($term, $day)) {
				$members[$term['member_id']]['board'] = true;
			}
		}
		return array_values($members);
	}

	/**
	 * Who a meeting invites and how.
	 *
	 * @param array<string,mixed> $meeting Meeting
	 * @return array<int,array<string,mixed>> Recipients of VereineMeetingRules::recipients()
	 */
	public function recipients(array $meeting)
	{
		$statutes = new VereineStatutes($this->db);
		return VereineMeetingRules::recipients($meeting['kind'], $this->members($meeting['day']), $statutes->rules());
	}

	/**
	 * Invitations sent for a meeting.
	 *
	 * @param int $id Meeting
	 * @return array<int,array<string,mixed>>
	 */
	public function invitations($id)
	{
		global $conf;

		$sql = "SELECT rowid, fk_adherent, name, email, channel, voting, sent_at, error, attempts, tried_at FROM ".MAIN_DB_PREFIX."vereine_meeting_invitation";
		$sql .= " WHERE fk_meeting = ".((int) $id)." AND entity = ".((int) $conf->entity)." ORDER BY rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$invitations = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$invitations[] = array('id' => (int) $obj->rowid, 'member_id' => (int) $obj->fk_adherent, 'name' => (string) $obj->name, 'email' => (string) $obj->email,
				'channel' => (string) $obj->channel, 'voting' => (int) $obj->voting === 1, 'sent_at' => $obj->sent_at ? $this->db->jdate($obj->sent_at) : 0,
				'error' => (string) $obj->error, 'attempts' => max(1, (int) $obj->attempts), 'tried_at' => $obj->tried_at ? $this->db->jdate($obj->tried_at) : 0);
		}
		$this->db->free($resql);
		return $invitations;
	}

	/**
	 * Send the invitations of a planned meeting: e-mails, one PDF with the letters, an agenda event and the proof per person.
	 *
	 * @param int       $id          Meeting
	 * @param User      $user        Who invites
	 * @param Translate $outputlangs Language of the invitation
	 * @return int Invitations written, 0 when refused (see errors), -1 on error
	 */
	public function invite($id, $user, $outputlangs)
	{
		global $conf, $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

		$meeting = $this->fetch($id);
		if ($meeting === null || $meeting['status'] !== VereineMeetingRules::STATUS_PLANNED) {
			$this->errors = array('VereineMeetingErrorNotPlanned');
			return 0;
		}
		$recipients = $this->recipients($meeting);
		if (!$recipients) {
			$this->errors = array('VereineMeetingErrorNobody');
			return 0;
		}
		$statutes = new VereineStatutes($this->db);
		$rules = $statutes->rules();
		$from = VereineMail::sender();
		$members = array();
		foreach ($this->members($meeting['day']) as $member) {
			$members[$member['id']] = $member;
		}
		$letters = array();
		$written = 0;
		foreach ($recipients as $recipient) {
			$sentAt = null;
			$error = '';
			if ($recipient['channel'] === VereineMeetingRules::CHANNEL_EMAIL) {
				$mail = new CMailFile($outputlangs->transnoentities('VereineMeetingMailSubject', $meeting['title'], vereineMeetingDay($meeting['day'], $outputlangs).' '.$meeting['time']),
					$recipient['email'], $from, $this->invitationText($meeting, $recipient, $rules, $outputlangs), array(), array(), array(), '', '', 0, 0, '', '', 'meeting'.$meeting['id']);
				if ($mail->sendfile()) {
					$sentAt = dol_now();
				} else {
					$error = dol_trunc((string) $mail->error, 250, 'right', 'UTF-8', 1);
				}
			} else {
				$letters[] = $recipient + array('address' => isset($members[$recipient['member_id']]) ? $members[$recipient['member_id']] : array());
				$sentAt = dol_now();
			}
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_meeting_invitation (entity, fk_meeting, fk_adherent, name, email, channel, voting, sent_at, error, datec)";
			$sql .= " VALUES (".((int) $conf->entity).", ".((int) $meeting['id']).", ".((int) $recipient['member_id']).", '".$this->db->escape($recipient['name'])."',";
			$sql .= " '".$this->db->escape($recipient['email'])."', '".$this->db->escape($recipient['channel'])."', ".($recipient['voting'] ? 1 : 0).",";
			$sql .= " ".($sentAt !== null ? "'".$this->db->idate($sentAt)."'" : "NULL").", ".($error !== '' ? "'".$this->db->escape($error)."'" : "NULL").", '".$this->db->idate(dol_now())."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$written++;
		}
		if ($letters && $this->buildLetters($meeting, $letters, $rules, $outputlangs) === '') {
			return -1;
		}
		$eventId = 0;
		if (isModEnabled('agenda')) {
			require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
			$event = new ActionComm($this->db);
			$event->type_code = 'AC_OTH';
			$event->label = $meeting['title'];
			$event->note_private = implode("\n", $meeting['agenda']);
			$event->location = $meeting['place'];
			$event->datep = dol_mktime((int) substr($meeting['time'], 0, 2), (int) substr($meeting['time'], 3, 2), 0, (int) substr($meeting['day'], 5, 2),
				(int) substr($meeting['day'], 8, 2), (int) substr($meeting['day'], 0, 4));
			$event->datef = $event->datep + 7200;
			$event->percentage = -1;
			$event->userownerid = (int) $user->id;
			$eventId = (int) $event->create($user);
			if ($eventId <= 0) {
				dol_syslog(__METHOD__.' agenda event: '.$event->error, LOG_WARNING);
				$eventId = 0;
			}
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_meeting SET status = '".VereineMeetingRules::STATUS_INVITED."', invited_at = '".$this->db->idate(dol_now())."',";
		$sql .= " fk_actioncomm = ".($eventId > 0 ? $eventId : "NULL").", fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $meeting['id'])." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::MEETING_INVITED, 0, 0, $meeting['title'].': '.$written.' ('.count($letters).' letters)');
		return $written;
	}

	/**
	 * Send the e-mail invitations again that did not go out, for instance after the sender was corrected.
	 *
	 * @param int       $id          Meeting
	 * @param User      $user        Who sends
	 * @param Translate $outputlangs Language of the invitation
	 * @return int Number of invitations sent now, 0 when refused (see errors), -1 on error
	 */
	public function resend($id, $user, $outputlangs)
	{
		global $conf;

		$this->errors = array();
		$meeting = $this->fetch($id);
		if ($meeting === null || $meeting['status'] !== VereineMeetingRules::STATUS_INVITED) {
			$this->errors[] = 'VereineMeetingErrorNotInvitedYet';
			return 0;
		}
		$failed = array();
		foreach ($this->invitations($id) as $invitation) {
			if ($invitation['channel'] === VereineMeetingRules::CHANNEL_EMAIL && $invitation['sent_at'] === 0) {
				$failed[] = $invitation;
			}
		}
		if (!$failed) {
			$this->errors[] = 'VereineMeetingErrorNothingFailed';
			return 0;
		}
		$statutes = new VereineStatutes($this->db);
		$rules = $statutes->rules();
		$mail = new VereineMail($this->db);
		$sent = 0;
		foreach ($failed as $invitation) {
			$recipient = array('member_id' => $invitation['member_id'], 'name' => $invitation['name'], 'email' => $invitation['email'],
				'channel' => $invitation['channel'], 'voting' => $invitation['voting']);
			$ok = $mail->send($outputlangs->transnoentities('VereineMeetingMailSubject', $meeting['title'], vereineMeetingDay($meeting['day'], $outputlangs).' '.$meeting['time']),
				$invitation['email'], $this->invitationText($meeting, $recipient, $rules, $outputlangs), 'meeting'.$meeting['id']);
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_meeting_invitation SET attempts = attempts + 1, tried_at = '".$this->db->idate(dol_now())."',";
			$sql .= $ok ? " sent_at = '".$this->db->idate(dol_now())."', error = NULL" : " error = '".$this->db->escape(dol_trunc($mail->error, 250, 'right', 'UTF-8', 1))."'";
			$sql .= " WHERE rowid = ".((int) $invitation['id'])." AND entity = ".((int) $conf->entity);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			if ($ok) {
				$sent++;
			}
		}
		VereineLog::add($this->db, $user, VereineLog::MEETING_INVITED, 0, 0, $meeting['title'].': '.$sent.' of '.count($failed).' sent again');
		if ($sent < 1) {
			$this->errors[] = 'VereineMeetingErrorStillFailing';
			return 0;
		}
		return $sent;
	}

	/**
	 * Mark a meeting as held or called off.
	 *
	 * @param int    $id     Meeting
	 * @param string $status STATUS_HELD or STATUS_CANCELLED
	 * @param User   $user   Who marks it
	 * @return int 1 when marked, 0 when refused, -1 on error
	 */
	public function setStatus($id, $status, $user)
	{
		global $conf;

		$meeting = $this->fetch($id);
		if ($meeting === null || !in_array($status, array(VereineMeetingRules::STATUS_HELD, VereineMeetingRules::STATUS_CANCELLED), true)
			|| in_array($meeting['status'], array(VereineMeetingRules::STATUS_HELD, VereineMeetingRules::STATUS_CANCELLED), true)) {
			$this->errors = array('VereineMeetingErrorStatus');
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_meeting SET status = '".$this->db->escape($status)."', fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::MEETING_STATUS, 0, 0, $meeting['title'].': '.$status);
		return 1;
	}

	/**
	 * Day of the last ordinary general assembly that took place or is invited to, before a day.
	 *
	 * @param string $day Day
	 * @return string YYYY-MM-DD, empty when none
	 */
	public function lastGeneral($day)
	{
		$last = '';
		foreach ($this->fetchAll() as $meeting) {
			if ($meeting['kind'] === VereineMeetingRules::KIND_GENERAL && $meeting['status'] !== VereineMeetingRules::STATUS_CANCELLED && $meeting['day'] <= $day && $meeting['day'] > $last) {
				$last = $meeting['day'];
			}
		}
		return $last;
	}

	/**
	 * Attendance of the invited members and their voting right.
	 *
	 * @param int $id Meeting
	 * @return array{rows:array<int,array<string,mixed>>,voting:array<int,bool>,names:array<int,string>}
	 */
	public function attendance($id)
	{
		global $conf;

		$invited = array();
		$voting = array();
		$names = array();
		foreach ($this->invitations($id) as $invitation) {
			$invited[] = $invitation['member_id'];
			$voting[$invitation['member_id']] = $invitation['voting'];
			$names[$invitation['member_id']] = $invitation['name'];
		}
		$stored = array();
		$sql = "SELECT fk_adherent, state, fk_holder, arrived, left_at FROM ".MAIN_DB_PREFIX."vereine_meeting_attendance";
		$sql .= " WHERE fk_meeting = ".((int) $id)." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$stored[(int) $obj->fk_adherent] = array('state' => (string) $obj->state, 'holder' => (int) $obj->fk_holder, 'arrived' => (string) $obj->arrived, 'left' => (string) $obj->left_at);
		}
		return array('rows' => VereineAttendanceRules::normalize($stored, $invited), 'voting' => $voting, 'names' => $names);
	}

	/**
	 * Store the attendance of a meeting that was invited to.
	 *
	 * @param int                 $id      Meeting
	 * @param array<mixed,mixed>  $entered Entered rows by member id
	 * @param User                $user    Who stores
	 * @return int 1 when stored, 0 when refused (see errors and rowErrors), -1 on error
	 */
	public function saveAttendance($id, array $entered, $user)
	{
		global $conf;

		$this->errors = array();
		$this->rowErrors = array();
		$meeting = $this->fetch($id);
		if ($meeting === null || !in_array($meeting['status'], array(VereineMeetingRules::STATUS_INVITED, VereineMeetingRules::STATUS_HELD), true)) {
			$this->errors = array('VereineMeetingErrorNotInvited');
			return 0;
		}
		$current = $this->attendance($id);
		$rows = VereineAttendanceRules::normalize($entered, array_keys($current['rows']));
		$statutes = new VereineStatutes($this->db);
		$this->rowErrors = VereineAttendanceRules::validate($meeting['kind'], $rows, $current['voting'], $statutes->rules());
		if ($this->rowErrors) {
			return 0;
		}
		$this->db->begin();
		if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_meeting_attendance WHERE fk_meeting = ".((int) $id)." AND entity = ".((int) $conf->entity))) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		foreach ($rows as $memberId => $row) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_meeting_attendance (entity, fk_meeting, fk_adherent, state, fk_holder, arrived, left_at, fk_user_modif)";
			$sql .= " VALUES (".((int) $conf->entity).", ".((int) $id).", ".((int) $memberId).", '".$this->db->escape($row['state'])."', ".((int) $row['holder']).",";
			$sql .= " ".($row['arrived'] !== '' ? "'".$this->db->escape($row['arrived'])."'" : "NULL").", ".($row['left'] !== '' ? "'".$this->db->escape($row['left'])."'" : "NULL").", ".((int) $user->id).")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		$present = count(array_filter($rows, function ($row) {
			return $row['state'] === VereineAttendanceRules::STATE_PRESENT;
		}));
		VereineLog::add($this->db, $user, VereineLog::MEETING_ATTENDANCE, 0, 0, $meeting['title'].': '.$present.' present');
		return 1;
	}

	/**
	 * Votes and elections of a meeting, in the order they took place.
	 *
	 * @param int $id Meeting
	 * @return array<int,array<string,mixed>>
	 */
	public function votes($id)
	{
		global $conf;

		$sql = "SELECT rowid, item, kind, title, secret, yes, no, abstain, tie, vote_time, majority, passed, fk_function, fk_candidate, applied FROM ".MAIN_DB_PREFIX."vereine_meeting_vote";
		$sql .= " WHERE fk_meeting = ".((int) $id)." AND entity = ".((int) $conf->entity)." ORDER BY rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$votes = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$votes[] = array('id' => (int) $obj->rowid, 'item' => (int) $obj->item, 'kind' => (string) $obj->kind, 'title' => (string) $obj->title, 'secret' => (int) $obj->secret === 1,
				'yes' => (int) $obj->yes, 'no' => (int) $obj->no, 'abstain' => (int) $obj->abstain, 'tie' => (string) $obj->tie, 'time' => (string) $obj->vote_time,
				'majority' => (string) $obj->majority, 'passed' => (int) $obj->passed === 1, 'function_id' => (int) $obj->fk_function, 'candidate_id' => (int) $obj->fk_candidate,
				'applied' => (string) $obj->applied);
		}
		$this->db->free($resql);
		return $votes;
	}

	/**
	 * Store a vote with its result; an election that passed starts the term of office, a change of the statutes that passed stores the version and its notice.
	 *
	 * @param int                 $id      Meeting
	 * @param array<string,mixed> $entered Entered vote
	 * @param User                $user    Who stores
	 * @param Translate           $outputlangs Language of letters
	 * @return int Id of the vote, 0 when refused (see errors), -1 on error
	 */
	public function saveVote($id, array $entered, $user, $outputlangs)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';

		$this->errors = array();
		$meeting = $this->fetch($id);
		if ($meeting === null || !in_array($meeting['status'], array(VereineMeetingRules::STATUS_INVITED, VereineMeetingRules::STATUS_HELD), true)) {
			$this->errors = array('VereineMeetingErrorNotInvited');
			return 0;
		}
		$statutes = new VereineStatutes($this->db);
		$rules = $statutes->rules();
		$vote = VereineVoteRules::normalize($entered);
		$attendance = $this->attendance($id);
		$quorum = VereineAttendanceRules::quorum($meeting['kind'], $attendance['rows'], $attendance['voting'], $rules, $vote['time']);
		$this->errors = VereineVoteRules::validate($meeting['kind'], $vote, $quorum['votes'], count($meeting['agenda']));
		if (!$quorum['reached']) {
			$this->errors[] = 'VereineVoteErrorQuorum';
		}
		if ($this->errors) {
			return 0;
		}
		$majority = VereineVoteRules::majority($vote['kind'], $rules);
		$result = VereineVoteRules::result($vote, $majority, $meeting['kind'], $rules);
		$applied = '';
		if ($result['passed'] && $vote['kind'] === VereineVoteRules::KIND_ELECTION) {
			$member = new Adherent($this->db);
			$functions = new VereineFunctions($this->db);
			$function = null;
			foreach ($functions->fetchAll(true) as $candidate) {
				if ($candidate['id'] === $vote['function_id']) {
					$function = $candidate;
				}
			}
			if ($function === null || $member->fetch($vote['candidate_id']) <= 0) {
				$this->errors = array('VereineVoteErrorElection');
				return 0;
			}
			if ((int) $function['max'] === 1) {
				foreach ($functions->terms() as $term) {
					if ($term['function_id'] === $function['id'] && $term['member_id'] !== $vote['candidate_id'] && VereineFunctionRules::isActive($term, $meeting['day'])) {
						$end = $term['start'] < $meeting['day'] ? VereineMeetingRules::addDays($meeting['day'], -1) : $term['start'];
						if ($functions->endTerm($term['id'], $end, $user) < 0) {
							$this->error = $functions->error;
							return -1;
						}
					}
				}
			}
			$termId = $functions->addTerm($member, $function['id'], $meeting['day'], '', $meeting['title'].': '.$vote['title'], $user);
			if ($termId <= 0) {
				$this->errors = $functions->errors ?: array('VereineVoteErrorElection');
				$this->error = $functions->error;
				return $termId < 0 ? -1 : 0;
			}
			$applied = 'term:'.$termId;
		} elseif ($result['passed'] && $vote['kind'] === VereineVoteRules::KIND_STATUTES) {
			$version = $statutes->saveVersion($meeting['day'], '', $meeting['title'].': '.$vote['title'], $user);
			if ($version <= 0) {
				$this->errors = $statutes->errors;
				$this->error = $statutes->error;
				return $version < 0 ? -1 : 0;
			}
			$applied = 'version:'.$version;
			require_once __DIR__.'/vereineauthorityletters.class.php';
			$letters = new VereineAuthorityLetters($this->db);
			if ($letters->create(VereineAuthorityRules::KIND_STATUTES, array('date' => $meeting['day']), $user, $outputlangs) > 0) {
				$applied .= ',notice';
			}
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_meeting_vote (entity, fk_meeting, item, kind, title, secret, yes, no, abstain, tie, vote_time, majority, passed, fk_function, fk_candidate, applied, datec, fk_user_modif)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $id).", ".((int) $vote['item']).", '".$this->db->escape($vote['kind'])."', '".$this->db->escape($vote['title'])."',";
		$sql .= " ".($vote['secret'] ? 1 : 0).", ".((int) $vote['yes']).", ".((int) $vote['no']).", ".((int) $vote['abstain']).", '".$this->db->escape($vote['tie'])."',";
		$sql .= " '".$this->db->escape($vote['time'])."', '".$this->db->escape($majority)."', ".($result['passed'] ? 1 : 0).", ".((int) $vote['function_id']).",";
		$sql .= " ".((int) $vote['candidate_id']).", '".$this->db->escape($applied)."', '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$voteId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_meeting_vote');
		$register = new VereineResolutions($this->db);
		if ($register->addFromVote($meeting, $voteId, $vote + array('majority' => $majority, 'passed' => $result['passed'], 'applied' => $applied), $user) < 0) {
			$this->error = $register->error;
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::MEETING_VOTE, $vote['candidate_id'], 0, $vote['title'].': '.($result['passed'] ? 'passed' : 'rejected').($applied !== '' ? ' ('.$applied.')' : ''));
		return $voteId;
	}

	/**
	 * Agenda templates of the association, or the suggested ones.
	 *
	 * @return array<string,array<int,array{title:string,text:string,required:bool}>>
	 */
	public function templates()
	{
		global $conf;

		$stored = array();
		$sql = "SELECT kind, title, body, mandatory FROM ".MAIN_DB_PREFIX."vereine_meeting_template WHERE entity = ".((int) $conf->entity)." ORDER BY kind, position, rowid";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$stored[(string) $obj->kind][] = array('title' => (string) $obj->title, 'text' => (string) $obj->body, 'required' => (int) $obj->mandatory === 1);
		}
		return VereineMinutesRules::normalize($stored);
	}

	/**
	 * Store the agenda templates; kinds without an item get the suggested templates.
	 *
	 * @param array<string,mixed>|null $entered Templates by kind, null to go back to the suggested templates
	 * @param User                     $user    Who stores
	 * @return int 1 when stored, -1 on error
	 */
	public function saveTemplates($entered, $user)
	{
		global $conf;

		$this->db->begin();
		if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_meeting_template WHERE entity = ".((int) $conf->entity))) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ($entered !== null) {
			foreach (VereineMinutesRules::normalize($entered) as $kind => $items) {
				foreach ($items as $position => $item) {
					$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_meeting_template (entity, kind, position, title, body, mandatory, fk_user_modif)";
					$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($kind)."', ".((int) $position).", '".$this->db->escape($item['title'])."',";
					$sql .= " '".$this->db->escape($item['text'])."', ".($item['required'] ? 1 : 0).", ".((int) $user->id).")";
					if (!$this->db->query($sql)) {
						$this->error = $this->db->lasterror();
						$this->db->rollback();
						return -1;
					}
				}
			}
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Stored texts of the agenda items of a meeting.
	 *
	 * @param int $id Meeting
	 * @return array<int,string> Text by item number from 1
	 */
	public function notes($id)
	{
		global $conf;

		$notes = array();
		$resql = $this->db->query("SELECT item, body FROM ".MAIN_DB_PREFIX."vereine_meeting_note WHERE fk_meeting = ".((int) $id)." AND entity = ".((int) $conf->entity));
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$notes[(int) $obj->item] = (string) $obj->body;
		}
		return $notes;
	}

	/**
	 * Store the texts of the agenda items; an emptied text stays empty and does not fall back to the template.
	 *
	 * @param int                $id      Meeting
	 * @param array<mixed,mixed> $entered Texts by item number from 1
	 * @param User               $user    Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function saveNotes($id, array $entered, $user)
	{
		global $conf;

		$this->errors = array();
		$meeting = $this->fetch($id);
		if ($meeting === null || $meeting['status'] === VereineMeetingRules::STATUS_CANCELLED) {
			$this->errors = array('VereineMinutesErrorMeeting');
			return 0;
		}
		$notes = array();
		foreach (array_keys($meeting['agenda']) as $index) {
			$notes[$index + 1] = VereineMinutesRules::text(isset($entered[$index + 1]) ? $entered[$index + 1] : '');
		}
		$this->db->begin();
		if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_meeting_note WHERE fk_meeting = ".((int) $id)." AND entity = ".((int) $conf->entity))) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		foreach ($notes as $item => $text) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_meeting_note (entity, fk_meeting, item, body, fk_user_modif)";
			$sql .= " VALUES (".((int) $conf->entity).", ".((int) $id).", ".((int) $item).", '".$this->db->escape($text)."', ".((int) $user->id).")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * The agenda items with their text, stored or from the template, and the text with the real numbers of the meeting.
	 *
	 * The numbers of an item are those at the time of its first vote, so a later arrival counts from then on; an item without a
	 * vote takes the time of the last vote before it, or the start of the meeting.
	 *
	 * @param array<string,mixed> $meeting     Meeting
	 * @param Translate           $outputlangs Language of the day in words
	 * @return array<int,array{item:int,title:string,text:string,stored:bool,filled:string}>
	 */
	public function items(array $meeting, $outputlangs)
	{
		global $mysoc;

		$templates = $this->templates();
		$notes = $this->notes($meeting['id']);
		$rules = (new VereineStatutes($this->db))->rules();
		$attendance = $this->attendance($meeting['id']);
		$votes = $this->votes($meeting['id']);
		$day = vereineMeetingDay($meeting['day'], $outputlangs);
		$items = array();
		$time = $meeting['time'];
		foreach (array_values($meeting['agenda']) as $index => $title) {
			$number = $index + 1;
			$onItem = array_values(array_filter($votes, function ($vote) use ($number) {
				return $vote['item'] === $number;
			}));
			if ($onItem && $onItem[0]['time'] !== '') {
				$time = $onItem[0]['time'];
			}
			$quorum = VereineAttendanceRules::quorum($meeting['kind'], $attendance['rows'], $attendance['voting'], $rules, $time);
			$text = isset($notes[$number]) ? $notes[$number] : VereineMinutesRules::textFor($templates, $meeting['kind'], $title);
			$values = VereineMinutesRules::values($meeting, $quorum, $onItem, trim((string) $mysoc->name), $day);
			$items[] = array('item' => $number, 'title' => $title, 'text' => $text, 'stored' => isset($notes[$number]), 'filled' => VereineMinutesRules::fill($text, $values));
		}
		return $items;
	}

	/**
	 * Move the texts of agenda items to their new numbers; texts of removed items are deleted.
	 *
	 * @param int            $id  Meeting
	 * @param array<int,int> $map New item number by old item number
	 * @return int 1 when moved, -1 on error
	 */
	private function moveNotes($id, array $map)
	{
		global $conf;

		$notes = $this->notes($id);
		if (!$notes) {
			return 1;
		}
		$where = " WHERE fk_meeting = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_meeting_note".$where)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		foreach ($notes as $item => $text) {
			if (!isset($map[$item])) {
				continue;
			}
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_meeting_note (entity, fk_meeting, item, body) VALUES (".((int) $conf->entity).", ".((int) $id).", ".((int) $map[$item]).", '".$this->db->escape($text)."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		return 1;
	}

	/**
	 * Path of the letters of a meeting.
	 *
	 * @param int $id Meeting
	 * @return string
	 */
	public static function lettersPath($id)
	{
		global $conf;

		return DOL_DATA_ROOT.($conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/vereine/meetings/einladung-'.((int) $id).'-briefe.pdf';
	}

	/**
	 * The invitation as text, the same in an e-mail and a letter.
	 *
	 * @param array<string,mixed> $meeting     Meeting
	 * @param array<string,mixed> $recipient   Recipient
	 * @param array<string,mixed> $rules       Normalized rules of the statutes
	 * @param Translate           $outputlangs Language
	 * @return string
	 */
	public function invitationText(array $meeting, array $recipient, array $rules, $outputlangs)
	{
		global $mysoc;

		$outputlangs->load('vereine@vereine');
		$lines = array($outputlangs->transnoentities('VereineMeetingMailGreeting', $recipient['name']), '',
			$outputlangs->transnoentities('VereineMeetingMailIntro_'.$meeting['kind'], trim((string) $mysoc->name)), '',
			$meeting['title'],
			$outputlangs->transnoentities('VereineMeetingMailWhen', vereineMeetingDay($meeting['day'], $outputlangs), $meeting['time']));
		if ($meeting['place'] !== '') {
			$lines[] = $outputlangs->transnoentities('VereineMeetingMailWhere', $meeting['place']);
		}
		if ($meeting['format'] !== VereineMeetingRules::FORMAT_PHYSICAL) {
			$lines[] = $outputlangs->transnoentities('VereineMeetingMailFormat_'.$meeting['format']);
			$lines[] = $outputlangs->transnoentities('VereineMeetingMailAccess', $meeting['access']);
		}
		$lines[] = '';
		$lines[] = $outputlangs->transnoentities('VereineMeetingMailAgenda');
		foreach ($meeting['agenda'] as $index => $item) {
			$lines[] = ($index + 1).'. '.$item;
		}
		$motions = VereineMeetingRules::motionsBy($meeting, $rules);
		if ($motions !== '') {
			$lines[] = '';
			$lines[] = $outputlangs->transnoentities('VereineMeetingMailMotions', vereineMeetingDay($motions, $outputlangs));
		}
		if (!$recipient['voting']) {
			$lines[] = '';
			$lines[] = $outputlangs->transnoentities('VereineMeetingMailNotVoting');
		}
		$lines[] = '';
		$lines[] = $outputlangs->transnoentities('VereineMeetingMailClosing');
		$lines[] = $outputlangs->transnoentities('VereineMeetingMailSignature', trim((string) $mysoc->name));
		return implode("\n", $lines);
	}

	/**
	 * One PDF with a letter per recipient without e-mail.
	 *
	 * @param array<string,mixed>            $meeting     Meeting
	 * @param array<int,array<string,mixed>> $letters     Recipients with their member under address
	 * @param array<string,mixed>            $rules       Normalized rules of the statutes
	 * @param Translate                      $outputlangs Language
	 * @return string Path, empty on error
	 */
	private function buildLetters(array $meeting, array $letters, array $rules, $outputlangs)
	{
		global $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$file = self::lettersPath($meeting['id']);
		if (dol_mkdir(dirname($file)) < 0) {
			$this->error = 'cannot create '.dirname($file);
			return '';
		}
		$pdf = pdf_getInstance();
		$font = pdf_getPDFFont($outputlangs);
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->SetMargins(20, 20, 20);
		$pdf->SetAutoPageBreak(true, 20);
		foreach ($letters as $letter) {
			$pdf->AddPage();
			$pdf->SetFont($font, 'B', 11);
			$pdf->MultiCell(0, 5, trim((string) $mysoc->name), 0, 'L');
			$pdf->SetFont($font, '', 10);
			$pdf->MultiCell(0, 5, trim($mysoc->address."\n".trim($mysoc->zip.' '.$mysoc->town)), 0, 'L');
			$pdf->Ln(10);
			$member = $letter['address'];
			$pdf->MultiCell(0, 5, trim($letter['name']."\n".(isset($member['address']) ? $member['address'] : '')."\n".trim((isset($member['zip']) ? $member['zip'] : '').' '.(isset($member['town']) ? $member['town'] : ''))), 0, 'L');
			$pdf->Ln(8);
			$pdf->MultiCell(0, 5, trim($mysoc->town.', '.dol_print_date(dol_now(), 'day', 'tzserver', $outputlangs), ', '), 0, 'R');
			$pdf->Ln(4);
			$pdf->SetFont($font, 'B', 11);
			$pdf->MultiCell(0, 5, $outputlangs->transnoentities('VereineMeetingMailSubject', $meeting['title'], vereineMeetingDay($meeting['day'], $outputlangs).' '.$meeting['time']), 0, 'L');
			$pdf->Ln(3);
			$pdf->SetFont($font, '', 10);
			$pdf->MultiCell(0, 5, $this->invitationText($meeting, $letter, $rules, $outputlangs), 0, 'L');
		}
		$pdf->Output($file, 'F');
		if (!is_file($file)) {
			$this->error = 'the PDF was not written';
			return '';
		}
		dolChmod($file);
		return $file;
	}
}

/**
 * A day in the words of a language, such as 17.09.2026.
 *
 * @param string    $day         Day YYYY-MM-DD
 * @param Translate $outputlangs Language
 * @return string
 */
function vereineMeetingDay($day, $outputlangs)
{
	return dol_print_date(dol_mktime(12, 0, 0, (int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4)), 'day', 'tzserver', $outputlangs);
}
