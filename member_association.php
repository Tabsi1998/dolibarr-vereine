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
 * \file    member_association.php
 * \ingroup vereine
 * \brief   Tab "Association" on a member: its third party as the module keeps it, open invoices, guardians, log.
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/member.lib.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
require_once __DIR__.'/class/vereinepartnerservice.class.php';
require_once __DIR__.'/class/vereineexits.class.php';
require_once __DIR__.'/class/vereineconsents.class.php';
require_once __DIR__.'/class/vereinefunctions.class.php';
require_once __DIR__.'/class/vereineresolutions.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('companies', 'members', 'bills', 'categories', 'vereine@vereine'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('vereine', 'association', 'read') || !$user->hasRight('adherent', 'lire') || !$user->hasRight('societe', 'lire')) {
	accessforbidden();
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
$result = restrictedArea($user, 'adherent', $id, '', '', 'socid', 'rowid', 0);

$object = new Adherent($db);
if ($id <= 0 || $object->fetch($id) <= 0) {
	accessforbidden('Member not found');
}
$socid = (int) $object->fk_soc;
$partner = null;
if ($socid > 0) {
	$partner = new Societe($db);
	if ($partner->fetch($socid) <= 0) {
		$partner = null;
	}
}
$service = new VereinePartnerService($db);
$canWrite = $user->hasRight('vereine', 'partner', 'write') && $user->hasRight('societe', 'creer');
$exits = new VereineExits($db);
$canExit = $user->hasRight('adherent', 'creer');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$exitBack = $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'#vereineexit';


/*
 * Actions
 */

