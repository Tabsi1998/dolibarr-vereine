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
 * \file    volunteer.php
 * \ingroup vereine
 * \brief   Volunteer allowances (#7): days of voluntary work, their limits, and the list for the reports.
 *
 * Whoever handles the money records a day of work with its allowance; the page says at once what goes
 * over a limit. The confirmed helper shifts of the events are offered for recording. At the end of the
 * year the list per person is what the reports to the tax office are prepared from.
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

require_once __DIR__.'/class/vereinevolunteers.class.php';
require_once __DIR__.'/class/vereinevolunteerpayouts.class.php';
require_once __DIR__.'/class/vereineduties.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('members', 'other', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read')) {
	accessforbidden();
}

$volunteers = new VereineVolunteers($db);
$payouts = new VereineVolunteerPayouts($db);
$signatures = new VereineSignatures($db);
$action = GETPOST('action', 'aZ09');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$thisYear = (int) substr($today, 0, 4);
$year = GETPOSTINT('year');
// The next year too: a helper shift can be confirmed before the year it lies in has begun.
if ($year < $thisYear - 5 || $year > $thisYear + 1) {
	$year = $thisYear;
}
$mayRecord = $volunteers->mayRecord($user, $today);
$self = $_SERVER['PHP_SELF'].'?year='.$year;


/*
 * Actions
 */

