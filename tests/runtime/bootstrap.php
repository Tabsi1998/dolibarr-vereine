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
 * \file    tests/runtime/bootstrap.php
 * \ingroup vereine
 * \brief   Shared start of the runtime fixture scripts.
 *
 * They run with the PHP CLI inside a disposable Dolibarr container, mounted
 * outside the web root, and must never answer a web request.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit(1);
}

define('NOSESSION', 1);
define('NOCSRFCHECK', 1);
define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

$dolibarrRoot = getenv('RT_DOLIBARR_ROOT') ?: '/var/www/html';
if (!is_file($dolibarrRoot.'/master.inc.php')) {
	fwrite(STDERR, "Dolibarr was not found in ".$dolibarrRoot."\n");
	exit(2);
}
require_once $dolibarrRoot.'/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

/**
 * Stop with a message the local check prints.
 *
 * @param string $message What went wrong
 * @return never
 */
function rt_fail($message)
{
	fwrite(STDERR, 'runtime fixture: '.$message."\n");
	exit(3);
}

/**
 * The administrator the image created, with rights loaded.
 *
 * @param DoliDB $db Database handler
 * @return User
 */
function rt_admin($db)
{
	$admin = new User($db);
	if ($admin->fetch(0, 'admin') <= 0) {
		rt_fail('the administrator account does not exist');
	}
	$admin->loadRights();
	return $admin;
}

/**
 * One value from the database, or null.
 *
 * @param DoliDB $db  Database handler
 * @param string $sql Query
 * @return string|null
 */
function rt_value($db, $sql)
{
	$resql = $db->query($sql);
	if (!$resql) {
		rt_fail('query failed: '.$db->lasterror().' - '.$sql);
	}
	$row = $db->fetch_row($resql);
	$db->free($resql);
	return $row ? $row[0] : null;
}

/**
 * Set a constant in entity 1, the only entity of the check.
 *
 * @param DoliDB $db    Database handler
 * @param string $name  Constant
 * @param string $value Value
 * @return void
 */
function rt_const($db, $name, $value)
{
	if (dolibarr_set_const($db, $name, (string) $value, 'chaine', 0, '', 1) <= 0) {
		rt_fail('could not set '.$name);
	}
}

/**
 * An environment variable that must be set.
 *
 * @param string $name Variable
 * @return string
 */
function rt_env($name)
{
	$value = (string) getenv($name);
	if ($value === '') {
		rt_fail($name.' must be set');
	}
	return $value;
}
