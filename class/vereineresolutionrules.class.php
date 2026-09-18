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
 * \file    class/vereineresolutionrules.class.php
 * \ingroup vereine
 * \brief   The register of resolutions: number, category, validity, search and what follows from a resolution. Plain PHP.
 *
 * A resolution comes from a vote in a meeting or from a circular resolution of the board. The register
 * keeps the facts of the vote as they were and adds what an association looks for later: the wording,
 * a category, from when to when it applies and the tasks that follow.
 */

require_once __DIR__.'/vereinevoterules.class.php';

/**
 * Rules of the register of resolutions.
 */
class VereineResolutionRules
{
	/** A resolution of a meeting. */
	const SOURCE_MEETING = 'meeting';
	/** A circular resolution of the board, without a meeting. */
	const SOURCE_CIRCULAR = 'circular';

	/** Money: budget, fees, purchases, financial statement. */
	const CATEGORY_FINANCE = 'finanzen';
	/** Members: admission, honour, exclusion. */
	const CATEGORY_MEMBERS = 'mitglieder';
	/** The board and the functions: elections, tasks of an organ. */
	const CATEGORY_BODIES = 'organe';
	/** The statutes. */
	const CATEGORY_STATUTES = 'statuten';
	/** Events and the activities of the association. */
	const CATEGORY_EVENTS = 'veranstaltungen';
	/** Everything else. */
	const CATEGORY_OTHER = 'sonstiges';
	/** Categories in the order the page offers them. */
	const CATEGORIES = array('finanzen', 'mitglieder', 'organe', 'statuten', 'veranstaltungen', 'sonstiges');

	/**
	 * The category a resolution gets when nobody chose one.
	 *
	 * @param string $kind Kind of the vote, see VereineVoteRules
	 * @return string One of the CATEGORY constants
	 */
	public static function category($kind)
	{
		if ($kind === VereineVoteRules::KIND_ELECTION) {
			return self::CATEGORY_BODIES;
		}
		if (in_array($kind, array(VereineVoteRules::KIND_STATUTES, VereineVoteRules::KIND_DISSOLUTION), true)) {
			return self::CATEGORY_STATUTES;
		}
		return self::CATEGORY_OTHER;
	}

	/**
	 * The number of a resolution: the year of the resolution and a running number in it.
	 *
	 * @param string $day    Day of the resolution as YYYY-MM-DD
	 * @param int    $number Running number in that year
	 * @return string For instance 2026-3
	 */
	public static function ref($day, $number)
	{
		$year = preg_match('/^(\d{4})-\d{2}-\d{2}$/', (string) $day) ? substr((string) $day, 0, 4) : date('Y');
		return $year.'-'.max(1, (int) $number);
	}

	/**
	 * What of a register entry may be changed later, as entered and usable as it is.
	 *
	 * The facts of the vote are not among them: they were counted in the meeting and stay as they are.
	 *
	 * @param mixed $data Entered entry
	 * @return array<string,mixed>
	 */
	public static function normalize($data)
	{
		$data = is_array($data) ? $data : array();
		$text = function ($key, $max) use ($data) {
			return isset($data[$key]) && is_scalar($data[$key]) ? mb_substr(trim((string) $data[$key]), 0, $max, 'UTF-8') : '';
		};
		$day = function ($key) use ($data) {
			$value = isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
			return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
		};
		$id = function ($key) use ($data) {
			return isset($data[$key]) && is_scalar($data[$key]) && preg_match('/^\d{1,10}$/', trim((string) $data[$key])) ? (int) trim((string) $data[$key]) : 0;
		};
		return array(
			'wording' => $text('wording', 65000),
			'category' => in_array($text('category', 24), self::CATEGORIES, true) ? $text('category', 24) : self::CATEGORY_OTHER,
			'valid_from' => $day('valid_from'),
			'valid_to' => $day('valid_to'),
			'member_id' => $id('member_id'),
			'invoice_id' => $id('invoice_id'),
			'note' => $text('note', 65000),
		);
	}

	/**
	 * Problems of a register entry before it is stored.
	 *
	 * @param array<string,mixed> $entry Normalized entry
	 * @return string[] Language keys, empty when fine
	 */
	public static function validate(array $entry)
	{
		$errors = array();
		if ($entry['valid_from'] !== '' && $entry['valid_to'] !== '' && $entry['valid_to'] < $entry['valid_from']) {
			$errors[] = 'VereineResolutionErrorValidity';
		}
		return $errors;
	}

