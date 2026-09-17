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
 * \file    class/vereinewebsiteevents.class.php
 * \ingroup vereine
 * \brief   A slim event for Dolibarr's webhooks when a member's summary may have changed.
 *
 * Dolibarr's webhook module sends the whole object of an event, for a member with birth
 * date, address and notes, and from Dolibarr 23 on keeps it in its history. The module
 * therefore raises its own event VEREINE_MEMBER_CHANGED, whose object holds the member id
 * and the cause only. The website reads the summary through the API afterwards.
 */

/**
 * What a webhook receives: the member id and what happened, nothing personal.
 */
class VereineMemberEvent
{
	/** @var int Member id; Dolibarr's webhook logs it as the object id */
	public $id;
	/** @var string Kind of object for Dolibarr's triggers */
	public $element = 'vereine_member_event';
	/** @var int Member id */
	public $member_id;
	/** @var string Dolibarr event that caused it, such as BILL_PAYED */
	public $cause;
	/** @var string Moment in UTC, ISO 8601 */
	public $occurred_at;
	/** @var array<string,mixed> Read by Dolibarr's webhook trigger */
	public $context = array();
}

/**
 * Turns Dolibarr's events on members, subscriptions, invoices and payments into VEREINE_MEMBER_CHANGED.
 */
class VereineWebsiteEvents
{
	/** The event a webhook subscribes to. */
	const TRIGGER_CODE = 'VEREINE_MEMBER_CHANGED';

	/** Events whose object is the member. */
	const MEMBER_EVENTS = array('MEMBER_CREATE', 'MEMBER_VALIDATE', 'MEMBER_MODIFY', 'MEMBER_RESILIATE', 'MEMBER_EXCLUDE', 'MEMBER_DELETE');
	/** Events whose object is a subscription period of the member. */
	const SUBSCRIPTION_EVENTS = array('MEMBER_SUBSCRIPTION_CREATE', 'MEMBER_SUBSCRIPTION_MODIFY', 'MEMBER_SUBSCRIPTION_DELETE');
	/** Events whose object is a customer invoice of the member's third party or linked to its subscription periods. */
	const INVOICE_EVENTS = array('BILL_VALIDATE', 'BILL_UNVALIDATE', 'BILL_MODIFY', 'BILL_PAYED', 'BILL_UNPAYED', 'BILL_CANCEL', 'BILL_DELETE');
	/** Events whose object is a customer payment on such invoices. */
	const PAYMENT_EVENTS = array('PAYMENT_CUSTOMER_CREATE', 'PAYMENT_CUSTOMER_DELETE');

	/**
	 * @var array<int,bool> Members already announced in this request: one action raises several events
	 */
	private static $announced = array();

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

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
	 * Whether a Dolibarr event can change a member's summary.
	 *
	 * @param string $action Event code
	 * @return bool
	 */
	public static function handles($action)
	{
		return in_array($action, array_merge(self::MEMBER_EVENTS, self::SUBSCRIPTION_EVENTS, self::INVOICE_EVENTS, self::PAYMENT_EVENTS), true);
	}

	/**
	 * Members of an event not yet announced in this request, and mark them as announced.
	 *
	 * @param int[] $memberIds Members of the event
	 * @return int[]
	 */
	public static function notYetAnnounced(array $memberIds)
	{
		$new = array();
		foreach ($memberIds as $id) {
			$id = (int) $id;
			if ($id > 0 && empty(self::$announced[$id])) {
				self::$announced[$id] = true;
				$new[] = $id;
			}
		}
		return $new;
	}

