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
 * \file    class/vereinepublications.class.php
 * \ingroup vereine
 * \brief   Publishing finished documents of the association's files (#156) and handing them out (#157).
 *
 * A publication is one revision of one document for one audience. Publishing the same revision again
 * changes nothing; publishing a newer one replaces the older for that audience; withdrawing hides it.
 * Every download reads the publication again and hands out the bytes only when their checksum is the
 * one kept for the revision; a file that is gone or was changed is an error, never another file.
 */

require_once __DIR__.'/vereinepublicationrules.class.php';
require_once __DIR__.'/vereinearchiverules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Publications of documents.
 */
class VereinePublications
{
	/** Rules per kind of document: JSON kind => {audience, auto}. */
	const RULES = 'VEREINE_PUBLISH_RULES';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * @var string[] Messages for the person
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
	 * The rules in force.
	 *
	 * @return array<string,array{audience:string,auto:bool}>
	 */
	public static function rules()
	{
		return VereinePublicationRules::rules(getDolGlobalString(self::RULES), VereineArchiveRules::KINDS);
	}

	/**
	 * Keep the rules.
	 *
	 * @param array<string,array{audience:string,auto:bool}> $entered Kind => rule
	 * @param User                                           $user    Who
	 * @return int 1 when saved, -1 on error
	 */
	public function saveRules(array $entered, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$rules = VereinePublicationRules::rules((string) json_encode($entered), VereineArchiveRules::KINDS);
		if (dolibarr_set_const($this->db, self::RULES, (string) json_encode($rules), 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::PUBLICATION, 0, 0, 'rules');
		return 1;
	}

	/**
	 * Publish one revision of a document for an audience.
	 *
	 * @param int    $documentId Document
	 * @param int    $fileId     Revision
	 * @param string $audience   board, members or public
	 * @param User   $user       Who
	 * @return int Id of the publication, 0 when refused (see errors), -1 on error
	 */
	public function publish($documentId, $fileId, $audience, $user)
	{
		global $conf;

		$this->errors = array();
		$entity = (int) $conf->entity;
		$p = MAIN_DB_PREFIX;
		if (!in_array($audience, VereinePublicationRules::AUDIENCES, true)) {
			$this->errors[] = 'VereinePublicationErrorAudience';
		}
		$belongs = $this->value("SELECT COUNT(*) as v FROM ".$p."vereine_document_file as f INNER JOIN ".$p."vereine_document as d ON d.rowid = f.fk_document"
			." WHERE f.rowid = ".((int) $fileId)." AND d.rowid = ".((int) $documentId)." AND d.entity = ".$entity);
		if ((int) $belongs === 0) {
			$this->errors[] = 'VereinePublicationErrorFile';
		}
		if ($this->errors) {
			return 0;
		}
		// The same revision for the same audience again: nothing new.
		$same = (int) $this->value("SELECT rowid as v FROM ".$p."vereine_publication WHERE entity = ".$entity." AND fk_file = ".((int) $fileId)
			." AND audience = '".$this->db->escape($audience)."' AND withdrawn_at IS NULL");
		if ($same > 0) {
			return $same;
		}
		$this->db->begin();
		$now = $this->db->idate(dol_now());
		// A newer revision replaces the older one for that audience.
		$ok = (bool) $this->db->query("UPDATE ".$p."vereine_publication SET withdrawn_at = '".$now."', fk_user_withdrawn = ".((int) $user->id).", reason = 'replaced'"
			." WHERE entity = ".$entity." AND fk_document = ".((int) $documentId)." AND audience = '".$this->db->escape($audience)."' AND withdrawn_at IS NULL");
		$ok = $ok && $this->db->query("INSERT INTO ".$p."vereine_publication (entity, fk_document, fk_file, audience, published_at, fk_user) VALUES (".$entity.", ".((int) $documentId).","
			." ".((int) $fileId).", '".$this->db->escape($audience)."', '".$now."', ".((int) $user->id).")");
		if (!$ok) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$id = (int) $this->db->last_insert_id($p.'vereine_publication');
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::PUBLICATION, 0, 0, 'document '.((int) $documentId).' revision '.((int) $fileId).' for '.$audience);
		return $id;
	}

