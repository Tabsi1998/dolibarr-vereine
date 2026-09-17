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
 * \file    functions.php
 * \ingroup vereine
 * \brief   Board and functions: who holds which function on a day, and what does not fit.
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

require_once __DIR__.'/class/vereinefunctions.class.php';
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

$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$day = GETPOST('day', 'alpha');
if (!VereineFunctionRules::isDate($day)) {
	$day = $today;
}

$store = new VereineFunctions($db);
$canWrite = $user->hasRight('adherent', 'creer');
$action = GETPOST('action', 'aZ09');


/*
 * Actions
 */

if ($action === 'reportpdf' && $canWrite) {
	$file = $store->buildReportPdf($day, $langs);
	if ($file === '') {
		setEventMessages($store->error, null, 'errors');
	} else {
		VereineLog::add($db, $user, VereineLog::FUNCTION_REPORT_PDF, 0, 0, basename($file));
		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="'.basename($file).'"');
		header('Content-Length: '.filesize($file));
		readfile($file);
		exit;
	}
} elseif ($action === 'applygroups' && !empty($user->admin)) {
	$done = 0;
	foreach ((array) GETPOST('changes', 'array') as $change) {
		if (!preg_match('/^(add|remove):(\d+):(\d+)$/', (string) $change, $parts)) {
			continue;
		}
		$result = $store->applyGroupChange($parts[1], (int) $parts[2], (int) $parts[3], $today, $user);
		if ($result < 0) {
			setEventMessages($store->error, null, 'errors');
		}
		$done += $result > 0 ? 1 : 0;
	}
	setEventMessages($langs->trans('VereineGroupsApplied', $done), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'#vereinegroups');
	exit;
} elseif ($action === 'markreported' && $canWrite) {
	$result = $store->markReported(GETPOST('reported_on', 'alpha'), $user);
	if ($result < 0) {
		setEventMessages($store->error, null, 'errors');
	} elseif ($result === 0 && $store->errors) {
		setEventMessages(null, array_map(array($langs, 'trans'), $store->errors), 'errors');
	} else {
		setEventMessages($langs->trans('VereineReportMarked', $result), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?day='.urlencode($day).'#vereinereport');
	exit;
}

$functions = $store->fetchAll(true);
$terms = $store->terms();
$check = VereineFunctionRules::check($functions, $terms, $day);
$names = array();
foreach ($terms as $term) {
	$names[$term['member_id']] = $term['member_name'];
}
$labels = array();
foreach ($functions as $function) {
	$labels[$function['id']] = $function['label'];
}


/*
 * View
 */

$title = $langs->trans('VereineFunctionsTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-functions');
print load_fiche_titre($title, '', 'fa-landmark');
print '<span class="opacitymedium">'.$langs->trans('VereineFunctionsIntro').'</span><br><br>';

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="vereinefunctionsday">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<label for="day">'.$langs->trans('VereineFunctionsDay').'</label> <input type="date" id="day" name="day" value="'.dol_escape_htmltag($day).'"> ';
print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Refresh')).'">';
print '</form><br>';

if ($check['problems']) {
	print '<div class="warning" data-problems="'.count($check['problems']).'"><ul>';
	foreach ($check['problems'] as $problem) {
		$members = array();
		foreach ($problem['members'] as $memberId) {
			$members[] = isset($names[$memberId]) ? $names[$memberId] : '#'.$memberId;
		}
		$label = $problem['function_id'] > 0 && isset($labels[$problem['function_id']]) ? $labels[$problem['function_id']] : '';
		$code = '';
		foreach ($functions as $function) {
			if ($function['id'] === $problem['function_id']) {
				$code = $function['code'];
			}
		}
		print '<li data-problem="'.$problem['kind'].'" data-function="'.dol_escape_htmltag($code).'">';
		print $langs->trans('VereineFunctionProblem_'.$problem['kind'], dol_escape_htmltag($label), $problem['count'], dol_escape_htmltag(implode(', ', $members))).'</li>';
	}
	print '</ul></div>';
} else {
	print '<div class="ok" data-problems="0">'.$langs->trans('VereineFunctionsAllFine').'</div>';
}

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineFunctionLabel').'</td><td class="center">'.$langs->trans('VereineFunctionCount').'</td><td>'.$langs->trans('VereineFunctionsHolders').'</td></tr>';
if (!$functions) {
	print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('VereineFunctionsNone').'</span></td></tr>';
}
foreach ($functions as $function) {
	$holders = $check['holders'][$function['id']];
	print '<tr class="oddeven" data-function-row="'.dol_escape_htmltag($function['code']).'" data-holders="'.count($holders).'">';
	print '<td>'.dol_escape_htmltag($function['label']);
	if ($function['board']) {
		print ' '.dolGetBadge($langs->trans('VereineFunctionBoard'), '', 'info');
	}
	print '</td><td class="center nowraponall">'.vereineFunctionCount($function).'</td><td>';
	$links = array();
	foreach ($holders as $memberId) {
		$links[] = '<a href="'.dol_buildpath('/vereine/member_association.php', 1).'?id='.((int) $memberId).'#vereinefunctions">'.dol_escape_htmltag($names[$memberId]).'</a>';
	}
	print $links ? implode(', ', $links) : '<span class="opacitymedium">'.$langs->trans('VereineFunctionsVacant').'</span>';
	print '</td></tr>';
}
print '</table></div><br>';

// Report to the association authority: new representatives within four weeks, with what the report needs.
print load_fiche_titre($langs->trans('VereineReportTitle'), '', '', 0, 'vereinereport');
print '<div class="info" data-report-howto="1">'.$langs->trans('VereineReportHowTo').'</div>';
$open = $store->reports(true);
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineFunctionLabel').'</td><td>'.$langs->trans('Member').'</td><td>'.$langs->trans('DateStart').'</td>';
print '<td>'.$langs->trans('VereineReportDeadline').'</td></tr>';
if (!$open) {
	print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('VereineReportNoneOpen').'</span></td></tr>';
}
foreach ($open as $report) {
	$overdue = $report['deadline'] < $today;
	print '<tr class="oddeven" data-report="'.((int) $report['term_id']).'" data-deadline="'.dol_escape_htmltag($report['deadline']).'" data-overdue="'.($overdue ? 1 : 0).'">';
	print '<td>'.dol_escape_htmltag($report['function']).'</td><td>'.dol_escape_htmltag($report['member_name']).'</td>';
	print '<td class="nowraponall">'.vereineFormatDay($report['start']).'</td><td class="nowraponall">'.vereineFormatDay($report['deadline']);
	if ($overdue) {
		print ' '.dolGetBadge($langs->trans('VereineReportOverdue'), '', 'danger');
	}
	print '</td></tr>';
}
print '</table></div>';
foreach ($store->representatives($day) as $person) {
	if ($person['missing']) {
		$missing = array();
		foreach ($person['missing'] as $key) {
			$missing[] = $langs->trans('VereineReportMissing_'.$key);
		}
		print '<div class="warning" data-missing="'.((int) $person['member_id']).'">';
		print $langs->trans('VereineReportMissingData', '<a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.((int) $person['member_id']).'">'.dol_escape_htmltag($person['name']).'</a>', implode(', ', $missing));
		print '</div>';
	}
}
if ($canWrite) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?day='.urlencode($day).'" name="vereinereportpdf" class="inline-block">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="reportpdf">';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('VereineReportPdf')).'">';
	print '</form> ';
	if ($open) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?day='.urlencode($day).'" name="vereinemarkreported" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="markreported">';
		print '<label for="reported_on" class="fieldrequired">'.$langs->trans('VereineReportReportedOn').'</label> <input type="date" id="reported_on" name="reported_on" value="'.$today.'"> ';
		print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('VereineReportMark')).'">';
		print '</form>';
	}
}

