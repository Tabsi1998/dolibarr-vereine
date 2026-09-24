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
 * \file    class/vereinepublicationrules.class.php
 * \ingroup vereine
 * \brief   Rules of publishing finished documents (#156): who may see which revision, when it goes out by itself.
 *
 * A document of the association's files is published revision by revision, for an audience the
 * association chose: its members, its board or the public. Nothing is published unless somebody said so,
 * by hand or by a rule for the kind of document; a rule publishes only a signed revision or the scan of
 * the signed paper, never a draft. Withdrawing a publication hides it again; the file stays in the files.
 */

/**
 * Rules of publishing documents.
 */
class VereinePublicationRules
{
	/** Who a document can be published for, from the narrowest. */
	const AUDIENCES = array('board', 'members', 'public');

	/** Revisions a rule may publish by itself: signed ones only. */
	const AUTO_FILES = array('signed', 'scan');

	/**
	 * The rules of every kind of document, as stored: audience ('' for none) and whether a signed revision goes out by itself.
	 *
	 * @param string   $stored JSON kind => {audience, auto}
	 * @param string[] $kinds  Kinds of documents
	 * @return array<string,array{audience:string,auto:bool}>
	 */
	public static function rules($stored, array $kinds)
	{
		$decoded = json_decode((string) $stored, true);
		$rules = array();
		foreach ($kinds as $kind) {
			$rule = is_array($decoded) && isset($decoded[$kind]) && is_array($decoded[$kind]) ? $decoded[$kind] : array();
			$audience = isset($rule['audience']) && in_array($rule['audience'], self::AUDIENCES, true) ? (string) $rule['audience'] : '';
			$rules[$kind] = array('audience' => $audience, 'auto' => $audience !== '' && !empty($rule['auto']));
		}
		return $rules;
	}

	/**
	 * The audience a rule publishes a new revision for, or '' when it does not.
	 *
	 * @param array<string,array{audience:string,auto:bool}> $rules From rules()
	 * @param string                                         $kind  Kind of the document
	 * @param string                                         $what  What the revision is: built, signed or scan
	 * @return string
	 */
	public static function autoAudience(array $rules, $kind, $what)
	{
		if (!isset($rules[$kind]) || !$rules[$kind]['auto'] || !in_array($what, self::AUTO_FILES, true)) {
			return '';
		}
		return $rules[$kind]['audience'];
	}

	/**
	 * Whether somebody sees a publication.
	 *
	 * @param string              $audience Audience of the publication
	 * @param array<string,bool>  $actor    public (anybody), member (an active member), board (on the board now)
	 * @return bool
	 */
	public static function sees($audience, array $actor)
	{
		if ($audience === 'public') {
			return true;
		}
		if ($audience === 'members') {
			return !empty($actor['member']);
		}
		return $audience === 'board' && !empty($actor['board']);
	}

	/**
	 * The name of a published file: kind, code and what the revision is; nothing from the title.
	 *
	 * @param string $kind Kind of the document
	 * @param string $code Code of the document
	 * @param string $what built, signed or scan
	 * @return string
	 */
	public static function filename($kind, $code, $what)
	{
		return preg_replace('/[^a-z0-9_-]/', '', strtolower($kind.'-'.$code.'-'.$what)).'.pdf';
	}
}
