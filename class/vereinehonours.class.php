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
 * \file    class/vereinehonours.class.php
 * \ingroup vereine
 * \brief   Honours, jubilees, birthdays and member statistics (#27, #28).
 *
 * Jubilees and round birthdays are a list for the board before the general assembly; an honour is kept
 * once it was given, with a certificate to print and sign. Birthdays only of members who agreed to it.
 * An honorary membership moves the member to the member type the association chose for it, whose fee
 * model frees them from the fee. The statistics count members on a day, never name them.
 */

require_once __DIR__.'/vereinehonourrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Honours and member statistics.
 */
class VereineHonours
{
	/** Years of membership worth an honour. */
	const MILESTONES = 'VEREINE_HONOUR_MILESTONES';

	/** The purpose of consent that allows birthday wishes; empty for no birthday list. */
	const BIRTHDAY_CONSENT = 'VEREINE_HONOUR_BIRTHDAY_CONSENT';

	/** The member type of honorary members. */
	const HONORARY_TYPE = 'VEREINE_HONORARY_TYPE';

	/** Upper ends of the age groups of the statistics. */
	const AGES = 'VEREINE_STATISTICS_AGES';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * @var string[] Messages for the person
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
	 * The settings in force.
	 *
	 * @return array{milestones:int[],birthday_consent:string,honorary_type:int,ages:int[]}
	 */
	public static function settings()
	{
		$milestones = VereineHonourRules::numbers(getDolGlobalString(self::MILESTONES, VereineHonourRules::MILESTONES));
		$ages = VereineHonourRules::numbers(getDolGlobalString(self::AGES, VereineHonourRules::AGES));
		return array('milestones' => $milestones ? $milestones : VereineHonourRules::numbers(VereineHonourRules::MILESTONES),
			'birthday_consent' => getDolGlobalString(self::BIRTHDAY_CONSENT), 'honorary_type' => getDolGlobalInt(self::HONORARY_TYPE),
			'ages' => $ages ? $ages : VereineHonourRules::numbers(VereineHonourRules::AGES));
	}

	/**
	 * Keep the settings.
	 *
	 * @param array<string,mixed> $entered  milestones, birthday_consent, honorary_type, ages
	 * @param string[]            $consents Codes of the purposes of consent there are
	 * @param int[]               $types    Member types there are
	 * @param User                $user     Who
	 * @return int 1 when saved, 0 when refused (see errors), -1 on error
	 */
	public function saveSettings(array $entered, array $consents, array $types, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$this->errors = array();
		$milestones = VereineHonourRules::numbers(isset($entered['milestones']) ? $entered['milestones'] : '');
		$ages = VereineHonourRules::numbers(isset($entered['ages']) ? $entered['ages'] : '');
		$consent = isset($entered['birthday_consent']) ? (string) $entered['birthday_consent'] : '';
		$type = isset($entered['honorary_type']) ? (int) $entered['honorary_type'] : 0;
		if (!$milestones) {
			$this->errors[] = 'VereineHonourErrorMilestones';
		}
		if (!$ages) {
			$this->errors[] = 'VereineHonourErrorAges';
		}
		if ($consent !== '' && !in_array($consent, $consents, true)) {
			$this->errors[] = 'VereineHonourErrorConsent';
		}
		if ($type !== 0 && !in_array($type, $types, true)) {
			$this->errors[] = 'VereineHonourErrorType';
		}
		if ($this->errors) {
			return 0;
		}
		foreach (array(self::MILESTONES => implode(',', $milestones), self::AGES => implode(',', $ages), self::BIRTHDAY_CONSENT => $consent,
			self::HONORARY_TYPE => (string) $type) as $name => $value) {
			if (dolibarr_set_const($this->db, $name, $value, 'chaine', 0, '', $conf->entity) < 0) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		VereineLog::add($this->db, $user, VereineLog::HONOUR, 0, 0, 'settings');
		return 1;
	}

	/**
	 * Every member with what the lists and the statistics need.
	 *
	 * @return array<int,array{id:int,name:string,status:int,birth:string,gender:string,type_id:int,type:string,since:string,ended:string}>
	 */
	public function members()
	{
		$p = MAIN_DB_PREFIX;
		$sql = "SELECT a.rowid, a.firstname, a.lastname, a.statut, a.birth, a.gender, a.fk_adherent_type, t.libelle, a.datevalid, a.tms,";
		$sql .= " (SELECT MIN(s.dateadh) FROM ".$p."subscription as s WHERE s.fk_adherent = a.rowid) as first_period,";
		$sql .= " (SELECT MAX(e.last_day) FROM ".$p."vereine_member_exit as e WHERE e.fk_adherent = a.rowid AND e.status = 'done') as exit_day";
		$sql .= " FROM ".$p."adherent as a LEFT JOIN ".$p."adherent_type as t ON t.rowid = a.fk_adherent_type";
		$sql .= " WHERE a.entity IN (".getEntity('adherent').") ORDER BY a.lastname, a.firstname, a.rowid";
		$members = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$status = (int) $obj->statut;
			$dates = array_filter(array(substr((string) $obj->first_period, 0, 10), substr((string) $obj->datevalid, 0, 10)), 'strlen');
			$ended = '';
			if (in_array($status, array(0, -2), true)) {
				// Without an exit through the module, the last change is the day we know the membership had ended by.
				$ended = (string) $obj->exit_day !== '' ? substr((string) $obj->exit_day, 0, 10) : substr((string) $obj->tms, 0, 10);
			}
			$members[] = array('id' => (int) $obj->rowid, 'name' => trim($obj->firstname.' '.$obj->lastname), 'status' => $status,
				'birth' => substr((string) $obj->birth, 0, 10), 'gender' => (string) $obj->gender, 'type_id' => (int) $obj->fk_adherent_type,
				'type' => (string) $obj->libelle, 'since' => $status === -1 || !$dates ? '' : min($dates), 'ended' => $ended);
		}
		return $members;
	}

	/**
	 * The jubilees of a year: active members reaching a milestone, and whether the honour is kept already.
	 *
	 * @param int $year Year
	 * @return array<int,array{id:int,name:string,since:string,years:int,day:string,honoured:bool}> In the order of the day
	 */
	public function jubilees($year)
	{
		$settings = self::settings();
		$honoured = array();
		foreach ($this->honours() as $honour) {
			if ($honour['kind'] === 'jubilee') {
				$honoured[$honour['member_id'].'-'.$honour['years']] = true;
			}
		}
		$list = array();
		foreach ($this->members() as $member) {
			$jubilee = $member['status'] === 1 ? VereineHonourRules::jubilee($member['since'], (int) $year, $settings['milestones']) : null;
			if ($jubilee !== null) {
				$list[] = array('id' => $member['id'], 'name' => $member['name'], 'since' => $member['since'], 'years' => $jubilee['years'],
					'day' => $jubilee['day'], 'honoured' => isset($honoured[$member['id'].'-'.$jubilee['years']]));
			}
		}
		usort($list, function ($a, $b) {
			return strcmp($a['day'].$a['name'], $b['day'].$b['name']);
		});
		return $list;
	}

	/**
	 * The birthdays of a year, only of active members who agreed to birthday wishes.
	 *
	 * @param int $year Year
	 * @return array<int,array{id:int,name:string,day:string,age:int,round:bool}>|null Null when no purpose of consent is chosen
	 */
	public function birthdays($year)
	{
		$code = self::settings()['birthday_consent'];
		if ($code === '') {
			return null;
		}
		// The latest decision per member counts: given is given, withdrawn is withdrawn.
		$agreed = array();
		$sql = "SELECT c.fk_adherent, c.given FROM ".MAIN_DB_PREFIX."vereine_consent as c WHERE c.code = '".$this->db->escape($code)."'";
		$sql .= " AND c.rowid = (SELECT MAX(d.rowid) FROM ".MAIN_DB_PREFIX."vereine_consent as d WHERE d.fk_adherent = c.fk_adherent AND d.code = c.code)";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			if ((int) $obj->given === 1) {
				$agreed[(int) $obj->fk_adherent] = true;
			}
		}
		$list = array();
		foreach ($this->members() as $member) {
			$birthday = $member['status'] === 1 && isset($agreed[$member['id']]) ? VereineHonourRules::birthday($member['birth'], (int) $year) : null;
			if ($birthday !== null) {
				$list[] = array('id' => $member['id'], 'name' => $member['name'], 'day' => $birthday['day'], 'age' => $birthday['age'], 'round' => $birthday['round']);
			}
		}
		usort($list, function ($a, $b) {
			return strcmp($a['day'].$a['name'], $b['day'].$b['name']);
		});
		return $list;
	}

	/**
	 * Keep an honour that was given.
	 *
	 * @param int    $memberId Member
	 * @param string $kind     jubilee, honorary or award
	 * @param int    $years    Years of membership for a jubilee, else 0
	 * @param string $label    What for, for an award
	 * @param string $day      Day it was given or decided, YYYY-MM-DD
	 * @param User   $user     Who
	 * @return int Id when kept, 0 when refused (see errors), -1 on error
	 */
	public function record($memberId, $kind, $years, $label, $day, $user)
	{
		global $conf;

		$this->errors = array();
		$label = mb_substr(trim((string) $label), 0, 255, 'UTF-8');
		if ((int) $memberId < 1 || !in_array($kind, VereineHonourRules::KINDS, true)) {
			$this->errors[] = 'VereineHonourErrorKind';
		}
		if ($kind === 'award' && $label === '') {
			$this->errors[] = 'VereineHonourErrorLabel';
		}
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $day, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
			$this->errors[] = 'VereineHonourErrorDay';
		}
		if ($this->errors) {
			return 0;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_honour (entity, fk_adherent, kind, years, label, given_on, datec, fk_user) VALUES (".((int) $conf->entity).",";
		$sql .= " ".((int) $memberId).", '".$this->db->escape($kind)."', ".((int) $years).", '".$this->db->escape($label)."', '".$this->db->escape($day)."',";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_honour');
		VereineLog::add($this->db, $user, VereineLog::HONOUR, (int) $memberId, 0, $kind.($years > 0 ? ' '.$years : '').($label !== '' ? ': '.$label : ''));
		return $id;
	}

	/**
	 * Make a member an honorary member: the member type of honorary members and the honour kept.
	 *
	 * @param int    $memberId Member
	 * @param string $day      Day of the decision, YYYY-MM-DD
	 * @param User   $user     Who
	 * @return int Id of the honour, 0 when refused (see errors), -1 on error
	 */
	public function makeHonorary($memberId, $day, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';

		$type = self::settings()['honorary_type'];
		$member = new Adherent($this->db);
		$this->errors = array();
		if ($type < 1) {
			$this->errors[] = 'VereineHonourErrorNoType';
		} elseif ($member->fetch((int) $memberId) <= 0 || (int) $member->statut !== 1) {
			$this->errors[] = 'VereineHonourErrorMember';
		}
		if ($this->errors) {
			return 0;
		}
		$this->db->begin();
		if (!$this->db->query("UPDATE ".MAIN_DB_PREFIX."adherent SET fk_adherent_type = ".((int) $type)." WHERE rowid = ".((int) $member->id))) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$id = $this->record((int) $member->id, 'honorary', 0, '', $day, $user);
		if ($id <= 0) {
			$this->db->rollback();
			return $id;
		}
		$this->db->commit();
		// Whoever follows the member learns that the member type changed.
		$member->fetch((int) $member->id);
		$member->call_trigger('MEMBER_MODIFY', $user);
		return $id;
	}

	/**
	 * Honours kept, newest first.
	 *
	 * @param int $memberId Only this member's, 0 for all
	 * @return array<int,array{id:int,member_id:int,name:string,kind:string,years:int,label:string,given_on:string}>
	 */
	public function honours($memberId = 0)
	{
		global $conf;

		$sql = "SELECT h.rowid, h.fk_adherent, h.kind, h.years, h.label, h.given_on, a.firstname, a.lastname FROM ".MAIN_DB_PREFIX."vereine_honour as h";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."adherent as a ON a.rowid = h.fk_adherent WHERE h.entity = ".((int) $conf->entity);
		$sql .= ((int) $memberId > 0 ? " AND h.fk_adherent = ".((int) $memberId) : "")." ORDER BY h.given_on DESC, h.rowid DESC";
		$list = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$list[] = array('id' => (int) $obj->rowid, 'member_id' => (int) $obj->fk_adherent, 'name' => trim($obj->firstname.' '.$obj->lastname),
				'kind' => (string) $obj->kind, 'years' => (int) $obj->years, 'label' => (string) $obj->label, 'given_on' => substr((string) $obj->given_on, 0, 10));
		}
		return $list;
	}

	/**
	 * The certificate of an honour, to print and sign: a PDF in the temporary folder, to hand out and delete.
	 *
	 * @param int       $honourId    Honour
	 * @param Translate $outputlangs Language
	 * @return string Path, empty on error
	 */
	public function certificate($honourId, $outputlangs)
	{
		global $conf, $mysoc;

		require_once __DIR__.'/vereinepdf.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

		$honour = null;
		foreach ($this->honours() as $candidate) {
			if ($candidate['id'] === (int) $honourId) {
				$honour = $candidate;
			}
		}
		if ($honour === null) {
			$this->error = 'unknown honour';
			return '';
		}
		$outputlangs->loadLangs(array('main', 'members', 'vereine@vereine'));
		$pdf = VereinePdf::start($outputlangs);
		$font = pdf_getPDFFont($outputlangs);
		$pdf->Ln(30);
		$pdf->SetFont($font, 'B', 30);
		$pdf->MultiCell(0, 14, $outputlangs->transnoentitiesnoconv('VereineHonourCertificate'), 0, 'C');
		$pdf->Ln(8);
		$pdf->SetFont($font, '', 13);
		$pdf->MultiCell(0, 7, $outputlangs->transnoentitiesnoconv('VereineHonourCertificateIntro', (string) $mysoc->name), 0, 'C');
		$pdf->Ln(6);
		$pdf->SetFont($font, 'B', 22);
		$pdf->MultiCell(0, 11, $honour['name'], 0, 'C');
		$pdf->Ln(6);
		$pdf->SetFont($font, '', 13);
		if ($honour['kind'] === 'jubilee') {
			$reason = $outputlangs->transnoentitiesnoconv('VereineHonourCertificateJubilee', $honour['years']);
		} elseif ($honour['kind'] === 'honorary') {
			$reason = $outputlangs->transnoentitiesnoconv('VereineHonourCertificateHonorary');
		} else {
			$reason = $outputlangs->transnoentitiesnoconv('VereineHonourCertificateAward', $honour['label']);
		}
		$pdf->MultiCell(0, 7, $reason, 0, 'C');
		$pdf->Ln(24);
		VereinePdf::signatures($pdf, $outputlangs, array('VereineHonourSignChair', 'VereineHonourSignSecretary'), 'urkunde', false);
		VereinePdf::finish($pdf, $outputlangs, $outputlangs->transnoentitiesnoconv('VereineHonourCertificate'));
		$dir = DOL_DATA_ROOT.($conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/vereine/temp';
		if (dol_mkdir($dir) < 0) {
			$this->error = 'cannot create '.$dir;
			return '';
		}
		$file = $dir.'/urkunde-'.((int) $honour['id']).'-'.dol_sanitizeFileName($honour['name']).'.pdf';
		$pdf->Output($file, 'F');
		return is_file($file) ? $file : '';
	}

	/**
	 * Members on a day, counted by member type, gender, age group and category; never a name.
	 *
	 * @param string $day Day, YYYY-MM-DD
	 * @return array{total:int,types:array<string,int>,genders:array<string,int>,ages:array<int,int>,unknown_age:int,groups:array<int,array{from:int,to:int|null}>,categories:array<string,int>}
	 */
	public function statistics($day)
	{
		$groups = VereineHonourRules::ageGroups(self::settings()['ages']);
		$counts = array('total' => 0, 'types' => array(), 'genders' => array('woman' => 0, 'man' => 0, 'other' => 0, 'unknown' => 0),
			'ages' => array_fill(0, count($groups), 0), 'unknown_age' => 0, 'groups' => $groups, 'categories' => array());
		$counted = array();
		foreach ($this->members() as $member) {
			if (!VereineHonourRules::memberOn($member['since'], $member['ended'], $day)) {
				continue;
			}
			$counted[] = $member['id'];
			$counts['total']++;
			$type = $member['type'] !== '' ? $member['type'] : '?';
			$counts['types'][$type] = (isset($counts['types'][$type]) ? $counts['types'][$type] : 0) + 1;
			$gender = in_array($member['gender'], array('woman', 'man', 'other'), true) ? $member['gender'] : 'unknown';
			$counts['genders'][$gender]++;
			$group = VereineHonourRules::groupOf(VereineHonourRules::age($member['birth'], $day), $groups);
			if ($group < 0) {
				$counts['unknown_age']++;
			} else {
				$counts['ages'][$group]++;
			}
		}
		ksort($counts['types']);
		// Divisions are Dolibarr's categories of members.
		if ($counted) {
			$sql = "SELECT c.label, COUNT(*) as n FROM ".MAIN_DB_PREFIX."categorie_member as cm INNER JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = cm.fk_categorie";
			$sql .= " WHERE cm.fk_member IN (".implode(',', array_map('intval', $counted)).") GROUP BY c.label ORDER BY c.label";
			$resql = $this->db->query($sql);
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$counts['categories'][(string) $obj->label] = (int) $obj->n;
			}
		}
		return $counts;
	}
}
