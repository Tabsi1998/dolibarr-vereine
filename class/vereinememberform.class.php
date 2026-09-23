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
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		$extrafields = new ExtraFields($db);
		$extrafields->fetch_name_optionals_label('adherent');
		$labels = array();
		$attributes = isset($extrafields->attributes['adherent']['label']) ? $extrafields->attributes['adherent']['label'] : array();
		foreach ($attributes as $code => $label) {
			// The module's own fields are kept by the module and have their own place on the form.
			if (strpos((string) $code, 'vereine_') !== 0) {
				$labels[(string) $code] = (string) $label;
			}
		}
		return $labels;
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
		$extraLabels = self::memberExtraFields($this->db);
		$settings = self::settings(array_keys($extraLabels));
		$organization = VereineOrganization::load($mysoc);
		$fillable = VereinePdf::fillable('application');
		$pdf = VereinePdf::start($outputlangs);
		$font = pdf_getPDFFont($outputlangs);
		$text = function ($value, $style = '', $size = 10) use ($pdf, $font) {
			$pdf->SetFont($font, $style, $size);
			$pdf->MultiCell(0, 5, $value, 0, 'L');
		};
		// A field of the form: what it is called, and the value or a line to write on.
		$field = function ($name, $label, $value, $required = false) use ($pdf, $font, $fillable) {
			$pdf->SetFont($font, '', 10);
			$pdf->MultiCell(55, 6, $label.($required ? ' *' : '').':', 0, 'L', false, 0);
			if ($value !== '') {
				$pdf->SetFont($font, 'B', 10);
				$pdf->MultiCell(115, 6, $value, 0, 'L', false, 1);
				return;
			}
			VereinePdf::input($pdf, 'antrag_'.$name, 115, $fillable);
		};
		$yesNo = function ($name, $label) use ($pdf, $outputlangs, $fillable) {
			VereinePdf::choice($pdf, $outputlangs, 'antrag_'.$name, $label, $fillable);
		};

		VereinePdf::title($pdf, $outputlangs, $outputlangs->transnoentities('VereineApplicationTitle'),
			$organization['name'].($organization['register']['number'] !== '' ? ' – ZVR '.$organization['register']['number'] : ''));
		if ($settings['intro'] !== '') {
			$text($this->filled($settings['intro'], $member));
			$pdf->Ln(2);
		}

		VereinePdf::heading($pdf, $outputlangs, $outputlangs->transnoentities('VereineApplicationPerson'));
		foreach (self::FIELDS as $name) {
			$field($name, $outputlangs->transnoentitiesnoconv('VereineApplicationField_'.$name), $this->personValue($member, $name, $outputlangs),
				in_array($name, $settings['required'], true));
		}
		// The association's own fields, such as a gamer tag, with the value the member already has.
		foreach ($settings['extra'] as $code => $mustHave) {
			$value = $member !== null && isset($member->array_options['options_'.$code]) ? (string) $member->array_options['options_'.$code] : '';
			$field('extra_'.$code, $outputlangs->transnoentitiesnoconv($extraLabels[$code]), $value, $mustHave);
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
		$text($outputlangs->transnoentities('VereineApplicationNotice', vereineExitRuleText((new VereineExits($this->db))->rule())));
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
		$yesNo('statuten', $outputlangs->transnoentitiesnoconv('VereineApplicationStatutes'));

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
				$text($consent['label'].' (v'.((int) $consent['version']).')', 'B');
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
				$creditor !== '' ? $outputlangs->transnoentities('VereineApplicationSepaCreditor', $creditor) : ''), '', 9);
			foreach (array('kontoinhaber' => 'VereineApplicationSepaHolder', 'iban' => 'VereineApplicationSepaIban') as $name => $label) {
				$field($name, $outputlangs->transnoentitiesnoconv($label), '');
			}
			VereinePdf::signatures($pdf, $outputlangs, array('VereineApplicationSepaSign'), 'sepa', $fillable && !$submitted);
		}

		$this->signatures($pdf, $member, $outputlangs, $fillable && !$submitted, $submitted);
		$footer = $this->footer($organization);
		$pdf->Ln(4);
		$pdf->SetFont($font, 'I', 8);
		$pdf->MultiCell(0, 4, $footer, 0, 'L');

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
	 * The footer: address, contact, bank account and register number of the association.
	 *
	 * @param array<string,mixed> $organization Association
	 * @return string
	 */
	private function footer(array $organization)
	{
		$parts = array($organization['name']);
		$address = trim($organization['address']['street'].', '.trim($organization['address']['zip'].' '.$organization['address']['town']), ' ,');
		foreach (array($address, $organization['email'], $organization['phone'], $organization['url']) as $value) {
			if ((string) $value !== '') {
				$parts[] = $value;
			}
		}
		$bank = self::bank($this->db);
		if ($bank !== '') {
			$parts[] = $bank;
		}
		if ($organization['register']['number'] !== '') {
			$parts[] = 'ZVR '.$organization['register']['number'];
		}
		return implode(' · ', $parts);
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
