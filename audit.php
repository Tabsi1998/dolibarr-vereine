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
 * \file    audit.php
 * \ingroup vereine
 * \brief   The audit of an association's year by the auditors (#117) and their report as PDF (#114).
 *
 * Auditors read the bookings and invoices, tick samples and fill the checklist of § 21 (3) VerG. The
 * module writes only its own tables: rights to change invoices or bank come from Dolibarr alone.
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

require_once __DIR__.'/class/vereineaudit.class.php';
require_once __DIR__.'/class/vereinesignatures.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('bills', 'banks', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read')) {
	accessforbidden();
}

$audit = new VereineAudit($db);
$signatures = new VereineSignatures($db);
// Auditors work here; the board and whoever reads invoices may look.
$isAuditor = $audit->isAuditor($user);
if (!$isAuditor && empty($user->admin) && !$user->hasRight('facture', 'lire')) {
	accessforbidden();
}
$action = GETPOST('action', 'aZ09');
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
$startMonth = getDolGlobalInt('SOCIETE_FISCAL_MONTH_START', 1);
$lastEnded = VereineAuditRules::lastEnded($today, $startMonth);
$year = GETPOSTINT('year');
if ($year < $lastEnded - 5 || $year > $lastEnded + 1) {
	$year = $lastEnded;
}
$self = $_SERVER['PHP_SELF'].'?year='.$year;


/*
 * Actions
 */

