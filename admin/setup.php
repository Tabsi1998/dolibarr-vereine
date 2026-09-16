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
 * \file    admin/setup.php
 * \ingroup vereine
 * \brief   Setup of the association: country profile and register data.
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
 * @var Societe $mysoc
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/vereine.lib.php';
require_once __DIR__.'/../class/vereineprofile.class.php';
require_once __DIR__.'/../class/vereineorganization.class.php';

$langs->loadLangs(array('admin', 'members', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');


/*
 * Actions
 */

if ($action == 'update') {
	$profile = GETPOST('VEREINE_COUNTRY_PROFILE', 'aZ09');
	$registerNumber = VereineProfile::normalizeRegisterNumber($profile, GETPOST('VEREINE_REGISTER_NUMBER', 'alphanohtml'));
	$registerCourt = trim(GETPOST('VEREINE_REGISTER_COURT', 'alphanohtml'));
	$authority = trim(GETPOST('VEREINE_AUTHORITY', 'alphanohtml'));
	$purpose = trim(GETPOST('VEREINE_PURPOSE', 'alphanohtml'));
	$nonprofit = GETPOSTINT('VEREINE_NONPROFIT') ? '1' : '0';
	$foundedYear = GETPOSTINT('foundedyear');
	$foundedMonth = GETPOSTINT('foundedmonth');
	$foundedDay = GETPOSTINT('foundedday');

	$errors = array();
	$warnings = array();
	if (!VereineProfile::isSupported($profile)) {
		$errors[] = $langs->trans('VereineErrorProfile');
	}
	$error = VereineProfile::validateRegisterNumber($profile, $registerNumber);
	if ($error !== '' && $profile !== getDolGlobalString('VEREINE_COUNTRY_PROFILE')) {
		// The number still belongs to the previous country; its field only
		// changes after saving. Drop it and ask for the new one.
		$registerNumber = '';
		$warnings[] = $langs->trans('VereineWarningRegisterNumberCleared');
	} elseif ($error !== '') {
		$errors[] = $langs->trans($error);
	}
	$error = VereineProfile::validatePurpose($purpose);
	if ($error !== '') {
		$errors[] = $langs->trans($error, VereineProfile::PURPOSE_MAX_LENGTH);
	}
	$error = VereineProfile::validateFoundingDate($foundedYear, $foundedMonth, $foundedDay, dol_now());
	if ($error !== '') {
		$errors[] = $langs->trans($error);
	}

	if ($errors) {
		setEventMessages(null, $errors, 'errors');
		// Keep what was entered on the form, so nothing has to be typed again.
		$action = 'edit';
	} else {
		$founded = $foundedYear ? sprintf('%04d-%02d-%02d', $foundedYear, $foundedMonth, $foundedDay) : '';
		$values = array(
			'VEREINE_COUNTRY_PROFILE' => $profile,
			'VEREINE_REGISTER_NUMBER' => $registerNumber,
			'VEREINE_REGISTER_COURT' => $profile === VereineProfile::GERMANY ? $registerCourt : '',
			'VEREINE_AUTHORITY' => $profile === VereineProfile::AUSTRIA ? $authority : '',
			'VEREINE_FOUNDED' => $founded,
			'VEREINE_NONPROFIT' => $nonprofit,
			'VEREINE_PURPOSE' => $purpose,
		);
		$db->begin();
		$failed = 0;
		foreach ($values as $name => $value) {
			if (dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity) <= 0) {
				$failed++;
			}
		}
		if ($failed) {
			$db->rollback();
			setEventMessages($langs->trans('Error').' '.$db->lasterror(), null, 'errors');
		} else {
			$db->commit();
			setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
			if ($warnings) {
				setEventMessages(null, $warnings, 'warnings');
			}
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}
	}
}


/*
 * View
 */

$form = new Form($db);

if ($action == 'edit') {
	// A form sent back with errors shows what was entered.
	$current = array(
		'VEREINE_COUNTRY_PROFILE' => GETPOST('VEREINE_COUNTRY_PROFILE', 'aZ09'),
		'VEREINE_REGISTER_NUMBER' => GETPOST('VEREINE_REGISTER_NUMBER', 'alphanohtml'),
		'VEREINE_REGISTER_COURT' => GETPOST('VEREINE_REGISTER_COURT', 'alphanohtml'),
		'VEREINE_AUTHORITY' => GETPOST('VEREINE_AUTHORITY', 'alphanohtml'),
		'VEREINE_NONPROFIT' => GETPOSTINT('VEREINE_NONPROFIT') ? '1' : '0',
		'VEREINE_PURPOSE' => GETPOST('VEREINE_PURPOSE', 'alphanohtml'),
	);
	$foundedTimestamp = GETPOSTINT('foundedyear') ? dol_mktime(12, 0, 0, GETPOSTINT('foundedmonth'), GETPOSTINT('foundedday'), GETPOSTINT('foundedyear')) : '';
} else {
	$current = array();
	foreach (VereineOrganization::SETTINGS as $name) {
		$current[$name] = getDolGlobalString($name);
	}
	$foundedTimestamp = '';
	if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $current['VEREINE_FOUNDED'], $parts)) {
		$foundedTimestamp = dol_mktime(12, 0, 0, (int) $parts[2], (int) $parts[3], (int) $parts[1]);
	}
}
if (!VereineProfile::isSupported($current['VEREINE_COUNTRY_PROFILE'])) {
	$current['VEREINE_COUNTRY_PROFILE'] = VereineProfile::suggestFromCountry($mysoc->country_code);
}
$profile = $current['VEREINE_COUNTRY_PROFILE'];

