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
 * \file    class/vereinesignatures.class.php
 * \ingroup vereine
 * \brief   Signature runs of a document: who has to sign, who signed when and how, and the signature sheet.
 *
 * A run freezes the document with its SHA-256. Signing in Dolibarr asks the person for the password
 * of its own user, checked the way Dolibarr's login checks it; a scan of the signed paper finishes
 * the run as well. A changed document needs a new run, so a signature always belongs to exactly the
 * file that was signed.
 */

require_once __DIR__.'/vereinesignaturerules.class.php';
require_once __DIR__.'/vereinefunctions.class.php';
require_once __DIR__.'/vereinepdf.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Signature runs.
 */
class VereineSignatures
{
	/** Constant holding the rules as JSON. */
	const CONST_RULES = 'VEREINE_SIGNATURE_RULES';

	/** A run waiting for signatures. */
	const STATUS_OPEN = 'open';
	/** Every needed signature is there. */
	const STATUS_DONE = 'done';
	/** The document changed, so the run does not count any more. */
	const STATUS_CANCELLED = 'cancelled';

	/** Largest scan of a signed document. */
	const SCAN_MAX = 10485760;

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
	 * Where the signature sheets and scans live.
	 *
	 * @return string
	 */
	public static function directory()
	{
		global $conf;

		return DOL_DATA_ROOT.($conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/vereine/signatures';
	}

	/**
	 * The rules, filled in from the model statutes for the functions the catalogue has.
	 *
	 * @return array<string,array{roles:string[],mode:string,min:int}>
	 */
	public function rules()
	{
		$functions = new VereineFunctions($this->db);
		$codes = array();
		foreach ($functions->fetchAll(true) as $function) {
			$codes[] = $function['code'];
		}
		return VereineSignatureRules::normalize(json_decode(getDolGlobalString(self::CONST_RULES, 'null'), true), $codes);
	}

	/**
	 * Labels of the functions, for the pages and the signature sheet.
	 *
	 * @return array<string,string> Label by code
	 */
	public function functionLabels()
	{
		$functions = new VereineFunctions($this->db);
		$labels = array();
		foreach ($functions->fetchAll() as $function) {
			$labels[$function['code']] = $function['label'];
		}
		return $labels;
	}

	/**
	 * Store the rules.
	 *
	 * @param array<string,mixed> $entered Rules by kind of document
	 * @param User                $user    Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function saveRules(array $entered, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$functions = new VereineFunctions($this->db);
		$codes = array();
		foreach ($functions->fetchAll(true) as $function) {
			$codes[] = $function['code'];
		}
		$rules = VereineSignatureRules::normalize($entered, $codes);
		$this->errors = VereineSignatureRules::validate($rules);
		if ($this->errors) {
			return 0;
		}
		if (dolibarr_set_const($this->db, self::CONST_RULES, json_encode($rules), 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::SIGNATURE_RULES, 0, 0, 'signature rules');
		return 1;
	}

	/**
	 * Holders of every function on a day, for the signers.
	 *
	 * @param string $day Day, YYYY-MM-DD
	 * @return array<string,array<int,array{member_id:int,name:string}>> By function code
	 */
	public function holders($day)
	{
		$functions = new VereineFunctions($this->db);
		return $functions->holdersByCode($day);
	}

	/**
	 * Start a run for a document: freeze it with its checksum and write down who has to sign.
	 *
	 * An open run of the same document is cancelled, so the newest document is the one that counts.
	 *
	 * @param string                         $kind     One of VereineSignatureRules::KINDS
	 * @param int                            $objectId The document's object, for example the letter
	 * @param string                         $file     Absolute path of the PDF
	 * @param string                         $day      Day the holders are taken from
	 * @param User                           $user     Who starts
	 * @param array<int,array<string,mixed>> $people   People who sign instead of the holders of the functions, each with member_id, role, label and name
	 * @return int Id of the run, 0 when refused (see errors), -1 on error
	 */
	public function start($kind, $objectId, $file, $day, $user, array $people = array())
	{
		global $conf;

		$this->errors = array();
		$rules = $this->rules();
		if (!in_array($kind, VereineSignatureRules::KINDS, true) || !VereineSignatureRules::wanted($rules, $kind)) {
			$this->errors[] = 'VereineSignatureErrorNotWanted';
			return 0;
		}
		if ((string) $file === '' || !is_file($file)) {
			$this->errors[] = 'VereineSignatureErrorDocument';
			return 0;
		}
		$signers = $people ? array('people' => $people, 'vacant' => array())
			: VereineSignatureRules::signers($rules, $kind, $this->holders($day), $this->functionLabels());
		if (!$signers['people']) {
			$this->errors[] = 'VereineSignatureErrorNobody';
			return 0;
		}
		$this->db->begin();
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_signature SET status = '".self::STATUS_CANCELLED."'";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND kind = '".$this->db->escape($kind)."' AND fk_object = ".((int) $objectId);
		$sql .= " AND status = '".self::STATUS_OPEN."'";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_signature (entity, kind, fk_object, doc_name, doc_sha, status, datec, fk_user_modif)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($kind)."', ".((int) $objectId).", '".$this->db->escape(basename($file))."',";
		$sql .= " '".$this->db->escape(hash_file('sha256', $file))."', '".self::STATUS_OPEN."', '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_signature');
		foreach ($signers['people'] as $person) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_signature_person (entity, fk_signature, fk_adherent, function_code, function_label, person_name)";
			$sql .= " VALUES (".((int) $conf->entity).", ".$id.", ".((int) $person['member_id']).", '".$this->db->escape($person['role'])."',";
			$sql .= " '".$this->db->escape($person['label'])."', '".$this->db->escape($person['name'])."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::SIGNATURE_STARTED, 0, 0, $kind.' '.$objectId.': '.count($signers['people']).' signers');
		return $id;
	}

	/**
	 * The newest run of a document with its people.
	 *
	 * @param string $kind     Kind of document
	 * @param int    $objectId The document's object
	 * @param string $file     Absolute path of the document, to see whether it still is the signed one
	 * @return array<string,mixed>|null
	 */
	public function current($kind, $objectId, $file = '')
	{
		global $conf;

		$sql = "SELECT rowid, kind, fk_object, doc_name, doc_sha, status, scan_name, datec FROM ".MAIN_DB_PREFIX."vereine_signature";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND kind = '".$this->db->escape($kind)."' AND fk_object = ".((int) $objectId);
		$sql .= " AND status <> '".self::STATUS_CANCELLED."' ORDER BY rowid DESC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return null;
		}
		$run = array('id' => (int) $obj->rowid, 'kind' => (string) $obj->kind, 'object_id' => (int) $obj->fk_object, 'doc_name' => (string) $obj->doc_name,
			'doc_sha' => (string) $obj->doc_sha, 'status' => (string) $obj->status, 'scan_name' => (string) $obj->scan_name,
			'created' => $this->db->jdate($obj->datec), 'people' => array(), 'signed' => 0, 'needed' => 0, 'complete' => false, 'document_changed' => false);
		$sql = "SELECT rowid, fk_adherent, function_code, function_label, person_name, signed_at, way, fk_user_signed FROM ".MAIN_DB_PREFIX."vereine_signature_person";
		$sql .= " WHERE fk_signature = ".((int) $run['id'])." ORDER BY rowid";
		$resql = $this->db->query($sql);
		while ($resql && ($row = $this->db->fetch_object($resql))) {
			$run['people'][] = array('id' => (int) $row->rowid, 'member_id' => (int) $row->fk_adherent, 'role' => (string) $row->function_code,
				'label' => (string) $row->function_label, 'name' => (string) $row->person_name,
				'signed_at' => $row->signed_at ? $this->db->jdate($row->signed_at) : 0, 'way' => (string) $row->way, 'user_id' => (int) $row->fk_user_signed);
			$run['signed'] += $row->signed_at ? 1 : 0;
		}
		$rules = $this->rules();
		$run['needed'] = VereineSignatureRules::needed($rules, $kind, count($run['people']));
		$run['complete'] = $run['status'] === self::STATUS_DONE;
		$run['document_changed'] = (string) $file !== '' && is_file($file) && hash_file('sha256', $file) !== $run['doc_sha'];
		return $run;
	}

