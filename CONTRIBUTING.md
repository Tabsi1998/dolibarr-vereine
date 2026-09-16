# Contributing

Contributions are welcome - code, translations, legal corrections and bug
reports. Never use real member, donor or bank data, credentials or database
dumps in issues, tests or screenshots.

## How work is organised

- Every change starts with an issue, labelled with its type (`bug`,
  `enhancement`, `documentation`, `ci`, `release`, `security`), its area
  (`bereich: ...`) and, where it applies, its country (`land: AT`, `land: DE`),
  and assigned to a milestone.
- One branch per issue: `feat/<issue>-<topic>`, `fix/<issue>-<topic>`,
  `docs/<issue>-<topic>`, `ci/<issue>-<topic>`.
- Pull requests close their issues (`Closes #12`) and nothing is pushed to
  `main` directly. Commits follow the conventional style: `feat:`, `fix:`,
  `docs:`, `ci:`, `chore:`, `release:`.

## Checks

Run the local checks before opening a pull request; GitHub runs a subset again
as a second confirmation.

```bash
python scripts/local_check.py            # everything GitHub runs plus the runtime tests
python scripts/local_check.py --all      # plus the extra gates
python scripts/local_check.py --list     # the steps without running them
```

They need Docker, Git and Python 3.10 or newer; see `CLAUDE.md` for details.

## Rules for code

- PHP 7.4 syntax is the minimum; Dolibarr's coding standard applies (tabs,
  English comments, doc blocks).
- Never change Dolibarr's files; use hooks, triggers and the module's own
  classes.
- Read input with `GETPOST()`, escape output, check rights on every page and
  endpoint, put the CSRF token in every form.
- Logic that can run without Dolibarr goes into plain PHP classes with tests in
  `tests/run.php`.
- German and English texts change together; English is always complete.
- A legal amount or deadline names its source in `docs/LEGAL-SOURCES.md`.
- User-visible changes get a `CHANGELOG.md` entry under `Unreleased`.

## Licence

By contributing you agree that your contribution is published under the GNU
General Public License v3.0 or later.
