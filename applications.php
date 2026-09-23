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
 * \file    applications.php
 * \ingroup vereine
 * \brief   Membership applications: look at them, take somebody in, say no (#72).
 *
 * The association decides here, never a website. Taking somebody in validates the member in Dolibarr.
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

require_once __DIR__.'/class/vereineapplications.class.php';
require_once __DIR__.'/class/vereinememberform.class.php';
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

$applications = new VereineApplications($db);
// Taking somebody in validates the member, so it needs the right to change members.
$canDecide = $user->hasRight('adherent', 'creer');
$action = GETPOST('action', 'aZ09');
$show = GETPOST('show', 'aZ09');
if (!in_array($show, VereineApplicationRules::STATUSES, true) && $show !== 'all') {
	$show = '';
}
$self = $_SERVER['PHP_SELF'].($show !== '' ? '?show='.$show : '');


/*
 * Actions
 */

if ($action === 'decide' && $canDecide) {
	$result = $applications->decide(GETPOSTINT('application'), GETPOST('status', 'aZ09'), GETPOST('reason', 'restricthtml'), GETPOST('note', 'restricthtml'), $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineApplicationDecided'), null, 'mesgs');
		header('Location: '.$self.'#vereineapplications');
		exit;
	}
	setEventMessages($result < 0 ? $applications->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $applications->errors), 'errors');
}


/*
 * View
 */

$rows = $applications->all($show === 'all' ? '' : ($show !== '' ? $show : VereineApplicationRules::RECEIVED));
$counts = array();
foreach ($applications->all('', 500) as $row) {
	$counts[$row['status']] = (isset($counts[$row['status']]) ? $counts[$row['status']] : 0) + 1;
}

llxHeader('', $langs->trans('VereineApplicationsTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-applications');
print load_fiche_titre($langs->trans('VereineApplicationsTitle'), '', 'fa-user-plus');
print '<div class="info" data-applications-howto="1"><ul>';
print '<li>'.$langs->trans('VereineApplicationsHowTo').'</li>';
print '<li>'.$langs->trans('VereineApplicationsHowToDecide').'</li>';
print '<li>'.$langs->trans('VereineApplicationsHowToReason').'</li>';
print '</ul></div>';

print '<div class="paddingbottom" data-applications-filter="'.($show !== '' ? $show : VereineApplicationRules::RECEIVED).'">';
foreach (array_merge(VereineApplicationRules::STATUSES, array('all')) as $option) {
	$label = $option === 'all' ? $langs->trans('VereineApplicationsAll') : $langs->trans('VereineApplicationStatus_'.$option);
	$number = $option === 'all' ? array_sum($counts) : (isset($counts[$option]) ? $counts[$option] : 0);
	$here = ($show === '' ? VereineApplicationRules::RECEIVED : $show) === $option;
	print $here ? '<strong class="paddingright">'.$label.' ('.$number.')</strong> '
		: '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?show='.$option.'">'.$label.' ('.$number.')</a> ';
}
print '</div>';

print '<a name="vereineapplications"></a>';
print '<div class="div-table-responsive"><table class="noborder centpercent" data-applications="'.count($rows).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('Email').'</td>';
print '<td>'.$langs->trans('Status').'</td><td>'.$langs->trans('VereineApplicationExternal').'</td><td></td></tr>';
foreach ($rows as $row) {
	$lookalikes = $applications->lookalikes($row);
	print '<tr class="oddeven" data-application="'.$row['id'].'" data-status="'.$row['status'].'" data-lookalikes="'.count($lookalikes).'">';
	print '<td class="nowraponall">'.dol_print_date($row['received'], 'dayhour').'</td>';
	print '<td><a href="'.DOL_URL_ROOT.'/adherents/card.php?id='.$row['member_id'].'">'.dol_escape_htmltag($row['name'] !== '' ? $row['name'] : $row['ref']).'</a>';
	if ($lookalikes) {
		print '<div class="warning small" data-application-lookalike="1">'.$langs->trans('VereineApplicationLookalike');
		foreach ($lookalikes as $other) {
			print ' <a href="'.DOL_URL_ROOT.'/adherents/card.php?id='.$other['id'].'">'.dol_escape_htmltag($other['name'] !== '' ? $other['name'] : $other['ref']).'</a>';
		}
		print '</div>';
	}
	print '</td><td>'.dol_escape_htmltag($row['email']).'</td>';
	print '<td>'.$langs->trans('VereineApplicationStatus_'.$row['status']);
	if ($row['status'] === VereineApplicationRules::REJECTED && $row['reason'] !== '') {
		print '<div class="opacitymedium small">'.dol_escape_htmltag($row['reason']).'</div>';
	}
	print '</td><td class="opacitymedium small">'.dol_escape_htmltag($row['external_id']).'</td><td class="right">';
	if ($canDecide && !in_array($row['status'], VereineApplicationRules::SETTLED, true)) {
		print '<form method="POST" action="'.$self.'#vereineapplications" name="vereineapplication'.$row['id'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="decide">';
		print '<input type="hidden" name="application" value="'.$row['id'].'">';
		print '<select name="status">';
		foreach (VereineApplicationRules::NEXT[$row['status']] as $next) {
			print '<option value="'.$next.'">'.$langs->trans('VereineApplicationStatus_'.$next).'</option>';
		}
		print '</select> ';
		print '<input type="text" name="reason" class="minwidth200" placeholder="'.dol_escape_htmltag($langs->trans('VereineApplicationReasonHint')).'"> ';
		print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineApplicationDecide')).'">';
		print '</form>';
	}
	print '</td></tr>';
}
if (!$rows) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('VereineApplicationsNone').'</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
