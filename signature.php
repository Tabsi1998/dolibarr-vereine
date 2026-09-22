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
 * \file    signature.php
 * \ingroup vereine
 * \brief   Sign with ID Austria: to the signature service and back, the signed PDF, and what its signatures say.
 *
 * The person comes back from the signature service with a plain link (GET), so the way back carries
 * no Dolibarr action: a one-time key in the session ties it to the run, the person and the document.
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

require_once __DIR__.'/class/vereinesignatures.class.php';
require_once __DIR__.'/class/vereineauthorityletters.class.php';
require_once __DIR__.'/class/vereineminutes.class.php';
require_once __DIR__.'/class/vereineresolutiondocs.class.php';
require_once __DIR__.'/class/vereineaudit.class.php';
require_once __DIR__.'/class/vereineaccount.class.php';
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

$canWrite = $user->hasRight('adherent', 'creer');
$action = GETPOST('action', 'aZ09');
$signatures = new VereineSignatures($db);
$qes = new VereineQes($db);
// A signature with ID Austria waits at most this long for the person to come back.
$qesTimeout = 3600;


/**
 * The document a run signs and the page it belongs to.
 *
 * @param DoliDB              $db  Database handler
 * @param array<string,mixed> $run Run of VereineSignatures::fetch()
 * @return array{file:string,back:string}
 */
function vereineSignatureDocument($db, array $run)
{
	if ($run['kind'] === VereineSignatureRules::KIND_LETTER) {
		$letters = new VereineAuthorityLetters($db);
		return array('file' => $letters->path($run['object_id']), 'back' => dol_buildpath('/vereine/authority.php', 1).'#vereineletters');
	}
	if ($run['kind'] === VereineSignatureRules::KIND_MINUTES) {
		$version = (new VereineMinutes($db))->version($run['object_id']);
		return array('file' => $version !== null ? VereineMinutes::path($version) : '',
			'back' => dol_buildpath('/vereine/meetings.php', 1).'?id='.($version !== null ? (int) $version['meeting_id'] : 0).'#vereinemeetingminutes');
	}
	if (in_array($run['kind'], array(VereineSignatureRules::KIND_RESOLUTION, VereineSignatureRules::KIND_MONEY), true)) {
		return array('file' => VereineResolutionDocs::path($run['object_id']),
			'back' => dol_buildpath('/vereine/resolutions.php', 1).'?id='.((int) $run['object_id']).'#vereineresolutionpdf');
	}
	if ($run['kind'] === VereineSignatureRules::KIND_AUDIT_REPORT) {
		return array('file' => VereineAudit::reportPath($run['object_id']),
			'back' => dol_buildpath('/vereine/audit.php', 1).'?year='.((new VereineAudit($db))->yearOf($run['object_id'])).'#vereineauditreport');
	}
	if ($run['kind'] === VereineSignatureRules::KIND_ACCOUNT) {
		return array('file' => VereineAccount::pdfPath($run['object_id']),
			'back' => dol_buildpath('/vereine/account.php', 1).'?year='.((new VereineAccount($db))->yearOf($run['object_id'])).'#vereineaccountpdf');
	}
	return array('file' => '', 'back' => dol_buildpath('/vereine/vereineindex.php', 1));
}

/**
 * Keep a document signed with ID Austria: the one handed over, byte for byte, with one signature more.
 *
 * Somebody else may have signed the same run in the meantime; then the document that came back lacks
 * that signature, and keeping it would lose it.
 *
 * @param DoliDB              $db         Database handler
 * @param VereineSignatures   $signatures Signature runs
 * @param array<string,mixed> $pending    What the session noted when the person left
 * @param string              $signed     The signed document
 * @param User                $user       Who is logged in
 * @param Translate           $langs      Language
 * @return string[] Messages of what was refused, empty when the signature is kept
 */
