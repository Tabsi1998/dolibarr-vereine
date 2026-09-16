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
 * \file    partner_membership.php
 * \ingroup vereine
 * \brief   Tab "Membership" on a third party: the linked member, open invoices, guardians, log.
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once __DIR__.'/class/vereinepartnerservice.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('companies', 'members', 'bills', 'categories', 'vereine@vereine'));

$socid = GETPOSTINT('socid');
$action = GETPOST('action', 'aZ09');

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('vereine', 'association', 'read') || !$user->hasRight('adherent', 'lire')) {
	accessforbidden();
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
$result = restrictedArea($user, 'societe', $socid, '&societe');

$object = new Societe($db);
if ($socid <= 0 || $object->fetch($socid) <= 0) {
	accessforbidden('Third party not found');
}
$service = new VereinePartnerService($db);
$memberId = $service->memberOfPartner((int) $object->id);
$member = null;
if ($memberId > 0) {
	$member = new Adherent($db);
	$member->fetch($memberId);
}
$canWrite = $user->hasRight('vereine', 'partner', 'write') && $user->hasRight('societe', 'creer');


/*
 * Actions
 */

if ($action === 'apply' && $canWrite && $member) {
	$result = $service->applyAttributes($member, $user);
	if (is_array($result)) {
		setEventMessages($result['changes'] ? $langs->trans('VereinePartnerApplied', implode(', ', $result['changes'])) : $langs->trans('VereinePartnerNothingToApply'), null, 'mesgs');
		if ($result['mismatch']) {
			setEventMessages($langs->trans('VereinePartnerTypeMismatch'), null, 'warnings');
		}
	} else {
		setEventMessages($service->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?socid='.((int) $object->id));
	exit;
}


/*
 * View
 */

$title = $langs->trans('VereineTabMembership').' - '.$object->name;
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-partner-membership');

$head = societe_prepare_head($object);
print dol_get_fiche_head($head, 'vereinemembership', $langs->trans('ThirdParty'), -1, 'company');
$linkback = '<a href="'.DOL_URL_ROOT.'/societe/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
dol_banner_tab($object, 'socid', $linkback, ($user->socid ? 0 : 1), 'rowid', 'nom');

print '<div class="fichecenter"><div class="underbanner clearboth"></div>';

if (!$member) {
	print '<div class="opacitymedium" data-membership="none">'.$langs->trans('VereinePartnerNoMember').'</div>';
	if ($user->hasRight('societe', 'lire')) {
		print '<br><a href="'.dol_buildpath('/vereine/partners.php', 1).'">'.$langs->trans('VereinePartnerOpenReconciliation').'</a>';
	}
} else {
	$member->fetch_subscriptions();
	$since = !empty($member->first_subscription_date_start) ? $member->first_subscription_date_start : $member->datevalid;
	$categories = new Categorie($db);
	$labels = $categories->containing((int) $object->id, 'customer', 'label');

	print '<div class="div-table-responsive-no-min">';
	print '<table class="border centpercent tableforfield" data-membership="'.((int) $member->id).'">';
	print '<tr><td class="titlefield">'.$langs->trans('MemberRef').'</td><td>'.$member->getNomUrl(1).'</td></tr>';
	print '<tr><td>'.$langs->trans('Type').'</td><td>'.dol_escape_htmltag((string) $member->type).'</td></tr>';
	print '<tr><td>'.$langs->trans('Status').'</td><td>'.$member->getLibStatut(4).'</td></tr>';
	print '<tr><td>'.$langs->trans('VereineMemberSince').'</td><td>'.($since ? dol_print_date($since, 'day') : '').'</td></tr>';
	print '<tr><td>'.$langs->trans('VereinePaidUntil').'</td><td>'.($member->datefin ? dol_print_date($member->datefin, 'day') : '<span class="opacitymedium">'.$langs->trans('VereineNotSet').'</span>').'</td></tr>';
	print '<tr><td>'.$langs->trans('Categories').'</td><td>'.dol_escape_htmltag(is_array($labels) ? implode(', ', $labels) : '').'</td></tr>';
	print '</table>';
	print '</div>';

	if ($canWrite) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?socid='.((int) $object->id).'" name="vereineapply">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="apply">';
		print '<div class="right"><input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereinePartnerApply')).'"></div>';
		print '</form>';
	}

	vereinePrintOpenInvoices($db, (int) $object->id);
	vereinePrintGuardians($db, (int) $object->id);
}

vereinePrintLog($db, $member ? (int) $member->id : 0, (int) $object->id);

print '</div>';
print dol_get_fiche_end();

llxFooter();
$db->close();
