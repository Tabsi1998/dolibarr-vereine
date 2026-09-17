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
 * \file    tests/runtime/fixtures.php
 * \ingroup vereine
 * \brief   Prepare a fresh Dolibarr for the runtime checks.
 *
 * php fixtures.php base    company, Members and API modules, two users with API keys
 * php fixtures.php rights  after the module is enabled: the reader gets the read right
 * php fixtures.php readmembers  the reader may also read, not change, members and third parties
 * php fixtures.php cardmember  a validated member without third party, for the member card
 * php fixtures.php invoicing  products, a customer and a supplier invoice with tax profiles
 * php fixtures.php turnover  validated invoices at the turn of 2025 to 2026; the reader may read invoices
 * php fixtures.php cashpayments  cash and bank payments on the invoice of 2026
 * php fixtures.php website  a website user with two rights and members in every fee situation
 * php fixtures.php onlinepayment  Stripe on (RT_ONLINE=1) or off, for payment links
 * php fixtures.php websiteinvoices  an abandoned invoice for a member's third party
 * php fixtures.php websitechange  a part payment and a new subscription period for the website sync
 * php fixtures.php websiteflip  a subscription period that ended yesterday
 * php fixtures.php webhook  Dolibarr's webhook module with a target for VEREINE_MEMBER_CHANGED
 * php fixtures.php webhookchanges  payments, a subscription period and a resignation in one request
 * php fixtures.php webhookdown  a blocking target that cannot be reached, and a member update
 * php fixtures.php feerunmember  a member with fee and third party, validated today
 * php fixtures.php payinvoice  pay the rest of an invoice, closing it as paid
 *
 * Prints one JSON object. Passwords and API keys come from the environment only.
 */

require __DIR__.'/bootstrap.php';

global $db, $conf;

$stage = isset($argv[1]) ? $argv[1] : '';
$admin = rt_admin($db);
$GLOBALS['user'] = $admin;

if ($stage === 'base') {
	rt_const($db, 'MAIN_LANG_DEFAULT', 'de_DE');
	rt_const($db, 'MAIN_MONNAIE', 'EUR');
	rt_const($db, 'MAIN_INFO_SOCIETE_ADDRESS', 'Teststraße 1');
	rt_const($db, 'MAIN_INFO_SOCIETE_ZIP', '6020');
	rt_const($db, 'MAIN_INFO_SOCIETE_TOWN', 'Innsbruck');
	rt_const($db, 'MAIN_INFO_SOCIETE_MAIL', 'office@runtime-verein.test');

	foreach (array('modAdherent', 'modApi') as $module) {
		$result = activateModule($module);
		if (!empty($result['errors'])) {
			rt_fail('activating '.$module.' failed: '.implode(' | ', (array) $result['errors']));
		}
	}

	$users = array();
	foreach (array('rtreader' => 'RT_READER', 'rtnobody' => 'RT_NOBODY') as $login => $prefix) {
		$new = new User($db);
		$new->login = $login;
		$new->lastname = ucfirst($login);
		$new->firstname = 'Runtime';
		$new->email = $login.'@runtime-verein.test';
		$new->admin = 0;
		$new->entity = 1;
		if ($new->create($admin) <= 0) {
			rt_fail('user '.$login.': '.$new->error);
		}
		if ($new->setPassword($admin, rt_env($prefix.'_PASSWORD')) === -1) {
			rt_fail('password for '.$login.': '.$new->error);
		}
		$new->fetch($new->id);
		$new->api_key = rt_env($prefix.'_KEY');
		if ($new->update($admin) <= 0) {
			rt_fail('API key for '.$login.': '.$new->error);
		}
		$users[$login] = (int) $new->id;
	}

	print json_encode(array(
		'dolibarr' => DOL_VERSION,
		'php' => PHP_VERSION,
		'users' => $users,
	))."\n";
	exit(0);
}

if ($stage === 'rights') {
	$rightId = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module = 'vereine' AND perms = 'association' AND subperms = 'read' AND entity = 1");
	if ($rightId <= 0) {
		rt_fail('the right vereine/association/read is not registered');
	}
	$reader = new User($db);
	if ($reader->fetch(0, 'rtreader') <= 0) {
		rt_fail('the user rtreader does not exist');
	}
	if ($reader->addrights($rightId) < 0) {
		rt_fail('granting the read right: '.$reader->error);
	}
	print json_encode(array('right' => $rightId))."\n";
	exit(0);
}

// The reader may read members and third parties, but change neither.
if ($stage === 'readmembers') {
	$reader = new User($db);
	if ($reader->fetch(0, 'rtreader') <= 0) {
		rt_fail('the user rtreader does not exist');
	}
	$granted = array();
	foreach (array('adherent', 'societe') as $module) {
		$rightId = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module = '".$module."' AND perms = 'lire' AND (subperms IS NULL OR subperms = '') AND entity = 1");
		if ($rightId <= 0 || $reader->addrights($rightId) < 0) {
			rt_fail('granting '.$module.'/lire: '.$reader->error);
		}
		$granted[] = $rightId;
	}
	print json_encode(array('rights' => $granted))."\n";
	exit(0);
}

