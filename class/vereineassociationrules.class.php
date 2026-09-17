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
 * \file    class/vereineassociationrules.class.php
 * \ingroup vereine
 * \brief   Input rules for the data of an Austrian association: ZVR number, purpose, founding date. Plain PHP.
 */

/**
 * Rules for the association data the setup page stores.
 */
class VereineAssociationRules
{
	/** Country of every association the module serves. */
	const COUNTRY = 'AT';

	/** Longest purpose text the setup page accepts. */
	const PURPOSE_MAX_LENGTH = 1000;

	/**
	 * A ZVR number the way it is stored and printed: digits only, as people copy it with spaces or dots.
	 *
	 * @param string $value What was entered
	 * @return string
	 */
	public static function normalizeZvr($value)
	{
		return (string) preg_replace('/[\s.]+/', '', trim((string) $value));
	}

	/**
	 * Check a normalized ZVR number. An empty number is allowed here: the overview reports it as missing.
	 *
	 * @param string $value Normalized ZVR number
	 * @return string Empty if valid, otherwise the language key of the error
	 */
	public static function validateZvr($value)
	{
		if ($value === '') {
			return '';
		}
		// The ZVR number is a random number of one to ten digits (§ 18 VerG).
		return preg_match('/^\d{1,10}$/', $value) ? '' : 'VereineErrorZvrFormat';
	}

	/**
	 * Check the purpose text.
	 *
	 * @param string $value Purpose as entered
	 * @return string Empty if valid, otherwise the language key of the error
	 */
	public static function validatePurpose($value)
	{
		$length = function_exists('mb_strlen') ? mb_strlen((string) $value, 'UTF-8') : strlen((string) $value);
		return $length <= self::PURPOSE_MAX_LENGTH ? '' : 'VereineErrorPurposeTooLong';
	}

	/**
	 * Check a founding date entered as year, month and day.
	 *
	 * @param int $year  Year, 0 when left empty
	 * @param int $month Month, 0 when left empty
	 * @param int $day   Day, 0 when left empty
	 * @param int $today Current timestamp, for the check against the future
	 * @return string Empty if valid or completely empty, otherwise the language key of the error
	 */
	public static function validateFoundingDate($year, $month, $day, $today)
	{
		$year = (int) $year;
		$month = (int) $month;
		$day = (int) $day;
		if ($year === 0 && $month === 0 && $day === 0) {
			return '';
		}
		if (!checkdate($month, $day, $year) || $year < 1800) {
			return 'VereineErrorFoundingDate';
		}
		if (sprintf('%04d-%02d-%02d', $year, $month, $day) > gmdate('Y-m-d', (int) $today)) {
			return 'VereineErrorFoundingDateFuture';
		}
		return '';
	}
}
