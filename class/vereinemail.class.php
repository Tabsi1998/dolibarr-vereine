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
 * \file    class/vereinemail.class.php
 * \ingroup vereine
 * \brief   The one way the module sends e-mail: the sender of the association, a test mail, errors in plain words.
 *
 * Many mail servers only let the address send that Dolibarr logs in with. The sender for the e-mails of
 * the association is therefore a setting of its own; without it Dolibarr's sender for automatic e-mails
 * is used, as before.
 */

require_once __DIR__.'/vereinemailrules.class.php';

/**
 * Sending e-mail for the association.
 */
class VereineMail
{
	/** Sender address for the e-mails of the association. */
	const CONST_FROM = 'VEREINE_MAIL_FROM';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string What the mail server answered when the last e-mail was not sent
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
	 * The sender of the e-mails of the association.
	 *
	 * @return string
	 */
	public static function sender()
	{
		global $mysoc;

		$own = trim(getDolGlobalString(self::CONST_FROM));
		if ($own !== '') {
			return $own;
		}
		return getDolGlobalString('MAIN_MAIL_EMAIL_FROM', (string) $mysoc->email);
	}

	/**
	 * Send one e-mail.
	 *
	 * @param string   $subject Subject
	 * @param string   $to      Recipient
	 * @param string   $body    Text of the e-mail
	 * @param string   $trackid What the e-mail belongs to, for Dolibarr's tracking
	 * @param string[] $files   Paths of attachments
	 * @param string[] $mimes   Types of the attachments
	 * @param string[] $names   Names of the attachments
	 * @return bool Whether the e-mail went out (see error when not)
	 */
	public function send($subject, $to, $body, $trackid, array $files = array(), array $mimes = array(), array $names = array())
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

		$this->error = '';
		$mail = new CMailFile($subject, $to, self::sender(), $body, $files, $mimes, $names, '', '', 0, 0, '', '', $trackid);
		if ($mail->sendfile()) {
			return true;
		}
		$this->error = (string) $mail->error;
		return false;
	}

	/**
	 * Store the sender of the association.
	 *
	 * @param string $address Sender, empty for Dolibarr's sender
	 * @return int 1 when stored, 0 when the address is no address, -1 on error
	 */
	public function saveSender($address)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$address = trim((string) $address);
		if ($address !== '' && !VereineMailRules::validSender($address)) {
			return 0;
		}
		return dolibarr_set_const($this->db, self::CONST_FROM, $address, 'chaine', 0, '', $conf->entity) > 0 ? 1 : -1;
	}

	/**
	 * Why an e-mail was not sent, in plain words, and what to do about it.
	 *
	 * @param string    $answer What the mail server answered
	 * @param Translate $langs  Language
	 * @return string
	 */
	public static function describe($answer, $langs)
	{
		$reason = VereineMailRules::explain((string) $answer);
		if ($reason['cause'] === VereineMailRules::CAUSE_SENDER) {
			return $langs->transnoentities('VereineMailError_sender', self::sender(),
				$reason['login'] !== '' ? $langs->transnoentities('VereineMailErrorOnly', $reason['login']) : '');
		}
		return $langs->transnoentities('VereineMailError_'.$reason['cause']);
	}
}
