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
 * \file    class/vereineaccount.class.php
 * \ingroup vereine
 * \brief   The income and expenditure account with the statement of assets of an association's year (#8).
 *
 * Read from Dolibarr: the bookings of the bank and cash accounts, the payments behind them, the areas of
 * the invoice lines (tax profiles) and of the supplier lines (area of the expense), open invoices at the
 * end of the year. The module writes only its own record: the day the account was made, further assets
 * and debts, a note.
 */

require_once __DIR__.'/vereineaccountrules.class.php';
require_once __DIR__.'/vereineauditrules.class.php';
require_once __DIR__.'/vereineorganization.class.php';
require_once __DIR__.'/vereinepdf.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * The income and expenditure account.
 */
class VereineAccount
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
	 * Path of the PDF of an account.
	 *
	 * @param int $id Record of the account
	 * @return string
	 */
	public static function pdfPath($id)
	{
		global $conf;

		return DOL_DATA_ROOT.($conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/vereine/account/einnahmen-ausgaben-'.((int) $id).'.pdf';
	}

	/**
	 * A label as Dolibarr shows it: some are stored as language keys, such as the cash box of the point of
	 * sale ("DefaultCashPOSLabel") or payments ("(CustomerInvoicePayment)").
	 *
	 * @param string $text Label as stored
	 * @return string
	 */
	public static function text($text)
	{
		global $langs;

		$text = (string) $text;
		if (preg_match('/^\(([A-Za-z][A-Za-z0-9_]+)\)$/', $text, $parts)) {
			$key = $parts[1];
		} elseif (preg_match('/^[A-Za-z][A-Za-z0-9_]+$/', $text)) {
			$key = $text;
		} else {
			return $text;
		}
		if (!is_object($langs)) {
			return $text;
		}
		$langs->loadLangs(array('banks', 'bills', 'compta', 'cashdesk', 'vereine@vereine'));
		$translated = $langs->transnoentitiesnoconv($key);
		if ($translated !== $key && $translated !== '') {
			return $translated;
		}
		// Dolibarr translates the name of the point of sale's cash box only from version 24 on.
		$own = $langs->transnoentitiesnoconv('VereineBankLabel_'.$key);
		return $own !== 'VereineBankLabel_'.$key && $own !== '' ? $own : $text;
	}

	/**
	 * First and last day of an association's year.
	 *
	 * @param int $year Year it starts in
	 * @return array{year:int,start:string,end:string,label:string}
	 */
	public static function period($year)
	{
		return VereineAuditRules::period((int) $year, getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1));
	}

	/**
	 * The record of a year; created on first use, so its PDF can have a signature run.
	 *
	 * @param int  $year Year
	 * @param User $user Who opens it
	 * @return array{id:int,year:int,made_on:string,extras:array<int,array<string,mixed>>,note:string}|null
	 */
	public function fetch($year, $user)
	{
		global $conf;

		$sql = "SELECT rowid, fiscal_year, made_on, extras, note FROM ".MAIN_DB_PREFIX."vereine_account WHERE entity = ".((int) $conf->entity)." AND fiscal_year = ".((int) $year);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_account (entity, fiscal_year, datec, fk_user_modif) VALUES (".((int) $conf->entity).", ".((int) $year).",";
			$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return null;
			}
			return $this->fetch($year, $user);
		}
		return array('id' => (int) $obj->rowid, 'year' => (int) $obj->fiscal_year, 'made_on' => (string) $obj->made_on,
			'extras' => VereineAccountRules::extras(json_decode((string) $obj->extras, true)), 'note' => (string) $obj->note);
	}

	/**
	 * The year a record belongs to.
	 *
	 * @param int $id Record
	 * @return int 0 when unknown
	 */
	public function yearOf($id)
	{
		global $conf;

		$resql = $this->db->query("SELECT fiscal_year FROM ".MAIN_DB_PREFIX."vereine_account WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? (int) $obj->fiscal_year : 0;
	}

	/**
	 * Store the day the account was made, further assets and debts, and a note.
	 *
	 * @param int                 $year    Year
	 * @param array<string,mixed> $entered made_on, extras, note
	 * @param User                $user    Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function save($year, array $entered, $user)
	{
		$record = $this->fetch($year, $user);
		if ($record === null) {
			return -1;
		}
		$this->errors = array();
		$made = isset($entered['made_on']) ? trim((string) $entered['made_on']) : '';
		if ($made !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $made)) {
			$this->errors[] = 'VereineAuditErrorDay';
			return 0;
		}
		$extras = VereineAccountRules::extras(isset($entered['extras']) ? $entered['extras'] : array());
		$note = isset($entered['note']) ? mb_substr(trim((string) $entered['note']), 0, 2000, 'UTF-8') : '';
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_account SET made_on = ".($made !== '' ? "'".$this->db->escape($made)."'" : "NULL").",";
		$sql .= " extras = '".$this->db->escape((string) json_encode($extras))."', note = '".$this->db->escape($note)."', fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $record['id']);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::ACCOUNT_SAVED, 0, 0, $year.': '.($made !== '' ? 'made on '.$made : 'not made yet'));
		return 1;
	}

	/**
	 * The bookings of the bank and cash accounts in a period, each with its kind and its parts by area.
	 *
	 * @param array{start:string,end:string} $period Period
	 * @return array<int,array{id:int,date:string,account:string,label:string,amount:float,kind:string,parts:array<string,float>}>
	 */
	public function bookings(array $period)
	{
		$sql = "SELECT b.rowid, b.dateo, b.amount, b.label, b.fk_type, a.label as account FROM ".MAIN_DB_PREFIX."bank as b";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account as a ON a.rowid = b.fk_account WHERE a.entity IN (".getEntity('bank_account').")";
		$sql .= " AND b.dateo BETWEEN '".$this->db->escape($period['start'])."' AND '".$this->db->escape($period['end'])."' ORDER BY b.dateo, b.rowid";
		$bookings = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$bookings[(int) $obj->rowid] = array('id' => (int) $obj->rowid, 'date' => (string) $obj->dateo, 'account' => self::text($obj->account),
				'label' => self::text($obj->label), 'amount' => round((float) $obj->amount, 2),
				// Dolibarr books the balance an account starts with as type SOLD, without a link.
				'links' => $obj->fk_type === 'SOLD' ? array(array('type' => 'initial', 'id' => 0)) : array());
		}
		if ($bookings) {
			$resql = $this->db->query("SELECT fk_bank, type, url_id FROM ".MAIN_DB_PREFIX."bank_url WHERE fk_bank IN (".implode(',', array_keys($bookings)).")");
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$bookings[(int) $obj->fk_bank]['links'][] = array('type' => (string) $obj->type, 'id' => (int) $obj->url_id);
			}
		}
		$chosen = $this->chosenAreas(array_keys($bookings));
		$result = array();
		foreach ($bookings as $booking) {
			$kind = VereineAccountRules::kindOf(array_column($booking['links'], 'type'));
			$area = VereineAccountRules::assignable($kind) && isset($chosen[$booking['id']]) ? $chosen[$booking['id']] : '';
			if ($kind === VereineAccountRules::KIND_INVOICE || $kind === VereineAccountRules::KIND_SUPPLIER) {
				$parts = $this->paymentParts($booking, $kind);
			} else {
				$parts = array($area !== '' ? $area : VereineAccountRules::sphereOf($kind) => $booking['amount']);
			}
			unset($booking['links']);
			$result[] = $booking + array('kind' => $kind, 'parts' => $parts, 'area' => $area);
		}
		return $result;
	}

	/**
	 * The areas the board chose for bookings.
	 *
	 * @param int[] $ids Bank lines
	 * @return array<int,string> Bank line => area
	 */
	private function chosenAreas(array $ids)
	{
		$chosen = array();
		if (!$ids) {
			return $chosen;
		}
		$resql = $this->db->query("SELECT fk_bank, sphere FROM ".MAIN_DB_PREFIX."vereine_account_line WHERE fk_bank IN (".implode(',', array_map('intval', $ids)).")");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			if (in_array((string) $obj->sphere, VereineAccountRules::areas(), true)) {
				$chosen[(int) $obj->fk_bank] = (string) $obj->sphere;
			}
		}
		return $chosen;
	}

	/**
	 * Store the areas chosen for bookings of a year without invoice lines behind them.
	 *
	 * @param int   $year    Year
	 * @param mixed $entered Bank line => area, empty to remove
	 * @param User  $user    Who chooses
	 * @return int Number of bookings stored, -1 on error
	 */
	public function assign($year, $entered, $user)
	{
		global $conf;

		$allowed = array();
		foreach ($this->bookings(self::period($year)) as $booking) {
			if (VereineAccountRules::assignable($booking['kind'])) {
				$allowed[] = $booking['id'];
			}
		}
		$changes = VereineAccountRules::assignments($entered, $allowed);
		$this->db->begin();
		foreach ($changes as $bank => $area) {
			$ok = $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_account_line WHERE fk_bank = ".((int) $bank));
			if ($ok && $area !== '') {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_account_line (entity, fk_bank, sphere, fk_user_modif)";
				$sql .= " VALUES (".((int) $conf->entity).", ".((int) $bank).", '".$this->db->escape($area)."', ".((int) $user->id).")";
				$ok = $this->db->query($sql);
			}
			if (!$ok) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		$given = count(array_filter($changes, 'strlen'));
		VereineLog::add($this->db, $user, VereineLog::ACCOUNT_ASSIGNED, 0, 0, $year.': '.$given.' of '.count($changes).' bookings with an area');
		return count($changes);
	}

	/**
	 * The parts by area of a payment: over the invoices it paid and their lines; what is left is unassigned.
	 *
	 * @param array<string,mixed> $booking Booking with its links
	 * @param string              $kind    invoice or supplier
	 * @return array<string,float>
	 */
	private function paymentParts(array $booking, $kind)
	{
		$supplier = $kind === VereineAccountRules::KIND_SUPPLIER;
		$type = $supplier ? 'payment_supplier' : 'payment';
		$payments = array();
		foreach ($booking['links'] as $link) {
			if ($link['type'] === $type && $link['id'] > 0) {
				$payments[] = $link['id'];
			}
		}
		$parts = array();
		$allocated = 0.0;
		if ($payments) {
			if ($supplier) {
				$sql = "SELECT pf.fk_facturefourn as invoice, pf.amount FROM ".MAIN_DB_PREFIX."paiementfourn_facturefourn as pf WHERE pf.fk_paiementfourn IN (".implode(',', $payments).")";
			} else {
				$sql = "SELECT pf.fk_facture as invoice, pf.amount, pf.fk_paiement as payment FROM ".MAIN_DB_PREFIX."paiement_facture as pf WHERE pf.fk_paiement IN (".implode(',', $payments).")";
			}
			$resql = $this->db->query($sql);
			$allocations = array();
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				// A supplier payment leaves the bank: its parts carry the sign of the booking.
				$allocations[] = array('invoice' => (int) $obj->invoice, 'amount' => $supplier ? -(float) $obj->amount : (float) $obj->amount,
					'payment' => $supplier ? 0 : (int) $obj->payment);
			}
			// An excess that became a donation (#54) is no longer part of the invoice: it counts as a donation.
			$donations = array();
			if (!$supplier && $allocations) {
				require_once __DIR__.'/vereineoverpayments.class.php';
				$donations = (new VereineOverpayments($this->db))->donations(array_column($allocations, 'invoice'));
			}
			foreach ($allocations as $allocation) {
				$donation = isset($donations[$allocation['invoice']]) && $donations[$allocation['invoice']]['payment'] === $allocation['payment']
					? $donations[$allocation['invoice']]['amount'] : 0.0;
				$carved = $donation > 0 ? VereineOverpaymentRules::splitDonation($allocation['amount'], $donation)
					: array('invoice' => $allocation['amount'], 'donation' => 0.0);
				$shares = VereineAccountRules::split($carved['invoice'], $this->invoiceLines($allocation['invoice'], $supplier));
				if ($carved['donation'] > 0) {
					$area = VereineAccountRules::sphereOf(VereineAccountRules::KIND_DONATION);
					$shares[$area] = round((isset($shares[$area]) ? $shares[$area] : 0.0) + $carved['donation'], 2);
				}
				foreach ($shares as $sphere => $part) {
					$parts[$sphere] = round((isset($parts[$sphere]) ? $parts[$sphere] : 0.0) + $part, 2);
				}
				$allocated += $allocation['amount'];
			}
		}
		$rest = round($booking['amount'] - $allocated, 2);
		if (abs($rest) >= 0.01) {
			$parts[VereineAccountRules::UNASSIGNED] = round((isset($parts[VereineAccountRules::UNASSIGNED]) ? $parts[VereineAccountRules::UNASSIGNED] : 0.0) + $rest, 2);
		}
		return $parts;
	}

	/**
	 * Lines of an invoice with their area and total.
	 *
	 * @param int  $invoiceId Invoice
	 * @param bool $supplier  Whether it is a supplier invoice
	 * @return array<int,array{sphere:string,total:float}>
	 */
	private function invoiceLines($invoiceId, $supplier)
	{
		if ($supplier) {
			$sql = "SELECT d.total_ttc, e.vereine_expense_sphere as sphere FROM ".MAIN_DB_PREFIX."facture_fourn_det as d";
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture_fourn_det_extrafields as e ON e.fk_object = d.rowid WHERE d.fk_facture_fourn = ".((int) $invoiceId);
		} else {
			$sql = "SELECT d.total_ttc, t.sphere FROM ".MAIN_DB_PREFIX."facturedet as d";
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facturedet_extrafields as e ON e.fk_object = d.rowid";
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."vereine_taxprofile as t ON t.rowid = e.vereine_taxprofile WHERE d.fk_facture = ".((int) $invoiceId);
		}
		$sql .= " ORDER BY d.rowid";
		$lines = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$lines[] = array('sphere' => (string) $obj->sphere, 'total' => (float) $obj->total_ttc);
		}
		return $lines;
	}

	/**
	 * Balance of every bank and cash account of the association at the end of a day.
	 *
	 * @param string $day Day, YYYY-MM-DD
	 * @return array{total:float,accounts:array<int,array{label:string,balance:float}>}
	 */
	public function balances($day)
	{
		$sql = "SELECT a.rowid, a.label, SUM(b.amount) as balance FROM ".MAIN_DB_PREFIX."bank_account as a";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank as b ON b.fk_account = a.rowid AND b.dateo <= '".$this->db->escape($day)."'";
		$sql .= " WHERE a.entity IN (".getEntity('bank_account').") GROUP BY a.rowid, a.label ORDER BY a.label";
		$accounts = array();
		$total = 0.0;
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$balance = round((float) $obj->balance, 2);
			$accounts[] = array('label' => self::text($obj->label), 'balance' => $balance);
			$total = round($total + $balance, 2);
		}
		return array('total' => $total, 'accounts' => $accounts);
	}

	/**
	 * Invoices still open at the end of a day: what is not paid by then.
	 *
	 * @param string $day      Day, YYYY-MM-DD
	 * @param bool   $supplier Supplier invoices instead of customer invoices
	 * @return array{total:float,invoices:array<int,array{ref:string,party:string,date:string,open:float}>}
	 */
	public function open($day, $supplier)
	{
		$end = $this->db->escape($day)." 23:59:59";
		if ($supplier) {
			$sql = "SELECT f.rowid, f.ref, f.ref_supplier as extra, f.datef, f.total_ttc, s.nom, (SELECT COALESCE(SUM(pf.amount), 0) FROM ".MAIN_DB_PREFIX."paiementfourn_facturefourn as pf";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."paiementfourn as p ON p.rowid = pf.fk_paiementfourn WHERE pf.fk_facturefourn = f.rowid AND p.datep <= '".$end."') as paid";
			$sql .= " FROM ".MAIN_DB_PREFIX."facture_fourn as f LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc WHERE f.entity IN (".getEntity('supplier_invoice').")";
		} else {
			$sql = "SELECT f.rowid, f.ref, '' as extra, f.datef, f.total_ttc, s.nom, (SELECT COALESCE(SUM(pf.amount), 0) FROM ".MAIN_DB_PREFIX."paiement_facture as pf";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."paiement as p ON p.rowid = pf.fk_paiement WHERE pf.fk_facture = f.rowid AND p.datep <= '".$end."') as paid";
			$sql .= " FROM ".MAIN_DB_PREFIX."facture as f LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc WHERE f.entity IN (".getEntity('invoice').")";
		}
		// Open at the end of the day: validated, or closed only later; an abandoned invoice is not open.
		$sql .= " AND f.datef <= '".$this->db->escape($day)."' AND (f.fk_statut = 1 OR (f.fk_statut = 2 AND f.date_closing > '".$end."')) ORDER BY f.datef, f.rowid";
		$invoices = array();
		$total = 0.0;
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$open = round((float) $obj->total_ttc - (float) $obj->paid, 2);
			if (abs($open) < 0.01) {
				continue;
			}
			$invoices[] = array('ref' => trim($obj->ref.' '.$obj->extra), 'party' => (string) $obj->nom, 'date' => (string) $obj->datef, 'open' => $open);
			$total = round($total + $open, 2);
		}
		return array('total' => $total, 'invoices' => $invoices);
	}

	/**
	 * The whole account of a year: bookings, totals by area, the check against the bank, the assets at the end.
	 *
	 * @param int  $year Year
	 * @param User $user Who reads it
	 * @return array<string,mixed>
	 */
	public function build($year, $user)
	{
		$period = self::period($year);
		$record = $this->fetch($year, $user);
		$bookings = $this->bookings($period);
		$totals = VereineAccountRules::totals($bookings);
		$opening = $this->balances(VereineAccountRules::dayBefore($period['start']));
		// An account opened during the year brings its initial balance: part of the opening, no income.
		foreach ($bookings as $booking) {
			if ($booking['kind'] === VereineAccountRules::KIND_OPENING) {
				$opening['total'] = round($opening['total'] + $booking['amount'], 2);
			}
		}
		$closing = $this->balances($period['end']);
		$receivables = $this->open($period['end'], false);
		$payables = $this->open($period['end'], true);
		$extras = $record !== null ? $record['extras'] : array();
		$net = $closing['total'] + $receivables['total'] - $payables['total'];
		foreach ($extras as $extra) {
			$net += $extra['kind'] === 'debt' ? -$extra['amount'] : $extra['amount'];
		}
		$previous = VereineAccountRules::totals($this->bookings(self::period($year - 1)))['totals'];
		// Why something is not assigned: invoice lines without profile, supplier lines without area, or a booking without invoice.
		$reasons = array('lines' => 0, 'supplier' => 0, 'bookings' => 0);
		foreach ($bookings as $booking) {
			if (!isset($booking['parts'][VereineAccountRules::UNASSIGNED]) || abs($booking['amount']) < 0.01 || VereineAccountRules::side($booking['kind'], $booking['amount']) === '') {
				continue;
			}
			if ($booking['kind'] === VereineAccountRules::KIND_INVOICE) {
				$reasons['lines']++;
			} elseif ($booking['kind'] === VereineAccountRules::KIND_SUPPLIER) {
				$reasons['supplier']++;
			} else {
				$reasons['bookings']++;
			}
		}
		return array('period' => $period, 'record' => $record, 'bookings' => $bookings, 'totals' => $totals, 'opening' => $opening, 'closing' => $closing,
			'reconciled' => VereineAccountRules::reconciled($opening['total'], $totals['totals']['result'], $closing['total']),
			'receivables' => $receivables, 'payables' => $payables, 'extras' => $extras, 'net' => round($net, 2),
			'deadline' => VereineAccountRules::deadline($period['end']), 'warnings' => VereineAccountRules::sizeWarnings($totals['totals'], $previous),
			'unassigned' => array_sum($reasons), 'reasons' => $reasons);
	}

	/**
	 * The bookings of a year as CSV for a spreadsheet: one row per booking and area.
	 *
	 * @param array<string,mixed> $account     Result of build()
	 * @param Translate           $outputlangs Language
	 * @return string CSV with a byte order mark, separated by semicolons
	 */
	public static function csv(array $account, $outputlangs)
	{
		$rows = array(array($outputlangs->transnoentities('Date'), $outputlangs->transnoentities('VereineAccountBank'), $outputlangs->transnoentities('Description'),
			$outputlangs->transnoentities('VereineAccountKind'), $outputlangs->transnoentities('VereineAccountSide'), $outputlangs->transnoentities('VereineTaxSphere'),
			$outputlangs->transnoentities('Amount')));
		foreach ($account['bookings'] as $booking) {
			$side = VereineAccountRules::side($booking['kind'], $booking['amount']);
			foreach ($booking['parts'] as $sphere => $part) {
				$rows[] = array($booking['date'], $booking['account'], $booking['label'], $outputlangs->transnoentities('VereineAccountKind_'.$booking['kind']),
					$side !== '' ? $outputlangs->transnoentities('VereineAccountSide_'.$side) : '', $outputlangs->transnoentities('VereineSphereShort_'.$sphere),
					number_format($part, 2, ',', ''));
			}
		}
		$out = "\xEF\xBB\xBF";
		foreach ($rows as $row) {
			$out .= implode(';', array_map(function ($value) {
				$value = str_replace(array("\r", "\n"), ' ', (string) $value);
				// Nothing a spreadsheet would take for a formula.
				if ($value !== '' && strpos('=+-@', $value[0]) !== false && !is_numeric(str_replace(',', '.', $value))) {
					$value = "'".$value;
				}
				return '"'.str_replace('"', '""', $value).'"';
			}, $row))."\r\n";
		}
		return $out;
	}

	/**
	 * Build the PDF of the account and the statement of assets.
	 *
	 * @param int       $year        Year
	 * @param User      $user        Who builds it
	 * @param Translate $outputlangs Language
	 * @return string Path, empty on error
	 */
	public function buildPdf($year, $user, $outputlangs)
	{
		global $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		require_once dirname(__DIR__).'/lib/vereine.lib.php';

		$account = $this->build($year, $user);
		if ($account['record'] === null) {
			return '';
		}
		$outputlangs->load('vereine@vereine');
		$file = self::pdfPath($account['record']['id']);
		if (dol_mkdir(dirname($file)) < 0) {
			$this->error = 'cannot create '.dirname($file);
			return '';
		}
		$period = $account['period'];
		$organization = VereineOrganization::load($mysoc);
		// A finished document: PDF/A with its code (#123).
		require_once __DIR__.'/vereinearchive.class.php';
		$archive = new VereineArchive($this->db);
		$code = $archive->codeFor('account', $account['record']['id']);
		$pdf = VereinePdf::start($outputlangs, true);
		$font = pdf_getPDFFont($outputlangs);
		$row = function ($label, $amount, $style = '') use ($pdf, $font, $outputlangs) {
			$pdf->SetFont($font, $style, 10);
			$pdf->MultiCell(125, 5, $label, 0, 'L', false, 0);
			$pdf->MultiCell(45, 5, price($amount, 0, $outputlangs, 1, 2, 2).' €', 0, 'R', false, 1);
		};
		VereinePdf::title($pdf, $outputlangs, $outputlangs->transnoentities('VereineAccountPdfTitle', $period['label']),
			$outputlangs->transnoentities('VereineAccountPdfPeriod', $organization['name'], vereineFormatDay($period['start']), vereineFormatDay($period['end'])));
		foreach (array('income', 'expense') as $side) {
			VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineAccountSide_'.$side));
			foreach ($account['totals'][$side] as $sphere => $kinds) {
				$row($outputlangs->transnoentities('VereineSphereShort_'.$sphere), array_sum($kinds), 'B');
				foreach ($kinds as $kind => $amount) {
					$row('    '.$outputlangs->transnoentities('VereineAccountKind_'.$kind), $amount);
				}
			}
			$row($outputlangs->transnoentities('VereineAccountTotal_'.$side), $account['totals']['totals'][$side], 'B');
		}
		$pdf->Ln(2);
		$row($outputlangs->transnoentities('VereineAccountResult'), $account['totals']['totals']['result'], 'B');
		$pdf->SetFont($font, 'I', 8);
		$pdf->MultiCell(0, 4, $outputlangs->transnoentities($account['reconciled'] ? 'VereineAccountReconciled' : 'VereineAccountNotReconciled',
			price($account['opening']['total'], 0, $outputlangs, 1, 2, 2), price($account['closing']['total'], 0, $outputlangs, 1, 2, 2)), 0, 'L');

		VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineAccountAssetsTitle', vereineFormatDay($period['end'])));
		foreach ($account['closing']['accounts'] as $bank) {
			$row($bank['label'], $bank['balance']);
		}
		$row($outputlangs->transnoentities('VereineAccountReceivables'), $account['receivables']['total']);
		$row($outputlangs->transnoentities('VereineAccountPayables'), -$account['payables']['total']);
		foreach ($account['extras'] as $extra) {
			$row($extra['label'], $extra['kind'] === 'debt' ? -$extra['amount'] : $extra['amount']);
		}
		$row($outputlangs->transnoentities('VereineAccountNet'), $account['net'], 'B');

		$record = $account['record'];
		$pdf->Ln(6);
		$pdf->SetFont($font, '', 10);
		$pdf->MultiCell(0, 5, $outputlangs->transnoentities('VereineAccountPdfMade', $record['made_on'] !== '' ? vereineFormatDay($record['made_on']) : '____________'), 0, 'L');
		if ($record['note'] !== '') {
			$pdf->MultiCell(0, 5, $record['note'], 0, 'L');
		}
		$pdf->Ln(10);
		$pdf->MultiCell(80, 5, '______________________', 0, 'L', false, 0);
		$pdf->MultiCell(80, 5, '______________________', 0, 'L', false, 1);
		$pdf->SetFont($font, 'I', 8);
		$pdf->Ln(4);
		$pdf->MultiCell(0, 4, $outputlangs->transnoentities('VereineAccountPdfLaw'), 0, 'L');

		VereinePdf::finish($pdf, $outputlangs, $outputlangs->transnoentities('VereineAccountPdfTitle', $period['label']), VereineArchive::seal($code));
		$pdf->Output($file, 'F');
		if (is_file($file) && $archive->register($code, 'account', $account['record']['id'], $outputlangs->transnoentities('VereineAccountPdfTitle', $period['label']), $file) < 0) {
			$this->error = $archive->error;
			return '';
		}
		if (!is_file($file)) {
			$this->error = 'cannot write '.$file;
			return '';
		}
		dolChmod($file);
		VereineLog::add($this->db, $user, VereineLog::ACCOUNT_PDF, 0, 0, $year.': '.basename($file));
		return $file;
	}
}
