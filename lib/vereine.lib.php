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
 * \file    lib/vereine.lib.php
 * \ingroup vereine
 * \brief   Shared functions of the Vereine pages.
 */

/**
 * A date YYYY-MM-DD in the user's date format.
 *
 * @param string $date Date
 * @return string Empty for an empty date
 */
function vereineFormatDay($date)
{
	if ((string) $date === '') {
		return '';
	}
	return dol_print_date(dol_mktime(12, 0, 0, (int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)), 'day');
}

/**
 * A paragraph of the statutes as HTML: its number hanging, list items one per line and indented.
 *
 * @param string $paragraph Paragraph of VereineStatuteText::sections(), list items after line breaks
 * @return string HTML
 */
function vereineStatuteParagraphHtml($paragraph)
{
	$lines = explode("\n", (string) $paragraph);
	$first = array_shift($lines);
	if (preg_match('/^(\(\d+\)) (.*)$/s', $first, $parts)) {
		$html = '<div class="statute-paragraph" style="display:flex; margin:0.4em 0;"><span style="flex:0 0 2.5em;">'.dol_escape_htmltag($parts[1]).'</span>';
		$html .= '<span style="flex:1;">'.dol_escape_htmltag($parts[2]);
	} else {
		$html = '<div class="statute-paragraph" style="display:flex; margin:0.4em 0;"><span style="flex:1;">'.dol_escape_htmltag($first);
	}
	foreach ($lines as $line) {
		if (preg_match('/^([a-z]\)) (.*)$/s', $line, $parts)) {
			$html .= '<span class="statute-item" data-statute-item="1" style="display:flex; margin-top:0.2em;"><span style="flex:0 0 2em; padding-left:0.5em;">'.dol_escape_htmltag($parts[1]).'</span>';
			$html .= '<span style="flex:1;">'.dol_escape_htmltag($parts[2]).'</span></span>';
		} else {
			$html .= '<br>'.dol_escape_htmltag($line);
		}
	}
	return $html.'</span></div>';
}

/**
 * The ages of a discount rule in plain words.
 *
 * @param array<string,mixed> $rule Discount rule
 * @return string
 */
function vereineDiscountAges(array $rule)
{
	global $langs;

	if ($rule['age_from'] !== '' && $rule['age_to'] !== '') {
		return $langs->trans('VereineDiscountAgesBetween', $rule['age_from'], $rule['age_to']);
	}
	return $rule['age_from'] !== '' ? $langs->trans('VereineDiscountAgesFrom', $rule['age_from']) : $langs->trans('VereineDiscountAgesUpTo', $rule['age_to']);
}

/**
 * What a discount rule does to the fee, in plain words.
 *
 * @param array<string,mixed> $rule Discount rule
 * @return string
 */
function vereineDiscountValue(array $rule)
{
	global $langs, $conf;

	if ($rule['mode'] === VereineFeeDiscounts::MODE_FREE) {
		return $langs->trans('VereineDiscountMode_free');
	}
	if ($rule['mode'] === VereineFeeDiscounts::MODE_PERCENT) {
		return $langs->trans('VereineDiscountPercentOff', price2num($rule['value']));
	}
	return $langs->trans('VereineDiscountFixedAmount', price($rule['value'], 0, $langs, 1, -1, -1, $conf->currency));
}

/**
 * How many holders a function needs, in plain words.
 *
 * @param array{min:int,max:int} $function Function
 * @return string
 */
function vereineFunctionCount(array $function)
{
	global $langs;

	if ($function['max'] > 0) {
		return $langs->trans($function['min'] === $function['max'] ? 'VereineFunctionCountExactly' : 'VereineFunctionCountBetween', $function['min'], $function['max']);
	}
	return $langs->trans('VereineFunctionCountAtLeast', $function['min']);
}

/**
 * The notice rule of the statutes in plain words.
 *
 * @param array{months:int,at:string,start_month:int} $rule Rule of VereineExits::rule()
 * @return string
 */
