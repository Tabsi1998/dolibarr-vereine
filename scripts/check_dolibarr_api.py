#!/usr/bin/env python3
"""Everything the module uses from Dolibarr exists in each supported version.

The module calls Dolibarr functions, extends its classes, relies on how the API
entry point finds module classes and on core language keys. This reads those
places in the source of a Dolibarr maintenance branch and fails when one is
gone or changed - before a user's installation finds out. The runtime checks in
scripts/local_check.py prove the same in a running Dolibarr; this check is the
fast one that GitHub runs too.

Usage:
    python scripts/check_dolibarr_api.py 22.0 23.0 24.0
    python scripts/check_dolibarr_api.py --cache .local-testing/dolibarr-src 24.0

Standard library only.
"""

from __future__ import annotations

import argparse
import re
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

SUPPORTED = ("22.0", "23.0", "24.0")
RAW = "https://raw.githubusercontent.com/Dolibarr/dolibarr/{ref}/{path}"

# (file in the Dolibarr repository, text that must appear, why the module needs it)
CONTRACTS = (
    ("htdocs/core/modules/DolibarrModules.class.php", "class DolibarrModules", "descriptor base class"),
    ("htdocs/core/modules/DolibarrModules.class.php", "function _init(", "module activation"),
    ("htdocs/core/modules/DolibarrModules.class.php", "function _remove(", "module deactivation"),
    ("htdocs/core/modules/DolibarrModules.class.php", "\"Module\".$this->name.\"Name\"", "module name from ModuleVereineName"),
    ("htdocs/core/modules/DolibarrModules.class.php", "'/README-'.$langs->defaultlang.'.md'", "README-de.md as German module description"),
    ("htdocs/core/modules/modAdherent.class.php", "class modAdherent", "the Members module the descriptor depends on"),
    ("htdocs/core/lib/admin.lib.php", "function dolibarr_set_const(", "setup page stores association data"),
    ("htdocs/core/lib/admin.lib.php", "function activateModule(", "runtime fixtures"),
    ("htdocs/core/lib/functions.lib.php", "function getDolGlobalString(", "reading settings"),
    ("htdocs/core/lib/functions.lib.php", "function isModEnabled(", "module state"),
    ("htdocs/core/lib/functions.lib.php", "function GETPOST(", "request input"),
    ("htdocs/core/lib/functions.lib.php", "function GETPOSTINT(", "request input"),
    ("htdocs/core/lib/functions.lib.php", "function newToken(", "CSRF token in forms"),
    ("htdocs/core/lib/security.lib.php", "function accessforbidden(", "refusing access"),
    ("htdocs/core/lib/functions.lib.php", "function setEventMessages(", "messages after saving"),
    ("htdocs/core/lib/functions.lib.php", "function dol_escape_htmltag(", "output escaping"),
    ("htdocs/core/lib/functions.lib.php", "function dol_buildpath(", "module URLs"),
    ("htdocs/core/lib/functions.lib.php", "function dol_include_once(", "including module files"),
    ("htdocs/core/lib/functions.lib.php", "function dol_mktime(", "founding date"),
    ("htdocs/core/lib/functions.lib.php", "function dol_print_date(", "founding date"),
    ("htdocs/core/lib/functions.lib.php", "function dol_now(", "founding date check"),
    ("htdocs/core/lib/functions.lib.php", "function dol_nl2br(", "address and purpose"),
    ("htdocs/core/lib/functions.lib.php", "function img_picto(", "menu picto"),
    ("htdocs/core/lib/functions.lib.php", "strpos($pictowithouttext, 'fa-') === 0", "fa-landmark picto"),
    ("htdocs/core/lib/functions.lib.php", "function dol_get_fiche_head(", "setup tabs"),
    ("htdocs/core/lib/functions.lib.php", "function dol_get_fiche_end(", "setup tabs"),
    ("htdocs/core/lib/functions.lib.php", "function load_fiche_titre(", "page titles"),
    ("htdocs/core/lib/functions.lib.php", "function complete_head_from_modules(", "setup tabs"),
    ("htdocs/core/lib/functions.lib.php", "function dolGetBadge(", "check status badges"),
    ("htdocs/core/lib/functions.lib.php", "function dolGetButtonTitle(", "edit button on the overview"),
    ("htdocs/core/lib/functions.lib.php", "function info_admin(", "hints on setup and about"),
    ("htdocs/core/lib/functions.lib.php", "function yn(", "non-profit yes/no"),
    ("htdocs/core/class/html.form.class.php", "public static function selectarray(", "country profile select"),
    ("htdocs/core/class/html.form.class.php", "public function selectDate(", "founding date input"),
    ("htdocs/user/class/user.class.php", "public function hasRight(", "right checks"),
    ("htdocs/societe/class/societe.class.php", "public function setMysoc(", "company data in $mysoc"),
    ("htdocs/admin/company.php", "SOCIETE_FISCAL_MONTH_START", "fiscal year start from the company settings"),
    ("htdocs/core/menus/standard/eldy.lib.php", "'idsel' => 'members'", "left menu under Members"),
    ("htdocs/api/class/api.class.php", "class DolibarrApi", "API base class"),
    ("htdocs/api/class/api_access.class.php", "public static $user", "API user for right checks"),
    ("htdocs/api/index.php", "$classname = ucwords($moduleobject);", "dispatch of /vereine to class Vereine"),
    ("htdocs/api/index.php", "'/class/api_'.$classfile.'.class.php'", "API file name api_vereine.class.php"),
    ("htdocs/admin/modules.php", "'/^(module[a-zA-Z0-9]*_|theme_|).*\\-([0-9][0-9\\.]*)(\\s\\(\\d+\\)\\s)?\\.zip$/i'",
     "ZIP name rule of Deploy an external module"),
    ("htdocs/admin/modules.php", "name=\"fileinstall\"", "upload field of Deploy an external module"),
    # Members and third parties (issue #15)
    ("htdocs/societe/class/societe.class.php", "public function create_from_member(Adherent $member, $socname = '', $socalias = '', $customercode = '')", "third party created the way Dolibarr does it"),
    ("htdocs/societe/class/societe.class.php", "$this->typent_code = ($member->morphy == 'phy' ? 'TE_PRIVATE' : 0);", "create_from_member sets the private customer type"),
    ("htdocs/adherents/class/adherent.class.php", "public function setThirdPartyId($thirdpartyid)", "linking a member to a third party"),
    ("htdocs/adherents/class/adherent.class.php", "SET fk_soc = null", "one member per third party (setThirdPartyId unlinks others)"),
    ("htdocs/adherents/class/adherent.class.php", "const STATUS_VALIDATED = 1;", "member status values of VereinePartnerRules"),
    ("htdocs/adherents/class/adherent.class.php", "const STATUS_RESILIATED = 0;", "member status values of VereinePartnerRules"),
    ("htdocs/adherents/class/adherent.class.php", "const STATUS_EXCLUDED = -2;", "member status values of VereinePartnerRules"),
    ("htdocs/adherents/class/adherent.class.php", "const STATUS_DRAFT = -1;", "member status values of VereinePartnerRules"),
    ("htdocs/adherents/class/adherent.class.php", "call_trigger('MEMBER_VALIDATE'", "trigger on validation"),
    ("htdocs/adherents/class/adherent.class.php", "call_trigger('MEMBER_RESILIATE'", "trigger on resignation"),
    ("htdocs/adherents/class/adherent.class.php", "call_trigger('MEMBER_EXCLUDE'", "trigger on exclusion"),
    ("htdocs/adherents/class/adherent.class.php", "call_trigger('MEMBER_DELETE'", "trigger on deletion"),
    ("htdocs/adherents/class/adherent.class.php", "$this->statut = self::STATUS_VALIDATED;", "statut property the trigger reads"),
    ("htdocs/adherents/class/adherent.class.php", "public function fetch_subscriptions()", "member since on the membership tab"),
    ("htdocs/adherents/class/adherent.class.php", "public function LibStatut($status, $need_subscription, $date_end_subscription, $mode = 0)", "status badge on the reconciliation page"),
    ("htdocs/categories/class/categorie.class.php", "public function add_type($obj, $type = '')", "adding a third party to a category"),
    ("htdocs/categories/class/categorie.class.php", "public function del_type($obj, $type)", "removing a third party from a category"),
    ("htdocs/categories/class/categorie.class.php", "public function containing($id, $type, $mode = 'object')", "categories on the membership tab"),
    ("htdocs/categories/class/categorie.class.php", "public function fetch($id, $label = '', $type = null, $ref_ext = '')", "finding a category by label"),
    ("htdocs/categories/class/categorie.class.php", "'customer'				=> 2,", "customer category type id 2"),
    ("htdocs/categories/class/categorie.class.php", "'contact'				=> 4,", "contact category type id 4"),
    ("htdocs/core/class/commonobject.class.php", "public function setValueFrom($field, $value, $table = '', $id = null, $format = '', $id_field = '', $fuser = null, $trigkey = '', $fk_user_field = 'fk_user_modif')", "changing single third party fields"),
    ("htdocs/core/triggers/dolibarrtriggers.class.php", "const VERSIONS = [", "trigger version constant"),
    ("htdocs/core/triggers/dolibarrtriggers.class.php", "abstract public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf);", "trigger signature"),
    ("htdocs/core/lib/company.lib.php", "function societe_prepare_head(Societe $object", "tabs of the third party card"),
    ("htdocs/core/lib/company.lib.php", "complete_head_from_modules($conf, $langs, $object, $head, $h, 'thirdparty', 'add', 'external');", "module tab type thirdparty"),
    ("htdocs/core/lib/functions.lib.php", "function dol_banner_tab(", "third party banner"),
    ("htdocs/core/lib/functions.lib.php", "function dol_getIdFromCode(", "customer type code and id"),
    ("htdocs/core/lib/functions.lib.php", "function getEntity(", "multi-company filter"),
    ("htdocs/core/lib/functions.lib.php", "function dol_print_email(", "guardian e-mail"),
    ("htdocs/core/lib/functions.lib.php", "function dol_trunc(", "log message length"),
    ("htdocs/core/lib/security.lib.php", "function restrictedArea(", "third party access on the membership tab"),
    ("htdocs/compta/facture/class/facture.class.php", "const STATUS_VALIDATED = 1;", "open invoices on the membership tab"),
    ("htdocs/install/mysql/tables/llx_categorie_societe.sql", "fk_soc", "customer category links"),
    ("htdocs/install/mysql/tables/llx_categorie_contact.sql", "fk_socpeople", "contact category links"),
    ("htdocs/install/mysql/data/llx_c_typent.sql", "'TE_PRIVATE'", "private customer type"),
    # Menu entry and row dialogs of the reconciliation (issues #30, #31)
    ("htdocs/core/menus/standard/eldy.lib.php", "'mainmenu='.$menu_array[$i]['mainmenu'].'&leftmenu='", "module menu entries keep the Members menu open"),
    ("htdocs/main.inc.php", "foreach ($arrayofjs as $jsfile)", "llxHeader loads js/partners.js"),
    ("htdocs/main.inc.php", "print '<script nonce=\"'.getNonce().'\" src=\"'.dol_buildpath($jsfile, 1)", "js/partners.js with the CSP nonce"),
    ("htdocs/main.inc.php", "jquery-ui.min.js", "jQuery UI dialog for the steps of a row"),
    ("htdocs/adherents/card.php", "$backtopage = GETPOST('backtopage', 'alpha');", "editing a member returns to the reconciliation"),
    ("htdocs/adherents/card.php", "name=\"backtopage\" value=\"'.($backtopage != '1' ? $backtopage : $_SERVER[\"HTTP_REFERER\"]).'\"", "the member edit form keeps backtopage"),
    ("htdocs/adherents/card.php", "hasRight('adherent', 'creer') && $action == 'edit'", "right checked for Edit member"),
    ("htdocs/contact/card.php", "print '<input type=\"hidden\" name=\"backtopage\" value=\"'.$backtopage.'\">';", "a new guardian contact returns to the reconciliation"),
    ("htdocs/contact/card.php", "hasRight('societe', 'contact', 'creer')", "right checked for adding a guardian"),
    ("htdocs/societe/card.php", "hasRight('societe', 'creer')", "right checked for Edit third party"),
    ("htdocs/theme/eldy/global.inc.php", ".cursorpointer {", "rows with a dialog look clickable"),
    ("htdocs/theme/eldy/global.inc.php", ".marginbottomonly {", "spacing of the steps in a dialog"),
    ("htdocs/theme/md/style.css.php", ".cursorpointer {", "rows with a dialog look clickable"),
    ("htdocs/theme/md/style.css.php", ".marginbottomonly {", "spacing of the steps in a dialog"),
    # Dolibarr's member card links third parties without a trigger; tab Association (issues #33, #34)
    ("htdocs/societe/class/societe.class.php", "$sql .= \" SET fk_soc = \".((int) $this->id);", "create_from_member links the member with plain SQL"),
    ("htdocs/adherents/card.php", "$hookmanager->initHooks(array('membercard', 'globalcard'));", "hook context of the member card"),
    ("htdocs/adherents/card.php", "executeHooks('doActions', $parameters, $object, $action);", "the link is noted before Dolibarr's action"),
    ("htdocs/adherents/card.php", "if ($action == 'setsocid' && $caneditfieldmember) {", "Linked third party on the member card"),
    ("htdocs/adherents/card.php", "if ($action == 'confirm_create_thirdparty' && $confirm == 'yes' && $user->hasRight('societe', 'creer')) {", "Create third party on the member card"),
    ("htdocs/adherents/card.php", "$res = $object->fetch($id);", "the card loads the member again before its buttons"),
    ("htdocs/adherents/card.php", "executeHooks('addMoreActionsButtons', $parameters, $object, $action);", "the new link is brought in line after Dolibarr's action"),
    ("htdocs/adherents/card.php", "$result = restrictedArea($user, 'adherent', $object->id, '', '', 'socid', 'rowid', 0);", "access check the member tab repeats"),
    ("htdocs/adherents/class/adherent.class.php", "$this->fk_soc = $obj->", "linked third party after fetch (22 reads fk_soc, 23 and 24 socid)"),
    ("htdocs/core/class/hookmanager.class.php", "$actionfile = 'actions_'.$module.'.class.php';", "hook file class/actions_vereine.class.php"),
    ("htdocs/core/class/hookmanager.class.php", "$controlclassname = 'Actions'.ucfirst($module);", "hook class ActionsVereine"),
    ("htdocs/core/class/hookmanager.class.php", "$parameters['context'] = implode(':', $this->contextarray);", "hook context in the parameters"),
    ("htdocs/core/modules/DolibarrModules.class.php", "if (isset($value['data']) && is_array($value['data'])) {", "hooks declared with data and entity"),
    ("htdocs/core/lib/member.lib.php", "function member_prepare_head(Adherent $object)", "tabs of the member card"),
    ("htdocs/core/lib/member.lib.php", "complete_head_from_modules($conf, $langs, $object, $head, $h, 'member', 'add', 'external');", "tab Association from the descriptor"),
    # Tax profiles (issue #36)
    ("htdocs/core/lib/functions.lib.php", "function img_edit(", "edit link on the tax profile setup"),
    ("htdocs/core/lib/functions.lib.php", "function dol_trunc(", "short invoice note in the tax profile list"),
    ("htdocs/install/mysql/data/llx_c_tva.sql", "values (41,  '10','0','VAT rate - reduced', 1,__ENTITY__);", "Dolibarr's Austrian VAT rates: 10 %"),
    ("htdocs/install/mysql/data/llx_c_tva.sql", "values (41,  '20','0','VAT rate - standard',1,__ENTITY__);", "Dolibarr's Austrian VAT rates: 20 %"),
    # Tax profiles on products and invoice lines (issue #37)
    ("htdocs/core/class/extrafields.class.php", "public function addExtraField($attrname, $label, $type, $pos, $size, $elementtype, $unique = 0, $required = 0, $default_value = '', $param = '', $alwayseditable = 0, $perms = '', $list = '-1', $help = '', $computed = '', $entity = '', $langfile = '', $enabled = '1'", "extra field tax profile"),
    ("htdocs/core/class/extrafields.class.php", "$err1 == 'DB_ERROR_COLUMN_ALREADY_EXISTS'", "enabling again keeps the extra field and its values"),
    ("htdocs/core/class/extrafields.class.php", "forgeSQLFromUniversalSearchCriteria($InfoFieldList[4]", "only active tax profiles in the list"),
    ("htdocs/core/class/extrafields.class.php", "'$ENTITY$'", "tax profiles of the current entity in the list"),
    ("htdocs/core/class/commonobject.class.php", "public function insertExtraFields(", "a new invoice line stores the product's tax profile"),
    ("htdocs/product/class/product.class.php", "public function updatePrice($newprice, $newpricebase, $user, $newvat = null, $newminprice = 0, $level = 0, $newnpr = 0", "the product's VAT rate follows its tax profile with the gross price"),
    ("htdocs/product/class/product.class.php", "call_trigger('PRODUCT_CREATE'", "tax profile on a new product"),
    ("htdocs/product/class/product.class.php", "call_trigger('PRODUCT_MODIFY'", "tax profile on a changed product"),
    ("htdocs/compta/facture/class/factureligne.class.php", "public $table_element = 'facturedet';", "customer invoice line extra fields"),
    ("htdocs/compta/facture/class/factureligne.class.php", "call_trigger('LINEBILL_INSERT'", "a new customer invoice line"),
    ("htdocs/fourn/class/fournisseur.facture.ligne.class.php", "public $table_element = 'facture_fourn_det';", "supplier invoice line extra fields"),
    ("htdocs/fourn/class/fournisseur.facture.ligne.class.php", "call_trigger('LINEBILL_SUPPLIER_CREATE'", "a new supplier invoice line"),
    ("htdocs/compta/facture/card.php", "$hookmanager->initHooks(array('invoicecard', 'globalcard'));", "hook context of the customer invoice"),
    ("htdocs/compta/facture/card.php", "executeHooks('formConfirm', $parameters, $object, $action)", "warning on the customer invoice"),
    ("htdocs/fourn/facture/card.php", "$hookmanager->initHooks(array('invoicesuppliercard', 'globalcard'));", "hook context of the supplier invoice"),
    ("htdocs/fourn/facture/card.php", "executeHooks('formConfirm', $parameters, $object, $action)", "warning on the supplier invoice"),
    ("htdocs/core/lib/functions.lib.php", "function dol_string_nohtmltag(", "line description in the warning"),
    # Tax profile notes and register number on invoice PDFs (issue #38)
    ("htdocs/core/modules/facture/doc/pdf_sponge.modules.php", "$hookmanager->initHooks(array('pdfgeneration'));", "hook context of the invoice PDF"),
    ("htdocs/core/modules/facture/doc/pdf_sponge.modules.php", "$parameters = array('file' => $file, 'object' => $object, 'outputlangs' => $outputlangs);", "the invoice and its language reach the PDF hooks"),
    ("htdocs/core/modules/facture/doc/pdf_sponge.modules.php", "executeHooks('beforePDFCreation', $parameters, $object, $action)", "notes are added before the PDF is built"),
    ("htdocs/core/modules/facture/doc/pdf_sponge.modules.php", "executeHooks('afterPDFCreation', $parameters, $this, $action)", "the public note is put back afterwards"),
    ("htdocs/core/modules/facture/doc/pdf_sponge.modules.php", "$notetoshow = empty($object->note_public) ? '' : $object->note_public;", "the invoice PDF prints the public note"),
    ("htdocs/core/lib/functions.lib.php", "function dol_concatdesc(", "notes joined with the existing public note"),
    # Thresholds and traffic light (issue #39)
    ("htdocs/compta/facture/class/facture.class.php", "const STATUS_CLOSED = 2;", "paid invoices count"),
    ("htdocs/compta/facture/class/facture.class.php", "const TYPE_STANDARD = 0;", "standard invoices count"),
    ("htdocs/compta/facture/class/facture.class.php", "const TYPE_REPLACEMENT = 1;", "replacement invoices count"),
    ("htdocs/compta/facture/class/facture.class.php", "const TYPE_CREDIT_NOTE = 2;", "credit notes count, negative"),
    ("htdocs/install/mysql/tables/llx_facture.sql", "datef", "invoice date decides the year"),
    ("htdocs/install/mysql/tables/llx_facture.sql", "fk_statut", "invoice status column"),
    ("htdocs/install/mysql/tables/llx_facturedet.sql", "total_ttc", "gross amount of a line"),
    ("htdocs/core/boxes/modules_boxes.php", "class ModeleBoxes", "home page box base class"),
    ("htdocs/core/boxes/modules_boxes.php", "public function showBox($head = null, $contents = null, $nooutput = 0)", "home page box output"),
    ("htdocs/core/modules/DolibarrModules.class.php", "public function insert_boxes(", "the box is registered on activation"),
    ("htdocs/core/lib/functions.lib.php", "function price(", "amounts in the traffic light"),
    # Cash register check and the 13 % VAT rate (issue #40)
    ("htdocs/install/mysql/data/llx_c_paiement.sql", "( 4, 'LIQ', 'Cash',", "cash payments"),
    ("htdocs/install/mysql/data/llx_c_paiement.sql", "( 6, 'CB',  'Credit card',", "card payments"),
    ("htdocs/install/mysql/data/llx_c_paiement.sql", "( 7, 'CHQ', 'Cheque',", "cheque payments"),
    ("htdocs/install/mysql/data/llx_c_paiement.sql", "(50, 'VAD', 'Online payment',", "online payments"),
    ("htdocs/install/mysql/tables/llx_paiement.sql", "datep", "payment date decides the year"),
    ("htdocs/install/mysql/tables/llx_paiement.sql", "fk_paiement      integer NOT NULL", "payment type of a payment"),
    ("htdocs/install/mysql/tables/llx_paiement_facture.sql", "fk_facture", "payment shared out to invoices"),
    ("htdocs/install/mysql/tables/llx_c_tva.key.sql", "uk_c_tva_id (entity, fk_pays, code, taux, recuperableonly)", "adding 13 % twice is impossible"),
    ("htdocs/compta/paiement/class/paiement.class.php", "public function create($user, $closepaidinvoices = 0, $thirdparty = null)", "runtime fixture pays an invoice in cash"),
    # Member summary for a website (issue #49)
    ("htdocs/install/mysql/tables/llx_adherent.sql", "datefin", "paid until: end of the last subscription period"),
    ("htdocs/install/mysql/tables/llx_adherent.sql", "datevalid", "validation date of a member"),
    ("htdocs/install/mysql/tables/llx_adherent.sql", "fk_adherent_type", "member type of a member"),
    ("htdocs/install/mysql/tables/llx_adherent.sql", "ref              varchar(30) NOT NULL", "member number"),
    ("htdocs/install/mysql/tables/llx_adherent_type.sql", "subscription     varchar(3) NOT NULL DEFAULT '1'", "whether a member type needs a subscription"),
    ("htdocs/install/mysql/tables/llx_adherent_type.sql", "amount           double(24,8) DEFAULT NULL", "fee amount of a member type, null when not set"),
    ("htdocs/install/mysql/tables/llx_subscription.sql", "dateadh", "start of a subscription period, member since"),
    ("htdocs/install/mysql/tables/llx_facture.sql", "date_lim_reglement", "due date of an open invoice"),
    ("htdocs/install/mysql/tables/llx_facture.sql", "paye", "paid flag of an invoice"),
    ("htdocs/compta/facture/class/facture.class.php", "const TYPE_DEPOSIT = 3;", "open deposit invoices are listed"),
    ("htdocs/core/class/commoninvoice.class.php", "public function getRemainToPay($multicurrency = 0)", "remaining amount of an open invoice"),
    ("htdocs/core/lib/payments.lib.php", "function getValidOnlinePaymentMethods($paymentmethod = '', $mode = 0)", "payment links only with an online payment service"),
    ("htdocs/core/lib/payments.lib.php", "function getOnlinePaymentUrl($mode, $type, $ref = '', $amount = 0, $freetag = 'your_tag', $localorexternal = 1)", "payment links for fee and invoices"),
    ("htdocs/core/lib/payments.lib.php", "} elseif ($type == 'member' || $type == 'membersubscription') {", "payment link for a member's fee"),
    ("htdocs/public/payment/newpayment.php", "$amount = $adht->amount;", "the payment page takes the member type's amount"),
    ("htdocs/core/db/DoliDB.class.php", "public function plimit(", "at most 50 open invoices"),
    ("htdocs/core/lib/admin.lib.php", "function unActivateModule(", "runtime fixtures"),
    ("htdocs/adherents/class/adherent.class.php", "public function subscription($date, $amount, $accountid = 0, $operation = '', $label = '', $num_chq = '', $emetteur_nom = '', $emetteur_banque = '', $datesubend = 0, $fk_type = null", "runtime fixture records subscription periods"),
    ("htdocs/adherents/class/api_members.class.php", "if (!DolibarrApiAccess::$user->hasRight('adherent', 'lire')) {", "the website user cannot read members through Dolibarr's own API"),
    # Invoices and PDFs of a member for a website (issue #50)
    ("htdocs/compta/facture/class/facture.class.php", "const STATUS_DRAFT = 0;", "drafts are no invoices of a member"),
    ("htdocs/compta/facture/class/facture.class.php", "const STATUS_ABANDONED = 3;", "abandoned invoices of a member"),
    ("htdocs/compta/facture/class/facture.class.php", "public function generateDocument($modele, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)", "a missing invoice PDF is built"),
    ("htdocs/compta/facture/class/facture.class.php", "$modele = getDolGlobalString('FACTURE_ADDON_PDF');", "an empty template name takes the invoice's or the default template"),
    ("htdocs/core/modules/facture/doc/pdf_sponge.modules.php", "$file = $dir.\"/\".$objectref.\".pdf\";", "where the invoice PDF is stored"),
    ("htdocs/core/modules/facture/doc/pdf_sponge.modules.php", "$objectref = dol_sanitizeFileName($object->ref);", "the PDF's folder and name come from the sanitised reference"),
    ("htdocs/core/lib/functions.lib.php", "function dol_sanitizeFileName(", "PDF file name of an invoice"),
    ("htdocs/core/class/commonobject.class.php", "public function fetch_thirdparty(", "language of the invoice's third party for a built PDF"),
    ("htdocs/core/class/commoninvoice.class.php", "const CLOSECODE_ABANDONED = 'abandon';", "runtime fixture abandons an invoice"),
    ("htdocs/compta/facture/class/facture.class.php", "public function setCanceled($user, $close_code = '', $close_note = '')", "runtime fixture abandons an invoice"),
    ("htdocs/api/class/api_documents.class.php", "$check_access = dol_check_secure_access_document($modulepart, $relativefile, $entity, DolibarrApiAccess::$user, '', 'read');", "the website user cannot download invoices through Dolibarr's own API"),
    # Website sync with changed_since (issue #51)
    ("htdocs/install/mysql/tables/llx_adherent.sql", "timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", "a changed member"),
    ("htdocs/install/mysql/tables/llx_adherent_type.sql", "timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", "a changed member type"),
    ("htdocs/install/mysql/tables/llx_subscription.sql", "timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", "a new or changed subscription period"),
    ("htdocs/install/mysql/tables/llx_facture.sql", "timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", "a new or changed invoice"),
    ("htdocs/install/mysql/tables/llx_paiement.sql", "timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", "a new or changed payment"),
    ("htdocs/install/mysql/tables/llx_paiement_facture.sql", "fk_paiement", "payments of an invoice"),
    # Slim webhook event for the website (issue #52)
    ("htdocs/core/class/interfaces.class.php", "public function run_triggers($action, $object, $user, $langs, $conf)", "raising VEREINE_MEMBER_CHANGED"),
    ("htdocs/core/triggers/interface_95_modWebhook_WebhookTriggers.class.php", "$actionarraytmp = explode(\",\", $tmpobject->trigger_codes);", "a webhook target chooses events by code"),
    ("htdocs/core/triggers/interface_95_modWebhook_WebhookTriggers.class.php", "$sendmanualtriggers = (!empty($object->context['sendmanualtriggers'])", "the event object needs a context"),
    ("htdocs/core/triggers/interface_95_modWebhook_WebhookTriggers.class.php", "global $dolibarr_allow_localurl_for_webhooks;", "runtime receiver in the same container"),
    ("htdocs/webhook/class/target.class.php", "c_action_trigger as c ORDER BY c.rang ASC", "a webhook target offers the events listed in c_action_trigger"),
    ("htdocs/webhook/class/target.class.php", "const STATUS_AUTOMATIC_TRIGGER = 1;", "runtime webhook target"),
    ("htdocs/install/mysql/tables/llx_c_action_trigger.key.sql", "uk_action_trigger_code (code)", "the event is registered once"),
    ("htdocs/adherents/class/adherent.class.php", "call_trigger('MEMBER_CREATE'", "a new member"),
    ("htdocs/adherents/class/adherent.class.php", "call_trigger('MEMBER_MODIFY'", "a changed member"),
    ("htdocs/adherents/class/subscription.class.php", "call_trigger('MEMBER_SUBSCRIPTION_CREATE'", "a new subscription period"),
    ("htdocs/adherents/class/subscription.class.php", "call_trigger('MEMBER_SUBSCRIPTION_MODIFY'", "a changed subscription period"),
    ("htdocs/adherents/class/subscription.class.php", "call_trigger('MEMBER_SUBSCRIPTION_DELETE'", "a deleted subscription period"),
    ("htdocs/compta/facture/class/facture.class.php", "call_trigger('BILL_VALIDATE'", "a validated invoice"),
    ("htdocs/compta/facture/class/facture.class.php", "call_trigger('BILL_UNVALIDATE'", "an invoice back to draft"),
    ("htdocs/compta/facture/class/facture.class.php", "call_trigger('BILL_PAYED'", "a paid invoice"),
    ("htdocs/compta/facture/class/facture.class.php", "call_trigger('BILL_UNPAYED'", "an invoice reopened"),
    ("htdocs/compta/facture/class/facture.class.php", "call_trigger('BILL_CANCEL'", "an abandoned invoice"),
    ("htdocs/compta/facture/class/facture.class.php", "call_trigger('BILL_DELETE'", "a deleted invoice"),
    ("htdocs/compta/facture/class/facture.class.php", "call_trigger('BILL_MODIFY'", "a changed invoice"),
    ("htdocs/compta/paiement/class/paiement.class.php", "call_trigger('PAYMENT_CUSTOMER_CREATE'", "a new payment"),
    ("htdocs/compta/paiement/class/paiement.class.php", "call_trigger('PAYMENT_CUSTOMER_DELETE'", "a deleted payment, raised while it is still linked to its invoices"),
    # Fee model on the member type (issue #60)
    ("htdocs/install/mysql/tables/llx_adherent_type_extrafields.sql", "fk_object", "extra fields of member types"),
    ("htdocs/adherents/type.php", "$extrafields->fetch_name_optionals_label($object->table_element);", "the member type card shows the fee fields"),
    ("htdocs/adherents/type.php", "$ret = $extrafields->setOptionalsFromPost(null, $object, '@GETPOSTISSET');", "the member type card stores the fee fields"),
    ("htdocs/adherents/type.php", "WHERE d.entity IN (\".getEntity('member_type').\")", "member types of the current entity"),
    ("htdocs/adherents/class/adherent_type.class.php", "public $table_element = 'adherent_type';", "extra fields element of member types"),
    ("htdocs/adherents/class/adherent_type.class.php", "d.duration", "duration of a member type such as 1y"),
    ("htdocs/install/mysql/tables/llx_adherent_type.sql", "duration", "duration of a member type"),
    ("htdocs/install/mysql/tables/llx_adherent_type.sql", "note", "public description of a member type"),
    ("htdocs/install/mysql/tables/llx_adherent_type.sql", "caneditamount", "a member may pay another amount"),
    ("htdocs/core/lib/date.lib.php", "function dol_time_plus_duree(", "periods of subscriptions, compared with the fee rules"),
    ("htdocs/adherents/subscription.php", "MEMBER_SUBSCRIPTION_START_FIRST_DAY_OF", "Dolibarr's own period suggestion on the member card"),
    ("htdocs/core/lib/functions.lib.php", "function yn(", "prorated yes/no on the fee setup"),
    # Fee run (issue #61)
    ("htdocs/adherents/class/adherent.class.php", "$invoice->linked_objects['subscription'] = $subscriptionid;", "the member card links a fee invoice to its subscription period the same way"),
    ("htdocs/adherents/class/adherent.class.php", "$result = $invoice->addline($label, 0, 1, $vattouse, 0, 0, $idprodsubscription, 0, $datesubscription, '', 0, 0, 0, 'TTC', $amount, 1);", "fee line with gross amount as on the member card"),
    ("htdocs/compta/facture/class/facture.class.php", "$ret = $this->add_object_linked($origin, $origin_id);", "linked_objects become element_element rows on create"),
    ("htdocs/core/class/commonobject.class.php", "public function add_object_linked($origin = null, $origin_id = null, $f_user = null, $notrigger = 0)", "link of fee invoice and subscription period"),
    ("htdocs/install/mysql/tables/llx_element_element.sql", "sourcetype", "fee invoices are found by source type subscription"),
    ("htdocs/install/mysql/tables/llx_subscription.key.sql", "ADD UNIQUE INDEX uk_subscription (fk_adherent", "one subscription period per member and start day, a second run cannot duplicate it"),
    ("htdocs/compta/facture/class/paymentterm.class.php", "public function getDefaultId()", "payment term of a fee invoice without the customer's own"),
    ("htdocs/core/lib/functions.lib.php", "function get_default_tva(Societe $thirdparty_seller, Societe $thirdparty_buyer, $idprod = 0, $idprodfournprice = 0)", "VAT rate of the fee product"),
    ("htdocs/compta/facture/class/facture.class.php", "public function validate($user, $force_number = '', $idwarehouse = 0, $notrigger = 0, $batch_rule = 0)", "fee invoices are validated"),
    ("htdocs/core/modules/modAdherent.class.php", "$this->rights[$r][4] = 'cotisation';", "right to record subscriptions"),
    ("htdocs/core/modules/modFacture.class.php", "$this->rights[$r][4] = 'invoice_advance';", "separate right to validate invoices with advanced permissions"),
    # Proration by month, quarter or half-year (issue #68)
    ("htdocs/core/class/extrafields.class.php", "public function fetch_name_optionals_label($elementtype, $forceload = false", "whether the old prorated checkbox still exists"),
    ("htdocs/core/class/extrafields.class.php", "public function delete($attrname, $elementtype = '')", "the old prorated checkbox is removed after moving its values"),
    # Fee discounts and exemptions (issue #62)
    ("htdocs/install/mysql/tables/llx_adherent.sql", "birth", "age of a member for discounts"),
    ("htdocs/install/mysql/tables/llx_adherent_extrafields.sql", "fk_object", "exemption and proof fields of a member"),
    ("htdocs/adherents/card.php", "$extrafields->fetch_name_optionals_label($object->table_element);", "the member card shows exemption and proof"),
    ("htdocs/core/lib/functions.lib.php", "function load_fiche_titre($title, $morehtmlright = '', $picto = 'generic', $pictoisfullpath = 0, $id = ''", "anchor of the discounts on the fee setup"),
    ("htdocs/adherents/class/adherent.class.php", "public function subscription($date, $amount, $accountid = 0, $operation = '', $label = ''", "a period of 0 for an exempt member"),
    # Families with one payer (issue #62)
    ("htdocs/compta/facture/class/facture.class.php", "if (is_array($tmp_origin_id)) {", "one family invoice is linked to the subscription periods of several members"),
    ("htdocs/core/class/extrafields.class.php", "} elseif ($type == 'link') {", "the payer is an extra field that links a third party"),
    ("htdocs/core/class/extrafields.class.php", "$param_list = array_keys($param['options']); // $param_list='ObjectName:classPath'", "the link names Societe and its class path"),
    ("htdocs/install/mysql/tables/llx_adherent_extrafields.sql", "tms              timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", "a changed payer, exemption or proof counts for the website sync"),
    ("htdocs/install/mysql/tables/llx_subscription.sql", "subscription", "what a family was already charged in a fee year"),
    ("htdocs/core/lib/functions.lib.php", "function getEntity($element, $shared = 1, $currentobject = null)", "third parties of the payer are read per entity"),
    # Exit with notice period (issue #64)
    ("htdocs/adherents/class/adherent.class.php", "public function resiliate($user)", "a resignation, death or striking off takes effect"),
    ("htdocs/adherents/class/adherent.class.php", "public function exclude($user)", "an exclusion takes effect"),
    ("htdocs/core/modules/DolibarrModules.class.php", "$methodename = isset($value['method']) ? $value['method'] : '';", "the scheduled job for exits is registered from the descriptor"),
    ("htdocs/core/modules/DolibarrModules.class.php", "$unitfrequency = isset($value['unitfrequency']) ? $value['unitfrequency'] : '';", "the job runs once a day"),
    ("htdocs/install/mysql/tables/llx_cronjob.sql", "methodename", "the runtime test finds the scheduled job"),
    # SEPA direct debit from the fee run (issue #63)
    ("htdocs/core/class/commoninvoice.class.php", "public function demande_prelevement(User $fuser, float $amount = 0, string $type = 'direct-debit', string $sourcetype = 'facture', int $checkduplicateamongall = 0, int $ribId = 0)", "direct debit request for a fee invoice with the payer's mandate"),
    ("htdocs/install/mysql/tables/llx_prelevement_demande.sql", "fk_societe_rib", "the request names the bank account of the mandate"),
    ("htdocs/install/mysql/tables/llx_prelevement_demande.sql", "date_traite", "the last collection keeps a mandate valid"),
    ("htdocs/install/mysql/tables/llx_societe_rib.sql", "date_rum", "signature date of the mandate"),
    ("htdocs/install/mysql/tables/llx_societe_rib.sql", "default_rib", "the default bank account holds the mandate"),
    ("htdocs/societe/class/companybankaccount.class.php", "public $rum;", "mandate reference on the bank account"),
    ("htdocs/compta/facture/prelevement.php", "$usercancreate = $user->hasRight('facture', 'creer');", "requesting a direct debit needs only the right to create invoices"),
    ("htdocs/compta/prelevement/class/bonprelevement.class.php", "PRELEVEMENT_ICS", "the creditor identifier of the pre-notification"),
    # Consents and membership applications (issue #65)
    ("htdocs/adherents/class/api_members.class.php", "public function post($request_data = null)", "a POST body reaches the method as request_data, as in Dolibarr's own API"),
    ("htdocs/adherents/class/adherent.class.php", "if (!getDolGlobalString('ADHERENT_LOGIN_NOT_REQUIRED')) {", "an application sets a login only when Dolibarr requires one"),
    ("htdocs/adherents/class/adherent.class.php", "$sql .= \" (ref, datec, login, fk_user_author, fk_user_mod, fk_user_valid, morphy, fk_adherent_type, entity, import_key, ip)\";", "a new member starts as draft"),
)

