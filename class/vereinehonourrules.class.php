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
 * \file    class/vereinehonourrules.class.php
 * \ingroup vereine
 * \brief   Rules of honours and member statistics (#27, #28): jubilees, round birthdays, age groups, the export.
 *
 * A jubilee counts full years of membership from the day the membership began, as the member summary
 * has it. A birthday is only ever shown for members who agreed to it. The statistics count members on
 * a day in groups, never names, for umbrella associations and federations.
 */

/**
 * Rules of honours and member statistics.
 */
class VereineHonourRules
{
	/** Years of membership worth an honour unless the association chooses others. */
	const MILESTONES = '10,20,25,30,40,50';

	/** Age groups of the statistics unless the association chooses others: upper ends of the groups. */
	const AGES = '14,18,26,40,60';

	/** Kinds of honours. */
	const KINDS = array('jubilee', 'honorary', 'award');

	/**
	 * Numbers as the setup stores them, such as "10,20,25": whole numbers from 1 to 120, sorted, each once.
	 *
	 * @param string $stored As stored or entered
	 * @return int[] Empty when nothing usable is in it
	 */
	public static function numbers($stored)
	{
		$numbers = array();
		foreach (preg_split('/[\s,;]+/', (string) $stored) as $part) {
			if (preg_match('/^\d{1,3}$/', $part) && (int) $part >= 1 && (int) $part <= 120) {
				$numbers[(int) $part] = (int) $part;
			}
		}
		sort($numbers);
		return array_values($numbers);
	}

	/**
	 * The jubilee a member reaches in a year, if any.
	 *
	 * @param string $since      Day the membership began, YYYY-MM-DD
	 * @param int    $year       Year
	 * @param int[]  $milestones Years worth an honour
	 * @return array{years:int,day:string}|null
	 */
	public static function jubilee($since, $year, array $milestones)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $since, $parts)) {
			return null;
		}
		$years = (int) $year - (int) $parts[1];
		if (!in_array($years, $milestones, true)) {
			return null;
		}
		return array('years' => $years, 'day' => self::sameDay((int) $year, (int) $parts[2], (int) $parts[3]));
	}

	/**
	 * A birthday in a year: the day, the age, and whether it is a round one.
	 *
	 * Round: 18, every ten years from 20, and every five from 65.
	 *
	 * @param string $birth Day of birth, YYYY-MM-DD
	 * @param int    $year  Year
	 * @return array{day:string,age:int,round:bool}|null
	 */
	public static function birthday($birth, $year)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $birth, $parts) || (int) $parts[1] >= (int) $year) {
			return null;
		}
		$age = (int) $year - (int) $parts[1];
		$round = $age === 18 || ($age >= 20 && $age % 10 === 0) || ($age >= 65 && $age % 5 === 0);
		return array('day' => self::sameDay((int) $year, (int) $parts[2], (int) $parts[3]), 'age' => $age, 'round' => $round);
	}

	/**
	 * The age of a person on a day.
	 *
	 * @param string $birth Day of birth, YYYY-MM-DD
	 * @param string $day   The day, YYYY-MM-DD
	 * @return int|null Null when the birth is unknown or after the day
	 */
	public static function age($birth, $day)
	{
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $birth) || (string) $birth > (string) $day) {
			return null;
		}
		$age = (int) substr($day, 0, 4) - (int) substr($birth, 0, 4);
		return substr($day, 5) < substr($birth, 5) ? $age - 1 : $age;
	}

	/**
	 * The age groups from their upper ends: "bis 14", "15–18", …, "ab 61".
	 *
	 * @param int[] $limits Upper ends, sorted
	 * @return array<int,array{from:int,to:int|null}> From and to, to null for the last group
	 */
	public static function ageGroups(array $limits)
	{
		$groups = array();
		$from = 0;
		foreach ($limits as $limit) {
			$groups[] = array('from' => $from, 'to' => (int) $limit);
			$from = (int) $limit + 1;
		}
		$groups[] = array('from' => $from, 'to' => null);
		return $groups;
	}

	/**
	 * The group an age falls in.
	 *
	 * @param int|null                                $age    Age, null when unknown
	 * @param array<int,array{from:int,to:int|null}> $groups From ageGroups()
	 * @return int Index of the group, -1 when the age is unknown
	 */
	public static function groupOf($age, array $groups)
	{
		if ($age === null) {
			return -1;
		}
		foreach ($groups as $index => $group) {
			if ($age >= $group['from'] && ($group['to'] === null || $age <= $group['to'])) {
				return $index;
			}
		}
		return -1;
	}

	/**
	 * Whether somebody was a member on a day: the membership began on or before it and had not ended.
	 *
	 * @param string $since Day the membership began, empty for a draft
	 * @param string $ended Last day of the membership, empty while it lasts
	 * @param string $day   The day
	 * @return bool
	 */
	public static function memberOn($since, $ended, $day)
	{
		return (string) $since !== '' && (string) $since <= (string) $day && ((string) $ended === '' || (string) $ended >= (string) $day);
	}

	/**
	 * Rows as CSV for a spreadsheet: semicolons, quotes where needed, a byte order mark so Excel reads umlauts.
	 *
	 * @param array<int,array<int,string|int>> $rows Rows, the first the heading
	 * @return string
	 */
	public static function csv(array $rows)
	{
		$lines = array();
		foreach ($rows as $row) {
			$cells = array();
			foreach ($row as $cell) {
				$cell = (string) $cell;
				// A cell a spreadsheet would read as a formula starts with an apostrophe.
				if ($cell !== '' && strpos('=+-@', $cell[0]) !== false && !is_numeric($cell)) {
					$cell = "'".$cell;
				}
				$cells[] = preg_match('/[;"\r\n]/', $cell) ? '"'.str_replace('"', '""', $cell).'"' : $cell;
			}
			$lines[] = implode(';', $cells);
		}
		return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
	}

	/**
	 * The same day in another year; 29 February becomes the 28th in a year without it.
	 *
	 * @param int $year  Year
	 * @param int $month Month
	 * @param int $day   Day
	 * @return string YYYY-MM-DD
	 */
	private static function sameDay($year, $month, $day)
	{
		return sprintf('%04d-%02d-%02d', $year, $month, checkdate($month, $day, $year) ? $day : 28);
	}
}
