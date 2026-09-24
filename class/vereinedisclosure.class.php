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
 * \file    class/vereinedisclosure.class.php
 * \ingroup vereine
 * \brief   Access to one's own data (#10, Art. 15 GDPR): a copy of what Dolibarr and the module keep about a member.
 *
 * The copy holds the member's own rows only: votes, signatures, tasks and entries where the member is the
 * one named, never the rows of others; the log without its texts, which may name others. It is made as a
 * PDF to read and a JSON to take along, handed out as one ZIP and not kept: the association keeps the
 * request, how the person was checked, when the copy went out, and the checksum of what went out.
 */

require_once __DIR__.'/vereinedisclosurerules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Access to one's own data.
 */
class VereineDisclosure
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * @var string[] Language keys of what was refused
	 */
	public $errors = array();

	/**
	 * @var bool Whether a part of the copy could not be read: then no copy goes out, an incomplete one would mislead
	 */
	private $failed = false;

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
	 * Rows of a query, each as field => value.
	 *
	 * @param string $sql Query
	 * @return array<int,array<string,mixed>>
	 */
	private function rows($sql)
	{
		$rows = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failed = true;
			$this->error = $this->db->lasterror();
			return $rows;
		}
		while ($row = $this->db->fetch_array($resql)) {
			$clean = array();
			foreach ($row as $field => $value) {
				if (!is_int($field)) {
					$clean[$field] = $value === null ? '' : (string) $value;
				}
			}
			$rows[] = $clean;
		}
		return $rows;
	}

	/**
	 * Everything kept about one member, section by section.
	 *
	 * @param Adherent  $member      Member
	 * @param User      $user        Who makes the copy
	 * @param Translate $outputlangs Language of the words in it
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public function collect($member, $user, $outputlangs)
	{
		global $conf;

		require_once __DIR__.'/vereinememberform.class.php';

		$id = (int) $member->id;
		$entity = (int) $conf->entity;
		$day = function ($value) {
			return $value !== '' && $value !== null ? dol_print_date(is_numeric($value) ? (int) $value : $this->db->jdate($value), '%Y-%m-%d', 'tzserver') : '';
		};
		$sections = array();
		$sections['member'] = array(array('lastname' => (string) $member->lastname, 'firstname' => (string) $member->firstname, 'company' => (string) $member->company,
			'address' => (string) $member->address, 'zip' => (string) $member->zip, 'town' => (string) $member->town, 'country' => (string) $member->country,
			'email' => (string) $member->email, 'phone' => (string) $member->phone, 'phone_mobile' => (string) $member->phone_mobile,
			'birth' => !empty($member->birth) ? dol_print_date($member->birth, '%Y-%m-%d', 'tzserver') : '', 'gender' => (string) $member->gender,
			'member_type' => (string) $member->type, 'status' => $member->getLibStatut(0), 'login' => (string) $member->login,
			'created' => !empty($member->datec) ? dol_print_date($member->datec, '%Y-%m-%d', 'tzserver') : '',
			'public' => !empty($member->public)));
		$extra = array();
		foreach (VereineMemberForm::memberExtraFieldSpecs($this->db) as $code => $spec) {
			$shown = VereineMemberForm::shownValue($spec, isset($member->array_options['options_'.$code]) ? $member->array_options['options_'.$code] : '', $outputlangs);
			if ($shown !== '') {
				$extra[] = array('field' => $outputlangs->transnoentitiesnoconv($spec['label']), 'value' => $shown);
			}
		}
		$sections['extra'] = $extra;
		$sections['subscriptions'] = $this->rows("SELECT dateadh as start, datef as end, subscription as amount, note FROM ".MAIN_DB_PREFIX."subscription WHERE fk_adherent = ".$id." ORDER BY dateadh");
		$sections['invoices'] = (int) $member->fk_soc > 0 ? $this->rows("SELECT ref, datef as day, total_ttc as amount, paye as paid FROM ".MAIN_DB_PREFIX."facture WHERE fk_soc = ".((int) $member->fk_soc)." AND fk_statut > 0 AND entity IN (".getEntity('invoice').") ORDER BY datef, rowid") : array();
		$sections['consents'] = $this->rows("SELECT code, version, given, source, date_event as day, note, proof_at FROM ".MAIN_DB_PREFIX."vereine_consent WHERE entity = ".$entity." AND fk_adherent = ".$id." ORDER BY date_event, rowid");
		$sections['applications'] = $this->rows("SELECT external_id, datec as received, status, decided_on, reason FROM ".MAIN_DB_PREFIX."vereine_application WHERE entity = ".$entity." AND fk_adherent = ".$id." ORDER BY rowid");
		$sections['functions'] = $this->rows("SELECT f.label as function, t.date_start as start, t.date_end as end FROM ".MAIN_DB_PREFIX."vereine_function_term as t INNER JOIN ".MAIN_DB_PREFIX."vereine_function as f ON f.rowid = t.fk_function WHERE t.entity = ".$entity." AND t.fk_adherent = ".$id." ORDER BY t.date_start");
		$sections['honours'] = $this->rows("SELECT kind, years, label, given_on as day FROM ".MAIN_DB_PREFIX."vereine_honour WHERE entity = ".$entity." AND fk_adherent = ".$id." ORDER BY given_on");
		$sections['exits'] = $this->rows("SELECT reason, notice_day, last_day, status, date_done as done FROM ".MAIN_DB_PREFIX."vereine_member_exit WHERE entity = ".$entity." AND fk_adherent = ".$id." ORDER BY rowid");
		// Bindings of apps and websites: who, what for and since when; never a secret.
		$sections['identities'] = $this->rows("SELECT client, capabilities, linked_at, revoked_at FROM ".MAIN_DB_PREFIX."vereine_identity WHERE entity = ".$entity." AND fk_adherent = ".$id." ORDER BY rowid");
		// Accounts at Discord, Twitch and the like, with the confirmation of an application (#233).
		require_once __DIR__.'/vereinesocial.class.php';
		$sections['accounts'] = array();
		foreach ((new VereineSocial($this->db))->accounts($member) as $account) {
			if ($account['handle'] !== '') {
				$sections['accounts'][] = array('network' => $account['label'], 'handle' => $account['handle'], 'confirmed_at' => $account['confirmed_at'], 'client' => $account['client']);
			}
		}
		$sections['invitations'] = $this->rows("SELECT m.title as meeting, m.meeting_day as day, i.channel, i.voting, i.sent_at FROM ".MAIN_DB_PREFIX."vereine_meeting_invitation as i INNER JOIN ".MAIN_DB_PREFIX."vereine_meeting as m ON m.rowid = i.fk_meeting WHERE i.entity = ".$entity." AND i.fk_adherent = ".$id." ORDER BY m.meeting_day");
		$sections['attendance'] = $this->rows("SELECT m.title as meeting, m.meeting_day as day, a.state FROM ".MAIN_DB_PREFIX."vereine_meeting_attendance as a INNER JOIN ".MAIN_DB_PREFIX."vereine_meeting as m ON m.rowid = a.fk_meeting WHERE a.entity = ".$entity." AND a.fk_adherent = ".$id." ORDER BY m.meeting_day");
		// Only the member's own votes of circular resolutions, which are not secret.
		$sections['votes'] = $this->rows("SELECT c.title as resolution, v.choice, v.voted_at FROM ".MAIN_DB_PREFIX."vereine_circular_vote as v INNER JOIN ".MAIN_DB_PREFIX."vereine_circular as c ON c.rowid = v.fk_circular WHERE v.entity = ".$entity." AND v.fk_adherent = ".$id." ORDER BY v.rowid");
		$sections['signatures'] = $this->rows("SELECT s.doc_name as document, p.function_label as function, p.signed_at, p.way FROM ".MAIN_DB_PREFIX."vereine_signature_person as p INNER JOIN ".MAIN_DB_PREFIX."vereine_signature as s ON s.rowid = p.fk_signature WHERE p.fk_adherent = ".$id." AND s.entity = ".$entity." ORDER BY p.rowid");
		$sections['tasks'] = $this->rows("SELECT label as task, deadline, done_at FROM ".MAIN_DB_PREFIX."vereine_resolution_task WHERE entity = ".$entity
			." AND fk_adherent = ".$id." ORDER BY rowid");
		$sections['duties'] = $this->rows("SELECT d.label as duty, t.fiscal_year as year, t.due_on, t.done_on FROM ".MAIN_DB_PREFIX."vereine_duty_task as t INNER JOIN ".MAIN_DB_PREFIX."vereine_duty as d ON d.rowid = t.fk_duty WHERE t.entity = ".$entity." AND t.fk_adherent = ".$id." ORDER BY t.due_on");
		$sections['shifts'] = $this->rows("SELECT s.label as shift, e.status, e.hours FROM ".MAIN_DB_PREFIX."vereine_event_shift_entry as e INNER JOIN ".MAIN_DB_PREFIX."vereine_event_shift as s ON s.rowid = e.fk_shift WHERE e.entity = ".$entity." AND e.fk_adherent = ".$id." ORDER BY e.rowid");
		$sections['volunteer'] = $this->rows("SELECT duty_day as day, activity, kind, amount, paid_on FROM ".MAIN_DB_PREFIX."vereine_volunteer WHERE entity = ".$entity." AND fk_adherent = ".$id." ORDER BY duty_day");
		// Donations: the donor's data only for whoever may see dates of birth (#6).
		$donations = array();
		foreach ($this->rows("SELECT rowid, refnr, firstname, lastname, birth, vbpk, given_on FROM ".MAIN_DB_PREFIX."vereine_donor WHERE entity = ".$entity
			." AND (fk_adherent = ".$id.((int) $member->fk_soc > 0 ? " OR fk_soc = ".((int) $member->fk_soc) : "").")") as $donor) {
			$mayBirth = $user->hasRight('vereine', 'donation', 'write');
			if ($mayBirth && $donor['birth'] !== '') {
				require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
			}
			$gifts = $this->rows("SELECT d.datedon as day, d.amount FROM ".MAIN_DB_PREFIX."don as d INNER JOIN ".MAIN_DB_PREFIX."vereine_donation_gift as g ON g.fk_don = d.rowid WHERE g.fk_donor = ".((int) $donor['rowid'])." AND d.fk_statut = 2 ORDER BY d.datedon");
			foreach ($gifts as $gift) {
				$donations[] = array('refnr' => $donor['refnr'], 'day' => $day($gift['day']), 'amount' => $gift['amount'],
					'birth' => $mayBirth && $donor['birth'] !== '' ? dolDecrypt($donor['birth']) : ($donor['birth'] !== '' ? '*' : ''),
					'vbpk' => $donor['vbpk'] !== '');
			}
		}
		$sections['donations'] = $donations;
		$sections['loans'] = $this->rows("SELECT r.ref, l.issued_on as start, l.due_on as deadline, l.returned_on as end, l.condition_out, l.condition_in FROM ".MAIN_DB_PREFIX
			."vereine_loan as l LEFT JOIN ".MAIN_DB_PREFIX."resource as r ON r.rowid = l.fk_resource WHERE l.entity = ".$entity." AND l.fk_adherent = ".$id." ORDER BY l.issued_on");
		// Fee arrears the Mahnwesen module reported: the invoice, the step and what became of it; never the dunning file.
		$sections['arrears'] = $this->rows("SELECT f.ref as invoice, a.level, a.state, a.date_state as day FROM ".MAIN_DB_PREFIX."vereine_arrear as a LEFT JOIN "
			.MAIN_DB_PREFIX."facture as f ON f.rowid = a.fk_facture WHERE a.entity = ".$entity." AND a.fk_adherent = ".$id." ORDER BY a.rowid");
		// What the module did about the member: the kind and the day; the texts may name others and stay out.
		$sections['log'] = $this->rows("SELECT datec as day, action FROM ".MAIN_DB_PREFIX."vereine_log WHERE entity = ".$entity." AND fk_adherent = ".$id." ORDER BY rowid");
		return $sections;
	}

	/**
	 * Make the copy for a request: PDF and JSON in one ZIP, the request kept with the checksum of what went out.
	 *
	 * @param Adherent            $member      Member
	 * @param array<string,mixed> $entered     requested_on, check, note
	 * @param string              $today       Today, YYYY-MM-DD
	 * @param User                $user        Who makes it
	 * @param Translate           $outputlangs Language
	 * @return string The ZIP to hand out and delete, empty when refused (see errors) or on error
	 */
	public function make($member, array $entered, $today, $user, $outputlangs)
	{
		global $conf, $mysoc;

		require_once __DIR__.'/vereinepdf.class.php';
		require_once __DIR__.'/vereineorganization.class.php';
		require_once __DIR__.'/vereinememberform.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$this->errors = array();
		$checked = VereineDisclosureRules::request($entered, $today);
		if ($checked['errors']) {
			$this->errors = $checked['errors'];
			return '';
		}
		if (!class_exists('ZipArchive')) {
			$this->error = 'ZipArchive is missing';
			return '';
		}
		$outputlangs->loadLangs(array('main', 'members', 'vereine@vereine'));
		$this->failed = false;
		$sections = $this->collect($member, $user, $outputlangs);
		if ($this->failed) {
			return '';
		}
		$organization = VereineOrganization::load($mysoc);
		$name = trim($member->firstname.' '.$member->lastname);
		$now = dol_now();
		$json = VereineDisclosureRules::json(array('association' => $organization['name'], 'member' => $name,
			'generated_at' => dol_print_date($now, '%Y-%m-%dT%H:%M:%S', 'tzserver'), 'requested_on' => $checked['request']['requested_on']), $sections);

		$labels = array();
		foreach (array_keys(self::fieldWords()) as $field) {
			$labels[$field] = $outputlangs->transnoentitiesnoconv('VereineDisclosureField_'.$field);
		}
		$pdf = VereinePdf::start($outputlangs);
		$font = pdf_getPDFFont($outputlangs);
		VereinePdf::title($pdf, $outputlangs, $outputlangs->transnoentities('VereineDisclosureTitle'), $name);
		$pdf->SetFont($font, '', 9);
		$pdf->MultiCell(0, 4.5, $outputlangs->transnoentities('VereineDisclosureIntro', $organization['name'], dol_print_date($now, 'day', 'tzserver', $outputlangs)), 0, 'L');
		$privacy = getDolGlobalString(VereineMemberForm::PRIVACY);
		if ($privacy !== '') {
			VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineDisclosurePrivacy'));
			$pdf->SetFont($font, '', 9);
			$pdf->MultiCell(0, 4.5, dol_string_nohtmltag($privacy, 0), 0, 'L');
		}
		foreach (VereineDisclosureRules::SECTIONS as $section) {
			VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineDisclosureSection_'.$section));
			$pdf->SetFont($font, '', 9);
			$rows = isset($sections[$section]) ? $sections[$section] : array();
			if (!$rows) {
				$pdf->MultiCell(0, 4.5, $outputlangs->transnoentitiesnoconv('VereineDisclosureNothing'), 0, 'L');
				continue;
			}
			foreach ($rows as $row) {
				$pdf->MultiCell(0, 4.5, '• '.VereineDisclosureRules::line($row, $labels + array('_yes' => $outputlangs->transnoentitiesnoconv('Yes'),
					'_no' => $outputlangs->transnoentitiesnoconv('No'))), 0, 'L');
			}
		}
		VereinePdf::finish($pdf, $outputlangs, $outputlangs->transnoentities('VereineDisclosureTitle').' – '.$name);

		$dir = $conf->vereine->dir_output.'/temp';
		if (dol_mkdir($dir) < 0) {
			$this->error = 'cannot create '.$dir;
			return '';
		}
		$stamp = dol_print_date($now, '%Y%m%d-%H%M%S', 'tzserver');
		$pdfFile = $dir.'/auskunft-'.((int) $member->id).'-'.$stamp.'.pdf';
		$pdf->Output($pdfFile, 'F');
		$zipFile = $dir.'/auskunft-'.((int) $member->id).'-'.$stamp.'.zip';
		$zip = new ZipArchive();
		if (!is_file($pdfFile) || $zip->open($zipFile, ZipArchive::CREATE) !== true) {
			$this->error = 'cannot write the copy';
			return '';
		}
		$zip->addFile($pdfFile, 'auskunft.pdf');
		$zip->addFromString('auskunft.json', $json);
		$zip->close();
		dol_delete_file($pdfFile);

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_disclosure (entity, fk_adherent, requested_on, identity_check, note, delivered_at, sha256, fk_user, datec) VALUES (";
		$sql .= ((int) $conf->entity).", ".((int) $member->id).", '".$this->db->escape($checked['request']['requested_on'])."', '".$this->db->escape($checked['request']['check'])."',";
		$sql .= " ".($checked['request']['note'] !== '' ? "'".$this->db->escape($checked['request']['note'])."'" : "NULL").", '".$this->db->idate($now)."',";
		$sql .= " '".$this->db->escape((string) hash_file('sha256', $zipFile))."', ".((int) $user->id).", '".$this->db->idate($now)."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			dol_delete_file($zipFile);
			return '';
		}
		VereineLog::add($this->db, $user, VereineLog::DISCLOSURE, (int) $member->id, (int) $member->fk_soc, 'requested '.$checked['request']['requested_on'].', '.$checked['request']['check']);
		return $zipFile;
	}

	/**
	 * The requests of a member, newest first.
	 *
	 * @param int $memberId Member
	 * @return array<int,array{requested_on:string,check:string,note:string,delivered_at:int,sha256:string,user:string}>
	 */
	public function requests($memberId)
	{
		global $conf;

		$sql = "SELECT d.requested_on, d.identity_check, d.note, d.delivered_at, d.sha256, u.login FROM ".MAIN_DB_PREFIX."vereine_disclosure as d";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = d.fk_user WHERE d.entity = ".((int) $conf->entity)." AND d.fk_adherent = ".((int) $memberId);
		$sql .= " ORDER BY d.rowid DESC";
		$requests = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$requests[] = array('requested_on' => (string) $obj->requested_on, 'check' => (string) $obj->identity_check, 'note' => (string) $obj->note,
				'delivered_at' => $this->db->jdate($obj->delivered_at), 'sha256' => (string) $obj->sha256, 'user' => (string) $obj->login);
		}
		return $requests;
	}

	/**
	 * The field names of the copy, for their words in the PDF.
	 *
	 * @return array<string,bool>
	 */
	public static function fieldWords()
	{
		return array_fill_keys(array('lastname', 'firstname', 'company', 'address', 'zip', 'town', 'country', 'email', 'phone', 'phone_mobile', 'birth', 'gender',
			'member_type', 'status', 'login', 'created', 'public', 'field', 'value', 'start', 'end', 'amount', 'note', 'ref', 'day', 'paid', 'code', 'version',
			'given', 'source', 'proof_at', 'external_id', 'received', 'decided_on', 'reason', 'function', 'notice_day', 'last_day', 'done', 'client',
			'capabilities', 'linked_at', 'revoked_at', 'meeting', 'channel', 'voting', 'sent_at', 'state', 'resolution', 'choice', 'voted_at', 'document',
			'signed_at', 'way', 'task', 'deadline', 'done_at', 'duty', 'year', 'due_on', 'done_on', 'shift', 'hours', 'activity', 'kind', 'paid_on', 'refnr',
			'vbpk', 'action', 'invoice', 'level', 'network', 'handle', 'confirmed_at', 'years', 'label', 'condition_out', 'condition_in'), true);
	}
}
