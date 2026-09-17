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
 * \file    class/vereineattendancerules.class.php
 * \ingroup vereine
 * \brief   Who is at a meeting, who votes for whom, and whether the meeting has its quorum, plain PHP.
 *
 * Times are strings HH:MM on the day of the meeting.
 */

require_once __DIR__.'/vereinestatuterules.class.php';
require_once __DIR__.'/vereinemeetingrules.class.php';

/**
 * Rules of attendance, proxies and quorum.
 */
class VereineAttendanceRules
{
	/** At the meeting. */
	const STATE_PRESENT = 'present';
	/** Excused. */
	const STATE_EXCUSED = 'excused';
	/** Not there without excuse, or not recorded yet. */
	const STATE_ABSENT = 'absent';
	/** Not there, but gave a written proxy to another member. */
	const STATE_REPRESENTED = 'represented';
	/** All states in the order they are offered. */
	const STATES = array('absent', 'present', 'excused', 'represented');

	/**
	 * Attendance entered for the invited members that can be used as it is.
	 *
	 * @param mixed    $data    Entered rows by member id, keys state, holder, arrived, left
	 * @param int[]    $invited Invited member ids
	 * @return array<int,array{state:string,holder:int,arrived:string,left:string}> By member id, every invited member once
	 */
	public static function normalize($data, array $invited)
	{
		$data = is_array($data) ? $data : array();
		$time = function ($value) {
			$value = is_scalar($value) ? trim((string) $value) : '';
			return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : '';
		};
		$attendance = array();
		foreach ($invited as $memberId) {
			$row = isset($data[$memberId]) && is_array($data[$memberId]) ? $data[$memberId] : array();
			$state = isset($row['state']) && in_array($row['state'], self::STATES, true) ? $row['state'] : self::STATE_ABSENT;
			$attendance[(int) $memberId] = array(
				'state' => $state,
				'holder' => $state === self::STATE_REPRESENTED && isset($row['holder']) && is_numeric($row['holder']) ? (int) $row['holder'] : 0,
				'arrived' => $state === self::STATE_PRESENT && isset($row['arrived']) ? $time($row['arrived']) : '',
				'left' => $state === self::STATE_PRESENT && isset($row['left']) ? $time($row['left']) : '',
			);
		}
		return $attendance;
	}

	/**
	 * Problems of an attendance list before it is stored.
	 *
	 * A proxy needs the statutes to allow it, is not possible in a board meeting (board functions are
	 * exercised in person), goes from a voting member to another voting member who is present, and
	 * a member who holds a proxy cannot pass it on.
	 *
	 * @param string                                   $kind       Kind of the meeting
	 * @param array<int,array<string,mixed>>           $attendance Normalized attendance by member id
	 * @param array<int,bool>                          $voting     Voting right by invited member id
	 * @param array<string,mixed>                      $rules      Normalized rules of the statutes
	 * @return array<int,string[]> Language keys by member id, empty when fine
	 */
	public static function validate($kind, array $attendance, array $voting, array $rules)
	{
		$errors = array();
		foreach ($attendance as $memberId => $row) {
			$problems = array();
			if ($row['arrived'] !== '' && $row['left'] !== '' && $row['left'] <= $row['arrived']) {
				$problems[] = 'VereineAttendanceErrorTimes';
			}
			if ($row['state'] === self::STATE_REPRESENTED) {
				$holder = $row['holder'];
				if ($kind === VereineMeetingRules::KIND_BOARD) {
					$problems[] = 'VereineAttendanceErrorProxyBoard';
				} elseif (empty($rules['proxy'])) {
					$problems[] = 'VereineAttendanceErrorProxyStatutes';
				} elseif (empty($voting[$memberId]) || $holder === (int) $memberId || !isset($attendance[$holder]) || empty($voting[$holder])) {
					$problems[] = 'VereineAttendanceErrorProxyHolder';
				} elseif ($attendance[$holder]['state'] !== self::STATE_PRESENT) {
					$problems[] = 'VereineAttendanceErrorProxyAbsent';
				}
			}
			if ($problems) {
				$errors[(int) $memberId] = $problems;
			}
		}
		return $errors;
	}

	/**
	 * Whether a member is in the room at a time.
	 *
	 * @param array<string,mixed> $row  Attendance of the member
	 * @param string              $time Time HH:MM, empty for the whole meeting
	 * @return bool
	 */
	public static function presentAt(array $row, $time)
	{
		if ($row['state'] !== self::STATE_PRESENT) {
			return false;
		}
		if ((string) $time === '') {
			return $row['left'] === '';
		}
		return ($row['arrived'] === '' || $row['arrived'] <= $time) && ($row['left'] === '' || $row['left'] > $time);
	}

	/**
	 * Votes and quorum at a time.
	 *
	 * General assembly: the votes of the members present plus those they represent, against the share
	 * of all voting members the statutes ask for (none: regardless of the number present). Board: all
	 * board members were invited, and at least the share the statutes ask for is present in person.
	 *
	 * @param string                         $kind       Kind of the meeting
	 * @param array<int,array<string,mixed>> $attendance Normalized attendance by member id
	 * @param array<int,bool>                $voting     Voting right by invited member id
	 * @param array<string,mixed>            $rules      Normalized rules of the statutes
	 * @param string                         $time       Time HH:MM, empty for now
	 * @return array{eligible:int,present:int,represented:int,votes:int,required:int,reached:bool}
	 */
	public static function quorum($kind, array $attendance, array $voting, array $rules, $time = '')
	{
		$eligible = 0;
		$present = 0;
		$represented = 0;
		foreach ($attendance as $memberId => $row) {
			if (empty($voting[$memberId])) {
				continue;
			}
			$eligible++;
			if (self::presentAt($row, $time)) {
				$present++;
			} elseif ($row['state'] === self::STATE_REPRESENTED && $kind !== VereineMeetingRules::KIND_BOARD && !empty($rules['proxy'])
				&& isset($attendance[$row['holder']]) && self::presentAt($attendance[$row['holder']], $time) && !empty($voting[$row['holder']])) {
				$represented++;
			}
		}
		if ($kind === VereineMeetingRules::KIND_BOARD) {
			$required = (int) ceil($eligible * (int) $rules['board_quorum'] / 100);
			return array('eligible' => $eligible, 'present' => $present, 'represented' => 0, 'votes' => $present, 'required' => $required,
				'reached' => $eligible > 0 && $present >= max(1, $required));
		}
		$required = (int) ceil($eligible * (int) $rules['general_quorum'] / 100);
		return array('eligible' => $eligible, 'present' => $present, 'represented' => $represented, 'votes' => $present + $represented, 'required' => $required,
			'reached' => $present > 0 && $present + $represented >= $required);
	}
}
