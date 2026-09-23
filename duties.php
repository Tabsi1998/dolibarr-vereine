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
 * \file    duties.php
 * \ingroup vereine
 * \brief   The calendar of duties (#24): what comes back every year, when it is due and who does it.
 *
 * The page shows a year of the association: every duty of the catalogue with its day and how it
 * stands. Whoever holds a function may put the duties into Dolibarr's agenda, tick them off, and hand
 * open ones over after a change of office. The catalogue itself is for administrators.
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

require_once __DIR__.'/class/vereineduties.class.php';
require_once __DIR__.'/class/vereinefunctions.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('admin', 'other', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read')) {
	accessforbidden();
}

$duties = new VereineDuties($db);
$action = GETPOST('action', 'aZ09');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$startMonth = getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1);
$lastEnded = VereineAuditRules::lastEnded($today, $startMonth);
$mayManage = $duties->mayManage($user, $today);
$year = GETPOSTINT('year');
if ($year < $lastEnded - 5 || $year > $lastEnded + 2) {
	$year = $lastEnded + 1;
}
$self = $_SERVER['PHP_SELF'].'?year='.$year;


/*
 * Actions
 */

if ($action === 'plan' && $mayManage) {
	$written = $duties->createTasks($year, $today, $user);
	if ($written >= 0) {
		setEventMessages($langs->trans($written > 0 ? 'VereineDutyPlanned' : 'VereineDutyNothingToPlan', $written), null, 'mesgs');
		header('Location: '.$self);
		exit;
	}
	setEventMessages($duties->error, null, 'errors');
} elseif ($action === 'done' && $mayManage) {
	if ($duties->markDone(GETPOSTINT('task'), GETPOST('day', 'alphanohtml') !== '' ? GETPOST('day', 'alphanohtml') : $today, $user) > 0) {
		header('Location: '.$self);
		exit;
	}
	setEventMessages($duties->error, null, 'errors');
} elseif ($action === 'undone' && $mayManage) {
	if ($duties->markDone(GETPOSTINT('task'), '', $user) > 0) {
		header('Location: '.$self);
		exit;
	}
	setEventMessages($duties->error, null, 'errors');
} elseif ($action === 'handover' && $mayManage) {
	$result = $duties->handover(GETPOSTINT('task'), $today, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineDutyHandedOver'), null, 'mesgs');
		header('Location: '.$self);
		exit;
	}
	setEventMessages($result < 0 ? $duties->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $duties->errors), 'errors');
} elseif ($action === 'save' && !empty($user->admin)) {
	$entered = array('code' => GETPOST('code', 'aZ09'), 'label' => GETPOST('label', 'alphanohtml'), 'function_code' => GETPOST('function_code', 'aZ09'),
		'basis' => GETPOST('basis', 'aZ09'), 'offset_months' => GETPOST('offset_months', 'int'), 'due_month' => GETPOST('due_month', 'int'),
		'due_day' => GETPOST('due_day', 'int'), 'every_years' => GETPOST('every_years', 'int'), 'first_year' => GETPOST('first_year', 'int'),
		'lead_days' => GETPOST('lead_days', 'int'), 'note' => GETPOST('note', 'alphanohtml'), 'active' => GETPOST('active', 'aZ09') !== '0');
	$result = $duties->save(GETPOSTINT('duty'), $entered, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineDutySaved'), null, 'mesgs');
		header('Location: '.$self.'#vereinedutycatalogue');
		exit;
	}
	setEventMessages($result < 0 ? $duties->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $duties->errors), 'errors');
} elseif ($action === 'remove' && !empty($user->admin)) {
	$result = $duties->remove(GETPOSTINT('duty'), $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineDutyRemoved'), null, 'mesgs');
		header('Location: '.$self.'#vereinedutycatalogue');
		exit;
	}
	setEventMessages($result < 0 ? $duties->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $duties->errors), 'errors');
}


/*
 * View
 */

$period = VereineAuditRules::period($year, $startMonth);
$plan = $duties->plan($year, $today);
$handovers = $duties->handovers($today);
$catalogue = $duties->fetchAll();
$mine = (int) $user->fk_member > 0 ? $duties->forMember((int) $user->fk_member, $today) : array();
$functions = (new VereineFunctions($db))->fetchAll(true);
$functionLabels = array();
foreach ($functions as $function) {
	$functionLabels[$function['code']] = $function['label'];
}
$badges = array('overdue' => 'badge-status8', 'due' => 'badge-status1', 'ahead' => 'badge-status4', 'done' => 'badge-status6');

