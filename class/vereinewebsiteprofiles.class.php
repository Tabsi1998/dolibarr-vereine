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
 * \brief   The website profile of a member (#255, #260): fields the association chooses, read by the website with consent.
 *
 * The fields are Dolibarr's own additional fields of the member: the association creates them (under Members or
 * right in the setup of consents), chooses which of them make up the profile and which of them the member keeps
 * in the web portal or an app; the board keeps the others on the member card. The photo is the member photo of
 * Dolibarr's member card. The API hands the profile and the photo out only when the member gave the consent the
 * association chose - without it the answer names the consent and nothing else. A change by the member counts as
 * a change of the member, so a website that follows the change feed or the webhooks reads it again.
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
	 * @var array<int,array{field:string,message:string}> Why a change was refused, each with the code of its field
	 */
	public $errors = array();

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
	 * The fields of the member a profile can hold: Dolibarr's additional fields of the member a form can show.
	 *
	 * @return array<string,array<string,mixed>> Code => label, kind, options, max, integer, pos
	 */
	public function available()
	{
		dol_include_once('/vereine/class/vereinememberform.class.php');
		return VereineMemberForm::memberExtraFieldSpecs($this->db);
	}

	/**
	 * The fields of the profile, each with its label and options in the language of the user and whether the
	 * member may change it.
	 *
	 * @return array<string,array<string,mixed>> Code => label, kind, options, max, integer, editable
	 */
	public function fields()
	{
		global $langs;

		$specs = $this->available();
		$fields = array();
		foreach (VereineWebsiteProfileRules::fields(getDolGlobalString(VereineWebsiteProfileRules::FIELDS), array_keys($specs)) as $code => $editable) {
			$spec = $specs[$code];
			$spec['label'] = (string) $langs->transnoentitiesnoconv($spec['label']);
			foreach ($spec['options'] as $option => $text) {
				$spec['options'][$option] = (string) $langs->transnoentitiesnoconv($text);
			}
			$spec['editable'] = $editable;
			$fields[$code] = $spec;
		}
		return $fields;
	}

	/**
	 * Keep which fields make up the profile and which of them the member may change.
	 *
	 * @param mixed $chosen Codes of the fields in the profile
	 * @param mixed $self   Codes the member may change
	 * @param User  $user   Who
	 * @return int 1 when saved, -1 on error
	 */
	public function saveFields($chosen, $self, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$setting = VereineWebsiteProfileRules::setting($chosen, $self, array_keys($this->available()));
		if (dolibarr_set_const($this->db, VereineWebsiteProfileRules::FIELDS, $setting, 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$decoded = $setting !== '' ? json_decode($setting, true) : array();
		VereineLog::add($this->db, $user, VereineLog::WEBSITE_PROFILE, 0, 0, 'fields: '.($decoded ? implode(', ', array_keys($decoded)) : '-'));
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
	 * The fields of the profile with the values of a member, as the API hands them out.
	 *
	 * @param Adherent                          $member Member
	 * @param array<string,array<string,mixed>> $fields Fields of the profile, see fields()
	 * @return array<int,array<string,mixed>>
	 */
	public function values($member, array $fields)
	{
		if ($fields && empty($member->array_options)) {
			$member->fetch_optionals();
		}
		$values = array();
		foreach ($fields as $code => $spec) {
			$raw = isset($member->array_options['options_'.$code]) ? $member->array_options['options_'.$code] : null;
			$values[] = VereineWebsiteProfileRules::field($code, $spec, $raw);
		}
		return $values;
	}

	/**
	 * Keep a change the member makes to the own profile: only the fields sent change; a field outside the
	 * profile, one the association keeps or a value that does not fit is refused with its code (#260).
	 *
	 * @param Adherent $member Member
	 * @param mixed    $sent   Code => value as sent
	 * @param User     $user   Who acts
	 * @return int 1 when kept, 0 when refused (see errors), -1 on error
	 */
	public function change($member, $sent, $user)
	{
		global $conf, $langs;

		$this->errors = array();
		$fields = $this->fields();
		$checked = VereineWebsiteProfileRules::change($sent, $fields);
		if ($checked['errors']) {
			$this->errors = $checked['errors'];
			return 0;
		}
		if (!$checked['values']) {
			return 1;
		}
		if (empty($member->array_options)) {
			$member->fetch_optionals();
		}
		$this->db->begin();
		foreach ($checked['values'] as $code => $value) {
			// A day of Dolibarr's fields is a moment, a yes or no a number.
			if ($value !== null && $fields[$code]['kind'] === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
				$value = dol_mktime(12, 0, 0, (int) $parts[2], (int) $parts[3], (int) $parts[1]);
			} elseif ($value !== null && $fields[$code]['kind'] === 'boolean') {
				$value = (int) $value;
			}
			// Dolibarr leaves a field set to null as it was; an empty value empties it.
			$member->array_options['options_'.$code] = $value === null ? '' : $value;
			$member->errors = array();
			if ($member->updateExtraField($code, '', $user) < 0) {
				$this->db->rollback();
				// Dolibarr refuses to empty a field it requires: that is the member's to fix, not an error.
				if ($member->errors) {
					$this->errors = array(array('field' => $code, 'message' => 'fields.'.$code.': '.implode(' ', (array) $member->errors)));
					return 0;
				}
				$this->error = (string) $member->error;
				return -1;
			}
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::WEBSITE_PROFILE, (int) $member->id, 0, 'fields: '.implode(', ', array_keys($checked['values'])));
		// The website follows the change feed and the webhooks: a new profile is a change of the member.
		dol_include_once('/vereine/class/vereinechanges.class.php');
		VereineChanges::record($this->db, VereineChangeRules::TYPE_MEMBERSHIP, (int) $member->id, VereineChangeRules::KIND_UPDATED, $user);
		if (isModEnabled('webhook')) {
			dol_include_once('/vereine/class/vereinewebsiteevents.class.php');
			$events = new VereineWebsiteEvents($this->db);
			$events->notify('MEMBER_MODIFY', $member, $user, $langs, $conf);
		}
		return 1;
	}

	/**
	 * Take the profiles of 1.1.0 over into fields of the member, once (#260).
	 *
	 * Each old field that holds something becomes a field of the member with the same code - gamertag, bio, games,
	 * platforms - and joins the profile, kept by the board as before. A member who already has a value in a field
	 * of that code keeps it. Then the old table goes: the values live on the member, where Dolibarr deletes them
	 * together with the member.
	 *
	 * @param User $user Who enables the module
	 * @return int Members taken over, -1 on error
	 */
	public function migrate($user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		if (getDolGlobalString(VereineWebsiteProfileRules::MIGRATED) !== '') {
			return 0;
		}
		$table = MAIN_DB_PREFIX.'vereine_member_profile';
		$tables = $this->db->DDLListTables($conf->db->name, $table);
		if (!is_array($tables) || !in_array($table, $tables, true)) {
			return 0;
		}
		$entity = (int) $conf->entity;
		$used = array();
		foreach (array_keys(VereineWebsiteProfileRules::OLD_FIELDS) as $old) {
			$resql = $this->db->query("SELECT COUNT(*) as nb FROM ".$table." WHERE entity = ".$entity." AND ".$old." IS NOT NULL AND ".$old." <> ''");
			$obj = $resql ? $this->db->fetch_object($resql) : null;
			if ($obj && (int) $obj->nb > 0) {
				$used[] = $old;
			}
		}

		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('adherent');
		$attributes = isset($extrafields->attributes['adherent']) ? $extrafields->attributes['adherent'] : array();
		$existing = array();
		$position = 10;
		foreach (isset($attributes['label']) && is_array($attributes['label']) ? $attributes['label'] : array() as $code => $label) {
			$existing[(string) $code] = VereineApplicationFormRules::kindOf(isset($attributes['type'][$code]) ? (string) $attributes['type'][$code] : '');
			$position = max($position, (isset($attributes['pos'][$code]) ? (int) $attributes['pos'][$code] : 0) + 10);
		}
		$plan = VereineWebsiteProfileRules::migration($used, $existing);
		foreach ($plan as $step) {
			if (!$step['create']) {
				continue;
			}
			$type = VereineApplicationFormRules::DOLIBARR_TYPES[$step['kind']];
			if ($extrafields->addExtraField($step['code'], $step['label'], $type[0], $position, $type[1], 'adherent', 0, 0, '', '', 1, '', '1', '', '', '', '', '1', 0, 1) <= 0) {
				$this->error = 'field '.$step['code'].': '.$extrafields->error;
				return -1;
			}
			$position += 10;
		}

		$specs = $this->available();
		$members = 0;
		$this->db->begin();
		$sql = "SELECT p.fk_adherent, p.gamertag, p.bio, p.games, p.platforms FROM ".$table." as p";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."adherent as a ON a.rowid = p.fk_adherent WHERE p.entity = ".$entity;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		foreach ($rows as $obj) {
			$memberId = (int) $obj->fk_adherent;
			$found = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."adherent_extrafields WHERE fk_object = ".$memberId);
			if ($found && !$this->db->fetch_object($found) && !$this->db->query("INSERT INTO ".MAIN_DB_PREFIX."adherent_extrafields (fk_object) VALUES (".$memberId.")")) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
			$taken = false;
			foreach ($plan as $old => $step) {
				$value = trim((string) $obj->$old);
				if ($value === '') {
					continue;
				}
				// Cut to the field it goes to, as a field the association made may be shorter than the old one.
				$max = $step['kind'] === 'textarea' ? VereineApplicationFormRules::TEXTAREA_MAX : VereineApplicationFormRules::EXTRA_MAX;
				if (!empty($specs[$step['code']]['max']) && (int) $specs[$step['code']]['max'] < $max) {
					$max = (int) $specs[$step['code']]['max'];
				}
				$column = $this->db->sanitize($step['code']);
				$sql = "UPDATE ".MAIN_DB_PREFIX."adherent_extrafields SET ".$column." = '".$this->db->escape(dol_substr($value, 0, $max, 'UTF-8'))."'";
				$sql .= " WHERE fk_object = ".$memberId." AND (".$column." IS NULL OR ".$column." = '')";
				if (!$this->db->query($sql)) {
					$this->error = $this->db->lasterror();
					$this->db->rollback();
					return -1;
				}
				$taken = true;
			}
			$members += $taken ? 1 : 0;
		}
		$chosen = VereineWebsiteProfileRules::fields(getDolGlobalString(VereineWebsiteProfileRules::FIELDS), array_keys($specs));
		foreach ($plan as $step) {
			if (!isset($chosen[$step['code']])) {
				$chosen[$step['code']] = false;
			}
		}
		$setting = VereineWebsiteProfileRules::setting(array_keys($chosen), array_keys(array_filter($chosen)), array_keys($specs));
		if (dolibarr_set_const($this->db, VereineWebsiteProfileRules::FIELDS, $setting, 'chaine', 0, '', $entity) < 0
			|| dolibarr_set_const($this->db, VereineWebsiteProfileRules::MIGRATED, dol_print_date(dol_now(), 'dayhourrfc', 'gmt'), 'chaine', 0, '', $entity) < 0
			|| !$this->db->query("DELETE FROM ".$table." WHERE entity = ".$entity)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::WEBSITE_PROFILE, 0, 0, 'taken over from 1.1.0: '.$members.' members, fields: '
			.($plan ? implode(', ', array_map(function ($step) {
				return $step['code'];
			}, $plan)) : '-'));
		// The last entity takes the empty table away.
		$left = $this->db->query("SELECT COUNT(*) as nb FROM ".$table);
		$obj = $left ? $this->db->fetch_object($left) : null;
		if ($obj && (int) $obj->nb === 0) {
			$this->db->query("DROP TABLE ".$table);
		}
		return $members;
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
	 * The profile as the API hands it out: with the consent the fields and the photo, without it only its name.
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
		$out['fields'] = $this->values($member, $this->fields());
		$out['photo'] = VereineWebsiteProfileRules::photo($photo ? array(
			'sha256' => hash_file('sha256', $photo['path']), 'size' => (int) filesize($photo['path']),
			'content_type' => $photo['content_type'], 'updated_at' => gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($photo['path'])),
		) : null);
		return $out;
	}

	/**
	 * The profile as the member sees it: every field with its value, also without the consent, and whether
	 * the consent is given (#260).
	 *
	 * @param Adherent $member Member
	 * @return array{consent:string,given:bool,fields:array<int,array<string,mixed>>}
	 */
	public function ownView($member)
	{
		return array('consent' => self::consentCode(), 'given' => $this->consentGiven((int) $member->id) === true,
			'fields' => $this->values($member, $this->fields()));
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
