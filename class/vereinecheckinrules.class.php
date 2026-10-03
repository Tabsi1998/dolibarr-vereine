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
 * \file    class/vereinecheckinrules.class.php
 * \ingroup vereine
 * \brief   Rules of the check-in at a general assembly through an application (#272), plain PHP.
 *
 * At the entrance an application scans a membership card and marks the member present, in the name of somebody
 * on the board. It is a fact the board records, not a decision: the same request twice changes nothing, a check-in
 * taken back needs a reason, and only an assembly that is open on its day takes any of it.
 */

/**
 * Rules of the check-in.
 */
class VereineCheckInRules
{
	/** The member came in. */
	const ACTION_PRESENT = 'present';
	/** A check-in taken back, with a reason. */
	const ACTION_REVOKED = 'revoked';

	/** Longest id of a request of an application. */
	const EXTERNAL_ID_MAX = 64;

	/** Longest reason. */
	const REASON_MAX = 255;

	/**
	 * What stops a check-in, as a code the API turns into its answer.
	 *
	 * @param array<string,mixed>|null $meeting  The meeting, null when there is none
	 * @param string                   $today    Today, YYYY-MM-DD
	 * @param bool                     $invited  Whether the member was invited
	 * @param bool                     $onBoard  Whether the person checking in is on the board today
	 * @return string not_found, not_board, not_invited, not_today, not_open, or '' when it may happen
	 */
	public static function problem($meeting, $today, $invited, $onBoard)
	{
		if (!is_array($meeting) || (string) $meeting['kind'] === VereineMeetingRules::KIND_BOARD) {
			return 'not_found';
		}
		// Whoever is not on the board learns nothing about who was invited.
		if (!$onBoard) {
			return 'not_board';
		}
		if (!$invited) {
			return 'not_invited';
		}
		if ((string) $meeting['day'] !== (string) $today) {
			return 'not_today';
		}
		if ((string) $meeting['status'] !== VereineMeetingRules::STATUS_INVITED) {
			return 'not_open';
		}
		return '';
	}

	/**
	 * The status of the answer for each problem.
	 *
	 * @param string $problem Code of problem()
	 * @return int
	 */
	public static function status($problem)
	{
		$statuses = array('not_found' => 404, 'not_invited' => 404, 'not_board' => 403, 'not_today' => 409, 'not_open' => 409, 'external_id' => 409,
			'external_id_missing' => 400, 'reason_missing' => 400, 'time' => 400);
		return isset($statuses[$problem]) ? $statuses[$problem] : 400;
	}

	/**
	 * The id of a request as an application sends it: trimmed, at most so long, empty when there is none.
	 *
	 * @param mixed $value Sent
	 * @return string
	 */
	public static function externalId($value)
	{
		$value = is_scalar($value) ? trim((string) $value) : '';
		return mb_strlen($value, 'UTF-8') <= self::EXTERNAL_ID_MAX && preg_match('/^[A-Za-z0-9._:-]+$/', $value) ? $value : '';
	}

	/**
	 * The time of arrival: the one sent as HH:MM, or now; null when what was sent is no time.
	 *
	 * @param mixed  $sent Sent, empty for now
	 * @param string $now  Now, HH:MM
	 * @return string|null
	 */
	public static function arrival($sent, $now)
	{
		$sent = is_scalar($sent) ? trim((string) $sent) : '';
		if ($sent === '') {
			return (string) $now;
		}
		return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $sent) ? $sent : null;
	}

	/**
	 * Whether a request seen before is the same as the one sent now: same assembly, member and action.
	 *
	 * @param array<string,mixed> $known Earlier request: meeting_id, member_id, action
	 * @param int                 $meetingId Meeting now
	 * @param int                 $memberId  Member now
	 * @param string              $action    Action now
	 * @return bool
	 */
	public static function sameRequest(array $known, $meetingId, $memberId, $action)
	{
		return (int) $known['meeting_id'] === (int) $meetingId && (int) $known['member_id'] === (int) $memberId && (string) $known['action'] === (string) $action;
	}

	/**
	 * The answer an application gets: the member's state and voting right with its reason, and the numbers of the assembly.
	 *
	 * @param int                           $memberId Member
	 * @param array<string,mixed>           $row      Normalized attendance row of the member
	 * @param array{eligible:bool,reason:string} $right Voting right as VereineBallotRules::right() works it out while present
	 * @param array<string,mixed>           $quorum   Numbers of VereineAttendanceRules::quorum()
	 * @return array<string,mixed>
	 */
	public static function answer($memberId, array $row, array $right, array $quorum)
	{
		$present = (string) $row['state'] === VereineAttendanceRules::STATE_PRESENT;
		return array(
			'member_id' => (int) $memberId,
			'state' => (string) $row['state'],
			'arrived' => $present ? (string) $row['arrived'] : '',
			'voting' => $present && !empty($right['eligible']),
			'reason' => $present ? (string) $right['reason'] : 'absent',
			'present' => (int) $quorum['present'],
			'eligible' => (int) $quorum['eligible'],
			'quorum_from' => (int) $quorum['required'],
			'quorum_reached' => (bool) $quorum['reached'],
		);
	}
}
