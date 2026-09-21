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
 * \file    class/vereineresolutions.class.php
 * \ingroup vereine
 * \brief   The register of resolutions: one place for everything the association resolved, with what follows from it.
 *
 * A vote in a meeting writes its entry here by itself. The entry keeps the counted facts as they were;
 * the wording, the category, the validity and the tasks that follow are added later by hand. A task is
 * kept as an agenda event of Dolibarr, so it shows up where the officers look anyway.
 */

require_once __DIR__.'/vereineresolutionrules.class.php';
require_once __DIR__.'/vereinemeetingrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * The register of resolutions.
 */
class VereineResolutions
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
	 * Write the register entry of a vote. Called right after the vote was stored.
	 *
	 * @param array<string,mixed> $meeting Meeting the vote belongs to
	 * @param int                 $voteId  Vote
	 * @param array<string,mixed> $vote    Normalized vote with majority and result
	 * @param User                $user    Who stores
	 * @return int Id of the entry, 0 when the vote already has one, -1 on error
	 */
	public function addFromVote(array $meeting, $voteId, array $vote, $user)
	{
		if ($this->hasVote($voteId)) {
			return 0;
		}
		$entry = array(
			'source' => VereineResolutionRules::SOURCE_MEETING,
			'meeting_id' => (int) $meeting['id'],
			'vote_id' => (int) $voteId,
			'organ' => (string) $meeting['kind'],
			'kind' => (string) $vote['kind'],
			'day' => (string) $meeting['day'],
			'item' => (int) $vote['item'],
			'title' => (string) $vote['title'],
			'category' => VereineResolutionRules::category((string) $vote['kind']),
			'passed' => !empty($vote['passed']),
			'yes' => (int) $vote['yes'],
			'no' => (int) $vote['no'],
			'abstain' => (int) $vote['abstain'],
			'majority' => (string) $vote['majority'],
			'member_id' => (int) (isset($vote['candidate_id']) ? $vote['candidate_id'] : 0),
			'applied' => (string) (isset($vote['applied']) ? $vote['applied'] : ''),
		);
		$id = $this->insert($entry, $user);
		if ($id > 0 && $this->attachAgreements((int) $meeting['id'], (int) $vote['item'], $id) < 0) {
			return -1;
		}
		return $id;
	}

	/**
	 * Write the register entry of a circular resolution of the board.
	 *
	 * @param array<string,mixed> $circular Circular resolution with the day it was counted
	 * @param array<string,mixed> $result   Result of VereineCircularRules::result()
	 * @param User                $user     Who counts
	 * @return int Id of the entry, -1 on error
	 */
	public function addFromCircular(array $circular, array $result, $user)
	{
		$counts = $result['counts'];
		$entry = array(
			'source' => VereineResolutionRules::SOURCE_CIRCULAR,
			'meeting_id' => 0,
			'vote_id' => 0,
			'organ' => VereineMeetingRules::KIND_BOARD,
			'kind' => VereineVoteRules::KIND_RESOLUTION,
			'day' => (string) $circular['decided_on'],
			'item' => 0,
			'title' => (string) $circular['title'],
			'category' => VereineResolutionRules::CATEGORY_OTHER,
			'passed' => !empty($result['passed']),
			'yes' => (int) $counts['yes'],
			'no' => (int) $counts['no'],
			'abstain' => (int) $counts['abstain'],
			'majority' => (string) $result['majority'],
			'member_id' => 0,
			'applied' => 'circular:'.((int) $circular['id']),
		);
		$id = $this->insert($entry, $user);
		if ($id > 0 && (string) $circular['wording'] !== '') {
			$this->save($id, array('wording' => (string) $circular['wording'], 'category' => VereineResolutionRules::CATEGORY_OTHER), $user);
		}
		return $id;
	}

	/**
	 * Write a register entry.
	 *
	 * @param array<string,mixed> $entry Facts of the resolution
	 * @param User                $user  Who stores
	 * @return int Id of the entry, -1 on error
	 */
	private function insert(array $entry, $user)
	{
		global $conf;

		$ref = VereineResolutionRules::ref($entry['day'], $this->nextNumber((string) $entry['day']));
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_resolution (entity, ref, source, fk_meeting, fk_vote, organ, kind, resolution_day, item, title, category,";
		$sql .= " passed, yes, no, abstain, majority, fk_adherent, applied, datec, fk_user_modif)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($ref)."', '".$this->db->escape($entry['source'])."', ".((int) $entry['meeting_id']).",";
		$sql .= " ".((int) $entry['vote_id']).", '".$this->db->escape($entry['organ'])."', '".$this->db->escape($entry['kind'])."', '".$this->db->escape($entry['day'])."',";
		$sql .= " ".((int) $entry['item']).", '".$this->db->escape($entry['title'])."', '".$this->db->escape($entry['category'])."', ".(!empty($entry['passed']) ? 1 : 0).",";
		$sql .= " ".((int) $entry['yes']).", ".((int) $entry['no']).", ".((int) $entry['abstain']).", '".$this->db->escape($entry['majority'])."',";
		$sql .= " ".((int) $entry['member_id']).", '".$this->db->escape($entry['applied'])."', '".$this->db->idate(dol_now())."', ".$this->userId($user).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_resolution');
		VereineLog::add($this->db, $user, VereineLog::RESOLUTION_ADDED, (int) $entry['member_id'], 0, $ref.': '.$entry['title']);
		return $id;
	}

	/**
	 * The next running number in the year of a day.
	 *
	 * @param string $day Day as YYYY-MM-DD
	 * @return int
	 */
	private function nextNumber($day)
	{
		global $conf;

		$year = preg_match('/^\d{4}-/', (string) $day) ? substr((string) $day, 0, 4) : date('Y');
		$sql = "SELECT COUNT(*) as taken FROM ".MAIN_DB_PREFIX."vereine_resolution WHERE entity = ".((int) $conf->entity);
		$sql .= " AND resolution_day >= '".$this->db->escape($year)."-01-01' AND resolution_day <= '".$this->db->escape($year)."-12-31'";
		$resql = $this->db->query($sql);
		$taken = 0;
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$taken = (int) $obj->taken;
		}
		return $taken + 1;
	}

	/**
	 * Register entries with their open tasks, newest first.
	 *
	 * @param array<string,mixed>|null $filters Normalized filters, null for all
	 * @return array<int,array<string,mixed>>
	 */
	public function fetchAll($filters = null)
	{
		global $conf;

		$sql = "SELECT rowid, ref, source, fk_meeting, fk_vote, organ, kind, resolution_day, item, title, wording, category, passed, yes, no, abstain,";
		$sql .= " majority, valid_from, valid_to, fk_adherent, fk_facture, money, applied, note FROM ".MAIN_DB_PREFIX."vereine_resolution";
		$sql .= " WHERE entity = ".((int) $conf->entity)." ORDER BY resolution_day DESC, rowid DESC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[(int) $obj->rowid] = array(
				'id' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'source' => (string) $obj->source,
				'meeting_id' => (int) $obj->fk_meeting,
				'vote_id' => (int) $obj->fk_vote,
				'organ' => (string) $obj->organ,
				'kind' => (string) $obj->kind,
				'day' => (string) $obj->resolution_day,
				'item' => (int) $obj->item,
				'title' => (string) $obj->title,
				'wording' => (string) $obj->wording,
				'category' => (string) $obj->category,
				'passed' => (int) $obj->passed === 1,
				'yes' => (int) $obj->yes,
				'no' => (int) $obj->no,
				'abstain' => (int) $obj->abstain,
				'majority' => (string) $obj->majority,
				'valid_from' => (string) $obj->valid_from,
				'valid_to' => (string) $obj->valid_to,
				'member_id' => (int) $obj->fk_adherent,
				'invoice_id' => (int) $obj->fk_facture,
				'money' => (int) $obj->money === 1,
				'applied' => (string) $obj->applied,
				'note' => (string) $obj->note,
				'tasks' => 0,
				'tasks_open' => 0,
			);
		}
		$this->db->free($resql);
		foreach ($this->tasks(0) as $task) {
			if (!isset($rows[$task['resolution_id']])) {
				continue;
			}
			$rows[$task['resolution_id']]['tasks']++;
			if ($task['done'] === '') {
				$rows[$task['resolution_id']]['tasks_open']++;
			}
		}
		$all = array_values($rows);
		if (!is_array($filters)) {
			return $all;
		}
		return array_values(array_filter($all, function ($row) use ($filters) {
			return VereineResolutionRules::matches($row, $filters);
		}));
	}

	/**
	 * One register entry.
	 *
	 * @param int $id Entry
	 * @return array<string,mixed>|null
	 */
	public function fetch($id)
	{
		foreach ($this->fetchAll() as $row) {
			if ($row['id'] === (int) $id) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Whether a vote already has its register entry.
	 *
	 * @param int $voteId Vote
	 * @return bool
	 */
	public function hasVote($voteId)
	{
		global $conf;

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_resolution WHERE entity = ".((int) $conf->entity)." AND fk_vote = ".((int) $voteId);
		$resql = $this->db->query($sql);
		$found = $resql && $this->db->fetch_object($resql) !== null;
		if ($resql) {
			$this->db->free($resql);
		}
		return $found;
	}

	/**
	 * Who acted, 0 for the system.
	 *
	 * @param User|null $user Who acted
	 * @return int
	 */
	private function userId($user)
	{
		return is_object($user) && !empty($user->id) ? (int) $user->id : 0;
	}

	/**
	 * The entries that concern a member: an election, an admission, an honour, an exclusion.
	 *
	 * @param int $memberId Member
	 * @return array<int,array<string,mixed>>
	 */
	public function forMember($memberId)
	{
		$rows = array();
		foreach ($this->fetchAll() as $row) {
			if ($row['member_id'] === (int) $memberId) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Store what may be changed of an entry.
	 *
	 * @param int                 $id      Entry
	 * @param array<string,mixed> $entered Entered entry
	 * @param User                $user    Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function save($id, array $entered, $user)
	{
		global $conf;

		$this->errors = array();
		$row = $this->fetch($id);
		if ($row === null) {
			$this->errors = array('VereineResolutionErrorUnknown');
			return 0;
		}
		$entry = VereineResolutionRules::normalize($entered);
		$this->errors = VereineResolutionRules::validate($entry);
		if ($this->errors) {
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_resolution SET wording = '".$this->db->escape($entry['wording'])."',";
		$sql .= " category = '".$this->db->escape($entry['category'])."',";
		$sql .= " valid_from = ".($entry['valid_from'] !== '' ? "'".$this->db->escape($entry['valid_from'])."'" : "NULL").",";
		$sql .= " valid_to = ".($entry['valid_to'] !== '' ? "'".$this->db->escape($entry['valid_to'])."'" : "NULL").",";
		$sql .= " fk_adherent = ".((int) $entry['member_id']).", fk_facture = ".((int) $entry['invoice_id']).",";
		$sql .= " money = ".(!empty($entry['money']) ? 1 : 0).",";
		$sql .= " note = '".$this->db->escape($entry['note'])."', fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::RESOLUTION_SAVED, (int) $entry['member_id'], 0, $row['ref'].': '.$entry['category']);
		return 1;
	}

	/**
	 * Tasks that follow from resolutions or were agreed at an agenda item, oldest deadline first.
	 *
	 * @param int $resolutionId Resolution, 0 for all
	 * @param int $meetingId    Meeting whose agreements and follow-ups are wanted, 0 for all
	 * @return array<int,array<string,mixed>>
	 */
	public function tasks($resolutionId = 0, $meetingId = 0)
	{
		global $conf;

		$sql = "SELECT t.rowid, t.fk_resolution, t.fk_meeting, t.item, t.label, t.fk_adherent, t.deadline, t.fk_actioncomm, t.done_at, r.ref, m.title as meeting_title";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_resolution_task as t";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."vereine_resolution as r ON r.rowid = t.fk_resolution";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."vereine_meeting as m ON m.rowid = t.fk_meeting";
		$sql .= " WHERE t.entity = ".((int) $conf->entity)." AND (t.fk_resolution > 0 OR t.fk_meeting > 0)";
		if ((int) $resolutionId > 0) {
			$sql .= " AND t.fk_resolution = ".((int) $resolutionId);
		}
		if ((int) $meetingId > 0) {
			$sql .= " AND t.fk_meeting = ".((int) $meetingId);
		}
		$sql .= " ORDER BY t.deadline IS NULL, t.deadline, t.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$tasks = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$tasks[] = array(
				'id' => (int) $obj->rowid,
				'resolution_id' => (int) $obj->fk_resolution,
				'meeting_id' => (int) $obj->fk_meeting,
				'item' => (int) $obj->item,
				'meeting_title' => (string) $obj->meeting_title,
				'ref' => (string) $obj->ref,
				'label' => (string) $obj->label,
				'member_id' => (int) $obj->fk_adherent,
				'deadline' => (string) $obj->deadline,
				'event_id' => (int) $obj->fk_actioncomm,
				'done' => (string) $obj->done_at,
			);
		}
		$this->db->free($resql);
		return $tasks;
	}

	/**
	 * The open tasks of every resolution, for the next agenda.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function openTasks()
	{
		return array_values(array_filter($this->tasks(0), function ($task) {
			return $task['done'] === '';
		}));
	}

	/**
	 * Add a task that follows from a resolution; with the agenda module it becomes a to-do of Dolibarr.
	 *
	 * @param int                 $resolutionId Resolution
	 * @param array<string,mixed> $entered      Entered task
	 * @param User                $user         Who stores
	 * @return int Id of the task, 0 when refused (see errors), -1 on error
	 */
	public function addTask($resolutionId, array $entered, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';

		$this->errors = array();
		$row = $this->fetch($resolutionId);
		if ($row === null) {
			$this->errors = array('VereineResolutionErrorUnknown');
			return 0;
		}
		$task = VereineResolutionRules::normalizeTask($entered);
		$this->errors = VereineResolutionRules::validateTask($task);
		$member = new Adherent($this->db);
		if (!$this->errors && $member->fetch($task['member_id']) <= 0) {
			$this->errors[] = 'VereineResolutionTaskErrorMember';
		}
		if ($this->errors) {
			return 0;
		}
		$eventId = $this->addEvent($row, $task, $member, $user);
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_resolution_task (entity, fk_resolution, label, fk_adherent, deadline, fk_actioncomm, datec, fk_user_modif)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $resolutionId).", '".$this->db->escape($task['label'])."', ".((int) $task['member_id']).",";
		$sql .= " ".($task['deadline'] !== '' ? "'".$this->db->escape($task['deadline'])."'" : "NULL").", ".($eventId > 0 ? $eventId : "NULL").",";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_resolution_task');
		VereineLog::add($this->db, $user, VereineLog::RESOLUTION_TASK, (int) $task['member_id'], 0, $row['ref'].': '.$task['label']);
		return $id;
	}

	/**
	 * The to-do of Dolibarr for a task, owned by the responsible member's user where there is one.
	 *
	 * @param array<string,mixed> $row    Resolution
	 * @param array<string,mixed> $task   Normalized task
	 * @param Adherent            $member Who is responsible
	 * @param User                $user   Who stores
	 * @return int Id of the event, 0 when there is none
	 */
	private function addEvent(array $row, array $task, $member, $user)
	{
		global $langs;

		if (!isModEnabled('agenda')) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

		$owner = (int) $member->user_id > 0 ? (int) $member->user_id : (int) $user->id;
		$day = $task['deadline'] !== '' ? $task['deadline'] : $row['day'];
		$event = new ActionComm($this->db);
		$event->type_code = 'AC_OTH';
		$event->label = $langs->transnoentities('VereineResolutionTaskAgendaLabel', $task['label'], $row['ref']);
		$event->note_private = $langs->transnoentities('VereineResolutionTaskAgendaNote', $row['title']);
		$event->datep = dol_mktime(0, 0, 0, (int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4));
		$event->datef = $event->datep;
		$event->fulldayevent = 1;
		$event->percentage = 0;
		$event->userownerid = $owner;
		$event->fk_element = (int) $member->id;
		$event->elementtype = 'member';
		$eventId = (int) $event->create($user);
		if ($eventId <= 0) {
			dol_syslog(__METHOD__.' agenda event: '.$event->error, LOG_WARNING);
			return 0;
		}
		return $eventId;
	}

	/**
	 * Agree at an agenda item who does what until when; every person gets a to-do in Dolibarr.
	 *
	 * An agreement needs no vote. When a vote on the same item follows, the agreement becomes a
	 * follow-up of that resolution (see attachAgreements()).
	 *
	 * @param array<string,mixed> $meeting Meeting
	 * @param int                 $item    Agenda item, from 1
	 * @param array<string,mixed> $entered Keys label, deadline and member_ids (list of members)
	 * @param User                $user    Who stores
	 * @return int Number of tasks written, 0 when refused (see errors), -1 on error
	 */
	public function addAgreement(array $meeting, $item, array $entered, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';

		$this->errors = array();
		$agenda = array_values($meeting['agenda']);
		if ((int) $item < 1 || (int) $item > count($agenda)) {
			$this->errors[] = 'VereineAgreementErrorItem';
			return 0;
		}
		$members = array();
		foreach (isset($entered['member_ids']) && is_array($entered['member_ids']) ? $entered['member_ids'] : array() as $memberId) {
			if (is_scalar($memberId) && preg_match('/^\d{1,10}$/', (string) $memberId) && !in_array((int) $memberId, $members, true)) {
				$members[] = (int) $memberId;
			}
		}
		$task = VereineResolutionRules::normalizeTask(array('label' => isset($entered['label']) ? $entered['label'] : '', 'member_id' => $members ? $members[0] : 0,
			'deadline' => isset($entered['deadline']) ? $entered['deadline'] : ''));
		if ($task['label'] === '') {
			$this->errors[] = 'VereineResolutionTaskErrorLabel';
		}
		if (!$members) {
			$this->errors[] = 'VereineAgreementErrorNobody';
		}
		if ($this->errors) {
			return 0;
		}
		// What the agenda event says: the meeting and the item the agreement comes from.
		$source = array('ref' => $meeting['title'], 'title' => ((int) $item).'. '.$agenda[(int) $item - 1], 'day' => $meeting['day']);
		$written = 0;
		foreach ($members as $memberId) {
			$member = new Adherent($this->db);
			if ($member->fetch($memberId) <= 0) {
				$this->errors[] = 'VereineResolutionTaskErrorMember';
				return $written > 0 ? $written : 0;
			}
			$eventId = $this->addEvent($source, $task, $member, $user);
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_resolution_task (entity, fk_resolution, fk_meeting, item, label, fk_adherent, deadline, fk_actioncomm, datec, fk_user_modif)";
			$sql .= " VALUES (".((int) $conf->entity).", 0, ".((int) $meeting['id']).", ".((int) $item).", '".$this->db->escape($task['label'])."', ".((int) $memberId).",";
			$sql .= " ".($task['deadline'] !== '' ? "'".$this->db->escape($task['deadline'])."'" : "NULL").", ".($eventId > 0 ? $eventId : "NULL").",";
			$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$written++;
			VereineLog::add($this->db, $user, VereineLog::RESOLUTION_TASK, $memberId, 0, $meeting['title'].' / '.$item.': '.$task['label']);
		}
		return $written;
	}

	/**
	 * Agreements of an agenda item become follow-ups of the resolution voted on that item.
	 *
	 * @param int $meetingId    Meeting
	 * @param int $item         Agenda item
	 * @param int $resolutionId Resolution of the vote
	 * @return int 1 when done, -1 on error
	 */
	public function attachAgreements($meetingId, $item, $resolutionId)
	{
		global $conf;

		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_resolution_task SET fk_resolution = ".((int) $resolutionId);
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_meeting = ".((int) $meetingId)." AND item = ".((int) $item)." AND fk_resolution = 0";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Note a task as done; its to-do in Dolibarr is done as well.
	 *
	 * @param int  $id   Task
	 * @param User $user Who notes
	 * @return int 1 when noted, 0 when refused (see errors), -1 on error
	 */
	public function taskDone($id, $user)
	{
		global $conf;

		$this->errors = array();
		$found = null;
		foreach ($this->tasks(0) as $task) {
			if ($task['id'] === (int) $id) {
				$found = $task;
			}
		}
		if ($found === null || $found['done'] !== '') {
			$this->errors = array('VereineResolutionTaskErrorUnknown');
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_resolution_task SET done_at = '".$this->db->idate(dol_now())."', fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($found['event_id'] > 0 && isModEnabled('agenda')) {
			require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
			$event = new ActionComm($this->db);
			if ($event->fetch($found['event_id']) > 0) {
				$event->percentage = 100;
				if ($event->update($user) < 0) {
					dol_syslog(__METHOD__.' agenda event: '.$event->error, LOG_WARNING);
				}
			}
		}
		VereineLog::add($this->db, $user, VereineLog::RESOLUTION_TASK_DONE, (int) $found['member_id'], 0, $found['ref'].': '.$found['label']);
		return 1;
	}

	/**
	 * Write the register entries of votes that were stored before there was a register.
	 *
	 * @param User|null $user Who repairs, null for the system
	 * @return int Number of entries written
	 */
	public function backfill($user = null)
	{
		global $conf;

		$sql = "SELECT v.rowid, v.fk_meeting, v.item, v.kind, v.title, v.yes, v.no, v.abstain, v.majority, v.passed, v.fk_candidate, v.applied,";
		$sql .= " m.kind as organ, m.meeting_day FROM ".MAIN_DB_PREFIX."vereine_meeting_vote as v";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_meeting as m ON m.rowid = v.fk_meeting";
		$sql .= " WHERE v.entity = ".((int) $conf->entity)." AND v.rowid NOT IN (SELECT fk_vote FROM ".MAIN_DB_PREFIX."vereine_resolution WHERE entity = ".((int) $conf->entity).")";
		$sql .= " ORDER BY m.meeting_day, v.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return 0;
		}
		$votes = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$votes[] = array(
				'meeting' => array('id' => (int) $obj->fk_meeting, 'kind' => (string) $obj->organ, 'day' => (string) $obj->meeting_day),
				'vote_id' => (int) $obj->rowid,
				'vote' => array('item' => (int) $obj->item, 'kind' => (string) $obj->kind, 'title' => (string) $obj->title, 'yes' => (int) $obj->yes,
					'no' => (int) $obj->no, 'abstain' => (int) $obj->abstain, 'majority' => (string) $obj->majority, 'passed' => (int) $obj->passed === 1,
					'candidate_id' => (int) $obj->fk_candidate, 'applied' => (string) $obj->applied),
			);
		}
		$this->db->free($resql);
		$written = 0;
		foreach ($votes as $vote) {
			if ($this->addFromVote($vote['meeting'], $vote['vote_id'], $vote['vote'], $user) > 0) {
				$written++;
			}
		}
		return $written;
	}
}
