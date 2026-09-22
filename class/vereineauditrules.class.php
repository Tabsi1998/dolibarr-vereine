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
 * \file    class/vereineauditrules.class.php
 * \ingroup vereine
 * \brief   Rules of the audit by the auditors (§ 21 VerG): the association's year, the points to check, the hints.
 *
 * § 21 (2) VerG: the auditors check the proper accounting and the use of the funds as the statutes say,
 * within four months after the income and expenditure account was made. § 21 (3): the report confirms
 * both or names the deficiencies and dangers for the association; unusual income or expenses, above all
 * transactions of an officer with the association (§ 6 (4)), are dealt with in particular.
 */

/**
 * Rules of the audit.
 */
class VereineAuditRules
{
	/** Proper accounting (§ 21 (2) VerG). */
	const POINT_ACCOUNTING = 'accounting';
	/** Funds used as the statutes say (§ 21 (2) VerG). */
	const POINT_USE = 'use';
	/** Unusual income or expenses (§ 21 (3) VerG). */
	const POINT_UNUSUAL = 'unusual';
	/** Transactions of an officer with the association (§ 21 (3), § 6 (4) VerG). */
	const POINT_SELF_DEALING = 'self_dealing';
	/** Dangers for the association (§ 21 (3) VerG). */
	const POINT_DANGER = 'danger';
	/** Every point of the checklist, in the order of the report. */
	const POINTS = array('accounting', 'use', 'unusual', 'self_dealing', 'danger');

	/** Not checked yet. */
	const STATE_OPEN = 'open';
	/** In order. */
	const STATE_OK = 'ok';
	/** A deficiency, with what it is. */
	const STATE_DEFECT = 'defect';
	/** Every state of a point. */
	const STATES = array('open', 'ok', 'defect');

	/** A booking without a payment or invoice behind it. */
	const HINT_NO_DOCUMENT = 'no_document';
	/** An amount far above the usual ones. */
	const HINT_UNUSUAL = 'unusual';
	/** Money between the association and an officer or the third party of an officer. */
	const HINT_SELF_DEALING = 'self_dealing';
	/** Every kind of hint. */
	const HINTS = array('no_document', 'unusual', 'self_dealing');

	/** An amount is unusual from this many times the median of the year on. */
	const UNUSUAL_FACTOR = 3;
	/** ... but never below this amount. */
	const UNUSUAL_MINIMUM = 1000;

	/** Longest text of a deficiency or note. */
	const TEXT_MAX = 2000;

	/**
	 * First and last day of an association's year and how it is named.
	 *
	 * The year starts in the month Dolibarr knows as the start of the fiscal year; a year that starts
	 * in July 2025 is "2025/26".
	 *
	 * @param int $year       Year the association's year starts in
	 * @param int $startMonth Month the association's year starts in, 1 to 12
	 * @return array{year:int,start:string,end:string,label:string}
	 */
	public static function period($year, $startMonth)
	{
		$year = (int) $year;
		$month = (int) $startMonth >= 1 && (int) $startMonth <= 12 ? (int) $startMonth : 1;
		$start = sprintf('%04d-%02d-01', $year, $month);
		if ($month === 1) {
			return array('year' => $year, 'start' => $start, 'end' => sprintf('%04d-12-31', $year), 'label' => (string) $year);
		}
		$endMonth = $month - 1;
		$endDay = (int) date('t', gmmktime(12, 0, 0, $endMonth, 1, $year + 1));
		return array('year' => $year, 'start' => $start, 'end' => sprintf('%04d-%02d-%02d', $year + 1, $endMonth, $endDay),
			'label' => $year.'/'.substr((string) ($year + 1), 2));
	}

	/**
	 * The association's year to audit by default: the last one that has ended.
	 *
	 * @param string $today      Today as YYYY-MM-DD
	 * @param int    $startMonth Month the association's year starts in
	 * @return int Year it starts in
	 */
	public static function lastEnded($today, $startMonth)
	{
		$year = (int) substr((string) $today, 0, 4);
		$current = self::period($year, $startMonth);
		if ((string) $today < $current['start']) {
			$year--;
		}
		return $year - 1;
	}

	/**
	 * From which amount a booking counts as unusual: a multiple of the median of the year, at least the minimum.
	 *
	 * @param float[] $amounts Amounts of the year, with or without sign
	 * @return float
	 */
	public static function unusualFrom(array $amounts)
	{
		$values = array();
		foreach ($amounts as $amount) {
			$values[] = abs((float) $amount);
		}
		if (!$values) {
			return (float) self::UNUSUAL_MINIMUM;
		}
		sort($values);
		$count = count($values);
		$middle = intdiv($count, 2);
		$median = $count % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
		return max((float) self::UNUSUAL_MINIMUM, round($median * self::UNUSUAL_FACTOR, 2));
	}

	/**
	 * The checklist as entered, usable as it is: every point with a state and, for a deficiency, a text.
	 *
	 * @param mixed $data Points by code, each with state and text
	 * @return array<string,array{state:string,text:string}>
	 */
	public static function points($data)
	{
		$points = array();
		foreach (self::POINTS as $point) {
			$entered = is_array($data) && isset($data[$point]) && is_array($data[$point]) ? $data[$point] : array();
			$state = isset($entered['state']) && in_array($entered['state'], self::STATES, true) ? (string) $entered['state'] : self::STATE_OPEN;
			$text = isset($entered['text']) && is_scalar($entered['text']) ? trim((string) $entered['text']) : '';
			$text = function_exists('mb_substr') ? mb_substr($text, 0, self::TEXT_MAX, 'UTF-8') : substr($text, 0, self::TEXT_MAX);
			$points[$point] = array('state' => $state, 'text' => $text);
		}
		return $points;
	}

	/**
	 * Problems of the checklist: a deficiency needs its description.
	 *
	 * @param array<string,array{state:string,text:string}> $points Points of points()
	 * @return string[] Language keys
	 */
	public static function validate(array $points)
	{
		foreach ($points as $point) {
			if ($point['state'] === self::STATE_DEFECT && $point['text'] === '') {
				return array('VereineAuditErrorDefectText');
			}
		}
		return array();
	}

	/**
	 * The result of the audit: confirmed when every point is in order, deficiencies when one is not, open otherwise.
	 *
	 * @param array<string,array{state:string,text:string}> $points Points of points()
	 * @return string 'confirmed', 'defects' or 'open'
	 */
	public static function result(array $points)
	{
		$states = array_column($points, 'state');
		if (in_array(self::STATE_DEFECT, $states, true)) {
			return 'defects';
		}
		return $states && !in_array(self::STATE_OPEN, $states, true) ? 'confirmed' : 'open';
	}

	/**
	 * The last day of the four months the auditors have (§ 21 (2) VerG), counted from the day the account was made.
	 *
	 * @param string $made Day the income and expenditure account was made, YYYY-MM-DD
	 * @return string YYYY-MM-DD, empty when the day is unknown
	 */
	public static function deadline($made)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $made, $parts)) {
			return '';
		}
		$month = (int) $parts[2] + 4;
		$year = (int) $parts[1] + intdiv($month - 1, 12);
		$month = ($month - 1) % 12 + 1;
		$last = (int) date('t', gmmktime(12, 0, 0, $month, 1, $year));
		return sprintf('%04d-%02d-%02d', $year, $month, min((int) $parts[3], $last));
	}
}
