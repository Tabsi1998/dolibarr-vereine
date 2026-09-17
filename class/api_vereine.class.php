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
	 * @return array Fields module_version, api_version, country_profile, country_profile_complete, server_time
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
			// Dolibarr's clock, for changed_since of a website sync.
			'server_time' => gmdate('Y-m-d\TH:i:s\Z', dol_now()),
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
	 * Membership fees
	 *
	 * The member types in use with their fee: amount, duration, the month the fee year starts,
	 * proration when joining, admission fee and the public description - for "become a member"
	 * on a website. Needs the right to read member summaries for a website.
	 *
	 * @return array List of member types as documented in docs/API.md
	 *
	 * @url GET membershipfees
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 500 The member types could not be read
	 * @throws RestException 501 Module not enabled
	 */
	public function getMembershipfees()
	{
		global $conf;

		$this->checkAccess();
		$this->checkWebsiteRight();
		dol_include_once('/vereine/class/vereinefeemodel.class.php');
		$feeModel = new VereineFeeModel($this->db);
		$types = $feeModel->memberTypes(true);
		if ($feeModel->error !== '') {
			throw new RestException(500, 'The member types could not be read');
		}
		$result = array();
		foreach ($types as $type) {
			$model = $type['model'];
			$result[] = array(
				'id' => $type['id'],
				'label' => $type['label'],
				'description' => dol_string_nohtmltag($type['note'], 1),
				'for' => $type['morphy'] === 'phy' ? 'natural' : ($type['morphy'] === 'mor' ? 'legal' : 'both'),
				'subscription_required' => $type['subscription'],
				'amount' => $type['subscription'] ? $model['amount'] : null,
				'amount_editable' => $type['amount_editable'],
				'duration' => array('value' => $model['duration_value'], 'unit' => $model['duration_unit']),
				'year_starts_month' => $model['start_month'],
				'prorated' => $model['prorated'],
				'proration' => $model['proration'],
				'admission_fee' => $type['subscription'] ? $model['admission_fee'] : 0.0,
				'currency' => (string) $conf->currency,
			);
		}
		return $result;
	}

	/**
	 * Consent texts
	 *
	 * The texts a person can agree to now, the newest version of each purpose - to show them on
	 * a membership form and send back the version agreed to. Needs the right to read member
	 * summaries for a website or to send membership applications.
	 *
	 * @return array List of consent texts as documented in docs/API.md
	 *
	 * @url GET consents
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 501 Module not enabled
	 */
	public function getConsents()
	{
		$this->checkAccess();
		if (!DolibarrApiAccess::$user->hasRight('vereine', 'website', 'read') && !DolibarrApiAccess::$user->hasRight('vereine', 'application', 'write')) {
			throw new RestException(403, 'Not allowed: the user needs the right to read member summaries for a website or to send membership applications');
		}
		dol_include_once('/vereine/class/vereineconsents.class.php');
		$consents = new VereineConsents($this->db);
		$result = array();
		foreach ($consents->currentTexts() as $text) {
			$result[] = array('code' => $text['code'], 'label' => $text['label'], 'version' => $text['version'], 'text' => $text['text']);
		}
		return $result;
	}

	/**
	 * Membership application
	 *
	 * Creates a member in draft from an application on a website, with the consents given and
	 * the version of each text. The association checks and validates the member in Dolibarr;
	 * the website never can. Sent twice with the same external_id, the application creates one
	 * member and answers duplicate true. Needs the right to send membership applications.
	 *
	 * @param array $request_data Application as documented in docs/API.md
	 * @return array Member id, number, status and whether the application was already received
	 *
	 * @url POST applications
	 * @status 200
	 *
	 * @throws RestException 400 The application is incomplete or invalid
	 * @throws RestException 403 Not allowed
	 * @throws RestException 500 The member could not be created
	 * @throws RestException 501 Module not enabled
	 */
	public function postApplication($request_data = null)
	{
		$this->checkAccess();
		if (!DolibarrApiAccess::$user->hasRight('vereine', 'application', 'write')) {
			throw new RestException(403, 'Not allowed: the user needs the right to send membership applications');
		}
		dol_include_once('/vereine/class/vereineconsents.class.php');
		dol_include_once('/vereine/class/vereinefeemodel.class.php');
		$feeModel = new VereineFeeModel($this->db);
		$types = array();
		foreach ($feeModel->memberTypes(true) as $type) {
			$types[$type['id']] = array('morphy' => (string) $type['morphy']);
		}
		$consents = new VereineConsents($this->db);
		$texts = array();
		foreach ($consents->currentTexts() as $code => $text) {
			$texts[$code] = $text['version'];
		}
		$checked = VereineConsentRules::application($request_data, $types, $texts);
		if ($checked['errors']) {
			throw new RestException(400, implode('; ', $checked['errors']));
		}
		$result = $consents->createApplication($checked['application'], DolibarrApiAccess::$user);
		if ($result === null) {
			dol_syslog(__METHOD__.' '.$consents->error, LOG_ERR);
			throw new RestException(500, 'The member could not be created');
		}
		return $result;
	}

	/**
	 * Members for a website sync
	 *
	 * Summaries of the members, by id. With changed_since only the members whose summary
	 * changed at or after that moment: the member, its member type, subscription periods,
	 * invoices and payments, or a fee that became due or an invoice that became overdue by
	 * the date. Needs the right to read member summaries for a website.
	 *
	 * @param string $changed_since ISO 8601 moment with time zone, such as 2026-09-17T08:00:00Z
	 * @param int    $limit         Members per page, 1 to 100
	 * @param int    $page          Page, starting at 0
	 * @return array List of member summaries as documented in docs/API.md
	 *
	 * @url GET members
	 *
	 * @throws RestException 400 changed_since, limit or page invalid
	 * @throws RestException 403 Not allowed
	 * @throws RestException 501 Module not enabled
	 */
	public function getMembers($changed_since = '', $limit = 100, $page = 0)
	{
		$this->checkAccess();
		$this->checkWebsiteRight();
		dol_include_once('/vereine/class/vereinemembersummary.class.php');
		$since = null;
		if ((string) $changed_since !== '') {
			$since = VereineMemberSummary::parseMoment($changed_since);
			if ($since === null) {
				throw new RestException(400, 'changed_since must be an ISO 8601 moment with time zone, such as 2026-09-17T08:00:00Z');
			}
		}
		if ((int) $limit < 1 || (int) $limit > 100 || (int) $page < 0) {
			throw new RestException(400, 'The limit must be between 1 and 100 and the page 0 or more');
		}
		return $this->memberReport()->members($since, (int) $limit, (int) $page);
	}

	/**
	 * Summary of a member
	 *
	 * What a website shows a member about the membership: member number, name, member type,
	 * status, member since, paid until, the fee with a payment link and the open invoices of
	 * the member's third party. Needs the right to read member summaries for a website, not
	 * the right to read members or invoices. Birth date, address, phone, notes and bank data
	 * are never part of it.
	 *
	 * @param int $id Member id
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET members/{id}/summary
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 No such member
	 * @throws RestException 501 Module not enabled
	 */
	public function getMemberSummary($id)
	{
		$this->checkAccess();
		$this->checkWebsiteRight();
		$summary = $this->memberReport()->summary((int) $id);
		if ($summary === null) {
			throw new RestException(404, 'No member with this id');
		}
		return $summary;
	}

	/**
	 * Find a member
	 *
	 * The summary of the member with a member number or an e-mail address, to link a
	 * website account to Dolibarr. Give either ref or email. E-mail addresses match
	 * ignoring upper and lower case.
	 *
	 * @param string $ref   Member number
	 * @param string $email E-mail address
	 * @return array Fields as for members/{id}/summary
	 *
	 * @url GET members/lookup
	 *
	 * @throws RestException 400 Neither or both of ref and email given
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 No member matches
	 * @throws RestException 409 Several members share the e-mail address
	 * @throws RestException 501 Module not enabled
	 */
	public function getMemberLookup($ref = '', $email = '')
	{
		$this->checkAccess();
		$this->checkWebsiteRight();
		$ref = trim((string) $ref);
		$email = trim((string) $email);
		if (($ref === '') === ($email === '')) {
			throw new RestException(400, 'Give either ref or email');
		}
		$report = $this->memberReport();
		$ids = $ref !== '' ? $report->idsByRef($ref) : $report->idsByEmail($email);
		if (count($ids) > 1) {
			throw new RestException(409, 'Several members match, link the account by member number');
		}
		$summary = $ids ? $report->summary($ids[0]) : null;
		if ($summary === null) {
			throw new RestException(404, 'No member matches');
		}
		return $summary;
	}

	/**
	 * Invoices of a member
	 *
	 * The validated invoices of the member's third party, newest first: number, type, date,
	 * due date, amount, remaining amount, status and payment link. Drafts are left out. Needs
	 * the right to read member summaries for a website, not the right to read invoices.
	 *
	 * @param int $id    Member id
	 * @param int $limit Invoices per page, 1 to 100
	 * @param int $page  Page, starting at 0
	 * @return array List of invoices as documented in docs/API.md
	 *
	 * @url GET members/{id}/invoices
	 *
	 * @throws RestException 400 Limit or page out of range
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 No such member
	 * @throws RestException 501 Module not enabled
	 */
	public function getMemberInvoices($id, $limit = 100, $page = 0)
	{
		$this->checkAccess();
		$this->checkWebsiteRight();
		if ((int) $limit < 1 || (int) $limit > 100 || (int) $page < 0) {
			throw new RestException(400, 'The limit must be between 1 and 100 and the page 0 or more');
		}
		$invoices = $this->memberReport()->memberInvoices((int) $id, (int) $limit, (int) $page);
		if ($invoices === null) {
			throw new RestException(404, 'No member with this id');
		}
		return $invoices;
	}

	/**
	 * PDF of a member's invoice
	 *
	 * The PDF of a validated invoice of the member, base64 encoded. Another member's invoice
	 * or a draft answers 404, like an invoice that does not exist. A missing PDF is built with
	 * the invoice's template, as Dolibarr does on validation. Hand it only to the member it
	 * belongs to.
	 *
	 * @param int $id      Member id
	 * @param int $invoice Invoice id, as listed by members/{id}/invoices
	 * @return array Fields filename, content_type, filesize, content
	 *
	 * @url GET members/{id}/invoices/{invoice}/pdf
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 No such member or no such invoice of the member
	 * @throws RestException 500 The PDF could not be built
	 * @throws RestException 501 Module not enabled
	 */
	public function getMemberInvoicePdf($id, $invoice)
	{
		$this->checkAccess();
		$this->checkWebsiteRight();
		$pdf = $this->memberReport()->invoicePdf((int) $id, (int) $invoice);
		if ($pdf === null) {
			throw new RestException(404, 'No such invoice of this member');
		}
		if ($pdf === false) {
			throw new RestException(500, 'The PDF could not be built');
		}
		return $pdf;
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

	/**
	 * Refuse the call unless the user may read member summaries for a website.
	 *
	 * @return void
	 *
	 * @throws RestException
	 */
	private function checkWebsiteRight()
	{
		if (!DolibarrApiAccess::$user->hasRight('vereine', 'website', 'read')) {
			throw new RestException(403, 'Not allowed: the user needs the right to read member summaries for a website');
		}
	}

	/**
	 * The reader of member summaries.
	 *
	 * @return VereineMemberReport
	 */
	private function memberReport()
	{
		dol_include_once('/vereine/class/vereinememberreport.class.php');
		return new VereineMemberReport($this->db);
	}
}
