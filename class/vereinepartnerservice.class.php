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
 * \file    class/vereinepartnerservice.class.php
 * \ingroup vereine
 * \brief   Members and their third parties in a running Dolibarr: link, create, keep consistent, report.
 *
 * Decisions come from VereinePartnerRules; this class reads and writes Dolibarr
 * with Dolibarr's own methods (Societe::create_from_member, Adherent::setThirdPartyId,
 * Categorie::add_type, CommonObject::setValueFrom) and logs every change.
 */

require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/vereinepartnerrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Link members and third parties and keep them consistent.
 */
class VereinePartnerService
{
	/** Category type ids of Dolibarr (Categorie::$MAP_ID). */
	const CATEGORY_CUSTOMER = 2;
	const CATEGORY_CONTACT = 4;

	/** Constants holding the ids of the module's categories. */
	const CONST_MEMBER = 'VEREINE_CATEGORY_MEMBER';
	const CONST_FORMER = 'VEREINE_CATEGORY_FORMER';
	const CONST_GUARDIAN = 'VEREINE_CATEGORY_GUARDIAN';

	/** @var DoliDB */
	public $db;

	/** @var string Last error */
	public $error = '';

	/** @var array<int,bool> Members being handled right now, against re-entry through triggers */
	private static $busy = array();

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	// ------------------------------------------------------------ categories

	/**
	 * Find or create the module's categories and remember their ids.
	 *
	 * Existing categories are kept - also when the association renamed them -
	 * because they are found by the id stored in the constants.
	 *
	 * @param User      $user  User who creates them
	 * @param Translate $langs Languages for the labels of new categories
	 * @return int 1 if OK, <0 on error
	 */
	public function ensureCategories($user, $langs)
	{
		global $conf;

		$langs->load('vereine@vereine');
		$wanted = array(
			self::CONST_MEMBER => array('customer', 'VereineCategoryMember'),
			self::CONST_FORMER => array('customer', 'VereineCategoryFormer'),
			self::CONST_GUARDIAN => array('contact', 'VereineCategoryGuardian'),
		);
		foreach ($wanted as $constant => $definition) {
			$id = getDolGlobalInt($constant);
			if ($id > 0) {
				$existing = new Categorie($this->db);
				if ($existing->fetch($id) > 0) {
					continue;
				}
			}
			$label = $langs->transnoentitiesnoconv($definition[1]);
			$category = new Categorie($this->db);
			if ($category->fetch(0, $label, $definition[0]) > 0) {
				$id = (int) $category->id;
			} else {
				$category = new Categorie($this->db);
				$category->label = $label;
				$category->type = $definition[0];
				$category->visible = 1;
				$id = $category->create($user);
				if ($id <= 0) {
					$this->error = 'Category '.$label.': '.$category->error;
					return -1;
				}
			}
			dolibarr_set_const($this->db, $constant, (string) $id, 'chaine', 0, '', $conf->entity);
			$conf->global->$constant = (string) $id;
		}
		return 1;
	}

