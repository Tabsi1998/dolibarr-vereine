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
 * \file    class/vereinecirculars.class.php
 * \ingroup vereine
 * \brief   Circular resolutions of the board: start one, vote in Dolibarr, count the result.
 *
 * The board decides as an organ, so its members vote here in Dolibarr and not through the website
 * (see docs/ARCHITECTURE.md). Everyone gets an e-mail with the link; a reminder can follow. What comes
 * out of it goes into the register of resolutions like every other resolution.
 */

require_once __DIR__.'/vereinecircularrules.class.php';
require_once __DIR__.'/vereinemeetings.class.php';
require_once __DIR__.'/vereineresolutions.class.php';
require_once __DIR__.'/vereinestatutes.class.php';
require_once __DIR__.'/vereinefunctions.class.php';
require_once __DIR__.'/vereinemail.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Circular resolutions of the board.
 */
class VereineCirculars
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
	 * The rules of the statutes.
	 *
	 * @return array<string,mixed>
	 */
	public function rules()
	{
		$statutes = new VereineStatutes($this->db);
		return $statutes->rules();
	}

	/**
	 * The board members who may vote on a day, with their e-mail address and their function.
	 *
	 * @param string $day Day as YYYY-MM-DD
	 * @return array<int,array{member_id:int,name:string,email:string,label:string}>
	 */
	public function voters($day)
	{
		$functions = new VereineFunctions($this->db);
		$labels = array();
		foreach ($functions->fetchAll(true) as $function) {
			$labels[$function['code']] = $function['label'];
		}
		$byMember = array();
		foreach ($functions->holdersByCode($day) as $code => $holders) {
			foreach ($holders as $holder) {
				if (!isset($byMember[(int) $holder['member_id']]) && isset($labels[$code])) {
					$byMember[(int) $holder['member_id']] = $labels[$code];
				}
			}
		}
		$meetings = new VereineMeetings($this->db);
		$voters = array();
		foreach ($meetings->members($day) as $member) {
			if (!$member['board'] || $member['status'] !== 1) {
				continue;
			}
			$voters[] = array('member_id' => $member['id'], 'name' => $member['name'], 'email' => $member['email'],
				'label' => isset($byMember[$member['id']]) ? $byMember[$member['id']] : '');
		}
		return $voters;
	}

	/**
	 * The board members who cannot vote in Dolibarr, because no user is linked to their member.
	 *
	 * @param array<int,array<string,mixed>> $voters Board members of voters()
	 * @return string[] Their names
	 */
	public function votersWithoutUser(array $voters)
	{
		global $conf;

		$linked = array();
		$resql = $this->db->query("SELECT fk_member FROM ".MAIN_DB_PREFIX."user WHERE fk_member IS NOT NULL AND statut = 1 AND entity IN (0, ".((int) $conf->entity).")");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$linked[(int) $obj->fk_member] = true;
		}
		$names = array();
		foreach ($voters as $voter) {
			if (!isset($linked[(int) $voter['member_id']])) {
				$names[] = (string) $voter['name'];
			}
		}
		return $names;
	}

	/**
	 * Start a circular resolution: everybody of the board gets it by e-mail with the link to Dolibarr.
	 *
	 * @param array<string,mixed> $entered     Entered circular resolution
	 * @param User                $user        Who starts it
	 * @param Translate           $outputlangs Language of the e-mail
	 * @return int Id of the circular resolution, 0 when refused (see errors), -1 on error
	 */
	public function start(array $entered, $user, $outputlangs)
	{
		global $conf, $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

		$this->errors = array();
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$circular = VereineCircularRules::normalize($entered);
		$voters = $this->voters($today);
		$this->errors = VereineCircularRules::validate($circular, $this->rules(), $today, count($voters));
		if ($this->errors) {
			return 0;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_circular (entity, title, wording, deadline, status, started_on, datec, fk_user_modif)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($circular['title'])."', '".$this->db->escape($circular['wording'])."',";
		$sql .= " '".$this->db->escape($circular['deadline'])."', '".VereineCircularRules::STATUS_OPEN."', '".$this->db->escape($today)."',";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_circular');
		$from = VereineMail::sender();
		foreach ($voters as $voter) {
			$sentAt = null;
			if ($voter['email'] !== '') {
				$mail = new CMailFile($outputlangs->transnoentities('VereineCircularMailSubject', $circular['title']), $voter['email'], $from,
					$this->mailText($id, $circular, $voter, false, $outputlangs), array(), array(), array(), '', '', 0, 0, '', '', 'circular'.$id);
				if ($mail->sendfile()) {
					$sentAt = dol_now();
				} else {
					dol_syslog(__METHOD__.' mail: '.$mail->error, LOG_WARNING);
				}
			}
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_circular_vote (entity, fk_circular, fk_adherent, person_name, function_label, email, invited_at, datec)";
			$sql .= " VALUES (".((int) $conf->entity).", ".$id.", ".((int) $voter['member_id']).", '".$this->db->escape($voter['name'])."',";
			$sql .= " '".$this->db->escape($voter['label'])."', '".$this->db->escape($voter['email'])."',";
			$sql .= " ".($sentAt !== null ? "'".$this->db->idate($sentAt)."'" : "NULL").", '".$this->db->idate(dol_now())."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		VereineLog::add($this->db, $user, VereineLog::CIRCULAR_STARTED, 0, 0, $circular['title'].': '.count($voters).' board members');
		return $id;
	}

	/**
	 * The text of the e-mail: what was moved, until when, and where to vote.
	 *
	 * @param int                 $id          Circular resolution
	 * @param array<string,mixed> $circular    Circular resolution
	 * @param array<string,mixed> $voter       Who gets the e-mail
	 * @param bool                $reminder    Whether it is the reminder
	 * @param Translate           $outputlangs Language of the e-mail
	 * @return string
	 */
	private function mailText($id, array $circular, array $voter, $reminder, $outputlangs)
	{
		global $mysoc;

		$link = dol_buildpath('/vereine/circulars.php', 2).'?id='.((int) $id);
		$lines = array();
		$lines[] = $outputlangs->transnoentities($reminder ? 'VereineCircularMailReminder' : 'VereineCircularMailIntro', $voter['name'], (string) $mysoc->name);
		$lines[] = '';
		$lines[] = $circular['title'];
		$lines[] = $circular['wording'];
		$lines[] = '';
		$lines[] = $outputlangs->transnoentities('VereineCircularMailDeadline', vereineFormatDay($circular['deadline']));
		$lines[] = $outputlangs->transnoentities('VereineCircularMailLink', $link);
		return implode("\n", $lines);
	}

	/**
	 * Every circular resolution, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function fetchAll()
	{
		global $conf;

		$sql = "SELECT rowid, title, wording, deadline, status, started_on, reminded_at, decided_on, passed, yes, no, abstain, objection, fk_resolution";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_circular WHERE entity = ".((int) $conf->entity)." ORDER BY started_on DESC, rowid DESC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'id' => (int) $obj->rowid,
				'title' => (string) $obj->title,
				'wording' => (string) $obj->wording,
				'deadline' => (string) $obj->deadline,
				'status' => (string) $obj->status,
				'started_on' => (string) $obj->started_on,
				'reminded' => $obj->reminded_at ? (int) $this->db->jdate($obj->reminded_at) : 0,
				'decided_on' => (string) $obj->decided_on,
				'passed' => (int) $obj->passed === 1,
				'yes' => (int) $obj->yes,
				'no' => (int) $obj->no,
				'abstain' => (int) $obj->abstain,
				'objection' => (int) $obj->objection,
				'resolution_id' => (int) $obj->fk_resolution,
			);
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * One circular resolution.
	 *
	 * @param int $id Circular resolution
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
	 * Who takes part in a circular resolution and how they voted.
	 *
	 * @param int $id Circular resolution
	 * @return array<int,array<string,mixed>>
	 */
	public function votes($id)
	{
		global $conf;

		$sql = "SELECT rowid, fk_adherent, person_name, function_label, email, choice, voted_at, invited_at FROM ".MAIN_DB_PREFIX."vereine_circular_vote";
		$sql .= " WHERE fk_circular = ".((int) $id)." AND entity = ".((int) $conf->entity)." ORDER BY person_name, rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$votes = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$votes[] = array(
				'id' => (int) $obj->rowid,
				'member_id' => (int) $obj->fk_adherent,
				'name' => (string) $obj->person_name,
				'label' => (string) $obj->function_label,
				'email' => (string) $obj->email,
				'choice' => (string) $obj->choice,
				'voted' => $obj->voted_at ? (int) $this->db->jdate($obj->voted_at) : 0,
				'invited' => $obj->invited_at ? (int) $this->db->jdate($obj->invited_at) : 0,
			);
		}
		$this->db->free($resql);
		return $votes;
	}

	/**
	 * Vote in a circular resolution; only somebody the circular resolution went to may vote, and only once.
	 *
	 * @param int    $id       Circular resolution
	 * @param int    $memberId Who votes
	 * @param string $choice   One of the CHOICE constants
	 * @param User   $user     Who is logged in
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function vote($id, $memberId, $choice, $user)
	{
		global $conf;

		$this->errors = array();
		$circular = $this->fetch($id);
		if ($circular === null || $circular['status'] !== VereineCircularRules::STATUS_OPEN) {
			$this->errors[] = 'VereineCircularErrorNotOpen';
			return 0;
		}
		if (!in_array((string) $choice, VereineCircularRules::CHOICES, true)) {
			$this->errors[] = 'VereineCircularErrorChoice';
			return 0;
		}
		if ((string) $choice === VereineCircularRules::CHOICE_OBJECTION && empty($this->rules()['circular_no_objection'])) {
			$this->errors[] = 'VereineCircularErrorObjection';
			return 0;
		}
		$row = null;
		foreach ($this->votes($id) as $candidate) {
			if ($candidate['member_id'] === (int) $memberId) {
				$row = $candidate;
			}
		}
		if ($row === null) {
			$this->errors[] = 'VereineCircularErrorNotInvited';
			return 0;
		}
		if ($row['voted'] > 0) {
			$this->errors[] = 'VereineCircularErrorVoted';
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_circular_vote SET choice = '".$this->db->escape((string) $choice)."',";
		$sql .= " voted_at = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $row['id'])." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::CIRCULAR_VOTE, (int) $memberId, 0, $circular['title'].': '.$choice);
		return 1;
	}

	/**
	 * Remind everybody who has not voted yet.
	 *
	 * @param int       $id          Circular resolution
	 * @param User      $user        Who reminds
	 * @param Translate $outputlangs Language of the e-mail
	 * @return int Number of reminders sent, 0 when refused (see errors), -1 on error
	 */
	public function remind($id, $user, $outputlangs)
	{
		global $conf, $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

		$this->errors = array();
		$circular = $this->fetch($id);
		if ($circular === null || $circular['status'] !== VereineCircularRules::STATUS_OPEN) {
			$this->errors[] = 'VereineCircularErrorNotOpen';
			return 0;
		}
		$from = VereineMail::sender();
		$sent = 0;
		foreach ($this->votes($id) as $row) {
			if ($row['voted'] > 0 || $row['email'] === '') {
				continue;
			}
			$mail = new CMailFile($outputlangs->transnoentities('VereineCircularMailSubjectReminder', $circular['title']), $row['email'], $from,
				$this->mailText($id, $circular, $row, true, $outputlangs), array(), array(), array(), '', '', 0, 0, '', '', 'circular'.$id);
			if ($mail->sendfile()) {
				$sent++;
			} else {
				dol_syslog(__METHOD__.' mail: '.$mail->error, LOG_WARNING);
			}
		}
		if ($sent < 1) {
			$this->errors[] = 'VereineCircularErrorNobodyOpen';
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_circular SET reminded_at = '".$this->db->idate(dol_now())."', fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::CIRCULAR_REMINDED, 0, 0, $circular['title'].': '.$sent.' reminders');
		return $sent;
	}

	/**
	 * Count the result: everybody voted, somebody objected, or the deadline has passed.
	 *
	 * A resolution that was taken goes into the register like every other one.
	 *
	 * @param int  $id   Circular resolution
	 * @param User $user Who counts
	 * @return int 1 when counted, 0 when refused (see errors), -1 on error
	 */
	public function close($id, $user)
	{
		global $conf;

		$this->errors = array();
		$circular = $this->fetch($id);
		if ($circular === null || $circular['status'] !== VereineCircularRules::STATUS_OPEN) {
			$this->errors[] = 'VereineCircularErrorNotOpen';
			return 0;
		}
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$rules = $this->rules();
		$votes = $this->votes($id);
		if (!VereineCircularRules::ready($circular, count($votes), $votes, $rules, $today)) {
			$this->errors[] = 'VereineCircularErrorNotReady';
			return 0;
		}
		$result = VereineCircularRules::result($votes, $rules);
		$counts = $result['counts'];
		$status = $result['objected'] ? VereineCircularRules::STATUS_CANCELLED : VereineCircularRules::STATUS_DECIDED;
		$resolutionId = 0;
		if (!$result['objected']) {
			$register = new VereineResolutions($this->db);
			$resolutionId = $register->addFromCircular(array_merge($circular, array('decided_on' => $today)), $result, $user);
			if ($resolutionId < 0) {
				$this->error = $register->error;
				return -1;
			}
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_circular SET status = '".$this->db->escape($status)."', decided_on = '".$this->db->escape($today)."',";
		$sql .= " passed = ".($result['passed'] ? 1 : 0).", yes = ".((int) $counts['yes']).", no = ".((int) $counts['no']).",";
		$sql .= " abstain = ".((int) $counts['abstain']).", objection = ".((int) $counts['objection']).", fk_resolution = ".((int) $resolutionId).",";
		$sql .= " fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$outcome = $result['objected'] ? 'objection' : ($result['passed'] ? 'passed' : 'rejected');
		VereineLog::add($this->db, $user, VereineLog::CIRCULAR_DECIDED, 0, 0, $circular['title'].': '.$outcome);
		return 1;
	}

	/**
	 * Call a circular resolution off without a result.
	 *
	 * @param int  $id   Circular resolution
	 * @param User $user Who calls it off
	 * @return int 1 when called off, 0 when refused (see errors), -1 on error
	 */
	public function cancel($id, $user)
	{
		global $conf;

		$this->errors = array();
		$circular = $this->fetch($id);
		if ($circular === null || $circular['status'] !== VereineCircularRules::STATUS_OPEN) {
			$this->errors[] = 'VereineCircularErrorNotOpen';
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_circular SET status = '".VereineCircularRules::STATUS_CANCELLED."',";
		$sql .= " fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::CIRCULAR_CANCELLED, 0, 0, $circular['title']);
		return 1;
	}
}
