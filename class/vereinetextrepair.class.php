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
 * \file    class/vereinetextrepair.class.php
 * \ingroup vereine
 * \brief   Repairs line breaks that were stored as the two characters backslash and n.
 *
 * Until 0.5.2 the text areas of the module showed a line break as \n; saving the form again stored those
 * two characters. The repair runs when the module is enabled and changes only values that contain them.
 */

/**
 * Repair of stored line breaks.
 */
class VereineTextRepair
{
	/** Constants holding plain text. */
	const CONSTANTS = array('VEREINE_AUTHORITY_ADDRESS', 'VEREINE_PURPOSE');
	/** Constant holding the text fields of the statutes as JSON. */
	const CONST_STATUTE_TEXT = 'VEREINE_STATUTE_TEXT';

	/**
	 * A text with stored \r\n, \r and \n turned into real line breaks.
	 *
	 * @param string $value Stored text
	 * @return string
	 */
	public static function text($value)
	{
		return str_replace(array('\r\n', '\r', '\n'), "\n", (string) $value);
	}

	/**
	 * The text fields of the statutes repaired; a list item holding several lines becomes several items.
	 *
	 * @param array<string,mixed> $fields Decoded text fields
	 * @return array<string,mixed>
	 */
	public static function statuteText(array $fields)
	{
		foreach ($fields as $key => $value) {
			if (is_string($value)) {
				$fields[$key] = self::text($value);
			} elseif (is_array($value) && array_values($value) === $value) {
				$items = array();
				foreach ($value as $item) {
					foreach (is_string($item) ? explode("\n", self::text($item)) : array($item) as $line) {
						if (!is_string($line) || trim($line) !== '') {
							$items[] = is_string($line) ? trim($line) : $line;
						}
					}
				}
				$fields[$key] = $items;
			}
		}
		return $fields;
	}

	/**
	 * Repair the stored values of every entity.
	 *
	 * @param DoliDB $db Database handler
	 * @return int Number of repaired values, -1 on error
	 */
	public static function run($db)
	{
		$repaired = 0;
		$names = array_merge(self::CONSTANTS, array(self::CONST_STATUTE_TEXT));
		// Few and short values: compared here, not with LIKE, whose backslash escaping differs between databases.
		$sql = "SELECT rowid, name, value FROM ".MAIN_DB_PREFIX."const WHERE name IN ('".implode("','", $names)."')";
		$resql = $db->query($sql);
		if (!$resql) {
			return -1;
		}
		$changes = array();
		while ($obj = $db->fetch_object($resql)) {
			$value = (string) $obj->value;
			$new = $value;
			if ($obj->name === self::CONST_STATUTE_TEXT) {
				$decoded = json_decode($value, true);
				if (is_array($decoded) && self::statuteText($decoded) !== $decoded) {
					$new = json_encode(self::statuteText($decoded));
				}
			} else {
				$new = self::text($value);
			}
			if ($new !== $value) {
				$changes[] = "UPDATE ".MAIN_DB_PREFIX."const SET value = '".$db->escape($new)."' WHERE rowid = ".((int) $obj->rowid);
			}
		}
		$db->free($resql);
		foreach (array('vereine_consent_text' => 'text', 'vereine_taxprofile' => 'note') as $table => $column) {
			$resql = $db->query("SELECT rowid, ".$column." as value FROM ".MAIN_DB_PREFIX.$table);
			if (!$resql) {
				return -1;
			}
			while ($obj = $db->fetch_object($resql)) {
				$new = self::text((string) $obj->value);
				if ($new !== (string) $obj->value) {
					$changes[] = "UPDATE ".MAIN_DB_PREFIX.$table." SET ".$column." = '".$db->escape($new)."' WHERE rowid = ".((int) $obj->rowid);
				}
			}
			$db->free($resql);
		}
		foreach ($changes as $change) {
			if (!$db->query($change)) {
				return -1;
			}
			$repaired++;
		}
		return $repaired;
	}
}
