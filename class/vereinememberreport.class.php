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
 * \file    class/vereinememberreport.class.php
 * \ingroup vereine
 * \brief   Summary of a member for a website: membership, fee and open invoices, nothing more.
 */

require_once __DIR__.'/vereinemembersummary.class.php';
require_once __DIR__.'/vereinefeediscountstore.class.php';
require_once __DIR__.'/vereinefeefamilystore.class.php';
require_once __DIR__.'/vereineexits.class.php';
require_once __DIR__.'/vereinefunctions.class.php';

/**
 * Reads what a website may show about a member.
 *
 * Birth date, address, phone, notes, bank data and dunning levels are never read here,
 * so they cannot slip into an answer.
 */
class VereineMemberReport
{
	/** Open invoices listed at most per member. */
	const MAX_OPEN_INVOICES = 50;

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
	 * Members of this entity with a member number.
	 *
	 * @param string $ref Member number (Dolibarr's reference of the member)
	 * @return int[]
	 */
	public function idsByRef($ref)
	{
		return $this->ids("d.ref = '".$this->db->escape(trim((string) $ref))."'");
	}

	/**
	 * Members of this entity with an e-mail address, ignoring case and surrounding spaces.
	 *
	 * @param string $email E-mail address
	 * @return int[]
	 */
	public function idsByEmail($email)
	{
		$email = strtolower(trim((string) $email));
		if ($email === '') {
			return array();
		}
		return $this->ids("LOWER(TRIM(d.email)) = '".$this->db->escape($email)."'");
	}

	/**
	 * The summary of a member.
	 *
	 * @param int $id Member id
	 * @return array<string,mixed>|null Null when this entity has no such member
	 */
	public function summary($id)
	{
		global $conf;

		$sql = "SELECT d.rowid, d.ref, d.firstname, d.lastname, d.societe, d.morphy, d.statut, d.datefin, d.datevalid, d.fk_soc,";
		$sql .= " t.rowid as type_id, t.libelle as type_label, t.subscription, t.amount,";
		$sql .= " (SELECT MIN(s.dateadh) FROM ".MAIN_DB_PREFIX."subscription as s WHERE s.fk_adherent = d.rowid) as first_period";
		$sql .= " FROM ".MAIN_DB_PREFIX."adherent as d";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent_type as t ON t.rowid = d.fk_adherent_type";
		$sql .= " WHERE d.rowid = ".((int) $id)." AND d.entity IN (".getEntity('adherent').")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return null;
		}
		$row = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$row) {
			return null;
		}

		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$status = VereineMemberSummary::status($row->statut);
		$ends = $this->periodEnds((int) $row->rowid, VereineMemberSummary::dayOf($row->datefin));
		$paidUntil = $ends['paid_until'];
		$validatedOn = VereineMemberSummary::datePart($row->datevalid);
		$required = (int) $row->subscription === 1;
		$fee = VereineMemberSummary::fee($status, $required, $paidUntil, $ends['invoiced_until'], $validatedOn, $today);
		$amount = ($row->amount === null || $row->amount === '') ? null : (float) $row->amount;
		$discount = array('kind' => 'none', 'reason' => '');
		if ($required && $status === VereineMemberSummary::STATUS_ACTIVE) {
			$discountStore = new VereineFeeDiscountStore($this->db);
			$memberData = $discountStore->memberData(array((int) $row->rowid));
			$discount = VereineFeeDiscounts::choose($discountStore->fetchAll(true),
				(isset($memberData[(int) $row->rowid]) ? $memberData[(int) $row->rowid] : array()) + array('type_id' => (int) $row->type_id),
				$fee['next_due'] !== '' ? $fee['next_due'] : $today);
			$amount = VereineFeeDiscounts::apply($amount, $discount);
		}
		$online = $this->onlinePayment();
		// A payer gets the fee invoices; paying the member's fee online would bypass them.
		$familyStore = new VereineFeeFamilyStore($this->db);
		$payerSocid = (int) $familyStore->payerOfMember((int) $row->rowid);
		$paidByOther = VereineFeeFamilies::paidByOther((int) $row->fk_soc, $payerSocid);
		$functionStore = new VereineFunctions($this->db);
		$exitStore = new VereineExits($this->db);
		$membershipEnds = '';
		foreach ($exitStore->fetchAll(array((int) $row->rowid)) as $exit) {
			if ($exit['status'] !== VereineExits::STATUS_CANCELLED) {
				$membershipEnds = $exit['last_day'];
				break;
			}
		}

