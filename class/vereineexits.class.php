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
 * \file    class/vereineexits.class.php
 * \ingroup vereine
 * \brief   Planned exits of members in llx_vereine_member_exit, carried out on their last day.
 */

require_once __DIR__.'/vereineexitrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Records exits and sets members to resiliated or excluded in Dolibarr on their last day.
 */
class VereineExits
{
	/** The exit waits for its last day. */
	const STATUS_PLANNED = 'planned';
	/** Dolibarr's member status was changed. */
	const STATUS_DONE = 'done';
	/** The exit was taken back before its last day. */
	const STATUS_CANCELLED = 'cancelled';

	/** Notice period in months. */
	const CONST_MONTHS = 'VEREINE_EXIT_NOTICE_MONTHS';
	/** Kind of date notice takes effect on. */
	const CONST_AT = 'VEREINE_EXIT_AT';
	/** Month the association year starts. */
	const CONST_START_MONTH = 'VEREINE_EXIT_START_MONTH';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * @var string[] Language keys of the last refused input
	 */
	public $errors = array();

	/**
	 * @var string What the scheduled job did, shown in Dolibarr's list of scheduled jobs
	 */
	public $output = '';

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
	 * The notice rule of the statutes.
	 *
	 * @return array{months:int,at:string,start_month:int}
	 */
	public function rule()
	{
		return VereineExitRules::normalize(getDolGlobalString(self::CONST_MONTHS, '0'), getDolGlobalString(self::CONST_AT, VereineExitRules::AT_ANY_DAY),
			getDolGlobalString(self::CONST_START_MONTH, '1'));
	}

	/**
	 * Store the notice rule.
	 *
	 * @param mixed $months     Notice period in months
	 * @param mixed $at         One of VereineExitRules::AT_*
	 * @param mixed $startMonth Month the association year starts
	 * @return int 1 if stored, 0 when refused (see $errors), <0 on error
	 */
	public function saveRule($months, $at, $startMonth)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$this->errors = VereineExitRules::validate($months, $at, $startMonth);
		if ($this->errors) {
			return 0;
		}
		$rule = VereineExitRules::normalize($months, $at, $startMonth);
		foreach (array(self::CONST_MONTHS => $rule['months'], self::CONST_AT => $rule['at'], self::CONST_START_MONTH => $rule['start_month']) as $name => $value) {
			if (dolibarr_set_const($this->db, $name, (string) $value, 'chaine', 0, '', $conf->entity) < 0) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		return 1;
	}

