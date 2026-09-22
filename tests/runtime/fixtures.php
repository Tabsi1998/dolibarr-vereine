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
 * php fixtures.php discountmembers  a child, two students and an honorary member for the discounts
 * php fixtures.php audit  a bank with bookings, a supplier invoice of an officer and an auditor with own login (RT_YEAR, RT_AUDITOR_PASSWORD)
 * php fixtures.php account  a paid invoice over two areas, part of the officer's invoice paid, a cash box with a transfer, an open invoice (RT_YEAR)
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
	// Outgoing mail goes to the Mailpit container of the stack.
	rt_const($db, 'MAIN_MAIL_EMAIL_FROM', 'robot@runtime-verein.test');
	rt_const($db, 'MAIN_MAIL_SENDMODE', 'smtps');
	rt_const($db, 'MAIN_MAIL_SMTP_SERVER', 'mail');
	rt_const($db, 'MAIN_MAIL_SMTP_PORT', '1025');
	rt_const($db, 'MAIN_MAIL_EMAIL_TLS', '0');
	rt_const($db, 'MAIN_MAIL_EMAIL_STARTTLS', '0');
	rt_const($db, 'MAIN_DISABLE_ALL_MAILS', '0');

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
	foreach (array('MITGLIEDSBEITRAG', 'BETRIEB_20', 'VEREINSFEST', 'KLEINUNTERNEHMER') as $code) {
		$profile[$code] = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_taxprofile WHERE code = '".$code."'");
	}

	$products = array();
	foreach (array('fee' => array('RT-BEITRAG', 'Mitgliedsbeitrag 2027', 1, 50, 20, 'MITGLIEDSBEITRAG'), 'drink' => array('RT-GETRAENK', 'Getränk Kantine', 0, 3, 10, 'BETRIEB_20'),
		'shirt' => array('RT-TRIKOT', 'Vereinstrikot', 0, 25, 0, 'KLEINUNTERNEHMER')) as $key => $data) {
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
	// Sold at 0 % as a small business, bought at 20 %: the supplier's VAT is right (#53).
	if ($supplierInvoice->addline('Trikots Einkauf', 15, 20, 0, 0, 10, $products['shirt']) <= 0) {
		rt_fail('supplier invoice line of the shirts: '.$supplierInvoice->error);
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

// A year to audit: a bank account with bookings, a supplier invoice of an officer, an auditor with own login.
if ($stage === 'audit') {
	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
	$result = activateModule('modBanque');
	if (!empty($result['errors'])) {
		rt_fail('activating modBanque failed: '.implode(' | ', (array) $result['errors']));
	}
	$conf->setValues($db);
	$admin->getrights();
	$year = (int) rt_env('RT_YEAR');
	$day = function ($month, $dayOfMonth) use ($year) {
		return dol_mktime(12, 0, 0, $month, $dayOfMonth, $year);
	};

	$account = new Account($db);
	$account->ref = 'RTBANK';
	$account->label = 'Vereinskonto';
	$account->type = Account::TYPE_CURRENT;
	$account->courant = Account::TYPE_CURRENT;
	$account->currency_code = 'EUR';
	$account->country_id = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");
	$account->date_solde = $day(1, 1);
	$account->solde = 0;
	$account->clos = 0;
	if ($account->create($admin) <= 0) {
		rt_fail('bank account: '.$account->error.' '.implode(' | ', (array) $account->errors));
	}
	$lines = array();
	foreach (array('small1' => array(2, 3, 'Kleinbetrag Getränke', 20), 'small2' => array(3, 4, 'Kleinbetrag Material', -30),
		'no_document' => array(4, 5, 'Bargeld ohne Beleg', -40), 'large' => array(5, 6, 'Großspende Firma', 5000)) as $key => $data) {
		$lineId = $account->addline($day($data[0], $data[1]), 'VIR', $data[2], $data[3], '', 0, $admin);
		if ($lineId <= 0) {
			rt_fail('bank line '.$key.': '.$account->error);
		}
		$lines[$key] = (int) $lineId;
	}

	// The chair's third party supplies the association: a transaction of an officer (§ 6 (4) VerG).
	$sql = "SELECT t.fk_adherent FROM ".MAIN_DB_PREFIX."vereine_function_term as t INNER JOIN ".MAIN_DB_PREFIX."vereine_function as f ON f.rowid = t.fk_function";
	$sql .= " WHERE f.code = 'obmann' AND (t.date_end IS NULL OR t.date_end >= CURDATE()) ORDER BY t.rowid DESC LIMIT 1";
	$chair = rt_value($db, $sql);
	if (!$chair) {
		rt_fail('the functions scenario should leave a chair in office');
	}
	$socid = (int) rt_value($db, "SELECT fk_soc FROM ".MAIN_DB_PREFIX."adherent WHERE rowid = ".((int) $chair));
	if ($socid <= 0) {
		$party = new Societe($db);
		$party->name = 'Obmann Handel';
		$party->fournisseur = 1;
		$party->code_fournisseur = -1;
		$party->country_id = $account->country_id;
		if ($party->create($admin) <= 0) {
			rt_fail('third party of the chair: '.$party->error);
		}
		$socid = (int) $party->id;
		$db->query("UPDATE ".MAIN_DB_PREFIX."adherent SET fk_soc = ".$socid." WHERE rowid = ".((int) $chair));
	}
	$db->query("UPDATE ".MAIN_DB_PREFIX."societe SET fournisseur = 1 WHERE rowid = ".$socid);
	$invoice = new FactureFournisseur($db);
	$invoice->socid = $socid;
	$invoice->ref_supplier = 'RT-OBMANN-'.$year;
	$invoice->date = $day(6, 15);
	$invoice->type = FactureFournisseur::TYPE_STANDARD;
	if ($invoice->create($admin) <= 0 || $invoice->addline('Miete Vereinsbus', 300, 0, 0, 0, 1) <= 0 || $invoice->validate($admin) <= 0) {
		rt_fail('supplier invoice of the chair: '.$invoice->error);
	}

	// An auditor with own login: a member without function, the auditing function, read rights only.
	$sql = "SELECT d.rowid FROM ".MAIN_DB_PREFIX."adherent as d WHERE d.statut = 1 AND d.morphy = 'phy' AND d.lastname <> ''";
	$sql .= " AND d.rowid NOT IN (SELECT fk_adherent FROM ".MAIN_DB_PREFIX."vereine_function_term)";
	$sql .= " AND d.rowid NOT IN (SELECT fk_member FROM ".MAIN_DB_PREFIX."user WHERE fk_member IS NOT NULL) ORDER BY d.rowid LIMIT 1";
	$member = (int) rt_value($db, $sql);
	$function = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_function WHERE code = 'rechnungspruefung'");
	if ($member <= 0 || $function <= 0) {
		rt_fail('no member without function, or no auditing function');
	}
	$sql = "INSERT INTO ".MAIN_DB_PREFIX."vereine_function_term (entity, fk_function, fk_adherent, date_start, datec)";
	$sql .= " VALUES (1, ".$function.", ".$member.", '".($year - 1)."-06-01', '".$db->idate(dol_now())."')";
	$db->query($sql);
	$auditor = new User($db);
	$auditor->login = 'rtauditor';
	$auditor->lastname = 'Prüferin';
	$auditor->firstname = 'Runtime';
	$auditor->admin = 0;
	$auditor->entity = 1;
	if ($auditor->create($admin) <= 0 || $auditor->setPassword($admin, rt_env('RT_AUDITOR_PASSWORD')) === -1) {
		rt_fail('the auditor: '.$auditor->error);
	}
	$db->query("UPDATE ".MAIN_DB_PREFIX."user SET fk_member = ".$member." WHERE rowid = ".((int) $auditor->id));
	foreach (array(array('vereine', 'association', 'read'), array('facture', 'lire', ''), array('fournisseur', 'facture', 'lire'), array('banque', 'lire', '')) as $right) {
		$sql = "SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module = '".$right[0]."' AND perms = '".$right[1]."' AND entity = 1";
		$sql .= $right[2] !== '' ? " AND subperms = '".$right[2]."'" : " AND (subperms IS NULL OR subperms = '')";
		$rightId = (int) rt_value($db, $sql." ORDER BY id LIMIT 1");
		if ($rightId <= 0 || $auditor->addrights($rightId) < 0) {
			rt_fail('right '.implode('/', $right).' for the auditor: '.$auditor->error);
		}
	}
	print json_encode(array('account' => (int) $account->id, 'lines' => $lines, 'officer_invoice' => (int) $invoice->id,
		'auditor_member' => $member, 'auditor_name' => trim((string) rt_value($db, "SELECT lastname FROM ".MAIN_DB_PREFIX."adherent WHERE rowid = ".$member)),
		'chair' => (int) $chair))."\n";
	exit(0);
}

// The money of a year for the account: a paid invoice over two areas, part of the chair's invoice paid,
// a cash box with an initial balance and a transfer to it, an invoice still open at the end of the year.
if ($stage === 'account') {
	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
	$year = (int) rt_env('RT_YEAR');
	$day = function ($month, $dayOfMonth) use ($year) {
		return dol_mktime(12, 0, 0, $month, $dayOfMonth, $year);
	};
	$bank = new Account($db);
	if ($bank->fetch(0, 'RTBANK') <= 0) {
		rt_fail('the audit fixture should leave the bank account RTBANK');
	}
	$transfer = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = 'VIR' AND entity IN (0, 1) ORDER BY entity DESC");
	$lines = array();

	$cash = new Account($db);
	$cash->ref = 'RTKASSA';
	// The name Dolibarr's point of sale gives its cash box: a language key.
	$cash->label = 'DefaultCashPOSLabel';
	$cash->type = Account::TYPE_CASH;
	$cash->courant = Account::TYPE_CASH;
	$cash->currency_code = 'EUR';
	$cash->country_id = $bank->country_id;
	$cash->date_solde = $day(1, 1);
	$cash->solde = 100;
	$cash->clos = 0;
	if ($cash->create($admin) <= 0) {
		rt_fail('cash box: '.$cash->error.' '.implode(' | ', (array) $cash->errors));
	}
	$lines['cash_opening'] = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."bank WHERE fk_account = ".((int) $cash->id)." AND fk_type = 'SOLD'");

	// Two invoices: one paid in full over two areas, one still open at the end of the year.
	$socid = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE nom = 'Rechnung Kunde'");
	$profile = function ($code) use ($db) {
		return (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."vereine_taxprofile WHERE code = '".$code."'");
	};
	$invoices = array();
	foreach (array('paid' => array(array('Mitgliedsbeitrag Konto', 50, 0, 'MITGLIEDSBEITRAG'), array('Kantine Konto', 100, 20, 'BETRIEB_20')),
		'open' => array(array('Kantine offen', 80, 20, 'BETRIEB_20'))) as $key => $invoiceLines) {
		$invoice = new Facture($db);
		$invoice->socid = $socid;
		$invoice->type = Facture::TYPE_STANDARD;
		$invoice->date = $day(6, 20);
		if ($invoice->create($admin) <= 0) {
			rt_fail('invoice '.$key.': '.$invoice->error);
		}
		foreach ($invoiceLines as $index => $line) {
			if ($invoice->addline($line[0], $line[1], 1, $line[2], 0, 0, 0, 0, '', '', 0, 0, 0, 'HT', 0, 1, $index + 1, 0, '', 0, 0, null, 0, '',
				array('options_vereine_taxprofile' => $profile($line[3]))) <= 0) {
				rt_fail('invoice line '.$line[0].': '.$invoice->error);
			}
		}
		if ($invoice->validate($admin) <= 0) {
			rt_fail('validate invoice '.$key.': '.$invoice->error.' '.implode(' | ', (array) $invoice->errors));
		}
		$invoice->fetch($invoice->id);
		$invoices[$key] = $invoice;
	}
	$payment = new Paiement($db);
	$payment->datepaye = $day(7, 1);
	$payment->date = $payment->datepaye;
	$payment->amounts = array($invoices['paid']->id => 170);
	$payment->paiementid = $transfer;
	$payment->paiementcode = 'VIR';
	if ($payment->create($admin, 1) <= 0) {
		rt_fail('payment of the invoice: '.$payment->error.' '.implode(' | ', (array) $payment->errors));
	}
	$lines['invoice'] = (int) $payment->addPaymentToBank($admin, 'payment', '(CustomerInvoicePayment)', (int) $bank->id, '', '');
	if ($lines['invoice'] <= 0) {
		rt_fail('payment of the invoice to the bank: '.$payment->error);
	}

	// A third of the chair's invoice paid; its line is an expense of the ideal area.
	$officerInvoice = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."facture_fourn WHERE ref_supplier = 'RT-OBMANN-".$year."'");
	if ($officerInvoice <= 0) {
		rt_fail('the audit fixture should leave the invoice of the chair');
	}
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."facture_fourn_det_extrafields WHERE fk_object IN (SELECT rowid FROM ".MAIN_DB_PREFIX."facture_fourn_det WHERE fk_facture_fourn = ".$officerInvoice.")");
	$db->query("INSERT INTO ".MAIN_DB_PREFIX."facture_fourn_det_extrafields (fk_object, vereine_expense_sphere) SELECT rowid, 'ideal' FROM ".MAIN_DB_PREFIX."facture_fourn_det WHERE fk_facture_fourn = ".$officerInvoice);
	$supplierPayment = new PaiementFourn($db);
	$supplierPayment->datepaye = $day(7, 2);
	$supplierPayment->date = $supplierPayment->datepaye;
	$supplierPayment->amounts = array($officerInvoice => 100);
	$supplierPayment->paiementid = $transfer;
	$supplierPayment->paiementcode = 'VIR';
	if ($supplierPayment->create($admin) <= 0) {
		rt_fail('payment to the chair: '.$supplierPayment->error.' '.implode(' | ', (array) $supplierPayment->errors));
	}
	$lines['supplier'] = (int) $supplierPayment->addPaymentToBank($admin, 'payment_supplier', '(SupplierInvoicePayment)', (int) $bank->id, '', '');
	if ($lines['supplier'] <= 0) {
		rt_fail('payment to the chair from the bank: '.$supplierPayment->error);
	}

	// 50 euros from the bank into the cash box, linked both ways as Dolibarr's transfer does.
	$lines['transfer_out'] = (int) $bank->addline($day(7, 3), 'VIR', 'Umbuchung in die Kassa', -50, '', 0, $admin);
	$lines['transfer_in'] = (int) $cash->addline($day(7, 3), 'LIQ', 'Umbuchung in die Kassa', 50, '', 0, $admin);
	if ($lines['transfer_out'] <= 0 || $lines['transfer_in'] <= 0) {
		rt_fail('transfer to the cash box: '.$bank->error.' '.$cash->error);
	}
	$bank->add_url_line($lines['transfer_out'], $lines['transfer_in'], DOL_URL_ROOT.'/compta/bank/line.php?rowid=', '(banktransfert)', 'banktransfert');
	$cash->add_url_line($lines['transfer_in'], $lines['transfer_out'], DOL_URL_ROOT.'/compta/bank/line.php?rowid=', '(banktransfert)', 'banktransfert');

	// A various payment without invoice: toner for the office, 25 euros.
	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';
	$various = new PaymentVarious($db);
	$various->datep = $day(7, 4);
	$various->datev = $various->datep;
	$various->sens = '0';
	$various->amount = 25;
	$various->label = 'Druckerpatronen';
	$various->note = '';
	$various->num_payment = '';
	$various->accountancy_code = '';
	$various->subledger_account = '';
	$various->fk_account = (int) $bank->id;
	$various->type_payment = $transfer;
	if ($various->create($admin) <= 0) {
		rt_fail('various payment: '.$various->error);
	}
	$lines['various'] = (int) rt_value($db, "SELECT fk_bank FROM ".MAIN_DB_PREFIX."payment_various WHERE rowid = ".((int) $various->id));
	$lines['large'] = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."bank WHERE label = 'Großspende Firma' AND fk_account = ".((int) $bank->id));

	$sql = "SELECT t.fk_adherent FROM ".MAIN_DB_PREFIX."vereine_function_term as t INNER JOIN ".MAIN_DB_PREFIX."vereine_function as f ON f.rowid = t.fk_function";
	$sql .= " WHERE f.code = 'obmann' AND (t.date_end IS NULL OR t.date_end >= CURDATE()) ORDER BY t.rowid DESC LIMIT 1";
	$chair = (int) rt_value($db, $sql);
	print json_encode(array('lines' => $lines, 'open_ref' => (string) $invoices['open']->ref, 'cash' => (int) $cash->id, 'chair' => $chair))."\n";
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

