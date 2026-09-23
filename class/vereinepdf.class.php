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
	 * @param string    $note        Small grey note on the right, such as the version of a text; may be empty
	 * @param float     $keep        Room in mm the heading needs under it on the same page (#202)
	 * @return void
	 */
	public static function heading($pdf, $outputlangs, $text, $note = '', $keep = 25)
	{
		$font = pdf_getPDFFont($outputlangs);
		self::keep($pdf, $keep + 10);
		$pdf->Ln(2);
		$pdf->SetFont($font, 'B', 11);
		if ((string) $note !== '') {
			$pdf->MultiCell(135, 6, $text, 0, 'L', false, 0);
			self::note($pdf, $outputlangs, $note, 35, 6);
		} else {
			$pdf->MultiCell(0, 6, $text, 0, 'L');
		}
		$y = $pdf->GetY();
		$pdf->SetDrawColor(190, 190, 190);
		$pdf->Line(self::SIDE, $y, $pdf->getPageWidth() - self::SIDE, $y);
		$pdf->Ln(1.5);
		$pdf->SetFont($font, '', 10);
	}

	/**
	 * A new page when the rest of this one is too short for what belongs together (#202).
	 *
	 * @param TCPDF $pdf    Document
	 * @param float $needed Room in mm
	 * @return void
	 */
	public static function keep($pdf, $needed)
	{
		if ($pdf->GetY() + (float) $needed > $pdf->getPageHeight() - self::BOTTOM - 5) {
			$pdf->AddPage();
		}
	}

	/**
	 * A bold line with a small grey note at its right end, such as a consent and its version.
	 *
	 * @param TCPDF     $pdf         Document
	 * @param Translate $outputlangs Language of the document
	 * @param string    $text        Bold text
	 * @param string    $note        Note, may be empty
	 * @return void
	 */
	public static function label($pdf, $outputlangs, $text, $note)
	{
		$pdf->SetFont(pdf_getPDFFont($outputlangs), 'B', 10);
		if ((string) $note === '') {
			$pdf->MultiCell(0, 5, $text, 0, 'L');
			return;
		}
		$pdf->MultiCell(135, 5, $text, 0, 'L', false, 0);
		self::note($pdf, $outputlangs, $note, 35, 5);
	}

	/**
	 * A small grey note, right aligned, ending the line.
	 *
	 * @param TCPDF     $pdf         Document
	 * @param Translate $outputlangs Language of the document
	 * @param string    $note        Note
	 * @param float     $width       Width in mm
	 * @param float     $height      Height of the line in mm
	 * @return void
	 */
	private static function note($pdf, $outputlangs, $note, $width, $height)
	{
		$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 8);
		$pdf->SetTextColor(120, 120, 120);
		$pdf->MultiCell($width, $height, $note, 0, 'R', false, 1);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 10);
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
	 * A line to write on, then on to the next line.
	 *
	 * @param TCPDF  $pdf      Document
	 * @param string $name     Name of the field, unique in the document
	 * @param float  $width    Width in mm
	 * @param bool   $fillable Whether the document carries fields
	 * @param float  $height   Height in mm
	 * @param string $value    What is known already, bold on the line; no field then
	 * @return void
	 */
	public static function input($pdf, $name, $width, $fillable, $height = 6, $value = '')
	{
		$x = $pdf->GetX();
		$y = $pdf->GetY();
		self::line($pdf, $name, $width, $fillable, $height, $value);
		$pdf->SetXY($x + $width, $y);
		$pdf->Ln($height);
	}

	/**
	 * A line to write on where the cursor stands, and the cursor right after it.
	 *
	 * The line is always drawn: printed, and on a screen that draws no fields, the sheet looks the same.
	 * A fillable document lays a field without a frame over it (#202).
	 *
	 * @param TCPDF  $pdf      Document
	 * @param string $name     Name of the field, unique in the document
	 * @param float  $width    Width in mm
	 * @param bool   $fillable Whether the document carries fields
	 * @param float  $height   Height in mm
	 * @param string $value    What is known already, bold on the line; no field then
	 * @return void
	 */
	public static function line($pdf, $name, $width, $fillable, $height = 6, $value = '')
	{
		$x = $pdf->GetX();
		$y = $pdf->GetY();
		$pdf->SetDrawColor(150, 150, 150);
		$pdf->SetLineWidth(0.2);
		$pdf->Line($x, $y + $height - 1.5, $x + $width, $y + $height - 1.5);
		if ((string) $value !== '') {
			$family = $pdf->getFontFamily();
			$size = $pdf->getFontSizePt();
			$pdf->SetFont($family, 'B', $size);
			$pdf->SetXY($x + 1, $y);
			$pdf->Cell($width - 1, $height - 1.5, (string) $value, 0, 0, 'L', false, '', 1, false, 'T', 'B');
			$pdf->SetFont($family, '', $size);
		} elseif ($fillable) {
			$pdf->TextField($name, $width, $height - 1.5, array('lineWidth' => 0, 'borderStyle' => 'none'), array(), $x, $y);
		}
		$pdf->SetXY($x + $width, $y);
	}

	/**
	 * A box to tick where the cursor stands: drawn always, a check box over it when the document is fillable.
	 *
	 * @param TCPDF  $pdf      Document
	 * @param string $name     Name of the field, unique in the document
	 * @param bool   $fillable Whether the document carries fields
	 * @param string $export   Value of the ticked box
	 * @return void
	 */
	public static function box($pdf, $name, $fillable, $export = 'Ja')
	{
		$x = $pdf->GetX();
		$y = $pdf->GetY();
		$pdf->SetDrawColor(90, 90, 90);
		$pdf->SetLineWidth(0.25);
		$pdf->Rect($x, $y + 1, 4, 4);
		if ($fillable) {
			$pdf->CheckBox($name, 4, false, array('lineWidth' => 0, 'borderStyle' => 'none'), array(), $export, $x, $y + 1);
		}
		$pdf->SetXY($x + 6, $y);
	}

	/**
	 * One box to tick with its text, for something that is only agreed to, such as the statutes (#202).
	 *
	 * @param TCPDF     $pdf         Document
	 * @param Translate $outputlangs Language of the document
	 * @param string    $name        Name of the field, unique in the document
	 * @param string    $label       What is agreed to
	 * @param bool      $fillable    Whether the document carries fields
	 * @return void
	 */
	public static function tick($pdf, $outputlangs, $name, $label, $fillable)
	{
		$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 10);
		self::box($pdf, $name, $fillable);
		$pdf->MultiCell(0, 6, $label, 0, 'L', false, 1);
	}

	/**
	 * A yes or no to tick: the question, then a box with Ja and a box with Nein, printed as on the
	 * screen. The label never carries boxes itself, so nothing shows twice (#202, #203).
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
		self::box($pdf, $name.'_ja', $fillable, 'Ja');
		$pdf->MultiCell(14, 6, $outputlangs->transnoentitiesnoconv('Yes'), 0, 'L', false, 0);
		self::box($pdf, $name.'_nein', $fillable, 'Nein');
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
		// The whole block on one page: nobody signs a page that does not show what is signed.
		self::keep($pdf, 8 + 17 * count($lines));
		$pdf->Ln(6);
		foreach (array_values($lines) as $index => $line) {
			$pdf->SetX(self::SIDE);
			$pdf->SetFont($font, '', 10);
			self::line($pdf, $prefix.'_ort_datum'.($index > 0 ? '_'.$index : ''), 60, $fillable, 10);
			$pdf->SetX(self::SIDE + 70);
			self::line($pdf, $prefix.'_'.dol_sanitizeFileName(strtolower($line)), 100, $fillable, 10);
			$pdf->Ln(10);
			$pdf->SetFont($font, '', 8);
			$pdf->SetX(self::SIDE);
			$pdf->MultiCell(60, 4, $outputlangs->transnoentitiesnoconv('VereineApplicationPlaceDay'), 0, 'L', false, 0);
			$pdf->SetX(self::SIDE + 70);
			$pdf->MultiCell(100, 4, $outputlangs->transnoentitiesnoconv($line), 0, 'L', false, 1);
			$pdf->Ln(3);
		}
		$pdf->SetFont($font, '', 10);
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
		$contact = array_filter(array(trim((string) $mysoc->email), trim((string) $mysoc->phone), trim((string) $mysoc->url)), 'strlen');
		if ($contact) {
			$lines[] = implode(' · ', $contact);
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