function vereineQesKeep($db, $signatures, array $pending, $signed, $user, $langs)
{
	$run = $signatures->fetch((int) $pending['run']);
	$source = $run !== null ? VereineSignatures::qesSource($run, vereineSignatureDocument($db, $run)['file']) : '';
	if ($source === '' || !is_file($source) || hash_file('sha256', $source) !== $pending['sha']) {
		return array($langs->trans('VereineQesErrorMeanwhile'));
	}
	$check = VereineQes::appended((string) file_get_contents($source), $signed, VereineSignatures::workdir());
	if (!$check['ok']) {
		return array($langs->trans('VereineQesErrorNotSigned'));
	}
	$result = $signatures->signQes((int) $pending['run'], (int) $pending['member'], $signed, $check['name'], $user, $langs);
	if ($result > 0) {
		return array();
	}
	return $result < 0 ? array($signatures->error) : array_map(array($langs, 'trans'), $signatures->errors);
}

if ($action === 'qes' && $canWrite) {
	// On the way to the signature service.
	$run = $signatures->fetch(GETPOSTINT('signature'));
	$document = $run !== null ? vereineSignatureDocument($db, $run) : array('file' => '', 'back' => dol_buildpath('/vereine/vereineindex.php', 1));
	$refused = '';
	if ($run === null || $run['status'] !== VereineSignatures::STATUS_OPEN) {
		$refused = 'VereineSignatureErrorNotOpen';
	} elseif (!VereineSignatureRules::allowsQes($signatures->rules(), $run['kind'])) {
		$refused = 'VereineSignatureErrorNotWanted';
	} elseif ($signatures->openFor($run, (int) $user->fk_member) === null) {
		$refused = 'VereineSignatureErrorNotYours';
	} elseif ($document['file'] === '' || hash_file('sha256', $document['file']) !== $run['doc_sha']) {
		$refused = 'VereineSignatureErrorChanged';
	}
	if ($refused === '') {
		$source = VereineSignatures::qesSource($run, $document['file']);
		$key = bin2hex(random_bytes(16));
		$pending = array('run' => $run['id'], 'member' => (int) $user->fk_member, 'user' => (int) $user->id, 'sha' => hash_file('sha256', $source),
			'at' => dol_now(), 'back' => $document['back']);
		// One signature at a time: a new start forgets one that never came back.
		$_SESSION['vereine_qes'] = array($key => $pending);
		$here = dol_buildpath('/vereine/signature.php', 2);
		$answer = $qes->sign((string) file_get_contents($source), 'vereine-'.$run['id'].'-'.$key, $here.'?qes='.$key, $here.'?qes='.$key.'&failed=1');
		if ($answer['redirect'] !== '') {
			header('Location: '.$answer['redirect']);
			exit;
		}
		if ($answer['signed'] !== '') {
			unset($_SESSION['vereine_qes']);
			$messages = vereineQesKeep($db, $signatures, $pending, $answer['signed'], $user, $langs);
			setEventMessages($messages ? null : $langs->trans('VereineQesSigned'), $messages ? $messages : null, $messages ? 'errors' : 'mesgs');
		} else {
			unset($_SESSION['vereine_qes']);
			setEventMessages(null, array_merge(array_map(array($langs, 'trans'), $qes->errors), $qes->error !== '' ? array($qes->error) : array()), 'errors');
		}
	} else {
		setEventMessages(null, array($langs->trans($refused)), 'errors');
	}
	header('Location: '.$document['back']);
	exit;
}

if (GETPOSTISSET('qes')) {
	// Back from the signature service: the key is used once, whatever happens next.
	$key = GETPOST('qes', 'aZ09');
	$waiting = isset($_SESSION['vereine_qes']) && is_array($_SESSION['vereine_qes']) ? $_SESSION['vereine_qes'] : array();
	$pending = $key !== '' && isset($waiting[$key]) ? $waiting[$key] : null;
	unset($_SESSION['vereine_qes']);
	if ($pending === null || (int) $pending['user'] !== (int) $user->id || dol_now() - (int) $pending['at'] > $qesTimeout) {
		setEventMessages(null, array($langs->trans('VereineQesErrorExpired')), 'errors');
		header('Location: '.dol_buildpath('/vereine/vereineindex.php', 1));
		exit;
	}
	if (GETPOSTISSET('failed')) {
		$reason = trim(GETPOST('error', 'alphanohtml').' '.GETPOST('cause', 'alphanohtml'));
		setEventMessages(null, array($langs->trans('VereineQesCancelled', dol_escape_htmltag(dol_trunc($reason, 200)))), 'errors');
	} else {
		$signed = $qes->fetchSigned(GETPOST('pdfurl', 'url'), $pending['sha']);
		if ($signed === '') {
			$messages = array_merge(array_map(array($langs, 'trans'), $qes->errors), $qes->error !== '' ? array($qes->error) : array());
		} else {
			$messages = vereineQesKeep($db, $signatures, $pending, $signed, $user, $langs);
		}
		setEventMessages($messages ? null : $langs->trans('VereineQesSigned'), $messages ? $messages : null, $messages ? 'errors' : 'mesgs');
	}
	header('Location: '.$pending['back']);
	exit;
}

