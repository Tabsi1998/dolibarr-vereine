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
 * \file    class/vereinemailrules.class.php
 * \ingroup vereine
 * \brief   What a mail server's answer means, in words a treasurer understands. Plain PHP.
 *
 * Mail servers answer in terse English with numeric codes (RFC 5321, RFC 3463). The most frequent
 * refusals have a simple cause and a simple fix; the raw answer stays available below the explanation.
 */

/**
 * Rules of sending e-mail.
 */
class VereineMailRules
{
	/** The server does not let this sender address send. */
	const CAUSE_SENDER = 'sender';
	/** The address of the recipient is refused. */
	const CAUSE_RECIPIENT = 'recipient';
	/** The server refused the login. */
	const CAUSE_LOGIN = 'login';
	/** The server could not be reached. */
	const CAUSE_CONNECT = 'connect';
	/** Anything else. */
	const CAUSE_OTHER = 'other';
	/** Every cause in the order the language keys follow. */
	const CAUSES = array('sender', 'recipient', 'login', 'connect', 'other');

	/**
	 * Why a mail was not sent.
	 *
	 * @param string $answer What the mail server or Dolibarr answered
	 * @return array{cause:string,login:string} The cause and, for a refused sender, the address the server allows
	 */
	public static function explain($answer)
	{
		$text = (string) $answer;
		$login = '';
		if (preg_match('/not owned by user\s+<?([^\s>]+@[^\s>]+?)>?[\s.]*(?:\\\\r|\r|\n|$)/i', $text, $found)) {
			$login = rtrim($found[1], '.');
		}
		if ($login !== '' || preg_match('/sender address rejected|553[ -]5\.7\.1|5\.7\.1 .*sender|sender .*not (allowed|permitted)/i', $text)) {
			return array('cause' => self::CAUSE_SENDER, 'login' => $login);
		}
		if (preg_match('/535|authentication (failed|unsuccessful)|auth(entication)? .*(fail|refus)|username and password not accepted/i', $text)) {
			return array('cause' => self::CAUSE_LOGIN, 'login' => '');
		}
		if (preg_match('/could not (connect|open socket)|connection (refused|timed out)|failed to connect|getaddrinfo|network is unreachable|stream_socket_client/i', $text)) {
			return array('cause' => self::CAUSE_CONNECT, 'login' => '');
		}
		if (preg_match('/recipient address rejected|no valid recipients|550[ -]5\.1\.1|user unknown|mailbox unavailable/i', $text)) {
			return array('cause' => self::CAUSE_RECIPIENT, 'login' => '');
		}
		return array('cause' => self::CAUSE_OTHER, 'login' => '');
	}

	/**
	 * The raw answer as it can be shown: escaped line breaks become real ones, the lines are trimmed.
	 *
	 * @param string $answer What the mail server or Dolibarr answered
	 * @return string
	 */
	public static function readable($answer)
	{
		$text = str_replace(array('\\r\\n', '\\n', '\\r'), "\n", (string) $answer);
		$text = str_replace(array("\r\n", "\r"), "\n", $text);
		$lines = array();
		foreach (explode("\n", $text) as $line) {
			$line = trim($line);
			if ($line !== '') {
				$lines[] = $line;
			}
		}
		return implode("\n", $lines);
	}

	/**
	 * Whether an address can be a sender.
	 *
	 * @param string $address Address
	 * @return bool
	 */
	public static function validSender($address)
	{
		return (bool) preg_match('/^[^\s@<>]+@[^\s@<>]+\.[a-z]{2,}$/i', trim((string) $address));
	}
}