print '<br>';

// Rights through functions: group changes the functions ask for, only after an administrator confirms them.
print load_fiche_titre($langs->trans('VereineGroupsTitle'), '', '', 0, 'vereinegroups');
print '<div class="info" data-groups-howto="1">'.$langs->trans('VereineGroupsHowTo').'</div>';
$groupNames = $store->userGroups();
$memberUsers = $store->memberUsers();
$functionsById = array();
foreach ($functions as $function) {
	$functionsById[$function['id']] = $function;
}
$changes = $store->groupChanges($today);
$memberNames = array();
foreach ($terms as $term) {
	$memberNames[$term['member_id']] = $term['member_name'];
}
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereineapplygroups">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="applygroups">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td></td><td>'.$langs->trans('Member').'</td><td>'.$langs->trans('Login').'</td><td>'.$langs->trans('VereineFunctionGroup').'</td>';
print '<td>'.$langs->trans('VereineGroupsChange').'</td></tr>';
if (!$changes) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('VereineGroupsNone').'</span></td></tr>';
}
foreach ($changes as $change) {
	$value = $change['action'].':'.$change['user_id'].':'.$change['group_id'];
	print '<tr class="oddeven" data-group-change="'.dol_escape_htmltag($value).'"><td>';
	if (!empty($user->admin)) {
		print '<input type="checkbox" name="changes[]" value="'.dol_escape_htmltag($value).'" checked>';
	}
	print '</td><td>'.dol_escape_htmltag(isset($memberNames[$change['member_id']]) ? $memberNames[$change['member_id']] : '#'.$change['member_id']).'</td>';
	print '<td>'.dol_escape_htmltag(isset($memberUsers['logins'][$change['user_id']]) ? $memberUsers['logins'][$change['user_id']] : '#'.$change['user_id']).'</td>';
	print '<td>'.dol_escape_htmltag(isset($groupNames[$change['group_id']]) ? $groupNames[$change['group_id']] : '#'.$change['group_id']).'</td>';
	print '<td>'.$langs->trans('VereineGroupsChange_'.$change['action']).'</td></tr>';
}
print '</table></div>';
if ($changes) {
	print !empty($user->admin) ? '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('VereineGroupsApply')).'"></div>'
		: '<span class="opacitymedium">'.$langs->trans('VereineGroupsAdminOnly').'</span>';
}
print '</form>';

