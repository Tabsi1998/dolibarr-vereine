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
 * \file    class/vereinefunctionrules.class.php
 * \ingroup vereine
 * \brief   Functions of the association and who holds them on a day, plain PHP.
 *
 * Dates are strings YYYY-MM-DD.
 */

require_once __DIR__.'/vereinestatuterules.class.php';

/**
 * Rules of functions and terms of office.
 */
class VereineFunctionRules
{
	/** Fewer holders than the function needs. */
	const PROBLEM_MISSING = 'missing';
	/** More holders than the function allows. */
	const PROBLEM_TOO_MANY = 'too_many';
	/** The board has fewer than two persons (§ 5 (3) VerG). */
	const PROBLEM_BOARD_TOO_SMALL = 'board_too_small';
	/** An auditor also sits on the board that is audited (§ 5 (5) and (4) VerG). */
	const PROBLEM_AUDITOR_ON_BOARD = 'auditor_on_board';
	/** Holders whose term of office under the statutes has run out: an election is due. */
	const PROBLEM_ELECTION_DUE = 'election_due';

	/** Smallest number of persons on the board. */
	const BOARD_MIN = 2;

	/** Days to report a new representative: four weeks (§ 14 (2) VerG). */
	const REPORT_DAYS = 28;

	/** The website shows a holder's name only with the holder's consent. */
	const NAMES_CONSENT = 'consent';
	/** The website shows the names of the board always (disclosure under § 25 (2) MedienG), other names with consent. */
	const NAMES_DISCLOSURE = 'disclosure';

	/**
	 * Changes of Dolibarr user groups the functions ask for on a day. Nothing is changed here.
	 *
	 * A user linked to a member belongs to the group of every function the member holds; a
	 * group no active function names is never touched, and neither is a user without member.
	 *
	 * @param array<int,array<string,mixed>> $functions   Active functions, keys id, group_id (0 for none)
	 * @param array<int,array<string,mixed>> $terms       Terms, keys function_id, member_id, member_status, start, end
	 * @param array<int,int>                 $memberUsers Dolibarr user id by member id
	 * @param array<int,int[]>               $memberships Group ids by user id
	 * @param string                         $day         Day
	 * @return array<int,array{action:string,user_id:int,member_id:int,group_id:int}> Additions first, then removals
	 */
	public static function groupChanges(array $functions, array $terms, array $memberUsers, array $memberships, $day)
	{
		$groups = array();
		$managed = array();
		foreach ($functions as $function) {
			if ((int) $function['group_id'] > 0) {
				$groups[(int) $function['id']] = (int) $function['group_id'];
				$managed[(int) $function['group_id']] = true;
			}
		}
		$wanted = array();
		foreach ($terms as $term) {
			$memberId = (int) $term['member_id'];
			if (isset($groups[(int) $term['function_id']], $memberUsers[$memberId]) && (int) $term['member_status'] === 1 && self::isActive($term, $day)) {
				$wanted[$memberUsers[$memberId]][$groups[(int) $term['function_id']]] = $memberId;
			}
		}
		$add = array();
		$remove = array();
		foreach ($memberUsers as $memberId => $userId) {
			$has = isset($memberships[$userId]) ? array_map('intval', $memberships[$userId]) : array();
			foreach (isset($wanted[$userId]) ? $wanted[$userId] : array() as $groupId => $holder) {
				if (!in_array($groupId, $has, true)) {
					$add[] = array('action' => 'add', 'user_id' => (int) $userId, 'member_id' => (int) $holder, 'group_id' => $groupId);
				}
			}
			foreach ($has as $groupId) {
				if (isset($managed[$groupId]) && !isset($wanted[$userId][$groupId])) {
					$remove[] = array('action' => 'remove', 'user_id' => (int) $userId, 'member_id' => (int) $memberId, 'group_id' => $groupId);
				}
			}
		}
		return array_merge($add, $remove);
	}

	/**
	 * Whether the website may show the name of a holder.
	 *
	 * @param string $mode       One of the NAMES constants
	 * @param bool   $board      The function belongs to the board
	 * @param bool   $hasConsent The holder consented to be shown
	 * @return bool
	 */
	public static function showName($mode, $board, $hasConsent)
	{
		return $hasConsent || ($mode === self::NAMES_DISCLOSURE && $board);
	}

