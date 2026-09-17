# CLAUDE.md

Notes for Claude Code sessions on dolibarr-vereine, the Dolibarr module for
associations under Austrian and German law. Answer the owner (Tabsi1998,
Fabian) in German. Code, comments, commits and repository documents are in
English (DoliStore requires English); `README-de.md` and the GitHub issues are
German.

## How work runs

- Every piece of work has an issue with type label (`bug`, `enhancement`,
  `documentation`, `ci`, `release`, `security`), area label (`bereich: ...`),
  country label where it applies (`land: AT`, `land: DE`) and a milestone.
- One branch per issue (`feat/<n>-<topic>`, `fix/...`, `docs/...`, `ci/...`),
  never a direct push to `main`. The pull request closes its issues
  (`Closes #n`). Commits: `feat:`, `fix:`, `docs:`, `ci:`, `chore:`,
  `release:`, ending with the Co-Authored-By line.
- Flow: commit, draft PR, `python scripts/local_check.py`, its result as a PR
  comment, `gh pr ready` (wait about 10 s after the last push). Fabian merges;
  everything else - issues, checks, releases - is done here.
- After Fabian merged a pull request that changes the package: switch to
  `main`, pull, run `python scripts/local_check.py`, then
  `python scripts/release.py --check` and `python scripts/release.py`. Report
  the release link so he can install the ZIP. See `docs/RELEASES.md`.
- A pull request that changes the package raises the version and moves its
  changelog entries into a dated section; the release step of the local check
  refuses otherwise.

## Checks: local first, GitHub second

```bash
python scripts/local_check.py                 # everything but extra (about 10 minutes)
python scripts/local_check.py --all           # plus deprecations, ShellCheck, OSV
python scripts/local_check.py --only php,package
python scripts/local_check.py --only runtime --keep-services   # leave the Dolibarr containers up
python scripts/local_check.py --list
```

Results: `.local-testing/local-check.json`, logs in `.local-testing/logs/`.

| Group | Runs |
| --- | --- |
| repository | shell scripts parse, no CRLF stored, whitespace, Gitleaks over history and new files |
| php | `scripts/check-module.sh` in `php:7.4-cli` to `php:8.4-cli`: lint, `tests/run.php`, security contracts |
| codestyle | `scripts/check-codestyle.sh`: PHP_CodeSniffer 4.0.4 with Dolibarr 24.0.1's ruleset, severity 5, pinned by SHA-256 |
| dolibarr | `scripts/check_dolibarr_api.py`: every function, class and core language key the module uses exists in branches 22.0, 23.0, 24.0 |
| package | `scripts/build_release.py` from the Git working copy (tracked files only, output inside the repository as on GitHub) and from the snapshot: byte-identical, checked file by file |
| release | `scripts/release.py` metadata: version scheme, changelog section, support matrix in descriptor, scripts, ci.yml and READMEs; version raised when the package changed since the last release |
| runtime | per Dolibarr 22.0.5, 23.0.4, 24.0.1 (ports 18042-18044): upload the ZIP through *Deploy an external module*, enable in the module list, pages, setup with bad and good input, rights, REST API, disable and enable again, PHP messages from module code (ratchet) |
| extra | deprecations on PHP 8.4, ShellCheck, OSV |

The php, codestyle, package and runtime steps work on `.local-testing/snapshot`,
a copy of what Git would commit with LF endings. Runtime containers use tmpfs
for database, documents and `custom/`, so each run starts from an empty
Dolibarr; the image `local-ci/dolibarr:24.0.1` is built from Dolibarr's docker
repository on first use and shared with dolibarr-mahnwesen.

Tools: Docker Desktop (running), Git for Windows, gitleaks, Python 3.11 as
`python`. A missing tool skips its steps with a hint; a release refuses skipped
steps.

## Keep in step

- The supported range lives in `modVereine.class.php` (`phpmin`,
  `need_dolibarr_version`), `scripts/local_check.py` (`PHP_VERSIONS`,
  `DOLIBARR_VERSIONS`, `RUNTIME_IMAGES`), `scripts/check_dolibarr_api.py`
  (`SUPPORTED`), `.github/workflows/ci.yml` and the README tables. The release
  step compares them.
- A new Dolibarr function, class or core language key in the code needs an
  entry in `CONTRACTS` or `LANG_KEYS` of `scripts/check_dolibarr_api.py`.
- A new page (`*.php` in the root or `admin/`) must be added to `pages` in
  `scripts/check-module.sh`, which enforces the access checks.
- A new API method must call `$this->checkAccess()`; the contract counts them.
  It also needs its path, parameters and answer in `docs/openapi.json`:
  `tests/run.php` compares the paths with the `@url` lines, and the runtime
  checks validate every answer of `vereine/...` against it (`Stack.api`).
- `build_release.py` packs everything except `EXCLUDED_TOP`. A new developer-only
  file or folder at the top level needs an entry there.
- German and English language files change together; `tests/run.php` checks
  that every key used by the code exists in English and every language has
  every key.
