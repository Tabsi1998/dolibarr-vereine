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
 * \file    class/vereinemeetingdocrules.class.php
 * \ingroup vereine
 * \brief   Documents of a meeting: which files are taken and what they are called. Plain PHP.
 *
 * Proof belongs to a vote: the count sheet, a scan of the ballots, the list of candidates, a signed
 * proxy. Only a scan or a photo is taken - a document nobody can read later is no proof.
 */

/**
 * Rules of the documents of a meeting.
 */
class VereineMeetingDocRules
{
	/** Proof of a vote or an election. */
	const KIND_VOTE = 'vote';
	/** A signed proxy of a member. */
	const KIND_PROXY = 'proxy';
	/** Anything else that belongs to the meeting. */
	const KIND_OTHER = 'other';
	/** Every kind in the order the page offers them. */
	const KINDS = array('vote', 'proxy', 'other');

	/** Largest file that is taken. */
	const MAX_SIZE = 10485760;

	/** File endings that are taken: a scan or a photo. */
	const EXTENSIONS = array('pdf', 'jpg', 'jpeg', 'png');

	/** Rows the count sheet gets when nobody says otherwise. */
	const SHEET_ROWS = 20;
	/** Most rows a count sheet can get. */
	const SHEET_ROWS_MAX = 60;

	/**
	 * Problems of an upload before the file is taken.
	 *
	 * @param mixed $upload One entry of $_FILES
	 * @return string[] Language keys, empty when the file is fine
	 */
	public static function check($upload)
	{
		$upload = is_array($upload) ? $upload : array();
		$name = isset($upload['name']) ? (string) $upload['name'] : '';
		$temporary = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
		$size = isset($upload['size']) ? (int) $upload['size'] : 0;
		if ($name === '' || $temporary === '' || $size <= 0) {
			return array('VereineMeetingDocErrorMissing');
		}
		if ($size > self::MAX_SIZE) {
			return array('VereineMeetingDocErrorSize');
		}
		if (!in_array(strtolower((string) pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
			return array('VereineMeetingDocErrorKind');
		}
		return array();
	}

	/**
	 * The name a file gets: the kind, what it belongs to and a moment, so nothing is ever overwritten.
	 *
	 * @param string $kind     One of the KIND constants
	 * @param int    $objectId Vote or member the file belongs to, 0 for the meeting itself
	 * @param string $original Name of the uploaded file
	 * @param string $moment   Moment as YYYYmmdd-HHMMSS
	 * @return string
	 */
	public static function name($kind, $objectId, $original, $moment)
	{
		$extension = strtolower((string) pathinfo((string) $original, PATHINFO_EXTENSION));
		if (!in_array($extension, self::EXTENSIONS, true)) {
			$extension = 'pdf';
		}
		$kind = in_array((string) $kind, self::KINDS, true) ? (string) $kind : self::KIND_OTHER;
		return $kind.'-'.max(0, (int) $objectId).'-'.preg_replace('/[^0-9\-]/', '', (string) $moment).'.'.$extension;
	}

	/**
	 * A label as entered that can be used as it is.
	 *
	 * @param mixed $label Entered label
	 * @return string
	 */
	public static function label($label)
	{
		return is_scalar($label) ? mb_substr(trim((string) $label), 0, 255, 'UTF-8') : '';
	}

	/**
	 * The count sheet as entered that can be used as it is.
	 *
	 * @param mixed $data Entered count sheet
	 * @return array{item:int,question:string,candidates:string[],rows:int}
	 */
	public static function sheet($data)
	{
		$data = is_array($data) ? $data : array();
		$question = isset($data['question']) && is_scalar($data['question']) ? mb_substr(trim((string) $data['question']), 0, 255, 'UTF-8') : '';
		$item = isset($data['item']) && is_scalar($data['item']) && preg_match('/^\d{1,3}$/', trim((string) $data['item'])) ? (int) trim((string) $data['item']) : 0;
		$rows = isset($data['rows']) && is_scalar($data['rows']) && preg_match('/^\d{1,3}$/', trim((string) $data['rows'])) ? (int) trim((string) $data['rows']) : self::SHEET_ROWS;
		$candidates = array();
		$entered = isset($data['candidates']) && is_scalar($data['candidates']) ? (string) $data['candidates'] : '';
		foreach (preg_split('/\r\n|\r|\n/', $entered) as $line) {
			$line = mb_substr(trim((string) $line), 0, 255, 'UTF-8');
			if ($line !== '') {
				$candidates[] = $line;
			}
		}
		return array(
			'item' => $item,
			'question' => $question,
			'candidates' => $candidates,
			'rows' => max(1, min($rows, self::SHEET_ROWS_MAX)),
		);
	}

	/**
	 * Problems of a count sheet before it is built.
	 *
	 * @param array<string,mixed> $sheet Normalized count sheet
	 * @return string[] Language keys, empty when fine
	 */
	public static function validateSheet(array $sheet)
	{
		return $sheet['question'] === '' ? array('VereineMeetingDocErrorQuestion') : array();
	}
}
