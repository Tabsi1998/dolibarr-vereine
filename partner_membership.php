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
 * \file    partner_membership.php
 * \ingroup vereine
 * \brief   Tab "Membership" on a third party: the linked member, open invoices, guardians, log.
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once __DIR__.'/class/vereinepartnerservice.class.php';
require_once __DIR__.'/class/vereinepartnerimages.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('companies', 'members', 'bills', 'categories', 'vereine@vereine'));

$socid = GETPOSTINT('socid');
$action = GETPOST('action', 'aZ09');

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('vereine', 'association', 'read') || !$user->hasRight('adherent', 'lire')) {
	accessforbidden();
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
$result = restrictedArea($user, 'societe', $socid, '&societe');

$object = new Societe($db);
if ($socid <= 0 || $object->fetch($socid) <= 0) {
	accessforbidden('Third party not found');
}
$service = new VereinePartnerService($db);
$memberId = $service->memberOfPartner((int) $object->id);
$member = null;
if ($memberId > 0) {
	$member = new Adherent($db);
	$member->fetch($memberId);
}
$canWrite = $user->hasRight('vereine', 'partner', 'write') && $user->hasRight('societe', 'creer');
$canImages = $user->hasRight('societe', 'creer');
$partnerImages = new VereinePartnerImages($db);


/*
 * Actions
 */

if ($action === 'apply' && $canWrite && $member) {
	$result = $service->applyAttributes($member, $user);
	if (is_array($result)) {
		setEventMessages($result['changes'] ? $langs->trans('VereinePartnerApplied', implode(', ', $result['changes'])) : $langs->trans('VereinePartnerNothingToApply'), null, 'mesgs');
		if ($result['mismatch']) {
			setEventMessages($langs->trans('VereinePartnerTypeMismatch'), null, 'warnings');
		}
	} else {
		setEventMessages($service->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?socid='.((int) $object->id));
	exit;
}


// Pictures for the website (#278): a logo and a banner, each for a light and for a dark background.
if (($action === 'partnerimage' || $action === 'partnerimageremove') && $canImages) {
	$kind = GETPOST('kind', 'aZ09');
	$variant = GETPOST('variant', 'aZ09');
	$result = $action === 'partnerimage' ? $partnerImages->upload((int) $object->id, $kind, $variant, isset($_FILES['image']) ? (array) $_FILES['image'] : array(), $user)
		: $partnerImages->remove((int) $object->id, $kind, $variant, $user);
	if ($result < 0) {
		setEventMessages($partnerImages->error, null, 'errors');
	} elseif ($result === 0 && $partnerImages->errors) {
		setEventMessages(null, array_map(function ($code) use ($langs) {
			return $langs->trans('VereinePartnerImageError_'.$code);
		}, $partnerImages->errors), 'errors');
	} else {
		header('Location: '.$_SERVER['PHP_SELF'].'?socid='.((int) $object->id).'#vereinepartnerimages');
		exit;
	}
}


/*
 * View
 */

$title = $langs->trans('VereineTabMembership').' - '.$object->name;
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-partner-membership');

$head = societe_prepare_head($object);
print dol_get_fiche_head($head, 'vereinemembership', $langs->trans('ThirdParty'), -1, 'company');
$linkback = '<a href="'.DOL_URL_ROOT.'/societe/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
dol_banner_tab($object, 'socid', $linkback, ($user->socid ? 0 : 1), 'rowid', 'nom');

print '<div class="fichecenter"><div class="underbanner clearboth"></div>';

if (!$member) {
	print '<div class="opacitymedium" data-membership="none">'.$langs->trans('VereinePartnerNoMember').'</div>';
	if ($user->hasRight('societe', 'lire')) {
		print '<br><a href="'.dol_buildpath('/vereine/partners.php', 1).'">'.$langs->trans('VereinePartnerOpenReconciliation').'</a>';
	}
} else {
	$member->fetch_subscriptions();
	$since = !empty($member->first_subscription_date_start) ? $member->first_subscription_date_start : $member->datevalid;
	$categories = new Categorie($db);
	$labels = $categories->containing((int) $object->id, 'customer', 'label');

	print '<div class="div-table-responsive-no-min">';
	print '<table class="border centpercent tableforfield" data-membership="'.((int) $member->id).'">';
	print '<tr><td class="titlefield">'.$langs->trans('MemberRef').'</td><td>'.$member->getNomUrl(1).'</td></tr>';
	print '<tr><td>'.$langs->trans('Type').'</td><td>'.dol_escape_htmltag((string) $member->type).'</td></tr>';
	print '<tr><td>'.$langs->trans('Status').'</td><td>'.$member->getLibStatut(4).'</td></tr>';
	print '<tr><td>'.$langs->trans('VereineMemberSince').'</td><td>'.($since ? dol_print_date($since, 'day') : '').'</td></tr>';
	print '<tr><td>'.$langs->trans('VereinePaidUntil').'</td><td>'.($member->datefin ? dol_print_date($member->datefin, 'day') : '<span class="opacitymedium">'.$langs->trans('VereineNotSet').'</span>').'</td></tr>';
	print '<tr><td>'.$langs->trans('Categories').'</td><td>'.dol_escape_htmltag(is_array($labels) ? implode(', ', $labels) : '').'</td></tr>';
	print '</table>';
	print '</div>';

	if ($canWrite) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?socid='.((int) $object->id).'" name="vereineapply">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="apply">';
		print '<div class="right"><input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereinePartnerApply')).'"></div>';
		print '</form>';
	}

	vereinePrintOpenInvoices($db, (int) $object->id);
	vereinePrintGuardians($db, (int) $object->id);
}

