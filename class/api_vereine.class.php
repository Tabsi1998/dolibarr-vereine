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
 * \file    class/api_vereine.class.php
 * \ingroup vereine
 * \brief   REST API of the Vereine module, under /api/index.php/vereine/.
 */

use Luracast\Restler\RestException;

require_once __DIR__.'/vereineprofile.class.php';
require_once __DIR__.'/vereineorganization.class.php';

/**
 * The association's data for websites and integrations.
 *
 * The class is named Vereine, not VereineApi: Dolibarr 22 and 23 only dispatch
 * /vereine/... to a class named after the endpoint.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Vereine extends DolibarrApi
{
	/** Version of this API's answers; raised when a field changes meaning or disappears. */
	const API_VERSION = 1;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Module status
	 *
	 * Module version, API version and country profile. Useful to test a connection.
	 *
	 * @return array Fields module_version, api_version, country_profile, country_profile_complete
	 *
	 * @url GET status
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 501 Module not enabled
	 */
	public function getStatus()
	{
		$this->checkAccess();
		dol_include_once('/vereine/core/modules/modVereine.class.php');
		$module = new modVereine($this->db);
		$profile = getDolGlobalString('VEREINE_COUNTRY_PROFILE');

		return array(
			'module_version' => (string) $module->version,
			'api_version' => self::API_VERSION,
			'country_profile' => $profile,
			'country_profile_complete' => VereineProfile::isComplete($profile),
		);
	}

	/**
	 * The association
	 *
	 * Name, register number (ZVR-Zahl in Austria, VR number and court in Germany),
	 * responsible authority, address, contact, founding date, non-profit status,
	 * purpose and the month the fiscal year starts. Suitable for a website imprint.
	 *
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET organization
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 501 Module not enabled
	 */
	public function getOrganization()
	{
		// master.inc.php, which the API entry point loads, sets up $mysoc.
		global $mysoc;

		$this->checkAccess();

		return VereineOrganization::load($mysoc);
	}

	/**
	 * Tax profiles
	 *
	 * The association's tax profiles: sphere and VAT treatment with their legal basis,
	 * rate in percent, invoice note and whether the profile is active. The id is the
	 * value of the extra field vereine_taxprofile on products and invoice lines. Profiles are
	 * suggestions of the module or the association's own; the classification of an
	 * activity remains the association's decision.
	 *
	 * @return array List of profiles with the fields documented in docs/API.md
	 *
	 * @url GET taxprofiles
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 501 Module not enabled
	 */
	public function getTaxprofiles()
	{
		$this->checkAccess();
		dol_include_once('/vereine/class/vereinetaxprofiles.class.php');
		$spheres = VereineTaxRules::spheres();
		$treatments = VereineTaxRules::treatments();
		$profiles = new VereineTaxProfiles($this->db);

		$result = array();
		foreach ($profiles->fetchAll() as $profile) {
			$result[] = array(
				'id' => $profile['id'],
				'code' => $profile['code'],
				'label' => $profile['label'],
				'sphere' => $profile['sphere'],
				'sphere_basis' => isset($spheres[$profile['sphere']]) ? $spheres[$profile['sphere']]['basis'] : '',
				'treatment' => $profile['treatment'],
				'treatment_basis' => isset($treatments[$profile['treatment']]) ? $treatments[$profile['treatment']]['basis'] : '',
				'rate' => $profile['rate'],
				'note' => $profile['note'],
				'active' => $profile['active'],
				'standard' => $profile['standard'],
			);
		}
		return $result;
	}

	/**
	 * Thresholds of a year
	 *
	 * The small business limit and the limit for businesses harmful to tax privileges
	 * of a calendar year: counted income, limit, status and legal basis. Needs the right
	 * to read invoices as well. Income from invoice lines without tax profile is reported
	 * separately and not counted.
	 *
	 * @param int $year Calendar year, the current one when left out
	 * @return array Fields year, thresholds, unassigned, as documented in docs/API.md
	 *
	 * @url GET thresholds
	 *
	 * @throws RestException 400 Year out of range
	 * @throws RestException 403 Not allowed
	 * @throws RestException 501 Module not enabled
	 */
	public function getThresholds($year = 0)
	{
		$this->checkAccess();
		if (!DolibarrApiAccess::$user->hasRight('facture', 'lire')) {
			throw new RestException(403, 'Not allowed: the user needs the right to read invoices');
		}
		$year = (int) $year > 0 ? (int) $year : (int) dol_print_date(dol_now(), '%Y');
		if ($year < 2000 || $year > 2100) {
			throw new RestException(400, 'The year must be between 2000 and 2100');
		}
		dol_include_once('/vereine/class/vereinethresholdreport.class.php');
		$report = new VereineThresholdReport($this->db);
		return $report->report($year);
	}

	/**
	 * Refuse the call unless the module is on and the user may read the association.
	 *
	 * @return void
	 *
	 * @throws RestException
	 */
	private function checkAccess()
	{
		if (!isModEnabled('vereine')) {
			throw new RestException(501, 'The Vereine module is not enabled');
		}
		if (!DolibarrApiAccess::$user->hasRight('vereine', 'association', 'read')) {
			throw new RestException(403, 'Not allowed: the user needs the right to read the association');
		}
	}
}
