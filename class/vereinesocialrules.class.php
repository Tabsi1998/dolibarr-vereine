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
 * \file    class/vereinesocialrules.class.php
 * \ingroup vereine
 * \brief   Rules of channels and accounts (#233): the association's own channels, members' accounts, their links.
 *
 * Networks are Dolibarr's own dictionary of social networks; an association adds what it misses there.
 * A channel belongs to the association and may be shown to the world. An account belongs to a member:
 * it is asked on the application and can be confirmed by an application after the person signed in at
 * the network. A confirmation is worth as much as the name it confirmed; once the name changes, it is gone.
 */

/**
 * Rules of channels and accounts.
 */
class VereineSocialRules
{
	/** An account the form may ask for. */
	const OPTIONAL = 'optional';

	/** An account the form must have. */
	const REQUIRED = 'required';

	/** How long a name of an account may be. */
	const HANDLE_MAX = 128;

	/** How long the address or name of a channel may be. */
	const TARGET_MAX = 255;

	/** How long the label of a channel may be. */
	const LABEL_MAX = 64;

	/** Where a name on a network leads, for networks whose dictionary entry has no address of its own. */
	const KNOWN = array(
		'twitch' => 'https://www.twitch.tv/{socialid}',
		'youtube' => 'https://www.youtube.com/{socialid}',
		'kick' => 'https://kick.com/{socialid}',
		'tiktok' => 'https://www.tiktok.com/@{socialid}',
		'instagram' => 'https://www.instagram.com/{socialid}',
		'github' => 'https://github.com/{socialid}',
	);

	/** Networks whose channel has a page of its live stream, and how it is found from the channel's address. */
	const LIVE = array('twitch' => '', 'kick' => '', 'youtube' => '/live');

	/**
	 * Which networks the application asks for, as stored: network => optional or required.
	 *
	 * @param string   $stored JSON
	 * @param string[] $known  Networks of the dictionary
	 * @return array<string,string>
	 */
	public static function asked($stored, array $known)
	{
		$asked = array();
		$decoded = json_decode((string) $stored, true);
		foreach (is_array($decoded) ? $decoded : array() as $network => $how) {
			if (in_array((string) $network, $known, true) && in_array($how, array(self::OPTIONAL, self::REQUIRED), true)) {
				$asked[(string) $network] = $how;
			}
		}
		return $asked;
	}

	/**
	 * A code for a network the association adds: small letters, digits and underscores.
	 *
	 * @param string $code As entered
	 * @return bool
	 */
	public static function networkCode($code)
	{
		return (bool) preg_match('/^[a-z][a-z0-9_]{1,31}$/', (string) $code);
	}

	/**
	 * The name of an account as entered, or null when it cannot be one.
	 *
	 * @param mixed $value As entered
	 * @return string|null Empty for no account
	 */
	public static function handle($value)
	{
		if (!is_string($value) && !is_int($value)) {
			return null;
		}
		$value = trim((string) $value);
		if (mb_strlen($value, 'UTF-8') > self::HANDLE_MAX || preg_match('/[\x00-\x1F\x7F<>"]/u', $value) || !mb_check_encoding($value, 'UTF-8')) {
			return null;
		}
		return $value;
	}

	/**
	 * Accounts sent with an application: only networks the form asks, every required one present.
	 *
	 * @param mixed                $sent  network => name, as sent
	 * @param array<string,string> $asked From asked()
	 * @return array{accounts:array<string,string>,errors:string[]}
	 */
	public static function fromApplication($sent, array $asked)
	{
		$accounts = array();
		$errors = array();
		if ($sent !== null && $sent !== '' && !is_array($sent)) {
			return array('accounts' => array(), 'errors' => array('accounts must be an object of network: name'));
		}
		foreach (is_array($sent) ? $sent : array() as $network => $value) {
			if (!isset($asked[(string) $network])) {
				$errors[] = 'accounts.'.$network.' is not asked by the form';
				continue;
			}
			$handle = self::handle($value);
			if ($handle === null) {
				$errors[] = 'accounts.'.$network.' is no name of an account';
			} elseif ($handle !== '') {
				$accounts[(string) $network] = $handle;
			}
		}
		foreach ($asked as $network => $how) {
			if ($how === self::REQUIRED && !isset($accounts[$network])) {
				$errors[] = 'accounts.'.$network.' is required';
			}
		}
		return array('accounts' => $accounts, 'errors' => $errors);
	}

	/**
	 * Where an account or a channel leads: an address stays, a name goes through the network's pattern.
	 *
	 * @param string $network Network
	 * @param string $value   Name or address
	 * @param string $pattern The dictionary's address with {socialid}, may be only '{socialid}'
	 * @return string Empty when there is no address to build
	 */
	public static function link($network, $value, $pattern = '')
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}
		if (preg_match('#^https?://[^\s<>"]+$#i', $value)) {
			return $value;
		}
		$pattern = (string) $pattern;
		if (!preg_match('#^https?://#i', $pattern) && isset(self::KNOWN[$network])) {
			$pattern = self::KNOWN[$network];
		}
		if (!preg_match('#^https?://#i', $pattern) || strpos($pattern, '{socialid}') === false) {
			return '';
		}
		// A YouTube channel is @name; a bare name gets its @, a channel path stays as it is.
		if ($network === 'youtube' && strpos($value, '/') === false && $value[0] !== '@') {
			$value = '@'.$value;
		}
		return str_replace('{socialid}', str_replace(' ', '%20', $value), $pattern);
	}

	/**
	 * The page of a channel's live stream, for networks that have one.
	 *
	 * @param string $network Network
	 * @param string $url     The channel's address
	 * @return string Empty when the network has no such page or there is no address
	 */
	public static function liveUrl($network, $url)
	{
		if ($url === '' || !array_key_exists($network, self::LIVE)) {
			return '';
		}
		$url = rtrim($url, '/');
		// A YouTube address that already points at a video or the live page stays.
		if ($network === 'youtube' && preg_match('#/(live|watch|shorts)\b#', $url)) {
			return $url;
		}
		return $url.self::LIVE[$network];
	}

	/**
	 * A channel as entered in the setup.
	 *
	 * @param array<string,mixed> $entered  network, label, target, stream, public, position
	 * @param string[]            $networks Networks of the dictionary
	 * @return array{channel:array{network:string,label:string,target:string,stream:bool,public:bool,position:int},errors:string[]}
	 */
	public static function checkChannel(array $entered, array $networks)
	{
		$errors = array();
		$network = isset($entered['network']) ? (string) $entered['network'] : '';
		if (!in_array($network, $networks, true)) {
			$errors[] = 'VereineChannelErrorNetwork';
		}
		$label = isset($entered['label']) ? trim((string) $entered['label']) : '';
		if ($label === '' || mb_strlen($label, 'UTF-8') > self::LABEL_MAX) {
			$errors[] = 'VereineChannelErrorLabel';
		}
		$target = isset($entered['target']) ? trim((string) $entered['target']) : '';
		if ($target === '' || mb_strlen($target, 'UTF-8') > self::TARGET_MAX || preg_match('/[\x00-\x1F\x7F<>"]/u', $target)
			|| (preg_match('#^[a-z]+://#i', $target) && !preg_match('#^https?://[^\s]+$#i', $target))) {
			$errors[] = 'VereineChannelErrorTarget';
		}
		$position = isset($entered['position']) && preg_match('/^\d{1,3}$/', (string) $entered['position']) ? (int) $entered['position'] : 0;
		return array('channel' => array('network' => $network, 'label' => $label, 'target' => $target, 'stream' => !empty($entered['stream']),
			'public' => !empty($entered['public']), 'position' => $position), 'errors' => $errors);
	}

	/**
	 * Channels in the order to show them: the order the association gave, then the networks in the order of
	 * the dictionary, then the label. So two streams and a live stream stand the way the association wants.
	 *
	 * @param array<int,array<string,mixed>> $channels Channels with network, position, label
	 * @param string[]                       $order    Networks in their order
	 * @return array<int,array<string,mixed>>
	 */
	public static function sortChannels(array $channels, array $order)
	{
		$rank = array_flip(array_values($order));
		usort($channels, function ($a, $b) use ($rank) {
			if ((int) $a['position'] !== (int) $b['position']) {
				return (int) $a['position'] < (int) $b['position'] ? -1 : 1;
			}
			$ra = isset($rank[$a['network']]) ? $rank[$a['network']] : PHP_INT_MAX;
			$rb = isset($rank[$b['network']]) ? $rank[$b['network']] : PHP_INT_MAX;
			if ($ra !== $rb) {
				return $ra < $rb ? -1 : 1;
			}
			return strcmp((string) $a['label'], (string) $b['label']);
		});
		return $channels;
	}

	/**
	 * Whether a confirmation still holds: it confirmed the name the member has now.
	 *
	 * @param string $confirmed The name that was confirmed
	 * @param string $current   The name the member has now
	 * @return bool
	 */
	public static function stillConfirmed($confirmed, $current)
	{
		$confirmed = trim((string) $confirmed);
		return $confirmed !== '' && mb_strtolower($confirmed, 'UTF-8') === mb_strtolower(trim((string) $current), 'UTF-8');
	}
}
