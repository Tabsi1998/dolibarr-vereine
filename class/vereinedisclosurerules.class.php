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
 * \file    class/vereinedisclosurerules.class.php
 * \ingroup vereine
 * \brief   Rules of the access to one's own data (#10, Art. 15 GDPR): what is asked, how the person was checked.
 */

/**
 * Rules of the access to one's own data.
 */
class VereineDisclosureRules
{
	/** How the association made sure the request came from the member, in the order the form offers them. */
	const CHECKS = array('known', 'id_document', 'member_email', 'id_austria', 'other');

	/** Sections of the copy, in the order of the document. New data of the module get their section here. */
	const SECTIONS = array('member', 'extra', 'subscriptions', 'invoices', 'consents', 'applications', 'functions', 'honours', 'exits', 'identities', 'accounts',
		'invitations', 'attendance', 'votes', 'signatures', 'tasks', 'duties', 'shifts', 'volunteer', 'donations', 'arrears', 'loans', 'log');

	/**
	 * A request as entered: the day it came in, how the person was checked, a short note.
	 *
	 * @param array<string,mixed> $entered requested_on, check, note
	 * @param string              $today   Today, YYYY-MM-DD
	 * @return array{request:array{requested_on:string,check:string,note:string},errors:string[]}
	 */
	public static function request(array $entered, $today)
	{
		$errors = array();
		$day = isset($entered['requested_on']) ? trim((string) $entered['requested_on']) : '';
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || $day > $today) {
			$errors[] = 'VereineDisclosureErrorDay';
		}
		$check = isset($entered['check']) ? (string) $entered['check'] : '';
		if (!in_array($check, self::CHECKS, true)) {
			$errors[] = 'VereineDisclosureErrorCheck';
		}
		$note = isset($entered['note']) ? mb_substr(trim((string) $entered['note']), 0, 255, 'UTF-8') : '';
		// Something else than a way the form names needs a word how it was done.
		if ($check === 'other' && $note === '') {
			$errors[] = 'VereineDisclosureErrorNote';
		}
		return array('request' => array('requested_on' => $day, 'check' => $check, 'note' => $note), 'errors' => $errors);
	}

	/**
	 * The deadline of the answer: one month after the request came in (Art. 12 (3) GDPR).
	 *
	 * @param string $requested Day the request came in, YYYY-MM-DD
	 * @return string YYYY-MM-DD
	 */
	public static function deadline($requested)
	{
		$year = (int) substr($requested, 0, 4);
		$month = (int) substr($requested, 5, 2) + 1;
		if ($month > 12) {
			$month = 1;
			$year++;
		}
		// The same day of the next month, or its last day when that month is shorter.
		$day = min((int) substr($requested, 8, 2), (int) date('t', mktime(12, 0, 0, $month, 1, $year)));
		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}

	/**
	 * The copy as JSON: who, when, and every section with its rows; empty sections stay, so nothing looks forgotten.
	 *
	 * @param array<string,mixed>                          $head     association, member, generated_at
	 * @param array<string,array<int,array<string,mixed>>> $sections Section => rows
	 * @return string
	 */
	public static function json(array $head, array $sections)
	{
		$ordered = array();
		foreach (self::SECTIONS as $section) {
			$ordered[$section] = isset($sections[$section]) ? array_values($sections[$section]) : array();
		}
		return (string) json_encode(array('format' => 'vereine-auskunft-1') + $head + array('sections' => $ordered),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * One row of a section as a line to read: field names turned into words, empty fields left out.
	 *
	 * @param array<string,mixed>  $row    Row
	 * @param array<string,string> $labels Field => word
	 * @return string
	 */
	public static function line(array $row, array $labels)
	{
		$parts = array();
		foreach ($row as $field => $value) {
			if ($value === null || $value === '' || $value === array()) {
				continue;
			}
			if (is_bool($value)) {
				$value = $value ? (isset($labels['_yes']) ? $labels['_yes'] : '1') : (isset($labels['_no']) ? $labels['_no'] : '0');
			}
			$parts[] = (isset($labels[$field]) ? $labels[$field] : (string) $field).': '.(is_array($value) ? implode(', ', $value) : (string) $value);
		}
		return implode(' · ', $parts);
	}
}
