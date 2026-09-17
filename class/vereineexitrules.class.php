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
 * \file    class/vereineexitrules.class.php
 * \ingroup vereine
 * \brief   When a membership ends: reasons and the notice period of the statutes, plain PHP.
 *
 * Dates are strings YYYY-MM-DD. The last day is the last day of the membership.
 */

/**
 * Rules of leaving the association.
 */
class VereineExitRules
{
	/** The member gives notice; the notice period of the statutes applies. */
	const REASON_RESIGNATION = 'resignation';
	/** The competent body excluded the member. */
	const REASON_EXCLUSION = 'exclusion';
	/** The member died. */
	const REASON_DEATH = 'death';
	/** The member was struck off, for example for unpaid fees, as the statutes allow. */
	const REASON_STRUCK_OFF = 'struck_off';
	/** All reasons in the order they are offered. */
	const REASONS = array('resignation', 'exclusion', 'death', 'struck_off');

	/** Notice takes effect on any day. */
	const AT_ANY_DAY = 'any_day';
	/** Notice takes effect at the end of a month. */
	const AT_MONTH_END = 'month_end';
	/** Notice takes effect at the end of a quarter of the association year. */
	const AT_QUARTER_END = 'quarter_end';
	/** Notice takes effect at the end of the association year. */
	const AT_YEAR_END = 'year_end';
	/** All kinds of dates notice takes effect on. */
	const ATS = array('any_day', 'month_end', 'quarter_end', 'year_end');

	/** Longest notice period in months that can be set. */
	const MAX_MONTHS = 24;

	/**
	 * A notice rule that can be used as it is.
	 *
	 * @param mixed $months     Notice period in months
	 * @param mixed $at         One of the AT constants
	 * @param mixed $startMonth Month the association year starts, 1 to 12
	 * @return array{months:int,at:string,start_month:int} Invalid parts fall back to no period, any day, January
	 */
	public static function normalize($months, $at, $startMonth)
	{
		$months = (is_numeric($months) && (int) $months == $months) ? (int) $months : 0;
		$startMonth = (is_numeric($startMonth) && (int) $startMonth >= 1 && (int) $startMonth <= 12) ? (int) $startMonth : 1;
		return array(
			'months' => ($months >= 0 && $months <= self::MAX_MONTHS) ? $months : 0,
			'at' => in_array($at, self::ATS, true) ? (string) $at : self::AT_ANY_DAY,
			'start_month' => $startMonth,
		);
	}

	/**
	 * Problems of a notice rule before it is stored.
	 *
	 * @param mixed $months     Notice period in months
	 * @param mixed $at         One of the AT constants
	 * @param mixed $startMonth Month the association year starts
	 * @return string[] Language keys, empty when fine
	 */
	public static function validate($months, $at, $startMonth)
	{
		$errors = array();
		if (!preg_match('/^\d{1,2}$/', trim((string) $months)) || (int) $months > self::MAX_MONTHS) {
			$errors[] = 'VereineExitErrorMonths';
		}
		if (!in_array($at, self::ATS, true)) {
			$errors[] = 'VereineExitErrorAt';
		}
		if (!is_numeric($startMonth) || (int) $startMonth < 1 || (int) $startMonth > 12) {
			$errors[] = 'VereineExitErrorStartMonth';
		}
		return $errors;
	}

	/**
	 * The last day of a membership after notice given on a day.
	 *
	 * The notice period runs from the day notice was given; the membership ends on the first
	 * end of a month, quarter or association year that is not before the end of the period.
	 * Notice on 30 September with three months to the end of a calendar year ends on
	 * 31 December; notice on 1 October ends on 31 December of the next year.
	 *
	 * @param string              $noticeDay Day the notice was given
	 * @param array<string,mixed> $rule      See normalize()
	 * @return string|null Null without a valid day
	 */
	public static function lastDay($noticeDay, array $rule)
	{
		if (!self::isDate($noticeDay)) {
			return null;
		}
		$rule = self::normalize($rule['months'], $rule['at'], $rule['start_month']);
		$earliest = self::addMonths($noticeDay, $rule['months']);
		if ($rule['at'] === self::AT_ANY_DAY) {
			return $earliest;
		}
		$length = array(self::AT_MONTH_END => 1, self::AT_QUARTER_END => 3, self::AT_YEAR_END => 12);
		$length = $length[$rule['at']];
		$index = (int) substr($earliest, 0, 4) * 12 + (int) substr($earliest, 5, 2) - 1;
		$offset = $length === 1 ? 0 : $rule['start_month'] - 1;
		$anchor = $offset + (int) floor(($index - $offset) / $length) * $length;
		$next = $anchor + $length;
		return gmdate('Y-m-d', gmmktime(12, 0, 0, $next % 12 + 1, 0, intdiv($next, 12)));
	}

	/**
	 * Whether an exit has to be carried out on a day: on its last day or later.
	 *
	 * @param string $lastDay Last day of the membership
	 * @param string $today   Today
	 * @return bool
	 */
	public static function isDue($lastDay, $today)
	{
		return self::isDate($lastDay) && $lastDay <= $today;
	}

	/**
	 * Dolibarr's member status an exit leads to.
	 *
	 * @param string $reason One of the REASON constants
	 * @return string 'excluded' for an exclusion, 'resiliated' otherwise
	 */
	public static function statusFor($reason)
	{
		return $reason === self::REASON_EXCLUSION ? 'excluded' : 'resiliated';
	}

	/**
	 * A date some months later, the day kept or moved to the month's last day: 31 January plus one month is 28 February.
	 *
	 * @param string $date   YYYY-MM-DD
	 * @param int    $months Months
	 * @return string
	 */
	public static function addMonths($date, $months)
	{
		$index = (int) substr($date, 0, 4) * 12 + (int) substr($date, 5, 2) - 1 + (int) $months;
		$year = intdiv($index, 12);
		$month = $index % 12 + 1;
		$day = min((int) substr($date, 8, 2), (int) gmdate('t', gmmktime(12, 0, 0, $month, 1, $year)));
		return sprintf('%04d-%02d-%02d', $year, $month, $day);
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
