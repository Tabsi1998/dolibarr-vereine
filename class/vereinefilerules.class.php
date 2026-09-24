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
 * \file    class/vereinefilerules.class.php
 * \ingroup vereine
 * \brief   How a file leaves the API as it is (#244): its tag, a request that already has it, a part of it.
 *
 * The API hands documents out as the bytes themselves besides the JSON with base64. The tag of a file is the
 * checksum the catalog names, so an application that has it asks with If-None-Match and gets 304; a broken
 * download goes on with Range. Who may have the file is decided before any of this, as for every answer.
 */

/**
 * Rules of handing out a file as it is.
 */
class VereineFileRules
{
	/**
	 * The answer to a request for a file: status, headers and which bytes.
	 *
	 * @param string $method      GET or HEAD
	 * @param int    $size        Size of the file in bytes
	 * @param string $sha256      Checksum of the file, its tag
	 * @param string $ifNoneMatch Header If-None-Match, empty when not sent
	 * @param string $range       Header Range, empty when not sent
	 * @param string $ifRange     Header If-Range, empty when not sent
	 * @return array{status:int,start:int,length:int,body:bool,headers:array<string,string>}
	 */
	public static function answer($method, $size, $sha256, $ifNoneMatch, $range, $ifRange)
	{
		$size = max(0, (int) $size);
		$etag = '"'.$sha256.'"';
		// The answer depends on who asks: a cache may keep it, but asks again every time.
		$headers = array('ETag' => $etag, 'Accept-Ranges' => 'bytes', 'Cache-Control' => 'private, no-cache');
		$body = strtoupper((string) $method) !== 'HEAD';
		if (self::matches($ifNoneMatch, $etag)) {
			return array('status' => 304, 'start' => 0, 'length' => 0, 'body' => false, 'headers' => $headers);
		}
		// A part only of the file the application already has a part of; a changed file comes whole.
		$wanted = trim((string) $range) !== '' && (trim((string) $ifRange) === '' || trim((string) $ifRange) === $etag) ? self::range($range, $size) : null;
		if ($wanted === false) {
			return array('status' => 416, 'start' => 0, 'length' => 0, 'body' => false, 'headers' => $headers + array('Content-Range' => 'bytes */'.$size));
		}
		if ($wanted === null) {
			return array('status' => 200, 'start' => 0, 'length' => $size, 'body' => $body, 'headers' => $headers + array('Content-Length' => (string) $size));
		}
		$length = $wanted[1] - $wanted[0] + 1;
		return array('status' => 206, 'start' => $wanted[0], 'length' => $length, 'body' => $body,
			'headers' => $headers + array('Content-Range' => 'bytes '.$wanted[0].'-'.$wanted[1].'/'.$size, 'Content-Length' => (string) $length));
	}

	/**
	 * Whether If-None-Match names the tag: "*" or one of a list, a weak tag compared by its value.
	 *
	 * @param string $header If-None-Match
	 * @param string $etag   Tag of the file, quoted
	 * @return bool
	 */
	public static function matches($header, $etag)
	{
		$header = trim((string) $header);
		if ($header === '') {
			return false;
		}
		if ($header === '*') {
			return true;
		}
		foreach (explode(',', $header) as $tag) {
			$tag = trim($tag);
			if (strpos($tag, 'W/') === 0) {
				$tag = substr($tag, 2);
			}
			if ($tag === $etag) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The one range of bytes asked for, first and last byte.
	 *
	 * Several ranges, another unit or a range that is no range get the whole file, as HTTP allows; a range
	 * beyond the end cannot be served.
	 *
	 * @param string $header Range, such as bytes=1000- or bytes=-500
	 * @param int    $size   Size of the file
	 * @return array{0:int,1:int}|null|false Null for the whole file, false when it cannot be served
	 */
	public static function range($header, $size)
	{
		if (!preg_match('/^bytes\s*=\s*(\d*)\s*-\s*(\d*)$/i', trim((string) $header), $parts) || ($parts[1] === '' && $parts[2] === '')) {
			return null;
		}
		$size = (int) $size;
		if ($parts[1] === '') {
			// The last bytes of the file.
			$suffix = (int) $parts[2];
			return $suffix > 0 && $size > 0 ? array(max(0, $size - $suffix), $size - 1) : false;
		}
		$start = (int) $parts[1];
		if ($parts[2] !== '' && (int) $parts[2] < $start) {
			return null;
		}
		if ($start >= $size) {
			return false;
		}
		return array($start, $parts[2] === '' ? $size - 1 : min((int) $parts[2], $size - 1));
	}
}
