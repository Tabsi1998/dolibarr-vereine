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
require_once $root.'/class/vereineassociationrules.class.php';
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
require_once $root.'/class/vereineauthorityrules.class.php';
require_once $root.'/class/vereinestatutetext.class.php';
require_once $root.'/class/vereinemeetingrules.class.php';
require_once $root.'/class/vereineminutesrules.class.php';
require_once $root.'/class/vereinesignaturerules.class.php';
require_once $root.'/class/vereineqes.class.php';
require_once $root.'/class/vereinemailtemplates.class.php';
require_once $root.'/class/vereinetaxcheckrules.class.php';
require_once $root.'/class/vereineauditrules.class.php';
require_once $root.'/class/vereinetextrepair.class.php';
require_once $root.'/class/vereineattendancerules.class.php';
require_once $root.'/class/vereinevoterules.class.php';
require_once $root.'/class/vereineresolutionrules.class.php';
require_once $root.'/class/vereinecircularrules.class.php';
require_once $root.'/class/vereinemeetingdocrules.class.php';
require_once $root.'/class/vereinemailrules.class.php';
require_once $root.'/class/vereineapirules.class.php';

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

// --------------------------------------------------------- association rules

same('AT', VereineAssociationRules::COUNTRY, 'the module serves Austrian associations');
same('123456789', VereineAssociationRules::normalizeZvr(' 123 456.789 '), 'ZVR spaces and dots removed');
same('', VereineAssociationRules::normalizeZvr('   '), 'blank ZVR number becomes empty');

same('', VereineAssociationRules::validateZvr(''), 'an empty ZVR number is allowed');
same('', VereineAssociationRules::validateZvr('1234567890'), 'ten digits are a ZVR number');
same('VereineErrorZvrFormat', VereineAssociationRules::validateZvr('12345678901'), 'eleven digits are not');
same('VereineErrorZvrFormat', VereineAssociationRules::validateZvr('ZVR 123'), 'letters are not');
same('VereineErrorZvrFormat', VereineAssociationRules::validateZvr('VR 12345'), 'a German register number is not a ZVR number');

same('', VereineAssociationRules::validatePurpose(str_repeat('ä', VereineAssociationRules::PURPOSE_MAX_LENGTH)), 'purpose at the limit counts characters, not bytes');
same('VereineErrorPurposeTooLong', VereineAssociationRules::validatePurpose(str_repeat('a', VereineAssociationRules::PURPOSE_MAX_LENGTH + 1)), 'purpose over the limit');

$today = gmmktime(12, 0, 0, 9, 16, 2026);
same('', VereineAssociationRules::validateFoundingDate(0, 0, 0, $today), 'no founding date is allowed');
same('', VereineAssociationRules::validateFoundingDate(2019, 3, 1, $today), 'a past founding date');
same('', VereineAssociationRules::validateFoundingDate(2026, 9, 16, $today), 'founded today');
same('VereineErrorFoundingDateFuture', VereineAssociationRules::validateFoundingDate(2026, 9, 17, $today), 'founded tomorrow');
same('VereineErrorFoundingDate', VereineAssociationRules::validateFoundingDate(2023, 2, 29, $today), '29 February 2023 does not exist');
same('VereineErrorFoundingDate', VereineAssociationRules::validateFoundingDate(2020, 5, 0, $today), 'a date without its day');
same('VereineErrorFoundingDate', VereineAssociationRules::validateFoundingDate(1700, 1, 1, $today), 'a founding date before 1800');

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
	'VEREINE_REGISTER_NUMBER' => '123456789',
	'VEREINE_AUTHORITY' => 'Landespolizeidirektion Tirol',
	'VEREINE_FOUNDED' => '2019-03-01',
	'VEREINE_NONPROFIT' => '1',
	'VEREINE_PURPOSE' => 'Förderung des E-Sports',
);

$organization = VereineOrganization::build($settings, $company);
same('AT', $organization['country_profile'], 'the deprecated country profile is always AT');
same(true, $organization['country_profile_complete'], 'the deprecated completeness is always true');
same(array('kind' => 'ZVR', 'number' => '123456789', 'court' => ''), $organization['register'], 'the register is the ZVR, the deprecated court stays empty');
same('Landespolizeidirektion Tirol', $organization['authority'], 'authority for Austria');
same(true, $organization['nonprofit'], 'non-profit flag');
same(1, $organization['fiscal_year_start_month'], 'an unset fiscal month means January');
same(
	array('country_profile', 'country_profile_complete', 'name', 'register', 'authority', 'address', 'email', 'phone', 'url', 'founded', 'nonprofit', 'purpose', 'fiscal_year_start_month'),
	array_keys($organization),
	'API version 1 fields and their order'
);

$july = VereineOrganization::build($settings, array_merge($company, array('fiscal_month_start' => '7')));
same(7, $july['fiscal_year_start_month'], 'fiscal year starts in July');

$fallback = VereineOrganization::build(array('VEREINE_COUNTRY_PROFILE' => 'DE', 'VEREINE_REGISTER_COURT' => 'Amtsgericht'), array('country_code' => 'DE'));
same(array('AT', ''), array($fallback['country_profile'], $fallback['register']['court']), 'old settings of a German profile are ignored');
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
	array('company_name' => 'ok', 'country' => 'ok', 'register' => 'ok', 'api' => 'ok'),
	$statusOf(VereineOrganization::checks($organization, true)),
	'a complete Austrian association passes every check'
);
$incomplete = VereineOrganization::build(array(), array_merge($company, array('town' => '', 'country_code' => 'DE')));
$checks = VereineOrganization::checks($incomplete, false);
same(
	array('company_name' => 'warning', 'country' => 'warning', 'register' => 'warning', 'api' => 'warning'),
	$statusOf($checks),
	'missing town, wrong country, no ZVR number and no API are reported'
);
foreach ($checks as $check) {
	if ($check['status'] === VereineOrganization::CHECK_OK) {
		same('', $check['fix'], 'a passed check '.$check['code'].' offers no fix');
	}
}
same('VereineCheckZvrMissing', $checks[2]['label'], 'the Austrian register check names the ZVR number');

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

same('2031-02-07', VereineFunctionRules::endOfTerm('2026-02-08', 5), 'a term of five years ends the day before the fifth anniversary');
same('2029-12-31', VereineFunctionRules::endOfTerm('2027-01-01', 3), 'a term over the turn of the year ends on 31 December');
same('', VereineFunctionRules::endOfTerm('2026-02-08', 0), 'without a term of office the end stays open');
same('', VereineFunctionRules::endOfTerm('08.02.2026', 5), 'a start that is no day gives no end');
same('2028-02-28', VereineFunctionRules::endOfTerm('2026-02-29', 2), 'a start on 29 February ends on the last day of February two years on');

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

// ------------------------------------------------------------ authority letters

same(array('Landespolizeidirektion Tirol', 'Landespolizeidirektion Kärnten', ''), array(VereineAuthorityRules::policeAuthorityFor(' Innsbruck '),
	VereineAuthorityRules::policeAuthorityFor('Klagenfurt am Wörthersee'), VereineAuthorityRules::policeAuthorityFor('Hall in Tirol')),
	'the Landespolizeidirektion is the authority only in its towns, elsewhere the district authority');
$suggestions = VereineAuthorityRules::suggestions();
same(array(9, "Gilmstraße 2\n6020 Innsbruck"), array(count($suggestions), $suggestions['bh_innsbruck']['address']), 'eight district authorities and the LPD of Tyrol to choose from');
same(array(), VereineAuthorityRules::validate('statutes', VereineAuthorityRules::normalize('statutes', array('date' => '2026-09-17'))), 'a change of the statutes with the day of the assembly');
same(array('VereineLetterErrorDate_statutes'), VereineAuthorityRules::validate('statutes', VereineAuthorityRules::normalize('statutes', array())), 'without the day of the assembly');
same(array('VereineLetterErrorKind'), VereineAuthorityRules::validate('representatives', array('date' => '2026-09-17')), 'representatives are written under Board and functions');
same(array(), VereineAuthorityRules::validate('extract', VereineAuthorityRules::normalize('extract', array('extract' => 'full'))), 'an extract of today needs no day');
same(array('VereineLetterErrorDate_extract'), VereineAuthorityRules::validate('extract', VereineAuthorityRules::normalize('extract', array('extract' => 'at'))), 'an extract of an earlier day needs it');
same('current', VereineAuthorityRules::normalize('extract', array('extract' => 'all'))['extract'], 'an unknown extract is the current one');
same(array('VereineLetterErrorLiquidator'), VereineAuthorityRules::validate('dissolution', VereineAuthorityRules::normalize('dissolution',
	array('date' => '2026-09-17', 'effective' => 'mit sofortiger Wirkung', 'assets' => '1', 'liquidator_name' => 'Anna Abwicklerin'))), 'assets need a liquidator with every detail');
$dissolution = VereineAuthorityRules::normalize('dissolution', array('date' => '2026-09-17', 'effective' => 'mit sofortiger Wirkung', 'liquidator_name' => 'ignored'));
same(array(array(), false, ''), array(VereineAuthorityRules::validate('dissolution', $dissolution), $dissolution['assets'], $dissolution['liquidator_name']), 'without assets no liquidator is kept');
same(array('VereineLetterErrorAddress'), VereineAuthorityRules::validate('address', VereineAuthorityRules::normalize('address', array('date' => '2026-09-17', 'address' => ' '))), 'a new address needs the address');
same(array('VereineLetterErrorDate_extension', 'VereineLetterErrorReason'), VereineAuthorityRules::validate('extension', VereineAuthorityRules::normalize('extension', array())), 'a longer deadline needs the day and a reason');
same(array(), VereineAuthorityRules::validate('founding', VereineAuthorityRules::normalize('founding', array('founders' => '1'))), 'a founding needs no day');
same(array('2026-10-15', '', ''), array(VereineAuthorityRules::deadline('statutes', '2026-09-17'), VereineAuthorityRules::deadline('extract', '2026-09-17'), VereineAuthorityRules::deadline('dissolution', '')),
	'four weeks for notices, none for applications');

// ------------------------------------------------------------ statute text

$statuteText = VereineStatuteText::normalize(array('activities' => "Turniere\n\n Training \nTurniere", 'funds' => array('Mitgliedsbeiträge', ''), 'tax' => 'bao', 'asset' => 'x',
	'arrears_months' => '3', 'branches' => '1', 'unknown' => 'x'));
same(array(array('Turniere', 'Training'), array('Mitgliedsbeiträge'), 'bao', 'base', 3, true, false), array($statuteText['activities'], $statuteText['funds'], $statuteText['tax'],
	$statuteText['asset'], $statuteText['arrears_months'], $statuteText['branches'], isset($statuteText['unknown'])), 'lists one entry per line, once; an unknown wording is the first of its kind');
same(VereineStatuteText::defaults(), VereineStatuteText::normalize(array('tax' => array('bao'))), 'a tax kind is a text');
same(array(1000, 500), array(mb_strlen(VereineStatuteText::normalize(array('asset_purpose' => str_repeat('Förderung ', 150)))['asset_purpose'], 'UTF-8'),
	mb_strlen(VereineStatuteText::normalize(array('asset_recipient' => str_repeat('Verein ', 100)))['asset_recipient'], 'UTF-8')), 'a purpose of the assets up to 1000 characters, a recipient up to 500');
same(array(), VereineStatuteText::validate(array('arrears_months' => '6', 'tax' => 'donation', 'asset' => '3')), 'valid text fields');
same(array('VereineStatuteTextErrorArrears', 'VereineStatuteTextErrorAsset'), VereineStatuteText::validate(array('arrears_months' => '0', 'tax' => 'none', 'asset' => 'a')),
	'no exclusion without months, and a tax wording without tax privilege');
$statuteContext = array('name' => 'Musterverein', 'seat' => 'Innsbruck', 'purpose' => 'die Förderung des Schachsports', 'nonprofit' => true,
	'board' => array('Obmann/Obfrau', 'Kassier:in', 'Schriftführer:in'), 'board_terms' => array(4, 4, 4), 'chair' => 'Obmann/Obfrau', 'secretary' => 'Schriftführer:in',
	'treasurer' => 'Kassier:in', 'auditors' => 2, 'auditor_term' => 2, 'types' => array('Ordentlich', 'Ehrenmitglied'), 'voting' => array('Ordentlich'), 'honorary' => true,
	'exit' => array('months' => 1, 'at' => 'month_end', 'start_month' => 1));
