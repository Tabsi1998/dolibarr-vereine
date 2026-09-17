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
 * \brief   Fee run: which fees are due, and creating subscription periods and invoices for them.
 *
 * A fee creates what Dolibarr's member card creates for "New subscription" with "Create
 * invoice": a subscription period and a validated invoice linked to it in element_element
 * (source type subscription, target type facture). That link marks fee invoices for other
 * modules, whether the invoice came from a fee run or from the member card. Fees of a family
 * that start on the same day share one invoice to the payer, linked to every period on it.
 */

require_once __DIR__.'/vereinefeemodel.class.php';
require_once __DIR__.'/vereinefeediscountstore.class.php';
require_once __DIR__.'/vereinefeefamilystore.class.php';
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
	/** The third party named as payer on the member does not exist any more. */
	const NO_PAYER = 'no_payer';
	/** The member type sets no amount. */
	const NO_AMOUNT = 'no_amount';
	/** The member has neither a subscription period nor a validation date to start from. */
	const NO_START = 'no_start';

	/** What a row without family rule carries. */
	const NO_FAMILY = array('kind' => VereineFeeFamilies::MODE_NONE, 'value' => 0.0, 'size' => 0, 'year_start' => '', 'charged' => 0.0, 'before' => null);

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
		$members = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$members[] = $obj;
		}
		$this->db->free($resql);

		// Families count every active member, also those of other member types than the one previewed.
		$familyStore = new VereineFeeFamilyStore($this->db);
		$family = $familyStore->setting();
		$people = $familyStore->members();
		$families = array();
		foreach ($people as $person) {
			if ($person['payer_socid'] > 0) {
				$families[$person['payer_socid']][] = $person['id'];
			}
		}
		$payerNames = $familyStore->thirdPartyNames(array_keys($families));

		// Discount rules exist only after the module was enabled with 0.3.7; without them nobody gets one.
		$discountStore = new VereineFeeDiscountStore($this->db);
		$rules = $discountStore->fetchAll(true);
		$discountData = $discountStore->memberData(array_merge(array_keys($people), array_map(function ($obj) {
			return (int) $obj->rowid;
		}, $members)));

		$rows = array();
		foreach ($members as $obj) {
			$type = isset($types[(int) $obj->fk_adherent_type]) ? $types[(int) $obj->fk_adherent_type] : null;
			if ($type === null || !$type['subscription']) {
				continue;
			}
			$memberId = (int) $obj->rowid;
			$memberDiscount = (isset($discountData[$memberId]) ? $discountData[$memberId] : array()) + array('type_id' => $type['id']);
			$person = isset($people[$memberId]) ? $people[$memberId] : array('socid' => (int) $obj->fk_soc, 'payer' => 0, 'payer_socid' => max(0, (int) $obj->fk_soc));
			$payerMissing = $person['payer'] > 0 && !isset($payerNames[$person['payer']]);
			$payerSocid = $payerMissing ? 0 : $person['payer_socid'];
			$base = array(
				'member_id' => $memberId,
				'member_ref' => (string) $obj->ref,
				'name' => $obj->morphy === 'mor' && (string) $obj->societe !== '' ? (string) $obj->societe : trim($obj->firstname.' '.$obj->lastname),
				'socid' => (int) $obj->fk_soc,
				'payer_socid' => $payerSocid,
				'payer_name' => (!$payerMissing && VereineFeeFamilies::paidByOther($person['socid'], $person['payer'])) ? $payerNames[$person['payer']] : '',
				'type_id' => $type['id'],
				'type_label' => $type['label'],
				'product_id' => $type['product_id'],
			);
			$paidUntil = VereineMemberSummary::dayOf($obj->datefin);
			$joinedOn = VereineMemberSummary::datePart($obj->datevalid);
			$fee = VereineFeeRules::nextFee($type['model'], $joinedOn, $paidUntil);
			if ($fee === null) {
				$rows[] = $base + array('key' => $memberId.':', 'status' => self::NO_START, 'fee' => null, 'backlog' => 0,
					'discount' => array('kind' => 'none', 'rule' => null, 'reason' => '', 'notes' => array()), 'family' => self::NO_FAMILY);
				continue;
			}
			$periods = array();
			$previousEnd = $paidUntil;
			while ($fee !== null && $fee['start'] <= $dueUntil && count($periods) < self::MAX_PERIODS) {
				$discount = VereineFeeDiscounts::choose($rules, $memberDiscount, $fee['start']);
				$familyRule = self::NO_FAMILY;
				$model = $type['model'];
				if ($discount['kind'] !== 'none') {
					$model['amount'] = VereineFeeDiscounts::apply($model['amount'], $discount);
					if ($discount['kind'] === 'exempt') {
						$model['admission_fee'] = 0.0;
					}
				}
				if ($family['mode'] === VereineFeeFamilies::MODE_PERCENT && $discount['kind'] !== 'exempt' && isset($families[$payerSocid])) {
					$amounts = $this->familyAmounts($families[$payerSocid], $people, $types, $rules, $discountData, $fee['start']);
					if (count($amounts) >= 2 && isset($amounts[$memberId]) && VereineFeeFamilies::head($amounts) !== $memberId) {
						$own = VereineFeeRules::nextFee($model, $joinedOn, $previousEnd);
						$model['amount'] = VereineFeeFamilies::percentOff($model['amount'], $family['value']);
						$familyRule = array('kind' => VereineFeeFamilies::MODE_PERCENT, 'size' => count($amounts), 'before' => $own['total'], 'value' => $family['value']) + self::NO_FAMILY;
					}
				}
				if ($discount['kind'] !== 'none' || $familyRule['kind'] !== VereineFeeFamilies::MODE_NONE) {
					$adjusted = VereineFeeRules::nextFee($model, $joinedOn, $previousEnd);
					$adjusted['full_total'] = $fee['total'];
					$fee = $adjusted;
				}
				$periods[] = array('fee' => $fee, 'discount' => $discount, 'family' => $familyRule);
				$previousEnd = $fee['end'];
				$fee = VereineFeeRules::nextFee($type['model'], $joinedOn, $fee['end']);
			}
			foreach ($periods as $period) {
				$fee = $period['fee'];
				$rows[] = $base + array('key' => $memberId.':'.$fee['start'], 'status' => self::statusOf($fee, $payerMissing, $payerSocid), 'fee' => $fee,
					'backlog' => count($periods), 'discount' => $period['discount'], 'family' => $period['family']);
			}
		}

		if ($family['mode'] === VereineFeeFamilies::MODE_CAP) {
			$rows = $this->applyCap($rows, $family['value'], $families, $people, $types, $discountData, $familyStore);
		}
		return $rows;
	}

	/**
	 * Create the chosen fees. Amounts are worked out again, nothing from the form is trusted.
	 *
	 * A fee of a member is only created when every earlier due period of that member is
	 * created too; a period already created is not due any more, so a second run finds nothing.
	 * Fees for the same payer starting on the same day share one invoice, created together or
	 * not at all.
	 *
	 * @param string   $dueUntil       Same day as the preview
	 * @param int      $typeId         Same member type as the preview
	 * @param string[] $keys           Keys of the chosen rows
	 * @param bool     $createPartners Create a third party for members that have none and no payer
	 * @param User     $user           User who runs it
	 * @return array{created:array<int,array<string,mixed>>,skipped:array<int,array<string,mixed>>,failed:array<int,array<string,mixed>>}
	 */
	public function run($dueUntil, $typeId, array $keys, $createPartners, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';

		$result = array('created' => array(), 'skipped' => array(), 'failed' => array());
		$stopped = array();
		$groups = array();
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
			if (in_array($row['status'], array(self::NO_AMOUNT, self::NO_START, self::NO_PAYER), true)) {
				$stopped[$memberId] = true;
				$result['skipped'][] = $row + array('reason' => $row['status']);
				continue;
			}
			if ($row['fee']['total'] <= 0) {
				// Nothing to invoice, such as an exempt member: the period alone, recorded as paid.
				$groups[] = array('start' => $row['fee']['start'], 'socid' => 0, 'invoice' => false, 'rows' => array($row));
				continue;
			}
			if ($row['payer_socid'] <= 0) {
				if (!$createPartners) {
					$stopped[$memberId] = true;
					$result['skipped'][] = $row + array('reason' => self::NO_PARTNER);
					continue;
				}
				$member = new Adherent($this->db);
				if ($member->fetch($memberId) <= 0) {
					$stopped[$memberId] = true;
					$result['failed'][] = $row + array('error' => $member->error);
					continue;
				}
				if ((int) $member->fk_soc <= 0) {
					dol_include_once('/vereine/class/vereinepartnerservice.class.php');
					$service = new VereinePartnerService($this->db);
					if ($service->createPartner($member, $user) <= 0 || (int) $member->fk_soc <= 0) {
						$stopped[$memberId] = true;
						$result['failed'][] = $row + array('error' => $service->error);
						continue;
					}
				}
				$row['payer_socid'] = (int) $member->fk_soc;
			}
			$groupKey = $row['payer_socid'].':'.$row['fee']['start'];
			if (!isset($groups[$groupKey])) {
				$groups[$groupKey] = array('start' => $row['fee']['start'], 'socid' => $row['payer_socid'], 'invoice' => true, 'rows' => array());
			}
			$groups[$groupKey]['rows'][] = $row;
		}

		// Earliest periods first, so a failed period stops the later ones of its members.
		$ordered = array_values($groups);
		foreach ($ordered as $position => $group) {
			$ordered[$position]['position'] = $position;
		}
		usort($ordered, function ($left, $right) {
			return strcmp($left['start'], $right['start']) ?: $left['position'] - $right['position'];
		});
		foreach ($ordered as $group) {
			$rows = array();
			foreach ($group['rows'] as $row) {
				if (empty($stopped[$row['member_id']])) {
					$rows[] = $row;
				} else {
					$result['skipped'][] = $row + array('reason' => 'earlier_period');
				}
			}
			if (!$rows) {
				continue;
			}
			if (!$group['invoice']) {
				$row = $rows[0];
				if ($this->createPeriod($row, $user) <= 0) {
					$stopped[$row['member_id']] = true;
					$result['failed'][] = $row + array('error' => $this->error);
					continue;
				}
				$result['created'][] = $row + array('invoice_id' => 0, 'invoice_ref' => '');
				continue;
			}
			$invoice = $this->createFees($rows, $group['socid'], $user);
			foreach ($rows as $row) {
				if ($invoice === null) {
					$stopped[$row['member_id']] = true;
					$result['failed'][] = $row + array('error' => $this->error);
					VereineLog::add($this->db, $user, VereineLog::FEE_ERROR, $row['member_id'], $group['socid'], $this->error);
				} else {
					$result['created'][] = array('invoice_id' => (int) $invoice->id, 'invoice_ref' => (string) $invoice->ref, 'payer_socid' => $group['socid']) + $row;
				}
			}
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
		$sql .= " ORDER BY f.rowid DESC, d.rowid";
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
	 * Status of a previewed fee.
	 *
	 * @param array<string,mixed> $fee          Fee of the period
	 * @param bool                $payerMissing The payer named on the member does not exist
	 * @param int                 $payerSocid   Third party that gets the invoice, 0 for none
	 * @return string
	 */
	private static function statusOf(array $fee, $payerMissing, $payerSocid)
	{
		if ($fee['amount'] === null) {
			return self::NO_AMOUNT;
		}
		if ($fee['total'] > 0 && $payerMissing) {
			return self::NO_PAYER;
		}
		return ($fee['total'] > 0 && $payerSocid <= 0) ? self::NO_PARTNER : self::READY;
	}

	/**
	 * Yearly fees of the members of a family that pay one, after their own discount on a day.
	 *
	 * @param int[]                            $ids          Members of the family, by id
	 * @param array<int,array<string,mixed>>   $people       Active members, see VereineFeeFamilyStore::members()
	 * @param array<int,array<string,mixed>>   $types        Member types
	 * @param array<int,array<string,mixed>>   $rules        Active discount rules
	 * @param array<int,array<string,mixed>>   $discountData What members bring to discounts
	 * @param string                           $day          First day of the period
	 * @return array<int,float> By member id, ordered by id
	 */
	private function familyAmounts(array $ids, array $people, array $types, array $rules, array $discountData, $day)
	{
		$amounts = array();
		foreach ($ids as $id) {
			$type = isset($people[$id], $types[$people[$id]['type_id']]) ? $types[$people[$id]['type_id']] : null;
			if ($type === null || !$type['subscription'] || $type['model']['amount'] === null) {
				continue;
			}
			$discount = VereineFeeDiscounts::choose($rules, (isset($discountData[$id]) ? $discountData[$id] : array()) + array('type_id' => $type['id']), $day);
			$yearly = VereineFeeFamilies::yearlyAmount(VereineFeeDiscounts::apply($type['model']['amount'], $discount), VereineFeeRules::periodMonths($type['model']));
			if ($yearly > 0) {
				$amounts[(int) $id] = $yearly;
			}
		}
		return $amounts;
	}

	/**
	 * Lower the fees of families above the family cap of their fee year.
	 *
	 * What family members were already charged for periods of that fee year counts; the fees
	 * of the preview share what is left in proportion.
	 *
	 * @param array<int,array<string,mixed>> $rows         Rows of the preview
	 * @param float                          $cap          Most a family pays in a fee year
	 * @param array<int,int[]>               $families     Member ids by payer
	 * @param array<int,array<string,mixed>> $people       Active members
	 * @param array<int,array<string,mixed>> $types        Member types
	 * @param array<int,array<string,mixed>> $discountData What members bring to discounts
	 * @param VereineFeeFamilyStore          $store        Family store
	 * @return array<int,array<string,mixed>>
	 */
	private function applyCap(array $rows, $cap, array $families, array $people, array $types, array $discountData, $store)
	{
		$groups = array();
		foreach ($rows as $index => $row) {
			$fee = $row['fee'];
			if ($fee === null || $fee['amount'] === null || $fee['amount'] <= 0 || !isset($families[$row['payer_socid']])) {
				continue;
			}
			$paying = array();
			foreach ($families[$row['payer_socid']] as $id) {
				$type = isset($types[$people[$id]['type_id']]) ? $types[$people[$id]['type_id']] : null;
				if ($type !== null && $type['subscription'] && $type['model']['amount'] > 0 && empty($discountData[$id]['exempt'])) {
					$paying[] = (int) $id;
				}
			}
			if (count($paying) < 2) {
				continue;
			}
			$yearStart = VereineFeeFamilies::feeYear($fee['start'], $types[$row['type_id']]['model']['start_month']);
			$key = $row['payer_socid'].':'.$yearStart;
			if (!isset($groups[$key])) {
				$groups[$key] = array('year_start' => $yearStart, 'members' => $paying, 'rows' => array());
			}
			$groups[$key]['rows'][] = $index;
		}
		foreach ($groups as $group) {
			$until = VereineFeeRules::addDays(VereineFeeRules::addDuration($group['year_start'], 1, 'y'), -1);
			$charged = $store->charged($group['members'], $group['year_start'], $until);
			$amounts = array();
			foreach ($group['rows'] as $index) {
				$amounts[$index] = (float) $rows[$index]['fee']['amount'];
			}
			foreach (VereineFeeFamilies::share($amounts, $cap - $charged) as $index => $share) {
				if (abs($share - $amounts[$index]) < 0.005) {
					continue;
				}
				$fee = $rows[$index]['fee'];
				$before = $fee['total'];
				$fee['full_total'] = isset($fee['full_total']) ? $fee['full_total'] : $before;
				$fee['amount'] = $share;
				$fee['total'] = round($share + $fee['admission_fee'], 2);
				$rows[$index]['fee'] = $fee;
				$rows[$index]['family'] = array('kind' => VereineFeeFamilies::MODE_CAP, 'value' => $cap, 'size' => count($group['members']),
					'year_start' => $group['year_start'], 'charged' => $charged, 'before' => $before);
				if ($rows[$index]['status'] === self::NO_PARTNER && $fee['total'] <= 0) {
					$rows[$index]['status'] = self::READY;
				}
			}
		}
		return $rows;
	}

	/**
	 * Subscription periods of fees for one payer and one validated invoice with a line for each, together or not at all.
	 *
	 * @param array<int,array<string,mixed>> $rows  Rows of the preview, one per member
	 * @param int                            $socid Third party that gets the invoice
	 * @param User                           $user  User
	 * @return Facture|null Null on error, see $error
	 */
	private function createFees(array $rows, $socid, $user)
	{
		global $langs, $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/paymentterm.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

		// On an invoice for several members or to a payer, every line names its member.
		$named = count($rows) > 1 || $rows[0]['payer_name'] !== '';

		$this->db->begin();
		$subscriptionIds = array();
		foreach ($rows as $row) {
			$member = new Adherent($this->db);
			if ($member->fetch($row['member_id']) <= 0) {
				return $this->fail('member '.$row['member_id'].': '.$member->error);
			}
			$subscriptionId = $member->subscription($this->moment($row['fee']['start']), $row['fee']['amount'], 0, '', $this->lineLabel($row, false), '', '', '', $this->moment($row['fee']['end']));
			if ($subscriptionId <= 0) {
				return $this->fail('subscription: '.$member->error.' '.implode(' | ', (array) $member->errors));
			}
			$subscriptionIds[] = (int) $subscriptionId;
		}

		$customer = new Societe($this->db);
		if ($customer->fetch((int) $socid) <= 0) {
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
		$invoice->linked_objects['subscription'] = $subscriptionIds;
		if ($invoice->create($user) <= 0) {
			return $this->fail('invoice: '.$invoice->error.' '.implode(' | ', (array) $invoice->errors));
		}

		foreach ($rows as $row) {
			$fee = $row['fee'];
			$productId = (int) $row['product_id'];
			$vat = $productId > 0 ? get_default_tva($mysoc, $customer, $productId) : 0;
			if ($invoice->addline($this->lineLabel($row, $named), 0, 1, $vat, 0, 0, $productId, 0, $this->moment($fee['start']), $this->moment($fee['end']), 0, 0, 0, 'TTC', $fee['amount'], 1) <= 0) {
				return $this->fail('invoice line: '.$invoice->error);
			}
			if ($fee['admission_fee'] > 0) {
				$admission = $langs->transnoentities('VereineFeeRunAdmissionLine', $row['type_label']);
				if ($named) {
					$admission = $langs->transnoentities('VereineFeeRunLineFor', $row['name'], $admission);
				}
				if ($invoice->addline($admission, 0, 1, $vat, 0, 0, $productId, 0, '', '', 0, 0, 0, 'TTC', $fee['admission_fee'], 1) <= 0) {
					return $this->fail('admission line: '.$invoice->error);
				}
			}
		}
		if ($invoice->validate($user) <= 0) {
			return $this->fail('validate invoice: '.$invoice->error.' '.implode(' | ', (array) $invoice->errors));
		}
		$this->db->commit();

		foreach ($rows as $row) {
			VereineLog::add($this->db, $user, VereineLog::FEE_INVOICE, $row['member_id'], (int) $customer->id,
				$invoice->ref.' / '.$row['fee']['start'].' - '.$row['fee']['end'].' / '.price2num($row['fee']['total'], 'MT'));
		}
		return $invoice;
	}

	/**
	 * A subscription period without invoice, for a fee of 0.
	 *
	 * @param array<string,mixed> $row  Row of the preview
	 * @param User                $user User
	 * @return int 1 if created, <0 on error, see $error
	 */
	private function createPeriod(array $row, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';

		$member = new Adherent($this->db);
		if ($member->fetch($row['member_id']) <= 0) {
			$this->error = 'member '.$row['member_id'].': '.$member->error;
			return -1;
		}
		$fee = $row['fee'];
		if ($member->subscription($this->moment($fee['start']), 0, 0, '', $this->lineLabel($row, false), '', '', '', $this->moment($fee['end'])) <= 0) {
			$this->error = trim('subscription: '.$member->error.' '.implode(' | ', (array) $member->errors));
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::FEE_PERIOD, (int) $member->id, (int) $member->fk_soc, $fee['start'].' - '.$fee['end'].' / '.$row['discount']['reason']);
		return 1;
	}

	/**
	 * Text of a fee on its subscription period and invoice line, with its discounts.
	 *
	 * @param array<string,mixed> $row   Row of the preview
	 * @param bool                $named Start with the member's name
	 * @return string
	 */
	private function lineLabel(array $row, $named)
	{
		global $langs;

		$fee = $row['fee'];
		$label = $langs->transnoentities('VereineFeeRunLine', $row['type_label'], dol_print_date($this->moment($fee['start']), 'day'), dol_print_date($this->moment($fee['end']), 'day'));
		$notes = array();
		if ($row['discount']['kind'] === 'exempt') {
			$notes[] = $row['discount']['reason'];
		} elseif ($row['discount']['reason'] !== '') {
			$notes[] = $langs->transnoentities('VereineDiscountOnInvoice', $row['discount']['reason']);
		}
		if ($row['family']['kind'] === VereineFeeFamilies::MODE_PERCENT) {
			$notes[] = $langs->transnoentities('VereineFamilyOnInvoicePercent', price2num($row['family']['value']));
		} elseif ($row['family']['kind'] === VereineFeeFamilies::MODE_CAP) {
			$notes[] = $langs->transnoentities('VereineFamilyOnInvoiceCap');
		}
		$notes = array_filter($notes, 'strlen');
		if ($notes) {
			$label .= ' ('.implode(', ', $notes).')';
		}
		return $named ? $langs->transnoentities('VereineFeeRunLineFor', $row['name'], $label) : $label;
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
