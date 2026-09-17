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
 * \file    class/vereinestatutes.class.php
 * \ingroup vereine
 * \brief   Stores the rules of the statutes and the terms of office of the functions.
 */

require_once __DIR__.'/vereinestatuterules.class.php';
require_once __DIR__.'/vereinestatutetext.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Rules of the statutes as a Dolibarr constant.
 */
class VereineStatutes
{
	/** Rules of the statutes as JSON. */
	const CONST_RULES = 'VEREINE_STATUTE_RULES';
	/** Text fields of the statutes as JSON. */
	const CONST_TEXT = 'VEREINE_STATUTE_TEXT';
	/** Largest uploaded PDF of statutes in bytes. */
	const UPLOAD_MAX = 10485760;

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
	 * The rules of the statutes, the defaults of the model statutes until the association stores its own.
	 *
	 * @return array<string,mixed>
	 */
	public function rules()
	{
		return VereineStatuteRules::normalize(json_decode(getDolGlobalString(self::CONST_RULES, '{}'), true));
	}

	/**
	 * Whether the association stored its own rules.
	 *
	 * @return bool
	 */
	public function stored()
	{
		return getDolGlobalString(self::CONST_RULES) !== '';
	}

	/**
	 * Store entered rules and terms of office.
	 *
	 * @param array<string,mixed> $data      Entered rules
	 * @param array<int,mixed>    $termYears Entered terms of office in years by function id
	 * @param User                $user      Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function save(array $data, array $termYears, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$this->errors = VereineStatuteRules::validate($data);
		foreach ($termYears as $years) {
			if (!VereineStatuteRules::isTermYears($years)) {
				$this->errors[] = 'VereineStatuteErrorTermYears';
				break;
			}
		}
		if ($this->errors) {
			return 0;
		}
		$rules = VereineStatuteRules::normalize($data);
		$this->db->begin();
		if (dolibarr_set_const($this->db, self::CONST_RULES, json_encode($rules), 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		foreach ($termYears as $functionId => $years) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_function SET term_years = ".((int) $years).", fk_user_modif = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $functionId)." AND entity = ".((int) $conf->entity)." AND term_years <> ".((int) $years);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::STATUTE_RULES, 0, 0, 'general_years='.$rules['general_years'].', invite_days='.$rules['invite_days']
			.', min_age='.$rules['min_age'].', virtual='.$rules['virtual']);
		return 1;
	}

	/**
	 * The text fields of the statutes.
	 *
	 * @return array<string,mixed>
	 */
	public function text()
	{
		return VereineStatuteText::normalize(json_decode(getDolGlobalString(self::CONST_TEXT, '{}'), true));
	}

	/**
	 * Store entered text fields.
	 *
	 * @param array<string,mixed> $data Entered fields
	 * @param User                $user Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function saveText(array $data, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$this->errors = VereineStatuteText::validate($data);
		if ($this->errors) {
			return 0;
		}
		$text = VereineStatuteText::normalize($data);
		if (dolibarr_set_const($this->db, self::CONST_TEXT, json_encode($text), 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::STATUTE_TEXT, 0, 0, 'tax='.$text['tax'].', asset='.$text['asset'].', activities='.count($text['activities']));
		return 1;
	}

	/**
	 * What the text of the statutes takes from Dolibarr: association, functions, member types, exit rule.
	 *
	 * @param array<string,mixed> $rules Normalized rules
	 * @return array<string,mixed> Context of VereineStatuteText::sections()
	 */
	public function context(array $rules)
	{
		global $mysoc;

		require_once __DIR__.'/vereinefunctions.class.php';
		require_once __DIR__.'/vereinefeemodel.class.php';
		require_once __DIR__.'/vereineexits.class.php';

		$context = array('name' => trim((string) $mysoc->name), 'seat' => trim((string) $mysoc->town), 'purpose' => getDolGlobalString('VEREINE_PURPOSE'),
			'nonprofit' => getDolGlobalString('VEREINE_NONPROFIT') === '1', 'board' => array(), 'board_terms' => array(), 'chair' => '', 'secretary' => '', 'treasurer' => '',
			'auditors' => 0, 'auditor_term' => 0, 'types' => array(), 'voting' => array(), 'honorary' => false);
		$functions = new VereineFunctions($this->db);
		foreach ($functions->fetchAll(true) as $function) {
			if ($function['board']) {
				$context['board'][] = $function['label'];
				$context['board_terms'][] = $function['term_years'];
			}
			if ($function['auditor']) {
				$context['auditors'] += max($function['min'], 1);
				$context['auditor_term'] = max($context['auditor_term'], $function['term_years']);
			}
			foreach (array('obmann' => 'chair', 'schriftfuehrung' => 'secretary', 'kassier' => 'treasurer') as $code => $key) {
				if ($function['code'] === $code) {
					$context[$key] = $function['label'];
				}
			}
		}
		$feeModel = new VereineFeeModel($this->db);
		foreach ($feeModel->memberTypes(true) as $type) {
			$context['types'][] = $type['label'];
			if (in_array($type['id'], $rules['voting_types'], true)) {
				$context['voting'][] = $type['label'];
			}
			if (preg_match('/ehren/i', $type['label'])) {
				$context['honorary'] = true;
			}
		}
		$exits = new VereineExits($this->db);
		$context['exit'] = $exits->rule();
		return $context;
	}

