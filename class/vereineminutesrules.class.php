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
 * \file    class/vereineminutesrules.class.php
 * \ingroup vereine
 * \brief   Agenda templates with standard texts, and their placeholders filled with the real numbers of a meeting, plain PHP.
 *
 * The texts of minutes are German, like the statutes of an Austrian association.
 */

require_once __DIR__.'/vereinemeetingrules.class.php';

/**
 * Rules of agenda templates and minutes texts.
 */
class VereineMinutesRules
{
	/** Placeholders a text may use, in the order they are explained. */
	const PLACEHOLDERS = array('verein', 'datum', 'ort', 'anwesend', 'vertreten', 'stimmen', 'stimmberechtigt', 'quorum', 'beschlussfaehig', 'ergebnis');
	/** Longest agenda of a template. */
	const ITEMS_MAX = 30;
	/** Longest standard text or text of an agenda item. */
	const TEXT_MAX = 4000;

	/**
	 * Suggested agenda templates. Required are the items the law asks for, and the quorum every decision needs:
	 * the board informs the general assembly about the activity and the finances (§ 20 VerG).
	 *
	 * @return array<string,array<int,array{title:string,text:string,required:bool}>> By kind of meeting
	 */
	public static function defaults()
	{
		$assembly = 'Generalversammlung';
		$auditors = 'Rechnungsprüfer';
		$item = function ($title, $text, $required = false) {
			return array('title' => $title, 'text' => $text, 'required' => $required);
		};
		$leader = 'Die Obfrau oder der Obmann';
		$counted = 'begrüßt die Anwesenden und stellt fest, dass ordnungsgemäß eingeladen wurde. Anwesend sind {anwesend} Stimmberechtigte, vertreten {vertreten}, zusammen {stimmen} von {stimmberechtigt} Stimmen; nötig sind {quorum}. Die Versammlung ist {beschlussfaehig}.';
		$welcome = $item('Begrüßung und Feststellung der Beschlussfähigkeit', $leader.' '.$counted, true);
		$boardWelcome = $item('Begrüßung und Feststellung der Beschlussfähigkeit', 'Anwesend sind {anwesend} von {stimmberechtigt} Mitgliedern des Vorstands; nötig sind {quorum}. Der Vorstand ist {beschlussfaehig}.', true);
		$other = $item('Allfälliges', '');
		return array(
			VereineMeetingRules::KIND_BOARD => array(
				$boardWelcome,
				$item('Genehmigung des Protokolls der letzten Sitzung', 'Das Protokoll der letzten Sitzung wird genehmigt. {ergebnis}'),
				$item('Berichte aus den Funktionen', ''),
				$item('Beschlüsse', '{ergebnis}'),
				$other,
			),
			VereineMeetingRules::KIND_GENERAL => array(
				$welcome,
				$item('Genehmigung des Protokolls der letzten '.$assembly, 'Das Protokoll der letzten '.$assembly.' wird genehmigt. {ergebnis}'),
				$item('Rechenschaftsbericht des Vorstands', 'Der Vorstand berichtet über die Tätigkeit des Vereins seit der letzten '.$assembly.'.', true),
				$item('Bericht über den Rechnungsabschluss', 'Die Kassierin oder der Kassier stellt den Rechnungsabschluss vor.', true),
				$item('Bericht der '.$auditors, 'Die '.$auditors.' berichten über die Prüfung der Finanzgebarung.'),
				$item('Entlastung des Vorstands', '{ergebnis}'),
				$item('Wahlen', '{ergebnis}'),
				$item('Festsetzung der Beitrittsgebühr und der Mitgliedsbeiträge', '{ergebnis}'),
				$item('Anträge', '{ergebnis}'),
				$other,
			),
			VereineMeetingRules::KIND_EXTRAORDINARY => array(
				$welcome,
				$item('Anlass der außerordentlichen '.$assembly, ''),
				$item('Beschlüsse', '{ergebnis}'),
				$other,
			),
		);
	}

