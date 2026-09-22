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
 * \file    class/vereineaudit.class.php
 * \ingroup vereine
 * \brief   The audit of an association's year in Dolibarr (#117) and the report of the auditors as PDF (#114).
 *
 * The auditors read the bookings and invoices of the year, tick the samples they checked and fill the
 * checklist of § 21 (3) VerG; the module only writes its own tables. Who is an auditor comes from the
 * functions: a member holding a function marked as auditing, linked to the Dolibarr user.
 */

require_once __DIR__.'/vereineauditrules.class.php';
require_once __DIR__.'/vereinefunctions.class.php';
require_once __DIR__.'/vereineorganization.class.php';
require_once __DIR__.'/vereinepdf.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * The audit of the auditors.
 */
class VereineAudit
{
	/** A booking of a bank or cash account. */
	const ELEMENT_BANK = 'bank';
	/** A customer invoice. */
	const ELEMENT_INVOICE = 'invoice';
	/** A supplier invoice. */
	const ELEMENT_SUPPLIER = 'supplier';
	/** Every kind of item, in the order of the page. */
	const ELEMENTS = array('bank', 'invoice', 'supplier');

	/** Links of a booking that stand for a payment or document behind it. */
	const DOCUMENT_LINKS = array('payment', 'payment_supplier', 'payment_vat', 'payment_salary', 'payment_donation', 'payment_expensereport',
		'payment_various', 'payment_loan', 'payment_sc', 'member', 'sc', 'banktransfert');

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
	 * Where the reports live.
	 *
	 * @return string
	 */
	public static function directory()
	{
		global $conf;

		return DOL_DATA_ROOT.($conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/vereine/audit';
	}

	/**
	 * Path of the report of an audit.
	 *
	 * @param int $id Audit
	 * @return string
	 */
	public static function reportPath($id)
	{
		return self::directory().'/pruefbericht-'.((int) $id).'.pdf';
	}

	/**
	 * First and last day of an association's year, with the start month Dolibarr knows.
	 *
	 * @param int $year Year it starts in
	 * @return array{year:int,start:string,end:string,label:string}
	 */
	public static function period($year)
	{
		return VereineAuditRules::period((int) $year, getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1));
	}

