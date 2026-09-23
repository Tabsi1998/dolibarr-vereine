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
 * \file    class/vereinevolunteerpayouts.class.php
 * \ingroup vereine
 * \brief   Paying volunteer allowances (#7): a list that is signed first and paid through Dolibarr afterwards.
 *
 * Paying out is a matter of money, so it goes the way the statutes want money matters to go: the list of
 * who gets what is a PDF, the officers sign it (by default the chair and the treasurer, #119), and only
 * then the money leaves. It leaves as Dolibarr's own various payments, one per person, so the bank
 * account, the reconciliation and the income and expenditure account see it like any other payment.
 */

require_once __DIR__.'/vereinevolunteerrules.class.php';
require_once __DIR__.'/vereinesignatures.class.php';
require_once __DIR__.'/vereinesignaturerules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * The payouts of volunteer allowances.
 */
class VereineVolunteerPayouts
{
	/** A list that is not paid yet. */
	const STATUS_DRAFT = 'draft';
	/** A list that went out through the bank. */
	const STATUS_PAID = 'paid';

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
	 * Where the lists are kept.
	 *
	 * @return string
	 */
	public static function directory()
	{
		global $conf;

		return $conf->vereine->dir_output.'/freiwilligenpauschale';
	}

	/**
	 * The file of a list.
	 *
	 * @param int $id Payout
	 * @return string
	 */
	public static function path($id)
	{
		return self::directory().'/auszahlung-'.((int) $id).'.pdf';
	}

	/**
	 * Every payout, the newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all()
	{
		global $conf;

		$sql = "SELECT rowid, label, total, status, fk_bank_account, fk_payment_mode, paid_on, datec FROM ".MAIN_DB_PREFIX."vereine_volunteer_payout";
		$sql .= " WHERE entity = ".((int) $conf->entity)." ORDER BY rowid DESC";
		// The table exists only after the module was enabled with 0.9.0.
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array('id' => (int) $obj->rowid, 'label' => (string) $obj->label, 'total' => round((float) $obj->total, 2),
				'status' => (string) $obj->status, 'bank_account' => (int) $obj->fk_bank_account, 'payment_mode' => (int) $obj->fk_payment_mode,
				'paid_on' => $obj->paid_on ? substr((string) $obj->paid_on, 0, 10) : '', 'created' => substr((string) $obj->datec, 0, 10));
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * One payout, null when there is none.
	 *
	 * @param int $id Payout
	 * @return array<string,mixed>|null
	 */
	public function fetch($id)
	{
		foreach ($this->all() as $payout) {
			if ($payout['id'] === (int) $id) {
				return $payout;
			}
		}
		return null;
	}

	/**
	 * The entries of a payout.
	 *
	 * @param int $id Payout
	 * @return array<int,array<string,mixed>>
	 */
	public function entriesOf($id)
	{
		global $conf;

		$sql = "SELECT v.rowid, v.fk_adherent, v.duty_day, v.activity, v.kind, v.amount, v.paid_on, v.fk_payment, d.firstname, d.lastname";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_volunteer as v INNER JOIN ".MAIN_DB_PREFIX."adherent as d ON d.rowid = v.fk_adherent";
		$sql .= " WHERE v.entity = ".((int) $conf->entity)." AND v.fk_payout = ".((int) $id)." ORDER BY d.lastname, d.firstname, v.duty_day";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array('id' => (int) $obj->rowid, 'member_id' => (int) $obj->fk_adherent,
				'name' => trim((string) $obj->firstname.' '.(string) $obj->lastname), 'day' => substr((string) $obj->duty_day, 0, 10),
				'activity' => (string) $obj->activity, 'kind' => (string) $obj->kind, 'amount' => round((float) $obj->amount, 2),
				'paid_on' => $obj->paid_on ? substr((string) $obj->paid_on, 0, 10) : '', 'payment_id' => (int) $obj->fk_payment);
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * Entries that are neither paid nor on a list yet.
	 *
	 * @param int $year Calendar year
	 * @return int[] Their ids
	 */
	public function openEntryIds($year)
	{
		global $conf;

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_volunteer WHERE entity = ".((int) $conf->entity);
		$sql .= " AND fk_payout IS NULL AND paid_on IS NULL";
		$sql .= " AND duty_day >= '".sprintf('%04d', (int) $year)."-01-01' AND duty_day <= '".sprintf('%04d', (int) $year)."-12-31'";
		$resql = $this->db->query($sql);
		$ids = array();
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$ids[] = (int) $obj->rowid;
		}
		return $ids;
	}

	/**
	 * Put entries on a new list and make its PDF, ready to be signed.
	 *
	 * @param int[]     $entryIds    Entries; only open ones are taken
	 * @param int       $year        Calendar year the entries belong to
	 * @param User      $user        Who makes the list
	 * @param Translate $outputlangs Language of the PDF
	 * @return int The payout, 0 when refused (see errors), -1 on error
	 */
	public function create(array $entryIds, $year, $user, $outputlangs)
	{
		global $conf;

		$open = array_intersect(array_map('intval', $entryIds), $this->openEntryIds($year));
		if (!$open) {
			$this->errors[] = 'VereineVolunteerPayoutErrorEmpty';
			return 0;
		}
		$entity = (int) $conf->entity;
		$this->db->begin();
		$label = $outputlangs->transnoentities('VereineVolunteerPayoutLabel', (int) $year, dol_print_date(dol_now(), 'day', 'tzserver', $outputlangs));
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_volunteer_payout (entity, label, total, status, datec, fk_user_modif)";
		$sql .= " VALUES (".$entity.", '".$this->db->escape(dol_trunc($label, 125, 'right', 'UTF-8', 1))."', 0, '".self::STATUS_DRAFT."',";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_volunteer_payout');
		// Only entries that are still open: two lists made at the same time never share an entry.
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_volunteer SET fk_payout = ".$id." WHERE entity = ".$entity;
		$sql .= " AND fk_payout IS NULL AND paid_on IS NULL AND rowid IN (".implode(', ', $open).")";
		$sql2 = "UPDATE ".MAIN_DB_PREFIX."vereine_volunteer_payout SET total = (SELECT COALESCE(SUM(amount), 0) FROM ".MAIN_DB_PREFIX."vereine_volunteer";
		$sql2 .= " WHERE entity = ".$entity." AND fk_payout = ".$id.") WHERE rowid = ".$id;
		if (!$this->db->query($sql) || !$this->db->query($sql2)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		if ($this->buildPdf($id, $outputlangs) === '') {
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::VOLUNTEER_PAYOUT, 0, 0, $label.': '.count($open));
		return $id;
	}

	/**
	 * The PDF of a list: who gets what, per person, with the days behind it, to be signed.
	 *
	 * @param int       $id          Payout
	 * @param Translate $outputlangs Language
	 * @return string The file, empty on error
	 */
	public function buildPdf($id, $outputlangs)
	{
		global $mysoc;

		require_once __DIR__.'/vereinepdf.class.php';
		require_once __DIR__.'/vereineorganization.class.php';
		require_once dirname(__DIR__).'/lib/vereine.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

		$payout = $this->fetch($id);
		if ($payout === null) {
			$this->error = 'no payout '.((int) $id);
			return '';
		}
		$outputlangs->loadLangs(array('main', 'vereine@vereine'));
		$file = self::path($id);
		if (dol_mkdir(dirname($file)) < 0) {
			$this->error = 'cannot create '.dirname($file);
			return '';
		}
		$organization = VereineOrganization::load($mysoc);
		$entries = $this->entriesOf($id);
		$pdf = VereinePdf::start($outputlangs);
		$font = pdf_getPDFFont($outputlangs);
		$line = function ($text, $style = '', $size = 10) use ($pdf, $font) {
			$pdf->SetFont($font, $style, $size);
			$pdf->MultiCell(0, 5, $text, 0, 'L');
		};
		$money = function ($amount) use ($outputlangs) {
			return price($amount, 0, $outputlangs, 1, -1, 2).' €';
		};
		VereinePdf::title($pdf, $outputlangs, $outputlangs->transnoentities('VereineVolunteerPayoutTitle'), $payout['label']);
		$line($outputlangs->transnoentities('VereineVolunteerPayoutFor', $organization['name']));
		foreach (VereineVolunteerRules::payoutLines($entries) as $person) {
			VereinePdf::heading($pdf, $outputlangs, $person['name'].' – '.$money($person['amount']));
			foreach ($entries as $entry) {
				if ($entry['member_id'] === $person['member_id']) {
					$line(vereineFormatDay($entry['day']).' · '.$outputlangs->transnoentitiesnoconv('VereineVolunteerKind_'.$entry['kind'])
						.' · '.$entry['activity'].' · '.$money($entry['amount']), '', 9);
				}
			}
		}
		$pdf->Ln(3);
		$line($outputlangs->transnoentities('VereineVolunteerPayoutTotal', $money($payout['total'])), 'B');
		$line($outputlangs->transnoentitiesnoconv('VereineVolunteerPayoutNote'), '', 8);
		VereinePdf::finish($pdf, $outputlangs, $outputlangs->transnoentities('VereineVolunteerPayoutTitle'));
		$pdf->Output($file, 'F');
		return $file;
	}

	/**
	 * Whether a list may be paid: nobody has to sign money matters, or everybody who has to did.
	 *
	 * @param int $id Payout
	 * @return bool
	 */
	public function payable($id)
	{
		$signatures = new VereineSignatures($this->db);
		$wanted = VereineSignatureRules::wanted($signatures->rules(), VereineSignatureRules::KIND_PAYOUT);
		$run = $signatures->current(VereineSignatureRules::KIND_PAYOUT, (int) $id, self::path($id));
		return VereineVolunteerRules::payable($wanted, $run !== null ? (string) $run['status'] : '',
			$run !== null && !empty($run['document_changed']));
	}

	/**
	 * Pay a signed list: one of Dolibarr's various payments per person, from one bank account.
	 *
	 * Everything or nothing: when one payment cannot be made, none is kept.
	 *
	 * @param int    $id          Payout
	 * @param int    $bankAccount Dolibarr bank account
	 * @param int    $paymentMode Dolibarr payment mode, such as a transfer
	 * @param string $day         Day of payment, YYYY-MM-DD
	 * @param User   $user        Who pays
	 * @return int Number of payments made, 0 when refused (see errors), -1 on error
	 */
	public function pay($id, $bankAccount, $paymentMode, $day, $user)
	{
		global $conf;

		$payout = $this->fetch($id);
		if ($payout === null || $payout['status'] !== self::STATUS_DRAFT) {
			$this->errors[] = 'VereineVolunteerPayoutErrorNotDraft';
			return 0;
		}
		if (!$this->payable($id)) {
			$this->errors[] = 'VereineVolunteerPayoutErrorNotSigned';
			return 0;
		}
		if ((int) $bankAccount < 1 || (int) $paymentMode < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $day)) {
			$this->errors[] = 'VereineVolunteerPayoutErrorBank';
			return 0;
		}
		if (!isModEnabled('bank')) {
			$this->errors[] = 'VereineVolunteerPayoutErrorNoBank';
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';

		$entity = (int) $conf->entity;
		$entries = $this->entriesOf($id);
		$when = dol_mktime(12, 0, 0, (int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4));
		$made = 0;
		$this->db->begin();
		foreach (VereineVolunteerRules::payoutLines($entries) as $person) {
			$payment = new PaymentVarious($this->db);
			$payment->datep = $when;
			$payment->datev = $when;
			// 0 is money leaving the account.
			$payment->sens = 0;
			$payment->amount = $person['amount'];
			$payment->type_payment = (int) $paymentMode;
			$payment->fk_account = (int) $bankAccount;
			$payment->label = dol_trunc($payout['label'].' – '.$person['name'], 250, 'right', 'UTF-8', 1);
			$payment->note = '';
			$paymentId = (int) $payment->create($user);
			if ($paymentId <= 0) {
				$this->error = 'payment for '.$person['name'].': '.$payment->error;
				$this->db->rollback();
				return -1;
			}
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_volunteer SET paid_on = '".$this->db->escape($day)."', fk_payment = ".$paymentId;
			$sql .= ", fk_user_modif = ".((int) $user->id)." WHERE entity = ".$entity." AND fk_payout = ".((int) $id);
			$sql .= " AND fk_adherent = ".((int) $person['member_id']);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
			$made++;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_volunteer_payout SET status = '".self::STATUS_PAID."', paid_on = '".$this->db->escape($day)."',";
		$sql .= " fk_bank_account = ".((int) $bankAccount).", fk_payment_mode = ".((int) $paymentMode).", fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".$entity;
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::VOLUNTEER_PAID, 0, 0, $payout['label'].': '.$made);
		return $made;
	}

	/**
	 * Take back a list that was not paid: its entries are open again, the list is gone.
	 *
	 * @param int  $id   Payout
	 * @param User $user Who takes it back
	 * @return int 1 when taken back, 0 when refused, -1 on error
	 */
	public function cancel($id, $user)
	{
		global $conf;

		$payout = $this->fetch($id);
		if ($payout === null || $payout['status'] !== self::STATUS_DRAFT) {
			$this->errors[] = 'VereineVolunteerPayoutErrorNotDraft';
			return 0;
		}
		$entity = (int) $conf->entity;
		$this->db->begin();
		$release = "UPDATE ".MAIN_DB_PREFIX."vereine_volunteer SET fk_payout = NULL WHERE entity = ".$entity." AND fk_payout = ".((int) $id);
		$remove = "DELETE FROM ".MAIN_DB_PREFIX."vereine_volunteer_payout WHERE rowid = ".((int) $id)." AND entity = ".$entity;
		if (!$this->db->query($release) || !$this->db->query($remove)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		dol_delete_file(self::path($id));
		VereineLog::add($this->db, $user, VereineLog::VOLUNTEER_PAYOUT, 0, 0, $payout['label'].': cancelled');
		return 1;
	}

	/**
	 * Bank accounts and payment modes Dolibarr knows, for the form.
	 *
	 * @return array{accounts:array<int,string>,modes:array<int,string>}
	 */
	public function bankChoices()
	{
		global $conf;

		$choices = array('accounts' => array(), 'modes' => array());
		$resql = $this->db->query("SELECT rowid, label FROM ".MAIN_DB_PREFIX."bank_account WHERE entity = ".((int) $conf->entity)." AND clos = 0 ORDER BY label");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$choices['accounts'][(int) $obj->rowid] = (string) $obj->label;
		}
		$resql = $this->db->query("SELECT id, code, libelle FROM ".MAIN_DB_PREFIX."c_paiement WHERE active = 1 AND entity IN (0, ".((int) $conf->entity).") ORDER BY libelle");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$choices['modes'][(int) $obj->id] = (string) $obj->libelle.' ('.(string) $obj->code.')';
		}
		return $choices;
	}
}