llxHeader('', $langs->trans('VereineDutyTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-duties');
print load_fiche_titre($langs->trans('VereineDutyTitle'), '', 'fa-calendar-check');

print '<div class="paddingbottom" data-duty-years="1">'.$langs->trans('VereineDutyYear').': ';
for ($option = $lastEnded - 2; $option <= $lastEnded + 2; $option++) {
	$label = VereineAuditRules::period($option, $startMonth)['label'];
	print $option === $year ? '<strong class="paddingright">'.$label.'</strong> '
		: '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?year='.$option.'">'.$label.'</a> ';
}
print '</div>';
print '<div class="info" data-duty-howto="1"><ul>';
print '<li>'.$langs->trans('VereineDutyHowTo', $period['label'], vereineFormatDay($period['start']), vereineFormatDay($period['end'])).'</li>';
print '<li>'.$langs->trans('VereineDutyHowToAgenda').'</li>';
print '<li>'.$langs->trans('VereineDutyHowToHandover').'</li>';
print '</ul></div>';

// What waits for a word after a change of office: nothing moves by itself.
if ($handovers) {
	print load_fiche_titre($langs->trans('VereineDutyHandoverTitle', count($handovers)), '', 'fa-exchange-alt', 0, 'vereinedutyhandover');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-duty-handovers="'.count($handovers).'">';
	print '<tr class="liste_titre"><td>'.$langs->trans('VereineDutyName').'</td><td>'.$langs->trans('VereineDutyDue').'</td>';
	print '<td>'.$langs->trans('VereineDutyFrom').'</td><td>'.$langs->trans('VereineDutyTo').'</td><td></td></tr>';
	foreach ($handovers as $entry) {
		print '<tr class="oddeven" data-duty-handover="'.$entry['task_id'].'">';
		print '<td>'.dol_escape_htmltag($entry['label']).'</td><td class="nowraponall">'.vereineFormatDay($entry['due_on']).'</td>';
		print '<td>'.dol_escape_htmltag($entry['from_name']).'</td><td>'.dol_escape_htmltag($entry['to_name']).'</td><td class="right">';
		if ($mayManage) {
			print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="handover"><input type="hidden" name="task" value="'.$entry['task_id'].'">';
			print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineDutyHandover')).'"></form>';
		}
		print '</td></tr>';
	}
	print '</table></div>';
}

// The year itself: every duty with its day and how it stands.
print load_fiche_titre($langs->trans('VereineDutyPlanTitle', $period['label']), '', '', 0, 'vereinedutyplan');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-duty-plan="'.count($plan).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineDutyDue').'</td><td>'.$langs->trans('VereineDutyName').'</td>';
print '<td>'.$langs->trans('VereineDutyResponsible').'</td><td>'.$langs->trans('VereineDutySource').'</td>';
print '<td>'.$langs->trans('Status').'</td><td></td></tr>';
foreach ($plan as $entry) {
	$duty = $entry['duty'];
	$names = array();
	foreach ($entry['holders'] as $holder) {
		$names[] = dol_escape_htmltag($holder['name']);
	}
	$functionLabel = isset($functionLabels[$duty['function_code']]) ? $functionLabels[$duty['function_code']] : $duty['function_code'];
	print '<tr class="oddeven" data-duty-code="'.dol_escape_htmltag($duty['code']).'" data-duty-state="'.$entry['state'].'" data-duty-due="'.$entry['due'].'">';
	print '<td class="nowraponall">'.vereineFormatDay($entry['due']).'</td>';
	print '<td>'.dol_escape_htmltag($duty['label']).($duty['note'] !== '' ? '<br><span class="opacitymedium small">'.dol_escape_htmltag($duty['note']).'</span>' : '').'</td>';
	print '<td>'.dol_escape_htmltag($functionLabel);
	print $names ? '<br><span class="opacitymedium small">'.implode(', ', $names).'</span>'
		: '<br><span class="opacitymedium small">'.$langs->trans('VereineDutyNobody').'</span>';
	print '</td>';
	print '<td class="nowraponall opacitymedium small">'.dol_escape_htmltag($duty['source']).'</td>';
	print '<td><span class="badge badge-status '.$badges[$entry['state']].'">'.$langs->trans('VereineDutyState_'.$entry['state']).'</span>';
	if ($entry['task'] !== null && $entry['task']['done_on'] !== '') {
		print ' <span class="opacitymedium small">'.vereineFormatDay($entry['task']['done_on']).'</span>';
	}
	print '</td><td class="right nowraponall">';
	if ($mayManage && $entry['task'] !== null) {
		$done = $entry['task']['done_on'] !== '';
		print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="'.($done ? 'undone' : 'done').'"><input type="hidden" name="task" value="'.$entry['task']['id'].'">';
		print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans($done ? 'VereineDutyUndone' : 'VereineDutyDone')).'"></form>';
	} elseif ($entry['task'] === null) {
		print '<span class="opacitymedium small" data-duty-unplanned="1">'.$langs->trans('VereineDutyNotPlanned').'</span>';
	}
	print '</td></tr>';
}
if (!$plan) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium" data-duty-none="1">'.$langs->trans('VereineDutyPlanEmpty').'</span></td></tr>';
}
print '</table></div>';

