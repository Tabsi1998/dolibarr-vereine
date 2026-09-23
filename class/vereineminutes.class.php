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
 * \file    class/vereineminutes.class.php
 * \ingroup vereine
 * \brief   Minutes of a meeting: draft as PDF, final version with checksum and signatures, sending them out.
 *
 * A draft is built whenever somebody asks for it and is overwritten. A final version is kept with its
 * checksum in llx_vereine_meeting_minutes and goes into a signature run; it is never changed again.
 */

require_once __DIR__.'/vereinemeetings.class.php';
require_once __DIR__.'/vereinesignatures.class.php';
require_once __DIR__.'/vereinemeetingdocs.class.php';
require_once __DIR__.'/vereineresolutions.class.php';
require_once __DIR__.'/vereinemail.class.php';
require_once __DIR__.'/vereinepdf.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Minutes of a meeting.
 */
class VereineMinutes
{
	/** Longest note of a final version. */
	const NOTE_MAX = 255;

	/** The board gets the minutes. */
	const AUDIENCE_BOARD = 'board';
	/** Every active member gets the minutes. */
	const AUDIENCE_MEMBERS = 'members';

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
	 * Where the minutes live.
	 *
	 * @return string
	 */
	public static function directory()
	{
		global $conf;

		return DOL_DATA_ROOT.($conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/vereine/minutes';
	}

	/**
	 * Path of the draft of a meeting; it is overwritten with every new draft.
	 *
	 * @param int $meetingId Meeting
	 * @return string
	 */
	public static function draftPath($meetingId)
	{
		return self::directory().'/protokoll-'.((int) $meetingId).'-entwurf.pdf';
	}

	/**
	 * Path of a final version.
	 *
	 * @param int $meetingId Meeting
	 * @param int $version   Version, from 1
	 * @return string
	 */
	public static function finalPath($meetingId, $version)
	{
		return self::directory().'/protokoll-'.((int) $meetingId).'-fassung-'.((int) $version).'.pdf';
	}

	/**
	 * Who presided and who kept the minutes, with their names.
	 *
	 * @param array<string,mixed> $meeting Meeting of VereineMeetings::fetch()
	 * @return array{chair:int,keeper:int,suggested:bool,names:array<int,string>}
	 */
	public function roles(array $meeting)
	{
		$meetings = new VereineMeetings($this->db);
		$attendance = $meetings->attendance($meeting['id']);
		$functions = new VereineFunctions($this->db);
		$roles = VereineMinutesRules::roles(array('chair' => $meeting['chair_id'], 'keeper' => $meeting['keeper_id']),
			$functions->holdersByCode($meeting['day']), array_keys($attendance['names']));
		$roles['names'] = $attendance['names'];
		return $roles;
	}

	/**
	 * Store who presided and who kept the minutes.
	 *
	 * @param int  $meetingId Meeting
	 * @param int  $chair     Member presiding, 0 for nobody
	 * @param int  $keeper    Member keeping the minutes, 0 for nobody
	 * @param User $user      Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function saveRoles($meetingId, $chair, $keeper, $user)
	{
		global $conf;

		$this->errors = array();
		$meetings = new VereineMeetings($this->db);
		$meeting = $meetings->fetch($meetingId);
		if ($meeting === null || $meeting['status'] === VereineMeetingRules::STATUS_CANCELLED) {
			$this->errors[] = 'VereineMinutesErrorMeeting';
			return 0;
		}
		$invited = array_keys($meetings->attendance($meetingId)['names']);
		foreach (array($chair, $keeper) as $member) {
			if ((int) $member > 0 && !in_array((int) $member, array_map('intval', $invited), true)) {
				$this->errors[] = 'VereineMinutesErrorRoleNotInvited';
				return 0;
			}
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_meeting SET fk_chair = ".((int) $chair).", fk_keeper = ".((int) $keeper);
		$sql .= ", fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $meetingId)." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * The final versions of a meeting, newest first.
	 *
	 * @param int $meetingId Meeting
	 * @return array<int,array<string,mixed>>
	 */
	public function versions($meetingId)
	{
		global $conf;

		$sql = "SELECT rowid, version, approved_on, note, filename, doc_sha, sent_board, sent_members, datec FROM ".MAIN_DB_PREFIX."vereine_meeting_minutes";
		$sql .= " WHERE fk_meeting = ".((int) $meetingId)." AND entity = ".((int) $conf->entity)." ORDER BY version DESC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$versions = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$versions[] = array('id' => (int) $obj->rowid, 'meeting_id' => (int) $meetingId, 'version' => (int) $obj->version,
				'approved_on' => (string) $obj->approved_on, 'note' => (string) $obj->note, 'filename' => (string) $obj->filename,
				'doc_sha' => (string) $obj->doc_sha, 'sent_board' => $obj->sent_board ? $this->db->jdate($obj->sent_board) : 0,
				'sent_members' => $obj->sent_members ? $this->db->jdate($obj->sent_members) : 0, 'created' => $this->db->jdate($obj->datec));
		}
		$this->db->free($resql);
		return $versions;
	}

	/**
	 * One final version.
	 *
	 * @param int $id Version
	 * @return array<string,mixed>|null
	 */
	public function version($id)
	{
		global $conf;

		$sql = "SELECT fk_meeting FROM ".MAIN_DB_PREFIX."vereine_meeting_minutes WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		foreach ($this->versions((int) $obj->fk_meeting) as $version) {
			if ($version['id'] === (int) $id) {
				return $version;
			}
		}
		return null;
	}

	/**
	 * Path of a stored final version, empty when it is missing.
	 *
	 * @param array<string,mixed> $version Version of versions() or version()
	 * @return string
	 */
	public static function path(array $version)
	{
		if (!preg_match('/^protokoll-\d+-fassung-\d+\.pdf$/', (string) $version['filename'])) {
			return '';
		}
		$file = self::directory().'/'.$version['filename'];
		return is_file($file) ? $file : '';
	}

	/**
	 * Build the draft of the minutes as they stand now.
	 *
	 * @param array<string,mixed> $meeting     Meeting
	 * @param Translate           $outputlangs Language of the minutes
	 * @return string Path of the PDF, empty on error
	 */
	public function buildDraft(array $meeting, $outputlangs)
	{
		return $this->build($meeting, self::draftPath($meeting['id']), 0, '', '', $outputlangs);
	}

	/**
	 * Store the minutes as a final version and start its signature run.
	 *
	 * @param int       $meetingId   Meeting
	 * @param string    $approvedOn  Day the minutes were approved, may be empty
	 * @param string    $note        How they were approved, for example "Beschluss der Generalversammlung"
	 * @param User      $user        Who stores
	 * @param Translate $outputlangs Language of the minutes
	 * @return int Id of the version, 0 when refused (see errors), -1 on error
	 */
	public function finalize($meetingId, $approvedOn, $note, $user, $outputlangs)
	{
		global $conf;

		$this->errors = array();
		$meetings = new VereineMeetings($this->db);
		$meeting = $meetings->fetch($meetingId);
		if ($meeting === null || !in_array($meeting['status'], array(VereineMeetingRules::STATUS_INVITED, VereineMeetingRules::STATUS_HELD), true)) {
			$this->errors[] = 'VereineMinutesErrorMeeting';
			return 0;
		}
		if ((string) $approvedOn !== '' && !VereineStatuteRules::isDate($approvedOn)) {
			$this->errors[] = 'VereineMinutesErrorApprovedOn';
			return 0;
		}
		$roles = $this->roles($meeting);
		if ($roles['chair'] === 0 && $roles['keeper'] === 0) {
			$this->errors[] = 'VereineMinutesErrorRoles';
			return 0;
		}
		$version = 1;
		foreach ($this->versions($meetingId) as $existing) {
			$version = max($version, $existing['version'] + 1);
		}
		$file = self::finalPath($meetingId, $version);
		// A final version is kept for years: PDF/A with its own code (#123).
		require_once __DIR__.'/vereinearchive.class.php';
		$archive = new VereineArchive($this->db);
		$code = $archive->codeFor('minutes', 0);
		if ($this->build($meeting, $file, $version, $approvedOn, VereineMinutesRules::text($note), $outputlangs, $code) === '') {
			return -1;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_meeting_minutes (entity, fk_meeting, version, approved_on, note, filename, doc_sha, datec, fk_user_modif)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $meetingId).", ".((int) $version).",";
		$sql .= " ".((string) $approvedOn !== '' ? "'".$this->db->escape($approvedOn)."'" : "NULL").", '".$this->db->escape(mb_substr(VereineMinutesRules::text($note), 0, self::NOTE_MAX, 'UTF-8'))."',";
		$sql .= " '".$this->db->escape(basename($file))."', '".$this->db->escape(hash_file('sha256', $file))."', '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_meeting_minutes');
		if ($archive->register($code, 'minutes', $id, $meeting['title'].', '.$outputlangs->transnoentities('VereineMinutesPdfVersion', $version), $file) < 0) {
			$this->error = $archive->error;
			return -1;
		}
		// The minutes are signed by those who presided and kept them, not by the functions of the catalogue.
		$signatures = new VereineSignatures($this->db);
		$people = array();
		foreach (array('chair' => 'VereineMinutesChair', 'keeper' => 'VereineMinutesKeeper') as $role => $key) {
			if ($roles[$role] > 0) {
				$people[] = array('member_id' => $roles[$role], 'role' => $role, 'label' => $outputlangs->transnoentitiesnoconv($key),
					'name' => isset($roles['names'][$roles[$role]]) ? $roles['names'][$roles[$role]] : '');
			}
		}
		$signatures->start(VereineSignatureRules::KIND_MINUTES, $id, $file, $meeting['day'], $user, $people);
		VereineLog::add($this->db, $user, VereineLog::MINUTES_FINAL, 0, 0, $meeting['title'].': version '.$version);
		return $id;
	}

	/**
	 * Send a final version by e-mail, with the PDF attached.
	 *
	 * @param int       $id          Version
	 * @param string    $audience    AUDIENCE_BOARD or AUDIENCE_MEMBERS
	 * @param User      $user        Who sends
	 * @param Translate $outputlangs Language of the e-mail
	 * @return int Number of e-mails sent, 0 when refused (see errors), -1 on error
	 */
	public function send($id, $audience, $user, $outputlangs)
	{
		global $conf, $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

		$this->errors = array();
		$version = $this->version($id);
		$file = $version !== null ? self::path($version) : '';
		if ($version === null || $file === '') {
			$this->errors[] = 'VereineMinutesErrorVersion';
			return 0;
		}
		if (!in_array($audience, array(self::AUDIENCE_BOARD, self::AUDIENCE_MEMBERS), true)) {
			$this->errors[] = 'VereineMinutesErrorAudience';
			return 0;
		}
		$meetings = new VereineMeetings($this->db);
		$meeting = $meetings->fetch($version['meeting_id']);
		if ($meeting === null) {
			$this->errors[] = 'VereineMinutesErrorMeeting';
			return 0;
		}
		$statutes = new VereineStatutes($this->db);
		$kind = $audience === self::AUDIENCE_BOARD ? VereineMeetingRules::KIND_BOARD : VereineMeetingRules::KIND_GENERAL;
		$recipients = VereineMeetingRules::recipients($kind, $meetings->members($meeting['day']), $statutes->rules());
		$from = VereineMail::sender();
		$templates = new VereineMailTemplates($this->db);
		$sent = 0;
		$without = 0;
		foreach ($recipients as $recipient) {
			if ($recipient['channel'] !== VereineMeetingRules::CHANNEL_EMAIL) {
				$without++;
				continue;
			}
			$values = VereinePlaceholders::meeting($meeting, (string) $recipient['name'], true, trim((string) $mysoc->name), vereineMeetingDay($meeting['day'], $outputlangs), '', $outputlangs);
			$composed = $templates->compose(VereineMailTemplates::TYPE_MINUTES, $values, $outputlangs);
			$mail = new CMailFile($composed['subject'], $recipient['email'], $from, $composed['body'], array($file), array('application/pdf'), array(basename($file)),
				'', '', 0, $composed['html'] ? 1 : 0, '', '', 'minutes'.$version['id']);
			if ($mail->sendfile()) {
				$sent++;
			}
		}
		$column = $audience === self::AUDIENCE_BOARD ? 'sent_board' : 'sent_members';
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_meeting_minutes SET ".$column." = '".$this->db->idate(dol_now())."'";
		$sql .= ", fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $version['id'])." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::MINUTES_SENT, 0, 0, $meeting['title'].': '.$audience.', '.$sent.' e-mails, '.$without.' without e-mail');
		return $sent;
	}

	/**
	 * Build the minutes as a PDF: what was decided, by whom, on what basis.
	 *
	 * @param array<string,mixed> $meeting     Meeting
	 * @param string              $file        Where to write
	 * @param int                 $version     Version, 0 for a draft
	 * @param string              $approvedOn  Day of the approval, may be empty
	 * @param string              $note        How it was approved
	 * @param Translate           $outputlangs Language of the minutes
	 * @param string              $code        Code of a final version (#123), empty for a draft
	 * @return string Path of the PDF, empty on error
	 */
	private function build(array $meeting, $file, $version, $approvedOn, $note, $outputlangs, $code = '')
	{
		global $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$outputlangs->load('vereine@vereine');
		if (dol_mkdir(dirname($file)) < 0) {
			$this->error = 'cannot create '.dirname($file);
			return '';
		}
		$meetings = new VereineMeetings($this->db);
		$statutes = new VereineStatutes($this->db);
		$rules = $statutes->rules();
		$attendance = $meetings->attendance($meeting['id']);
		$quorum = VereineAttendanceRules::quorum($meeting['kind'], $attendance['rows'], $attendance['voting'], $rules, $meeting['time']);
		$votes = $meetings->votes($meeting['id']);
		$items = $meetings->items($meeting, $outputlangs);
		$roles = $this->roles($meeting);
		$name = function ($id) use ($roles, $outputlangs) {
			return $id > 0 && isset($roles['names'][$id]) ? $roles['names'][$id] : $outputlangs->transnoentitiesnoconv('VereineMinutesNobody');
		};

		$pdf = VereinePdf::start($outputlangs, $version > 0);
		$font = pdf_getPDFFont($outputlangs);
		$line = function ($text, $style = '', $size = 10) use ($pdf, $font) {
			$pdf->SetFont($font, $style, $size);
			$pdf->MultiCell(0, 5, $text, 0, 'L');
		};

		$title = $outputlangs->transnoentities('VereineMinutesPdfTitle', $outputlangs->transnoentitiesnoconv('VereineMeetingKind_'.$meeting['kind']));
		VereinePdf::title($pdf, $outputlangs, $title.($version > 0 ? '' : ' - '.$outputlangs->transnoentitiesnoconv('VereineMinutesDraft')), $meeting['title']);
		$line($outputlangs->transnoentities('VereineMinutesPdfWhen', vereineMeetingDay($meeting['day'], $outputlangs), $meeting['time']));
		$line($outputlangs->transnoentities('VereineMinutesPdfWhere', $outputlangs->transnoentitiesnoconv('VereineMeetingFormat_'.$meeting['format']),
			$meeting['place'] !== '' ? $meeting['place'] : '-'));
		$line($outputlangs->transnoentities('VereineMinutesPdfChair', $name($roles['chair'])));
		$line($outputlangs->transnoentities('VereineMinutesPdfKeeper', $name($roles['keeper'])));
		if ($version > 0) {
			$line($outputlangs->transnoentities('VereineMinutesPdfVersion', $version));
			if ((string) $approvedOn !== '') {
				$line($outputlangs->transnoentities('VereineMinutesPdfApproved', vereineFormatDay($approvedOn)).($note !== '' ? ' - '.$note : ''));
			}
		}
		$pdf->Ln(4);

		// Attendance and quorum.
		VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineAttendance'));
		$line($outputlangs->transnoentities('VereineMinutesPdfAttendance', $quorum['present'], $quorum['represented'], $quorum['votes'], $quorum['eligible']));
		$line($outputlangs->transnoentities($quorum['reached'] ? 'VereineAttendanceReached' : 'VereineAttendanceMissing').' '
			.$outputlangs->transnoentities('VereineMinutesPdfQuorum', max($meeting['kind'] === VereineMeetingRules::KIND_BOARD ? 1 : 0, (int) $quorum['required'])));
		$present = array();
		$excused = array();
		foreach ($attendance['rows'] as $memberId => $row) {
			$who = isset($attendance['names'][$memberId]) ? $attendance['names'][$memberId] : (string) $memberId;
			if ($row['state'] === VereineAttendanceRules::STATE_PRESENT) {
				$present[] = $who;
			} elseif ($row['state'] === VereineAttendanceRules::STATE_EXCUSED) {
				$excused[] = $who;
			} elseif ($row['state'] === VereineAttendanceRules::STATE_REPRESENTED) {
				$holder = $row['holder'] > 0 && isset($attendance['names'][$row['holder']]) ? $attendance['names'][$row['holder']] : '';
				$present[] = $who.' ('.$outputlangs->transnoentitiesnoconv('VereineAttendanceState_represented').($holder !== '' ? ': '.$holder : '').')';
			}
		}
		if ($present) {
			$line($outputlangs->transnoentities('VereineMinutesPdfPresent', implode(', ', $present)));
		}
		if ($excused) {
			$line($outputlangs->transnoentities('VereineMinutesPdfExcused', implode(', ', $excused)));
		}
		$pdf->Ln(4);

		// What was agreed per item: who does what until when.
		$register = new VereineResolutions($this->db);
		$agreed = array();
		foreach ($register->tasks(0, $meeting['id']) as $task) {
			$who = isset($attendance['names'][$task['member_id']]) ? $attendance['names'][$task['member_id']] : (string) $task['member_id'];
			$agreed[$task['item']][] = $outputlangs->transnoentities('VereineAgreementLine', $who, $task['label'],
				$task['deadline'] !== '' ? vereineFormatDay($task['deadline']) : $outputlangs->transnoentitiesnoconv('VereineAgreementNoDeadline'));
		}

		// The agenda with its texts and votes.
		VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineMeetingAgenda'));
		foreach ($items as $item) {
			$pdf->Ln(1);
			$line($item['item'].'. '.$item['title'], 'B');
			if ($item['filled'] !== '') {
				$line($item['filled']);
			}
			if (isset($agreed[$item['item']])) {
				$line(implode("\n", $agreed[$item['item']]));
			}
			foreach ($votes as $vote) {
				if ($vote['item'] !== $item['item']) {
					continue;
				}
				$line($outputlangs->transnoentities('VereineMinutesPdfVote', $vote['title'],
					$outputlangs->transnoentitiesnoconv($vote['passed'] ? 'VereineVotePassed' : 'VereineVoteRejected'),
					$outputlangs->transnoentities('VereineVoteCountsText', $vote['yes'], $vote['no'], $vote['abstain'])));
				$line($outputlangs->transnoentities('VereineMinutesPdfMajority', $outputlangs->transnoentitiesnoconv('VereineStatuteMajority_'.$vote['majority']))
					.($vote['secret'] ? ' - '.$outputlangs->transnoentitiesnoconv('VereineVoteSecret') : ''));
			}
			$pdf->Ln(2);
		}

		// What was decided, in one list.
		$passed = array();
		foreach ($votes as $vote) {
			if ($vote['passed']) {
				$passed[] = $vote['item'].'. '.$vote['title'];
			}
		}
		$pdf->Ln(2);
		VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineMinutesPdfResolutions'));
		$line($passed ? implode("\n", $passed) : $outputlangs->transnoentitiesnoconv('VereineVotesNone'));

		// The attachments: what proves the votes and the proxies (#115).
		$attachments = array();
		$docs = new VereineMeetingDocs($this->db);
		$voteTitles = array();
		foreach ($votes as $vote) {
			$voteTitles[$vote['id']] = $vote['title'];
		}
		foreach ($docs->all($meeting['id']) as $document) {
			$what = $outputlangs->transnoentitiesnoconv('VereineMeetingDocKind_'.$document['kind']);
			if ($document['vote_id'] > 0 && isset($voteTitles[$document['vote_id']])) {
				$what .= ' - '.$voteTitles[$document['vote_id']];
			} elseif ($document['member_id'] > 0 && isset($attendance['names'][$document['member_id']])) {
				$what .= ' - '.$attendance['names'][$document['member_id']];
			}
			$attachments[] = $what.($document['label'] !== '' ? ': '.$document['label'] : '').' ('.$document['filename'].')';
		}
		if ($attachments) {
			$pdf->Ln(4);
			VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineMinutesPdfAttachments'));
			$line(implode("\n", $attachments));
		}
		$pdf->Ln(8);

		// Signature lines, for the paper way.
		VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineMinutesPdfSignatures'));
		$pdf->Ln(6);
		$pdf->SetFont($font, '', 10);
		$pdf->MultiCell(80, 5, '______________________', 0, 'L', false, 0);
		$pdf->MultiCell(80, 5, '______________________', 0, 'L', false, 1);
		$pdf->MultiCell(80, 5, $outputlangs->transnoentitiesnoconv('VereineMinutesChair').': '.$name($roles['chair']), 0, 'L', false, 0);
		$pdf->MultiCell(80, 5, $outputlangs->transnoentitiesnoconv('VereineMinutesKeeper').': '.$name($roles['keeper']), 0, 'L', false, 1);

		VereinePdf::finish($pdf, $outputlangs, $title.' - '.$meeting['title'].($version > 0 ? ', '.$outputlangs->transnoentities('VereineMinutesPdfVersion', $version)
			: ', '.$outputlangs->transnoentitiesnoconv('VereineMinutesDraft')), $code !== '' ? VereineArchive::seal($code) : null);
		$pdf->Output($file, 'F');
		if (!is_file($file)) {
			$this->error = 'cannot write '.$file;
			return '';
		}
		dolChmod($file);
		return $file;
	}
}
