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
 * \file    class/vereineballotrules.class.php
 * \ingroup vereine
 * \brief   Votes of the members in a general assembly through applications and on paper (#160, #161), plain PHP.
 *
 * A ballot belongs to an agenda item of a general assembly and is no poll of its own: only who takes part
 * in the assembly votes. Its rules are frozen when it is released; the voting rights when it is opened in
 * the assembly: who was invited with a voting right, is a member on the day, and who holds whose proxy.
 * Each voting right is used once, whichever way: an application, another application, or a paper ballot
 * the board enters. Unpaid fees take no voting right away; the statutes would have to say so.
 */

require_once __DIR__.'/vereinevoterules.class.php';
require_once __DIR__.'/vereineattendancerules.class.php';
require_once __DIR__.'/vereinemeetingrules.class.php';

/**
 * Rules of ballots.
 */
class VereineBallotRules
{
	/** Being prepared; nothing is fixed yet. */
	const STATUS_DRAFT = 'draft';
	/** Rules frozen, visible in the applications, not open yet. */
	const STATUS_RELEASED = 'released';
	/** Voting rights frozen, votes are taken. */
	const STATUS_OPEN = 'open';
	/** No more votes. */
	const STATUS_CLOSED = 'closed';
	/** Counted and the result stored with the meeting (#163). */
	const STATUS_EVALUATED = 'evaluated';
	/** Called off; votes taken stay as a record and count for nothing. */
	const STATUS_CANCELLED = 'cancelled';
	/** Every status. */
	const STATUSES = array('draft', 'released', 'open', 'closed', 'evaluated', 'cancelled');

	/** Where a status may go; everything else is refused. */
	const MOVES = array(
		'draft' => array('released', 'cancelled'),
		'released' => array('open', 'cancelled'),
		'open' => array('closed', 'cancelled'),
		'closed' => array('evaluated', 'cancelled'),
		'evaluated' => array(),
		'cancelled' => array(),
	);

	/** A vote through an application of a member. */
	const CHANNEL_APP = 'app';
	/** A paper ballot the board enters in Dolibarr. */
	const CHANNEL_PAPER = 'paper';
	/** Every channel. */
	const CHANNELS = array('app', 'paper');

	/** Votes for oneself. */
	const REASON_OWN = 'own';
	/** Voted by the holder of a written proxy. */
	const REASON_PROXY = 'proxy';
	/** Invited without a voting right, for instance by the kind of membership. */
	const REASON_NO_VOTING_RIGHT = 'no_voting_right';
	/** Not a member on the day of the assembly. */
	const REASON_NOT_MEMBER = 'not_member';
	/** Every reason a right is kept with. */
	const REASONS = array('own', 'proxy', 'no_voting_right', 'not_member');

	/** Options of a resolution, a change of the statutes and the dissolution; they never change. */
	const YES = 'yes';
	/** Against. */
	const NO = 'no';
	/** Abstention: no valid vote cast. */
	const ABSTAIN = 'abstain';

	/** Counted, not yet confirmed by whoever chairs. */
	const RESULT_PROVISIONAL = 'provisional';
	/** Confirmed in Dolibarr; what follows from it happened once. */
	const RESULT_CONFIRMED = 'confirmed';
	/** Replaced by a later count with its reason; kept with its proof. */
	const RESULT_SUPERSEDED = 'superseded';
	/** Every state of a count. */
	const RESULTS = array('provisional', 'confirmed', 'superseded');

	/** Every outcome of a count. */
	const OUTCOMES = array('passed', 'rejected', 'no_quorum', 'no_majority');

	/** The most candidates in one ballot. */
	const CANDIDATES_MAX = 20;

	/**
	 * Whether a ballot may go from one status to another.
	 *
	 * @param string $from Status now
	 * @param string $to   Status wanted
	 * @return bool
	 */
	public static function canMove($from, $to)
	{
		return isset(self::MOVES[$from]) && in_array($to, self::MOVES[$from], true);
	}

	/**
	 * A ballot as entered, with its options.
	 *
	 * An election has one option per candidate and an abstention, a single candidate also a no; the codes
	 * of the options are fixed when the ballot is created and never change. Several seats in one ballot and
	 * an automatic run-off are not offered: one ballot per seat, a run-off as a ballot of its own.
	 *
	 * @param mixed                  $entered    Keys item, kind, question, channels, closes, function_id, candidates, consent
	 * @param array<int,string>      $candidates Active members who may stand, id => name
	 * @param int                    $items      Number of agenda items
	 * @param array<int,string>      $functions  Functions to elect, id => label
	 * @return array{ballot:array<string,mixed>,options:array<int,array{code:string,label:string,member_id:int,consent:bool}>,errors:string[]}
	 */
	public static function entered($entered, array $candidates, $items, array $functions)
	{
		$entered = is_array($entered) ? $entered : array();
		$text = function ($key, $max) use ($entered) {
			return isset($entered[$key]) && is_scalar($entered[$key]) ? mb_substr(trim((string) $entered[$key]), 0, $max, 'UTF-8') : '';
		};
		$errors = array();
		$kind = in_array($text('kind', 16), VereineVoteRules::KINDS, true) ? $text('kind', 16) : VereineVoteRules::KIND_RESOLUTION;
		$item = preg_match('/^\d{1,3}$/', $text('item', 3)) ? (int) $text('item', 3) : 0;
		if ($item < 1 || $item > (int) $items) {
			$errors[] = 'VereineVoteErrorItem';
		}
		$question = $text('question', 255);
		if ($question === '') {
			$errors[] = 'VereineBallotErrorQuestion';
		}
		$channels = array();
		foreach (isset($entered['channels']) && is_array($entered['channels']) ? $entered['channels'] : array() as $channel) {
			if (in_array($channel, self::CHANNELS, true) && !in_array($channel, $channels, true)) {
				$channels[] = $channel;
			}
		}
		if (!$channels) {
			$errors[] = 'VereineBallotErrorChannels';
		}
		$closes = $text('closes', 5);
		if ($closes !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $closes)) {
			$errors[] = 'VereineBallotErrorCloses';
			$closes = '';
		}
		$options = array();
		$functionId = 0;
		if ($kind === VereineVoteRules::KIND_ELECTION) {
			$functionId = preg_match('/^\d{1,9}$/', $text('function_id', 9)) ? (int) $text('function_id', 9) : 0;
			if (!isset($functions[$functionId])) {
				$errors[] = 'VereineVoteErrorElection';
			}
			$wanted = isset($entered['candidates']) && is_array($entered['candidates']) ? $entered['candidates'] : array();
			$consent = isset($entered['consent']) && is_array($entered['consent']) ? $entered['consent'] : array();
			foreach ($wanted as $candidate) {
				$candidate = is_scalar($candidate) && preg_match('/^\d{1,9}$/', (string) $candidate) ? (int) $candidate : 0;
				if (!isset($candidates[$candidate])) {
					if ($candidate > 0) {
						$errors[] = 'VereineBallotErrorCandidate';
					}
					continue;
				}
				$options['c'.$candidate] = array('code' => 'c'.$candidate, 'label' => $candidates[$candidate], 'member_id' => $candidate,
					'consent' => in_array((string) $candidate, array_map('strval', $consent), true));
			}
			if (!$options) {
				$errors[] = 'VereineBallotErrorCandidates';
			} elseif (count($options) > self::CANDIDATES_MAX) {
				$errors[] = 'VereineBallotErrorCandidates';
			}
			$options = array_values($options);
			if (count($options) === 1) {
				$options[] = array('code' => self::NO, 'label' => '', 'member_id' => 0, 'consent' => false);
			}
		} else {
			foreach (array(self::YES, self::NO) as $code) {
				$options[] = array('code' => $code, 'label' => '', 'member_id' => 0, 'consent' => false);
			}
		}
		$options[] = array('code' => self::ABSTAIN, 'label' => '', 'member_id' => 0, 'consent' => false);
		return array('ballot' => array('item' => $item, 'kind' => $kind, 'question' => $question, 'channels' => $channels, 'closes' => $closes,
			'function_id' => $functionId), 'options' => $options, 'errors' => array_values(array_unique($errors)));
	}

	/**
	 * What stops a ballot from being released, from what is known about it and its meeting.
	 *
	 * @param array<string,mixed>                  $ballot  Ballot with status and kind
	 * @param array<int,array<string,mixed>>       $options Options with member_id and consent
	 * @param array<string,mixed>                  $meeting Meeting with kind and status
	 * @return string[] Language keys
	 */
	public static function releaseProblems(array $ballot, array $options, array $meeting)
	{
		$errors = array();
		if (!self::canMove((string) $ballot['status'], self::STATUS_RELEASED)) {
			$errors[] = 'VereineBallotErrorStatus';
		}
		// Organs of the association act in Dolibarr; applications carry votes of the members in their assembly only.
		if ((string) $meeting['kind'] === VereineMeetingRules::KIND_BOARD) {
			$errors[] = 'VereineBallotErrorBoard';
		}
		if (!in_array((string) $meeting['status'], array(VereineMeetingRules::STATUS_INVITED, VereineMeetingRules::STATUS_HELD), true)) {
			$errors[] = 'VereineMeetingErrorNotInvited';
		}
		if (!empty($ballot['secret'])) {
			$errors[] = 'VereineBallotErrorSecret';
		}
		foreach ($options as $option) {
			if ((int) $option['member_id'] > 0 && empty($option['consent'])) {
				$errors[] = 'VereineBallotErrorConsent';
				break;
			}
		}
		return $errors;
	}

	/**
	 * The voting right of an invited member, frozen when the ballot opens.
	 *
	 * Invited with a voting right and a member on the day: the member votes, or the holder of a proxy the
	 * attendance names and the statutes allow. A notice of exit counts from the last day of the membership
	 * on; a later exit leaves the right as it is. Unpaid fees do not take the right away.
	 *
	 * @param array{member_id:int,voting:bool}          $invitation Invitation of the member
	 * @param array{status:int}                         $member     The member now
	 * @param string                                    $lastDay    Last day of the membership after a notice, '' for none
	 * @param int                                       $holder     Holder of a valid proxy, 0 for none
	 * @param string                                    $day        Day of the assembly
	 * @return array{member_id:int,holder:int,reason:string,eligible:bool}
	 */
	public static function right(array $invitation, array $member, $lastDay, $holder, $day)
	{
		$memberId = (int) $invitation['member_id'];
		if (empty($invitation['voting'])) {
			return array('member_id' => $memberId, 'holder' => 0, 'reason' => self::REASON_NO_VOTING_RIGHT, 'eligible' => false);
		}
		if ((int) $member['status'] !== 1 || ((string) $lastDay !== '' && (string) $lastDay < (string) $day)) {
			return array('member_id' => $memberId, 'holder' => 0, 'reason' => self::REASON_NOT_MEMBER, 'eligible' => false);
		}
		if ((int) $holder > 0 && (int) $holder !== $memberId) {
			return array('member_id' => $memberId, 'holder' => (int) $holder, 'reason' => self::REASON_PROXY, 'eligible' => true);
		}
		return array('member_id' => $memberId, 'holder' => $memberId, 'reason' => self::REASON_OWN, 'eligible' => true);
	}

	/**
	 * The holders of valid proxies by the member they represent: only what the attendance rules accept.
	 *
	 * @param string                         $kind       Kind of the meeting
	 * @param array<int,array<string,mixed>> $attendance Normalized attendance by member id
	 * @param array<int,bool>                $voting     Voting right by invited member id
	 * @param array<string,mixed>            $rules      Normalized rules of the statutes
	 * @return array<int,int> Represented member => holder
	 */
	public static function proxies($kind, array $attendance, array $voting, array $rules)
	{
		$problems = VereineAttendanceRules::validate($kind, $attendance, $voting, $rules);
		$holders = array();
		foreach ($attendance as $memberId => $row) {
			if ($row['state'] === VereineAttendanceRules::STATE_REPRESENTED && !isset($problems[$memberId]) && (int) $row['holder'] > 0) {
				$holders[(int) $memberId] = (int) $row['holder'];
			}
		}
		return $holders;
	}

	/**
	 * What stops a vote, '' when it counts.
	 *
	 * The ballot is open and not past the hour it closes at; the right is valid and not used; whoever votes
	 * holds it; through an application only while that person is in the assembly by the attendance.
	 *
	 * @param array<string,mixed>      $ballot   Ballot with status, closes, channels, day
	 * @param array<string,mixed>|null $right    The right with eligible, holder, used
	 * @param int                      $actor    Member who votes, 0 for the board entering a paper ballot
	 * @param string                   $channel  One of CHANNELS
	 * @param string                   $today    Today, YYYY-MM-DD
	 * @param string                   $now      Time now, HH:MM
	 * @param bool                     $present  Whether the actor is in the assembly now
	 * @return string Reason: not_found, not_open, closed, channel, used, not_present, or ''
	 */
	public static function castProblem(array $ballot, $right, $actor, $channel, $today, $now, $present)
	{
		if ($right === null || empty($right['eligible']) || ($channel === self::CHANNEL_APP && (int) $right['holder'] !== (int) $actor)) {
			return 'not_found';
		}
		if ((string) $ballot['status'] !== self::STATUS_OPEN) {
			return (string) $ballot['status'] === self::STATUS_RELEASED ? 'not_open' : 'closed';
		}
		if ((string) $today !== (string) $ballot['day'] || ((string) $ballot['closes'] !== '' && (string) $now >= (string) $ballot['closes'])) {
			return 'closed';
		}
		if (!in_array($channel, (array) $ballot['channels'], true)) {
			return 'channel';
		}
		if (!empty($right['used'])) {
			return 'used';
		}
		if ($channel === self::CHANNEL_APP && !$present) {
			return 'not_present';
		}
		return '';
	}

	/**
	 * Votes per option: every option once, abstentions apart; the valid votes cast are the others.
	 *
	 * @param string[] $codes Codes of the options
	 * @param string[] $votes Code of each vote cast
	 * @return array{counts:array<string,int>,valid:int,abstain:int}
	 */
	public static function tally(array $codes, array $votes)
	{
		$counts = array_fill_keys($codes, 0);
		foreach ($votes as $vote) {
			if (isset($counts[$vote])) {
				$counts[$vote]++;
			}
		}
		$abstain = isset($counts[self::ABSTAIN]) ? $counts[self::ABSTAIN] : 0;
		return array('counts' => $counts, 'valid' => array_sum($counts) - $abstain, 'abstain' => $abstain);
	}

	/**
	 * What a counted ballot decided, by its frozen rules.
	 *
	 * Without the quorum nothing is decided. A resolution needs its majority of the valid votes cast. An
	 * election with one candidate is a vote for or against; with several, whoever has more than half of the
	 * valid votes cast is elected, otherwise nobody, and a run-off is a ballot of its own.
	 *
	 * @param array<string,mixed>                                   $ballot Ballot with options and rules
	 * @param array{counts:array<string,int>,valid:int,abstain:int} $tally  Votes per option
	 * @param array<string,mixed>                                   $quorum Quorum when the ballot opened, with reached
	 * @return array{outcome:string,passed:bool,yes:int,no:int,winner:string,candidate_id:int,majority:string}
	 */
	public static function outcome(array $ballot, array $tally, array $quorum)
	{
		$counts = $tally['counts'];
		$majority = isset($ballot['rules']['majority']) && in_array($ballot['rules']['majority'], VereineStatuteRules::MAJORITIES, true)
			? (string) $ballot['rules']['majority'] : VereineStatuteRules::MAJORITY_SIMPLE;
		$candidates = array();
		foreach ($ballot['options'] as $option) {
			if ((int) $option['member_id'] > 0) {
				$candidates[(string) $option['code']] = (int) $option['member_id'];
			}
		}
		$winner = '';
		if (count($candidates) === 1) {
			$winner = (string) key($candidates);
			$yes = isset($counts[$winner]) ? (int) $counts[$winner] : 0;
			$no = isset($counts[self::NO]) ? (int) $counts[self::NO] : 0;
		} elseif ($candidates) {
			$best = -1;
			foreach (array_keys($candidates) as $code) {
				$votes = isset($counts[$code]) ? (int) $counts[$code] : 0;
				if ($votes > $best) {
					$best = $votes;
					$winner = $code;
				} elseif ($votes === $best) {
					// Two with the most votes: nobody is elected.
					$winner = '';
				}
			}
			$yes = max(0, $best);
			$no = (int) $tally['valid'] - $yes;
		} else {
			$yes = isset($counts[self::YES]) ? (int) $counts[self::YES] : 0;
			$no = isset($counts[self::NO]) ? (int) $counts[self::NO] : 0;
		}
		$decided = VereineVoteRules::result(array('yes' => $yes, 'no' => $no, 'tie' => ''), $majority, VereineMeetingRules::KIND_GENERAL, array());
		$passed = !empty($quorum['reached']) && $decided['passed'] && ($candidates === array() || $winner !== '');
		if (empty($quorum['reached'])) {
			$outcome = 'no_quorum';
		} elseif ($passed) {
			$outcome = 'passed';
		} else {
			$outcome = count($candidates) > 1 ? 'no_majority' : 'rejected';
		}
		return array('outcome' => $outcome, 'passed' => $passed, 'yes' => $yes, 'no' => $no, 'winner' => $passed ? $winner : '',
			'candidate_id' => $passed && $winner !== '' ? $candidates[$winner] : 0, 'majority' => $majority);
	}
}
