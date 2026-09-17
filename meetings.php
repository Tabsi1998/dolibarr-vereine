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
 * \file    meetings.php
 * \ingroup vereine
 * \brief   Meetings of the board and general assemblies: plan, check the recipients, invite.
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

require_once __DIR__.'/class/vereinemeetings.class.php';
require_once __DIR__.'/class/vereineminutes.class.php';
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
$meetings = new VereineMeetings($db);
$minutes = new VereineMinutes($db);
$signatures = new VereineSignatures($db);
$statutes = new VereineStatutes($db);
$rules = $statutes->rules();
$canWrite = $user->hasRight('adherent', 'creer');
$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$entered = null;


/*
 * Actions
 */

if ($action === 'savemeeting' && $canWrite) {
	$entered = array();
	foreach (array('kind', 'title', 'day', 'time', 'place', 'format') as $key) {
		$entered[$key] = GETPOST($key, 'alphanohtml');
	}
	foreach (array('access', 'agenda') as $key) {
		$entered[$key] = GETPOST($key, 'restricthtml');
	}
	$result = $meetings->save($id, $entered, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineMeetingSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$result);
		exit;
	}
	if ($result < 0) {
		setEventMessages($meetings->error, null, 'errors');
	} else {
		setEventMessages(null, array_map(array($langs, 'trans'), $meetings->errors), 'errors');
	}
	$entered = VereineMeetingRules::normalize($entered);
} elseif ($action === 'invite' && $canWrite) {
	if (!GETPOSTISSET('checked')) {
		setEventMessages($langs->trans('VereineMeetingErrorNotChecked'), null, 'errors');
	} else {
		$result = $meetings->invite($id, $user, $langs);
		if ($result > 0) {
			setEventMessages($langs->trans('VereineMeetingInvited', $result), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
			exit;
		}
		if ($result < 0) {
			setEventMessages($meetings->error, null, 'errors');
		} else {
			setEventMessages(null, array_map(array($langs, 'trans'), $meetings->errors), 'errors');
		}
	}
} elseif (($action === 'held' || $action === 'cancel') && $canWrite) {
	$result = $meetings->setStatus($id, $action === 'held' ? VereineMeetingRules::STATUS_HELD : VereineMeetingRules::STATUS_CANCELLED, $user);
	if ($result <= 0) {
		setEventMessages($result < 0 ? $meetings->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $meetings->errors), 'errors');
	} else {
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
		exit;
	}
} elseif ($action === 'saveattendance' && $canWrite) {
	$result = $meetings->saveAttendance($id, (array) GETPOST('attendance', 'array'), $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineAttendanceSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id.'#vereineattendance');
		exit;
	}
	if ($result < 0) {
		setEventMessages($meetings->error, null, 'errors');
	} else {
		$names = $meetings->attendance($id)['names'];
		$messages = array_map(array($langs, 'trans'), $meetings->errors);
		foreach ($meetings->rowErrors as $memberId => $keys) {
			foreach ($keys as $key) {
				$messages[] = $langs->trans('VereineAttendanceErrorRow', isset($names[$memberId]) ? $names[$memberId] : $memberId, $langs->transnoentitiesnoconv($key));
			}
		}
		setEventMessages(null, $messages, 'errors');
	}
} elseif ($action === 'savevote' && $canWrite) {
	$entered = array();
	foreach (array('kind', 'item', 'title', 'yes', 'no', 'abstain', 'tie', 'time', 'function_id', 'candidate_id') as $key) {
		$entered[$key] = GETPOST($key, 'alphanohtml');
	}
	$entered['secret'] = GETPOSTISSET('secret');
	$result = $meetings->saveVote($id, $entered, $user, $langs);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineVoteSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id.'#vereinevotes');
		exit;
	}
	if ($result < 0) {
		setEventMessages($meetings->error, null, 'errors');
	} else {
		setEventMessages(null, array_map(array($langs, 'trans'), $meetings->errors), 'errors');
	}
	$entered = null;
} elseif ($action === 'savenotes' && $canWrite) {
	$result = $meetings->saveNotes($id, (array) GETPOST('note', 'array:restricthtml'), $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineMinutesSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id.'#vereinemeetingnotes');
		exit;
	}
	setEventMessages($result < 0 ? $meetings->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $meetings->errors), 'errors');
} elseif ($action === 'saveroles' && $canWrite) {
	$result = $minutes->saveRoles($id, GETPOSTINT('chair'), GETPOSTINT('keeper'), $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineMinutesRolesSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id.'#vereinemeetingminutes');
		exit;
	}
	setEventMessages($result < 0 ? $minutes->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $minutes->errors), 'errors');
} elseif ($action === 'draft' && $canWrite) {
	$meeting = $meetings->fetch($id);
	$file = $meeting === null ? '' : $minutes->buildDraft($meeting, $langs);
	if ($file === '') {
		setEventMessages($minutes->error !== '' ? $minutes->error : $langs->trans('VereineMinutesErrorMeeting'), null, 'errors');
	} else {
		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="'.basename($file).'"');
		header('Content-Length: '.filesize($file));
		readfile($file);
		exit;
	}
} elseif ($action === 'finalize' && $canWrite) {
	$result = $minutes->finalize($id, GETPOST('approved_on', 'alphanohtml'), GETPOST('note', 'alphanohtml'), $user, $langs);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineMinutesFinalized'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id.'#vereinemeetingminutes');
		exit;
	}
	setEventMessages($result < 0 ? $minutes->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $minutes->errors), 'errors');
} elseif ($action === 'minutes') {
	$version = $minutes->version(GETPOSTINT('version'));
	$file = $version !== null ? VereineMinutes::path($version) : '';
	if ($file === '') {
		accessforbidden();
	}
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
} elseif ($action === 'sendminutes' && $canWrite) {
	$result = $minutes->send(GETPOSTINT('version'), GETPOST('audience', 'aZ09'), $user, $langs);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineMinutesSent', $result), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id.'#vereinemeetingminutes');
		exit;
	}
	setEventMessages($result < 0 ? $minutes->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $minutes->errors ? $minutes->errors : array('VereineMinutesErrorNobody')), 'errors');
} elseif ($action === 'sheet' || $action === 'signed') {
	$run = $signatures->fetch(GETPOSTINT('signature'));
	$file = '';
	if ($run !== null && $run['kind'] === VereineSignatureRules::KIND_MINUTES) {
		$file = $action === 'sheet' ? VereineSignatures::sheetPath($run['id']) : VereineSignatures::scanPath($run);
	}
	if ($file === '' || !is_file($file)) {
		accessforbidden();
	}
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
} elseif ($action === 'startsign' && $canWrite) {
	$version = $minutes->version(GETPOSTINT('object'));
	$meeting = $version !== null ? $meetings->fetch($version['meeting_id']) : null;
	$result = $meeting === null ? 0 : $signatures->start(VereineSignatureRules::KIND_MINUTES, $version['id'], VereineMinutes::path($version), $meeting['day'], $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineSignatureStarted'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id.'#vereinemeetingminutes');
		exit;
	}
	setEventMessages($result < 0 ? $signatures->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $signatures->errors ? $signatures->errors : array('VereineSignatureErrorDocument')), 'errors');
} elseif (($action === 'sign' || $action === 'signscan') && $canWrite) {
	$run = $signatures->fetch(GETPOSTINT('signature'));
	$version = $run !== null ? $minutes->version($run['object_id']) : null;
	$file = $version !== null ? VereineMinutes::path($version) : '';
	$message = 'VereineSignatureSigned';
	if ($action === 'sign') {
		$result = $file === '' ? 0 : $signatures->sign($run['id'], GETPOST('password', 'password'), $file, $user, $langs);
	} else {
		$upload = isset($_FILES['scan_file']) && is_array($_FILES['scan_file']) ? $_FILES['scan_file'] : array();
		$result = $file === '' ? 0 : $signatures->uploadScan($run['id'], $upload, $user, $langs);
		$message = 'VereineSignatureScanStored';
	}
	if ($result > 0) {
		setEventMessages($langs->trans($message), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id.'#vereinemeetingminutes');
		exit;
	}
	setEventMessages($result < 0 ? $signatures->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $run === null ? array('VereineSignatureErrorNotOpen') : $signatures->errors), 'errors');
} elseif ($action === 'letters') {
	$file = VereineMeetings::lettersPath($id);
	if ($meetings->fetch($id) === null || !is_file($file)) {
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

llxHeader('', $langs->trans('VereineMeetingsTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-meetings');

/**
 * The form of a meeting.
 *
 * @param array<string,mixed> $meeting Meeting to show
 * @param int                 $id      Meeting, 0 for a new one
 * @return void
 */
function vereineMeetingForm(array $meeting, $id)
{
	global $langs;

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].($id > 0 ? '?id='.$id : '').'" name="vereinemeeting">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="savemeeting">';
	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired"><label for="kind">'.$langs->trans('VereineMeetingKind').'</label></td><td><select id="kind" name="kind">';
	foreach (VereineMeetingRules::KINDS as $kind) {
		print '<option value="'.$kind.'"'.($meeting['kind'] === $kind ? ' selected' : '').'>'.$langs->trans('VereineMeetingKind_'.$kind).'</option>';
	}
	print '</select></td></tr>';
	print '<tr><td class="fieldrequired"><label for="title">'.$langs->trans('VereineMeetingTitle').'</label></td>';
	print '<td><input type="text" id="title" name="title" class="minwidth300" maxlength="255" value="'.dol_escape_htmltag($meeting['title']).'"></td></tr>';
	print '<tr><td class="fieldrequired"><label for="day">'.$langs->trans('VereineMeetingWhen').'</label></td>';
	print '<td><input type="date" id="day" name="day" value="'.dol_escape_htmltag($meeting['day']).'"> <input type="time" name="time" value="'.dol_escape_htmltag($meeting['time']).'"></td></tr>';
	print '<tr><td><label for="format">'.$langs->trans('VereineMeetingFormat').'</label></td><td><select id="format" name="format">';
	foreach (VereineMeetingRules::FORMATS as $format) {
		print '<option value="'.$format.'"'.($meeting['format'] === $format ? ' selected' : '').'>'.$langs->trans('VereineMeetingFormat_'.$format).'</option>';
	}
	print '</select> <span class="opacitymedium small">'.$langs->trans('VereineMeetingFormatHelp').'</span></td></tr>';
	print '<tr><td><label for="place">'.$langs->trans('VereineMeetingPlace').'</label></td>';
	print '<td><input type="text" id="place" name="place" class="minwidth300" maxlength="255" value="'.dol_escape_htmltag($meeting['place']).'"></td></tr>';
	print '<tr><td class="tdtop"><label for="access">'.$langs->trans('VereineMeetingAccess').'</label></td>';
	print '<td><textarea id="access" name="access" rows="2" class="centpercent">'.dol_escape_htmltag($meeting['access'], 0, 1).'</textarea>';
	print '<div class="opacitymedium small">'.$langs->trans('VereineMeetingAccessHelp').'</div></td></tr>';
	print '<tr><td class="tdtop fieldrequired"><label for="agenda">'.$langs->trans('VereineMeetingAgenda').'</label></td>';
	print '<td><textarea id="agenda" name="agenda" rows="6" class="centpercent">'.dol_escape_htmltag(implode("\n", $meeting['agenda']), 0, 1).'</textarea>';
	print '<div class="opacitymedium small">'.$langs->trans('VereineMeetingAgendaHelp').'</div></td></tr>';
	print '</table>';
	print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div>';
	print '</form>';
}

/**
 * The texts of the agenda items for the minutes, with the real numbers once the meeting was invited to.
 *
 * @param VereineMeetings     $meetings Meetings
 * @param array<string,mixed> $meeting  Meeting to show
 * @param bool                $canWrite Whether the user may change the texts
 * @return void
 */
function vereineMeetingNotes($meetings, array $meeting, $canWrite)
{
	global $langs, $user;

	$planned = $meeting['status'] === VereineMeetingRules::STATUS_PLANNED;
	print '<br>'.load_fiche_titre($langs->trans('VereineMinutesTitle'), '', '', 0, 'vereinemeetingnotes');
	print '<div class="opacitymedium small paddingbottom">'.$langs->trans($planned ? 'VereineMinutesHowToPlanned' : 'VereineMinutesHowTo');
	if (!empty($user->admin)) {
		print ' <a href="'.dol_buildpath('/vereine/admin/meetings.php', 1).'">'.$langs->trans('VereineMinutesTemplatesLink').'</a>';
	}
	print '</div>';
	if ($canWrite) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$meeting['id'].'#vereinemeetingnotes" name="vereinemeetingnotes">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="savenotes">';
	}
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td class="width25p">'.$langs->trans('VereineVoteItem').'</td><td>'.$langs->trans('VereineMinutesText').'</td></tr>';
	foreach ($meetings->items($meeting, $langs) as $item) {
		print '<tr class="oddeven tdtop" data-note="'.$item['item'].'" data-stored="'.($item['stored'] ? 1 : 0).'"><td>'.$item['item'].'. '.dol_escape_htmltag($item['title']).'</td><td>';
		if ($canWrite) {
			print '<textarea name="note['.$item['item'].']" rows="3" class="centpercent">'.dol_escape_htmltag($item['text'], 0, 1).'</textarea>';
		}
		if (!$planned && $item['filled'] !== '') {
			print '<div class="'.($canWrite ? 'opacitymedium small paddingtop ' : '').'" data-note-preview="'.$item['item'].'">'.nl2br(dol_escape_htmltag($item['filled'], 0, 1)).'</div>';
		} elseif (!$canWrite) {
			print nl2br(dol_escape_htmltag($item['text'], 0, 1));
		}
		print '</td></tr>';
	}
	print '</table></div>';
	if ($canWrite) {
		print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('VereineMinutesSave')).'"></div>';
		print '</form>';
	}
}

