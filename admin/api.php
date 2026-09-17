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
 * \file    admin/api.php
 * \ingroup vereine
 * \brief   The REST API of the module: every endpoint with its rights and an example, the users with API access, webhooks.
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
require_once __DIR__.'/../class/vereineapirules.class.php';

$langs->loadLangs(array('admin', 'members', 'bills', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$endpoints = VereineApiRules::endpoints(json_decode((string) @file_get_contents(__DIR__.'/../docs/openapi.json'), true));

// Labels of the rights the endpoints need, from Dolibarr's list of rights.
$rightIds = array();
$resql = $db->query("SELECT id, module, perms, subperms FROM ".MAIN_DB_PREFIX."rights_def WHERE entity = ".((int) $conf->entity)." AND module IN ('vereine', 'facture')");
while ($resql && ($obj = $db->fetch_object($resql))) {
	$rightIds[VereineApiRules::rightKey($obj->module, $obj->perms, $obj->subperms)] = (int) $obj->id;
}
$rightLabel = function ($key) use ($langs, $rightIds) {
	return isset($rightIds[$key]) ? $langs->trans('Permission'.$rightIds[$key]) : $key;
};

// Users with an API key and their rights, directly or through their groups.
$apiUsers = array();
$resql = $db->query("SELECT rowid, login, admin, statut FROM ".MAIN_DB_PREFIX."user WHERE entity IN (0, ".((int) $conf->entity).") AND api_key IS NOT NULL AND api_key <> '' ORDER BY login");
while ($resql && ($obj = $db->fetch_object($resql))) {
	$apiUsers[(int) $obj->rowid] = array('login' => (string) $obj->login, 'admin' => (int) $obj->admin === 1, 'active' => (int) $obj->statut === 1, 'rights' => array());
}
if ($apiUsers) {
	$ids = implode(', ', array_keys($apiUsers));
	$sql = "SELECT ur.fk_user as user_id, rd.module, rd.perms, rd.subperms FROM ".MAIN_DB_PREFIX."user_rights as ur INNER JOIN ".MAIN_DB_PREFIX."rights_def as rd ON rd.id = ur.fk_id";
	$sql .= " WHERE ur.fk_user IN (".$ids.")";
	$sql .= " UNION SELECT ugu.fk_user as user_id, rd.module, rd.perms, rd.subperms FROM ".MAIN_DB_PREFIX."usergroup_user as ugu";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."usergroup_rights as ugr ON ugr.fk_usergroup = ugu.fk_usergroup INNER JOIN ".MAIN_DB_PREFIX."rights_def as rd ON rd.id = ugr.fk_id";
	$sql .= " WHERE ugu.fk_user IN (".$ids.")";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$apiUsers[(int) $obj->user_id]['rights'][] = VereineApiRules::rightKey($obj->module, $obj->perms, $obj->subperms);
	}
}

$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-api');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');
print dol_get_fiche_head(vereineAdminPrepareHead(), 'api', $title, -1, 'fa-landmark');

$base = rtrim(DOL_MAIN_URL_ROOT, '/').'/api/index.php';
if (!isModEnabled('api')) {
	print '<div class="warning" data-api-module="0">'.$langs->trans('VereineApiModuleOff').' <a href="'.DOL_URL_ROOT.'/admin/modules.php?search_keyword=api">'.$langs->trans('VereineApiModuleEnable').'</a></div>';
}
print '<div class="info" data-api-howto="1"><ol>';
foreach (array('VereineApiStepUser', 'VereineApiStepRights', 'VereineApiStepKey', 'VereineApiStepServer', 'VereineApiStepExplorer') as $step) {
	print '<li>'.$langs->trans($step).'</li>';
}
print '</ol><a href="'.$base.'/explorer" target="_blank" rel="noopener">'.$langs->trans('VereineApiExplorer').'</a></div>';

