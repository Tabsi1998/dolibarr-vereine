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
 * \file    donations.php
 * \ingroup vereine
 * \brief   The donation report to the tax office (#6): donors, identifiers, the file for FinanzOnline, its protocol.
 *
 * Dates of birth and identifiers are personal data of the donors: the page needs its own right.
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

require_once __DIR__.'/class/vereinedonations.class.php';
require_once __DIR__.'/class/vereineduties.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('donations', 'members', 'other', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read') || !$user->hasRight('vereine', 'donation', 'write')) {
	accessforbidden();
}

$store = new VereineDonations($db);
$action = GETPOST('action', 'aZ09');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$thisYear = (int) substr($today, 0, 4);
$year = GETPOSTINT('year');
if ($year < VereineDonationRules::FIRST_YEAR || $year > $thisYear) {
	// Reported is the year before, until the end of February and a little after.
	$year = $thisYear - 1;
}
// Not "donor": the firewall of Dolibarr 24 reads "donor=" as an HTML event handler and refuses the page.
$donorId = GETPOSTINT('person');
$self = $_SERVER['PHP_SELF'].'?year='.$year;
$messages = function ($result, $object) use ($langs) {
	setEventMessages($result < 0 ? $object->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $object->errors), 'errors');
};


/*
 * Actions
 */

if ($action === 'savedonor') {
	$entered = array('firstname' => GETPOST('firstname', 'alphanohtml'), 'lastname' => GETPOST('lastname', 'alphanohtml'),
		'birth' => GETPOST('birth', 'alphanohtml'), 'refnr' => GETPOST('refnr', 'alphanohtml'), 'vbpk' => GETPOST('vbpk', 'alphanohtml'),
		'given_on' => GETPOST('given_on', 'alphanohtml'));
	$result = $store->saveDonor($donorId, $entered, $today, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineDonationDonorSaved'), null, 'mesgs');
		header('Location: '.$self.'#vereinedonationdonors');
		exit;
	}
	$messages($result, $store);
} elseif ($action === 'move') {
	$result = $store->move(GETPOSTINT('don'), GETPOSTINT('to'), $user);
	if ($result > 0) {
		header('Location: '.$self.'&person='.GETPOSTINT('to').'#vereinedonationdonor');
		exit;
	}
	$messages($result, $store);
} elseif ($action === 'szrexport') {
	$export = $store->szrExport($year);
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="BPK_spendenmeldung_'.$year.'.csv"');
	print $export['content'];
	exit;
} elseif ($action === 'szrimport') {
	$upload = isset($_FILES['szr_file']) && is_array($_FILES['szr_file']) ? $_FILES['szr_file'] : array();
	$result = $store->szrImport($upload, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineDonationSzrRead', $result), null, 'mesgs');
		header('Location: '.$self.'#vereinedonationdonors');
		exit;
	}
	$messages($result, $store);
} elseif ($action === 'report') {
	$result = $store->createReport($year, $today, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineDonationReportCreated'), null, 'mesgs');
		header('Location: '.$self.'#vereinedonationreports');
		exit;
	}
	$messages($result, $store);
	if ($store->problems) {
		setEventMessages(null, $store->problems, 'errors');
	}
} elseif ($action === 'discard') {
	$result = $store->discard(GETPOSTINT('report'), $user);
	if ($result > 0) {
		header('Location: '.$self.'#vereinedonationreports');
		exit;
	}
	$messages($result, $store);
} elseif ($action === 'protocol') {
	$upload = isset($_FILES['protocol_file']) && is_array($_FILES['protocol_file']) ? $_FILES['protocol_file'] : array();
	$result = $store->readProtocol($upload, $user);
	if ($result > 0) {
		$report = $store->fetchReport($result);
		setEventMessages($langs->trans($report !== null && $report['protocol_test'] ? 'VereineDonationProtocolTest' : 'VereineDonationProtocolRead'), null, 'mesgs');
		header('Location: '.$self.'#vereinedonationreports');
		exit;
	}
	$messages($result, $store);
} elseif ($action === 'xml') {
	$report = $store->fetchReport(GETPOSTINT('report'));
	$file = $report !== null ? VereineDonations::reportPath($report) : '';
	if ($file === '' || !is_file($file)) {
		accessforbidden();
	}
	header('Content-Type: application/xml; charset=utf-8');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
} elseif ($action === 'receipt') {
	$file = $store->receipt($donorId, $year, $langs);
	if ($file === '' || !is_file($file)) {
		accessforbidden();
	}
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
}


/*
 * View
 */