// Members with third party, validated today, for the discounts: a child, two students (proof valid and expired), an honorary member.
if ($stage === 'discountmembers') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	$typeId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."adherent_type WHERE libelle = 'Beitragspflichtig'");
	$studentRule = (int) rt_env('RT_STUDENT_RULE');
	$today = dol_mktime(12, 0, 0, (int) dol_print_date(dol_now(), '%m'), (int) dol_print_date(dol_now(), '%d'), (int) dol_print_date(dol_now(), '%Y'));
	$people = array(
		'child' => array('Jonas', 'Jung', dol_mktime(12, 0, 0, 5, 5, 2015), array()),
		'student' => array('Stella', 'Studentin', dol_mktime(12, 0, 0, 1, 1, 2000), array('options_vereine_fee_proof' => $studentRule, 'options_vereine_fee_proof_until' => dol_time_plus_duree($today, 100, 'd'))),
		'expired' => array('Erik', 'Ehemals', dol_mktime(12, 0, 0, 1, 1, 2001), array('options_vereine_fee_proof' => $studentRule, 'options_vereine_fee_proof_until' => dol_time_plus_duree($today, -1, 'd'))),
		'honorary' => array('Hanna', 'Ehren', dol_mktime(12, 0, 0, 3, 3, 1950), array('options_vereine_fee_exempt' => 1, 'options_vereine_fee_exempt_reason' => 'Ehrenmitglied')),
	);
	$members = array();
	foreach ($people as $key => $data) {
		$member = new Adherent($db);
		$member->typeid = $typeId;
		$member->morphy = 'phy';
		$member->firstname = $data[0];
		$member->lastname = $data[1];
		$member->birth = $data[2];
		$member->email = $key.'.discount@runtime-verein.test';
		$member->country_id = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");
		$member->public = 0;
		$member->array_options = $data[3];
		if ($member->create($admin) <= 0 || $member->validate($admin) <= 0) {
			rt_fail('member '.$key.': '.$member->error.' '.implode(' | ', (array) $member->errors));
		}
		$member->fetch($member->id);
		$partner = new Societe($db);
		if ((int) $member->fk_soc <= 0 && $partner->create_from_member($member) <= 0) {
			rt_fail('third party of '.$key.': '.$partner->error);
		}
		$members[$key] = (int) $member->id;
	}
	print json_encode(array('members' => $members))."\n";
	exit(0);
}

