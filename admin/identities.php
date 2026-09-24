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
 * \file    admin/identities.php
 * \ingroup vereine
 * \brief   External identities (#153): who may act for whom through which application.
 *
 * An invitation is shown once and dies on first use. A binding can be taken back at any time, and what
 * it allowed stops with it. An address or a member number only ever finds candidates to look at.
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
require_once __DIR__.'/../class/vereineaccess.class.php';
require_once __DIR__.'/../lib/vereine.lib.php';

$langs->loadLangs(array('admin', 'members', 'other', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$access = new VereineAccess($db);
$action = GETPOST('action', 'aZ09');
$self = $_SERVER['PHP_SELF'];
$shownCode = '';
$candidates = array();


/*
 * Actions
 */

if ($action === 'invite') {
	$shownCode = $access->invite(GETPOST('client', 'alphanohtml'), GETPOSTINT('member_id'), GETPOSTINT('application_id'),
		GETPOST('capabilities', 'array'), $user);
	if ($shownCode === '') {
		setEventMessages($access->error, array_map(array($langs, 'trans'), $access->errors), 'errors');
	} else {
		setEventMessages($langs->trans('VereineIdentityInvited'), null, 'mesgs');
	}
} elseif ($action === 'revoke') {
	if ($access->revoke(GETPOSTINT('identity'), $user) > 0) {
		setEventMessages($langs->trans('VereineIdentityRevoked'), null, 'mesgs');
		header('Location: '.$self);
		exit;
	}
	setEventMessages($access->error, array_map(array($langs, 'trans'), $access->errors), 'errors');
} elseif ($action === 'savedirect') {
	dol_include_once('/vereine/class/vereineprofiles.class.php');
	$fields = array();
	foreach (VereineProfileRules::DIRECT_ALLOWED as $field) {
		if (GETPOSTISSET('direct_'.$field)) {
			$fields[] = $field;
		}
	}
	if ((new VereineProfiles($db))->saveDirect($fields, $user) > 0) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$self.'#vereineprofiledirect');
		exit;
	}
} elseif ($action === 'saveportal') {
	dol_include_once('/vereine/class/vereineportal.class.php');
	$abilities = array();
	foreach (VereinePortal::OFFERED as $ability) {
		if (GETPOSTISSET('portal_'.$ability)) {
			$abilities[] = $ability;
		}
	}
	if ((new VereinePortal($db))->save($abilities, $user) > 0) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$self.'#vereineportal');
		exit;
	}
} elseif ($action === 'search') {
	$candidates = $access->candidates(GETPOST('email', 'alphanohtml'), GETPOST('ref', 'alphanohtml'));
}


/*
 * View
 */

$identities = $access->all();
$invitations = $access->invitations();
$today = dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S', 'tzserver');

llxHeader('', $langs->trans('VereineSetupTabIdentities'), '', '', 0, 0, '', '', '', 'mod-vereine page-admin-identities');
print load_fiche_titre($langs->trans('VereineSetupTabIdentities'), '', 'object_vereine@vereine');
print dol_get_fiche_head(vereineAdminPrepareHead(), 'identities', '', -1);
print '<div class="info" data-identity-howto="1"><ul>';
print '<li>'.$langs->trans('VereineIdentityHowTo').'</li>';
print '<li>'.$langs->trans('VereineIdentityHowToProof').'</li>';
print '<li>'.$langs->trans('VereineIdentityHowToTrust').'</li>';
print '</ul></div>';