	/**
	 * Functions suggested for Austria: code, label, board, represents the association, auditor, min, max.
	 *
	 * @return array<int,array{code:string,label:string,board:bool,represents:bool,auditor:bool,min:int,max:int}>
	 */
	public static function suggestedAt()
	{
		$rows = array(
			array('obmann', 'Obmann/Obfrau', 1, 1, 0, 1, 1),
			array('obmann_stv', 'Stellvertretung Obmann/Obfrau', 1, 1, 0, 0, 2),
			array('kassier', 'Kassier:in', 1, 1, 0, 1, 1),
			array('kassier_stv', 'Stellvertretung Kassier:in', 1, 1, 0, 0, 1),
			array('schriftfuehrung', 'Schriftführer:in', 1, 1, 0, 1, 1),
			array('schriftfuehrung_stv', 'Stellvertretung Schriftführer:in', 1, 1, 0, 0, 1),
			array('rechnungspruefung', 'Rechnungsprüfer:in', 0, 0, 1, 2, 0),
		);
		$functions = array();
		foreach ($rows as $row) {
			$functions[] = array('code' => $row[0], 'label' => $row[1], 'board' => (bool) $row[2], 'represents' => (bool) $row[3],
				'auditor' => (bool) $row[4], 'min' => $row[5], 'max' => $row[6]);
		}
		return $functions;
	}