	/**
	 * Exits of members, newest first.
	 *
	 * @param int[]|null  $memberIds Only these members, null for all of this entity
	 * @param string|null $status    Only this status, null for all
	 * @return array<int,array{id:int,member_id:int,reason:string,notice_day:string,last_day:string,note:string,status:string}>
	 */
	public function fetchAll($memberIds = null, $status = null)
	{
		global $conf;

		$sql = "SELECT rowid, fk_adherent, reason, notice_day, last_day, note, status FROM ".MAIN_DB_PREFIX."vereine_member_exit";
		$sql .= " WHERE entity = ".((int) $conf->entity);
		if ($memberIds !== null) {
			$ids = array_values(array_filter(array_map('intval', $memberIds)));
			if (!$ids) {
				return array();
			}
			$sql .= " AND fk_adherent IN (".implode(', ', $ids).")";
		}
		if ($status !== null) {
			$sql .= " AND status = '".$this->db->escape($status)."'";
		}
		$sql .= " ORDER BY rowid DESC";
		// The table exists only after the module was enabled with 0.3.9; without it there are no exits.
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$exits = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$exits[] = array(
				'id' => (int) $obj->rowid,
				'member_id' => (int) $obj->fk_adherent,
				'reason' => (string) $obj->reason,
				'notice_day' => $obj->notice_day ? substr((string) $obj->notice_day, 0, 10) : '',
				'last_day' => substr((string) $obj->last_day, 0, 10),
				'note' => (string) $obj->note,
				'status' => (string) $obj->status,
			);
		}
		$this->db->free($resql);
		return $exits;
	}

	/**
	 * Planned exits by member.
	 *
	 * @param int[]|null $memberIds Only these members, null for all
	 * @return array<int,array<string,mixed>>
	 */
	public function planned($memberIds = null)
	{
		$planned = array();
		foreach ($this->fetchAll($memberIds, self::STATUS_PLANNED) as $exit) {
			$planned[$exit['member_id']] = $exit;
		}
		return $planned;
	}

	/**
	 * Record an exit and carry it out at once when its last day has come.
	 *
	 * A resignation ends on the day the notice rule gives; the other reasons on the day entered.
	 *
	 * @param Adherent $member    Validated member
	 * @param string   $reason    One of VereineExitRules::REASON_*
	 * @param string   $noticeDay Day notice was given or the decision was taken
	 * @param string   $lastDay   Last day of the membership, used for all reasons but a resignation
	 * @param string   $note      Internal note
	 * @param User     $user      User
	 * @return int Exit id, 0 when refused (see $errors), <0 on error
	 */
	public function plan($member, $reason, $noticeDay, $lastDay, $note, $user)
	{
		global $conf;

		$this->errors = array();
		if ((int) $member->statut !== 1) {
			$this->errors[] = 'VereineExitErrorNotActive';
		}
		if (!in_array($reason, VereineExitRules::REASONS, true)) {
			$this->errors[] = 'VereineExitErrorReason';
		}
		if (!VereineExitRules::isDate($noticeDay)) {
			$this->errors[] = 'VereineExitErrorNoticeDay';
		}
		if ($reason === VereineExitRules::REASON_RESIGNATION && VereineExitRules::isDate($noticeDay)) {
			$lastDay = VereineExitRules::lastDay($noticeDay, $this->rule());
		} elseif (!VereineExitRules::isDate($lastDay) || (VereineExitRules::isDate($noticeDay) && $lastDay < $noticeDay)) {
			$this->errors[] = 'VereineExitErrorLastDay';
		}
		if (!$this->errors && $this->planned(array((int) $member->id))) {
			$this->errors[] = 'VereineExitErrorAlreadyPlanned';
		}
		if ($this->errors) {
			return 0;
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_member_exit (entity, fk_adherent, reason, notice_day, last_day, note, status, datec, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $member->id).", '".$this->db->escape($reason)."', '".$this->db->escape($noticeDay)."',";
		$sql .= " '".$this->db->escape($lastDay)."', '".$this->db->escape(dol_trunc(trim((string) $note), 255, 'right', 'UTF-8', 1))."', '".self::STATUS_PLANNED."',";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_member_exit');
		VereineLog::add($this->db, $user, VereineLog::EXIT_PLANNED, (int) $member->id, (int) $member->fk_soc, $reason.' / '.$noticeDay.' / '.$lastDay);

		if (VereineExitRules::isDue($lastDay, $this->today())) {
			$exits = $this->fetchAll(array((int) $member->id), self::STATUS_PLANNED);
			if ($exits && $this->carryOut($exits[0], $user) < 0) {
				return -1;
			}
		}
		return $id;
	}

	/**
	 * Take back a planned exit.
	 *
	 * @param int  $id   Exit id
	 * @param User $user User
	 * @return int 1 if taken back, 0 when there is no such planned exit, <0 on error
	 */
	public function cancel($id, $user)
	{
		global $conf;

		$exit = null;
		foreach ($this->fetchAll(null, self::STATUS_PLANNED) as $candidate) {
			if ($candidate['id'] === (int) $id) {
				$exit = $candidate;
			}
		}
		if ($exit === null) {
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_member_exit SET status = '".self::STATUS_CANCELLED."', fk_user_done = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity)." AND status = '".self::STATUS_PLANNED."'";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::EXIT_CANCELLED, $exit['member_id'], 0, $exit['reason'].' / '.$exit['last_day']);
		return 1;
	}

	/**
	 * Scheduled job: carry out every planned exit whose last day has come.
	 *
	 * @return int 0 if OK, the number of failed exits otherwise
	 */
	public function runDue()
	{
		global $user;

		$today = $this->today();
		$done = 0;
		$failed = 0;
		foreach (array_reverse($this->fetchAll(null, self::STATUS_PLANNED)) as $exit) {
			if (!VereineExitRules::isDue($exit['last_day'], $today)) {
				continue;
			}
			if ($this->carryOut($exit, $user) > 0) {
				$done++;
			} else {
				$failed++;
			}
		}
		$this->output = $done.' exits carried out'.($failed ? ', '.$failed.' failed: '.$this->error : '');
		return $failed;
	}

	/**
	 * Set the member to resiliated or excluded and mark the exit as done.
	 *
	 * @param array<string,mixed> $exit Planned exit
	 * @param User                $user User
	 * @return int 1 if done, <0 on error
	 */
	public function carryOut(array $exit, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';

		$member = new Adherent($this->db);
		if ($member->fetch($exit['member_id']) <= 0) {
			$this->error = 'member '.$exit['member_id'].': '.$member->error;
			VereineLog::add($this->db, $user, VereineLog::EXIT_ERROR, $exit['member_id'], 0, $this->error);
			return -1;
		}
		$wanted = VereineExitRules::statusFor($exit['reason']) === 'excluded' ? Adherent::STATUS_EXCLUDED : Adherent::STATUS_RESILIATED;
		if ((int) $member->statut !== (int) $wanted) {
			$result = $wanted === Adherent::STATUS_EXCLUDED ? $member->exclude($user) : $member->resiliate($user);
			if ($result < 0) {
				$this->error = 'member '.$exit['member_id'].': '.$member->error.' '.implode(' | ', (array) $member->errors);
				VereineLog::add($this->db, $user, VereineLog::EXIT_ERROR, $exit['member_id'], (int) $member->fk_soc, $this->error);
				return -1;
			}
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_member_exit SET status = '".self::STATUS_DONE."', date_done = '".$this->db->idate(dol_now())."',";
		$sql .= " fk_user_done = ".(is_object($user) && (int) $user->id > 0 ? (int) $user->id : "NULL")." WHERE rowid = ".((int) $exit['id']);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::EXIT_DONE, $exit['member_id'], (int) $member->fk_soc, $exit['reason'].' / '.$exit['last_day']);
		return 1;
	}

	/**
	 * Today in the server's time zone.
	 *
	 * @return string YYYY-MM-DD
	 */
	private function today()
	{
		return dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
	}
}