// A member type of 60 per calendar year, not prorated, and a family: Petra pays for Paul (10, youth discount) and Pia (20).
// Nora names a payer that was deleted; Dolibarr refuses to store a link to a missing third party, so it is written directly.
if ($stage === 'familymembers') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent_type.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	$type = new AdherentType($db);
	$type->label = 'Familienbeitrag';
	$type->morphy = '';
	$type->status = 1;
	$type->subscription = 1;
	$type->amount = 60;
	$type->duration_value = 1;
	$type->duration_unit = 'y';
	$type->array_options = array('options_vereine_fee_start_month' => 1, 'options_vereine_fee_proration' => 'none', 'options_vereine_admission_fee' => 0,
		'options_vereine_fee_product' => (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."product WHERE ref = 'RT-BEITRAG'"));
	if ($type->create($admin) <= 0) {
		rt_fail('member type Familienbeitrag: '.$type->error.' '.implode(' | ', (array) $type->errors));
	}
	$today = dol_mktime(12, 0, 0, (int) dol_print_date(dol_now(), '%m'), (int) dol_print_date(dol_now(), '%d'), (int) dol_print_date(dol_now(), '%Y'));
	$create = function ($firstname, $birth, $options) use ($db, $admin, $type) {
		$member = new Adherent($db);
		$member->typeid = (int) $type->id;
		$member->morphy = 'phy';
		$member->firstname = $firstname;
		$member->lastname = 'Familie';
		$member->birth = $birth;
		$member->email = strtolower($firstname).'.familie@runtime-verein.test';
		$member->country_id = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");
		$member->public = 0;
		$member->array_options = $options;
		if ($member->create($admin) <= 0 || $member->validate($admin) <= 0) {
			rt_fail('member '.$firstname.': '.$member->error.' '.implode(' | ', (array) $member->errors));
		}
		$member->fetch($member->id);
		return $member;
	};
	$petra = $create('Petra', dol_mktime(12, 0, 0, 4, 4, 1985), array());
	$partner = new Societe($db);
	if ((int) $petra->fk_soc <= 0 && $partner->create_from_member($petra) <= 0) {
		rt_fail('third party of Petra: '.$partner->error);
	}
	$payer = (int) rt_value($db, "SELECT fk_soc FROM ".MAIN_DB_PREFIX."adherent WHERE rowid = ".((int) $petra->id));
	$paul = $create('Paul', dol_time_plus_duree($today, -10, 'y'), array('options_vereine_fee_payer' => $payer));
	$pia = $create('Pia', dol_time_plus_duree($today, -20, 'y'), array('options_vereine_fee_payer' => $payer));
	$nora = $create('Nora', dol_mktime(12, 0, 0, 6, 6, 1990), array());
	if (!$db->query("DELETE FROM ".MAIN_DB_PREFIX."adherent_extrafields WHERE fk_object = ".((int) $nora->id))
		|| !$db->query("INSERT INTO ".MAIN_DB_PREFIX."adherent_extrafields (fk_object, vereine_fee_payer) VALUES (".((int) $nora->id).", 999999)")) {
		rt_fail('deleted payer of Nora: '.$db->lasterror());
	}
	print json_encode(array('type' => (int) $type->id, 'payer' => $payer,
		'members' => array('petra' => (int) $petra->id, 'paul' => (int) $paul->id, 'pia' => (int) $pia->id, 'nora' => (int) $nora->id)))."\n";
	exit(0);
}