/**
 * The minutes of a meeting: who presided and kept them, the draft, the final versions with their signatures.
 *
 * @param VereineMinutes      $minutes    Minutes store
 * @param VereineSignatures   $signatures Signature store
 * @param array<string,mixed> $meeting    Meeting to show
 * @param bool                $canWrite   Whether the user may change the minutes
 * @return void
 */
function vereineMeetingMinutes($minutes, $signatures, array $meeting, $canWrite)
{
	global $langs;

	$roles = $minutes->roles($meeting);
	$versions = $minutes->versions($meeting['id']);
	$day = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
	print '<br>'.load_fiche_titre($langs->trans('VereineMinutesPdfSection'), '', '', 0, 'vereinemeetingminutes');
	print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineMinutesPdfHowTo').'</div>';
	if ($canWrite) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$meeting['id'].'#vereinemeetingminutes" name="vereinemeetingroles">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="saveroles">';
	}
	print '<table class="border centpercent" data-minutes="'.$meeting['id'].'" data-versions="'.count($versions).'" data-suggested="'.($roles['suggested'] ? 1 : 0).'">';
	foreach (array('chair' => 'VereineMinutesChair', 'keeper' => 'VereineMinutesKeeper') as $role => $label) {
		print '<tr><td class="titlefieldcreate">'.$langs->trans($label).'</td><td>';
		if ($canWrite) {
			print '<select name="'.$role.'" data-role="'.$role.'"><option value="0"></option>';
			foreach ($roles['names'] as $memberId => $name) {
				print '<option value="'.$memberId.'"'.($roles[$role] === $memberId ? ' selected' : '').'>'.dol_escape_htmltag($name).'</option>';
			}
			print '</select>';
		} else {
			print dol_escape_htmltag(isset($roles['names'][$roles[$role]]) ? $roles['names'][$roles[$role]] : $langs->trans('VereineMinutesNobody'));
		}
		print '</td></tr>';
	}
	if ($canWrite) {
		print '<tr><td></td><td><input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineMinutesRolesSave')).'">';
		if ($roles['suggested']) {
			print ' <span class="opacitymedium small">'.$langs->trans('VereineMinutesRolesSuggested').'</span>';
		}
		print '</td></tr>';
	}
	print '</table>';
	if ($canWrite) {
		print '</form>';
		print '<div class="paddingtop">';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$meeting['id'].'" name="vereinemeetingdraft" class="inline-block paddingright">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="draft">';
		print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineMinutesDraftButton')).'">';
		print '</form>';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$meeting['id'].'#vereinemeetingminutes" name="vereinemeetingfinalize" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="finalize">';
		print '<label>'.$langs->trans('VereineMinutesApprovedOn').' <input type="date" name="approved_on" value="'.$day.'"></label> ';
		print '<input type="text" name="note" class="minwidth200" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('VereineMinutesApprovedNote')).'"> ';
		print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineMinutesFinalize')).'">';
		print '</form></div>';
	}
	print '<div class="div-table-responsive-no-min paddingtop"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('VereineMinutesVersion').'</td><td>'.$langs->trans('VereineMinutesApprovedOn').'</td>';
	print '<td>'.$langs->trans('VereineSignatureTitle').'</td><td>'.$langs->trans('VereineMinutesSendTitle').'</td><td></td></tr>';
	if (!$versions) {
		print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('VereineMinutesNoVersion').'</span></td></tr>';
	}
	foreach ($versions as $version) {
		$file = VereineMinutes::path($version);
		print '<tr class="oddeven tdtop" data-version="'.$version['id'].'" data-number="'.$version['version'].'">';
		print '<td>'.$version['version'].'<div class="opacitymedium small">'.dol_escape_htmltag(substr($version['doc_sha'], 0, 16)).'</div></td>';
		print '<td>'.($version['approved_on'] !== '' ? vereineFormatDay($version['approved_on']) : '<span class="opacitymedium">'.$langs->trans('VereineMinutesNotApproved').'</span>');
		print ($version['note'] !== '' ? '<div class="opacitymedium small">'.dol_escape_htmltag($version['note']).'</div>' : '').'</td><td>';
		vereineSignatureBlock($signatures, VereineSignatureRules::KIND_MINUTES, $version['id'], $file, $canWrite, 'vereinemeetingminutes');
		print '</td><td>';
		foreach (array(VereineMinutes::AUDIENCE_BOARD => 'sent_board', VereineMinutes::AUDIENCE_MEMBERS => 'sent_members') as $audience => $field) {
			print '<div data-sent="'.$audience.'" data-when="'.($version[$field] > 0 ? 1 : 0).'">';
			if ($version[$field] > 0) {
				print $langs->trans('VereineMinutesSentOn', $langs->transnoentitiesnoconv('VereineMinutesAudience_'.$audience), dol_print_date($version[$field], 'dayhour', 'tzuserrel'));
			} elseif ($canWrite && $file !== '') {
				print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$meeting['id'].'#vereinemeetingminutes" name="vereinesend'.$audience.$version['id'].'">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="sendminutes">';
				print '<input type="hidden" name="version" value="'.$version['id'].'">';
				print '<input type="hidden" name="audience" value="'.$audience.'">';
				print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineMinutesSend_'.$audience)).'">';
				print '</form>';
			}
			print '</div>';
		}
		print '</td><td class="right">';
		if ($file !== '') {
			print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$meeting['id'].'&amp;action=minutes&amp;version='.$version['id'].'&amp;token='.newToken().'">';
			print img_picto('', 'pdf').' '.dol_escape_htmltag($version['filename']).'</a>';
		}
		print '</td></tr>';
	}
	print '</table></div>';
}

