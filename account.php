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
 * \file    account.php
 * \ingroup vereine
 * \brief   The income and expenditure account with the statement of assets of an association's year (#8).
 *
 * The figures come from the bookings of the bank and cash accounts; the module keeps only the day the
 * account was made, further assets and debts, and a note.
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

require_once __DIR__.'/class/vereineaccount.class.php';
require_once __DIR__.'/class/vereinesignatures.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('bills', 'banks', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read') || (empty($user->admin) && !$user->hasRight('banque', 'lire'))) {
	accessforbidden();
}

$account = new VereineAccount($db);
$signatures = new VereineSignatures($db);
$canWrite = !empty($user->admin) || $user->hasRight('banque', 'modifier');
$action = GETPOST('action', 'aZ09');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$lastEnded = VereineAuditRules::lastEnded($today, getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1));
$year = GETPOSTINT('year');
if ($year < $lastEnded - 5 || $year > $lastEnded + 1) {
	$year = $lastEnded;
}
$self = $_SERVER['PHP_SELF'].'?year='.$year;
$record = $account->fetch($year, $user);
if ($record === null) {
	accessforbidden();
}
// Whoever the run of the account names may sign, also without the right to change the bank.
$run = $signatures->current(VereineSignatureRules::KIND_ACCOUNT, $record['id']);
$isSigner = $run !== null && $signatures->openFor($run, (int) $user->fk_member) !== null;


/*
 * Actions
 */

