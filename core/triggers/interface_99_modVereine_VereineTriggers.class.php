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
 * \file    core/triggers/interface_99_modVereine_VereineTriggers.class.php
 * \ingroup vereine
 * \brief   Reacts to member, product and invoice line events of Dolibarr.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Triggers of the Vereine module.
 */
class InterfaceVereineTriggers extends DolibarrTriggers
{
	/** Member events the module handles. */
	const MEMBER_EVENTS = array('MEMBER_VALIDATE', 'MEMBER_RESILIATE', 'MEMBER_EXCLUDE', 'MEMBER_MODIFY', 'MEMBER_DELETE');

	/** Product events after which the VAT rate follows the tax profile. */
	const PRODUCT_EVENTS = array('PRODUCT_CREATE', 'PRODUCT_MODIFY');

	/** New invoice lines that take the tax profile of their product. */
	const LINE_EVENTS = array('LINEBILL_INSERT', 'LINEBILL_SUPPLIER_CREATE');

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'hr';
		$this->description = 'Vereine: hält Mitglieder und ihre Geschäftspartner in Ordnung, wendet Steuerprofile an und meldet Website-Webhooks, welches Mitglied sich geändert hat.';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'fa-landmark';
	}

	/**
	 * Called by Dolibarr for every business event.
	 *
	 * A problem with the third party never makes the member's own action fail;
	 * it is written to the module's log instead. Tax profiles never block a
	 * product or an invoice line either; problems go to the system log.
	 *
	 * @param string       $action Event code
	 * @param CommonObject $object Object of the event
	 * @param User         $user   User
	 * @param Translate    $langs  Languages
	 * @param Conf         $conf   Configuration
	 * @return int 0 when nothing ran, >0 when something ran
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('vereine')) {
			return 0;
		}
		$result = 0;
		// Fee arrears from the Mahnwesen module (#17): one proposal per case for the board, kept up to date by payment or pause.
		if (strpos($action, 'MAHNWESEN_') === 0 && is_object($object) && isset($object->element) && $object->element === 'mahnwesen_event') {
			dol_include_once('/vereine/class/vereinearrears.class.php');
			$arrears = new VereineArrears($this->db);
			$result = $arrears->onEvent($object, $user);
			if ($result < 0) {
				// Refused, so the Mahnwesen module keeps the event and delivers it again.
				$this->errors[] = $arrears->error;
			}
			return $result;
		}
		if (in_array($action, self::MEMBER_EVENTS, true) && $object instanceof Adherent) {
			dol_include_once('/vereine/class/vereinepartnerservice.class.php');
			$service = new VereinePartnerService($this->db);
			$result = $service->onMemberEvent($action, $object, $user);
		} elseif (in_array($action, self::PRODUCT_EVENTS, true) || in_array($action, self::LINE_EVENTS, true)) {
			dol_include_once('/vereine/class/vereinetaxassign.class.php');
			$assign = new VereineTaxAssign($this->db);
			$result = in_array($action, self::PRODUCT_EVENTS, true) ? $assign->onProductSaved($object, $user) : $assign->onLineCreated($object);
			if ($result < 0) {
				dol_syslog('Vereine: tax profile on '.$action.': '.$assign->error, LOG_WARNING);
				$result = 0;
			}
		}
		// Note in the change feed which member changed, so a client can catch up after a break (#154).
		if (preg_match('/^(MEMBER_|BILL_|PAYMENT_CUSTOMER_)/', $action)) {
			dol_include_once('/vereine/class/vereinewebsiteevents.class.php');
			dol_include_once('/vereine/class/vereinechanges.class.php');
			$feed = new VereineWebsiteEvents($this->db);
			if (VereineWebsiteEvents::handles($action)) {
				$kind = $action === 'MEMBER_DELETE' ? VereineChangeRules::KIND_DELETED
					: ($action === 'MEMBER_CREATE' ? VereineChangeRules::KIND_CREATED : VereineChangeRules::KIND_UPDATED);
				$type = strpos($action, 'MEMBER_') === 0 && !in_array($action, VereineWebsiteEvents::SUBSCRIPTION_EVENTS, true)
					? VereineChangeRules::TYPE_MEMBERSHIP : VereineChangeRules::TYPE_FEE;
				VereineChanges::recordMany($this->db, $type, $feed->memberIds($action, $object), $kind, $user);
			}
		}

		// Tell webhooks that a member's summary may have changed, after the module's own work.
		if (isModEnabled('webhook') && preg_match('/^(MEMBER_|BILL_|PAYMENT_CUSTOMER_)/', $action)) {
			dol_include_once('/vereine/class/vereinewebsiteevents.class.php');
			$events = new VereineWebsiteEvents($this->db);
			$result += $events->notify($action, $object, $user, $langs, $conf);
		}
		return $result;
	}
}
