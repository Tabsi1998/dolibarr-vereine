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
 * \file    class/vereinethresholds.class.php
 * \ingroup vereine
 * \brief   Legal thresholds of Austrian associations as a dated table, and their traffic light, plain PHP.
 *
 * Amounts, dates and legal bases are listed with their sources in docs/LEGAL-SOURCES.md.
 */

require_once __DIR__.'/vereinetaxrules.class.php';

/**
 * Thresholds and their evaluation. No Dolibarr dependency, so tests/run.php checks them directly.
 */
class VereineThresholds
{
	/** Small business exemption, § 6 (1) no. 27 UStG, per calendar year. */
	const SMALL_BUSINESS = 'small_business';
	/** Turnover of businesses harmful to tax privileges, § 45a BAO, per assessment period (calendar year). */
	const HARMFUL_BUSINESS = 'harmful_business';
	/** Cash register duty, § 131b BAO. Listed only; checked with issue #40. */
	const CASH_REGISTER = 'cash_register';
	/** Small association festivals, § 45 (1a) BAO, hours a year. Listed only; hours are not in invoices. */
	const FESTIVAL_HOURS = 'festival_hours';

	/** Below 80 % of the limit. */
	const STATUS_OK = 'ok';
	/** From 80 % up to the limit. */
	const STATUS_NEAR = 'near';
	/** Above the limit, within its tolerance. */
	const STATUS_TOLERANCE = 'tolerance';
	/** Above the limit and any tolerance. */
	const STATUS_EXCEEDED = 'exceeded';

	/** Share of a limit from which the light turns yellow. */
	const NEAR_RATIO = 0.8;

	/**
	 * The dated table. valid_from and valid_to are inclusive days, '' for open. A valid_from is the
	 * earliest day a source confirms, not necessarily the day the rule began.
	 *
	 * gross tells whether the law counts gross amounts. For § 45a BAO the law counts
	 * turnover under § 1 UStG without saying gross; the module compares gross amounts
	 * so that it rather warns early than late.
	 *
	 * @return array<int,array{code:string,amount:float,unit:string,gross:bool,tolerance:float,evaluated:bool,valid_from:string,valid_to:string,basis:string,source:string}>
	 */
	public static function table()
	{
		$ust6 = 'https://www.jusline.at/gesetz/ustg/paragraf/6';
		$bao45a = 'https://www.jusline.at/gesetz/bao/paragraf/45a';
		return array(
			array('code' => self::SMALL_BUSINESS, 'amount' => 35000.0, 'unit' => 'EUR', 'gross' => false, 'tolerance' => 0.0, 'evaluated' => true, 'valid_from' => '2020-01-01', 'valid_to' => '2024-12-31', 'basis' => '§ 6 Abs. 1 Z 27 UStG (bis 2024)', 'source' => 'https://www.usp.gv.at/aktuelles/newsliste/kleinunternehmerregelung-ab-2025.html'),
			array('code' => self::SMALL_BUSINESS, 'amount' => 55000.0, 'unit' => 'EUR', 'gross' => true, 'tolerance' => 10.0, 'evaluated' => true, 'valid_from' => '2025-01-01', 'valid_to' => '', 'basis' => '§ 6 Abs. 1 Z 27 UStG', 'source' => $ust6),
			array('code' => self::HARMFUL_BUSINESS, 'amount' => 40000.0, 'unit' => 'EUR', 'gross' => true, 'tolerance' => 0.0, 'evaluated' => true, 'valid_from' => '2016-01-01', 'valid_to' => '2023-12-31', 'basis' => '§ 45a BAO (bis 2023)', 'source' => 'https://www.wko.at/oe/wirtschaftsrecht/bmf-br-st-vereine-und-steuern-201608-12.pdf'),
			array('code' => self::HARMFUL_BUSINESS, 'amount' => 100000.0, 'unit' => 'EUR', 'gross' => true, 'tolerance' => 0.0, 'evaluated' => true, 'valid_from' => '2024-01-01', 'valid_to' => '', 'basis' => '§ 45a BAO', 'source' => $bao45a),
			array('code' => self::CASH_REGISTER, 'amount' => 15000.0, 'unit' => 'EUR', 'gross' => false, 'tolerance' => 0.0, 'evaluated' => false, 'valid_from' => '2016-01-01', 'valid_to' => '', 'basis' => '§ 131b BAO', 'source' => 'https://www.jusline.at/gesetz/bao/paragraf/131b'),
			array('code' => self::FESTIVAL_HOURS, 'amount' => 72.0, 'unit' => 'hours', 'gross' => false, 'tolerance' => 0.0, 'evaluated' => false, 'valid_from' => '2016-08-02', 'valid_to' => '', 'basis' => '§ 45 Abs. 1a BAO', 'source' => 'https://www.jusline.at/gesetz/bao/paragraf/45'),
		);
	}

