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

$langs->loadLangs(array('admin', 'members', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
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
print '</table></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