// Pictures for the website: shown on the background they are meant for, so a wrong one is seen at once (#278).
$onSite = $partnerImages->onSite((int) $object->id);
$images = $partnerImages->images((int) $object->id);
print '<br>'.load_fiche_titre($langs->trans('VereinePartnerImagesTitle'), '', '', 0, 'vereinepartnerimages');
print '<div class="paddingbottom" data-partner-site="'.($onSite !== null ? 1 : 0).'">';
if ($onSite !== null) {
	print $langs->trans('VereinePartnerImagesOnSite').' '.dol_escape_htmltag(implode(', ', array_column($onSite, 'label')));
} else {
	print '<span class="opacitymedium">'.$langs->trans('VereinePartnerImagesOffSite').'</span>';
}
print '</div>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-partner-images="'.count($images).'">';
print '<tr class="liste_titre"><td>'.$langs->trans('VereinePartnerImageSlot').'</td><td>'.$langs->trans('VereinePartnerImagePreview').'</td><td>'.$langs->trans('VereinePartnerImageInfo').'</td><td></td></tr>';
foreach (VereinePartnerImageRules::KINDS as $kind) {
	foreach (VereinePartnerImageRules::VARIANTS as $variant) {
		$image = isset($images[$kind.'-'.$variant]) ? $images[$kind.'-'.$variant] : null;
		print '<tr class="oddeven" data-partner-image="'.$kind.'-'.$variant.'" data-partner-image-source="'.($image !== null ? $image['source'] : '').'">';
		print '<td>'.$langs->trans('VereinePartnerImage_'.$kind.'_'.$variant).'</td>';
		print '<td><div style="display: inline-block; padding: 8px; border-radius: 4px; background: '.($variant === 'dark' ? '#1e1e1e' : '#ffffff').'; border: 1px solid #ccc;">';
		if ($image !== null) {
			$src = DOL_URL_ROOT.'/viewimage.php?modulepart=societe&entity='.((int) $conf->entity).'&file='.urlencode(VereinePartnerImages::relativePath((int) $object->id, $image));
			print '<img src="'.dol_escape_htmltag($src).'" alt="" style="max-height: 60px; max-width: 220px;">';
		} else {
			print '<span class="opacitymedium" style="color: '.($variant === 'dark' ? '#bbbbbb' : '#777777').';">–</span>';
		}
		print '</div></td><td class="small">';
		if ($image !== null) {
			print dol_escape_htmltag(strtoupper(VereinePartnerImageRules::TYPES[$image['content_type']]).' · '.$image['width'].' × '.$image['height'].' px · '.dol_print_size($image['size']));
			if ($image['source'] === 'dolibarr') {
				print '<br><span class="opacitymedium">'.$langs->trans('VereinePartnerImageFromCard').'</span>';
			}
		}
		print '</td><td class="right nowraponall">';
		if ($canImages) {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?socid='.((int) $object->id).'" name="vereinepartnerimage'.$kind.$variant.'" enctype="multipart/form-data" class="inline-block">';
			print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="partnerimage">';
			print '<input type="hidden" name="kind" value="'.$kind.'"><input type="hidden" name="variant" value="'.$variant.'">';
			print '<input type="file" name="image" accept="image/png,image/jpeg,image/webp"> <input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereinePartnerImageUpload')).'"></form>';
			if ($image !== null && $image['source'] === 'vereine') {
				print ' <form method="POST" action="'.$_SERVER['PHP_SELF'].'?socid='.((int) $object->id).'" name="vereinepartnerimageremove'.$kind.$variant.'" class="inline-block">';
				print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="partnerimageremove">';
				print '<input type="hidden" name="kind" value="'.$kind.'"><input type="hidden" name="variant" value="'.$variant.'">';
				print '<input type="submit" class="button small butActionDelete" value="'.dol_escape_htmltag($langs->trans('Delete')).'"></form>';
			}
		}
		print '</td></tr>';
	}
}
print '</table></div>';
print '<div class="opacitymedium small paddingtop">'.$langs->trans('VereinePartnerImagesHowTo').'</div><br>';

vereinePrintLog($db, $member ? (int) $member->id : 0, (int) $object->id);

print '</div>';
print dol_get_fiche_end();

llxFooter();
$db->close();