	/**
	 * Versions of the statutes, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function versions()
	{
		global $conf;

		$sql = "SELECT rowid, version, decided_on, valid_from, source, filename, sha256, note FROM ".MAIN_DB_PREFIX."vereine_statute";
		$sql .= " WHERE entity = ".((int) $conf->entity)." ORDER BY version DESC";
		// The table exists only after the module was enabled with 0.5.2.
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		$versions = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$versions[] = array('id' => (int) $obj->rowid, 'version' => (int) $obj->version, 'decided_on' => (string) $obj->decided_on, 'valid_from' => (string) $obj->valid_from,
				'source' => (string) $obj->source, 'filename' => (string) $obj->filename, 'sha256' => (string) $obj->sha256, 'note' => (string) $obj->note);
		}
		$this->db->free($resql);
		return $versions;
	}

	/**
	 * The version in force on a day.
	 *
	 * @param string $day Day
	 * @return array<string,mixed>|null
	 */
	public function current($day)
	{
		$current = null;
		foreach ($this->versions() as $version) {
			if ($version['valid_from'] <= $day && ($current === null || $version['valid_from'] > $current['valid_from']
				|| ($version['valid_from'] === $current['valid_from'] && $version['version'] > $current['version']))) {
				$current = $version;
			}
		}
		return $current;
	}

	/**
	 * Store the statutes as generated now as a new version.
	 *
	 * @param string    $decidedOn   Day of the resolution
	 * @param string    $validFrom   First day in force, empty for the day of the resolution
	 * @param string    $note        Note
	 * @param User      $user        Who stores
	 * @return int Id of the version, 0 when refused (see errors), -1 on error
	 */
	public function saveVersion($decidedOn, $validFrom, $note, $user)
	{
		$validFrom = (string) $validFrom !== '' ? (string) $validFrom : (string) $decidedOn;
		if (!$this->checkDates($decidedOn, $validFrom)) {
			return 0;
		}
		$rules = $this->rules();
		$text = $this->text();
		$context = $this->context($rules);
		$number = $this->nextNumber();
		$file = self::directory().'/statuten-v'.$number.'-'.$decidedOn.'.pdf';
		if (!$this->buildPdf(VereineStatuteText::sections($rules, $text, $context), $context['name'], 'Fassung '.$number.', beschlossen am '
			.dol_print_date(dol_mktime(12, 0, 0, (int) substr($decidedOn, 5, 2), (int) substr($decidedOn, 8, 2), (int) substr($decidedOn, 0, 4)), 'day', 'tzserver'), $file)) {
			return -1;
		}
		return $this->insertVersion($number, $decidedOn, $validFrom, 'generated', $file, json_encode(array('rules' => $rules, 'text' => $text, 'context' => $context)), $note, $user);
	}

	/**
	 * Store existing statutes from an uploaded PDF as a new version.
	 *
	 * @param string $uploaded  Uploaded temporary file
	 * @param string $decidedOn Day of the resolution
	 * @param string $validFrom First day in force, empty for the day of the resolution
	 * @param string $note      Note
	 * @param User   $user      Who stores
	 * @return int Id of the version, 0 when refused (see errors), -1 on error
	 */
	public function uploadVersion($uploaded, $decidedOn, $validFrom, $note, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$validFrom = (string) $validFrom !== '' ? (string) $validFrom : (string) $decidedOn;
		if (!$this->checkDates($decidedOn, $validFrom)) {
			return 0;
		}
		$head = is_file((string) $uploaded) ? (string) file_get_contents((string) $uploaded, false, null, 0, 5) : '';
		if ($head !== '%PDF-' || filesize((string) $uploaded) > self::UPLOAD_MAX) {
			$this->errors = array('VereineStatuteVersionErrorFile');
			return 0;
		}
		if (dol_mkdir(self::directory()) < 0) {
			$this->error = 'cannot create '.self::directory();
			return -1;
		}
		$number = $this->nextNumber();
		$file = self::directory().'/statuten-v'.$number.'-'.$decidedOn.'.pdf';
		$moved = dol_move_uploaded_file((string) $uploaded, $file, 1, 0, 0, 1);
		if ($moved !== 1 && $moved !== true) {
			$this->errors = array('VereineStatuteVersionErrorFile');
			dol_syslog(__METHOD__.' upload: '.(is_array($moved) ? implode(', ', $moved) : (string) $moved), LOG_WARNING);
			return 0;
		}
		return $this->insertVersion($number, $decidedOn, $validFrom, 'uploaded', $file, null, $note, $user);
	}