	/**
	 * Problems of a function before it is stored.
	 *
	 * @param array<string,mixed> $data Keys code, label, min, max
	 * @return string[] Language keys, empty when fine
	 */
	public static function validate(array $data)
	{
		$errors = array();
		if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', (string) (isset($data['code']) ? $data['code'] : ''))) {
			$errors[] = 'VereineFunctionErrorCode';
		}
		$label = trim((string) (isset($data['label']) ? $data['label'] : ''));
		if ($label === '' || mb_strlen($label, 'UTF-8') > 128) {
			$errors[] = 'VereineFunctionErrorLabel';
		}
		$min = isset($data['min']) ? trim((string) $data['min']) : '';
		$max = isset($data['max']) ? trim((string) $data['max']) : '';
		if (!preg_match('/^\d{1,2}$/', $min === '' ? '0' : $min) || !preg_match('/^\d{1,2}$/', $max === '' ? '0' : $max)
			|| ((int) $max > 0 && (int) $min > (int) $max)) {
			$errors[] = 'VereineFunctionErrorCount';
		}
		return $errors;
	}

	/**
	 * Problems of a term of office before it is stored.
	 *
	 * @param string $start First day
	 * @param string $end   Last day, empty while open
	 * @return string[] Language keys, empty when fine
	 */
	public static function validateTerm($start, $end)
	{
		if (!self::isDate($start)) {
			return array('VereineFunctionErrorStart');
		}
		if ((string) $end !== '' && (!self::isDate($end) || $end < $start)) {
			return array('VereineFunctionErrorEnd');
		}
		return array();
	}

	/**
	 * The day a term of office ends when nobody says otherwise: as many years as the catalogue holds,
	 * ending the day before the anniversary. Without a term of office it stays open.
	 *
	 * @param string $start Day the term starts as YYYY-MM-DD
	 * @param int    $years Years of the term of office, 0 for none
	 * @return string Day as YYYY-MM-DD, empty when the term stays open
	 */
	public static function endOfTerm($start, $years)
	{
		if ((int) $years < 1 || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $start, $parts)) {
			return '';
		}
		$day = mktime(12, 0, 0, (int) $parts[2], (int) $parts[3], (int) $parts[1] + (int) $years);
		return $day === false ? '' : date('Y-m-d', $day - 86400);
	}

	/**
	 * Whether a term runs on a day.
	 *
	 * @param array<string,mixed> $term Keys start, end (empty while open)
	 * @param string              $day  Day
	 * @return bool
	 */
	public static function isActive(array $term, $day)
	{
		return $term['start'] <= $day && ((string) $term['end'] === '' || $term['end'] >= $day);
	}

	/**
	 * Who holds which function on a day, and what does not fit.
	 *
	 * @param array<int,array<string,mixed>> $functions Active functions, keys id, code, board, auditor, min, max, term_years (optional)
	 * @param array<int,array<string,mixed>> $terms     Terms, keys function_id, member_id, start, end
	 * @param string                         $day       Day
	 * @return array{holders:array<int,int[]>,problems:array<int,array{kind:string,function_id:int,count:int,members:int[]}>}
	 *         Holders are member ids by function id; problems in the order of the functions, the board ones last
	 */
	public static function check(array $functions, array $terms, $day)
	{
		$holders = array();
		$years = array();
		$due = array();
		foreach ($functions as $function) {
			$holders[(int) $function['id']] = array();
			$years[(int) $function['id']] = isset($function['term_years']) ? (int) $function['term_years'] : 0;
			$due[(int) $function['id']] = array();
		}
		foreach ($terms as $term) {
			$functionId = (int) $term['function_id'];
			if (!isset($holders[$functionId]) || !self::isActive($term, $day)) {
				continue;
			}
			if (!in_array((int) $term['member_id'], $holders[$functionId], true)) {
				$holders[$functionId][] = (int) $term['member_id'];
			}
			// The statutes usually keep the holder in office until the election, so this is a reminder, not an end.
			if ($years[$functionId] > 0 && VereineStatuteRules::addYears($term['start'], $years[$functionId]) <= $day
				&& !in_array((int) $term['member_id'], $due[$functionId], true)) {
				$due[$functionId][] = (int) $term['member_id'];
			}
		}
		$problems = array();
		$board = array();
		$auditors = array();
		foreach ($functions as $function) {
			$id = (int) $function['id'];
			$count = count($holders[$id]);
			if ($count < (int) $function['min']) {
				$problems[] = array('kind' => self::PROBLEM_MISSING, 'function_id' => $id, 'count' => $count, 'members' => array());
			} elseif ((int) $function['max'] > 0 && $count > (int) $function['max']) {
				$problems[] = array('kind' => self::PROBLEM_TOO_MANY, 'function_id' => $id, 'count' => $count, 'members' => $holders[$id]);
			}
			if ($due[$id]) {
				$problems[] = array('kind' => self::PROBLEM_ELECTION_DUE, 'function_id' => $id, 'count' => $years[$id], 'members' => $due[$id]);
			}
			if (!empty($function['board'])) {
				$board = array_merge($board, $holders[$id]);
			}
			if (!empty($function['auditor'])) {
				$auditors = array_merge($auditors, $holders[$id]);
			}
		}
		$board = array_values(array_unique($board));
		if ($board && count($board) < self::BOARD_MIN) {
			$problems[] = array('kind' => self::PROBLEM_BOARD_TOO_SMALL, 'function_id' => 0, 'count' => count($board), 'members' => $board);
		}
		$both = array_values(array_intersect($board, array_unique($auditors)));
		if ($both) {
			$problems[] = array('kind' => self::PROBLEM_AUDITOR_ON_BOARD, 'function_id' => 0, 'count' => count($both), 'members' => $both);
		}
		return array('holders' => $holders, 'problems' => $problems);
	}

	/**
	 * Last day to report a new representative to the association authority: four weeks after the start (§ 14 (2) VerG).
	 *
	 * @param string $start First day of the term
	 * @return string YYYY-MM-DD
	 */
	public static function reportDeadline($start)
	{
		list($year, $month, $day) = array_map('intval', explode('-', $start));
		return gmdate('Y-m-d', gmmktime(12, 0, 0, $month, $day + self::REPORT_DAYS, $year));
	}

	/**
	 * What a report to the association authority needs and a person lacks.
	 *
	 * @param array<string,mixed> $person Keys birth, birth_place, address, zip, town
	 * @return string[] Missing: 'birth', 'birth_place', 'address'
	 */
	public static function missingForReport(array $person)
	{
		$missing = array();
		if (!self::isDate(isset($person['birth']) ? $person['birth'] : '')) {
			$missing[] = 'birth';
		}
		if (trim((string) (isset($person['birth_place']) ? $person['birth_place'] : '')) === '') {
			$missing[] = 'birth_place';
		}
		foreach (array('address', 'zip', 'town') as $key) {
			if (trim((string) (isset($person[$key]) ? $person[$key] : '')) === '') {
				$missing[] = 'address';
				break;
			}
		}
		return $missing;
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
}
