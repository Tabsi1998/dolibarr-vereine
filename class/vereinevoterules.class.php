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
 * \file    class/vereinevoterules.class.php
 * \ingroup vereine
 * \brief   Votes and elections in a meeting: the majority the statutes ask for and the result, plain PHP.
 *
 * Abstentions are not valid votes cast: the model statutes count the valid votes cast only.
 */

require_once __DIR__.'/vereinestatuterules.class.php';
require_once __DIR__.'/vereinemeetingrules.class.php';

/**
 * Rules of votes and elections.
 */
class VereineVoteRules
{
	/** A resolution on an agenda item. */
	const KIND_RESOLUTION = 'resolution';
	/** An election of a function. */
	const KIND_ELECTION = 'election';
	/** A change of the statutes (general assembly only). */
	const KIND_STATUTES = 'statutes';
	/** The voluntary dissolution (general assembly only). */
	const KIND_DISSOLUTION = 'dissolution';
	/** All kinds in the order they are offered. */
	const KINDS = array('resolution', 'election', 'statutes', 'dissolution');

	/** The chair's vote decides a tie in favour. */
	const TIE_YES = 'yes';
	/** The chair's vote decides a tie against. */
	const TIE_NO = 'no';

	/**
	 * Entered vote that can be used as it is.
	 *
	 * @param mixed $data Entered vote
	 * @return array<string,mixed>
	 */
	public static function normalize($data)
	{
		$data = is_array($data) ? $data : array();
		$count = function ($key) use ($data) {
			return isset($data[$key]) && is_scalar($data[$key]) && preg_match('/^\d{1,6}$/', trim((string) $data[$key])) ? (int) trim((string) $data[$key]) : -1;
		};
		$text = function ($key, $max) use ($data) {
			return isset($data[$key]) && is_scalar($data[$key]) ? mb_substr(trim((string) $data[$key]), 0, $max, 'UTF-8') : '';
		};
		$kind = in_array($text('kind', 16), self::KINDS, true) ? $text('kind', 16) : self::KIND_RESOLUTION;
		return array(
			'kind' => $kind,
			'item' => $count('item'),
			'title' => $text('title', 255),
			'secret' => !empty($data['secret']),
			'yes' => $count('yes'),
			'no' => $count('no'),
			'abstain' => $count('abstain') < 0 && $text('abstain', 6) === '' ? 0 : $count('abstain'),
			'tie' => in_array($text('tie', 3), array(self::TIE_YES, self::TIE_NO), true) ? $text('tie', 3) : '',
			'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $text('time', 5)) ? $text('time', 5) : '',
			'function_id' => $kind === self::KIND_ELECTION ? max(0, $count('function_id')) : 0,
			'candidate_id' => $kind === self::KIND_ELECTION ? max(0, $count('candidate_id')) : 0,
		);
	}

	/**
	 * Problems of a vote before it is stored.
	 *
	 * @param string              $meetingKind Kind of the meeting
	 * @param array<string,mixed> $vote        Normalized vote
	 * @param int                 $votes       Votes present at the time of the vote, with proxies
	 * @param int                 $items       Number of agenda items
	 * @return string[] Language keys, empty when fine
	 */
	public static function validate($meetingKind, array $vote, $votes, $items)
	{
		$errors = array();
		if ($vote['item'] < 1 || $vote['item'] > $items) {
			$errors[] = 'VereineVoteErrorItem';
		}
		if ($vote['title'] === '') {
			$errors[] = 'VereineVoteErrorTitle';
		}
		if ($meetingKind === VereineMeetingRules::KIND_BOARD && in_array($vote['kind'], array(self::KIND_STATUTES, self::KIND_DISSOLUTION), true)) {
			$errors[] = 'VereineVoteErrorBoardKind';
		}
		if ($vote['yes'] < 0 || $vote['no'] < 0 || $vote['abstain'] < 0) {
			$errors[] = 'VereineVoteErrorCounts';
		} elseif ($vote['yes'] + $vote['no'] + $vote['abstain'] > (int) $votes) {
			$errors[] = 'VereineVoteErrorTooMany';
		}
		if ($vote['kind'] === self::KIND_ELECTION && ($vote['function_id'] < 1 || $vote['candidate_id'] < 1)) {
			$errors[] = 'VereineVoteErrorElection';
		}
		return $errors;
	}

	/**
	 * The majority a vote needs under the statutes.
	 *
	 * @param string              $kind  One of the KIND constants
	 * @param array<string,mixed> $rules Normalized rules of the statutes
	 * @return string One of the VereineStatuteRules MAJORITY constants
	 */
	public static function majority($kind, array $rules)
	{
		if ($kind === self::KIND_STATUTES) {
			return $rules['statute_majority'];
		}
		if ($kind === self::KIND_DISSOLUTION) {
			return $rules['dissolution_majority'];
		}
		return VereineStatuteRules::MAJORITY_SIMPLE;
	}

	/**
	 * Whether a vote passed.
	 *
	 * Simple majority: more yes than no. Two thirds or three quarters: at least that share of the
	 * valid votes cast. A tie in a board meeting is decided by the chair's vote where the statutes
	 * say so; otherwise a tie is no majority.
	 *
	 * @param array<string,mixed> $vote        Normalized vote
	 * @param string              $majority    Majority of majority()
	 * @param string              $meetingKind Kind of the meeting
	 * @param array<string,mixed> $rules       Normalized rules of the statutes
	 * @return array{passed:bool,tie:bool,decided_by_chair:bool}
	 */
	public static function result(array $vote, $majority, $meetingKind, array $rules)
	{
		$yes = max(0, (int) $vote['yes']);
		$no = max(0, (int) $vote['no']);
		$cast = $yes + $no;
		if ($majority === VereineStatuteRules::MAJORITY_TWO_THIRDS) {
			return array('passed' => $cast > 0 && $yes * 3 >= $cast * 2, 'tie' => false, 'decided_by_chair' => false);
		}
		if ($majority === VereineStatuteRules::MAJORITY_THREE_QUARTERS) {
			return array('passed' => $cast > 0 && $yes * 4 >= $cast * 3, 'tie' => false, 'decided_by_chair' => false);
		}
		if ($yes === $no) {
			$chair = $meetingKind === VereineMeetingRules::KIND_BOARD && !empty($rules['board_tie_chair']) && $yes > 0 && $vote['tie'] !== '';
			return array('passed' => $chair && $vote['tie'] === self::TIE_YES, 'tie' => true, 'decided_by_chair' => $chair);
		}
		return array('passed' => $yes > $no, 'tie' => false, 'decided_by_chair' => false);
	}
}