function vereineExitRuleText(array $rule)
{
	global $langs;

	$months = array(1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
		9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December');
	return $langs->trans('VereineExitRuleText_'.$rule['at'], $rule['months'], $langs->trans($months[$rule['start_month']]));
}

/**
 * Why a fee has its amount, in plain words, or empty for a whole period.
 *
 * @param array<string,mixed> $fee Fee of VereineFeeRules::nextFee()
 * @return string
 */
function vereineFeeReason(array $fee)
{
	global $langs;

	if ($fee['reason'] === VereineFeeRules::REASON_PRORATED) {
		return $langs->trans('VereineFeeReasonProrated_'.$fee['proration'], $fee['parts'], $fee['period_parts']);
	}
	if ($fee['reason'] === VereineFeeRules::REASON_FIRST_PART_FULL) {
		return $langs->trans('VereineFeeReasonFirstPartFull_'.$fee['proration']);
	}
	if ($fee['reason'] === VereineFeeRules::REASON_REST_FULL) {
		return $langs->trans('VereineFeeReasonRestFull', $fee['months']);
	}
	return '';
}

/**
 * The signatures of one document: state, who is still missing, the sheet, and the ways to sign.
 *
 * The same block serves every page that has documents to sign.
 *
 * @param VereineSignatures $signatures Signature store
 * @param string            $kind       Kind of document, see VereineSignatureRules::KINDS
 * @param int               $objectId   The document's object
 * @param string            $file       Absolute path of the document
 * @param bool              $canWrite   Whether the user may sign or upload
 * @param string            $anchor     Anchor the forms return to
 * @return void
 */
function vereineSignatureBlock($signatures, $kind, $objectId, $file, $canWrite, $anchor)
{
	global $langs, $user;

	$rules = $signatures->rules();
	$run = $signatures->current($kind, $objectId, $file);
	$back = $_SERVER['PHP_SELF'].'#'.$anchor;
	if ($run === null) {
		if (!VereineSignatureRules::wanted($rules, $kind)) {
			print '<span class="opacitymedium" data-signature="none">'.$langs->trans('VereineSignatureOff').'</span>';
			return;
		}
		if ($canWrite && $file !== '' && is_file($file)) {
			print '<form method="POST" action="'.$back.'" name="vereinestartsign'.$kind.$objectId.'">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="startsign">';
			print '<input type="hidden" name="object" value="'.((int) $objectId).'">';
			print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineSignatureStart')).'">';
			print '</form>';
		}
		return;
	}
	print '<span data-signature="'.$run['id'].'" data-status="'.$run['status'].'" data-signed="'.$run['signed'].'" data-needed="'.$run['needed'].'">';
	print $langs->trans($run['status'] === VereineSignatures::STATUS_DONE ? 'VereineSignatureComplete' : 'VereineSignatureProgress', $run['signed'], $run['needed']);
	print '</span>';
	$open = array();
	$mine = false;
	foreach ($run['people'] as $person) {
		if ($person['signed_at'] === 0) {
			$open[] = $person['name'].' ('.$person['label'].')';
			$mine = $mine || $person['member_id'] === (int) $user->fk_member;
		}
	}
	if ($open && $run['status'] === VereineSignatures::STATUS_OPEN) {
		print '<div class="opacitymedium small">'.$langs->trans('VereineSignatureOpenBy', implode(', ', $open)).'</div>';
	}
	if ($run['document_changed']) {
		print '<div class="warning" data-signature-changed="1">'.$langs->trans('VereineSignatureChanged').'</div>';
	}
	if (is_file(VereineSignatures::sheetPath($run['id']))) {
		print '<div><a href="'.$_SERVER['PHP_SELF'].'?action=sheet&amp;signature='.$run['id'].'&amp;token='.newToken().'">'.img_picto('', 'pdf').' '.$langs->trans('VereineSignatureSheet').'</a></div>';
	}
	if (VereineSignatures::scanPath($run) !== '') {
		print '<div><a href="'.$_SERVER['PHP_SELF'].'?action=signed&amp;signature='.$run['id'].'&amp;token='.newToken().'">'.img_picto('', 'pdf').' '.$langs->trans('VereineSignatureScan').'</a></div>';
	}
	if (is_file(VereineSignatures::qesPath($run['id']))) {
		$page = dol_buildpath('/vereine/signature.php', 1);
		print '<div data-qes-document="'.$run['id'].'"><a href="'.$page.'?action=download&amp;signature='.$run['id'].'&amp;token='.newToken().'">'.img_picto('', 'pdf').' '.$langs->trans('VereineQesDocument').'</a>';
		print ' &middot; <a href="'.$page.'?signature='.$run['id'].'" data-qes-verify="'.$run['id'].'">'.$langs->trans('VereineQesVerify').'</a></div>';
	}
	$click = VereineSignatureRules::allowsClick($rules, $kind);
	$withQes = VereineSignatureRules::allowsQes($rules, $kind);
	if ($run['status'] === VereineSignatures::STATUS_OPEN && $canWrite && $mine && !$run['document_changed'] && $withQes) {
		if (VereineQes::configured()) {
			print '<form method="POST" action="'.dol_buildpath('/vereine/signature.php', 1).'" name="vereinesignqes'.$run['id'].'" class="paddingtop">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="qes">';
			print '<input type="hidden" name="signature" value="'.$run['id'].'">';
			print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineQesSign')).'">';
			print ' <span class="opacitymedium small">'.$langs->trans('VereineQesSignHelp').'</span>';
			print '</form>';
		} else {
			print '<div class="warning" data-qes-missing="1">'.$langs->trans('VereineQesNotConfigured').'</div>';
		}
	}
	if ($run['status'] === VereineSignatures::STATUS_OPEN && $withQes && !$click) {
		print '<div class="opacitymedium small" data-qes-only="1">'.$langs->trans('VereineQesOnlyHint').'</div>';
	}
	if ($run['status'] === VereineSignatures::STATUS_OPEN && $canWrite && $mine && !$run['document_changed'] && $click) {
		print '<form method="POST" action="'.$back.'" name="vereinesign'.$run['id'].'" class="paddingtop">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="sign">';
		print '<input type="hidden" name="signature" value="'.$run['id'].'">';
		print '<input type="password" name="password" autocomplete="current-password" placeholder="'.dol_escape_htmltag($langs->trans('Password')).'"> ';
		print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineSignatureSign')).'">';
		print '</form>';
	}
	if ($run['status'] === VereineSignatures::STATUS_OPEN && $canWrite) {
		print '<form method="POST" action="'.$back.'" name="vereinesignscan'.$run['id'].'" enctype="multipart/form-data" class="paddingtop">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="signscan">';
		print '<input type="hidden" name="signature" value="'.$run['id'].'">';
		print '<input type="file" name="scan_file" accept="application/pdf"> ';
		print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('VereineSignatureUpload')).'">';
		print '</form>';
	}
}

