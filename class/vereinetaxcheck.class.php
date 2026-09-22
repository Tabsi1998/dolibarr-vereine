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
 * \file    class/vereinetaxcheck.class.php
 * \ingroup vereine
 * \brief   Older invoices and products without a tax profile, and giving their lines one afterwards (#57).
 *
 * Only the extra field of the tax profile changes. Amount, VAT, status, payments and notes of an
 * invoice stay as they are, also on validated invoices; every change is written to the log.
 */

require_once __DIR__.'/vereinetaxcheckrules.class.php';
require_once __DIR__.'/vereinetaxrules.class.php';
require_once __DIR__.'/vereinetaxprofiles.class.php';
require_once __DIR__.'/vereinetaxassign.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * The check of older invoice lines and products.
 */
class VereineTaxCheck
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
	 * Years with validated customer invoices, newest first.
	 *
	 * @return int[]
	 */
	public function years()
	{
		$sql = "SELECT DISTINCT YEAR(datef) as y FROM ".MAIN_DB_PREFIX."facture WHERE entity IN (".getEntity('invoice').") AND fk_statut > 0 ORDER BY y DESC";
		$years = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			if ((int) $obj->y > 0) {
				$years[] = (int) $obj->y;
			}
		}
		return $years;
	}

	/**
	 * Ids of the active profiles by code, and every profile by id.
	 *
	 * @return array{codes:array<string,int>,profiles:array<int,array<string,mixed>>}
	 */
	public function profiles()
	{
		$codes = array();
		$profiles = array();
		foreach ((new VereineTaxProfiles($this->db))->fetchAll() as $profile) {
			$profiles[(int) $profile['id']] = $profile;
			if (!empty($profile['active'])) {
				$codes[(string) $profile['code']] = (int) $profile['id'];
			}
		}
		return array('codes' => $codes, 'profiles' => $profiles);
	}

	/**
	 * Products and services for sale without a tax profile.
	 *
	 * @return array<int,array{id:int,ref:string,label:string,rate:float}>
	 */
	public function productsWithout()
	{
		$sql = "SELECT p.rowid, p.ref, p.label, p.tva_tx FROM ".MAIN_DB_PREFIX."product as p";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields as e ON e.fk_object = p.rowid";
		$sql .= " WHERE p.entity IN (".getEntity('product').") AND p.tosell = 1 AND (e.".VereineTaxAssign::FIELD." IS NULL OR e.".VereineTaxAssign::FIELD." = 0)";
		$sql .= " ORDER BY p.ref";
		$products = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$products[] = array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'label' => (string) $obj->label, 'rate' => (float) $obj->tva_tx);
		}
		return $products;
	}

	/**
	 * Lines of validated customer invoices of a year without a tax profile, each with a suggestion.
	 *
	 * @param int                 $year  Year of the invoice date
	 * @param array<string,int>   $codes Ids of the active profiles by code
	 * @return array<int,array<string,mixed>>
	 */
	public function linesWithout($year, array $codes)
	{
		$field = VereineTaxAssign::FIELD;
		$sql = "SELECT d.rowid, d.fk_facture, d.description, d.total_ht, d.tva_tx, d.fk_product, f.ref, f.datef, f.type, f.fk_facture_source, s.nom,";
		$sql .= " p.label as product_label, pe.".$field." as product_profile,";
		$sql .= " (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."element_element as ee WHERE ee.sourcetype = 'subscription' AND ee.targettype = 'facture'";
		$sql .= " AND ee.fk_target = f.rowid) as fee";
		$sql .= " FROM ".MAIN_DB_PREFIX."facturedet as d INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = d.fk_facture";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facturedet_extrafields as e ON e.fk_object = d.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = d.fk_product";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields as pe ON pe.fk_object = d.fk_product";
		$sql .= " WHERE f.entity IN (".getEntity('invoice').") AND f.fk_statut > 0 AND d.product_type <> 9";
		$sql .= " AND f.datef >= '".((int) $year)."-01-01' AND f.datef <= '".((int) $year)."-12-31'";
		$sql .= " AND (e.".$field." IS NULL OR e.".$field." = 0)";
		$sql .= " ORDER BY f.datef, f.rowid, d.rang, d.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$lines = array();
		$originals = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$source = 0;
			if ((int) $obj->type === 2 && (int) $obj->fk_facture_source > 0) {
				if (!isset($originals[(int) $obj->fk_facture_source])) {
					$originals[(int) $obj->fk_facture_source] = $this->profiledLines((int) $obj->fk_facture_source);
				}
				$source = VereineTaxCheckRules::sourceProfile((int) $obj->fk_product, $originals[(int) $obj->fk_facture_source]);
			}
			$text = trim(dol_string_nohtmltag((string) $obj->description).' '.(string) $obj->product_label);
			$facts = array('product_profile' => (int) $obj->product_profile, 'fee' => (int) $obj->fee > 0, 'source_profile' => $source, 'text' => $text);
			$lines[] = array(
				'id' => (int) $obj->rowid, 'invoice_id' => (int) $obj->fk_facture, 'invoice' => (string) $obj->ref, 'date' => $this->db->jdate($obj->datef),
				'customer' => (string) $obj->nom, 'text' => $text, 'total' => (float) $obj->total_ht, 'rate' => (float) $obj->tva_tx,
				'credit' => (int) $obj->type === 2, 'suggestion' => VereineTaxCheckRules::suggest($facts, $codes),
			);
		}
		return $lines;
	}

	/**
	 * Lines of validated customer invoices of a year whose VAT differs from their tax profile.
	 *
	 * @param int $year Year of the invoice date
	 * @return array<int,array{invoice_id:int,invoice:string,text:string,rate:float,profile:string,profile_rate:float}>
	 */
	public function deviating($year)
	{
		$field = VereineTaxAssign::FIELD;
		$sql = "SELECT d.fk_facture, f.ref, d.description, d.tva_tx, t.label, t.rate FROM ".MAIN_DB_PREFIX."facturedet as d";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = d.fk_facture";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facturedet_extrafields as e ON e.fk_object = d.rowid";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_taxprofile as t ON t.rowid = e.".$field;
		$sql .= " WHERE f.entity IN (".getEntity('invoice').") AND f.fk_statut > 0";
		$sql .= " AND f.datef >= '".((int) $year)."-01-01' AND f.datef <= '".((int) $year)."-12-31' ORDER BY f.datef, f.rowid, d.rang";
		$lines = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			if (VereineTaxRules::rateDeviates($obj->tva_tx, $obj->rate)) {
				$lines[] = array('invoice_id' => (int) $obj->fk_facture, 'invoice' => (string) $obj->ref,
					'text' => dol_trunc(dol_string_nohtmltag((string) $obj->description), 60), 'rate' => (float) $obj->tva_tx,
					'profile' => (string) $obj->label, 'profile_rate' => (float) $obj->rate);
			}
		}
		return $lines;
	}

	/**
	 * How many supplier invoice lines still carry a sales profile from before #53; it is hidden and ignored.
	 *
	 * @return int
	 */
	public function supplierLegacy()
	{
		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."facture_fourn_det_extrafields WHERE ".VereineTaxAssign::FIELD." > 0";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? (int) $obj->nb : 0;
	}

	/**
	 * Give lines without a tax profile the one chosen; nothing else of the invoice changes.
	 *
	 * @param array<int,int> $chosen Profile id by line id
	 * @param User           $user   Who assigns
	 * @return int Lines changed, 0 when refused (see errors), -1 on error
	 */
	public function assign(array $chosen, $user)
	{
		$this->errors = array();
		$field = VereineTaxAssign::FIELD;
		$active = array();
		foreach ($this->profiles()['codes'] as $code => $id) {
			$active[$id] = $code;
		}
		$done = 0;
		$this->db->begin();
		foreach ($chosen as $lineId => $profileId) {
			if (!isset($active[(int) $profileId])) {
				continue;
			}
			$sql = "SELECT d.rowid, f.ref, e.rowid as extra, e.".$field." as profile FROM ".MAIN_DB_PREFIX."facturedet as d";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = d.fk_facture";
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facturedet_extrafields as e ON e.fk_object = d.rowid";
			$sql .= " WHERE d.rowid = ".((int) $lineId)." AND f.entity IN (".getEntity('invoice').")";
			$resql = $this->db->query($sql);
			$obj = $resql ? $this->db->fetch_object($resql) : null;
			// Only lines without a profile: a profile somebody chose is never overwritten here.
			if (!$obj || (int) $obj->profile > 0) {
				continue;
			}
			if ($obj->extra) {
				$sql = "UPDATE ".MAIN_DB_PREFIX."facturedet_extrafields SET ".$field." = ".((int) $profileId)." WHERE rowid = ".((int) $obj->extra);
			} else {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."facturedet_extrafields (fk_object, ".$field.") VALUES (".((int) $lineId).", ".((int) $profileId).")";
			}
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
			VereineLog::add($this->db, $user, VereineLog::TAX_PROFILE_SET, 0, 0, 'line '.((int) $lineId).' of '.$obj->ref.': - -> '.$active[(int) $profileId]);
			$done++;
		}
		$this->db->commit();
		if ($done === 0) {
			$this->errors[] = 'VereineTaxCheckNothing';
		}
		return $done;
	}

	/**
	 * Lines of an invoice with their product and tax profile, for a credit note.
	 *
	 * @param int $invoiceId Invoice
	 * @return array<int,array{product:int,profile:int}>
	 */
	private function profiledLines($invoiceId)
	{
		$sql = "SELECT d.fk_product, e.".VereineTaxAssign::FIELD." as profile FROM ".MAIN_DB_PREFIX."facturedet as d";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facturedet_extrafields as e ON e.fk_object = d.rowid WHERE d.fk_facture = ".((int) $invoiceId);
		$lines = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$lines[] = array('product' => (int) $obj->fk_product, 'profile' => (int) $obj->profile);
		}
		return $lines;
	}
}
