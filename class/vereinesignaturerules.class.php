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
 * \file    class/vereinesignaturerules.class.php
 * \ingroup vereine
 * \brief   Who signs which kind of document, and when a document is completely signed. Plain PHP.
 *
 * The model statutes of the Ministry of the Interior are the defaults: written documents of the
 * association are signed by the chair and the secretary, money matters by the chair and the
 * treasurer, and the secretary keeps the minutes. An association may set other functions.
 */

/**
 * Rules of the signatures.
 */
class VereineSignatureRules
{
	/** Letters and notices to the association authority. */
	const KIND_LETTER = 'letter';
	/** Minutes of a meeting. */
	const KIND_MINUTES = 'minutes';
	/** A single resolution. */
	const KIND_RESOLUTION = 'resolution';
	/** Report of the auditors. */
	const KIND_AUDIT_REPORT = 'audit_report';
	/** A money matter: a resolution with an effect on the assets of the association. */
	const KIND_MONEY = 'money';
	/** Kinds of document, in the order the setup page shows them. */
	const KINDS = array('letter', 'minutes', 'resolution', 'money', 'audit_report');

	/** Everybody named has to sign. */
	const MODE_ALL = 'all';
	/** At least a number of those named has to sign. */
	const MODE_MIN = 'min';
	/** Ways to sign, without the qualified signature, which comes with its own setting. */
	const MODE_LIST = array('all', 'min');

	/** Signed with the button in Dolibarr, after the password. */
	const WAY_CLICK = 'click';
	/** Signed on paper, uploaded as a scan. */
	const WAY_PAPER = 'paper';

	/**
	 * Who signs which kind of document, following the model statutes.
	 *
	 * @return array<string,array{roles:string[],mode:string,min:int}> By kind of document
	 */
	public static function defaults()
	{
		return array(
			// Written documents of the association: chair and secretary (model statutes of the BMI).
			self::KIND_LETTER => array('roles' => array('obmann', 'schriftfuehrung'), 'mode' => self::MODE_ALL, 'min' => 2),
			self::KIND_MINUTES => array('roles' => array('obmann', 'schriftfuehrung'), 'mode' => self::MODE_ALL, 'min' => 2),
			self::KIND_RESOLUTION => array('roles' => array('obmann', 'schriftfuehrung'), 'mode' => self::MODE_ALL, 'min' => 2),
			// Money matters: chair and treasurer, as § 13 Abs. 2 of the model statutes puts it. Statutes that
			// ask for the secretary as well are set up by adding that function here.
			self::KIND_MONEY => array('roles' => array('obmann', 'kassier'), 'mode' => self::MODE_ALL, 'min' => 2),
			// The auditors report themselves (§ 21 VerG).
			self::KIND_AUDIT_REPORT => array('roles' => array('rechnungspruefung'), 'mode' => self::MODE_ALL, 'min' => 2),
		);
	}

	/**
	 * Rules as entered that can be used as they are; a kind without a role is switched off.
	 *
	 * @param mixed    $data  Rules by kind of document
	 * @param string[] $codes Codes of the function catalogue
	 * @return array<string,array{roles:string[],mode:string,min:int}>
	 */
	public static function normalize($data, array $codes)
	{
		$defaults = self::defaults();
		$rules = array();
		foreach (self::KINDS as $kind) {
			$entered = is_array($data) && isset($data[$kind]) && is_array($data[$kind]) ? $data[$kind] : null;
			if ($entered === null) {
				// Nothing stored yet: the defaults apply, but only with functions the catalogue has.
				$roles = array_values(array_intersect($defaults[$kind]['roles'], $codes));
				$rules[$kind] = array('roles' => $roles, 'mode' => $defaults[$kind]['mode'], 'min' => $defaults[$kind]['min']);
				continue;
			}
			$roles = array();
			foreach (isset($entered['roles']) && is_array($entered['roles']) ? $entered['roles'] : array() as $role) {
				if (is_scalar($role) && in_array((string) $role, $codes, true) && !in_array((string) $role, $roles, true)) {
					$roles[] = (string) $role;
				}
			}
			$mode = isset($entered['mode']) && in_array($entered['mode'], self::MODE_LIST, true) ? (string) $entered['mode'] : self::MODE_ALL;
			$min = isset($entered['min']) && is_numeric($entered['min']) ? (int) $entered['min'] : count($roles);
			$rules[$kind] = array('roles' => $roles, 'mode' => $mode, 'min' => max(1, min($min, max(1, count($roles)))));
		}
		return $rules;
	}

