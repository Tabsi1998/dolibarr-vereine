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
 * \file    class/vereinechangerules.class.php
 * \ingroup vereine
 * \brief   Rules of the change feed (#154): what a change looks like and how a cursor moves, plain PHP.
 *
 * The feed says that something changed, never what. An entry carries the kind of object, its id, its
 * revision, the kind of change and when it happened. Names, amounts, invoice contents, signatures and
 * votes never appear; even an id is treated as a reference to a person, so it only goes to a client
 * that may read that object anyway.
 *
 * The cursor is opaque on purpose. It holds the moment an entry was written and its row, in that order,
 * and a reader only ever sees entries that are older than a short safety margin: a transaction that is
 * still open may hold a lower row than one that committed earlier, and without the margin that entry
 * would appear behind a cursor the reader already confirmed. The margin does not make the feed perfect,
 * which is why a full reconciliation stays part of the contract.
 */

/**
 * Rules of the change feed.
 */
class VereineChangeRules
{
	/** The membership of a person. */
	const TYPE_MEMBERSHIP = 'membership';
	/** A function of the association held by somebody. */
	const TYPE_FUNCTION = 'function';
	/** What a member owes or paid. */
	const TYPE_FEE = 'fee';
	/** An application for membership. */
	const TYPE_APPLICATION = 'application';
	/** A consent of a member. */
	const TYPE_CONSENT = 'consent';
	/** A published document: published, replaced by a newer revision, withdrawn (#239). */
	const TYPE_DOCUMENT = 'document';

	/** Every kind of object the feed carries. */
	const TYPES = array('membership', 'function', 'fee', 'application', 'consent', 'document');

	/** The object came into being. */
	const KIND_CREATED = 'created';
	/** Something about it changed. */
	const KIND_UPDATED = 'updated';
	/** It is gone. */
	const KIND_DELETED = 'deleted';
	/** A link to it was cut, for instance a member and a third party. */
	const KIND_UNLINKED = 'unlinked';
	/** A client may no longer read it, so whatever it holds of it has to go. */
	const KIND_REVOKED = 'revoked';

	/** Every kind of change. */
	const KINDS = array('created', 'updated', 'deleted', 'unlinked', 'revoked');

	/** Seconds a reader stays behind the newest entry, so no open transaction can slip in behind it. */
	const HORIZON_SECONDS = 5;

	/** Days an entry is kept; a cursor older than that cannot be continued. */
	const RETENTION_DAYS = 90;

	/** Entries per page, and the largest a caller may ask for. */
	const PAGE_DEFAULT = 100;
	/** The largest page. */
	const PAGE_MAX = 500;

	/**
	 * The name of a change, the same for the same change however often it is written.
	 *
	 * Two clients that see the same event see the same name, and a repetition never looks like a new
	 * change. The moment is part of it, so the same object changing again is a change of its own.
	 *
	 * @param int    $entity     Entity
	 * @param string $type       Kind of object
	 * @param int    $objectId   The object
	 * @param string $kind       Kind of change
	 * @param string $occurredAt When it happened, YYYY-MM-DD HH:MM:SS
	 * @return string
	 */
	public static function eventId($entity, $type, $objectId, $kind, $occurredAt)
	{
		return substr(hash('sha256', implode('|', array((int) $entity, (string) $type, (int) $objectId, (string) $kind,
			(string) $occurredAt))), 0, 40);
	}

	/**
	 * Whether a kind of object and a kind of change are ones the feed knows.
	 *
	 * @param string $type Kind of object
	 * @param string $kind Kind of change
	 * @return bool
	 */
	public static function known($type, $kind)
	{
		return in_array((string) $type, self::TYPES, true) && in_array((string) $kind, self::KINDS, true);
	}

	/**
	 * A cursor from the moment and the row of the last entry a reader took.
	 *
	 * @param string $writtenAt Moment the entry was written, YYYY-MM-DD HH:MM:SS
	 * @param int    $row       Row of the entry
	 * @return string Opaque text
	 */
	public static function cursor($writtenAt, $row)
	{
		// Hex, not base64: the module never decodes base64 outside the two places the security contract allows.
		return bin2hex('v1|'.$writtenAt.'|'.((int) $row));
	}

	/**
	 * What a cursor holds, null when it is not one of ours.
	 *
	 * @param string $cursor The cursor
	 * @return array{written_at:string,row:int}|null
	 */
	public static function readCursor($cursor)
	{
		$text = (string) $cursor;
		if ($text === '') {
			return null;
		}
		if (!preg_match('/^[0-9a-f]{2,200}$/', $text)) {
			return null;
		}
		$plain = hex2bin($text);
		if ($plain === false) {
			return null;
		}
		$parts = explode('|', $plain);
		if (count($parts) !== 3 || $parts[0] !== 'v1' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $parts[1])
			|| !preg_match('/^\d+$/', $parts[2])) {
			return null;
		}
		return array('written_at' => $parts[1], 'row' => (int) $parts[2]);
	}

	/**
	 * The newest moment a reader may see, so an open transaction cannot slip in behind its cursor.
	 *
	 * @param string $now Now, YYYY-MM-DD HH:MM:SS
	 * @return string
	 */
	public static function horizon($now)
	{
		$stamp = strtotime((string) $now.' UTC');
		return $stamp === false ? (string) $now : gmdate('Y-m-d H:i:s', $stamp - self::HORIZON_SECONDS);
	}

	/**
	 * The oldest moment that is still kept.
	 *
	 * @param string $now Now, YYYY-MM-DD HH:MM:SS
	 * @return string
	 */
	public static function oldestKept($now)
	{
		$stamp = strtotime((string) $now.' UTC');
		return $stamp === false ? (string) $now : gmdate('Y-m-d H:i:s', $stamp - self::RETENTION_DAYS * 86400);
	}

	/**
	 * Whether a reader has to start over: its cursor points before what is still kept.
	 *
	 * A reader that has taken nothing yet does not have to start over; it simply starts.
	 *
	 * @param array{written_at:string,row:int}|null $cursor The cursor, null when there is none
	 * @param string                                $oldest Oldest entry still kept, empty when the feed is empty
	 * @param string                                $now    Now
	 * @return bool
	 */
	public static function resyncRequired($cursor, $oldest, $now)
	{
		if ($cursor === null) {
			return false;
		}
		if ($cursor['written_at'] < self::oldestKept($now)) {
			return true;
		}
		// The feed was emptied further than the cursor reaches, so what lies between is gone.
		return (string) $oldest !== '' && $cursor['written_at'] < (string) $oldest;
	}

	/**
	 * How many entries a page holds.
	 *
	 * @param mixed $limit What the caller asked for
	 * @return int
	 */
	public static function pageSize($limit)
	{
		$size = (int) $limit;
		if ($size < 1) {
			return self::PAGE_DEFAULT;
		}
		return min($size, self::PAGE_MAX);
	}

	/**
	 * The kinds of object a caller asked for, all of them when the wish is empty or unknown.
	 *
	 * @param mixed $types Comma separated kinds
	 * @return string[]
	 */
	public static function wantedTypes($types)
	{
		$wanted = array();
		foreach (explode(',', (string) $types) as $type) {
			$type = trim($type);
			if ($type !== '' && in_array($type, self::TYPES, true)) {
				$wanted[] = $type;
			}
		}
		return $wanted ? array_values(array_unique($wanted)) : self::TYPES;
	}
}
