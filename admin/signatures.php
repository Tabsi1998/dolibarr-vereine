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
 * \file    admin/signatures.php
 * \ingroup vereine
 * \brief   Who signs which kind of document, and how many signatures it needs.
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
require_once __DIR__.'/../class/vereinesignatures.class.php';

$langs->loadLangs(array('admin', 'members', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$signatures = new VereineSignatures($db);
$qes = new VereineQes($db);

if ($action === 'saverules') {
	$entered = array();
	foreach ((array) GETPOST('rule', 'array') as $kind => $rule) {
		$entered[$kind] = array(
			'roles' => isset($rule['roles']) && is_array($rule['roles']) ? $rule['roles'] : array(),
			'mode' => isset($rule['mode']) ? (string) $rule['mode'] : VereineSignatureRules::MODE_ALL,
			'min' => isset($rule['min']) ? (int) $rule['min'] : 1,
			'sign' => isset($rule['sign']) ? (string) $rule['sign'] : VereineSignatureRules::SIGN_CLICK,
		);
	}
	$result = $signatures->saveRules($entered, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineSignatureRulesSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	setEventMessages($result < 0 ? $signatures->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $signatures->errors), 'errors');
} elseif ($action === 'saveqes') {
	$result = $qes->saveSettings(GETPOST('qes_url', 'url'), GETPOST('qes_connector', 'aZ09'), GETPOST('qes_key', 'alphanohtml'), GETPOST('qes_profile', 'alphanohtml'), $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineQesSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereineqes');
		exit;
	}
	setEventMessages($result < 0 ? $qes->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $qes->errors), 'errors');
} elseif ($action === 'checkqes') {
	// A blank page shows whether the service takes a document the way the module sends it.
	$pdf = VereinePdf::start($langs);
	$here = dol_buildpath('/vereine/admin/signatures.php', 2);
	$answer = $qes->sign($pdf->Output('', 'S'), 'vereine-check', $here, $here);
	if ($answer['redirect'] !== '' || $answer['signed'] !== '') {
		setEventMessages($langs->trans('VereineQesCheckOk'), null, 'mesgs');
	} else {
		setEventMessages(null, array_merge(array_map(array($langs, 'trans'), $qes->errors), $qes->error !== '' ? array($qes->error) : array()), 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'#vereineqes');
	exit;
}

$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-signatures');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');
print dol_get_fiche_head(vereineAdminPrepareHead(), 'signatures', $title, -1, 'fa-landmark');

print '<div class="info" data-signatures-howto="1"><ul>';
foreach (array('VereineSignatureHowToWho', 'VereineSignatureHowToWays', 'VereineSignatureHowToLegal') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
print '</ul></div>';

$rules = $signatures->rules();
$labels = $signatures->functionLabels();
$catalogue = array();
foreach ((new VereineFunctions($db))->fetchAll(true) as $function) {
	$catalogue[$function['code']] = $function['label'];
}
$holders = $signatures->holders(dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'));

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinesignaturerules">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="saverules">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="width25p">'.$langs->trans('VereineSignatureDocument').'</td><td>'.$langs->trans('VereineSignatureWho').'</td>';
print '<td>'.$langs->trans('VereineSignatureHowMany').'</td><td>'.$langs->trans('VereineSignatureSignHow').'</td></tr>';
foreach (VereineSignatureRules::KINDS as $kind) {
	$rule = $rules[$kind];
	print '<tr class="oddeven tdtop" data-rule="'.$kind.'" data-roles="'.count($rule['roles']).'" data-mode="'.$rule['mode'].'" data-sign="'.$rule['sign'].'">';
	print '<td>'.$langs->trans('VereineSignatureKind_'.$kind).'<div class="opacitymedium small">'.$langs->trans('VereineSignatureKindHelp_'.$kind).'</div></td><td>';
	foreach ($catalogue as $code => $label) {
		$holderCount = isset($holders[$code]) ? count($holders[$code]) : 0;
		print '<label class="inline-block paddingright"><input type="checkbox" name="rule['.$kind.'][roles][]" value="'.$code.'"';
		print (in_array($code, $rule['roles'], true) ? ' checked' : '').'> '.dol_escape_htmltag($label);
		print ' <span class="opacitymedium small">'.$langs->trans('VereineSignatureHolders', $holderCount).'</span></label> ';
	}
	if (!$catalogue) {
		print '<span class="opacitymedium">'.$langs->trans('VereineSignatureNoFunctions').'</span>';
	}
	print '</td><td class="nowraponall"><select name="rule['.$kind.'][mode]">';
	foreach (VereineSignatureRules::MODE_LIST as $mode) {
		print '<option value="'.$mode.'"'.($rule['mode'] === $mode ? ' selected' : '').'>'.$langs->trans('VereineSignatureMode_'.$mode).'</option>';
	}
	print '</select> <input type="number" min="1" max="20" name="rule['.$kind.'][min]" class="width50" value="'.((int) $rule['min']).'">';
	print '</td><td><select name="rule['.$kind.'][sign]">';
	foreach (VereineSignatureRules::SIGN_WAYS as $way) {
		print '<option value="'.$way.'"'.($rule['sign'] === $way ? ' selected' : '').'>'.$langs->trans('VereineSignatureSign_'.$way).'</option>';
	}
	print '</select></td></tr>';
}
print '</table></div>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div>';
print '</form>';

// The signature service for ID Austria.
$settings = VereineQes::settings();
print load_fiche_titre($langs->trans('VereineQesSetupTitle'), '', '', 0, 'vereineqes');
print '<div class="info" data-qes-howto="1"><ul>';
foreach (array('VereineQesSetupIntro', 'VereineQesSetupReach', 'VereineQesSetupPrivacy') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
print '</ul></div>';
if ($settings['url'] !== '' && $settings['connector'] === VereineQes::CONNECTOR_TEST) {
	print '<div class="warning" data-qes-test="1">'.$langs->trans('VereineQesTestMode').'</div>';
}
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereineqessetup">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="saveqes">';
print '<table class="noborder centpercent" data-qes-configured="'.($settings['url'] !== '' ? 1 : 0).'" data-qes-connector="'.$settings['connector'].'">';
print '<tr class="oddeven"><td class="width25p"><label for="qes_url">'.$langs->trans('VereineQesUrl').'</label></td>';
print '<td><input type="text" id="qes_url" name="qes_url" class="minwidth300" value="'.dol_escape_htmltag($settings['url']).'" placeholder="https://signatur.example.at/pdf-as-web">';
print '<div class="opacitymedium small">'.$langs->trans('VereineQesUrlHelp').'</div></td></tr>';
print '<tr class="oddeven"><td><label for="qes_connector">'.$langs->trans('VereineQesConnector').'</label></td><td><select id="qes_connector" name="qes_connector">';
foreach (VereineQes::CONNECTORS as $connector) {
	print '<option value="'.$connector.'"'.($settings['connector'] === $connector ? ' selected' : '').'>'.$langs->trans('VereineQesConnector_'.$connector).'</option>';
}
print '</select></td></tr>';
print '<tr class="oddeven"><td><label for="qes_key">'.$langs->trans('VereineQesKey').'</label></td>';
print '<td><input type="text" id="qes_key" name="qes_key" value="'.dol_escape_htmltag($settings['key']).'"></td></tr>';
print '<tr class="oddeven"><td><label for="qes_profile">'.$langs->trans('VereineQesProfile').'</label></td>';
print '<td><input type="text" id="qes_profile" name="qes_profile" value="'.dol_escape_htmltag($settings['profile']).'"></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div>';
print '</form>';
if ($settings['url'] !== '') {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereineqescheck" class="center paddingtop">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="checkqes">';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineQesCheck')).'">';
	print '</form>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
