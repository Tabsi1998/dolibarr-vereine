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
 * \file    class/vereineerasure.class.php
 * \ingroup vereine
 * \brief   Erasing a former member's data (#10): a preview kind by kind, then only what is due.
 *
 * Nothing goes without a person pressing the button after looking at the preview. What is done is kept as
 * a run with counts per kind, never with the data themselves; a second run does only what became due
 * since. Bookkeeping and club records are never touched: invoices, payments and Dolibarr's own donations
 * stay as they are, and so do minutes, resolutions, signatures and terms of office.
 */

require_once __DIR__.'/vereineerasurerules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Erasing a former member's data.
 */
class VereineErasure
{
	/** Periods the association chose, as JSON kind => years. */
	const SETTING = 'VEREINE_ERASURE_PERIODS';

	/** The name a member keeps once it is gone. */
	const ANONYMOUS = 'Anonymisiert';

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
	 * The periods in force.
	 *
	 * @return array<string,int|null>
	 */
	public static function periods()
	{
		$chosen = json_decode(getDolGlobalString(self::SETTING), true);
		return VereineErasureRules::periods(is_array($chosen) ? $chosen : array());
	}

	/**
	 * Keep the periods the association chose.
	 *
	 * @param array<string,mixed> $entered Kind => years
	 * @param User                $user    Who saves
	 * @return int 1 when saved, 0 when refused (see errors), -1 on error
	 */
	public function savePeriods(array $entered, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$checked = VereineErasureRules::checkPeriods($entered);
		$this->errors = $checked['errors'] ? array('VereineErasureErrorYears') : array();
		if ($this->errors) {
			return 0;
		}
		if (dolibarr_set_const($this->db, self::SETTING, (string) json_encode($checked['periods']), 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::ERASURE_SETUP, 0, 0, (string) json_encode($checked['periods']));
		return 1;
	}

	/**
	 * The day the membership ended: the last day of an exit carried out, else the last change of a member
	 * who is no longer one. Empty while somebody is a member.
	 *
	 * @param Adherent $member Member
	 * @return string YYYY-MM-DD or empty
	 */
	public function exitDay($member)
	{
		global $conf;

		if (!in_array((int) $member->statut, array(Adherent::STATUS_RESILIATED, Adherent::STATUS_EXCLUDED), true)) {
			return '';
		}
		$day = $this->value("SELECT MAX(last_day) as v FROM ".MAIN_DB_PREFIX."vereine_member_exit WHERE entity = ".((int) $conf->entity)
			." AND fk_adherent = ".((int) $member->id)." AND status = 'done'");
		if ($day !== '') {
			return substr($day, 0, 10);
		}
		// Without an exit through the module, the latest change is the safe side: it can only make periods longer.
		$changed = !empty($member->datem) ? $member->datem : $member->datec;
		return !empty($changed) ? dol_print_date($changed, '%Y-%m-%d', 'tzserver') : dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
	}

	/**
	 * The preview: what is there kind by kind, and what happens to it when.
	 *
	 * @param Adherent $member Member
	 * @param string   $today  Today, YYYY-MM-DD
	 * @return array{exit:string,plan:array<string,array<string,mixed>>,hold:array{on:bool,note:string},hints:array<string,int>}
	 */
	public function preview($member, $today)
	{
		$periods = self::periods();
		$exitDay = $this->exitDay($member);
		$hold = $this->hold((int) $member->id);
		$holds = array('hold' => $hold['on'], 'open_invoices' => $this->openInvoices($member) > 0,
			'functions' => $this->count("SELECT COUNT(*) as v FROM ".MAIN_DB_PREFIX."vereine_function_term WHERE fk_adherent = ".((int) $member->id)) > 0);
		$plan = VereineErasureRules::plan($this->found($member, $today, $periods), $exitDay, $today, $periods, $holds);
		return array('exit' => $exitDay, 'plan' => $plan, 'hold' => $hold, 'hints' => $this->hints($member));
	}

	/**
	 * What is there of a member, kind by kind: rows, the last entry's day, rows whose own period is over.
	 *
	 * @param Adherent               $member  Member
	 * @param string                 $today   Today, YYYY-MM-DD
	 * @param array<string,int|null> $periods Periods in force
	 * @return array<string,array{count:int,last:string,due:int}>
	 */
	public function found($member, $today, array $periods)
	{
		global $conf;

		$id = (int) $member->id;
		$entity = (int) $conf->entity;
		$p = MAIN_DB_PREFIX;
		$found = array();
		$found['identities'] = array('count' => $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_identity WHERE entity = ".$entity." AND fk_adherent = ".$id)
			+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_identity_invite WHERE entity = ".$entity." AND fk_adherent = ".$id)
			+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_social WHERE entity = ".$entity." AND fk_adherent = ".$id), 'last' => '', 'due' => 0);
		$contact = 0;
		foreach (array('societe', 'address', 'zip', 'town', 'email', 'url', 'phone', 'phone_perso', 'phone_mobile', 'birth', 'photo', 'note_public', 'note_private') as $field) {
			$contact += !empty($member->$field) ? 1 : 0;
		}
		$contact += !empty($member->socialnetworks) ? 1 : 0;
		$contact += $this->count("SELECT COUNT(*) as v FROM ".$p."adherent_extrafields WHERE fk_object = ".$id);
		$found['contact'] = array('count' => $contact, 'last' => '', 'due' => 0);
		$cut = $this->cutoff($today, $periods['invitations']);
		$found['invitations'] = array(
			'count' => $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_meeting_invitation WHERE entity = ".$entity." AND fk_adherent = ".$id." AND email IS NOT NULL AND email <> ''")
				+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_circular_vote WHERE entity = ".$entity." AND fk_adherent = ".$id." AND email IS NOT NULL AND email <> ''"),
			'last' => $this->day("SELECT MAX(m.meeting_day) as v FROM ".$p."vereine_meeting_invitation as i INNER JOIN ".$p."vereine_meeting as m ON m.rowid = i.fk_meeting WHERE i.entity = ".$entity." AND i.fk_adherent = ".$id." AND i.email IS NOT NULL AND i.email <> ''", "SELECT MAX(datec) as v FROM ".$p."vereine_circular_vote WHERE entity = ".$entity." AND fk_adherent = ".$id." AND email IS NOT NULL AND email <> ''"),
			'due' => $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_meeting_invitation as i INNER JOIN ".$p."vereine_meeting as m ON m.rowid = i.fk_meeting WHERE i.entity = ".$entity." AND i.fk_adherent = ".$id." AND i.email IS NOT NULL AND i.email <> '' AND m.meeting_day <= '".$this->db->escape($cut)."'")
				+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_circular_vote WHERE entity = ".$entity." AND fk_adherent = ".$id
				." AND email IS NOT NULL AND email <> '' AND datec <= '".$this->db->escape($cut)." 23:59:59'")
				+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_meeting_response as r INNER JOIN ".$p."vereine_meeting as m ON m.rowid = r.fk_meeting WHERE r.entity = ".$entity
				." AND r.fk_adherent = ".$id." AND m.meeting_day <= '".$this->db->escape($cut)."'"));
		$found['tasks'] = array('count' => $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_resolution_task WHERE entity = ".$entity." AND fk_adherent = ".$id)
			+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_duty_task WHERE entity = ".$entity." AND fk_adherent = ".$id)
			+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_event_shift_entry WHERE entity = ".$entity." AND fk_adherent = ".$id), 'last' => '', 'due' => 0);
		$found['consents'] = array('count' => $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_consent WHERE entity = ".$entity." AND fk_adherent = ".$id), 'last' => '', 'due' => 0);
		$found['applications'] = array('count' => $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_application WHERE entity = ".$entity." AND fk_adherent = ".$id)
			+ count($this->files($member, '/^mitgliedsantrag-.*\.pdf$/')), 'last' => '', 'due' => 0);
		// Equipment that came back; what is still out is a claim of the association and stays.
		$found['loans'] = array('count' => $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_loan WHERE entity = ".$entity." AND fk_adherent = ".$id." AND returned_on IS NOT NULL"),
			'last' => '', 'due' => 0);
		$found['arrears'] = array('count' => $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_arrear WHERE entity = ".$entity." AND fk_adherent = ".$id), 'last' => '', 'due' => 0);
		$cut = $this->cutoff($today, $periods['disclosures']);
		$found['disclosures'] = array('count' => $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_disclosure WHERE entity = ".$entity." AND fk_adherent = ".$id),
			'last' => $this->day("SELECT MAX(datec) as v FROM ".$p."vereine_disclosure WHERE entity = ".$entity." AND fk_adherent = ".$id),
			'due' => $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_disclosure WHERE entity = ".$entity." AND fk_adherent = ".$id." AND datec <= '".$this->db->escape($cut)." 23:59:59'"));
		$found['log'] = array('count' => $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_log WHERE entity = ".$entity." AND fk_adherent = ".$id
			." AND action NOT IN ('".VereineLog::ERASURE."', '".VereineLog::ERASURE_HOLD."')")
			+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_profile_request WHERE entity = ".$entity." AND fk_adherent = ".$id), 'last' => '', 'due' => 0);
		$found['volunteer'] = array('count' => $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_volunteer WHERE entity = ".$entity." AND fk_adherent = ".$id),
			'last' => $this->day("SELECT MAX(duty_day) as v FROM ".$p."vereine_volunteer WHERE entity = ".$entity." AND fk_adherent = ".$id), 'due' => 0);
		$found['donations'] = array('count' => $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_donor WHERE entity = ".$entity." AND fk_adherent = ".$id),
			'last' => $this->day("SELECT MAX(d.datedon) as v FROM ".$p."don as d INNER JOIN ".$p."vereine_donation_gift as g ON g.fk_don = d.rowid INNER JOIN ".$p."vereine_donor as o ON o.rowid = g.fk_donor WHERE o.entity = ".$entity." AND o.fk_adherent = ".$id,
				"SELECT MAX(datec) as v FROM ".$p."vereine_donor WHERE entity = ".$entity." AND fk_adherent = ".$id), 'due' => 0);
		$soc = (int) $member->fk_soc;
		$found['bookkeeping'] = array('count' => $this->count("SELECT COUNT(*) as v FROM ".$p."subscription WHERE fk_adherent = ".$id)
			+ ($soc > 0 ? $this->count("SELECT COUNT(*) as v FROM ".$p."facture WHERE fk_soc = ".$soc." AND fk_statut > 0 AND entity IN (".getEntity('invoice').")") : 0),
			'last' => $this->day("SELECT MAX(dateadh) as v FROM ".$p."subscription WHERE fk_adherent = ".$id,
				$soc > 0 ? "SELECT MAX(datef) as v FROM ".$p."facture WHERE fk_soc = ".$soc." AND fk_statut > 0 AND entity IN (".getEntity('invoice').")" : ''), 'due' => 0);
		$found['records'] = array('count' => $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_function_term WHERE fk_adherent = ".$id)
			+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_signature_person WHERE fk_adherent = ".$id)
			+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_meeting_attendance WHERE entity = ".$entity." AND fk_adherent = ".$id)
			+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_circular_vote WHERE entity = ".$entity." AND fk_adherent = ".$id)
			+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_honour WHERE entity = ".$entity." AND fk_adherent = ".$id)
			+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_publication WHERE entity = ".$entity." AND fk_adherent = ".$id)
			+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_ballot_right WHERE entity = ".$entity." AND fk_adherent = ".$id)
			+ $this->count("SELECT COUNT(*) as v FROM ".$p."vereine_motion WHERE entity = ".$entity." AND fk_adherent = ".$id), 'last' => '', 'due' => 0);
		$found['name'] = array('count' => (string) $member->lastname === self::ANONYMOUS && (string) $member->firstname === '' && (string) $member->login === '' ? 0 : 1,
			'last' => '', 'due' => 0);
		return $found;
	}

	/**
	 * Carry out what is due now. Only kinds the preview shows as due are touched.
	 *
	 * @param Adherent $member Member
	 * @param string   $today  Today, YYYY-MM-DD
	 * @param User     $user   Who presses the button
	 * @return array<string,int>|null Kind => rows changed, empty when nothing was due, null on error
	 */
	public function carryOut($member, $today, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$preview = $this->preview($member, $today);
		$due = VereineErasureRules::due($preview['plan']);
		if (!$due) {
			return array();
		}
		$periods = self::periods();
		$id = (int) $member->id;
		$entity = (int) $conf->entity;
		$p = MAIN_DB_PREFIX;
		$done = array();
		$files = array();
		$this->db->begin();
		foreach ($due as $kind) {
			$changed = 0;
			if ($kind === 'identities') {
				require_once __DIR__.'/vereineaccess.class.php';
				$access = new VereineAccess($this->db);
				// Take back what is still bound first, so a client sees the same revocation as by hand.
				foreach ($this->column("SELECT rowid as v FROM ".$p."vereine_identity WHERE entity = ".$entity." AND fk_adherent = ".$id." AND revoked_at IS NULL") as $binding) {
					if ($access->revoke((int) $binding, $user) < 0) {
						return $this->fail($access->error);
					}
				}
				$changed = $this->change("DELETE FROM ".$p."vereine_identity WHERE entity = ".$entity." AND fk_adherent = ".$id)
					+ $this->change("DELETE FROM ".$p."vereine_identity_invite WHERE entity = ".$entity." AND fk_adherent = ".$id)
					+ $this->change("DELETE FROM ".$p."vereine_social WHERE entity = ".$entity." AND fk_adherent = ".$id);
			} elseif ($kind === 'contact') {
				$changed = $this->change("UPDATE ".$p."adherent SET societe = NULL, address = NULL, zip = NULL, town = NULL, state_id = NULL, email = NULL, url = NULL, socialnetworks = NULL, phone = NULL, phone_perso = NULL, phone_mobile = NULL, birth = NULL, photo = NULL, note_public = NULL, note_private = NULL, gender = NULL WHERE rowid = ".$id) < 0 ? -1 : $preview['plan']['contact']['count'];
				if ($changed >= 0 && $member->deleteExtraFields() < 0) {
					return $this->fail($member->error);
				}
				$files[] = $this->folder($member).'/photos';
			} elseif ($kind === 'invitations') {
				$cut = $this->db->escape($this->cutoff($today, $periods['invitations']));
				$changed = $this->change("UPDATE ".$p."vereine_meeting_invitation SET email = NULL, error = NULL WHERE entity = ".$entity." AND fk_adherent = ".$id
					." AND email IS NOT NULL AND email <> '' AND fk_meeting IN (SELECT rowid FROM ".$p."vereine_meeting WHERE meeting_day <= '".$cut."')")
					+ $this->change("UPDATE ".$p."vereine_circular_vote SET email = NULL WHERE entity = ".$entity." AND fk_adherent = ".$id
					." AND email IS NOT NULL AND email <> '' AND datec <= '".$cut." 23:59:59'")
					+ $this->change("DELETE FROM ".$p."vereine_meeting_response WHERE entity = ".$entity." AND fk_adherent = ".$id
					." AND fk_meeting IN (SELECT rowid FROM ".$p."vereine_meeting WHERE meeting_day <= '".$cut."')");
			} elseif ($kind === 'tasks') {
				$changed = $this->change("UPDATE ".$p."vereine_resolution_task SET fk_adherent = 0 WHERE entity = ".$entity." AND fk_adherent = ".$id)
					+ $this->change("UPDATE ".$p."vereine_duty_task SET fk_adherent = 0 WHERE entity = ".$entity." AND fk_adherent = ".$id)
					+ $this->change("UPDATE ".$p."vereine_event_shift_entry SET fk_adherent = 0, note = NULL WHERE entity = ".$entity." AND fk_adherent = ".$id);
			} elseif ($kind === 'consents') {
				foreach ($this->column("SELECT scan_name as v FROM ".$p."vereine_consent WHERE entity = ".$entity." AND fk_adherent = ".$id." AND scan_name IS NOT NULL AND scan_name <> ''") as $scan) {
					$files[] = $this->folder($member).'/'.basename((string) $scan);
				}
				$changed = $this->change("DELETE FROM ".$p."vereine_consent WHERE entity = ".$entity." AND fk_adherent = ".$id);
			} elseif ($kind === 'applications') {
				$pdfs = $this->files($member, '/^mitgliedsantrag-.*\.pdf$/');
				$files = array_merge($files, $pdfs);
				$changed = $this->change("DELETE FROM ".$p."vereine_application WHERE entity = ".$entity." AND fk_adherent = ".$id) + count($pdfs);
			} elseif ($kind === 'loans') {
				$changed = $this->change("DELETE FROM ".$p."vereine_loan WHERE entity = ".$entity." AND fk_adherent = ".$id." AND returned_on IS NOT NULL");
			} elseif ($kind === 'arrears') {
				$changed = $this->change("DELETE FROM ".$p."vereine_arrear WHERE entity = ".$entity." AND fk_adherent = ".$id);
			} elseif ($kind === 'disclosures') {
				$changed = $this->change("DELETE FROM ".$p."vereine_disclosure WHERE entity = ".$entity." AND fk_adherent = ".$id
					." AND datec <= '".$this->db->escape($this->cutoff($today, $periods['disclosures']))." 23:59:59'");
			} elseif ($kind === 'log') {
				$changed = $this->change("DELETE FROM ".$p."vereine_log WHERE entity = ".$entity." AND fk_adherent = ".$id
					." AND action NOT IN ('".VereineLog::ERASURE."', '".VereineLog::ERASURE_HOLD."')")
					+ $this->change("DELETE FROM ".$p."vereine_profile_request WHERE entity = ".$entity." AND fk_adherent = ".$id);
			} elseif ($kind === 'volunteer') {
				$changed = $this->change("DELETE FROM ".$p."vereine_volunteer WHERE entity = ".$entity." AND fk_adherent = ".$id);
			} elseif ($kind === 'donations') {
				// The lines of reports sent stay: they carry a reference number only and belong to the report.
				$this->change("DELETE FROM ".$p."vereine_donation_gift WHERE fk_donor IN (SELECT rowid FROM ".$p."vereine_donor WHERE entity = ".$entity." AND fk_adherent = ".$id.")");
				$changed = $this->change("DELETE FROM ".$p."vereine_donor WHERE entity = ".$entity." AND fk_adherent = ".$id);
			} elseif ($kind === 'name') {
				$changed = $this->change("UPDATE ".$p."adherent SET lastname = '".$this->db->escape(self::ANONYMOUS)."', firstname = NULL, civility = NULL, login = NULL, pass_crypted = NULL WHERE rowid = ".$id);
			}
			if ($changed < 0) {
				return $this->fail($this->error);
			}
			$done[$kind] = $changed;
		}
		$summary = (string) json_encode($done);
		if ($this->change("INSERT INTO ".$p."vereine_erasure (entity, fk_adherent, kind, done, fk_user, datec) VALUES (".$entity.", ".$id.", 'run', '"
			.$this->db->escape($summary)."', ".((int) $user->id).", '".$this->db->idate(dol_now())."')") < 0) {
			return $this->fail($this->error);
		}
		VereineLog::add($this->db, $user, VereineLog::ERASURE, $id, 0, $summary);
		$this->db->commit();
		// Files go after the database, so a failed run never leaves rows pointing at nothing.
		foreach ($files as $file) {
			if (is_dir($file)) {
				dol_delete_dir_recursive($file);
			} elseif (is_file($file)) {
				dol_delete_file($file, 0, 1, 0, null, false, 0);
			}
		}
		// Whoever follows the member (change feed, web hooks, other modules) learns that it changed.
		if (isset($done['contact']) || isset($done['name'])) {
			$member->fetch($id);
			$member->call_trigger('MEMBER_MODIFY', $user);
		}
		return $done;
	}

	/**
	 * Hold the erasure for a member (a dispute, a claim, an authority asking), or lift the hold.
	 *
	 * @param int    $memberId Member
	 * @param bool   $on       True to hold
	 * @param string $note     Why, needed to hold
	 * @param User   $user     Who
	 * @return int 1 when done, 0 when refused (see errors), -1 on error
	 */
	public function setHold($memberId, $on, $note, $user)
	{
		global $conf;

		$note = mb_substr(trim((string) $note), 0, 255, 'UTF-8');
		$this->errors = $on && $note === '' ? array('VereineErasureErrorHoldNote') : array();
		if ($this->errors) {
			return 0;
		}
		if ($this->change("INSERT INTO ".MAIN_DB_PREFIX."vereine_erasure (entity, fk_adherent, kind, note, fk_user, datec) VALUES (".((int) $conf->entity).", "
			.((int) $memberId).", '".($on ? 'hold' : 'release')."', '".$this->db->escape($note)."', ".((int) $user->id).", '".$this->db->idate(dol_now())."')") < 0) {
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::ERASURE_HOLD, (int) $memberId, 0, $on ? 'hold' : 'release');
		return 1;
	}

	/**
	 * Whether the erasure of a member is held, and why.
	 *
	 * @param int $memberId Member
	 * @return array{on:bool,note:string}
	 */
	public function hold($memberId)
	{
		global $conf;

		$sql = "SELECT kind, note FROM ".MAIN_DB_PREFIX."vereine_erasure WHERE entity = ".((int) $conf->entity)." AND fk_adherent = ".((int) $memberId);
		$sql .= " AND kind IN ('hold', 'release') ORDER BY rowid DESC";
		$resql = $this->db->query($sql." LIMIT 1");
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return array('on' => $obj && $obj->kind === 'hold', 'note' => $obj ? (string) $obj->note : '');
	}

	/**
	 * Runs and holds of a member, newest first.
	 *
	 * @param int $memberId Member
	 * @return array<int,array{kind:string,done:array<string,int>,note:string,user:string,at:int}>
	 */
	public function history($memberId)
	{
		global $conf;

		$sql = "SELECT e.kind, e.done, e.note, e.datec, u.login FROM ".MAIN_DB_PREFIX."vereine_erasure as e LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = e.fk_user";
		$sql .= " WHERE e.entity = ".((int) $conf->entity)." AND e.fk_adherent = ".((int) $memberId)." ORDER BY e.rowid DESC";
		$rows = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$done = json_decode((string) $obj->done, true);
			$rows[] = array('kind' => (string) $obj->kind, 'done' => is_array($done) ? $done : array(), 'note' => (string) $obj->note,
				'user' => (string) $obj->login, 'at' => (int) $this->db->jdate($obj->datec));
		}
		return $rows;
	}

	/**
	 * Things outside the module a person should look at: files in the member's documents, a user account.
	 *
	 * @param Adherent $member Member
	 * @return array<string,int>
	 */
	private function hints($member)
	{
		$other = 0;
		foreach ($this->files($member, '/.*/') as $file) {
			$other += preg_match('/^(mitgliedsantrag-.*\.pdf|einwilligung-\d+\.(pdf|jpe?g|png))$/', basename($file)) ? 0 : 1;
		}
		return array('files' => $other, 'user' => (int) $member->user_id > 0 ? 1 : 0, 'thirdparty' => (int) $member->fk_soc > 0 ? 1 : 0);
	}

	/**
	 * Unpaid invoices of the member's third party: while they are open, nothing goes.
	 *
	 * @param Adherent $member Member
	 * @return int
	 */
	private function openInvoices($member)
	{
		if ((int) $member->fk_soc < 1) {
			return 0;
		}
		return $this->count("SELECT COUNT(*) as v FROM ".MAIN_DB_PREFIX."facture WHERE fk_soc = ".((int) $member->fk_soc)." AND fk_statut = 1 AND paye = 0 AND entity IN (".getEntity('invoice').")");
	}

	/**
	 * The folder Dolibarr keeps the member's documents in.
	 *
	 * @param Adherent $member Member
	 * @return string
	 */
	private function folder($member)
	{
		global $conf;

		return $conf->adherent->dir_output.'/'.dol_sanitizeFileName((string) $member->ref !== '' ? (string) $member->ref : (string) $member->id);
	}

	/**
	 * Files in the member's folder whose name matches.
	 *
	 * @param Adherent $member  Member
	 * @param string   $pattern Regular expression on the name
	 * @return string[]
	 */
	private function files($member, $pattern)
	{
		$found = array();
		$folder = $this->folder($member);
		foreach (is_dir($folder) ? (array) scandir($folder) : array() as $name) {
			if (is_file($folder.'/'.$name) && preg_match($pattern, (string) $name)) {
				$found[] = $folder.'/'.$name;
			}
		}
		return $found;
	}

	/**
	 * The last day whose entries are over their period.
	 *
	 * @param string   $today Today, YYYY-MM-DD
	 * @param int|null $years Period
	 * @return string YYYY-MM-DD
	 */
	private function cutoff($today, $years)
	{
		$year = (int) substr($today, 0, 4) - (int) $years;
		$month = (int) substr($today, 5, 2);
		$day = (int) substr($today, 8, 2);
		// 29 February of a year without one is the 28th: the day before the period of that entry ends.
		return sprintf('%04d-%02d-%02d', $year, $month, checkdate($month, $day, $year) ? $day : 28);
	}

	/**
	 * Undo the run and keep the error.
	 *
	 * @param string $error What went wrong
	 * @return null
	 */
	private function fail($error)
	{
		$this->db->rollback();
		$this->error = (string) $error;
		return null;
	}

	/**
	 * Run a statement.
	 *
	 * @param string $sql Statement
	 * @return int Rows changed, -1 on error
	 */
	private function change($sql)
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return (int) $this->db->affected_rows($resql);
	}

	/**
	 * One value of a query, empty when there is none.
	 *
	 * @param string $sql Query with one column v
	 * @return string
	 */
	private function value($sql)
	{
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj && $obj->v !== null ? (string) $obj->v : '';
	}

	/**
	 * A count.
	 *
	 * @param string $sql Query with one column v
	 * @return int
	 */
	private function count($sql)
	{
		return (int) $this->value($sql);
	}

	/**
	 * The latest day of one or two queries.
	 *
	 * @param string $sql   Query with one column v
	 * @param string $other Another, empty for none
	 * @return string YYYY-MM-DD or empty
	 */
	private function day($sql, $other = '')
	{
		$days = array(substr($this->value($sql), 0, 10), $other !== '' ? substr($this->value($other), 0, 10) : '');
		return max($days);
	}

	/**
	 * One column of a query.
	 *
	 * @param string $sql Query with one column v
	 * @return string[]
	 */
	private function column($sql)
	{
		$values = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$values[] = (string) $obj->v;
		}
		return $values;
	}
}
