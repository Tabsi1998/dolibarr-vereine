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
 * \file    admin/webhooks.php
 * \ingroup vereine
 * \brief   Webhook targets (#155): where change notices go, and how the deliveries are doing.
 *
 * The secret of a target is shown once, when it is made or rotated, and never again. What is on its way
 * stands here with its state, its attempts and the reason it failed, with anything secret taken out.
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
require_once __DIR__.'/../class/vereinehooks.class.php';
require_once __DIR__.'/../lib/vereine.lib.php';

$langs->loadLangs(array('admin', 'other', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$hooks = new VereineHooks($db);
$action = GETPOST('action', 'aZ09');
$self = $_SERVER['PHP_SELF'];
$shownSecret = '';


/*
 * Actions
 */

if ($action === 'save') {
	$entered = array('label' => GETPOST('label', 'alphanohtml'), 'url' => GETPOST('url', 'alphanohtml'),
		'user_id' => GETPOSTINT('user_id'), 'object_types' => implode(',', GETPOST('object_types', 'array')),
		'allow_internal' => GETPOST('allow_internal', 'aZ09') === '1', 'active' => GETPOST('active', 'aZ09') !== '0');
	$result = $hooks->save(GETPOSTINT('target'), $entered, $user);
	if ($result > 0) {
		$shownSecret = $hooks->newSecret;
		setEventMessages($langs->trans('VereineHookSaved'), null, 'mesgs');
		if ($shownSecret === '') {
			header('Location: '.$self);
			exit;
		}
	} else {
		setEventMessages($result < 0 ? $hooks->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $hooks->errors), 'errors');
	}
} elseif ($action === 'rotate') {
	$shownSecret = $hooks->rotate(GETPOSTINT('target'), $user);
	if ($shownSecret === '') {
		setEventMessages($hooks->error, array_map(array($langs, 'trans'), $hooks->errors), 'errors');
	} else {
		setEventMessages($langs->trans('VereineHookRotated'), null, 'mesgs');
	}
} elseif ($action === 'remove') {
	if ($hooks->remove(GETPOSTINT('target'), $user) > 0) {
		header('Location: '.$self);
		exit;
	}
	setEventMessages($hooks->error, null, 'errors');
} elseif ($action === 'retry') {
	if ($hooks->retry(GETPOSTINT('delivery'), $user) > 0) {
		setEventMessages($langs->trans('VereineHookRetryStarted'), null, 'mesgs');
		header('Location: '.$self.'#vereinehookdeliveries');
		exit;
	}
	setEventMessages($hooks->error, null, 'errors');
} elseif ($action === 'run') {
	// By hand, for a test; otherwise the scheduled job does it.
	$hooks->runDue();
	setEventMessages(dol_escape_htmltag($hooks->output), null, 'mesgs');
	header('Location: '.$self.'#vereinehookdeliveries');
	exit;
}


/*
 * View
 */

$targets = $hooks->targets();
$backlog = $hooks->backlog();
$deliveries = $hooks->deliveries(0, 50);
$users = array();
$sql = "SELECT rowid, login, lastname, firstname FROM ".MAIN_DB_PREFIX."user";
$sql .= " WHERE entity IN (0, ".((int) $conf->entity).") AND statut = 1 ORDER BY login";
$resql = $db->query($sql);
while ($resql && ($obj = $db->fetch_object($resql))) {
	$users[(int) $obj->rowid] = (string) $obj->login.' ('.trim($obj->firstname.' '.$obj->lastname).')';
}
$states = array('pending' => 'badge-status1', 'sent' => 'badge-status6', 'failed' => 'badge-status8', 'stopped' => 'badge-status0');

llxHeader('', $langs->trans('VereineSetupTabWebhooks'), '', '', 0, 0, '', '', '', 'mod-vereine page-admin-webhooks');
print load_fiche_titre($langs->trans('VereineSetupTabWebhooks'), '', 'object_vereine@vereine');
print dol_get_fiche_head(vereineAdminPrepareHead(), 'webhooks', '', -1);
print '<div class="info" data-hook-howto="1"><ul>';
print '<li>'.$langs->trans('VereineHookHowTo').'</li>';
print '<li>'.$langs->trans('VereineHookHowToSign').'</li>';
print '<li>'.$langs->trans('VereineHookHowToProof').'</li>';
print '</ul></div>';

if ($shownSecret !== '') {
	print '<div class="warning" data-hook-secret="1"><strong>'.$langs->trans('VereineHookSecretOnce').'</strong><br>';
	print '<code>'.dol_escape_htmltag($shownSecret).'</code></div>';
}

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-hook-targets="'.count($targets).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineHookTarget').'</td><td>'.$langs->trans('VereineHookUrl').'</td>';
print '<td>'.$langs->trans('VereineHookUser').'</td><td>'.$langs->trans('VereineHookKey').'</td>';
print '<td>'.$langs->trans('VereineHookLastOk').'</td><td>'.$langs->trans('Status').'</td><td></td></tr>';
foreach ($targets as $target) {
	$allowed = $hooks->mayDeliver($target);
	print '<tr class="oddeven" data-hook-target="'.((int) $target['id']).'" data-hook-active="'.($target['active'] ? 1 : 0).'"';
	print ' data-hook-allowed="'.($allowed ? 1 : 0).'">';
	print '<td>'.dol_escape_htmltag($target['label']).'</td>';
	print '<td class="nowraponall"><span class="opacitymedium small">'.dol_escape_htmltag($target['url']).'</span></td>';
	print '<td>'.dol_escape_htmltag(isset($users[$target['user_id']]) ? $users[$target['user_id']] : (string) $target['user_id']);
	if (!$allowed) {
		print '<br><span class="badge badge-status badge-status8">'.$langs->trans('VereineHookNoRight').'</span>';
	}
	print '</td>';
	print '<td class="nowraponall">'.dol_escape_htmltag(VereineHookRules::maskSecret($target['key_id']));
	if ($target['next_key_id'] !== '' && $target['rotate_until'] !== '') {
		print '<br><span class="opacitymedium small">'.$langs->trans('VereineHookRotationUntil',
			dol_escape_htmltag(VereineHookRules::maskSecret($target['next_key_id'])), dol_print_date($db->jdate($target['rotate_until']), 'dayhour')).'</span>';
	}
	print '</td>';
	print '<td class="nowraponall">'.($target['last_ok'] !== '' ? dol_print_date($db->jdate($target['last_ok']), 'dayhour') : '').'</td>';
	print '<td><span class="badge badge-status '.($target['active'] ? 'badge-status4' : 'badge-status0').'">';
	print $langs->trans($target['active'] ? 'Enabled' : 'Disabled').'</span></td>';
	print '<td class="right nowraponall">';
	print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="rotate"><input type="hidden" name="target" value="'.((int) $target['id']).'">';
	print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineHookRotate')).'"></form> ';
	print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="remove"><input type="hidden" name="target" value="'.((int) $target['id']).'">';
	print '<input type="submit" class="button small butActionDelete" value="'.dol_escape_htmltag($langs->trans('Delete')).'"></form>';
	print '</td></tr>';
}
if (!$targets) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium" data-hook-none="1">'.$langs->trans('VereineHookNoTarget').'</span></td></tr>';
}
print '</table></div>';

// A new target.
print load_fiche_titre($langs->trans('VereineHookAdd'), '', '', 0, 'vereinehookform');
print '<form method="POST" name="vereinehook" action="'.$self.'#vereinehookform">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
print '<table class="border centpercent">';
print '<tr><td class="titlefield fieldrequired">'.$langs->trans('VereineHookTarget').'</td><td><input type="text" name="label" size="30" maxlength="128" value=""></td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('VereineHookUrl').'</td><td><input type="url" name="url" size="60" maxlength="255" value="">';
print '<br><span class="opacitymedium small">'.$langs->trans('VereineHookUrlHint').'</span></td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('VereineHookUser').'</td><td><select name="user_id" class="flat">';
foreach ($users as $userId => $label) {
	print '<option value="'.$userId.'">'.dol_escape_htmltag($label).'</option>';
}
print '</select><br><span class="opacitymedium small">'.$langs->trans('VereineHookUserHint').'</span></td></tr>';
print '<tr><td>'.$langs->trans('VereineHookTypes').'</td><td>';
foreach (VereineChangeRules::TYPES as $type) {
	print '<label class="paddingright"><input type="checkbox" name="object_types[]" value="'.$type.'"> '.$type.'</label>';
}
print '<br><span class="opacitymedium small">'.$langs->trans('VereineHookTypesHint').'</span></td></tr>';
print '<tr><td>'.$langs->trans('VereineHookInternal').'</td><td><select name="allow_internal" class="flat">';
print '<option value="0">'.$langs->trans('VereineHookInternalNo').'</option><option value="1">'.$langs->trans('VereineHookInternalYes').'</option>';
print '</select></td></tr>';
print '</table>';
print '<div class="center paddingtop"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div></form>';

// What is on its way.
print load_fiche_titre($langs->trans('VereineHookDeliveries'), '', '', 0, 'vereinehookdeliveries');
print '<div class="paddingbottom" data-hook-backlog="'.((int) $backlog['pending']).'" data-hook-failed="'.((int) $backlog['failed']).'">';
print $langs->trans('VereineHookBacklog', $backlog['pending'], $backlog['sent'], $backlog['failed'], $backlog['stopped']).'</div>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-hook-deliveries="'.count($deliveries).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineHookEvent').'</td><td>'.$langs->trans('VereineHookObject').'</td>';
print '<td>'.$langs->trans('Status').'</td><td>'.$langs->trans('VereineHookAttempts').'</td>';
print '<td>'.$langs->trans('VereineHookLastError').'</td><td></td></tr>';
foreach ($deliveries as $row) {
	print '<tr class="oddeven" data-hook-delivery="'.((int) $row['id']).'" data-hook-status="'.$row['status'].'">';
	print '<td class="nowraponall"><span class="opacitymedium small">'.dol_escape_htmltag(substr($row['event_id'], 0, 12)).'…</span></td>';
	print '<td class="nowraponall">'.dol_escape_htmltag($row['object_type'].' '.$row['object_id'].' · '.$row['change']).'</td>';
	print '<td><span class="badge badge-status '.$states[$row['status']].'">'.$langs->trans('VereineHookStatus_'.$row['status']).'</span></td>';
	print '<td class="nowraponall">'.((int) $row['attempts']);
	if ($row['next_try'] !== '' && $row['status'] === 'pending') {
		print ' <span class="opacitymedium small">'.dol_print_date($db->jdate($row['next_try']), 'dayhour').'</span>';
	}
	print '</td>';
	print '<td><span class="opacitymedium small">'.dol_escape_htmltag($row['last_error']).'</span></td>';
	print '<td class="right">';
	if ($row['status'] !== 'sent') {
		print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="retry"><input type="hidden" name="delivery" value="'.((int) $row['id']).'">';
		print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineHookRetry')).'"></form>';
	}
	print '</td></tr>';
}
if (!$deliveries) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium" data-hook-nodelivery="1">'.$langs->trans('VereineHookNoDelivery').'</span></td></tr>';
}
print '</table></div>';
print '<div class="tabsAction"><form method="POST" name="vereinehookrun" action="'.$self.'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="run">';
print '<input type="submit" class="butAction" value="'.dol_escape_htmltag($langs->trans('VereineHookRun')).'"></form></div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
