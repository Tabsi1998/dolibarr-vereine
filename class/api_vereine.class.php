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

require_once __DIR__.'/vereineassociationrules.class.php';
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
	const API_VERSION = 2;

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
	 * Module version and API version. Useful to test a connection.
	 *
	 * @return array Fields module_version, api_version, server_time
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

		return array(
			'module_version' => (string) $module->version,
			'api_version' => self::API_VERSION,
			// Dolibarr's clock, for changed_since of a website sync.
			'server_time' => gmdate('Y-m-d\TH:i:s\Z', dol_now()),
		);
	}

	/**
	 * The association
	 *
	 * Name, ZVR number,
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
		dol_include_once('/vereine/class/vereinestatutes.class.php');
		$statutes = new VereineStatutes($this->db);
		$statuteRules = $statutes->rules();
		$checked = VereineConsentRules::application($request_data, $types, $texts, $statuteRules['min_age'], dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'));
		// The same fields the printed form asks for: what the PDF marks as required, the web may not leave out (#216).
		dol_include_once('/vereine/class/vereinememberform.class.php');
		$specs = VereineMemberForm::memberExtraFieldSpecs($this->db);
		$form = VereineMemberForm::settings(array_keys($specs));
		$fields = VereineApplicationFormRules::checkWeb($checked['application'], $form['required'], $form['extra'],
			isset($request_data['fields']) ? $request_data['fields'] : array(), $specs);
		// Accounts at Discord, Twitch and the like the form asks for (#233).
		dol_include_once('/vereine/class/vereinesocial.class.php');
		$accounts = VereineSocialRules::fromApplication(isset($request_data['accounts']) ? $request_data['accounts'] : null, (new VereineSocial($this->db))->asked());
		$checked['errors'] = array_values(array_unique(array_merge($checked['errors'], $fields['errors'], $accounts['errors'])));
		$checked['application']['fields'] = $fields['fields'];
		$checked['application']['accounts'] = $accounts['accounts'];
		if ($checked['errors']) {
			throw new RestException(400, implode('; ', $checked['errors']));
		}
		$result = $consents->createApplication($checked['application'], DolibarrApiAccess::$user);
		if ($result === null && in_array('VereineApplicationErrorConflict', $consents->errors, true)) {
			throw new RestException(409, 'An application with this external_id exists with other content');
		}
		if ($result === null) {
			dol_syslog(__METHOD__.' '.$consents->error, LOG_ERR);
			throw new RestException(500, 'The member could not be created');
		}
		return $result;
	}

	/**
	 * State of an application
	 *
	 * Where a membership application stands: received, in review, accepted, rejected or withdrawn,
	 * with the day it was decided and, when the association said no, the reason meant for the person.
	 * Internal notes are never part of it. Needs the right to send membership applications.
	 *
	 * @param string $external_id The id the website gave the application
	 * @return array State as documented in docs/API.md
	 *
	 * @url GET applications/{external_id}
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 No such application
	 * @throws RestException 501 Module not enabled
	 */
	public function getApplication($external_id)
	{
		$this->checkAccess();
		if (!DolibarrApiAccess::$user->hasRight('vereine', 'application', 'write')) {
			throw new RestException(403, 'Not allowed: the user needs the right to send membership applications');
		}
		dol_include_once('/vereine/class/vereineapplications.class.php');
		$applications = new VereineApplications($this->db);
		$application = $applications->fetch(0, (string) $external_id);
		if ($application === null) {
			throw new RestException(404, 'No application with this external_id');
		}
		return array(
			'external_id' => $application['external_id'],
			'status' => $application['status'],
			'received_at' => dol_print_date($application['received'], 'dayhourrfc'),
			'decided_at' => $application['decided_on'] !== '' ? dol_print_date($this->db->jdate($application['decided_on']), 'dayhourrfc') : '',
			'reason' => $application['status'] === VereineApplicationRules::REJECTED ? $application['reason'] : '',
			'member_id' => $application['status'] === VereineApplicationRules::ACCEPTED ? $application['member_id'] : 0,
			'member_ref' => $application['status'] === VereineApplicationRules::ACCEPTED ? $application['ref'] : '',
		);
	}

	/**
	 * Take an application back
	 *
	 * The website takes back an application it sent, as long as the association has not decided.
	 * An application that was accepted, rejected or already withdrawn cannot be taken back; an
	 * accepted membership ends through the exit of the member instead. Taking it back twice
	 * answers the same state with changed false. Needs the right to send membership applications.
	 *
	 * @param string $external_id The id the website gave the application
	 * @return array What the application looks like now
	 *
	 * @url POST applications/{external_id}/withdraw
	 * @status 200
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 No such application
	 * @throws RestException 409 The association has already decided
	 * @throws RestException 501 Module not enabled
	 */
	public function postApplicationWithdraw($external_id)
	{
		$this->checkAccess();
		if (!DolibarrApiAccess::$user->hasRight('vereine', 'application', 'write')) {
			throw new RestException(403, 'Not allowed: the user needs the right to send membership applications');
		}
		dol_include_once('/vereine/class/vereineapplications.class.php');
		$applications = new VereineApplications($this->db);
		if ($applications->fetch(0, (string) $external_id) === null) {
			throw new RestException(404, 'No application with this external_id');
		}
		$result = $applications->withdraw((string) $external_id, DolibarrApiAccess::$user);
		if ($result === null && in_array('VereineApplicationErrorStep', $applications->errors, true)) {
			throw new RestException(409, 'The association has already decided about this application');
		}
		if ($result === null) {
			dol_syslog(__METHOD__.' '.$applications->error, LOG_ERR);
			throw new RestException(500, 'The application could not be withdrawn');
		}
		return $result;
	}

	/**
	 * Board and functions
	 *
	 * The functions of the association with their holders today, in their order - for a board
	 * page on a website. A name is given only as the association set it: with the holder's
	 * consent, or for board functions always when the website must disclose the board. Needs
	 * the right to read member summaries for a website.
	 *
	 * @return array List of functions as documented in docs/API.md
	 *
	 * @url GET board
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 501 Module not enabled
	 */
	public function getBoard()
	{
		$this->checkAccess();
		$this->checkWebsiteRight();
		dol_include_once('/vereine/class/vereinefunctions.class.php');
		$functions = new VereineFunctions($this->db);
		return $functions->board(dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'));
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
	 * Consents of a member
	 *
	 * The state per purpose for the member: what they agreed to, in which version, when, the
	 * current version of the text and whether they can give or withdraw it now. Needs the right
	 * to read member summaries for a website. Which person a website may act for is bound to the
	 * verified client of issue #153; until then the website itself is responsible for asking the
	 * right member.
	 *
	 * @param int $id Member id
	 * @return array List of purposes as documented in docs/API.md
	 *
	 * @url GET members/{id}/consents
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 No such member
	 * @throws RestException 501 Module not enabled
	 */
	public function getMemberConsents($id)
	{
		$this->checkAccess();
		$this->checkWebsiteRight();
		if ($this->memberReport()->summary((int) $id) === null) {
			throw new RestException(404, 'No member with this id');
		}
		dol_include_once('/vereine/class/vereineconsents.class.php');
		$consents = new VereineConsents($this->db);
		return $consents->stateFor((int) $id);
	}

	/**
	 * Decide about a consent
	 *
	 * Records that a member gives or withdraws a consent. Giving needs the version of the text
	 * that was shown; an older version is refused, so nobody agrees silently to a newer text.
	 * Withdrawing needs no version and works although the text has a newer version (Art. 7 (3)
	 * GDPR). The same order with the same reference is recorded once. Needs the right to send
	 * membership applications.
	 *
	 * @param int   $id           Member id
	 * @param array $request_data Decision as documented in docs/API.md
	 * @return array What was recorded
	 *
	 * @url POST members/{id}/consents
	 * @status 200
	 *
	 * @throws RestException 400 The decision is incomplete or invalid
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 No such member
	 * @throws RestException 409 A withdrawal is newer than this consent
	 * @throws RestException 500 The decision could not be recorded
	 * @throws RestException 501 Module not enabled
	 */
	public function postMemberConsent($id, $request_data = null)
	{
		$this->checkAccess();
		if (!DolibarrApiAccess::$user->hasRight('vereine', 'application', 'write')) {
			throw new RestException(403, 'Not allowed: the user needs the right to send membership applications');
		}
		if ($this->memberReport()->summary((int) $id) === null) {
			throw new RestException(404, 'No member with this id');
		}
		dol_include_once('/vereine/class/vereineconsents.class.php');
		$consents = new VereineConsents($this->db);
		$texts = array();
		foreach ($consents->currentTexts() as $code => $text) {
			$texts[$code] = (int) $text['version'];
		}
		$checked = VereineConsentRules::decision($request_data, $texts, VereineConsentRules::current($consents->history((int) $id)));
		if ($checked['errors']) {
			throw new RestException(400, implode('; ', $checked['errors']));
		}
		$result = $consents->decide((int) $id, $checked['decision'], DolibarrApiAccess::$user);
		if ($result === null && in_array('VereineConsentErrorLate', $consents->errors, true)) {
			throw new RestException(409, 'A withdrawal of this consent is newer than the moment this consent was given');
		}
		if ($result === null) {
			dol_syslog(__METHOD__.' '.$consents->error, LOG_ERR);
			throw new RestException(500, 'The decision could not be recorded');
		}
		return $result;
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
	 * Changes since a cursor
	 *
	 * What changed about the objects an external application may follow: membership, functions, fees,
	 * applications and consents. An entry says only that something changed, never what: kind of object,
	 * its id, its revision, the kind of change and when it happened. The current data is read through
	 * the ordinary endpoints, which decide for themselves what a client may see.
	 *
	 * A reader follows the feed with the opaque cursor it got last. Entries younger than a few seconds
	 * are held back, so a transaction that is still open cannot slip in behind a cursor that was already
	 * confirmed. When the cursor is older than the feed is kept, the answer says resync_required and
	 * carries no events; the reader then reconciles through changes/snapshot. Needs the right to follow
	 * the change feed, which is not the right to read member summaries.
	 *
	 * @param string $cursor Where the reader stands, empty to start at the beginning
	 * @param int    $limit  Entries per page, 1 to 500
	 * @param string $types  Kinds of object, comma separated; empty for all
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET changes
	 *
	 * @throws RestException 400 limit invalid
	 * @throws RestException 403 Not allowed
	 * @throws RestException 501 Module not enabled
	 */
	public function getChanges($cursor = '', $limit = 100, $types = '')
	{
		$this->checkAccess();
		$this->checkSyncRight();
		if ((int) $limit < 1 || (int) $limit > 500) {
			throw new RestException(400, 'The limit must be between 1 and 500');
		}
		dol_include_once('/vereine/class/vereinechanges.class.php');
		$changes = new VereineChanges($this->db);
		return $changes->feed((string) $cursor, (int) $limit, (string) $types);
	}

	/**
	 * Which objects exist right now
	 *
	 * The full reconciliation after a break that was too long. One page carries the ids of one kind of
	 * object and nothing else. The last page says complete; only then may a client act on what is
	 * missing on its side. A reconciliation that broke off is no proof that anything was deleted.
	 *
	 * The answer also carries the cursor of the moment the reconciliation started from, so the reader
	 * continues with the feed from there and loses nothing in between. Needs the right to follow the
	 * change feed.
	 *
	 * @param string $object_type Kind of object: membership, function, fee, application or consent
	 * @param int    $after       Continue after this object, 0 to start
	 * @param int    $limit       Objects per page, 1 to 500
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET changes/snapshot
	 *
	 * @throws RestException 400 object_type or limit invalid
	 * @throws RestException 403 Not allowed
	 * @throws RestException 501 Module not enabled
	 */
	public function getChangesSnapshot($object_type = 'membership', $after = 0, $limit = 100)
	{
		$this->checkAccess();
		$this->checkSyncRight();
		dol_include_once('/vereine/class/vereinechanges.class.php');
		if (!in_array((string) $object_type, VereineChangeRules::TYPES, true)) {
			throw new RestException(400, 'The object_type must be one of: '.implode(', ', VereineChangeRules::TYPES));
		}
		if ((int) $limit < 1 || (int) $limit > 500 || (int) $after < 0) {
			throw new RestException(400, 'The limit must be between 1 and 500 and after 0 or more');
		}
		$changes = new VereineChanges($this->db);
		return $changes->snapshot((string) $object_type, (int) $after, (int) $limit);
	}

	/**
	 * What an application for membership asks for
	 *
	 * The fields a website shows in its own form, so it asks for exactly what the printed form of the
	 * association asks for: which of Dolibarr's fields are required, and which own fields of the
	 * association there are, with their label and whether they are required. Own fields go into the
	 * application as fields: {code: value}.
	 *
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET applicationform
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 501 Module not enabled
	 */
	public function getApplicationForm()
	{
		global $langs;

		$this->checkAccess();
		if (!DolibarrApiAccess::$user->hasRight('vereine', 'application', 'write')) {
			throw new RestException(403, 'Not allowed: the user needs the right to send membership applications');
		}
		dol_include_once('/vereine/class/vereinememberform.class.php');
		$specs = VereineMemberForm::memberExtraFieldSpecs($this->db);
		$settings = VereineMemberForm::settings(array_keys($specs));
		$fields = array();
		// Each field with what a website needs to show it: its kind, its options, how long it may be (#226).
		foreach ($specs as $code => $spec) {
			if (!isset($settings['extra'][$code])) {
				continue;
			}
			$field = array('code' => $code, 'label' => (string) $langs->transnoentitiesnoconv($spec['label']), 'required' => $settings['extra'][$code],
				'type' => $spec['kind']);
			if ($spec['kind'] === 'text' || $spec['kind'] === 'textarea') {
				$limit = $spec['kind'] === 'text' ? VereineApplicationFormRules::EXTRA_MAX : VereineApplicationFormRules::TEXTAREA_MAX;
				$field['max_length'] = $spec['max'] > 0 && $spec['max'] < $limit ? $spec['max'] : $limit;
			}
			if ($spec['options']) {
				$field['options'] = array();
				foreach ($spec['options'] as $option => $text) {
					$field['options'][] = array('code' => (string) $option, 'label' => (string) $langs->transnoentitiesnoconv($text));
				}
			}
			$fields[] = $field;
		}
		$required = array();
		foreach ($settings['required'] as $field) {
			if (in_array($field, VereineApplicationFormRules::WEB_FIELDS, true)) {
				$required[] = $field;
			}
		}
		// The accounts the form asks for, each with its network's name (#233).
		dol_include_once('/vereine/class/vereinesocial.class.php');
		$social = new VereineSocial($this->db);
		$networks = $social->networks();
		$accounts = array();
		foreach ($social->asked() as $network => $how) {
			$accounts[] = array('network' => $network, 'label' => $networks[$network]['label'], 'required' => $how === VereineSocialRules::REQUIRED);
		}
		return array('required' => $required, 'fields' => $fields, 'accounts' => $accounts);
	}

	/**
	 * Documents published for the public
	 *
	 * Finished documents of the association's files that it published for the public, the newest
	 * revision each: minutes, resolutions, reports, as the association chose. Nothing else, never a
	 * draft, never a withdrawn one. The PDF comes from documents/{id}/pdf.
	 *
	 * @return array List as documented in docs/API.md
	 *
	 * @url GET documents
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 501 Module not enabled
	 */
	public function getDocuments()
	{
		$this->checkAccess();
		dol_include_once('/vereine/class/vereinepublications.class.php');
		return (new VereinePublications($this->db))->catalog(array('public' => true));
	}

	/**
	 * The PDF of a document published for the public
	 *
	 * The bytes of the published revision, unchanged, signatures included, checked against the checksum
	 * kept in the association's files. An unknown, unpublished or withdrawn document is not found.
	 *
	 * @param int $id       Document
	 * @param int $revision Revision, 0 for the one published now
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET documents/{id}/pdf
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 No such document
	 * @throws RestException 500 The archived file is missing or was changed
	 * @throws RestException 501 Module not enabled
	 */
	public function getDocumentPdf($id, $revision = 0)
	{
		$this->checkAccess();
		return $this->documentPdf((int) $id, (int) $revision, array('public' => true));
	}

	/**
	 * Documents for the person the caller acts for
	 *
	 * What the association published for this person: public documents, those for members while the
	 * person is an active member, those for the board while the person sits on it. Only with the
	 * ability documents for this binding.
	 *
	 * @param string $subject How the application calls the person
	 * @return array List as documented in docs/API.md
	 *
	 * @url GET me/documents
	 *
	 * @throws RestException 400 subject missing
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 501 Module not enabled
	 */
	public function getMyDocuments($subject = '')
	{
		$this->checkAccess();
		$actor = $this->documentActor((string) $subject);
		return (new VereinePublications($this->db))->catalog($actor);
	}

	/**
	 * The PDF of a document for the person the caller acts for
	 *
	 * Checked again on every call: whether the binding may, whether the person may, whether it is still
	 * published. A document the person may not see is not found, whether it exists or not.
	 *
	 * @param int    $id       Document
	 * @param string $subject  How the application calls the person
	 * @param int    $revision Revision, 0 for the one published now
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET me/documents/{id}/pdf
	 *
	 * @throws RestException 400 subject missing
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 404 No such document
	 * @throws RestException 500 The archived file is missing or was changed
	 * @throws RestException 501 Module not enabled
	 */
	public function getMyDocumentPdf($id, $subject = '', $revision = 0)
	{
		$this->checkAccess();
		return $this->documentPdf((int) $id, (int) $revision, $this->documentActor((string) $subject));
	}

	/**
	 * The statutes, when the association publishes them for the public
	 *
	 * Every stored version with its state on the day: in force, future or repealed, and which one is in
	 * force. Two versions beginning on the same day are ambiguous and none is named. The text the board is
	 * still editing is never part of it. Nothing unless the association publishes the statutes for the public.
	 *
	 * @param string $day The day, YYYY-MM-DD; empty for today
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET statutes
	 *
	 * @throws RestException 400 The day is no day
	 * @throws RestException 403 Not allowed
	 * @throws RestException 501 Module not enabled
	 */
	public function getStatutes($day = '')
	{
		$this->checkAccess();
		return $this->statutesFor(array('public' => true), (string) $day);
	}

	/**
	 * The PDF of a version of the statutes, when published for the public
	 *
	 * @param int $id Version
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET statutes/{id}/pdf
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 No such version for the caller
	 * @throws RestException 500 The file is missing or was changed
	 * @throws RestException 501 Module not enabled
	 */
	public function getStatutePdf($id)
	{
		$this->checkAccess();
		return $this->statutePdf((int) $id, array('public' => true));
	}

	/**
	 * The statutes for the person the caller acts for
	 *
	 * The same as statutes, for statutes published for members while the person is an active member.
	 * Only with the ability documents for this binding.
	 *
	 * @param string $subject How the application calls the person
	 * @param string $day     The day, YYYY-MM-DD; empty for today
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET me/statutes
	 *
	 * @throws RestException 400 subject missing or the day is no day
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 501 Module not enabled
	 */
	public function getMyStatutes($subject = '', $day = '')
	{
		$this->checkAccess();
		return $this->statutesFor($this->documentActor((string) $subject), (string) $day);
	}

	/**
	 * The PDF of a version of the statutes for the person the caller acts for
	 *
	 * @param int    $id      Version
	 * @param string $subject How the application calls the person
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET me/statutes/{id}/pdf
	 *
	 * @throws RestException 400 subject missing
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 404 No such version for the caller
	 * @throws RestException 500 The file is missing or was changed
	 * @throws RestException 501 Module not enabled
	 */
	public function getMyStatutePdf($id, $subject = '')
	{
		$this->checkAccess();
		return $this->statutePdf((int) $id, $this->documentActor((string) $subject));
	}

	/**
	 * Meetings of the person the caller acts for
	 *
	 * Every meeting the person was invited to, newest first: kind, day and time, place or access, the
	 * agenda as invited, whether the person has a vote, their own answer and motions, and until when a
	 * motion is in time. Never a meeting because of the membership alone: a board meeting stays with the
	 * board. Only with the ability meetings for this binding.
	 *
	 * @param string $subject How the application calls the person
	 * @return array List as documented in docs/API.md
	 *
	 * @url GET me/meetings
	 *
	 * @throws RestException 400 subject missing
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 501 Module not enabled
	 */
	public function getMyMeetings($subject = '')
	{
		$this->checkAccess();
		$memberId = $this->meetingMember((string) $subject);
		return (new VereineMeetingPortal($this->db))->meetingsFor($memberId);
	}

	/**
	 * Answer an invitation for the person the caller acts for
	 *
	 * Whether the person means to come: yes, no or maybe. It is no attendance and no vote; the same answer
	 * again changes nothing.
	 *
	 * @param int    $id           Meeting
	 * @param string $subject      How the application calls the person
	 * @param array  $request_data response
	 * @return array The meeting afterwards
	 *
	 * @url PUT me/meetings/{id}/response
	 *
	 * @throws RestException 400 subject missing or no such answer
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 404 Not invited to such a meeting
	 * @throws RestException 409 The meeting is over or cancelled
	 * @throws RestException 501 Module not enabled
	 */
	public function putMyMeetingResponse($id, $subject = '', $request_data = null)
	{
		$this->checkAccess();
		$memberId = $this->meetingMember((string) $subject);
		$portal = new VereineMeetingPortal($this->db);
		$response = is_array($request_data) && isset($request_data['response']) && is_scalar($request_data['response']) ? (string) $request_data['response'] : '';
		$result = $portal->respond($memberId, (int) $id, $response, (string) DolibarrApiAccess::$user->login, dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'));
		if ($result <= 0) {
			$this->portalRefused($portal, $result);
		}
		foreach ($portal->meetingsFor($memberId) as $meeting) {
			if ($meeting['id'] === (int) $id) {
				return $meeting;
			}
		}
		throw new RestException(404, 'Not found');
	}

	/**
	 * Send a motion for the agenda of a general assembly for the person the caller acts for
	 *
	 * The motion arrives once: the same external_id with the same content answers the same motion, with
	 * other content it is refused. After the days the statutes set before the assembly it is kept as late.
	 * Whether it goes on the agenda, the board decides in Dolibarr.
	 *
	 * @param int    $id           Meeting
	 * @param string $subject      How the application calls the person
	 * @param array  $request_data external_id, title, text
	 * @return array The motion
	 *
	 * @url POST me/meetings/{id}/motions
	 * @status 200
	 *
	 * @throws RestException 400 subject missing, the motion is incomplete, or the meeting is no general assembly
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 404 Not invited to such a meeting
	 * @throws RestException 409 The meeting is over or cancelled, or the external_id holds another motion
	 * @throws RestException 501 Module not enabled
	 */
	public function postMyMeetingMotion($id, $subject = '', $request_data = null)
	{
		$this->checkAccess();
		$memberId = $this->meetingMember((string) $subject);
		$portal = new VereineMeetingPortal($this->db);
		$motion = $portal->submitMotion($memberId, (int) $id, $request_data, (string) DolibarrApiAccess::$user->login, dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'));
		if ($motion === null) {
			$this->portalRefused($portal, $portal->error !== '' ? -1 : 0);
		}
		return $motion;
	}

	/**
	 * The own data of the person the caller acts for
	 *
	 * Name, contact data, member type, status and a planned or finished exit, with the version a change
	 * must name and the fields that change at once. Only with the ability profile for this binding; the
	 * summary for websites stays without address and birth.
	 *
	 * @param string $subject How the application calls the person
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET me/profile
	 *
	 * @throws RestException 400 subject missing
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 501 Module not enabled
	 */
	public function getMyProfile($subject = '')
	{
		$this->checkAccess();
		$member = $this->profileMember((string) $subject);
		return (new VereineProfiles($this->db))->profile($member);
	}

	/**
	 * Ask for a change of the own contact data
	 *
	 * Only address, zip, town, country, phones and e-mail; nothing else can be changed this way. The
	 * request names the version of GET me/profile; when the data changed since, it is a conflict. Fields
	 * the association lets change at once are applied, everything else waits for the board. The same
	 * external_id with the same content answers the same request.
	 *
	 * @param string $subject      How the application calls the person
	 * @param array  $request_data external_id, version, changes
	 * @return array The request
	 *
	 * @url POST me/profile/changes
	 * @status 200
	 *
	 * @throws RestException 400 subject missing or the request is not valid
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 409 The data changed since, or the external_id holds another request
	 * @throws RestException 501 Module not enabled
	 */
	public function postMyProfileChange($subject = '', $request_data = null)
	{
		$this->checkAccess();
		$member = $this->profileMember((string) $subject);
		$profiles = new VereineProfiles($this->db);
		$request = $profiles->submitChange($member, $request_data, (string) DolibarrApiAccess::$user->login, DolibarrApiAccess::$user);
		if ($request === null) {
			$this->profileRefused($profiles);
		}
		return $request;
	}

	/**
	 * The own requests of the person the caller acts for
	 *
	 * Changes and notices of the exit, newest first, each with its state and, when the board said no,
	 * the reason meant for the member. What the board notes for itself is never part of it.
	 *
	 * @param string $subject How the application calls the person
	 * @return array List as documented in docs/API.md
	 *
	 * @url GET me/profile/changes
	 *
	 * @throws RestException 400 subject missing
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 501 Module not enabled
	 */
	public function getMyProfileChanges($subject = '')
	{
		$this->checkAccess();
		$member = $this->profileMember((string) $subject);
		return (new VereineProfiles($this->db))->requests((int) $member->id);
	}

	/**
	 * Give notice of the exit for the person the caller acts for
	 *
	 * Kept with the day it came; the membership ends on the day the notice rule of the association says,
	 * or later when a later day is wished. The answer names that day. The same external_id again answers
	 * the same notice.
	 *
	 * @param string $subject      How the application calls the person
	 * @param array  $request_data external_id, wished_last_day
	 * @return array The notice
	 *
	 * @url POST me/exit
	 * @status 200
	 *
	 * @throws RestException 400 subject missing or the notice is not valid
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 409 An exit is already planned, the person is no active member, or the external_id holds another request
	 * @throws RestException 501 Module not enabled
	 */
	public function postMyExit($subject = '', $request_data = null)
	{
		$this->checkAccess();
		$member = $this->profileMember((string) $subject);
		$profiles = new VereineProfiles($this->db);
		$notice = $profiles->submitExit($member, $request_data, (string) DolibarrApiAccess::$user->login, dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'), DolibarrApiAccess::$user);
		if ($notice === null) {
			$this->profileRefused($profiles);
		}
		return $notice;
	}

	/**
	 * Ballots for the person the caller acts for
	 *
	 * Ballots of general assemblies the person was invited to, from their release on: question, options,
	 * status, and the voting rights the person may use: the own one, and those of members who gave the
	 * person a written proxy. A represented member sees why the own right is not there. Only with the
	 * ability votes for this binding. Opening, closing and counting happen in Dolibarr.
	 *
	 * @param string $subject How the application calls the person
	 * @return array List as documented in docs/API.md
	 *
	 * @url GET me/ballots
	 *
	 * @throws RestException 400 subject missing
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 501 Module not enabled
	 */
	public function getMyBallots($subject = '')
	{
		$this->checkAccess();
		$memberId = $this->ballotMember((string) $subject);
		return (new VereineBallots($this->db))->forMember($memberId);
	}

	/**
	 * Cast a vote for the person the caller acts for
	 *
	 * With one voting right of GET me/ballots and one option, while the ballot is open and the person is
	 * in the assembly by the attendance. A right is used once, by any application or on paper. The same
	 * external_id answers the same request; the same vote sent again without one changes nothing.
	 *
	 * @param int    $id           Ballot
	 * @param string $subject      How the application calls the person
	 * @param array  $request_data right_id, option, external_id
	 * @return array The ballot afterwards
	 *
	 * @url POST me/ballots/{id}/votes
	 * @status 200
	 *
	 * @throws RestException 400 subject, right_id or option missing, or an option the ballot does not have
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 404 No such ballot or right for the person
	 * @throws RestException 409 Not open, closed, the right was used, not in the assembly, or the external_id holds another vote
	 * @throws RestException 501 Module not enabled
	 */
	public function postMyVote($id, $subject = '', $request_data = null)
	{
		$this->checkAccess();
		$memberId = $this->ballotMember((string) $subject);
		$data = is_array($request_data) ? $request_data : array();
		$right = isset($data['right_id']) && is_numeric($data['right_id']) ? (int) $data['right_id'] : 0;
		$option = isset($data['option']) && is_string($data['option']) ? trim($data['option']) : '';
		$external = isset($data['external_id']) && is_scalar($data['external_id']) ? (string) $data['external_id'] : '';
		if ($right < 1 || $option === '') {
			throw new RestException(400, 'right_id and option are needed');
		}
		$ballots = new VereineBallots($this->db);
		$result = $ballots->cast((int) $id, $right, $option, $memberId, VereineBallotRules::CHANNEL_APP, (string) DolibarrApiAccess::$user->login, $external, DolibarrApiAccess::$user);
		if ($result < 0) {
			dol_syslog(__METHOD__.' '.$ballots->error, LOG_ERR);
			throw new RestException(500, 'The vote could not be kept');
		}
		if ($result === 0) {
			if ($ballots->reason === 'not_found') {
				throw new RestException(404, 'Not found');
			}
			throw new RestException($ballots->reason === 'option' ? 400 : 409, $ballots->reason);
		}
		foreach ($ballots->forMember($memberId) as $ballot) {
			if ($ballot['id'] === (int) $id) {
				return $ballot;
			}
		}
		throw new RestException(404, 'Not found');
	}

	/**
	 * Public events
	 *
	 * Events from today on that the association marked as public: name, days, place, state and where
	 * people register (nowhere, in Dolibarr, or at one named external application). Never a list of
	 * participants, internal tasks or money.
	 *
	 * @return array List as documented in docs/API.md
	 *
	 * @url GET events
	 *
	 * @throws RestException 403 Not allowed
	 * @throws RestException 501 Module not enabled
	 */
	public function getEvents()
	{
		$this->checkAccess();
		dol_include_once('/vereine/class/vereineeventportal.class.php');
		return (new VereineEventPortal($this->db))->events(dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'));
	}

	/**
	 * Events and helper shifts for the person the caller acts for
	 *
	 * The public events and those for members, from today on, each with its helper shifts: how many
	 * places are taken and the person's own state. Only with the ability events for this binding.
	 *
	 * @param string $subject How the application calls the person
	 * @return array List as documented in docs/API.md
	 *
	 * @url GET me/events
	 *
	 * @throws RestException 400 subject missing
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 501 Module not enabled
	 */
	public function getMyEvents($subject = '')
	{
		$this->checkAccess();
		$memberId = $this->eventMember((string) $subject);
		return (new VereineEventPortal($this->db))->events(dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'), $memberId);
	}

	/**
	 * Ask for a helper shift for the person the caller acts for
	 *
	 * The request waits for the board, which confirms it in Dolibarr. Asking again changes nothing; a
	 * shift overlapping another of the person is refused.
	 *
	 * @param int    $id      Event
	 * @param int    $shift   Shift
	 * @param string $subject How the application calls the person
	 * @return array The event afterwards
	 *
	 * @url PUT me/events/{id}/shifts/{shift}
	 *
	 * @throws RestException 400 subject missing
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 404 No such event or shift for the person
	 * @throws RestException 409 The event is cancelled, the shift is over or full, or overlaps another
	 * @throws RestException 501 Module not enabled
	 */
	public function putMyShift($id, $shift, $subject = '')
	{
		$this->checkAccess();
		$memberId = $this->eventMember((string) $subject);
		$portal = new VereineEventPortal($this->db);
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$result = $portal->ask($memberId, (int) $id, (int) $shift, (string) DolibarrApiAccess::$user->login, $today, DolibarrApiAccess::$user);
		if ($result <= 0) {
			$this->eventRefused($portal, $result);
		}
		return $this->eventFor($portal, $memberId, (int) $id, $today);
	}

	/**
	 * Take back a helper shift the board has not confirmed yet
	 *
	 * @param int    $id      Event
	 * @param int    $shift   Shift
	 * @param string $subject How the application calls the person
	 * @return array The event afterwards
	 *
	 * @url DELETE me/events/{id}/shifts/{shift}
	 *
	 * @throws RestException 400 subject missing
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 404 No such event or shift for the person
	 * @throws RestException 409 The shift is confirmed, over or the event cancelled
	 * @throws RestException 501 Module not enabled
	 */
	public function deleteMyShift($id, $shift, $subject = '')
	{
		$this->checkAccess();
		$memberId = $this->eventMember((string) $subject);
		$portal = new VereineEventPortal($this->db);
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$result = $portal->withdraw($memberId, (int) $id, (int) $shift, $today, DolibarrApiAccess::$user);
		if ($result <= 0) {
			$this->eventRefused($portal, $result);
		}
		return $this->eventFor($portal, $memberId, (int) $id, $today);
	}

	/**
	 * The accounts of the person the caller acts for
	 *
	 * The networks the association asks for and every other the member has, each with the name, where it
	 * leads and whether an application confirmed it. Only with the ability accounts for this binding.
	 *
	 * @param string $subject How the application calls the person
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET me/accounts
	 *
	 * @throws RestException 400 subject missing
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 501 Module not enabled
	 */
	public function getMyAccounts($subject = '')
	{
		$this->checkAccess();
		$member = $this->boundMember((string) $subject);
		return (new VereineSocial($this->db))->accounts($member);
	}

	/**
	 * Link an account of the person the caller acts for
	 *
	 * Sets the person's name at one network. With confirmed true the application says it checked the
	 * account at the network, for example after the person signed in with Discord or Twitch; the
	 * association sees it as confirmed until the name changes. Without, an older confirmation is gone.
	 *
	 * @param string $network      Network, as GET me/accounts names it
	 * @param string $subject      How the application calls the person
	 * @param array  $request_data handle, confirmed and external_id as documented in docs/API.md
	 * @return array The person's accounts afterwards
	 *
	 * @url PUT me/accounts/{network}
	 *
	 * @throws RestException 400 subject missing, unknown network or no name of an account
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 500 The account could not be saved
	 * @throws RestException 501 Module not enabled
	 */
	public function putMyAccount($network, $subject = '', $request_data = null)
	{
		$this->checkAccess();
		$member = $this->boundMember((string) $subject);
		$data = is_array($request_data) ? $request_data : array();
		if (!isset($data['handle']) || (string) $data['handle'] === '') {
			throw new RestException(400, 'handle is needed; to take an account away, use DELETE');
		}
		return $this->setAccount($member, (string) $network, $data['handle'], !empty($data['confirmed']), isset($data['external_id']) ? (string) $data['external_id'] : '');
	}

	/**
	 * Take an account of the person the caller acts for away
	 *
	 * Removes the name and its confirmation, for example when the person unlinked it in the application.
	 *
	 * @param string $network Network, as GET me/accounts names it
	 * @param string $subject How the application calls the person
	 * @return array The person's accounts afterwards
	 *
	 * @url DELETE me/accounts/{network}
	 *
	 * @throws RestException 400 subject missing or unknown network
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 500 The account could not be removed
	 * @throws RestException 501 Module not enabled
	 */
	public function deleteMyAccount($network, $subject = '')
	{
		$this->checkAccess();
		$member = $this->boundMember((string) $subject);
		return $this->setAccount($member, (string) $network, '', false, '');
	}

	/**
	 * Bind a person of a client to a member or an application
	 *
	 * The application sends the name it knows the person by and the one-time code the association gave
	 * that person. The code is short lived, dies on first use and is worth nothing at another client.
	 * An e-mail address or a member number is never enough: they find candidates, they prove nothing.
	 *
	 * Needs the right to act for verified people. The binding that comes out belongs to this client and
	 * this entity and carries only the abilities the association switched on for it.
	 *
	 * @param string $subject How the application calls the person; stable, never an address alone
	 * @param string $code    The one-time code of the invitation
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url POST identities/claim
	 *
	 * @throws RestException 400 subject or code missing
	 * @throws RestException 403 Not allowed, code used, expired, or the name is taken
	 * @throws RestException 501 Module not enabled
	 */
	public function postIdentityClaim($subject = '', $code = '')
	{
		$this->checkAccess();
		$this->checkIdentityRight();
		dol_include_once('/vereine/class/vereineidentityrules.class.php');
		if ((string) $subject === '' || (string) $code === '') {
			throw new RestException(400, 'subject and code are both needed');
		}
		$access = $this->access();
		$result = $access->claim((string) DolibarrApiAccess::$user->login, (string) $subject, (string) $code, DolibarrApiAccess::$user);
		if (!$result['ok']) {
			throw new RestException(403, 'Not allowed: '.$result['reason']);
		}
		return VereineIdentityRules::describe($result['identity']);
	}

	/**
	 * Who the caller is acting for
	 *
	 * What the association knows about this person at this client: the member or the application they
	 * are bound to, what they may do, and how the binding came about. Nothing else, and nothing about
	 * anybody else.
	 *
	 * @param string $subject How the application calls the person
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET identities/me
	 *
	 * @throws RestException 400 subject missing
	 * @throws RestException 403 Not allowed or no binding
	 * @throws RestException 501 Module not enabled
	 */
	public function getIdentityMe($subject = '')
	{
		$this->checkAccess();
		$this->checkIdentityRight();
		dol_include_once('/vereine/class/vereineidentityrules.class.php');
		$identity = $this->boundIdentity((string) $subject);
		return VereineIdentityRules::describe($identity);
	}

	/**
	 * The consents of the person the caller acts for
	 *
	 * Only the person's own consents, and only when the association switched the ability consents on for
	 * this binding. A binding that carries only an application has no member and gets nothing here.
	 *
	 * @param string $subject How the application calls the person
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET me/consents
	 *
	 * @throws RestException 400 subject missing
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 501 Module not enabled
	 */
	public function getMyConsents($subject = '')
	{
		$this->checkAccess();
		$this->checkIdentityRight();
		dol_include_once('/vereine/class/vereineidentityrules.class.php');
		$identity = $this->allowed((string) $subject, VereineIdentityRules::CAPABILITY_CONSENTS, 'member');
		dol_include_once('/vereine/class/vereineconsents.class.php');
		$consents = new VereineConsents($this->db);
		return $consents->stateFor((int) $identity['member_id']);
	}

	/**
	 * The application for membership of the person the caller acts for
	 *
	 * An applicant is nobody's member yet. This is the one thing such a binding opens: the state of
	 * their own application, never a member's data.
	 *
	 * @param string $subject How the application calls the person
	 * @return array Fields as documented in docs/API.md
	 *
	 * @url GET me/application
	 *
	 * @throws RestException 400 subject missing
	 * @throws RestException 403 Not allowed, no binding or the ability is off
	 * @throws RestException 404 No such application
	 * @throws RestException 501 Module not enabled
	 */
	public function getMyApplication($subject = '')
	{
		$this->checkAccess();
		$this->checkIdentityRight();
		dol_include_once('/vereine/class/vereineidentityrules.class.php');
		$identity = $this->allowed((string) $subject, VereineIdentityRules::CAPABILITY_APPLICATIONS, 'application');
		dol_include_once('/vereine/class/vereineapplications.class.php');
		$applications = new VereineApplications($this->db);
		$row = $applications->fetch((int) $identity['application_id']);
		if ($row === null) {
			throw new RestException(404, 'No such application');
		}
		// The same form as GET applications/{external_id} gives: a moment, or empty while nobody decided.
		return array('application_id' => (int) $row['id'], 'status' => (string) $row['status'],
			'decided_at' => (string) $row['decided_on'] !== '' ? dol_print_date($this->db->jdate($row['decided_on']), 'dayhourrfc') : '',
			'reason' => (string) $row['reason']);
	}

	/**
	 * Refuse the call unless this client may act for verified people at all.
	 *
	 * @return void
	 *
	 * @throws RestException
	 */
	private function checkIdentityRight()
	{
		if (!DolibarrApiAccess::$user->hasRight('vereine', 'identity', 'use')) {
			throw new RestException(403, 'Not allowed: the user needs the right to act for verified people');
		}
	}

	/**
	 * The access service.
	 *
	 * @return VereineAccess
	 */
	private function access()
	{
		dol_include_once('/vereine/class/vereineaccess.class.php');
		return new VereineAccess($this->db);
	}

	/**
	 * The binding of a person at this client, or a refusal.
	 *
	 * @param string $subject How the application calls the person
	 * @return array<string,mixed>
	 *
	 * @throws RestException
	 */
	private function boundIdentity($subject)
	{
		global $conf;

		dol_include_once('/vereine/class/vereineidentityrules.class.php');
		if ((string) $subject === '') {
			throw new RestException(400, 'subject is needed');
		}
		$access = $this->access();
		$client = (string) DolibarrApiAccess::$user->login;
		$identity = $access->identity($client, (string) $subject);
		$wrong = VereineIdentityRules::alive($identity, $client, (int) $conf->entity);
		if ($wrong !== '') {
			throw new RestException(403, 'Not allowed: '.$wrong);
		}
		return $identity;
	}

	/**
	 * The binding of a person, refused unless it may do this to that kind of object.
	 *
	 * @param string $subject    How the application calls the person
	 * @param string $capability What is to be done
	 * @param string $objectType member or application
	 * @return array<string,mixed>
	 *
	 * @throws RestException
	 */
	private function allowed($subject, $capability, $objectType)
	{
		dol_include_once('/vereine/class/vereineidentityrules.class.php');
		if ((string) $subject === '') {
			throw new RestException(400, 'subject is needed');
		}
		$result = $this->access()->check(DolibarrApiAccess::$user, (string) $subject, (string) $capability, (string) $objectType);
		if (!$result['ok']) {
			throw new RestException(403, 'Not allowed: '.$result['reason']);
		}
		return $result['identity'];
	}

	/**
	 * Who the caller acts for, for the publications: a member binding with the ability documents.
	 *
	 * @param string $subject How the application calls the person
	 * @return array{public:bool,member:bool,board:bool}
	 *
	 * @throws RestException
	 */
	private function documentActor($subject)
	{
		$this->checkIdentityRight();
		dol_include_once('/vereine/class/vereineidentityrules.class.php');
		dol_include_once('/vereine/class/vereinepublications.class.php');
		$identity = $this->allowed($subject, VereineIdentityRules::CAPABILITY_DOCUMENTS, 'member');
		return (new VereinePublications($this->db))->actorFor((int) $identity['member_id']);
	}

	/**
	 * The statutes as somebody may read them on a day.
	 *
	 * @param array<string,bool> $actor public, member, board
	 * @param string             $day   The day, empty for today
	 * @return array
	 *
	 * @throws RestException
	 */
	private function statutesFor(array $actor, $day)
	{
		if ($day === '') {
			$day = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		}
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
			throw new RestException(400, 'day must be YYYY-MM-DD');
		}
		dol_include_once('/vereine/class/vereinestatutes.class.php');
		return (new VereineStatutes($this->db))->published($actor, $day);
	}

	/**
	 * The PDF of a version of the statutes for somebody.
	 *
	 * @param int                $id    Version
	 * @param array<string,bool> $actor public, member, board
	 * @return array
	 *
	 * @throws RestException
	 */
	private function statutePdf($id, array $actor)
	{
		dol_include_once('/vereine/class/vereinestatutes.class.php');
		$statutes = new VereineStatutes($this->db);
		$pdf = $statutes->publishedPdf($id, $actor);
		if ($pdf === null) {
			throw new RestException(404, 'No such version');
		}
		if ($pdf === false) {
			dol_syslog(__METHOD__.' '.$statutes->error, LOG_ERR);
			throw new RestException(500, 'The file is missing or was changed');
		}
		return $pdf;
	}

	/**
	 * The PDF of a published document for somebody.
	 *
	 * @param int                $id       Document
	 * @param int                $revision Revision, 0 for the one published now
	 * @param array<string,bool> $actor    public, member, board
	 * @return array
	 *
	 * @throws RestException
	 */
	private function documentPdf($id, $revision, array $actor)
	{
		dol_include_once('/vereine/class/vereinepublications.class.php');
		$publications = new VereinePublications($this->db);
		$pdf = $publications->pdf($id, $revision, $actor);
		if ($pdf === null) {
			throw new RestException(404, 'No such document');
		}
		if ($pdf === false) {
			dol_syslog(__METHOD__.' '.$publications->error, LOG_ERR);
			throw new RestException(500, 'The archived file is missing or was changed');
		}
		return $pdf;
	}

	/**
	 * The member the caller acts for, when the binding may vote for the person.
	 *
	 * @param string $subject How the application calls the person
	 * @return int Member
	 *
	 * @throws RestException
	 */
	private function ballotMember($subject)
	{
		global $langs;

		$this->checkIdentityRight();
		dol_include_once('/vereine/class/vereineidentityrules.class.php');
		dol_include_once('/vereine/class/vereineballots.class.php');
		$langs->load('vereine@vereine');
		$identity = $this->allowed($subject, VereineIdentityRules::CAPABILITY_VOTES, 'member');
		return (int) $identity['member_id'];
	}

	/**
	 * The member the caller acts for, when the binding may handle the person's events.
	 *
	 * @param string $subject How the application calls the person
	 * @return int Member
	 *
	 * @throws RestException
	 */
	private function eventMember($subject)
	{
		$this->checkIdentityRight();
		dol_include_once('/vereine/class/vereineidentityrules.class.php');
		dol_include_once('/vereine/class/vereineeventportal.class.php');
		$identity = $this->allowed($subject, VereineIdentityRules::CAPABILITY_EVENTS, 'member');
		return (int) $identity['member_id'];
	}

	/**
	 * One event as the member sees it.
	 *
	 * @param VereineEventPortal $portal   The service
	 * @param int                $memberId Member
	 * @param int                $eventId  Event
	 * @param string             $today    Today
	 * @return array
	 *
	 * @throws RestException
	 */
	private function eventFor($portal, $memberId, $eventId, $today)
	{
		foreach ($portal->events($today, $memberId) as $event) {
			if ($event['id'] === $eventId) {
				return $event;
			}
		}
		throw new RestException(404, 'Not found');
	}

	/**
	 * Turn what the event service refused into the answer of the API.
	 *
	 * @param VereineEventPortal $portal The service
	 * @param int                $result What it returned
	 * @return void
	 *
	 * @throws RestException
	 */
	private function eventRefused($portal, $result)
	{
		if ($result < 0) {
			dol_syslog(__METHOD__.' '.$portal->error, LOG_ERR);
			throw new RestException(500, 'The shift could not be kept');
		}
		if (in_array('not found', $portal->errors, true)) {
			throw new RestException(404, 'Not found');
		}
		throw new RestException(409, implode('; ', $portal->errors));
	}

	/**
	 * The member the caller acts for, when the binding may handle the person's own data.
	 *
	 * @param string $subject How the application calls the person
	 * @return Adherent
	 *
	 * @throws RestException
	 */
	private function profileMember($subject)
	{
		$this->checkIdentityRight();
		dol_include_once('/vereine/class/vereineidentityrules.class.php');
		dol_include_once('/vereine/class/vereineprofiles.class.php');
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
		$identity = $this->allowed($subject, VereineIdentityRules::CAPABILITY_PROFILE, 'member');
		$member = new Adherent($this->db);
		if ($member->fetch((int) $identity['member_id']) <= 0) {
			throw new RestException(403, 'Not allowed: the binding has no member');
		}
		return $member;
	}

	/**
	 * Turn what the service for own data refused into the answer of the API.
	 *
	 * @param VereineProfiles $profiles The service
	 * @return void
	 *
	 * @throws RestException
	 */
	private function profileRefused($profiles)
	{
		if (!$profiles->errors) {
			dol_syslog(__METHOD__.' '.$profiles->error, LOG_ERR);
			throw new RestException(500, 'The request could not be kept');
		}
		if (in_array('conflict', $profiles->errors, true)) {
			throw new RestException(409, 'The data changed since, or the external_id holds another request');
		}
		if (array_intersect(array('an exit is already planned', 'only an active member can give notice'), $profiles->errors)) {
			throw new RestException(409, implode('; ', $profiles->errors));
		}
		throw new RestException(400, implode('; ', $profiles->errors));
	}

	/**
	 * The member the caller acts for, when the binding may handle the person's meetings.
	 *
	 * @param string $subject How the application calls the person
	 * @return int Member
	 *
	 * @throws RestException
	 */
	private function meetingMember($subject)
	{
		$this->checkIdentityRight();
		dol_include_once('/vereine/class/vereineidentityrules.class.php');
		dol_include_once('/vereine/class/vereinemeetingportal.class.php');
		$identity = $this->allowed($subject, VereineIdentityRules::CAPABILITY_MEETINGS, 'member');
		return (int) $identity['member_id'];
	}

	/**
	 * Turn what the meeting service refused into the answer of the API.
	 *
	 * @param VereineMeetingPortal $portal The service
	 * @param int                  $result What it returned
	 * @return void
	 *
	 * @throws RestException
	 */
	private function portalRefused($portal, $result)
	{
		if ($result < 0) {
			dol_syslog(__METHOD__.' '.$portal->error, LOG_ERR);
			throw new RestException(500, 'The answer could not be kept');
		}
		if (in_array('not found', $portal->errors, true)) {
			throw new RestException(404, 'Not found');
		}
		if (in_array('the meeting is over or cancelled', $portal->errors, true)) {
			throw new RestException(409, 'The meeting is over or cancelled');
		}
		if (in_array('conflict', $portal->errors, true)) {
			throw new RestException(409, 'A motion with this external_id exists with other content');
		}
		throw new RestException(400, implode('; ', $portal->errors));
	}

	/**
	 * The member the caller acts for, when the binding may handle the person's accounts.
	 *
	 * @param string $subject How the application calls the person
	 * @return Adherent
	 *
	 * @throws RestException
	 */
	private function boundMember($subject)
	{
		$this->checkIdentityRight();
		dol_include_once('/vereine/class/vereineidentityrules.class.php');
		dol_include_once('/vereine/class/vereinesocial.class.php');
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
		$identity = $this->allowed($subject, VereineIdentityRules::CAPABILITY_ACCOUNTS, 'member');
		$member = new Adherent($this->db);
		if ($member->fetch((int) $identity['member_id']) <= 0) {
			throw new RestException(403, 'Not allowed: the binding has no member');
		}
		return $member;
	}

	/**
	 * Set or remove an account of a bound member and answer with the accounts afterwards.
	 *
	 * @param Adherent $member     Member
	 * @param string   $network    Network
	 * @param mixed    $handle     Name, empty to remove
	 * @param bool     $confirmed  Whether the application checked it at the network
	 * @param string   $externalId The network's own id of the account
	 * @return array
	 *
	 * @throws RestException
	 */
	private function setAccount($member, $network, $handle, $confirmed, $externalId)
	{
		$social = new VereineSocial($this->db);
		$result = $social->setAccount($member, $network, is_scalar($handle) ? (string) $handle : "\x00", $confirmed, $externalId,
			(string) DolibarrApiAccess::$user->login, DolibarrApiAccess::$user);
		if ($result === 0) {
			throw new RestException(400, implode('; ', $social->errors));
		}
		if ($result < 0) {
			dol_syslog(__METHOD__.' '.$social->error, LOG_ERR);
			throw new RestException(500, 'The account could not be saved');
		}
		return $social->accounts($member);
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
	 * Refuse the call unless the user may follow the change feed.
	 *
	 * @return void
	 *
	 * @throws RestException
	 */
	private function checkSyncRight()
	{
		if (!DolibarrApiAccess::$user->hasRight('vereine', 'sync', 'read')) {
			throw new RestException(403, 'Not allowed: the user needs the right to follow the change feed');
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
