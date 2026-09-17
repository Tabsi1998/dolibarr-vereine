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
 * \file    tests/run.php
 * \ingroup vereine
 * \brief   Tests that need no Dolibarr: profile rules, association data, checks, language files.
 *
 * Run with: php tests/run.php
 * Prints "Unit tests: OK (n assertions)" and exits 0, or lists every failure and exits 1.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit(1);
}

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__);
require_once $root.'/class/vereineprofile.class.php';
require_once $root.'/class/vereineorganization.class.php';
require_once $root.'/class/vereinepartnerrules.class.php';
require_once $root.'/class/vereinetaxrules.class.php';
require_once $root.'/class/vereinethresholds.class.php';
require_once $root.'/class/vereinecashregister.class.php';
require_once $root.'/class/vereinemembersummary.class.php';
require_once $root.'/class/vereinewebsiteevents.class.php';
require_once $root.'/class/vereinefeerules.class.php';
require_once $root.'/class/vereinefeediscounts.class.php';
require_once $root.'/class/vereinefeefamilies.class.php';
require_once $root.'/class/vereineexitrules.class.php';
require_once $root.'/class/vereinesepa.class.php';
require_once $root.'/class/vereineconsentrules.class.php';
require_once $root.'/class/vereinefunctionrules.class.php';
require_once $root.'/class/vereinemailingrules.class.php';
require_once $root.'/class/vereinestatuterules.class.php';

$failures = array();
$assertions = 0;

/**
 * Record one expectation.
 *
 * @param bool   $condition Whether it holds
 * @param string $message   What was expected
 * @return void
 */
function expect($condition, $message)
{
	global $failures, $assertions;

	$assertions++;
	if (!$condition) {
		$failures[] = $message;
	}
}

/**
 * Record that two values are identical.
 *
 * @param mixed  $expected Expected value
 * @param mixed  $actual   Actual value
 * @param string $message  What is compared
 * @return void
 */
function same($expected, $actual, $message)
{
	expect($expected === $actual, $message.': expected '.var_export($expected, true).', got '.var_export($actual, true));
}

/**
 * Keys of a Dolibarr language file, as Translate::load() reads them.
 *
 * @param string $path Language file
 * @return array<string,string>
 */
function langEntries($path)
{
	$entries = array();
	foreach (file($path, FILE_IGNORE_NEW_LINES) as $number => $line) {
		if (trim($line) === '' || $line[0] === '#') {
			continue;
		}
		if (!preg_match('/^([A-Za-z0-9_]+)=(.*)$/', $line, $matches)) {
			$entries['!line '.($number + 1)] = $line;
			continue;
		}
		if (isset($entries[$matches[1]])) {
			$entries['!duplicate '.$matches[1]] = $line;
		}
		$entries[$matches[1]] = $matches[2];
	}
	return $entries;
}

// ------------------------------------------------------------------ profiles

same(array('AT', 'DE'), VereineProfile::codes(), 'profile codes');
expect(VereineProfile::isSupported('AT') && VereineProfile::isSupported('DE'), 'AT and DE are supported');
expect(!VereineProfile::isSupported('CH') && !VereineProfile::isSupported('') && !VereineProfile::isSupported('at'), 'CH, empty and lower case are not supported');
expect(VereineProfile::isComplete('AT') && !VereineProfile::isComplete('DE'), 'AT is complete, DE is a preview until 1.1');
same('DE', VereineProfile::suggestFromCountry('de'), 'a German company suggests DE');
same('AT', VereineProfile::suggestFromCountry('AT'), 'an Austrian company suggests AT');
same('AT', VereineProfile::suggestFromCountry(''), 'an unknown country suggests AT');
same('AT', VereineProfile::suggestFromCountry('CH'), 'a Swiss company suggests AT until CH exists');
same('ZVR', VereineProfile::registerKind('AT'), 'Austrian register kind');
same('VR', VereineProfile::registerKind('DE'), 'German register kind');

same('123456789', VereineProfile::normalizeRegisterNumber('AT', ' 123 456.789 '), 'ZVR spaces and dots removed');
same('VR 12345 B', VereineProfile::normalizeRegisterNumber('DE', 'vr12345  B'), 'VR prefix and spacing normalised');
same('VR 200', VereineProfile::normalizeRegisterNumber('DE', ' VR   200 '), 'VR trimmed');
same('', VereineProfile::normalizeRegisterNumber('AT', '   '), 'blank register number becomes empty');

same('', VereineProfile::validateRegisterNumber('AT', ''), 'an empty ZVR number is allowed');
same('', VereineProfile::validateRegisterNumber('AT', '1234567890'), 'ten digits are a ZVR number');
same('VereineErrorZvrFormat', VereineProfile::validateRegisterNumber('AT', '12345678901'), 'eleven digits are not');
same('VereineErrorZvrFormat', VereineProfile::validateRegisterNumber('AT', 'ZVR 123'), 'letters are not');
same('', VereineProfile::validateRegisterNumber('DE', 'VR 12345'), 'VR 12345 is valid');
same('', VereineProfile::validateRegisterNumber('DE', 'VR 12345 B'), 'VR 12345 B is valid');
same('VereineErrorVrFormat', VereineProfile::validateRegisterNumber('DE', '12345'), 'a VR number needs its prefix');
same('VereineErrorVrFormat', VereineProfile::validateRegisterNumber('DE', 'VR 12345678'), 'eight digits are too many');

same('', VereineProfile::validatePurpose(str_repeat('ä', VereineProfile::PURPOSE_MAX_LENGTH)), 'purpose at the limit counts characters, not bytes');
same('VereineErrorPurposeTooLong', VereineProfile::validatePurpose(str_repeat('a', VereineProfile::PURPOSE_MAX_LENGTH + 1)), 'purpose over the limit');

$today = gmmktime(12, 0, 0, 9, 16, 2026);
same('', VereineProfile::validateFoundingDate(0, 0, 0, $today), 'no founding date is allowed');
same('', VereineProfile::validateFoundingDate(2019, 3, 1, $today), 'a past founding date');
same('', VereineProfile::validateFoundingDate(2026, 9, 16, $today), 'founded today');
same('VereineErrorFoundingDateFuture', VereineProfile::validateFoundingDate(2026, 9, 17, $today), 'founded tomorrow');
same('VereineErrorFoundingDate', VereineProfile::validateFoundingDate(2023, 2, 29, $today), '29 February 2023 does not exist');
same('VereineErrorFoundingDate', VereineProfile::validateFoundingDate(2020, 5, 0, $today), 'a date without its day');
same('VereineErrorFoundingDate', VereineProfile::validateFoundingDate(1700, 1, 1, $today), 'a founding date before 1800');

// -------------------------------------------------------------- organization

$company = array(
	'name' => 'THE LION SQUAD',
	'address' => 'Musterweg 1',
	'zip' => '6020',
	'town' => 'Innsbruck',
	'country_code' => 'AT',
	'email' => 'office@example.test',
	'phone' => '',
	'url' => 'https://example.test',
	'fiscal_month_start' => '',
);
$settings = array(
	'VEREINE_COUNTRY_PROFILE' => 'AT',
	'VEREINE_REGISTER_NUMBER' => '123456789',
	'VEREINE_REGISTER_COURT' => 'Amtsgericht München',
	'VEREINE_AUTHORITY' => 'Landespolizeidirektion Tirol',
	'VEREINE_FOUNDED' => '2019-03-01',
	'VEREINE_NONPROFIT' => '1',
	'VEREINE_PURPOSE' => 'Förderung des E-Sports',
);

$organization = VereineOrganization::build($settings, $company);
same('AT', $organization['country_profile'], 'profile from settings');
same(true, $organization['country_profile_complete'], 'Austria is complete');
same(array('kind' => 'ZVR', 'number' => '123456789', 'court' => ''), $organization['register'], 'an Austrian register carries no court');
same('Landespolizeidirektion Tirol', $organization['authority'], 'authority for Austria');
same(true, $organization['nonprofit'], 'non-profit flag');
same(1, $organization['fiscal_year_start_month'], 'an unset fiscal month means January');
same(
	array('country_profile', 'country_profile_complete', 'name', 'register', 'authority', 'address', 'email', 'phone', 'url', 'founded', 'nonprofit', 'purpose', 'fiscal_year_start_month'),
	array_keys($organization),
	'API version 1 fields and their order'
);

$german = VereineOrganization::build(array_merge($settings, array('VEREINE_COUNTRY_PROFILE' => 'DE', 'VEREINE_REGISTER_NUMBER' => 'VR 12345')), array_merge($company, array('country_code' => 'DE', 'fiscal_month_start' => '7')));
same('Amtsgericht München', $german['register']['court'], 'court for Germany');
same('', $german['authority'], 'a German association has no Vereinsbehörde');
same(7, $german['fiscal_year_start_month'], 'fiscal year starts in July');

$fallback = VereineOrganization::build(array('VEREINE_COUNTRY_PROFILE' => 'XX'), array('country_code' => 'DE'));
same('DE', $fallback['country_profile'], 'an unknown stored profile falls back to the company country');
same('', $fallback['name'], 'missing company data stays empty, not null');
same(false, $fallback['nonprofit'], 'missing non-profit flag is false');

$statusOf = static function (array $checks) {
	$result = array();
	foreach ($checks as $check) {
		$result[$check['code']] = $check['status'];
	}
	return $result;
};

same(
	array('company_name' => 'ok', 'country' => 'ok', 'register' => 'ok', 'profile' => 'ok', 'api' => 'ok'),
	$statusOf(VereineOrganization::checks($organization, true)),
	'a complete Austrian association passes every check'
);
$incomplete = VereineOrganization::build(array('VEREINE_COUNTRY_PROFILE' => 'AT'), array_merge($company, array('town' => '', 'country_code' => 'DE')));
$checks = VereineOrganization::checks($incomplete, false);
same(
	array('company_name' => 'warning', 'country' => 'warning', 'register' => 'warning', 'profile' => 'ok', 'api' => 'warning'),
	$statusOf($checks),
	'missing town, wrong country, no ZVR number and no API are reported'
);
foreach ($checks as $check) {
	if ($check['status'] === VereineOrganization::CHECK_OK) {
		same('', $check['fix'], 'a passed check '.$check['code'].' offers no fix');
	}
}
same('VereineCheckZvrMissing', $checks[2]['label'], 'the Austrian register check names the ZVR number');
$germanChecks = VereineOrganization::checks(VereineOrganization::build(array('VEREINE_COUNTRY_PROFILE' => 'DE', 'VEREINE_REGISTER_NUMBER' => 'VR 1'), array('country_code' => 'DE', 'name' => 'x', 'town' => 'y')), true);
same('warning', $statusOf($germanChecks)['register'], 'a German register number without court is incomplete');
same('warning', $statusOf($germanChecks)['profile'], 'the German profile is reported as preview');

// ---------------------------------------------------------- partner rules

same('anna@verein.test', VereinePartnerRules::normalizeEmail('  Anna@Verein.TEST '), 'e-mail compared in lower case without spaces');
same('müller hans', VereinePartnerRules::normalizeName(' MÜLLER,  Hans. '), 'names compared in lower case, umlauts kept, punctuation gone');

$natural = array('morphy' => 'phy', 'firstname' => 'Lisa', 'lastname' => 'Neu', 'company' => 'Neu Handel', 'email' => 'lisa@verein.test', 'zip' => '6020');
$legal = array('morphy' => 'mor', 'firstname' => 'Max', 'lastname' => 'Kontakt', 'company' => 'Sponsor GmbH', 'email' => '', 'zip' => '6020');
same('Lisa Neu', VereinePartnerRules::partnerName($natural), 'a natural person is named by first and last name');
same('Sponsor GmbH', VereinePartnerRules::partnerName($legal), 'a legal entity is named by its company');

