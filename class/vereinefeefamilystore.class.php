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
 * \file    class/vereinefeefamilystore.class.php
 * \ingroup vereine
 * \brief   The payer field on members, the family rule and what families were charged.
 */

require_once __DIR__.'/vereinefeefamilies.class.php';
require_once __DIR__.'/vereinefeerules.class.php';

/**
 * Reads and stores what families need.
 */
class VereineFeeFamilyStore
{
	/** Third party that pays the member's fees. */
	const FIELD_PAYER = 'vereine_fee_payer';
	/** Family rule, one of VereineFeeFamilies::MODE_*. */
	const CONST_MODE = 'VEREINE_FEE_FAMILY_MODE';
	/** Percentage or amount of the family rule. */
	const CONST_VALUE = 'VEREINE_FEE_FAMILY_VALUE';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last database error
	 */
	public $error = '';

	/**
	 * @var string[] Language keys of the last refused family rule
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
	 * Register the payer field on members. An existing field and its values stay.
	 *
	 * @return int 1 if OK, <0 on error
	 */
	public function ensureFields()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$result = $extrafields->addExtraField(
			self::FIELD_PAYER,
			'VereineFamilyPayer',
			'link',
			1210,
			'',
			'adherent',
			0,
			0,
			'',
			array('options' => array('Societe:societe/class/societe.class.php' => null)),
			1,
			'',
			'1',
			'VereineFamilyPayerHelp',
			'',
			'',
			'vereine@vereine',
			'isModEnabled("vereine")'
		);
		if ($result <= 0) {
			$this->error = 'Extra field '.self::FIELD_PAYER.': '.$extrafields->error;
			return -1;
		}
		return 1;
	}

	/**
	 * The family rule of this entity.
	 *
	 * @return array{mode:string,value:float}
	 */
	public function setting()
	{
		return VereineFeeFamilies::normalize(getDolGlobalString(self::CONST_MODE, VereineFeeFamilies::MODE_NONE), getDolGlobalString(self::CONST_VALUE));
	}

	/**
	 * Store the family rule.
	 *
	 * @param string $mode  One of VereineFeeFamilies::MODE_*
	 * @param mixed  $value Percentage or amount
	 * @return int 1 if stored, 0 when refused (see $errors), <0 on database error
	 */
	public function save($mode, $value)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$this->errors = VereineFeeFamilies::validate($mode, $value);
		if ($this->errors) {
			return 0;
		}
		$setting = VereineFeeFamilies::normalize($mode, $value);
		if (dolibarr_set_const($this->db, self::CONST_MODE, $setting['mode'], 'chaine', 0, '', $conf->entity) < 0
			|| dolibarr_set_const($this->db, self::CONST_VALUE, (string) $setting['value'], 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Active members of this entity with what makes them a family.
	 *
	 * @return array<int,array{id:int,name:string,type_id:int,socid:int,payer:int,payer_socid:int}> By member id, ordered by id;
	 *         payer is the third party named on the member, payer_socid the one that gets the invoices
	 */
	public function members()
	{
		// e.* instead of the field name: the column exists only after the module was enabled with 0.3.8.
		$sql = "SELECT d.rowid as member_id, d.firstname, d.lastname, d.societe, d.morphy, d.fk_adherent_type, d.fk_soc, e.*";
		$sql .= " FROM ".MAIN_DB_PREFIX."adherent as d";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."adherent_extrafields as e ON e.fk_object = d.rowid";
		$sql .= " WHERE d.entity IN (".getEntity('adherent').") AND d.statut = 1";
		$sql .= " ORDER BY d.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$members = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$payer = isset($obj->{self::FIELD_PAYER}) ? max(0, (int) $obj->{self::FIELD_PAYER}) : 0;
			$members[(int) $obj->member_id] = array(
				'id' => (int) $obj->member_id,
				'name' => $obj->morphy === 'mor' && (string) $obj->societe !== '' ? (string) $obj->societe : trim($obj->firstname.' '.$obj->lastname),
				'type_id' => (int) $obj->fk_adherent_type,
				'socid' => (int) $obj->fk_soc,
				'payer' => $payer,
				'payer_socid' => VereineFeeFamilies::payerOf((int) $obj->fk_soc, $payer),
			);
		}
		$this->db->free($resql);
		return $members;
	}

	/**
	 * The third party named as payer on one member.
	 *
	 * @param int $memberId Member id
	 * @return int 0 for none
	 */
	public function payerOfMember($memberId)
	{
		$resql = $this->db->query("SELECT e.* FROM ".MAIN_DB_PREFIX."adherent_extrafields as e WHERE e.fk_object = ".((int) $memberId));
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return 0;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return ($obj && isset($obj->{self::FIELD_PAYER})) ? max(0, (int) $obj->{self::FIELD_PAYER}) : 0;
	}

	/**
	 * Names of third parties that exist.
	 *
	 * @param int[] $socids Third party ids
	 * @return array<int,string> Name by id; a missing id was deleted
	 */
	public function thirdPartyNames(array $socids)
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $socids))));
		if (!$ids) {
			return array();
		}
		$resql = $this->db->query("SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE rowid IN (".implode(', ', $ids).") AND entity IN (".getEntity('societe').")");
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$names = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$names[(int) $obj->rowid] = (string) $obj->nom;
		}
		$this->db->free($resql);
		return $names;
	}

	/**
	 * Fees already charged to members for periods starting within some days.
	 *
	 * Counts the amount of every subscription period, except those whose fee invoice was abandoned.
	 * A stored start counts for the nearest midnight, see VereineMemberSummary::dayOf().
	 *
	 * @param int[]  $memberIds Members
	 * @param string $from      First day, YYYY-MM-DD
	 * @param string $until     Last day, YYYY-MM-DD
	 * @return float
	 */
	public function charged(array $memberIds, $from, $until)
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

		$ids = array_values(array_filter(array_map('intval', $memberIds)));
		if (!$ids) {
			return 0.0;
		}
		$sql = "SELECT SUM(s.subscription) as charged FROM ".MAIN_DB_PREFIX."subscription as s";
		$sql .= " WHERE s.fk_adherent IN (".implode(', ', $ids).")";
		$sql .= " AND s.dateadh >= '".$this->db->escape(VereineFeeRules::addDays($from, -1))." 12:00:00' AND s.dateadh < '".$this->db->escape($until)." 12:00:00'";
		$sql .= " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."element_element as ee INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = ee.fk_target";
		$sql .= " WHERE ee.fk_source = s.rowid AND ee.sourcetype = 'subscription' AND ee.targettype = 'facture' AND f.fk_statut = ".((int) Facture::STATUS_ABANDONED).")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return 0.0;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? round((float) $obj->charged, 2) : 0.0;
	}

	/**
	 * Families of at least two active members, by payer.
	 *
	 * @return array<int,array{socid:int,name:string,exists:bool,members:array<int,array<string,mixed>>}> Ordered by payer name
	 */
	public function families()
	{
		$groups = array();
		foreach ($this->members() as $member) {
			if ($member['payer_socid'] > 0) {
				$groups[$member['payer_socid']][] = $member;
			}
		}
		$groups = array_filter($groups, function ($members) {
			return count($members) >= 2;
		});
		$names = $this->thirdPartyNames(array_keys($groups));
		$families = array();
		foreach ($groups as $socid => $members) {
			$families[] = array('socid' => (int) $socid, 'name' => isset($names[$socid]) ? $names[$socid] : '', 'exists' => isset($names[$socid]), 'members' => $members);
		}
		usort($families, function ($left, $right) {
			return strcasecmp($left['name'], $right['name']) ?: $left['socid'] - $right['socid'];
		});
		return $families;
	}
}
