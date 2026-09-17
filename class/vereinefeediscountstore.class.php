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
 * \file    class/vereinefeediscountstore.class.php
 * \ingroup vereine
 * \brief   Fee discount rules in llx_vereine_fee_discount, the member fields for exemption and proof.
 */

require_once __DIR__.'/vereinefeediscounts.class.php';

/**
 * Stores discount rules and reads what a member brings to them.
 */
class VereineFeeDiscountStore
{
	/** Member is exempt from fees. */
	const FIELD_EXEMPT = 'vereine_fee_exempt';
	/** Why the member is exempt. */
	const FIELD_EXEMPT_REASON = 'vereine_fee_exempt_reason';
	/** Discount rule with proof the member has. */
	const FIELD_PROOF = 'vereine_fee_proof';
	/** Last day the proof is valid. */
	const FIELD_PROOF_UNTIL = 'vereine_fee_proof_until';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last database error
	 */
	public $error = '';

	/**
	 * @var string[] Language keys of the last refused rule
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
	 * Register the member fields for exemption and proof. Existing fields and their values stay.
	 *
	 * @return int 1 if OK, <0 on error
	 */
	public function ensureFields()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$proofs = array('options' => array('vereine_fee_discount:label:rowid::(kind:=:\'proof\') AND (active:=:1) AND (entity:=:$ENTITY$)' => null));
		$fields = array(
			array(self::FIELD_EXEMPT, 'VereineDiscountExempt', 'boolean', '', '', 'VereineDiscountExemptHelp'),
			array(self::FIELD_EXEMPT_REASON, 'VereineDiscountExemptReason', 'varchar', '', '128', 'VereineDiscountExemptReasonHelp'),
			array(self::FIELD_PROOF, 'VereineDiscountProof', 'sellist', $proofs, '', 'VereineDiscountProofHelp'),
			array(self::FIELD_PROOF_UNTIL, 'VereineDiscountProofUntil', 'date', '', '', 'VereineDiscountProofUntilHelp'),
		);
		foreach ($fields as $position => $field) {
			$result = $extrafields->addExtraField(
				$field[0],
				$field[1],
				$field[2],
				1200 + $position,
				$field[4],
				'adherent',
				0,
				0,
				'',
				$field[3],
				1,
				'',
				'1',
				$field[5],
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
	 * Rules of the current entity in their order.
	 *
	 * @param bool $activeOnly Only active rules
	 * @return array<int,array{id:int,label:string,kind:string,type_id:int,age_from:string,age_to:string,mode:string,value:float,active:bool,position:int}>
	 */
	public function fetchAll($activeOnly = false)
	{
		global $conf;

		$sql = "SELECT rowid, label, kind, fk_adherent_type, age_from, age_to, mode, value, active, position FROM ".MAIN_DB_PREFIX."vereine_fee_discount";
		$sql .= " WHERE entity = ".((int) $conf->entity).($activeOnly ? " AND active = 1" : "");
		$sql .= " ORDER BY position ASC, rowid ASC";
		$rules = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $rules;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$rules[] = array(
				'id' => (int) $obj->rowid,
				'label' => (string) $obj->label,
				'kind' => (string) $obj->kind,
				'type_id' => (int) $obj->fk_adherent_type,
				'age_from' => $obj->age_from === null ? '' : (string) (int) $obj->age_from,
				'age_to' => $obj->age_to === null ? '' : (string) (int) $obj->age_to,
				'mode' => (string) $obj->mode,
				'value' => (float) $obj->value,
				'active' => (int) $obj->active === 1,
				'position' => (int) $obj->position,
			);
		}
		$this->db->free($resql);
		return $rules;
	}

	/**
	 * One rule.
	 *
	 * @param int $id Rule id
	 * @return array<string,mixed>|null
	 */
	public function fetch($id)
	{
		foreach ($this->fetchAll() as $rule) {
			if ($rule['id'] === (int) $id) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Store a new rule or change one.
	 *
	 * @param int                 $id   Rule id, 0 for a new one
	 * @param array<string,mixed> $data Fields label, kind, type_id, age_from, age_to, mode, value, active
	 * @param User                $user User
	 * @return int Id, 0 when refused (see $errors), <0 on database error
	 */
	public function save($id, array $data, $user)
	{
		global $conf;

		$this->errors = VereineFeeDiscounts::validate($data);
		if ($this->errors) {
			return 0;
		}
		$age = function ($key) use ($data) {
			if ($data['kind'] !== VereineFeeDiscounts::KIND_AGE) {
				return "NULL";
			}
			$value = VereineFeeDiscounts::optionalAge($data[$key]);
			return $value === null ? "NULL" : (string) $value;
		};
		$value = $data['mode'] === VereineFeeDiscounts::MODE_FREE ? 0.0 : round((float) $data['value'], 2);
		$userId = is_object($user) && (int) $user->id > 0 ? (int) $user->id : "NULL";
		if ((int) $id > 0) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_fee_discount SET";
			$sql .= " label = '".$this->db->escape(trim((string) $data['label']))."', kind = '".$this->db->escape($data['kind'])."',";
			$sql .= " fk_adherent_type = ".((int) $data['type_id']).", age_from = ".$age('age_from').", age_to = ".$age('age_to').",";
			$sql .= " mode = '".$this->db->escape($data['mode'])."', value = ".$value.", active = ".(empty($data['active']) ? 0 : 1).", fk_user_modif = ".$userId;
			$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			return (int) $id;
		}
		$position = (int) $this->maxPosition() + 10;
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_fee_discount (entity, label, kind, fk_adherent_type, age_from, age_to, mode, value, active, position, datec, fk_user_modif)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape(trim((string) $data['label']))."', '".$this->db->escape($data['kind'])."',";
		$sql .= " ".((int) $data['type_id']).", ".$age('age_from').", ".$age('age_to').", '".$this->db->escape($data['mode'])."', ".$value.",";
		$sql .= " ".(empty($data['active']) ? 0 : 1).", ".$position.", '".$this->db->idate(dol_now())."', ".$userId.")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_fee_discount');
	}

	/**
	 * What members bring to discounts: exemption, proof, birth date.
	 *
	 * @param int[] $memberIds Member ids
	 * @return array<int,array{exempt:bool,exempt_reason:string,proof_rule:int,proof_until:string,birth:string}>
	 */
	public function memberData(array $memberIds)
	{
		$ids = array_values(array_filter(array_map('intval', $memberIds)));
		if (!$ids) {
			return array();
		}
		// e.* instead of the field names: the columns exist only after the module was enabled with 0.3.7.
		$sql = "SELECT d.rowid as member_id, d.birth, e.* FROM ".MAIN_DB_PREFIX."adherent as d";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."adherent_extrafields as e ON e.fk_object = d.rowid";
		$sql .= " WHERE d.rowid IN (".implode(', ', $ids).")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$members = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$members[(int) $obj->member_id] = array(
				'exempt' => !empty($obj->{self::FIELD_EXEMPT}),
				'exempt_reason' => isset($obj->{self::FIELD_EXEMPT_REASON}) ? (string) $obj->{self::FIELD_EXEMPT_REASON} : '',
				'proof_rule' => isset($obj->{self::FIELD_PROOF}) ? (int) $obj->{self::FIELD_PROOF} : 0,
				'proof_until' => isset($obj->{self::FIELD_PROOF_UNTIL}) ? substr((string) $obj->{self::FIELD_PROOF_UNTIL}, 0, 10) : '',
				'birth' => $obj->birth ? substr((string) $obj->birth, 0, 10) : '',
			);
		}
		$this->db->free($resql);
		return $members;
	}

	/**
	 * Highest position of the rules of this entity.
	 *
	 * @return int
	 */
	private function maxPosition()
	{
		global $conf;

		$resql = $this->db->query("SELECT MAX(position) as position FROM ".MAIN_DB_PREFIX."vereine_fee_discount WHERE entity = ".((int) $conf->entity));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? (int) $obj->position : 0;
	}
}
