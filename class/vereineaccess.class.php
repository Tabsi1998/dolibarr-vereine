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
 * \file    class/vereineaccess.class.php
 * \ingroup vereine
 * \brief   The access service for personal operations (#153): one place that decides who may act.
 *
 * Every personal call goes through here, so the same question is answered the same way whatever
 * application asks it: is the calling program allowed to act for people at all, does a binding exist
 * for this person at this program, is it still alive, does it carry the ability that is being used, and
 * is the object the one the binding is for.
 *
 * The service knows nothing about websites. A vereins app, a website and Dolibarr's own portal all use
 * the same contract; none of them gets a special case.
 */

require_once __DIR__.'/vereineidentityrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Bindings of external identities and the decision whether they may act.
 */
class VereineAccess
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
	 * The binding of one person at one client, null when there is none.
	 *
	 * @param string $client  The client, by its login
	 * @param string $subject How the person is called there
	 * @return array<string,mixed>|null
	 */
	public function identity($client, $subject)
	{
		global $conf;

		$sql = "SELECT rowid, entity, client, subject, fk_adherent, fk_soc, fk_application, proof, proof_note,";
		$sql .= " capabilities, linked_at, revoked_at FROM ".MAIN_DB_PREFIX."vereine_identity";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND client = '".$this->db->escape((string) $client)."'";
		$sql .= " AND subject = '".$this->db->escape((string) $subject)."'";
		// The table exists only after the module was enabled with 0.8.0.
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? $this->row($obj) : null;
	}

	/**
	 * One binding by its id, null when there is none.
	 *
	 * @param int $id The binding
	 * @return array<string,mixed>|null
	 */
	public function fetch($id)
	{
		global $conf;

		$sql = "SELECT rowid, entity, client, subject, fk_adherent, fk_soc, fk_application, proof, proof_note,";
		$sql .= " capabilities, linked_at, revoked_at FROM ".MAIN_DB_PREFIX."vereine_identity";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND rowid = ".((int) $id);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? $this->row($obj) : null;
	}

	/**
	 * A row of the table as the rest of the module wants it.
	 *
	 * @param object $obj Row
	 * @return array<string,mixed>
	 */
	private function row($obj)
	{
		return array('id' => (int) $obj->rowid, 'entity' => (int) $obj->entity, 'client' => (string) $obj->client,
			'subject' => (string) $obj->subject, 'member_id' => (int) $obj->fk_adherent, 'socid' => (int) $obj->fk_soc,
			'application_id' => (int) $obj->fk_application, 'proof' => (string) $obj->proof,
			'proof_note' => (string) $obj->proof_note, 'capabilities' => (string) $obj->capabilities,
			'linked_at' => substr((string) $obj->linked_at, 0, 19),
			'revoked_at' => $obj->revoked_at ? substr((string) $obj->revoked_at, 0, 19) : '');
	}

	/**
	 * Every binding of this entity, for the setup.
	 *
	 * @param int $limit At most so many
	 * @return array<int,array<string,mixed>>
	 */
	public function all($limit = 200)
	{
		global $conf;

		$sql = "SELECT rowid, entity, client, subject, fk_adherent, fk_soc, fk_application, proof, proof_note,";
		$sql .= " capabilities, linked_at, revoked_at FROM ".MAIN_DB_PREFIX."vereine_identity";
		$sql .= " WHERE entity = ".((int) $conf->entity)." ORDER BY rowid DESC LIMIT ".((int) $limit);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $this->row($obj);
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * May this caller act for this person, in this way, on this object?
	 *
	 * @param User   $user       The technical client, as Dolibarr authenticated it
	 * @param string $subject    How the person is called at that client
	 * @param string $capability What is to be done
	 * @param string $objectType member or application
	 * @param int    $objectId   Which object, 0 for the one of the binding
	 * @return array{ok:bool,reason:string,identity:array<string,mixed>|null}
	 */
	public function check($user, $subject, $capability, $objectType, $objectId = 0)
	{
		global $conf;

		// The program has to be allowed to act for people at all; that is a right of the association.
		if (!is_object($user) || !$user->hasRight('vereine', 'identity', 'use')) {
			return array('ok' => false, 'reason' => 'VereineIdentityErrorClientRight', 'identity' => null);
		}
		$wrong = VereineIdentityRules::checkSubject($subject);
		if ($wrong !== '') {
			return array('ok' => false, 'reason' => $wrong, 'identity' => null);
		}
		$client = (string) $user->login;
		$identity = $this->identity($client, $subject);
		$refused = VereineIdentityRules::decide($identity, $client, (int) $conf->entity, $capability, $objectType, $objectId);
		return array('ok' => $refused === '', 'reason' => $refused, 'identity' => $identity);
	}

	/**
	 * Make an invitation an administrator hands to one person, once.
	 *
	 * @param string   $client       The client the invitation is for
	 * @param int      $memberId     The member it binds to, 0 for none
	 * @param int      $applicationId The application it binds to, 0 for none
	 * @param string[] $capabilities What the binding may do
	 * @param User     $user         Who makes it
	 * @return string The code, empty when refused (see errors); it is never shown again
	 */
	public function invite($client, $memberId, $applicationId, array $capabilities, $user)
	{
		global $conf;

		$this->errors = array();
		if (trim((string) $client) === '') {
			$this->errors[] = 'VereineIdentityErrorClientName';
		}
		if ((int) $memberId < 1 && (int) $applicationId < 1) {
			$this->errors[] = 'VereineIdentityErrorNothingToBind';
		}
		if ($this->errors) {
			return '';
		}
		$code = VereineIdentityRules::newCode();
		$expires = dol_now() + VereineIdentityRules::INVITE_SECONDS;
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_identity_invite (entity, client, fk_adherent, fk_application,";
		$sql .= " capabilities, code_hash, expires_at, datec, fk_user_modif) VALUES (".((int) $conf->entity).",";
		$sql .= " '".$this->db->escape(trim((string) $client))."', ".((int) $memberId > 0 ? (int) $memberId : "NULL").",";
		$sql .= " ".((int) $applicationId > 0 ? (int) $applicationId : "NULL").",";
		$sql .= " '".$this->db->escape(implode(',', VereineIdentityRules::capabilities($capabilities)))."',";
		$sql .= " '".$this->db->escape($code['hash'])."', '".$this->db->idate($expires)."', '".$this->db->idate(dol_now())."',";
		$sql .= " ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return '';
		}
		VereineLog::add($this->db, $user, VereineLog::IDENTITY_INVITE, (int) $memberId, 0, $client);
		return $code['code'];
	}

	/**
	 * Use an invitation: it makes the binding, once, and dies doing it.
	 *
	 * @param string $client  The client that is calling
	 * @param string $subject How the person is called there
	 * @param string $code    The code from the invitation
	 * @param User   $user    The technical client
	 * @return array{ok:bool,reason:string,identity:array<string,mixed>|null}
	 */
	public function claim($client, $subject, $code, $user)
	{
		global $conf;

		$entity = (int) $conf->entity;
		$wrong = VereineIdentityRules::checkSubject($subject);
		if ($wrong !== '') {
			return array('ok' => false, 'reason' => $wrong, 'identity' => null);
		}
		$now = dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S', 'tzserver');
		$invite = $this->invitation(VereineIdentityRules::hash($code), $entity);
		$refused = VereineIdentityRules::inviteUsable($invite, $client, $now);
		if ($refused !== '') {
			return array('ok' => false, 'reason' => $refused, 'identity' => null);
		}
		// Somebody is already bound to this name at this client: that is a conflict, not a second binding.
		$existing = $this->identity($client, $subject);
		if ($existing !== null && $existing['revoked_at'] === '') {
			return array('ok' => false, 'reason' => 'VereineIdentityErrorTaken', 'identity' => null);
		}
		$this->db->begin();
		// The invitation dies first: two calls at the same time then find it used, and only one wins.
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_identity_invite SET used_at = '".$this->db->idate(dol_now())."',";
		$sql .= " used_subject = '".$this->db->escape((string) $subject)."' WHERE rowid = ".((int) $invite['id']);
		$sql .= " AND entity = ".$entity." AND used_at IS NULL";
		$resql = $this->db->query($sql);
		if (!$resql || (int) $this->db->affected_rows($resql) !== 1) {
			$this->db->rollback();
			return array('ok' => false, 'reason' => 'VereineIdentityErrorUsed', 'identity' => null);
		}
		if ($this->store($client, $subject, (int) $invite['member_id'], (int) $invite['application_id'],
			VereineIdentityRules::PROOF_INVITATION, 'invite '.((int) $invite['id']), $invite['capabilities'], $user) < 1) {
			$this->db->rollback();
			return array('ok' => false, 'reason' => 'VereineIdentityErrorStore', 'identity' => null);
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::IDENTITY_LINKED, (int) $invite['member_id'], 0, $client.': '.$subject);
		return array('ok' => true, 'reason' => '', 'identity' => $this->identity($client, $subject));
	}

	/**
	 * The invitation behind a code, null when there is none.
	 *
	 * @param string $hash   What is kept of the code
	 * @param int    $entity Entity
	 * @return array<string,mixed>|null
	 */
	private function invitation($hash, $entity)
	{
		$sql = "SELECT rowid, client, fk_adherent, fk_application, capabilities, expires_at, used_at";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_identity_invite WHERE entity = ".((int) $entity);
		$sql .= " AND code_hash = '".$this->db->escape((string) $hash)."'";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		return array('id' => (int) $obj->rowid, 'client' => (string) $obj->client, 'member_id' => (int) $obj->fk_adherent,
			'application_id' => (int) $obj->fk_application, 'capabilities' => (string) $obj->capabilities,
			'expires_at' => substr((string) $obj->expires_at, 0, 19),
			'used_at' => $obj->used_at ? substr((string) $obj->used_at, 0, 19) : '');
	}

	/**
	 * Write a binding, or wake a revoked one for the same person.
	 *
	 * @param string   $client        The client
	 * @param string   $subject       The name there
	 * @param int      $memberId      The member, 0 for none
	 * @param int      $applicationId The application, 0 for none
	 * @param string   $proof         How it came about
	 * @param string   $note          A word about the proof
	 * @param mixed    $capabilities  What it may do
	 * @param User     $user          Who writes it
	 * @return int 1 when written, -1 on error
	 */
	public function store($client, $subject, $memberId, $applicationId, $proof, $note, $capabilities, $user)
	{
		global $conf;

		$entity = (int) $conf->entity;
		$socid = 0;
		if ((int) $memberId > 0) {
			$resql = $this->db->query("SELECT fk_soc FROM ".MAIN_DB_PREFIX."adherent WHERE rowid = ".((int) $memberId));
			$obj = $resql ? $this->db->fetch_object($resql) : null;
			$socid = $obj ? (int) $obj->fk_soc : 0;
		}
		$abilities = implode(',', VereineIdentityRules::capabilities($capabilities));
		$existing = $this->identity($client, $subject);
		if ($existing !== null) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_identity SET fk_adherent = ".((int) $memberId > 0 ? (int) $memberId : "NULL");
			$sql .= ", fk_soc = ".($socid > 0 ? $socid : "NULL").", fk_application = ".((int) $applicationId > 0 ? (int) $applicationId : "NULL");
			$sql .= ", proof = '".$this->db->escape((string) $proof)."', proof_note = '".$this->db->escape((string) $note)."'";
			$sql .= ", capabilities = '".$this->db->escape($abilities)."', linked_at = '".$this->db->idate(dol_now())."'";
			$sql .= ", revoked_at = NULL, fk_user_modif = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $existing['id'])." AND entity = ".$entity;
		} else {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_identity (entity, client, subject, fk_adherent, fk_soc,";
			$sql .= " fk_application, proof, proof_note, capabilities, linked_at, datec, fk_user_modif) VALUES (".$entity.",";
			$sql .= " '".$this->db->escape((string) $client)."', '".$this->db->escape((string) $subject)."',";
			$sql .= " ".((int) $memberId > 0 ? (int) $memberId : "NULL").", ".($socid > 0 ? $socid : "NULL").",";
			$sql .= " ".((int) $applicationId > 0 ? (int) $applicationId : "NULL").", '".$this->db->escape((string) $proof)."',";
			$sql .= " '".$this->db->escape((string) $note)."', '".$this->db->escape($abilities)."',";
			$sql .= " '".$this->db->idate(dol_now())."', '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Take a binding back. What it allowed stops at once.
	 *
	 * @param int  $id   The binding
	 * @param User $user Who takes it back
	 * @return int 1 when taken back, 0 when there is none, -1 on error
	 */
	public function revoke($id, $user)
	{
		global $conf;

		$identity = $this->fetch($id);
		if ($identity === null) {
			$this->errors[] = 'VereineIdentityErrorUnknown';
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_identity SET revoked_at = '".$this->db->idate(dol_now())."',";
		$sql .= " fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::IDENTITY_REVOKED, (int) $identity['member_id'], 0,
			$identity['client'].': '.$identity['subject']);
		return 1;
	}

	/**
	 * Members that could be meant by an address or a member number.
	 *
	 * These are candidates for a human being to look at, never a proof. One address belongs to a whole
	 * family often enough, and a member number can be guessed: neither binds anybody to anything.
	 *
	 * @param string $email Address, empty to ignore
	 * @param string $ref   Member number, empty to ignore
	 * @return array<int,array{member_id:int,name:string,ref:string}> At most ten
	 */
	public function candidates($email, $ref)
	{
		global $conf;

		$where = array();
		if (trim((string) $email) !== '') {
			$where[] = "email = '".$this->db->escape(trim((string) $email))."'";
		}
		if (trim((string) $ref) !== '') {
			$where[] = "ref = '".$this->db->escape(trim((string) $ref))."'";
		}
		if (!$where) {
			return array();
		}
		$sql = "SELECT rowid, ref, firstname, lastname FROM ".MAIN_DB_PREFIX."adherent";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND (".implode(' OR ', $where).") ORDER BY rowid LIMIT 10";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array('member_id' => (int) $obj->rowid, 'name' => trim((string) $obj->firstname.' '.(string) $obj->lastname),
				'ref' => (string) $obj->ref);
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * The invitations that are still open, for the setup.
	 *
	 * @return array<int,array<string,mixed>> Never the code itself
	 */
	public function invitations()
	{
		global $conf;

		$sql = "SELECT rowid, client, fk_adherent, fk_application, capabilities, expires_at, used_at, used_subject";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_identity_invite WHERE entity = ".((int) $conf->entity);
		$sql .= " ORDER BY rowid DESC LIMIT 50";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array('id' => (int) $obj->rowid, 'client' => (string) $obj->client, 'member_id' => (int) $obj->fk_adherent,
				'application_id' => (int) $obj->fk_application, 'capabilities' => (string) $obj->capabilities,
				'expires_at' => substr((string) $obj->expires_at, 0, 19),
				'used_at' => $obj->used_at ? substr((string) $obj->used_at, 0, 19) : '',
				'used_subject' => (string) $obj->used_subject);
		}
		$this->db->free($resql);
		return $rows;
	}
}
