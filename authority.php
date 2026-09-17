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
 * \file    authority.php
 * \ingroup vereine
 * \brief   Letters of the association to its association authority.
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
 * @var Societe $mysoc
 */

require_once __DIR__.'/class/vereineauthorityletters.class.php';
require_once __DIR__.'/class/vereinesignatures.class.php';
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
$letters = new VereineAuthorityLetters($db);
$signatures = new VereineSignatures($db);
$canWrite = $user->hasRight('adherent', 'creer');
$action = GETPOST('action', 'aZ09');
// A refused letter keeps what was entered.
$entered = array();


/*
 * Actions
 */

if ($action === 'download') {
	$file = $letters->path(GETPOSTINT('id'));
	if ($file === '') {
		accessforbidden();
	}
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
} elseif ($action === 'sheet') {
	$run = $signatures->fetch(GETPOSTINT('id'));
	$file = $run !== null && $run['kind'] === VereineSignatureRules::KIND_LETTER ? VereineSignatures::sheetPath($run['id']) : '';
	if ($file === '' || !is_file($file)) {
		accessforbidden();
	}
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
} elseif ($action === 'signed') {
	$run = $signatures->fetch(GETPOSTINT('id'));
	$file = $run !== null && $run['kind'] === VereineSignatureRules::KIND_LETTER ? VereineSignatures::scanPath($run) : '';
	if ($file === '') {
		accessforbidden();
	}
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
} elseif ($action === 'startsign' && $canWrite) {
	$id = GETPOSTINT('id');
	$result = $signatures->start(VereineSignatureRules::KIND_LETTER, $id, $letters->path($id), $today, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineSignatureStarted'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereineletters');
		exit;
	}
	setEventMessages($result < 0 ? $signatures->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $signatures->errors), 'errors');
} elseif ($action === 'sign' && $canWrite) {
	$run = $signatures->fetch(GETPOSTINT('id'));
	$file = $run !== null ? $letters->path($run['object_id']) : '';
	$result = $run === null ? 0 : $signatures->sign($run['id'], GETPOST('password', 'password'), $file, $user, $langs);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineSignatureSigned'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereineletters');
		exit;
	}
	setEventMessages($result < 0 ? $signatures->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $run === null ? array('VereineSignatureErrorNotOpen') : $signatures->errors), 'errors');
} elseif ($action === 'signscan' && $canWrite) {
	$run = $signatures->fetch(GETPOSTINT('id'));
	$upload = isset($_FILES['scan_file']) && is_array($_FILES['scan_file']) ? $_FILES['scan_file'] : array();
	$result = $run === null ? 0 : $signatures->uploadScan($run['id'], $upload, $user, $langs);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineSignatureScanStored'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereineletters');
		exit;
	}
	setEventMessages($result < 0 ? $signatures->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $run === null ? array('VereineSignatureErrorNotOpen') : $signatures->errors), 'errors');
} elseif ($action === 'saveauthority' && $canWrite) {
	$result = $letters->saveAuthority(GETPOST('authority_name', 'alphanohtml'), GETPOST('authority_address', 'restricthtml'), GETPOST('authority_email', 'alphanohtml'), GETPOST('authority_gz', 'alphanohtml'));
	if ($result < 0) {
		setEventMessages($letters->error, null, 'errors');
	} elseif ($result === 0) {
		setEventMessages(null, array_map(array($langs, 'trans'), $letters->errors), 'errors');
	} else {
		setEventMessages($langs->trans('VereineLetterAuthoritySaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
} elseif ($action === 'pickauthority' && $canWrite) {
	$suggestions = VereineAuthorityRules::suggestions();
	$code = GETPOST('authority_code', 'aZ09');
	$current = $letters->authority();
	if (isset($suggestions[$code]) && $letters->saveAuthority($suggestions[$code]['name'], $suggestions[$code]['address'], $suggestions[$code]['email'], $current['gz']) > 0) {
		setEventMessages($langs->trans('VereineLetterAuthoritySaved'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
} elseif ($action === 'writeletter' && $canWrite) {
	$kind = GETPOST('kind', 'aZ09');
	foreach (array('date', 'effective', 'liquidator_name', 'liquidator_birth', 'liquidator_birth_place', 'liquidator_start', 'extract') as $key) {
		$entered[$key] = GETPOST($key, 'alphanohtml');
	}
	foreach (array('address', 'liquidator_address', 'reason') as $key) {
		$entered[$key] = GETPOST($key, 'restricthtml');
	}
	$entered['assets'] = GETPOSTISSET('assets');
	$entered['founders'] = GETPOSTISSET('founders');
	$result = $letters->create($kind, $entered, $user, $langs);
	if ($result > 0) {
		// A written letter goes straight into its signature run, where the statutes ask for signatures.
		$signatures->start(VereineSignatureRules::KIND_LETTER, $result, $letters->path($result), $today, $user);
		setEventMessages($langs->trans('VereineLetterWritten', $langs->transnoentities('VereineLetterKind_'.$kind)), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereineletters');
		exit;
	}
	if ($result < 0) {
		setEventMessages($letters->error, null, 'errors');
	} else {
		setEventMessages(null, array_map(array($langs, 'trans'), $letters->errors), 'errors');
	}
	$entered['kind'] = $kind;
} elseif ($action === 'markfiled' && $canWrite) {
	$result = $letters->markFiled(GETPOSTINT('id'), GETPOST('filed_on', 'alpha'), $user);
	if ($result < 0) {
		setEventMessages($letters->error, null, 'errors');
	} elseif ($result === 0) {
		setEventMessages(null, array_map(array($langs, 'trans'), $letters->errors), 'errors');
	} else {
		header('Location: '.$_SERVER['PHP_SELF'].'#vereineletters');
		exit;
	}
}


/*
 * View
 */

llxHeader('', $langs->trans('VereineLettersTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-authority');
print load_fiche_titre($langs->trans('VereineLettersTitle'), '', 'fa-landmark');

print '<div class="info" data-letters-howto="1"><ul>';
foreach (array('VereineLettersHowToSame', 'VereineLettersHowToSign', 'VereineLettersHowToOnline') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
print '</ul></div>';

// The responsible authority.
$authority = $letters->authority();
$police = VereineAuthorityRules::policeAuthorityFor($mysoc->town);
print load_fiche_titre($langs->trans('VereineLetterAuthority'), '', '', 0, 'vereineauthority');
print '<div class="opacitymedium" data-authority-kind="'.($police !== '' ? 'police' : 'district').'">';
print $police !== '' ? $langs->trans('VereineLetterAuthorityPolice', dol_escape_htmltag($mysoc->town), dol_escape_htmltag($police)) : $langs->trans('VereineLetterAuthorityDistrict');
print '</div>';
if ($police !== '' && $authority['name'] !== '' && $authority['name'] !== $police) {
	print '<div class="warning" data-authority-mismatch="1">'.$langs->trans('VereineLetterAuthorityMismatch', dol_escape_htmltag($authority['name']), dol_escape_htmltag($police)).'</div>';
}
if ($canWrite) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereineauthoritypick" class="paddingtop paddingbottom">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="pickauthority">';
	print '<label for="authority_code">'.$langs->trans('VereineLetterAuthorityPick').'</label> <select id="authority_code" name="authority_code">';
	foreach (VereineAuthorityRules::suggestions() as $code => $suggestion) {
		print '<option value="'.$code.'">'.dol_escape_htmltag($suggestion['name']).'</option>';
	}
	print '</select> <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineLetterAuthorityUse')).'">';
	print '</form>';
}
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereineauthority">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="saveauthority">';
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate fieldrequired"><label for="authority_name">'.$langs->trans('VereineAuthority').'</label></td>';
print '<td><input type="text" id="authority_name" name="authority_name" class="minwidth300" maxlength="128" value="'.dol_escape_htmltag($authority['name']).'"'.($canWrite ? '' : ' disabled').'></td></tr>';
print '<tr><td class="fieldrequired tdtop"><label for="authority_address">'.$langs->trans('VereineLetterAuthorityAddress').'</label></td>';
print '<td><textarea id="authority_address" name="authority_address" rows="3" class="minwidth300"'.($canWrite ? '' : ' disabled').'>'.dol_escape_htmltag($authority['address'], 0, 1).'</textarea></td></tr>';
print '<tr><td><label for="authority_email">'.$langs->trans('Email').'</label></td>';
print '<td><input type="text" id="authority_email" name="authority_email" class="minwidth300" value="'.dol_escape_htmltag($authority['email']).'"'.($canWrite ? '' : ' disabled').'></td></tr>';
print '<tr><td><label for="authority_gz">'.$langs->trans('VereineLetterAuthorityGz').'</label></td>';
print '<td><input type="text" id="authority_gz" name="authority_gz" class="minwidth200" maxlength="64" value="'.dol_escape_htmltag($authority['gz']).'"'.($canWrite ? '' : ' disabled').'>';
print ' <span class="opacitymedium small">'.$langs->trans('VereineLetterAuthorityGzHelp').'</span></td></tr>';
print '</table>';
if ($canWrite) {
	print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div>';
}
print '</form><br>';

// Letters written so far.
print load_fiche_titre($langs->trans('VereineLettersWritten'), '', '', 0, 'vereineletters');
$written = $letters->fetchAll();
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineLetterKind').'</td><td>'.$langs->trans('VereineLetterDate').'</td><td>'.$langs->trans('VereineReportDeadline').'</td>';
print '<td>'.$langs->trans('VereineLetterFiledOn').'</td><td>'.$langs->trans('VereineSignatureTitle').'</td><td></td></tr>';
if (!$written) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('VereineLettersNone').'</span></td></tr>';
}
foreach ($written as $letter) {
	$overdue = $letter['filed_on'] === '' && $letter['deadline'] !== '' && $letter['deadline'] < $today;
	print '<tr class="oddeven" data-letter="'.$letter['id'].'" data-kind="'.$letter['kind'].'" data-deadline="'.$letter['deadline'].'" data-filed="'.$letter['filed_on'].'" data-overdue="'.($overdue ? 1 : 0).'">';
	print '<td>'.$langs->trans('VereineLetterKind_'.$letter['kind']).'</td><td>'.vereineFormatDay($letter['date']).'</td>';
	print '<td>'.vereineFormatDay($letter['deadline']).($overdue ? ' '.dolGetBadge($langs->trans('VereineReportOverdue'), '', 'danger') : '').'</td><td>';
	if ($letter['filed_on'] !== '') {
		print vereineFormatDay($letter['filed_on']);
	} elseif ($canWrite) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinemarkfiled'.$letter['id'].'" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="markfiled">';
		print '<input type="hidden" name="id" value="'.$letter['id'].'">';
		print '<input type="date" name="filed_on" value="'.$today.'"> <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineLetterMarkFiled')).'">';
		print '</form>';
	}
	print '</td><td>';
	vereineSignatureBlock($signatures, VereineSignatureRules::KIND_LETTER, $letter['id'], $letters->path($letter['id']), $canWrite, 'vereineletters');
	print '</td><td class="right"><a href="'.$_SERVER['PHP_SELF'].'?action=download&amp;id='.$letter['id'].'&amp;token='.newToken().'">'.img_picto('', 'pdf').' '.dol_escape_htmltag($letter['filename']).'</a></td></tr>';
}
print '</table></div>';
print '<div class="opacitymedium small paddingtop">'.$langs->trans('VereineLettersRepresentatives');
print ' <a href="'.dol_buildpath('/vereine/functions.php', 1).'#vereinereport">'.$langs->trans('VereineMenuFunctions').'</a></div><br>';

// New letters, one form per kind.
if ($canWrite) {
	$value = function ($kind, $key, $default = '') use ($entered) {
		return isset($entered['kind'], $entered[$key]) && $entered['kind'] === $kind ? $entered[$key] : $default;
	};
	$companyAddress = trim($mysoc->address."\n".trim($mysoc->zip.' '.$mysoc->town));
	foreach (VereineAuthorityRules::KINDS as $kind) {
		print load_fiche_titre($langs->trans('VereineLetterKind_'.$kind), '', '', 0, 'vereineletter'.$kind);
		print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineLetterHelp_'.$kind).'</div>';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#vereineletter'.$kind.'" name="vereineletter'.$kind.'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="writeletter">';
		print '<input type="hidden" name="kind" value="'.$kind.'">';
		print '<table class="border centpercent">';
		if ($kind === VereineAuthorityRules::KIND_EXTRACT) {
			print '<tr><td class="titlefieldcreate"><label for="extract">'.$langs->trans('VereineLetterExtract').'</label></td><td><select id="extract" name="extract">';
			foreach (VereineAuthorityRules::EXTRACTS as $extract) {
				print '<option value="'.$extract.'"'.($value($kind, 'extract') === $extract ? ' selected' : '').'>'.$langs->trans('VereineLetterExtract_'.$extract).'</option>';
			}
			print '</select></td></tr>';
		}
		if ($kind !== VereineAuthorityRules::KIND_FOUNDING) {
			print '<tr><td class="titlefieldcreate'.($kind !== VereineAuthorityRules::KIND_EXTRACT ? ' fieldrequired' : '').'"><label for="date_'.$kind.'">'.$langs->trans('VereineLetterDate_'.$kind).'</label></td>';
			print '<td><input type="date" id="date_'.$kind.'" name="date" value="'.dol_escape_htmltag($value($kind, 'date')).'"></td></tr>';
		}
		if ($kind === VereineAuthorityRules::KIND_ADDRESS) {
			print '<tr><td class="tdtop fieldrequired"><label for="address">'.$langs->trans('VereineLetterNewAddress').'</label></td>';
			print '<td><textarea id="address" name="address" rows="3" class="minwidth300">'.dol_escape_htmltag($value($kind, 'address', $companyAddress), 0, 1).'</textarea></td></tr>';
		} elseif ($kind === VereineAuthorityRules::KIND_DISSOLUTION) {
			print '<tr><td class="fieldrequired"><label for="effective">'.$langs->trans('VereineLetterEffective').'</label></td>';
			print '<td><input type="text" id="effective" name="effective" class="minwidth300" value="'.dol_escape_htmltag($value($kind, 'effective', $langs->transnoentitiesnoconv('VereineLetterEffectiveNow'))).'"></td></tr>';
			print '<tr><td></td><td><label><input type="checkbox" name="assets" value="1"'.($value($kind, 'assets') ? ' checked' : '').'> '.$langs->trans('VereineLetterAssets').'</label></td></tr>';
			foreach (array('liquidator_name' => 'text', 'liquidator_birth' => 'date', 'liquidator_birth_place' => 'text', 'liquidator_address' => 'text', 'liquidator_start' => 'date') as $key => $type) {
				print '<tr><td><label for="'.$key.'">'.$langs->trans('VereineLetter_'.$key).'</label></td>';
				print '<td><input type="'.$type.'" id="'.$key.'" name="'.$key.'" class="'.($type === 'text' ? 'minwidth300' : '').'" value="'.dol_escape_htmltag($value($kind, $key)).'"></td></tr>';
			}
		} elseif ($kind === VereineAuthorityRules::KIND_FOUNDING) {
			print '<tr><td class="titlefieldcreate"></td><td><label><input type="checkbox" name="founders" value="1"'.($value($kind, 'founders') ? ' checked' : '').'> '.$langs->trans('VereineLetterAsFounders').'</label></td></tr>';
		} elseif ($kind === VereineAuthorityRules::KIND_EXTENSION) {
			print '<tr><td class="tdtop fieldrequired"><label for="reason">'.$langs->trans('VereineLetterReason').'</label></td>';
			print '<td><textarea id="reason" name="reason" rows="4" class="centpercent">'.dol_escape_htmltag($value($kind, 'reason'), 0, 1).'</textarea></td></tr>';
		}
		print '</table>';
		print '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineLetterWrite')).'"></div>';
		print '</form><br>';
	}
}

llxFooter();
$db->close();
