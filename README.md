# Vereine for Dolibarr

A Dolibarr ERP & CRM module for associations (Vereine) under Austrian and
German law. It builds on Dolibarr's own Members, Donations, Invoices, Bank and
SEPA features and adds what associations in Austria and Germany need on top -
with a REST API for the association's website.

[Deutsche Beschreibung](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/README-de.md)

> **Beta.** Version 0.2 links members and third parties on top of the
> foundation of 0.1 (country profile, register data, overview, API). Tax profiles,
> membership fee runs, donation reporting, volunteer allowances, officers,
> annual accounts and general meetings follow milestone by milestone - see the
> [roadmap](https://github.com/Tabsi1998/dolibarr-vereine/milestones).

## What it does so far

- **Country profile** Austria (complete) or Germany (preview until 1.1).
- **Association data** Dolibarr does not know: ZVR number (Austria) or register
  number and register court (Germany), association authority, founding date,
  non-profit status and purpose. Name, address and contact stay in Dolibarr's
  company settings.
- **Overview** under *Members > Association*: the association's data and checks
  for what is missing, for example a ZVR number that must appear on letters,
  invoices and the website (§ 18 VerG).
- **REST API** for websites: `GET /api/index.php/vereine/organization` and
  `GET /api/index.php/vereine/status`. See [docs/API.md](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/docs/API.md).
- **Member summaries for the website** (0.3): membership, fee status with payment
  link and open invoices of a member, found by member number or e-mail address,
  for a website user that cannot read anything else of Dolibarr. All invoices of
  a member with their PDFs (0.3.1). A sync that reads only the members changed
  since the last one (0.3.2). Webhook notifications with the member id only
  (0.3.3). Described in
  [docs/openapi.json](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/docs/openapi.json).
- **Members and third parties** (0.2): a validated member can get its third
  party automatically; an existing third party with the same e-mail (or name
  and postcode) is suggested instead of duplicated. Third parties of members
  carry the category *Member* or *Former member* according to the member status,
  are marked as customers and get the private customer type when they have none.
- **Reconciliation page** *Members > Association > Members and third parties*:
  what is not linked or not consistent - with a preview before every change.
  *Select all* per section; a click on a row opens a dialog with the steps for
  that row (bring in line, link, edit member or third party).
- **Tab *Membership*** on the third party card and **tab *Association*** on the
  member card, and guardians of minor members as contacts in the category
  *Guardian*.
- **Tax profiles** (0.2.3, Austria): sphere, VAT treatment, rate and invoice
  note with their legal basis, suggested profiles and checks for combinations
  the law excludes. Products and invoice lines carry a tax profile (0.2.5): the
  product's VAT rate follows it, new invoice lines take it over, and an invoice
  lists lines whose rate does not match. Invoice PDFs print the notes per line
  and the ZVR number (0.2.6).
- **Thresholds as traffic light** (0.2.7): small business limit and § 45a BAO per
  calendar year from the invoices, on the overview, as home page box and in the API.
- **Cash register check** per sphere and the missing **13 % VAT rate** for Austria
  added on request (0.2.8).
- Dolibarr's own buttons on the member card (*Create third party*, *Linked third
  party*) work too: the module brings the third party in line right away.

## Tax profiles in plain words

A tax profile says how one kind of income is treated for tax. Three questions
lead there: what kind of income is it (the area of the association), is VAT due
(treatment and rate), and what the invoice says when no VAT is charged. The
setup page explains every area with the examples of the Austrian ministry of
finance, for example a theatre association's performance as indispensable
auxiliary business or a canteen the association runs itself as business harmful
to tax privileges. The module calculates and warns; it gives no advice. Sources
are listed in [docs/LEGAL-SOURCES.md](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/docs/LEGAL-SOURCES.md).

## Where it sits in Dolibarr

