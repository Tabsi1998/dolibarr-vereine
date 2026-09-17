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
 * \file    admin/statutes.php
 * \ingroup vereine
 * \brief   What the statutes lay down for membership, general assembly, board and terms of office.
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
require_once __DIR__.'/../lib/vereine.lib.php';
require_once __DIR__.'/../class/vereinestatutes.class.php';
require_once __DIR__.'/../class/vereinefunctions.class.php';
require_once __DIR__.'/../class/vereinefeemodel.class.php';
require_once __DIR__.'/../class/vereineexits.class.php';

$langs->loadLangs(array('admin', 'members', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$statutes = new VereineStatutes($db);
$functionStore = new VereineFunctions($db);
$functions = $functionStore->fetchAll(true);

$rules = $statutes->rules();
// Shown as entered while a refused form is on the page.
$shown = $rules;
$termYears = array();
foreach ($functions as $function) {
	$termYears[$function['id']] = (string) $function['term_years'];
}


/*
 * Actions
 */

if ($action === 'saverules') {
	$shown = array('proxy' => GETPOSTISSET('proxy') ? 1 : 0, 'board_tie_chair' => GETPOSTISSET('board_tie_chair') ? 1 : 0,
		'invite_channels' => GETPOST('invite_channels', 'array'), 'voting_types' => GETPOST('voting_types', 'array'));
	foreach (array('min_age', 'general_years', 'invite_days', 'motion_days', 'general_quorum', 'board_quorum') as $key) {
		$shown[$key] = GETPOST($key, 'alphanohtml');
	}
	foreach (array('statute_majority', 'dissolution_majority', 'virtual') as $key) {
		$shown[$key] = GETPOST($key, 'aZ09');
	}
	$entered = GETPOST('term_years', 'array');
	foreach ($termYears as $functionId => $years) {
		$termYears[$functionId] = isset($entered[$functionId]) && is_scalar($entered[$functionId]) ? trim((string) $entered[$functionId]) : $years;
	}
	$result = $statutes->save($shown, $termYears, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineStatutesSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	if ($result < 0) {
		setEventMessages($statutes->error, null, 'errors');
	} else {
		setEventMessages(null, array_map(array($langs, 'trans'), array_unique($statutes->errors)), 'errors');
	}
}


/*
 * View
 */

$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-statutes');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = vereineAdminPrepareHead();
print dol_get_fiche_head($head, 'statutes', $title, -1, 'fa-landmark');

print '<div class="info" data-statutes-howto="1" data-statutes-stored="'.($statutes->stored() ? 1 : 0).'"><ul>';
foreach (array('VereineStatutesHowToWhere', 'VereineStatutesHowToUse', 'VereineStatutesHowToAdvice') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
if (!$statutes->stored()) {
	print '<li><strong>'.$langs->trans('VereineStatutesNotStored').'</strong></li>';
}
print '</ul></div>';

$number = function ($name, $value, $max) {
	return '<input type="number" min="0" max="'.$max.'" step="1" id="'.$name.'" name="'.$name.'" class="width50 right" value="'.dol_escape_htmltag((string) $value).'">';
};
$select = function ($name, array $options, $prefix, $value) use ($langs) {
	$html = '<select id="'.$name.'" name="'.$name.'" class="minwidth300">';
	foreach ($options as $option) {
		$html .= '<option value="'.$option.'"'.((string) $value === $option ? ' selected' : '').'>'.$langs->trans($prefix.$option).'</option>';
	}
	return $html.'</select>';
};
$checked = function ($value) {
	return !empty($value) ? ' checked' : '';
};

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinestatutes" id="vereinestatutes">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="saverules">';

print load_fiche_titre($langs->trans('VereineStatutesMembership'), '', '');
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate"><label for="min_age">'.$langs->trans('VereineStatuteMinAge').'</label></td>';
print '<td>'.$number('min_age', $shown['min_age'], 99).' <span class="opacitymedium small">'.$langs->trans('VereineStatuteMinAgeHelp').'</span></td></tr>';
print '<tr><td>'.$langs->trans('VereineStatuteVotingTypes').'</td><td>';
$feeModel = new VereineFeeModel($db);
$voting = array_map('intval', is_array($shown['voting_types']) ? $shown['voting_types'] : array());
foreach ($feeModel->memberTypes(true) as $type) {
	print '<label class="paddingright" data-voting-type="'.$type['id'].'"><input type="checkbox" name="voting_types[]" value="'.$type['id'].'"'.$checked(in_array($type['id'], $voting, true)).'> ';
	print dol_escape_htmltag($type['label']).'</label> ';
}
print '<br><span class="opacitymedium small">'.$langs->trans('VereineStatuteVotingTypesHelp').'</span></td></tr>';
$exits = new VereineExits($db);
print '<tr><td>'.$langs->trans('VereineStatuteExit').'</td><td data-exit-rule="1">'.vereineExitRuleText($exits->rule());
print ' <a class="small" href="'.dol_buildpath('/vereine/admin/fees.php', 1).'#vereineexitrule">'.$langs->trans('VereineStatuteExitHelp').'</a></td></tr>';
print '</table><br>';

print load_fiche_titre($langs->trans('VereineStatutesGeneral'), '', '');
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate"><label for="general_years">'.$langs->trans('VereineStatuteGeneralYears').'</label></td>';
print '<td>'.$number('general_years', $shown['general_years'], VereineStatuteRules::GENERAL_MAX_YEARS).' '.$langs->trans('VereineStatuteGeneralYearsUnit').'</td></tr>';
print '<tr><td><label for="invite_days">'.$langs->trans('VereineStatuteInviteDays').'</label></td>';
print '<td>'.$number('invite_days', $shown['invite_days'], VereineStatuteRules::MAX_DAYS).' '.$langs->trans('VereineStatuteDaysBefore').'</td></tr>';
print '<tr><td>'.$langs->trans('VereineStatuteInviteChannels').'</td><td>';
$channels = is_array($shown['invite_channels']) ? $shown['invite_channels'] : array();
foreach (VereineStatuteRules::CHANNELS as $channel) {
	print '<label class="paddingright"><input type="checkbox" name="invite_channels[]" value="'.$channel.'"'.$checked(in_array($channel, $channels, true)).'> ';
	print $langs->trans('VereineStatuteChannel_'.$channel).'</label> ';
}
print '</td></tr>';
print '<tr><td><label for="motion_days">'.$langs->trans('VereineStatuteMotionDays').'</label></td>';
print '<td>'.$number('motion_days', $shown['motion_days'], VereineStatuteRules::MAX_DAYS).' '.$langs->trans('VereineStatuteDaysBefore').'</td></tr>';
print '<tr><td></td><td><label><input type="checkbox" id="proxy" name="proxy" value="1"'.$checked($shown['proxy']).'> '.$langs->trans('VereineStatuteProxy').'</label></td></tr>';
print '<tr><td><label for="general_quorum">'.$langs->trans('VereineStatuteGeneralQuorum').'</label></td>';
print '<td>'.$number('general_quorum', $shown['general_quorum'], 100).' <span class="opacitymedium small">'.$langs->trans('VereineStatuteGeneralQuorumHelp').'</span></td></tr>';
print '<tr><td><label for="statute_majority">'.$langs->trans('VereineStatuteStatuteMajority').'</label></td>';
print '<td>'.$select('statute_majority', VereineStatuteRules::MAJORITIES, 'VereineStatuteMajority_', $shown['statute_majority']).'</td></tr>';
print '<tr><td><label for="dissolution_majority">'.$langs->trans('VereineStatuteDissolutionMajority').'</label></td>';
print '<td>'.$select('dissolution_majority', VereineStatuteRules::MAJORITIES, 'VereineStatuteMajority_', $shown['dissolution_majority']).'</td></tr>';
print '<tr><td><label for="virtual">'.$langs->trans('VereineStatuteVirtual').'</label></td>';
print '<td>'.$select('virtual', VereineStatuteRules::VIRTUALS, 'VereineStatuteVirtual_', $shown['virtual']);
print '<br><span class="opacitymedium small">'.$langs->trans('VereineStatuteVirtualHelp').'</span></td></tr>';
print '</table><br>';

print load_fiche_titre($langs->trans('VereineStatutesBoard'), '', '');
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate"><label for="board_quorum">'.$langs->trans('VereineStatuteBoardQuorum').'</label></td>';
print '<td>'.$number('board_quorum', $shown['board_quorum'], 100).' '.$langs->trans('VereineStatuteBoardQuorumUnit').'</td></tr>';
print '<tr><td></td><td><label><input type="checkbox" id="board_tie_chair" name="board_tie_chair" value="1"'.$checked($shown['board_tie_chair']).'> ';
print $langs->trans('VereineStatuteBoardTieChair').'</label></td></tr>';
print '</table><br>';

print load_fiche_titre($langs->trans('VereineStatutesTerms'), '', '');
print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineStatutesTermsHelp').'</div>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineFunctionLabel').'</td><td>'.$langs->trans('VereineStatuteTermYears').'</td></tr>';
foreach ($functions as $function) {
	print '<tr class="oddeven" data-term-function="'.dol_escape_htmltag($function['code']).'"><td><label for="term_years_'.$function['id'].'">'.dol_escape_htmltag($function['label']).'</label></td>';
	print '<td><input type="number" min="0" max="'.VereineStatuteRules::TERM_MAX_YEARS.'" step="1" id="term_years_'.$function['id'].'" name="term_years['.$function['id'].']"';
	print ' class="width50 right" value="'.dol_escape_htmltag($termYears[$function['id']]).'"></td></tr>';
}
print '</table></div>';

print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div>';
print '</form><br>';

print load_fiche_titre($langs->trans('VereineStatutesCheck'), '', '');
$hints = VereineStatuteRules::hints($rules, $functions);
if ($hints) {
	$byId = array();
	foreach ($functions as $function) {
		$byId[$function['id']] = $function;
	}
	print '<div class="warning" data-hints="'.count($hints).'"><ul>';
	foreach ($hints as $hint) {
		$function = $byId[$hint['function_id']];
		print '<li data-hint="'.$hint['kind'].'" data-function="'.dol_escape_htmltag($function['code']).'">';
		print $langs->trans('VereineStatuteHint_'.$hint['kind'], dol_escape_htmltag($function['label']), $function['term_years'], $rules['general_years']).'</li>';
	}
	print '</ul></div>';
} else {
	print '<div class="ok" data-hints="0">'.$langs->trans('VereineStatutesAllFine').'</div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
