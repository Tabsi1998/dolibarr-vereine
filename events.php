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
 * \file    events.php
 * \ingroup vereine
 * \brief   Events of the association (#23): from a template to a project of Dolibarr with its checklist.
 *
 * The list shows what is coming and how far each event has got. One event shows its checklist by phase
 * with the day of every point, the function that looks after it and where it comes from; every point
 * links to its task in Dolibarr's project, where the work itself is done.
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

require_once __DIR__.'/class/vereineevents.class.php';
require_once __DIR__.'/class/vereinefunctions.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('admin', 'projects', 'other', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read')) {
	accessforbidden();
}

$events = new VereineEvents($db);
$action = GETPOST('action', 'aZ09');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$mayManage = $events->mayManage($user, $today);
$id = GETPOSTINT('id');
$self = $_SERVER['PHP_SELF'].($id > 0 ? '?id='.$id : '');


/*
 * Actions
 */

if ($action === 'create' && $mayManage) {
	$entered = array('label' => GETPOST('label', 'alphanohtml'), 'event_day' => GETPOST('event_day', 'alphanohtml'),
		'end_day' => GETPOST('end_day', 'alphanohtml'), 'place' => GETPOST('place', 'alphanohtml'),
		'public' => GETPOST('public', 'aZ09') === '1', 'registration' => GETPOST('registration', 'aZ09'),
		'external_ref' => GETPOST('external_ref', 'alphanohtml'), 'note' => GETPOST('note', 'restricthtml'));
	$created = $events->createFromTemplate(GETPOSTINT('template'), $entered, $today, $user);
	if ($created > 0) {
		setEventMessages($langs->trans('VereineEventCreated'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$created);
		exit;
	}
	setEventMessages($created < 0 ? $events->error : null, $created < 0 ? null : array_map(array($langs, 'trans'), $events->errors), 'errors');
} elseif ($action === 'addtask' && $mayManage && $id > 0) {
	$entered = array('phase' => GETPOST('phase', 'aZ09'), 'label' => GETPOST('task_label', 'alphanohtml'),
		'function_code' => GETPOST('function_code', 'aZ09'), 'due_on' => GETPOST('due_on', 'alphanohtml'),
		'source' => GETPOST('source', 'alphanohtml'));
	$result = $events->addTask($id, $entered, $user);
	if ($result > 0) {
		header('Location: '.$self.'#vereineeventchecklist');
		exit;
	}
	setEventMessages($result < 0 ? $events->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $events->errors), 'errors');
} elseif (($action === 'done' || $action === 'undone') && $mayManage && $id > 0) {
	if ($events->markDone(GETPOSTINT('task'), $action === 'done' ? $today : '', $user) > 0) {
		header('Location: '.$self.'#vereineeventchecklist');
		exit;
	}
	setEventMessages($events->error, null, 'errors');
} elseif ($action === 'status' && $mayManage && $id > 0) {
	$result = $events->setStatus($id, GETPOST('status', 'aZ09'), $user);
	if ($result > 0) {
		header('Location: '.$self);
		exit;
	}
	setEventMessages($result < 0 ? $events->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $events->errors), 'errors');
}


/*
 * View
 */

$templates = $events->templates(true);
$functions = (new VereineFunctions($db))->fetchAll(true);
$functionLabels = array();
foreach ($functions as $function) {
	$functionLabels[$function['code']] = $function['label'];
}
$badges = array('overdue' => 'badge-status8', 'due' => 'badge-status1', 'ahead' => 'badge-status4', 'done' => 'badge-status6');
$event = $id > 0 ? $events->fetch($id) : null;

