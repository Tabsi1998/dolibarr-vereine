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
 * \file    class/vereinetaxassign.class.php
 * \ingroup vereine
 * \brief   Tax profiles on products, customer invoice lines and supplier invoice lines.
 */

require_once __DIR__.'/vereinetaxprofiles.class.php';

/**
 * Links tax profiles to Dolibarr objects through one extra field, vereine_taxprofile.
 *
 * A product's VAT rate follows its profile. A new invoice line takes the profile of its
 * product. An invoice line whose rate differs from its profile is only reported: invoices
 * are never changed by the module.
 */
class VereineTaxAssign
{
	/** Name of the extra field on every element. */
	const FIELD = 'vereine_taxprofile';

	/** Elements with the extra field: element type => [line table, invoice id column]. */
	const ELEMENTS = array(
		'product' => array('', ''),
		'facturedet' => array('facturedet', 'fk_facture'),
		'facture_fourn_det' => array('facture_fourn_det', 'fk_facture_fourn'),
	);

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
	 * Register the extra field on products and invoice lines. Existing fields and their values stay.
	 *
	 * The list offers active profiles of the current entity only.
	 *
	 * @return int 1 if OK, <0 on error
	 */
	public function ensureFields()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$param = array('options' => array('vereine_taxprofile:label:rowid::(active:=:1) AND (entity:=:$ENTITY$)' => null));
		foreach (array_keys(self::ELEMENTS) as $position => $elementtype) {
			$result = $extrafields->addExtraField(
				self::FIELD,
				'VereineTaxProfileField',
				'sellist',
				1000 + $position,
				'',
				$elementtype,
				0,
				0,
				'',
				$param,
				1,
				'',
				'1',
				'VereineTaxProfileFieldHelp',
				'',
				'',
				'vereine@vereine',
				'isModEnabled("vereine")'
			);
			if ($result <= 0) {
				$this->error = 'Extra field on '.$elementtype.': '.$extrafields->error;
				return -1;
			}
		}
		return 1;
	}

	/**
	 * A product was created or changed: its VAT rate follows its tax profile.
	 *
	 * The rate changes through Dolibarr's price update, so the gross price is calculated
	 * again. With several price levels the module only reports the rate to set.
	 *
	 * @param Product $product Product with its extra fields
	 * @param User    $user    User
	 * @return int 1 when the rate changed, 0 when nothing to do, <0 on error
	 */
	public function onProductSaved($product, $user)
	{
		global $langs;

		$profile = $this->profileOf($product);
		if (!$profile || !VereineTaxRules::rateDeviates($product->tva_tx, $profile['rate'])) {
			return 0;
		}
		$langs->load('vereine@vereine');
		if (getDolGlobalString('PRODUIT_MULTIPRICES') || getDolGlobalString('PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES')) {
			setEventMessages($langs->trans('VereineTaxProductMultiprices', $profile['label'], VereineTaxRules::formatRate($profile['rate'])), null, 'warnings');
			return 0;
		}
		$base = $product->price_base_type === 'TTC' ? 'TTC' : 'HT';
		$price = $base === 'TTC' ? $product->price_ttc : $product->price;
		$minimum = $base === 'TTC' ? $product->price_min_ttc : $product->price_min;
		if ($product->updatePrice($price, $base, $user, $profile['rate'], $minimum, 0, (int) $product->tva_npr) <= 0) {
			$this->error = $product->error;
			return -1;
		}
		setEventMessages($langs->trans('VereineTaxProductRateSet', $profile['label'], VereineTaxRules::formatRate($profile['rate'])), null, 'mesgs');
		return 1;
	}

	/**
	 * A new invoice line without tax profile takes the profile of its product.
	 *
	 * @param CommonObjectLine $line Customer or supplier invoice line, already stored
	 * @return int 1 when a profile was set, 0 when nothing to do, <0 on error
	 */
	public function onLineCreated($line)
	{
		$key = 'options_'.self::FIELD;
		if ((int) $line->fk_product <= 0 || !empty($line->array_options[$key])) {
			return 0;
		}
		$sql = "SELECT ".self::FIELD." FROM ".MAIN_DB_PREFIX."product_extrafields WHERE fk_object = ".((int) $line->fk_product);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		$profileId = $obj ? (int) $obj->{self::FIELD} : 0;
		if ($profileId <= 0) {
			return 0;
		}
		if (!is_array($line->array_options)) {
			$line->array_options = array();
		}
		$line->array_options[$key] = $profileId;
		if ($line->insertExtraFields() < 0) {
			$this->error = $line->error;
			return -1;
		}
		return 1;
	}

	/**
	 * Lines of an invoice whose VAT rate differs from their tax profile.
	 *
	 * @param string $element   'facturedet' or 'facture_fourn_det'
	 * @param int    $invoiceId Invoice id
	 * @return array<int,array{position:int,description:string,rate:float,profile:string,profile_rate:float}>
	 */
	public function deviations($element, $invoiceId)
	{
		if (!isset(self::ELEMENTS[$element]) || self::ELEMENTS[$element][0] === '') {
			return array();
		}
		list($table, $column) = self::ELEMENTS[$element];
		$sql = "SELECT d.rowid, d.rang, d.description, d.tva_tx, p.label, p.rate";
		$sql .= " FROM ".MAIN_DB_PREFIX.$table." as d";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX.$table."_extrafields as e ON e.fk_object = d.rowid";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_taxprofile as p ON p.rowid = e.".self::FIELD;
		$sql .= " WHERE d.".$column." = ".((int) $invoiceId);
		$sql .= " ORDER BY d.rang, d.rowid";
		$lines = array();
		$resql = $this->db->query($sql);
		$position = 0;
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$position++;
			if (VereineTaxRules::rateDeviates($obj->tva_tx, $obj->rate)) {
				$lines[] = array(
					'position' => (int) $obj->rang > 0 ? (int) $obj->rang : $position,
					'description' => dol_trunc(dol_string_nohtmltag((string) $obj->description), 60),
					'rate' => (float) $obj->tva_tx,
					'profile' => (string) $obj->label,
					'profile_rate' => (float) $obj->rate,
				);
			}
		}
		return $lines;
	}

	/**
	 * Invoice notes of the tax profiles on an invoice's lines, grouped by text.
	 *
	 * @param string $element   'facturedet' or 'facture_fourn_det'
	 * @param int    $invoiceId Invoice id
	 * @return array<int,array{positions:int[],note:string}>
	 */
	public function invoiceNotes($element, $invoiceId)
	{
		if (!isset(self::ELEMENTS[$element]) || self::ELEMENTS[$element][0] === '') {
			return array();
		}
		list($table, $column) = self::ELEMENTS[$element];
		$sql = "SELECT d.rowid, d.rang, p.note";
		$sql .= " FROM ".MAIN_DB_PREFIX.$table." as d";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX.$table."_extrafields as e ON e.fk_object = d.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."vereine_taxprofile as p ON p.rowid = e.".self::FIELD;
		$sql .= " WHERE d.".$column." = ".((int) $invoiceId);
		$sql .= " ORDER BY d.rang, d.rowid";
		$lines = array();
		$position = 0;
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$position++;
			$lines[] = array('position' => (int) $obj->rang > 0 ? (int) $obj->rang : $position, 'note' => (string) $obj->note);
		}
		return VereineTaxRules::groupNotes($lines);
	}

	/**
	 * The tax profile an object points to.
	 *
	 * @param CommonObject $object Object with array_options
	 * @return array<string,mixed>|null
	 */
	private function profileOf($object)
	{
		$key = 'options_'.self::FIELD;
		$profileId = is_array($object->array_options) && !empty($object->array_options[$key]) ? (int) $object->array_options[$key] : 0;
		if ($profileId <= 0) {
			return null;
		}
		$profiles = new VereineTaxProfiles($this->db);
		return $profiles->fetch($profileId);
	}
}
