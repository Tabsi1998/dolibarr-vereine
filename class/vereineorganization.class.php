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
 * \file    class/vereineorganization.class.php
 * \ingroup vereine
 * \brief   The association's data and the checks on it, for the overview page and the API.
 *
 * build() and checks() are plain PHP; load() reads them from a running Dolibarr.
 * The company name and address are Dolibarr's own company settings - the module
 * adds only what Dolibarr does not know about an association.
 */

require_once __DIR__.'/vereineprofile.class.php';

/**
 * The association as the module sees it.
 */
class VereineOrganization
{
	/** Constants the setup page writes. */
	const SETTINGS = array(
		'VEREINE_COUNTRY_PROFILE',
		'VEREINE_REGISTER_NUMBER',
		'VEREINE_REGISTER_COURT',
		'VEREINE_AUTHORITY',
		'VEREINE_FOUNDED',
		'VEREINE_NONPROFIT',
		'VEREINE_PURPOSE',
	);

	/** Check result: nothing to do. */
	const CHECK_OK = 'ok';

	/** Check result: something should be fixed. */
	const CHECK_WARNING = 'warning';

	/**
	 * The association's data, as the overview shows it and the API returns it.
	 *
	 * @param array<string,string> $settings Module constants, see SETTINGS
	 * @param array<string,string> $company  Dolibarr company data: name, address, zip, town, country_code, email, phone, url, fiscal_month_start
	 * @return array<string,mixed>
	 */
	public static function build(array $settings, array $company)
	{
		$profile = isset($settings['VEREINE_COUNTRY_PROFILE']) ? (string) $settings['VEREINE_COUNTRY_PROFILE'] : '';
		if (!VereineProfile::isSupported($profile)) {
			$profile = VereineProfile::suggestFromCountry(isset($company['country_code']) ? $company['country_code'] : '');
		}
		$value = static function (array $source, $key) {
			return isset($source[$key]) ? trim((string) $source[$key]) : '';
		};
		$fiscalMonth = (int) $value($company, 'fiscal_month_start');

		return array(
			'country_profile' => $profile,
			'country_profile_complete' => VereineProfile::isComplete($profile),
			'name' => $value($company, 'name'),
			'register' => array(
				'kind' => VereineProfile::registerKind($profile),
				'number' => $value($settings, 'VEREINE_REGISTER_NUMBER'),
				'court' => $profile === VereineProfile::GERMANY ? $value($settings, 'VEREINE_REGISTER_COURT') : '',
			),
			'authority' => $profile === VereineProfile::AUSTRIA ? $value($settings, 'VEREINE_AUTHORITY') : '',
			'address' => array(
				'street' => $value($company, 'address'),
				'zip' => $value($company, 'zip'),
				'town' => $value($company, 'town'),
				'country_code' => $value($company, 'country_code'),
			),
			'email' => $value($company, 'email'),
			'phone' => $value($company, 'phone'),
			'url' => $value($company, 'url'),
			'founded' => $value($settings, 'VEREINE_FOUNDED'),
			'nonprofit' => $value($settings, 'VEREINE_NONPROFIT') === '1',
			'purpose' => $value($settings, 'VEREINE_PURPOSE'),
			'fiscal_year_start_month' => ($fiscalMonth >= 1 && $fiscalMonth <= 12) ? $fiscalMonth : 1,
		);
	}

	/**
	 * What the overview reports as fine or to be fixed.
	 *
	 * @param array<string,mixed> $organization Result of build()
	 * @param bool                $apiEnabled   Whether Dolibarr's REST API module is enabled
	 * @return array<int,array{code:string,status:string,label:string,fix:string}> Label keys are language keys; fix names the page that fixes it
	 */
	public static function checks(array $organization, $apiEnabled)
	{
		$checks = array();
		$profile = $organization['country_profile'];

		$checks[] = array(
			'code' => 'company_name',
			'status' => $organization['name'] !== '' && $organization['address']['town'] !== '' ? self::CHECK_OK : self::CHECK_WARNING,
			'label' => $organization['name'] !== '' && $organization['address']['town'] !== '' ? 'VereineCheckCompanyOk' : 'VereineCheckCompanyMissing',
			'fix' => 'company',
		);

		$countryMatches = strtoupper($organization['address']['country_code']) === $profile;
		$checks[] = array(
			'code' => 'country',
			'status' => $countryMatches ? self::CHECK_OK : self::CHECK_WARNING,
			'label' => $countryMatches ? 'VereineCheckCountryOk' : 'VereineCheckCountryMismatch',
			'fix' => $countryMatches ? '' : 'setup',
		);

		$hasNumber = $organization['register']['number'] !== '';
		if ($profile === VereineProfile::AUSTRIA) {
			$label = $hasNumber ? 'VereineCheckZvrOk' : 'VereineCheckZvrMissing';
		} else {
			$label = $hasNumber && $organization['register']['court'] !== '' ? 'VereineCheckVrOk' : 'VereineCheckVrMissing';
			$hasNumber = $hasNumber && $organization['register']['court'] !== '';
		}
		$checks[] = array(
			'code' => 'register',
			'status' => $hasNumber ? self::CHECK_OK : self::CHECK_WARNING,
			'label' => $label,
			'fix' => $hasNumber ? '' : 'setup',
		);

		$checks[] = array(
			'code' => 'profile',
			'status' => $organization['country_profile_complete'] ? self::CHECK_OK : self::CHECK_WARNING,
			'label' => $organization['country_profile_complete'] ? 'VereineCheckProfileComplete' : 'VereineCheckProfilePreview',
			'fix' => '',
		);

		$checks[] = array(
			'code' => 'api',
			'status' => $apiEnabled ? self::CHECK_OK : self::CHECK_WARNING,
			'label' => $apiEnabled ? 'VereineCheckApiOk' : 'VereineCheckApiOff',
			'fix' => $apiEnabled ? '' : 'modules',
		);

		return $checks;
	}

	/**
	 * The association's data from the running Dolibarr.
	 *
	 * @param Societe $mysoc The company of the current entity
	 * @return array<string,mixed>
	 */
	public static function load($mysoc)
	{
		$settings = array();
		foreach (self::SETTINGS as $name) {
			$settings[$name] = getDolGlobalString($name);
		}
		$company = array(
			'name' => (string) $mysoc->name,
			'address' => (string) $mysoc->address,
			'zip' => (string) $mysoc->zip,
			'town' => (string) $mysoc->town,
			'country_code' => (string) $mysoc->country_code,
			'email' => (string) $mysoc->email,
			'phone' => (string) $mysoc->phone,
			'url' => (string) $mysoc->url,
			'fiscal_month_start' => getDolGlobalString('SOCIETE_FISCAL_MONTH_START'),
		);
		return self::build($settings, $company);
	}
}
