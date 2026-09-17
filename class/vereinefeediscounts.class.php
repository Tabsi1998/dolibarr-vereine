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
 * \file    class/vereinefeediscounts.class.php
 * \ingroup vereine
 * \brief   Which discount a member gets on a fee, plain PHP.
 *
 * One discount per fee, in this order: an exemption of the member, a discount with a
 * proof that is still valid on the first day of the period, a discount by age on that day.
 * Dates are strings YYYY-MM-DD.
 */

/**
 * Rules of fee discounts.
 */
class VereineFeeDiscounts
{
	/** A discount for members of an age on the first day of the period. */
	const KIND_AGE = 'age';
	/** A discount for members with a proof, such as a student card, valid on the first day of the period. */
	const KIND_PROOF = 'proof';

	/** The fee is reduced by a percentage. */
	const MODE_PERCENT = 'percent';
	/** The fee is a fixed, lower amount for a whole period. */
	const MODE_AMOUNT = 'amount';
	/** No fee. */
	const MODE_FREE = 'free';

	/** Longest label of a discount. */
	const LABEL_MAX = 64;

	/**
	 * Full years of age on a day.
	 *
	 * @param string $birth Birth date
	 * @param string $day   Day
	 * @return int|null Null without a valid birth date or when the day is before it
	 */
	public static function age($birth, $day)
	{
		if (!self::isDate($birth) || !self::isDate($day) || $day < $birth) {
			return null;
		}
		$age = (int) substr($day, 0, 4) - (int) substr($birth, 0, 4);
		if (substr($day, 5) < substr($birth, 5)) {
			$age--;
		}
		return $age;
	}

	/**
	 * Problems of a discount rule before it is stored.
	 *
	 * @param array<string,mixed> $rule Keys label, kind, age_from, age_to, mode, value
	 * @return string[] Language keys of the problems, empty when the rule is fine
	 */
	public static function validate(array $rule)
	{
		$errors = array();
		$label = trim((string) (isset($rule['label']) ? $rule['label'] : ''));
		if ($label === '' || mb_strlen($label, 'UTF-8') > self::LABEL_MAX) {
			$errors[] = 'VereineDiscountErrorLabel';
		}
		$kind = isset($rule['kind']) ? $rule['kind'] : '';
		if (!in_array($kind, array(self::KIND_AGE, self::KIND_PROOF), true)) {
			$errors[] = 'VereineDiscountErrorKind';
		}
		if ($kind === self::KIND_AGE) {
			$from = self::optionalAge(isset($rule['age_from']) ? $rule['age_from'] : '');
			$to = self::optionalAge(isset($rule['age_to']) ? $rule['age_to'] : '');
			if ($from === false || $to === false || ($from === null && $to === null) || ($from !== null && $to !== null && $from > $to)) {
				$errors[] = 'VereineDiscountErrorAge';
			}
		}
		$mode = isset($rule['mode']) ? $rule['mode'] : '';
		$value = isset($rule['value']) ? $rule['value'] : '';
		if (!in_array($mode, array(self::MODE_PERCENT, self::MODE_AMOUNT, self::MODE_FREE), true)) {
			$errors[] = 'VereineDiscountErrorMode';
		} elseif ($mode === self::MODE_PERCENT && (!is_numeric($value) || (float) $value <= 0 || (float) $value >= 100)) {
			$errors[] = 'VereineDiscountErrorPercent';
		} elseif ($mode === self::MODE_AMOUNT && (!is_numeric($value) || (float) $value < 0)) {
			$errors[] = 'VereineDiscountErrorAmount';
		}
		return $errors;
	}

