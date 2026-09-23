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
 * \file    overpayments.php
 * \ingroup vereine
 * \brief   Overpayments (#54): invoices paid over their total, and what becomes of the excess.
 *
 * The module asks and never decides by the amount: the excess stays as a credit, goes back, or becomes a
 * donation when it was given freely. Each way shows beforehand what Dolibarr will make of it.
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

require_once __DIR__.'/class/vereineoverpayments.class.php';
require_once __DIR__.'/class/vereinevolunteerpayouts.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('bills', 'banks', 'members', 'other', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read') || !$user->hasRight('facture', 'lire')) {
	accessforbidden();
}

$store = new VereineOverpayments($db);
$action = GETPOST('action', 'aZ09');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$thisYear = (int) substr($today, 0, 4);
$year = GETPOSTINT('year');
if ($year !== 0 && ($year < $thisYear - 10 || $year > $thisYear)) {
	$year = 0;
}
$show = GETPOST('show', 'aZ09') === 'all' ? 'all' : 'open';
$search = trim(GETPOST('search', 'alphanohtml'));
$invoiceId = GETPOSTINT('invoice');
$self = $_SERVER['PHP_SELF'].'?'.http_build_query(array('year' => $year, 'show' => $show, 'search' => $search));


/*
 * Actions
 */

if ($action === 'assign') {
	$kind = GETPOST('kind', 'aZ09');
	$result = $store->assign($invoiceId, $kind, GETPOST('given_freely', 'aZ09') === 'yes',
		array('account' => GETPOSTINT('bank_account'), 'mode' => GETPOSTINT('payment_mode'), 'day' => GETPOST('pay_day', 'alphanohtml')), $user, $langs);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineOverpaymentAssigned_'.$kind), null, 'mesgs');
		header('Location: '.$self.'&invoice='.$invoiceId.'#vereineoverpayment');
		exit;
	}
	setEventMessages($result < 0 ? $store->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $store->errors), 'errors');
}


/*
 * View
 */

$rows = $store->listing($year, $search, $show === 'open');
$open = array_values(array_filter($show === 'open' ? $rows : $store->listing($year, $search, true), function ($row) {
	return $row['state'] === VereineOverpaymentRules::STATE_OPEN;
}));
$chosen = $invoiceId > 0 ? $store->fetch($invoiceId) : null;
$money = function ($amount) use ($langs) {
	return price($amount, 0, $langs, 1, -1, 2).' €';
};
$invoiceLink = function ($row) {
	return '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $row['invoice_id']).'">'.img_picto('', 'bill', 'class="pictofixedwidth"')
		.dol_escape_htmltag($row['ref']).'</a>';
};
$partnerLink = function ($row) use ($langs) {
	$html = '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $row['socid']).'">'.dol_escape_htmltag($row['partner']).'</a>';
	if ($row['member_id'] > 0) {
		$html .= ' <a class="opacitymedium small" href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.((int) $row['member_id']).'">'.$langs->trans('Member').'</a>';
	}
	return $html;
};
// What became of an excess, with the Dolibarr object behind it.
$stateText = function ($row) use ($langs) {
	$badge = $row['state'] === VereineOverpaymentRules::STATE_OPEN ? 'badge-status1' : 'badge-status4';
	$html = '<span class="badge badge-status '.$badge.'">'.$langs->trans('VereineOverpaymentState_'.$row['state']).'</span>';
	if ($row['state'] === VereineOverpaymentRules::KIND_REFUND && $row['payment_id'] > 0) {
		$html .= ' <a href="'.DOL_URL_ROOT.'/compta/bank/various_payment/card.php?id='.$row['payment_id'].'">'.$langs->trans('VereineOverpaymentShowPayment').'</a>';
	} elseif ($row['state'] === VereineOverpaymentRules::KIND_DONATION && $row['don_id'] > 0) {
		$html .= ' <a href="'.DOL_URL_ROOT.'/don/card.php?id='.$row['don_id'].'">'.$langs->trans('VereineOverpaymentShowDonation').'</a>';
	} elseif (in_array($row['state'], array(VereineOverpaymentRules::KIND_CREDIT, VereineOverpaymentRules::STATE_DOLIBARR), true)) {
		$html .= ' <a href="'.DOL_URL_ROOT.'/comm/remx.php?id='.$row['socid'].'">'.$langs->trans('VereineOverpaymentShowCredit').'</a>';
	}
	return $html;
};

