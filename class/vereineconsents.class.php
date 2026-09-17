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
	 * @param User     $user     User who records it
	 * @return int Id, <0 on error
	 */
	public function record($memberId, $code, $version, $given, $source, $note, $user)
	{
		global $conf;

		if (!VereineConsentRules::isCode($code) || !in_array($source, VereineConsentRules::SOURCES, true) || (int) $version <= 0) {
			$this->error = 'invalid consent';
			return -1;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_consent (entity, fk_adherent, code, version, given, source, date_event, note, fk_user)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $memberId).", '".$this->db->escape($code)."', ".((int) $version).", ".($given ? 1 : 0).",";
		$sql .= " '".$this->db->escape($source)."', '".$this->db->idate(dol_now())."', '".$this->db->escape(dol_trunc(trim((string) $note), 255, 'right', 'UTF-8', 1))."',";
		$sql .= " ".(is_object($user) && (int) $user->id > 0 ? (int) $user->id : "NULL").")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_consent');
		VereineLog::add($this->db, $user, $given ? VereineLog::CONSENT_GIVEN : VereineLog::CONSENT_WITHDRAWN, (int) $memberId, 0, $code.' v'.((int) $version).' / '.$source);
		return $id;
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

		$sql = "SELECT rowid, code, version, given, source, date_event, note FROM ".MAIN_DB_PREFIX."vereine_consent";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_adherent = ".((int) $memberId)." ORDER BY date_event DESC, rowid DESC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$events = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$events[] = array('id' => (int) $obj->rowid, 'code' => (string) $obj->code, 'version' => (int) $obj->version, 'given' => (int) $obj->given === 1,
				'source' => (string) $obj->source, 'date' => (string) $obj->date_event, 'moment' => (int) $this->db->jdate($obj->date_event), 'note' => (string) $obj->note);
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
	 * @return array{id:int,ref:string,status:string,duplicate:bool}|null Null on error, see $error
	 */
	public function createApplication(array $application, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';

		if ($application['external_id'] !== '') {
			$existing = $this->applicationMember($application['external_id']);
			if ($existing !== null) {
				return $existing + array('duplicate' => true);
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

		$this->db->begin();
		if ($member->create($user) <= 0) {
			$this->error = 'member: '.$member->error.' '.implode(' | ', (array) $member->errors);
			$this->db->rollback();
			return null;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_application (entity, external_id, fk_adherent, datec, fk_user)";
		$sql .= " VALUES (".((int) $conf->entity).", ".($application['external_id'] === '' ? "NULL" : "'".$this->db->escape($application['external_id'])."'").",";
		$sql .= " ".((int) $member->id).", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return null;
		}
		foreach ($application['consents'] as $code => $version) {
			if ($this->record((int) $member->id, $code, $version, true, 'website', '', $user) < 0) {
				$this->db->rollback();
				return null;
			}
		}
		VereineLog::add($this->db, $user, VereineLog::APPLICATION_RECEIVED, (int) $member->id, 0, $application['external_id']);
		$this->db->commit();
		$member->fetch($member->id);
		return array('id' => (int) $member->id, 'ref' => (string) $member->ref, 'status' => VereineMemberSummary::status($member->statut), 'duplicate' => false);
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

		$sql = "SELECT d.rowid, d.ref, d.statut FROM ".MAIN_DB_PREFIX."vereine_application as a";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent as d ON d.rowid = a.fk_adherent";
		$sql .= " WHERE a.entity = ".((int) $conf->entity)." AND a.external_id = '".$this->db->escape($externalId)."'";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'status' => VereineMemberSummary::status($obj->statut)) : null;
	}
}