if ($action === 'record' && $mayRecord) {
	$entered = array('member_id' => GETPOSTINT('member_id'), 'day' => GETPOST('day', 'alphanohtml'),
		'activity' => GETPOST('activity', 'alphanohtml'), 'kind' => GETPOST('kind', 'aZ09'), 'amount' => GETPOST('amount', 'alphanohtml'),
		'hours' => GETPOST('hours', 'alphanohtml'), 'shift_entry_id' => GETPOSTINT('shift_entry'), 'note' => GETPOST('note', 'alphanohtml'));
	$id = $volunteers->record($entered, $user);
	if ($id > 0) {
		if ($volunteers->findings) {
			$said = array();
			foreach ($volunteers->findings as $finding) {
				$said[] = $langs->trans('VereineVolunteerFinding_'.$finding);
			}
			setEventMessages($langs->trans('VereineVolunteerRecordedOver'), $said, 'warnings');
		} else {
			setEventMessages($langs->trans('VereineVolunteerRecorded'), null, 'mesgs');
		}
		header('Location: '.$_SERVER['PHP_SELF'].'?year='.((int) substr((string) $entered['day'], 0, 4) ?: $year).'#vereinevolunteerentries');
		exit;
	}
	setEventMessages($id < 0 ? $volunteers->error : null, $id < 0 ? null : array_map(array($langs, 'trans'), $volunteers->errors), 'errors');
} elseif ($action === 'remove' && $mayRecord) {
	$result = $volunteers->remove(GETPOSTINT('entry'), $user);
	if ($result > 0) {
		header('Location: '.$self.'#vereinevolunteerentries');
		exit;
	}
	setEventMessages($result < 0 ? $volunteers->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $volunteers->errors), 'errors');
} elseif ($action === 'payout' && $mayRecord) {
	// A list of what goes out; it is signed before anything is paid.
	$created = $payouts->create(GETPOST('entries', 'array'), $year, $user, $langs);
	if ($created > 0) {
		setEventMessages($langs->trans('VereineVolunteerPayoutCreated'), null, 'mesgs');
		header('Location: '.$self.'#vereinevolunteerpayouts');
		exit;
	}
	setEventMessages($created < 0 ? $payouts->error : null, $created < 0 ? null : array_map(array($langs, 'trans'), $payouts->errors), 'errors');
} elseif ($action === 'paypayout' && $mayRecord) {
	$made = $payouts->pay(GETPOSTINT('payout'), GETPOSTINT('bank_account'), GETPOSTINT('payment_mode'), GETPOST('pay_day', 'alphanohtml'), $user);
	if ($made > 0) {
		setEventMessages($langs->trans('VereineVolunteerPayoutPaid', $made), null, 'mesgs');
		header('Location: '.$self.'#vereinevolunteerpayouts');
		exit;
	}
	setEventMessages($made < 0 ? $payouts->error : null, $made < 0 ? null : array_map(array($langs, 'trans'), $payouts->errors), 'errors');
} elseif ($action === 'cancelpayout' && $mayRecord) {
	$result = $payouts->cancel(GETPOSTINT('payout'), $user);
	if ($result > 0) {
		header('Location: '.$self.'#vereinevolunteerpayouts');
		exit;
	}
	setEventMessages($result < 0 ? $payouts->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $payouts->errors), 'errors');
} elseif ($action === 'startsign' && $mayRecord) {
	$objectId = GETPOSTINT('object');
	$result = $payouts->fetch($objectId) !== null ? $signatures->start(VereineSignatureRules::KIND_PAYOUT, $objectId,
		VereineVolunteerPayouts::path($objectId), $today, $user) : 0;
	if ($result > 0) {
		setEventMessages($langs->trans('VereineSignatureStarted'), null, 'mesgs');
		header('Location: '.$self.'#vereinevolunteerpayouts');
		exit;
	}
	setEventMessages($result < 0 ? $signatures->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $signatures->errors), 'errors');
} elseif ($action === 'sign' || $action === 'signscan') {
	// Who may sign is the signature run's business: it knows who is asked to sign this list.
	$run = $signatures->fetch(GETPOSTINT('signature'));
	$ok = $run !== null && $run['kind'] === VereineSignatureRules::KIND_PAYOUT && $payouts->fetch($run['object_id']) !== null;
	if ($action === 'sign') {
		$result = $ok ? $signatures->sign($run['id'], GETPOST('password', 'password'), VereineVolunteerPayouts::path($run['object_id']), $user, $langs) : 0;
	} else {
		$upload = isset($_FILES['scan_file']) && is_array($_FILES['scan_file']) ? $_FILES['scan_file'] : array();
		$result = $ok && $mayRecord ? $signatures->uploadScan($run['id'], $upload, $user, $langs) : 0;
	}
	if ($result > 0) {
		setEventMessages($langs->trans($action === 'sign' ? 'VereineSignatureSigned' : 'VereineSignatureScanStored'), null, 'mesgs');
		header('Location: '.$self.'#vereinevolunteerpayouts');
		exit;
	}
	setEventMessages($result < 0 ? $signatures->error : null, $result < 0 ? null
		: array_map(array($langs, 'trans'), $ok ? $signatures->errors : array('VereineSignatureErrorNotOpen')), 'errors');
} elseif (in_array($action, array('payoutpdf', 'sheet', 'signed'), true)) {
	if ($action === 'payoutpdf') {
		$file = $payouts->fetch(GETPOSTINT('payout')) !== null ? VereineVolunteerPayouts::path(GETPOSTINT('payout')) : '';
	} else {
		$run = $signatures->fetch(GETPOSTINT('signature'));
		$money = $run !== null && $run['kind'] === VereineSignatureRules::KIND_PAYOUT && $payouts->fetch($run['object_id']) !== null;
		$file = !$money ? '' : ($action === 'sheet' ? VereineSignatures::sheetPath($run['id']) : VereineSignatures::scanPath($run));
	}
	if ($file === '' || !is_file($file)) {
		accessforbidden();
	}
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
} elseif ($action === 'csv') {
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="freiwilligenpauschale-'.$year.'.csv"');
	// A byte order mark, so a spreadsheet opens the umlauts as they are.
	print "\xEF\xBB\xBF".$volunteers->csv($year);
	exit;
}


/*
 * View
 */

