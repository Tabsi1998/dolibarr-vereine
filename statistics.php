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
 * \file    statistics.php
 * \ingroup vereine
 * \brief   Members on a day by member type, gender, age group and division, as a file for federations (#28).
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

require_once __DIR__.'/class/vereinehonours.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('members', 'other', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read') || !$user->hasRight('adherent', 'lire')) {
	accessforbidden();
}

$honours = new VereineHonours($db);
$action = GETPOST('action', 'aZ09');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$day = GETPOST('day', 'alphanohtml');
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $day, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
	$day = $today;
}
$counts = $honours->statistics($day);
$groupLabel = function ($group) use ($langs) {
	if ($group['from'] === 0) {
		return $langs->transnoentities('VereineStatisticsAgeTo', $group['to']);
	}
	return $group['to'] === null ? $langs->transnoentities('VereineStatisticsAgeFrom', $group['from']) : $group['from'].'–'.$group['to'];
};
// Every count as a row of section, group and number: the same for the page and the file.
$rows = array(array($langs->transnoentities('VereineStatisticsSection'), $langs->transnoentities('VereineStatisticsGroup'), $langs->transnoentities('VereineStatisticsCount')));
$rows[] = array($langs->transnoentities('VereineStatisticsTotal'), $day, $counts['total']);
foreach ($counts['types'] as $type => $number) {
	$rows[] = array($langs->transnoentities('VereineStatisticsTypes'), $type, $number);
}
foreach ($counts['genders'] as $gender => $number) {
	$rows[] = array($langs->transnoentities('VereineStatisticsGenders'), $langs->transnoentities('VereineStatisticsGender_'.$gender), $number);
}
foreach ($counts['groups'] as $index => $group) {
	$rows[] = array($langs->transnoentities('VereineStatisticsAgeGroups'), $groupLabel($group), $counts['ages'][$index]);
}
$rows[] = array($langs->transnoentities('VereineStatisticsAgeGroups'), $langs->transnoentities('VereineStatisticsAgeUnknown'), $counts['unknown_age']);
foreach ($counts['categories'] as $category => $number) {
	$rows[] = array($langs->transnoentities('VereineStatisticsCategories'), $category, $number);
}

if ($action === 'csv') {
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="mitgliederstatistik-'.$day.'.csv"');
	print VereineHonourRules::csv($rows);
	exit;
}

$title = $langs->trans('VereineStatisticsTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-statistics');
print load_fiche_titre($title, '', 'fa-chart-bar');
print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineStatisticsHowTo').'</div>';
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="vereinestatistics"><input type="hidden" name="token" value="'.newToken().'">';
print $langs->trans('VereineStatisticsDay').' <input type="date" name="day" value="'.dol_escape_htmltag($day).'"> ';
print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('Refresh')).'"> ';
print '<a class="button small" href="'.$_SERVER['PHP_SELF'].'?day='.urlencode($day).'&action=csv&token='.newToken().'">'.$langs->trans('VereineStatisticsCsv').'</a></form><br>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-statistics-total="'.$counts['total'].'">';
foreach ($rows as $index => $row) {
	print $index === 0 ? '<tr class="liste_titre">' : '<tr class="oddeven" data-statistics-row="'.dol_escape_htmltag($row[0].'|'.$row[1]).'">';
	print '<td>'.dol_escape_htmltag($row[0]).'</td><td>'.dol_escape_htmltag((string) $row[1]).'</td><td class="right">'.dol_escape_htmltag((string) $row[2]).'</td></tr>';
}
print '</table></div>';
print '<div class="opacitymedium small paddingtop">'.$langs->trans('VereineStatisticsNote').'</div>';

llxFooter();
$db->close();
