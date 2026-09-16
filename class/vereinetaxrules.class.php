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
 * \file    class/vereinetaxrules.class.php
 * \ingroup vereine
 * \brief   Spheres, VAT treatments and tax profiles of Austrian associations, plain PHP.
 *
 * The module calculates and warns; which sphere an activity belongs to remains the
 * association's decision. Every rule names its legal basis; docs/LEGAL-SOURCES.md
 * lists the sources and when they were read.
 */

/**
 * Rules for tax profiles. No Dolibarr dependency, so tests/run.php checks them directly.
 */
class VereineTaxRules
{
	/** Idealistic sphere: membership fees, donations, subsidies without consideration. */
	const SPHERE_IDEAL = 'ideal';
	/** Asset management: interest, letting. */
	const SPHERE_ASSETS = 'assets';
	/** Indispensable auxiliary business, § 45 (2) BAO. */
	const SPHERE_ESSENTIAL = 'essential';
	/** Dispensable auxiliary business, § 45 (1) BAO. */
	const SPHERE_AUXILIARY = 'auxiliary';
	/** Small association festival, § 45 (1a) BAO: at most 72 hours a year. */
	const SPHERE_FESTIVAL = 'festival';
	/** Business harmful to the tax privileges, § 45 (3) and § 44 BAO. */
	const SPHERE_HARMFUL = 'harmful';

	/** Not subject to VAT: no consideration. */
	const TREATMENT_NONBUSINESS = 'nonbusiness';
	/** Not subject to VAT: Liebhaberei. */
	const TREATMENT_HOBBY = 'hobby';
	/** Exempt: small business, § 6 (1) no. 27 UStG. */
	const TREATMENT_SMALL_BUSINESS = 'small_business';
	/** Exempt: sports associations, § 6 (1) no. 14 UStG. */
	const TREATMENT_SPORT = 'sport';
	/** 10 % for non-profit bodies, § 10 (2) no. 4 UStG. */
	const TREATMENT_REDUCED_10 = 'reduced10';
	/** 13 %, § 10 (3) UStG. */
	const TREATMENT_REDUCED_13 = 'reduced13';
	/** 20 %, § 10 (1) UStG. */
	const TREATMENT_STANDARD_20 = 'standard20';

	/** Longest invoice note, as stored. */
	const NOTE_MAX = 1000;

	/**
	 * Spheres of an Austrian association with their legal basis.
	 *
	 * @return array<string,array{basis:string,source:string}>
	 */
	public static function spheres()
	{
		return array(
			self::SPHERE_IDEAL => array('basis' => '§§ 34 bis 47 BAO', 'source' => 'https://www.usp.gv.at/themen/steuern-finanzen/umsatzsteuer-ueberblick/weitere-informationen-zur-umsatzsteuer/weitere-steuertatbestaende-und-befreiungen/umsatzsteuer-fuer-vereine.html'),
			self::SPHERE_ASSETS => array('basis' => '§ 47 BAO', 'source' => 'https://www.jusline.at/gesetz/bao/paragraf/47'),
			self::SPHERE_ESSENTIAL => array('basis' => '§ 45 Abs. 2 BAO', 'source' => 'https://www.jusline.at/gesetz/bao/paragraf/45'),
			self::SPHERE_AUXILIARY => array('basis' => '§ 45 Abs. 1 BAO', 'source' => 'https://www.jusline.at/gesetz/bao/paragraf/45'),
			self::SPHERE_FESTIVAL => array('basis' => '§ 45 Abs. 1a BAO', 'source' => 'https://www.jusline.at/gesetz/bao/paragraf/45'),
			self::SPHERE_HARMFUL => array('basis' => '§ 45 Abs. 3, § 44 BAO', 'source' => 'https://www.jusline.at/gesetz/bao/paragraf/45'),
		);
	}

	/**
	 * VAT treatments with rate, whether the invoice must name the exemption, and the legal basis.
	 *
	 * An exemption must be named on the invoice (§ 11 (1) no. 3 lit. e UStG); income
	 * that is not subject to VAT needs no VAT invoice at all.
	 *
	 * @return array<string,array{rate:float,note_required:bool,basis:string,source:string}>
	 */
	public static function treatments()
	{
		$ust = 'https://www.jusline.at/gesetz/ustg/paragraf/';
		return array(
			self::TREATMENT_NONBUSINESS => array('rate' => 0.0, 'note_required' => false, 'basis' => 'kein Leistungsaustausch', 'source' => 'https://www.usp.gv.at/themen/steuern-finanzen/umsatzsteuer-ueberblick/weitere-informationen-zur-umsatzsteuer/weitere-steuertatbestaende-und-befreiungen/umsatzsteuer-fuer-vereine.html'),
			self::TREATMENT_HOBBY => array('rate' => 0.0, 'note_required' => false, 'basis' => 'Liebhaberei', 'source' => 'https://www.usp.gv.at/themen/steuern-finanzen/umsatzsteuer-ueberblick/weitere-informationen-zur-umsatzsteuer/weitere-steuertatbestaende-und-befreiungen/umsatzsteuer-fuer-vereine.html'),
			self::TREATMENT_SMALL_BUSINESS => array('rate' => 0.0, 'note_required' => true, 'basis' => '§ 6 Abs. 1 Z 27 UStG', 'source' => $ust.'6'),
			self::TREATMENT_SPORT => array('rate' => 0.0, 'note_required' => true, 'basis' => '§ 6 Abs. 1 Z 14 UStG', 'source' => $ust.'6'),
			self::TREATMENT_REDUCED_10 => array('rate' => 10.0, 'note_required' => false, 'basis' => '§ 10 Abs. 2 Z 4 UStG', 'source' => $ust.'10'),
			self::TREATMENT_REDUCED_13 => array('rate' => 13.0, 'note_required' => false, 'basis' => '§ 10 Abs. 3 UStG', 'source' => $ust.'10'),
			self::TREATMENT_STANDARD_20 => array('rate' => 20.0, 'note_required' => false, 'basis' => '§ 10 Abs. 1 UStG', 'source' => $ust.'10'),
		);
	}