if ($stage === 'members') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent_type.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';

	rt_const($db, 'ADHERENT_LOGIN_NOT_REQUIRED', '1');
	rt_const($db, 'ADHERENT_MAIL_REQUIRED', '0');
	$conf->setValues($db);
	$countryId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");

	$type = new AdherentType($db);
	$type->label = 'Ordentliches Mitglied';
	$type->morphy = '';
	$type->subscription = 0;
	$typeId = $type->create($admin);
	if ($typeId <= 0) {
		rt_fail('member type: '.$type->error);
	}

	/**
	 * Create a third party without member.
	 *
	 * @param DoliDB $db        Database handler
	 * @param User   $admin     Administrator
	 * @param string $name      Name
	 * @param string $email     E-mail
	 * @param int    $countryId Country
	 * @return int
	 */
	function rt_partner($db, $admin, $name, $email, $countryId)
	{
		$partner = new Societe($db);
		$partner->name = $name;
		$partner->email = $email;
		$partner->address = 'Teststraße 1';
		$partner->zip = '6020';
		$partner->town = 'Innsbruck';
		$partner->country_id = $countryId;
		$partner->client = 1;
		$partner->code_client = -1;
		if ($partner->create($admin) <= 0) {
			rt_fail('third party '.$name.': '.$partner->error);
		}
		return (int) $partner->id;
	}

	$annaPartner = rt_partner($db, $admin, 'Anna Vorhanden', 'anna@runtime-verein.test', $countryId);
	$oldPartner = rt_partner($db, $admin, 'Alt Partner', 'alt@runtime-verein.test', $countryId);
	$memberCategory = new Categorie($db);
	$memberCategory->fetch((int) getDolGlobalInt('VEREINE_CATEGORY_MEMBER'));
	$old = new Societe($db);
	$old->fetch($oldPartner);
	if ($memberCategory->add_type($old, 'customer') < 0) {
		rt_fail('orphan category: '.$memberCategory->error);
	}

	/**
	 * Create a member and validate it unless it stays a draft.
	 *
	 * @param DoliDB $db        Database handler
	 * @param User   $admin     Administrator
	 * @param int    $typeId    Member type
	 * @param array  $fields    Member fields
	 * @param bool   $validate  Whether to validate
	 * @param int    $countryId Country
	 * @return int
	 */
	function rt_member($db, $admin, $typeId, array $fields, $validate, $countryId)
	{
		$member = new Adherent($db);
		$member->typeid = $typeId;
		$member->morphy = 'phy';
		$member->address = 'Teststraße 1';
		$member->zip = '6020';
		$member->town = 'Innsbruck';
		$member->country_id = $countryId;
		$member->public = 0;
		foreach ($fields as $name => $value) {
			$member->$name = $value;
		}
		if ($member->create($admin) <= 0) {
			rt_fail('member '.$member->lastname.': '.$member->error.' '.implode(' | ', (array) $member->errors));
		}
		if ($validate && $member->validate($admin) <= 0) {
			rt_fail('validate member '.$member->lastname.': '.$member->error);
		}
		return (int) $member->id;
	}

	$members = array(
		'lisa' => rt_member($db, $admin, $typeId, array('firstname' => 'Lisa', 'lastname' => 'Neu', 'email' => 'lisa@runtime-verein.test'), true, $countryId),
		'anna' => rt_member($db, $admin, $typeId, array('firstname' => 'Anna', 'lastname' => 'Vorhanden', 'email' => 'anna@runtime-verein.test'), true, $countryId),
		'kind' => rt_member($db, $admin, $typeId, array('firstname' => 'Kim', 'lastname' => 'Jung', 'email' => 'kind@runtime-verein.test', 'birth' => dol_mktime(12, 0, 0, 5, 5, 2015)), true, $countryId),
		'sponsor' => rt_member($db, $admin, $typeId, array('morphy' => 'mor', 'company' => 'Sponsor GmbH', 'societe' => 'Sponsor GmbH', 'firstname' => 'Max', 'lastname' => 'Kontakt', 'email' => 'sponsor@runtime-verein.test'), true, $countryId),
		'draft' => rt_member($db, $admin, $typeId, array('firstname' => 'Erik', 'lastname' => 'Entwurf', 'email' => 'entwurf@runtime-verein.test'), false, $countryId),
	);
	print json_encode(array('type' => $typeId, 'members' => $members, 'partners' => array('anna' => $annaPartner, 'old' => $oldPartner)))."\n";
	exit(0);
}