- Legal amounts and deadlines name their source in `docs/LEGAL-SOURCES.md`.

## Shared core

`scripts/local_check.py` has three parts: header (paths, groups, matrices,
runtime images), the shared core (identical in OmniFM, IT-Tabelander,
THE-LION_SQUAD-eSPORT-Webseite and dolibarr-mahnwesen - port fixes there), and
the dolibarr-vereine steps with `plan()`. A step is `(context) -> str`; it
raises `StepFailed` or `StepSkipped`. Gates beyond GitHub's go through
`ratchet()`.

## Facts found while building 0.1

- The API class must be named `Vereine`: Dolibarr 22 and 23 dispatch
  `/vereine/...` only to a class named after the endpoint (`VereineApi` works
  from 24 on, Dolibarr #37282).
- *Deploy an external module* accepts only `...-x.y.z.zip`; betas are packaged
  without `-beta`.
- Dolibarr shows `README-<lang>.md`, `README-<language>.md`, then `README.md`
  as module description - hence `README-de.md` in lower case.
- Disabling a module deletes its rights definitions but not the rights granted
  to users; enabling it again restores them.
- The official Dolibarr images ship `custom/` read-only; web deployment needs a
  writable `custom/`.

## Facts found while building 0.2

- One member per third party in Dolibarr (`setThirdPartyId` unlinks others).
- `Societe::update()` does not sync back to the member by default
  (`$nosyncmember = 1`), so changing a member's third party cannot loop through
  `MEMBER_MODIFY`; the service still guards against re-entry.
- `Adherent::validate()` sets `datevalid` only after `MEMBER_VALIDATE` ran.
- Third party contact roles need the hidden, unstable option
  `MAIN_SUPPORT_SHARED_CONTACT_BETWEEN_THIRDPARTIES`; guardians use a contact
  category instead.
- The runtime `upgrade` scenario deploys the newest earlier release (built from
  its tag), enables it, stores data, deploys the current package, disables and
  enables, checks, and then resets with `fixtures.php reset`.
- Menu entries are written at activation: a new entry reaches an installation
  only after disabling and enabling the module once.
- The member edit form and the new contact form keep `backtopage`; the third
  party edit form does not and always ends on the third party card.
- Page JavaScript goes through `llxHeader(..., array('/vereine/js/...'))`, which
  adds Dolibarr's CSP nonce; an inline `<script>` would not get it.
- *Create third party* and *Linked third party* on the member card change
  `fk_soc` without any trigger; the hook in `class/actions_vereine.class.php`
  follows them (doActions notes, addMoreActionsButtons applies).
- Dolibarr 24 refuses GET actions without token (HTTP 403): runtime tests follow
  the links a page offers (`action_link`) instead of building URLs.
- `Adherent::fetch` reads `fk_soc` in 22 and `fk_soc as socid` in 23 and 24;
  both fill `$member->fk_soc`.
- Dolibarr passes every translation through `sprintf()`: a lone `%` in a
  language file stops the page with a ValueError. Write `%%`; `tests/run.php`
  checks it.
- Pages must not use `$form` for their own data: Dolibarr's page header sets the
  global `$form` to a `Form` object.
- `Translate::trans()` passes at most four parameters to `sprintf()` (the fifth is
  `$maxsize`): a translation with five placeholders is a fatal error in the middle
  of the page. `tests/run.php` checks it.
- A fatal error in Dolibarr's own code called from the module still answers HTTP
  200: `page_ok` requires `</html>`, and the php-messages step counts uncaught
  errors whose stack trace passes through `custom/vereine`.
- `Product::create` stores extra fields before `PRODUCT_CREATE`; a product's VAT
  changes through `updatePrice()` so the gross price follows. Dolibarr does not
  copy product extra fields to invoice lines; `LINEBILL_INSERT` and
  `LINEBILL_SUPPLIER_CREATE` run after the line's extra fields are stored.
- Never edit repository files with PowerShell `Get-Content`/`Set-Content`: they
  read UTF-8 as ANSI and write mangled umlauts. Use Python or the edit tools.
- The invoice template `sponge` calls `beforePDFCreation` with the invoice and
  its output language, then prints `note_public`; `afterPDFCreation` gets the
  template as object and the invoice in `$parameters['object']`. The runtime
  test reads the PDF by inflating its streams with `zlib`: text with the core
  font appears as `(...)` strings.
- Module boxes live in `core/boxes/box_<name>.php` with class `box_<name>`,
  registered in the descriptor's `$this->boxes` and inserted on activation;
  `$this->hidden` decides per user whether the box shows.
- Apply scripts must be idempotent: an interrupted run left half a feature in
  place once. Check whether a change is already there before applying it.
- `Paiement::create()` needs the class `Facture` loaded and, when
  `multicurrency_amounts` is set, a `multicurrency_code` per invoice; the
  runtime fixture leaves the foreign currency out.
- A fixture that fails prints its PHP error in "What failed, in full" at the end
  of the local check log, not in the step line.
