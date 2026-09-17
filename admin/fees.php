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
 * \file    admin/fees.php
 * \ingroup vereine
 * \brief   Fee models of the member types, explained, with what joining today would cost.
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
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/vereine.lib.php';
require_once __DIR__.'/../class/vereinefeemodel.class.php';
require_once __DIR__.'/../class/vereinefeediscountstore.class.php';
require_once __DIR__.'/../class/vereinefeefamilystore.class.php';
require_once __DIR__.'/../class/vereineexits.class.php';
require_once __DIR__.'/../class/vereinesepastore.class.php';

$langs->loadLangs(array('admin', 'members', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$discountStore = new VereineFeeDiscountStore($db);
$familyStore = new VereineFeeFamilyStore($db);
$familyModes = array(
	VereineFeeFamilies::MODE_NONE => $langs->trans('VereineFamilyMode_none'),
	VereineFeeFamilies::MODE_PERCENT => $langs->trans('VereineFamilyMode_percent'),
	VereineFeeFamilies::MODE_CAP => $langs->trans('VereineFamilyMode_cap'),
);
$familyEdit = $familyStore->setting();
$exits = new VereineExits($db);
$exitEdit = $exits->rule();
$sepaStore = new VereineSepaStore($db);
$sepaDays = (string) $sepaStore->noticeDays();
$modes = array(
	VereineFeeDiscounts::MODE_PERCENT => $langs->trans('VereineDiscountMode_percent'),
	VereineFeeDiscounts::MODE_AMOUNT => $langs->trans('VereineDiscountMode_amount'),
	VereineFeeDiscounts::MODE_FREE => $langs->trans('VereineDiscountMode_free'),
);
$kinds = array(
	VereineFeeDiscounts::KIND_AGE => $langs->trans('VereineDiscountKind_age'),
	VereineFeeDiscounts::KIND_PROOF => $langs->trans('VereineDiscountKind_proof'),
);
// What the form shows: the submitted values after a refused save, else the rule being edited.
$edit = array('id' => 0, 'label' => '', 'kind' => VereineFeeDiscounts::KIND_AGE, 'type_id' => 0, 'age_from' => '', 'age_to' => '', 'mode' => VereineFeeDiscounts::MODE_PERCENT, 'value' => '', 'active' => true);


/*
 * Actions
 */

if ($action === 'savediscount') {
	$data = array(
		'label' => GETPOST('label', 'alphanohtml'),
		'kind' => GETPOST('kind', 'aZ09'),
		'type_id' => GETPOSTINT('type_id'),
		'age_from' => GETPOST('age_from', 'alpha'),
		'age_to' => GETPOST('age_to', 'alpha'),
		'mode' => GETPOST('mode', 'aZ09'),
		'value' => price2num(GETPOST('value', 'alpha')),
		'active' => GETPOSTINT('active') ? 1 : 0,
	);
	$result = $discountStore->save($id, $data, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineDiscountSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereinediscounts');
		exit;
	}
	if ($result < 0) {
		setEventMessages($discountStore->error, null, 'errors');
	} else {
		setEventMessages(null, array_map(array($langs, 'trans'), array_unique($discountStore->errors)), 'errors');
	}
	$edit = array_merge($data, array('id' => $id, 'active' => !empty($data['active'])));
} elseif ($action === 'togglediscount' && $id > 0) {
	$current = $discountStore->fetch($id);
	if ($current) {
		$current['active'] = !$current['active'];
		if ($discountStore->save($id, $current, $user) <= 0) {
			setEventMessages($discountStore->error, array_map(array($langs, 'trans'), $discountStore->errors), 'errors');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'#vereinediscounts');
	exit;
} elseif ($action === 'editdiscount' && $id > 0) {
	$current = $discountStore->fetch($id);
	if ($current) {
		$edit = $current;
	}
} elseif ($action === 'savefamily') {
	$familyMode = GETPOST('family_mode', 'aZ09');
	$familyValue = price2num(GETPOST('family_value', 'alpha'));
	$result = $familyStore->save($familyMode, $familyValue);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineFamilySaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereinefamilies');
		exit;
	}
	if ($result < 0) {
		setEventMessages($familyStore->error, null, 'errors');
	} else {
		setEventMessages(null, array_map(array($langs, 'trans'), $familyStore->errors), 'errors');
	}
	$familyEdit = array('mode' => $familyMode, 'value' => $familyValue);
} elseif ($action === 'savesepa') {
	$sepaDays = GETPOST('sepa_notice_days', 'alpha');
	$result = $sepaStore->saveNoticeDays($sepaDays);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineSepaSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereinesepa');
		exit;
	}
	setEventMessages($result < 0 ? $sepaStore->error : $langs->trans('VereineSepaErrorDays'), null, 'errors');
} elseif ($action === 'saveexitrule') {
	$exitEdit = array('months' => GETPOST('exit_months', 'alpha'), 'at' => GETPOST('exit_at', 'aZ09'), 'start_month' => GETPOSTINT('exit_start_month'));
	$result = $exits->saveRule($exitEdit['months'], $exitEdit['at'], $exitEdit['start_month']);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineExitRuleSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'#vereineexitrule');
		exit;
	}
	if ($result < 0) {
		setEventMessages($exits->error, null, 'errors');
	} else {
		setEventMessages(null, array_map(array($langs, 'trans'), $exits->errors), 'errors');
	}
}

