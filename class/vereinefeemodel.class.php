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
 * \file    class/vereinefeemodel.class.php
 * \ingroup vereine
 * \brief   Fee model of Dolibarr's member types: extra fields on the member type card and reading them.
 */

require_once __DIR__.'/vereinefeerules.class.php';

/**
 * The fee model lives in extra fields of Dolibarr's member type, next to its amount and duration.
 */
class VereineFeeModel
{
	/** Month the fee year starts, empty when it starts with joining. */
	const FIELD_START_MONTH = 'vereine_fee_start_month';
	/** Whether a first fee during a fee year is prorated by month. */
	const FIELD_PRORATED = 'vereine_fee_prorated';
	/** One-off admission fee with the first fee. */
	const FIELD_ADMISSION = 'vereine_admission_fee';
	/** Product of the invoice line; Dolibarr's subscription product when empty. */
	const FIELD_PRODUCT = 'vereine_fee_product';

	/** Core language keys of the months, for the list of start months. */
	const MONTHS = array(1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
		7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December');

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
	 * Register the extra fields on member types. Existing fields and their values stay.
	 *
	 * @return int 1 if OK, <0 on error
	 */
	public function ensureFields()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$fields = array(
			array(self::FIELD_START_MONTH, 'VereineFeeStartMonth', 'select', array('options' => self::MONTHS), 'VereineFeeStartMonthHelp'),
			array(self::FIELD_PRORATED, 'VereineFeeProrated', 'boolean', '', 'VereineFeeProratedHelp'),
			array(self::FIELD_ADMISSION, 'VereineFeeAdmission', 'price', '', 'VereineFeeAdmissionHelp'),
			array(self::FIELD_PRODUCT, 'VereineFeeProduct', 'link', array('options' => array('Product:product/class/product.class.php' => null)), 'VereineFeeProductHelp'),
		);
		foreach ($fields as $position => $field) {
			$result = $extrafields->addExtraField(
				$field[0],
				$field[1],
				$field[2],
				1100 + $position,
				$field[2] === 'price' ? '24,8' : '',
				'adherent_type',
				0,
				0,
				'',
				$field[3],
				1,
				'',
				'1',
				$field[4],
				'',
				'',
				'vereine@vereine',
				'isModEnabled("vereine")'
			);
			if ($result <= 0) {
				$this->error = 'Extra field '.$field[0].': '.$extrafields->error;
				return -1;
			}
		}
		return 1;
	}

	/**
	 * Member types of this entity with their fee model.
	 *
	 * @param bool $activeOnly Only member types that are in use
	 * @return array<int,array<string,mixed>> By member type id, ordered by label; empty on error, see $error
	 */
	public function memberTypes($activeOnly = false)
	{
		// e.* instead of the field names: before the module is enabled again after an update,
		// the columns do not exist yet and the page still has to open.
		$sql = "SELECT t.rowid as type_id, t.libelle as type_label, t.statut as type_status, t.subscription as type_subscription,";
		$sql .= " t.amount as type_amount, t.caneditamount as type_caneditamount, t.duration as type_duration, t.morphy as type_morphy,";
		$sql .= " t.note as type_note, e.*";
		$sql .= " FROM ".MAIN_DB_PREFIX."adherent_type as t";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."adherent_type_extrafields as e ON e.fk_object = t.rowid";
		$sql .= " WHERE t.entity IN (".getEntity('member_type').")";
		if ($activeOnly) {
			$sql .= " AND t.statut = 1";
		}
		$sql .= " ORDER BY t.libelle, t.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$defaultProduct = getDolGlobalInt('ADHERENT_PRODUCT_ID_FOR_SUBSCRIPTIONS');
		$types = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$duration = (string) $obj->type_duration;
			$field = self::FIELD_PRODUCT;
			$ownProduct = isset($obj->$field) ? (int) $obj->$field : 0;
			$types[(int) $obj->type_id] = array(
				'id' => (int) $obj->type_id,
				'label' => (string) $obj->type_label,
				'active' => (int) $obj->type_status === 1,
				'subscription' => (int) $obj->type_subscription === 1,
				'amount_editable' => (int) $obj->type_caneditamount === 1,
				'morphy' => (string) $obj->type_morphy,
				'note' => (string) $obj->type_note,
				'product_id' => $ownProduct > 0 ? $ownProduct : $defaultProduct,
				'product_own' => $ownProduct > 0,
				'model' => VereineFeeRules::normalize(array(
					'amount' => $obj->type_amount,
					'duration_value' => (int) substr($duration, 0, -1),
					'duration_unit' => substr($duration, -1),
					'start_month' => $this->extraValue($obj, self::FIELD_START_MONTH),
					'prorated' => $this->extraValue($obj, self::FIELD_PRORATED),
					'admission_fee' => $this->extraValue($obj, self::FIELD_ADMISSION),
				)),
			);
		}
		$this->db->free($resql);
		return $types;
	}

	/**
	 * Label and tax profile of the products used for fees.
	 *
	 * @param int[] $productIds Product ids
	 * @return array<int,array{ref:string,label:string,taxprofile:string}>
	 */
	public function products(array $productIds)
	{
		$ids = array_values(array_filter(array_unique(array_map('intval', $productIds))));
		if (!$ids) {
			return array();
		}
		$sql = "SELECT p.rowid, p.ref, p.label, tp.label as taxprofile";
		$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields as pe ON pe.fk_object = p.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."vereine_taxprofile as tp ON tp.rowid = pe.vereine_taxprofile";
		$sql .= " WHERE p.rowid IN (".implode(', ', $ids).")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$products = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$products[(int) $obj->rowid] = array('ref' => (string) $obj->ref, 'label' => (string) $obj->label, 'taxprofile' => (string) $obj->taxprofile);
		}
		$this->db->free($resql);
		return $products;
	}

	/**
	 * Value of an extra field in a row, or null when the column does not exist yet.
	 *
	 * @param object $row   Database row
	 * @param string $field Field name
	 * @return mixed
	 */
	private function extraValue($row, $field)
	{
		return isset($row->$field) ? $row->$field : null;
	}
}
