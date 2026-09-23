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
 * \file    class/vereineerasurerules.class.php
 * \ingroup vereine
 * \brief   Rules of erasing a former member's data (#10): what is kept how long and why, and what is due.
 *
 * Every kind of data has its own period and its own start: contact data are needed while somebody is a
 * member, bookkeeping for seven years after its year, proofs of consent as long as a claim could come.
 * Club records (minutes, resolutions, signatures, functions) are never changed; they are the history of
 * the association. The name stays as long as anything kept still needs it.
 */

/**
 * Rules of erasing a former member's data.
 */
class VereineErasureRules
{
	/** Removed from the member: address, phones, e-mail, birth, photo, own fields. */
	const ACTION_BLANK = 'blank';

	/** Rows deleted. */
	const ACTION_DELETE = 'delete';

	/** The member's name replaced, the record itself stays for what refers to it. */
	const ACTION_ANONYMIZE = 'anonymize';

	/** Never changed by the module. */
	const ACTION_KEEP = 'keep';

	/** The period starts with the end of the membership. */
	const START_EXIT = 'exit';

	/** The period starts with the end of the calendar year of the last entry. */
	const START_YEAR = 'year';

	/** The period starts with each entry on its own. */
	const START_ENTRY = 'entry';

	/**
	 * The kinds of data of a former member, in the order of the preview.
	 *
	 * years: default period, null for never; setting: whether the association may choose another period.
	 */
	const CATEGORIES = array(
		'identities' => array('action' => 'delete', 'start' => 'exit', 'years' => 0, 'setting' => false),
		'contact' => array('action' => 'blank', 'start' => 'exit', 'years' => 0, 'setting' => true),
		'invitations' => array('action' => 'blank', 'start' => 'entry', 'years' => 1, 'setting' => true),
		'tasks' => array('action' => 'delete', 'start' => 'exit', 'years' => 1, 'setting' => true),
		'consents' => array('action' => 'delete', 'start' => 'exit', 'years' => 3, 'setting' => true),
		'applications' => array('action' => 'delete', 'start' => 'exit', 'years' => 3, 'setting' => true),
		'disclosures' => array('action' => 'delete', 'start' => 'entry', 'years' => 3, 'setting' => true),
		'log' => array('action' => 'delete', 'start' => 'exit', 'years' => 3, 'setting' => true),
		'volunteer' => array('action' => 'delete', 'start' => 'year', 'years' => 7, 'setting' => false),
		'donations' => array('action' => 'delete', 'start' => 'year', 'years' => 7, 'setting' => false),
		'bookkeeping' => array('action' => 'keep', 'start' => 'year', 'years' => 7, 'setting' => false),
		'records' => array('action' => 'keep', 'start' => 'exit', 'years' => null, 'setting' => false),
		'name' => array('action' => 'anonymize', 'start' => 'exit', 'years' => 0, 'setting' => false),
	);

	/** Kinds whose data still need the member's name while they are kept. */
	const NEED_NAME = array('volunteer', 'donations', 'bookkeeping');

	/** Longest period an association may choose, in years. */
	const MAX_YEARS = 30;

	/**
	 * The periods in force: the defaults, with what the association chose where it may choose.
	 *
	 * @param array<string,mixed> $chosen Kind => years, as stored
	 * @return array<string,int|null>
	 */
	public static function periods(array $chosen)
	{
		$periods = array();
		foreach (self::CATEGORIES as $kind => $rule) {
			$periods[$kind] = $rule['years'];
			if ($rule['setting'] && isset($chosen[$kind]) && preg_match('/^\d{1,2}$/', (string) $chosen[$kind]) && (int) $chosen[$kind] <= self::MAX_YEARS) {
				$periods[$kind] = (int) $chosen[$kind];
			}
		}
		return $periods;
	}

	/**
	 * Check periods as entered in the setup.
	 *
	 * @param array<string,mixed> $entered Kind => years
	 * @return array{periods:array<string,int>,errors:string[]}
	 */
	public static function checkPeriods(array $entered)
	{
		$periods = array();
		$errors = array();
		foreach (self::CATEGORIES as $kind => $rule) {
			if (!$rule['setting']) {
				continue;
			}
			$value = isset($entered[$kind]) ? trim((string) $entered[$kind]) : '';
			if (!preg_match('/^\d{1,2}$/', $value) || (int) $value > self::MAX_YEARS) {
				$errors[] = $kind;
				continue;
			}
			$periods[$kind] = (int) $value;
		}
		return array('periods' => $periods, 'errors' => $errors);
	}

