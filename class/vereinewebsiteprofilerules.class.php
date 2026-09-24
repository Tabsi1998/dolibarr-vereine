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
 * \file    class/vereinewebsiteprofilerules.class.php
 * \ingroup vereine
 * \brief   Rules of the website profile of a member (#255): fields, lists, photo types, the consent code.
 *
 * The profile is what the association itself writes about a member for its website - never the member's
 * contact data. It leaves Dolibarr only with the consent the association chose, and the website decides
 * what it shows of it.
 */

/**
 * Rules of the website profile.
 */
class VereineWebsiteProfileRules
{
	/** Fields of the profile and their longest length. */
	const FIELDS = array('gamertag' => 40, 'bio' => 2000, 'games' => 255, 'platforms' => 255);

	/** Fields kept as a list: one entry per comma, semicolon or line. */
	const LISTS = array('games', 'platforms');

	/** Setting: the consent a member must have given before the website gets the profile. */
	const CONSENT = 'VEREINE_WEBSITE_PROFILE_CONSENT';

	/** Image types a member photo may have, by extension. */
	const IMAGE_TYPES = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp');

	/**
	 * The profile as it is stored: every field trimmed and cut, lists tidied, nothing else.
	 *
	 * @param array<string,mixed> $entered Field => value as entered
	 * @return array<string,string>
	 */
	public static function normalize(array $entered)
	{
		$out = array();
		foreach (self::FIELDS as $field => $length) {
			$value = isset($entered[$field]) ? trim((string) $entered[$field]) : '';
			if (in_array($field, self::LISTS, true)) {
				$value = implode(', ', self::splitList($value));
			}
			$out[$field] = mb_substr($value, 0, $length);
		}
		return $out;
	}

	/**
	 * A change the member sends: only the fields sent change, the others stay; a field longer than it may be is refused
	 * with its name, never cut (#260).
	 *
	 * @param mixed                $sent    Field => value as sent; lists as array or text
	 * @param array<string,string> $current The stored profile
	 * @return array{fields:array<string,string>,errors:string[]}
	 */
	public static function change($sent, array $current)
	{
		$sent = is_array($sent) ? $sent : array();
		$fields = array();
		$errors = array();
		foreach (self::FIELDS as $field => $length) {
			$fields[$field] = isset($current[$field]) ? (string) $current[$field] : '';
			if (!array_key_exists($field, $sent)) {
				continue;
			}
			$raw = $sent[$field];
			if (is_array($raw) && in_array($field, self::LISTS, true) && count(array_filter($raw, 'is_scalar')) === count($raw)) {
				$raw = implode("\n", $raw);
			}
			if (!is_scalar($raw) && $raw !== null) {
				$errors[] = $field.' must be text'.(in_array($field, self::LISTS, true) ? ' or a list of texts' : '');
				continue;
			}
			$value = trim((string) $raw);
			if (in_array($field, self::LISTS, true)) {
				$value = implode(', ', self::splitList($value));
			}
			if (mb_strlen($value, 'UTF-8') > $length) {
				$errors[] = $field.' may have at most '.$length.' characters';
				continue;
			}
			$fields[$field] = $value;
		}
		return array('fields' => $fields, 'errors' => $errors);
	}

	/**
	 * A list as entered - "TFT, Rocket League" or one per line - as tidy entries without repeats.
	 *
	 * @param string $value Entered
	 * @return string[]
	 */
	public static function splitList($value)
	{
		$out = array();
		foreach (preg_split('/[,;\r\n]+/', (string) $value) as $entry) {
			$entry = trim($entry);
			if ($entry !== '' && !in_array($entry, $out, true)) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Whether nothing is filled in.
	 *
	 * @param array<string,string> $fields Normalised fields
	 * @return bool
	 */
	public static function isEmpty(array $fields)
	{
		foreach (self::FIELDS as $field => $length) {
			if (isset($fields[$field]) && $fields[$field] !== '') {
				return false;
			}
		}
		return true;
	}

	/**
	 * The content type of a photo by its extension, null when it is no image the website may show.
	 *
	 * @param string $filename File name
	 * @return string|null
	 */
	public static function contentType($filename)
	{
		$extension = strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION));
		return isset(self::IMAGE_TYPES[$extension]) ? self::IMAGE_TYPES[$extension] : null;
	}

	/**
	 * The consent code as stored: letters, digits, underscore and dash only - anything else counts as none.
	 *
	 * @param string $setting Stored setting
	 * @return string
	 */
	public static function consentCode($setting)
	{
		$code = trim((string) $setting);
		return preg_match('/^[A-Za-z0-9_-]{1,60}$/', $code) ? $code : '';
	}

	/**
	 * The profile as the API hands it out: lists as arrays, the photo as its checksum and size.
	 *
	 * @param array<string,string> $row   Stored fields
	 * @param array|null           $photo sha256, size, content_type, updated_at - or null without photo
	 * @return array<string,mixed>
	 */
	public static function view(array $row, $photo)
	{
		$out = array();
		foreach (self::FIELDS as $field => $length) {
			$value = isset($row[$field]) ? (string) $row[$field] : '';
			$out[$field] = in_array($field, self::LISTS, true) ? self::splitList($value) : $value;
		}
		$out['photo'] = $photo ? array(
			'sha256' => (string) $photo['sha256'], 'size' => (int) $photo['size'],
			'content_type' => (string) $photo['content_type'], 'updated_at' => (string) $photo['updated_at'],
		) : null;
		return $out;
	}
}