	/**
	 * The audit of a year; it is created on first use, so its report can have a signature run.
	 *
	 * @param int  $year Year the association's year starts in
	 * @param User $user Who opens it
	 * @return array<string,mixed>|null
	 */
	public function fetch($year, $user)
	{
		global $conf;

		$sql = "SELECT rowid, fiscal_year, audit_day, statement_day, documents, points, note FROM ".MAIN_DB_PREFIX."vereine_audit";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND fiscal_year = ".((int) $year);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_audit (entity, fiscal_year, datec, fk_user_modif) VALUES (".((int) $conf->entity).", ".((int) $year).",";
			$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return null;
			}
			return $this->fetch($year, $user);
		}
		return array('id' => (int) $obj->rowid, 'year' => (int) $obj->fiscal_year, 'audit_day' => (string) $obj->audit_day, 'statement_day' => (string) $obj->statement_day,
			'documents' => (string) $obj->documents, 'points' => VereineAuditRules::points(json_decode((string) $obj->points, true)), 'note' => (string) $obj->note);
	}

	/**
	 * The audit by id.
	 *
	 * @param int $id Audit
	 * @return int Year it belongs to, 0 when unknown
	 */
	public function yearOf($id)
	{
		global $conf;

		$sql = "SELECT fiscal_year FROM ".MAIN_DB_PREFIX."vereine_audit WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? (int) $obj->fiscal_year : 0;
	}

	/**
	 * Store the checklist, the days and the documents of an audit.
	 *
	 * @param int                 $year    Year
	 * @param array<string,mixed> $entered points, audit_day, statement_day, documents, note
	 * @param User                $user    Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function save($year, array $entered, $user)
	{
		$audit = $this->fetch($year, $user);
		if ($audit === null) {
			return -1;
		}
		$points = VereineAuditRules::points(isset($entered['points']) ? $entered['points'] : array());
		$this->errors = VereineAuditRules::validate($points);
		$days = array();
		foreach (array('audit_day', 'statement_day') as $key) {
			$value = isset($entered[$key]) ? trim((string) $entered[$key]) : '';
			if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
				$this->errors[] = 'VereineAuditErrorDay';
			}
			$days[$key] = $value;
		}
		if ($this->errors) {
			return 0;
		}
		$text = function ($key) use ($entered) {
			return isset($entered[$key]) ? mb_substr(trim((string) $entered[$key]), 0, VereineAuditRules::TEXT_MAX, 'UTF-8') : '';
		};
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_audit SET points = '".$this->db->escape((string) json_encode($points))."',";
		foreach ($days as $key => $value) {
			$sql .= " ".$key." = ".($value !== '' ? "'".$this->db->escape($value)."'" : "NULL").",";
		}
		$sql .= " documents = '".$this->db->escape($text('documents'))."', note = '".$this->db->escape($text('note'))."', fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $audit['id']);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::AUDIT_SAVED, 0, 0, $year.': '.VereineAuditRules::result($points));
		return 1;
	}

	/**
	 * Holders of functions of a kind on a day, with the term of their function.
	 *
	 * @param string $day   Day, YYYY-MM-DD
	 * @param string $which 'auditor' or 'board'
	 * @return array<int,array{member_id:int,name:string,label:string,code:string,start:string,end:string}>
	 */
	public function holders($day, $which)
	{
		$functions = new VereineFunctions($this->db);
		$byId = array();
		foreach ($functions->fetchAll(true) as $function) {
			if ($which === 'auditor' ? $function['auditor'] : ($function['board'] || $function['represents'])) {
				$byId[$function['id']] = $function;
			}
		}
		$holders = array();
		foreach ($functions->terms() as $term) {
			if (!isset($byId[$term['function_id']]) || (int) $term['member_status'] !== 1 || !VereineFunctionRules::isActive($term, $day)) {
				continue;
			}
			$function = $byId[$term['function_id']];
			$holders[] = array('member_id' => (int) $term['member_id'], 'name' => (string) $term['member_name'], 'label' => (string) $function['label'],
				'code' => (string) $function['code'], 'start' => (string) $term['start'], 'end' => (string) $term['end']);
		}
		return $holders;
	}

	/**
	 * Whether a user is an auditor today: the member of the user holds a function marked as auditing.
	 *
	 * @param User $user User
	 * @return bool
	 */
	public function isAuditor($user)
	{
		if ((int) $user->fk_member <= 0) {
			return false;
		}
		foreach ($this->holders(dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'), 'auditor') as $holder) {
			if ($holder['member_id'] === (int) $user->fk_member) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The third parties of officers during a period: money with them is a transaction of an officer (§ 6 (4) VerG).
	 *
	 * @param array{start:string,end:string} $period Period
	 * @return array<int,string> Name of the officer by third party
	 */
	public function officerParties(array $period)
	{
		$sql = "SELECT DISTINCT d.fk_soc, d.firstname, d.lastname FROM ".MAIN_DB_PREFIX."vereine_function_term as t";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_function as f ON f.rowid = t.fk_function";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent as d ON d.rowid = t.fk_adherent";
		$sql .= " WHERE (f.board = 1 OR f.represents = 1) AND d.fk_soc > 0 AND t.date_start <= '".$this->db->escape($period['end'])."'";
		$sql .= " AND (t.date_end IS NULL OR t.date_end >= '".$this->db->escape($period['start'])."')";
		$parties = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$parties[(int) $obj->fk_soc] = trim($obj->firstname.' '.$obj->lastname);
		}
		return $parties;
	}

	/**
	 * Bookings and invoices of a year, each with the hints where to look closer and whether it was checked.
	 *
	 * @param int $year Year the association's year starts in
	 * @return array<string,array<int,array<string,mixed>>> By element
	 */
	public function items($year)
	{
		$period = self::period($year);
		$between = " BETWEEN '".$this->db->escape($period['start'])."' AND '".$this->db->escape($period['end'])."'";
		$officers = $this->officerParties($period);
		$checks = $this->checks($year);
		$items = array_fill_keys(self::ELEMENTS, array());

		$sql = "SELECT b.rowid, b.dateo, b.amount, b.label, a.label as account,";
		$sql .= " (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."bank_url as u WHERE u.fk_bank = b.rowid AND u.type IN ('".implode("','", self::DOCUMENT_LINKS)."')) as documents,";
		$sql .= " (SELECT MAX(u.url_id) FROM ".MAIN_DB_PREFIX."bank_url as u WHERE u.fk_bank = b.rowid AND u.type = 'company') as socid";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank as b INNER JOIN ".MAIN_DB_PREFIX."bank_account as a ON a.rowid = b.fk_account";
		$sql .= " WHERE a.entity IN (".getEntity('bank_account').") AND b.dateo".$between." ORDER BY b.dateo, b.rowid";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$items[self::ELEMENT_BANK][] = array('id' => (int) $obj->rowid, 'date' => (string) $obj->dateo, 'text' => trim($obj->account.': '.$obj->label),
				'amount' => (float) $obj->amount, 'party' => (int) $obj->socid, 'documents' => (int) $obj->documents,
				'url' => DOL_URL_ROOT.'/compta/bank/line.php?rowid='.((int) $obj->rowid));
		}
		$sql = "SELECT f.rowid, f.ref, f.datef, f.total_ttc, f.fk_soc, s.nom,";
		$sql .= " (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."element_element as ee WHERE ee.sourcetype = 'subscription' AND ee.targettype = 'facture' AND ee.fk_target = f.rowid) as fee";
		$sql .= " FROM ".MAIN_DB_PREFIX."facture as f LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
		$sql .= " WHERE f.entity IN (".getEntity('invoice').") AND f.fk_statut > 0 AND f.datef".$between." ORDER BY f.datef, f.rowid";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$items[self::ELEMENT_INVOICE][] = array('id' => (int) $obj->rowid, 'date' => (string) $obj->datef, 'text' => trim($obj->ref.' '.$obj->nom),
				'amount' => (float) $obj->total_ttc, 'party' => (int) $obj->fee > 0 ? 0 : (int) $obj->fk_soc, 'documents' => 1,
				'url' => DOL_URL_ROOT.'/compta/facture/card.php?id='.((int) $obj->rowid));
		}
		$sql = "SELECT f.rowid, f.ref, f.ref_supplier, f.datef, f.total_ttc, f.fk_soc, s.nom FROM ".MAIN_DB_PREFIX."facture_fourn as f";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
		$sql .= " WHERE f.entity IN (".getEntity('supplier_invoice').") AND f.fk_statut > 0 AND f.datef".$between." ORDER BY f.datef, f.rowid";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$items[self::ELEMENT_SUPPLIER][] = array('id' => (int) $obj->rowid, 'date' => (string) $obj->datef, 'text' => trim($obj->ref.' '.$obj->ref_supplier.' '.$obj->nom),
				'amount' => -(float) $obj->total_ttc, 'party' => (int) $obj->fk_soc, 'documents' => 1,
				'url' => DOL_URL_ROOT.'/fourn/facture/card.php?facid='.((int) $obj->rowid));
		}

		foreach ($items as $element => $rows) {
			$unusual = VereineAuditRules::unusualFrom(array_column($rows, 'amount'));
			foreach ($rows as $index => $row) {
				$hints = array();
				if ($row['documents'] === 0) {
					$hints[] = VereineAuditRules::HINT_NO_DOCUMENT;
				}
				if (abs($row['amount']) >= $unusual) {
					$hints[] = VereineAuditRules::HINT_UNUSUAL;
				}
				if ($row['party'] > 0 && isset($officers[$row['party']])) {
					$hints[] = VereineAuditRules::HINT_SELF_DEALING;
				}
				$key = $element.':'.$row['id'];
				$items[$element][$index] += array('hints' => $hints, 'officer' => $row['party'] > 0 && isset($officers[$row['party']]) ? $officers[$row['party']] : '',
					'check' => isset($checks[$key]) ? $checks[$key] : null);
			}
		}
		return $items;
	}

	/**
	 * The samples the auditors checked in a year.
	 *
	 * @param int $year Year
	 * @return array<string,array{note:string,user:string,date:int}> By element:id
	 */
	public function checks($year)
	{
		global $conf;

		$sql = "SELECT c.element, c.fk_object, c.note, c.datec, u.firstname, u.lastname, u.login FROM ".MAIN_DB_PREFIX."vereine_audit_check as c";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = c.fk_user WHERE c.entity = ".((int) $conf->entity)." AND c.fiscal_year = ".((int) $year);
		$checks = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$name = trim($obj->firstname.' '.$obj->lastname);
			$checks[$obj->element.':'.((int) $obj->fk_object)] = array('note' => (string) $obj->note, 'user' => $name !== '' ? $name : (string) $obj->login,
				'date' => $this->db->jdate($obj->datec));
		}
		return $checks;
	}

	/**
	 * Tick a booking or invoice as checked, with a note; or take the tick back.
	 *
	 * @param int    $year     Year
	 * @param string $element  One of ELEMENTS
	 * @param int    $objectId The booking or invoice
	 * @param string $note     Note of the auditor
	 * @param bool   $checked  False to take the tick back
	 * @param User   $user     The auditor
	 * @return int 1 when done, 0 when refused (see errors), -1 on error
	 */
	public function check($year, $element, $objectId, $note, $checked, $user)
	{
		global $conf;

		$this->errors = array();
		if (!in_array($element, self::ELEMENTS, true) || (int) $objectId <= 0) {
			$this->errors[] = 'VereineAuditErrorItem';
			return 0;
		}
		$known = false;
		foreach ($this->items($year)[$element] as $row) {
			$known = $known || $row['id'] === (int) $objectId;
		}
		if (!$known) {
			$this->errors[] = 'VereineAuditErrorItem';
			return 0;
		}
		$where = " WHERE entity = ".((int) $conf->entity)." AND fiscal_year = ".((int) $year)." AND element = '".$this->db->escape($element)."' AND fk_object = ".((int) $objectId);
		if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_audit_check".$where)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($checked) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_audit_check (entity, fiscal_year, element, fk_object, note, fk_user, datec) VALUES (".((int) $conf->entity).",";
			$sql .= " ".((int) $year).", '".$this->db->escape($element)."', ".((int) $objectId).", '".$this->db->escape(mb_substr(trim((string) $note), 0, 255, 'UTF-8'))."',";
			$sql .= " ".((int) $user->id).", '".$this->db->idate(dol_now())."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		VereineLog::add($this->db, $user, VereineLog::AUDIT_CHECKED, 0, 0, $year.': '.$element.' '.((int) $objectId).($checked ? ' checked' : ' taken back'));
		return 1;
	}

	/**
	 * Build the report of the auditors for a year.
	 *
	 * @param int       $year        Year
	 * @param User      $user        Who builds it
	 * @param Translate $outputlangs Language
	 * @return string Path of the PDF, empty on error
	 */
	public function buildReport($year, $user, $outputlangs)
	{
		global $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		require_once dirname(__DIR__).'/lib/vereine.lib.php';

		$audit = $this->fetch($year, $user);
		if ($audit === null) {
			return '';
		}
		$outputlangs->load('vereine@vereine');
		$period = self::period($year);
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$statement = $audit['statement_day'] !== '' ? $audit['statement_day'] : $period['end'];
		$auditors = $this->holders($today, 'auditor');
		$board = $this->holders($statement, 'board');
		$organization = VereineOrganization::load($mysoc);
		$checked = count($this->checks($year));
		require_once __DIR__.'/vereineaccount.class.php';
		$figures = (new VereineAccount($this->db))->build($year, $user);

		$file = self::reportPath($audit['id']);
		if (dol_mkdir(dirname($file)) < 0) {
			$this->error = 'cannot create '.dirname($file);
			return '';
		}
		$pdf = VereinePdf::start($outputlangs);
		$font = pdf_getPDFFont($outputlangs);
		$line = function ($text, $style = '', $size = 10) use ($pdf, $font) {
			$pdf->SetFont($font, $style, $size);
			$pdf->MultiCell(0, 5, $text, 0, 'L');
		};
		$names = function (array $holders) {
			$list = array();
			foreach ($holders as $holder) {
				$list[] = $holder['name'].' ('.$holder['label'].')';
			}
			return implode(', ', $list);
		};

		VereinePdf::title($pdf, $outputlangs, $outputlangs->transnoentities('VereineAuditReportTitle', $period['label']),
			$outputlangs->transnoentities('VereineAuditReportPeriod', vereineFormatDay($period['start']), vereineFormatDay($period['end'])));
		$line($outputlangs->transnoentities('VereineAuditReportTo', $organization['name']));
		if ($board) {
			$line($outputlangs->transnoentities('VereineAuditReportBoard', vereineFormatDay($statement), $names($board)));
		}

		VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineAuditReportAuditors'));
		foreach ($auditors as $auditor) {
			$line($outputlangs->transnoentities('VereineAuditReportAuditor', $auditor['name'], vereineFormatDay($auditor['start']),
				$auditor['end'] !== '' ? vereineFormatDay($auditor['end']) : $outputlangs->transnoentitiesnoconv('VereineAuditReportOpenEnd')));
		}
		if (!$auditors) {
			$line('______________________________');
		}

		VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineAuditReportScope'));
		$line($outputlangs->transnoentities('VereineAuditReportDays', $audit['audit_day'] !== '' ? vereineFormatDay($audit['audit_day']) : '____________',
			vereineFormatDay($statement)));
		$line($outputlangs->transnoentities('VereineAuditReportDocuments', $audit['documents'] !== '' ? $audit['documents'] : $outputlangs->transnoentitiesnoconv('VereineAuditDocumentsDefault')));
		if ($checked > 0) {
			$line($outputlangs->transnoentities('VereineAuditReportSamples', $checked));
		}
		$money = function ($amount) use ($outputlangs) {
			return price($amount, 0, $outputlangs, 1, 2, 2).' €';
		};
		$line($outputlangs->transnoentities('VereineAuditReportFigures', $money($figures['totals']['totals']['income']), $money($figures['totals']['totals']['expense']),
			$money($figures['totals']['totals']['result']), $money($figures['net'])));
		$informants = array();
		foreach ($board as $holder) {
			if (in_array($holder['code'], array('kassier', 'obmann'), true)) {
				$informants[] = $holder['name'].' ('.$holder['label'].')';
			}
		}
		if ($informants) {
			$line($outputlangs->transnoentities('VereineAuditReportInformants', implode(', ', $informants)));
		}

		VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineAuditReportResult'));
		foreach ($audit['points'] as $point => $entry) {
			$state = $outputlangs->transnoentitiesnoconv('VereineAuditState_'.$entry['state']);
			$line($outputlangs->transnoentitiesnoconv('VereineAuditPoint_'.$point).': '.$state, 'B');
			if ($entry['text'] !== '') {
				$line($entry['text']);
			}
		}
		$pdf->Ln(2);
		$line($outputlangs->transnoentitiesnoconv('VereineAuditConclusion_'.VereineAuditRules::result($audit['points'])));
		if ($audit['note'] !== '') {
			$pdf->Ln(2);
			$line($audit['note']);
		}

		// Place, day and a line to sign for every auditor.
		$pdf->Ln(10);
		foreach ($auditors ? $auditors : array(array('name' => ''), array('name' => '')) as $auditor) {
			$pdf->SetFont($font, '', 10);
			$pdf->MultiCell(80, 5, '______________________', 0, 'L', false, 0);
			$pdf->MultiCell(80, 5, '______________________', 0, 'L', false, 1);
			$pdf->SetFont($font, '', 8);
			$pdf->MultiCell(80, 4, $outputlangs->transnoentitiesnoconv('VereineAuditReportPlaceDay'), 0, 'L', false, 0);
			$pdf->MultiCell(80, 4, $auditor['name'], 0, 'L', false, 1);
			$pdf->Ln(6);
		}
		$pdf->SetFont($font, 'I', 8);
		$pdf->MultiCell(0, 4, $outputlangs->transnoentities('VereineAuditReportLaw'), 0, 'L');

		VereinePdf::finish($pdf, $outputlangs, $outputlangs->transnoentities('VereineAuditReportTitle', $period['label']));
		$pdf->Output($file, 'F');
		if (!is_file($file)) {
			$this->error = 'cannot write '.$file;
			return '';
		}
		dolChmod($file);
		VereineLog::add($this->db, $user, VereineLog::AUDIT_REPORT, 0, 0, $year.': '.basename($file));
		return $file;
	}
}