if (in_array($action, array('csv', 'pdf', 'sheet', 'signed'), true)) {
	if ($action === 'csv') {
		$csv = VereineAccount::csv($account->build($year, $user), $langs);
		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="einnahmen-ausgaben-'.$year.'.csv"');
		print $csv;
		exit;
	}
	$byRun = $action === 'pdf' ? null : $signatures->fetch(GETPOSTINT('signature'));
	if ($action === 'pdf') {
		$file = VereineAccount::pdfPath($record['id']);
	} elseif ($action === 'sheet') {
		$file = $byRun !== null && $byRun['kind'] === VereineSignatureRules::KIND_ACCOUNT ? VereineSignatures::sheetPath($byRun['id']) : '';
	} else {
		$file = $byRun !== null && $byRun['kind'] === VereineSignatureRules::KIND_ACCOUNT ? VereineSignatures::scanPath($byRun) : '';
	}
	if ($file === '' || !is_file($file)) {
		accessforbidden();
	}
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
} elseif ($action === 'save' && $canWrite) {
	$result = $account->save($year, array('made_on' => GETPOST('made_on', 'alphanohtml'), 'extras' => GETPOST('extras', 'array'), 'note' => GETPOST('note', 'restricthtml')), $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineAccountSaved'), null, 'mesgs');
		header('Location: '.$self.'#vereineaccountassets');
		exit;
	}
	setEventMessages($result < 0 ? $account->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $account->errors), 'errors');
} elseif ($action === 'assign' && $canWrite) {
	if ($account->assign($year, GETPOST('area', 'array'), $user) >= 0) {
		setEventMessages($langs->trans('VereineAccountAssigned'), null, 'mesgs');
		header('Location: '.$self.'#vereineaccountassign');
		exit;
	}
	setEventMessages($account->error, null, 'errors');
} elseif ($action === 'build' && $canWrite) {
	if ($account->buildPdf($year, $user, $langs) !== '') {
		setEventMessages($langs->trans('VereineAccountPdfBuilt'), null, 'mesgs');
		header('Location: '.$self.'#vereineaccountpdf');
		exit;
	}
	setEventMessages($account->error, null, 'errors');
} elseif ($action === 'startsign' && $canWrite) {
	$objectId = GETPOSTINT('object');
	$year = $account->yearOf($objectId) ?: $year;
	$result = $signatures->start(VereineSignatureRules::KIND_ACCOUNT, $objectId, VereineAccount::pdfPath($objectId), $today, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineSignatureStarted'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?year='.$year.'#vereineaccountpdf');
		exit;
	}
	setEventMessages($result < 0 ? $signatures->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $signatures->errors), 'errors');
} elseif ($action === 'sign' || ($action === 'signscan' && $canWrite)) {
	$byRun = $signatures->fetch(GETPOSTINT('signature'));
	// Whoever the run names may sign it, also without the right to change the bank.
	$ok = $byRun !== null && $byRun['kind'] === VereineSignatureRules::KIND_ACCOUNT && ($canWrite || $signatures->openFor($byRun, (int) $user->fk_member) !== null);
	if ($ok) {
		$year = $account->yearOf($byRun['object_id']) ?: $year;
	}
	if ($action === 'sign') {
		$result = $ok ? $signatures->sign($byRun['id'], GETPOST('password', 'password'), VereineAccount::pdfPath($byRun['object_id']), $user, $langs) : 0;
	} else {
		$upload = isset($_FILES['scan_file']) && is_array($_FILES['scan_file']) ? $_FILES['scan_file'] : array();
		$result = $ok ? $signatures->uploadScan($byRun['id'], $upload, $user, $langs) : 0;
	}
	if ($result > 0) {
		setEventMessages($langs->trans($action === 'sign' ? 'VereineSignatureSigned' : 'VereineSignatureScanStored'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?year='.$year.'#vereineaccountpdf');
		exit;
	}
	setEventMessages($result < 0 ? $signatures->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $ok ? $signatures->errors : array('VereineSignatureErrorNotOpen')), 'errors');
}


/*
 * View
 */

$data = $account->build($year, $user);
$period = $data['period'];
$money = function ($amount) use ($langs) {
	return price($amount, 0, $langs, 1, -1, 2).' €';
};

llxHeader('', $langs->trans('VereineAccountTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-account');
print load_fiche_titre($langs->trans('VereineAccountTitle'), '', 'fa-balance-scale-left');
print '<div class="paddingbottom" data-account-years="1">'.$langs->trans('VereineAuditYear').': ';
for ($option = $lastEnded - 5; $option <= $lastEnded + 1; $option++) {
	$label = VereineAccount::period($option)['label'];
	print $option === $year ? '<strong class="paddingright">'.$label.'</strong> ' : '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?year='.$option.'">'.$label.'</a> ';
}
print '</div>';
print '<div class="info" data-account-howto="1"><ul>';
print '<li>'.$langs->trans('VereineAccountHowTo', $period['label'], vereineFormatDay($period['start']), vereineFormatDay($period['end'])).'</li>';
print '<li>'.$langs->trans('VereineAccountHowToAreas').'</li>';
print '<li>'.$langs->trans('VereineAccountHowToLaw').'</li>';
print '</ul></div>';

// Deadline, size and whether the account agrees with the bank.
$made = $data['record']['made_on'];
if ($made !== '') {
	print '<div class="ok" data-account-made="'.$made.'">'.$langs->trans('VereineAccountMadeOn', vereineFormatDay($made), vereineFormatDay(VereineAuditRules::deadline($made))).'</div>';
} else {
	print '<div class="'.($today > $data['deadline'] ? 'error' : 'opacitymedium').'" data-account-deadline="'.$data['deadline'].'">';
	print $langs->trans($today > $data['deadline'] ? 'VereineAccountOverdue' : 'VereineAccountDue', vereineFormatDay($data['deadline'])).'</div>';
}
foreach ($data['warnings'] as $warning) {
	print '<div class="warning" data-account-size="'.$warning.'">'.$langs->trans($warning).'</div>';
}
$totals = $data['totals']['totals'];
print '<div class="'.($data['reconciled'] ? 'ok' : 'error').'" data-reconciled="'.($data['reconciled'] ? 1 : 0).'">';
print $langs->trans($data['reconciled'] ? 'VereineAccountReconciledShort' : 'VereineAccountNotReconciledShort', $money($data['opening']['total']), $money($totals['income']),
	$money($totals['expense']), $money($data['closing']['total'])).'</div>';
if ($data['unassigned'] > 0) {
	$reasons = $data['reasons'];
	print '<div class="warning" data-account-unassigned="'.$data['unassigned'].'" data-account-unassigned-bookings="'.$reasons['bookings'].'">';
	print $langs->trans('VereineAccountUnassigned', $data['unassigned']).'<ul>';
	if ($reasons['lines'] > 0) {
		print '<li>'.$langs->trans('VereineAccountUnassignedLines', $reasons['lines']).' <a href="'.dol_buildpath('/vereine/taxcheck.php', 1).'?year='.$year.'">'.$langs->trans('VereineTaxCheckOpen').'</a></li>';
	}
	if ($reasons['supplier'] > 0) {
		print '<li>'.$langs->trans('VereineAccountUnassignedSupplier', $reasons['supplier']).'</li>';
	}
	if ($reasons['bookings'] > 0) {
		print '<li>'.$langs->trans('VereineAccountUnassignedBookings', $reasons['bookings']).($canWrite ? ' <a href="#vereineaccountassign">'.$langs->trans('VereineAccountAssignOpen').'</a>' : '').'</li>';
	}
	print '</ul></div>';
}

// Income and expenses by area.
foreach (array('income', 'expense') as $side) {
	print '<br>'.load_fiche_titre($langs->trans('VereineAccountSide_'.$side), '', '');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-account-side="'.$side.'">';
	print '<tr class="liste_titre"><td>'.$langs->trans('VereineTaxSphere').'</td><td class="right">'.$langs->trans('Amount').'</td></tr>';
	foreach ($data['totals'][$side] as $sphere => $kinds) {
		print '<tr class="oddeven" data-account-sum="'.$side.':'.$sphere.'" data-amount="'.price2num(array_sum($kinds), 'MT').'"><td><strong>'.$langs->trans('VereineSphereShort_'.$sphere).'</strong>';
		foreach ($kinds as $kind => $amount) {
			print '<div class="small opacitymedium paddingleft" data-account-kind="'.$side.':'.$sphere.':'.$kind.'">'.$langs->trans('VereineAccountKind_'.$kind).': '.$money($amount).'</div>';
		}
		print '</td><td class="right nowraponall tdtop">'.$money(array_sum($kinds)).'</td></tr>';
	}
	print '<tr class="liste_total"><td>'.$langs->trans('VereineAccountTotal_'.$side).'</td><td class="right nowraponall">'.$money($totals[$side]).'</td></tr>';
	print '</table></div>';
}
print '<div class="paddingtop"><strong>'.$langs->trans('VereineAccountResult').': '.$money($totals['result']).'</strong></div>';

// Every booking, to look up where a figure comes from.
print '<details class="paddingtop" data-account-bookings="'.count($data['bookings']).'"><summary>'.$langs->trans('VereineAccountBookings', count($data['bookings'])).'</summary>';
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('VereineAccountBank').'</td><td>'.$langs->trans('Description').'</td>';
print '<td>'.$langs->trans('VereineAccountKind').'</td><td>'.$langs->trans('VereineTaxSphere').'</td><td class="right">'.$langs->trans('Amount').'</td></tr>';
foreach ($data['bookings'] as $booking) {
	$parts = array();
	$partsData = array();
	foreach ($booking['parts'] as $sphere => $part) {
		$parts[] = $langs->trans('VereineSphereShort_'.$sphere).(count($booking['parts']) > 1 ? ' '.$money($part) : '');
		$partsData[] = $sphere.'='.number_format($part, 2, '.', '');
	}
	print '<tr class="oddeven" data-booking="'.$booking['id'].'" data-kind="'.$booking['kind'].'" data-parts="'.implode(';', $partsData).'">';
	print '<td class="nowraponall">'.vereineFormatDay($booking['date']).'</td>';
	print '<td>'.dol_escape_htmltag($booking['account']).'</td><td><a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.$booking['id'].'">'.dol_escape_htmltag($booking['label']).'</a></td>';
	print '<td>'.$langs->trans('VereineAccountKind_'.$booking['kind']).'</td><td>'.implode(', ', $parts).'</td><td class="right nowraponall">'.$money($booking['amount']).'</td></tr>';
}
print '</table></div></details>';

// Bookings without invoice lines behind them: the board chooses their area.
$assignable = array_filter($data['bookings'], function ($booking) {
	return VereineAccountRules::assignable($booking['kind']);
});
if ($canWrite && $assignable) {
	print '<br>'.load_fiche_titre($langs->trans('VereineAccountAssignTitle'), '', '', 0, 'vereineaccountassign');
	print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineAccountAssignHelp').'</div>';
	print '<form method="POST" action="'.$self.'#vereineaccountassign" name="vereineaccountassign">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="assign">';
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('VereineAccountBank').'</td><td>'.$langs->trans('Description').'</td>';
	print '<td>'.$langs->trans('VereineAccountKind').'</td><td class="right">'.$langs->trans('Amount').'</td><td>'.$langs->trans('VereineTaxSphere').'</td></tr>';
	foreach ($assignable as $booking) {
		print '<tr class="oddeven" data-assign="'.$booking['id'].'" data-area="'.$booking['area'].'"><td class="nowraponall">'.vereineFormatDay($booking['date']).'</td>';
		print '<td>'.dol_escape_htmltag($booking['account']).'</td><td><a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.$booking['id'].'">'.dol_escape_htmltag($booking['label']).'</a></td>';
		print '<td>'.$langs->trans('VereineAccountKind_'.$booking['kind']).'</td><td class="right nowraponall">'.$money($booking['amount']).'</td>';
		print '<td><select name="area['.$booking['id'].']"><option value="">'.$langs->trans('VereineAccountAssignNone').'</option>';
		foreach (VereineAccountRules::areas() as $area) {
			print '<option value="'.$area.'"'.($booking['area'] === $area ? ' selected' : '').'>'.$langs->trans('VereineSphereShort_'.$area).'</option>';
		}
		print '</select></td></tr>';
	}
	print '</table></div>';
	print '<div class="center paddingtop"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('VereineAccountAssignSave')).'"></div></form>';
}

// The statement of assets at the end of the year.
print '<br>'.load_fiche_titre($langs->trans('VereineAccountAssetsTitle', vereineFormatDay($period['end'])), '', '', 0, 'vereineaccountassets');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-account-net="'.price2num($data['net'], 'MT').'">';
foreach ($data['closing']['accounts'] as $bank) {
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($bank['label']).'</td><td class="right nowraponall">'.$money($bank['balance']).'</td></tr>';
}
foreach (array('receivables' => 1, 'payables' => -1) as $key => $sign) {
	print '<tr class="oddeven" data-account-open="'.$key.'" data-amount="'.price2num($data[$key]['total'], 'MT').'"><td>'.$langs->trans('VereineAccount'.ucfirst($key));
	if ($data[$key]['invoices']) {
		print '<details class="small"><summary>'.$langs->trans('VereineAccountOpenInvoices', count($data[$key]['invoices'])).'</summary>';
		foreach ($data[$key]['invoices'] as $invoice) {
			print '<div>'.dol_escape_htmltag($invoice['ref'].' – '.$invoice['party']).': '.$money($invoice['open']).'</div>';
		}
		print '</details>';
	}
	print '</td><td class="right nowraponall tdtop">'.$money($sign * $data[$key]['total']).'</td></tr>';
}
foreach ($data['extras'] as $extra) {
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($extra['label']).'</td><td class="right nowraponall">'.$money($extra['kind'] === 'debt' ? -$extra['amount'] : $extra['amount']).'</td></tr>';
}
print '<tr class="liste_total"><td>'.$langs->trans('VereineAccountNet').'</td><td class="right nowraponall">'.$money($data['net']).'</td></tr>';
print '</table></div>';

if ($canWrite) {
	print '<form method="POST" action="'.$self.'#vereineaccountassets" name="vereineaccount" class="paddingtop">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="save">';
	print '<table class="border centpercent"><tr><td class="titlefieldcreate"><label for="made_on">'.$langs->trans('VereineAccountMadeField').'</label></td>';
	print '<td><input type="date" id="made_on" name="made_on" value="'.dol_escape_htmltag($made).'"> <span class="opacitymedium small">'.$langs->trans('VereineAccountMadeHelp').'</span></td></tr>';
	print '<tr><td class="tdtop">'.$langs->trans('VereineAccountExtras').'<div class="opacitymedium small">'.$langs->trans('VereineAccountExtrasHelp').'</div></td><td>';
	$rows = array_merge($data['extras'], array_fill(0, 3, array('label' => '', 'amount' => '', 'kind' => 'asset')));
	foreach ($rows as $index => $extra) {
		print '<div class="paddingbottom"><input type="text" name="extras['.$index.'][label]" class="minwidth200" value="'.dol_escape_htmltag($extra['label']).'" placeholder="'.dol_escape_htmltag($langs->trans('Label')).'"> ';
		print '<input type="text" name="extras['.$index.'][amount]" class="width75 right" value="'.dol_escape_htmltag((string) $extra['amount']).'"> € ';
		print '<select name="extras['.$index.'][kind]"><option value="asset"'.($extra['kind'] === 'asset' ? ' selected' : '').'>'.$langs->trans('VereineAccountExtra_asset').'</option>';
		print '<option value="debt"'.($extra['kind'] === 'debt' ? ' selected' : '').'>'.$langs->trans('VereineAccountExtra_debt').'</option></select></div>';
	}
	print '</td></tr><tr><td><label for="note">'.$langs->trans('VereineAudit_note').'</label></td>';
	print '<td><textarea id="note" name="note" rows="2" class="centpercent">'.dol_escape_htmltag($data['record']['note'], 0, 1).'</textarea></td></tr></table>';
	print '<div class="center paddingtop"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div></form>';
}

// PDF, spreadsheet and signatures.
print '<br>'.load_fiche_titre($langs->trans('VereineAccountPdfSection'), '', '', 0, 'vereineaccountpdf');
print '<div class="paddingbottom"><a href="'.$self.'&amp;action=csv&amp;token='.newToken().'" data-account-csv="1">'.img_picto('', 'fa-file-csv').' '.$langs->trans('VereineAccountCsv').'</a></div>';
$pdfFile = VereineAccount::pdfPath($record['id']);
if (is_file($pdfFile)) {
	print '<div class="paddingbottom" data-account-pdf="1"><a href="'.$self.'&amp;action=pdf&amp;token='.newToken().'">'.img_picto('', 'pdf').' '.basename($pdfFile).'</a></div>';
}
if ($canWrite) {
	print '<form method="POST" action="'.$self.'#vereineaccountpdf" name="vereineaccountbuild" class="paddingbottom">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="build">';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans(is_file($pdfFile) ? 'VereineAccountPdfRebuild' : 'VereineAccountPdfBuild')).'"></form>';
}
if (is_file($pdfFile)) {
	vereineSignatureBlock($signatures, VereineSignatureRules::KIND_ACCOUNT, $record['id'], $pdfFile, $canWrite || $isSigner, 'vereineaccountpdf');
}

llxFooter();
$db->close();
