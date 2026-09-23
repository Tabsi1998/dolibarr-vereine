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
 * \file    class/vereineapplicationrules.class.php
 * \ingroup vereine
 * \brief   The way of a membership application: received, in review, accepted, rejected, withdrawn (#72).
 *
 * The association decides in Dolibarr, never the website: a client may send an application and withdraw
 * its own one, nothing else. Every step is checked against the state the application has right now, so
 * two clients cannot decide the same application twice.
 */

/**
 * Rules of a membership application.
 */
class VereineApplicationRules
{
	/** The application came in over the API or was entered in Dolibarr. */
	const RECEIVED = 'received';
	/** The board is looking at it. */
	const IN_REVIEW = 'in_review';
	/** The association took the applicant in; the member is validated. */
	const ACCEPTED = 'accepted';
	/** The association said no, with a reason for the person. */
	const REJECTED = 'rejected';
	/** The applicant took the application back. */
	const WITHDRAWN = 'withdrawn';
	/** Every state, in the order of the way. */
	const STATUSES = array('received', 'in_review', 'accepted', 'rejected', 'withdrawn');
	/** States that are settled: nothing changes them any more. */
	const SETTLED = array('accepted', 'rejected', 'withdrawn');

	/** What the association may do next, per state. */
	const NEXT = array(
		'received' => array('in_review', 'accepted', 'rejected', 'withdrawn'),
		'in_review' => array('accepted', 'rejected', 'withdrawn'),
		'accepted' => array(),
		'rejected' => array(),
		'withdrawn' => array(),
	);

	/**
	 * Whether an application in one state may go to another.
	 *
	 * @param string $from State it has now
	 * @param string $to   State it should take
	 * @return bool
	 */
	public static function allows($from, $to)
	{
		return isset(self::NEXT[$from]) && in_array($to, self::NEXT[$from], true);
	}

	/**
	 * What the person may be told when the association says no: a short text, never an internal note.
	 *
	 * @param mixed $reason Reason as entered
	 * @return string At most 500 characters, without HTML
	 */
	public static function reason($reason)
	{
		// Plain text without Dolibarr: the rules are checked by the unit tests as well.
		$text = is_scalar($reason) ? trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $reason))) : '';
		return function_exists('mb_substr') ? mb_substr($text, 0, 500, 'UTF-8') : substr($text, 0, 500);
	}

	/**
	 * A fingerprint of what a website sent, so the same external id with different content is a conflict
	 * instead of a silent change.
	 *
	 * @param array<string,mixed> $application Normalised application
	 * @return string
	 */
	public static function fingerprint(array $application)
	{
		$parts = array();
		foreach (array('firstname', 'lastname', 'email', 'birth', 'address', 'zip', 'town', 'country_code', 'type_id', 'morphy', 'company') as $field) {
			$parts[$field] = isset($application[$field]) ? (string) $application[$field] : '';
		}
		$consents = array();
		foreach (isset($application['consents']) && is_array($application['consents']) ? $application['consents'] : array() as $code => $consent) {
			$consents[(string) $code] = is_array($consent) ? (int) $consent['version'] : (int) $consent;
		}
		ksort($consents);
		$parts['consents'] = $consents;
		return hash('sha256', (string) json_encode($parts));
	}
}
