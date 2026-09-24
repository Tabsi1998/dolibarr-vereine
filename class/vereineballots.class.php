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
 * \file    class/vereineballots.class.php
 * \ingroup vereine
 * \brief   Votes of the members in a general assembly (#160, #161): prepared, released and opened in Dolibarr, cast through applications or on paper.
 *
 * Releasing, opening, closing and cancelling are acts of whoever chairs the assembly, in Dolibarr; an
 * application only casts the vote of a member. A voting right is used by one statement that only takes
 * an unused right, so two applications, or an application and a paper ballot, never count twice.
 */

require_once __DIR__.'/vereineballotrules.class.php';
require_once __DIR__.'/vereinemeetings.class.php';
require_once __DIR__.'/vereinestatutes.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Ballots of general assemblies.
 */
class VereineBallots
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
	 * @var string[] Language keys for the person
	 */
	public $errors = array();

	/**
	 * @var string Why the last vote was refused: not_found, not_open, closed, channel, used, not_present, option, external_id
	 */
	public $reason = '';

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
	 * The ballots of a meeting, oldest first.
	 *
	 * @param int $meetingId Meeting
	 * @return array<int,array<string,mixed>>
	 */
	public function forMeeting($meetingId)
	{
		global $conf;

		$list = array();
		$resql = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_ballot WHERE entity = ".((int) $conf->entity)." AND fk_meeting = ".((int) $meetingId)." ORDER BY rowid");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$ballot = $this->fetch((int) $obj->rowid);
			if ($ballot !== null) {
				$list[] = $ballot;
			}
		}
		return $list;
	}

	/**
	 * One ballot with its options and the day of its meeting.
	 *
	 * @param int $id Ballot
	 * @return array<string,mixed>|null
	 */
	public function fetch($id)
	{
		global $conf;

		$sql = "SELECT b.rowid, b.fk_meeting, b.item, b.kind, b.question, b.secret, b.channels, b.status, b.closes, b.rules, b.fk_function, b.fk_vote,";
		$sql .= " m.meeting_day, m.kind as meeting_kind, m.status as meeting_status, m.title as meeting_title FROM ".MAIN_DB_PREFIX."vereine_ballot as b";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_meeting as m ON m.rowid = b.fk_meeting WHERE b.rowid = ".((int) $id)." AND b.entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		$rules = json_decode((string) $obj->rules, true);
		$ballot = array('id' => (int) $obj->rowid, 'meeting_id' => (int) $obj->fk_meeting, 'item' => (int) $obj->item, 'kind' => (string) $obj->kind,
			'question' => (string) $obj->question, 'secret' => (int) $obj->secret === 1, 'channels' => array_values(array_filter(explode(',', (string) $obj->channels))),
			'status' => (string) $obj->status, 'closes' => (string) $obj->closes, 'rules' => is_array($rules) ? $rules : array(), 'function_id' => (int) $obj->fk_function,
			'vote_id' => (int) $obj->fk_vote, 'day' => substr((string) $obj->meeting_day, 0, 10), 'meeting_kind' => (string) $obj->meeting_kind,
			'meeting_status' => (string) $obj->meeting_status, 'meeting_title' => (string) $obj->meeting_title, 'options' => array());
		$resql = $this->db->query("SELECT code, label, fk_adherent, consent FROM ".MAIN_DB_PREFIX."vereine_ballot_option WHERE fk_ballot = ".((int) $obj->rowid)." ORDER BY position, rowid");
		while ($resql && ($row = $this->db->fetch_object($resql))) {
			$ballot['options'][] = array('code' => (string) $row->code, 'label' => (string) $row->label, 'member_id' => (int) $row->fk_adherent, 'consent' => (int) $row->consent === 1);
		}
		return $ballot;
	}

	/**
	 * Prepare a ballot for an agenda item of a general assembly.
	 *
	 * @param int                 $meetingId Meeting
	 * @param array<string,mixed> $entered   Entered ballot
	 * @param User                $user      Who prepares it
	 * @return int Id, 0 when refused (see errors), -1 on error
	 */
	public function create($meetingId, array $entered, $user)
	{
		global $conf;

		require_once __DIR__.'/vereinefunctions.class.php';

		$this->errors = array();
		$meeting = (new VereineMeetings($this->db))->fetch((int) $meetingId);
		if ($meeting === null) {
			$this->errors[] = 'VereineBallotErrorMeeting';
			return 0;
		}
		if ($meeting['kind'] === VereineMeetingRules::KIND_BOARD) {
			$this->errors[] = 'VereineBallotErrorBoard';
			return 0;
		}
		$candidates = array();
		$resql = $this->db->query("SELECT rowid, firstname, lastname FROM ".MAIN_DB_PREFIX."adherent WHERE entity IN (".getEntity('adherent').") AND statut = 1 ORDER BY lastname, firstname");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$candidates[(int) $obj->rowid] = trim($obj->firstname.' '.$obj->lastname);
		}
		$functions = array();
		foreach ((new VereineFunctions($this->db))->fetchAll(true) as $function) {
			$functions[(int) $function['id']] = (string) $function['label'];
		}
		$checked = VereineBallotRules::entered($entered, $candidates, count($meeting['agenda']), $functions);
		if ($checked['errors']) {
			$this->errors = $checked['errors'];
			return 0;
		}
		$ballot = $checked['ballot'];
		$this->db->begin();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_ballot (entity, fk_meeting, item, kind, question, secret, channels, status, closes, fk_function, datec, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $meetingId).", ".((int) $ballot['item']).", '".$this->db->escape($ballot['kind'])."',";
		$sql .= " '".$this->db->escape($ballot['question'])."', 0, '".$this->db->escape(implode(',', $ballot['channels']))."', '".VereineBallotRules::STATUS_DRAFT."',";
		$sql .= " '".$this->db->escape($ballot['closes'])."', ".((int) $ballot['function_id']).", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		$ok = (bool) $this->db->query($sql);
		$id = $ok ? (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_ballot') : 0;
		foreach ($checked['options'] as $position => $option) {
			$ok = $ok && $this->db->query("INSERT INTO ".MAIN_DB_PREFIX."vereine_ballot_option (fk_ballot, code, label, fk_adherent, consent, position) VALUES (".$id.", '".$this->db->escape($option['code'])."', '".$this->db->escape($option['label'])."', ".((int) $option['member_id']).", ".($option['consent'] ? 1 : 0).", ".((int) $position).")");
		}
		if (!$ok) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::BALLOT, 0, 0, $meeting['title'].': '.$ballot['question'].' (prepared)');
		return $id;
	}

	/**
	 * Release a ballot: its rules are frozen with the version of the statutes in force, and applications see it.
	 *
	 * @param int  $id   Ballot
	 * @param User $user Who chairs
	 * @return int 1 when released, 0 when refused (see errors), -1 on error
	 */
	public function release($id, $user)
	{
		$this->errors = array();
		$ballot = $this->fetch($id);
		if ($ballot === null) {
			$this->errors[] = 'VereineBallotErrorMeeting';
			return 0;
		}
		$this->errors = VereineBallotRules::releaseProblems($ballot, $ballot['options'], array('kind' => $ballot['meeting_kind'], 'status' => $ballot['meeting_status']));
		if ($this->errors) {
			return 0;
		}
		$statutes = new VereineStatutes($this->db);
		$rules = $statutes->rules();
		$frozen = array('majority' => VereineVoteRules::majority($ballot['kind'], $rules), 'proxy' => !empty($rules['proxy']), 'meeting_kind' => $ballot['meeting_kind'],
			'day' => $ballot['day'], 'timezone' => (string) getServerTimeZoneString(), 'channels' => $ballot['channels'], 'statute_version' => $this->statuteVersion($ballot['day']),
			'attendance' => 'present');
		return $this->move($ballot, VereineBallotRules::STATUS_RELEASED, $user, ", rules = '".$this->db->escape((string) json_encode($frozen))."', released_at = '".$this->db->idate(dol_now())."'");
	}

	/**
	 * Open a released ballot in the assembly: the voting rights are frozen from the invitations, the members and the proxies of the attendance.
	 *
	 * @param int    $id    Ballot
	 * @param string $today Today
	 * @param User   $user  Who chairs
	 * @return int 1 when open, 0 when refused (see errors), -1 on error
	 */
	public function open($id, $today, $user)
	{
		global $conf;

		$this->errors = array();
		$ballot = $this->fetch($id);
		if ($ballot === null || !VereineBallotRules::canMove($ballot['status'], VereineBallotRules::STATUS_OPEN)) {
			$this->errors[] = 'VereineBallotErrorStatus';
			return 0;
		}
		if ((string) $today !== $ballot['day']) {
			$this->errors[] = 'VereineBallotErrorDay';
			return 0;
		}
		$meetings = new VereineMeetings($this->db);
		$attendance = $meetings->attendance($ballot['meeting_id']);
		$statuteRules = (new VereineStatutes($this->db))->rules();
		$statuteRules['proxy'] = !empty($ballot['rules']['proxy']);
		$holders = VereineBallotRules::proxies($ballot['meeting_kind'], $attendance['rows'], $attendance['voting'], $statuteRules);
		$members = array();
		$resql = $this->db->query("SELECT rowid, statut FROM ".MAIN_DB_PREFIX."adherent WHERE entity IN (".getEntity('adherent').")");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$members[(int) $obj->rowid] = array('status' => (int) $obj->statut);
		}
		$exits = array();
		$resql = $this->db->query("SELECT fk_adherent, last_day FROM ".MAIN_DB_PREFIX."vereine_member_exit WHERE entity = ".((int) $conf->entity)." AND status <> 'cancelled'");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$exits[(int) $obj->fk_adherent] = substr((string) $obj->last_day, 0, 10);
		}
		$this->db->begin();
		$ok = true;
		foreach ($attendance['voting'] as $memberId => $voting) {
			$right = VereineBallotRules::right(array('member_id' => (int) $memberId, 'voting' => $voting), isset($members[$memberId]) ? $members[$memberId] : array('status' => 0),
				isset($exits[$memberId]) ? $exits[$memberId] : '', isset($holders[$memberId]) ? $holders[$memberId] : 0, $ballot['day']);
			$ok = $ok && $this->db->query("INSERT INTO ".MAIN_DB_PREFIX."vereine_ballot_right (entity, fk_ballot, fk_adherent, fk_holder, reason, eligible) VALUES ("
				.((int) $conf->entity).", ".((int) $id).", ".((int) $right['member_id']).", ".((int) $right['holder']).", '".$this->db->escape($right['reason'])."', ".($right['eligible'] ? 1 : 0).")");
		}
		if (!$ok) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$result = $this->move($ballot, VereineBallotRules::STATUS_OPEN, $user, ", opened_at = '".$this->db->idate(dol_now())."'");
		if ($result <= 0) {
			$this->db->rollback();
			return $result;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Close an open ballot, or cancel one that is not counted yet.
	 *
	 * @param int    $id     Ballot
	 * @param string $status closed or cancelled
	 * @param User   $user   Who chairs
	 * @return int 1 when done, 0 when refused (see errors), -1 on error
	 */
	public function finish($id, $status, $user)
	{
		$this->errors = array();
		$ballot = $this->fetch($id);
		if ($ballot === null || !in_array($status, array(VereineBallotRules::STATUS_CLOSED, VereineBallotRules::STATUS_CANCELLED), true)) {
			$this->errors[] = 'VereineBallotErrorStatus';
			return 0;
		}
		return $this->move($ballot, $status, $user, $status === VereineBallotRules::STATUS_CLOSED ? ", closed_at = '".$this->db->idate(dol_now())."'" : '');
	}

	/**
	 * The voting rights of an opened ballot with names and whether they were used.
	 *
	 * @param int $id Ballot
	 * @return array<int,array{id:int,member_id:int,name:string,holder:int,holder_name:string,reason:string,eligible:bool,used:bool,channel:string,option:string,client:string}>
	 */
	public function rights($id)
	{
		global $conf;

		$p = MAIN_DB_PREFIX;
		$sql = "SELECT r.rowid, r.fk_adherent, r.fk_holder, r.reason, r.eligible, r.used_at, r.channel, v.option_code, v.client, a.firstname, a.lastname, h.firstname as hfirst, h.lastname as hlast";
		$sql .= " FROM ".$p."vereine_ballot_right as r LEFT JOIN ".$p."vereine_ballot_vote as v ON v.fk_right = r.rowid LEFT JOIN ".$p."adherent as a ON a.rowid = r.fk_adherent";
		$sql .= " LEFT JOIN ".$p."adherent as h ON h.rowid = r.fk_holder WHERE r.fk_ballot = ".((int) $id)." AND r.entity = ".((int) $conf->entity)." ORDER BY a.lastname, a.firstname, r.rowid";
		$list = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$list[] = array('id' => (int) $obj->rowid, 'member_id' => (int) $obj->fk_adherent, 'name' => trim($obj->firstname.' '.$obj->lastname), 'holder' => (int) $obj->fk_holder,
				'holder_name' => trim($obj->hfirst.' '.$obj->hlast), 'reason' => (string) $obj->reason, 'eligible' => (int) $obj->eligible === 1, 'used' => $obj->used_at !== null,
				'channel' => (string) $obj->channel, 'option' => (string) $obj->option_code, 'client' => (string) $obj->client);
		}
		return $list;
	}

	/**
	 * The votes per option of a ballot; abstentions are no valid votes cast.
	 *
	 * @param array<string,mixed> $ballot Ballot of fetch()
	 * @return array{counts:array<string,int>,valid:int,abstain:int}
	 */
	public function tally(array $ballot)
	{
		$votes = array();
		$resql = $this->db->query("SELECT option_code FROM ".MAIN_DB_PREFIX."vereine_ballot_vote WHERE fk_ballot = ".((int) $ballot['id']));
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$votes[] = (string) $obj->option_code;
		}
		return VereineBallotRules::tally(array_column($ballot['options'], 'code'), $votes);
	}

	/**
	 * Cast a vote with one voting right. The same request again, by its id or with the same option from the
	 * same application, changes nothing; a right used before, by any way, is refused.
	 *
	 * @param int    $id         Ballot
	 * @param int    $rightId    The voting right
	 * @param string $option     Code of the option
	 * @param int    $actor      Member who votes, 0 when the board enters a paper ballot
	 * @param string $channel    app or paper
	 * @param string $client     The application, '' on paper
	 * @param string $externalId The application's id of the request, '' for none
	 * @param User   $user       Who acts
	 * @return int 1 when counted now, 2 when it was counted before, 0 when refused (see reason), -1 on error
	 */
	public function cast($id, $rightId, $option, $actor, $channel, $client, $externalId, $user)
	{
		global $conf;

		$this->reason = '';
		$ballot = $this->fetch($id);
		if ($ballot === null) {
			$this->reason = 'not_found';
			return 0;
		}
		$externalId = mb_substr(trim((string) $externalId), 0, 64, 'UTF-8');
		$client = mb_substr((string) $client, 0, 64, 'UTF-8');
		// The same request again: the answer it had, whatever happened since.
		$known = $this->knownVote((int) $id, (int) $rightId, $client, $externalId);
		if ($known !== null) {
			if ($known['right_id'] !== (int) $rightId || $known['option'] !== (string) $option) {
				$this->reason = 'external_id';
				return 0;
			}
			return 2;
		}
		$right = null;
		foreach ($this->rights($id) as $candidate) {
			if ($candidate['id'] === (int) $rightId) {
				$right = $candidate;
			}
		}
		if (!in_array((string) $option, array_column($ballot['options'], 'code'), true)) {
			$this->reason = $right === null ? 'not_found' : 'option';
			return 0;
		}
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$now = dol_print_date(dol_now(), '%H:%M', 'tzserver');
		$present = false;
		if ($right !== null && $channel === VereineBallotRules::CHANNEL_APP) {
			$attendance = (new VereineMeetings($this->db))->attendance($ballot['meeting_id']);
			$present = isset($attendance['rows'][(int) $actor]) && VereineAttendanceRules::presentAt($attendance['rows'][(int) $actor], $now);
		}
		$this->reason = VereineBallotRules::castProblem($ballot, $right, (int) $actor, (string) $channel, $today, $now, $present);
		if ($this->reason === 'used' && $right['channel'] === $channel && $right['option'] === (string) $option && $right['client'] === $client && $externalId === '') {
			// Sent twice without an id of its own: the same vote from the same way.
			$this->reason = '';
			return 2;
		}
		if ($this->reason !== '') {
			return 0;
		}
		$this->db->begin();
		// Only an unused right is taken; whoever comes second finds none.
		$resql = $this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_ballot_right SET used_at = '".$this->db->idate(dol_now())."', channel = '".$this->db->escape($channel)."' WHERE rowid = ".((int) $rightId)." AND fk_ballot = ".((int) $id)." AND eligible = 1 AND used_at IS NULL");
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ((int) $this->db->affected_rows($resql) !== 1) {
			$this->db->rollback();
			$this->reason = 'used';
			return 0;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_ballot_vote (entity, fk_ballot, fk_right, option_code, fk_cast_by, channel, client, external_id, cast_at, fk_user)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $id).", ".((int) $rightId).", '".$this->db->escape((string) $option)."', ".((int) $actor).", '".$this->db->escape($channel)."',";
		$sql .= " '".$this->db->escape($client)."', ".($externalId !== '' ? "'".$this->db->escape($externalId)."'" : "NULL").", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::BALLOT_VOTE, (int) $right['member_id'], 0, $ballot['question'].': '.$channel.($client !== '' ? ' '.$client : ''));
		return 1;
	}

	/**
	 * The ballots a member sees in an application: released and later ones of assemblies the member was invited to, with the rights the member may use.
	 *
	 * @param int $memberId Member
	 * @return array<int,array<string,mixed>>
	 */
	public function forMember($memberId)
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

		$p = MAIN_DB_PREFIX;
		$sql = "SELECT b.rowid FROM ".$p."vereine_ballot as b INNER JOIN ".$p."vereine_meeting_invitation as i ON i.fk_meeting = b.fk_meeting AND i.fk_adherent = ".((int) $memberId);
		$sql .= " WHERE b.entity = ".((int) $conf->entity)." AND b.status <> '".VereineBallotRules::STATUS_DRAFT."' GROUP BY b.rowid ORDER BY b.rowid DESC";
		$list = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$ballot = $this->fetch((int) $obj->rowid);
			if ($ballot === null) {
				continue;
			}
			$options = array();
			foreach ($ballot['options'] as $option) {
				$options[] = array('code' => $option['code'], 'label' => $option['label'] !== '' ? $option['label'] : $langs->transnoentitiesnoconv('VereineBallotOption_'.$option['code']));
			}
			$rights = array();
			foreach ($this->rights($ballot['id']) as $right) {
				if ($right['member_id'] === (int) $memberId && $right['holder'] !== (int) $memberId) {
					// Represented or without a right: the member sees why, and cannot vote with it.
					$rights[] = array('right_id' => 0, 'for' => 'self', 'name' => '', 'state' => 'none', 'reason' => $right['eligible'] ? 'represented' : $right['reason'], 'option' => '');
				} elseif ($right['holder'] === (int) $memberId) {
					$rights[] = array('right_id' => $right['id'], 'for' => $right['member_id'] === (int) $memberId ? 'self' : 'proxy',
						'name' => $right['member_id'] === (int) $memberId ? '' : $right['name'], 'state' => $right['used'] ? 'used' : 'open', 'reason' => $right['reason'],
						'option' => $right['used'] ? $right['option'] : '');
				}
			}
			$list[] = array('id' => $ballot['id'], 'meeting_id' => $ballot['meeting_id'], 'meeting' => $ballot['meeting_title'], 'day' => $ballot['day'], 'item' => $ballot['item'],
				'kind' => $ballot['kind'], 'question' => $ballot['question'], 'status' => $ballot['status'], 'closes' => $ballot['closes'],
				'timezone' => (string) getServerTimeZoneString(), 'options' => $options, 'rights' => $rights);
		}
		return $list;
	}

	/**
	 * A vote an application sent before under the same id.
	 *
	 * @param int    $id         Ballot
	 * @param int    $rightId    The voting right asked for now
	 * @param string $client     The application
	 * @param string $externalId Its id of the request
	 * @return array{right_id:int,option:string}|null
	 */
	private function knownVote($id, $rightId, $client, $externalId)
	{
		if ($externalId === '' || $client === '') {
			return null;
		}
		$resql = $this->db->query("SELECT fk_right, option_code FROM ".MAIN_DB_PREFIX."vereine_ballot_vote WHERE fk_ballot = ".((int) $id)." AND client = '".$this->db->escape($client)."' AND external_id = '".$this->db->escape($externalId)."'");
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? array('right_id' => (int) $obj->fk_right, 'option' => (string) $obj->option_code) : null;
	}

	/**
	 * The version of the statutes in force on a day, 0 when none is known.
	 *
	 * @param string $day Day
	 * @return int
	 */
	private function statuteVersion($day)
	{
		global $conf;

		$resql = $this->db->query("SELECT version FROM ".MAIN_DB_PREFIX."vereine_statute WHERE entity = ".((int) $conf->entity)." AND (valid_from IS NULL OR valid_from <= '"
			.$this->db->escape($day)."') ORDER BY valid_from DESC, version DESC LIMIT 1");
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? (int) $obj->version : 0;
	}

	/**
	 * Move a ballot to another status when the rules allow it.
	 *
	 * @param array<string,mixed> $ballot Ballot
	 * @param string              $status New status
	 * @param User                $user   Who
	 * @param string              $more   More of the SET clause
	 * @return int 1 when moved, 0 when refused (see errors), -1 on error
	 */
	private function move(array $ballot, $status, $user, $more)
	{
		if (!VereineBallotRules::canMove($ballot['status'], $status)) {
			$this->errors[] = 'VereineBallotErrorStatus';
			return 0;
		}
		$resql = $this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_ballot SET status = '".$this->db->escape($status)."'".$more.", fk_user_modif = ".((int) $user->id)
			." WHERE rowid = ".((int) $ballot['id'])." AND status = '".$this->db->escape($ballot['status'])."'");
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ((int) $this->db->affected_rows($resql) !== 1) {
			$this->errors[] = 'VereineBallotErrorStatus';
			return 0;
		}
		VereineLog::add($this->db, $user, VereineLog::BALLOT, 0, 0, $ballot['meeting_title'].': '.$ballot['question'].' ('.$status.')');
		return 1;
	}
}
