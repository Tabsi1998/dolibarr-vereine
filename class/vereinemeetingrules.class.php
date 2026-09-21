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
 * \file    class/vereinemeetingrules.class.php
 * \ingroup vereine
 * \brief   Meetings of the board and general assemblies: who is invited, how and by when, plain PHP.
 *
 * Days are strings YYYY-MM-DD, moments YYYY-MM-DD HH:MM.
 */

require_once __DIR__.'/vereinestatuterules.class.php';

/**
 * Rules of meetings and invitations.
 */
class VereineMeetingRules
{
	/** Meeting of the board. */
	const KIND_BOARD = 'board';
	/** Ordinary general assembly. */
	const KIND_GENERAL = 'general';
	/** Extraordinary general assembly. */
	const KIND_EXTRAORDINARY = 'extraordinary';
	/** All kinds in the order they are offered. */
	const KINDS = array('board', 'general', 'extraordinary');

	/** Planned, nobody invited yet. */
	const STATUS_PLANNED = 'planned';
	/** Invitations sent. */
	const STATUS_INVITED = 'invited';
	/** The meeting took place. */
	const STATUS_HELD = 'held';
	/** Called off. */
	const STATUS_CANCELLED = 'cancelled';
	/** All states. */
	const STATUSES = array('planned', 'invited', 'held', 'cancelled');

	/** Everyone in the room. */
	const FORMAT_PHYSICAL = 'physical';
	/** Without physical presence (§ 1 (2) VirtGesG). */
	const FORMAT_VIRTUAL = 'virtual';
	/** Everyone chooses (§ 1 (4) VirtGesG). */
	const FORMAT_HYBRID = 'hybrid';
	/** All formats. */
	const FORMATS = array('physical', 'virtual', 'hybrid');

	/** Invitation by e-mail. */
	const CHANNEL_EMAIL = 'email';
	/** Invitation by letter. */
	const CHANNEL_LETTER = 'letter';

	/** Longest agenda. */
	const AGENDA_MAX = 50;

	/** The steps of a meeting, in their order. */
	const STEPS = array('plan', 'invite', 'meet', 'minutes', 'close');

	/**
	 * Where a meeting stands: every step is done, the one to do now, or later.
	 *
	 * @param array<string,mixed> $meeting Meeting with status, day and agenda
	 * @param array<string,bool>  $facts   Keys invited (an invitation went out), met (attendance or votes recorded),
	 *                                     final (a final version of the minutes), signed (its signatures are complete)
	 * @param string              $today   Today as YYYY-MM-DD
	 * @return array<string,string> State by step: done, now or later
	 */
	public static function steps(array $meeting, array $facts, $today)
	{
		$planned = !empty($meeting['agenda']) && (string) $meeting['day'] !== '';
		$invited = $meeting['status'] !== self::STATUS_PLANNED && !empty($facts['invited']);
		$met = $meeting['status'] === self::STATUS_HELD || !empty($facts['met']);
		$final = !empty($facts['final']);
		$signed = $final && !empty($facts['signed']);
		$states = array();
		$states['plan'] = $planned ? 'done' : 'now';
		$states['invite'] = $invited ? 'done' : ($planned ? 'now' : 'later');
		$states['meet'] = $met ? 'done' : ($invited && (string) $meeting['day'] <= (string) $today ? 'now' : 'later');
		$states['minutes'] = $final ? 'done' : ($met || ($invited && (string) $meeting['day'] < (string) $today) ? 'now' : 'later');
		$states['close'] = $signed ? 'done' : ($final ? 'now' : 'later');
		// Only one step is "now": the first one that is not done.
		$seen = false;
		foreach (self::STEPS as $step) {
			if ($states[$step] === 'now') {
				if ($seen) {
					$states[$step] = 'later';
				}
				$seen = true;
			}
		}
		return $states;
	}

