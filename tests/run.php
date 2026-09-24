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
require_once $root.'/class/vereinedutyrules.class.php';
require_once $root.'/class/vereineeventrules.class.php';
require_once $root.'/class/vereineshiftrules.class.php';
require_once $root.'/class/vereineassemblyrules.class.php';
require_once $root.'/class/vereinechangerules.class.php';
require_once $root.'/class/vereinehookrules.class.php';
require_once $root.'/class/vereineidentityrules.class.php';
require_once $root.'/class/vereineapplicationformrules.class.php';
require_once $root.'/class/vereinevolunteerrules.class.php';
require_once $root.'/class/vereineoverpaymentrules.class.php';
require_once $root.'/class/vereinedonationrules.class.php';
require_once $root.'/class/vereinesetupguiderules.class.php';
require_once $root.'/class/vereinearchiverules.class.php';
require_once $root.'/class/vereinedisclosurerules.class.php';
require_once $root.'/class/vereinedisclosure.class.php';
require_once $root.'/class/vereineerasurerules.class.php';
require_once $root.'/class/vereinearrearrules.class.php';
require_once $root.'/class/vereinesocialrules.class.php';
require_once $root.'/class/vereinehonourrules.class.php';
require_once $root.'/class/vereineloanrules.class.php';
require_once $root.'/class/vereinepublicationrules.class.php';
require_once $root.'/class/vereinestatuteversionrules.class.php';
require_once $root.'/class/vereineaccountrules.class.php';
require_once $root.'/class/vereinememberform.class.php';
require_once $root.'/class/vereineapplicationrules.class.php';
require_once $root.'/class/vereinepdf.class.php';
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
	array('country_profile', 'country_profile_complete', 'name', 'register', 'authority', 'address', 'email', 'phone', 'url', 'founded', 'nonprofit', 'purpose', 'fiscal_year_start_month', 'channels'),
	array_keys($organization),
	'API version 1 fields and their order, channels added at the end'
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
	'country_code' => 'at', 'type_id' => '5',
	'consents' => array(array('code' => 'fotos', 'version' => 2, 'granted_at' => '2026-09-22T19:30:00+02:00', 'form' => 'Beitrittsformular', 'reference' => 'web-42')));
$checked = VereineConsentRules::application($valid, $types, $texts);
same(array(), $checked['errors'], 'a complete application');
same(array('AT', 5, array('fotos' => 2), 'phy'), array($checked['application']['country_code'], $checked['application']['type_id'],
	array_map(function ($consent) {
		return $consent['version'];
	}, $checked['application']['consents']), $checked['application']['morphy']), 'the application is normalised');
same(array('at' => '2026-09-22 19:30:00', 'form' => 'Beitrittsformular', 'ref' => 'web-42'), $checked['application']['consents']['fotos']['proof'],
	'the application carries how the consent was given');
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
expect(in_array('money', VereineSignatureRules::KINDS, true) && count(VereineSignatureRules::KINDS) === 7, 'seven kinds of document');
same(array('obmann', 'kassier'), VereineSignatureRules::defaults()['payout']['roles'], 'a volunteer payout is signed by the chair and the treasurer');
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

// ------------------------------------------------------------- income and expenditure account (§ 21 (1) VerG)

same('transfer', VereineAccountRules::kindOf(array('company', 'banktransfert')), 'a transfer between own accounts, whatever else is linked');
same('invoice', VereineAccountRules::kindOf(array('company', 'payment')), 'the payment of an invoice');
same('fee', VereineAccountRules::kindOf(array('member')), 'a membership fee booked on the member');
same('opening', VereineAccountRules::kindOf(array('initial')), 'the initial balance of a new account');
same('unlinked', VereineAccountRules::kindOf(array('company')), 'only a third party: no payment behind it');
same('ideal', VereineAccountRules::sphereOf('donation'), 'donations belong to the ideal area');
same('unassigned', VereineAccountRules::sphereOf('various'), 'a various payment is not guessed');
same(array('ideal' => 50.0, 'harmful' => 120.0), VereineAccountRules::split(170, array(array('sphere' => 'ideal', 'total' => 50), array('sphere' => 'harmful', 'total' => 120))),
	'a full payment over two areas');
same(array('ideal' => 33.33, 'harmful' => 66.67), VereineAccountRules::split(100, array(array('sphere' => 'ideal', 'total' => 10), array('sphere' => 'harmful', 'total' => 20))),
	'a part payment in the shares of the lines, to the cent');
same(array('ideal' => 30.0, 'unassigned' => 20.0), VereineAccountRules::split(50, array(array('sphere' => 'ideal', 'total' => 30), array('sphere' => '', 'total' => 20))),
	'a line without profile stays unassigned');
same(array('unassigned' => 12.5), VereineAccountRules::split(12.5, array()), 'no lines: unassigned');
same(array('ideal' => -100.0), VereineAccountRules::split(-100, array(array('sphere' => 'ideal', 'total' => 300))), 'a supplier payment keeps its sign');
same('income', VereineAccountRules::side('invoice', -20), 'a refund of an invoice is negative income');
same('expense', VereineAccountRules::side('supplier', -100), 'a supplier payment is an expense');
same('expense', VereineAccountRules::side('unlinked', -40), 'a booking without payment counts by its sign');
same('', VereineAccountRules::side('transfer', -50), 'a transfer is neither income nor expense');
same('', VereineAccountRules::side('opening', 100), 'an initial balance is neither income nor expense');
$accountTotals = VereineAccountRules::totals(array(
	array('kind' => 'invoice', 'amount' => 170, 'parts' => array('harmful' => 120, 'ideal' => 50)),
	array('kind' => 'supplier', 'amount' => -100, 'parts' => array('ideal' => -100)),
	array('kind' => 'unlinked', 'amount' => -40, 'parts' => array('unassigned' => -40)),
	array('kind' => 'transfer', 'amount' => -50, 'parts' => array('unassigned' => -50)),
	array('kind' => 'fee', 'amount' => 30, 'parts' => array('ideal' => 30)),
));
same(array('ideal', 'harmful'), array_keys($accountTotals['income']), 'areas in the order of the account');
same(array('invoice' => 50.0, 'fee' => 30.0), $accountTotals['income']['ideal'], 'kinds within an area');
same(array('ideal' => array('supplier' => 100.0), 'unassigned' => array('unlinked' => 40.0)), $accountTotals['expense'], 'expenses count positive');
same(array('income' => 200.0, 'expense' => 140.0, 'result' => 60.0), $accountTotals['totals'], 'the transfer counts nowhere');
expect(VereineAccountRules::reconciled(100, 5020, 5120) && !VereineAccountRules::reconciled(100, 5020, 5119.99), 'opening plus result is the closing balance, to the cent');
same('2024-12-31', VereineAccountRules::dayBefore('2025-01-01'), 'the day before the year');
same('2024-02-29', VereineAccountRules::dayBefore('2024-03-01'), 'the day before March in a leap year');
same('2026-05-31', VereineAccountRules::deadline('2025-12-31'), 'five months after a calendar year');
same('2026-11-30', VereineAccountRules::deadline('2026-06-30'), 'a year to June: until the end of November');
same('2027-07-31', VereineAccountRules::deadline('2027-02-28'), 'a year to February: until the end of July');
same(array(), VereineAccountRules::sizeWarnings(array('income' => 1200000, 'expense' => 900000), array('income' => 800000, 'expense' => 990000)), 'one large year is not enough');
same(array('VereineAccountSizeLarge'), VereineAccountRules::sizeWarnings(array('income' => 1200000, 'expense' => 0), array('income' => 0, 'expense' => 1100000)),
	'income or expenses above one million in two years in a row');
same(array('VereineAccountSizeLarge', 'VereineAccountSizeVeryLarge'), VereineAccountRules::sizeWarnings(array('income' => 3500000, 'expense' => 0), array('income' => 3100000, 'expense' => 0)),
	'above three million an auditor as well');
same(array(array('label' => 'Beamer', 'amount' => 400.0, 'kind' => 'asset'), array('label' => 'Darlehen', 'amount' => 1000.5, 'kind' => 'debt')),
	VereineAccountRules::extras(array(array('label' => ' Beamer ', 'amount' => '400', 'kind' => 'x'), array('label' => 'Darlehen', 'amount' => '-1000,50', 'kind' => 'debt'),
		array('label' => '', 'amount' => '5'), array('label' => 'Null', 'amount' => '0'), 'kaputt')), 'entered rows: a label and an amount, a debt by its kind');
same(30, count(VereineAccountRules::extras(array_fill(0, 40, array('label' => 'x', 'amount' => 1)))), 'at most 30 further assets and debts');
expect(VereineAccountRules::assignable('various') && VereineAccountRules::assignable('unlinked') && !VereineAccountRules::assignable('invoice')
	&& !VereineAccountRules::assignable('transfer') && !VereineAccountRules::assignable('fee'), 'only bookings without invoice lines take a chosen area');
same(array('ideal', 'assets', 'essential', 'auxiliary', 'festival', 'harmful'), VereineAccountRules::areas(), 'every area but unassigned can be chosen');
same(array(7 => 'ideal', 9 => ''), VereineAccountRules::assignments(array('7' => 'ideal', '8' => 'harmful', '9' => '', '10' => 'unassigned', 'x' => 'ideal', '11' => array('ideal')),
	array(7, 9, 10, 11)), 'only bookings that may take an area and only known areas; empty removes it');
same(array(), VereineAccountRules::assignments('kaputt', array(7)), 'nothing entered, nothing stored');

// ------------------------------------------------------------- application for membership (#108)

same(array('birth', 'email'), VereineMemberForm::requiredFields('email,birth,name'), 'only fields of the form, in their order');
same(array(), VereineMemberForm::requiredFields(''), 'nothing required');
same(array('gender', 'phone'), VereineMemberForm::requiredFields(array('phone', 'gender', array('birth'))), 'entered as an array, nonsense left out');
expect(VereineMemberForm::isMinor('2010-09-23', '2026-09-22') && !VereineMemberForm::isMinor('2008-09-22', '2026-09-22'),
	'under age until the day of the eighteenth birthday');
expect(!VereineMemberForm::isMinor('', '2026-09-22') && !VereineMemberForm::isMinor('2010-09-23', ''), 'without a date of birth nobody is counted as a minor');
same(array('at' => '2026-09-22 19:30:00', 'form' => 'Beitritt', 'ref' => 'A-17'),
	VereineConsentRules::proof(array('at' => '2026-09-22T19:30:00Z', 'form' => 'Beitritt', 'ref' => 'A-17')), 'the moment of a website, its form and its reference');
same(array('at' => '2026-09-22 00:00:00', 'form' => '', 'ref' => ''), VereineConsentRules::proof(array('at' => '2026-09-22')), 'a day without a time');
same(array('at' => '', 'form' => '', 'ref' => ''), VereineConsentRules::proof(array('at' => 'gestern', 'form' => array('x'))), 'nonsense is left out');
same(array('at' => '', 'form' => '', 'ref' => ''), VereineConsentRules::proof(null), 'no proof at all');
same(array('application', 'consent'), VereinePdf::fillableKinds('consent,application,unsinn'), 'documents with fields to fill in, in their order');
same(array('consent'), VereinePdf::fillableKinds(array('consent')), 'entered as an array');
same(array(), VereinePdf::fillableKinds(''), 'nothing to fill in: only to print');

// ------------------------------------------------------------- the way of a membership application (#72)

expect(VereineApplicationRules::allows('received', 'accepted') && VereineApplicationRules::allows('in_review', 'rejected')
	&& VereineApplicationRules::allows('received', 'withdrawn'), 'an application that is not settled can be decided');
expect(!VereineApplicationRules::allows('accepted', 'withdrawn') && !VereineApplicationRules::allows('withdrawn', 'accepted')
	&& !VereineApplicationRules::allows('rejected', 'in_review'), 'a settled application stays as it is');
same('Kein Platz in der Mannschaft', VereineApplicationRules::reason(' <b>Kein Platz in der Mannschaft</b> '), 'the reason for the person is plain text');
same(500, strlen(VereineApplicationRules::reason(str_repeat('x', 600))), 'a reason is cut at 500 characters');
$applicationOne = array('firstname' => 'Anna', 'lastname' => 'Antrag', 'email' => 'anna@example.org', 'type_id' => 5,
	'consents' => array('fotos' => array('version' => 2)));
$applicationTwo = $applicationOne;
$applicationTwo['consents'] = array('fotos' => 2);
same(VereineApplicationRules::fingerprint($applicationOne), VereineApplicationRules::fingerprint($applicationTwo),
	'the fingerprint reads a consent as version, however it was stored');