$partners = array(
	array('id' => 1, 'name' => 'Lisa Neu', 'email' => 'other@verein.test', 'zip' => '6020', 'linked_member' => 0),
	array('id' => 2, 'name' => 'Irgendwer', 'email' => 'LISA@verein.test', 'zip' => '1010', 'linked_member' => 0),
	array('id' => 3, 'name' => 'Lisa Neu', 'email' => 'lisa@verein.test', 'zip' => '6020', 'linked_member' => 9),
	array('id' => 4, 'name' => 'Lisa Neu', 'email' => '', 'zip' => '1010', 'linked_member' => 0),
);
same(
	array(array('id' => 2, 'match' => 'email'), array('id' => 1, 'match' => 'name_zip')),
	VereinePartnerRules::candidates($natural, $partners),
	'e-mail matches first, then name and postcode; a partner linked to another member and a different postcode never match'
);
same(array(), VereinePartnerRules::candidates(array_merge($natural, array('email' => '', 'zip' => '')), $partners), 'without e-mail and postcode nothing matches');
same(array(), VereinePartnerRules::candidates($legal, $partners), 'a company name matches no person');

$memberAddress = array('email' => 'lisa@verein.test', 'address' => 'Teststraße 1', 'zip' => '6020', 'town' => 'Innsbruck');
same(array(), VereinePartnerRules::differences($memberAddress, array('email' => 'LISA@verein.test', 'address' => 'teststraße 1.', 'zip' => '6020', 'town' => 'INNSBRUCK')), 'case and trailing punctuation are no difference');
same(
	array('email' => array('member' => 'lisa@verein.test', 'partner' => 'alt@verein.test'), 'zip' => array('member' => '6020', 'partner' => '6060')),
	VereinePartnerRules::differences($memberAddress, array('email' => 'alt@verein.test', 'address' => 'Teststraße 1', 'zip' => '6060', 'town' => 'Innsbruck')),
	'changed e-mail and postcode are reported with both values'
);

same(array('member' => true, 'former' => false), VereinePartnerRules::categoriesFor(1, true), 'an active member is in the member category only');
same(array('member' => false, 'former' => true), VereinePartnerRules::categoriesFor(0, true), 'a resigned member is a former member');
same(array('member' => false, 'former' => true), VereinePartnerRules::categoriesFor(-2, true), 'an excluded member is a former member');
same(null, VereinePartnerRules::categoriesFor(-1, false), 'a draft leaves the categories alone');
same(array('member' => false, 'former' => true), VereinePartnerRules::categoriesFor(1, true, true), 'a deleted member that was active becomes former');
same(array('member' => false, 'former' => false), VereinePartnerRules::categoriesFor(-1, false, true), 'a deleted draft leaves no category');

same(array('set' => 'TE_PRIVATE', 'mismatch' => false), VereinePartnerRules::customerType('phy', '', 'TE_PRIVATE', ''), 'an empty customer type is filled');
same(array('set' => '', 'mismatch' => false), VereinePartnerRules::customerType('phy', 'TE_PRIVATE', 'TE_PRIVATE', ''), 'a matching customer type stays');
same(array('set' => '', 'mismatch' => true), VereinePartnerRules::customerType('phy', 'TE_SMALL', 'TE_PRIVATE', ''), 'a sole trader keeps the business type and is reported');
same(array('set' => '', 'mismatch' => false), VereinePartnerRules::customerType('mor', 'TE_SMALL', 'TE_PRIVATE', ''), 'no type configured for legal entities means leave unchanged');
same(array('set' => 'TE_OTHER', 'mismatch' => false), VereinePartnerRules::customerType('mor', '0', 'TE_PRIVATE', 'TE_OTHER'), 'id 0 counts as empty');

same(1, VereinePartnerRules::customerFlag(0), 'no flag becomes customer');
same(3, VereinePartnerRules::customerFlag(2), 'a prospect becomes customer and prospect');
same(1, VereinePartnerRules::customerFlag(1), 'a customer stays customer');
same(3, VereinePartnerRules::customerFlag(3), 'customer and prospect stays');

same(true, VereinePartnerRules::isMinor('2008-09-17', '2026-09-16'), 'the day before the 18th birthday is under age');
same(false, VereinePartnerRules::isMinor('2008-09-16', '2026-09-16'), 'on the 18th birthday of age');
same(false, VereinePartnerRules::isMinor('', '2026-09-16'), 'an unknown birth date is not treated as under age');
same(true, VereinePartnerRules::isMinor('2015-05-05 00:00:00', '2026-09-16'), 'a date time from the database works');

$checksWithPartners = VereineOrganization::checks($organization, true, 3);
same(
	array('code' => 'partners', 'status' => 'warning', 'label' => 'VereineCheckPartnersOpen', 'fix' => 'partners', 'value' => 3),
	end($checksWithPartners),
	'open partner points are reported with their number'
);
same('ok', $statusOf(VereineOrganization::checks($organization, true, 0))['partners'], 'no open partner points');
same(false, isset($statusOf(VereineOrganization::checks($organization, true))['partners']), 'without the rights to check, no partner check');

// ------------------------------------------------------------- tax rules

$valid = array('code' => 'KANTINE', 'label' => 'Kantine', 'sphere' => 'harmful', 'treatment' => 'standard20', 'rate' => 20, 'note' => '');
same(array(), VereineTaxRules::validate($valid), 'a canteen in the harmful business with 20 % is valid');
same(array('VereineTaxErrorRate'), VereineTaxRules::validate(array_merge($valid, array('rate' => 10))), '20 % treatment with rate 10 is refused');
same(array('VereineTaxErrorRate'), VereineTaxRules::validate(array_merge($valid, array('rate' => 'abc'))), 'a rate that is no number is refused');
same(array(), VereineTaxRules::validate(array_merge($valid, array('rate' => '20.000'))), 'a rate as stored by the database is accepted');
same(array('VereineTaxErrorReducedHarmful', 'VereineTaxErrorRate'), VereineTaxRules::validate(array_merge($valid, array('treatment' => 'reduced10'))), '10 % in the harmful business is refused (and 20 no longer fits)');
same(array('VereineTaxErrorReducedHarmful'), VereineTaxRules::validate(array_merge($valid, array('treatment' => 'reduced10', 'rate' => 10))), '10 % in the harmful business is refused by § 10 (2) no. 4 UStG');
same(array('VereineTaxErrorSportHarmful', 'VereineTaxErrorNoteRequired'), VereineTaxRules::validate(array_merge($valid, array('treatment' => 'sport', 'rate' => 0))), 'the sports exemption does not reach the harmful business');
same(array('VereineTaxErrorIdealNoSupply'), VereineTaxRules::validate(array_merge($valid, array('sphere' => 'ideal'))), 'the idealistic sphere has no taxable supplies');
same(array(), VereineTaxRules::validate(array('code' => 'BEITRAG', 'label' => 'Beitrag', 'sphere' => 'ideal', 'treatment' => 'nonbusiness', 'rate' => 0, 'note' => '')), 'a genuine membership fee is valid without note');
same(array('VereineTaxErrorNonbusinessSphere'), VereineTaxRules::validate(array('code' => 'FEST', 'label' => 'Fest', 'sphere' => 'festival', 'treatment' => 'nonbusiness', 'rate' => 0, 'note' => '')), 'a festival is not without consideration');
same(array(), VereineTaxRules::validate(array('code' => 'ZINSEN', 'label' => 'Zinsen', 'sphere' => 'assets', 'treatment' => 'nonbusiness', 'rate' => 0, 'note' => '')), 'interest in asset management is without consideration');
same(array('VereineTaxErrorHobbySphere'), VereineTaxRules::validate(array('code' => 'MIETE', 'label' => 'Miete', 'sphere' => 'assets', 'treatment' => 'hobby', 'rate' => 0, 'note' => '')), 'Liebhaberei is not presumed for asset management');
foreach (array('essential', 'auxiliary', 'festival') as $sphere) {
	same('', VereineTaxRules::combinationError($sphere, 'hobby'), 'Liebhaberei may be used for '.$sphere);
}
same(array('VereineTaxErrorNoteRequired'), VereineTaxRules::validate(array('code' => 'KU', 'label' => 'KU', 'sphere' => 'harmful', 'treatment' => 'small_business', 'rate' => 0, 'note' => '  ')), 'a small business exemption needs its invoice note');
same(array('VereineTaxErrorNoteLength'), VereineTaxRules::validate(array_merge($valid, array('note' => str_repeat('ä', 1001)))), 'a note of 1001 characters is refused');
same(array(), VereineTaxRules::validate(array_merge($valid, array('note' => str_repeat('ä', 1000)))), 'a note of 1000 umlauts is accepted');
same(array('VereineTaxErrorCode'), VereineTaxRules::validate(array_merge($valid, array('code' => 'kantine'))), 'lower case codes are refused');
same(array('VereineTaxErrorCode'), VereineTaxRules::validate(array_merge($valid, array('code' => '1KANTINE'))), 'codes start with a letter');
same(array('VereineTaxErrorCode'), VereineTaxRules::validate(array_merge($valid, array('code' => str_repeat('A', 33)))), 'codes have at most 32 characters');
same(array('VereineTaxErrorLabel'), VereineTaxRules::validate(array_merge($valid, array('label' => ' '))), 'a blank label is refused');
same(array('VereineTaxErrorSphere'), VereineTaxRules::validate(array_merge($valid, array('sphere' => 'other'))), 'an unknown sphere is refused');
same(array('VereineTaxErrorTreatment'), VereineTaxRules::validate(array_merge($valid, array('treatment' => 'other'))), 'an unknown treatment is refused');
same(array('VereineTaxErrorCode', 'VereineTaxErrorLabel', 'VereineTaxErrorSphere', 'VereineTaxErrorTreatment'), VereineTaxRules::validate(array()), 'an empty profile lists every missing field');
same(10.0, VereineTaxRules::rateOf('reduced10'), 'rate of 10 %');
same(13.0, VereineTaxRules::rateOf('reduced13'), 'rate of 13 %');
same(20.0, VereineTaxRules::rateOf('standard20'), 'rate of 20 %');
same(0.0, VereineTaxRules::rateOf('sport'), 'an exemption has rate 0');
same(null, VereineTaxRules::rateOf('other'), 'an unknown treatment has no rate');
expect(!VereineTaxRules::rateDeviates('20.0000', 20), 'a line rate as the database stores it matches its profile');
expect(VereineTaxRules::rateDeviates(10, '20.000'), '10 % on a 20 % profile deviates');
expect(!VereineTaxRules::rateDeviates(0, '0'), 'no VAT on a no-VAT profile matches');
expect(VereineTaxRules::rateDeviates(13, 10), '13 % on a 10 % profile deviates');
same('10', VereineTaxRules::formatRate('10.000'), 'rates are written without decimals');
same('0', VereineTaxRules::formatRate(0), 'no VAT is written as 0');
same('5,5', VereineTaxRules::formatRate(5.5), 'decimal rates keep their decimals with a comma');
same(
	array(array('positions' => array(1, 3), 'note' => 'Nicht umsatzsteuerbar (Liebhaberei).'), array('positions' => array(2), 'note' => 'Spende.')),
	VereineTaxRules::groupNotes(array(
		array('position' => 1, 'note' => 'Nicht umsatzsteuerbar (Liebhaberei).'),
		array('position' => 2, 'note' => ' Spende. '),
		array('position' => 3, 'note' => 'Nicht umsatzsteuerbar (Liebhaberei).'),
		array('position' => 4, 'note' => ''),
	)),
	'invoice notes are grouped by text in line order, lines without note left out'
);
same(array(), VereineTaxRules::groupNotes(array()), 'an invoice without lines has no notes');
foreach (VereineTaxRules::treatments() as $code => $treatment) {
	expect(strpos($treatment['source'], 'https://') === 0 && $treatment['basis'] !== '', 'treatment '.$code.' names its legal basis and source');
}
foreach (VereineTaxRules::spheres() as $code => $sphere) {
	expect(strpos($sphere['source'], 'https://') === 0 && $sphere['basis'] !== '', 'sphere '.$code.' names its legal basis and source');
}
$codes = array();
foreach (VereineTaxRules::standardProfiles() as $standard) {
	$codes[] = $standard['code'];
	$profile = array_merge($standard, array('label' => 'x', 'note' => $standard['note'] !== '' ? 'Hinweis' : '', 'rate' => VereineTaxRules::rateOf($standard['treatment'])));
	same(array(), VereineTaxRules::validate($profile), 'standard profile '.$standard['code'].' is valid');
}
same(count($codes), count(array_unique($codes)), 'standard profile codes are unique');
$inactive = array();
foreach (VereineTaxRules::standardProfiles() as $standard) {
	if (!$standard['active']) {
		$inactive[] = $standard['code'];
	}
}
same(array('SPORT', 'HILFSBETRIEB_10'), $inactive, 'the sports exemption and 10 % without Liebhaberei start inactive');

