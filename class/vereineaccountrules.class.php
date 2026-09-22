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
 * \file    class/vereineaccountrules.class.php
 * \ingroup vereine
 * \brief   Rules of the income and expenditure account with the statement of assets (§ 21 (1) VerG).
 *
 * The account follows the money: what came in and went out on the bank and cash accounts in the
 * association's year. A payment of an invoice is split over the areas of the invoice's lines; what
 * cannot be assigned stays visible as such, it is never guessed. Transfers between the association's
 * own accounts are no income and no expense.
 */

/**
 * Rules of the income and expenditure account.
 */
class VereineAccountRules
{
	/** Payment of a customer invoice. */
	const KIND_INVOICE = 'invoice';
	/** Membership fee paid to the bank directly. */
	const KIND_FEE = 'fee';
	/** Donation. */
	const KIND_DONATION = 'donation';
	/** Payment of a supplier invoice. */
	const KIND_SUPPLIER = 'supplier';
	/** Salary. */
	const KIND_SALARY = 'salary';
	/** Social contribution or tax. */
	const KIND_TAX = 'tax';
	/** Expense report. */
	const KIND_EXPENSE_REPORT = 'expense_report';
	/** Loan. */
	const KIND_LOAN = 'loan';
	/** A payment Dolibarr knows as "various payment". */
	const KIND_VARIOUS = 'various';
	/** A booking without a payment behind it. */
	const KIND_UNLINKED = 'unlinked';
	/** A transfer between the association's own accounts: not counted. */
	const KIND_TRANSFER = 'transfer';
	/** The initial balance of an account opened in Dolibarr: part of the opening balance, not counted. */
	const KIND_OPENING = 'opening';
	/** Every kind, in the order of the account. */
	const KINDS = array('invoice', 'fee', 'donation', 'supplier', 'salary', 'tax', 'expense_report', 'loan', 'various', 'unlinked', 'transfer', 'opening');

	/** Area of what could not be assigned. */
	const UNASSIGNED = 'unassigned';
	/** The areas in the order of the account, the unassigned last. */
	const SPHERES = array('ideal', 'assets', 'essential', 'auxiliary', 'festival', 'harmful', 'unassigned');

	/** Link type of a bank line in Dolibarr => kind of booking; the first that fits counts. */
	const LINKS = array(
		'initial' => 'opening',
		'banktransfert' => 'transfer',
		'payment' => 'invoice',
		'payment_supplier' => 'supplier',
		'member' => 'fee',
		'payment_donation' => 'donation',
		'payment_salary' => 'salary',
		'payment_sc' => 'tax',
		'payment_vat' => 'tax',
		'payment_expensereport' => 'expense_report',
		'payment_loan' => 'loan',
		'payment_various' => 'various',
	);

	/** Months after the end of the year to make the account (§ 21 (1) VerG). */
	const MONTHS_TO_MAKE = 5;

	/** Ordinary income or expenses above this in two years in a row: a balance sheet is due (§ 22 (1) VerG). */
	const LARGE = 1000000;
	/** Ordinary income or expenses above this in two years in a row: an auditor is due (§ 22 (2) VerG). */
	const VERY_LARGE = 3000000;

	/**
	 * The kind of a bank line from the link types Dolibarr wrote for it.
	 *
	 * @param string[] $types Link types of the line
	 * @return string One of KINDS
	 */
	public static function kindOf(array $types)
	{
		foreach (self::LINKS as $type => $kind) {
			if (in_array($type, $types, true)) {
				return $kind;
			}
		}
		return self::KIND_UNLINKED;
	}

	/**
	 * The area of a booking that is not the payment of an invoice.
	 *
	 * @param string $kind One of KINDS
	 * @return string One of SPHERES
	 */
	public static function sphereOf($kind)
	{
		return in_array($kind, array(self::KIND_FEE, self::KIND_DONATION), true) ? 'ideal' : self::UNASSIGNED;
	}

	/**
	 * Split an amount over areas in the shares of the lines behind it, to the cent; the last area takes the rest.
	 *
	 * @param float                                  $amount Amount of the booking
	 * @param array<int,array{sphere:string,total:float}> $lines  Lines behind it with their area (empty for none) and total
	 * @return array<string,float> Amount by area
	 */
	public static function split($amount, array $lines)
	{
		$amount = round((float) $amount, 2);
		$shares = array();
		$sum = 0.0;
		foreach ($lines as $line) {
			$sphere = in_array((string) $line['sphere'], self::SPHERES, true) && (string) $line['sphere'] !== '' ? (string) $line['sphere'] : self::UNASSIGNED;
			$shares[$sphere] = (isset($shares[$sphere]) ? $shares[$sphere] : 0.0) + (float) $line['total'];
			$sum += (float) $line['total'];
		}
		if (!$shares || abs($sum) < 0.005) {
			return array(self::UNASSIGNED => $amount);
		}
		$result = array();
		$given = 0.0;
		$keys = array_keys($shares);
		foreach ($keys as $index => $sphere) {
			$part = $index === count($keys) - 1 ? round($amount - $given, 2) : round($amount * $shares[$sphere] / $sum, 2);
			$result[$sphere] = $part;
			$given += $part;
		}
		return $result;
	}