	/**
	 * Sign in Dolibarr: the person's own user confirms with its password.
	 *
	 * @param int       $id          Run
	 * @param string    $password    Password of the logged-in user
	 * @param string    $file        Absolute path of the document
	 * @param User      $user        Who signs
	 * @param Translate $outputlangs Language of the signature sheet
	 * @return int 1 when signed, 0 when refused (see errors), -1 on error
	 */
	public function sign($id, $password, $file, $user, $outputlangs)
	{
		global $conf, $dolibarr_main_authentication;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';

		$this->errors = array();
		$run = $this->fetch($id);
		if ($run === null || $run['status'] !== self::STATUS_OPEN) {
			$this->errors[] = 'VereineSignatureErrorNotOpen';
			return 0;
		}
		if ((string) $file === '' || !is_file($file) || hash_file('sha256', $file) !== $run['doc_sha']) {
			$this->errors[] = 'VereineSignatureErrorChanged';
			return 0;
		}
		$mine = null;
		foreach ($run['people'] as $person) {
			if ($person['member_id'] === (int) $user->fk_member && $person['signed_at'] === 0) {
				$mine = $person;
			}
		}
		if ($mine === null) {
			$this->errors[] = 'VereineSignatureErrorNotYours';
			return 0;
		}
		// The password is checked the way Dolibarr's login checks it, but only with the modes that
		// really ask for a password. Web server or forced authentication would confirm anything.
		$modes = array();
		foreach (explode(',', (string) $dolibarr_main_authentication) as $mode) {
			if (in_array(trim($mode), array('dolibarr', 'ldap'), true)) {
				$modes[] = trim($mode);
			}
		}
		if (!$modes) {
			$this->errors[] = 'VereineSignatureErrorNoPassword';
			return 0;
		}
		if ((string) $password === '' || strtolower((string) checkLoginPassEntity($user->login, $password, $conf->entity, $modes)) !== strtolower((string) $user->login)) {
			$this->errors[] = 'VereineSignatureErrorPassword';
			return 0;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_signature_person SET signed_at = '".$this->db->idate(dol_now())."',";
		$sql .= " way = '".VereineSignatureRules::WAY_CLICK."', fk_user_signed = ".((int) $user->id)." WHERE rowid = ".((int) $mine['id']);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::SIGNATURE_SIGNED, $mine['member_id'], 0, $run['kind'].' '.$run['object_id'].': '.$mine['label']);
		return $this->finishWhenComplete($id, $user, $outputlangs);
	}

