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
 * \file    class/vereinechanges.class.php
 * \ingroup vereine
 * \brief   The change feed (#154): a note per change, so an external application can catch up.
 *
 * An entry is written in the same transaction as the change itself, so a change that is rolled back
 * never reaches a reader. The entry says only that something changed; the current data is read through
 * the ordinary API, which decides on its own what a client may see. A reader follows the feed with an
 * opaque cursor; when its cursor is older than what is kept, it is told to reconcile instead of being
 * handed a silent gap.
 */

require_once __DIR__.'/vereinechangerules.class.php';

/**
 * The change feed of the association.
 */
class VereineChanges
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
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Note that something changed.
	 *
	 * The same change written twice keeps one entry: the name of a change is worked out from what it is
	 * about, so a repetition is recognised and not delivered again.
	 *
	 * @param DoliDB      $db       Database handler
	 * @param string      $type     One of VereineChangeRules::TYPES
	 * @param int         $objectId The object
	 * @param string      $kind     One of VereineChangeRules::KINDS
	 * @param User|null   $user     Who caused it, null for the system
	 * @param string|null $moment   When it happened, null for now
	 * @return int 1 when noted, 0 when it was already there, -1 on error
	 */
	public static function record($db, $type, $objectId, $kind, $user = null, $moment = null)
	{
		global $conf;

		if (!VereineChangeRules::known($type, $kind) || (int) $objectId < 1) {
			return -1;
		}
		$entity = (int) $conf->entity;
		$now = dol_now();
		$occurred = $moment !== null ? (string) $moment : dol_print_date($now, '%Y-%m-%d %H:%M:%S', 'gmt');
		$eventId = VereineChangeRules::eventId($entity, $type, (int) $objectId, $kind, $occurred);
		$revision = self::nextRevision($db, $entity, (string) $type, (int) $objectId);
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_change (entity, event_id, object_type, object_id, revision, change_kind,";
		$sql .= " occurred_at, fk_user, datec) VALUES (".$entity.", '".$db->escape($eventId)."', '".$db->escape((string) $type)."',";
		$sql .= " ".((int) $objectId).", ".$revision.", '".$db->escape((string) $kind)."', '".$db->escape($occurred)."',";
		$sql .= " ".(is_object($user) && (int) $user->id > 0 ? (int) $user->id : "NULL").", '".$db->idate($now)."')";
		if (!$db->query($sql)) {
			// The same change again: the unique key holds, and the entry that is there stays as it is.
			if ($db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				return 0;
			}
			dol_syslog('VereineChanges::record '.$db->lasterror(), LOG_ERR);
			return -1;
		}
		return 1;
	}

	/**
	 * The revision an object reaches with its next change: how often it changed, counted from one.
	 *
	 * @param DoliDB $db       Database handler
	 * @param int    $entity   Entity
	 * @param string $type     Kind of object
	 * @param int    $objectId The object
	 * @return int
	 */
	private static function nextRevision($db, $entity, $type, $objectId)
	{
		$sql = "SELECT MAX(revision) as last FROM ".MAIN_DB_PREFIX."vereine_change WHERE entity = ".((int) $entity);
		$sql .= " AND object_type = '".$db->escape($type)."' AND object_id = ".((int) $objectId);
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		return ($obj ? (int) $obj->last : 0) + 1;
	}

	/**
	 * Note the same change for several objects at once.
	 *
	 * @param DoliDB    $db    Database handler
	 * @param string    $type  Kind of object
	 * @param int[]     $ids   The objects
	 * @param string    $kind  Kind of change
	 * @param User|null $user  Who caused it
	 * @return int Number noted
	 */
	public static function recordMany($db, $type, array $ids, $kind, $user = null)
	{
		$noted = 0;
		foreach (array_unique(array_map('intval', $ids)) as $id) {
			if ($id > 0 && self::record($db, $type, $id, $kind, $user) > 0) {
				$noted++;
			}
		}
		return $noted;
	}

	/**
	 * Now, in the time zone the database writes its own stamps in.
	 *
	 * The feed keeps two clocks on purpose: datec and the cursor follow the database, so a comparison
	 * never crosses a time zone, while occurred_at is the moment the world sees and stays GMT.
	 *
	 * @return string YYYY-MM-DD HH:MM:SS
	 */
	private function stored()
	{
		return dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S', 'tzserver');
	}

	/**
	 * A page of the feed from a cursor on.
	 *
	 * @param string $cursor Where the reader stands, empty to start at the beginning
	 * @param mixed  $limit  Entries per page
	 * @param mixed  $types  Kinds of object the reader wants, comma separated
	 * @return array{events:array<int,array<string,mixed>>,next_cursor:string,resync_required:bool,has_more:bool,retention_days:int}
	 */
	public function feed($cursor, $limit, $types = '')
	{
		global $conf;

		$entity = (int) $conf->entity;
		$now = $this->stored();
		$position = VereineChangeRules::readCursor($cursor);
		$oldest = $this->oldest($entity);
		$size = VereineChangeRules::pageSize($limit);
		$answer = array('events' => array(), 'next_cursor' => (string) $cursor, 'resync_required' => false,
			'has_more' => false, 'retention_days' => VereineChangeRules::RETENTION_DAYS);
		if ((string) $cursor !== '' && $position === null) {
			// A cursor nobody wrote here: better start over than pretend to continue.
			$answer['resync_required'] = true;
			return $answer;
		}
		if (VereineChangeRules::resyncRequired($position, $oldest, $now)) {
			$answer['resync_required'] = true;
			return $answer;
		}
		$wanted = VereineChangeRules::wantedTypes($types);
		$quoted = array();
		foreach ($wanted as $type) {
			$quoted[] = "'".$this->db->escape($type)."'";
		}
		$sql = "SELECT rowid, event_id, object_type, object_id, revision, change_kind, occurred_at, datec";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_change WHERE entity = ".$entity;
		$sql .= " AND object_type IN (".implode(', ', $quoted).")";
		// Never past the safety margin, so nothing appears behind a cursor the reader already confirmed.
		$sql .= " AND datec <= '".$this->db->escape(VereineChangeRules::horizon($now))."'";
		if ($position !== null) {
			$sql .= " AND (datec > '".$this->db->escape($position['written_at'])."'";
			$sql .= " OR (datec = '".$this->db->escape($position['written_at'])."' AND rowid > ".((int) $position['row'])."))";
		}
		$sql .= " ORDER BY datec, rowid LIMIT ".($size + 1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $answer;
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$this->db->free($resql);
		if (count($rows) > $size) {
			$answer['has_more'] = true;
			$rows = array_slice($rows, 0, $size);
		}
		foreach ($rows as $obj) {
			$answer['events'][] = array(
				'event_id' => (string) $obj->event_id,
				'object_type' => (string) $obj->object_type,
				'object_id' => (int) $obj->object_id,
				'revision' => (int) $obj->revision,
				'change' => (string) $obj->change_kind,
				'occurred_at' => str_replace(' ', 'T', substr((string) $obj->occurred_at, 0, 19)).'Z',
			);
			$answer['next_cursor'] = VereineChangeRules::cursor(substr((string) $obj->datec, 0, 19), (int) $obj->rowid);
		}
		return $answer;
	}

	/**
	 * A page of the full reconciliation: which objects exist right now, nothing more.
	 *
	 * The last page says so. A reconciliation that broke off is no proof that anything was deleted, so a
	 * client may only act on what is missing once it has seen that mark.
	 *
	 * @param string $type   Kind of object
	 * @param int    $after  Object to continue after, 0 to start
	 * @param mixed  $limit  Objects per page
	 * @return array{object_type:string,objects:array<int,array<string,mixed>>,next_after:int,complete:bool,cursor:string}
	 */
	public function snapshot($type, $after, $limit)
	{
		global $conf;

		$entity = (int) $conf->entity;
		$size = VereineChangeRules::pageSize($limit);
		$answer = array('object_type' => (string) $type, 'objects' => array(), 'next_after' => (int) $after,
			'complete' => true, 'cursor' => '');
		if (!in_array((string) $type, VereineChangeRules::TYPES, true)) {
			return $answer;
		}
		// The cursor of the moment the reconciliation starts from, so nothing between the two is lost.
		$answer['cursor'] = $this->head($entity);
		$source = $this->source((string) $type, $entity, (int) $after, $size + 1);
		if ($source === null) {
			return $answer;
		}
		$rows = array();
		$resql = $this->db->query($source);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $answer;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = (int) $obj->rowid;
		}
		$this->db->free($resql);
		if (count($rows) > $size) {
			$answer['complete'] = false;
			$rows = array_slice($rows, 0, $size);
		}
		foreach ($rows as $id) {
			$answer['objects'][] = array('object_type' => (string) $type, 'object_id' => $id);
			$answer['next_after'] = $id;
		}
		return $answer;
	}

	/**
	 * Where the objects of a kind are read from for the reconciliation. Only ids, never any content.
	 *
	 * @param string $type   Kind of object
	 * @param int    $entity Entity
	 * @param int    $after  Continue after this object
	 * @param int    $limit  At most so many
	 * @return string|null The query, null when the kind has no source of its own
	 */
	private function source($type, $entity, $after, $limit)
	{
		$tables = array(
			VereineChangeRules::TYPE_MEMBERSHIP => 'adherent',
			VereineChangeRules::TYPE_FUNCTION => 'vereine_function_term',
			VereineChangeRules::TYPE_APPLICATION => 'vereine_application',
			VereineChangeRules::TYPE_CONSENT => 'vereine_consent',
		);
		if (!isset($tables[$type])) {
			// A fee is not an object of its own; it is read at the member it belongs to.
			return null;
		}
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$tables[$type]." WHERE entity = ".((int) $entity);
		$sql .= " AND rowid > ".((int) $after)." ORDER BY rowid LIMIT ".((int) $limit);
		return $sql;
	}

	/**
	 * The cursor of the newest entry a reader may see right now.
	 *
	 * @param int $entity Entity
	 * @return string Empty when there is nothing to follow yet
	 */
	public function head($entity)
	{
		$now = $this->stored();
		$sql = "SELECT rowid, datec FROM ".MAIN_DB_PREFIX."vereine_change WHERE entity = ".((int) $entity);
		$sql .= " AND datec <= '".$this->db->escape(VereineChangeRules::horizon($now))."' ORDER BY datec DESC, rowid DESC LIMIT 1";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? VereineChangeRules::cursor(substr((string) $obj->datec, 0, 19), (int) $obj->rowid) : '';
	}

	/**
	 * The moment of the oldest entry still kept.
	 *
	 * @param int $entity Entity
	 * @return string Empty when the feed is empty
	 */
	public function oldest($entity)
	{
		$sql = "SELECT MIN(datec) as first_entry FROM ".MAIN_DB_PREFIX."vereine_change WHERE entity = ".((int) $entity);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj && $obj->first_entry ? substr((string) $obj->first_entry, 0, 19) : '';
	}

	/**
	 * Throw away entries that are older than the association keeps them.
	 *
	 * @return int Number thrown away, <0 on error
	 */
	public function purge()
	{
		global $conf;

		$now = $this->stored();
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."vereine_change WHERE entity = ".((int) $conf->entity);
		$sql .= " AND datec < '".$this->db->escape(VereineChangeRules::oldestKept($now))."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return (int) $this->db->affected_rows($resql);
	}

	/**
	 * Every change noted about one object, newest first, for a look from the inside.
	 *
	 * @param string $type     Kind of object
	 * @param int    $objectId The object
	 * @return array<int,array<string,mixed>>
	 */
	public function forObject($type, $objectId)
	{
		global $conf;

		$sql = "SELECT event_id, revision, change_kind, occurred_at FROM ".MAIN_DB_PREFIX."vereine_change";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND object_type = '".$this->db->escape((string) $type)."'";
		$sql .= " AND object_id = ".((int) $objectId)." ORDER BY revision DESC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array('event_id' => (string) $obj->event_id, 'revision' => (int) $obj->revision,
				'change' => (string) $obj->change_kind, 'occurred_at' => substr((string) $obj->occurred_at, 0, 19));
		}
		$this->db->free($resql);
		return $rows;
	}
}
