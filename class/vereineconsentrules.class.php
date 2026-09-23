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
 * \file    class/vereineconsentrules.class.php
 * \ingroup vereine
 * \brief   Consent texts, consents of members and membership applications, plain PHP.
 */

require_once __DIR__.'/vereinestatuterules.class.php';

/**
 * Rules of consents and applications.
 */
class VereineConsentRules
{
	/** Where a consent was given. */
	const SOURCES = array('website', 'paper', 'member_card');

	/** Longest label of a consent text. */
	const LABEL_MAX = 128;
	/** Longest consent text. */
	const TEXT_MAX = 10000;

	/**
	 * Whether a code of a consent purpose is usable: lower case letters, digits, underscore.
	 *
	 * @param string $code Code
	 * @return bool
	 */
	public static function isCode($code)
	{
		return (bool) preg_match('/^[a-z][a-z0-9_]{1,31}$/', (string) $code);
	}

	/**
	 * Problems of a consent text before it is stored.
	 *
	 * @param array<string,mixed> $data Keys code, label, text
	 * @return string[] Language keys, empty when fine
	 */
	public static function validateText(array $data)
	{
		$errors = array();
		if (!self::isCode(isset($data['code']) ? $data['code'] : '')) {
			$errors[] = 'VereineConsentErrorCode';
		}
		$label = trim((string) (isset($data['label']) ? $data['label'] : ''));
		if ($label === '' || mb_strlen($label, 'UTF-8') > self::LABEL_MAX) {
			$errors[] = 'VereineConsentErrorLabel';
		}
		$text = trim((string) (isset($data['text']) ? $data['text'] : ''));
		if ($text === '' || mb_strlen($text, 'UTF-8') > self::TEXT_MAX) {
			$errors[] = 'VereineConsentErrorText';
		}
		return $errors;
	}

	/**
	 * The current state per purpose: the latest event of each code.
	 *
	 * @param array<int,array<string,mixed>> $events Consents and withdrawals with keys id, code, date
	 * @return array<string,array<string,mixed>> Latest event by code, codes sorted
	 */
	public static function current(array $events)
	{
		$latest = array();
		foreach ($events as $event) {
			$code = (string) $event['code'];
			if (!isset($latest[$code]) || array((string) $event['date'], (int) $event['id']) > array((string) $latest[$code]['date'], (int) $latest[$code]['id'])) {
				$latest[$code] = $event;
			}
		}
		ksort($latest);
		return $latest;
	}

