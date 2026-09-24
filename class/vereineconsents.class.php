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
 * \file    class/vereineconsents.class.php
 * \ingroup vereine
 * \brief   Consent texts with versions, consents of members and membership applications from a website.
 */

require_once __DIR__.'/vereineconsentrules.class.php';
require_once __DIR__.'/vereinemembersummary.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Stores consent texts, consents and applications.
 */
class VereineConsents
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last database error
	 */
	public $error = '';

	/**
	 * @var string[] Language keys of the last refused input
	 */
	public $errors = array();

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
	 * Consent texts of this entity, per code the newest version first.
	 *
	 * @return array<int,array{id:int,code:string,version:int,label:string,text:string,active:bool}>
	 */
	public function texts()
	{
		global $conf;

		$sql = "SELECT rowid, code, version, label, text, active FROM ".MAIN_DB_PREFIX."vereine_consent_text";
		$sql .= " WHERE entity = ".((int) $conf->entity)." ORDER BY code, version DESC";
		// The table exists only after the module was enabled with 0.3.11.
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$texts = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$texts[] = array('id' => (int) $obj->rowid, 'code' => (string) $obj->code, 'version' => (int) $obj->version,
				'label' => (string) $obj->label, 'text' => (string) $obj->text, 'active' => (int) $obj->active === 1);
		}
		$this->db->free($resql);
		return $texts;
	}

	/**
	 * The texts a person can agree to now: the newest version of every active code.
	 *
	 * @return array<string,array<string,mixed>> By code
	 */
	public function currentTexts()
	{
		$current = array();
		foreach ($this->texts() as $text) {
			if ($text['active'] && !isset($current[$text['code']])) {
				$current[$text['code']] = $text;
			}
		}
		return $current;
	}

	/**
	 * Store a consent text. A changed label or text becomes a new version; the old one stays.
	 *
	 * @param string $code  Purpose code
	 * @param string $label Short name
	 * @param string $text  Text the person agrees to
	 * @param User   $user  User
	 * @return int Version stored, 0 when refused (see $errors), <0 on error
	 */
	public function saveText($code, $label, $text, $user)
	{
		global $conf;

		$this->errors = VereineConsentRules::validateText(array('code' => $code, 'label' => $label, 'text' => $text));
		if ($this->errors) {
			return 0;
		}
		$label = trim((string) $label);
		$text = trim((string) $text);
		$latest = null;
		foreach ($this->texts() as $existing) {
			if ($existing['code'] === $code) {
				$latest = $existing;
				break;
			}
		}
		if ($latest !== null && $latest['label'] === $label && $latest['text'] === $text) {
			return $this->setActive($code, true) < 0 ? -1 : $latest['version'];
		}
		$version = $latest === null ? 1 : $latest['version'] + 1;
		$this->db->begin();
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_consent_text SET active = 0 WHERE entity = ".((int) $conf->entity)." AND code = '".$this->db->escape($code)."'";
		$insert = "INSERT INTO ".MAIN_DB_PREFIX."vereine_consent_text (entity, code, version, label, text, active, datec, fk_user)";
		$insert .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($code)."', ".$version.", '".$this->db->escape($label)."',";
		$insert .= " '".$this->db->escape($text)."', 1, '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql) || !$this->db->query($insert)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return $version;
	}

	/**
	 * Offer a purpose or stop offering it. Consents already given keep their text.
	 *
	 * @param string $code   Purpose code
	 * @param bool   $active Offer the newest version
	 * @return int 1 if OK, <0 on error
	 */
	public function setActive($code, $active)
	{
		global $conf;

		$where = " WHERE entity = ".((int) $conf->entity)." AND code = '".$this->db->escape($code)."'";
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_consent_text SET active = 0".$where;
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($active) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_consent_text SET active = 1".$where;
			$sql .= " AND version = (SELECT v FROM (SELECT MAX(version) as v FROM ".MAIN_DB_PREFIX."vereine_consent_text".$where.") as newest)";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		return 1;
	}

	/**
	 * Record that a member gave or withdrew a consent.
	 *
	 * @param int      $memberId Member
	 * @param string   $code     Purpose code
	 * @param int      $version  Version of the text
	 * @param bool     $given    True for a consent, false for a withdrawal
	 * @param string   $source   One of VereineConsentRules::SOURCES
	 * @param string   $note     Note, such as where the paper form is kept
	 * @param User                $user  User who records it
	 * @param array<string,mixed> $proof How it was given: at, form, ref (see VereineConsentRules::proof())
	 * @return int Id, <0 on error
	 */
	public function record($memberId, $code, $version, $given, $source, $note, $user, array $proof = array())
	{
		global $conf;

		if (!VereineConsentRules::isCode($code) || !in_array($source, VereineConsentRules::SOURCES, true) || (int) $version <= 0) {
			$this->error = 'invalid consent';
			return -1;
		}
		$proof = VereineConsentRules::proof($proof);
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_consent (entity, fk_adherent, code, version, given, source, date_event, note, fk_user,";
		$sql .= " proof_at, proof_form, proof_ref)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $memberId).", '".$this->db->escape($code)."', ".((int) $version).", ".($given ? 1 : 0).",";
		$sql .= " '".$this->db->escape($source)."', '".$this->db->idate(dol_now())."', '".$this->db->escape(dol_trunc(trim((string) $note), 255, 'right', 'UTF-8', 1))."',";
		$sql .= " ".(is_object($user) && (int) $user->id > 0 ? (int) $user->id : "NULL").",";
		$sql .= " ".($proof['at'] !== '' ? "'".$this->db->escape($proof['at'])."'" : "NULL").", '".$this->db->escape($proof['form'])."', '".$this->db->escape($proof['ref'])."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_consent');
		VereineLog::add($this->db, $user, $given ? VereineLog::CONSENT_GIVEN : VereineLog::CONSENT_WITHDRAWN, (int) $memberId, 0, $code.' v'.((int) $version).' / '.$source);
		return $id;
	}

	/**
	 * The application as PDF at the documents of the member, as if it came in on paper (#111).
	 *
	 * @param Adherent $member    Member in draft
	 * @param string   $signature Signature drawn on the screen as a PNG, empty when there is none
	 * @param User     $user      API user
	 * @return bool Whether the document was written
	 */
	private function applicationDocument($member, $signature, $user)
	{
		global $conf, $langs;

		dol_include_once('/vereine/class/vereinememberform.class.php');
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$image = '';
		if ($signature !== '') {
			$image = DOL_DATA_ROOT.($conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/vereine/temp/unterschrift-'.((int) $member->id).'.png';
			if (dol_mkdir(dirname($image)) < 0 || file_put_contents($image, $signature) === false) {
				dol_syslog(__METHOD__.' cannot keep the signature at '.$image, LOG_WARNING);
				$image = '';
			}
		}
		$form = new VereineMemberForm($this->db);
		$directory = $conf->adherent->dir_output.'/'.dol_sanitizeFileName($member->ref !== '' ? $member->ref : (string) $member->id);
		$file = $directory.'/mitgliedsantrag-'.dol_sanitizeFileName($member->ref !== '' ? $member->ref : (string) $member->id).'.pdf';
		$submitted = array('at' => dol_now(), 'signature' => $image);
		$written = $form->build($member, (int) $member->typeid, $langs, $file, $submitted) !== '';
		if ($image !== '') {
			dol_delete_file($image, 0, 1, 0, null, false, 0);
		}
		if (!$written) {
			dol_syslog(__METHOD__.' '.$form->error, LOG_WARNING);
			return false;
		}
		VereineLog::add($this->db, $user, VereineLog::APPLICATION_PDF, (int) $member->id, 0,
			basename($file).' / sha256 '.substr(hash_file('sha256', $file), 0, 16).($signature !== '' ? ' / signature' : ''));
		return true;
	}

	/**
	 * What a member can be asked about their consents: the state per purpose with the version they
	 * agreed to, the current version of the text and what they can do now (#98).
	 *
	 * @param int $memberId Member
	 * @return array<int,array<string,mixed>> By purpose, in the order of the texts
	 */
	public function stateFor($memberId)
	{
		$current = VereineConsentRules::current($this->history((int) $memberId));
		$texts = $this->currentTexts();
		$labels = array();
		foreach ($this->texts() as $text) {
			if (!isset($labels[$text['code']])) {
				$labels[$text['code']] = $text['label'];
			}
		}
		$state = array();
		foreach (array_unique(array_merge(array_keys($texts), array_keys($current))) as $code) {
			$event = isset($current[$code]) ? $current[$code] : null;
			$given = $event !== null && $event['given'];
			$state[] = array(
				'code' => $code,
				'label' => isset($labels[$code]) ? $labels[$code] : $code,
				'state' => $event === null ? 'none' : ($given ? 'given' : 'withdrawn'),
				'version' => $event !== null ? (int) $event['version'] : 0,
				'current_version' => isset($texts[$code]) ? (int) $texts[$code]['version'] : 0,
				'moment' => $event !== null ? dol_print_date($event['moment'], 'dayhourrfc') : '',
				'can_give' => isset($texts[$code]) && (!$given || (int) $event['version'] !== (int) $texts[$code]['version']),
				'can_withdraw' => $given,
			);
		}
		return $state;
	}

	/**
	 * Record a decision a member made about a purpose, as a website sends it (#98).
	 *
	 * The same decision with the same reference is recorded once; a consent that arrives late must
	 * not undo a withdrawal that happened after it.
	 *
	 * @param int                 $memberId Member
	 * @param array<string,mixed> $decision Normalised decision, see VereineConsentRules::decision()
	 * @param User                $user     API user
	 * @return array{code:string,state:string,version:int,recorded:bool}|null Null on error or conflict, see $error and $errors
	 */
	public function decide($memberId, array $decision, $user)
	{
		$this->errors = array();
		$history = $this->history((int) $memberId);
		$reference = $decision['proof']['ref'];
		foreach ($history as $event) {
			// The same order sent twice: what was recorded the first time counts.
			if ($reference !== '' && $event['code'] === $decision['code'] && $event['proof_ref'] === $reference
				&& $event['given'] === ($decision['decision'] === 'given')) {
				return array('code' => $decision['code'], 'state' => $decision['decision'], 'version' => (int) $event['version'], 'recorded' => false);
			}
		}
		$current = VereineConsentRules::current($history);
		$latest = isset($current[$decision['code']]) ? $current[$decision['code']] : null;
		if ($decision['decision'] === 'given' && $latest !== null && !$latest['given'] && $decision['proof']['at'] !== ''
			&& $decision['proof']['at'] < dol_print_date($latest['moment'], '%Y-%m-%d %H:%M:%S', 'tzserver')) {
			// A consent from before the withdrawal arrives late: the withdrawal stays.
			$this->errors[] = 'VereineConsentErrorLate';
			return null;
		}
		$stored = $this->record((int) $memberId, $decision['code'], (int) $decision['version'], $decision['decision'] === 'given', 'website', '', $user, $decision['proof']);
		if ($stored < 0) {
			return null;
		}
		return array('code' => $decision['code'], 'state' => $decision['decision'], 'version' => (int) $decision['version'], 'recorded' => true);
	}

	/**
	 * Attach the scan of a signed declaration to a consent: Dolibarr keeps it with the documents of the member.
	 *
	 * @param int                 $eventId  Consent event
	 * @param int                 $memberId Member the event belongs to
	 * @param array<string,mixed> $upload   One entry of $_FILES
	 * @param User                $user     Who attaches it
	 * @return int 1 when stored, 0 when refused (see $errors), -1 on error
	 */
	public function attachScan($eventId, $memberId, array $upload, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$this->errors = array();
		$name = isset($upload['name']) ? dol_sanitizeFileName((string) $upload['name']) : '';
		$temp = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if ($name === '' || $temp === '' || !is_uploaded_file($temp)) {
			$this->errors[] = 'VereineConsentScanMissing';
			return 0;
		}
		if (!in_array($extension, array('pdf', 'jpg', 'jpeg', 'png'), true)) {
			$this->errors[] = 'VereineConsentScanKind';
			return 0;
		}
		$member = $this->memberRef((int) $memberId);
		if ($member === '') {
			$this->error = 'unknown member';
			return -1;
		}
		$dir = $conf->adherent->dir_output.'/'.$member;
		if (dol_mkdir($dir) < 0) {
			$this->error = 'cannot create '.$dir;
			return -1;
		}
		$stored = 'einwilligung-'.((int) $eventId).'.'.$extension;
		if (dol_move_uploaded_file($temp, $dir.'/'.$stored, 1, 0, isset($upload['error']) ? $upload['error'] : 0) <= 0) {
			$this->error = 'cannot store '.$stored;
			return -1;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_consent SET scan_name = '".$this->db->escape($stored)."'";
		$sql .= " WHERE rowid = ".((int) $eventId)." AND fk_adherent = ".((int) $memberId)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::CONSENT_SCAN, (int) $memberId, 0, $stored);
		return 1;
	}

	/**
	 * Path of the scan of a consent, empty when there is none.
	 *
	 * @param int $eventId  Consent event
	 * @param int $memberId Member
	 * @return string
	 */
	public function scanPath($eventId, $memberId)
	{
		global $conf;

		$sql = "SELECT scan_name FROM ".MAIN_DB_PREFIX."vereine_consent WHERE rowid = ".((int) $eventId);
		$sql .= " AND fk_adherent = ".((int) $memberId)." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		$member = $obj && (string) $obj->scan_name !== '' ? $this->memberRef((int) $memberId) : '';
		if ($member === '') {
			return '';
		}
		$file = $conf->adherent->dir_output.'/'.$member.'/'.((string) $obj->scan_name);
		return is_file($file) ? $file : '';
	}

	/**
	 * The directory Dolibarr keeps the documents of a member in.
	 *
	 * @param int $memberId Member
	 * @return string Name of the directory, empty when the member is gone
	 */
	private function memberRef($memberId)
	{
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';

		$member = new Adherent($this->db);
		if ($member->fetch((int) $memberId) <= 0) {
			return '';
		}
		return dol_sanitizeFileName((string) $member->ref !== '' ? (string) $member->ref : (string) $memberId);
	}

	/**
	 * Consents and withdrawals of a member, newest first.
	 *
	 * @param int $memberId Member
	 * @return array<int,array{id:int,code:string,version:int,given:bool,source:string,date:string,note:string}>
	 */
	public function history($memberId)
	{
		global $conf;

		$sql = "SELECT rowid, code, version, given, source, date_event, note, fk_user, proof_at, proof_form, proof_ref, scan_name";
		$sql .= " FROM ".MAIN_DB_PREFIX."vereine_consent";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_adherent = ".((int) $memberId)." ORDER BY date_event DESC, rowid DESC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$events = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$events[] = array('id' => (int) $obj->rowid, 'code' => (string) $obj->code, 'version' => (int) $obj->version, 'given' => (int) $obj->given === 1,
				'source' => (string) $obj->source, 'date' => (string) $obj->date_event, 'moment' => (int) $this->db->jdate($obj->date_event), 'note' => (string) $obj->note,
				'user' => (int) $obj->fk_user, 'proof_at' => (string) $obj->proof_at, 'proof_form' => (string) $obj->proof_form,
				'proof_ref' => (string) $obj->proof_ref, 'scan' => (string) $obj->scan_name);
		}
		$this->db->free($resql);
		return $events;
	}

	/**
	 * Which members have a consent given now: their latest event for the purpose is a consent.
	 *
	 * @param int[]  $memberIds Members
	 * @param string $code      Purpose code
	 * @return array<int,bool> By member id, true when given
	 */
	public function givenBy(array $memberIds, $code)
	{
		global $conf;

		$ids = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
		if (!$ids || !VereineConsentRules::isCode($code)) {
			return array();
		}
		$sql = "SELECT fk_adherent, given FROM ".MAIN_DB_PREFIX."vereine_consent WHERE entity = ".((int) $conf->entity);
		$sql .= " AND code = '".$this->db->escape($code)."' AND fk_adherent IN (".implode(', ', $ids).") ORDER BY date_event, rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$given = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$given[(int) $obj->fk_adherent] = (int) $obj->given === 1;
		}
		$this->db->free($resql);
		return $given;
	}

	/**
	 * Create a draft member from a website application, with its consents, once per external id.
	 *
	 * @param array<string,mixed> $application Normalised application, see VereineConsentRules::application()
	 * @param User                $user        API user
	 * @return array{id:int,ref:string,status:string,duplicate:bool,document:bool}|null Null on error, see $error
	 */
	public function createApplication(array $application, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';

		if ($application['external_id'] !== '') {
			$existing = $this->applicationMember($application['external_id']);
			if ($existing !== null) {
				require_once __DIR__.'/vereineapplicationrules.class.php';
				// The same id with other content is a conflict, never a silent change (#72).
				if ($existing['fingerprint'] !== '' && $existing['fingerprint'] !== VereineApplicationRules::fingerprint($application)) {
					$this->errors[] = 'VereineApplicationErrorConflict';
					return null;
				}
				// Sent twice: the same member, and no second document (#111).
				unset($existing['fingerprint']);
				$existing['application_status'] = (string) $existing['application_status'];
				return $existing + array('duplicate' => true, 'document' => false);
			}
		}

		$member = new Adherent($this->db);
		$member->typeid = (int) $application['type_id'];
		$member->morphy = $application['morphy'];
		$member->company = $application['company'];
		$member->societe = $application['company'];
		$member->firstname = $application['firstname'];
		$member->lastname = $application['lastname'];
		$member->email = $application['email'];
		$member->phone = $application['phone'];
		$member->address = $application['address'];
		$member->zip = $application['zip'];
		$member->town = $application['town'];
		$member->public = 0;
		if ($application['birth'] !== '') {
			$member->birth = dol_mktime(12, 0, 0, (int) substr($application['birth'], 5, 2), (int) substr($application['birth'], 8, 2), (int) substr($application['birth'], 0, 4));
		}
		if ($application['country_code'] !== '') {
			$member->country_id = (int) dol_getIdFromCode($this->db, $application['country_code'], 'c_country', 'code', 'rowid');
		}
		if (!getDolGlobalString('ADHERENT_LOGIN_NOT_REQUIRED')) {
			$member->login = $application['email'];
		}
		$member->note_private = $application['note'];
		// The association's own fields, such as a gamer tag; only the ones the form asks for got this far (#216).
		require_once __DIR__.'/vereinememberform.class.php';
		$specs = VereineMemberForm::memberExtraFieldSpecs($this->db);
		foreach (isset($application['fields']) && is_array($application['fields']) ? $application['fields'] : array() as $code => $value) {
			$kind = isset($specs[$code]) ? $specs[$code]['kind'] : 'text';
			// A date field of Dolibarr holds a moment, a yes or no a number (#226).
			if ($kind === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $parts)) {
				$value = dol_mktime(12, 0, 0, (int) $parts[2], (int) $parts[3], (int) $parts[1]);
			} elseif ($kind === 'boolean') {
				$value = (int) $value;
			}
			$member->array_options['options_'.$code] = is_int($value) ? $value : (string) $value;
		}

		$this->db->begin();
		// Accounts at Discord, Twitch and the like go into Dolibarr's own field of the member (#233).
		if (!empty($application['accounts']) && is_array($application['accounts'])) {
			$member->socialnetworks = $application['accounts'];
		}
		if ($member->create($user) <= 0) {
			$this->error = 'member: '.$member->error.' '.implode(' | ', (array) $member->errors);
			$this->db->rollback();
			return null;
		}
		require_once __DIR__.'/vereineapplicationrules.class.php';
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_application (entity, external_id, fk_adherent, datec, fk_user, status, fingerprint)";
		$sql .= " VALUES (".((int) $conf->entity).", ".($application['external_id'] === '' ? "NULL" : "'".$this->db->escape($application['external_id'])."'").",";
		$sql .= " ".((int) $member->id).", '".$this->db->idate(dol_now())."', ".((int) $user->id).", '".VereineApplicationRules::RECEIVED."',";
		$sql .= " '".$this->db->escape(VereineApplicationRules::fingerprint($application))."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return null;
		}
		foreach ($application['consents'] as $code => $version) {
			$proof = is_array($version) && isset($version['proof']) ? $version['proof'] : array();
			$version = is_array($version) ? (int) $version['version'] : (int) $version;
			if ($this->record((int) $member->id, $code, $version, true, 'website', '', $user, $proof) < 0) {
				$this->db->rollback();
				return null;
			}
		}
		VereineLog::add($this->db, $user, VereineLog::APPLICATION_RECEIVED, (int) $member->id, 0, $application['external_id']);
		$this->db->commit();
		$member->fetch($member->id);
		// The document comes after the transaction: a PDF that fails must not lose the application (#111).
		$document = $this->applicationDocument($member, isset($application['signature']) ? (string) $application['signature'] : '', $user);
		return array('id' => (int) $member->id, 'ref' => (string) $member->ref, 'status' => VereineMemberSummary::status($member->statut),
			'application_status' => VereineApplicationRules::RECEIVED, 'duplicate' => false, 'document' => $document);
	}

	/**
	 * The member of an application already received.
	 *
	 * @param string $externalId Website's id of the application
	 * @return array{id:int,ref:string,status:string}|null
	 */
	private function applicationMember($externalId)
	{
		global $conf;

		$sql = "SELECT d.rowid, d.ref, d.statut, a.fingerprint, a.status as application_status FROM ".MAIN_DB_PREFIX."vereine_application as a";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent as d ON d.rowid = a.fk_adherent";
		$sql .= " WHERE a.entity = ".((int) $conf->entity)." AND a.external_id = '".$this->db->escape($externalId)."'";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'status' => VereineMemberSummary::status($obj->statut),
			'fingerprint' => (string) $obj->fingerprint, 'application_status' => (string) $obj->application_status) : null;
	}
}
