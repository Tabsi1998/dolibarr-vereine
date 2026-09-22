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
 * \file    application.php
 * \ingroup vereine
 * \brief   The blank application for membership, one per member type (#108).
 *
 * The application filled in for a member is a document template of Dolibarr's member card; this page
 * builds the blank form to print, with the fee of the member type and the current consents.
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

require_once __DIR__.'/class/vereinememberform.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('members', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read') || !$user->hasRight('adherent', 'lire')) {
	accessforbidden();
}

// Not $form: that is Dolibarr's own Form object on every page.
$application = new VereineMemberForm($db);
// Building a document is writing at the members, like Dolibarr's own documents.
$canWrite = $user->hasRight('adherent', 'creer');
$action = GETPOST('action', 'aZ09');
$typeId = GETPOSTINT('type');
$self = $_SERVER['PHP_SELF'];


/*
 * Actions
 */

if ($action === 'pdf') {
	$file = VereineMemberForm::blankPath($typeId);
	if ($typeId <= 0 || !is_file($file)) {
		accessforbidden();
	}
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
} elseif ($action === 'build' && $canWrite) {
	$type = $typeId > 0 ? $application->memberType($typeId) : null;
	if ($type === null) {
		setEventMessages($langs->trans('VereineApplicationTypeGone'), null, 'errors');
	} elseif ($application->build(null, $typeId, $langs, VereineMemberForm::blankPath($typeId)) !== '') {
		VereineLog::add($db, $user, VereineLog::APPLICATION_PDF, 0, 0, 'blank / type '.$typeId);
		setEventMessages($langs->trans('VereineApplicationBuilt'), null, 'mesgs');
		header('Location: '.$self.'#vereineapplication'.$typeId);
		exit;
	} else {
		setEventMessages($form->error, null, 'errors');
	}
}


/*
 * View
 */

$types = (new VereineFeeModel($db))->memberTypes(true);
$settings = VereineMemberForm::settings();

llxHeader('', $langs->trans('VereineApplicationTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-application');
print load_fiche_titre($langs->trans('VereineApplicationTitle'), '', 'fa-file-signature');
print '<div class="info" data-application-howto="1"><ul>';
print '<li>'.$langs->trans('VereineApplicationHowToBlank').'</li>';
print '<li>'.$langs->trans('VereineApplicationHowToMember').'</li>';
print '<li>'.$langs->trans('VereineApplicationHowToSource').'</li>';
if (!empty($user->admin)) {
	print '<li><a href="'.dol_buildpath('/vereine/admin/application.php', 1).'">'.$langs->trans('VereineApplicationHowToSetup').'</a></li>';
}
print '</ul></div>';

if ($settings['intro'] === '' && $settings['privacy'] === '') {
	print '<div class="warning" data-application-texts="0">'.$langs->trans('VereineApplicationNoTexts').'</div>';
}

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-application-types="'.count($types).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineApplicationType').'</td><td>'.$langs->trans('VereineApplicationFeeColumn').'</td><td></td></tr>';
foreach ($types as $type) {
	$file = VereineMemberForm::blankPath($type['id']);
	$model = $type['model'];
	print '<tr class="oddeven" data-application-type="'.$type['id'].'" data-application-pdf="'.(is_file($file) ? 1 : 0).'">';
	print '<td><span id="vereineapplication'.$type['id'].'"></span>'.dol_escape_htmltag($type['label']).'</td><td>';
	if (empty($type['subscription'])) {
		print '<span class="opacitymedium">'.$langs->trans('VereineApplicationNoFee').'</span>';
	} elseif ($model['amount'] === null) {
		print '<span class="opacitymedium">'.$langs->trans('VereineApplicationFeeMissing').'</span>';
	} else {
		print price($model['amount'], 0, $langs, 1, -1, 2).' € ';
		print '<span class="opacitymedium">'.$langs->trans('VereineApplicationPeriod_'.$model['duration_unit'], (int) $model['duration_value']).'</span>';
	}
	print '</td><td class="right nowraponall">';
	if (is_file($file)) {
		print '<a href="'.$self.'?action=pdf&amp;type='.$type['id'].'&amp;token='.newToken().'">'.img_picto('', 'pdf').' '.$langs->trans('VereineApplicationDownload').'</a> ';
	}
	if ($canWrite) {
		print '<form method="POST" action="'.$self.'#vereineapplication'.$type['id'].'" name="vereineapplication'.$type['id'].'" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="build">';
		print '<input type="hidden" name="type" value="'.$type['id'].'">';
		print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans(is_file($file) ? 'VereineApplicationRebuild' : 'VereineApplicationBuild')).'">';
		print '</form>';
	}
	print '</td></tr>';
}
if (!$types) {
	print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('VereineApplicationNoTypes').'</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
