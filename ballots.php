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
 * \file    ballots.php
 * \ingroup vereine
 * \brief   Ballots of a general assembly (#160, #161): prepared, released, opened and closed by whoever chairs; paper ballots entered.
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

require_once __DIR__.'/class/vereineballots.class.php';
require_once __DIR__.'/class/vereinefunctions.class.php';
require_once __DIR__.'/lib/vereine.lib.php';

$langs->loadLangs(array('members', 'other', 'vereine@vereine'));

if (!isModEnabled('vereine')) {
	accessforbidden('Module not enabled');
}
if (!empty($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('vereine', 'association', 'read') || !$user->hasRight('adherent', 'lire')) {
	accessforbidden();
}

$canWrite = $user->hasRight('adherent', 'creer');
$action = GETPOST('action', 'aZ09');
$meetingId = GETPOSTINT('meeting');
$ballotId = GETPOSTINT('ballot');
$meetings = new VereineMeetings($db);
$meeting = $meetings->fetch($meetingId);
if ($meeting === null) {
	accessforbidden();
}
$ballots = new VereineBallots($db);
$self = $_SERVER['PHP_SELF'].'?meeting='.$meeting['id'];
$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');


/*
 * Actions
 */

// Only a ballot of this meeting is acted on.
$ballot = $ballotId > 0 ? $ballots->fetch($ballotId) : null;
if ($ballot !== null && $ballot['meeting_id'] !== $meeting['id']) {
	$ballot = null;
}
$result = null;
if ($action === 'create' && $canWrite) {
	$entered = array('item' => GETPOST('item', 'aZ09'), 'kind' => GETPOST('kind', 'aZ09'), 'question' => GETPOST('question', 'alphanohtml'),
		'channels' => (array) GETPOST('channels', 'array'), 'closes' => GETPOST('closes', 'alphanohtml'), 'function_id' => GETPOST('function_id', 'aZ09'),
		'candidates' => (array) GETPOST('candidates', 'array'));
	$entered['consent'] = GETPOSTISSET('consent') ? $entered['candidates'] : array();
	$result = $ballots->create($meeting['id'], $entered, $user);
} elseif ($ballot !== null && $canWrite && $action === 'release') {
	$result = $ballots->release($ballot['id'], $user);
} elseif ($ballot !== null && $canWrite && $action === 'open') {
	$result = $ballots->open($ballot['id'], $today, $user);
} elseif ($ballot !== null && $canWrite && in_array($action, array('close', 'cancel'), true)) {
	$result = $ballots->finish($ballot['id'], $action === 'close' ? VereineBallotRules::STATUS_CLOSED : VereineBallotRules::STATUS_CANCELLED, $user);
} elseif ($ballot !== null && $canWrite && in_array($action, array('evaluate', 'reevaluate'), true)) {
	$result = $ballots->evaluate($ballot['id'], $action === 'reevaluate' ? GETPOST('reason', 'alphanohtml') : '', $user, $langs);
} elseif ($ballot !== null && $canWrite && $action === 'proof') {
	$result = $ballots->buildProof(GETPOSTINT('result'), $langs);
} elseif ($ballot !== null && $canWrite && $action === 'confirm') {
	// Confirming is the act of whoever chairs; only now the resolution, the term or the version of the statutes follows.
	$result = $ballots->confirm($ballot['id'], $user, $langs);
} elseif ($ballot !== null && $action === 'download') {
	$file = '';
	foreach ($ballots->results($ballot['id']) as $counted) {
		if ($counted['id'] === GETPOSTINT('result') && $counted['filename'] !== '') {
			$file = VereineBallots::directory().'/'.$counted['filename'];
		}
	}
	if ($file === '' || !is_file($file)) {
		accessforbidden();
	}
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Length: '.filesize($file));
	readfile($file);
	exit;
} elseif ($ballot !== null && $canWrite && $action === 'paper') {
	// A paper ballot the board collected: the same voting right, used once whichever way.
	$result = $ballots->cast($ballot['id'], GETPOSTINT('right'), GETPOST('option', 'aZ09'), 0, VereineBallotRules::CHANNEL_PAPER, '', '', $user);
	if ($result === 0) {
		$ballots->errors = array('VereineBallotRefused_'.$ballots->reason);
	}
}
if ($result !== null) {
	if ($result > 0 && $action === 'evaluate' && $ballots->error !== '') {
		// Counted, but the proof failed; it is built again from the same count.
		setEventMessages($langs->trans('VereineBallotProofMissing'), null, 'warnings');
	}
	if ($result > 0) {
		setEventMessages($langs->trans('VereineBallotSaved'), null, 'mesgs');
		header('Location: '.$self.'#vereineballots');
		exit;
	}
	setEventMessages($result < 0 ? $ballots->error : null, $result < 0 ? null : array_map(array($langs, 'trans'), $ballots->errors), 'errors');
}


/*
 * View
 */

$title = $langs->trans('VereineBallotsTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-vereine page-ballots');
print load_fiche_titre($title.': '.dol_escape_htmltag($meeting['title']).' ('.vereineFormatDay($meeting['day']).')',
	'<a href="'.dol_buildpath('/vereine/meetings.php', 1).'?id='.$meeting['id'].'">'.$langs->trans('BackToList').'</a>', 'fa-vote-yea', 0, 'vereineballots');
print '<div class="opacitymedium paddingbottom">'.$langs->trans('VereineBallotsHowTo').'</div>';
$option = function ($code, $label) use ($langs) {
	return $label !== '' ? dol_escape_htmltag($label) : $langs->trans('VereineBallotOption_'.$code);
};
$form = function ($name, $action, $ballotId, $label, $inner = '') use ($self) {
	return '<form method="POST" action="'.$self.'" name="'.$name.'" class="inline-block paddingright"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="ballot" value="'.((int) $ballotId).'">'.$inner
		.'<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($label).'"></form>';
};

foreach ($ballots->forMeeting($meeting['id']) as $shown) {
	$id = $shown['id'];
	print '<div class="fichecenter paddingbottom" data-ballot="'.$id.'" data-ballot-status="'.$shown['status'].'">';
	print '<table class="border centpercent"><tr><td class="titlefield">'.$langs->trans('VereineVoteItem').' '.$shown['item'].'</td>';
	print '<td><strong>'.dol_escape_htmltag($shown['question']).'</strong> · '.$langs->trans('VereineVoteKind_'.$shown['kind']);
	print ' · <span class="badge badge-status4">'.$langs->trans('VereineBallotStatus_'.$shown['status']).'</span></td></tr>';
	print '<tr><td>'.$langs->trans('VereineBallotOptions').'</td><td>';
	$labels = array();
	foreach ($shown['options'] as $entry) {
		$labels[$entry['code']] = $option($entry['code'], $entry['label']);
	}
	print implode(' · ', $labels);
	print '<br><span class="opacitymedium small">'.$langs->trans('VereineBallotChannels').': '.implode(', ', array_map(function ($channel) use ($langs) {
		return $langs->trans('VereineBallotChannel_'.$channel);
	}, $shown['channels'])).($shown['closes'] !== '' ? ' · '.$langs->trans('VereineBallotClosesAt', $shown['closes']) : '').'</span></td></tr>';
	if ($canWrite) {
		print '<tr><td></td><td>';
		if ($shown['status'] === VereineBallotRules::STATUS_DRAFT) {
			print $form('vereineballotrelease'.$id, 'release', $id, $langs->trans('VereineBallotRelease'));
		} elseif ($shown['status'] === VereineBallotRules::STATUS_RELEASED) {
			print $form('vereineballotopen'.$id, 'open', $id, $langs->trans('VereineBallotOpen'));
		} elseif ($shown['status'] === VereineBallotRules::STATUS_OPEN) {
			print $form('vereineballotclose'.$id, 'close', $id, $langs->trans('VereineBallotClose'));
		} elseif ($shown['status'] === VereineBallotRules::STATUS_CLOSED) {
			print $form('vereineballotevaluate'.$id, 'evaluate', $id, $langs->trans('VereineBallotEvaluate'));
		}
		if (VereineBallotRules::canMove($shown['status'], VereineBallotRules::STATUS_CANCELLED)) {
			print $form('vereineballotcancel'.$id, 'cancel', $id, $langs->trans('VereineBallotCancel'));
		}
		print '</td></tr>';
	}
	print '</table>';
	if (in_array($shown['status'], array(VereineBallotRules::STATUS_OPEN, VereineBallotRules::STATUS_CLOSED, VereineBallotRules::STATUS_EVALUATED, VereineBallotRules::STATUS_CANCELLED), true)) {
		$rights = $ballots->rights($id);
		$eligible = count(array_filter($rights, function ($right) {
			return $right['eligible'];
		}));
		$used = count(array_filter($rights, function ($right) {
			return $right['used'];
		}));
		print '<div class="paddingtop" data-ballot-used="'.$used.'" data-ballot-eligible="'.$eligible.'">'.$langs->trans('VereineBallotUsed', $used, $eligible).'</div>';
		print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Member').'</td>';
		print '<td>'.$langs->trans('VereineBallotRight').'</td><td>'.$langs->trans('VereineBallotState').'</td></tr>';
		$unused = array();
		foreach ($rights as $right) {
			print '<tr class="oddeven" data-ballot-right="'.$right['id'].'" data-ballot-right-member="'.$right['member_id'].'"><td>'.dol_escape_htmltag($right['name']).'</td><td>'
				.$langs->trans('VereineBallotReason_'.$right['reason']).($right['reason'] === VereineBallotRules::REASON_PROXY ? ': '.dol_escape_htmltag($right['holder_name']) : '').'</td><td>';
			// An open ballot shows who voted, never what; the choice is counted after closing.
			print $right['used'] ? $langs->trans('VereineBallotVoted', $langs->trans('VereineBallotChannel_'.$right['channel'])) : ($right['eligible'] ? $langs->trans('VereineBallotNotVoted') : '');
			print '</td></tr>';
			if ($right['eligible'] && !$right['used']) {
				$unused[$right['id']] = $right['name'].($right['reason'] === VereineBallotRules::REASON_PROXY ? ' ('.$right['holder_name'].')' : '');
			}
		}
		print '</table></div>';
		if ($canWrite && $shown['status'] === VereineBallotRules::STATUS_OPEN && in_array(VereineBallotRules::CHANNEL_PAPER, $shown['channels'], true) && $unused) {
			print '<div class="paddingtop">'.$langs->trans('VereineBallotPaper').' ';
			print $form('vereineballotpaper'.$id, 'paper', $id, $langs->trans('VereineBallotPaperButton'), Form::selectarray('right', $unused, '', 0, 0, 0, '', 0, 0, 0, '', 'maxwidth200')
				.' '.Form::selectarray('option', $labels, '', 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150', 0, '', 0, 1).' ');
			print '</div>';
		}
		if (in_array($shown['status'], array(VereineBallotRules::STATUS_CLOSED, VereineBallotRules::STATUS_EVALUATED), true)) {
			$tally = $ballots->tally($shown);
			print '<div class="paddingtop" data-ballot-valid="'.$tally['valid'].'"><strong>'.$langs->trans('VereineBallotTally').'</strong> ';
			foreach ($tally['counts'] as $code => $count) {
				print '<span class="paddingright" data-ballot-count="'.dol_escape_htmltag($code).'">'.$labels[$code].': '.$count.'</span> ';
			}
			print '</div>';
		}
	}
	// Each count with its proof: provisional until confirmed, replaced counts stay with their reason (#163).
	$counts = $ballots->results($id);
	if ($counts) {
		print '<div class="div-table-responsive-no-min paddingtop"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('VereineBallotCount').'</td>';
		print '<td>'.$langs->trans('VereineBallotProofOutcome').'</td><td>'.$langs->trans('VereineBallotProofTitle').'</td></tr>';
		foreach ($counts as $counted) {
			$outcome = $counted['snapshot']['outcome'];
			print '<tr class="oddeven" data-ballot-result="'.$counted['id'].'" data-ballot-result-status="'.$counted['status'].'" data-ballot-outcome="'.$outcome['outcome'].'"><td>';
			print $langs->trans('VereineBallotProofRevision', $counted['revision']).' · '.dol_print_date($counted['created'], 'dayhour').' · '.$langs->trans('VereineBallotResult_'.$counted['status']);
			print $counted['reason'] !== '' ? '<br><span class="opacitymedium small">'.dol_escape_htmltag($counted['reason']).'</span>' : '';
			print '</td><td>'.$langs->trans('VereineBallotOutcome_'.$outcome['outcome']).'</td><td>';
			if ($counted['filename'] !== '') {
				print '<a href="'.$self.'&action=download&ballot='.$id.'&result='.$counted['id'].'&token='.newToken().'">'.dol_escape_htmltag($counted['filename']).'</a>';
				print ' <span class="opacitymedium small" data-ballot-proof-sha="'.$counted['sha256'].'">'.substr($counted['sha256'], 0, 16).'…</span>';
			} elseif ($canWrite) {
				print $form('vereineballotproof'.$id, 'proof', $id, $langs->trans('VereineBallotProofBuild'), '<input type="hidden" name="result" value="'.$counted['id'].'">');
			}
			print '</td></tr>';
		}
		print '</table></div>';
		if ($canWrite && $counts[0]['status'] === VereineBallotRules::RESULT_PROVISIONAL) {
			print '<div class="paddingtop">'.$form('vereineballotconfirm'.$id, 'confirm', $id, $langs->trans('VereineBallotConfirm'));
			print $form('vereineballotreevaluate'.$id, 'reevaluate', $id, $langs->trans('VereineBallotReevaluate'),
				'<input type="text" name="reason" class="minwidth300" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('VereineBallotReevaluateReason')).'"> ');
			print '<br><span class="opacitymedium small">'.$langs->trans('VereineBallotConfirmHint').'</span></div>';
		}
	}
	print '</div>';
}

// A new ballot for an agenda item of a general assembly.
if ($canWrite && $meeting['kind'] !== VereineMeetingRules::KIND_BOARD) {
	$items = array();
	foreach (array_values($meeting['agenda']) as $index => $entry) {
		$items[$index + 1] = ($index + 1).'. '.(is_array($entry) && isset($entry['title']) ? $entry['title'] : (string) $entry);
	}
	$kinds = array();
	foreach (VereineVoteRules::KINDS as $kind) {
		$kinds[$kind] = $langs->trans('VereineVoteKind_'.$kind);
	}
	$functions = array('0' => '');
	foreach ((new VereineFunctions($db))->fetchAll(true) as $function) {
		$functions[$function['id']] = $function['label'];
	}
	$candidates = array();
	$resql = $db->query("SELECT rowid, firstname, lastname FROM ".MAIN_DB_PREFIX."adherent WHERE entity IN (".getEntity('adherent').") AND statut = 1 ORDER BY lastname, firstname");
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$candidates[(int) $obj->rowid] = trim($obj->firstname.' '.$obj->lastname);
	}
	print '<br>'.load_fiche_titre($langs->trans('VereineBallotNew'), '', '', 0, 'vereineballotnew');
	print '<form method="POST" action="'.$self.'" name="vereineballot"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="create">';
	print '<table class="border centpercent">';
	print '<tr><td class="titlefield fieldrequired">'.$langs->trans('VereineVoteItem').'</td><td>'.Form::selectarray('item', $items, '', 0, 0, 0, '', 0, 0, 0, '', 'maxwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('VereineVoteKind').'</td><td>'.Form::selectarray('kind', $kinds, '', 0).'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('VereineBallotQuestion').'</td><td><input type="text" name="question" class="minwidth400" maxlength="255"></td></tr>';
	print '<tr><td>'.$langs->trans('VereineBallotElection').'</td><td>'.Form::selectarray('function_id', $functions, '', 0, 0, 0, '', 0, 0, 0, '', 'maxwidth200').' ';
	print '<select name="candidates[]" multiple size="4" class="minwidth200">';
	foreach ($candidates as $candidateId => $name) {
		print '<option value="'.$candidateId.'">'.dol_escape_htmltag($name).'</option>';
	}
	print '</select><br><label><input type="checkbox" name="consent" value="1"> '.$langs->trans('VereineBallotConsent').'</label>';
	print '<br><span class="opacitymedium small">'.$langs->trans('VereineBallotElectionHint').'</span></td></tr>';
	print '<tr><td>'.$langs->trans('VereineBallotChannels').'</td><td>';
	foreach (VereineBallotRules::CHANNELS as $channel) {
		print '<label class="paddingright"><input type="checkbox" name="channels[]" value="'.$channel.'" checked> '.$langs->trans('VereineBallotChannel_'.$channel).'</label>';
	}
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('VereineBallotCloses').'</td><td><input type="time" name="closes"> <span class="opacitymedium small">'.$langs->trans('VereineBallotClosesHint').'</span></td></tr>';
	print '</table><div class="center paddingtop"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('VereineBallotCreate')).'"></div></form>';
}

llxFooter();
$db->close();