// ------------------------------------------------------------- thresholds

same(35000.0, VereineThresholds::validOn('small_business', '2024-12-31')['amount'], 'small business limit on the last day of 2024');
same(false, VereineThresholds::validOn('small_business', '2024-12-31')['gross'], 'until 2024 the small business limit was net');
same(55000.0, VereineThresholds::validOn('small_business', '2025-01-01')['amount'], 'small business limit from 2025');
same(true, VereineThresholds::validOn('small_business', '2025-01-01')['gross'], 'from 2025 the small business limit is gross');
same(null, VereineThresholds::validOn('small_business', '2019-12-31'), 'no small business limit is listed before 2020');
same(40000.0, VereineThresholds::validOn('harmful_business', '2023-12-31')['amount'], 'harmful business limit until 2023');
same(100000.0, VereineThresholds::validOn('harmful_business', '2024-01-01')['amount'], 'harmful business limit from 2024');
foreach (VereineThresholds::table() as $threshold) {
	expect(strpos($threshold['source'], 'https://') === 0 && $threshold['basis'] !== '', 'threshold '.$threshold['code'].' from '.$threshold['valid_from'].' names basis and source');
	expect($threshold['valid_to'] === '' || $threshold['valid_from'] <= $threshold['valid_to'], 'threshold '.$threshold['code'].' has a valid period');
}
foreach (array('small_business', 'harmful_business', 'cash_register', 'festival_hours') as $code) {
	$periods = array();
	foreach (VereineThresholds::table() as $threshold) {
		if ($threshold['code'] === $code) {
			$periods[] = array($threshold['valid_from'], $threshold['valid_to']);
		}
	}
	$periodCount = count($periods);
	for ($i = 1; $i < $periodCount; $i++) {
		expect($periods[$i - 1][1] !== '' && $periods[$i - 1][1] < $periods[$i][0], 'periods of '.$code.' follow each other without overlap');
	}
}

expect(VereineThresholds::counts('small_business', 'harmful', 'standard20'), 'a taxable canteen counts towards the small business limit');
expect(VereineThresholds::counts('small_business', 'harmful', 'small_business'), 'turnover exempt as small business counts');
expect(!VereineThresholds::counts('small_business', 'essential', 'hobby'), 'auxiliary businesses (Liebhaberei) do not count, as the ministry says');
expect(!VereineThresholds::counts('small_business', 'essential', 'sport'), 'the sports exemption does not count towards the small business limit');
expect(!VereineThresholds::counts('small_business', 'ideal', 'nonbusiness'), 'membership fees do not count');
expect(VereineThresholds::counts('harmful_business', 'harmful', 'small_business'), 'every turnover of a harmful business counts towards § 45a BAO');
expect(!VereineThresholds::counts('harmful_business', 'auxiliary', 'reduced10'), 'a dispensable auxiliary business does not count towards § 45a BAO');
expect(!VereineThresholds::counts('cash_register', 'harmful', 'standard20'), 'the cash register duty is not evaluated from invoices');

same('ok', VereineThresholds::status(43999.99, 55000, 10), 'just below 80 % is green');
same('near', VereineThresholds::status(44000, 55000, 10), '80 % is yellow');
same('near', VereineThresholds::status(55000, 55000, 10), 'exactly the limit is still yellow');
same('tolerance', VereineThresholds::status(55000.01, 55000, 10), 'just above the limit with tolerance is orange');
same('tolerance', VereineThresholds::status(60500, 55000, 10), '110 % is still within the tolerance');
same('exceeded', VereineThresholds::status(60500.01, 55000, 10), 'above the tolerance is red');
same('exceeded', VereineThresholds::status(100000.01, 100000, 0), 'without tolerance, above the limit is red');
same('ok', VereineThresholds::status(-50, 55000, 10), 'more credit notes than invoices is green');

// A calendar year counts on its own; the year before only decides whether the exemption holds.
$income2026 = array(
	array('sphere' => 'harmful', 'treatment' => 'standard20', 'net' => 50000.0, 'gross' => 60000.0),
	array('sphere' => 'essential', 'treatment' => 'hobby', 'net' => 9000.0, 'gross' => 9000.0),
	array('sphere' => 'ideal', 'treatment' => 'nonbusiness', 'net' => 20000.0, 'gross' => 20000.0),
);
$income2025 = array(array('sphere' => 'harmful', 'treatment' => 'standard20', 'net' => 1000.0, 'gross' => 1200.0));
$result = VereineThresholds::evaluate(2026, $income2026, $income2025);
same(array('small_business', 'harmful_business'), array($result[0]['code'], $result[1]['code']), 'both evaluated thresholds for 2026');
same(60000.0, $result[0]['amount'], 'small business limit counts the gross canteen only');
same('tolerance', $result[0]['status'], '60,000 gross is within the tolerance of 55,000');
same(false, $result[0]['previous_exceeded'], '2025 was below the limit');
same('ok', $result[1]['status'], '60,000 of 100,000 for § 45a BAO is green');
same(0.6, $result[1]['ratio'], 'ratio of the harmful business limit');
$result = VereineThresholds::evaluate(2025, $income2025, $income2026);
same(1200.0, $result[0]['amount'], '2025 counts only its own invoices');
same(true, $result[0]['previous_exceeded'], 'a year before above the limit is reported');
$result = VereineThresholds::evaluate(2024, array(array('sphere' => 'harmful', 'treatment' => 'standard20', 'net' => 34000.0, 'gross' => 40800.0)), array());
same(34000.0, $result[0]['amount'], 'until 2024 the small business limit counts net amounts');
same('near', $result[0]['status'], '34,000 net of 35,000 is yellow');
same(array(), VereineThresholds::evaluate(2015, $income2025, array()), 'no thresholds are known for 2015');

// ---------------------------------------------------------- cash register

same('not_relevant', VereineCashRegister::status('ideal', 90000, 90000), 'fees and donations raise no cash register question');
same('exempt', VereineCashRegister::status('essential', 90000, 90000), 'an indispensable auxiliary business needs no cash register (§ 3 (1) BarUV)');
same('exempt_festival', VereineCashRegister::status('festival', 90000, 90000), 'a small festival needs no cash register (§ 3 (2) BarUV)');
same('near', VereineCashRegister::status('harmful', 15000, 90000), 'exactly 15,000 turnover does not exceed the limit, but is close');
same('near', VereineCashRegister::status('harmful', 90000, 7500), 'exactly 7,500 cash does not exceed the limit, but is close');
same('ok', VereineCashRegister::status('harmful', 90000, 5000), 'plenty of turnover with little cash needs no register');
same('required', VereineCashRegister::status('harmful', 15000.01, 7500.01), 'both limits exceeded needs a cash register');
same('near', VereineCashRegister::status('auxiliary', 12000, 6000), '80 % of both limits is close');
same('ok', VereineCashRegister::status('auxiliary', 12000, 5999.99), 'close needs both limits near');
same(45000.0, VereineCashRegister::smallCanteenLimit(2026), 'small canteen limit from 2026');
same(30000.0, VereineCashRegister::smallCanteenLimit(2025), 'small canteen limit until 2025');
same(array('harmful' => 14888.34, 'ideal' => 4962.78, '' => 148.88),
	VereineCashRegister::allocate(array('harmful' => 60000.0, 'ideal' => 20000.0, '' => 600.0), 20000),
	'cash of an invoice is shared out over its spheres');
same(array(), VereineCashRegister::allocate(array(), 100), 'an invoice without lines shares out nothing');
expect(in_array('LIQ', VereineCashRegister::CASH_PAYMENT_CODES, true) && in_array('CB', VereineCashRegister::CASH_PAYMENT_CODES, true), 'cash and card count as cash turnover');
expect(!in_array('VIR', VereineCashRegister::CASH_PAYMENT_CODES, true), 'a bank transfer is no cash turnover');

// ---------------------------------------------------------- member summary

same('draft', VereineMemberSummary::status(-1), 'a member not yet validated is a draft');
same('active', VereineMemberSummary::status('1'), 'a validated member is active');
same('terminated', VereineMemberSummary::status(0), 'a resiliated member has terminated the membership');
same('excluded', VereineMemberSummary::status(-2), 'an excluded member');

same('2019-03-01', VereineMemberSummary::memberSince('active', '2019-03-01', '2026-09-17'), 'an imported history reaches back before the validation');
same('2026-01-10', VereineMemberSummary::memberSince('active', '', '2026-01-10'), 'without subscription the validation date counts');
same('', VereineMemberSummary::memberSince('draft', '2026-01-01', ''), 'a draft is no member yet');

$today = '2026-09-17';
same(array('status' => 'paid', 'next_due' => '2027-01-01'), VereineMemberSummary::fee('active', true, '2026-12-31', '', '2026-01-10', $today), 'a period covering today is paid, the next fee is due the day after');
same(array('status' => 'paid', 'next_due' => '2026-09-18'), VereineMemberSummary::fee('active', true, '2026-09-17', '', '2026-01-10', $today), 'the last day of a period is still paid');
same(array('status' => 'due', 'next_due' => '2026-09-17'), VereineMemberSummary::fee('active', true, '2026-09-16', '', '2026-01-10', $today), 'the day after a period the fee is due');
same(array('status' => 'due', 'next_due' => '2026-01-10'), VereineMemberSummary::fee('active', true, '', '', '2026-01-10', $today), 'a member who never paid owes the fee since the validation');
same(array('status' => 'not_required', 'next_due' => ''), VereineMemberSummary::fee('active', false, '', '', '2026-01-10', $today), 'a member type without subscription');
same(array('status' => 'inactive', 'next_due' => ''), VereineMemberSummary::fee('terminated', true, '2026-12-31', '', '2026-01-10', $today), 'a former member owes no further fee');
same(array('status' => 'inactive', 'next_due' => ''), VereineMemberSummary::fee('draft', true, '', '', '', $today), 'a draft owes no fee yet');
same(array('status' => 'invoiced', 'next_due' => '2026-09-17'), VereineMemberSummary::fee('active', true, '2026-09-16', '2026-12-31', '2026-01-10', $today),
	'a fee run billed the current period, the invoice is not paid yet: invoiced, not paid');
same(array('status' => 'invoiced', 'next_due' => '2026-01-10'), VereineMemberSummary::fee('active', true, '', '2026-12-31', '2026-01-10', $today), 'a first fee invoiced but not paid');
same(array('status' => 'due', 'next_due' => '2025-01-01'), VereineMemberSummary::fee('active', true, '2024-12-31', '2025-12-31', '2024-01-10', $today),
	'an unpaid invoice of last year does not cover this year: due');
