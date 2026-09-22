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
 * \file    taxcheck.php
 * \ingroup vereine
 * \brief   Older invoice lines and products without a tax profile: suggestions, a preview, and assigning them.
 *
 * Only the extra field of the tax profile changes; amounts, VAT and status of an invoice stay (#57).
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once __DIR__.'/class/vereinetaxcheck.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('bills', 'products', 'vereine@vereine'));

if (!isModEnabled('vereine') || !isModEnabled('invoice')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read') || !$user->hasRight('facture', 'lire')) {
	accessforbidden();
}

$canWrite = $user->hasRight('facture', 'creer');
$action = GETPOST('action', 'aZ09');
$check = new VereineTaxCheck($db);
$years = $check->years();
$year = GETPOSTINT('year');
if (!in_array($year, $years, true)) {
	$current = (int) dol_print_date(dol_now(), '%Y', 'tzserver');
	$year = in_array($current, $years, true) || !$years ? $current : $years[0];
}

if ($action === 'assign' && $canWrite) {
	$chosen = array();
	$ticked = GETPOST('line', 'array');
	$profiles = GETPOST('profile', 'array');
	foreach (is_array($ticked) ? $ticked : array() as $lineId => $on) {
		if ((int) $on === 1 && isset($profiles[$lineId]) && (int) $profiles[$lineId] > 0) {
			$chosen[(int) $lineId] = (int) $profiles[$lineId];
		}
	}
	$result = $check->assign($chosen, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineTaxCheckAssigned', $result), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?year='.$year);
		exit;
	}
	setEventMessages($result < 0 ? $check->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $check->errors), $result < 0 ? 'errors' : 'warnings');
}


/*
 * View
 */

$known = $check->profiles();
$lines = $check->linesWithout($year, $known['codes']);
$label = function ($profileId) use ($known, $langs) {
	return isset($known['profiles'][$profileId]) ? dol_escape_htmltag($known['profiles'][$profileId]['label']) : $langs->trans('VereineTaxCheckManual');
};

llxHeader('', $langs->trans('VereineTaxCheckTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-taxcheck');
print load_fiche_titre($langs->trans('VereineTaxCheckTitle'), '<a href="'.dol_buildpath('/vereine/vereineindex.php', 1).'">'.$langs->trans('Back').'</a>', 'fa-balance-scale');
print '<div class="info" data-taxcheck-howto="1"><ul>';
foreach (array('VereineTaxCheckHowTo', 'VereineTaxCheckHowToSafe', 'VereineTaxCheckHowToManual') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
print '</ul></div>';

print '<div class="paddingbottom" data-taxcheck-years="1">'.$langs->trans('Year').': ';
foreach ($years ? $years : array($year) as $option) {
	print $option === $year ? '<strong class="paddingright">'.$option.'</strong> '
		: '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?year='.$option.'">'.$option.'</a> ';
}
print '</div>';

// Preview: what the suggestions would give, by profile.
print load_fiche_titre($langs->trans('VereineTaxCheckLinesTitle', count($lines), $year), '', '', 0, 'vereinetaxchecklines');
if (!$lines) {
	print '<div class="ok" data-taxcheck-lines="0">'.$langs->trans('VereineTaxCheckAllAssigned', $year).'</div>';
} else {
	print '<div class="div-table-responsive-no-min"><table class="noborder" data-taxcheck-preview="1">';
	print '<tr class="liste_titre"><td>'.$langs->trans('VereineTaxCheckSuggested').'</td><td class="right">'.$langs->trans('VereineTaxCheckCount').'</td>';
	print '<td class="right">'.$langs->trans('AmountHT').'</td></tr>';
	foreach (VereineTaxCheckRules::totals($lines) as $profileId => $total) {
		print '<tr class="oddeven" data-preview-profile="'.$profileId.'"><td>'.$label($profileId).'</td><td class="right">'.$total['count'].'</td>';
		print '<td class="right nowraponall">'.price($total['total'], 0, $langs, 1, -1, 2).'</td></tr>';
	}
	print '</table></div>';

	if ($canWrite) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?year='.$year.'#vereinetaxchecklines" name="vereinetaxcheck">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="assign">';
	}
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td></td><td>'.$langs->trans('Invoice').'</td><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Customer').'</td>';
	print '<td>'.$langs->trans('Description').'</td><td class="right">'.$langs->trans('AmountHT').'</td><td class="right">'.$langs->trans('VAT').'</td>';
	print '<td>'.$langs->trans('VereineTaxProfileField').'</td><td>'.$langs->trans('VereineTaxCheckWhy').'</td></tr>';
	foreach ($lines as $line) {
		$suggestion = $line['suggestion'];
		print '<tr class="oddeven" data-taxcheck-line="'.$line['id'].'" data-suggested="'.$suggestion['profile'].'" data-reason="'.$suggestion['reason'].'"';
		print ' data-certain="'.($suggestion['certain'] ? 1 : 0).'">';
		print '<td>'.($canWrite ? '<input type="checkbox" name="line['.$line['id'].']" value="1"'.($suggestion['certain'] ? ' checked' : '').'>' : '').'</td>';
		print '<td class="nowraponall"><a href="'.DOL_URL_ROOT.'/compta/facture/card.php?id='.$line['invoice_id'].'">'.dol_escape_htmltag($line['invoice']).'</a></td>';
		print '<td class="nowraponall">'.dol_print_date($line['date'], 'day').'</td><td>'.dol_escape_htmltag($line['customer']).'</td>';
		print '<td>'.dol_escape_htmltag(dol_trunc($line['text'], 70)).'</td><td class="right nowraponall">'.price($line['total'], 0, $langs, 1, -1, 2).'</td>';
		print '<td class="right nowraponall">'.VereineTaxRules::formatRate($line['rate']).'</td><td>';
		if ($canWrite) {
			print '<select name="profile['.$line['id'].']"><option value="0"></option>';
			foreach ($known['codes'] as $profileId) {
				print '<option value="'.$profileId.'"'.($profileId === $suggestion['profile'] ? ' selected' : '').'>'.$label($profileId).'</option>';
			}
			print '</select>';
		} else {
			print $label($suggestion['profile']);
		}
		print '</td><td class="small">'.$langs->trans('VereineTaxCheckReason_'.$suggestion['reason']).'</td></tr>';
	}
	print '</table></div>';
	if ($canWrite) {
		print '<div class="center paddingtop"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineTaxCheckAssign')).'"> ';
		print '<span class="opacitymedium small">'.$langs->trans('VereineTaxCheckAssignHelp').'</span></div>';
		print '</form>';
	}
}

