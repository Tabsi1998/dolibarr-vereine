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
 * \file    fees_run.php
 * \ingroup vereine
 * \brief   Fee run: preview of the fees due, then subscription periods and invoices for the chosen ones.
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

require_once __DIR__.'/class/vereinefeerun.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('members', 'bills', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read') || !$user->hasRight('adherent', 'lire')) {
	accessforbidden();
}
$mayRun = isModEnabled('invoice') && VereineFeeRun::mayRun($user);
$mayCreatePartners = $user->hasRight('societe', 'creer');

$action = GETPOST('action', 'aZ09');
$typeId = GETPOSTINT('typeid');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$dueUntil = $today;
if (GETPOSTINT('dueuntilyear') > 0) {
	$dueUntil = dol_print_date(dol_mktime(12, 0, 0, GETPOSTINT('dueuntilmonth'), GETPOSTINT('dueuntilday'), GETPOSTINT('dueuntilyear')), '%Y-%m-%d');
} elseif (VereineFeeRules::isDate(GETPOST('dueuntil', 'alpha'))) {
	$dueUntil = GETPOST('dueuntil', 'alpha');
}
if (!VereineFeeRules::isDate($dueUntil)) {
	$dueUntil = $today;
}

$feeRun = new VereineFeeRun($db);
$result = null;


/*
 * Actions
 */

if ($action === 'run') {
	if (!$mayRun) {
		accessforbidden($langs->trans('VereineFeeRunNoRight'));
	}
	$keys = array();
	foreach ((array) GETPOST('fees', 'array') as $key) {
		if (preg_match('/^\d+:\d{4}-\d{2}-\d{2}$/', (string) $key)) {
			$keys[] = (string) $key;
		}
	}
	$result = $feeRun->run($dueUntil, $typeId, $keys, GETPOSTINT('createpartners') === 1 && $mayCreatePartners, $user);
	if ($result['created']) {
		$total = 0.0;
		foreach ($result['created'] as $created) {
			$total += $created['fee']['total'];
		}
		setEventMessages($langs->trans('VereineFeeRunCreated', count($result['created']), price($total, 0, $langs, 1, -1, -1, $conf->currency)), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('VereineFeeRunNothingCreated'), null, 'warnings');
	}
	if ($result['skipped']) {
		setEventMessages($langs->trans('VereineFeeRunSkipped', count($result['skipped'])), null, 'warnings');
	}
	if ($result['failed']) {
		setEventMessages($langs->trans('VereineFeeRunFailed', count($result['failed'])), null, 'errors');
	}
}


/*
 * View
 */

$rows = $feeRun->preview($dueUntil, $typeId);
$feeModel = new VereineFeeModel($db);
$typeOptions = array(0 => $langs->trans('VereineFeeRunAllTypes'));
foreach ($feeModel->memberTypes() as $type) {
	if ($type['subscription']) {
		$typeOptions[$type['id']] = $type['label'];
	}
}

$title = $langs->trans('VereineFeeRunTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-fees-run');
print load_fiche_titre($title, '', 'fa-landmark');
print '<span class="opacitymedium">'.$langs->trans('VereineFeeRunIntro').'</span><br><br>';

