#!/usr/bin/env python3
"""Everything the module uses from Dolibarr exists in each supported version.

The module calls Dolibarr functions, extends its classes, relies on how the API
entry point finds module classes and on core language keys. This reads those
places in the source of a Dolibarr maintenance branch and fails when one is
gone or changed - before a user's installation finds out. The runtime checks in
scripts/local_check.py prove the same in a running Dolibarr; this check is the
fast one that GitHub runs too.

Usage:
    python scripts/check_dolibarr_api.py 22.0 23.0 24.0
    python scripts/check_dolibarr_api.py --cache .local-testing/dolibarr-src 24.0

Standard library only.
"""

from __future__ import annotations

import argparse
import re
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

SUPPORTED = ("22.0", "23.0", "24.0")
RAW = "https://raw.githubusercontent.com/Dolibarr/dolibarr/{ref}/{path}"

# (file in the Dolibarr repository, text that must appear, why the module needs it)
CONTRACTS = (
    ("htdocs/core/modules/DolibarrModules.class.php", "class DolibarrModules", "descriptor base class"),
    ("htdocs/core/modules/DolibarrModules.class.php", "function _init(", "module activation"),
    ("htdocs/core/modules/DolibarrModules.class.php", "function _remove(", "module deactivation"),
    ("htdocs/core/modules/DolibarrModules.class.php", "\"Module\".$this->name.\"Name\"", "module name from ModuleVereineName"),
    ("htdocs/core/modules/DolibarrModules.class.php", "'/README-'.$langs->defaultlang.'.md'", "README-de.md as German module description"),
    ("htdocs/core/modules/modAdherent.class.php", "class modAdherent", "the Members module the descriptor depends on"),
    ("htdocs/core/lib/admin.lib.php", "function dolibarr_set_const(", "setup page stores association data"),
    ("htdocs/core/lib/admin.lib.php", "function activateModule(", "runtime fixtures"),
    ("htdocs/core/lib/functions.lib.php", "function getDolGlobalString(", "reading settings"),
    ("htdocs/core/lib/functions.lib.php", "function isModEnabled(", "module state"),
    ("htdocs/core/lib/functions.lib.php", "function GETPOST(", "request input"),
    ("htdocs/core/lib/functions.lib.php", "function GETPOSTINT(", "request input"),
    ("htdocs/core/lib/functions.lib.php", "function newToken(", "CSRF token in forms"),
    ("htdocs/core/lib/security.lib.php", "function accessforbidden(", "refusing access"),
    ("htdocs/core/lib/functions.lib.php", "function setEventMessages(", "messages after saving"),
    ("htdocs/core/lib/functions.lib.php", "function dol_escape_htmltag(", "output escaping"),
    ("htdocs/core/lib/functions.lib.php", "function dol_buildpath(", "module URLs"),
    ("htdocs/core/lib/functions.lib.php", "function dol_include_once(", "including module files"),
    ("htdocs/core/lib/functions.lib.php", "function dol_mktime(", "founding date"),
    ("htdocs/core/lib/functions.lib.php", "function dol_print_date(", "founding date"),
    ("htdocs/core/lib/functions.lib.php", "function dol_now(", "founding date check"),
    ("htdocs/core/lib/functions.lib.php", "function dol_nl2br(", "address and purpose"),
    ("htdocs/core/lib/functions.lib.php", "function img_picto(", "menu picto"),
    ("htdocs/core/lib/functions.lib.php", "strpos($pictowithouttext, 'fa-') === 0", "fa-landmark picto"),
    ("htdocs/core/lib/functions.lib.php", "function dol_get_fiche_head(", "setup tabs"),
    ("htdocs/core/lib/functions.lib.php", "function dol_get_fiche_end(", "setup tabs"),
    ("htdocs/core/lib/functions.lib.php", "function load_fiche_titre(", "page titles"),
    ("htdocs/core/lib/functions.lib.php", "function complete_head_from_modules(", "setup tabs"),
    ("htdocs/core/lib/functions.lib.php", "function dolGetBadge(", "check status badges"),
    ("htdocs/core/lib/functions.lib.php", "function dolGetButtonTitle(", "edit button on the overview"),
    ("htdocs/core/lib/functions.lib.php", "function info_admin(", "hints on setup and about"),
    ("htdocs/core/lib/functions.lib.php", "function yn(", "non-profit yes/no"),
    ("htdocs/core/class/html.form.class.php", "public static function selectarray(", "country profile select"),
    ("htdocs/core/class/html.form.class.php", "public function selectDate(", "founding date input"),
    ("htdocs/user/class/user.class.php", "public function hasRight(", "right checks"),
    ("htdocs/societe/class/societe.class.php", "public function setMysoc(", "company data in $mysoc"),
    ("htdocs/admin/company.php", "SOCIETE_FISCAL_MONTH_START", "fiscal year start from the company settings"),
    ("htdocs/core/menus/standard/eldy.lib.php", "'idsel' => 'members'", "left menu under Members"),
    ("htdocs/api/class/api.class.php", "class DolibarrApi", "API base class"),
    ("htdocs/api/class/api_access.class.php", "public static $user", "API user for right checks"),
    ("htdocs/api/index.php", "$classname = ucwords($moduleobject);", "dispatch of /vereine to class Vereine"),
    ("htdocs/api/index.php", "'/class/api_'.$classfile.'.class.php'", "API file name api_vereine.class.php"),
    ("htdocs/admin/modules.php", "'/^(module[a-zA-Z0-9]*_|theme_|).*\\-([0-9][0-9\\.]*)(\\s\\(\\d+\\)\\s)?\\.zip$/i'",
     "ZIP name rule of Deploy an external module"),
    ("htdocs/admin/modules.php", "name=\"fileinstall\"", "upload field of Deploy an external module"),
)