same(array('assets'), VereineStatuteText::problems(VereineStatuteText::normalize(array('activities' => 'Turniere', 'tax' => 'bao', 'asset' => 'b', 'asset_purpose' => 'Jugendsport')),
	$statuteContext), 'a recipient is missing for the chosen wording');
same(array('purpose', 'activities', 'board_terms_differ', 'auditors', 'nonprofit'), VereineStatuteText::problems(VereineStatuteText::defaults(),
	array('purpose' => '', 'board_terms' => array(4, 2), 'auditors' => 1, 'auditor_term' => 2) + $statuteContext),
	'missing purpose and activities, differing board terms, one auditor, non-profit without tax wording');
$statuteRules = VereineStatuteRules::normalize(array('min_age' => '18', 'general_years' => '2', 'invite_channels' => array('email', 'website'), 'virtual' => 'hybrid',
	'statute_majority' => 'two_thirds', 'dissolution_majority' => 'three_quarters', 'proxy' => ''));
$sections = VereineStatuteText::sections($statuteRules, VereineStatuteText::normalize(array('activities' => "Turniere\nTraining", 'tax' => 'bao', 'asset' => 'a',
	'asset_purpose' => 'Jugendsport')), $statuteContext);
$byTitle = array();
foreach ($sections as $section) {
	$byTitle[$section['title']] = implode("\n", $section['paragraphs']);
}
same(17, count($sections), 'sixteen sections of the model and § 17 on the assets of a tax-privileged association');
expect(strpos($byTitle['Mittel zur Erreichung des Vereinszwecks'], "Tätigkeiten sind:\na) Turniere\nb) Training") !== false, 'activities as a list');
expect(strpos($byTitle['Erwerb der Mitgliedschaft'], 'die das 18. Lebensjahr vollendet haben, sowie juristische Personen') !== false, 'minimum age in the admission');
expect(strpos($byTitle['Erwerb der Mitgliedschaft'], 'Ehrenmitglied') !== false, 'honorary members where a member type is one');
expect(strpos($byTitle['Beendigung der Mitgliedschaft'], 'nur zum Ende eines Monats erfolgen. Er muss dem Vorstand mindestens einen Monat vorher') !== false, 'exit rule in words');
$general = $byTitle['Generalversammlung'];
expect(strpos($general, 'alle zwei Jahre statt') !== false && strpos($general, 'mindestens zwei Wochen vor dem Termin per E-Mail an die vom Mitglied dem Verein bekanntgegebene E-Mail-Adresse oder durch Veröffentlichung auf der Website des Vereins einzuladen') !== false, 'interval, invitation period and channels');
expect(strpos($general, 'Übertragung des Stimmrechts') === false && strpos($general, 'Stimmberechtigt sind nur Mitglieder folgender Mitgliedsarten: Ordentlich.') !== false,
	'no proxy votes, voting member types');
expect(strpos($general, 'geändert werden soll, bedürfen jedoch einer Zweidrittelmehrheit') !== false && strpos($general, 'aufgelöst werden soll, bedürfen einer Dreiviertelmehrheit') !== false,
	'different majorities for changes and dissolution');
expect(strpos($general, 'zwischen physischer und virtueller Teilnahme wählen') !== false && strpos($general, 'technischen Voraussetzungen') !== false, 'hybrid assembly under the VirtGesG');
expect(strpos($byTitle['Vorstand'], 'aus drei Mitgliedern, und zwar aus: Obmann/Obfrau, Kassier:in und Schriftführer:in') !== false
	&& strpos($byTitle['Vorstand'], 'beträgt vier Jahre') !== false, 'board from the function catalogue with its term');
expect(strpos($byTitle['Rechnungsprüfer'], 'Zwei Rechnungsprüfer werden von der Generalversammlung auf die Dauer von zwei Jahren gewählt') !== false, 'auditors with their term');
expect(strpos($byTitle['Freiwillige Auflösung des Vereins'], 'Dreiviertelmehrheit') !== false && strpos($byTitle['Freiwillige Auflösung des Vereins'], 'binnen vier Wochen') !== false,
	'dissolution with its majority and the notice of the Ministry of Finance model');
expect(strpos(end($sections)['paragraphs'][0], 'für den Zweck „Jugendsport“ zu verwenden') !== false, 'the assets go to the entered purpose');
$plain = VereineStatuteText::sections(VereineStatuteRules::defaults(), VereineStatuteText::defaults(), array('purpose' => '', 'honorary' => false) + $statuteContext);
expect(count($plain) === 16 && strpos(end($plain)['paragraphs'][1], 'sonst Zwecken der Sozialhilfe') !== false, 'without tax privileges the model of the Ministry of the Interior');
expect(strpos(implode(' ', $plain[1]['paragraphs']), 'Zweck: __________') !== false && strpos(implode(' ', $plain[4]['paragraphs']), 'Ehrenmitglied') === false,
	'a blank for the missing purpose, no honorary members without such a type');
same(array('Der Austritt kann jederzeit erfolgen. Er muss dem Vorstand schriftlich mitgeteilt werden.', 'Der Austritt kann nur zum 31. Dezember jeden Jahres erfolgen.'),
	array(VereineStatuteText::exitSentence(array('months' => 0, 'at' => 'any_day', 'start_month' => 1)),
		substr(VereineStatuteText::exitSentence(array('months' => 0, 'at' => 'year_end', 'start_month' => 1)), 0, 61)), 'exit at any day and at the end of the calendar year');
same(array('zwei Wochen', 'eine Woche', '10 Tage', 'einen Tag', 'ein Jahr', 'sechs Monate', 'a, b und c', 'a oder b'), array(VereineStatuteText::days(14), VereineStatuteText::days(7),
	VereineStatuteText::days(10), VereineStatuteText::days(1), VereineStatuteText::years(1), VereineStatuteText::months(6), VereineStatuteText::join(array('a', 'b', 'c')),
	VereineStatuteText::join(array('a', 'b'), 'oder')), 'numbers and lists in words');
foreach (VereineStatuteText::ASSETS as $tax => $assets) {
	foreach ($assets as $asset) {
		$wording = VereineStatuteText::assetText(VereineStatuteText::normalize(array('tax' => $tax, 'asset' => $asset, 'asset_purpose' => 'ZZZ', 'asset_recipient' => 'XY')));
		expect($tax === 'none' || (strpos($wording, 'Passiva') !== false && (!in_array($tax.':'.$asset, VereineStatuteText::NEEDS_PURPOSE, true) || strpos($wording, '„ZZZ“') !== false)
			&& (!in_array($tax.':'.$asset, VereineStatuteText::NEEDS_RECIPIENT, true) || strpos($wording, '„XY“') !== false)), 'asset wording '.$tax.':'.$asset.' names what it needs');
	}
}

$changedText = VereineStatuteText::normalize(array('activities' => "Turniere\nTraining", 'tax' => 'bao', 'asset' => 'a', 'asset_purpose' => 'Jugendsport', 'arrears_months' => '3'));
$changes = VereineStatuteText::compare($sections, VereineStatuteText::sections($statuteRules, $changedText, $statuteContext));
same(array(array('Beendigung der Mitgliedschaft', 6, 6)), array_map(function ($change) {
	return array($change['title'], $change['old_number'], $change['new_number']);
}, $changes), 'only the section with the new exclusion period differs');
expect(strpos(implode(' ', $changes[0]['old']), 'länger als sechs Monate') !== false && strpos(implode(' ', $changes[0]['new']), 'länger als drei Monate') !== false,
	'old and new wording of the changed section');
same(array(), VereineStatuteText::compare($sections, $sections), 'the same statutes have no change');
$withoutTax = VereineStatuteText::compare($sections, VereineStatuteText::sections($statuteRules, VereineStatuteText::normalize(array('activities' => "Turniere\nTraining")), $statuteContext));
same(array(0, 17), array(end($withoutTax)['new_number'], end($withoutTax)['old_number']), 'a section that is gone comes last with the old number only');

// ---------------------------------------------------------------- meetings

$meetingRules = VereineStatuteRules::normalize(array('invite_days' => '14', 'motion_days' => '3', 'invite_channels' => array('email', 'letter'), 'virtual' => 'hybrid',
	'voting_types' => array('5')));
$meeting = VereineMeetingRules::normalize(array('kind' => 'general', 'title' => ' Generalversammlung ', 'day' => '2026-10-01', 'time' => '19:30', 'format' => 'hybrid',
	'place' => 'Vereinsheim', 'access' => 'Link folgt', 'agenda' => "Begrüßung\n\nWahlen\n"));
same(array('general', 'Generalversammlung', array('Begrüßung', 'Wahlen')), array($meeting['kind'], $meeting['title'], $meeting['agenda']), 'a meeting as entered, agenda one item per line');
same(array(), VereineMeetingRules::validate($meeting, $meetingRules), 'a hybrid general assembly the statutes allow');
same(array('VereineMeetingErrorFormat'), VereineMeetingRules::validate(array('format' => 'physical') + $meeting, $meetingRules), 'a general assembly in person when the statutes say hybrid');
same(array('VereineMeetingErrorMoment', 'VereineMeetingErrorAccess', 'VereineMeetingErrorAgenda'), VereineMeetingRules::validate(VereineMeetingRules::normalize(array('kind' => 'board',
	'title' => 'Vorstand', 'day' => '2026-02-30', 'time' => '19:00', 'format' => 'virtual')), $meetingRules), 'a board meeting on no real day, virtual without access and without agenda');
same(array(true, false, true), array(VereineMeetingRules::formatAllowed('board', 'physical', $meetingRules), VereineMeetingRules::formatAllowed('extraordinary', 'virtual', $meetingRules),
	VereineMeetingRules::formatAllowed('general', 'physical', VereineStatuteRules::defaults())), 'formats: any for the board, the statutes decide for general assemblies');
same(array('2026-09-17', '2026-09-28', '', ''), array(VereineMeetingRules::inviteBy($meeting, $meetingRules), VereineMeetingRules::motionsBy($meeting, $meetingRules),
	VereineMeetingRules::inviteBy(array('kind' => 'board') + $meeting, $meetingRules), VereineMeetingRules::motionsBy($meeting, array('motion_days' => 0) + $meetingRules)),
	'invite 14 days and motions 3 days before a general assembly; no period for the board, none for motions until the start');
$people = array(
	array('id' => 1, 'status' => 1, 'type_id' => 5, 'email' => 'chair@example.org', 'name' => 'Paula', 'board' => true),
	array('id' => 2, 'status' => 1, 'type_id' => 6, 'email' => '', 'name' => 'Otto', 'board' => false),
	array('id' => 3, 'status' => 0, 'type_id' => 5, 'email' => 'former@example.org', 'name' => 'Frieda', 'board' => true),
	array('id' => 4, 'status' => 1, 'type_id' => 5, 'email' => 'not an address', 'name' => 'Karl', 'board' => true),
	array('id' => 5, 'status' => 1, 'type_id' => 6, 'email' => 'nina@example.org', 'name' => 'Nina', 'board' => false),
);
same(array(array(1, 'email', true), array(4, 'letter', true)), array_map(function ($recipient) {
	return array($recipient['member_id'], $recipient['channel'], $recipient['voting']);
}, VereineMeetingRules::recipients('board', $people, $meetingRules)), 'a board meeting reaches the active board and nobody else; an invalid address gets a letter');
same(array(array(1, 'email', true), array(2, 'letter', false), array(4, 'letter', true), array(5, 'email', false)), array_map(function ($recipient) {
	return array($recipient['member_id'], $recipient['channel'], $recipient['voting']);
}, VereineMeetingRules::recipients('general', $people, $meetingRules)), 'a general assembly reaches every active member; voting by member type');
same(array('letter', 'letter'), array_column(VereineMeetingRules::recipients('general', array($people[0], $people[4]), array('invite_channels' => array('letter')) + $meetingRules), 'channel'),
	'statutes without e-mail invite everyone by letter');