	/**
	 * The paper way: a scan of the signed document finishes the run.
	 *
	 * @param int                 $id          Run
	 * @param array<string,mixed> $upload      One entry of $_FILES
	 * @param User                $user        Who uploads
	 * @param Translate           $outputlangs Language of the signature sheet
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function uploadScan($id, array $upload, $user, $outputlangs)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$this->errors = array();
		$run = $this->fetch($id);
		if ($run === null || $run['status'] !== self::STATUS_OPEN) {
			$this->errors[] = 'VereineSignatureErrorNotOpen';
			return 0;
		}
		$name = isset($upload['name']) ? (string) $upload['name'] : '';
		$temporary = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
		$size = isset($upload['size']) ? (int) $upload['size'] : 0;
		if ($name === '' || $temporary === '' || $size <= 0) {
			$this->errors[] = 'VereineSignatureErrorScanMissing';
			return 0;
		}
		if ($size > self::SCAN_MAX) {
			$this->errors[] = 'VereineSignatureErrorScanSize';
			return 0;
		}
		if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
			$this->errors[] = 'VereineSignatureErrorScanKind';
			return 0;
		}
		$dir = self::directory();
		if (dol_mkdir($dir) < 0) {
			$this->error = 'cannot create '.$dir;
			return -1;
		}
		$target = 'unterschrieben-'.$run['kind'].'-'.$run['object_id'].'-'.$run['id'].'.pdf';
		if (dol_move_uploaded_file($temporary, $dir.'/'.$target, 1, 0, 0, 0) != 1) {
			$this->errors[] = 'VereineSignatureErrorScanStore';
			return 0;
		}
		$this->db->begin();
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_signature_person SET signed_at = '".$this->db->idate(dol_now())."',";
		$sql .= " way = '".VereineSignatureRules::WAY_PAPER."', fk_user_signed = ".((int) $user->id)." WHERE fk_signature = ".((int) $run['id'])." AND signed_at IS NULL";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_signature SET status = '".self::STATUS_DONE."', scan_name = '".$this->db->escape($target)."'";
		$sql .= ", fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $run['id']);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::SIGNATURE_SIGNED, 0, 0, $run['kind'].' '.$run['object_id'].': scan');
		$this->buildSheet($run['id'], $outputlangs);
		return 1;
	}

	/**
	 * A run with its people, by id.
	 *
	 * @param int $id Run
	 * @return array<string,mixed>|null
	 */
	public function fetch($id)
	{
		global $conf;

		$sql = "SELECT kind, fk_object FROM ".MAIN_DB_PREFIX."vereine_signature WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		$run = $this->current((string) $obj->kind, (int) $obj->fk_object);
		return $run !== null && $run['id'] === (int) $id ? $run : null;
	}