	/**
	 * The threshold of a kind valid on a day.
	 *
	 * @param string $code Threshold code
	 * @param string $day  Day YYYY-MM-DD
	 * @return array<string,mixed>|null
	 */
	public static function validOn($code, $day)
	{
		foreach (self::table() as $threshold) {
			if ($threshold['code'] !== $code) {
				continue;
			}
			if (($threshold['valid_from'] === '' || $threshold['valid_from'] <= $day) && ($threshold['valid_to'] === '' || $day <= $threshold['valid_to'])) {
				return $threshold;
			}
		}
		return null;
	}

	/**
	 * Whether income of a tax profile counts towards a threshold.
	 *
	 * Small business limit: turnover the association makes as a business - taxable or
	 * exempt as small business. Not counted: income without consideration, Liebhaberei
	 * (auxiliary businesses, which the ministry excludes from the limit) and the sports
	 * exemption, which § 6 (1) no. 27 UStG leaves out.
	 * § 45a BAO: turnover of businesses harmful to tax privileges.
	 *
	 * @param string $code      Threshold code
	 * @param string $sphere    Sphere of the profile
	 * @param string $treatment VAT treatment of the profile
	 * @return bool
	 */
	public static function counts($code, $sphere, $treatment)
	{
		if ($code === self::SMALL_BUSINESS) {
			return in_array($treatment, array(VereineTaxRules::TREATMENT_SMALL_BUSINESS, VereineTaxRules::TREATMENT_REDUCED_10, VereineTaxRules::TREATMENT_REDUCED_13, VereineTaxRules::TREATMENT_STANDARD_20), true);
		}
		if ($code === self::HARMFUL_BUSINESS) {
			return $sphere === VereineTaxRules::SPHERE_HARMFUL;
		}
		return false;
	}

	/**
	 * Traffic light for an amount against a limit.
	 *
	 * @param float $amount    Amount of the year
	 * @param float $limit     Limit
	 * @param float $tolerance Tolerance above the limit in percent
	 * @return string One of the STATUS constants
	 */
	public static function status($amount, $limit, $tolerance)
	{
		$amount = (float) $amount;
		$limit = (float) $limit;
		if ($limit <= 0) {
			return self::STATUS_OK;
		}
		if ($amount > $limit) {
			return $amount <= $limit * (1 + (float) $tolerance / 100) && $tolerance > 0 ? self::STATUS_TOLERANCE : self::STATUS_EXCEEDED;
		}
		return $amount >= $limit * self::NEAR_RATIO ? self::STATUS_NEAR : self::STATUS_OK;
	}

	/**
	 * Evaluate the thresholds of a calendar year from the income per tax profile.
	 *
	 * @param int                                                                  $year       Calendar year
	 * @param array<int,array{sphere:string,treatment:string,net:float,gross:float}> $income     Income of the year per profile kind
	 * @param array<int,array{sphere:string,treatment:string,net:float,gross:float}> $previous   Income of the year before
	 * @return array<int,array<string,mixed>> One entry per evaluated threshold valid at the end of the year
	 */
	public static function evaluate($year, array $income, array $previous)
	{
		$results = array();
		foreach (array(self::SMALL_BUSINESS, self::HARMFUL_BUSINESS) as $code) {
			$threshold = self::validOn($code, sprintf('%04d-12-31', (int) $year));
			if ($threshold === null) {
				continue;
			}
			$amount = self::sum($code, $income, $threshold['gross']);
			$result = array(
				'code' => $code,
				'year' => (int) $year,
				'limit' => $threshold['amount'],
				'gross' => $threshold['gross'],
				'tolerance' => $threshold['tolerance'],
				'amount' => $amount,
				'remaining' => round($threshold['amount'] - $amount, 2),
				'ratio' => round($amount / $threshold['amount'], 4),
				'status' => self::status($amount, $threshold['amount'], $threshold['tolerance']),
				'basis' => $threshold['basis'],
				'source' => $threshold['source'],
				'previous_exceeded' => false,
			);
			// The small business exemption also needs the year before below the limit.
			if ($code === self::SMALL_BUSINESS) {
				$result['previous_exceeded'] = self::sum($code, $previous, $threshold['gross']) > $threshold['amount'];
			}
			$results[] = $result;
		}
		return $results;
	}

	/**
	 * Income of the kinds that count towards a threshold.
	 *
	 * @param string                                                                $code   Threshold code
	 * @param array<int,array{sphere:string,treatment:string,net:float,gross:float}> $income Income per profile kind
	 * @param bool                                                                  $gross  Count gross amounts
	 * @return float
	 */
	private static function sum($code, array $income, $gross)
	{
		$total = 0.0;
		foreach ($income as $row) {
			if (self::counts($code, (string) $row['sphere'], (string) $row['treatment'])) {
				$total += (float) ($gross ? $row['gross'] : $row['net']);
			}
		}
		return round($total, 2);
	}
}
