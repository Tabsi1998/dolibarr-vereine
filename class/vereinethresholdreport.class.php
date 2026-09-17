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
 * \file    class/vereinethresholdreport.class.php
 * \ingroup vereine
 * \brief   Income per tax profile from customer invoices, and the thresholds of a calendar year.
 */

require_once __DIR__.'/vereinethresholds.class.php';
require_once __DIR__.'/vereinecashregister.class.php';

/**
 * Reads invoice lines and evaluates the thresholds.
 *
 * Counted: validated and paid customer invoices, credit notes and replacements, by
 * invoice date. Not counted: drafts, abandoned invoices and deposit invoices, whose
 * amount the final invoice contains.
 */
class VereineThresholdReport
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
	 * Thresholds of a calendar year, with the income that has no tax profile.
	 *
	 * @param int $year Calendar year
	 * @return array{year:int,thresholds:array<int,array<string,mixed>>,unassigned:array{net:float,gross:float,lines:int}}
	 */
	public function report($year)
	{
		$year = (int) $year;
		$income = $this->income($year);
		$assigned = array();
		$unassigned = array('net' => 0.0, 'gross' => 0.0, 'lines' => 0);
		foreach ($income as $row) {
			if ($row['sphere'] === '') {
				$unassigned = array('net' => $row['net'], 'gross' => $row['gross'], 'lines' => $row['lines']);
			} else {
				$assigned[] = $row;
			}
		}
		$previous = array_filter($this->income($year - 1), function ($row) {
			return $row['sphere'] !== '';
		});
		return array(
			'year' => $year,
			'thresholds' => VereineThresholds::evaluate($year, $assigned, array_values($previous)),
			'unassigned' => $unassigned,
			'cash_register' => $this->cashRegister($year),
		);
	}

	/**
	 * Cash register duty per sphere of a calendar year.
	 *
	 * Turnover counts gross from the invoice lines; cash counts payments of the year made
	 * in cash, by card, cheque or online, shared out over the spheres of the paid invoice.
	 *
	 * @param int $year Calendar year
	 * @return array{turnover_limit:float,cash_limit:float,small_canteen_limit:float,small_canteen_days:int,spheres:array<int,array{sphere:string,turnover:float,cash:float,status:string}>,unassigned_cash:float}
	 */
	public function cashRegister($year)
	{
		$turnover = array();
		foreach ($this->income($year) as $row) {
			if ($row['sphere'] !== '') {
				$turnover[$row['sphere']] = (isset($turnover[$row['sphere']]) ? $turnover[$row['sphere']] : 0.0) + $row['gross'];
			}
		}

		$codes = array();
		foreach (VereineCashRegister::CASH_PAYMENT_CODES as $code) {
			$codes[] = "'".$this->db->escape($code)."'";
		}
		$sql = "SELECT pf.fk_facture, SUM(pf.amount) as cash";
		$sql .= " FROM ".MAIN_DB_PREFIX."paiement_facture as pf";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."paiement as p ON p.rowid = pf.fk_paiement";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."c_paiement as c ON c.id = p.fk_paiement";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = pf.fk_facture";
		$sql .= " WHERE f.entity IN (".getEntity('invoice').") AND c.code IN (".implode(', ', $codes).")";
		$sql .= " AND p.datep >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, (int) $year))."'";
		$sql .= " AND p.datep <= '".$this->db->idate(dol_mktime(23, 59, 59, 12, 31, (int) $year))."'";
		$sql .= " GROUP BY pf.fk_facture";
		$cashByInvoice = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$cashByInvoice[(int) $obj->fk_facture] = (float) $obj->cash;
		}

		$cash = array();
		if ($cashByInvoice) {
			$sql = "SELECT d.fk_facture, p.sphere, SUM(d.total_ttc) as gross";
			$sql .= " FROM ".MAIN_DB_PREFIX."facturedet as d";
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facturedet_extrafields as e ON e.fk_object = d.rowid";
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."vereine_taxprofile as p ON p.rowid = e.vereine_taxprofile";
			$sql .= " WHERE d.fk_facture IN (".implode(', ', array_map('intval', array_keys($cashByInvoice))).")";
			$sql .= " GROUP BY d.fk_facture, p.sphere";
			$grossByInvoice = array();
			$resql = $this->db->query($sql);
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$grossByInvoice[(int) $obj->fk_facture][(string) $obj->sphere] = (float) $obj->gross;
			}
			foreach ($cashByInvoice as $invoiceId => $paid) {
				$shares = VereineCashRegister::allocate(isset($grossByInvoice[$invoiceId]) ? $grossByInvoice[$invoiceId] : array(), $paid);
				foreach ($shares as $sphere => $share) {
					$cash[$sphere] = (isset($cash[$sphere]) ? $cash[$sphere] : 0.0) + $share;
				}
			}
		}

		$spheres = array();
		foreach (array_keys(VereineTaxRules::spheres()) as $sphere) {
			$sphereTurnover = isset($turnover[$sphere]) ? round($turnover[$sphere], 2) : 0.0;
			$sphereCash = isset($cash[$sphere]) ? round($cash[$sphere], 2) : 0.0;
			if (abs($sphereTurnover) < 0.005 && abs($sphereCash) < 0.005) {
				continue;
			}
			$spheres[] = array('sphere' => $sphere, 'turnover' => $sphereTurnover, 'cash' => $sphereCash, 'status' => VereineCashRegister::status($sphere, $sphereTurnover, $sphereCash));
		}
		return array(
			'turnover_limit' => VereineCashRegister::TURNOVER_LIMIT,
			'cash_limit' => VereineCashRegister::CASH_LIMIT,
			'small_canteen_limit' => VereineCashRegister::smallCanteenLimit($year),
			'small_canteen_days' => VereineCashRegister::SMALL_CANTEEN_DAYS,
			'spheres' => $spheres,
			'unassigned_cash' => isset($cash['']) ? round($cash[''], 2) : 0.0,
		);
	}

	/**
	 * Income of a calendar year per sphere and VAT treatment; lines without profile have sphere ''.
	 *
	 * @param int $year Calendar year
	 * @return array<int,array{sphere:string,treatment:string,net:float,gross:float,lines:int}>
	 */
	public function income($year)
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$sql = "SELECT p.sphere, p.treatment, SUM(d.total_ht) as net, SUM(d.total_ttc) as gross, COUNT(d.rowid) as nb";
		$sql .= " FROM ".MAIN_DB_PREFIX."facturedet as d";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = d.fk_facture";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facturedet_extrafields as e ON e.fk_object = d.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."vereine_taxprofile as p ON p.rowid = e.vereine_taxprofile";
		$sql .= " WHERE f.entity IN (".getEntity('invoice').")";
		$sql .= " AND f.fk_statut IN (".Facture::STATUS_VALIDATED.", ".Facture::STATUS_CLOSED.")";
		$sql .= " AND f.type IN (".Facture::TYPE_STANDARD.", ".Facture::TYPE_REPLACEMENT.", ".Facture::TYPE_CREDIT_NOTE.")";
		$sql .= " AND f.datef >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, (int) $year))."'";
		$sql .= " AND f.datef <= '".$this->db->idate(dol_mktime(23, 59, 59, 12, 31, (int) $year))."'";
		$sql .= " GROUP BY p.sphere, p.treatment";
		$rows = array();
		$unassigned = array('sphere' => '', 'treatment' => '', 'net' => 0.0, 'gross' => 0.0, 'lines' => 0);
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			if ((string) $obj->sphere === '') {
				// Lines without profile, and lines whose profile was deleted, are one group.
				$unassigned['net'] += (float) $obj->net;
				$unassigned['gross'] += (float) $obj->gross;
				$unassigned['lines'] += (int) $obj->nb;
				continue;
			}
			$rows[] = array('sphere' => (string) $obj->sphere, 'treatment' => (string) $obj->treatment, 'net' => (float) $obj->net, 'gross' => (float) $obj->gross, 'lines' => (int) $obj->nb);
		}
		if ($unassigned['lines'] > 0) {
			$rows[] = $unassigned;
		}
		return $rows;
	}
}
