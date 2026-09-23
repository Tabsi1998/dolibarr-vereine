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
require_once __DIR__.'/../class/vereineapplicationformrules.class.php';
require_once __DIR__.'/../class/vereinepdf.class.php';
require_once __DIR__.'/../class/vereinelog.class.php';

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
		// "none" rather than empty, so a choice of nothing is not taken for no choice and the default comes back.
		VereineMemberForm::REQUIRED => implode(',', VereineMemberForm::requiredFields(GETPOST('required', 'array'))) ?: 'none',
		// Own fields: ticked "on the form", and with it "required" or not (#216).
		VereineMemberForm::EXTRA => (string) json_encode(VereineApplicationFormRules::extraFields(
			array_map(function ($value) {
				return $value === 'required' ? 1 : 0;
			}, array_filter((array) GETPOST('extra', 'array'), function ($value) {
				return in_array($value, array('optional', 'required'), true);
			})), array_keys(VereineMemberForm::memberExtraFields($db)))),
		VereineMemberForm::ACCOUNT => (string) GETPOSTINT('account'),
		VereinePdf::FILLABLE => implode(',', VereinePdf::fillableKinds(GETPOST('fillable', 'array'))),
	);
	$stored = true;
	foreach ($values as $name => $value) {
		$stored = dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity) > 0 && $stored;
	}
	setEventMessages($stored ? $langs->trans('RecordModifiedSuccessfully') : $langs->trans('Error'), null, $stored ? 'mesgs' : 'errors');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
} elseif ($action === 'newfield') {
	// An own field made right here, and put on the form at once (#226).
	$errors = array();
	$label = GETPOST('field_label', 'alphanohtml');
	$code = VereineMemberForm::addField($db, $label, GETPOST('field_kind', 'aZ09'), GETPOST('field_options', 'nohtml'), $errors);
	if ($code !== '') {
		$extra = VereineMemberForm::settings(array_keys(VereineMemberForm::memberExtraFields($db)))['extra'];
		$extra[$code] = GETPOST('field_state', 'aZ09') === 'required';
		dolibarr_set_const($db, VereineMemberForm::EXTRA, (string) json_encode($extra), 'chaine', 0, '', $conf->entity);
		VereineLog::add($db, $user, VereineLog::APPLICATION_FIELD, 0, 0, $code);
		setEventMessages($langs->trans('VereineApplicationNewFieldCreated', $label), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereinenewfield');
		exit;
	}
	setEventMessages(null, array_map(array($langs, 'trans'), $errors), 'errors');
}


/*
 * View
 */

$extraSpecs = VereineMemberForm::memberExtraFieldSpecs($db);
$extraLabels = array_map(function ($spec) {
	return $spec['label'];
}, $extraSpecs);
$settings = VereineMemberForm::settings(array_keys($extraLabels));
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
print '<td><textarea id="privacy" name="privacy" rows="4" class="centpercent" placeholder="'.dol_escape_htmltag($langs->trans('VereineApplicationPrivacySuggestion')).'">';
print dol_escape_htmltag($settings['privacy'], 0, 1).'</textarea>';
if ($settings['privacy'] === '') {
	print '<div class="opacitymedium small">'.$langs->trans('VereineApplicationPrivacyMissing').'</div>';
}
print '</td></tr>';

print '<tr class="oddeven"><td><label for="privacy_url">'.$langs->trans('VereineApplicationPrivacyUrl').'</label></td>';
print '<td><input type="text" id="privacy_url" name="privacy_url" class="minwidth300" value="'.dol_escape_htmltag($settings['privacy_url']).'" placeholder="https://"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('VereineApplicationRequired').'<div class="opacitymedium small">'.$langs->trans('VereineApplicationRequiredHelp').'</div></td><td>';
// The name is required anyway; it stands here so nobody wonders where it went.
foreach (VereineApplicationFormRules::ALWAYS as $field) {
	print '<label class="paddingright"><input type="checkbox" checked disabled data-always="'.$field.'"> ';
	print $langs->trans('VereineApplicationField_'.$field).'</label> ';
}
foreach (VereineMemberForm::REQUIRABLE as $field) {
	print '<label class="paddingright"><input type="checkbox" name="required[]" value="'.$field.'"'.(in_array($field, $settings['required'], true) ? ' checked' : '').'> ';
	print $langs->trans('VereineApplicationField_'.$field).'</label> ';
}
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('VereineApplicationExtra').'<div class="opacitymedium small">'.$langs->trans('VereineApplicationExtraHelp').'</div></td><td>';
if (!$extraLabels) {
	print '<span class="opacitymedium" data-extra-none="1">'.$langs->trans('VereineApplicationExtraNone').'</span>';
}
foreach ($extraLabels as $code => $label) {
	$state = !isset($settings['extra'][$code]) ? 'off' : ($settings['extra'][$code] ? 'required' : 'optional');
	print '<div class="paddingbottom" data-extra-field="'.dol_escape_htmltag($code).'" data-extra-state="'.$state.'">';
	print '<select name="extra['.dol_escape_htmltag($code).']" class="flat">';
	foreach (array('off', 'optional', 'required') as $option) {
		print '<option value="'.$option.'"'.($state === $option ? ' selected' : '').'>'.$langs->trans('VereineApplicationExtra_'.$option).'</option>';
	}
	print '</select> '.dol_escape_htmltag($langs->trans($label)).' <span class="opacitymedium small">('.dol_escape_htmltag($code).', '
		.$langs->trans('VereineApplicationKind_'.$extraSpecs[$code]['kind']).')</span></div>';
}
print '<div class="paddingtop small"><a href="'.DOL_URL_ROOT.'/adherents/admin/member_extrafields.php">'.img_picto('', 'fa-pen', 'class="pictofixedwidth"')
	.$langs->trans('VereineApplicationEditInDolibarr').'</a></div>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('VereineApplicationFillable').'<div class="opacitymedium small">'.$langs->trans('VereineApplicationFillableHelp').'</div></td><td>';
foreach (VereinePdf::FILLABLE_KINDS as $kind) {
	print '<label class="paddingright"><input type="checkbox" name="fillable[]" value="'.$kind.'"'.(VereinePdf::fillable($kind) ? ' checked' : '').'> ';
	print $langs->trans('VereineApplicationFillable_'.$kind).'</label> ';
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

// A new own field: made here, it is a field of the member in Dolibarr and on the form at once (#226).
print load_fiche_titre($langs->trans('VereineApplicationNewField'), '', '', 0, 'vereinenewfield');
print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineApplicationNewFieldHelp').'</div>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#vereinenewfield" name="vereineapplicationnewfield">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="newfield">';
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate fieldrequired"><label for="field_label">'.$langs->trans('VereineApplicationNewFieldLabel').'</label></td>';
print '<td><input type="text" id="field_label" name="field_label" maxlength="100" class="minwidth300" value=""></td></tr>';
print '<tr><td><label for="field_kind">'.$langs->trans('VereineApplicationNewFieldKind').'</label></td><td><select id="field_kind" name="field_kind" class="flat">';
foreach (VereineApplicationFormRules::KINDS as $kind) {
	print '<option value="'.$kind.'">'.$langs->trans('VereineApplicationKind_'.$kind).'</option>';
}
print '</select></td></tr>';
print '<tr><td><label for="field_options">'.$langs->trans('VereineApplicationNewFieldOptions').'</label><div class="opacitymedium small">'
	.$langs->trans('VereineApplicationNewFieldOptionsHelp').'</div></td><td><textarea id="field_options" name="field_options" rows="4" class="minwidth300"></textarea></td></tr>';
print '<tr><td><label for="field_state">'.$langs->trans('VereineApplicationNewFieldState').'</label></td><td><select id="field_state" name="field_state" class="flat">';
foreach (array('optional', 'required') as $option) {
	print '<option value="'.$option.'">'.$langs->trans('VereineApplicationExtra_'.$option).'</option>';
}
print '</select></td></tr></table>';
print '<div class="center paddingtop"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineApplicationNewFieldCreate')).'"></div></form>';

print '<div class="paddingtop"><a href="'.dol_buildpath('/vereine/application.php', 1).'">'.$langs->trans('VereineApplicationBlankPage').'</a></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