$run = $signatures->fetch(GETPOSTINT('signature'));
$file = $run !== null ? VereineSignatures::qesPath($run['id']) : '';
if ($run === null || !is_file($file)) {
	accessforbidden();
}

if ($action === 'download') {
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
}

// What the module reads out of the signed document itself, and what the service says about the certificates.
$document = vereineSignatureDocument($db, $run);
$pdf = (string) file_get_contents($file);
$found = VereineQes::signatures($pdf, VereineSignatures::workdir());
$certificates = array();
foreach ((array) $qes->verify($pdf) as $result) {
	$certificates[$result['index']] = $result;
}
llxHeader('', $langs->trans('VereineQesVerifyTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-signature');
print load_fiche_titre($langs->trans('VereineQesVerifyTitle'), '<a href="'.$document['back'].'">'.$langs->trans('Back').'</a>', 'fa-file-signature');
print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineQesVerifyIntro', dol_escape_htmltag($run['doc_name'])).'</div>';
if (!$found) {
	print '<div class="warning" data-qes-results="0">'.$langs->trans('VereineQesVerifyNone').'</div>';
} else {
	$badges = array('yes' => 'success', 'no' => 'danger', 'unknown' => 'secondary', VereineQes::STATE_VALID => 'success',
		VereineQes::STATE_UNCLEAR => 'warning', VereineQes::STATE_INVALID => 'danger');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-qes-results="'.count($found).'">';
	print '<tr class="liste_titre"><td>#</td><td>'.$langs->trans('VereineQesSignedBy').'</td><td>'.$langs->trans('VereineQesIntact').'</td>';
	print $certificates ? '<td>'.$langs->trans('VereineQesCertificate').'</td>' : '';
	print '</tr>';
	foreach ($found as $signature) {
		$intact = $signature['intact'] === null ? 'unknown' : ($signature['intact'] ? 'yes' : 'no');
		$certificate = isset($certificates[$signature['index']]) ? $certificates[$signature['index']] : null;
		print '<tr class="oddeven" data-qes-intact="'.$intact.'" data-qes-name="'.dol_escape_htmltag($signature['name']).'"';
		print ' data-qes-certificate="'.($certificate !== null ? $certificate['state'] : '').'">';
		print '<td>'.($signature['index'] + 1).'</td><td>'.dol_escape_htmltag($signature['name'] !== '' ? $signature['name'] : $langs->trans('Unknown')).'</td>';
		print '<td>'.dolGetBadge($langs->trans('VereineQesIntact_'.$intact), '', $badges[$intact]).'</td>';
		if ($certificates) {
			print '<td>'.($certificate !== null ? dolGetBadge($langs->trans('VereineQesState_'.$certificate['state']), '', $badges[$certificate['state']]) : '');
			if ($certificate !== null && $certificate['message'] !== '') {
				print ' <div class="opacitymedium small">'.dol_escape_htmltag($certificate['message']).'</div>';
			}
			print '</td>';
		}
		print '</tr>';
	}
	print '</table></div>';
	$last = end($found);
	if (!$last['covers_end']) {
		print '<div class="warning" data-qes-after-last="1">'.$langs->trans('VereineQesAfterLast').'</div>';
	}
}
if (!$certificates) {
	print '<div class="info" data-qes-no-certificates="1">'.$langs->trans('VereineQesCertificateNone').'</div>';
}
print '<div class="paddingtop"><a href="'.$_SERVER['PHP_SELF'].'?action=download&amp;signature='.$run['id'].'&amp;token='.newToken().'">'.img_picto('', 'pdf').' '.$langs->trans('VereineQesDocument').'</a>';
print ' &middot; <a href="https://www.signaturpruefung.gv.at" target="_blank" rel="noopener">signaturpruefung.gv.at</a></div>';

llxFooter();
$db->close();
