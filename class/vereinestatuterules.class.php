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
 * \file    class/vereinestatuterules.class.php
 * \ingroup vereine
 * \brief   What the statutes lay down for membership, general assembly and board, plain PHP.
 *
 * The defaults follow the model statutes of the Austrian Ministry of the Interior (April 2024).
 * Dates are strings YYYY-MM-DD.
 */

/**
 * Rules of the statutes.
 */
class VereineStatuteRules
{
	/** The general assembly meets at least every five years (§ 5 (2) VerG). */
	const GENERAL_MAX_YEARS = 5;
	/** Longest term of office in years that can be set. */
	const TERM_MAX_YEARS = 20;
	/** Longest period in days that can be set for invitations and motions. */
	const MAX_DAYS = 365;

	/** Invitation by letter. */
	const CHANNEL_LETTER = 'letter';
	/** Invitation by e-mail to the address the member gave. */
	const CHANNEL_EMAIL = 'email';
	/** Invitation published on the association's website. */
	const CHANNEL_WEBSITE = 'website';
	/** All ways of inviting in the order they are offered. */
	const CHANNELS = array('letter', 'email', 'website');

	/** More yes than no of the valid votes cast. */
	const MAJORITY_SIMPLE = 'simple';
	/** Two thirds of the valid votes cast. */
	const MAJORITY_TWO_THIRDS = 'two_thirds';
	/** Three quarters of the valid votes cast. */
	const MAJORITY_THREE_QUARTERS = 'three_quarters';
	/** All majorities in the order they are offered. */
	const MAJORITIES = array('simple', 'two_thirds', 'three_quarters');

	/** Members meet in person only. */
	const VIRTUAL_NONE = 'none';
	/** Whoever convenes decides whether the assembly meets virtually (§ 1 (3) VirtGesG). */
	const VIRTUAL_CONVENER = 'convener';
	/** The assembly always meets virtually (§ 1 (3) VirtGesG). */
	const VIRTUAL_ALWAYS = 'always';
	/** Every member chooses to attend in person or virtually (§ 1 (4) VirtGesG). */
	const VIRTUAL_HYBRID = 'hybrid';
	/** All kinds of virtual assemblies in the order they are offered. */
	const VIRTUALS = array('none', 'convener', 'always', 'hybrid');

	/** A board or auditor function has no term of office (§ 3 (2) no. 8 VerG). */
	const HINT_TERM_MISSING = 'term_missing';
	/** A term of office ends between two ordinary general assemblies. */
	const HINT_TERM_NOT_ALIGNED = 'term_not_aligned';

	/**
	 * The rules of the model statutes of the Ministry of the Interior.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults()
	{
		return array(
			'min_age' => 0,
			'voting_types' => array(),
			'general_years' => 1,
			'invite_days' => 14,
			'invite_channels' => array(self::CHANNEL_LETTER, self::CHANNEL_EMAIL),
			'motion_days' => 3,
			'proxy' => true,
			'general_quorum' => 0,
			'statute_majority' => self::MAJORITY_TWO_THIRDS,
			'dissolution_majority' => self::MAJORITY_TWO_THIRDS,
			'virtual' => self::VIRTUAL_NONE,
			'board_quorum' => 50,
			'board_tie_chair' => true,
			'circular' => false,
			'circular_no_objection' => false,
		);
	}

	/**
	 * Rules that can be used as they are: known keys only, missing ones from the defaults.
	 *
	 * @param mixed $data Stored or entered rules
	 * @return array<string,mixed>
	 */
	public static function normalize($data)
	{
		$rules = self::defaults();
		if (!is_array($data)) {
			return $rules;
		}
		foreach (array('min_age', 'general_years', 'invite_days', 'motion_days', 'general_quorum', 'board_quorum') as $key) {
			if (isset($data[$key]) && is_scalar($data[$key]) && preg_match('/^\d{1,3}$/', trim((string) $data[$key]))) {
				$rules[$key] = (int) trim((string) $data[$key]);
			}
		}
		foreach (array('proxy', 'board_tie_chair', 'circular', 'circular_no_objection') as $key) {
			if (array_key_exists($key, $data)) {
				$rules[$key] = !empty($data[$key]);
			}
		}
		foreach (array('statute_majority' => self::MAJORITIES, 'dissolution_majority' => self::MAJORITIES, 'virtual' => self::VIRTUALS) as $key => $allowed) {
			if (isset($data[$key]) && in_array($data[$key], $allowed, true)) {
				$rules[$key] = $data[$key];
			}
		}
		if (isset($data['invite_channels']) && is_array($data['invite_channels'])) {
			$rules['invite_channels'] = array_values(array_intersect(self::CHANNELS, $data['invite_channels']));
		}
		if (isset($data['voting_types']) && is_array($data['voting_types'])) {
			$types = array();
			foreach ($data['voting_types'] as $type) {
				if (is_scalar($type) && preg_match('/^\d+$/', (string) $type) && (int) $type > 0) {
					$types[] = (int) $type;
				}
			}
			$types = array_values(array_unique($types));
			sort($types);
			$rules['voting_types'] = $types;
		}
		return $rules;
	}

