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
 * \file    class/vereineprofile.class.php
 * \ingroup vereine
 * \brief   Country profiles: which register, which authority, which rules apply.
 *
 * Plain PHP without Dolibarr, so tests/run.php checks it without a database.
 */

/**
 * The country profiles an association can choose.
 */
class VereineProfile
{
	/** Austria: Vereinsgesetz 2002, register number is the ZVR-Zahl. */
	const AUSTRIA = 'AT';

	/** Germany: BGB, register number is the VR number of a district court. */
	const GERMANY = 'DE';

	/** Longest purpose text the setup page accepts. */
	const PURPOSE_MAX_LENGTH = 1000;

	/**
	 * Every profile, in the order the setup page offers them.
	 *
	 * @return string[]
	 */
	public static function codes()
	{
		return array(self::AUSTRIA, self::GERMANY);
	}

	/**
	 * Whether a profile code is one the module knows.
	 *
	 * @param string $code Profile code
	 * @return bool
	 */
	public static function isSupported($code)
	{
		return in_array((string) $code, self::codes(), true);
	}

	/**
	 * Whether a profile carries its tax and legal rules yet, or is a preview.
	 *
	 * @param string $code Profile code
	 * @return bool
	 */
	public static function isComplete($code)
	{
		return $code === self::AUSTRIA;
	}

	/**
	 * The profile a new installation starts with.
	 *
	 * @param string $countryCode ISO code of the company's country
	 * @return string
	 */
	public static function suggestFromCountry($countryCode)
	{
		return strtoupper((string) $countryCode) === self::GERMANY ? self::GERMANY : self::AUSTRIA;
	}

	/**
	 * The kind of register number: ZVR for Austria, VR for Germany.
	 *
	 * @param string $code Profile code
	 * @return string
	 */
	public static function registerKind($code)
	{
		return $code === self::GERMANY ? 'VR' : 'ZVR';
	}

	/**
	 * A register number the way it is stored and printed.
	 *
	 * An Austrian ZVR-Zahl is digits only; people copy it with spaces or dots.
	 * A German VR number keeps its letters ("VR 12345 B") with single spaces.
	 *
	 * @param string $code  Profile code
	 * @param string $value What was entered
	 * @return string
	 */
	public static function normalizeRegisterNumber($code, $value)
	{
		$value = trim((string) $value);
		if ($code === self::AUSTRIA) {
			return (string) preg_replace('/[\s.]+/', '', $value);
		}
		$value = (string) preg_replace('/\s+/', ' ', $value);
		if (preg_match('/^vr\s*(\d.*)$/i', $value, $matches)) {
			$value = 'VR '.$matches[1];
		}
		return $value;
	}

	/**
	 * Check a normalized register number.
	 *
	 * An empty number is allowed here: the overview reports it as missing.
	 *
	 * @param string $code  Profile code
	 * @param string $value Normalized register number
	 * @return string Empty if valid, otherwise the language key of the error
	 */
	public static function validateRegisterNumber($code, $value)
	{
		if ($value === '') {
			return '';
		}
		if ($code === self::AUSTRIA) {
			// The ZVR-Zahl is a random number of one to ten digits (§ 18 VerG).
			return preg_match('/^\d{1,10}$/', $value) ? '' : 'VereineErrorZvrFormat';
		}
		return preg_match('/^VR \d{1,7}( [A-Z]{1,3})?$/', $value) ? '' : 'VereineErrorVrFormat';
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
