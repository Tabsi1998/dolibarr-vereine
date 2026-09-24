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
 * \file    class/vereineloanrules.class.php
 * \ingroup vereine
 * \brief   Rules of lending the association's equipment to members (#26): a loan, its state, when to remind.
 *
 * The equipment itself is Dolibarr's own: resources with reference, inventory number and type, which the
 * agenda can reserve for an event. A loan hands one of them to a member until a day, with the state it
 * left and came back in. A loan that is late is reminded once a week, to the borrower only.
 */

/**
 * Rules of lending equipment.
 */
class VereineLoanRules
{
	/** Days between two reminders of the same loan. */
	const REMIND_DAYS = 7;

	/** How long a note on the state of the equipment may be. */
	const CONDITION_MAX = 255;

	/**
	 * A loan as entered.
	 *
	 * @param array<string,mixed> $entered resource_id, member_id, issued_on, due_on, condition
	 * @param string              $today   Today, YYYY-MM-DD
	 * @return array{loan:array{resource_id:int,member_id:int,issued_on:string,due_on:string,condition:string},errors:string[]}
	 */
	public static function check(array $entered, $today)
	{
		$errors = array();
		$resource = isset($entered['resource_id']) ? (int) $entered['resource_id'] : 0;
		$member = isset($entered['member_id']) ? (int) $entered['member_id'] : 0;
		$issued = isset($entered['issued_on']) ? trim((string) $entered['issued_on']) : '';
		$due = isset($entered['due_on']) ? trim((string) $entered['due_on']) : '';
		if ($resource < 1) {
			$errors[] = 'VereineLoanErrorResource';
		}
		if ($member < 1) {
			$errors[] = 'VereineLoanErrorMember';
		}
		if (!self::isDay($issued) || $issued > $today) {
			$errors[] = 'VereineLoanErrorIssued';
		}
		if (!self::isDay($due) || (self::isDay($issued) && $due < $issued)) {
			$errors[] = 'VereineLoanErrorDue';
		}
		$condition = isset($entered['condition']) ? trim((string) $entered['condition']) : '';
		if (mb_strlen($condition, 'UTF-8') > self::CONDITION_MAX) {
			$errors[] = 'VereineLoanErrorCondition';
		}
		return array('loan' => array('resource_id' => $resource, 'member_id' => $member, 'issued_on' => $issued, 'due_on' => $due, 'condition' => $condition),
			'errors' => $errors);
	}

	/**
	 * Where a loan stands.
	 *
	 * @param string $dueOn      Day it is due back
	 * @param string $returnedOn Day it came back, empty while out
	 * @param string $today      Today
	 * @return string returned, overdue or out
	 */
	public static function state($dueOn, $returnedOn, $today)
	{
		if ((string) $returnedOn !== '') {
			return 'returned';
		}
		return (string) $dueOn < (string) $today ? 'overdue' : 'out';
	}

	/**
	 * Whether a late loan is to be reminded today: late, and not reminded within the last week.
	 *
	 * @param string $dueOn        Day it is due back
	 * @param string $returnedOn   Day it came back, empty while out
	 * @param string $lastReminded Day of the last reminder, empty for none
	 * @param string $today        Today
	 * @return bool
	 */
	public static function remind($dueOn, $returnedOn, $lastReminded, $today)
	{
		if (self::state($dueOn, $returnedOn, $today) !== 'overdue') {
			return false;
		}
		if ((string) $lastReminded === '') {
			return true;
		}
		$next = date('Y-m-d', strtotime($lastReminded.' 12:00:00 +'.self::REMIND_DAYS.' days'));
		return $next <= $today;
	}

	/**
	 * Whether a text is a day of the calendar.
	 *
	 * @param string $day Text
	 * @return bool
	 */
	public static function isDay($day)
	{
		return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $day, $parts) && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
	}
}
