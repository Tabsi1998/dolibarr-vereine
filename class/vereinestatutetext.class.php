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
 * \file    class/vereinestatutetext.class.php
 * \ingroup vereine
 * \brief   The text of the statutes from their rules, plain PHP.
 *
 * The wording follows the model statutes of the Ministry of the Interior (April 2024) and, for
 * associations with tax privileges, those of the Ministry of Finance (Vereinsrichtlinien Rz 867,
 * version of the 2025 update). The statutes of an association are German, so is the text here.
 */

require_once __DIR__.'/vereinestatuterules.class.php';
require_once __DIR__.'/vereineexitrules.class.php';

/**
 * Builds the statutes paragraph by paragraph.
 */
class VereineStatuteText
{
	/** No tax privileges: the model of the Ministry of the Interior. */
	const TAX_NONE = 'none';
	/** Charitable, benevolent or church purposes (§§ 34 ff. BAO). */
	const TAX_BAO = 'bao';
	/** Donations are deductible (§ 4a EStG 1988). */
	const TAX_DONATION = 'donation';
	/** Wordings for the assets on dissolution, by tax privilege. */
	const ASSETS = array('none' => array('bmi'), 'bao' => array('base', 'a', 'b', 'c'), 'donation' => array('1', '2', '3', '4'));
	/** Wordings that need the purpose the assets go to ("ZZZ"). */
	const NEEDS_PURPOSE = array('bao:a', 'bao:b', 'donation:2', 'donation:3', 'donation:4');
	/** Wordings that need the recipient of the assets ("XY"). */
	const NEEDS_RECIPIENT = array('bao:b', 'bao:c', 'donation:3', 'donation:4');
	/** Activities the Ministry of Finance suggests. */
	const SUGGESTED_ACTIVITIES = array('Einrichtung einer Website und/oder sonstiger elektronischer Medien', 'Herausgabe von Publikationen', 'Versammlungen',
		'Diskussionsabende und Vorträge', 'Veranstaltungen');
	/** Sources of money the Ministry of Finance suggests. */
	const SUGGESTED_FUNDS = array('Beitrittsgebühren und Mitgliedsbeiträge', 'Subventionen und Förderungen', 'Spenden, Sammlungen, Vermächtnisse und sonstige Zuwendungen',
		'Vermögensverwaltung (zB Zinsen, sonstige Kapitaleinkünfte, Einnahmen aus Vermietung und Verpachtung)', 'Erträge aus Vereinsveranstaltungen', 'Sponsorgelder', 'Werbeeinnahmen');
	/** Longest list of activities or funds. */
	const LIST_MAX = 30;

	/** Nothing entered for the purpose (§ 3 (2) no. 3 VerG). */
	const PROBLEM_PURPOSE = 'purpose';
	/** No activities (§ 3 (2) no. 4 VerG). */
	const PROBLEM_ACTIVITIES = 'activities';
	/** No sources of money (§ 3 (2) no. 4 VerG). */
	const PROBLEM_FUNDS = 'funds';
	/** No board function in the catalogue. */
	const PROBLEM_BOARD = 'board';
	/** No term of office for the board (§ 3 (2) no. 8 VerG). */
	const PROBLEM_BOARD_TERM = 'board_term';
	/** Board functions with different terms of office; the statutes name one. */
	const PROBLEM_BOARD_TERMS_DIFFER = 'board_terms_differ';
	/** Fewer than two auditors or no term of office for them (§ 5 (5) VerG). */
	const PROBLEM_AUDITORS = 'auditors';
	/** The chosen wording on the assets needs a purpose or recipient that is missing. */
	const PROBLEM_ASSETS = 'assets';
	/** The association is marked non-profit but the statutes have no tax wording. */
	const PROBLEM_NONPROFIT = 'nonprofit';