// Holders without Dolibarr user, and which rights come through which function.
$withoutUser = array();
$rights = array();
foreach ($terms as $term) {
	if ($term['member_status'] !== 1 || !isset($functionsById[$term['function_id']]) || !VereineFunctionRules::isActive($term, $today)) {
		continue;
	}
	$function = $functionsById[$term['function_id']];
	if (!isset($memberUsers['by_member'][$term['member_id']])) {
		$withoutUser[$term['member_id']] = $term['member_name'];
	} elseif ($function['group_id'] > 0) {
		$rights[] = array('group' => isset($groupNames[$function['group_id']]) ? $groupNames[$function['group_id']] : '#'.$function['group_id'],
			'function' => $function['label'], 'login' => $memberUsers['logins'][$memberUsers['by_member'][$term['member_id']]]);
	}
}
if ($withoutUser) {
	print '<br><div class="opacitymedium" data-without-user="'.count($withoutUser).'">'.$langs->trans('VereineGroupsWithoutUser').' ';
	$links = array();
	foreach ($withoutUser as $memberId => $name) {
		$links[] = '<a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.((int) $memberId).'" data-without-user-member="'.((int) $memberId).'">'.dol_escape_htmltag($name).'</a>';
	}
	print implode(', ', $links).'</div>';
}
print '<br><div class="div-table-responsive-no-min"><table class="noborder centpercent" data-rights="'.count($rights).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineFunctionGroup').'</td><td>'.$langs->trans('VereineFunctionLabel').'</td><td>'.$langs->trans('Login').'</td></tr>';
if (!$rights) {
	print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('VereineGroupsNoRights').'</span></td></tr>';
}
foreach ($rights as $right) {
	print '<tr class="oddeven" data-right="'.dol_escape_htmltag($right['login']).'"><td>'.dol_escape_htmltag($right['group']).'</td><td>'.dol_escape_htmltag($right['function']).'</td>';
	print '<td>'.dol_escape_htmltag($right['login']).'</td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
