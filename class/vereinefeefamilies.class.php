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
 * \file    class/vereinefeefamilies.class.php
 * \ingroup vereine
 * \brief   Families of members with the same payer and their fee rule, plain PHP.
 *
 * A member pays its fees itself, or names a third party that pays them, such as a parent.
 * Members with the same payer are a family. The family rule comes after each member's own
 * discount, counts only members that pay a fee, and never touches admission fees.
 * Dates are strings YYYY-MM-DD.
 */

/**
 * Rules of families.
 */
class VereineFeeFamilies
{
	/** No family discount; fees of a family starting on the same day still share one invoice. */
	const MODE_NONE = 'none';
	/** The member with the highest fee pays in full, every further member a percentage less. */
	const MODE_PERCENT = 'percent';
	/** The fees of a family in one fee year together cost at most an amount. */
	const MODE_CAP = 'cap';

	/**
	 * A family rule that can be used as it is.
	 *
	 * @param string $mode  One of the MODE constants
	 * @param mixed  $value Percentage or amount
	 * @return array{mode:string,value:float} No discount for anything invalid
	 */
	public static function normalize($mode, $value)
	{
		if (self::validate($mode, $value) || $mode === self::MODE_NONE) {
			return array('mode' => self::MODE_NONE, 'value' => 0.0);
		}
		return array('mode' => (string) $mode, 'value' => round((float) $value, 2));
	}

	/**
	 * Problems of a family rule before it is stored.
	 *
	 * @param string $mode  One of the MODE constants
	 * @param mixed  $value Percentage or amount
	 * @return string[] Language keys of the problems, empty when the rule is fine
	 */
	public static function validate($mode, $value)
	{
		if ($mode === self::MODE_NONE) {
			return array();
		}
		if ($mode === self::MODE_PERCENT) {
			return (is_numeric($value) && (float) $value > 0 && (float) $value < 100) ? array() : array('VereineFamilyErrorPercent');
		}
		if ($mode === self::MODE_CAP) {
			return (is_numeric($value) && (float) $value > 0) ? array() : array('VereineFamilyErrorCap');
		}
		return array('VereineFamilyErrorMode');
	}

	/**
	 * The third party that gets a member's fee invoices.
	 *
	 * @param int $ownSocid   Third party of the member, 0 for none
	 * @param int $payerSocid Third party named as payer on the member, 0 or less for none
	 * @return int 0 when nobody can be invoiced yet
	 */
	public static function payerOf($ownSocid, $payerSocid)
	{
		return (int) $payerSocid > 0 ? (int) $payerSocid : max(0, (int) $ownSocid);
	}

	/**
	 * Whether someone else than the member's own third party pays its fees.
	 *
	 * @param int $ownSocid   Third party of the member, 0 for none
	 * @param int $payerSocid Third party named as payer on the member, 0 or less for none
	 * @return bool
	 */
	public static function paidByOther($ownSocid, $payerSocid)
	{
		return (int) $payerSocid > 0 && (int) $payerSocid !== (int) $ownSocid;
	}

	/**
	 * A fee as amount per year, so fees of different period lengths can be compared.
	 *
	 * @param float|null $amount       Fee of a whole period
	 * @param int|null   $periodMonths Months of a period, null when counted in weeks or days
	 * @return float|null
	 */
	public static function yearlyAmount($amount, $periodMonths)
	{
		if ($amount === null) {
			return null;
		}
		return (int) $periodMonths > 0 ? round((float) $amount * 12 / (int) $periodMonths, 2) : (float) $amount;
	}

	/**
	 * The member who pays in full: the highest fee, with equal fees the first one given.
	 *
	 * @param array<int|string,float> $amounts Yearly fee by member, members without fee left out
	 * @return int|string|null Null without any fee above 0
	 */
	public static function head(array $amounts)
	{
		$head = null;
		foreach ($amounts as $key => $amount) {
			if ((float) $amount > 0 && ($head === null || (float) $amount > (float) $amounts[$head])) {
				$head = $key;
			}
		}
		return $head;
	}

	/**
	 * A fee a percentage less.
	 *
	 * @param float|null $amount  Fee
	 * @param float      $percent Percentage
	 * @return float|null
	 */
	public static function percentOff($amount, $percent)
	{
		return $amount === null ? null : round((float) $amount * (100 - (float) $percent) / 100, 2);
	}

	/**
	 * Fees lowered in proportion so that together they cost at most what is left.
	 *
	 * Every fee is rounded down to the cent; the last fee above 0 takes the rest, so the fees
	 * together cost exactly what is left.
	 *
	 * @param array<int|string,float> $amounts Fees by key
	 * @param float                   $left    What the family may still be charged, below 0 counts as 0
	 * @return array<int|string,float> Fees by key in the same order, unchanged when they fit
	 */
	public static function share(array $amounts, $left)
	{
		$left = max(0.0, round((float) $left, 2));
		$total = 0.0;
		$last = null;
		foreach ($amounts as $key => $amount) {
			$total += (float) $amount;
			if ((float) $amount > 0) {
				$last = $key;
			}
		}
		$result = array_map('floatval', $amounts);
		if ($last === null || round($total, 2) <= $left) {
			return $result;
		}
		$shared = 0.0;
		foreach ($amounts as $key => $amount) {
			if ($key === $last) {
				continue;
			}
			$result[$key] = floor(round((float) $amount * $left / $total * 100, 6)) / 100;
			$shared += $result[$key];
		}
		$result[$last] = round($left - $shared, 2);
		return $result;
	}

	/**
	 * First day of the fee year a day falls into.
	 *
	 * @param string $day        YYYY-MM-DD
	 * @param int    $startMonth Month the fee year starts, 0 for the calendar year
	 * @return string YYYY-MM-DD
	 */
	public static function feeYear($day, $startMonth)
	{
		$startMonth = ((int) $startMonth >= 1 && (int) $startMonth <= 12) ? (int) $startMonth : 1;
		$year = (int) substr($day, 0, 4);
		if ((int) substr($day, 5, 2) < $startMonth) {
			$year--;
		}
		return sprintf('%04d-%02d-01', $year, $startMonth);
	}
}
