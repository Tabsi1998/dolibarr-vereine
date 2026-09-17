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
 * \file    resolutions.php
 * \ingroup vereine
 * \brief   The register of resolutions: search what was resolved, keep its wording and follow what comes of it.
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

require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
require_once __DIR__.'/class/vereineresolutions.class.php';
require_once __DIR__.'/class/vereinemeetings.class.php';
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

$register = new VereineResolutions($db);
$meetings = new VereineMeetings($db);
$canWrite = $user->hasRight('adherent', 'creer');
$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$filters = VereineResolutionRules::filters(array(
	'search' => GETPOST('search', 'alphanohtml'),
	'year' => GETPOST('year', 'alphanohtml'),
	'organ' => GETPOST('organ', 'aZ09'),
	'category' => GETPOST('category', 'aZ09'),
	'result' => GETPOST('result', 'aZ09'),
	'open' => GETPOSTISSET('open'),
));


/**
 * The names of the active members, for the responsible person of a task.
 *
 * @param DoliDB $db Database handler
 * @return array<int,string> Name by member
 */
function vereineResolutionMembers($db)
{
	global $conf;

	$names = array();
	$sql = "SELECT rowid, firstname, lastname, societe FROM ".MAIN_DB_PREFIX."adherent WHERE entity IN (".getEntity('adherent').")";
	$sql .= " AND statut = 1 ORDER BY lastname, firstname";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$name = trim((string) $obj->firstname.' '.(string) $obj->lastname);
		$names[(int) $obj->rowid] = $name !== '' ? $name : (string) $obj->societe;
	}
	return $names;
}

/**
 * The register as a CSV file, with what the filters let through.
 *
 * @param array<int,array<string,mixed>> $rows Resolutions
 * @return void
 */
function vereineResolutionCsv(array $rows)
{
	global $langs;

	$columns = array('VereineResolutionRef', 'VereineMeetingWhen', 'VereineResolutionOrgan', 'VereineResolutionTitleColumn', 'VereineResolutionCategory',
		'VereineResolutionResult', 'VereineVoteYes', 'VereineVoteNo', 'VereineVoteAbstain', 'VereineResolutionValidFrom', 'VereineResolutionValidTo',
		'VereineResolutionTasksOpen', 'VereineResolutionWording');
	$line = function (array $values) {
		$cells = array();
		foreach ($values as $value) {
			$cells[] = '"'.str_replace('"', '""', str_replace(array("\r\n", "\r", "\n"), ' ', (string) $value)).'"';
		}
		return implode(';', $cells)."\r\n";
	};
	$out = "\xEF\xBB\xBF";
	$titles = array();
	foreach ($columns as $column) {
		$titles[] = $langs->transnoentitiesnoconv($column);
	}
	$out .= $line($titles);
	foreach ($rows as $row) {
		$out .= $line(array($row['ref'], $row['day'], $langs->transnoentitiesnoconv('VereineMeetingKind_'.$row['organ']), $row['title'],
			$langs->transnoentitiesnoconv('VereineResolutionCategory_'.$row['category']),
			$langs->transnoentitiesnoconv($row['passed'] ? 'VereineResolutionPassed' : 'VereineResolutionRejected'),
			$row['yes'], $row['no'], $row['abstain'], $row['valid_from'], $row['valid_to'], $row['tasks_open'], $row['wording']));
	}
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="beschlussbuch.csv"');
	header('Content-Length: '.strlen($out));
	print $out;
}


/*
 * Actions
 */

if ($action === 'export') {
	vereineResolutionCsv($register->fetchAll($filters));
	exit;
} elseif ($action === 'save' && $canWrite) {
	$entered = array();
	foreach (array('category', 'valid_from', 'valid_to', 'member_id', 'invoice_id') as $key) {
		$entered[$key] = GETPOST($key, 'alphanohtml');
	}
	foreach (array('wording', 'note') as $key) {
		$entered[$key] = GETPOST($key, 'restricthtml');
	}
	$result = $register->save($id, $entered, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineResolutionSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
		exit;
	}
	setEventMessages($result < 0 ? $register->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $register->errors), 'errors');
} elseif ($action === 'addtask' && $canWrite) {
	$entered = array('label' => GETPOST('label', 'alphanohtml'), 'member_id' => GETPOST('member_id', 'alphanohtml'), 'deadline' => GETPOST('deadline', 'alphanohtml'));
	$result = $register->addTask($id, $entered, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineResolutionTaskSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id.'#vereineresolutiontasks');
		exit;
	}
	setEventMessages($result < 0 ? $register->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $register->errors), 'errors');
} elseif ($action === 'taskdone' && $canWrite) {
	$result = $register->taskDone(GETPOSTINT('task'), $user);
	if ($result > 0) {
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id.'#vereineresolutiontasks');
		exit;
	}
	setEventMessages($result < 0 ? $register->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $register->errors), 'errors');
}