$meeting = $id > 0 ? $meetings->fetch($id) : null;
if ($id > 0 && $meeting === null) {
	print '<div class="error">'.$langs->trans('VereineMeetingUnknown').'</div>';
	llxFooter();
	$db->close();
	exit;
}

if ($meeting === null) {
	print load_fiche_titre($langs->trans('VereineMeetingsTitle'), '', 'fa-landmark');
	print '<div class="info" data-meetings-howto="1"><ul>';
	foreach (array('VereineMeetingsHowToWho', 'VereineMeetingsHowToWhen', 'VereineMeetingsHowToProof') as $line) {
		print '<li>'.$langs->trans($line).'</li>';
	}
	print '</ul></div>';
	$overdue = VereineMeetingRules::generalOverdue($meetings->lastGeneral($today), $today, $rules);
	if ($overdue !== '') {
		print '<div class="warning" data-general-overdue="'.$overdue.'">'.$langs->trans('VereineMeetingGeneralOverdue_'.$overdue, vereineFormatDay($meetings->lastGeneral($today))).'</div>';
	}
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('VereineMeetingWhen').'</td><td>'.$langs->trans('VereineMeetingKind').'</td><td>'.$langs->trans('VereineMeetingTitle').'</td>';
	print '<td>'.$langs->trans('Status').'</td></tr>';
	$all = $meetings->fetchAll();
	if (!$all) {
		print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('VereineMeetingsNone').'</span></td></tr>';
	}
	foreach ($all as $row) {
		print '<tr class="oddeven" data-meeting="'.$row['id'].'" data-kind="'.$row['kind'].'" data-status="'.$row['status'].'">';
		print '<td>'.vereineFormatDay($row['day']).' '.dol_escape_htmltag($row['time']).'</td><td>'.$langs->trans('VereineMeetingKind_'.$row['kind']).'</td>';
		print '<td><a href="'.$_SERVER['PHP_SELF'].'?id='.$row['id'].'">'.dol_escape_htmltag($row['title']).'</a></td>';
		print '<td>'.dolGetBadge($langs->trans('VereineMeetingStatus_'.$row['status']), '', $row['status'] === VereineMeetingRules::STATUS_CANCELLED ? 'secondary' : 'info').'</td></tr>';
	}
	print '</table></div><br>';
	if ($canWrite) {
		print load_fiche_titre($langs->trans('VereineMeetingNew'), '', '', 0, 'vereinemeetingnew');
		$template = GETPOST('template', 'aZ09');
		print '<div class="paddingbottom" data-templates="1">'.$langs->trans('VereineMeetingFromTemplate');
		foreach (VereineMeetingRules::KINDS as $kind) {
			print ' <a class="button smallpaddingimp marginleftonly" href="'.$_SERVER['PHP_SELF'].'?template='.$kind.'#vereinemeetingnew" data-template-link="'.$kind.'">';
			print $langs->trans('VereineMeetingKind_'.$kind).'</a>';
		}
		print '</div>';
		if ($entered === null && in_array($template, VereineMeetingRules::KINDS, true)) {
			$entered = VereineMeetingRules::normalize(array('kind' => $template, 'title' => $langs->transnoentitiesnoconv('VereineMeetingKind_'.$template).' '.substr($today, 0, 4),
				'agenda' => implode("\n", VereineMinutesRules::agenda($meetings->templates(), $template))));
		}
		vereineMeetingForm($entered !== null ? $entered : VereineMeetingRules::normalize(array()), 0);
	}
	llxFooter();
	$db->close();
	exit;
}

