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
 * \file    class/vereineparticipationrules.class.php
 * \ingroup vereine
 * \brief   Rules of participations (#273): what a participation needs, and who was active in a year.
 *
 * A participation says what a member took part in, of which kind and on which day, with hours when they
 * count. Kinds come from a dictionary the association extends. A confirmed helper shift is a participation
 * by itself and is read from the shift, so nothing is entered twice.
 */

/**
 * Rules of participations.
 */
class VereineParticipationRules
{
	/** Entered in Dolibarr. */
	const SOURCE_DOLIBARR = 'dolibarr';

	/** Sent by an application with its own id. */
	const SOURCE_API = 'api';

	/** A confirmed helper shift of an event. */
	const SOURCE_SHIFT = 'shift';

	/** Every source. */
	const SOURCES = array('dolibarr', 'api', 'shift');

	/** The kind a helper shift has. */
	const KIND_SHIFT = 'shift';

	/** Kinds the module suggests, with their German names and positions. */
	const STANDARD = array('event' => array('Veranstaltung', 10), 'competition' => array('Wettbewerb', 20), 'shift' => array('Helferdienst', 30));

	/** Longest title. */
	const TITLE_MAX = 255;

	/** Most hours of one participation. */
	const HOURS_MAX = 9999;

	/**
	 * A participation as entered or sent: a kind of the dictionary, a title, a day not after today, hours if any.
	 *
	 * @param mixed    $sent  kind, title, day, hours
	 * @param string[] $kinds Codes of the active kinds
	 * @param string   $today Today, YYYY-MM-DD
	 * @return array{participation:array{kind:string,title:string,day:string,hours:float|null},errors:string[]} Errors name the field
	 */
	public static function entered($sent, array $kinds, $today)
	{
		$sent = is_array($sent) ? $sent : array();
		$errors = array();
		$kind = isset($sent['kind']) && is_scalar($sent['kind']) ? trim((string) $sent['kind']) : '';
		if (!in_array($kind, $kinds, true)) {
			$errors[] = 'kind';
		}
		$title = isset($sent['title']) && is_scalar($sent['title']) ? trim(preg_replace('/\s+/u', ' ', (string) $sent['title'])) : '';
		if ($title === '' || mb_strlen($title, 'UTF-8') > self::TITLE_MAX || preg_match('/[\x00-\x1F\x7F<>]/u', $title)) {
			$errors[] = 'title';
		}
		$day = isset($sent['day']) && is_scalar($sent['day']) ? trim((string) $sent['day']) : '';
		if (!self::isDay($day) || $day > (string) $today) {
			$errors[] = 'day';
		}
		$hours = self::hours(isset($sent['hours']) ? $sent['hours'] : null);
		if ($hours === false) {
			$errors[] = 'hours';
		}
		return array('participation' => array('kind' => $kind, 'title' => $title, 'day' => $day, 'hours' => $hours === false ? null : $hours), 'errors' => $errors);
	}

	/**
	 * Hours as sent: empty for none, else a number from 0 to HOURS_MAX with at most two decimals; a comma counts as a point.
	 *
	 * @param mixed $hours As sent
	 * @return float|null|false Null for none, false when it is no such number
	 */
	public static function hours($hours)
	{
		if ($hours === null || (is_string($hours) && trim($hours) === '')) {
			return null;
		}
		if (!is_scalar($hours) || is_bool($hours)) {
			return false;
		}
		$text = str_replace(',', '.', trim((string) $hours));
		if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $text) || (float) $text > self::HOURS_MAX) {
			return false;
		}
		return round((float) $text, 2);
	}

	/**
	 * Whether a request under an id says the same as what is kept under it.
	 *
	 * @param array{member_id:int,kind:string,title:string,day:string,hours:float|null} $known        Kept
	 * @param int                                                                        $memberId     Member of the request
	 * @param array{kind:string,title:string,day:string,hours:float|null}                $participation As sent
	 * @return bool
	 */
	public static function sameRequest(array $known, $memberId, array $participation)
	{
		$hours = function ($value) {
			return $value === null ? '' : number_format((float) $value, 2, '.', '');
		};
		return (int) $known['member_id'] === (int) $memberId && (string) $known['kind'] === (string) $participation['kind']
			&& (string) $known['title'] === (string) $participation['title'] && (string) $known['day'] === (string) $participation['day']
			&& $hours($known['hours']) === $hours($participation['hours']);
	}

	/**
	 * A confirmed helper shift as a participation: the event and the shift as title, the hours noted for the
	 * person or else the length of the shift.
	 *
	 * @param array{event:string,label:string,shift_day:string,start_time:string,end_time:string,hours:float|string|null} $shift Shift with the person's entry
	 * @return array{kind:string,title:string,day:string,hours:float|null,source:string}
	 */
	public static function fromShift(array $shift)
	{
		$hours = $shift['hours'] !== null && $shift['hours'] !== '' ? round((float) $shift['hours'], 2) : null;
		if ($hours === null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $shift['start_time']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $shift['end_time'])) {
			$minutes = ((int) substr($shift['end_time'], 0, 2) * 60 + (int) substr($shift['end_time'], 3, 2)) - ((int) substr($shift['start_time'], 0, 2) * 60 + (int) substr($shift['start_time'], 3, 2));
			$hours = $minutes > 0 ? round($minutes / 60, 2) : null;
		}
		$title = trim((string) $shift['event']);
		$label = trim((string) $shift['label']);
		return array('kind' => self::KIND_SHIFT, 'title' => $label !== '' && $label !== $title ? ($title !== '' ? $title.' – '.$label : $label) : $title,
			'day' => (string) $shift['shift_day'], 'hours' => $hours, 'source' => self::SOURCE_SHIFT);
	}

	/**
	 * Who was active: every member with at least one participation, with the number and hours per kind, and per
	 * kind how many members took part.
	 *
	 * @param array<int,array{member_id:int,kind:string,hours:float|null}> $participations Participations of the period
	 * @return array{total:int,members:array<int,array{count:int,hours:float,kinds:array<string,array{count:int,hours:float}>}>,kinds:array<string,array{members:int,count:int,hours:float}>}
	 */
	public static function active(array $participations)
	{
		$members = array();
		$kinds = array();
		foreach ($participations as $participation) {
			$member = (int) $participation['member_id'];
			if ($member < 1) {
				continue;
			}
			$kind = (string) $participation['kind'];
			$hours = $participation['hours'] !== null ? (float) $participation['hours'] : 0.0;
			if (!isset($members[$member])) {
				$members[$member] = array('count' => 0, 'hours' => 0.0, 'kinds' => array());
			}
			if (!isset($members[$member]['kinds'][$kind])) {
				$members[$member]['kinds'][$kind] = array('count' => 0, 'hours' => 0.0);
			}
			if (!isset($kinds[$kind])) {
				$kinds[$kind] = array('members' => array(), 'count' => 0, 'hours' => 0.0);
			}
			$members[$member]['count']++;
			$members[$member]['hours'] = round($members[$member]['hours'] + $hours, 2);
			$members[$member]['kinds'][$kind]['count']++;
			$members[$member]['kinds'][$kind]['hours'] = round($members[$member]['kinds'][$kind]['hours'] + $hours, 2);
			$kinds[$kind]['members'][$member] = true;
			$kinds[$kind]['count']++;
			$kinds[$kind]['hours'] = round($kinds[$kind]['hours'] + $hours, 2);
		}
		foreach ($kinds as $kind => $numbers) {
			$kinds[$kind]['members'] = count($numbers['members']);
		}
		ksort($members);
		return array('total' => count($members), 'members' => $members, 'kinds' => $kinds);
	}

	/**
	 * Whether a text is a real day.
	 *
	 * @param string $day YYYY-MM-DD
	 * @return bool
	 */
	public static function isDay($day)
	{
		return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $day, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
	}
}