$applicationTwo['email'] = 'anders@example.org';
expect(VereineApplicationRules::fingerprint($applicationOne) !== VereineApplicationRules::fingerprint($applicationTwo),
	'other content gives another fingerprint');

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
	'VereineLog_' => array('partner_created', 'partner_linked', 'partner_suggested', 'partner_attributes', 'partner_updated', 'partner_error', 'partner_unlinked', 'fee_invoice', 'fee_run', 'fee_error', 'fee_period', 'fee_direct_debit', 'exit_planned', 'exit_done', 'exit_cancelled', 'exit_error', 'consent_given', 'consent_withdrawn', 'application_received', 'function_start', 'function_end', 'function_reported', 'function_report_pdf', 'function_group_add', 'function_group_remove', 'statute_rules', 'authority_letter', 'authority_letter_filed', 'statute_text', 'statute_version', 'meeting_created', 'meeting_invited', 'meeting_status', 'meeting_attendance', 'meeting_vote', 'signature_rules', 'signature_started', 'signature_signed', 'signature_done', 'minutes_final', 'minutes_sent', 'resolution_added', 'resolution_saved', 'resolution_task', 'resolution_task_done', 'circular_started', 'circular_vote', 'circular_reminded', 'circular_decided', 'circular_cancelled', 'meeting_document', 'qes_setup', 'qes_signed', 'tax_profile_set', 'audit_saved', 'audit_checked', 'audit_report', 'account_saved', 'account_pdf', 'account_assigned', 'application_pdf', 'consent_form', 'consent_scan', 'application_decided', 'duty_saved', 'duty_removed', 'duty_planned', 'duty_handover', 'duty_done', 'event_template', 'event_created', 'event_task', 'event_task_done', 'event_status', 'event_report', 'shift_saved', 'shift_signup', 'shift_done', 'hook_target', 'hook_rotated', 'hook_retry', 'identity_invite', 'identity_linked', 'identity_revoked', 'volunteer_recorded', 'volunteer_removed', 'volunteer_payout', 'volunteer_paid', 'overpayment_assigned', 'donation_setup', 'donation_donor', 'donation_szr', 'donation_report', 'donation_protocol', 'setup_guide', 'application_field', 'archive', 'disclosure', 'erasure', 'erasure_hold', 'erasure_setup', 'arrear', 'channel', 'social_setup', 'social_linked', 'social_unlinked', 'honour', 'loan', 'loan_returned', 'loan_reminded', 'publication'),
	'VereineDutyState_' => array('overdue', 'due', 'ahead', 'done'),
	'VereineEventState_' => array('overdue', 'due', 'ahead', 'done'),
	'VereineEventPhase_' => VereineEventRules::PHASES,
	'VereineEventStatus_' => VereineEventRules::STATUSES,
	'VereineEventRegistration_' => VereineEventRules::REGISTRATIONS,
	'VereineShiftStatus_' => VereineShiftRules::STATUSES,
	'VereineShiftTo_' => array('confirmed', 'done', 'cancelled'),
	'VereineAssemblyPhase_' => VereineAssemblyRules::PHASES,
	'VereineAssemblyState_' => array('done', 'overdue', 'now', 'later', 'none'),
	'VereineAssemblyStep_' => array_keys(VereineAssemblyRules::STEPS),
	'VereineAssemblyHelp_' => array_keys(VereineAssemblyRules::STEPS),
	'VereineHookStatus_' => VereineHookRules::STATUSES,
	'VereineIdentityCapability_' => VereineIdentityRules::CAPABILITIES,
	'VereineIdentityProof_' => VereineIdentityRules::PROOFS,
	'VereineIdentityInviteState_' => array('open', 'used', 'expired'),
	'VereineApplicationExtra_' => array('off', 'optional', 'required'),
	'VereineVolunteerKind_' => VereineVolunteerRules::KINDS,
	'VereineOverpaymentState_' => array_merge(array(VereineOverpaymentRules::STATE_OPEN, VereineOverpaymentRules::STATE_DOLIBARR), VereineOverpaymentRules::KINDS),
	'VereineOverpaymentWay_' => VereineOverpaymentRules::KINDS,
	'VereineOverpaymentPreview_' => VereineOverpaymentRules::KINDS,
	'VereineOverpaymentDo_' => VereineOverpaymentRules::KINDS,
	'VereineOverpaymentAssigned_' => VereineOverpaymentRules::KINDS,
	'VereineOverpaymentShow_' => array('open', 'all'),
	'VereineDonationKind_' => array_map(array('VereineDonationRules', 'kindKey'), VereineDonationRules::KINDS),
	'VereineDonationVbpkState_' => VereineDonationRules::STATES,
	'VereineDonationType_' => array('E', 'A', 'S'),
	'VereineDonationProtocol_' => array('ok', 'twok', 'nok'),
	'VereineSetupState_' => VereineSetupGuideRules::STATES,
	'VereineApplicationKind_' => VereineApplicationFormRules::KINDS,
	'VereineArchiveKind_' => VereineArchiveRules::KINDS,
	'VereineArchiveFile_' => VereineArchiveRules::FILES,
	'VereineDisclosureSection_' => VereineDisclosureRules::SECTIONS,
	'VereineDisclosureCheck_' => VereineDisclosureRules::CHECKS,
	'VereineDisclosureField_' => array_keys(VereineDisclosure::fieldWords()),
	'VereineErasureKind_' => array_keys(VereineErasureRules::CATEGORIES),
	'VereineErasureWhat_' => array_keys(VereineErasureRules::CATEGORIES),
	'VereineErasureWhy_' => array_keys(VereineErasureRules::CATEGORIES),
	'VereineErasureStart_' => array(VereineErasureRules::START_EXIT, VereineErasureRules::START_YEAR, VereineErasureRules::START_ENTRY),
	'VereineErasureAction_' => array(VereineErasureRules::ACTION_BLANK, VereineErasureRules::ACTION_DELETE, VereineErasureRules::ACTION_ANONYMIZE, VereineErasureRules::ACTION_KEEP),
	'VereineErasureState_' => array('due', 'waiting', 'kept', 'held', 'none', 'member'),
	'VereineArrearState_' => VereineArrearRules::STATES,
	'VereineStatisticsGender_' => array('woman', 'man', 'other', 'unknown'),
	'VereineLoanState_' => array('available', 'out', 'overdue', 'returned'),
	'VereinePublicationAudience_' => array('none', 'board', 'members', 'public'),
	'VereineHonourKind_' => array('jubilee', 'honorary'),
	'VereineErasureReason_' => array('keep_bookkeeping', 'keep_records', 'member', 'hold', 'open_invoices', 'functions', 'name'),
	'VereineSetupStep_' => VereineSetupGuideRules::STEPS,
	'VereineSetupStepHelp_' => VereineSetupGuideRules::STEPS,
	'VereineSetupModule_' => array('banque', 'facture', 'prelevement', 'agenda', 'mailing', 'don', 'api', 'webportal'),
	'VereineVolunteerLimit_' => VereineVolunteerRules::KINDS,
	'VereineVolunteerFinding_' => VereineVolunteerRules::FINDINGS,
	'VereineVolunteerPayoutStatus_' => array('draft', 'paid'),
	'VereineDutyBasis_' => VereineDutyRules::BASES,
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
	'VereineAccountKind_' => VereineAccountRules::KINDS,
	'VereineSphereShort_' => VereineAccountRules::SPHERES,
	'VereineAccountSide_' => array('income', 'expense'),
	'VereineAccountTotal_' => array('income', 'expense'),
	'VereineBankLabel_' => array('DefaultCashPOSLabel'),
	'VereineApplicationField_' => VereineMemberForm::FIELDS,
	'VereineApplicationPeriod_' => array('y', 'm', 'w', 'd'),
	'VereineApplicationPeriodOne_' => array('y', 'm', 'w', 'd'),
	'VereineApplicationProration_' => array('month', 'quarter', 'half_year'),
	'VereineApplicationFillable_' => VereinePdf::FILLABLE_KINDS,
	'VereineApplicationStatus_' => VereineApplicationRules::STATUSES,
	'VereineSepaStatus_' => array(VereineSepa::MANDATE_VALID, VereineSepa::MANDATE_EXPIRED, VereineSepa::MANDATE_NONE),
	'VereineTodoState_' => array('overdue', 'due', 'open'),
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
	'VereineApplicationExit_' => VereineExitRules::ATS,
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

// ------------------------------------------------------------- calendar of duties (#24)

$dutyStandard = array();
foreach (VereineDutyRules::standard() as $duty) {
	$dutyStandard[$duty['code']] = $duty;
}
expect(isset($dutyStandard['account'], $dutyStandard['audit'], $dutyStandard['assembly'], $dutyStandard['donations']),
	'the catalogue starts with the duties of Austrian law');
same('kassier', $dutyStandard['donations']['function_code'], 'the report of donations belongs to the treasurer');
expect($dutyStandard['donations']['active'] === 0 && $dutyStandard['account']['active'] === 1,
	'what only some associations do is switched off until they switch it on');

// The day a duty is due follows the association's year, also when it does not start in January.
same('2026-05-31', VereineDutyRules::due($dutyStandard['account'], '2025-12-31'), 'the account of 2025 is due end of May 2026');
same('2026-11-30', VereineDutyRules::due($dutyStandard['account'], '2026-06-30'), 'a year to June has five months, so end of November');
same('2026-09-29', VereineDutyRules::due($dutyStandard['audit'], '2025-12-31', '2026-05-29'),
	'the audit runs four months from the day the account was made');
same('2026-09-30', VereineDutyRules::due($dutyStandard['audit'], '2025-12-31', '', '2026-05-31'),
	'while the account is not made, its own deadline counts');
same('2027-02-28', VereineDutyRules::due($dutyStandard['donations'], '2026-06-30'),
	'the report of donations is due end of February of the calendar year after the year of the association');
same('2028-02-29', VereineDutyRules::due($dutyStandard['donations'], '2027-12-31'), 'the end of February is the 29th in a leap year');
same('', VereineDutyRules::due($dutyStandard['report'], '2025-12-31'), 'what follows an event has no day of the year');

// The end of a month stays the end of a month.
same('2026-02-28', VereineDutyRules::addMonths('2025-12-31', 2), 'two months after 31 December is the last day of February');
same('2026-01-31', VereineDutyRules::addMonths('2025-12-31', 1), 'a month with 31 days keeps the 31st');
same('2025-11-30', VereineDutyRules::addMonths('2025-12-31', -1), 'counting back works too');

// How a duty stands.
same('overdue', VereineDutyRules::state('2026-05-31', '2026-06-01', false), 'a day that passed is overdue');
same('due', VereineDutyRules::state('2026-05-31', '2026-05-01', false), 'within the lead time the deadline runs');
same('ahead', VereineDutyRules::state('2026-05-31', '2026-01-01', false), 'far ahead there is still time');
same('done', VereineDutyRules::state('2026-05-31', '2026-06-01', true), 'what is done is done, however late');
same('ahead', VereineDutyRules::state('2026-05-31', '2026-05-01', false, 10), 'a short lead time keeps it ahead');

// Duties that do not come back every year.
$everyThree = array('active' => 1, 'basis' => VereineDutyRules::BASIS_YEAR_END, 'every_years' => 3, 'first_year' => 2025);
expect(VereineDutyRules::planned($everyThree, 2025) && !VereineDutyRules::planned($everyThree, 2026)
	&& VereineDutyRules::planned($everyThree, 2028), 'every three years means 2025, 2028, and nothing in between');
expect(!VereineDutyRules::planned(array('active' => 0, 'basis' => VereineDutyRules::BASIS_YEAR_END) + $everyThree, 2025),
	'a duty that is switched off is not planned');
expect(!VereineDutyRules::planned(array('active' => 1, 'basis' => VereineDutyRules::BASIS_EVENT, 'every_years' => 1), 2025),
	'what follows an event is not planned by the year either');

// What the catalogue refuses.
same(array(), VereineDutyRules::validate(array('code' => 'sponsorenbericht', 'label' => 'Bericht an Sponsoren',
	'basis' => VereineDutyRules::BASIS_CALENDAR, 'due_month' => '3', 'due_day' => '15', 'every_years' => '1', 'lead_days' => '30')),
	'a sensible own duty is fine');
same(array('VereineDutyErrorCode'), VereineDutyRules::validate(array('code' => 'Sponsoren Bericht', 'label' => 'x',
	'basis' => VereineDutyRules::BASIS_EVENT, 'every_years' => '1', 'lead_days' => '30')), 'a code with a blank is refused');
same(array('VereineDutyErrorDay'), VereineDutyRules::validate(array('code' => 'bericht', 'label' => 'x',
	'basis' => VereineDutyRules::BASIS_CALENDAR, 'due_month' => '13', 'due_day' => '1', 'every_years' => '1', 'lead_days' => '30')),
	'a thirteenth month is refused');
same(array('VereineDutyErrorLabel', 'VereineDutyErrorEveryYears'), VereineDutyRules::validate(array('code' => 'bericht', 'label' => '',
	'basis' => VereineDutyRules::BASIS_EVENT, 'every_years' => '0', 'lead_days' => '30')), 'everything wrong is named at once');

// ------------------------------------------------------------- events from templates (#23)

$eventTemplates = array();
foreach (VereineEventRules::standard() as $template) {
	$eventTemplates[$template['code']] = $template;
}
expect(isset($eventTemplates['turnier'], $eventTemplates['vereinsfest']), 'a tournament and a club festival are suggested');
$phases = array();
foreach ($eventTemplates['turnier']['tasks'] as $point) {
	$phases[$point['phase']] = true;
}
same(array('before', 'during', 'after'), array_keys($phases), 'the tournament has points in every phase, in their order');
$announce = array();
foreach ($eventTemplates['vereinsfest']['tasks'] as $point) {
	if (strpos($point['label'], 'Gemeinde anzeigen') !== false) {
		$announce = $point;
	}
}
expect($announce && $announce['offset_days'] === -56 && $announce['source'] !== '',
	'the notice to the municipality is due eight weeks ahead and names where it comes from');

