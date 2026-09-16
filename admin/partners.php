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
 * \file    admin/partners.php
 * \ingroup vereine
 * \brief   Setup of members and third parties: automatic creation, categories, customer types.
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
require_once __DIR__.'/../class/vereinepartnerservice.class.php';

$langs->loadLangs(array('admin', 'companies', 'categories', 'members', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

/**
 * Active customer types as code => label.
 *
 * @param DoliDB    $db    Database handler
 * @param Translate $langs Languages
 * @return array<string,string>
 */
function vereineCustomerTypes($db, $langs)
{
	$types = array('' => $langs->trans('VereinePartnerTypeKeep'));
	$resql = $db->query("SELECT code, libelle FROM ".MAIN_DB_PREFIX."c_typent WHERE active = 1 ORDER BY id");
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$label = $langs->trans((string) $obj->code);
		$types[(string) $obj->code] = $label !== (string) $obj->code ? $label : (string) $obj->libelle;
	}
	return $types;
}

/**
 * Categories of one type as id => label.
 *
 * @param DoliDB $db   Database handler
 * @param int    $type Dolibarr category type id (2 customer, 4 contact)
 * @return array<int,string>
 */
function vereineCategoriesOfType($db, $type)
{
	$categories = array();
	$sql = "SELECT rowid, label FROM ".MAIN_DB_PREFIX."categorie WHERE type = ".((int) $type);
	$sql .= " AND entity IN (".getEntity('category').") ORDER BY label";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$categories[(int) $obj->rowid] = (string) $obj->label;
	}
	return $categories;
}

$customerTypes = vereineCustomerTypes($db, $langs);
$customerCategories = vereineCategoriesOfType($db, VereinePartnerService::CATEGORY_CUSTOMER);
$contactCategories = vereineCategoriesOfType($db, VereinePartnerService::CATEGORY_CONTACT);


/*
 * Actions
 */

if ($action === 'update') {
	$values = array(
		'VEREINE_PARTNER_AUTOCREATE' => GETPOSTINT('VEREINE_PARTNER_AUTOCREATE') ? '1' : '0',
		'VEREINE_PARTNER_CATEGORIES' => GETPOSTINT('VEREINE_PARTNER_CATEGORIES') ? '1' : '0',
		'VEREINE_PARTNER_CATEGORY_PER_TYPE' => GETPOSTINT('VEREINE_PARTNER_CATEGORY_PER_TYPE') ? '1' : '0',
		'VEREINE_PARTNER_TYPENT_NATURAL' => GETPOST('VEREINE_PARTNER_TYPENT_NATURAL', 'aZ09'),
		'VEREINE_PARTNER_TYPENT_LEGAL' => GETPOST('VEREINE_PARTNER_TYPENT_LEGAL', 'aZ09'),
		VereinePartnerService::CONST_MEMBER => (string) GETPOSTINT(VereinePartnerService::CONST_MEMBER),
		VereinePartnerService::CONST_FORMER => (string) GETPOSTINT(VereinePartnerService::CONST_FORMER),
		VereinePartnerService::CONST_GUARDIAN => (string) GETPOSTINT(VereinePartnerService::CONST_GUARDIAN),
	);
	$errors = array();
	foreach (array('VEREINE_PARTNER_TYPENT_NATURAL', 'VEREINE_PARTNER_TYPENT_LEGAL') as $name) {
		if (!array_key_exists($values[$name], $customerTypes)) {
			$errors[] = $langs->trans('VereinePartnerErrorType');
		}
	}
	foreach (array(VereinePartnerService::CONST_MEMBER, VereinePartnerService::CONST_FORMER) as $name) {
		if (!isset($customerCategories[(int) $values[$name]])) {
			$errors[] = $langs->trans('VereinePartnerErrorCategory');
		}
	}
	if ($values[VereinePartnerService::CONST_MEMBER] === $values[VereinePartnerService::CONST_FORMER]) {
		$errors[] = $langs->trans('VereinePartnerErrorSameCategory');
	}
	if (!isset($contactCategories[(int) $values[VereinePartnerService::CONST_GUARDIAN]])) {
		$errors[] = $langs->trans('VereinePartnerErrorCategory');
	}
	if ($errors) {
		setEventMessages(null, array_unique($errors), 'errors');
	} else {
		foreach ($values as $name => $value) {
			dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity);
		}
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
} elseif ($action === 'ensurecategories') {
	$service = new VereinePartnerService($db);
	if ($service->ensureCategories($user, $langs) > 0) {
		setEventMessages($langs->trans('VereinePartnerCategoriesReady'), null, 'mesgs');
	} else {
		setEventMessages($service->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */

$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-partners');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = vereineAdminPrepareHead();
print dol_get_fiche_head($head, 'partners', $title, -1, 'fa-landmark');

print '<span class="opacitymedium">'.$langs->trans('VereinePartnerSetupIntro').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinepartnersetup" id="vereinepartnersetup">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefieldcreate">'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

foreach (array('VEREINE_PARTNER_AUTOCREATE' => '0', 'VEREINE_PARTNER_CATEGORIES' => '1', 'VEREINE_PARTNER_CATEGORY_PER_TYPE' => '0') as $name => $default) {
	print '<tr class="oddeven"><td><label for="'.$name.'">'.$langs->trans('VereineSetting_'.$name).'</label></td><td>';
	print '<input type="checkbox" id="'.$name.'" name="'.$name.'" value="1"'.(getDolGlobalString($name, $default) === '1' ? ' checked' : '').'>';
	print ' <span class="opacitymedium small">'.$langs->trans('VereineSettingHelp_'.$name).'</span></td></tr>';
}
foreach (array('VEREINE_PARTNER_TYPENT_NATURAL' => 'TE_PRIVATE', 'VEREINE_PARTNER_TYPENT_LEGAL' => '') as $name => $default) {
	print '<tr class="oddeven"><td><label for="'.$name.'">'.$langs->trans('VereineSetting_'.$name).'</label></td><td>';
	print Form::selectarray($name, $customerTypes, getDolGlobalString($name, $default), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print ' <span class="opacitymedium small">'.$langs->trans('VereineSettingHelp_VEREINE_PARTNER_TYPENT').'</span></td></tr>';
}
$categorySettings = array(
	VereinePartnerService::CONST_MEMBER => $customerCategories,
	VereinePartnerService::CONST_FORMER => $customerCategories,
	VereinePartnerService::CONST_GUARDIAN => $contactCategories,
);
foreach ($categorySettings as $name => $options) {
	print '<tr class="oddeven"><td><label for="'.$name.'">'.$langs->trans('VereineSetting_'.$name).'</label></td><td>';
	print Form::selectarray($name, $options, getDolGlobalInt($name), 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print '</td></tr>';
}
print '</table></div>';
print '<div class="center"><input type="submit" class="button button-save" name="save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form>';

print '<br><form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereineensurecategories">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="ensurecategories">';
print '<span class="opacitymedium">'.$langs->trans('VereinePartnerCategoriesHelp').'</span> ';
print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereinePartnerCategoriesButton')).'">';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
