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
$text = $statutes->text();
// Shown as entered while a refused text form is on the page.
$shownText = $text;
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');


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

if ($action === 'savetext') {
	$wording = explode(':', GETPOST('wording', 'alphanohtml').':');
	$shownText = array('area' => GETPOST('area', 'alphanohtml'), 'admission' => GETPOST('admission', 'alphanohtml'), 'asset_purpose' => GETPOST('asset_purpose', 'restricthtml'),
		'asset_recipient' => GETPOST('asset_recipient', 'restricthtml'), 'activities' => GETPOST('activities', 'restricthtml'), 'funds' => GETPOST('funds', 'restricthtml'),
		'branches' => GETPOSTISSET('branches') ? 1 : 0, 'legal_persons' => GETPOSTISSET('legal_persons') ? 1 : 0, 'arrears_months' => GETPOST('arrears_months', 'alphanohtml'),
		'tax' => $wording[0], 'asset' => $wording[1]);
	$result = $statutes->saveText($shownText, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineStatuteTextSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereinestatutetext');
		exit;
	}
	if ($result < 0) {
		setEventMessages($statutes->error, null, 'errors');
	} else {
		setEventMessages(null, array_map(array($langs, 'trans'), $statutes->errors), 'errors');
	}
	$shownText = VereineStatuteText::normalize($shownText);
} elseif ($action === 'draftpdf') {
	$context = $statutes->context($rules);
	$file = VereineStatutes::directory().'/entwurf-'.dol_print_date(dol_now(), '%Y%m%d-%H%M%S', 'tzserver').'.pdf';
	if ($statutes->buildPdf(VereineStatuteText::sections($rules, $text, $context), $context['name'], $langs->transnoentities('VereineStatuteDraft', vereineFormatDay($today)), $file)) {
		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="'.basename($file).'"');
		header('Content-Length: '.filesize($file));
		readfile($file);
		dol_delete_file($file);
		exit;
	}
	setEventMessages($statutes->error, null, 'errors');
} elseif ($action === 'comparisonpdf') {
	$comparison = $statutes->comparison($today);
	$file = VereineStatutes::directory().'/gegenueberstellung-'.dol_print_date(dol_now(), '%Y%m%d-%H%M%S', 'tzserver').'.pdf';
	if ($comparison['state'] === 'changed' && $statutes->buildComparisonPdf($comparison, $file)) {
		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="'.basename($file).'"');
		header('Content-Length: '.filesize($file));
		readfile($file);
		dol_delete_file($file);
		exit;
	}
	setEventMessages($statutes->error !== '' ? $statutes->error : $langs->trans('VereineStatuteChangeNothing'), null, 'errors');
} elseif ($action === 'saveversion' || $action === 'uploadversion') {
	if ($action === 'saveversion') {
		$result = $statutes->saveVersion(GETPOST('decided_on', 'alpha'), GETPOST('valid_from', 'alpha'), GETPOST('note', 'alphanohtml'), $user);
	} else {
		$uploaded = isset($_FILES['statute_file']['tmp_name']) && is_string($_FILES['statute_file']['tmp_name']) ? $_FILES['statute_file']['tmp_name'] : '';
		$result = $statutes->uploadVersion($uploaded, GETPOST('decided_on', 'alpha'), GETPOST('valid_from', 'alpha'), GETPOST('note', 'alphanohtml'), $user);
	}
	if ($result > 0) {
		setEventMessages($langs->trans('VereineStatuteVersionSaved'), null, 'mesgs');
		if (GETPOSTISSET('notify')) {
			require_once __DIR__.'/../class/vereineauthorityletters.class.php';
			$letters = new VereineAuthorityLetters($db);
			$letter = $letters->create(VereineAuthorityRules::KIND_STATUTES, array('date' => GETPOST('decided_on', 'alpha')), $user, $langs);
			if ($letter > 0) {
				setEventMessages($langs->trans('VereineStatuteNotified'), null, 'mesgs');
			} else {
				setEventMessages($langs->trans('VereineStatuteNotifyFailed', implode(' ', array_map(array($langs, 'trans'), $letters->errors)).$letters->error), null, 'warnings');
			}
		}
		header('Location: '.$_SERVER['PHP_SELF'].'#vereinestatuteversions');
		exit;
	}
	if ($result < 0) {
		setEventMessages($statutes->error, null, 'errors');
	} else {
		setEventMessages(null, array_map(array($langs, 'trans'), $statutes->errors), 'errors');
	}
} elseif ($action === 'download') {
	$file = $statutes->path(GETPOSTINT('id'));
	if ($file === '') {
		accessforbidden();
	}
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
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
print '<tr><td class="titlefieldcreate fieldrequired"><label for="min_age">'.$langs->trans('VereineStatuteMinAge').'</label></td>';
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
print '<tr><td class="titlefieldcreate fieldrequired"><label for="general_years">'.$langs->trans('VereineStatuteGeneralYears').'</label></td>';
print '<td>'.$number('general_years', $shown['general_years'], VereineStatuteRules::GENERAL_MAX_YEARS).' '.$langs->trans('VereineStatuteGeneralYearsUnit').'</td></tr>';
print '<tr><td class="fieldrequired"><label for="invite_days">'.$langs->trans('VereineStatuteInviteDays').'</label></td>';
print '<td>'.$number('invite_days', $shown['invite_days'], VereineStatuteRules::MAX_DAYS).' '.$langs->trans('VereineStatuteDaysBefore').'</td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('VereineStatuteInviteChannels').'</td><td>';
$channels = is_array($shown['invite_channels']) ? $shown['invite_channels'] : array();
foreach (VereineStatuteRules::CHANNELS as $channel) {
	print '<label class="paddingright"><input type="checkbox" name="invite_channels[]" value="'.$channel.'"'.$checked(in_array($channel, $channels, true)).'> ';
	print $langs->trans('VereineStatuteChannel_'.$channel).'</label> ';
}
print '</td></tr>';
print '<tr><td class="fieldrequired"><label for="motion_days">'.$langs->trans('VereineStatuteMotionDays').'</label></td>';
print '<td>'.$number('motion_days', $shown['motion_days'], VereineStatuteRules::MAX_DAYS).' '.$langs->trans('VereineStatuteDaysBefore').'</td></tr>';
print '<tr><td></td><td><label><input type="checkbox" id="proxy" name="proxy" value="1"'.$checked($shown['proxy']).'> '.$langs->trans('VereineStatuteProxy').'</label></td></tr>';
print '<tr><td class="fieldrequired"><label for="general_quorum">'.$langs->trans('VereineStatuteGeneralQuorum').'</label></td>';
print '<td>'.$number('general_quorum', $shown['general_quorum'], 100).' <span class="opacitymedium small">'.$langs->trans('VereineStatuteGeneralQuorumHelp').'</span></td></tr>';
print '<tr><td class="fieldrequired"><label for="statute_majority">'.$langs->trans('VereineStatuteStatuteMajority').'</label></td>';
print '<td>'.$select('statute_majority', VereineStatuteRules::MAJORITIES, 'VereineStatuteMajority_', $shown['statute_majority']).'</td></tr>';
print '<tr><td class="fieldrequired"><label for="dissolution_majority">'.$langs->trans('VereineStatuteDissolutionMajority').'</label></td>';
print '<td>'.$select('dissolution_majority', VereineStatuteRules::MAJORITIES, 'VereineStatuteMajority_', $shown['dissolution_majority']).'</td></tr>';
print '<tr><td class="fieldrequired"><label for="virtual">'.$langs->trans('VereineStatuteVirtual').'</label></td>';
print '<td>'.$select('virtual', VereineStatuteRules::VIRTUALS, 'VereineStatuteVirtual_', $shown['virtual']);
print '<br><span class="opacitymedium small">'.$langs->trans('VereineStatuteVirtualHelp').'</span></td></tr>';
print '</table><br>';

print load_fiche_titre($langs->trans('VereineStatutesBoard'), '', '');
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate fieldrequired"><label for="board_quorum">'.$langs->trans('VereineStatuteBoardQuorum').'</label></td>';
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
print '<br>';

// Text of the statutes.
print load_fiche_titre($langs->trans('VereineStatuteTextTitle'), '', '', 0, 'vereinestatutetext');
print '<div class="info" data-statute-text-howto="1"><ul>';
foreach (array('VereineStatuteTextHowToModel', 'VereineStatuteTextHowToComplete', 'VereineStatuteTextHowToAdvice') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
print '</ul></div>';
// The purpose of the association comes from the tab Association; shown here so it is not mistaken for the purpose of the assets.
$purposeLink = dol_buildpath('/vereine/admin/setup.php', 1).'#VEREINE_PURPOSE';
$associationPurpose = trim(getDolGlobalString('VEREINE_PURPOSE'));
print '<table class="border centpercent" data-association-purpose="'.($associationPurpose !== '' ? 1 : 0).'"><tr><td class="titlefieldcreate tdtop">'.$langs->trans('VereinePurpose').'</td><td>';
print ($associationPurpose !== '' ? nl2br(dol_escape_htmltag($associationPurpose, 0, 1)) : '<span class="opacitymedium">'.$langs->trans('VereineStatutePurposeEmpty').'</span>');
print ' <a href="'.$purposeLink.'">'.img_picto($langs->trans('Modify'), 'edit').' '.$langs->trans('VereineStatutePurposeEdit').'</a>';
print '<div class="opacitymedium small">'.$langs->trans('VereineStatutePurposeHelp').'</div></td></tr></table><br>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#vereinestatutetext" name="vereinestatutetext">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savetext">';
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate"><label for="area">'.$langs->trans('VereineStatuteTextArea').'</label></td>';
print '<td><input type="text" id="area" name="area" class="minwidth300" maxlength="255" value="'.dol_escape_htmltag($shownText['area']).'">';
print ' <span class="opacitymedium small">'.$langs->trans('VereineStatuteTextAreaHelp').'</span></td></tr>';
print '<tr><td></td><td><label><input type="checkbox" name="branches" value="1"'.$checked($shownText['branches']).'> '.$langs->trans('VereineStatuteTextBranches').'</label></td></tr>';
foreach (array('activities' => VereineStatuteText::SUGGESTED_ACTIVITIES, 'funds' => VereineStatuteText::SUGGESTED_FUNDS) as $key => $suggested) {
	print '<tr><td class="tdtop"><label for="'.$key.'">'.$langs->trans('VereineStatuteText_'.$key).'</label></td>';
	print '<td><textarea id="'.$key.'" name="'.$key.'" rows="6" class="centpercent">'.dol_escape_htmltag(implode("\n", $shownText[$key]), 0, 1).'</textarea>';
	print '<div class="opacitymedium small">'.$langs->trans('VereineStatuteTextHelp_'.$key).' '.dol_escape_htmltag(implode('; ', $suggested)).'</div></td></tr>';
}
print '<tr><td><label for="admission">'.$langs->trans('VereineStatuteTextAdmission').'</label></td>';
print '<td><input type="text" id="admission" name="admission" class="minwidth300" maxlength="255" value="'.dol_escape_htmltag($shownText['admission']).'">';
print ' <span class="opacitymedium small">'.$langs->trans('VereineStatuteTextAdmissionHelp').'</span></td></tr>';
print '<tr><td></td><td><label><input type="checkbox" name="legal_persons" value="1"'.$checked($shownText['legal_persons']).'> '.$langs->trans('VereineStatuteTextLegalPersons').'</label></td></tr>';
print '<tr><td class="fieldrequired"><label for="arrears_months">'.$langs->trans('VereineStatuteTextArrears').'</label></td>';
print '<td>'.$number('arrears_months', $shownText['arrears_months'], 24).' '.$langs->trans('VereineStatuteTextArrearsUnit').'</td></tr>';
print '<tr><td class="tdtop fieldrequired"><label for="wording">'.$langs->trans('VereineStatuteTextWording').'</label></td><td><select id="wording" name="wording" class="minwidth300">';
foreach (VereineStatuteText::ASSETS as $tax => $assets) {
	print '<optgroup label="'.dol_escape_htmltag($langs->trans('VereineStatuteTextTax_'.$tax)).'">';
	foreach ($assets as $asset) {
		$selected = $shownText['tax'] === $tax && $shownText['asset'] === (string) $asset;
		print '<option value="'.$tax.':'.$asset.'"'.($selected ? ' selected' : '').'>'.$langs->trans('VereineStatuteTextWording_'.$tax.'_'.$asset).'</option>';
	}
	print '</optgroup>';
}
print '</select><div class="opacitymedium small">'.$langs->trans('VereineStatuteTextWordingHelp').'</div></td></tr>';
print '<tr><td class="tdtop"><label for="asset_purpose">'.$langs->trans('VereineStatuteTextAssetPurpose').'</label></td>';
print '<td><textarea id="asset_purpose" name="asset_purpose" rows="3" class="centpercent" maxlength="1000">'.dol_escape_htmltag($shownText['asset_purpose'], 0, 1).'</textarea></td></tr>';
print '<tr><td class="tdtop"><label for="asset_recipient">'.$langs->trans('VereineStatuteTextAssetRecipient').'</label></td>';
print '<td><textarea id="asset_recipient" name="asset_recipient" rows="2" class="centpercent" maxlength="500">'.dol_escape_htmltag($shownText['asset_recipient'], 0, 1).'</textarea></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div>';
print '</form><br>';

$context = $statutes->context($rules);
$problems = VereineStatuteText::problems($text, $context);
if ($problems) {
	print '<div class="warning" data-text-problems="'.count($problems).'"><ul>';
	foreach ($problems as $problem) {
		print '<li data-text-problem="'.$problem.'">'.$langs->trans('VereineStatuteTextProblem_'.$problem);
		if ($problem === VereineStatuteText::PROBLEM_PURPOSE) {
			print ' <a href="'.$purposeLink.'" data-purpose-link="1">'.$langs->trans('VereineStatutePurposeEdit').'</a>';
		}
		print '</li>';
	}
	print '</ul></div>';
} else {
	print '<div class="ok" data-text-problems="0">'.$langs->trans('VereineStatuteTextComplete').'</div>';
}

// Preview of the statutes as they would be generated now.
print load_fiche_titre($langs->trans('VereineStatutePreview'), '', '', 0, 'vereinestatutepreview');
print '<div class="statute-preview" data-statute-preview="1" style="max-height: 40em; overflow: auto; padding: 1em; border: 1px solid #ddd;">';
print '<h3 class="center">'.dol_escape_htmltag('Statuten des Vereins „'.$context['name'].'“').'</h3>';
foreach (VereineStatuteText::sections($rules, $text, $context) as $section) {
	print '<h4 data-section-number="'.$section['number'].'">§ '.$section['number'].': '.dol_escape_htmltag($section['title']).'</h4>';
	foreach ($section['paragraphs'] as $paragraph) {
		print vereineStatuteParagraphHtml($paragraph);
	}
}
print '</div>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinestatutedraft" class="center paddingtop">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="draftpdf">';
print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineStatuteDraftPdf')).'">';
print '</form><br>';

// Change of the statutes: what differs from the version in force.
print load_fiche_titre($langs->trans('VereineStatuteChange'), '', '', 0, 'vereinestatutechange');
$comparison = $statutes->comparison($today);
print '<div data-statute-change="'.$comparison['state'].'">';
if ($comparison['state'] === 'none') {
	print '<div class="opacitymedium">'.$langs->trans('VereineStatuteChangeNoVersion').'</div>';
} elseif ($comparison['state'] === 'uploaded') {
	print '<div class="opacitymedium">'.$langs->trans('VereineStatuteChangeUploaded', $comparison['version']['version']).'</div>';
} elseif ($comparison['state'] === 'same') {
	print '<div class="ok">'.$langs->trans('VereineStatuteChangeNone', $comparison['version']['version']).'</div>';
} else {
	print '<div class="info"><ul><li>'.$langs->trans('VereineStatuteChangeIntro', $comparison['version']['version']).'</li>';
	print '<li data-change-majority="'.$comparison['majority'].'">'.$langs->trans('VereineStatuteChangeMajority', $langs->trans('VereineStatuteMajority_'.$comparison['majority'])).'</li>';
	print '<li>'.$langs->trans('VereineStatuteChangeResolution').'</li><li>'.$langs->trans('VereineStatuteChangeEffect').'</li></ul></div>';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td class="width50p">'.$langs->trans('VereineStatuteChangeOld').'</td><td>'.$langs->trans('VereineStatuteChangeNew').'</td></tr>';
	foreach ($comparison['changes'] as $change) {
		print '<tr class="oddeven tdtop" data-changed-section="'.dol_escape_htmltag($change['title']).'">';
		foreach (array('old' => 'old_number', 'new' => 'new_number') as $side => $number) {
			print '<td class="tdtop">'.($change[$number] > 0 ? '<strong>§ '.$change[$number].': '.dol_escape_htmltag($change['title']).'</strong>'
				.implode('', array_map('vereineStatuteParagraphHtml', $change[$side])) : '<span class="opacitymedium">-</span>').'</td>';
		}
		print '</tr>';
	}
	print '</table></div>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinestatutecomparison" class="center paddingtop">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="comparisonpdf">';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineStatuteChangePdf')).'">';
	print '</form>';
}
print '</div><br>';

// Versions.
print load_fiche_titre($langs->trans('VereineStatuteVersions'), '', '', 0, 'vereinestatuteversions');
$versions = $statutes->versions();
$current = $statutes->current($today);
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineStatuteVersion').'</td><td>'.$langs->trans('VereineStatuteDecidedOn').'</td><td>'.$langs->trans('VereineStatuteValidFrom').'</td>';
print '<td>'.$langs->trans('VereineStatuteSource').'</td><td>'.$langs->trans('Note').'</td><td></td></tr>';
if (!$versions) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('VereineStatuteNoVersion').'</span></td></tr>';
}
foreach ($versions as $version) {
	$isCurrent = $current !== null && $current['id'] === $version['id'];
	print '<tr class="oddeven" data-statute-version="'.$version['version'].'" data-source="'.$version['source'].'" data-current="'.($isCurrent ? 1 : 0).'">';
	print '<td>'.$version['version'].($isCurrent ? ' '.dolGetBadge($langs->trans('VereineStatuteCurrent'), '', 'success') : '').'</td>';
	print '<td>'.vereineFormatDay($version['decided_on']).'</td><td>'.vereineFormatDay($version['valid_from']).'</td>';
	print '<td>'.$langs->trans('VereineStatuteSource_'.$version['source']).'</td><td>'.dol_escape_htmltag($version['note']).'</td>';
	print '<td class="right"><a href="'.$_SERVER['PHP_SELF'].'?action=download&amp;id='.$version['id'].'&amp;token='.newToken().'">'.img_picto('', 'pdf').' '.dol_escape_htmltag($version['filename']).'</a></td></tr>';
}
print '</table></div><br>';

print '<div class="fichecenter"><div class="fichehalfleft">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#vereinestatuteversions" name="vereinestatuteversion">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="saveversion">';
print '<div class="titre">'.$langs->trans('VereineStatuteSaveVersion').'</div>';
print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineStatuteSaveVersionHelp').'</div>';
print '<table class="border centpercent">';
print '<tr><td class="fieldrequired">'.$langs->trans('VereineStatuteDecidedOn').'</td><td><input type="date" name="decided_on" value=""></td></tr>';
print '<tr><td>'.$langs->trans('VereineStatuteValidFrom').'</td><td><input type="date" name="valid_from" value=""></td></tr>';
print '<tr><td>'.$langs->trans('Note').'</td><td><input type="text" name="note" class="minwidth200" maxlength="255" value=""></td></tr>';
print '<tr><td></td><td><label><input type="checkbox" name="notify" value="1"'.($versions ? ' checked' : '').'> '.$langs->trans('VereineStatuteNotify').'</label></td></tr>';
print '</table><div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineStatuteSaveVersion')).'"></div>';
print '</form></div><div class="fichehalfright">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#vereinestatuteversions" name="vereinestatuteupload" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="uploadversion">';
print '<div class="titre">'.$langs->trans('VereineStatuteUpload').'</div>';
print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineStatuteUploadHelp').'</div>';
print '<table class="border centpercent">';
print '<tr><td class="fieldrequired">'.$langs->trans('VereineStatuteFile').'</td><td><input type="file" name="statute_file" accept="application/pdf"></td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('VereineStatuteDecidedOn').'</td><td><input type="date" name="decided_on" value=""></td></tr>';
print '<tr><td>'.$langs->trans('VereineStatuteValidFrom').'</td><td><input type="date" name="valid_from" value=""></td></tr>';
print '<tr><td>'.$langs->trans('Note').'</td><td><input type="text" name="note" class="minwidth200" maxlength="255" value=""></td></tr>';
print '</table><div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineStatuteUpload')).'"></div>';
print '</form></div></div><div class="clearboth"></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