// A validated member without third party, for Dolibarr's own member card.
if ($stage === 'cardmember') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	$member = new Adherent($db);
	$member->typeid = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."adherent_type WHERE libelle = 'Ordentliches Mitglied'");
	$member->morphy = 'phy';
	$member->firstname = 'Karl';
	$member->lastname = 'Karte';
	$member->email = 'karl.karte@runtime-verein.test';
	$member->address = 'Kartenweg 3';
	$member->zip = '6020';
	$member->town = 'Innsbruck';
	$member->country_id = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");
	$member->public = 0;
	if ($member->create($admin) <= 0 || $member->validate($admin) <= 0) {
		rt_fail('member Karl Karte: '.$member->error.' '.implode(' | ', (array) $member->errors));
	}
	print json_encode(array('member' => (int) $member->id))."\n";
	exit(0);
}

// Products, invoices and supplier invoices with tax profiles, created the way Dolibarr's own code does.
if ($stage === 'invoicing') {
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
	foreach (array('modProduct', 'modService', 'modFacture', 'modFournisseur') as $module) {
		$result = activateModule($module);
		if (!empty($result['errors'])) {
			rt_fail('activating '.$module.' failed: '.implode(' | ', (array) $result['errors']));
		}
	}
	$conf->setValues($db);
	$admin->getrights();
	$countryId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");
	$profile = array();
	foreach (array('MITGLIEDSBEITRAG', 'BETRIEB_20', 'VEREINSFEST') as $code) {
		$profile[$code] = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_taxprofile WHERE code = '".$code."'");
	}

	$products = array();
	foreach (array('fee' => array('RT-BEITRAG', 'Mitgliedsbeitrag 2027', 1, 50, 20, 'MITGLIEDSBEITRAG'), 'drink' => array('RT-GETRAENK', 'Getränk Kantine', 0, 3, 10, 'BETRIEB_20')) as $key => $data) {
		$product = new Product($db);
		$product->ref = $data[0];
		$product->label = $data[1];
		$product->type = $data[2];
		$product->price = $data[3];
		$product->price_base_type = 'HT';
		$product->tva_tx = $data[4];
		$product->status = 1;
		$product->status_buy = 1;
		$product->array_options = array('options_vereine_taxprofile' => $profile[$data[5]]);
		if ($product->create($admin) <= 0) {
			rt_fail('product '.$data[0].': '.$product->error.' '.implode(' | ', (array) $product->errors));
		}
		$products[$key] = (int) $product->id;
	}

	$partner = new Societe($db);
	$partner->name = 'Rechnung Kunde';
	$partner->client = 1;
	$partner->fournisseur = 1;
	$partner->code_client = -1;
	$partner->code_fournisseur = -1;
	$partner->country_id = $countryId;
	if ($partner->create($admin) <= 0) {
		rt_fail('invoice partner: '.$partner->error);
	}

	$invoice = new Facture($db);
	$invoice->socid = (int) $partner->id;
	$invoice->type = Facture::TYPE_STANDARD;
	$invoice->date = dol_now();
	if ($invoice->create($admin) <= 0) {
		rt_fail('invoice: '.$invoice->error);
	}
	// Fee at 0 % as its profile says, drink at 10 % although its profile says 20 %, a free line with its own profile.
	$lines = array(
		array('Mitgliedsbeitrag 2027', 50, 0, $products['fee'], array()),
		array('Getränk Kantine', 3, 10, $products['drink'], array()),
		array('Buffet Sommerfest', 5, 0, 0, array('options_vereine_taxprofile' => $profile['VEREINSFEST'])),
	);
	$invoiceLines = array();
	foreach ($lines as $index => $line) {
		$result = $invoice->addline($line[0], $line[1], 1, $line[2], 0, 0, $line[3], 0, '', '', 0, 0, 0, 'HT', 0, $index === 0 ? 1 : 0, $index + 1, 0, '', 0, 0, null, 0, '', $line[4]);
		if ($result <= 0) {
			rt_fail('invoice line '.$line[0].': '.$invoice->error);
		}
		$invoiceLines[] = (int) $result;
	}

	$supplierInvoice = new FactureFournisseur($db);
	$supplierInvoice->socid = (int) $partner->id;
	$supplierInvoice->ref_supplier = 'RT-EINKAUF-1';
	$supplierInvoice->date = dol_now();
	$supplierInvoice->type = FactureFournisseur::TYPE_STANDARD;
	if ($supplierInvoice->create($admin) <= 0) {
		rt_fail('supplier invoice: '.$supplierInvoice->error);
	}
	$result = $supplierInvoice->addline('Getränke Einkauf', 2, 20, 0, 0, 10, $products['drink']);
	if ($result <= 0) {
		rt_fail('supplier invoice line: '.$supplierInvoice->error);
	}

	print json_encode(array(
		'products' => $products,
		'profiles' => $profile,
		'invoice' => (int) $invoice->id,
		'invoice_lines' => $invoiceLines,
		'supplier_invoice' => (int) $supplierInvoice->id,
		'supplier_line' => (int) $result,
	))."\n";
	exit(0);
}