/**
 * The placeholders of some groups with what they mean and an example; a click puts one where the cursor was.
 *
 * @param string[]             $groups Groups of VereinePlaceholders::KEYS
 * @param array<string,string> $values Example values by placeholder
 * @param bool                 $open   Whether the list starts open
 * @return void
 */
function vereinePlaceholderList(array $groups, array $values, $open = false)
{
	global $langs;

	dol_include_once('/vereine/class/vereineplaceholders.class.php');
	print '<details class="paddingbottom" data-placeholders="'.dol_escape_htmltag(implode(' ', $groups)).'"'.($open ? ' open' : '').'>';
	print '<summary>'.$langs->trans('VereinePlaceholdersTitle').'</summary>';
	print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereinePlaceholdersHelp').'</div>';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('VereinePlaceholder').'</td><td>'.$langs->trans('Description').'</td>';
	print '<td>'.$langs->trans('VereinePlaceholderExample').'</td></tr>';
	foreach ($groups as $group) {
		print '<tr class="liste_titre_filter"><td colspan="3">'.$langs->trans('VereinePlaceholderGroup_'.$group).'</td></tr>';
		foreach (VereinePlaceholders::KEYS[$group] as $key) {
			$example = isset($values[$key]) ? str_replace("\n", ' · ', trim($values[$key])) : '';
			print '<tr class="oddeven" data-placeholder="'.dol_escape_htmltag($key).'"><td class="nowraponall">';
			print '<a href="#" class="vereine-placeholder" data-insert="'.dol_escape_htmltag($key).'" title="'.dol_escape_htmltag($langs->trans('VereinePlaceholderInsert')).'">';
			print '<code>'.dol_escape_htmltag($key).'</code></a></td>';
			print '<td>'.$langs->trans(VereinePlaceholders::describedBy($key)).'</td>';
			print '<td class="opacitymedium small" data-example="'.dol_escape_htmltag($key).'">'.dol_escape_htmltag(dol_trunc($example, 90)).'</td></tr>';
		}
	}
	print '</table></div></details>';
	// Once per page: remember the last text field and put the placeholder at its cursor.
	print '<script>(function () { if (window.vereinePlaceholders) { return; } window.vereinePlaceholders = true; var last = null;';
	print ' document.addEventListener("focusin", function (e) { var t = e.target; if (t && (t.tagName === "TEXTAREA" || (t.tagName === "INPUT" && t.type === "text"))) { last = t; } });';
	print ' document.addEventListener("click", function (e) { var link = e.target.closest ? e.target.closest("a.vereine-placeholder") : null; if (!link) { return; }';
	print ' e.preventDefault(); var text = link.getAttribute("data-insert");';
	print ' if (!last) { if (navigator.clipboard) { navigator.clipboard.writeText(text); } return; }';
	print ' var start = last.selectionStart || 0, end = last.selectionEnd || 0; last.value = last.value.slice(0, start) + text + last.value.slice(end);';
	print ' last.focus(); last.selectionStart = last.selectionEnd = start + text.length; }); })();</script>';
}

