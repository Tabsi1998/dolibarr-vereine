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
 * \file    class/vereinearrears.class.php
 * \ingroup vereine
 * \brief   Fee arrears reported by the Mahnwesen module (#17): one proposal per dunning case for the board.
 *
 * The Mahnwesen module is optional. Without it no event comes and nothing here runs. With it, its
 * integration events arrive as ordinary Dolibarr triggers; before an event counts, the case and the
 * invoice are read again as they are now, so a payment that overtook the event wins. The general feed
 * and the member summary get nothing of the dunning file.
 */

require_once __DIR__.'/vereinearrearrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Fee arrears of members.
 */
class VereineArrears
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
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * The Mahnwesen module's own reader of cases and profiles, when it is there and knows what is asked.
	 *
	 * @param DoliDB $db Database handler
	 * @return object|null
	 */
	public static function dunning($db)
	{
		if (!isModEnabled('mahnwesen')) {
			return null;
		}
		if (!class_exists('DunningManager')) {
			dol_include_once('/mahnwesen/class/dunningmanager.class.php');
		}
		if (!class_exists('DunningManager')) {
			return null;
		}
		$manager = new DunningManager($db);
		return method_exists($manager, 'getCase') && method_exists($manager, 'resolveProfile') ? $manager : null;
	}

	/**
	 * Take in one event of the Mahnwesen module.
	 *
	 * @param object $event The event as the Mahnwesen module delivers it
	 * @param User   $user  Who caused it
	 * @return int 1 when an arrear changed, 0 when the event means nothing here, -1 on error (it is delivered again)
	 */
	public function onEvent($event, $user)
	{
		global $conf;

		if (!is_object($event) || !isset($event->element) || $event->element !== 'mahnwesen_event') {
			return 0;
		}
		if (!isset($event->contract_version) || (string) $event->contract_version !== VereineArrearRules::CONTRACT) {
			dol_syslog('Vereine: Mahnwesen event '.(isset($event->event_id) ? $event->event_id : '?').' has an unknown contract, left alone', LOG_WARNING);
			return 0;
		}
		if (!empty($event->entity) && (int) $event->entity !== (int) $conf->entity) {
			return 0;
		}
		$caseId = (int) $event->case_id;
		$invoiceId = (int) $event->invoice_id;
		$stored = $this->byCase($caseId);
		$fee = $this->feeOf($invoiceId);
		$facts = array('fee' => $fee !== null, 'paid' => $this->paid($invoiceId), 'final_step' => '', 'case_status' => '', 'paused' => false);
		// Read the case as it is now: a payment or a pause since the event was noted counts.
		$manager = self::dunning($this->db);
		if ($manager !== null) {
			$case = $manager->getCase($caseId);
			$facts['case_status'] = $case ? (string) $case['status'] : '';
			$facts['paused'] = $case && !empty($case['paused']);
			$resolution = $manager->resolveProfile($invoiceId);
			$facts['final_step'] = isset($resolution['profile']['final_step']) ? (string) $resolution['profile']['final_step'] : '';
		}
		$decision = VereineArrearRules::decide($stored, array('type' => (string) $event->event_type, 'revision' => (int) $event->case_revision), $facts);
		if ($decision['do'] === 'ignore') {
			return 0;
		}
		$now = $this->db->idate(dol_now());
		$memberId = $stored ? (int) $stored['member_id'] : (int) $fee['member'];
		if ($decision['do'] === 'create') {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_arrear (entity, fk_case, fk_facture, fk_adherent, fk_subscription, level, state, case_revision, event_id, datec, date_state)";
			$sql .= " VALUES (".((int) $conf->entity).", ".$caseId.", ".$invoiceId.", ".$memberId.", ".((int) $fee['subscription']).", ".((int) $event->level).",";
			$sql .= " '".$this->db->escape($decision['state'])."', ".((int) $event->case_revision).", '".$this->db->escape((string) $event->event_id)."', '".$now."', '".$now."')";
		} else {
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_arrear SET state = '".$this->db->escape($decision['state'])."', level = ".max((int) $stored['level'], (int) $event->level).",";
			$sql .= " case_revision = ".((int) $event->case_revision).", event_id = '".$this->db->escape((string) $event->event_id)."'";
			$sql .= $decision['state'] !== $stored['state'] ? ", date_state = '".$now."'" : "";
			$sql .= " WHERE rowid = ".((int) $stored['id']);
		}
		if (!$this->db->query($sql)) {
			// The same case at the same moment from another request: that one keeps it.
			if ($this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				return 0;
			}
			$this->error = $this->db->lasterror();
			return -1;
		}
		if (!$stored || $decision['state'] !== $stored['state']) {
			VereineLog::add($this->db, $user, VereineLog::ARREAR, $memberId, 0, 'case '.$caseId.': '.$decision['state'].' ('.$decision['why'].')');
		}
		return 1;
	}

	/**
	 * Arrears waiting for the board and not yet on an agenda, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function waiting()
	{
		return $this->rows("a.state = 'open' AND (a.fk_meeting = 0 OR m.status = 'cancelled')");
	}

	/**
	 * The arrears of one member, newest first.
	 *
	 * @param int $memberId Member
	 * @return array<int,array<string,mixed>>
	 */
	public function forMember($memberId)
	{
		return array_reverse($this->rows("a.fk_adherent = ".((int) $memberId)));
	}

	/**
	 * Note that arrears went on the agenda of a board meeting.
	 *
	 * @param int[] $ids       Arrears
	 * @param int   $meetingId Meeting
	 * @param User  $user      Who
	 * @return int Number noted, -1 on error
	 */
	public function putOnAgenda(array $ids, $meetingId, $user)
	{
		global $conf;

		$ids = array_filter(array_map('intval', $ids));
		if (!$ids) {
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_arrear SET fk_meeting = ".((int) $meetingId)." WHERE entity = ".((int) $conf->entity);
		$sql .= " AND state = 'open' AND rowid IN (".implode(',', $ids).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		foreach ($this->rows("a.rowid IN (".implode(',', $ids).")") as $arrear) {
			VereineLog::add($this->db, $user, VereineLog::ARREAR, (int) $arrear['member_id'], 0, 'case '.$arrear['case_id'].': on the agenda of meeting '.((int) $meetingId));
		}
		return count($ids);
	}

	/**
	 * Arrears with their member, invoice and meeting.
	 *
	 * @param string $where Condition on a (arrear) and m (meeting)
	 * @return array<int,array<string,mixed>>
	 */
	private function rows($where)
	{
		global $conf;

		$sql = "SELECT a.rowid, a.fk_case, a.fk_facture, a.fk_adherent, a.level, a.state, a.case_revision, a.fk_meeting, a.datec, a.date_state,";
		$sql .= " d.firstname, d.lastname, f.ref as invoice_ref, m.title as meeting_title, m.meeting_day";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_arrear as a";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."adherent as d ON d.rowid = a.fk_adherent";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = a.fk_facture";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."vereine_meeting as m ON m.rowid = a.fk_meeting";
		$sql .= " WHERE a.entity = ".((int) $conf->entity)." AND ".$where." ORDER BY a.datec, a.rowid";
		$rows = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$rows[] = array('id' => (int) $obj->rowid, 'case_id' => (int) $obj->fk_case, 'invoice_id' => (int) $obj->fk_facture, 'invoice_ref' => (string) $obj->invoice_ref,
				'member_id' => (int) $obj->fk_adherent, 'name' => trim($obj->firstname.' '.$obj->lastname), 'level' => (int) $obj->level, 'state' => (string) $obj->state,
				'revision' => (int) $obj->case_revision, 'meeting_id' => (int) $obj->fk_meeting, 'meeting' => (string) $obj->meeting_title,
				'meeting_day' => $obj->meeting_day !== null ? substr((string) $obj->meeting_day, 0, 10) : '',
				'since' => (int) $this->db->jdate($obj->date_state));
		}
		return $rows;
	}

	/**
	 * The arrear kept for a dunning case.
	 *
	 * @param int $caseId Case
	 * @return array<string,mixed>|null
	 */
	private function byCase($caseId)
	{
		$rows = $this->rows("a.fk_case = ".((int) $caseId));
		return $rows ? $rows[0] : null;
	}

	/**
	 * The membership fee an invoice is for, by Dolibarr's own link between a subscription and its invoice.
	 * A sale to a member, an event fee or a customer category is no fee.
	 *
	 * @param int $invoiceId Invoice
	 * @return array{member:int,subscription:int}|null
	 */
	private function feeOf($invoiceId)
	{
		$sql = "SELECT s.rowid as subscription, s.fk_adherent FROM ".MAIN_DB_PREFIX."element_element as e";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."subscription as s ON s.rowid = e.fk_source";
		$sql .= " WHERE e.sourcetype = 'subscription' AND e.targettype = 'facture' AND e.fk_target = ".((int) $invoiceId)." ORDER BY s.rowid";
		$resql = $this->db->query($sql.$this->db->plimit(1));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? array('member' => (int) $obj->fk_adherent, 'subscription' => (int) $obj->subscription) : null;
	}

	/**
	 * Whether the invoice is paid, as Dolibarr has it now.
	 *
	 * @param int $invoiceId Invoice
	 * @return bool
	 */
	private function paid($invoiceId)
	{
		$resql = $this->db->query("SELECT paye FROM ".MAIN_DB_PREFIX."facture WHERE rowid = ".((int) $invoiceId));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj && (int) $obj->paye === 1;
	}
}