if ($mayManage) {
	print '<div class="tabsAction"><form method="POST" name="vereinedutyplan" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="plan">';
	print '<input type="submit" class="butAction" value="'.dol_escape_htmltag($langs->trans('VereineDutyPlan')).'"></form></div>';
}

// What waits for the person looking.
if ($mine) {
	print load_fiche_titre($langs->trans('VereineDutyMineTitle', count($mine)), '', 'fa-user-check', 0, 'vereinedutymine');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-duty-mine="'.count($mine).'">';
	foreach ($mine as $entry) {
		print '<tr class="oddeven" data-duty-mine-task="'.$entry['task_id'].'" data-duty-state="'.$entry['state'].'">';
		print '<td>'.dol_escape_htmltag($entry['label']).'</td>';
		print '<td class="nowraponall">'.vereineFormatDay($entry['due_on']).'</td>';
		print '<td><span class="badge badge-status '.$badges[$entry['state']].'">'.$langs->trans('VereineDutyState_'.$entry['state']).'</span></td></tr>';
	}
	print '</table></div>';
}

// The catalogue: what the association carries, and what it adds itself.
print load_fiche_titre($langs->trans('VereineDutyCatalogueTitle'), '', 'fa-list', 0, 'vereinedutycatalogue');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-duty-catalogue="'.count($catalogue).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineDutyName').'</td><td>'.$langs->trans('VereineDutyResponsible').'</td>';
print '<td>'.$langs->trans('VereineDutyWhen').'</td><td>'.$langs->trans('VereineDutyReminder').'</td><td>'.$langs->trans('Status').'</td><td></td></tr>';
foreach ($catalogue as $duty) {
	$when = $duty['basis'] === VereineDutyRules::BASIS_CALENDAR
		? ($duty['due_day'] >= 31 ? $langs->trans('VereineDutyWhenCalendarLast', vereineMonthName($duty['due_month']))
			: $langs->trans('VereineDutyWhenCalendar', $duty['due_day'], vereineMonthName($duty['due_month'])))
		: ($duty['basis'] === VereineDutyRules::BASIS_EVENT ? $langs->trans('VereineDutyWhenEvent')
			: $langs->trans($duty['basis'] === VereineDutyRules::BASIS_ACCOUNT ? 'VereineDutyWhenAccount' : 'VereineDutyWhenYearEnd', $duty['offset_months']));
	if ($duty['every_years'] > 1) {
		$when .= ', '.$langs->trans('VereineDutyEveryYears', $duty['every_years']);
	}
	print '<tr class="oddeven" data-duty-entry="'.dol_escape_htmltag($duty['code']).'" data-duty-active="'.($duty['active'] ? 1 : 0).'">';
	print '<td>'.dol_escape_htmltag($duty['label']).'<br><span class="opacitymedium small">'.dol_escape_htmltag($duty['source'] !== '' ? $duty['source'] : $duty['code']).'</span></td>';
	print '<td>'.dol_escape_htmltag(isset($functionLabels[$duty['function_code']]) ? $functionLabels[$duty['function_code']] : $duty['function_code']).'</td>';
	print '<td>'.$when.'</td>';
	print '<td class="nowraponall">'.$langs->trans('VereineDutyLeadDaysValue', $duty['lead_days']).'</td>';
	print '<td><span class="badge badge-status '.($duty['active'] ? 'badge-status4' : 'badge-status0').'">'.$langs->trans($duty['active'] ? 'Enabled' : 'Disabled').'</span></td>';
	print '<td class="right nowraponall">';
	if (!empty($user->admin)) {
		print '<a class="button small" href="'.$self.'&amp;edit='.((int) $duty['id']).'#vereinedutyform">'.$langs->trans('Modify').'</a>';
		if (!$duty['standard']) {
			print ' <form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="remove"><input type="hidden" name="duty" value="'.((int) $duty['id']).'">';
			print '<input type="submit" class="button small butActionDelete" value="'.dol_escape_htmltag($langs->trans('Delete')).'"></form>';
		}
	}
	print '</td></tr>';
}
print '</table></div>';