$entries = $volunteers->entries($year);
$people = VereineVolunteerRules::yearList($entries, $year);
$open = $mayRecord ? $volunteers->openShifts($year) : array();
$kinds = array();
foreach (VereineVolunteerRules::KINDS as $kind) {
	$kinds[$kind] = VereineVolunteerRules::limitsOn($kind, $today);
}
$money = function ($amount) use ($langs) {
	return price($amount, 0, $langs, 1, -1, 2).' €';
};

llxHeader('', $langs->trans('VereineVolunteerTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-volunteer');
print load_fiche_titre($langs->trans('VereineVolunteerTitle'), '', 'fa-hands-helping');
print '<div class="paddingbottom" data-volunteer-years="1">'.$langs->trans('VereineVolunteerYear').': ';
for ($option = $thisYear - 5; $option <= $thisYear + 1; $option++) {
	print $option === $year ? '<strong class="paddingright">'.$option.'</strong> '
		: '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?year='.$option.'">'.$option.'</a> ';
}
print '</div>';

print '<div class="info" data-volunteer-howto="1"><ul>';
foreach ($kinds as $kind => $limits) {
	if ($limits === null) {
		continue;
	}
	print '<li data-volunteer-limit="'.$kind.'">'.$langs->trans('VereineVolunteerLimit_'.$kind, $money($limits['day']),
		$money($kind === VereineVolunteerRules::KIND_PRAE ? $limits['month'] : $limits['year']));
	print ' <span class="opacitymedium small">'.dol_escape_htmltag($limits['basis']).'</span></li>';
}
print '<li>'.$langs->trans('VereineVolunteerHowToReport').'</li>';
print '<li>'.$langs->trans('VereineVolunteerHowToAdvice').'</li>';
print '</ul></div>';

// The deadline of the report lives in the calendar of duties (#24); say so when it is switched off there.
$duties = new VereineDuties($db);
$reportDuty = null;
foreach ($duties->fetchAll() as $duty) {
	if ($duty['code'] === 'volunteers') {
		$reportDuty = $duty;
	}
}
if ($entries && ($reportDuty === null || !$reportDuty['active'])) {
	print '<div class="warning" data-volunteer-duty="off">'.$langs->trans('VereineVolunteerDutyOff',
		'<a href="'.dol_buildpath('/vereine/duties.php', 1).'#vereinedutycatalogue">'.$langs->trans('VereineMenuDuties').'</a>').'</div>';
}

// The year per person: what the reports are prepared from.
print load_fiche_titre($langs->trans('VereineVolunteerYearList', $year), '', '', 0, 'vereinevolunteeryear');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-volunteer-people="'.count($people).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('Member').'</td><td class="right">'.$langs->trans('VereineVolunteerDays').'</td>';
print '<td class="right">'.$langs->trans('VereineVolunteerKind_small').'</td><td class="right">'.$langs->trans('VereineVolunteerKind_large').'</td>';
print '<td class="right">'.$langs->trans('VereineVolunteerKind_prae').'</td><td>'.$langs->trans('VereineVolunteerFindings').'</td></tr>';
foreach ($people as $person) {
	print '<tr class="oddeven" data-volunteer-person="'.((int) $person['member_id']).'" data-volunteer-total="'
		.number_format($person['small'] + $person['large'] + $person['prae'], 2, '.', '').'" data-volunteer-findings="'.implode(' ', $person['findings']).'">';
	print '<td><a href="'.dol_buildpath('/vereine/member_association.php', 1).'?id='.((int) $person['member_id']).'">'.dol_escape_htmltag($person['name']).'</a></td>';
	print '<td class="right">'.((int) $person['days']).'</td>';
	foreach (VereineVolunteerRules::KINDS as $kind) {
		print '<td class="right nowraponall">'.($person[$kind] > 0 ? $money($person[$kind]) : '').'</td>';
	}
	print '<td>';
	foreach ($person['findings'] as $finding) {
		print '<span class="badge badge-status badge-status8">'.$langs->trans('VereineVolunteerFinding_'.$finding).'</span> ';
	}
	print '</td></tr>';
}
if (!$people) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium" data-volunteer-none="1">'.$langs->trans('VereineVolunteerNone').'</span></td></tr>';
}
print '</table></div>';
if ($people) {
	print '<div class="paddingbottom"><a href="'.$self.'&amp;action=csv&amp;token='.newToken().'" data-volunteer-csv="1">'
		.img_picto('', 'fa-file-csv').' '.$langs->trans('VereineVolunteerCsv').'</a></div>';
}