llxHeader('', $langs->trans('VereineOverpaymentTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-overpayments');
print load_fiche_titre($langs->trans('VereineOverpaymentTitle'), '', 'fa-coins');
print '<div class="info" data-overpayment-howto="1">'.$langs->trans('VereineOverpaymentHowTo').'</div>';

// One invoice: what was paid over, and the three ways with what Dolibarr makes of each.
if ($chosen !== null) {
	print load_fiche_titre($langs->trans('VereineOverpaymentFor', $chosen['ref']), '', '', 0, 'vereineoverpayment');
	print '<table class="border centpercent" data-overpayment-chosen="'.((int) $chosen['invoice_id']).'" data-overpayment-state="'.$chosen['state'].'">';
	print '<tr><td class="titlefield">'.$langs->trans('Invoice').'</td><td>'.$invoiceLink($chosen).' · '.vereineFormatDay($chosen['day']).'</td></tr>';
	print '<tr><td>'.$langs->trans('ThirdParty').'</td><td>'.$partnerLink($chosen).'</td></tr>';
	print '<tr><td>'.$langs->trans('VereineOverpaymentInvoiceTotal').'</td><td class="nowraponall">'.$money($chosen['total']).'</td></tr>';
	print '<tr><td>'.$langs->trans('VereineOverpaymentPaid').'</td><td class="nowraponall">'.$money($chosen['paid']).'</td></tr>';
	print '<tr><td><strong>'.$langs->trans('VereineOverpaymentExcess').'</strong></td><td class="nowraponall"><strong data-overpayment-excess="'
		.number_format($chosen['excess'], 2, '.', '').'">'.$money($chosen['excess']).'</strong> '.$stateText($chosen).'</td></tr>';
	print '</table>';

	if ($chosen['state'] === VereineOverpaymentRules::STATE_OPEN) {
		$last = $store->lastPayment($chosen['invoice_id']);
		$choices = (new VereineVolunteerPayouts($db))->bankChoices();
		print '<div class="fichecenter paddingtop"><table class="noborder centpercent">';
		foreach (VereineOverpaymentRules::KINDS as $kind) {
			$blocker = $store->blocker($user, $kind);
			print '<tr class="oddeven tdtop" data-overpayment-way="'.$kind.'" data-overpayment-blocked="'.($blocker !== '' ? 1 : 0).'"><td class="titlefield"><strong>'
				.$langs->trans('VereineOverpaymentWay_'.$kind).'</strong></td><td>';
			// The preview: what Dolibarr will make of it, in so many words.
			// The name of the third party goes in as it is: the whole sentence is escaped once, no tag of a name survives.
			$third = $kind === VereineOverpaymentRules::KIND_DONATION ? ($last !== null ? dol_print_date($last['date'], 'day') : '–') : $money($chosen['total']);
			print '<span data-overpayment-preview="'.$kind.'">'.dol_escape_htmltag($langs->transnoentities('VereineOverpaymentPreview_'.$kind,
				$money($chosen['excess']), $chosen['partner'], $third, $money($chosen['paid']))).'</span>';
			print '<br>';
			if ($blocker !== '') {
				print '<span class="opacitymedium">'.$langs->trans($blocker).'</span></td></tr>';
				continue;
			}
			print '<form method="POST" name="vereineoverpayment'.$kind.'" action="'.dol_escape_htmltag($self).'&amp;invoice='.((int) $chosen['invoice_id']).'#vereineoverpayment" class="paddingtop">';
			print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="assign">';
			print '<input type="hidden" name="kind" value="'.$kind.'">';
			if ($kind === VereineOverpaymentRules::KIND_REFUND) {
				print '<select name="bank_account" class="flat">';
				foreach ($choices['accounts'] as $accountId => $label) {
					print '<option value="'.$accountId.'">'.dol_escape_htmltag($label).'</option>';
				}
				print '</select> <select name="payment_mode" class="flat">';
				foreach ($choices['modes'] as $modeId => $label) {
					print '<option value="'.$modeId.'">'.dol_escape_htmltag($label).'</option>';
				}
				print '</select> <input type="date" name="pay_day" value="'.dol_escape_htmltag($today).'"> ';
			}
			if ($kind === VereineOverpaymentRules::KIND_DONATION) {
				print '<label><input type="checkbox" name="given_freely" value="yes"> '.$langs->trans('VereineOverpaymentGivenFreely').'</label><br>';
			}
			print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineOverpaymentDo_'.$kind)).'"></form>';
			print '</td></tr>';
		}
		print '</table></div>';
	}
}

// Every invoice paid over, with the filters of the issue: year, third party or invoice, open or all.
print load_fiche_titre($langs->trans('VereineOverpaymentList'), '', '', 0, 'vereineoverpayments');
print '<form method="GET" name="vereineoverpaymentfilter" action="'.$_SERVER['PHP_SELF'].'#vereineoverpayments" class="paddingbottom">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<select name="year" class="flat"><option value="0">'.$langs->trans('VereineOverpaymentAllYears').'</option>';
for ($option = $thisYear; $option >= $thisYear - 10; $option--) {
	print '<option value="'.$option.'"'.($option === $year ? ' selected' : '').'>'.$option.'</option>';
}
print '</select> <select name="show" class="flat">';
foreach (array('open', 'all') as $option) {
	print '<option value="'.$option.'"'.($option === $show ? ' selected' : '').'>'.$langs->trans('VereineOverpaymentShow_'.$option).'</option>';
}
print '</select> <input type="text" name="search" size="24" value="'.dol_escape_htmltag($search).'" placeholder="'.dol_escape_htmltag($langs->trans('VereineOverpaymentSearch')).'">';
print ' <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('Search')).'"></form>';

$openSum = array_sum(array_column($open, 'excess'));
print '<div class="paddingbottom" data-overpayment-open="'.count($open).'" data-overpayment-open-sum="'.number_format($openSum, 2, '.', '').'">';
print $open ? $langs->trans('VereineOverpaymentOpenSummary', count($open), $money($openSum)) : $langs->trans('VereineOverpaymentNoneOpen');
print '</div>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-overpayments="'.count($rows).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('Invoice').'</td><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('ThirdParty').'</td>';
print '<td class="right">'.$langs->trans('VereineOverpaymentInvoiceTotal').'</td><td class="right">'.$langs->trans('VereineOverpaymentPaid').'</td>';
print '<td class="right">'.$langs->trans('VereineOverpaymentExcess').'</td><td>'.$langs->trans('Status').'</td><td></td></tr>';
foreach ($rows as $row) {
	print '<tr class="oddeven" data-overpayment="'.((int) $row['invoice_id']).'" data-overpayment-amount="'.number_format($row['excess'], 2, '.', '').'"';
	print ' data-overpayment-state="'.$row['state'].'">';
	print '<td class="nowraponall">'.$invoiceLink($row).'</td><td class="nowraponall">'.vereineFormatDay($row['day']).'</td><td>'.$partnerLink($row).'</td>';
	print '<td class="right nowraponall">'.$money($row['total']).'</td><td class="right nowraponall">'.$money($row['paid']).'</td>';
	print '<td class="right nowraponall"><strong>'.$money($row['excess']).'</strong></td><td>'.$stateText($row).'</td><td class="right">';
	if ($row['state'] === VereineOverpaymentRules::STATE_OPEN) {
		print '<a class="button small" href="'.dol_escape_htmltag($self).'&amp;invoice='.((int) $row['invoice_id']).'#vereineoverpayment">'.$langs->trans('VereineOverpaymentAssign').'</a>';
	}
	print '</td></tr>';
}
if (!$rows) {
	print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium" data-overpayment-none="1">'.$langs->trans('VereineOverpaymentNone').'</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
