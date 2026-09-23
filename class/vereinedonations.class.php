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
 * \file    class/vereinedonations.class.php
 * \ingroup vereine
 * \brief   The donation report to the tax office (#6): donors, their identifiers, the XML and its protocol.
 *
 * The donations themselves stay in Dolibarr's donation module; a paid donation counts in the year of its
 * date. The module adds what the report needs and Dolibarr does not keep: the donor as a person with the
 * date of birth (encrypted with Dolibarr's own key), a reference number, and the encrypted identifier the
 * register of source numbers gives for the tax office. It writes the file for FinanzOnline, checked against
 * the schema of the Ministry of Finance, and reads the protocol back from the DataBox. Uploading stays a
 * click in FinanzOnline.
 */

require_once __DIR__.'/vereinedonationrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Donors and the donation report.
 */
class VereineDonations
{
	/** A line on its way: written, not yet confirmed by a protocol. */
	const LINE_SENT = 'sent';
	/** The tax office took the line. */
	const LINE_OK = 'ok';
	/** The tax office refused the line. */
	const LINE_FAILED = 'failed';

	/** A report written, no protocol of the real transmission yet. */
	const REPORT_CREATED = 'created';
	/** The protocol of the real transmission is read. */
	const REPORT_DONE = 'done';

	/** Largest file read back, in bytes. */
	const UPLOAD_MAX = 5242880;

	/** Settings of the report, as Dolibarr constants. */
	const KIND = 'VEREINE_DONATION_KIND';
	const FASTNR_ORG = 'VEREINE_DONATION_FASTNR_ORG';
	const FASTNR_TN = 'VEREINE_DONATION_FASTNR_TN';
	const SZR_CONTACT = 'VEREINE_DONATION_SZR_CONTACT';
	const SZR_EMAIL = 'VEREINE_DONATION_SZR_EMAIL';
	const SZR_VKZ = 'VEREINE_DONATION_SZR_VKZ';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last database error
	 */
	public $error = '';

	/**
	 * @var string[] Language keys of what was refused
	 */
	public $errors = array();

	/**
	 * @var string[] What the schema found in a report that was refused
	 */
	public $problems = array();

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
	 * Where reports and confirmations are kept.
	 *
	 * @return string
	 */
	public static function directory()
	{
		global $conf;

		return $conf->vereine->dir_output.'/spendenmeldung';
	}

	/**
	 * The schema of the Ministry of Finance the reports are checked against.
	 *
	 * @return string
	 */
	public static function schema()
	{
		return dirname(__DIR__).'/xsd/UebermittlungSonderausgaben_2.xsd';
	}

	/**
	 * The file of a report.
	 *
	 * @param array<string,mixed> $report Report
	 * @return string
	 */
	public static function reportPath(array $report)
	{
		return self::directory().'/spendenmeldung-'.((int) $report['year']).'-'.((int) $report['id']).'.xml';
	}

	/**
	 * The settings of the report, with the defaults the association data give.
	 *
	 * @return array{kind:string,fastnr_org:string,fastnr_tn:string,contact:string,email:string,vkz:string}
	 */
	public static function settings()
	{
		$zvr = preg_replace('/[^0-9]/', '', getDolGlobalString('VEREINE_REGISTER_NUMBER'));
		return array('kind' => getDolGlobalString(self::KIND), 'fastnr_org' => getDolGlobalString(self::FASTNR_ORG),
			'fastnr_tn' => getDolGlobalString(self::FASTNR_TN), 'contact' => getDolGlobalString(self::SZR_CONTACT),
			'email' => getDolGlobalString(self::SZR_EMAIL),
			// In the portal network an association is known by its register number.
			'vkz' => getDolGlobalString(self::SZR_VKZ, $zvr !== '' ? 'XZVR-'.$zvr : ''));
	}

	/**
	 * Store the settings of the report.
	 *
	 * @param array<string,mixed> $entered kind, fastnr_org, fastnr_tn, contact, email, vkz
	 * @param User                $user    Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function saveSettings(array $entered, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$this->errors = array();
		$text = static function ($key) use ($entered) {
			return isset($entered[$key]) ? trim((string) $entered[$key]) : '';
		};
		$kind = $text('kind');
		if ($kind !== '' && !in_array($kind, VereineDonationRules::KINDS, true)) {
			$this->errors[] = 'VereineDonationErrorKind';
		}
		$org = VereineDonationRules::fastnr($text('fastnr_org'));
		$participant = VereineDonationRules::fastnr($text('fastnr_tn'));
		if ($org === null || $participant === null) {
			$this->errors[] = 'VereineDonationErrorFastnr';
		}
		$email = $text('email');
		if ($email !== '' && !isValidEmail($email)) {
			$this->errors[] = 'VereineDonationErrorEmail';
		}
		if ($this->errors) {
			return 0;
		}
		$values = array(self::KIND => $kind, self::FASTNR_ORG => (string) $org, self::FASTNR_TN => (string) $participant,
			self::SZR_CONTACT => dol_trunc($text('contact'), 128, 'right', 'UTF-8', 1), self::SZR_EMAIL => $email,
			self::SZR_VKZ => dol_trunc(preg_replace('/[^0-9A-Za-z\-+]/', '', $text('vkz')), 32, 'right', 'UTF-8', 1));
		foreach ($values as $name => $value) {
			$result = $value !== '' ? dolibarr_set_const($this->db, $name, $value, 'chaine', 0, '', $conf->entity) : dolibarr_del_const($this->db, $name, $conf->entity);
			if ($result < 0) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		VereineLog::add($this->db, $user, VereineLog::DONATION_SETUP, 0, 0, 'kind '.($kind !== '' ? $kind : '-'));
		return 1;
	}

	/**
	 * Link the paid donations of a year that belong to no donor yet: by third party, else by name, else a new donor.
	 *
	 * @param int  $year Year
	 * @param User $user Who looks
	 * @return int Donations linked, -1 on error
	 */
	public function link($year, $user)
	{
		global $conf;

		$entity = (int) $conf->entity;
		$sql = "SELECT d.rowid, d.fk_soc, d.firstname, d.lastname, d.societe FROM ".MAIN_DB_PREFIX."don as d";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."vereine_donation_gift as g ON g.fk_don = d.rowid AND g.entity = ".$entity;
		$sql .= " WHERE d.entity = ".$entity." AND d.fk_statut = 2 AND g.rowid IS NULL";
		$sql .= " AND d.datedon BETWEEN '".((int) $year)."-01-01 00:00:00' AND '".((int) $year)."-12-31 23:59:59' ORDER BY d.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$open = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$open[] = $obj;
		}
		$linked = 0;
		foreach ($open as $gift) {
			$donor = $this->findDonor((int) $gift->fk_soc, (string) $gift->firstname, (string) $gift->lastname);
			if ($donor === 0) {
				$donor = $this->createDonor((int) $gift->fk_soc, (string) $gift->firstname, (string) $gift->lastname !== '' ? (string) $gift->lastname
					: (string) $gift->societe, $user);
			}
			if ($donor < 0) {
				return -1;
			}
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_donation_gift (entity, fk_don, fk_donor) VALUES (".$entity.", ".((int) $gift->rowid).", ".$donor.")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$linked++;
		}
		return $linked;
	}

	/**
	 * The donor a donation belongs to: the same third party, else the same first and last name.
	 *
	 * @param int    $socid     Third party of the donation
	 * @param string $firstname First name
	 * @param string $lastname  Last name
	 * @return int Donor, 0 when none
	 */
	private function findDonor($socid, $firstname, $lastname)
	{
		global $conf;

		$where = "entity = ".((int) $conf->entity);
		if ($socid > 0) {
			$where .= " AND fk_soc = ".((int) $socid);
		} elseif (trim($lastname) !== '') {
			$where .= " AND fk_soc IS NULL AND LOWER(firstname) = '".$this->db->escape(mb_strtolower(trim($firstname), 'UTF-8'))."'";
			$where .= " AND LOWER(lastname) = '".$this->db->escape(mb_strtolower(trim($lastname), 'UTF-8'))."'";
		} else {
			return 0;
		}
		$resql = $this->db->query("SELECT MIN(rowid) as id FROM ".MAIN_DB_PREFIX."vereine_donor WHERE ".$where);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? (int) $obj->id : 0;
	}

	/**
	 * A new donor from a donation; a member of the third party gives the names.
	 *
	 * @param int    $socid     Third party
	 * @param string $firstname First name
	 * @param string $lastname  Last name or company
	 * @param User   $user      Who looks
	 * @return int Donor, -1 on error
	 */
	private function createDonor($socid, $firstname, $lastname, $user)
	{
		global $conf;

		$member = 0;
		if ($socid > 0) {
			$resql = $this->db->query("SELECT MIN(rowid) as id FROM ".MAIN_DB_PREFIX."adherent WHERE fk_soc = ".((int) $socid)." AND entity IN (".getEntity('adherent').")");
			$obj = $resql ? $this->db->fetch_object($resql) : null;
			$member = $obj ? (int) $obj->id : 0;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_donor (entity, fk_soc, fk_adherent, firstname, lastname, refnr, datec, fk_user_modif) VALUES (";
		$sql .= ((int) $conf->entity).", ".($socid > 0 ? (int) $socid : "NULL").", ".($member > 0 ? $member : "NULL").",";
		$sql .= " '".$this->db->escape(dol_trunc(trim($firstname), 128, 'right', 'UTF-8', 1))."', '".$this->db->escape(dol_trunc(trim($lastname), 128, 'right', 'UTF-8', 1))."',";
		// A reference number that cannot collide until the row has its own.
		$sql .= " '".$this->db->escape('N'.dol_now().mt_rand(100, 999))."', '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_donor');
		if (!$this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_donor SET refnr = 'D".$id."' WHERE rowid = ".$id)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return $id;
	}

	/**
	 * The donors of a year with what they gave and where their report stands.
	 *
	 * @param int $year Year
	 * @return array<int,array<string,mixed>> By name
	 */
	public function donors($year)
	{
		global $conf;

		$entity = (int) $conf->entity;
		$sql = "SELECT p.rowid, p.fk_soc, p.fk_adherent, p.firstname, p.lastname, p.birth, p.refnr, p.vbpk, p.vbpk_state, p.given_on,";
		$sql .= " SUM(d.amount) as total, COUNT(d.rowid) as gifts FROM ".MAIN_DB_PREFIX."vereine_donor as p";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_donation_gift as g ON g.fk_donor = p.rowid AND g.entity = p.entity";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."don as d ON d.rowid = g.fk_don AND d.fk_statut = 2";
		$sql .= " AND d.datedon BETWEEN '".((int) $year)."-01-01 00:00:00' AND '".((int) $year)."-12-31 23:59:59'";
		$sql .= " WHERE p.entity = ".$entity." GROUP BY p.rowid, p.fk_soc, p.fk_adherent, p.firstname, p.lastname, p.birth, p.refnr, p.vbpk, p.vbpk_state, p.given_on";
		$sql .= " ORDER BY p.lastname, p.firstname, p.rowid";
		$donors = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$donors[(int) $obj->rowid] = $this->row($obj) + array('total' => round((float) $obj->total, 2), 'gifts' => (int) $obj->gifts);
		}
		// Donors reported before whose gifts of the year are gone: their report has to be cancelled.
		$sql = "SELECT DISTINCT p.rowid, p.fk_soc, p.fk_adherent, p.firstname, p.lastname, p.birth, p.refnr, p.vbpk, p.vbpk_state, p.given_on";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_donor as p INNER JOIN ".MAIN_DB_PREFIX."vereine_donation_line as l ON l.fk_donor = p.rowid";
		$sql .= " WHERE p.entity = ".$entity." AND l.fiscal_year = ".((int) $year);
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			if (!isset($donors[(int) $obj->rowid])) {
				$donors[(int) $obj->rowid] = $this->row($obj) + array('total' => 0.0, 'gifts' => 0);
			}
		}
		$held = $this->held($year);
		foreach ($donors as $id => $donor) {
			$donors[$id]['held'] = isset($held[$id]) ? $held[$id]['amount'] : null;
			$donors[$id]['pending'] = isset($held[$id]) && $held[$id]['pending'];
			$donors[$id]['next'] = $donor['vbpk'] !== '' && !$donors[$id]['pending'] ? VereineDonationRules::transmission($donor['total'], $donors[$id]['held']) : '';
		}
		return $donors;
	}

	/**
	 * A donor as the page needs it; the date of birth stays encrypted here.
	 *
	 * @param object $obj Row
	 * @return array<string,mixed>
	 */
	private function row($obj)
	{
		return array('id' => (int) $obj->rowid, 'socid' => (int) $obj->fk_soc, 'member_id' => (int) $obj->fk_adherent,
			'firstname' => (string) $obj->firstname, 'lastname' => (string) $obj->lastname, 'has_birth' => (string) $obj->birth !== '',
			'refnr' => (string) $obj->refnr, 'vbpk' => (string) $obj->vbpk, 'vbpk_state' => (string) $obj->vbpk_state,
			'given_on' => $obj->given_on !== null ? substr((string) $obj->given_on, 0, 10) : '',
			// Only a person has an identifier; a company gets a confirmation instead.
			'person' => trim((string) $obj->firstname) !== '');
	}

	/**
	 * What the tax office holds for each donor of a year, and whether a line still waits for its protocol.
	 *
	 * @param int $year Year
	 * @return array<int,array{amount:float|null,pending:bool}>
	 */
	public function held($year)
	{
		global $conf;

		$held = array();
		$sql = "SELECT l.fk_donor, l.kind, l.amount, l.state FROM ".MAIN_DB_PREFIX."vereine_donation_line as l";
		$sql .= " WHERE l.entity = ".((int) $conf->entity)." AND l.fiscal_year = ".((int) $year)." ORDER BY l.rowid";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$donor = (int) $obj->fk_donor;
			if (!isset($held[$donor])) {
				$held[$donor] = array('amount' => null, 'pending' => false);
			}
			if ($obj->state === self::LINE_SENT) {
				$held[$donor]['pending'] = true;
			} elseif ($obj->state === self::LINE_OK) {
				// The last accepted line decides; a cancellation leaves nothing held.
				$held[$donor]['amount'] = $obj->kind === VereineDonationRules::TYPE_CANCEL ? 0.0 : round((float) $obj->amount, 2);
			}
		}
		return $held;
	}

	/**
	 * One donor with the date of birth decrypted, for whoever may see it.
	 *
	 * @param int $id Donor
	 * @return array<string,mixed>|null
	 */
	public function fetchDonor($id)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';

		$sql = "SELECT rowid, fk_soc, fk_adherent, firstname, lastname, birth, refnr, vbpk, vbpk_state, given_on FROM ".MAIN_DB_PREFIX."vereine_donor";
		$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		return $this->row($obj) + array('birth' => (string) $obj->birth !== '' ? dolDecrypt((string) $obj->birth) : '');
	}

	/**
	 * The paid donations of a donor, all years.
	 *
	 * @param int $id Donor
	 * @return array<int,array{id:int,ref:string,day:string,amount:float}>
	 */
	public function gifts($id)
	{
		global $conf;

		$sql = "SELECT d.rowid, d.ref, d.datedon, d.amount FROM ".MAIN_DB_PREFIX."don as d INNER JOIN ".MAIN_DB_PREFIX."vereine_donation_gift as g ON g.fk_don = d.rowid";
		$sql .= " WHERE g.fk_donor = ".((int) $id)." AND g.entity = ".((int) $conf->entity)." AND d.fk_statut = 2 ORDER BY d.datedon, d.rowid";
		$gifts = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$gifts[] = array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'day' => substr((string) $obj->datedon, 0, 10), 'amount' => round((float) $obj->amount, 2));
		}
		return $gifts;
	}

	/**
	 * Store what a donor made known for the report: names, date of birth, reference number, identifier.
	 *
	 * @param int                 $id      Donor
	 * @param array<string,mixed> $entered firstname, lastname, birth, refnr, vbpk, given_on
	 * @param string              $today   Today, YYYY-MM-DD
	 * @param User                $user    Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function saveDonor($id, array $entered, $today, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';

		$this->errors = array();
		$donor = $this->fetchDonor($id);
		if ($donor === null) {
			$this->errors[] = 'VereineDonationErrorDonor';
			return 0;
		}
		$text = static function ($key) use ($entered) {
			return isset($entered[$key]) ? trim((string) $entered[$key]) : '';
		};
		$refnr = VereineDonationRules::refNr($text('refnr'));
		if ($refnr === '') {
			$this->errors[] = 'VereineDonationErrorRef';
		}
		$birth = $text('birth') !== '' ? VereineDonationRules::birthDate($text('birth'), $today) : '';
		if ($text('birth') !== '' && $birth === '') {
			$this->errors[] = 'VereineDonationErrorBirth';
		}
		$vbpk = $text('vbpk') !== '' ? VereineDonationRules::vbpk($text('vbpk')) : '';
		if ($text('vbpk') !== '' && $vbpk === '') {
			$this->errors[] = 'VereineDonationErrorVbpk';
		}
		$given = $text('given_on');
		if ($given !== '' && VereineDonationRules::birthDate($given, $today) === '') {
			$this->errors[] = 'VereineDonationErrorGiven';
		}
		if ($this->errors) {
			return 0;
		}
		$encrypted = $birth !== '' ? dolEncrypt($birth) : '';
		// Never a date of birth in the clear: without Dolibarr's key it is not stored at all.
		if ($birth !== '' && strncmp($encrypted, 'dolcrypt:', 9) !== 0) {
			$this->errors[] = 'VereineDonationErrorCrypt';
			return 0;
		}
		$renamed = $text('firstname') !== $donor['firstname'] || $text('lastname') !== $donor['lastname'] || $birth !== $donor['birth'];
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_donor SET firstname = '".$this->db->escape(dol_trunc($text('firstname'), 128, 'right', 'UTF-8', 1))."',";
		$sql .= " lastname = '".$this->db->escape(dol_trunc($text('lastname'), 128, 'right', 'UTF-8', 1))."', birth = ".($encrypted !== '' ? "'".$this->db->escape($encrypted)."'" : "NULL").",";
		$sql .= " refnr = '".$this->db->escape($refnr)."', vbpk = ".($vbpk !== '' ? "'".$this->db->escape($vbpk)."'" : "NULL").",";
		// An identifier entered by hand counts as found; other data changed, an old answer of the register no longer fits.
		$state = $vbpk !== '' ? VereineDonationRules::STATE_FOUND : ($renamed ? VereineDonationRules::STATE_NONE : $donor['vbpk_state']);
		$sql .= " vbpk_state = ".($state !== '' ? "'".$this->db->escape($state)."'" : "NULL").", given_on = ".($given !== '' ? "'".$this->db->escape($given)."'" : "NULL").",";
		$sql .= " fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			// The reference number is unique per association.
			$this->errors[] = 'VereineDonationErrorRefTaken';
			return 0;
		}
		VereineLog::add($this->db, $user, VereineLog::DONATION_DONOR, $donor['member_id'], $donor['socid'], $refnr.($vbpk !== '' ? ': vbPK' : ''));
		return 1;
	}

	/**
	 * Move a donation to another donor, when the automatic link took the wrong person.
	 *
	 * @param int  $donId   Donation of Dolibarr
	 * @param int  $donorId Donor
	 * @param User $user    Who moves
	 * @return int 1 when moved, 0 when refused, -1 on error
	 */
	public function move($donId, $donorId, $user)
	{
		global $conf;

		if ($this->fetchDonor($donorId) === null) {
			$this->errors[] = 'VereineDonationErrorDonor';
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_donation_gift SET fk_donor = ".((int) $donorId)." WHERE fk_don = ".((int) $donId)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::DONATION_DONOR, 0, 0, 'donation '.((int) $donId).' to donor '.((int) $donorId));
		return 1;
	}

	/**
	 * Every donor, for moving a donation.
	 *
	 * @return array<int,string> Donor => name and reference number
	 */
	public function donorChoices()
	{
		global $conf;

		$choices = array();
		$resql = $this->db->query("SELECT rowid, firstname, lastname, refnr FROM ".MAIN_DB_PREFIX."vereine_donor WHERE entity = ".((int) $conf->entity)." ORDER BY lastname, firstname");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$choices[(int) $obj->rowid] = trim($obj->firstname.' '.$obj->lastname).' ('.$obj->refnr.')';
		}
		return $choices;
	}

	/**
	 * The batch file for the register: the donors of a year who made names and date of birth known and have no identifier yet.
	 *
	 * @param int $year Year
	 * @return array{content:string,count:int}
	 */
	public function szrExport($year)
	{
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

		$settings = self::settings();
		$people = array();
		foreach ($this->donors($year) as $donor) {
			if (!$donor['person'] || $donor['vbpk'] !== '' || !$donor['has_birth'] || $donor['total'] < 0.01) {
				continue;
			}
			$full = $this->fetchDonor($donor['id']);
			$place = array('town' => '', 'zip' => '', 'address' => '', 'country' => '');
			$company = new Societe($this->db);
			if ($donor['socid'] > 0 && $company->fetch($donor['socid']) > 0) {
				$place = array('town' => (string) $company->town, 'zip' => (string) $company->zip, 'address' => (string) $company->address,
					'country' => (string) $company->country_code);
			}
			$people[] = array('ref' => $donor['refnr'], 'lastname' => $donor['lastname'], 'firstname' => $donor['firstname'],
				'birth' => $full !== null ? $full['birth'] : '') + $place;
		}
		$header = array('contact' => $settings['contact'], 'email' => $settings['email'], 'vkz' => $settings['vkz'],
			'reference' => 'Ausstattung mit vbPK SA für die Spendenmeldung '.((int) $year));
		return array('content' => VereineDonationRules::szrFile($header, $people), 'count' => count($people));
	}

	/**
	 * Read back what the register answered: its ZIP, or the CSV files out of it.
	 *
	 * @param array<string,mixed> $upload One entry of $_FILES
	 * @param User                $user   Who reads
	 * @return int Donors updated, 0 when refused (see errors), -1 on error
	 */
	public function szrImport(array $upload, $user)
	{
		global $conf;

		$files = $this->uploaded($upload, array('csv', 'zip'));
		if ($files === null) {
			return 0;
		}
		$updated = 0;
		foreach ($files as $name => $content) {
			$state = VereineDonationRules::szrKind($name);
			if ($state === '') {
				continue;
			}
			$result = VereineDonationRules::szrResult($content);
			foreach ($result['refs'] as $ref) {
				$vbpk = isset($result['vbpk'][$ref]) ? $result['vbpk'][$ref] : '';
				if ($state === VereineDonationRules::STATE_FOUND && $vbpk === '') {
					continue;
				}
				$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_donor SET vbpk_state = '".$this->db->escape($state)."'";
				if ($vbpk !== '') {
					$sql .= ", vbpk = '".$this->db->escape($vbpk)."'";
				}
				$sql .= ", fk_user_modif = ".((int) $user->id)." WHERE entity = ".((int) $conf->entity)." AND refnr = '".$this->db->escape($ref)."'";
				// A person the register did not find keeps an identifier entered earlier by hand.
				if ($state !== VereineDonationRules::STATE_FOUND) {
					$sql .= " AND vbpk IS NULL";
				}
				$resql = $this->db->query($sql);
				if (!$resql) {
					$this->error = $this->db->lasterror();
					return -1;
				}
				$updated += (int) $this->db->affected_rows($resql);
			}
		}
		if ($updated === 0) {
			$this->errors[] = 'VereineDonationErrorSzrNothing';
			return 0;
		}
		VereineLog::add($this->db, $user, VereineLog::DONATION_SZR, 0, 0, $updated.' donors');
		return $updated;
	}

	/**
	 * The files of an upload: a CSV or XML as it is, or the CSV files of a ZIP.
	 *
	 * @param array<string,mixed> $upload     One entry of $_FILES
	 * @param string[]            $extensions What is accepted
	 * @return array<string,string>|null Name => content, null when refused (see errors)
	 */
	private function uploaded(array $upload, array $extensions)
	{
		$this->errors = array();
		$name = isset($upload['name']) ? (string) $upload['name'] : '';
		$temporary = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
		$size = isset($upload['size']) ? (int) $upload['size'] : 0;
		$extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
		if ($name === '' || $temporary === '' || $size <= 0 || !is_uploaded_file($temporary)) {
			$this->errors[] = 'VereineDonationErrorUpload';
			return null;
		}
		if ($size > self::UPLOAD_MAX || !in_array($extension, $extensions, true)) {
			$this->errors[] = 'VereineDonationErrorUploadKind';
			return null;
		}
		if ($extension !== 'zip') {
			return array($name => (string) file_get_contents($temporary));
		}
		if (!class_exists('ZipArchive')) {
			$this->errors[] = 'VereineDonationErrorZip';
			return null;
		}
		$zip = new ZipArchive();
		if ($zip->open($temporary) !== true) {
			$this->errors[] = 'VereineDonationErrorZip';
			return null;
		}
		$files = array();
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$entry = (string) $zip->getNameIndex($index);
			$stat = $zip->statIndex($index);
			// Only the flat CSV files of the register, and never more than the upload may hold.
			if (strtolower((string) pathinfo($entry, PATHINFO_EXTENSION)) === 'csv' && is_array($stat) && (int) $stat['size'] <= self::UPLOAD_MAX) {
				$files[basename($entry)] = (string) $zip->getFromIndex($index);
			}
		}
		$zip->close();
		return $files;
	}

	/**
	 * Write the report of a year: a line for every donor whose sum the tax office does not hold yet.
	 *
	 * @param int    $year  Year
	 * @param string $today Today, YYYY-MM-DD
	 * @param User   $user  Who writes
	 * @return int Report, 0 when refused (see errors and problems), -1 on error
	 */
	public function createReport($year, $today, $user)
	{
		global $conf;

		$this->errors = array();
		$this->problems = array();
		$settings = self::settings();
		if (!in_array($settings['kind'], VereineDonationRules::KINDS, true)) {
			$this->errors[] = 'VereineDonationErrorKind';
			return 0;
		}
		if ((int) $year < VereineDonationRules::FIRST_YEAR || (int) $year >= (int) substr($today, 0, 4)) {
			$this->errors[] = 'VereineDonationErrorYear';
			return 0;
		}
		if ($this->link($year, $user) < 0) {
			return -1;
		}
		$lines = array();
		foreach ($this->donors($year) as $donor) {
			if ($donor['next'] === '') {
				continue;
			}
			$lines[] = array('donor' => $donor['id'], 'type' => $donor['next'], 'ref' => $donor['refnr'], 'vbpk' => $donor['vbpk'],
				'amount' => $donor['next'] === VereineDonationRules::TYPE_CANCEL ? '' : VereineDonationRules::amount($donor['total']));
		}
		if (!$lines) {
			$this->errors[] = 'VereineDonationErrorNothing';
			return 0;
		}
		$entity = (int) $conf->entity;
		$now = dol_now();
		$this->db->begin();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_donation_report (entity, fiscal_year, message_ref, kind, status, line_count, datec, fk_user_creat)";
		$sql .= " VALUES (".$entity.", ".((int) $year).", '".$this->db->escape('pending-'.$now.mt_rand(100, 999))."', '".$this->db->escape($settings['kind'])."',";
		$sql .= " '".self::REPORT_CREATED."', ".count($lines).", '".$this->db->idate($now)."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_donation_report');
		$ref = VereineDonationRules::messageRef($year, $id, dol_print_date($now, '%Y%m%d%H%M%S', 'tzserver'));
		$xml = VereineDonationRules::xml(array('message_ref' => $ref, 'timestamp' => dol_print_date($now, '%Y-%m-%dT%H:%M:%S', 'tzserver'),
			'kind' => $settings['kind'], 'year' => (string) ((int) $year), 'fastnr_org' => $settings['fastnr_org'], 'fastnr_tn' => $settings['fastnr_tn']), $lines);
		// Checked against the schema before anybody can download it.
		$this->problems = VereineDonationRules::validate($xml, self::schema());
		if ($this->problems) {
			$this->db->rollback();
			$this->errors[] = 'VereineDonationErrorSchema';
			return 0;
		}
		$ok = $this->db->query("UPDATE ".MAIN_DB_PREFIX."vereine_donation_report SET message_ref = '".$this->db->escape($ref)."' WHERE rowid = ".$id);
		foreach ($lines as $line) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_donation_line (entity, fk_report, fk_donor, fiscal_year, refnr, kind, amount, state)";
			$sql .= " VALUES (".$entity.", ".$id.", ".((int) $line['donor']).", ".((int) $year).", '".$this->db->escape($line['ref'])."',";
			$sql .= " '".$this->db->escape($line['type'])."', ".((float) ($line['amount'] !== '' ? $line['amount'] : 0)).", '".self::LINE_SENT."')";
			$ok = $ok && $this->db->query($sql);
		}
		$file = self::reportPath(array('id' => $id, 'year' => $year));
		if (!$ok || dol_mkdir(dirname($file)) < 0 || file_put_contents($file, $xml) === false) {
			$this->error = $ok ? 'cannot write '.$file : $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::DONATION_REPORT, 0, 0, $ref.': '.count($lines).' lines');
		return $id;
	}

	/**
	 * The reports of a year, newest first.
	 *
	 * @param int $year Year
	 * @return array<int,array<string,mixed>>
	 */
	public function reports($year)
	{
		global $conf;

		$sql = "SELECT rowid, fiscal_year, message_ref, kind, status, line_count, protocol, protocol_test, protocol_on, datec FROM ".MAIN_DB_PREFIX."vereine_donation_report";
		$sql .= " WHERE entity = ".((int) $conf->entity).((int) $year > 0 ? " AND fiscal_year = ".((int) $year) : "")." ORDER BY rowid DESC";
		$reports = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$reports[] = array('id' => (int) $obj->rowid, 'year' => (int) $obj->fiscal_year, 'message_ref' => (string) $obj->message_ref,
				'kind' => (string) $obj->kind, 'status' => (string) $obj->status, 'lines' => (int) $obj->line_count, 'protocol' => (string) $obj->protocol,
				'protocol_test' => (int) $obj->protocol_test === 1, 'protocol_on' => $obj->protocol_on !== null ? $this->db->jdate($obj->protocol_on) : 0,
				'created' => $this->db->jdate($obj->datec));
		}
		return $reports;
	}

	/**
	 * One report.
	 *
	 * @param int $id Report
	 * @return array<string,mixed>|null
	 */
	public function fetchReport($id)
	{
		foreach ($this->reports(0) as $report) {
			if ($report['id'] === (int) $id) {
				return $report;
			}
		}
		return null;
	}

	/**
	 * The lines of a report.
	 *
	 * @param int $id Report
	 * @return array<int,array<string,mixed>>
	 */
	public function lines($id)
	{
		global $conf;

		$sql = "SELECT l.rowid, l.fk_donor, l.refnr, l.kind, l.amount, l.state, l.error, p.firstname, p.lastname FROM ".MAIN_DB_PREFIX."vereine_donation_line as l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."vereine_donor as p ON p.rowid = l.fk_donor WHERE l.fk_report = ".((int) $id)." AND l.entity = ".((int) $conf->entity)." ORDER BY l.rowid";
		$lines = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$lines[] = array('id' => (int) $obj->rowid, 'donor' => (int) $obj->fk_donor, 'refnr' => (string) $obj->refnr, 'kind' => (string) $obj->kind,
				'amount' => round((float) $obj->amount, 2), 'state' => (string) $obj->state, 'error' => (string) $obj->error,
				'name' => trim($obj->firstname.' '.$obj->lastname));
		}
		return $lines;
	}

	/**
	 * Take back a report nobody has sent for real: its lines are gone, the donors wait again.
	 *
	 * @param int  $id   Report
	 * @param User $user Who takes it back
	 * @return int 1 when taken back, 0 when refused, -1 on error
	 */
	public function discard($id, $user)
	{
		global $conf;

		$report = $this->fetchReport($id);
		if ($report === null || $report['status'] !== self::REPORT_CREATED) {
			$this->errors[] = 'VereineDonationErrorDiscard';
			return 0;
		}
		$entity = (int) $conf->entity;
		$this->db->begin();
		if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_donation_line WHERE fk_report = ".((int) $id)." AND entity = ".$entity)
			|| !$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_donation_report WHERE rowid = ".((int) $id)." AND entity = ".$entity)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		dol_delete_file(self::reportPath($report));
		VereineLog::add($this->db, $user, VereineLog::DONATION_REPORT, 0, 0, $report['message_ref'].': taken back');
		return 1;
	}

	/**
	 * Read the protocol FinanzOnline put into the DataBox, and mark every line taken or refused.
	 *
	 * A protocol of a test transmission is noted, but nothing counts as sent: test data are not filed.
	 *
	 * @param array<string,mixed> $upload One entry of $_FILES
	 * @param User                $user   Who reads
	 * @return int The report, 0 when refused (see errors), -1 on error
	 */
	public function readProtocol(array $upload, $user)
	{
		global $conf;

		$files = $this->uploaded($upload, array('xml'));
		if ($files === null) {
			return 0;
		}
		$protocol = VereineDonationRules::protocol((string) reset($files));
		if ($protocol === null) {
			$this->errors[] = 'VereineDonationErrorProtocol';
			return 0;
		}
		$report = null;
		foreach ($this->reports(0) as $candidate) {
			if ($candidate['message_ref'] === $protocol['message_ref']) {
				$report = $candidate;
			}
		}
		if ($report === null) {
			$this->errors[] = 'VereineDonationErrorProtocolUnknown';
			return 0;
		}
		if ($report['status'] === self::REPORT_DONE) {
			$this->errors[] = 'VereineDonationErrorProtocolRead';
			return 0;
		}
		$entity = (int) $conf->entity;
		$this->db->begin();
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_donation_report SET protocol = '".$this->db->escape($protocol['info'])."', protocol_test = ".($protocol['test'] ? 1 : 0).",";
		$sql .= " protocol_on = '".$this->db->idate(dol_now())."'".($protocol['test'] ? "" : ", status = '".self::REPORT_DONE."'");
		$sql .= " WHERE rowid = ".((int) $report['id'])." AND entity = ".$entity;
		$ok = (bool) $this->db->query($sql);
		if (!$protocol['test']) {
			foreach ($this->lines($report['id']) as $line) {
				$taken = VereineDonationRules::accepted($protocol, $line['refnr']);
				$error = $taken ? '' : implode(' · ', isset($protocol['errors'][$line['refnr']]) ? $protocol['errors'][$line['refnr']] : $protocol['general']);
				$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_donation_line SET state = '".($taken ? self::LINE_OK : self::LINE_FAILED)."',";
				$sql .= " error = ".($error !== '' ? "'".$this->db->escape(dol_trunc($error, 2000, 'right', 'UTF-8', 1))."'" : "NULL")." WHERE rowid = ".$line['id'];
				$ok = $ok && $this->db->query($sql);
			}
		}
		if (!$ok) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::DONATION_PROTOCOL, 0, 0, $report['message_ref'].': '.$protocol['info'].($protocol['test'] ? ' (test)' : ''));
		return $report['id'];
	}

	/**
	 * A confirmation of the donations of a year for one donor, as PDF.
	 *
	 * @param int       $id          Donor
	 * @param int       $year        Year
	 * @param Translate $outputlangs Language
	 * @return string File, empty when there is nothing to confirm
	 */
	public function receipt($id, $year, $outputlangs)
	{
		global $mysoc;

		require_once __DIR__.'/vereinepdf.class.php';
		require_once __DIR__.'/vereineorganization.class.php';
		require_once dirname(__DIR__).'/lib/vereine.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

		$donor = $this->fetchDonor($id);
		$gifts = array();
		foreach ($donor !== null ? $this->gifts($id) : array() as $gift) {
			if ((int) substr($gift['day'], 0, 4) === (int) $year) {
				$gifts[] = $gift;
			}
		}
		if (!$gifts) {
			return '';
		}
		$outputlangs->loadLangs(array('main', 'vereine@vereine'));
		$file = self::directory().'/spendenbestaetigung-'.((int) $year).'-'.((int) $id).'.pdf';
		if (dol_mkdir(dirname($file)) < 0) {
			$this->error = 'cannot create '.dirname($file);
			return '';
		}
		$organization = VereineOrganization::load($mysoc);
		$money = function ($amount) use ($outputlangs) {
			return price($amount, 0, $outputlangs, 1, -1, 2).' €';
		};
		$pdf = VereinePdf::start($outputlangs);
		$font = pdf_getPDFFont($outputlangs);
		$line = function ($text, $style = '', $size = 10) use ($pdf, $font) {
			$pdf->SetFont($font, $style, $size);
			$pdf->MultiCell(0, 5, $text, 0, 'L');
		};
		$name = trim($donor['firstname'].' '.$donor['lastname']);
		VereinePdf::title($pdf, $outputlangs, $outputlangs->transnoentities('VereineDonationReceiptTitle', (int) $year), $name);
		$line($outputlangs->transnoentities('VereineDonationReceiptIntro', $organization['name'], $name, (int) $year));
		$pdf->Ln(2);
		$total = 0.0;
		foreach ($gifts as $gift) {
			$line(vereineFormatDay($gift['day']).' · '.$money($gift['amount']).($gift['ref'] !== '' ? ' · '.$gift['ref'] : ''), '', 9);
			$total += $gift['amount'];
		}
		$pdf->Ln(2);
		$line($outputlangs->transnoentities('VereineDonationReceiptTotal', $money($total)), 'B');
		$line($outputlangs->transnoentitiesnoconv('VereineDonationReceiptNote'), '', 8);
		VereinePdf::finish($pdf, $outputlangs, $outputlangs->transnoentities('VereineDonationReceiptTitle', (int) $year));
		$pdf->Output($file, 'F');
		return $file;
	}
}