same(array('status' => 'paid', 'next_due' => '2027-01-01'), VereineMemberSummary::fee('active', true, '2026-12-31', '2027-12-31', '2026-01-10', $today), 'paid this year, next year already invoiced');
same(array('paid_until' => '2025-12-31', 'invoiced_until' => '2026-12-31'),
	VereineMemberSummary::periodEnds(array(array('end' => '2025-12-31', 'invoice_status' => null), array('end' => '2026-12-31', 'invoice_status' => 1))),
	'a period without fee invoice counts as paid, one with an open fee invoice as invoiced');
same(array('paid_until' => '2026-12-31', 'invoiced_until' => ''),
	VereineMemberSummary::periodEnds(array(array('end' => '2025-12-31', 'invoice_status' => 2), array('end' => '2026-12-31', 'invoice_status' => 2))), 'paid fee invoices');
same(array('paid_until' => '2025-12-31', 'invoiced_until' => ''),
	VereineMemberSummary::periodEnds(array(array('end' => '2025-12-31', 'invoice_status' => null), array('end' => '2026-12-31', 'invoice_status' => 3), array('end' => '2027-12-31', 'invoice_status' => 0))),
	'an abandoned or draft fee invoice pays nothing');

expect(VereineMemberSummary::overdue('2026-09-16', $today), 'an invoice due yesterday is overdue');
expect(!VereineMemberSummary::overdue('2026-09-17', $today), 'an invoice due today is not overdue');
expect(!VereineMemberSummary::overdue('', $today), 'an invoice without due date is not overdue');

same('open', VereineMemberSummary::invoiceStatus(1, '2026-09-17', $today), 'a validated invoice due today is open');
same('overdue', VereineMemberSummary::invoiceStatus('1', '2026-09-16', $today), 'a validated invoice due yesterday is overdue');
same('open', VereineMemberSummary::invoiceStatus(1, '', $today), 'a validated invoice without due date is open');
same('paid', VereineMemberSummary::invoiceStatus(2, '2026-01-01', $today), 'a closed invoice is paid, whatever its due date');
same('abandoned', VereineMemberSummary::invoiceStatus(3, '2026-01-01', $today), 'an abandoned invoice');
same(array('standard', 'replacement', 'credit_note', 'deposit', ''),
	array_map(array('VereineMemberSummary', 'invoiceType'), array(0, '1', 2, 3, 5)),
	'names of Dolibarr\'s invoice types, none for a type the module does not list');

same(1758096000, VereineMemberSummary::parseMoment('2025-09-17T08:00:00Z'), 'a moment in UTC');
same(1758096000, VereineMemberSummary::parseMoment('2025-09-17T10:00:00+02:00'), 'a moment in Vienna summer time');
same(1758096000, VereineMemberSummary::parseMoment('2025-09-17T03:00-05:00'), 'a moment west of UTC without seconds');
same(null, VereineMemberSummary::parseMoment('2025-09-17T08:00:00'), 'a moment without time zone is refused');
same(null, VereineMemberSummary::parseMoment('2025-02-30T08:00:00Z'), 'a day that does not exist is refused');
same(null, VereineMemberSummary::parseMoment('gestern'), 'no moment in words');
same(-7200, VereineMemberSummary::clockOffset(1000000000 - 7197, 1000000000), 'MariaDB in UTC read like PHP in Vienna summer time is two hours early');
same(0, VereineMemberSummary::clockOffset(1000000003, 1000000000), 'the same time zone, a few seconds apart, is no offset');
same(19800, VereineMemberSummary::clockOffset(1000019800, 1000000000), 'offsets of half hours, such as India');
same('2025-09-17T08:00:00Z', VereineMemberSummary::isoMoment(1758096000), 'a moment written in UTC');
same('', VereineMemberSummary::isoMoment(0), 'no moment');
same(300, VereineMemberSummary::latestMoment(array(100, null, 300, 900), 500), 'the latest moment not in the future');
same(0, VereineMemberSummary::latestMoment(array(null, 0), 500), 'no moment at all');
same(array('2026-09-11'), VereineMemberSummary::changeDays('active', true, '2026-09-10', '', $today), 'a fee that ran out changes the summary the day after');
same(array(), VereineMemberSummary::changeDays('active', true, '2026-09-17', '', $today), 'a fee paid until today changes nothing yet');
same(array(), VereineMemberSummary::changeDays('terminated', true, '2026-09-10', '', $today), 'the fee of a former member changes nothing');
same(array(), VereineMemberSummary::changeDays('active', false, '2026-09-10', '', $today), 'a member type without subscription changes nothing');
same(array('2026-08-16'), VereineMemberSummary::changeDays('active', false, '', '2026-08-15', $today), 'an invoice becomes overdue the day after its due date');

same('2025-01-01', VereineMemberSummary::dayAfter('2024-12-31'), 'the day after New Year\'s Eve');
same('2024-02-29', VereineMemberSummary::dayAfter('2024-02-28'), 'the day after 28 February in a leap year');
same('', VereineMemberSummary::dayAfter('31.12.2024'), 'no day after an invalid date');

same('2026-12-31', VereineMemberSummary::dayOf('2026-12-31 00:00:00'), 'a day stored at midnight');
same('2026-12-31', VereineMemberSummary::dayOf('2026-12-30 23:00:00'), '31 December entered in Vienna on a server in UTC');
same('2026-12-31', VereineMemberSummary::dayOf('2026-12-31 05:00:00'), '31 December entered in New York on a server in UTC');
same('2026-10-17', VereineMemberSummary::dayOf('2026-10-17'), 'a date column without time');
same('', VereineMemberSummary::dayOf(null), 'no day for an empty value');
same('', VereineMemberSummary::dayOf('0000-00-00 00:00:00'), 'no day for a zero date');
same('2026-09-17', VereineMemberSummary::datePart('2026-09-17 21:30:00'), 'a validation in the evening keeps its day');
same('', VereineMemberSummary::datePart(''), 'no validation date');

// ----------------------------------------------------------- website events

expect(VereineWebsiteEvents::handles('BILL_PAYED') && VereineWebsiteEvents::handles('MEMBER_SUBSCRIPTION_CREATE') && VereineWebsiteEvents::handles('PAYMENT_CUSTOMER_DELETE'),
	'payments, subscription periods and invoices can change a member summary');
expect(!VereineWebsiteEvents::handles('BILL_SUPPLIER_PAYED') && !VereineWebsiteEvents::handles('VEREINE_MEMBER_CHANGED') && !VereineWebsiteEvents::handles('BILL_CREATE'),
	'supplier invoices, the module\'s own event and new drafts do not');
same(array(12, 13), VereineWebsiteEvents::notYetAnnounced(array(12, 13, 12)), 'a member is announced once, even when the event names it twice');
same(array(14), VereineWebsiteEvents::notYetAnnounced(array(13, 14, 0)), 'a member already announced in this request is left out');
$eventVars = array_keys(get_object_vars(new VereineMemberEvent()));
sort($eventVars);
same(array('cause', 'context', 'element', 'id', 'member_id', 'occurred_at'), $eventVars, 'a webhook receives the member id, the cause and the moment, nothing personal');

// ---------------------------------------------------------------- fee rules

$calendarYear = array('amount' => 60, 'duration_value' => 1, 'duration_unit' => 'y', 'start_month' => 1, 'prorated' => true, 'admission_fee' => 20);
$fee = VereineFeeRules::nextFee($calendarYear, '2026-03-15', '');
same(array('start' => '2026-03-15', 'end' => '2026-12-31', 'amount' => 50.0, 'admission_fee' => 20.0, 'total' => 70.0, 'reason' => 'prorated', 'months' => 10, 'period_months' => 12,
	'proration' => 'month', 'parts' => 10, 'period_parts' => 12),
	$fee, 'joining on 15 March in a calendar fee year pays March to December, 10 of 12 months, plus the admission fee');
$fee = VereineFeeRules::nextFee($calendarYear, '2026-03-15', '2026-12-31');
same(array('2027-01-01', '2027-12-31', 60.0, 0.0, 'full'), array($fee['start'], $fee['end'], $fee['amount'], $fee['admission_fee'], $fee['reason']),
	'the year after is the full calendar year, without admission fee');
$fee = VereineFeeRules::nextFee($calendarYear, '2026-01-01', '');
same(array('2026-12-31', 60.0, 80.0, 'full', 0), array($fee['end'], $fee['amount'], $fee['total'], $fee['reason'], $fee['months']), 'joining on 1 January pays the full year');
$fee = VereineFeeRules::nextFee($calendarYear, '2026-01-10', '2026-05-31');
same(array('2026-06-01', '2026-12-31', 35.0, 0.0, 'prorated'), array($fee['start'], $fee['end'], $fee['amount'], $fee['admission_fee'], $fee['reason']),
	'a period that ended in May from before the model is brought in line with the calendar year');

$season = array('amount' => 60, 'duration_value' => 1, 'duration_unit' => 'y', 'start_month' => 9, 'prorated' => false, 'admission_fee' => 0);
$fee = VereineFeeRules::nextFee($season, '2026-03-15', '');
same(array('2026-03-15', '2026-08-31', 60.0, 'rest_full', 6), array($fee['start'], $fee['end'], $fee['amount'], $fee['reason'], $fee['months']),
	'joining in March in a season from September pays the rest of the season in full when nothing is prorated');
$fee = VereineFeeRules::nextFee($season, '2026-03-15', '2026-08-31');
same(array('2026-09-01', '2027-08-31', 'full'), array($fee['start'], $fee['end'], $fee['reason']), 'the next season runs September to August');

$halfYear = array('amount' => 60, 'duration_value' => 6, 'duration_unit' => 'm', 'start_month' => 1, 'prorated' => true);
$fee = VereineFeeRules::nextFee($halfYear, '2026-08-10', '');
same(array('2026-12-31', 50.0, 5, 6), array($fee['end'], $fee['amount'], $fee['months'], $fee['period_months']), 'half years from January: joining in August pays August to December, 5 of 6 months');

$monthly = array('amount' => 5, 'duration_value' => 1, 'duration_unit' => 'm');
$fee = VereineFeeRules::nextFee($monthly, '2026-04-10', '');
same(array('2026-04-10', '2026-05-09', 5.0, 'full'), array($fee['start'], $fee['end'], $fee['amount'], $fee['reason']), 'a monthly fee from the day of joining');
same('2026-03-02', VereineFeeRules::nextFee($monthly, '2026-01-31', '')['end'], 'a month from 31 January ends like PHP and Dolibarr count, on 2 March');
same('2026-03-17', VereineFeeRules::nextFee(array('amount' => 5, 'duration_value' => 2, 'duration_unit' => 'w', 'start_month' => 3), '2026-03-04', '')['end'],
	'weeks ignore a start month');

$fee = VereineFeeRules::nextFee(array('amount' => '', 'duration_value' => 1, 'duration_unit' => 'y'), '2026-03-15', '');
same(array(null, null), array($fee['amount'], $fee['total']), 'a member type without amount has no amount to charge');
same(null, VereineFeeRules::nextFee($calendarYear, '', ''), 'no fee without a day to start from');
same(array('amount' => null, 'duration_value' => 1, 'duration_unit' => 'y', 'start_month' => 0, 'proration' => 'none', 'prorated' => false, 'admission_fee' => 0.0),
	VereineFeeRules::normalize(array('duration_unit' => 'x', 'start_month' => 13, 'duration_value' => 0, 'admission_fee' => -5, 'proration' => 'weekly')), 'unknown values fall back to safe defaults');
