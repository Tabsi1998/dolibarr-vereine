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
 * \file    admin/social.php
 * \ingroup vereine
 * \brief   Channels of the association and which accounts of members the application asks for (#233).
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
require_once __DIR__.'/../class/vereinesocial.class.php';

$langs->loadLangs(array('admin', 'members', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$social = new VereineSocial($db);

if ($action === 'savechannel') {
	$entered = array('network' => GETPOST('network', 'aZ09'), 'label' => GETPOST('label', 'alphanohtml'), 'target' => GETPOST('target', 'alphanohtml'),
		'stream' => GETPOSTISSET('stream'), 'public' => GETPOSTISSET('public'), 'position' => GETPOST('position', 'alphanohtml'));
	$result = $social->saveChannel(GETPOSTINT('id'), $entered, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereinechannels');
		exit;
	}
	setEventMessages($result < 0 ? $social->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $social->errors), 'errors');
} elseif ($action === 'removechannel') {
	if ($social->removeChannel(GETPOSTINT('id'), $user) < 0) {
		setEventMessages($social->error, null, 'errors');
	} else {
		header('Location: '.$_SERVER['PHP_SELF'].'#vereinechannels');
		exit;
	}
} elseif ($action === 'saveasked') {
	$entered = array();
	foreach (array_keys($social->networks()) as $code) {
		$entered[$code] = GETPOST('asked_'.$code, 'aZ09');
	}
	if ($social->saveAsked($entered, $user) < 0) {
		setEventMessages($social->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereineaccounts');
		exit;
	}
} elseif ($action === 'addnetwork') {
	$result = $social->addNetwork(GETPOST('code', 'aZ09'), GETPOST('label', 'alphanohtml'), GETPOST('pattern', 'alphanohtml'), $user);
	if ($result > 0) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereineaccounts');
		exit;
	}
	setEventMessages($result < 0 ? $social->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $social->errors), 'errors');
}

$networks = $social->networks();
$labels = array();
foreach ($networks as $code => $network) {
	$labels[$code] = $network['label'];
}
$channels = $social->channels(false);
$asked = $social->asked();
$edit = null;
foreach ($channels as $channel) {
	if ($channel['id'] === GETPOSTINT('edit')) {
		$edit = $channel;
	}
}

$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-social');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');
print dol_get_fiche_head(vereineAdminPrepareHead(), 'social', $title, -1, 'fa-landmark');

// The association's own channels: several per network, in the order the association wants.
print load_fiche_titre($langs->trans('VereineChannelsTitle'), '', '', 0, 'vereinechannels');
print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineChannelsHowTo').'</div>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-channels="'.count($channels).'">';
print '<tr class="liste_titre"><td class="right">'.$langs->trans('VereineChannelPosition').'</td><td>'.$langs->trans('VereineChannelNetwork').'</td><td>'.$langs->trans('VereineChannelLabel').'</td>';
print '<td>'.$langs->trans('VereineChannelTarget').'</td><td class="center">'.$langs->trans('VereineChannelStream').'</td><td class="center">'.$langs->trans('VereineChannelPublic').'</td><td></td></tr>';
foreach ($channels as $channel) {
	print '<tr class="oddeven" data-channel="'.$channel['id'].'" data-channel-network="'.dol_escape_htmltag($channel['network']).'">';
	print '<td class="right">'.$channel['position'].'</td><td>'.dol_escape_htmltag($channel['network_label']).'</td><td>'.dol_escape_htmltag($channel['label']).'</td>';
	print '<td>'.($channel['url'] !== '' ? '<a href="'.dol_escape_htmltag($channel['url']).'" target="_blank" rel="noopener noreferrer">'.dol_escape_htmltag($channel['target']).'</a>' : dol_escape_htmltag($channel['target']));
	print $channel['live_url'] !== '' ? ' <span class="opacitymedium small">· <a href="'.dol_escape_htmltag($channel['live_url']).'" target="_blank" rel="noopener noreferrer">'.$langs->trans('VereineChannelLive').'</a></span>' : '';
	print '</td><td class="center">'.yn($channel['stream'] ? 1 : 0).'</td><td class="center">'.yn($channel['public'] ? 1 : 0).'</td>';
	print '<td class="right nowraponall"><a class="editfielda" href="'.$_SERVER['PHP_SELF'].'?edit='.$channel['id'].'#vereinechannelform">'.img_edit().'</a> ';
	print '<a href="'.$_SERVER['PHP_SELF'].'?action=removechannel&id='.$channel['id'].'&token='.newToken().'">'.img_delete().'</a></td></tr>';
}
if (!$channels) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('VereineChannelsNone').'</span></td></tr>';
}
print '</table></div>';

