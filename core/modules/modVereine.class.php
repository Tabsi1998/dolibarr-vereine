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

		// Taken from the editors' range of https://wiki.dolibarr.org/index.php?title=List_of_modules_id
		// (492100 - 492109, free when chosen; the reservation is issue #2). Permission ids
		// derive from it, so it must never change.
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
		$this->version = '0.5.6-beta';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-landmark';

		$this->module_parts = array(
			'triggers' => 1,
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
			// class/actions_vereine.class.php: the member card links third parties without a trigger;
			// invoice cards report lines whose VAT rate differs from their tax profile; invoice PDFs
			// get the tax profile notes and the register number.
			'hooks' => array('data' => array('membercard', 'invoicecard', 'invoicesuppliercard', 'pdfgeneration'), 'entity' => '0'),
			'moduleforexternal' => 0,
		);

		$this->dirs = array('/vereine/temp');
		$this->config_page_url = array('setup.php@vereine');
		$this->hidden = false;
		// Members, third parties and categories are Dolibarr's; the module links them.
		$this->depends = array('modAdherent', 'modSociete', 'modCategorie');
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
		// Defaults below are written once and never overwrite a saved choice.
		$this->const = array(
			array('VEREINE_PARTNER_AUTOCREATE', 'chaine', '0', 'Create a third party when a member is validated', 0, 'current', 0),
			array('VEREINE_PARTNER_CATEGORIES', 'chaine', '1', 'Keep the member categories of third parties in line with the member status', 0, 'current', 0),
			array('VEREINE_PARTNER_CATEGORY_PER_TYPE', 'chaine', '0', 'Add a sub-category per member type', 0, 'current', 0),
			array('VEREINE_PARTNER_TYPENT_NATURAL', 'chaine', 'TE_PRIVATE', 'Customer type for natural persons, set only when empty', 0, 'current', 0),
			array('VEREINE_PARTNER_TYPENT_LEGAL', 'chaine', '', 'Customer type for legal entities, set only when empty', 0, 'current', 0),
			array('VEREINE_PDF_TAX_NOTES', 'chaine', '1', 'Print the invoice notes of the tax profiles on invoice PDFs', 0, 'current', 0),
			array('VEREINE_PDF_REGISTER', 'chaine', '1', 'Print the register number (ZVR) on invoice PDFs', 0, 'current', 0),
		);

		$this->tabs = array();
		$this->tabs[] = array('data' => 'thirdparty:+vereinemembership:VereineTabMembership:vereine@vereine:$user->hasRight("vereine", "association", "read") && $user->hasRight("adherent", "lire"):/vereine/partner_membership.php?socid=__ID__');
		$this->tabs[] = array('data' => 'member:+vereineassociation:VereineTabAssociation:vereine@vereine:$user->hasRight("vereine", "association", "read") && $user->hasRight("adherent", "lire") && $user->hasRight("societe", "lire"):/vereine/member_association.php?id=__ID__');
		$this->dictionaries = array();
		// Thresholds of the current year on the home page, for users who may read invoices.
		$this->boxes = array(
			0 => array('file' => 'box_vereine_thresholds.php@vereine', 'note' => '', 'enabledbydefaulton' => 'Home'),
		);
		// Planned exits take effect on their last day; needs Dolibarr's module Scheduled jobs.
		$this->cronjobs = array(
			0 => array(
				'label' => 'VereineCronExits',
				'jobtype' => 'method',
				'class' => '/vereine/class/vereineexits.class.php',
				'objectname' => 'VereineExits',
				'method' => 'runDue',
				'parameters' => '',
				'comment' => 'VereineCronExitsHelp',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 1,
				'test' => 'isModEnabled("vereine")',
				'priority' => 50,
			),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.'01';
		$this->rights[$r][1] = 'Read the association overview and its data (also through the API)';
		$this->rights[$r][4] = 'association';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.'02';
		$this->rights[$r][1] = 'Link members and third parties and bring them in line';
		$this->rights[$r][4] = 'partner';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero.'03';
		$this->rights[$r][1] = 'Read member summaries for a website through the API: membership, fee and open invoices';
		$this->rights[$r][4] = 'website';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.'04';
		$this->rights[$r][1] = 'Send membership applications through the API: members in draft with consents';
		$this->rights[$r][4] = 'application';
		$this->rights[$r][5] = 'write';
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
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=members,fk_leftmenu=vereine',
			'type' => 'left',
			'titre' => 'VereineMenuPartners',
			'mainmenu' => 'members',
			'leftmenu' => 'vereine_partners',
			'url' => '/vereine/partners.php',
			'langs' => 'vereine@vereine',
			'position' => 1100 + $r,
			'enabled' => 'isModEnabled("vereine")',
			'perms' => '$user->hasRight("vereine", "association", "read") && $user->hasRight("adherent", "lire") && $user->hasRight("societe", "lire")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=members,fk_leftmenu=vereine',
			'type' => 'left',
			'titre' => 'VereineMenuFeeRun',
			'mainmenu' => 'members',
			'leftmenu' => 'vereine_feerun',
			'url' => '/vereine/fees_run.php',
			'langs' => 'vereine@vereine',
			'position' => 1100 + $r,
			'enabled' => 'isModEnabled("vereine")',
			'perms' => '$user->hasRight("vereine", "association", "read") && $user->hasRight("adherent", "lire")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=members,fk_leftmenu=vereine',
			'type' => 'left',
			'titre' => 'VereineMenuFunctions',
			'mainmenu' => 'members',
			'leftmenu' => 'vereine_functions',
			'url' => '/vereine/functions.php',
			'langs' => 'vereine@vereine',
			'position' => 1100 + $r,
			'enabled' => 'isModEnabled("vereine")',
			'perms' => '$user->hasRight("vereine", "association", "read") && $user->hasRight("adherent", "lire")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=members,fk_leftmenu=vereine',
			'type' => 'left',
			'titre' => 'VereineMenuMeetings',
			'mainmenu' => 'members',
			'leftmenu' => 'vereine_meetings',
			'url' => '/vereine/meetings.php',
			'langs' => 'vereine@vereine',
			'position' => 1100 + $r,
			'enabled' => 'isModEnabled("vereine")',
			'perms' => '$user->hasRight("vereine", "association", "read") && $user->hasRight("adherent", "lire")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=members,fk_leftmenu=vereine',
			'type' => 'left',
			'titre' => 'VereineMenuLetters',
			'mainmenu' => 'members',
			'leftmenu' => 'vereine_authority',
			'url' => '/vereine/authority.php',
			'langs' => 'vereine@vereine',
			'position' => 1100 + $r,
			'enabled' => 'isModEnabled("vereine")',
			'perms' => '$user->hasRight("vereine", "association", "read") && $user->hasRight("adherent", "lire")',
			'target' => '',
			'user' => 0,
		);
		// The same setup page as in the module list, for administrators who work in Members.
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=members,fk_leftmenu=vereine',
			'type' => 'left',
			'titre' => 'VereineMenuPartnerSetup',
			'mainmenu' => 'members',
			'leftmenu' => 'vereine_partnersetup',
			'url' => '/vereine/admin/partners.php',
			'langs' => 'vereine@vereine',
			'position' => 1100 + $r,
			'enabled' => 'isModEnabled("vereine")',
			'perms' => '$user->admin',
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
		global $conf, $mysoc, $user, $langs;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dol_include_once('/vereine/class/vereineprofile.class.php');

		// Tables are created once and kept when the module is disabled, so an
		// update or a re-activation never loses the log.
		$result = $this->_load_tables('/vereine/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		$sql = array();
		$result = $this->_init($sql, $options);
		if ($result <= 0) {
			return $result;
		}

		// Not gated on isModEnabled(): the Third parties and Categories modules this
		// module depends on are enabled in the same request, and $conf does not show
		// them yet. Their tables belong to every Dolibarr installation.
		dol_include_once('/vereine/class/vereinepartnerservice.class.php');
		$service = new VereinePartnerService($this->db);
		if ($service->ensureCategories($user, $langs) < 0) {
			$this->error = $service->error;
			dol_syslog('modVereine::init '.$service->error, LOG_ERR);
		}

		// Standard tax profiles arrive as suggestions; a profile the association
		// changed keeps its code, so it is never replaced.
		dol_include_once('/vereine/class/vereinetaxprofiles.class.php');
		$langs->load('vereine@vereine');
		$taxProfiles = new VereineTaxProfiles($this->db);
		if ($taxProfiles->ensureStandard($langs, $user) < 0) {
			$this->error = $taxProfiles->error;
			dol_syslog('modVereine::init '.$taxProfiles->error, LOG_ERR);
		}
		// The extra field "tax profile" on products and invoice lines; kept when the module is disabled.
		dol_include_once('/vereine/class/vereinetaxassign.class.php');
		$assign = new VereineTaxAssign($this->db);
		if ($assign->ensureFields() < 0) {
			$this->error = $assign->error;
			dol_syslog('modVereine::init '.$assign->error, LOG_ERR);
		}
		// The fee model as extra fields on Dolibarr's member type; kept when the module is disabled.
		dol_include_once('/vereine/class/vereinefeemodel.class.php');
		$feeModel = new VereineFeeModel($this->db);
		if ($feeModel->ensureFields() < 0) {
			$this->error = $feeModel->error;
			dol_syslog('modVereine::init '.$feeModel->error, LOG_ERR);
		}
		// Member fields for fee exemption and proof of a discount; kept when the module is disabled.
		dol_include_once('/vereine/class/vereinefeediscountstore.class.php');
		$discountStore = new VereineFeeDiscountStore($this->db);
		if ($discountStore->ensureFields() < 0) {
			$this->error = $discountStore->error;
			dol_syslog('modVereine::init '.$discountStore->error, LOG_ERR);
		}
		// The functions suggested for Austria; changed or switched off ones stay as the association left them.
		dol_include_once('/vereine/class/vereinefunctions.class.php');
		$functions = new VereineFunctions($this->db);
		if ($functions->ensureStandard() < 0 || $functions->ensureFields() < 0) {
			$this->error = $functions->error;
			dol_syslog('modVereine::init '.$functions->error, LOG_ERR);
		}
		// Member field for the third party that pays the fees of a family; kept when the module is disabled.
		dol_include_once('/vereine/class/vereinefeefamilystore.class.php');
		$familyStore = new VereineFeeFamilyStore($this->db);
		if ($familyStore->ensureFields() < 0) {
			$this->error = $familyStore->error;
			dol_syslog('modVereine::init '.$familyStore->error, LOG_ERR);
		}
		// The slim event for website webhooks, so a webhook target can choose it.
		dol_include_once('/vereine/class/vereinewebsiteevents.class.php');
		if (VereineWebsiteEvents::ensureTriggerCode($this->db) < 0) {
			dol_syslog('modVereine::init adding '.VereineWebsiteEvents::TRIGGER_CODE.': '.$this->db->lasterror(), LOG_ERR);
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
