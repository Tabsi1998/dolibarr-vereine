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
 * \file    class/vereinewaiting.class.php
 * \ingroup vereine
 * \brief   What waits for one person: votes in circular resolutions, signatures, tasks.
 *
 * Board members vote on circular resolutions in Dolibarr with their own user (decision of 21.09.2026), so
 * they have to see at once when something waits for them. Everything is found through the member the
 * Dolibarr user is linked to.
 */

require_once __DIR__.'/vereineaccountrules.class.php';

/**
 * The open things of one member.
 */
class VereineWaiting
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

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
	 * What waits for a member, each with a text and a link.
	 *
	 * @param int $memberId Member the Dolibarr user is linked to
	 * @return array{votes:array<int,array<string,mixed>>,signatures:array<int,array<string,mixed>>,tasks:array<int,array<string,mixed>>}
	 */
	/**
	 * What the association has to do, whoever looks: deadlines, elections and applications that wait
	 * for a decision (#124). Everything comes from data the module already keeps.
	 *
	 * @param string $today Today, YYYY-MM-DD
	 * @return array<int,array{kind:string,state:string,title:string,deadline:string,url:string}> Most urgent first
	 */
	public function forAssociation($today)
	{
		global $conf, $langs, $user;

		require_once __DIR__.'/vereinefunctions.class.php';
		require_once __DIR__.'/vereineauditrules.class.php';
		require_once __DIR__.'/vereineaccount.class.php';
		require_once __DIR__.'/vereineapplicationrules.class.php';
		require_once __DIR__.'/vereinestatutes.class.php';
		require_once dirname(__DIR__).'/lib/vereine.lib.php';

		$langs->load('vereine@vereine');
		$entity = (int) $conf->entity;
		$open = array();
		$add = function ($kind, $deadline, $title, $url) use (&$open, $today) {
			$open[] = array('kind' => $kind, 'state' => $deadline !== '' && $deadline < $today ? 'overdue' : ($deadline !== '' ? 'due' : 'open'),
				'title' => $title, 'deadline' => $deadline, 'url' => $url);
		};

		// Reports of new representatives to the authority (§ 14 (2) VerG).
		$sql = "SELECT r.rowid, r.deadline, d.firstname, d.lastname FROM ".MAIN_DB_PREFIX."vereine_function_report as r";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_function_term as t ON t.rowid = r.fk_term";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent as d ON d.rowid = t.fk_adherent";
		$sql .= " WHERE r.entity = ".$entity." AND r.reported_on IS NULL ORDER BY r.deadline";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$add('report', substr((string) $obj->deadline, 0, 10), $langs->transnoentities('VereineTodoReport', trim($obj->firstname.' '.$obj->lastname)),
				dol_buildpath('/vereine/functions.php', 1));
		}

		// What the calendar of duties holds for the year that ended: the account, its audit and everything
		// else the catalogue carries, each with the day it is due (#24). Done ones are gone from here.
		require_once __DIR__.'/vereineduties.class.php';
		$year = VereineAuditRules::lastEnded($today, getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1));
		$duties = new VereineDuties($this->db);
		foreach ($duties->plan($year, $today) as $entry) {
			if ($entry['state'] === 'done' || $entry['due'] === '') {
				continue;
			}
			$add('duty', $entry['due'], $entry['duty']['label'].' ('.VereineAuditRules::period($year, getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1))['label'].')',
				dol_buildpath('/vereine/duties.php', 1).'?year='.((int) $year));
		}

		// Tasks that sit with someone who no longer holds the function and wait for a word.
		foreach ($duties->handovers($today) as $entry) {
			$add('handover', $entry['due_on'], $langs->transnoentities('VereineTodoHandover', $entry['label'], $entry['to_name']),
				dol_buildpath('/vereine/duties.php', 1).'?year='.((int) $entry['fiscal_year']));
		}

		// Fee arrears at the last dunning step wait for the board; the list names nobody (#17).
		require_once __DIR__.'/vereinearrears.class.php';
		$arrearCount = count((new VereineArrears($this->db))->waiting());
		if ($arrearCount > 0) {
			$add('arrear', '', $langs->transnoentities('VereineTodoArrears', $arrearCount), dol_buildpath('/vereine/meetings.php', 1).'?template=board#vereinemeetingnew');
		}

		// Equipment that is late; the list names nobody (#26).
		require_once __DIR__.'/vereineloans.class.php';
		$lateLoans = (new VereineLoans($this->db))->overdue($today);
		if ($lateLoans > 0) {
			$add('loan', '', $langs->transnoentities('VereineTodoLoans', $lateLoans), dol_buildpath('/vereine/inventory.php', 1));
		}

		// Functions without a holder and terms of office that are over: an election is due.
		require_once __DIR__.'/vereinefunctionrules.class.php';
		$store = new VereineFunctions($this->db);
		$functions = $store->fetchAll(true);
		$labels = array();
		foreach ($functions as $function) {
			$labels[(int) $function['id']] = (string) $function['label'];
		}
		foreach (VereineFunctionRules::check($functions, $store->terms(), $today)['problems'] as $problem) {
			if (!in_array($problem['kind'], array(VereineFunctionRules::PROBLEM_MISSING, VereineFunctionRules::PROBLEM_ELECTION_DUE), true)) {
				continue;
			}
			$label = isset($labels[(int) $problem['function_id']]) ? $labels[(int) $problem['function_id']] : '';
			$add('election', '', $langs->transnoentities($problem['kind'] === VereineFunctionRules::PROBLEM_MISSING
				? 'VereineTodoFunctionMissing' : 'VereineTodoElection', $label), dol_buildpath('/vereine/functions.php', 1));
		}

		// What the next general assembly still needs, in the order of its way (#127).
		require_once __DIR__.'/vereineassembly.class.php';
		$assembly = new VereineAssembly($this->db);
		$next = $assembly->current($today);
		if ($next !== null) {
			$steps = $assembly->steps($next, $today);
			foreach (VereineAssemblyRules::open($steps['steps']) as $step) {
				$add('assembly', $step['deadline'], $langs->transnoentities('VereineTodoAssembly',
					$langs->transnoentitiesnoconv('VereineAssemblyStep_'.$step['code']), vereineFormatDay($next['day'])),
					dol_buildpath('/vereine/assembly.php', 1).'?id='.((int) $next['id']));
			}
		}

		// Invoices paid over their total that wait for a decision (#54), for whoever may read invoices.
		if (is_object($user) && $user->hasRight('facture', 'lire')) {
			require_once __DIR__.'/vereineoverpayments.class.php';
			$unassigned = (new VereineOverpayments($this->db))->listing(0, '', true);
			if ($unassigned) {
				$add('overpayment', '', $langs->transnoentities('VereineTodoOverpayments', count($unassigned),
					price(array_sum(array_column($unassigned, 'excess')), 0, $langs, 1, -1, 2).' €'), dol_buildpath('/vereine/overpayments.php', 1));
			}
		}

		// Applications that wait for a decision of the association.
		$sql = "SELECT COUNT(*) as waiting FROM ".MAIN_DB_PREFIX."vereine_application WHERE entity = ".$entity;
		$sql .= " AND status IN ('".VereineApplicationRules::RECEIVED."', '".VereineApplicationRules::IN_REVIEW."')";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if ($obj && (int) $obj->waiting > 0) {
			$add('application', '', $langs->transnoentities('VereineTodoApplications', (int) $obj->waiting), dol_buildpath('/vereine/applications.php', 1));
		}

		usort($open, function ($left, $right) {
			$order = array('overdue' => 0, 'due' => 1, 'open' => 2);
			if ($order[$left['state']] !== $order[$right['state']]) {
				return $order[$left['state']] - $order[$right['state']];
			}
			return strcmp($left['deadline'] !== '' ? $left['deadline'] : '9999', $right['deadline'] !== '' ? $right['deadline'] : '9999');
		});
		return $open;
	}

	/**
	 * What waits for one member: votes in circular resolutions, signatures and tasks.
	 *
	 * @param int $memberId Member the Dolibarr user is linked to
	 * @return array{votes:array<int,array<string,mixed>>,signatures:array<int,array<string,mixed>>,tasks:array<int,array<string,mixed>>}
	 */
	public function forMember($memberId)
	{
		global $conf;

		$waiting = array('votes' => array(), 'signatures' => array(), 'tasks' => array());
		if ((int) $memberId < 1) {
			return $waiting;
		}
		$entity = (int) $conf->entity;

		// Circular resolutions still open, where this member has not voted yet.
		$sql = "SELECT c.rowid, c.title, c.deadline FROM ".MAIN_DB_PREFIX."vereine_circular as c";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_circular_vote as v ON v.fk_circular = c.rowid AND v.entity = c.entity";
		$sql .= " WHERE c.entity = ".$entity." AND c.status = 'open' AND v.fk_adherent = ".((int) $memberId)." AND v.voted_at IS NULL ORDER BY c.deadline, c.rowid";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$waiting['votes'][] = array('id' => (int) $obj->rowid, 'title' => (string) $obj->title, 'deadline' => (string) $obj->deadline,
				'url' => dol_buildpath('/vereine/circulars.php', 1).'?id='.((int) $obj->rowid));
		}

		// Signature runs still open, where this member has not signed yet.
		$sql = "SELECT s.rowid, s.kind, s.fk_object, s.doc_name FROM ".MAIN_DB_PREFIX."vereine_signature as s";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_signature_person as p ON p.fk_signature = s.rowid";
		$sql .= " WHERE s.entity = ".$entity." AND s.status = 'open' AND p.fk_adherent = ".((int) $memberId)." AND p.signed_at IS NULL ORDER BY s.rowid";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$url = $this->signatureUrl((string) $obj->kind, (int) $obj->fk_object);
			if ((string) $obj->kind === 'minutes') {
				// A run of the minutes belongs to a version; the page to sign it is the meeting.
				$version = $this->db->query("SELECT fk_meeting FROM ".MAIN_DB_PREFIX."vereine_meeting_minutes WHERE rowid = ".((int) $obj->fk_object));
				$row = $version ? $this->db->fetch_object($version) : null;
				$url = dol_buildpath('/vereine/meetings.php', 1).($row ? '?id='.((int) $row->fk_meeting).'#vereinemeetingminutes' : '');
			}
			$waiting['signatures'][] = array('id' => (int) $obj->rowid, 'kind' => (string) $obj->kind, 'title' => (string) $obj->doc_name, 'url' => $url);
		}

		// Tasks from resolutions and meetings, not done yet.
		$sql = "SELECT t.rowid, t.label, t.deadline, t.fk_resolution, t.fk_meeting FROM ".MAIN_DB_PREFIX."vereine_resolution_task as t";
		$sql .= " WHERE t.entity = ".$entity." AND t.fk_adherent = ".((int) $memberId)." AND t.done_at IS NULL ORDER BY t.deadline IS NULL, t.deadline, t.rowid";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$url = (int) $obj->fk_resolution > 0 ? dol_buildpath('/vereine/resolutions.php', 1).'?id='.((int) $obj->fk_resolution)
				: dol_buildpath('/vereine/meetings.php', 1).'?id='.((int) $obj->fk_meeting);
			$waiting['tasks'][] = array('id' => (int) $obj->rowid, 'title' => (string) $obj->label, 'deadline' => (string) $obj->deadline, 'url' => $url);
		}
		return $waiting;
	}

	/**
	 * Where a document of a signature run is signed.
	 *
	 * @param string $kind     Kind of document
	 * @param int    $objectId What the run belongs to
	 * @return string
	 */
	private function signatureUrl($kind, $objectId)
	{
		if (in_array($kind, array('resolution', 'money'), true)) {
			return dol_buildpath('/vereine/resolutions.php', 1).'?id='.((int) $objectId).'#vereineresolutionpdf';
		}
		if ($kind === 'letter') {
			return dol_buildpath('/vereine/authority.php', 1);
		}
		if ($kind === 'payout') {
			return dol_buildpath('/vereine/volunteer.php', 1).'#vereinevolunteerpayouts';
		}
		if ($kind === 'account') {
			require_once __DIR__.'/vereineaccount.class.php';
			return dol_buildpath('/vereine/account.php', 1).'?year='.((new VereineAccount($this->db))->yearOf($objectId)).'#vereineaccountpdf';
		}
		if ($kind === 'audit_report') {
			require_once __DIR__.'/vereineaudit.class.php';
			return dol_buildpath('/vereine/audit.php', 1).'?year='.((new VereineAudit($this->db))->yearOf($objectId)).'#vereineauditreport';
		}
		return dol_buildpath('/vereine/meetings.php', 1);
	}
}
