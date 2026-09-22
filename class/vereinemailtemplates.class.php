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
 * \file    class/vereinemailtemplates.class.php
 * \ingroup vereine
 * \brief   The e-mails of the module as Dolibarr e-mail templates, which the association can change.
 *
 * Every kind of e-mail is a type of template in Dolibarr's editor (Setup - E-mails - Templates). The
 * module adds one template per kind with the text it always sent; a changed or added template of that
 * type is used instead. Only templates of the module's own types count: a template "for all" of Dolibarr
 * never replaces an invitation.
 */

require_once __DIR__.'/vereineplaceholders.class.php';

/**
 * The module's e-mail templates in Dolibarr.
 */
class VereineMailTemplates
{
	/** Invitation to a meeting. */
	const TYPE_INVITATION = 'vereine_invitation';
	/** Minutes sent to the board or the members. */
	const TYPE_MINUTES = 'vereine_minutes';
	/** Start of a circular resolution. */
	const TYPE_CIRCULAR = 'vereine_circular';
	/** Reminder of a circular resolution. */
	const TYPE_REMINDER = 'vereine_reminder';
	/** Every type, in the order the setup shows them. */
	const TYPES = array('vereine_invitation', 'vereine_minutes', 'vereine_circular', 'vereine_reminder');

	/** The groups of placeholders each type knows, besides those of the association. */
	const GROUPS = array(
		'vereine_invitation' => array('meeting'),
		'vereine_minutes' => array('meeting'),
		'vereine_circular' => array('circular'),
		'vereine_reminder' => array('circular'),
	);

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * @var array<string,string>|null Dolibarr's own placeholders, once per instance
	 */
	private $common = null;

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
	 * The text the module always sent, with placeholders instead of the values.
	 *
	 * @param string    $type        One of TYPES
	 * @param Translate $outputlangs Language
	 * @return array{label:string,topic:string,content:string}
	 */
	public static function defaults($type, $outputlangs)
	{
		$outputlangs->load('vereine@vereine');
		$label = $outputlangs->transnoentities('VereineMailTemplateType_'.$type);
		if ($type === self::TYPE_INVITATION) {
			$content = implode("\n", array(
				$outputlangs->transnoentities('VereineMeetingMailGreeting', '__VEREINE_EMPFAENGER__'), '',
				'__VEREINE_SITZUNG_EINLEITUNG__', '',
				'__VEREINE_SITZUNG_DETAILS__', '',
				$outputlangs->transnoentities('VereineMeetingMailAgenda'),
				'__VEREINE_TAGESORDNUNG__',
				'__VEREINE_SITZUNG_HINWEISE__',
				$outputlangs->transnoentities('VereineMeetingMailClosing'),
				$outputlangs->transnoentities('VereineMeetingMailSignature', '__VEREINE_NAME__'),
			));
			return array('label' => $label, 'content' => $content,
				'topic' => $outputlangs->transnoentities('VereineMeetingMailSubject', '__VEREINE_SITZUNG_TITEL__', '__VEREINE_SITZUNG_TAG__ __VEREINE_SITZUNG_ZEIT__'));
		}
		if ($type === self::TYPE_MINUTES) {
			return array('label' => $label, 'topic' => $outputlangs->transnoentities('VereineMinutesMailSubject', '__VEREINE_SITZUNG_TITEL__'),
				'content' => $outputlangs->transnoentities('VereineMinutesMailText', '__VEREINE_NAME__', '__VEREINE_SITZUNG_TITEL__', '__VEREINE_SITZUNG_TAG__'));
		}
		$reminder = $type === self::TYPE_REMINDER;
		$content = implode("\n", array(
			$outputlangs->transnoentities($reminder ? 'VereineCircularMailReminder' : 'VereineCircularMailIntro', '__VEREINE_EMPFAENGER__', '__VEREINE_NAME__'), '',
			'__VEREINE_UMLAUF_TITEL__',
			'__VEREINE_UMLAUF_ANTRAG__', '',
			$outputlangs->transnoentities('VereineCircularMailDeadline', '__VEREINE_UMLAUF_FRIST__'),
			$outputlangs->transnoentities('VereineCircularMailLink', '__VEREINE_UMLAUF_LINK__'),
		));
		return array('label' => $label, 'content' => $content,
			'topic' => $outputlangs->transnoentities($reminder ? 'VereineCircularMailSubjectReminder' : 'VereineCircularMailSubject', '__VEREINE_UMLAUF_TITEL__'));
	}

	/**
	 * A text of a template for a letter or the text part of an e-mail: HTML becomes plain text.
	 *
	 * @param string $text Text or HTML
	 * @return string
	 */
	public static function plain($text)
	{
		$text = (string) $text;
		if (!preg_match('/<(br|p|div|b|strong|i|em|u|ul|ol|li|span|a|table)\b/i', $text)) {
			return $text;
		}
		$text = (string) preg_replace('/\r?\n/', ' ', $text);
		$text = (string) preg_replace('/<br\s*\/?>/i', "\n", $text);
		$text = (string) preg_replace('/<\/(p|div|li|tr|h[1-6])>/i', "\n", $text);
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return trim((string) preg_replace('/[ \t]*\n[ \t]*/', "\n", $text));
	}

