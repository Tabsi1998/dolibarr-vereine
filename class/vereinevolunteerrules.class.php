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
 * \file    class/vereinevolunteerrules.class.php
 * \ingroup vereine
 * \brief   Volunteer allowances in Austria (#7): which limits apply on a day and what goes over them, plain PHP.
 *
 * An association may pay volunteers a tax-free allowance per day of work, up to a limit per day and per
 * calendar year (Freiwilligenpauschale, § 3 (1) Z 42 EStG), or, in sport, a flat travel allowance up to
 * a limit per day and per calendar month (PRAE, § 3 (1) Z 16c EStG). What goes over a limit is not
 * tax-free any more and has to be reported. The module does not forbid anything: it marks it the moment
 * it is recorded, so nobody learns about it in February.
 *
 * The limits are a dated table, like the other thresholds of the module, with the law and a source for
 * each row, so a change of the law is one new row and the past keeps its own numbers.
 */

/**
 * Rules of the volunteer allowances.
 */
class VereineVolunteerRules
{
	/** The small allowance. */
	const KIND_SMALL = 'small';
	/** The large allowance, only for the activities the law names. */
	const KIND_LARGE = 'large';
	/** The flat travel allowance in sport. */
	const KIND_PRAE = 'prae';

	/** Every kind of allowance. */
	const KINDS = array('small', 'large', 'prae');

	/** The two volunteer allowances; they share their yearly limit, and next to a PRAE they are a case to check. */
	const ALLOWANCES = array('small', 'large');

	/** A day's amount goes over the limit of the day. */
	const OVER_DAY = 'over_day';
	/** The month goes over its limit (PRAE). */
	const OVER_MONTH = 'over_month';
	/** The calendar year goes over its limit (volunteer allowance). */
	const OVER_YEAR = 'over_year';
	/** The same person gets a PRAE and a volunteer allowance in one year. */
	const MIXED = 'mixed';

	/** Everything the rules may point out. */
	const FINDINGS = array('over_day', 'over_month', 'over_year', 'mixed');

	/**
	 * The dated table of the limits. valid_from and valid_to are inclusive days, '' for open.
	 *
	 * @return array<int,array{kind:string,day:float,month:float,year:float,valid_from:string,valid_to:string,basis:string,source:string}>
	 *         A limit of 0 means there is none for that span
	 */
	public static function table()
	{
		$z42 = 'https://www.jusline.at/gesetz/estg/paragraf/3';
		return array(
			array('kind' => self::KIND_SMALL, 'day' => 30.0, 'month' => 0.0, 'year' => 1000.0, 'valid_from' => '2024-01-01', 'valid_to' => '',
				'basis' => '§ 3 Abs. 1 Z 42 EStG', 'source' => $z42),
			array('kind' => self::KIND_LARGE, 'day' => 50.0, 'month' => 0.0, 'year' => 3000.0, 'valid_from' => '2024-01-01', 'valid_to' => '',
				'basis' => '§ 3 Abs. 1 Z 42 EStG', 'source' => $z42),
			array('kind' => self::KIND_PRAE, 'day' => 120.0, 'month' => 720.0, 'year' => 0.0, 'valid_from' => '2024-01-01', 'valid_to' => '',
				'basis' => '§ 3 Abs. 1 Z 16c EStG', 'source' => $z42),
		);
	}

	/**
	 * The limits of a kind on a day, null when none is known for that day.
	 *
	 * @param string $kind One of KINDS
	 * @param string $day  Day YYYY-MM-DD
	 * @return array<string,mixed>|null
	 */
	public static function limitsOn($kind, $day)
	{
		foreach (self::table() as $row) {
			if ($row['kind'] === (string) $kind && ($row['valid_from'] === '' || $row['valid_from'] <= (string) $day)
				&& ($row['valid_to'] === '' || (string) $day <= $row['valid_to'])) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * What is wrong with an entry before it is stored.
	 *
	 * @param array<string,mixed> $entry Keys member_id, day, activity, kind, amount
	 * @return string[] Language keys, empty when fine
	 */
	public static function validate(array $entry)
	{
		$errors = array();
		if ((int) (isset($entry['member_id']) ? $entry['member_id'] : 0) < 1) {
			$errors[] = 'VereineVolunteerErrorMember';
		}
		$day = isset($entry['day']) ? (string) $entry['day'] : '';
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
			$errors[] = 'VereineVolunteerErrorDay';
		}
		$activity = trim((string) (isset($entry['activity']) ? $entry['activity'] : ''));
		if ($activity === '' || mb_strlen($activity, 'UTF-8') > 255) {
			$errors[] = 'VereineVolunteerErrorActivity';
		}
		if (!in_array(isset($entry['kind']) ? (string) $entry['kind'] : '', self::KINDS, true)) {
			$errors[] = 'VereineVolunteerErrorKind';
		}
		$amount = isset($entry['amount']) ? str_replace(',', '.', trim((string) $entry['amount'])) : '';
		if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 100000) {
			$errors[] = 'VereineVolunteerErrorAmount';
		}
		return $errors;
	}

	/**
	 * What an entry goes over, measured with the other entries of the same person.
	 *
	 * The entry itself counts as part of its day, month and year. Nothing is refused: what goes over a
	 * limit is simply not tax-free any more, and the association has to know it.
	 *
	 * @param array<string,mixed>            $entry  Keys day, kind, amount (the entry being looked at)
	 * @param array<int,array<string,mixed>> $others Other entries of the same person, keys day, kind, amount
	 * @return array{findings:string[],day:float,month:float,year:float,limits:array<string,mixed>|null}
	 */
	public static function check(array $entry, array $others)
	{
		$day = (string) $entry['day'];
		$kind = (string) $entry['kind'];
		$limits = self::limitsOn($kind, $day);
		$sums = array('day' => (float) $entry['amount'], 'month' => (float) $entry['amount'], 'year' => (float) $entry['amount']);
		$allowanceKinds = in_array($kind, self::ALLOWANCES, true) ? self::ALLOWANCES : array($kind);
		$mixed = false;
		foreach ($others as $other) {
			$otherDay = (string) $other['day'];
			if (substr($otherDay, 0, 4) !== substr($day, 0, 4)) {
				continue;
			}
			$otherKind = (string) $other['kind'];
			// A PRAE and a volunteer allowance for one person in one year are a case to check, not a routine.
			if (in_array($kind, self::ALLOWANCES, true) !== in_array($otherKind, self::ALLOWANCES, true)) {
				$mixed = true;
			}
			if (!in_array($otherKind, $allowanceKinds, true)) {
				continue;
			}
			$sums['year'] += (float) $other['amount'];
			if (substr($otherDay, 0, 7) === substr($day, 0, 7)) {
				$sums['month'] += (float) $other['amount'];
			}
			if ($otherDay === $day) {
				$sums['day'] += (float) $other['amount'];
			}
		}
		$findings = array();
		if ($limits !== null) {
			// Cents decide: 30.00 is within the limit, 30.01 is not.
			if ($limits['day'] > 0 && round($sums['day'], 2) > $limits['day']) {
				$findings[] = self::OVER_DAY;
			}
			if ($limits['month'] > 0 && round($sums['month'], 2) > $limits['month']) {
				$findings[] = self::OVER_MONTH;
			}
			if ($limits['year'] > 0 && round($sums['year'], 2) > $limits['year']) {
				$findings[] = self::OVER_YEAR;
			}
		}
		if ($mixed) {
			$findings[] = self::MIXED;
		}
		return array('findings' => $findings, 'day' => round($sums['day'], 2), 'month' => round($sums['month'], 2),
			'year' => round($sums['year'], 2), 'limits' => $limits);
	}

	/**
	 * The calendar year per person: what was paid of each kind, on how many days, and what went over.
	 *
	 * This is what the reports at the end of February are prepared from.
	 *
	 * @param array<int,array<string,mixed>> $entries Entries of the year, keys member_id, name, day, kind, amount
	 * @param int                            $year    Calendar year
	 * @return array<int,array<string,mixed>> One row per person, by name
	 */
	public static function yearList(array $entries, $year)
	{
		$people = array();
		foreach ($entries as $entry) {
			if (substr((string) $entry['day'], 0, 4) !== sprintf('%04d', (int) $year)) {
				continue;
			}
			$id = (int) $entry['member_id'];
			if (!isset($people[$id])) {
				$people[$id] = array('member_id' => $id, 'name' => (string) $entry['name'], 'small' => 0.0, 'large' => 0.0, 'prae' => 0.0,
					'days' => array(), 'months' => array(), 'findings' => array());
			}
			$people[$id][(string) $entry['kind']] += (float) $entry['amount'];
			$people[$id]['days'][(string) $entry['day']] = true;
			if ((string) $entry['kind'] === self::KIND_PRAE) {
				$month = substr((string) $entry['day'], 0, 7);
				$people[$id]['months'][$month] = (isset($people[$id]['months'][$month]) ? $people[$id]['months'][$month] : 0.0) + (float) $entry['amount'];
			}
		}
		$yearEnd = sprintf('%04d-12-31', (int) $year);
		foreach ($people as $id => $person) {
			$findings = array();
			$allowance = $person['small'] + $person['large'];
			$limit = self::limitsOn($person['large'] > 0 ? self::KIND_LARGE : self::KIND_SMALL, $yearEnd);
			if ($limit !== null && $limit['year'] > 0 && round($allowance, 2) > $limit['year']) {
				$findings[] = self::OVER_YEAR;
			}
			$prae = self::limitsOn(self::KIND_PRAE, $yearEnd);
			foreach ($person['months'] as $sum) {
				if ($prae !== null && $prae['month'] > 0 && round($sum, 2) > $prae['month']) {
					$findings[] = self::OVER_MONTH;
					break;
				}
			}
			if ($allowance > 0 && $person['prae'] > 0) {
				$findings[] = self::MIXED;
			}
			$people[$id]['small'] = round($person['small'], 2);
			$people[$id]['large'] = round($person['large'], 2);
			$people[$id]['prae'] = round($person['prae'], 2);
			$people[$id]['days'] = count($person['days']);
			$people[$id]['findings'] = $findings;
		}
		usort($people, function ($left, $right) {
			return strcmp($left['name'], $right['name']);
		});
		return $people;
	}
}
