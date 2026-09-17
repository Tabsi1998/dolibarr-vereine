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
 * \file    class/vereinesepa.class.php
 * \ingroup vereine
 * \brief   SEPA direct debit mandates and pre-notification, plain PHP.
 *
 * Dates are strings YYYY-MM-DD.
 */

require_once __DIR__.'/vereinefeerules.class.php';
require_once __DIR__.'/vereineexitrules.class.php';

/**
 * Rules of SEPA direct debits for fees.
 */
class VereineSepa
{
	/** A mandate not used for this many months has expired (SEPA Core rulebook). */
	const EXPIRY_MONTHS = 36;
	/** Days the payer is told before the collection, unless agreed otherwise. */
	const DEFAULT_NOTICE_DAYS = 14;
	/** Most days of pre-notification that can be set. */
	const MAX_NOTICE_DAYS = 60;

	/** Mandate with reference and signature date, used within 36 months. */
	const MANDATE_VALID = 'valid';
	/** Mandate not used for 36 months. */
	const MANDATE_EXPIRED = 'expired';
	/** No default bank account with mandate reference and signature date. */
	const MANDATE_NONE = 'none';

	/**
	 * State of a mandate on a day.
	 *
	 * @param string $reference      Mandate reference (RUM)
	 * @param string $signedOn       Day the mandate was signed
	 * @param string $lastCollection Day of the last collection with this payer, empty for none
	 * @param string $today          Today
	 * @return string One of the MANDATE constants
	 */
	public static function mandateStatus($reference, $signedOn, $lastCollection, $today)
	{
		if (trim((string) $reference) === '' || !VereineFeeRules::isDate($signedOn)) {
			return self::MANDATE_NONE;
		}
		$from = (VereineFeeRules::isDate($lastCollection) && $lastCollection > $signedOn) ? $lastCollection : $signedOn;
		return VereineExitRules::addMonths($from, self::EXPIRY_MONTHS) < $today ? self::MANDATE_EXPIRED : self::MANDATE_VALID;
	}

	/**
	 * Days of pre-notification that can be used.
	 *
	 * @param mixed $days Setting
	 * @return int 1 to MAX_NOTICE_DAYS, DEFAULT_NOTICE_DAYS for anything else
	 */
	public static function noticeDays($days)
	{
		return (is_numeric($days) && (int) $days == $days && (int) $days >= 1 && (int) $days <= self::MAX_NOTICE_DAYS) ? (int) $days : self::DEFAULT_NOTICE_DAYS;
	}

	/**
	 * Earliest day of the collection after pre-notification on a day.
	 *
	 * @param string $day  Day of the invoice
	 * @param int    $days Days of pre-notification
	 * @return string
	 */
	public static function collectionDay($day, $days)
	{
		return VereineFeeRules::addDays($day, self::noticeDays($days));
	}
}
