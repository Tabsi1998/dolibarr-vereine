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
 * \file    class/vereineassemblyrules.class.php
 * \ingroup vereine
 * \brief   The way through a general assembly (#127): what comes before, on the day and after it, plain PHP.
 *
 * Everything a general assembly needs already exists in the module. What was missing is the order: the
 * account before the audit, the audit report before the invitation, the election before the notice to
 * the authority. This works out, from facts the module already keeps, which step is done, which one is
 * next and which one is late.
 */

/**
 * Rules of the way through a general assembly.
 */
class VereineAssemblyRules
{
	/** Everything that has to be ready before the day. */
	const PHASE_BEFORE = 'before';
	/** What happens at the assembly itself. */
	const PHASE_MEETING = 'meeting';
	/** What follows from it. */
	const PHASE_AFTER = 'after';

	/** The phases in their order. */
	const PHASES = array('before', 'meeting', 'after');

	/** The steps, each in its phase, in the order they are done. */
	const STEPS = array(
		'account' => 'before',
		'audit' => 'before',
		'auditreport' => 'before',
		'elections' => 'before',
		'agenda' => 'before',
		'invitation' => 'before',
		'motions' => 'before',
		'documents' => 'before',
		'attendance' => 'meeting',
		'votes' => 'meeting',
		'minutes' => 'after',
		'signatures' => 'after',
		'resolutions' => 'after',
		'authority' => 'after',
		'statutes' => 'after',
		'groups' => 'after',
		'inform' => 'after',
	);

	/** The step is finished. */
	const STATE_DONE = 'done';
	/** Its day has passed and it is not finished. */
	const STATE_OVERDUE = 'overdue';
	/** It is what to do now. */
	const STATE_NOW = 'now';
	/** Its turn has not come yet. */
	const STATE_LATER = 'later';
	/** It does not apply to this assembly. */
	const STATE_NONE = 'none';

	/**
	 * Every step of an assembly with how it stands, from facts the module already keeps.
	 *
	 * @param array<string,mixed> $facts Keys: day, held (bool), account_made, account_deadline, audit_day,
	 *                                   audit_deadline, audit_report_signed (bool), elections_due (int),
	 *                                   agenda_missing (int), invited_on, invite_deadline, motions_deadline,
	 *                                   sheets (int), elections_on_agenda (int), attendance (int), votes (int),
	 *                                   minutes_final (bool), minutes_signed (bool), resolutions (int),
	 *                                   resolution_pdfs (int), authority_open (int), authority_deadline,
	 *                                   statute_change (bool), statute_letter (bool), group_changes (int),
	 *                                   minutes_sent (bool)
	 * @param string              $today Today, YYYY-MM-DD
	 * @return array<int,array{code:string,phase:string,state:string,deadline:string,detail:string}> In the order of the steps
	 */
	public static function check(array $facts, $today)
	{
		$fact = function ($key, $default = '') use ($facts) {
			return isset($facts[$key]) ? $facts[$key] : $default;
		};
		$day = (string) $fact('day');
		$held = !empty($facts['held']);
		$steps = array();
		$add = function ($code, $done, $deadline = '', $detail = '', $applies = true) use (&$steps, $today, $day, $held) {
			$phase = self::STEPS[$code];
			if (!$applies) {
				$steps[] = array('code' => $code, 'phase' => $phase, 'state' => self::STATE_NONE, 'deadline' => '', 'detail' => $detail);
				return;
			}
			if ($done) {
				$state = self::STATE_DONE;
			} elseif ((string) $deadline !== '' && (string) $deadline < (string) $today) {
				$state = self::STATE_OVERDUE;
			} else {
				$state = self::started($phase, $day, $today, $held) ? self::STATE_NOW : self::STATE_LATER;
			}
			$steps[] = array('code' => $code, 'phase' => $phase, 'state' => $state, 'deadline' => (string) $deadline, 'detail' => (string) $detail);
		};

		// Before: the figures of the year that ended, then the people, then the invitation.
		$made = (string) $fact('account_made');
		$audit = (string) $fact('audit_day');
		$add('account', $made !== '', (string) $fact('account_deadline'), $made);
		$add('audit', $audit !== '', (string) $fact('audit_deadline'), $audit);
		$add('auditreport', !empty($facts['audit_report_signed']));
		$elections = (int) $fact('elections_due', 0);
		$add('elections', $elections === 0, '', (string) $elections);
		$missing = (int) $fact('agenda_missing', 0);
		$add('agenda', $missing === 0, '', (string) $missing);
		$invited = (string) $fact('invited_on');
		$inviteDeadline = (string) $fact('invite_deadline');
		// An invitation that went out too late is not undone by sending it; it stays a fault of this assembly.
		$add('invitation', $invited !== '' && ($inviteDeadline === '' || $invited <= $inviteDeadline), $inviteDeadline, $invited);
		$motions = (string) $fact('motions_deadline');
		$add('motions', $motions !== '' && $motions < (string) $today, '', $motions, $motions !== '');
		$onAgenda = (int) $fact('elections_on_agenda', 0);
		$add('documents', (int) $fact('sheets', 0) > 0, '', (string) $fact('sheets', 0), $onAgenda > 0);

		// The day itself.
		$add('attendance', (int) $fact('attendance', 0) > 0, '', (string) $fact('attendance', 0));
		$add('votes', (int) $fact('votes', 0) > 0, '', (string) $fact('votes', 0));

		// Afterwards: the minutes, what follows from the resolutions, and the members.
		$add('minutes', !empty($facts['minutes_final']));
		$add('signatures', !empty($facts['minutes_signed']));
		$resolutions = (int) $fact('resolutions', 0);
		$add('resolutions', $resolutions > 0 && (int) $fact('resolution_pdfs', 0) >= $resolutions, '',
			$fact('resolution_pdfs', 0).'/'.$resolutions, $resolutions > 0);
		$authority = (int) $fact('authority_open', 0);
		$add('authority', $authority === 0, (string) $fact('authority_deadline'), (string) $authority, $held || $authority > 0);
		$add('statutes', !empty($facts['statute_letter']), '', '', !empty($facts['statute_change']));
		$groups = (int) $fact('group_changes', 0);
		$add('groups', $groups === 0, '', (string) $groups, $held || $groups > 0);
		$add('inform', !empty($facts['minutes_sent']));
		return $steps;
	}

	/**
	 * Whether the turn of a phase has come.
	 *
	 * @param string $phase One of PHASES
	 * @param string $day   Day of the assembly
	 * @param string $today Today
	 * @param bool   $held  Whether the assembly took place
	 * @return bool
	 */
	public static function started($phase, $day, $today, $held)
	{
		if ($phase === self::PHASE_BEFORE) {
			return true;
		}
		if ($phase === self::PHASE_MEETING) {
			return $held || ((string) $day !== '' && (string) $day <= (string) $today);
		}
		return $held || ((string) $day !== '' && (string) $day < (string) $today);
	}

	/**
	 * How far an assembly has come, and what is late.
	 *
	 * @param array<int,array<string,mixed>> $steps Steps as check() returns them
	 * @return array{total:int,done:int,overdue:int,now:int,percent:int}
	 */
	public static function progress(array $steps)
	{
		$counts = array('total' => 0, 'done' => 0, 'overdue' => 0, 'now' => 0);
		foreach ($steps as $step) {
			if ($step['state'] === self::STATE_NONE) {
				continue;
			}
			$counts['total']++;
			if ($step['state'] === self::STATE_DONE) {
				$counts['done']++;
			} elseif ($step['state'] === self::STATE_OVERDUE) {
				$counts['overdue']++;
			} elseif ($step['state'] === self::STATE_NOW) {
				$counts['now']++;
			}
		}
		$counts['percent'] = $counts['total'] > 0 ? (int) round($counts['done'] * 100 / $counts['total']) : 0;
		return $counts;
	}

	/**
	 * The steps that still wait, most urgent first, for the overview and the calendar of duties.
	 *
	 * @param array<int,array<string,mixed>> $steps Steps as check() returns them
	 * @return array<int,array<string,mixed>> Only overdue ones and the ones to do now
	 */
	public static function open(array $steps)
	{
		$open = array();
		foreach ($steps as $step) {
			if ($step['state'] === self::STATE_OVERDUE || $step['state'] === self::STATE_NOW) {
				$open[] = $step;
			}
		}
		usort($open, function ($left, $right) {
			if (($left['state'] === self::STATE_OVERDUE) !== ($right['state'] === self::STATE_OVERDUE)) {
				return $left['state'] === self::STATE_OVERDUE ? -1 : 1;
			}
			return strcmp($left['deadline'] !== '' ? $left['deadline'] : '9999', $right['deadline'] !== '' ? $right['deadline'] : '9999');
		});
		return $open;
	}
}
