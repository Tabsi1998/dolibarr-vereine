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
 * \file    admin/functions.php
 * \ingroup vereine
 * \brief   Function catalogue: board, representation, auditors and how many are needed.
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
require_once __DIR__.'/../class/vereinefunctions.class.php';

$langs->loadLangs(array('admin', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$functions = new VereineFunctions($db);
$edit = array('id' => 0, 'code' => '', 'label' => '', 'board' => false, 'represents' => false, 'auditor' => false, 'min' => 0, 'max' => 0, 'position' => 100, 'active' => true);


/*
 * Actions
 */

if ($action === 'savefunction') {
	$data = array(
		'code' => GETPOST('code', 'aZ09'),
		'label' => GETPOST('label', 'alphanohtml'),
		'board' => GETPOSTINT('board') === 1,
		'represents' => GETPOSTINT('represents') === 1,
		'auditor' => GETPOSTINT('auditor') === 1,
		'min' => GETPOST('min', 'alpha'),
		'max' => GETPOST('max', 'alpha'),
		'position' => GETPOSTINT('position'),
		'active' => GETPOSTINT('active') === 1,
	);
	$result = $functions->save($id, $data, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineFunctionSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	if ($result < 0) {
		setEventMessages($functions->error, null, 'errors');
	} else {
		setEventMessages(null, array_map(array($langs, 'trans'), $functions->errors), 'errors');
	}
	$edit = array_merge($data, array('id' => $id));
} elseif ($action === 'editfunction' && $id > 0) {
	foreach ($functions->fetchAll() as $function) {
		if ($function['id'] === $id) {
			$edit = $function;
		}
	}
}


/*
 * View
 */

$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-functions');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = vereineAdminPrepareHead();
print dol_get_fiche_head($head, 'functions', $title, -1, 'fa-landmark');

print '<div class="info" data-functions-howto="1"><ul>';
foreach (array('VereineFunctionHowToCatalogue', 'VereineFunctionHowToBoard', 'VereineFunctionHowToRepresents', 'VereineFunctionHowToAuditors', 'VereineFunctionHowToTerms') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
print '</ul></div>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineFunctionLabel').'</td><td class="center">'.$langs->trans('VereineFunctionBoard').'</td>';
print '<td class="center">'.$langs->trans('VereineFunctionRepresents').'</td><td class="center">'.$langs->trans('VereineFunctionAuditor').'</td>';
print '<td class="center">'.$langs->trans('VereineFunctionCount').'</td><td class="center">'.$langs->trans('Status').'</td><td></td></tr>';
foreach ($functions->fetchAll() as $function) {
	print '<tr class="oddeven" data-function="'.dol_escape_htmltag($function['code']).'" data-active="'.($function['active'] ? 1 : 0).'">';
	print '<td>'.dol_escape_htmltag($function['label']).' <span class="opacitymedium small">'.dol_escape_htmltag($function['code']).'</span></td>';
	print '<td class="center">'.yn($function['board'] ? 1 : 0).'</td><td class="center">'.yn($function['represents'] ? 1 : 0).'</td><td class="center">'.yn($function['auditor'] ? 1 : 0).'</td>';
	print '<td class="center nowraponall">'.vereineFunctionCount($function).'</td>';
	print '<td class="center">'.dolGetBadge($langs->trans($function['active'] ? 'Enabled' : 'Disabled'), '', $function['active'] ? 'success' : 'secondary').'</td>';
	print '<td class="right"><a class="editfielda" href="'.$_SERVER['PHP_SELF'].'?action=editfunction&amp;id='.((int) $function['id']).'&amp;token='.newToken().'#vereinefunction">'.img_edit().'</a></td></tr>';
}
print '</table></div><br>';

print load_fiche_titre($langs->trans($edit['id'] > 0 ? 'VereineFunctionEdit' : 'VereineFunctionNew'), '', '');
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinefunction" id="vereinefunction">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savefunction">';
print '<input type="hidden" name="id" value="'.((int) $edit['id']).'">';
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate fieldrequired"><label for="code">'.$langs->trans('VereineConsentCode').'</label></td>';
print '<td><input type="text" id="code" name="code" class="minwidth200" maxlength="32" value="'.dol_escape_htmltag($edit['code']).'"'.($edit['id'] > 0 ? ' readonly' : '').'>';
print ' <span class="opacitymedium small">'.$langs->trans('VereineFunctionCodeHelp').'</span></td></tr>';
print '<tr><td class="fieldrequired"><label for="label">'.$langs->trans('VereineFunctionLabel').'</label></td>';
print '<td><input type="text" id="label" name="label" class="minwidth300" maxlength="128" value="'.dol_escape_htmltag($edit['label']).'"></td></tr>';
foreach (array('board' => 'VereineFunctionBoard', 'represents' => 'VereineFunctionRepresents', 'auditor' => 'VereineFunctionAuditor', 'active' => 'Enabled') as $flag => $labelKey) {
	print '<tr><td><label for="'.$flag.'">'.$langs->trans($labelKey).'</label></td><td><input type="checkbox" id="'.$flag.'" name="'.$flag.'" value="1"'.(!empty($edit[$flag]) ? ' checked' : '').'></td></tr>';
}
print '<tr><td><label for="min">'.$langs->trans('VereineFunctionCount').'</label></td><td>';
print '<input type="number" min="0" max="99" id="min" name="min" class="width50" value="'.dol_escape_htmltag((string) $edit['min']).'"> - ';
print '<input type="number" min="0" max="99" id="max" name="max" class="width50" value="'.dol_escape_htmltag((string) $edit['max']).'">';
print ' <span class="opacitymedium small">'.$langs->trans('VereineFunctionCountHelp').'</span></td></tr>';
print '<tr><td><label for="position">'.$langs->trans('Position').'</label></td>';
print '<td><input type="number" min="0" id="position" name="position" class="width50" value="'.((int) $edit['position']).'"></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