same(array('', 'statutes', 'law', ''), array(VereineMeetingRules::generalOverdue('2025-10-01', '2026-09-17', $meetingRules),
	VereineMeetingRules::generalOverdue('2025-09-01', '2026-09-17', $meetingRules), VereineMeetingRules::generalOverdue('2021-09-01', '2026-09-17', array('general_years' => 5) + $meetingRules),
	VereineMeetingRules::generalOverdue('', '2026-09-17', $meetingRules)), 'the next general assembly under the statutes and at the latest after five years');

// -------------------------------------------------------------- attendance

$proxyRules = array('proxy' => true, 'general_quorum' => 50) + $meetingRules;
$voting = array(1 => true, 2 => true, 3 => true, 4 => true, 5 => false);
$rows = VereineAttendanceRules::normalize(array(
	1 => array('state' => 'present', 'arrived' => '18:30', 'left' => '20:00'),
	2 => array('state' => 'represented', 'holder' => '1'),
	3 => array('state' => 'present', 'holder' => '9', 'arrived' => 'x'),
	5 => array('state' => 'nonsense'),
), array(1, 2, 3, 4, 5));
same(array('present', 'represented', 'present', 'absent', 'absent'), array_column($rows, 'state'), 'every invited member once, unknown states absent');
same(array(1, 0, ''), array($rows[2]['holder'], $rows[3]['holder'], $rows[3]['arrived']), 'a holder only for a proxy, times only when valid');
same(array(), VereineAttendanceRules::validate('general', $rows, $voting, $proxyRules), 'a proxy to a present voting member');
same(array(2 => array('VereineAttendanceErrorProxyStatutes')), VereineAttendanceRules::validate('general', $rows, $voting, array('proxy' => false) + $proxyRules), 'no proxy without the statutes');
same(array(2 => array('VereineAttendanceErrorProxyBoard')), VereineAttendanceRules::validate('board', $rows, $voting, $proxyRules), 'no proxy on the board');
same(array(5 => array('VereineAttendanceErrorProxyHolder'), 4 => array('VereineAttendanceErrorProxyAbsent')), VereineAttendanceRules::validate('general', array(
	5 => array('state' => 'represented', 'holder' => 1, 'arrived' => '', 'left' => ''), 4 => array('state' => 'represented', 'holder' => 2, 'arrived' => '', 'left' => ''),
) + $rows, $voting, $proxyRules), 'a member without vote cannot give a proxy, and the holder has to be present');
same(array(1 => array('VereineAttendanceErrorTimes')), VereineAttendanceRules::validate('general', array(1 => array('left' => '18:00') + $rows[1]) + $rows, $voting, $proxyRules),
	'leaving before arriving');
$quorum = VereineAttendanceRules::quorum('general', $rows, $voting, $proxyRules, '19:00');
same(array(4, 2, 1, 3, 2, true), array($quorum['eligible'], $quorum['present'], $quorum['represented'], $quorum['votes'], $quorum['required'], $quorum['reached']),
	'at 19:00 two present and one represented of four voting members, half needed');
$quorum = VereineAttendanceRules::quorum('general', $rows, $voting, $proxyRules, '20:30');
same(array(1, 0, 1, false), array($quorum['present'], $quorum['represented'], $quorum['votes'], $quorum['reached']), 'after the holder left, the proxy counts no more and the quorum is gone');
same(true, VereineAttendanceRules::quorum('general', $rows, $voting, array('general_quorum' => 0) + $proxyRules, '20:30')['reached'], 'regardless of the number present');
$board = VereineAttendanceRules::quorum('board', $rows, $voting, $proxyRules, '19:00');
same(array(2, 0, 2, true), array($board['present'], $board['represented'], $board['required'], $board['reached']), 'the board counts only who is present, half of four');

// ------------------------------------------------------------------- votes

$voteRules = VereineStatuteRules::normalize(array('statute_majority' => 'two_thirds', 'dissolution_majority' => 'three_quarters', 'board_tie_chair' => '1'));
$vote = VereineVoteRules::normalize(array('kind' => 'statutes', 'item' => '2', 'title' => 'Statutenänderung', 'yes' => '4', 'no' => '2', 'abstain' => '', 'time' => '19:00'));
same(array('statutes', 2, 4, 2, 0, '19:00', 0), array($vote['kind'], $vote['item'], $vote['yes'], $vote['no'], $vote['abstain'], $vote['time'], $vote['function_id']),
	'a vote as entered, no abstentions when left empty');
same(array(), VereineVoteRules::validate('general', $vote, 6, 3), 'six votes of six present on item 2 of 3');
same(array('VereineVoteErrorItem', 'VereineVoteErrorBoardKind', 'VereineVoteErrorTooMany'), VereineVoteRules::validate('board', array('item' => 4) + $vote, 5, 3),
	'an item not on the agenda, a change of statutes on the board and more votes than present');
same(array('VereineVoteErrorElection'), VereineVoteRules::validate('general', VereineVoteRules::normalize(array('kind' => 'election', 'item' => '1', 'title' => 'Kassier', 'yes' => '3', 'no' => '0')), 3, 1),
	'an election needs function and candidate');
same(array('two_thirds', 'three_quarters', 'simple'), array(VereineVoteRules::majority('statutes', $voteRules), VereineVoteRules::majority('dissolution', $voteRules),
	VereineVoteRules::majority('election', $voteRules)), 'majorities from the statutes');
same(array(true, false, false, true), array(VereineVoteRules::result($vote, 'two_thirds', 'general', $voteRules)['passed'],
	VereineVoteRules::result(array('yes' => 3, 'no' => 2) + $vote, 'two_thirds', 'general', $voteRules)['passed'],
	VereineVoteRules::result(array('yes' => 2, 'no' => 1) + $vote, 'three_quarters', 'general', $voteRules)['passed'],
	VereineVoteRules::result(array('yes' => 3, 'no' => 1) + $vote, 'three_quarters', 'general', $voteRules)['passed']), 'two thirds and three quarters of the valid votes cast, abstentions not cast');
same(array(array('passed' => true, 'tie' => true, 'decided_by_chair' => true), array('passed' => false, 'tie' => true, 'decided_by_chair' => false),
	array('passed' => false, 'tie' => true, 'decided_by_chair' => false)), array(
	VereineVoteRules::result(array('yes' => 2, 'no' => 2, 'tie' => 'yes') + $vote, 'simple', 'board', $voteRules),
	VereineVoteRules::result(array('yes' => 2, 'no' => 2, 'tie' => 'yes') + $vote, 'simple', 'general', $voteRules),
	VereineVoteRules::result(array('yes' => 2, 'no' => 2, 'tie' => 'yes') + $vote, 'simple', 'board', array('board_tie_chair' => false) + $voteRules),
), 'a tie: the chair decides on the board where the statutes say so, never in the general assembly');

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

// ------------------------------------------------- who presided and kept minutes

$roleHolders = array('obmann' => array(array('member_id' => 3, 'name' => 'Paula')), 'schriftfuehrung' => array(array('member_id' => 4, 'name' => 'Sam')));
same(array('chair' => 3, 'keeper' => 4, 'suggested' => true), VereineMinutesRules::roles(array('chair' => 0, 'keeper' => 0), $roleHolders, array(3, 4, 5)),
	'without an entry the chair and the secretary are suggested');
same(array('chair' => 5, 'keeper' => 4, 'suggested' => false), VereineMinutesRules::roles(array('chair' => 5, 'keeper' => 4), $roleHolders, array(3, 4, 5)),
	'what was entered wins, nothing is suggested then');
same(array('chair' => 3, 'keeper' => 0, 'suggested' => true), VereineMinutesRules::roles(array('chair' => 0, 'keeper' => 9), $roleHolders, array(3, 5)),
	'somebody who was not invited is dropped, and the secretary was not in the room either');
same(array('chair' => 0, 'keeper' => 0, 'suggested' => false), VereineMinutesRules::roles(array(), array(), array()),
	'without functions and without an entry nobody presides');
same(array('chair' => 3, 'keeper' => 4, 'suggested' => true), VereineMinutesRules::roles(array(), $roleHolders, array()),
	'without an attendance list the suggestion still works');

// ----------------------------------------------------------- signature rules

$codes = array('obmann', 'schriftfuehrung', 'kassier', 'rechnungspruefung');
$signatureRules = VereineSignatureRules::normalize(null, $codes);
same(array('obmann', 'schriftfuehrung'), $signatureRules['letter']['roles'], 'letters are signed by the chair and the secretary, as in the model statutes');
same(array('rechnungspruefung'), $signatureRules['audit_report']['roles'], 'the auditors sign their own report');
same(array(), VereineSignatureRules::normalize(null, array('obmann'))['audit_report']['roles'], 'a function the catalogue lacks is left out');
expect(VereineSignatureRules::wanted($signatureRules, 'letter') && !VereineSignatureRules::wanted(VereineSignatureRules::normalize(array('letter' => array('roles' => array())), $codes), 'letter'),
	'a kind without a function is switched off');
$own = VereineSignatureRules::normalize(array('letter' => array('roles' => array('obmann', 'kassier', 'obmann', 'unknown'), 'mode' => 'min', 'min' => 5)), $codes);
same(array(array('obmann', 'kassier'), 'min', 2), array($own['letter']['roles'], $own['letter']['mode'], $own['letter']['min']), 'doubles and unknown functions drop out, min never exceeds the functions');
same(array(), VereineSignatureRules::validate($own), 'own rules are fine');
same(array('VereineSignatureErrorMinWithoutRole'), VereineSignatureRules::validate(VereineSignatureRules::normalize(array('letter' => array('roles' => array(), 'mode' => 'min', 'min' => 1)), $codes)), 'min without a function is refused');

$holders = array('obmann' => array(array('member_id' => 7, 'name' => 'Paula Beispiel')),
	'schriftfuehrung' => array(array('member_id' => 7, 'name' => 'Paula Beispiel')),
	'rechnungspruefung' => array(array('member_id' => 8, 'name' => 'Rafael Beispiel'), array('member_id' => 9, 'name' => 'Marco Beispiel')));
$labels = array('obmann' => 'Obmann/Obfrau', 'schriftfuehrung' => 'Schriftführer:in', 'rechnungspruefung' => 'Rechnungsprüfer:in');
$signers = VereineSignatureRules::signers($signatureRules, 'letter', $holders, $labels);
same(array(1, 'obmann', 7), array(count($signers['people']), $signers['people'][0]['role'], $signers['people'][0]['member_id']), 'somebody holding both functions signs once');
same(array(), $signers['vacant'], 'no function is vacant');
$audit = VereineSignatureRules::signers($signatureRules, 'audit_report', $holders, $labels);
same(array(8, 9), array_column($audit['people'], 'member_id'), 'both auditors sign');
$vacant = VereineSignatureRules::signers($signatureRules, 'letter', array('obmann' => array(array('member_id' => 7, 'name' => 'Paula'))), $labels);
same(array('Schriftführer:in'), $vacant['vacant'], 'a function nobody holds is reported');

same(2, VereineSignatureRules::needed($signatureRules, 'audit_report', 2), 'all of them sign');
same(2, VereineSignatureRules::needed($own, 'letter', 3), 'min takes the number entered');
same(1, VereineSignatureRules::needed($own, 'letter', 1), 'min never asks for more people than there are');
same(0, VereineSignatureRules::needed($signatureRules, 'letter', 0), 'nobody to sign needs no signature');
expect(!VereineSignatureRules::complete($signatureRules, 'audit_report', 2, 1) && VereineSignatureRules::complete($signatureRules, 'audit_report', 2, 2),
	'a document is complete with every needed signature');
expect(!VereineSignatureRules::complete($signatureRules, 'letter', 0, 0), 'without signers a document never counts as signed');

// -------------------------------------------------------- register of resolutions

same('organe', VereineResolutionRules::category('election'), 'an election belongs to the bodies of the association');
same('statuten', VereineResolutionRules::category('statutes'), 'a change of the statutes belongs to the statutes');
same('statuten', VereineResolutionRules::category('dissolution'), 'the dissolution belongs to the statutes too');
same('sonstiges', VereineResolutionRules::category('resolution'), 'an ordinary resolution starts as something else');
same('2026-3', VereineResolutionRules::ref('2026-03-01', 3), 'the number is the year and a running number');
same(date('Y').'-1', VereineResolutionRules::ref('', 0), 'without a day the current year and at least one');