	/**
	 * Build the PDF of statutes.
	 *
	 * @param array<int,array{number:int,title:string,paragraphs:string[]}> $sections Sections of VereineStatuteText::sections()
	 * @param string                                                        $name     Name of the association
	 * @param string                                                        $footer   Version line, such as "Entwurf"
	 * @param string                                                        $file     Path to write
	 * @return bool
	 */
	public function buildPdf(array $sections, $name, $footer, $file)
	{
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		if (dol_mkdir(dirname($file)) < 0) {
			$this->error = 'cannot create '.dirname($file);
			return false;
		}
		$pdf = pdf_getInstance();
		$font = pdf_getPDFFont($langs);
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->SetMargins(22, 20, 22);
		$pdf->SetAutoPageBreak(true, 20);
		$pdf->AddPage();
		$pdf->SetFont($font, 'B', 16);
		$pdf->MultiCell(0, 8, 'Statuten des Vereins', 0, 'C');
		$pdf->MultiCell(0, 8, '„'.$name.'“', 0, 'C');
		$pdf->SetFont($font, '', 9);
		$pdf->MultiCell(0, 5, $footer, 0, 'C');
		$pdf->Ln(6);
		foreach ($sections as $section) {
			$pdf->SetFont($font, 'B', 11);
			$pdf->MultiCell(0, 6, '§ '.$section['number'].': '.$section['title'], 0, 'L');
			$pdf->Ln(1);
			$pdf->SetFont($font, '', 10);
			foreach ($section['paragraphs'] as $paragraph) {
				$pdf->MultiCell(0, 5, $paragraph, 0, 'J');
				$pdf->Ln(1.5);
			}
			$pdf->Ln(3);
		}
		$pdf->Output($file, 'F');
		if (!is_file($file)) {
			$this->error = 'the PDF was not written';
			return false;
		}
		dolChmod($file);
		return true;
	}

	/**
	 * Path of the PDF of a version.
	 *
	 * @param int $id Version
	 * @return string Empty when unknown or missing
	 */
	public function path($id)
	{
		foreach ($this->versions() as $version) {
			if ($version['id'] === (int) $id && preg_match('/^statuten-v\d+-\d{4}-\d{2}-\d{2}\.pdf$/', $version['filename'])) {
				$file = self::directory().'/'.$version['filename'];
				return is_file($file) ? $file : '';
			}
		}
		return '';
	}

	/**
	 * Directory of the statutes.
	 *
	 * @return string
	 */
	public static function directory()
	{
		global $conf;

		return DOL_DATA_ROOT.($conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/vereine/statutes';
	}

	/**
	 * Whether the days of a version can be stored; sets errors otherwise.
	 *
	 * @param string $decidedOn Day of the resolution
	 * @param string $validFrom First day in force
	 * @return bool
	 */
	private function checkDates($decidedOn, $validFrom)
	{
		$this->errors = array();
		if (!VereineStatuteRules::isDate($decidedOn) || !VereineStatuteRules::isDate($validFrom) || $validFrom < $decidedOn) {
			$this->errors[] = 'VereineStatuteVersionErrorDate';
		}
		return !$this->errors;
	}

	/**
	 * Number of the next version.
	 *
	 * @return int
	 */
	private function nextNumber()
	{
		$highest = 0;
		foreach ($this->versions() as $version) {
			$highest = max($highest, $version['version']);
		}
		return $highest + 1;
	}

	/**
	 * Keep a version.
	 *
	 * @param int         $number    Version number
	 * @param string      $decidedOn Day of the resolution
	 * @param string      $validFrom First day in force
	 * @param string      $source    generated or uploaded
	 * @param string      $file      Stored PDF
	 * @param string|null $content   Rules, text and context as JSON for a generated version
	 * @param string      $note      Note
	 * @param User        $user      Who stores
	 * @return int Id, -1 on error
	 */
	private function insertVersion($number, $decidedOn, $validFrom, $source, $file, $content, $note, $user)
	{
		global $conf;

		dolChmod($file);
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_statute (entity, version, decided_on, valid_from, source, filename, sha256, content, note, datec, fk_user_modif)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $number).", '".$this->db->escape($decidedOn)."', '".$this->db->escape($validFrom)."', '".$this->db->escape($source)."',";
		$sql .= " '".$this->db->escape(basename($file))."', '".$this->db->escape((string) hash_file('sha256', $file))."', ".($content !== null ? "'".$this->db->escape($content)."'" : "NULL").",";
		$sql .= " '".$this->db->escape(mb_substr(trim((string) $note), 0, 255, 'UTF-8'))."', '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_statute');
		VereineLog::add($this->db, $user, VereineLog::STATUTE_VERSION, 0, 0, 'version '.$number.' ('.$source.'), '.$decidedOn);
		return $id;
	}
}