// The day of a point follows the day of the event.
same('2026-11-07', VereineEventRules::due('2026-12-05', -28), 'four weeks before 5 December is 7 November');
same('2026-12-05', VereineEventRules::due('2026-12-05', 0), 'a point of the day itself falls on the day');
same('2026-12-19', VereineEventRules::due('2026-12-05', 14), 'two weeks afterwards crosses no year');
same('2027-01-02', VereineEventRules::due('2026-12-19', 14), 'two weeks afterwards may cross the year');
same('', VereineEventRules::due('nicht ein tag', -7), 'without a day there is no deadline');

// How a point stands.
same('overdue', VereineEventRules::state('2026-11-07', '2026-11-08', false), 'a day that passed is overdue');
same('due', VereineEventRules::state('2026-11-07', '2026-11-01', false), 'within a fortnight the deadline runs');
same('ahead', VereineEventRules::state('2026-11-07', '2026-10-01', false), 'far ahead there is still time');
same('done', VereineEventRules::state('2026-11-07', '2026-11-08', true), 'what is done is done, however late');

// How far an event has come.
$points = array(
	array('due_on' => '2026-11-01', 'done_on' => '2026-10-30'),
	array('due_on' => '2026-11-07', 'done_on' => ''),
	array('due_on' => '2026-12-05', 'done_on' => ''),
	array('due_on' => '2026-12-19', 'done_on' => ''),
);
same(array('total' => 4, 'done' => 1, 'overdue' => 1, 'percent' => 25), VereineEventRules::progress($points, '2026-11-10'),
	'one of four done, one overdue, a quarter of the way');
same(array('total' => 0, 'done' => 0, 'overdue' => 0, 'percent' => 0), VereineEventRules::progress(array(), '2026-11-10'),
	'an empty checklist is not a division by zero');

// What the templates refuse.
same(array(), VereineEventRules::validateTemplate(array('code' => 'lanparty', 'label' => 'LAN-Party')), 'a sensible own template is fine');
same(array('VereineEventErrorCode'), VereineEventRules::validateTemplate(array('code' => 'LAN Party', 'label' => 'LAN-Party')),
	'a code with a blank is refused');
same(array(), VereineEventRules::validateTask(array('phase' => 'before', 'label' => 'Halle buchen', 'offset_days' => '-30')),
	'a sensible point is fine');
same(array('VereineEventErrorPhase'), VereineEventRules::validateTask(array('phase' => 'irgendwann', 'label' => 'Halle buchen', 'offset_days' => '-30')),
	'a phase that does not exist is refused');
same(array('VereineEventErrorOffset'), VereineEventRules::validateTask(array('phase' => 'before', 'label' => 'Halle buchen', 'offset_days' => '-400')),
	'more than a year ahead is refused');

// What an event refuses: exactly one place leads the sign-up, and an external one says who it is.
$eventData = array('label' => 'Winter-Cup', 'event_day' => '2026-12-05', 'end_day' => '', 'registration' => 'dolibarr');
same(array(), VereineEventRules::validateEvent($eventData), 'an event led by Dolibarr is fine');
same(array('VereineEventErrorExternalRef'), VereineEventRules::validateEvent(array('registration' => 'external') + $eventData),
	'an external sign-up without its reference is refused');
same(array(), VereineEventRules::validateEvent(array('registration' => 'external', 'external_ref' => 'lionsapp-42') + $eventData),
	'an external sign-up with its reference is fine');
same(array('VereineEventErrorEndDay'), VereineEventRules::validateEvent(array('end_day' => '2026-12-04') + $eventData),
	'an end before the beginning is refused');

// ------------------------------------------------------------- helper shifts (#23)

$shift = array('capacity' => 2, 'shift_day' => '2026-12-05', 'start_time' => '10:00', 'end_time' => '14:00');
same(array(), VereineShiftRules::validate(array('label' => 'Kassa', 'capacity' => '2') + $shift), 'a sensible shift is fine');
same(array('VereineShiftErrorEndTime'), VereineShiftRules::validate(array('label' => 'Kassa', 'capacity' => '2',
	'shift_day' => '2026-12-05', 'start_time' => '14:00', 'end_time' => '10:00')), 'a shift that ends before it starts is refused');
same(array('VereineShiftErrorCapacity'), VereineShiftRules::validate(array('label' => 'Kassa', 'capacity' => '0') + $shift),
	'a shift without a place is refused');
same(array('VereineShiftErrorTime'), VereineShiftRules::validate(array('label' => 'Kassa', 'capacity' => '2',
	'shift_day' => '2026-12-05', 'start_time' => '25:00', 'end_time' => '')), 'a time that does not exist is refused');

// Places: what is asked for does not take a place, what is confirmed or done does.
$entries = array(
	array('member_id' => 1, 'status' => 'confirmed'),
	array('member_id' => 2, 'status' => 'requested'),
);
same(array('taken' => 1, 'free' => 1, 'requested' => 1, 'done' => 0, 'full' => false), VereineShiftRules::places(2, $entries),
	'one place taken, one free, one asking');
$full = array(array('member_id' => 1, 'status' => 'confirmed'), array('member_id' => 3, 'status' => 'done'));
same(true, VereineShiftRules::places(2, $full)['full'], 'somebody who was there still holds their place');

// Two shifts at the same time.
$morning = array('shift_day' => '2026-12-05', 'start_time' => '08:00', 'end_time' => '12:00');
$noon = array('shift_day' => '2026-12-05', 'start_time' => '12:00', 'end_time' => '16:00');
$overlapping = array('shift_day' => '2026-12-05', 'start_time' => '11:00', 'end_time' => '13:00');
expect(!VereineShiftRules::overlap($morning, $noon), 'one shift ending when the next starts is no overlap');
expect(VereineShiftRules::overlap($morning, $overlapping), 'shifts that share an hour overlap');
expect(!VereineShiftRules::overlap($morning, array('shift_day' => '2026-12-06', 'start_time' => '08:00', 'end_time' => '12:00')),
	'shifts on different days never overlap');
expect(VereineShiftRules::overlap($morning, array('shift_day' => '2026-12-05', 'start_time' => '', 'end_time' => '')),
	'a shift without times covers the whole day');

// Who may go on a shift.
same('', VereineShiftRules::refuse($shift, $entries, array(), 5, 'confirmed'), 'a free place takes another person');
same('VereineShiftErrorTwice', VereineShiftRules::refuse($shift, $entries, array(), 1, 'confirmed'), 'nobody stands on a shift twice');
same('VereineShiftErrorFull', VereineShiftRules::refuse($shift, $full, array(), 5, 'confirmed'), 'a full shift takes nobody');
same('', VereineShiftRules::refuse($shift, $full, array(), 5, 'requested'), 'asking is fine even when the shift is full');
same('VereineShiftErrorOverlap', VereineShiftRules::refuse(array('capacity' => 2) + $overlapping, array(), array($morning), 5, 'confirmed'),
	'nobody does two shifts at once');
same('VereineShiftErrorStatus', VereineShiftRules::refuse($shift, $entries, array(), 5, 'vielleicht'), 'a status that does not exist is refused');

// How long a shift lasts.
same(4.0, VereineShiftRules::hours($shift), 'ten to two is four hours');
same(1.5, VereineShiftRules::hours(array('start_time' => '18:00', 'end_time' => '19:30')), 'half hours are counted');
same(0.0, VereineShiftRules::hours(array('start_time' => '', 'end_time' => '')), 'without times there are no hours');

// ------------------------------------------------------------- the way through a general assembly (#127)

$assemblyFacts = array(
	'day' => '2026-11-15', 'held' => false,
	'account_made' => '', 'account_deadline' => '2026-05-31',
	'audit_day' => '', 'audit_deadline' => '', 'audit_report_signed' => false,
	'elections_due' => 2, 'agenda_missing' => 1,
	'invited_on' => '', 'invite_deadline' => '2026-11-01', 'motions_deadline' => '2026-11-08',
	'sheets' => 0, 'elections_on_agenda' => 1, 'attendance' => 0, 'votes' => 0,
	'minutes_final' => false, 'minutes_signed' => false, 'minutes_sent' => false,
	'resolutions' => 0, 'resolution_pdfs' => 0,
	'authority_open' => 0, 'authority_deadline' => '',
	'statute_change' => false, 'statute_letter' => false, 'group_changes' => 0,
);
$byCode = function (array $steps) {
	$map = array();
	foreach ($steps as $step) {
		$map[$step['code']] = $step;
	}
	return $map;
};

// Thirty days before the day: the invitation still has time, the account is late, a vote is due.
$steps = $byCode(VereineAssemblyRules::check($assemblyFacts, '2026-10-16'));
same(17, count($steps), 'every step of the way appears exactly once');
same('overdue', $steps['account']['state'], 'the account was due end of May and is not made');
same('2026-11-01', $steps['invitation']['deadline'], 'the invitation deadline comes from the statutes');
same('now', $steps['invitation']['state'], 'two weeks before the deadline the invitation is what to do now');
same('now', $steps['elections']['state'], 'two functions have to be elected');
same('now', $steps['agenda']['state'], 'a required item is missing from the agenda');
same('later', $steps['attendance']['state'], 'the attendance is nothing to do before the day');
same('later', $steps['minutes']['state'], 'the minutes wait for the assembly');
same('none', $steps['statutes']['state'], 'without a change of the statutes there is nothing to report');
same('none', $steps['resolutions']['state'], 'without resolutions there is nothing to put into a PDF');

// The day the invitation deadline has passed and nothing went out.
$steps = $byCode(VereineAssemblyRules::check($assemblyFacts, '2026-11-02'));
same('overdue', $steps['invitation']['state'], 'the invitation deadline passed without an invitation');
$late = array('invited_on' => '2026-11-03') + $assemblyFacts;
same('overdue', $byCode(VereineAssemblyRules::check($late, '2026-11-04'))['invitation']['state'],
	'an invitation sent too late stays a fault of this assembly');
$intime = array('invited_on' => '2026-10-20') + $assemblyFacts;
same('done', $byCode(VereineAssemblyRules::check($intime, '2026-11-02'))['invitation']['state'], 'an invitation in time is done');

// After the assembly: the election reached the register, the authority has to hear of it.
$after = array(
	'held' => true, 'account_made' => '2026-05-20', 'audit_day' => '2026-06-10', 'audit_report_signed' => true,
	'elections_due' => 0, 'agenda_missing' => 0, 'invited_on' => '2026-10-20',
	'sheets' => 1, 'attendance' => 13, 'votes' => 4, 'minutes_final' => true, 'minutes_signed' => false,
	'resolutions' => 4, 'resolution_pdfs' => 2, 'authority_open' => 1, 'authority_deadline' => '2026-12-13',
	'statute_change' => true, 'statute_letter' => false, 'group_changes' => 2,
) + $assemblyFacts;
$steps = $byCode(VereineAssemblyRules::check($after, '2026-11-16'));
same('done', $steps['account']['state'], 'the account is made');
same('now', $steps['authority']['state'], 'the new representatives have to reach the authority');
same('2026-12-13', $steps['authority']['deadline'], 'the four weeks of the report are the deadline');
same('now', $steps['statutes']['state'], 'a change of the statutes has to be reported');
same('now', $steps['resolutions']['state'], 'two of four resolutions still lack their PDF');
same('2/4', $steps['resolutions']['detail'], 'the step says how many PDFs are there');
same('overdue', $byCode(VereineAssemblyRules::check($after, '2026-12-14'))['authority']['state'],
	'after four weeks the report to the authority is late');

// How far the assembly has come, and what is open.
$progress = VereineAssemblyRules::progress(VereineAssemblyRules::check($after, '2026-11-16'));
expect($progress['total'] === 17 && $progress['done'] === 11 && $progress['percent'] === 65,
	'eleven of seventeen steps done: '.$progress['done'].'/'.$progress['total'].', '.$progress['percent'].' %');
$open = VereineAssemblyRules::open(VereineAssemblyRules::check($after, '2026-12-14'));
expect($open && $open[0]['code'] === 'authority', 'what is late stands first: '.($open ? $open[0]['code'] : 'nothing'));

// Every step belongs to a phase, and the phases come in their order.
$phases = array();
foreach (VereineAssemblyRules::check($assemblyFacts, '2026-10-16') as $step) {
	$phases[$step['phase']] = true;
}
same(VereineAssemblyRules::PHASES, array_keys($phases), 'the steps come in the order of the phases');

// ------------------------------------------------------------- the change feed (#154)

// The name of a change is the same however often it is written, and different for another change.
$eventOne = VereineChangeRules::eventId(1, 'membership', 42, 'updated', '2026-09-23 14:05:11');
same($eventOne, VereineChangeRules::eventId(1, 'membership', 42, 'updated', '2026-09-23 14:05:11'),
	'the same change keeps the same name');
expect($eventOne !== VereineChangeRules::eventId(1, 'membership', 42, 'updated', '2026-09-23 14:05:12'),
	'the same object changing again is a change of its own');
expect($eventOne !== VereineChangeRules::eventId(2, 'membership', 42, 'updated', '2026-09-23 14:05:11'),
	'another entity is another change');
expect($eventOne !== VereineChangeRules::eventId(1, 'fee', 42, 'updated', '2026-09-23 14:05:11'),
	'another kind of object is another change');
same(40, strlen($eventOne), 'the name of a change has a fixed length');