if (in_array($action, array('sheet', 'signed', 'report'), true)) {
	$run = $action === 'report' ? null : $signatures->fetch(GETPOSTINT('signature'));
	if ($action === 'report') {
		$record = $audit->fetch($year, $user);
		$file = $record !== null ? VereineAudit::reportPath($record['id']) : '';
	} elseif ($action === 'sheet') {
		$file = $run !== null && $run['kind'] === VereineSignatureRules::KIND_AUDIT_REPORT ? VereineSignatures::sheetPath($run['id']) : '';
	} else {
		$file = $run !== null && $run['kind'] === VereineSignatureRules::KIND_AUDIT_REPORT ? VereineSignatures::scanPath($run) : '';
	}
	if ($file === '' || !is_file($file)) {
		accessforbidden();
	}
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
} elseif ($action === 'check' && $isAuditor) {
	$result = $audit->check($year, GETPOST('element', 'aZ09'), GETPOSTINT('object'), GETPOST('note', 'alphanohtml'), GETPOST('checked', 'aZ09') !== '0', $user);
	if ($result > 0) {
		header('Location: '.$self.'#vereineaudit'.GETPOST('element', 'aZ09'));
		exit;
	}
	setEventMessages($result < 0 ? $audit->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $audit->errors), 'errors');
} elseif ($action === 'save' && $isAuditor) {
	$entered = array('points' => GETPOST('points', 'array'), 'audit_day' => GETPOST('audit_day', 'alphanohtml'), 'statement_day' => GETPOST('statement_day', 'alphanohtml'),
		'documents' => GETPOST('documents', 'restricthtml'), 'note' => GETPOST('note', 'restricthtml'));
	$result = $audit->save($year, $entered, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineAuditSaved'), null, 'mesgs');
		header('Location: '.$self.'#vereineauditchecklist');
		exit;
	}
	setEventMessages($result < 0 ? $audit->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $audit->errors), 'errors');
} elseif ($action === 'build' && $isAuditor) {
	if ($audit->buildReport($year, $user, $langs) !== '') {
		setEventMessages($langs->trans('VereineAuditReportBuilt'), null, 'mesgs');
		header('Location: '.$self.'#vereineauditreport');
		exit;
	}
	setEventMessages($audit->error, null, 'errors');
} elseif ($action === 'startsign' && $isAuditor) {
	$objectId = GETPOSTINT('object');
	$year = $audit->yearOf($objectId) ?: $year;
	$result = $signatures->start(VereineSignatureRules::KIND_AUDIT_REPORT, $objectId, VereineAudit::reportPath($objectId), $today, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('VereineSignatureStarted'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?year='.$year.'#vereineauditreport');
		exit;
	}
	setEventMessages($result < 0 ? $signatures->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $signatures->errors), 'errors');
} elseif (($action === 'sign' || $action === 'signscan') && $isAuditor) {
	$run = $signatures->fetch(GETPOSTINT('signature'));
	$ok = $run !== null && $run['kind'] === VereineSignatureRules::KIND_AUDIT_REPORT;
	if ($ok) {
		$year = $audit->yearOf($run['object_id']) ?: $year;
	}
	if ($action === 'sign') {
		$result = $ok ? $signatures->sign($run['id'], GETPOST('password', 'password'), VereineAudit::reportPath($run['object_id']), $user, $langs) : 0;
	} else {
		$upload = isset($_FILES['scan_file']) && is_array($_FILES['scan_file']) ? $_FILES['scan_file'] : array();
		$result = $ok ? $signatures->uploadScan($run['id'], $upload, $user, $langs) : 0;
	}
	if ($result > 0) {
		setEventMessages($langs->trans($action === 'sign' ? 'VereineSignatureSigned' : 'VereineSignatureScanStored'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?year='.$year.'#vereineauditreport');
		exit;
	}
	setEventMessages($result < 0 ? $signatures->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $ok ? $signatures->errors : array('VereineSignatureErrorNotOpen')), 'errors');
}


/*
 * View
 */

$period = VereineAudit::period($year);
$record = $audit->fetch($year, $user);
$items = $audit->items($year);
$auditors = $audit->holders($today, 'auditor');

llxHeader('', $langs->trans('VereineAuditTitle'), '', '', 0, 0, '', '', '', 'mod-vereine page-audit');
print load_fiche_titre($langs->trans('VereineAuditTitle'), '', 'fa-search-dollar');
print '<div class="paddingbottom" data-audit-years="1">'.$langs->trans('VereineAuditYear').': ';
for ($option = $lastEnded - 5; $option <= $lastEnded + 1; $option++) {
	$label = VereineAudit::period($option)['label'];
	print $option === $year ? '<strong class="paddingright">'.$label.'</strong> ' : '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?year='.$option.'">'.$label.'</a> ';
}
print '</div>';
print '<div class="info" data-audit-howto="1"><ul>';
print '<li>'.$langs->trans('VereineAuditHowTo', $period['label'], vereineFormatDay($period['start']), vereineFormatDay($period['end'])).'</li>';
print '<li>'.$langs->trans('VereineAuditHowToLaw').'</li>';
print '<li>'.$langs->trans('VereineAuditHowToRights').'</li>';
print '</ul></div>';

// Who audits.
print '<div class="paddingbottom" data-audit-auditors="'.count($auditors).'" data-audit-is-auditor="'.($isAuditor ? 1 : 0).'">';
if (!$auditors) {
	print '<div class="warning">'.$langs->trans('VereineAuditNoAuditors', '<a href="'.dol_buildpath('/vereine/functions.php', 1).'">'.$langs->trans('VereineMenuFunctions').'</a>').'</div>';
} else {
	$names = array();
	foreach ($auditors as $auditor) {
		$names[] = dol_escape_htmltag($auditor['name']).' <span class="opacitymedium small">'.$langs->trans('VereineAuditSince', vereineFormatDay($auditor['start'])).'</span>';
	}
	print '<strong>'.$langs->trans('VereineAuditAuditors').':</strong> '.implode(', ', $names);
	if (!$isAuditor) {
		print '<div class="opacitymedium small">'.$langs->trans('VereineAuditOnlyAuditors').'</div>';
	}
}
print '</div>';

// Where to look closer.
$hinted = array();
foreach ($items as $element => $rows) {
	foreach ($rows as $row) {
		if ($row['hints']) {
			$hinted[] = array('element' => $element) + $row;
		}
	}
}
print load_fiche_titre($langs->trans('VereineAuditHintsTitle', count($hinted)), '', '', 0, 'vereineaudithints');
if (!$hinted) {
	print '<div class="ok" data-audit-hints="0">'.$langs->trans('VereineAuditNoHints').'</div>';
} else {
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" data-audit-hints="'.count($hinted).'">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Description').'</td><td class="right">'.$langs->trans('Amount').'</td>';
	print '<td>'.$langs->trans('VereineAuditHint').'</td></tr>';
	foreach ($hinted as $row) {
		print '<tr class="oddeven" data-audit-hint="'.$row['element'].':'.$row['id'].'" data-hints="'.implode(' ', $row['hints']).'">';
		print '<td class="nowraponall">'.vereineFormatDay($row['date']).'</td><td><a href="'.$row['url'].'">'.dol_escape_htmltag($row['text']).'</a></td>';
		print '<td class="right nowraponall">'.price($row['amount'], 0, $langs, 1, -1, 2).'</td><td>';
		foreach ($row['hints'] as $hint) {
			print dolGetBadge($langs->trans('VereineAuditHint_'.$hint, dol_escape_htmltag($row['officer'])), '', $hint === VereineAuditRules::HINT_SELF_DEALING ? 'danger' : 'warning').' ';
		}
		print '</td></tr>';
	}
	print '</table></div>';
}

// Every booking and invoice, to tick as checked.
foreach (VereineAudit::ELEMENTS as $element) {
	$rows = $items[$element];
	$checked = count(array_filter($rows, function ($row) {
		return $row['check'] !== null;
	}));
	print '<br>'.load_fiche_titre($langs->trans('VereineAuditElement_'.$element, count($rows), $checked), '', '', 0, 'vereineaudit'.$element);
	if (!$rows) {
		print '<div class="opacitymedium">'.$langs->trans('VereineAuditNothing').'</div>';
		continue;
	}
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Description').'</td><td class="right">'.$langs->trans('Amount').'</td>';
	print '<td>'.$langs->trans('VereineAuditChecked').'</td></tr>';
	foreach ($rows as $row) {
		print '<tr class="oddeven" data-audit-item="'.$element.':'.$row['id'].'" data-checked="'.($row['check'] !== null ? 1 : 0).'">';
		print '<td class="nowraponall">'.vereineFormatDay($row['date']).'</td><td><a href="'.$row['url'].'">'.dol_escape_htmltag($row['text']).'</a></td>';
		print '<td class="right nowraponall">'.price($row['amount'], 0, $langs, 1, -1, 2).'</td><td>';
		if ($row['check'] !== null) {
			print '<span class="small">'.$langs->trans('VereineAuditCheckedBy', dol_escape_htmltag($row['check']['user']), dol_print_date($row['check']['date'], 'day')).'</span>';
			if ($row['check']['note'] !== '') {
				print '<div class="small opacitymedium">'.dol_escape_htmltag($row['check']['note']).'</div>';
			}
		}
		if ($isAuditor) {
			print '<form method="POST" action="'.$self.'#vereineaudit'.$element.'" class="inline-block">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="check"><input type="hidden" name="element" value="'.$element.'">';
			print '<input type="hidden" name="object" value="'.$row['id'].'"><input type="hidden" name="checked" value="'.($row['check'] !== null ? '0' : '1').'">';
			if ($row['check'] === null) {
				print '<input type="text" name="note" class="maxwidth150" placeholder="'.dol_escape_htmltag($langs->trans('VereineAuditNote')).'"> ';
			}
			print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans($row['check'] !== null ? 'VereineAuditUncheck' : 'VereineAuditCheck')).'">';
			print '</form>';
		}
		print '</td></tr>';
	}
	print '</table></div>';
}

