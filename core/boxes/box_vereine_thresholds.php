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
 * \file    core/boxes/box_vereine_thresholds.php
 * \ingroup vereine
 * \brief   Home page box: the association's thresholds of the current year as a traffic light.
 */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

/**
 * Box with the small business limit and the limit for businesses harmful to tax privileges.
 */
class box_vereine_thresholds extends ModeleBoxes
{
	/**
	 * @var string Box code
	 */
	public $boxcode = 'vereine_thresholds';

	/**
	 * @var string Picto
	 */
	public $boximg = 'fa-landmark';

	/**
	 * @var string Label key
	 */
	public $boxlabel = 'VereineBoxThresholds';

	/**
	 * @var string[] Modules the box needs
	 */
	public $depends = array('vereine', 'facture');

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
		$this->hidden = !($user->hasRight('vereine', 'association', 'read') && $user->hasRight('facture', 'lire') && empty($user->socid));
	}

	/**
	 * Load the thresholds of the current year.
	 *
	 * @param int $max Not used
	 * @return void
	 */
	public function loadBox($max = 5)
	{
		global $langs, $user;

		dol_include_once('/vereine/class/vereinethresholdreport.class.php');
		dol_include_once('/vereine/lib/vereine.lib.php');
		$langs->load('vereine@vereine');
		$year = (int) dol_print_date(dol_now(), '%Y');
		$this->info_box_head = array('text' => $langs->trans('VereineThresholdsTitle', $year));

		if (!$user->hasRight('vereine', 'association', 'read') || !$user->hasRight('facture', 'lire')) {
			$this->info_box_contents[0][0] = array('td' => 'class="nohover opacitymedium"', 'text' => $langs->trans('ReadPermissionNotAllowed'));
			return;
		}
		$thresholdReport = new VereineThresholdReport($this->db);
		$line = 0;
		foreach (vereineThresholdRows($thresholdReport->report($year)) as $row) {
			$this->info_box_contents[$line][0] = array('td' => 'data-box-threshold="'.$row['code'].'" data-status="'.$row['status'].'"', 'text' => '<a href="'.dol_buildpath('/vereine/vereineindex.php', 1).'">'.$row['label'].'</a>', 'asis' => 1);
			$this->info_box_contents[$line][1] = array('td' => 'class="right nowraponall"', 'text' => $row['amount'].' / '.$row['limit'], 'asis' => 1);
			$this->info_box_contents[$line][2] = array('td' => 'class="right nowraponall"', 'text' => $row['badge'], 'asis' => 1);
			$line++;
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
