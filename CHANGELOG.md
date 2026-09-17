# Changelog

All notable changes to the Vereine module. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/), with `-beta` marking pre-releases.
The section of a version is the text of its GitHub release.

## [Unreleased]

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

[Unreleased]: https://github.com/Tabsi1998/dolibarr-vereine/compare/v0.2.8-beta...HEAD
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
