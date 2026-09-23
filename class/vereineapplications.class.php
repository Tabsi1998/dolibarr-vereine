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
 */

/**
 * \file    class/vereineapplications.class.php
 * \ingroup vereine
 * \brief   Membership applications with their state: look at them, take somebody in, say no (#72).
 *
 * Taking somebody in happens in Dolibarr and validates the member there; the website can only send an
 * application and take its own one back. What the person is told when the association says no is kept
 * apart from the internal note.
 */

require_once __DIR__.'/vereineapplicationrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Membership applications.
 */
class VereineApplications
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
	 * The applications, newest first.
	 *
	 * @param string $status Only this state, empty for every one
	 * @param int    $limit  Maximum number
	 * @return array<int,array<string,mixed>>
	 */
	public function all($status = '', $limit = 200)
	{
		global $conf;

		$sql = "SELECT a.rowid, a.external_id, a.fk_adherent, a.datec, a.status, a.decided_on, a.fk_user_decided, a.reason, a.note,";
		$sql .= " d.ref, d.firstname, d.lastname, d.email, d.statut as member_status, d.fk_adherent_type";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_application as a";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."adherent as d ON d.rowid = a.fk_adherent";
		$sql .= " WHERE a.entity = ".((int) $conf->entity);
		if (in_array($status, VereineApplicationRules::STATUSES, true)) {
			$sql .= " AND a.status = '".$this->db->escape($status)."'";
		}
		$sql .= " ORDER BY a.datec DESC, a.rowid DESC".$this->db->plimit((int) $limit, 0);
		$rows = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$rows[] = array(
				'id' => (int) $obj->rowid,
				'external_id' => (string) $obj->external_id,
				'member_id' => (int) $obj->fk_adherent,
				'received' => (int) $this->db->jdate($obj->datec),
				'status' => (string) $obj->status,
				'decided_on' => (string) $obj->decided_on,
				'decided_by' => (int) $obj->fk_user_decided,
				'reason' => (string) $obj->reason,
				'note' => (string) $obj->note,
				'ref' => (string) $obj->ref,
				'name' => trim($obj->firstname.' '.$obj->lastname),
				'email' => (string) $obj->email,
				'member_status' => (int) $obj->member_status,
				'type_id' => (int) $obj->fk_adherent_type,
			);
		}
		return $rows;
	}

	/**
	 * One application by its row, or by the id the website gave it.
	 *
	 * @param int    $id         Row of the application, 0 to look by the external id
	 * @param string $externalId Id of the website
	 * @return array<string,mixed>|null
	 */
	public function fetch($id, $externalId = '')
	{
		global $conf;

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_application WHERE entity = ".((int) $conf->entity);
		$sql .= (int) $id > 0 ? " AND rowid = ".((int) $id) : " AND external_id = '".$this->db->escape($externalId)."'";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		foreach ($this->all('', 1000) as $row) {
			if ($row['id'] === (int) $obj->rowid) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Other members that look like the applicant, so nobody is taken in twice.
	 *
	 * @param array<string,mixed> $application Application of all()
	 * @return array<int,array{id:int,ref:string,name:string,email:string}>
	 */
	public function lookalikes(array $application)
	{
		$email = trim((string) $application['email']);
		$name = trim((string) $application['name']);
		if ($email === '' && $name === '') {
			return array();
		}
		$where = array();
		if ($email !== '') {
			$where[] = "LOWER(d.email) = '".$this->db->escape(strtolower($email))."'";
		}
		if ($name !== '') {
			$where[] = "LOWER(TRIM(CONCAT(COALESCE(d.firstname, ''), ' ', COALESCE(d.lastname, '')))) = '".$this->db->escape(strtolower($name))."'";
		}
		$sql = "SELECT d.rowid, d.ref, d.firstname, d.lastname, d.email FROM ".MAIN_DB_PREFIX."adherent as d";
		$sql .= " WHERE d.entity IN (".getEntity('member').") AND d.rowid <> ".((int) $application['member_id']);
		$sql .= " AND (".implode(" OR ", $where).") ORDER BY d.rowid";
		$rows = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$rows[] = array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'name' => trim($obj->firstname.' '.$obj->lastname), 'email' => (string) $obj->email);
		}
		return $rows;
	}

	/**
	 * Take the applicant in, say no, or note that the board is looking at it. Taking somebody in
	 * validates the member in Dolibarr.
	 *
	 * @param int    $id     Application
	 * @param string $status New state, see VereineApplicationRules
	 * @param string $reason What the person is told when the association says no
	 * @param string $note   Internal note, never sent to the person
	 * @param User   $user   Who decides
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function decide($id, $status, $reason, $note, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';

		$this->errors = array();
		$application = $this->fetch((int) $id);
		if ($application === null) {
			$this->errors[] = 'VereineApplicationErrorGone';
			return 0;
		}
		if (!VereineApplicationRules::allows($application['status'], $status)) {
			$this->errors[] = 'VereineApplicationErrorStep';
			return 0;
		}
		$reason = VereineApplicationRules::reason($reason);
		if ($status === VereineApplicationRules::REJECTED && $reason === '') {
			$this->errors[] = 'VereineApplicationErrorReason';
			return 0;
		}
		$this->db->begin();
		if ($status === VereineApplicationRules::ACCEPTED) {
			$member = new Adherent($this->db);
			if ($member->fetch($application['member_id']) <= 0) {
				$this->error = 'member '.$application['member_id'].' is gone';
				$this->db->rollback();
				return -1;
			}
			if ((int) $member->statut !== 1 && $member->validate($user) <= 0) {
				$this->error = 'validate member: '.$member->error;
				$this->db->rollback();
				return -1;
			}
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_application SET status = '".$this->db->escape($status)."',";
		$sql .= " decided_on = '".$this->db->idate(dol_now())."', fk_user_decided = ".((int) $user->id).",";
		$sql .= " reason = '".$this->db->escape($reason)."', note = '".$this->db->escape(VereineApplicationRules::reason($note))."'";
		$sql .= " WHERE rowid = ".((int) $application['id'])." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::APPLICATION_DECIDED, (int) $application['member_id'], 0, $status.($reason !== '' ? ': '.$reason : ''));
		return 1;
	}

	/**
	 * Take an application back, as the website that sent it may do.
	 *
	 * @param string $externalId Id of the website
	 * @param User   $user       API user
	 * @return array{external_id:string,status:string,changed:bool}|null Null when it cannot be withdrawn, see errors
	 */
	public function withdraw($externalId, $user)
	{
		$this->errors = array();
		$application = $this->fetch(0, (string) $externalId);
		if ($application === null) {
			return null;
		}
		if ($application['status'] === VereineApplicationRules::WITHDRAWN) {
			// Sent twice: it stays withdrawn, and nothing is written again.
			return array('external_id' => $application['external_id'], 'status' => $application['status'], 'changed' => false);
		}
		if (!VereineApplicationRules::allows($application['status'], VereineApplicationRules::WITHDRAWN)) {
			$this->errors[] = 'VereineApplicationErrorStep';
			return null;
		}
		if ($this->decide($application['id'], VereineApplicationRules::WITHDRAWN, '', '', $user) <= 0) {
			return null;
		}
		return array('external_id' => $application['external_id'], 'status' => VereineApplicationRules::WITHDRAWN, 'changed' => true);
	}
}
