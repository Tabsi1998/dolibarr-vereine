# REST API

The module adds endpoints below Dolibarr's REST API at
`https://<dolibarr>/api/index.php/vereine/`. They need Dolibarr's *API REST*
module and a user with the right **Read the association overview and its data**
(`vereine > association > read`). The member endpoints need the right **Read
member summaries for a website** (`vereine > website > read`) as well.

[`openapi.json`](openapi.json) describes every endpoint as OpenAPI 3.0. The
runtime checks compare every answer of the module with it in Dolibarr 22, 23
and 24; a field it does not list fails the check.

Authenticate with the user's API key in the `DOLAPIKEY` header. Call the API
from a server, never from a browser: whoever sees the key acts as that user.
Since Dolibarr 24 the login endpoints are off by default, so a key is the way in.

| Answer | Meaning |
| --- | --- |
| 200 | JSON as described below |
| 400 | A parameter is missing, out of range or malformed, such as an invalid e-mail address or a moment without time zone |
| 401 | No or unknown API key |
| 403 | The user lacks the right |
| 404 | No such member |
| 409 | Several members match |
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
  "country_profile_complete": true,
  "server_time": "2026-09-17T08:00:00Z"
}
```

`server_time` is Dolibarr's clock in UTC - take it before a website sync and use
it as `changed_since` of the next one (see below).

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

## GET /vereine/thresholds

Thresholds of a calendar year (`?year=2026`, the current year when left out).
Needs the right to read invoices as well. Income counts from validated and paid
customer invoices, credit notes and replacements, each line through its tax
profile; lines without profile are reported in `unassigned` and not counted.

```json
{
  "year": 2026,
  "thresholds": [
    {
      "code": "small_business",
      "year": 2026,
      "limit": 55000,
      "gross": true,
      "tolerance": 10,
      "amount": 60000,
      "remaining": -5000,
      "ratio": 1.0909,
      "status": "tolerance",
      "basis": "§ 6 Abs. 1 Z 27 UStG",
      "source": "https://www.jusline.at/gesetz/ustg/paragraf/6",
      "previous_exceeded": false
    }
  ],
  "unassigned": { "net": 500, "gross": 600, "lines": 1 }
}
```

| Field | Content |
| --- | --- |
| `code` | `small_business` (§ 6 (1) no. 27 UStG) or `harmful_business` (§ 45a BAO) |
| `gross` | Whether gross amounts are compared; for `harmful_business` the module compares gross amounts to warn early |
| `status` | `ok` below 80 %, `near` up to the limit, `tolerance` above within the tolerance, `exceeded` |
| `previous_exceeded` | `small_business` only: the year before was above the limit, so the exemption does not apply |

`cash_register` tells per sphere whether a cash register is needed:

```json
"cash_register": {
  "turnover_limit": 15000,
  "cash_limit": 7500,
  "small_canteen_limit": 45000,
  "small_canteen_days": 52,
  "spheres": [
    { "sphere": "harmful", "turnover": 60000, "cash": 14888.34, "status": "required" }
  ],
  "unassigned_cash": 148.88
}
```

`status` is `not_relevant` (idealistic sphere, asset management), `exempt`
(indispensable auxiliary business), `exempt_festival` (small association
festival within 72 hours a year), `ok`, `near` (both from 80 %) or `required`.
Turnover counts gross; cash counts payments of the year in cash, by card,
cheque or online, shared out over the spheres of the paid invoice.

Answers 400 for a year before 2000 or after 2100.

## A user for the website

The website gets its own Dolibarr user that can read exactly what the website
shows. Dolibarr's own endpoints such as `/members` or `/invoices` answer it with
403, because it lacks the rights to read members, third parties and invoices:
the member summary below is all it sees of a member.

1. *Home > Users & Groups > New user*: login for example `website`, not an
   administrator. The user never logs in to Dolibarr, any strong password will do.
2. Tab *Permissions*, module *Vereine (AT/DE)*: tick **Read the association
   overview and its data** and **Read member summaries for a website**. Nothing
   else.
3. *Modify* on the user card: generate the **API key**, save, and store it on the
   website's server only, for example as `DOLIBARR_API_KEY` in its `.env`.
4. Test from the website's server:

```bash
curl --fail -H "DOLAPIKEY: $DOLIBARR_API_KEY" \
  "https://erp.example.org/api/index.php/vereine/members/lookup?ref=1"
