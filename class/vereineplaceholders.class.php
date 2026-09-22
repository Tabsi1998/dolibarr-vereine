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
 * \file    class/vereineplaceholders.class.php
 * \ingroup vereine
 * \brief   Placeholders such as __VEREINE_ZVR__: the data of the association in Dolibarr's e-mail templates and texts.
 *
 * Dolibarr asks every module for its placeholders (core/substitutions/functions_vereine.lib.php), so the
 * association's ones work in every e-mail template of Dolibarr. Those of a meeting, the minutes and a
 * circular resolution only exist in the e-mails the module sends itself.
 */

/**
 * The placeholders of the module and their values.
 */
class VereinePlaceholders
{
	/** Data of the association, everywhere in Dolibarr. */
	const GROUP_ASSOCIATION = 'association';
	/** At a member, in Dolibarr's e-mails to a member. */
	const GROUP_MEMBER = 'member';
	/** In the invitation and the minutes of a meeting. */
	const GROUP_MEETING = 'meeting';
	/** In the e-mails of a circular resolution. */
	const GROUP_CIRCULAR = 'circular';
	/** In the texts per agenda item of the minutes. */
	const GROUP_MINUTES = 'minutes';

	/** Every placeholder by group, in the order the list shows them. */
	const KEYS = array(
		'association' => array('__VEREINE_NAME__', '__VEREINE_ZVR__', '__VEREINE_SITZ__', '__VEREINE_ADRESSE__', '__VEREINE_EMAIL__', '__VEREINE_WEBSITE__',
			'__VEREINE_BEHOERDE__', '__VEREINE_GEGRUENDET__', '__VEREINE_ZWECK__', '__VEREINE_OBMANN__', '__VEREINE_KASSIER__', '__VEREINE_SCHRIFTFUEHRUNG__',
			'__VEREINE_VORSTAND__'),
		'member' => array('__VEREINE_MITGLIED_FUNKTIONEN__'),
		'meeting' => array('__VEREINE_EMPFAENGER__', '__VEREINE_SITZUNG_TITEL__', '__VEREINE_SITZUNG_ART__', '__VEREINE_SITZUNG_TAG__', '__VEREINE_SITZUNG_ZEIT__',
			'__VEREINE_SITZUNG_ORT__', '__VEREINE_SITZUNG_ZUGANG__', '__VEREINE_SITZUNG_EINLEITUNG__', '__VEREINE_SITZUNG_DETAILS__', '__VEREINE_TAGESORDNUNG__',
			'__VEREINE_ANTRAGSFRIST__', '__VEREINE_SITZUNG_HINWEISE__'),
		'circular' => array('__VEREINE_EMPFAENGER__', '__VEREINE_UMLAUF_TITEL__', '__VEREINE_UMLAUF_ANTRAG__', '__VEREINE_UMLAUF_FRIST__',
			'__VEREINE_UMLAUF_LINK__'),
		'minutes' => array('{verein}', '{datum}', '{ort}', '{anwesend}', '{vertreten}', '{stimmen}', '{stimmberechtigt}', '{quorum}', '{beschlussfaehig}',
			'{ergebnis}'),
	);

	/** The functions named in the placeholders of their own. */
	const FUNCTIONS = array('__VEREINE_OBMANN__' => 'obmann', '__VEREINE_KASSIER__' => 'kassier', '__VEREINE_SCHRIFTFUEHRUNG__' => 'schriftfuehrung');

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var array<string,string>|null Values of the association, once per request
	 */
	private static $cache = null;

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
	 * The language key that explains a placeholder.
	 *
	 * @param string $key Placeholder
	 * @return string
	 */
	public static function describedBy($key)
	{
		if (preg_match('/^\{([a-z]+)\}$/', $key, $match)) {
			return 'VereineMinutesPlaceholder_'.$match[1];
		}
		return 'VereinePh_'.strtolower(trim($key, '_'));
	}

	/**
	 * Values of the association's placeholders.
	 *
	 * @param array<string,mixed>                            $organization Data of VereineOrganization::build()
	 * @param array<int,array{code:string,label:string,board:bool}> $functions    Active functions of the catalogue
	 * @param array<string,array<int,string>>                $holders      Names of the holders today by function code
	 * @param string                                         $founded      Founding day as shown, empty when unknown
	 * @return array<string,string>
	 */
	public static function association(array $organization, array $functions, array $holders, $founded)
	{
		$address = trim($organization['address']['street']);
		$town = trim($organization['address']['zip'].' '.$organization['address']['town']);
		$board = array();
		foreach ($functions as $function) {
			if (!empty($function['board']) && !empty($holders[$function['code']])) {
				$board[] = $function['label'].': '.implode(', ', $holders[$function['code']]);
			}
		}
		$values = array(
			'__VEREINE_NAME__' => (string) $organization['name'],
			'__VEREINE_ZVR__' => (string) $organization['register']['number'],
			'__VEREINE_SITZ__' => (string) $organization['address']['town'],
			'__VEREINE_ADRESSE__' => implode(', ', array_filter(array($address, $town), 'strlen')),
			'__VEREINE_EMAIL__' => (string) $organization['email'],
			'__VEREINE_WEBSITE__' => (string) $organization['url'],
			'__VEREINE_BEHOERDE__' => (string) $organization['authority'],
			'__VEREINE_GEGRUENDET__' => (string) $founded,
			'__VEREINE_ZWECK__' => (string) $organization['purpose'],
			'__VEREINE_VORSTAND__' => implode("\n", $board),
		);
		foreach (self::FUNCTIONS as $key => $code) {
			$values[$key] = !empty($holders[$code]) ? implode(', ', $holders[$code]) : '';
		}
		return $values;
	}

