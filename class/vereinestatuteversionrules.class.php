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
 * \file    class/vereinestatuteversionrules.class.php
 * \ingroup vereine
 * \brief   Which version of the statutes is in force on a day (#158), without guessing.
 *
 * Only versions the association decided and stored count; the text being edited never does. A version
 * is in force from its first day until the day before the next one begins. When two versions begin on
 * the same day, nobody can say which one holds: that is said, not decided by the higher number.
 */

/**
 * Versions of the statutes on a day.
 */
class VereineStatuteVersionRules
{
	/**
	 * Every version with its state and last day, and which one is in force.
	 *
	 * @param array<int,array{id:int,version:int,valid_from:string}> $versions Versions as stored
	 * @param string                                                 $day      The day, YYYY-MM-DD
	 * @return array{state:string,current:int,versions:array<int,array<string,mixed>>} state: in_force, none or ambiguous; current 0 unless in force
	 */
	public static function onDay(array $versions, $day)
	{
		usort($versions, function ($a, $b) {
			return strcmp($a['valid_from'].sprintf('%06d', $a['version']), $b['valid_from'].sprintf('%06d', $b['version']));
		});
		$starts = array_values(array_unique(array_column($versions, 'valid_from')));
		$inForce = '';
		foreach ($starts as $start) {
			if ($start <= $day) {
				$inForce = $start;
			}
		}
		$sameDay = count(array_filter($versions, function ($version) use ($inForce) {
			return $inForce !== '' && $version['valid_from'] === $inForce;
		}));
		$state = $inForce === '' ? 'none' : ($sameDay > 1 ? 'ambiguous' : 'in_force');
		$current = 0;
		foreach ($versions as $index => $version) {
			$next = '';
			foreach ($starts as $start) {
				if ($start > $version['valid_from']) {
					$next = $start;
					break;
				}
			}
			$versions[$index]['valid_to'] = $next !== '' ? date('Y-m-d', strtotime($next.' 12:00:00 -1 day')) : '';
			if ($version['valid_from'] > $day) {
				$versions[$index]['state'] = 'future';
			} elseif ($version['valid_from'] < $inForce) {
				$versions[$index]['state'] = 'repealed';
			} else {
				$versions[$index]['state'] = $state;
				$current = $state === 'in_force' ? (int) $version['id'] : 0;
			}
		}
		return array('state' => $state, 'current' => $current, 'versions' => array_reverse($versions));
	}
}