/*
 * View
 */

llxHeader('', $langs->trans('VereineResolutionsTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-resolutions');

$row = $id > 0 ? $register->fetch($id) : null;
if ($id > 0 && $row === null) {
	setEventMessages($langs->trans('VereineResolutionErrorUnknown'), null, 'errors');
}

if ($row === null) {
	print load_fiche_titre($langs->trans('VereineResolutionsTitle'), '', 'fa-gavel');
	print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineResolutionsHowTo').'</div>';

	print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="vereineresolutionfilter">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<div class="paddingbottom">';
	print '<input type="search" name="search" class="minwidth200" value="'.dol_escape_htmltag($filters['search']).'" placeholder="'.dol_escape_htmltag($langs->trans('VereineResolutionSearch')).'"> ';
	print '<input type="text" name="year" class="width50" maxlength="4" value="'.dol_escape_htmltag($filters['year']).'" placeholder="'.dol_escape_htmltag($langs->trans('Year')).'"> ';
	print '<select name="organ"><option value="">'.$langs->trans('VereineResolutionOrgan').'</option>';
	foreach (VereineMeetingRules::KINDS as $kind) {
		print '<option value="'.$kind.'"'.($filters['organ'] === $kind ? ' selected' : '').'>'.$langs->trans('VereineMeetingKind_'.$kind).'</option>';
	}
	print '</select> <select name="category"><option value="">'.$langs->trans('VereineResolutionCategory').'</option>';
	foreach (VereineResolutionRules::CATEGORIES as $category) {
		print '<option value="'.$category.'"'.($filters['category'] === $category ? ' selected' : '').'>'.$langs->trans('VereineResolutionCategory_'.$category).'</option>';
	}
	print '</select> <select name="result"><option value="">'.$langs->trans('VereineResolutionResult').'</option>';
	foreach (array('passed' => 'VereineResolutionPassed', 'rejected' => 'VereineResolutionRejected') as $value => $label) {
		print '<option value="'.$value.'"'.($filters['result'] === $value ? ' selected' : '').'>'.$langs->trans($label).'</option>';
	}
	print '</select> <label><input type="checkbox" name="open" value="1"'.($filters['open'] ? ' checked' : '').'> '.$langs->trans('VereineResolutionOnlyOpen').'</label> ';
	print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Search')).'"> ';
	print '<a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Reset').'</a>';
	print '</div></form>';

	$rows = $register->fetchAll($filters);
	$query = array('action=export', 'token='.newToken());
	foreach (array('search', 'year', 'organ', 'category', 'result') as $key) {
		if ($filters[$key] !== '') {
			$query[] = $key.'='.urlencode($filters[$key]);
		}
	}
	if ($filters['open']) {
		$query[] = 'open=1';
	}
	print '<div class="paddingbottom" data-resolutions="'.count($rows).'">';
	print '<a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?'.implode('&amp;', $query).'">'.$langs->trans('VereineResolutionExport').'</a>';
	print '</div>';

	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('VereineResolutionRef').'</td><td>'.$langs->trans('VereineMeetingWhen').'</td>';
	print '<td>'.$langs->trans('VereineResolutionOrgan').'</td><td>'.$langs->trans('VereineResolutionTitleColumn').'</td>';
	print '<td>'.$langs->trans('VereineResolutionCategory').'</td><td>'.$langs->trans('VereineResolutionResult').'</td>';
	print '<td>'.$langs->trans('VereineResolutionValidity').'</td><td>'.$langs->trans('VereineResolutionTasks').'</td></tr>';
	if (!$rows) {
		print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">'.$langs->trans('VereineResolutionsNone').'</span></td></tr>';
	}
	foreach ($rows as $entry) {
		print '<tr class="oddeven" data-resolution="'.$entry['id'].'" data-category="'.$entry['category'].'" data-passed="'.($entry['passed'] ? 1 : 0).'"';
		print ' data-open="'.$entry['tasks_open'].'">';
		print '<td><a href="'.$_SERVER['PHP_SELF'].'?id='.$entry['id'].'">'.dol_escape_htmltag($entry['ref']).'</a></td>';
		print '<td>'.vereineFormatDay($entry['day']).'</td><td>'.$langs->trans('VereineMeetingKind_'.$entry['organ']).'</td>';
		print '<td>'.dol_escape_htmltag($entry['title']).'</td>';
		print '<td>'.$langs->trans('VereineResolutionCategory_'.$entry['category']).'</td>';
		print '<td>'.dolGetBadge($langs->trans($entry['passed'] ? 'VereineResolutionPassed' : 'VereineResolutionRejected'), '', $entry['passed'] ? 'success' : 'secondary').'</td>';
		print '<td>'.($entry['valid_from'] !== '' ? vereineFormatDay($entry['valid_from']) : '').($entry['valid_to'] !== '' ? ' - '.vereineFormatDay($entry['valid_to']) : '').'</td>';
		print '<td>'.($entry['tasks'] > 0 ? $langs->trans('VereineResolutionTasksCount', $entry['tasks_open'], $entry['tasks']) : '').'</td></tr>';
	}
	print '</table></div>';

	llxFooter();
	$db->close();
	exit;
}