	/**
	 * Whether a kind of document is signed at all.
	 *
	 * @param array<string,array<string,mixed>> $rules Normalized rules
	 * @param string                            $kind  Kind of document
	 * @return bool
	 */
	public static function wanted(array $rules, $kind)
	{
		return isset($rules[$kind]) && $rules[$kind]['roles'] !== array();
	}

	/**
	 * The people who have to sign a document: the holders of the named functions on a day.
	 *
	 * Somebody holding two of the named functions signs once, for the first of them. A named
	 * function nobody holds is reported, so the page can say what is missing.
	 *
	 * @param array<string,array<string,mixed>>                                    $rules   Normalized rules
	 * @param string                                                               $kind    Kind of document
	 * @param array<string,array<int,array{member_id:int,name:string}>>             $holders Holders by function code
	 * @param array<string,string>                                                 $labels  Label by function code
	 * @return array{people:array<int,array{member_id:int,role:string,label:string,name:string}>,vacant:string[]}
	 */
	public static function signers(array $rules, $kind, array $holders, array $labels)
	{
		$people = array();
		$vacant = array();
		$taken = array();
		foreach (self::wanted($rules, $kind) ? $rules[$kind]['roles'] : array() as $role) {
			$found = isset($holders[$role]) ? $holders[$role] : array();
			if (!$found) {
				$vacant[] = isset($labels[$role]) ? $labels[$role] : $role;
				continue;
			}
			foreach ($found as $holder) {
				if (isset($taken[(int) $holder['member_id']])) {
					continue;
				}
				$taken[(int) $holder['member_id']] = true;
				$people[] = array('member_id' => (int) $holder['member_id'], 'role' => $role,
					'label' => isset($labels[$role]) ? $labels[$role] : $role, 'name' => (string) $holder['name']);
			}
		}
		return array('people' => $people, 'vacant' => $vacant);
	}

	/**
	 * How many signatures a document needs.
	 *
	 * @param array<string,array<string,mixed>> $rules  Normalized rules
	 * @param string                            $kind   Kind of document
	 * @param int                               $people Number of people who have to sign
	 * @return int
	 */
	public static function needed(array $rules, $kind, $people)
	{
		if (!self::wanted($rules, $kind) || (int) $people < 1) {
			return 0;
		}
		if ($rules[$kind]['mode'] === self::MODE_MIN) {
			return (int) min((int) $people, max(1, (int) $rules[$kind]['min']));
		}
		return (int) $people;
	}

	/**
	 * Whether a document is completely signed.
	 *
	 * @param array<string,array<string,mixed>> $rules  Normalized rules
	 * @param string                            $kind   Kind of document
	 * @param int                               $people Number of people who have to sign
	 * @param int                               $signed Number of signatures given
	 * @return bool
	 */
	public static function complete(array $rules, $kind, $people, $signed)
	{
		$needed = self::needed($rules, $kind, $people);
		return $needed > 0 && (int) $signed >= $needed;
	}

	/**
	 * Problems of the entered rules.
	 *
	 * @param array<string,array<string,mixed>> $rules Normalized rules
	 * @return string[] Language keys
	 */
	public static function validate(array $rules)
	{
		$errors = array();
		foreach (self::KINDS as $kind) {
			$rule = isset($rules[$kind]) ? $rules[$kind] : array('roles' => array(), 'mode' => self::MODE_ALL, 'min' => 1);
			if ($rule['mode'] === self::MODE_MIN && $rule['roles'] === array()) {
				$errors[] = 'VereineSignatureErrorMinWithoutRole';
			}
		}
		return array_values(array_unique($errors));
	}
}
