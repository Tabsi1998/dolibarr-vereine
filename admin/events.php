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
 * \file    admin/events.php
 * \ingroup vereine
 * \brief   Event templates (#23): what has to be done before, during and after an event.
 *
 * A template is a checklist with a day for every point, counted from the day of the event. Changing a
 * template never changes an event that already runs: an event keeps the checklist it was made with.
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../class/vereineevents.class.php';
require_once __DIR__.'/../class/vereinefunctions.class.php';
require_once __DIR__.'/../lib/vereine.lib.php';

$langs->loadLangs(array('admin', 'other', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$events = new VereineEvents($db);
$action = GETPOST('action', 'aZ09');
$templateId = GETPOSTINT('template');
$self = $_SERVER['PHP_SELF'].($templateId > 0 ? '?template='.$templateId : '');


/*
 * Actions
 */

if ($action === 'savetemplate') {
	$entered = array('code' => GETPOST('code', 'aZ09'), 'label' => GETPOST('label', 'alphanohtml'),
		'note' => GETPOST('note', 'restricthtml'), 'active' => GETPOST('active', 'aZ09') !== '0');
	$result = $events->saveTemplate(GETPOSTINT('id'), $entered, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineEventTemplateSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	setEventMessages($result < 0 ? $events->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $events->errors), 'errors');
} elseif ($action === 'savetask' && $templateId > 0) {
	$entered = array('phase' => GETPOST('phase', 'aZ09'), 'label' => GETPOST('task_label', 'alphanohtml'),
		'function_code' => GETPOST('function_code', 'aZ09'), 'offset_days' => GETPOST('offset_days', 'alphanohtml'),
		'source' => GETPOST('source', 'alphanohtml'));
	$result = $events->saveTemplateTask(GETPOSTINT('id'), $templateId, $entered, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineEventTemplateSaved'), null, 'mesgs');
		header('Location: '.$self);
		exit;
	}
	setEventMessages($result < 0 ? $events->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $events->errors), 'errors');
} elseif ($action === 'removetask' && $templateId > 0) {
	if ($events->removeTemplateTask(GETPOSTINT('id'), $user) > 0) {
		header('Location: '.$self);
		exit;
	}
	setEventMessages($events->error, null, 'errors');
}


/*
 * View
 */

$templates = $events->templates();
$functions = (new VereineFunctions($db))->fetchAll(true);
$functionLabels = array();
foreach ($functions as $function) {
	$functionLabels[$function['code']] = $function['label'];
}

llxHeader('', $langs->trans('VereineEventTemplates'), '', '', 0, 0, '', '', '', 'mod-vereine page-admin-events');
print load_fiche_titre($langs->trans('VereineEventTemplates'), '', 'object_vereine@vereine');
print dol_get_fiche_head(vereineAdminPrepareHead(), 'events', '', -1);
print '<div class="info" data-template-howto="1">'.$langs->trans('VereineEventTemplateHowTo').'</div>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-templates="'.count($templates).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineEventTemplate').'</td><td>'.$langs->trans('VereineEventPoints').'</td>';
print '<td>'.$langs->trans('Status').'</td><td></td></tr>';
foreach ($templates as $template) {
	$points = count($events->templateTasks($template['id']));
	print '<tr class="oddeven" data-template="'.dol_escape_htmltag($template['code']).'" data-template-points="'.$points.'">';
	print '<td>'.dol_escape_htmltag($template['label']);
	if ($template['note'] !== '') {
		print '<br><span class="opacitymedium small">'.dol_escape_htmltag($template['note']).'</span>';
	}
	print '</td><td>'.$points.'</td>';
	print '<td><span class="badge badge-status '.($template['active'] ? 'badge-status4' : 'badge-status0').'">';
	print $langs->trans($template['active'] ? 'Enabled' : 'Disabled').'</span></td>';
	print '<td class="right"><a class="button small" href="'.$_SERVER['PHP_SELF'].'?template='.((int) $template['id']).'#vereinetemplatetasks">';
	print $langs->trans('VereineEventPoints').'</a></td></tr>';
}
print '</table></div>';

// A template of the association itself.
print load_fiche_titre($langs->trans('VereineEventTemplateAdd'), '', '', 0, 'vereinetemplateform');
print '<form method="POST" name="vereinetemplate" action="'.$_SERVER['PHP_SELF'].'#vereinetemplateform">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="savetemplate">';
print '<table class="border centpercent">';
print '<tr><td class="titlefield fieldrequired">'.$langs->trans('VereineEventCode').'</td><td><input type="text" name="code" maxlength="32" value="">';
print ' <span class="opacitymedium small">'.$langs->trans('VereineEventCodeHint').'</span></td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('VereineEventName').'</td><td><input type="text" name="label" size="40" maxlength="128" value=""></td></tr>';
print '<tr><td>'.$langs->trans('Note').'</td><td><input type="text" name="note" size="60" value=""></td></tr>';
print '</table>';
print '<div class="center paddingtop"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div></form>';

// The points of one template.
if ($templateId > 0) {
	$tasks = $events->templateTasks($templateId);
	print load_fiche_titre($langs->trans('VereineEventPoints'), '', '', 0, 'vereinetemplatetasks');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-template-tasks="'.count($tasks).'">';
	print '<tr class="liste_titre"><td>'.$langs->trans('VereineEventPhase').'</td><td>'.$langs->trans('VereineEventOffset').'</td>';
	print '<td>'.$langs->trans('VereineEventPoint').'</td><td>'.$langs->trans('VereineEventResponsible').'</td>';
	print '<td>'.$langs->trans('VereineEventSource').'</td><td></td></tr>';
	foreach ($tasks as $task) {
		print '<tr class="oddeven" data-template-task="'.((int) $task['id']).'" data-template-phase="'.$task['phase'].'" data-template-offset="'.((int) $task['offset_days']).'">';
		print '<td class="nowraponall">'.$langs->trans('VereineEventPhase_'.$task['phase']).'</td>';
		print '<td class="nowraponall">'.$langs->trans($task['offset_days'] < 0 ? 'VereineEventOffsetBefore' : 'VereineEventOffsetAfter', abs((int) $task['offset_days'])).'</td>';
		print '<td>'.dol_escape_htmltag($task['label']).'</td>';
		print '<td>'.dol_escape_htmltag(isset($functionLabels[$task['function_code']]) ? $functionLabels[$task['function_code']] : $task['function_code']).'</td>';
		print '<td class="opacitymedium small">'.dol_escape_htmltag($task['source']).'</td>';
		print '<td class="right"><form method="POST" action="'.$self.'" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="removetask">';
		print '<input type="hidden" name="id" value="'.((int) $task['id']).'">';
		print '<input type="submit" class="button small butActionDelete" value="'.dol_escape_htmltag($langs->trans('Delete')).'"></form></td></tr>';
	}
	print '</table></div>';

	print '<form method="POST" name="vereinetemplatetask" action="'.$self.'#vereinetemplatetasks">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="savetask">';
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
	print '</select> <input type="number" name="offset_days" size="4" value="-14"> '.$langs->trans('VereineEventOffsetHint');
	print ' <input type="text" name="source" size="20" maxlength="64" value="" placeholder="'.dol_escape_htmltag($langs->trans('VereineEventSource')).'">';
	print ' <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('Add')).'">';
	print '</td></tr></table></form>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
