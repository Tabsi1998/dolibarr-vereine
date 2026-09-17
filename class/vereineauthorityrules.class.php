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
 * \file    class/vereineauthorityrules.class.php
 * \ingroup vereine
 * \brief   Letters of the association to its association authority in Austria, plain PHP.
 *
 * Dates are strings YYYY-MM-DD.
 */

/**
 * Rules of letters to the association authority.
 */
class VereineAuthorityRules
{
	/** New representatives (§ 14 (2) VerG). */
	const KIND_REPRESENTATIVES = 'representatives';
	/** Change of the statutes (§ 14 (1) VerG). */
	const KIND_STATUTES = 'statutes';
	/** New address for service (§ 14 (3) VerG). */
	const KIND_ADDRESS = 'address';
	/** Extract of the register of associations (§ 17 VerG). */
	const KIND_EXTRACT = 'extract';
	/** Voluntary dissolution (§ 28 (2) VerG). */
	const KIND_DISSOLUTION = 'dissolution';
	/** Founding of the association (§ 11 VerG). */
	const KIND_FOUNDING = 'founding';
	/** Longer deadline for the first appointment of representatives (§ 2 (3) VerG). */
	const KIND_EXTENSION = 'extension';
	/** Letters written on the letters page, in the order they are offered; representatives are written under Board and functions. */
	const KINDS = array('statutes', 'address', 'extract', 'dissolution', 'founding', 'extension');
	/** Letters due within four weeks after what they report. */
	const KINDS_WITH_DEADLINE = array('representatives', 'statutes', 'address', 'dissolution');
	/** Days to report to the authority. */
	const DEADLINE_DAYS = 28;

	/** Extract with the current representatives. */
	const EXTRACT_CURRENT = 'current';
	/** Extract with birth date, place of birth and address of the representatives (§ 16 (1) no. 8 VerG), for the association itself. */
	const EXTRACT_FULL = 'full';
	/** Extract with the representatives on an earlier day (§ 17 (2) VerG). */
	const EXTRACT_AT = 'at';
	/** All kinds of extracts. */
	const EXTRACTS = array('current', 'full', 'at');

	/**
	 * Municipalities where the Landespolizeidirektion is the association authority (§ 9 (1) VerG with § 8 SPG).
	 *
	 * @return array<string,string> Lower-case municipality => authority
	 */
	public static function policeTowns()
	{
		return array(
			'eisenstadt' => 'Landespolizeidirektion Burgenland', 'rust' => 'Landespolizeidirektion Burgenland',
			'graz' => 'Landespolizeidirektion Steiermark', 'leoben' => 'Landespolizeidirektion Steiermark',
			'innsbruck' => 'Landespolizeidirektion Tirol',
			'klagenfurt' => 'Landespolizeidirektion Kärnten', 'klagenfurt am wörthersee' => 'Landespolizeidirektion Kärnten', 'villach' => 'Landespolizeidirektion Kärnten',
			'linz' => 'Landespolizeidirektion Oberösterreich', 'steyr' => 'Landespolizeidirektion Oberösterreich', 'wels' => 'Landespolizeidirektion Oberösterreich',
			'salzburg' => 'Landespolizeidirektion Salzburg',
			'st. pölten' => 'Landespolizeidirektion Niederösterreich', 'sankt pölten' => 'Landespolizeidirektion Niederösterreich',
			'wiener neustadt' => 'Landespolizeidirektion Niederösterreich', 'schwechat' => 'Landespolizeidirektion Niederösterreich',
			'wien' => 'Landespolizeidirektion Wien',
		);
	}

	/**
	 * The Landespolizeidirektion responsible for a seat, or empty where the district authority is.
	 *
	 * @param string $town Municipality of the seat
	 * @return string
	 */
	public static function policeAuthorityFor($town)
	{
		$town = mb_strtolower(trim((string) $town), 'UTF-8');
		$towns = self::policeTowns();
		return isset($towns[$town]) ? $towns[$town] : '';
	}

	/**
	 * Association authorities in Tyrol to choose from (tirol.gv.at and oesterreich.gv.at, read 17 September 2026).
	 *
	 * @return array<string,array{name:string,address:string,email:string}> By code
	 */
	public static function suggestions()
	{
		return array(
			'lpd_tirol' => array('name' => 'Landespolizeidirektion Tirol', 'address' => "Innrain 34\n6020 Innsbruck", 'email' => 'LPD-T@polizei.gv.at'),
			'bh_imst' => array('name' => 'Bezirkshauptmannschaft Imst', 'address' => "Stadtplatz 1\n6460 Imst", 'email' => 'bh.imst@tirol.gv.at'),
			'bh_innsbruck' => array('name' => 'Bezirkshauptmannschaft Innsbruck', 'address' => "Gilmstraße 2\n6020 Innsbruck", 'email' => 'bh.innsbruck@tirol.gv.at'),
			'bh_kitzbuehel' => array('name' => 'Bezirkshauptmannschaft Kitzbühel', 'address' => "Josef-Herold-Straße 10\n6370 Kitzbühel", 'email' => 'bh.kitzbuehel@tirol.gv.at'),
			'bh_kufstein' => array('name' => 'Bezirkshauptmannschaft Kufstein', 'address' => "Bozner Platz 1\n6330 Kufstein", 'email' => 'bh.kufstein@tirol.gv.at'),
			'bh_landeck' => array('name' => 'Bezirkshauptmannschaft Landeck', 'address' => "Innstraße 5\n6500 Landeck", 'email' => 'bh.landeck@tirol.gv.at'),
			'bh_lienz' => array('name' => 'Bezirkshauptmannschaft Lienz', 'address' => "Dolomitenstraße 3\n9900 Lienz", 'email' => 'bh.lienz@tirol.gv.at'),
			'bh_reutte' => array('name' => 'Bezirkshauptmannschaft Reutte', 'address' => "Obermarkt 7\n6600 Reutte", 'email' => 'bh.reutte@tirol.gv.at'),
			'bh_schwaz' => array('name' => 'Bezirkshauptmannschaft Schwaz', 'address' => "Franz-Josef-Straße 25\n6130 Schwaz", 'email' => 'bh.schwaz@tirol.gv.at'),
		);
	}