// Only what the feed knows gets in.
expect(VereineChangeRules::known('membership', 'updated'), 'a membership that changed is known');
expect(!VereineChangeRules::known('bankverbindung', 'updated'), 'the feed does not carry bank data');
expect(!VereineChangeRules::known('membership', 'geaendert'), 'a kind of change nobody defined is refused');

// The cursor is opaque but comes back exactly as it went in.
$cursor = VereineChangeRules::cursor('2026-09-23 14:05:11', 17);
expect(strpos($cursor, '2026') === false, 'the cursor does not show its moment');
same(array('written_at' => '2026-09-23 14:05:11', 'row' => 17), VereineChangeRules::readCursor($cursor),
	'the cursor reads back as it was written');
same(null, VereineChangeRules::readCursor('irgendwas'), 'a cursor nobody wrote here is refused');
same(null, VereineChangeRules::readCursor(''), 'an empty cursor is no cursor');
same(null, VereineChangeRules::readCursor(bin2hex('v1|nicht ein zeitpunkt|3')), 'a cursor with a broken moment is refused');

// The safety margin keeps a reader behind the newest entries.
same('2026-09-23 14:05:06', VereineChangeRules::horizon('2026-09-23 14:05:11'), 'the reader stays five seconds behind');
same('2026-06-25 14:05:11', VereineChangeRules::oldestKept('2026-09-23 14:05:11'), 'ninety days are kept');

// When a reader has to start over.
$fresh = VereineChangeRules::readCursor(VereineChangeRules::cursor('2026-09-01 10:00:00', 5));
$stale = VereineChangeRules::readCursor(VereineChangeRules::cursor('2026-01-01 10:00:00', 5));
expect(!VereineChangeRules::resyncRequired(null, '2026-08-01 00:00:00', '2026-09-23 14:05:11'),
	'a reader that has taken nothing yet simply starts');
expect(!VereineChangeRules::resyncRequired($fresh, '2026-08-01 00:00:00', '2026-09-23 14:05:11'),
	'a cursor inside what is kept continues');
expect(VereineChangeRules::resyncRequired($stale, '2026-08-01 00:00:00', '2026-09-23 14:05:11'),
	'a cursor older than the retention has to start over');
expect(VereineChangeRules::resyncRequired($fresh, '2026-09-10 00:00:00', '2026-09-23 14:05:11'),
	'a cursor before the oldest entry that is left has to start over');
expect(!VereineChangeRules::resyncRequired($fresh, '', '2026-09-23 14:05:11'),
	'an empty feed is no reason to start over');

// Pages and what a reader asks for.
same(100, VereineChangeRules::pageSize(0), 'without a wish a page holds a hundred');
same(500, VereineChangeRules::pageSize(5000), 'a page never grows past five hundred');
same(7, VereineChangeRules::pageSize(7), 'a sensible wish is followed');
same(array('membership', 'fee'), VereineChangeRules::wantedTypes('membership, fee'), 'the kinds asked for are taken');
same(VereineChangeRules::TYPES, VereineChangeRules::wantedTypes(''), 'without a wish every kind comes');
same(VereineChangeRules::TYPES, VereineChangeRules::wantedTypes('bankverbindung'), 'a kind nobody knows is ignored');
same(array('membership'), VereineChangeRules::wantedTypes('membership,membership'), 'a kind named twice comes once');

// ------------------------------------------------------------- signed webhooks (#155)

$hookEvent = array('event_id' => 'abc123', 'object_type' => 'membership', 'object_id' => 42, 'revision' => 3,
	'change' => 'updated', 'occurred_at' => '2026-09-23T14:05:11Z');
$hookBody = VereineHookRules::body($hookEvent);
$decoded = json_decode($hookBody, true);
same(array('version', 'event_id', 'object_type', 'object_id', 'revision', 'change', 'occurred_at'), array_keys($decoded),
	'the body carries the reference and nothing else');
expect(strpos($hookBody, 'name') === false && strpos($hookBody, 'iban') === false, 'the body carries no content of the change');

// The signature covers version, moment and the bytes; anything else changes it.
$secret = 'ein-geheimnis';
$header = VereineHookRules::header($secret, 'a1b2c3d4', $hookBody, 1790000000, 'abc123');
same('', VereineHookRules::verify($header, $hookBody, array('a1b2c3d4' => $secret), 1790000000), 'a fresh delivery is genuine');
same('signature', VereineHookRules::verify($header, $hookBody.' ', array('a1b2c3d4' => $secret), 1790000000),
	'one changed byte makes the signature wrong');
same('signature', VereineHookRules::verify($header, $hookBody, array('a1b2c3d4' => 'falsch'), 1790000000),
	'the wrong secret makes the signature wrong');
same('key', VereineHookRules::verify($header, $hookBody, array('99999999' => $secret), 1790000000),
	'a key the receiver does not know is refused');
same('expired', VereineHookRules::verify($header, $hookBody, array('a1b2c3d4' => $secret), 1790000000 + 301),
	'a moment older than five minutes is refused');
same('', VereineHookRules::verify($header, $hookBody, array('a1b2c3d4' => $secret), 1790000000 + 299),
	'inside the five minutes it still counts');
same('header', VereineHookRules::verify('kein header', $hookBody, array('a1b2c3d4' => $secret), 1790000000),
	'something that is no header of ours is refused');
same('header', VereineHookRules::verify('v1=kurz,t=1790000000,k=a1b2c3d4,e=abc123', $hookBody,
	array('a1b2c3d4' => $secret), 1790000000), 'a signature that is not a sha256 is refused');

// A repetition keeps the name of the event and gets a new moment, so it is no replay.
$again = VereineHookRules::header($secret, 'a1b2c3d4', $hookBody, 1790000200, 'abc123');
expect($again !== $header, 'a second attempt is signed anew');
same('abc123', VereineHookRules::readHeader($again)['event_id'], 'a second attempt keeps the name of the event');
same('', VereineHookRules::verify($again, $hookBody, array('a1b2c3d4' => $secret), 1790000200), 'the second attempt is genuine too');

// During a rotation a receiver knows two secrets.
$old = VereineHookRules::header('altes-geheimnis', 'aaaa1111', $hookBody, 1790000000, 'abc123');
$both = array('a1b2c3d4' => $secret, 'aaaa1111' => 'altes-geheimnis');
same('', VereineHookRules::verify($old, $hookBody, $both, 1790000000), 'the old key still works during the rotation');
same('', VereineHookRules::verify($header, $hookBody, $both, 1790000000), 'the new key works as well');

// The pauses grow, and they stop growing.
same(30, VereineHookRules::backoff(1), 'the first pause is half a minute');
same(120, VereineHookRules::backoff(2), 'the second is two minutes');
same(21600, VereineHookRules::backoff(7), 'the seventh is six hours');
same(21600, VereineHookRules::backoff(99), 'it never grows past six hours');
expect(VereineHookRules::accepted(200) && VereineHookRules::accepted(204) && !VereineHookRules::accepted(302)
	&& !VereineHookRules::accepted(500), 'only an answer from 200 to 299 counts as taken');

// Where a delivery may go.
same('VereineHookErrorHttps', VereineHookRules::checkUrl('http://verein.test/hook', false), 'plain http is refused');
same('VereineHookErrorCredentials', VereineHookRules::checkUrl('https://user:pass@verein.test/hook', false),
	'credentials in the address are refused');
same('VereineHookErrorUrl', VereineHookRules::checkUrl('', false), 'an empty address is refused');
same('VereineHookErrorInternal', VereineHookRules::checkUrl('https://localhost/hook', false), 'localhost is inside the network');
same('VereineHookErrorInternal', VereineHookRules::checkUrl('https://127.0.0.1/hook', false), 'the loopback address is inside');
same('VereineHookErrorInternal', VereineHookRules::checkUrl('https://192.168.1.10/hook', false), 'a private address is inside');
same('VereineHookErrorInternal', VereineHookRules::checkUrl('https://169.254.169.254/latest/meta-data', false),
	'the address a cloud answers about itself with is inside, whatever the letter says');
same('', VereineHookRules::checkUrl('https://192.168.1.10/hook', true), 'with the exception a local address is allowed');
expect(VereineHookRules::isInternalAddress('10.0.0.5') && VereineHookRules::isInternalAddress('::1')
	&& !VereineHookRules::isInternalAddress('93.184.216.34'), 'private and loopback are inside, a public address is not');
expect(VereineHookRules::isInternalAddress('kein-ip'), 'what is no address at all is not a target either');

// Nothing secret ever reaches a log.
expect(strpos(VereineHookRules::maskError('failed with ein-geheimnis', array($secret)), $secret) === false,
	'the secret is taken out of a message');
expect(strpos(VereineHookRules::maskError('v1=deadbeefdeadbeef broken', array()), 'deadbeef') === false,
	'a signature is taken out of a message');
same('a1b2c3d4…', VereineHookRules::maskSecret('a1b2c3d4'), 'the setup shows the name of the secret, never the secret');
same(250, mb_strlen(VereineHookRules::maskError(str_repeat('x', 400), array()), 'UTF-8'), 'a message stays short');

// ------------------------------------------------------------- verified external identities (#153)

$memberIdentity = array('entity' => 1, 'client' => 'lionsapp', 'subject' => 'sub-1', 'member_id' => 42,
	'application_id' => 0, 'capabilities' => 'consents', 'revoked_at' => '', 'proof' => 'invitation',
	'linked_at' => '2026-09-23 14:05:11');
$applicantIdentity = array('entity' => 1, 'client' => 'website', 'subject' => 'sub-2', 'member_id' => 0,
	'application_id' => 7, 'capabilities' => 'applications', 'revoked_at' => '', 'proof' => 'invitation',
	'linked_at' => '2026-09-23 14:05:11');

// What a name at a client may look like.
same('', VereineIdentityRules::checkSubject('sub-1'), 'a plain name is fine');
same('', VereineIdentityRules::checkSubject('auth0|61f2c9'), 'the name an identity provider gives is fine');
same('VereineIdentityErrorSubject', VereineIdentityRules::checkSubject(''), 'no name is refused');
same('VereineIdentityErrorSubject', VereineIdentityRules::checkSubject('mit leerzeichen'), 'a name with a blank is refused');
same('VereineIdentityErrorSubject', VereineIdentityRules::checkSubject(str_repeat('x', 129)), 'a name that long is refused');

// Abilities are off until somebody switches them on.
same(array(), VereineIdentityRules::capabilities(''), 'nothing is allowed by default');
same(array('consents'), VereineIdentityRules::capabilities('consents'), 'what was switched on is allowed');
same(array('consents', 'votes'), VereineIdentityRules::capabilities('consents, votes, unfug'),
	'an ability nobody defined is dropped');
same(array('consents'), VereineIdentityRules::capabilities('consents,consents'), 'an ability named twice counts once');

// A binding is alive only for its own client and entity.
same('', VereineIdentityRules::alive($memberIdentity, 'lionsapp', 1), 'the binding of this client is alive');
same('VereineIdentityErrorClient', VereineIdentityRules::alive($memberIdentity, 'website', 1),
	'a binding of one application is worth nothing at another');
same('VereineIdentityErrorEntity', VereineIdentityRules::alive($memberIdentity, 'lionsapp', 2),
	'a binding of one entity is worth nothing in another');
same('VereineIdentityErrorUnknown', VereineIdentityRules::alive(null, 'lionsapp', 1), 'without a binding nothing is alive');
same('VereineIdentityErrorRevoked', VereineIdentityRules::alive(array('revoked_at' => '2026-09-01 08:00:00') + $memberIdentity,
	'lionsapp', 1), 'a binding that was taken back is not alive');

// Who may do what to which object.
same('', VereineIdentityRules::decide($memberIdentity, 'lionsapp', 1, 'consents', 'member'),
	'the member reads their own consents');
same('', VereineIdentityRules::decide($memberIdentity, 'lionsapp', 1, 'consents', 'member', 42),
	'naming their own member changes nothing');
same('VereineIdentityErrorForeign', VereineIdentityRules::decide($memberIdentity, 'lionsapp', 1, 'consents', 'member', 43),
	'asking for somebody else is refused, not forgiven');
same('VereineIdentityErrorNotAllowed', VereineIdentityRules::decide($memberIdentity, 'lionsapp', 1, 'votes', 'member'),
	'an ability that is off is refused');
same('VereineIdentityErrorCapability', VereineIdentityRules::decide($memberIdentity, 'lionsapp', 1, 'alles', 'member'),
	'an ability nobody defined is refused');
same('VereineIdentityErrorNoBinding', VereineIdentityRules::decide($memberIdentity, 'lionsapp', 1, 'consents', 'application'),
	'a member binding is no application binding');

// An applicant is bound to their application and to nothing else.
same('', VereineIdentityRules::decide($applicantIdentity, 'website', 1, 'applications', 'application'),
	'the applicant reads their own application');
same('VereineIdentityErrorNoBinding', VereineIdentityRules::decide($applicantIdentity, 'website', 1, 'applications', 'member'),
	'an applicant is nobody\'s member, whatever they ask for');
same('VereineIdentityErrorForeign', VereineIdentityRules::decide($applicantIdentity, 'website', 1, 'applications', 'application', 8),
	'another application is not theirs');
same('VereineIdentityErrorNotAllowed', VereineIdentityRules::decide($applicantIdentity, 'website', 1, 'consents', 'application'),
	'the applicant did not get the ability for consents');

