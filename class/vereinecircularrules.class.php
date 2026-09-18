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
 * \file    class/vereinecircularrules.class.php
 * \ingroup vereine
 * \brief   Circular resolutions of the board: when they are allowed, who votes and what comes out. Plain PHP.
 *
 * The law on associations does not mention circular resolutions, so only the statutes of the association
 * decide. Therefore the setting is off unless somebody switches it on, and an association whose statutes
 * ask that nobody objects to the procedure can say so: one objection ends the circular resolution.
 * There is no casting vote here - nobody presides over a circular resolution.
 */

require_once __DIR__.'/vereinevoterules.class.php';
require_once __DIR__.'/vereinestatuterules.class.php';

/**
 * Rules of the circular resolutions.
 */
class VereineCircularRules
{
	/** Waiting for the votes of the board. */
	const STATUS_OPEN = 'open';
	/** Counted: passed or rejected. */
	const STATUS_DECIDED = 'decided';
	/** Called off, or ended by an objection. */
	const STATUS_CANCELLED = 'cancelled';

	/** In favour. */
	const CHOICE_YES = 'yes';
	/** Against. */
	const CHOICE_NO = 'no';
	/** Abstention, not a valid vote cast. */
	const CHOICE_ABSTAIN = 'abstain';
	/** Objection to the procedure itself, where the statutes ask that nobody objects. */
	const CHOICE_OBJECTION = 'objection';
	/** Every choice in the order the page offers them. */
	const CHOICES = array('yes', 'no', 'abstain', 'objection');

	/** Longest period in days between the start and the deadline. */
	const MAX_DAYS = 90;

	/**
	 * A circular resolution as entered that can be used as it is.
	 *
	 * @param mixed $data Entered circular resolution
	 * @return array{title:string,wording:string,deadline:string}
	 */
	public static function normalize($data)
	{
		$data = is_array($data) ? $data : array();
		$text = function ($key, $max) use ($data) {
			return isset($data[$key]) && is_scalar($data[$key]) ? mb_substr(trim((string) $data[$key]), 0, $max, 'UTF-8') : '';
		};
		$deadline = $text('deadline', 10);
		return array(
			'title' => $text('title', 255),
			'wording' => $text('wording', 65000),
			'deadline' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) ? $deadline : '',
		);
	}

	/**
	 * Problems of a circular resolution before it is started.
	 *
	 * @param array<string,mixed> $circular Normalized circular resolution
	 * @param array<string,mixed> $rules    Normalized rules of the statutes
	 * @param string              $today    Today as YYYY-MM-DD
	 * @param int                 $voters   Board members who may vote
	 * @return string[] Language keys, empty when fine
	 */
	public static function validate(array $circular, array $rules, $today, $voters)
	{
		$errors = array();
		if (empty($rules['circular'])) {
			$errors[] = 'VereineCircularErrorNotAllowed';
		}
		if ($circular['title'] === '' || $circular['wording'] === '') {
			$errors[] = 'VereineCircularErrorText';
		}
		if ($circular['deadline'] === '' || $circular['deadline'] < $today) {
			$errors[] = 'VereineCircularErrorDeadline';
		} elseif (self::days($today, $circular['deadline']) > self::MAX_DAYS) {
			$errors[] = 'VereineCircularErrorTooLong';
		}
		if ((int) $voters < 1) {
			$errors[] = 'VereineCircularErrorNobody';
		}
		return $errors;
	}

	/**
	 * Days between two days.
	 *
	 * @param string $from Day as YYYY-MM-DD
	 * @param string $to   Day as YYYY-MM-DD
	 * @return int
	 */
	public static function days($from, $to)
	{
		$start = strtotime($from.' 12:00:00');
		$end = strtotime($to.' 12:00:00');
		if ($start === false || $end === false) {
			return 0;
		}
		return (int) round(($end - $start) / 86400);
	}

	/**
	 * How the board voted.
	 *
	 * @param array<int,array<string,mixed>> $votes Votes given
	 * @return array{yes:int,no:int,abstain:int,objection:int,given:int}
	 */
	public static function counts(array $votes)
	{
		$counts = array('yes' => 0, 'no' => 0, 'abstain' => 0, 'objection' => 0, 'given' => 0);
		foreach ($votes as $vote) {
			$choice = isset($vote['choice']) ? (string) $vote['choice'] : '';
			if (!in_array($choice, self::CHOICES, true)) {
				continue;
			}
			$counts[$choice]++;
			$counts['given']++;
		}
		return $counts;
	}

	/**
	 * Whether somebody objected to the procedure and the statutes ask that nobody does.
	 *
	 * @param array<int,array<string,mixed>> $votes Votes given
	 * @param array<string,mixed>            $rules Normalized rules of the statutes
	 * @return bool
	 */
	public static function objected(array $votes, array $rules)
	{
		return !empty($rules['circular_no_objection']) && self::counts($votes)['objection'] > 0;
	}

	/**
	 * Whether the result can be counted: everybody voted, somebody objected, or the deadline has passed.
	 *
	 * @param array<string,mixed>            $circular Circular resolution with its deadline
	 * @param int                            $voters   Board members who may vote
	 * @param array<int,array<string,mixed>> $votes    Votes given
	 * @param array<string,mixed>            $rules    Normalized rules of the statutes
	 * @param string                         $today    Today as YYYY-MM-DD
	 * @return bool
	 */
	public static function ready(array $circular, $voters, array $votes, array $rules, $today)
	{
		if (self::objected($votes, $rules)) {
			return true;
		}
		return self::counts($votes)['given'] >= (int) $voters || (string) $circular['deadline'] < (string) $today;
	}

	/**
	 * The result of a circular resolution: the simple majority of the valid votes cast, without a casting vote.
	 *
	 * @param array<int,array<string,mixed>> $votes Votes given
	 * @param array<string,mixed>            $rules Normalized rules of the statutes
	 * @return array{passed:bool,tie:bool,objected:bool,counts:array<string,int>,majority:string}
	 */
	public static function result(array $votes, array $rules)
	{
		$counts = self::counts($votes);
		$majority = VereineStatuteRules::MAJORITY_SIMPLE;
		if (self::objected($votes, $rules)) {
			return array('passed' => false, 'tie' => false, 'objected' => true, 'counts' => $counts, 'majority' => $majority);
		}
		$outcome = VereineVoteRules::result(array('yes' => $counts['yes'], 'no' => $counts['no'], 'tie' => ''), $majority,
			VereineMeetingRules::KIND_BOARD, array('board_tie_chair' => false));
		return array('passed' => $outcome['passed'], 'tie' => $outcome['tie'], 'objected' => false, 'counts' => $counts, 'majority' => $majority);
	}
}
