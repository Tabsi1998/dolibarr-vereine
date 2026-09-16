# Changelog

All notable changes to the Vereine module. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/), with `-beta` marking pre-releases.
The section of a version is the text of its GitHub release.

## [Unreleased]

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

[Unreleased]: https://github.com/Tabsi1998/dolibarr-vereine/compare/v0.2.0-beta...HEAD
[0.2.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.0-beta
[0.1.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.1.0-beta
