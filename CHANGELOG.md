# Changelog

All notable changes to the Vereine module. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/), with `-beta` marking pre-releases.
The section of a version is the text of its GitHub release.

## [Unreleased]

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

[Unreleased]: https://github.com/Tabsi1998/dolibarr-vereine/compare/v0.2.4-beta...HEAD
[0.2.4-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.4-beta
[0.2.3-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.3-beta
[0.2.2-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.2-beta
[0.2.1-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.1-beta
[0.2.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.0-beta
[0.1.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.1.0-beta