$feeModel = new VereineFeeModel($db);
$types = $feeModel->memberTypes();
$typesError = $feeModel->error;
$productIds = array();
foreach ($types as $type) {
	$productIds[] = $type['product_id'];
}
$products = $feeModel->products($productIds);
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');

$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-fees');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = vereineAdminPrepareHead();
print dol_get_fiche_head($head, 'fees', $title, -1, 'fa-landmark');

print '<span class="opacitymedium">'.$langs->trans('VereineFeesIntro').'</span><br><br>';
print '<div class="info" data-fees-howto="1"><strong>'.$langs->trans('VereineFeesHowToTitle').'</strong><ul>';
foreach (array('VereineFeesHowToAmount', 'VereineFeesHowToStartMonth', 'VereineFeesHowToProration', 'VereineFeesHowToAdmission', 'VereineFeesHowToProduct') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
print '</ul>'.$langs->trans('VereineFeesHowToWhere', '<a href="'.DOL_URL_ROOT.'/adherents/type.php">'.$langs->trans('VereineFeesMemberTypes').'</a>').'</div>';
print info_admin($langs->trans('VereineFeesDolibarrCard'), 0, 0, '1', '');

if ($typesError !== '') {
	print '<div class="error">'.$langs->trans('VereineFeesReadError').'</div>';
}

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('MemberType').'</td><td class="right">'.$langs->trans('VereineFeesAmount').'</td>';
print '<td>'.$langs->trans('Duration').'</td><td>'.$langs->trans('VereineFeeStartMonth').'</td><td>'.$langs->trans('VereineFeeProration').'</td>';
print '<td class="right">'.$langs->trans('VereineFeeAdmission').'</td><td>'.$langs->trans('VereineFeeProduct').'</td>';
print '<td>'.$langs->trans('VereineFeesExample', vereineFormatDay($today)).'</td><td></td></tr>';
if (!$types && $typesError === '') {
	print '<tr class="oddeven"><td colspan="9"><span class="opacitymedium">'.$langs->trans('VereineFeesNoTypes').'</span></td></tr>';
}
$units = array('y' => 'Year', 'm' => 'Month', 'w' => 'Week', 'd' => 'Day');
foreach ($types as $type) {
	$model = $type['model'];
	print '<tr class="oddeven" data-fee-type="'.((int) $type['id']).'">';
	print '<td>'.dol_escape_htmltag($type['label']);
	if (!$type['active']) {
		print ' '.dolGetBadge($langs->trans('Disabled'), '', 'secondary');
	}
	print '</td>';
	if (!$type['subscription']) {
		print '<td class="right" colspan="7" data-fee-example="none"><span class="opacitymedium">'.$langs->trans('VereineFeesNoSubscription').'</span></td>';
	} else {
		print '<td class="right nowraponall">'.($model['amount'] === null ? '<span class="warning">'.$langs->trans('VereineFeesNoAmount').'</span>' : price($model['amount'], 0, $langs, 1, -1, -1, $conf->currency)).'</td>';
		print '<td class="nowraponall">'.$model['duration_value'].' '.$langs->trans($units[$model['duration_unit']]).'</td>';
		print '<td>'.($model['start_month'] > 0 ? $langs->trans(VereineFeeModel::MONTHS[$model['start_month']]) : $langs->trans('VereineFeeStartsWithJoining')).'</td>';
		print '<td data-fee-proration="'.$model['proration'].'">'.$langs->trans(VereineFeeModel::PRORATIONS[$model['proration']]).'</td>';
		print '<td class="right nowraponall">'.($model['admission_fee'] > 0 ? price($model['admission_fee'], 0, $langs, 1, -1, -1, $conf->currency) : '').'</td>';
		print '<td>';
		if ($type['product_id'] > 0 && isset($products[$type['product_id']])) {
			$product = $products[$type['product_id']];
			print dol_escape_htmltag($product['ref'].' - '.$product['label']);
			print $type['product_own'] ? '' : ' <span class="opacitymedium small">('.$langs->trans('VereineFeeProductDefault').')</span>';
			print '<br><span class="small">'.($product['taxprofile'] !== '' ? $langs->trans('VereineFeeProductTaxProfile', dol_escape_htmltag($product['taxprofile'])) : '<span class="warning">'.$langs->trans('VereineFeeProductNoTaxProfile').'</span>').'</span>';
		} else {
			print '<span class="warning">'.$langs->trans('VereineFeeNoProduct').'</span>';
		}
		print '</td>';
		$fee = VereineFeeRules::nextFee($model, $today, '');
		print '<td data-fee-example="'.dol_escape_htmltag($fee['reason']).'" data-fee-start="'.$fee['start'].'" data-fee-end="'.$fee['end'].'"';
		print ' data-fee-amount="'.($fee['amount'] === null ? '' : price2num($fee['amount'], 'MT')).'" data-fee-admission="'.price2num($fee['admission_fee'], 'MT').'">';
		print $langs->trans('VereineFeesPeriod', vereineFormatDay($fee['start']), vereineFormatDay($fee['end']));
		if ($fee['amount'] !== null) {
			print ': <strong>'.price($fee['amount'], 0, $langs, 1, -1, -1, $conf->currency).'</strong>';
		}
		$reason = vereineFeeReason($fee);
		if ($reason !== '') {
			print ' <span class="opacitymedium">('.$reason.')</span>';
		}
		if ($fee['admission_fee'] > 0) {
			print '<br>'.$langs->trans('VereineFeesPlusAdmission', price($fee['admission_fee'], 0, $langs, 1, -1, -1, $conf->currency));
		}
		print '</td>';
	}
	print '<td class="right"><a class="editfielda" href="'.DOL_URL_ROOT.'/adherents/type.php?rowid='.((int) $type['id']).'&amp;action=edit&amp;token='.newToken().'" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Modify')).'">'.img_edit().'</a></td>';
	print '</tr>';
}
print '</table></div><br>';

