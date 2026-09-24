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
 * \file    class/vereineprofilerules.class.php
 * \ingroup vereine
 * \brief   Rules of a member changing their own data through an application (#164).
 *
 * Only contact data can be asked to change, never the member type, the status, functions, payments,
 * notes or the link to a third party. A request names the state of the data it was made on; when the
 * data changed since, it is a conflict, not a silent overwrite. The association chooses which fields
 * change at once; an e-mail address never does, because nothing proves the new one belongs to the member.
 */

/**
 * Rules of changing one's own data.
 */
class VereineProfileRules
{
	/** Fields a member may ask to change, and their longest length. */
	const FIELDS = array('address' => 255, 'zip' => 30, 'town' => 50, 'country_code' => 2, 'phone' => 30, 'phone_mobile' => 30, 'email' => 255);

	/** Fields the association may let change at once. */
	const DIRECT_ALLOWED = array('address', 'zip', 'town', 'country_code', 'phone', 'phone_mobile');

	/** States of a request. */
	const STATES = array('received', 'applied', 'rejected');

	/**
	 * The state of the data a request is made on: a checksum of the fields as they are now.
	 *
	 * @param array<string,string> $values Field => value
	 * @return string
	 */
	public static function version(array $values)
	{
		$ordered = array();
		foreach (array_keys(self::FIELDS) as $field) {
			$ordered[$field] = isset($values[$field]) ? (string) $values[$field] : '';
		}
		return substr(hash('sha256', (string) json_encode($ordered)), 0, 16);
	}

	/**
	 * A request as sent: an id, the version it was made on, and only fields that may change, each valid.
	 *
	 * @param mixed                $sent    external_id, version, changes
	 * @param array<string,string> $current Field => value now
	 * @return array{external_id:string,version:string,asked:array<string,string>,changes:array<string,string>,errors:string[]}
	 */
	public static function check($sent, array $current)
	{
		$sent = is_array($sent) ? $sent : array();
		$errors = array();
		$external = isset($sent['external_id']) && is_scalar($sent['external_id']) ? trim((string) $sent['external_id']) : '';
		if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $external)) {
			$errors[] = 'external_id must be 1 to 64 letters, digits or . _ : -';
		}
		$version = isset($sent['version']) && is_scalar($sent['version']) ? (string) $sent['version'] : '';
		if ($version === '') {
			$errors[] = 'version is needed: the one GET me/profile gave';
		}
		$changes = array();
		$asked = array();
		foreach (isset($sent['changes']) && is_array($sent['changes']) ? $sent['changes'] : array() as $field => $value) {
			if (!array_key_exists((string) $field, self::FIELDS)) {
				$errors[] = 'changes.'.$field.' cannot be changed this way';
				continue;
			}
			$value = is_scalar($value) ? trim((string) $value) : null;
			if ($value === null || mb_strlen($value, 'UTF-8') > self::FIELDS[$field] || preg_match('/[\x00-\x1F\x7F<>]/u', $value)) {
				$errors[] = 'changes.'.$field.' is no valid value';
				continue;
			}
			if ($field === 'country_code') {
				$value = strtoupper($value);
				if (!preg_match('/^[A-Z]{2}$/', $value)) {
					$errors[] = 'changes.country_code must be two letters';
					continue;
				}
			}
			if ($field === 'email' && ($value === '' || !filter_var($value, FILTER_VALIDATE_EMAIL))) {
				$errors[] = 'changes.email is no valid address';
				continue;
			}
			$asked[(string) $field] = $value;
			if (!isset($current[$field]) || (string) $current[$field] !== $value) {
				$changes[(string) $field] = $value;
			}
		}
		if (!$errors && !$changes) {
			$errors[] = 'changes has nothing that differs from the data now';
		}
		ksort($asked);
		return array('external_id' => $external, 'version' => $version, 'asked' => $asked, 'changes' => $changes, 'errors' => $errors);
	}

	/**
	 * Whether every change of a request may be applied at once.
	 *
	 * @param array<string,string> $changes Field => value
	 * @param string[]             $direct  Fields the association lets change at once
	 * @return bool
	 */
	public static function direct(array $changes, array $direct)
	{
		foreach (array_keys($changes) as $field) {
			if (!in_array($field, $direct, true) || !in_array($field, self::DIRECT_ALLOWED, true)) {
				return false;
			}
		}
		return (bool) $changes;
	}

	/**
	 * What a request says, to tell a repetition from another request under the same id.
	 *
	 * @param array<string,mixed> $payload What was asked
	 * @return string
	 */
	public static function fingerprint(array $payload)
	{
		ksort($payload);
		return hash('sha256', (string) json_encode($payload));
	}
}
