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
 * \file    class/vereinearchiverules.class.php
 * \ingroup vereine
 * \brief   Rules of the files of the association (#123): the code on a finished document, the index and the checksums.
 */

/**
 * Rules of the files of the association.
 */
class VereineArchiveRules
{
	/** Letters of a code: no 0 and O, no 1 and I, so nobody misreads a printout. */
	const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	/** Length of a code; 32^10 codes are too many to guess one. */
	const LENGTH = 10;

	/** Kinds of finished documents that carry a code, in the order of the index. */
	const KINDS = array('minutes', 'resolution', 'audit_report', 'account', 'payout', 'statute', 'letter');

	/** Kinds of files of a document: as built, signed with ID Austria, the signed paper as a scan. */
	const FILES = array('built', 'signed', 'scan', 'excerpt');

	/** A shortened version for members or the public, a file of its own derived from another revision (#239). */
	const FILE_EXCERPT = 'excerpt';

	/**
	 * A new code from random bytes.
	 *
	 * @param string $bytes At least LENGTH random bytes
	 * @return string LENGTH letters of the alphabet
	 */
	public static function code($bytes)
	{
		$code = '';
		for ($index = 0; $index < self::LENGTH; $index++) {
			$code .= self::ALPHABET[ord($bytes[$index]) % strlen(self::ALPHABET)];
		}
		return $code;
	}

	/**
	 * A code as somebody typed it: small letters, spaces and dashes allowed.
	 *
	 * @param mixed $value Entered
	 * @return string The code, empty when it cannot be one
	 */
	public static function normalize($value)
	{
		$code = strtoupper((string) preg_replace('/[\s\-]+/', '', (string) $value));
		return strlen($code) === self::LENGTH && strspn($code, self::ALPHABET) === self::LENGTH ? $code : '';
	}

	/**
	 * A code as printed: two groups of five.
	 *
	 * @param string $code Code
	 * @return string
	 */
	public static function format($code)
	{
		return substr((string) $code, 0, 5).'-'.substr((string) $code, 5);
	}

	/**
	 * A period of the file export: two days, the first not after the second.
	 *
	 * @param mixed $from First day, YYYY-MM-DD
	 * @param mixed $to   Last day, YYYY-MM-DD
	 * @return array{from:string,to:string}|null
	 */
	public static function period($from, $to)
	{
		$valid = static function ($day) {
			return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $day, $parts) && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
		};
		if (!$valid($from) || !$valid($to) || (string) $from > (string) $to) {
			return null;
		}
		return array('from' => (string) $from, 'to' => (string) $to);
	}

	/**
	 * The name a file gets in the export: day, kind, number, and its own name, safe on every system.
	 *
	 * @param array<string,mixed> $entry day, kind, code, filename
	 * @return string
	 */
	public static function entryName(array $entry)
	{
		$base = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) $entry['filename']));
		return $entry['day'].'_'.$entry['kind'].'_'.($entry['code'] !== '' ? $entry['code'].'_' : '').$base;
	}

	/**
	 * The table of contents of an export, as CSV with a header: one line per file.
	 *
	 * @param array<int,array<string,mixed>> $entries day, kind label, title, code, name, sha256
	 * @return string
	 */
	public static function index(array $entries)
	{
		$cell = static function ($value) {
			$value = str_replace(array("\r", "\n"), ' ', (string) $value);
			return strpbrk($value, ';"') !== false ? '"'.str_replace('"', '""', $value).'"' : $value;
		};
		$lines = array('Datum;Art;Titel;Kennung;Datei;SHA-256');
		foreach ($entries as $entry) {
			$lines[] = implode(';', array_map($cell, array($entry['day'], $entry['label'], $entry['title'],
				$entry['code'] !== '' ? self::format($entry['code']) : '', $entry['name'], $entry['sha256'])));
		}
		return implode("\r\n", $lines)."\r\n";
	}

	/**
	 * The checksums of an export, in the format sha256sum reads back.
	 *
	 * @param array<int,array<string,mixed>> $entries name, sha256
	 * @return string
	 */
	public static function sums(array $entries)
	{
		$lines = '';
		foreach ($entries as $entry) {
			$lines .= $entry['sha256'].'  '.$entry['name']."\n";
		}
		return $lines;
	}

	/**
	 * What is wrong with an uploaded shortened version, '' when it can be kept.
	 *
	 * @param int    $parent The revision it shortens, 0 when the document has none
	 * @param string $name   Name of the uploaded file
	 * @param int    $size   Its size
	 * @param string $head   Its first five bytes
	 * @param int    $max    The largest size
	 * @return string Language key
	 */
	public static function excerptProblem($parent, $name, $size, $head, $max)
	{
		if ((int) $parent <= 0) {
			return 'VereinePublicationErrorFile';
		}
		if ((string) $name === '' || (int) $size <= 0) {
			return 'VereineExcerptErrorMissing';
		}
		if ((int) $size > (int) $max) {
			return 'VereineExcerptErrorSize';
		}
		return strtolower((string) pathinfo((string) $name, PATHINFO_EXTENSION)) === 'pdf' && (string) $head === '%PDF-' ? '' : 'VereineExcerptErrorKind';
	}
}
