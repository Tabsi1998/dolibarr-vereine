# Vereine for Dolibarr

A Dolibarr ERP & CRM module for associations (Vereine) under Austrian and
German law. It builds on Dolibarr's own Members, Donations, Invoices, Bank and
SEPA features and adds what associations in Austria and Germany need on top -
with a REST API for the association's website.

[Deutsche Beschreibung](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/README-de.md)

> **Beta.** Version 0.1 lays the foundation: country profile, register data,
> an overview with checks, and the first API endpoints. Tax profiles,
> membership fee runs, donation reporting, volunteer allowances, officers,
> annual accounts and general meetings follow milestone by milestone - see the
> [roadmap](https://github.com/Tabsi1998/dolibarr-vereine/milestones).

## What version 0.1 does

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

## Where it sits in Dolibarr

| Place in Dolibarr | What the module adds or uses |
| --- | --- |
| *Home > Setup > Company/Organisation* | Name, address, e-mail, phone, website and first month of the fiscal year - the module reads them, it does not keep a copy |
| *Home > Setup > Modules > Vereine* | Setup: country profile and register data; about page with version and licence |
| *Members* (Dolibarr's own module) | Required and enabled with Vereine; the left menu gets the entry *Association* |
| *Members > Association* | Overview with the association's data and the checks |
| *Users & Groups > Permissions* | *Vereine: Read the association overview and its data* - for the overview and the API |
| *API REST* module | Needed for `/api/index.php/vereine/...`; the overview warns while it is off |

How the parts connect: the setup stores the association data as Dolibarr
constants; the overview and the API read the same data through one class
(`VereineOrganization`), so a website sees exactly what the overview shows.

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
| Dolibarr modules | Members | REST API for the website endpoints |

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

Updating works the same way: deploy the newer ZIP and re-enable the module.
The association data stays.

## Using the API from a website

Create a Dolibarr user for the website with only the rights it needs, generate
an API key for it, and call the API from the website's server - never from
the browser, where the key would be visible:

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
