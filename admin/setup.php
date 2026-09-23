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
require_once __DIR__.'/../class/vereineassociationrules.class.php';
require_once __DIR__.'/../class/vereineorganization.class.php';
require_once __DIR__.'/../class/vereinemail.class.php';
require_once __DIR__.'/../class/vereinesetupguide.class.php';

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

if ($action == 'savemail') {
	$mailer = new VereineMail($db);
	$saved = $mailer->saveSender(GETPOST('VEREINE_MAIL_FROM', 'alphanohtml'));
	if ($saved > 0) {
		setEventMessages($langs->trans('VereineMailSenderSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereinemail');
		exit;
	}
	setEventMessages($langs->trans($saved === 0 ? 'VereineMailSenderInvalid' : 'Error'), null, 'errors');
} elseif ($action == 'testmail') {
	$mailer = new VereineMail($db);
	$to = trim((string) $user->email);
	if ($to === '') {
		setEventMessages($langs->trans('VereineMailTestNoAddress'), null, 'errors');
	} elseif ($mailer->send($langs->transnoentities('VereineMailTestSubject'), $to, $langs->transnoentities('VereineMailTestText', VereineMail::sender()), 'vereinetest')) {
		setEventMessages($langs->trans('VereineMailTestSent', $to, VereineMail::sender()), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereinemail');
		exit;
	} else {
		setEventMessages(null, array(VereineMail::describe($mailer->error, $langs), VereineMailRules::readable($mailer->error)), 'errors');
	}
}

if ($action == 'update') {
	$registerNumber = VereineAssociationRules::normalizeZvr(GETPOST('VEREINE_REGISTER_NUMBER', 'alphanohtml'));
	$authority = trim(GETPOST('VEREINE_AUTHORITY', 'alphanohtml'));
	$nonprofit = GETPOSTINT('VEREINE_NONPROFIT') ? '1' : '0';
	$pdfRegister = GETPOSTINT('VEREINE_PDF_REGISTER') ? '1' : '0';
	$foundedYear = GETPOSTINT('foundedyear');
	$foundedMonth = GETPOSTINT('foundedmonth');
	$foundedDay = GETPOSTINT('foundedday');

	$errors = array();
	$error = VereineAssociationRules::validateZvr($registerNumber);
	if ($error !== '') {
		$errors[] = $langs->trans($error);
	}
	$error = VereineAssociationRules::validateFoundingDate($foundedYear, $foundedMonth, $foundedDay, dol_now());
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
			'VEREINE_REGISTER_NUMBER' => $registerNumber,
			'VEREINE_AUTHORITY' => $authority,
			'VEREINE_FOUNDED' => $founded,
			'VEREINE_NONPROFIT' => $nonprofit,
			'VEREINE_PDF_REGISTER' => $pdfRegister,
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
		'VEREINE_REGISTER_NUMBER' => GETPOST('VEREINE_REGISTER_NUMBER', 'alphanohtml'),
		'VEREINE_AUTHORITY' => GETPOST('VEREINE_AUTHORITY', 'alphanohtml'),
		'VEREINE_NONPROFIT' => GETPOSTINT('VEREINE_NONPROFIT') ? '1' : '0',
		'VEREINE_PDF_REGISTER' => GETPOSTINT('VEREINE_PDF_REGISTER') ? '1' : '0',
	);
	$foundedTimestamp = GETPOSTINT('foundedyear') ? dol_mktime(12, 0, 0, GETPOSTINT('foundedmonth'), GETPOSTINT('foundedday'), GETPOSTINT('foundedyear')) : '';
} else {
	$current = array();
	foreach (VereineOrganization::SETTINGS as $name) {
		$current[$name] = getDolGlobalString($name);
	}
	// Not part of the association data the API returns: only how invoices look.
	$current['VEREINE_PDF_REGISTER'] = getDolGlobalString('VEREINE_PDF_REGISTER', '1');
	$foundedTimestamp = '';
	if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $current['VEREINE_FOUNDED'], $parts)) {
		$foundedTimestamp = dol_mktime(12, 0, 0, (int) $parts[2], (int) $parts[3], (int) $parts[1]);
	}
}