	/**
	 * How a consent was given, so the association can show it later (Art. 7 (1) GDPR): the moment on the
	 * website, the form or page it was given on, and the reference of the website's own record. No IP
	 * address: the module keeps only what it needs (Art. 5 (1) (c) GDPR).
	 *
	 * @param mixed $data Entered proof with the keys at, form and ref
	 * @return array{at:string,form:string,ref:string} Empty strings for what was not entered
	 */
	public static function proof($data)
	{
		$value = static function ($key, $length) use ($data) {
			$raw = is_array($data) && isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
			return function_exists('mb_substr') ? mb_substr($raw, 0, $length, 'UTF-8') : substr($raw, 0, $length);
		};
		$at = $value('at', 25);
		if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?/', $at, $parts)) {
			$at = $parts[1].' '.$parts[2].(isset($parts[3]) && $parts[3] !== '' ? $parts[3] : ':00');
		} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $at)) {
			$at .= ' 00:00:00';
		} else {
			$at = '';
		}
		return array('at' => $at, 'form' => $value('form', 128), 'ref' => $value('ref', 64));
	}

	/** What somebody can decide about a purpose. */
	const DECISIONS = array('given', 'withdrawn');

	/** Largest signature image a website may send, in bytes. */
	const SIGNATURE_BYTES = 200000;

	/**
	 * The signature drawn on the screen, as a website sends it: a PNG image, base64 encoded.
	 *
	 * It is a simple electronic signature, not a qualified one (Art. 25 eIDAS); it is kept as long
	 * as the application itself.
	 *
	 * @param mixed $data Value of the field signature
	 * @return array{image:string,errors:string[]} Raw image, empty when there is none
	 */
	public static function signature($data)
	{
		if ($data === null || $data === '' || !is_scalar($data)) {
			return array('image' => '', 'errors' => array());
		}
		$value = preg_replace('#^data:image/png;base64,#i', '', trim((string) $data));
		$image = base64_decode($value, true);
		if ($image === false || strncmp($image, "\x89PNG\r\n\x1a\n", 8) !== 0) {
			return array('image' => '', 'errors' => array('signature must be a PNG image, base64 encoded'));
		}
		if (strlen($image) > self::SIGNATURE_BYTES) {
			return array('image' => '', 'errors' => array('signature must be at most '.self::SIGNATURE_BYTES.' bytes'));
		}
		return array('image' => $image, 'errors' => array());
	}

	/**
	 * Check and normalise a decision about one consent, as a website sends it for a member (#98).
	 *
	 * Giving a consent needs the version that was shown, so nobody agrees silently to a newer text.
	 * Withdrawing needs no version: a new text must never stand in the way of a withdrawal
	 * (Art. 7 (3) GDPR).
	 *
	 * @param mixed                             $data    Body of the request
	 * @param array<string,int>                 $texts   Current version of every active text by code
	 * @param array<string,array<string,mixed>> $current The member's effective consents by code
	 * @return array{errors:string[],decision:array<string,mixed>} Errors in English for the API answer
	 */
	public static function decision($data, array $texts, array $current)
	{
		$errors = array();
		$code = is_array($data) && isset($data['code']) && is_scalar($data['code']) ? trim((string) $data['code']) : '';
		$what = is_array($data) && isset($data['decision']) && is_scalar($data['decision']) ? trim((string) $data['decision']) : '';
		$version = is_array($data) && isset($data['version']) && is_numeric($data['version']) ? (int) $data['version'] : 0;
		if (!in_array($what, self::DECISIONS, true)) {
			$errors[] = 'decision must be '.implode(' or ', self::DECISIONS);
		}
		$given = isset($current[$code]) && $current[$code]['given'];
		if ($what === 'given') {
			if (!isset($texts[$code])) {
				$errors[] = 'code '.$code.' is no active consent text, see GET /vereine/consents';
			} elseif ($version !== $texts[$code]) {
				$errors[] = 'version '.$version.' was given, the text shown must be the current version '.$texts[$code];
			}
		} elseif ($what === 'withdrawn') {
			if (!$given) {
				$errors[] = 'code '.$code.' is not given right now, so there is nothing to withdraw';
			} else {
				$version = (int) $current[$code]['version'];
			}
		}
		$proof = self::proof(array(
			'at' => is_array($data) && isset($data['granted_at']) ? $data['granted_at'] : '',
			'form' => is_array($data) && isset($data['form']) ? $data['form'] : '',
			'ref' => is_array($data) && isset($data['reference']) ? $data['reference'] : '',
		));
		return array('errors' => $errors, 'decision' => array('code' => $code, 'decision' => $what, 'version' => $version, 'proof' => $proof));
	}

	/**
	 * Check and normalise a membership application from a website.
	 *
	 * @param mixed                            $data  Body of the request
	 * @param array<int,array<string,mixed>>   $types Active member types by id, keys morphy ('phy', 'mor' or '')
	 * @param array<string,int>                $texts Current version of every active consent text by code
	 * @param int                              $minAge Minimum age of the statutes for natural persons, 0 for none
	 * @param string                           $today  Day of the application, YYYY-MM-DD
	 * @return array{errors:string[],application:array<string,mixed>} Errors in English for the API answer
	 */
	public static function application($data, array $types, array $texts, $minAge = 0, $today = '')
	{
		$errors = array();
		$data = is_array($data) ? $data : array();
		$text = function ($key, $max) use ($data, &$errors) {
			$value = isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
			if (mb_strlen($value, 'UTF-8') > $max) {
				$errors[] = $key.' is longer than '.$max.' characters';
			}
			return $value;
		};
		$application = array(
			'external_id' => $text('external_id', 64),
			'morphy' => isset($data['morphy']) && $data['morphy'] === 'mor' ? 'mor' : 'phy',
			'company' => $text('company', 128),
			'firstname' => $text('firstname', 50),
			'lastname' => $text('lastname', 50),
			'email' => $text('email', 255),
			'phone' => $text('phone', 30),
			'birth' => $text('birth', 10),
			'address' => $text('address', 255),
			'zip' => $text('zip', 25),
			'town' => $text('town', 50),
			'country_code' => strtoupper($text('country_code', 2)),
			'type_id' => isset($data['type_id']) && is_numeric($data['type_id']) ? (int) $data['type_id'] : 0,
			'note' => $text('note', 1000),
			'consents' => array(),
			'signature' => '',
		);
		if ($application['external_id'] !== '' && !preg_match('/^[A-Za-z0-9._:-]+$/', $application['external_id'])) {
			$errors[] = 'external_id may only contain letters, digits and . _ : -';
		}
		if ($application['firstname'] === '' || $application['lastname'] === '') {
			$errors[] = 'firstname and lastname are required';
		}
		if ($application['morphy'] === 'mor' && $application['company'] === '') {
			$errors[] = 'company is required for a legal entity';
		}
		if (!filter_var($application['email'], FILTER_VALIDATE_EMAIL)) {
			$errors[] = 'email must be a valid e-mail address';
		}
		if ($application['birth'] !== '' && !(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $application['birth'], $parts) && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))) {
			$errors[] = 'birth must be a date YYYY-MM-DD';
		}
		if ((int) $minAge > 0 && $application['morphy'] === 'phy' && !in_array('birth must be a date YYYY-MM-DD', $errors, true)
			&& !VereineStatuteRules::oldEnough($application['birth'], (int) $minAge, $today)) {
			$errors[] = $application['birth'] === '' ? 'birth is required: the statutes admit members from '.((int) $minAge).' years of age'
				: 'the statutes admit members from '.((int) $minAge).' years of age';
		}
		if ($application['country_code'] !== '' && !preg_match('/^[A-Z]{2}$/', $application['country_code'])) {
			$errors[] = 'country_code must be two letters such as AT';
		}
		if (!isset($types[$application['type_id']])) {
			$errors[] = 'type_id must be an active member type, see GET /vereine/membershipfees';
		} elseif ($types[$application['type_id']]['morphy'] !== '' && $types[$application['type_id']]['morphy'] !== $application['morphy']) {
			$errors[] = 'the member type is not open to this kind of person (morphy)';
		}
		$signature = self::signature(isset($data['signature']) ? $data['signature'] : null);
		$errors = array_merge($errors, $signature['errors']);
		$application['signature'] = $signature['image'];
		$consents = isset($data['consents']) ? $data['consents'] : array();
		if (!is_array($consents)) {
			$errors[] = 'consents must be a list';
			$consents = array();
		}
		foreach ($consents as $consent) {
			$code = is_array($consent) && isset($consent['code']) && is_scalar($consent['code']) ? (string) $consent['code'] : '';
			$version = is_array($consent) && isset($consent['version']) && is_numeric($consent['version']) ? (int) $consent['version'] : 0;
			if (!isset($texts[$code])) {
				$errors[] = 'consent '.$code.' is no active consent text, see GET /vereine/consents';
			} elseif ($texts[$code] !== $version) {
				$errors[] = 'consent '.$code.' was given to version '.$version.', the current version is '.$texts[$code];
			} elseif (isset($application['consents'][$code])) {
				$errors[] = 'consent '.$code.' is listed twice';
			} else {
				$application['consents'][$code] = array('version' => $version, 'proof' => self::proof(array(
					'at' => is_array($consent) && isset($consent['granted_at']) ? $consent['granted_at'] : '',
					'form' => is_array($consent) && isset($consent['form']) ? $consent['form'] : '',
					'ref' => is_array($consent) && isset($consent['reference']) ? $consent['reference'] : '',
				)));
			}
		}
		return array('errors' => $errors, 'application' => $application);
	}
}