$donModule = isModEnabled('don');
if ($donModule) {
	$store->link($year, $user);
}
$donors = $donModule ? $store->donors($year) : array();
$settings = VereineDonations::settings();
$money = function ($amount) use ($langs) {
	return price($amount, 0, $langs, 1, -1, 2).' €';
};

llxHeader('', $langs->trans('VereineDonationTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-donations');
print load_fiche_titre($langs->trans('VereineDonationTitle'), '', 'fa-hand-holding-heart');
print '<div class="paddingbottom" data-donation-years="1">'.$langs->trans('VereineDonationYear').': ';
$oldest = max(VereineDonationRules::FIRST_YEAR, $thisYear - 6);
for ($option = $thisYear; $option >= $oldest; $option--) {
	print $option === $year ? '<strong class="paddingright">'.$option.'</strong> '
		: '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?year='.$option.'">'.$option.'</a> ';
}
print '</div>';
print '<div class="info" data-donation-howto="1">'.$langs->trans('VereineDonationHowTo').'</div>';

if (!$donModule) {
	print '<div class="warning" data-donation-module="off">'.$langs->trans('VereineDonationModuleOff').'</div>';
}
if (!in_array($settings['kind'], VereineDonationRules::KINDS, true)) {
	print '<div class="warning" data-donation-setup="missing">'.$langs->trans('VereineDonationSetupMissing',
		'<a href="'.dol_buildpath('/vereine/admin/donations.php', 1).'">'.$langs->trans('VereineSetupTabDonations').'</a>').'</div>';
}
// The deadline lives in the calendar of duties (#24); say so when it is switched off there.
$reportDuty = null;
foreach ((new VereineDuties($db))->fetchAll() as $duty) {
	if ($duty['code'] === 'donations') {
		$reportDuty = $duty;
	}
}
if ($reportDuty === null || !$reportDuty['active']) {
	print '<div class="warning" data-donation-duty="off">'.$langs->trans('VereineDonationDutyOff',
		'<a href="'.dol_buildpath('/vereine/duties.php', 1).'#vereinedutycatalogue">'.$langs->trans('VereineMenuDuties').'</a>').'</div>';
}

// One donor: what the report needs, and the donations behind it.
$chosen = $donorId > 0 ? $store->fetchDonor($donorId) : null;
if ($chosen !== null) {
	print load_fiche_titre(dol_escape_htmltag($langs->transnoentities('VereineDonationDonor', trim($chosen['firstname'].' '.$chosen['lastname']))), '', '', 0, 'vereinedonationdonor');
	print '<form method="POST" name="vereinedonationdonor" action="'.$self.'&amp;person='.$chosen['id'].'#vereinedonationdonor">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="savedonor">';
	print '<table class="border centpercent" data-donation-donor="'.$chosen['id'].'">';
	print '<tr><td class="titlefield">'.$langs->trans('Firstname').'</td><td><input type="text" name="firstname" size="30" maxlength="128" value="'.dol_escape_htmltag($chosen['firstname']).'"></td></tr>';
	print '<tr><td>'.$langs->trans('Lastname').'</td><td><input type="text" name="lastname" size="30" maxlength="128" value="'.dol_escape_htmltag($chosen['lastname']).'"></td></tr>';
	print '<tr><td>'.$langs->trans('VereineDonationBirth').'</td><td><input type="date" name="birth" value="'.dol_escape_htmltag($chosen['birth']).'">';
	print ' <span class="opacitymedium small">'.$langs->trans('VereineDonationBirthHint').'</span></td></tr>';
	print '<tr><td>'.$langs->trans('VereineDonationGivenOn').'</td><td><input type="date" name="given_on" value="'.dol_escape_htmltag($chosen['given_on']).'">';
	print ' <span class="opacitymedium small">'.$langs->trans('VereineDonationGivenOnHint').'</span></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('VereineDonationRefNr').'</td><td><input type="text" name="refnr" size="23" maxlength="23" value="'.dol_escape_htmltag($chosen['refnr']).'">';
	print ' <span class="opacitymedium small">'.$langs->trans('VereineDonationRefNrHint').'</span></td></tr>';
	print '<tr><td>vbPK SA</td><td><textarea name="vbpk" rows="3" cols="60" class="flat">'.dol_escape_htmltag($chosen['vbpk']).'</textarea>';
	print '<br><span class="opacitymedium small">'.$langs->trans('VereineDonationVbpkHint').'</span></td></tr>';
	print '</table><div class="center paddingtop"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div></form>';

	$choices = $store->donorChoices();
	print '<div class="div-table-responsive-no-min paddingtop"><table class="noborder centpercent" data-donation-gifts="'.$chosen['id'].'">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Ref').'</td><td class="right">'.$langs->trans('Amount').'</td><td></td></tr>';
	foreach ($store->gifts($chosen['id']) as $gift) {
		print '<tr class="oddeven" data-donation-gift="'.$gift['id'].'"><td class="nowraponall">'.vereineFormatDay($gift['day']).'</td>';
		print '<td><a href="'.DOL_URL_ROOT.'/don/card.php?id='.$gift['id'].'">'.dol_escape_htmltag($gift['ref'] !== '' ? $gift['ref'] : '#'.$gift['id']).'</a></td>';
		print '<td class="right nowraponall">'.$money($gift['amount']).'</td><td class="right">';
		// When the automatic link took the wrong person: another donor for this donation.
		print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="move"><input type="hidden" name="don" value="'.$gift['id'].'"><select name="to" class="flat">';
		foreach ($choices as $choiceId => $label) {
			print '<option value="'.$choiceId.'"'.($choiceId === $chosen['id'] ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
		}
		print '</select> <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineDonationMove')).'"></form></td></tr>';
	}
	print '</table></div>';
}