// Every day, with what it goes over.
print load_fiche_titre($langs->trans('VereineVolunteerEntries'), '', '', 0, 'vereinevolunteerentries');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-volunteer-entries="'.count($entries).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Member').'</td><td>'.$langs->trans('VereineVolunteerActivity').'</td>';
print '<td>'.$langs->trans('VereineVolunteerKind').'</td><td class="right">'.$langs->trans('Amount').'</td><td>'.$langs->trans('VereineVolunteerFindings').'</td><td></td></tr>';
foreach ($entries as $entry) {
	print '<tr class="oddeven" data-volunteer-entry="'.((int) $entry['id']).'" data-volunteer-kind="'.$entry['kind'].'"';
	print ' data-volunteer-flags="'.implode(' ', $entry['check']['findings']).'">';
	print '<td class="nowraponall">'.vereineFormatDay($entry['day']).'</td><td>'.dol_escape_htmltag($entry['name']).'</td>';
	print '<td>'.dol_escape_htmltag($entry['activity']).($entry['shift_entry_id'] > 0 ? ' <span class="opacitymedium small">'.$langs->trans('VereineVolunteerFromShift').'</span>' : '').'</td>';
	print '<td class="nowraponall">'.$langs->trans('VereineVolunteerKind_'.$entry['kind']).'</td>';
	print '<td class="right nowraponall">'.$money($entry['amount']).'</td><td>';
	foreach ($entry['check']['findings'] as $finding) {
		print '<span class="badge badge-status badge-status8">'.$langs->trans('VereineVolunteerFinding_'.$finding).'</span> ';
	}
	print '</td><td class="right">';
	if ($entry['paid_on'] !== '') {
		print '<span class="badge badge-status badge-status6" data-volunteer-paid="'.((int) $entry['id']).'">'.$langs->trans('VereineVolunteerPaidOn', vereineFormatDay($entry['paid_on'])).'</span>';
	} elseif ($entry['payout_id'] > 0) {
		print '<span class="opacitymedium small">'.$langs->trans('VereineVolunteerOnPayout').'</span>';
	} elseif ($mayRecord) {
		print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="remove"><input type="hidden" name="entry" value="'.((int) $entry['id']).'">';
		print '<input type="submit" class="button small butActionDelete" value="'.dol_escape_htmltag($langs->trans('Delete')).'"></form>';
	}
	print '</td></tr>';
}
if (!$entries) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('VereineVolunteerNone').'</span></td></tr>';
}
print '</table></div>';

