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
 * \file    class/vereineportal.class.php
 * \ingroup vereine
 * \brief   The association in Dolibarr's web portal (#25): the same services as the API, for the member who is logged in there.
 *
 * Dolibarr 23 and later let a module add a page to its web portal (hook initController) and a menu entry
 * (hook PrintTopMenu); Dolibarr 22 has no such point without changing its core, so there the portal stays
 * as it is. The portal acts for exactly the member logged in, with the abilities the association switched
 * on for it, like a binding of an application (#153); it gets no rights of the administration and no key
 * of the API. Every page reads the member's state from the same services the API uses.
 */

/**
 * Settings of the web portal.
 */
class VereinePortal
{
	/** Which abilities the portal offers, comma separated. */
	const SETTING = 'VEREINE_PORTAL_CAPABILITIES';

	/** Abilities the portal can offer so far, in the order of its page. */
	const OFFERED = array('documents', 'meetings', 'votes', 'consents', 'profile', 'events', 'accounts');

	/** How the portal is named as the application in what it records. */
	const CLIENT = 'webportal';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * The abilities as stored: only offered ones, in their order, each once.
	 *
	 * @param mixed $stored Comma separated list or array
	 * @return string[]
	 */
	public static function parse($stored)
	{
		$wanted = is_array($stored) ? $stored : explode(',', (string) $stored);
		$wanted = array_map(function ($entry) {
			return is_scalar($entry) ? trim((string) $entry) : '';
		}, $wanted);
		return array_values(array_intersect(self::OFFERED, $wanted));
	}

	/**
	 * The abilities the association switched on for the portal.
	 *
	 * @return string[]
	 */
	public static function capabilities()
	{
		return self::parse(getDolGlobalString(self::SETTING));
	}

	/**
	 * Keep the abilities of the portal.
	 *
	 * @param string[] $entered Abilities
	 * @param User     $user    Who
	 * @return int 1 when kept, -1 on error
	 */
	public function save(array $entered, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		require_once __DIR__.'/vereinelog.class.php';

		$abilities = self::parse($entered);
		if (dolibarr_set_const($this->db, self::SETTING, implode(',', $abilities), 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::PORTAL, 0, 0, 'web portal: '.($abilities ? implode(', ', $abilities) : 'off'));
		return 1;
	}

	/**
	 * Whether Dolibarr's web portal can show the pages of the module: its hook for pages exists from Dolibarr 23 on.
	 *
	 * @param string $version Version of Dolibarr
	 * @return bool
	 */
	public static function supported($version)
	{
		return version_compare((string) $version, '23.0.0', '>=');
	}
}