// A child joining a family later: Finn, whose fees Petra's third party pays too.
if ($stage === 'familychild') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	$member = new Adherent($db);
	$member->typeid = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."adherent_type WHERE libelle = 'Familienbeitrag'");
	$member->morphy = 'phy';
	$member->firstname = 'Finn';
	$member->lastname = 'Familie';
	$member->birth = dol_mktime(12, 0, 0, 1, 1, 1995);
	$member->email = 'finn.familie@runtime-verein.test';
	$member->country_id = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");
	$member->public = 0;
	$member->array_options = array('options_vereine_fee_payer' => (int) rt_env('RT_PAYER'));
	if ($member->create($admin) <= 0 || $member->validate($admin) <= 0) {
		rt_fail('member Finn: '.$member->error.' '.implode(' | ', (array) $member->errors));
	}
	print json_encode(array('member' => (int) $member->id))."\n";
	exit(0);
}

// Members of the member type with fee, validated today, for exits: Karl gives notice, Xaver is excluded, Lena's planned exit is due.
if ($stage === 'exitmembers') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	$members = array();
	foreach (array('karl' => 'Karl', 'xaver' => 'Xaver', 'lena' => 'Lena') as $key => $firstname) {
		$member = new Adherent($db);
		$member->typeid = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."adherent_type WHERE libelle = 'Beitragspflichtig'");
		$member->morphy = 'phy';
		$member->firstname = $firstname;
		$member->lastname = 'Austritt';
		$member->email = $key.'.austritt@runtime-verein.test';
		$member->country_id = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");
		$member->public = 0;
		if ($member->create($admin) <= 0 || $member->validate($admin) <= 0) {
			rt_fail('member '.$firstname.': '.$member->error.' '.implode(' | ', (array) $member->errors));
		}
		$members[$key] = (int) $member->id;
	}
	print json_encode(array('members' => $members))."\n";
	exit(0);
}

