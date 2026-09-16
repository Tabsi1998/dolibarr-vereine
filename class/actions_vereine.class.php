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
 * \file    class/actions_vereine.class.php
 * \ingroup vereine
 * \brief   Hooks of the Vereine module on Dolibarr's own pages.
 */

/**
 * Hooks on Dolibarr's member card.
 *
 * The card creates or links a third party ("Create third party", "Linked third
 * party") with plain SQL and no trigger. doActions notes the link before
 * Dolibarr's action runs; the action buttons, printed later in the same request
 * with the member loaded again, see the result and bring the third party in
 * line. Dolibarr's actions themselves stay untouched, and only viewing the card
 * changes nothing.
 */
class ActionsVereine
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Error
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();

	/**
	 * @var array<string,mixed> Results for the hook manager
	 */
	public $results = array();

	/**
	 * @var string HTML for the hook manager
	 */
	public $resprints = '';

	/**
	 * @var array<int,array{previous:int,how:string}> Member id => its third party before Dolibarr's action
	 */
	private $pending = array();

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
	 * Before Dolibarr's actions on the member card: note the third party of a member about to be linked.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject        $object      Member
	 * @param string              $action      Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0, Dolibarr's action always runs
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		if (!$this->isMemberCard($parameters, $object)) {
			return 0;
		}
		if ($action === 'setsocid' || ($action === 'confirm_create_thirdparty' && GETPOST('confirm', 'alpha') === 'yes')) {
			$this->pending[(int) $object->id] = array('previous' => (int) $object->fk_soc, 'how' => $action === 'setsocid' ? 'link' : 'create');
		}
		return 0;
	}

	/**
	 * After Dolibarr's actions, while the card prints its buttons: bring a newly linked third party in line.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject        $object      Member, loaded again after the action
	 * @param string              $action      Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0, Dolibarr's buttons always print
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $user;

		if (!$this->isMemberCard($parameters, $object) || !isset($this->pending[(int) $object->id])) {
			return 0;
		}
		$pending = $this->pending[(int) $object->id];
		unset($this->pending[(int) $object->id]);

		dol_include_once('/vereine/class/vereinepartnerservice.class.php');
		$service = new VereinePartnerService($this->db);
		$service->onMemberCardLink($object, $pending['previous'], $pending['how'], $user);
		return 0;
	}

	/**
	 * On a customer or supplier invoice: report lines whose VAT rate differs from their tax profile.
	 *
	 * Printed where Dolibarr shows confirmations, so it is seen before the lines. Nothing changes.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject        $object      Invoice
	 * @param string              $action      Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0, Dolibarr's confirmations always print
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$contexts = explode(':', isset($parameters['context']) ? (string) $parameters['context'] : '');
		$element = in_array('invoicecard', $contexts, true) ? 'facturedet' : (in_array('invoicesuppliercard', $contexts, true) ? 'facture_fourn_det' : '');
		if ($element === '' || !is_object($object) || (int) $object->id <= 0) {
			return 0;
		}
		dol_include_once('/vereine/class/vereinetaxassign.class.php');
		$assign = new VereineTaxAssign($this->db);
		$lines = $assign->deviations($element, (int) $object->id);
		if (!$lines) {
			return 0;
		}
		$langs->load('vereine@vereine');
		$html = '<div class="warning" data-taxprofile-warning="'.count($lines).'"><strong>'.$langs->trans('VereineTaxLinesDeviateTitle').'</strong><ul>';
		foreach ($lines as $line) {
			// Translate::trans() takes at most four parameters: position and description travel together.
			$where = $line['position'].($line['description'] !== '' ? ' ('.$line['description'].')' : '');
			$html .= '<li>'.$langs->trans('VereineTaxLineDeviation', $where, VereineTaxRules::formatRate($line['rate']), $line['profile'], VereineTaxRules::formatRate($line['profile_rate'])).'</li>';
		}
		$this->resprints = $html.'</ul>'.$langs->trans('VereineTaxLinesDeviateHint').'</div>';
		return 0;
	}

	/**
	 * Whether a hook runs on the card of an existing member.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param mixed               $object     Object of the page
	 * @return bool
	 */
	private function isMemberCard($parameters, $object)
	{
		$contexts = explode(':', isset($parameters['context']) ? (string) $parameters['context'] : '');
		return in_array('membercard', $contexts, true) && $object instanceof Adherent && (int) $object->id > 0;
	}
}