// The checklist of § 21 (3) VerG.
print '<br>'.load_fiche_titre($langs->trans('VereineAuditChecklistTitle'), '', '', 0, 'vereineauditchecklist');
$result = VereineAuditRules::result($record['points']);
print '<div class="paddingbottom" data-audit-result="'.$result.'">'.$langs->trans('VereineAuditConclusion_'.$result).'</div>';
if ($isAuditor) {
	print '<form method="POST" action="'.$self.'#vereineauditchecklist" name="vereineauditchecklist">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="save">';
}
print '<table class="border centpercent">';
foreach ($record['points'] as $point => $entry) {
	print '<tr data-audit-point="'.$point.'" data-state="'.$entry['state'].'"><td class="titlefieldcreate tdtop">'.$langs->trans('VereineAuditPoint_'.$point);
	print '<div class="opacitymedium small">'.$langs->trans('VereineAuditPointHelp_'.$point).'</div></td><td>';
	if ($isAuditor) {
		print '<select name="points['.$point.'][state]">';
		foreach (VereineAuditRules::STATES as $state) {
			print '<option value="'.$state.'"'.($entry['state'] === $state ? ' selected' : '').'>'.$langs->trans('VereineAuditState_'.$state).'</option>';
		}
		print '</select><textarea name="points['.$point.'][text]" rows="2" class="centpercent" placeholder="'.dol_escape_htmltag($langs->trans('VereineAuditDefectPlaceholder')).'">';
		print dol_escape_htmltag($entry['text'], 0, 1).'</textarea>';
	} else {
		print $langs->trans('VereineAuditState_'.$entry['state']).($entry['text'] !== '' ? '<div>'.nl2br(dol_escape_htmltag($entry['text'], 0, 1)).'</div>' : '');
	}
	print '</td></tr>';
}
$fields = array('audit_day' => 'date', 'statement_day' => 'date', 'documents' => 'text', 'note' => 'text');
foreach ($fields as $field => $type) {
	print '<tr><td class="titlefieldcreate"><label for="'.$field.'">'.$langs->trans('VereineAudit_'.$field).'</label></td><td>';
	if ($isAuditor) {
		print $type === 'date' ? '<input type="date" id="'.$field.'" name="'.$field.'" value="'.dol_escape_htmltag($record[$field]).'">'
			: '<textarea id="'.$field.'" name="'.$field.'" rows="2" class="centpercent" placeholder="'.dol_escape_htmltag($field === 'documents' ? $langs->trans('VereineAuditDocumentsDefault') : '').'">'.dol_escape_htmltag($record[$field], 0, 1).'</textarea>';
	} else {
		print $type === 'date' ? vereineFormatDay($record[$field]) : nl2br(dol_escape_htmltag($record[$field], 0, 1));
	}
	print '</td></tr>';
}
print '</table>';
if ($isAuditor) {
	print '<div class="center paddingtop"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div></form>';
}

// The report as PDF, and its signatures.
print '<br>'.load_fiche_titre($langs->trans('VereineAuditReportSection'), '', '', 0, 'vereineauditreport');
$report = VereineAudit::reportPath($record['id']);
if (is_file($report)) {
	print '<div class="paddingbottom" data-audit-report="1"><a href="'.$self.'&amp;action=report&amp;token='.newToken().'">'.img_picto('', 'pdf').' '.basename($report).'</a></div>';
}
if ($isAuditor) {
	print '<form method="POST" action="'.$self.'#vereineauditreport" name="vereineauditbuild" class="paddingbottom">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="build">';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans(is_file($report) ? 'VereineAuditReportRebuild' : 'VereineAuditReportBuild')).'"> ';
	print '<span class="opacitymedium small">'.$langs->trans('VereineAuditReportHelp').'</span></form>';
}
if (is_file($report)) {
	vereineSignatureBlock($signatures, VereineSignatureRules::KIND_AUDIT_REPORT, $record['id'], $report, $isAuditor, 'vereineauditreport');
}

llxFooter();
$db->close();
