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
 * \file    class/vereineoverpaymentrules.class.php
 * \ingroup vereine
 * \brief   Rules of overpayments (#54): what is too much, and what may happen to it.
 *
 * Without database: the page, the invoice card and the income and expenditure account ask here.
 */

/**
 * Rules of overpayments on customer invoices.
 */
class VereineOverpaymentRules
{
	/** The excess stays as a credit of the customer, Dolibarr's own discount. */
	const KIND_CREDIT = 'credit';
	/** The excess goes back to the customer. */
	const KIND_REFUND = 'refund';
	/** The excess was given freely and becomes a donation. */
	const KIND_DONATION = 'donation';
	/** What may happen to an excess, in the order the page offers it. */
	const KINDS = array('credit', 'refund', 'donation');

	/** Nobody decided yet. */
	const STATE_OPEN = 'open';
	/** Somebody converted the excess with Dolibarr's own button on the invoice. */
	const STATE_DOLIBARR = 'dolibarr';

	/**
	 * What was paid over the invoice: payments plus credit notes and down payments used, less the total.
	 *
	 * The way Dolibarr computes it when it converts an excess into a credit, to the cent.
	 *
	 * @param float $total   Invoice total including tax
	 * @param float $paid    Sum of the payments
	 * @param float $credits Sum of credit notes and down payments used on the invoice
	 * @return float 0 when nothing was paid over
	 */
	public static function excess($total, $paid, $credits)
	{
		$excess = round((float) $paid + (float) $credits - (float) $total, 2);
		return $excess >= 0.01 ? $excess : 0.0;
	}

	/**
	 * Where an excess stands.
	 *
	 * @param string $assigned        Kind chosen in the module, empty when none
	 * @param bool   $dolibarrCredit  Whether Dolibarr's own button made a credit of it
	 * @return string One of KINDS, STATE_DOLIBARR or STATE_OPEN
	 */
	public static function state($assigned, $dolibarrCredit)
	{
		if (in_array((string) $assigned, self::KINDS, true)) {
			return (string) $assigned;
		}
		return $dolibarrCredit ? self::STATE_DOLIBARR : self::STATE_OPEN;
	}

	/**
	 * Why an excess may not be assigned this way; nothing when it may.
	 *
	 * @param string $kind      One of KINDS
	 * @param float  $excess    What was paid over
	 * @param string $state     Where it stands, see state()
	 * @param bool   $confirmed For a donation: the excess was given freely, for nothing in return
	 * @return string[] Language keys
	 */
	public static function check($kind, $excess, $state, $confirmed)
	{
		if (!in_array((string) $kind, self::KINDS, true)) {
			return array('VereineOverpaymentErrorKind');
		}
		if ((float) $excess < 0.01) {
			return array('VereineOverpaymentErrorNone');
		}
		if ($state !== self::STATE_OPEN) {
			return array('VereineOverpaymentErrorAssigned');
		}
		if ($kind === self::KIND_DONATION && !$confirmed) {
			return array('VereineOverpaymentErrorConfirm');
		}
		return array();
	}

	/**
	 * A payment of an invoice whose excess became a donation: the donation leaves the invoice's areas.
	 *
	 * Only the payment that brought the excess in carries it, never more than it paid.
	 *
	 * @param float $amount   What the payment paid on the invoice
	 * @param float $donation The excess that became a donation, 0 when none
	 * @return array{invoice:float,donation:float}
	 */
	public static function splitDonation($amount, $donation)
	{
		$amount = round((float) $amount, 2);
		$part = round(max(0.0, min((float) $donation, $amount)), 2);
		return array('invoice' => round($amount - $part, 2), 'donation' => $part);
	}
}