// Dolibarr's direct debit module with a creditor identifier, and three members: a valid mandate, one unused for 40 months, none.
if ($stage === 'sepamembers') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/companybankaccount.class.php';
	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
	$result = activateModule('modPrelevement');
	if (!empty($result['errors'])) {
		rt_fail('activating modPrelevement failed: '.implode(' | ', (array) $result['errors']));
	}
	// A made-up creditor identifier in the Austrian format, for tests only.
	dolibarr_set_const($db, 'PRELEVEMENT_ICS', 'AT12ZZZ00000000001', 'chaine', 0, '', 1);
	$today = dol_mktime(12, 0, 0, (int) dol_print_date(dol_now(), '%m'), (int) dol_print_date(dol_now(), '%d'), (int) dol_print_date(dol_now(), '%Y'));
	$people = array(
		'valid' => array('Valentin', 'RT-MANDAT-GUELTIG', dol_time_plus_duree($today, -30, 'd')),
		'expired' => array('Egon', 'RT-MANDAT-ALT', dol_time_plus_duree($today, -40, 'm')),
		'none' => array('Nadine', '', 0),
	);
	$members = array();
	$accounts = array();
	foreach ($people as $key => $data) {
		$member = new Adherent($db);
		$member->typeid = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."adherent_type WHERE libelle = 'Beitragspflichtig'");
		$member->morphy = 'phy';
		$member->firstname = $data[0];
		$member->lastname = 'Lastschrift';
		$member->email = $key.'.lastschrift@runtime-verein.test';
		$member->country_id = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");
		$member->public = 0;
		if ($member->create($admin) <= 0 || $member->validate($admin) <= 0) {
			rt_fail('member '.$key.': '.$member->error.' '.implode(' | ', (array) $member->errors));
		}
		$member->fetch($member->id);
		$partner = new Societe($db);
		if ((int) $member->fk_soc <= 0 && $partner->create_from_member($member) <= 0) {
			rt_fail('third party of '.$key.': '.$partner->error);
		}
		$members[$key] = (int) $member->id;
		if ($data[1] === '') {
			continue;
		}
		$account = new CompanyBankAccount($db);
		$account->socid = (int) rt_value($db, "SELECT fk_soc FROM ".MAIN_DB_PREFIX."adherent WHERE rowid = ".((int) $member->id));
		$account->type = 'ban';
		$account->label = 'RT Konto '.$data[0];
		$account->bank = 'RT Bank';
		// The example IBAN and BIC published for documentation, not a real account.
		$account->iban = 'AT611904300234573201';
		$account->bic = 'BKAUATWW';
		$account->proprio = $data[0].' Lastschrift';
		$account->owner_name = $account->proprio;
		$account->rum = $data[1];
		$account->date_rum = $data[2];
		$account->frstrecur = 'FRST';
		$account->default_rib = 1;
		if ($account->create($admin) <= 0 || $account->update($admin) <= 0) {
			rt_fail('bank account of '.$key.': '.$account->error.' '.implode(' | ', (array) $account->errors));
		}
		$accounts[$key] = (int) $account->id;
	}
	print json_encode(array('members' => $members, 'accounts' => $accounts))."\n";
	exit(0);
}

