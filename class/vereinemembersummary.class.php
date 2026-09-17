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
 * \file    class/vereinemembersummary.class.php
 * \ingroup vereine
 * \brief   Membership and fee status of a member for a website, plain PHP.
 *
 * Dates are strings YYYY-MM-DD, an empty string when unknown. A day counts in full:
 * paid until 31 December means paid on that whole day.
 */

/**
 * Rules that turn Dolibarr's member data into the summary a website shows.
 */
class VereineMemberSummary
{
	/** Not yet validated. */
	const STATUS_DRAFT = 'draft';
	/** Validated member. */
	const STATUS_ACTIVE = 'active';
	/** Membership ended (resiliated in Dolibarr). */
	const STATUS_TERMINATED = 'terminated';
	/** Excluded. */
	const STATUS_EXCLUDED = 'excluded';

	/** The subscription period covers today. */
	const FEE_PAID = 'paid';
	/** No subscription period yet, or the last one has ended. */
	const FEE_DUE = 'due';
	/** The member type needs no subscription. */
	const FEE_NOT_REQUIRED = 'not_required';
	/** The membership is not active, so no fee is expected. */
	const FEE_INACTIVE = 'inactive';

	/** Validated, not yet paid, due date not passed. */
	const INVOICE_OPEN = 'open';
	/** Validated, not yet paid, due date passed. */
	const INVOICE_OVERDUE = 'overdue';
	/** Paid, or closed as paid. */
	const INVOICE_PAID = 'paid';
	/** Abandoned: nothing more to pay. */
	const INVOICE_ABANDONED = 'abandoned';

	/** Dolibarr's invoice types by their number: standard, replacement, credit note, deposit. */
	const INVOICE_TYPES = array(0 => 'standard', 1 => 'replacement', 2 => 'credit_note', 3 => 'deposit');

	/**
	 * Membership status from Dolibarr's member status.
	 *
	 * @param int|string $statut Dolibarr's status: -1 draft, 1 validated, 0 resiliated, -2 excluded
	 * @return string One of the STATUS constants
	 */
	public static function status($statut)
	{
		$statut = (int) $statut;
		if ($statut >= 1) {
			return self::STATUS_ACTIVE;
		}
		if ($statut === 0) {
			return self::STATUS_TERMINATED;
		}
		if ($statut === -2) {
			return self::STATUS_EXCLUDED;
		}
		return self::STATUS_DRAFT;
	}

	/**
	 * Since when someone is a member: the start of the first subscription period or the
	 * validation date, whichever is earlier. Empty for drafts.
	 *
	 * @param string $status            One of the STATUS constants
	 * @param string $firstPeriodStart  Start of the first subscription period
	 * @param string $validatedOn       Validation date
	 * @return string
	 */
	public static function memberSince($status, $firstPeriodStart, $validatedOn)
	{
		if ($status === self::STATUS_DRAFT) {
			return '';
		}
		$dates = array_filter(array((string) $firstPeriodStart, (string) $validatedOn), 'strlen');
		return $dates ? min($dates) : '';
	}

	/**
	 * Fee status and the day the next fee is due.
	 *
	 * @param string $status      One of the STATUS constants
	 * @param bool   $required    Whether the member type needs a subscription
	 * @param string $paidUntil   End of the last subscription period
	 * @param string $validatedOn Validation date, due date of a first fee
	 * @param string $today       Today
	 * @return array{status:string,next_due:string}
	 */
	public static function fee($status, $required, $paidUntil, $validatedOn, $today)
	{
		if ($status !== self::STATUS_ACTIVE) {
			return array('status' => self::FEE_INACTIVE, 'next_due' => '');
		}
		if (!$required) {
			return array('status' => self::FEE_NOT_REQUIRED, 'next_due' => '');
		}
		if ((string) $paidUntil === '') {
			return array('status' => self::FEE_DUE, 'next_due' => (string) $validatedOn);
		}
		return array(
			'status' => $paidUntil >= $today ? self::FEE_PAID : self::FEE_DUE,
			'next_due' => self::dayAfter($paidUntil),
		);
	}

	/**
	 * Whether an open invoice is past its due date.
	 *
	 * @param string $dueDate Due date, empty when the invoice has none
	 * @param string $today   Today
	 * @return bool
	 */
	public static function overdue($dueDate, $today)
	{
		return (string) $dueDate !== '' && $dueDate < $today;
	}

	/**
	 * Status of a validated invoice as a website shows it.
	 *
	 * @param int|string $statut  Dolibarr's invoice status: 1 validated, 2 closed, 3 abandoned
	 * @param string     $dueDate Due date, empty when the invoice has none
	 * @param string     $today   Today
	 * @return string One of the INVOICE constants
	 */
	public static function invoiceStatus($statut, $dueDate, $today)
	{
		$statut = (int) $statut;
		if ($statut === 2) {
			return self::INVOICE_PAID;
		}
		if ($statut === 3) {
			return self::INVOICE_ABANDONED;
		}
		return self::overdue($dueDate, $today) ? self::INVOICE_OVERDUE : self::INVOICE_OPEN;
	}

	/**
	 * Name of a Dolibarr invoice type.
	 *
	 * @param int|string $type Dolibarr's invoice type
	 * @return string standard, replacement, credit_note or deposit; empty for a type the module does not list
	 */
	public static function invoiceType($type)
	{
		return isset(self::INVOICE_TYPES[(int) $type]) ? self::INVOICE_TYPES[(int) $type] : '';
	}