LANG_KEYS = {
    # Loaded by every page through main.inc.php.
    "htdocs/langs/en_US/main.lang": ("About", "Parameter", "Value", "Save", "Error", "Name", "Status",
                                     "January", "February", "March", "April", "May", "June", "July",
                                     "August", "September", "October", "November", "December",
                                     "Type", "Categories", "BackToList", "DateDue", "AmountTTC", "Ref",
                                     "None", "Date", "Action", "Description", "Confirm", "Cancel", "Email",
                                     "Enabled", "Disabled", "Modify", "ReadPermissionNotAllowed",
                                     "Year", "Month", "Week", "Day", "Duration"),
    # Loaded by partners.php and partner_membership.php.
    "htdocs/langs/en_US/members.lang": ("MemberRef", "Member", "MemberType"),
    # Loaded by admin/setup.php and admin/about.php.
    "htdocs/langs/en_US/admin.lang": ("Version", "Publisher", "BackToModuleList", "SetupSaved"),
    # Loaded by vereineindex.php.
    "htdocs/langs/en_US/companies.lang": ("Address", "ThirdParty"),
}


def fetch(ref: str, path: str, cache: Path | None) -> str:
    target = cache / ref / path if cache else None
    if target and target.is_file() and time.time() - target.stat().st_mtime < 6 * 3600:
        return target.read_text(encoding="utf-8", errors="replace")
    url = RAW.format(ref=ref, path=path)
    last = None
    for attempt in range(3):
        try:
            with urllib.request.urlopen(url, timeout=60) as response:
                text = response.read().decode("utf-8", errors="replace")
            break
        except (urllib.error.URLError, TimeoutError) as error:
            last = error
            time.sleep(2 * (attempt + 1))
    else:
        raise SystemExit(f"cannot download {url}: {last}")
    if target:
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text, encoding="utf-8")
    return text