	/**
	 * Text fields of a new association.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults()
	{
		return array(
			'area' => 'ganz Österreich',
			'branches' => false,
			'activities' => array(),
			'funds' => array('Beitrittsgebühren und Mitgliedsbeiträge'),
			'admission' => '',
			'legal_persons' => true,
			'arrears_months' => 6,
			'tax' => self::TAX_NONE,
			'asset' => 'bmi',
			'asset_purpose' => '',
			'asset_recipient' => '',
		);
	}

	/**
	 * Text fields that can be used as they are.
	 *
	 * @param mixed $data Stored or entered fields; lists may be arrays or text with one entry per line
	 * @return array<string,mixed>
	 */
	public static function normalize($data)
	{
		$text = self::defaults();
		if (!is_array($data)) {
			return $text;
		}
		foreach (array('area' => 255, 'admission' => 255, 'asset_purpose' => 1000, 'asset_recipient' => 500) as $key => $max) {
			if (isset($data[$key]) && is_scalar($data[$key])) {
				$text[$key] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $data[$key])), 0, $max, 'UTF-8');
			}
		}
		foreach (array('branches', 'legal_persons') as $key) {
			if (array_key_exists($key, $data)) {
				$text[$key] = !empty($data[$key]);
			}
		}
		foreach (array('activities', 'funds') as $key) {
			if (!isset($data[$key])) {
				continue;
			}
			$items = is_array($data[$key]) ? $data[$key] : preg_split('/\R/u', (string) $data[$key]);
			$list = array();
			foreach ($items as $item) {
				$item = is_scalar($item) ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $item)), 0, 255, 'UTF-8') : '';
				if ($item !== '' && !in_array($item, $list, true) && count($list) < self::LIST_MAX) {
					$list[] = $item;
				}
			}
			$text[$key] = $list;
		}
		if (isset($data['arrears_months']) && is_scalar($data['arrears_months']) && preg_match('/^\d{1,2}$/', trim((string) $data['arrears_months']))) {
			$text['arrears_months'] = (int) trim((string) $data['arrears_months']);
		}
		if (isset($data['tax']) && is_string($data['tax']) && isset(self::ASSETS[$data['tax']])) {
			$text['tax'] = $data['tax'];
		}
		$text['asset'] = isset($data['asset']) && in_array((string) $data['asset'], self::ASSETS[$text['tax']], true) ? (string) $data['asset'] : self::ASSETS[$text['tax']][0];
		return $text;
	}

	/**
	 * Problems of entered text fields before they are stored.
	 *
	 * @param array<string,mixed> $data Entered fields
	 * @return string[] Language keys, empty when fine
	 */
	public static function validate(array $data)
	{
		$errors = array();
		$months = isset($data['arrears_months']) && is_scalar($data['arrears_months']) ? trim((string) $data['arrears_months']) : '';
		if (!preg_match('/^\d{1,2}$/', $months) || (int) $months < 1 || (int) $months > 24) {
			$errors[] = 'VereineStatuteTextErrorArrears';
		}
		if (!isset($data['tax']) || !is_string($data['tax']) || !isset(self::ASSETS[$data['tax']])) {
			$errors[] = 'VereineStatuteTextErrorTax';
		} elseif (!isset($data['asset']) || !in_array((string) $data['asset'], self::ASSETS[$data['tax']], true)) {
			$errors[] = 'VereineStatuteTextErrorAsset';
		}
		return $errors;
	}

	/**
	 * What the statutes still lack or what does not fit, without stopping anything.
	 *
	 * @param array<string,mixed> $text    Normalized text fields
	 * @param array<string,mixed> $context Keys of context()
	 * @return string[] PROBLEM constants
	 */
	public static function problems(array $text, array $context)
	{
		$problems = array();
		if (trim((string) $context['purpose']) === '') {
			$problems[] = self::PROBLEM_PURPOSE;
		}
		if (!$text['activities']) {
			$problems[] = self::PROBLEM_ACTIVITIES;
		}
		if (!$text['funds']) {
			$problems[] = self::PROBLEM_FUNDS;
		}
		if (!$context['board']) {
			$problems[] = self::PROBLEM_BOARD;
		} elseif (count(array_unique($context['board_terms'])) > 1) {
			$problems[] = self::PROBLEM_BOARD_TERMS_DIFFER;
		} elseif ((int) reset($context['board_terms']) === 0) {
			$problems[] = self::PROBLEM_BOARD_TERM;
		}
		if ((int) $context['auditors'] < 2 || (int) $context['auditor_term'] === 0) {
			$problems[] = self::PROBLEM_AUDITORS;
		}
		$key = $text['tax'].':'.$text['asset'];
		if ((in_array($key, self::NEEDS_PURPOSE, true) && $text['asset_purpose'] === '') || (in_array($key, self::NEEDS_RECIPIENT, true) && $text['asset_recipient'] === '')) {
			$problems[] = self::PROBLEM_ASSETS;
		}
		if (!empty($context['nonprofit']) && $text['tax'] === self::TAX_NONE) {
			$problems[] = self::PROBLEM_NONPROFIT;
		}
		return $problems;
	}

	/**
	 * The statutes, paragraph by paragraph.
	 *
	 * @param array<string,mixed> $rules   Normalized rules of VereineStatuteRules
	 * @param array<string,mixed> $text    Normalized text fields
	 * @param array<string,mixed> $context Keys name, seat, purpose, board (labels), board_terms (years), chair, secretary, treasurer,
	 *                                     auditors (count), auditor_term, types (labels), voting (labels, empty for all), honorary (bool),
	 *                                     exit (rule of VereineExitRules), nonprofit
	 * @return array<int,array{number:int,title:string,paragraphs:string[]}>
	 */
	public static function sections(array $rules, array $text, array $context)
	{
		$blank = '__________';
		$tax = $text['tax'] !== self::TAX_NONE;
		$chair = $context['chair'] !== '' ? $context['chair'] : 'Obmann/Obfrau';
		$secretary = $context['secretary'] !== '' ? $context['secretary'] : 'Schriftführer:in';
		$treasurer = $context['treasurer'] !== '' ? $context['treasurer'] : 'Kassier:in';
		$sections = array();
		$add = function ($title, array $paragraphs) use (&$sections) {
			$numbered = array();
			foreach (array_values(array_filter($paragraphs, 'strlen')) as $index => $paragraph) {
				$numbered[] = count(array_filter($paragraphs, 'strlen')) > 1 ? '('.($index + 1).') '.$paragraph : $paragraph;
			}
			$sections[] = array('number' => count($sections) + 1, 'title' => $title, 'paragraphs' => $numbered);
		};
		$letters = function (array $items) {
			$lines = array();
			foreach (array_values($items) as $index => $item) {
				$lines[] = chr(97 + $index % 26).') '.$item;
			}
			return implode("\n", $lines);
		};

		$add('Name, Sitz und Tätigkeitsbereich', array(
			'Der Verein führt den Namen „'.$context['name'].'“.',
			'Er hat seinen Sitz in '.$context['seat'].' und erstreckt seine Tätigkeit auf '.($text['area'] !== '' ? $text['area'] : $blank).'.',
			'Die Errichtung von Zweigvereinen ist '.($text['branches'] ? '' : 'nicht ').'beabsichtigt.',
		));

		$purpose = trim((string) $context['purpose']);
		$add('Zweck', array('Der Verein, dessen Tätigkeit nicht auf Gewinn gerichtet ist, verfolgt folgenden Zweck: '.($purpose !== '' ? rtrim($purpose, '.').'.' : $blank)));

		$add('Mittel zur Erreichung des Vereinszwecks', array(
			$tax ? 'Der Vereinszweck soll durch die in den Abs. 2 und 3 angeführten Tätigkeiten und finanziellen Mittel erreicht werden.'
				: 'Der Vereinszweck soll durch die in den Abs. 2 und 3 angeführten ideellen und materiellen Mittel erreicht werden.',
			($tax ? 'Für die Verwirklichung des Vereinszweckes vorgesehene Tätigkeiten sind:' : 'Als ideelle Mittel dienen:')."\n".($text['activities'] ? $letters($text['activities']) : 'a) '.$blank),
			($tax ? 'Die erforderlichen finanziellen Mittel sollen aufgebracht werden durch:' : 'Die erforderlichen materiellen Mittel sollen aufgebracht werden durch:')."\n".($text['funds'] ? $letters($text['funds']) : 'a) '.$blank),
		));

		$types = $context['types'] ? self::join($context['types']) : $blank;
		$add('Arten der Mitgliedschaft', array('Die Mitglieder des Vereins gliedern sich in folgende Mitgliedsarten: '.$types.'.'));

		$conditions = array();
		if ((int) $rules['min_age'] > 0) {
			$conditions[] = 'das '.((int) $rules['min_age']).'. Lebensjahr vollendet haben';
		}
		if ($text['admission'] !== '') {
			$conditions[] = rtrim($text['admission'], '.');
		}
		$add('Erwerb der Mitgliedschaft', array(
			'Mitglieder des Vereins können alle physischen Personen'.($conditions ? ', die '.implode(' und ', $conditions).',' : '')
				.($text['legal_persons'] ? ' sowie juristische Personen und rechtsfähige Personengesellschaften' : '').' werden.',
			'Über die Aufnahme von Mitgliedern entscheidet der Vorstand. Die Aufnahme kann ohne Angabe von Gründen verweigert werden.',
			'Bis zur Entstehung des Vereins erfolgt die vorläufige Aufnahme von Mitgliedern durch die Vereinsgründer, im Fall eines bereits bestellten Vorstands durch diesen. Diese Mitgliedschaft wird erst mit Entstehung des Vereins wirksam. Wird ein Vorstand erst nach Entstehung des Vereins bestellt, erfolgt auch die (definitive) Aufnahme von Mitgliedern bis dahin durch die Gründer des Vereins.',
			$context['honorary'] ? 'Die Ernennung zum Ehrenmitglied erfolgt auf Antrag des Vorstands durch die Generalversammlung.' : '',
		));

		$add('Beendigung der Mitgliedschaft', array(
			'Die Mitgliedschaft erlischt durch Tod, bei juristischen Personen und rechtsfähigen Personengesellschaften durch Verlust der Rechtspersönlichkeit, durch freiwilligen Austritt und durch Ausschluss.',
			self::exitSentence($context['exit']),
			'Der Vorstand kann ein Mitglied ausschließen, wenn dieses trotz zweimaliger schriftlicher Mahnung unter Setzung einer angemessenen Nachfrist länger als '
				.self::months((int) $text['arrears_months']).' mit der Zahlung der Mitgliedsbeiträge im Rückstand ist. Die Verpflichtung zur Zahlung der fällig gewordenen Mitgliedsbeiträge bleibt hiervon unberührt.',
			'Der Ausschluss eines Mitglieds aus dem Verein kann vom Vorstand auch wegen grober Verletzung anderer Mitgliedspflichten und wegen unehrenhaften Verhaltens verfügt werden.',
			$context['honorary'] ? 'Die Aberkennung der Ehrenmitgliedschaft kann aus den im Abs. 4 genannten Gründen von der Generalversammlung über Antrag des Vorstands beschlossen werden.' : '',
		));

		$voting = $context['voting'] ? 'Das Stimmrecht in der Generalversammlung sowie das aktive und passive Wahlrecht steht nur Mitgliedern folgender Mitgliedsarten zu: '.self::join($context['voting']).'.'
			: 'Das Stimmrecht in der Generalversammlung sowie das aktive und passive Wahlrecht steht allen Mitgliedern zu.';
		$add('Rechte und Pflichten der Mitglieder', array(
			'Die Mitglieder sind berechtigt, an allen Veranstaltungen des Vereins teilzunehmen und die Einrichtungen des Vereins zu beanspruchen. '.$voting,
			'Jedes Mitglied ist berechtigt, vom Vorstand die Ausfolgung der Statuten zu verlangen.',
			'Mindestens ein Zehntel der Mitglieder kann vom Vorstand die Einberufung einer Generalversammlung verlangen.',
			'Die Mitglieder sind in jeder Generalversammlung vom Vorstand über die Tätigkeit und finanzielle Gebarung des Vereins zu informieren. Wenn mindestens ein Zehntel der Mitglieder dies unter Angabe von Gründen verlangt, hat der Vorstand den betreffenden Mitgliedern eine solche Information auch sonst binnen vier Wochen zu geben.',
			'Die Mitglieder sind vom Vorstand über den geprüften Rechnungsabschluss (Rechnungslegung) zu informieren. Geschieht dies in der Generalversammlung, sind die Rechnungsprüfer einzubinden.',
			'Die Mitglieder sind verpflichtet, die Interessen des Vereins nach Kräften zu fördern und alles zu unterlassen, wodurch das Ansehen und der Zweck des Vereins Abbruch erleiden könnte. Sie haben die Vereinsstatuten und die Beschlüsse der Vereinsorgane zu beachten. Die Mitglieder sind zur pünktlichen Zahlung der Beitrittsgebühr und der Mitgliedsbeiträge in der von der Generalversammlung für ihre Mitgliedsart beschlossenen Höhe verpflichtet.',
		));

		$add('Vereinsorgane', array('Organe des Vereins sind die Generalversammlung (§§ 9 und 10), der Vorstand (§§ 11 bis 13), die Rechnungsprüfer (§ 14) und das Schiedsgericht (§ 15).'));

		$years = (int) $rules['general_years'];
		$channels = array('letter' => 'schriftlich per Brief', 'email' => 'per E-Mail an die vom Mitglied dem Verein bekanntgegebene E-Mail-Adresse',
			'website' => 'durch Veröffentlichung auf der Website des Vereins');
		$invite = array();
		foreach ($rules['invite_channels'] as $channel) {
			$invite[] = $channels[$channel];
		}
		$general = array(
			'Die Generalversammlung ist die „Mitgliederversammlung“ im Sinne des Vereinsgesetzes 2002. Eine ordentliche Generalversammlung findet '
				.($years === 1 ? 'jährlich' : 'alle '.self::number($years).' Jahre').' statt.',
			'Eine außerordentliche Generalversammlung findet auf a. Beschluss des Vorstands oder der ordentlichen Generalversammlung, b. schriftlichen Antrag von mindestens einem Zehntel der Mitglieder, c. Verlangen der Rechnungsprüfer (§ 21 Abs. 5 erster Satz VereinsG), d. Beschluss der/eines Rechnungsprüfer/s (§ 21 Abs. 5 zweiter Satz VereinsG, § 11 Abs. 2 dritter Satz dieser Statuten), e. Beschluss eines gerichtlich bestellten Kurators (§ 11 Abs. 2 letzter Satz dieser Statuten) binnen vier Wochen statt.',
			'Sowohl zu den ordentlichen wie auch zu den außerordentlichen Generalversammlungen sind alle Mitglieder mindestens '.self::days((int) $rules['invite_days'])
				.' vor dem Termin '.self::join($invite, 'oder').' einzuladen. Die Anberaumung der Generalversammlung hat unter Angabe der Tagesordnung zu erfolgen. Die Einberufung erfolgt durch den Vorstand (Abs. 1 und Abs. 2 lit. a – c), durch die/einen Rechnungsprüfer (Abs. 2 lit. d) oder durch einen gerichtlich bestellten Kurator (Abs. 2 lit. e).',
			(int) $rules['motion_days'] > 0 ? 'Anträge zur Generalversammlung sind mindestens '.self::days((int) $rules['motion_days']).' vor dem Termin der Generalversammlung beim Vorstand schriftlich oder per E-Mail einzureichen.' : 'Anträge zur Generalversammlung können bis zu ihrem Beginn beim Vorstand schriftlich oder per E-Mail eingereicht werden.',
			'Gültige Beschlüsse – ausgenommen solche über einen Antrag auf Einberufung einer außerordentlichen Generalversammlung – können nur zur Tagesordnung gefasst werden.',
			'Bei der Generalversammlung sind alle Mitglieder teilnahmeberechtigt. '.($context['voting'] ? 'Stimmberechtigt sind nur Mitglieder folgender Mitgliedsarten: '.self::join($context['voting']).'.'
				: 'Stimmberechtigt sind alle Mitglieder.').' Jedes Mitglied hat eine Stimme.'.($text['legal_persons'] ? ' Juristische Personen werden durch eine(n) Bevollmächtigte(n) vertreten.' : '')
				.($rules['proxy'] ? ' Die Übertragung des Stimmrechts auf ein anderes Mitglied im Wege einer schriftlichen Bevollmächtigung ist zulässig.' : ''),
			(int) $rules['general_quorum'] === 0 ? 'Die Generalversammlung ist ohne Rücksicht auf die Anzahl der Erschienenen beschlussfähig.'
				: 'Die Generalversammlung ist beschlussfähig, wenn mindestens '.((int) $rules['general_quorum']).' % der Stimmberechtigten anwesend oder vertreten sind.',
			self::majoritySentence($rules),
			'Den Vorsitz in der Generalversammlung führt '.$chair.', bei Verhinderung die Stellvertretung. Wenn auch diese verhindert ist, so führt das an Jahren älteste anwesende Vorstandsmitglied den Vorsitz.',
		);
		$virtual = array(
			VereineStatuteRules::VIRTUAL_CONVENER => 'Die Generalversammlung kann nach Maßgabe des Virtuelle Gesellschafterversammlungen-Gesetzes (VirtGesG) auch ohne physische Anwesenheit der Teilnehmer:innen als virtuelle Versammlung durchgeführt werden. Ob sie physisch, virtuell oder so stattfindet, dass die Mitglieder zwischen physischer und virtueller Teilnahme wählen können, entscheidet, wer sie einberuft.',
			VereineStatuteRules::VIRTUAL_ALWAYS => 'Die Generalversammlung wird nach Maßgabe des Virtuelle Gesellschafterversammlungen-Gesetzes (VirtGesG) stets als virtuelle Versammlung ohne physische Anwesenheit der Teilnehmer:innen durchgeführt.',
			VereineStatuteRules::VIRTUAL_HYBRID => 'Die Generalversammlung wird nach Maßgabe des Virtuelle Gesellschafterversammlungen-Gesetzes (VirtGesG) stets so durchgeführt, dass die Mitglieder zwischen physischer und virtueller Teilnahme wählen können.',
		);
		if (isset($virtual[$rules['virtual']])) {
			$general[] = $virtual[$rules['virtual']].' Die Einladung gibt an, welche organisatorischen und technischen Voraussetzungen für die Teilnahme bestehen.';
		}
		$add('Generalversammlung', $general);

		$add('Aufgaben der Generalversammlung', array('Der Generalversammlung sind folgende Aufgaben vorbehalten:'."\n".$letters(array_filter(array(
			'Beschlussfassung über den Voranschlag;',
			'Entgegennahme und Genehmigung des Rechenschaftsberichts und des Rechnungsabschlusses unter Einbindung der Rechnungsprüfer;',
			'Wahl und Enthebung der Mitglieder des Vorstands und der Rechnungsprüfer;',
			'Genehmigung von Rechtsgeschäften zwischen Rechnungsprüfern und Verein;',
			'Entlastung des Vorstands;',
			'Festsetzung der Höhe der Beitrittsgebühr und der Mitgliedsbeiträge;',
			$context['honorary'] ? 'Verleihung und Aberkennung der Ehrenmitgliedschaft;' : '',
			'Beschlussfassung über Statutenänderungen und die freiwillige Auflösung des Vereins;',
			'Beratung und Beschlussfassung über sonstige auf der Tagesordnung stehende Fragen.',
		), 'strlen'))));

		$boardTerm = $context['board_terms'] ? (int) max($context['board_terms']) : 0;
		$quorum = (int) $rules['board_quorum'] === 50 ? 'die Hälfte' : ((int) $rules['board_quorum']).' %';
		// A circular resolution is possible only where the statutes say so: the Associations Act does not
		// provide for it, and the model statutes of the Ministry of the Interior do not know it either.
		$circular = '';
		if (!empty($rules['circular'])) {
			$circular = 'Beschlüsse des Vorstands können auch außerhalb einer Sitzung im Umlaufweg gefasst werden, sofern jedes Vorstandsmitglied Gelegenheit zur Stimmabgabe erhält. ';
			$circular .= !empty($rules['circular_no_objection'])
				? 'Ein solcher Umlaufbeschluss kommt nur zustande, wenn kein Vorstandsmitglied dem Umlaufverfahren widerspricht; im Übrigen gelten die Mehrheitserfordernisse des Abs. 6.'
				: 'Für die Beschlussfassung im Umlaufweg gelten die Mehrheitserfordernisse des Abs. 6.';
		}
		$add('Vorstand', array_values(array_filter(array(
			'Der Vorstand besteht aus '.($context['board'] ? self::number(count($context['board'])).' Mitgliedern, und zwar aus: '.self::join($context['board']) : $blank).'.',
			'Der Vorstand wird von der Generalversammlung gewählt. Der Vorstand hat bei Ausscheiden eines gewählten Mitglieds das Recht, an seine Stelle ein anderes wählbares Mitglied zu kooptieren, wozu die nachträgliche Genehmigung in der nächstfolgenden Generalversammlung einzuholen ist. Fällt der Vorstand ohne Selbstergänzung durch Kooptierung überhaupt oder auf unvorhersehbar lange Zeit aus, so ist jeder Rechnungsprüfer verpflichtet, unverzüglich eine außerordentliche Generalversammlung zum Zweck der Neuwahl eines Vorstands einzuberufen. Sollten auch die Rechnungsprüfer handlungsunfähig sein, hat jedes Mitglied, das die Notsituation erkennt, unverzüglich die Bestellung eines Kurators beim zuständigen Gericht zu beantragen, der umgehend eine außerordentliche Generalversammlung einzuberufen hat.',
			'Die Funktionsperiode des Vorstands beträgt '.($boardTerm > 0 ? self::years($boardTerm) : $blank.' Jahre').'. Erfolgt die Neuwahl nicht rechtzeitig vor ihrem Ablauf, so läuft sie bis zur Wahl eines neuen Vorstands weiter. Eine Wiederwahl ist möglich. Jede Funktion im Vorstand ist persönlich auszuüben.',
			'Der Vorstand wird von '.$chair.', bei Verhinderung von der Stellvertretung, schriftlich oder mündlich einberufen. Ist auch diese auf unvorhersehbar lange Zeit verhindert, darf jedes sonstige Vorstandsmitglied den Vorstand einberufen.',
			'Der Vorstand ist beschlussfähig, wenn alle seine Mitglieder eingeladen wurden und mindestens '.$quorum.' von ihnen anwesend ist.',
			'Der Vorstand fasst seine Beschlüsse mit einfacher Stimmenmehrheit'.($rules['board_tie_chair'] ? '; bei Stimmengleichheit gibt die Stimme der/des Vorsitzenden den Ausschlag.' : '; bei Stimmengleichheit gilt ein Antrag als abgelehnt.'),
			$circular,
			'Den Vorsitz führt '.$chair.', bei Verhinderung die Stellvertretung. Ist auch diese verhindert, obliegt der Vorsitz dem an Jahren ältesten anwesenden Vorstandsmitglied oder jenem Vorstandsmitglied, das die übrigen Vorstandsmitglieder mehrheitlich dazu bestimmen.',
			'Außer durch den Tod und Ablauf der Funktionsperiode (Abs. 3) erlischt die Funktion eines Vorstandsmitglieds durch Enthebung (Abs. 9) und Rücktritt (Abs. 10).',
			'Die Generalversammlung kann jederzeit den gesamten Vorstand oder einzelne seiner Mitglieder entheben. Die Enthebung tritt mit Bestellung des neuen Vorstands bzw. Vorstandsmitglieds in Kraft.',
			'Die Vorstandsmitglieder können jederzeit schriftlich ihren Rücktritt erklären. Die Rücktrittserklärung ist an den Vorstand, im Falle des Rücktritts des gesamten Vorstands an die Generalversammlung zu richten. Der Rücktritt wird erst mit Wahl bzw. Kooptierung (Abs. 2) eines Nachfolgers wirksam.',
		), 'strlen')));

		$tasks = array(
			'Einrichtung eines den Anforderungen des Vereins entsprechenden Rechnungswesens mit laufender Aufzeichnung der Einnahmen/Ausgaben und Führung eines Vermögensverzeichnisses als Mindesterfordernis;',
			'Erstellung des Jahresvoranschlags, des Rechenschaftsberichts und des Rechnungsabschlusses;',
			'Vorbereitung und Einberufung der Generalversammlung in den Fällen des § 9 Abs. 1 und Abs. 2 lit. a – c dieser Statuten;',
			'Information der Vereinsmitglieder über die Vereinstätigkeit, die Vereinsgebarung und den geprüften Rechnungsabschluss;',
			'Verwaltung des Vereinsvermögens;',
			'Aufnahme und Ausschluss von Vereinsmitgliedern;',
			'Aufnahme und Kündigung von Angestellten des Vereins.',
		);
		$add('Aufgaben des Vorstands', array('Dem Vorstand obliegt die Leitung des Vereins. Er ist das „Leitungsorgan“ im Sinne des Vereinsgesetzes 2002. Ihm kommen alle Aufgaben zu, die nicht durch die Statuten einem anderen Vereinsorgan zugewiesen sind. In seinen Wirkungsbereich fallen insbesondere folgende Angelegenheiten:'."\n".$letters($tasks)));

		$add('Besondere Obliegenheiten einzelner Vorstandsmitglieder', array(
			$chair.' führt die laufenden Geschäfte des Vereins. '.$secretary.' unterstützt dabei.',
			$chair.' vertritt den Verein nach außen. Schriftliche Ausfertigungen des Vereins bedürfen zu ihrer Gültigkeit der Unterschriften von '.$chair.' und '.$secretary
				.', in Geldangelegenheiten (vermögenswerte Dispositionen) von '.$chair.' und '.$treasurer.'. Rechtsgeschäfte zwischen Vorstandsmitgliedern und Verein bedürfen der Zustimmung eines anderen Vorstandsmitglieds.',
			'Rechtsgeschäftliche Bevollmächtigungen, den Verein nach außen zu vertreten bzw. für ihn zu zeichnen, können ausschließlich von den in Abs. 2 genannten Vorstandsmitgliedern erteilt werden.',
			'Bei Gefahr im Verzug ist '.$chair.' berechtigt, auch in Angelegenheiten, die in den Wirkungsbereich der Generalversammlung oder des Vorstands fallen, unter eigener Verantwortung selbständig Anordnungen zu treffen; im Innenverhältnis bedürfen diese jedoch der nachträglichen Genehmigung durch das zuständige Vereinsorgan.',
			$chair.' führt den Vorsitz in der Generalversammlung und im Vorstand.',
			$secretary.' führt die Protokolle der Generalversammlung und des Vorstands.',
			$treasurer.' ist für die ordnungsgemäße Geldgebarung des Vereins verantwortlich.',
			'Im Fall der Verhinderung treten an die Stelle von '.$chair.', '.$secretary.' oder '.$treasurer.' ihre Stellvertretungen.',
		));

		$auditors = max(2, (int) $context['auditors']);
		$add('Rechnungsprüfer', array(
			ucfirst(self::number($auditors)).' Rechnungsprüfer werden von der Generalversammlung auf die Dauer von '.((int) $context['auditor_term'] > 0
				? ((int) $context['auditor_term'] === 1 ? 'einem Jahr' : self::number((int) $context['auditor_term']).' Jahren') : $blank.' Jahren')
				.' gewählt. Wiederwahl ist möglich. Die Rechnungsprüfer dürfen keinem Organ – mit Ausnahme der Generalversammlung – angehören, dessen Tätigkeit Gegenstand der Prüfung ist.',
			'Den Rechnungsprüfern obliegt die laufende Geschäftskontrolle sowie die Prüfung der Finanzgebarung des Vereins im Hinblick auf die Ordnungsmäßigkeit der Rechnungslegung und die statutengemäße Verwendung der Mittel. Der Vorstand hat den Rechnungsprüfern die erforderlichen Unterlagen vorzulegen und die erforderlichen Auskünfte zu erteilen. Die Rechnungsprüfer haben dem Vorstand über das Ergebnis der Prüfung zu berichten.',
			'Rechtsgeschäfte zwischen Rechnungsprüfern und Verein bedürfen der Genehmigung durch die Generalversammlung. Im Übrigen gelten für die Rechnungsprüfer die Bestimmungen des § 11 Abs. 8 bis 10 sinngemäß.',
		));

		$add('Schiedsgericht', array(
			'Zur Schlichtung von allen aus dem Vereinsverhältnis entstehenden Streitigkeiten ist das vereinsinterne Schiedsgericht berufen. Es ist eine „Schlichtungseinrichtung“ im Sinne des Vereinsgesetzes 2002 und kein Schiedsgericht nach den §§ 577 ff ZPO.',
			'Das Schiedsgericht setzt sich aus drei Vereinsmitgliedern zusammen. Es wird derart gebildet, dass ein Streitteil dem Vorstand ein Mitglied als Schiedsrichter schriftlich namhaft macht. Über Aufforderung durch den Vorstand binnen sieben Tagen macht der andere Streitteil innerhalb von 14 Tagen seinerseits ein Mitglied des Schiedsgerichts namhaft. Nach Verständigung durch den Vorstand innerhalb von sieben Tagen wählen die namhaft gemachten Schiedsrichter binnen weiterer 14 Tage ein drittes Mitglied zur/zum Vorsitzenden des Schiedsgerichts. Bei Stimmengleichheit entscheidet unter den Vorgeschlagenen das Los. Die Mitglieder des Schiedsgerichts dürfen keinem Organ – mit Ausnahme der Generalversammlung – angehören, dessen Tätigkeit Gegenstand der Streitigkeit ist.',
			'Das Schiedsgericht fällt seine Entscheidung nach Gewährung beiderseitigen Gehörs bei Anwesenheit aller seiner Mitglieder mit einfacher Stimmenmehrheit. Es entscheidet nach bestem Wissen und Gewissen. Seine Entscheidungen sind vereinsintern endgültig.',
		));

		$dissolution = array('Die freiwillige Auflösung des Vereins kann nur in einer Generalversammlung und nur mit '.self::majorityName($rules['dissolution_majority'])
			.' der abgegebenen gültigen Stimmen beschlossen werden.');
		if ($tax) {
			$dissolution[] = 'Die Generalversammlung hat – sofern Vereinsvermögen vorhanden ist – über die Abwicklung zu beschließen. Insbesondere hat sie eine Abwicklerin oder einen Abwickler zu berufen und Beschluss darüber zu fassen, wem diese(r) das nach Abdeckung der Passiva verbleibende Vereinsvermögen zu übertragen hat.';
			$dissolution[] = 'Der letzte Vereinsvorstand hat die freiwillige Auflösung binnen vier Wochen nach Beschlussfassung der zuständigen Vereinsbehörde schriftlich anzuzeigen.';
		} else {
			$dissolution[] = 'Diese Generalversammlung hat auch – sofern Vereinsvermögen vorhanden ist – über die Abwicklung zu beschließen. Insbesondere hat sie einen Abwickler zu berufen und Beschluss darüber zu fassen, wem dieser das nach Abdeckung der Passiven verbleibende Vereinsvermögen zu übertragen hat. Dieses Vermögen soll, soweit dies möglich und erlaubt ist, einer Organisation zufallen, die gleiche oder ähnliche Zwecke wie dieser Verein verfolgt, sonst Zwecken der Sozialhilfe.';
		}
		$add('Freiwillige Auflösung des Vereins', $dissolution);

		if ($tax) {
			$add('Verwendung des Vereinsvermögens bei Auflösung des Vereins oder bei Wegfall des begünstigten Zwecks', array(self::assetText($text)));
		}
		return $sections;
	}

	/**
	 * The sections that differ between two versions of the statutes, matched by their title.
	 *
	 * @param array<int,array{number:int,title:string,paragraphs:string[]}> $old Sections in force
	 * @param array<int,array{number:int,title:string,paragraphs:string[]}> $new Sections as they would be now
	 * @return array<int,array{title:string,old_number:int,new_number:int,old:string[],new:string[]}> In the order of the new statutes, removed sections last
	 */
	public static function compare(array $old, array $new)
	{
		$oldByTitle = array();
		foreach ($old as $section) {
			$oldByTitle[$section['title']] = $section;
		}
		$changes = array();
		foreach ($new as $section) {
			$before = isset($oldByTitle[$section['title']]) ? $oldByTitle[$section['title']] : null;
			unset($oldByTitle[$section['title']]);
			if ($before !== null && $before['paragraphs'] === $section['paragraphs']) {
				continue;
			}
			$changes[] = array('title' => $section['title'], 'old_number' => $before !== null ? $before['number'] : 0, 'new_number' => $section['number'],
				'old' => $before !== null ? $before['paragraphs'] : array(), 'new' => $section['paragraphs']);
		}
		foreach ($oldByTitle as $section) {
			$changes[] = array('title' => $section['title'], 'old_number' => $section['number'], 'new_number' => 0, 'old' => $section['paragraphs'], 'new' => array());
		}
		return $changes;
	}

	/**
	 * The wording on the assets of an association with tax privileges (Vereinsrichtlinien Rz 867, § 17).
	 *
	 * @param array<string,mixed> $text Normalized text fields
	 * @return string
	 */
	public static function assetText(array $text)
	{
		$purpose = '„'.($text['asset_purpose'] !== '' ? $text['asset_purpose'] : '__________').'“';
		$recipient = '„'.($text['asset_recipient'] !== '' ? $text['asset_recipient'] : '__________').'“';
		$similar = ' Soweit möglich und erlaubt, soll es dabei Institutionen zufallen, die gleiche oder ähnliche Zwecke wie dieser Verein verfolgen.';
		$baoStart = 'Bei Auflösung des Vereins oder bei Wegfall des bisherigen begünstigten Vereinszwecks ist das nach Abdeckung der Passiva verbleibende Vereinsvermögen';
		$baoFallback = ' ist das verbleibende Vereinsvermögen anderen gemeinnützigen, mildtätigen oder kirchlichen Zwecken gemäß den §§ 34 ff BAO zuzuführen.'.$similar;
		$recipientFailing = ' Sollte '.$recipient.' im Zeitpunkt der durch die Auflösung des Vereins oder den Wegfall des bisherigen begünstigten Vereinszwecks nötigen Vermögensabwicklung nicht mehr existieren, nicht mehr die Voraussetzungen der Steuerbegünstigung gemäß den §§ 34 ff BAO erfüllen, oder aus sonstigen Gründen die Übergabe des Vermögens nicht im Sinne obiger Ausführungen möglich sein,'.$baoFallback;
		$recipientCheck = ' Zu diesem Zweck ist das verbleibende Vereinsvermögen an '.$recipient.' zu übergeben, wenn dieser die Voraussetzungen für die Zuerkennung von steuerlichen Begünstigung gemäß den §§ 34 ff BAO erfüllt, was er durch die Vorlage einer aktuellen Bestätigung des dafür zuständigen Finanzamtes nachzuweisen hat.';
		$donationStart = 'Bei Auflösung der Körperschaft oder bei Wegfall ihres bisherigen begünstigten Zwecks ist das nach Abdeckung der Passiva verbleibende Vermögen der Körperschaft';
		$donationPurposes = ' jedenfalls für die in dieser Rechtsgrundlage angeführten, gemäß § 4a Abs. 2 EStG 1988 begünstigten Zwecke zu verwenden.';
		$donationFailing = ' im Zeitpunkt der durch die Auflösung der Körperschaft oder den Wegfall ihres bisherigen begünstigten Zwecks nötigen Vermögensabwicklung nicht mehr existieren,';
		switch ($text['tax'].':'.$text['asset']) {
			case 'bao:a':
				return $baoStart.', jedenfalls gemeinnützigen, mildtätigen oder kirchlichen Zwecken im Sinne der §§ 34 ff Bundesabgabenordnung (BAO) zuzuführen. Daher ist das verbleibende Vereinsvermögen für den Zweck '.$purpose.' zu verwenden. Sollte das im Zeitpunkt der durch die Auflösung des Vereins oder den Wegfall des bisherigen begünstigten Vereinszwecks nötigen Vermögensabwicklung nicht möglich sein,'.$baoFallback;
			case 'bao:b':
				return $baoStart.', jedenfalls für gemeinnützige, mildtätige oder kirchliche Zwecke im Sinne der §§ 34 ff Bundesabgabenordnung (BAO) zu verwenden.'.$recipientCheck
					.' Das verbleibende Vereinsvermögen ist mit der zwingenden Auflage der ausschließlichen Verwendung für den Zweck '.$purpose.' zu übergeben.'.$recipientFailing;
			case 'bao:c':
				return $baoStart.', jedenfalls für gemeinnützige, mildtätige oder kirchliche Zwecke im Sinne der §§ 34 ff Bundesabgabenordnung (BAO) zu verwenden.'.$recipientCheck.$recipientFailing;
			case 'donation:1':
				return $donationStart.' für die in dieser Rechtsgrundlage angeführten, gemäß § 4a Abs. 2 EStG 1988 begünstigten Zwecke zu verwenden.';
			case 'donation:2':
				return $donationStart.$donationPurposes.' Daher ist das verbleibende Vermögen der Körperschaft für den Zweck '.$purpose.' zu verwenden. Sollte das im Zeitpunkt der durch die Auflösung der Körperschaft oder den Wegfall ihres bisherigen begünstigten Zwecks nötigen Vermögensabwicklung nicht möglich sein, ist das verbleibende Vermögen der Körperschaft den selben begünstigten Zwecken gemäß § 4a Abs. 2 EStG 1988, wie sie diese Körperschaft verfolgt, zuzuführen.';
			case 'donation:3':
				return $donationStart.$donationPurposes.' Daher ist das verbleibende Vermögen der Körperschaft an die Körperschaft '.$recipient.' mit der zwingenden Auflage der ausschließlichen Verwendung für den in der Rechtsgrundlage angeführten begünstigten Zweck '.$purpose.' zu übergeben, wenn '.$recipient.' die Voraussetzungen für die Zuerkennung von steuerlichen Begünstigungen gemäß den §§ 34 – 47 BAO erfüllt. Sollte '.$recipient.$donationFailing.' nicht mehr die Voraussetzungen der Steuerbegünstigung gemäß §§ 34 – 47 BAO erfüllen, oder aus sonstigen Gründen die Übergabe des Vermögens nicht im Sinne obiger Ausführungen möglich sein, muss das verbleibende Vermögen der Körperschaft anderen Körperschaften zufallen, die die genannten Voraussetzungen erfüllen.';
			case 'donation:4':
				return $donationStart.$donationPurposes.' Daher ist das verbleibende Vermögen der Körperschaft an die Körperschaft '.$recipient.' mit der zwingenden Auflage der ausschließlichen Verwendung für den Zweck '.$purpose.' zu übergeben, wenn dieser zum Zeitpunkt der Vermögensübergabe die Begünstigung gemäß § 4a Abs. 1 EStG 1988 zukommt. Sollte '.$recipient.$donationFailing.' ihr die Begünstigung gemäß § 4a EStG 1988 nicht mehr zukommen, oder aus sonstigen Gründen die Übergabe des Vermögens nicht im Sinne obiger Ausführungen möglich sein, muss das verbleibende Vermögen der Körperschaft anderen Körperschaften zufallen, die die genannten Voraussetzungen erfüllen.';
			default:
				return $baoStart.', für gemeinnützige, mildtätige oder kirchliche Zwecke im Sinne der §§ 34 ff Bundesabgabenordnung (BAO) zu verwenden.'.$similar;
		}
	}

	/**
	 * When a member can leave, as the statutes say it.
	 *
	 * @param array<string,mixed> $exit Rule of VereineExitRules
	 * @return string
	 */
	public static function exitSentence(array $exit)
	{
		$months = (int) $exit['months'];
		$notice = $months > 0 ? ' Er muss dem Vorstand mindestens '.self::months($months).' vorher schriftlich mitgeteilt werden.' : ' Er muss dem Vorstand schriftlich mitgeteilt werden.';
		$monthNames = array(1 => 'Jänner', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober',
			11 => 'November', 12 => 'Dezember');
		$yearStart = (int) $exit['start_month'] === 1 ? '' : ' (das Vereinsjahr beginnt am 1. '.$monthNames[(int) $exit['start_month']].')';
		switch ($exit['at']) {
			case VereineExitRules::AT_MONTH_END:
				$at = 'Der Austritt kann nur zum Ende eines Monats erfolgen.';
				break;
			case VereineExitRules::AT_QUARTER_END:
				$at = 'Der Austritt kann nur zum Ende eines Quartals des Vereinsjahres'.$yearStart.' erfolgen.';
				break;
			case VereineExitRules::AT_YEAR_END:
				$at = (int) $exit['start_month'] === 1 ? 'Der Austritt kann nur zum 31. Dezember jeden Jahres erfolgen.' : 'Der Austritt kann nur zum Ende des Vereinsjahres'.$yearStart.' erfolgen.';
				break;
			default:
				return 'Der Austritt kann jederzeit erfolgen.'.$notice;
		}
		return $at.$notice.' Erfolgt die Anzeige verspätet, so ist sie erst zum nächsten Austrittstermin wirksam. Für die Rechtzeitigkeit ist das Datum der Postaufgabe oder des Einlangens der E-Mail maßgeblich.';
	}

	/**
	 * The sentence on majorities in the general assembly.
	 *
	 * @param array<string,mixed> $rules Normalized rules
	 * @return string
	 */
	private static function majoritySentence(array $rules)
	{
		$sentence = 'Die Wahlen und die Beschlussfassungen in der Generalversammlung erfolgen in der Regel mit einfacher Mehrheit der abgegebenen gültigen Stimmen.';
		$statutes = $rules['statute_majority'];
		$dissolution = $rules['dissolution_majority'];
		if ($statutes === $dissolution && $statutes !== VereineStatuteRules::MAJORITY_SIMPLE) {
			return $sentence.' Beschlüsse, mit denen das Statut des Vereins geändert oder der Verein aufgelöst werden soll, bedürfen jedoch einer '.self::majorityName($statutes)
				.' der abgegebenen gültigen Stimmen.';
		}
		if ($statutes !== VereineStatuteRules::MAJORITY_SIMPLE) {
			$sentence .= ' Beschlüsse, mit denen das Statut des Vereins geändert werden soll, bedürfen jedoch einer '.self::majorityName($statutes).' der abgegebenen gültigen Stimmen.';
		}
		if ($dissolution !== VereineStatuteRules::MAJORITY_SIMPLE) {
			$sentence .= ' Beschlüsse, mit denen der Verein aufgelöst werden soll, bedürfen einer '.self::majorityName($dissolution).' der abgegebenen gültigen Stimmen.';
		}
		return $sentence;
	}

	/**
	 * Name of a majority.
	 *
	 * @param string $majority One of the MAJORITY constants
	 * @return string
	 */
	private static function majorityName($majority)
	{
		$names = array(VereineStatuteRules::MAJORITY_SIMPLE => 'einfachen Mehrheit', VereineStatuteRules::MAJORITY_TWO_THIRDS => 'Zweidrittelmehrheit',
			VereineStatuteRules::MAJORITY_THREE_QUARTERS => 'Dreiviertelmehrheit');
		return isset($names[$majority]) ? $names[$majority] : $names[VereineStatuteRules::MAJORITY_TWO_THIRDS];
	}

	/**
	 * A small number in words.
	 *
	 * @param int $number Number
	 * @return string
	 */
	public static function number($number)
	{
		$words = array(1 => 'ein', 2 => 'zwei', 3 => 'drei', 4 => 'vier', 5 => 'fünf', 6 => 'sechs', 7 => 'sieben', 8 => 'acht', 9 => 'neun', 10 => 'zehn', 11 => 'elf', 12 => 'zwölf');
		return isset($words[$number]) ? $words[$number] : (string) $number;
	}

	/**
	 * Months in words, such as "sechs Monate".
	 *
	 * @param int $months Months
	 * @return string
	 */
	public static function months($months)
	{
		return $months === 1 ? 'einen Monat' : self::number($months).' Monate';
	}

	/**
	 * Years in words, such as "vier Jahre".
	 *
	 * @param int $years Years
	 * @return string
	 */
	public static function years($years)
	{
		return $years === 1 ? 'ein Jahr' : self::number($years).' Jahre';
	}

	/**
	 * Days in words, whole weeks as weeks.
	 *
	 * @param int $days Days
	 * @return string
	 */
	public static function days($days)
	{
		if ($days % 7 === 0 && $days <= 84) {
			return $days === 7 ? 'eine Woche' : self::number($days / 7).' Wochen';
		}
		return $days === 1 ? 'einen Tag' : $days.' Tage';
	}

	/**
	 * A list in words: "a, b und c".
	 *
	 * @param string[] $items Items
	 * @param string   $last  Word before the last item
	 * @return string
	 */
	public static function join(array $items, $last = 'und')
	{
		$items = array_values($items);
		if (count($items) < 2) {
			return implode('', $items);
		}
		return implode(', ', array_slice($items, 0, -1)).' '.$last.' '.end($items);
	}
}
