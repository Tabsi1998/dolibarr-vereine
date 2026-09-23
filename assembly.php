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
 * \file    assembly.php
 * \ingroup vereine
 * \brief   The way through a general assembly (#127): before, on the day, afterwards.
 *
 * Every step says how it stands and links to the page where it is done. Nothing is decided here: the
 * page only reads what the module already knows, so the order of the year is visible at a glance.
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

require_once __DIR__.'/class/vereineassembly.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('other', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read')) {
	accessforbidden();
}

$assembly = new VereineAssembly($db);
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$id = GETPOSTINT('id');
$meetings = $assembly->all();
$meeting = null;
foreach ($meetings as $row) {
	if ($id > 0 && $row['id'] === $id) {
		$meeting = $row;
	}
}
if ($meeting === null) {
	$meeting = $assembly->current($today);
}


/*
 * View
 */

$badges = array('overdue' => 'badge-status8', 'now' => 'badge-status1', 'later' => 'badge-status4',
	'done' => 'badge-status6', 'none' => 'badge-status0');
// Where each step is done; the page only points there.
$links = array(
	'account' => '/vereine/account.php',
	'audit' => '/vereine/audit.php',
	'auditreport' => '/vereine/audit.php',
	'elections' => '/vereine/functions.php',
	'agenda' => '/vereine/meetings.php',
	'invitation' => '/vereine/meetings.php',
	'motions' => '/vereine/meetings.php',
	'documents' => '/vereine/meetings.php',
	'attendance' => '/vereine/meetings.php',
	'votes' => '/vereine/meetings.php',
	'minutes' => '/vereine/meetings.php',
	'signatures' => '/vereine/meetings.php',
	'resolutions' => '/vereine/resolutions.php',
	'authority' => '/vereine/functions.php',
	'statutes' => '/vereine/authority.php',
	'groups' => '/vereine/functions.php',
	'inform' => '/vereine/meetings.php',
);

llxHeader('', $langs->trans('VereineAssemblyTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-assembly');
print load_fiche_titre($langs->trans('VereineAssemblyTitle'), '', 'fa-list-ol');

if ($meeting === null) {
	print '<div class="info" data-assembly="none">'.$langs->trans('VereineAssemblyNone',
		'<a href="'.dol_buildpath('/vereine/meetings.php', 1).'">'.$langs->trans('VereineMenuMeetings').'</a>').'</div>';
	llxFooter();
	$db->close();
	exit;
}

if (count($meetings) > 1) {
	print '<div class="paddingbottom" data-assembly-list="'.count($meetings).'">'.$langs->trans('VereineAssemblyWhich').': ';
	foreach ($meetings as $row) {
		$label = vereineFormatDay($row['day']);
		print $row['id'] === $meeting['id'] ? '<strong class="paddingright">'.$label.'</strong> '
			: '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?id='.((int) $row['id']).'">'.$label.'</a> ';
	}
	print '</div>';
}

$result = $assembly->steps($meeting, $today);
$facts = $result['facts'];
$progress = $result['progress'];
print '<div class="paddingbottom" data-assembly="'.((int) $meeting['id']).'" data-assembly-day="'.dol_escape_htmltag($meeting['day']).'"';
print ' data-assembly-progress="'.((int) $progress['percent']).'" data-assembly-overdue="'.((int) $progress['overdue']).'">';
print '<strong>'.dol_escape_htmltag($meeting['title']).'</strong> · '.vereineFormatDay($meeting['day']);
print ' · <a href="'.dol_buildpath('/vereine/meetings.php', 1).'?id='.((int) $meeting['id']).'">'.$langs->trans('VereineMenuMeetings').'</a>';
print '<br>'.$langs->trans('VereineAssemblyProgress', $progress['done'], $progress['total']);
if ($progress['overdue'] > 0) {
	print ' <span class="badge badge-status badge-status8">'.$langs->trans('VereineAssemblyOverdue', $progress['overdue']).'</span>';
}
print '</div>';
print '<div class="info" data-assembly-howto="1">'.$langs->trans('VereineAssemblyHowTo', $facts['fiscal_label']).'</div>';

foreach (VereineAssemblyRules::PHASES as $phase) {
	print load_fiche_titre($langs->trans('VereineAssemblyPhase_'.$phase), '', '', 0, 'vereineassembly'.$phase);
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-assembly-phase="'.$phase.'">';
	print '<tr class="liste_titre"><td>'.$langs->trans('VereineAssemblyStep').'</td><td>'.$langs->trans('VereineAssemblyDeadline').'</td>';
	print '<td>'.$langs->trans('Status').'</td><td></td></tr>';
	foreach ($result['steps'] as $step) {
		if ($step['phase'] !== $phase) {
			continue;
		}
		print '<tr class="oddeven" data-step="'.$step['code'].'" data-step-state="'.$step['state'].'">';
		print '<td>'.$langs->trans('VereineAssemblyStep_'.$step['code']);
		print '<br><span class="opacitymedium small">'.$langs->trans('VereineAssemblyHelp_'.$step['code']).'</span></td>';
		print '<td class="nowraponall">'.($step['deadline'] !== '' ? vereineFormatDay($step['deadline']) : '').'</td>';
		print '<td><span class="badge badge-status '.$badges[$step['state']].'">'.$langs->trans('VereineAssemblyState_'.$step['state']).'</span>';
		if ($step['detail'] !== '' && $step['state'] !== 'none') {
			print ' <span class="opacitymedium small">'.dol_escape_htmltag($step['detail']).'</span>';
		}
		print '</td><td class="right nowraponall">';
		if ($step['state'] !== 'none' && isset($links[$step['code']])) {
			$url = dol_buildpath($links[$step['code']], 1);
			if (in_array($step['code'], array('agenda', 'invitation', 'motions', 'documents', 'attendance', 'votes', 'minutes', 'signatures', 'inform'), true)) {
				$url .= '?id='.((int) $meeting['id']);
			} elseif (in_array($step['code'], array('account', 'audit', 'auditreport'), true)) {
				$url .= '?year='.((int) $facts['fiscal_year']);
			}
			print '<a href="'.$url.'">'.$langs->trans('VereineAssemblyOpen').'</a>';
		}
		print '</td></tr>';
	}
	print '</table></div>';
}

llxFooter();
$db->close();