def check(ref: str, cache: Path | None) -> list[str]:
    problems = []
    for path, needle, why in CONTRACTS:
        if needle not in fetch(ref, path, cache):
            problems.append(f"{path} lacks {needle!r} ({why})")
    for path, keys in LANG_KEYS.items():
        text = fetch(ref, path, cache)
        present = set(re.findall(r"^([A-Za-z0-9_]+)\s*=", text, re.MULTILINE))
        problems += [f"{path} lacks the language key {key}" for key in keys if key not in present]
    return problems


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("versions", nargs="*", default=list(SUPPORTED), help="Dolibarr branches, e.g. 24.0")
    parser.add_argument("--cache", type=Path, help="folder that keeps downloaded sources for six hours")
    arguments = parser.parse_args(argv)
    failed = False
    for version in arguments.versions:
        if version not in SUPPORTED:
            print(f"Dolibarr {version} is not a supported version ({', '.join(SUPPORTED)})", file=sys.stderr)
            return 2
        problems = check(version, arguments.cache)
        if problems:
            failed = True
            print(f"Dolibarr {version}: {len(problems)} problems", file=sys.stderr)
            for problem in problems:
                print(f"  {problem}", file=sys.stderr)
        else:
            count = len(CONTRACTS) + sum(len(keys) for keys in LANG_KEYS.values())
            print(f"Dolibarr {version} source/API compatibility check: OK ({count} contracts)")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
