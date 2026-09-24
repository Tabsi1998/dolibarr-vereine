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
require_once __DIR__.'/vereinepdf.class.php';

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

		$sql = "SELECT b.rowid, b.fk_meeting, b.item, b.kind, b.question, b.secret, b.channels, b.status, b.closes, b.rules, b.fk_function, b.fk_vote, b.opened_at, b.closed_at, b.quorum,";
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
			'meeting_status' => (string) $obj->meeting_status, 'meeting_title' => (string) $obj->meeting_title, 'options' => array(),
			'opened_at' => $obj->opened_at ? (int) $this->db->jdate($obj->opened_at) : 0, 'closed_at' => $obj->closed_at ? (int) $this->db->jdate($obj->closed_at) : 0,
			'quorum' => is_array(json_decode((string) $obj->quorum, true)) ? json_decode((string) $obj->quorum, true) : null);
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
		$frozen = array('majority' => VereineVoteRules::majority($ballot['kind'], $rules), 'proxy' => !empty($rules['proxy']), 'general_quorum' => (int) $rules['general_quorum'],
			'meeting_kind' => $ballot['meeting_kind'],
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
		// The quorum of the moment it opens is kept with it; later changes of the attendance do not change the count.
		$statuteRules['general_quorum'] = isset($ballot['rules']['general_quorum']) ? (int) $ballot['rules']['general_quorum'] : (int) $statuteRules['general_quorum'];
		$quorum = VereineAttendanceRules::quorum($ballot['meeting_kind'], $attendance['rows'], $attendance['voting'], $statuteRules, dol_print_date(dol_now(), '%H:%M', 'tzserver'));
		$result = $this->move($ballot, VereineBallotRules::STATUS_OPEN, $user, ", opened_at = '".$this->db->idate(dol_now())."', quorum = '".$this->db->escape((string) json_encode($quorum))."'");
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
				'timezone' => (string) getServerTimeZoneString(), 'options' => $options, 'rights' => $rights, 'result' => $this->confirmedResult($ballot['id']));
		}
		return $list;
	}

	/**
	 * Where the proofs of counted ballots are kept.
	 *
	 * @return string
	 */
	public static function directory()
	{
		global $conf;

		return DOL_DATA_ROOT.($conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/vereine/ballots';
	}

	/**
	 * The counts of a ballot, newest first, each with its proof.
	 *
	 * @param int $id Ballot
	 * @return array<int,array{id:int,revision:int,status:string,reason:string,filename:string,sha256:string,vote_id:int,snapshot:array<string,mixed>,created:int,confirmed_at:int}>
	 */
	public function results($id)
	{
		$list = array();
		$sql = "SELECT rowid, revision, status, reason, filename, doc_sha, fk_vote, snapshot, datec, confirmed_at FROM ".MAIN_DB_PREFIX."vereine_ballot_result";
		$resql = $this->db->query($sql." WHERE fk_ballot = ".((int) $id)." ORDER BY revision DESC");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$snapshot = json_decode((string) $obj->snapshot, true);
			$list[] = array('id' => (int) $obj->rowid, 'revision' => (int) $obj->revision, 'status' => (string) $obj->status, 'reason' => (string) $obj->reason,
				'filename' => (string) $obj->filename, 'sha256' => (string) $obj->doc_sha, 'vote_id' => (int) $obj->fk_vote, 'snapshot' => is_array($snapshot) ? $snapshot : array(),
				'created' => (int) $this->db->jdate($obj->datec), 'confirmed_at' => $obj->confirmed_at ? (int) $this->db->jdate($obj->confirmed_at) : 0);
		}
		return $list;
	}

	/**
	 * Count a closed ballot: a snapshot of question, rules, rights, votes, quorum and outcome, kept with a proof as PDF.
	 * Counting again replaces a count that is not confirmed yet, with its reason; the earlier one stays with its proof.
	 *
	 * @param int       $id          Ballot
	 * @param string    $reason      Why it is counted again, '' the first time
	 * @param User      $user        Who counts
	 * @param Translate $outputlangs Language of the proof
	 * @return int Id of the count, 0 when refused (see errors), -1 on error
	 */
	public function evaluate($id, $reason, $user, $outputlangs)
	{
		global $conf;

		$this->errors = array();
		$ballot = $this->fetch($id);
		if ($ballot === null) {
			$this->errors[] = 'VereineBallotErrorStatus';
			return 0;
		}
		$results = $this->results($id);
		$again = $ballot['status'] === VereineBallotRules::STATUS_EVALUATED;
		if (!$again && $ballot['status'] !== VereineBallotRules::STATUS_CLOSED) {
			$this->errors[] = 'VereineBallotErrorStatus';
			return 0;
		}
		$reason = mb_substr(trim((string) $reason), 0, 255, 'UTF-8');
		if ($again && ($results === array() || $results[0]['status'] !== VereineBallotRules::RESULT_PROVISIONAL)) {
			// A confirmed count is not counted again; that is a matter of a new resolution.
			$this->errors[] = 'VereineBallotErrorConfirmed';
			return 0;
		}
		if ($again && $reason === '') {
			$this->errors[] = 'VereineBallotErrorReason';
			return 0;
		}
		$snapshot = $this->snapshot($ballot);
		$revision = $results ? $results[0]['revision'] + 1 : 1;
		$this->db->begin();
		$ok = !$again || $this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_ballot_result SET status = '".VereineBallotRules::RESULT_SUPERSEDED."' WHERE fk_ballot = ".((int) $id)
			." AND status = '".VereineBallotRules::RESULT_PROVISIONAL."'");
		$ok = $ok && $this->db->query("INSERT INTO ".MAIN_DB_PREFIX."vereine_ballot_result (entity, fk_ballot, revision, snapshot, reason, status, datec, fk_user) VALUES ("
			.((int) $conf->entity).", ".((int) $id).", ".$revision.", '".$this->db->escape((string) json_encode($snapshot))."', '".$this->db->escape($reason)."', '".VereineBallotRules::RESULT_PROVISIONAL."', '".$this->db->idate(dol_now())."', ".((int) $user->id).")");
		$resultId = $ok ? (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_ballot_result') : 0;
		if ($ok && !$again && $this->move($ballot, VereineBallotRules::STATUS_EVALUATED, $user, '') <= 0) {
			$ok = false;
		}
		if (!$ok) {
			$this->error = $this->error !== '' ? $this->error : $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		// The proof is built apart: when it fails, it is built again from the same snapshot.
		if ($this->buildProof($resultId, $outputlangs) < 0) {
			dol_syslog('VereineBallots::evaluate proof of count '.$resultId.': '.$this->error, LOG_WARNING);
		}
		return $resultId;
	}

	/**
	 * Build the proof of a count from its snapshot and keep it with a code in the association's files; nothing when it is there.
	 *
	 * @param int       $resultId    Count
	 * @param Translate $outputlangs Language of the proof
	 * @return int 1 when the proof is there, -1 on error
	 */
	public function buildProof($resultId, $outputlangs)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		require_once __DIR__.'/vereinearchive.class.php';

		$resql = $this->db->query("SELECT rowid, fk_ballot, revision, snapshot, reason, filename, doc_sha, datec FROM ".MAIN_DB_PREFIX."vereine_ballot_result WHERE rowid = ".((int) $resultId));
		$row = $resql ? $this->db->fetch_object($resql) : null;
		if (!$row) {
			$this->error = 'unknown count '.((int) $resultId);
			return -1;
		}
		$dir = self::directory();
		$file = $dir.'/nachweis-abstimmung-'.((int) $row->fk_ballot).'-'.((int) $row->revision).'.pdf';
		if ((string) $row->filename !== '' && is_file($dir.'/'.$row->filename) && hash_file('sha256', $dir.'/'.$row->filename) === (string) $row->doc_sha) {
			return 1;
		}
		if (dol_mkdir($dir) < 0) {
			$this->error = 'cannot create '.$dir;
			return -1;
		}
		$snapshot = json_decode((string) $row->snapshot, true);
		$archive = new VereineArchive($this->db);
		$code = $archive->codeFor('ballot', (int) $row->rowid);
		if (!is_array($snapshot) || $this->writeProof($snapshot, (int) $row->revision, (string) $row->reason, (int) $this->db->jdate($row->datec), $file, $code, $outputlangs) < 0) {
			return -1;
		}
		$sha = (string) hash_file('sha256', $file);
		if (!$this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_ballot_result SET filename = '".$this->db->escape(basename($file))."', doc_sha = '".$sha."' WHERE rowid = ".((int) $row->rowid))) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$title = $outputlangs->transnoentities('VereineBallotProofTitle').': '.$snapshot['question'].' ('.$outputlangs->transnoentities('VereineBallotProofRevision', (int) $row->revision).')';
		if ($archive->register($code, 'ballot', (int) $row->rowid, $title, $file) < 0) {
			$this->error = $archive->error;
			return -1;
		}
		return 1;
	}

	/**
	 * Confirm the newest count in Dolibarr: only now what follows from it happens, once, as for a vote entered by hand (#163).
	 *
	 * @param int       $id          Ballot
	 * @param User      $user        Who chairs
	 * @param Translate $outputlangs Language of letters
	 * @return int 1 when confirmed now, 2 when it was confirmed before, 0 when refused (see errors), -1 on error
	 */
	public function confirm($id, $user, $outputlangs)
	{
		$this->errors = array();
		$ballot = $this->fetch($id);
		$results = $this->results($id);
		if ($ballot === null || $results === array()) {
			$this->errors[] = 'VereineBallotErrorStatus';
			return 0;
		}
		$latest = $results[0];
		if ($latest['status'] === VereineBallotRules::RESULT_CONFIRMED) {
			return 2;
		}
		if ($latest['filename'] === '') {
			$this->errors[] = 'VereineBallotErrorProof';
			return 0;
		}
		$meetings = new VereineMeetings($this->db);
		$meeting = $meetings->fetch($ballot['meeting_id']);
		$outcome = $latest['snapshot']['outcome'];
		$this->db->begin();
		// Only a count that is still provisional is taken; whoever comes second finds it confirmed.
		$resql = $this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_ballot_result SET status = '".VereineBallotRules::RESULT_CONFIRMED."', confirmed_at = '".$this->db->idate(dol_now())."', fk_user_confirmed = ".((int) $user->id)." WHERE rowid = ".$latest['id']." AND status = '".VereineBallotRules::RESULT_PROVISIONAL."'");
		if (!$resql || (int) $this->db->affected_rows($resql) !== 1) {
			$this->db->rollback();
			return $resql ? 2 : -1;
		}
		$vote = array('kind' => $ballot['kind'], 'item' => $ballot['item'], 'title' => $ballot['question'], 'secret' => false, 'yes' => (int) $outcome['yes'],
			'no' => (int) $outcome['no'], 'abstain' => (int) $latest['snapshot']['abstain'], 'tie' => '', 'time' => (string) $latest['snapshot']['opened_time'],
			'function_id' => $ballot['function_id'], 'candidate_id' => (int) $outcome['candidate_id']);
		$voteId = $meeting !== null ? $meetings->applyVote($meeting, $vote, (string) $outcome['majority'], array('passed' => (bool) $outcome['passed'], 'tie' => false,
			'decided_by_chair' => false), $user, $outputlangs) : 0;
		if ($voteId <= 0) {
			$this->errors = $meetings->errors ?: array('VereineBallotErrorStatus');
			$this->error = $meetings->error;
			$this->db->rollback();
			return $voteId < 0 ? -1 : 0;
		}
		if (!$this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_ballot_result SET fk_vote = ".$voteId." WHERE rowid = ".$latest['id'])
			|| !$this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_ballot SET fk_vote = ".$voteId.", fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $id))) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::BALLOT, (int) $outcome['candidate_id'], 0, $ballot['question'].': confirmed ('.$outcome['outcome'].')');
		return 1;
	}

	/**
	 * The confirmed count as members see it, null before.
	 *
	 * @param int $id Ballot
	 * @return array{revision:int,outcome:string,passed:bool,counts:array<string,int>,valid:int,abstain:int,winner:string}|null
	 */
	private function confirmedResult($id)
	{
		foreach ($this->results($id) as $result) {
			if ($result['status'] === VereineBallotRules::RESULT_CONFIRMED) {
				$snapshot = $result['snapshot'];
				return array('revision' => $result['revision'], 'outcome' => (string) $snapshot['outcome']['outcome'], 'passed' => (bool) $snapshot['outcome']['passed'],
					'counts' => (array) $snapshot['counts'], 'valid' => (int) $snapshot['valid'], 'abstain' => (int) $snapshot['abstain'], 'winner' => (string) $snapshot['outcome']['winner']);
			}
		}
		return null;
	}

	/**
	 * What a count keeps: question, rules, times, rights, votes per option, quorum when the ballot opened, and the outcome. No person and no vote of a person.
	 *
	 * @param array<string,mixed> $ballot Ballot of fetch()
	 * @return array<string,mixed>
	 */
	private function snapshot(array $ballot)
	{
		$tally = $this->tally($ballot);
		$rights = $this->rights($ballot['id']);
		$eligible = 0;
		$represented = 0;
		$used = 0;
		foreach ($rights as $right) {
			$eligible += $right['eligible'] ? 1 : 0;
			$represented += $right['eligible'] && $right['reason'] === VereineBallotRules::REASON_PROXY ? 1 : 0;
			$used += $right['used'] ? 1 : 0;
		}
		$opened = $ballot['opened_at'] > 0 ? dol_print_date($ballot['opened_at'], '%H:%M', 'tzserver') : '';
		$quorum = $ballot['quorum'];
		if ($quorum === null) {
			$attendance = (new VereineMeetings($this->db))->attendance($ballot['meeting_id']);
			$rules = array('proxy' => !empty($ballot['rules']['proxy']), 'general_quorum' => isset($ballot['rules']['general_quorum']) ? (int) $ballot['rules']['general_quorum'] : 0,
				'board_quorum' => 0);
			$quorum = VereineAttendanceRules::quorum($ballot['meeting_kind'], $attendance['rows'], $attendance['voting'], $rules, $opened);
		}
		$options = array();
		foreach ($ballot['options'] as $option) {
			$options[] = array('code' => $option['code'], 'label' => $option['label'], 'count' => (int) $tally['counts'][$option['code']]);
		}
		return array('ballot_id' => $ballot['id'], 'meeting' => $ballot['meeting_title'], 'day' => $ballot['day'], 'item' => $ballot['item'], 'kind' => $ballot['kind'],
			'question' => $ballot['question'], 'rules' => $ballot['rules'], 'opened_at' => $ballot['opened_at'] > 0 ? dol_print_date($ballot['opened_at'], 'dayhourrfc') : '',
			'closed_at' => $ballot['closed_at'] > 0 ? dol_print_date($ballot['closed_at'], 'dayhourrfc') : '', 'opened_time' => $opened, 'options' => $options,
			'counts' => $tally['counts'], 'valid' => $tally['valid'], 'abstain' => $tally['abstain'],
			'rights' => array('eligible' => $eligible, 'represented' => $represented, 'used' => $used, 'unused' => $eligible - $used), 'quorum' => $quorum,
			'outcome' => VereineBallotRules::outcome($ballot, $tally, $quorum));
	}

	/**
	 * Write the proof of a count as PDF/A with its code.
	 *
	 * @param array<string,mixed> $snapshot    The snapshot of the count
	 * @param int                 $revision    Its number
	 * @param string              $reason      Why it was counted again
	 * @param int                 $created     When it was counted
	 * @param string              $file        Where the PDF goes
	 * @param string              $code        Code of the document
	 * @param Translate           $outputlangs Language
	 * @return int 1 when written, -1 on error
	 */
	private function writeProof(array $snapshot, $revision, $reason, $created, $file, $code, $outputlangs)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once __DIR__.'/vereinearchive.class.php';

		$outputlangs->load('vereine@vereine');
		$pdf = VereinePdf::start($outputlangs, true);
		$font = pdf_getPDFFont($outputlangs);
		$line = function ($text, $style = '') use ($pdf, $font) {
			$pdf->SetFont($font, $style, 10);
			$pdf->MultiCell(0, 5, $text, 0, 'L');
		};
		$label = function ($option) use ($outputlangs) {
			return (string) $option['label'] !== '' ? (string) $option['label'] : $outputlangs->transnoentitiesnoconv('VereineBallotOption_'.$option['code']);
		};
		$title = $outputlangs->transnoentities('VereineBallotProofTitle');
		VereinePdf::title($pdf, $outputlangs, $title, (string) $snapshot['meeting']);
		$line($outputlangs->transnoentities('VereineBallotProofItem', (int) $snapshot['item'], (string) $snapshot['question']), 'B');
		$line($outputlangs->transnoentities('VereineBallotProofKind', $outputlangs->transnoentitiesnoconv('VereineVoteKind_'.$snapshot['kind']), vereineFormatDay((string) $snapshot['day'])));
		$line($outputlangs->transnoentities('VereineBallotProofPeriod', (string) $snapshot['opened_at'], (string) $snapshot['closed_at'], (string) $snapshot['rules']['timezone']));
		$line($outputlangs->transnoentities('VereineBallotProofRules', $outputlangs->transnoentitiesnoconv('VereineStatuteMajority_'.$snapshot['outcome']['majority']),
			$outputlangs->transnoentitiesnoconv(!empty($snapshot['rules']['proxy']) ? 'Yes' : 'No'), (int) $snapshot['rules']['statute_version']));
		$pdf->Ln(3);
		VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineBallotProofRights'));
		$line($outputlangs->transnoentities('VereineBallotProofRightsLine', (int) $snapshot['rights']['eligible'], (int) $snapshot['rights']['represented'],
			(int) $snapshot['rights']['used'], (int) $snapshot['rights']['unused']));
		$quorum = $snapshot['quorum'];
		$line($outputlangs->transnoentities('VereineBallotProofQuorum', (string) $snapshot['opened_time'], (int) $quorum['votes'], (int) $quorum['required'],
			$outputlangs->transnoentitiesnoconv(!empty($quorum['reached']) ? 'Yes' : 'No')));
		VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineBallotProofVotes'));
		foreach ($snapshot['options'] as $option) {
			$line($label($option).': '.(int) $option['count']);
		}
		$line($outputlangs->transnoentities('VereineBallotProofValid', (int) $snapshot['valid'], (int) $snapshot['abstain']));
		VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineBallotProofOutcome'));
		$line($outputlangs->transnoentitiesnoconv('VereineBallotOutcome_'.$snapshot['outcome']['outcome']), 'B');
		if ((string) $snapshot['outcome']['winner'] !== '') {
			foreach ($snapshot['options'] as $option) {
				if ($option['code'] === $snapshot['outcome']['winner']) {
					$line($outputlangs->transnoentities('VereineBallotProofElected', $label($option)));
				}
			}
		}
		$pdf->Ln(3);
		$line($outputlangs->transnoentities('VereineBallotProofState', (int) $revision, dol_print_date($created, 'dayhour', 'tzserver', $outputlangs)), 'I');
		if ((string) $reason !== '') {
			$line($outputlangs->transnoentities('VereineBallotProofReason', (string) $reason), 'I');
		}
		VereinePdf::finish($pdf, $outputlangs, $title.' - '.$outputlangs->transnoentities('VereineBallotProofRevision', (int) $revision), VereineArchive::seal($code));
		$pdf->Output($file, 'F');
		if (!is_file($file)) {
			$this->error = 'cannot write '.$file;
			return -1;
		}
		dolChmod($file);
		return 1;
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
