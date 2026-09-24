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
 * \file    class/vereineloans.class.php
 * \ingroup vereine
 * \brief   Lending the association's equipment to members (#26).
 *
 * The equipment is Dolibarr's module Resources; reservations for events are its own links between a
 * resource and an agenda event. The module adds the loans: who has what until when, in which state it
 * left and came back, and a reminder to the borrower when it is late.
 */

require_once __DIR__.'/vereineloanrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Loans of equipment.
 */
class VereineLoans
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * @var string[] Messages for the person
	 */
	public $errors = array();

	/**
	 * @var string What the scheduled job did
	 */
	public $output = '';

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
	 * The association's equipment from Dolibarr's resources, each with its open loan and next reservation.
	 *
	 * @param string $today Today, YYYY-MM-DD
	 * @return array<int,array{id:int,ref:string,asset_number:string,type:string,loan:array<string,mixed>|null,reserved:array{label:string,day:string,id:int}|null}>
	 */
	public function resources($today)
	{
		$open = array();
		foreach ($this->loans(0, 0, true) as $loan) {
			$open[$loan['resource_id']] = $loan;
		}
		$sql = "SELECT r.rowid, r.ref, r.asset_number, t.label as type FROM ".MAIN_DB_PREFIX."resource as r";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_type_resource as t ON t.code = r.fk_code_type_resource";
		$sql .= " WHERE r.entity IN (".getEntity('resource').") ORDER BY r.ref, r.rowid";
		$list = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$id = (int) $obj->rowid;
			$list[] = array('id' => $id, 'ref' => (string) $obj->ref, 'asset_number' => (string) $obj->asset_number, 'type' => (string) $obj->type,
				'loan' => isset($open[$id]) ? $open[$id] : null, 'reserved' => $this->nextReservation($id, $today));
		}
		return $list;
	}

	/**
	 * Lend a piece of equipment to a member.
	 *
	 * @param array<string,mixed> $entered resource_id, member_id, issued_on, due_on, condition
	 * @param string              $today   Today
	 * @param User                $user    Who hands it out
	 * @return int Id of the loan, 0 when refused (see errors), -1 on error
	 */
	public function lend(array $entered, $today, $user)
	{
		global $conf;

		$checked = VereineLoanRules::check($entered, $today);
		$this->errors = $checked['errors'];
		$loan = $checked['loan'];
		if (!$this->errors) {
			$resource = (int) $this->value("SELECT COUNT(*) as v FROM ".MAIN_DB_PREFIX."resource WHERE rowid = ".$loan['resource_id']." AND entity IN (".getEntity('resource').")");
			$member = (int) $this->value("SELECT COUNT(*) as v FROM ".MAIN_DB_PREFIX."adherent WHERE rowid = ".$loan['member_id']." AND statut = 1");
			$lent = (int) $this->value("SELECT COUNT(*) as v FROM ".MAIN_DB_PREFIX."vereine_loan WHERE entity = ".((int) $conf->entity)." AND fk_resource = ".$loan['resource_id']
				." AND returned_on IS NULL");
			if ($resource === 0) {
				$this->errors[] = 'VereineLoanErrorResource';
			} elseif ($lent > 0) {
				$this->errors[] = 'VereineLoanErrorLent';
			}
			if ($member === 0) {
				$this->errors[] = 'VereineLoanErrorMember';
			}
		}
		if ($this->errors) {
			return 0;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_loan (entity, fk_resource, fk_adherent, issued_on, due_on, condition_out, fk_user_out, datec) VALUES (".((int) $conf->entity).",";
		$sql .= " ".$loan['resource_id'].", ".$loan['member_id'].", '".$this->db->escape($loan['issued_on'])."', '".$this->db->escape($loan['due_on'])."',";
		$sql .= " '".$this->db->escape($loan['condition'])."', ".((int) $user->id).", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_loan');
		VereineLog::add($this->db, $user, VereineLog::LOAN, $loan['member_id'], 0, 'resource '.$loan['resource_id'].' until '.$loan['due_on']);
		return $id;
	}

	/**
	 * Take a piece of equipment back.
	 *
	 * @param int    $loanId    Loan
	 * @param string $day       Day it came back
	 * @param string $condition State it came back in
	 * @param string $today     Today
	 * @param User   $user      Who takes it back
	 * @return int 1 when done, 0 when refused (see errors), -1 on error
	 */
	public function giveBack($loanId, $day, $condition, $today, $user)
	{
		global $conf;

		$this->errors = array();
		$loan = null;
		foreach ($this->loans(0, 0, true) as $open) {
			if ($open['id'] === (int) $loanId) {
				$loan = $open;
			}
		}
		$condition = trim((string) $condition);
		if ($loan === null) {
			$this->errors[] = 'VereineLoanErrorUnknown';
		} elseif (!VereineLoanRules::isDay($day) || $day > $today || $day < $loan['issued_on']) {
			$this->errors[] = 'VereineLoanErrorReturned';
		}
		if (mb_strlen($condition, 'UTF-8') > VereineLoanRules::CONDITION_MAX) {
			$this->errors[] = 'VereineLoanErrorCondition';
		}
		if ($this->errors) {
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_loan SET returned_on = '".$this->db->escape($day)."', condition_in = '".$this->db->escape($condition)."', fk_user_in = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $loanId)." AND entity = ".((int) $conf->entity)." AND returned_on IS NULL";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::LOAN_RETURNED, $loan['member_id'], 0, 'resource '.$loan['resource_id']);
		return 1;
	}

	/**
	 * Loans, newest first.
	 *
	 * @param int  $memberId Only this member's, 0 for all
	 * @param int  $limit    At most this many, 0 for all
	 * @param bool $openOnly Only the ones still out
	 * @return array<int,array{id:int,resource_id:int,ref:string,member_id:int,name:string,email:string,issued_on:string,due_on:string,returned_on:string,condition_out:string,condition_in:string,reminded_at:string}>
	 */
	public function loans($memberId = 0, $limit = 0, $openOnly = false)
	{
		global $conf;

		$sql = "SELECT l.rowid, l.fk_resource, l.fk_adherent, l.issued_on, l.due_on, l.returned_on, l.condition_out, l.condition_in, l.reminded_at,";
		$sql .= " r.ref, a.firstname, a.lastname, a.email FROM ".MAIN_DB_PREFIX."vereine_loan as l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."resource as r ON r.rowid = l.fk_resource LEFT JOIN ".MAIN_DB_PREFIX."adherent as a ON a.rowid = l.fk_adherent";
		$sql .= " WHERE l.entity = ".((int) $conf->entity).((int) $memberId > 0 ? " AND l.fk_adherent = ".((int) $memberId) : "").($openOnly ? " AND l.returned_on IS NULL" : "");
		$sql .= " ORDER BY l.issued_on DESC, l.rowid DESC".((int) $limit > 0 ? $this->db->plimit((int) $limit) : "");
		$list = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$list[] = array('id' => (int) $obj->rowid, 'resource_id' => (int) $obj->fk_resource, 'ref' => (string) $obj->ref, 'member_id' => (int) $obj->fk_adherent,
				'name' => trim($obj->firstname.' '.$obj->lastname), 'email' => (string) $obj->email, 'issued_on' => substr((string) $obj->issued_on, 0, 10),
				'due_on' => substr((string) $obj->due_on, 0, 10), 'returned_on' => substr((string) $obj->returned_on, 0, 10),
				'condition_out' => (string) $obj->condition_out, 'condition_in' => (string) $obj->condition_in, 'reminded_at' => substr((string) $obj->reminded_at, 0, 10));
		}
		return $list;
	}

	/**
	 * How many loans are late; for the list of what is to do, without names.
	 *
	 * @param string $today Today
	 * @return int
	 */
	public function overdue($today)
	{
		global $conf;

		return (int) $this->value("SELECT COUNT(*) as v FROM ".MAIN_DB_PREFIX."vereine_loan WHERE entity = ".((int) $conf->entity)
			." AND returned_on IS NULL AND due_on < '".$this->db->escape($today)."'");
	}

	/**
	 * Scheduled job: remind the borrowers of late loans, once a week, at their own address only.
	 *
	 * @return int 0 when every reminder went out, else the number that did not
	 */
	public function runDue()
	{
		global $conf, $langs, $mysoc, $user;

		require_once __DIR__.'/vereinemail.class.php';

		$langs->loadLangs(array('main', 'members', 'vereine@vereine'));
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$mail = new VereineMail($this->db);
		$sent = 0;
		$failed = 0;
		$skipped = 0;
		foreach ($this->loans(0, 0, true) as $loan) {
			if (!VereineLoanRules::remind($loan['due_on'], $loan['returned_on'], $loan['reminded_at'], $today)) {
				continue;
			}
			// A reminder goes to the borrower's own address or nowhere: never to somebody else.
			if (!isValidEmail($loan['email'])) {
				$skipped++;
				continue;
			}
			$subject = $langs->transnoentitiesnoconv('VereineLoanReminderSubject', $loan['ref']);
			$body = $langs->transnoentitiesnoconv('VereineLoanReminderBody', $loan['name'], $loan['ref'], dol_print_date(dol_stringtotime($loan['due_on']), 'day'),
				(string) $mysoc->name);
			if (!$mail->send($subject, $loan['email'], $body, 'loan'.$loan['id'])) {
				$failed++;
				$this->error = $mail->error;
				continue;
			}
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_loan SET reminded_at = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $loan['id'])
				." AND entity = ".((int) $conf->entity));
			VereineLog::add($this->db, $user, VereineLog::LOAN_REMINDED, $loan['member_id'], 0, 'resource '.$loan['resource_id']);
			$sent++;
		}
		$this->output = $sent.' reminders sent'.($skipped ? ', '.$skipped.' without an address' : '').($failed ? ', '.$failed.' failed: '.$this->error : '');
		return $failed;
	}

	/**
	 * The next agenda event a resource is reserved for, from Dolibarr's links between resources and events.
	 *
	 * @param int    $resourceId Resource
	 * @param string $today      Today
	 * @return array{id:int,label:string,day:string}|null
	 */
	private function nextReservation($resourceId, $today)
	{
		$sql = "SELECT a.id, a.label, a.datep FROM ".MAIN_DB_PREFIX."element_resources as e INNER JOIN ".MAIN_DB_PREFIX."actioncomm as a ON a.id = e.element_id";
		$sql .= " WHERE e.element_type = 'action' AND e.resource_type = 'dolresource' AND e.resource_id = ".((int) $resourceId);
		$sql .= " AND COALESCE(a.datep2, a.datep) >= '".$this->db->escape($today)." 00:00:00' ORDER BY a.datep".$this->db->plimit(1);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? array('id' => (int) $obj->id, 'label' => (string) $obj->label, 'day' => substr((string) $obj->datep, 0, 10)) : null;
	}

	/**
	 * One value of a query.
	 *
	 * @param string $sql Query with one column v
	 * @return string
	 */
	private function value($sql)
	{
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj && $obj->v !== null ? (string) $obj->v : '';
	}
}
