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
 * \file    class/vereineapplicationformrules.class.php
 * \ingroup vereine
 * \brief   Which fields an application for membership asks for (#216): one list for the PDF and the web, plain PHP.
 *
 * The printed form and an application that comes in over a website follow the same list. What the PDF
 * marks as required, the web refuses when it is missing; there is no second list that could drift apart.
 * Besides the fields of Dolibarr's member card, the association may put its own fields on the form:
 * the additional fields of the member, such as a gamer tag, each with its own switch whether it must be
 * filled in.
 */

/**
 * Rules of the fields of an application.
 */
class VereineApplicationFormRules
{
	/** Without a name there is no member; these two are always required. */
	const ALWAYS = array('lastname', 'firstname');

	/** Fields the association may require, in the order of the form. */
	const REQUIRABLE = array('address', 'zip', 'town', 'birth', 'gender', 'phone', 'email');

	/** What is required as long as the association has not said otherwise: the address the register needs. */
	const DEFAULT_REQUIRED = 'address,zip,town';

	/** Fields an application over the web carries; the web knows no gender field. */
	const WEB_FIELDS = array('lastname', 'firstname', 'address', 'zip', 'town', 'birth', 'phone', 'email');

	/** How long the value of an own field may be. */
	const EXTRA_MAX = 255;

	/** How long a long text of an own field may be (#226). */
	const TEXTAREA_MAX = 2000;

	/** Kinds of own fields the form can ask for, in the order the setup offers them (#226). */
	const KINDS = array('text', 'textarea', 'number', 'date', 'boolean', 'select', 'multi');

	/** Dolibarr's type and size of a new field of each kind. */
	const DOLIBARR_TYPES = array('text' => array('varchar', '255'), 'textarea' => array('text', '2000'), 'number' => array('double', '24,8'),
		'date' => array('date', ''), 'boolean' => array('boolean', ''), 'select' => array('select', ''), 'multi' => array('checkbox', ''));

	/**
	 * The kind of an additional field of Dolibarr, as a form can ask for it.
	 *
	 * Fields that point into other tables or hold secrets cannot be asked on a form; they get no kind.
	 *
	 * @param string $type Dolibarr's type of the field
	 * @return string One of KINDS, empty when a form cannot ask for it
	 */
	public static function kindOf($type)
	{
		$map = array('varchar' => 'text', 'phone' => 'text', 'mail' => 'text', 'url' => 'text', 'ip' => 'text', 'text' => 'textarea', 'html' => 'textarea',
			'int' => 'number', 'double' => 'number', 'price' => 'number', 'date' => 'date', 'datetime' => 'date', 'boolean' => 'boolean',
			'select' => 'select', 'radio' => 'select', 'checkbox' => 'multi');
		return isset($map[(string) $type]) ? $map[(string) $type] : '';
	}

	/**
	 * A code for a new field from its label: small letters, digits and underscores, not yet taken.
	 *
	 * @param string   $label What the field is called
	 * @param string[] $taken Codes that exist
	 * @return string Empty when the label gives no letters
	 */
	public static function code($label, array $taken)
	{
		$code = strtr(mb_strtolower(trim((string) $label), 'UTF-8'), array('ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'));
		$code = trim((string) preg_replace('/[^a-z0-9]+/', '_', (string) $code), '_');
		if ($code === '') {
			return '';
		}
		// Dolibarr wants a letter first, and the module's own fields keep their prefix.
		if (!preg_match('/^[a-z]/', $code) || strpos($code, 'vereine_') === 0) {
			$code = 'feld_'.$code;
		}
		$code = substr($code, 0, 50);
		$candidate = $code;
		$number = 2;
		while (in_array($candidate, $taken, true)) {
			$candidate = substr($code, 0, 46).'_'.$number;
			$number++;
		}
		return $candidate;
	}

	/**
	 * The options of a choice, one per line as the setup takes them: code => label.
	 *
	 * @param string $text One option per line
	 * @return array<string,string>
	 */
	public static function options($text)
	{
		$options = array();
		foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
			$label = trim($line);
			if ($label === '') {
				continue;
			}
			$code = self::code($label, array_keys($options));
			if ($code !== '') {
				$options[$code] = mb_substr($label, 0, 128, 'UTF-8');
			}
		}
		return $options;
	}

	/**
	 * One value of an own field as the web sent it, checked by the kind of the field.
	 *
	 * @param string              $code  Code of the field, for the message
	 * @param mixed               $value What was sent
	 * @param array<string,mixed> $spec  kind, options (for a choice), max, integer
	 * @return array{value:string|null,error:string} Value null when nothing was given
	 */
	public static function checkValue($code, $value, array $spec)
	{
		$kind = isset($spec['kind']) ? (string) $spec['kind'] : 'text';
		$name = 'fields.'.$code;
		if ($value === null || $value === '' || $value === array()) {
			return array('value' => null, 'error' => '');
		}
		if ($kind === 'multi') {
			$list = is_array($value) ? $value : explode(',', (string) $value);
			$chosen = array();
			foreach ($list as $one) {
				if (!is_scalar($one) || !isset($spec['options'][trim((string) $one)])) {
					return array('value' => null, 'error' => $name.' must be a list of: '.implode(', ', array_keys($spec['options'])));
				}
				$chosen[trim((string) $one)] = true;
			}
			return array('value' => implode(',', array_keys($chosen)), 'error' => '');
		}
		if ($kind === 'boolean' && is_bool($value)) {
			return array('value' => $value ? '1' : '0', 'error' => '');
		}
		if (!is_scalar($value)) {
			return array('value' => null, 'error' => $name.' must be text');
		}
		$text = trim((string) $value);
		if ($text === '') {
			return array('value' => null, 'error' => '');
		}
		switch ($kind) {
			case 'number':
				$number = str_replace(',', '.', $text);
				if (!is_numeric($number) || (!empty($spec['integer']) && !preg_match('/^-?\d+$/', $number))) {
					return array('value' => null, 'error' => $name.' must be '.(!empty($spec['integer']) ? 'a whole number' : 'a number'));
				}
				return array('value' => $number, 'error' => '');
			case 'date':
				if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
					return array('value' => null, 'error' => $name.' must be a date (YYYY-MM-DD)');
				}
				return array('value' => $text, 'error' => '');
			case 'boolean':
				$yes = array('1', 'true', 'yes', 'ja', 'on');
				$no = array('0', 'false', 'no', 'nein', 'off');
				$lower = strtolower($text);
				if (!in_array($lower, $yes, true) && !in_array($lower, $no, true)) {
					return array('value' => null, 'error' => $name.' must be true or false');
				}
				return array('value' => in_array($lower, $yes, true) ? '1' : '0', 'error' => '');
			case 'select':
				if (!isset($spec['options'][$text])) {
					return array('value' => null, 'error' => $name.' must be one of: '.implode(', ', array_keys($spec['options'])));
				}
				return array('value' => $text, 'error' => '');
		}
		$max = $kind === 'textarea' ? self::TEXTAREA_MAX : self::EXTRA_MAX;
		if (!empty($spec['max']) && (int) $spec['max'] < $max) {
			$max = (int) $spec['max'];
		}
		if (mb_strlen($text, 'UTF-8') > $max) {
			return array('value' => null, 'error' => $name.' is longer than '.$max.' characters');
		}
		return array('value' => $text, 'error' => '');
	}

	/**
	 * The required fields of the form: the name always, and what the association chose.
	 *
	 * @param mixed $chosen Comma separated list or array of field names
	 * @return string[] In the order of the form
	 */
	public static function required($chosen)
	{
		$list = is_array($chosen) ? $chosen : explode(',', (string) $chosen);
		$wanted = array();
		foreach ($list as $value) {
			if (is_scalar($value)) {
				$wanted[trim((string) $value)] = true;
			}
		}
		$required = self::ALWAYS;
		foreach (self::REQUIRABLE as $field) {
			if (isset($wanted[$field])) {
				$required[] = $field;
			}
		}
		return $required;
	}

	/**
	 * The own fields the association put on the form, only ones the member really has.
	 *
	 * @param mixed    $stored JSON object of code => 1 when required, 0 when optional
	 * @param string[] $known  Codes of the additional fields of the member
	 * @return array<string,bool> Code => required, in the order they were stored
	 */
	public static function extraFields($stored, array $known)
	{
		$decoded = is_array($stored) ? $stored : json_decode((string) $stored, true);
		if (!is_array($decoded)) {
			return array();
		}
		$fields = array();
		foreach ($decoded as $code => $required) {
			$code = (string) $code;
			// The module's own fields are kept by the module; an association does not put them on a form.
			if (in_array($code, $known, true) && strpos($code, 'vereine_') !== 0) {
				$fields[$code] = !empty($required);
			}
		}
		return $fields;
	}

	/**
	 * What is wrong with an application that came in over the web, measured against the form.
	 *
	 * @param array<string,mixed>  $application The application as the rules of the consents normalised it
	 * @param string[]             $required    Required fields of the form, see required()
	 * @param array<string,bool>   $extra       Own fields of the form, see extraFields()
	 * @param mixed                $sent        What the web sent as fields: code => value
	 * @param array<string,array<string,mixed>> $specs Kind, options and length of each own field (#226); text when missing
	 * @return array{errors:string[],fields:array<string,string>} The own fields that are taken, cleaned
	 */
	public static function checkWeb(array $application, array $required, array $extra, $sent, array $specs = array())
	{
		$errors = array();
		foreach ($required as $field) {
			// A field the web cannot send is not held against it; the form asks for it on paper.
			if (in_array($field, self::WEB_FIELDS, true) && trim((string) (isset($application[$field]) ? $application[$field] : '')) === '') {
				$errors[] = $field.' is required';
			}
		}
		$given = is_array($sent) ? $sent : array();
		$fields = array();
		// A field that came but was refused for its form is named once, not a second time as missing.
		$refused = array();
		foreach ($given as $code => $value) {
			$code = (string) $code;
			if (!array_key_exists($code, $extra)) {
				$errors[] = 'fields.'.$code.' is not a field of the application';
				continue;
			}
			$checked = self::checkValue($code, $value, isset($specs[$code]) ? $specs[$code] : array('kind' => 'text'));
			if ($checked['error'] !== '') {
				$errors[] = $checked['error'];
				$refused[$code] = true;
				continue;
			}
			if ($checked['value'] !== null) {
				$fields[$code] = $checked['value'];
			}
		}
		foreach ($extra as $code => $mustHave) {
			if ($mustHave && !isset($fields[$code]) && !isset($refused[$code])) {
				$errors[] = 'fields.'.$code.' is required';
			}
		}
		return array('errors' => array_values(array_unique($errors)), 'fields' => $fields);
	}

	/**
	 * The fee of the year of joining in plain numbers: which part of the fee year costs how much.
	 *
	 * Worked out with the same rule the fee run uses, for the first day of every part, so the form never
	 * promises an amount the invoice does not carry.
	 *
	 * @param array<string,mixed> $model  Fee model, see VereineFeeRules::normalize()
	 * @param int                 $year   Year the fee year starts in
	 * @return array<int,array{from:string,to:string,amount:float}> Empty when nothing is prorated
	 */
	public static function prorationSteps(array $model, $year)
	{
		require_once __DIR__.'/vereinefeerules.class.php';

		$model = VereineFeeRules::normalize($model);
		$periodMonths = VereineFeeRules::periodMonths($model);
		if ($model['start_month'] < 1 || $model['proration'] === VereineFeeRules::PRORATION_NONE || $model['amount'] === null
			|| $periodMonths === null) {
			return array();
		}
		$partMonths = VereineFeeRules::PRORATION_MONTHS[$model['proration']];
		if ($periodMonths % $partMonths !== 0) {
			$partMonths = 1;
		}
		$steps = array();
		for ($offset = 0; $offset < $periodMonths; $offset += $partMonths) {
			$from = self::monthStart((int) $year, $model['start_month'] + $offset);
			$to = VereineFeeRules::addDays(self::monthStart((int) $year, $model['start_month'] + $offset + $partMonths), -1);
			$fee = VereineFeeRules::nextFee($model, $from, '');
			$steps[] = array('from' => $from, 'to' => $to, 'amount' => $fee !== null && $fee['amount'] !== null ? (float) $fee['amount'] : 0.0);
		}
		return $steps;
	}

	/**
	 * The first day of a month counted from January of a year, past December into the next years.
	 *
	 * @param int $year  Year
	 * @param int $month Month, 1 and up
	 * @return string YYYY-MM-DD
	 */
	private static function monthStart($year, $month)
	{
		$index = ((int) $year) * 12 + ((int) $month - 1);
		return sprintf('%04d-%02d-01', intdiv($index, 12), $index % 12 + 1);
	}
}
