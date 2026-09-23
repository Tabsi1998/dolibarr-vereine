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
 * \file    admin/start.php
 * \ingroup vereine
 * \brief   First steps (#126): a new association set up step by step, each step checked against its data.
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
require_once __DIR__.'/../class/vereinesetupguide.class.php';

$langs->loadLangs(array('admin', 'members', 'companies', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$guide = new VereineSetupGuide($db);
$action = GETPOST('action', 'aZ09');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');

if ($action === 'skip' || $action === 'unskip') {
	if ($guide->skip(GETPOST('step', 'aZ09'), $action === 'skip', $user) < 0) {
		setEventMessages($guide->error, null, 'errors');
	} else {
		header('Location: '.$_SERVER['PHP_SELF'].'#step-'.GETPOST('step', 'aZ09'));
		exit;
	}
} elseif ($action === 'mailtest') {
	$result = $guide->sendTest($user, $langs);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineSetupMailSent', $user->email), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#step-mail');
		exit;
	}
	setEventMessages($result === 0 ? $langs->trans('VereineSetupMailNoAddress') : $langs->trans('VereineSetupMailFailed', $guide->error), null, 'errors');
} elseif ($action === 'hide' || $action === 'show') {
	if ($guide->hide($action === 'hide') > 0) {
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
}

// Where every step leads: the pages that already hold its settings.
$links = array(
	'association' => array(array(DOL_URL_ROOT.'/admin/company.php', 'VereineSetupLinkCompany'), array(dol_buildpath('/vereine/admin/setup.php', 1), 'VereineSetupLinkAssociation'),
		array(dol_buildpath('/vereine/admin/statutes.php', 1), 'VereineSetupLinkPurpose')),
	'modules' => array(array(DOL_URL_ROOT.'/admin/modules.php', 'VereineSetupLinkModules')),
	'statutes' => array(array(dol_buildpath('/vereine/admin/statutes.php', 1), 'VereineSetupLinkStatutes')),
	'board' => array(array(dol_buildpath('/vereine/admin/functions.php', 1), 'VereineSetupLinkFunctionCatalogue'),
		array(dol_buildpath('/vereine/functions.php', 1), 'VereineSetupLinkFunctions'), array(DOL_URL_ROOT.'/user/list.php', 'VereineSetupLinkUsers')),
	'fees' => array(array(DOL_URL_ROOT.'/adherents/type.php', 'VereineSetupLinkMemberTypes'), array(dol_buildpath('/vereine/admin/fees.php', 1), 'VereineSetupLinkFees')),
	'consents' => array(array(dol_buildpath('/vereine/admin/consents.php', 1), 'VereineSetupLinkConsents')),
	'mail' => array(array(DOL_URL_ROOT.'/admin/mails.php', 'VereineSetupLinkMail')),
	'meetings' => array(array(dol_buildpath('/vereine/admin/meetings.php', 1), 'VereineSetupLinkMeetings'),
		array(dol_buildpath('/vereine/admin/signatures.php', 1), 'VereineSetupLinkSignatures')),
	'website' => array(array(dol_buildpath('/vereine/admin/api.php', 1), 'VereineSetupLinkApi')),
);
$overview = $guide->overview($today);
$facts = $overview['facts'];
$badges = array('done' => 'badge-status4', 'open' => 'badge-status1', 'skipped' => 'badge-status9', 'optional' => 'badge-status9');

$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-start');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');
print dol_get_fiche_head(vereineAdminPrepareHead(), 'start', $title, -1, 'fa-landmark');
print '<div class="info" data-setup-progress="'.$overview['progress']['finished'].'/'.$overview['progress']['total'].'">'
	.$langs->trans('VereineSetupGuideIntro').'<br><strong>'.$langs->trans($overview['progress']['complete'] ? 'VereineSetupComplete' : 'VereineSetupProgress',
	$overview['progress']['finished'], $overview['progress']['total']).'</strong></div>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
$number = 0;
foreach ($overview['states'] as $step => $state) {
	$number++;
	print '<tr class="oddeven tdtop" id="step-'.$step.'" data-setup-step="'.$step.'" data-setup-state="'.$state.'">';
	print '<td class="nowraponall"><strong>'.$number.'.</strong></td><td>';
	print '<strong>'.$langs->trans('VereineSetupStep_'.$step).'</strong> <span class="badge badge-status '.$badges[$state].'">'.$langs->trans('VereineSetupState_'.$state).'</span>';
	print '<div class="opacitymedium paddingtop">'.$langs->trans('VereineSetupStepHelp_'.$step).'</div>';

	// What exactly is missing, where the data tell it.
	$details = array();
	if ($step === 'association' && $state !== VereineSetupGuideRules::STATE_DONE) {
		foreach (array('name' => 'VereineSetupMissingName', 'town' => 'VereineSetupMissingTown', 'zvr' => 'VereineSetupMissingZvr', 'purpose' => 'VereineSetupMissingPurpose') as $fact => $key) {
			if ((string) $facts[$fact] === '') {
				$details[] = $langs->trans($key);
			}
		}
	} elseif ($step === 'modules') {
		foreach ($facts['modules_missing'] as $module) {
			$details[] = '<span class="error" data-setup-module-missing="'.$module.'">'.$langs->trans('VereineSetupModuleMissing',
				$langs->transnoentitiesnoconv(VereineSetupGuide::REQUIRED_MODULES[$module])).'</span>';
		}
		foreach ($facts['modules_optional'] as $module => $enabled) {
			$details[] = '<span data-setup-module="'.$module.'" data-setup-module-on="'.($enabled ? 1 : 0).'">'.($enabled ? img_picto('', 'tick') : img_picto('', 'fa-circle', 'class="opacitymedium"'))
				.' '.$langs->trans('VereineSetupModule_'.$module).'</span>';
		}
	} elseif ($step === 'board' && $state !== VereineSetupGuideRules::STATE_DONE) {
		if ($facts['functions_missing'] > 0) {
			$details[] = $langs->trans('VereineSetupFunctionsMissing', $facts['functions_missing']);
		}
		if ($facts['board_users'] === 0) {
			$details[] = $langs->trans('VereineSetupBoardUsersMissing');
		}
	} elseif ($step === 'mail') {
		$details[] = $langs->trans('VereineSetupMailMode', dol_escape_htmltag($facts['mail_mode']));
	}
	if ($details) {
		print '<ul class="small">';
		foreach ($details as $detail) {
			print '<li>'.$detail.'</li>';
		}
		print '</ul>';
	}
	print '<div class="paddingtop">';
	foreach ($links[$step] as $link) {
		print '<a class="paddingright" href="'.$link[0].'">'.img_picto('', 'fa-arrow-right', 'class="pictofixedwidth"').$langs->trans($link[1]).'</a> ';
	}
	print '</div>';
	if ($step === 'mail') {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#step-mail" name="vereinesetupmail" class="paddingtop">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="mailtest">';
		print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineSetupMailTest')).'"'.(trim((string) $user->email) === '' ? ' disabled' : '').'>';
		print ' <span class="opacitymedium small">'.dol_escape_htmltag(trim((string) $user->email) !== '' ? $langs->transnoentities('VereineSetupMailTo', $user->email)
			: $langs->transnoentities('VereineSetupMailNoAddress')).'</span></form>';
	}
	print '</td><td class="right nowraponall">';
	if ($state === VereineSetupGuideRules::STATE_OPEN || $state === VereineSetupGuideRules::STATE_OPTIONAL || $state === VereineSetupGuideRules::STATE_SKIPPED) {
		$skipping = $state !== VereineSetupGuideRules::STATE_SKIPPED;
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinesetupskip'.$step.'"><input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="'.($skipping ? 'skip' : 'unskip').'"><input type="hidden" name="step" value="'.$step.'">';
		print '<input type="submit" class="button small'.($skipping ? ' button-cancel' : '').'" value="'.dol_escape_htmltag($langs->trans($skipping ? 'VereineSetupSkip' : 'VereineSetupUnskip')).'"></form>';
	}
	print '</td></tr>';
}
print '</table></div>';

$hidden = getDolGlobalString(VereineSetupGuide::HIDDEN) !== '';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinesetuphint" class="paddingtop">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.($hidden ? 'show' : 'hide').'">';
print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans($hidden ? 'VereineSetupShowHint' : 'VereineSetupHideHint')).'"></form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