/**
 * Tabs of the module's setup pages.
 *
 * @return array<int,array<int,string>>
 */
function vereineAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load('vereine@vereine');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/vereine/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabAssociation');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/partners.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabPartners');
	$head[$h][2] = 'partners';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/taxprofiles.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabTaxProfiles');
	$head[$h][2] = 'taxprofiles';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/fees.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabFees');
	$head[$h][2] = 'fees';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/functions.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabFunctions');
	$head[$h][2] = 'functions';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/statutes.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabStatutes');
	$head[$h][2] = 'statutes';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/meetings.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabMeetings');
	$head[$h][2] = 'meetings';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/events.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabEvents');
	$head[$h][2] = 'events';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/signatures.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabSignatures');
	$head[$h][2] = 'signatures';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/webhooks.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabWebhooks');
	$head[$h][2] = 'webhooks';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/api.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabApi');
	$head[$h][2] = 'api';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/consents.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabConsents');
	$head[$h][2] = 'consents';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/application.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabApplication');
	$head[$h][2] = 'application';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'vereine@vereine');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'vereine@vereine', 'remove');

	return $head;
}

/**
 * Name of a month in the user's language.
 *
 * @param int $month 1 to 12
 * @return string
 */
function vereineMonthName($month)
{
	global $langs;

	$keys = array(1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
	return isset($keys[$month]) ? $langs->trans($keys[$month]) : '';
}

/**
 * A status badge for a check result.
 *
 * @param string $status VereineOrganization::CHECK_OK or CHECK_WARNING
 * @return string HTML
 */
function vereineCheckBadge($status)
{
	global $langs;

	if ($status === VereineOrganization::CHECK_OK) {
		return dolGetBadge($langs->trans('VereineCheckStatusOk'), '', 'success');
	}
	return dolGetBadge($langs->trans('VereineCheckStatusWarning'), '', 'warning');
}

/**
 * Open invoices of a third party, for the membership tabs of third party and member.
 *
 * @param DoliDB $db    Database handler
 * @param int    $socid Third party id
 * @return void
 */
function vereinePrintOpenInvoices($db, $socid)
{
	global $conf, $langs, $user;

	// Membership fee invoices get their own marker in 0.3 (issue #14).
	if (!isModEnabled('invoice') || !$user->hasRight('facture', 'lire')) {
		return;
	}
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

	print load_fiche_titre($langs->trans('VereineOpenInvoices'), '', '');
	$sql = "SELECT f.rowid, f.ref, f.total_ttc, f.date_lim_reglement FROM ".MAIN_DB_PREFIX."facture as f";
	$sql .= " WHERE f.fk_soc = ".((int) $socid)." AND f.fk_statut = ".Facture::STATUS_VALIDATED." AND f.paye = 0";
	$sql .= " AND f.entity IN (".getEntity('invoice').") ORDER BY f.date_lim_reglement ASC";
	$resql = $db->query($sql);
	print '<table class="noborder centpercent" data-open-invoices="1">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td class="center">'.$langs->trans('DateDue').'</td><td class="right">'.$langs->trans('AmountTTC').'</td></tr>';
	$count = 0;
	while ($resql && ($row = $db->fetch_object($resql))) {
		$invoice = new Facture($db);
		$invoice->id = (int) $row->rowid;
		$invoice->ref = (string) $row->ref;
		print '<tr class="oddeven"><td>'.$invoice->getNomUrl(1).'</td><td class="center">'.dol_print_date($db->jdate($row->date_lim_reglement), 'day').'</td>';
		print '<td class="right">'.price((float) $row->total_ttc, 0, $langs, 1, -1, -1, $conf->currency).'</td></tr>';
		$count++;
	}
	if ($count === 0) {
		print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	print '</table>';
	print '<div class="opacitymedium small">'.$langs->trans('VereineOpenInvoicesNote').'</div>';
}

/**
 * Guardians of a minor member: contacts of the third party in the guardian category.
 *
 * @param DoliDB $db    Database handler
 * @param int    $socid Third party id
 * @return void
 */
function vereinePrintGuardians($db, $socid)
{
	global $langs;

	require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
	dol_include_once('/vereine/class/vereinepartnerservice.class.php');

	$guardianCategory = getDolGlobalInt(VereinePartnerService::CONST_GUARDIAN);
	print load_fiche_titre($langs->trans('VereineGuardians'), '', '');
	print '<table class="noborder centpercent" data-guardians="1">';
	$found = 0;
	if ($guardianCategory > 0) {
		$sql = "SELECT sp.rowid FROM ".MAIN_DB_PREFIX."socpeople as sp";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."categorie_contact as cc ON cc.fk_socpeople = sp.rowid";
		$sql .= " WHERE sp.fk_soc = ".((int) $socid)." AND cc.fk_categorie = ".$guardianCategory;
		$resql = $db->query($sql);
		while ($resql && ($row = $db->fetch_object($resql))) {
			$contact = new Contact($db);
			if ($contact->fetch((int) $row->rowid) > 0) {
				print '<tr class="oddeven"><td>'.$contact->getNomUrl(1).'</td><td>'.dol_print_email($contact->email, $contact->id, (int) $socid, 1).'</td></tr>';
				$found++;
			}
		}
	}
	if ($found === 0) {
		print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">'.$langs->trans('VereineGuardiansNone').'</span></td></tr>';
	}
	print '</table>';
}

/**
 * What the module did with a member and its third party: Dolibarr's events of it, where its history is;
 * the module's own list only while Dolibarr's agenda is off (#112).
 *
 * @param DoliDB $db       Database handler
 * @param int    $memberId Member id, 0 for none
 * @param int    $socid    Third party id, 0 for none
 * @return void
 */
function vereinePrintLog($db, $memberId, $socid)
{
	global $langs;

	dol_include_once('/vereine/class/vereinelog.class.php');

	if (isModEnabled('agenda')) {
		$url = (int) $memberId > 0 ? DOL_URL_ROOT.'/adherents/agenda.php?id='.((int) $memberId) : DOL_URL_ROOT.'/societe/agenda.php?socid='.((int) $socid);
		print '<div class="paddingtop" data-log-events="1">'.img_picto('', 'action', 'class="pictofixedwidth"');
		print '<a href="'.$url.'">'.$langs->trans('VereineLogEvents').'</a> <span class="opacitymedium small">'.$langs->trans('VereineLogEventsHelp').'</span></div>';
		return;
	}
	print load_fiche_titre($langs->trans('VereineLogTitle'), '', '');
	print '<div class="opacitymedium small paddingbottom">'.$langs->trans('VereineLogNoAgenda').'</div>';
	print '<table class="noborder centpercent" data-log="1">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Action').'</td><td>'.$langs->trans('Description').'</td></tr>';
	$entries = VereineLog::recent($db, (int) $memberId, (int) $socid, 20);
	foreach ($entries as $entry) {
		print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($entry['date'], 'dayhour').'</td>';
		print '<td>'.dol_escape_htmltag($langs->trans('VereineLog_'.$entry['action'])).'</td>';
		print '<td>'.dol_escape_htmltag($entry['message']).'</td></tr>';
	}
	if (!$entries) {
		print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	print '</table>';
}

/**
 * The thresholds of a year as rows for the overview and the home page box, in plain words.
 *
 * @param array{year:int,thresholds:array<int,array<string,mixed>>,unassigned:array{net:float,gross:float,lines:int}} $report Report of VereineThresholdReport
 * @return array<int,array{code:string,label:string,status:string,badge:string,amount:string,limit:string,text:string,basis:string,source:string}>
 */
function vereineThresholdRows(array $report)
{
	global $langs;

	$langs->load('vereine@vereine');
	$badges = array('ok' => 'success', 'near' => 'warning', 'tolerance' => 'warning', 'exceeded' => 'danger');
	$rows = array();
	foreach ($report['thresholds'] as $threshold) {
		$status = $threshold['status'];
		$amount = price($threshold['amount'], 0, $langs, 1, -1, 2);
		$limit = price($threshold['limit'], 0, $langs, 1, -1, 0);
		$remaining = price(max(0, $threshold['remaining']), 0, $langs, 1, -1, 2);
		if ($status === VereineThresholds::STATUS_EXCEEDED) {
			$text = $langs->trans('VereineThresholdText_exceeded_'.$threshold['code'], $limit);
		} elseif ($status === VereineThresholds::STATUS_TOLERANCE) {
			$text = $langs->trans('VereineThresholdText_tolerance', $limit);
		} else {
			$text = $langs->trans('VereineThresholdText_'.$status, $remaining, $limit);
		}
		if (!empty($threshold['previous_exceeded'])) {
			$text .= ' '.$langs->trans('VereineThresholdPreviousExceeded');
		}
		$rows[] = array(
			'code' => $threshold['code'],
			'label' => $langs->trans('VereineThreshold_'.$threshold['code']),
			'status' => $status,
			'badge' => dolGetBadge($langs->trans('VereineThresholdStatus_'.$status), '', $badges[$status]),
			'amount' => $amount.' € <span class="opacitymedium small">'.$langs->trans($threshold['gross'] ? 'VereineThresholdGross' : 'VereineThresholdNet').'</span>',
			'limit' => $limit.' €',
			'text' => $text,
			'basis' => $threshold['basis'],
			'source' => $threshold['source'],
		);
	}
	return $rows;
}
/**
 * The cash register duty per sphere as rows for the overview, in plain words.
 *
 * @param array<string,mixed> $cashRegister cash_register part of VereineThresholdReport::report()
 * @return array<int,array{sphere:string,label:string,status:string,badge:string,text:string}>
 */
function vereineCashRegisterRows(array $cashRegister)
{
	global $langs;

	$langs->load('vereine@vereine');
	$badges = array('not_relevant' => 'secondary', 'exempt' => 'success', 'exempt_festival' => 'success', 'ok' => 'success', 'near' => 'warning', 'required' => 'danger');
	$rows = array();
	foreach ($cashRegister['spheres'] as $sphere) {
		$status = $sphere['status'];
		$turnover = price($sphere['turnover'], 0, $langs, 1, -1, 2);
		$cash = price($sphere['cash'], 0, $langs, 1, -1, 2);
		$text = $langs->trans('VereineCashText_'.$status, $turnover, $cash);
		if ($sphere['sphere'] === 'harmful') {
			$text .= ' '.$langs->trans('VereineCashSmallCanteen', $cashRegister['small_canteen_days'], price($cashRegister['small_canteen_limit'], 0, $langs, 1, -1, 0));
		}
		$rows[] = array(
			'sphere' => $sphere['sphere'],
			'label' => $langs->trans('VereineSphere_'.$sphere['sphere']),
			'status' => $status,
			'badge' => dolGetBadge($langs->trans('VereineCashStatus_'.$status), '', $badges[$status]),
			'text' => $text,
		);
	}
	return $rows;
}