// The donors of the year and where their report stands.
$reportable = 0;
$missing = 0;
foreach ($donors as $donor) {
	if ($donor['next'] !== '') {
		$reportable++;
	} elseif ($donor['person'] && $donor['vbpk'] === '' && $donor['total'] >= 0.01) {
		$missing++;
	}
}
print load_fiche_titre($langs->trans('VereineDonationDonors', $year), '', '', 0, 'vereinedonationdonors');
print '<div class="paddingbottom" data-donation-reportable="'.$reportable.'" data-donation-missing="'.$missing.'">'.$langs->trans('VereineDonationSummary', $reportable, $missing).'</div>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-donation-donors="'.count($donors).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('Name').'</td><td>'.$langs->trans('VereineDonationRefNr').'</td><td class="right">'.$langs->trans('VereineDonationYearSum').'</td>';
print '<td>'.$langs->trans('VereineDonationData').'</td><td>vbPK SA</td><td class="right">'.$langs->trans('VereineDonationHeld').'</td><td>'.$langs->trans('VereineDonationNext').'</td><td></td></tr>';
foreach ($donors as $donor) {
	print '<tr class="oddeven" data-donation-person="'.$donor['id'].'" data-donation-ref="'.dol_escape_htmltag($donor['refnr']).'" data-donation-total="'
		.number_format($donor['total'], 2, '.', '').'" data-donation-next="'.$donor['next'].'" data-donation-vbpk="'.($donor['vbpk'] !== '' ? 1 : 0).'">';
	print '<td><a href="'.$self.'&amp;person='.$donor['id'].'#vereinedonationdonor">'.dol_escape_htmltag(trim($donor['firstname'].' '.$donor['lastname'])).'</a>';
	if ($donor['member_id'] > 0) {
		print ' <a class="opacitymedium small" href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.$donor['member_id'].'">'.$langs->trans('Member').'</a>';
	}
	print '</td><td>'.dol_escape_htmltag($donor['refnr']).'</td><td class="right nowraponall">'.$money($donor['total']).' <span class="opacitymedium small">('.$donor['gifts'].')</span></td><td>';
	if (!$donor['person']) {
		print '<span class="opacitymedium small">'.$langs->trans('VereineDonationCompany').'</span>';
	} else {
		print $donor['has_birth'] ? '<span class="badge badge-status4">'.$langs->trans('VereineDonationBirthKnown').'</span>'
			: '<span class="badge badge-status1">'.$langs->trans('VereineDonationBirthMissing').'</span>';
	}
	print '</td><td>';
	if ($donor['vbpk'] !== '') {
		print '<span class="badge badge-status4">'.$langs->trans('VereineDonationVbpkState_found').'</span>';
	} elseif ($donor['vbpk_state'] !== '') {
		print '<span class="badge badge-status8">'.$langs->trans('VereineDonationVbpkState_'.$donor['vbpk_state']).'</span>';
	}
	print '</td><td class="right nowraponall">'.($donor['held'] !== null ? $money($donor['held']) : '').'</td><td>';
	if ($donor['pending']) {
		print '<span class="opacitymedium small">'.$langs->trans('VereineDonationPending').'</span>';
	} elseif ($donor['next'] !== '') {
		print '<span class="badge badge-status1">'.$langs->trans('VereineDonationType_'.$donor['next']).'</span>';
	}
	print '</td><td class="right"><a href="'.$self.'&amp;action=receipt&amp;person='.$donor['id'].'&amp;token='.newToken().'" data-donation-receipt="'.$donor['id'].'">'
		.img_picto('', 'pdf').' '.$langs->trans('VereineDonationReceipt').'</a></td></tr>';
}
if (!$donors) {
	print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium" data-donation-none="1">'.$langs->trans('VereineDonationNone').'</span></td></tr>';
}
print '</table></div>';