	/**
	 * A task that follows from a resolution, as entered and usable as it is.
	 *
	 * @param mixed $data Entered task
	 * @return array<string,mixed>
	 */
	public static function normalizeTask($data)
	{
		$data = is_array($data) ? $data : array();
		$label = isset($data['label']) && is_scalar($data['label']) ? mb_substr(trim((string) $data['label']), 0, 255, 'UTF-8') : '';
		$deadline = isset($data['deadline']) && is_scalar($data['deadline']) ? trim((string) $data['deadline']) : '';
		$member = isset($data['member_id']) && is_scalar($data['member_id']) && preg_match('/^\d{1,10}$/', trim((string) $data['member_id'])) ? (int) trim((string) $data['member_id']) : 0;
		return array(
			'label' => $label,
			'member_id' => $member,
			'deadline' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) ? $deadline : '',
		);
	}

	/**
	 * Problems of a task before it is stored.
	 *
	 * @param array<string,mixed> $task Normalized task
	 * @return string[] Language keys, empty when fine
	 */
	public static function validateTask(array $task)
	{
		$errors = array();
		if ($task['label'] === '') {
			$errors[] = 'VereineResolutionTaskErrorLabel';
		}
		if ($task['member_id'] < 1) {
			$errors[] = 'VereineResolutionTaskErrorMember';
		}
		return $errors;
	}

	/**
	 * Filters of the list, as entered and usable as they are.
	 *
	 * @param mixed $data Entered filters
	 * @return array{search:string,year:string,organ:string,category:string,result:string,open:bool}
	 */
	public static function filters($data)
	{
		$data = is_array($data) ? $data : array();
		$text = function ($key, $max) use ($data) {
			return isset($data[$key]) && is_scalar($data[$key]) ? mb_substr(trim((string) $data[$key]), 0, $max, 'UTF-8') : '';
		};
		return array(
			'search' => $text('search', 128),
			'year' => preg_match('/^\d{4}$/', $text('year', 4)) ? $text('year', 4) : '',
			'organ' => in_array($text('organ', 16), VereineMeetingRules::KINDS, true) ? $text('organ', 16) : '',
			'category' => in_array($text('category', 24), self::CATEGORIES, true) ? $text('category', 24) : '',
			'result' => in_array($text('result', 8), array('passed', 'rejected'), true) ? $text('result', 8) : '',
			'open' => !empty($data['open']),
		);
	}

	/**
	 * Whether a resolution matches the filters. The search looks at number, title, wording and note.
	 *
	 * @param array<string,mixed>                                    $row     Resolution of the register
	 * @param array{search:string,year:string,organ:string,category:string,result:string,open:bool} $filters Normalized filters
	 * @return bool
	 */
	public static function matches(array $row, array $filters)
	{
		if ($filters['search'] !== '') {
			$needle = mb_strtolower($filters['search'], 'UTF-8');
			$haystack = mb_strtolower(implode(' ', array($row['ref'], $row['title'], $row['wording'], $row['note'])), 'UTF-8');
			if (mb_strpos($haystack, $needle, 0, 'UTF-8') === false) {
				return false;
			}
		}
		if ($filters['year'] !== '' && substr((string) $row['day'], 0, 4) !== $filters['year']) {
			return false;
		}
		if ($filters['organ'] !== '' && $row['organ'] !== $filters['organ']) {
			return false;
		}
		if ($filters['category'] !== '' && $row['category'] !== $filters['category']) {
			return false;
		}
		if ($filters['result'] === 'passed' && empty($row['passed'])) {
			return false;
		}
		if ($filters['result'] === 'rejected' && !empty($row['passed'])) {
			return false;
		}
		if ($filters['open'] && (int) $row['tasks_open'] < 1) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a resolution applies on a day. Without a validity it applies from the day it was taken.
	 *
	 * @param array<string,mixed> $row Resolution of the register
	 * @param string              $day Day as YYYY-MM-DD
	 * @return bool
	 */
	public static function applies(array $row, $day)
	{
		if (empty($row['passed'])) {
			return false;
		}
		$from = $row['valid_from'] !== '' ? $row['valid_from'] : (string) $row['day'];
		if ($day < $from) {
			return false;
		}
		return $row['valid_to'] === '' || $day <= $row['valid_to'];
	}

	/**
	 * The open tasks as agenda items for the next meeting, newest last.
	 *
	 * @param array<int,array<string,mixed>> $tasks Open tasks with their resolution
	 * @param Translate|null                 $langs Language for the wording, null for the plain label
	 * @return string[] Suggested agenda items
	 */
	public static function suggestions(array $tasks, $langs = null)
	{
		$items = array();
		foreach ($tasks as $task) {
			$label = trim((string) $task['label']);
			if ($label === '') {
				continue;
			}
			$item = $langs !== null ? $langs->transnoentitiesnoconv('VereineResolutionAgendaItem', $label, (string) $task['ref']) : $label.' ('.$task['ref'].')';
			if (!in_array($item, $items, true)) {
				$items[] = $item;
			}
		}
		return $items;
	}
}
