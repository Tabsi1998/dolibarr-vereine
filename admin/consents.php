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
 * \file    admin/consents.php
 * \ingroup vereine
 * \brief   Consent texts with versions, for the member card and membership applications.
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
require_once __DIR__.'/../class/vereineconsents.class.php';
require_once __DIR__.'/../class/vereinememberform.class.php';
require_once __DIR__.'/../class/vereinewebsiteprofiles.class.php';

$langs->loadLangs(array('admin', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$consents = new VereineConsents($db);
$edit = array('code' => '', 'label' => '', 'text' => '');


/*
 * Actions
 */

if ($action === 'savewebsiteprofileconsent') {
	$websiteProfiles = new VereineWebsiteProfiles($db);
	if ($websiteProfiles->saveConsentCode(GETPOST('website_profile_consent', 'aZ09'), $user) < 0) {
		setEventMessages($websiteProfiles->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('VereineWebsiteProfileConsentSaved'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
} elseif ($action === 'savewebsiteprofilefields') {
	// Which fields of the member make up the website profile, and which of them the member keeps (#260).
	$websiteProfiles = new VereineWebsiteProfiles($db);
	if ($websiteProfiles->saveFields(GETPOST('profile_fields', 'array'), GETPOST('profile_self', 'array'), $user) < 0) {
		setEventMessages($websiteProfiles->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('VereineWebsiteProfileFieldsSaved'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'#vereinewebsiteprofilefields');
	exit;
} elseif ($action === 'newwebsiteprofilefield') {
	// A field made right here is a field of the member in Dolibarr and joins the website profile at once (#260).
	$errors = array();
	$label = GETPOST('field_label', 'alphanohtml');
	$code = VereineMemberForm::addField($db, $label, GETPOST('field_kind', 'aZ09'), GETPOST('field_options', 'nohtml'), $errors);
	if ($code !== '') {
		$websiteProfiles = new VereineWebsiteProfiles($db);
		$chosen = array($code);
		$self = GETPOST('field_self', 'aZ09') === '1' ? array($code) : array();
		foreach ($websiteProfiles->fields() as $fieldCode => $spec) {
			$chosen[] = $fieldCode;
			if ($spec['editable']) {
				$self[] = $fieldCode;
			}
		}
		if ($websiteProfiles->saveFields($chosen, $self, $user) < 0) {
			setEventMessages($websiteProfiles->error, null, 'errors');
		} else {
			setEventMessages($langs->trans('VereineWebsiteProfileFieldCreated', $label), null, 'mesgs');
		}
		header('Location: '.$_SERVER['PHP_SELF'].'#vereinewebsiteprofilefields');
		exit;
	}
	setEventMessages(null, array_map(array($langs, 'trans'), $errors), 'errors');
} elseif ($action === 'savetext') {
	$edit = array('code' => GETPOST('code', 'aZ09'), 'label' => GETPOST('label', 'alphanohtml'), 'text' => GETPOST('text', 'restricthtml'));
	$result = $consents->saveText($edit['code'], $edit['label'], $edit['text'], $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineConsentSaved', $result), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	if ($result < 0) {
		setEventMessages($consents->error, null, 'errors');
	} else {
		setEventMessages(null, array_map(array($langs, 'trans'), $consents->errors), 'errors');
	}
} elseif ($action === 'toggletext') {
	$code = GETPOST('code', 'aZ09');
	$current = $consents->currentTexts();
	if ($consents->setActive($code, !isset($current[$code])) < 0) {
		setEventMessages($consents->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
} elseif ($action === 'edittext') {
	foreach ($consents->texts() as $text) {
		if ($text['code'] === GETPOST('code', 'aZ09')) {
			$edit = $text;
			break;
		}
	}
}


/*
 * View
 */

$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-consents');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = vereineAdminPrepareHead();
print dol_get_fiche_head($head, 'consents', $title, -1, 'fa-landmark');

print '<div class="info" data-consents-howto="1"><ul>';
foreach (array('VereineConsentHowToWhy', 'VereineConsentHowToVersion', 'VereineConsentHowToWithdraw', 'VereineConsentHowToWebsite', 'VereineConsentHowToChildren') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
print '</ul></div>';

$texts = $consents->texts();
$current = $consents->currentTexts();
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineConsentCode').'</td><td>'.$langs->trans('VereineConsentLabel').'</td>';
print '<td class="center">'.$langs->trans('VereineConsentVersion').'</td><td>'.$langs->trans('VereineConsentText').'</td><td class="center">'.$langs->trans('Status').'</td><td></td></tr>';
if (!$texts) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('VereineConsentNone').'</span></td></tr>';
}
$shown = array();
foreach ($texts as $text) {
	if (isset($shown[$text['code']])) {
		continue;
	}
	$shown[$text['code']] = true;
	$active = isset($current[$text['code']]);
	print '<tr class="oddeven" data-consent="'.dol_escape_htmltag($text['code']).'" data-version="'.$text['version'].'" data-active="'.($active ? 1 : 0).'">';
	print '<td>'.dol_escape_htmltag($text['code']).'</td><td>'.dol_escape_htmltag($text['label']).'</td><td class="center">'.$text['version'].'</td>';
	print '<td class="small">'.dol_escape_htmltag(dol_trunc($text['text'], 160)).'</td>';
	print '<td class="center">'.dolGetBadge($langs->trans($active ? 'Enabled' : 'Disabled'), '', $active ? 'success' : 'secondary').'</td>';
	print '<td class="right nowraponall">';
	print '<a class="editfielda paddingright" href="'.$_SERVER['PHP_SELF'].'?action=edittext&amp;code='.urlencode($text['code']).'&amp;token='.newToken().'#vereineconsenttext">'.img_edit().'</a>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block" name="vereineconsenttoggle">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="toggletext">';
	print '<input type="hidden" name="code" value="'.dol_escape_htmltag($text['code']).'">';
	print '<button type="submit" class="button small">'.$langs->trans($active ? 'VereineTaxDeactivate' : 'VereineTaxActivate').'</button>';
	print '</form></td></tr>';
}
print '</table></div><br>';

// Which consent opens the website profile of a member (#255).
$websiteProfileConsent = VereineWebsiteProfiles::consentCode();
print load_fiche_titre($langs->trans('VereineWebsiteProfileConsentSetting'), '', '');
print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineWebsiteProfileConsentSettingHowTo').'</div>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinewebsiteprofileconsent">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savewebsiteprofileconsent">';
print '<select name="website_profile_consent" class="minwidth300" data-website-profile-consent="'.dol_escape_htmltag($websiteProfileConsent).'">';
print '<option value=""'.($websiteProfileConsent === '' ? ' selected' : '').'>'.$langs->trans('VereineWebsiteProfileConsentSettingNone').'</option>';
foreach ($current as $code => $text) {
	print '<option value="'.dol_escape_htmltag($code).'"'.($websiteProfileConsent === $code ? ' selected' : '').'>'.dol_escape_htmltag($text['label'].' ('.$code.')').'</option>';
}
print '</select> <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'">';
print '</form><br>';

// The fields of the website profile: fields of the member the association defines, each kept by the board or by the member (#260).
$websiteProfiles = new VereineWebsiteProfiles($db);
$profileFields = $websiteProfiles->fields();
$memberFields = $websiteProfiles->available();
print load_fiche_titre($langs->trans('VereineWebsiteProfileFields'), '', '', 0, 'vereinewebsiteprofilefields');
print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineWebsiteProfileFieldsHowTo').'</div>';
if (!$memberFields) {
	print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineWebsiteProfileFieldsNone').'</div>';
} else {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#vereinewebsiteprofilefields" name="vereinewebsiteprofilefields">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="savewebsiteprofilefields">';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('VereineWebsiteProfileField').'</th><th>'.$langs->trans('VereineApplicationNewFieldKind').'</th>';
	print '<th class="center">'.$langs->trans('VereineWebsiteProfileFieldShown').'</th><th class="center">'.$langs->trans('VereineWebsiteProfileFieldSelf').'</th></tr>';
	foreach ($memberFields as $code => $spec) {
		$state = !isset($profileFields[$code]) ? 'off' : ($profileFields[$code]['editable'] ? 'self' : 'board');
		print '<tr class="oddeven" data-website-profile-field="'.dol_escape_htmltag($code).'" data-website-profile-state="'.$state.'">';
		print '<td>'.dol_escape_htmltag($langs->transnoentitiesnoconv($spec['label'])).' <span class="opacitymedium small">('.dol_escape_htmltag($code).')</span></td>';
		print '<td>'.$langs->trans('VereineApplicationKind_'.$spec['kind']).'</td>';
		print '<td class="center"><input type="checkbox" name="profile_fields[]" value="'.dol_escape_htmltag($code).'"'.($state !== 'off' ? ' checked' : '').'></td>';
		print '<td class="center"><input type="checkbox" name="profile_self[]" value="'.dol_escape_htmltag($code).'"'.($state === 'self' ? ' checked' : '').'></td></tr>';
	}
	print '</table></div>';
	print '<div class="center paddingtop"><input type="submit" class="button small" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div>';
	print '</form>';
}
print '<div class="paddingtop"><strong>'.$langs->trans('VereineWebsiteProfileNewField').'</strong></div>';
print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineWebsiteProfileNewFieldHelp').'</div>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#vereinewebsiteprofilefields" name="vereinewebsiteprofilenewfield">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="newwebsiteprofilefield">';
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate fieldrequired"><label for="profile_field_label">'.$langs->trans('VereineApplicationNewFieldLabel').'</label></td>';
print '<td><input type="text" id="profile_field_label" name="field_label" maxlength="100" class="minwidth300" value=""></td></tr>';
print '<tr><td><label for="profile_field_kind">'.$langs->trans('VereineApplicationNewFieldKind').'</label></td><td><select id="profile_field_kind" name="field_kind" class="flat">';
foreach (VereineApplicationFormRules::KINDS as $kind) {
	print '<option value="'.$kind.'">'.$langs->trans('VereineApplicationKind_'.$kind).'</option>';
}
print '</select></td></tr>';
print '<tr><td><label for="profile_field_options">'.$langs->trans('VereineApplicationNewFieldOptions').'</label><div class="opacitymedium small">'
	.$langs->trans('VereineApplicationNewFieldOptionsHelp').'</div></td><td><textarea id="profile_field_options" name="field_options" rows="4" class="minwidth300"></textarea></td></tr>';
print '<tr><td><label for="profile_field_self">'.$langs->trans('VereineWebsiteProfileNewFieldSelf').'</label></td>';
print '<td><input type="checkbox" id="profile_field_self" name="field_self" value="1"></td></tr></table>';
print '<div class="center paddingtop"><input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineApplicationNewFieldCreate')).'"></div></form><br>';

print load_fiche_titre($langs->trans($edit['code'] !== '' && isset($shown[$edit['code']]) ? 'VereineConsentEdit' : 'VereineConsentNew'), '', '');
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereineconsenttext" id="vereineconsenttext">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savetext">';
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate fieldrequired"><label for="code">'.$langs->trans('VereineConsentCode').'</label></td>';
print '<td><input type="text" id="code" name="code" class="minwidth200" maxlength="32" value="'.dol_escape_htmltag($edit['code']).'">';
print ' <span class="opacitymedium small">'.$langs->trans('VereineConsentCodeHelp').'</span></td></tr>';
print '<tr><td class="fieldrequired"><label for="label">'.$langs->trans('VereineConsentLabel').'</label></td>';
print '<td><input type="text" id="label" name="label" class="minwidth300" maxlength="'.VereineConsentRules::LABEL_MAX.'" value="'.dol_escape_htmltag($edit['label']).'"></td></tr>';
print '<tr><td class="fieldrequired"><label for="text">'.$langs->trans('VereineConsentText').'</label></td>';
print '<td><textarea id="text" name="text" rows="5" class="centpercent">'.dol_escape_htmltag($edit['text'], 0, 1).'</textarea></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
