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
 * \file    class/vereinemailingrules.class.php
 * \ingroup vereine
 * \brief   Who receives an e-mail campaign: members by status, type, function and consent, plain PHP.
 */

/**
 * Rules of e-mail campaign recipients.
 */
class VereineMailingRules
{
	/** Active members. */
	const STATUS_ACTIVE = 'active';
	/** Members in draft, such as applications. */
	const STATUS_DRAFT = 'draft';
	/** Resiliated and excluded members. */
	const STATUS_FORMER = 'former';
	/** Every member. */
	const STATUS_ALL = 'all';
	/** Statuses offered. */
	const STATUSES = array('active', 'draft', 'former', 'all');

	/** Any function of the board. */
	const FUNCTION_BOARD = 'board';
	/** Information of the association, which needs no consent, such as an invitation to the general assembly. */
	const PURPOSE_INFO = 'info';

	/** Age from which members are reached directly. */
	const ADULT_AGE = 18;

	/**
	 * A filter that can be used as it is.
	 *
	 * @param array<string,mixed> $filter Keys status, type_id, function, purpose, guardians
	 * @return array{status:string,type_id:int,function:string,purpose:string,guardians:bool}
	 */
	public static function normalize(array $filter)
	{
		$status = isset($filter['status']) && in_array($filter['status'], self::STATUSES, true) ? $filter['status'] : self::STATUS_ACTIVE;
		$function = isset($filter['function']) && preg_match('/^[a-z][a-z0-9_]{1,31}$/', (string) $filter['function']) ? (string) $filter['function'] : '';
		$purpose = isset($filter['purpose']) && preg_match('/^[a-z][a-z0-9_]{1,31}$/', (string) $filter['purpose']) ? (string) $filter['purpose'] : self::PURPOSE_INFO;
		return array(
			'status' => $status,
			'type_id' => isset($filter['type_id']) ? max(0, (int) $filter['type_id']) : 0,
			'function' => $function,
			'purpose' => $purpose,
			'guardians' => !empty($filter['guardians']),
		);
	}

	/**
	 * Recipients of a campaign.
	 *
	 * A member without e-mail address, and a minor reached through guardians without address,
	 * gets nothing. Each address appears once.
	 *
	 * @param array<int,array<string,mixed>> $members Keys id, status (Dolibarr: -1, 0, 1, -2), type_id, email, firstname, lastname, birth,
	 *                                                functions (codes held today), board (bool: holds a board function today),
	 *                                                consents (codes given), guardians (list of contact_id, email, firstname, lastname)
	 * @param array<string,mixed>            $filter  See normalize()
	 * @param string                         $today   Today, YYYY-MM-DD
	 * @return array<int,array{email:string,member_id:int,contact_id:int,firstname:string,lastname:string}>
	 */
	public static function recipients(array $members, array $filter, $today)
	{
		$filter = self::normalize($filter);
		$result = array();
		$seen = array();
		foreach ($members as $member) {
			if (!self::matches($member, $filter)) {
				continue;
			}
			$addresses = array();
			if ($filter['guardians'] && self::isMinor(isset($member['birth']) ? $member['birth'] : '', $today)) {
				foreach (isset($member['guardians']) ? $member['guardians'] : array() as $guardian) {
					$addresses[] = array('email' => (string) $guardian['email'], 'member_id' => (int) $member['id'], 'contact_id' => (int) $guardian['contact_id'],
						'firstname' => (string) $guardian['firstname'], 'lastname' => (string) $guardian['lastname']);
				}
			} else {
				$addresses[] = array('email' => (string) $member['email'], 'member_id' => (int) $member['id'], 'contact_id' => 0,
					'firstname' => (string) $member['firstname'], 'lastname' => (string) $member['lastname']);
			}
			foreach ($addresses as $address) {
				$key = strtolower(trim($address['email']));
				if ($key === '' || isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$address['email'] = trim($address['email']);
				$result[] = $address;
			}
		}
		return $result;
	}

	/**
	 * Whether a member passes status, type, function and consent of a filter.
	 *
	 * @param array<string,mixed> $member Member, see recipients()
	 * @param array<string,mixed> $filter Normalised filter
	 * @return bool
	 */
	public static function matches(array $member, array $filter)
	{
		$status = (int) $member['status'];
		if (($filter['status'] === self::STATUS_ACTIVE && $status !== 1) || ($filter['status'] === self::STATUS_DRAFT && $status !== -1)
			|| ($filter['status'] === self::STATUS_FORMER && !in_array($status, array(0, -2), true))) {
			return false;
		}
		if ($filter['type_id'] > 0 && (int) $member['type_id'] !== $filter['type_id']) {
			return false;
		}
		if ($filter['function'] === self::FUNCTION_BOARD && empty($member['board'])) {
			return false;
		}
		if ($filter['function'] !== '' && $filter['function'] !== self::FUNCTION_BOARD && !in_array($filter['function'], isset($member['functions']) ? $member['functions'] : array(), true)) {
			return false;
		}
		return $filter['purpose'] === self::PURPOSE_INFO || in_array($filter['purpose'], isset($member['consents']) ? $member['consents'] : array(), true);
	}

	/**
	 * Whether a person is under age on a day.
	 *
	 * @param string $birth Birth date, empty when unknown
	 * @param string $today Day
	 * @return bool False when the birth date is unknown
	 */
	public static function isMinor($birth, $today)
	{
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $birth)) {
			return false;
		}
		$age = (int) substr($today, 0, 4) - (int) substr($birth, 0, 4);
		if (substr($today, 5) < substr($birth, 5)) {
			$age--;
		}
		return $age < self::ADULT_AGE;
	}
}