// Validated invoices on both sides of a new year, for the thresholds.
if ($stage === 'turnover') {
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	$reader = new User($db);
	$rightId = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module = 'facture' AND perms = 'lire' AND (subperms IS NULL OR subperms = '') AND entity = 1");
	if ($reader->fetch(0, 'rtreader') <= 0 || $rightId <= 0 || $reader->addrights($rightId) < 0) {
		rt_fail('granting facture/lire to the reader: '.$reader->error);
	}
	$canteen = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_taxprofile WHERE code = 'BETRIEB_20'");
	$fee = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_taxprofile WHERE code = 'MITGLIEDSBEITRAG'");
	$socid = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE nom = 'Rechnung Kunde'");
	// Date, lines (label, net, VAT, profile id or 0), validate.
	$invoices = array(
		'2025' => array(dol_mktime(12, 0, 0, 12, 31, 2025), array(array('Kantine Silvester', 1000, 20, $canteen)), true),
		'2026' => array(dol_mktime(12, 0, 0, 1, 1, 2026), array(array('Kantine Turniersaison', 50000, 20, $canteen), array('Mitgliedsbeiträge', 20000, 0, $fee), array('Ohne Profil', 500, 20, 0)), true),
		'draft' => array(dol_mktime(12, 0, 0, 6, 1, 2026), array(array('Entwurf Kantine', 99999, 20, $canteen)), false),
	);
	$ids = array();
	foreach ($invoices as $key => $data) {
		$invoice = new Facture($db);
		$invoice->socid = $socid;
		$invoice->type = Facture::TYPE_STANDARD;
		$invoice->date = $data[0];
		if ($invoice->create($admin) <= 0) {
			rt_fail('invoice '.$key.': '.$invoice->error);
		}
		foreach ($data[1] as $index => $line) {
			$options = $line[3] > 0 ? array('options_vereine_taxprofile' => $line[3]) : array();
			if ($invoice->addline($line[0], $line[1], 1, $line[2], 0, 0, 0, 0, '', '', 0, 0, 0, 'HT', 0, 1, $index + 1, 0, '', 0, 0, null, 0, '', $options) <= 0) {
				rt_fail('invoice line '.$line[0].': '.$invoice->error);
			}
		}
		if ($data[2] && $invoice->validate($admin) <= 0) {
			rt_fail('validate invoice '.$key.': '.$invoice->error.' '.implode(' | ', (array) $invoice->errors));
		}
		$ids[$key] = (int) $invoice->id;
	}
	print json_encode(array('invoices' => $ids))."\n";
	exit(0);
}

// Payments on the 2026 invoice: one in cash, one by bank transfer.
if ($stage === 'cashpayments') {
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
	$invoiceId = (int) rt_env('RT_INVOICE_ID');
	$ids = array();
	foreach (array('LIQ' => 20000, 'VIR' => 5000) as $code => $amount) {
		$payment = new Paiement($db);
		$payment->datepaye = dol_mktime(12, 0, 0, 2, 1, 2026);
		$payment->date = $payment->datepaye;
		$payment->amounts = array($invoiceId => $amount);
		$payment->paiementid = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = '".$code."' AND entity IN (0, 1) ORDER BY entity DESC");
		$payment->paiementcode = $code;
		if ($payment->create($admin) <= 0) {
			rt_fail('payment '.$code.': '.$payment->error.' '.implode(' | ', (array) $payment->errors));
		}
		$ids[$code] = (int) $payment->id;
	}
	print json_encode(array('payments' => $ids))."\n";
	exit(0);
}

