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
 * \file    class/vereinewaiting.class.php
 * \ingroup vereine
 * \brief   What waits for one person: votes in circular resolutions, signatures, tasks.
 *
 * Board members vote on circular resolutions in Dolibarr with their own user (decision of 21.09.2026), so
 * they have to see at once when something waits for them. Everything is found through the member the
 * Dolibarr user is linked to.
 */

/**
 * The open things of one member.
 */
class VereineWaiting
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

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
	 * What waits for a member, each with a text and a link.
	 *
	 * @param int $memberId Member the Dolibarr user is linked to
	 * @return array{votes:array<int,array<string,mixed>>,signatures:array<int,array<string,mixed>>,tasks:array<int,array<string,mixed>>}
	 */
	public function forMember($memberId)
	{
		global $conf;

		$waiting = array('votes' => array(), 'signatures' => array(), 'tasks' => array());
		if ((int) $memberId < 1) {
			return $waiting;
		}
		$entity = (int) $conf->entity;

		// Circular resolutions still open, where this member has not voted yet.
		$sql = "SELECT c.rowid, c.title, c.deadline FROM ".MAIN_DB_PREFIX."vereine_circular as c";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_circular_vote as v ON v.fk_circular = c.rowid AND v.entity = c.entity";
		$sql .= " WHERE c.entity = ".$entity." AND c.status = 'open' AND v.fk_adherent = ".((int) $memberId)." AND v.voted_at IS NULL ORDER BY c.deadline, c.rowid";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$waiting['votes'][] = array('id' => (int) $obj->rowid, 'title' => (string) $obj->title, 'deadline' => (string) $obj->deadline,
				'url' => dol_buildpath('/vereine/circulars.php', 1).'?id='.((int) $obj->rowid));
		}

		// Signature runs still open, where this member has not signed yet.
		$sql = "SELECT s.rowid, s.kind, s.fk_object, s.doc_name FROM ".MAIN_DB_PREFIX."vereine_signature as s";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."vereine_signature_person as p ON p.fk_signature = s.rowid";
		$sql .= " WHERE s.entity = ".$entity." AND s.status = 'open' AND p.fk_adherent = ".((int) $memberId)." AND p.signed_at IS NULL ORDER BY s.rowid";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$url = self::signatureUrl((string) $obj->kind, (int) $obj->fk_object);
			if ((string) $obj->kind === 'minutes') {
				// A run of the minutes belongs to a version; the page to sign it is the meeting.
				$version = $this->db->query("SELECT fk_meeting FROM ".MAIN_DB_PREFIX."vereine_meeting_minutes WHERE rowid = ".((int) $obj->fk_object));
				$row = $version ? $this->db->fetch_object($version) : null;
				$url = dol_buildpath('/vereine/meetings.php', 1).($row ? '?id='.((int) $row->fk_meeting).'#vereinemeetingminutes' : '');
			}
			$waiting['signatures'][] = array('id' => (int) $obj->rowid, 'kind' => (string) $obj->kind, 'title' => (string) $obj->doc_name, 'url' => $url);
		}

		// Tasks from resolutions and meetings, not done yet.
		$sql = "SELECT t.rowid, t.label, t.deadline, t.fk_resolution, t.fk_meeting FROM ".MAIN_DB_PREFIX."vereine_resolution_task as t";
		$sql .= " WHERE t.entity = ".$entity." AND t.fk_adherent = ".((int) $memberId)." AND t.done_at IS NULL ORDER BY t.deadline IS NULL, t.deadline, t.rowid";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$url = (int) $obj->fk_resolution > 0 ? dol_buildpath('/vereine/resolutions.php', 1).'?id='.((int) $obj->fk_resolution)
				: dol_buildpath('/vereine/meetings.php', 1).'?id='.((int) $obj->fk_meeting);
			$waiting['tasks'][] = array('id' => (int) $obj->rowid, 'title' => (string) $obj->label, 'deadline' => (string) $obj->deadline, 'url' => $url);
		}
		return $waiting;
	}

	/**
	 * Where a document of a signature run is signed.
	 *
	 * @param string $kind     Kind of document
	 * @param int    $objectId What the run belongs to
	 * @return string
	 */
	private static function signatureUrl($kind, $objectId)
	{
		if (in_array($kind, array('resolution', 'money'), true)) {
			return dol_buildpath('/vereine/resolutions.php', 1).'?id='.((int) $objectId).'#vereineresolutionpdf';
		}
		if ($kind === 'letter') {
			return dol_buildpath('/vereine/authority.php', 1);
		}
		return dol_buildpath('/vereine/meetings.php', 1);
	}
}
