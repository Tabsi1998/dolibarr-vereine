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
 * \file    core/substitutions/functions_vereine.lib.php
 * \ingroup vereine
 * \brief   The module's placeholders for Dolibarr: every e-mail template and e-mail knows __VEREINE_ZVR__ and the like.
 */

/**
 * Add the placeholders of the association, and those of a member when the e-mail is about one.
 *
 * Dolibarr calls this function for every e-mail it prepares and for the list of placeholders in its
 * template editor. There the placeholders of meetings and circular resolutions are listed as well,
 * with what they mean, because only the module's own e-mails fill them.
 *
 * @param array<string,string> $substitutionarray Placeholders so far, completed here
 * @param Translate            $outputlangs       Language
 * @param object|null          $object            Object the e-mail is about
 * @param array<string,mixed>  $parameters        Parameters, mode 'formemail' when Dolibarr lists the placeholders
 * @return void
 */
function vereine_completesubstitutionarray(&$substitutionarray, $outputlangs, $object, $parameters)
{
	global $db;

	if (!isModEnabled('vereine')) {
		return;
	}
	dol_include_once('/vereine/class/vereineplaceholders.class.php');
	$placeholders = new VereinePlaceholders($db);
	foreach ($placeholders->associationValues() as $key => $value) {
		$substitutionarray[$key] = $value;
	}
	if (is_object($object) && isset($object->element) && $object->element === 'member' && !empty($object->id)) {
		foreach ($placeholders->memberValues((int) $object->id) as $key => $value) {
			$substitutionarray[$key] = $value;
		}
	}
	$listing = is_array($parameters) && isset($parameters['mode']) && in_array($parameters['mode'], array('formemail', 'formemailwithlines', 'formemailforlines'), true);
	if ($listing && !is_object($object) && is_object($outputlangs)) {
		$outputlangs->load('vereine@vereine');
		foreach (array(VereinePlaceholders::GROUP_MEMBER, VereinePlaceholders::GROUP_MEETING, VereinePlaceholders::GROUP_CIRCULAR) as $group) {
			foreach (VereinePlaceholders::KEYS[$group] as $key) {
				if (!isset($substitutionarray[$key])) {
					$substitutionarray[$key] = $outputlangs->transnoentities(VereinePlaceholders::describedBy($key));
				}
			}
		}
	}
}