	/**
	 * Templates as entered that can be used as they are; kinds without an item keep the suggested defaults.
	 *
	 * @param mixed $data Templates by kind, each a list of title, text and required
	 * @return array<string,array<int,array{title:string,text:string,required:bool}>>
	 */
	public static function normalize($data)
	{
		$templates = self::defaults();
		if (!is_array($data)) {
			return $templates;
		}
		foreach (VereineMeetingRules::KINDS as $kind) {
			if (!isset($data[$kind]) || !is_array($data[$kind])) {
				continue;
			}
			$items = array();
			foreach ($data[$kind] as $item) {
				$title = is_array($item) && isset($item['title']) && is_scalar($item['title']) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $item['title']))), 0, 255, 'UTF-8') : '';
				$text = is_array($item) && isset($item['text']) && is_scalar($item['text']) ? self::text($item['text']) : '';
				if ($title !== '' && count($items) < self::ITEMS_MAX) {
					$items[] = array('title' => $title, 'text' => $text, 'required' => !empty($item['required']));
				}
			}
			if ($items) {
				$templates[$kind] = $items;
			}
		}
		return $templates;
	}

	/**
	 * A text as it is stored: without tags and carriage returns, trimmed, at most TEXT_MAX characters.
	 *
	 * @param mixed $value Entered text
	 * @return string
	 */
	public static function text($value)
	{
		return is_scalar($value) ? mb_substr(trim(str_replace("\r", '', strip_tags((string) $value))), 0, self::TEXT_MAX, 'UTF-8') : '';
	}

	/**
	 * The titles of a template, to fill the agenda of a new meeting.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $templates Normalized templates
	 * @param string                                       $kind      Kind of the meeting
	 * @return string[]
	 */
	public static function agenda(array $templates, $kind)
	{
		$titles = array();
		foreach (isset($templates[$kind]) ? $templates[$kind] : array() as $item) {
			$titles[] = $item['title'];
		}
		return $titles;
	}

	/**
	 * The standard text of an agenda item, found by its title in the template of the kind.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $templates Normalized templates
	 * @param string                                       $kind      Kind of the meeting
	 * @param string                                       $title     Title of the agenda item
	 * @return string Empty when the template has no such item
	 */
	public static function textFor(array $templates, $kind, $title)
	{
		foreach (isset($templates[$kind]) ? $templates[$kind] : array() as $item) {
			if (self::same($item['title'], $title)) {
				return $item['text'];
			}
		}
		return '';
	}

	/**
	 * Required items of the template that the agenda lacks.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $templates Normalized templates
	 * @param string                                       $kind      Kind of the meeting
	 * @param string[]                                     $agenda    Titles of the agenda
	 * @return string[] Titles of the missing items
	 */
	public static function missing(array $templates, $kind, array $agenda)
	{
		$missing = array();
		foreach (isset($templates[$kind]) ? $templates[$kind] : array() as $item) {
			if (empty($item['required'])) {
				continue;
			}
			$found = false;
			foreach ($agenda as $title) {
				$found = $found || self::same($item['title'], $title);
			}
			if (!$found) {
				$missing[] = $item['title'];
			}
		}
		return $missing;
	}

	/**
	 * Where the texts of agenda items go when the agenda changes: an item keeps its text when its title is still there.
	 *
	 * @param string[] $old Titles before
	 * @param string[] $new Titles after
	 * @return array<int,int> New item number by old item number, both from 1; items without their title are left out
	 */
	public static function remap(array $old, array $new)
	{
		$map = array();
		$taken = array();
		foreach (array_values($old) as $index => $title) {
			foreach (array_values($new) as $newIndex => $newTitle) {
				if (!isset($taken[$newIndex]) && self::same($title, $newTitle)) {
					$map[$index + 1] = $newIndex + 1;
					$taken[$newIndex] = true;
					break;
				}
			}
		}
		return $map;
	}

	/**
	 * The real values of the placeholders for one agenda item.
	 *
	 * @param array<string,mixed>            $meeting Meeting with kind, day, place, format
	 * @param array<string,mixed>            $quorum  Quorum of VereineAttendanceRules::quorum() at the time of the item
	 * @param array<int,array<string,mixed>> $votes   Votes on this agenda item, with title, yes, no, abstain, passed, secret
	 * @param string                         $name    Name of the association
	 * @param string                         $day     Day of the meeting in words
	 * @return array<string,string>
	 */
	public static function values(array $meeting, array $quorum, array $votes, $name, $day)
	{
		$results = array();
		foreach ($votes as $vote) {
			$results[] = $vote['title'].': '.($vote['passed'] ? 'angenommen' : 'abgelehnt').' mit '.$vote['yes'].' Ja, '.$vote['no'].' Nein und '.$vote['abstain'].' Enthaltungen'
				.($vote['secret'] ? ' (geheime Abstimmung)' : '').'.';
		}
		return array(
			'verein' => (string) $name,
			'datum' => (string) $day,
			'ort' => $meeting['format'] === VereineMeetingRules::FORMAT_VIRTUAL ? 'virtuell' : (string) $meeting['place'],
			'anwesend' => (string) $quorum['present'],
			'vertreten' => (string) $quorum['represented'],
			'stimmen' => (string) $quorum['votes'],
			'stimmberechtigt' => (string) $quorum['eligible'],
			'quorum' => (string) max($meeting['kind'] === VereineMeetingRules::KIND_BOARD ? 1 : 0, (int) $quorum['required']),
			'beschlussfaehig' => $quorum['reached'] ? 'beschlussfähig' : 'nicht beschlussfähig',
			'ergebnis' => $results ? implode(' ', $results) : 'Keine Abstimmung.',
		);
	}

	/**
	 * A text with its placeholders replaced; unknown placeholders stay as they are.
	 *
	 * @param string               $text   Text with placeholders such as {anwesend}
	 * @param array<string,string> $values Values of values()
	 * @return string
	 */
	public static function fill($text, array $values)
	{
		return (string) preg_replace_callback('/\{([a-z]+)\}/', function ($match) use ($values) {
			return isset($values[$match[1]]) ? $values[$match[1]] : $match[0];
		}, (string) $text);
	}

	/**
	 * Whether two titles name the same item, regardless of case and spaces.
	 *
	 * @param string $one   Title
	 * @param string $other Title
	 * @return bool
	 */
	private static function same($one, $other)
	{
		$clean = function ($title) {
			return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $title)), 'UTF-8');
		};
		return $clean($one) !== '' && $clean($one) === $clean($other);
	}
}