// Paying: a list, signed like every money matter, then paid through Dolibarr (#7).
$openIds = $payouts->openEntryIds($year);
$allPayouts = $payouts->all();
print load_fiche_titre($langs->trans('VereineVolunteerPayouts'), '', 'fa-money-bill-wave', 0, 'vereinevolunteerpayouts');
print '<div class="info" data-volunteer-payout-howto="1">'.$langs->trans('VereineVolunteerPayoutHowTo').'</div>';
if ($mayRecord && $openIds) {
	print '<form method="POST" name="vereinevolunteerpayout" action="'.$self.'#vereinevolunteerpayouts">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="payout">';
	print '<div class="paddingbottom" data-volunteer-payable="'.count($openIds).'">';
	foreach ($entries as $entry) {
		if (in_array($entry['id'], $openIds, true)) {
			print '<label class="paddingright"><input type="checkbox" name="entries[]" value="'.((int) $entry['id']).'" checked> ';
			print dol_escape_htmltag($entry['name']).' · '.vereineFormatDay($entry['day']).' · '.$money($entry['amount']).'</label><br>';
		}
	}
	print '</div><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineVolunteerPayoutCreate')).'"></form>';
}
$choices = $payouts->bankChoices();
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-volunteer-payouts="'.count($allPayouts).'">';
foreach ($allPayouts as $payout) {
	$payable = $payout['status'] === VereineVolunteerPayouts::STATUS_DRAFT && $payouts->payable($payout['id']);
	print '<tr class="oddeven" data-volunteer-payout="'.((int) $payout['id']).'" data-volunteer-payout-status="'.$payout['status'].'"';
	print ' data-volunteer-payout-payable="'.($payable ? 1 : 0).'"><td>';
	print '<strong>'.dol_escape_htmltag($payout['label']).'</strong> · '.$money($payout['total']);
	if (is_file(VereineVolunteerPayouts::path($payout['id']))) {
		print ' · <a href="'.$self.'&amp;action=payoutpdf&amp;payout='.((int) $payout['id']).'&amp;token='.newToken().'">'.img_picto('', 'pdf').' '.$langs->trans('VereineVolunteerPayoutPdf').'</a>';
	}
	print '<br><span class="badge badge-status '.($payout['status'] === VereineVolunteerPayouts::STATUS_PAID ? 'badge-status6' : 'badge-status1').'">';
	print $langs->trans('VereineVolunteerPayoutStatus_'.$payout['status']).($payout['paid_on'] !== '' ? ' '.vereineFormatDay($payout['paid_on']) : '').'</span>';
	print '</td><td>';
	vereineSignatureBlock($signatures, VereineSignatureRules::KIND_PAYOUT, $payout['id'], VereineVolunteerPayouts::path($payout['id']), $mayRecord, 'vereinevolunteerpayouts');
	print '</td><td class="right">';
	if ($mayRecord && $payable) {
		print '<form method="POST" name="vereinevolunteerpay'.((int) $payout['id']).'" action="'.$self.'#vereinevolunteerpayouts">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="paypayout">';
		print '<input type="hidden" name="payout" value="'.((int) $payout['id']).'">';
		print '<select name="bank_account" class="flat">';
		foreach ($choices['accounts'] as $accountId => $label) {
			print '<option value="'.$accountId.'">'.dol_escape_htmltag($label).'</option>';
		}
		print '</select> <select name="payment_mode" class="flat">';
		foreach ($choices['modes'] as $modeId => $label) {
			print '<option value="'.$modeId.'">'.dol_escape_htmltag($label).'</option>';
		}
		print '</select> <input type="date" name="pay_day" value="'.dol_escape_htmltag($today).'">';
		print ' <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineVolunteerPayoutPay')).'"></form>';
	} elseif ($payout['status'] === VereineVolunteerPayouts::STATUS_DRAFT) {
		print '<span class="opacitymedium small" data-volunteer-waits-signature="1">'.$langs->trans('VereineVolunteerPayoutWaits').'</span>';
	}
	if ($mayRecord && $payout['status'] === VereineVolunteerPayouts::STATUS_DRAFT) {
		print '<form method="POST" action="'.$self.'#vereinevolunteerpayouts" class="inline-block paddingtop"><input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="cancelpayout"><input type="hidden" name="payout" value="'.((int) $payout['id']).'">';
		print '<input type="submit" class="button small butActionDelete" value="'.dol_escape_htmltag($langs->trans('VereineVolunteerPayoutCancel')).'"></form>';
	}
	print '</td></tr>';
}
if (!$allPayouts) {
	print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('VereineVolunteerPayoutNone').'</span></td></tr>';
}
print '</table></div>';

