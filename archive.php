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
 * \file    archive.php
 * \ingroup vereine
 * \brief   The files of the association (#123): finished documents with their codes, and the export of a period as ZIP.
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

require_once __DIR__.'/class/vereinearchive.class.php';
require_once __DIR__.'/class/vereinepublications.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('members', 'other', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read')) {
	accessforbidden();
}

$archive = new VereineArchive($db);
$action = GETPOST('action', 'aZ09');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$thisYear = (int) substr($today, 0, 4);
$year = GETPOSTINT('year');
if ($year !== 0 && ($year < $thisYear - 30 || $year > $thisYear)) {
	$year = $thisYear;
}


/*
 * Actions
 */

if ($action === 'export') {
	$period = VereineArchiveRules::period(GETPOST('from', 'alphanohtml'), GETPOST('to', 'alphanohtml'));
	if ($period === null) {
		setEventMessages($langs->trans('VereineArchiveErrorPeriod'), null, 'errors');
	} else {
		$file = $archive->export($period['from'], $period['to'], $user, $langs);
		if ($file === '' || !is_file($file)) {
			setEventMessages($archive->error, null, 'errors');
		} else {
			header('Content-Type: application/zip');
			header('Content-Disposition: attachment; filename="'.basename($file).'"');
			header('Content-Length: '.filesize($file));
			readfile($file);
			exit;
		}
	}
} elseif ($action === 'publish' && $user->hasRight('adherent', 'creer')) {
	// Publishing for the public is the administrator's; for members and board whoever runs the members.
	$audience = GETPOST('audience', 'aZ09');
	$publications = new VereinePublications($db);
	$documentId = GETPOSTINT('document');
	$result = $audience === 'public' && empty($user->admin) ? 0 : $publications->publish($documentId, $publications->bestFile($documentId), $audience, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereinePublicationDone'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?year='.$year.'#vereinearchivedocuments');
		exit;
	}
	setEventMessages($result < 0 ? $publications->error : null, $result < 0 ? null : ($publications->errors ? array_map(array($langs, 'trans'), $publications->errors)
		: array($langs->trans('VereinePublicationErrorPublic'))), 'errors');
} elseif ($action === 'withdraw' && $user->hasRight('adherent', 'creer')) {
	$publications = new VereinePublications($db);
	if ($publications->withdraw(GETPOSTINT('publication'), $user) < 0) {
		setEventMessages($publications->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('VereinePublicationWithdrawn'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?year='.$year.'#vereinearchivedocuments');
		exit;
	}
} elseif ($action === 'publishrules' && !empty($user->admin)) {
	$entered = array();
	foreach (VereineArchiveRules::KINDS as $kind) {
		$entered[$kind] = array('audience' => GETPOST('audience_'.$kind, 'aZ09'), 'auto' => GETPOSTISSET('auto_'.$kind));
	}
	$publications = new VereinePublications($db);
	if ($publications->saveRules($entered, $user) < 0) {
		setEventMessages($publications->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereinepublishrules');
		exit;
	}
} elseif (($action === 'publicon' || $action === 'publicoff') && !empty($user->admin)) {
	if ($archive->setPublic($action === 'publicon', $user) > 0) {
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	setEventMessages($archive->error, null, 'errors');
}


/*
 * View
 */

$documents = $archive->documents($year);
llxHeader('', $langs->trans('VereineArchiveTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-archive');
print load_fiche_titre($langs->trans('VereineArchiveTitle'), '', 'fa-archive');
print '<div class="info" data-archive-howto="1">'.$langs->trans('VereineArchiveHowTo').'</div>';

// Whether anybody may check a printout, and where.
$public = VereineArchive::publicOn();
print '<div class="paddingbottom" data-archive-public="'.($public ? 1 : 0).'">'.$langs->trans($public ? 'VereineArchivePublicOn' : 'VereineArchivePublicOff',
	'<a href="'.dol_buildpath('/vereine/public/verify.php', 1).'">'.dol_escape_htmltag(dol_buildpath('/vereine/public/verify.php', 2)).'</a>');
if (!empty($user->admin)) {
	print ' <form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinearchivepublic" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.($public ? 'publicoff' : 'publicon').'">';
	print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans($public ? 'VereineArchivePublicSwitchOff' : 'VereineArchivePublicSwitchOn')).'"></form>';
}
print '</div>';

// A period as one ZIP, for instance when the board changes.
print load_fiche_titre($langs->trans('VereineArchiveExport'), '', '', 0, 'vereinearchiveexport');
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinearchiveexport" class="paddingbottom">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="export">';
print $langs->trans('DateFrom').' <input type="date" name="from" value="'.dol_escape_htmltag(($year > 0 ? $year : $thisYear).'-01-01').'"> ';
print $langs->trans('DateTo').' <input type="date" name="to" value="'.dol_escape_htmltag(($year > 0 ? $year : $thisYear).'-12-31').'"> ';
print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineArchiveExportButton')).'"></form>';

print load_fiche_titre($langs->trans('VereineArchiveDocuments'), '', '', 0, 'vereinearchivedocuments');
print '<div class="paddingbottom">'.$langs->trans('Year').': <a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?year=0">'.$langs->trans('All').'</a> ';
for ($option = $thisYear; $option >= $thisYear - 5; $option--) {
	print $option === $year ? '<strong class="paddingright">'.$option.'</strong> ' : '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?year='.$option.'">'.$option.'</a> ';
}
print '</div>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-archive-documents="'.count($documents).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Type').'</td><td>'.$langs->trans('Title').'</td>';
print '<td>'.$langs->trans('VereineVerifyCode').'</td><td class="right">'.$langs->trans('VereineArchiveFiles').'</td><td>'.$langs->trans('VereinePublicationTitle').'</td></tr>';
$published = (new VereinePublications($db))->byDocument();
$canPublish = $user->hasRight('adherent', 'creer');
$audiences = array();
foreach (VereinePublicationRules::AUDIENCES as $audience) {
	if ($audience !== 'public' || !empty($user->admin)) {
		$audiences[$audience] = $langs->trans('VereinePublicationAudience_'.$audience);
	}
}
foreach ($documents as $document) {
	print '<tr class="oddeven" data-archive-code="'.$document['code'].'" data-archive-kind="'.$document['kind'].'">';
	print '<td class="nowraponall">'.dol_print_date($document['created'], 'day').'</td><td>'.$langs->trans('VereineArchiveKind_'.$document['kind']).'</td>';
	print '<td>'.dol_escape_htmltag($document['title']).'</td><td class="nowraponall"><a href="'.VereineArchive::verifyUrl($document['code']).'">'
		.VereineArchiveRules::format($document['code']).'</a></td><td class="right">'.$document['files'].'</td><td>';
	// Who sees it, and the way to change that.
	foreach (isset($published[$document['id']]) ? $published[$document['id']] : array() as $publication) {
		print '<div data-publication="'.$publication['id'].'" data-publication-audience="'.$publication['audience'].'">'.$langs->trans('VereinePublicationAudience_'.$publication['audience'])
			.' <span class="opacitymedium small">('.$langs->trans('VereineArchiveFile_'.$publication['what']).')</span>';
		if ($canPublish && ($publication['audience'] !== 'public' || !empty($user->admin))) {
			print ' <form method="POST" action="'.$_SERVER['PHP_SELF'].'?year='.$year.'" name="vereinewithdraw'.$publication['id'].'" class="inline-block">';
			print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="withdraw"><input type="hidden" name="publication" value="'.$publication['id'].'">';
			print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('VereinePublicationWithdraw')).'"></form>';
		}
		print '</div>';
	}
	if ($canPublish) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?year='.$year.'" name="vereinepublish'.$document['id'].'" class="paddingtop">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="publish"><input type="hidden" name="document" value="'.$document['id'].'">';
		print Form::selectarray('audience', $audiences, '', 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150').' ';
		print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('VereinePublicationPublish')).'"></form>';
	}
	print '</td></tr>';
}
if (!$documents) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('VereineArchiveNone').'</span></td></tr>';
}
print '</table></div>';

// Which kinds of documents go out by themselves once signed (#156).
if (!empty($user->admin)) {
	$rules = VereinePublications::rules();
	print '<br>'.load_fiche_titre($langs->trans('VereinePublicationRulesTitle'), '', '', 0, 'vereinepublishrules');
	print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereinePublicationRulesHowTo').'</div>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinepublishrules">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="publishrules">';
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Type').'</td><td>'.$langs->trans('VereinePublicationAudience').'</td>';
	print '<td class="center">'.$langs->trans('VereinePublicationAuto').'</td></tr>';
	$choices = array('' => $langs->trans('VereinePublicationAudience_none'));
	foreach (VereinePublicationRules::AUDIENCES as $audience) {
		$choices[$audience] = $langs->trans('VereinePublicationAudience_'.$audience);
	}
	foreach (VereineArchiveRules::KINDS as $kind) {
		print '<tr class="oddeven" data-publish-rule="'.$kind.'"><td>'.$langs->trans('VereineArchiveKind_'.$kind).'</td>';
		print '<td>'.Form::selectarray('audience_'.$kind, $choices, $rules[$kind]['audience'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth150').'</td>';
		print '<td class="center"><input type="checkbox" name="auto_'.$kind.'" value="1"'.($rules[$kind]['auto'] ? ' checked' : '').'></td></tr>';
	}
	print '</table><div class="center paddingtop"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div></form>';
}

llxFooter();
$db->close();
