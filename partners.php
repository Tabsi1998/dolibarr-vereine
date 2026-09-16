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
 * \file    partners.php
 * \ingroup vereine
 * \brief   Members and third parties: what is not linked or not consistent, and how to fix it.
 *
 * Every change is shown as a preview first and only runs after confirmation.
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

require_once __DIR__.'/class/vereinepartnerservice.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('members', 'companies', 'categories', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read') || !$user->hasRight('adherent', 'lire') || !$user->hasRight('societe', 'lire')) {
	accessforbidden();
}
$canWrite = $user->hasRight('vereine', 'partner', 'write') && $user->hasRight('societe', 'creer') && $user->hasRight('adherent', 'creer');

$action = GETPOST('action', 'aZ09');
$op = GETPOST('op', 'aZ09');
// The confirmation form sends sel[]; each section of the page has its own sel_<op>[],
// so ticks in one section never reach another section's button.
$selection = $action === 'confirm' ? 'sel' : 'sel_'.$op;
$ids = array_values(array_filter(array_map('intval', (array) GETPOST($selection, 'array'))));
$force = GETPOSTINT('force');
$link = GETPOST('link', 'alphanohtml');

$operations = array('create', 'attributes', 'copy', 'orphans');
$service = new VereinePartnerService($db);


/*
 * Actions
 */

if (($link !== '' || $force > 0 || in_array($op, $operations, true) || $action === 'confirm') && !$canWrite) {
	accessforbidden();
}

