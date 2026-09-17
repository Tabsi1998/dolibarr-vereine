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
 * \file    class/vereinesepastore.class.php
 * \ingroup vereine
 * \brief   Mandates on the default bank account of third parties and when they were last used.
 */

require_once __DIR__.'/vereinesepa.class.php';

/**
 * Reads Dolibarr's mandates and direct debit requests.
 */
class VereineSepaStore
{
	/** Days of pre-notification. */
	const CONST_NOTICE_DAYS = 'VEREINE_SEPA_NOTICE_DAYS';

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
	 * Whether direct debits can be requested: Dolibarr's module Direct debit payment orders is on.
	 *
	 * @return bool
	 */
	public static function enabled()
	{
		return isModEnabled('prelevement');
	}

	/**
	 * Days of pre-notification.
	 *
	 * @return int
	 */
	public function noticeDays()
	{
		return VereineSepa::noticeDays(getDolGlobalString(self::CONST_NOTICE_DAYS, (string) VereineSepa::DEFAULT_NOTICE_DAYS));
	}

	/**
	 * Store the days of pre-notification.
	 *
	 * @param mixed $days Days, 1 to VereineSepa::MAX_NOTICE_DAYS
	 * @return int 1 if stored, 0 when refused, <0 on error
	 */
	public function saveNoticeDays($days)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		if (!preg_match('/^\d{1,2}$/', trim((string) $days)) || VereineSepa::noticeDays($days) !== (int) $days) {
			return 0;
		}
		if (dolibarr_set_const($this->db, self::CONST_NOTICE_DAYS, (string) (int) $days, 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Mandates of third parties: their default bank account for direct debits.
	 *
	 * @param int[]  $socids Third parties
	 * @param string $today  Today, YYYY-MM-DD
	 * @return array<int,array{status:string,rib_id:int,reference:string,signed_on:string,last_collection:string}> By third party; missing ones have no mandate
	 */
	public function mandates(array $socids, $today)
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $socids))));
		if (!$ids) {
			return array();
		}
		$collections = array();
		$sql = "SELECT f.fk_soc, MAX(pd.date_traite) as last_collection FROM ".MAIN_DB_PREFIX."prelevement_demande as pd";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = pd.fk_facture";
		$sql .= " WHERE f.fk_soc IN (".implode(', ', $ids).") AND pd.traite = 1 AND pd.type = 'ban' GROUP BY f.fk_soc";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$collections[(int) $obj->fk_soc] = $obj->last_collection ? substr((string) $obj->last_collection, 0, 10) : '';
			}
			$this->db->free($resql);
		}

		$sql = "SELECT rowid, fk_soc, rum, date_rum FROM ".MAIN_DB_PREFIX."societe_rib";
		$sql .= " WHERE fk_soc IN (".implode(', ', $ids).") AND type = 'ban' AND default_rib = 1";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$mandates = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$socid = (int) $obj->fk_soc;
			$signedOn = $obj->date_rum ? substr((string) $obj->date_rum, 0, 10) : '';
			$last = isset($collections[$socid]) ? $collections[$socid] : '';
			$mandates[$socid] = array(
				'status' => VereineSepa::mandateStatus((string) $obj->rum, $signedOn, $last, $today),
				'rib_id' => (int) $obj->rowid,
				'reference' => (string) $obj->rum,
				'signed_on' => $signedOn,
				'last_collection' => $last,
			);
		}
		$this->db->free($resql);
		return $mandates;
	}
}
