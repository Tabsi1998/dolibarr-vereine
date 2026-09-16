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
| `partners.php`, `partner_membership.php`, `admin/partners.php` | Reconciliation, membership tab, partner setup |
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

## Rules for every change

- Nothing changes silently: runs and reports show a preview first and are logged.
- Sensitive personal data (birth dates, vbPK) gets its own right and is stored
  encrypted.
- Features of newer Dolibarr versions are detected, not assumed from a version
  number.
- German and English texts change together; English is always complete.