// Change an entry or add one of the association's own.
if (!empty($user->admin)) {
	$editId = GETPOSTINT('edit');
	$edited = array('id' => 0, 'code' => '', 'label' => '', 'function_code' => '', 'basis' => VereineDutyRules::BASIS_YEAR_END,
		'offset_months' => 5, 'due_month' => 2, 'due_day' => 28, 'every_years' => 1, 'first_year' => 0,
		'lead_days' => VereineDutyRules::LEAD_DAYS, 'note' => '', 'standard' => false, 'active' => true);
	foreach ($catalogue as $duty) {
		if ($duty['id'] === $editId) {
			$edited = $duty;
		}
	}
	print load_fiche_titre($langs->trans($edited['id'] > 0 ? 'VereineDutyEditTitle' : 'VereineDutyAddTitle'), '', '', 0, 'vereinedutyform');
	print '<form method="POST" name="vereineduty" action="'.$self.'#vereinedutyform" data-duty-form="'.($edited['id'] > 0 ? 'edit' : 'add').'">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
	print '<input type="hidden" name="duty" value="'.((int) $edited['id']).'">';
	print '<table class="border centpercent">';
	print '<tr><td class="titlefield fieldrequired">'.$langs->trans('VereineDutyCode').'</td><td>';
	if ($edited['id'] > 0) {
		print '<strong>'.dol_escape_htmltag($edited['code']).'</strong><input type="hidden" name="code" value="'.dol_escape_htmltag($edited['code']).'">';
	} else {
		print '<input type="text" name="code" maxlength="32" value="'.dol_escape_htmltag(GETPOST('code', 'aZ09')).'">';
		print ' <span class="opacitymedium small">'.$langs->trans('VereineDutyCodeHint').'</span>';
	}
	print '</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('VereineDutyName').'</td><td><input type="text" name="label" size="60" maxlength="128" value="'.dol_escape_htmltag($edited['label']).'"></td></tr>';
	print '<tr><td>'.$langs->trans('VereineDutyResponsible').'</td><td><select name="function_code" class="flat">';
	print '<option value=""'.($edited['function_code'] === '' ? ' selected' : '').'>&nbsp;</option>';
	foreach ($functions as $function) {
		print '<option value="'.dol_escape_htmltag($function['code']).'"'.($edited['function_code'] === $function['code'] ? ' selected' : '').'>'.dol_escape_htmltag($function['label']).'</option>';
	}
	print '</select></td></tr>';
	print '<tr><td>'.$langs->trans('VereineDutyWhen').'</td><td><select name="basis" class="flat">';
	foreach (VereineDutyRules::BASES as $basis) {
		print '<option value="'.$basis.'"'.($edited['basis'] === $basis ? ' selected' : '').'>'.$langs->trans('VereineDutyBasis_'.$basis).'</option>';
	}
	print '</select> ';
	print $langs->trans('VereineDutyOffsetMonths').' <input type="number" name="offset_months" min="0" max="24" size="3" value="'.((int) $edited['offset_months']).'"> ';
	print $langs->trans('VereineDutyCalendarDay').' <input type="number" name="due_day" min="1" max="31" size="3" value="'.((int) $edited['due_day']).'">.';
	print '<input type="number" name="due_month" min="1" max="12" size="3" value="'.((int) $edited['due_month']).'">';
	print '<br><span class="opacitymedium small">'.$langs->trans('VereineDutyWhenHint').'</span></td></tr>';
	print '<tr><td>'.$langs->trans('VereineDutyEvery').'</td><td>';
	print '<input type="number" name="every_years" min="1" max="10" size="3" value="'.((int) $edited['every_years']).'"> '.$langs->trans('VereineDutyEveryHint');
	print ' <input type="number" name="first_year" min="0" max="2100" size="5" value="'.((int) $edited['first_year']).'"></td></tr>';
	print '<tr><td>'.$langs->trans('VereineDutyReminder').'</td><td><input type="number" name="lead_days" min="0" max="365" size="4" value="'.((int) $edited['lead_days']).'"> '.$langs->trans('VereineDutyLeadDaysHint').'</td></tr>';
	print '<tr><td>'.$langs->trans('Note').'</td><td><input type="text" name="note" size="60" value="'.dol_escape_htmltag($edited['note']).'"></td></tr>';
	print '<tr><td>'.$langs->trans('Status').'</td><td><select name="active" class="flat">';
	print '<option value="1"'.($edited['active'] ? ' selected' : '').'>'.$langs->trans('Enabled').'</option>';
	print '<option value="0"'.($edited['active'] ? '' : ' selected').'>'.$langs->trans('Disabled').'</option>';
	print '</select></td></tr>';
	print '</table>';
	print '<div class="center paddingtop"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
	if ($edited['id'] > 0) {
		print ' <a class="button button-cancel" href="'.$self.'#vereinedutycatalogue">'.$langs->trans('Cancel').'</a>';
	}
	print '</div></form>';
}

llxFooter();
$db->close();
