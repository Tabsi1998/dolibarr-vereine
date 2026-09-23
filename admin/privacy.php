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
 * \file    admin/privacy.php
 * \ingroup vereine
 * \brief   Data protection setup (#10): what the module keeps about former members, why and how long.
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/vereine.lib.php';
require_once __DIR__.'/../class/vereineerasure.class.php';

$langs->loadLangs(array('admin', 'members', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$erasure = new VereineErasure($db);

if ($action === 'save') {
	$entered = array();
	foreach (VereineErasureRules::CATEGORIES as $kind => $rule) {
		if ($rule['setting']) {
			$entered[$kind] = GETPOST('years_'.$kind, 'alphanohtml');
		}
	}
	$result = $erasure->savePeriods($entered, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	setEventMessages($result < 0 ? $erasure->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $erasure->errors), 'errors');
}

$periods = VereineErasure::periods();
$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-privacy');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');
print dol_get_fiche_head(vereineAdminPrepareHead(), 'privacy', $title, -1, 'fa-user-shield');
print '<div class="info" data-privacy-howto="1">'.$langs->trans('VereineErasureSetupHowTo').'</div>';

// The inventory: every kind of data about a former member, what it is for, and what happens when.
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereineprivacy">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
print '<div class="div-table-responsive"><table class="noborder centpercent" data-erasure-kinds="'.count(VereineErasureRules::CATEGORIES).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineErasureKind').'</td><td>'.$langs->trans('VereineErasureWhy').'</td>';
print '<td>'.$langs->trans('VereineErasureStart').'</td><td>'.$langs->trans('VereineErasurePeriod').'</td><td>'.$langs->trans('VereineErasureThen').'</td></tr>';
foreach (VereineErasureRules::CATEGORIES as $kind => $rule) {
	print '<tr class="oddeven" data-erasure-kind="'.$kind.'" data-erasure-years="'.($periods[$kind] === null ? 'never' : (int) $periods[$kind]).'">';
	print '<td class="tdtop"><strong>'.$langs->trans('VereineErasureKind_'.$kind).'</strong><br><span class="small opacitymedium">'.$langs->trans('VereineErasureWhat_'.$kind).'</span></td>';
	print '<td class="tdtop small">'.$langs->trans('VereineErasureWhy_'.$kind).'</td>';
	print '<td class="tdtop nowraponall">'.$langs->trans('VereineErasureStart_'.$rule['start']).'</td>';
	print '<td class="tdtop nowraponall">';
	if ($rule['setting']) {
		print '<input type="number" name="years_'.$kind.'" min="0" max="'.VereineErasureRules::MAX_YEARS.'" class="width50" value="'.((int) $periods[$kind]).'"> '.$langs->trans('VereineErasureYearsUnit');
	} else {
		print $periods[$kind] === null ? $langs->trans('VereineErasureNever') : $langs->trans('VereineErasureYears', (int) $periods[$kind]);
	}
	print '</td><td class="tdtop">'.$langs->trans('VereineErasureAction_'.$rule['action']).'</td></tr>';
}
print '</table></div>';
print '<div class="center paddingtop"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div></form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
