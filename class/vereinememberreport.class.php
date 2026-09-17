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
 * \file    class/vereinememberreport.class.php
 * \ingroup vereine
 * \brief   Summary of a member for a website: membership, fee and open invoices, nothing more.
 */

require_once __DIR__.'/vereinemembersummary.class.php';

/**
 * Reads what a website may show about a member.
 *
 * Birth date, address, phone, notes, bank data and dunning levels are never read here,
 * so they cannot slip into an answer.
 */
class VereineMemberReport
{
	/** Open invoices listed at most per member. */
	const MAX_OPEN_INVOICES = 50;

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
	 * Members of this entity with a member number.
	 *
	 * @param string $ref Member number (Dolibarr's reference of the member)
	 * @return int[]
	 */
	public function idsByRef($ref)
	{
		return $this->ids("d.ref = '".$this->db->escape(trim((string) $ref))."'");
	}

	/**
	 * Members of this entity with an e-mail address, ignoring case and surrounding spaces.
	 *
	 * @param string $email E-mail address
	 * @return int[]
	 */
	public function idsByEmail($email)
	{
		$email = strtolower(trim((string) $email));
		if ($email === '') {
			return array();
		}
		return $this->ids("LOWER(TRIM(d.email)) = '".$this->db->escape($email)."'");
	}

	/**
	 * The summary of a member.
	 *
	 * @param int $id Member id
	 * @return array<string,mixed>|null Null when this entity has no such member
	 */
	public function summary($id)
	{
		global $conf;

		$sql = "SELECT d.rowid, d.ref, d.firstname, d.lastname, d.societe, d.morphy, d.statut, d.datefin, d.datevalid, d.fk_soc,";
		$sql .= " t.rowid as type_id, t.libelle as type_label, t.subscription, t.amount,";
		$sql .= " (SELECT MIN(s.dateadh) FROM ".MAIN_DB_PREFIX."subscription as s WHERE s.fk_adherent = d.rowid) as first_period";
		$sql .= " FROM ".MAIN_DB_PREFIX."adherent as d";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent_type as t ON t.rowid = d.fk_adherent_type";
		$sql .= " WHERE d.rowid = ".((int) $id)." AND d.entity IN (".getEntity('adherent').")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return null;
		}
		$row = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$row) {
			return null;
		}

		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$status = VereineMemberSummary::status($row->statut);
		$paidUntil = VereineMemberSummary::dayOf($row->datefin);
		$validatedOn = VereineMemberSummary::datePart($row->datevalid);
		$required = (int) $row->subscription === 1;
		$fee = VereineMemberSummary::fee($status, $required, $paidUntil, $validatedOn, $today);
		$amount = ($row->amount === null || $row->amount === '') ? null : (float) $row->amount;
		$online = $this->onlinePayment();

		return array(
			'id' => (int) $row->rowid,
			'ref' => (string) $row->ref,
			'firstname' => (string) $row->firstname,
			'lastname' => (string) $row->lastname,
			'company' => $row->morphy === 'mor' ? (string) $row->societe : '',
			'type' => array('id' => (int) $row->type_id, 'label' => (string) $row->type_label),
			'status' => $status,
			'member_since' => VereineMemberSummary::memberSince($status, VereineMemberSummary::dayOf($row->first_period), $validatedOn),
			'paid_until' => $paidUntil,
			'currency' => (string) $conf->currency,
			'fee' => array(
				'required' => $required,
				'status' => $fee['status'],
				'next_due' => $fee['next_due'],
				'amount' => $required ? $amount : null,
				'payment_url' => ($online && $fee['status'] === VereineMemberSummary::FEE_DUE && (string) $row->ref !== '')
					? getOnlinePaymentUrl(0, 'member', (string) $row->ref, $amount === null ? 0 : $amount) : '',
			),
			'open_invoices' => (int) $row->fk_soc > 0 ? $this->openInvoices((int) $row->fk_soc, $today, $online) : array(),
		);
	}

	/**
	 * Validated, unpaid invoices of a third party, oldest first.
	 *
	 * @param int    $socid  Third party of the member
	 * @param string $today  Today, YYYY-MM-DD
	 * @param bool   $online Whether an online payment service is set up
	 * @return array<int,array<string,mixed>>
	 */
	private function openInvoices($socid, $today, $online)
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

		$sql = "SELECT f.rowid, f.datef, f.date_lim_reglement FROM ".MAIN_DB_PREFIX."facture as f";
		$sql .= " WHERE f.fk_soc = ".((int) $socid)." AND f.entity IN (".getEntity('invoice').")";
		$sql .= " AND f.fk_statut = ".((int) Facture::STATUS_VALIDATED)." AND f.paye = 0";
		$sql .= " AND f.type IN (".((int) Facture::TYPE_STANDARD).", ".((int) Facture::TYPE_REPLACEMENT).", ".((int) Facture::TYPE_DEPOSIT).")";
		$sql .= " ORDER BY f.datef, f.rowid";
		$sql .= $this->db->plimit(self::MAX_OPEN_INVOICES);
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$this->db->free($resql);

		$invoices = array();
		foreach ($rows as $obj) {
			$invoice = new Facture($this->db);
			if ($invoice->fetch((int) $obj->rowid) <= 0) {
				continue;
			}
			$dueDate = VereineMemberSummary::dayOf($obj->date_lim_reglement);
			$invoices[] = array(
				'ref' => (string) $invoice->ref,
				'date' => VereineMemberSummary::dayOf($obj->datef),
				'due_date' => $dueDate,
				'total' => (float) price2num($invoice->total_ttc, 'MT'),
				'remaining' => (float) $invoice->getRemainToPay(0),
				'overdue' => VereineMemberSummary::overdue($dueDate, $today),
				'payment_url' => $online ? getOnlinePaymentUrl(0, 'invoice', (string) $invoice->ref) : '',
			);
		}
		return $invoices;
	}

	/**
	 * Whether Dolibarr has an online payment service (Stripe, PayPal or one added by a module).
	 *
	 * @return bool
	 */
	private function onlinePayment()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';

		return count(getValidOnlinePaymentMethods()) > 0;
	}

	/**
	 * Member ids of this entity matching a condition.
	 *
	 * @param string $condition SQL condition on the member table d, escaped by the caller
	 * @return int[]
	 */
	private function ids($condition)
	{
		$sql = "SELECT d.rowid FROM ".MAIN_DB_PREFIX."adherent as d";
		$sql .= " WHERE ".$condition." AND d.entity IN (".getEntity('adherent').")";
		$sql .= " ORDER BY d.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return array();
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
		$this->db->free($resql);
		return $ids;
	}
}