if ($shownCode !== '') {
	print '<div class="warning" data-identity-code="1"><strong>'.$langs->trans('VereineIdentityCodeOnce').'</strong><br>';
	print '<code>'.dol_escape_htmltag($shownCode).'</code></div>';
}

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-identities="'.count($identities).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineIdentityClient').'</td><td>'.$langs->trans('VereineIdentitySubject').'</td>';
print '<td>'.$langs->trans('VereineIdentityBoundTo').'</td><td>'.$langs->trans('VereineIdentityCapabilities').'</td>';
print '<td>'.$langs->trans('VereineIdentityProof').'</td><td>'.$langs->trans('Status').'</td><td></td></tr>';
foreach ($identities as $identity) {
	$live = $identity['revoked_at'] === '';
	print '<tr class="oddeven" data-identity="'.((int) $identity['id']).'" data-identity-live="'.($live ? 1 : 0).'">';
	print '<td>'.dol_escape_htmltag($identity['client']).'</td>';
	print '<td class="nowraponall"><span class="opacitymedium small">'.dol_escape_htmltag($identity['subject']).'</span></td>';
	print '<td class="nowraponall">';
	if ($identity['member_id'] > 0) {
		print '<a href="'.dol_buildpath('/adherents/card.php', 1).'?id='.((int) $identity['member_id']).'">'
			.$langs->trans('VereineIdentityMember', (int) $identity['member_id']).'</a>';
	} elseif ($identity['application_id'] > 0) {
		print '<a href="'.dol_buildpath('/vereine/applications.php', 1).'">'
			.$langs->trans('VereineIdentityApplication', (int) $identity['application_id']).'</a>';
	} else {
		print '<span class="opacitymedium">'.$langs->trans('VereineIdentityNothing').'</span>';
	}
	print '</td>';
	print '<td class="nowraponall"><span class="opacitymedium small">'
		.dol_escape_htmltag($identity['capabilities'] !== '' ? $identity['capabilities'] : $langs->transnoentitiesnoconv('VereineIdentityNoCapability')).'</span></td>';
	print '<td class="nowraponall">'.$langs->trans('VereineIdentityProof_'.$identity['proof']).'</td>';
	print '<td><span class="badge badge-status '.($live ? 'badge-status4' : 'badge-status0').'">';
	print $langs->trans($live ? 'VereineIdentityLive' : 'VereineIdentityRevokedState').'</span></td>';
	print '<td class="right">';
	if ($live) {
		print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="revoke"><input type="hidden" name="identity" value="'.((int) $identity['id']).'">';
		print '<input type="submit" class="button small butActionDelete" value="'.dol_escape_htmltag($langs->trans('VereineIdentityRevoke')).'"></form>';
	}
	print '</td></tr>';
}
if (!$identities) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium" data-identity-none="1">'.$langs->trans('VereineIdentityNone').'</span></td></tr>';
}
print '</table></div>';

// Who could be meant: candidates to look at, never a proof.
print load_fiche_titre($langs->trans('VereineIdentitySearchTitle'), '', '', 0, 'vereineidentitysearch');
print '<form method="POST" name="vereineidentitysearch" action="'.$self.'#vereineidentitysearch">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="search">';
print '<table class="border centpercent"><tr><td class="titlefield">'.$langs->trans('VereineIdentitySearch').'</td><td>';
print '<input type="text" name="email" size="30" value="" placeholder="'.dol_escape_htmltag($langs->trans('Email')).'"> ';
print '<input type="text" name="ref" size="14" value="" placeholder="'.dol_escape_htmltag($langs->trans('Ref')).'"> ';
print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('Search')).'">';
print '<br><span class="opacitymedium small">'.$langs->trans('VereineIdentitySearchHint').'</span>';
print '</td></tr></table></form>';
if ($candidates) {
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-identity-candidates="'.count($candidates).'">';
	foreach ($candidates as $candidate) {
		print '<tr class="oddeven" data-identity-candidate="'.((int) $candidate['member_id']).'">';
		print '<td>'.dol_escape_htmltag($candidate['ref']).'</td><td>'.dol_escape_htmltag($candidate['name']).'</td>';
		print '<td class="right">'.((int) $candidate['member_id']).'</td></tr>';
	}
	print '</table></div>';
	if (count($candidates) > 1) {
		print '<div class="warning" data-identity-many="1">'.$langs->trans('VereineIdentityManyCandidates').'</div>';
	}
}

