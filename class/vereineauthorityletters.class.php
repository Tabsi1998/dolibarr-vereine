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
 * \file    class/vereineauthorityletters.class.php
 * \ingroup vereine
 * \brief   Letters of the association to its association authority: one layout, PDF, deadline and a note once sent.
 */

require_once __DIR__.'/vereineauthorityrules.class.php';
require_once __DIR__.'/vereinefunctions.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Writes and keeps letters to the association authority.
 */
class VereineAuthorityLetters
{
	/** Postal address of the authority, lines separated by line breaks. */
	const CONST_ADDRESS = 'VEREINE_AUTHORITY_ADDRESS';
	/** E-mail address of the authority. */
	const CONST_EMAIL = 'VEREINE_AUTHORITY_EMAIL';
	/** File number (GZ) of the association at the authority. */
	const CONST_GZ = 'VEREINE_AUTHORITY_GZ';

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
	 * The association authority as stored.
	 *
	 * @return array{name:string,address:string,email:string,gz:string}
	 */
	public function authority()
	{
		return array('name' => getDolGlobalString('VEREINE_AUTHORITY'), 'address' => getDolGlobalString(self::CONST_ADDRESS),
			'email' => getDolGlobalString(self::CONST_EMAIL), 'gz' => getDolGlobalString(self::CONST_GZ));
	}