		return array(
			'id' => (int) $row->rowid,
			'ref' => (string) $row->ref,
			'firstname' => (string) $row->firstname,
			'lastname' => (string) $row->lastname,
			'company' => $row->morphy === 'mor' ? (string) $row->societe : '',
			'type' => array('id' => (int) $row->type_id, 'label' => (string) $row->type_label),
			'status' => $status,
			'member_since' => VereineMemberSummary::memberSince($status, VereineMemberSummary::dayOf($row->first_period), $validatedOn),
			'paid_until' => $paidUntil,
			'membership_ends' => $membershipEnds,
			'functions' => $functionStore->memberFunctions((int) $row->rowid, $today),
			'currency' => (string) $conf->currency,
			'fee' => array(
				'required' => $required,
				'status' => $fee['status'],
				'next_due' => $fee['next_due'],
				'amount' => $required ? $amount : null,
				'discount' => array('kind' => $discount['kind'], 'label' => $discount['reason']),
				'payer' => $paidByOther ? 'other' : 'self',
				'payment_url' => ($online && !$paidByOther && $fee['status'] === VereineMemberSummary::FEE_DUE && (string) $row->ref !== '' && $amount !== 0.0)
					? getOnlinePaymentUrl(0, 'member', (string) $row->ref, $amount === null ? 0 : $amount) : '',
				// How the fee is collected: the state of the mandate, never bank data (#125).
				'mandate' => $this->mandate((int) ($paidByOther ? $payerSocid : $row->fk_soc), $today),
			),
			'open_invoices' => (int) $row->fk_soc > 0 ? $this->invoices((int) $row->fk_soc, $today, $online, true, self::MAX_OPEN_INVOICES, 0) : array(),
			'updated_at' => VereineMemberSummary::isoMoment(current($this->changes((int) $row->rowid))),
		);
	}

	/**
	 * The state of the SEPA mandate of the payer: enough for a website to say how the fee is collected,
	 * and nothing more - no IBAN, no name of the bank (#125).
	 *
	 * @param int    $socid Third party that pays
	 * @param string $today Today, YYYY-MM-DD
	 * @return array{status:string,signed_on:string}
	 */
	private function mandate($socid, $today)
	{
		require_once __DIR__.'/vereinesepastore.class.php';

		if ($socid <= 0 || !VereineSepaStore::enabled()) {
			return array('status' => 'off', 'signed_on' => '');
		}
		$store = new VereineSepaStore($this->db);
		$mandates = $store->mandates(array($socid), $today);
		if (!isset($mandates[$socid])) {
			return array('status' => VereineSepa::MANDATE_NONE, 'signed_on' => '');
		}
		return array('status' => $mandates[$socid]['status'], 'signed_on' => $mandates[$socid]['signed_on']);
	}

	/**
	 * Paid and invoiced ends of a member's subscription periods, see VereineMemberSummary::periodEnds().
	 *
	 * @param int    $memberId  Member id
	 * @param string $memberEnd Dolibarr's end date of the member, used when it has no subscription periods (imported data)
	 * @return array{paid_until:string,invoiced_until:string}
	 */
	private function periodEnds($memberId, $memberEnd)
	{
		$sql = "SELECT s.datef, f.fk_statut FROM ".MAIN_DB_PREFIX."subscription as s";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."element_element as ee ON ee.fk_source = s.rowid AND ee.sourcetype = 'subscription' AND ee.targettype = 'facture'";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = ee.fk_target";
		$sql .= " WHERE s.fk_adherent = ".((int) $memberId);
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return array('paid_until' => $memberEnd, 'invoiced_until' => '');
		}
		$periods = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$periods[] = array('end' => VereineMemberSummary::dayOf($obj->datef), 'invoice_status' => $obj->fk_statut === null ? null : (int) $obj->fk_statut);
		}
		$this->db->free($resql);
		return $periods ? VereineMemberSummary::periodEnds($periods) : array('paid_until' => $memberEnd, 'invoiced_until' => '');
	}

	/**
	 * Summaries of the members of this entity for a website sync, by id.
	 *
	 * @param int|null $since Only members whose summary changed at or after this moment, null for all
	 * @param int      $limit Members per page
	 * @param int      $page  Page, starting at 0
	 * @return array<int,array<string,mixed>>
	 */
	public function members($since, $limit, $page)
	{
		if ($since === null) {
			$ids = $this->ids('1 = 1', (int) $limit, (int) $limit * (int) $page);
		} else {
			$ids = array();
			foreach ($this->changes(0) as $id => $moment) {
				if ($moment >= (int) $since) {
					$ids[] = $id;
				}
			}
			$ids = array_slice($ids, (int) $limit * (int) $page, (int) $limit);
		}
		$members = array();
		foreach ($ids as $id) {
			$summary = $this->summary($id);
			if ($summary !== null) {
				$members[] = $summary;
			}
		}
		return $members;
	}

	/**
	 * When the summaries of members last changed.
	 *
	 * Counts changes of the member and its extra fields, its member type, its subscription periods, the invoices
	 * of its third party and payments on them, the fee invoices linked to its periods and
	 * payments on them (a payer's invoice for a family), and the days on which a fee becomes
	 * due or an open invoice overdue by the date alone.
	 *
	 * @param int $id One member, or 0 for every member of this entity
	 * @return array<int,int> Unix timestamp per member id, ordered by id
	 */
	private function changes($id)
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

		$now = dol_now();
		$today = dol_print_date($now, '%Y-%m-%d', 'tzserver');
		$entities = getEntity('invoice');
		$open = "f.fk_statut = ".((int) Facture::STATUS_VALIDATED)." AND f.paye = 0";
		$open .= " AND f.type IN (".((int) Facture::TYPE_STANDARD).", ".((int) Facture::TYPE_REPLACEMENT).", ".((int) Facture::TYPE_DEPOSIT).")";

		$sql = "SELECT d.rowid, d.statut, d.datefin, d.tms, t.subscription, t.tms as type_tms,";
		// The extra fields row has its own tms: it tells when exemption, proof or payer changed.
		$sql .= " (SELECT MAX(e.tms) FROM ".MAIN_DB_PREFIX."adherent_extrafields as e WHERE e.fk_object = d.rowid) as fields_tms,";
		$sql .= " (SELECT MAX(s.tms) FROM ".MAIN_DB_PREFIX."subscription as s WHERE s.fk_adherent = d.rowid) as subscription_tms,";
		$sql .= " (SELECT MAX(f.tms) FROM ".MAIN_DB_PREFIX."facture as f WHERE f.fk_soc = d.fk_soc AND f.entity IN (".$entities.")";
		$sql .= " AND f.fk_statut <> ".((int) Facture::STATUS_DRAFT).") as invoice_tms,";
		$sql .= " (SELECT MAX(p.tms) FROM ".MAIN_DB_PREFIX."paiement as p";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."paiement_facture as pf ON pf.fk_paiement = p.rowid";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = pf.fk_facture";
		$sql .= " WHERE f.fk_soc = d.fk_soc AND f.entity IN (".$entities.")) as payment_tms,";
		$fees = " FROM ".MAIN_DB_PREFIX."subscription as s";
		$fees .= " INNER JOIN ".MAIN_DB_PREFIX."element_element as ee ON ee.fk_source = s.rowid AND ee.sourcetype = 'subscription' AND ee.targettype = 'facture'";
		$sql .= " (SELECT MAX(f.tms)".$fees." INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = ee.fk_target WHERE s.fk_adherent = d.rowid) as fee_invoice_tms,";
		$sql .= " (SELECT MAX(p.tms)".$fees." INNER JOIN ".MAIN_DB_PREFIX."paiement_facture as pf ON pf.fk_facture = ee.fk_target";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."paiement as p ON p.rowid = pf.fk_paiement WHERE s.fk_adherent = d.rowid) as fee_payment_tms,";
		// The exit table exists only after the module was enabled with 0.3.9.
		$exitTable = (bool) $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_member_exit WHERE 1 = 0");
		$sql .= $exitTable ? " (SELECT MAX(x.tms) FROM ".MAIN_DB_PREFIX."vereine_member_exit as x WHERE x.fk_adherent = d.rowid) as exit_tms," : " NULL as exit_tms,";
		// The function tables exist only after the module was enabled with 0.4.0.
		$termTable = (bool) $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_function_term WHERE 1 = 0");
		$sql .= $termTable ? " (SELECT MAX(ft.tms) FROM ".MAIN_DB_PREFIX."vereine_function_term as ft WHERE ft.fk_adherent = d.rowid) as term_tms," : " NULL as term_tms,";
		$sql .= " (SELECT MAX(f.date_lim_reglement) FROM ".MAIN_DB_PREFIX."facture as f WHERE f.fk_soc = d.fk_soc AND f.entity IN (".$entities.")";
		$sql .= " AND ".$open." AND f.date_lim_reglement < '".$this->db->escape($today)."') as last_due";
		$sql .= " FROM ".MAIN_DB_PREFIX."adherent as d";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent_type as t ON t.rowid = d.fk_adherent_type";
		$sql .= " WHERE d.entity IN (".getEntity('adherent').")";
		if ($id > 0) {
			$sql .= " AND d.rowid = ".((int) $id);
		}
		$sql .= " ORDER BY d.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$this->db->free($resql);

		$offset = $rows ? $this->databaseClockOffset($now) : 0;
		$changes = array();
		foreach ($rows as $obj) {
			$moments = array();
			foreach (array('tms', 'fields_tms', 'type_tms', 'subscription_tms', 'invoice_tms', 'payment_tms', 'fee_invoice_tms', 'fee_payment_tms', 'exit_tms', 'term_tms') as $field) {
				$moments[] = $obj->$field ? (int) $this->db->jdate($obj->$field) - $offset : 0;
			}
			$days = VereineMemberSummary::changeDays(VereineMemberSummary::status($obj->statut), (int) $obj->subscription === 1,
				VereineMemberSummary::dayOf($obj->datefin), VereineMemberSummary::dayOf($obj->last_due), $today);
			foreach ($days as $day) {
				$moments[] = (int) dol_mktime(0, 0, 0, (int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4), 'tzserver');
			}
			$changes[(int) $obj->rowid] = VereineMemberSummary::latestMoment($moments, $now);
		}
		return $changes;
	}

	/**
	 * Seconds the moments the database fills in itself read off, see VereineMemberSummary::clockOffset().
	 *
	 * @param int $now PHP's current time
	 * @return int
	 */
	private function databaseClockOffset($now)
	{
		$resql = $this->db->query("SELECT CURRENT_TIMESTAMP as dbnow");
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return 0;
		}
		$this->db->free($resql);
		return VereineMemberSummary::clockOffset((int) $this->db->jdate($obj->dbnow), $now);
	}

	/**
	 * All validated invoices of a member, newest first.
	 *
	 * @param int $id    Member id
	 * @param int $limit Invoices per page
	 * @param int $page  Page, starting at 0
	 * @return array<int,array<string,mixed>>|null Null when this entity has no such member
	 */
	public function memberInvoices($id, $limit, $page)
	{
		$socid = $this->thirdPartyOf($id);
		if ($socid === null) {
			return null;
		}
		if ($socid === 0) {
			return array();
		}
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		return $this->invoices($socid, $today, $this->onlinePayment(), false, (int) $limit, (int) $limit * (int) $page);
	}

	/**
	 * The PDF of a member's invoice, built the way Dolibarr builds it when it is missing.
	 *
	 * @param int $id        Member id
	 * @param int $invoiceId Invoice id
	 * @return array{filename:string,content_type:string,filesize:int,sha256:string,content:string}|false|null
	 *         Null when the member or a validated invoice of the member does not exist, false when the PDF cannot be built
	 */
	public function invoicePdf($id, $invoiceId)
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

		$socid = $this->thirdPartyOf($id);
		if (!$socid) {
			return null;
		}
		$invoice = new Facture($this->db);
		if ($invoice->fetch((int) $invoiceId) <= 0 || (int) $invoice->socid !== $socid || (int) $invoice->status === Facture::STATUS_DRAFT
			|| !in_array((int) $invoice->entity, array_map('intval', explode(',', getEntity('invoice'))), true)) {
			return null;
		}

		$reference = dol_sanitizeFileName($invoice->ref);
		$file = $conf->facture->multidir_output[$invoice->entity].'/'.$reference.'/'.$reference.'.pdf';
		if (!is_file($file)) {
			$outputlangs = $langs;
			$invoice->fetch_thirdparty();
			if (getDolGlobalInt('MAIN_MULTILANGS') && !empty($invoice->thirdparty->default_lang)) {
				$outputlangs = new Translate('', $conf);
				$outputlangs->setDefaultLang($invoice->thirdparty->default_lang);
			}
			if ($invoice->generateDocument('', $outputlangs) <= 0 || !is_file($file)) {
				dol_syslog(__METHOD__.' building the PDF of '.$invoice->ref.' failed: '.$invoice->error, LOG_ERR);
				return false;
			}
		}
		$content = file_get_contents($file);
		if ($content === false) {
			return false;
		}
		return array(
			'filename' => $reference.'.pdf',
			'content_type' => 'application/pdf',
			'filesize' => strlen($content),
			'sha256' => hash('sha256', $content),
			'content' => base64_encode($content),
		);
	}

	/**
	 * Validated invoices of a third party: the open ones oldest first, or all of them newest first.
	 *
	 * @param int    $socid    Third party of the member
	 * @param string $today    Today, YYYY-MM-DD
	 * @param bool   $online   Whether an online payment service is set up
	 * @param bool   $openOnly Only unpaid standard, replacement and deposit invoices
	 * @param int    $limit    At most this many
	 * @param int    $offset   Skip this many
	 * @return array<int,array<string,mixed>>
	 */
	private function invoices($socid, $today, $online, $openOnly, $limit, $offset)
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

		$sql = "SELECT f.rowid, f.datef, f.date_lim_reglement FROM ".MAIN_DB_PREFIX."facture as f";
		$sql .= " WHERE f.fk_soc = ".((int) $socid)." AND f.entity IN (".getEntity('invoice').")";
		if ($openOnly) {
			$sql .= " AND f.fk_statut = ".((int) Facture::STATUS_VALIDATED)." AND f.paye = 0";
			$sql .= " AND f.type IN (".((int) Facture::TYPE_STANDARD).", ".((int) Facture::TYPE_REPLACEMENT).", ".((int) Facture::TYPE_DEPOSIT).")";
			$sql .= " ORDER BY f.datef, f.rowid";
		} else {
			$sql .= " AND f.fk_statut IN (".((int) Facture::STATUS_VALIDATED).", ".((int) Facture::STATUS_CLOSED).", ".((int) Facture::STATUS_ABANDONED).")";
			$sql .= " AND f.type IN (".implode(', ', array_keys(VereineMemberSummary::INVOICE_TYPES)).")";
			$sql .= " ORDER BY f.datef DESC, f.rowid DESC";
		}
		$sql .= $this->db->plimit((int) $limit, (int) $offset);
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$this->db->free($resql);

		$fees = $this->feeInvoiceIds(array_map(function ($obj) {
			return (int) $obj->rowid;
		}, $rows));
		$invoices = array();
		foreach ($rows as $obj) {
			$invoice = new Facture($this->db);
			if ($invoice->fetch((int) $obj->rowid) <= 0) {
				continue;
			}
			$dueDate = VereineMemberSummary::dayOf($obj->date_lim_reglement);
			$status = VereineMemberSummary::invoiceStatus($invoice->status, $dueDate, $today);
			$open = in_array($status, array(VereineMemberSummary::INVOICE_OPEN, VereineMemberSummary::INVOICE_OVERDUE), true);
			$type = VereineMemberSummary::invoiceType($invoice->type);
			$invoices[] = array(
				'id' => (int) $invoice->id,
				'ref' => (string) $invoice->ref,
				'type' => $type,
				'date' => VereineMemberSummary::dayOf($obj->datef),
				'due_date' => $dueDate,
				'total' => (float) price2num($invoice->total_ttc, 'MT'),
				'remaining' => $open ? (float) $invoice->getRemainToPay(0) : 0.0,
				'status' => $status,
				'overdue' => $status === VereineMemberSummary::INVOICE_OVERDUE,
				'payment_url' => ($online && $open && $type !== 'credit_note') ? getOnlinePaymentUrl(0, 'invoice', (string) $invoice->ref) : '',
				'fee' => isset($fees[(int) $invoice->id]),
			);
		}
		return $invoices;
	}

	/**
	 * Invoices linked to a subscription period, as Dolibarr links a fee invoice.
	 *
	 * @param int[] $invoiceIds Invoice ids
	 * @return array<int,bool> Fee invoice ids as keys
	 */
	private function feeInvoiceIds(array $invoiceIds)
	{
		$invoiceIds = array_values(array_filter(array_map('intval', $invoiceIds)));
		if (!$invoiceIds) {
			return array();
		}
		$sql = "SELECT DISTINCT ee.fk_target FROM ".MAIN_DB_PREFIX."element_element as ee";
		$sql .= " WHERE ee.sourcetype = 'subscription' AND ee.targettype = 'facture' AND ee.fk_target IN (".implode(', ', $invoiceIds).")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return array();
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[(int) $obj->fk_target] = true;
		}
		$this->db->free($resql);
		return $ids;
	}

	/**
	 * The third party of a member of this entity.
	 *
	 * @param int $id Member id
	 * @return int|null Third party id, 0 when the member has none, null when there is no such member
	 */
	private function thirdPartyOf($id)
	{
		$sql = "SELECT d.fk_soc FROM ".MAIN_DB_PREFIX."adherent as d";
		$sql .= " WHERE d.rowid = ".((int) $id)." AND d.entity IN (".getEntity('adherent').")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? (int) $obj->fk_soc : null;
	}

	/**
	 * Whether Dolibarr has an online payment service (Stripe, PayPal or one added by a module).
	 *
	 * @return bool
	 */
	private function onlinePayment()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';

		return count(getValidOnlinePaymentMethods()) > 0;
	}

	/**
	 * Member ids of this entity matching a condition.
	 *
	 * @param string $condition SQL condition on the member table d, escaped by the caller
	 * @param int    $limit     At most this many, 0 for all
	 * @param int    $offset    Skip this many
	 * @return int[]
	 */
	private function ids($condition, $limit = 0, $offset = 0)
	{
		$sql = "SELECT d.rowid FROM ".MAIN_DB_PREFIX."adherent as d";
		$sql .= " WHERE ".$condition." AND d.entity IN (".getEntity('adherent').")";
		$sql .= " ORDER BY d.rowid";
		if ($limit > 0) {
			$sql .= $this->db->plimit((int) $limit, (int) $offset);
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return array();
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
		$this->db->free($resql);
		return $ids;
	}
}