$form = $edit !== null ? $edit : array('id' => 0, 'network' => 'twitch', 'label' => '', 'target' => '', 'stream' => true, 'public' => true,
	'position' => ($channels ? max(array_column($channels, 'position')) + 10 : 10));
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#vereinechannels" name="vereinechannel" id="vereinechannelform" class="paddingtop">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="savechannel"><input type="hidden" name="id" value="'.((int) $form['id']).'">';
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('VereineChannelNetwork').'</td><td>'.Form::selectarray('network', $labels, $form['network'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('VereineChannelLabel').'</td><td><input type="text" name="label" maxlength="64" class="minwidth300" value="'.dol_escape_htmltag($form['label']).'">';
print ' <span class="opacitymedium small">'.$langs->trans('VereineChannelLabelHelp').'</span></td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('VereineChannelTarget').'</td><td><input type="text" name="target" maxlength="255" class="minwidth400" value="'.dol_escape_htmltag($form['target']).'">';
print '<br><span class="opacitymedium small">'.$langs->trans('VereineChannelTargetHelp').'</span></td></tr>';
print '<tr><td>'.$langs->trans('VereineChannelPosition').'</td><td><input type="number" name="position" min="0" max="999" class="width50" value="'.((int) $form['position']).'"></td></tr>';
print '<tr><td></td><td><label><input type="checkbox" name="stream" value="1"'.($form['stream'] ? ' checked' : '').'> '.$langs->trans('VereineChannelStreamHelp').'</label><br>';
print '<label><input type="checkbox" name="public" value="1"'.($form['public'] ? ' checked' : '').'> '.$langs->trans('VereineChannelPublicHelp').'</label></td></tr>';
print '</table><div class="center paddingtop"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans($edit !== null ? 'Save' : 'VereineChannelAdd')).'"></div></form>';

// Accounts of members: which networks the application and the app ask for.
print '<br>'.load_fiche_titre($langs->trans('VereineSocialTitle'), '', '', 0, 'vereineaccounts');
print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineSocialHowTo').'</div>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#vereineaccounts" name="vereinesocialasked">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="saveasked">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-social-asked="'.count($asked).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineChannelNetwork').'</td><td>'.$langs->trans('VereineSocialAsk').'</td><td class="center">'.$langs->trans('VereineSocialOnCard').'</td></tr>';
$choices = array('' => $langs->trans('VereineSocialAsk_no'), VereineSocialRules::OPTIONAL => $langs->trans('VereineSocialAsk_optional'),
	VereineSocialRules::REQUIRED => $langs->trans('VereineSocialAsk_required'));
foreach ($networks as $code => $network) {
	print '<tr class="oddeven" data-social-network="'.dol_escape_htmltag($code).'"><td>'.dol_escape_htmltag($network['label']).' <span class="opacitymedium small">'.dol_escape_htmltag($code).'</span></td>';
	print '<td>'.Form::selectarray('asked_'.$code, $choices, isset($asked[$code]) ? $asked[$code] : '', 0, 0, 0, '', 0, 0, 0, '', 'minwidth150').'</td>';
	print '<td class="center">'.yn($network['active'] ? 1 : 0).'</td></tr>';
}
print '</table></div>';
print '<div class="center paddingtop"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div></form>';

// A network the dictionary lacks.
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#vereineaccounts" name="vereinesocialnetwork" class="paddingtop">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="addnetwork">';
print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineSocialAddHowTo').'</div>';
print $langs->trans('VereineSocialCode').' <input type="text" name="code" size="12" maxlength="32" placeholder="steam"> ';
print $langs->trans('VereineChannelLabel').' <input type="text" name="label" size="16" maxlength="64" placeholder="Steam"> ';
print $langs->trans('VereineSocialPattern').' <input type="text" name="pattern" size="40" maxlength="255" placeholder="https://steamcommunity.com/id/{socialid}"> ';
print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineSocialAdd')).'"></form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
