# CLAUDE.md

Notes for anyone (and any Claude Code session) changing dolibarr-vereine, the Dolibarr module for associations
under Austrian law. Project knowledge - status, decisions, history - is kept outside this repository; a local,
untracked `CLAUDE.local.md` may point to it.

## Language

Everything people read is German: UI texts (`langs/de_DE`; `langs/en_US` is an exact copy made by
`python scripts/sync_langs.py`), PDFs, README, `docs/`, `CHANGELOG.md`, release notes, issues and pull requests.
Code names and comments stay English (Dolibarr's coding standard, no umlauts in comments).

## Work

- An issue per change, a branch per issue (`feat/<n>-<topic>`, `fix/...`, `docs/...`), never a push to `main`.
  The pull request says `Closes #n` (a German "Schließt" closes nothing). The owner merges.
- A pull request that changes the package either lists its changes under `## [Unreleased]` in `CHANGELOG.md`
  or carries the release itself: version in `core/modules/modVereine.class.php` and `docs/API.md`
  (`module_version`), a dated changelog section with its link. After the merge, on `main`:
  `python scripts/release.py --check`, then `python scripts/release.py` (`docs/RELEASES.md`).

## Checks (local first, GitHub second)

```bash
python scripts/local_check.py                 # everything but extra (10-20 minutes)
python scripts/local_check.py --all           # plus deprecations, ShellCheck, OSV
python scripts/local_check.py --only php,codestyle
python scripts/local_check.py --only runtime --keep-services
```

Groups: repository (Gitleaks, CRLF, whitespace), php (lint and `tests/run.php` on PHP 7.4-8.4), codestyle
(Dolibarr's PHP_CodeSniffer ruleset), dolibarr (`scripts/check_dolibarr_api.py`: what the module uses exists in
Dolibarr 22, 23, 24), package (reproducible ZIP), release (version, changelog, support matrix), runtime (real
Dolibarr 22/23/24 in Docker, `tests/runtime/scenarios.py`). Only one runtime run at a time: the containers
`vereine-rt-*` are shared. Needs Docker, Git, gitleaks and Python 3.10+.

## Rules the checks enforce

- Every form posts Dolibarr's token; no two string literals joined with `.` across lines.
- A new page goes into `pages` in `scripts/check-module.sh`; a new API method calls `$this->checkAccess()` and is
  described in `docs/openapi.json`; a new Dolibarr function or core language key goes into `CONTRACTS` or
  `LANG_KEYS` of `scripts/check_dolibarr_api.py`.
- Every language key the code uses exists; keys built at runtime (`'Prefix_'.$x`) are listed in the prefix registry
  of `tests/run.php`; every right has a `Permission492100NN` label.
- Schema: new columns only through `sql/update_<version>.sql`; new tables as `llx_*.sql` plus `.key.sql`.
- Logic without Dolibarr lives in `class/*rules.class.php` with unit tests in `tests/run.php`.
- A new right or menu entry updates the lists in the runtime scenario `enable`.
- Legal amounts and deadlines name their source in `docs/LEGAL-SOURCES.md`.
- Edit files with Python or an editor, never with PowerShell `Get-Content`/`Set-Content` (they break UTF-8).
