# Releases

## Versions

| Kind | Tag and module version | GitHub | Package |
| --- | --- | --- | --- |
| Beta | `v0.1.0-beta` | Pre-release | `module_vereine-0.1.0.zip` |
| Bugfix of a beta | `v0.1.1-beta` | Pre-release | `module_vereine-0.1.1.zip` |
| Release | `v1.0.0` | Release, marked latest | `module_vereine-1.0.0.zip` |
| Bugfix release | `v1.0.1` | Release, marked latest | `module_vereine-1.0.1.zip` |
| Beta of a later release | `v1.1.0-beta` | Pre-release | `module_vereine-1.1.0.zip` |

Versions below 1.0.0 are always betas. The package name carries no `-beta`:
Dolibarr's *Deploy an external module* only accepts names ending in
`-x.y.z.zip`. The beta marker is in the tag, the release title and the module
version Dolibarr shows in its module list.

Each minor version has a milestone. Bugfix versions go into the milestone of
the version they fix.

## One release per merged pull request

Every pull request that changes the installable package is released as soon as
it is merged, so the newest state can always be installed in Dolibarr from the
releases page. Pull requests that only touch what the package leaves out -
`scripts/`, `tests/`, `.github/`, `CLAUDE.md`, `CONTRIBUTING.md` - are not
released.

Such a pull request therefore carries its version itself:

1. Raise `$this->version` in `core/modules/modVereine.class.php`: the next
   patch version for fixes and small additions within a milestone
   (`0.1.0-beta` to `0.1.1-beta`), the next minor version for the first pull
   request of a new milestone (`0.1.1-beta` to `0.2.0-beta`).
2. Move its `Unreleased` entries of `CHANGELOG.md` into a section
   `## [x.y.z(-beta)] - YYYY-MM-DD` and add the link at the bottom. That section
   becomes the text of the GitHub release.
3. `python scripts/local_check.py` must pass. Its release step fails when the
   package changed since the newest release but the version did not.

## Publishing

Right after the merge, on an up-to-date `main`:

```bash
python scripts/local_check.py             # the full check for exactly this commit
python scripts/release.py --check         # everything except tag and publication
python scripts/release.py                 # tag, GitHub release, package upload
```

`release.py` refuses unless:

- `main` is checked out, clean and equal to `origin/main`;
- the module version is `x.y.z` or `x.y.z-beta`, and below 1.0.0 it is a beta;
- `CHANGELOG.md` has a non-empty section for it, dated today or earlier;
- neither the tag nor a release of that name exists yet, and the version is
  higher than every released one;
- `.local-testing/local-check.json` reports a complete, green local check for
  this commit, runtime tests included.

It then builds the package from the committed files (`git archive`), verifies
it, creates the annotated tag `v<version>`, pushes it, creates the GitHub
release - a pre-release for betas, otherwise the latest release - with the
changelog section as its notes, uploads the ZIP and its `.sha256`, downloads
both again and compares the checksum.

## GitHub's second confirmation

`.github/workflows/release-verify.yml` runs when a release is published. It
checks out the tag, builds the package again and fails when the SHA-256 differs
from the uploaded asset, or when the pre-release flag does not match the
version. The package build is reproducible byte for byte, so a difference means
the published ZIP was not built from that tag.
