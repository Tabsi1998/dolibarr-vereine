# Architecture

## Principle: extend Dolibarr, do not rebuild it

Members, member types, subscriptions, donations, invoices, bank accounts, SEPA
direct debits, events, the agenda, documents, email templates, cron jobs and
the REST API already exist in Dolibarr. The module uses those objects and adds
only what an association under Austrian or German law needs on top. It never
changes Dolibarr's files and writes only below the documents folder.

## Country profiles

An association chooses one profile, `AT` or `DE`
(`class/vereineprofile.class.php`). The profile decides the register (ZVR number
or VR number with court), the authorities and - from version 0.2 on - spheres,
tax profiles, thresholds, forms and texts. Austria is complete first; Germany
is a preview until version 1.1.

Legal amounts and deadlines will live in data with a validity period and a
legal source, not in code, so a changed threshold is a data update.

## Layout

| Path | Content |
| --- | --- |
| `core/modules/modVereine.class.php` | Descriptor: id 492100, rights, menu, dependencies |
| `class/vereineprofile.class.php` | Country profiles and input rules, plain PHP |
| `class/vereineorganization.class.php` | Association data and checks, plain PHP; `load()` reads Dolibarr |
| `class/api_vereine.class.php` | REST API class `Vereine` |
| `class/vereinepartnerrules.class.php` | Member and third party decisions, plain PHP |
| `class/vereinepartnerservice.class.php` | Links, creates and reconciles members and third parties in Dolibarr |
| `class/vereinelog.class.php` | The append-only log `llx_vereine_log` |
| `core/triggers/interface_99_modVereine_VereineTriggers.class.php` | Member events keep the third party in line |
| `partners.php`, `partner_membership.php`, `member_association.php`, `admin/partners.php` | Reconciliation, tab on the third party, tab on the member, partner setup |
| `class/actions_vereine.class.php` | Hooks on Dolibarr's member card |
| `class/vereinetaxrules.class.php` | Spheres, VAT treatments, tax profile checks and suggestions, plain PHP |
| `class/vereinetaxprofiles.class.php`, `admin/taxprofiles.php` | Tax profiles in `llx_vereine_taxprofile` and their setup tab |
| `class/vereinefeerules.class.php` | Next fee period and amount of a member: fee year, proration, admission fee, plain PHP |
| `class/vereinefeediscounts.class.php` | Which discount a member gets on a fee, plain PHP |
| `class/vereinefeediscountstore.class.php` | Discount rules in `llx_vereine_fee_discount` and the member fields for exemption and proof |
| `class/vereinefeerun.class.php`, `fees_run.php` | Fee run: preview of the fees due, subscription period and linked invoice per fee, recent fee invoices |
| `class/vereinefeemodel.class.php`, `admin/fees.php` | Fee model as extra fields of Dolibarr's member type (`vereine_fee_start_month`, `vereine_fee_proration`, `vereine_admission_fee`, `vereine_fee_product`; the checkbox `vereine_fee_prorated` of 0.3.4 and 0.3.5 becomes `month` on activation) and its setup tab |
| `class/vereinetaxassign.class.php` | Extra field `vereine_taxprofile` on products and invoice lines, product VAT, line profiles, deviations |
| `class/vereinethresholds.class.php` | Dated threshold table and traffic light, plain PHP |
| `class/vereinecashregister.class.php` | Cash register duty per sphere and sharing cash out over spheres, plain PHP |
| `class/vereinemembersummary.class.php` | Membership status, fee status and dates of a member summary, plain PHP |
| `class/vereinememberreport.class.php` | Reads a member summary and its open invoices; finds members by number or e-mail |
| `class/vereinewebsiteevents.class.php` | Raises `VEREINE_MEMBER_CHANGED` with the member id only, for Dolibarr's webhooks |
| `docs/openapi.json` | OpenAPI 3.0 description of every endpoint; `tests/run.php` compares it with the API class, `tests/runtime/openapi.py` with the answers |
| `class/vereinethresholdreport.class.php`, `core/boxes/box_vereine_thresholds.php` | Income per tax profile from invoices; home page box |
| `js/partners.js` | Select all and the row dialog of the reconciliation page |
| `sql/` | Tables, created on activation and kept on deactivation |
| `lib/vereine.lib.php` | Shared page helpers |
| `vereineindex.php` | Overview under Members |
| `admin/setup.php`, `admin/about.php` | Setup and about pages |
| `langs/*/vereine.lang` | Translations; `en_US` is complete by rule |
| `tests/run.php` | Tests without Dolibarr |
| `tests/runtime/` | Tests in running Dolibarr 22, 23 and 24 |
| `scripts/` | Checks, package build, release |

Logic that can be tested without Dolibarr stays in plain PHP classes; pages and
the API only read input, call those classes and render.

## Data