// A code is only ever kept as its hash, and an invitation dies.
$code = VereineIdentityRules::newCode();
expect(strlen($code['code']) >= 30 && $code['hash'] !== $code['code'], 'a code is long and is not what is stored');
same($code['hash'], VereineIdentityRules::hash($code['code']), 'the same code gives the same hash');
expect(VereineIdentityRules::hash('anderer code') !== $code['hash'], 'another code gives another hash');
$invite = array('client' => 'lionsapp', 'used_at' => '', 'expires_at' => '2026-09-23 15:00:00');
same('', VereineIdentityRules::inviteUsable($invite, 'lionsapp', '2026-09-23 14:05:11'), 'a fresh invitation can be used');
same('VereineIdentityErrorExpired', VereineIdentityRules::inviteUsable($invite, 'lionsapp', '2026-09-23 15:00:01'),
	'an invitation that ran out cannot be used');
same('VereineIdentityErrorUsed', VereineIdentityRules::inviteUsable(array('used_at' => '2026-09-23 14:30:00') + $invite,
	'lionsapp', '2026-09-23 14:35:00'), 'an invitation works once');
same('VereineIdentityErrorClient', VereineIdentityRules::inviteUsable($invite, 'website', '2026-09-23 14:05:11'),
	'an invitation for one application is worth nothing at another');
same('VereineIdentityErrorCode', VereineIdentityRules::inviteUsable(null, 'lionsapp', '2026-09-23 14:05:11'),
	'a code nobody handed out is refused');

// What a caller is told about itself, and what it is not told.
$described = VereineIdentityRules::describe($memberIdentity);
same(array('subject', 'member_id', 'application_id', 'capabilities', 'proof', 'linked_at'), array_keys($described),
	'the answer carries the binding and nothing else');
same(42, $described['member_id'], 'the member of the binding is named');
same(null, $described['application_id'], 'what the binding does not carry is null, not zero');
same(array('consents'), $described['capabilities'], 'the abilities are named as a list');

// ------------------------------------------------------------- the fields of an application (#216)

// The name always, the address by default, and nothing that does not exist.
same(array('lastname', 'firstname', 'address', 'zip', 'town'), VereineApplicationFormRules::required(VereineApplicationFormRules::DEFAULT_REQUIRED),
	'by default name and address are required');
same(array('lastname', 'firstname'), VereineApplicationFormRules::required('none'), 'a choice of nothing still keeps the name');
same(array('lastname', 'firstname', 'birth', 'email'), VereineApplicationFormRules::required('email,birth,unfug'),
	'in the order of the form, nonsense left out');

// Own fields: only ones the member really has, never the module's own.
same(array('gamertag' => true, 'discord' => false), VereineApplicationFormRules::extraFields('{"gamertag":1,"discord":0,"weg":1}',
	array('gamertag', 'discord')), 'a field that is gone is dropped');
same(array(), VereineApplicationFormRules::extraFields('{"vereine_fee_payer":1}', array('vereine_fee_payer')),
	'the fields of the module never go on the form');
same(array(), VereineApplicationFormRules::extraFields('kein json', array('gamertag')), 'something that is no JSON is nothing');

// The web follows the same list as the PDF.
$webApplication = array('firstname' => 'Amelie', 'lastname' => 'Antrag', 'address' => '', 'zip' => '6020', 'town' => 'Innsbruck', 'email' => 'a@b.test');
$required = VereineApplicationFormRules::required(VereineApplicationFormRules::DEFAULT_REQUIRED);
$checked = VereineApplicationFormRules::checkWeb($webApplication, $required, array(), array());
same(array('address is required'), $checked['errors'], 'a web application without a street is refused');
$checked = VereineApplicationFormRules::checkWeb(array('address' => 'Teststraße 1') + $webApplication, VereineApplicationFormRules::required('gender'),
	array(), array());
same(array(), $checked['errors'], 'a field the web cannot send is not held against it');

// Own fields over the web.
$extra = array('gamertag' => true, 'discord' => false);
$full = array('address' => 'Teststraße 1') + $webApplication;
same(array('fields.gamertag is required'), VereineApplicationFormRules::checkWeb($full, $required, $extra, array())['errors'],
	'a required own field that is missing is refused');
$checked = VereineApplicationFormRules::checkWeb($full, $required, $extra, array('gamertag' => ' LionKing ', 'discord' => ''));
same(array(), $checked['errors'], 'a required own field that is there is fine');
same(array('gamertag' => 'LionKing'), $checked['fields'], 'values are trimmed and empty optional ones dropped');
same(array('fields.passwort is not a field of the application'),
	VereineApplicationFormRules::checkWeb($full, $required, $extra, array('gamertag' => 'x', 'passwort' => 'geheim'))['errors'],
	'a field the form does not ask for is refused, not stored');
same(array('fields.gamertag must be text'), VereineApplicationFormRules::checkWeb($full, $required, $extra, array('gamertag' => array('x')))['errors'],
	'a field must be text');

// The fee of the year of joining in real numbers, by the same rule the fee run uses.
$halfYear = array('amount' => 75, 'duration_value' => 1, 'duration_unit' => 'y', 'start_month' => 1, 'proration' => 'half_year');
same(array(array('from' => '2026-01-01', 'to' => '2026-06-30', 'amount' => 75.0), array('from' => '2026-07-01', 'to' => '2026-12-31', 'amount' => 37.5)),
	VereineApplicationFormRules::prorationSteps($halfYear, 2026), 'first half-year the full fee, second half-year half of it');
$quarter = array('proration' => 'quarter') + $halfYear;
same(array(75.0, 56.25, 37.5, 18.75), array_column(VereineApplicationFormRules::prorationSteps($quarter, 2026), 'amount'),
	'by quarter, four steps down');
same(12, count(VereineApplicationFormRules::prorationSteps(array('proration' => 'month') + $halfYear, 2026)), 'by month, twelve steps');
$july = array('start_month' => 7) + $halfYear;
same('2027-06-30', VereineApplicationFormRules::prorationSteps($july, 2026)[1]['to'], 'a fee year from July ends in June of the next year');
same(array(), VereineApplicationFormRules::prorationSteps(array('proration' => 'none') + $halfYear, 2026), 'nothing prorated, no steps');
same(array(), VereineApplicationFormRules::prorationSteps(array('start_month' => 0) + $halfYear, 2026), 'a fee year from joining has no steps');

// ------------------------------------------------------------- volunteer allowances (#7)

same(array('small' => 30.0, 'large' => 50.0, 'prae' => 120.0), array(
	'small' => VereineVolunteerRules::limitsOn('small', '2026-05-01')['day'], 'large' => VereineVolunteerRules::limitsOn('large', '2026-05-01')['day'],
	'prae' => VereineVolunteerRules::limitsOn('prae', '2026-05-01')['day']), 'the limits of a day since 2024');
same(null, VereineVolunteerRules::limitsOn('small', '2023-12-31'), 'before the rule the module knows no limit and claims none');

// A day: 30.00 is within, 30.01 is not.
same(array(), VereineVolunteerRules::check(array('day' => '2026-05-01', 'kind' => 'small', 'amount' => 30), array())['findings'],
	'thirty euros on a day are within the small allowance');
same(array('over_day'), VereineVolunteerRules::check(array('day' => '2026-05-01', 'kind' => 'small', 'amount' => 30.01), array())['findings'],
	'one cent more is over the day');
same(array('over_day'), VereineVolunteerRules::check(array('day' => '2026-05-01', 'kind' => 'small', 'amount' => 20),
	array(array('day' => '2026-05-01', 'kind' => 'small', 'amount' => 15)))['findings'], 'two entries on one day count together');

// The year, and a year that ends: December counts, January of the next year does not.
$year = array();
for ($month = 1; $month <= 11; $month++) {
	$year[] = array('day' => sprintf('2026-%02d-10', $month), 'kind' => 'small', 'amount' => 30);
	$year[] = array('day' => sprintf('2026-%02d-20', $month), 'kind' => 'small', 'amount' => 30);
	$year[] = array('day' => sprintf('2026-%02d-25', $month), 'kind' => 'small', 'amount' => 30);
}
$december = VereineVolunteerRules::check(array('day' => '2026-12-05', 'kind' => 'small', 'amount' => 30), $year);
same(1020.0, $december['year'], 'eleven months of three days and one in December make 1020 euros');
same(array('over_year'), $december['findings'], 'which is over the year');
same(array(), VereineVolunteerRules::check(array('day' => '2027-01-05', 'kind' => 'small', 'amount' => 30), $year)['findings'],
	'the next year starts at nothing');
same(array(), VereineVolunteerRules::check(array('day' => '2026-12-05', 'kind' => 'large', 'amount' => 30), $year)['findings'],
	'the large allowance has its own, higher limit of the year');

// PRAE by month, and a month that ends.
$june = array();
for ($day = 1; $day <= 6; $day++) {
	$june[] = array('day' => sprintf('2026-06-%02d', $day), 'kind' => 'prae', 'amount' => 120);
}
same(array('over_month'), VereineVolunteerRules::check(array('day' => '2026-06-30', 'kind' => 'prae', 'amount' => 1), $june)['findings'],
	'a seventh day in June is over the month');
same(array(), VereineVolunteerRules::check(array('day' => '2026-07-01', 'kind' => 'prae', 'amount' => 120), $june)['findings'],
	'July starts at nothing');
same(array('over_day'), VereineVolunteerRules::check(array('day' => '2026-07-01', 'kind' => 'prae', 'amount' => 121), array())['findings'],
	'more than 120 euros on a day is over');

// PRAE and an allowance for the same person in one year.
same(array('mixed'), VereineVolunteerRules::check(array('day' => '2026-08-01', 'kind' => 'small', 'amount' => 10),
	array(array('day' => '2026-03-01', 'kind' => 'prae', 'amount' => 50)))['findings'], 'PRAE and an allowance in one year is a case to check');
same(array(), VereineVolunteerRules::check(array('day' => '2027-08-01', 'kind' => 'small', 'amount' => 10),
	array(array('day' => '2026-03-01', 'kind' => 'prae', 'amount' => 50)))['findings'], 'in different years it is not');

// The list of the year per person.
$list = VereineVolunteerRules::yearList(array(
	array('member_id' => 2, 'name' => 'Zoe', 'day' => '2026-01-02', 'kind' => 'small', 'amount' => 30),
	array('member_id' => 2, 'name' => 'Zoe', 'day' => '2026-01-02', 'kind' => 'small', 'amount' => 10),
	array('member_id' => 1, 'name' => 'Anna', 'day' => '2026-02-02', 'kind' => 'prae', 'amount' => 100),
	array('member_id' => 1, 'name' => 'Anna', 'day' => '2025-12-31', 'kind' => 'prae', 'amount' => 100),
), 2026);
same(array('Anna', 'Zoe'), array_column($list, 'name'), 'one line per person, by name');
same(array(100.0, 40.0), array(array_sum(array($list[0]['prae'])), $list[1]['small']), 'only the calendar year counts');
same(1, $list[1]['days'], 'two entries on one day are one day');
same(array(), VereineVolunteerRules::validate(array('member_id' => 1, 'day' => '2026-02-28', 'activity' => 'Kassa', 'kind' => 'small', 'amount' => '25,50')),
	'a sensible entry with a comma is fine');
same(array('VereineVolunteerErrorDay', 'VereineVolunteerErrorAmount'), VereineVolunteerRules::validate(
	array('member_id' => 1, 'day' => '2026-02-30', 'activity' => 'Kassa', 'kind' => 'small', 'amount' => '0')), 'no 30 February, no zero amount');

// ------------------------------------------------------------- paying volunteer allowances (#7)

same(array(array('member_id' => 1, 'name' => 'Anna', 'amount' => 55.5), array('member_id' => 2, 'name' => 'Zoe', 'amount' => 30.0)),
	VereineVolunteerRules::payoutLines(array(
		array('member_id' => 2, 'name' => 'Zoe', 'amount' => 30),
		array('member_id' => 1, 'name' => 'Anna', 'amount' => 25.25),
		array('member_id' => 1, 'name' => 'Anna', 'amount' => 30.25),
	)), 'one line per person, the days added up, by name');
same(array(), VereineVolunteerRules::payoutLines(array()), 'an empty list has no lines');
expect(VereineVolunteerRules::payable(true, 'done', false), 'a list everybody signed may be paid');
expect(!VereineVolunteerRules::payable(true, 'open', false), 'a list somebody still has to sign may not be paid');
expect(!VereineVolunteerRules::payable(true, '', false), 'a list nobody asked to sign may not be paid');
expect(!VereineVolunteerRules::payable(true, 'done', true), 'a list that changed after it was signed may not be paid');
expect(VereineVolunteerRules::payable(false, '', false), 'when the association signs no money matters, the list may be paid');

// ------------------------------------------------------------- overpayments (#54)