same('month', VereineFeeRules::normalize(array('prorated' => '1'))['proration'], 'the ticked checkbox of 0.3.4 means by month');
same('half_year', VereineFeeRules::normalize(array('proration' => 'half_year', 'prorated' => '1'))['proration'], 'a chosen proration wins over the old checkbox');
same('none', VereineFeeRules::normalize(array('proration' => 'none', 'prorated' => '1'))['proration'], 'not prorated, chosen, wins over the old checkbox');

// Prorated by half-year and by quarter, counted from the start of the fee year.
$halfYears = array('amount' => 60, 'duration_value' => 1, 'duration_unit' => 'y', 'start_month' => 1, 'proration' => 'half_year');
$fee = VereineFeeRules::nextFee($halfYears, '2026-03-15', '');
same(array(60.0, 'first_part_full', 'half_year', 2, 2), array($fee['amount'], $fee['reason'], $fee['proration'], $fee['parts'], $fee['period_parts']),
	'by half-year: joining in March, the first half, pays the full amount');
$fee = VereineFeeRules::nextFee($halfYears, '2026-07-01', '');
same(array(30.0, 'prorated', 1, 2, '2026-12-31'), array($fee['amount'], $fee['reason'], $fee['parts'], $fee['period_parts'], $fee['end']), 'by half-year: joining on 1 July pays the half');
same(60.0, VereineFeeRules::nextFee($halfYears, '2026-06-30', '')['amount'], 'by half-year: 30 June is still the first half');
$fee = VereineFeeRules::nextFee(array('amount' => 60, 'duration_value' => 1, 'duration_unit' => 'y', 'start_month' => 1, 'proration' => 'quarter'), '2026-05-20', '');
same(array(45.0, 'prorated', 3, 4), array($fee['amount'], $fee['reason'], $fee['parts'], $fee['period_parts']), 'by quarter: joining in May pays 3 of 4 quarters');
$fee = VereineFeeRules::nextFee(array('amount' => 60, 'duration_value' => 1, 'duration_unit' => 'y', 'start_month' => 9, 'proration' => 'half_year'), '2027-02-10', '');
same(array(60.0, 'first_part_full', '2027-08-31'), array($fee['amount'], $fee['reason'], $fee['end']), 'season from September by half-year: February is still the first half');
$fee = VereineFeeRules::nextFee(array('amount' => 60, 'duration_value' => 1, 'duration_unit' => 'y', 'start_month' => 9, 'proration' => 'half_year'), '2027-03-01', '');
same(array(30.0, 'prorated'), array($fee['amount'], $fee['reason']), 'season from September by half-year: March starts the second half');
$fee = VereineFeeRules::nextFee(array('amount' => 30, 'duration_value' => 3, 'duration_unit' => 'm', 'start_month' => 1, 'proration' => 'half_year'), '2026-02-10', '');
same(array('month', 20.0, 2, 3), array($fee['proration'], $fee['amount'], $fee['parts'], $fee['period_parts']),
	'a quarterly fee cannot be split into half-years: by month instead, February and March of three months');
$fee = VereineFeeRules::nextFee(array('amount' => 60, 'duration_value' => 1, 'duration_unit' => 'y', 'start_month' => 1, 'proration' => 'month'), '2026-01-20', '');
same(array(60.0, 'first_part_full'), array($fee['amount'], $fee['reason']), 'by month: joining in January, the first month, pays in full');
same('2024-02-29', VereineFeeRules::addDays('2024-02-28', 1), 'leap day');

// ---------------------------------------------------------- fee discounts

same(17, VereineFeeDiscounts::age('2008-01-15', '2026-01-14'), 'the day before the 18th birthday is still 17');
same(18, VereineFeeDiscounts::age('2008-01-01', '2026-01-01'), 'on the 18th birthday the age is 18');
same(null, VereineFeeDiscounts::age('', '2026-01-01'), 'no age without birth date');
same(null, VereineFeeDiscounts::age('2027-01-01', '2026-01-01'), 'no age before birth');

$youth = array('id' => 1, 'label' => 'Jugend', 'kind' => 'age', 'type_id' => 0, 'age_from' => '', 'age_to' => '17', 'mode' => 'percent', 'value' => 50.0);
$seniors = array('id' => 2, 'label' => 'Senioren', 'kind' => 'age', 'type_id' => 0, 'age_from' => '65', 'age_to' => '', 'mode' => 'amount', 'value' => 40.0);
$students = array('id' => 3, 'label' => 'Studierende', 'kind' => 'proof', 'type_id' => 0, 'age_from' => '', 'age_to' => '', 'mode' => 'amount', 'value' => 30.0);
$otherType = array('id' => 4, 'label' => 'Nur Aktive', 'kind' => 'age', 'type_id' => 9, 'age_from' => '', 'age_to' => '99', 'mode' => 'free', 'value' => 0.0);
$rules = array($youth, $seniors, $students, $otherType);
$member = array('type_id' => 5, 'exempt' => false, 'exempt_reason' => '', 'proof_rule' => 0, 'proof_until' => '', 'birth' => '2015-05-05');

$discount = VereineFeeDiscounts::choose($rules, $member, '2026-01-01');
same(array('age', 1, 'Jugend'), array($discount['kind'], $discount['rule']['id'], $discount['reason']), 'a child of 10 gets the youth discount');
same(30.0, VereineFeeDiscounts::apply(60.0, $discount), 'youth pays half');
$discount = VereineFeeDiscounts::choose($rules, array('birth' => '1950-02-01') + $member, '2026-01-01');
same(array('age', 40.0), array($discount['kind'], VereineFeeDiscounts::apply(60.0, $discount)), 'a senior pays the fixed lower amount');
same(20.0, VereineFeeDiscounts::apply(20.0, $discount), 'a fixed amount never raises a smaller fee');
$discount = VereineFeeDiscounts::choose($rules, array('birth' => '1990-02-01') + $member, '2026-01-01');
same(array('none', 60.0, array()), array($discount['kind'], VereineFeeDiscounts::apply(60.0, $discount), $discount['notes']), 'an adult of 35 gets no discount');
$discount = VereineFeeDiscounts::choose($rules, array('birth' => '') + $member, '2026-01-01');
same(array('none', array('VereineDiscountNoteNoBirth')), array($discount['kind'], $discount['notes']), 'without birth date the age rules are noted, not guessed');

$discount = VereineFeeDiscounts::choose($rules, array('proof_rule' => 3, 'proof_until' => '2026-03-31', 'birth' => '2000-01-01') + $member, '2026-01-01');
same(array('proof', 30.0), array($discount['kind'], VereineFeeDiscounts::apply(60.0, $discount)), 'a student with a valid proof pays the student amount');
$discount = VereineFeeDiscounts::choose($rules, array('proof_rule' => 3, 'proof_until' => '2025-12-31', 'birth' => '2000-01-01') + $member, '2026-01-01');
same(array('none', array('VereineDiscountNoteProofExpired')), array($discount['kind'], $discount['notes']), 'an expired proof gives no discount and is noted');
$discount = VereineFeeDiscounts::choose($rules, array('proof_rule' => 3, 'proof_until' => '2025-12-31', 'birth' => '2012-01-01') + $member, '2026-01-01');
same(array('age', array('VereineDiscountNoteProofExpired')), array($discount['kind'], $discount['notes']), 'an expired proof of a child falls back to the youth discount, noted');
$discount = VereineFeeDiscounts::choose($rules, array('proof_rule' => 3, 'proof_until' => '2026-03-31', 'birth' => '2012-01-01') + $member, '2026-01-01');
same('proof', $discount['kind'], 'a valid proof comes before the age');

$discount = VereineFeeDiscounts::choose($rules, array('exempt' => true, 'exempt_reason' => 'Ehrenmitglied', 'proof_rule' => 3, 'proof_until' => '2026-03-31') + $member, '2026-01-01');
same(array('exempt', 'Ehrenmitglied', 0.0), array($discount['kind'], $discount['reason'], VereineFeeDiscounts::apply(60.0, $discount)), 'an exemption comes first and costs nothing');
$discount = VereineFeeDiscounts::choose($rules, array('type_id' => 9, 'birth' => '1990-01-01') + $member, '2026-01-01');
same(array(4, 0.0), array($discount['rule']['id'], VereineFeeDiscounts::apply(60.0, $discount)), 'a rule for one member type applies to it');
same(null, VereineFeeDiscounts::apply(null, VereineFeeDiscounts::choose($rules, $member, '2026-01-01')), 'no amount stays no amount');

same(array(), VereineFeeDiscounts::validate(array('label' => 'Jugend', 'kind' => 'age', 'age_from' => '', 'age_to' => '17', 'mode' => 'percent', 'value' => '50')), 'a valid age rule');
same(array('VereineDiscountErrorAge'), VereineFeeDiscounts::validate(array('label' => 'Alle', 'kind' => 'age', 'age_from' => '', 'age_to' => '', 'mode' => 'free', 'value' => '')), 'an age rule needs an age');
same(array('VereineDiscountErrorAge'), VereineFeeDiscounts::validate(array('label' => 'Falsch', 'kind' => 'age', 'age_from' => '30', 'age_to' => '20', 'mode' => 'free', 'value' => '')), 'from must not be above to');
same(array('VereineDiscountErrorPercent'), VereineFeeDiscounts::validate(array('label' => 'Alles', 'kind' => 'proof', 'mode' => 'percent', 'value' => '100')), '100 % is free, not a percentage');
same(array('VereineDiscountErrorLabel', 'VereineDiscountErrorKind', 'VereineDiscountErrorMode'), VereineFeeDiscounts::validate(array('label' => ' ', 'kind' => 'x', 'mode' => 'y')), 'label, kind and mode are required');
same(false, VereineFeeDiscounts::optionalAge('-1'), 'a negative age is invalid');

// ---------------------------------------------------------------- families

same(array('mode' => 'percent', 'value' => 20.0), VereineFeeFamilies::normalize('percent', '20'), 'a family discount of 20 %');
same(array('mode' => 'cap', 'value' => 120.0), VereineFeeFamilies::normalize('cap', '120'), 'a family cap of 120');
same(array('mode' => 'none', 'value' => 0.0), VereineFeeFamilies::normalize('percent', '100'), '100 % is no family discount that can be used');
same(array('mode' => 'none', 'value' => 0.0), VereineFeeFamilies::normalize('', ''), 'without setting there is no family rule');
same(array(), VereineFeeFamilies::validate('none', ''), 'no family discount needs no value');
same(array('VereineFamilyErrorPercent'), VereineFeeFamilies::validate('percent', '0'), 'a family discount of 0 % is refused');
same(array('VereineFamilyErrorCap'), VereineFeeFamilies::validate('cap', '0'), 'a family cap of 0 is refused');
same(array('VereineFamilyErrorMode'), VereineFeeFamilies::validate('other', '5'), 'an unknown family rule is refused');

same(12, VereineFeeFamilies::payerOf(12, 0), 'without payer the member\'s own third party gets the invoice');
same(30, VereineFeeFamilies::payerOf(12, 30), 'a payer gets the invoice instead of the member\'s own third party');
same(30, VereineFeeFamilies::payerOf(0, 30), 'a payer gets the invoice of a member without third party');
same(0, VereineFeeFamilies::payerOf(0, -1), 'without payer and third party nobody can be invoiced');
same(array(false, true, false), array(VereineFeeFamilies::paidByOther(12, 0), VereineFeeFamilies::paidByOther(0, 30), VereineFeeFamilies::paidByOther(30, 30)),
	'only a payer other than the own third party pays for someone else');

