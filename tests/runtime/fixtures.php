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
 * \file    tests/runtime/fixtures.php
 * \ingroup vereine
 * \brief   Prepare a fresh Dolibarr for the runtime checks.
 *
 * php fixtures.php base    company, Members and API modules, two users with API keys
 * php fixtures.php rights  after the module is enabled: the reader gets the read right
 * php fixtures.php readmembers  the reader may also read, not change, members and third parties
 *
 * Prints one JSON object. Passwords and API keys come from the environment only.
 */

require __DIR__.'/bootstrap.php';

global $db, $conf;

$stage = isset($argv[1]) ? $argv[1] : '';
$admin = rt_admin($db);
$GLOBALS['user'] = $admin;

if ($stage === 'base') {
	rt_const($db, 'MAIN_LANG_DEFAULT', 'de_DE');
	rt_const($db, 'MAIN_MONNAIE', 'EUR');
	rt_const($db, 'MAIN_INFO_SOCIETE_ADDRESS', 'Teststraße 1');
	rt_const($db, 'MAIN_INFO_SOCIETE_ZIP', '6020');
	rt_const($db, 'MAIN_INFO_SOCIETE_TOWN', 'Innsbruck');
	rt_const($db, 'MAIN_INFO_SOCIETE_MAIL', 'office@runtime-verein.test');

	foreach (array('modAdherent', 'modApi') as $module) {
		$result = activateModule($module);
		if (!empty($result['errors'])) {
			rt_fail('activating '.$module.' failed: '.implode(' | ', (array) $result['errors']));
		}
	}

	$users = array();
	foreach (array('rtreader' => 'RT_READER', 'rtnobody' => 'RT_NOBODY') as $login => $prefix) {
		$new = new User($db);
		$new->login = $login;
		$new->lastname = ucfirst($login);
		$new->firstname = 'Runtime';
		$new->email = $login.'@runtime-verein.test';
		$new->admin = 0;
		$new->entity = 1;
		if ($new->create($admin) <= 0) {
			rt_fail('user '.$login.': '.$new->error);
		}
		if ($new->setPassword($admin, rt_env($prefix.'_PASSWORD')) === -1) {
			rt_fail('password for '.$login.': '.$new->error);
		}
		$new->fetch($new->id);
		$new->api_key = rt_env($prefix.'_KEY');
		if ($new->update($admin) <= 0) {
			rt_fail('API key for '.$login.': '.$new->error);
		}
		$users[$login] = (int) $new->id;
	}

	print json_encode(array(
		'dolibarr' => DOL_VERSION,
		'php' => PHP_VERSION,
		'users' => $users,
	))."\n";
	exit(0);
}

if ($stage === 'rights') {
	$rightId = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module = 'vereine' AND perms = 'association' AND subperms = 'read' AND entity = 1");
	if ($rightId <= 0) {
		rt_fail('the right vereine/association/read is not registered');
	}
	$reader = new User($db);
	if ($reader->fetch(0, 'rtreader') <= 0) {
		rt_fail('the user rtreader does not exist');
	}
	if ($reader->addrights($rightId) < 0) {
		rt_fail('granting the read right: '.$reader->error);
	}
	print json_encode(array('right' => $rightId))."\n";
	exit(0);
}

// The reader may read members and third parties, but change neither.
if ($stage === 'readmembers') {
	$reader = new User($db);
	if ($reader->fetch(0, 'rtreader') <= 0) {
		rt_fail('the user rtreader does not exist');
	}
	$granted = array();
	foreach (array('adherent', 'societe') as $module) {
		$rightId = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module = '".$module."' AND perms = 'lire' AND (subperms IS NULL OR subperms = '') AND entity = 1");
		if ($rightId <= 0 || $reader->addrights($rightId) < 0) {
			rt_fail('granting '.$module.'/lire: '.$reader->error);
		}
		$granted[] = $rightId;
	}
	print json_encode(array('rights' => $granted))."\n";
	exit(0);
}