	/**
	 * The day a period ends.
	 *
	 * @param string   $start Kind of start, START_*
	 * @param string   $day   The day the period counts from (exit, or the last entry), YYYY-MM-DD
	 * @param int|null $years Period, null for never
	 * @return string YYYY-MM-DD, empty for never
	 */
	public static function until($start, $day, $years)
	{
		if ($years === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $day, $parts)) {
			return '';
		}
		if ($start === self::START_YEAR) {
			return sprintf('%04d-12-31', (int) $parts[1] + $years);
		}
		$year = (int) $parts[1] + $years;
		// 29 February has no match in most years; the period ends a day later then.
		if ((int) $parts[2] === 2 && (int) $parts[3] === 29 && !checkdate(2, 29, $year)) {
			return sprintf('%04d-03-01', $year);
		}
		return sprintf('%04d-%s-%s', $year, $parts[2], $parts[3]);
	}

	/**
	 * What is due for a former member, kind by kind.
	 *
	 * @param array<string,array{count:int,last:string,due:int}> $found   Kind => rows found, the last entry's day, rows whose
	 *                                                                    own period is over (entry kinds)
	 * @param string                                             $exitDay End of the membership, YYYY-MM-DD, empty while a member
	 * @param string                                             $today   Today, YYYY-MM-DD
	 * @param array<string,int|null>                             $periods From periods()
	 * @param array<string,bool>                                 $holds   'open_invoices', 'hold' (kept on purpose), 'functions'
	 * @return array<string,array{action:string,count:int,until:string,state:string,reason:string}>
	 */
	public static function plan(array $found, $exitDay, $today, array $periods, array $holds)
	{
		$plan = array();
		$nameUntil = $exitDay;
		foreach (self::CATEGORIES as $kind => $rule) {
			$count = isset($found[$kind]) ? (int) $found[$kind]['count'] : 0;
			$last = isset($found[$kind]) ? (string) $found[$kind]['last'] : '';
			$years = array_key_exists($kind, $periods) ? $periods[$kind] : $rule['years'];
			$from = $rule['start'] === self::START_YEAR ? ($last !== '' ? $last : $exitDay) : $exitDay;
			$until = $rule['start'] === self::START_ENTRY ? self::until(self::START_EXIT, $last, $years) : self::until($rule['start'], $from, $years);
			$state = 'due';
			$reason = '';
			if ($rule['action'] === self::ACTION_KEEP) {
				$state = 'kept';
				$reason = 'keep_'.$kind;
			} elseif ($exitDay === '') {
				$state = 'member';
				$reason = 'member';
			} elseif (!empty($holds['hold'])) {
				$state = 'held';
				$reason = 'hold';
			} elseif (!empty($holds['open_invoices']) && $kind !== 'identities') {
				$state = 'held';
				$reason = 'open_invoices';
			} elseif ($kind === 'name' && !empty($holds['functions'])) {
				$state = 'kept';
				$reason = 'functions';
			} elseif ($count === 0) {
				$state = 'none';
			} elseif ($rule['start'] === self::START_ENTRY) {
				// Entries end one by one: some may be due, the rest wait for their own day.
				$due = isset($found[$kind]['due']) ? (int) $found[$kind]['due'] : 0;
				$state = $due > 0 ? 'due' : 'waiting';
				$count = $due > 0 ? $due : $count;
				$until = $due > 0 ? '' : $until;
			} elseif ($until !== '' && $until > $today) {
				$state = 'waiting';
			}
			if (in_array($kind, self::NEED_NAME, true) && $count > 0 && $until > $nameUntil) {
				$nameUntil = $until;
			}
			if ($kind === 'name' && $state === 'due' && $nameUntil > $today) {
				$state = 'waiting';
				$until = $nameUntil;
				$reason = 'name';
			}
			$plan[$kind] = array('action' => $rule['action'], 'count' => $count, 'until' => $state === 'due' ? '' : $until, 'state' => $state, 'reason' => $reason);
		}
		return $plan;
	}

	/**
	 * The kinds that can be carried out now.
	 *
	 * @param array<string,array{state:string}> $plan From plan()
	 * @return string[]
	 */
	public static function due(array $plan)
	{
		$due = array();
		foreach ($plan as $kind => $step) {
			if ($step['state'] === 'due') {
				$due[] = $kind;
			}
		}
		return $due;
	}
}