	/**
	 * Problems of entered rules before they are stored.
	 *
	 * @param array<string,mixed> $data Entered rules
	 * @return string[] Language keys, empty when fine
	 */
	public static function validate(array $data)
	{
		$number = function ($key, $min, $max) use ($data) {
			$value = isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
			return preg_match('/^\d{1,3}$/', $value) && (int) $value >= $min && (int) $value <= $max ? (int) $value : null;
		};
		$errors = array();
		if ($number('min_age', 0, 99) === null) {
			$errors[] = 'VereineStatuteErrorMinAge';
		}
		if ($number('general_years', 1, self::GENERAL_MAX_YEARS) === null) {
			$errors[] = 'VereineStatuteErrorGeneralYears';
		}
		$invite = $number('invite_days', 1, self::MAX_DAYS);
		$motion = $number('motion_days', 0, self::MAX_DAYS);
		if ($invite === null || $motion === null) {
			$errors[] = 'VereineStatuteErrorDays';
		} elseif ($motion > 0 && $motion >= $invite) {
			$errors[] = 'VereineStatuteErrorMotionDays';
		}
		if (empty($data['invite_channels']) || !is_array($data['invite_channels']) || array_diff($data['invite_channels'], self::CHANNELS)) {
			$errors[] = 'VereineStatuteErrorChannels';
		}
		if ($number('general_quorum', 0, 100) === null || $number('board_quorum', 1, 100) === null) {
			$errors[] = 'VereineStatuteErrorQuorum';
		}
		foreach (array('statute_majority', 'dissolution_majority') as $key) {
			if (!isset($data[$key]) || !in_array($data[$key], self::MAJORITIES, true)) {
				$errors[] = 'VereineStatuteErrorMajority';
				break;
			}
		}
		if (!isset($data['virtual']) || !in_array($data['virtual'], self::VIRTUALS, true)) {
			$errors[] = 'VereineStatuteErrorVirtual';
		}
		return $errors;
	}

	/**
	 * Whether an entered term of office in years can be stored; 0 means not set.
	 *
	 * @param mixed $years Entered years
	 * @return bool
	 */
	public static function isTermYears($years)
	{
		return is_scalar($years) && preg_match('/^\d{1,2}$/', trim((string) $years)) && (int) $years <= self::TERM_MAX_YEARS;
	}

	/**
	 * What is missing or does not fit together, without stopping anything.
	 *
	 * @param array<string,mixed>            $rules     Normalized rules
	 * @param array<int,array<string,mixed>> $functions Active functions, keys id, board, auditor, term_years
	 * @return array<int,array{kind:string,function_id:int}>
	 */
	public static function hints(array $rules, array $functions)
	{
		$hints = array();
		foreach ($functions as $function) {
			$years = (int) $function['term_years'];
			if ($years === 0 && (!empty($function['board']) || !empty($function['auditor']))) {
				$hints[] = array('kind' => self::HINT_TERM_MISSING, 'function_id' => (int) $function['id']);
			} elseif ($years > 0 && $rules['general_years'] > 1 && $years % $rules['general_years'] !== 0) {
				$hints[] = array('kind' => self::HINT_TERM_NOT_ALIGNED, 'function_id' => (int) $function['id']);
			}
		}
		return $hints;
	}

	/**
	 * Whether a person is old enough for the minimum age of the statutes on a day.
	 *
	 * @param string $birth  Birth date, empty when unknown
	 * @param int    $minAge Minimum age, 0 for none
	 * @param string $day    Day
	 * @return bool Unknown birth dates are not old enough while there is a minimum age
	 */
	public static function oldEnough($birth, $minAge, $day)
	{
		if ((int) $minAge <= 0) {
			return true;
		}
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $birth, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
			return false;
		}
		return self::addYears($birth, (int) $minAge) <= $day;
	}

	/**
	 * Whether a text is a real date YYYY-MM-DD.
	 *
	 * @param string $date Text
	 * @return bool
	 */
	public static function isDate($date)
	{
		return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $parts) && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
	}

	/**
	 * The same day some years later; 29 February becomes 28 February in other years.
	 *
	 * @param string $date  Day YYYY-MM-DD
	 * @param int    $years Years
	 * @return string YYYY-MM-DD
	 */
	public static function addYears($date, $years)
	{
		list($year, $month, $day) = array_map('intval', explode('-', $date));
		$year += (int) $years;
		if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
			$day = 28;
		}
		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}
}