// The register of source numbers: a file out, the answer back in.
print load_fiche_titre($langs->trans('VereineDonationSzr'), '', '', 0, 'vereinedonationszr');
print '<div class="info">'.$langs->trans('VereineDonationSzrHowTo').'</div>';
print '<div class="paddingbottom"><a class="button" href="'.$self.'&amp;action=szrexport&amp;token='.newToken().'" data-donation-szr-export="1">'
	.$langs->trans('VereineDonationSzrExport').'</a></div>';
print '<form method="POST" name="vereinedonationszr" action="'.$self.'#vereinedonationszr" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="szrimport">';
print '<input type="file" name="szr_file" accept=".zip,.csv"> <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineDonationSzrImport')).'"></form>';

// The report itself: written here, uploaded in FinanzOnline, its protocol read back.
$reports = $store->reports($year);
print load_fiche_titre($langs->trans('VereineDonationReports', $year), '', 'fa-file-code', 0, 'vereinedonationreports');
print '<div class="info">'.$langs->trans('VereineDonationReportHowTo').'</div>';
print '<form method="POST" name="vereinedonationreport" action="'.$self.'#vereinedonationreports" class="paddingbottom">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="report">';
print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineDonationReportCreate', $reportable)).'"'.($reportable > 0 ? '' : ' disabled').'></form>';
print '<form method="POST" name="vereinedonationprotocol" action="'.$self.'#vereinedonationreports" enctype="multipart/form-data" class="paddingbottom">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="protocol">';
print $langs->trans('VereineDonationProtocolUpload').' <input type="file" name="protocol_file" accept=".xml">';
print ' <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineDonationProtocolButton')).'"></form>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-donation-reports="'.count($reports).'">';
foreach ($reports as $report) {
	print '<tr class="oddeven tdtop" data-donation-report="'.$report['id'].'" data-donation-report-status="'.$report['status'].'" data-donation-protocol="'
		.dol_escape_htmltag($report['protocol']).'"><td>';
	print '<strong>'.dol_escape_htmltag($report['message_ref']).'</strong> · '.dol_print_date($report['created'], 'dayhour').' · '
		.$langs->trans('VereineDonationReportLines', $report['lines']);
	print ' · <a href="'.$self.'&amp;action=xml&amp;report='.$report['id'].'&amp;token='.newToken().'">'.img_picto('', 'fa-file-code').' XML</a><br>';
	if ($report['protocol'] !== '') {
		print '<span class="badge badge-status'.($report['protocol'] === 'OK' ? '4' : '8').'">'.$langs->trans('VereineDonationProtocol_'.strtolower($report['protocol']))
			.($report['protocol_test'] ? ' ('.$langs->trans('VereineDonationProtocolTestMark').')' : '').'</span>';
	} else {
		print '<span class="opacitymedium small">'.$langs->trans('VereineDonationProtocolWaits').'</span>';
	}
	print '<ul class="small">';
	foreach ($store->lines($report['id']) as $line) {
		print '<li data-donation-line="'.$line['id'].'" data-donation-line-state="'.$line['state'].'">'.$langs->trans('VereineDonationType_'.$line['kind']).' · '
			.dol_escape_htmltag($line['name']).' ('.dol_escape_htmltag($line['refnr']).')'.($line['kind'] !== VereineDonationRules::TYPE_CANCEL ? ' · '.$money($line['amount']) : '');
		if ($line['error'] !== '') {
			print ' · <span class="error">'.dol_escape_htmltag($line['error']).'</span>';
		}
		print '</li>';
	}
	print '</ul></td><td class="right">';
	if ($report['status'] === VereineDonations::REPORT_CREATED) {
		print '<form method="POST" action="'.$self.'#vereinedonationreports" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="discard"><input type="hidden" name="report" value="'.$report['id'].'">';
		print '<input type="submit" class="button small butActionDelete" value="'.dol_escape_htmltag($langs->trans('VereineDonationDiscard')).'"></form>';
	}
	print '</td></tr>';
}
if (!$reports) {
	print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">'.$langs->trans('VereineDonationReportNone').'</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
