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
print '<td>'.$langs->trans('VereineVerifyCode').'</td><td class="right">'.$langs->trans('VereineArchiveFiles').'</td></tr>';
foreach ($documents as $document) {
	print '<tr class="oddeven" data-archive-code="'.$document['code'].'" data-archive-kind="'.$document['kind'].'">';
	print '<td class="nowraponall">'.dol_print_date($document['created'], 'day').'</td><td>'.$langs->trans('VereineArchiveKind_'.$document['kind']).'</td>';
	print '<td>'.dol_escape_htmltag($document['title']).'</td><td class="nowraponall"><a href="'.VereineArchive::verifyUrl($document['code']).'">'
		.VereineArchiveRules::format($document['code']).'</a></td><td class="right">'.$document['files'].'</td></tr>';
}
if (!$documents) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('VereineArchiveNone').'</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
