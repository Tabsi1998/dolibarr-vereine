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
 * A stand-in for PDF-AS in the runtime checks: the JSON interface the module uses, without real signatures.
 *
 * api/v2/sign/single keeps the document and answers with a redirectUrl (mobilebku) or signs at once (jks).
 * confirm plays the person who confirms on the phone and sends the browser back to the invoke URL with
 * pdfurl and pdflength; cancel=1 sends it to the error URL instead. PDFData hands the signed document out
 * once, and only with the right origdigest. A "signature" is a comment appended to the PDF with the SHA-256
 * of everything before it, so api/v2/verify can tell whether the document changed afterwards. It is no
 * PAdES signature and proves nothing outside these checks.
 */

$dir = sys_get_temp_dir().'/pdfas-stub';
if (!is_dir($dir)) {
	mkdir($dir, 0700, true);
}
$path = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '/';
$self = 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'];

/**
 * Answer with JSON and stop.
 *
 * @param int                 $code HTTP status
 * @param array<string,mixed> $data Answer
 * @return void
 */
function stubAnswer($code, array $data)
{
	http_response_code($code);
	header('Content-Type: application/json');
	echo json_encode($data);
	exit;
}

/**
 * Append a test signature: the signer and the checksum of everything before it.
 *
 * @param string $pdf  Document
 * @param string $name Who signs
 * @return string
 */
function stubSign($pdf, $name)
{
	$data = base64_encode(json_encode(array('signedBy' => 'CN='.$name.',O=Testdienst,C=AT', 'sha' => hash('sha256', $pdf))));
	return $pdf."\n%VEREINE-TESTSIGNATUR ".$data."\n";
}

/**
 * The file of a job.
 *
 * @param string $dir Folder of the jobs
 * @param string $id  Job
 * @return string
 */
function stubJobFile($dir, $id)
{
	return $dir.'/'.preg_replace('/[^a-f0-9]/', '', (string) $id).'.json';
}

/**
 * The document of a request, or an answer that it is none.
 *
 * @return array<string,mixed> The request with the document decoded under pdf
 */
function stubRequest()
{
	$request = json_decode((string) file_get_contents('php://input'), true);
	$pdf = is_array($request) && isset($request['inputData']) ? base64_decode((string) $request['inputData'], true) : false;
	if ($pdf === false || substr($pdf, 0, 5) !== '%PDF-') {
		stubAnswer(400, array('error' => 'inputData is no PDF'));
	}
	$request['pdf'] = $pdf;
	return $request;
}

if ($path === '/api/v2/sign/single' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$request = stubRequest();
	$parameters = isset($request['parameters']) && is_array($request['parameters']) ? $request['parameters'] : array();
	$connector = isset($parameters['connector']) ? $parameters['connector'] : '';
	$requestId = isset($request['requestID']) ? $request['requestID'] : '';
	if ($connector === 'jks') {
		$key = isset($parameters['keyIdentifier']) ? $parameters['keyIdentifier'] : 'default';
		stubAnswer(200, array('requestID' => $requestId, 'signedPDF' => base64_encode(stubSign($request['pdf'], 'Testschluessel '.$key))));
	}
	if ($connector !== 'mobilebku' || empty($parameters['invoke-url']) || empty($parameters['invoke-error-url'])) {
		stubAnswer(400, array('requestID' => $requestId, 'error' => 'connector mobilebku needs invoke-url and invoke-error-url'));
	}
	$id = bin2hex(random_bytes(16));
	file_put_contents(stubJobFile($dir, $id), json_encode(array('pdf' => base64_encode($request['pdf']), 'invoke' => $parameters['invoke-url'],
		'error' => $parameters['invoke-error-url'], 'base' => $self)));
	// The browser reaches this stand-in under the host of Dolibarr it came from.
	$invoke = parse_url($parameters['invoke-url']);
	$browser = $invoke['scheme'].'://'.$invoke['host'].(isset($invoke['port']) ? ':'.$invoke['port'] : '').$_SERVER['SCRIPT_NAME'];
	stubAnswer(200, array('requestID' => $requestId, 'redirectUrl' => $browser.'/confirm?job='.$id));
}

if ($path === '/confirm') {
	$id = isset($_GET['job']) ? (string) $_GET['job'] : '';
	$file = stubJobFile($dir, $id);
	$job = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
	if (!is_array($job)) {
		http_response_code(404);
		echo 'unknown job';
		exit;
	}
	if (!empty($_GET['cancel'])) {
		unlink($file);
		header('Location: '.$job['error'].(strpos($job['error'], '?') === false ? '?' : '&').'error='.rawurlencode('Abgebrochen').'&cause='.rawurlencode('Die Person hat am Handy abgebrochen.'));
		exit;
	}
	$signed = stubSign(base64_decode($job['pdf']), isset($_GET['name']) ? (string) $_GET['name'] : 'Erika Muster');
	$job['signed'] = base64_encode($signed);
	file_put_contents($file, json_encode($job));
	header('Location: '.$job['invoke'].(strpos($job['invoke'], '?') === false ? '?' : '&').'pdfurl='.rawurlencode($job['base'].'/PDFData?job='.$id).'&pdflength='.strlen($signed));
	exit;
}

if ($path === '/PDFData') {
	$file = stubJobFile($dir, isset($_GET['job']) ? (string) $_GET['job'] : '');
	$job = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
	if (!is_array($job) || empty($job['signed'])) {
		http_response_code(404);
		echo 'no signed document';
		exit;
	}
	if (!isset($_GET['origdigest']) || $_GET['origdigest'] !== hash('sha256', base64_decode($job['pdf']))) {
		http_response_code(400);
		echo 'origdigest does not match';
		exit;
	}
	// Fetched once, then gone, as with PDF-AS.
	unlink($file);
	header('Content-Type: application/pdf');
	echo base64_decode($job['signed']);
	exit;
}

if ($path === '/api/v2/verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$request = stubRequest();
	$pdf = $request['pdf'];
	preg_match_all('/\n%VEREINE-TESTSIGNATUR ([A-Za-z0-9+\/=]+)\n/', $pdf, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
	$results = array();
	foreach ($matches as $index => $match) {
		$data = json_decode((string) base64_decode($match[1][0]), true);
		$intact = is_array($data) && hash('sha256', substr($pdf, 0, $match[0][1])) === $data['sha'];
		$results[] = array('requestID' => isset($request['requestID']) ? $request['requestID'] : '', 'processed' => true, 'signatureIndex' => $index,
			'signedBy' => is_array($data) ? $data['signedBy'] : '', 'certificateCode' => 0, 'certificateMessage' => 'Testzertifikat',
			'valueCode' => $intact ? 0 : 1, 'valueMessage' => $intact ? 'Signatur intakt' : 'Dokument nach der Signatur verändert');
	}
	stubAnswer(200, array('verifyResults' => $results));
}

stubAnswer(404, array('error' => 'unknown path '.$path));