$entry = VereineResolutionRules::normalize(array('wording' => "  Der Vorstand kauft Trikots.  ", 'category' => 'finanzen',
	'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'member_id' => '7', 'invoice_id' => '', 'note' => 'Angebot von Beispiel GmbH'));
same(array('Der Vorstand kauft Trikots.', 'finanzen', '2026-01-01', '2026-12-31', 7, 0), array($entry['wording'], $entry['category'],
	$entry['valid_from'], $entry['valid_to'], $entry['member_id'], $entry['invoice_id']), 'the entry is trimmed and typed');
same(array('sonstiges', '', ''), array(VereineResolutionRules::normalize(array('category' => 'unbekannt', 'valid_from' => '1.1.2026'))['category'],
	VereineResolutionRules::normalize(array('valid_from' => '1.1.2026'))['valid_from'],
	VereineResolutionRules::normalize(null)['wording']), 'an unknown category and a day that is no day fall back');
same(array(), VereineResolutionRules::validate($entry), 'a validity from January to December is fine');
same(array('VereineResolutionErrorValidity'), VereineResolutionRules::validate(VereineResolutionRules::normalize(array('valid_from' => '2026-12-31', 'valid_to' => '2026-01-01'))),
	'a validity that ends before it starts is refused');

$task = VereineResolutionRules::normalizeTask(array('label' => '  Trikots bestellen  ', 'member_id' => '7', 'deadline' => '2026-10-01'));
same(array('Trikots bestellen', 7, '2026-10-01'), array($task['label'], $task['member_id'], $task['deadline']), 'a task is trimmed and typed');
same(array(), VereineResolutionRules::validateTask($task), 'a task with a text and somebody responsible is fine');
same(array('VereineResolutionTaskErrorLabel', 'VereineResolutionTaskErrorMember'), VereineResolutionRules::validateTask(VereineResolutionRules::normalizeTask(array())),
	'a task without a text and without somebody responsible is refused');

$filters = VereineResolutionRules::filters(array('search' => 'Trikots', 'year' => '2026', 'organ' => 'board', 'category' => 'finanzen', 'result' => 'passed', 'open' => '1'));
same(array('Trikots', '2026', 'board', 'finanzen', 'passed', true), array($filters['search'], $filters['year'], $filters['organ'], $filters['category'],
	$filters['result'], $filters['open']), 'the filters are taken as entered');
same(array('', '', '', ''), array(VereineResolutionRules::filters(array('year' => '26'))['year'], VereineResolutionRules::filters(array('organ' => 'x'))['organ'],
	VereineResolutionRules::filters(array('category' => 'x'))['category'], VereineResolutionRules::filters(array('result' => 'x'))['result']),
	'a year that is no year and unknown values fall away');

$row = array('id' => 1, 'ref' => '2026-1', 'title' => 'Anschaffung', 'wording' => 'Der Vorstand kauft Trikots.', 'note' => '', 'day' => '2026-03-01',
	'organ' => 'board', 'category' => 'finanzen', 'passed' => true, 'valid_from' => '', 'valid_to' => '', 'tasks' => 1, 'tasks_open' => 1);
expect(VereineResolutionRules::matches($row, $filters), 'the resolution matches every filter');
expect(VereineResolutionRules::matches($row, VereineResolutionRules::filters(array('search' => 'trikots'))), 'the search looks at the wording and ignores upper and lower case');
expect(VereineResolutionRules::matches($row, VereineResolutionRules::filters(array('search' => '2026-1'))), 'the search finds a resolution by its number');
expect(!VereineResolutionRules::matches($row, VereineResolutionRules::filters(array('search' => 'Bälle'))), 'a word that is nowhere finds nothing');
expect(!VereineResolutionRules::matches($row, VereineResolutionRules::filters(array('year' => '2025'))), 'another year does not match');
expect(!VereineResolutionRules::matches($row, VereineResolutionRules::filters(array('organ' => 'general'))), 'another organ does not match');
expect(!VereineResolutionRules::matches($row, VereineResolutionRules::filters(array('result' => 'rejected'))), 'a resolution that passed is not a rejected one');
expect(!VereineResolutionRules::matches(array_merge($row, array('tasks_open' => 0)), VereineResolutionRules::filters(array('open' => '1'))),
	'without an open follow-up the filter for open ones leaves it out');

expect(VereineResolutionRules::applies($row, '2026-03-01') && !VereineResolutionRules::applies($row, '2026-02-28'),
	'without a validity a resolution applies from the day it was taken');
$limited = array_merge($row, array('valid_from' => '2026-04-01', 'valid_to' => '2026-06-30'));
same(array(false, true, false), array(VereineResolutionRules::applies($limited, '2026-03-31'), VereineResolutionRules::applies($limited, '2026-06-30'),
	VereineResolutionRules::applies($limited, '2026-07-01')), 'a validity holds on its first and its last day');
expect(!VereineResolutionRules::applies(array_merge($row, array('passed' => false)), '2026-03-01'), 'a rejected resolution never applies');

same(array('Trikots bestellen (2026-1)'), VereineResolutionRules::suggestions(array(array('label' => 'Trikots bestellen', 'ref' => '2026-1'))),
	'an open follow-up becomes an agenda item with its number');
same(array('Trikots bestellen (2026-1)'), VereineResolutionRules::suggestions(array(array('label' => 'Trikots bestellen', 'ref' => '2026-1'),
	array('label' => 'Trikots bestellen', 'ref' => '2026-1'), array('label' => '  ', 'ref' => '2026-2'))), 'the same item comes once, an empty one not at all');

// --------------------------------------------------- circular resolutions of the board

$statuteRules = VereineStatuteRules::defaults();
expect(empty($statuteRules['circular']) && empty($statuteRules['circular_no_objection']),
	'the model statutes know no circular resolution, so both switches start off');
$allowing = array_merge($statuteRules, array('circular' => true));
$circular = VereineCircularRules::normalize(array('title' => '  Trikots  ', 'wording' => ' Der Vorstand kauft Trikots. ', 'deadline' => '2026-10-01'));
same(array('Trikots', 'Der Vorstand kauft Trikots.', '2026-10-01'), array($circular['title'], $circular['wording'], $circular['deadline']),
	'a circular resolution is trimmed and typed');
same('', VereineCircularRules::normalize(array('deadline' => '1.10.2026'))['deadline'], 'a deadline that is no day falls away');
same(array(), VereineCircularRules::validate($circular, $allowing, '2026-09-18', 3), 'with the switch on, a motion with a deadline is fine');
same(array('VereineCircularErrorNotAllowed'), VereineCircularRules::validate($circular, $statuteRules, '2026-09-18', 3),
	'without the switch there is no circular resolution');
same(array('VereineCircularErrorDeadline'), VereineCircularRules::validate($circular, $allowing, '2026-10-02', 3), 'a deadline in the past is refused');
same(array('VereineCircularErrorTooLong'), VereineCircularRules::validate(VereineCircularRules::normalize(array('title' => 'x', 'wording' => 'y', 'deadline' => '2027-09-18')), $allowing, '2026-09-18', 3),
	'a deadline more than 90 days away is refused');
same(array('VereineCircularErrorText', 'VereineCircularErrorNobody'), VereineCircularRules::validate(VereineCircularRules::normalize(array('deadline' => '2026-10-01')), $allowing, '2026-09-18', 0),
	'without a text and without a board nothing starts');
same(13, VereineCircularRules::days('2026-09-18', '2026-10-01'), 'days between two days');

$given = array(array('choice' => 'yes'), array('choice' => 'yes'), array('choice' => 'no'), array('choice' => 'abstain'), array('choice' => ''));
same(array(2, 1, 1, 0, 4), array_values(VereineCircularRules::counts($given)), 'the votes are counted, an empty choice is nobody');
$result = VereineCircularRules::result($given, $allowing);
same(array(true, false, false, 'simple'), array($result['passed'], $result['tie'], $result['objected'], $result['majority']),
	'two yes against one no is the simple majority, abstentions are no valid votes cast');
$tie = VereineCircularRules::result(array(array('choice' => 'yes'), array('choice' => 'no')), array_merge($allowing, array('board_tie_chair' => true)));
expect(!$tie['passed'] && $tie['tie'], 'a tie has no majority: nobody presides over a circular resolution, so there is no casting vote');
expect(!VereineCircularRules::result(array(array('choice' => 'abstain')), $allowing)['passed'], 'only abstentions decide nothing');

$objecting = array(array('choice' => 'yes'), array('choice' => 'objection'));
expect(!VereineCircularRules::objected($objecting, $allowing), 'an objection counts only where the statutes ask that nobody objects');
$strict = array_merge($allowing, array('circular_no_objection' => true));
expect(VereineCircularRules::objected($objecting, $strict), 'with that rule one objection ends the circular resolution');
$objected = VereineCircularRules::result($objecting, $strict);
expect(!$objected['passed'] && $objected['objected'], 'a circular resolution somebody objected to never passes');

$motion = array('deadline' => '2026-10-01');
expect(!VereineCircularRules::ready($motion, 5, $given, $allowing, '2026-09-18'), 'while the deadline runs and somebody is missing, nothing is counted');
expect(VereineCircularRules::ready($motion, 4, $given, $allowing, '2026-09-18'), 'when everybody voted, the result can be counted');
expect(VereineCircularRules::ready($motion, 9, $given, $allowing, '2026-10-02'), 'after the deadline the result can be counted');
expect(VereineCircularRules::ready($motion, 9, $objecting, $strict, '2026-09-18'), 'an objection ends it at once');

// ------------------------------------------------ documents of a meeting and count sheet

same(array(), VereineMeetingDocRules::check(array('name' => 'zaehlliste.pdf', 'tmp_name' => '/tmp/x', 'size' => 1000)), 'a scan as PDF is taken');
same(array(), VereineMeetingDocRules::check(array('name' => 'Foto.JPG', 'tmp_name' => '/tmp/x', 'size' => 1000)), 'a photo is taken, whatever the case of its ending');
same(array('VereineMeetingDocErrorMissing'), VereineMeetingDocRules::check(array('name' => '', 'tmp_name' => '', 'size' => 0)), 'nothing chosen, nothing taken');
same(array('VereineMeetingDocErrorMissing'), VereineMeetingDocRules::check(null), 'an upload that is no upload is refused');
same(array('VereineMeetingDocErrorSize'), VereineMeetingDocRules::check(array('name' => 'x.pdf', 'tmp_name' => '/tmp/x', 'size' => VereineMeetingDocRules::MAX_SIZE + 1)),
	'a file larger than 10 MB is refused');
same(array('VereineMeetingDocErrorKind'), VereineMeetingDocRules::check(array('name' => 'liste.txt', 'tmp_name' => '/tmp/x', 'size' => 10)), 'a text file is no proof');
same(array('VereineMeetingDocErrorKind'), VereineMeetingDocRules::check(array('name' => 'liste', 'tmp_name' => '/tmp/x', 'size' => 10)), 'a file without an ending is refused');

same('vote-7-20260918-191500.pdf', VereineMeetingDocRules::name('vote', 7, 'Zählliste Wahl.pdf', '20260918-191500'), 'the name says kind, what it belongs to and when');
same('proxy-3-20260918-191500.jpg', VereineMeetingDocRules::name('proxy', 3, 'foto.JPG', '20260918-191500'), 'a photo keeps its kind of file');
same('other-0-20260918-191500.pdf', VereineMeetingDocRules::name('unbekannt', -5, 'x.exe', '2026/09/18-19:15:00'),
	'an unknown kind, a negative id and a strange ending fall back, and the moment holds only digits');
same('Vollmacht', VereineMeetingDocRules::label('  Vollmacht  '), 'a label is trimmed');
same('', VereineMeetingDocRules::label(array('x')), 'a label that is no text falls away');

$sheet = VereineMeetingDocRules::sheet(array('item' => '3', 'question' => '  Wahl Kassier:in  ', 'candidates' => "Paula Beispiel\n\n  Sam Beispiel  \n", 'rows' => '5'));
same(array(3, 'Wahl Kassier:in', array('Paula Beispiel', 'Sam Beispiel'), 5), array($sheet['item'], $sheet['question'], $sheet['candidates'], $sheet['rows']),
	'the count sheet takes its question, one candidate per line, empty lines fall away');
same(VereineMeetingDocRules::SHEET_ROWS, VereineMeetingDocRules::sheet(array('question' => 'x'))['rows'], 'without a number the count sheet gets its usual rows');
same(array(1, VereineMeetingDocRules::SHEET_ROWS_MAX), array(VereineMeetingDocRules::sheet(array('rows' => '0'))['rows'], VereineMeetingDocRules::sheet(array('rows' => '999'))['rows']),
	'the number of rows stays between one and the maximum');
same(array(), VereineMeetingDocRules::validateSheet($sheet), 'a count sheet with a question is fine');
same(array('VereineMeetingDocErrorQuestion'), VereineMeetingDocRules::validateSheet(VereineMeetingDocRules::sheet(array())), 'a count sheet without a question is refused');

// ------------------------------------------------------------- mail server answers

// The answer @Tabsi1998 got on 21.09.2026, as Dolibarr stores it (escaped line breaks included).
$refused = 'Error [120]: Ran into problems sending Mail.\r\nResponse: 553 5.7.1 <noreply@lionsquad.at>: Sender address rejected: not owned by user office@lionsquad.at\r\n\nError [120]: Ran into problems sending Mail.\r\nResponse: 554 5.5.1 Error: no valid recipients\r\n\n';
same(array('cause' => 'sender', 'login' => 'office@lionsquad.at'), VereineMailRules::explain($refused),
	'a refused sender is recognised, with the address the server allows - even though "no valid recipients" follows');
same('sender', VereineMailRules::explain('SMTP Error: 553 5.7.1 Sender address rejected')['cause'], 'a refused sender without a login named');
same('login', VereineMailRules::explain('535 5.7.8 Error: authentication failed: UGFzc3dvcmQ6')['cause'], 'a refused login');
same('connect', VereineMailRules::explain('SMTP Error: Could not connect to SMTP host. Connection refused')['cause'], 'a server that cannot be reached');
same('recipient', VereineMailRules::explain('550 5.1.1 <nobody@example.at>: Recipient address rejected: User unknown')['cause'], 'an unknown recipient');
same('other', VereineMailRules::explain('421 4.7.0 Try again later')['cause'], 'anything else stays "other"');
same("Error [120]: Ran into problems sending Mail.\nResponse: 553 5.7.1 <noreply@lionsquad.at>: Sender address rejected: not owned by user office@lionsquad.at\nError [120]: Ran into problems sending Mail.\nResponse: 554 5.5.1 Error: no valid recipients",
	VereineMailRules::readable($refused), 'escaped line breaks become real ones, empty lines fall away');
expect(VereineMailRules::validSender('office@lionsquad.at') && !VereineMailRules::validSender('office') && !VereineMailRules::validSender('Office <office@lionsquad.at>'),
	'a sender is a plain address');

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

// Every endpoint names the rights it needs, so the API tab of the setup can show them.
$apiEndpoints = VereineApiRules::endpoints($openapi);
expect(count($apiEndpoints) >= 13 && count(array_filter($apiEndpoints, function ($endpoint) {
	return $endpoint['rights'] && $endpoint['operation'] !== '';
})) === count($apiEndpoints), 'every endpoint in docs/openapi.json has an operationId and x-vereine-rights');
$thresholds = array_values(array_filter($apiEndpoints, function ($endpoint) {
	return $endpoint['path'] === '/vereine/thresholds';
}));
same(array(true, false, true, false), array(
	VereineApiRules::canCall($thresholds[0]['rights'], array('vereine:association:read', 'facture:lire'), false),
	VereineApiRules::canCall($thresholds[0]['rights'], array('vereine:association:read'), false),
	VereineApiRules::canCall($thresholds[0]['rights'], array(), true),
	VereineApiRules::canCall(array(), array('vereine:association:read'), false),
), 'thresholds need both rights, an administrator has all, an endpoint without rights is never callable');
same(array('vereine:website:read', 'facture:lire'), array(VereineApiRules::rightKey('vereine', 'website', 'read'), VereineApiRules::rightKey('facture', 'lire', null)), 'rights as in the description');

// Every rule of the statutes has to show in the generated text: if a rule has two values, the text
// has to differ. A rule that deliberately says nothing there stands in the list below, with its reason.
$ruleText = function (array $changes) use ($statuteContext) {
	$parts = array();
	foreach (VereineStatuteText::sections(VereineStatuteRules::normalize($changes), VereineStatuteText::normalize(array('activities' => 'Turniere', 'tax' => 'bao',
		'asset' => 'a', 'asset_purpose' => 'Jugendsport')), $statuteContext) as $section) {
		$parts[] = $section['title'].': '.implode("\n", $section['paragraphs']);
	}
	return implode("\n", $parts);
};
$ruleVariants = array(
	'min_age' => array(array('min_age' => 0), array('min_age' => 18)),
	'general_years' => array(array('general_years' => 1), array('general_years' => 3)),
	'invite_days' => array(array('invite_days' => 14), array('invite_days' => 21)),
	'invite_channels' => array(array('invite_channels' => array('letter')), array('invite_channels' => array('email'))),
	'motion_days' => array(array('motion_days' => 0), array('motion_days' => 7)),
	'proxy' => array(array('proxy' => true), array('proxy' => false)),
	'general_quorum' => array(array('general_quorum' => 0), array('general_quorum' => 25)),
	'statute_majority' => array(array('statute_majority' => 'two_thirds'), array('statute_majority' => 'three_quarters')),
	'dissolution_majority' => array(array('dissolution_majority' => 'two_thirds'), array('dissolution_majority' => 'three_quarters')),
	'virtual' => array(array('virtual' => 'none'), array('virtual' => 'hybrid')),
	'board_quorum' => array(array('board_quorum' => 50), array('board_quorum' => 75)),
	'board_tie_chair' => array(array('board_tie_chair' => true), array('board_tie_chair' => false)),
	'circular' => array(array('circular' => false), array('circular' => true)),
	'circular_no_objection' => array(array('circular' => true, 'circular_no_objection' => false), array('circular' => true, 'circular_no_objection' => true)),
);
// The kinds of member who may vote come from the catalogue of member types, not from the rules; the
// generated text names them from the context instead (see $statuteContext['voting']).
$ruleExempt = array('voting_types');
foreach (array_keys(VereineStatuteRules::defaults()) as $key) {
	if (in_array($key, $ruleExempt, true)) {
		continue;
	}
	expect(isset($ruleVariants[$key]), 'rule '.$key.' has no variant in this test: add one or say in $ruleExempt why the statutes stay silent about it');
	if (isset($ruleVariants[$key])) {
		expect($ruleText($ruleVariants[$key][0]) !== $ruleText($ruleVariants[$key][1]), 'rule '.$key.' changes nothing in the generated statutes');
	}
}
$withCircular = $ruleText(array('circular' => true));
expect(strpos($withCircular, 'im Umlaufweg') !== false && strpos($ruleText(array()), 'im Umlaufweg') === false,
	'the statutes name the circular resolution only when the rules allow it');
expect(strpos($ruleText(array('circular' => true, 'circular_no_objection' => true)), 'widerspricht') !== false && strpos($withCircular, 'widerspricht') === false,
	'the objection to the procedure is named only when the statutes ask for it');

// The statutes name the board as the catalogue has it: a fixed number only where every function is required.
$boardContext = array('board' => array('Obmann/Obfrau', 'Schriftführer:in', 'Kassier:in', 'Stellvertretung Obmann/Obfrau'),
	'board_required' => array('Obmann/Obfrau', 'Schriftführer:in', 'Kassier:in'), 'board_optional' => array('Stellvertretung Obmann/Obfrau'));
same('Der Vorstand besteht aus folgenden Mitgliedern: Obmann/Obfrau, Schriftführer:in und Kassier:in und bei Bedarf Stellvertretung Obmann/Obfrau.',
	VereineStatuteText::boardSentence($boardContext, '____'), 'a board with optional functions names them with "bei Bedarf", without a number');
$fixedBoard = array('board' => array('Obmann/Obfrau', 'Schriftführer:in', 'Kassier:in'),
	'board_required' => array('Obmann/Obfrau', 'Schriftführer:in', 'Kassier:in'), 'board_optional' => array());
same('Der Vorstand besteht aus drei Mitgliedern, und zwar aus: Obmann/Obfrau, Schriftführer:in und Kassier:in.',
	VereineStatuteText::boardSentence($fixedBoard, '____'), 'a board where every function is required keeps the number of the model statutes');
same('Der Vorstand besteht aus ____.', VereineStatuteText::boardSentence(array('board' => array(), 'board_required' => array(), 'board_optional' => array()), '____'),
	'without functions the sentence stays open');
expect(strpos(implode(' ', VereineStatuteText::sections(VereineStatuteRules::defaults(), VereineStatuteText::defaults(),
	array_merge($statuteContext, $boardContext))[12]['paragraphs']), 'Stellvertretungen') !== false,
	'with deputies the statutes keep the clause about standing in');
expect(strpos(implode(' ', VereineStatuteText::sections(VereineStatuteRules::defaults(), VereineStatuteText::defaults(),
	array_merge($statuteContext, $fixedBoard))[12]['paragraphs']), 'Stellvertretungen') === false,
	'without deputies the clause about standing in falls away');

// Every paragraph says where it comes from, and nothing is named that is not generated.
$statuteSources = VereineStatuteText::sources();
foreach (VereineStatuteText::sections($statuteRules, VereineStatuteText::normalize(array('activities' => 'Turniere', 'tax' => 'bao', 'asset' => 'a',
	'asset_purpose' => 'Jugendsport')), $statuteContext) as $section) {
	expect(isset($statuteSources[$section['title']]) && $statuteSources[$section['title']] !== '',
		'the paragraph '.$section['title'].' does not say where it comes from: add it to VereineStatuteText::sources()');
}

// The signature of a money matter: chair and treasurer, as § 13 Abs. 2 of the model statutes says.
$moneyRules = VereineSignatureRules::normalize(null, array('obmann', 'schriftfuehrung', 'kassier', 'rechnungspruefung'));
same(array('obmann', 'kassier'), $moneyRules['money']['roles'], 'a money matter is signed by the chair and the treasurer');
same(array('obmann', 'schriftfuehrung'), $moneyRules['resolution']['roles'], 'an ordinary resolution stays with chair and secretary');
expect(in_array('money', VereineSignatureRules::KINDS, true) && count(VereineSignatureRules::KINDS) === 5, 'five kinds of document');
same(true, VereineResolutionRules::normalize(array('money' => '1'))['money'], 'a resolution can be marked as a money matter');
same(false, VereineResolutionRules::normalize(array())['money'], 'without the mark it is no money matter');

// ------------------------------------------------------------- signing with ID Austria

$signWays = VereineSignatureRules::normalize(null, $codes);
same('click', $signWays['letter']['sign'], 'without a setting a document is signed in Dolibarr, as before');
$qesOnly = VereineSignatureRules::normalize(array('letter' => array('roles' => array('obmann'), 'sign' => 'qes'),
	'minutes' => array('roles' => array('obmann'), 'sign' => 'both'), 'resolution' => array('roles' => array('obmann'), 'sign' => 'fax')), $codes);
expect(VereineSignatureRules::allowsQes($qesOnly, 'letter') && !VereineSignatureRules::allowsClick($qesOnly, 'letter'), 'only ID Austria: no signing with the password');
expect(VereineSignatureRules::allowsQes($qesOnly, 'minutes') && VereineSignatureRules::allowsClick($qesOnly, 'minutes'), 'both: whoever signs chooses');
same('click', $qesOnly['resolution']['sign'], 'an unknown way falls back to Dolibarr');
expect(!VereineSignatureRules::allowsQes(VereineSignatureRules::normalize(array('letter' => array('roles' => array(), 'sign' => 'qes')), $codes), 'letter'),
	'a kind nobody signs offers no way to sign');

$qesSettings = array('url' => 'https://signatur.example.at/pdf-as-web', 'connector' => 'mobilebku', 'key' => '', 'profile' => '');
$qesBody = VereineQes::signRequest("%PDF-1.7\n", 'vereine-7-abc', $qesSettings, 'https://erp.example.at/custom/vereine/signature.php?qes=abc',
	'https://erp.example.at/custom/vereine/signature.php?qes=abc&failed=1');
same(base64_encode("%PDF-1.7\n"), $qesBody['inputData'], 'the document goes along with the request');
same(array('mobilebku', 'https://erp.example.at/custom/vereine/signature.php?qes=abc', 'https://erp.example.at/custom/vereine/signature.php?qes=abc',
	'https://erp.example.at/custom/vereine/signature.php?qes=abc&failed=1', '_self'),
	array($qesBody['parameters']['connector'], $qesBody['parameters']['invoke-url'], $qesBody['parameters']['invokeURL'],
		$qesBody['parameters']['invoke-error-url'], $qesBody['parameters']['invoke-target']), 'ID Austria: the way back under both names PDF-AS knows');
$qesTest = VereineQes::signRequest("%PDF-1.7\n", str_repeat('x', 80), array('connector' => 'jks', 'key' => 'test', 'profile' => 'SIGNATURBLOCK_SMALL_DE') + $qesSettings, '', '');
expect(!isset($qesTest['parameters']['invoke-url']) && $qesTest['parameters']['keyIdentifier'] === 'test' && $qesTest['parameters']['profile'] === 'SIGNATURBLOCK_SMALL_DE'
	&& strlen($qesTest['requestID']) === 64, 'a test key store signs at once, with its key and profile, and the request id is cut to 64');

same(array('redirect' => 'https://signatur.example.at/pdf-as-web/Sign?id=1', 'signed' => '', 'error' => ''),
	VereineQes::readSignAnswer(array('requestID' => 'x', 'redirectUrl' => 'https://signatur.example.at/pdf-as-web/Sign?id=1')), 'ID Austria: the person is sent on');
same("%PDF-1.7 signed", VereineQes::readSignAnswer(array('signedPDF' => base64_encode("%PDF-1.7 signed")))['signed'], 'a test key store: the signed document at once');
same(array('redirect' => '', 'signed' => '', 'error' => 'no PDF'), VereineQes::readSignAnswer(array('signedPDF' => base64_encode('<html>'))), 'something else than a PDF is refused');
same('', VereineQes::readSignAnswer(array('redirectUrl' => 'javascript:alert(1)'))['redirect'], 'only a web address is followed');
same('connector not allowed', VereineQes::readSignAnswer(array('error' => 'connector not allowed'))['error'], 'an error of the service is passed on');

// The codes of an MOA signature check, as the PDF-AS handbook lists them.
$qesRead = VereineQes::readVerifyAnswer(array('verifyResults' => array(
	array('signatureIndex' => 1, 'signedBy' => 'CN=Max Muster,C=AT', 'valueCode' => 0, 'certificateCode' => 3, 'certificateMessage' => 'Status unbekannt'),
	array('signatureIndex' => 0, 'signedBy' => 'CN=Erika Muster,O=Testdienst,C=AT', 'valueCode' => 0, 'certificateCode' => 0),
	array('signatureIndex' => 2, 'signedBy' => 'CN=Gesperrt\, Paula,C=AT', 'valueCode' => 0, 'certificateCode' => 5),
	array('signatureIndex' => 3, 'signedBy' => 'CN=Verändert,C=AT', 'valueCode' => 1, 'certificateCode' => 0),
)));
same(array('Erika Muster', 'Max Muster', 'Gesperrt, Paula', 'Verändert'), array_column($qesRead, 'name'), 'in the order of signing, with the common name');
same(array('valid', 'unclear', 'invalid', 'invalid'), array_column($qesRead, 'state'),
	'valid only with an intact value and a valid chain; status unknown is unclear; suspended (5) and changed documents are not valid');
same('Status unbekannt', $qesRead[1]['message'], 'the message of the service is kept');
same(array(), VereineQes::readVerifyAnswer('<html>'), 'an answer without results reads as no signature');
same('O=Verein', VereineQes::commonName('O=Verein'), 'a subject without a name stays as it is');

expect(VereineQes::sameService('https://signatur.example.at/pdf-as-web', 'https://signatur.example.at:443/pdf-as-web/PDFData?id=1'), 'same host, same port');
expect(!VereineQes::sameService('https://signatur.example.at/pdf-as-web', 'https://evil.example.at/PDFData'), 'another host is no signature service');
expect(!VereineQes::sameService('https://signatur.example.at/pdf-as-web', 'http://signatur.example.at/PDFData'), 'another scheme is no signature service');
expect(!VereineQes::sameService('http://127.0.0.1/pdf-as', 'http://127.0.0.1:8080/pdf-as/PDFData'), 'another port is no signature service');
expect(!VereineQes::sameService('https://signatur.example.at', 'https://user:pw@signatur.example.at/PDFData'), 'no address with a login in it');

// The module reads a signed PDF itself: appended signatures, the name of the certificate, whether the bytes still match.
same('', VereineQes::der('3082zz'), 'no hex, no signature');
same("\x30\x03\x02\x01\x05", VereineQes::der('3003020105000000'), 'the padding after the signature is cut off');
same("\x30\x81\x02\x05\x00", VereineQes::der('308102050000'), 'a long form length');
same('', VereineQes::der('30820500'), 'a signature longer than its hex is none');
same(array(), VereineQes::signatures("%PDF-1.7\n%%EOF\n", sys_get_temp_dir()), 'a PDF without signature');
if (function_exists('openssl_pkcs7_sign')) {
	require_once $root.'/tests/runtime/pdfas_sign.php';
	$qesPdf = "%PDF-1.7\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
	$qesOnce = vereineTestSignPdf($qesPdf, 'Erika Muster', sys_get_temp_dir());
	$qesTwice = vereineTestSignPdf($qesOnce, 'Max Muster', sys_get_temp_dir());
	$qesFound = VereineQes::signatures($qesTwice, sys_get_temp_dir());
	same(array('Erika Muster', 'Max Muster'), array_column($qesFound, 'name'), 'both signatures with the name of their certificate');
	same(array(false, true), array_column($qesFound, 'covers_end'), 'only the last signature reaches the end of the file');
	if (VereineQes::canCheck()) {
		same(array(true, true), array_column($qesFound, 'intact'), 'both signatures match their bytes');
		$qesChanged = $qesTwice;
		$qesChanged[20] = 'X';
		same(array(false, false), array_column(VereineQes::signatures($qesChanged, sys_get_temp_dir()), 'intact'), 'a changed byte breaks every signature over it');
	}
	same(array('ok' => true, 'name' => 'Max Muster'), VereineQes::appended($qesOnce, $qesTwice, sys_get_temp_dir()), 'one signature appended, nothing before it changed');
	same(false, VereineQes::appended($qesPdf, $qesTwice, sys_get_temp_dir())['ok'], 'two signatures at once are no single signature');
	same(false, VereineQes::appended($qesOnce, vereineTestSignPdf($qesPdf, 'Max Muster', sys_get_temp_dir()), sys_get_temp_dir())['ok'],
		'a PDF without the earlier signature would lose it');
	same(false, VereineQes::appended($qesOnce, $qesTwice.'% more', sys_get_temp_dir())['ok'], 'something after the new signature');
}

// ------------------------------------------------------------- placeholders and e-mail templates

/**
 * A stand-in for Dolibarr's Translate with the German texts of the module.
 */
class VereineTestLangs
{
	/** @var string Language */
	public $defaultlang = 'de_DE';

	/** @var array<string,string> Texts by key */
	private $entries;

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $entries Texts by key
	 */
	public function __construct(array $entries)
	{
		$this->entries = $entries;
	}

	/**
	 * Nothing to load, the texts are there.
	 *
	 * @param string $domain Domain
	 * @return int
	 */
	public function load($domain)
	{
		return 1;
	}

	/**
	 * A text with its values, as Dolibarr fills it.
	 *
	 * @param string $key Key
	 * @param string $p1  First value
	 * @param string $p2  Second value
	 * @param string $p3  Third value
	 * @return string
	 */
	public function transnoentities($key, $p1 = '', $p2 = '', $p3 = '')
	{
		return isset($this->entries[$key]) ? sprintf($this->entries[$key], $p1, $p2, $p3) : $key;
	}
}

$germanTexts = langEntries($root.'/langs/de_DE/vereine.lang');
$testLangs = new VereineTestLangs($germanTexts);
foreach (VereinePlaceholders::KEYS as $group => $keys) {
	foreach ($keys as $key) {
		expect(isset($germanTexts[VereinePlaceholders::describedBy($key)]), 'the placeholder '.$key.' has no explanation '.VereinePlaceholders::describedBy($key));
	}
}
same(VereineMinutesRules::PLACEHOLDERS, array_map(function ($key) {
	return trim($key, '{}');
}, VereinePlaceholders::KEYS['minutes']), 'the list names every placeholder of the minutes');

$phFunctions = array(array('code' => 'obmann', 'label' => 'Obmann/Obfrau', 'board' => true), array('code' => 'kassier', 'label' => 'Kassier:in', 'board' => true),
	array('code' => 'rechnungspruefung', 'label' => 'Rechnungsprüfer:in', 'board' => false));
$phValues = VereinePlaceholders::association($organization, $phFunctions, array('obmann' => array('Erika Muster'), 'kassier' => array('Max Muster', 'Paula Muster'),
	'rechnungspruefung' => array('Pia Prüf')), '01.03.2019');
same(array('123456789', 'THE LION SQUAD', 'Innsbruck', 'Musterweg 1, 6020 Innsbruck', 'Landespolizeidirektion Tirol', 'Erika Muster', 'Max Muster, Paula Muster', ''),
	array($phValues['__VEREINE_ZVR__'], $phValues['__VEREINE_NAME__'], $phValues['__VEREINE_SITZ__'], $phValues['__VEREINE_ADRESSE__'], $phValues['__VEREINE_BEHOERDE__'],
		$phValues['__VEREINE_OBMANN__'], $phValues['__VEREINE_KASSIER__'], $phValues['__VEREINE_SCHRIFTFUEHRUNG__']), 'the data of the association, a vacant function stays empty');
same("Obmann/Obfrau: Erika Muster\nKassier:in: Max Muster, Paula Muster", $phValues['__VEREINE_VORSTAND__'], 'the board, one function per line, without the auditors');
same(array_merge(VereinePlaceholders::KEYS['association']), array_keys(array_intersect_key(array_flip(VereinePlaceholders::KEYS['association']), $phValues)),
	'every placeholder of the association has a value');

// The standard invitation gives the text the module always sent.
$phMeeting = array('kind' => 'general', 'title' => 'Generalversammlung 2026', 'time' => '19:00', 'place' => 'Vereinsheim', 'format' => 'hybrid',
	'access' => 'https://meet.example.test/gv', 'agenda' => array('Begrüßung', 'Bericht des Vorstands'));
$phInvitation = VereineMailTemplates::defaults(VereineMailTemplates::TYPE_INVITATION, $testLangs);
$phFilled = VereinePlaceholders::fill($phInvitation['content'], array_merge($phValues,
	VereinePlaceholders::meeting($phMeeting, 'Erika Muster', false, 'THE LION SQUAD', '14.10.2026', '11.10.2026', $testLangs)));
same(implode("
", array('Guten Tag Erika Muster,', '', 'hiermit lädt der Vorstand des Vereins „THE LION SQUAD“ zur ordentlichen Generalversammlung ein:', '',
	'Generalversammlung 2026', 'Wann: 14.10.2026 um 19:00 Uhr', 'Wo: Vereinsheim', 'Sie können vor Ort oder virtuell teilnehmen.', 'Teilnahme: https://meet.example.test/gv', '',
	'Tagesordnung:', '1. Begrüßung', '2. Bericht des Vorstands', '',
	'Anträge zur Generalversammlung sind bis 11.10.2026 beim Vorstand schriftlich oder per E-Mail einzureichen.', '',
	'Sie sind zur Teilnahme eingeladen, laut Statuten aber nicht stimmberechtigt.', '',
	'Mit freundlichen Grüßen', 'Der Vorstand des Vereins „THE LION SQUAD“')), $phFilled, 'the standard invitation is the text the module always sent');
$phBoard = VereinePlaceholders::fill($phInvitation['content'], array_merge($phValues, VereinePlaceholders::meeting(array('kind' => 'board', 'place' => '',
	'format' => 'physical', 'agenda' => array('Bericht')) + $phMeeting, 'Max Muster', true, 'THE LION SQUAD', '02.10.2026', '', $testLangs)));
expect(strpos($phBoard, "1. Bericht\n\nMit freundlichen Grüßen") !== false && strpos($phBoard, 'Wo:') === false,
	'without notes one empty line before the closing, without a place no line for it');
same('Einladung: Generalversammlung 2026 am 14.10.2026 19:00', VereinePlaceholders::fill($phInvitation['topic'],
	VereinePlaceholders::meeting($phMeeting, 'Erika Muster', true, 'THE LION SQUAD', '14.10.2026', '', $testLangs)), 'the subject as always');
$phReminder = VereineMailTemplates::defaults(VereineMailTemplates::TYPE_REMINDER, $testLangs);
expect(strpos(VereinePlaceholders::fill($phReminder['content'], VereinePlaceholders::circular(array('title' => 'Neue Trikots', 'wording' => 'Wir kaufen 20 Trikots.'),
	'Max', '30.09.2026', 'https://erp.example.test/custom/vereine/circulars.php?id=3') + $phValues), "Hallo Max, im Vorstand von THE LION SQUAD fehlt noch deine Stimme") === 0,
	'the reminder of a circular resolution');
same("Hallo <b>__VEREINE_UNBEKANNT__</b><br>A &amp; B<br>\nC", VereinePlaceholders::fill('Hallo <b>__VEREINE_UNBEKANNT__</b><br>__VEREINE_X__',
	array('__VEREINE_X__' => "A & B\nC"), true), 'in HTML values are escaped and keep their line breaks; unknown placeholders stay');
same("Hallo Erika,\nschön & gut\nGruß", VereineMailTemplates::plain("<p>Hallo Erika,<br>schön &amp; gut</p>\n<p>Gruß</p>"), 'an HTML template as text for a letter');
same("keine\nÄnderung", VereineMailTemplates::plain("keine\nÄnderung"), 'plain text stays as it is');

// ------------------------------------------------------------- tax profiles of older invoice lines

$tcCodes = array('MITGLIEDSBEITRAG' => 1, 'SPENDE' => 2, 'BETRIEB_20' => 6);
$tcLine = array('product_profile' => 0, 'fee' => false, 'source_profile' => 0, 'text' => '');
same(array('profile' => 6, 'certain' => true, 'reason' => 'product'), VereineTaxCheckRules::suggest(array('product_profile' => 6, 'fee' => true) + $tcLine, $tcCodes),
	'the product has a profile: that one, ticked');
same(array('profile' => 1, 'certain' => true, 'reason' => 'fee'), VereineTaxCheckRules::suggest(array('fee' => true) + $tcLine, $tcCodes), 'an invoice of a membership fee');
same(array('profile' => 6, 'certain' => true, 'reason' => 'credit'), VereineTaxCheckRules::suggest(array('source_profile' => 6) + $tcLine, $tcCodes), 'a credit note of a line with a profile');
same(array('profile' => 1, 'certain' => false, 'reason' => 'member'), VereineTaxCheckRules::suggest(array('text' => 'Mitgliedsbeitrag 2025 Jugend') + $tcLine, $tcCodes),
	'the word membership fee only preselects');
same('donation', VereineTaxCheckRules::suggest(array('text' => 'Spende Sommerfest') + $tcLine, $tcCodes)['reason'], 'a donation by its word');
same('manual', VereineTaxCheckRules::suggest(array('text' => 'Spendenlauf Startgeld') + $tcLine, $tcCodes)['reason'], 'a word that only contains donation is no donation');
foreach (array('Sponsoring Trikotwerbung', 'Vereinstrikot', 'Getränke Sommerfest', 'Turnierbeitrag') as $tcText) {
	same(array('profile' => 0, 'certain' => false, 'reason' => 'manual'), VereineTaxCheckRules::suggest(array('text' => $tcText) + $tcLine, $tcCodes), 'to check by hand: '.$tcText);
}
same('manual', VereineTaxCheckRules::suggest(array('fee' => true) + $tcLine, array())['reason'], 'without an active membership fee profile nothing is suggested');
same(array(0 => array('count' => 1, 'total' => 20.0), 1 => array('count' => 2, 'total' => 100.5)), VereineTaxCheckRules::totals(array(
	array('suggestion' => array('profile' => 1), 'total' => 50.25), array('suggestion' => array('profile' => 0), 'total' => 20), array('suggestion' => array('profile' => 1), 'total' => 50.25))),
	'the preview counts and sums by suggested profile');
same(6, VereineTaxCheckRules::sourceProfile(9, array(array('product' => 8, 'profile' => 1), array('product' => 9, 'profile' => 6))), 'the credit note takes the line with its product');
same(1, VereineTaxCheckRules::sourceProfile(0, array(array('product' => 0, 'profile' => 1), array('product' => 3, 'profile' => 1), array('product' => 4, 'profile' => 0))),
	'a free line takes the one profile of the original');
same(0, VereineTaxCheckRules::sourceProfile(0, array(array('product' => 0, 'profile' => 1), array('product' => 3, 'profile' => 6))), 'two profiles on the original: not clear');

// ------------------------------------------------------------- audit of the auditors (§ 21 VerG)

same(array('year' => 2025, 'start' => '2025-01-01', 'end' => '2025-12-31', 'label' => '2025'), VereineAuditRules::period(2025, 1), 'a calendar year');
same(array('year' => 2025, 'start' => '2025-07-01', 'end' => '2026-06-30', 'label' => '2025/26'), VereineAuditRules::period(2025, 7), 'a year from July');
same('2024-02-29', VereineAuditRules::period(2023, 3)['end'], 'a year from March ends on the last day of February, also in a leap year');
same(2025, VereineAuditRules::lastEnded('2026-09-22', 1), 'in September 2026 the last year that ended is 2025');
same(2024, VereineAuditRules::lastEnded('2026-03-10', 7), 'in March 2026 the year 2025/26 still runs, 2024/25 is the last that ended');
same(2025, VereineAuditRules::lastEnded('2026-07-01', 7), 'on 1 July 2026 the year 2025/26 has ended');
same(1000.0, VereineAuditRules::unusualFrom(array(20, 30, -25, 40)), 'small amounts: the minimum counts');
same(4950.0, VereineAuditRules::unusualFrom(array(1200, -1500, 1800, 5000)), 'three times the median of the absolute amounts: (1500 + 1800) / 2 x 3');
same(1000.0, VereineAuditRules::unusualFrom(array()), 'no amounts: the minimum');
$auditPoints = VereineAuditRules::points(array('accounting' => array('state' => 'ok'), 'use' => array('state' => 'defect', 'text' => '  Reise ohne Beschluss  '),
	'unusual' => array('state' => 'maybe')));
same(array('state' => 'defect', 'text' => 'Reise ohne Beschluss'), $auditPoints['use'], 'a deficiency with its text');
same('open', $auditPoints['unusual']['state'], 'an unknown state is still open');
same(VereineAuditRules::POINTS, array_keys($auditPoints), 'every point of § 21 (3) VerG, in order');
same(array('VereineAuditErrorDefectText'), VereineAuditRules::validate(VereineAuditRules::points(array('danger' => array('state' => 'defect')))), 'a deficiency needs its text');
same('defects', VereineAuditRules::result($auditPoints), 'one deficiency: the report names it');
same('open', VereineAuditRules::result(VereineAuditRules::points(array('accounting' => array('state' => 'ok')))), 'points still open: no result yet');
$allOk = array();
foreach (VereineAuditRules::POINTS as $auditPoint) {
	$allOk[$auditPoint] = array('state' => 'ok');
}
same('confirmed', VereineAuditRules::result(VereineAuditRules::points($allOk)), 'every point in order: confirmed');
same('2026-09-30', VereineAuditRules::deadline('2026-05-31'), 'four months after the account was made, at most the last day of the month');
same('2027-01-15', VereineAuditRules::deadline('2026-09-15'), 'across the new year');

// ------------------------------------------------------------ language files

// The module speaks German; en_US is an exact copy so an English interface shows German, not keys.
expect(file_get_contents($root.'/langs/en_US/vereine.lang') === file_get_contents($root.'/langs/de_DE/vereine.lang'), 'langs/en_US/vereine.lang is an exact copy of de_DE; run python scripts/sync_langs.py');
$english = langEntries($root.'/langs/en_US/vereine.lang');
$languages = glob($root.'/langs/*/vereine.lang');
same(array('de_DE', 'en_US'), array_map(function ($file) {
	return basename(dirname($file));
}, $languages), 'German and its English copy are the only language files');
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
	'VereinePartnerSection_' => $sections,
	'VereinePartnerSectionHelp_' => $sections,
	'VereinePartnerPreviewButton_' => $operations,
	'VereinePartnerPreview' => array('', 'Create', 'Attributes', 'Copy', 'Orphans'),
	'VereinePartnerMatch_' => array(VereinePartnerRules::MATCH_EMAIL, VereinePartnerRules::MATCH_NAME_ZIP),
	'VereineField_' => array('email', 'address', 'zip', 'town'),
	'VereineLog_' => array('partner_created', 'partner_linked', 'partner_suggested', 'partner_attributes', 'partner_updated', 'partner_error', 'partner_unlinked', 'fee_invoice', 'fee_run', 'fee_error', 'fee_period', 'fee_direct_debit', 'exit_planned', 'exit_done', 'exit_cancelled', 'exit_error', 'consent_given', 'consent_withdrawn', 'application_received', 'function_start', 'function_end', 'function_reported', 'function_report_pdf', 'function_group_add', 'function_group_remove', 'statute_rules', 'authority_letter', 'authority_letter_filed', 'statute_text', 'statute_version', 'meeting_created', 'meeting_invited', 'meeting_status', 'meeting_attendance', 'meeting_vote', 'signature_rules', 'signature_started', 'signature_signed', 'signature_done', 'minutes_final', 'minutes_sent', 'resolution_added', 'resolution_saved', 'resolution_task', 'resolution_task_done', 'circular_started', 'circular_vote', 'circular_reminded', 'circular_decided', 'circular_cancelled', 'meeting_document', 'qes_setup', 'qes_signed', 'tax_profile_set', 'audit_saved', 'audit_checked', 'audit_report'),
	'VereineGroupsChange_' => array('add', 'remove'),
	'VereineMailingStatus_' => VereineMailingRules::STATUSES,
	'VereineReportMissing_' => array('birth', 'birth_place', 'address'),
	'VereineFunctionProblem_' => array('missing', 'too_many', 'board_too_small', 'auditor_on_board', 'election_due'),
	'VereineStatuteChannel_' => VereineStatuteRules::CHANNELS,
	'VereineStatuteText_' => array('activities', 'funds'),
	'VereineStatuteTextHelp_' => array('activities', 'funds'),
	'VereineStatuteTextTax_' => array_keys(VereineStatuteText::ASSETS),
	'VereineStatuteTextWording_' => array('none_bmi', 'bao_base', 'bao_a', 'bao_b', 'bao_c', 'donation_1', 'donation_2', 'donation_3', 'donation_4'),
	'VereineStatuteTextProblem_' => array('purpose', 'activities', 'funds', 'board', 'board_term', 'board_terms_differ', 'auditors', 'assets', 'nonprofit'),
	'VereineStatuteSource_' => array('generated', 'uploaded'),
	'VereineMeetingKind_' => VereineMeetingRules::KINDS,
	'VereineMeetingStatus_' => VereineMeetingRules::STATUSES,
	'VereineMeetingFormat_' => VereineMeetingRules::FORMATS,
	'VereineMeetingChannel_' => array(VereineMeetingRules::CHANNEL_EMAIL, VereineMeetingRules::CHANNEL_LETTER),
	'VereineMeetingRecipientsHelp_' => VereineMeetingRules::KINDS,
	'VereineMeetingMailIntro_' => VereineMeetingRules::KINDS,
	'VereineMeetingMailFormat_' => array(VereineMeetingRules::FORMAT_VIRTUAL, VereineMeetingRules::FORMAT_HYBRID),
	'VereineMeetingGeneralOverdue_' => array('statutes', 'law'),
	'VereineAttendanceState_' => VereineAttendanceRules::STATES,
	'VereineAttendanceHowTo_' => array('board', 'general'),
	'VereineVoteKind_' => VereineVoteRules::KINDS,
	'VereineMinutesPlaceholder_' => VereineMinutesRules::PLACEHOLDERS,
	'VereineMinutesItemKind_' => VereineMinutesRules::ITEM_KINDS,
	'VereineMeetingStep_' => VereineMeetingRules::STEPS,
	'VereineMeetingStepState_' => array('done', 'now', 'later'),
	'VereineMeetingStepHint_' => array('plan', 'invite_board', 'invite_general', 'meet', 'minutes', 'close'),
	'VereineGlossary_' => array('quorum', 'majority', 'proxy', 'kinds', 'circular', 'keeper'),
	'VereineGlossaryText_' => array('quorum', 'majority', 'proxy', 'kinds', 'circular', 'keeper'),
	'VereineSignatureKind_' => VereineSignatureRules::KINDS,
	'VereineSignatureKindHelp_' => VereineSignatureRules::KINDS,
	'VereineSignatureMode_' => VereineSignatureRules::MODE_LIST,
	'VereineSignatureWay_' => array('click', 'paper', 'qes'),
	'VereineSignatureSign_' => VereineSignatureRules::SIGN_WAYS,
	'VereineQesConnector_' => VereineQes::CONNECTORS,
	'VereineQesState_' => array(VereineQes::STATE_VALID, VereineQes::STATE_UNCLEAR, VereineQes::STATE_INVALID),
	'VereineQesIntact_' => array('yes', 'no', 'unknown'),
	'VereineMailTemplateType_' => VereineMailTemplates::TYPES,
	'VereineMailTemplateState_' => array('standard', 'unchanged', 'changed'),
	'VereinePlaceholderGroup_' => array_keys(VereinePlaceholders::KEYS),
	'VereineTaxCheckReason_' => VereineTaxCheckRules::REASONS,
	'VereineAuditPoint_' => VereineAuditRules::POINTS,
	'VereineAuditPointHelp_' => VereineAuditRules::POINTS,
	'VereineAuditState_' => VereineAuditRules::STATES,
	'VereineAuditHint_' => VereineAuditRules::HINTS,
	'VereineAuditElement_' => array('bank', 'invoice', 'supplier'),
	'VereineAuditConclusion_' => array('open', 'confirmed', 'defects'),
	'VereineAudit_' => array('audit_day', 'statement_day', 'documents', 'note'),
	'VereinePh_' => array_map(function ($key) {
		return substr(VereinePlaceholders::describedBy($key), strlen('VereinePh_'));
	}, array_merge(VereinePlaceholders::KEYS['association'], VereinePlaceholders::KEYS['member'], VereinePlaceholders::KEYS['meeting'], VereinePlaceholders::KEYS['circular'])),
	'VereineResolutionCategory_' => VereineResolutionRules::CATEGORIES,
	'VereineCircularChoice_' => VereineCircularRules::CHOICES,
	'VereineMeetingDocKind_' => VereineMeetingDocRules::KINDS,
	'VereineMailError_' => VereineMailRules::CAUSES,
	'VereineCircularStatus_' => array('open', 'cancelled', 'objection'),
	'VereineMinutesAudience_' => array('board', 'members'),
	'VereineMinutesSend_' => array('board', 'members'),
	'VereineApiEndpoint_' => array_column(VereineApiRules::endpoints($openapi), 'operation'),
	'VereineLetterKind_' => array_merge(array(VereineAuthorityRules::KIND_REPRESENTATIVES), VereineAuthorityRules::KINDS),
	'VereineLetterTitle_' => array_merge(array(VereineAuthorityRules::KIND_REPRESENTATIVES), VereineAuthorityRules::KINDS),
	'VereineLetterHelp_' => VereineAuthorityRules::KINDS,
	'VereineLetterDate_' => array_values(array_diff(VereineAuthorityRules::KINDS, array(VereineAuthorityRules::KIND_FOUNDING))),
	'VereineLetterErrorDate_' => array_values(array_diff(VereineAuthorityRules::KINDS, array(VereineAuthorityRules::KIND_FOUNDING))),
	'VereineLetterBody_extract_' => VereineAuthorityRules::EXTRACTS,
	'VereineLetterExtract_' => VereineAuthorityRules::EXTRACTS,
	'VereineLetter_' => array('liquidator_name', 'liquidator_birth', 'liquidator_birth_place', 'liquidator_address', 'liquidator_start'),
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

// ------------------------------------------------------------ minutes texts

$templates = VereineMinutesRules::defaults();
same(array('Begrüßung und Feststellung der Beschlussfähigkeit', true), array($templates['general'][0]['title'], $templates['general'][0]['required']), 'a general assembly starts with the quorum, required');
same(array(), VereineMinutesRules::missing($templates, 'general', VereineMinutesRules::agenda($templates, 'general')), 'an agenda from the Austrian template lacks no required item');
same(array('Rechenschaftsbericht des Vorstands', 'Bericht über den Rechnungsabschluss'), VereineMinutesRules::missing($templates, 'general', array('begrüßung und  feststellung der beschlussfähigkeit', 'Wahlen')),
	'the report on activity and finances is required in Austria, titles compared regardless of case and spaces');
$own = VereineMinutesRules::normalize(array('board' => array(array('title' => ' <b>Kassa</b>  prüfen ', 'text' => "Zeile 1\r\nZeile 2", 'required' => '1'), array('title' => '', 'text' => 'ohne Titel'))));
same(array(array('title' => 'Kassa prüfen', 'text' => "Zeile 1\nZeile 2", 'required' => true)), $own['board'], 'own template: tags and empty titles removed, lines kept');
same($templates['general'], $own['general'], 'kinds without own items keep the template of the profile');
same('{ergebnis}', VereineMinutesRules::textFor($templates, 'general', 'WAHLEN'), 'the text of an item is found by its title');
same('', VereineMinutesRules::textFor($templates, 'board', 'Wahlen'), 'no text for an item the template lacks');
same(array(1 => 2, 3 => 1), VereineMinutesRules::remap(array('A', 'B', 'C'), array('C', 'A')), 'texts follow their titles when the agenda changes, removed items drop out');
same(array(1 => 1, 2 => 2), VereineMinutesRules::remap(array('Wahl', 'Wahl'), array('Wahl', 'Wahl', 'X')), 'items with the same title keep their order');
$meeting = array('kind' => 'general', 'format' => 'physical', 'place' => 'Vereinsheim');
$quorum = array('eligible' => 12, 'present' => 5, 'represented' => 2, 'votes' => 7, 'required' => 6, 'reached' => true);
$values = VereineMinutesRules::values($meeting, $quorum, array(array('title' => 'Budget', 'yes' => 6, 'no' => 1, 'abstain' => 0, 'passed' => true, 'secret' => false)), 'Testverein', '1. März 2026');
same('Anwesend sind 5 Stimmberechtigte, vertreten 2, zusammen 7 von 12 Stimmen; nötig sind 6. Die Versammlung ist beschlussfähig.',
	substr(VereineMinutesRules::fill($templates['general'][0]['text'], $values), strpos(VereineMinutesRules::fill($templates['general'][0]['text'], $values), 'Anwesend sind')), 'placeholders filled with the real numbers');
same('Budget: angenommen mit 6 Ja, 1 Nein und 0 Enthaltungen. {unbekannt}', VereineMinutesRules::fill('{ergebnis} {unbekannt}', $values), 'result of the vote filled, unknown placeholders stay');
same('Keine Abstimmung.', VereineMinutesRules::values($meeting, $quorum, array(), 'V', 'D')['ergebnis'], 'an item without vote says so');
same('1', VereineMinutesRules::values(array('kind' => 'board', 'format' => 'virtual', 'place' => 'x'), array('eligible' => 2, 'present' => 0, 'represented' => 0, 'votes' => 0, 'required' => 0, 'reached' => false), array(), 'V', 'D')['quorum'],
	'a board needs at least one member present');
same('virtuell', VereineMinutesRules::values(array('kind' => 'board', 'format' => 'virtual', 'place' => 'x'), $quorum, array(), 'V', 'D')['ort'], 'a virtual meeting takes place virtually');

// ------------------------------------------------------------- kinds of agenda items

// The agenda of a real board meeting (02.10.2026): most items are reports and discussions.
$kindsOfItems = array(
	'Begrüßung und Feststellung der Beschlussfähigkeit' => 'discussion',
	'Kurzer Rückblick seit der letzten Vorstandssitzung' => 'report',
	'Bericht des Obmanns / der Vorstandsmitglieder über laufende Angelegenheiten' => 'report',
	'Finanzieller Überblick – aktueller Kontostand, Einnahmen, Ausgaben und offene Zahlungen' => 'report',
	'Planung des restlichen Jahres 2026' => 'discussion',
	'Finanzplanung und Budgetrahmen für 2027' => 'decision',
	'Festlegung konkreter Aufgaben mit Verantwortlichen und Terminen' => 'decision',
	'Wahl des Kassiers' => 'election',
	'Genehmigung des Rechnungsabschlusses' => 'decision',
	'Allfälliges' => 'discussion',
);
foreach ($kindsOfItems as $title => $kind) {
	same($kind, VereineMinutesRules::kindOf($title), 'the kind suggested for "'.$title.'"');
}
expect(VereineMinutesRules::votes('decision') && VereineMinutesRules::votes('election') && !VereineMinutesRules::votes('report') && !VereineMinutesRules::votes('discussion'),
	'only decisions and elections are voted on');

// ------------------------------------------------------------- steps of a meeting

$stepMeeting = array('status' => 'planned', 'day' => '2026-10-02', 'agenda' => array('Begrüßung'));
same(array('plan' => 'done', 'invite' => 'now', 'meet' => 'later', 'minutes' => 'later', 'close' => 'later'),
	VereineMeetingRules::steps($stepMeeting, array(), '2026-09-21'), 'a planned meeting: invite is next');
same('now', VereineMeetingRules::steps(array('status' => 'invited') + $stepMeeting, array('invited' => false), '2026-09-21')['invite'],
	'invited in the status, but no e-mail went out: inviting is still to do');
same(array('plan' => 'done', 'invite' => 'done', 'meet' => 'later', 'minutes' => 'later', 'close' => 'later'),
	VereineMeetingRules::steps(array('status' => 'invited') + $stepMeeting, array('invited' => true), '2026-09-21'), 'invited, the day is still to come');
same('now', VereineMeetingRules::steps(array('status' => 'invited') + $stepMeeting, array('invited' => true), '2026-10-02')['meet'], 'on the day the meeting is on');
same(array('meet' => 'done', 'minutes' => 'now'), array_intersect_key(VereineMeetingRules::steps(array('status' => 'held') + $stepMeeting, array('invited' => true), '2026-10-03'),
	array('meet' => 1, 'minutes' => 1)), 'held: the minutes are next');
same(array('minutes' => 'done', 'close' => 'now'), array_intersect_key(VereineMeetingRules::steps(array('status' => 'held') + $stepMeeting,
	array('invited' => true, 'final' => true), '2026-10-03'), array('minutes' => 1, 'close' => 1)), 'a final version: signing and sending are next');
same('done', VereineMeetingRules::steps(array('status' => 'held') + $stepMeeting, array('invited' => true, 'final' => true, 'signed' => true), '2026-10-03')['close'],
	'signed: everything is done');
expect(count(array_filter(VereineMeetingRules::steps($stepMeeting, array(), '2026-09-21'), function ($state) {
	return $state === 'now';
})) === 1, 'only one step is "now"');

// ------------------------------------------------------------- text repair

same("Gilmstraße 2\n6020 Innsbruck", VereineTextRepair::text('Gilmstraße 2\n6020 Innsbruck'), 'a stored \\n becomes a line break');
same("a\nb\nc", VereineTextRepair::text('a\r\nb\rc'), 'stored \\r\\n and \\r become line breaks too');
same(array('activities' => array('Turniere', 'Training', 'Liga'), 'asset_purpose' => "Zeile 1\nZeile 2", 'arrears_months' => 3),
	VereineTextRepair::statuteText(array('activities' => array('Turniere\nTraining', 'Liga'), 'asset_purpose' => 'Zeile 1\nZeile 2', 'arrears_months' => 3)),
	'statute text: lists split into items, texts get line breaks, numbers stay');

// ------------------------------------------------------------------- result

if ($failures) {
	fwrite(STDERR, count($failures)." of ".$assertions." assertions failed:\n  - ".implode("\n  - ", $failures)."\n");
	exit(1);
}
print 'Unit tests: OK ('.$assertions." assertions)\n";
exit(0);
