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
 * \file    class/vereinecashregister.class.php
 * \ingroup vereine
 * \brief   Cash register duty (Registrierkassenpflicht) per sphere of an Austrian association, plain PHP.
 *
 * The module is no cash register (RKSV). It tells from the invoices in Dolibarr whether a
 * sphere needs one; sales without invoice in Dolibarr are not seen.
 */

require_once __DIR__.'/vereinetaxrules.class.php';

/**
 * Rules of § 131b BAO and the Barumsatzverordnung 2015 for associations.
 */
class VereineCashRegister
{
	/** Yearly turnover per business from which the duty can apply, § 131b (1) no. 2 BAO. */
	const TURNOVER_LIMIT = 15000.0;
	/** Cash turnover per year that must be exceeded as well. */
	const CASH_LIMIT = 7500.0;
	/** Operating days a year of a small association canteen, § 131 (4) no. 1 lit. d BAO. */
	const SMALL_CANTEEN_DAYS = 52;

	/**
	 * Dolibarr payment types that count as cash turnover: cash, card, cheque, online payment.
	 * § 131b (1) no. 3 BAO counts cash, debit and credit cards, comparable electronic payments,
	 * cash cheques and vouchers; cheques and online payments are counted to rather warn early.
	 */
	const CASH_PAYMENT_CODES = array('LIQ', 'CB', 'CHQ', 'VAD');

	/** A sphere without sales, so no cash register question. */
	const STATUS_NOT_RELEVANT = 'not_relevant';
	/** Indispensable auxiliary business: simplified cash count, § 3 (1) and § 1 (4) BarUV 2015. */
	const STATUS_EXEMPT = 'exempt';
	/** Small association festival: exempt while all festivals stay within 72 hours a year, § 3 (2) BarUV 2015. */
	const STATUS_EXEMPT_FESTIVAL = 'exempt_festival';
	/** Below both limits. */
	const STATUS_OK = 'ok';
	/** From 80 % of both limits. */
	const STATUS_NEAR = 'near';
	/** Both limits exceeded: a cash register is needed. */
	const STATUS_REQUIRED = 'required';

	/**
	 * Turnover limit of a small association canteen in a calendar year.
	 *
	 * § 131 (4) no. 1 BAO: EUR 45,000 from 2026; EUR 30,000 before, confirmed for 2016 by the
	 * ministry's brochure.
	 *
	 * @param int $year Calendar year
	 * @return float
	 */
	public static function smallCanteenLimit($year)
	{
		return (int) $year >= 2026 ? 45000.0 : 30000.0;
	}

	/**
	 * Whether a sphere needs a cash register for the income of a year.
	 *
	 * @param string $sphere   Sphere code
	 * @param float  $turnover Turnover of the year, gross
	 * @param float  $cash     Cash turnover of the year
	 * @return string One of the STATUS constants
	 */
	public static function status($sphere, $turnover, $cash)
	{
		if (in_array($sphere, array(VereineTaxRules::SPHERE_IDEAL, VereineTaxRules::SPHERE_ASSETS), true)) {
			return self::STATUS_NOT_RELEVANT;
		}
		if ($sphere === VereineTaxRules::SPHERE_ESSENTIAL) {
			return self::STATUS_EXEMPT;
		}
		if ($sphere === VereineTaxRules::SPHERE_FESTIVAL) {
			return self::STATUS_EXEMPT_FESTIVAL;
		}
		$turnover = (float) $turnover;
		$cash = (float) $cash;
		if ($turnover > self::TURNOVER_LIMIT && $cash > self::CASH_LIMIT) {
			return self::STATUS_REQUIRED;
		}
		if ($turnover >= self::TURNOVER_LIMIT * 0.8 && $cash >= self::CASH_LIMIT * 0.8) {
			return self::STATUS_NEAR;
		}
		return self::STATUS_OK;
	}

	/**
	 * Share a cash payment of an invoice out over the spheres of the invoice's lines.
	 *
	 * @param array<string,float> $grossBySphere Gross amount of the invoice's lines per sphere ('' without profile)
	 * @param float               $cash          Cash paid on the invoice
	 * @return array<string,float> Cash per sphere, rounded to cents
	 */
	public static function allocate(array $grossBySphere, $cash)
	{
		$total = 0.0;
		foreach ($grossBySphere as $gross) {
			$total += (float) $gross;
		}
		$shares = array();
		if (abs($total) < 0.005) {
			return $shares;
		}
		foreach ($grossBySphere as $sphere => $gross) {
			$shares[(string) $sphere] = round((float) $cash * (float) $gross / $total, 2);
		}
		return $shares;
	}
}
