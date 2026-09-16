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
 * \file    vereineindex.php
 * \ingroup vereine
 * \brief   Overview of the association: its data and what still needs attention.
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
 * @var Societe $mysoc
 */

require_once __DIR__.'/lib/vereine.lib.php';
require_once __DIR__.'/class/vereineprofile.class.php';
require_once __DIR__.'/class/vereineorganization.class.php';

$langs->loadLangs(array('members', 'companies', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
// External users (portal accounts) see nothing of the association's administration.
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read')) {
	accessforbidden();
}


/*
 * View
 */

$organization = VereineOrganization::load($mysoc);
// Open points between members and third parties, for users who may see both.
$partnerIssues = null;
if ($user->hasRight('adherent', 'lire') && $user->hasRight('societe', 'lire')) {
	require_once __DIR__.'/class/vereinepartnerservice.class.php';
	$partnerService = new VereinePartnerService($db);
	$partnerReport = $partnerService->report(dol_print_date(dol_now(), '%Y-%m-%d'));
	$partnerIssues = 0;
	foreach (array('without_partner', 'attributes', 'differences', 'orphans', 'minors', 'duplicates') as $section) {
		$partnerIssues += count($partnerReport[$section]);
	}
}
$checks = VereineOrganization::checks($organization, isModEnabled('api'), $partnerIssues);
$profile = $organization['country_profile'];
$notSet = '<span class="opacitymedium">'.$langs->trans('VereineNotSet').'</span>';

$title = $langs->trans('VereineOverviewTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-index');

$morehtmlright = '';
if (!empty($user->admin)) {
	$morehtmlright = dolGetButtonTitle($langs->trans('VereineEditAssociation'), '', 'fa fa-pen', dol_buildpath('/vereine/admin/setup.php', 1));
}
print load_fiche_titre($title, $morehtmlright, 'fa-landmark');

print '<div class="fichecenter"><div class="fichethirdleft">';

// The association's data
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('VereineAssociationData').'</th></tr>';

$rows = array();
$rows[] = array($langs->trans('VereineCountryProfile'), $langs->trans('VereineProfile'.$profile).($organization['country_profile_complete'] ? '' : ' - '.$langs->trans('VereineProfilePreview')));
$rows[] = array($langs->trans('Name'), $organization['name'] !== '' ? dol_escape_htmltag($organization['name']) : $notSet);
$address = trim($organization['address']['street']."\n".trim($organization['address']['zip'].' '.$organization['address']['town']));
$rows[] = array($langs->trans('Address'), $address !== '' ? dol_nl2br(dol_escape_htmltag($address)) : $notSet);
$rows[] = array($langs->trans('VereineRegisterNumber'.$organization['register']['kind']), $organization['register']['number'] !== '' ? dol_escape_htmltag($organization['register']['number']) : $notSet);
if ($profile === VereineProfile::GERMANY) {
	$rows[] = array($langs->trans('VereineRegisterCourt'), $organization['register']['court'] !== '' ? dol_escape_htmltag($organization['register']['court']) : $notSet);
} else {
	$rows[] = array($langs->trans('VereineAuthority'), $organization['authority'] !== '' ? dol_escape_htmltag($organization['authority']) : $notSet);
}
$founded = $notSet;
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $organization['founded'], $parts)) {
	$founded = dol_print_date(dol_mktime(12, 0, 0, (int) $parts[2], (int) $parts[3], (int) $parts[1]), 'day');
}
$rows[] = array($langs->trans('VereineFounded'), $founded);
$rows[] = array($langs->trans('VereineNonprofit'), yn($organization['nonprofit'] ? 1 : 0));
$rows[] = array($langs->trans('VereineFiscalYearStart'), dol_escape_htmltag(vereineMonthName($organization['fiscal_year_start_month'])));
$rows[] = array($langs->trans('VereinePurpose'), $organization['purpose'] !== '' ? dol_nl2br(dol_escape_htmltag($organization['purpose'])) : $notSet);

foreach ($rows as $row) {
	print '<tr class="oddeven"><td class="titlefield tdtop">'.$row[0].'</td><td>'.$row[1].'</td></tr>';
}
print '</table>';
print '</div>';

print '</div><div class="fichetwothirdright">';

// What still needs attention
$fixLinks = array(
	'setup' => array(dol_buildpath('/vereine/admin/setup.php', 1), 'VereineFixInSetup'),
	'company' => array(DOL_URL_ROOT.'/admin/company.php', 'VereineFixInCompany'),
	'modules' => array(DOL_URL_ROOT.'/admin/modules.php?search_keyword=api', 'VereineFixInModules'),
	'partners' => array(dol_buildpath('/vereine/partners.php', 1), 'VereineFixInPartners'),
);
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('VereineChecks').'</th><th class="center">'.$langs->trans('Status').'</th><th></th></tr>';
foreach ($checks as $check) {
	print '<tr class="oddeven" data-check="'.dol_escape_htmltag($check['code']).'" data-status="'.dol_escape_htmltag($check['status']).'">';
	print '<td>'.$langs->trans($check['label'], $check['value']).'</td>';
	print '<td class="center nowraponall">'.vereineCheckBadge($check['status']).'</td>';
	print '<td class="right nowraponall">';
	// Setup pages need an administrator; the reconciliation page only its own rights.
	if ($check['fix'] !== '' && isset($fixLinks[$check['fix']]) && (!empty($user->admin) || $check['fix'] === 'partners')) {
		print '<a href="'.$fixLinks[$check['fix']][0].'">'.$langs->trans($fixLinks[$check['fix']][1]).'</a>';
	}
	print '</td></tr>';
}
print '</table>';
print '</div>';

print '<div class="opacitymedium small">'.$langs->trans('VereineNoTaxAdvice').'</div>';

print '</div></div>';

llxFooter();
$db->close();
