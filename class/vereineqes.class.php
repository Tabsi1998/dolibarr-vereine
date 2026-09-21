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
 * \file    class/vereineqes.class.php
 * \ingroup vereine
 * \brief   The qualified electronic signature with ID Austria, through a signature service of the association.
 *
 * Only a qualified electronic signature stands for a handwritten one (Art. 25 (2) eIDAS, § 4 (1) SVG).
 * In Austria everybody with ID Austria has one. The module does not sign by itself: it talks to PDF-AS
 * (A-SIT, EUPL 1.2), which the association runs beside Dolibarr; its address is a setting. The document
 * goes along with the request, so the signature service never needs access to Dolibarr.
 *
 * The way there and back, from "Anbindung externer Webanwendung an PDF-AS-WEB 5.0" (EGIZ, 30.04.2025):
 * POST api/v2/sign/single with the PDF and the connector. With mobilebku the answer holds a redirectUrl
 * the person is sent to; with jks (a key store on the server, for tests) the signed PDF comes back at once.
 * After signing, PDF-AS sends the person back to the invoke URL with pdfurl and pdflength; the signed PDF
 * can be fetched there exactly once. POST api/v2/verify reads the signatures of a PDF.
 */

require_once __DIR__.'/vereinelog.class.php';

/**
 * The signature service of the association.
 */
class VereineQes
{
	/** Address of the signature service. */
	const CONST_URL = 'VEREINE_QES_URL';
	/** Which signature device the service uses. */
	const CONST_CONNECTOR = 'VEREINE_QES_CONNECTOR';
	/** Key identifier for a key store of the service. */
	const CONST_KEY = 'VEREINE_QES_KEY_ID';
	/** Signature profile of PDF-AS, empty for its default. */
	const CONST_PROFILE = 'VEREINE_QES_PROFILE';

	/** The person signs with ID Austria on the phone. */
	const CONNECTOR_MOBILE = 'mobilebku';
	/** A key store on the server signs: only for tests, this is no qualified signature of a person. */
	const CONNECTOR_TEST = 'jks';
	/** Every connector the setup offers. */
	const CONNECTORS = array('mobilebku', 'jks');

	/** The signature holds and its certificate is valid. */
	const STATE_VALID = 'valid';
	/** The signature holds, but the service could not say whether the certificate is valid. */
	const STATE_UNCLEAR = 'unclear';
	/** The document was changed after signing, or the certificate is not valid. */
	const STATE_INVALID = 'invalid';

	/** Largest signed document taken from the signature service. */
	const MAX_DOCUMENT = 20971520;

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error, ready to be shown
	 */
	public $error = '';

	/**
	 * @var string[] Language keys of what was refused
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
	 * The settings of the signature service.
	 *
	 * @return array{url:string,connector:string,key:string,profile:string}
	 */
	public static function settings()
	{
		$connector = getDolGlobalString(self::CONST_CONNECTOR);
		return array(
			'url' => rtrim(getDolGlobalString(self::CONST_URL), '/'),
			'connector' => in_array($connector, self::CONNECTORS, true) ? $connector : self::CONNECTOR_MOBILE,
			'key' => getDolGlobalString(self::CONST_KEY),
			'profile' => getDolGlobalString(self::CONST_PROFILE),
		);
	}

	/**
	 * Whether a signature service is set up.
	 *
	 * @return bool
	 */
	public static function configured()
	{
		return self::settings()['url'] !== '';
	}