same(60.0, VereineFeeFamilies::yearlyAmount(60.0, 12), 'a yearly fee per year');
same(60.0, VereineFeeFamilies::yearlyAmount(5.0, 1), 'a monthly fee of 5 is 60 per year');
same(null, VereineFeeFamilies::yearlyAmount(null, 12), 'no amount stays no amount');
same(8, VereineFeeFamilies::head(array(3 => 30.0, 5 => 60.0, 8 => 90.0)), 'the highest fee pays in full');
same(5, VereineFeeFamilies::head(array(3 => 30.0, 5 => 60.0, 7 => 60.0)), 'with equal fees the first member pays in full');
same(null, VereineFeeFamilies::head(array(3 => 0.0)), 'nobody pays in full without a fee');
same(48.0, VereineFeeFamilies::percentOff(60.0, 20.0), 'a further member pays 20 % less');

same(array('a' => 60.0, 'b' => 60.0, 'c' => 30.0), VereineFeeFamilies::share(array('a' => 60.0, 'b' => 60.0, 'c' => 30.0), 150), 'a family at the cap pays as it is');
same(array('a' => 48.0, 'b' => 48.0, 'c' => 24.0), VereineFeeFamilies::share(array('a' => 60.0, 'b' => 60.0, 'c' => 30.0), 120), 'above the cap every fee is lowered in proportion');
$capped = VereineFeeFamilies::share(array('a' => 10.0, 'b' => 10.0, 'c' => 10.0), 20);
same(array('a' => 6.66, 'b' => 6.66, 'c' => 6.68), $capped, 'fees are rounded down to the cent, the last takes the rest');
same(20.0, round(array_sum($capped), 2), 'so the family pays exactly the cap');
same(array('a' => 0.0, 'b' => 0.0), VereineFeeFamilies::share(array('a' => 60.0, 'b' => 30.0), -12), 'a family already above the cap pays nothing more');
same(array('a' => 18.0, 'b' => 0.0), VereineFeeFamilies::share(array('a' => 60.0, 'b' => 0.0), 18), 'the rest goes to the last fee above 0');

same('2026-01-01', VereineFeeFamilies::feeYear('2026-09-17', 0), 'without start month the fee year is the calendar year');
same('2026-09-01', VereineFeeFamilies::feeYear('2026-09-17', 9), 'a season starting in September');
same('2025-09-01', VereineFeeFamilies::feeYear('2026-08-31', 9), 'the last day of the season belongs to the year before');

// ------------------------------------------------------------------- exits

$yearEnd = VereineExitRules::normalize(3, 'year_end', 1);
same('2026-12-31', VereineExitRules::lastDay('2026-09-30', $yearEnd), 'notice on 30 September with 3 months ends on 31 December');
same('2027-12-31', VereineExitRules::lastDay('2026-10-01', $yearEnd), 'notice on 1 October ends a year later');
same('2026-12-31', VereineExitRules::lastDay('2026-06-15', $yearEnd), 'notice in June ends at the end of the year');
$season = VereineExitRules::normalize(3, 'year_end', 9);
same('2027-08-31', VereineExitRules::lastDay('2026-09-17', $season), 'a season from September ends on 31 August');
same('2026-08-31', VereineExitRules::lastDay('2026-05-31', $season), 'notice on 31 May is just in time for the season');
same('2027-08-31', VereineExitRules::lastDay('2026-06-01', $season), 'notice on 1 June is too late for the season');
same('2026-02-28', VereineExitRules::lastDay('2026-01-31', VereineExitRules::normalize(1, 'month_end', 1)), 'one month from 31 January ends with February');
same('2026-03-31', VereineExitRules::lastDay('2026-02-15', VereineExitRules::normalize(1, 'month_end', 1)), 'one month from mid-February ends with March');
same('2026-09-30', VereineExitRules::lastDay('2026-09-17', VereineExitRules::normalize(0, 'quarter_end', 1)), 'without period at the end of the quarter');
same('2026-11-30', VereineExitRules::lastDay('2026-09-17', VereineExitRules::normalize(1, 'quarter_end', 3)), 'quarters of a year from March end in November');
same('2026-09-17', VereineExitRules::lastDay('2026-09-17', VereineExitRules::normalize(0, 'any_day', 1)), 'without rule the day of the notice');
same(null, VereineExitRules::lastDay('gestern', $yearEnd), 'no last day without a day of notice');
same(array('months' => 0, 'at' => 'any_day', 'start_month' => 1), VereineExitRules::normalize('', 'x', 13), 'an unusable rule is no rule');
same(array('VereineExitErrorMonths', 'VereineExitErrorAt', 'VereineExitErrorStartMonth'), VereineExitRules::validate('25', 'soon', 0), 'months, kind and start month are checked');
same(array(), VereineExitRules::validate('3', 'year_end', 9), 'a valid notice rule');
same(array(true, true, false), array(VereineExitRules::isDue('2026-09-17', '2026-09-17'), VereineExitRules::isDue('2026-09-16', '2026-09-17'), VereineExitRules::isDue('2026-09-18', '2026-09-17')),
	'an exit takes effect on its last day or later');
same(array('excluded', 'resiliated', 'resiliated'), array(VereineExitRules::statusFor('exclusion'), VereineExitRules::statusFor('death'), VereineExitRules::statusFor('resignation')),
	'only an exclusion excludes in Dolibarr');

// -------------------------------------------------------------------- sepa

same('valid', VereineSepa::mandateStatus('M-1', '2024-01-10', '', '2026-09-17'), 'a mandate signed 32 months ago and never used is valid');
same('expired', VereineSepa::mandateStatus('M-1', '2023-01-10', '', '2026-09-17'), 'a mandate never used for more than 36 months has expired');
same('valid', VereineSepa::mandateStatus('M-1', '2020-01-10', '2024-03-01', '2026-09-17'), 'a collection starts the 36 months again');
same('valid', VereineSepa::mandateStatus('M-1', '2023-09-17', '', '2026-09-17'), 'on the day 36 months after signing the mandate still counts');
same('expired', VereineSepa::mandateStatus('M-1', '2023-09-16', '', '2026-09-17'), 'a day later it has expired');
same('expired', VereineSepa::mandateStatus('M-2', '2023-01-10', '2019-05-01', '2026-09-17'), 'a collection before signing a new mandate does not count');
same(array('none', 'none'), array(VereineSepa::mandateStatus('', '2026-01-01', '', '2026-09-17'), VereineSepa::mandateStatus('M-1', '', '', '2026-09-17')),
	'without reference or signature date there is no mandate');
same(array(14, 5, 14, 14), array(VereineSepa::noticeDays(''), VereineSepa::noticeDays('5'), VereineSepa::noticeDays('0'), VereineSepa::noticeDays('61')), 'days of pre-notification from 1 to 60, 14 otherwise');
same('2026-10-01', VereineSepa::collectionDay('2026-09-17', 14), 'collection 14 days after the invoice');

// --------------------------------------------------------------- functions

$suggested = VereineFunctionRules::suggestedAt();
same(array('obmann', 'obmann_stv', 'kassier', 'kassier_stv', 'schriftfuehrung', 'schriftfuehrung_stv', 'rechnungspruefung'), array_column($suggested, 'code'), 'functions suggested for Austria');
same(array(2, true, false), array($suggested[6]['min'], $suggested[6]['auditor'], $suggested[6]['board']), 'at least two auditors, not on the board');
same(array(), VereineFunctionRules::validate(array('code' => 'jugendleitung', 'label' => 'Jugendleitung', 'min' => '0', 'max' => '1')), 'a valid function');
same(array('VereineFunctionErrorCode', 'VereineFunctionErrorLabel', 'VereineFunctionErrorCount'), VereineFunctionRules::validate(array('code' => 'X', 'label' => '', 'min' => '3', 'max' => '2')),
	'code, label and a minimum above the maximum are refused');
same(array(), VereineFunctionRules::validate(array('code' => 'beirat', 'label' => 'Beirat', 'min' => '3', 'max' => '0')), 'a maximum of 0 means no limit');
same(array(array(), array('VereineFunctionErrorStart'), array('VereineFunctionErrorEnd')),
	array(VereineFunctionRules::validateTerm('2026-01-01', ''), VereineFunctionRules::validateTerm('', ''), VereineFunctionRules::validateTerm('2026-02-01', '2026-01-31')),
	'a term needs a first day and a last day not before it');
same(array(true, true, false, false), array(
	VereineFunctionRules::isActive(array('start' => '2026-01-01', 'end' => ''), '2026-09-17'),
	VereineFunctionRules::isActive(array('start' => '2026-01-01', 'end' => '2026-09-17'), '2026-09-17'),
	VereineFunctionRules::isActive(array('start' => '2026-01-01', 'end' => '2026-09-16'), '2026-09-17'),
	VereineFunctionRules::isActive(array('start' => '2026-09-18', 'end' => ''), '2026-09-17'),
), 'a term runs from its first to its last day');

$catalogue = array(
	array('id' => 1, 'code' => 'obmann', 'board' => true, 'auditor' => false, 'min' => 1, 'max' => 1),
	array('id' => 2, 'code' => 'kassier', 'board' => true, 'auditor' => false, 'min' => 1, 'max' => 1),
	array('id' => 3, 'code' => 'rechnungspruefung', 'board' => false, 'auditor' => true, 'min' => 2, 'max' => 0),
);
$check = VereineFunctionRules::check($catalogue, array(), '2026-09-17');
same(array('missing', 'missing', 'missing'), array_column($check['problems'], 'kind'), 'without terms every required function is missing');
$terms = array(
	array('function_id' => 1, 'member_id' => 10, 'start' => '2025-01-01', 'end' => ''),
	array('function_id' => 3, 'member_id' => 10, 'start' => '2025-01-01', 'end' => '2026-09-17'),
	array('function_id' => 3, 'member_id' => 11, 'start' => '2025-01-01', 'end' => ''),
	array('function_id' => 2, 'member_id' => 12, 'start' => '2026-10-01', 'end' => ''),
);
$check = VereineFunctionRules::check($catalogue, $terms, '2026-09-17');
same(array(1 => array(10), 2 => array(), 3 => array(10, 11)), $check['holders'], 'holders on the day: a future term does not count yet, a term ending today still does');
same(array(array('missing', 2), array('board_too_small', 0), array('auditor_on_board', 0)), array_map(function ($problem) {
	return array($problem['kind'], $problem['function_id']);
}, $check['problems']), 'missing treasurer, a board of one person and an auditor on the board');
$check = VereineFunctionRules::check($catalogue, $terms, '2026-10-01');
same(array(array('missing', 3)), array_map(function ($problem) {
	return array($problem['kind'], $problem['function_id']);
}, $check['problems']), 'on 1 October: treasurer on board, the ended auditor term leaves one auditor');
$catalogue[0]['term_years'] = 2;
$catalogue[2]['term_years'] = 0;
$check = VereineFunctionRules::check($catalogue, $terms, '2027-01-01');
same(array(array('election_due', 1, 2, array(10)), array('missing', 3, 1, array())), array_map(function ($problem) {
	return array($problem['kind'], $problem['function_id'], $problem['count'], $problem['members']);
}, $check['problems']), 'two years after the start the chair is due for election, a function without term never');
same(false, in_array('election_due', array_column(VereineFunctionRules::check($catalogue, $terms, '2026-12-31')['problems'], 'kind'), true),
	'the day before two years are over no election is due');

