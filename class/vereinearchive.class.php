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
 * \file    class/vereinearchive.class.php
 * \ingroup vereine
 * \brief   The files of the association (#123): finished documents with a code, their checksums, and the export.
 *
 * A finished document gets a code before it is built; the PDF carries it with a QR code, and every file
 * of it – as built, signed with ID Austria, the signed paper as a scan – is kept with its SHA-256. The
 * public check tells whether a document with a code exists, of which kind, from when, who signed it and
 * which checksums it has, and nothing more; the association can switch it off.
 */

require_once __DIR__.'/vereinearchiverules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * The files of the association.
 */
class VereineArchive
{
	/** Whether the public check is on: "0" switches it off, anything else or nothing leaves it on. */
	const PUBLIC_CHECK = 'VEREINE_VERIFY_PUBLIC';

	/** Largest file the public check compares, in bytes. */
	const CHECK_MAX = 20971520;

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
	 * Where exports are kept.
	 *
	 * @return string
	 */
	public static function directory()
	{
		global $conf;

		return $conf->vereine->dir_output.'/vereinsakte';
	}

	/**
	 * Whether anybody may check a document without logging in.
	 *
	 * @return bool
	 */
	public static function publicOn()
	{
		return getDolGlobalString(self::PUBLIC_CHECK, '1') !== '0';
	}

	/**
	 * The address of the public check of a code.
	 *
	 * @param string $code Code
	 * @return string
	 */
	public static function verifyUrl($code)
	{
		return dol_buildpath('/vereine/public/verify.php', 2).'?code='.VereineArchiveRules::format($code);
	}

	/**
	 * What the foot of a finished PDF prints: the code, and the address of the check while it is public.
	 *
	 * @param string $code Code
	 * @return array{code:string,url:string}
	 */
	public static function seal($code)
	{
		return array('code' => (string) $code, 'url' => self::publicOn() ? self::verifyUrl($code) : '');
	}

	/**
	 * The code of a document: the one it has, or a new one for a document built the first time.
	 *
	 * @param string $kind     One of VereineArchiveRules::KINDS
	 * @param int    $objectId What the document belongs to, 0 when it gets its number only after it is built
	 * @return string
	 */
	public function codeFor($kind, $objectId)
	{
		global $conf;

		if ((int) $objectId > 0) {
			$sql = "SELECT code FROM ".MAIN_DB_PREFIX."vereine_document WHERE entity = ".((int) $conf->entity);
			$sql .= " AND kind = '".$this->db->escape($kind)."' AND fk_object = ".((int) $objectId);
			$resql = $this->db->query($sql);
			$obj = $resql ? $this->db->fetch_object($resql) : null;
			if ($obj) {
				return (string) $obj->code;
			}
		}
		do {
			$code = VereineArchiveRules::code(random_bytes(VereineArchiveRules::LENGTH));
		} while ($this->documentId($code) > 0);
		return $code;
	}

	/**
	 * The document of a code.
	 *
	 * @param string $code Code
	 * @return int 0 when unknown
	 */
	private function documentId($code)
	{
		$resql = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_document WHERE code = '".$this->db->escape($code)."'");
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Keep a finished document with its code and the checksum of the file just built.
	 *
	 * @param string $code     Code printed on it
	 * @param string $kind     One of VereineArchiveRules::KINDS
	 * @param int    $objectId What it belongs to
	 * @param string $title    Title, for the association's own list and the export; never shown by the public check
	 * @param string $file     The file
	 * @param string $what     One of VereineArchiveRules::FILES
	 * @return int Document, -1 on error
	 */
	public function register($code, $kind, $objectId, $title, $file, $what = 'built')
	{
		global $conf;

		$id = $this->documentId($code);
		if ($id === 0) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_document (entity, code, kind, fk_object, title, datec) VALUES (".((int) $conf->entity).",";
			$sql .= " '".$this->db->escape($code)."', '".$this->db->escape($kind)."', ".((int) $objectId).", '".$this->db->escape(dol_trunc((string) $title, 250, 'right', 'UTF-8', 1))."',";
			$sql .= " '".$this->db->idate(dol_now())."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_document');
		}
		return $this->addFile($id, $file, $what) < 0 ? -1 : $id;
	}

	/**
	 * Keep another file of a document that has a code: its signed copy or the scan of the signed paper.
	 *
	 * @param string $kind     Kind of the signature run
	 * @param int    $objectId What the run belongs to
	 * @param string $file     The file
	 * @param string $what     signed or scan
	 * @return int 1 when kept, 0 when the document has no code, -1 on error
	 */
	public function registerCopy($kind, $objectId, $file, $what)
	{
		global $conf;

		// A money matter is a resolution too.
		$kind = $kind === 'money' ? 'resolution' : (string) $kind;
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_document WHERE entity = ".((int) $conf->entity)." AND kind = '".$this->db->escape($kind)."'";
		$sql .= " AND fk_object = ".((int) $objectId);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return 0;
		}
		return $this->addFile((int) $obj->rowid, $file, $what) < 0 ? -1 : 1;
	}

	/**
	 * The checksum of a file of a document, once.
	 *
	 * @param int    $documentId Document
	 * @param string $file       The file
	 * @param string $what       One of VereineArchiveRules::FILES
	 * @return int 1 when kept or known, -1 on error
	 */
	private function addFile($documentId, $file, $what)
	{
		global $conf;

		if (!is_file($file)) {
			$this->error = 'no file '.$file;
			return -1;
		}
		$sha = (string) hash_file('sha256', $file);
		$root = rtrim((string) $conf->vereine->dir_output, '/').'/';
		$relative = strpos($file, $root) === 0 ? substr($file, strlen($root)) : basename($file);
		$resql = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_document_file WHERE fk_document = ".((int) $documentId)." AND sha256 = '".$this->db->escape($sha)."'");
		if ($resql && $this->db->fetch_object($resql)) {
			return 1;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_document_file (fk_document, sha256, relpath, what, datec) VALUES (".((int) $documentId).",";
		$sql .= " '".$this->db->escape($sha)."', '".$this->db->escape($relative)."', '".$this->db->escape($what)."', '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * What the check may say about a code: kind, day, checksums and who signed; no title, no content.
	 *
	 * @param string $code Code
	 * @return array{kind:string,created:int,files:array<int,array{sha256:string,what:string,created:int}>,signers:array<int,array{name:string,label:string,signed_at:int}>}|null
	 */
	public function find($code)
	{
		global $conf;

		require_once __DIR__.'/vereinesignatures.class.php';

		$sql = "SELECT rowid, kind, fk_object, datec FROM ".MAIN_DB_PREFIX."vereine_document WHERE entity = ".((int) $conf->entity)." AND code = '".$this->db->escape($code)."'";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		$found = array('kind' => (string) $obj->kind, 'created' => $this->db->jdate($obj->datec), 'files' => array(), 'signers' => array());
		$resql = $this->db->query("SELECT sha256, what, datec FROM ".MAIN_DB_PREFIX."vereine_document_file WHERE fk_document = ".((int) $obj->rowid)." ORDER BY rowid");
		while ($resql && ($row = $this->db->fetch_object($resql))) {
			$found['files'][] = array('sha256' => (string) $row->sha256, 'what' => (string) $row->what, 'created' => $this->db->jdate($row->datec));
		}
		$signatures = new VereineSignatures($this->db);
		foreach ($found['kind'] === 'resolution' ? array('resolution', 'money') : array($found['kind']) as $kind) {
			$run = $signatures->current($kind, (int) $obj->fk_object);
			foreach ($run !== null ? $run['people'] : array() as $person) {
				if ($person['signed_at'] > 0) {
					$found['signers'][] = array('name' => (string) $person['name'], 'label' => (string) $person['label'], 'signed_at' => (int) $person['signed_at']);
				}
			}
		}
		return $found;
	}

	/**
	 * Switch the public check on or off.
	 *
	 * @param bool $on   Whether it is on
	 * @param User $user Who decides
	 * @return int 1 when stored, -1 on error
	 */
	public function setPublic($on, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		if (dolibarr_set_const($this->db, self::PUBLIC_CHECK, $on ? '1' : '0', 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::ARCHIVE, 0, 0, 'public check '.($on ? 'on' : 'off'));
		return 1;
	}

	/**
	 * Every finished document of a period with its files: those with a code, the statutes and the letters to the authority.
	 *
	 * @param string    $from        First day, YYYY-MM-DD
	 * @param string    $to          Last day, YYYY-MM-DD
	 * @param Translate $outputlangs Language of the kinds
	 * @return array<int,array{day:string,kind:string,label:string,title:string,code:string,path:string,filename:string,name:string,sha256:string}>
	 */
	public function entries($from, $to, $outputlangs)
	{
		global $conf;

		require_once __DIR__.'/vereinestatutes.class.php';
		require_once __DIR__.'/vereineauthorityletters.class.php';

		$root = rtrim((string) $conf->vereine->dir_output, '/').'/';
		$entries = array();
		$add = function ($day, $kind, $title, $code, $path) use (&$entries, $outputlangs) {
			if ($path === '' || !is_file($path)) {
				return;
			}
			$entry = array('day' => $day, 'kind' => $kind, 'label' => $outputlangs->transnoentitiesnoconv('VereineArchiveKind_'.$kind), 'title' => $title,
				'code' => $code, 'path' => $path, 'filename' => basename($path), 'sha256' => (string) hash_file('sha256', $path));
			$entry['name'] = VereineArchiveRules::entryName($entry);
			$entries[] = $entry;
		};
		$sql = "SELECT d.code, d.kind, d.title, d.datec, f.relpath FROM ".MAIN_DB_PREFIX."vereine_document as d";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_document_file as f ON f.fk_document = d.rowid WHERE d.entity = ".((int) $conf->entity);
		$sql .= " AND d.datec BETWEEN '".$this->db->escape($from)." 00:00:00' AND '".$this->db->escape($to)." 23:59:59' ORDER BY d.datec, d.rowid, f.rowid";
		$resql = $this->db->query($sql);
		$seen = array();
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			// Only the files still there, each once even when it was kept for more than one document.
			$path = $root.(string) $obj->relpath;
			if (isset($seen[$path])) {
				continue;
			}
			$seen[$path] = true;
			$add(dol_print_date($this->db->jdate($obj->datec), '%Y-%m-%d', 'tzserver'), (string) $obj->kind, (string) $obj->title, (string) $obj->code, $path);
		}
		$statutes = new VereineStatutes($this->db);
		foreach ($statutes->versions() as $version) {
			$day = $version['decided_on'] !== '' ? substr($version['decided_on'], 0, 10) : '';
			if ($day >= $from && $day <= $to) {
				$add($day, 'statute', $outputlangs->transnoentities('VereineArchiveStatuteTitle', $version['version']), '', $statutes->path($version['id']));
			}
		}
		$letters = new VereineAuthorityLetters($this->db);
		foreach ($letters->fetchAll() as $letter) {
			$day = dol_print_date($letter['created'], '%Y-%m-%d', 'tzserver');
			if ($day >= $from && $day <= $to) {
				$add($day, 'letter', $outputlangs->transnoentitiesnoconv('VereineLetterTitle_'.$letter['kind']), '', $letters->path($letter['id']));
			}
		}
		usort($entries, function ($left, $right) {
			return strcmp($left['day'].$left['name'], $right['day'].$right['name']);
		});
		return $entries;
	}

	/**
	 * The files of a period as one ZIP: every document, a table of contents and the checksums.
	 *
	 * @param string    $from        First day, YYYY-MM-DD
	 * @param string    $to          Last day, YYYY-MM-DD
	 * @param User      $user        Who exports
	 * @param Translate $outputlangs Language of the table of contents
	 * @return string The ZIP, empty on error
	 */
	public function export($from, $to, $user, $outputlangs)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		if (!class_exists('ZipArchive')) {
			$this->error = 'ZipArchive is missing';
			return '';
		}
		$entries = $this->entries($from, $to, $outputlangs);
		$file = self::directory().'/vereinsakte-'.$from.'-'.$to.'.zip';
		if (dol_mkdir(dirname($file)) < 0) {
			$this->error = 'cannot create '.dirname($file);
			return '';
		}
		dol_delete_file($file);
		$zip = new ZipArchive();
		if ($zip->open($file, ZipArchive::CREATE) !== true) {
			$this->error = 'cannot write '.$file;
			return '';
		}
		foreach ($entries as $entry) {
			$zip->addFile($entry['path'], $entry['name']);
		}
		$zip->addFromString('inhaltsverzeichnis.csv', "\xEF\xBB\xBF".VereineArchiveRules::index($entries));
		$zip->addFromString('pruefsummen.sha256', VereineArchiveRules::sums($entries));
		if (!$zip->close()) {
			$this->error = 'cannot close '.$file;
			return '';
		}
		VereineLog::add($this->db, $user, VereineLog::ARCHIVE, 0, 0, 'export '.$from.' - '.$to.': '.count($entries).' files');
		return $file;
	}

	/**
	 * The documents with a code, newest first, for the association's own list.
	 *
	 * @param int $year Year of the document, 0 for all
	 * @return array<int,array{code:string,kind:string,title:string,created:int,files:int}>
	 */
	public function documents($year)
	{
		global $conf;

		$sql = "SELECT d.code, d.kind, d.title, d.datec, COUNT(f.rowid) as files FROM ".MAIN_DB_PREFIX."vereine_document as d";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."vereine_document_file as f ON f.fk_document = d.rowid WHERE d.entity = ".((int) $conf->entity);
		if ((int) $year > 0) {
			$sql .= " AND d.datec BETWEEN '".((int) $year)."-01-01 00:00:00' AND '".((int) $year)."-12-31 23:59:59'";
		}
		$sql .= " GROUP BY d.rowid, d.code, d.kind, d.title, d.datec ORDER BY d.datec DESC, d.rowid DESC";
		$documents = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$documents[] = array('code' => (string) $obj->code, 'kind' => (string) $obj->kind, 'title' => (string) $obj->title,
				'created' => $this->db->jdate($obj->datec), 'files' => (int) $obj->files);
		}
		return $documents;
	}
}
