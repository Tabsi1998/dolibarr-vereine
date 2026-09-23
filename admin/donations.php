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
 * \file    admin/donations.php
 * \ingroup vereine
 * \brief   Setup of the donation report (#6): the kind of body, tax numbers, and who the register may ask.
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
require_once __DIR__.'/../class/vereinedonations.class.php';

$langs->loadLangs(array('admin', 'donations', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$store = new VereineDonations($db);

if ($action === 'save') {
	$entered = array('kind' => GETPOST('kind', 'alphanohtml'), 'fastnr_org' => GETPOST('fastnr_org', 'alphanohtml'),
		'fastnr_tn' => GETPOST('fastnr_tn', 'alphanohtml'), 'contact' => GETPOST('contact', 'alphanohtml'),
		'email' => GETPOST('email', 'alphanohtml'), 'vkz' => GETPOST('vkz', 'alphanohtml'));
	$result = $store->saveSettings($entered, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	setEventMessages($result < 0 ? $store->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $store->errors), 'errors');
}

$settings = VereineDonations::settings();
$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-donations');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');
print dol_get_fiche_head(vereineAdminPrepareHead(), 'donations', $title, -1, 'fa-landmark');
print '<div class="info" data-donation-setup-howto="1">'.$langs->trans('VereineDonationSetupHowTo').'</div>';
if (!isModEnabled('don')) {
	print '<div class="warning">'.$langs->trans('VereineDonationModuleOff').'</div>';
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinedonationsetup">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent" data-donation-kind="'.dol_escape_htmltag($settings['kind']).'">';
print '<tr class="liste_titre"><td class="titlefield">'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('VereineDonationKind').'</td><td><select name="kind" class="flat minwidth300">';
print '<option value=""></option>';
foreach (VereineDonationRules::KINDS as $kind) {
	print '<option value="'.dol_escape_htmltag($kind).'"'.($kind === $settings['kind'] ? ' selected' : '').'>'.dol_escape_htmltag($kind).' – '
		.$langs->trans('VereineDonationKind_'.VereineDonationRules::kindKey($kind)).'</option>';
}
print '</select><br><span class="opacitymedium small">'.$langs->trans('VereineDonationKindHint').'</span></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('VereineDonationFastnrOrg').'</td><td><input type="text" name="fastnr_org" size="12" maxlength="12" value="'
	.dol_escape_htmltag($settings['fastnr_org']).'"><br><span class="opacitymedium small">'.$langs->trans('VereineDonationFastnrOrgHint').'</span></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('VereineDonationFastnrTn').'</td><td><input type="text" name="fastnr_tn" size="12" maxlength="12" value="'
	.dol_escape_htmltag($settings['fastnr_tn']).'"><br><span class="opacitymedium small">'.$langs->trans('VereineDonationFastnrTnHint').'</span></td></tr>';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('VereineDonationSzrSetup').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('VereineDonationSzrContact').'</td><td><input type="text" name="contact" size="40" maxlength="128" value="'
	.dol_escape_htmltag($settings['contact']).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Email').'</td><td><input type="email" name="email" size="40" maxlength="128" value="'.dol_escape_htmltag($settings['email']).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('VereineDonationSzrVkz').'</td><td><input type="text" name="vkz" size="20" maxlength="32" value="'
	.dol_escape_htmltag($settings['vkz']).'"><br><span class="opacitymedium small">'.$langs->trans('VereineDonationSzrVkzHint').'</span></td></tr>';
print '</table><div class="center paddingtop"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div></form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
