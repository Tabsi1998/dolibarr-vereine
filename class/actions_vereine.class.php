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
	 * @var array<int,string|null> Invoice id => its public note before the PDF was built
	 */
	private $notes = array();

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
	 * Before an invoice PDF is built: add the tax profile notes and the register number to the public note.
	 *
	 * Only the object in memory changes, for this PDF; afterPDFCreation puts the note back.
	 * Dolibarr's PDF templates print the public note, so no template is changed.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters: file, object, outputlangs
	 * @param CommonObject        $object      Invoice
	 * @param string              $action      Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0, the PDF is always built
	 */
	public function beforePDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $mysoc;

		if (!is_object($object) || !isset($object->element) || $object->element !== 'facture' || (int) $object->id <= 0) {
			return 0;
		}
		$outputlangs = isset($parameters['outputlangs']) && is_object($parameters['outputlangs']) ? $parameters['outputlangs'] : $langs;
		$outputlangs->load('vereine@vereine');
		$texts = array();

		if (getDolGlobalString('VEREINE_PDF_TAX_NOTES', '1') === '1') {
			dol_include_once('/vereine/class/vereinetaxassign.class.php');
			$assign = new VereineTaxAssign($this->db);
			foreach ($assign->invoiceNotes('facturedet', (int) $object->id) as $group) {
				$key = count($group['positions']) > 1 ? 'VereinePdfNoteLines' : 'VereinePdfNoteLine';
				$texts[] = $outputlangs->transnoentitiesnoconv($key, implode(', ', $group['positions']), $group['note']);
			}
		}
		if (getDolGlobalString('VEREINE_PDF_REGISTER', '1') === '1') {
			dol_include_once('/vereine/class/vereineorganization.class.php');
			$organization = VereineOrganization::load($mysoc);
			if ($organization['register']['number'] !== '') {
				$texts[] = $outputlangs->transnoentitiesnoconv('VereinePdfRegister_ZVR', $organization['register']['number']);
			}
		}
		if ($texts) {
			$this->notes[(int) $object->id] = $object->note_public;
			$object->note_public = dol_concatdesc((string) $object->note_public, implode("\n", $texts));
		}
		return 0;
	}

	/**
	 * After an invoice PDF is built: the public note is what it was, so nothing unintended is saved.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters: file, object, outputlangs
	 * @param CommonDocGenerator  $object      PDF template
	 * @param string              $action      Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int 0
	 */
	public function afterPDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		$invoice = isset($parameters['object']) && is_object($parameters['object']) ? $parameters['object'] : null;
		if ($invoice && isset($invoice->id) && array_key_exists((int) $invoice->id, $this->notes)) {
			$invoice->note_public = $this->notes[(int) $invoice->id];
			unset($this->notes[(int) $invoice->id]);
		}
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