$groupFunctions = array(array('id' => 1, 'group_id' => 7), array('id' => 2, 'group_id' => 0), array('id' => 3, 'group_id' => 8));
$groupTerms = array(
	array('function_id' => 1, 'member_id' => 20, 'member_status' => 1, 'start' => '2026-01-01', 'end' => ''),
	array('function_id' => 3, 'member_id' => 21, 'member_status' => 1, 'start' => '2025-01-01', 'end' => '2026-06-30'),
	array('function_id' => 2, 'member_id' => 22, 'member_status' => 1, 'start' => '2026-01-01', 'end' => ''),
	array('function_id' => 1, 'member_id' => 23, 'member_status' => 0, 'start' => '2026-01-01', 'end' => ''),
);
same(array(
	array('action' => 'add', 'user_id' => 100, 'member_id' => 20, 'group_id' => 7),
	array('action' => 'remove', 'user_id' => 101, 'member_id' => 21, 'group_id' => 8),
	array('action' => 'remove', 'user_id' => 103, 'member_id' => 23, 'group_id' => 7),
), VereineFunctionRules::groupChanges($groupFunctions, $groupTerms, array(20 => 100, 21 => 101, 22 => 102, 23 => 103), array(101 => array(8, 5), 102 => array(5), 104 => array(7), 103 => array(7)), '2026-09-17'),
	'the treasurer joins the group, an ended term and a resigned member leave it; groups without function and users without member stay');
same(array(), VereineFunctionRules::groupChanges($groupFunctions, $groupTerms, array(20 => 100), array(100 => array(7)), '2026-09-17'), 'nothing to change when groups fit');
same(array(false, true, true, false, true), array(
	VereineFunctionRules::showName('consent', true, false),
	VereineFunctionRules::showName('consent', false, true),
	VereineFunctionRules::showName('disclosure', true, false),
	VereineFunctionRules::showName('disclosure', false, false),
	VereineFunctionRules::showName('disclosure', false, true),
), 'names on the website: with consent, and the board always when it must be disclosed');
same(array('2026-10-15', '2027-01-28'), array(VereineFunctionRules::reportDeadline('2026-09-17'), VereineFunctionRules::reportDeadline('2026-12-31')),
	'a new representative is reported within four weeks');
same(array('birth', 'birth_place', 'address'), VereineFunctionRules::missingForReport(array('birth' => '', 'birth_place' => ' ', 'address' => 'Hauptplatz 1', 'zip' => '', 'town' => 'Innsbruck')),
	'birth date, place of birth and a complete address are needed');
same(array(), VereineFunctionRules::missingForReport(array('birth' => '1980-05-05', 'birth_place' => 'Innsbruck', 'address' => 'Hauptplatz 1', 'zip' => '6020', 'town' => 'Innsbruck')),
	'nothing missing');

// ---------------------------------------------------------------- statutes

$model = VereineStatuteRules::defaults();
same(array(1, 14, 3, true, 0, 'two_thirds', 'two_thirds', 'none', 50, true), array($model['general_years'], $model['invite_days'], $model['motion_days'], $model['proxy'],
	$model['general_quorum'], $model['statute_majority'], $model['dissolution_majority'], $model['virtual'], $model['board_quorum'], $model['board_tie_chair']),
	'the defaults are the model statutes of the Ministry of the Interior');
$entered = array('min_age' => '18', 'voting_types' => array('3', '1', '3', 'x'), 'general_years' => '2', 'invite_days' => '14', 'invite_channels' => array('email', 'fax', 'letter'),
	'motion_days' => '3', 'proxy' => '', 'general_quorum' => '0', 'statute_majority' => 'three_quarters', 'dissolution_majority' => 'two_thirds', 'virtual' => 'hybrid',
	'board_quorum' => '50', 'board_tie_chair' => '1', 'unknown' => 'x');
$rules = VereineStatuteRules::normalize($entered);
same(array(18, array(1, 3), array('letter', 'email'), false, 'hybrid', true), array($rules['min_age'], $rules['voting_types'], $rules['invite_channels'], $rules['proxy'], $rules['virtual'], $rules['board_tie_chair']),
	'entered rules: numbers, member types once, known channels in order, unticked proxy');
same(false, isset($rules['unknown']), 'unknown keys are dropped');
same($model, VereineStatuteRules::normalize('not stored'), 'nothing stored gives the model statutes');
same(array(), VereineStatuteRules::validate(array_merge($entered, array('invite_channels' => array('email', 'letter')))), 'valid rules');
same(array('VereineStatuteErrorGeneralYears'), VereineStatuteRules::validate(array_merge($entered, array('invite_channels' => array('email'), 'general_years' => '6'))),
	'a general assembly less often than every five years is refused');
same(array('VereineStatuteErrorMinAge', 'VereineStatuteErrorMotionDays', 'VereineStatuteErrorChannels', 'VereineStatuteErrorQuorum', 'VereineStatuteErrorMajority', 'VereineStatuteErrorVirtual'),
	VereineStatuteRules::validate(array('min_age' => '-1', 'general_years' => '1', 'invite_days' => '7', 'motion_days' => '7', 'invite_channels' => array('fax'),
		'general_quorum' => '0', 'board_quorum' => '0', 'statute_majority' => 'most', 'dissolution_majority' => 'two_thirds', 'virtual' => 'zoom')),
	'bad age, motions not before the invitation, unknown channel, a board quorum of 0, unknown majority and kind of assembly');
same(array('VereineStatuteErrorDays'), VereineStatuteRules::validate(array_merge($entered, array('invite_channels' => array('email'), 'invite_days' => '0'))), 'an invitation needs at least one day');
same(array(true, true, false, false), array(VereineStatuteRules::isTermYears('4'), VereineStatuteRules::isTermYears('0'), VereineStatuteRules::isTermYears('21'), VereineStatuteRules::isTermYears('x')),
	'terms of office from 0 to 20 years');
same(array(array('kind' => 'term_missing', 'function_id' => 1), array('kind' => 'term_not_aligned', 'function_id' => 2)), VereineStatuteRules::hints(array('general_years' => 2), array(
	array('id' => 1, 'board' => true, 'auditor' => false, 'term_years' => 0),
	array('id' => 2, 'board' => false, 'auditor' => true, 'term_years' => 3),
	array('id' => 3, 'board' => true, 'auditor' => false, 'term_years' => 4),
	array('id' => 4, 'board' => false, 'auditor' => false, 'term_years' => 0),
)), 'a board function without term and a term ending between two assemblies; other functions need no term');
same(array(true, true, false, false), array(
	VereineStatuteRules::oldEnough('', 0, '2026-09-17'),
	VereineStatuteRules::oldEnough('2008-09-17', 18, '2026-09-17'),
	VereineStatuteRules::oldEnough('2008-09-18', 18, '2026-09-17'),
	VereineStatuteRules::oldEnough('', 18, '2026-09-17'),
), 'old enough on the 18th birthday, not the day before, and not with an unknown birth date');
same(array('2028-02-29', '2025-02-28', '2030-09-17'), array(VereineStatuteRules::addYears('2024-02-29', 4), VereineStatuteRules::addYears('2024-02-29', 1), VereineStatuteRules::addYears('2026-09-17', 4)),
	'years later, 29 February becomes 28 February');

// ---------------------------------------------------------------- mailings

$people = array(
	array('id' => 1, 'status' => 1, 'type_id' => 5, 'email' => 'chair@example.org', 'firstname' => 'Paula', 'lastname' => 'P', 'birth' => '1980-01-01',
		'functions' => array('obmann'), 'board' => true, 'consents' => array('newsletter'), 'guardians' => array()),
	array('id' => 2, 'status' => 1, 'type_id' => 6, 'email' => 'Kid@Example.org', 'firstname' => 'Jonas', 'lastname' => 'J', 'birth' => '2015-05-05',
		'functions' => array(), 'board' => false, 'consents' => array(), 'guardians' => array(array('contact_id' => 9, 'email' => 'parent@example.org', 'firstname' => 'Gerda', 'lastname' => 'J'))),
	array('id' => 3, 'status' => 0, 'type_id' => 5, 'email' => 'former@example.org', 'firstname' => 'Otto', 'lastname' => 'O', 'birth' => '',
		'functions' => array(), 'board' => false, 'consents' => array('newsletter'), 'guardians' => array()),
	array('id' => 4, 'status' => 1, 'type_id' => 5, 'email' => 'CHAIR@example.org', 'firstname' => 'Twin', 'lastname' => 'T', 'birth' => '1990-01-01',
		'functions' => array('jugendleitung'), 'board' => false, 'consents' => array(), 'guardians' => array()),
	array('id' => 5, 'status' => -1, 'type_id' => 5, 'email' => '', 'firstname' => 'Ohne', 'lastname' => 'Adresse', 'birth' => '',
		'functions' => array(), 'board' => false, 'consents' => array(), 'guardians' => array()),
);
$emails = function ($filter) use ($people) {
	return array_column(VereineMailingRules::recipients($people, $filter, '2026-09-17'), 'email');
};
same(array('chair@example.org', 'Kid@Example.org'), $emails(array()), 'active members by default, each address once regardless of case');
same(array('chair@example.org'), $emails(array('function' => 'board')), 'only the board');
same(array('CHAIR@example.org'), $emails(array('function' => 'jugendleitung', 'status' => 'all')), 'one function');
same(array('chair@example.org'), $emails(array('purpose' => 'newsletter')), 'newsletter only with consent and active');
same(array('chair@example.org', 'former@example.org'), $emails(array('purpose' => 'newsletter', 'status' => 'all')), 'all members with consent');
same(array('chair@example.org', 'parent@example.org'), $emails(array('guardians' => true)), 'a minor is reached through the guardian');
same(array(), $emails(array('status' => 'draft')), 'a member without address gets nothing');
same(array(true, false, false), array(VereineMailingRules::isMinor('2009-09-18', '2026-09-17'), VereineMailingRules::isMinor('2008-09-17', '2026-09-17'), VereineMailingRules::isMinor('', '2026-09-17')),
	'under 18 on the day, unknown birth date counts as adult');
same(array('status' => 'active', 'type_id' => 0, 'function' => '', 'purpose' => 'info', 'guardians' => false), VereineMailingRules::normalize(array('status' => 'x', 'function' => 'DROP', 'purpose' => '')),
	'an unusable filter is the default filter');

// ---------------------------------------------------------------- consents

same(array(true, false, false), array(VereineConsentRules::isCode('fotos_web'), VereineConsentRules::isCode('Fotos'), VereineConsentRules::isCode('1newsletter')), 'codes are lower case and start with a letter');
same(array(), VereineConsentRules::validateText(array('code' => 'newsletter', 'label' => 'Newsletter', 'text' => 'Ich möchte den Newsletter bekommen.')), 'a valid consent text');
same(array('VereineConsentErrorCode', 'VereineConsentErrorLabel', 'VereineConsentErrorText'), VereineConsentRules::validateText(array('code' => 'x', 'label' => '', 'text' => ' ')),
	'code, label and text are checked');
$events = array(
	array('id' => 1, 'code' => 'fotos', 'version' => 1, 'given' => true, 'date' => '2026-01-01 10:00:00'),
	array('id' => 3, 'code' => 'fotos', 'version' => 1, 'given' => false, 'date' => '2026-03-01 10:00:00'),
	array('id' => 2, 'code' => 'newsletter', 'version' => 2, 'given' => true, 'date' => '2026-02-01 10:00:00'),
);
$current = VereineConsentRules::current($events);
same(array('fotos' => false, 'newsletter' => true), array_map(function ($event) {
	return $event['given'];
}, $current), 'the latest event per purpose counts: photos withdrawn, newsletter given');

$types = array(5 => array('morphy' => ''), 6 => array('morphy' => 'mor'));
$texts = array('fotos' => 2, 'newsletter' => 1);
$valid = array('external_id' => 'web-42', 'firstname' => 'Anna', 'lastname' => 'Antrag', 'email' => 'anna@example.org', 'birth' => '2001-04-30',
	'country_code' => 'at', 'type_id' => '5', 'consents' => array(array('code' => 'fotos', 'version' => 2)));
$checked = VereineConsentRules::application($valid, $types, $texts);
same(array(), $checked['errors'], 'a complete application');
same(array('AT', 5, array('fotos' => 2), 'phy'), array($checked['application']['country_code'], $checked['application']['type_id'], $checked['application']['consents'], $checked['application']['morphy']),
	'the application is normalised');
