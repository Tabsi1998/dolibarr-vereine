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
 * \file    class/vereineapirules.class.php
 * \ingroup vereine
 * \brief   The module's REST endpoints as described in docs/openapi.json and who may call them, plain PHP.
 */

/**
 * Endpoints and rights of the API.
 */
class VereineApiRules
{
	/**
	 * The endpoints of a parsed docs/openapi.json.
	 *
	 * @param mixed $spec Decoded OpenAPI description
	 * @return array<int,array{method:string,path:string,operation:string,summary:string,rights:array<int,string[]>,parameters:string[]}> In the order of the description
	 */
	public static function endpoints($spec)
	{
		$endpoints = array();
		if (!is_array($spec) || !isset($spec['paths']) || !is_array($spec['paths'])) {
			return $endpoints;
		}
		foreach ($spec['paths'] as $path => $item) {
			foreach ((array) $item as $method => $operation) {
				if (!in_array($method, array('get', 'post', 'put', 'delete'), true) || !is_array($operation)) {
					continue;
				}
				$parameters = array();
				foreach (isset($operation['parameters']) ? (array) $operation['parameters'] : array() as $parameter) {
					if (is_array($parameter) && isset($parameter['name'], $parameter['in'])) {
						$parameters[] = $parameter['name'].($parameter['in'] === 'path' ? '' : (!empty($parameter['required']) ? '' : '?'));
					}
				}
				$endpoints[] = array('method' => strtoupper($method), 'path' => (string) $path, 'operation' => isset($operation['operationId']) ? (string) $operation['operationId'] : '',
					'summary' => isset($operation['summary']) ? (string) $operation['summary'] : '',
					'rights' => isset($operation['x-vereine-rights']) && is_array($operation['x-vereine-rights']) ? $operation['x-vereine-rights'] : array(),
					'parameters' => $parameters);
			}
		}
		return $endpoints;
	}

	/**
	 * Whether a user with some rights may call an endpoint.
	 *
	 * @param array<int,string[]> $needed Rights of the endpoint: all groups, one right of each group
	 * @param string[]            $has    Rights of the user as module:perms or module:perms:subperms
	 * @param bool                $admin  The user is an administrator and has every right
	 * @return bool
	 */
	public static function canCall(array $needed, array $has, $admin)
	{
		if ($admin) {
			return true;
		}
		foreach ($needed as $group) {
			if (!array_intersect((array) $group, $has)) {
				return false;
			}
		}
		return (bool) $needed;
	}

	/**
	 * A right in the form of the description, from a row of llx_rights_def.
	 *
	 * @param string      $module   Module
	 * @param string      $perms    Permission
	 * @param string|null $subperms Sub-permission
	 * @return string module:perms or module:perms:subperms
	 */
	public static function rightKey($module, $perms, $subperms)
	{
		return $module.':'.$perms.((string) $subperms !== '' ? ':'.$subperms : '');
	}
}
