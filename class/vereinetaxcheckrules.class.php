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
 * \file    class/vereinetaxcheckrules.class.php
 * \ingroup vereine
 * \brief   Suggestions of a tax profile for invoice lines written before the profiles existed.
 *
 * Only facts lead to a suggestion that is ticked: the product has a profile, the invoice belongs to
 * a membership fee, or a credit note's original line has one. A word in the text only preselects a
 * profile; somebody has to tick it. Everything else is to be checked by hand: sponsoring, merchandise
 * or drinks can belong to more than one area, and that is not for the module to decide.
 */

/**
 * Rules of the check of older invoice lines.
 */
class VereineTaxCheckRules
{
	/** The product of the line has a profile. */
	const REASON_PRODUCT = 'product';
	/** The invoice belongs to a membership fee in Dolibarr. */
	const REASON_FEE = 'fee';
	/** A credit note: the line of the original invoice has a profile. */
	const REASON_CREDIT = 'credit';
	/** The text says membership fee. */
	const REASON_MEMBER = 'member';
	/** The text says donation. */
	const REASON_DONATION = 'donation';
	/** Nothing clear: to be checked by hand. */
	const REASON_MANUAL = 'manual';
	/** Every reason, in the order they are tried. */
	const REASONS = array('product', 'fee', 'credit', 'member', 'donation', 'manual');

	/**
	 * A suggestion for a line without tax profile.
	 *
	 * @param array{product_profile:int,fee:bool,source_profile:int,text:string} $line  Facts of the line
	 * @param array<string,int>                                                 $codes Ids of the active profiles by code
	 * @return array{profile:int,certain:bool,reason:string} Certain suggestions are ticked, the others only preselected
	 */
	public static function suggest(array $line, array $codes)
	{
		if ((int) $line['product_profile'] > 0) {
			return array('profile' => (int) $line['product_profile'], 'certain' => true, 'reason' => self::REASON_PRODUCT);
		}
		if (!empty($line['fee']) && isset($codes['MITGLIEDSBEITRAG'])) {
			return array('profile' => (int) $codes['MITGLIEDSBEITRAG'], 'certain' => true, 'reason' => self::REASON_FEE);
		}
		if ((int) $line['source_profile'] > 0) {
			return array('profile' => (int) $line['source_profile'], 'certain' => true, 'reason' => self::REASON_CREDIT);
		}
		$text = function_exists('mb_strtolower') ? mb_strtolower((string) $line['text'], 'UTF-8') : strtolower((string) $line['text']);
		if (strpos($text, 'mitgliedsbeitrag') !== false && isset($codes['MITGLIEDSBEITRAG'])) {
			return array('profile' => (int) $codes['MITGLIEDSBEITRAG'], 'certain' => false, 'reason' => self::REASON_MEMBER);
		}
		if (preg_match('/(^|[^a-zäöüß])spende([^a-zäöüß]|$)/u', $text) && isset($codes['SPENDE'])) {
			return array('profile' => (int) $codes['SPENDE'], 'certain' => false, 'reason' => self::REASON_DONATION);
		}
		return array('profile' => 0, 'certain' => false, 'reason' => self::REASON_MANUAL);
	}

	/**
	 * Number and sum of the lines by suggested profile, for the preview; 0 collects those to check by hand.
	 *
	 * @param array<int,array{suggestion:array{profile:int},total:float}> $lines Lines with their suggestion and net total
	 * @return array<int,array{count:int,total:float}> By profile id
	 */
	public static function totals(array $lines)
	{
		$totals = array();
		foreach ($lines as $line) {
			$profile = (int) $line['suggestion']['profile'];
			if (!isset($totals[$profile])) {
				$totals[$profile] = array('count' => 0, 'total' => 0.0);
			}
			$totals[$profile]['count']++;
			$totals[$profile]['total'] = round($totals[$profile]['total'] + (float) $line['total'], 2);
		}
		ksort($totals);
		return $totals;
	}

	/**
	 * The profile of a credit note's line from the lines of its original invoice.
	 *
	 * The line with the same product counts; without a product, the original's profile when all its
	 * lines with a profile share it.
	 *
	 * @param int                                     $product  Product of the credit note's line, 0 for a free line
	 * @param array<int,array{product:int,profile:int}> $original Lines of the original invoice
	 * @return int 0 when it is not clear
	 */
	public static function sourceProfile($product, array $original)
	{
		$profiles = array();
		foreach ($original as $line) {
			if ((int) $line['profile'] <= 0) {
				continue;
			}
			if ((int) $product > 0 && (int) $line['product'] === (int) $product) {
				return (int) $line['profile'];
			}
			$profiles[(int) $line['profile']] = true;
		}
		return (int) $product <= 0 && count($profiles) === 1 ? (int) key($profiles) : 0;
	}
}