$dateForm = new Form($db);
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="vereinefeerunfilter">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<label for="dueuntil">'.$langs->trans('VereineFeeRunDueUntil').'</label> ';
print $dateForm->selectDate(dol_mktime(12, 0, 0, (int) substr($dueUntil, 5, 2), (int) substr($dueUntil, 8, 2), (int) substr($dueUntil, 0, 4)), 'dueuntil', 0, 0, 0, 'vereinefeerunfilter', 1, 0);
print ' '.Form::selectarray('typeid', $typeOptions, $typeId, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
print ' <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('VereineFeeRunPreview')).'">';
print '</form><br>';

if ($result !== null && ($result['created'] || $result['skipped'] || $result['failed'])) {
	print load_fiche_titre($langs->trans('VereineFeeRunResult'), '', '');
	print '<table class="noborder centpercent" data-fee-result="1">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Member').'</td><td>'.$langs->trans('VereineFeeRunPeriod').'</td><td>'.$langs->trans('VereineFeeRunNote').'</td></tr>';
	foreach (array('created', 'skipped', 'failed') as $kind) {
		foreach ($result[$kind] as $entry) {
			print '<tr class="oddeven" data-fee-outcome="'.$kind.'" data-fee-key="'.dol_escape_htmltag($entry['key']).'">';
			print '<td>'.dol_escape_htmltag($entry['name']).'</td>';
			print '<td>'.($entry['fee'] ? vereineFormatDay($entry['fee']['start']).' - '.vereineFormatDay($entry['fee']['end']) : '').'</td><td>';
			if ($kind === 'created' && $entry['invoice_id'] <= 0) {
				print $langs->trans('VereineFeeRunPeriodOnly');
			} elseif ($kind === 'created') {
				print '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $entry['invoice_id']).'">'.dol_escape_htmltag($entry['invoice_ref']).'</a>';
			} elseif ($kind === 'skipped') {
				print $langs->trans('VereineFeeRunSkip_'.$entry['reason']);
			} else {
				print '<span class="error">'.dol_escape_htmltag($entry['error']).'</span>';
			}
			print '</td></tr>';
		}
	}
	print '</table><br>';
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinefeerun">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="run">';
print '<input type="hidden" name="dueuntil" value="'.dol_escape_htmltag($dueUntil).'">';
print '<input type="hidden" name="typeid" value="'.((int) $typeId).'">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-fee-preview="1">';
print '<tr class="liste_titre"><td></td><td>'.$langs->trans('Member').'</td><td>'.$langs->trans('MemberType').'</td>';
print '<td>'.$langs->trans('VereineFeeRunPeriod').'</td><td class="right">'.$langs->trans('VereineFeesAmount').'</td>';
print '<td class="right">'.$langs->trans('VereineFeeAdmission').'</td><td class="right">'.$langs->trans('VereineFeeRunTotal').'</td><td>'.$langs->trans('VereineFeeRunNote').'</td></tr>';
if (!$rows) {
	print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">'.$langs->trans($feeRun->error !== '' ? 'VereineFeesReadError' : 'VereineFeeRunNothingDue').'</span></td></tr>';
}
foreach ($rows as $row) {
	$fee = $row['fee'];
	$selectable = $mayRun && in_array($row['status'], array(VereineFeeRun::READY, VereineFeeRun::NO_PARTNER), true);
	print '<tr class="oddeven" data-fee-row="'.dol_escape_htmltag($row['key']).'" data-status="'.$row['status'].'"';
	print ' data-total="'.($fee && $fee['total'] !== null ? price2num($fee['total'], 'MT') : '').'">';
	print '<td>';
	if ($selectable) {
		print '<input type="checkbox" name="fees[]" value="'.dol_escape_htmltag($row['key']).'"'.($row['status'] === VereineFeeRun::READY ? ' checked' : '').'>';
	}
	print '</td>';
	print '<td><a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.((int) $row['member_id']).'">'.dol_escape_htmltag($row['name']).'</a></td>';
	print '<td>'.dol_escape_htmltag($row['type_label']).'</td>';
	if ($fee === null) {
		print '<td colspan="4"></td>';
	} else {
		print '<td class="nowraponall">'.vereineFormatDay($fee['start']).' - '.vereineFormatDay($fee['end']).'</td>';
		print '<td class="right nowraponall">'.($fee['amount'] === null ? '' : price($fee['amount'], 0, $langs, 1, -1, -1, $conf->currency)).'</td>';
		print '<td class="right nowraponall">'.($fee['admission_fee'] > 0 ? price($fee['admission_fee'], 0, $langs, 1, -1, -1, $conf->currency) : '').'</td>';
		print '<td class="right nowraponall"><strong>'.($fee['total'] === null ? '' : price($fee['total'], 0, $langs, 1, -1, -1, $conf->currency)).'</strong></td>';
	}
	print '<td class="small">';
	$notes = array();
	if ($row['status'] !== VereineFeeRun::READY) {
		$notes[] = '<span class="warning">'.$langs->trans('VereineFeeRunStatus_'.$row['status']).'</span>';
	}
	if ($fee !== null) {
		$discount = $row['discount'];
		if ($discount['kind'] === 'exempt') {
			$notes[] = '<span data-discount-kind="exempt">'.$langs->trans('VereineDiscountExemptNote', dol_escape_htmltag($discount['reason'])).'</span>';
		} elseif ($discount['kind'] !== 'none' && isset($fee['full_total']) && $fee['full_total'] !== null) {
			$notes[] = '<span data-discount-kind="'.$discount['kind'].'">'.$langs->trans('VereineDiscountApplied', dol_escape_htmltag($discount['reason']), price($fee['full_total'], 0, $langs, 1, -1, -1, $conf->currency)).'</span>';
		}
		foreach ($discount['notes'] as $note) {
			$notes[] = '<span class="warning">'.$langs->trans($note).'</span>';
		}
		$family = $row['family'];
		if ($family['kind'] === VereineFeeFamilies::MODE_PERCENT) {
			$notes[] = '<span data-family-kind="percent">'.$langs->trans('VereineFamilyPercentNote', $family['size'], price2num($family['value']),
				price($family['before'], 0, $langs, 1, -1, -1, $conf->currency)).'</span>';
		} elseif ($family['kind'] === VereineFeeFamilies::MODE_CAP) {
			$notes[] = '<span data-family-kind="cap">'.$langs->trans('VereineFamilyCapNote', price($family['value'], 0, $langs, 1, -1, -1, $conf->currency),
				vereineFormatDay($family['year_start']), price($family['charged'], 0, $langs, 1, -1, -1, $conf->currency),
				price($family['before'], 0, $langs, 1, -1, -1, $conf->currency)).'</span>';
		}
		if ($row['payer_name'] !== '') {
			$notes[] = '<span data-payer="'.((int) $row['payer_socid']).'">'.$langs->trans('VereineFamilyInvoiceTo', dol_escape_htmltag($row['payer_name'])).'</span>';
		}
		$reason = vereineFeeReason($fee);
		if ($reason !== '') {
			$notes[] = $reason;
		}
		if ($fee['admission_fee'] > 0) {
			$notes[] = $langs->trans('VereineFeeRunFirstFee');
		}
	}
	if ($row['backlog'] > 1) {
		$notes[] = $langs->trans('VereineFeeRunBacklog', $row['backlog']);
	}
	print implode('<br>', $notes);
	print '</td></tr>';
}
print '</table></div>';
if ($rows) {
	if ($mayRun) {
		if ($mayCreatePartners) {
			print '<input type="checkbox" id="createpartners" name="createpartners" value="1"> <label for="createpartners">'.$langs->trans('VereineFeeRunCreatePartners').'</label><br>';
		}
		print '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('VereineFeeRunCreate')).'"></div>';
	} else {
		print '<div class="warning">'.$langs->trans('VereineFeeRunNoRight').'</div>';
	}
}
print '</form><br>';

print load_fiche_titre($langs->trans('VereineFeeRunRecent'), '', '');
print '<span class="opacitymedium small">'.$langs->trans('VereineFeeRunRecentHelp').'</span>';
print '<table class="noborder centpercent" data-fee-recent="1">';
print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Member').'</td><td>'.$langs->trans('VereineFeeRunPeriod').'</td>';
print '<td class="right">'.$langs->trans('AmountTTC').'</td><td>'.$langs->trans('Status').'</td></tr>';
$recent = $feeRun->recentFeeInvoices(30);
if (!$recent) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
}
foreach ($recent as $invoice) {
	print '<tr class="oddeven" data-fee-invoice="'.dol_escape_htmltag($invoice['ref']).'" data-status="'.$invoice['status'].'">';
	print '<td><a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $invoice['id']).'">'.dol_escape_htmltag($invoice['ref']).'</a></td>';
	print '<td><a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.((int) $invoice['member_id']).'">'.dol_escape_htmltag($invoice['name']).'</a></td>';
	print '<td class="nowraponall">'.vereineFormatDay($invoice['start']).' - '.vereineFormatDay($invoice['end']).'</td>';
	print '<td class="right nowraponall">'.price($invoice['total'], 0, $langs, 1, -1, -1, $conf->currency).'</td>';
	print '<td>'.$langs->trans('VereineInvoiceStatus_'.$invoice['status']).'</td></tr>';
}
print '</table>';

llxFooter();
$db->close();