	/**
	 * Values of a meeting for one recipient, the same wording as the invitation always had.
	 *
	 * @param array<string,mixed> $meeting     Meeting with kind, title, time, place, format, access and agenda
	 * @param string              $recipient   Name of the recipient
	 * @param bool                $voting      Whether the recipient may vote
	 * @param string              $name        Name of the association
	 * @param string              $day         Day of the meeting as shown
	 * @param string              $motions     Deadline for motions as shown, empty when there is none
	 * @param Translate           $outputlangs Language
	 * @return array<string,string>
	 */
	public static function meeting(array $meeting, $recipient, $voting, $name, $day, $motions, $outputlangs)
	{
		$details = array($meeting['title'], $outputlangs->transnoentities('VereineMeetingMailWhen', $day, $meeting['time']));
		if ((string) $meeting['place'] !== '') {
			$details[] = $outputlangs->transnoentities('VereineMeetingMailWhere', $meeting['place']);
		}
		if ($meeting['format'] !== 'physical') {
			$details[] = $outputlangs->transnoentities('VereineMeetingMailFormat_'.$meeting['format']);
			$details[] = $outputlangs->transnoentities('VereineMeetingMailAccess', $meeting['access']);
		}
		$agenda = array();
		foreach ($meeting['agenda'] as $index => $item) {
			$agenda[] = ($index + 1).'. '.$item;
		}
		$notes = array();
		if ((string) $motions !== '') {
			$notes[] = $outputlangs->transnoentities('VereineMeetingMailMotions', $motions);
		}
		if (!$voting) {
			$notes[] = $outputlangs->transnoentities('VereineMeetingMailNotVoting');
		}
		return array(
			'__VEREINE_EMPFAENGER__' => (string) $recipient,
			'__VEREINE_SITZUNG_TITEL__' => (string) $meeting['title'],
			'__VEREINE_SITZUNG_ART__' => $outputlangs->transnoentities('VereineMeetingKind_'.$meeting['kind']),
			'__VEREINE_SITZUNG_TAG__' => (string) $day,
			'__VEREINE_SITZUNG_ZEIT__' => (string) $meeting['time'],
			'__VEREINE_SITZUNG_ORT__' => (string) $meeting['place'],
			'__VEREINE_SITZUNG_ZUGANG__' => (string) $meeting['access'],
			'__VEREINE_SITZUNG_EINLEITUNG__' => $outputlangs->transnoentities('VereineMeetingMailIntro_'.$meeting['kind'], $name),
			'__VEREINE_SITZUNG_DETAILS__' => implode("\n", $details),
			'__VEREINE_TAGESORDNUNG__' => implode("\n", $agenda),
			'__VEREINE_ANTRAGSFRIST__' => (string) $motions,
			// Every note on a line of its own, with an empty line before; nothing when there is none.
			'__VEREINE_SITZUNG_HINWEISE__' => $notes ? "\n".implode("\n\n", $notes)."\n" : '',
		);
	}

	/**
	 * Values of a circular resolution for one recipient.
	 *
	 * @param array<string,mixed> $circular  Circular resolution with title, wording and deadline
	 * @param string              $recipient Name of the recipient
	 * @param string              $deadline  Deadline as shown
	 * @param string              $link      Where to vote
	 * @return array<string,string>
	 */
	public static function circular(array $circular, $recipient, $deadline, $link)
	{
		return array(
			'__VEREINE_EMPFAENGER__' => (string) $recipient,
			'__VEREINE_UMLAUF_TITEL__' => (string) $circular['title'],
			'__VEREINE_UMLAUF_ANTRAG__' => (string) $circular['wording'],
			'__VEREINE_UMLAUF_FRIST__' => (string) $deadline,
			'__VEREINE_UMLAUF_LINK__' => (string) $link,
		);
	}

	/**
	 * Replace the placeholders of the module in a text; unknown ones stay as they are.
	 *
	 * @param string               $text   Text
	 * @param array<string,string> $values Values by placeholder
	 * @param bool                 $html   Whether the text is HTML: values are escaped and keep their line breaks
	 * @return string
	 */
	public static function fill($text, array $values, $html = false)
	{
		if ($html) {
			foreach ($values as $key => $value) {
				$values[$key] = nl2br(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'), false);
			}
		}
		return strtr((string) $text, $values);
	}

	/**
	 * Values of the association's placeholders today, read once per request.
	 *
	 * @return array<string,string>
	 */
	public function associationValues()
	{
		global $mysoc;

		if (self::$cache !== null) {
			return self::$cache;
		}
		require_once __DIR__.'/vereineorganization.class.php';
		require_once __DIR__.'/vereinefunctions.class.php';
		require_once dirname(__DIR__).'/lib/vereine.lib.php';

		$organization = VereineOrganization::load($mysoc);
		$functions = new VereineFunctions($this->db);
		$names = array();
		foreach ($functions->holdersByCode(dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver')) as $code => $holders) {
			$names[$code] = array_column($holders, 'name');
		}
		$founded = (string) $organization['founded'] !== '' ? vereineFormatDay($organization['founded']) : '';
		self::$cache = self::association($organization, $functions->fetchAll(true), $names, $founded);
		return self::$cache;
	}

	/**
	 * Values of the placeholders of a member.
	 *
	 * @param int $memberId Member
	 * @return array<string,string>
	 */
	public function memberValues($memberId)
	{
		require_once __DIR__.'/vereinefunctions.class.php';

		$functions = new VereineFunctions($this->db);
		$held = $functions->memberFunctions((int) $memberId, dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'));
		return array('__VEREINE_MITGLIED_FUNKTIONEN__' => implode(', ', array_column($held, 'label')));
	}

	/**
	 * Forget the values read in this request, after the data changed.
	 *
	 * @return void
	 */
	public static function forget()
	{
		self::$cache = null;
	}
}
