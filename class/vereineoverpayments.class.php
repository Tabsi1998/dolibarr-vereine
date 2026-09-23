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
 * \file    class/vereineoverpayments.class.php
 * \ingroup vereine
 * \brief   Overpayments on customer invoices (#54): found, and assigned once to a credit, a refund or a donation.
 *
 * Dolibarr takes a payment that is higher than the invoice and leaves the invoice open with a negative
 * remainder. What the association does with the excess is its decision, and the module only asks: it
 * becomes Dolibarr's own credit of the customer, a various payment back to the customer, or a donation in
 * Dolibarr's donation module. The invoice itself never changes; afterwards it is classified as paid, so
 * Dolibarr's own button cannot convert the same excess a second time. A unique key on the invoice keeps
 * the module from assigning it twice.
 */

require_once __DIR__.'/vereineoverpaymentrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * The overpayments of the association.
 */
class VereineOverpayments
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last database error
	 */
	public $error = '';

	/**
	 * @var string[] Language keys of what was refused
	 */
	public $errors = array();

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
	 * Invoices paid over their total, newest first.
	 *
	 * @param int    $year     Year of the invoice date, 0 for every year
	 * @param string $search   Part of the invoice reference or of the third party's name
	 * @param bool   $openOnly Only those nobody decided on yet
	 * @return array<int,array<string,mixed>>
	 */
	public function listing($year = 0, $search = '', $openOnly = false)
	{
		$where = '';
		if ((int) $year > 0) {
			$where .= " AND f.datef BETWEEN '".((int) $year)."-01-01' AND '".((int) $year)."-12-31'";
		}
		$search = trim((string) $search);
		if ($search !== '') {
			$like = $this->db->escape($this->db->escapeforlike($search));
			$where .= " AND (f.ref LIKE '%".$like."%' OR s.nom LIKE '%".$like."%')";
		}
		$rows = $this->query($where);
		if ($openOnly) {
			$rows = array_values(array_filter($rows, function ($row) {
				return $row['state'] === VereineOverpaymentRules::STATE_OPEN;
			}));
		}
		return $rows;
	}

	/**
	 * One invoice paid over its total.
	 *
	 * @param int $invoiceId Invoice
	 * @return array<string,mixed>|null Null when it is unknown or not paid over
	 */
	public function fetch($invoiceId)
	{
		$rows = $this->query(" AND f.rowid = ".((int) $invoiceId));
		return $rows ? $rows[0] : null;
	}

	/**
	 * Customer invoices whose payments, credit notes and down payments add up to more than their total.
	 *
	 * @param string $where Further conditions on f (invoice) and s (third party), already escaped
	 * @return array<int,array<string,mixed>>
	 */
	private function query($where)
	{
		global $conf;

		$sql = "SELECT x.* FROM (SELECT f.rowid, f.ref, f.datef, f.total_ttc, f.paye, f.fk_soc, s.nom,";
		$sql .= " (SELECT COALESCE(SUM(pf.amount), 0) FROM ".MAIN_DB_PREFIX."paiement_facture as pf WHERE pf.fk_facture = f.rowid) as paid,";
		$sql .= " (SELECT COALESCE(SUM(rc.amount_ttc), 0) FROM ".MAIN_DB_PREFIX."societe_remise_except as rc WHERE rc.fk_facture = f.rowid) as credits,";
		// What Dolibarr's own button on the invoice made of the excess.
		$sql .= " (SELECT MIN(rx.rowid) FROM ".MAIN_DB_PREFIX."societe_remise_except as rx WHERE rx.fk_facture_source = f.rowid";
		$sql .= " AND rx.description = '(EXCESS RECEIVED)') as dolibarr_credit,";
		$sql .= " (SELECT MIN(a.rowid) FROM ".MAIN_DB_PREFIX."adherent as a WHERE a.fk_soc = f.fk_soc AND a.entity IN (".getEntity('adherent').")) as member_id,";
		$sql .= " o.rowid as assignment, o.kind, o.amount as assigned_amount, o.fk_discount, o.fk_payment_various, o.fk_don, o.datec as assigned_at";
		$sql .= " FROM ".MAIN_DB_PREFIX."facture as f INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."vereine_overpayment as o ON o.fk_facture = f.rowid AND o.entity = ".((int) $conf->entity);
		// Standard, replacement and situation invoices, validated or paid: those Dolibarr lets convert an excess.
		$sql .= " WHERE f.entity IN (".getEntity('invoice').") AND f.type IN (0, 1, 5) AND f.fk_statut IN (1, 2)".$where;
		$sql .= ") as x WHERE x.paid + x.credits - x.total_ttc >= 0.005 ORDER BY x.datef DESC, x.rowid DESC";
		$rows = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $rows;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$excess = VereineOverpaymentRules::excess($obj->total_ttc, $obj->paid, $obj->credits);
			if ($excess <= 0) {
				continue;
			}
			$rows[] = array('invoice_id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'day' => substr((string) $obj->datef, 0, 10),
				'total' => round((float) $obj->total_ttc, 2), 'paid' => round((float) $obj->paid + (float) $obj->credits, 2), 'excess' => $excess,
				'is_paid' => (int) $obj->paye === 1, 'socid' => (int) $obj->fk_soc, 'partner' => (string) $obj->nom, 'member_id' => (int) $obj->member_id,
				'dolibarr_credit' => (int) $obj->dolibarr_credit, 'kind' => (string) $obj->kind,
				'state' => VereineOverpaymentRules::state((string) $obj->kind, (int) $obj->dolibarr_credit > 0),
				'assigned_amount' => round((float) $obj->assigned_amount, 2), 'discount_id' => (int) $obj->fk_discount,
				'payment_id' => (int) $obj->fk_payment_various, 'don_id' => (int) $obj->fk_don,
				'assigned_at' => $obj->assigned_at !== null ? $this->db->jdate($obj->assigned_at) : 0);
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * What keeps a user from assigning this way, empty when nothing does.
	 *
	 * The Dolibarr rights of what comes out: an invoice to close, a bank line, a donation.
	 *
	 * @param User   $user Who wants to assign
	 * @param string $kind One of VereineOverpaymentRules::KINDS
	 * @return string Language key, empty when the user may
	 */
	public function blocker($user, $kind)
	{
		if (!$user->hasRight('facture', 'creer')) {
			return 'VereineOverpaymentNeedsInvoiceRight';
		}
		if ($kind === VereineOverpaymentRules::KIND_REFUND) {
			if (!isModEnabled('bank')) {
				return 'VereineOverpaymentNeedsBank';
			}
			return $user->hasRight('banque', 'modifier') ? '' : 'VereineOverpaymentNeedsBankRight';
		}
		if ($kind === VereineOverpaymentRules::KIND_DONATION) {
			if (!isModEnabled('don')) {
				return 'VereineOverpaymentNeedsDonations';
			}
			return $user->hasRight('don', 'creer') ? '' : 'VereineOverpaymentNeedsDonationRight';
		}
		return '';
	}

	/**
	 * The payment that came in last on an invoice: its day and the way it was paid.
	 *
	 * @param int $invoiceId Invoice
	 * @return array{date:int,mode:int}|null
	 */
	public function lastPayment($invoiceId)
	{
		$sql = "SELECT p.datep, p.fk_paiement FROM ".MAIN_DB_PREFIX."paiement as p INNER JOIN ".MAIN_DB_PREFIX."paiement_facture as pf ON pf.fk_paiement = p.rowid";
		$sql .= " WHERE pf.fk_facture = ".((int) $invoiceId)." ORDER BY p.datep DESC, p.rowid DESC";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? array('date' => (int) $this->db->jdate($obj->datep), 'mode' => (int) $obj->fk_paiement) : null;
	}

	/**
	 * Assign the excess of an invoice, once: a credit, a refund or a donation, then the invoice is paid.
	 *
	 * Everything or nothing: when Dolibarr refuses a part, nothing is kept.
	 *
	 * @param int                 $invoiceId Invoice
	 * @param string              $kind      One of VereineOverpaymentRules::KINDS
	 * @param bool                $confirmed For a donation: given freely, for nothing in return
	 * @param array<string,mixed> $refund    For a refund: account, mode and day (YYYY-MM-DD)
	 * @param User                $user      Who assigns
	 * @param Translate           $outputlangs Language of the texts stored in Dolibarr
	 * @return int 1 when assigned, 0 when refused (see errors), -1 on error
	 */
	public function assign($invoiceId, $kind, $confirmed, array $refund, $user, $outputlangs)
	{
		global $conf;

		$this->errors = array();
		$row = $this->fetch($invoiceId);
		if ($row === null) {
			$this->errors[] = 'VereineOverpaymentErrorNone';
			return 0;
		}
		$this->errors = VereineOverpaymentRules::check($kind, $row['excess'], $row['state'], $confirmed);
		if ($this->errors) {
			return 0;
		}
		$blocker = $this->blocker($user, $kind);
		if ($blocker !== '') {
			$this->errors[] = $blocker;
			return 0;
		}
		$day = isset($refund['day']) ? (string) $refund['day'] : '';
		if ($kind === VereineOverpaymentRules::KIND_REFUND && (empty($refund['account']) || (int) $refund['account'] < 1 || empty($refund['mode'])
			|| (int) $refund['mode'] < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day))) {
			$this->errors[] = 'VereineOverpaymentErrorBank';
			return 0;
		}

		$this->db->begin();
		// The unique key on the invoice is the lock: a second assignment of the same excess stops here.
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_overpayment (entity, fk_facture, kind, amount, datec, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $row['invoice_id']).", '".$this->db->escape($kind)."', ".((float) $row['excess']).",";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			$this->errors[] = 'VereineOverpaymentErrorAssigned';
			return 0;
		}
		$assignment = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_overpayment');
		if ($kind === VereineOverpaymentRules::KIND_CREDIT) {
			$made = array('fk_discount', $this->credit($row, $user));
		} elseif ($kind === VereineOverpaymentRules::KIND_REFUND) {
			$made = array('fk_payment_various', $this->refund($row, (int) $refund['account'], (int) $refund['mode'], $day, $user, $outputlangs));
		} else {
			$made = array('fk_don', $this->donation($row, $user, $outputlangs));
		}
		if ($made[1] <= 0) {
			$this->db->rollback();
			return -1;
		}
		$ok = $this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_overpayment SET ".$made[0]." = ".((int) $made[1])." WHERE rowid = ".$assignment);
		if (!$ok) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		// Settled: Dolibarr classifies the invoice as paid, as its own button does after a credit.
		if (!$row['is_paid']) {
			require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
			$invoice = new Facture($this->db);
			if ($invoice->fetch($row['invoice_id']) <= 0 || $invoice->setPaid($user) < 0) {
				$this->error = 'invoice '.$row['ref'].': '.$invoice->error;
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::OVERPAYMENT_ASSIGNED, $row['member_id'], $row['socid'],
			$row['ref'].': '.$kind.' '.number_format($row['excess'], 2, '.', ''));
		return 1;
	}

	/**
	 * The excess as a credit of the customer, the way Dolibarr's own button makes it.
	 *
	 * @param array<string,mixed> $row  Overpayment
	 * @param User                $user Who assigns
	 * @return int Id of the discount, -1 on error
	 */
	private function credit(array $row, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/discount.class.php';

		$discount = new DiscountAbsolute($this->db);
		// Without VAT and marked as an excess received, like compta/facture/card.php (confirm_converttoreduc).
		$discount->description = '(EXCESS RECEIVED)';
		$discount->fk_soc = $row['socid'];
		$discount->socid = $row['socid'];
		$discount->fk_facture_source = $row['invoice_id'];
		$discount->amount_ttc = $row['excess'];
		$discount->amount_tva = 0;
		$discount->amount_ht = $row['excess'];
		$discount->tva_tx = 0;
		$discount->vat_src_code = '';
		$id = (int) $discount->create($user);
		if ($id <= 0) {
			$this->error = 'credit: '.$discount->error;
			return -1;
		}
		return $id;
	}

	/**
	 * The excess back to the customer: one of Dolibarr's various payments, money leaving the account.
	 *
	 * @param array<string,mixed> $row         Overpayment
	 * @param int                 $account     Bank account
	 * @param int                 $mode        Payment mode
	 * @param string              $day         Day of payment, YYYY-MM-DD
	 * @param User                $user        Who assigns
	 * @param Translate           $outputlangs Language of the label
	 * @return int Id of the payment, -1 on error
	 */
	private function refund(array $row, $account, $mode, $day, $user, $outputlangs)
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';

		$when = dol_mktime(12, 0, 0, (int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4));
		$payment = new PaymentVarious($this->db);
		$payment->datep = $when;
		$payment->datev = $when;
		// 0 is money leaving the account.
		$payment->sens = 0;
		$payment->amount = $row['excess'];
		$payment->type_payment = (int) $mode;
		$payment->fk_account = (int) $account;
		$payment->label = dol_trunc($outputlangs->transnoentities('VereineOverpaymentRefundLabel', $row['ref'], $row['partner']), 250, 'right', 'UTF-8', 1);
		$payment->note = '';
		$id = (int) $payment->create($user);
		if ($id <= 0) {
			$this->error = 'refund: '.$payment->error;
			return -1;
		}
		return $id;
	}

	/**
	 * The excess as a donation in Dolibarr's donation module: received with the payment that brought it.
	 *
	 * No bank line: the money came in with the payment of the invoice already.
	 *
	 * @param array<string,mixed> $row         Overpayment
	 * @param User                $user        Who assigns
	 * @param Translate           $outputlangs Language of the note
	 * @return int Id of the donation, -1 on error
	 */
	private function donation(array $row, $user, $outputlangs)
	{
		require_once DOL_DOCUMENT_ROOT.'/don/class/don.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';

		$company = new Societe($this->db);
		if ($company->fetch($row['socid']) <= 0) {
			$this->error = 'donation: third party '.$row['socid'].' not found';
			return -1;
		}
		$last = $this->lastPayment($row['invoice_id']);
		$don = new Don($this->db);
		$don->date = $last !== null ? $last['date'] : dol_now();
		$don->amount = $row['excess'];
		$don->socid = $row['socid'];
		$don->modepaymentid = $last !== null ? $last['mode'] : 0;
		$don->address = (string) $company->address;
		$don->zip = (string) $company->zip;
		$don->town = (string) $company->town;
		$don->country_id = (int) $company->country_id;
		$don->email = (string) $company->email;
		$member = new Adherent($this->db);
		if ($row['member_id'] > 0 && $member->fetch($row['member_id']) > 0) {
			// A member gives as a person: first and last name, as the donation report needs them later (#6).
			$don->firstname = (string) $member->firstname;
			$don->lastname = (string) $member->lastname;
			$don->societe = (string) $member->company;
			foreach (array('address', 'zip', 'town', 'email') as $field) {
				if ($don->$field === '' && (string) $member->$field !== '') {
					$don->$field = (string) $member->$field;
				}
			}
		} else {
			$don->societe = (string) $company->name;
		}
		// Not published unless the donor says so on Dolibarr's donation card.
		$don->public = 0;
		$don->note_private = $outputlangs->transnoentities('VereineOverpaymentDonationNote', $row['ref'], price($row['excess'], 0, $outputlangs, 1, -1, 2).' €');
		$id = (int) $don->create($user);
		if ($id <= 0) {
			$this->error = 'donation: '.$don->error;
			return -1;
		}
		$don->id = $id;
		if ($don->valid_promesse($id, $user->id) < 0 || $don->setPaid($id, $don->modepaymentid) < 0) {
			$this->error = 'donation: '.$don->error;
			return -1;
		}
		// On both cards: the invoice shows the donation, the donation its invoice.
		if ($don->add_object_linked('facture', $row['invoice_id']) < 0) {
			$this->error = 'donation link: '.$don->error;
			return -1;
		}
		return $id;
	}

	/**
	 * Donations made of excesses, for the income and expenditure account.
	 *
	 * @param int[] $invoiceIds Invoices
	 * @return array<int,array{amount:float,payment:int}> Invoice => the excess and the payment that brought it
	 */
	public function donations(array $invoiceIds)
	{
		global $conf;

		$found = array();
		$ids = array_filter(array_map('intval', $invoiceIds));
		if (!$ids) {
			return $found;
		}
		$sql = "SELECT o.fk_facture, o.amount, (SELECT MAX(pf.fk_paiement) FROM ".MAIN_DB_PREFIX."paiement_facture as pf WHERE pf.fk_facture = o.fk_facture) as payment";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_overpayment as o WHERE o.entity = ".((int) $conf->entity);
		$sql .= " AND o.kind = '".VereineOverpaymentRules::KIND_DONATION."' AND o.fk_facture IN (".implode(',', $ids).")";
		// Before the module is enabled again after an update, the table may still be missing: then there is none.
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$found[(int) $obj->fk_facture] = array('amount' => round((float) $obj->amount, 2), 'payment' => (int) $obj->payment);
		}
		return $found;
	}
}