// One resolution.
print load_fiche_titre(dol_escape_htmltag($row['ref'].' '.$row['title']), '<a href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('BackToList').'</a>', 'fa-gavel');
$meeting = $row['meeting_id'] > 0 ? $meetings->fetch($row['meeting_id']) : null;
$names = vereineResolutionMembers($db);
print '<table class="border centpercent" data-resolution-card="'.$row['id'].'" data-kind="'.$row['kind'].'" data-passed="'.($row['passed'] ? 1 : 0).'">';
print '<tr><td class="titlefield">'.$langs->trans('VereineResolutionRef').'</td><td>'.dol_escape_htmltag($row['ref']).'</td></tr>';
print '<tr><td>'.$langs->trans('VereineMeetingWhen').'</td><td>'.vereineFormatDay($row['day']).'</td></tr>';
print '<tr><td>'.$langs->trans('VereineResolutionOrgan').'</td><td>'.$langs->trans('VereineMeetingKind_'.$row['organ']);
if ($meeting !== null) {
	print ' - <a href="'.dol_buildpath('/vereine/meetings.php', 1).'?id='.$meeting['id'].'">'.dol_escape_htmltag($meeting['title']).'</a>';
	if ($row['item'] > 0) {
		print ' ('.$langs->trans('VereineVoteItem').' '.$row['item'].')';
	}
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('VereineVoteKind').'</td><td>'.$langs->trans('VereineVoteKind_'.$row['kind']).'</td></tr>';
print '<tr><td>'.$langs->trans('VereineResolutionResult').'</td><td>';
print dolGetBadge($langs->trans($row['passed'] ? 'VereineResolutionPassed' : 'VereineResolutionRejected'), '', $row['passed'] ? 'success' : 'secondary');
print ' '.$langs->trans('VereineVoteYes').' '.$row['yes'].', '.$langs->trans('VereineVoteNo').' '.$row['no'].', '.$langs->trans('VereineVoteAbstain').' '.$row['abstain'];
print ' <span class="opacitymedium small">'.$langs->trans('VereineStatuteMajority_'.$row['majority']).'</span></td></tr>';
if ($row['applied'] !== '') {
	print '<tr><td>'.$langs->trans('VereineResolutionApplied').'</td><td><span class="opacitymedium">'.dol_escape_htmltag($row['applied']).'</span></td></tr>';
}
print '</table>';

print '<br>'.load_fiche_titre($langs->trans('VereineResolutionRegisterTitle'), '', '', 0, 'vereineresolutionentry');
print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineResolutionRegisterHowTo').'</div>';
if ($canWrite) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$row['id'].'" name="vereineresolution">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="save">';
}
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate tdtop">'.$langs->trans('VereineResolutionWording').'</td><td>';
if ($canWrite) {
	print '<textarea name="wording" rows="4" class="centpercent">'.dol_escape_htmltag($row['wording'], 0, 1).'</textarea>';
	print '<div class="opacitymedium small">'.$langs->trans('VereineResolutionWordingHelp').'</div>';
} else {
	print $row['wording'] !== '' ? nl2br(dol_escape_htmltag($row['wording'], 0, 1)) : '<span class="opacitymedium">'.$langs->trans('VereineResolutionNoWording').'</span>';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('VereineResolutionCategory').'</td><td>';
if ($canWrite) {
	print '<select name="category">';
	foreach (VereineResolutionRules::CATEGORIES as $category) {
		print '<option value="'.$category.'"'.($row['category'] === $category ? ' selected' : '').'>'.$langs->trans('VereineResolutionCategory_'.$category).'</option>';
	}
	print '</select>';
} else {
	print $langs->trans('VereineResolutionCategory_'.$row['category']);
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('VereineResolutionValidity').'</td><td>';
if ($canWrite) {
	print '<input type="date" name="valid_from" value="'.dol_escape_htmltag($row['valid_from']).'"> - ';
	print '<input type="date" name="valid_to" value="'.dol_escape_htmltag($row['valid_to']).'">';
	print ' <span class="opacitymedium small">'.$langs->trans('VereineResolutionValidityHelp').'</span>';
} else {
	print ($row['valid_from'] !== '' ? vereineFormatDay($row['valid_from']) : vereineFormatDay($row['day'])).($row['valid_to'] !== '' ? ' - '.vereineFormatDay($row['valid_to']) : '');
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('VereineResolutionMember').'</td><td>';
if ($canWrite) {
	print '<select name="member_id"><option value="0"></option>';
	foreach ($names as $memberId => $name) {
		print '<option value="'.$memberId.'"'.($row['member_id'] === $memberId ? ' selected' : '').'>'.dol_escape_htmltag($name).'</option>';
	}
	print '</select>';
} elseif ($row['member_id'] > 0) {
	print dol_escape_htmltag(isset($names[$row['member_id']]) ? $names[$row['member_id']] : (string) $row['member_id']);
}
print ' <span class="opacitymedium small">'.$langs->trans('VereineResolutionMemberHelp').'</span></td></tr>';
print '<tr><td>'.$langs->trans('VereineResolutionInvoice').'</td><td>';
if ($canWrite) {
	print '<input type="number" min="0" name="invoice_id" class="width100" value="'.((int) $row['invoice_id']).'">';
	print ' <span class="opacitymedium small">'.$langs->trans('VereineResolutionInvoiceHelp').'</span>';
} elseif ($row['invoice_id'] > 0) {
	print '<a href="'.dol_buildpath('/compta/facture/card.php', 1).'?facid='.$row['invoice_id'].'">'.$row['invoice_id'].'</a>';
}
print '</td></tr>';
print '<tr><td class="tdtop">'.$langs->trans('VereineResolutionNote').'</td><td>';
if ($canWrite) {
	print '<textarea name="note" rows="2" class="centpercent">'.dol_escape_htmltag($row['note'], 0, 1).'</textarea>';
} else {
	print nl2br(dol_escape_htmltag($row['note'], 0, 1));
}
print '</td></tr></table>';
if ($canWrite) {
	print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div></form>';
}

print '<br>'.load_fiche_titre($langs->trans('VereineResolutionTasks'), '', '', 0, 'vereineresolutiontasks');
print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineResolutionTasksHowTo').'</div>';
$tasks = $register->tasks($row['id']);
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineResolutionTaskLabel').'</td><td>'.$langs->trans('VereineResolutionTaskMember').'</td>';
print '<td>'.$langs->trans('VereineResolutionTaskDeadline').'</td><td>'.$langs->trans('Status').'</td><td></td></tr>';
if (!$tasks) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('VereineResolutionTasksNone').'</span></td></tr>';
}
foreach ($tasks as $task) {
	print '<tr class="oddeven" data-task="'.$task['id'].'" data-done="'.($task['done'] !== '' ? 1 : 0).'" data-event="'.$task['event_id'].'">';
	print '<td>'.dol_escape_htmltag($task['label']).'</td>';
	print '<td>'.dol_escape_htmltag(isset($names[$task['member_id']]) ? $names[$task['member_id']] : (string) $task['member_id']).'</td>';
	print '<td>'.($task['deadline'] !== '' ? vereineFormatDay($task['deadline']) : '').'</td>';
	print '<td>'.dolGetBadge($langs->trans($task['done'] !== '' ? 'VereineResolutionTaskDone' : 'VereineResolutionTaskOpen'), '', $task['done'] !== '' ? 'success' : 'info').'</td>';
	print '<td class="right">';
	if ($canWrite && $task['done'] === '') {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$row['id'].'" name="vereineresolutiontaskdone'.$task['id'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="taskdone">';
		print '<input type="hidden" name="task" value="'.$task['id'].'">';
		print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('VereineResolutionTaskMarkDone')).'">';
		print '</form>';
	}
	print '</td></tr>';
}
print '</table></div>';
if ($canWrite) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$row['id'].'" name="vereineresolutiontask">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="addtask">';
	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('VereineResolutionTaskLabel').'</td>';
	print '<td><input type="text" name="label" class="minwidth300" maxlength="255" value=""></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('VereineResolutionTaskMember').'</td><td><select name="member_id"><option value="0"></option>';
	foreach ($names as $memberId => $name) {
		print '<option value="'.$memberId.'">'.dol_escape_htmltag($name).'</option>';
	}
	print '</select></td></tr>';
	print '<tr><td>'.$langs->trans('VereineResolutionTaskDeadline').'</td><td><input type="date" name="deadline" value="">';
	print ' <span class="opacitymedium small">'.$langs->trans('VereineResolutionTaskDeadlineHelp').'</span></td></tr>';
	print '</table><div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineResolutionTaskSave')).'"></div></form>';
}

llxFooter();
$db->close();