// A website user with exactly the two documented rights, and one member per fee situation.
if ($stage === 'website') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent_type.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';

	$website = new User($db);
	$website->login = 'rtwebsite';
	$website->lastname = 'Website';
	$website->firstname = 'Runtime';
	$website->admin = 0;
	$website->entity = 1;
	if ($website->create($admin) <= 0) {
		rt_fail('user rtwebsite: '.$website->error);
	}
	$website->fetch($website->id);
	$website->api_key = rt_env('RT_WEBSITE_KEY');
	if ($website->update($admin) <= 0) {
		rt_fail('API key for rtwebsite: '.$website->error);
	}
	foreach (array('association', 'website') as $perms) {
		$rightId = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module = 'vereine' AND perms = '".$perms."' AND subperms = 'read' AND entity = 1");
		if ($rightId <= 0 || $website->addrights($rightId) < 0) {
			rt_fail('granting vereine/'.$perms.'/read to rtwebsite: '.$website->error);
		}
	}

	$feeType = new AdherentType($db);
	$feeType->label = 'Beitragspflichtig';
	$feeType->morphy = '';
	$feeType->subscription = 1;
	$feeType->amount = 50;
	$feeTypeId = $feeType->create($admin);
	if ($feeTypeId <= 0) {
		rt_fail('member type with fee: '.$feeType->error);
	}
	$freeTypeId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."adherent_type WHERE libelle = 'Ordentliches Mitglied'");
	$countryId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");
	$today = dol_mktime(0, 0, 0, (int) dol_print_date(dol_now(), '%m'), (int) dol_print_date(dol_now(), '%d'), (int) dol_print_date(dol_now(), '%Y'));
	$day = function ($offset) use ($today) {
		return dol_time_plus_duree($today, $offset, 'd');
	};
	$ymd = function ($time) {
		return dol_print_date($time, '%Y-%m-%d');
	};

	// Key => type, first name, last name, e-mail, period start and end as days from today (or null), resiliate.
	$people = array(
		'paid' => array($feeTypeId, 'Paula', 'Bezahlt', 'Paula.Bezahlt@Runtime-Verein.test', array(-30, 334), false),
		'expired' => array($feeTypeId, 'Emil', 'Abgelaufen', 'emil@runtime-verein.test', array(-400, -35), false),
		'unpaid' => array($feeTypeId, 'Nina', 'Neu', 'nina@runtime-verein.test', null, false),
		'free' => array($freeTypeId, 'Otto', 'Ohnebeitrag', 'familie@runtime-verein.test', null, false),
		'terminated' => array($feeTypeId, 'Tom', 'Ausgetreten', 'familie@runtime-verein.test', array(-200, 165), true),
	);
	$members = array();
	$refs = array();
	foreach ($people as $key => $data) {
		$member = new Adherent($db);
		$member->typeid = $data[0];
		$member->morphy = 'phy';
		$member->firstname = $data[1];
		$member->lastname = $data[2];
		$member->email = $data[3];
		$member->address = 'Geheimgasse 7';
		$member->zip = '6020';
		$member->town = 'Innsbruck';
		$member->country_id = $countryId;
		$member->phone = '+43 512 999999';
		$member->birth = dol_mktime(12, 0, 0, 7, 14, 1990);
		$member->note_private = 'Geheimnotiz '.$key;
		$member->public = 0;
		if ($member->create($admin) <= 0 || $member->validate($admin) <= 0) {
			rt_fail('member '.$key.': '.$member->error.' '.implode(' | ', (array) $member->errors));
		}
		if ($data[4] !== null && $member->subscription($day($data[4][0]), 50, 0, '', 'Beitrag', '', '', '', $day($data[4][1])) <= 0) {
			rt_fail('subscription of '.$key.': '.$member->error.' '.implode(' | ', (array) $member->errors));
		}
		if ($data[5] && $member->resiliate($admin) <= 0) {
			rt_fail('resiliate '.$key.': '.$member->error);
		}
		$members[$key] = (int) $member->id;
		$refs[$key] = (string) rt_value($db, "SELECT ref FROM ".MAIN_DB_PREFIX."adherent WHERE rowid = ".((int) $member->id));
	}

	// Paula's third party gets an open invoice with a part payment, a paid one and a draft.
	$paula = new Adherent($db);
	$paula->fetch($members['paid']);
	$partner = new Societe($db);
	if ($partner->create_from_member($paula) <= 0) {
		rt_fail('third party of Paula: '.$partner->error.' '.implode(' | ', (array) $partner->errors));
	}
	$invoices = array();
	foreach (array('open' => array(60, -40, 10), 'paid' => array(30, -20, 30), 'draft' => array(99, -1, 0)) as $key => $data) {
		$invoice = new Facture($db);
		$invoice->socid = (int) $partner->id;
		$invoice->type = Facture::TYPE_STANDARD;
		$invoice->date = $day($data[1]);
		if ($invoice->create($admin) <= 0 || $invoice->addline('Mitgliedsbeitrag', $data[0], 1, 0) <= 0) {
			rt_fail('invoice '.$key.' of Paula: '.$invoice->error);
		}
		if ($key !== 'draft' && $invoice->validate($admin) <= 0) {
			rt_fail('validate invoice '.$key.' of Paula: '.$invoice->error.' '.implode(' | ', (array) $invoice->errors));
		}
		if ($data[2] > 0) {
			$payment = new Paiement($db);
			$payment->datepaye = $day(-5);
			$payment->date = $payment->datepaye;
			$payment->amounts = array((int) $invoice->id => $data[2]);
			$payment->paiementid = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = 'VIR' AND entity IN (0, 1) ORDER BY entity DESC");
			$payment->paiementcode = 'VIR';
			if ($payment->create($admin, $key === 'paid' ? 1 : 0) <= 0) {
				rt_fail('payment on invoice '.$key.': '.$payment->error.' '.implode(' | ', (array) $payment->errors));
			}
		}
		$invoice->fetch($invoice->id);
		$invoices[$key] = array('id' => (int) $invoice->id, 'ref' => (string) $invoice->ref);
	}

	print json_encode(array(
		'user' => (int) $website->id,
		'members' => $members,
		'refs' => $refs,
		'invoices' => $invoices,
		'dates' => array(
			'today' => $ymd($today),
			'paid_until' => $ymd($day(334)),
			'paid_since' => $ymd($day(-30)),
			'expired_until' => $ymd($day(-35)),
			'expired_since' => $ymd($day(-400)),
			'open_invoice' => $ymd($day(-40)),
			'paid_invoice' => $ymd($day(-20)),
		),
	))."\n";
	exit(0);
}