if ($stage === 'members') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent_type.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';

	rt_const($db, 'ADHERENT_LOGIN_NOT_REQUIRED', '1');
	rt_const($db, 'ADHERENT_MAIL_REQUIRED', '0');
	$conf->setValues($db);
	$countryId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");

	$type = new AdherentType($db);
	$type->label = 'Ordentliches Mitglied';
	$type->morphy = '';
	$type->subscription = 0;
	$typeId = $type->create($admin);
	if ($typeId <= 0) {
		rt_fail('member type: '.$type->error);
	}

	/**
	 * Create a third party without member.
	 *
	 * @param DoliDB $db        Database handler
	 * @param User   $admin     Administrator
	 * @param string $name      Name
	 * @param string $email     E-mail
	 * @param int    $countryId Country
	 * @return int
	 */
	function rt_partner($db, $admin, $name, $email, $countryId)
	{
		$partner = new Societe($db);
		$partner->name = $name;
		$partner->email = $email;
		$partner->address = 'Teststraße 1';
		$partner->zip = '6020';
		$partner->town = 'Innsbruck';
		$partner->country_id = $countryId;
		$partner->client = 1;
		$partner->code_client = -1;
		if ($partner->create($admin) <= 0) {
			rt_fail('third party '.$name.': '.$partner->error);
		}
		return (int) $partner->id;
	}

	$annaPartner = rt_partner($db, $admin, 'Anna Vorhanden', 'anna@runtime-verein.test', $countryId);
	$oldPartner = rt_partner($db, $admin, 'Alt Partner', 'alt@runtime-verein.test', $countryId);
	$memberCategory = new Categorie($db);
	$memberCategory->fetch((int) getDolGlobalInt('VEREINE_CATEGORY_MEMBER'));
	$old = new Societe($db);
	$old->fetch($oldPartner);
	if ($memberCategory->add_type($old, 'customer') < 0) {
		rt_fail('orphan category: '.$memberCategory->error);
	}

	/**
	 * Create a member and validate it unless it stays a draft.
	 *
	 * @param DoliDB $db        Database handler
	 * @param User   $admin     Administrator
	 * @param int    $typeId    Member type
	 * @param array  $fields    Member fields
	 * @param bool   $validate  Whether to validate
	 * @param int    $countryId Country
	 * @return int
	 */
	function rt_member($db, $admin, $typeId, array $fields, $validate, $countryId)
	{
		$member = new Adherent($db);
		$member->typeid = $typeId;
		$member->morphy = 'phy';
		$member->address = 'Teststraße 1';
		$member->zip = '6020';
		$member->town = 'Innsbruck';
		$member->country_id = $countryId;
		$member->public = 0;
		foreach ($fields as $name => $value) {
			$member->$name = $value;
		}
		if ($member->create($admin) <= 0) {
			rt_fail('member '.$member->lastname.': '.$member->error.' '.implode(' | ', (array) $member->errors));
		}
		if ($validate && $member->validate($admin) <= 0) {
			rt_fail('validate member '.$member->lastname.': '.$member->error);
		}
		return (int) $member->id;
	}

	$members = array(
		'lisa' => rt_member($db, $admin, $typeId, array('firstname' => 'Lisa', 'lastname' => 'Neu', 'email' => 'lisa@runtime-verein.test'), true, $countryId),
		'anna' => rt_member($db, $admin, $typeId, array('firstname' => 'Anna', 'lastname' => 'Vorhanden', 'email' => 'anna@runtime-verein.test'), true, $countryId),
		'kind' => rt_member($db, $admin, $typeId, array('firstname' => 'Kim', 'lastname' => 'Jung', 'email' => 'kind@runtime-verein.test', 'birth' => dol_mktime(12, 0, 0, 5, 5, 2015)), true, $countryId),
		'sponsor' => rt_member($db, $admin, $typeId, array('morphy' => 'mor', 'company' => 'Sponsor GmbH', 'societe' => 'Sponsor GmbH', 'firstname' => 'Max', 'lastname' => 'Kontakt', 'email' => 'sponsor@runtime-verein.test'), true, $countryId),
		'draft' => rt_member($db, $admin, $typeId, array('firstname' => 'Erik', 'lastname' => 'Entwurf', 'email' => 'entwurf@runtime-verein.test'), false, $countryId),
	);
	print json_encode(array('type' => $typeId, 'members' => $members, 'partners' => array('anna' => $annaPartner, 'old' => $oldPartner)))."\n";
	exit(0);
}

if ($stage === 'resiliate') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	$member = new Adherent($db);
	if ($member->fetch((int) rt_env('RT_MEMBER_ID')) <= 0 || $member->resiliate($admin) <= 0) {
		rt_fail('resiliate member: '.$member->error);
	}
	print json_encode(array('resiliated' => (int) $member->id))."\n";
	exit(0);
}

if ($stage === 'guardian') {
	require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
	$contact = new Contact($db);
	$contact->socid = (int) rt_env('RT_PARTNER_ID');
	$contact->firstname = 'Gerda';
	$contact->lastname = 'Jung';
	$contact->email = 'gerda.jung@runtime-verein.test';
	$contact->statut = 1;
	if ($contact->create($admin) <= 0) {
		rt_fail('guardian contact: '.$contact->error);
	}
	$category = new Categorie($db);
	if ($category->fetch((int) getDolGlobalInt('VEREINE_CATEGORY_GUARDIAN')) <= 0 || $category->add_type($contact, 'contact') < 0) {
		rt_fail('guardian category: '.$category->error);
	}
	print json_encode(array('guardian' => (int) $contact->id))."\n";
	exit(0);
}

if ($stage === 'reset') {
	// After the upgrade test: back to a Dolibarr that never had the module, apart from its files.
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
	unActivateModule('modVereine');
	foreach (array('VEREINE_CATEGORY_MEMBER', 'VEREINE_CATEGORY_FORMER', 'VEREINE_CATEGORY_GUARDIAN') as $name) {
		$category = new Categorie($db);
		if (getDolGlobalInt($name) > 0 && $category->fetch(getDolGlobalInt($name)) > 0 && $category->delete($admin) < 0) {
			rt_fail('delete category '.$name.': '.$category->error);
		}
	}
	if (!$db->query("DELETE FROM ".MAIN_DB_PREFIX."const WHERE name LIKE 'VEREINE\\_%'")) {
		rt_fail('delete constants: '.$db->lasterror());
	}
	if (!$db->query("DROP TABLE IF EXISTS ".MAIN_DB_PREFIX."vereine_log")) {
		rt_fail('drop log table: '.$db->lasterror());
	}
	print json_encode(array('reset' => 1))."\n";
	exit(0);
}

rt_fail('unknown stage "'.$stage.'", use base, rights, readmembers, members, resiliate, guardian or reset');
