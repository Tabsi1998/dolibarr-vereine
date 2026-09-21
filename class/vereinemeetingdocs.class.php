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
 * \file    class/vereinemeetingdocs.class.php
 * \ingroup vereine
 * \brief   Documents of a meeting: proof of a vote, signed proxies, and the count sheet as a PDF.
 *
 * A file is kept with its checksum and never replaced: what was uploaded stays as it was. The count
 * sheet is the other way round - it is a working paper, printed before the meeting and handed out, not
 * kept; what comes back from the meeting is the filled sheet, scanned and uploaded as proof of the vote.
 */

require_once __DIR__.'/vereinemeetingdocrules.class.php';
require_once __DIR__.'/vereinemeetings.class.php';
require_once __DIR__.'/vereinepdf.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Documents of a meeting.
 */
class VereineMeetingDocs
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
	 * @var string[] Language keys of what was refused
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
	 * Where the documents of a meeting live.
	 *
	 * @param int $meetingId Meeting
	 * @return string
	 */
	public static function directory($meetingId)
	{
		global $conf;

		return DOL_DATA_ROOT.($conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/vereine/meetings/'.((int) $meetingId);
	}

	/**
	 * Path of a document.
	 *
	 * @param array<string,mixed> $row Document
	 * @return string
	 */
	public static function path(array $row)
	{
		return self::directory((int) $row['meeting_id']).'/'.$row['filename'];
	}

	/**
	 * The documents of a meeting, oldest first.
	 *
	 * @param int $meetingId Meeting
	 * @return array<int,array<string,mixed>>
	 */
	public function all($meetingId)
	{
		global $conf;

		$sql = "SELECT rowid, fk_meeting, fk_vote, fk_adherent, kind, label, filename, doc_sha, datec FROM ".MAIN_DB_PREFIX."vereine_meeting_document";
		$sql .= " WHERE fk_meeting = ".((int) $meetingId)." AND entity = ".((int) $conf->entity)." ORDER BY rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'id' => (int) $obj->rowid,
				'meeting_id' => (int) $obj->fk_meeting,
				'vote_id' => (int) $obj->fk_vote,
				'member_id' => (int) $obj->fk_adherent,
				'kind' => (string) $obj->kind,
				'label' => (string) $obj->label,
				'filename' => (string) $obj->filename,
				'sha' => (string) $obj->doc_sha,
				'date' => (int) $this->db->jdate($obj->datec),
			);
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * One document.
	 *
	 * @param int $id Document
	 * @return array<string,mixed>|null
	 */
	public function fetch($id)
	{
		global $conf;

		$sql = "SELECT fk_meeting FROM ".MAIN_DB_PREFIX."vereine_meeting_document WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		$meetingId = 0;
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$meetingId = (int) $obj->fk_meeting;
		}
		foreach ($meetingId > 0 ? $this->all($meetingId) : array() as $row) {
			if ($row['id'] === (int) $id) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The documents of one vote.
	 *
	 * @param int $meetingId Meeting
	 * @param int $voteId    Vote
	 * @return array<int,array<string,mixed>>
	 */
	public function forVote($meetingId, $voteId)
	{
		$rows = array();
		foreach ($this->all($meetingId) as $row) {
			if ($row['kind'] === VereineMeetingDocRules::KIND_VOTE && $row['vote_id'] === (int) $voteId) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * The proxies of one member at a meeting.
	 *
	 * @param int $meetingId Meeting
	 * @param int $memberId  Member who gave the proxy
	 * @return array<int,array<string,mixed>>
	 */
	public function forMember($meetingId, $memberId)
	{
		$rows = array();
		foreach ($this->all($meetingId) as $row) {
			if ($row['kind'] === VereineMeetingDocRules::KIND_PROXY && $row['member_id'] === (int) $memberId) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Take a file and keep it with its checksum.
	 *
	 * @param int                 $meetingId Meeting
	 * @param string              $kind      One of the VereineMeetingDocRules KIND constants
	 * @param int                 $objectId  Vote for a proof, member for a proxy, 0 for the meeting itself
	 * @param string              $label     What the file is
	 * @param array<string,mixed> $upload    One entry of $_FILES
	 * @param User                $user      Who uploads
	 * @return int Id of the document, 0 when refused (see errors), -1 on error
	 */
	public function upload($meetingId, $kind, $objectId, $label, array $upload, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$this->errors = array();
		$meetings = new VereineMeetings($this->db);
		$meeting = $meetings->fetch($meetingId);
		if ($meeting === null) {
			$this->errors[] = 'VereineMeetingDocErrorMeeting';
			return 0;
		}
		if (!in_array((string) $kind, VereineMeetingDocRules::KINDS, true)) {
			$this->errors[] = 'VereineMeetingDocErrorKindUnknown';
			return 0;
		}
		if ((string) $kind === VereineMeetingDocRules::KIND_VOTE && !$this->knownVote($meetingId, $objectId)) {
			$this->errors[] = 'VereineMeetingDocErrorVote';
			return 0;
		}
		if ((string) $kind === VereineMeetingDocRules::KIND_PROXY && !isset($meetings->attendance($meetingId)['rows'][(int) $objectId])) {
			$this->errors[] = 'VereineMeetingDocErrorMember';
			return 0;
		}
		$this->errors = VereineMeetingDocRules::check($upload);
		if ($this->errors) {
			return 0;
		}
		$dir = self::directory($meetingId);
		if (dol_mkdir($dir) < 0) {
			$this->error = 'cannot create '.$dir;
			return -1;
		}
		$name = VereineMeetingDocRules::name($kind, $objectId, (string) $upload['name'], dol_print_date(dol_now(), '%Y%m%d-%H%M%S', 'tzserver'));
		if (dol_move_uploaded_file((string) $upload['tmp_name'], $dir.'/'.$name, 1, 0, 0, 0) != 1) {
			$this->errors[] = 'VereineMeetingDocErrorStore';
			return 0;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_meeting_document (entity, fk_meeting, fk_vote, fk_adherent, kind, label, filename, doc_sha, datec, fk_user_modif)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $meetingId).",";
		$sql .= " ".((string) $kind === VereineMeetingDocRules::KIND_VOTE ? (int) $objectId : 0).",";
		$sql .= " ".((string) $kind === VereineMeetingDocRules::KIND_PROXY ? (int) $objectId : 0).", '".$this->db->escape((string) $kind)."',";
		$sql .= " '".$this->db->escape(VereineMeetingDocRules::label($label))."', '".$this->db->escape($name)."',";
		$sql .= " '".$this->db->escape(hash_file('sha256', $dir.'/'.$name))."', '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_meeting_document');
		VereineLog::add($this->db, $user, VereineLog::MEETING_DOCUMENT, (string) $kind === VereineMeetingDocRules::KIND_PROXY ? (int) $objectId : 0, 0,
			$meeting['title'].': '.$kind.' '.$name);
		return $id;
	}

	/**
	 * Whether a vote belongs to a meeting.
	 *
	 * @param int $meetingId Meeting
	 * @param int $voteId    Vote
	 * @return bool
	 */
	private function knownVote($meetingId, $voteId)
	{
		$meetings = new VereineMeetings($this->db);
		foreach ($meetings->votes($meetingId) as $vote) {
			if ($vote['id'] === (int) $voteId) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the count sheet of a vote or an election to print before the meeting.
	 *
	 * @param array<string,mixed> $meeting     Meeting
	 * @param array<string,mixed> $sheet       Normalized count sheet
	 * @param string              $file        Where to write
	 * @param Translate           $outputlangs Language of the sheet
	 * @return string Path of the PDF, empty on error
	 */
	public function buildSheet(array $meeting, array $sheet, $file, $outputlangs)
	{
		global $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$this->errors = VereineMeetingDocRules::validateSheet($sheet);
		if ($this->errors) {
			return '';
		}
		$outputlangs->load('vereine@vereine');
		if (dol_mkdir(dirname($file)) < 0) {
			$this->error = 'cannot create '.dirname($file);
			return '';
		}
		$pdf = VereinePdf::start($outputlangs);
		$font = pdf_getPDFFont($outputlangs);
		$line = function ($text, $style = '', $size = 10) use ($pdf, $font) {
			$pdf->SetFont($font, $style, $size);
			$pdf->MultiCell(0, 5, $text, 0, 'L');
		};

		VereinePdf::title($pdf, $outputlangs, $outputlangs->transnoentitiesnoconv('VereineMeetingDocSheetTitle'),
			$meeting['title'].' - '.vereineMeetingDay($meeting['day'], $outputlangs).' '.$meeting['time']);
		$line($outputlangs->transnoentities('VereineMeetingDocSheetQuestion', $sheet['question']), 'B');
		if ($sheet['item'] > 0) {
			$line($outputlangs->transnoentities('VereineResolutionPdfItem', $sheet['item']));
		}
		if ($sheet['candidates']) {
			$line($outputlangs->transnoentitiesnoconv('VereineMeetingDocSheetCandidates'), 'B');
			foreach ($sheet['candidates'] as $index => $candidate) {
				$line(($index + 1).'. '.$candidate);
			}
		}
		$pdf->Ln(4);

		// The table to fill in by hand: one line per counted ballot.
		$columns = array($outputlangs->transnoentitiesnoconv('VereineMeetingDocSheetNumber') => 15,
			$outputlangs->transnoentitiesnoconv('VereineVoteYes') => 35,
			$outputlangs->transnoentitiesnoconv('VereineVoteNo') => 35,
			$outputlangs->transnoentitiesnoconv('VereineVoteAbstain') => 35,
			$outputlangs->transnoentitiesnoconv('VereineMeetingDocSheetNote') => 50);
		$pdf->SetFont($font, 'B', 9);
		$last = count($columns);
		$index = 0;
		foreach ($columns as $title => $width) {
			$index++;
			$pdf->MultiCell($width, 6, $title, 1, 'L', false, $index === $last ? 1 : 0);
		}
		$pdf->SetFont($font, '', 9);
		for ($row = 1; $row <= (int) $sheet['rows']; $row++) {
			$index = 0;
			foreach ($columns as $title => $width) {
				$index++;
				$pdf->MultiCell($width, 6, $index === 1 ? (string) $row : '', 1, 'L', false, $index === $last ? 1 : 0);
			}
		}
		$pdf->Ln(4);
		$line($outputlangs->transnoentitiesnoconv('VereineMeetingDocSheetTotals'), 'B');
		$line($outputlangs->transnoentitiesnoconv('VereineMeetingDocSheetTotalsLine'));
		$pdf->Ln(8);
		$line($outputlangs->transnoentitiesnoconv('VereineMeetingDocSheetSignatures'), 'B');
		$pdf->Ln(6);
		$pdf->SetFont($font, '', 10);
		$pdf->MultiCell(80, 5, '______________________', 0, 'L', false, 0);
		$pdf->MultiCell(80, 5, '______________________', 0, 'L', false, 1);
		$pdf->MultiCell(80, 5, $outputlangs->transnoentitiesnoconv('VereineMeetingDocSheetLeader'), 0, 'L', false, 0);
		$pdf->MultiCell(80, 5, $outputlangs->transnoentitiesnoconv('VereineMeetingDocSheetCounter'), 0, 'L', false, 1);

		VereinePdf::finish($pdf, $outputlangs, $outputlangs->transnoentitiesnoconv('VereineMeetingDocSheetTitle').' - '.$meeting['title']);
		$pdf->Output($file, 'F');
		if (!is_file($file)) {
			$this->error = 'cannot write '.$file;
			return '';
		}
		dolChmod($file);
		return $file;
	}
}
