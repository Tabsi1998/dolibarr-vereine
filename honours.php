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
 * \file    honours.php
 * \ingroup vereine
 * \brief   Honours, jubilees and birthdays of members, and the certificates to print (#27).
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

require_once __DIR__.'/class/vereinehonours.class.php';
require_once __DIR__.'/class/vereineconsents.class.php';
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

$honours = new VereineHonours($db);
$action = GETPOST('action', 'aZ09');
$canWrite = $user->hasRight('adherent', 'creer');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$thisYear = (int) substr($today, 0, 4);
$year = GETPOSTINT('year') >= $thisYear - 5 && GETPOSTINT('year') <= $thisYear + 5 ? GETPOSTINT('year') : $thisYear;
$here = $_SERVER['PHP_SELF'].'?year='.$year;
$consents = new VereineConsents($db);
$consentCodes = array_keys($consents->currentTexts());
$types = array();
$resql = $db->query("SELECT rowid, libelle FROM ".MAIN_DB_PREFIX."adherent_type WHERE entity IN (".getEntity('member_type').") ORDER BY libelle");
while ($resql && ($obj = $db->fetch_object($resql))) {
	$types[(int) $obj->rowid] = (string) $obj->libelle;
}


/*
 * Actions
 */

if ($action === 'savesettings' && !empty($user->admin)) {
	$result = $honours->saveSettings(array('milestones' => GETPOST('milestones', 'alphanohtml'), 'ages' => GETPOST('ages', 'alphanohtml'),
		'birthday_consent' => GETPOST('birthday_consent', 'aZ09'), 'honorary_type' => GETPOSTINT('honorary_type')), $consentCodes, array_keys($types), $user);
	if ($result > 0) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$here);
		exit;
	}
	setEventMessages($result < 0 ? $honours->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $honours->errors), 'errors');
} elseif (in_array($action, array('recordjubilee', 'recordaward', 'honorary'), true) && $canWrite) {
	$day = GETPOST('given_on', 'alphanohtml');
	if ($action === 'recordjubilee') {
		$result = $honours->record(GETPOSTINT('member'), 'jubilee', GETPOSTINT('years'), '', $day, $user);
	} elseif ($action === 'recordaward') {
		$result = $honours->record(GETPOSTINT('member'), 'award', 0, GETPOST('label', 'alphanohtml'), $day, $user);
	} else {
		$result = $honours->makeHonorary(GETPOSTINT('member'), $day, $user);
	}
	if ($result > 0) {
		setEventMessages($langs->trans('VereineHonourSaved'), null, 'mesgs');
		header('Location: '.$here.'#vereinehonourlist');
		exit;
	}
	setEventMessages($result < 0 ? $honours->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $honours->errors), 'errors');
} elseif ($action === 'certificate') {
	$file = $honours->certificate(GETPOSTINT('id'), $langs);
	if ($file === '') {
		setEventMessages($honours->error, null, 'errors');
	} else {
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="'.basename($file).'"');
		header('Content-Length: '.filesize($file));
		readfile($file);
		dol_delete_file($file);
		exit;
	}
}


/*
 * View
 */

$settings = VereineHonours::settings();
$members = array();
foreach ($honours->members() as $member) {
	if ($member['status'] === 1) {
		$members[$member['id']] = $member['name'];
	}
}
$title = $langs->trans('VereineHonoursTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-honours');
$navigation = '<a href="'.$_SERVER['PHP_SELF'].'?year='.($year - 1).'">&lsaquo; '.($year - 1).'</a> &nbsp; <a href="'.$_SERVER['PHP_SELF'].'?year='.($year + 1).'">'.($year + 1).' &rsaquo;</a>';
print load_fiche_titre($title.' '.$year, $navigation, 'fa-award');
print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineHonoursHowTo').'</div>';

// Jubilees of the year.
$jubilees = $honours->jubilees($year);
print load_fiche_titre($langs->trans('VereineHonourJubilees', implode(', ', $settings['milestones'])), '', '', 0, 'vereinejubilees');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-jubilees="'.count($jubilees).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('VereineHonourSince').'</td><td class="right">'.$langs->trans('VereineHonourYears').'</td>';
print '<td>'.$langs->trans('VereineHonourDay').'</td><td></td></tr>';
foreach ($jubilees as $jubilee) {
	print '<tr class="oddeven" data-jubilee="'.$jubilee['id'].'" data-jubilee-years="'.$jubilee['years'].'" data-jubilee-honoured="'.($jubilee['honoured'] ? 1 : 0).'">';
	print '<td><a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.$jubilee['id'].'">'.dol_escape_htmltag($jubilee['name']).'</a></td>';
	print '<td>'.vereineFormatDay($jubilee['since']).'</td><td class="right">'.$jubilee['years'].'</td><td>'.vereineFormatDay($jubilee['day']).'</td><td class="right">';
	if ($jubilee['honoured']) {
		print '<span class="badge badge-status4">'.$langs->trans('VereineHonourGiven').'</span>';
	} elseif ($canWrite) {
		print '<form method="POST" action="'.$here.'" name="vereinejubilee'.$jubilee['id'].'" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="recordjubilee">';
		print '<input type="hidden" name="member" value="'.$jubilee['id'].'"><input type="hidden" name="years" value="'.$jubilee['years'].'">';
		print '<input type="date" name="given_on" value="'.dol_escape_htmltag($today).'"> <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineHonourRecord')).'"></form>';
	}
	print '</td></tr>';
}
if (!$jubilees) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('VereineHonourNoJubilee').'</span></td></tr>';
}
print '</table></div><br>';