$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = vereineAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $title, -1, 'fa-landmark');
print (new VereineSetupGuide($db))->hint(dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver'));

print '<span class="opacitymedium">'.$langs->trans('VereineSetupIntro').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinesetup" id="vereinesetup">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefieldcreate">'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

// ZVR number
print '<tr class="oddeven"><td><label for="VEREINE_REGISTER_NUMBER">'.$langs->trans('VereineRegisterNumberZVR').'</label></td><td>';
print '<input type="text" class="minwidth200" id="VEREINE_REGISTER_NUMBER" name="VEREINE_REGISTER_NUMBER" value="'.dol_escape_htmltag($current['VEREINE_REGISTER_NUMBER']).'" maxlength="32">';
print '<div class="opacitymedium small">'.$langs->trans('VereineRegisterNumberHelpZVR').'</div>';
print '<input type="checkbox" id="VEREINE_PDF_REGISTER" name="VEREINE_PDF_REGISTER" value="1"'.($current['VEREINE_PDF_REGISTER'] === '1' ? ' checked' : '').'>';
print ' <label for="VEREINE_PDF_REGISTER">'.$langs->trans('VereinePdfRegisterSetting').'</label>';
print '</td></tr>';

print '<tr class="oddeven"><td><label for="VEREINE_AUTHORITY">'.$langs->trans('VereineAuthority').'</label></td><td>';
print '<input type="text" class="minwidth300" id="VEREINE_AUTHORITY" name="VEREINE_AUTHORITY" value="'.dol_escape_htmltag($current['VEREINE_AUTHORITY']).'" maxlength="128">';
print '<div class="opacitymedium small">'.$langs->trans('VereineAuthorityHelp').'</div>';
print '</td></tr>';

// Founding date
print '<tr class="oddeven"><td>'.$langs->trans('VereineFounded').'</td><td>';
print $form->selectDate($foundedTimestamp, 'founded', 0, 0, 1, 'vereinesetup', 1, 0);
print '</td></tr>';

// Non-profit
print '<tr class="oddeven"><td><label for="VEREINE_NONPROFIT">'.$langs->trans('VereineNonprofit').'</label></td><td>';
print '<input type="checkbox" id="VEREINE_NONPROFIT" name="VEREINE_NONPROFIT" value="1"'.($current['VEREINE_NONPROFIT'] === '1' ? ' checked' : '').'>';
print ' <span class="opacitymedium small">'.$langs->trans('VereineNonprofitHelp').'</span>';
print '</td></tr>';

// The purpose belongs to the statutes (§ 3 Abs. 2 Z 2 VerG), so it is entered there and only shown here.
print '<tr class="oddeven"><td class="tdtop" id="VEREINE_PURPOSE">'.$langs->trans('VereinePurpose').'</td><td>';
print '<span data-purpose-here="0">';
$shownPurpose = trim(getDolGlobalString('VEREINE_PURPOSE'));
print $shownPurpose !== '' ? nl2br(dol_escape_htmltag($shownPurpose, 0, 1))
	: '<span class="opacitymedium">'.$langs->trans('VereineStatutePurposeEmpty').'</span>';
print '</span> <a href="'.dol_buildpath('/vereine/admin/statutes.php', 1).'#vereinestatutetext">'.img_picto($langs->trans('Modify'), 'edit').' ';
print $langs->trans('VereinePurposeInStatutes').'</a>';
print '<div class="opacitymedium small">'.$langs->trans('VereinePurposeHelp').'</div>';
print '</td></tr>';

print '</table>';
print '</div>';

print '<div class="center"><input type="submit" class="button button-save" name="save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form>';

print '<br>';
print info_admin($langs->trans('VereineSetupCompanyHint').' <a href="'.DOL_URL_ROOT.'/admin/company.php">'.$langs->trans('VereineSetupCompanyLink').'</a>', 0, 0, '1', '');

// E-mails of the association: invitations, minutes, circular resolutions and reminders.
print '<br>'.load_fiche_titre($langs->trans('VereineMailTitle'), '', '', 0, 'vereinemail');
print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineMailHowTo').'</div>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#vereinemail" name="vereinemail">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savemail">';
print '<table class="border centpercent"><tr><td class="titlefieldcreate"><label for="VEREINE_MAIL_FROM">'.$langs->trans('VereineMailFrom').'</label></td>';
print '<td><input type="email" id="VEREINE_MAIL_FROM" name="VEREINE_MAIL_FROM" class="minwidth300" maxlength="128" value="'.dol_escape_htmltag(getDolGlobalString(VereineMail::CONST_FROM)).'"';
print ' placeholder="'.dol_escape_htmltag(getDolGlobalString('MAIN_MAIL_EMAIL_FROM')).'">';
print '<div class="opacitymedium small">'.$langs->trans('VereineMailFromHelp', dol_escape_htmltag(VereineMail::sender())).'</div></td></tr></table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#vereinemail" name="vereinetestmail" class="center paddingtop">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="testmail">';
print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineMailTest')).'"> ';
print '<span class="opacitymedium small">'.$langs->trans('VereineMailTestHelp', dol_escape_htmltag((string) $user->email)).'</span>';
print '</form>';

// The texts of these e-mails are Dolibarr templates; the association changes them in Dolibarr's editor.
dol_include_once('/vereine/class/vereinemailtemplates.class.php');
$mailTemplates = new VereineMailTemplates($db);
print '<br>'.load_fiche_titre($langs->trans('VereineMailTemplatesTitle'), '', '', 0, 'vereinemailtemplates');
print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineMailTemplatesHowTo').'</div>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineMailTemplateKind').'</td><td>'.$langs->trans('VereineMailTemplateUsed').'</td><td></td></tr>';
foreach (VereineMailTemplates::TYPES as $type) {
	$template = $mailTemplates->find($type, $langs);
	$standard = VereineMailTemplates::defaults($type, $langs);
	$state = $template === null ? 'standard' : (VereineMailTemplates::plain($template['content']) === $standard['content'] && $template['topic'] === $standard['topic'] ? 'unchanged' : 'changed');
	print '<tr class="oddeven" data-mail-template="'.$type.'" data-template-state="'.$state.'"><td>'.$langs->trans('VereineMailTemplateType_'.$type).'</td>';
	print '<td>'.$langs->trans('VereineMailTemplateState_'.$state).'</td>';
	print '<td class="right"><a href="'.DOL_URL_ROOT.'/admin/mails_templates.php?search_type_template='.urlencode($type).'">'.$langs->trans('VereineMailTemplateEdit').'</a></td></tr>';
}
print '</table></div>';
vereinePlaceholderList(array('association', 'member', 'meeting', 'circular'), (new VereinePlaceholders($db))->associationValues());

print dol_get_fiche_end();

llxFooter();
$db->close();