	/**
	 * Add the module's standard templates that are missing. A changed one stays; a deleted one comes back on the next activation.
	 *
	 * @param Translate $outputlangs Language of the texts
	 * @return int Templates added, -1 on error
	 */
	public function ensureDefaults($outputlangs)
	{
		global $conf;

		$added = 0;
		// With Dolibarr's HTML editor for e-mails a plain text would lose its line breaks.
		$html = getDolGlobalString('FCKEDITOR_ENABLE_MAIL') !== '';
		foreach (self::TYPES as $position => $type) {
			$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."c_email_templates WHERE type_template = '".$this->db->escape($type)."'";
			$sql .= " AND entity = ".((int) $conf->entity);
			$resql = $this->db->query($sql);
			$obj = $resql ? $this->db->fetch_object($resql) : null;
			if (!$obj) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			if ((int) $obj->nb > 0) {
				continue;
			}
			$template = self::defaults($type, $outputlangs);
			$content = $html ? nl2br(htmlspecialchars($template['content'], ENT_QUOTES, 'UTF-8'), false) : $template['content'];
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_email_templates (entity, module, type_template, lang, private, datec, label, position, defaultfortype, enabled, active, topic, content)";
			$sql .= " VALUES (".((int) $conf->entity).", 'vereine', '".$this->db->escape($type)."', '', 0, '".$this->db->idate(dol_now())."',";
			$sql .= " '".$this->db->escape($template['label'])."', ".((int) $position + 1).", 1, '1', 1,";
			$sql .= " '".$this->db->escape($template['topic'])."', '".$this->db->escape($content)."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$added++;
		}
		return $added;
	}

	/**
	 * The template the module uses for a type: an active public one of that type, in the language or for all.
	 *
	 * @param string    $type        One of TYPES
	 * @param Translate $outputlangs Language
	 * @return array{id:int,topic:string,content:string}|null
	 */
	public function find($type, $outputlangs)
	{
		$lang = is_object($outputlangs) ? (string) $outputlangs->defaultlang : '';
		$sql = "SELECT rowid, topic, content, lang FROM ".MAIN_DB_PREFIX."c_email_templates WHERE type_template = '".$this->db->escape($type)."'";
		$sql .= " AND entity IN (".getEntity('email_template').") AND active = 1 AND private = 0";
		$sql .= " AND (lang = '".$this->db->escape($lang)."' OR lang IS NULL OR lang = '')";
		// The one in the language first, then the one marked as default, then by position.
		$sql .= " ORDER BY CASE WHEN lang = '".$this->db->escape($lang)."' THEN 0 ELSE 1 END, defaultfortype DESC, position, rowid";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			return null;
		}
		return array('id' => (int) $obj->rowid, 'topic' => (string) $obj->topic, 'content' => (string) $obj->content);
	}

	/**
	 * Subject and text of one e-mail: the template of the type, else the standard text, with every placeholder filled.
	 *
	 * @param string               $type        One of TYPES
	 * @param array<string,string> $values      Values of the placeholders of this e-mail
	 * @param Translate            $outputlangs Language
	 * @return array{subject:string,body:string,html:bool,template:int}
	 */
	public function compose($type, array $values, $outputlangs)
	{
		$template = $this->find($type, $outputlangs);
		$source = $template !== null ? $template : self::defaults($type, $outputlangs);
		$html = (bool) preg_match('/<(br|p|div|b|strong|i|em|u|ul|ol|li|span|a|table)\b/i', $source['content']);
		$placeholders = new VereinePlaceholders($this->db);
		$own = array_merge($placeholders->associationValues(), $values);
		$subject = VereinePlaceholders::fill($source['topic'], $own);
		$body = VereinePlaceholders::fill($source['content'], $own, $html);
		// Then Dolibarr's own placeholders, such as __MYCOMPANY_NAME__ or __(Hello)__.
		$common = $this->common($outputlangs);
		$subject = trim(strip_tags(make_substitutions($subject, $common, $outputlangs)));
		$body = make_substitutions($body, $common, $outputlangs, $html ? 1 : 0);
		return array('subject' => $subject, 'body' => $body, 'html' => $html, 'template' => $template !== null ? $template['id'] : 0);
	}

	/**
	 * Dolibarr's own placeholders without an object, read once.
	 *
	 * @param Translate $outputlangs Language
	 * @return array<string,string>
	 */
	private function common($outputlangs)
	{
		if ($this->common === null) {
			$this->common = getCommonSubstitutionArray($outputlangs, 0, null, null);
			complete_substitutions_array($this->common, $outputlangs, null, array('mode' => 'vereine'));
		}
		return $this->common;
	}
}