The association's data are Dolibarr constants (`VEREINE_COUNTRY_PROFILE`,
`VEREINE_REGISTER_NUMBER`, `VEREINE_REGISTER_COURT`, `VEREINE_AUTHORITY`,
`VEREINE_FOUNDED`, `VEREINE_NONPROFIT`, `VEREINE_PURPOSE`) per entity.
Deactivating the module keeps them.

`llx_vereine_log` records what the module changed, by whom and when. Nothing in
the module updates or deletes a row.

## Members and third parties

- Dolibarr links one member to one third party (`llx_adherent.fk_soc`;
  `Adherent::setThirdPartyId` unlinks any other member). A family paying for
  several members therefore cannot share one third party through this link;
  fee runs (issue #4) handle the payer separately.
- Third parties are created with Dolibarr's `Societe::create_from_member` and
  linked with `Adherent::setThirdPartyId`; single fields change through
  `CommonObject::setValueFrom`, categories through `Categorie::add_type`.
- The categories *Member*, *Former member* (customer) and *Guardian* (contact)
  are created on activation and found again by the ids in
  `VEREINE_CATEGORY_MEMBER`, `VEREINE_CATEGORY_FORMER`, `VEREINE_CATEGORY_GUARDIAN`,
  so the association may rename them.
- Guardians are a contact category, not a third party contact role: Dolibarr
  offers roles on third parties only behind the hidden option
  `MAIN_SUPPORT_SHARED_CONTACT_BETWEEN_THIRDPARTIES`, which it calls unstable.
- A trigger never makes a member's own action fail. Problems go to the log as
  `partner_error`.
- Dolibarr's member card links a third party without a trigger
  (`Societe::create_from_member` and `Adherent::setThirdPartyId` write `fk_soc`
  with plain SQL). The hook `doActions` notes the link before Dolibarr's action;
  `addMoreActionsButtons` runs later in the same request with the member loaded
  again and calls `VereinePartnerService::onMemberCardLink`. Dolibarr's actions
  are not replaced, and viewing the card changes nothing.
- Invoice PDFs: the hook `beforePDFCreation` adds the tax profile notes and the
  register number to the invoice's `note_public` in memory, which Dolibarr's
  templates print; `afterPDFCreation` puts the note back. No template changes,
  nothing is stored.
- The reconciliation page prints the steps of each row as real forms and links
  (with CSRF token) after its main form, because forms cannot nest;
  `js/partners.js` only shows them in a jQuery UI dialog and ticks *select all*.
  The runtime tests submit those forms exactly as a browser would.

## Membership fees

- The fee model is four extra fields of Dolibarr's member type (see Layout);
  amount and duration stay Dolibarr's own. `VereineFeeRules` works out period
  and amount, plain PHP.
- A fee run creates, per member and period, what Dolibarr's member card creates
  for *New subscription* with *Create invoice*
  (`Adherent::subscriptionComplementaryActions`): a subscription period with
  `Adherent::subscription()` and a validated invoice whose `linked_objects`
  hold the subscription. Period and invoice are created in one transaction.
  The invoice line is the fee product of the member type (Dolibarr's
  `ADHERENT_PRODUCT_ID_FOR_SUBSCRIPTIONS` when the type has none) with the
  gross amount, so the product's tax profile reaches the line.
- `llx_subscription` has a unique key on member and start day, and a period
  already created is not due any more: a second run for the same day creates
  nothing.

- Discounts are rules in `llx_vereine_fee_discount` (by age on the first day of
  a period, or with a proof) and member fields (`vereine_fee_exempt`,
  `vereine_fee_exempt_reason`, `vereine_fee_proof`, `vereine_fee_proof_until`).
  `VereineFeeDiscounts` picks at most one per fee: exemption, valid proof, age.
  The discount lowers the member type's amount before proration; an exemption
  also drops the admission fee. A fee of 0 records the period without invoice,
  which the member summary counts as paid.

### Recognising fee invoices

A customer invoice is a membership fee invoice when `llx_element_element` links
it to a subscription period:

```sql
SELECT ee.fk_target AS invoice_id, s.fk_adherent AS member_id, s.dateadh, s.datef
FROM llx_element_element AS ee
INNER JOIN llx_subscription AS s ON s.rowid = ee.fk_source
WHERE ee.sourcetype = 'subscription' AND ee.targettype = 'facture'
```

Dolibarr writes this link itself when a fee invoice is created on the member
card, and the fee run writes it the same way, so other modules - such as a
dunning module that treats membership fees differently from sales - need
nothing from this module to read it. The website API shows it as `fee` on every
invoice.

## Rules for every change

- Nothing changes silently: runs and reports show a preview first and are logged.
- Sensitive personal data (birth dates, vbPK) gets its own right and is stored
  encrypted.
- Features of newer Dolibarr versions are detected, not assumed from a version
  number.
- German and English texts change together; English is always complete.
