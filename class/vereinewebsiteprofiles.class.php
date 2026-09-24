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
 * \file    class/vereinewebsiteprofiles.class.php
 * \ingroup vereine
 * \brief   The website profile of a member (#255): kept by the board, read by the website with consent.
 *
 * The board writes gamertag, bio, games and platforms on the tab Association; the photo is the member
 * photo of Dolibarr's own member card. The API hands the profile and the photo out only when the member
 * gave the consent the association chose in the setup of consents - without it the answer names the
 * consent and nothing else. A saved profile counts as a change of the member, so a website that follows
 * the change feed or the webhooks reads it again.
 */

require_once __DIR__.'/vereinewebsiteprofilerules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * The website profile of a member.
 */
class VereineWebsiteProfiles
{
	/** @var DoliDB */
	public $db;

	/** @var string Last error */
	public $error = '';

	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * The consent the association chose for the website profile; empty when none.
	 *
	 * @return string
	 */
	public static function consentCode()
	{
		return VereineWebsiteProfileRules::consentCode(getDolGlobalString(VereineWebsiteProfileRules::CONSENT));
	}

	/**
	 * Keep which consent opens the website profile.
	 *
	 * @param string $code Consent code, empty for none
	 * @param User   $user Who
	 * @return int 1 when saved, -1 on error
	 */
	public function saveConsentCode($code, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$code = VereineWebsiteProfileRules::consentCode($code);
		if (dolibarr_set_const($this->db, VereineWebsiteProfileRules::CONSENT, $code, 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::WEBSITE_PROFILE, 0, 0, 'consent: '.($code !== '' ? $code : '-'));
		return 1;
	}

	/**
	 * Whether the member gave the chosen consent: true, false, or null when none is chosen.
	 *
	 * @param int $memberId Member
	 * @return bool|null
	 */
	public function consentGiven($memberId)
	{
		$code = self::consentCode();
		if ($code === '') {
			return null;
		}
		dol_include_once('/vereine/class/vereineconsents.class.php');
		$consents = new VereineConsents($this->db);
		foreach ($consents->stateFor((int) $memberId) as $row) {
			if (isset($row['code']) && $row['code'] === $code) {
				return isset($row['state']) && $row['state'] === 'given';
			}
		}
		return false;
	}

	/**
	 * The stored profile of a member, every field a string, empty when nothing was kept.
	 *
	 * @param int $memberId Member
	 * @return array<string,string>
	 */
	public function load($memberId)
	{
		global $conf;

		$out = array();
		foreach (VereineWebsiteProfileRules::FIELDS as $field => $length) {
			$out[$field] = '';
		}
		$sql = "SELECT gamertag, bio, games, platforms FROM ".MAIN_DB_PREFIX."vereine_member_profile";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND fk_adherent = ".((int) $memberId);
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				foreach (array_keys($out) as $field) {
					$out[$field] = (string) $obj->$field;
				}
			}
			$this->db->free($resql);
		}
		return $out;
	}

	/**
	 * Keep the profile of a member and note the change for the website.
	 *
	 * @param Adherent            $member  Member
	 * @param array<string,mixed> $entered Field => value as entered
	 * @param User                $user    Who
	 * @return int 1 when saved, -1 on error
	 */
	public function save($member, array $entered, $user)
	{
		global $conf, $langs;

		$fields = VereineWebsiteProfileRules::normalize($entered);
		$memberId = (int) $member->id;
		$entity = (int) $conf->entity;
		$now = $this->db->idate(dol_now());
		$existing = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_member_profile WHERE entity = ".$entity." AND fk_adherent = ".$memberId);
		$row = $existing ? $this->db->fetch_object($existing) : null;
		if ($row) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_member_profile SET gamertag = '".$this->db->escape($fields['gamertag'])."'";
			$sql .= ", bio = '".$this->db->escape($fields['bio'])."', games = '".$this->db->escape($fields['games'])."'";
			$sql .= ", platforms = '".$this->db->escape($fields['platforms'])."', fk_user_modif = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $row->rowid);
		} else {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_member_profile (entity, fk_adherent, gamertag, bio, games, platforms, datec, fk_user_modif) VALUES (";
			$sql .= $entity.", ".$memberId.", '".$this->db->escape($fields['gamertag'])."', '".$this->db->escape($fields['bio'])."'";
			$sql .= ", '".$this->db->escape($fields['games'])."', '".$this->db->escape($fields['platforms'])."', '".$now."', ".((int) $user->id).")";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::WEBSITE_PROFILE, $memberId, 0, 'gamertag: '.($fields['gamertag'] !== '' ? $fields['gamertag'] : '-'));
		// The website follows the change feed and the webhooks: a new profile is a change of the member.
		dol_include_once('/vereine/class/vereinechanges.class.php');
		VereineChanges::record($this->db, VereineChangeRules::TYPE_MEMBERSHIP, $memberId, VereineChangeRules::KIND_UPDATED, $user);
		if (isModEnabled('webhook')) {
			dol_include_once('/vereine/class/vereinewebsiteevents.class.php');
			$events = new VereineWebsiteEvents($this->db);
			$events->notify('MEMBER_MODIFY', $member, $user, $langs, $conf);
		}
		return 1;
	}

	/**
	 * The photo of Dolibarr's member card, when there is one the website may show.
	 *
	 * @param Adherent $member Member
	 * @return array|null path, name, content_type
	 */
	public function photoFile($member)
	{
		global $conf;

		$name = isset($member->photo) ? (string) $member->photo : '';
		if ($name === '' || strpos($name, '/') !== false || strpos($name, '\\') !== false || strpos($name, '..') !== false) {
			return null;
		}
		$type = VereineWebsiteProfileRules::contentType($name);
		if ($type === null) {
			return null;
		}
		$path = $conf->adherent->dir_output.'/'.get_exdir(0, 0, 0, 0, $member, 'member').'photos/'.$name;
		if (!is_file($path)) {
			return null;
		}
		return array('path' => $path, 'name' => $name, 'content_type' => $type);
	}

	/**
	 * The profile as the API hands it out: with the consent everything, without it only its name.
	 *
	 * @param Adherent $member Member
	 * @return array<string,mixed>
	 */
	public function apiView($member)
	{
		$out = array('consent' => self::consentCode(), 'given' => $this->consentGiven((int) $member->id) === true);
		if (!$out['given']) {
			return $out;
		}
		$photo = $this->photoFile($member);
		$photoView = null;
		if ($photo) {
			$photoView = array(
				'sha256' => hash_file('sha256', $photo['path']), 'size' => (int) filesize($photo['path']),
				'content_type' => $photo['content_type'], 'updated_at' => gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($photo['path'])),
			);
		}
		return $out + VereineWebsiteProfileRules::view($this->load((int) $member->id), $photoView);
	}

	/**
	 * The photo as the API hands it out, base64 with its checksum - null without consent or photo.
	 *
	 * @param Adherent $member Member
	 * @return array|null filename, content_type, filesize, sha256, content
	 */
	public function apiPhoto($member)
	{
		if ($this->consentGiven((int) $member->id) !== true) {
			return null;
		}
		$photo = $this->photoFile($member);
		if (!$photo) {
			return null;
		}
		$bytes = (string) file_get_contents($photo['path']);
		return array(
			'filename' => $photo['name'], 'content_type' => $photo['content_type'], 'filesize' => strlen($bytes),
			'sha256' => hash('sha256', $bytes), 'content' => base64_encode($bytes),
		);
	}
}