	/**
	 * Entered data of a letter that can be used as it is.
	 *
	 * @param string $kind One of the KIND constants
	 * @param mixed  $data Entered data
	 * @return array<string,mixed>
	 */
	public static function normalize($kind, $data)
	{
		$data = is_array($data) ? $data : array();
		$text = function ($key, $max) use ($data) {
			$value = isset($data[$key]) && is_scalar($data[$key]) ? trim(str_replace("\r", '', (string) $data[$key])) : '';
			return mb_substr($value, 0, $max, 'UTF-8');
		};
		$letter = array('date' => $text('date', 10));
		if ($kind === self::KIND_ADDRESS) {
			$letter['address'] = $text('address', 255);
		} elseif ($kind === self::KIND_EXTRACT) {
			$letter['extract'] = in_array($text('extract', 16), self::EXTRACTS, true) ? $text('extract', 16) : self::EXTRACT_CURRENT;
		} elseif ($kind === self::KIND_DISSOLUTION) {
			$letter['effective'] = $text('effective', 128);
			$letter['assets'] = !empty($data['assets']);
			foreach (array('liquidator_name' => 128, 'liquidator_birth' => 10, 'liquidator_birth_place' => 128, 'liquidator_address' => 255, 'liquidator_start' => 10) as $key => $max) {
				$letter[$key] = $letter['assets'] ? $text($key, $max) : '';
			}
		} elseif ($kind === self::KIND_FOUNDING) {
			$letter['founders'] = !empty($data['founders']);
		} elseif ($kind === self::KIND_EXTENSION) {
			$letter['reason'] = $text('reason', 2000);
		}
		return $letter;
	}

	/**
	 * Problems of a letter before it is written.
	 *
	 * @param string              $kind   One of the KINDS
	 * @param array<string,mixed> $letter Normalized letter
	 * @return string[] Language keys, empty when fine
	 */
	public static function validate($kind, array $letter)
	{
		if (!in_array($kind, self::KINDS, true)) {
			return array('VereineLetterErrorKind');
		}
		$errors = array();
		// The day of the general assembly, of the new address, of the extract, or until when the deadline runs.
		if ($kind !== self::KIND_FOUNDING && !($kind === self::KIND_EXTRACT && $letter['extract'] !== self::EXTRACT_AT) && !self::isDate($letter['date'])) {
			$errors[] = 'VereineLetterErrorDate_'.$kind;
		}
		if ($kind === self::KIND_ADDRESS && $letter['address'] === '') {
			$errors[] = 'VereineLetterErrorAddress';
		}
		if ($kind === self::KIND_DISSOLUTION) {
			if ($letter['effective'] === '') {
				$errors[] = 'VereineLetterErrorEffective';
			}
			if ($letter['assets'] && ($letter['liquidator_name'] === '' || !self::isDate($letter['liquidator_birth']) || $letter['liquidator_birth_place'] === ''
				|| $letter['liquidator_address'] === '' || !self::isDate($letter['liquidator_start']))) {
				$errors[] = 'VereineLetterErrorLiquidator';
			}
		}
		if ($kind === self::KIND_EXTENSION && $letter['reason'] === '') {
			$errors[] = 'VereineLetterErrorReason';
		}
		return $errors;
	}

	/**
	 * Last day to send a letter, or empty for letters without a deadline.
	 *
	 * @param string $kind One of the KIND constants
	 * @param string $date Day of what the letter reports
	 * @return string YYYY-MM-DD or ''
	 */
	public static function deadline($kind, $date)
	{
		if (!in_array($kind, self::KINDS_WITH_DEADLINE, true) || !self::isDate($date)) {
			return '';
		}
		list($year, $month, $day) = array_map('intval', explode('-', $date));
		return gmdate('Y-m-d', gmmktime(12, 0, 0, $month, $day + self::DEADLINE_DAYS, $year));
	}

	/**
	 * Whether a text is a real date YYYY-MM-DD.
	 *
	 * @param string $date Text
	 * @return bool
	 */
	public static function isDate($date)
	{
		return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $parts) && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
	}
}