if ($mayRecord) {
	// Confirmed helper shifts that have no allowance yet: one click with the amount, nothing typed twice.
	if ($open) {
		print load_fiche_titre($langs->trans('VereineVolunteerOpenShifts', count($open)), '', '', 0, 'vereinevolunteershifts');
		print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-volunteer-open="'.count($open).'">';
		foreach ($open as $row) {
			print '<tr class="oddeven" data-volunteer-shift="'.((int) $row['entry_id']).'"><td class="nowraponall">'.vereineFormatDay($row['day']).'</td>';
			print '<td>'.dol_escape_htmltag($row['name']).'</td><td>'.dol_escape_htmltag($row['activity']);
			if ($row['hours'] > 0) {
				print ' <span class="opacitymedium small">'.$langs->trans('VereineShiftHoursValue', price($row['hours'], 0, $langs, 1, -1, 2)).'</span>';
			}
			print '</td><td class="right nowraponall">';
			print '<form method="POST" name="vereinevolunteershift'.((int) $row['entry_id']).'" action="'.$self.'" class="inline-block">';
			print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="record">';
			print '<input type="hidden" name="shift_entry" value="'.((int) $row['entry_id']).'">';
			print '<input type="hidden" name="member_id" value="'.((int) $row['member_id']).'">';
			print '<input type="hidden" name="day" value="'.dol_escape_htmltag($row['day']).'">';
			print '<input type="hidden" name="activity" value="'.dol_escape_htmltag($row['activity']).'">';
			print '<input type="hidden" name="hours" value="'.dol_escape_htmltag((string) $row['hours']).'">';
			print '<select name="kind" class="flat">';
			foreach (VereineVolunteerRules::KINDS as $kind) {
				print '<option value="'.$kind.'">'.$langs->trans('VereineVolunteerKind_'.$kind).'</option>';
			}
			print '</select> <input type="text" name="amount" size="6" value="" placeholder="€">';
			print ' <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineVolunteerRecord')).'"></form></td></tr>';
		}
		print '</table></div>';
	}

	// A day that was not a helper shift.
	$members = array();
	$resql = $db->query("SELECT rowid, firstname, lastname FROM ".MAIN_DB_PREFIX."adherent WHERE entity = ".((int) $conf->entity)." AND statut = 1 ORDER BY lastname, firstname");
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$members[(int) $obj->rowid] = trim($obj->firstname.' '.$obj->lastname);
	}
	print load_fiche_titre($langs->trans('VereineVolunteerAdd'), '', '', 0, 'vereinevolunteerform');
	print '<form method="POST" name="vereinevolunteer" action="'.$self.'#vereinevolunteerform">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="record">';
	print '<table class="border centpercent">';
	print '<tr><td class="titlefield fieldrequired">'.$langs->trans('Member').'</td><td><select name="member_id" class="flat">';
	foreach ($members as $memberId => $name) {
		print '<option value="'.$memberId.'">'.dol_escape_htmltag($name).'</option>';
	}
	print '</select></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Date').'</td><td><input type="date" name="day" value="'.dol_escape_htmltag($today).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('VereineVolunteerActivity').'</td><td><input type="text" name="activity" size="50" maxlength="255" value=""></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('VereineVolunteerKind').'</td><td><select name="kind" class="flat">';
	foreach (VereineVolunteerRules::KINDS as $kind) {
		print '<option value="'.$kind.'">'.$langs->trans('VereineVolunteerKind_'.$kind).'</option>';
	}
	print '</select><br><span class="opacitymedium small">'.$langs->trans('VereineVolunteerKindHint').'</span></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Amount').'</td><td><input type="text" name="amount" size="8" value=""> €</td></tr>';
	print '<tr><td>'.$langs->trans('Note').'</td><td><input type="text" name="note" size="50" value=""></td></tr>';
	print '</table>';
	print '<div class="center paddingtop"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineVolunteerRecord')).'"></div></form>';
}

llxFooter();
$db->close();
