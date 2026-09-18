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
 * \file    circulars.php
 * \ingroup vereine
 * \brief   Circular resolutions of the board: start one, vote in Dolibarr, count the result.
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

require_once __DIR__.'/class/vereinecirculars.class.php';
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
$circulars = new VereineCirculars($db);
$rules = $circulars->rules();
$allowed = !empty($rules['circular']);
$canWrite = $user->hasRight('adherent', 'creer');
$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$entered = null;


/**
 * The state of a circular resolution as a badge.
 *
 * @param array<string,mixed> $circular Circular resolution
 * @return string HTML
 */
function vereineCircularBadge(array $circular)
{
	global $langs;

	if ($circular['status'] === VereineCircularRules::STATUS_OPEN) {
		return dolGetBadge($langs->trans('VereineCircularStatus_open'), '', 'info');
	}
	if ($circular['status'] === VereineCircularRules::STATUS_CANCELLED) {
		$key = $circular['objection'] > 0 ? 'VereineCircularStatus_objection' : 'VereineCircularStatus_cancelled';
		return dolGetBadge($langs->trans($key), '', 'secondary');
	}
	return dolGetBadge($langs->trans($circular['passed'] ? 'VereineResolutionPassed' : 'VereineResolutionRejected'), '', $circular['passed'] ? 'success' : 'secondary');
}


/*
 * Actions
 */

if ($action === 'start' && $canWrite) {
	$entered = array('title' => GETPOST('title', 'alphanohtml'), 'wording' => GETPOST('wording', 'restricthtml'), 'deadline' => GETPOST('deadline', 'alphanohtml'));
	$result = $circulars->start($entered, $user, $langs);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineCircularStarted'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$result);
		exit;
	}
	setEventMessages($result < 0 ? $circulars->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $circulars->errors), 'errors');
	$entered = VereineCircularRules::normalize($entered);
} elseif ($action === 'vote') {
	$result = $circulars->vote($id, (int) $user->fk_member, GETPOST('choice', 'aZ09'), $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineCircularVoted'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
		exit;
	}
	setEventMessages($result < 0 ? $circulars->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $circulars->errors), 'errors');
} elseif ($action === 'remind' && $canWrite) {
	$result = $circulars->remind($id, $user, $langs);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineCircularReminded', $result), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
		exit;
	}
	setEventMessages($result < 0 ? $circulars->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $circulars->errors), 'errors');
} elseif (($action === 'close' || $action === 'cancel') && $canWrite) {
	$result = $action === 'close' ? $circulars->close($id, $user) : $circulars->cancel($id, $user);
	if ($result > 0) {
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
		exit;
	}
	setEventMessages($result < 0 ? $circulars->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $circulars->errors), 'errors');
}


/*
 * View
 */