	/**
	 * Whether a treatment may be used in a sphere.
	 *
	 * The idealistic sphere has no supplies, so only "not subject to VAT" fits it, and
	 * that treatment fits nothing but the idealistic sphere and asset management.
	 * Liebhaberei is presumed for auxiliary businesses only. § 6 (1) no. 14 and
	 * § 10 (2) no. 4 UStG exclude businesses under § 45 (3) BAO.
	 *
	 * @param string $sphere    Sphere code
	 * @param string $treatment Treatment code
	 * @return string '' when allowed, otherwise the language key of the reason
	 */
	public static function combinationError($sphere, $treatment)
	{
		if ($sphere === self::SPHERE_IDEAL && $treatment !== self::TREATMENT_NONBUSINESS) {
			return 'VereineTaxErrorIdealNoSupply';
		}
		if ($treatment === self::TREATMENT_NONBUSINESS && !in_array($sphere, array(self::SPHERE_IDEAL, self::SPHERE_ASSETS), true)) {
			return 'VereineTaxErrorNonbusinessSphere';
		}
		if ($treatment === self::TREATMENT_HOBBY && !in_array($sphere, array(self::SPHERE_ESSENTIAL, self::SPHERE_AUXILIARY, self::SPHERE_FESTIVAL), true)) {
			return 'VereineTaxErrorHobbySphere';
		}
		if ($sphere === self::SPHERE_HARMFUL && $treatment === self::TREATMENT_REDUCED_10) {
			return 'VereineTaxErrorReducedHarmful';
		}
		if ($sphere === self::SPHERE_HARMFUL && $treatment === self::TREATMENT_SPORT) {
			return 'VereineTaxErrorSportHarmful';
		}
		return '';
	}

	/**
	 * Check a tax profile before it is stored.
	 *
	 * @param array{code?:string,label?:string,sphere?:string,treatment?:string,rate?:float|int|string,note?:string} $profile Profile
	 * @return string[] Language keys of every problem, empty when the profile is valid
	 */
	public static function validate(array $profile)
	{
		$errors = array();
		$code = isset($profile['code']) ? (string) $profile['code'] : '';
		$label = isset($profile['label']) ? trim((string) $profile['label']) : '';
		$sphere = isset($profile['sphere']) ? (string) $profile['sphere'] : '';
		$treatment = isset($profile['treatment']) ? (string) $profile['treatment'] : '';
		$note = isset($profile['note']) ? trim((string) $profile['note']) : '';
		$spheres = self::spheres();
		$treatments = self::treatments();

		if (!preg_match('/^[A-Z][A-Z0-9_]{1,31}$/', $code)) {
			$errors[] = 'VereineTaxErrorCode';
		}
		if ($label === '' || self::length($label) > 255) {
			$errors[] = 'VereineTaxErrorLabel';
		}
		if (!isset($spheres[$sphere])) {
			$errors[] = 'VereineTaxErrorSphere';
		}
		if (!isset($treatments[$treatment])) {
			$errors[] = 'VereineTaxErrorTreatment';
		}
		if (isset($spheres[$sphere], $treatments[$treatment])) {
			$combination = self::combinationError($sphere, $treatment);
			if ($combination !== '') {
				$errors[] = $combination;
			}
			$rate = isset($profile['rate']) && is_numeric($profile['rate']) ? (float) $profile['rate'] : null;
			if ($rate === null || abs($rate - $treatments[$treatment]['rate']) > 0.0001) {
				$errors[] = 'VereineTaxErrorRate';
			}
			if ($treatments[$treatment]['note_required'] && $note === '') {
				$errors[] = 'VereineTaxErrorNoteRequired';
			}
		}
		if (self::length($note) > self::NOTE_MAX) {
			$errors[] = 'VereineTaxErrorNoteLength';
		}
		return $errors;
	}

