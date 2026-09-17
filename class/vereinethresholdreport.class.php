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
