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

rt_fail('unknown stage "'.$stage.'", use base or rights');
