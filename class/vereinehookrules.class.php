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
 * \file    class/vereinehookrules.class.php
 * \ingroup vereine
 * \brief   Rules of the signed webhooks (#155): what is signed, when it is tried again, plain PHP.
 *
 * A delivery is a hint to read something, never a proof. It carries the same reference the change feed
 * carries and nothing else: kind of object, its id, its revision, the kind of change and when it
 * happened. No names, no amounts, no documents, no bank data, no votes.
 *
 * The signature covers the version, the moment of this attempt and the body exactly as it goes over the
 * wire. A repetition keeps the name of the event and gets a new moment and a new signature, so a
 * receiver can tell a repetition from a replay: the name says what it is about, the moment says how old
 * it is. Receivers accept a delivery more than once and sort it out by the name of the event.
 */

/**
 * Rules of the signed webhooks.
 */
class VereineHookRules
{
	/** The version of the signature format. */
	const VERSION = 'v1';

	/** The header the signature travels in. */
	const HEADER = 'Vereine-Signature';

	/** How long a signed moment is accepted, in seconds. */
	const TOLERANCE_SECONDS = 300;

	/** How often a delivery is tried before it is left alone. */
	const MAX_ATTEMPTS = 8;

	/** How long one attempt may take, in seconds. */
	const TIMEOUT_SECONDS = 10;

	/** How long a rotated secret stays valid beside the new one, in seconds. */
	const ROTATION_SECONDS = 86400;

	/** A delivery that has not been tried yet, or waits for its next attempt. */
	const STATUS_PENDING = 'pending';
	/** The receiver took it. */
	const STATUS_SENT = 'sent';
	/** Every attempt failed; nothing is tried again unless somebody says so. */
	const STATUS_FAILED = 'failed';
	/** Nobody may read it any more, so it is not delivered. */
	const STATUS_STOPPED = 'stopped';

	/** Every state a delivery may be in. */
	const STATUSES = array('pending', 'sent', 'failed', 'stopped');

	/**
	 * The body of a delivery: the same reference the change feed gives, and nothing more.
	 *
	 * @param array<string,mixed> $event Keys event_id, object_type, object_id, revision, change, occurred_at
	 * @return string JSON, exactly the bytes that are signed and sent
	 */
	public static function body(array $event)
	{
		return (string) json_encode(array(
			'version' => self::VERSION,
			'event_id' => (string) $event['event_id'],
			'object_type' => (string) $event['object_type'],
			'object_id' => (int) $event['object_id'],
			'revision' => (int) $event['revision'],
			'change' => (string) $event['change'],
			'occurred_at' => (string) $event['occurred_at'],
		));
	}

	/**
	 * The signature of a body for one moment.
	 *
	 * @param string $secret    The secret of the target
	 * @param string $body      The bytes that go over the wire
	 * @param int    $timestamp Moment of this attempt, seconds since the epoch
	 * @return string Hexadecimal
	 */
	public static function signature($secret, $body, $timestamp)
	{
		return hash_hmac('sha256', self::VERSION.'.'.((int) $timestamp).'.'.(string) $body, (string) $secret);
	}

	/**
	 * The header of a delivery.
	 *
	 * @param string $secret    The secret of the target
	 * @param string $keyId     Which secret it is, so a rotation can be told apart
	 * @param string $body      The bytes that go over the wire
	 * @param int    $timestamp Moment of this attempt
	 * @param string $eventId   The name of the event, the same over repetitions
	 * @return string
	 */
	public static function header($secret, $keyId, $body, $timestamp, $eventId)
	{
		return self::VERSION.'='.self::signature($secret, $body, $timestamp)
			.',t='.((int) $timestamp).',k='.(string) $keyId.',e='.(string) $eventId;
	}

	/**
	 * What a header holds, null when it is not one of ours.
	 *
	 * @param string $header The header
	 * @return array{signature:string,timestamp:int,key_id:string,event_id:string}|null
	 */
	public static function readHeader($header)
	{
		$parts = array();
		foreach (explode(',', (string) $header) as $piece) {
			$at = strpos($piece, '=');
			if ($at === false) {
				return null;
			}
			$parts[trim(substr($piece, 0, $at))] = trim(substr($piece, $at + 1));
		}
		if (!isset($parts[self::VERSION], $parts['t'], $parts['k'], $parts['e'])
			|| !preg_match('/^[0-9a-f]{64}$/', $parts[self::VERSION]) || !preg_match('/^\d{1,12}$/', $parts['t'])) {
			return null;
		}
		return array('signature' => $parts[self::VERSION], 'timestamp' => (int) $parts['t'],
			'key_id' => $parts['k'], 'event_id' => $parts['e']);
	}

	/**
	 * Whether a delivery is genuine, fresh and signed with a secret the receiver knows.
	 *
	 * This is what a receiver does, and the module keeps it here so the reference receiver and the tests
	 * follow exactly the same rules as the sender.
	 *
	 * @param string               $header  The header as it arrived
	 * @param string               $body    The body exactly as it arrived, byte for byte
	 * @param array<string,string> $secrets Secret by key id; during a rotation there are two
	 * @param int                  $now     Now, seconds since the epoch
	 * @return string Empty when it is genuine, otherwise which check failed
	 */
	public static function verify($header, $body, array $secrets, $now)
	{
		$parts = self::readHeader($header);
		if ($parts === null) {
			return 'header';
		}
		if (!isset($secrets[$parts['key_id']])) {
			return 'key';
		}
		if (abs((int) $now - $parts['timestamp']) > self::TOLERANCE_SECONDS) {
			return 'expired';
		}
		$expected = self::signature($secrets[$parts['key_id']], $body, $parts['timestamp']);
		// Constant time: a comparison that stops early tells an attacker how far they got.
		return hash_equals($expected, $parts['signature']) ? '' : 'signature';
	}

	/**
	 * How long to wait before the next attempt: a little longer each time, up to six hours.
	 *
	 * @param int $attempt Attempts made so far, from 1
	 * @return int Seconds
	 */
	public static function backoff($attempt)
	{
		$steps = array(30, 120, 600, 1800, 3600, 10800, 21600);
		$index = max(0, (int) $attempt - 1);
		return $steps[min($index, count($steps) - 1)];
	}

	/**
	 * Whether an answer counts as taken. Everything from 200 to 299 does, nothing else.
	 *
	 * @param int $code HTTP status
	 * @return bool
	 */
	public static function accepted($code)
	{
		return (int) $code >= 200 && (int) $code < 300;
	}

	/**
	 * What is wrong with an address before it is stored.
	 *
	 * Only https, no credentials in the address, and no host inside the network unless the association
	 * switched that on for this target on purpose. The name is looked up again before every attempt,
	 * because a name that answers with a public address today may answer with a local one tomorrow.
	 *
	 * @param string $url           The address
	 * @param bool   $allowInternal Whether a host inside the network is allowed for this target
	 * @return string Empty when fine, otherwise a language key
	 */
	public static function checkUrl($url, $allowInternal)
	{
		$text = trim((string) $url);
		if ($text === '' || mb_strlen($text, 'UTF-8') > 255) {
			return 'VereineHookErrorUrl';
		}
		$parts = parse_url($text);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https') {
			return 'VereineHookErrorHttps';
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			return 'VereineHookErrorCredentials';
		}
		if (!$allowInternal && self::isInternalHost((string) $parts['host'])) {
			return 'VereineHookErrorInternal';
		}
		return '';
	}

	/**
	 * Whether a host lies inside the network, so a delivery must not go there by accident.
	 *
	 * A name is resolved; what it answers with decides. That keeps a name that points at a local
	 * address today from being taken for a public one because it was public yesterday.
	 *
	 * @param string $host Host of the address
	 * @return bool
	 */
	public static function isInternalHost($host)
	{
		$name = strtolower(trim((string) $host, "[] \t\n\r\0\x0B"));
		if ($name === '' || $name === 'localhost' || substr($name, -6) === '.local' || substr($name, -10) === '.localhost') {
			return true;
		}
		$addresses = self::addressesOf($name);
		if (!$addresses) {
			// A name nobody can resolve is not a target either.
			return true;
		}
		foreach ($addresses as $address) {
			if (self::isInternalAddress($address)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The addresses a host name answers with; the name itself when it already is an address.
	 *
	 * @param string $name Host
	 * @return string[]
	 */
	public static function addressesOf($name)
	{
		if (filter_var($name, FILTER_VALIDATE_IP) !== false) {
			return array($name);
		}
		$addresses = array();
		$ipv4 = gethostbynamel($name);
		if (is_array($ipv4)) {
			$addresses = $ipv4;
		}
		$records = @dns_get_record($name, DNS_AAAA);
		if (is_array($records)) {
			foreach ($records as $record) {
				if (isset($record['ipv6'])) {
					$addresses[] = (string) $record['ipv6'];
				}
			}
		}
		return $addresses;
	}

	/**
	 * Whether an address lies inside the network, including the addresses a cloud answers itself with.
	 *
	 * @param string $address IPv4 or IPv6
	 * @return bool
	 */
	public static function isInternalAddress($address)
	{
		$ip = trim((string) $address);
		if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
			return true;
		}
		// What is neither private nor reserved is a public address; everything else stays inside.
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			return true;
		}
		// The address a cloud answers about itself with is public by the letter and internal in effect.
		return $ip === '169.254.169.254' || strpos($ip, 'fd00:ec2') === 0;
	}

	/**
	 * A secret nobody can guess, and the short name it is known by.
	 *
	 * @return array{key_id:string,secret:string}
	 */
	public static function newSecret()
	{
		return array('key_id' => bin2hex(random_bytes(4)), 'secret' => bin2hex(random_bytes(32)));
	}

	/**
	 * A message about a failure with anything secret taken out.
	 *
	 * @param string $text  What went wrong
	 * @param string[] $secrets Secrets that must never appear
	 * @return string At most 250 characters
	 */
	public static function maskError($text, array $secrets = array())
	{
		$clean = (string) $text;
		foreach ($secrets as $secret) {
			if ((string) $secret !== '') {
				$clean = str_replace((string) $secret, '…', $clean);
			}
		}
		$clean = preg_replace('/'.self::VERSION.'=[0-9a-f]{8,}/', self::VERSION.'=…', $clean);
		return mb_substr(trim((string) $clean), 0, 250, 'UTF-8');
	}

	/**
	 * What a secret looks like when somebody looks at the setup: its name, never the secret.
	 *
	 * @param string $keyId The name of the secret
	 * @return string
	 */
	public static function maskSecret($keyId)
	{
		return (string) $keyId !== '' ? (string) $keyId.'…' : '';
	}
}
