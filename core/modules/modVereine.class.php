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
 * \file    core/modules/modVereine.class.php
 * \ingroup vereine
 * \brief   Descriptor of the Vereine module: associations under Austrian and German law.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Descriptor of the Vereine module.
 */
class modVereine extends DolibarrModules
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;

		$this->db = $db;

		// Reserved for IT-Tabelander on https://wiki.dolibarr.org/index.php?title=List_of_modules_id
		// (range 492100 - 492109). Permission ids derive from it, so it must never change.
		$this->numero = 492100;
		$this->rights_class = 'vereine';
		// Next to the Members module, which this module extends.
		$this->family = 'hr';
		$this->module_position = '07';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleVereineDesc';
		$this->descriptionlong = 'ModuleVereineDescLong';
		$this->editor_name = 'IT-Tabelander';
		$this->editor_url = 'https://it.tabelander.co.at';
		$this->version = '0.1.0-beta';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-landmark';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
		);

		$this->dirs = array('/vereine/temp');
		$this->config_page_url = array('setup.php@vereine');
		$this->hidden = false;
		$this->depends = array('modAdherent');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('vereine@vereine');
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(22, 0);
		$this->need_javascript_ajax = 0;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// The association data lives in constants written by the setup page. The
		// country profile gets its default in init(), from the company's country.
		$this->const = array();

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.'01';
		$this->rights[$r][1] = 'Read the association overview and its data (also through the API)';
		$this->rights[$r][4] = 'association';
		$this->rights[$r][5] = 'read';
		$r++;

		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=members',
			'type' => 'left',
			'titre' => 'VereineMenuAssociation',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle paddingright"'),
			'mainmenu' => 'members',
			'leftmenu' => 'vereine',
			'url' => '/vereine/vereineindex.php',
			'langs' => 'vereine@vereine',
			'position' => 1100 + $r,
			'enabled' => 'isModEnabled("vereine")',
			'perms' => '$user->hasRight("vereine", "association", "read")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Enable the module: register constants, rights and menus.
	 *
	 * @param string $options Options when enabling the module ('', 'noboxes')
	 * @return int<-1,1> 1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dol_include_once('/vereine/class/vereineprofile.class.php');

		$this->remove($options);

		$sql = array();
		$result = $this->_init($sql, $options);
		if ($result <= 0) {
			return $result;
		}

		// A first activation picks the profile of the company's country. A later
		// re-activation keeps whatever the association chose on the setup page.
		if (getDolGlobalString('VEREINE_COUNTRY_PROFILE') === '') {
			$countryCode = (is_object($mysoc) && !empty($mysoc->country_code)) ? $mysoc->country_code : '';
			dolibarr_set_const($this->db, 'VEREINE_COUNTRY_PROFILE', VereineProfile::suggestFromCountry($countryCode), 'chaine', 0, '', $conf->entity);
		}

		return $result;
	}

	/**
	 * Disable the module. Association data stays, so a re-activation finds it again.
	 *
	 * @param string $options Options when disabling the module ('', 'noboxes')
	 * @return int<-1,1> 1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
