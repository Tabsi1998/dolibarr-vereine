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
 * \file    class/vereinesetupguiderules.class.php
 * \ingroup vereine
 * \brief   Rules of the setup guide (#126): which step is done, judged from the data, not from a click.
 */

/**
 * Rules of the setup guide.
 */
class VereineSetupGuideRules
{
	/** The steps, in the order a new association goes through them. New settings get their own step here. */
	const STEPS = array('association', 'modules', 'statutes', 'board', 'fees', 'consents', 'mail', 'meetings', 'website');
	/** Steps an association may leave out without anything missing. */
	const OPTIONAL = array('website');

	/** The data show the step is done. */
	const STATE_DONE = 'done';
	/** Still to do. */
	const STATE_OPEN = 'open';
	/** Left out on purpose. */
	const STATE_SKIPPED = 'skipped';
	/** Not done, and not needed. */
	const STATE_OPTIONAL = 'optional';
	/** Every state, for the page. */
	const STATES = array('done', 'open', 'skipped', 'optional');

	/**
	 * Whether the data show a step as done.
	 *
	 * @param string              $step  One of STEPS
	 * @param array<string,mixed> $facts What the installation holds, see VereineSetupGuide::facts()
	 * @return bool
	 */
	public static function done($step, array $facts)
	{
		$fact = static function ($name) use ($facts) {
			return isset($facts[$name]) ? $facts[$name] : null;
		};
		switch ($step) {
			case 'association':
				return (string) $fact('name') !== '' && (string) $fact('town') !== '' && (string) $fact('zvr') !== '' && (string) $fact('purpose') !== '';
			case 'modules':
				return is_array($fact('modules_missing')) && $fact('modules_missing') === array();
			case 'statutes':
				return (bool) $fact('statute_rules') || (int) $fact('statute_versions') > 0;
			case 'board':
				return (int) $fact('functions_missing') === 0 && (int) $fact('board_users') > 0;
			case 'fees':
				return (int) $fact('member_types') > 0;
			case 'consents':
				return (int) $fact('consents') > 0;
			case 'mail':
				return (bool) $fact('mail_tested');
			case 'meetings':
				return (bool) $fact('signature_rules') || (int) $fact('meeting_templates') > 0;
			case 'website':
				return (bool) $fact('api') && (int) $fact('api_users') > 0;
		}
		return false;
	}

	/**
	 * Where every step stands.
	 *
	 * @param array<string,mixed> $facts   What the installation holds
	 * @param string[]            $skipped Steps left out on purpose
	 * @return array<string,string> Step => one of STATES
	 */
	public static function states(array $facts, array $skipped)
	{
		$states = array();
		foreach (self::STEPS as $step) {
			if (self::done($step, $facts)) {
				$states[$step] = self::STATE_DONE;
			} elseif (in_array($step, $skipped, true)) {
				$states[$step] = self::STATE_SKIPPED;
			} else {
				$states[$step] = in_array($step, self::OPTIONAL, true) ? self::STATE_OPTIONAL : self::STATE_OPEN;
			}
		}
		return $states;
	}

	/**
	 * How far the setup is: steps done or left out, of all steps, and whether anything is still open.
	 *
	 * @param array<string,string> $states Step => state
	 * @return array{finished:int,total:int,complete:bool}
	 */
	public static function progress(array $states)
	{
		$finished = 0;
		foreach ($states as $state) {
			if ($state !== self::STATE_OPEN) {
				$finished++;
			}
		}
		return array('finished' => $finished, 'total' => count($states), 'complete' => $finished === count($states));
	}

	/**
	 * The steps left out, as stored: a comma separated list, unknown steps dropped.
	 *
	 * @param mixed $value Stored value
	 * @return string[]
	 */
	public static function skipped($value)
	{
		$list = array();
		foreach (explode(',', (string) $value) as $step) {
			$step = trim($step);
			if (in_array($step, self::STEPS, true) && !in_array($step, $list, true)) {
				$list[] = $step;
			}
		}
		return $list;
	}
}