// Changes a website sync must notice: a part payment on an invoice and a new subscription period.
if ($stage === 'websitechange') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
	$today = dol_mktime(0, 0, 0, (int) dol_print_date(dol_now(), '%m'), (int) dol_print_date(dol_now(), '%d'), (int) dol_print_date(dol_now(), '%Y'));
	$payment = new Paiement($db);
	$payment->datepaye = $today;
	$payment->date = $today;
	$payment->amounts = array((int) rt_env('RT_INVOICE_ID') => 5);
	$payment->paiementid = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = 'VIR' AND entity IN (0, 1) ORDER BY entity DESC");
	$payment->paiementcode = 'VIR';
	if ($payment->create($admin) <= 0) {
		rt_fail('part payment: '.$payment->error.' '.implode(' | ', (array) $payment->errors));
	}
	$member = new Adherent($db);
	if ($member->fetch((int) rt_env('RT_MEMBER_ID')) <= 0 || $member->subscription($today, 50, 0, '', 'Beitrag', '', '', '', dol_time_plus_duree($today, 364, 'd')) <= 0) {
		rt_fail('new subscription period: '.$member->error.' '.implode(' | ', (array) $member->errors));
	}
	print json_encode(array('payment' => (int) $payment->id, 'paid_until' => dol_print_date(dol_time_plus_duree($today, 364, 'd'), '%Y-%m-%d')))."\n";
	exit(0);
}

// A subscription period that ended yesterday: the fee became due today by the date alone.
if ($stage === 'websiteflip') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
	$midnight = dol_mktime(0, 0, 0, (int) substr($today, 5, 2), (int) substr($today, 8, 2), (int) substr($today, 0, 4), 'tzserver');
	$member = new Adherent($db);
	if ($member->fetch((int) rt_env('RT_MEMBER_ID')) <= 0
		|| $member->subscription(dol_time_plus_duree($midnight, -365, 'd'), 50, 0, '', 'Beitrag', '', '', '', dol_time_plus_duree($midnight, -1, 'd')) <= 0) {
		rt_fail('ended subscription period: '.$member->error.' '.implode(' | ', (array) $member->errors));
	}
	print json_encode(array(
		'moment' => gmdate('Y-m-d\TH:i:s\Z', $midnight),
		'paid_until' => dol_print_date(dol_time_plus_duree($midnight, -1, 'd'), '%Y-%m-%d', 'tzserver'),
		'backdate' => $db->idate(dol_time_plus_duree($midnight, -10, 'd')),
	))."\n";
	exit(0);
}

// Dolibarr's webhook module with one target for the module's event.
if ($stage === 'webhook') {
	$result = activateModule('modWebhook');
	if (!empty($result['errors'])) {
		rt_fail('activating modWebhook failed: '.implode(' | ', (array) $result['errors']));
	}
	$conf->setValues($db);
	require_once DOL_DOCUMENT_ROOT.'/webhook/class/target.class.php';
	$target = new Target($db);
	$target->ref = 'RT-WEBSITE';
	$target->label = 'Runtime website';
	$target->type = 1;
	$target->trigger_codes = 'VEREINE_MEMBER_CHANGED';
	$target->url = rt_env('RT_WEBHOOK_URL');
	$target->status = Target::STATUS_AUTOMATIC_TRIGGER;
	if ($target->create($admin) <= 0) {
		rt_fail('webhook target: '.$target->error.' '.implode(' | ', (array) $target->errors));
	}
	dol_include_once('/vereine/class/vereinewebsiteevents.class.php');
	print json_encode(array('target' => (int) $target->id, 'register_again' => VereineWebsiteEvents::ensureTriggerCode($db)))."\n";
	exit(0);
}

