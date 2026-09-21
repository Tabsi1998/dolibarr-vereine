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
 * \file    core/boxes/box_vereine_waiting.php
 * \ingroup vereine
 * \brief   Home page box: what waits for me - votes, signatures, tasks.
 */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

/**
 * Box of what waits for the logged-in user.
 */
class box_vereine_waiting extends ModeleBoxes
{
	/**
	 * @var string Code of the box
	 */
	public $boxcode = 'vereine_waiting';

	/**
	 * @var string Picto of the box
	 */
	public $boximg = 'fa-inbox';

	/**
	 * @var string Label of the box
	 */
	public $boxlabel = 'VereineBoxWaiting';

	/**
	 * @var string[] Modules the box depends on
	 */
	public $depends = array('vereine', 'adherent');

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db    Database handler
	 * @param string $param More parameters
	 */
	public function __construct($db, $param = '')
	{
		global $user;

		$this->db = $db;
		$this->hidden = !($user->hasRight('vereine', 'association', 'read') && $user->hasRight('adherent', 'lire') && empty($user->socid));
	}

	/**
	 * Load what waits for the user, through the member the user is linked to.
	 *
	 * @param int $max Most lines per kind
	 * @return void
	 */
	public function loadBox($max = 5)
	{
		global $langs, $user;

		dol_include_once('/vereine/class/vereinewaiting.class.php');
		dol_include_once('/vereine/lib/vereine.lib.php');
		$langs->load('vereine@vereine');
		$this->info_box_head = array('text' => $langs->trans('VereineBoxWaiting'));

		if ((int) $user->fk_member < 1) {
			$this->info_box_contents[0][0] = array('td' => 'class="nohover opacitymedium" data-box-waiting="nomember"', 'text' => $langs->trans('VereineBoxWaitingNoMember'));
			return;
		}
		$waiting = new VereineWaiting($this->db);
		$open = $waiting->forMember((int) $user->fk_member);
		$line = 0;
		foreach (array('votes' => 'VereineBoxWaitingVote', 'signatures' => 'VereineBoxWaitingSignature', 'tasks' => 'VereineBoxWaitingTask') as $kind => $label) {
			foreach (array_slice($open[$kind], 0, max(1, (int) $max)) as $entry) {
				$when = isset($entry['deadline']) && $entry['deadline'] !== '' ? $langs->trans('VereineBoxWaitingUntil', vereineFormatDay($entry['deadline'])) : '';
				$this->info_box_contents[$line][0] = array('td' => 'data-box-waiting="'.$kind.'"', 'asis' => 1,
					'text' => '<a href="'.$entry['url'].'" class="valignmiddle">'.$langs->trans($label).': '.dol_escape_htmltag($entry['title']).'</a>');
				$this->info_box_contents[$line][1] = array('td' => 'class="right nowraponall"', 'text' => $when);
				$line++;
			}
		}
		if ($line === 0) {
			$this->info_box_contents[0][0] = array('td' => 'class="nohover opacitymedium" data-box-waiting="none"', 'text' => $langs->trans('VereineBoxWaitingNone'));
		}
	}

	/**
	 * Print the box.
	 *
	 * @param array<array{text?:string,sublink?:string,subpicto:?string,nbcol?:int,limit?:int,subclass?:string,graph?:string}>|null $head     Head
	 * @param array<array<array{tr?:string,td?:string,target?:string,text?:string,text2?:string,textnoformat?:string,tooltip?:string,logo?:string,url?:string,maxlength?:string,asis?:string,asis2?:string}>>|null $contents Contents
	 * @param int                                                                                                                  $nooutput Only return the HTML
	 * @return string
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
	}
}
