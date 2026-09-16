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
 * \file    admin/taxprofiles.php
 * \ingroup vereine
 * \brief   Setup of the tax profiles: sphere, VAT treatment, rate and invoice note.
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
require_once __DIR__.'/../class/vereinetaxprofiles.class.php';

$langs->loadLangs(array('admin', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$profiles = new VereineTaxProfiles($db);
$spheres = VereineTaxRules::spheres();
$treatments = VereineTaxRules::treatments();

// What the form shows: the submitted values after a refused save, else the profile being edited.
$edit = array('id' => 0, 'code' => '', 'label' => '', 'sphere' => VereineTaxRules::SPHERE_IDEAL, 'treatment' => VereineTaxRules::TREATMENT_NONBUSINESS, 'note' => '', 'active' => true);


/*
 * Actions
 */

if ($action === 'save') {
	$data = array(
		'code' => strtoupper(trim(GETPOST('code', 'aZ09'))),
		'label' => GETPOST('label', 'alphanohtml'),
		'sphere' => GETPOST('sphere', 'aZ09'),
		'treatment' => GETPOST('treatment', 'aZ09'),
		'note' => GETPOST('note', 'alphanohtml'),
		'active' => GETPOSTINT('active') ? 1 : 0,
	);
	// The rate follows from the treatment; the rules still check it for every other writer.
	$data['rate'] = VereineTaxRules::rateOf($data['treatment']);
	$result = $id > 0 ? $profiles->update($id, $data, $user) : $profiles->create($data, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineTaxSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	if ($result < 0) {
		setEventMessages($profiles->error, null, 'errors');
	} else {
		setEventMessages(null, array_map(array($langs, 'trans'), array_unique($profiles->errors)), 'errors');
	}
	$edit = array_merge($data, array('id' => $id, 'active' => !empty($data['active'])));
	if ($id > 0) {
		$current = $profiles->fetch($id);
		$edit['code'] = $current ? $current['code'] : $edit['code'];
	}
} elseif ($action === 'toggle' && $id > 0) {
	$current = $profiles->fetch($id);
	if ($current) {
		$current['active'] = !$current['active'];
		if ($profiles->update($id, $current, $user) > 0) {
			setEventMessages($langs->trans($current['active'] ? 'VereineTaxActivated' : 'VereineTaxDeactivated', $current['code']), null, 'mesgs');
		} else {
			setEventMessages($profiles->error, array_map(array($langs, 'trans'), $profiles->errors), 'errors');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
} elseif ($action === 'edit' && $id > 0) {
	$current = $profiles->fetch($id);
	if ($current) {
		$edit = $current;
	}
}


/*
 * View
 */

/**
 * The legal basis as a small grey link, for the tax advisor.
 *
 * @param array{basis:string,source:string} $rule Sphere or treatment
 * @return string HTML
 */
function vereineLegalBasis(array $rule)
{
	global $langs;

	return '<span class="opacitymedium small">'.$langs->trans('VereineTaxLegalBasis').': <a href="'.dol_escape_htmltag($rule['source']).'" target="_blank" rel="noopener noreferrer">'.dol_escape_htmltag($rule['basis']).'</a></span>';
}

$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-taxprofiles');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = vereineAdminPrepareHead();
print dol_get_fiche_head($head, 'taxprofiles', $title, -1, 'fa-landmark');

print '<span class="opacitymedium">'.$langs->trans('VereineTaxProfilesIntro').'</span><br><br>';
// The three questions in the order a treasurer answers them.
print '<div class="info" data-howto="1"><strong>'.$langs->trans('VereineTaxHowToTitle').'</strong><ol>';
foreach (array('VereineTaxHowTo1', 'VereineTaxHowTo2', 'VereineTaxHowTo3') as $step) {
	print '<li>'.$langs->trans($step).'</li>';
}
print '</ol></div>';
print info_admin($langs->trans('VereineTaxProfilesHonest'), 0, 0, '1', '');

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineTaxCode').'</td><td>'.$langs->trans('VereineTaxLabel').'</td>';
print '<td>'.$langs->trans('VereineTaxSphere').'</td><td>'.$langs->trans('VereineTaxTreatment').'</td>';
print '<td class="right">'.$langs->trans('VereineTaxRate').'</td><td>'.$langs->trans('VereineTaxNote').'</td>';
print '<td class="center">'.$langs->trans('Status').'</td><td></td></tr>';
foreach ($profiles->fetchAll() as $profile) {
	$sphere = isset($spheres[$profile['sphere']]) ? $spheres[$profile['sphere']] : array('basis' => '', 'source' => '');
	$treatment = isset($treatments[$profile['treatment']]) ? $treatments[$profile['treatment']] : array('basis' => '', 'source' => '');
	print '<tr class="oddeven" data-taxprofile="'.dol_escape_htmltag($profile['code']).'" data-active="'.($profile['active'] ? 1 : 0).'">';
	print '<td class="nowraponall">'.dol_escape_htmltag($profile['code']);
	if ($profile['standard']) {
		print ' <span class="opacitymedium small" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv('VereineTaxStandard')).'">*</span>';
	}
	print '</td>';
	print '<td>'.dol_escape_htmltag($profile['label']).'</td>';
	print '<td>'.$langs->trans('VereineSphere_'.$profile['sphere']).'<br>'.vereineLegalBasis($sphere).'</td>';
	print '<td>'.$langs->trans('VereineTreatment_'.$profile['treatment']).'<br>'.vereineLegalBasis($treatment).'</td>';
	print '<td class="right nowraponall">'.vereineRate($profile['rate']).' %</td>';
	print '<td class="small">'.dol_escape_htmltag(dol_trunc($profile['note'], 80)).'</td>';
	print '<td class="center">'.dolGetBadge($langs->trans($profile['active'] ? 'Enabled' : 'Disabled'), '', $profile['active'] ? 'success' : 'secondary').'</td>';
	print '<td class="right nowraponall">';
	print '<a class="editfielda paddingright" href="'.$_SERVER['PHP_SELF'].'?action=edit&amp;id='.((int) $profile['id']).'&amp;token='.newToken().'#vereinetaxprofile" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Modify')).'">'.img_edit().'</a>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block" name="vereinetaxtoggle">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="toggle">';
	print '<input type="hidden" name="id" value="'.((int) $profile['id']).'">';
	print '<button type="submit" class="button small" name="toggle" value="'.dol_escape_htmltag($profile['code']).'">'.$langs->trans($profile['active'] ? 'VereineTaxDeactivate' : 'VereineTaxActivate').'</button>';
	print '</form>';
	print '</td></tr>';
}
print '</table></div><br>';

// What each area and each VAT treatment means, in everyday words with the ministry's examples.
print load_fiche_titre($langs->trans('VereineTaxSpheresTitle'), '', '');
print '<table class="noborder centpercent">';
foreach ($spheres as $code => $sphere) {
	print '<tr class="oddeven" data-sphere-help="'.$code.'"><td class="titlefieldcreate"><strong>'.$langs->trans('VereineSphere_'.$code).'</strong></td>';
	print '<td>'.$langs->trans('VereineSphereHelp_'.$code).'<br>'.vereineLegalBasis($sphere).'</td></tr>';
}
print '</table><br>';
print load_fiche_titre($langs->trans('VereineTaxTreatmentsTitle'), '', '');
print '<table class="noborder centpercent">';
foreach ($treatments as $code => $treatment) {
	print '<tr class="oddeven" data-treatment-help="'.$code.'"><td class="titlefieldcreate"><strong>'.$langs->trans('VereineTreatment_'.$code).'</strong></td>';
	print '<td>'.$langs->trans('VereineTreatmentHelp_'.$code).'<br>'.vereineLegalBasis($treatment).'</td></tr>';
}
print '</table><br>';

$sphereOptions = array();
foreach (array_keys($spheres) as $code) {
	$sphereOptions[$code] = $langs->transnoentitiesnoconv('VereineSphere_'.$code);
}
$treatmentOptions = array();
foreach (array_keys($treatments) as $code) {
	$treatmentOptions[$code] = $langs->transnoentitiesnoconv('VereineTreatment_'.$code);
}

print load_fiche_titre($langs->trans($edit['id'] > 0 ? 'VereineTaxEdit' : 'VereineTaxNew'), '', '');
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinetaxprofile" id="vereinetaxprofile">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<input type="hidden" name="id" value="'.((int) $edit['id']).'">';
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate fieldrequired"><label for="code">'.$langs->trans('VereineTaxCode').'</label></td><td>';
if ($edit['id'] > 0) {
	print '<strong>'.dol_escape_htmltag($edit['code']).'</strong>';
} else {
	print '<input type="text" id="code" name="code" class="minwidth200" maxlength="32" value="'.dol_escape_htmltag($edit['code']).'">';
	print ' <span class="opacitymedium small">'.$langs->trans('VereineTaxCodeHelp').'</span>';
}
print '</td></tr>';
print '<tr><td class="fieldrequired"><label for="label">'.$langs->trans('VereineTaxLabel').'</label></td>';
print '<td><input type="text" id="label" name="label" class="quatrevingtpercent" maxlength="255" value="'.dol_escape_htmltag($edit['label']).'"></td></tr>';
print '<tr><td class="fieldrequired"><label for="sphere">'.$langs->trans('VereineTaxSphere').'</label></td>';
print '<td>'.Form::selectarray('sphere', $sphereOptions, $edit['sphere'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
print '<tr><td class="fieldrequired"><label for="treatment">'.$langs->trans('VereineTaxTreatment').'</label></td>';
print '<td>'.Form::selectarray('treatment', $treatmentOptions, $edit['treatment'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '<br><span class="opacitymedium small">'.$langs->trans('VereineTaxRateAuto').'</span></td></tr>';
print '<tr><td><label for="note">'.$langs->trans('VereineTaxNote').'</label></td>';
print '<td><textarea id="note" name="note" class="quatrevingtpercent" rows="2" maxlength="'.VereineTaxRules::NOTE_MAX.'">'.dol_escape_htmltag($edit['note']).'</textarea>';
print '<br><span class="opacitymedium small">'.$langs->trans('VereineTaxNoteHelp').'</span></td></tr>';
print '<tr><td><label for="active">'.$langs->trans('Enabled').'</label></td>';
print '<td><input type="checkbox" id="active" name="active" value="1"'.(!empty($edit['active']) ? ' checked' : '').'></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'">';
if ($edit['id'] > 0) {
	print ' <a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Cancel').'</a>';
}
print '</div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