// Discounts: by age on the first day of a period or with a proof; exemptions are set on the member.
print load_fiche_titre($langs->trans('VereineDiscountsTitle'), '', '', 0, 'vereinediscounts');
print '<div class="info" data-discounts-howto="1"><ul>';
foreach (array('VereineDiscountsHowToOrder', 'VereineDiscountsHowToAge', 'VereineDiscountsHowToProof', 'VereineDiscountsHowToExempt') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
print '</ul></div>';
$typeNames = array(0 => $langs->trans('VereineDiscountAllTypes'));
foreach ($types as $type) {
	$typeNames[$type['id']] = $type['label'];
}
$rules = $discountStore->fetchAll();
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineDiscountLabel').'</td><td>'.$langs->trans('VereineDiscountKind').'</td><td>'.$langs->trans('MemberType').'</td>';
print '<td>'.$langs->trans('VereineDiscountValue').'</td><td class="center">'.$langs->trans('Status').'</td><td></td></tr>';
if (!$rules) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('VereineDiscountsNone').'</span></td></tr>';
}
foreach ($rules as $rule) {
	print '<tr class="oddeven" data-discount="'.((int) $rule['id']).'" data-active="'.($rule['active'] ? 1 : 0).'">';
	print '<td>'.dol_escape_htmltag($rule['label']).'</td><td>'.$kinds[$rule['kind']];
	if ($rule['kind'] === VereineFeeDiscounts::KIND_AGE) {
		print '<br><span class="opacitymedium small">'.vereineDiscountAges($rule).'</span>';
	}
	print '</td><td>'.dol_escape_htmltag(isset($typeNames[$rule['type_id']]) ? $typeNames[$rule['type_id']] : '#'.$rule['type_id']).'</td>';
	print '<td class="nowraponall">'.vereineDiscountValue($rule).'</td>';
	print '<td class="center">'.dolGetBadge($langs->trans($rule['active'] ? 'Enabled' : 'Disabled'), '', $rule['active'] ? 'success' : 'secondary').'</td>';
	print '<td class="right nowraponall">';
	print '<a class="editfielda paddingright" href="'.$_SERVER['PHP_SELF'].'?action=editdiscount&amp;id='.((int) $rule['id']).'&amp;token='.newToken().'#vereinediscount" title="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Modify')).'">'.img_edit().'</a>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block" name="vereinediscounttoggle">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="togglediscount">';
	print '<input type="hidden" name="id" value="'.((int) $rule['id']).'">';
	print '<button type="submit" class="button small">'.$langs->trans($rule['active'] ? 'VereineTaxDeactivate' : 'VereineTaxActivate').'</button>';
	print '</form></td></tr>';
}
print '</table></div><br>';