	/**
	 * Path of the signature sheet of a run.
	 *
	 * @param int $id Run
	 * @return string
	 */
	public static function sheetPath($id)
	{
		return self::directory().'/unterschriftenblatt-'.((int) $id).'.pdf';
	}

	/**
	 * Path of the uploaded scan of a run.
	 *
	 * @param array<string,mixed> $run Run of current() or fetch()
	 * @return string Empty when there is none
	 */
	public static function scanPath(array $run)
	{
		if ((string) $run['scan_name'] === '' || !preg_match('/^unterschrieben-[a-z_]+-\d+-\d+\.pdf$/', $run['scan_name'])) {
			return '';
		}
		$file = self::directory().'/'.$run['scan_name'];
		return is_file($file) ? $file : '';
	}

	/**
	 * Mark a run as done and build its signature sheet once every needed signature is there.
	 *
	 * @param int       $id          Run
	 * @param User      $user        Who signed last
	 * @param Translate $outputlangs Language of the signature sheet
	 * @return int 1 when the signature counts, -1 on error
	 */
	private function finishWhenComplete($id, $user, $outputlangs)
	{
		$run = $this->fetch($id);
		if ($run === null) {
			return -1;
		}
		if (!VereineSignatureRules::complete($this->rules(), $run['kind'], count($run['people']), $run['signed'])) {
			return 1;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_signature SET status = '".self::STATUS_DONE."', fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $run['id']);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::SIGNATURE_DONE, 0, 0, $run['kind'].' '.$run['object_id'].': '.$run['signed'].' signatures');
		$this->buildSheet($run['id'], $outputlangs);
		return 1;
	}

	/**
	 * Build the signature sheet: which document was signed, by whom, when and how.
	 *
	 * @param int       $id          Run
	 * @param Translate $outputlangs Language of the sheet
	 * @return string Path of the PDF, empty on error
	 */
	public function buildSheet($id, $outputlangs)
	{
		global $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$run = $this->fetch($id);
		if ($run === null) {
			$this->error = 'unknown signature run '.((int) $id);
			return '';
		}
		$outputlangs->load('vereine@vereine');
		$dir = self::directory();
		if (dol_mkdir($dir) < 0) {
			$this->error = 'cannot create '.$dir;
			return '';
		}
		$pdf = VereinePdf::start($outputlangs);
		$font = pdf_getPDFFont($outputlangs);
		$line = function ($text, $style = '', $size = 10) use ($pdf, $font) {
			$pdf->SetFont($font, $style, $size);
			$pdf->MultiCell(0, 5, $text, 0, 'L');
		};

		VereinePdf::title($pdf, $outputlangs, $outputlangs->transnoentities('VereineSignatureSheetTitle'));
		$line($outputlangs->transnoentities('VereineSignatureSheetDocument', $outputlangs->transnoentitiesnoconv('VereineSignatureKind_'.$run['kind']), $run['doc_name']));
		$line($outputlangs->transnoentities('VereineSignatureSheetChecksum', $run['doc_sha']));
		$pdf->Ln(4);
		foreach ($run['people'] as $person) {
			$line($person['name'].' - '.$person['label'], 'B');
			if ($person['signed_at'] > 0) {
				$way = $outputlangs->transnoentitiesnoconv('VereineSignatureWay_'.($person['way'] !== '' ? $person['way'] : VereineSignatureRules::WAY_CLICK));
				$line($outputlangs->transnoentities('VereineSignatureSheetSigned', dol_print_date($person['signed_at'], 'dayhour', 'tzserver', $outputlangs), $way));
			} else {
				$line($outputlangs->transnoentities('VereineSignatureSheetOpen'));
			}
			$pdf->Ln(2);
		}
		$pdf->Ln(4);
		$pdf->SetFont($font, 'I', 8);
		$pdf->MultiCell(0, 4, $outputlangs->transnoentities('VereineSignatureSheetNote'), 0, 'L');

		$file = self::sheetPath($run['id']);
		VereinePdf::finish($pdf, $outputlangs, $outputlangs->transnoentities('VereineSignatureSheetTitle').' - '.$run['doc_name']);
		$pdf->Output($file, 'F');
		if (!is_file($file)) {
			$this->error = 'cannot write '.$file;
			return '';
		}
		dolChmod($file);
		return $file;
	}
}
