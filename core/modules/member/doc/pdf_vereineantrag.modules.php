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
 * \file    core/modules/member/doc/pdf_vereineantrag.modules.php
 * \ingroup vereine
 * \brief   Document template "Mitgliedsantrag" for Dolibarr's member card (#108).
 *
 * Dolibarr offers it under Documents on the member card, so the application can be built and sent
 * with Dolibarr's own means. The form itself is built by VereineMemberForm.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/member/modules_cards.php';
dol_include_once('/vereine/class/vereinememberform.class.php');

/**
 * The application for membership, filled in for a member.
 */
class pdf_vereineantrag extends ModelePDFCards
{
	/**
	 * @var string Dolibarr version of the loaded document
	 */
	public $version = 'dolibarr';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Name of the template, as llx_document_model knows it
	 */
	public $name = 'vereineantrag';

	/**
	 * @var string What the list of templates shows
	 */
	public $description = '';

	/**
	 * @var string Kind of document
	 */
	public $type = 'pdf';

	/**
	 * @var string Path of the file written last
	 */
	public $result = '';

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;

		$langs->loadLangs(array('main', 'vereine@vereine'));
		$this->db = $db;
		$this->description = $langs->trans('VereineApplicationTemplate');
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Write the application of a member into the member's document directory.
	 *
	 * @param Adherent                 $object          Member
	 * @param Translate                $outputlangs     Language
	 * @param string                   $srctemplatepath Not used
	 * @param int                      $hidedetails     Not used
	 * @param int                      $hidedesc        Not used
	 * @param int                      $hideref         Not used
	 * @param array<string,mixed>|null $moreparams      Not used
	 * @return int 1 when written, <0 on error
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)
	{
		// phpcs:enable
		global $conf, $langs, $user;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		if (!is_object($object) || empty($object->id)) {
			$this->error = 'no member';
			return -1;
		}
		$dir = rtrim($conf->adherent->dir_output.'/'.get_exdir(0, 0, 0, 1, $object, 'member'), '/');
		$file = $dir.'/mitgliedsantrag-'.dol_sanitizeFileName($object->ref ? $object->ref : (string) $object->id).'.pdf';
		$form = new VereineMemberForm($this->db);
		if ($form->build($object, (int) $object->typeid, $outputlangs, $file) === '') {
			$this->error = $form->error;
			return -1;
		}
		$this->result = $file;
		VereineLog::add($this->db, $user, VereineLog::APPLICATION_PDF, (int) $object->id, 0, basename($file));
		return 1;
	}
}