print load_fiche_titre($langs->trans($edit['id'] > 0 ? 'VereineDiscountEdit' : 'VereineDiscountNew'), '', '');
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinediscount" id="vereinediscount">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savediscount">';
print '<input type="hidden" name="id" value="'.((int) $edit['id']).'">';
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate fieldrequired"><label for="label">'.$langs->trans('VereineDiscountLabel').'</label></td>';
print '<td><input type="text" id="label" name="label" class="minwidth300" maxlength="'.VereineFeeDiscounts::LABEL_MAX.'" value="'.dol_escape_htmltag($edit['label']).'"></td></tr>';
print '<tr><td class="fieldrequired"><label for="kind">'.$langs->trans('VereineDiscountKind').'</label></td>';
print '<td>'.Form::selectarray('kind', $kinds, $edit['kind'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';
print '<tr><td><label for="age_from">'.$langs->trans('VereineDiscountAges').'</label></td><td>';
print '<input type="number" min="0" max="150" id="age_from" name="age_from" class="width50" value="'.dol_escape_htmltag((string) $edit['age_from']).'"> - ';
print '<input type="number" min="0" max="150" id="age_to" name="age_to" class="width50" value="'.dol_escape_htmltag((string) $edit['age_to']).'">';
print ' <span class="opacitymedium small">'.$langs->trans('VereineDiscountAgesHelp').'</span></td></tr>';
print '<tr><td><label for="type_id">'.$langs->trans('MemberType').'</label></td>';
print '<td>'.Form::selectarray('type_id', $typeNames, $edit['type_id'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';
print '<tr><td class="fieldrequired"><label for="mode">'.$langs->trans('VereineDiscountMode').'</label></td>';
print '<td>'.Form::selectarray('mode', $modes, $edit['mode'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
print ' <input type="text" id="value" name="value" class="width75" value="'.dol_escape_htmltag($edit['value'] === '' ? '' : price2num($edit['value'])).'">';
print ' <span class="opacitymedium small">'.$langs->trans('VereineDiscountValueHelp').'</span></td></tr>';
print '<tr><td><label for="active">'.$langs->trans('Enabled').'</label></td>';
print '<td><input type="checkbox" id="active" name="active" value="1"'.(!empty($edit['active']) ? ' checked' : '').'></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'">';
if ($edit['id'] > 0) {
	print ' <a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'#vereinediscounts">'.$langs->trans('Cancel').'</a>';
}
print '</div></form><br>';

// Families: members with the same payer, one invoice to the payer, and the family rule.
print load_fiche_titre($langs->trans('VereineFamilyTitle'), '', '', 0, 'vereinefamilies');
print '<div class="info" data-families-howto="1"><ul>';
foreach (array('VereineFamilyHowToPayer', 'VereineFamilyHowToInvoice', 'VereineFamilyHowToPercent', 'VereineFamilyHowToCap', 'VereineFamilyHowToOrder') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
print '</ul></div>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinefamily" id="vereinefamily">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savefamily">';
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate"><label for="family_mode">'.$langs->trans('VereineFamilyMode').'</label></td>';
print '<td>'.Form::selectarray('family_mode', $familyModes, $familyEdit['mode'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print ' <input type="text" id="family_value" name="family_value" class="width75" value="'.dol_escape_htmltag($familyEdit['mode'] === VereineFeeFamilies::MODE_NONE ? '' : price2num($familyEdit['value'])).'">';
print ' <span class="opacitymedium small">'.$langs->trans('VereineFamilyValueHelp').'</span></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div>';
print '</form><br>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-families="1">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereineFamilyPayer').'</td><td>'.$langs->trans('VereineFamilyMembers').'</td></tr>';
$families = $familyStore->families();
if (!$families) {
	print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">'.$langs->trans('VereineFamilyNone').'</span></td></tr>';
}
foreach ($families as $family) {
	print '<tr class="oddeven" data-family="'.((int) $family['socid']).'" data-members="'.count($family['members']).'"><td>';
	if ($family['exists']) {
		print '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $family['socid']).'">'.dol_escape_htmltag($family['name']).'</a>';
	} else {
		print '<span class="warning">'.$langs->trans('VereineFamilyPayerMissing').'</span>';
	}
	print '</td><td>';
	$links = array();
	foreach ($family['members'] as $member) {
		$links[] = '<a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.((int) $member['id']).'">'.dol_escape_htmltag($member['name']).'</a>';
	}
	print implode(', ', $links).'</td></tr>';
}
print '</table></div><br>';

// SEPA direct debit: Dolibarr's own module does the orders; the fee run requests them.
print load_fiche_titre($langs->trans('VereineSepaTitle'), '', '', 0, 'vereinesepa');
print '<div class="info" data-sepa-howto="1"><ul>';
foreach (array('VereineSepaHowToModule', 'VereineSepaHowToMandate', 'VereineSepaHowToExpiry', 'VereineSepaHowToNotice', 'VereineSepaHowToOrder') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
print '</ul></div>';
if (!VereineSepaStore::enabled()) {
	print '<div class="warning" data-sepa-module="off">'.$langs->trans('VereineSepaModuleOff').'</div>';
} elseif (getDolGlobalString('PRELEVEMENT_ICS') === '') {
	print '<div class="warning" data-sepa-ics="missing">'.$langs->trans('VereineSepaNoCreditorId').'</div>';
}
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinesepa">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savesepa">';
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate fieldrequired"><label for="sepa_notice_days">'.$langs->trans('VereineSepaNoticeDays').'</label></td>';
print '<td><input type="number" min="1" max="'.VereineSepa::MAX_NOTICE_DAYS.'" id="sepa_notice_days" name="sepa_notice_days" class="width50" value="'.dol_escape_htmltag($sepaDays).'">';
print ' <span class="opacitymedium small">'.$langs->trans('VereineSepaNoticeDaysHelp').'</span></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div>';
print '</form><br>';

// Exit: the notice period of the statutes.
print load_fiche_titre($langs->trans('VereineExitRuleTitle'), '', '', 0, 'vereineexitrule');
print '<div class="info" data-exit-howto="1"><ul>';
foreach (array('VereineExitHowToRule', 'VereineExitHowToMember', 'VereineExitHowToCron', 'VereineExitHowToFees') as $line) {
	print '<li>'.$langs->trans($line).'</li>';
}
print '</ul></div>';
$atOptions = array();
foreach (VereineExitRules::ATS as $at) {
	$atOptions[$at] = $langs->trans('VereineExitAt_'.$at);
}
$monthOptions = array();
foreach (VereineFeeModel::MONTHS as $number => $monthKey) {
	$monthOptions[$number] = $langs->trans($monthKey);
}
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereineexitrule">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="saveexitrule">';
print '<table class="border centpercent">';
print '<tr><td class="titlefieldcreate fieldrequired"><label for="exit_months">'.$langs->trans('VereineExitMonths').'</label></td>';
print '<td><input type="number" min="0" max="'.VereineExitRules::MAX_MONTHS.'" id="exit_months" name="exit_months" class="width50" value="'.dol_escape_htmltag((string) $exitEdit['months']).'"></td></tr>';
print '<tr><td><label for="exit_at">'.$langs->trans('VereineExitAt').'</label></td><td>'.Form::selectarray('exit_at', $atOptions, $exitEdit['at'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';
print '<tr><td><label for="exit_start_month">'.$langs->trans('VereineExitStartMonth').'</label></td><td>'.Form::selectarray('exit_start_month', $monthOptions, $exitEdit['start_month'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