	/**
	 * Store the settings of the signature service.
	 *
	 * @param string $url       Address of the service, empty to switch it off
	 * @param string $connector One of the CONNECTORS
	 * @param string $key       Key identifier of a key store
	 * @param string $profile   Signature profile of PDF-AS
	 * @param User   $user      Who stores
	 * @return int 1 when stored, 0 when refused (see errors), -1 on error
	 */
	public function saveSettings($url, $connector, $key, $profile, $user)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$this->errors = array();
		$url = rtrim(trim((string) $url), '/');
		if ($url !== '' && !preg_match('#^https?://[^\s/?\#]+(/[^\s?\#]*)?$#i', $url)) {
			$this->errors[] = 'VereineQesErrorUrl';
			return 0;
		}
		$values = array(
			self::CONST_URL => $url,
			self::CONST_CONNECTOR => in_array((string) $connector, self::CONNECTORS, true) ? (string) $connector : self::CONNECTOR_MOBILE,
			self::CONST_KEY => mb_substr(trim((string) $key), 0, 128, 'UTF-8'),
			self::CONST_PROFILE => mb_substr(trim((string) $profile), 0, 64, 'UTF-8'),
		);
		foreach ($values as $name => $value) {
			if (dolibarr_set_const($this->db, $name, $value, 'chaine', 0, '', $conf->entity) < 0) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		VereineLog::add($this->db, $user, VereineLog::QES_SETUP, 0, 0, ($url !== '' ? $url : 'off').' / '.$values[self::CONST_CONNECTOR]);
		return 1;
	}

	/**
	 * What to send for a signature. PDF-AS 5.0 documents the SOAP names (invoke-url) for its JSON interface,
	 * while its first JSON builds read the Java names (invokeURL); both are sent, the service ignores one.
	 *
	 * @param string                                                    $pdf       The document
	 * @param string                                                    $requestId What the answer is about
	 * @param array{url:string,connector:string,key:string,profile:string} $settings  Settings of the service
	 * @param string                                                    $invokeUrl Where the person comes back after signing
	 * @param string                                                    $errorUrl  Where the person comes back when it failed
	 * @return array<string,mixed>
	 */
	public static function signRequest($pdf, $requestId, array $settings, $invokeUrl, $errorUrl)
	{
		$parameters = array('connector' => $settings['connector']);
		if ($settings['connector'] === self::CONNECTOR_MOBILE) {
			$parameters += array('invoke-url' => (string) $invokeUrl, 'invokeURL' => (string) $invokeUrl, 'invoke-error-url' => (string) $errorUrl,
				'invokeErrorURL' => (string) $errorUrl, 'invoke-target' => '_self', 'invokeTarget' => '_self');
		}
		if ($settings['key'] !== '') {
			$parameters['keyIdentifier'] = $settings['key'];
		}
		if ($settings['profile'] !== '') {
			$parameters['profile'] = $settings['profile'];
		}
		return array('requestID' => mb_substr((string) $requestId, 0, 64, 'UTF-8'), 'inputData' => base64_encode((string) $pdf), 'parameters' => $parameters);
	}

	/**
	 * What the answer to a signature says.
	 *
	 * @param mixed $answer Decoded answer of the service
	 * @return array{redirect:string,signed:string,error:string} Where to send the person, or the signed document at once
	 */
	public static function readSignAnswer($answer)
	{
		$answer = is_array($answer) ? $answer : array();
		$signed = !empty($answer['signedPDF']) && is_string($answer['signedPDF']) ? (string) base64_decode($answer['signedPDF'], true) : '';
		$redirect = !empty($answer['redirectUrl']) && is_string($answer['redirectUrl']) && preg_match('#^https?://#i', $answer['redirectUrl']) ? $answer['redirectUrl'] : '';
		$error = !empty($answer['error']) && is_scalar($answer['error']) ? (string) $answer['error'] : '';
		if ($signed !== '' && substr($signed, 0, 5) !== '%PDF-') {
			$signed = '';
			$error = $error !== '' ? $error : 'no PDF';
		}
		return array('redirect' => $redirect, 'signed' => $signed, 'error' => $error);
	}

