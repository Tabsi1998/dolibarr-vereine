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
 * \file    docs/beispiele/webhook-empfaenger.php
 * \ingroup vereine
 * \brief   Reference receiver of the signed webhooks (#155), short enough to read in one go.
 *
 * This is not part of the module. It is the smallest receiver that does everything a receiver has to
 * do, so an association can hand it to whoever builds their website:
 *
 *   1. read the body as bytes, never as parsed data, because the signature covers the bytes;
 *   2. check the signature with the secret of the key the header names;
 *   3. refuse a moment that is too old, so a recording cannot be played back later;
 *   4. remember the name of the event and refuse it a second time;
 *   5. answer 200 quickly and read the real data through the API afterwards.
 *
 * A webhook is a hint to read something, never a proof. Whoever books a membership or a payment on the
 * strength of a webhook alone has built the wrong thing: read the data through the API, and reconcile
 * regularly, because a hint can always be missed.
 *
 * Run it behind https. Set VEREINE_HOOK_SECRETS to a JSON object of key id to secret, for instance
 * {"a1b2c3d4":"…","e5f6a7b8":"…"} — two entries while a rotation is running.
 */

// How far the signed moment may lie in the past or the future, in seconds.
const TOLERANCE = 300;

/** Where the names of the events already seen are kept. A real receiver uses its database. */
const SEEN_FILE = '/tmp/vereine-hook-seen.json';

/** Where this example writes what it took, so a test can look at it. */
const LOG_FILE = '/tmp/vereine-hook-log.json';

/**
 * Answer and stop.
 *
 * @param int    $code   HTTP status
 * @param string $reason Short reason, for the log of the sender
 * @return void
 */
function answer($code, $reason)
{
	http_response_code($code);
	header('Content-Type: application/json');
	print json_encode(array('ok' => $code < 300, 'reason' => $reason));
	exit;
}

$secrets = json_decode((string) getenv('VEREINE_HOOK_SECRETS'), true);
if (!is_array($secrets) || !$secrets) {
	answer(500, 'no secrets configured');
}

// The bytes exactly as they arrived: parsing first and signing the result would check something else.
$body = (string) file_get_contents('php://input');
$header = isset($_SERVER['HTTP_VEREINE_SIGNATURE']) ? (string) $_SERVER['HTTP_VEREINE_SIGNATURE'] : '';

$parts = array();
foreach (explode(',', $header) as $piece) {
	$at = strpos($piece, '=');
	if ($at === false) {
		answer(400, 'header');
	}
	$parts[trim(substr($piece, 0, $at))] = trim(substr($piece, $at + 1));
}
if (!isset($parts['v1'], $parts['t'], $parts['k'], $parts['e'])) {
	answer(400, 'header');
}
if (!isset($secrets[$parts['k']])) {
	answer(400, 'key');
}
if (abs(time() - (int) $parts['t']) > TOLERANCE) {
	answer(400, 'expired');
}
$expected = hash_hmac('sha256', 'v1.'.((int) $parts['t']).'.'.$body, (string) $secrets[$parts['k']]);
// Constant time: a comparison that stops at the first wrong character tells an attacker how far they got.
if (!hash_equals($expected, (string) $parts['v1'])) {
	answer(400, 'signature');
}

// The same notice may arrive more than once; the name of the event decides, not the moment.
$seen = is_file(SEEN_FILE) ? json_decode((string) file_get_contents(SEEN_FILE), true) : array();
$seen = is_array($seen) ? $seen : array();
if (isset($seen[$parts['e']])) {
	answer(200, 'already seen');
}
$seen[$parts['e']] = time();
file_put_contents(SEEN_FILE, json_encode($seen));

$event = json_decode($body, true);
$log = is_file(LOG_FILE) ? json_decode((string) file_get_contents(LOG_FILE), true) : array();
$log = is_array($log) ? $log : array();
$log[] = array('event' => is_array($event) ? $event : null, 'key' => $parts['k'], 'received_at' => time());
file_put_contents(LOG_FILE, json_encode($log));

// Here the real work begins: read the object through the API, then reconcile regularly anyway.
answer(200, 'taken');
