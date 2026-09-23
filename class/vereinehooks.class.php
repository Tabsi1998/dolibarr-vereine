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
 * \file    class/vereinehooks.class.php
 * \ingroup vereine
 * \brief   Signed webhooks (#155): deliver the change feed to a target, later and signed.
 *
 * Nothing is sent while a member is being saved. A scheduled job picks up what the change feed already
 * holds, so a change that was rolled back never turns into a delivery and a slow receiver never delays
 * anybody's work. A delivery is tried again with growing pauses; the name of the event stays the same,
 * so a receiver that sees it twice knows it is the same thing.
 *
 * Before every attempt the module asks again whether the user behind the target may still follow the
 * feed. When that right is gone, or the target is switched off, nothing more goes out.
 */

require_once __DIR__.'/vereinehookrules.class.php';
require_once __DIR__.'/vereinechanges.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * The targets of the webhooks and their deliveries.
 */
class VereineHooks
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
	 * @var string What a newly stored target has to be told once, because it is never shown again
	 */
	public $newSecret = '';

	/**
	 * @var string What the scheduled job reports
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
	 * The targets of this entity.
	 *
	 * @param bool $activeOnly Only targets that are switched on
	 * @return array<int,array<string,mixed>> The secret itself is never part of it
	 */
	public function targets($activeOnly = false)
	{
		global $conf;

		$sql = "SELECT rowid, label, url, fk_user, key_id, next_key_id, rotate_until, object_types, allow_internal,";
		$sql .= " active, cursor_value, last_ok FROM ".MAIN_DB_PREFIX."vereine_hook_target WHERE entity = ".((int) $conf->entity);
		$sql .= ($activeOnly ? " AND active = 1" : "")." ORDER BY label, rowid";
		// The table exists only after the module was enabled with 0.8.0.
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$targets = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$targets[] = array('id' => (int) $obj->rowid, 'label' => (string) $obj->label, 'url' => (string) $obj->url,
				'user_id' => (int) $obj->fk_user, 'key_id' => (string) $obj->key_id, 'next_key_id' => (string) $obj->next_key_id,
				'rotate_until' => $obj->rotate_until ? substr((string) $obj->rotate_until, 0, 19) : '',
				'object_types' => (string) $obj->object_types, 'allow_internal' => (bool) $obj->allow_internal,
				'active' => (bool) $obj->active, 'cursor' => (string) $obj->cursor_value,
				'last_ok' => $obj->last_ok ? substr((string) $obj->last_ok, 0, 19) : '');
		}
		$this->db->free($resql);
		return $targets;
	}

	/**
	 * One target, null when there is none.
	 *
	 * @param int $id Target
	 * @return array<string,mixed>|null
	 */
	public function fetch($id)
	{
		foreach ($this->targets() as $target) {
			if ($target['id'] === (int) $id) {
				return $target;
			}
		}
		return null;
	}

	/**
	 * Store a target, a new one when there is no id. A new target gets its secret here, once.
	 *
	 * @param int                 $id   Target, 0 for a new one
	 * @param array<string,mixed> $data Keys label, url, user_id, object_types, allow_internal, active
	 * @param User                $user Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function save($id, array $data, $user)
	{
		global $conf;

		$this->errors = array();
		$label = trim((string) (isset($data['label']) ? $data['label'] : ''));
		if ($label === '' || mb_strlen($label, 'UTF-8') > 128) {
			$this->errors[] = 'VereineHookErrorLabel';
		}
		$allowInternal = !empty($data['allow_internal']);
		$wrong = VereineHookRules::checkUrl(isset($data['url']) ? $data['url'] : '', $allowInternal);
		if ($wrong !== '') {
			$this->errors[] = $wrong;
		}
		if ((int) (isset($data['user_id']) ? $data['user_id'] : 0) < 1) {
			$this->errors[] = 'VereineHookErrorUser';
		}
		if ($this->errors) {
			return 0;
		}
		$entity = (int) $conf->entity;
		$types = array();
		foreach (explode(',', (string) (isset($data['object_types']) ? $data['object_types'] : '')) as $type) {
			$type = trim($type);
			if ($type !== '' && in_array($type, VereineChangeRules::TYPES, true)) {
				$types[] = $type;
			}
		}
		$values = array(
			'label' => "'".$this->db->escape($label)."'",
			'url' => "'".$this->db->escape(trim((string) $data['url']))."'",
			'fk_user' => (string) (int) $data['user_id'],
			'object_types' => "'".$this->db->escape(implode(',', array_unique($types)))."'",
			'allow_internal' => $allowInternal ? '1' : '0',
			'active' => !empty($data['active']) ? '1' : '0',
			'fk_user_modif' => (string) (int) $user->id,
		);
		if ((int) $id > 0) {
			$set = array();
			foreach ($values as $column => $value) {
				$set[] = $column.' = '.$value;
			}
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_hook_target SET ".implode(', ', $set);
			$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".$entity;
		} else {
			$secret = VereineHookRules::newSecret();
			$this->newSecret = $secret['secret'];
			$values['entity'] = (string) $entity;
			$values['key_id'] = "'".$this->db->escape($secret['key_id'])."'";
			$values['secret'] = "'".$this->db->escape($secret['secret'])."'";
			// A new target starts at the end of the feed: what happened before it existed is not its business.
			$values['cursor_value'] = "'".$this->db->escape((new VereineChanges($this->db))->head($entity))."'";
			$values['datec'] = "'".$this->db->idate(dol_now())."'";
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_hook_target (".implode(', ', array_keys($values)).")";
			$sql .= " VALUES (".implode(', ', array_values($values)).")";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::HOOK_TARGET, 0, 0, $label);
		return 1;
	}

	/**
	 * Give a target a new secret. The old one stays valid for a day, so a receiver has time to change.
	 *
	 * @param int  $id   Target
	 * @param User $user Who rotates
	 * @return string The new secret, empty on error; it is never shown again
	 */
	public function rotate($id, $user)
	{
		global $conf;

		$target = $this->fetch($id);
		if ($target === null) {
			$this->errors[] = 'VereineHookErrorUnknown';
			return '';
		}
		$secret = VereineHookRules::newSecret();
		$until = dol_now() + VereineHookRules::ROTATION_SECONDS;
		// The secret that was valid until now becomes the second one, and the new one signs from here on.
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_hook_target SET next_key_id = key_id, next_secret = secret,";
		$sql .= " key_id = '".$this->db->escape($secret['key_id'])."', secret = '".$this->db->escape($secret['secret'])."',";
		$sql .= " rotate_until = '".$this->db->idate($until)."', fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return '';
		}
		VereineLog::add($this->db, $user, VereineLog::HOOK_ROTATED, 0, 0, $target['label'].': '.$secret['key_id']);
		return $secret['secret'];
	}

	/**
	 * Remove a target with everything that was on its way to it.
	 *
	 * @param int  $id   Target
	 * @param User $user Who removes
	 * @return int 1 when removed, -1 on error
	 */
	public function remove($id, $user)
	{
		global $conf;

		$entity = (int) $conf->entity;
		$this->db->begin();
		foreach (array('vereine_hook_delivery WHERE fk_target = '.((int) $id), 'vereine_hook_target WHERE rowid = '.((int) $id)) as $what) {
			if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX.$what." AND entity = ".$entity)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::HOOK_TARGET, 0, 0, 'target '.((int) $id).' removed');
		return 1;
	}

	/**
	 * Turn what the change feed holds into jobs, one per target and event.
	 *
	 * This reads the feed with the cursor of the target, so a change that was rolled back was never in
	 * the feed and never becomes a job. Running it again writes nothing twice.
	 *
	 * @return int Number of jobs written
	 */
	public function queue()
	{
		global $conf;

		$entity = (int) $conf->entity;
		$changes = new VereineChanges($this->db);
		$written = 0;
		foreach ($this->targets(true) as $target) {
			$page = $changes->feed($target['cursor'], VereineChangeRules::PAGE_DEFAULT, $target['object_types']);
			if (!empty($page['resync_required'])) {
				// The target fell behind the retention; it has to reconcile itself through the API.
				$this->note($target['id'], 'VereineHookResync');
				$this->moveCursor((int) $target['id'], $changes->head($entity));
				continue;
			}
			foreach ($page['events'] as $event) {
				$written += $this->addDelivery((int) $target['id'], $event, $entity) > 0 ? 1 : 0;
			}
			if ($page['next_cursor'] !== $target['cursor']) {
				$this->moveCursor((int) $target['id'], (string) $page['next_cursor']);
			}
		}
		return $written;
	}

	/**
	 * Write one job, or leave the one that is already there.
	 *
	 * @param int                 $targetId Target
	 * @param array<string,mixed> $event    Event of the feed
	 * @param int                 $entity   Entity
	 * @return int 1 when written, 0 when it was already there, -1 on error
	 */
	private function addDelivery($targetId, array $event, $entity)
	{
		$occurred = str_replace(array('T', 'Z'), array(' ', ''), (string) $event['occurred_at']);
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_hook_delivery (entity, fk_target, event_id, object_type, object_id,";
		$sql .= " revision, change_kind, occurred_at, status, attempts, next_try, datec) VALUES (".((int) $entity).",";
		$sql .= " ".((int) $targetId).", '".$this->db->escape((string) $event['event_id'])."',";
		$sql .= " '".$this->db->escape((string) $event['object_type'])."', ".((int) $event['object_id']).",";
		$sql .= " ".((int) $event['revision']).", '".$this->db->escape((string) $event['change'])."',";
		$sql .= " '".$this->db->escape($occurred)."', '".VereineHookRules::STATUS_PENDING."', 0,";
		$sql .= " '".$this->db->idate(dol_now())."', '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			if ($this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				return 0;
			}
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Move the cursor of a target.
	 *
	 * @param int    $targetId Target
	 * @param string $cursor   Where it stands now
	 * @return void
	 */
	private function moveCursor($targetId, $cursor)
	{
		global $conf;

		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_hook_target SET cursor_value = '".$this->db->escape((string) $cursor)."'";
		$sql .= " WHERE rowid = ".((int) $targetId)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
		}
	}

	/**
	 * Note something about a target in the module's log.
	 *
	 * @param int    $targetId Target
	 * @param string $what     Short text
	 * @return void
	 */
	private function note($targetId, $what)
	{
		VereineLog::add($this->db, null, VereineLog::HOOK_TARGET, 0, 0, 'target '.((int) $targetId).': '.$what);
	}

	/**
	 * Send what is due. This is what the scheduled job calls; nothing here runs inside a member's save.
	 *
	 * @param int $limit At most so many attempts in one run
	 * @return int Number sent, <0 on error
	 */
	public function deliver($limit = 50)
	{
		global $conf;

		$entity = (int) $conf->entity;
		$now = dol_now();
		$sql = "SELECT rowid, fk_target, event_id, object_type, object_id, revision, change_kind, occurred_at, attempts";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_hook_delivery WHERE entity = ".$entity;
		$sql .= " AND status = '".VereineHookRules::STATUS_PENDING."'";
		$sql .= " AND (next_try IS NULL OR next_try <= '".$this->db->idate($now)."') ORDER BY rowid LIMIT ".((int) $limit);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$jobs = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$jobs[] = $obj;
		}
		$this->db->free($resql);
		$sent = 0;
		foreach ($jobs as $job) {
			$sent += $this->attempt($job) > 0 ? 1 : 0;
		}
		return $sent;
	}

	/**
	 * One attempt at one job.
	 *
	 * @param object $job Row of the job
	 * @return int 1 when the receiver took it, 0 otherwise
	 */
	private function attempt($job)
	{
		$secrets = $this->secretsOf((int) $job->fk_target);
		$target = $this->fetch((int) $job->fk_target);
		if ($target === null || !$target['active'] || !$this->mayDeliver($target)) {
			// Switched off, gone, or the right was taken away: this job stops here.
			$this->finish((int) $job->rowid, VereineHookRules::STATUS_STOPPED, 0, 'VereineHookStopped', (int) $job->attempts);
			return 0;
		}
		$body = VereineHookRules::body(array(
			'event_id' => (string) $job->event_id, 'object_type' => (string) $job->object_type,
			'object_id' => (int) $job->object_id, 'revision' => (int) $job->revision,
			'change' => (string) $job->change_kind,
			'occurred_at' => str_replace(' ', 'T', substr((string) $job->occurred_at, 0, 19)).'Z',
		));
		$stamp = (int) dol_now();
		$header = VereineHookRules::header($secrets['secret'], $secrets['key_id'], $body, $stamp, (string) $job->event_id);
		$answer = $this->post($target, $body, $header);
		$attempts = (int) $job->attempts + 1;
		if (VereineHookRules::accepted($answer['code'])) {
			$this->finish((int) $job->rowid, VereineHookRules::STATUS_SENT, (int) $answer['code'], '', $attempts);
			$this->markOk((int) $target['id']);
			return 1;
		}
		$error = VereineHookRules::maskError($answer['error'], array($secrets['secret'], $secrets['next_secret']));
		if ($attempts >= VereineHookRules::MAX_ATTEMPTS) {
			$this->finish((int) $job->rowid, VereineHookRules::STATUS_FAILED, (int) $answer['code'], $error, $attempts);
			return 0;
		}
		$this->later((int) $job->rowid, (int) $answer['code'], $error, $attempts);
		return 0;
	}

	/**
	 * Post a body to a target, with the address checked again right before.
	 *
	 * @param array<string,mixed> $target The target
	 * @param string              $body   The bytes
	 * @param string              $header The signature
	 * @return array{code:int,error:string}
	 */
	private function post(array $target, $body, $header)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

		// The name is looked up again: one that answered publicly yesterday may point inside today.
		$wrong = VereineHookRules::checkUrl($target['url'], (bool) $target['allow_internal']);
		if ($wrong !== '') {
			return array('code' => 0, 'error' => $wrong);
		}
		$headers = array('Content-Type: application/json', 'Accept: application/json',
			VereineHookRules::HEADER.': '.$header);
		// Only https, no redirect is followed, and the certificate is checked: a hint may not wander.
		$answer = getURLContent($target['url'], 'POSTALREADYFORMATED', $body, 0, $headers, array('https'), 2);
		$code = isset($answer['http_code']) ? (int) $answer['http_code'] : 0;
		$error = isset($answer['curl_error_msg']) ? (string) $answer['curl_error_msg'] : '';
		return array('code' => $code, 'error' => $error !== '' ? $error : 'HTTP '.$code);
	}

	/**
	 * Whether the user behind a target may still follow the feed.
	 *
	 * @param array<string,mixed> $target The target
	 * @return bool
	 */
	public function mayDeliver(array $target)
	{
		if ((int) $target['user_id'] < 1) {
			return false;
		}
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

		$holder = new User($this->db);
		if ($holder->fetch((int) $target['user_id']) <= 0 || (int) $holder->statut !== 1) {
			return false;
		}
		$holder->getrights();
		return (bool) $holder->hasRight('vereine', 'sync', 'read');
	}

	/**
	 * The secrets of a target: the one that signs, and the one that is still accepted during a rotation.
	 *
	 * @param int $targetId Target
	 * @return array{key_id:string,secret:string,next_key_id:string,next_secret:string}
	 */
	public function secretsOf($targetId)
	{
		global $conf;

		$empty = array('key_id' => '', 'secret' => '', 'next_key_id' => '', 'next_secret' => '');
		$sql = "SELECT key_id, secret, next_key_id, next_secret, rotate_until FROM ".MAIN_DB_PREFIX."vereine_hook_target";
		$sql .= " WHERE rowid = ".((int) $targetId)." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return $empty;
		}
		$until = $obj->rotate_until ? $this->db->jdate($obj->rotate_until) : 0;
		$stillValid = $until > 0 && $until >= dol_now();
		return array('key_id' => (string) $obj->key_id, 'secret' => (string) $obj->secret,
			'next_key_id' => $stillValid ? (string) $obj->next_key_id : '',
			'next_secret' => $stillValid ? (string) $obj->next_secret : '');
	}

	/**
	 * Note a job as done, one way or the other.
	 *
	 * @param int    $id       Job
	 * @param string $status   New state
	 * @param int    $code     What the receiver answered
	 * @param string $error    What went wrong, already masked
	 * @param int    $attempts Attempts made
	 * @return void
	 */
	private function finish($id, $status, $code, $error, $attempts)
	{
		global $conf;

		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_hook_delivery SET status = '".$this->db->escape((string) $status)."',";
		$sql .= " attempts = ".((int) $attempts).", last_code = ".((int) $code).", last_error = '".$this->db->escape((string) $error)."',";
		$sql .= " next_try = NULL, sent_at = ".($status === VereineHookRules::STATUS_SENT ? "'".$this->db->idate(dol_now())."'" : "NULL");
		$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
		}
	}

	/**
	 * Put a job off for a while.
	 *
	 * @param int    $id       Job
	 * @param int    $code     What the receiver answered
	 * @param string $error    What went wrong, already masked
	 * @param int    $attempts Attempts made
	 * @return void
	 */
	private function later($id, $code, $error, $attempts)
	{
		global $conf;

		$next = dol_now() + VereineHookRules::backoff($attempts);
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_hook_delivery SET attempts = ".((int) $attempts).",";
		$sql .= " last_code = ".((int) $code).", last_error = '".$this->db->escape((string) $error)."',";
		$sql .= " next_try = '".$this->db->idate($next)."' WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
		}
	}

	/**
	 * Note when a target last took something.
	 *
	 * @param int $targetId Target
	 * @return void
	 */
	private function markOk($targetId)
	{
		global $conf;

		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_hook_target SET last_ok = '".$this->db->idate(dol_now())."'";
		$sql .= " WHERE rowid = ".((int) $targetId)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
		}
	}

	/**
	 * Start one job again by hand: the same job, the same name of the event, a fresh attempt.
	 *
	 * @param int  $id   Job
	 * @param User $user Who says so
	 * @return int 1 when it waits again, 0 when there is no such job, -1 on error
	 */
	public function retry($id, $user)
	{
		global $conf;

		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_hook_delivery SET status = '".VereineHookRules::STATUS_PENDING."',";
		$sql .= " attempts = 0, next_try = '".$this->db->idate(dol_now())."', last_error = ''";
		$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ((int) $this->db->affected_rows($resql) < 1) {
			return 0;
		}
		VereineLog::add($this->db, $user, VereineLog::HOOK_RETRY, 0, 0, 'delivery '.((int) $id));
		return 1;
	}

	/**
	 * What is on its way, for the view an officer looks at.
	 *
	 * @param int $targetId Target, 0 for all
	 * @param int $limit    At most so many
	 * @return array<int,array<string,mixed>> Newest first; the secret never appears
	 */
	public function deliveries($targetId = 0, $limit = 50)
	{
		global $conf;

		$sql = "SELECT rowid, fk_target, event_id, object_type, object_id, revision, change_kind, status, attempts,";
		$sql .= " next_try, last_code, last_error, sent_at, datec FROM ".MAIN_DB_PREFIX."vereine_hook_delivery";
		$sql .= " WHERE entity = ".((int) $conf->entity).((int) $targetId > 0 ? " AND fk_target = ".((int) $targetId) : "");
		$sql .= " ORDER BY rowid DESC LIMIT ".((int) $limit);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array('id' => (int) $obj->rowid, 'target_id' => (int) $obj->fk_target, 'event_id' => (string) $obj->event_id,
				'object_type' => (string) $obj->object_type, 'object_id' => (int) $obj->object_id, 'revision' => (int) $obj->revision,
				'change' => (string) $obj->change_kind, 'status' => (string) $obj->status, 'attempts' => (int) $obj->attempts,
				'next_try' => $obj->next_try ? substr((string) $obj->next_try, 0, 19) : '', 'last_code' => (int) $obj->last_code,
				'last_error' => (string) $obj->last_error, 'sent_at' => $obj->sent_at ? substr((string) $obj->sent_at, 0, 19) : '');
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * How much waits per state, for the view an officer looks at.
	 *
	 * @return array<string,int>
	 */
	public function backlog()
	{
		global $conf;

		$counts = array();
		foreach (VereineHookRules::STATUSES as $status) {
			$counts[$status] = 0;
		}
		$sql = "SELECT status, COUNT(*) as total FROM ".MAIN_DB_PREFIX."vereine_hook_delivery";
		$sql .= " WHERE entity = ".((int) $conf->entity)." GROUP BY status";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$counts[(string) $obj->status] = (int) $obj->total;
		}
		return $counts;
	}

	/**
	 * What the scheduled job of Dolibarr calls: first make jobs out of the feed, then send what is due.
	 *
	 * @return int 1 when it ran, <0 on error
	 */
	public function runDue()
	{
		$this->output = '';
		$queued = $this->queue();
		$sent = $this->deliver();
		if ($sent < 0) {
			$this->error = $this->error !== '' ? $this->error : 'delivery failed';
			return -1;
		}
		$this->output = 'Webhooks: '.$queued.' neue Aufträge, '.$sent.' zugestellt';
		return 1;
	}
}
