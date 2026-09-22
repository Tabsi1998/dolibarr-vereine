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
 * \file    admin/meetings.php
 * \ingroup vereine
 * \brief   Agenda templates per kind of meeting with standard texts and placeholders.
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
require_once __DIR__.'/../class/vereinemeetings.class.php';

$langs->loadLangs(array('admin', 'members', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$meetings = new VereineMeetings($db);

if ($action === 'savetemplates') {
	$entered = array();
	foreach ((array) GETPOST('template', 'array') as $kind => $items) {
		foreach ((array) $items as $item) {
			$entered[$kind][] = array('title' => isset($item['title']) ? (string) $item['title'] : '', 'text' => isset($item['text']) ? (string) $item['text'] : '',
				'required' => !empty($item['required']));
		}
	}
	if ($meetings->saveTemplates($entered, $user) > 0) {
		setEventMessages($langs->trans('VereineMeetingTemplatesSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	setEventMessages($meetings->error, null, 'errors');
} elseif ($action === 'resettemplates') {
	if ($meetings->saveTemplates(null, $user) > 0) {
		setEventMessages($langs->trans('VereineMeetingTemplatesReset'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	setEventMessages($meetings->error, null, 'errors');
}

$title = $langs->trans('VereineSetupTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-admin-meetings');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');
print dol_get_fiche_head(vereineAdminPrepareHead(), 'meetings', $title, -1, 'fa-landmark');

print '<div class="info" data-templates-howto="1"><ul><li>'.$langs->trans('VereineMeetingTemplatesHowTo').'</li><li>'.$langs->trans('VereineMeetingTemplatesPlaceholders').'</li></ul></div>';
dol_include_once('/vereine/class/vereineplaceholders.class.php');
vereinePlaceholderList(array('minutes', 'association'), (new VereinePlaceholders($db))->associationValues(), true);

$templates = $meetings->templates();
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinetemplates">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savetemplates">';
foreach (VereineMeetingRules::KINDS as $kind) {
	print load_fiche_titre($langs->trans('VereineMeetingKind_'.$kind), '', '');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td class="width25p">'.$langs->trans('VereineMeetingTemplateItem').'</td><td>'.$langs->trans('VereineMeetingTemplateText').'</td>';
	print '<td class="center">'.$langs->trans('VereineMeetingTemplateRequired').'</td></tr>';
	$items = $templates[$kind];
	$rows = count($items) + 3;
	for ($index = 0; $index < $rows; $index++) {
		$item = isset($items[$index]) ? $items[$index] : array('title' => '', 'text' => '', 'required' => false);
		print '<tr class="oddeven tdtop" data-template="'.$kind.'" data-item="'.$index.'" data-required="'.($item['required'] ? 1 : 0).'">';
		print '<td><input type="text" name="template['.$kind.']['.$index.'][title]" class="centpercent" maxlength="255" value="'.dol_escape_htmltag($item['title']).'"></td>';
		print '<td><textarea name="template['.$kind.']['.$index.'][text]" rows="2" class="centpercent">'.dol_escape_htmltag($item['text'], 0, 1).'</textarea></td>';
		print '<td class="center"><input type="checkbox" name="template['.$kind.']['.$index.'][required]" value="1"'.($item['required'] ? ' checked' : '').'></td></tr>';
	}
	print '</table></div><div class="opacitymedium small paddingbottom">'.$langs->trans('VereineMeetingTemplateEmpty').'</div>';
}
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Save')).'"></div>';
print '</form>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinetemplatesreset" class="center paddingtop">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="resettemplates">';
print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('VereineMeetingTemplatesResetButton')).'">';
print '</form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
