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
 * \file    class/vereinepdf.class.php
 * \ingroup vereine
 * \brief   One look for the PDFs of the association: logo on the right, name and register number on the left, page numbers.
 *
 * Everything comes from Dolibarr: the logo and the address of the association (Setup - Company/Organization),
 * the font and the text colour of Dolibarr's PDF settings. The head and the foot are drawn after the content,
 * on every page, into margins kept free for them - so a page break never runs into the logo.
 */

/**
 * Head and foot of the PDFs of the association.
 */
class VereinePdf
{
	/** Room kept free above the content for the head, in mm. */
	const TOP = 42;
	/** Room kept free below the content for the foot, in mm. */
	const BOTTOM = 20;
	/** Left and right margin, in mm. */
	const SIDE = 20;

	/** Documents that can carry fields to fill in on the screen (#107). */
	const FILLABLE_KINDS = array('application', 'consent');
	/** Setting: which documents carry those fields, comma separated. */
	const FILLABLE = 'VEREINE_PDF_FILLABLE';

	/**
	 * A new document with room for head and foot.
	 *
	 * @param Translate $outputlangs Language of the document
	 * @return TCPDF
	 */
	public static function start($outputlangs)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

		$pdf = pdf_getInstance();
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->SetMargins(self::SIDE, self::TOP, self::SIDE);
		$pdf->SetAutoPageBreak(true, self::BOTTOM + 5);
		$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 10);
		$pdf->AddPage();
		return $pdf;
	}

	/**
	 * A title under the head: large, with a little room.
	 *
	 * @param TCPDF     $pdf         Document
	 * @param Translate $outputlangs Language of the document
	 * @param string    $title       Title
	 * @param string    $subtitle    Line under the title, may be empty
	 * @return void
	 */
	public static function title($pdf, $outputlangs, $title, $subtitle = '')
	{
		$font = pdf_getPDFFont($outputlangs);
		$pdf->SetFont($font, 'B', 14);
		$pdf->MultiCell(0, 7, $title, 0, 'L');
		if ((string) $subtitle !== '') {
			$pdf->SetFont($font, '', 10);
			$pdf->MultiCell(0, 5, $subtitle, 0, 'L');
		}
		$pdf->Ln(3);
		$pdf->SetFont($font, '', 10);
	}

	/**
	 * A heading of a section, with a thin line under it.
	 *
	 * @param TCPDF     $pdf         Document
	 * @param Translate $outputlangs Language of the document
	 * @param string    $text        Heading
	 * @return void
	 */
	public static function heading($pdf, $outputlangs, $text)
	{
		$font = pdf_getPDFFont($outputlangs);
		$pdf->Ln(2);
		$pdf->SetFont($font, 'B', 11);
		$pdf->MultiCell(0, 6, $text, 0, 'L');
		$y = $pdf->GetY();
		$pdf->SetDrawColor(190, 190, 190);
		$pdf->Line(self::SIDE, $y, $pdf->getPageWidth() - self::SIDE, $y);
		$pdf->Ln(1.5);
		$pdf->SetFont($font, '', 10);
	}

	/**
	 * Draw head and foot on every page, then the document is ready to be written.
	 *
	 * @param TCPDF     $pdf         Document
	 * @param Translate $outputlangs Language of the document
	 * @param string    $what        What the document is, for the foot (for instance "Protokoll, Fassung 1")
	 * @return void
	 */
	public static function finish($pdf, $outputlangs, $what)
	{
		$pages = $pdf->getNumPages();
		for ($page = 1; $page <= $pages; $page++) {
			$pdf->setPage($page);
			// Head and foot lie in the margins; switch the page break off while drawing there.
			$pdf->SetAutoPageBreak(false);
			self::head($pdf, $outputlangs);
			self::foot($pdf, $outputlangs, $what, $page, $pages);
			$pdf->SetAutoPageBreak(true, self::BOTTOM + 5);
		}
		$pdf->lastPage();
	}

	/**
	 * Which documents carry fillable fields, as the setup stores it.
	 *
	 * @param mixed $entered Comma separated list or array of kinds; null reads the setting
	 * @return string[] Kinds of FILLABLE_KINDS, in their order
	 */
	public static function fillableKinds($entered = null)
	{
		$value = $entered === null ? getDolGlobalString(self::FILLABLE) : $entered;
		$list = is_array($value) ? $value : explode(',', (string) $value);
		$kinds = array();
		foreach (self::FILLABLE_KINDS as $kind) {
			foreach ($list as $one) {
				if (is_scalar($one) && trim((string) $one) === $kind) {
					$kinds[] = $kind;
					break;
				}
			}
		}
		return $kinds;
	}

	/**
	 * Whether a document is built with fillable fields.
	 *
	 * @param string $kind One of FILLABLE_KINDS
	 * @return bool
	 */
	public static function fillable($kind)
	{
		return in_array($kind, self::fillableKinds(), true);
	}

	/**
	 * A field to write in, or a line to write on when the document is only printed.
	 *
	 * @param TCPDF  $pdf      Document
	 * @param string $name     Name of the field, unique in the document
	 * @param float  $width    Width in mm
	 * @param bool   $fillable Whether the document carries fields
	 * @param float  $height   Height in mm
	 * @return void
	 */
	public static function input($pdf, $name, $width, $fillable, $height = 6)
	{
		$x = $pdf->GetX();
		$y = $pdf->GetY();
		if ($fillable) {
			$pdf->TextField($name, $width, $height, array('lineWidth' => 0.1, 'borderStyle' => 'solid', 'strokeColor' => array(190, 190, 190)), array(), $x, $y);
		} else {
			// A thin line to write on reads better than a row of underscores.
			$pdf->SetDrawColor(150, 150, 150);
			$pdf->SetLineWidth(0.2);
			$pdf->Line($x, $y + $height - 1.5, $x + $width, $y + $height - 1.5);
		}
		$pdf->SetXY($x + $width, $y);
		$pdf->Ln($height);
	}

	/**
	 * A yes or no to tick: two boxes to tick, or two brackets when the document is only printed. The
	 * label never carries the brackets itself, so a fillable form does not show them twice (#203).
	 *
	 * @param TCPDF     $pdf         Document
	 * @param Translate $outputlangs Language of the document
	 * @param string    $name        Name of the field, unique in the document
	 * @param string    $label       What is agreed to
	 * @param bool      $fillable    Whether the document carries fields
	 * @return void
	 */
	public static function choice($pdf, $outputlangs, $name, $label, $fillable)
	{
		$font = pdf_getPDFFont($outputlangs);
		$pdf->SetFont($font, '', 10);
		$pdf->MultiCell(110, 6, $label, 0, 'L', false, 0);
		if (!$fillable) {
			$pdf->MultiCell(60, 6, $outputlangs->transnoentitiesnoconv('VereineApplicationYesNo'), 0, 'L', false, 1);
			return;
		}
		$x = $pdf->GetX();
		$y = $pdf->GetY();
		$pdf->CheckBox($name.'_ja', 4, false, array(), array(), 'Ja', $x, $y + 1);
		$pdf->SetXY($x + 6, $y);
		$pdf->MultiCell(14, 6, $outputlangs->transnoentitiesnoconv('Yes'), 0, 'L', false, 0);
		$x = $pdf->GetX();
		$pdf->CheckBox($name.'_nein', 4, false, array(), array(), 'Nein', $x, $y + 1);
		$pdf->SetXY($x + 6, $y);
		$pdf->MultiCell(20, 6, $outputlangs->transnoentitiesnoconv('No'), 0, 'L', false, 1);
	}

	/**
	 * Place, day and the lines to sign; with fields to sign in when the document is fillable.
	 *
	 * @param TCPDF     $pdf         Document
	 * @param Translate $outputlangs Language of the document
	 * @param string[]  $lines       Language keys under the signature lines
	 * @param string    $prefix      Prefix of the field names, unique in the document
	 * @param bool      $fillable    Whether the document carries fields
	 * @return void
	 */
	public static function signatures($pdf, $outputlangs, array $lines, $prefix, $fillable)
	{
		$font = pdf_getPDFFont($outputlangs);
		$pdf->Ln(4);
		$pdf->SetFont($font, '', 10);
		$pdf->MultiCell(45, 6, $outputlangs->transnoentitiesnoconv('VereineApplicationPlaceDay').':', 0, 'L', false, 0);
		self::input($pdf, $prefix.'_ort_datum', 80, $fillable);
		$pdf->Ln(4);
		foreach ($lines as $line) {
			self::input($pdf, $prefix.'_'.dol_sanitizeFileName(strtolower($line)), 85, $fillable, 10);
			$pdf->SetFont($font, '', 8);
			$pdf->MultiCell(85, 4, $outputlangs->transnoentitiesnoconv($line), 0, 'L');
			$pdf->Ln(3);
		}
	}

	/**
	 * The logo of the association, if Dolibarr has one: the small one when there is, else the large one.
	 *
	 * @return string Path of the image, empty when there is none
	 */
	public static function logo()
	{
		global $conf, $mysoc;

		$dir = isset($conf->mycompany->dir_output) ? (string) $conf->mycompany->dir_output : DOL_DATA_ROOT.'/mycompany';
		$candidates = array();
		if (!empty($mysoc->logo_small)) {
			$candidates[] = $dir.'/logos/thumbs/'.$mysoc->logo_small;
		}
		if (!empty($mysoc->logo)) {
			$candidates[] = $dir.'/logos/'.$mysoc->logo;
		}
		foreach ($candidates as $file) {
			if (is_readable($file) && @getimagesize($file) !== false) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Name, register number and address on the left, the logo on the right, a thin line under both.
	 *
	 * @param TCPDF     $pdf         Document
	 * @param Translate $outputlangs Language of the document
	 * @return void
	 */
	private static function head($pdf, $outputlangs)
	{
		global $mysoc;

		$font = pdf_getPDFFont($outputlangs);
		$width = $pdf->getPageWidth();
		$logo = self::logo();
		$logoWidth = 0;
		if ($logo !== '') {
			$size = getimagesize($logo);
			$height = 18;
			$logoWidth = $size[1] > 0 ? min(60, $height * $size[0] / $size[1]) : 0;
			if ($logoWidth > 0) {
				$pdf->Image($logo, $width - self::SIDE - $logoWidth, 12, $logoWidth, 0);
			}
		}
		$pdf->SetXY(self::SIDE, 13);
		$pdf->SetFont($font, 'B', 12);
		$pdf->MultiCell($width - 2 * self::SIDE - $logoWidth - 4, 6, trim((string) $mysoc->name), 0, 'L');
		$pdf->SetFont($font, '', 8.5);
		$lines = array();
		if (getDolGlobalString('VEREINE_REGISTER_NUMBER') !== '') {
			$lines[] = $outputlangs->transnoentities('VereineReportRegister', getDolGlobalString('VEREINE_REGISTER_NUMBER'));
		}
		$address = trim(implode(', ', array_filter(array(trim((string) $mysoc->address), trim(trim((string) $mysoc->zip).' '.trim((string) $mysoc->town))))));
		if ($address !== '') {
			$lines[] = str_replace(array("\r\n", "\n"), ', ', $address);
		}
		if (trim((string) $mysoc->email) !== '') {
			$lines[] = trim((string) $mysoc->email);
		}
		$pdf->SetX(self::SIDE);
		$pdf->MultiCell($width - 2 * self::SIDE - $logoWidth - 4, 4, implode("\n", $lines), 0, 'L');
		$pdf->SetDrawColor(160, 160, 160);
		$pdf->Line(self::SIDE, self::TOP - 6, $width - self::SIDE, self::TOP - 6);
	}

	/**
	 * What the document is on the left, page x of y on the right.
	 *
	 * @param TCPDF     $pdf         Document
	 * @param Translate $outputlangs Language of the document
	 * @param string    $what        What the document is
	 * @param int       $page        This page
	 * @param int       $pages       All pages
	 * @return void
	 */
	private static function foot($pdf, $outputlangs, $what, $page, $pages)
	{
		$font = pdf_getPDFFont($outputlangs);
		$width = $pdf->getPageWidth();
		$y = $pdf->getPageHeight() - self::BOTTOM + 6;
		$pdf->SetDrawColor(200, 200, 200);
		$pdf->Line(self::SIDE, $y - 2, $width - self::SIDE, $y - 2);
		$pdf->SetFont($font, '', 8);
		$pdf->SetXY(self::SIDE, $y);
		$pdf->Cell(($width - 2 * self::SIDE) * 0.7, 4, (string) $what, 0, 0, 'L');
		$pdf->Cell(($width - 2 * self::SIDE) * 0.3, 4, $outputlangs->transnoentities('VereinePdfPage', $page, $pages), 0, 0, 'R');
	}
}
