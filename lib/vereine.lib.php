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
 * \file    lib/vereine.lib.php
 * \ingroup vereine
 * \brief   Shared functions of the Vereine pages.
 */

/**
 * Tabs of the module's setup pages.
 *
 * @return array<int,array<int,string>>
 */
function vereineAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load('vereine@vereine');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/vereine/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabAssociation');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/partners.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabPartners');
	$head[$h][2] = 'partners';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/taxprofiles.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabTaxProfiles');
	$head[$h][2] = 'taxprofiles';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'vereine@vereine');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'vereine@vereine', 'remove');

	return $head;
}

/**
 * Name of a month in the user's language.
 *
 * @param int $month 1 to 12
 * @return string
 */
function vereineMonthName($month)
{
	global $langs;

	$keys = array(1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
	return isset($keys[$month]) ? $langs->trans($keys[$month]) : '';
}

/**
 * A VAT rate as people write it: 10, 13, 20, 0 - decimals only when there are any.
 *
 * @param float $rate Rate in percent
 * @return string
 */
function vereineRate($rate)
{
	$text = number_format((float) $rate, 3, ',', '');
	return rtrim(rtrim($text, '0'), ',');
}

/**
 * A status badge for a check result.
 *
 * @param string $status VereineOrganization::CHECK_OK or CHECK_WARNING
 * @return string HTML
 */
function vereineCheckBadge($status)
{
	global $langs;

	if ($status === VereineOrganization::CHECK_OK) {
		return dolGetBadge($langs->trans('VereineCheckStatusOk'), '', 'success');
	}
	return dolGetBadge($langs->trans('VereineCheckStatusWarning'), '', 'warning');
}

/**
 * Open invoices of a third party, for the membership tabs of third party and member.
 *
 * @param DoliDB $db    Database handler
 * @param int    $socid Third party id
 * @return void
 */
function vereinePrintOpenInvoices($db, $socid)
{
	global $conf, $langs, $user;

	// Membership fee invoices get their own marker in 0.3 (issue #14).
	if (!isModEnabled('invoice') || !$user->hasRight('facture', 'lire')) {
		return;
	}
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

	print load_fiche_titre($langs->trans('VereineOpenInvoices'), '', '');
	$sql = "SELECT f.rowid, f.ref, f.total_ttc, f.date_lim_reglement FROM ".MAIN_DB_PREFIX."facture as f";
	$sql .= " WHERE f.fk_soc = ".((int) $socid)." AND f.fk_statut = ".Facture::STATUS_VALIDATED." AND f.paye = 0";
	$sql .= " AND f.entity IN (".getEntity('invoice').") ORDER BY f.date_lim_reglement ASC";
	$resql = $db->query($sql);
	print '<table class="noborder centpercent" data-open-invoices="1">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td class="center">'.$langs->trans('DateDue').'</td><td class="right">'.$langs->trans('AmountTTC').'</td></tr>';
	$count = 0;
	while ($resql && ($row = $db->fetch_object($resql))) {
		$invoice = new Facture($db);
		$invoice->id = (int) $row->rowid;
		$invoice->ref = (string) $row->ref;
		print '<tr class="oddeven"><td>'.$invoice->getNomUrl(1).'</td><td class="center">'.dol_print_date($db->jdate($row->date_lim_reglement), 'day').'</td>';
		print '<td class="right">'.price((float) $row->total_ttc, 0, $langs, 1, -1, -1, $conf->currency).'</td></tr>';
		$count++;
	}
	if ($count === 0) {
		print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	print '</table>';
	print '<div class="opacitymedium small">'.$langs->trans('VereineOpenInvoicesNote').'</div>';
}

/**
 * Guardians of a minor member: contacts of the third party in the guardian category.
 *
 * @param DoliDB $db    Database handler
 * @param int    $socid Third party id
 * @return void
 */
function vereinePrintGuardians($db, $socid)
{
	global $langs;

	require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
	dol_include_once('/vereine/class/vereinepartnerservice.class.php');

	$guardianCategory = getDolGlobalInt(VereinePartnerService::CONST_GUARDIAN);
	print load_fiche_titre($langs->trans('VereineGuardians'), '', '');
	print '<table class="noborder centpercent" data-guardians="1">';
	$found = 0;
	if ($guardianCategory > 0) {
		$sql = "SELECT sp.rowid FROM ".MAIN_DB_PREFIX."socpeople as sp";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."categorie_contact as cc ON cc.fk_socpeople = sp.rowid";
		$sql .= " WHERE sp.fk_soc = ".((int) $socid)." AND cc.fk_categorie = ".$guardianCategory;
		$resql = $db->query($sql);
		while ($resql && ($row = $db->fetch_object($resql))) {
			$contact = new Contact($db);
			if ($contact->fetch((int) $row->rowid) > 0) {
				print '<tr class="oddeven"><td>'.$contact->getNomUrl(1).'</td><td>'.dol_print_email($contact->email, $contact->id, (int) $socid, 1).'</td></tr>';
				$found++;
			}
		}
	}
	if ($found === 0) {
		print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">'.$langs->trans('VereineGuardiansNone').'</span></td></tr>';
	}
	print '</table>';
}

/**
 * What the module did with a member and its third party.
 *
 * @param DoliDB $db       Database handler
 * @param int    $memberId Member id, 0 for none
 * @param int    $socid    Third party id, 0 for none
 * @return void
 */
function vereinePrintLog($db, $memberId, $socid)
{
	global $langs;

	dol_include_once('/vereine/class/vereinelog.class.php');

	print load_fiche_titre($langs->trans('VereineLogTitle'), '', '');
	print '<table class="noborder centpercent" data-log="1">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Action').'</td><td>'.$langs->trans('Description').'</td></tr>';
	$entries = VereineLog::recent($db, (int) $memberId, (int) $socid, 20);
	foreach ($entries as $entry) {
		print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($entry['date'], 'dayhour').'</td>';
		print '<td>'.dol_escape_htmltag($langs->trans('VereineLog_'.$entry['action'])).'</td>';
		print '<td>'.dol_escape_htmltag($entry['message']).'</td></tr>';
	}
	if (!$entries) {
		print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	print '</table>';
}
