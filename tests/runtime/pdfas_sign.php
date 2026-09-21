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
 * A test signature in a PDF, the way PDF-AS puts one there: appended after the document, a signature
 * dictionary whose ByteRange covers everything but its Contents, and a detached CMS in Contents, made
 * with a throwaway self-signed certificate. For the stand-in of PDF-AS and the unit tests only.
 *
 * Before the signature dictionary stands a comment with the signer and the SHA-256 of the document up to
 * there, so the stand-in can answer api/v2/verify the way PDF-AS with MOA-SP would.
 */

/**
 * Append a test signature.
 *
 * @param string $pdf     Document
 * @param string $name    Who signs: the common name of the throwaway certificate
 * @param string $workdir Folder for temporary files
 * @return string The signed document
 */
function vereineTestSignPdf($pdf, $name, $workdir)
{
	$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
	$request = openssl_csr_new(array('commonName' => $name, 'organizationName' => 'Testdienst', 'countryName' => 'AT'), $key, array('digest_alg' => 'sha256'));
	$certificate = openssl_csr_sign($request, null, $key, 30, array('digest_alg' => 'sha256'));

	$marker = "\n%VEREINE-TESTSIGNATUR ".base64_encode((string) json_encode(array('signedBy' => 'CN='.$name.',O=Testdienst,C=AT',
		'sha' => hash('sha256', $pdf))))."\n";
	$dictionary = "9999 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached /ByteRange [0 %010d %010d %010d] /Contents ";
	$suffix = "\n>>\nendobj\n";
	$room = 16384;
	// The numbers have a fixed width, so the offsets do not depend on them.
	$b = strlen($pdf.$marker) + strlen(sprintf($dictionary, 0, 0, 0));
	$c = $b + $room + 2;
	$head = $pdf.$marker.sprintf($dictionary, $b, $c, strlen($suffix));

	$in = tempnam($workdir, 'sig');
	$out = tempnam($workdir, 'sig');
	file_put_contents($in, $head.$suffix);
	openssl_pkcs7_sign($in, $out, $certificate, $key, array(), PKCS7_DETACHED | PKCS7_BINARY);
	$smime = (string) file_get_contents($out);
	unlink($in);
	unlink($out);
	preg_match('/Content-Type: application\/(?:x-)?pkcs7-signature.*?\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/s', $smime, $match);
	$hex = strtoupper(bin2hex((string) base64_decode(str_replace(array("\r", "\n"), '', $match[1]))));
	return $head.'<'.str_pad($hex, $room, '0').'>'.$suffix;
}