if ($action === 'apply' && $canWrite && $partner) {
	$result = $service->applyAttributes($object, $user);
	if (is_array($result)) {
		setEventMessages($result['changes'] ? $langs->trans('VereinePartnerApplied', implode(', ', $result['changes'])) : $langs->trans('VereinePartnerNothingToApply'), null, 'mesgs');
		if ($result['mismatch']) {
			setEventMessages($langs->trans('VereinePartnerTypeMismatch'), null, 'warnings');
		}
	} else {
		setEventMessages($service->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
	exit;
}
if ($action === 'planexit' && $canExit) {
	$result = $exits->plan($object, GETPOST('exit_reason', 'aZ09'), GETPOST('exit_notice_day', 'alpha'), GETPOST('exit_last_day', 'alpha'), GETPOST('exit_note', 'alphanohtml'), $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineExitSaved'), null, 'mesgs');
	} elseif ($result < 0) {
		setEventMessages($exits->error, null, 'errors');
	} else {
		setEventMessages(null, array_map(array($langs, 'trans'), $exits->errors), 'errors');
	}
	header('Location: '.$exitBack);
	exit;
}
$functionStore = new VereineFunctions($db);
if (($action === 'addfunction' || $action === 'endfunction') && $canExit) {
	if ($action === 'addfunction') {
		$result = $functionStore->addTerm($object, GETPOSTINT('function_id'), GETPOST('function_start', 'alpha'), GETPOST('function_end', 'alpha'), GETPOST('function_note', 'alphanohtml'), $user);
	} else {
		$result = 0;
		foreach ($functionStore->terms((int) $object->id) as $term) {
			if ($term['id'] === GETPOSTINT('term_id')) {
				$result = $functionStore->endTerm($term['id'], GETPOST('function_end', 'alpha'), $user);
			}
		}
	}
	if ($result > 0) {
		setEventMessages($langs->trans('VereineFunctionTermSaved'), null, 'mesgs');
	} elseif ($result < 0) {
		setEventMessages($functionStore->error, null, 'errors');
	} else {
		setEventMessages(null, array_map(array($langs, 'trans'), $functionStore->errors ?: array('VereineFunctionErrorTerm')), 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'#vereinefunctions');
	exit;
}
$consents = new VereineConsents($db);
if (($action === 'recordconsent' || $action === 'withdrawconsent') && $canExit) {
	$code = GETPOST('consent_code', 'aZ09');
	$history = VereineConsentRules::current($consents->history((int) $object->id));
	$texts = $consents->currentTexts();
	$source = GETPOST('consent_source', 'aZ09') === 'member_card' ? 'member_card' : 'paper';
	$result = 0;
	if ($action === 'recordconsent' && isset($texts[$code])) {
		$result = $consents->record((int) $object->id, $code, $texts[$code]['version'], true, $source, GETPOST('consent_note', 'alphanohtml'), $user);
	} elseif ($action === 'withdrawconsent' && isset($history[$code]) && $history[$code]['given']) {
		$result = $consents->record((int) $object->id, $code, $history[$code]['version'], false, $source, GETPOST('consent_note', 'alphanohtml'), $user);
	}
	if ($result < 0) {
		setEventMessages($consents->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'#vereineconsents');
	exit;
}
if (($action === 'cancelexit' || $action === 'carryoutexit') && $canExit) {
	foreach ($exits->planned(array((int) $object->id)) as $exit) {
		if ($exit['id'] !== GETPOSTINT('exit_id')) {
			continue;
		}
		if ($action === 'cancelexit') {
			$result = $exits->cancel($exit['id'], $user);
		} else {
			$result = VereineExitRules::isDue($exit['last_day'], $today) ? $exits->carryOut($exit, $user) : 0;
		}
		if ($result < 0) {
			setEventMessages($exits->error, null, 'errors');
		}
	}
	header('Location: '.$exitBack);
	exit;
}


/*
 * View
 */

$title = $langs->trans('VereineTabAssociation').' - '.$object->getFullName($langs);
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-member-association');

$head = member_prepare_head($object);
print dol_get_fiche_head($head, 'vereineassociation', $langs->trans('Member'), -1, 'user');
$linkback = '<a href="'.DOL_URL_ROOT.'/adherents/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
dol_banner_tab($object, 'rowid', $linkback);

print '<div class="fichecenter"><div class="underbanner clearboth"></div>';

if (!$partner) {
	print '<div class="opacitymedium" data-association="none">'.$langs->trans('VereineMemberNoPartner').'</div>';
	print '<br><a href="'.dol_buildpath('/vereine/partners.php', 1).'">'.$langs->trans('VereinePartnerOpenReconciliation').'</a>';
} else {
	// What does not fit, as the reconciliation page sees it.
	$problems = array();
	$report = $service->report(dol_print_date(dol_now(), '%Y-%m-%d'));
	foreach ($report['attributes'] as $row) {
		if ($row['member']['id'] === (int) $object->id) {
			foreach ($row['problems'] as $problem) {
				$problems[] = $langs->transnoentitiesnoconv($problem);
			}
			if ($row['type_mismatch']) {
				$problems[] = $langs->transnoentitiesnoconv('VereinePartnerTypeMismatchShort', $row['partner']['typent_code']);
			}
		}
	}
	foreach ($report['differences'] as $row) {
		if ($row['member']['id'] === (int) $object->id) {
			foreach ($row['fields'] as $field => $values) {
				$problems[] = $langs->transnoentitiesnoconv('VereineField_'.$field).': '.$values['partner'].' → '.$values['member'];
			}
		}
	}
	$categories = new Categorie($db);
	$labels = $categories->containing((int) $partner->id, 'customer', 'label');
	$typeCode = (int) $partner->typent_id > 0 ? (string) dol_getIdFromCode($db, (int) $partner->typent_id, 'c_typent', 'id', 'code') : '';
	$typeLabel = $typeCode !== '' ? $langs->trans($typeCode) : '';

	print '<div class="div-table-responsive-no-min">';
	print '<table class="border centpercent tableforfield" data-association="'.((int) $partner->id).'">';
	print '<tr><td class="titlefield">'.$langs->trans('VereinePartnerColumn').'</td><td>'.$partner->getNomUrl(1).'</td></tr>';
	print '<tr><td>'.$langs->trans('Categories').'</td><td data-partner-categories="1">'.dol_escape_htmltag(is_array($labels) ? implode(', ', $labels) : '').'</td></tr>';
	print '<tr><td>'.$langs->trans('VereinePartnerCustomerFlag').'</td><td>'.yn(in_array((int) $partner->client, array(1, 3), true) ? 1 : 0).'</td></tr>';
	print '<tr><td>'.$langs->trans('VereinePartnerCustomerType').'</td><td>'.($typeLabel !== '' ? dol_escape_htmltag($typeLabel) : '<span class="opacitymedium">'.$langs->trans('VereineNotSet').'</span>').'</td></tr>';
	print '<tr><td>'.$langs->trans('VereinePartnerProblems').'</td><td data-problems="'.count($problems).'">';
	if ($problems) {
		print dol_escape_htmltag(implode(', ', $problems));
	} else {
		print '<span class="opacitymedium">'.$langs->trans('VereinePartnerInLine').'</span>';
	}
	print '</td></tr>';
	print '</table>';
	print '</div>';

	if ($canWrite) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'" name="vereineapply">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="apply">';
		print '<div class="right"><input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereinePartnerApply')).'"></div>';
		print '</form>';
	}

	vereinePrintOpenInvoices($db, (int) $partner->id);
	vereinePrintGuardians($db, (int) $partner->id);
}

// Exit: reason, notice and last day; Dolibarr's status changes on the last day.
print load_fiche_titre($langs->trans('VereineExitTitle'), '', '', 0, 'vereineexit');
$exitRule = $exits->rule();
$planned = $exits->planned(array((int) $object->id));
if ($planned) {
	$exit = current($planned);
	print '<div class="warning" data-exit="planned" data-last-day="'.dol_escape_htmltag($exit['last_day']).'">';
	print $langs->trans('VereineExitPlannedNote', $langs->trans('VereineExitReason_'.$exit['reason']), vereineFormatDay($exit['last_day']));
	if ($exit['note'] !== '') {
		print '<br>'.dol_escape_htmltag($exit['note']);
	}
	print '</div>';
	if (!isModEnabled('cron')) {
		print info_admin($langs->trans('VereineExitCronOff'), 0, 0, '1', 'warning');
	}
	if ($canExit) {
		foreach (VereineExitRules::isDue($exit['last_day'], $today) ? array('carryoutexit', 'cancelexit') : array('cancelexit') as $exitAction) {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'" name="vereine'.$exitAction.'" class="inline-block">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="'.$exitAction.'">';
			print '<input type="hidden" name="exit_id" value="'.((int) $exit['id']).'">';
			print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv($exitAction === 'cancelexit' ? 'VereineExitCancel' : 'VereineExitCarryOut')).'"> ';
			print '</form>';
		}
	}
} elseif ((int) $object->statut === 1 && $canExit) {
	print '<span class="opacitymedium" data-exit-rule="1">'.$langs->trans('VereineExitRuleToday', vereineExitRuleText($exitRule), vereineFormatDay((string) VereineExitRules::lastDay($today, $exitRule))).'</span>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'" name="vereineplanexit">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="planexit">';
	$reasons = array();
	foreach (VereineExitRules::REASONS as $reason) {
		$reasons[$reason] = $langs->trans('VereineExitReason_'.$reason);
	}
	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired"><label for="exit_reason">'.$langs->trans('VereineExitReason').'</label></td><td>'.Form::selectarray('exit_reason', $reasons, VereineExitRules::REASON_RESIGNATION, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';
	print '<tr><td><label for="exit_notice_day">'.$langs->trans('VereineExitNoticeDay').'</label></td><td><input type="date" id="exit_notice_day" name="exit_notice_day" value="'.$today.'"></td></tr>';
	$suggestedLastDay = (string) VereineExitRules::lastDay($today, $exitRule);
	print '<tr><td class="fieldrequired"><label for="exit_last_day">'.$langs->trans('VereineExitLastDay').'</label></td>';
	print '<td><input type="date" id="exit_last_day" name="exit_last_day" value="'.dol_escape_htmltag($suggestedLastDay !== '' ? $suggestedLastDay : $today).'">';
	print ' <span class="opacitymedium small">'.$langs->trans('VereineExitLastDayHelp').'</span></td></tr>';
	print '<tr><td><label for="exit_note">'.$langs->trans('VereineExitNote').'</label></td><td><input type="text" id="exit_note" name="exit_note" class="minwidth300" maxlength="255"></td></tr>';
	print '</table>';
	print '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('VereineExitPlan')).'"></div>';
	print '</form>';
} else {
	print '<span class="opacitymedium" data-exit="none">'.$langs->trans((int) $object->statut === 1 ? 'VereineExitNoRight' : 'VereineExitNotActive').'</span>';
}
foreach ($exits->fetchAll(array((int) $object->id)) as $past) {
	if ($past['status'] !== VereineExits::STATUS_PLANNED) {
		print '<div class="opacitymedium small" data-exit="'.dol_escape_htmltag($past['status']).'">'.$langs->trans('VereineExitPast_'.$past['status'], $langs->trans('VereineExitReason_'.$past['reason']), vereineFormatDay($past['last_day'])).'</div>';
	}
}
print '<br>';