// Endpoints.
print load_fiche_titre($langs->trans('VereineApiEndpoints', count($endpoints)), '', '', 0, 'vereineapiendpoints');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineApiEndpoint').'</td><td>'.$langs->trans('VereineApiPurpose').'</td><td>'.$langs->trans('VereineApiRights').'</td>';
print '<td>'.$langs->trans('VereineApiExample').'</td></tr>';
foreach ($endpoints as $endpoint) {
	$rights = array();
	foreach ($endpoint['rights'] as $group) {
		$rights[] = implode(' '.$langs->trans('VereineApiOr').' ', array_map($rightLabel, (array) $group));
	}
	$example = 'curl -H "DOLAPIKEY: …" '.($endpoint['method'] !== 'GET' ? '-X '.$endpoint['method'].' -H "Content-Type: application/json" -d \'{…}\' ' : '').$base.$endpoint['path'];
	print '<tr class="oddeven tdtop" data-endpoint="'.dol_escape_htmltag($endpoint['method'].' '.$endpoint['path']).'" data-rights="'.count($endpoint['rights']).'">';
	print '<td class="nowraponall"><strong>'.$endpoint['method'].'</strong> <code>'.dol_escape_htmltag($endpoint['path']).'</code>';
	if ($endpoint['parameters']) {
		print '<br><span class="opacitymedium small">'.dol_escape_htmltag(implode(', ', $endpoint['parameters'])).'</span>';
	}
	print '</td><td>'.$langs->trans('VereineApiEndpoint_'.$endpoint['operation']).'</td>';
	print '<td class="small"><ul class="paddingleft">';
	foreach ($rights as $right) {
		print '<li>'.dol_escape_htmltag($right).'</li>';
	}
	print '</ul></td><td class="small"><code>'.dol_escape_htmltag($example).'</code></td></tr>';
}
print '</table></div>';
print '<div class="opacitymedium small paddingtop">'.$langs->trans('VereineApiDocs').'</div><br>';

// Users with API access.
print load_fiche_titre($langs->trans('VereineApiUsers'), '', '', 0, 'vereineapiusers');
print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineApiUsersHelp').'</div>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Login').'</td><td>'.$langs->trans('Status').'</td><td>'.$langs->trans('VereineApiUserRights').'</td>';
print '<td>'.$langs->trans('VereineApiUserEndpoints').'</td></tr>';
if (!$apiUsers) {
	print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('VereineApiUsersNone').'</span></td></tr>';
}
foreach ($apiUsers as $userId => $apiUser) {
	$callable = array();
	foreach ($endpoints as $endpoint) {
		if (VereineApiRules::canCall($endpoint['rights'], $apiUser['rights'], $apiUser['admin'])) {
			$callable[] = $endpoint['method'].' '.$endpoint['path'];
		}
	}
	$relevant = array_values(array_unique(array_intersect($apiUser['rights'], array_keys($rightIds))));
	print '<tr class="oddeven tdtop" data-api-user="'.dol_escape_htmltag($apiUser['login']).'" data-admin="'.($apiUser['admin'] ? 1 : 0).'" data-endpoints="'.count($callable).'">';
	print '<td><a href="'.DOL_URL_ROOT.'/user/card.php?id='.$userId.'">'.dol_escape_htmltag($apiUser['login']).'</a></td>';
	print '<td>'.$langs->trans($apiUser['active'] ? 'Enabled' : 'Disabled').($apiUser['admin'] ? ' '.dolGetBadge($langs->trans('Administrator'), '', 'danger') : '').'</td><td class="small">';
	if ($apiUser['admin']) {
		print '<span class="error">'.$langs->trans('VereineApiUserAdmin').'</span>';
	} else {
		print $relevant ? dol_escape_htmltag(implode(', ', array_map($rightLabel, $relevant))) : '<span class="opacitymedium">'.$langs->trans('VereineApiUserNoRights').'</span>';
	}
	print '</td><td class="small">'.($callable ? dol_escape_htmltag(implode(', ', $callable)) : '<span class="opacitymedium">-</span>').'</td></tr>';
}
print '</table></div><br>';

// Webhooks.
print load_fiche_titre($langs->trans('VereineApiWebhooks'), '', '', 0, 'vereineapiwebhooks');
print '<div class="info" data-api-webhooks="1"><ul>';
foreach (array('VereineApiWebhookWhat', 'VereineApiWebhookSetup', 'VereineApiWebhookToken', 'VereineApiWebhookRead') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
print '</ul><code>{"triggercode": "VEREINE_MEMBER_CHANGED", "object": {"member_id": 12, "cause": "PAYMENT_CUSTOMER_CREATE", "occurred_at": "2026-09-17T08:00:00Z"}}</code></div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
