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
 * \file    class/vereinearrearrules.class.php
 * \ingroup vereine
 * \brief   Rules of fee arrears reported by the Mahnwesen module (#17): what an event of a dunning case means here.
 *
 * The Mahnwesen module dunns; the association decides. When a membership fee reaches the last step
 * "Mitgliedschaft prüfen", the board gets one proposal for its next meeting, nothing more: no exclusion,
 * no lost right to vote. A payment, a pause or a closed case updates the proposal instead of escalating
 * a debt that is gone.
 */

/**
 * Rules of fee arrears.
 */
class VereineArrearRules
{
	/** Waiting for the board. */
	const STATE_OPEN = 'open';

	/** The dunning is paused; the board need not look now. */
	const STATE_PAUSED = 'paused';

	/** Paid or closed: nothing left to decide. */
	const STATE_SETTLED = 'settled';

	/** Handed over for collection: not a matter of membership any more. */
	const STATE_HANDED_OVER = 'handed_over';

	/** States in the order of the list. */
	const STATES = array('open', 'paused', 'settled', 'handed_over');

	/** The version of the Mahnwesen event contract understood here. */
	const CONTRACT = '1';

	/** The last step of a dunning profile that asks for the membership to be looked at. */
	const FINAL_STEP = 'membership_review';

	/** Events of the Mahnwesen module this module listens to. */
	const EVENTS = array('MAHNWESEN_CASE_FINAL_STAGE', 'MAHNWESEN_CASE_CLOSED', 'MAHNWESEN_CASE_PAUSED', 'MAHNWESEN_CASE_RESUMED',
		'MAHNWESEN_CASE_REOPENED', 'MAHNWESEN_CASE_HANDED_OVER');

	/**
	 * What an event means for the arrear of its case.
	 *
	 * @param array{state:string,revision:int}|null $stored The arrear kept for the case, null when there is none
	 * @param array{type:string,revision:int}       $event  The event: its type and the case revision it belongs to
	 * @param array<string,mixed>                   $facts  fee (the invoice is a membership fee by Dolibarr's own link),
	 *                                                       final_step (of the profile), case_status and paused (as read now),
	 *                                                       paid (the invoice as read now)
	 * @return array{do:string,state:string,why:string} do: create, update or ignore
	 */
	public static function decide($stored, array $event, array $facts)
	{
		$ignore = function ($why) use ($stored) {
			return array('do' => 'ignore', 'state' => $stored ? (string) $stored['state'] : '', 'why' => $why);
		};
		if (!in_array((string) $event['type'], self::EVENTS, true)) {
			return $ignore('other_event');
		}
		// A repeated or late event carries a revision the arrear already went past.
		if ($stored && (int) $event['revision'] <= (int) $stored['revision']) {
			return $ignore('stale');
		}
		$gone = !empty($facts['paid']) || (isset($facts['case_status']) && $facts['case_status'] === 'closed');
		if ($event['type'] === 'MAHNWESEN_CASE_FINAL_STAGE') {
			if (empty($facts['fee'])) {
				return $ignore('not_a_fee');
			}
			if (!isset($facts['final_step']) || $facts['final_step'] !== self::FINAL_STEP) {
				return $ignore('other_final_step');
			}
			// Paid before the event arrived: nothing to put before the board.
			if ($gone) {
				return $stored ? array('do' => 'update', 'state' => self::STATE_SETTLED, 'why' => 'paid') : $ignore('paid');
			}
			$state = !empty($facts['paused']) ? self::STATE_PAUSED : self::STATE_OPEN;
			return array('do' => $stored ? 'update' : 'create', 'state' => $state, 'why' => 'final_stage');
		}
		if (!$stored) {
			return $ignore('no_arrear');
		}
		if ($event['type'] === 'MAHNWESEN_CASE_CLOSED') {
			return array('do' => 'update', 'state' => self::STATE_SETTLED, 'why' => 'closed');
		}
		if ($event['type'] === 'MAHNWESEN_CASE_HANDED_OVER') {
			return array('do' => 'update', 'state' => self::STATE_HANDED_OVER, 'why' => 'handed_over');
		}
		if ($event['type'] === 'MAHNWESEN_CASE_PAUSED') {
			return array('do' => 'update', 'state' => $stored['state'] === self::STATE_OPEN ? self::STATE_PAUSED : (string) $stored['state'], 'why' => 'paused');
		}
		if ($event['type'] === 'MAHNWESEN_CASE_RESUMED') {
			return array('do' => 'update', 'state' => $stored['state'] === self::STATE_PAUSED && !$gone ? self::STATE_OPEN : (string) $stored['state'], 'why' => 'resumed');
		}
		// Reopened: the dunning starts again. The board hears of it only when the last step is reached anew.
		return array('do' => 'update', 'state' => (string) $stored['state'], 'why' => 'reopened');
	}

	/**
	 * The agenda item for an arrear: the member and the invoice, the question for the board.
	 *
	 * @param string    $name        Member
	 * @param string    $invoice     Invoice reference
	 * @param Translate $outputlangs Language
	 * @return string
	 */
	public static function agendaItem($name, $invoice, $outputlangs)
	{
		return $outputlangs->transnoentities('VereineArrearAgendaItem', trim((string) $name), trim((string) $invoice));
	}
}