	/**
	 * The discount of a member on a fee period.
	 *
	 * @param array<int,array<string,mixed>> $rules  Active rules in their order, keys id, kind, type_id (0 for every member type), age_from, age_to, mode, value, label
	 * @param array<string,mixed>            $member Keys type_id, exempt (bool), exempt_reason, proof_rule (rule id or 0), proof_until, birth
	 * @param string                         $start  First day of the period
	 * @return array{kind:string,rule:array<string,mixed>|null,reason:string,notes:string[]}
	 *         kind is 'exempt', 'proof', 'age' or 'none'; notes are language keys of things to check
	 */
	public static function choose(array $rules, array $member, $start)
	{
		$notes = array();
		if (!empty($member['exempt'])) {
			return array('kind' => 'exempt', 'rule' => null, 'reason' => (string) (isset($member['exempt_reason']) ? $member['exempt_reason'] : ''), 'notes' => $notes);
		}
		$typeId = (int) (isset($member['type_id']) ? $member['type_id'] : 0);
		$applies = function ($rule) use ($typeId) {
			return (int) $rule['type_id'] === 0 || (int) $rule['type_id'] === $typeId;
		};

		$proofRule = (int) (isset($member['proof_rule']) ? $member['proof_rule'] : 0);
		if ($proofRule > 0) {
			foreach ($rules as $rule) {
				if ((int) $rule['id'] !== $proofRule || $rule['kind'] !== self::KIND_PROOF || !$applies($rule)) {
					continue;
				}
				$until = (string) (isset($member['proof_until']) ? $member['proof_until'] : '');
				if (self::isDate($until) && $until >= $start) {
					return array('kind' => 'proof', 'rule' => $rule, 'reason' => (string) $rule['label'], 'notes' => $notes);
				}
				$notes[] = self::isDate($until) ? 'VereineDiscountNoteProofExpired' : 'VereineDiscountNoteProofNoDate';
			}
		}

		$ageRules = array_values(array_filter($rules, function ($rule) use ($applies) {
			return $rule['kind'] === VereineFeeDiscounts::KIND_AGE && $applies($rule);
		}));
		if ($ageRules) {
			$age = self::age((string) (isset($member['birth']) ? $member['birth'] : ''), $start);
			if ($age === null) {
				$notes[] = 'VereineDiscountNoteNoBirth';
			} else {
				foreach ($ageRules as $rule) {
					$from = self::optionalAge($rule['age_from']);
					$to = self::optionalAge($rule['age_to']);
					if (($from === null || $age >= $from) && ($to === null || $age <= $to)) {
						return array('kind' => 'age', 'rule' => $rule, 'reason' => (string) $rule['label'], 'notes' => $notes);
					}
				}
			}
		}
		return array('kind' => 'none', 'rule' => null, 'reason' => '', 'notes' => $notes);
	}

	/**
	 * The fee amount of a whole period after a discount.
	 *
	 * @param float|null               $amount   Amount of the member type
	 * @param array<string,mixed>      $discount Result of choose()
	 * @return float|null
	 */
	public static function apply($amount, array $discount)
	{
		if ($discount['kind'] === 'exempt') {
			return 0.0;
		}
		if ($amount === null || $discount['rule'] === null) {
			return $amount;
		}
		$rule = $discount['rule'];
		if ($rule['mode'] === self::MODE_FREE) {
			return 0.0;
		}
		if ($rule['mode'] === self::MODE_PERCENT) {
			return round((float) $amount * (100 - (float) $rule['value']) / 100, 2);
		}
		return round(min((float) $amount, (float) $rule['value']), 2);
	}

	/**
	 * An age limit of a rule: empty for none, a whole number from 0 to 150.
	 *
	 * @param mixed $value Value
	 * @return int|null|false Null when empty, false when invalid
	 */
	public static function optionalAge($value)
	{
		if ($value === null || $value === '') {
			return null;
		}
		if (!preg_match('/^\d{1,3}$/', trim((string) $value)) || (int) $value > 150) {
			return false;
		}
		return (int) $value;
	}

	/**
	 * Whether a text is a real date YYYY-MM-DD.
	 *
	 * @param string $date Text
	 * @return bool
	 */
	private static function isDate($date)
	{
		return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $parts) && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
	}
}