// Birthdays, only of members who agreed.
$birthdays = $honours->birthdays($year);
print load_fiche_titre($langs->trans('VereineHonourBirthdays'), '', '', 0, 'vereinebirthdays');
if ($birthdays === null) {
	print '<div class="opacitymedium" data-birthdays="off">'.$langs->trans('VereineHonourBirthdaysOff').'</div><br>';
} else {
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-birthdays="'.count($birthdays).'">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('VereineHonourDay').'</td><td class="right">'.$langs->trans('VereineHonourAge').'</td></tr>';
	foreach ($birthdays as $birthday) {
		print '<tr class="oddeven" data-birthday="'.$birthday['id'].'"><td>'.dol_escape_htmltag($birthday['name']).'</td><td>'.dol_print_date(dol_stringtotime($birthday['day']), '%d.%m.').'</td>';
		print '<td class="right">'.($birthday['round'] ? '<strong>'.$birthday['age'].'</strong>' : $birthday['age']).'</td></tr>';
	}
	if (!$birthdays) {
		print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('VereineHonourNoBirthday').'</span></td></tr>';
	}
	print '</table></div><br>';
}

// Honours given, with their certificates.
$given = $honours->honours();
print load_fiche_titre($langs->trans('VereineHonourList'), '', '', 0, 'vereinehonourlist');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-honours="'.count($given).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineHonourDay').'</td><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('VereineHonourKind').'</td><td></td></tr>';
foreach ($given as $honour) {
	$what = $honour['kind'] === 'jubilee' ? $langs->trans('VereineHonourKind_jubilee', $honour['years'])
		: ($honour['kind'] === 'award' ? dol_escape_htmltag($honour['label']) : $langs->trans('VereineHonourKind_honorary'));
	print '<tr class="oddeven" data-honour="'.$honour['id'].'" data-honour-kind="'.$honour['kind'].'"><td>'.vereineFormatDay($honour['given_on']).'</td>';
	print '<td>'.dol_escape_htmltag($honour['name']).'</td><td>'.$what.'</td>';
	print '<td class="right"><a href="'.$here.'&action=certificate&id='.$honour['id'].'&token='.newToken().'">'.img_pdf().' '.$langs->trans('VereineHonourCertificate').'</a></td></tr>';
}
if (!$given) {
	print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('VereineHonourNone').'</span></td></tr>';
}
print '</table></div>';

if ($canWrite && $members) {
	// An award or an honorary membership, decided by the board or the general assembly.
	print '<form method="POST" action="'.$here.'" name="vereinehonouraward" class="paddingtop">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="recordaward">';
	print Form::selectarray('member', $members, '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth200').' ';
	print '<input type="text" name="label" size="30" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('VereineHonourLabelPlaceholder')).'"> ';
	print '<input type="date" name="given_on" value="'.dol_escape_htmltag($today).'"> <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineHonourAward')).'"></form>';
	print '<form method="POST" action="'.$here.'" name="vereinehonourhonorary" class="paddingtop">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="honorary">';
	print Form::selectarray('member', $members, '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth200').' ';
	print '<input type="date" name="given_on" value="'.dol_escape_htmltag($today).'"> <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineHonourMakeHonorary')).'">';
	print ' <span class="opacitymedium small">'.$langs->trans($settings['honorary_type'] > 0 ? 'VereineHonourHonoraryHelp' : 'VereineHonourHonoraryNoType',
		isset($types[$settings['honorary_type']]) ? $types[$settings['honorary_type']] : '').'</span></form>';
}

if (!empty($user->admin)) {
	print '<br>'.load_fiche_titre($langs->trans('VereineHonourSettings'), '', '', 0, 'vereinehonoursettings');
	print '<form method="POST" action="'.$here.'" name="vereinehonoursettings">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="savesettings">';
	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate">'.$langs->trans('VereineHonourMilestones').'</td><td><input type="text" name="milestones" size="30" value="'.dol_escape_htmltag(implode(', ', $settings['milestones'])).'"></td></tr>';
	$consentChoices = array('' => $langs->trans('VereineHonourBirthdayNone'));
	foreach ($consentCodes as $code) {
		$consentChoices[$code] = $code;
	}
	print '<tr><td>'.$langs->trans('VereineHonourBirthdayConsent').'</td><td>'.Form::selectarray('birthday_consent', $consentChoices, $settings['birthday_consent'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print ' <span class="opacitymedium small">'.$langs->trans('VereineHonourBirthdayConsentHelp').'</span></td></tr>';
	print '<tr><td>'.$langs->trans('VereineHonourHonoraryType').'</td><td>'.Form::selectarray('honorary_type', array(0 => '') + $types, $settings['honorary_type'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print ' <span class="opacitymedium small">'.$langs->trans('VereineHonourHonoraryTypeHelp').'</span></td></tr>';
	print '<tr><td>'.$langs->trans('VereineStatisticsAges').'</td><td><input type="text" name="ages" size="30" value="'.dol_escape_htmltag(implode(', ', $settings['ages'])).'">';
	print ' <span class="opacitymedium small">'.$langs->trans('VereineStatisticsAgesHelp').'</span></td></tr>';
	print '</table><div class="center paddingtop"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div></form>';
}

llxFooter();
$db->close();