llxHeader('', $langs->trans('VereineEventTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-events');

if ($event === null) {
	print load_fiche_titre($langs->trans('VereineEventTitle'), '', 'fa-calendar-alt');
	print '<div class="info" data-event-howto="1"><ul>';
	print '<li>'.$langs->trans('VereineEventHowTo').'</li>';
	print '<li>'.$langs->trans('VereineEventHowToProject').'</li>';
	print '<li>'.$langs->trans('VereineEventHowToLaw').'</li>';
	print '</ul></div>';
	if (!isModEnabled('project')) {
		print '<div class="warning" data-event-noproject="1">'.$langs->trans('VereineEventNoProjectModule').'</div>';
	}

	$upcoming = $events->upcoming($today, 20);
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-event-list="'.count($upcoming).'">';
	print '<tr class="liste_titre"><td>'.$langs->trans('VereineEventDay').'</td><td>'.$langs->trans('VereineEventName').'</td>';
	print '<td>'.$langs->trans('VereineEventPlace').'</td><td>'.$langs->trans('VereineEventRegistration').'</td>';
	print '<td>'.$langs->trans('VereineEventProgress').'</td><td></td></tr>';
	foreach ($upcoming as $row) {
		print '<tr class="oddeven" data-event-row="'.((int) $row['id']).'" data-event-done="'.((int) $row['progress']['done']).'"';
		print ' data-event-total="'.((int) $row['progress']['total']).'" data-event-overdue="'.((int) $row['progress']['overdue']).'">';
		print '<td class="nowraponall">'.vereineFormatDay($row['event_day']).'</td>';
		print '<td><a href="'.$_SERVER['PHP_SELF'].'?id='.((int) $row['id']).'">'.dol_escape_htmltag($row['label']).'</a></td>';
		print '<td>'.dol_escape_htmltag($row['place']).'</td>';
		print '<td class="nowraponall">'.$langs->trans('VereineEventRegistration_'.$row['registration']).'</td>';
		print '<td class="nowraponall">'.$langs->trans('VereineEventProgressValue', $row['progress']['done'], $row['progress']['total']);
		if ($row['progress']['overdue'] > 0) {
			print ' <span class="badge badge-status badge-status8">'.$langs->trans('VereineEventOverdueValue', $row['progress']['overdue']).'</span>';
		}
		print '</td><td class="right">';
		if ($row['project_id'] > 0 && isModEnabled('project')) {
			print '<a href="'.dol_buildpath('/projet/card.php', 1).'?id='.((int) $row['project_id']).'">'.$langs->trans('Project').'</a>';
		}
		print '</td></tr>';
	}
	if (!$upcoming) {
		print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium" data-event-none="1">'.$langs->trans('VereineEventNone').'</span></td></tr>';
	}
	print '</table></div>';

	// A new event out of a template.
	if ($mayManage) {
		print load_fiche_titre($langs->trans('VereineEventAddTitle'), '', '', 0, 'vereineeventform');
		print '<form method="POST" name="vereineevent" action="'.$_SERVER['PHP_SELF'].'#vereineeventform">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="create">';
		print '<table class="border centpercent">';
		print '<tr><td class="titlefield fieldrequired">'.$langs->trans('VereineEventTemplate').'</td><td><select name="template" class="flat">';
		print '<option value="0">'.$langs->trans('VereineEventNoTemplate').'</option>';
		foreach ($templates as $template) {
			print '<option value="'.((int) $template['id']).'">'.dol_escape_htmltag($template['label']).'</option>';
		}
		print '</select> <a href="'.dol_buildpath('/vereine/admin/events.php', 1).'">'.$langs->trans('VereineEventTemplates').'</a></td></tr>';
		print '<tr><td class="fieldrequired">'.$langs->trans('VereineEventName').'</td><td><input type="text" name="label" size="60" maxlength="255" value=""></td></tr>';
		print '<tr><td class="fieldrequired">'.$langs->trans('VereineEventDay').'</td><td><input type="date" name="event_day" value="">';
		print ' '.$langs->trans('VereineEventEndDay').' <input type="date" name="end_day" value=""></td></tr>';
		print '<tr><td>'.$langs->trans('VereineEventPlace').'</td><td><input type="text" name="place" size="40" maxlength="255" value=""></td></tr>';
		print '<tr><td>'.$langs->trans('VereineEventPublic').'</td><td><select name="public" class="flat">';
		print '<option value="0">'.$langs->trans('VereineEventInternal').'</option><option value="1">'.$langs->trans('VereineEventPublicYes').'</option>';
		print '</select></td></tr>';
		print '<tr><td>'.$langs->trans('VereineEventRegistration').'</td><td><select name="registration" class="flat">';
		foreach (VereineEventRules::REGISTRATIONS as $kind) {
			print '<option value="'.$kind.'">'.$langs->trans('VereineEventRegistration_'.$kind).'</option>';
		}
		print '</select> '.$langs->trans('VereineEventExternalRef').' <input type="text" name="external_ref" size="20" maxlength="64" value="">';
		print '<br><span class="opacitymedium small">'.$langs->trans('VereineEventRegistrationHint').'</span></td></tr>';
		print '<tr><td>'.$langs->trans('Note').'</td><td><textarea name="note" rows="3" class="quatrevingtpercent"></textarea></td></tr>';
		print '</table>';
		print '<div class="center paddingtop"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineEventCreate')).'"></div></form>';
	}
} else {
	$checklist = $events->checklist($id, $today);
	$progress = VereineEventRules::progress($checklist, $today);
	print load_fiche_titre(dol_escape_htmltag($event['label']), '<a href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('VereineEventBack').'</a>', 'fa-calendar-alt');
	print '<div class="paddingbottom" data-event="'.((int) $event['id']).'" data-event-status="'.$event['status'].'"';
	print ' data-event-registration="'.$event['registration'].'" data-event-public="'.($event['public'] ? 1 : 0).'">';
	print '<strong>'.vereineFormatDay($event['event_day']).($event['end_day'] !== '' ? ' – '.vereineFormatDay($event['end_day']) : '').'</strong>';
	if ($event['place'] !== '') {
		print ' · '.dol_escape_htmltag($event['place']);
	}
	print ' · '.$langs->trans('VereineEventRegistration_'.$event['registration']);
	if ($event['external_ref'] !== '') {
		print ' ('.dol_escape_htmltag($event['external_ref']).')';
	}
	if ($event['project_id'] > 0 && isModEnabled('project')) {
		print ' · <a href="'.dol_buildpath('/projet/card.php', 1).'?id='.((int) $event['project_id']).'">'.$langs->trans('Project').'</a>';
	}
	print '</div>';
	print '<div class="paddingbottom" data-event-progress="'.((int) $progress['percent']).'">';
	print $langs->trans('VereineEventProgressValue', $progress['done'], $progress['total']);
	if ($progress['overdue'] > 0) {
		print ' <span class="badge badge-status badge-status8">'.$langs->trans('VereineEventOverdueValue', $progress['overdue']).'</span>';
	}
	print '</div>';

	print load_fiche_titre($langs->trans('VereineEventChecklistTitle'), '', '', 0, 'vereineeventchecklist');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-event-checklist="'.count($checklist).'">';
	print '<tr class="liste_titre"><td>'.$langs->trans('VereineEventPhase').'</td><td>'.$langs->trans('VereineEventDue').'</td>';
	print '<td>'.$langs->trans('VereineEventPoint').'</td><td>'.$langs->trans('VereineEventResponsible').'</td>';
	print '<td>'.$langs->trans('VereineEventSource').'</td><td>'.$langs->trans('Status').'</td><td></td></tr>';
	foreach ($checklist as $row) {
		print '<tr class="oddeven" data-event-task="'.((int) $row['id']).'" data-event-phase="'.$row['phase'].'" data-event-state="'.$row['state'].'">';
		print '<td class="nowraponall">'.$langs->trans('VereineEventPhase_'.$row['phase']).'</td>';
		print '<td class="nowraponall">'.($row['due_on'] !== '' ? vereineFormatDay($row['due_on']) : '').'</td>';
		print '<td>'.dol_escape_htmltag($row['label']);
		if ($row['task_id'] > 0 && isModEnabled('project')) {
			print ' <a class="small" href="'.dol_buildpath('/projet/tasks/task.php', 1).'?id='.((int) $row['task_id']).'">'.$langs->trans('Task').'</a>';
		}
		print '</td>';
		print '<td>'.dol_escape_htmltag(isset($functionLabels[$row['function_code']]) ? $functionLabels[$row['function_code']] : $row['function_code']).'</td>';
		print '<td class="opacitymedium small nowraponall">'.dol_escape_htmltag($row['source']).'</td>';
		print '<td><span class="badge badge-status '.$badges[$row['state']].'">'.$langs->trans('VereineEventState_'.$row['state']).'</span></td>';
		print '<td class="right nowraponall">';
		if ($mayManage) {
			$done = $row['done_on'] !== '';
			print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="'.($done ? 'undone' : 'done').'"><input type="hidden" name="task" value="'.((int) $row['id']).'">';
			print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans($done ? 'VereineEventUndone' : 'VereineEventDone')).'"></form>';
		}
		print '</td></tr>';
	}
	if (!$checklist) {
		print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium" data-event-checklist-none="1">'.$langs->trans('VereineEventChecklistEmpty').'</span></td></tr>';
	}
	print '</table></div>';

	if ($mayManage) {
		print '<form method="POST" name="vereineeventtask" action="'.$self.'#vereineeventchecklist">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="addtask">';
		print '<table class="border centpercent"><tr><td class="titlefield">'.$langs->trans('VereineEventAddPoint').'</td><td>';
		print '<select name="phase" class="flat">';
		foreach (VereineEventRules::PHASES as $phase) {
			print '<option value="'.$phase.'">'.$langs->trans('VereineEventPhase_'.$phase).'</option>';
		}
		print '</select> <input type="text" name="task_label" size="40" maxlength="255" value="" placeholder="'.dol_escape_htmltag($langs->trans('VereineEventPoint')).'">';
		print ' <select name="function_code" class="flat"><option value="">&nbsp;</option>';
		foreach ($functions as $function) {
			print '<option value="'.dol_escape_htmltag($function['code']).'">'.dol_escape_htmltag($function['label']).'</option>';
		}
		print '</select> <input type="date" name="due_on" value="">';
		print ' <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('Add')).'">';
		print '</td></tr></table></form>';

		print '<div class="tabsAction"><form method="POST" name="vereineeventstatus" action="'.$self.'">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="status">';
		print '<select name="status" class="flat">';
		foreach (VereineEventRules::STATUSES as $status) {
			print '<option value="'.$status.'"'.($event['status'] === $status ? ' selected' : '').'>'.$langs->trans('VereineEventStatus_'.$status).'</option>';
		}
		print '</select> <input type="submit" class="butAction" value="'.dol_escape_htmltag($langs->trans('Save')).'"></form></div>';
	}
}

llxFooter();
$db->close();