// One meeting.
print load_fiche_titre(dol_escape_htmltag($meeting['title']), '<a href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('BackToList').'</a>', 'fa-landmark');
$inviteBy = VereineMeetingRules::inviteBy($meeting, $rules);
$motionsBy = VereineMeetingRules::motionsBy($meeting, $rules);
$late = $meeting['status'] === VereineMeetingRules::STATUS_PLANNED && $inviteBy !== '' && $today > $inviteBy;
print '<table class="border centpercent" data-meeting-card="'.$meeting['id'].'" data-kind="'.$meeting['kind'].'" data-status="'.$meeting['status'].'" data-invite-by="'.$inviteBy.'" data-late="'.($late ? 1 : 0).'">';
print '<tr><td class="titlefield">'.$langs->trans('VereineMeetingKind').'</td><td>'.$langs->trans('VereineMeetingKind_'.$meeting['kind']).'</td></tr>';
print '<tr><td>'.$langs->trans('VereineMeetingWhen').'</td><td>'.vereineFormatDay($meeting['day']).' '.dol_escape_htmltag($meeting['time']).'</td></tr>';
print '<tr><td>'.$langs->trans('VereineMeetingFormat').'</td><td>'.$langs->trans('VereineMeetingFormat_'.$meeting['format']).($meeting['place'] !== '' ? ', '.dol_escape_htmltag($meeting['place']) : '').'</td></tr>';
if ($meeting['access'] !== '') {
	print '<tr><td>'.$langs->trans('VereineMeetingAccess').'</td><td>'.nl2br(dol_escape_htmltag($meeting['access'], 0, 1)).'</td></tr>';
}
print '<tr><td class="tdtop">'.$langs->trans('VereineMeetingAgenda').'</td><td><ol>';
foreach ($meeting['agenda'] as $item) {
	print '<li>'.dol_escape_htmltag($item).'</li>';
}
print '</ol></td></tr>';
print '<tr><td>'.$langs->trans('Status').'</td><td>'.$langs->trans('VereineMeetingStatus_'.$meeting['status']).'</td></tr>';
if ($inviteBy !== '') {
	print '<tr><td>'.$langs->trans('VereineMeetingInviteBy').'</td><td>'.vereineFormatDay($inviteBy).' <span class="opacitymedium small">'.$langs->trans('VereineMeetingInviteByHelp', $rules['invite_days']).'</span></td></tr>';
}
if ($motionsBy !== '') {
	print '<tr><td>'.$langs->trans('VereineMeetingMotionsBy').'</td><td>'.vereineFormatDay($motionsBy).'</td></tr>';
}
print '</table>';
if ($late) {
	print '<div class="warning">'.$langs->trans('VereineMeetingLate', vereineFormatDay($inviteBy)).'</div>';
}
$missing = VereineMinutesRules::missing($meetings->templates(), $meeting['kind'], $meeting['agenda']);
if ($missing && $meeting['status'] !== VereineMeetingRules::STATUS_CANCELLED) {
	print '<div class="warning" data-missing-items="'.count($missing).'">'.$langs->trans('VereineMeetingMissingItems').'<ul>';
	foreach ($missing as $title) {
		print '<li>'.dol_escape_htmltag($title).'</li>';
	}
	print '</ul></div>';
}
print '<br>';

if ($meeting['status'] === VereineMeetingRules::STATUS_PLANNED) {
	$recipients = $meetings->recipients($meeting);
	$byChannel = array(VereineMeetingRules::CHANNEL_EMAIL => 0, VereineMeetingRules::CHANNEL_LETTER => 0);
	foreach ($recipients as $recipient) {
		$byChannel[$recipient['channel']]++;
	}
	print load_fiche_titre($langs->trans('VereineMeetingRecipients'), '', '', 0, 'vereinemeetingrecipients');
	print '<div class="info">'.$langs->trans('VereineMeetingRecipientsHelp_'.$meeting['kind'], count($recipients), $byChannel['email'], $byChannel['letter']).'</div>';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('Email').'</td><td>'.$langs->trans('VereineMeetingChannel').'</td>';
	print '<td>'.$langs->trans('VereineMeetingVoting').'</td></tr>';
	foreach ($recipients as $recipient) {
		print '<tr class="oddeven" data-recipient="'.$recipient['member_id'].'" data-channel="'.$recipient['channel'].'" data-voting="'.($recipient['voting'] ? 1 : 0).'">';
		print '<td>'.dol_escape_htmltag($recipient['name']).'</td><td>'.dol_escape_htmltag($recipient['email']).'</td>';
		print '<td>'.$langs->trans('VereineMeetingChannel_'.$recipient['channel']).'</td><td>'.yn($recipient['voting']).'</td></tr>';
	}
	print '</table></div>';
	vereineMeetingNotes($meetings, $meeting, $canWrite);
	if ($canWrite) {
		print '<br>';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$meeting['id'].'" name="vereinemeetinginvite" class="center paddingtop">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="invite">';
		print '<label><input type="checkbox" name="checked" value="1"> '.$langs->trans('VereineMeetingChecked').'</label> ';
		print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineMeetingInvite')).'">';
		print '</form><br>';
		print load_fiche_titre($langs->trans('VereineMeetingEdit'), '', '');
		vereineMeetingForm($entered !== null ? $entered : $meeting, $meeting['id']);
	}
} else {
	print load_fiche_titre($langs->trans('VereineMeetingInvitations'), '', '', 0, 'vereinemeetinginvitations');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('VereineMeetingChannel').'</td><td>'.$langs->trans('VereineMeetingSentAt').'</td>';
	print '<td>'.$langs->trans('VereineMeetingVoting').'</td></tr>';
	foreach ($meetings->invitations($meeting['id']) as $invitation) {
		print '<tr class="oddeven" data-invited="'.$invitation['member_id'].'" data-channel="'.$invitation['channel'].'" data-sent="'.($invitation['sent_at'] ? 1 : 0).'">';
		print '<td>'.dol_escape_htmltag($invitation['name']).($invitation['email'] !== '' ? ' <span class="opacitymedium">'.dol_escape_htmltag($invitation['email']).'</span>' : '').'</td>';
		print '<td>'.$langs->trans('VereineMeetingChannel_'.$invitation['channel']).'</td>';
		print '<td>'.($invitation['sent_at'] ? dol_print_date($invitation['sent_at'], 'dayhour', 'tzuserrel') : '<span class="error">'.dol_escape_htmltag($invitation['error']).'</span>').'</td>';
		print '<td>'.yn($invitation['voting']).'</td></tr>';
	}
	print '</table></div>';
	if (is_file(VereineMeetings::lettersPath($meeting['id']))) {
		print '<div class="paddingtop"><a href="'.$_SERVER['PHP_SELF'].'?id='.$meeting['id'].'&amp;action=letters&amp;token='.newToken().'">'.img_picto('', 'pdf').' '.$langs->trans('VereineMeetingLetters').'</a></div>';
	}
	// Attendance, proxies and quorum.
	$attendance = $meetings->attendance($meeting['id']);
	$at = GETPOST('at', 'alpha');
	$at = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at) ? $at : '';
	$quorum = VereineAttendanceRules::quorum($meeting['kind'], $attendance['rows'], $attendance['voting'], $rules, $at);
	print '<br>'.load_fiche_titre($langs->trans('VereineAttendance'), '', '', 0, 'vereineattendance');
	print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineAttendanceHowTo_'.($meeting['kind'] === VereineMeetingRules::KIND_BOARD ? 'board' : 'general')).'</div>';
	print '<div class="'.($quorum['reached'] ? 'ok' : 'warning').'" data-quorum-reached="'.($quorum['reached'] ? 1 : 0).'" data-votes="'.$quorum['votes'].'" data-present="'.$quorum['present'].'"';
	print ' data-represented="'.$quorum['represented'].'" data-eligible="'.$quorum['eligible'].'" data-required="'.$quorum['required'].'" data-at="'.$at.'">';
	print $langs->trans($quorum['reached'] ? 'VereineAttendanceReached' : 'VereineAttendanceMissing').' ';
	print $meeting['kind'] === VereineMeetingRules::KIND_BOARD ? $langs->trans('VereineAttendanceQuorumBoard', $quorum['present'], $quorum['eligible'], max(1, $quorum['required']))
		: $langs->trans('VereineAttendanceQuorumGeneral', $quorum['votes'], $quorum['present'], $quorum['represented'], $quorum['required']).' '.$langs->trans('VereineAttendanceEligible', $quorum['eligible']);
	print '</div>';
	print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'#vereineattendance" name="vereinequorumat" class="paddingtop paddingbottom">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="id" value="'.$meeting['id'].'">';
	print '<label>'.$langs->trans('VereineAttendanceAt').' <input type="time" name="at" value="'.dol_escape_htmltag($at).'"></label> ';
	print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineAttendanceAtShow')).'">';
	print '</form>';
	if ($canWrite) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$meeting['id'].'#vereineattendance" name="vereineattendance">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="saveattendance">';
	}
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('VereineMeetingVoting').'</td><td>'.$langs->trans('Status').'</td>';
	print '<td>'.$langs->trans('VereineAttendanceHolder').'</td><td>'.$langs->trans('VereineAttendanceArrived').'</td><td>'.$langs->trans('VereineAttendanceLeft').'</td></tr>';
	foreach ($attendance['rows'] as $memberId => $row) {
		print '<tr class="oddeven" data-attendance="'.$memberId.'" data-state="'.$row['state'].'" data-voting="'.(!empty($attendance['voting'][$memberId]) ? 1 : 0).'">';
		print '<td>'.dol_escape_htmltag($attendance['names'][$memberId]).'</td><td>'.yn(!empty($attendance['voting'][$memberId])).'</td>';
		if (!$canWrite) {
			print '<td>'.$langs->trans('VereineAttendanceState_'.$row['state']).'</td><td>'.($row['holder'] > 0 && isset($attendance['names'][$row['holder']]) ? dol_escape_htmltag($attendance['names'][$row['holder']]) : '').'</td>';
			print '<td>'.dol_escape_htmltag($row['arrived']).'</td><td>'.dol_escape_htmltag($row['left']).'</td></tr>';
			continue;
		}
		print '<td><select name="attendance['.$memberId.'][state]">';
		foreach (VereineAttendanceRules::STATES as $state) {
			print '<option value="'.$state.'"'.($row['state'] === $state ? ' selected' : '').'>'.$langs->trans('VereineAttendanceState_'.$state).'</option>';
		}
		print '</select></td><td><select name="attendance['.$memberId.'][holder]"><option value="0"></option>';
		foreach ($attendance['names'] as $holderId => $name) {
			if ($holderId !== $memberId && !empty($attendance['voting'][$holderId])) {
				print '<option value="'.$holderId.'"'.($row['holder'] === $holderId ? ' selected' : '').'>'.dol_escape_htmltag($name).'</option>';
			}
		}
		print '</select></td>';
		print '<td><input type="time" name="attendance['.$memberId.'][arrived]" value="'.dol_escape_htmltag($row['arrived']).'"></td>';
		print '<td><input type="time" name="attendance['.$memberId.'][left]" value="'.dol_escape_htmltag($row['left']).'"></td></tr>';
	}
	print '</table></div>';
	if ($canWrite) {
		print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('VereineAttendanceSave')).'"></div>';
		print '</form>';
	}

	// Votes and elections.
	require_once __DIR__.'/class/vereinefunctions.class.php';
	$functionStore = new VereineFunctions($db);
	$catalogue = array();
	foreach ($functionStore->fetchAll(true) as $function) {
		$catalogue[$function['id']] = $function['label'];
	}
	print '<br>'.load_fiche_titre($langs->trans('VereineVotes'), '', '', 0, 'vereinevotes');
	print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineVotesHowTo').'</div>';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('VereineVoteItem').'</td><td>'.$langs->trans('VereineVoteTitle').'</td><td>'.$langs->trans('VereineVoteCounts').'</td>';
	print '<td>'.$langs->trans('VereineVoteMajority').'</td><td>'.$langs->trans('VereineVoteResult').'</td></tr>';
	$votes = $meetings->votes($meeting['id']);
	if (!$votes) {
		print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('VereineVotesNone').'</span></td></tr>';
	}
	foreach ($votes as $vote) {
		print '<tr class="oddeven" data-vote="'.$vote['id'].'" data-kind="'.$vote['kind'].'" data-passed="'.($vote['passed'] ? 1 : 0).'" data-applied="'.dol_escape_htmltag($vote['applied']).'">';
		print '<td>'.$vote['item'].'</td><td>'.dol_escape_htmltag($vote['title']).($vote['kind'] === VereineVoteRules::KIND_ELECTION && isset($catalogue[$vote['function_id']])
			? ' <span class="opacitymedium">('.dol_escape_htmltag($catalogue[$vote['function_id']]).')</span>' : '').($vote['secret'] ? ' '.dolGetBadge($langs->trans('VereineVoteSecret'), '', 'secondary') : '').'</td>';
		print '<td>'.$langs->trans('VereineVoteCountsText', $vote['yes'], $vote['no'], $vote['abstain']).'</td><td>'.$langs->trans('VereineStatuteMajority_'.$vote['majority']).'</td>';
		print '<td>'.dolGetBadge($langs->trans($vote['passed'] ? 'VereineVotePassed' : 'VereineVoteRejected'), '', $vote['passed'] ? 'success' : 'danger').'</td></tr>';
	}
	print '</table></div>';
	if ($canWrite) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$meeting['id'].'#vereinevotes" name="vereinevote" class="paddingtop">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="savevote">';
		print '<table class="border centpercent">';
		print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('VereineVoteItem').'</td><td><select name="item">';
		foreach ($meeting['agenda'] as $index => $item) {
			print '<option value="'.($index + 1).'">'.($index + 1).'. '.dol_escape_htmltag($item).'</option>';
		}
		print '</select></td></tr>';
		print '<tr><td>'.$langs->trans('VereineVoteKind').'</td><td><select name="kind">';
		foreach (VereineVoteRules::KINDS as $kind) {
			print '<option value="'.$kind.'">'.$langs->trans('VereineVoteKind_'.$kind).'</option>';
		}
		print '</select></td></tr>';
		print '<tr><td class="fieldrequired">'.$langs->trans('VereineVoteTitle').'</td><td><input type="text" name="title" class="minwidth300" maxlength="255" value=""></td></tr>';
		print '<tr><td>'.$langs->trans('VereineVoteCounts').'</td><td>'.$langs->trans('VereineVoteYes').' <input type="number" min="0" name="yes" class="width50" value="0"> ';
		print $langs->trans('VereineVoteNo').' <input type="number" min="0" name="no" class="width50" value="0"> '.$langs->trans('VereineVoteAbstain');
		print ' <input type="number" min="0" name="abstain" class="width50" value="0"> <label><input type="checkbox" name="secret" value="1"> '.$langs->trans('VereineVoteSecret').'</label></td></tr>';
		print '<tr><td>'.$langs->trans('VereineVoteTime').'</td><td><input type="time" name="time" value=""> <span class="opacitymedium small">'.$langs->trans('VereineVoteTimeHelp').'</span></td></tr>';
		if ($meeting['kind'] === VereineMeetingRules::KIND_BOARD && !empty($rules['board_tie_chair'])) {
			print '<tr><td>'.$langs->trans('VereineVoteTie').'</td><td><select name="tie"><option value=""></option><option value="yes">'.$langs->trans('VereineVoteYes').'</option>';
			print '<option value="no">'.$langs->trans('VereineVoteNo').'</option></select></td></tr>';
		}
		print '<tr><td>'.$langs->trans('VereineVoteFunction').'</td><td><select name="function_id"><option value="0"></option>';
		foreach ($catalogue as $functionId => $label) {
			print '<option value="'.$functionId.'">'.dol_escape_htmltag($label).'</option>';
		}
		print '</select> '.$langs->trans('VereineVoteCandidate').' <select name="candidate_id"><option value="0"></option>';
		foreach ($meetings->members($meeting['day']) as $member) {
			if ($member['status'] === 1) {
				print '<option value="'.$member['id'].'">'.dol_escape_htmltag($member['name']).'</option>';
			}
		}
		print '</select> <span class="opacitymedium small">'.$langs->trans('VereineVoteElectionHelp').'</span></td></tr>';
		print '</table><div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineVoteSave')).'"></div></form>';
	}

	if ($meeting['status'] !== VereineMeetingRules::STATUS_CANCELLED) {
		vereineMeetingNotes($meetings, $meeting, $canWrite);
		vereineMeetingMinutes($minutes, $signatures, $meeting, $canWrite);
	}

	if ($canWrite && $meeting['status'] === VereineMeetingRules::STATUS_INVITED) {
		print '<div class="center paddingtop">';
		foreach (array('held' => 'VereineMeetingMarkHeld', 'cancel' => 'VereineMeetingMarkCancelled') as $status => $label) {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$meeting['id'].'" name="vereinemeeting'.$status.'" class="inline-block paddingright">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="'.$status.'">';
			print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans($label)).'">';
			print '</form>';
		}
		print '</div>';
	}
}

llxFooter();
$db->close();