same(0.32, VereineOverpaymentRules::excess(37.68, 38.00, 0), 'the example of the issue: 37,68 paid with 38,00 leaves 0,32');
same(0.0, VereineOverpaymentRules::excess(37.68, 37.68, 0), 'paid exactly is no overpayment');
same(0.0, VereineOverpaymentRules::excess(37.68, 20, 0), 'paid in part is no overpayment');
same(5.0, VereineOverpaymentRules::excess(100, 80, 25), 'a credit note used on the invoice counts as paid');
same(0.0, VereineOverpaymentRules::excess(10, 10.004, 0), 'less than a cent is no overpayment');
same('open', VereineOverpaymentRules::state('', false), 'nobody decided: open');
same('dolibarr', VereineOverpaymentRules::state('', true), "Dolibarr's own button made a credit of it");
same('donation', VereineOverpaymentRules::state('donation', false), 'what the module stored is where it stands');
same(array(), VereineOverpaymentRules::check('credit', 0.32, 'open', false), 'an open excess may stay as a credit');
same(array(), VereineOverpaymentRules::check('refund', 0.32, 'open', false), 'an open excess may go back');
same(array('VereineOverpaymentErrorConfirm'), VereineOverpaymentRules::check('donation', 0.32, 'open', false), 'a donation needs the word that it was given freely');
same(array(), VereineOverpaymentRules::check('donation', 0.32, 'open', true), 'given freely, the excess may become a donation');
same(array('VereineOverpaymentErrorAssigned'), VereineOverpaymentRules::check('refund', 0.32, 'credit', false), 'an excess is assigned once');
same(array('VereineOverpaymentErrorAssigned'), VereineOverpaymentRules::check('donation', 0.32, 'dolibarr', true), 'an excess Dolibarr converted is assigned');
same(array('VereineOverpaymentErrorNone'), VereineOverpaymentRules::check('credit', 0.0, 'open', false), 'nothing to assign without an excess');
same(array('VereineOverpaymentErrorKind'), VereineOverpaymentRules::check('keep', 0.32, 'open', true), 'only the three ways');
same(array('invoice' => 37.68, 'donation' => 0.32), VereineOverpaymentRules::splitDonation(38.0, 0.32), 'the payment: 37,68 for the invoice, 0,32 donation');
same(array('invoice' => 0.0, 'donation' => 0.2), VereineOverpaymentRules::splitDonation(0.2, 0.32), 'never more donation than the payment brought');
same(array('invoice' => 50.0, 'donation' => 0.0), VereineOverpaymentRules::splitDonation(50, 0), 'without a donation the payment stays with the invoice');

// ------------------------------------------------------------- donation report (#6)

same('SP-1', VereineDonationRules::refNr(' SP-1 '), 'a reference number as the schema takes it');
same('', VereineDonationRules::refNr('M 1'), 'no spaces in a reference number');
same('', VereineDonationRules::refNr(str_repeat('1', 24)), 'at most 23 characters');
$fakeVbpk = str_repeat('Ab3+', 43);
same($fakeVbpk, VereineDonationRules::vbpk(substr($fakeVbpk, 0, 80)."\n ".substr($fakeVbpk, 80)), 'an identifier copied with a line break');
same('', VereineDonationRules::vbpk(substr($fakeVbpk, 0, 171)), 'an identifier cut short is none');
same('123456789', VereineDonationRules::fastnr('12-345/6789'), 'a tax number with its separators');
same(null, VereineDonationRules::fastnr('1234'), 'four digits are no tax number');
same('', VereineDonationRules::fastnr(''), 'no tax number at all is allowed');
same('1980-05-12', VereineDonationRules::birthDate('1980-05-12', '2026-09-23'), 'a real date of birth');
same('', VereineDonationRules::birthDate('1980-02-30', '2026-09-23'), 'no 30th of February');
same('', VereineDonationRules::birthDate('1840-01-01', '2026-09-23'), 'the register searches from 1850');
same('', VereineDonationRules::birthDate('2027-01-01', '2026-09-23'), 'not born in the future');
same('100.00', VereineDonationRules::amount(100), 'a sum with two decimals');
same('', VereineDonationRules::amount(0), 'nothing is no sum the schema takes');
same('E', VereineDonationRules::transmission(100, null), 'nothing at the tax office: a first transmission');
same('', VereineDonationRules::transmission(0, null), 'nothing given, nothing held: nothing to send');
same('', VereineDonationRules::transmission(100, 100.0), 'the tax office holds the sum: nothing to send');
same('A', VereineDonationRules::transmission(120, 100.0), 'a changed sum is a change');
same('S', VereineDonationRules::transmission(0, 100.0), 'every donation gone: cancel the reference number');
same('E', VereineDonationRules::transmission(50, 0.0), 'after a cancellation a new first transmission');
same('OE', VereineDonationRules::kindKey('Ö'), 'language keys without umlauts');
expect(in_array('SP', VereineDonationRules::KINDS, true) && in_array('GM', VereineDonationRules::KINDS, true) && count(VereineDonationRules::KINDS) === 24, 'the 24 kinds of body of the schema');
same(array('Hauptstraße', '12a'), VereineDonationRules::splitAddress('Hauptstraße 12a'), 'street and house number apart');
same(array('Am Platz', ''), VereineDonationRules::splitAddress('Am Platz'), 'a street without a number stays whole');
expect(strlen(VereineDonationRules::messageRef(2025, 12, '20260923221530')) <= 36 && preg_match('/^[0-9a-zA-Z\-]+$/', VereineDonationRules::messageRef(2025, 12, '20260923221530')) === 1, 'a message reference of the schema');

$szr = VereineDonationRules::szrFile(array('contact' => 'Kassier; 0664', 'email' => 'kassa@example.org', 'vkz' => 'XZVR-123456789', 'reference' => 'Test'),
	array(array('ref' => 'SP-1', 'lastname' => 'Beispiel', 'firstname' => 'Erika', 'birth' => '1980-05-12', 'town' => 'Telfs', 'zip' => '6410', 'address' => 'Hauptstraße 12a', 'country' => 'AT')));
$szrLines = explode("\r\n", $szr);
same('KONTAKT=Kassier, 0664', $szrLines[0], 'no separator inside the header of the register file');
expect(in_array('VERSCHLÜSSELTEBPK=BMF+SA', $szrLines, true) && in_array('DATUMSFORMAT=JJJJ-MM-TT', $szrLines, true), 'the register file asks for vbPK SA with ISO dates');
same('', $szrLines[12], 'an empty line between the header and the columns');
same(implode(';', VereineDonationRules::SZR_COLUMNS), $szrLines[13], 'the columns in the order the register fixes');
same('SP-1;Beispiel;Erika;1980-05-12;;;;;AUT;Telfs;6410;Hauptstraße;12a', $szrLines[14], 'one line per person');

$answer = "KONTAKT=x\r\nVERSCHLÜSSELTEBPK=BMF+SA\r\n\r\nLAUFNR;NACHNAME;VORNAME;GEBDATUM;NAME_VOR_ERSTER_EHE;GEBORT;GESCHLECHT;STAATSANGEHÖRIGKEIT;ANSCHRIFTSSTAAT;GEMEINDENAME;PLZ;STRASSE;HAUSNR;REGISTER;VBPK_FÜR_VKZ=BMF+SA;ZUSATZINFO\r\nSP-1;Beispiel;Erika;1980-05-12;;;;;AUT;Telfs;6410;Hauptstraße;12a;ZMR; ".$fakeVbpk.";\r\n";
same(array('refs' => array('SP-1'), 'vbpk' => array('SP-1' => $fakeVbpk)), VereineDonationRules::szrResult($answer), 'the identifier out of the register answer');
same(array('refs' => array('D7'), 'vbpk' => array()), VereineDonationRules::szrResult("\xEF\xBB\xBFLAUFNR;NACHNAME\nD7;Muster\n"), 'a person the register did not find');
same('found', VereineDonationRules::szrKind('BPK_XZVR-1_1_20260923-120000_VERSCHL_BPK.csv'), 'the file of the identifiers');
same('notfound', VereineDonationRules::szrKind('BPK_XZVR-1_1_20260923-120000_KEINTREFFER.csv'), 'the file of the misses');
same('ambiguous', VereineDonationRules::szrKind('bpk_x_nicht_eindeutig.csv'), 'the file of the many hits');
same('', VereineDonationRules::szrKind('BPK_XZVR-1_1_20260923-120000_STATISTIK.csv'), 'the statistics say nothing about a person');

$xml = VereineDonationRules::xml(array('message_ref' => 'VRN-2025-1-20260923221530', 'timestamp' => '2026-09-23T22:15:30', 'kind' => 'SP', 'year' => '2025',
	'fastnr_org' => '', 'fastnr_tn' => ''),
	array(array('type' => 'E', 'ref' => 'SP-1', 'amount' => '100.00', 'vbpk' => $fakeVbpk), array('type' => 'A', 'ref' => 'D7', 'amount' => '75.50', 'vbpk' => ''),
		array('type' => 'S', 'ref' => 'D8', 'amount' => '', 'vbpk' => '')));
same(array(), VereineDonationRules::validate($xml, $root.'/xsd/UebermittlungSonderausgaben_2.xsd'), 'a report of first, change and cancellation holds against the schema of the Ministry of Finance');
expect(strpos($xml, 'Info_Daten') === false, 'without a tax number of the association the block Info_Daten stays out');
expect(substr_count($xml, '<vbPK>') === 1 && strpos($xml, '<Betrag>75.50</Betrag>') !== false, 'the identifier only in the first transmission, a change with its new sum');
$withNumbers = VereineDonationRules::xml(array('message_ref' => 'VRN-2025-2-1', 'timestamp' => '2026-09-23T22:15:30', 'kind' => 'GM', 'year' => '2025',
	'fastnr_org' => '123456789', 'fastnr_tn' => ''), array(array('type' => 'E', 'ref' => '1', 'amount' => '5.00', 'vbpk' => $fakeVbpk)));
same(array(), VereineDonationRules::validate($withNumbers, $root.'/xsd/UebermittlungSonderausgaben_2.xsd'), 'with the tax number of the association it holds too');
expect(strpos($withNumbers, '<Fastnr_Fon_Tn>123456789</Fastnr_Fon_Tn>') !== false, 'the association sends for itself: both tax numbers the same');
expect(VereineDonationRules::validate(str_replace('<Zeitraum>2025</Zeitraum>', '<Zeitraum>1999x</Zeitraum>', $xml), $root.'/xsd/UebermittlungSonderausgaben_2.xsd') !== array(), 'a broken report is caught by the schema');

$protocolTwok = implode('', array('<?xml version="1.0" encoding="UTF-8"?><SonderausgabenResponse xmlns="https://finanzonline.bmf.gv.at/fon/ws/uebermittlungSonderausgaben">',
	'<MessageSpec><MessageRefId>VRN-2025-1-1</MessageRefId><EinbringungsTimestamp>2026-02-10T16:08:59</EinbringungsTimestamp><Art>UEB_SA</Art><Uebermittlung>P</Uebermittlung><Info>TWOK</Info></MessageSpec>',
	'<SonderausgabenError><RefNr>D7</RefNr><Error><Code>ERR-U-008</Code><Text>Die Erstübermittlung ist nicht möglich.</Text></Error></SonderausgabenError></SonderausgabenResponse>'));
$read = VereineDonationRules::protocol($protocolTwok);
same(array('VRN-2025-1-1', 'TWOK', false), array($read['message_ref'], $read['info'], $read['test']), 'what the protocol is about');
same(array('D7' => array('ERR-U-008 Die Erstübermittlung ist nicht möglich.')), $read['errors'], 'the refused reference number with its reason');
expect(VereineDonationRules::accepted($read, 'SP-1') && !VereineDonationRules::accepted($read, 'D7'), 'in a partly accepted report only the refused line failed');
$readNok = VereineDonationRules::protocol(str_replace(array('<Info>TWOK</Info>', '<Uebermittlung>P</Uebermittlung>'), array('<Info>NOK</Info>', '<Uebermittlung>T</Uebermittlung>'), $protocolTwok));
expect(!VereineDonationRules::accepted($readNok, 'SP-1') && $readNok['test'], 'a refused report takes nothing, and a test says so');
same(null, VereineDonationRules::protocol('<html></html>'), 'something else is no protocol');

// ------------------------------------------------------------- setup guide (#126)

$freshFacts = array('name' => 'Verein', 'town' => 'Telfs', 'zvr' => '', 'purpose' => '', 'modules_missing' => array('categorie'), 'statute_rules' => false,
	'statute_versions' => 0, 'functions_missing' => 3, 'board_users' => 0, 'member_types' => 0, 'consents' => 0, 'mail_tested' => false,
	'signature_rules' => false, 'meeting_templates' => 0, 'api' => false, 'api_users' => 0);
$freshStates = VereineSetupGuideRules::states($freshFacts, array());
same(array('open'), array_values(array_unique(array_diff_key($freshStates, array('website' => 1)))), 'a fresh installation: every step open');
same('optional', $freshStates['website'], 'a website is up to the association');
same(array('finished' => 1, 'total' => 9, 'complete' => false), VereineSetupGuideRules::progress($freshStates), 'only the optional step counts as finished');
expect(!VereineSetupGuideRules::done('association', array('purpose' => 'Sport') + $freshFacts), 'without a ZVR number the association data are not complete');
expect(VereineSetupGuideRules::done('association', array('zvr' => '123456789', 'purpose' => 'Sport') + $freshFacts), 'name, town, ZVR and purpose: done');
expect(VereineSetupGuideRules::done('modules', array('modules_missing' => array()) + $freshFacts), 'every required module on: done');
expect(VereineSetupGuideRules::done('statutes', array('statute_versions' => 1) + $freshFacts), 'an uploaded version of the statutes counts');
expect(!VereineSetupGuideRules::done('board', array('functions_missing' => 0) + $freshFacts), 'a board nobody of which can log in is not done');
expect(VereineSetupGuideRules::done('board', array('functions_missing' => 0, 'board_users' => 1) + $freshFacts), 'every function held and one of them with an account');
expect(VereineSetupGuideRules::done('mail', array('mail_tested' => true) + $freshFacts), 'a test e-mail went out');
expect(!VereineSetupGuideRules::done('website', array('api' => true) + $freshFacts), 'the API alone is no website access');
same('skipped', VereineSetupGuideRules::states($freshFacts, array('consents'))['consents'], 'a step left out on purpose');
same(array('consents', 'mail'), VereineSetupGuideRules::skipped('consents, mail,unknown,consents'), 'only known steps are left out, each once');
$doneStates = VereineSetupGuideRules::states(array('zvr' => '1', 'purpose' => 'x', 'modules_missing' => array(), 'statute_rules' => true, 'functions_missing' => 0,
	'board_users' => 1, 'member_types' => 1, 'consents' => 1, 'mail_tested' => true, 'signature_rules' => true) + $freshFacts, array());
