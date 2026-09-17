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
 * \file    core/modules/mailings/vereine.modules.php
 * \ingroup vereine
 * \brief   Recipients for Dolibarr's e-mail campaigns: members by status, type, function and consent, minors through guardians.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/mailings/modules_mailings.php';
dol_include_once('/vereine/class/vereinemailingrules.class.php');
dol_include_once('/vereine/class/vereinefunctions.class.php');
dol_include_once('/vereine/class/vereineconsents.class.php');

/**
 * Recipient selector "Association members" for e-mail campaigns.
 */
class mailing_vereine extends MailingTargets
{
	/** @var string Name, translated through the language key of the same name */
	public $name = 'VereineMailingTargets';
	/** @var string Description when the translation is missing */
	public $desc = 'Association members by status, type, function and consent';
	/** @var int Available for every user who may create campaigns */
	public $require_admin = 0;
	/** @var string[] Needs Dolibarr's members */
	public $require_module = array('adherent');
	/** @var string Shown only while the Vereine module is on */
	public $enabled = 'isModEnabled("vereine")';
	/** @var string Icon */
	public $picto = 'user';

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;

		$this->db = $db;
		$langs->load('vereine@vereine');
	}

	/**
	 * Statistics on the campaign area: active members with e-mail address.
	 *
	 * @return string[]
	 */
	public function getSqlArrayForStats()
	{
		global $langs;

		$sql = "SELECT '".$this->db->escape($langs->trans('VereineMailingTargets'))."' as label, count(*) as nb FROM ".MAIN_DB_PREFIX."adherent";
		$sql .= " WHERE statut = 1 AND email <> '' AND entity IN (".getEntity('member').")";
		return array($sql);
	}

	/**
	 * Members with e-mail address, before any filter.
	 *
	 * @param string $sql Not used
	 * @return int|string
	 */
	public function getNbOfRecipients($sql = '')
	{
		$sql = "SELECT count(distinct(a.email)) as nb FROM ".MAIN_DB_PREFIX."adherent as a";
		$sql .= " WHERE a.statut = 1 AND a.email IS NOT NULL AND a.email <> '' AND a.entity IN (".getEntity('member').")";
		return parent::getNbOfRecipients($sql);
	}

	/**
	 * Filter of the selector: status, member type, function, purpose and guardians.
	 *
	 * @return string HTML
	 */
	public function formFilter()
	{
		global $langs;

		$langs->loadLangs(array('members', 'vereine@vereine'));
		$s = '<select name="vereine_status" id="vereine_status" class="flat">';
		foreach (VereineMailingRules::STATUSES as $status) {
			$s .= '<option value="'.$status.'"'.($status === VereineMailingRules::STATUS_ACTIVE ? ' selected' : '').'>'.$langs->trans('VereineMailingStatus_'.$status).'</option>';
		}
		$s .= '</select> ';

		$s .= '<select name="vereine_type" id="vereine_type" class="flat"><option value="0">'.$langs->trans('VereineMailingAllTypes').'</option>';
		$resql = $this->db->query("SELECT rowid, libelle FROM ".MAIN_DB_PREFIX."adherent_type WHERE entity IN (".getEntity('member_type').") ORDER BY libelle");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$s .= '<option value="'.((int) $obj->rowid).'">'.dol_escape_htmltag($obj->libelle).'</option>';
		}
		$s .= '</select> ';

		$functions = new VereineFunctions($this->db);
		$s .= '<select name="vereine_function" id="vereine_function" class="flat"><option value="">'.$langs->trans('VereineMailingAnyFunction').'</option>';
		$s .= '<option value="'.VereineMailingRules::FUNCTION_BOARD.'">'.$langs->trans('VereineMailingBoard').'</option>';
		foreach ($functions->fetchAll(true) as $function) {
			$s .= '<option value="'.dol_escape_htmltag($function['code']).'">'.dol_escape_htmltag($function['label']).'</option>';
		}
		$s .= '</select><br>';

		$consents = new VereineConsents($this->db);
		$texts = $consents->currentTexts();
		$default = isset($texts['newsletter']) ? 'newsletter' : VereineMailingRules::PURPOSE_INFO;
		$s .= '<select name="vereine_purpose" id="vereine_purpose" class="flat">';
		$s .= '<option value="'.VereineMailingRules::PURPOSE_INFO.'"'.($default === VereineMailingRules::PURPOSE_INFO ? ' selected' : '').'>'.$langs->trans('VereineMailingPurposeInfo').'</option>';
		foreach ($texts as $code => $text) {
			$s .= '<option value="'.dol_escape_htmltag($code).'"'.($default === $code ? ' selected' : '').'>'.$langs->trans('VereineMailingPurposeConsent', dol_escape_htmltag($text['label'])).'</option>';
		}
		$s .= '</select> ';
		$s .= '<label><input type="checkbox" name="vereine_guardians" value="1"> '.$langs->trans('VereineMailingGuardians').'</label>';
		return $s;
	}

	/**
	 * Link to the source of a recipient.
	 *
	 * @param int $id Member id
	 * @return string
	 */
	public function url($id)
	{
		return '<a href="'.DOL_URL_ROOT.'/adherents/card.php?rowid='.((int) $id).'">'.img_object('', 'user').'</a>';
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Add the members the filter selects to a campaign.
	 *
	 * @param int $mailing_id Campaign id
	 * @return int Number added, <0 on error
	 */
	public function add_to_target($mailing_id)
	{
		// phpcs:enable
		$filter = VereineMailingRules::normalize(array(
			'status' => GETPOST('vereine_status', 'aZ09'),
			'type_id' => GETPOSTINT('vereine_type'),
			'function' => GETPOST('vereine_function', 'aZ09'),
			'purpose' => GETPOST('vereine_purpose', 'aZ09'),
			'guardians' => GETPOSTINT('vereine_guardians') === 1,
		));
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$members = $this->members($filter, $today);
		if ($members === null) {
			return -1;
		}
		$targets = array();
		foreach (VereineMailingRules::recipients($members, $filter, $today) as $recipient) {
			$targets[] = array(
				'email' => $recipient['email'],
				'fk_contact' => $recipient['contact_id'],
				'lastname' => $recipient['lastname'],
				'firstname' => $recipient['firstname'],
				'other' => '',
				'source_url' => $this->url($recipient['member_id']),
				'source_id' => $recipient['member_id'],
				'source_type' => $recipient['contact_id'] > 0 ? 'contact' : 'member',
			);
		}
		return parent::addTargetsToDatabase($mailing_id, $targets);
	}

	/**
	 * Members with what the filter needs: functions today, consents, guardians.
	 *
	 * @param array<string,mixed> $filter Normalised filter
	 * @param string              $today  Today
	 * @return array<int,array<string,mixed>>|null Null on error
	 */
	private function members(array $filter, $today)
	{
		$sql = "SELECT d.rowid, d.statut, d.fk_adherent_type, d.email, d.firstname, d.lastname, d.birth, d.fk_soc FROM ".MAIN_DB_PREFIX."adherent as d";
		$sql .= " WHERE d.entity IN (".getEntity('member').") ORDER BY d.lastname, d.firstname, d.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$members = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$members[(int) $obj->rowid] = array('id' => (int) $obj->rowid, 'status' => (int) $obj->statut, 'type_id' => (int) $obj->fk_adherent_type,
				'email' => (string) $obj->email, 'firstname' => (string) $obj->firstname, 'lastname' => (string) $obj->lastname,
				'birth' => $obj->birth ? substr((string) $obj->birth, 0, 10) : '', 'socid' => (int) $obj->fk_soc,
				'functions' => array(), 'board' => false, 'consents' => array(), 'guardians' => array());
		}
		$this->db->free($resql);

		$store = new VereineFunctions($this->db);
		$catalogue = array();
		foreach ($store->fetchAll(true) as $function) {
			$catalogue[$function['id']] = $function;
		}
		foreach ($store->terms() as $term) {
			if (isset($members[$term['member_id']], $catalogue[$term['function_id']]) && VereineFunctionRules::isActive($term, $today)) {
				$members[$term['member_id']]['functions'][] = $catalogue[$term['function_id']]['code'];
				$members[$term['member_id']]['board'] = $members[$term['member_id']]['board'] || $catalogue[$term['function_id']]['board'];
			}
		}

		if ($filter['purpose'] !== VereineMailingRules::PURPOSE_INFO) {
			$consents = new VereineConsents($this->db);
			foreach ($consents->givenBy(array_keys($members), $filter['purpose']) as $memberId => $given) {
				if ($given) {
					$members[$memberId]['consents'][] = $filter['purpose'];
				}
			}
		}

		$category = getDolGlobalInt('VEREINE_CATEGORY_GUARDIAN');
		if ($filter['guardians'] && $category > 0) {
			$socids = array_filter(array_column($members, 'socid'));
			if ($socids) {
				$sql = "SELECT c.rowid, c.fk_soc, c.email, c.firstname, c.lastname FROM ".MAIN_DB_PREFIX."socpeople as c";
				$sql .= " INNER JOIN ".MAIN_DB_PREFIX."categorie_contact as cc ON cc.fk_socpeople = c.rowid AND cc.fk_categorie = ".((int) $category);
				$sql .= " WHERE c.statut = 1 AND c.email <> '' AND c.fk_soc IN (".implode(', ', array_map('intval', array_unique($socids))).")";
				$resql = $this->db->query($sql);
				$bySoc = array();
				while ($resql && ($obj = $this->db->fetch_object($resql))) {
					$bySoc[(int) $obj->fk_soc][] = array('contact_id' => (int) $obj->rowid, 'email' => (string) $obj->email, 'firstname' => (string) $obj->firstname, 'lastname' => (string) $obj->lastname);
				}
				foreach ($members as $memberId => $member) {
					if (isset($bySoc[$member['socid']])) {
						$members[$memberId]['guardians'] = $bySoc[$member['socid']];
					}
				}
			}
		}
		return array_values($members);
	}
}
