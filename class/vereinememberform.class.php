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
 * \file    class/vereinememberform.class.php
 * \ingroup vereine
 * \brief   The application for membership as PDF (#108): blank to print, or filled in for a member.
 *
 * Everything on the form comes from Dolibarr: the association from the setup, the fee from the member
 * type's fee model, the notice period from the exit rule, the consents from their current texts. What
 * the association writes itself are two texts and the link to its privacy notice.
 */

require_once __DIR__.'/vereineorganization.class.php';
require_once __DIR__.'/vereinepdf.class.php';
require_once __DIR__.'/vereineapplicationformrules.class.php';
require_once __DIR__.'/vereinefeemodel.class.php';
require_once __DIR__.'/vereinefeerules.class.php';
require_once __DIR__.'/vereineconsents.class.php';
require_once __DIR__.'/vereineexits.class.php';
require_once __DIR__.'/vereinestatutes.class.php';
require_once __DIR__.'/vereinestatutetext.class.php';
require_once __DIR__.'/vereineplaceholders.class.php';
require_once __DIR__.'/vereinefeediscountstore.class.php';
require_once __DIR__.'/vereinefeefamilystore.class.php';
require_once __DIR__.'/vereinesepastore.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * The application for membership as PDF.
 */
class VereineMemberForm
{
	/** Text above the fields. */
	const INTRO = 'VEREINE_APPLICATION_INTRO';
	/** What the association says about data protection. */
	const PRIVACY = 'VEREINE_APPLICATION_PRIVACY';
	/** Web address of the privacy notice. */
	const PRIVACY_URL = 'VEREINE_APPLICATION_PRIVACY_URL';
	/** Fields the association wants filled in, comma separated. */
	const REQUIRED = 'VEREINE_APPLICATION_REQUIRED';
	/** Bank account of the association shown in the footer. */
	const ACCOUNT = 'VEREINE_APPLICATION_ACCOUNT';

	/** Fields of the person, in the order of the form. */
	const FIELDS = array('lastname', 'firstname', 'birth', 'gender', 'address', 'zip', 'town', 'country', 'phone', 'email');
	/** Fields the association may require; the name is required anyway (#216). */
	const REQUIRABLE = VereineApplicationFormRules::REQUIRABLE;
	/** Own fields of the member on the form, as JSON of code => 1 when required (#216). */
	const EXTRA = 'VEREINE_APPLICATION_EXTRAFIELDS';
	/** Age from which somebody signs alone (§ 21 (2) ABGB). */
	const ADULT = 18;

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
	 * What the association set for the form.
	 *
	 * The required fields always carry the name; until the association saves its own choice the address
	 * is required too, because the register of members needs it. The own fields are the additional fields
	 * of the member the association put on the form, each with its switch.
	 *
	 * @param string[] $known Codes of the additional fields of the member, to drop ones that are gone
	 * @return array{intro:string,privacy:string,privacy_url:string,required:string[],extra:array<string,bool>,account:int}
	 */
	public static function settings(array $known = array())
	{
		return array(
			'intro' => (string) getDolGlobalString(self::INTRO),
			'privacy' => (string) getDolGlobalString(self::PRIVACY),
			'privacy_url' => (string) getDolGlobalString(self::PRIVACY_URL),
			'required' => VereineApplicationFormRules::required(getDolGlobalString(self::REQUIRED, VereineApplicationFormRules::DEFAULT_REQUIRED)),
			'extra' => VereineApplicationFormRules::extraFields(getDolGlobalString(self::EXTRA), $known),
			'account' => getDolGlobalInt(self::ACCOUNT),
		);
	}

	/**
	 * The additional fields of the member an association may put on the form: code => label.
	 *
	 * @param DoliDB $db Database handler
	 * @return array<string,string>
	 */
	public static function memberExtraFields($db)
	{
		return array_map(function ($spec) {
			return $spec['label'];
		}, self::memberExtraFieldSpecs($db));
	}

	/**
	 * The additional fields of the member a form can ask for, each with its kind, options and length (#226).
	 *
	 * Read from Dolibarr's own additional fields, in their order; fields that point into other tables or
	 * hold secrets are left out, and so are the module's own fields, which have their own place.
	 *
	 * @param DoliDB $db Database handler
	 * @return array<string,array{label:string,kind:string,options:array<string,string>,max:int,integer:bool,pos:int}>
	 */
	public static function memberExtraFieldSpecs($db)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		require_once __DIR__.'/vereineapplicationformrules.class.php';

		$extrafields = new ExtraFields($db);
		$extrafields->fetch_name_optionals_label('adherent');
		$attributes = isset($extrafields->attributes['adherent']) ? $extrafields->attributes['adherent'] : array();
		$specs = array();
		foreach (isset($attributes['label']) && is_array($attributes['label']) ? $attributes['label'] : array() as $code => $label) {
			$code = (string) $code;
			$type = isset($attributes['type'][$code]) ? (string) $attributes['type'][$code] : '';
			$kind = VereineApplicationFormRules::kindOf($type);
			if ($kind === '' || strpos($code, 'vereine_') === 0) {
				continue;
			}
			$param = isset($attributes['param'][$code]) && is_array($attributes['param'][$code]) ? $attributes['param'][$code] : array();
			$options = array();
			foreach (isset($param['options']) && is_array($param['options']) ? $param['options'] : array() as $value => $text) {
				if ((string) $value !== '') {
					// Dolibarr may keep a parent after a bar; the form shows the option itself.
					$options[(string) $value] = (string) preg_replace('/\|.*$/', '', (string) $text);
				}
			}
			$size = isset($attributes['size'][$code]) ? (string) $attributes['size'][$code] : '';
			$specs[$code] = array('label' => (string) $label, 'kind' => $kind, 'options' => $options,
				'max' => $kind === 'text' && ctype_digit($size) ? (int) $size : 0, 'integer' => $type === 'int',
				'pos' => isset($attributes['pos'][$code]) ? (int) $attributes['pos'][$code] : 0);
		}
		uasort($specs, function ($left, $right) {
			return $left['pos'] - $right['pos'];
		});
		return $specs;
	}

	/**
	 * Create an own field of the member right from the setup of the form (#226).
	 *
	 * It becomes one of Dolibarr's additional fields of the member, with the member card showing it too;
	 * changing and deleting it stays with Dolibarr.
	 *
	 * @param DoliDB   $db      Database handler
	 * @param string   $label   What the field is called
	 * @param string   $kind    One of VereineApplicationFormRules::KINDS
	 * @param string   $options For a choice: one option per line
	 * @param string[] $errors  Language keys of what was refused, or a message of Dolibarr
	 * @return string Code of the new field, empty when refused
	 */
	public static function addField($db, $label, $kind, $options, array &$errors)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		require_once __DIR__.'/vereineapplicationformrules.class.php';

		$label = trim((string) $label);
		if ($label === '' || mb_strlen($label, 'UTF-8') > 100) {
			$errors[] = 'VereineApplicationNewFieldErrorLabel';
			return '';
		}
		if (!in_array($kind, VereineApplicationFormRules::KINDS, true)) {
			$errors[] = 'VereineApplicationNewFieldErrorKind';
			return '';
		}
		$choices = in_array($kind, array('select', 'multi'), true) ? VereineApplicationFormRules::options($options) : array();
		if (in_array($kind, array('select', 'multi'), true) && count($choices) < 2) {
			$errors[] = 'VereineApplicationNewFieldErrorOptions';
			return '';
		}
		$extrafields = new ExtraFields($db);
		$extrafields->fetch_name_optionals_label('adherent');
		$attributes = isset($extrafields->attributes['adherent']) ? $extrafields->attributes['adherent'] : array();
		$code = VereineApplicationFormRules::code($label, array_keys(isset($attributes['label']) && is_array($attributes['label']) ? $attributes['label'] : array()));
		if ($code === '') {
			$errors[] = 'VereineApplicationNewFieldErrorLabel';
			return '';
		}
		// After the fields there are, so the form keeps its order.
		$position = 10;
		foreach (isset($attributes['pos']) && is_array($attributes['pos']) ? $attributes['pos'] : array() as $pos) {
			$position = max($position, (int) $pos + 10);
		}
		$type = VereineApplicationFormRules::DOLIBARR_TYPES[$kind];
		$result = $extrafields->addExtraField($code, $label, $type[0], $position, $type[1], 'adherent', 0, 0, '', $choices ? array('options' => $choices) : '',
			1, '', '1', '', '', '', '', '1', 0, 1);
		if ($result <= 0) {
			$errors[] = (string) $extrafields->error !== '' ? (string) $extrafields->error : 'VereineApplicationNewFieldErrorCreate';
			return '';
		}
		return $code;
	}

	/**
	 * A value an own field of the member holds, as the form prints it: a date as a day, a choice by its text.
	 *
	 * @param array<string,mixed> $spec        Field, see memberExtraFieldSpecs()
	 * @param mixed               $value       Value of the member
	 * @param Translate           $outputlangs Language
	 * @return string Empty when there is none
	 */
	public static function shownValue(array $spec, $value, $outputlangs)
	{
		if ($value === null || $value === '' || (is_array($value) && !$value)) {
			return '';
		}
		switch ($spec['kind']) {
			case 'date':
				if (is_numeric($value)) {
					return dol_print_date((int) $value, 'day', 'tzserver', $outputlangs);
				}
				return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $value, $parts) ? $parts[3].'.'.$parts[2].'.'.$parts[1] : (string) $value;
			case 'boolean':
				return $outputlangs->transnoentitiesnoconv(!empty($value) ? 'Yes' : 'No');
			case 'select':
			case 'multi':
				$shown = array();
				foreach (is_array($value) ? $value : explode(',', (string) $value) as $one) {
					$one = trim((string) $one);
					if ($one !== '') {
						$shown[] = isset($spec['options'][$one]) ? $outputlangs->transnoentitiesnoconv($spec['options'][$one]) : $one;
					}
				}
				return implode(', ', $shown);
		}
		return (string) $value;
	}

	/**
	 * The fields that must be filled in, from what was entered.
	 *
	 * @param mixed $entered Comma separated list or array of field names
	 * @return string[] Fields of REQUIRABLE, in their order
	 */
	public static function requiredFields($entered)
	{
		$list = is_array($entered) ? $entered : explode(',', (string) $entered);
		$fields = array();
		foreach (self::REQUIRABLE as $field) {
			foreach ($list as $value) {
				if (is_scalar($value) && trim((string) $value) === $field) {
					$fields[] = $field;
					break;
				}
			}
		}
		return $fields;
	}

	/**
	 * Whether somebody is under age on a day, from the date of birth.
	 *
	 * @param string $birth Date of birth, YYYY-MM-DD, empty when unknown
	 * @param string $day   Day to count on, YYYY-MM-DD
	 * @return bool False when the date of birth is unknown
	 */
	public static function isMinor($birth, $day)
	{
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $birth) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $day)) {
			return false;
		}
		$years = (int) substr($day, 0, 4) - (int) substr($birth, 0, 4);
		if (substr($day, 5) < substr($birth, 5)) {
			$years--;
		}
		return $years < self::ADULT;
	}

	/**
	 * Path of the blank form of a member type.
	 *
	 * @param int $typeId Member type, 0 for none
	 * @return string
	 */
	public static function blankPath($typeId)
	{
		global $conf;

		return DOL_DATA_ROOT.($conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/vereine/application/mitgliedsantrag-'.((int) $typeId).'.pdf';
	}

	/**
	 * The member type with its fee model, or null when it is gone.
	 *
	 * @param int $typeId Member type
	 * @return array<string,mixed>|null
	 */
	public function memberType($typeId)
	{
		$model = new VereineFeeModel($this->db);
		$types = $model->memberTypes(false);
		return isset($types[(int) $typeId]) ? $types[(int) $typeId] : null;
	}

	/**
	 * Build the form: blank for a member type, or filled in for a member.
	 *
	 * @param Adherent|null $member      Member, null for a blank form
	 * @param int           $typeId      Member type; taken from the member when there is one
	 * @param Translate            $outputlangs Language
	 * @param string               $file        Where to write it
	 * @param array<string,mixed>  $submitted   An application that came in over the website: at, signature (path of a PNG)
	 * @return string Path, empty on error
	 */
	public function build($member, $typeId, $outputlangs, $file, array $submitted = array())
	{
		global $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		require_once dirname(__DIR__).'/lib/vereine.lib.php';

		$outputlangs->loadLangs(array('members', 'companies', 'vereine@vereine'));
		if (dol_mkdir(dirname($file)) < 0) {
			$this->error = 'cannot create '.dirname($file);
			return '';
		}
		$type = $this->memberType($member !== null ? (int) $member->typeid : (int) $typeId);
		$extraSpecs = self::memberExtraFieldSpecs($this->db);
		$settings = self::settings(array_keys($extraSpecs));
		$organization = VereineOrganization::load($mysoc);
		$fillable = VereinePdf::fillable('application');
		$pdf = VereinePdf::start($outputlangs);
		$font = pdf_getPDFFont($outputlangs);
		$text = function ($value, $style = '', $size = 10) use ($pdf, $font) {
			$pdf->SetFont($font, $style, $size);
			$pdf->MultiCell(0, 5, $value, 0, 'L');
		};
		// A field of the form: what it is called, and a line with the value on it or to write on.
		$field = function ($name, $label, $value, $required = false) use ($pdf, $font, $fillable) {
			$pdf->SetFont($font, '', 9);
			$pdf->MultiCell(28, 7, $label.($required ? ' *' : ''), 0, 'L', false, 0, '', '', true, 0, false, true, 7, 'B');
			$pdf->SetFont($font, '', 10);
			VereinePdf::input($pdf, 'antrag_'.$name, 142, $fillable, 7, $value);
		};
		// Two short fields side by side, such as first and last name (#202).
		$pair = function (array $left, array $right) use ($pdf, $font, $fillable) {
			foreach (array($left, $right) as $index => $one) {
				$pdf->SetX(VereinePdf::SIDE + $index * 87);
				$pdf->SetFont($font, '', 9);
				$pdf->MultiCell(28, 7, $one[1].(!empty($one[3]) ? ' *' : ''), 0, 'L', false, 0, '', '', true, 0, false, true, 7, 'B');
				$pdf->SetFont($font, '', 10);
				VereinePdf::line($pdf, 'antrag_'.$one[0], 55, $fillable, 7, $one[2]);
			}
			$pdf->Ln(7);
		};
		$yesNo = function ($name, $label) use ($pdf, $outputlangs, $fillable) {
			VereinePdf::choice($pdf, $outputlangs, 'antrag_'.$name, $label, $fillable);
		};

		VereinePdf::title($pdf, $outputlangs, $outputlangs->transnoentities('VereineApplicationTitle'));
		if ($settings['intro'] !== '') {
			$text($this->filled($settings['intro'], $member));
			$pdf->Ln(2);
		}

		VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineApplicationPerson'));
		$person = array();
		foreach (self::FIELDS as $name) {
			$person[$name] = array($name, $outputlangs->transnoentitiesnoconv('VereineApplicationField_'.$name), $this->personValue($member, $name, $outputlangs),
				in_array($name, $settings['required'], true));
		}
		// Short fields in pairs, the long ones across the page.
		foreach (array(array('lastname', 'firstname'), array('birth', 'gender'), array('address'), array('zip', 'town'), array('country', 'phone'), array('email')) as $row) {
			if (count($row) === 2) {
				$pair($person[$row[0]], $person[$row[1]]);
			} else {
				$field($person[$row[0]][0], $person[$row[0]][1], $person[$row[0]][2], $person[$row[0]][3]);
			}
		}
		// The association's own fields, each by its kind, with the value the member already has (#216, #226).
		foreach ($extraSpecs as $code => $spec) {
			if (!isset($settings['extra'][$code])) {
				continue;
			}
			$mustHave = $settings['extra'][$code];
			$label = $outputlangs->transnoentitiesnoconv($spec['label']);
			$shown = self::shownValue($spec, $member !== null && isset($member->array_options['options_'.$code]) ? $member->array_options['options_'.$code] : '', $outputlangs);
			if ($shown !== '' || in_array($spec['kind'], array('text', 'number'), true)) {
				$field('extra_'.$code, $label, $shown, $mustHave);
			} elseif ($spec['kind'] === 'date') {
				$field('extra_'.$code, $label.' ('.$outputlangs->transnoentitiesnoconv('VereineApplicationDateHint').')', '', $mustHave);
			} elseif ($spec['kind'] === 'textarea') {
				$field('extra_'.$code, $label, '', $mustHave);
				$pdf->SetX(VereinePdf::SIDE + 28);
				VereinePdf::input($pdf, 'antrag_extra_'.$code.'_2', 142, $fillable, 7);
			} elseif ($spec['kind'] === 'boolean') {
				$pdf->SetX(VereinePdf::SIDE);
				VereinePdf::tick($pdf, $outputlangs, 'antrag_extra_'.$code, $label.($mustHave ? ' *' : ''), $fillable);
			} else {
				// A choice: a box for every option, wrapped where the line ends.
				$pdf->SetFont($font, '', 9);
				$pdf->MultiCell(28, 7, $label.($mustHave ? ' *' : ''), 0, 'L', false, 0, '', '', true, 0, false, true, 7, 'B');
				$pdf->SetFont($font, '', 10);
				foreach ($spec['options'] as $option => $optionLabel) {
					$optionLabel = $outputlangs->transnoentitiesnoconv($optionLabel);
					$width = $pdf->GetStringWidth($optionLabel) + 10;
					if ($pdf->GetX() + $width > $pdf->getPageWidth() - VereinePdf::SIDE) {
						$pdf->Ln(7);
						$pdf->SetX(VereinePdf::SIDE + 28);
					}
					VereinePdf::box($pdf, 'antrag_extra_'.$code.'_'.$option, $fillable, $option);
					$pdf->Cell($width - 6, 7, $optionLabel, 0, 0, 'L');
				}
				$pdf->Ln(7);
			}
		}
		if ($settings['required'] || in_array(true, $settings['extra'], true)) {
			$pdf->SetFont($font, 'I', 8);
			$pdf->MultiCell(0, 4, $outputlangs->transnoentitiesnoconv('VereineApplicationRequiredHint'), 0, 'L');
		}

		VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineApplicationMembership'));
		$field('mitgliedsart', $outputlangs->transnoentitiesnoconv('VereineApplicationType'), $type !== null ? $type['label'] : '');
		foreach ($this->feeLines($type, $outputlangs) as $line) {
			$text($line);
		}
		$bank = self::bank($this->db);
		if ($bank !== '') {
			$text($outputlangs->transnoentities('VereineApplicationBankLine', $bank), '', 9);
		}
		$text(self::exitSentence((new VereineExits($this->db))->rule(), $outputlangs));
		foreach ($type !== null ? $this->discountLines((int) $type['id'], $outputlangs) : array() as $line) {
			$text($line, '', 9);
		}
		// What the member type includes, as the association wrote it at the member type in Dolibarr.
		if ($type !== null && trim((string) $type['note']) !== '') {
			$text($outputlangs->transnoentitiesnoconv('VereineApplicationIncluded'), 'B', 9);
			$text(dol_string_nohtmltag((string) $type['note'], 0), '', 9);
		}
		$pdf->Ln(1);

		// Everything here comes from the statutes of the association, nothing is written twice.
		$statutes = $this->statuteFacts($outputlangs);
		if ($statutes) {
			VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineApplicationStatutesTitle'));
			foreach ($statutes as $line) {
				$text($line, '', 9);
			}
			$pdf->Ln(1);
		}
		VereinePdf::tick($pdf, $outputlangs, 'antrag_statuten', $outputlangs->transnoentitiesnoconv('VereineApplicationStatutes'), $fillable);

		$consents = (new VereineConsents($this->db))->currentTexts();
		if ($consents) {
			VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineApplicationConsents'));
			foreach ($consents as $consent) {
				$body = $consent['text'] !== '' ? dol_string_nohtmltag($consent['text'], 0) : '';
				$pdf->SetFont($font, '', 9);
				// Title, text and the box to tick belong together on one page (#203).
				$needed = 6 + ($body !== '' ? $pdf->getStringHeight(170, $body) : 0) + 10;
				if ($pdf->GetY() + $needed > $pdf->getPageHeight() - VereinePdf::BOTTOM - 5) {
					$pdf->AddPage();
				}
				VereinePdf::label($pdf, $outputlangs, $consent['label'], $outputlangs->transnoentities('VereineApplicationConsentVersion', (int) $consent['version']));
				if ($body !== '') {
					$text($body, '', 9);
				}
				$yesNo('einwilligung_'.$consent['code'], $outputlangs->transnoentitiesnoconv('VereineApplicationConsentYesNo'));
				$pdf->Ln(2);
			}
		}

		if ($settings['privacy'] !== '' || $settings['privacy_url'] !== '') {
			VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineApplicationPrivacy'));
			if ($settings['privacy'] !== '') {
				$text($this->filled($settings['privacy'], $member), '', 9);
			}
			if ($settings['privacy_url'] !== '') {
				$text($settings['privacy_url'], '', 9);
			}
		}

		// The SEPA mandate on paper, when the association collects by direct debit (#206).
		if (VereineSepaStore::enabled()) {
			VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineApplicationSepaTitle'));
			$creditor = getDolGlobalString('PRELEVEMENT_ICS');
			$text($outputlangs->transnoentities('VereineApplicationSepaText', $organization['name'],
				$creditor !== '' ? ' '.$outputlangs->transnoentities('VereineApplicationSepaCreditor', $creditor) : ''), '', 9);
			foreach (array('kontoinhaber' => 'VereineApplicationSepaHolder', 'iban' => 'VereineApplicationSepaIban') as $name => $label) {
				$field($name, $outputlangs->transnoentitiesnoconv($label), '');
			}
			VereinePdf::signatures($pdf, $outputlangs, array('VereineApplicationSepaSign'), 'sepa', $fillable && !$submitted);
		}

		$this->signatures($pdf, $member, $outputlangs, $fillable && !$submitted, $submitted);

		VereinePdf::finish($pdf, $outputlangs, $outputlangs->transnoentities('VereineApplicationTitle'));
		$pdf->Output($file, 'F');
		if (!is_file($file)) {
			$this->error = 'cannot write '.$file;
			return '';
		}
		dolChmod($file);
		return $file;
	}

	/**
	 * A text the association wrote itself, with the placeholders of the module filled in (#206): so the
	 * name, the address or the e-mail of the association never has to be typed twice.
	 *
	 * @param string        $text   Text as entered
	 * @param Adherent|null $member Member the form is for, null for a blank form
	 * @return string
	 */
	private function filled($text, $member)
	{
		if (strpos((string) $text, '__') === false) {
			return (string) $text;
		}
		$placeholders = new VereinePlaceholders($this->db);
		$values = $placeholders->associationValues();
		if ($member !== null && !empty($member->id)) {
			$values = array_merge($values, $placeholders->memberValues((int) $member->id));
		}
		return make_substitutions((string) $text, $values);
	}

	/**
	 * The discounts of a member type in plain words, from the fee model (#206).
	 *
	 * @param int       $typeId      Member type
	 * @param Translate $outputlangs Language
	 * @return string[]
	 */
	private function discountLines($typeId, $outputlangs)
	{
		$lines = array();
		$store = new VereineFeeDiscountStore($this->db);
		foreach ($store->fetchAll(true) as $rule) {
			if ((int) $rule['type_id'] !== (int) $typeId && (int) $rule['type_id'] !== 0) {
				continue;
			}
			$value = $rule['mode'] === 'free' ? $outputlangs->transnoentitiesnoconv('VereineApplicationDiscountFree')
				: ($rule['mode'] === 'percent' ? price($rule['value'], 0, $outputlangs, 1, -1, 0).' %' : price($rule['value'], 0, $outputlangs, 1, -1, 2).' €');
			if ($rule['kind'] === VereineFeeDiscounts::KIND_AGE) {
				$range = $rule['age_to'] !== '' && $rule['age_from'] !== ''
					? $outputlangs->transnoentities('VereineApplicationDiscountAgeRange', $rule['age_from'], $rule['age_to'])
					: ($rule['age_to'] !== '' ? $outputlangs->transnoentities('VereineApplicationDiscountAgeUnder', $rule['age_to'])
						: $outputlangs->transnoentities('VereineApplicationDiscountAgeFrom', $rule['age_from']));
				$lines[] = $outputlangs->transnoentities('VereineApplicationDiscountLine', $rule['label'], $range, $value);
			} else {
				$lines[] = $outputlangs->transnoentities('VereineApplicationDiscountLine', $rule['label'],
					$outputlangs->transnoentitiesnoconv('VereineApplicationDiscountProof'), $value);
			}
		}
		$family = (new VereineFeeFamilyStore($this->db))->setting();
		if (isset($family['mode']) && $family['mode'] === 'percent' && (float) $family['value'] > 0) {
			$lines[] = $outputlangs->transnoentities('VereineApplicationFamilyPercent', price((float) $family['value'], 0, $outputlangs, 1, -1, 0).' %');
		} elseif (isset($family['mode']) && $family['mode'] === 'cap' && (float) $family['value'] > 0) {
			$lines[] = $outputlangs->transnoentities('VereineApplicationFamilyCap', price((float) $family['value'], 0, $outputlangs, 1, -1, 2).' €');
		}
		return $lines;
	}

	/**
	 * What the statutes say and the applicant should know: the purpose, the duties of a member and the
	 * version of the statutes that is in force (#204). Empty when the association has no statutes yet.
	 *
	 * @param Translate $outputlangs Language
	 * @return string[] Lines for the form
	 */
	private function statuteFacts($outputlangs)
	{
		global $mysoc;

		$organization = VereineOrganization::load($mysoc);
		$lines = array();
		if (trim((string) $organization['purpose']) !== '') {
			$lines[] = $outputlangs->transnoentities('VereineApplicationPurpose', trim((string) $organization['purpose']));
		}
		$store = new VereineStatutes($this->db);
		$rules = $store->rules();
		$text = VereineStatuteText::normalize(json_decode((string) getDolGlobalString(VereineStatutes::CONST_TEXT), true));
		$duties = '';
		foreach (VereineStatuteText::sections($rules, $text, $store->context($rules)) as $section) {
			if (strpos((string) $section['title'], 'Pflichten') === false) {
				continue;
			}
			foreach ($section['paragraphs'] as $paragraph) {
				if (strpos($paragraph, 'verpflichtet') !== false) {
					$duties = $paragraph;
				}
			}
		}
		if ($duties !== '') {
			$lines[] = $duties;
		}
		$version = $store->current(dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'));
		$lines[] = $version !== null
			? $outputlangs->transnoentities('VereineApplicationStatutesVersion', (int) $version['version'], vereineFormatDay($version['valid_from']))
			: $outputlangs->transnoentitiesnoconv('VereineApplicationStatutesCurrent');
		return $lines;
	}

	/**
	 * What a member has in a field of the form; empty for a blank form.
	 *
	 * @param Adherent|null $member      Member, null for a blank form
	 * @param string        $name        One of FIELDS
	 * @param Translate     $outputlangs Language
	 * @return string
	 */
	private function personValue($member, $name, $outputlangs)
	{
		if ($member === null) {
			return '';
		}
		switch ($name) {
			case 'lastname':
				return (string) $member->lastname;
			case 'firstname':
				return (string) $member->firstname;
			case 'birth':
				return !empty($member->birth) ? vereineFormatDay(dol_print_date($member->birth, '%Y-%m-%d', 'tzserver')) : '';
			case 'gender':
				return !empty($member->gender) ? $outputlangs->transnoentitiesnoconv(ucfirst((string) $member->gender)) : '';
			case 'address':
				return (string) $member->address;
			case 'zip':
				return (string) $member->zip;
			case 'town':
				return (string) $member->town;
			case 'country':
				return (string) (!empty($member->country) ? $member->country : $member->country_code);
			case 'phone':
				return (string) (!empty($member->phone) ? $member->phone : $member->phone_mobile);
			default:
				return (string) $member->email;
		}
	}

	/**
	 * The fee of a member type in plain words: amount per period, admission fee, first year.
	 *
	 * @param array<string,mixed>|null $type        Member type with its fee model
	 * @param Translate                $outputlangs Language
	 * @return string[]
	 */
	private function feeLines($type, $outputlangs)
	{
		if ($type === null || empty($type['subscription'])) {
			return array($outputlangs->transnoentitiesnoconv('VereineApplicationNoFee'));
		}
		$model = $type['model'];
		// "pro Jahr" for a single period, "alle 2 Jahre" for more.
		$value = (int) $model['duration_value'];
		$period = $value === 1 ? $outputlangs->transnoentitiesnoconv('VereineApplicationPeriodOne_'.$model['duration_unit'])
			: $outputlangs->transnoentities('VereineApplicationPeriod_'.$model['duration_unit'], $value);
		// A member type without a fixed amount: the board fills it in.
		$lines = array($model['amount'] === null ? $outputlangs->transnoentities('VereineApplicationFeeOpen', $period)
			: $outputlangs->transnoentities('VereineApplicationFee', price($model['amount'], 0, $outputlangs, 1, -1, 2).' €', $period));
		if (!empty($model['admission_fee'])) {
			$lines[] = $outputlangs->transnoentities('VereineApplicationAdmission', price($model['admission_fee'], 0, $outputlangs, 1, -1, 2).' €');
		}
		if ($model['proration'] !== VereineFeeRules::PRORATION_NONE) {
			$lines = array_merge($lines, $this->prorationLines($model, $outputlangs));
		}
		return $lines;
	}

	/**
	 * What joining costs in the year of joining, part by part, and from the next year on (#216).
	 *
	 * A sentence like "prorated by half-year" reads as "billed every half-year" to many; the amounts
	 * leave no room for that. With a monthly proration twelve lines would be too many, so the first and
	 * the last month stand for them.
	 *
	 * @param array<string,mixed> $model       Fee model
	 * @param Translate           $outputlangs Language
	 * @return string[]
	 */
	private function prorationLines(array $model, $outputlangs)
	{
		$year = (int) dol_print_date(dol_now(), '%Y', 'tzserver');
		$steps = VereineApplicationFormRules::prorationSteps($model, $year);
		if (!$steps) {
			return array($outputlangs->transnoentitiesnoconv('VereineApplicationProration_'.$model['proration']));
		}
		$day = function ($date) use ($outputlangs) {
			// 1. Juli, not 01. Juli.
			return ltrim(dol_print_date(dol_mktime(12, 0, 0, (int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)),
				'%d. %B', 'tzserver', $outputlangs), '0');
		};
		$money = function ($amount) use ($outputlangs) {
			return price($amount, 0, $outputlangs, 1, -1, 2).' €';
		};
		$lines = array($outputlangs->transnoentitiesnoconv('VereineApplicationProrationHead'));
		$shown = count($steps) > 4 ? array($steps[0], $steps[count($steps) - 1]) : $steps;
		foreach ($shown as $index => $step) {
			if (count($steps) > 4 && $index === 1) {
				$lines[] = $outputlangs->transnoentities('VereineApplicationProrationEach', $money($steps[0]['amount'] - $steps[1]['amount']));
			}
			$lines[] = $outputlangs->transnoentities('VereineApplicationProrationStep', $day($step['from']), $day($step['to']), $money($step['amount']));
		}
		$lines[] = $outputlangs->transnoentities('VereineApplicationProrationAfter', $money($model['amount']));
		return $lines;
	}

	/**
	 * Place, day and the lines to sign: the applicant, the guardians of a minor, the board.
	 *
	 * @param TCPDF         $pdf         PDF
	 * @param Adherent|null $member      Member, null for a blank form
	 * @param Translate     $outputlangs Language
	 * @param bool                $fillable    Whether the form carries fields to fill in
	 * @param array<string,mixed> $submitted   An application that came in over the website: at, signature
	 * @return void
	 */
	private function signatures($pdf, $member, $outputlangs, $fillable, array $submitted = array())
	{
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		if ($submitted) {
			// Came in over the website: the note takes the place of the handwritten signature (#111).
			$pdf->Ln(4);
			$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 9);
			$pdf->MultiCell(0, 5, $outputlangs->transnoentities('VereineApplicationSubmitted',
				dol_print_date(isset($submitted['at']) ? $submitted['at'] : dol_now(), 'dayhour')), 0, 'L');
			if (!empty($submitted['signature']) && is_file($submitted['signature'])) {
				$pdf->Image($submitted['signature'], VereinePdf::SIDE, $pdf->GetY() + 2, 50, 0, 'PNG');
				$pdf->Ln(20);
				$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 8);
				$pdf->MultiCell(85, 4, $outputlangs->transnoentitiesnoconv('VereineApplicationSignDrawn'), 0, 'L');
			}
		}
		$birth = $member !== null && !empty($member->birth) ? dol_print_date($member->birth, '%Y-%m-%d', 'tzserver') : '';
		// A blank form carries the line for guardians as well, it does not know who fills it in.
		$lines = $submitted ? array() : array('VereineApplicationSignApplicant');
		if (!$submitted && ($member === null || self::isMinor($birth, $today))) {
			$lines[] = 'VereineApplicationSignGuardian';
		}
		$lines[] = 'VereineApplicationSignBoard';
		VereinePdf::signatures($pdf, $outputlangs, $lines, 'antrag', $fillable);
	}

	/**
	 * How to leave, as a sentence for the one who applies (#202).
	 *
	 * @param array<string,mixed> $rule        Rule of VereineExits::rule()
	 * @param Translate           $outputlangs Language
	 * @return string
	 */
	public static function exitSentence(array $rule, $outputlangs)
	{
		$months = array(1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
			9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December');
		$span = (int) $rule['months'] === 1 ? $outputlangs->transnoentitiesnoconv('VereineMonthsOne')
			: $outputlangs->transnoentities('VereineMonthsMany', (int) $rule['months']);
		$start = isset($months[(int) $rule['start_month']]) ? $outputlangs->transnoentitiesnoconv($months[(int) $rule['start_month']]) : '';
		return $outputlangs->transnoentities('VereineApplicationExit_'.$rule['at'], $span, $start);
	}

	/**
	 * The association's bank account for the footer: the one chosen in the setup, else the first open one.
	 *
	 * @param DoliDB $db Database handler
	 * @return string IBAN and BIC, empty when there is none
	 */
	public static function bank($db)
	{
		$chosen = getDolGlobalInt(self::ACCOUNT);
		$sql = "SELECT label, iban_prefix, bic FROM ".MAIN_DB_PREFIX."bank_account WHERE entity IN (".getEntity('bank_account').") AND clos = 0";
		$sql .= $chosen > 0 ? " AND rowid = ".$chosen : "";
		$sql .= " ORDER BY rowid";
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj || (string) $obj->iban_prefix === '') {
			return '';
		}
		return trim('IBAN '.$obj->iban_prefix.((string) $obj->bic !== '' ? ', BIC '.$obj->bic : ''));
	}
}
