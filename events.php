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
require_once __DIR__.'/class/vereineshifts.class.php';
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
$shifts = new VereineShifts($db);
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
		'public' => VereineEventRules::storedVisibility(GETPOST('public', 'aZ09')), 'registration' => GETPOST('registration', 'aZ09'),
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
} elseif ($action === 'saveshift' && $mayManage && $id > 0) {
	$entered = array('label' => GETPOST('shift_label', 'alphanohtml'), 'shift_day' => GETPOST('shift_day', 'alphanohtml'),
		'start_time' => GETPOST('start_time', 'alphanohtml'), 'end_time' => GETPOST('end_time', 'alphanohtml'),
		'capacity' => GETPOST('capacity', 'alphanohtml'), 'function_code' => GETPOST('shift_function', 'aZ09'),
		'note' => GETPOST('shift_note', 'alphanohtml'));
	$result = $shifts->save(GETPOSTINT('shift'), $id, $entered, $user);
	if ($result > 0) {
		header('Location: '.$self.'#vereineeventshifts');
		exit;
	}
	setEventMessages($result < 0 ? $shifts->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $shifts->errors), 'errors');
} elseif ($action === 'removeshift' && $mayManage && $id > 0) {
	if ($shifts->remove(GETPOSTINT('shift'), $user) > 0) {
		header('Location: '.$self.'#vereineeventshifts');
		exit;
	}
	setEventMessages($shifts->error, null, 'errors');
} elseif ($action === 'signup' && $id > 0) {
	// Whoever plans may put anybody on a shift; everyone else only asks for themselves.
	$member = $mayManage ? GETPOSTINT('member') : (int) $user->fk_member;
	$status = $mayManage ? VereineShiftRules::STATUS_CONFIRMED : VereineShiftRules::STATUS_REQUESTED;
	$result = $member > 0 ? $shifts->signUp(GETPOSTINT('shift'), $member, $status, 'dolibarr', $user) : 0;
	if ($result > 0) {
		setEventMessages($langs->trans($mayManage ? 'VereineShiftTaken' : 'VereineShiftRequested'), null, 'mesgs');
		header('Location: '.$self.'#vereineeventshifts');
		exit;
	}
	setEventMessages($result < 0 ? $shifts->error : null, $result < 0 ? null
		: array_map(array($langs, 'trans'), $shifts->errors ? $shifts->errors : array('VereineShiftErrorNoMember')), 'errors');
} elseif ($action === 'entry' && $mayManage && $id > 0) {
	$result = $shifts->setStatus(GETPOSTINT('entry'), GETPOST('status', 'aZ09'), GETPOST('hours', 'alphanohtml'), $user);
	if ($result > 0) {
		header('Location: '.$self.'#vereineeventshifts');
		exit;
	}
	setEventMessages($result < 0 ? $shifts->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $shifts->errors), 'errors');
} elseif ($action === 'report' && $id > 0) {
	$file = $mayManage ? $events->buildReport($id, $today, $user, $langs) : '';
	if ($file !== '') {
		setEventMessages($langs->trans('VereineEventReportBuilt'), null, 'mesgs');
		header('Location: '.$self.'#vereineeventreport');
		exit;
	}
	setEventMessages($events->error, null, 'errors');
} elseif ($action === 'reportpdf' && $id > 0) {
	$file = VereineEvents::reportPath($id);
	if (!is_file($file)) {
		accessforbidden();
	}
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
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
		print '<option value="0">'.$langs->trans('VereineEventInternal').'</option><option value="2">'.$langs->trans('VereineEventMembersOnly').'</option>';
		print '<option value="1">'.$langs->trans('VereineEventPublicYes').'</option>';
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
	print ' data-event-registration="'.$event['registration'].'" data-event-public="'.($event['public'] ? 1 : 0).'" data-event-visibility="'.$event['visibility'].'">';
	print '<strong>'.vereineFormatDay($event['event_day']).($event['end_day'] !== '' ? ' – '.vereineFormatDay($event['end_day']) : '').'</strong>';
	if ($event['place'] !== '') {
		print ' · '.dol_escape_htmltag($event['place']);
	}
	$seen = array('internal' => 'VereineEventInternal', 'public' => 'VereineEventPublicYes', 'members' => 'VereineEventMembersOnly');
	print ' · '.$langs->trans($seen[$event['visibility']]).' · '.$langs->trans('VereineEventRegistration_'.$event['registration']);
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
	}

	// Who helps, when, and who really was there.
	$plan = $shifts->forEvent($id);
	$helpers = $shifts->summary($id);
	print load_fiche_titre($langs->trans('VereineShiftTitle'), '', 'fa-hands-helping', 0, 'vereineeventshifts');
	print '<div class="paddingbottom" data-shift-summary="'.((int) $helpers['taken']).'/'.((int) $helpers['capacity']).'"';
	print ' data-shift-hours="'.((float) $helpers['hours']).'">'.$langs->trans('VereineShiftSummary', $helpers['taken'], $helpers['capacity'],
		$helpers['requested']).'</div>';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-shifts="'.count($plan).'">';
	print '<tr class="liste_titre"><td>'.$langs->trans('VereineShiftWhen').'</td><td>'.$langs->trans('VereineShiftName').'</td>';
	print '<td>'.$langs->trans('VereineShiftPlaces').'</td><td>'.$langs->trans('VereineShiftPeople').'</td><td></td></tr>';
	foreach ($plan as $shift) {
		print '<tr class="oddeven" data-shift="'.((int) $shift['id']).'" data-shift-free="'.((int) $shift['places']['free']).'"';
		print ' data-shift-taken="'.((int) $shift['places']['taken']).'">';
		print '<td class="nowraponall">'.vereineFormatDay($shift['shift_day']);
		if ($shift['start_time'] !== '') {
			print '<br><span class="opacitymedium small">'.dol_escape_htmltag($shift['start_time'].($shift['end_time'] !== '' ? '–'.$shift['end_time'] : '')).'</span>';
		}
		print '</td><td>'.dol_escape_htmltag($shift['label']);
		if ($shift['function_code'] !== '') {
			print '<br><span class="opacitymedium small">'.dol_escape_htmltag(isset($functionLabels[$shift['function_code']])
				? $functionLabels[$shift['function_code']] : $shift['function_code']).'</span>';
		}
		print '</td>';
		print '<td class="nowraponall">'.$langs->trans('VereineShiftPlacesValue', $shift['places']['taken'], (int) $shift['capacity']);
		if ($shift['places']['requested'] > 0) {
			print ' <span class="badge badge-status badge-status1">'.$langs->trans('VereineShiftRequestedValue', $shift['places']['requested']).'</span>';
		}
		print '</td><td>';
		foreach ($shift['entries'] as $entry) {
			print '<div data-shift-entry="'.((int) $entry['id']).'" data-shift-status="'.$entry['status'].'">';
			print dol_escape_htmltag($entry['name']).' <span class="badge badge-status '.($entry['status'] === VereineShiftRules::STATUS_DONE
				? 'badge-status6' : ($entry['status'] === VereineShiftRules::STATUS_CONFIRMED ? 'badge-status4' : 'badge-status1')).'">';
			print $langs->trans('VereineShiftStatus_'.$entry['status']).'</span>';
			if ($entry['status'] === VereineShiftRules::STATUS_DONE && $entry['hours'] > 0) {
				print ' <span class="opacitymedium small">'.$langs->trans('VereineShiftHoursValue', price($entry['hours'], 0, $langs, 1, -1, 2)).'</span>';
			}
			if ($mayManage) {
				foreach (array(VereineShiftRules::STATUS_CONFIRMED, VereineShiftRules::STATUS_DONE, VereineShiftRules::STATUS_CANCELLED) as $next) {
					if ($next === $entry['status']) {
						continue;
					}
					print ' <form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
					print '<input type="hidden" name="action" value="entry"><input type="hidden" name="entry" value="'.((int) $entry['id']).'">';
					print '<input type="hidden" name="status" value="'.$next.'">';
					print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineShiftTo_'.$next)).'"></form>';
				}
			}
			print '</div>';
		}
		if (!$shift['entries']) {
			print '<span class="opacitymedium" data-shift-empty="1">'.$langs->trans('VereineShiftNobody').'</span>';
		}
		print '</td><td class="right nowraponall">';
		if (!$mayManage && (int) $user->fk_member > 0 && !$shift['places']['full']) {
			print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="signup"><input type="hidden" name="shift" value="'.((int) $shift['id']).'">';
			print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineShiftSignUp')).'"></form>';
		}
		if ($mayManage) {
			print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="removeshift"><input type="hidden" name="shift" value="'.((int) $shift['id']).'">';
			print '<input type="submit" class="button small butActionDelete" value="'.dol_escape_htmltag($langs->trans('Delete')).'"></form>';
		}
		print '</td></tr>';
	}
	if (!$plan) {
		print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium" data-shifts-none="1">'.$langs->trans('VereineShiftNone').'</span></td></tr>';
	}
	print '</table></div>';

	if ($mayManage) {
		$members = array();
		$resql = $db->query("SELECT rowid, firstname, lastname FROM ".MAIN_DB_PREFIX."adherent WHERE entity = ".((int) $conf->entity)
			." AND statut = 1 ORDER BY lastname, firstname");
		while ($resql && ($obj = $db->fetch_object($resql))) {
			$members[(int) $obj->rowid] = trim($obj->firstname.' '.$obj->lastname);
		}
		print '<form method="POST" name="vereineshift" action="'.$self.'#vereineeventshifts">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="saveshift">';
		print '<table class="border centpercent"><tr><td class="titlefield">'.$langs->trans('VereineShiftAdd').'</td><td>';
		print '<input type="text" name="shift_label" size="30" maxlength="255" value="" placeholder="'.dol_escape_htmltag($langs->trans('VereineShiftName')).'">';
		print ' <input type="date" name="shift_day" value="'.dol_escape_htmltag($event['event_day']).'">';
		print ' <input type="time" name="start_time" value=""> <input type="time" name="end_time" value="">';
		print ' <input type="number" name="capacity" min="1" max="999" size="3" value="2"> '.$langs->trans('VereineShiftCapacity');
		print ' <select name="shift_function" class="flat"><option value="">&nbsp;</option>';
		foreach ($functions as $function) {
			print '<option value="'.dol_escape_htmltag($function['code']).'">'.dol_escape_htmltag($function['label']).'</option>';
		}
		print '</select> <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('Add')).'">';
		print '</td></tr></table></form>';

		if ($plan) {
			print '<form method="POST" name="vereineshiftperson" action="'.$self.'#vereineeventshifts">';
			print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="signup">';
			print '<table class="border centpercent"><tr><td class="titlefield">'.$langs->trans('VereineShiftPutOn').'</td><td>';
			print '<select name="shift" class="flat">';
			foreach ($plan as $shift) {
				print '<option value="'.((int) $shift['id']).'">'.dol_escape_htmltag($shift['label'].' · '.vereineFormatDay($shift['shift_day'])).'</option>';
			}
			print '</select> <select name="member" class="flat">';
			foreach ($members as $memberId => $name) {
				print '<option value="'.$memberId.'">'.dol_escape_htmltag($name).'</option>';
			}
			print '</select> <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineShiftPutOnButton')).'">';
			print '</td></tr></table></form>';
		}
	}

	// The short report of the event.
	print load_fiche_titre($langs->trans('VereineEventReportHeading'), '', '', 0, 'vereineeventreport');
	$reportFile = VereineEvents::reportPath($id);
	print '<div class="paddingbottom" data-event-report="'.(is_file($reportFile) ? 1 : 0).'">';
	if (is_file($reportFile)) {
		print '<a href="'.$self.'&amp;action=reportpdf&amp;token='.newToken().'">'.$langs->trans('VereineEventReportDownload').'</a> ';
		print '<span class="opacitymedium small">'.dol_print_date(filemtime($reportFile), 'dayhour').'</span>';
	} else {
		print '<span class="opacitymedium">'.$langs->trans('VereineEventReportNone').'</span>';
	}
	print '</div>';

	if ($mayManage) {
		print '<div class="tabsAction"><form method="POST" name="vereineeventreport" action="'.$self.'">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="report">';
		print '<input type="submit" class="butAction" value="'.dol_escape_htmltag($langs->trans('VereineEventReportBuild')).'"></form></div>';
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