	/**
	 * Withdraw a publication. The file stays in the association's files.
	 *
	 * @param int  $publicationId Publication
	 * @param User $user          Who
	 * @return int 1 when withdrawn, 0 when there was none, -1 on error
	 */
	public function withdraw($publicationId, $user)
	{
		global $conf;

		$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_publication SET withdrawn_at = '".$this->db->idate(dol_now())."', fk_user_withdrawn = ".((int) $user->id).", reason = 'withdrawn'";
		$sql .= " WHERE rowid = ".((int) $publicationId)." AND entity = ".((int) $conf->entity)." AND withdrawn_at IS NULL";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ((int) $this->db->affected_rows($resql) === 0) {
			return 0;
		}
		VereineLog::add($this->db, $user, VereineLog::PUBLICATION, 0, 0, 'withdrawn '.((int) $publicationId));
		return 1;
	}

	/**
	 * A revision was added to the association's files: publish it when the rule of its kind says so.
	 *
	 * @param int    $documentId Document
	 * @param int    $fileId     Revision
	 * @param string $kind       Kind of the document
	 * @param string $what       built, signed or scan
	 * @param User   $user       Who caused it
	 * @return int Id of the publication, 0 when the rule publishes nothing, -1 on error
	 */
	public function onFile($documentId, $fileId, $kind, $what, $user)
	{
		$audience = VereinePublicationRules::autoAudience(self::rules(), $kind, $what);
		return $audience === '' ? 0 : $this->publish($documentId, $fileId, $audience, $user);
	}