$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = vereineAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $title, -1, 'fa-landmark');

print '<span class="opacitymedium">'.$langs->trans('VereineSetupIntro').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinesetup" id="vereinesetup">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefieldcreate">'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

// Country profile
$profiles = array();
foreach (VereineProfile::codes() as $code) {
	$profiles[$code] = $langs->trans('VereineProfile'.$code).(VereineProfile::isComplete($code) ? '' : ' - '.$langs->trans('VereineProfilePreview'));
}
print '<tr class="oddeven"><td class="fieldrequired"><label for="VEREINE_COUNTRY_PROFILE">'.$langs->trans('VereineCountryProfile').'</label></td><td>';
print Form::selectarray('VEREINE_COUNTRY_PROFILE', $profiles, $profile, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
print '<div class="opacitymedium small">'.$langs->trans('VereineCountryProfileHelp').'</div>';
print '</td></tr>';

// Register number
print '<tr class="oddeven"><td><label for="VEREINE_REGISTER_NUMBER">'.$langs->trans('VereineRegisterNumber'.VereineProfile::registerKind($profile)).'</label></td><td>';
print '<input type="text" class="minwidth200" id="VEREINE_REGISTER_NUMBER" name="VEREINE_REGISTER_NUMBER" value="'.dol_escape_htmltag($current['VEREINE_REGISTER_NUMBER']).'" maxlength="32">';
print '<div class="opacitymedium small">'.$langs->trans('VereineRegisterNumberHelp'.VereineProfile::registerKind($profile)).'</div>';
print '</td></tr>';

if ($profile === VereineProfile::GERMANY) {
	print '<tr class="oddeven"><td><label for="VEREINE_REGISTER_COURT">'.$langs->trans('VereineRegisterCourt').'</label></td><td>';
	print '<input type="text" class="minwidth300" id="VEREINE_REGISTER_COURT" name="VEREINE_REGISTER_COURT" value="'.dol_escape_htmltag($current['VEREINE_REGISTER_COURT']).'" maxlength="128">';
	print '</td></tr>';
} else {
	print '<tr class="oddeven"><td><label for="VEREINE_AUTHORITY">'.$langs->trans('VereineAuthority').'</label></td><td>';
	print '<input type="text" class="minwidth300" id="VEREINE_AUTHORITY" name="VEREINE_AUTHORITY" value="'.dol_escape_htmltag($current['VEREINE_AUTHORITY']).'" maxlength="128">';
	print '<div class="opacitymedium small">'.$langs->trans('VereineAuthorityHelp').'</div>';
	print '</td></tr>';
}

// Founding date
print '<tr class="oddeven"><td>'.$langs->trans('VereineFounded').'</td><td>';
print $form->selectDate($foundedTimestamp, 'founded', 0, 0, 1, 'vereinesetup', 1, 0);
print '</td></tr>';

// Non-profit
print '<tr class="oddeven"><td><label for="VEREINE_NONPROFIT">'.$langs->trans('VereineNonprofit').'</label></td><td>';
print '<input type="checkbox" id="VEREINE_NONPROFIT" name="VEREINE_NONPROFIT" value="1"'.($current['VEREINE_NONPROFIT'] === '1' ? ' checked' : '').'>';
print ' <span class="opacitymedium small">'.$langs->trans('VereineNonprofitHelp').'</span>';
print '</td></tr>';

// Purpose
print '<tr class="oddeven"><td class="tdtop"><label for="VEREINE_PURPOSE">'.$langs->trans('VereinePurpose').'</label></td><td>';
print '<textarea id="VEREINE_PURPOSE" name="VEREINE_PURPOSE" class="quatrevingtpercent" rows="4" maxlength="'.VereineProfile::PURPOSE_MAX_LENGTH.'">'.dol_escape_htmltag($current['VEREINE_PURPOSE']).'</textarea>';
print '<div class="opacitymedium small">'.$langs->trans('VereinePurposeHelp').'</div>';
print '</td></tr>';

print '</table>';
print '</div>';

print '<div class="center"><input type="submit" class="button button-save" name="save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form>';

print '<br>';
print info_admin($langs->trans('VereineSetupCompanyHint').' <a href="'.DOL_URL_ROOT.'/admin/company.php">'.$langs->trans('VereineSetupCompanyLink').'</a>', 0, 0, '1', '');

print dol_get_fiche_end();

llxFooter();
$db->close();