// Changes that concern three members and one third party without member, in one request.
if ($stage === 'webhookchanges') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
	// The receiver runs in the same container; conf.php of a real installation would not allow that.
	$GLOBALS['dolibarr_allow_localurl_for_webhooks'] = 1;
	$today = dol_mktime(0, 0, 0, (int) dol_print_date(dol_now(), '%m'), (int) dol_print_date(dol_now(), '%d'), (int) dol_print_date(dol_now(), '%Y'));
	foreach (array('RT_PAYMENT_INVOICE', 'RT_OTHER_INVOICE') as $name) {
		$payment = new Paiement($db);
		$payment->datepaye = $today;
		$payment->date = $today;
		$payment->amounts = array((int) rt_env($name) => 1);
		$payment->paiementid = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = 'VIR' AND entity IN (0, 1) ORDER BY entity DESC");
		$payment->paiementcode = 'VIR';
		if ($payment->create($admin) <= 0) {
			rt_fail('payment for '.$name.': '.$payment->error.' '.implode(' | ', (array) $payment->errors));
		}
	}
	// The next period: one member cannot have two periods starting on the same day.
	$member = new Adherent($db);
	if ($member->fetch((int) rt_env('RT_SUBSCRIPTION_MEMBER')) <= 0
		|| $member->subscription(dol_time_plus_duree($today, 365, 'd'), 50, 0, '', 'Beitrag', '', '', '', dol_time_plus_duree($today, 729, 'd')) <= 0) {
		rt_fail('subscription period: '.$member->error.' '.implode(' | ', (array) $member->errors));
	}
	$member = new Adherent($db);
	if ($member->fetch((int) rt_env('RT_RESILIATE_MEMBER')) <= 0 || $member->resiliate($admin) <= 0) {
		rt_fail('resiliate: '.$member->error);
	}
	print json_encode(array('done' => 1))."\n";
	exit(0);
}

// A blocking webhook target that cannot be reached, and a member change that must still succeed.
if ($stage === 'webhookdown') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	$GLOBALS['dolibarr_allow_localurl_for_webhooks'] = 1;
	if (!$db->query("UPDATE ".MAIN_DB_PREFIX."webhook_target SET type = 0, url = 'http://127.0.0.1:9/nothing' WHERE ref = 'RT-WEBSITE'")) {
		rt_fail('make the webhook target blocking: '.$db->lasterror());
	}
	$member = new Adherent($db);
	if ($member->fetch((int) rt_env('RT_MEMBER_ID')) <= 0) {
		rt_fail('member for the blocked webhook: '.$member->error);
	}
	$member->phone_mobile = '+43 660 0000000';
	$result = $member->update($admin);
	print json_encode(array('update' => (int) $result, 'error' => (string) $member->error))."\n";
	exit(0);
}

// A member of the member type with fee, validated today, with third party: a first fee with admission fee.
if ($stage === 'feerunmember') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	$member = new Adherent($db);
	$member->typeid = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."adherent_type WHERE libelle = 'Beitragspflichtig'");
	$member->morphy = 'phy';
	$member->firstname = 'Fiona';
	$member->lastname = 'Frisch';
	$member->email = 'fiona@runtime-verein.test';
	$member->country_id = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");
	$member->public = 0;
	if ($member->create($admin) <= 0 || $member->validate($admin) <= 0) {
		rt_fail('member Fiona: '.$member->error.' '.implode(' | ', (array) $member->errors));
	}
	$member->fetch($member->id);
	$partner = new Societe($db);
	if ((int) $member->fk_soc <= 0 && $partner->create_from_member($member) <= 0) {
		rt_fail('third party of Fiona: '.$partner->error);
	}
	print json_encode(array('member' => (int) $member->id))."\n";
	exit(0);
}

// Pay what is left of an invoice by bank transfer, closing it as paid.
if ($stage === 'payinvoice') {
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
	$invoice = new Facture($db);
	if ($invoice->fetch((int) rt_env('RT_INVOICE_ID')) <= 0) {
		rt_fail('invoice to pay: '.$invoice->error);
	}
	$payment = new Paiement($db);
	$payment->datepaye = dol_now();
	$payment->date = $payment->datepaye;
	$payment->amounts = array((int) $invoice->id => (float) $invoice->getRemainToPay(0));
	$payment->paiementid = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = 'VIR' AND entity IN (0, 1) ORDER BY entity DESC");
	$payment->paiementcode = 'VIR';
	if ($payment->create($admin, 1) <= 0) {
		rt_fail('pay invoice: '.$payment->error.' '.implode(' | ', (array) $payment->errors));
	}
	print json_encode(array('payment' => (int) $payment->id))."\n";
	exit(0);
}

