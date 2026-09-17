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
 * \file    class/vereinefeerun.class.php
 * \ingroup vereine
 * \brief   Fee run: which fees are due, and creating subscription period and invoice for each.
 *
 * A fee creates what Dolibarr's member card creates for "New subscription" with "Create
 * invoice": a subscription period and a validated invoice linked to it in element_element
 * (source type subscription, target type facture). That link marks fee invoices for other
 * modules, whether the invoice came from a fee run or from the member card.
 */

require_once __DIR__.'/vereinefeemodel.class.php';
require_once __DIR__.'/vereinemembersummary.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Preview and execution of a fee run.
 */
class VereineFeeRun
{
	/** Periods listed per member at most, so a long backlog cannot create hundreds of invoices at once. */
	const MAX_PERIODS = 12;

	/** The fee can be created. */
	const READY = 'ready';
	/** The member has no third party to invoice yet. */
	const NO_PARTNER = 'no_partner';
	/** The member type sets no amount. */
	const NO_AMOUNT = 'no_amount';
	/** The member has neither a subscription period nor a validation date to start from. */
	const NO_START = 'no_start';

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
	 * Fees due up to a day: one row per period, oldest first per member.
	 *
	 * @param string $dueUntil Periods starting on or before this day, YYYY-MM-DD
	 * @param int    $typeId   Only this member type, 0 for all
	 * @return array<int,array<string,mixed>>
	 */
	public function preview($dueUntil, $typeId = 0)
	{
		$feeModel = new VereineFeeModel($this->db);
		$types = $feeModel->memberTypes();
		if ($feeModel->error !== '') {
			$this->error = $feeModel->error;
			return array();
		}

		$sql = "SELECT d.rowid, d.ref, d.firstname, d.lastname, d.societe, d.morphy, d.datefin, d.datevalid, d.fk_soc, d.fk_adherent_type";
		$sql .= " FROM ".MAIN_DB_PREFIX."adherent as d";
		$sql .= " WHERE d.entity IN (".getEntity('adherent').") AND d.statut = 1";
		if ((int) $typeId > 0) {
			$sql .= " AND d.fk_adherent_type = ".((int) $typeId);
		}
		$sql .= " ORDER BY d.lastname, d.firstname, d.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$type = isset($types[(int) $obj->fk_adherent_type]) ? $types[(int) $obj->fk_adherent_type] : null;
			if ($type === null || !$type['subscription']) {
				continue;
			}
			$base = array(
				'member_id' => (int) $obj->rowid,
				'member_ref' => (string) $obj->ref,
				'name' => $obj->morphy === 'mor' && (string) $obj->societe !== '' ? (string) $obj->societe : trim($obj->firstname.' '.$obj->lastname),
				'socid' => (int) $obj->fk_soc,
				'type_id' => $type['id'],
				'type_label' => $type['label'],
				'product_id' => $type['product_id'],
			);
			$paidUntil = VereineMemberSummary::dayOf($obj->datefin);
			$joinedOn = VereineMemberSummary::datePart($obj->datevalid);
			$fee = VereineFeeRules::nextFee($type['model'], $joinedOn, $paidUntil);
			if ($fee === null) {
				$rows[] = $base + array('key' => $obj->rowid.':', 'status' => self::NO_START, 'fee' => null, 'backlog' => 0);
				continue;
			}
			$periods = array();
			while ($fee !== null && $fee['start'] <= $dueUntil && count($periods) < self::MAX_PERIODS) {
				$periods[] = $fee;
				$fee = VereineFeeRules::nextFee($type['model'], $joinedOn, $fee['end']);
			}
			foreach ($periods as $fee) {
				$status = self::READY;
				if ($fee['amount'] === null) {
					$status = self::NO_AMOUNT;
				} elseif ((int) $obj->fk_soc <= 0) {
					$status = self::NO_PARTNER;
				}
				$rows[] = $base + array('key' => $obj->rowid.':'.$fee['start'], 'status' => $status, 'fee' => $fee, 'backlog' => count($periods));
			}
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * Create the chosen fees. Amounts are worked out again, nothing from the form is trusted.
	 *
	 * A fee of a member is only created when every earlier due period of that member is
	 * created too; a period already created is not due any more, so a second run finds nothing.
	 *
	 * @param string   $dueUntil       Same day as the preview
	 * @param int      $typeId         Same member type as the preview
	 * @param string[] $keys           Keys of the chosen rows
	 * @param bool     $createPartners Create a third party for members that have none
	 * @param User     $user           User who runs it
	 * @return array{created:array<int,array<string,mixed>>,skipped:array<int,array<string,mixed>>,failed:array<int,array<string,mixed>>}
	 */
	public function run($dueUntil, $typeId, array $keys, $createPartners, $user)
	{
		$result = array('created' => array(), 'skipped' => array(), 'failed' => array());
		$stopped = array();
		foreach ($this->preview($dueUntil, $typeId) as $row) {
			$memberId = $row['member_id'];
			if (!in_array($row['key'], $keys, true)) {
				$stopped[$memberId] = true;
				continue;
			}
			if (!empty($stopped[$memberId])) {
				$result['skipped'][] = $row + array('reason' => 'earlier_period');
				continue;
			}
			if ($row['status'] === self::NO_AMOUNT || $row['status'] === self::NO_START) {
				$stopped[$memberId] = true;
				$result['skipped'][] = $row + array('reason' => $row['status']);
				continue;
			}
			require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
			$member = new Adherent($this->db);
			if ($member->fetch($memberId) <= 0) {
				$stopped[$memberId] = true;
				$result['failed'][] = $row + array('error' => $member->error);
				continue;
			}
			if ((int) $member->fk_soc <= 0) {
				if (!$createPartners) {
					$stopped[$memberId] = true;
					$result['skipped'][] = $row + array('reason' => self::NO_PARTNER);
					continue;
				}
				dol_include_once('/vereine/class/vereinepartnerservice.class.php');
				$service = new VereinePartnerService($this->db);
				if ($service->createPartner($member, $user) <= 0) {
					$stopped[$memberId] = true;
					$result['failed'][] = $row + array('error' => $service->error);
					continue;
				}
			}
			$invoice = $this->createFee($member, $row, $user);
			if ($invoice === null) {
				$stopped[$memberId] = true;
				$result['failed'][] = $row + array('error' => $this->error);
				VereineLog::add($this->db, $user, VereineLog::FEE_ERROR, $memberId, (int) $member->fk_soc, $this->error);
				continue;
			}
			$result['created'][] = $row + array('invoice_id' => (int) $invoice->id, 'invoice_ref' => (string) $invoice->ref, 'socid' => (int) $member->fk_soc);
		}
		if ($result['created']) {
			$total = 0.0;
			foreach ($result['created'] as $created) {
				$total += $created['fee']['total'];
			}
			VereineLog::add($this->db, $user, VereineLog::FEE_RUN, 0, 0, count($result['created']).' / '.price2num($total, 'MT').' / '.$dueUntil);
		}
		return $result;
	}

	/**
	 * Fee invoices, newest first, with their subscription period and member.
	 *
	 * @param int $limit At most this many
	 * @return array<int,array<string,mixed>>
	 */
	public function recentFeeInvoices($limit = 30)
	{
		$sql = "SELECT f.rowid, f.ref, f.total_ttc, f.fk_statut, f.date_lim_reglement, s.dateadh, s.datef as period_end,";
		$sql .= " d.rowid as member_id, d.firstname, d.lastname, d.societe, d.morphy";
		$sql .= " FROM ".MAIN_DB_PREFIX."element_element as ee";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = ee.fk_target";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."subscription as s ON s.rowid = ee.fk_source";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent as d ON d.rowid = s.fk_adherent";
		$sql .= " WHERE ee.sourcetype = 'subscription' AND ee.targettype = 'facture' AND f.entity IN (".getEntity('invoice').")";
		$sql .= " ORDER BY f.rowid DESC";
		$sql .= $this->db->plimit((int) $limit, 0);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$invoices = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$invoices[] = array(
				'id' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'total' => (float) $obj->total_ttc,
				'status' => (int) $obj->fk_statut === 0 ? 'draft' : VereineMemberSummary::invoiceStatus($obj->fk_statut, VereineMemberSummary::dayOf($obj->date_lim_reglement), $today),
				'start' => VereineMemberSummary::dayOf($obj->dateadh),
				'end' => VereineMemberSummary::dayOf($obj->period_end),
				'member_id' => (int) $obj->member_id,
				'name' => $obj->morphy === 'mor' && (string) $obj->societe !== '' ? (string) $obj->societe : trim($obj->firstname.' '.$obj->lastname),
			);
		}
		$this->db->free($resql);
		return $invoices;
	}

