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
 * \brief   Reacts to member events: keeps the linked third party in line, creates one when configured.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Triggers of the Vereine module.
 */
class InterfaceVereineTriggers extends DolibarrTriggers
{
	/** Member events the module handles. */
	const MEMBER_EVENTS = array('MEMBER_VALIDATE', 'MEMBER_RESILIATE', 'MEMBER_EXCLUDE', 'MEMBER_MODIFY', 'MEMBER_DELETE');

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'hr';
		$this->description = 'Vereine: keeps members and their third parties consistent.';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'fa-landmark';
	}

	/**
	 * Called by Dolibarr for every business event.
	 *
	 * A problem with the third party never makes the member's own action fail;
	 * it is written to the module's log instead.
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
		if (!isModEnabled('vereine') || !in_array($action, self::MEMBER_EVENTS, true)) {
			return 0;
		}
		if (!($object instanceof Adherent)) {
			return 0;
		}
		dol_include_once('/vereine/class/vereinepartnerservice.class.php');
		$service = new VereinePartnerService($this->db);
		return $service->onMemberEvent($action, $object, $user);
	}
}