// The invitation itself.
print load_fiche_titre($langs->trans('VereineIdentityInviteTitle'), '', '', 0, 'vereineidentityform');
print '<form method="POST" name="vereineidentityinvite" action="'.$self.'#vereineidentityform">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="invite">';
print '<table class="border centpercent">';
print '<tr><td class="titlefield fieldrequired">'.$langs->trans('VereineIdentityClient').'</td><td>';
print '<input type="text" name="client" size="30" maxlength="64" value="">';
print '<br><span class="opacitymedium small">'.$langs->trans('VereineIdentityClientHint').'</span></td></tr>';
print '<tr><td>'.$langs->trans('VereineIdentityMemberId').'</td><td><input type="number" name="member_id" min="0" value="0"> ';
print $langs->trans('VereineIdentityApplicationId').' <input type="number" name="application_id" min="0" value="0">';
print '<br><span class="opacitymedium small">'.$langs->trans('VereineIdentityBindHint').'</span></td></tr>';
print '<tr><td>'.$langs->trans('VereineIdentityCapabilities').'</td><td>';
foreach (VereineIdentityRules::CAPABILITIES as $capability) {
	print '<label class="paddingright"><input type="checkbox" name="capabilities[]" value="'.$capability.'"> ';
	print $langs->trans('VereineIdentityCapability_'.$capability).'</label>';
}
print '<br><span class="opacitymedium small">'.$langs->trans('VereineIdentityCapabilitiesHint').'</span></td></tr>';
print '</table>';
print '<div class="center paddingtop"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineIdentityInviteButton')).'"></div></form>';

// What was handed out.
print load_fiche_titre($langs->trans('VereineIdentityInvitations'), '', '', 0, 'vereineidentityinvites');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-identity-invites="'.count($invitations).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineIdentityClient').'</td><td>'.$langs->trans('VereineIdentityBoundTo').'</td>';
print '<td>'.$langs->trans('VereineIdentityExpires').'</td><td>'.$langs->trans('Status').'</td></tr>';
foreach ($invitations as $invite) {
	$state = $invite['used_at'] !== '' ? 'used' : ($invite['expires_at'] < $today ? 'expired' : 'open');
	print '<tr class="oddeven" data-identity-invite="'.((int) $invite['id']).'" data-identity-invite-state="'.$state.'">';
	print '<td>'.dol_escape_htmltag($invite['client']).'</td>';
	print '<td class="nowraponall">'.($invite['member_id'] > 0 ? $langs->trans('VereineIdentityMember', (int) $invite['member_id'])
		: $langs->trans('VereineIdentityApplication', (int) $invite['application_id'])).'</td>';
	print '<td class="nowraponall">'.dol_print_date($db->jdate($invite['expires_at']), 'dayhour').'</td>';
	print '<td>'.$langs->trans('VereineIdentityInviteState_'.$state).'</td></tr>';
}
if (!$invitations) {
	print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium" data-identity-noinvite="1">'.$langs->trans('VereineIdentityNoInvite').'</span></td></tr>';
}
print '</table></div>';

// Which own data a member's change through an application takes at once; everything else waits for the board (#164).
dol_include_once('/vereine/class/vereineprofiles.class.php');
$direct = VereineProfiles::directFields();
print '<br>'.load_fiche_titre($langs->trans('VereineProfileDirectTitle'), '', '', 0, 'vereineprofiledirect');
print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineProfileDirectHowTo').'</div>';
print '<form method="POST" action="'.$self.'#vereineprofiledirect" name="vereineprofiledirect"><input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savedirect">';
foreach (VereineProfileRules::DIRECT_ALLOWED as $field) {
	print '<label class="paddingright"><input type="checkbox" name="direct_'.$field.'" value="1"'.(in_array($field, $direct, true) ? ' checked' : '').'> '
		.$langs->trans('VereineProfileField_'.$field).'</label> ';
}
print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('Save')).'"></form>';

// Dolibarr's web portal with the page of the association (#25): the same services, for the member logged in there.
dol_include_once('/vereine/class/vereineportal.class.php');
$portalAbilities = VereinePortal::capabilities();
$portalSupported = VereinePortal::supported(DOL_VERSION);
print '<br>'.load_fiche_titre($langs->trans('VereinePortalSetupTitle'), '', '', 0, 'vereineportal');
print '<div class="opacitymedium paddingbottom" data-portal-supported="'.($portalSupported ? 1 : 0).'">'.$langs->trans($portalSupported ? 'VereinePortalSetupHowTo' : 'VereinePortalUnsupported', DOL_VERSION).'</div>';
if ($portalSupported) {
	print '<form method="POST" action="'.$self.'#vereineportal" name="vereineportal"><input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="saveportal">';
	foreach (VereinePortal::OFFERED as $ability) {
		print '<label class="paddingright"><input type="checkbox" name="portal_'.$ability.'" value="1"'.(in_array($ability, $portalAbilities, true) ? ' checked' : '').'> '
			.$langs->trans('VereineIdentityCapability_'.$ability).'</label> ';
	}
	print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('Save')).'"></form>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