// An abandoned invoice for the third party of a member.
if ($stage === 'websiteinvoices') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	$socid = (int) rt_value($db, "SELECT fk_soc FROM ".MAIN_DB_PREFIX."adherent WHERE rowid = ".((int) rt_env('RT_MEMBER_ID')));
	$date = dol_time_plus_duree(dol_mktime(0, 0, 0, (int) dol_print_date(dol_now(), '%m'), (int) dol_print_date(dol_now(), '%d'), (int) dol_print_date(dol_now(), '%Y')), -10, 'd');
	$invoice = new Facture($db);
	$invoice->socid = $socid;
	$invoice->type = Facture::TYPE_STANDARD;
	$invoice->date = $date;
	if ($socid <= 0 || $invoice->create($admin) <= 0 || $invoice->addline('Turnierbeitrag', 20, 1, 0) <= 0 || $invoice->validate($admin) <= 0) {
		rt_fail('abandoned invoice: '.$invoice->error.' '.implode(' | ', (array) $invoice->errors));
	}
	if ($invoice->setCanceled($admin, Facture::CLOSECODE_ABANDONED, 'Runtime') <= 0) {
		rt_fail('abandon invoice: '.$invoice->error);
	}
	$invoice->fetch($invoice->id);
	print json_encode(array('abandoned' => array('id' => (int) $invoice->id, 'ref' => (string) $invoice->ref, 'date' => dol_print_date($date, '%Y-%m-%d'))))."\n";
	exit(0);
}

// Online payment through Stripe on (RT_ONLINE=1) or off, so Dolibarr offers payment links or not.
if ($stage === 'onlinepayment') {
	if (rt_env('RT_ONLINE') === '1') {
		$result = activateModule('modStripe');
		if (!empty($result['errors'])) {
			rt_fail('activating modStripe failed: '.implode(' | ', (array) $result['errors']));
		}
	} elseif (unActivateModule('modStripe') !== '') {
		rt_fail('disabling modStripe failed');
	}
	print json_encode(array('stripe' => rt_env('RT_ONLINE') === '1' ? 1 : 0))."\n";
	exit(0);
}

if ($stage === 'resiliate') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	$member = new Adherent($db);
	if ($member->fetch((int) rt_env('RT_MEMBER_ID')) <= 0 || $member->resiliate($admin) <= 0) {
		rt_fail('resiliate member: '.$member->error);
	}
	print json_encode(array('resiliated' => (int) $member->id))."\n";
	exit(0);
}

if ($stage === 'guardian') {
	require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
	$contact = new Contact($db);
	$contact->socid = (int) rt_env('RT_PARTNER_ID');
	$contact->firstname = 'Gerda';
	$contact->lastname = 'Jung';
	$contact->email = 'gerda.jung@runtime-verein.test';
	$contact->statut = 1;
	if ($contact->create($admin) <= 0) {
		rt_fail('guardian contact: '.$contact->error);
	}
	$category = new Categorie($db);
	if ($category->fetch((int) getDolGlobalInt('VEREINE_CATEGORY_GUARDIAN')) <= 0 || $category->add_type($contact, 'contact') < 0) {
		rt_fail('guardian category: '.$category->error);
	}
	print json_encode(array('guardian' => (int) $contact->id))."\n";
	exit(0);
}

if ($stage === 'reset') {
	// After the upgrade test: back to a Dolibarr that never had the module, apart from its files.
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
	unActivateModule('modVereine');
	foreach (array('VEREINE_CATEGORY_MEMBER', 'VEREINE_CATEGORY_FORMER', 'VEREINE_CATEGORY_GUARDIAN') as $name) {
		$category = new Categorie($db);
		if (getDolGlobalInt($name) > 0 && $category->fetch(getDolGlobalInt($name)) > 0 && $category->delete($admin) < 0) {
			rt_fail('delete category '.$name.': '.$category->error);
		}
	}
	if (!$db->query("DELETE FROM ".MAIN_DB_PREFIX."const WHERE name LIKE 'VEREINE\\_%'")) {
		rt_fail('delete constants: '.$db->lasterror());
	}
	require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
	foreach (array('product', 'facturedet', 'facture_fourn_det') as $elementtype) {
		$extrafields = new ExtraFields($db);
		$extrafields->delete('vereine_taxprofile', $elementtype);
	}
	foreach (array('vereine_fee_start_month', 'vereine_fee_proration', 'vereine_fee_prorated', 'vereine_admission_fee', 'vereine_fee_product') as $name) {
		$extrafields = new ExtraFields($db);
		$extrafields->delete($name, 'adherent_type');
	}
	foreach (array('vereine_log', 'vereine_taxprofile') as $table) {
		if (!$db->query("DROP TABLE IF EXISTS ".MAIN_DB_PREFIX.$table)) {
			rt_fail('drop table '.$table.': '.$db->lasterror());
		}
	}
	print json_encode(array('reset' => 1))."\n";
	exit(0);
}

rt_fail('unknown stage "'.$stage.'", use base, rights, readmembers, members, cardmember, invoicing, turnover, cashpayments, website, onlinepayment, websiteinvoices, websitechange, websiteflip, webhook, webhookchanges, webhookdown, feerunmember, payinvoice, resiliate, guardian or reset');
