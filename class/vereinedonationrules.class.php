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
 * \file    class/vereinedonationrules.class.php
 * \ingroup vereine
 * \brief   Rules of the donation report to the tax office (#6), without database.
 *
 * An association that may take deductible donations reports, by the end of February, the sum each donor
 * gave in the year before, with the donor's encrypted personal identifier for taxes (vbPK SA). The file
 * follows the schema of the Ministry of Finance (UebermittlungSonderausgaben_2.xsd, as of 24 July 2024);
 * the identifiers come from the register of source numbers (SZR) in its batch format. Sources: BMF,
 * "Allgemeines zur Sonderausgaben-Datenuebermittlung" (as of 19 January 2026), and the SZR batch
 * documentation (version 2.3 of 9 August 2023).
 */

/**
 * Rules of the donation report.
 */
class VereineDonationRules
{
	/** Namespace of the transmission and of the protocol. */
	const NS = 'https://finanzonline.bmf.gv.at/fon/ws/uebermittlungSonderausgaben';

	/** First transmission of a donor's year. */
	const TYPE_FIRST = 'E';
	/** A changed sum. */
	const TYPE_CHANGE = 'A';
	/** Cancellation: the whole reference number leaves the records of the tax office. */
	const TYPE_CANCEL = 'S';

	/** The first year that can be reported. */
	const FIRST_YEAR = 2017;

	/**
	 * Kinds of body the tax office knows (Uebermittlungsart), as the schema lists them. Most associations
	 * are GM (other charitable bodies) or SP (sports).
	 */
	const KINDS = array('GM', 'SP', 'SO', 'NT', 'KK', 'FF', 'BI', 'FW', 'SN', 'SV', 'SE', 'SG', 'ZG', 'ZI', 'UN', 'AS', 'MÖ', 'MP', 'KR', 'PA', 'ÖK', 'ÖS', 'PK', 'PS');

	/** What the register answered for a person. */
	const STATE_NONE = '';
	const STATE_FOUND = 'found';
	const STATE_NOT_FOUND = 'notfound';
	const STATE_AMBIGUOUS = 'ambiguous';
	const STATE_ERROR = 'error';
	/** States a donor's identifier can be in, for the page. */
	const STATES = array('found', 'notfound', 'ambiguous', 'error');

	/** Columns of the register's batch file, in the order it fixes. */
	const SZR_COLUMNS = array('LAUFNR', 'NACHNAME', 'VORNAME', 'GEBDATUM', 'NAME_VOR_ERSTER_EHE', 'GEBORT', 'GESCHLECHT',
		'STAATSANGEHÖRIGKEIT', 'ANSCHRIFTSSTAAT', 'GEMEINDENAME', 'PLZ', 'STRASSE', 'HAUSNR');

	/**
	 * The language key suffix of a kind: the schema has umlauts, language keys do not.
	 *
	 * @param string $kind One of KINDS
	 * @return string
	 */
	public static function kindKey($kind)
	{
		return strtr((string) $kind, array('Ö' => 'OE'));
	}

	/**
	 * A reference number of the payer as the schema takes it, empty when it cannot be one.
	 *
	 * @param mixed $value Entered
	 * @return string
	 */
	public static function refNr($value)
	{
		$value = trim((string) $value);
		return preg_match('/^[0-9a-zA-Z\-\\\\\/]{1,23}$/', $value) ? $value : '';
	}

	/**
	 * An encrypted identifier as the register delivers it: 172 characters of Base64; empty when it is none.
	 *
	 * @param mixed $value Entered or read, line breaks and spaces are dropped
	 * @return string
	 */
	public static function vbpk($value)
	{
		$value = preg_replace('/\s+/', '', (string) $value);
		return preg_match('/^[0-9a-zA-Z+\/=]{172}$/', (string) $value) ? (string) $value : '';
	}

	/**
	 * A tax number of FinanzOnline: nine digits, empty when there is none.
	 *
	 * @param mixed $value Entered, spaces, slashes and dashes are dropped
	 * @return string|null Null when something was entered that is no tax number
	 */
	public static function fastnr($value)
	{
		$value = preg_replace('/[\s\/\-]+/', '', (string) $value);
		if ($value === '') {
			return '';
		}
		return preg_match('/^[0-9]{9}$/', (string) $value) ? (string) $value : null;
	}

	/**
	 * A date of birth the register can search with: a real day, from 1850, not after today.
	 *
	 * @param mixed  $value Entered, YYYY-MM-DD
	 * @param string $today Today, YYYY-MM-DD
	 * @return string Empty when it is none
	 */
	public static function birthDate($value, $today)
	{
		$value = trim((string) $value);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
			return '';
		}
		return (int) $parts[1] >= 1850 && $value <= $today ? $value : '';
	}

	/**
	 * A sum as the schema writes it: two decimals with a point, from 0.01 to 999999.99.
	 *
	 * @param float $amount Sum
	 * @return string Empty when the schema does not take it
	 */
	public static function amount($amount)
	{
		$amount = round((float) $amount, 2);
		return $amount >= 0.01 && $amount <= 999999.99 ? number_format($amount, 2, '.', '') : '';
	}

	/**
	 * What a donor's year needs now, by what the tax office already holds.
	 *
	 * @param float      $current The year's sum now
	 * @param float|null $held    What the tax office holds after the last accepted transmission, null for nothing
	 * @return string One of the TYPE constants, empty when nothing is to be sent
	 */
	public static function transmission($current, $held)
	{
		$current = round((float) $current, 2);
		if ($held === null || round((float) $held, 2) < 0.01) {
			// Nothing held, or cancelled before: a first transmission, when there is something to report.
			return $current >= 0.01 ? self::TYPE_FIRST : '';
		}
		if ($current < 0.01) {
			return self::TYPE_CANCEL;
		}
		return abs($current - round((float) $held, 2)) >= 0.005 ? self::TYPE_CHANGE : '';
	}

	/**
	 * A reference of the transmission that stays unique for the association: year, report and time.
	 *
	 * @param int    $year     Year reported
	 * @param int    $reportId Report
	 * @param string $stamp    Time, YYYYMMDDHHMMSS
	 * @return string At most 36 characters of the schema's alphabet
	 */
	public static function messageRef($year, $reportId, $stamp)
	{
		return substr('VRN-'.((int) $year).'-'.((int) $reportId).'-'.preg_replace('/[^0-9]/', '', (string) $stamp), 0, 36);
	}

	/**
	 * Street and house number apart, as the register wants them; a street without a number stays whole.
	 *
	 * @param string $address Address line
	 * @return array{0:string,1:string}
	 */
	public static function splitAddress($address)
	{
		$address = trim(preg_replace('/\s+/', ' ', (string) $address));
		if (preg_match('/^(.*\D)\s+(\d[\w\/\-]*)$/u', $address, $parts)) {
			return array(trim($parts[1]), $parts[2]);
		}
		return array($address, '');
	}

	/**
	 * The batch file for the register: header, an empty line, the column line, one line per person.
	 *
	 * @param array<string,string>             $header  contact, email, reference, vkz
	 * @param array<int,array<string,string>> $people  ref, lastname, firstname, birth, town, zip, address, country
	 * @return string UTF-8, lines ending in CR LF
	 */
	public static function szrFile(array $header, array $people)
	{
		$clean = static function ($value) {
			// The separator and line breaks cannot stand in a field of this format.
			return trim(str_replace(array(';', "\r", "\n"), array(',', ' ', ' '), (string) $value));
		};
		$lines = array(
			'KONTAKT='.$clean(isset($header['contact']) ? $header['contact'] : ''),
			'E-MAIL='.$clean(isset($header['email']) ? $header['email'] : ''),
			'REFERENZ='.$clean(isset($header['reference']) ? $header['reference'] : ''),
			'VKZ='.$clean(isset($header['vkz']) ? $header['vkz'] : ''),
			'BETRIEBSUMGEBUNG=PROD',
			'VERSCHLÜSSELTEBPK=BMF+SA',
			'TRENNZEICHEN=;',
			'DATUMSFORMAT=JJJJ-MM-TT',
			'BPKBERECHNUNG=TRUE',
			'SUCHWIZARD=FALSE',
			'INSERTERNP=FALSE',
			'MEHRFACHTREFFER=FALSE',
			'',
			implode(';', self::SZR_COLUMNS),
		);
		foreach ($people as $person) {
			$street = self::splitAddress(isset($person['address']) ? $person['address'] : '');
			$country = isset($person['country']) && $person['country'] === 'AT' ? 'AUT' : '';
			$lines[] = implode(';', array_map($clean, array($person['ref'], $person['lastname'], $person['firstname'], $person['birth'],
				'', '', '', '', $country, isset($person['town']) ? $person['town'] : '', isset($person['zip']) ? $person['zip'] : '', $street[0], $street[1])));
		}
		return implode("\r\n", $lines)."\r\n";
	}

	/**
	 * What a result file of the register says: the running numbers it holds, and the identifiers found.
	 *
	 * Reads the files of the register's ZIP (VERSCHL_BPK, KEINTREFFER, NICHT_EINDEUTIG, ERROR): the header
	 * lines are skipped up to the column line that starts with LAUFNR.
	 *
	 * @param string $content File content
	 * @return array{refs:string[],vbpk:array<string,string>}
	 */
	public static function szrResult($content)
	{
		$result = array('refs' => array(), 'vbpk' => array());
		$content = (string) $content;
		if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
			$content = substr($content, 3);
		}
		$columns = null;
		$column = -1;
		foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
			if ($columns === null) {
				if (strncmp($line, 'LAUFNR;', 7) === 0) {
					$columns = explode(';', $line);
					foreach ($columns as $index => $name) {
						if (stripos($name, 'VBPK') === 0) {
							$column = $index;
						}
					}
				}
				continue;
			}
			if (trim($line) === '') {
				continue;
			}
			$fields = explode(';', $line);
			$ref = self::refNr($fields[0]);
			if ($ref === '') {
				continue;
			}
			$result['refs'][] = $ref;
			$vbpk = $column >= 0 && isset($fields[$column]) ? self::vbpk($fields[$column]) : '';
			if ($vbpk !== '') {
				$result['vbpk'][$ref] = $vbpk;
			}
		}
		return $result;
	}

	/**
	 * What a result file is, by its name: the register names each file after its content.
	 *
	 * @param string $name File name
	 * @return string One of STATES, empty when it is none of the result files
	 */
	public static function szrKind($name)
	{
		$name = strtoupper((string) $name);
		foreach (array('VERSCHL_BPK' => self::STATE_FOUND, 'KEINTREFFER' => self::STATE_NOT_FOUND,
			'NICHT_EINDEUTIG' => self::STATE_AMBIGUOUS, '_ERROR' => self::STATE_ERROR) as $part => $state) {
			if (strpos($name, $part) !== false) {
				return $state;
			}
		}
		return '';
	}

	/**
	 * The transmission as XML of the schema.
	 *
	 * @param array<string,string>             $spec  message_ref, timestamp (YYYY-MM-DDTHH:MM:SS), kind, year, fastnr_tn, fastnr_org
	 * @param array<int,array<string,string>> $lines type, ref, amount (formatted), vbpk
	 * @return string
	 */
	public static function xml(array $spec, array $lines)
	{
		$doc = new DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;
		$root = $doc->createElementNS(self::NS, 'SonderausgabenUebermittlung');
		$doc->appendChild($root);
		$add = static function ($parent, $name, $value = null) use ($doc) {
			$element = $doc->createElementNS(self::NS, $name);
			if ($value !== null) {
				$element->appendChild($doc->createTextNode((string) $value));
			}
			$parent->appendChild($element);
			return $element;
		};
		// Without a tax number of the association the whole block stays out; a service provider has both.
		if (!empty($spec['fastnr_org'])) {
			$info = $add($root, 'Info_Daten');
			$add($info, 'Fastnr_Fon_Tn', !empty($spec['fastnr_tn']) ? $spec['fastnr_tn'] : $spec['fastnr_org']);
			$add($info, 'Fastnr_Org', $spec['fastnr_org']);
		}
		$message = $add($root, 'MessageSpec');
		$add($message, 'MessageRefId', $spec['message_ref']);
		$add($message, 'Timestamp', $spec['timestamp']);
		$add($message, 'Uebermittlungsart', $spec['kind']);
		$add($message, 'Zeitraum', $spec['year']);
		foreach ($lines as $line) {
			$item = $add($root, 'Sonderausgaben');
			$item->setAttribute('Uebermittlungs_Typ', $line['type']);
			$add($item, 'RefNr', $line['ref']);
			if ($line['type'] !== self::TYPE_CANCEL) {
				$add($item, 'Betrag', $line['amount']);
			}
			if ($line['type'] === self::TYPE_FIRST) {
				$add($item, 'vbPK', $line['vbpk']);
			}
		}
		return (string) $doc->saveXML();
	}

	/**
	 * Check an XML against a schema.
	 *
	 * @param string $xml    Document
	 * @param string $schema Path of the XSD
	 * @return string[] What the schema finds, empty when the document holds
	 */
	public static function validate($xml, $schema)
	{
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$doc = new DOMDocument();
		$problems = array();
		if (!$doc->loadXML((string) $xml, LIBXML_NONET) || !$doc->schemaValidate($schema)) {
			foreach (libxml_get_errors() as $error) {
				$problems[] = trim($error->message).' (line '.$error->line.')';
			}
			if (!$problems) {
				$problems[] = 'not valid';
			}
		}
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		return $problems;
	}

	/**
	 * What a protocol of the DataBox says: OK, TWOK (partly) or NOK, and the errors per reference number.
	 *
	 * @param string $xml Protocol
	 * @return array{message_ref:string,info:string,test:bool,errors:array<string,string[]>,general:string[]}|null Null when it is no protocol
	 */
	public static function protocol($xml)
	{
		$previous = libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		$loaded = $doc->loadXML((string) $xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded || $doc->documentElement === null || $doc->documentElement->localName !== 'SonderausgabenResponse') {
			return null;
		}
		$path = new DOMXPath($doc);
		$path->registerNamespace('s', self::NS);
		$text = static function ($query, $context = null) use ($path) {
			$nodes = $context === null ? $path->query($query) : $path->query($query, $context);
			return $nodes !== false && $nodes->length > 0 ? trim((string) $nodes->item(0)->textContent) : '';
		};
		$info = $text('/s:SonderausgabenResponse/s:MessageSpec/s:Info');
		if (!in_array($info, array('OK', 'TWOK', 'NOK'), true)) {
			return null;
		}
		$errors = array();
		foreach ($path->query('/s:SonderausgabenResponse/s:SonderausgabenError') as $node) {
			$ref = $text('s:RefNr', $node);
			foreach ($path->query('s:Error', $node) as $error) {
				$errors[$ref][] = $text('s:Code', $error).' '.$text('s:Text', $error);
			}
		}
		$general = array();
		foreach ($path->query('/s:SonderausgabenResponse/s:MessageSpec/s:Error') as $error) {
			$general[] = $text('s:Code', $error).' '.$text('s:Text', $error);
		}
		return array('message_ref' => $text('/s:SonderausgabenResponse/s:MessageSpec/s:MessageRefId'), 'info' => $info,
			'test' => $text('/s:SonderausgabenResponse/s:MessageSpec/s:Uebermittlung') === 'T', 'errors' => $errors, 'general' => $general);
	}

	/**
	 * Whether a line of a transmission was taken, by its protocol.
	 *
	 * @param array<string,mixed> $protocol See protocol()
	 * @param string              $ref      Reference number of the line
	 * @return bool
	 */
	public static function accepted(array $protocol, $ref)
	{
		if ($protocol['info'] === 'NOK') {
			return false;
		}
		return !isset($protocol['errors'][(string) $ref]);
	}
}
