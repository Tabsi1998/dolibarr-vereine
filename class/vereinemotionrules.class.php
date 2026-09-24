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
 * \file    class/vereinemotionrules.class.php
 * \ingroup vereine
 * \brief   Rules of what a member says about a meeting through an application (#159): an answer, a motion.
 *
 * An answer says whether somebody means to come; it is no attendance, no proof of an invitation and no
 * vote. A motion for the agenda of a general assembly arrives once, keeps the day it came, and is late
 * when the statutes' days before the assembly were already over. Whether it goes on the agenda is for the
 * board in Dolibarr to say.
 */

/**
 * Rules of answers and motions.
 */
class VereineMotionRules
{
	/** What somebody may answer to an invitation. */
	const RESPONSES = array('yes', 'no', 'maybe');

	/** States of a motion. */
	const STATES = array('received', 'accepted', 'rejected');

	/** How long the text of a motion may be. */
	const TEXT_MAX = 5000;

	/**
	 * The last day a motion is in time: the days of the statutes before the meeting.
	 *
	 * @param string $meetingDay Day of the meeting, YYYY-MM-DD
	 * @param int    $days       Days before the meeting motions must arrive
	 * @return string YYYY-MM-DD
	 */
	public static function deadline($meetingDay, $days)
	{
		return date('Y-m-d', strtotime($meetingDay.' 12:00:00 -'.max(0, (int) $days).' days'));
	}

	/**
	 * A motion as sent.
	 *
	 * @param mixed $sent external_id, title, text
	 * @return array{motion:array{external_id:string,title:string,text:string},errors:string[]}
	 */
	public static function check($sent)
	{
		$sent = is_array($sent) ? $sent : array();
		$errors = array();
		$external = isset($sent['external_id']) && is_scalar($sent['external_id']) ? trim((string) $sent['external_id']) : '';
		$title = isset($sent['title']) && is_scalar($sent['title']) ? trim(preg_replace('/\s+/u', ' ', (string) $sent['title'])) : '';
		$text = isset($sent['text']) && is_scalar($sent['text']) ? trim(str_replace("\r", '', (string) $sent['text'])) : '';
		if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $external)) {
			$errors[] = 'external_id must be 1 to 64 letters, digits or . _ : -';
		}
		if ($title === '' || mb_strlen($title, 'UTF-8') > 255) {
			$errors[] = 'title is needed, at most 255 characters';
		}
		if (mb_strlen($text, 'UTF-8') > self::TEXT_MAX) {
			$errors[] = 'text may have at most '.self::TEXT_MAX.' characters';
		}
		if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $title.$text)) {
			$errors[] = 'title and text may not hold control characters';
		}
		return array('motion' => array('external_id' => $external, 'title' => $title, 'text' => $text), 'errors' => $errors);
	}

	/**
	 * What a motion says, to tell a repetition from a different motion under the same id.
	 *
	 * @param array{title:string,text:string} $motion Motion
	 * @return string
	 */
	public static function fingerprint(array $motion)
	{
		return hash('sha256', $motion['title']."\n".$motion['text']);
	}
}
