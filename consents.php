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
 * \file    consents.php
 * \ingroup vereine
 * \brief   The declaration of consent to print (#110): chosen consents, one page per member.
 *
 * For one member from the tab Verein of the member, or for everybody who is still missing one of the
 * chosen consents. The texts come from the setup in their current version.
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

require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
require_once __DIR__.'/class/vereineconsentform.class.php';
require_once __DIR__.'/class/vereinelog.class.php';
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

// Not $form: that is Dolibarr's own Form object on every page.
$declaration = new VereineConsentForm($db);
$consents = new VereineConsents($db);
$action = GETPOST('action', 'aZ09');
$memberId = GETPOSTINT('member');
$member = null;
if ($memberId > 0) {
	$member = new Adherent($db);
	if ($member->fetch($memberId) <= 0) {
		$member = null;
		$memberId = 0;
	}
}
$self = $_SERVER['PHP_SELF'].($memberId > 0 ? '?member='.$memberId : '');


/*
 * Actions
 */

if ($action === 'pdf') {
	$file = VereineConsentForm::path();
	if (!is_file($file)) {
		accessforbidden();
	}
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
} elseif ($action === 'build') {
	$codes = array();
	foreach (GETPOST('codes', 'array') as $code) {
		if (is_scalar($code) && VereineConsentRules::isCode((string) $code)) {
			$codes[] = (string) $code;
		}
	}
	$members = $memberId > 0 ? array($memberId) : $declaration->membersMissing($codes);
	if (!$codes) {
		setEventMessages($langs->trans('VereineConsentFormNoCodes'), null, 'errors');
	} elseif (!$members) {
		setEventMessages($langs->trans('VereineConsentFormNobody'), null, 'warnings');
	} elseif ($declaration->build($members, $codes, $langs, VereineConsentForm::path()) !== '') {
		VereineLog::add($db, $user, VereineLog::CONSENT_FORM, $memberId, 0, implode(',', $codes).' / '.count($members).' members');
		setEventMessages($langs->trans('VereineConsentFormBuilt', count($members)), null, 'mesgs');
		header('Location: '.$self.'#vereineconsentform');
		exit;
	} else {
		setEventMessages($declaration->error, null, 'errors');
	}
}


/*
 * View
 */

$texts = $consents->currentTexts();
$file = VereineConsentForm::path();

llxHeader('', $langs->trans('VereineConsentFormTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-consentform');
print load_fiche_titre($langs->trans('VereineConsentFormTitle'), '', 'fa-file-signature');
print '<div class="info" data-consentform-howto="1"><ul>';
print '<li>'.$langs->trans('VereineConsentFormHowTo').'</li>';
print '<li>'.$langs->trans('VereineConsentFormHowToTexts').'</li>';
print '<li>'.$langs->trans('VereineConsentFormHowToBack').'</li>';
print '</ul></div>';

if (!$texts) {
	print '<div class="warning" data-consentform-texts="0">'.$langs->trans('VereineConsentFormNoTexts').'</div>';
} else {
	print '<a name="vereineconsentform"></a>';
	print '<form method="POST" action="'.$self.'#vereineconsentform" name="vereineconsentform">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="build">';
	print '<table class="border centpercent"><tr><td class="titlefieldcreate">'.$langs->trans('VereineConsentFormChoose').'</td><td>';
	foreach ($texts as $code => $text) {
		print '<label class="paddingright" data-consentform-code="'.dol_escape_htmltag($code).'"><input type="checkbox" name="codes[]" value="'.dol_escape_htmltag($code).'" checked> ';
		print dol_escape_htmltag($text['label']).' <span class="opacitymedium small">v'.((int) $text['version']).'</span></label> ';
	}
	print '</td></tr><tr><td>'.$langs->trans('VereineConsentFormScope').'</td><td data-consentform-scope="'.($memberId > 0 ? 'member' : 'missing').'">';
	print $memberId > 0 ? $langs->trans('VereineConsentFormScopeMember', dol_escape_htmltag($member->getFullName($langs))) : $langs->trans('VereineConsentFormScopeMissing');
	print '</td></tr></table>';
	print '<div class="center paddingtop"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('VereineConsentFormBuild')).'"></div>';
	print '</form>';
}

if (is_file($file)) {
	print '<div class="paddingtop" data-consentform-pdf="1"><a href="'.$self.($memberId > 0 ? '&amp;' : '?').'action=pdf&amp;token='.newToken().'">';
	print img_picto('', 'pdf').' '.$langs->trans('VereineConsentFormDownload').'</a></div>';
}

llxFooter();
$db->close();
