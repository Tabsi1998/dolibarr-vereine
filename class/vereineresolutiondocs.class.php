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
 * \file    class/vereineresolutiondocs.class.php
 * \ingroup vereine
 * \brief   Every resolution as its own PDF, and several of them as one excerpt.
 *
 * A single resolution can then be handed on - to a bank, to a funding body - without the whole minutes.
 * The PDF names the association, the organ, the day, the wording, the counted votes and the majority the
 * statutes asked for; it goes into a signature run of its own (see VereineSignatures).
 */

require_once __DIR__.'/vereineresolutions.class.php';
require_once __DIR__.'/vereinemeetings.class.php';
require_once __DIR__.'/vereinefunctions.class.php';
require_once __DIR__.'/vereinestatutes.class.php';

/**
 * The PDFs of the register of resolutions.
 */
class VereineResolutionDocs
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
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Where the PDFs of the resolutions live.
	 *
	 * @return string
	 */
	public static function directory()
	{
		global $conf;

		return DOL_DATA_ROOT.($conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/vereine/resolutions';
	}

	/**
	 * Path of the PDF of one resolution.
	 *
	 * @param int $id Resolution
	 * @return string
	 */
	public static function path($id)
	{
		return self::directory().'/beschluss-'.((int) $id).'.pdf';
	}

	/**
	 * What a resolution needs on its PDF beyond the register: how it was voted and what it started.
	 *
	 * @param array<string,mixed> $row Resolution of the register
	 * @return array{time:string,secret:bool,quorum:array<string,mixed>|null,meeting:array<string,mixed>|null,election:string}
	 */
	public function details(array $row)
	{
		$meetings = new VereineMeetings($this->db);
		$meeting = $row['meeting_id'] > 0 ? $meetings->fetch($row['meeting_id']) : null;
		$vote = null;
		foreach ($meeting !== null ? $meetings->votes($meeting['id']) : array() as $candidate) {
			if ($candidate['id'] === $row['vote_id']) {
				$vote = $candidate;
			}
		}
		$quorum = null;
		if ($meeting !== null) {
			$statutes = new VereineStatutes($this->db);
			$attendance = $meetings->attendance($meeting['id']);
			$quorum = VereineAttendanceRules::quorum($meeting['kind'], $attendance['rows'], $attendance['voting'], $statutes->rules(),
				$vote !== null ? $vote['time'] : $meeting['time']);
		}
		return array(
			'time' => $vote !== null ? $vote['time'] : '',
			'secret' => $vote !== null && !empty($vote['secret']),
			'quorum' => $quorum,
			'meeting' => $meeting,
			'election' => $this->election($row),
		);
	}

	/**
	 * The term of office an election started, as a line of text.
	 *
	 * @param array<string,mixed> $row Resolution of the register
	 * @return string Empty when the resolution started no term
	 */
	private function election(array $row)
	{
		global $langs;

		if (strpos((string) $row['applied'], 'term:') !== 0) {
			return '';
		}
		$termId = (int) substr((string) $row['applied'], 5);
		$functions = new VereineFunctions($this->db);
		$catalogue = array();
		foreach ($functions->fetchAll(true) as $function) {
			$catalogue[$function['id']] = $function['label'];
		}
		foreach ($functions->terms() as $term) {
			if ($term['id'] !== $termId) {
				continue;
			}
			$label = isset($catalogue[$term['function_id']]) ? $catalogue[$term['function_id']] : '';
			$until = $term['end'] !== '' ? vereineFormatDay($term['end']) : $langs->transnoentitiesnoconv('VereineFunctionOpen');
			return $langs->transnoentities('VereineResolutionPdfTerm', $label, $term['member_name'], vereineFormatDay($term['start']), $until);
		}
		return '';
	}

	/**
	 * Build the PDF of one resolution; it replaces an earlier one, so a signature run notices the change.
	 *
	 * @param int       $id          Resolution
	 * @param Translate $outputlangs Language of the document
	 * @return string Path of the PDF, empty on error
	 */
	public function build($id, $outputlangs)
	{
		$register = new VereineResolutions($this->db);
		$row = $register->fetch($id);
		if ($row === null) {
			$this->error = 'unknown resolution '.((int) $id);
			return '';
		}
		$file = self::path($row['id']);
		$pdf = $this->open($outputlangs, $file);
		if ($pdf === null) {
			return '';
		}
		$this->section($pdf, $outputlangs, $row, true);
		$this->signatureLines($pdf, $outputlangs);
		return $this->close($pdf, $file);
	}

	/**
	 * Build an excerpt with several resolutions, to hand on as one document.
	 *
	 * @param array<int,array<string,mixed>> $rows        Resolutions of the register, in the order they appear
	 * @param string                         $file        Where to write
	 * @param Translate                      $outputlangs Language of the document
	 * @return string Path of the PDF, empty on error
	 */
	public function buildExcerpt(array $rows, $file, $outputlangs)
	{
		if (!$rows) {
			$this->error = 'no resolution chosen';
			return '';
		}
		$pdf = $this->open($outputlangs, $file, $outputlangs->transnoentitiesnoconv('VereineResolutionExcerptTitle'));
		if ($pdf === null) {
			return '';
		}
		foreach ($rows as $index => $row) {
			$this->section($pdf, $outputlangs, $row, $index === 0);
			$pdf->Ln(4);
		}
		$this->signatureLines($pdf, $outputlangs);
		return $this->close($pdf, $file);
	}

	/**
	 * A document with the association in its head.
	 *
	 * @param Translate $outputlangs Language of the document
	 * @param string    $file        Where it will be written
	 * @param string    $title       Title above the resolutions, empty for a single resolution
	 * @return TCPDF|null
	 */
	private function open($outputlangs, $file, $title = '')
	{
		global $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$outputlangs->load('vereine@vereine');
		if (dol_mkdir(dirname($file)) < 0) {
			$this->error = 'cannot create '.dirname($file);
			return null;
		}
		$pdf = pdf_getInstance();
		$font = pdf_getPDFFont($outputlangs);
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->SetMargins(20, 20, 20);
		$pdf->SetAutoPageBreak(true, 20);
		$pdf->AddPage();
		$pdf->SetFont($font, 'B', 11);
		$pdf->MultiCell(0, 5, trim((string) $mysoc->name), 0, 'L');
		if (getDolGlobalString('VEREINE_REGISTER_NUMBER') !== '') {
			$pdf->SetFont($font, '', 10);
			$pdf->MultiCell(0, 5, $outputlangs->transnoentities('VereineReportRegister', getDolGlobalString('VEREINE_REGISTER_NUMBER')), 0, 'L');
		}
		if ($title !== '') {
			$pdf->Ln(6);
			$pdf->SetFont($font, 'B', 12);
			$pdf->MultiCell(0, 5, $title, 0, 'L');
		}
		return $pdf;
	}

	/**
	 * One resolution with everything it rests on.
	 *
	 * @param TCPDF               $pdf         Document
	 * @param Translate           $outputlangs Language of the document
	 * @param array<string,mixed> $row         Resolution of the register
	 * @param bool                $first       Whether it is the first one in the document
	 * @return void
	 */
	private function section($pdf, $outputlangs, array $row, $first)
	{
		$font = pdf_getPDFFont($outputlangs);
		$line = function ($text, $style = '', $size = 10) use ($pdf, $font) {
			$pdf->SetFont($font, $style, $size);
			$pdf->MultiCell(0, 5, $text, 0, 'L');
		};
		$details = $this->details($row);
		$pdf->Ln($first ? 6 : 2);
		$line($outputlangs->transnoentities('VereineResolutionPdfTitle', $row['ref']), 'B', 12);
		$line($row['title'], 'B');
		$pdf->Ln(2);
		$line($outputlangs->transnoentities('VereineResolutionPdfOrgan', $outputlangs->transnoentitiesnoconv('VereineMeetingKind_'.$row['organ'])));
		if ($details['meeting'] !== null) {
			$line($outputlangs->transnoentities('VereineResolutionPdfMeeting', $details['meeting']['title'],
				vereineMeetingDay($row['day'], $outputlangs), $details['time'] !== '' ? $details['time'] : $details['meeting']['time']));
		} else {
			$line($outputlangs->transnoentities('VereineResolutionPdfDay', vereineMeetingDay($row['day'], $outputlangs)));
		}
		if ($row['item'] > 0) {
			$line($outputlangs->transnoentities('VereineResolutionPdfItem', $row['item']));
		}
		$pdf->Ln(2);
		$line($outputlangs->transnoentitiesnoconv('VereineResolutionWording'), 'B');
		$line($row['wording'] !== '' ? $row['wording'] : $row['title']);
		$pdf->Ln(2);
		$line($outputlangs->transnoentitiesnoconv('VereineResolutionResult'), 'B');
		$line($outputlangs->transnoentitiesnoconv($row['passed'] ? 'VereineResolutionPassed' : 'VereineResolutionRejected').': '
			.$outputlangs->transnoentities('VereineVoteCountsText', $row['yes'], $row['no'], $row['abstain'])
			.($details['secret'] ? ' - '.$outputlangs->transnoentitiesnoconv('VereineVoteSecret') : ''));
		$line($outputlangs->transnoentities('VereineMinutesPdfMajority', $outputlangs->transnoentitiesnoconv('VereineStatuteMajority_'.$row['majority'])));
		if (!empty($row['money'])) {
			$line($outputlangs->transnoentitiesnoconv('VereineResolutionMoneyLabel'));
		}
		if ($details['quorum'] !== null) {
			$quorum = $details['quorum'];
			$line($outputlangs->transnoentities('VereineMinutesPdfAttendance', $quorum['present'], $quorum['represented'], $quorum['votes'], $quorum['eligible']));
			$line($outputlangs->transnoentitiesnoconv($quorum['reached'] ? 'VereineAttendanceReached' : 'VereineAttendanceMissing'));
		}
		if ($details['election'] !== '') {
			$pdf->Ln(2);
			$line($outputlangs->transnoentitiesnoconv('VereineResolutionPdfElection'), 'B');
			$line($details['election']);
		}
		if ($row['valid_from'] !== '' || $row['valid_to'] !== '') {
			$pdf->Ln(2);
			$line($outputlangs->transnoentities('VereineResolutionPdfValidity',
				vereineFormatDay($row['valid_from'] !== '' ? $row['valid_from'] : $row['day']),
				$row['valid_to'] !== '' ? vereineFormatDay($row['valid_to']) : $outputlangs->transnoentitiesnoconv('VereineResolutionPdfOpenEnd')));
		}
	}

	/**
	 * Lines to sign on paper, with the day the document was written.
	 *
	 * @param TCPDF     $pdf         Document
	 * @param Translate $outputlangs Language of the document
	 * @return void
	 */
	private function signatureLines($pdf, $outputlangs)
	{
		$font = pdf_getPDFFont($outputlangs);
		$pdf->Ln(8);
		$pdf->SetFont($font, '', 10);
		$pdf->MultiCell(0, 5, $outputlangs->transnoentities('VereineResolutionPdfWritten',
			vereineFormatDay(dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'))), 0, 'L');
		$pdf->Ln(8);
		$pdf->MultiCell(80, 5, '______________________', 0, 'L', false, 0);
		$pdf->MultiCell(80, 5, '______________________', 0, 'L', false, 1);
	}

	/**
	 * Write the document.
	 *
	 * @param TCPDF  $pdf  Document
	 * @param string $file Where to write
	 * @return string Path of the PDF, empty on error
	 */
	private function close($pdf, $file)
	{
		$pdf->Output($file, 'F');
		if (!is_file($file)) {
			$this->error = 'cannot write '.$file;
			return '';
		}
		dolChmod($file);
		return $file;
	}
}