	/**
	 * Whether a user may create fees: new subscriptions, new and validated invoices.
	 *
	 * @param User $user User
	 * @return bool
	 */
	public static function mayRun($user)
	{
		$validate = getDolGlobalString('MAIN_USE_ADVANCED_PERMS') ? $user->hasRight('facture', 'invoice_advance', 'validate') : $user->hasRight('facture', 'creer');
		return $user->hasRight('adherent', 'cotisation', 'creer') && $user->hasRight('facture', 'creer') && $validate;
	}

	/**
	 * Subscription period and validated invoice of one fee, together or not at all.
	 *
	 * @param Adherent             $member Member with third party
	 * @param array<string,mixed>  $row    Row of the preview
	 * @param User                 $user   User
	 * @return Facture|null Null on error, see $error
	 */
	private function createFee($member, array $row, $user)
	{
		global $langs, $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/paymentterm.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

		$fee = $row['fee'];
		$start = $this->moment($fee['start']);
		$end = $this->moment($fee['end']);
		$label = $langs->transnoentities('VereineFeeRunLine', $row['type_label'], dol_print_date($start, 'day'), dol_print_date($end, 'day'));

		$this->db->begin();
		$subscriptionId = $member->subscription($start, $fee['amount'], 0, '', $label, '', '', '', $end);
		if ($subscriptionId <= 0) {
			return $this->fail('subscription: '.$member->error.' '.implode(' | ', (array) $member->errors));
		}

		$customer = new Societe($this->db);
		if ($customer->fetch((int) $member->fk_soc) <= 0) {
			return $this->fail('third party: '.$customer->error);
		}
		$invoice = new Facture($this->db);
		$invoice->type = Facture::TYPE_STANDARD;
		$invoice->socid = (int) $customer->id;
		$invoice->date = dol_now();
		$invoice->cond_reglement_id = (int) $customer->cond_reglement_id;
		if ($invoice->cond_reglement_id <= 0) {
			$paymentTerm = new PaymentTerm($this->db);
			$invoice->cond_reglement_id = (int) $paymentTerm->getDefaultId();
		}
		if (!empty($customer->mode_reglement_id)) {
			$invoice->mode_reglement_id = (int) $customer->mode_reglement_id;
		}
		if (!empty($customer->fk_account)) {
			$invoice->fk_account = (int) $customer->fk_account;
		} elseif (getDolGlobalInt('FACTURE_RIB_NUMBER') > 0) {
			$invoice->fk_account = getDolGlobalInt('FACTURE_RIB_NUMBER');
		}
		$invoice->linked_objects['subscription'] = $subscriptionId;
		if ($invoice->create($user) <= 0) {
			return $this->fail('invoice: '.$invoice->error.' '.implode(' | ', (array) $invoice->errors));
		}

		$productId = (int) $row['product_id'];
		$vat = $productId > 0 ? get_default_tva($mysoc, $customer, $productId) : 0;
		if ($invoice->addline($label, 0, 1, $vat, 0, 0, $productId, 0, $start, $end, 0, 0, 0, 'TTC', $fee['amount'], 1) <= 0) {
			return $this->fail('invoice line: '.$invoice->error);
		}
		if ($fee['admission_fee'] > 0) {
			$admission = $langs->transnoentities('VereineFeeRunAdmissionLine', $row['type_label']);
			if ($invoice->addline($admission, 0, 1, $vat, 0, 0, $productId, 0, '', '', 0, 0, 0, 'TTC', $fee['admission_fee'], 1) <= 0) {
				return $this->fail('admission line: '.$invoice->error);
			}
		}
		if ($invoice->validate($user) <= 0) {
			return $this->fail('validate invoice: '.$invoice->error.' '.implode(' | ', (array) $invoice->errors));
		}
		$this->db->commit();

		VereineLog::add($this->db, $user, VereineLog::FEE_INVOICE, (int) $member->id, (int) $customer->id,
			$invoice->ref.' / '.$fee['start'].' - '.$fee['end'].' / '.price2num($fee['total'], 'MT'));
		return $invoice;
	}

	/**
	 * Roll back and keep the error.
	 *
	 * @param string $error What went wrong
	 * @return null
	 */
	private function fail($error)
	{
		$this->db->rollback();
		$this->error = trim($error);
		return null;
	}

	/**
	 * Midnight of a day, the way Dolibarr's own subscription form stores a day.
	 *
	 * @param string $date YYYY-MM-DD
	 * @return int
	 */
	private function moment($date)
	{
		return (int) dol_mktime(0, 0, 0, (int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
	}
}
