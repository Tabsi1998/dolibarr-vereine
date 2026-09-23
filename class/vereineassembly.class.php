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
 * \file    class/vereineassembly.class.php
 * \ingroup vereine
 * \brief   The way through a general assembly (#127): the facts behind every step.
 *
 * Nothing here is stored twice. Every fact is read where the module already keeps it: the account, the
 * audit, the functions, the meeting with its agenda and votes, the minutes, the register of resolutions
 * and the letters to the authority. The rules in VereineAssemblyRules then say what is done, what is
 * next and what is late.
 */

require_once __DIR__.'/vereineassemblyrules.class.php';
require_once __DIR__.'/vereinemeetings.class.php';
require_once __DIR__.'/vereinemeetingrules.class.php';
require_once __DIR__.'/vereinemeetingdocrules.class.php';
require_once __DIR__.'/vereineminutes.class.php';
require_once __DIR__.'/vereinefunctions.class.php';
require_once __DIR__.'/vereinefunctionrules.class.php';
require_once __DIR__.'/vereineauditrules.class.php';
require_once __DIR__.'/vereineaccountrules.class.php';
require_once __DIR__.'/vereineauthorityrules.class.php';
require_once __DIR__.'/vereinestatutes.class.php';
require_once __DIR__.'/vereineresolutionrules.class.php';
require_once __DIR__.'/vereineresolutiondocs.class.php';
require_once __DIR__.'/vereinesignaturerules.class.php';

/**
 * The way through a general assembly.
 */
class VereineAssembly
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
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * The general assembly the association is working on: the next one that is planned, otherwise the
	 * last one that was held.
	 *
	 * @param string $today Today, YYYY-MM-DD
	 * @return array<string,mixed>|null
	 */
	public function current($today)
	{
		$meetings = new VereineMeetings($this->db);
		$coming = null;
		$past = null;
		foreach ($meetings->fetchAll() as $meeting) {
			if (!in_array($meeting['kind'], array(VereineMeetingRules::KIND_GENERAL, VereineMeetingRules::KIND_EXTRAORDINARY), true)
				|| $meeting['status'] === VereineMeetingRules::STATUS_CANCELLED) {
				continue;
			}
			if ($meeting['day'] >= (string) $today) {
				if ($coming === null || $meeting['day'] < $coming['day']) {
					$coming = $meeting;
				}
			} elseif ($past === null || $meeting['day'] > $past['day']) {
				$past = $meeting;
			}
		}
		return $coming !== null ? $coming : $past;
	}

	/**
	 * Every general assembly, the next one first, for the list.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all()
	{
		$meetings = new VereineMeetings($this->db);
		$rows = array();
		foreach ($meetings->fetchAll() as $meeting) {
			if (in_array($meeting['kind'], array(VereineMeetingRules::KIND_GENERAL, VereineMeetingRules::KIND_EXTRAORDINARY), true)) {
				$rows[] = $meeting;
			}
		}
		usort($rows, function ($left, $right) {
			return strcmp($right['day'], $left['day']);
		});
		return $rows;
	}

	/**
	 * The facts of one assembly, as VereineAssemblyRules::check() wants them.
	 *
	 * @param array<string,mixed> $meeting The assembly
	 * @param string              $today   Today, YYYY-MM-DD
	 * @return array<string,mixed>
	 */
	public function facts(array $meeting, $today)
	{
		global $conf;

		$entity = (int) $conf->entity;
		$id = (int) $meeting['id'];
		$day = (string) $meeting['day'];
		$rules = (new VereineStatutes($this->db))->rules();
		$facts = array(
			'day' => $day,
			'held' => $meeting['status'] === VereineMeetingRules::STATUS_HELD,
			'invited_on' => (int) $meeting['invited_at'] > 0 ? dol_print_date((int) $meeting['invited_at'], '%Y-%m-%d', 'tzserver') : '',
			'invite_deadline' => VereineMeetingRules::inviteBy($meeting, $rules),
			'motions_deadline' => VereineMeetingRules::motionsBy($meeting, $rules),
		);

		// The figures of the year that ended before the assembly, and their audit.
		$startMonth = getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1);
		$year = VereineAuditRules::lastEnded($day !== '' ? $day : $today, $startMonth);
		$period = VereineAuditRules::period($year, $startMonth);
		$facts['fiscal_year'] = $year;
		$facts['fiscal_label'] = $period['label'];
		$account = $this->row("SELECT rowid, made_on FROM ".MAIN_DB_PREFIX."vereine_account WHERE entity = ".$entity." AND fiscal_year = ".$year);
		$facts['account_made'] = $account && $account->made_on ? substr((string) $account->made_on, 0, 10) : '';
		$facts['account_deadline'] = VereineAccountRules::deadline($period['end']);
		$audit = $this->row("SELECT rowid, audit_day FROM ".MAIN_DB_PREFIX."vereine_audit WHERE entity = ".$entity." AND fiscal_year = ".$year);
		$facts['audit_day'] = $audit && $audit->audit_day ? substr((string) $audit->audit_day, 0, 10) : '';
		$facts['audit_deadline'] = $facts['account_made'] !== '' ? VereineAuditRules::deadline($facts['account_made']) : '';
		$facts['audit_report_signed'] = $audit ? $this->signed(VereineSignatureRules::KIND_AUDIT_REPORT, (int) $audit->rowid) : false;

		// Elections that are due on the day of the assembly.
		$store = new VereineFunctions($this->db);
		$functions = $store->fetchAll(true);
		$due = 0;
		foreach (VereineFunctionRules::check($functions, $store->terms(), $day !== '' ? $day : $today)['problems'] as $problem) {
			if (in_array($problem['kind'], array(VereineFunctionRules::PROBLEM_MISSING, VereineFunctionRules::PROBLEM_ELECTION_DUE), true)) {
				$due++;
			}
		}
		$facts['elections_due'] = $due;

		// The agenda: every point the template of a general assembly asks for has to be on it.
		$facts['agenda_missing'] = $this->agendaMissing($meeting);

		// What was decided and what proves it.
		$facts['attendance'] = $this->count("SELECT COUNT(*) as total FROM ".MAIN_DB_PREFIX."vereine_meeting_attendance WHERE entity = ".$entity
			." AND fk_meeting = ".$id);
		$facts['votes'] = $this->count("SELECT COUNT(*) as total FROM ".MAIN_DB_PREFIX."vereine_meeting_vote WHERE entity = ".$entity
			." AND fk_meeting = ".$id);
		$facts['elections_on_agenda'] = $this->count("SELECT COUNT(*) as total FROM ".MAIN_DB_PREFIX."vereine_meeting_vote WHERE entity = ".$entity
			." AND fk_meeting = ".$id." AND kind = 'election'");
		$facts['sheets'] = $this->count("SELECT COUNT(*) as total FROM ".MAIN_DB_PREFIX."vereine_meeting_document WHERE entity = ".$entity
			." AND fk_meeting = ".$id." AND kind = '".$this->db->escape(VereineMeetingDocRules::KIND_VOTE)."'");

		// The minutes, their signatures and the members.
		$minutes = new VereineMinutes($this->db);
		$versions = $minutes->versions($id);
		$last = $versions ? $versions[0] : null;
		$facts['minutes_final'] = $last !== null;
		$facts['minutes_signed'] = $last !== null ? $this->signed(VereineSignatureRules::KIND_MINUTES, (int) $last['id']) : false;
		$facts['minutes_sent'] = $last !== null && (int) $last['sent_members'] > 0;

		// Every resolution of the assembly with its own PDF.
		$resolutions = $this->resolutions($id);
		$facts['resolutions'] = count($resolutions);
		$pdfs = 0;
		$statuteChange = false;
		foreach ($resolutions as $resolution) {
			if (is_file(VereineResolutionDocs::path((int) $resolution['id']))) {
				$pdfs++;
			}
			if ($resolution['category'] === VereineResolutionRules::CATEGORY_STATUTES && $resolution['passed']) {
				$statuteChange = true;
			}
		}
		$facts['resolution_pdfs'] = $pdfs;
		$facts['statute_change'] = $statuteChange;
		$sql = "SELECT COUNT(*) as total FROM ".MAIN_DB_PREFIX."vereine_authority_letter WHERE entity = ".$entity;
		$sql .= " AND kind = '".$this->db->escape(VereineAuthorityRules::KIND_STATUTES)."'";
		$sql .= " AND COALESCE(event_date, '9999-12-31') >= '".$this->db->escape($day)."'";
		$facts['statute_letter'] = $statuteChange && $this->count($sql) > 0;

		// New representatives have to reach the authority within four weeks.
		$sql = "SELECT COUNT(*) as total, MIN(deadline) as first_deadline FROM ".MAIN_DB_PREFIX."vereine_function_report";
		$sql .= " WHERE entity = ".$entity." AND reported_on IS NULL";
		$report = $this->row($sql);
		$facts['authority_open'] = $report ? (int) $report->total : 0;
		$facts['authority_deadline'] = $report && $report->first_deadline ? substr((string) $report->first_deadline, 0, 10) : '';

		// User groups that follow from the new functions, once an administrator confirms them.
		$facts['group_changes'] = count($store->groupChanges($today));
		return $facts;
	}

	/**
	 * The steps of an assembly with their state, ready for the page.
	 *
	 * @param array<string,mixed> $meeting The assembly
	 * @param string              $today   Today, YYYY-MM-DD
	 * @return array{steps:array<int,array<string,mixed>>,facts:array<string,mixed>,progress:array<string,int>}
	 */
	public function steps(array $meeting, $today)
	{
		$facts = $this->facts($meeting, $today);
		$steps = VereineAssemblyRules::check($facts, $today);
		return array('steps' => $steps, 'facts' => $facts, 'progress' => VereineAssemblyRules::progress($steps));
	}

	/**
	 * How many points the template of a general assembly asks for and the agenda does not carry.
	 *
	 * @param array<string,mixed> $meeting The assembly
	 * @return int
	 */
	public function agendaMissing(array $meeting)
	{
		$meetings = new VereineMeetings($this->db);
		$templates = $meetings->templates();
		$kind = $meeting['kind'] === VereineMeetingRules::KIND_EXTRAORDINARY ? VereineMeetingRules::KIND_GENERAL : $meeting['kind'];
		$required = array();
		foreach (isset($templates[$kind]) ? $templates[$kind] : array() as $item) {
			if (!empty($item['required']) && trim((string) $item['title']) !== '') {
				$required[] = mb_strtolower(trim((string) $item['title']), 'UTF-8');
			}
		}
		if (!$required) {
			return 0;
		}
		$agenda = array();
		foreach ((array) $meeting['agenda'] as $item) {
			$agenda[] = mb_strtolower(trim((string) $item), 'UTF-8');
		}
		$missing = 0;
		foreach ($required as $title) {
			$found = false;
			foreach ($agenda as $item) {
				if ($item !== '' && (strpos($item, $title) !== false || strpos($title, $item) !== false)) {
					$found = true;
					break;
				}
			}
			if (!$found) {
				$missing++;
			}
		}
		return $missing;
	}

	/**
	 * The resolutions that came out of a meeting.
	 *
	 * @param int $meetingId Meeting
	 * @return array<int,array{id:int,ref:string,title:string,category:string,passed:bool}>
	 */
	public function resolutions($meetingId)
	{
		global $conf;

		$sql = "SELECT rowid, ref, title, category, passed FROM ".MAIN_DB_PREFIX."vereine_resolution";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_meeting = ".((int) $meetingId)." ORDER BY rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'title' => (string) $obj->title,
				'category' => (string) $obj->category, 'passed' => (int) $obj->passed === 1);
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * Whether a document of a kind is signed through.
	 *
	 * @param string $kind     One of the VereineSignatureRules KIND constants
	 * @param int    $objectId The object
	 * @return bool
	 */
	private function signed($kind, $objectId)
	{
		global $conf;

		$sql = "SELECT status FROM ".MAIN_DB_PREFIX."vereine_signature WHERE entity = ".((int) $conf->entity);
		$sql .= " AND kind = '".$this->db->escape((string) $kind)."' AND fk_object = ".((int) $objectId)." ORDER BY rowid DESC";
		$obj = $this->row($sql);
		return $obj !== null && (string) $obj->status === 'done';
	}

	/**
	 * The first row of a query, null when there is none.
	 *
	 * @param string $sql Query
	 * @return object|null
	 */
	private function row($sql)
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? $obj : null;
	}

	/**
	 * A counting query.
	 *
	 * @param string $sql Query with a column named total
	 * @return int
	 */
	private function count($sql)
	{
		$obj = $this->row($sql);
		return $obj ? (int) $obj->total : 0;
	}
}
