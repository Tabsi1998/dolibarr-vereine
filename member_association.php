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
 * \file    member_association.php
 * \ingroup vereine
 * \brief   Tab "Association" on a member: its third party as the module keeps it, open invoices, guardians, log.
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/member.lib.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
require_once __DIR__.'/class/vereinepartnerservice.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('companies', 'members', 'bills', 'categories', 'vereine@vereine'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('vereine', 'association', 'read') || !$user->hasRight('adherent', 'lire') || !$user->hasRight('societe', 'lire')) {
	accessforbidden();
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
$result = restrictedArea($user, 'adherent', $id, '', '', 'socid', 'rowid', 0);

$object = new Adherent($db);
if ($id <= 0 || $object->fetch($id) <= 0) {
	accessforbidden('Member not found');
}
$socid = (int) $object->fk_soc;
$partner = null;
if ($socid > 0) {
	$partner = new Societe($db);
	if ($partner->fetch($socid) <= 0) {
		$partner = null;
	}
}
$service = new VereinePartnerService($db);
$canWrite = $user->hasRight('vereine', 'partner', 'write') && $user->hasRight('societe', 'creer');


/*
 * Actions
 */

if ($action === 'apply' && $canWrite && $partner) {
	$result = $service->applyAttributes($object, $user);
	if (is_array($result)) {
		setEventMessages($result['changes'] ? $langs->trans('VereinePartnerApplied', implode(', ', $result['changes'])) : $langs->trans('VereinePartnerNothingToApply'), null, 'mesgs');
		if ($result['mismatch']) {
			setEventMessages($langs->trans('VereinePartnerTypeMismatch'), null, 'warnings');
		}
	} else {
		setEventMessages($service->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
	exit;
}


/*
 * View
 */

$title = $langs->trans('VereineTabAssociation').' - '.$object->getFullName($langs);
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-member-association');

$head = member_prepare_head($object);
print dol_get_fiche_head($head, 'vereineassociation', $langs->trans('Member'), -1, 'user');
$linkback = '<a href="'.DOL_URL_ROOT.'/adherents/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
dol_banner_tab($object, 'rowid', $linkback);

print '<div class="fichecenter"><div class="underbanner clearboth"></div>';

if (!$partner) {
	print '<div class="opacitymedium" data-association="none">'.$langs->trans('VereineMemberNoPartner').'</div>';
	print '<br><a href="'.dol_buildpath('/vereine/partners.php', 1).'">'.$langs->trans('VereinePartnerOpenReconciliation').'</a>';
} else {
	// What does not fit, as the reconciliation page sees it.
	$problems = array();
	$report = $service->report(dol_print_date(dol_now(), '%Y-%m-%d'));
	foreach ($report['attributes'] as $row) {
		if ($row['member']['id'] === (int) $object->id) {
			foreach ($row['problems'] as $problem) {
				$problems[] = $langs->transnoentitiesnoconv($problem);
			}
			if ($row['type_mismatch']) {
				$problems[] = $langs->transnoentitiesnoconv('VereinePartnerTypeMismatchShort', $row['partner']['typent_code']);
			}
		}
	}
	foreach ($report['differences'] as $row) {
		if ($row['member']['id'] === (int) $object->id) {
			foreach ($row['fields'] as $field => $values) {
				$problems[] = $langs->transnoentitiesnoconv('VereineField_'.$field).': '.$values['partner'].' → '.$values['member'];
			}
		}
	}
	$categories = new Categorie($db);
	$labels = $categories->containing((int) $partner->id, 'customer', 'label');
	$typeCode = (int) $partner->typent_id > 0 ? (string) dol_getIdFromCode($db, (int) $partner->typent_id, 'c_typent', 'id', 'code') : '';
	$typeLabel = $typeCode !== '' ? $langs->trans($typeCode) : '';

	print '<div class="div-table-responsive-no-min">';
	print '<table class="border centpercent tableforfield" data-association="'.((int) $partner->id).'">';
	print '<tr><td class="titlefield">'.$langs->trans('VereinePartnerColumn').'</td><td>'.$partner->getNomUrl(1).'</td></tr>';
	print '<tr><td>'.$langs->trans('Categories').'</td><td data-partner-categories="1">'.dol_escape_htmltag(is_array($labels) ? implode(', ', $labels) : '').'</td></tr>';
	print '<tr><td>'.$langs->trans('VereinePartnerCustomerFlag').'</td><td>'.yn(in_array((int) $partner->client, array(1, 3), true) ? 1 : 0).'</td></tr>';
	print '<tr><td>'.$langs->trans('VereinePartnerCustomerType').'</td><td>'.($typeLabel !== '' ? dol_escape_htmltag($typeLabel) : '<span class="opacitymedium">'.$langs->trans('VereineNotSet').'</span>').'</td></tr>';
	print '<tr><td>'.$langs->trans('VereinePartnerProblems').'</td><td data-problems="'.count($problems).'">';
	if ($problems) {
		print dol_escape_htmltag(implode(', ', $problems));
	} else {
		print '<span class="opacitymedium">'.$langs->trans('VereinePartnerInLine').'</span>';
	}
	print '</td></tr>';
	print '</table>';
	print '</div>';

	if ($canWrite) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'" name="vereineapply">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="apply">';
		print '<div class="right"><input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereinePartnerApply')).'"></div>';
		print '</form>';
	}

	vereinePrintOpenInvoices($db, (int) $partner->id);
	vereinePrintGuardians($db, (int) $partner->id);
}

vereinePrintLog($db, (int) $object->id, $partner ? (int) $partner->id : 0);

print '</div>';
print dol_get_fiche_end();

llxFooter();
$db->close();