// Lines whose VAT differs from their profile: only to look at.
$deviating = $check->deviating($year);
if ($deviating) {
	print '<br>'.load_fiche_titre($langs->trans('VereineTaxCheckDeviatingTitle', count($deviating)), '', '');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-taxcheck-deviating="'.count($deviating).'">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Invoice').'</td><td>'.$langs->trans('Description').'</td><td class="right">'.$langs->trans('VAT').'</td>';
	print '<td>'.$langs->trans('VereineTaxProfileField').'</td></tr>';
	foreach ($deviating as $line) {
		print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/compta/facture/card.php?id='.$line['invoice_id'].'">'.dol_escape_htmltag($line['invoice']).'</a></td>';
		print '<td>'.dol_escape_htmltag($line['text']).'</td><td class="right">'.VereineTaxRules::formatRate($line['rate']).'</td>';
		print '<td>'.dol_escape_htmltag($line['profile']).' ('.VereineTaxRules::formatRate($line['profile_rate']).')</td></tr>';
	}
	print '</table></div>';
}

// Products for sale without a profile: set on the product card, so its VAT follows.
$products = $check->productsWithout();
print '<br>'.load_fiche_titre($langs->trans('VereineTaxCheckProductsTitle', count($products)), '', '');
if (!$products) {
	print '<div class="ok" data-taxcheck-products="0">'.$langs->trans('VereineTaxCheckProductsDone').'</div>';
} else {
	print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineTaxCheckProductsHelp').'</div>';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-taxcheck-products="'.count($products).'">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Label').'</td><td class="right">'.$langs->trans('VAT').'</td></tr>';
	foreach ($products as $product) {
		print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/product/card.php?id='.$product['id'].'&amp;action=edit&amp;token='.newToken().'">'.dol_escape_htmltag($product['ref']).'</a></td>';
		print '<td>'.dol_escape_htmltag($product['label']).'</td><td class="right">'.VereineTaxRules::formatRate($product['rate']).'</td></tr>';
	}
	print '</table></div>';
}

$legacy = $check->supplierLegacy();
if ($legacy > 0) {
	print '<br><div class="opacitymedium" data-taxcheck-supplier-legacy="'.$legacy.'">'.$langs->trans('VereineTaxCheckSupplierLegacy', $legacy).'</div>';
}

llxFooter();
$db->close();
