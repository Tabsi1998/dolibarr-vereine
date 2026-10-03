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
 * \file    class/vereinepartnerimages.class.php
 * \ingroup vereine
 * \brief   Partners and sponsors for the website with their pictures (#278).
 *
 * The third parties of the categories the association chose go to the website with name, address of their
 * website and categories, never with contact data. Each may have a logo and a banner for a light and for a dark
 * background; the files lie with the third party in Dolibarr's documents, so Dolibarr shows them and deletes
 * them with the third party. Without an own logo for a light background, the logo of the Dolibarr card counts.
 */

require_once __DIR__.'/vereinepartnerimagerules.class.php';
require_once __DIR__.'/vereinelog.class.php';

/**
 * Partners and their pictures.
 */
class VereinePartnerImages
{
	/** Categories of third parties that go to the website, comma separated, in their order. */
	const CATEGORIES = 'VEREINE_WEBSITE_PARTNER_CATEGORIES';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error
	 */
	public $error = '';

	/**
	 * @var string[] Codes of what was refused
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
	 * The categories chosen for the website, in their order.
	 *
	 * @return int[]
	 */
	public static function categories()
	{
		return VereinePartnerImageRules::categories(getDolGlobalString(self::CATEGORIES));
	}

	/**
	 * Categories of third parties there are: customers and prospects, and suppliers.
	 *
	 * @return array<int,string> Id => name with its kind
	 */
	public function choices()
	{
		global $langs;

		$choices = array();
		$sql = "SELECT rowid, label, type FROM ".MAIN_DB_PREFIX."categorie WHERE type IN (1, 2) AND entity IN (".getEntity('category').") ORDER BY type DESC, label";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$choices[(int) $obj->rowid] = (string) $obj->label.' ('.$langs->transnoentitiesnoconv((int) $obj->type === 1 ? 'VereinePartnerCategorySupplier' : 'VereinePartnerCategoryCustomer').')';
		}
		return $choices;
	}

	/**
	 * Keep which categories go to the website, in the order the association numbered them.
	 *
	 * @param array<int|string,mixed> $positions Category => number, empty for not on the website
	 * @param User                    $user      Who
	 * @return int 1 when saved, -1 on error
	 */
	public function saveCategories(array $positions, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$kept = VereinePartnerImageRules::numbered($positions, array_keys($this->choices()));
		if (dolibarr_set_const($this->db, self::CATEGORIES, implode(',', $kept), 'chaine', 0, '', $conf->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		VereineLog::add($this->db, $user, VereineLog::PARTNER_IMAGE, 0, 0, 'categories '.implode(',', $kept));
		return 1;
	}

	/**
	 * The partners for the website: active third parties of the chosen categories, in their order, with their pictures.
	 *
	 * @return array<int,array{id:int,name:string,url:string,categories:array<int,array{id:int,label:string}>,images:array<int,array<string,mixed>>}>
	 */
	public function partners()
	{
		$chosen = self::categories();
		if (!$chosen) {
			return array();
		}
		$partners = array();
		$in = implode(',', array_map('intval', $chosen));
		foreach (array('categorie_societe', 'categorie_fournisseur') as $link) {
			$sql = "SELECT s.rowid, s.nom, s.url, c.rowid as category, c.label FROM ".MAIN_DB_PREFIX."societe as s INNER JOIN ".MAIN_DB_PREFIX.$link." as l ON l.fk_soc = s.rowid";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = l.fk_categorie WHERE l.fk_categorie IN (".$in.") AND s.status = 1";
			$sql .= " AND s.entity IN (".getEntity('societe').")";
			$resql = $this->db->query($sql);
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$id = (int) $obj->rowid;
				if (!isset($partners[$id])) {
					$partners[$id] = array('id' => $id, 'name' => (string) $obj->nom, 'url' => (string) $obj->url, 'categories' => array());
				}
				$partners[$id]['categories'][(int) $obj->category] = array('id' => (int) $obj->category, 'label' => (string) $obj->label);
			}
		}
		foreach ($partners as $id => $partner) {
			$categories = array_values($partner['categories']);
			usort($categories, function ($a, $b) use ($chosen) {
				return array_search($a['id'], $chosen, true) - array_search($b['id'], $chosen, true);
			});
			$partners[$id]['categories'] = $categories;
			$partners[$id]['images'] = array_values($this->images($id));
		}
		return VereinePartnerImageRules::order(array_values($partners), $chosen);
	}

	/**
	 * Whether a third party goes to the website now.
	 *
	 * @param int $socid Third party
	 * @return array<int,array{id:int,label:string}>|null Its chosen categories, null when it does not go
	 */
	public function onSite($socid)
	{
		$chosen = self::categories();
		if (!$chosen) {
			return null;
		}
		$found = array();
		foreach (array('categorie_societe', 'categorie_fournisseur') as $link) {
			$sql = "SELECT c.rowid, c.label FROM ".MAIN_DB_PREFIX.$link." as l INNER JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = l.fk_categorie";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = l.fk_soc AND s.status = 1 AND s.entity IN (".getEntity('societe').")";
			$sql .= " WHERE l.fk_soc = ".((int) $socid)." AND l.fk_categorie IN (".implode(',', array_map('intval', $chosen)).")";
			$resql = $this->db->query($sql);
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$found[(int) $obj->rowid] = array('id' => (int) $obj->rowid, 'label' => (string) $obj->label);
			}
		}
		if (!$found) {
			return null;
		}
		usort($found, function ($a, $b) use ($chosen) {
			return array_search($a['id'], $chosen, true) - array_search($b['id'], $chosen, true);
		});
		return array_values($found);
	}

	/**
	 * The pictures of a third party, each with its place, type, size, dimensions and checksum.
	 *
	 * @param int $socid Third party
	 * @return array<string,array{kind:string,variant:string,source:string,file:string,content_type:string,size:int,width:int,height:int,sha256:string,updated_at:int}> By kind-variant
	 */
	public function images($socid)
	{
		global $conf;

		$images = array();
		$resql = $this->db->query("SELECT kind, variant, filename, content_type, filesize, width, height, sha256, datec FROM ".MAIN_DB_PREFIX."vereine_partner_image WHERE entity = "
			.((int) $conf->entity)." AND fk_soc = ".((int) $socid));
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$file = self::directory((int) $socid).'/'.basename((string) $obj->filename);
			if (!is_file($file)) {
				continue;
			}
			$images[$obj->kind.'-'.$obj->variant] = array('kind' => (string) $obj->kind, 'variant' => (string) $obj->variant, 'source' => 'vereine', 'file' => $file,
				'content_type' => (string) $obj->content_type, 'size' => (int) $obj->filesize, 'width' => (int) $obj->width, 'height' => (int) $obj->height,
				'sha256' => (string) $obj->sha256, 'updated_at' => (int) $this->db->jdate($obj->datec));
		}
		// Without an own logo for a light background, the logo of the Dolibarr card counts.
		if (!isset($images['logo-light'])) {
			$logo = $this->dolibarrLogo((int) $socid);
			if ($logo !== null) {
				$images['logo-light'] = $logo;
			}
		}
		$order = array();
		foreach (VereinePartnerImageRules::KINDS as $kind) {
			foreach (VereinePartnerImageRules::VARIANTS as $variant) {
				if (isset($images[$kind.'-'.$variant])) {
					$order[$kind.'-'.$variant] = $images[$kind.'-'.$variant];
				}
			}
		}
		return $order;
	}

	/**
	 * Keep a picture of a third party in its place; one there before is replaced.
	 *
	 * @param int                 $socid   Third party
	 * @param string              $kind    logo or banner
	 * @param string              $variant light or dark
	 * @param array<string,mixed> $upload  The entry of $_FILES
	 * @param User                $user    Who
	 * @return int 1 when kept, 0 when refused (see errors), -1 on error
	 */
	public function upload($socid, $kind, $variant, array $upload, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$this->errors = array();
		$temp = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
		if ($temp === '' || !is_uploaded_file($temp)) {
			$this->errors = array(VereinePartnerImageRules::slot($kind, $variant) ? 'empty' : 'slot');
			return 0;
		}
		$size = (int) filesize($temp);
		$info = @getimagesize($temp);
		$type = is_array($info) && isset($info['mime']) ? (string) $info['mime'] : '';
		$this->errors = VereinePartnerImageRules::check($kind, $variant, $size, $type, is_array($info) ? (int) $info[0] : 0, is_array($info) ? (int) $info[1] : 0);
		if ($this->errors) {
			return 0;
		}
		$dir = self::directory((int) $socid);
		if (dol_mkdir($dir) < 0) {
			$this->error = 'cannot create '.$dir;
			return -1;
		}
		$name = VereinePartnerImageRules::fileName($kind, $variant, $type);
		if (dol_move_uploaded_file($temp, $dir.'/'.$name, 1, 0, isset($upload['error']) ? $upload['error'] : 0, 1) != 1) {
			$this->error = 'cannot store '.$name;
			return -1;
		}
		// A picture of another type in the same place goes.
		foreach (VereinePartnerImageRules::TYPES as $ending) {
			if ($kind.'-'.$variant.'.'.$ending !== $name && is_file($dir.'/'.$kind.'-'.$variant.'.'.$ending)) {
				dol_delete_file($dir.'/'.$kind.'-'.$variant.'.'.$ending, 0, 1);
			}
		}
		$this->db->begin();
		$ok = $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_partner_image WHERE entity = ".((int) $conf->entity)." AND fk_soc = ".((int) $socid)
			." AND kind = '".$this->db->escape($kind)."' AND variant = '".$this->db->escape($variant)."'");
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_partner_image (entity, fk_soc, kind, variant, filename, content_type, filesize, width, height, sha256, datec, fk_user) VALUES (";
		$sql .= ((int) $conf->entity).", ".((int) $socid).", '".$this->db->escape($kind)."', '".$this->db->escape($variant)."', '".$this->db->escape($name)."',";
		$sql .= " '".$this->db->escape($type)."', ".((int) filesize($dir.'/'.$name)).", ".((int) $info[0]).", ".((int) $info[1]).", '".hash_file('sha256', $dir.'/'.$name)."',";
		$sql .= " '".$this->db->idate(dol_now())."', ".((int) $user->id).")";
		$ok = $ok && $this->db->query($sql);
		if (!$ok) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		VereineLog::add($this->db, $user, VereineLog::PARTNER_IMAGE, 0, (int) $socid, $kind.' '.$variant.' '.$type.' '.((int) $info[0]).'x'.((int) $info[1]));
		return 1;
	}

	/**
	 * Remove an own picture of a third party; the logo of the Dolibarr card stays.
	 *
	 * @param int    $socid   Third party
	 * @param string $kind    logo or banner
	 * @param string $variant light or dark
	 * @param User   $user    Who
	 * @return int 1 when removed, 0 when there was none, -1 on error
	 */
	public function remove($socid, $kind, $variant, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$images = $this->images((int) $socid);
		$key = $kind.'-'.$variant;
		if (!VereinePartnerImageRules::slot($kind, $variant) || !isset($images[$key]) || $images[$key]['source'] !== 'vereine') {
			return 0;
		}
		if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."vereine_partner_image WHERE entity = ".((int) $conf->entity)." AND fk_soc = ".((int) $socid)
			." AND kind = '".$this->db->escape($kind)."' AND variant = '".$this->db->escape($variant)."'")) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		dol_delete_file($images[$key]['file'], 0, 1);
		VereineLog::add($this->db, $user, VereineLog::PARTNER_IMAGE, 0, (int) $socid, $kind.' '.$variant.' removed');
		return 1;
	}

	/**
	 * A picture of a partner as it is, for the API: only of third parties that go to the website.
	 *
	 * @param int    $socid   Third party
	 * @param string $kind    logo or banner
	 * @param string $variant light or dark
	 * @return array{filename:string,content_type:string,filesize:int,sha256:string,bytes:string}|null
	 */
	public function file($socid, $kind, $variant)
	{
		if (!VereinePartnerImageRules::slot($kind, $variant) || $this->onSite((int) $socid) === null) {
			return null;
		}
		$images = $this->images((int) $socid);
		$image = isset($images[$kind.'-'.$variant]) ? $images[$kind.'-'.$variant] : null;
		if ($image === null) {
			return null;
		}
		$bytes = (string) file_get_contents($image['file']);
		return array('filename' => $kind.'-'.$variant.'.'.VereinePartnerImageRules::TYPES[$image['content_type']], 'content_type' => $image['content_type'],
			'filesize' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'bytes' => $bytes);
	}

	/**
	 * A partner as the website sees it: no contact data, the pictures without where they lie.
	 *
	 * @param array<string,mixed> $partner From partners()
	 * @return array{id:int,name:string,url:string,categories:array<int,array{id:int,label:string}>,images:array<int,array<string,mixed>>}
	 */
	public static function apiView(array $partner)
	{
		$images = array();
		foreach ($partner['images'] as $image) {
			$images[] = array('kind' => $image['kind'], 'variant' => $image['variant'], 'source' => $image['source'], 'content_type' => $image['content_type'],
				'size' => $image['size'], 'width' => $image['width'], 'height' => $image['height'], 'sha256' => $image['sha256'],
				'updated_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $image['updated_at']));
		}
		return array('id' => $partner['id'], 'name' => $partner['name'], 'url' => $partner['url'], 'categories' => $partner['categories'], 'images' => $images);
	}

	/**
	 * Where the pictures of a third party lie: with its documents in Dolibarr.
	 *
	 * @param int $socid Third party
	 * @return string
	 */
	public static function directory($socid)
	{
		global $conf;

		$root = !empty($conf->societe->multidir_output[$conf->entity]) ? $conf->societe->multidir_output[$conf->entity] : DOL_DATA_ROOT.'/societe';
		return $root.'/'.((int) $socid).'/vereine';
	}

	/**
	 * The path of a picture relative to the documents of third parties, for Dolibarr's own viewer.
	 *
	 * @param int                 $socid Third party
	 * @param array<string,mixed> $image From images()
	 * @return string
	 */
	public static function relativePath($socid, array $image)
	{
		return $image['source'] === 'dolibarr' ? ((int) $socid).'/logos/'.basename((string) $image['file']) : ((int) $socid).'/vereine/'.basename((string) $image['file']);
	}

	/**
	 * The logo of the Dolibarr card of a third party, when it is a picture the website may get.
	 *
	 * @param int $socid Third party
	 * @return array<string,mixed>|null
	 */
	private function dolibarrLogo($socid)
	{
		global $conf;

		$resql = $this->db->query("SELECT logo FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".((int) $socid));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		$name = $obj ? basename((string) $obj->logo) : '';
		$root = !empty($conf->societe->multidir_output[$conf->entity]) ? $conf->societe->multidir_output[$conf->entity] : DOL_DATA_ROOT.'/societe';
		$file = $root.'/'.((int) $socid).'/logos/'.$name;
		if ($name === '' || !is_file($file)) {
			return null;
		}
		$info = @getimagesize($file);
		$type = is_array($info) && isset($info['mime']) ? (string) $info['mime'] : '';
		if (VereinePartnerImageRules::check('logo', 'light', (int) filesize($file), $type, is_array($info) ? (int) $info[0] : 0, is_array($info) ? (int) $info[1] : 0)) {
			return null;
		}
		return array('kind' => 'logo', 'variant' => 'light', 'source' => 'dolibarr', 'file' => $file, 'content_type' => $type, 'size' => (int) filesize($file),
			'width' => (int) $info[0], 'height' => (int) $info[1], 'sha256' => (string) hash_file('sha256', $file), 'updated_at' => (int) filemtime($file));
	}
}