same(array('finished' => 9, 'total' => 9, 'complete' => true), VereineSetupGuideRules::progress($doneStates), 'everything done: the hint goes away');

// ------------------------------------------------------------- own fields by their kind (#226)

same('text', VereineApplicationFormRules::kindOf('varchar'), 'a line of text');
same('select', VereineApplicationFormRules::kindOf('radio'), 'radio buttons are a choice');
same('multi', VereineApplicationFormRules::kindOf('checkbox'), 'check boxes are a choice of several');
same('', VereineApplicationFormRules::kindOf('sellist'), 'a list out of another table cannot be asked on a form');
same('', VereineApplicationFormRules::kindOf('password'), 'no secrets on a form');
same('spielstaerke', VereineApplicationFormRules::code('Spielstärke', array()), 'a code from the label, umlauts spelled out');
same('spielstaerke_2', VereineApplicationFormRules::code('Spielstärke', array('spielstaerke')), 'a code that is taken gets a number');
same('feld_2_liga', VereineApplicationFormRules::code('2. Liga', array()), 'a code starts with a letter');
same('feld_vereine_x', VereineApplicationFormRules::code('Vereine X', array()), 'the module keeps its own prefix');
same('', VereineApplicationFormRules::code(' ?! ', array()), 'no letters, no code');
same(array('anfaenger' => 'Anfänger', 'profi' => 'Profi'), VereineApplicationFormRules::options("Anfänger\r\n\r\n Profi \n"), 'one option per line, empty lines dropped');
$specs = array('staerke' => array('kind' => 'select', 'options' => array('anfaenger' => 'Anfänger', 'profi' => 'Profi')),
	'spiele' => array('kind' => 'multi', 'options' => array('lol' => 'LoL', 'cs' => 'CS')), 'seit' => array('kind' => 'date'),
	'jahre' => array('kind' => 'number', 'integer' => true), 'pro' => array('kind' => 'boolean'), 'notiz' => array('kind' => 'textarea'),
	'kurz' => array('kind' => 'text', 'max' => 5));
$extraSpecs = array('staerke' => true, 'spiele' => false, 'seit' => false, 'jahre' => false, 'pro' => false, 'notiz' => false, 'kurz' => false);
$good = VereineApplicationFormRules::checkWeb($full, $required, $extraSpecs, array('staerke' => 'profi', 'spiele' => array('cs', 'lol', 'cs'), 'seit' => '2020-02-29',
	'jahre' => '12', 'pro' => true, 'notiz' => str_repeat('x', 1500), 'kurz' => 'abc'), $specs);
same(array(), $good['errors'], 'every kind with a good value');
same(array('staerke' => 'profi', 'spiele' => 'cs,lol', 'seit' => '2020-02-29', 'jahre' => '12', 'pro' => '1', 'notiz' => str_repeat('x', 1500), 'kurz' => 'abc'),
	$good['fields'], 'values as Dolibarr keeps them: several options with a comma, yes as 1');
$bad = VereineApplicationFormRules::checkWeb($full, $required, $extraSpecs, array('staerke' => 'meister', 'spiele' => array('fifa'), 'seit' => '2021-02-29',
	'jahre' => '1.5', 'pro' => 'vielleicht', 'kurz' => 'abcdef'), $specs);
same(array('fields.staerke must be one of: anfaenger, profi', 'fields.spiele must be a list of: lol, cs', 'fields.seit must be a date (YYYY-MM-DD)',
	'fields.jahre must be a whole number', 'fields.pro must be true or false', 'fields.kurz is longer than 5 characters'), $bad['errors'],
	'every kind refuses what it cannot take, the refused required choice named once');
same(array('pro' => '0'), VereineApplicationFormRules::checkWeb($full, $required, array('pro' => false), array('pro' => 'nein'), $specs)['fields'], 'a no is a value too');
same(array('fields.staerke is required'), VereineApplicationFormRules::checkWeb($full, $required, $extraSpecs, array(), $specs)['errors'], 'a required choice left out');

// ------------------------------------------------------------- files of the association (#123)

same('AAAAAAAAAA', VereineArchiveRules::code(str_repeat("\0", 10)), 'a code from its bytes');
$someCode = VereineArchiveRules::code(random_bytes(10));
expect(strlen($someCode) === 10 && strspn($someCode, VereineArchiveRules::ALPHABET) === 10, 'ten letters nobody misreads');
same('ABCDE23456', VereineArchiveRules::normalize(' abcde-23456 '), 'typed small and with a dash');
same('', VereineArchiveRules::normalize('ABCDE-2345O'), 'an O is no letter of a code');
same('', VereineArchiveRules::normalize('ABCDE'), 'too short is no code');
same('ABCDE-23456', VereineArchiveRules::format('ABCDE23456'), 'printed in two groups of five');
same(array('from' => '2025-01-01', 'to' => '2025-12-31'), VereineArchiveRules::period('2025-01-01', '2025-12-31'), 'a year');
same(null, VereineArchiveRules::period('2025-12-31', '2025-01-01'), 'backwards is no period');
same(null, VereineArchiveRules::period('2025-02-30', '2025-12-31'), 'no 30th of February');
same('2025-03-01_minutes_ABCDE23456_protokoll_v1.pdf', VereineArchiveRules::entryName(array('day' => '2025-03-01', 'kind' => 'minutes', 'code' => 'ABCDE23456',
	'filename' => 'protokoll v1.pdf')), 'a safe name in the ZIP');
$archiveEntries = array(array('day' => '2025-03-01', 'label' => 'Protokoll', 'title' => 'Sitzung; "März"', 'code' => 'ABCDE23456', 'name' => 'a.pdf', 'sha256' => str_repeat('a', 64)));
same("Datum;Art;Titel;Kennung;Datei;SHA-256\r\n2025-03-01;Protokoll;\"Sitzung; \"\"März\"\"\";ABCDE-23456;a.pdf;".str_repeat('a', 64)."\r\n",
	VereineArchiveRules::index($archiveEntries), 'the table of contents, quoted where needed');
same(str_repeat('a', 64)."  a.pdf\n", VereineArchiveRules::sums($archiveEntries), 'checksums as sha256sum reads them');

// ------------------------------------------------------------- access to one's own data (#10)

$asked = VereineDisclosureRules::request(array('requested_on' => '2026-09-20', 'check' => 'id_document', 'note' => ''), '2026-09-24');
same(array(), $asked['errors'], 'a request with its day and how the person was checked');
same(array('VereineDisclosureErrorDay', 'VereineDisclosureErrorCheck'), VereineDisclosureRules::request(array('requested_on' => '2026-09-30', 'check' => 'guess'), '2026-09-24')['errors'],
	'a request from the future and without a check is refused');
same(array('VereineDisclosureErrorNote'), VereineDisclosureRules::request(array('requested_on' => '2026-09-20', 'check' => 'other'), '2026-09-24')['errors'],
	'another way of checking needs a word how');
same('2026-10-20', VereineDisclosureRules::deadline('2026-09-20'), 'one month to answer');
same('2026-02-28', VereineDisclosureRules::deadline('2026-01-31'), 'the end of a shorter month');
same('2027-01-15', VereineDisclosureRules::deadline('2026-12-15'), 'into the next year');
$disclosed = json_decode(VereineDisclosureRules::json(array('member' => 'Anna Muster'), array('consents' => array(array('code' => 'fotos')))), true);
same(VereineDisclosureRules::SECTIONS, array_keys($disclosed['sections']), 'every section in its order, empty ones too, so nothing looks forgotten');
same('vereine-auskunft-1', $disclosed['format'], 'the copy names its format');
same('Kürzel: fotos · eingewilligt: ja', VereineDisclosureRules::line(array('code' => 'fotos', 'note' => '', 'given' => true), array('code' => 'Kürzel', 'given' => 'eingewilligt', '_yes' => 'ja')),
	'a row to read, empty fields left out');
expect(count(VereineDisclosure::fieldWords()) > 60, 'every field of the copy has its word');

// ------------------------------------------------------------- erasing a former member's data (#10)

$periods = VereineErasureRules::periods(array('consents' => '5', 'volunteer' => '2', 'tasks' => 'x'));
same(array(5, 7, 1), array($periods['consents'], $periods['volunteer'], $periods['tasks']), 'a chosen period counts only where the association may choose, and only as a number');
same(null, $periods['records'], 'club records are kept without limit');
same(array('consents', 'log'), VereineErasureRules::checkPeriods(array('consents' => '31', 'log' => '', 'contact' => '0', 'tasks' => '1', 'invitations' => '1', 'applications' => '3', 'arrears' => '3', 'loans' => '1', 'disclosures' => '3'))['errors'],
	'more than thirty years or nothing is refused');
same('2033-12-31', VereineErasureRules::until(VereineErasureRules::START_YEAR, '2026-03-01', 7), 'bookkeeping: seven years from the end of the year');
same('2029-03-01', VereineErasureRules::until(VereineErasureRules::START_EXIT, '2028-02-29', 1), 'a leap day ends a day later in a year without one');
same('', VereineErasureRules::until(VereineErasureRules::START_EXIT, '2026-01-01', null), 'never has no day');
$none = array('count' => 0, 'last' => '', 'due' => 0);
$found = array_fill_keys(array_keys(VereineErasureRules::CATEGORIES), $none);
$found['contact'] = array('count' => 4, 'last' => '', 'due' => 0);
$found['identities'] = array('count' => 1, 'last' => '', 'due' => 0);
$found['consents'] = array('count' => 2, 'last' => '', 'due' => 0);
$found['invitations'] = array('count' => 3, 'last' => '2026-05-01', 'due' => 1);
$found['bookkeeping'] = array('count' => 5, 'last' => '2025-11-30', 'due' => 0);
$found['name'] = array('count' => 1, 'last' => '', 'due' => 0);
$periods = VereineErasureRules::periods(array());
$plan = VereineErasureRules::plan($found, '2026-06-30', '2026-09-24', $periods, array());
same(array('identities', 'contact', 'invitations'), VereineErasureRules::due($plan), 'after the exit: bindings and contact data at once, invitations whose year is over');
same(1, $plan['invitations']['count'], 'only the invitations whose own year is over are counted as due');
same(array('waiting', '2029-06-30'), array($plan['consents']['state'], $plan['consents']['until']), 'proofs of consent wait three years from the exit');
same(array('kept', '2032-12-31'), array($plan['bookkeeping']['state'], $plan['bookkeeping']['until']), 'bookkeeping stays seven years from the end of its year');
same(array('waiting', '2032-12-31', 'name'), array($plan['name']['state'], $plan['name']['until'], $plan['name']['reason']), 'the name stays as long as bookkeeping needs it');
same('none', $plan['tasks']['state'], 'nothing there, nothing to do');
same(array(), VereineErasureRules::due(VereineErasureRules::plan($found, '', '2026-09-24', $periods, array())), 'nothing while somebody is a member');
$held = VereineErasureRules::plan($found, '2026-06-30', '2026-09-24', $periods, array('open_invoices' => true));
same(array('identities'), VereineErasureRules::due($held), 'with unpaid invoices only the bindings go');
same(array(), VereineErasureRules::due(VereineErasureRules::plan($found, '2026-06-30', '2026-09-24', $periods, array('hold' => true))), 'on hold nothing goes');
$found['bookkeeping'] = $none;
same('due', VereineErasureRules::plan($found, '2019-06-30', '2026-09-24', $periods, array())['name']['state'], 'the name goes when nothing kept needs it');
same(array('kept', 'functions'), array_values(array_intersect_key(VereineErasureRules::plan($found, '2019-06-30', '2026-09-24', $periods, array('functions' => true))['name'], array('state' => 1, 'reason' => 1))),
	'whoever held a function keeps the name');

// ------------------------------------------------------------- fee arrears from the Mahnwesen module (#17)

