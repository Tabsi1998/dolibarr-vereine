# Compatibility

## Supported versions

| Dolibarr | Status | Checked by |
| --- | --- | --- |
| 24.x | Supported | API contract against branch 24.0, runtime tests on 24.0.1 |
| 23.x | Supported | API contract against branch 23.0, runtime tests on 23.0.4 |
| 22.x | Supported | API contract against branch 22.0, runtime tests on 22.0.5 |
| 21.x and older | Not supported | The descriptor refuses activation |

Dolibarr publishes security fixes for the last two major versions only
(currently 23 and 24). The module keeps running on 22, but installations should
move on.

PHP 7.4 is the minimum syntax target; every file is linted on 7.4, 8.1, 8.2,
8.3 and 8.4, and the unit tests run on each of them.

## Keeping the versions in step

The supported range appears in several places that the release check compares:

- `need_dolibarr_version` and `phpmin` in `core/modules/modVereine.class.php`
- `DOLIBARR_VERSIONS`, `PHP_VERSIONS` and `RUNTIME_IMAGES` in `scripts/local_check.py`
- `SUPPORTED` in `scripts/check_dolibarr_api.py`
- the matrices in `.github/workflows/ci.yml`
- the tables in `README.md`, `README-de.md` and this file

Raising the minimum changes all of them in one pull request.

## Dolibarr details the module relies on

- The API entry point dispatches `/vereine/...` to a class named `Vereine` in
  `class/api_vereine.class.php`. The alternative name `VereineApi` is only
  dispatched from Dolibarr 24 on (Dolibarr #37282).
- *Deploy an external module* accepts only file names ending in `-x.y.z.zip`,
  so a beta is packaged as `module_vereine-0.1.0.zip`.
- The German module description is read from `README-de.md`
  (`README-<lang>.md`, then `README-<language>.md`, then `README.md`).
- `SOCIETE_FISCAL_MONTH_START` from the company settings is the first month of
  the fiscal year.

`scripts/check_dolibarr_api.py` checks each of these in the Dolibarr source.