llxHeader('', $langs->trans('VereineCircularsTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-circulars');

$circular = $id > 0 ? $circulars->fetch($id) : null;

if ($circular === null) {
	print load_fiche_titre($langs->trans('VereineCircularsTitle'), '', 'fa-inbox');
	print '<div class="opacitymedium paddingbottom" data-circular-allowed="'.($allowed ? 1 : 0).'">'.$langs->trans('VereineCircularsHowTo');
	if (!$allowed) {
		print ' <span class="warning">'.$langs->trans('VereineCircularNotAllowed').'</span>';
		if (!empty($user->admin)) {
			print ' <a href="'.dol_buildpath('/vereine/admin/statutes.php', 1).'">'.$langs->trans('VereineCircularRulesLink').'</a>';
		}
	}
	print '</div>';

	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('VereineCircularTitleColumn').'</td><td>'.$langs->trans('VereineCircularDeadline').'</td>';
	print '<td>'.$langs->trans('Status').'</td><td>'.$langs->trans('VereineCircularVotes').'</td></tr>';
	$all = $circulars->fetchAll();
	if (!$all) {
		print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('VereineCircularsNone').'</span></td></tr>';
	}
	foreach ($all as $row) {
		$votes = $circulars->votes($row['id']);
		$counts = VereineCircularRules::counts($votes);
		print '<tr class="oddeven" data-circular="'.$row['id'].'" data-status="'.$row['status'].'" data-passed="'.($row['passed'] ? 1 : 0).'">';
		print '<td><a href="'.$_SERVER['PHP_SELF'].'?id='.$row['id'].'">'.dol_escape_htmltag($row['title']).'</a></td>';
		print '<td class="nowraponall">'.vereineFormatDay($row['deadline']).'</td>';
		print '<td>'.vereineCircularBadge($row).'</td>';
		print '<td>'.$langs->trans('VereineCircularVotesCount', $counts['given'], count($votes)).'</td></tr>';
	}
	print '</table></div><br>';

	if ($canWrite && $allowed) {
		print load_fiche_titre($langs->trans('VereineCircularNew'), '', '', 0, 'vereinecircularnew');
		$draft = $entered !== null ? $entered : VereineCircularRules::normalize(array());
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#vereinecircularnew" name="vereinecircular">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="start">';
		print '<table class="border centpercent">';
		print '<tr><td class="titlefieldcreate fieldrequired"><label for="title">'.$langs->trans('VereineCircularTitleColumn').'</label></td>';
		print '<td><input type="text" id="title" name="title" class="minwidth300" maxlength="255" value="'.dol_escape_htmltag($draft['title']).'"></td></tr>';
		print '<tr><td class="tdtop fieldrequired"><label for="wording">'.$langs->trans('VereineResolutionWording').'</label></td>';
		print '<td><textarea id="wording" name="wording" rows="4" class="centpercent">'.dol_escape_htmltag($draft['wording'], 0, 1).'</textarea>';
		print '<div class="opacitymedium small">'.$langs->trans('VereineCircularWordingHelp').'</div></td></tr>';
		print '<tr><td class="fieldrequired"><label for="deadline">'.$langs->trans('VereineCircularDeadline').'</label></td>';
		print '<td><input type="date" id="deadline" name="deadline" value="'.dol_escape_htmltag($draft['deadline']).'">';
		print ' <span class="opacitymedium small">'.$langs->trans('VereineCircularDeadlineHelp').'</span></td></tr>';
		print '<tr><td>'.$langs->trans('VereineCircularVoters').'</td><td>';
		$voters = $circulars->voters($today);
		$names = array();
		foreach ($voters as $voter) {
			$names[] = $voter['name'].($voter['label'] !== '' ? ' ('.$voter['label'].')' : '').($voter['email'] === '' ? ' - '.$langs->transnoentitiesnoconv('VereineCircularNoEmail') : '');
		}
		print '<span data-circular-voters="'.count($voters).'">'.dol_escape_htmltag(implode(', ', $names)).'</span>';
		print '</td></tr></table>';
		print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('VereineCircularStart')).'"></div>';
		print '</form>';
	}

	llxFooter();
	$db->close();
	exit;
}