// Functions: terms of office of the member, running and ended.
print load_fiche_titre($langs->trans('VereineFunctionsMemberTitle'), '', '', 0, 'vereinefunctions');
$catalogue = array();
foreach ($functionStore->fetchAll() as $function) {
	$catalogue[$function['id']] = $function;
}
$memberTerms = $functionStore->terms((int) $object->id);
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineFunctionLabel').'</td><td>'.$langs->trans('DateStart').'</td><td>'.$langs->trans('DateEnd').'</td>';
print '<td>'.$langs->trans('VereineExitNote').'</td><td></td></tr>';
if (!$memberTerms) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('VereineFunctionsMemberNone').'</span></td></tr>';
}
foreach ($memberTerms as $term) {
	$function = isset($catalogue[$term['function_id']]) ? $catalogue[$term['function_id']] : array('code' => '', 'label' => '#'.$term['function_id']);
	$running = VereineFunctionRules::isActive($term, $today);
	print '<tr class="oddeven" data-term="'.dol_escape_htmltag($function['code']).'" data-running="'.($running ? 1 : 0).'" data-end="'.dol_escape_htmltag($term['end']).'">';
	print '<td>'.dol_escape_htmltag($function['label']).'</td><td class="nowraponall">'.vereineFormatDay($term['start']).'</td>';
	print '<td class="nowraponall">'.($term['end'] !== '' ? vereineFormatDay($term['end']) : '<span class="opacitymedium">'.$langs->trans('VereineFunctionOpen').'</span>').'</td>';
	print '<td class="small">'.dol_escape_htmltag($term['note']).'</td><td class="right nowraponall">';
	if ($canExit && $term['end'] === '') {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'" name="vereineendfunction" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="endfunction">';
		print '<input type="hidden" name="term_id" value="'.((int) $term['id']).'">';
		print '<input type="date" name="function_end" value="'.dol_escape_htmltag(max($today, $term['start'])).'"> ';
		print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('VereineFunctionEnd')).'">';
		print '</form>';
	}
	print '</td></tr>';
}
print '</table></div>';
if ($canExit && (int) $object->statut === 1) {
	$options = array();
	foreach ($catalogue as $function) {
		if ($function['active']) {
			$options[$function['id']] = $function['label'];
		}
	}
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'" name="vereineaddfunction">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="addfunction">';
	print Form::selectarray('function_id', $options, 0, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print ' <label for="function_start" class="fieldrequired">'.$langs->trans('DateStart').'</label> <input type="date" id="function_start" name="function_start" value="'.$today.'">';
	print ' <label for="function_end">'.$langs->trans('DateEnd').'</label> <input type="date" id="function_end" name="function_end" value="">';
	print ' <input type="text" name="function_note" class="minwidth200" maxlength="255" placeholder="'.dol_escape_htmltag($langs->transnoentitiesnoconv('VereineFunctionNoteHelp')).'">';
	print ' <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('VereineFunctionAdd')).'">';
	print '</form>';
}
print '<br>';

// Consents: the current state per purpose with the version agreed to, and every change.
print load_fiche_titre($langs->trans('VereineConsentTitle'), '', '', 0, 'vereineconsents');
$consentTexts = $consents->texts();
$labels = array();
foreach ($consentTexts as $text) {
	$labels[$text['code'].':'.$text['version']] = $text['label'];
	if (!isset($labels[$text['code']])) {
		$labels[$text['code']] = $text['label'];
	}
}
$consentHistory = $consents->history((int) $object->id);
$currentConsents = VereineConsentRules::current($consentHistory);
$offered = $consents->currentTexts();
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineConsentLabel').'</td><td>'.$langs->trans('Status').'</td><td class="center">'.$langs->trans('VereineConsentVersion').'</td>';
print '<td>'.$langs->trans('Date').'</td><td>'.$langs->trans('VereineConsentSource').'</td><td></td></tr>';
if (!$currentConsents && !$offered) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('VereineConsentNoTexts').'</span></td></tr>';
}
foreach (array_unique(array_merge(array_keys($currentConsents), array_keys($offered))) as $code) {
	$event = isset($currentConsents[$code]) ? $currentConsents[$code] : null;
	$state = $event === null ? 'none' : ($event['given'] ? 'given' : 'withdrawn');
	print '<tr class="oddeven" data-member-consent="'.dol_escape_htmltag($code).'" data-state="'.$state.'" data-version="'.($event ? $event['version'] : '').'">';
	print '<td>'.dol_escape_htmltag($event && isset($labels[$code.':'.$event['version']]) ? $labels[$code.':'.$event['version']] : (isset($labels[$code]) ? $labels[$code] : $code)).'</td>';
	print '<td>'.$langs->trans('VereineConsentState_'.$state).'</td>';
	print '<td class="center">'.($event ? $event['version'] : '').'</td>';
	print '<td class="nowraponall">'.($event ? dol_print_date($event['moment'], 'dayhour') : '').'</td>';
	print '<td>'.($event ? $langs->trans('VereineConsentSource_'.$event['source']) : '').'</td><td class="right">';
	if ($canExit && ($state === 'given' || isset($offered[$code]))) {
		$consentAction = $state === 'given' ? 'withdrawconsent' : 'recordconsent';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'" name="vereine'.$consentAction.'" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="'.$consentAction.'">';
		print '<input type="hidden" name="consent_code" value="'.dol_escape_htmltag($code).'">';
		print '<input type="hidden" name="consent_source" value="paper">';
		print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv($state === 'given' ? 'VereineConsentWithdraw' : 'VereineConsentRecordPaper')).'">';
		print '</form>';
	}
	print '</td></tr>';
}
print '</table></div>';
print '<div class="paddingtop"><a href="'.dol_buildpath('/vereine/consents.php', 1).'?member='.((int) $object->id).'">'.$langs->trans('VereineConsentFormLink').'</a></div>';
if (count($consentHistory) > count($currentConsents)) {
	foreach ($consentHistory as $event) {
		print '<div class="opacitymedium small" data-consent-event="'.dol_escape_htmltag($event['code']).'">'.dol_print_date($event['moment'], 'dayhour').': ';
		print $langs->trans($event['given'] ? 'VereineConsentEventGiven' : 'VereineConsentEventWithdrawn', dol_escape_htmltag(isset($labels[$event['code']]) ? $labels[$event['code']] : $event['code']), $event['version'],
			$langs->trans('VereineConsentSource_'.$event['source'])).'</div>';
	}
}
print '<br>';