	/**
	 * Whether a booking counts as income or expense: payments to the association are income, payments by
	 * it are expenses, whatever their sign; a refund of an invoice is negative income.
	 *
	 * @param string $kind   One of KINDS
	 * @param float  $amount Amount of the booking
	 * @return string 'income', 'expense' or '' for a transfer or an initial balance
	 */
	public static function side($kind, $amount)
	{
		if ($kind === self::KIND_TRANSFER || $kind === self::KIND_OPENING) {
			return '';
		}
		if (in_array($kind, array(self::KIND_INVOICE, self::KIND_FEE, self::KIND_DONATION), true)) {
			return 'income';
		}
		if (in_array($kind, array(self::KIND_SUPPLIER, self::KIND_SALARY, self::KIND_TAX, self::KIND_EXPENSE_REPORT, self::KIND_LOAN), true)) {
			return 'expense';
		}
		return (float) $amount >= 0 ? 'income' : 'expense';
	}

	/**
	 * Add the bookings up by side, area and kind; expenses count positive.
	 *
	 * @param array<int,array{kind:string,parts:array<string,float>,amount:float}> $bookings Bookings with their parts by area
	 * @return array{income:array<string,array<string,float>>,expense:array<string,array<string,float>>,totals:array{income:float,expense:float,result:float}}
	 */
	public static function totals(array $bookings)
	{
		$sides = array('income' => array(), 'expense' => array());
		$sum = array('income' => 0.0, 'expense' => 0.0);
		foreach ($bookings as $booking) {
			$side = self::side($booking['kind'], $booking['amount']);
			if ($side === '') {
				continue;
			}
			foreach ($booking['parts'] as $sphere => $part) {
				$value = $side === 'expense' ? -(float) $part : (float) $part;
				if (!isset($sides[$side][$sphere][$booking['kind']])) {
					$sides[$side][$sphere][$booking['kind']] = 0.0;
				}
				$sides[$side][$sphere][$booking['kind']] = round($sides[$side][$sphere][$booking['kind']] + $value, 2);
				$sum[$side] = round($sum[$side] + $value, 2);
			}
		}
		foreach ($sides as $side => $spheres) {
			uksort($spheres, function ($a, $b) {
				return array_search($a, self::SPHERES, true) - array_search($b, self::SPHERES, true);
			});
			$sides[$side] = $spheres;
		}
		return array('income' => $sides['income'], 'expense' => $sides['expense'],
			'totals' => array('income' => $sum['income'], 'expense' => $sum['expense'], 'result' => round($sum['income'] - $sum['expense'], 2)));
	}

	/**
	 * Whether the account agrees with the bank: opening balance, plus income, minus expenses, is the closing balance.
	 *
	 * @param float $opening Balance of every account the day before the year
	 * @param float $result  Income minus expenses
	 * @param float $closing Balance of every account on the last day
	 * @return bool
	 */
	public static function reconciled($opening, $result, $closing)
	{
		return abs(round((float) $opening + (float) $result - (float) $closing, 2)) < 0.005;
	}

	/**
	 * The day before a day, for the opening balance.
	 *
	 * @param string $day Day, YYYY-MM-DD
	 * @return string YYYY-MM-DD
	 */
	public static function dayBefore($day)
	{
		return gmdate('Y-m-d', gmmktime(12, 0, 0, (int) substr((string) $day, 5, 2), (int) substr((string) $day, 8, 2) - 1, (int) substr((string) $day, 0, 4)));
	}

	/**
	 * The last day to make the account: five months after the end of the year (§ 21 (1) VerG).
	 *
	 * @param string $end Last day of the year, YYYY-MM-DD
	 * @return string YYYY-MM-DD
	 */
	public static function deadline($end)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', (string) $end, $parts)) {
			return '';
		}
		$month = (int) $parts[2] + self::MONTHS_TO_MAKE;
		$year = (int) $parts[1] + intdiv($month - 1, 12);
		$month = ($month - 1) % 12 + 1;
		return sprintf('%04d-%02d-%02d', $year, $month, (int) date('t', gmmktime(12, 0, 0, $month, 1, $year)));
	}

	/**
	 * What § 22 VerG asks of a larger association, from income and expenses of this and the year before.
	 *
	 * @param array{income:float,expense:float} $current  Totals of the year
	 * @param array{income:float,expense:float} $previous Totals of the year before
	 * @return string[] Language keys: balance sheet due, auditor due
	 */
	public static function sizeWarnings(array $current, array $previous)
	{
		$over = function ($limit) use ($current, $previous) {
			return max($current['income'], $current['expense']) > $limit && max($previous['income'], $previous['expense']) > $limit;
		};
		$warnings = array();
		if ($over(self::LARGE)) {
			$warnings[] = 'VereineAccountSizeLarge';
		}
		if ($over(self::VERY_LARGE)) {
			$warnings[] = 'VereineAccountSizeVeryLarge';
		}
		return $warnings;
	}

	/**
	 * Further assets and debts as entered: a label and an amount each, debts negative.
	 *
	 * @param mixed $data Rows with label, amount and kind asset or debt
	 * @return array<int,array{label:string,amount:float,kind:string}>
	 */
	public static function extras($data)
	{
		$rows = array();
		foreach (is_array($data) ? $data : array() as $row) {
			if (!is_array($row)) {
				continue;
			}
			$label = isset($row['label']) && is_scalar($row['label']) ? trim((string) $row['label']) : '';
			$amount = isset($row['amount']) && is_numeric(str_replace(',', '.', (string) $row['amount'])) ? round((float) str_replace(',', '.', (string) $row['amount']), 2) : 0.0;
			if ($label === '' || $amount == 0) {
				continue;
			}
			$kind = isset($row['kind']) && $row['kind'] === 'debt' ? 'debt' : 'asset';
			$rows[] = array('label' => function_exists('mb_substr') ? mb_substr($label, 0, 120, 'UTF-8') : substr($label, 0, 120), 'amount' => abs($amount), 'kind' => $kind);
		}
		return array_slice($rows, 0, 30);
	}
}