LANG_KEYS = {
    # Loaded by every page through main.inc.php.
    "htdocs/langs/en_US/main.lang": ("About", "Parameter", "Value", "Save", "Error", "Name", "Status",
                                     "January", "February", "March", "April", "May", "June", "July",
                                     "August", "September", "October", "November", "December"),
    # Loaded by admin/setup.php and admin/about.php.
    "htdocs/langs/en_US/admin.lang": ("Version", "Publisher", "BackToModuleList", "SetupSaved"),
    # Loaded by vereineindex.php.
    "htdocs/langs/en_US/companies.lang": ("Address",),
}


def fetch(ref: str, path: str, cache: Path | None) -> str:
    target = cache / ref / path if cache else None
    if target and target.is_file() and time.time() - target.stat().st_mtime < 6 * 3600:
        return target.read_text(encoding="utf-8", errors="replace")
    url = RAW.format(ref=ref, path=path)
    last = None
    for attempt in range(3):
        try:
            with urllib.request.urlopen(url, timeout=60) as response:
                text = response.read().decode("utf-8", errors="replace")
            break
        except (urllib.error.URLError, TimeoutError) as error:
            last = error
            time.sleep(2 * (attempt + 1))
    else:
        raise SystemExit(f"cannot download {url}: {last}")
    if target:
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text, encoding="utf-8")
    return text


def check(ref: str, cache: Path | None) -> list[str]:
    problems = []
    for path, needle, why in CONTRACTS:
        if needle not in fetch(ref, path, cache):
            problems.append(f"{path} lacks {needle!r} ({why})")
    for path, keys in LANG_KEYS.items():
        text = fetch(ref, path, cache)
        present = set(re.findall(r"^([A-Za-z0-9_]+)\s*=", text, re.MULTILINE))
        problems += [f"{path} lacks the language key {key}" for key in keys if key not in present]
    return problems


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("versions", nargs="*", default=list(SUPPORTED), help="Dolibarr branches, e.g. 24.0")
    parser.add_argument("--cache", type=Path, help="folder that keeps downloaded sources for six hours")
    arguments = parser.parse_args(argv)
    failed = False
    for version in arguments.versions:
        if version not in SUPPORTED:
            print(f"Dolibarr {version} is not a supported version ({', '.join(SUPPORTED)})", file=sys.stderr)
            return 2
        problems = check(version, arguments.cache)
        if problems:
            failed = True
            print(f"Dolibarr {version}: {len(problems)} problems", file=sys.stderr)
            for problem in problems:
                print(f"  {problem}", file=sys.stderr)
        else:
            count = len(CONTRACTS) + sum(len(keys) for keys in LANG_KEYS.values())
            print(f"Dolibarr {version} source/API compatibility check: OK ({count} contracts)")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