// Resolutions that concern the member: admission, honour, exclusion, an election.
print load_fiche_titre($langs->trans('VereineResolutionMemberTitle'), '', '', 0, 'vereineresolutions');
$register = new VereineResolutions($db);
$memberResolutions = $register->forMember((int) $object->id);
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineResolutionRef').'</td><td>'.$langs->trans('VereineMeetingWhen').'</td>';
print '<td>'.$langs->trans('VereineResolutionTitleColumn').'</td><td>'.$langs->trans('VereineResolutionCategory').'</td>';
print '<td>'.$langs->trans('VereineResolutionResult').'</td></tr>';
if (!$memberResolutions) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('VereineResolutionMemberNone').'</span></td></tr>';
}
foreach ($memberResolutions as $entry) {
	print '<tr class="oddeven" data-member-resolution="'.$entry['id'].'" data-passed="'.($entry['passed'] ? 1 : 0).'">';
	print '<td><a href="'.dol_buildpath('/vereine/resolutions.php', 1).'?id='.$entry['id'].'">'.dol_escape_htmltag($entry['ref']).'</a></td>';
	print '<td class="nowraponall">'.vereineFormatDay($entry['day']).'</td><td>'.dol_escape_htmltag($entry['title']).'</td>';
	print '<td>'.$langs->trans('VereineResolutionCategory_'.$entry['category']).'</td>';
	print '<td>'.$langs->trans($entry['passed'] ? 'VereineResolutionPassed' : 'VereineResolutionRejected').'</td></tr>';
}
print '</table></div><br>';

vereinePrintLog($db, (int) $object->id, $partner ? (int) $partner->id : 0);

print '</div>';
print dol_get_fiche_end();

llxFooter();
$db->close();
