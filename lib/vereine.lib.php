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
 * \file    lib/vereine.lib.php
 * \ingroup vereine
 * \brief   Shared functions of the Vereine pages.
 */

/**
 * Tabs of the module's setup pages.
 *
 * @return array<int,array<int,string>>
 */
function vereineAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load('vereine@vereine');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/vereine/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabAssociation');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/partners.php', 1);
	$head[$h][1] = $langs->trans('VereineSetupTabPartners');
	$head[$h][2] = 'partners';
	$h++;

	$head[$h][0] = dol_buildpath('/vereine/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'vereine@vereine');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'vereine@vereine', 'remove');

	return $head;
}

/**
 * Name of a month in the user's language.
 *
 * @param int $month 1 to 12
 * @return string
 */
function vereineMonthName($month)
{
	global $langs;

	$keys = array(1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
	return isset($keys[$month]) ? $langs->trans($keys[$month]) : '';
}

/**
 * A status badge for a check result.
 *
 * @param string $status VereineOrganization::CHECK_OK or CHECK_WARNING
 * @return string HTML
 */
function vereineCheckBadge($status)
{
	global $langs;

	if ($status === VereineOrganization::CHECK_OK) {
		return dolGetBadge($langs->trans('VereineCheckStatusOk'), '', 'success');
	}
	return dolGetBadge($langs->trans('VereineCheckStatusWarning'), '', 'warning');
}