| Place in Dolibarr | What the module adds or uses |
| --- | --- |
| *Home > Setup > Company/Organisation* | Name, address, e-mail, phone, website and first month of the fiscal year - the module reads them, it does not keep a copy |
| *Home > Setup > Modules > Vereine* | Setup: country profile and register data; tax profiles; about page with version and licence |
| *Members*, *Third parties*, *Categories* (Dolibarr's own modules) | Required and enabled with Vereine; the Members menu gets *Association*, *Members and third parties* and, for administrators, *Third party settings* |
| *Members > Association* | Overview with the association's data, the checks and the thresholds of the year |
| Home page | Box *Vereine: thresholds of the year* (users who may read invoices) |
| *Members > Association > Members and third parties* | Reconciliation of members and their third parties |
| Third party card | Tab *Membership*; categories *Member* and *Former member* |
| Member card | Tab *Association*; *Create third party* and *Linked third party* are followed by the module |
| Product and service card, invoice and supplier invoice lines | Extra field *Tax profile*; invoices warn about lines whose VAT differs from their profile |
| Invoice PDF (Dolibarr's templates, unchanged) | Tax profile notes per line and the ZVR number in the note area |
| Contacts of a member's third party | Category *Guardian* for guardians of minors |
| *Home > Setup > Modules > Vereine > Members and third parties*, also *Members > Association > Third party settings* | Automatic creation, categories, customer types (administrators) |
| *Users & Groups > Permissions* | *Read the association overview and its data* (overview, API); *Link members and third parties and bring them in line* (reconciliation changes); *Read member summaries for a website* (member endpoints of the API) |
| *API REST* module | Needed for `/api/index.php/vereine/...`; the overview warns while it is off |

How the parts connect: the setup stores the association data as Dolibarr
constants; the overview and the API read the same data through one class
(`VereineOrganization`), so a website sees exactly what the overview shows.
Member events (validate, resign, exclude, change, delete) reach the module
through a Dolibarr trigger, which keeps the linked third party in line; the
dunning module can then tell members apart by category and customer type.

## Versions and updates

Every merged change is published as a release with its patch notes on the
[releases page](https://github.com/Tabsi1998/dolibarr-vereine/releases).
Versions below 1.0.0 are betas (`v0.1.0-beta`, `v0.1.1-beta`, ...) and marked
as pre-releases; 1.0.0 is the first stable release. The file to install is
always `module_vereine-x.y.z.zip` - without `-beta`, because Dolibarr only
accepts that name.

## Requirements

| | Minimum | Tested |
| --- | --- | --- |
| Dolibarr | 22.0 | 22.0.5, 23.0.4, 24.0.1 |
| PHP | 7.4 | 7.4, 8.1, 8.2, 8.3, 8.4 |
| Dolibarr modules | Members, Third parties, Categories | REST API for the website endpoints |

## Installation

1. Download `module_vereine-x.y.z.zip` from the
   [releases](https://github.com/Tabsi1998/dolibarr-vereine/releases).
2. In Dolibarr open *Home > Setup > Modules > Deploy an external module* and
   upload the ZIP. Do not rename it: Dolibarr only accepts the original name.
3. Enable **Vereine (AT/DE)** in the module list. The Members module is enabled
   with it.
4. Open the module's setup, choose the country profile and enter the register
   data.
5. Give users the right *Read the association overview and its data*.

Updating works the same way: deploy the newer ZIP, then disable and enable the
module once in the module list, so new tables, rights and categories are
created. The association data stays.

## Using the API from a website

Create a Dolibarr user for the website with only the rights it needs, generate
an API key for it, and call the API from the website's server - never from
the browser, where the key would be visible. [docs/API.md](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/docs/API.md#a-user-for-the-website)
lists the steps and the two rights:

```bash
curl -H "DOLAPIKEY: <key>" https://erp.example.org/api/index.php/vereine/organization
```

## Not tax or legal advice

The module calculates and warns. How an activity is classified for tax
purposes remains the association's decision, ideally with a tax advisor.

## Support and contributing

Report problems and ideas in the
[issue tracker](https://github.com/Tabsi1998/dolibarr-vereine/issues). Security
problems go through a private
[security advisory](https://github.com/Tabsi1998/dolibarr-vereine/security/advisories/new),
see [SECURITY.md](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/SECURITY.md). Contributions are welcome, see
[CONTRIBUTING.md](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/CONTRIBUTING.md).

## Licence

Copyright (C) 2026 IT-Tabelander. Free software under the GNU General Public
License v3.0 or later, see [LICENSE](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/LICENSE).
