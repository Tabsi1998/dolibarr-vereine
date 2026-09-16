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
	preg_match_all("/'((?:Vereine|ModuleVereine|Permission492100)[A-Za-z0-9]*)'/", (string) file_get_contents($path), $matches);
	foreach ($matches[1] as $key) {
		$used[$key] = true;
	}
}
$prefixes = array('VereineProfile' => VereineProfile::codes(), 'VereineRegisterNumber' => array('ZVR', 'VR'), 'VereineRegisterNumberHelp' => array('ZVR', 'VR'));
foreach (array_keys($used) as $key) {
	if (isset($prefixes[$key])) {
		foreach ($prefixes[$key] as $ending) {
			expect(isset($english[$key.$ending]), 'language key '.$key.$ending.' is used by the code but missing in en_US');
		}
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