	/**
	 * Rate of a treatment, for forms that fill it in.
	 *
	 * @param string $treatment Treatment code
	 * @return float|null
	 */
	public static function rateOf($treatment)
	{
		$treatments = self::treatments();
		return isset($treatments[$treatment]) ? $treatments[$treatment]['rate'] : null;
	}

	/**
	 * A VAT rate as people write it: 10, 13, 20, 0 - decimals only when there are any.
	 *
	 * @param float|int|string $rate Rate in percent
	 * @return string
	 */
	public static function formatRate($rate)
	{
		return rtrim(rtrim(number_format((float) $rate, 3, ',', ''), '0'), ',');
	}

	/**
	 * Whether a VAT rate on a product or invoice line differs from its profile's rate.
	 *
	 * @param float|int|string $rate        Rate of the line in percent
	 * @param float|int|string $profileRate Rate of the profile in percent
	 * @return bool
	 */
	public static function rateDeviates($rate, $profileRate)
	{
		return abs((float) $rate - (float) $profileRate) > 0.0001;
	}

	/**
	 * Invoice notes grouped by text, in the order the lines come, each with its line positions.
	 *
	 * Lines without note are left out; the same note on several lines is printed once.
	 *
	 * @param array<int,array{position:int,note:string}> $lines Invoice lines in their order
	 * @return array<int,array{positions:int[],note:string}>
	 */
	public static function groupNotes(array $lines)
	{
		$groups = array();
		foreach ($lines as $line) {
			$note = trim((string) $line['note']);
			if ($note === '') {
				continue;
			}
			if (!isset($groups[$note])) {
				$groups[$note] = array('positions' => array(), 'note' => $note);
			}
			$groups[$note]['positions'][] = (int) $line['position'];
		}
		return array_values($groups);
	}

	/**
	 * Profiles the module suggests on activation. Labels and notes are language keys.
	 *
	 * Only suggestions: the sport exemption applies only to associations whose statutory
	 * purpose is physical sport, and 10 % requires the Liebhaberei presumption to be
	 * rebutted, so both start inactive.
	 *
	 * @return array<int,array{code:string,label:string,sphere:string,treatment:string,note:string,active:int}>
	 */
	public static function standardProfiles()
	{
		return array(
			array('code' => 'MITGLIEDSBEITRAG', 'label' => 'VereineTaxProfile_MITGLIEDSBEITRAG', 'sphere' => self::SPHERE_IDEAL, 'treatment' => self::TREATMENT_NONBUSINESS, 'note' => 'VereineTaxNote_MITGLIEDSBEITRAG', 'active' => 1),
			array('code' => 'SPENDE', 'label' => 'VereineTaxProfile_SPENDE', 'sphere' => self::SPHERE_IDEAL, 'treatment' => self::TREATMENT_NONBUSINESS, 'note' => 'VereineTaxNote_SPENDE', 'active' => 1),
			array('code' => 'SUBVENTION', 'label' => 'VereineTaxProfile_SUBVENTION', 'sphere' => self::SPHERE_IDEAL, 'treatment' => self::TREATMENT_NONBUSINESS, 'note' => 'VereineTaxNote_SUBVENTION', 'active' => 1),
			array('code' => 'HILFSBETRIEB', 'label' => 'VereineTaxProfile_HILFSBETRIEB', 'sphere' => self::SPHERE_ESSENTIAL, 'treatment' => self::TREATMENT_HOBBY, 'note' => 'VereineTaxNote_HOBBY', 'active' => 1),
			array('code' => 'VEREINSFEST', 'label' => 'VereineTaxProfile_VEREINSFEST', 'sphere' => self::SPHERE_FESTIVAL, 'treatment' => self::TREATMENT_HOBBY, 'note' => 'VereineTaxNote_HOBBY', 'active' => 1),
			array('code' => 'KLEINUNTERNEHMER', 'label' => 'VereineTaxProfile_KLEINUNTERNEHMER', 'sphere' => self::SPHERE_HARMFUL, 'treatment' => self::TREATMENT_SMALL_BUSINESS, 'note' => 'VereineTaxNote_KLEINUNTERNEHMER', 'active' => 1),
			array('code' => 'BETRIEB_20', 'label' => 'VereineTaxProfile_BETRIEB_20', 'sphere' => self::SPHERE_HARMFUL, 'treatment' => self::TREATMENT_STANDARD_20, 'note' => '', 'active' => 1),
			array('code' => 'SPORT', 'label' => 'VereineTaxProfile_SPORT', 'sphere' => self::SPHERE_ESSENTIAL, 'treatment' => self::TREATMENT_SPORT, 'note' => 'VereineTaxNote_SPORT', 'active' => 0),
			array('code' => 'HILFSBETRIEB_10', 'label' => 'VereineTaxProfile_HILFSBETRIEB_10', 'sphere' => self::SPHERE_AUXILIARY, 'treatment' => self::TREATMENT_REDUCED_10, 'note' => '', 'active' => 0),
		);
	}

	/**
	 * Length in characters; bytes when mbstring is missing, as VereineProfile counts.
	 *
	 * @param string $text Text
	 * @return int
	 */
	private static function length($text)
	{
		return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
	}
}
