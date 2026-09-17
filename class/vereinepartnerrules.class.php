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
 * \file    class/vereinepartnerrules.class.php
 * \ingroup vereine
 * \brief   Rules for members and their third parties: matching, differences, categories, customer type.
 *
 * Plain PHP without Dolibarr, so tests/run.php checks every decision without a database.
 * Members and third parties are passed as arrays with the keys documented per method.
 */

/**
 * What belongs together between a member and a third party, and what should change.
 */
class VereinePartnerRules
{
	/** Dolibarr's member statuses (Adherent::STATUS_*), repeated so the rules need no Dolibarr. */
	const STATUS_DRAFT = -1;
	const STATUS_VALIDATED = 1;
	const STATUS_RESILIATED = 0;
	const STATUS_EXCLUDED = -2;

	/** A match on the e-mail address. */
	const MATCH_EMAIL = 'email';

	/** A match on name and postcode. */
	const MATCH_NAME_ZIP = 'name_zip';

	/** Age of majority in Austria. */
	const AGE_OF_MAJORITY = 18;

	/**
	 * An e-mail address as it is compared: trimmed and lower case.
	 *
	 * @param string|null $email Address
	 * @return string
	 */
	public static function normalizeEmail($email)
	{
		return strtolower(trim((string) $email));
	}

	/**
	 * A name as it is compared: lower case, single spaces, no punctuation around words.
	 *
	 * @param string|null $name Name
	 * @return string
	 */
	public static function normalizeName($name)
	{
		$name = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $name), 'UTF-8') : strtolower(trim((string) $name));
		$name = (string) preg_replace('/[\s,.;]+/u', ' ', $name);
		return trim($name);
	}

	/**
	 * The name a third party of this member carries, the way Dolibarr's create_from_member builds it.
	 *
	 * @param array{morphy:string,firstname:string,lastname:string,company:string} $member Member
	 * @return string
	 */
	public static function partnerName(array $member)
	{
		if ($member['morphy'] === 'mor' && trim($member['company']) !== '') {
			return trim($member['company']);
		}
		return trim(trim($member['firstname']).' '.trim($member['lastname']));
	}

	/**
	 * Third parties that could be this member's, best first. Nothing is ever linked automatically from here.
	 *
	 * @param array{morphy:string,firstname:string,lastname:string,company:string,email:string,zip:string} $member   Member
	 * @param array<int,array{id:int,name:string,email:string,zip:string,linked_member:int}>                $partners Third parties to compare with
	 * @return array<int,array{id:int,match:string}>
	 */
	public static function candidates(array $member, array $partners)
	{
		$email = self::normalizeEmail($member['email']);
		$name = self::normalizeName(self::partnerName($member));
		$zip = trim((string) $member['zip']);
		$byEmail = array();
		$byName = array();
		foreach ($partners as $partner) {
			if (!empty($partner['linked_member'])) {
				continue;
			}
			if ($email !== '' && self::normalizeEmail($partner['email']) === $email) {
				$byEmail[] = array('id' => (int) $partner['id'], 'match' => self::MATCH_EMAIL);
			} elseif ($name !== '' && $zip !== '' && self::normalizeName($partner['name']) === $name && trim((string) $partner['zip']) === $zip) {
				$byName[] = array('id' => (int) $partner['id'], 'match' => self::MATCH_NAME_ZIP);
			}
		}
		return array_merge($byEmail, $byName);
	}

	/**
	 * Fields in which a linked member and its third party disagree.
	 *
	 * Dolibarr copies name and address to the third party when the member is saved,
	 * but not the other way round - a third party edited directly drifts apart.
	 *
	 * @param array{email:string,address:string,zip:string,town:string} $member  Member
	 * @param array{email:string,address:string,zip:string,town:string} $partner Third party
	 * @return array<string,array{member:string,partner:string}> Keyed by field
	 */
	public static function differences(array $member, array $partner)
	{
		$found = array();
		if (self::normalizeEmail($member['email']) !== self::normalizeEmail($partner['email'])) {
			$found['email'] = array('member' => trim($member['email']), 'partner' => trim($partner['email']));
		}
		foreach (array('address', 'zip', 'town') as $field) {
			$left = self::normalizeName($member[$field]);
			$right = self::normalizeName($partner[$field]);
			if ($left !== $right) {
				$found[$field] = array('member' => trim($member[$field]), 'partner' => trim($partner[$field]));
			}
		}
		return $found;
	}

	/**
	 * Which of the two categories a third party carries for a member status.
	 *
	 * @param int  $status        Member status (STATUS_*)
	 * @param bool $everValidated Whether the member was ever validated
	 * @param bool $deleted       Whether the member was deleted
	 * @return array{member:bool,former:bool}|null Null when the categories stay as they are (a draft)
	 */
	public static function categoriesFor($status, $everValidated, $deleted = false)
	{
		if ($deleted) {
			return array('member' => false, 'former' => (bool) $everValidated);
		}
		switch ((int) $status) {
			case self::STATUS_VALIDATED:
				return array('member' => true, 'former' => false);
			case self::STATUS_RESILIATED:
			case self::STATUS_EXCLUDED:
				return array('member' => false, 'former' => true);
			default:
				return null;
		}
	}

	/**
	 * What to do with the customer type of a member's third party.
	 *
	 * The type is only filled in when it is empty. A third party that already has
	 * another type - a sole trader who is also a member - is reported, never changed:
	 * the dunning module charges fees by customer type.
	 *
	 * @param string $morphy      'phy' natural person or 'mor' legal entity
	 * @param string $currentCode Current customer type code of the third party, '' when none
	 * @param string $naturalCode Configured code for natural persons, '' for none
	 * @param string $legalCode   Configured code for legal entities, '' for none
	 * @return array{set:string,mismatch:bool} set is the code to write or ''
	 */
	public static function customerType($morphy, $currentCode, $naturalCode, $legalCode)
	{
		$wanted = $morphy === 'mor' ? (string) $legalCode : (string) $naturalCode;
		$current = (string) $currentCode;
		if ($wanted === '' || $current === $wanted) {
			return array('set' => '', 'mismatch' => false);
		}
		if ($current === '' || $current === '0') {
			return array('set' => $wanted, 'mismatch' => false);
		}
		return array('set' => '', 'mismatch' => true);
	}

	/**
	 * The customer flag a member's third party needs: a customer, and still a prospect if it was one.
	 *
	 * @param int $client Dolibarr's client flag: 0 none, 1 customer, 2 prospect, 3 both
	 * @return int
	 */
	public static function customerFlag($client)
	{
		$client = (int) $client;
		if ($client === 1 || $client === 3) {
			return $client;
		}
		return $client === 2 ? 3 : 1;
	}

	/**
	 * Whether a person is under age on a day.
	 *
	 * @param string $birth Birth date YYYY-MM-DD, '' when unknown
	 * @param string $today Day YYYY-MM-DD
	 * @return bool False when the birth date is unknown
	 */
	public static function isMinor($birth, $today)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $birth, $b) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $today, $t)) {
			return false;
		}
		$age = (int) $t[1] - (int) $b[1];
		if ((int) $t[2] < (int) $b[2] || ((int) $t[2] === (int) $b[2] && (int) $t[3] < (int) $b[3])) {
			$age--;
		}
		return $age < self::AGE_OF_MAJORITY;
	}
}
