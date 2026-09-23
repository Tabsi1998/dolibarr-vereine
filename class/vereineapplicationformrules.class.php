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
	 * @return array{errors:string[],fields:array<string,string>} The own fields that are taken, cleaned
	 */
	public static function checkWeb(array $application, array $required, array $extra, $sent)
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
			if (!is_scalar($value)) {
				$errors[] = 'fields.'.$code.' must be text';
				$refused[$code] = true;
				continue;
			}
			$text = trim((string) $value);
			if (mb_strlen($text, 'UTF-8') > self::EXTRA_MAX) {
				$errors[] = 'fields.'.$code.' is longer than '.self::EXTRA_MAX.' characters';
				$refused[$code] = true;
				continue;
			}
			if ($text !== '') {
				$fields[$code] = $text;
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
