# REST API

The module adds endpoints below Dolibarr's REST API at
`https://<dolibarr>/api/index.php/vereine/`. They need Dolibarr's *API REST*
module and a user with the right **Read the association overview and its data**
(`vereine > association > read`).

Authenticate with the user's API key in the `DOLAPIKEY` header. Call the API
from a server, never from a browser: whoever sees the key acts as that user.
Since Dolibarr 24 the login endpoints are off by default, so a key is the way in.

| Answer | Meaning |
| --- | --- |
| 200 | JSON as described below |
| 401 | No or unknown API key |
| 403 | The user lacks the right |
| 501 | The Vereine module is disabled |

`api_version` rises when a field changes meaning or disappears. New fields can
appear in any version; clients should ignore fields they do not know.

## GET /vereine/status

Module version, API version and country profile - a cheap way to test a
connection.

```json
{
  "module_version": "0.1.0-beta",
  "api_version": 1,
  "country_profile": "AT",
  "country_profile_complete": true
}
```

## GET /vereine/organization

The association, for example for a website imprint. Name, address and contact
come from Dolibarr's company settings; the rest from the module's setup.

```json
{
  "country_profile": "AT",
  "country_profile_complete": true,
  "name": "THE LION SQUAD",
  "register": { "kind": "ZVR", "number": "123456789", "court": "" },
  "authority": "Landespolizeidirektion Tirol",
  "address": { "street": "Musterweg 1", "zip": "6020", "town": "Innsbruck", "country_code": "AT" },
  "email": "office@example.org",
  "phone": "",
  "url": "https://example.org",
  "founded": "2019-03-01",
  "nonprofit": true,
  "purpose": "Förderung des E-Sports",
  "fiscal_year_start_month": 1
}
```

| Field | Content |
| --- | --- |
| `country_profile` | `AT` or `DE` |
| `country_profile_complete` | `false` while a profile is a preview (Germany until 1.1) |
| `register.kind` | `ZVR` in Austria, `VR` in Germany |
| `register.number` | ZVR number (digits) or VR number such as `VR 12345 B`; empty when not set |
| `register.court` | German register court; always empty in Austria |
| `authority` | Austrian association authority; always empty in Germany |
| `founded` | `YYYY-MM-DD`, or empty |
| `fiscal_year_start_month` | 1 to 12, from Dolibarr's company settings |

Every text field is a string, empty when not set - never `null`.

## GET /vereine/taxprofiles

The association's tax profiles (Austria): sphere and VAT treatment with their
legal basis, rate and invoice note. Profiles marked `standard` were suggested by
the module; the association may have changed them. The classification of an
activity remains the association's decision.

```json
[
  {
    "id": 1,
    "code": "MITGLIEDSBEITRAG",
    "label": "Mitgliedsbeitrag (echt)",
    "sphere": "ideal",
    "sphere_basis": "§§ 34 bis 47 BAO",
    "treatment": "nonbusiness",
    "treatment_basis": "kein Leistungsaustausch",
    "rate": 0,
    "note": "Echter Mitgliedsbeitrag ohne Gegenleistung, nicht umsatzsteuerbar.",
    "active": true,
    "standard": true
  }
]
```

| Field | Content |
| --- | --- |
| `id` | Value of the extra field `vereine_taxprofile` on products and invoice lines (`array_options.options_vereine_taxprofile` in Dolibarr's own API) |
| `code` | Stable identifier, capital letters, digits and `_` |
| `sphere` | `ideal`, `assets`, `essential` (§ 45 (2) BAO), `auxiliary` (§ 45 (1) BAO), `festival` (§ 45 (1a) BAO), `harmful` (§ 45 (3) BAO) |
| `treatment` | `nonbusiness`, `hobby` (Liebhaberei), `small_business` (§ 6 (1) no. 27 UStG), `sport` (§ 6 (1) no. 14 UStG), `reduced10`, `reduced13`, `standard20` |
| `rate` | VAT rate in percent, a number |
| `note` | Invoice note, may be empty |
| `active`, `standard` | Booleans |

## Example

```bash
curl --fail -H "DOLAPIKEY: $DOLIBARR_API_KEY" \
  https://erp.example.org/api/index.php/vereine/organization
```

The endpoints are also listed in Dolibarr's API explorer at
`/api/index.php/explorer` once the module is enabled.