```

Whoever has the key can read the summary of every member and find members by
e-mail address. Treat it like a password: never in the browser, never in a
repository.

## GET /vereine/membershipfees

The member types in use with their fee, for "become a member" on a website.
Needs the website right.

```json
[
  {
    "id": 2,
    "label": "Ordentliches Mitglied",
    "description": "Für alle, die mitspielen.",
    "for": "both",
    "subscription_required": true,
    "amount": 60,
    "amount_editable": false,
    "duration": { "value": 1, "unit": "y" },
    "year_starts_month": 1,
    "prorated": true,
    "proration": "month",
    "admission_fee": 20,
    "currency": "EUR"
  }
]
```

| Field | Content |
| --- | --- |
| `description` | Public description of the member type, as plain text |
| `for` | `natural` (persons), `legal` (companies and associations) or `both` |
| `amount` | Fee per period; `null` when the type sets none or needs no subscription |
| `amount_editable` | The member may pay a different amount |
| `duration` | Length of a period: `unit` `y` years, `m` months, `w` weeks, `d` days |
| `year_starts_month` | Month the fee year starts, `0` when every member pays from joining |
| `proration` | How joining during the fee year pays: `none` the full amount; `month`, `quarter` or `half_year` the remaining months, quarters or half-years of the fee year, the one of joining counted in full. `half_year`: joining in the first half pays in full, in the second half the half |
| `prorated` | `true` unless `proration` is `none`; kept from version 0.3.4 |
| `admission_fee` | Once, with the first fee; `0` when there is none |

Example: `amount` 60, a year from January, prorated, joining on 15 March - the
first fee covers March to December, 10 of 12 months, 50 plus the admission fee.

## GET /vereine/board

The functions of the association with their holders today, in the order of the
function catalogue - for a board page. Needs the right to read member summaries
for a website.

```json
[
  {
    "code": "obmann", "label": "Obmann/Obfrau", "board": true, "represents": true, "auditor": false,
    "holders": [{ "name": "Paula Beispiel", "since": "2026-09-17" }]
  },
  {
    "code": "rechnungspruefung", "label": "Rechnungsprüfer:in", "board": false, "represents": false, "auditor": true,
    "holders": [{ "name": null, "since": "2025-03-01" }, { "name": null, "since": "2025-03-01" }]
  }
]
```

A `name` is `null` unless the website may show it, as set under *Setup > Vereine
> Functions*:

- **only with consent** (default): the holder's latest consent for the chosen
  consent text is given;
- **board always with names**: for functions of the board also without consent,
  for a website that must disclose the board (§ 25 (2) MedienG, websites going
  beyond presenting the association); other functions still need consent.

An empty `holders` list means the function is vacant. Show the function without
name where `name` is `null`.

## GET /vereine/consents

The consent texts a person can agree to now - one per purpose, in its newest
version. The association writes them under *Setup > Vereine > Consents*; a
changed text becomes a new version. Needs the right to read member summaries for
a website or to send membership applications.

```json
[
  { "code": "fotos", "label": "Fotos auf der Website", "version": 2, "text": "Fotos von Veranstaltungen, ..." },
  { "code": "newsletter", "label": "Newsletter", "version": 1, "text": "Ich möchte den Newsletter ..." }
]
```

Show `text` next to the checkbox and send `code` and `version` back with the
application. An application with an older version answers 400: read the texts
again right before showing the form.

## POST /vereine/applications

A membership application from the website. It creates a member **in draft** with
the consents given; the association checks and validates the member in Dolibarr,
the website never can. Give the form's user its own API user with only the rights
*Read the association* and *Send membership applications*.

```json
{
  "external_id": "web-2026-0042",
  "firstname": "Amelie",
  "lastname": "Beispiel",
  "email": "amelie@example.org",
  "birth": "2001-04-30",
  "address": "Hauptplatz 1",
  "zip": "6020",
  "town": "Innsbruck",
  "country_code": "AT",
  "type_id": 2,
  "note": "Ich spiele gern Schach.",
  "consents": [{ "code": "fotos", "version": 2 }]
}
```

Answer:

```json
{ "id": 57, "ref": "57", "status": "draft", "duplicate": false }
```

| Field | Content |
| --- | --- |
| `external_id` | The website's own id of the application, up to 64 letters, digits and `. _ : -`. Sent again with the same id - after a network error, say - the answer is the member created the first time with `duplicate: true`; nothing new is created |
| `morphy`, `company` | `phy` (default) or `mor` with the name of the legal entity |
| `firstname`, `lastname`, `email` | Required |
| `type_id` | Required, an active member type of [`GET /vereine/membershipfees`](#get-vereinemembershipfees) open to this kind of person |
| `birth` | `YYYY-MM-DD`, optional; discounts by age need it. Required while the statutes set a minimum age (setup tab *Statutes*): younger applicants are refused with 400 |
| `note` | Message of the applicant, kept as private note of the member |
| `consents` | The consents given, each with the version shown; purposes not ticked are left out |

Answers 400 with every problem in the message, 403 without the right. Consents
of children: for an online form offered directly to children, a child in Austria
can consent itself from 14 years of age (§ 4 (4) DSG); for younger ones ask the
parents.

## GET /vereine/members

Summaries of all members, by id, for a website that keeps its own copy.
`?limit=` (1 to 100, default 100) and `?page=` (from 0) page through them.

With `?changed_since=2026-09-17T08:00:00Z` only the members whose summary
changed at or after that moment come back. The moment needs a time zone (`Z`
or an offset such as `+02:00`); anything else answers 400.

A summary counts as changed - and its `updated_at` moves - when

- the member changes: status, member type, third party, name, number, paid until;
- its member type changes, for example the fee amount;
- a subscription period of the member is added or changed;
- an invoice of its third party, or a fee invoice linked to one of its
  subscription periods (a payer's family invoice), is validated, changed, paid
  or abandoned;
- a payment on such an invoice is added or changed;
- the member's fee fields change: exemption, proof, payer (*Fees paid by*);
- an exit is recorded, carried out or taken back;
- a function of the member starts or ends;
- a fee becomes due or an invoice overdue by the date alone: the summary changes
  at midnight (server time) of the day after the period or the due date.

Not noticed, so a full sync now and then is still worth it, for example once a
night:

- deleted members, subscription periods, invoices or payments;
- a credit note or deposit used on an invoice that stays unpaid;
- discount rules and the family rule in the fee setup, which change the amount
  for many members at once;
- switching an online payment service on or off (the payment links).

A sync that loses nothing:

1. Take `server_time` from `GET /vereine/status` and keep it.
2. Read `GET /vereine/members?changed_since=<the time kept last time>`, page by
   page until a page is empty.
3. After the last page, keep the new time from step 1 for the next sync.

The first sync leaves `changed_since` out and reads every member.

## GET /vereine/members/{id}/summary

What a website shows a member about the membership. `{id}` is the member's id
in Dolibarr (the number in the address of the member card).

```json
{
  "id": 12,
  "ref": "12",
  "firstname": "Paula",
  "lastname": "Beispiel",
  "company": "",
  "type": { "id": 2, "label": "Ordentliches Mitglied" },
  "status": "active",
  "member_since": "2023-01-01",
  "paid_until": "2026-12-31",
  "functions": [{ "code": "kassier", "label": "Kassier:in", "since": "2026-03-01" }],
  "membership_ends": "",
  "currency": "EUR",
  "fee": {
    "required": true,
    "status": "paid",
    "next_due": "2027-01-01",
    "amount": 50,
    "discount": { "kind": "none", "label": "" },
    "payer": "self",
    "payment_url": ""
  },
  "open_invoices": [
    {
      "id": 31,
      "ref": "FA2608-0003",
      "type": "standard",
      "date": "2026-08-01",
      "due_date": "2026-08-15",
      "total": 60,
      "remaining": 50,
      "status": "overdue",
      "overdue": true,
      "payment_url": "https://erp.example.org/public/payment/newpayment.php?source=invoice&ref=FA2608-0003&securekey=...",
      "fee": true
    }
  ],
  "updated_at": "2026-09-17T06:12:40Z"
}
```

| Field | Content |
| --- | --- |
| `ref` | Member number (Dolibarr's reference of the member) |
| `company` | Name of a legal entity; empty for natural persons |
| `status` | `draft` (not yet validated), `active`, `terminated` (resiliated in Dolibarr), `excluded` |
| `member_since` | Start of the first subscription period or the validation date, whichever is earlier; empty for drafts |
| `paid_until` | End of the last paid subscription period, the whole day included: a period without fee invoice (recorded as paid on the member card) or with its fee invoice paid. Empty when the member never paid |
| `functions` | Functions the member holds today, each with `code`, `label` and `since`; the member's own data, so no consent is needed |
| `membership_ends` | Last day of the membership after a recorded exit: a resignation with the notice period of the statutes, an exclusion, death or being struck off. `status` stays `active` until that day. Empty without exit or after an exit was taken back |
| `fee.required` | Whether the member type needs a subscription |
| `fee.status` | `paid` (a paid period covers today), `invoiced` (the period covering today has a fee invoice that is not paid yet, see `open_invoices`), `due` (never paid, or the last period has ended), `not_required` (member type without subscription), `inactive` (draft, terminated or excluded) |
| `fee.next_due` | The day after `paid_until`; the validation date when the member never paid; empty for `not_required` and `inactive` |
| `fee.amount` | Amount of a whole period for this member: the member type's amount after the member's discount on `next_due`, before a family discount; `null` when the type sets none or needs no subscription |
| `fee.discount` | `kind` `none`, `exempt` (label is the reason), `proof` or `age` (label is the name of the discount) |
| `fee.payer` | `self` when the member's own third party gets the fee invoices; `other` when another third party is named as payer, such as a parent paying for a family. The payer's invoices are not listed for the member, and `fee.status` still follows them: `invoiced` until the payer has paid |
| `fee.payment_url` | Dolibarr's online payment page for the fee, only while the fee is `due`, `fee.payer` is `self` and an online payment service (Stripe, PayPal or one added by a module) is set up; otherwise empty. An `invoiced` fee is paid through the payment link of its invoice |
| `open_invoices` | Validated, unpaid invoices of the member's third party, oldest first, at most 50: standard, replacement and deposit invoices. Empty when the member has no third party. A family invoice appears for the member whose third party is the payer. Each invoice as in [`members/{id}/invoices`](#get-vereinemembersidinvoices) |
| `updated_at` | When something in the summary last changed, in UTC; see [`GET /vereine/members`](#get-vereinemembers) for what counts |

Dates are `YYYY-MM-DD` or empty, amounts are numbers in `currency`. The summary
never contains birth date, address, phone, e-mail, notes, bank data or dunning
levels. Dolibarr's member status "subscription late" is `status: active` with
`fee.status: due`.

Answers 404 when there is no member with this id.

## GET /vereine/members/lookup

The same summary, found by member number or e-mail address - to link a website
account with Dolibarr.

| Call | Answer |
| --- | --- |
| `?ref=12` | the member with this member number |
| `?email=paula@example.org` | the member with this e-mail address, ignoring upper and lower case |
| neither or both, or an invalid e-mail address | 400 - Dolibarr's API checks the format of `email` itself, so trim spaces first |
| nobody matches | 404 |
| several members share the e-mail address | 409 - link the account by member number instead |

Look members up once when an account is linked, store the `id`, and read the
summary by id afterwards.

## GET /vereine/members/{id}/invoices

All validated invoices of the member's third party, newest first - for a list
of invoices on the website. Drafts are left out; a member without third party
has none. `?limit=` (1 to 100, default 100) and `?page=` (from 0) page through
them; a page after the last one is an empty list.

```json
[
  {
    "id": 31,
    "ref": "FA2608-0003",
    "type": "standard",
    "date": "2026-08-01",
    "due_date": "2026-08-15",
    "total": 60,
    "remaining": 50,
    "status": "overdue",
    "overdue": true,
    "payment_url": "",
    "fee": true
  }
]
```

| Field | Content |
| --- | --- |
| `id` | Invoice id, for the PDF below |
| `type` | `standard`, `replacement`, `credit_note` (negative amounts) or `deposit` |
| `total` | Amount including VAT |
| `remaining` | What is still to pay after payments, credit notes and deposits; 0 for paid and abandoned invoices |
| `status` | `open`, `overdue` (due date passed), `paid`, `abandoned` |
| `overdue` | `true` exactly when `status` is `overdue` |
| `fee` | A membership fee invoice: linked to a subscription period, whether it came from a fee run or from the member card |
| `payment_url` | Dolibarr's online payment page for an open or overdue invoice other than a credit note, only with an online payment service; otherwise empty |

Answers 400 for a limit or page out of range and 404 when there is no member
with this id.

## GET /vereine/members/{id}/invoices/{invoice}/pdf

The PDF of one invoice of the member, base64 encoded, the same way Dolibarr's
own document download answers:

```json
{
  "filename": "FA2608-0003.pdf",
  "content_type": "application/pdf",
  "filesize": 48213,
  "content": "JVBERi0xLjcK..."
}
```

- The invoice must belong to the member's third party and be validated.
  Another member's invoice, a draft or an unknown invoice answers 404 - the
  same answer, so nobody learns whether a foreign invoice exists.
- A PDF that was never built is built with the invoice's template, in the third
  party's language when Dolibarr uses several languages, and stored like a PDF
  built on the invoice card. Answers 500 when that fails.
- The website must hand the PDF only to the member it belongs to: it carries
  the member's name and address. Pass it through, do not keep it.

## Notifications through webhooks

Instead of asking every few minutes, a website can be told when a member's
summary may have changed. Dolibarr's own webhooks would send the whole member -
birth date, address, notes - and Dolibarr 23 and 24 keep that in their webhook
history. The module therefore raises its own event `VEREINE_MEMBER_CHANGED`
that carries the member id and the cause, nothing else:

```json
{
  "triggercode": "VEREINE_MEMBER_CHANGED",
  "object": {
    "id": 12,
    "element": "vereine_member_event",
    "member_id": 12,
    "cause": "PAYMENT_CUSTOMER_CREATE",
    "occurred_at": "2026-09-17T08:00:00Z",
    "context": []
  }
}
```

Set it up:

1. Enable Dolibarr's module *Webhooks*.
2. Create a webhook target: the website's URL, the event
   *Vereine: member summary changed (for website webhooks)*
   (`VEREINE_MEMBER_CHANGED`) and nothing else, status *automatic*.
3. Type *Non blocking* (Dolibarr 22) or *No check of result* (23 and 24).
   Dolibarr sends while the user waits; the module never lets a failing
   webhook stop a change, but a slow website still slows Dolibarr down.

On the website:

- Dolibarr signs nothing. Put a long random token into the target URL and
  refuse requests without it. A forged event can do no more than make the
  website read a summary again.
- Read the summary with the API a few seconds after the event, not at once:
  Dolibarr sends while its database transaction is still open, so an immediate
  read can see the old state. A 404 means the member was deleted.

| `cause` | What happened |
| --- | --- |
| `MEMBER_CREATE`, `MEMBER_VALIDATE`, `MEMBER_MODIFY`, `MEMBER_RESILIATE`, `MEMBER_EXCLUDE`, `MEMBER_DELETE` | The member |
| `MEMBER_SUBSCRIPTION_CREATE`, `MEMBER_SUBSCRIPTION_MODIFY`, `MEMBER_SUBSCRIPTION_DELETE` | A subscription period of the member |
| `BILL_VALIDATE`, `BILL_UNVALIDATE`, `BILL_MODIFY`, `BILL_PAYED`, `BILL_UNPAYED`, `BILL_CANCEL`, `BILL_DELETE` | A customer invoice of the member's third party, or a fee invoice linked to the member's subscription period - so paying a family invoice announces every member on it (drafts only when they became one again) |
| `PAYMENT_CUSTOMER_CREATE`, `PAYMENT_CUSTOMER_DELETE` | A payment on such an invoice |

One action raises one event per member, even when Dolibarr reports several
things at once (a payment that also closes the invoice). Not announced: a
changed member type, a fee that becomes due or an invoice that becomes overdue
by the date, and the online payment service - keep the nightly sync with
[`GET /vereine/members`](#get-vereinemembers).

## Example

```bash
curl --fail -H "DOLAPIKEY: $DOLIBARR_API_KEY" \
  https://erp.example.org/api/index.php/vereine/organization
```

The endpoints are also listed in Dolibarr's API explorer at
`/api/index.php/explorer` once the module is enabled, and in the module's setup
tab *API* with the rights each one needs (`x-vereine-rights` in
`docs/openapi.json`: every right of one inner list is enough, every list is
needed) and the users with an API key.
