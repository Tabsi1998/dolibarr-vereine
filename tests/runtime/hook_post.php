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
 * \file    tests/runtime/hook_post.php
 * \ingroup vereine
 * \brief   Post a body to the reference receiver, for the runtime test of #155.
 *
 * Belongs to the tests, never to the module: it runs inside the container and sends exactly the bytes
 * of a file, so the receiver sees what the sender would have sent. It uses curl because the container
 * may have allow_url_fopen switched off, which is how a hardened installation is set up.
 *
 * Usage: php hook_post.php <url> <body file> <signature header>
 */

if ($argc < 4) {
	fwrite(STDERR, "usage: hook_post.php <url> <body file> <header>\n");
	exit(2);
}
$body = (string) file_get_contents($argv[2]);
$call = curl_init($argv[1]);
curl_setopt($call, CURLOPT_POST, true);
curl_setopt($call, CURLOPT_POSTFIELDS, $body);
curl_setopt($call, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Vereine-Signature: '.$argv[3]));
curl_setopt($call, CURLOPT_RETURNTRANSFER, true);
curl_setopt($call, CURLOPT_TIMEOUT, 10);
$answer = curl_exec($call);
$failed = curl_error($call);
curl_close($call);
// The answer of a refusal is what the test wants to read, so a 4xx is an answer like any other.
print $answer === false || $answer === '' ? '{"ok":false,"reason":"no answer","curl":"'.$failed.'"}' : $answer;