	/**
	 * Raise VEREINE_MEMBER_CHANGED for every member an event touches.
	 *
	 * Only while Dolibarr's webhook module is on: nothing else listens. A failing webhook never
	 * makes the member's, invoice's or payment's own action fail; it goes to the system log.
	 *
	 * @param string    $action Dolibarr event code
	 * @param object    $object Object of the event
	 * @param User      $user   User
	 * @param Translate $langs  Languages
	 * @param Conf      $conf   Configuration
	 * @return int Number of events raised
	 */
	public function notify($action, $object, $user, $langs, $conf)
	{
		if (!isModEnabled('webhook') || !self::handles($action)) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/class/interfaces.class.php';

		$raised = 0;
		foreach (self::notYetAnnounced($this->memberIds($action, $object)) as $memberId) {
			$event = new VereineMemberEvent();
			$event->id = $memberId;
			$event->member_id = $memberId;
			$event->cause = $action;
			$event->occurred_at = gmdate('Y-m-d\TH:i:s\Z', dol_now());
			$interfaces = new Interfaces($this->db);
			if ($interfaces->run_triggers(self::TRIGGER_CODE, $event, $user, $langs, $conf) < 0) {
				dol_syslog('Vereine: '.self::TRIGGER_CODE.' for member '.$memberId.' failed: '.implode(' | ', (array) $interfaces->errors), LOG_WARNING);
			}
			$raised++;
		}
		return $raised;
	}

	/**
	 * Members of this entity an event touches.
	 *
	 * @param string $action Dolibarr event code
	 * @param object $object Object of the event
	 * @return int[]
	 */
	public function memberIds($action, $object)
	{
		if (in_array($action, self::MEMBER_EVENTS, true)) {
			return array((int) $object->id);
		}
		if (in_array($action, self::SUBSCRIPTION_EVENTS, true)) {
			return empty($object->fk_adherent) ? array() : array((int) $object->fk_adherent);
		}
		if (in_array($action, self::INVOICE_EVENTS, true)) {
			// A draft is no part of a summary, unless it just became one again.
			if (!isset($object->socid) || ((int) $object->status === 0 && $action !== 'BILL_UNVALIDATE')) {
				return array();
			}
			return $this->membersOf("f.rowid = ".((int) $object->id));
		}
		if (in_array($action, self::PAYMENT_EVENTS, true)) {
			// Dolibarr raises both events while the payment is still linked to its invoices.
			$condition = "f.rowid IN (SELECT pf.fk_facture FROM ".MAIN_DB_PREFIX."paiement_facture as pf WHERE pf.fk_paiement = ".((int) $object->id).")";
			if (!empty($object->amounts) && is_array($object->amounts)) {
				$condition = "(".$condition." OR f.rowid IN (".implode(', ', array_map('intval', array_keys($object->amounts)))."))";
			}
			return $this->membersOf($condition);
		}
		return array();
	}

	/**
	 * Add the event to Dolibarr's list of events, so a webhook target can choose it.
	 *
	 * @param DoliDB $db Database handler
	 * @return int 1 when added, 0 when already there, -1 on error
	 */
	public static function ensureTriggerCode($db)
	{
		$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."c_action_trigger WHERE code = '".$db->escape(self::TRIGGER_CODE)."'");
		if (!$resql) {
			return -1;
		}
		if ($db->fetch_object($resql)) {
			return 0;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_action_trigger (elementtype, code, label, description, rang)";
		$sql .= " VALUES ('member', '".$db->escape(self::TRIGGER_CODE)."', 'Vereine: member summary changed (for website webhooks)',";
		$sql .= " 'Only the member id and the cause are sent; read the summary through the API', 9900)";
		return $db->query($sql) ? 1 : -1;
	}

	/**
	 * Members whose third party has the invoices matching a condition, and members whose
	 * subscription periods these invoices are linked to, such as the children on a payer's invoice.
	 *
	 * @param string $condition SQL condition on the invoice table f
	 * @return int[]
	 */
	private function membersOf($condition)
	{
		$sql = "SELECT d.rowid as member_id FROM ".MAIN_DB_PREFIX."facture as f";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent as d ON d.fk_soc = f.fk_soc";
		$sql .= " WHERE ".$condition." AND d.entity IN (".getEntity('adherent').")";
		$sql .= " UNION SELECT d.rowid as member_id FROM ".MAIN_DB_PREFIX."facture as f";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."element_element as ee ON ee.fk_target = f.rowid AND ee.sourcetype = 'subscription' AND ee.targettype = 'facture'";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."subscription as s ON s.rowid = ee.fk_source";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent as d ON d.rowid = s.fk_adherent";
		$sql .= " WHERE ".$condition." AND d.entity IN (".getEntity('adherent').")";
		$sql .= " ORDER BY member_id";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return array();
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->member_id;
		}
		$this->db->free($resql);
		return $ids;
	}
}
