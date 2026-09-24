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
 * \brief   Rules of the website profile of a member (#255, #260): its fields, their values, the photo, the consent code.
 *
 * The profile is what the association itself chooses to show about a member on its own website: fields of the
 * member it defines as Dolibarr's additional fields - never the contact data. Which fields, and which of them the
 * member keeps, the association decides. The profile leaves Dolibarr only with the consent the association chose,
 * and the website decides what it shows of it.
 */

require_once __DIR__.'/vereineapplicationformrules.class.php';

/**
 * Rules of the website profile.
 */
class VereineWebsiteProfileRules
{
	/** Setting: the consent a member must have given before the website gets the profile. */
	const CONSENT = 'VEREINE_WEBSITE_PROFILE_CONSENT';

	/** Setting: the fields of the profile, a JSON object of code => {"self": 1 when the member may change it}. */
	const FIELDS = 'VEREINE_WEBSITE_PROFILE_FIELDS';

	/** Setting: the profiles of 1.1.0 were taken over into fields of the member. */
	const MIGRATED = 'VEREINE_WEBSITE_PROFILE_MIGRATED';

	/** The fixed fields of 1.1.0, each with the label and kind of the field of the member it becomes. */
	const OLD_FIELDS = array('gamertag' => array('Gamertag', 'text'), 'bio' => array('Kurztext', 'textarea'),
		'games' => array('Spiele', 'text'), 'platforms' => array('Plattformen', 'text'));

	/** Image types a member photo may have, by extension. */
	const IMAGE_TYPES = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp');

	/**
	 * The fields of the profile as the association chose them, only ones the member really has.
	 *
	 * @param mixed    $stored JSON object of code => {"self": 0 or 1}
	 * @param string[] $known  Codes of the fields of the member a form can show, in their order
	 * @return array<string,bool> Code => the member may change it, in the order of the fields
	 */
	public static function fields($stored, array $known)
	{
		$decoded = is_array($stored) ? $stored : json_decode((string) $stored, true);
		if (!is_array($decoded)) {
			return array();
		}
		$fields = array();
		foreach ($known as $code) {
			$code = (string) $code;
			// The module's own fields are kept by the module and never leave for a website.
			if (array_key_exists($code, $decoded) && strpos($code, 'vereine_') !== 0) {
				$entry = $decoded[$code];
				$fields[$code] = is_array($entry) ? !empty($entry['self']) : !empty($entry);
			}
		}
		return $fields;
	}

	/**
	 * The setting as the setup stores it.
	 *
	 * @param mixed    $chosen Codes of the fields in the profile
	 * @param mixed    $self   Codes the member may change; only chosen ones count
	 * @param string[] $known  Codes of the fields of the member a form can show
	 * @return string JSON, empty when no field is chosen
	 */
	public static function setting($chosen, $self, array $known)
	{
		$chosen = is_array($chosen) ? array_map('strval', array_filter($chosen, 'is_scalar')) : array();
		$self = is_array($self) ? array_map('strval', array_filter($self, 'is_scalar')) : array();
		$setting = array();
		foreach ($known as $code) {
			$code = (string) $code;
			if (in_array($code, $chosen, true) && strpos($code, 'vereine_') !== 0) {
				$setting[$code] = array('self' => in_array($code, $self, true) ? 1 : 0);
			}
		}
		return $setting ? (string) json_encode($setting) : '';
	}

	/**
	 * A value of a field as the API hands it out: null when empty, several options as a list, yes or no as
	 * true or false, a number as a number, a day as YYYY-MM-DD, anything else as text.
	 *
	 * @param string $kind One of VereineApplicationFormRules::KINDS
	 * @param mixed  $raw  As Dolibarr holds it; a day as a moment or as text
	 * @return mixed
	 */
	public static function value($kind, $raw)
	{
		if ($raw === null || $raw === '' || (is_array($raw) && !$raw)) {
			return null;
		}
		switch ($kind) {
			case 'multi':
				$list = array();
				foreach (is_array($raw) ? $raw : explode(',', (string) $raw) as $one) {
					$one = trim((string) $one);
					if ($one !== '' && !in_array($one, $list, true)) {
						$list[] = $one;
					}
				}
				return $list ? $list : null;
			case 'boolean':
				return (int) $raw !== 0;
			case 'number':
				if (!is_numeric($raw)) {
					return null;
				}
				$number = (float) $raw;
				return floor($number) == $number && abs($number) < 1e15 ? (int) $number : $number;
			case 'date':
				if (is_int($raw) || ctype_digit((string) $raw)) {
					return date('Y-m-d', (int) $raw);
				}
				return preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $raw, $day) ? $day[0] : null;
		}
		return (string) $raw;
	}

	/**
	 * One field of the profile as the API hands it out: code, label, type, value and whether the member may
	 * change it; a text with its longest length, a choice with its options.
	 *
	 * @param string              $code Code of the field
	 * @param array<string,mixed> $spec label, kind, options (code => label), max, editable
	 * @param mixed               $raw  Value as Dolibarr holds it
	 * @return array<string,mixed>
	 */
	public static function field($code, array $spec, $raw)
	{
		$kind = (string) $spec['kind'];
		$field = array('code' => (string) $code, 'label' => (string) $spec['label'], 'type' => $kind, 'editable' => !empty($spec['editable']),
			'value' => self::value($kind, $raw));
		if ($kind === 'text' || $kind === 'textarea') {
			$limit = $kind === 'text' ? VereineApplicationFormRules::EXTRA_MAX : VereineApplicationFormRules::TEXTAREA_MAX;
			$field['max_length'] = !empty($spec['max']) && (int) $spec['max'] < $limit ? (int) $spec['max'] : $limit;
		}
		if (!empty($spec['options'])) {
			$field['options'] = array();
			foreach ($spec['options'] as $option => $text) {
				$field['options'][] = array('code' => (string) $option, 'label' => (string) $text);
			}
		}
		return $field;
	}

	/**
	 * A change the member sends: every field sent must be in the profile, be one the member may change and fit
	 * its kind; the fields not sent stay as they are (#260).
	 *
	 * @param mixed                             $sent  Code => value as sent
	 * @param array<string,array<string,mixed>> $specs Fields of the profile: kind, options, max, integer, editable
	 * @return array{values:array<string,string|null>,errors:array<int,array{field:string,message:string}>} Values as
	 *         checkValue() gives them, null to empty a field
	 */
	public static function change($sent, array $specs)
	{
		if (!is_array($sent)) {
			return array('values' => array(), 'errors' => array(array('field' => '', 'message' => 'fields must be an object of code => value')));
		}
		$values = array();
		$errors = array();
		foreach ($sent as $code => $value) {
			$code = (string) $code;
			if (!isset($specs[$code])) {
				$errors[] = array('field' => $code, 'message' => 'fields.'.$code.' is no field of the website profile');
				continue;
			}
			if (empty($specs[$code]['editable'])) {
				$errors[] = array('field' => $code, 'message' => 'fields.'.$code.' is kept by the association, not by the member');
				continue;
			}
			$checked = VereineApplicationFormRules::checkValue($code, $value, $specs[$code]);
			if ($checked['error'] !== '') {
				$errors[] = array('field' => $code, 'message' => $checked['error']);
				continue;
			}
			$values[$code] = $checked['value'];
		}
		return array('values' => $values, 'errors' => $errors);
	}

	/**
	 * What becomes of the profiles of 1.1.0: each old field that holds something goes into the field of the member
	 * with the same code when that is a text or a long text, into a new field otherwise (#260).
	 *
	 * @param string[]             $used     Old fields with at least one value
	 * @param array<string,string> $existing Code => kind of every field the member has, empty for a kind a form cannot show
	 * @return array<string,array{code:string,create:bool,label:string,kind:string}> Old field => where it goes
	 */
	public static function migration(array $used, array $existing)
	{
		$plan = array();
		$taken = array_keys($existing);
		foreach (self::OLD_FIELDS as $old => $definition) {
			if (!in_array($old, $used, true)) {
				continue;
			}
			if (isset($existing[$old]) && in_array($existing[$old], array('text', 'textarea'), true)) {
				$plan[$old] = array('code' => $old, 'create' => false, 'label' => $definition[0], 'kind' => $existing[$old]);
				continue;
			}
			$code = VereineApplicationFormRules::code($old, $taken);
			$taken[] = $code;
			$plan[$old] = array('code' => $code, 'create' => true, 'label' => $definition[0], 'kind' => $definition[1]);
		}
		return $plan;
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
	 * The photo as the profile names it: its checksum, size, type and time, null without photo.
	 *
	 * @param array|null $photo sha256, size, content_type, updated_at - or null
	 * @return array<string,mixed>|null
	 */
	public static function photo($photo)
	{
		return $photo ? array(
			'sha256' => (string) $photo['sha256'], 'size' => (int) $photo['size'],
			'content_type' => (string) $photo['content_type'], 'updated_at' => (string) $photo['updated_at'],
		) : null;
	}
}
