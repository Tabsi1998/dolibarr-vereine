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
 * \file    class/vereinesocial.class.php
 * \ingroup vereine
 * \brief   Channels of the association and accounts of members at Discord, Twitch, YouTube and the like (#233).
 *
 * The networks are Dolibarr's own dictionary of social networks; what a member has there is Dolibarr's
 * own field of the member, which its member card shows. The module adds the association's channels, the
 * choice which networks an application asks for, and confirmations that an application sends after the
 * person signed in at the network.
 */

require_once __DIR__.'/vereinesocialrules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Channels and accounts.
 */
class VereineSocial
{
	/** Which networks the application asks for: JSON network => optional or required. */
	const ASKED = 'VEREINE_SOCIAL_ASKED';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * @var string[] Messages for the person
	 */
	public $errors = array();

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * The networks of Dolibarr's dictionary, in its order: code => label, address pattern, icon, active.
	 *
	 * @return array<string,array{label:string,url:string,icon:string,active:bool}>
	 */
	public function networks()
	{
		global $conf;

		$networks = array();
		$resql = $this->db->query("SELECT code, label, url, icon, active FROM ".MAIN_DB_PREFIX."c_socialnetworks WHERE entity = ".((int) $conf->entity)." ORDER BY rowid");
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$networks[(string) $obj->code] = array('label' => (string) $obj->label, 'url' => (string) $obj->url, 'icon' => (string) $obj->icon, 'active' => (int) $obj->active === 1);
		}
		return $networks;
	}

	/**
	 * Add a network the dictionary lacks, such as Steam or a Riot ID.
	 *
	 * @param string $code    Code, small letters
	 * @param string $label   Name
	 * @param string $pattern Address with {socialid}, may be empty
	 * @param User   $user    Who
	 * @return int 1 when added, 0 when refused (see errors), -1 on error
	 */
	public function addNetwork($code, $label, $pattern, $user)
	{
		global $conf;

		$code = trim((string) $code);
		$label = trim((string) $label);
		$pattern = trim((string) $pattern);
		$this->errors = array();
		if (!VereineSocialRules::networkCode($code) || isset($this->networks()[$code])) {
			$this->errors[] = 'VereineSocialErrorCode';
		}
		if ($label === '' || mb_strlen($label, 'UTF-8') > 64) {
			$this->errors[] = 'VereineSocialErrorLabel';
		}
		if ($pattern !== '' && (!preg_match('#^https?://[^\s<>"]+$#i', $pattern) || strpos($pattern, '{socialid}') === false)) {
			$this->errors[] = 'VereineSocialErrorPattern';
		}
		if ($this->errors) {
			return 0;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_socialnetworks (entity, code, label, url, icon, active) VALUES (".((int) $conf->entity).", '".$this->db->escape($code)."',";
		$sql .= " '".$this->db->escape($label)."', '".$this->db->escape($pattern !== '' ? $pattern : '{socialid}')."', '', 1)";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::SOCIAL_SETUP, 0, 0, 'network '.$code);
		return 1;
	}

	/**
	 * Which networks the application asks for.
	 *
	 * @return array<string,string> network => optional or required
	 */
	public function asked()
	{
		return VereineSocialRules::asked(getDolGlobalString(self::ASKED), array_keys($this->networks()));
	}

	/**
	 * Keep which networks the application asks for. A network asked for is switched on in Dolibarr's
	 * dictionary too, so the member card shows its field.
	 *
	 * @param array<string,string> $entered network => '', optional or required
	 * @param User                 $user    Who
	 * @return int 1 when saved, -1 on error
	 */
	public function saveAsked(array $entered, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$asked = VereineSocialRules::asked((string) json_encode($entered), array_keys($this->networks()));
		if (dolibarr_set_const($this->db, self::ASKED, (string) json_encode($asked), 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($asked) {
			$codes = array_map(function ($code) {
				return "'".$this->db->escape($code)."'";
			}, array_keys($asked));
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."c_socialnetworks SET active = 1 WHERE entity = ".((int) $conf->entity)." AND code IN (".implode(',', $codes).")");
		}
		VereineLog::add($this->db, $user, VereineLog::SOCIAL_SETUP, 0, 0, (string) json_encode($asked));
		return 1;
	}

	/**
	 * The association's channels in the order to show them, each with its address and live page.
	 *
	 * @param bool $publicOnly Only the ones to show to the world
	 * @return array<int,array{id:int,network:string,network_label:string,label:string,target:string,url:string,stream:bool,live_url:string,public:bool,position:int}>
	 */
	public function channels($publicOnly = false)
	{
		global $conf;

		$networks = $this->networks();
		$sql = "SELECT rowid, network, label, target, stream, public, position FROM ".MAIN_DB_PREFIX."vereine_channel WHERE entity = ".((int) $conf->entity);
		$sql .= $publicOnly ? " AND public = 1" : "";
		$channels = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$network = (string) $obj->network;
			$pattern = isset($networks[$network]) ? $networks[$network]['url'] : '';
			$url = VereineSocialRules::link($network, (string) $obj->target, $pattern);
			$channels[] = array('id' => (int) $obj->rowid, 'network' => $network, 'network_label' => isset($networks[$network]) ? $networks[$network]['label'] : $network,
				'label' => (string) $obj->label, 'target' => (string) $obj->target, 'url' => $url, 'stream' => (int) $obj->stream === 1,
				'live_url' => (int) $obj->stream === 1 ? VereineSocialRules::liveUrl($network, $url) : '', 'public' => (int) $obj->public === 1, 'position' => (int) $obj->position);
		}
		return VereineSocialRules::sortChannels($channels, array_keys($networks));
	}

	/**
	 * Save a channel, new or changed.
	 *
	 * @param int                 $id      Channel, 0 for a new one
	 * @param array<string,mixed> $entered As entered
	 * @param User                $user    Who
	 * @return int Id when saved, 0 when refused (see errors), -1 on error
	 */
	public function saveChannel($id, array $entered, $user)
	{
		global $conf;

		$checked = VereineSocialRules::checkChannel($entered, array_keys($this->networks()));
		$this->errors = $checked['errors'];
		if ($this->errors) {
			return 0;
		}
		$channel = $checked['channel'];
		$values = "network = '".$this->db->escape($channel['network'])."', label = '".$this->db->escape($channel['label'])."', target = '".$this->db->escape($channel['target'])."',";
		$values .= " stream = ".($channel['stream'] ? 1 : 0).", public = ".($channel['public'] ? 1 : 0).", position = ".((int) $channel['position']).", fk_user_modif = ".((int) $user->id);
		if ((int) $id > 0) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."vereine_channel SET ".$values." WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		} else {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_channel (entity, network, label, target, stream, public, position, datec, fk_user_modif) VALUES (".((int) $conf->entity).",";
			$sql .= " '".$this->db->escape($channel['network'])."', '".$this->db->escape($channel['label'])."', '".$this->db->escape($channel['target'])."', ".($channel['stream'] ? 1 : 0).",";
			$sql .= " ".($channel['public'] ? 1 : 0).", ".((int) $channel['position']).", '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$saved = (int) $id > 0 ? (int) $id : (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'vereine_channel');
		VereineLog::add($this->db, $user, VereineLog::CHANNEL, 0, 0, $channel['network'].': '.$channel['label']);
		return $saved;
	}

	/**
	 * Remove a channel.
	 *
	 * @param int  $id   Channel
	 * @param User $user Who
	 * @return int 1 when removed, -1 on error
	 */
	public function removeChannel($id, $user)
	{
		global $conf;

		if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_channel WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity))) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::CHANNEL, 0, 0, 'removed '.((int) $id));
		return 1;
	}

	/**
	 * A member's accounts: the networks the form asks for and every other the member has, each with
	 * its address and whether an application confirmed the name the member has now.
	 *
	 * @param Adherent $member Member
	 * @return array<int,array{network:string,label:string,asked:string,handle:string,url:string,confirmed:bool,confirmed_at:string,client:string}>
	 */
	public function accounts($member)
	{
		global $conf;

		$networks = $this->networks();
		$asked = $this->asked();
		$have = is_array($member->socialnetworks) ? $member->socialnetworks : array();
		$confirmations = array();
		$resql = $this->db->query("SELECT network, handle, client, confirmed_at FROM ".MAIN_DB_PREFIX."vereine_social WHERE entity = ".((int) $conf->entity)." AND fk_adherent = ".((int) $member->id));
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$confirmations[(string) $obj->network] = $obj;
		}
		$accounts = array();
		foreach ($networks as $code => $network) {
			$handle = isset($have[$code]) ? trim((string) $have[$code]) : '';
			if (!isset($asked[$code]) && $handle === '') {
				continue;
			}
			$confirmation = isset($confirmations[$code]) ? $confirmations[$code] : null;
			$confirmed = $confirmation !== null && VereineSocialRules::stillConfirmed((string) $confirmation->handle, $handle);
			$accounts[] = array('network' => $code, 'label' => $network['label'], 'asked' => isset($asked[$code]) ? $asked[$code] : '', 'handle' => $handle,
				'url' => VereineSocialRules::link($code, $handle, $network['url']), 'confirmed' => $confirmed,
				'confirmed_at' => $confirmed ? dol_print_date($this->db->jdate($confirmation->confirmed_at), 'dayhourrfc') : '', 'client' => $confirmed ? (string) $confirmation->client : '');
		}
		return $accounts;
	}

	/**
	 * Set or remove a member's account, and confirm it when an application checked it at the network.
	 *
	 * The name goes into Dolibarr's own field of the member. A name set without confirmation drops an
	 * older confirmation; removing the account removes both.
	 *
	 * @param Adherent $member     Member
	 * @param string   $network    Network
	 * @param string   $handle     Name, empty to remove
	 * @param bool     $confirmed  Whether the application checked it at the network
	 * @param string   $externalId The network's own id of the account, as the application got it
	 * @param string   $client     The application
	 * @param User     $user       Who
	 * @return int 1 when done, 0 when refused (see errors), -1 on error
	 */
	public function setAccount($member, $network, $handle, $confirmed, $externalId, $client, $user)
	{
		global $conf;

		$this->errors = array();
		$networks = $this->networks();
		$handle = VereineSocialRules::handle($handle);
		$externalId = mb_substr(trim((string) $externalId), 0, 128, 'UTF-8');
		if (!isset($networks[(string) $network])) {
			$this->errors[] = 'unknown network';
		}
		if ($handle === null) {
			$this->errors[] = 'handle is no name of an account';
		}
		if ($this->errors) {
			return 0;
		}
		$have = is_array($member->socialnetworks) ? $member->socialnetworks : array();
		if ($handle === '') {
			unset($have[$network]);
		} else {
			$have[$network] = $handle;
		}
		$entity = (int) $conf->entity;
		$id = (int) $member->id;
		$this->db->begin();
		$ok = (bool) $this->db->query("UPDATE ".MAIN_DB_PREFIX."adherent SET socialnetworks = ".($have ? "'".$this->db->escape((string) json_encode($have))."'" : "NULL")." WHERE rowid = ".$id);
		$ok = $ok && $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_social WHERE entity = ".$entity." AND fk_adherent = ".$id." AND network = '".$this->db->escape($network)."'");
		if ($ok && $handle !== '' && $confirmed) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_social (entity, fk_adherent, network, handle, external_id, client, confirmed_at, fk_user, datec) VALUES (".$entity.", ".$id.",";
			$sql .= " '".$this->db->escape($network)."', '".$this->db->escape($handle)."', '".$this->db->escape($externalId)."', '".$this->db->escape((string) $client)."',";
			$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).", '".$this->db->idate(dol_now())."')";
			$ok = (bool) $this->db->query($sql);
		}
		if (!$ok) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		VereineLog::add($this->db, $user, $handle === '' ? VereineLog::SOCIAL_UNLINKED : VereineLog::SOCIAL_LINKED, $id, 0,
			$network.($handle !== '' && $confirmed ? ' (confirmed by '.$client.')' : ''));
		$this->db->commit();
		$member->socialnetworks = $have;
		// Whoever follows the member learns that it changed, as after an edit on the member card.
		$member->call_trigger('MEMBER_MODIFY', $user);
		return 1;
	}
}