	/**
	 * Store the association authority.
	 *
	 * @param string $name    Name
	 * @param string $address Postal address
	 * @param string $email   E-mail address, may be empty
	 * @param string $gz      File number, may be empty
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function saveAuthority($name, $address, $email, $gz)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$values = array('VEREINE_AUTHORITY' => mb_substr(trim((string) $name), 0, 128, 'UTF-8'), self::CONST_ADDRESS => mb_substr(trim(str_replace("\r", '', (string) $address)), 0, 255, 'UTF-8'),
			self::CONST_EMAIL => trim((string) $email), self::CONST_GZ => mb_substr(trim((string) $gz), 0, 64, 'UTF-8'));
		$this->errors = array();
		if ($values['VEREINE_AUTHORITY'] === '' || $values[self::CONST_ADDRESS] === '') {
			$this->errors[] = 'VereineLetterErrorAuthority';
		}
		if ($values[self::CONST_EMAIL] !== '' && !filter_var($values[self::CONST_EMAIL], FILTER_VALIDATE_EMAIL)) {
			$this->errors[] = 'VereineLetterErrorAuthorityEmail';
		}
		if ($this->errors) {
			return 0;
		}
		foreach ($values as $constant => $value) {
			if (dolibarr_set_const($this->db, $constant, $value, 'chaine', 0, '', $conf->entity) < 0) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		return 1;
	}

	/**
	 * Letters written on the letters page, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function fetchAll()
	{
		global $conf;

		$sql = "SELECT rowid, kind, event_date, deadline, filed_on, filename, fk_actioncomm, datec FROM ".MAIN_DB_PREFIX."vereine_authority_letter";
		$sql .= " WHERE entity = ".((int) $conf->entity)." ORDER BY datec DESC, rowid DESC";
		// The table exists only after the module was enabled with 0.5.1.
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$letters = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$letters[] = array('id' => (int) $obj->rowid, 'kind' => (string) $obj->kind, 'date' => (string) $obj->event_date, 'deadline' => (string) $obj->deadline,
				'filed_on' => (string) $obj->filed_on, 'filename' => (string) $obj->filename, 'actioncomm_id' => (int) $obj->fk_actioncomm, 'created' => $this->db->jdate($obj->datec));
		}
		$this->db->free($resql);
		return $letters;
	}

	/**
	 * Write a letter, keep it with its deadline and put the deadline into the agenda.
	 *
	 * @param string              $kind         One of VereineAuthorityRules::KINDS
	 * @param array<string,mixed> $entered      Entered data
	 * @param User                $user         Who writes
	 * @param Translate           $outputlangs  Language of the letter
	 * @return int Id of the letter, 0 when refused (see errors), -1 on error
	 */
	public function create($kind, array $entered, $user, $outputlangs)
	{
		global $conf, $langs;

		$letter = VereineAuthorityRules::normalize($kind, $entered);
		$this->errors = VereineAuthorityRules::validate($kind, $letter);
		$authority = $this->authority();
		if ($authority['name'] === '' || $authority['address'] === '') {
			$this->errors[] = 'VereineLetterErrorAuthority';
		}
		if ($this->errors) {
			return 0;
		}
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$functions = new VereineFunctions($this->db);
		$file = $this->build($kind, $letter, $functions->representatives($letter['date'] !== '' && $kind !== VereineAuthorityRules::KIND_EXTENSION ? $letter['date'] : $today), $outputlangs);
		if ($file === '') {
			return -1;
		}
		$deadline = VereineAuthorityRules::deadline($kind, $letter['date']);
		$eventId = 0;
		if ($deadline !== '' && isModEnabled('agenda')) {
			require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
			$event = new ActionComm($this->db);
			$event->type_code = 'AC_OTH';
			$event->label = $langs->transnoentities('VereineLetterAgendaLabel', $langs->transnoentities('VereineLetterKind_'.$kind));
			$event->note_private = $langs->transnoentities('VereineLetterAgendaNote');
			$event->datep = dol_mktime(0, 0, 0, (int) substr($deadline, 5, 2), (int) substr($deadline, 8, 2), (int) substr($deadline, 0, 4));
			$event->datef = $event->datep;
			$event->fulldayevent = 1;
			$event->percentage = 0;
			$event->userownerid = (int) $user->id;
			$eventId = (int) $event->create($user);
			if ($eventId <= 0) {
				dol_syslog(__METHOD__.' agenda event: '.$event->error, LOG_WARNING);
				$eventId = 0;
			}
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_authority_letter (entity, kind, event_date, deadline, filename, fk_actioncomm, datec, fk_user_modif)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($kind)."', ".($letter['date'] !== '' ? "'".$this->db->escape($letter['date'])."'" : "NULL").",";
		$sql .= " ".($deadline !== '' ? "'".$this->db->escape($deadline)."'" : "NULL").", '".$this->db->escape(basename($file))."', ".($eventId > 0 ? $eventId : "NULL").",";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_authority_letter');
		VereineLog::add($this->db, $user, VereineLog::AUTHORITY_LETTER, 0, 0, $kind.' / '.basename($file));
		return $id;
	}

	/**
	 * Note a letter as sent to the authority; its agenda event is done.
	 *
	 * @param int    $id   Letter
	 * @param string $day  Day it was sent
	 * @param User   $user Who notes it
	 * @return int 1 when noted, 0 when refused (see errors), -1 on error
	 */
	public function markFiled($id, $day, $user)
	{
		global $conf;

		if (!VereineAuthorityRules::isDate($day)) {
			$this->errors = array('VereineLetterErrorFiledOn');
			return 0;
		}
		foreach ($this->fetchAll() as $letter) {
			if ($letter['id'] !== (int) $id) {
				continue;
			}
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_authority_letter SET filed_on = '".$this->db->escape($day)."', fk_user_modif = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			if ($letter['actioncomm_id'] > 0 && isModEnabled('agenda')) {
				require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
				$event = new ActionComm($this->db);
				if ($event->fetch($letter['actioncomm_id']) > 0) {
					$event->percentage = 100;
					$event->update($user);
				}
			}
			VereineLog::add($this->db, $user, VereineLog::AUTHORITY_LETTER_FILED, 0, 0, $letter['kind'].' / '.$day);
			return 1;
		}
		$this->errors = array('VereineLetterErrorUnknown');
		return 0;
	}

	/**
	 * Directory of the letters.
	 *
	 * @return string
	 */
	public static function directory()
	{
		global $conf;

		return DOL_DATA_ROOT.($conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/vereine/authority';
	}

	/**
	 * Build the PDF of a letter in the layout every letter to the authority shares.
	 *
	 * @param string                         $kind        One of the KIND constants
	 * @param array<string,mixed>            $letter      Normalized letter
	 * @param array<int,array<string,mixed>> $people      Representatives of VereineFunctions::representatives()
	 * @param Translate                      $outputlangs Language of the letter
	 * @return string Path of the PDF, empty on error
	 */
	public function build($kind, array $letter, array $people, $outputlangs)
	{
		global $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$outputlangs->load('vereine@vereine');
		$dir = self::directory();
		if (dol_mkdir($dir) < 0) {
			$this->error = 'cannot create '.$dir;
			return '';
		}
		$day = function ($date) use ($outputlangs) {
			return VereineAuthorityRules::isDate($date)
				? dol_print_date(dol_mktime(12, 0, 0, (int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)), 'day', 'tzserver', $outputlangs) : '______________________';
		};
		$authority = $this->authority();
		$name = trim($mysoc->name);
		$seat = trim($mysoc->town);

		$pdf = pdf_getInstance();
		$font = pdf_getPDFFont($outputlangs);
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->SetMargins(20, 20, 20);
		$pdf->SetAutoPageBreak(true, 20);
		$pdf->AddPage();
		$line = function ($text, $style = '', $size = 10, $align = 'L') use ($pdf, $font) {
			$pdf->SetFont($font, $style, $size);
			$pdf->MultiCell(0, 5, $text, 0, $align);
		};

		// Letterhead of the association.
		$line($name, 'B', 11);
		$line(trim($mysoc->address."\n".trim($mysoc->zip.' '.$mysoc->town)));
		if (getDolGlobalString('VEREINE_REGISTER_NUMBER') !== '') {
			$line($outputlangs->transnoentities('VereineReportRegister', getDolGlobalString('VEREINE_REGISTER_NUMBER')));
		}
		if (trim((string) $mysoc->email) !== '') {
			$line(trim($mysoc->email));
		}
		$pdf->Ln(8);
		$line(trim(($authority['name'] !== '' ? $authority['name'] : $outputlangs->transnoentities('VereineReportAuthority'))."\n".$authority['address']));
		$pdf->Ln(6);
		$line(trim($seat.', '.dol_print_date(dol_now(), 'day', 'tzserver', $outputlangs), ', '), '', 10, 'R');
		if ($authority['gz'] !== '') {
			$line($outputlangs->transnoentities('VereineLetterGz', $authority['gz']));
		}
		$pdf->Ln(4);
		$line($outputlangs->transnoentities('VereineLetterTitle_'.$kind), 'B', 11);
		$pdf->Ln(3);

		$person = function (array $person, $withFunction, $isNew) use ($line, $pdf, $outputlangs, $day) {
			$blank = '______________________';
			$address = trim($person['address']) !== '' ? trim(str_replace("\n", ', ', $person['address']).', '.$person['zip'].' '.$person['town'].($person['country'] !== '' ? ', '.$person['country'] : '')) : $blank;
			$line(($withFunction ? $person['function'] : $person['name']).($isNew ? ' - '.$outputlangs->transnoentities('VereineReportNew') : ''), 'B');
			if ($withFunction) {
				$line($outputlangs->transnoentities('VereineReportName').': '.$person['name']);
			}
			$line($outputlangs->transnoentities('VereineReportBirth').': '.$day($person['birth']));
			$line($outputlangs->transnoentities('VereineReportBirthPlace').': '.($person['birth_place'] !== '' ? $person['birth_place'] : $blank));
			$line($outputlangs->transnoentities('VereineReportAddress').': '.$address);
			if ($withFunction) {
				$line($outputlangs->transnoentities('VereineReportStart').': '.$day($person['start']));
			}
			$pdf->Ln(3);
		};

		$attachments = array();
		$signature = 'VereineLetterForAssociation';
		switch ($kind) {
			case VereineAuthorityRules::KIND_REPRESENTATIVES:
				$line($outputlangs->transnoentities('VereineLetterBody_representatives', $name, $seat, $day($letter['date'])));
				$pdf->Ln(2);
				foreach ($people as $holder) {
					$person($holder, true, empty($holder['reported']));
				}
				break;
			case VereineAuthorityRules::KIND_STATUTES:
				$line($outputlangs->transnoentities('VereineLetterBody_statutes', $name, $seat, $day($letter['date'])));
				$attachments[] = $outputlangs->transnoentities('VereineLetterAttachStatutesNew');
				break;
			case VereineAuthorityRules::KIND_ADDRESS:
				$line($outputlangs->transnoentities('VereineLetterBody_address', $name, $seat, $day($letter['date'])));
				$pdf->Ln(2);
				$line($letter['address'], 'B');
				break;
			case VereineAuthorityRules::KIND_EXTRACT:
				$line($outputlangs->transnoentities('VereineLetterBody_extract_'.$letter['extract'], $name, $seat, $day($letter['date'])));
				break;
			case VereineAuthorityRules::KIND_DISSOLUTION:
				$line($outputlangs->transnoentities('VereineLetterBody_dissolution', $name, $seat, $day($letter['date']), $letter['effective']));
				$pdf->Ln(2);
				if ($letter['assets']) {
					$line($outputlangs->transnoentities('VereineLetterLiquidator'));
					$pdf->Ln(2);
					$person(array('name' => $letter['liquidator_name'], 'birth' => $letter['liquidator_birth'], 'birth_place' => $letter['liquidator_birth_place'],
						'address' => $letter['liquidator_address'], 'zip' => '', 'town' => '', 'country' => '', 'start' => $letter['liquidator_start'],
						'function' => $outputlangs->transnoentities('VereineLetterLiquidatorFunction')), true, false);
				} else {
					$line($outputlangs->transnoentities('VereineLetterNoAssets'));
				}
				$attachments[] = $outputlangs->transnoentities('VereineLetterAttachMinutes');
				break;
			case VereineAuthorityRules::KIND_FOUNDING:
				$line($outputlangs->transnoentities('VereineLetterBody_founding', $name, $seat));
				$pdf->Ln(2);
				$line($outputlangs->transnoentities($letter['founders'] ? 'VereineLetterFounders' : 'VereineLetterRepresentatives'), 'B');
				$pdf->Ln(2);
				foreach ($people as $holder) {
					$person($holder, !$letter['founders'], false);
				}
				$line($outputlangs->transnoentities('VereineLetterServiceAddress', trim(str_replace("\n", ', ', $mysoc->address).', '.$mysoc->zip.' '.$mysoc->town, ', ')));
				$attachments[] = $outputlangs->transnoentities('VereineLetterAttachStatutes');
				if ($letter['founders']) {
					$signature = 'VereineLetterForAssociationFounders';
				}
				break;
			case VereineAuthorityRules::KIND_EXTENSION:
				$line($outputlangs->transnoentities('VereineLetterBody_extension', $name, $seat, $day($letter['date'])));
				$pdf->Ln(2);
				$line($outputlangs->transnoentities('VereineLetterReasonIntro'));
				$line($letter['reason']);
				$signature = 'VereineLetterForAssociationFounders';
				break;
		}

		$pdf->Ln(10);
		$line($outputlangs->transnoentities($signature), 'B', 10, 'C');
		$pdf->Ln(12);
		$signers = array();
		foreach ($people as $holder) {
			$signers[$holder['member_id']] = $holder['name'].', '.$holder['function'];
		}
		if (!$signers) {
			$signers = array('', '');
		}
		foreach (array_chunk(array_values($signers), 2) as $pair) {
			$pdf->SetFont($font, '', 10);
			$pdf->Cell(80, 5, '______________________________', 0, 0, 'L');
			$pdf->Cell(0, 5, count($pair) > 1 ? '______________________________' : '', 0, 1, 'L');
			$pdf->SetFont($font, '', 8);
			$pdf->Cell(80, 5, $pair[0], 0, 0, 'L');
			$pdf->Cell(0, 5, count($pair) > 1 ? $pair[1] : '', 0, 1, 'L');
			$pdf->Ln(10);
		}
		if ($attachments) {
			$line($outputlangs->transnoentities('VereineLetterAttachments', implode(', ', $attachments)));
		}

		$prefixes = array('representatives' => 'meldung-vertreter', 'statutes' => 'anzeige-statutenaenderung', 'address' => 'anzeige-zustellanschrift',
			'extract' => 'antrag-registerauszug', 'dissolution' => 'anzeige-aufloesung', 'founding' => 'anzeige-errichtung', 'extension' => 'antrag-fristverlaengerung');
		$stem = $dir.'/'.$prefixes[$kind].'-'.dol_print_date(dol_now(), '%Y%m%d-%H%M%S', 'tzserver');
		$file = $stem.'.pdf';
		$number = 2;
		while (is_file($file)) {
			$file = $stem.'-'.$number.'.pdf';
			$number++;
		}
		$pdf->Output($file, 'F');
		if (!is_file($file)) {
			$this->error = 'the PDF was not written';
			return '';
		}
		dolChmod($file);
		return $file;
	}

	/**
	 * Path of a stored letter.
	 *
	 * @param int $id Letter
	 * @return string Empty when unknown or missing
	 */
	public function path($id)
	{
		foreach ($this->fetchAll() as $letter) {
			if ($letter['id'] === (int) $id && preg_match('/^[a-z-]+-\d{8}-\d{6}(-\d+)?\.pdf$/', $letter['filename'])) {
				$file = self::directory().'/'.$letter['filename'];
				return is_file($file) ? $file : '';
			}
		}
		return '';
	}
}
