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
 * \file    functions.php
 * \ingroup vereine
 * \brief   Board and functions: who holds which function on a day, and what does not fit.
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

require_once __DIR__.'/class/vereinefunctions.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('members', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read') || !$user->hasRight('adherent', 'lire')) {
	accessforbidden();
}

$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$day = GETPOST('day', 'alpha');
if (!VereineFunctionRules::isDate($day)) {
	$day = $today;
}

$store = new VereineFunctions($db);
$functions = $store->fetchAll(true);
$terms = $store->terms();
$check = VereineFunctionRules::check($functions, $terms, $day);
$names = array();
foreach ($terms as $term) {
	$names[$term['member_id']] = $term['member_name'];
}
$labels = array();
foreach ($functions as $function) {
	$labels[$function['id']] = $function['label'];
}


/*
 * View
 */

$title = $langs->trans('VereineFunctionsTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-functions');
print load_fiche_titre($title, '', 'fa-landmark');
print '<span class="opacitymedium">'.$langs->trans('VereineFunctionsIntro').'</span><br><br>';

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="vereinefunctionsday">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<label for="day">'.$langs->trans('VereineFunctionsDay').'</label> <input type="date" id="day" name="day" value="'.dol_escape_htmltag($day).'"> ';
print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Refresh')).'">';
print '</form><br>';

if ($check['problems']) {
	print '<div class="warning" data-problems="'.count($check['problems']).'"><ul>';
	foreach ($check['problems'] as $problem) {
		$members = array();
		foreach ($problem['members'] as $memberId) {
			$members[] = isset($names[$memberId]) ? $names[$memberId] : '#'.$memberId;
		}
		$label = $problem['function_id'] > 0 && isset($labels[$problem['function_id']]) ? $labels[$problem['function_id']] : '';
		$code = '';
		foreach ($functions as $function) {
			if ($function['id'] === $problem['function_id']) {
				$code = $function['code'];
			}
		}
		print '<li data-problem="'.$problem['kind'].'" data-function="'.dol_escape_htmltag($code).'">';
		print $langs->trans('VereineFunctionProblem_'.$problem['kind'], dol_escape_htmltag($label), $problem['count'], dol_escape_htmltag(implode(', ', $members))).'</li>';
	}
	print '</ul></div>';
} else {
	print '<div class="ok" data-problems="0">'.$langs->trans('VereineFunctionsAllFine').'</div>';
}

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineFunctionLabel').'</td><td class="center">'.$langs->trans('VereineFunctionCount').'</td><td>'.$langs->trans('VereineFunctionsHolders').'</td></tr>';
if (!$functions) {
	print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('VereineFunctionsNone').'</span></td></tr>';
}
foreach ($functions as $function) {
	$holders = $check['holders'][$function['id']];
	print '<tr class="oddeven" data-function-row="'.dol_escape_htmltag($function['code']).'" data-holders="'.count($holders).'">';
	print '<td>'.dol_escape_htmltag($function['label']);
	if ($function['board']) {
		print ' '.dolGetBadge($langs->trans('VereineFunctionBoard'), '', 'info');
	}
	print '</td><td class="center nowraponall">'.vereineFunctionCount($function).'</td><td>';
	$links = array();
	foreach ($holders as $memberId) {
		$links[] = '<a href="'.dol_buildpath('/vereine/member_association.php', 1).'?id='.((int) $memberId).'#vereinefunctions">'.dol_escape_htmltag($names[$memberId]).'</a>';
	}
	print $links ? implode(', ', $links) : '<span class="opacitymedium">'.$langs->trans('VereineFunctionsVacant').'</span>';
	print '</td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
