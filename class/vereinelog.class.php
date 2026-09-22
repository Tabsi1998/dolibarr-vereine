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
	const FEE_PERIOD = 'fee_period';
	const FEE_DIRECT_DEBIT = 'fee_direct_debit';
	const EXIT_PLANNED = 'exit_planned';
	const EXIT_DONE = 'exit_done';
	const EXIT_CANCELLED = 'exit_cancelled';
	const EXIT_ERROR = 'exit_error';
	const CONSENT_GIVEN = 'consent_given';
	const CONSENT_WITHDRAWN = 'consent_withdrawn';
	const APPLICATION_RECEIVED = 'application_received';
	const FUNCTION_START = 'function_start';
	const FUNCTION_END = 'function_end';
	const FUNCTION_REPORTED = 'function_reported';
	const FUNCTION_REPORT_PDF = 'function_report_pdf';
	const FUNCTION_GROUP_ADD = 'function_group_add';
	const FUNCTION_GROUP_REMOVE = 'function_group_remove';
	const STATUTE_RULES = 'statute_rules';
	const AUTHORITY_LETTER = 'authority_letter';
	const AUTHORITY_LETTER_FILED = 'authority_letter_filed';
	const STATUTE_TEXT = 'statute_text';
	const STATUTE_VERSION = 'statute_version';
	const MEETING_CREATED = 'meeting_created';
	const MEETING_INVITED = 'meeting_invited';
	const MEETING_STATUS = 'meeting_status';
	const MEETING_ATTENDANCE = 'meeting_attendance';
	const MEETING_VOTE = 'meeting_vote';
	const SIGNATURE_RULES = 'signature_rules';
	const QES_SETUP = 'qes_setup';
	const QES_SIGNED = 'qes_signed';
	const TAX_PROFILE_SET = 'tax_profile_set';
	const AUDIT_SAVED = 'audit_saved';
	const AUDIT_CHECKED = 'audit_checked';
	const AUDIT_REPORT = 'audit_report';
	const ACCOUNT_SAVED = 'account_saved';
	const ACCOUNT_PDF = 'account_pdf';
	const ACCOUNT_ASSIGNED = 'account_assigned';
	const APPLICATION_PDF = 'application_pdf';
	const CONSENT_FORM = 'consent_form';
	const CONSENT_SCAN = 'consent_scan';
	const SIGNATURE_STARTED = 'signature_started';
	const SIGNATURE_SIGNED = 'signature_signed';
	const SIGNATURE_DONE = 'signature_done';
	const MINUTES_FINAL = 'minutes_final';
	const MINUTES_SENT = 'minutes_sent';
	const RESOLUTION_ADDED = 'resolution_added';
	const RESOLUTION_SAVED = 'resolution_saved';
	const RESOLUTION_TASK = 'resolution_task';
	const RESOLUTION_TASK_DONE = 'resolution_task_done';
	const CIRCULAR_STARTED = 'circular_started';
	const CIRCULAR_VOTE = 'circular_vote';
	const CIRCULAR_REMINDED = 'circular_reminded';
	const CIRCULAR_DECIDED = 'circular_decided';
	const CIRCULAR_CANCELLED = 'circular_cancelled';
	const MEETING_DOCUMENT = 'meeting_document';

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
		$id = (int) $db->last_insert_id(MAIN_DB_PREFIX.'vereine_log');
		// What concerns a member or a third party also goes to Dolibarr's events of it, where its history is (#112).
		if (((int) $memberId > 0 || (int) $socid > 0) && isModEnabled('agenda')) {
			self::toEvent($db, $id, $user, (string) $action, (int) $memberId, (int) $socid, (string) $message, dol_now());
		}
		return $id;
	}

	/**
	 * Enter an entry as an automatic event of Dolibarr's agenda at the member, or at the third party.
	 *
	 * The event carries the same text as the entry, nothing more.
	 *
	 * @param DoliDB    $db       Database handler
	 * @param int       $logId    Entry
	 * @param User|null $user     Who acted; null takes the user of the request
	 * @param string    $action   One of the constants
	 * @param int       $memberId Member concerned, 0 for none
	 * @param int       $socid    Third party concerned, 0 for none
	 * @param string    $message  Text of the entry
	 * @param int       $date     When it happened
	 * @return int Event, 0 when none was made
	 */
	public static function toEvent($db, $logId, $user, $action, $memberId, $socid, $message, $date)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		if (!is_object($user) || (int) $user->id <= 0) {
			$user = isset($GLOBALS['user']) && is_object($GLOBALS['user']) && (int) $GLOBALS['user']->id > 0 ? $GLOBALS['user'] : null;
		}
		if ($user === null) {
			return 0;
		}
		if ($memberId > 0 && $socid <= 0) {
			$resql = $db->query("SELECT fk_soc FROM ".MAIN_DB_PREFIX."adherent WHERE rowid = ".((int) $memberId));
			$obj = $resql ? $db->fetch_object($resql) : null;
			$socid = $obj ? (int) $obj->fk_soc : 0;
		}
		$langs->load('vereine@vereine');
		$event = new ActionComm($db);
		$event->type_code = 'AC_OTH_AUTO';
		// Own prefix: Dolibarr's agenda writes AC_VEREINE_MEMBER_CHANGED for the module's webhook trigger.
		$event->code = 'AC_VEREINE_LOG_'.strtoupper(substr((string) $action, 0, 34));
		$event->label = dol_trunc($langs->transnoentitiesnoconv('VereineLog_'.$action), 250, 'right', 'UTF-8', 1);
		$event->note_private = (string) $message;
		$event->datep = (int) $date;
		$event->datef = (int) $date;
		$event->durationp = 0;
		$event->fulldayevent = 0;
		$event->percentage = -1;
		$event->socid = $socid > 0 ? $socid : 0;
		$event->authorid = (int) $user->id;
		$event->userownerid = (int) $user->id;
		if ($memberId > 0) {
			$event->elementtype = 'member';
			$event->fk_element = $memberId;
		} elseif ($socid > 0) {
			$event->elementtype = 'societe';
			$event->fk_element = $socid;
		}
		$eventId = (int) $event->create($user, 1);
		if ($eventId <= 0) {
			dol_syslog('VereineLog::toEvent '.$event->error, LOG_WARNING);
			return 0;
		}
		$db->query("UPDATE ".MAIN_DB_PREFIX."vereine_log SET fk_actioncomm = ".$eventId." WHERE rowid = ".((int) $logId));
		return $eventId;
	}

	/**
	 * Enter every earlier entry about a member or a third party as an event, once: an entry that has its
	 * event is never entered again (#112).
	 *
	 * @param DoliDB $db Database handler
	 * @return int Number of events made, -1 on error
	 */
	public static function migrateToEvents($db)
	{
		global $conf;

		$sql = "SELECT rowid, datec, fk_user, action, fk_adherent, fk_soc, message FROM ".MAIN_DB_PREFIX."vereine_log";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_actioncomm IS NULL AND (fk_adherent > 0 OR fk_soc > 0) ORDER BY rowid";
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog('VereineLog::migrateToEvents '.$db->lasterror(), LOG_ERR);
			return -1;
		}
		$rows = array();
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$actors = array();
		$made = 0;
		foreach ($rows as $obj) {
			$userId = (int) $obj->fk_user;
			if ($userId > 0 && !array_key_exists($userId, $actors)) {
				$actor = new User($db);
				$actors[$userId] = $actor->fetch($userId) > 0 ? $actor : null;
			}
			if (self::toEvent($db, (int) $obj->rowid, $userId > 0 ? $actors[$userId] : null, (string) $obj->action, (int) $obj->fk_adherent, (int) $obj->fk_soc,
				(string) $obj->message, (int) $db->jdate($obj->datec)) > 0) {
				$made++;
			}
		}
		return $made;
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
