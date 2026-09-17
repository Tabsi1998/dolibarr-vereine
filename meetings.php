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
		print load_fiche_titre($langs->trans('VereineMeetingNew'), '', '');
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
	if ($canWrite) {
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
