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
 * \file    public/verify.php
 * \ingroup vereine
 * \brief   Is this document genuine? The public check of a finished document by its code (#123).
 *
 * The one page of the module without login. It says whether a document with the code exists, of which
 * kind, from when, who signed it and which checksums its files have – no title, no content, no other
 * personal data. A file brought along is compared by its checksum and not kept. The association can
 * switch the page off; then it answers nothing.
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

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
 * @var Societe $mysoc
 */

require_once __DIR__.'/../class/vereinearchive.class.php';

$langs->loadLangs(array('main', 'vereine@vereine'));

// Nothing at all while the module is off or the association switched the check off.
if (!isModEnabled('vereine') || !VereineArchive::publicOn()) {
	http_response_code(404);
	print '<!DOCTYPE html><html><body><p data-verify="off">'.dol_escape_htmltag($langs->transnoentities('VereineVerifyOff')).'</p></body></html>';
	exit;
}

$archive = new VereineArchive($db);
$entered = GETPOST('code', 'alphanohtml');
$code = VereineArchiveRules::normalize($entered);
$found = $code !== '' ? $archive->find($code) : null;
$compared = '';
if ($found !== null && isset($_FILES['document']) && is_array($_FILES['document'])) {
	$upload = $_FILES['document'];
	$temporary = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
	$size = isset($upload['size']) ? (int) $upload['size'] : 0;
	if ($temporary !== '' && $size > 0 && $size <= VereineArchive::CHECK_MAX && is_uploaded_file($temporary)) {
		// Only the checksum is compared; the file is not kept.
		$compared = in_array((string) hash_file('sha256', $temporary), array_column($found['files'], 'sha256'), true) ? 'match' : 'nomatch';
	}
}

top_htmlhead('', $langs->transnoentities('VereineVerifyTitle'), 1);
print '<body class="bodyforlist"><div class="center" style="max-width: 720px; margin: 2em auto; text-align: left;">';
print '<h2>'.dol_escape_htmltag($langs->transnoentities('VereineVerifyTitle')).'</h2>';
print '<p class="opacitymedium">'.dol_escape_htmltag($langs->transnoentities('VereineVerifyIntro', (string) $mysoc->name)).'</p>';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<label for="code">'.dol_escape_htmltag($langs->transnoentities('VereineVerifyCode')).'</label> ';
print '<input type="text" id="code" name="code" maxlength="20" value="'.dol_escape_htmltag($code !== '' ? VereineArchiveRules::format($code) : (string) $entered).'"> ';
print '<br><label for="document">'.dol_escape_htmltag($langs->transnoentities('VereineVerifyFile')).'</label> <input type="file" id="document" name="document" accept=".pdf"> ';
print '<br><input type="submit" class="button" value="'.dol_escape_htmltag($langs->transnoentities('VereineVerifyButton')).'"></form>';

if ((string) $entered !== '' && $found === null) {
	print '<div class="warning" data-verify="unknown">'.dol_escape_htmltag($langs->transnoentities('VereineVerifyUnknown')).'</div>';
} elseif ($found !== null) {
	print '<div class="ok" data-verify="genuine" data-verify-kind="'.dol_escape_htmltag($found['kind']).'">';
	print '<p><strong>'.dol_escape_htmltag($langs->transnoentities('VereineVerifyGenuine', $langs->transnoentitiesnoconv('VereineArchiveKind_'.$found['kind']),
		dol_print_date($found['created'], 'day', 'tzserver', $langs))).'</strong></p>';
	if ($found['signers']) {
		print '<p>'.dol_escape_htmltag($langs->transnoentities('VereineVerifySigned')).'</p><ul>';
		foreach ($found['signers'] as $signer) {
			print '<li>'.dol_escape_htmltag($signer['name'].($signer['label'] !== '' ? ' ('.$signer['label'].')' : '').', '
				.dol_print_date($signer['signed_at'], 'day', 'tzserver', $langs)).'</li>';
		}
		print '</ul>';
	}
	print '<p>'.dol_escape_htmltag($langs->transnoentities('VereineVerifySums')).'</p><ul class="small">';
	foreach ($found['files'] as $file) {
		print '<li><code>'.dol_escape_htmltag($file['sha256']).'</code> '.dol_escape_htmltag($langs->transnoentitiesnoconv('VereineArchiveFile_'.$file['what'])).'</li>';
	}
	print '</ul></div>';
	if ($compared !== '') {
		print '<div class="'.($compared === 'match' ? 'ok' : 'warning').'" data-verify-file="'.$compared.'">'
			.dol_escape_htmltag($langs->transnoentities($compared === 'match' ? 'VereineVerifyFileMatch' : 'VereineVerifyFileNoMatch')).'</div>';
	}
}
print '</div></body></html>';
$db->close();
