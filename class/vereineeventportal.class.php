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
 * \file    class/vereineeventportal.class.php
 * \ingroup vereine
 * \brief   Events for websites and applications (#165): what is public, what members see, their own shifts.
 *
 * Each event says where people register: nowhere, in Dolibarr, or at one named external application.
 * The module never books a second time; it tells a website where the registration lives. Members ask
 * for a helper shift through their application; the board confirms it in Dolibarr, and only what was
 * really done counts for the volunteer allowance (#7).
 */

require_once __DIR__.'/vereineeventrules.class.php';
require_once __DIR__.'/vereineshiftrules.class.php';

/**
 * Events for websites and applications.
 */
class VereineEventPortal
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * @var string[] Messages for the caller
	 */
	public $errors = array();

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Events from today on for somebody: the public ones, and for a member also the ones for members.
	 *
	 * @param string $today    Today
	 * @param int    $memberId The member, 0 for the public
	 * @return array<int,array<string,mixed>>
	 */
	public function events($today, $memberId = 0)
	{
		require_once __DIR__.'/vereineevents.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

		$list = array();
		foreach ((new VereineEvents($this->db))->all($today) as $event) {
			$visible = $event['visibility'] === VereineEventRules::VISIBILITY_PUBLIC
				|| ($memberId > 0 && $event['visibility'] === VereineEventRules::VISIBILITY_MEMBERS);
			if (!$visible) {
				continue;
			}
			$entry = array('id' => $event['id'], 'label' => $event['label'], 'day' => $event['event_day'], 'end_day' => $event['end_day'],
				'timezone' => (string) getServerTimeZoneString(), 'place' => $event['place'], 'status' => $event['status'], 'visibility' => $event['visibility'],
				'registration' => array('kind' => $event['registration'],
					'external_ref' => $event['registration'] === VereineEventRules::REGISTRATION_EXTERNAL ? $event['external_ref'] : ''));
			if ($memberId > 0) {
				$entry['shifts'] = $this->shifts($event['id'], $memberId);
			}
			$list[] = $entry;
		}
		return $list;
	}

	/**
	 * Ask for a place on a helper shift; the board confirms it in Dolibarr. Asking again changes nothing.
	 *
	 * @param int    $memberId Member
	 * @param int    $eventId  Event
	 * @param int    $shiftId  Shift
	 * @param string $client   The application
	 * @param string $today    Today
	 * @param User   $user     Who acts (the client)
	 * @return int 1 when asked or already there, 0 when refused (see errors), -1 on error
	 */
	public function ask($memberId, $eventId, $shiftId, $client, $today, $user)
	{
		require_once __DIR__.'/vereineshifts.class.php';

		$this->errors = array();
		$shift = $this->openShift($memberId, $eventId, $shiftId, $today);
		if ($shift === null) {
			return 0;
		}
		if ($shift['mine'] !== '' && $shift['mine'] !== VereineShiftRules::STATUS_CANCELLED) {
			return 1;
		}
		$shifts = new VereineShifts($this->db);
		$result = $shifts->signUp((int) $shiftId, (int) $memberId, VereineShiftRules::STATUS_REQUESTED, $client, $user);
		if ($result === 0) {
			$this->errors = array_map(function ($error) {
				return $error === 'VereineShiftErrorOverlap' ? 'the shift overlaps another shift of the person' : ($error === 'VereineShiftErrorFull' ? 'the shift is full' : $error);
			}, $shifts->errors);
		}
		$this->error = $shifts->error;
		return $result;
	}

	/**
	 * Take back a request that the board has not confirmed yet. A confirmed shift is cancelled with the association.
	 *
	 * @param int    $memberId Member
	 * @param int    $eventId  Event
	 * @param int    $shiftId  Shift
	 * @param string $today    Today
	 * @param User   $user     Who acts (the client)
	 * @return int 1 when taken back or not there, 0 when refused (see errors), -1 on error
	 */
	public function withdraw($memberId, $eventId, $shiftId, $today, $user)
	{
		require_once __DIR__.'/vereineshifts.class.php';

		$this->errors = array();
		$shift = $this->openShift($memberId, $eventId, $shiftId, $today);
		if ($shift === null) {
			return 0;
		}
		if ($shift['mine'] === '' || $shift['mine'] === VereineShiftRules::STATUS_CANCELLED) {
			return 1;
		}
		if ($shift['mine'] !== VereineShiftRules::STATUS_REQUESTED) {
			$this->errors[] = 'a confirmed shift is cancelled with the association';
			return 0;
		}
		$shifts = new VereineShifts($this->db);
		$result = $shifts->setStatus($this->entryOf((int) $shiftId, (int) $memberId), VereineShiftRules::STATUS_CANCELLED, '', $user);
		$this->errors = $shifts->errors;
		$this->error = $shifts->error;
		return $result;
	}

	/**
	 * The helper shifts of an event with how many places are taken and the member's own state.
	 *
	 * @param int $eventId  Event
	 * @param int $memberId Member
	 * @return array<int,array<string,mixed>>
	 */
	private function shifts($eventId, $memberId)
	{
		require_once __DIR__.'/vereineshifts.class.php';

		$list = array();
		foreach ((new VereineShifts($this->db))->forEvent((int) $eventId) as $shift) {
			$mine = '';
			foreach ($shift['entries'] as $entry) {
				if ((int) $entry['member_id'] === (int) $memberId && ($mine === '' || $entry['status'] !== VereineShiftRules::STATUS_CANCELLED)) {
					$mine = (string) $entry['status'];
				}
			}
			$places = VereineShiftRules::places($shift['capacity'], $shift['entries']);
			$list[] = array('id' => (int) $shift['id'], 'label' => (string) $shift['label'], 'day' => (string) $shift['shift_day'], 'start' => (string) $shift['start_time'],
				'end' => (string) $shift['end_time'], 'capacity' => (int) $shift['capacity'], 'taken' => (int) $places['taken'], 'full' => (bool) $places['full'], 'mine' => $mine);
		}
		return $list;
	}

	/**
	 * A shift of an event the member may see that is still ahead, or null with the reason in errors.
	 *
	 * @param int    $memberId Member
	 * @param int    $eventId  Event
	 * @param int    $shiftId  Shift
	 * @param string $today    Today
	 * @return array<string,mixed>|null
	 */
	private function openShift($memberId, $eventId, $shiftId, $today)
	{
		foreach ($this->events($today, $memberId) as $event) {
			if ($event['id'] !== (int) $eventId) {
				continue;
			}
			if ($event['status'] === VereineEventRules::STATUS_CANCELLED) {
				$this->errors[] = 'the event is cancelled';
				return null;
			}
			foreach ($event['shifts'] as $shift) {
				if ($shift['id'] === (int) $shiftId) {
					if ($shift['day'] < $today) {
						$this->errors[] = 'the shift is over';
						return null;
					}
					return $shift;
				}
			}
		}
		$this->errors[] = 'not found';
		return null;
	}

	/**
	 * The entry of a member on a shift that is not cancelled.
	 *
	 * @param int $shiftId  Shift
	 * @param int $memberId Member
	 * @return int
	 */
	private function entryOf($shiftId, $memberId)
	{
		$resql = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_event_shift_entry WHERE fk_shift = ".((int) $shiftId)." AND fk_adherent = ".((int) $memberId)
			." AND status <> '".VereineShiftRules::STATUS_CANCELLED."' ORDER BY rowid DESC");
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? (int) $obj->rowid : 0;
	}
}
