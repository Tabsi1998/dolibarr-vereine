# Changelog

All notable changes to the Vereine module. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/), with `-beta` marking pre-releases.
The section of a version is the text of its GitHub release.

## [Unreleased]

## [0.4.0-beta] - 2026-09-17

Officers, part 1 (issue #76): function catalogue and terms of office.

### Added

- Setup tab *Functions*: the usual functions of Austrian associations are
  suggested - chair, treasurer, secretary with their deputies, auditors - each
  with board, represents the association, auditor and how many are needed. The
  association changes them, switches them off and adds its own.
- The member's tab *Association*, section *Functions*: take over a function from
  a day, end it; ended terms stay.
- *Members > Board and functions*: who holds which function on a day, and what
  does not fit - a function not held or held too often, a board of fewer than
  two persons, an auditor on the board (§ 5 VerG).

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that creates the function tables, the suggested functions and the menu entry.

## [0.3.11-beta] - 2026-09-17

Membership fees, part 6 (issue #65): consents with text version and membership
applications through the API.

### Added

- Setup tab *Consents*: consent texts per purpose, for example photos on the
  website or the newsletter. A changed text becomes a new version; earlier
  consents keep the version agreed to.
- The member's tab *Association*, section *Consents*: state per purpose with
  version, moment and source (website, paper, member card), recording a consent
  on paper, recording a withdrawal, and the whole history. Nothing is changed or
  deleted.
- Website API: `GET /vereine/consents` with the texts to show, and `POST
  /vereine/applications`, which creates a member in draft with its consents -
  once per `external_id` - and needs the new right *Send membership
  applications*.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that creates the consent and application tables and the new right.

## [0.3.10-beta] - 2026-09-17

Membership fees, part 4 (issue #63): SEPA direct debit from the fee run.

### Added

- The fee run checks the payer's mandate - the default bank account of the
  third party with mandate reference and signature date - and reports a mandate
  not used for 36 months as expired.
- With Dolibarr's module *Direct debit payment orders* on, the fee run offers
  *Request direct debit where a valid mandate exists*: the invoice gets payment
  mode direct debit, a pre-notification with amount, earliest collection day,
  mandate reference and creditor identifier, and a direct debit request that
  Dolibarr turns into the order for the bank as usual.
- Setup tab *Fees*, section *SEPA direct debit*: how it works, a warning when the
  module or the creditor identifier is missing, and the days of pre-notification
  (14 unless set otherwise).

### Upgrade

Deploy the new ZIP. Nothing to activate again; enable Dolibarr's module *Direct
debit payment orders* to use it.

## [0.3.9-beta] - 2026-09-17

Membership fees, part 5 (issue #64): exit with reason and the notice period of
the statutes.

### Added

- Setup tab *Fees*, section *Exit according to the statutes*: notice period in
  months and when notice takes effect - on any day, at the end of a month, a
  quarter or the association year, which may start in any month.
- The member's tab *Association*, section *Exit*: resignation, exclusion, death
  or struck off, with the day of notice or decision and an internal note. For a
  resignation the module works out the last day of the membership; the member
  stays active until then and the exit can be taken back.
- On the last day the member is set to resiliated, or excluded for an
  exclusion: at once when that day has come, otherwise by the scheduled job
  *Vereine: exits due* (Dolibarr's module *Scheduled jobs*) or the button *Take
  effect now*.
- The fee run creates no period that starts after the last day and names the
  planned exit.
- The member summary of the website API has `membership_ends`, and a recorded,
  carried out or taken back exit counts as a change for `changed_since`.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that creates the exit table and the scheduled job.

## [0.3.8-beta] - 2026-09-17

Membership fees, part 3b (issue #62): families with one payer.

### Added

- Dolibarr's member card gets *Fees paid by*: the third party that gets the
  member's fee invoices, for example a parent - the third party of another
  member or one that is no member. Members with the same payer are a family;
  every member keeps its own third party or none.
- Setup tab *Fees*, section *Families*: the family rule - no discount, a
  discount in percent for every member after the one with the highest fee, or a
  cap per fee year that counts what was already charged in that year - and the
  list of families.
- The fee run puts fees of a family starting on the same day on one invoice to
  the payer, with one line per member, linked to every subscription period. The
  family rule comes after the member's own discount; the preview names it and
  the payer, and reports a payer that no longer exists.
- The member summary of the website API says who gets the fee invoices
  (`fee.payer`: `self` or `other`) and offers no online payment of the fee when
  a payer gets them. Paying a family invoice counts as a change for every member
  on it, for `changed_since` and for webhooks.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that creates the member field *Fees paid by*.

## [0.3.7-beta] - 2026-09-17

Membership fees, part 3a (issue #62): discounts by age, with proof, and
exemptions.

### Added

- Setup tab *Fees*, section *Discounts*: rules by age on the first day of a fee
  period (for example "youth up to 17: 50 % less") or with a proof such as a
  student card, as percent less, a fixed lower amount per period or free of
  fees, for all or one member type. Rules can be changed and switched off.
- Dolibarr's member card gets *Exempt from fees* with a reason (for example
  honorary members), and *Discount with proof* with the day the proof is valid
  until.
- The fee run applies at most one discount per fee - exemption, then a valid
  proof, then age - names it in the preview and on the invoice line, and
  reports an expired proof or a missing birth date. An exempt member gets the
  subscription period without invoice and without admission fee.
- The member summary of the website API shows the member's amount after the
  discount and the discount (`fee.discount`).

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that creates the discount table and the member fields.

## [0.3.6-beta] - 2026-09-17

Membership fees: prorating by month, quarter or half-year (issue #68).

### Changed

- *Prorated when joining* on the member type is a choice instead of a checkbox:
  not prorated, by month, by quarter or by half-year. By half-year, joining in
  the first half of the fee year pays the full amount and in the second half
  the half; by quarter the remaining quarters. Parts are counted from the start
  of the fee year, the part of joining in full. A fee period that does not
  divide into quarters or half-years is prorated by month.
- A ticked checkbox of 0.3.4 and 0.3.5 becomes "by month" when the module is
  enabled; the old field is then removed.
- The setup tab *Fees*, the fee run and `GET /vereine/membershipfees` show the
  kind of proration (`proration`); `prorated` stays for existing websites.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that replaces the checkbox by the choice and keeps the setting.

## [0.3.5-beta] - 2026-09-17

Membership fees, part 2 (issues #61 and #14): the fee run.

### Added

- *Members > Association > Fee run*: a preview of every fee due up to a day,
  per member type or for all, with period, amount, admission fee and the reason
  in plain words ("prorated, 4 of 12 months", "first fee"). Members without
  third party, member types without amount and a backlog of several periods are
  marked.
- Creating the chosen fees makes, per member and period, what Dolibarr's member
  card makes for "New subscription - Create invoice": a subscription period and
  a validated invoice linked to it, with the fee product of the member type and
  its tax profile. Missing third parties can be created in the same step. A
  second run for the same period creates nothing; a later period is only
  created when the earlier ones are.
- The page lists the latest fee invoices with their state today, including
  those created on the member card, and the module's log records every fee
  invoice and run.
- Fee invoices are recognisable for other modules through Dolibarr's own link
  of invoice and subscription period, described in `docs/ARCHITECTURE.md`; the
  website API marks them with `fee` on every invoice.
- Creating needs Dolibarr's rights to record subscriptions and to create and
  validate invoices.

### Changed

- The member summary counts a subscription period as paid only when it has no
  fee invoice or its fee invoice is paid. Dolibarr treats a period as paid as
  soon as it is recorded, so after a fee run the website would have shown
  "paid until 31 December" for an unpaid invoice; the fee status is now
  `invoiced` until the invoice is paid.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that adds the menu entry *Fee run*.

## [0.3.4-beta] - 2026-09-17

Membership fees, part 1 (issue #60): the fee model of a member type.

### Added

- Dolibarr's member type card gets four fields: the month the fee year starts
  (empty for "from joining", January for the calendar year, September for a
  season), whether a first fee during the fee year is prorated by month, an
  admission fee, and the fee product whose tax profile decides VAT and invoice
  note (Dolibarr's subscription product when empty).
- Setup tab *Fees*: every member type with its fee, a plain-language
  explanation of each setting and what joining today would cost, for example
  "17 September to 31 December: 20.00 € (prorated, 4 of 12 months), plus
  admission fee 20.00 €". It warns about member types without amount and fee
  products without tax profile.
- `GET /vereine/membershipfees` for "become a member" on a website.
- The calculation is plain PHP with unit tests; the fee run (#61) uses it.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that adds the fields to the member type card.

## [0.3.3-beta] - 2026-09-17

Website API, part 4 of 4 (issues #52 and #16): notifications without personal
data.

### Added

- New event `VEREINE_MEMBER_CHANGED` for Dolibarr's webhooks: when a member, a
  subscription period, a customer invoice of the member's third party or a
  payment on it changes, a webhook target receives the member id, the cause and
  the moment - never birth date, address or notes, which Dolibarr's own member
  webhooks would send and keep in their history.
- One event per member per action; a webhook that fails never stops the change.
- `docs/API.md` explains the setup in Dolibarr and on the website.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that lists the new event for webhook targets.

## [0.3.2-beta] - 2026-09-17

Website API, part 3 (issue #51): a sync that reads only changed members.

### Added

- `GET /vereine/members`: summaries of all members by id, paged with `?limit=`
  and `?page=`. With `?changed_since=` only the members whose summary changed at
  or after that moment - also when only a subscription period, an invoice or a
  payment changed, or when a fee became due or an invoice overdue by the date.
- Every member summary carries `updated_at`, the moment it last changed.
- `GET /vereine/status` returns `server_time`, Dolibarr's clock, to start a sync
  from.
- `docs/API.md` lists what counts as a change, what a sync does not notice
  (deletions, a credit note used on an unpaid invoice, the payment service) and
  how to sync without losing anything.

### Upgrade

Deploy the new ZIP. Disabling and enabling is not needed.

## [0.3.1-beta] - 2026-09-17

Website API, part 2 (issue #50): a member's invoices and their PDFs.

### Added

- `GET /vereine/members/{id}/invoices`: all validated invoices of the member's
  third party, newest first, with type, date, due date, amount, remaining amount,
  status (open, overdue, paid, abandoned) and payment link; `?limit=` and
  `?page=` page through them.
- `GET /vereine/members/{id}/invoices/{invoice}/pdf`: the PDF of one of the
  member's invoices, base64 encoded. Another member's invoice, a draft or an
  unknown invoice answers 404. A PDF that was never built is built the way
  Dolibarr builds it on the invoice card.
- Both need only the right *Read member summaries for a website*; the website
  user still cannot read invoices or documents through Dolibarr's own API.

### Changed

- The open invoices in the member summary carry `id`, `type` and `status` as
  well, so the website can fetch their PDFs.

### Upgrade

Deploy the new ZIP. Disabling and enabling is not needed.

## [0.3.0-beta] - 2026-09-17

Website API, part 1 (issues #49 and #16): member summaries for the association's
website.

### Added

- `GET /vereine/members/{id}/summary`: member number, name, member type, status,
  member since, paid until, the fee (paid or due, next due date, amount of the
  member type, payment link) and the open invoices of the member's third party
  with remaining amount, due date and payment link. Payment links appear only
  when Dolibarr has an online payment service such as Stripe or PayPal.
- `GET /vereine/members/lookup?ref=` or `?email=`: the same summary, found by
  member number or e-mail address (ignoring case); 404 when nobody matches, 409
  when several members share the e-mail address.
- New right *Read member summaries for a website*. A website user with only this
  right and *Read the association overview and its data* reads the summaries,
  but not Dolibarr's own member, third party or invoice endpoints. The summary
  never contains birth date, address, phone, e-mail, notes or bank data.
- `docs/openapi.json` describes every endpoint of the module; the runtime checks
  compare every answer with it. `docs/API.md` explains step by step how to set up
  the website user.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that registers the new right.

## [0.2.8-beta] - 2026-09-17

Cash register check and the 13 % VAT rate, part 5 of 5 (issue #3).

### Added

- The overview says per sphere whether a cash register is needed, in plain
  words: indispensable auxiliary businesses and small association festivals
  need none (§ 3 Barumsatzverordnung 2015); other businesses need one above
  15,000 EUR turnover when more than 7,500 EUR of it is paid in cash or by card
  (§ 131b BAO), from the fourth month after the VAT return period in which both
  were first exceeded. The exception for small association canteens (at most 52
  days, 45,000 EUR from 2026) is explained.
- Cash counts payments in cash, by card, cheque or online, shared out over the
  spheres of the paid invoice; the module says that cash sales without invoice
  in Dolibarr are missing and that it is no cash register itself.
- `GET /vereine/thresholds` returns the cash register check as `cash_register`.
- The *Tax profiles* tab offers to add the reduced rate of 13 % (§ 10 (3) UStG)
  to Dolibarr's VAT dictionary for Austria, which ships 0, 10 and 20 % only.
  Adding it twice changes nothing.

### Upgrade

Deploy the new ZIP. Disabling and enabling is not needed.

## [0.2.7-beta] - 2026-09-16

Thresholds and traffic light, part 4 of 5 (issue #3).

### Added

- The overview shows the thresholds of a calendar year as a traffic light, with a
  sentence in plain words: the small business limit (35,000 EUR net until 2024,
  55,000 EUR gross with 10 % tolerance from 2025) and the turnover of businesses
  harmful to tax privileges (§ 45a BAO: 40,000 EUR until 2023, 100,000 EUR from
  2024). Earlier years can be opened; the cash register duty and the 72 hours of
  small festivals are listed and checked later.
- Income counts from validated and paid invoices, credit notes and replacements
  by invoice date, each line through its tax profile. Drafts, abandoned and
  deposit invoices do not count; lines without tax profile are reported, not
  counted. Income of auxiliary businesses (Liebhaberei) and the sports
  exemption does not count towards the small business limit, as the ministry of
  finance says. For § 45a BAO the module compares gross amounts, to warn early
  rather than late.
- A small business limit exceeded in the year before is reported.
- Home page box *Vereine: thresholds of the year* and REST API
  `GET /vereine/thresholds?year=`, both only for users who may read invoices.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that registers the home page box.

## [0.2.6-beta] - 2026-09-16

Invoice PDFs, part 3 of 5 (issue #3).

### Added

- Invoice PDFs print the invoice notes of the lines' tax profiles in the note
  area, with the lines they apply to, for example "Line 1: Genuine membership
  fee without consideration, not subject to VAT." The same note on several
  lines is printed once.
- Invoice PDFs print the association's ZVR number (in Germany the register
  number and court).
- Both can be switched off: *Tax profiles* tab and association setup. Both are
  on by default.
- Dolibarr's PDF templates stay as they are: the text is added to the invoice's
  public note for the PDF only and put back afterwards; nothing is stored.

### Changed

- The reason for exemption for e-invoices (VATEX) moved to its own issue: in
  Dolibarr 24 it is a column of the VAT dictionary that no core code reads, one
  code per rate.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that registers the PDF hook. Build an invoice PDF again to see the notes.

## [0.2.5-beta] - 2026-09-16

Tax profiles on products and invoices, part 2 of 5 (issue #3).

### Added

- Extra field *Tax profile* on products and services, customer invoice lines and
  supplier invoice lines. The list offers active profiles only. Dolibarr's REST
  API shows it as `options_vereine_taxprofile`; `GET /vereine/taxprofiles` now
  returns the matching `id`.
- A product's VAT rate follows its tax profile when the product is created or
  saved. The rate changes through Dolibarr's price update, so the gross price is
  calculated again. With several price levels the module only says which rate to
  set.
- A new invoice line with a product takes the product's tax profile; a line
  without product can choose one.
- Customer and supplier invoices list the lines whose VAT rate differs from
  their tax profile, in plain words. The module never changes an invoice.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that adds the extra field and the invoice hooks. Existing products and invoices
keep their rates; choose a tax profile on a product to let its rate follow.

## [0.2.4-beta] - 2026-09-16

Tax profiles in plain words.

### Changed

- The tax profile setup starts with three questions (what kind of income, is
  VAT due, what the invoice says) and explains every area of an association and
  every VAT treatment in everyday language, with the examples of the Austrian
  ministry of finance's brochure "Vereine und Steuern".
- Areas and VAT treatments are named by what they mean first, the technical
  term second; the legal basis is a small link for the tax advisor.
- Refusals say in plain words why a combination does not work and name the
  legal basis at the end.

### Upgrade

Deploy the new ZIP. No need to disable and enable the module: only texts and the
setup page changed. Profile names and invoice notes the association already has
stay as they are.

## [0.2.3-beta] - 2026-09-16

Tax profiles for Austrian associations, part 1 of 5 (issue #3).

### Added

- Spheres of an Austrian association (idealistic sphere, asset management,
  indispensable and dispensable auxiliary business, small association festival,
  business harmful to tax privileges) and VAT treatments (not subject to VAT
  without consideration or as Liebhaberei, exempt as small business or sports
  association, 10 %, 13 %, 20 %), each with its legal basis.
- Tax profiles: sphere, treatment, rate and invoice note. Nine suggested
  profiles arrive on activation; the sports exemption and 10 % without
  Liebhaberei start inactive. Enabling again never replaces a profile the
  association changed.
- Setup tab *Tax profiles* to add, edit and switch profiles on and off. The rate
  follows from the treatment. Combinations the law excludes are refused: 10 % or
  the sports exemption in a business harmful to tax privileges, an exemption
  without invoice note.
- REST API `GET /vereine/taxprofiles`.
- Legal sources for spheres, rates and exemptions in `docs/LEGAL-SOURCES.md`,
  read in the law itself.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that creates the tax profile table and the suggested profiles. Data stays.

## [0.2.2-beta] - 2026-09-16

The member card and the module work together.

### Fixed

- A third party created with *Create third party* on Dolibarr's member card, or
  chosen in *Linked third party* there, now gets the member category, customer
  flag and customer type at once, and the module's log records it. Dolibarr
  writes that link without a trigger, so until now only the reconciliation page
  brought such a third party in line. Removing the link takes the member
  categories away from the third party left without member.

### Added

- Tab *Association* on the member card: the linked third party with its
  categories, customer flag and customer type, what does not fit the member,
  open invoices, guardians and the module's log, with *Bring in line with the
  member*. Without a third party it points to the reconciliation page.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that registers the new tab and the member card hook. Data and settings stay.

## [0.2.1-beta] - 2026-09-16

The reconciliation page is quicker to work through.

### Added

- *Select all* in the header of every section with a bulk step. Rows whose member
  has a suggested third party stay unticked.
- A click on a row, or on its *Actions* button, opens a dialog with the steps for
  that row: create, link or bring in line (always through the preview), edit the
  member, edit the third party, add a guardian. Nothing happens until a step is
  chosen, and only steps the user has the rights for are offered. Editing a
  member returns to the reconciliation after saving; Dolibarr's third party form
  ends on the third party card.
- Menu entry *Members > Association > Third party settings* for administrators:
  the same setup page as in the module list.

### Changed

- The reconciliation names the column *Third party* as the rest of the page does.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that adds the new menu entry. Data and settings stay.

## [0.2.0-beta] - 2026-09-16

Members and third parties work together.

### Added

- A validated member gets a third party automatically when the new setting is
  on. Dolibarr's own `create_from_member` creates it; when an existing third
  party could already be the member's (same e-mail, or same name and postcode),
  nothing is created and the reconciliation page suggests it instead.
- Third parties of members follow the member status: category "Member" while
  active, "Former member" after resignation or exclusion, optionally a
  sub-category per member type. The customer flag is set; the customer type is
  filled in only when it is empty and reported otherwise, because the dunning
  module charges fees by customer type.
- Reconciliation page *Members > Association > Members and third parties*:
  members without third party (with suggestions to link), third parties not in
  line, differing e-mail or address, third parties in the member category
  without active membership, minors without guardian, several third parties
  with a member's e-mail. Every bulk change shows a preview and runs only after
  confirmation.
- Tab *Membership* on the third party card: member, type, status, member since,
  paid until, categories, open invoices, guardians and the module's log.
- Guardians of minor members are contacts of the member's third party in the new
  contact category "Guardian".
- Setup tab *Members and third parties* and the right *Link members and third
  parties and bring them in line*.
- The module's log (`llx_vereine_log`), only ever appended to.
- The overview reports open points between members and third parties.

### Changed

- The module now requires Dolibarr's Third parties and Categories modules; they
  are enabled together with Vereine.
- The runtime tests upgrade an installation of the previous release to the new
  package in Dolibarr 22, 23 and 24.

### Upgrade

Deploy the new ZIP, then disable and enable the module once in the module list:
that creates the log table, the new right and the categories. Association data
stays. Existing members are linked on the reconciliation page.

## [0.1.0-beta] - 2026-09-16

First pre-release: the foundation every later version builds on.

### Added

- Country profile Austria, and Germany as a preview until version 1.1. A new
  installation starts with the profile of the company's country.
- Setup page for the association data Dolibarr does not know: ZVR number or VR
  register number with register court, association authority, founding date,
  non-profit status and purpose. Input is checked; a changed country profile
  clears a register number that no longer fits.
- Overview under *Members > Association* with the association's data and checks
  for missing company data, a country mismatch, a missing register number and a
  disabled REST API.
- REST API endpoints `GET /vereine/organization` and `GET /vereine/status`,
  answering only users with the new right *Read the association overview and
  its data*.
- English and German translations.
- Local checks and GitHub workflows: PHP 7.4 to 8.4, Dolibarr's coding
  standard, the Dolibarr 22/23/24 API contract, a reproducible package, and
  runtime tests that deploy the package through *Deploy an external module*
  into real Dolibarr 22, 23 and 24 installations.
- Release tooling: packages are built and published locally and re-verified by
  GitHub against the tagged commit.

[Unreleased]: https://github.com/Tabsi1998/dolibarr-vereine/compare/v0.4.0-beta...HEAD
[0.4.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.4.0-beta
[0.3.11-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.11-beta
[0.3.10-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.10-beta
[0.3.9-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.9-beta
[0.3.8-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.8-beta
[0.3.7-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.7-beta
[0.3.6-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.6-beta
[0.3.5-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.5-beta
[0.3.4-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.4-beta
[0.3.3-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.3-beta
[0.3.2-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.2-beta
[0.3.1-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.1-beta
[0.3.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.0-beta
[0.2.8-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.8-beta
[0.2.7-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.7-beta
[0.2.6-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.6-beta
[0.2.5-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.5-beta
[0.2.4-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.4-beta
[0.2.3-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.3-beta
[0.2.2-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.2-beta
[0.2.1-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.1-beta
[0.2.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.0-beta
[0.1.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.1.0-beta