$fee = array('fee' => true, 'final_step' => 'membership_review', 'paid' => false, 'case_status' => 'open', 'paused' => false);
$final = array('type' => 'MAHNWESEN_CASE_FINAL_STAGE', 'revision' => 4);
same(array('do' => 'create', 'state' => 'open', 'why' => 'final_stage'), VereineArrearRules::decide(null, $final, $fee), 'a fee at the last step becomes one proposal');
same('not_a_fee', VereineArrearRules::decide(null, $final, array('fee' => false) + $fee)['why'], 'a sale to a member is no fee');
same('other_final_step', VereineArrearRules::decide(null, $final, array('final_step' => 'collection') + $fee)['why'], 'a profile that ends in collection is no matter of membership');
same('paid', VereineArrearRules::decide(null, $final, array('paid' => true) + $fee)['why'], 'paid before the event arrived: nothing for the board');
same('paused', VereineArrearRules::decide(null, $final, array('paused' => true) + $fee)['state'], 'a paused case waits');
$kept = array('state' => 'open', 'revision' => 4);
same('stale', VereineArrearRules::decide($kept, $final, $fee)['why'], 'the same event again changes nothing');
same('stale', VereineArrearRules::decide($kept, array('type' => 'MAHNWESEN_CASE_CLOSED', 'revision' => 3), $fee)['why'], 'a late event is left alone');
same('settled', VereineArrearRules::decide($kept, array('type' => 'MAHNWESEN_CASE_CLOSED', 'revision' => 5), $fee)['state'], 'closing settles the proposal');
same('paused', VereineArrearRules::decide($kept, array('type' => 'MAHNWESEN_CASE_PAUSED', 'revision' => 5), $fee)['state'], 'a pause holds it');
same('open', VereineArrearRules::decide(array('state' => 'paused', 'revision' => 5), array('type' => 'MAHNWESEN_CASE_RESUMED', 'revision' => 6), $fee)['state'], 'resumed, it waits again');
same('settled', VereineArrearRules::decide(array('state' => 'settled', 'revision' => 5), array('type' => 'MAHNWESEN_CASE_REOPENED', 'revision' => 6), $fee)['state'],
	'reopened, the board hears of it only when the last step comes again');
same('no_arrear', VereineArrearRules::decide(null, array('type' => 'MAHNWESEN_CASE_CLOSED', 'revision' => 1), $fee)['why'], 'closing a case the board never had changes nothing');
same('other_event', VereineArrearRules::decide(null, array('type' => 'MAHNWESEN_NOTICE_SENT', 'revision' => 1), $fee)['why'], 'a notice sent is no matter for the board');

// ------------------------------------------------------------- channels and accounts (#233)

same(array('discord' => 'required', 'twitch' => 'optional'), VereineSocialRules::asked('{"discord":"required","twitch":"optional","gone":"optional","youtube":"maybe"}', array('discord', 'twitch', 'youtube')),
	'asked networks: known ones asked in a known way only');
expect(VereineSocialRules::networkCode('steam') && VereineSocialRules::networkCode('riot_id') && !VereineSocialRules::networkCode('Steam') && !VereineSocialRules::networkCode('1up'),
	'codes of own networks: small letters, digits, underscore, a letter first');
same('Lion#1234', VereineSocialRules::handle(' Lion#1234 '), 'a name is trimmed');
same(null, VereineSocialRules::handle('<script>'), 'no markup in a name');
same(null, VereineSocialRules::handle(array('x')), 'a name is text');
$sent = VereineSocialRules::fromApplication(array('twitch' => 'lion_tv', 'steam' => 'x'), array('discord' => 'required', 'twitch' => 'optional'));
same(array('twitch' => 'lion_tv'), $sent['accounts'], 'only accounts the form asks for');
same(array('accounts.steam is not asked by the form', 'accounts.discord is required'), $sent['errors'], 'an unknown network and a missing required one are refused');
same('https://www.twitch.tv/lionsquad', VereineSocialRules::link('twitch', 'lionsquad', '{socialid}'), 'a Twitch name becomes its address');
same('https://www.youtube.com/@lionsquad', VereineSocialRules::link('youtube', 'lionsquad', 'https://www.youtube.com/{socialid}'), 'a YouTube name gets its @');
same('https://discord.gg/abc', VereineSocialRules::link('discord', 'https://discord.gg/abc', '{socialid}'), 'an address stays as it is');
same('', VereineSocialRules::link('discord', 'LionSquad', '{socialid}'), 'a Discord name has no address');
same('https://steamcommunity.com/id/lion', VereineSocialRules::link('steam', 'lion', 'https://steamcommunity.com/id/{socialid}'), 'an own network with its address');
same('https://www.youtube.com/@lionsquad/live', VereineSocialRules::liveUrl('youtube', 'https://www.youtube.com/@lionsquad/'), 'the live page of a YouTube channel');
same('https://www.twitch.tv/lionsquad', VereineSocialRules::liveUrl('twitch', 'https://www.twitch.tv/lionsquad'), 'a Twitch channel is its own live page');
same('', VereineSocialRules::liveUrl('discord', 'https://discord.gg/abc'), 'Discord has no live page');
$channel = VereineSocialRules::checkChannel(array('network' => 'twitch', 'label' => 'Hauptstream', 'target' => 'lionsquad', 'stream' => '1', 'position' => '10'), array('twitch'));
same(array(), $channel['errors'], 'a channel with network, label and name');
same(array(true, false, 10), array($channel['channel']['stream'], $channel['channel']['public'], $channel['channel']['position']), 'streams, not public unless ticked, its place');
same(array('VereineChannelErrorNetwork', 'VereineChannelErrorLabel', 'VereineChannelErrorTarget'),
	VereineSocialRules::checkChannel(array('network' => 'myspace', 'label' => '', 'target' => 'javascript://x'), array('twitch'))['errors'], 'unknown network, no label, a strange address');
$sorted = VereineSocialRules::sortChannels(array(
	array('network' => 'youtube', 'position' => 20, 'label' => 'Livestream'),
	array('network' => 'twitch', 'position' => 10, 'label' => 'Hauptstream'),
	array('network' => 'twitch', 'position' => 20, 'label' => 'CS2'),
	array('network' => 'discord', 'position' => 30, 'label' => 'Server')), array('discord', 'twitch', 'youtube'));
same(array('Hauptstream', 'CS2', 'Livestream', 'Server'), array_column($sorted, 'label'), 'the order of the association first, then the network');
// ------------------------------------------------------------- statutes on a day (#158)

$stored = array(array('id' => 1, 'version' => 1, 'valid_from' => '2020-01-01'), array('id' => 3, 'version' => 2, 'valid_from' => '2026-04-20'),
	array('id' => 5, 'version' => 3, 'valid_from' => '2027-01-01'));
$onDay = VereineStatuteVersionRules::onDay($stored, '2026-09-24');
same(array('in_force', 3), array($onDay['state'], $onDay['current']), 'version 2 is in force, version 3 still to come');
same(array('future', 'in_force', 'repealed'), array_column($onDay['versions'], 'state'), 'newest first: future, in force, repealed');
same(array('', '2026-12-31', '2026-04-19'), array_column($onDay['versions'], 'valid_to'), 'each version holds until the day before the next begins');
same(array('in_force', 1), array(VereineStatuteVersionRules::onDay($stored, '2026-04-19')['state'], VereineStatuteVersionRules::onDay($stored, '2026-04-19')['current']),
	'on the day before, version 1 still holds');
same(array('none', 0), array(VereineStatuteVersionRules::onDay($stored, '2019-12-31')['state'], VereineStatuteVersionRules::onDay($stored, '2019-12-31')['current']),
	'before the first, none holds');
$twice = VereineStatuteVersionRules::onDay(array_merge($stored, array(array('id' => 4, 'version' => 4, 'valid_from' => '2026-04-20'))), '2026-09-24');
same(array('ambiguous', 0), array($twice['state'], $twice['current']), 'two versions from the same day: ambiguous, none named, not the higher number');

// ------------------------------------------------------------- publishing documents (#156, #157)

$rules = VereinePublicationRules::rules('{"minutes":{"audience":"members","auto":true},"account":{"audience":"everybody","auto":true},"letter":{"audience":"","auto":true}}',
	array('minutes', 'account', 'letter', 'payout'));
same(array('minutes' => array('audience' => 'members', 'auto' => true), 'account' => array('audience' => '', 'auto' => false),
	'letter' => array('audience' => '', 'auto' => false), 'payout' => array('audience' => '', 'auto' => false)), $rules,
	'rules: a known audience only; without an audience nothing goes out by itself; an unknown kind publishes nothing');
same(array('members', '', ''), array(VereinePublicationRules::autoAudience($rules, 'minutes', 'signed'), VereinePublicationRules::autoAudience($rules, 'minutes', 'built'),
	VereinePublicationRules::autoAudience($rules, 'payout', 'signed')), 'a signed revision goes out by itself, a draft never');
same(array(true, true, false, false, true), array(VereinePublicationRules::sees('public', array('public' => true)), VereinePublicationRules::sees('members', array('member' => true)),
	VereinePublicationRules::sees('members', array('public' => true)), VereinePublicationRules::sees('board', array('member' => true)),
	VereinePublicationRules::sees('board', array('member' => true, 'board' => true))), 'the public sees public ones, members theirs, the board its own');
same('minutes-qzlas8tmmd-signed.pdf', VereinePublicationRules::filename('minutes', 'QZLAS8TMMD', 'signed'), 'a file name from kind, code and revision, nothing from the title');

// ------------------------------------------------------------- lending equipment (#26)

$lent = VereineLoanRules::check(array('resource_id' => '3', 'member_id' => '7', 'issued_on' => '2026-09-24', 'due_on' => '2026-10-08', 'condition' => ' vollständig '), '2026-09-24');
same(array(array(), 'vollständig'), array($lent['errors'], $lent['loan']['condition']), 'a loan with equipment, member and days');
same(array('VereineLoanErrorResource', 'VereineLoanErrorMember', 'VereineLoanErrorIssued', 'VereineLoanErrorDue'),
	VereineLoanRules::check(array('issued_on' => '2026-09-30', 'due_on' => '2026-09-29'), '2026-09-24')['errors'], 'nothing chosen, handed out in the future, back before it left');
same(array('out', 'out', 'overdue', 'returned'), array(VereineLoanRules::state('2026-10-08', '', '2026-09-24'), VereineLoanRules::state('2026-09-24', '', '2026-09-24'),
	VereineLoanRules::state('2026-09-23', '', '2026-09-24'), VereineLoanRules::state('2026-09-23', '2026-09-24', '2026-09-24')), 'out until the day it is due, then late, back when back');
same(array(true, false, true, false), array(VereineLoanRules::remind('2026-09-20', '', '', '2026-09-24'), VereineLoanRules::remind('2026-09-20', '', '2026-09-20', '2026-09-24'),
	VereineLoanRules::remind('2026-09-01', '', '2026-09-17', '2026-09-24'), VereineLoanRules::remind('2026-10-01', '', '', '2026-09-24')), 'reminded when late, again a week later, never before it is due');

// ------------------------------------------------------------- honours and statistics (#27, #28)

same(array(10, 20, 25), VereineHonourRules::numbers('25, 10;20 10 abc 0 200'), 'milestones: whole numbers from 1 to 120, sorted, once');
same(array('years' => 10, 'day' => '2026-05-04'), VereineHonourRules::jubilee('2016-05-04', 2026, array(10, 25)), 'ten years of membership in 2026');
same(null, VereineHonourRules::jubilee('2017-05-04', 2026, array(10, 25)), 'nine years are no jubilee');
same('2025-02-28', VereineHonourRules::jubilee('2020-02-29', 2025, array(5))['day'], 'a leap day in a year without one');
same(array('day' => '2026-06-15', 'age' => 50, 'round' => true), VereineHonourRules::birthday('1976-06-15', 2026), 'fifty is round');
expect(VereineHonourRules::birthday('2008-01-01', 2026)['round'] && !VereineHonourRules::birthday('1985-01-01', 2026)['round'] && VereineHonourRules::birthday('1951-01-01', 2026)['round'],
	'18 and 75 are round, 41 is not');
same(array(49, 50), array(VereineHonourRules::age('1976-06-15', '2026-06-14'), VereineHonourRules::age('1976-06-15', '2026-06-15')), 'the age changes on the birthday');
$groups = VereineHonourRules::ageGroups(array(14, 18));
same(array(array('from' => 0, 'to' => 14), array('from' => 15, 'to' => 18), array('from' => 19, 'to' => null)), $groups, 'age groups from their upper ends');
same(array(0, 1, 2, -1), array(VereineHonourRules::groupOf(14, $groups), VereineHonourRules::groupOf(15, $groups), VereineHonourRules::groupOf(80, $groups), VereineHonourRules::groupOf(null, $groups)),
	'an age in its group, an unknown one in none');
expect(VereineHonourRules::memberOn('2020-01-01', '', '2026-01-01') && !VereineHonourRules::memberOn('2020-01-01', '2025-12-31', '2026-01-01')
	&& !VereineHonourRules::memberOn('', '', '2026-01-01') && !VereineHonourRules::memberOn('2026-02-01', '', '2026-01-01'), 'a member on a day: begun, not ended, no draft');
same("\xEF\xBB\xBFBereich;Anzahl\r\n\"a;b\";'=1+1\r\n", VereineHonourRules::csv(array(array('Bereich', 'Anzahl'), array('a;b', '=1+1'))), 'CSV for a spreadsheet: quotes, no formulas');

expect(VereineSocialRules::stillConfirmed('LionTV', ' liontv ') && !VereineSocialRules::stillConfirmed('LionTV', 'LionTV2') && !VereineSocialRules::stillConfirmed('', ''),
	'a confirmation holds for the name it confirmed, whatever the case');

// ------------------------------------------------------------------- result

if ($failures) {
	fwrite(STDERR, count($failures)." of ".$assertions." assertions failed:\n  - ".implode("\n  - ", $failures)."\n");
	exit(1);
}
print 'Unit tests: OK ('.$assertions." assertions)\n";
exit(0);
