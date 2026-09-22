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
 * \file    admin/application.php
 * \ingroup vereine
 * \brief   What the association writes itself on the application for membership (#108).
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/vereine.lib.php';
require_once __DIR__.'/../class/vereinememberform.class.php';

$langs->loadLangs(array('admin', 'members', 'banks', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');


/*
 * Actions
 */

if ($action === 'save') {
	$values = array(
		VereineMemberForm::INTRO => GETPOST('intro', 'restricthtml'),
		VereineMemberForm::PRIVACY => GETPOST('privacy', 'restricthtml'),
		VereineMemberForm::PRIVACY_URL => GETPOST('privacy_url', 'alphanohtml'),
		VereineMemberForm::REQUIRED => implode(',', VereineMemberForm::requiredFields(GETPOST('required', 'array'))),
		VereineMemberForm::ACCOUNT => (string) GETPOSTINT('account'),
	);
	$stored = true;
	foreach ($values as $name => $value) {
		$stored = dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity) > 0 && $stored;
	}
	setEventMessages($stored ? $langs->trans('RecordModifiedSuccessfully') : $langs->trans('Error'), null, $stored ? 'mesgs' : 'errors');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */

$settings = VereineMemberForm::settings();
$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-application');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = vereineAdminPrepareHead();
print dol_get_fiche_head($head, 'application', $title, -1, 'fa-landmark');

print '<div class="info" data-application-howto="1"><ul>';
foreach (array('VereineApplicationSetupWhat', 'VereineApplicationSetupSource', 'VereineApplicationSetupWhere') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
print '</ul></div>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereineapplicationsetup">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td class="titlefieldcreate"><label for="intro">'.$langs->trans('VereineApplicationIntro').'</label>';
print '<div class="opacitymedium small">'.$langs->trans('VereineApplicationIntroHelp').'</div></td>';
print '<td><textarea id="intro" name="intro" rows="3" class="centpercent">'.dol_escape_htmltag($settings['intro'], 0, 1).'</textarea></td></tr>';

print '<tr class="oddeven"><td><label for="privacy">'.$langs->trans('VereineApplicationPrivacyText').'</label>';
print '<div class="opacitymedium small">'.$langs->trans('VereineApplicationPrivacyHelp').'</div></td>';
print '<td><textarea id="privacy" name="privacy" rows="4" class="centpercent">'.dol_escape_htmltag($settings['privacy'], 0, 1).'</textarea></td></tr>';

print '<tr class="oddeven"><td><label for="privacy_url">'.$langs->trans('VereineApplicationPrivacyUrl').'</label></td>';
print '<td><input type="text" id="privacy_url" name="privacy_url" class="minwidth300" value="'.dol_escape_htmltag($settings['privacy_url']).'" placeholder="https://"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('VereineApplicationRequired').'<div class="opacitymedium small">'.$langs->trans('VereineApplicationRequiredHelp').'</div></td><td>';
foreach (VereineMemberForm::REQUIRABLE as $field) {
	print '<label class="paddingright"><input type="checkbox" name="required[]" value="'.$field.'"'.(in_array($field, $settings['required'], true) ? ' checked' : '').'> ';
	print $langs->trans('VereineApplicationField_'.$field).'</label> ';
}
print '</td></tr>';

print '<tr class="oddeven"><td><label for="account">'.$langs->trans('VereineApplicationAccount').'</label>';
print '<div class="opacitymedium small">'.$langs->trans('VereineApplicationAccountHelp').'</div></td><td><select id="account" name="account">';
print '<option value="0">'.$langs->trans('VereineApplicationAccountFirst').'</option>';
$sql = "SELECT rowid, label, iban_prefix FROM ".MAIN_DB_PREFIX."bank_account WHERE entity IN (".getEntity('bank_account').") AND clos = 0 ORDER BY label";
$resql = $db->query($sql);
while ($resql && ($obj = $db->fetch_object($resql))) {
	print '<option value="'.((int) $obj->rowid).'"'.($settings['account'] === (int) $obj->rowid ? ' selected' : '').'>';
	print dol_escape_htmltag(trim($obj->label.' '.$obj->iban_prefix)).'</option>';
}
print '</select></td></tr>';
print '</table>';
print '<div class="center paddingtop"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form>';

print '<div class="paddingtop"><a href="'.dol_buildpath('/vereine/application.php', 1).'">'.$langs->trans('VereineApplicationBlankPage').'</a></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
