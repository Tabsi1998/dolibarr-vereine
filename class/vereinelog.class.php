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
 * \file    class/vereinelog.class.php
 * \ingroup vereine
 * \brief   The module's log: what it changed automatically or on request, by whom and when.
 *
 * The table llx_vereine_log is only ever appended to; nothing in the module
 * updates or deletes a row.
 */

/**
 * Append and read entries of llx_vereine_log.
 */
class VereineLog
{
	const PARTNER_CREATED = 'partner_created';
	const PARTNER_LINKED = 'partner_linked';
	const PARTNER_SUGGESTED = 'partner_suggested';
	const PARTNER_ATTRIBUTES = 'partner_attributes';
	const PARTNER_UPDATED = 'partner_updated';
	const PARTNER_ERROR = 'partner_error';
	const PARTNER_UNLINKED = 'partner_unlinked';
	const FEE_INVOICE = 'fee_invoice';
	const FEE_RUN = 'fee_run';
	const FEE_ERROR = 'fee_error';

	/**
	 * Append one entry.
	 *
	 * @param DoliDB    $db        Database handler
	 * @param User|null $user      Who acted, null for the system
	 * @param string    $action    One of the constants
	 * @param int       $memberId  Member concerned, 0 for none
	 * @param int       $socid     Third party concerned, 0 for none
	 * @param string    $message   Short text, at most 255 characters
	 * @return int Id of the entry, <0 on error
	 */
	public static function add($db, $user, $action, $memberId, $socid, $message)
	{
		global $conf;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_log (entity, datec, fk_user, action, fk_adherent, fk_soc, message)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$db->idate(dol_now())."', ";
		$sql .= (is_object($user) && (int) $user->id > 0 ? (int) $user->id : "NULL").", ";
		$sql .= "'".$db->escape(substr((string) $action, 0, 64))."', ";
		$sql .= ((int) $memberId > 0 ? (int) $memberId : "NULL").", ";
		$sql .= ((int) $socid > 0 ? (int) $socid : "NULL").", ";
		$sql .= "'".$db->escape(dol_trunc((string) $message, 250, 'right', 'UTF-8', 1))."')";
		if (!$db->query($sql)) {
			dol_syslog('VereineLog::add '.$db->lasterror(), LOG_ERR);
			return -1;
		}
		return (int) $db->last_insert_id(MAIN_DB_PREFIX.'vereine_log');
	}

	/**
	 * The newest entries for a member or a third party.
	 *
	 * @param DoliDB $db       Database handler
	 * @param int    $memberId Member, 0 for any
	 * @param int    $socid    Third party, 0 for any
	 * @param int    $limit    Maximum number of entries
	 * @return array<int,array{date:int,user:int,action:string,member:int,partner:int,message:string}>
	 */
	public static function recent($db, $memberId, $socid, $limit = 20)
	{
		global $conf;

		$where = array("entity = ".((int) $conf->entity));
		$either = array();
		if ((int) $memberId > 0) {
			$either[] = "fk_adherent = ".((int) $memberId);
		}
		if ((int) $socid > 0) {
			$either[] = "fk_soc = ".((int) $socid);
		}
		if ($either) {
			$where[] = "(".implode(" OR ", $either).")";
		}
		$sql = "SELECT datec, fk_user, action, fk_adherent, fk_soc, message FROM ".MAIN_DB_PREFIX."vereine_log";
		$sql .= " WHERE ".implode(" AND ", $where)." ORDER BY datec DESC, rowid DESC";
		$sql .= $db->plimit((int) $limit, 0);
		$entries = array();
		$resql = $db->query($sql);
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$entries[] = array(
				'date' => (int) $db->jdate($obj->datec),
				'user' => (int) $obj->fk_user,
				'action' => (string) $obj->action,
				'member' => (int) $obj->fk_adherent,
				'partner' => (int) $obj->fk_soc,
				'message' => (string) $obj->message,
			);
		}
		return $entries;
	}
}
