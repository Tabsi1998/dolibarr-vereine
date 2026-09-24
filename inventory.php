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
 * \file    inventory.php
 * \ingroup vereine
 * \brief   The association's equipment and who has it: lending, return, reservations for events (#26).
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

require_once __DIR__.'/class/vereineloans.class.php';
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

$loans = new VereineLoans($db);
$action = GETPOST('action', 'aZ09');
$canWrite = $user->hasRight('adherent', 'creer');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');


/*
 * Actions
 */

if ($action === 'lend' && $canWrite && isModEnabled('resource')) {
	$result = $loans->lend(array('resource_id' => GETPOSTINT('resource'), 'member_id' => GETPOSTINT('member'), 'issued_on' => GETPOST('issued_on', 'alphanohtml'),
		'due_on' => GETPOST('due_on', 'alphanohtml'), 'condition' => GETPOST('condition', 'alphanohtml')), $today, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineLoanLent'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	setEventMessages($result < 0 ? $loans->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $loans->errors), 'errors');
} elseif ($action === 'giveback' && $canWrite) {
	$result = $loans->giveBack(GETPOSTINT('loan'), GETPOST('returned_on', 'alphanohtml'), GETPOST('condition', 'alphanohtml'), $today, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineLoanReturned'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	setEventMessages($result < 0 ? $loans->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $loans->errors), 'errors');
}


/*
 * View
 */

$title = $langs->trans('VereineInventoryTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-inventory');
print load_fiche_titre($title, '', 'fa-headset');
print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineInventoryHowTo').'</div>';
if (!isModEnabled('resource')) {
	print '<div class="warning" data-inventory="module-off">'.$langs->trans('VereineInventoryModuleOff').'</div>';
	llxFooter();
	$db->close();
	exit;
}

$resources = $loans->resources($today);
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-resources="'.count($resources).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Type').'</td><td>'.$langs->trans('VereineInventoryNumber').'</td>';
print '<td>'.$langs->trans('VereineLoanState').'</td><td>'.$langs->trans('VereineInventoryReserved').'</td></tr>';
foreach ($resources as $resource) {
	$loan = $resource['loan'];
	$state = $loan === null ? 'available' : VereineLoanRules::state($loan['due_on'], '', $today);
	print '<tr class="oddeven" data-resource="'.$resource['id'].'" data-loan-state="'.$state.'">';
	print '<td><a href="'.DOL_URL_ROOT.'/resource/card.php?id='.$resource['id'].'">'.dol_escape_htmltag($resource['ref']).'</a></td>';
	print '<td>'.dol_escape_htmltag($resource['type']).'</td><td>'.dol_escape_htmltag($resource['asset_number']).'</td><td>';
	if ($loan === null) {
		print '<span class="badge badge-status4">'.$langs->trans('VereineLoanState_available').'</span>';
	} else {
		print '<span class="badge '.($state === 'overdue' ? 'badge-status8' : 'badge-status1').'">'.$langs->trans('VereineLoanState_'.$state).'</span> ';
		print dol_escape_htmltag($langs->transnoentities('VereineLoanWith', $loan['name'], dol_print_date(dol_stringtotime($loan['due_on']), 'day')));
		if ($canWrite) {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinegiveback'.$loan['id'].'" class="paddingtop">';
			print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="giveback"><input type="hidden" name="loan" value="'.$loan['id'].'">';
			print '<input type="date" name="returned_on" value="'.dol_escape_htmltag($today).'"> ';
			print '<input type="text" name="condition" size="24" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('VereineLoanConditionIn')).'"> ';
			print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineLoanGiveBack')).'"></form>';
		}
	}
	print '</td><td>'.($resource['reserved'] !== null ? '<a href="'.DOL_URL_ROOT.'/comm/action/card.php?id='.$resource['reserved']['id'].'">'
		.dol_escape_htmltag($resource['reserved']['label']).'</a> '.vereineFormatDay($resource['reserved']['day']) : '').'</td></tr>';
}
if (!$resources) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('VereineInventoryNone').'</span></td></tr>';
}
print '</table></div>';
print '<div class="small opacitymedium paddingtop"><a href="'.DOL_URL_ROOT.'/resource/card.php?action=create">'.$langs->trans('VereineInventoryAdd').'</a></div>';

if ($canWrite) {
	$available = array();
	foreach ($resources as $resource) {
		if ($resource['loan'] === null) {
			$available[$resource['id']] = $resource['ref'].($resource['type'] !== '' ? ' ('.$resource['type'].')' : '');
		}
	}
	$members = array();
	$resql = $db->query("SELECT rowid, firstname, lastname FROM ".MAIN_DB_PREFIX."adherent WHERE statut = 1 AND entity IN (".getEntity('adherent').") ORDER BY lastname, firstname");
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$members[(int) $obj->rowid] = trim($obj->lastname.' '.$obj->firstname);
	}
	if ($available && $members) {
		print '<br>'.load_fiche_titre($langs->trans('VereineLoanNew'), '', '', 0, 'vereineloannew');
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinelend">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="lend">';
		print '<table class="border centpercent">';
		print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('VereineLoanResource').'</td><td>'.Form::selectarray('resource', $available, GETPOSTINT('resource'), 1, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';
		print '<tr><td class="fieldrequired">'.$langs->trans('VereineLoanMember').'</td><td>'.Form::selectarray('member', $members, GETPOSTINT('member'), 1, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';
		print '<tr><td class="fieldrequired">'.$langs->trans('VereineLoanIssued').'</td><td><input type="date" name="issued_on" value="'.dol_escape_htmltag($today).'"></td></tr>';
		print '<tr><td class="fieldrequired">'.$langs->trans('VereineLoanDue').'</td><td><input type="date" name="due_on" value="'.dol_escape_htmltag(date('Y-m-d', strtotime($today.' +14 days'))).'"></td></tr>';
		print '<tr><td>'.$langs->trans('VereineLoanConditionOut').'</td><td><input type="text" name="condition" class="minwidth300" maxlength="255" placeholder="'
			.dol_escape_htmltag($langs->trans('VereineLoanConditionPlaceholder')).'"></td></tr>';
		print '</table><div class="center paddingtop"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('VereineLoanLend')).'"></div></form>';
	}
}

// What was lent lately, with the state it left and came back in.
$recent = $loans->loans(0, 30);
print '<br>'.load_fiche_titre($langs->trans('VereineLoanHistory'), '', '', 0, 'vereineloanhistory');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-loans="'.count($recent).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('VereineLoanMember').'</td><td>'.$langs->trans('VereineLoanIssued').'</td><td>'.$langs->trans('VereineLoanDue').'</td>';
print '<td>'.$langs->trans('VereineLoanBack').'</td><td>'.$langs->trans('VereineLoanConditionOut').'</td><td>'.$langs->trans('VereineLoanConditionIn').'</td></tr>';
foreach ($recent as $loan) {
	print '<tr class="oddeven" data-loan="'.$loan['id'].'" data-loan-history-state="'.VereineLoanRules::state($loan['due_on'], $loan['returned_on'], $today).'">';
	print '<td>'.dol_escape_htmltag($loan['ref']).'</td><td>'.dol_escape_htmltag($loan['name']).'</td><td>'.vereineFormatDay($loan['issued_on']).'</td>';
	print '<td>'.vereineFormatDay($loan['due_on']).'</td><td>'.($loan['returned_on'] !== '' ? vereineFormatDay($loan['returned_on']) : '').'</td>';
	print '<td>'.dol_escape_htmltag($loan['condition_out']).'</td><td>'.dol_escape_htmltag($loan['condition_in']).'</td></tr>';
}
if (!$recent) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('VereineLoanNone').'</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