	/**
	 * The publications in force, per document.
	 *
	 * @return array<int,array<int,array{id:int,audience:string,file_id:int,what:string,published_at:int}>>
	 */
	public function byDocument()
	{
		global $conf;

		$sql = "SELECT p.rowid, p.fk_document, p.audience, p.fk_file, p.published_at, f.what FROM ".MAIN_DB_PREFIX."vereine_publication as p";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_document_file as f ON f.rowid = p.fk_file WHERE p.entity = ".((int) $conf->entity)." AND p.withdrawn_at IS NULL ORDER BY p.rowid";
		$list = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$list[(int) $obj->fk_document][] = array('id' => (int) $obj->rowid, 'audience' => (string) $obj->audience, 'file_id' => (int) $obj->fk_file,
				'what' => (string) $obj->what, 'published_at' => (int) $this->db->jdate($obj->published_at));
		}
		return $list;
	}

	/**
	 * The newest revision of a document: signed before scan before built, then the latest.
	 *
	 * @param int $documentId Document
	 * @return int Revision, 0 when there is none
	 */
	public function bestFile($documentId)
	{
		return (int) $this->value("SELECT rowid as v FROM ".MAIN_DB_PREFIX."vereine_document_file WHERE fk_document = ".((int) $documentId)
			." ORDER BY CASE what WHEN 'signed' THEN 0 WHEN 'scan' THEN 1 ELSE 2 END, rowid DESC".$this->db->plimit(1));
	}

	/**
	 * What somebody may see: every document with a publication for them, the newest revision each.
	 *
	 * @param array<string,bool> $actor public, member, board
	 * @return array<int,array{document_id:int,revision:int,code:string,kind:string,title:string,date:string,what:string,sha256:string,size:int,audience:string}>
	 */
	public function catalog(array $actor)
	{
		global $conf;

		$root = rtrim((string) $conf->vereine->dir_output, '/').'/';
		$sql = "SELECT p.audience, p.fk_file, d.rowid as document, d.code, d.kind, d.title, f.what, f.sha256, f.relpath, f.datec FROM ".MAIN_DB_PREFIX."vereine_publication as p";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_document as d ON d.rowid = p.fk_document INNER JOIN ".MAIN_DB_PREFIX."vereine_document_file as f ON f.rowid = p.fk_file";
		$sql .= " WHERE p.entity = ".((int) $conf->entity)." AND p.withdrawn_at IS NULL ORDER BY f.datec DESC, p.fk_file DESC";
		$list = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$document = (int) $obj->document;
			if (isset($list[$document]) || !VereinePublicationRules::sees((string) $obj->audience, $actor)) {
				continue;
			}
			$path = $root.(string) $obj->relpath;
			$list[$document] = array('document_id' => $document, 'revision' => (int) $obj->fk_file, 'code' => VereineArchiveRules::format((string) $obj->code),
				'kind' => (string) $obj->kind, 'title' => (string) $obj->title, 'date' => dol_print_date($this->db->jdate($obj->datec), 'dayhourrfc'),
				'what' => (string) $obj->what, 'sha256' => (string) $obj->sha256, 'size' => is_file($path) ? (int) filesize($path) : 0, 'audience' => (string) $obj->audience);
		}
		return array_values($list);
	}

	/**
	 * The PDF of a published document for somebody, checked against the checksum kept for the revision.
	 *
	 * @param int                $documentId Document
	 * @param int                $revision   Revision, 0 for the one the catalog names
	 * @param array<string,bool> $actor      public, member, board
	 * @return array{filename:string,content_type:string,filesize:int,sha256:string,content:string}|null|false Null when not published for them, false when the file is gone or changed
	 */
	public function pdf($documentId, $revision, array $actor)
	{
		global $conf;

		$found = null;
		foreach ($this->catalog($actor) as $entry) {
			if ($entry['document_id'] === (int) $documentId) {
				$found = $entry;
			}
		}
		if ($found === null || ((int) $revision > 0 && (int) $revision !== $found['revision'])) {
			return null;
		}
		$relpath = $this->value("SELECT relpath as v FROM ".MAIN_DB_PREFIX."vereine_document_file WHERE rowid = ".((int) $found['revision']));
		$path = rtrim((string) $conf->vereine->dir_output, '/').'/'.$relpath;
		$content = is_file($path) ? file_get_contents($path) : false;
		if ($content === false || hash('sha256', $content) !== $found['sha256']) {
			$this->error = 'The archived file of revision '.$found['revision'].' is missing or was changed';
			return false;
		}
		return array('filename' => VereinePublicationRules::filename($found['kind'], (string) str_replace('-', '', $found['code']), $found['what']),
			'content_type' => 'application/pdf', 'filesize' => strlen($content), 'sha256' => $found['sha256'], 'content' => base64_encode($content));
	}

	/**
	 * Who a member is for the publications: an active member, and on the board today.
	 *
	 * @param int $memberId Member
	 * @return array{public:bool,member:bool,board:bool}
	 */
	public function actorFor($memberId)
	{
		global $conf;

		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$active = (int) $this->value("SELECT COUNT(*) as v FROM ".MAIN_DB_PREFIX."adherent WHERE rowid = ".((int) $memberId)." AND statut = 1") > 0;
		$board = (int) $this->value("SELECT COUNT(*) as v FROM ".MAIN_DB_PREFIX."vereine_function_term as t INNER JOIN ".MAIN_DB_PREFIX."vereine_function as f ON f.rowid = t.fk_function"
			." WHERE t.entity = ".((int) $conf->entity)." AND t.fk_adherent = ".((int) $memberId)." AND f.board = 1 AND t.date_start <= '".$today."'"
			." AND (t.date_end IS NULL OR t.date_end >= '".$today."')") > 0;
		return array('public' => true, 'member' => $active, 'board' => $active && $board);
	}

	/**
	 * One value of a query.
	 *
	 * @param string $sql Query with one column v
	 * @return string
	 */
	private function value($sql)
	{
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj && $obj->v !== null ? (string) $obj->v : '';
	}
}