// Dolibarr's agenda, so a new representative gets an event on the report deadline.
if ($stage === 'agenda') {
	$result = activateModule('modAgenda');
	if (!empty($result['errors'])) {
		rt_fail('activating modAgenda failed: '.implode(' | ', (array) $result['errors']));
	}
	print json_encode(array('agenda' => 1))."\n";
	exit(0);
}

// Birth date, place of birth and address of representatives (RT_MEMBERS, comma separated), as a report needs them.
if ($stage === 'reportpeople') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	foreach (array_filter(array_map('intval', explode(',', rt_env('RT_MEMBERS')))) as $index => $memberId) {
		$member = new Adherent($db);
		if ($member->fetch($memberId) <= 0) {
			rt_fail('member '.$memberId.': '.$member->error);
		}
		$member->birth = dol_mktime(12, 0, 0, 5, 5 + $index, 1980);
		$member->address = 'Musterweg '.($index + 1);
		$member->zip = '6020';
		$member->town = 'Innsbruck';
		$member->country_id = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");
		$member->array_options['options_vereine_birth_place'] = 'Hall in Tirol';
		if ($member->update($admin) < 0) {
			rt_fail('update member '.$memberId.': '.$member->error.' '.implode(' | ', (array) $member->errors));
		}
	}
	print json_encode(array('done' => 1))."\n";
	exit(0);
}