	/**
	 * What the service read out of a signed document: who signed, and whether it holds.
	 *
	 * The codes are those of an MOA signature check: value 0 means the document is unchanged since signing;
	 * certificate 0 means a valid chain to a trusted root, 3 that the status of a certificate was unknown.
	 * Everything else (no chain, expired, revoked, suspended) is not valid.
	 *
	 * @param mixed $answer Decoded answer of the service
	 * @return array<int,array{index:int,signed_by:string,name:string,intact:bool,state:string,message:string}> In the order of signing
	 */
	public static function readVerifyAnswer($answer)
	{
		$results = array();
		$rows = is_array($answer) && isset($answer['verifyResults']) && is_array($answer['verifyResults']) ? $answer['verifyResults'] : array();
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$value = isset($row['valueCode']) && is_numeric($row['valueCode']) ? (int) $row['valueCode'] : 1;
			$certificate = isset($row['certificateCode']) && is_numeric($row['certificateCode']) ? (int) $row['certificateCode'] : 1;
			$state = self::STATE_INVALID;
			if ($value === 0 && $certificate === 0) {
				$state = self::STATE_VALID;
			} elseif ($value === 0 && $certificate === 3) {
				$state = self::STATE_UNCLEAR;
			}
			$signedBy = isset($row['signedBy']) && is_scalar($row['signedBy']) ? (string) $row['signedBy'] : '';
			$messages = array();
			foreach (array('valueMessage', 'certificateMessage', 'error') as $key) {
				if (!empty($row[$key]) && is_scalar($row[$key])) {
					$messages[] = trim((string) $row[$key]);
				}
			}
			$results[] = array('index' => isset($row['signatureIndex']) && is_numeric($row['signatureIndex']) ? (int) $row['signatureIndex'] : count($results),
				'signed_by' => $signedBy, 'name' => self::commonName($signedBy), 'intact' => $value === 0, 'state' => $state,
				'message' => implode(' ', $messages));
		}
		usort($results, function ($a, $b) {
			return $a['index'] - $b['index'];
		});
		return $results;
	}

	/**
	 * The name in a certificate subject such as "CN=Erika Muster,C=AT".
	 *
	 * @param string $subject Subject of the certificate
	 * @return string The common name, else the subject as it is
	 */
	public static function commonName($subject)
	{
		if (preg_match('/(?:^|,)\s*CN=((?:\\\\,|[^,])+)/i', (string) $subject, $match)) {
			return trim(str_replace('\\,', ',', $match[1]));
		}
		return trim((string) $subject);
	}

	/**
	 * Whether an address belongs to the signature service that is set up: same scheme, host and port.
	 *
	 * @param string $service Address of the service
	 * @param string $url     Address to check
	 * @return bool
	 */
	public static function sameService($service, $url)
	{
		$parts = parse_url((string) $service);
		$given = parse_url((string) $url);
		if (!is_array($parts) || !is_array($given) || !isset($parts['host'], $given['host'], $parts['scheme'], $given['scheme'])) {
			return false;
		}
		$port = function ($url) {
			return isset($url['port']) ? (int) $url['port'] : (strtolower($url['scheme']) === 'https' ? 443 : 80);
		};
		return strtolower($parts['scheme']) === strtolower($given['scheme']) && strtolower($parts['host']) === strtolower($given['host'])
			&& $port($parts) === $port($given) && !isset($given['user']) && !isset($given['pass']);
	}

	/**
	 * Hand a document to the signature service.
	 *
	 * @param string $file      Document to sign
	 * @param string $requestId What the answer is about, at most 64 characters
	 * @param string $invokeUrl Where the person comes back after signing
	 * @param string $errorUrl  Where the person comes back when something went wrong
	 * @return array{redirect:string,signed:string} Where to send the person, or the signed document at once; both empty on error (see errors)
	 */
	public function sign($file, $requestId, $invokeUrl, $errorUrl)
	{
		$this->errors = array();
		$settings = self::settings();
		if ($settings['url'] === '') {
			$this->errors[] = 'VereineQesErrorNotConfigured';
			return array('redirect' => '', 'signed' => '');
		}
		if ((string) $file === '' || !is_file($file)) {
			$this->errors[] = 'VereineSignatureErrorDocument';
			return array('redirect' => '', 'signed' => '');
		}
		$answer = $this->post('/api/v2/sign/single', self::signRequest((string) file_get_contents($file), $requestId, $settings, $invokeUrl, $errorUrl));
		if ($answer === null) {
			return array('redirect' => '', 'signed' => '');
		}
		$read = self::readSignAnswer($answer);
		if ($read['error'] !== '' || ($read['signed'] === '' && $read['redirect'] === '')) {
			$this->error = dol_trunc($read['error'], 250, 'right', 'UTF-8', 1);
			$this->errors[] = 'VereineQesErrorAnswer';
			return array('redirect' => '', 'signed' => '');
		}
		return array('redirect' => $read['redirect'], 'signed' => $read['signed']);
	}

	/**
	 * Fetch the signed document after the person came back. It can be fetched exactly once.
	 *
	 * @param string $pdfurl Address PDF-AS named
	 * @param string $sha    SHA-256 of the document as it was handed over; PDF-AS only answers when it matches
	 * @return string The signed document, empty on error (see errors)
	 */
	public function fetchSigned($pdfurl, $sha)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

		$this->errors = array();
		$settings = self::settings();
		if ($settings['url'] === '' || !self::sameService($settings['url'], (string) $pdfurl)) {
			$this->errors[] = 'VereineQesErrorAddress';
			return '';
		}
		$url = (string) $pdfurl.(strpos((string) $pdfurl, '?') === false ? '?' : '&').'origdigest='.urlencode((string) $sha);
		// The service is set up by an administrator and often runs beside Dolibarr, so a local address is fine.
		$answer = getURLContent($url, 'GET', '', 1, array(), array('http', 'https'), 2);
		$content = isset($answer['content']) ? (string) $answer['content'] : '';
		if ((int) $answer['http_code'] !== 200 || $content === '') {
			$this->error = dol_trunc('HTTP '.((int) $answer['http_code']).' '.(isset($answer['curl_error_msg']) ? (string) $answer['curl_error_msg'] : ''), 250, 'right', 'UTF-8', 1);
			$this->errors[] = 'VereineQesErrorFetch';
			return '';
		}
		if (strlen($content) > self::MAX_DOCUMENT || substr($content, 0, 5) !== '%PDF-') {
			$this->errors[] = 'VereineQesErrorNoPdf';
			return '';
		}
		return $content;
	}

	/**
	 * Read the signatures of a document with the signature service.
	 *
	 * @param string $pdf The document
	 * @return array<int,array{index:int,signed_by:string,name:string,intact:bool,state:string,message:string}>|null Null when the service could not be asked (see errors)
	 */
	public function verify($pdf)
	{
		$this->errors = array();
		if (!self::configured()) {
			$this->errors[] = 'VereineQesErrorNotConfigured';
			return null;
		}
		$answer = $this->post('/api/v2/verify', array('requestID' => 'verify-'.dol_print_date(dol_now(), '%Y%m%d%H%M%S', 'gmt'),
			'inputData' => base64_encode((string) $pdf), 'verificationLevel' => 'full'));
		return $answer === null ? null : self::readVerifyAnswer($answer);
	}

	/**
	 * Ask the signature service, with a JSON body.
	 *
	 * @param string              $path Path below the address of the service
	 * @param array<string,mixed> $body What to send
	 * @return array<string,mixed>|null The answer, null on error (see errors)
	 */
	private function post($path, array $body)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

		// The service is set up by an administrator and often runs beside Dolibarr, so a local address is fine.
		$answer = getURLContent(self::settings()['url'].$path, 'POSTALREADYFORMATED', (string) json_encode($body), 1,
			array('Content-Type: application/json', 'Accept: application/json'), array('http', 'https'), 2);
		$content = isset($answer['content']) ? (string) $answer['content'] : '';
		if ((int) $answer['http_code'] !== 200) {
			$this->error = dol_trunc('HTTP '.((int) $answer['http_code']).' '.(isset($answer['curl_error_msg']) ? (string) $answer['curl_error_msg'] : '').' '.strip_tags($content), 250, 'right', 'UTF-8', 1);
			$this->errors[] = (int) $answer['http_code'] === 0 ? 'VereineQesErrorConnect' : 'VereineQesErrorService';
			return null;
		}
		$decoded = json_decode($content, true);
		if (!is_array($decoded)) {
			$this->errors[] = 'VereineQesErrorAnswer';
			return null;
		}
		return $decoded;
	}
}
