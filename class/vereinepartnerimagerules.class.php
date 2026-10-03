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
 * \file    class/vereinepartnerimagerules.class.php
 * \ingroup vereine
 * \brief   Rules of partners and sponsors for the website (#278): which third parties, which pictures, in which order.
 *
 * The association chooses categories of third parties, such as "Sponsor" or "Partner"; their third parties go
 * to the website. Each may have a logo and a banner, each for a light and for a dark background. Only pictures
 * a browser shows safely are taken: PNG, JPEG and WebP, nothing that can carry a script.
 */

/**
 * Rules of partner pictures.
 */
class VereinePartnerImageRules
{
	/** Kinds of pictures. */
	const KINDS = array('logo', 'banner');

	/** For a light or for a dark background. */
	const VARIANTS = array('light', 'dark');

	/** Types taken, with the ending of the file. */
	const TYPES = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp');

	/** Largest file: 5 MB. */
	const MAX_BYTES = 5242880;

	/** Longest side in pixels. */
	const MAX_SIDE = 8000;

	/**
	 * Whether a kind and a variant name a place for a picture.
	 *
	 * @param string $kind    logo or banner
	 * @param string $variant light or dark
	 * @return bool
	 */
	public static function slot($kind, $variant)
	{
		return in_array((string) $kind, self::KINDS, true) && in_array((string) $variant, self::VARIANTS, true);
	}

	/**
	 * What is wrong with a picture before it is kept.
	 *
	 * @param string $kind    logo or banner
	 * @param string $variant light or dark
	 * @param int    $size    Bytes
	 * @param string $type    Type as the picture itself says, empty when it is no picture
	 * @param int    $width   Width in pixels
	 * @param int    $height  Height in pixels
	 * @return string[] Codes: slot, empty, size, type, dimensions
	 */
	public static function check($kind, $variant, $size, $type, $width, $height)
	{
		$errors = array();
		if (!self::slot($kind, $variant)) {
			$errors[] = 'slot';
		}
		if ((int) $size <= 0) {
			$errors[] = 'empty';
		} elseif ((int) $size > self::MAX_BYTES) {
			$errors[] = 'size';
		}
		if (!isset(self::TYPES[(string) $type])) {
			$errors[] = 'type';
		} elseif ((int) $width < 1 || (int) $height < 1 || (int) $width > self::MAX_SIDE || (int) $height > self::MAX_SIDE) {
			$errors[] = 'dimensions';
		}
		return $errors;
	}

	/**
	 * The name of the file of a place: always the same, so a new picture replaces the old one.
	 *
	 * @param string $kind    logo or banner
	 * @param string $variant light or dark
	 * @param string $type    image/png, image/jpeg or image/webp
	 * @return string
	 */
	public static function fileName($kind, $variant, $type)
	{
		return $kind.'-'.$variant.'.'.self::TYPES[$type];
	}

	/**
	 * Categories as the setup stores them, such as "7,3": ids in the chosen order, each once.
	 *
	 * @param string $stored As stored
	 * @return int[]
	 */
	public static function categories($stored)
	{
		$ids = array();
		foreach (preg_split('/[\s,;]+/', (string) $stored) as $part) {
			if (preg_match('/^\d{1,10}$/', $part) && (int) $part > 0 && !in_array((int) $part, $ids, true)) {
				$ids[] = (int) $part;
			}
		}
		return $ids;
	}

	/**
	 * The categories in the order the association numbered them: 1 first, empty or 0 not at all.
	 *
	 * @param array<int|string,mixed> $positions Category => number as entered
	 * @param int[]                   $known     Categories there are
	 * @return int[] Categories in their order
	 */
	public static function numbered(array $positions, array $known)
	{
		$numbered = array();
		foreach ($positions as $id => $position) {
			$number = is_scalar($position) && preg_match('/^\s*\d{1,4}\s*$/', (string) $position) ? (int) $position : 0;
			if ($number > 0 && in_array((int) $id, $known, true)) {
				$numbered[(int) $id] = $number;
			}
		}
		uksort($numbered, function ($a, $b) use ($numbered) {
			return ($numbered[$a] - $numbered[$b]) ?: $a - $b;
		});
		return array_keys($numbered);
	}

	/**
	 * Partners in the order of the categories the association chose, then by name.
	 *
	 * @param array<int,array{name:string,categories:array<int,array{id:int,label:string}>}> $partners   Partners
	 * @param int[]                                                                          $categories Chosen categories in their order
	 * @return array<int,array<string,mixed>>
	 */
	public static function order(array $partners, array $categories)
	{
		$rank = function (array $partner) use ($categories) {
			$best = count($categories);
			foreach ($partner['categories'] as $category) {
				$index = array_search((int) $category['id'], $categories, true);
				if ($index !== false && $index < $best) {
					$best = $index;
				}
			}
			return $best;
		};
		usort($partners, function ($a, $b) use ($rank) {
			return ($rank($a) - $rank($b)) ?: strnatcasecmp((string) $a['name'], (string) $b['name']);
		});
		return array_values($partners);
	}
}
