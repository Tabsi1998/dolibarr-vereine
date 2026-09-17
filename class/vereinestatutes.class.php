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
 * \file    class/vereinestatutes.class.php
 * \ingroup vereine
 * \brief   Stores the rules of the statutes and the terms of office of the functions.
 */

require_once __DIR__.'/vereinestatuterules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Rules of the statutes as a Dolibarr constant.
 */
class VereineStatutes
{
	/** Rules of the statutes as JSON. */
	const CONST_RULES = 'VEREINE_STATUTE_RULES';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last database error
	 */
	public $error = '';

	/**
	 * @var string[] Language keys of the last refused input
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
	 * The rules of the statutes, the defaults of the model statutes until the association stores its own.
	 *
	 * @return array<string,mixed>
	 */
	public function rules()
	{
		return VereineStatuteRules::normalize(json_decode(getDolGlobalString(self::CONST_RULES, '{}'), true));
	}

	/**
	 * Whether the association stored its own rules.
	 *
	 * @return bool
	 */
	public function stored()
	{
		return getDolGlobalString(self::CONST_RULES) !== '';
	}

	/**
	 * Store entered rules and terms of office.
	 *
	 * @param array<string,mixed> $data      Entered rules
	 * @param array<int,mixed>    $termYears Entered terms of office in years by function id
	 * @param User                $user      Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function save(array $data, array $termYears, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$this->errors = VereineStatuteRules::validate($data);
		foreach ($termYears as $years) {
			if (!VereineStatuteRules::isTermYears($years)) {
				$this->errors[] = 'VereineStatuteErrorTermYears';
				break;
			}
		}
		if ($this->errors) {
			return 0;
		}
		$rules = VereineStatuteRules::normalize($data);
		$this->db->begin();
		if (dolibarr_set_const($this->db, self::CONST_RULES, json_encode($rules), 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		foreach ($termYears as $functionId => $years) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_function SET term_years = ".((int) $years).", fk_user_modif = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $functionId)." AND entity = ".((int) $conf->entity)." AND term_years <> ".((int) $years);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::STATUTE_RULES, 0, 0, 'general_years='.$rules['general_years'].', invite_days='.$rules['invite_days']
			.', min_age='.$rules['min_age'].', virtual='.$rules['virtual']);
		return 1;
	}
}