$checked = VereineConsentRules::application(array('consents' => array(array('code' => 'fotos', 'version' => 1), array('code' => 'werbung', 'version' => 1))) + $valid, $types, $texts);
same(array('consent fotos was given to version 1, the current version is 2', 'consent werbung is no active consent text, see GET /vereine/consents'), $checked['errors'],
	'an outdated version and an unknown purpose are refused');
$checked = VereineConsentRules::application(array('email' => 'anna at example', 'lastname' => '', 'type_id' => 6, 'birth' => '2001-02-30', 'external_id' => 'web 42') + $valid, $types, $texts);
same(array('external_id may only contain letters, digits and . _ : -', 'firstname and lastname are required', 'email must be a valid e-mail address',
	'birth must be a date YYYY-MM-DD', 'the member type is not open to this kind of person (morphy)'), $checked['errors'], 'bad input is explained');
same(array('body' => array('type_id must be an active member type, see GET /vereine/membershipfees')),
	array('body' => array_values(array_filter(VereineConsentRules::application(array('type_id' => 99) + $valid, $types, $texts)['errors']))), 'an unknown member type is refused');
same(array(array(), array('the statutes admit members from 18 years of age'), array('birth is required: the statutes admit members from 18 years of age')), array(
	VereineConsentRules::application(array('birth' => '2008-09-17') + $valid, $types, $texts, 18, '2026-09-17')['errors'],
	VereineConsentRules::application(array('birth' => '2008-09-18') + $valid, $types, $texts, 18, '2026-09-17')['errors'],
	VereineConsentRules::application(array('birth' => '') + $valid, $types, $texts, 18, '2026-09-17')['errors'],
), 'the minimum age of the statutes: old enough on the birthday, younger and unknown birth dates refused');

// ------------------------------------------------------------------- openapi

// Every endpoint of the API class is in docs/openapi.json, and the description lists no other.
$openapi = json_decode((string) file_get_contents($root.'/docs/openapi.json'), true);
expect(is_array($openapi) && isset($openapi['paths']), 'docs/openapi.json is valid JSON with paths');
$described = array();
foreach (is_array($openapi) ? $openapi['paths'] : array() as $path => $item) {
	foreach ($item as $method => $operation) {
		$described[] = strtoupper($method).' '.$path;
		expect(isset($operation['responses']['200'], $operation['responses']['401'], $operation['responses']['403'], $operation['responses']['501']),
			'docs/openapi.json lists 200, 401, 403 and 501 for '.strtoupper($method).' '.$path);
	}
}
preg_match_all('/@url\s+(GET|POST|PUT|DELETE)\s+(\S+)/', (string) file_get_contents($root.'/class/api_vereine.class.php'), $matches, PREG_SET_ORDER);
$implemented = array();
foreach ($matches as $match) {
	$implemented[] = $match[1].' /vereine/'.$match[2];
}
sort($described);
sort($implemented);
same($implemented, $described, 'the endpoints in class/api_vereine.class.php and docs/openapi.json');

// ------------------------------------------------------------ language files

$english = langEntries($root.'/langs/en_US/vereine.lang');
$languages = glob($root.'/langs/*/vereine.lang');
expect(count($languages) >= 2, 'at least English and German language files exist');
foreach ($languages as $file) {
	$language = basename(dirname($file));
	$entries = langEntries($file);
	foreach (array_keys($entries) as $key) {
		expect(strpos($key, '!') !== 0, $language.': unreadable or duplicate entry "'.$key.'": '.(isset($entries[$key]) ? $entries[$key] : ''));
		if (strpos($key, '!') !== 0) {
			expect(isset($english[$key]), $language.': key '.$key.' is missing in en_US, which must be complete');
		}
	}
	foreach (array_keys($english) as $key) {
		expect(isset($entries[$key]), $language.': key '.$key.' of en_US is not translated');
	}
	foreach ($entries as $key => $value) {
		// Translate::trans() passes at most four parameters to sprintf(); a fifth placeholder stops the page.
		preg_match_all('/%(?:\d+\$)?[sd]/', str_replace('%%', '', $value), $placeholders);
		expect(count($placeholders[0]) <= 4, $language.': '.$key.' has more than four placeholders, Translate::trans() passes only four');
		// Dolibarr passes every translation through sprintf(): a lone % stops the page with a ValueError.
		expect(preg_match('/%(?!%|(?:\d+\$)?[sd])/', str_replace('%%', '', $value)) === 0, $language.': '.$key.' has a lone %, write %% for a percent sign');
		if (isset($english[$key]) && strpos($key, '!') !== 0) {
			preg_match_all('/%(?:\d+\$)?[sd]/', $english[$key], $left);
			preg_match_all('/%(?:\d+\$)?[sd]/', $value, $right);
			same($left[0], $right[0], $language.': placeholders of '.$key);
		}
	}
}

// Every language key the PHP code names literally exists in English. Keys built
// from a prefix and a profile code are listed with every possible ending.
$used = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
	$path = str_replace('\\', '/', $file->getPathname());
	if (substr($path, -4) !== '.php' || preg_match('#/(tests|scripts|\.local-testing|\.git|dist)/#', $path)) {
		continue;
	}
	preg_match_all("/'((?:Vereine|ModuleVereine|Permission492100)[A-Za-z0-9_]*)'/", (string) file_get_contents($path), $matches);
	foreach ($matches[1] as $key) {
		$used[$key] = true;
	}
}
$sections = array('without_partner', 'attributes', 'differences', 'orphans', 'minors', 'duplicates');
$operations = array('create', 'attributes', 'copy', 'orphans');
$prefixes = array(
	'VereineProfile' => VereineProfile::codes(),
	'VereineRegisterNumber' => array('ZVR', 'VR'),
	'VereineRegisterNumberHelp' => array('ZVR', 'VR'),
	'VereinePartnerSection_' => $sections,
	'VereinePartnerSectionHelp_' => $sections,
	'VereinePartnerPreviewButton_' => $operations,
	'VereinePartnerPreview' => array('', 'Create', 'Attributes', 'Copy', 'Orphans'),
	'VereinePartnerMatch_' => array(VereinePartnerRules::MATCH_EMAIL, VereinePartnerRules::MATCH_NAME_ZIP),
	'VereineField_' => array('email', 'address', 'zip', 'town'),
	'VereineLog_' => array('partner_created', 'partner_linked', 'partner_suggested', 'partner_attributes', 'partner_updated', 'partner_error', 'partner_unlinked', 'fee_invoice', 'fee_run', 'fee_error', 'fee_period', 'fee_direct_debit', 'exit_planned', 'exit_done', 'exit_cancelled', 'exit_error', 'consent_given', 'consent_withdrawn', 'application_received', 'function_start', 'function_end', 'function_reported', 'function_report_pdf', 'function_group_add', 'function_group_remove', 'statute_rules'),
	'VereineGroupsChange_' => array('add', 'remove'),
	'VereineMailingStatus_' => VereineMailingRules::STATUSES,
	'VereineReportMissing_' => array('birth', 'birth_place', 'address'),
	'VereineFunctionProblem_' => array('missing', 'too_many', 'board_too_small', 'auditor_on_board', 'election_due'),
	'VereineStatuteChannel_' => VereineStatuteRules::CHANNELS,
	'VereineStatuteMajority_' => VereineStatuteRules::MAJORITIES,
	'VereineStatuteVirtual_' => VereineStatuteRules::VIRTUALS,
	'VereineStatuteHint_' => array(VereineStatuteRules::HINT_TERM_MISSING, VereineStatuteRules::HINT_TERM_NOT_ALIGNED),
	'VereineConsentSource_' => VereineConsentRules::SOURCES,
	'VereineConsentState_' => array('none', 'given', 'withdrawn'),
	'VereineSetting_' => array('VEREINE_PARTNER_AUTOCREATE', 'VEREINE_PARTNER_CATEGORIES', 'VEREINE_PARTNER_CATEGORY_PER_TYPE', 'VEREINE_PARTNER_TYPENT_NATURAL', 'VEREINE_PARTNER_TYPENT_LEGAL', 'VEREINE_CATEGORY_MEMBER', 'VEREINE_CATEGORY_FORMER', 'VEREINE_CATEGORY_GUARDIAN'),
	'VereineSettingHelp_' => array('VEREINE_PARTNER_AUTOCREATE', 'VEREINE_PARTNER_CATEGORIES', 'VEREINE_PARTNER_CATEGORY_PER_TYPE', 'VEREINE_PARTNER_TYPENT'),
	'VereineSphere_' => array_keys(VereineTaxRules::spheres()),
	'VereineTreatment_' => array_keys(VereineTaxRules::treatments()),
	'VereineSphereHelp_' => array_keys(VereineTaxRules::spheres()),
	'VereinePdfRegister_' => array('ZVR', 'VR'),
	'VereineThreshold_' => array('small_business', 'harmful_business', 'cash_register', 'festival_hours'),
	'VereineThresholdHelp_' => array('cash_register', 'festival_hours'),
	'VereineThresholdStatus_' => array('ok', 'near', 'tolerance', 'exceeded'),
	'VereineThresholdText_' => array('ok', 'near'),
	'VereineThresholdText_exceeded_' => array('small_business', 'harmful_business'),
	'VereineCashStatus_' => array('not_relevant', 'exempt', 'exempt_festival', 'ok', 'near', 'required'),
	'VereineCashText_' => array('not_relevant', 'exempt', 'exempt_festival', 'ok', 'near', 'required'),
	'VereineTreatmentHelp_' => array_keys(VereineTaxRules::treatments()),
	'VereineFeeRunStatus_' => array('ready', 'no_partner', 'no_payer', 'no_amount', 'no_start'),
	'VereineFeeRunSkip_' => array('earlier_period', 'no_partner', 'no_payer', 'no_amount', 'no_start'),
	'VereineFamilyMode_' => array('none', 'percent', 'cap'),
	'VereineExitReason_' => VereineExitRules::REASONS,
	'VereineExitAt_' => VereineExitRules::ATS,
	'VereineExitRuleText_' => VereineExitRules::ATS,
	'VereineExitPast_' => array('done', 'cancelled'),
	'VereineInvoiceStatus_' => array('draft', 'open', 'overdue', 'paid', 'abandoned'),
	'VereineFeeReasonProrated_' => array('month', 'quarter', 'half_year'),
	'VereineFeeReasonFirstPartFull_' => array('month', 'quarter', 'half_year'),
	'VereineDiscountKind_' => array('age', 'proof'),
	'VereineDiscountMode_' => array('percent', 'amount', 'free'),
);
foreach (array_keys($used) as $key) {
	if (isset($prefixes[$key])) {
		foreach ($prefixes[$key] as $ending) {
			expect(isset($english[$key.$ending]), 'language key '.$key.$ending.' is used by the code but missing in en_US');
		}
		continue;
	}
	// Class names in the descriptor, such as the scheduled job's object, are no language keys.
	if (is_file($root.'/class/'.strtolower($key).'.class.php')) {
		continue;
	}
	if (in_array($key, array('ModuleVereineDesc', 'ModuleVereineDescLong'), true) || isset($english[$key])) {
		expect(isset($english[$key]), 'language key '.$key.' is missing in en_US');
		continue;
	}
	expect(false, 'language key '.$key.' is used by the code but missing in en_US');
}
expect(isset($english['ModuleVereineName']), 'the module name has a translation');
expect(isset($english['Permission49210001']), 'the read permission has a translation');

// ------------------------------------------------------------------- result

if ($failures) {
	fwrite(STDERR, count($failures)." of ".$assertions." assertions failed:\n  - ".implode("\n  - ", $failures)."\n");
	exit(1);
}
print 'Unit tests: OK ('.$assertions." assertions)\n";
exit(0);
