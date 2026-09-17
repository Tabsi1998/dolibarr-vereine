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
 * \file    class/vereinefeerules.class.php
 * \ingroup vereine
 * \brief   The next fee period and its amount for a member, plain PHP.
 *
 * A fee model extends Dolibarr's member type (amount and duration) by the month the fee
 * year starts, whether a first fee is prorated and an admission fee. Dates are strings
 * YYYY-MM-DD.
 */

/**
 * Rules of the fee model.
 */
class VereineFeeRules
{
	/** The whole period at the full amount. */
	const REASON_FULL = 'full';
	/** Joined during a fee year that starts in a fixed month: the rest of it, prorated by month. */
	const REASON_PRORATED = 'prorated';
	/** Joined during a fee year that starts in a fixed month: the rest of it at the full amount. */
	const REASON_REST_FULL = 'rest_full';

	/**
	 * A fee model with every key set.
	 *
	 * @param array<string,mixed> $model Keys amount (float|null), duration_value (int), duration_unit ('y', 'm', 'w', 'd'),
	 *                                   start_month (0 = with joining, 1 to 12), prorated (bool), admission_fee (float)
	 * @return array{amount:float|null,duration_value:int,duration_unit:string,start_month:int,prorated:bool,admission_fee:float}
	 */
	public static function normalize(array $model)
	{
		$unit = isset($model['duration_unit']) && in_array($model['duration_unit'], array('y', 'm', 'w', 'd'), true) ? $model['duration_unit'] : 'y';
		$month = isset($model['start_month']) ? (int) $model['start_month'] : 0;
		return array(
			'amount' => (!isset($model['amount']) || $model['amount'] === '' || $model['amount'] === null) ? null : round((float) $model['amount'], 2),
			'duration_value' => max(1, isset($model['duration_value']) ? (int) $model['duration_value'] : 1),
			'duration_unit' => $unit,
			'start_month' => ($month >= 1 && $month <= 12) ? $month : 0,
			'prorated' => !empty($model['prorated']),
			'admission_fee' => isset($model['admission_fee']) ? max(0.0, round((float) $model['admission_fee'], 2)) : 0.0,
		);
	}

	/**
	 * Length of a period in months, when it is counted in months or years.
	 *
	 * @param array<string,mixed> $model Normalized model
	 * @return int|null
	 */
	public static function periodMonths(array $model)
	{
		if ($model['duration_unit'] === 'y') {
			return 12 * $model['duration_value'];
		}
		return $model['duration_unit'] === 'm' ? $model['duration_value'] : null;
	}

	/**
	 * The next fee of a member.
	 *
	 * The period starts the day after the last one, or on the day the member joined. With a
	 * fixed start month and a period in months or years, it ends where the fee year ends;
	 * joining during a fee year then pays its rest, prorated by started month or in full.
	 *
	 * @param array<string,mixed> $model     Fee model, see normalize()
	 * @param string              $joinedOn  Day the member joined, for a first fee
	 * @param string              $paidUntil End of the last subscription period, empty for a first fee
	 * @return array{start:string,end:string,amount:float|null,admission_fee:float,total:float|null,reason:string,months:int,period_months:int}|null
	 *         Null without a date to start from
	 */
	public static function nextFee(array $model, $joinedOn, $paidUntil)
	{
		$model = self::normalize($model);
		$first = (string) $paidUntil === '';
		$start = $first ? (string) $joinedOn : self::addDays($paidUntil, 1);
		if (!self::isDate($start)) {
			return null;
		}
		$periodMonths = self::periodMonths($model);
		$reason = self::REASON_FULL;
		$months = 0;
		$amount = $model['amount'];

		if ($model['start_month'] > 0 && $periodMonths !== null) {
			$startIndex = self::monthIndex($start);
			$offset = $model['start_month'] - 1;
			$anchorIndex = $offset + (int) floor(($startIndex - $offset) / $periodMonths) * $periodMonths;
			$end = self::addDays(self::dateOfMonthIndex($anchorIndex + $periodMonths), -1);
			if ($start !== self::dateOfMonthIndex($anchorIndex)) {
				$months = $anchorIndex + $periodMonths - $startIndex;
				if ($model['prorated']) {
					$reason = self::REASON_PRORATED;
					$amount = $amount === null ? null : round($amount * $months / $periodMonths, 2);
				} else {
					$reason = self::REASON_REST_FULL;
				}
			}
		} else {
			$end = self::addDays(self::addDuration($start, $model['duration_value'], $model['duration_unit']), -1);
		}

		$admission = $first ? $model['admission_fee'] : 0.0;
		return array(
			'start' => $start,
			'end' => $end,
			'amount' => $amount,
			'admission_fee' => $admission,
			'total' => $amount === null ? null : round($amount + $admission, 2),
			'reason' => $reason,
			'months' => $months,
			'period_months' => (int) $periodMonths,
		);
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
	 * A date some days later or earlier.
	 *
	 * @param string $date YYYY-MM-DD
	 * @param int    $days Days, negative for earlier
	 * @return string
	 */
	public static function addDays($date, $days)
	{
		list($year, $month, $day) = array_map('intval', explode('-', $date));
		return gmdate('Y-m-d', gmmktime(12, 0, 0, $month, $day + (int) $days, $year));
	}

	/**
	 * A date some years, months, weeks or days later, the way PHP's date arithmetic that Dolibarr
	 * uses counts: 31 January plus one month is 3 March in a common year.
	 *
	 * @param string $date  YYYY-MM-DD
	 * @param int    $value Count
	 * @param string $unit  'y', 'm', 'w' or 'd'
	 * @return string
	 */
	public static function addDuration($date, $value, $unit)
	{
		list($year, $month, $day) = array_map('intval', explode('-', $date));
		$value = (int) $value;
		if ($unit === 'y') {
			$year += $value;
		} elseif ($unit === 'm') {
			$month += $value;
		} else {
			$day += $unit === 'w' ? 7 * $value : $value;
		}
		return gmdate('Y-m-d', gmmktime(12, 0, 0, $month, $day, $year));
	}

	/**
	 * Months since year 0 of the month a date is in.
	 *
	 * @param string $date YYYY-MM-DD
	 * @return int
	 */
	private static function monthIndex($date)
	{
		return (int) substr($date, 0, 4) * 12 + (int) substr($date, 5, 2) - 1;
	}

	/**
	 * First day of the month with a month index.
	 *
	 * @param int $index Months since year 0
	 * @return string YYYY-MM-DD
	 */
	private static function dateOfMonthIndex($index)
	{
		return sprintf('%04d-%02d-01', intdiv($index, 12), $index % 12 + 1);
	}
}