// One circular resolution.
print load_fiche_titre(dol_escape_htmltag($circular['title']), '<a href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('BackToList').'</a>', 'fa-inbox');
$votes = $circulars->votes($circular['id']);
$counts = VereineCircularRules::counts($votes);
$ready = VereineCircularRules::ready($circular, count($votes), $votes, $rules, $today);
print '<table class="border centpercent" data-circular-card="'.$circular['id'].'" data-status="'.$circular['status'].'" data-ready="'.($ready ? 1 : 0).'">';
print '<tr><td class="titlefield">'.$langs->trans('Status').'</td><td>'.vereineCircularBadge($circular).'</td></tr>';
print '<tr><td class="tdtop">'.$langs->trans('VereineResolutionWording').'</td><td>'.nl2br(dol_escape_htmltag($circular['wording'], 0, 1)).'</td></tr>';
print '<tr><td>'.$langs->trans('VereineCircularStartedOn').'</td><td>'.vereineFormatDay($circular['started_on']).'</td></tr>';
print '<tr><td>'.$langs->trans('VereineCircularDeadline').'</td><td>'.vereineFormatDay($circular['deadline']);
if ($circular['reminded'] > 0) {
	print ' <span class="opacitymedium small">'.$langs->trans('VereineCircularRemindedOn', dol_print_date($circular['reminded'], 'dayhour')).'</span>';
}
print '</td></tr>';
if ($circular['status'] !== VereineCircularRules::STATUS_OPEN) {
	print '<tr><td>'.$langs->trans('VereineResolutionResult').'</td><td data-circular-result="'.($circular['passed'] ? 1 : 0).'">';
	print $langs->trans('VereineVoteCountsText', $circular['yes'], $circular['no'], $circular['abstain']);
	if ($circular['objection'] > 0) {
		print ' - '.$langs->trans('VereineCircularObjections', $circular['objection']);
	}
	print '</td></tr>';
	if ($circular['resolution_id'] > 0) {
		print '<tr><td>'.$langs->trans('VereineResolutionsTitle').'</td><td><a href="'.dol_buildpath('/vereine/resolutions.php', 1).'?id='.$circular['resolution_id'].'">';
		print $langs->trans('VereineCircularInRegister').'</a></td></tr>';
	}
}
print '</table>';

print '<br>'.load_fiche_titre($langs->trans('VereineCircularVotes'), '', '', 0, 'vereinecircularvotes');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineResolutionTaskMember').'</td><td>'.$langs->trans('VereineFunctionLabel').'</td>';
print '<td>'.$langs->trans('VereineCircularChoice').'</td><td>'.$langs->trans('Date').'</td></tr>';
foreach ($votes as $row) {
	print '<tr class="oddeven" data-circular-vote="'.$row['member_id'].'" data-choice="'.dol_escape_htmltag($row['choice']).'">';
	print '<td>'.dol_escape_htmltag($row['name']).'</td><td>'.dol_escape_htmltag($row['label']).'</td>';
	print '<td>'.($row['choice'] !== '' ? $langs->trans('VereineCircularChoice_'.$row['choice']) : '<span class="opacitymedium">'.$langs->trans('VereineCircularOpen').'</span>').'</td>';
	print '<td class="nowraponall">'.($row['voted'] > 0 ? dol_print_date($row['voted'], 'dayhour') : '').'</td></tr>';
}
print '</table></div>';

$mine = null;
foreach ($votes as $row) {
	if ($row['member_id'] === (int) $user->fk_member) {
		$mine = $row;
	}
}
if ($circular['status'] === VereineCircularRules::STATUS_OPEN && $mine !== null && $mine['voted'] === 0) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$circular['id'].'" name="vereinecircularvote" class="paddingtop">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="vote">';
	print '<span class="paddingright">'.$langs->trans('VereineCircularYourVote').'</span>';
	foreach (VereineCircularRules::CHOICES as $choice) {
		if ($choice === VereineCircularRules::CHOICE_OBJECTION && empty($rules['circular_no_objection'])) {
			continue;
		}
		print '<label class="paddingright"><input type="radio" name="choice" value="'.$choice.'"'.($choice === VereineCircularRules::CHOICE_YES ? ' checked' : '').'> ';
		print $langs->trans('VereineCircularChoice_'.$choice).'</label>';
	}
	print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineCircularVote')).'">';
	print '</form>';
}

if ($canWrite && $circular['status'] === VereineCircularRules::STATUS_OPEN) {
	print '<div class="center paddingtop">';
	$buttons = array('remind' => 'VereineCircularRemind', 'cancel' => 'VereineCircularCancel');
	if ($ready) {
		$buttons = array('close' => 'VereineCircularClose') + $buttons;
	}
	foreach ($buttons as $what => $label) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$circular['id'].'" name="vereinecircular'.$what.'" class="inline-block paddingright">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="'.$what.'">';
		print '<input type="submit" class="button'.($what === 'close' ? ' button-save' : '').'" value="'.dol_escape_htmltag($langs->trans($label)).'">';
		print '</form>';
	}
	print '</div>';
}

llxFooter();
$db->close();
