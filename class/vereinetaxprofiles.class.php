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
 * \file    class/vereinetaxprofiles.class.php
 * \ingroup vereine
 * \brief   Stores the association's tax profiles in llx_vereine_taxprofile.
 */

require_once __DIR__.'/vereinetaxrules.class.php';

/**
 * Read and write tax profiles. Every write is checked by VereineTaxRules::validate().
 */
class VereineTaxProfiles
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
	 * @var string[] Language keys of the last refused profile
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
	 * All profiles of the current entity, in their order.
	 *
	 * @param bool $activeOnly Only active profiles
	 * @return array<int,array{id:int,code:string,label:string,sphere:string,treatment:string,rate:float,note:string,active:bool,standard:bool,position:int}>
	 */
	public function fetchAll($activeOnly = false)
	{
		global $conf;

		$sql = "SELECT rowid, code, label, sphere, treatment, rate, note, active, standard, position FROM ".MAIN_DB_PREFIX."vereine_taxprofile";
		$sql .= " WHERE entity = ".((int) $conf->entity).($activeOnly ? " AND active = 1" : "");
		$sql .= " ORDER BY position ASC, code ASC";
		$profiles = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$profiles[] = array(
				'id' => (int) $obj->rowid,
				'code' => (string) $obj->code,
				'label' => (string) $obj->label,
				'sphere' => (string) $obj->sphere,
				'treatment' => (string) $obj->treatment,
				'rate' => (float) $obj->rate,
				'note' => (string) $obj->note,
				'active' => (int) $obj->active === 1,
				'standard' => (int) $obj->standard === 1,
				'position' => (int) $obj->position,
			);
		}
		return $profiles;
	}

	/**
	 * One profile.
	 *
	 * @param int $id Profile id
	 * @return array<string,mixed>|null
	 */
	public function fetch($id)
	{
		foreach ($this->fetchAll() as $profile) {
			if ($profile['id'] === (int) $id) {
				return $profile;
			}
		}
		return null;
	}

	/**
	 * Store a new profile.
	 *
	 * @param array<string,mixed> $data Fields code, label, sphere, treatment, rate, note, active
	 * @param User                $user User
	 * @return int Id, 0 when refused (see $errors), <0 on database error
	 */
	public function create(array $data, $user)
	{
		global $conf;

		$this->errors = VereineTaxRules::validate($data);
		if (!$this->errors && $this->codeExists((string) $data['code'], 0)) {
			$this->errors[] = 'VereineTaxErrorCodeExists';
		}
		if ($this->errors) {
			return 0;
		}
		$position = (int) $this->value("SELECT MAX(position) FROM ".MAIN_DB_PREFIX."vereine_taxprofile WHERE entity = ".((int) $conf->entity)) + 10;
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_taxprofile (entity, code, label, sphere, treatment, rate, note, active, standard, position, datec, fk_user_modif)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape((string) $data['code'])."', '".$this->db->escape(trim((string) $data['label']))."',";
		$sql .= " '".$this->db->escape((string) $data['sphere'])."', '".$this->db->escape((string) $data['treatment'])."', ".((float) $data['rate']).",";
		$sql .= " '".$this->db->escape(trim((string) $data['note']))."', ".(empty($data['active']) ? 0 : 1).", ".(empty($data['standard']) ? 0 : 1).", ".$position.",";
		$sql .= " '".$this->db->idate(dol_now())."', ".(is_object($user) && (int) $user->id > 0 ? (int) $user->id : "NULL").")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_taxprofile');
	}

	/**
	 * Change a profile. The code stays: it identifies the profile for the API and later assignments.
	 *
	 * @param int                 $id   Profile id
	 * @param array<string,mixed> $data Fields label, sphere, treatment, rate, note, active
	 * @param User                $user User
	 * @return int 1 if stored, 0 when refused (see $errors), <0 on error
	 */
	public function update($id, array $data, $user)
	{
		global $conf;

		$current = $this->fetch($id);
		if (!$current) {
			$this->error = 'Tax profile '.((int) $id).' not found';
			return -1;
		}
		$data['code'] = $current['code'];
		$this->errors = VereineTaxRules::validate($data);
		if ($this->errors) {
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_taxprofile SET";
		$sql .= " label = '".$this->db->escape(trim((string) $data['label']))."',";
		$sql .= " sphere = '".$this->db->escape((string) $data['sphere'])."',";
		$sql .= " treatment = '".$this->db->escape((string) $data['treatment'])."',";
		$sql .= " rate = ".((float) $data['rate']).",";
		$sql .= " note = '".$this->db->escape(trim((string) $data['note']))."',";
		$sql .= " active = ".(empty($data['active']) ? 0 : 1).",";
		$sql .= " fk_user_modif = ".(is_object($user) && (int) $user->id > 0 ? (int) $user->id : "NULL");
		$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Add the standard profiles whose code is missing. Existing profiles are never changed.
	 *
	 * @param Translate $langs Languages for labels and notes
	 * @param User      $user  User
	 * @return int Number of profiles added, <0 on error
	 */
	public function ensureStandard($langs, $user)
	{
		$added = 0;
		foreach (VereineTaxRules::standardProfiles() as $standard) {
			if ($this->codeExists($standard['code'], 0)) {
				continue;
			}
			$data = array(
				'code' => $standard['code'],
				'label' => $langs->transnoentitiesnoconv($standard['label']),
				'sphere' => $standard['sphere'],
				'treatment' => $standard['treatment'],
				'rate' => VereineTaxRules::rateOf($standard['treatment']),
				'note' => $standard['note'] !== '' ? $langs->transnoentitiesnoconv($standard['note']) : '',
				'active' => $standard['active'],
				'standard' => 1,
			);
			$result = $this->create($data, $user);
			if ($result <= 0) {
				$this->error = $result < 0 ? $this->error : 'Standard profile '.$standard['code'].': '.implode(', ', $this->errors);
				return -1;
			}
			$added++;
		}
		return $added;
	}

	/**
	 * Whether Dolibarr's VAT dictionary has an active rate for Austria.
	 *
	 * @param float $rate Rate in percent
	 * @return bool
	 */
	public function austrianRateExists($rate)
	{
		$sql = "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."c_tva as t INNER JOIN ".MAIN_DB_PREFIX."c_country as c ON c.rowid = t.fk_pays";
		$sql .= " WHERE c.code = 'AT' AND t.taux = ".((float) $rate)." AND t.active = 1 AND t.entity IN (".getEntity('c_tva').")";
		return (int) $this->value($sql) > 0;
	}

	/**
	 * Add the reduced rate of 13 % (§ 10 (3) UStG) to Dolibarr's VAT dictionary for Austria.
	 *
	 * Dolibarr 22 to 24 ship 0, 10 and 20 % only. A switched-off entry is switched on again.
	 *
	 * @return int 1 when added or switched on, 0 when it was there already, <0 on error
	 */
	public function addAustrianRate13()
	{
		global $conf;

		if ($this->austrianRateExists(13)) {
			return 0;
		}
		$countryId = (int) $this->value("SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");
		if ($countryId <= 0) {
			$this->error = 'Country AT not found';
			return -1;
		}
		$where = " WHERE entity = ".((int) $conf->entity)." AND fk_pays = ".$countryId." AND code = '' AND taux = 13 AND recuperableonly = 0";
		if ((int) $this->value("SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."c_tva".$where) > 0) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."c_tva SET active = 1".$where;
		} else {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_tva (entity, fk_pays, code, taux, localtax1, localtax1_type, localtax2, localtax2_type, recuperableonly, note, active)";
			$sql .= " VALUES (".((int) $conf->entity).", ".$countryId.", '', 13, '0', '0', '0', '0', 0, 'VAT rate - reduced 13 % (added by Vereine)', 1)";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Whether a code is taken in the current entity.
	 *
	 * @param string $code      Profile code
	 * @param int    $exceptId  Profile to ignore
	 * @return bool
	 */
	private function codeExists($code, $exceptId)
	{
		global $conf;

		$sql = "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."vereine_taxprofile WHERE entity = ".((int) $conf->entity);
		$sql .= " AND code = '".$this->db->escape((string) $code)."' AND rowid <> ".((int) $exceptId);
		return (int) $this->value($sql) > 0;
	}

	/**
	 * First column of the first row.
	 *
	 * @param string $sql Query
	 * @return string|null
	 */
	private function value($sql)
	{
		$resql = $this->db->query($sql);
		if ($resql && ($row = $this->db->fetch_row($resql))) {
			return $row[0] === null ? null : (string) $row[0];
		}
		return null;
	}
}