	/**
	 * Entered data of a meeting that can be used as it is.
	 *
	 * @param mixed $data Entered data; the agenda may be text with one item per line
	 * @return array<string,mixed>
	 */
	public static function normalize($data)
	{
		$data = is_array($data) ? $data : array();
		$text = function ($key, $max) use ($data) {
			$value = isset($data[$key]) && is_scalar($data[$key]) ? trim(str_replace("\r", '', (string) $data[$key])) : '';
			return mb_substr($value, 0, $max, 'UTF-8');
		};
		$meeting = array(
			'kind' => in_array($text('kind', 16), self::KINDS, true) ? $text('kind', 16) : self::KIND_BOARD,
			'title' => $text('title', 255),
			'day' => $text('day', 10),
			'time' => $text('time', 5),
			'place' => $text('place', 255),
			'format' => in_array($text('format', 16), self::FORMATS, true) ? $text('format', 16) : self::FORMAT_PHYSICAL,
			'access' => $text('access', 2000),
			'agenda' => array(),
		);
		$items = isset($data['agenda']) ? (is_array($data['agenda']) ? $data['agenda'] : preg_split('/\R/u', (string) $data['agenda'])) : array();
		foreach ($items as $item) {
			$item = is_scalar($item) ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $item)), 0, 255, 'UTF-8') : '';
			if ($item !== '' && count($meeting['agenda']) < self::AGENDA_MAX) {
				$meeting['agenda'][] = $item;
			}
		}
		return $meeting;
	}

	/**
	 * Problems of a meeting before it is stored.
	 *
	 * @param array<string,mixed> $meeting Normalized meeting
	 * @param array<string,mixed> $rules   Normalized rules of the statutes
	 * @return string[] Language keys, empty when fine
	 */
	public static function validate(array $meeting, array $rules)
	{
		$errors = array();
		if ($meeting['title'] === '') {
			$errors[] = 'VereineMeetingErrorTitle';
		}
		if (!VereineStatuteRules::isDate($meeting['day']) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $meeting['time'])) {
			$errors[] = 'VereineMeetingErrorMoment';
		}
		if (!self::formatAllowed($meeting['kind'], $meeting['format'], $rules)) {
			$errors[] = 'VereineMeetingErrorFormat';
		}
		if ($meeting['format'] === self::FORMAT_PHYSICAL || $meeting['format'] === self::FORMAT_HYBRID) {
			if ($meeting['place'] === '') {
				$errors[] = 'VereineMeetingErrorPlace';
			}
		}
		if ($meeting['format'] !== self::FORMAT_PHYSICAL && $meeting['access'] === '') {
			$errors[] = 'VereineMeetingErrorAccess';
		}
		if (!$meeting['agenda']) {
			$errors[] = 'VereineMeetingErrorAgenda';
		}
		return $errors;
	}

	/**
	 * Whether the statutes allow a format for a general assembly; a board meeting may take any.
	 *
	 * @param string              $kind   One of the KIND constants
	 * @param string              $format One of the FORMAT constants
	 * @param array<string,mixed> $rules  Normalized rules of the statutes
	 * @return bool
	 */
	public static function formatAllowed($kind, $format, array $rules)
	{
		if ($kind === self::KIND_BOARD) {
			return in_array($format, self::FORMATS, true);
		}
		switch ($rules['virtual']) {
			case VereineStatuteRules::VIRTUAL_CONVENER:
				return in_array($format, self::FORMATS, true);
			case VereineStatuteRules::VIRTUAL_ALWAYS:
				return $format === self::FORMAT_VIRTUAL;
			case VereineStatuteRules::VIRTUAL_HYBRID:
				return $format === self::FORMAT_HYBRID;
			default:
				return $format === self::FORMAT_PHYSICAL;
		}
	}

	/**
	 * Last day to send the invitations, as the statutes say for general assemblies; board meetings have no period.
	 *
	 * @param array<string,mixed> $meeting Normalized meeting
	 * @param array<string,mixed> $rules   Normalized rules of the statutes
	 * @return string YYYY-MM-DD, empty for board meetings
	 */
	public static function inviteBy(array $meeting, array $rules)
	{
		if ($meeting['kind'] === self::KIND_BOARD || !VereineStatuteRules::isDate($meeting['day'])) {
			return '';
		}
		return self::addDays($meeting['day'], -(int) $rules['invite_days']);
	}

	/**
	 * Last day for motions to a general assembly.
	 *
	 * @param array<string,mixed> $meeting Normalized meeting
	 * @param array<string,mixed> $rules   Normalized rules of the statutes
	 * @return string YYYY-MM-DD, empty for board meetings and when motions run until the start
	 */
	public static function motionsBy(array $meeting, array $rules)
	{
		if ($meeting['kind'] === self::KIND_BOARD || (int) $rules['motion_days'] === 0 || !VereineStatuteRules::isDate($meeting['day'])) {
			return '';
		}
		return self::addDays($meeting['day'], -(int) $rules['motion_days']);
	}

	/**
	 * Who is invited and how.
	 *
	 * A board meeting reaches the board members of the meeting day and nobody else; a general
	 * assembly reaches every active member. Members without e-mail address, or all members when
	 * the statutes do not allow e-mail, get a letter.
	 *
	 * @param string                         $kind    One of the KIND constants
	 * @param array<int,array<string,mixed>> $members Members, keys id, status, type_id, email, name, board (bool: board member on the meeting day)
	 * @param array<string,mixed>            $rules   Normalized rules of the statutes
	 * @return array<int,array{member_id:int,name:string,email:string,channel:string,voting:bool}> In the order of the members
	 */
	public static function recipients($kind, array $members, array $rules)
	{
		$email = $kind === self::KIND_BOARD || in_array(VereineStatuteRules::CHANNEL_EMAIL, $rules['invite_channels'], true);
		$recipients = array();
		foreach ($members as $member) {
			if ((int) $member['status'] !== 1 || ($kind === self::KIND_BOARD && empty($member['board']))) {
				continue;
			}
			$address = trim((string) $member['email']);
			$valid = $address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL);
			$recipients[] = array(
				'member_id' => (int) $member['id'],
				'name' => (string) $member['name'],
				'email' => $valid ? $address : '',
				'channel' => $email && $valid ? self::CHANNEL_EMAIL : self::CHANNEL_LETTER,
				'voting' => $kind === self::KIND_BOARD || !$rules['voting_types'] || in_array((int) $member['type_id'], $rules['voting_types'], true),
			);
		}
		return $recipients;
	}

	/**
	 * Whether the last ordinary general assembly is longer ago than the statutes or the law allow.
	 *
	 * @param string              $last  Day of the last ordinary general assembly held, empty when none
	 * @param string              $today Day
	 * @param array<string,mixed> $rules Normalized rules of the statutes
	 * @return string '' when fine, 'statutes' when the interval of the statutes is over, 'law' when five years are over (§ 5 (2) VerG)
	 */
	public static function generalOverdue($last, $today, array $rules)
	{
		if (!VereineStatuteRules::isDate($last)) {
			return '';
		}
		if (VereineStatuteRules::addYears($last, VereineStatuteRules::GENERAL_MAX_YEARS) < $today) {
			return 'law';
		}
		return VereineStatuteRules::addYears($last, (int) $rules['general_years']) < $today ? 'statutes' : '';
	}

	/**
	 * A day some days later or earlier.
	 *
	 * @param string $day  Day
	 * @param int    $days Days, negative for earlier
	 * @return string YYYY-MM-DD
	 */
	public static function addDays($day, $days)
	{
		list($year, $month, $date) = array_map('intval', explode('-', $day));
		return gmdate('Y-m-d', gmmktime(12, 0, 0, $month, $date + (int) $days, $year));
	}
}