$preview = null;
if ($link !== '' && preg_match('/^(\d+)_(\d+)$/', $link, $parts)) {
	$member = new Adherent($db);
	if ($member->fetch((int) $parts[1]) > 0 && $service->linkPartner($member, (int) $parts[2], $user) > 0) {
		setEventMessages($langs->trans('VereinePartnerLinkedMessage', $member->ref), null, 'mesgs');
	} else {
		setEventMessages($service->error ?: $member->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
} elseif ($force > 0) {
	$preview = array('op' => 'create', 'ids' => array($force), 'force' => 1);
} elseif ($action !== 'confirm' && in_array($op, $operations, true)) {
	if (!$ids) {
		setEventMessages($langs->trans('VereinePartnerNothingSelected'), null, 'warnings');
	} else {
		$preview = array('op' => $op, 'ids' => $ids, 'force' => 0);
	}
} elseif ($action === 'confirm' && in_array($op, $operations, true) && $ids) {
	$done = 0;
	$skipped = 0;
	$errors = array();
	$report = $op === 'copy' ? $service->report(dol_print_date(dol_now(), '%Y-%m-%d')) : null;
	foreach ($ids as $id) {
		if ($op === 'orphans') {
			$result = $service->releaseOrphan($id, $user);
			if ($result < 0) {
				$errors[] = $service->error;
			} elseif ($result > 0) {
				$done++;
			} else {
				$skipped++;
			}
			continue;
		}
		$member = new Adherent($db);
		if ($member->fetch($id) <= 0) {
			$errors[] = 'Member '.$id.' not found';
			continue;
		}
		if ($op === 'create') {
			$candidates = VereinePartnerRules::candidates($service->memberData($member), $service->partners());
			if ((int) $member->fk_soc > 0 || ($candidates && !$force)) {
				$skipped++;
				continue;
			}
			$result = $service->createPartner($member, $user);
		} elseif ($op === 'attributes') {
			$result = $service->applyAttributes($member, $user);
			$result = is_array($result) ? 1 : -1;
		} else {
			$fields = array();
			foreach ($report['differences'] as $difference) {
				if ($difference['member']['id'] === (int) $member->id) {
					$fields = array_keys($difference['fields']);
				}
			}
			$result = $fields ? $service->copyToPartner($member, $fields, $user) : 0;
			if ($result === 0) {
				$skipped++;
				continue;
			}
		}
		if ($result < 0) {
			$errors[] = $member->ref.': '.$service->error;
		} else {
			$done++;
		}
	}
	setEventMessages($langs->trans('VereinePartnerBulkResult', $done, $skipped), null, $errors ? 'warnings' : 'mesgs');
	if ($errors) {
		setEventMessages(null, $errors, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */

$report = $service->report(dol_print_date(dol_now(), '%Y-%m-%d'));

$title = $langs->trans('VereinePartnersTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-partners');
print load_fiche_titre($title, '', 'fa-landmark');
print '<span class="opacitymedium">'.$langs->trans('VereinePartnersIntro').'</span><br><br>';

/**
 * Link to a member.
 *
 * @param DoliDB               $db     Database handler
 * @param array<string,mixed> $member Member data from the report
 * @return string HTML
 */
function vereineMemberLink($db, array $member)
{
	$object = new Adherent($db);
	$object->id = (int) $member['id'];
	$object->ref = (string) $member['ref'];
	$object->firstname = (string) $member['firstname'];
	$object->lastname = (string) $member['lastname'];
	$object->company = (string) $member['company'];
	$object->morphy = (string) $member['morphy'];
	$object->statut = (int) $member['status'];
	return $object->getNomUrl(1);
}

/**
 * Link to a third party.
 *
 * @param DoliDB               $db      Database handler
 * @param array<string,mixed> $partner Third party data from the report
 * @return string HTML
 */
function vereinePartnerLink($db, array $partner)
{
	$object = new Societe($db);
	$object->id = (int) $partner['id'];
	$object->name = (string) $partner['name'];
	$object->client = (int) $partner['client'];
	return $object->getNomUrl(1);
}

if ($preview) {
	print '<div class="info" data-preview="'.dol_escape_htmltag($preview['op']).'">';
	print '<b>'.$langs->trans('VereinePartnerPreview'.ucfirst($preview['op'])).'</b><br><br>';
	print '<table class="noborder centpercent">';
	$byMember = array();
	foreach (array('without_partner', 'minors') as $section) {
		foreach ($report[$section] as $row) {
			$byMember[$row['id']] = $row;
		}
	}
	foreach (array('attributes', 'differences') as $section) {
		foreach ($report[$section] as $row) {
			$byMember[$row['member']['id']] = isset($byMember[$row['member']['id']]) ? $byMember[$row['member']['id']] : $row['member'];
			$byMember[$row['member']['id']]['_'.$section] = $row;
		}
	}
	$orphans = array();
	foreach ($report['orphans'] as $row) {
		$orphans[$row['partner']['id']] = $row;
	}
	foreach ($preview['ids'] as $id) {
		if (($preview['op'] === 'orphans' && !isset($orphans[$id])) || ($preview['op'] !== 'orphans' && !isset($byMember[$id]))) {
			continue;
		}
		print '<tr class="oddeven" data-preview-row="'.((int) $id).'">';
		if ($preview['op'] === 'orphans') {
			print '<td>'.vereinePartnerLink($db, $orphans[$id]['partner']).'</td><td>'.$langs->trans('VereinePartnerPlanOrphan').'</td>';
		} else {
			$row = $byMember[$id];
			print '<td>'.vereineMemberLink($db, $row).'</td><td>';
			if ($preview['op'] === 'create') {
				if (!empty($row['candidates']) && !$preview['force']) {
					print '<span class="warning">'.$langs->trans('VereinePartnerPlanSkipCandidates').'</span>';
				} else {
					print $langs->trans('VereinePartnerPlanCreate', VereinePartnerRules::partnerName($row));
				}
			} elseif ($preview['op'] === 'attributes' && isset($row['_attributes'])) {
				print dol_escape_htmltag(implode(', ', array_map(array($langs, 'trans'), $row['_attributes']['problems'])));
			} elseif ($preview['op'] === 'copy' && isset($row['_differences'])) {
				foreach ($row['_differences']['fields'] as $field => $values) {
					print dol_escape_htmltag($langs->trans('VereineField_'.$field).': '.$values['partner'].' → '.$values['member']).'<br>';
				}
			}
			print '</td>';
		}
		print '</tr>';
	}
	print '</table><br>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinepreview">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="confirm">';
	print '<input type="hidden" name="op" value="'.dol_escape_htmltag($preview['op']).'">';
	print '<input type="hidden" name="force" value="'.((int) $preview['force']).'">';
	foreach ($preview['ids'] as $id) {
		print '<input type="hidden" name="sel[]" value="'.((int) $id).'">';
	}
	print '<input type="submit" class="button" name="confirmbutton" value="'.dol_escape_htmltag($langs->trans('Confirm')).'"> ';
	print '<a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Cancel').'</a>';
	print '</form>';
	print '</div><br>';
}

$sections = array(
	'without_partner' => array('op' => 'create', 'columns' => array('Member', 'Type', 'Status', 'Email', 'VereinePartnerCandidates')),
	'attributes' => array('op' => 'attributes', 'columns' => array('Member', 'ThirdParty', 'VereinePartnerProblems')),
	'differences' => array('op' => 'copy', 'columns' => array('Member', 'ThirdParty', 'VereinePartnerDifferencesColumn')),
	'orphans' => array('op' => 'orphans', 'columns' => array('ThirdParty', 'Member')),
	'minors' => array('op' => '', 'columns' => array('Member', 'ThirdParty', 'VereineGuardians')),
	'duplicates' => array('op' => '', 'columns' => array('Member', 'VereinePartnerDuplicatesColumn')),
);

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="vereinepartners">';
print '<input type="hidden" name="token" value="'.newToken().'">';

foreach ($sections as $section => $definition) {
	$rows = $report[$section];
	$badge = dolGetBadge((string) count($rows), '', count($rows) ? 'warning' : 'success');
	print '<div data-section="'.$section.'" data-count="'.count($rows).'">';
	print load_fiche_titre($langs->trans('VereinePartnerSection_'.$section).' '.$badge, '', '');
	print '<div class="opacitymedium small">'.$langs->trans('VereinePartnerSectionHelp_'.$section).'</div>';
	if (!$rows) {
		print '<div class="opacitymedium">'.$langs->trans('VereinePartnerSectionEmpty').'</div><br>';
		print '</div>';
		continue;
	}
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	if ($definition['op'] && $canWrite) {
		print '<td class="width20"></td>';
	}
	foreach ($definition['columns'] as $column) {
		print '<td>'.$langs->trans($column).'</td>';
	}
	print '</tr>';

	foreach ($rows as $row) {
		$member = isset($row['member']) ? $row['member'] : ($section === 'orphans' ? null : $row);
		$partner = isset($row['partner']) ? $row['partner'] : null;
		$selectId = $section === 'orphans' ? (int) $partner['id'] : (int) $member['id'];
		print '<tr class="oddeven" data-row="'.$selectId.'">';
		if ($definition['op'] && $canWrite) {
			$disabled = $section === 'without_partner' && !empty($row['candidates']);
			print '<td><input type="checkbox" name="sel_'.$definition['op'].'[]" value="'.$selectId.'"'.($disabled ? ' disabled' : '').'></td>';
		}
		switch ($section) {
			case 'without_partner':
				$status = new Adherent($db);
				print '<td>'.vereineMemberLink($db, $row).'</td><td>'.dol_escape_htmltag($row['type']).'</td>';
				print '<td>'.$status->LibStatut($row['status'], 0, 0, 1).'</td><td>'.dol_escape_htmltag($row['email']).'</td><td>';
				foreach ($row['candidates'] as $candidate) {
					print '<div>'.dol_escape_htmltag($candidate['name']).' <span class="opacitymedium">('.$langs->trans('VereinePartnerMatch_'.$candidate['match']).')</span> ';
					if ($canWrite) {
						print '<button type="submit" class="button small" name="link" value="'.((int) $row['id']).'_'.((int) $candidate['id']).'">'.$langs->trans('VereinePartnerLinkButton').'</button>';
					}
					print '</div>';
				}
				if ($row['candidates'] && $canWrite) {
					print '<button type="submit" class="button small button-cancel" name="force" value="'.((int) $row['id']).'">'.$langs->trans('VereinePartnerCreateAnyway').'</button>';
				}
				print '</td>';
				break;
			case 'attributes':
				print '<td>'.vereineMemberLink($db, $member).'</td><td>'.vereinePartnerLink($db, $partner).'</td><td>';
				print dol_escape_htmltag(implode(', ', array_map(array($langs, 'trans'), $row['problems'])));
				if ($row['type_mismatch']) {
					print ($row['problems'] ? '<br>' : '').'<span class="opacitymedium">'.$langs->trans('VereinePartnerTypeMismatchShort', $partner['typent_code']).'</span>';
				}
				print '</td>';
				break;
			case 'differences':
				print '<td>'.vereineMemberLink($db, $member).'</td><td>'.vereinePartnerLink($db, $partner).'</td><td>';
				foreach ($row['fields'] as $field => $values) {
					print '<div>'.$langs->trans('VereineField_'.$field).': <span class="opacitymedium">'.dol_escape_htmltag($values['partner']).'</span> → '.dol_escape_htmltag($values['member']).'</div>';
				}
				print '</td>';
				break;
			case 'orphans':
				print '<td>'.vereinePartnerLink($db, $partner).'</td><td>'.($member ? vereineMemberLink($db, $member) : '<span class="opacitymedium">'.$langs->trans('None').'</span>').'</td>';
				break;
			case 'minors':
				print '<td>'.vereineMemberLink($db, $row).' <span class="opacitymedium">'.dol_escape_htmltag($row['birth']).'</span></td><td>';
				if ($row['fk_soc'] > 0) {
					print '<a href="'.DOL_URL_ROOT.'/contact/card.php?action=create&amp;socid='.((int) $row['fk_soc']).'">'.$langs->trans('VereineGuardianAdd').'</a>';
				} else {
					print '<span class="opacitymedium">'.$langs->trans('VereinePartnerNeedsPartnerFirst').'</span>';
				}
				print '</td><td><span class="opacitymedium">'.$langs->trans('VereineGuardiansNone').'</span></td>';
				break;
			case 'duplicates':
				print '<td>'.vereineMemberLink($db, $member).'</td><td>';
				foreach ($row['partners'] as $candidate) {
					print '<div>'.vereinePartnerLink($db, $candidate).'</div>';
				}
				print '</td>';
				break;
		}
		print '</tr>';
	}
	print '</table></div>';
	if ($definition['op'] && $canWrite) {
		print '<div class="right"><button type="submit" class="button" name="op" value="'.$definition['op'].'">'.$langs->trans('VereinePartnerPreviewButton_'.$definition['op']).'</button></div>';
	}
	print '<br></div>';
}
print '</form>';

print info_admin($langs->trans('VereinePartnersFooter'), 0, 0, '1', '');

llxFooter();
$db->close();
