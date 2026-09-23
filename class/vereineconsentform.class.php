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
 * \file    class/vereineconsentform.class.php
 * \ingroup vereine
 * \brief   The declaration of consent as PDF (#110): chosen consents, one page per member.
 *
 * The texts come from the consent setup in their current version, so a new version reaches the form
 * without any change in the code. Who already gave a consent is left out when the form is built for
 * everybody who is still missing it.
 */

require_once __DIR__.'/vereineconsents.class.php';
require_once __DIR__.'/vereineconsentrules.class.php';
require_once __DIR__.'/vereinememberform.class.php';
require_once __DIR__.'/vereineorganization.class.php';
require_once __DIR__.'/vereinepdf.class.php';

/**
 * The declaration of consent as PDF.
 */
class VereineConsentForm
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
	 * Path of the declaration built last.
	 *
	 * @return string
	 */
	public static function path()
	{
		global $conf;

		return DOL_DATA_ROOT.($conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/vereine/consent/einwilligungserklaerung.pdf';
	}

	/**
	 * Active members who have none of the chosen consents given right now.
	 *
	 * @param string[] $codes Purpose codes
	 * @return int[] Members, by their name
	 */
	public function membersMissing(array $codes)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."adherent WHERE entity IN (".getEntity('member').") AND statut = 1";
		$sql .= " ORDER BY lastname, firstname, rowid";
		$members = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$members[] = (int) $obj->rowid;
		}
		if (!$members || !$codes) {
			return $members;
		}
		$consents = new VereineConsents($this->db);
		$missing = array();
		foreach ($members as $member) {
			foreach ($codes as $code) {
				$given = $consents->givenBy(array($member), $code);
				if (empty($given[$member])) {
					$missing[] = $member;
					break;
				}
			}
		}
		return $missing;
	}

	/**
	 * Build the declaration: one page per member, with the chosen consents in their current version.
	 *
	 * @param int[]     $memberIds   Members
	 * @param string[]  $codes       Purpose codes, empty for every active one
	 * @param Translate $outputlangs Language
	 * @param string    $file        Where to write it
	 * @return string Path, empty on error; see $error
	 */
	public function build(array $memberIds, array $codes, $outputlangs, $file)
	{
		global $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
		require_once dirname(__DIR__).'/lib/vereine.lib.php';

		$outputlangs->loadLangs(array('members', 'vereine@vereine'));
		$texts = (new VereineConsents($this->db))->currentTexts();
		if ($codes) {
			$texts = array_intersect_key($texts, array_flip($codes));
		}
		if (!$texts) {
			$this->error = 'no consent text';
			return '';
		}
		if (!$memberIds) {
			$this->error = 'no member';
			return '';
		}
		if (dol_mkdir(dirname($file)) < 0) {
			$this->error = 'cannot create '.dirname($file);
			return '';
		}
		$organization = VereineOrganization::load($mysoc);
		$fillable = VereinePdf::fillable('consent');
		$pdf = VereinePdf::start($outputlangs);
		$font = pdf_getPDFFont($outputlangs);
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$page = 0;
		foreach ($memberIds as $memberId) {
			$member = new Adherent($this->db);
			if ($member->fetch((int) $memberId) <= 0) {
				continue;
			}
			if ($page > 0) {
				$pdf->AddPage();
			}
			$page++;
			VereinePdf::title($pdf, $outputlangs, $outputlangs->transnoentities('VereineConsentFormTitle'), $organization['name']);
			$pdf->SetFont($font, '', 10);
			$pdf->MultiCell(0, 5, $outputlangs->transnoentities('VereineConsentFormFor', $member->getFullName($outputlangs)), 0, 'L');
			$address = trim($member->address.', '.trim($member->zip.' '.$member->town), ' ,');
			if ($address !== '') {
				$pdf->MultiCell(0, 5, $address, 0, 'L');
			}
			$pdf->Ln(2);
			$pdf->SetFont($font, '', 9);
			$pdf->MultiCell(0, 4, $outputlangs->transnoentities('VereineConsentFormIntro'), 0, 'L');

			foreach ($texts as $code => $text) {
				$body = dol_string_nohtmltag($text['text'], 0);
				$pdf->SetFont($font, '', 9);
				// Title, text and the box to tick belong together on one page (#203).
				if ($pdf->GetY() + 8 + $pdf->getStringHeight(170, $body) + 10 > $pdf->getPageHeight() - VereinePdf::BOTTOM - 5) {
					$pdf->AddPage();
				}
				VereinePdf::heading($pdf, $outputlangs, $text['label'].' (v'.((int) $text['version']).')');
				$pdf->SetFont($font, '', 9);
				$pdf->MultiCell(0, 4, $body, 0, 'L');
				VereinePdf::choice($pdf, $outputlangs, 'consent_'.$page.'_'.$code, $outputlangs->transnoentitiesnoconv('VereineConsentFormChoice'), $fillable);
				$pdf->Ln(2);
			}

			$pdf->Ln(2);
			$pdf->SetFont($font, 'I', 8);
			$contact = trim((string) $organization['email']) !== '' ? (string) $organization['email']
				: trim($organization['address']['street'].', '.trim($organization['address']['zip'].' '.$organization['address']['town']), ' ,');
			$pdf->MultiCell(0, 4, $outputlangs->transnoentities('VereineConsentFormWithdraw', $contact !== '' ? $contact : $organization['name']), 0, 'L');
			$birth = !empty($member->birth) ? dol_print_date($member->birth, '%Y-%m-%d', 'tzserver') : '';
			$lines = array('VereineApplicationSignApplicant');
			if (VereineMemberForm::isMinor($birth, $today)) {
				$lines[] = 'VereineApplicationSignGuardian';
			}
			VereinePdf::signatures($pdf, $outputlangs, $lines, 'consent_'.$page, $fillable);
		}
		if ($page === 0) {
			$this->error = 'no member could be read';
			return '';
		}
		VereinePdf::finish($pdf, $outputlangs, $outputlangs->transnoentities('VereineConsentFormTitle'));
		$pdf->Output($file, 'F');
		if (!is_file($file)) {
			$this->error = 'cannot write '.$file;
			return '';
		}
		dolChmod($file);
		return $file;
	}
}