	/**
	 * The latest of several moments that are not in the future.
	 *
	 * @param array<int,int|null> $moments Unix timestamps; empty values are left out
	 * @param int                 $now     Now
	 * @return int The latest moment, 0 when there is none
	 */
	public static function latestMoment(array $moments, $now)
	{
		$latest = 0;
		foreach ($moments as $moment) {
			if ((int) $moment > $latest && (int) $moment <= (int) $now) {
				$latest = (int) $moment;
			}
		}
		return $latest;
	}

	/**
	 * How far the database's clock is off when its time is read like PHP's.
	 *
	 * Columns the database fills itself (tms) are written in the database's time zone, but
	 * Dolibarr reads every date in PHP's time zone. On a server where MariaDB runs in UTC and
	 * PHP in Europe/Vienna, such a moment reads two hours early. Time zones differ in steps of
	 * 15 minutes, so the difference of the two clocks is rounded to that.
	 *
	 * @param int $databaseNow The database's current time, read the way Dolibarr reads dates
	 * @param int $now         PHP's current time
	 * @return int Seconds to subtract from a moment the database filled in
	 */
	public static function clockOffset($databaseNow, $now)
	{
		return (int) (round(((int) $databaseNow - (int) $now) / 900) * 900);
	}

	/**
	 * A moment as ISO 8601 in UTC, such as 2026-09-17T08:00:00Z.
	 *
	 * @param int $moment Unix timestamp
	 * @return string Empty for 0
	 */
	public static function isoMoment($moment)
	{
		return (int) $moment > 0 ? gmdate('Y-m-d\TH:i:s\Z', (int) $moment) : '';
	}

	/**
	 * Read an ISO 8601 moment with time zone, such as 2026-09-17T08:00:00Z or 2026-09-17T10:00:00+02:00.
	 *
	 * @param string $text Moment
	 * @return int|null Unix timestamp, null when the text is no such moment
	 */
	public static function parseMoment($text)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?(Z|([+-])(\d{2}):(\d{2}))$/', trim((string) $text), $parts)) {
			return null;
		}
		$second = isset($parts[6]) && $parts[6] !== '' ? (int) $parts[6] : 0;
		if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || (int) $parts[4] > 23 || (int) $parts[5] > 59 || $second > 59) {
			return null;
		}
		$moment = gmmktime((int) $parts[4], (int) $parts[5], $second, (int) $parts[2], (int) $parts[3], (int) $parts[1]);
		if ($parts[7] !== 'Z') {
			$offset = ((int) $parts[9] * 60 + (int) $parts[10]) * 60;
			$moment -= $parts[8] === '+' ? $offset : -$offset;
		}
		return $moment;
	}

	/**
	 * The day a stored status changes by the date alone: a paid fee becomes due the day after
	 * the period, an open invoice overdue the day after its due date.
	 *
	 * @param string $status      Membership status
	 * @param bool   $required    Whether the member type needs a subscription
	 * @param string $paidUntil   End of the last subscription period
	 * @param string $lastDueDate The latest due date of an open invoice that is already past
	 * @param string $today       Today
	 * @return string[] Days, YYYY-MM-DD, not after today
	 */
	public static function changeDays($status, $required, $paidUntil, $lastDueDate, $today)
	{
		$days = array();
		if ($status === self::STATUS_ACTIVE && $required && (string) $paidUntil !== '' && $paidUntil < $today) {
			$days[] = self::dayAfter($paidUntil);
		}
		if ((string) $lastDueDate !== '' && $lastDueDate < $today) {
			$days[] = self::dayAfter($lastDueDate);
		}
		return $days;
	}

	/**
	 * The day after a date.
	 *
	 * @param string $date YYYY-MM-DD
	 * @return string YYYY-MM-DD, empty for an invalid date
	 */
	public static function dayAfter($date)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $parts)) {
			return '';
		}
		return gmdate('Y-m-d', gmmktime(12, 0, 0, (int) $parts[2], (int) $parts[3] + 1, (int) $parts[1]));
	}

	/**
	 * The date part of a database datetime that holds a moment, such as the validation.
	 *
	 * @param string|null $value Value as the database returns it, in the server's time zone
	 * @return string YYYY-MM-DD or empty
	 */
	public static function datePart($value)
	{
		return preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value, $match) && $match[0] !== '0000-00-00' ? $match[0] : '';
	}

	/**
	 * The day a database datetime stands for when it holds a day, such as the end of a
	 * subscription period.
	 *
	 * Dolibarr stores such a day as midnight in the time zone of the user who entered it,
	 * written in the server's time zone: 31 December entered in Vienna is
	 * "2026-12-30 23:00:00" on a server running in UTC. Rounding to the nearest midnight
	 * gives the day back for any user within twelve hours of the server.
	 *
	 * @param string|null $value Value as the database returns it
	 * @return string YYYY-MM-DD or empty
	 */
	public static function dayOf($value)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/', (string) $value, $parts) || $parts[1] === '0000') {
			return '';
		}
		$moment = gmmktime(
			isset($parts[4]) ? (int) $parts[4] : 0,
			isset($parts[5]) ? (int) $parts[5] : 0,
			isset($parts[6]) ? (int) $parts[6] : 0,
			(int) $parts[2],
			(int) $parts[3],
			(int) $parts[1]
		);
		return gmdate('Y-m-d', $moment + 43200);
	}
}