// Dolibarr's e-mail campaigns and one campaign in draft to add recipients to.
if ($stage === 'mailing') {
	$result = activateModule('modMailing');
	if (!empty($result['errors'])) {
		rt_fail('activating modMailing failed: '.implode(' | ', (array) $result['errors']));
	}
	require_once DOL_DOCUMENT_ROOT.'/comm/mailing/class/mailing.class.php';
	$mailing = new Mailing($db);
	$mailing->messtype = 'email';
	$mailing->title = 'RT Einladung';
	$mailing->sujet = 'Einladung zur Generalversammlung';
	$mailing->body = 'Liebe Mitglieder';
	$mailing->email_from = 'verein@runtime-verein.test';
	if ($mailing->create($admin) <= 0) {
		rt_fail('campaign: '.$mailing->error);
	}
	print json_encode(array('mailing' => (int) $mailing->id))."\n";
	exit(0);
}

// A user group for the treasury and a Dolibarr user created from the member RT_MEMBER_ID.
if ($stage === 'groupuser') {
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';
	$group = new UserGroup($db);
	$group->name = 'RT Kassa';
	$group->nom = 'RT Kassa';
	$group->entity = 1;
	if ($group->create() <= 0) {
		rt_fail('user group: '.$group->error);
	}
	$member = new Adherent($db);
	if ($member->fetch((int) rt_env('RT_MEMBER_ID')) <= 0) {
		rt_fail('member for the user: '.$member->error);
	}
	$account = new User($db);
	$userId = $account->create_from_member($member, 'rtkassier');
	if ($userId <= 0) {
		rt_fail('user from member: '.$account->error.' '.implode(' | ', (array) $account->errors));
	}
	print json_encode(array('group' => (int) $group->id, 'user' => (int) $userId))."\n";
	exit(0);
}

