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
 * \file    participations.php
 * \ingroup vereine
 * \brief   Participations of members (#273): recorded for several members at once, and who was active in a year.
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

require_once __DIR__.'/class/vereineparticipations.class.php';
require_once __DIR__.'/class/vereinehonourrules.class.php';
require_once __DIR__.'/class/vereineevents.class.php';
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

$participations = new VereineParticipations($db);
$action = GETPOST('action', 'aZ09');
$canWrite = $user->hasRight('adherent', 'creer');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$thisYear = (int) substr($today, 0, 4);
$year = GETPOSTINT('year') >= 1990 && GETPOSTINT('year') <= $thisYear ? GETPOSTINT('year') : $thisYear;
$here = $_SERVER['PHP_SELF'].'?year='.$year;
$kinds = $participations->kinds();
$entered = array('members' => array(), 'kind' => isset($kinds['event']) ? 'event' : (string) key($kinds), 'title' => '', 'day' => $today, 'hours' => '');
// From an event: its name and day are what everybody who took part shares.
$eventId = GETPOSTINT('event');
if ($eventId > 0) {
	$event = (new VereineEvents($db))->fetch($eventId);
	if ($event !== null) {
		$entered['title'] = (string) $event['label'];
		$entered['day'] = (string) $event['event_day'] <= $today ? (string) $event['event_day'] : $today;
	}
}


/*
 * Actions
 */

if ($action === 'record' && $canWrite) {
	$entered = array('members' => GETPOST('members', 'array'), 'kind' => GETPOST('kind', 'aZ09'), 'title' => GETPOST('title', 'alphanohtml'),
		'day' => GETPOST('day', 'alphanohtml'), 'hours' => GETPOST('hours', 'alphanohtml'));
	$result = $participations->record((array) $entered['members'], $entered, $today, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineParticipationRecorded', $result), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?year='.substr((string) $entered['day'], 0, 4).'#vereineactive');
		exit;
	}
	setEventMessages($result < 0 ? $participations->error : null, $result < 0 ? null : array_map(function ($field) use ($langs) {
		return $langs->trans('VereineParticipationError_'.$field);
	}, $participations->errors), 'errors');
}

$report = $participations->report($year, $today);
$labels = $report['labels'];
// One row per active member, the same for the page and the file.
$rows = array(array_merge(array($langs->transnoentities('Name'), $langs->transnoentities('VereineParticipationCount'), $langs->transnoentities('VereineParticipationHours')),
	array_map(function ($kind) use ($labels) {
		return $labels[$kind];
	}, array_keys($report['kinds']))));
foreach ($report['members'] as $member => $numbers) {
	$row = array($numbers['name'], $numbers['count'], price2num($numbers['hours']));
	foreach (array_keys($report['kinds']) as $kind) {
		$row[] = isset($numbers['kinds'][$kind]) ? $numbers['kinds'][$kind]['count'] : 0;
	}
	$rows[] = $row;
}

if ($action === 'csv') {
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="aktive-mitglieder-'.$year.'.csv"');
	print VereineHonourRules::csv($rows);
	exit;
}


/*
 * View
 */

$title = $langs->trans('VereineParticipationsTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-participations');
$navigation = '<a href="'.$_SERVER['PHP_SELF'].'?year='.($year - 1).'">&lsaquo; '.($year - 1).'</a>';
if ($year < $thisYear) {
	$navigation .= ' &nbsp; <a href="'.$_SERVER['PHP_SELF'].'?year='.($year + 1).'">'.($year + 1).' &rsaquo;</a>';
}
print load_fiche_titre($title.' '.$year, $navigation, 'fa-running');
print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineParticipationsHowTo').'</div>';

if ($canWrite) {
	$members = array();
	$resql = $db->query("SELECT rowid, firstname, lastname FROM ".MAIN_DB_PREFIX."adherent WHERE entity IN (".getEntity('adherent').") AND statut = 1 ORDER BY lastname, firstname");
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$members[(int) $obj->rowid] = trim($obj->lastname.' '.$obj->firstname);
	}
	print load_fiche_titre($langs->trans('VereineParticipationNew'), '', '', 0, 'vereineparticipationnew');
	print '<form method="POST" action="'.$here.'" name="vereineparticipations">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="record">';
	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('VereineParticipationMembers').'</td><td>';
	print Form::multiselectarray('members', $members, array_map('intval', (array) $entered['members']), 0, 0, 'minwidth300 maxwidth500', 0, '100%').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('VereineParticipationKind').'</td><td>'.Form::selectarray('kind', $kinds, $entered['kind'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('VereineParticipationTitle').'</td><td><input type="text" name="title" size="50" maxlength="255" value="'.dol_escape_htmltag((string) $entered['title']).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('VereineParticipationDay').'</td><td><input type="date" name="day" max="'.dol_escape_htmltag($today).'" value="'.dol_escape_htmltag((string) $entered['day']).'"></td></tr>';
	print '<tr><td>'.$langs->trans('VereineParticipationHours').'</td><td><input type="text" name="hours" size="6" value="'.dol_escape_htmltag((string) $entered['hours']).'">';
	print ' <span class="opacitymedium small">'.$langs->trans('VereineParticipationHoursHelp').'</span></td></tr>';
	print '</table><div class="center paddingtop"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineParticipationRecord')).'"></div></form>';
	if (!empty($user->admin)) {
		print '<div class="opacitymedium small paddingtop" data-participation-dictionary="1">'.$langs->trans('VereineParticipationKindsHelp');
		print ' <a href="'.DOL_URL_ROOT.'/admin/dict.php">'.$langs->trans('VereineDictionaries').'</a></div>';
	}
	print '<br>';
}

// Who was active in the year: at least one participation, with the number and hours per kind.
print load_fiche_titre($langs->trans('VereineParticipationActive', $year), '<a class="button small" href="'.$here.'&action=csv&token='.newToken().'">'.$langs->trans('VereineParticipationCsv').'</a>', '', 0, 'vereineactive');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-active-members="'.$report['total'].'">';
foreach ($rows as $index => $row) {
	if ($index === 0) {
		print '<tr class="liste_titre">';
	} else {
		$member = array_keys($report['members'])[$index - 1];
		print '<tr class="oddeven" data-active-member="'.$member.'" data-active-count="'.$report['members'][$member]['count'].'">';
	}
	foreach ($row as $column => $cell) {
		print '<td'.($column > 0 ? ' class="right"' : '').'>'.dol_escape_htmltag((string) $cell).'</td>';
	}
	print '</tr>';
}
if ($report['members']) {
	// Per kind how many members took part, and in how many participations.
	print '<tr class="liste_total"><td>'.$langs->trans('VereineParticipationTotal', $report['total']).'</td>';
	print '<td class="right">'.array_sum(array_column($report['kinds'], 'count')).'</td><td class="right">'.price2num(array_sum(array_column($report['kinds'], 'hours'))).'</td>';
	foreach ($report['kinds'] as $kind => $numbers) {
		print '<td class="right" data-active-kind="'.dol_escape_htmltag($kind).'" data-active-kind-members="'.$numbers['members'].'">'.$langs->trans('VereineParticipationKindMembers', $numbers['members']).'</td>';
	}
	print '</tr>';
} else {
	print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('VereineParticipationNone').'</span></td></tr>';
}
print '</table></div>';
print '<div class="opacitymedium small paddingtop">'.$langs->trans('VereineParticipationActiveNote').'</div>';

llxFooter();
$db->close();
