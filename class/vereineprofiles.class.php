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
 * \file    class/vereineprofiles.class.php
 * \ingroup vereine
 * \brief   A member's own data through an application (#164): read it, ask for a change, give notice of the exit.
 *
 * Nothing here writes a member object freely. A change is a request with an id; it is applied at once
 * only for fields the association chose, otherwise the board looks at it in Dolibarr. The notice of the
 * exit goes through the exits of the module and ends on the day its rule says; the member gets that day
 * back. What the board notes for itself never reaches the member.
 */

require_once __DIR__.'/vereineprofilerules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * A member's own data through an application.
 */
class VereineProfiles
{
	/** Fields that change at once, comma separated. */
	const DIRECT = 'VEREINE_PROFILE_DIRECT';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * @var string[] Messages for the caller
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
	 * Fields that change at once.
	 *
	 * @return string[]
	 */
	public static function directFields()
	{
		return array_values(array_intersect(VereineProfileRules::DIRECT_ALLOWED, explode(',', getDolGlobalString(self::DIRECT))));
	}

	/**
	 * Keep which fields change at once.
	 *
	 * @param string[] $fields Fields
	 * @param User     $user   Who
	 * @return int 1 when saved, -1 on error
	 */
	public function saveDirect(array $fields, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$fields = array_values(array_intersect(VereineProfileRules::DIRECT_ALLOWED, $fields));
		if (dolibarr_set_const($this->db, self::DIRECT, implode(',', $fields), 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::PROFILE, 0, 0, 'direct: '.implode(',', $fields));
		return 1;
	}

	/**
	 * The fields a member may ask to change, as they are now.
	 *
	 * @param Adherent $member Member
	 * @return array<string,string>
	 */
	public static function current($member)
	{
		return array('address' => (string) $member->address, 'zip' => (string) $member->zip, 'town' => (string) $member->town,
			'country_code' => (string) $member->country_code, 'phone' => (string) $member->phone, 'phone_mobile' => (string) $member->phone_mobile,
			'email' => (string) $member->email);
	}

	/**
	 * The member's own data as the member may read them, with the version a change must name.
	 *
	 * @param Adherent $member Member
	 * @return array<string,mixed>
	 */
	public function profile($member)
	{
		require_once __DIR__.'/vereineexits.class.php';

		$current = self::current($member);
		$exit = null;
		foreach ((new VereineExits($this->db))->fetchAll(array((int) $member->id)) as $candidate) {
			if (in_array($candidate['status'], array(VereineExits::STATUS_PLANNED, 'done'), true)) {
				$exit = array('status' => (string) $candidate['status'], 'reason' => (string) $candidate['reason'], 'notice_day' => (string) $candidate['notice_day'],
					'last_day' => (string) $candidate['last_day']);
				break;
			}
		}
		return array('member_id' => (int) $member->id, 'ref' => (string) $member->ref, 'firstname' => (string) $member->firstname, 'lastname' => (string) $member->lastname,
			'birth' => !empty($member->birth) ? dol_print_date($member->birth, '%Y-%m-%d', 'tzserver') : '') + $current + array(
			'member_type' => (string) $member->type, 'status' => (int) $member->statut === 1 ? 'active' : ((int) $member->statut === -1 ? 'draft' : 'former'),
			'version' => VereineProfileRules::version($current), 'direct' => self::directFields(), 'exit' => $exit);
	}

	/**
	 * Ask for a change of one's own data. Applied at once when every field may; otherwise the board decides.
	 *
	 * @param Adherent $member Member
	 * @param mixed    $sent   external_id, version, changes
	 * @param string   $client The application
	 * @param User     $user   Who acts (the client)
	 * @return array<string,mixed>|null The request; null when refused (see errors: conflict for another state or another request under the id)
	 */
	public function submitChange($member, $sent, $client, $user)
	{
		$this->errors = array();
		$current = self::current($member);
		$checked = VereineProfileRules::check($sent, $current);
		$known = $checked['external_id'] !== '' ? $this->byExternal($client, $checked['external_id']) : null;
		$asked = array('version' => $checked['version'], 'asked' => $checked['asked']);
		$payload = array('version' => $checked['version'], 'changes' => $checked['changes']);
		if ($known !== null) {
			// The same request again is the same request, even after it was applied; another one under the same id is refused.
			if ($known['member_id'] !== (int) $member->id || $known['fingerprint'] !== VereineProfileRules::fingerprint($asked) || $known['kind'] !== 'change') {
				$this->errors[] = 'conflict';
				return null;
			}
			return $this->view($known['id']);
		}
		if ($checked['errors']) {
			$this->errors = $checked['errors'];
			return null;
		}
		if ($checked['version'] !== VereineProfileRules::version($current)) {
			$this->errors[] = 'conflict';
			return null;
		}
		$direct = VereineProfileRules::direct($checked['changes'], self::directFields());
		$id = $this->insert((int) $member->id, $client, $checked['external_id'], 'change', $payload, $direct ? 'applied' : 'received', 0, VereineProfileRules::fingerprint($asked));
		if ($id < 0) {
			return null;
		}
		if ($direct && $this->apply($member, $checked['changes'], $user) < 0) {
			return null;
		}
		VereineLog::add($this->db, $user, VereineLog::PROFILE, (int) $member->id, 0, 'change '.implode(',', array_keys($checked['changes'])).($direct ? ' applied' : ' received'));
		return $this->view($id);
	}

	/**
	 * Give notice of the exit. It ends on the day the rule of the association says, or later when the member wants.
	 *
	 * @param Adherent $member Member
	 * @param mixed    $sent   external_id, wished_last_day
	 * @param string   $client The application
	 * @param string   $today  Today
	 * @param User     $user   Who acts (the client)
	 * @return array<string,mixed>|null The notice with its last day; null when refused (see errors)
	 */
	public function submitExit($member, $sent, $client, $today, $user)
	{
		require_once __DIR__.'/vereineexits.class.php';

		$this->errors = array();
		$sent = is_array($sent) ? $sent : array();
		$external = isset($sent['external_id']) && is_scalar($sent['external_id']) ? trim((string) $sent['external_id']) : '';
		$wished = isset($sent['wished_last_day']) && is_scalar($sent['wished_last_day']) ? trim((string) $sent['wished_last_day']) : '';
		if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $external)) {
			$this->errors[] = 'external_id must be 1 to 64 letters, digits or . _ : -';
		}
		if ($wished !== '' && !VereineExitRules::isDate($wished)) {
			$this->errors[] = 'wished_last_day must be YYYY-MM-DD';
		}
		if ($this->errors) {
			return null;
		}
		$payload = array('wished_last_day' => $wished);
		$known = $this->byExternal($client, $external);
		if ($known !== null) {
			if ($known['member_id'] !== (int) $member->id || $known['fingerprint'] !== VereineProfileRules::fingerprint($payload) || $known['kind'] !== 'exit') {
				$this->errors[] = 'conflict';
				return null;
			}
			return $this->exitView($known['id']);
		}
		$exits = new VereineExits($this->db);
		$exitId = $exits->plan($member, VereineExitRules::REASON_RESIGNATION, $today, '', $client, $user);
		if ($exitId <= 0) {
			$this->errors = $exitId < 0 ? array() : array_map(function ($error) {
				return $error === 'VereineExitErrorAlreadyPlanned' ? 'an exit is already planned' : ($error === 'VereineExitErrorNotActive' ? 'only an active member can give notice' : $error);
			}, $exits->errors);
			$this->error = $exits->error;
			return null;
		}
		// A later day than the rule's is the member's to choose; an earlier one is not.
		$planned = $this->value("SELECT last_day as v FROM ".MAIN_DB_PREFIX."vereine_member_exit WHERE rowid = ".((int) $exitId));
		if ($wished !== '' && $wished > $planned && $this->value("SELECT status as v FROM ".MAIN_DB_PREFIX."vereine_member_exit WHERE rowid = ".((int) $exitId)) === VereineExits::STATUS_PLANNED) {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_member_exit SET last_day = '".$this->db->escape($wished)."' WHERE rowid = ".((int) $exitId));
		}
		$id = $this->insert((int) $member->id, $client, $external, 'exit', $payload, 'applied', $exitId, VereineProfileRules::fingerprint($payload));
		return $id > 0 ? $this->exitView($id) : null;
	}

	/**
	 * The member's own requests, newest first; what the board noted for itself stays out.
	 *
	 * @param int $memberId Member
	 * @return array<int,array<string,mixed>>
	 */
	public function requests($memberId)
	{
		global $conf;

		$list = array();
		$resql = $this->db->query("SELECT rowid, kind FROM ".MAIN_DB_PREFIX."vereine_profile_request WHERE entity = ".((int) $conf->entity)." AND fk_adherent = ".((int) $memberId)
			." ORDER BY rowid DESC");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$list[] = (string) $obj->kind === 'exit' ? $this->exitView((int) $obj->rowid) : $this->view((int) $obj->rowid);
		}
		return $list;
	}

	/**
	 * Requests of changes that wait for the board, with the member and the data now.
	 *
	 * @param int $memberId Only this member's, 0 for all
	 * @return array<int,array<string,mixed>>
	 */
	public function pending($memberId = 0)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';

		$list = array();
		$sql = "SELECT rowid, fk_adherent FROM ".MAIN_DB_PREFIX."vereine_profile_request WHERE entity = ".((int) $conf->entity)." AND kind = 'change' AND status = 'received'";
		$resql = $this->db->query($sql.((int) $memberId > 0 ? " AND fk_adherent = ".((int) $memberId) : "")." ORDER BY rowid");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$member = new Adherent($this->db);
			if ($member->fetch((int) $obj->fk_adherent) <= 0) {
				continue;
			}
			$request = $this->view((int) $obj->rowid) + array('id' => (int) $obj->rowid, 'member_id' => (int) $member->id, 'name' => $member->getFullName($GLOBALS['langs']),
				'now' => self::current($member), 'outdated' => $this->baseVersion((int) $obj->rowid) !== VereineProfileRules::version(self::current($member)));
			$list[] = $request;
		}
		return $list;
	}

	/**
	 * The board decides on a request: applied, when the data are still those it was made on, or rejected with a word for the member.
	 *
	 * @param int    $requestId Request
	 * @param bool   $apply     Apply it
	 * @param string $reason    What the member reads
	 * @param string $note      What only the board reads
	 * @param User   $user      Who
	 * @return int 1 when decided, 0 when refused (see errors), -1 on error
	 */
	public function decide($requestId, $apply, $reason, $note, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';

		$this->errors = array();
		$request = null;
		foreach ($this->pending() as $candidate) {
			if ($candidate['id'] === (int) $requestId) {
				$request = $candidate;
			}
		}
		if ($request === null) {
			return 0;
		}
		if ($apply && $request['outdated']) {
			$this->errors[] = 'VereineProfileErrorOutdated';
			return 0;
		}
		$member = new Adherent($this->db);
		$member->fetch($request['member_id']);
		if ($apply && $this->apply($member, $request['changes'], $user) < 0) {
			return -1;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_profile_request SET status = '".($apply ? 'applied' : 'rejected')."', reason = '".$this->db->escape(mb_substr(trim((string) $reason), 0, 255, 'UTF-8'))."',";
		$sql .= " note_internal = '".$this->db->escape(mb_substr(trim((string) $note), 0, 255, 'UTF-8'))."', decided_at = '".$this->db->idate(dol_now())."', fk_user_decided = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $requestId)." AND entity = ".((int) $conf->entity)." AND status = 'received'";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::PROFILE, $request['member_id'], 0, 'change '.($apply ? 'applied' : 'rejected'));
		return 1;
	}

	/**
	 * Write changed contact data into the member and tell whoever follows the member.
	 *
	 * @param Adherent             $member  Member
	 * @param array<string,string> $changes Field => value
	 * @param User                 $user    Who
	 * @return int 1 when written, -1 on error
	 */
	private function apply($member, array $changes, $user)
	{
		$columns = array('address' => 'address', 'zip' => 'zip', 'town' => 'town', 'phone' => 'phone', 'phone_mobile' => 'phone_mobile', 'email' => 'email');
		$set = array();
		foreach ($changes as $field => $value) {
			if ($field === 'country_code') {
				$country = (int) dol_getIdFromCode($this->db, $value, 'c_country', 'code', 'rowid');
				$set[] = "country = ".($country > 0 ? $country : "NULL");
			} elseif (isset($columns[$field])) {
				$set[] = $columns[$field]." = ".($value !== '' ? "'".$this->db->escape($value)."'" : "NULL");
			}
		}
		if ($set && !$this->db->query("UPDATE ".MAIN_DB_PREFIX."adherent SET ".implode(', ', $set)." WHERE rowid = ".((int) $member->id))) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$member->fetch((int) $member->id);
		$member->call_trigger('MEMBER_MODIFY', $user);
		return 1;
	}

	/**
	 * Keep a request.
	 *
	 * @param int                 $memberId Member
	 * @param string              $client   Application
	 * @param string              $external Its id
	 * @param string              $kind     change or exit
	 * @param array<string,mixed> $payload  What was asked
	 * @param string              $status   received or applied
	 * @param int                 $exitId      The exit a notice made
	 * @param string              $fingerprint What was sent, to recognise a repetition
	 * @return int Id, -1 on error
	 */
	private function insert($memberId, $client, $external, $kind, array $payload, $status, $exitId, $fingerprint)
	{
		global $conf;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_profile_request (entity, fk_adherent, client, external_id, kind, payload, fingerprint, status, fk_exit, received_at) VALUES (";
		$sql .= ((int) $conf->entity).", ".((int) $memberId).", '".$this->db->escape($client)."', '".$this->db->escape($external)."', '".$this->db->escape($kind)."',";
		$sql .= " '".$this->db->escape((string) json_encode($payload))."', '".$this->db->escape($fingerprint)."', '".$this->db->escape($status)."', ".((int) $exitId).",";
		$sql .= " '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_profile_request');
	}

	/**
	 * A request of the same application under the same id.
	 *
	 * @param string $client   Application
	 * @param string $external Its id
	 * @return array{id:int,member_id:int,kind:string,fingerprint:string}|null
	 */
	private function byExternal($client, $external)
	{
		global $conf;

		$resql = $this->db->query("SELECT rowid, fk_adherent, kind, fingerprint FROM ".MAIN_DB_PREFIX."vereine_profile_request WHERE entity = ".((int) $conf->entity)
			." AND client = '".$this->db->escape($client)."' AND external_id = '".$this->db->escape($external)."'");
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? array('id' => (int) $obj->rowid, 'member_id' => (int) $obj->fk_adherent, 'kind' => (string) $obj->kind, 'fingerprint' => (string) $obj->fingerprint) : null;
	}

	/**
	 * A request of a change as the member may see it.
	 *
	 * @param int $id Request
	 * @return array<string,mixed>
	 */
	private function view($id)
	{
		$resql = $this->db->query("SELECT external_id, payload, status, reason, received_at, decided_at FROM ".MAIN_DB_PREFIX."vereine_profile_request WHERE rowid = ".((int) $id));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		$payload = $obj ? json_decode((string) $obj->payload, true) : array();
		return array('external_id' => $obj ? (string) $obj->external_id : '', 'kind' => 'change', 'changes' => isset($payload['changes']) ? $payload['changes'] : array(),
			'status' => $obj ? (string) $obj->status : '', 'reason' => $obj ? (string) $obj->reason : '',
			'received_at' => $obj ? dol_print_date($this->db->jdate($obj->received_at), 'dayhourrfc') : '',
			'decided_at' => $obj && $obj->decided_at ? dol_print_date($this->db->jdate($obj->decided_at), 'dayhourrfc') : '');
	}

	/**
	 * A notice of the exit as the member may see it: when it came, the day it ends, what was wished.
	 *
	 * @param int $id Request
	 * @return array<string,mixed>
	 */
	private function exitView($id)
	{
		$sql = "SELECT r.external_id, r.payload, r.received_at, e.notice_day, e.last_day, e.status FROM ".MAIN_DB_PREFIX."vereine_profile_request as r";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."vereine_member_exit as e ON e.rowid = r.fk_exit WHERE r.rowid = ".((int) $id);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		$payload = $obj ? json_decode((string) $obj->payload, true) : array();
		$wished = isset($payload['wished_last_day']) ? (string) $payload['wished_last_day'] : '';
		$lastDay = $obj ? substr((string) $obj->last_day, 0, 10) : '';
		return array('external_id' => $obj ? (string) $obj->external_id : '', 'kind' => 'exit', 'received_at' => $obj ? dol_print_date($this->db->jdate($obj->received_at), 'dayhourrfc') : '',
			'notice_day' => $obj ? substr((string) $obj->notice_day, 0, 10) : '', 'last_day' => $lastDay, 'status' => $obj ? (string) $obj->status : '',
			'wished_last_day' => $wished, 'wished_too_early' => $wished !== '' && $wished < $lastDay);
	}

	/**
	 * The version of the data a request was made on.
	 *
	 * @param int $id Request
	 * @return string
	 */
	private function baseVersion($id)
	{
		$payload = json_decode($this->value("SELECT payload as v FROM ".MAIN_DB_PREFIX."vereine_profile_request WHERE rowid = ".((int) $id)), true);
		return is_array($payload) && isset($payload['version']) ? (string) $payload['version'] : '';
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
