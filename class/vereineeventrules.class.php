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
 * \file    class/vereineeventrules.class.php
 * \ingroup vereine
 * \brief   Templates for the events of an association (#23): phases, deadlines before the day, plain PHP.
 *
 * A template says what has to be done before, during and after an event, how many days before the day
 * each point is due and which function looks after it. What an association really carries is in its own
 * catalogue, see VereineEvents; the suggestions here are only how it starts out.
 */

/**
 * Rules of the event templates.
 */
class VereineEventRules
{
	/** Everything before the day. */
	const PHASE_BEFORE = 'before';
	/** The day itself. */
	const PHASE_DURING = 'during';
	/** What follows afterwards. */
	const PHASE_AFTER = 'after';

	/** The phases in their order. */
	const PHASES = array('before', 'during', 'after');

	/** An event that is planned. */
	const STATUS_PLANNED = 'planned';
	/** An event that took place. */
	const STATUS_DONE = 'done';
	/** An event that was called off. */
	const STATUS_CANCELLED = 'cancelled';

	/** Only the association sees the event (stored as 0 in public). */
	const VISIBILITY_INTERNAL = 'internal';
	/** Anybody, a website too (stored as 1). */
	const VISIBILITY_PUBLIC = 'public';
	/** Members through their application (stored as 2) (#165). */
	const VISIBILITY_MEMBERS = 'members';
	/** Stored value => who sees it. */
	const VISIBILITIES = array(0 => 'internal', 1 => 'public', 2 => 'members');

	/** Every status an event may have. */
	const STATUSES = array('planned', 'done', 'cancelled');

	/** Nobody signs up. */
	const REGISTRATION_NONE = 'none';
	/** Dolibarr's own event organisation takes the sign-ups. */
	const REGISTRATION_DOLIBARR = 'dolibarr';
	/** A released external client takes them, and Dolibarr only keeps its reference (#165). */
	const REGISTRATION_EXTERNAL = 'external';

	/** Exactly one place leads the sign-up, so nothing is booked twice. */
	const REGISTRATIONS = array('none', 'dolibarr', 'external');

	/**
	 * The templates an Austrian association starts out with: a tournament and a club festival.
	 *
	 * Every point names where it comes from, so an association can check it against the law that
	 * applies to it. Nothing here is an approval: the points say what to look at, not that it is settled.
	 *
	 * @return array<int,array{code:string,label:string,note:string,tasks:array<int,array{phase:string,label:string,function_code:string,offset_days:int,source:string}>}>
	 */
	public static function standard()
	{
		return array(
			array(
				'code' => 'turnier',
				'label' => 'Turnier',
				'note' => 'Für ein Turnier mit Publikum. Prüfe jeden Punkt am eigenen Landesrecht, die Vorlage ist keine Genehmigung.',
				'tasks' => array(
					array('phase' => self::PHASE_BEFORE, 'label' => 'Termin, Ort und Ablauf im Vorstand beschließen', 'function_code' => 'obmann',
						'offset_days' => -84, 'source' => ''),
					array('phase' => self::PHASE_BEFORE, 'label' => 'Veranstaltung bei der Gemeinde anzeigen (Landesrecht prüfen)', 'function_code' => 'obmann',
						'offset_days' => -42, 'source' => 'Tiroler Veranstaltungsgesetz 2003'),
					array('phase' => self::PHASE_BEFORE, 'label' => 'Musiknutzung bei der AKM anmelden, wenn Musik läuft', 'function_code' => 'obmann',
						'offset_days' => -28, 'source' => '§ 17 UrhG'),
					array('phase' => self::PHASE_BEFORE, 'label' => 'Veranstaltungshaftpflicht prüfen oder abschließen', 'function_code' => 'kassier',
						'offset_days' => -28, 'source' => ''),
					array('phase' => self::PHASE_BEFORE, 'label' => 'Jugendschutz klären: Altersfreigabe, Ausweiskontrolle, Aufsicht', 'function_code' => 'obmann',
						'offset_days' => -21, 'source' => 'Tiroler Jugendgesetz'),
					array('phase' => self::PHASE_BEFORE, 'label' => 'Registrierkasse oder Kassabuch für Bareinnahmen vorbereiten', 'function_code' => 'kassier',
						'offset_days' => -14, 'source' => '§ 131b BAO'),
					array('phase' => self::PHASE_BEFORE, 'label' => 'Helferplan aufstellen und Schichten besetzen', 'function_code' => 'schriftfuehrung',
						'offset_days' => -14, 'source' => ''),
					array('phase' => self::PHASE_BEFORE, 'label' => 'Turnier ankündigen: Website, Newsletter, Anmeldung öffnen', 'function_code' => 'schriftfuehrung',
						'offset_days' => -21, 'source' => ''),
					array('phase' => self::PHASE_DURING, 'label' => 'Anwesende Helfer einteilen und Ablauf führen', 'function_code' => 'obmann',
						'offset_days' => 0, 'source' => ''),
					array('phase' => self::PHASE_DURING, 'label' => 'Einnahmen zählen und Belege sichern', 'function_code' => 'kassier',
						'offset_days' => 0, 'source' => ''),
					array('phase' => self::PHASE_AFTER, 'label' => 'Einnahmen und Ausgaben im Projekt erfassen und Sphäre zuordnen', 'function_code' => 'kassier',
						'offset_days' => 14, 'source' => ''),
					array('phase' => self::PHASE_AFTER, 'label' => 'Kurzbericht schreiben und im Vorstand besprechen', 'function_code' => 'schriftfuehrung',
						'offset_days' => 21, 'source' => ''),
				),
			),
			array(
				'code' => 'vereinsfest',
				'label' => 'Vereinsfest',
				'note' => 'Für ein Fest mit Speisen und Getränken. Prüfe jeden Punkt am eigenen Landesrecht, die Vorlage ist keine Genehmigung.',
				'tasks' => array(
					array('phase' => self::PHASE_BEFORE, 'label' => 'Termin, Ort und Ablauf im Vorstand beschließen', 'function_code' => 'obmann',
						'offset_days' => -120, 'source' => ''),
					array('phase' => self::PHASE_BEFORE, 'label' => 'Veranstaltung bei der Gemeinde anzeigen (Landesrecht prüfen)', 'function_code' => 'obmann',
						'offset_days' => -56, 'source' => 'Tiroler Veranstaltungsgesetz 2003'),
					array('phase' => self::PHASE_BEFORE, 'label' => 'Ausschank klären: Gewerberecht, Sperrstunde, Jugendschutz', 'function_code' => 'obmann',
						'offset_days' => -42, 'source' => '§ 2 Abs. 1 Z 25 GewO'),
					array('phase' => self::PHASE_BEFORE, 'label' => 'Musiknutzung bei der AKM anmelden', 'function_code' => 'obmann',
						'offset_days' => -28, 'source' => '§ 17 UrhG'),
					array('phase' => self::PHASE_BEFORE, 'label' => 'Veranstaltungshaftpflicht prüfen oder abschließen', 'function_code' => 'kassier',
						'offset_days' => -28, 'source' => ''),
					array('phase' => self::PHASE_BEFORE, 'label' => 'Registrierkasse oder Kassabuch für Bareinnahmen vorbereiten', 'function_code' => 'kassier',
						'offset_days' => -14, 'source' => '§ 131b BAO'),
					array('phase' => self::PHASE_BEFORE, 'label' => 'Helferplan aufstellen und Schichten besetzen', 'function_code' => 'schriftfuehrung',
						'offset_days' => -14, 'source' => ''),
					array('phase' => self::PHASE_DURING, 'label' => 'Ausschank und Kassa beaufsichtigen', 'function_code' => 'kassier',
						'offset_days' => 0, 'source' => ''),
					array('phase' => self::PHASE_AFTER, 'label' => 'Einnahmen und Ausgaben im Projekt erfassen und Sphäre zuordnen', 'function_code' => 'kassier',
						'offset_days' => 14, 'source' => ''),
					array('phase' => self::PHASE_AFTER, 'label' => 'Kurzbericht schreiben und im Vorstand besprechen', 'function_code' => 'schriftfuehrung',
						'offset_days' => 21, 'source' => ''),
				),
			),
		);
	}

	/**
	 * What is wrong with a template before it is stored.
	 *
	 * @param array<string,mixed> $data Keys code, label
	 * @return string[] Language keys, empty when fine
	 */
	public static function validateTemplate(array $data)
	{
		$errors = array();
		$code = isset($data['code']) ? trim((string) $data['code']) : '';
		$label = isset($data['label']) ? trim((string) $data['label']) : '';
		if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $code)) {
			$errors[] = 'VereineEventErrorCode';
		}
		if ($label === '' || mb_strlen($label, 'UTF-8') > 128) {
			$errors[] = 'VereineEventErrorLabel';
		}
		return $errors;
	}

	/**
	 * What is wrong with a point of a template before it is stored.
	 *
	 * @param array<string,mixed> $data Keys phase, label, offset_days
	 * @return string[] Language keys, empty when fine
	 */
	public static function validateTask(array $data)
	{
		$errors = array();
		$label = isset($data['label']) ? trim((string) $data['label']) : '';
		if ($label === '' || mb_strlen($label, 'UTF-8') > 255) {
			$errors[] = 'VereineEventErrorTaskLabel';
		}
		if (!in_array(isset($data['phase']) ? (string) $data['phase'] : '', self::PHASES, true)) {
			$errors[] = 'VereineEventErrorPhase';
		}
		$offset = isset($data['offset_days']) ? trim((string) $data['offset_days']) : '';
		if ($offset === '' || !preg_match('/^-?\d{1,3}$/', $offset) || (int) $offset < -365 || (int) $offset > 365) {
			$errors[] = 'VereineEventErrorOffset';
		}
		return $errors;
	}

	/**
	 * Who sees an event, from what is stored.
	 *
	 * @param mixed $stored 0, 1 or 2
	 * @return string internal, public or members
	 */
	public static function visibility($stored)
	{
		return isset(self::VISIBILITIES[(int) $stored]) ? self::VISIBILITIES[(int) $stored] : self::VISIBILITY_INTERNAL;
	}

	/**
	 * The value to store for who sees an event, from the form: 0, 1 or 2; anything else is internal.
	 *
	 * @param mixed $entered As entered
	 * @return int
	 */
	public static function storedVisibility($entered)
	{
		return is_scalar($entered) && preg_match('/^[0-2]$/', (string) $entered) ? (int) $entered : 0;
	}

	/**
	 * What is wrong with an event before it is stored.
	 *
	 * @param array<string,mixed> $data Keys label, event_day, end_day
	 * @return string[] Language keys, empty when fine
	 */
	public static function validateEvent(array $data)
	{
		$errors = array();
		$label = isset($data['label']) ? trim((string) $data['label']) : '';
		$day = isset($data['event_day']) ? trim((string) $data['event_day']) : '';
		$end = isset($data['end_day']) ? trim((string) $data['end_day']) : '';
		if ($label === '' || mb_strlen($label, 'UTF-8') > 255) {
			$errors[] = 'VereineEventErrorEventLabel';
		}
		if (!self::isDay($day)) {
			$errors[] = 'VereineEventErrorDay';
		}
		if ($end !== '' && (!self::isDay($end) || $end < $day)) {
			$errors[] = 'VereineEventErrorEndDay';
		}
		$registration = isset($data['registration']) ? (string) $data['registration'] : self::REGISTRATION_NONE;
		if (!in_array($registration, self::REGISTRATIONS, true)) {
			$errors[] = 'VereineEventErrorRegistration';
		}
		// An external client only counts when it says who it is, so a booking can be traced back.
		if ($registration === self::REGISTRATION_EXTERNAL && trim((string) (isset($data['external_ref']) ? $data['external_ref'] : '')) === '') {
			$errors[] = 'VereineEventErrorExternalRef';
		}
		return $errors;
	}

	/**
	 * The day a point of a template is due for an event: so many days before or after the day itself.
	 *
	 * @param string $eventDay Day of the event, YYYY-MM-DD
	 * @param int    $offset   Days, negative before the event
	 * @return string Day as YYYY-MM-DD, empty when the event has no day
	 */
	public static function due($eventDay, $offset)
	{
		if (!self::isDay($eventDay)) {
			return '';
		}
		return gmdate('Y-m-d', gmmktime(12, 0, 0, (int) substr($eventDay, 5, 2), (int) substr($eventDay, 8, 2) + (int) $offset,
			(int) substr($eventDay, 0, 4)));
	}

	/**
	 * How a point of the checklist stands on a day.
	 *
	 * @param string $due   Day it is due
	 * @param string $today Today
	 * @param bool   $done  Whether it is done
	 * @return string done, overdue, due or ahead
	 */
	public static function state($due, $today, $done)
	{
		if ($done) {
			return 'done';
		}
		if (!self::isDay($due) || !self::isDay($today)) {
			return 'ahead';
		}
		if ($due < $today) {
			return 'overdue';
		}
		// A point counts as running from two weeks before its day, which is how far ahead people plan.
		return $due <= self::due($today, 14) ? 'due' : 'ahead';
	}

	/**
	 * How far an event has come: how many points are done, open and overdue.
	 *
	 * @param array<int,array<string,mixed>> $tasks Points with keys due_on and done_on
	 * @param string                         $today Today
	 * @return array{total:int,done:int,overdue:int,percent:int}
	 */
	public static function progress(array $tasks, $today)
	{
		$done = 0;
		$overdue = 0;
		foreach ($tasks as $task) {
			$state = self::state((string) $task['due_on'], $today, (string) $task['done_on'] !== '');
			if ($state === 'done') {
				$done++;
			} elseif ($state === 'overdue') {
				$overdue++;
			}
		}
		$total = count($tasks);
		return array('total' => $total, 'done' => $done, 'overdue' => $overdue,
			'percent' => $total > 0 ? (int) round($done * 100 / $total) : 0);
	}

	/**
	 * Whether a text is a day.
	 *
	 * @param string $day Day
	 * @return bool
	 */
	public static function isDay($day)
	{
		return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $day);
	}
}
