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
 * \file    class/vereinedutyrules.class.php
 * \ingroup vereine
 * \brief   What an association has to do again and again, and when it is due (#24), plain PHP.
 *
 * A duty says which function looks after it and how its day follows from the association's year, so a
 * year that does not start in January and statutes with a longer general assembly interval both work.
 * Everything here is rules only: what an association really carries is in its catalogue, see
 * VereineDuties.
 */

/**
 * Rules of the recurring duties.
 */
class VereineDutyRules
{
	/** So many months after the association's year ended. */
	const BASIS_YEAR_END = 'year_end';
	/** So many months after the income and expenditure account was made. */
	const BASIS_ACCOUNT = 'account';
	/** A fixed day of the calendar year after the association's year ended. */
	const BASIS_CALENDAR = 'calendar';
	/** Not due by the calendar but after something happens; it only stands in the catalogue. */
	const BASIS_EVENT = 'event';

	/** Every basis a duty may have. */
	const BASES = array('year_end', 'account', 'calendar', 'event');

	/** From how many days before its day a duty counts as running, until the association says otherwise. */
	const LEAD_DAYS = 60;

	/**
	 * The duties an Austrian association has anyway, as the catalogue starts out.
	 *
	 * Every row names the law it comes from. The association may change all of it afterwards: another
	 * function, another day, or switched off when it does not apply.
	 *
	 * @return array<int,array{code:string,label:string,function_code:string,basis:string,offset_months:int,due_month:int,due_day:int,every_years:int,source:string,active:int}>
	 */
	public static function standard()
	{
		return array(
			// § 21 (1) VerG: within five months of the end of the year.
			array('code' => 'account', 'label' => 'Einnahmen-Ausgaben-Rechnung erstellen', 'function_code' => 'kassier',
				'basis' => self::BASIS_YEAR_END, 'offset_months' => 5, 'due_month' => 0, 'due_day' => 0, 'every_years' => 1,
				'source' => '§ 21 Abs. 1 VerG', 'active' => 1),
			// § 21 (2) VerG: within four months of the account being made.
			array('code' => 'audit', 'label' => 'Rechnungsprüfung durchführen', 'function_code' => 'rechnungspruefung',
				'basis' => self::BASIS_ACCOUNT, 'offset_months' => 4, 'due_month' => 0, 'due_day' => 0, 'every_years' => 1,
				'source' => '§ 21 Abs. 2 VerG', 'active' => 1),
			// § 21 (4) VerG: the members hear about the audited account.
			array('code' => 'inform', 'label' => 'Mitglieder über die geprüfte Rechnung informieren', 'function_code' => 'obmann',
				'basis' => self::BASIS_ACCOUNT, 'offset_months' => 4, 'due_month' => 0, 'due_day' => 0, 'every_years' => 1,
				'source' => '§ 21 Abs. 4 VerG', 'active' => 1),
			// § 5 (2) VerG: as often as the statutes say; the catalogue takes the interval from them.
			array('code' => 'assembly', 'label' => 'Ordentliche Generalversammlung abhalten', 'function_code' => 'obmann',
				'basis' => self::BASIS_YEAR_END, 'offset_months' => 0, 'due_month' => 0, 'due_day' => 0, 'every_years' => 1,
				'source' => '§ 5 Abs. 2 VerG', 'active' => 1),
			// § 18 (1) Z 7 EStG: only for associations that may take deductible donations.
			array('code' => 'donations', 'label' => 'Spendenmeldung an das Finanzamt', 'function_code' => 'kassier',
				'basis' => self::BASIS_CALENDAR, 'offset_months' => 0, 'due_month' => 2, 'due_day' => 31, 'every_years' => 1,
				'source' => '§ 18 Abs. 1 Z 7 EStG', 'active' => 0),
			// § 3 (1) Z 42 EStG: only when the association pays volunteer allowances.
			array('code' => 'volunteers', 'label' => 'Meldung der Freiwilligenpauschale', 'function_code' => 'kassier',
				'basis' => self::BASIS_CALENDAR, 'offset_months' => 0, 'due_month' => 2, 'due_day' => 31, 'every_years' => 1,
				'source' => '§ 3 Abs. 1 Z 42 EStG', 'active' => 0),
			// § 131b BAO: only with a cash register; the yearly receipt is checked within a week.
			array('code' => 'cash_register', 'label' => 'Jahresbeleg der Registrierkasse prüfen', 'function_code' => 'kassier',
				'basis' => self::BASIS_CALENDAR, 'offset_months' => 0, 'due_month' => 1, 'due_day' => 7, 'every_years' => 1,
				'source' => '§ 131b BAO', 'active' => 0),
			// § 14 (2) VerG: not a day of the year but something that follows an election.
			array('code' => 'report', 'label' => 'Organwechsel der Vereinsbehörde anzeigen', 'function_code' => 'obmann',
				'basis' => self::BASIS_EVENT, 'offset_months' => 0, 'due_month' => 0, 'due_day' => 0, 'every_years' => 1,
				'source' => '§ 14 Abs. 2 VerG', 'active' => 1),
		);
	}

	/**
	 * What is wrong with an entry of the catalogue before it is stored.
	 *
	 * @param array<string,mixed> $data Keys code, label, basis, offset_months, due_month, due_day, every_years, lead_days
	 * @return string[] Language keys, empty when fine
	 */
	public static function validate(array $data)
	{
		$errors = array();
		$value = function ($key) use ($data) {
			return isset($data[$key]) ? trim((string) $data[$key]) : '';
		};
		if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $value('code'))) {
			$errors[] = 'VereineDutyErrorCode';
		}
		$label = $value('label');
		if ($label === '' || mb_strlen($label, 'UTF-8') > 128) {
			$errors[] = 'VereineDutyErrorLabel';
		}
		$basis = $value('basis');
		if (!in_array($basis, self::BASES, true)) {
			$errors[] = 'VereineDutyErrorBasis';
		}
		if ($basis === self::BASIS_YEAR_END || $basis === self::BASIS_ACCOUNT) {
			$months = (int) $value('offset_months');
			if ((string) $months !== $value('offset_months') || $months < 0 || $months > 24) {
				$errors[] = 'VereineDutyErrorMonths';
			}
		}
		if ($basis === self::BASIS_CALENDAR) {
			$month = (int) $value('due_month');
			$day = (int) $value('due_day');
			if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
				$errors[] = 'VereineDutyErrorDay';
			}
		}
		$every = $value('every_years') === '' ? 1 : (int) $value('every_years');
		if ($every < 1 || $every > 10) {
			$errors[] = 'VereineDutyErrorEveryYears';
		}
		$lead = $value('lead_days') === '' ? self::LEAD_DAYS : (int) $value('lead_days');
		if ($lead < 0 || $lead > 365) {
			$errors[] = 'VereineDutyErrorLeadDays';
		}
		return $errors;
	}

	/**
	 * Whether a duty falls into an association's year at all: every year, or every so many years since
	 * the catalogue carries it.
	 *
	 * @param array<string,mixed> $duty Entry of the catalogue, keys active, basis, every_years, first_year
	 * @param int                 $year Year the association's year starts in
	 * @return bool
	 */
	public static function planned(array $duty, $year)
	{
		if (empty($duty['active']) || $duty['basis'] === self::BASIS_EVENT) {
			return false;
		}
		$every = isset($duty['every_years']) ? max(1, (int) $duty['every_years']) : 1;
		if ($every === 1) {
			return true;
		}
		$first = isset($duty['first_year']) && (int) $duty['first_year'] > 0 ? (int) $duty['first_year'] : (int) $year;
		return (int) $year >= $first && (((int) $year - $first) % $every) === 0;
	}

	/**
	 * The day a duty is due for an association's year.
	 *
	 * @param array<string,mixed> $duty    Entry of the catalogue, keys basis, offset_months, due_month, due_day
	 * @param string              $end     Last day of the association's year, YYYY-MM-DD
	 * @param string              $made    Day the account was made, empty while it is not
	 * @param string              $fallback Day the account is due, used while it is not made
	 * @return string Day as YYYY-MM-DD, empty when there is none
	 */
	public static function due(array $duty, $end, $made = '', $fallback = '')
	{
		$basis = isset($duty['basis']) ? (string) $duty['basis'] : '';
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $end)) {
			return '';
		}
		if ($basis === self::BASIS_YEAR_END) {
			return self::addMonths($end, (int) $duty['offset_months']);
		}
		if ($basis === self::BASIS_ACCOUNT) {
			$from = (string) $made !== '' ? (string) $made : (string) $fallback;
			return preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? self::addMonths($from, (int) $duty['offset_months']) : '';
		}
		if ($basis === self::BASIS_CALENDAR) {
			$month = (int) $duty['due_month'];
			$day = (int) $duty['due_day'];
			if ($month < 1 || $month > 12 || $day < 1) {
				return '';
			}
			// The calendar year after the association's year ended, so a year to March 2026 reports in 2027.
			$year = (int) substr((string) $end, 0, 4) + 1;
			$last = (int) date('t', gmmktime(12, 0, 0, $month, 1, $year));
			return sprintf('%04d-%02d-%02d', $year, $month, min($day, $last));
		}
		return '';
	}

	/**
	 * The last day of the month so many months later; the same day of the month when it exists.
	 *
	 * @param string $day    Day, YYYY-MM-DD
	 * @param int    $months Months to add
	 * @return string
	 */
	public static function addMonths($day, $months)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $day, $parts)) {
			return '';
		}
		$month = (int) $parts[2] + (int) $months;
		$year = (int) $parts[1] + (int) floor(($month - 1) / 12);
		$month = (($month - 1) % 12 + 12) % 12 + 1;
		$last = (int) date('t', gmmktime(12, 0, 0, $month, 1, $year));
		return sprintf('%04d-%02d-%02d', $year, $month, min((int) $parts[3], $last));
	}

	/**
	 * How a duty stands on a day.
	 *
	 * @param string $due   Day it is due, YYYY-MM-DD
	 * @param string $today Today, YYYY-MM-DD
	 * @param bool   $done  Whether it is done
	 * @param int    $lead  Days from which it counts as running
	 * @return string done, overdue, due or ahead
	 */
	public static function state($due, $today, $done, $lead = self::LEAD_DAYS)
	{
		if ($done) {
			return 'done';
		}
		if ((string) $due === '' || (string) $today === '') {
			return 'ahead';
		}
		if ((string) $due < (string) $today) {
			return 'overdue';
		}
		$limit = gmdate('Y-m-d', gmmktime(12, 0, 0, (int) substr($today, 5, 2), (int) substr($today, 8, 2) + max(0, (int) $lead), (int) substr($today, 0, 4)));
		return (string) $due <= $limit ? 'due' : 'ahead';
	}
}