	/**
	 * The child category of the member category for one member type, created on first use.
	 *
	 * @param int    $memberCategory Id of the member category
	 * @param string $typeLabel      Label of the member type
	 * @param User   $user           User
	 * @return int Category id, <=0 on error
	 */
	private function typeCategory($memberCategory, $typeLabel, $user)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."categorie";
		$sql .= " WHERE fk_parent = ".((int) $memberCategory)." AND type = ".self::CATEGORY_CUSTOMER;
		$sql .= " AND label = '".$this->db->escape($typeLabel)."'";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->rowid;
		}
		$category = new Categorie($this->db);
		$category->label = $typeLabel;
		$category->type = 'customer';
		$category->fk_parent = (int) $memberCategory;
		$category->visible = 1;
		return (int) $category->create($user);
	}

	/**
	 * Ids of the categories a third party is in, among the given ones.
	 *
	 * @param int   $socid      Third party id
	 * @param int[] $categories Category ids of interest
	 * @return int[]
	 */
	private function categoriesOf($socid, array $categories)
	{
		$categories = array_filter(array_map('intval', $categories));
		if (!$categories) {
			return array();
		}
		$found = array();
		$sql = "SELECT fk_categorie FROM ".MAIN_DB_PREFIX."categorie_societe";
		$sql .= " WHERE fk_soc = ".((int) $socid)." AND fk_categorie IN (".implode(',', $categories).")";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$found[] = (int) $obj->fk_categorie;
		}
		return $found;
	}

	/**
	 * Ids of the child categories of the member category.
	 *
	 * @return int[]
	 */
	private function typeCategories()
	{
		$member = getDolGlobalInt(self::CONST_MEMBER);
		if ($member <= 0) {
			return array();
		}
		$ids = array();
		$resql = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."categorie WHERE fk_parent = ".$member." AND type = ".self::CATEGORY_CUSTOMER);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$ids[] = (int) $obj->rowid;
		}
		return $ids;
	}

	// ------------------------------------------------------------- attributes

	/**
	 * Bring a linked third party in line with its member: categories, customer flag, customer type.
	 *
	 * Only what is needed changes; a customer type that differs is reported, not overwritten.
	 *
	 * @param Adherent $member  Member with fk_soc set
	 * @param User     $user    User
	 * @param bool     $deleted Whether the member is being deleted
	 * @return array{changes:string[],mismatch:bool}|int Array of what changed, <0 on error
	 */
	public function applyAttributes(Adherent $member, $user, $deleted = false)
	{
		$socid = (int) $member->fk_soc;
		if ($socid <= 0) {
			return array('changes' => array(), 'mismatch' => false);
		}
		$partner = new Societe($this->db);
		if ($partner->fetch($socid) <= 0) {
			$this->error = 'Third party '.$socid.' not found';
			return -1;
		}
		$changes = array();

		$memberCategory = getDolGlobalInt(self::CONST_MEMBER);
		$formerCategory = getDolGlobalInt(self::CONST_FORMER);
		$wanted = VereinePartnerRules::categoriesFor((int) $member->statut, !empty($member->datevalid), $deleted);
		if ($wanted !== null && getDolGlobalString('VEREINE_PARTNER_CATEGORIES', '1') === '1' && $memberCategory > 0 && $formerCategory > 0) {
			$typeCategories = $this->typeCategories();
			$wantedType = 0;
			if ($wanted['member'] && getDolGlobalString('VEREINE_PARTNER_CATEGORY_PER_TYPE') === '1' && !empty($member->type)) {
				$wantedType = $this->typeCategory($memberCategory, (string) $member->type, $user);
				if ($wantedType > 0 && !in_array($wantedType, $typeCategories, true)) {
					$typeCategories[] = $wantedType;
				}
			}
			$current = $this->categoriesOf($socid, array_merge(array($memberCategory, $formerCategory), $typeCategories));
			$plan = array($memberCategory => $wanted['member'], $formerCategory => $wanted['former']);
			foreach ($typeCategories as $typeCategory) {
				$plan[$typeCategory] = ($typeCategory === $wantedType);
			}
			foreach ($plan as $categoryId => $shouldHave) {
				$has = in_array((int) $categoryId, $current, true);
				if ($has === $shouldHave) {
					continue;
				}
				$category = new Categorie($this->db);
				if ($category->fetch((int) $categoryId) <= 0) {
					continue;
				}
				$result = $shouldHave ? $category->add_type($partner, 'customer') : $category->del_type($partner, 'customer');
				if ($result < 0 && $result !== -3) {
					$this->error = $category->error;
					return -1;
				}
				$changes[] = ($shouldHave ? '+' : '-').$category->label;
			}
		}

		if (!$deleted && (int) $member->statut === VereinePartnerRules::STATUS_VALIDATED) {
			$flag = VereinePartnerRules::customerFlag((int) $partner->client);
			if ($flag !== (int) $partner->client) {
				if ($partner->setValueFrom('client', $flag, '', null, 'int', '', $user) <= 0) {
					$this->error = $partner->error;
					return -1;
				}
				$changes[] = 'client='.$flag;
			}
		}

		$currentType = (string) dol_getIdFromCode($this->db, (int) $partner->typent_id, 'c_typent', 'id', 'code');
		$type = VereinePartnerRules::customerType(
			(string) $member->morphy,
			(int) $partner->typent_id > 0 ? $currentType : '',
			getDolGlobalString('VEREINE_PARTNER_TYPENT_NATURAL', 'TE_PRIVATE'),
			getDolGlobalString('VEREINE_PARTNER_TYPENT_LEGAL')
		);
		if (!$deleted && $type['set'] !== '') {
			$typeId = (int) dol_getIdFromCode($this->db, $type['set'], 'c_typent', 'code', 'id');
			if ($typeId > 0) {
				if ($partner->setValueFrom('fk_typent', $typeId, '', null, 'int', '', $user) <= 0) {
					$this->error = $partner->error;
					return -1;
				}
				$changes[] = 'typent='.$type['set'];
			}
		}

		if ($changes) {
			VereineLog::add($this->db, $user, VereineLog::PARTNER_ATTRIBUTES, (int) $member->id, $socid, implode(', ', $changes));
		}
		return array('changes' => $changes, 'mismatch' => $type['mismatch']);
	}

	// ----------------------------------------------------------- link, create

	/**
	 * Create a third party for a member with Dolibarr's own method, then set its attributes.
	 *
	 * @param Adherent $member Member without third party
	 * @param User     $user   User
	 * @return int Third party id, <0 on error
	 */
	public function createPartner(Adherent $member, $user)
	{
		if ((int) $member->fk_soc > 0) {
			$this->error = 'Member '.$member->ref.' already has a third party';
			return -1;
		}
		$GLOBALS['user'] = $user;
		$partner = new Societe($this->db);
		$socid = $partner->create_from_member($member);
		if ($socid <= 0) {
			$this->error = $partner->error.' '.implode(', ', (array) $partner->errors);
			return -1;
		}
		$member->fk_soc = (int) $socid;
		$member->socid = (int) $socid;
		VereineLog::add($this->db, $user, VereineLog::PARTNER_CREATED, (int) $member->id, (int) $socid, $partner->name);
		$result = $this->applyAttributes($member, $user);
		if (!is_array($result)) {
			return -1;
		}
		return (int) $socid;
	}

	/**
	 * Link a member to an existing third party, then set its attributes.
	 *
	 * @param Adherent $member Member
	 * @param int      $socid  Third party id
	 * @param User     $user   User
	 * @return int 1 if OK, <0 on error
	 */
	public function linkPartner(Adherent $member, $socid, $user)
	{
		$partner = new Societe($this->db);
		if ($partner->fetch((int) $socid) <= 0) {
			$this->error = 'Third party '.((int) $socid).' not found';
			return -1;
		}
		$linked = $this->memberOfPartner((int) $socid);
		if ($linked > 0 && $linked !== (int) $member->id) {
			$this->error = 'Third party '.$partner->name.' belongs to another member already';
			return -2;
		}
		if ($member->setThirdPartyId((int) $socid) <= 0) {
			$this->error = $member->error;
			return -1;
		}
		$member->fk_soc = (int) $socid;
		$member->socid = (int) $socid;
		VereineLog::add($this->db, $user, VereineLog::PARTNER_LINKED, (int) $member->id, (int) $socid, $partner->name);
		$result = $this->applyAttributes($member, $user);
		return is_array($result) ? 1 : -1;
	}

	/**
	 * Copy e-mail and address fields from the member to its third party.
	 *
	 * @param Adherent $member Member with fk_soc set
	 * @param string[] $fields Any of email, address, zip, town
	 * @param User     $user   User
	 * @return int Number of fields written, <0 on error
	 */
	public function copyToPartner(Adherent $member, array $fields, $user)
	{
		$partner = new Societe($this->db);
		if ((int) $member->fk_soc <= 0 || $partner->fetch((int) $member->fk_soc) <= 0) {
			$this->error = 'The member has no third party';
			return -1;
		}
		$written = array();
		foreach (array_intersect($fields, array('email', 'address', 'zip', 'town')) as $field) {
			if ($partner->setValueFrom($field, (string) $member->$field, '', null, 'text', '', $user) <= 0) {
				$this->error = $partner->error;
				return -1;
			}
			$written[] = $field;
		}
		if ($written) {
			$partner->fetch((int) $member->fk_soc);
			$partner->call_trigger('COMPANY_MODIFY', $user);
			VereineLog::add($this->db, $user, VereineLog::PARTNER_UPDATED, (int) $member->id, (int) $member->fk_soc, implode(', ', $written));
		}
		return count($written);
	}

	/**
	 * Correct the categories of a third party in the member category without an active membership.
	 *
	 * With a linked member the member's status decides; without one the member
	 * categories are removed and nothing else changes.
	 *
	 * @param int  $socid Third party id
	 * @param User $user  User
	 * @return int 1 if something changed, 0 if nothing to do, <0 on error
	 */
	public function releaseOrphan($socid, $user)
	{
		$memberId = $this->memberOfPartner((int) $socid);
		if ($memberId > 0) {
			$member = new Adherent($this->db);
			if ($member->fetch($memberId) <= 0) {
				$this->error = $member->error;
				return -1;
			}
			$result = $this->applyAttributes($member, $user);
			return is_array($result) ? ($result['changes'] ? 1 : 0) : -1;
		}
		$partner = new Societe($this->db);
		if ($partner->fetch((int) $socid) <= 0) {
			$this->error = 'Third party '.((int) $socid).' not found';
			return -1;
		}
		$removed = array();
		$memberCategory = getDolGlobalInt(self::CONST_MEMBER);
		foreach ($this->categoriesOf((int) $socid, array_merge(array($memberCategory), $this->typeCategories())) as $categoryId) {
			$category = new Categorie($this->db);
			if ($category->fetch($categoryId) > 0 && $category->del_type($partner, 'customer') >= 0) {
				$removed[] = '-'.$category->label;
			}
		}
		if ($removed) {
			VereineLog::add($this->db, $user, VereineLog::PARTNER_ATTRIBUTES, 0, (int) $socid, implode(', ', $removed));
		}
		return $removed ? 1 : 0;
	}

	// --------------------------------------------------------------- triggers

	/**
	 * A member was validated, resiliated, excluded, modified or is being deleted.
	 *
	 * Never fails the member's own action: problems are logged and reported to syslog.
	 *
	 * @param string   $action Trigger code
	 * @param Adherent $member Member
	 * @param User     $user   User
	 * @return int 1 when something was done, 0 otherwise
	 */
	public function onMemberEvent($action, Adherent $member, $user)
	{
		$id = (int) $member->id;
		if ($id <= 0 || !empty(self::$busy[$id])) {
			return 0;
		}
		self::$busy[$id] = true;
		try {
			if ($action === 'MEMBER_DELETE') {
				$result = $this->applyAttributes($member, $user, true);
			} elseif ((int) $member->fk_soc > 0) {
				$result = $this->applyAttributes($member, $user);
			} elseif ($action === 'MEMBER_VALIDATE' && getDolGlobalString('VEREINE_PARTNER_AUTOCREATE') === '1') {
				$result = $this->createOrSuggest($member, $user);
			} else {
				return 0;
			}
			if (!is_array($result) && (int) $result < 0) {
				dol_syslog('Vereine: '.$action.' for member '.$member->ref.': '.$this->error, LOG_WARNING);
				VereineLog::add($this->db, $user, VereineLog::PARTNER_ERROR, $id, (int) $member->fk_soc, dol_trunc($this->error, 250));
				return 0;
			}
			return 1;
		} finally {
			unset(self::$busy[$id]);
		}
	}

	/**
	 * Dolibarr's own member card linked, created or removed the third party of a member.
	 *
	 * Those actions write llx_adherent.fk_soc with plain SQL and no trigger; the
	 * module's hook calls this afterwards in the same request. A third party left
	 * without member loses the member categories, as on the reconciliation page.
	 * Never fails the page: problems are logged.
	 *
	 * @param Adherent $member   Member as loaded after Dolibarr's action
	 * @param int      $previous Third party linked before the action, 0 for none
	 * @param string   $how      'create' or 'link'
	 * @param User     $user     User
	 * @return int 1 when something was done, 0 otherwise
	 */
	public function onMemberCardLink(Adherent $member, $previous, $how, $user)
	{
		$id = (int) $member->id;
		$current = (int) $member->fk_soc;
		$previous = (int) $previous;
		if ($id <= 0 || $current === $previous || !empty(self::$busy[$id])) {
			return 0;
		}
		self::$busy[$id] = true;
		try {
			$ok = true;
			if ($previous > 0) {
				VereineLog::add($this->db, $user, VereineLog::PARTNER_UNLINKED, $id, $previous, $this->partnerName($previous));
				$ok = $this->memberOfPartner($previous) > 0 || $this->releaseOrphan($previous, $user) >= 0;
			}
			if ($ok && $current > 0) {
				VereineLog::add($this->db, $user, $how === 'create' ? VereineLog::PARTNER_CREATED : VereineLog::PARTNER_LINKED, $id, $current, $this->partnerName($current));
				$ok = is_array($this->applyAttributes($member, $user));
			}
			if (!$ok) {
				dol_syslog('Vereine: member card link for member '.$member->ref.': '.$this->error, LOG_WARNING);
				VereineLog::add($this->db, $user, VereineLog::PARTNER_ERROR, $id, $current, dol_trunc($this->error, 250));
				return 0;
			}
			return 1;
		} finally {
			unset(self::$busy[$id]);
		}
	}

	/**
	 * Name of a third party for the log.
	 *
	 * @param int $socid Third party id
	 * @return string
	 */
	private function partnerName($socid)
	{
		$resql = $this->db->query("SELECT nom FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".((int) $socid));
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (string) $obj->nom;
		}
		return '';
	}

	/**
	 * Create a third party for a newly validated member - unless one may already exist.
	 *
	 * @param Adherent $member Member without third party
	 * @param User     $user   User
	 * @return int|array<string,mixed> Third party id, 0 when only suggested, <0 on error
	 */
	private function createOrSuggest(Adherent $member, $user)
	{
		$partners = $this->partners();
		$candidates = VereinePartnerRules::candidates($this->memberData($member), $partners);
		if ($candidates) {
			$names = array();
			foreach ($candidates as $candidate) {
				$names[] = $partners[$candidate['id']]['name'].' ('.$candidate['match'].')';
			}
			VereineLog::add($this->db, $user, VereineLog::PARTNER_SUGGESTED, (int) $member->id, (int) $candidates[0]['id'], implode(', ', $names));
			return 0;
		}
		return $this->createPartner($member, $user);
	}

	// ----------------------------------------------------------------- report

	/**
	 * A member as the rules see it.
	 *
	 * @param Adherent|object $member Member object or database row
	 * @return array<string,mixed>
	 */
	public function memberData($member)
	{
		$birth = '';
		if (!empty($member->birth)) {
			$birth = is_numeric($member->birth) ? dol_print_date((int) $member->birth, '%Y-%m-%d') : substr((string) $member->birth, 0, 10);
		}
		return array(
			'id' => (int) (isset($member->id) ? $member->id : $member->rowid),
			'ref' => (string) $member->ref,
			'status' => (int) $member->statut,
			'morphy' => (string) $member->morphy,
			'firstname' => (string) $member->firstname,
			'lastname' => (string) $member->lastname,
			'company' => (string) (isset($member->company) && $member->company !== null ? $member->company : (isset($member->societe) ? $member->societe : '')),
			'email' => (string) $member->email,
			'address' => (string) $member->address,
			'zip' => (string) $member->zip,
			'town' => (string) $member->town,
			'birth' => $birth,
			'fk_soc' => (int) $member->fk_soc,
		);
	}

	/**
	 * Every third party of the current entity with what the rules compare.
	 *
	 * @return array<int,array<string,mixed>> Keyed by id
	 */
	public function partners()
	{
		$partners = array();
		$sql = "SELECT s.rowid, s.nom, s.email, s.address, s.zip, s.town, s.client, s.fk_typent, ty.code as typent_code, a.rowid as linked_member";
		$sql .= " FROM ".MAIN_DB_PREFIX."societe as s";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_typent as ty ON ty.id = s.fk_typent";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."adherent as a ON a.fk_soc = s.rowid";
		$sql .= " WHERE s.entity IN (".getEntity('societe').")";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$partners[(int) $obj->rowid] = array(
				'id' => (int) $obj->rowid,
				'name' => (string) $obj->nom,
				'email' => (string) $obj->email,
				'address' => (string) $obj->address,
				'zip' => (string) $obj->zip,
				'town' => (string) $obj->town,
				'client' => (int) $obj->client,
				'typent_code' => (int) $obj->fk_typent > 0 ? (string) $obj->typent_code : '',
				'linked_member' => (int) $obj->linked_member,
			);
		}
		return $partners;
	}

	/**
	 * The member linked to a third party.
	 *
	 * @param int $socid Third party id
	 * @return int Member id, 0 when none
	 */
	public function memberOfPartner($socid)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."adherent WHERE fk_soc = ".((int) $socid)." AND entity IN (".getEntity('adherent').")";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->rowid;
		}
		return 0;
	}

	/**
	 * Everything the reconciliation page shows, in one pass.
	 *
	 * @param string $today Day YYYY-MM-DD for the age check
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public function report($today)
	{
		$members = array();
		$sql = "SELECT a.rowid, a.ref, a.statut, a.morphy, a.firstname, a.lastname, a.societe, a.email, a.address, a.zip, a.town, a.birth, a.fk_soc, a.datevalid, t.libelle as type_label";
		$sql .= " FROM ".MAIN_DB_PREFIX."adherent as a";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."adherent_type as t ON t.rowid = a.fk_adherent_type";
		$sql .= " WHERE a.entity IN (".getEntity('adherent').")";
		$sql .= " ORDER BY a.lastname, a.firstname, a.rowid";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$data = $this->memberData($obj);
			$data['type'] = (string) $obj->type_label;
			$data['ever_validated'] = !empty($obj->datevalid);
			$members[$data['id']] = $data;
		}
		$partners = $this->partners();

		$memberCategory = getDolGlobalInt(self::CONST_MEMBER);
		$formerCategory = getDolGlobalInt(self::CONST_FORMER);
		$inCategory = array($memberCategory => array(), $formerCategory => array());
		if ($memberCategory > 0 && $formerCategory > 0) {
			$resql = $this->db->query("SELECT fk_categorie, fk_soc FROM ".MAIN_DB_PREFIX."categorie_societe WHERE fk_categorie IN (".$memberCategory.",".$formerCategory.")");
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$inCategory[(int) $obj->fk_categorie][(int) $obj->fk_soc] = true;
			}
		}
		$guarded = array();
		$guardian = getDolGlobalInt(self::CONST_GUARDIAN);
		if ($guardian > 0) {
			$sql = "SELECT DISTINCT sp.fk_soc FROM ".MAIN_DB_PREFIX."socpeople as sp";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."categorie_contact as cc ON cc.fk_socpeople = sp.rowid";
			$sql .= " WHERE cc.fk_categorie = ".$guardian." AND sp.statut = 1";
			$resql = $this->db->query($sql);
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$guarded[(int) $obj->fk_soc] = true;
			}
		}

		$report = array('without_partner' => array(), 'attributes' => array(), 'differences' => array(),
			'orphans' => array(), 'minors' => array(), 'duplicates' => array());
		$categoriesActive = getDolGlobalString('VEREINE_PARTNER_CATEGORIES', '1') === '1' && $memberCategory > 0 && $formerCategory > 0;
		$emailCount = array();
		foreach ($partners as $partner) {
			$email = VereinePartnerRules::normalizeEmail($partner['email']);
			if ($email !== '') {
				$emailCount[$email][] = $partner['id'];
			}
		}

		foreach ($members as $member) {
			$active = in_array($member['status'], array(VereinePartnerRules::STATUS_VALIDATED, VereinePartnerRules::STATUS_DRAFT), true);
			$partner = $member['fk_soc'] > 0 && isset($partners[$member['fk_soc']]) ? $partners[$member['fk_soc']] : null;

			if (!$partner && $active) {
				$member['candidates'] = VereinePartnerRules::candidates($member, $partners);
				foreach ($member['candidates'] as $index => $candidate) {
					$member['candidates'][$index]['name'] = $partners[$candidate['id']]['name'];
				}
				$report['without_partner'][] = $member;
			}

			if ($partner) {
				$problems = array();
				$wanted = VereinePartnerRules::categoriesFor($member['status'], $member['ever_validated']);
				if ($categoriesActive && $wanted !== null) {
					if ($wanted['member'] !== isset($inCategory[$memberCategory][$partner['id']])) {
						$problems[] = $wanted['member'] ? 'VereinePartnerProblemMemberCategoryMissing' : 'VereinePartnerProblemMemberCategoryExtra';
					}
					if ($wanted['former'] !== isset($inCategory[$formerCategory][$partner['id']])) {
						$problems[] = $wanted['former'] ? 'VereinePartnerProblemFormerCategoryMissing' : 'VereinePartnerProblemFormerCategoryExtra';
					}
				}
				if ($member['status'] === VereinePartnerRules::STATUS_VALIDATED && VereinePartnerRules::customerFlag($partner['client']) !== $partner['client']) {
					$problems[] = 'VereinePartnerProblemNotCustomer';
				}
				$type = VereinePartnerRules::customerType($member['morphy'], $partner['typent_code'],
					getDolGlobalString('VEREINE_PARTNER_TYPENT_NATURAL', 'TE_PRIVATE'), getDolGlobalString('VEREINE_PARTNER_TYPENT_LEGAL'));
				if ($type['set'] !== '') {
					$problems[] = 'VereinePartnerProblemTypeMissing';
				}
				if ($problems || $type['mismatch']) {
					$report['attributes'][] = array('member' => $member, 'partner' => $partner, 'problems' => $problems, 'type_mismatch' => $type['mismatch']);
				}
				$differences = VereinePartnerRules::differences($member, $partner);
				if ($differences) {
					$report['differences'][] = array('member' => $member, 'partner' => $partner, 'fields' => $differences);
				}
			}

			if ($member['status'] === VereinePartnerRules::STATUS_VALIDATED && $member['morphy'] !== 'mor'
				&& VereinePartnerRules::isMinor($member['birth'], $today) && ($member['fk_soc'] <= 0 || empty($guarded[$member['fk_soc']]))) {
				$report['minors'][] = $member;
			}

			$email = VereinePartnerRules::normalizeEmail($member['email']);
			if ($email !== '' && isset($emailCount[$email]) && count($emailCount[$email]) > 1) {
				$report['duplicates'][] = array('member' => $member, 'partners' => array_map(static function ($id) use ($partners) {
					return $partners[$id];
				}, $emailCount[$email]));
			}
		}

		if ($memberCategory > 0) {
			foreach (array_keys($inCategory[$memberCategory]) as $socid) {
				$linked = isset($partners[$socid]) ? $partners[$socid]['linked_member'] : 0;
				if (!$linked || !isset($members[$linked]) || $members[$linked]['status'] !== VereinePartnerRules::STATUS_VALIDATED) {
					if (isset($partners[$socid])) {
						$report['orphans'][] = array('partner' => $partners[$socid], 'member' => $linked && isset($members[$linked]) ? $members[$linked] : null);
					}
				}
			}
		}
		return $report;
	}
}