// A user for the website's membership form: may read the association and send applications, nothing else.
if ($stage === 'applicationuser') {
	$form = new User($db);
	$form->login = 'rtapplications';
	$form->lastname = 'Beitrittsformular';
	$form->firstname = 'Runtime';
	$form->admin = 0;
	$form->entity = 1;
	if ($form->create($admin) <= 0) {
		rt_fail('user rtapplications: '.$form->error);
	}
	$form->fetch($form->id);
	$form->api_key = rt_env('RT_APPLICATION_KEY');
	if ($form->update($admin) <= 0) {
		rt_fail('API key for rtapplications: '.$form->error);
	}
	foreach (array(array('association', 'read'), array('application', 'write')) as $right) {
		$rightId = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module = 'vereine' AND perms = '".$right[0]."' AND subperms = '".$right[1]."' AND entity = 1");
		if ($rightId <= 0 || $form->addrights($rightId) < 0) {
			rt_fail('granting vereine/'.$right[0].'/'.$right[1].' to rtapplications: '.$form->error);
		}
	}
	print json_encode(array('user' => (int) $form->id))."\n";
	exit(0);
}

// Dolibarr's scheduled job for exits, as the cron runner calls it.
if ($stage === 'runexits') {
	dol_include_once('/vereine/class/vereineexits.class.php');
	$GLOBALS['user'] = $admin;
	$exits = new VereineExits($db);
	$result = $exits->runDue();
	print json_encode(array('result' => (int) $result, 'output' => $exits->output))."\n";
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
	foreach (array('vereine_fee_exempt', 'vereine_fee_exempt_reason', 'vereine_fee_proof', 'vereine_fee_proof_until', 'vereine_fee_payer', 'vereine_birth_place') as $name) {
		$extrafields = new ExtraFields($db);
		$extrafields->delete($name, 'adherent');
	}
	foreach (array('vereine_log', 'vereine_taxprofile', 'vereine_fee_discount', 'vereine_member_exit', 'vereine_consent_text', 'vereine_consent', 'vereine_application', 'vereine_function', 'vereine_function_term', 'vereine_function_report') as $table) {
		if (!$db->query("DROP TABLE IF EXISTS ".MAIN_DB_PREFIX.$table)) {
			rt_fail('drop table '.$table.': '.$db->lasterror());
		}
	}
	print json_encode(array('reset' => 1))."\n";
	exit(0);
}

rt_fail('unknown stage "'.$stage.'", use base, rights, readmembers, members, cardmember, invoicing, turnover, cashpayments, website, onlinepayment, websiteinvoices, websitechange, websiteflip, webhook, webhookchanges, webhookdown, feerunmember, payinvoice, discountmembers, familymembers, familychild, exitmembers, runexits, sepamembers, applicationuser, agenda, reportpeople, groupuser, mailing, resiliate, guardian or reset');
