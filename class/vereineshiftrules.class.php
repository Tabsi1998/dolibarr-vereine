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
 * \file    class/vereineshiftrules.class.php
 * \ingroup vereine
 * \brief   Helper shifts of an event (#23): how many people a shift takes and who may take it, plain PHP.
 *
 * Signing up is not the same as being taken, and being taken is not the same as having been there. The
 * three states stay apart, because only the last one says anything about a volunteer allowance (#7).
 */

/**
 * Rules of the helper shifts.
 */
class VereineShiftRules
{
	/** Somebody asked for the shift. */
	const STATUS_REQUESTED = 'requested';
	/** The association took them for it. */
	const STATUS_CONFIRMED = 'confirmed';
	/** They really were there; this is what an allowance may build on. */
	const STATUS_DONE = 'done';
	/** They are not on the shift after all. */
	const STATUS_CANCELLED = 'cancelled';

	/** Every status an entry may have. */
	const STATUSES = array('requested', 'confirmed', 'done', 'cancelled');

	/** The statuses that fill a place on the shift. */
	const TAKING = array('confirmed', 'done');

	/**
	 * What is wrong with a shift before it is stored.
	 *
	 * @param array<string,mixed> $data Keys label, shift_day, start_time, end_time, capacity
	 * @return string[] Language keys, empty when fine
	 */
	public static function validate(array $data)
	{
		$errors = array();
		$value = function ($key) use ($data) {
			return isset($data[$key]) ? trim((string) $data[$key]) : '';
		};
		$label = $value('label');
		if ($label === '' || mb_strlen($label, 'UTF-8') > 255) {
			$errors[] = 'VereineShiftErrorLabel';
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value('shift_day'))) {
			$errors[] = 'VereineShiftErrorDay';
		}
		$start = $value('start_time');
		$end = $value('end_time');
		foreach (array($start, $end) as $time) {
			if ($time !== '' && !self::isTime($time)) {
				$errors[] = 'VereineShiftErrorTime';
				break;
			}
		}
		if ($start !== '' && $end !== '' && self::isTime($start) && self::isTime($end) && $end <= $start) {
			$errors[] = 'VereineShiftErrorEndTime';
		}
		$capacity = $value('capacity');
		if ($capacity === '' || !preg_match('/^\d{1,3}$/', $capacity) || (int) $capacity < 1) {
			$errors[] = 'VereineShiftErrorCapacity';
		}
		return $errors;
	}

	/**
	 * Whether a text is a time of day.
	 *
	 * @param string $time Time as HH:MM
	 * @return bool
	 */
	public static function isTime($time)
	{
		return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $time);
	}

	/**
	 * How many places of a shift are taken and how many are left.
	 *
	 * @param int                            $capacity How many people the shift takes
	 * @param array<int,array<string,mixed>> $entries  Entries with key status
	 * @return array{taken:int,free:int,requested:int,done:int,full:bool}
	 */
	public static function places($capacity, array $entries)
	{
		$taken = 0;
		$requested = 0;
		$done = 0;
		foreach ($entries as $entry) {
			$status = (string) $entry['status'];
			if (in_array($status, self::TAKING, true)) {
				$taken++;
			}
			if ($status === self::STATUS_REQUESTED) {
				$requested++;
			}
			if ($status === self::STATUS_DONE) {
				$done++;
			}
		}
		$capacity = max(0, (int) $capacity);
		return array('taken' => $taken, 'free' => max(0, $capacity - $taken), 'requested' => $requested,
			'done' => $done, 'full' => $taken >= $capacity);
	}

	/**
	 * Whether two shifts run at the same time, so one person cannot do both.
	 *
	 * A shift without times counts as the whole day.
	 *
	 * @param array<string,mixed> $left  Shift with keys shift_day, start_time, end_time
	 * @param array<string,mixed> $right The other shift
	 * @return bool
	 */
	public static function overlap(array $left, array $right)
	{
		if ((string) $left['shift_day'] !== (string) $right['shift_day']) {
			return false;
		}
		$span = function (array $shift) {
			$start = self::isTime((string) $shift['start_time']) ? (string) $shift['start_time'] : '00:00';
			$end = self::isTime((string) $shift['end_time']) ? (string) $shift['end_time'] : '23:59';
			return array($start, $end);
		};
		list($leftStart, $leftEnd) = $span($left);
		list($rightStart, $rightEnd) = $span($right);
		// Touching at the edge is not an overlap: one shift ends when the next begins.
		return $leftStart < $rightEnd && $rightStart < $leftEnd;
	}

	/**
	 * Whether a member may be put on a shift, and why not.
	 *
	 * @param array<string,mixed>            $shift    The shift with keys capacity, shift_day, start_time, end_time
	 * @param array<int,array<string,mixed>> $entries  Entries of this shift
	 * @param array<int,array<string,mixed>> $elsewhere Shifts the member already takes, with their times
	 * @param int                            $memberId Member
	 * @param string                         $status   Status the entry is to get
	 * @return string Empty when fine, otherwise a language key
	 */
	public static function refuse(array $shift, array $entries, array $elsewhere, $memberId, $status)
	{
		if (!in_array((string) $status, self::STATUSES, true)) {
			return 'VereineShiftErrorStatus';
		}
		foreach ($entries as $entry) {
			if ((int) $entry['member_id'] === (int) $memberId && (string) $entry['status'] !== self::STATUS_CANCELLED) {
				return 'VereineShiftErrorTwice';
			}
		}
		if (in_array((string) $status, self::TAKING, true) && self::places($shift['capacity'], $entries)['full']) {
			return 'VereineShiftErrorFull';
		}
		foreach ($elsewhere as $other) {
			if (self::overlap($shift, $other)) {
				return 'VereineShiftErrorOverlap';
			}
		}
		return '';
	}

	/**
	 * How many hours a shift lasts, 0 when it has no times.
	 *
	 * @param array<string,mixed> $shift Shift with keys start_time, end_time
	 * @return float
	 */
	public static function hours(array $shift)
	{
		if (!self::isTime((string) $shift['start_time']) || !self::isTime((string) $shift['end_time'])) {
			return 0.0;
		}
		$minutes = ((int) substr($shift['end_time'], 0, 2) * 60 + (int) substr($shift['end_time'], 3, 2))
			- ((int) substr($shift['start_time'], 0, 2) * 60 + (int) substr($shift['start_time'], 3, 2));
		return $minutes > 0 ? round($minutes / 60, 2) : 0.0;
	}
}
