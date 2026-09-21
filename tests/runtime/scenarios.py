"""What the runtime checks prove in a running Dolibarr.

scripts/local_check.py starts one Dolibarr per supported version with MariaDB,
runs fixtures.php base, and then calls the scenarios below in order. Each
scenario raises CheckFailed with what went wrong and returns a one-line result.
They use only what an administrator, a user and a website use: the module
upload, the module list, the pages, the REST API - and the database to verify.
"""

from __future__ import annotations

import base64
import datetime
import hashlib
import html
import json
import re
import secrets
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable

from dolibarr_http import Browser, Mailpit, Page, token_of
from openapi import OpenApi

MODULE_DIR = "/var/www/html/custom/vereine"
OPENAPI = Path(__file__).resolve().parents[2] / "docs" / "openapi.json"
PHP_PROBLEM = re.compile(r"PHP (Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable fatal error):"
                         r"\s*(.+?) in (/var/www/html/custom/vereine/\S+) on line \d+")
# An uncaught error thrown in Dolibarr's own code while module code called it: the module is in the stack trace.
PHP_THROWN = re.compile(r"PHP (Fatal error|Recoverable fatal error):\s*(Uncaught [^\\]+?) in (/var/www/html/\S+?):\d+\\nStack trace:(.*)")
MODULE_FRAME = re.compile(r"/var/www/html/custom/vereine/([^\s(]+)\(\d+\)")


class CheckFailed(Exception):
    """A scenario found a problem. The message says what."""


def expect(condition: bool, message: str) -> None:
    if not condition:
        raise CheckFailed(message)


@dataclass
class Stack:
    """One running Dolibarr with its database."""

    version: str
    image: str
    web: str
    db: str
    mail: str
    web_port: int
    mail_port: int
    admin_password: str
    reader_password: str
    nobody_password: str
    reader_key: str
    nobody_key: str
    db_password: str
    package: Path
    run: Callable[..., subprocess.CompletedProcess]
    docker: str
    fixtures: dict = field(default_factory=dict)
    notes: dict = field(default_factory=dict)
    previous_package: Path | None = None
    openapi: OpenApi = field(default_factory=lambda: OpenApi(OPENAPI))

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.web_port}"

    def mailpit(self) -> Mailpit:
        return Mailpit(f"http://127.0.0.1:{self.mail_port}")

    @property
    def module_version(self) -> str:
        return package_version(self.package)

    def browser(self, who: str = "admin") -> Browser:
        passwords = {"admin": self.admin_password, "rtreader": self.reader_password,
                     "rtnobody": self.nobody_password}
        browser = Browser(self.url)
        browser.login(who, passwords[who])
        return browser

    def sql(self, query: str) -> list[list[str]]:
        completed = self.run(self.docker, "exec", "-e", f"MYSQL_PWD={self.db_password}", self.db,
                             "mariadb", "-uroot", "-N", "-B", "--default-character-set=utf8mb4", "dolibarr",
                             "-e", query, check=False, timeout=120)
        if completed.returncode != 0:
            raise CheckFailed(f"SQL failed: {query}\n{completed.stderr.strip()}")
        return [line.split("\t") for line in completed.stdout.splitlines()]

    def value(self, query: str) -> str | None:
        rows = self.sql(query)
        return rows[0][0] if rows and rows[0] else None

    def const(self, name: str) -> str | None:
        return self.value(f"SELECT value FROM llx_const WHERE name = '{name}' AND entity IN (0, 1) ORDER BY entity DESC LIMIT 1")

    def php_fixture(self, stage: str, **extra: str) -> dict:
        arguments = [self.docker, "exec", "-u", "www-data",
                     "--env", f"RT_READER_PASSWORD={self.reader_password}",
                     "--env", f"RT_NOBODY_PASSWORD={self.nobody_password}",
                     "--env", f"RT_READER_KEY={self.reader_key}",
                     "--env", f"RT_NOBODY_KEY={self.nobody_key}"]
        for name, value in extra.items():
            arguments += ["--env", f"{name}={value}"]
        completed = self.run(*arguments, self.web, "php", "/opt/vereine-tests/fixtures.php", stage,
                             check=False, timeout=600)
        if completed.returncode != 0:
            raise CheckFailed(f"fixtures.php {stage} failed on Dolibarr {self.version}:\n"
                              f"{(completed.stdout + completed.stderr)[-1500:]}")
        return parse_fixtures(completed.stdout)

    def shell(self, command: str) -> subprocess.CompletedProcess:
        return self.run(self.docker, "exec", "-u", "www-data", self.web, "sh", "-c", command, check=False, timeout=120)

    def api(self, path: str, key: str | None, check: bool = True, method: str = "GET", data: object = None) -> tuple[int, object]:
        """Call an API path, GET or with a JSON body. Answers of the Vereine API must match docs/openapi.json unless check is off."""
        headers = {"Accept": "application/json", **({"DOLAPIKEY": key} if key else {})}
        body = None
        if data is not None:
            body = json.dumps(data).encode("utf-8")
            headers["Content-Type"] = "application/json"
        request = urllib.request.Request(f"{self.url}/api/index.php/{path.lstrip('/')}", method=method, data=body, headers=headers)
        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                status, raw = response.status, response.read()
        except urllib.error.HTTPError as error:
            status, raw = error.code, error.read()
        try:
            body = json.loads(raw.decode("utf-8") or "null")
        except ValueError:
            body = raw.decode("utf-8", errors="replace")
        if check and path.lstrip("/").startswith("vereine/"):
            problems = self.openapi.check(method, path, status, body)
            expect(not problems, "the answer differs from docs/openapi.json:\n" + "\n".join(problems[:10]))
        return status, body

    def log(self) -> str:
        completed = self.run(self.docker, "logs", self.web, check=False, timeout=120)
        return completed.stdout + completed.stderr


def page_ok(page: Page, what: str) -> Page:
    expect(page.status == 200, f"{what}: HTTP {page.status}")
    # A fatal error after the header still answers 200, but the page stops before its end.
    expect("<html" not in page.text[:2000] or "</html>" in page.text[-2000:], f"{what}: the page stops before its end")
    expect(not page.denied(), f"{what}: access denied")
    problems = page.errors()
    if problems:
        # Show where the first marker stands, so a failure explains itself.
        at = page.text.find(problems[0].split()[0])
        context = re.sub(r"\s+", " ", page.text[max(0, at - 160):at + 160])
        raise CheckFailed(f"{what}: the page shows {', '.join(problems)} - near: {context}")
    return page


def denied(page: Page) -> bool:
    """Dolibarr's accessforbidden() page, whatever the language."""
    if page.denied():
        return True
    # accessforbidden() prints its reason in <div class="error"> and nothing of the page it refused.
    refused_page_parts = ('name="vereinesetup"', "data-check=", "page-admin-about", "data-section=",
                          "data-membership=", "data-association=", 'name="vereinepartnersetup"')
    return '<div class="error">' in page.text and not any(part in page.text for part in refused_page_parts)


def module_link(page: Page, action: str) -> str:
    """The enable or disable link of the module in Dolibarr's module list."""
    for href in re.findall(r'href="([^"]*modules\.php\?[^"]*)"', page.text):
        target = html.unescape(href)
        if f"action={action}&" in target + "&" and "value=modVereine" in target:
            return target
    raise CheckFailed(f"the module list offers no action={action} link for modVereine")


def module_list(browser: Browser) -> Page:
    return page_ok(browser.get("/admin/modules.php?mode=common&search_keyword=vereine"), "module list")


def package_version(package: Path) -> str:
    """The module version inside a package."""
    with zipfile.ZipFile(package) as bundle:
        text = bundle.read("vereine/core/modules/modVereine.class.php").decode("utf-8")
    return re.search(r"\$this->version\s*=\s*'([^']+)'", text).group(1)


def upload(stack: Stack, package: Path) -> list[str]:
    """Deploy an external module, as an administrator does it; the installed files must be the package."""
    browser = stack.browser()
    page = page_ok(browser.get("/admin/modules.php?mode=deploy"), "deploy page")
    form = page.form(name="forminstall")
    # checkforcompliance asks dolibarr.org for a blacklist; the check runs offline.
    fields = [(name, value) for name, value in form.values() if name != "checkforcompliance"]
    result = browser.post_multipart(form.url(), fields, [("fileinstall", package.name, package.read_bytes())])
    expect(result.status == 200, f"uploading {package.name} answered HTTP {result.status}")
    expect(not result.errors(), f"the upload page shows {', '.join(result.errors())}")
    installed = stack.shell(f"cd {MODULE_DIR} && find . -type f | sort")
    expect(installed.returncode == 0, f"the upload did not create {MODULE_DIR}:\n{result.text[-800:]}")
    files = [line[2:] for line in installed.stdout.splitlines() if line.startswith("./")]
    with zipfile.ZipFile(package) as bundle:
        packaged = sorted(info.filename[len("vereine/"):] for info in bundle.infolist() if not info.is_dir())
    expect(files == packaged, f"deployed files differ from {package.name}: "
                              f"missing {sorted(set(packaged) - set(files))[:5]}, extra {sorted(set(files) - set(packaged))[:5]}")
    return files


def switch_module(stack: Stack, action: str) -> None:
    """Enable (set) or disable (reset) the module from Dolibarr's module list."""
    browser = stack.browser()
    page_ok(browser.get(module_link(module_list(browser), action)), f"module list action {action}")


def section_counts(page: Page) -> dict:
    return dict(re.findall(r'data-section="([a-z_]+)" data-count="(\d+)"', page.text))


def action_link(page: Page, action: str) -> str:
    """The link the page offers for an action, as a browser follows it (Dolibarr 24 adds a token)."""
    for href in re.findall(r'href="([^"]*[?&](?:amp;)?action=' + re.escape(action) + r'(?:&[^"]*)?)"', page.text):
        return html.unescape(href)
    raise CheckFailed(f"{page.url} offers no link for action={action}")


def data_status(page: Page, check: str) -> str | None:
    match = re.search(rf'data-check="{re.escape(check)}" data-status="([a-z]+)"', page.text)
    return match.group(1) if match else None


# ------------------------------------------------------------------ scenarios

CATEGORY_CONSTANTS = ("VEREINE_CATEGORY_MEMBER", "VEREINE_CATEGORY_FORMER", "VEREINE_CATEGORY_GUARDIAN")
# Code, sphere, treatment, rate, active - as class/vereinetaxrules.class.php suggests them.
STANDARD_TAX_PROFILES = [
    ["BETRIEB_20", "harmful", "standard20", "20.000", "1"],
    ["HILFSBETRIEB", "essential", "hobby", "0.000", "1"],
    ["HILFSBETRIEB_10", "auxiliary", "reduced10", "10.000", "0"],
    ["KLEINUNTERNEHMER", "harmful", "small_business", "0.000", "1"],
    ["MITGLIEDSBEITRAG", "ideal", "nonbusiness", "0.000", "1"],
    ["SPENDE", "ideal", "nonbusiness", "0.000", "1"],
    ["SPORT", "essential", "sport", "0.000", "0"],
    ["SUBVENTION", "ideal", "nonbusiness", "0.000", "1"],
    ["VEREINSFEST", "festival", "hobby", "0.000", "1"],
]


def upgrade(stack: Stack) -> str:
    """An installation of the previous release takes the new package: its data stays, the new parts arrive."""
    expect(stack.fixtures.get("dolibarr", "").startswith(stack.version.rsplit(".", 1)[0]),
           f"the container runs Dolibarr {stack.fixtures.get('dolibarr')}, expected {stack.version}")
    if stack.previous_package is None:
        return "no earlier release to upgrade from"
    old = package_version(stack.previous_package)
    upload(stack, stack.previous_package)
    switch_module(stack, "set")
    expect(stack.const("MAIN_MODULE_VEREINE") == "1", f"{old} could not be enabled")
    browser = stack.browser()
    form = browser.get("/custom/vereine/admin/setup.php").form(name="vereinesetup")
    page_ok(browser.submit(form, {"VEREINE_REGISTER_NUMBER": "987654321", "VEREINE_AUTHORITY": "BH Innsbruck"}),
            f"setup of {old}")
    expect(stack.const("VEREINE_REGISTER_NUMBER") == "987654321", f"{old} did not store the ZVR number")
    # A member type whose fee was prorated with the checkbox of 0.3.4 and 0.3.5.
    old_checkbox = bool(stack.sql("SHOW COLUMNS FROM llx_adherent_type_extrafields LIKE 'vereine_fee_prorated'"))
    if old_checkbox:
        stack.sql("INSERT INTO llx_adherent_type (entity, statut, libelle, morphy, duration, subscription, amount) "
                  "VALUES (1, 1, 'RT Upgrade', '', '1y', '1', 12)")
        upgrade_type = int(stack.value("SELECT rowid FROM llx_adherent_type WHERE libelle = 'RT Upgrade'"))
        stack.sql(f"INSERT INTO llx_adherent_type_extrafields (fk_object, vereine_fee_prorated) VALUES ({upgrade_type}, 1)")

    upload(stack, stack.package)
    switch_module(stack, "reset")
    switch_module(stack, "set")
    about = page_ok(stack.browser().get("/custom/vereine/admin/about.php"), "about after the upgrade")
    expect(stack.module_version in about.text, f"the about page does not show {stack.module_version} after the upgrade")
    expect(stack.const("VEREINE_REGISTER_NUMBER") == "987654321" and stack.const("VEREINE_AUTHORITY") == "BH Innsbruck",
           "the upgrade lost the association data")
    expect(stack.sql("SHOW TABLES LIKE 'llx_vereine_log'") == [["llx_vereine_log"]], "the upgrade did not create the log table")
    rights = {row[0] for row in stack.sql("SELECT id FROM llx_rights_def WHERE module = 'vereine' AND entity = 1")}
    expect("49210003" in rights, f"the upgrade did not add the website right: {rights}")
    categories = [stack.const(name) or "" for name in CATEGORY_CONSTANTS]
    expect(all(value.isdigit() and int(value) > 0 for value in categories), f"categories after the upgrade: {categories}")
    profiles = stack.value("SELECT COUNT(*) FROM llx_vereine_taxprofile WHERE entity = 1 AND standard = 1")
    expect(profiles == str(len(STANDARD_TAX_PROFILES)), f"the upgrade brought {profiles} standard tax profiles, expected {len(STANDARD_TAX_PROFILES)}")
    if old_checkbox:
        proration = stack.value(f"SELECT vereine_fee_proration FROM llx_adherent_type_extrafields WHERE fk_object = {upgrade_type}")
        leftover = stack.sql("SHOW COLUMNS FROM llx_adherent_type_extrafields LIKE 'vereine_fee_prorated'")
        definition = stack.value("SELECT COUNT(*) FROM llx_extrafields WHERE elementtype = 'adherent_type' AND name = 'vereine_fee_prorated'")
        expect(proration == "month" and not leftover and definition == "0",
               f"the old checkbox became {proration!r}, column left {leftover}, definitions left {definition}")
        stack.sql(f"DELETE FROM llx_adherent_type_extrafields WHERE fk_object = {upgrade_type}")
        stack.sql(f"DELETE FROM llx_adherent_type WHERE rowid = {upgrade_type}")

    stack.php_fixture("reset")
    expect(stack.const("MAIN_MODULE_VEREINE") is None and stack.const("VEREINE_REGISTER_NUMBER") is None,
           "the reset after the upgrade test left module state behind")
    return (f"{old} -> {stack.module_version}: association data kept; tables, website right and categories in place"
            + ("; old prorated checkbox became by month" if old_checkbox else ""))


def deploy(stack: Stack) -> str:
    """The package goes in the way an administrator installs it: Deploy an external module."""
    if stack.previous_package is None:
        expect(stack.shell(f"test ! -e {MODULE_DIR}").returncode == 0, "the module exists before the upload")
    files = upload(stack, stack.package)
    return f"{stack.package.name} deployed: {len(files)} files in custom/vereine on Dolibarr {stack.fixtures['dolibarr']}"


def enable(stack: Stack) -> str:
    """Enabling from the module list registers rights and menu and removes the country profile of earlier versions."""
    browser = stack.browser()
    page = module_list(browser)
    expect("Vereine (Österreich)" in page.text, "the module list does not show the translated module name")
    page_ok(browser.get(module_link(page, "set")), "enable")
    expect(stack.const("MAIN_MODULE_VEREINE") == "1", "MAIN_MODULE_VEREINE is not 1 after enabling")
    for module in ("MAIN_MODULE_ADHERENT", "MAIN_MODULE_SOCIETE", "MAIN_MODULE_CATEGORIE"):
        expect(stack.const(module) == "1", f"{module} was not enabled together with Vereine")
    expect(stack.const("VEREINE_COUNTRY_PROFILE") is None and stack.const("VEREINE_REGISTER_COURT") is None,
           "enabling left the country profile of earlier versions in place")
    rights = stack.sql("SELECT id, perms, subperms FROM llx_rights_def WHERE module = 'vereine' AND entity = 1 ORDER BY id")
    expect(rights == [["49210001", "association", "read"], ["49210002", "partner", "write"], ["49210003", "website", "read"],
                      ["49210004", "application", "write"]], f"rights after enabling: {rights}")
    menu = sorted(stack.sql("SELECT mainmenu, leftmenu, url FROM llx_menu WHERE module = 'vereine' AND entity = 1"))
    expect(menu == [["members", "vereine", "/vereine/vereineindex.php"], ["members", "vereine_authority", "/vereine/authority.php"],
                    ["members", "vereine_circulars", "/vereine/circulars.php"],
                    ["members", "vereine_feerun", "/vereine/fees_run.php"], ["members", "vereine_functions", "/vereine/functions.php"],
                    ["members", "vereine_meetings", "/vereine/meetings.php"],
                    ["members", "vereine_partners", "/vereine/partners.php"], ["members", "vereine_partnersetup", "/vereine/admin/partners.php"],
                    ["members", "vereine_resolutions", "/vereine/resolutions.php"]],
           f"menu entries after enabling: {menu}")
    expect(stack.sql("SHOW TABLES LIKE 'llx_vereine_log'") == [["llx_vereine_log"]], "the log table was not created")
    categories = {name: stack.const(name) or "0" for name in CATEGORY_CONSTANTS}
    types = {name: stack.value(f"SELECT type FROM llx_categorie WHERE rowid = {int(value)}") for name, value in categories.items()}
    expect(types == {"VEREINE_CATEGORY_MEMBER": "2", "VEREINE_CATEGORY_FORMER": "2", "VEREINE_CATEGORY_GUARDIAN": "4"},
           f"categories after enabling: {categories}, types {types}")
    label = stack.value(f"SELECT label FROM llx_categorie WHERE rowid = {int(categories['VEREINE_CATEGORY_MEMBER'])}")
    expect(label == "Mitglied", f"the member category is called {label!r}, expected the German 'Mitglied'")
    expect(stack.const("VEREINE_PARTNER_AUTOCREATE") == "0" and stack.const("VEREINE_PARTNER_TYPENT_NATURAL") == "TE_PRIVATE",
           "the defaults for members and third parties were not written")
    granted = stack.php_fixture("rights")
    expect(granted.get("right") == 49210001, f"granting the right returned {granted}")
    return (f"module {stack.module_version} on with Members, third parties and categories; no country profile; "
            "4 rights, 9 menu entries, log table, 3 categories")


def pages(stack: Stack) -> str:
    """Overview, setup, about and the Members menu render without errors, in German."""
    browser = stack.browser()
    overview = page_ok(browser.get("/custom/vereine/vereineindex.php"), "overview")
    expect("Vereinsdaten" in overview.text, "the overview is not in German; the de_DE language file did not load")
    expect(data_status(overview, "register") == "warning", "a missing ZVR number is not reported on the overview")
    expect(data_status(overview, "company_name") == "ok", "name and town of the company are reported as missing")
    expect(data_status(overview, "api") == "ok", "the enabled REST API is reported as off")
    setup = page_ok(browser.get("/custom/vereine/admin/setup.php"), "setup")
    form = setup.form(name="vereinesetup")
    expect(not form.has("VEREINE_COUNTRY_PROFILE") and form.has("VEREINE_AUTHORITY") and not form.has("VEREINE_REGISTER_COURT"),
           "the setup must ask for the authority, and neither for a country nor for a register court")
    about = page_ok(browser.get("/custom/vereine/admin/about.php"), "about")
    expect(stack.module_version in about.text and "IT-Tabelander" in about.text, "the about page lacks version or publisher")
    members = page_ok(browser.get("/adherents/index.php?mainmenu=members&leftmenu="), "Members home")
    expect("/custom/vereine/vereineindex.php" in members.text, "the Members menu has no entry for the association")
    entries = sorted(set(re.findall(r'href="([^"]*/custom/vereine/[^"]*)"', members.text)))
    expect(any("/custom/vereine/partners.php" in entry for entry in entries),
           f"the Members menu has no entry Members and third parties; module links: {entries}")
    expect(any("/custom/vereine/admin/partners.php" in entry for entry in entries),
           f"the Members menu has no entry Third party settings for the administrator; module links: {entries}")
    from_menu = page_ok(browser.get("/custom/vereine/admin/partners.php?mainmenu=members&leftmenu="), "third party settings from Members")
    expect('name="vereinepartnersetup"' in from_menu.text and "/adherents/list.php" in from_menu.text,
           "the third party settings opened from Members do not keep the Members menu")
    expect(data_status(overview, "partners") == "ok", "an empty Dolibarr reports open points between members and third parties")
    reconciliation = page_ok(browser.get("/custom/vereine/partners.php"), "members and third parties")
    sections = re.findall(r'data-section="([a-z_]+)"', reconciliation.text)
    expect(sections == ["without_partner", "attributes", "differences", "orphans", "minors", "duplicates"],
           f"reconciliation sections: {sections}")
    partner_setup = page_ok(browser.get("/custom/vereine/admin/partners.php"), "partner setup")
    form = partner_setup.form(name="vereinepartnersetup")
    expect(form.value("VEREINE_PARTNER_TYPENT_NATURAL") == "TE_PRIVATE" and form.value("VEREINE_PARTNER_AUTOCREATE") is None,
           "the partner setup does not show its defaults")
    script = browser.get("/custom/vereine/js/partners.js")
    expect(script.status == 200 and "vereine-select-all" in script.text, f"js/partners.js answered HTTP {script.status}")
    return "overview with checks, setup, partner setup, about, reconciliation and the Members menu entries render in German"


def setup(stack: Stack) -> str:
    """The setup refuses bad input, normalises good input, strips markup and follows a profile change."""
    browser = stack.browser()
    form = browser.get("/custom/vereine/admin/setup.php").form(name="vereinesetup")

    refused = page_ok(browser.submit(form, {"VEREINE_REGISTER_NUMBER": "12A"}), "setup with a bad ZVR number")
    expect(refused.form(name="vereinesetup").value("VEREINE_REGISTER_NUMBER") == "12A",
           "the refused form does not keep what was entered")
    expect(not stack.const("VEREINE_REGISTER_NUMBER"), "a bad ZVR number was stored")

    # Dolibarr's own injection filter (main.inc.php) refuses a script before the
    # module sees it; nothing may be stored. The purpose lives with the statutes, so it is tried there.
    statutes_form = page_ok(browser.get("/custom/vereine/admin/statutes.php"), "statutes before the purpose").form(name="vereinestatutetext")
    blocked = browser.submit(statutes_form, {"VEREINE_PURPOSE": "Zweck <script>alert(1)</script>"})
    expect(blocked.status == 403, f"a script in the purpose was not refused by Dolibarr (HTTP {blocked.status})")
    expect(not stack.const("VEREINE_PURPOSE"), "a refused request stored a purpose")
    setup_page = page_ok(browser.get("/custom/vereine/admin/setup.php"), "setup without the purpose field")
    expect('name="VEREINE_PURPOSE"' not in setup_page.text and 'data-purpose-here="0"' in setup_page.text
           and "admin/statutes.php#vereinestatutetext" in setup_page.text,
           "the general setup still offers the purpose instead of linking to the statutes")

    # Markup Dolibarr lets through is the module's to strip and escape.
    form = browser.get("/custom/vereine/admin/setup.php").form(name="vereinesetup")
    saved = browser.submit(form, {
        "VEREINE_REGISTER_NUMBER": " 123 456 789 ",
        "VEREINE_AUTHORITY": "Landespolizeidirektion Tirol",
        "foundedday": "1", "foundedmonth": "3", "foundedyear": "2019",
        "VEREINE_NONPROFIT": "1",
    })
    page_ok(saved, "setup after saving")
    expected = {"VEREINE_REGISTER_NUMBER": "123456789", "VEREINE_AUTHORITY": "Landespolizeidirektion Tirol",
                "VEREINE_FOUNDED": "2019-03-01", "VEREINE_NONPROFIT": "1"}
    stored = {name: stack.const(name) for name in expected}
    expect(stored == expected, f"stored {stored}, expected {expected}")
    statutes_form = page_ok(browser.get("/custom/vereine/admin/statutes.php"), "statutes for the purpose").form(name="vereinestatutetext")
    page_ok(browser.submit(statutes_form, {"VEREINE_PURPOSE": 'Förderung des E-Sports <i>im Verein</i> & "gemeinsam"', "arrears_months": "3"}),
            "the purpose saved with the statutes")
    purpose = stack.const("VEREINE_PURPOSE") or ""
    expect(purpose.startswith("Förderung des E-Sports"), f"the purpose lost its text or its umlaut: {purpose!r}")
    expect("<i>" not in purpose, f"markup was stored in the purpose: {purpose!r}")
    shown = page_ok(browser.get("/custom/vereine/admin/setup.php"), "setup showing the purpose")
    expect("Förderung des E-Sports" in html.unescape(shown.text), "the general setup does not show the purpose entered with the statutes")

    overview = page_ok(browser.get("/custom/vereine/vereineindex.php"), "overview after saving")
    expect(data_status(overview, "register") == "ok", "the overview still reports the ZVR number as missing")
    expect("123456789" in overview.text, "the overview does not show the stored ZVR number")
    # Dolibarr's input filter drops the quotes; the ampersand must reach the page escaped.
    expect(re.search(r"im Verein &amp; (&quot;)?gemeinsam", overview.text) is not None
           and "im Verein & " not in overview.text, "the overview prints the purpose without escaping")

    stack.sql("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('VEREINE_COUNTRY_PROFILE', 1, 'DE', 'chaine', 0)")
    overview = page_ok(browser.get("/custom/vereine/vereineindex.php"), "overview with an old German profile")
    expect("123456789" in overview.text and "Registergericht" not in overview.text, "an old German profile still changes the overview")
    stack.sql("DELETE FROM llx_const WHERE name = 'VEREINE_COUNTRY_PROFILE'")
    return "bad ZVR refused and kept on the form, data normalised, markup stripped, no country choice, an old German profile is ignored"


def access(stack: Stack) -> str:
    """A user with the right reads the overview only; a user without it reaches nothing."""
    reader = stack.browser("rtreader")
    page_ok(reader.get("/custom/vereine/vereineindex.php"), "overview for a reader")
    expect(denied(reader.get("/custom/vereine/admin/setup.php")), "a non-administrator opens the setup")
    expect(denied(reader.get("/custom/vereine/admin/about.php")), "a non-administrator opens the about page")
    expect(denied(reader.get("/custom/vereine/admin/partners.php")), "a non-administrator opens the partner setup")
    expect(denied(reader.get("/custom/vereine/partners.php")),
           "a user without the member and third party rights opens the reconciliation")
    nobody = stack.browser("rtnobody")
    expect(denied(nobody.get("/custom/vereine/vereineindex.php")), "a user without the right opens the overview")
    members = nobody.get("/adherents/index.php?mainmenu=members&leftmenu=")
    expect("/custom/vereine/vereineindex.php" not in members.text, "the menu entry shows for a user without the right")
    return "reader: overview yes, setup and about no; without the right: neither page nor menu entry"


def api(stack: Stack) -> str:
    """The REST API answers the reader, refuses others, and returns the stored association."""
    status, body = stack.api("vereine/status", stack.reader_key)
    expect(status == 200, f"GET vereine/status answered HTTP {status}: {body}")
    server_time = body.pop("server_time", "") if isinstance(body, dict) else ""
    expect(body == {"module_version": stack.module_version, "api_version": 1, "country_profile": "AT",
                    "country_profile_complete": True}, f"GET vereine/status returned {body}")
    drift = abs((datetime.datetime.now(datetime.timezone.utc)
                 - datetime.datetime.strptime(server_time, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=datetime.timezone.utc)).total_seconds())
    expect(drift < 300, f"server_time {server_time} is {drift:.0f} s away from this computer's clock")
    status, body = stack.api("vereine/organization", stack.reader_key)
    expect(status == 200 and isinstance(body, dict), f"GET vereine/organization answered HTTP {status}: {body}")
    expected = {
        "country_profile": "AT", "name": "Runtime Verein", "authority": "Landespolizeidirektion Tirol",
        "register": {"kind": "ZVR", "number": "123456789", "court": ""}, "founded": "2019-03-01",
        "nonprofit": True, "fiscal_year_start_month": 1,
    }
    differing = {key: body.get(key) for key, value in expected.items() if body.get(key) != value}
    expect(not differing, f"GET vereine/organization differs from the setup: {differing}")
    expect(body.get("address", {}).get("town") == "Innsbruck" and body["address"].get("country_code") == "AT",
           f"address from the company settings is wrong: {body.get('address')}")
    expect(str(body.get("purpose", "")).startswith("Förderung des E-Sports"), f"purpose: {body.get('purpose')!r}")
    status, _ = stack.api("vereine/organization", stack.nobody_key)
    expect(status == 403, f"a user without the right got HTTP {status}, expected 403")
    status, _ = stack.api("vereine/organization", None)
    expect(status == 401, f"a call without API key got HTTP {status}, expected 401")
    return "status and organization match the setup; without right 403, without key 401"


def partners(stack: Stack) -> str:
    """Members get their third party, a possible duplicate is only suggested, and the reconciliation fixes the rest."""
    browser = stack.browser()
    form = page_ok(browser.get("/custom/vereine/admin/partners.php"), "partner setup").form(name="vereinepartnersetup")
    page_ok(browser.submit(form, {"VEREINE_PARTNER_AUTOCREATE": "1"}), "switch on automatic third parties")
    expect(stack.const("VEREINE_PARTNER_AUTOCREATE") == "1", "automatic third parties were not switched on")

    data = stack.php_fixture("members")
    members, existing = data["members"], data["partners"]
    private = stack.value("SELECT id FROM llx_c_typent WHERE code = 'TE_PRIVATE'")
    member_category = stack.const("VEREINE_CATEGORY_MEMBER")
    former_category = stack.const("VEREINE_CATEGORY_FORMER")

    def partner_of(key: str) -> str | None:
        value = stack.value(f"SELECT fk_soc FROM llx_adherent WHERE rowid = {int(members[key])}")
        return None if value in (None, "NULL", "0") else value

    def categories_of(socid) -> set:
        return {row[0] for row in stack.sql(f"SELECT fk_categorie FROM llx_categorie_societe WHERE fk_soc = {int(socid)}")}

    lisa = partner_of("lisa")
    expect(lisa is not None, "validating Lisa did not create a third party")
    row = stack.sql(f"SELECT client, fk_typent, nom FROM llx_societe WHERE rowid = {int(lisa)}")[0]
    expect(row == ["1", private, "Lisa Neu"], f"Lisa's third party is {row}, expected customer, private, 'Lisa Neu'")
    expect(member_category in categories_of(lisa), "Lisa's third party is not in the member category")
    expect(partner_of("anna") is None, "Anna was linked or duplicated although a third party with her e-mail exists")
    suggested = stack.value("SELECT fk_soc FROM llx_vereine_log WHERE action = 'partner_suggested' "
                            f"AND fk_adherent = {int(members['anna'])}")
    expect(suggested == str(existing["anna"]), f"the log does not suggest Anna's existing third party ({suggested})")
    kind = partner_of("kind")
    expect(kind is not None, "the minor member got no third party")
    sponsor = partner_of("sponsor")
    sponsor_row = stack.sql(f"SELECT nom, fk_typent FROM llx_societe WHERE rowid = {int(sponsor or 0)}")
    expect(sponsor_row and sponsor_row[0][0] == "Sponsor GmbH" and sponsor_row[0][1] in ("0", "NULL"),
           f"the sponsor's third party is {sponsor_row}, expected 'Sponsor GmbH' without customer type")
    expect(partner_of("draft") is None, "a draft member got a third party")
    reader = stack.browser("rtreader")
    expect(denied(reader.get(f"/custom/vereine/partner_membership.php?socid={int(lisa)}")),
           "a user without member rights opens the membership tab")

    page = page_ok(browser.get("/custom/vereine/partners.php"), "reconciliation")
    counts = section_counts(page)
    expect(counts.get("without_partner") == "2" and counts.get("orphans") == "1" and counts.get("minors") == "1",
           f"reconciliation counts {counts}; expected 2 without third party, 1 orphan, 1 minor")
    link_value = f"{members['anna']}_{existing['anna']}"
    expect(f'value="{link_value}"' in page.text, "Anna's existing third party is not offered for linking")

    # Every row opens a dialog with its steps; each section with a bulk step has "select all".
    row_tags = re.findall(r'<tr class="oddeven[^"]*" data-row="\d+"([^>]*)>', page.text)
    dialogs = re.findall(r'data-dialog="(vereinerow-[a-z_]+-\d+)"', " ".join(row_tags))
    expect(row_tags and len(dialogs) == len(row_tags), f"{len(row_tags)} rows, but {len(dialogs)} open a dialog")
    missing = [dialog for dialog in dialogs if f'id="{dialog}" class="vereine-row-dialog"' not in page.text]
    expect(not missing, f"rows point to dialogs that are not on the page: {missing}")
    expect('data-select="sel_create[]"' in page.text and 'data-select="sel_orphans[]"' in page.text,
           "the sections have no 'select all'")
    expect("/custom/vereine/js/partners.js" in page.text, "the reconciliation does not load js/partners.js")
    page.form(name=f"vereineaction-link-{link_value}")

    # A reader of members and third parties sees the same, but is offered nothing to change or edit.
    stack.php_fixture("readmembers")
    reader_page = page_ok(reader.get("/custom/vereine/partners.php"), "reconciliation for a reader")
    expect(section_counts(reader_page) == counts, f"the reader sees {section_counts(reader_page)}, the administrator {counts}")
    offered = [part for part in ('name="vereinerowaction"', "vereine-select-all", "data-dialog=", 'name="op"')
               if part in reader_page.text]
    expect(not offered, f"a reader without write rights is offered {offered}")
    reader_members = page_ok(reader.get("/adherents/index.php?mainmenu=members&leftmenu="), "Members home for a reader")
    expect("/custom/vereine/partners.php" in reader_members.text and "/custom/vereine/admin/partners.php" not in reader_members.text,
           "the reader's Members menu must offer Members and third parties but not Third party settings")
    page_ok(browser.submit(page.form(name="vereinepartners"), button=("link", link_value)), "link Anna")
    expect(partner_of("anna") == str(existing["anna"]), "linking did not set Anna's third party")
    expect(member_category in categories_of(existing["anna"]), "Anna's linked third party is not in the member category")
    expect(stack.value(f"SELECT fk_typent FROM llx_societe WHERE rowid = {int(existing['anna'])}") == private,
           "Anna's third party did not get the private customer type")

    form = page_ok(browser.get("/custom/vereine/partners.php"), "reconciliation").form(name="vereinepartners")
    preview = page_ok(browser.submit(form, {"sel_create[]": str(members["draft"])}, button=("op", "create")), "preview create")
    expect('data-preview="create"' in preview.text and f'data-preview-row="{members["draft"]}"' in preview.text,
           "no preview before creating third parties")
    expect(partner_of("draft") is None, "the preview already created a third party")
    page_ok(browser.submit(preview.form(name="vereinepreview")), "confirm create")
    draft = partner_of("draft")
    expect(draft is not None, "confirming did not create the draft member's third party")
    expect(member_category not in categories_of(draft), "a draft member's third party got the member category")

    stack.sql(f"UPDATE llx_societe SET email = 'veraltet@runtime-verein.test' WHERE rowid = {int(kind)}")
    form = page_ok(browser.get("/custom/vereine/partners.php"), "reconciliation").form(name="vereinepartners")
    preview = page_ok(browser.submit(form, {"sel_copy[]": str(members["kind"])}, button=("op", "copy")), "preview copy")
    expect("veraltet@runtime-verein.test" in preview.text and "kind@runtime-verein.test" in preview.text,
           "the copy preview does not show the old and the new e-mail")
    page_ok(browser.submit(preview.form(name="vereinepreview")), "confirm copy")
    expect(stack.value(f"SELECT email FROM llx_societe WHERE rowid = {int(kind)}") == "kind@runtime-verein.test",
           "the member's e-mail was not copied to the third party")

    form = page_ok(browser.get("/custom/vereine/partners.php"), "reconciliation").form(name="vereinepartners")
    preview = page_ok(browser.submit(form, {"sel_orphans[]": str(existing["old"])}, button=("op", "orphans")), "preview orphans")
    page_ok(browser.submit(preview.form(name="vereinepreview")), "confirm orphans")
    expect(member_category not in categories_of(existing["old"]), "the third party without member kept the member category")

    # The step in a row's dialog previews exactly that row and changes nothing before the confirmation.
    stack.sql(f"DELETE FROM llx_categorie_societe WHERE fk_soc = {int(lisa)} AND fk_categorie = {int(member_category)}")
    page = page_ok(browser.get("/custom/vereine/partners.php"), "reconciliation with Lisa out of line")
    expect(f'data-dialog="vereinerow-attributes-{members["lisa"]}"' in page.text, "Lisa's row has no dialog")
    preview = page_ok(browser.submit(page.form(name=f"vereineaction-attributes-{members['lisa']}")), "preview from Lisa's dialog")
    previewed = re.findall(r'data-preview-row="(\d+)"', preview.text)
    expect('data-preview="attributes"' in preview.text and previewed == [str(members["lisa"])],
           f"the dialog's step previews rows {previewed}, expected only Lisa")
    expect(member_category not in categories_of(lisa), "the dialog's step changed the third party before the confirmation")
    page_ok(browser.submit(preview.form(name="vereinepreview")), "confirm the step from Lisa's dialog")
    expect(member_category in categories_of(lisa), "confirming the dialog's step did not bring Lisa's third party in line")

    # Edit member from the dialog: Dolibarr's own form, and saving returns to the reconciliation.
    edit_link = re.search(rf'href="([^"]*/adherents/card\.php\?id={members["lisa"]}&amp;action=edit&amp;backtopage=[^"]+)"', page.text)
    expect(edit_link is not None, "Lisa's dialog does not offer to edit the member")
    edit = page_ok(browser.get(html.unescape(edit_link.group(1))), "edit Lisa from the dialog")
    saved = browser.submit(edit.form(name="formsoc"), follow=False)
    location = next((value for name, value in saved.headers.items() if name.lower() == "location"), "")
    expect(saved.status in (301, 302, 303) and location.endswith("/custom/vereine/partners.php"),
           f"saving the member answered HTTP {saved.status} to {location!r}, expected a return to the reconciliation")

    stack.php_fixture("resiliate", RT_MEMBER_ID=str(members["lisa"]))
    lisa_categories = categories_of(lisa)
    expect(former_category in lisa_categories and member_category not in lisa_categories,
           f"after resigning, Lisa's third party is in categories {lisa_categories}")
    stack.php_fixture("guardian", RT_PARTNER_ID=str(kind))
    page = page_ok(browser.get("/custom/vereine/partners.php"), "reconciliation after the fixes")
    counts = section_counts(page)
    expect(set(counts.values()) == {"0"}, f"open points left after the fixes: {counts}")

    tab = page_ok(browser.get(f"/custom/vereine/partner_membership.php?socid={int(lisa)}"), "membership tab")
    expect(f'data-membership="{members["lisa"]}"' in tab.text, "the membership tab does not show Lisa's membership")
    expect("Geschäftspartner angelegt" in html.unescape(tab.text), "the membership tab does not show the module's log")
    card = page_ok(browser.get(f"/societe/card.php?socid={int(lisa)}"), "third party card")
    expect(f"partner_membership.php?socid={int(lisa)}" in card.text, "the third party card has no membership tab")
    overview = page_ok(browser.get("/custom/vereine/vereineindex.php"), "overview")
    expect(data_status(overview, "partners") == "ok", "the overview still reports open partner points")
    actions = {row[0] for row in stack.sql("SELECT DISTINCT action FROM llx_vereine_log")}
    wanted = {"partner_created", "partner_suggested", "partner_linked", "partner_attributes", "partner_updated"}
    expect(wanted <= actions, f"log actions {sorted(actions)} lack {sorted(wanted - actions)}")

    form = page_ok(browser.get("/custom/vereine/admin/partners.php"), "partner setup").form(name="vereinepartnersetup")
    page_ok(browser.submit(form, {"VEREINE_PARTNER_CATEGORY_PER_TYPE": "1"}), "switch on sub-categories per member type")
    tab = page_ok(browser.get(f"/custom/vereine/partner_membership.php?socid={int(sponsor)}"), "sponsor membership tab")
    page_ok(browser.submit(tab.form(name="vereineapply")), "bring the sponsor in line")
    child = stack.value(f"SELECT rowid FROM llx_categorie WHERE fk_parent = {int(member_category)} "
                        "AND label = 'Ordentliches Mitglied' AND type = 2")
    expect(child is not None and child in categories_of(sponsor),
           "the sub-category for the member type was not created below the member category or not assigned")
    return ("created on validation, existing third party suggested and linked, draft created after preview, "
            "e-mail copied, orphan corrected, row dialog: preview of one row and edit returns, reader offered nothing, "
            "resignation -> former member, guardian clears the minor, sub-category per member type")


def membercard(stack: Stack) -> str:
    """Dolibarr's own member card creates, removes and links a third party; the module follows and shows it."""
    browser = stack.browser()
    form = page_ok(browser.get("/custom/vereine/admin/partners.php"), "partner setup").form(name="vereinepartnersetup")
    page_ok(browser.submit(form, drop=("VEREINE_PARTNER_AUTOCREATE",)), "switch off automatic third parties")
    expect(stack.const("VEREINE_PARTNER_AUTOCREATE") == "0", "automatic third parties were not switched off")
    karl = int(stack.php_fixture("cardmember")["member"])
    member_category = stack.const("VEREINE_CATEGORY_MEMBER")

    def partner_of() -> str | None:
        value = stack.value(f"SELECT fk_soc FROM llx_adherent WHERE rowid = {karl}")
        return None if value in (None, "NULL", "0") else value

    def categories_of(socid) -> set:
        return {row[0] for row in stack.sql(f"SELECT fk_categorie FROM llx_categorie_societe WHERE fk_soc = {int(socid)}")}

    def logged(action: str) -> list:
        return [row[0] for row in stack.sql(f"SELECT fk_soc FROM llx_vereine_log WHERE fk_adherent = {karl} AND action = '{action}' ORDER BY rowid")]

    expect(partner_of() is None, "Karl got a third party although automatic creation is off")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={karl}"), "tab Association without third party")
    expect('data-association="none"' in tab.text, "the tab Association does not say that no third party is linked")

    # Viewing the member card changes nothing.
    entries = stack.value("SELECT COUNT(*) FROM llx_vereine_log")
    card = page_ok(browser.get(f"/adherents/card.php?id={karl}"), "member card")
    expect(f"member_association.php?id={karl}" in card.text, "the member card has no tab Association")
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_log") == entries, "viewing the member card wrote to the module's log")

    # "Create third party" on the member card, sent as its confirmation dialog sends it.
    created = browser.post("/adherents/card.php", [("token", token_of(card)), ("id", str(karl)), ("action", "confirm_create_thirdparty"),
                                                   ("confirm", "yes"), ("companyname", "Karl Karte"), ("companyalias", "")])
    page_ok(created, "Create third party on the member card")
    first = partner_of()
    expect(first is not None, "Dolibarr's Create third party did not link a third party")
    expect(member_category in categories_of(first), "the third party created on the member card is not in the member category")
    expect(logged("partner_created") == [first], f"the log does not record the third party created on the member card: {logged('partner_created')}")

    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={karl}"), "tab Association")
    shown = re.search(r'data-partner-categories="1">([^<]*)<', tab.text)
    expect(f'data-association="{first}"' in tab.text and shown is not None and "Mitglied" in html.unescape(shown.group(1)),
           "the tab Association does not show the third party and its member category")
    expect('data-problems="0"' in tab.text and 'name="vereineapply"' in tab.text,
           "the tab Association reports open points or offers no way to bring the third party in line")

    # "Linked third party" on the member card: remove the link, then link again, with Dolibarr's own form.
    card = page_ok(browser.get(f"/adherents/card.php?id={karl}"), "member card with third party")
    edit = page_ok(browser.get(action_link(card, "editthirdparty")), "edit the linked third party")
    page_ok(browser.submit(edit.form(name="formsocid"), {"socid": "-1"}), "remove the linked third party")
    expect(partner_of() is None, "removing the link on the member card did not unlink the third party")
    expect(member_category not in categories_of(first), "the third party left without member kept the member category")
    expect(logged("partner_unlinked") == [first], f"the log does not record the removed link: {logged('partner_unlinked')}")

    card = page_ok(browser.get(f"/adherents/card.php?id={karl}"), "member card without third party")
    edit = page_ok(browser.get(action_link(card, "editthirdparty")), "edit the linked third party again")
    page_ok(browser.submit(edit.form(name="formsocid"), {"socid": first}), "link the third party again")
    expect(partner_of() == first, "linking on the member card did not set the third party")
    expect(member_category in categories_of(first), "the third party linked on the member card is not in the member category")
    expect(logged("partner_linked") == [first], f"the log does not record the link: {logged('partner_linked')}")

    reader = stack.browser("rtreader")
    reader_tab = page_ok(reader.get(f"/custom/vereine/member_association.php?id={karl}"), "tab Association for a reader")
    expect(f'data-association="{first}"' in reader_tab.text and 'name="vereineapply"' not in reader_tab.text,
           "a reader does not see the third party, or is offered to change it")
    expect(denied(stack.browser("rtnobody").get(f"/custom/vereine/member_association.php?id={karl}")),
           "a user without the rights opens the tab Association")
    return ("Create third party and Linked third party on Dolibarr's member card: member category and log follow, "
            "a removed link takes the category away; tab Association on the member card; viewing changes nothing")


def taxprofiles(stack: Stack) -> str:
    """Suggested tax profiles arrive on activation; the setup refuses what the law excludes and keeps the association's changes."""
    stored = stack.sql("SELECT code, sphere, treatment, rate, active FROM llx_vereine_taxprofile WHERE entity = 1 AND standard = 1 ORDER BY code")
    expect(stored == STANDARD_TAX_PROFILES, f"standard tax profiles after enabling: {stored}")
    note = stack.value("SELECT note FROM llx_vereine_taxprofile WHERE code = 'KLEINUNTERNEHMER'") or ""
    expect("§ 6 Abs. 1 Z 27 UStG" in note, f"the small business profile lacks its German invoice note: {note!r}")

    browser = stack.browser()
    page = page_ok(browser.get("/custom/vereine/admin/taxprofiles.php"), "tax profile setup")
    rows = re.findall(r'data-taxprofile="([A-Z0-9_]+)" data-active="([01])"', page.text)
    expect(sorted(rows) == sorted((row[0], row[4]) for row in STANDARD_TAX_PROFILES), f"the setup lists {rows}")
    # Plain words first: the three steps and an explanation with examples for every area and VAT treatment.
    expect('data-howto="1"' in page.text, "the tax profile setup lacks its how-to")
    explained = set(re.findall(r'data-sphere-help="([a-z]+)"', page.text))
    expect(explained == {"ideal", "assets", "essential", "auxiliary", "festival", "harmful"}, f"areas explained: {sorted(explained)}")
    explained = set(re.findall(r'data-treatment-help="([a-z0-9_]+)"', page.text))
    expect(explained == {"nonbusiness", "hobby", "small_business", "sport", "reduced10", "reduced13", "standard20"},
           f"VAT treatments explained: {sorted(explained)}")
    text = html.unescape(page.text)
    expect("Theatervorstellung eines Theatervereins" in text and "Faschingsball" in text,
           "the explanations lack the ministry's examples in German")

    # 10 % in the business harmful to tax privileges: § 10 (2) no. 4 UStG excludes it.
    form = page.form(name="vereinetaxprofile")
    refused = page_ok(browser.submit(form, {"code": "KANTINE", "label": "Kantine beim Turnier", "sphere": "harmful", "treatment": "reduced10"}),
                      "tax profile with 10 % in the harmful business")
    refusal = html.unescape(refused.text)
    expect("wie ein normales Geschäft" in refusal and "§ 10 Abs. 2 Z 4 UStG" in refusal,
           "the refusal does not explain in plain words and name § 10 Abs. 2 Z 4 UStG at the end")
    expect(refused.form(name="vereinetaxprofile").value("label") == "Kantine beim Turnier", "the refused form lost what was entered")
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_taxprofile WHERE code = 'KANTINE'") == "0", "a refused tax profile was stored")

    # An exemption needs its invoice note.
    form = browser.get("/custom/vereine/admin/taxprofiles.php").form(name="vereinetaxprofile")
    refused = page_ok(browser.submit(form, {"code": "KANTINE", "label": "Kantine", "sphere": "harmful", "treatment": "small_business", "note": ""}),
                      "small business profile without note")
    expect("§ 11 Abs. 1 Z 3 lit. e UStG" in html.unescape(refused.text), "an exemption without invoice note was not refused")

    form = browser.get("/custom/vereine/admin/taxprofiles.php").form(name="vereinetaxprofile")
    page_ok(browser.submit(form, {"code": "KANTINE", "label": "Kantine beim Turnier", "sphere": "harmful", "treatment": "standard20"}),
            "own tax profile")
    expect(stack.sql("SELECT sphere, treatment, rate, active, standard FROM llx_vereine_taxprofile WHERE code = 'KANTINE'")
           == [["harmful", "standard20", "20.000", "1", "0"]], "the own tax profile was not stored with 20 %")

    # The association renames a suggestion and switches one off; enabling again changes neither.
    page = page_ok(browser.get("/custom/vereine/admin/taxprofiles.php"), "tax profile setup")
    festival = stack.value("SELECT rowid FROM llx_vereine_taxprofile WHERE code = 'VEREINSFEST'")
    edit = page_ok(browser.get(action_link_for(page, "edit", festival)), "edit a suggested tax profile")
    page_ok(browser.submit(edit.form(name="vereinetaxprofile"), {"label": "LAN-Party im Vereinsheim"}), "rename the festival profile")
    toggles = [form for form in page.forms() if form.name == "vereinetaxtoggle" and form.value("id") == stack.value("SELECT rowid FROM llx_vereine_taxprofile WHERE code = 'SPENDE'")]
    expect(len(toggles) == 1, "the donation profile has no switch")
    page_ok(browser.submit(toggles[0]), "switch off the donation profile")
    switch_module(stack, "reset")
    switch_module(stack, "set")
    expect(stack.sql("SELECT label FROM llx_vereine_taxprofile WHERE code = 'VEREINSFEST'") == [["LAN-Party im Vereinsheim"]],
           "enabling again replaced the renamed festival profile")
    expect(stack.value("SELECT active FROM llx_vereine_taxprofile WHERE code = 'SPENDE'") == "0", "enabling again switched the donation profile back on")
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_taxprofile WHERE entity = 1") == str(len(STANDARD_TAX_PROFILES) + 1),
           "enabling again added tax profiles twice")

    status, body = stack.api("vereine/taxprofiles", stack.reader_key)
    expect(status == 200 and isinstance(body, list), f"GET vereine/taxprofiles answered HTTP {status}: {body}")
    by_code = {profile.get("code"): profile for profile in body}
    kantine = by_code.get("KANTINE", {})
    expect(kantine.get("rate") == 20 and kantine.get("treatment_basis") == "§ 10 Abs. 1 UStG" and kantine.get("active") is True
           and kantine.get("standard") is False, f"GET vereine/taxprofiles returns KANTINE as {kantine}")
    expect(by_code.get("SPENDE", {}).get("active") is False, "the API does not show the donation profile as inactive")
    status, _ = stack.api("vereine/taxprofiles", stack.nobody_key)
    expect(status == 403, f"a user without the right got HTTP {status} for the tax profiles, expected 403")
    expect(denied(stack.browser("rtreader").get("/custom/vereine/admin/taxprofiles.php")), "a non-administrator opens the tax profile setup")
    return ("9 suggested profiles, 10 % in the harmful business and an exemption without note refused, own profile stored, "
            "renamed and switched-off suggestions survive enabling again, API with and without right")


def taxassign(stack: Stack) -> str:
    """Products and invoice lines carry a tax profile; the product's VAT follows it; a line that differs is reported."""
    fields = sorted(row[0] for row in stack.sql("SELECT elementtype FROM llx_extrafields WHERE name = 'vereine_taxprofile'"))
    expect(fields == ["facture_fourn_det", "facturedet", "product"], f"extra field tax profile on {fields}")
    data = stack.php_fixture("invoicing")
    products, profiles = data["products"], data["profiles"]

    fee = stack.sql(f"SELECT tva_tx, price, price_ttc FROM llx_product WHERE rowid = {int(products['fee'])}")[0]
    expect(float(fee[0]) == 0 and float(fee[1]) == 50 and float(fee[2]) == 50,
           f"the membership fee created at 20 % did not follow its 0 % profile with a matching gross price: {fee}")
    drink = stack.sql(f"SELECT tva_tx, price, price_ttc FROM llx_product WHERE rowid = {int(products['drink'])}")[0]
    expect(float(drink[0]) == 20 and abs(float(drink[2]) - 3.6) < 0.001,
           f"the canteen drink created at 10 % did not follow its 20 % profile: {drink}")

    def line_profiles(table: str, column: str, invoice) -> list:
        return stack.sql(f"SELECT d.description, e.vereine_taxprofile FROM llx_{table} as d LEFT JOIN llx_{table}_extrafields as e "
                         f"ON e.fk_object = d.rowid WHERE d.{column} = {int(invoice)} ORDER BY d.rang, d.rowid")

    lines = line_profiles("facturedet", "fk_facture", data["invoice"])
    expected = [["Mitgliedsbeitrag 2027", str(profiles["MITGLIEDSBEITRAG"])], ["Getränk Kantine", str(profiles["BETRIEB_20"])],
                ["Buffet Sommerfest", str(profiles["VEREINSFEST"])]]
    expect(lines == expected, f"invoice lines and their tax profiles: {lines}")
    supplier = line_profiles("facture_fourn_det", "fk_facture_fourn", data["supplier_invoice"])
    expect(supplier == [["Getränke Einkauf", str(profiles["BETRIEB_20"])]], f"supplier invoice line and its tax profile: {supplier}")

    browser = stack.browser()
    card = page_ok(browser.get(f"/compta/facture/card.php?id={int(data['invoice'])}"), "customer invoice")
    expect('data-taxprofile-warning="1"' in card.text, "the invoice does not report the one line whose VAT differs from its profile")
    text = html.unescape(card.text)
    expect("Getränk Kantine" in text and "Umsatzsteuer passt nicht zum Steuerprofil" in text,
           "the warning does not name the canteen drink in German")
    supplier_card = page_ok(browser.get(f"/fourn/facture/card.php?facid={int(data['supplier_invoice'])}"), "supplier invoice")
    expect("data-taxprofile-warning" not in supplier_card.text, "a supplier invoice whose VAT matches its profile shows a warning")

    # The product card offers active profiles only; choosing one there changes the VAT rate.
    product_card = page_ok(browser.get(f"/product/card.php?id={int(products['drink'])}"), "product card")
    edit = page_ok(browser.get(action_link(product_card, "edit")), "edit the canteen drink")
    forms = [form for form in edit.forms() if form.has("options_vereine_taxprofile")]
    expect(len(forms) == 1, "the product edit form has no tax profile")
    options = set(re.findall(r'<option value="(\d+)"', edit.text.split('name="options_vereine_taxprofile"', 1)[1].split("</select>", 1)[0]))
    inactive = {stack.value("SELECT rowid FROM llx_vereine_taxprofile WHERE code = 'SPORT'"), stack.value("SELECT rowid FROM llx_vereine_taxprofile WHERE code = 'SPENDE'")}
    small_business = stack.value("SELECT rowid FROM llx_vereine_taxprofile WHERE code = 'KLEINUNTERNEHMER'")
    expect(small_business in options and not (inactive & options), f"the tax profile list offers {sorted(options)}; inactive {sorted(inactive)} must be missing")
    page_ok(browser.submit(forms[0], {"options_vereine_taxprofile": small_business}), "switch the drink to the small business profile")
    expect(float(stack.value(f"SELECT tva_tx FROM llx_product WHERE rowid = {int(products['drink'])}")) == 0,
           "choosing the small business profile on the product card did not set 0 % VAT")

    status, body = stack.api("vereine/taxprofiles", stack.reader_key)
    ids = {profile.get("code"): profile.get("id") for profile in body} if status == 200 else {}
    expect(ids.get("BETRIEB_20") == profiles["BETRIEB_20"], f"GET vereine/taxprofiles does not give the id of BETRIEB_20: {ids}")
    return ("extra field on products and invoice lines; product VAT and gross price follow the profile; new lines take the "
            "product's profile; one differing line reported on the invoice; product card offers active profiles only")


def pdf_text(stack: Stack, directory: str) -> str:
    """Text of the newest PDF below a documents directory: every stream inflated, as Latin-1."""
    listing = stack.shell(f"ls -t $(find /var/www/documents/{directory} -name '*.pdf') | head -1")
    path = listing.stdout.strip()
    expect(listing.returncode == 0 and path.endswith(".pdf"), f"no PDF was built below documents/{directory}")
    return pdf_bytes_text(base64.b64decode(stack.shell(f"base64 '{path}'").stdout))


def pdf_bytes_text(data: bytes) -> str:
    """Text of a PDF: every stream inflated, as Latin-1."""
    import zlib
    parts = []
    # A compressed stream may itself end with \r, which the pattern takes for the line break (#179):
    # try again with it, then take what inflates.
    for stream, carriage in re.findall(rb"stream\r?\n(.*?)(\r?)\nendstream", data, re.S):
        for candidate in (stream, stream + carriage):
            try:
                parts.append(zlib.decompress(candidate))
                break
            except zlib.error:
                continue
        else:
            try:
                parts.append(zlib.decompressobj().decompress(stream + carriage))
            except zlib.error:
                parts.append(stream)
    # Text shows as (...) strings with escaped brackets; drop the escapes so words read as written.
    return b"".join(parts).decode("latin-1").replace("\\(", "(").replace("\\)", ")")


def invoicepdf(stack: Stack) -> str:
    """The invoice PDF shows the tax profile notes per line and the ZVR number; the stored invoice stays as it was."""
    invoice = stack.value("SELECT MAX(rowid) FROM llx_facture")
    expect(invoice not in (None, "NULL"), "no invoice to build a PDF from; the taxassign scenario did not run")
    browser = stack.browser()

    def build() -> str:
        card = page_ok(browser.get(f"/compta/facture/card.php?id={int(invoice)}"), "invoice card")
        forms = [form for form in card.forms() if form.value("action") == "builddoc"]
        expect(len(forms) == 1, "the invoice card offers no form to build the PDF")
        page_ok(browser.submit(forms[0], {"model": "sponge", "lang_id": "de_DE"}), "build the invoice PDF")
        return pdf_text(stack, "facture")

    text = build()
    for part in ("Zeile 1: Echter Mitgliedsbeitrag ohne Gegenleistung, nicht umsatzsteuerbar.", "Zeile 3: Nicht umsatzsteuerbar (Liebhaberei).",
                 "ZVR-Zahl: 123456789"):
        words = part.split()
        expect(all(word.encode("latin-1", "replace").decode("latin-1") in text for word in words if word.isascii()),
               f"the invoice PDF lacks: {part}")
    expect("Zeile 2:" not in text, "the canteen drink line without note got a note on the PDF")
    note = stack.value(f"SELECT note_public FROM llx_facture WHERE rowid = {int(invoice)}")
    expect(note in (None, "NULL", ""), f"building the PDF stored the notes in the invoice: {note!r}")

    # Switched off, neither appears.
    form = page_ok(browser.get("/custom/vereine/admin/taxprofiles.php"), "tax profile setup").form(name="vereinetaxpdf")
    page_ok(browser.submit(form, drop=("VEREINE_PDF_TAX_NOTES",)), "switch off the invoice notes")
    form = browser.get("/custom/vereine/admin/setup.php").form(name="vereinesetup")
    page_ok(browser.submit(form, drop=("VEREINE_PDF_REGISTER",)), "switch off the register number on invoices")
    expect(stack.const("VEREINE_PDF_TAX_NOTES") == "0" and stack.const("VEREINE_PDF_REGISTER") == "0", "the PDF switches did not store 0")
    text = build()
    expect("Liebhaberei" not in text and "123456789" not in text, "switched off, the invoice PDF still shows notes or the ZVR number")
    return "notes per line and ZVR number on the invoice PDF, stored invoice unchanged, both switchable"


def thresholds(stack: Stack) -> str:
    """Thresholds per calendar year from validated invoices, as traffic light on the overview, the home page and the API."""
    data = stack.php_fixture("turnover")
    expect(len(data.get("invoices", {})) == 3, f"turnover fixture returned {data}")
    stack.notes["invoice_2026"] = data["invoices"]["2026"]

    status, body = stack.api("vereine/thresholds?year=2026", stack.reader_key)
    expect(status == 200 and isinstance(body, dict), f"GET vereine/thresholds?year=2026 answered HTTP {status}: {body}")
    found = {entry["code"]: entry for entry in body.get("thresholds", [])}
    small, harmful = found.get("small_business", {}), found.get("harmful_business", {})
    expect(small.get("amount") == 60000 and small.get("status") == "tolerance" and small.get("limit") == 55000 and small.get("gross") is True,
           f"small business limit 2026 (only the canteen, gross, draft left out): {small}")
    expect(harmful.get("amount") == 60000 and harmful.get("status") == "ok", f"§ 45a BAO 2026: {harmful}")
    unassigned = body.get("unassigned", {})
    expect(unassigned.get("lines") == 1 and unassigned.get("gross") == 600, f"income without tax profile 2026: {unassigned}")

    status, body = stack.api("vereine/thresholds?year=2025", stack.reader_key)
    found = {entry["code"]: entry for entry in body.get("thresholds", [])} if status == 200 else {}
    expect(found.get("small_business", {}).get("amount") == 1200 and found["small_business"].get("status") == "ok",
           f"2025 counts only the invoice of 31 December 2025: {found}")
    status, _ = stack.api("vereine/thresholds?year=2026", stack.nobody_key)
    expect(status == 403, f"a user without rights got HTTP {status} for the thresholds, expected 403")

    browser = stack.browser()
    overview = page_ok(browser.get("/custom/vereine/vereineindex.php?year=2026"), "overview with thresholds")
    rows = dict(re.findall(r'data-threshold="([a-z_]+)" data-status="([a-z]+)"', overview.text))
    expect(rows == {"small_business": "tolerance", "harmful_business": "ok", "festival_hours": "unchecked"},
           f"traffic light on the overview: {rows}")
    text = html.unescape(overview.text)
    expect("innerhalb der 10 % Toleranz" in text and 'data-thresholds-unassigned="1"' in overview.text,
           "the overview does not explain the tolerance in German or does not report the line without tax profile")

    boxes = stack.value("SELECT COUNT(*) FROM llx_boxes_def WHERE file = 'box_vereine_thresholds.php@vereine'")
    expect(boxes == "1", f"the home page box is registered {boxes} times")
    home = page_ok(browser.get("/index.php?mainmenu=home"), "home page")
    expect('data-box-threshold="small_business"' in home.text, "the home page does not show the thresholds box")
    reader_home = page_ok(stack.browser("rtnobody").get("/index.php?mainmenu=home"), "home page without rights")
    expect("data-box-threshold" not in reader_home.text, "a user without rights sees the thresholds box")
    return ("calendar years counted on their own; draft and lines without profile left out and reported; tolerance, "
            "§ 45a status on overview, home page box and API with and without rights")


def cashregister(stack: Stack) -> str:
    """Cash register duty per sphere from invoices and cash payments; the 13 % VAT rate is added once."""
    stack.php_fixture("cashpayments", RT_INVOICE_ID=str(stack.notes["invoice_2026"]))
    status, body = stack.api("vereine/thresholds?year=2026", stack.reader_key)
    expect(status == 200, f"GET vereine/thresholds?year=2026 answered HTTP {status}: {body}")
    register = body.get("cash_register", {})
    spheres = {entry["sphere"]: entry for entry in register.get("spheres", [])}
    harmful, ideal = spheres.get("harmful", {}), spheres.get("ideal", {})
    # 20,000 in cash on an invoice of 60,000 canteen, 20,000 fees and 600 without profile; the transfer does not count.
    expect(harmful.get("turnover") == 60000 and abs(harmful.get("cash", 0) - 14888.34) < 0.011 and harmful.get("status") == "required",
           f"canteen: {harmful}")
    expect(ideal.get("status") == "not_relevant" and abs(ideal.get("cash", 0) - 4962.78) < 0.011, f"membership fees: {ideal}")
    expect(abs(register.get("unassigned_cash", 0) - 148.88) < 0.011 and register.get("small_canteen_limit") == 45000,
           f"cash without tax profile and small canteen limit: {register}")

    browser = stack.browser()
    overview = page_ok(browser.get("/custom/vereine/vereineindex.php?year=2026"), "overview with cash register")
    rows = dict(re.findall(r'data-cash-sphere="([a-z]+)" data-status="([a-z_]+)"', overview.text))
    expect(rows.get("harmful") == "required" and rows.get("ideal") == "not_relevant", f"cash register on the overview: {rows}")
    text = html.unescape(overview.text)
    expect("vierten Monat" in text and "52 Tagen" in text and "45.000" in text, "the overview does not explain the duty and the small canteen in German")

    def rate13() -> str:
        return stack.value("SELECT COUNT(*) FROM llx_c_tva as t INNER JOIN llx_c_country as c ON c.rowid = t.fk_pays "
                           "WHERE c.code = 'AT' AND t.taux = 13")

    expect(rate13() == "0", "Dolibarr already had 13 % for Austria, the test proves nothing")
    page = page_ok(browser.get("/custom/vereine/admin/taxprofiles.php"), "tax profiles without 13 %")
    expect('data-vat13="missing"' in page.text, "the tax profile setup does not say that 13 % is missing")
    page_ok(browser.submit(page.form(name="vereinevat13")), "add 13 %")
    expect(rate13() == "1", f"adding 13 % created {rate13()} entries")
    page = page_ok(browser.get("/custom/vereine/admin/taxprofiles.php"), "tax profiles with 13 %")
    expect('data-vat13="present"' in page.text and 'name="vereinevat13"' not in page.text, "the 13 % hint stays after adding it")
    page_ok(browser.post("/custom/vereine/admin/taxprofiles.php", [("token", token_of(page)), ("action", "addvat13")]), "add 13 % again")
    expect(rate13() == "1", f"adding 13 % a second time left {rate13()} entries")
    return "canteen needs a cash register (cash shared out per sphere, transfer left out), fees no topic, 13 % added exactly once"


def day_after(date: str) -> str:
    return (datetime.date.fromisoformat(date) + datetime.timedelta(days=1)).isoformat()


def website(stack: Stack) -> str:
    """A website user with two rights reads member summaries and finds members, but nothing else of Dolibarr."""
    key = secrets.token_hex(20)
    data = stack.php_fixture("website", RT_WEBSITE_KEY=key)
    members, refs, dates, invoices = data["members"], data["refs"], data["dates"], data["invoices"]
    stack.notes["website"] = {"key": key, **data}

    def summary(member: str, who: str = key) -> dict:
        status, body = stack.api(f"vereine/members/{members[member]}/summary", who)
        expect(status == 200 and isinstance(body, dict), f"summary of {member} answered HTTP {status}: {body}")
        return body

    answers = {member: summary(member) for member in members}
    paid = answers["paid"]
    expect((paid["id"], paid["ref"], paid["firstname"], paid["lastname"], paid["company"], paid["type"]["label"], paid["status"])
           == (members["paid"], refs["paid"], "Paula", "Bezahlt", "", "Beitragspflichtig", "active"), f"paid member: {paid}")
    expect(paid["member_since"] == dates["paid_since"] and paid["paid_until"] == dates["paid_until"] and paid["currency"] == "EUR",
           f"paid member since {paid['member_since']} until {paid['paid_until']}, expected {dates['paid_since']} to {dates['paid_until']}")
    expect(paid["fee"] == {"required": True, "status": "paid", "next_due": day_after(dates["paid_until"]), "amount": 50, "discount": {"kind": "none", "label": ""}, "payer": "self", "payment_url": ""},
           f"fee of the paid member: {paid['fee']}")
    expect(paid["open_invoices"] == [{"id": invoices["open"]["id"], "ref": invoices["open"]["ref"], "type": "standard",
                                      "date": dates["open_invoice"], "due_date": dates["open_invoice"], "total": 60, "remaining": 50,
                                      "status": "overdue", "overdue": True, "payment_url": "", "fee": False}],
           f"only the validated unpaid invoice with its part payment is open: {paid['open_invoices']}")

    expired = answers["expired"]
    expect(expired["status"] == "active" and expired["paid_until"] == dates["expired_until"] and expired["member_since"] == dates["expired_since"]
           and expired["fee"]["status"] == "due" and expired["fee"]["next_due"] == day_after(dates["expired_until"]),
           f"expired member: {expired}")
    unpaid = answers["unpaid"]
    expect(unpaid["paid_until"] == "" and unpaid["member_since"] == dates["today"]
           and unpaid["fee"] == {"required": True, "status": "due", "next_due": dates["today"], "amount": 50, "discount": {"kind": "none", "label": ""}, "payer": "self", "payment_url": ""},
           f"member who never paid: {unpaid}")
    free = answers["free"]
    expect(free["type"]["label"] == "Ordentliches Mitglied"
           and free["fee"] == {"required": False, "status": "not_required", "next_due": "", "amount": None, "discount": {"kind": "none", "label": ""}, "payer": "self", "payment_url": ""},
           f"member type without fee: {free}")
    terminated = answers["terminated"]
    expect(terminated["status"] == "terminated" and terminated["fee"]["status"] == "inactive" and terminated["fee"]["next_due"] == "",
           f"terminated member: {terminated}")
    text = json.dumps(answers, ensure_ascii=False)
    leaked = [secret for secret in ("Geheim", "1990", "999999", "@runtime-verein.test", "Innsbruck") if secret in text]
    expect(not leaked, f"member summaries contain private data: {leaked}")

    def lookup(query: dict, expected: int) -> object:
        status, body = stack.api("vereine/members/lookup?" + urllib.parse.urlencode(query), key)
        expect(status == expected, f"lookup {query} answered HTTP {status}, expected {expected}: {body}")
        return body

    expect(lookup({"ref": refs["paid"]}, 200)["id"] == members["paid"], "lookup by member number found someone else")
    expect(lookup({"email": "paula.bezahlt@RUNTIME-verein.test"}, 200)["id"] == members["paid"],
           "lookup by e-mail does not ignore upper and lower case")
    # Dolibarr's API layer checks a parameter named email itself, before the module's code runs.
    lookup({"email": " paula.bezahlt@runtime-verein.test "}, 400)
    lookup({"email": "keine-adresse"}, 400)
    lookup({"email": "familie@runtime-verein.test"}, 409)
    lookup({"email": "niemand@runtime-verein.test"}, 404)
    lookup({"ref": "RT-GIBT-ES-NICHT"}, 404)
    lookup({}, 400)
    lookup({"ref": refs["paid"], "email": "paula.bezahlt@runtime-verein.test"}, 400)
    status, _ = stack.api("vereine/members/999999/summary", key)
    expect(status == 404, f"an unknown member answered HTTP {status}, expected 404")

    for path in ("members", "invoices", "thirdparties"):
        status, _ = stack.api(path, key)
        expect(status == 403, f"the website user reads Dolibarr's {path} with HTTP {status}, expected 403")
    for who, name in ((stack.reader_key, "a user who may read members and invoices but lacks the website right"),
                      (stack.nobody_key, "a user without rights")):
        status, _ = stack.api(f"vereine/members/{members['paid']}/summary", who)
        expect(status == 403, f"{name} got HTTP {status} for a summary, expected 403")
        status, _ = stack.api("vereine/members/lookup?ref=" + urllib.parse.quote(refs["paid"]), who)
        expect(status == 403, f"{name} got HTTP {status} for a lookup, expected 403")

    stack.php_fixture("onlinepayment", RT_ONLINE="1")
    try:
        unpaid, paid = summary("unpaid"), summary("paid")
    finally:
        stack.php_fixture("onlinepayment", RT_ONLINE="0")
    fee_link = unpaid["fee"]["payment_url"]
    expect("/public/payment/newpayment.php?source=member" in fee_link and "amount=50" in fee_link
           and f"ref={urllib.parse.quote(refs['unpaid'])}" in fee_link, f"payment link for the due fee: {fee_link!r}")
    invoice_link = paid["open_invoices"][0]["payment_url"]
    expect(f"/public/payment/newpayment.php?source=invoice&ref={urllib.parse.quote(invoices['open']['ref'])}" in invoice_link
           and paid["fee"]["payment_url"] == "", f"payment links of the paid member: fee {paid['fee']['payment_url']!r}, invoice {invoice_link!r}")
    return ("paid, expired, never paid, no fee, terminated; lookup by number and e-mail (409, 404, 400); payment links with Stripe; "
            "website user refused by Dolibarr's own API")


def websiteinvoices(stack: Stack) -> str:
    """A website user lists a member's invoices and gets the PDFs of that member's invoices only."""
    site = stack.notes["website"]
    key, members, invoices, dates = site["key"], site["members"], site["invoices"], site["dates"]
    abandoned = stack.php_fixture("websiteinvoices", RT_MEMBER_ID=str(members["paid"]))["abandoned"]
    listed = [
        {"id": abandoned["id"], "ref": abandoned["ref"], "type": "standard", "date": abandoned["date"], "due_date": abandoned["date"],
         "total": 20, "remaining": 0, "status": "abandoned", "overdue": False, "payment_url": "", "fee": False},
        {"id": invoices["paid"]["id"], "ref": invoices["paid"]["ref"], "type": "standard", "date": dates["paid_invoice"],
         "due_date": dates["paid_invoice"], "total": 30, "remaining": 0, "status": "paid", "overdue": False, "payment_url": "", "fee": False},
        {"id": invoices["open"]["id"], "ref": invoices["open"]["ref"], "type": "standard", "date": dates["open_invoice"],
         "due_date": dates["open_invoice"], "total": 60, "remaining": 50, "status": "overdue", "overdue": True, "payment_url": "", "fee": False},
    ]
    base = f"vereine/members/{members['paid']}/invoices"
    status, body = stack.api(base, key)
    expect(status == 200 and body == listed, f"invoices of the paid member, newest first and without the draft: HTTP {status} {body}")
    for query, expected in (("limit=1&page=1", listed[1:2]), ("limit=2&page=1", listed[2:]), ("limit=2&page=5", [])):
        status, body = stack.api(f"{base}?{query}", key)
        expect(status == 200 and body == expected, f"invoices with {query}: HTTP {status} {body}")
    for query in ("limit=0", "limit=101", "page=-1"):
        status, _ = stack.api(f"{base}?{query}", key)
        expect(status == 400, f"invoices with {query} answered HTTP {status}, expected 400")
    status, body = stack.api(f"vereine/members/{members['unpaid']}/invoices", key)
    expect(status == 200 and body == [], f"a member without third party has no invoices: HTTP {status} {body}")
    status, _ = stack.api("vereine/members/999999/invoices", key)
    expect(status == 404, f"invoices of an unknown member answered HTTP {status}, expected 404")

    def download(invoice: dict) -> tuple[bytes, str]:
        status, pdf = stack.api(f"{base}/{invoice['id']}/pdf", key)
        expect(status == 200 and isinstance(pdf, dict), f"PDF of {invoice['ref']} answered HTTP {status}: {str(pdf)[:300]}")
        content = base64.b64decode(pdf["content"])
        expect(content.startswith(b"%PDF") and pdf["filesize"] == len(content) and pdf["filename"] == f"{invoice['ref']}.pdf",
               f"PDF of {invoice['ref']}: {pdf['filename']}, {pdf['filesize']} bytes, starts with {content[:8]!r}")
        stored = f"/var/www/documents/facture/{invoice['ref']}/{invoice['ref']}.pdf"
        on_disk = stack.shell(f"sha256sum '{stored}' && stat -c %Y '{stored}'")
        expect(on_disk.returncode == 0 and on_disk.stdout.split()[0] == hashlib.sha256(content).hexdigest(),
               f"the PDF of {invoice['ref']} differs from {stored}: {on_disk.stdout.strip() or on_disk.stderr.strip()}")
        return content, on_disk.stdout.split()[-1]

    # The abandoned invoice never got a payment, so Dolibarr never built its PDF.
    stored = f"/var/www/documents/facture/{abandoned['ref']}/{abandoned['ref']}.pdf"
    expect(stack.shell(f"test ! -e '{stored}'").returncode == 0, f"{stored} exists before the download, the test proves nothing")
    content, _ = download(abandoned)
    expect(abandoned["ref"] in pdf_bytes_text(content), f"the built PDF does not show the invoice number {abandoned['ref']}")
    # Backdate the stored file: a second build would give it a new modification time.
    expect(stack.shell(f"touch -d '2000-01-01 00:00:00' '{stored}'").returncode == 0, f"could not backdate {stored}")
    again, mtime = download(abandoned)
    expect(again == content and int(mtime) < 1000000000, "the second download built the PDF again instead of returning the stored one")
    # Dolibarr built the open invoice's PDF when the part payment was booked; the download returns that file.
    content, _ = download(invoices["open"])
    expect(invoices["open"]["ref"] in pdf_bytes_text(content), f"the PDF does not show the invoice number {invoices['open']['ref']}")
    ref = invoices["open"]["ref"]

    foreign = stack.notes["invoice_2026"]
    for path, what in ((f"{base}/{foreign}/pdf", "an invoice of another third party"),
                       (f"{base}/{invoices['draft']['id']}/pdf", "a draft of the member"),
                       (f"{base}/99999999/pdf", "an unknown invoice"),
                       (f"vereine/members/{members['expired']}/invoices/{invoices['open']['id']}/pdf", "another member's invoice"),
                       (f"vereine/members/{members['unpaid']}/invoices/{invoices['open']['id']}/pdf", "an invoice for a member without third party")):
        status, _ = stack.api(path, key)
        expect(status == 404, f"the PDF of {what} answered HTTP {status}, expected 404")

    status, _ = stack.api(f"documents/download?modulepart=facture&original_file={ref}/{ref}.pdf", key)
    expect(status == 403, f"the website user downloads through Dolibarr's own documents API with HTTP {status}, expected 403")
    for who, name in ((stack.reader_key, "a user who may read invoices but lacks the website right"), (stack.nobody_key, "a user without rights")):
        for path in (base, f"{base}/{invoices['open']['id']}/pdf"):
            status, _ = stack.api(path, who)
            expect(status == 403, f"{name} got HTTP {status} for {path}, expected 403")

    return ("abandoned, paid and overdue invoice newest first, pages, draft left out; missing PDF built once, stored PDFs returned as stored, "
            "foreign, draft and unknown invoices 404; Dolibarr's documents API 403")


def websitesync(stack: Stack) -> str:
    """A website sync reads all members page by page, and afterwards only those whose summary changed."""
    site = stack.notes["website"]
    key, members, invoices = site["key"], site["members"], site["invoices"]

    def listing(query: str) -> list:
        status, body = stack.api(f"vereine/members?{query}", key)
        expect(status == 200 and isinstance(body, list), f"members with {query} answered HTTP {status}: {str(body)[:300]}")
        return body

    total = int(stack.value("SELECT COUNT(*) FROM llx_adherent WHERE entity = 1"))
    everyone = listing("")
    ids = [member["id"] for member in everyone]
    expect(len(everyone) == total and ids == sorted(ids), f"all {total} members by id, got {ids}")
    paged, page = [], 0
    while page < 50:
        chunk = listing(f"limit=3&page={page}")
        if not chunk:
            break
        paged += chunk
        page += 1
    expect([member["id"] for member in paged] == ids, f"pages of 3 give {[member['id'] for member in paged]}, expected {ids}")

    time.sleep(2)
    status, info = stack.api("vereine/status", key)
    expect(status == 200, f"GET vereine/status answered HTTP {status} for the website user")
    since = info["server_time"]
    expect(all(member["updated_at"] < since for member in everyone), "a member changed after the sync started")
    expect(listing("changed_since=" + urllib.parse.quote(since)) == [], f"members changed since {since} without any change")

    change = stack.php_fixture("websitechange", RT_INVOICE_ID=str(invoices["open"]["id"]), RT_MEMBER_ID=str(members["expired"]))
    changed = listing("changed_since=" + urllib.parse.quote(since))
    expect([member["id"] for member in changed] == sorted([members["paid"], members["expired"]]),
           f"after a part payment for Paula and a new period for Emil the sync returned {[member['id'] for member in changed]}")
    found = {member["id"]: member for member in changed}
    paula, emil = found[members["paid"]], found[members["expired"]]
    expect(paula["open_invoices"][0]["remaining"] == 45 and paula["updated_at"] >= since,
           f"Paula after the part payment: {paula['open_invoices']}, updated {paula['updated_at']}")
    expect(emil["fee"]["status"] == "paid" and emil["paid_until"] == change["paid_until"] and emil["updated_at"] >= since,
           f"Emil after the new period: {emil['fee']}, paid until {emil['paid_until']}, updated {emil['updated_at']}")
    vienna = (datetime.datetime.strptime(since, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=datetime.timezone.utc)
              .astimezone(datetime.timezone(datetime.timedelta(hours=2))).isoformat())
    expect([member["id"] for member in listing("changed_since=" + urllib.parse.quote(vienna))] == [member["id"] for member in changed],
           f"changed_since={vienna} gives other members than {since}")

    # Nina's period ended yesterday; with every stored change backdated, only the date changed her summary today.
    nina = members["unpaid"]
    flip = stack.php_fixture("websiteflip", RT_MEMBER_ID=str(nina))
    stack.sql(f"UPDATE llx_adherent SET tms = '{flip['backdate']}' WHERE rowid = {int(nina)}")
    stack.sql(f"UPDATE llx_subscription SET tms = '{flip['backdate']}' WHERE fk_adherent = {int(nina)}")
    stack.sql(f"UPDATE llx_adherent_extrafields SET tms = '{flip['backdate']}' WHERE fk_object = {int(nina)}")
    stack.sql(f"UPDATE llx_adherent_type SET tms = '{flip['backdate']}' WHERE rowid = {int(stack.value(f'SELECT fk_adherent_type FROM llx_adherent WHERE rowid = {int(nina)}'))}")
    status, summary = stack.api(f"vereine/members/{nina}/summary", key)
    expect(status == 200 and summary["updated_at"] == flip["moment"] and summary["paid_until"] == flip["paid_until"]
           and summary["fee"]["status"] == "due", f"Nina after her period ended yesterday: {summary}")
    after = (datetime.datetime.strptime(flip["moment"], "%Y-%m-%dT%H:%M:%SZ") + datetime.timedelta(seconds=1)).strftime("%Y-%m-%dT%H:%M:%SZ")
    expect(nina in [member["id"] for member in listing("changed_since=" + urllib.parse.quote(flip["moment"]))],
           f"Nina is missing from the sync since {flip['moment']}, when her fee became due")
    expect(nina not in [member["id"] for member in listing("changed_since=" + urllib.parse.quote(after))],
           f"Nina is in the sync since {after}, although nothing changed after her fee became due")

    for query in ("changed_since=gestern", "changed_since=2026-09-17T08:00:00", "limit=0", "limit=101", "page=-1"):
        status, _ = stack.api(f"vereine/members?{query}", key)
        expect(status == 400, f"members with {query} answered HTTP {status}, expected 400")
    for who, name in ((stack.reader_key, "a user who may read members but lacks the website right"), (stack.nobody_key, "a user without rights")):
        status, _ = stack.api("vereine/members", who)
        expect(status == 403, f"{name} got HTTP {status} for the member list, expected 403")
    return (f"all {total} members by id and in pages; nothing changed, nothing listed; a part payment and a new period listed exactly "
            "those two members, also with +02:00; a fee due by the date alone counts from midnight; bad input 400, other users 403")


WEBHOOK_RECEIVER = "<?php\nfile_put_contents('/tmp/rt-webhooks.jsonl', file_get_contents('php://input').\"\\n\", FILE_APPEND | LOCK_EX);\necho 'ok';\n"
EVENT_FIELDS = {"id", "element", "member_id", "cause", "occurred_at", "context"}


def websiteevents(stack: Stack) -> str:
    """Dolibarr's webhooks tell a website which member changed - the id and the cause only - and never block a change."""
    site = stack.notes["website"]
    members, invoices = site["members"], site["invoices"]
    receiver = "/var/www/html/custom/rt-webhook.php"
    encoded = base64.b64encode(WEBHOOK_RECEIVER.encode()).decode()
    expect(stack.shell(f"echo {encoded} | base64 -d > {receiver} && rm -f /tmp/rt-webhooks.jsonl").returncode == 0,
           "could not install the webhook receiver")
    try:
        registered = stack.value("SELECT COUNT(*) FROM llx_c_action_trigger WHERE code = 'VEREINE_MEMBER_CHANGED' AND elementtype = 'member'")
        expect(registered == "1", f"VEREINE_MEMBER_CHANGED is listed {registered} times among Dolibarr's events")
        target = stack.php_fixture("webhook", RT_WEBHOOK_URL="http://127.0.0.1/custom/rt-webhook.php")
        expect(target.get("register_again") == 0, f"registering the event a second time returned {target.get('register_again')}")

        stack.php_fixture("webhookchanges", RT_PAYMENT_INVOICE=str(invoices["open"]["id"]), RT_OTHER_INVOICE=str(stack.notes["invoice_2026"]),
                          RT_SUBSCRIPTION_MEMBER=str(members["expired"]), RT_RESILIATE_MEMBER=str(members["free"]))
        raw = stack.shell("cat /tmp/rt-webhooks.jsonl").stdout
        payloads = [json.loads(line) for line in raw.splitlines() if line.strip()]
        found = {payload["object"]["member_id"]: payload for payload in payloads}
        expect(len(payloads) == 3 and sorted(found) == sorted([members["paid"], members["expired"], members["free"]]),
               f"expected one event each for Paula's payment, Emil's period and Otto's resignation, none for the third party without member: {raw[:800]}")
        for payload in payloads:
            event = payload["object"]
            expect(payload["triggercode"] == "VEREINE_MEMBER_CHANGED" and set(event) == EVENT_FIELDS and event["id"] == event["member_id"]
                   and re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", event["occurred_at"] or ""),
                   f"webhook payload: {payload}")
        causes = {member: found[members[member]]["object"]["cause"] for member in ("paid", "expired", "free")}
        expect(causes["paid"] == "PAYMENT_CUSTOMER_CREATE" and causes["free"] == "MEMBER_RESILIATE"
               and causes["expired"] in ("MEMBER_SUBSCRIPTION_CREATE", "MEMBER_MODIFY"), f"causes: {causes}")
        leaked = [secret for secret in ("Geheim", "1990", "@runtime-verein.test", "Innsbruck", "Bezahlt", "999999") if secret in raw]
        expect(not leaked, f"the webhook payloads contain personal data: {leaked}")
        history = ""
        # Dolibarr 22 has the history table already, but its webhook trigger writes nothing into it.
        if not stack.version.startswith("22."):
            stored = stack.sql("SELECT trigger_data FROM llx_webhook_history WHERE trigger_code = 'VEREINE_MEMBER_CHANGED'")
            leaked = [secret for secret in ("Geheim", "1990", "@runtime-verein.test", "Innsbruck") if any(secret in row[0] for row in stored)]
            expect(len(stored) == 3 and not leaked, f"Dolibarr's webhook history holds {len(stored)} events, personal data: {leaked}")
            history = "; Dolibarr's webhook history holds the same 3 slim events"

        down = stack.php_fixture("webhookdown", RT_MEMBER_ID=str(members["paid"]))
        expect(int(down.get("update", 0)) > 0, f"a blocking webhook that cannot be reached made the member update fail: {down}")
        phone = stack.value(f"SELECT phone_mobile FROM llx_adherent WHERE rowid = {int(members['paid'])}")
        expect(phone == "+43 660 0000000", f"the member update was not stored while the webhook was down: {phone!r}")
    finally:
        stack.shell(f"rm -f {receiver}")
    return ("one event each for a payment, a subscription period and a resignation, none for a third party without member; "
            "only member id, cause and moment are sent" + history + "; an unreachable blocking webhook does not stop a member update")


def fees(stack: Stack) -> str:
    """The fee model is set on Dolibarr's member type card; the setup page and the API show what joining today costs."""
    site = stack.notes["website"]
    key, today = site["key"], site["dates"]["today"]
    fields = stack.value("SELECT COUNT(*) FROM llx_extrafields WHERE elementtype = 'adherent_type' AND name LIKE 'vereine%'")
    expect(fields == "4", f"{fields} fee fields on member types, expected 4")
    fee_type = int(stack.value("SELECT rowid FROM llx_adherent_type WHERE libelle = 'Beitragspflichtig'"))
    free_type = int(stack.value("SELECT rowid FROM llx_adherent_type WHERE libelle = 'Ordentliches Mitglied'"))
    product = stack.value("SELECT rowid FROM llx_product WHERE ref = 'RT-BEITRAG'")

    browser = stack.browser()
    setup = page_ok(browser.get("/custom/vereine/admin/fees.php"), "fee setup")
    links = [html.unescape(href) for href in re.findall(r'href="([^"]*/adherents/type\.php\?[^"]*)"', setup.text)]
    edit = next((link for link in links if re.search(rf"[?&]rowid={fee_type}(&|$)", link) and "action=edit" in link), None)
    expect(edit is not None, f"the fee setup offers no edit link for member type {fee_type}: {links}")
    card = page_ok(browser.get(edit), "Dolibarr's member type card in edit mode")
    form = card.form(action_part=f"rowid={fee_type}")
    for name in ("options_vereine_fee_start_month", "options_vereine_fee_proration", "options_vereine_admission_fee", "options_vereine_fee_product"):
        expect(form.has(name), f"Dolibarr's member type card does not offer {name}")
    year, month, day = (int(part) for part in today.split("-"))

    def save_and_example(proration: str) -> tuple[dict, str]:
        card = page_ok(browser.get(edit), f"member type card for {proration}")
        page_ok(browser.submit(card.form(action_part=f"rowid={fee_type}"),
                               {"duration_value": "1", "duration_unit": "y", "options_vereine_fee_start_month": "1",
                                "options_vereine_fee_proration": proration, "options_vereine_admission_fee": "20",
                                "options_vereine_fee_product": product}), f"save the fee model by {proration}")
        stored = stack.sql(f"SELECT vereine_fee_start_month, vereine_fee_proration, vereine_admission_fee, vereine_fee_product "
                           f"FROM llx_adherent_type_extrafields WHERE fk_object = {fee_type}")
        expect(stored and stored[0][0] == "1" and stored[0][1] == proration and float(stored[0][2]) == 20 and stored[0][3] == product,
               f"fee model stored on the member type: {stored}")
        page = page_ok(browser.get("/custom/vereine/admin/fees.php"), f"fee setup by {proration}")
        found = re.search(rf'<tr class="oddeven" data-fee-type="{fee_type}">(.*?)</tr>', page.text, re.S)
        expect(found is not None, f"the fee setup shows no row for member type {fee_type}")
        values = dict(re.findall(r'data-fee-(example|start|end|amount|admission|proration)="([^"]*)"', found.group(1)))
        expect(values.get("proration") == proration, f"the fee setup shows proration {values.get('proration')!r}, expected {proration}")
        return values, found.group(1)

    # By half-year: joining in the first half pays in full, in the second half the half.
    example, _ = save_and_example("half_year")
    half_reason, half_amount = ("first_part_full", 50.0) if month <= 6 else ("prorated", 25.0)
    if (month, day) == (1, 1):
        half_reason = "full"
    expect(example.get("example") == half_reason and float(example.get("amount") or -1) == half_amount,
           f"joining today by half-year costs {example}, expected {half_reason} {half_amount}")

    months = 13 - month
    reason, amount = ("full", 50.0) if (month, day) == (1, 1) else (("first_part_full", 50.0) if month == 1 else ("prorated", round(50 * months / 12, 2)))
    example, row_html = save_and_example("month")
    expect(example.get("example") == reason and example.get("start") == today and example.get("end") == f"{year}-12-31"
           and float(example.get("amount") or -1) == amount and float(example.get("admission") or -1) == 20,
           f"joining today costs {example}, expected {reason} {amount} until {year}-12-31 plus 20")
    text = html.unescape(row_html)
    expect("RT-BEITRAG" in text and "Steuerprofil" in text, "the fee setup does not show the fee product and its tax profile")
    expect(f'data-fee-type="{free_type}"' in setup.text and 'data-fee-example="none"' in setup.text,
           "the member type without fee is not shown as such")
    expect(denied(stack.browser("rtreader").get("/custom/vereine/admin/fees.php")), "a non-administrator opens the fee setup")

    status, body = stack.api("vereine/membershipfees", key)
    expect(status == 200 and isinstance(body, list), f"GET vereine/membershipfees answered HTTP {status}: {body}")
    found = {entry["id"]: entry for entry in body}
    paying, free = found.get(fee_type, {}), found.get(free_type, {})
    expect({k: paying.get(k) for k in ("amount", "duration", "year_starts_month", "prorated", "proration", "admission_fee", "subscription_required", "currency")}
           == {"amount": 50, "duration": {"value": 1, "unit": "y"}, "year_starts_month": 1, "prorated": True, "proration": "month", "admission_fee": 20,
               "subscription_required": True, "currency": "EUR"}, f"fee of the paying member type: {paying}")
    expect(free.get("subscription_required") is False and free.get("amount") is None and free.get("admission_fee") == 0,
           f"member type without fee: {free}")
    for who, name in ((stack.reader_key, "a user without the website right"), (stack.nobody_key, "a user without rights")):
        status, _ = stack.api("vereine/membershipfees", who)
        expect(status == 403, f"{name} got HTTP {status} for the membership fees, expected 403")
    return (f"fee model saved on Dolibarr's member type card; joining today by half-year {half_amount} €, by month {reason} {amount} € "
            "until 31 December plus 20 € admission, "
            "fee product with tax profile shown; API for the website; non-administrators and users without right refused")


def feerun(stack: Stack) -> str:
    """A fee run previews the fees due, creates subscription period and linked invoice once, and nothing on a second run."""
    site = stack.notes["website"]
    key, members, today = site["key"], site["members"], site["dates"]["today"]
    fiona = int(stack.php_fixture("feerunmember")["member"])
    nina = int(members["unpaid"])
    year, month, day = (int(part) for part in today.split("-"))
    fee = 50.0 if (month, day) == (1, 1) else round(50 * (13 - month) / 12, 2)
    fiona_key, nina_key = f"{fiona}:{today}", f"{nina}:{today}"

    def fee_invoices() -> list[list[str]]:
        return stack.sql("SELECT f.rowid, f.total_ttc, f.fk_statut, s.fk_adherent, DATE(s.dateadh), DATE(s.datef) "
                         "FROM llx_element_element as ee INNER JOIN llx_facture as f ON f.rowid = ee.fk_target "
                         "INNER JOIN llx_subscription as s ON s.rowid = ee.fk_source "
                         "WHERE ee.sourcetype = 'subscription' AND ee.targettype = 'facture' ORDER BY s.fk_adherent")

    browser = stack.browser()
    menu = page_ok(browser.get("/adherents/index.php?mainmenu=members&leftmenu="), "Members home")
    expect("/custom/vereine/fees_run.php" in menu.text, "the Members menu has no entry Fee run")
    preview = page_ok(browser.get(f"/custom/vereine/fees_run.php?dueuntil={today}"), "fee run preview")
    rows = {row: (status, total) for row, status, total in
            re.findall(r'data-fee-row="([^"]+)" data-status="([a-z_]+)" data-total="([^"]*)"', preview.text)}
    expect({row.split(":")[0] for row in rows} == {str(fiona), str(nina)},
           f"only Fiona (first fee) and Nina (period ended yesterday) are due today, preview lists {sorted(rows)}")
    expect(rows.get(fiona_key, ("", ""))[0] == "ready" and float(rows[fiona_key][1]) == round(fee + 20, 2),
           f"Fiona's first fee: {rows.get(fiona_key)}, expected ready {fee} plus 20 admission")
    expect(rows.get(nina_key, ("", ""))[0] == "no_partner" and float(rows[nina_key][1]) == fee,
           f"Nina's fee without third party: {rows.get(nina_key)}, expected no_partner {fee}")
    expect(fee_invoices() == [], "fee invoices exist before the run, the test proves nothing")

    reader = stack.browser("rtreader")
    reader_page = page_ok(reader.get(f"/custom/vereine/fees_run.php?dueuntil={today}"), "fee run preview for a reader")
    expect('name="fees[]"' not in reader_page.text, "a user without the rights to create invoices is offered the fee run")
    refused = reader.post("/custom/vereine/fees_run.php", [("token", token_of(reader_page)), ("action", "run"), ("dueuntil", today),
                                                           ("typeid", "0"), ("fees[]", fiona_key)])
    expect(denied(refused) and fee_invoices() == [], "a user without the rights to create invoices ran the fee run")

    fields = [("token", token_of(preview)), ("action", "run"), ("dueuntil", today), ("typeid", "0"),
              ("fees[]", fiona_key), ("fees[]", nina_key), ("createpartners", "1")]
    result = page_ok(browser.post("/custom/vereine/fees_run.php", fields), "run the fees")
    outcomes = dict((row, outcome) for outcome, row in re.findall(r'data-fee-outcome="([a-z]+)" data-fee-key="([^"]+)"', result.text))
    expect(outcomes == {fiona_key: "created", nina_key: "created"}, f"outcome of the fee run: {outcomes}")
    created = fee_invoices()
    by_member = {int(row[3]): row for row in created}
    expect(len(created) == 2 and all(row[2] == "1" and row[4] == today and row[5] == f"{year}-12-31" for row in created),
           f"two validated invoices linked to periods from today to 31 December: {created}")
    expect(float(by_member[fiona][1]) == round(fee + 20, 2) and float(by_member[nina][1]) == fee,
           f"invoice totals: Fiona {by_member[fiona][1]}, Nina {by_member[nina][1]}")
    ids = ", ".join(row[0] for row in created)
    profiled = stack.value(f"SELECT COUNT(*) FROM llx_facturedet as d INNER JOIN llx_facturedet_extrafields as e ON e.fk_object = d.rowid "
                           f"INNER JOIN llx_vereine_taxprofile as p ON p.rowid = e.vereine_taxprofile "
                           f"WHERE d.fk_facture IN ({ids}) AND p.code = 'MITGLIEDSBEITRAG'")
    expect(profiled == "3", f"{profiled} invoice lines carry the tax profile of the fee product, expected 3 (fee and admission for Fiona, fee for Nina)")
    expect(stack.value(f"SELECT fk_soc FROM llx_adherent WHERE rowid = {nina}") not in (None, "", "NULL", "0"),
           "Nina got no third party although it was asked for")
    logged = dict(stack.sql("SELECT action, COUNT(*) FROM llx_vereine_log WHERE action LIKE 'fee%' GROUP BY action"))
    expect(logged == {"fee_invoice": "2", "fee_run": "1"}, f"fee run log: {logged}")

    again = page_ok(browser.post("/custom/vereine/fees_run.php", fields), "run the same fees again")
    expect('data-fee-outcome="created"' not in again.text and len(fee_invoices()) == 2, "a second run created fee invoices again")
    expect(fiona_key not in again.text.split('data-fee-preview="1"', 1)[-1].split('data-fee-recent="1"', 1)[0],
           "Fiona's paid period is still offered after the run")
    recent = dict(re.findall(r'data-fee-invoice="([^"]+)" data-status="([a-z]+)"', again.text))
    expect(len(recent) == 2 and set(recent.values()) <= {"open", "overdue"}, f"fee invoices listed with their state: {recent}")

    status, invoices = stack.api(f"vereine/members/{fiona}/invoices", key)
    expect(status == 200 and len(invoices) == 1 and invoices[0]["fee"] is True, f"Fiona's invoice through the website API: {invoices}")
    # Dolibarr counts the period as paid once recorded; the summary waits for the invoice.
    status, summary = stack.api(f"vereine/members/{fiona}/summary", key)
    expect(status == 200 and summary["paid_until"] == "" and summary["fee"]["status"] == "invoiced" and summary["fee"]["payment_url"] == ""
           and [invoice["fee"] for invoice in summary["open_invoices"]] == [True],
           f"Fiona with an unpaid fee invoice: paid until {summary.get('paid_until')!r}, fee {summary.get('fee')}")
    status, nina_summary = stack.api(f"vereine/members/{nina}/summary", key)
    expect(status == 200 and nina_summary["paid_until"] < today and nina_summary["fee"]["status"] == "invoiced",
           f"Nina with an unpaid fee invoice keeps her last paid period: {nina_summary.get('paid_until')}, {nina_summary.get('fee')}")
    stack.php_fixture("payinvoice", RT_INVOICE_ID=by_member[fiona][0])
    status, summary = stack.api(f"vereine/members/{fiona}/summary", key)
    expect(status == 200 and summary["paid_until"] == f"{year}-12-31" and summary["fee"]["status"] == "paid" and summary["open_invoices"] == [],
           f"Fiona after paying the fee invoice: paid until {summary.get('paid_until')}, fee {summary.get('fee')}")
    return (f"preview: Fiona {fee} + 20 admission ready, Nina {fee} without third party; reader refused; run created both with "
            "linked periods to 31 December, fee product tax profile on 3 lines, third party for Nina, log; second run created nothing; "
            "website API marks the fee invoice, shows invoiced until it is paid and paid until 31 December afterwards")


def discounts(stack: Stack) -> str:
    """Discount rules by age and with proof, and exemptions, reach the fee run and the website summary."""
    site = stack.notes["website"]
    key, today = site["key"], site["dates"]["today"]
    month = int(today.split("-")[1])

    def prorate(amount: float) -> float:
        return amount if month == 1 else round(amount * (13 - month) / 12, 2)

    browser = stack.browser()
    setup = page_ok(browser.get("/custom/vereine/admin/fees.php"), "fee setup with discounts")
    expect('data-discounts-howto="1"' in setup.text and 'name="vereinediscount"' in setup.text, "the fee setup offers no discounts")
    refused = page_ok(browser.submit(setup.form(name="vereinediscount"), {"label": "Ohne Alter", "kind": "age", "age_from": "", "age_to": "",
                                                                          "type_id": "0", "mode": "free", "value": "", "active": "1"}),
                      "a discount by age without age")
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_fee_discount") == "0" and "mindestens ein Alter" in html.unescape(refused.text),
           "a discount by age without age was stored or not explained")
    for fields in ({"label": "Jugend", "kind": "age", "age_from": "", "age_to": "17", "type_id": "0", "mode": "percent", "value": "50", "active": "1"},
                   {"label": "Studierende", "kind": "proof", "age_from": "", "age_to": "", "type_id": "0", "mode": "amount", "value": "30", "active": "1"}):
        page = page_ok(browser.get("/custom/vereine/admin/fees.php"), "fee setup")
        page_ok(browser.submit(page.form(name="vereinediscount"), fields), f"store discount {fields['label']}")
    stored = stack.sql("SELECT label, kind, age_from, age_to, mode, value FROM llx_vereine_fee_discount ORDER BY position")
    expect([[row[0], row[1], row[2], row[3], row[4], float(row[5])] for row in stored]
           == [["Jugend", "age", "NULL", "17", "percent", 50.0], ["Studierende", "proof", "NULL", "NULL", "amount", 30.0]],
           f"stored discounts: {stored}")
    student_rule = stack.value("SELECT rowid FROM llx_vereine_fee_discount WHERE label = 'Studierende'")

    members = stack.php_fixture("discountmembers", RT_STUDENT_RULE=student_rule)["members"]
    card = page_ok(browser.get(f"/adherents/card.php?rowid={members['honorary']}"), "member card with the exemption")
    card_text = html.unescape(card.text)
    expect("Vom Beitrag befreit" in card_text and "Ehrenmitglied" in card_text and "Nachweis gültig bis" in card_text,
           "Dolibarr's member card does not show exemption and proof fields")

    preview = page_ok(browser.get(f"/custom/vereine/fees_run.php?dueuntil={today}"), "fee run with discounts")
    rows = {}
    for match in re.finditer(r'<tr class="oddeven" data-fee-row="([^"]+)" data-status="([a-z_]+)" data-total="([^"]*)">(.*?)</tr>', preview.text, re.S):
        rows[match.group(1)] = (match.group(2), match.group(3), html.unescape(match.group(4)))
    expected = {"child": prorate(25.0) + 20, "student": prorate(30.0) + 20, "expired": prorate(50.0) + 20, "honorary": 0.0}
    for person, total in expected.items():
        row = rows.get(f"{members[person]}:{today}")
        expect(row is not None and row[0] == "ready" and abs(float(row[1] or -1) - round(total, 2)) < 0.005,
               f"fee of {person} in the preview: {row[:2] if row else None}, expected ready {round(total, 2)}")
    expect('data-discount-kind="age"' in rows[f"{members['child']}:{today}"][2] and "Jugend" in rows[f"{members['child']}:{today}"][2],
           "the child's row does not name the youth discount")
    expect('data-discount-kind="proof"' in rows[f"{members['student']}:{today}"][2], "the student's row does not name the proof discount")
    expect("Nachweis ist abgelaufen" in rows[f"{members['expired']}:{today}"][2] and "data-discount-kind" not in rows[f"{members['expired']}:{today}"][2],
           "the expired proof is not reported or still discounted")
    expect('data-discount-kind="exempt"' in rows[f"{members['honorary']}:{today}"][2], "the honorary member is not shown as exempt")

    fields = [("token", token_of(preview)), ("action", "run"), ("dueuntil", today), ("typeid", "0")]
    fields += [("fees[]", f"{members[person]}:{today}") for person in expected]
    result = page_ok(browser.post("/custom/vereine/fees_run.php", fields), "run the discounted fees")
    outcomes = dict((row, outcome) for outcome, row in re.findall(r'data-fee-outcome="([a-z]+)" data-fee-key="([^"]+)"', result.text))
    expect(sorted(outcomes.values()) == ["created"] * 4 and "Beitragsperiode ohne Rechnung angelegt" in html.unescape(result.text),
           f"outcome of the discounted run: {outcomes}")
    honorary_periods = stack.sql(f"SELECT s.subscription, COUNT(ee.rowid) FROM llx_subscription as s LEFT JOIN llx_element_element as ee "
                                 f"ON ee.fk_source = s.rowid AND ee.sourcetype = 'subscription' WHERE s.fk_adherent = {members['honorary']} GROUP BY s.rowid")
    expect(len(honorary_periods) == 1 and float(honorary_periods[0][0]) == 0 and honorary_periods[0][1] == "0",
           f"the honorary member's period: {honorary_periods}, expected one period of 0 without invoice")
    for person in ("child", "student", "expired"):
        total = stack.value(f"SELECT f.total_ttc FROM llx_subscription as s INNER JOIN llx_element_element as ee ON ee.fk_source = s.rowid "
                            f"AND ee.sourcetype = 'subscription' AND ee.targettype = 'facture' INNER JOIN llx_facture as f ON f.rowid = ee.fk_target "
                            f"WHERE s.fk_adherent = {members[person]}")
        expect(total is not None and abs(float(total) - round(expected[person], 2)) < 0.005, f"invoice of {person}: {total}, expected {round(expected[person], 2)}")
    label = stack.value(f"SELECT d.description FROM llx_facturedet as d INNER JOIN llx_element_element as ee ON ee.fk_target = d.fk_facture "
                        f"AND ee.sourcetype = 'subscription' AND ee.targettype = 'facture' INNER JOIN llx_subscription as s ON s.rowid = ee.fk_source "
                        f"WHERE s.fk_adherent = {members['child']} AND d.subprice > 0 ORDER BY d.rang LIMIT 1")
    expect(label is not None and "Ermäßigung: Jugend" in label, f"the child's invoice line does not name the discount: {label!r}")

    status, honorary = stack.api(f"vereine/members/{members['honorary']}/summary", key)
    expect(status == 200 and honorary["fee"]["status"] == "paid" and honorary["fee"]["amount"] == 0
           and honorary["fee"]["discount"] == {"kind": "exempt", "label": "Ehrenmitglied"},
           f"honorary member through the website API: {honorary.get('fee')}")
    status, child = stack.api(f"vereine/members/{members['child']}/summary", key)
    expect(status == 200 and child["fee"]["amount"] == 25 and child["fee"]["discount"] == {"kind": "age", "label": "Jugend"}
           and child["fee"]["status"] == "invoiced", f"child through the website API: {child.get('fee')}")
    return (f"age rule without age refused; youth 50 % and student 30 € stored; preview and invoices: child {round(expected['child'], 2)}, "
            f"student {round(expected['student'], 2)}, expired proof {round(expected['expired'], 2)} with note, honorary member period without invoice; "
            "discount named on the invoice line and in the website summary")


def families(stack: Stack) -> str:
    """Members with one payer share an invoice to the payer, with a discount per further member or a cap per fee year."""
    site = stack.notes["website"]
    key, today = site["key"], site["dates"]["today"]
    year = today.split("-")[0]
    field = stack.value("SELECT COUNT(*) FROM llx_extrafields WHERE elementtype = 'adherent' AND name = 'vereine_fee_payer' AND type = 'link'")
    expect(field == "1", f"{field} payer fields of type link on members, expected 1")

    browser = stack.browser()
    setup = page_ok(browser.get("/custom/vereine/admin/fees.php"), "fee setup with families")
    expect('data-families-howto="1"' in setup.text and 'name="vereinefamily"' in setup.text, "the fee setup offers no family rule")
    refused = page_ok(browser.submit(setup.form(name="vereinefamily"), {"family_mode": "percent", "family_value": "100"}), "a family discount of 100 %")
    expect(stack.const("VEREINE_FEE_FAMILY_MODE") is None and "Prozent zwischen 0 und 100 eintragen." in html.unescape(refused.text),
           "a family discount of 100 % was stored or not explained")
    page = page_ok(browser.get("/custom/vereine/admin/fees.php"), "fee setup")
    page_ok(browser.submit(page.form(name="vereinefamily"), {"family_mode": "percent", "family_value": "20"}), "store a family discount of 20 %")
    stored = (stack.const("VEREINE_FEE_FAMILY_MODE"), stack.const("VEREINE_FEE_FAMILY_VALUE"))
    expect(stored == ("percent", "20"), f"stored family rule: {stored}")

    family = stack.php_fixture("familymembers")
    members, payer, type_id = family["members"], int(family["payer"]), int(family["type"])
    listed = page_ok(browser.get("/custom/vereine/admin/fees.php"), "fee setup with a family")
    count = re.search(rf'data-family="{payer}" data-members="(\d+)"', listed.text)
    expect(count is not None and count.group(1) == "3", f"Petra's family is not listed with 3 members: {count.group(1) if count else None}")
    card = page_ok(browser.get(f"/adherents/card.php?rowid={members['paul']}"), "Paul's member card")
    expect("Beiträge zahlt" in html.unescape(card.text) and re.search(rf"socid={payer}(&|\")", card.text) is not None,
           "Dolibarr's member card does not show who pays Paul's fees")

    def key_of(person: str) -> str:
        return f"{members[person]}:{today}"

    def preview_rows() -> tuple[Page, dict]:
        page = page_ok(browser.get(f"/custom/vereine/fees_run.php?dueuntil={today}&typeid={type_id}"), "fee run for the family type")
        rows = {}
        for match in re.finditer(r'<tr class="oddeven" data-fee-row="([^"]+)" data-status="([a-z_]+)" data-total="([^"]*)">(.*?)</tr>', page.text, re.S):
            rows[match.group(1)] = (match.group(2), match.group(3), html.unescape(match.group(4)))
        return page, rows

    def run(page: Page, keys: list[str]) -> dict:
        fields = [("token", token_of(page)), ("action", "run"), ("dueuntil", today), ("typeid", str(type_id))] + [("fees[]", row) for row in keys]
        result = page_ok(browser.post("/custom/vereine/fees_run.php", fields), "run the family fees")
        return dict((row, outcome) for outcome, row in re.findall(r'data-fee-outcome="([a-z]+)" data-fee-key="([^"]+)"', result.text))

    def fee_invoice(member: int) -> list[str] | None:
        rows = stack.sql(f"SELECT f.rowid, f.fk_soc, f.total_ttc, f.fk_statut FROM llx_subscription as s INNER JOIN llx_element_element as ee "
                         f"ON ee.fk_source = s.rowid AND ee.sourcetype = 'subscription' AND ee.targettype = 'facture' "
                         f"INNER JOIN llx_facture as f ON f.rowid = ee.fk_target WHERE s.fk_adherent = {int(member)}")
        return rows[0] if len(rows) == 1 else None

    # Petra pays in full; Pia 20 % less; Paul first his youth discount of 50 %, then 20 % less.
    preview, rows = preview_rows()
    expected = {"petra": 60.0, "pia": 48.0, "paul": 24.0}
    for person, total in expected.items():
        row = rows.get(key_of(person))
        expect(row is not None and row[0] == "ready" and abs(float(row[1] or -1) - total) < 0.005,
               f"fee of {person} in the family preview: {row[:2] if row else None}, expected ready {total}")
    expect("data-family-kind" not in rows[key_of("petra")][2] and "data-payer" not in rows[key_of("petra")][2],
           "Petra, who pays the highest fee for herself, is shown with a family discount or a payer")
    for person in ("pia", "paul"):
        expect('data-family-kind="percent"' in rows[key_of(person)][2] and f'data-payer="{payer}"' in rows[key_of(person)][2]
               and "Rechnung an" in rows[key_of(person)][2], f"{person}'s row does not name the family discount and the payer")
    expect('data-discount-kind="age"' in rows[key_of("paul")][2], "Paul's youth discount is not named")
    nora = rows.get(key_of("nora"))
    expect(nora is not None and nora[0] == "no_payer" and f'value="{key_of("nora")}"' not in preview.text,
           f"Nora with a deleted payer: {nora[:2] if nora else None}, expected no_payer without checkbox")

    outcomes = run(preview, [key_of(person) for person in expected] + [key_of("nora")])
    expect(outcomes == {key_of("petra"): "created", key_of("pia"): "created", key_of("paul"): "created", key_of("nora"): "skipped"},
           f"outcome of the family run: {outcomes}")
    invoices = {person: fee_invoice(members[person]) for person in expected}
    expect(all(invoices.values()) and len({row[0] for row in invoices.values()}) == 1, f"the family's periods are not linked to one invoice: {invoices}")
    invoice = invoices["petra"]
    expect(invoice[1] == str(payer) and float(invoice[2]) == 132 and invoice[3] == "1",
           f"family invoice: third party {invoice[1]}, total {invoice[2]}, status {invoice[3]}; expected {payer}, 132, validated")
    lines = stack.sql(f"SELECT description, total_ttc FROM llx_facturedet WHERE fk_facture = {int(invoice[0])} ORDER BY rang")
    paul_line = next((line[0] for line in lines if line[0].startswith("Paul Familie:")), "")
    expect(sorted(float(line[1]) for line in lines) == [24.0, 48.0, 60.0] and "Ermäßigung: Jugend" in paul_line and "Familienermäßigung 20 %" in paul_line,
           f"lines of the family invoice: {lines}")

    status, paul = stack.api(f"vereine/members/{members['paul']}/summary", key)
    expect(status == 200 and paul["fee"]["payer"] == "other" and paul["fee"]["status"] == "invoiced" and paul["fee"]["payment_url"] == ""
           and paul["open_invoices"] == [], f"Paul through the website API: {paul.get('fee')}, open invoices {paul.get('open_invoices')}")
    status, petra = stack.api(f"vereine/members/{members['petra']}/summary", key)
    expect(status == 200 and petra["fee"]["payer"] == "self"
           and [(entry["id"], entry["total"], entry["fee"]) for entry in petra["open_invoices"]] == [(int(invoice[0]), 132, True)],
           f"Petra through the website API: {petra.get('fee')}, open invoices {petra.get('open_invoices')}")
    status, listed_invoices = stack.api(f"vereine/members/{members['paul']}/invoices", key)
    expect(status == 200 and listed_invoices == [], f"the payer's invoice is listed for Paul: {listed_invoices}")

    status, info = stack.api("vereine/status", key)
    expect(status == 200, f"GET vereine/status answered HTTP {status}")
    stack.php_fixture("payinvoice", RT_INVOICE_ID=invoice[0])
    status, changed = stack.api("vereine/members?changed_since=" + urllib.parse.quote(info["server_time"]), key)
    changed_ids = {entry["id"] for entry in changed} if status == 200 else set()
    expect({members["petra"], members["pia"], members["paul"]} <= changed_ids,
           f"after paying the family invoice the sync lists {sorted(changed_ids)}, expected Petra, Pia and Paul among them")
    status, paul = stack.api(f"vereine/members/{members['paul']}/summary", key)
    expect(status == 200 and paul["fee"]["status"] == "paid" and paul["paid_until"] == f"{year}-12-31",
           f"Paul after the payer paid: {paul.get('fee')}, paid until {paul.get('paid_until')}")

    # Cap of 150 per fee year: 132 are charged already, so Finn, joining today, pays the 18 left.
    page = page_ok(browser.get("/custom/vereine/admin/fees.php"), "fee setup")
    page_ok(browser.submit(page.form(name="vereinefamily"), {"family_mode": "cap", "family_value": "150"}), "store a family cap of 150")
    members["finn"] = int(stack.php_fixture("familychild", RT_PAYER=str(payer))["member"])
    preview, rows = preview_rows()
    finn = rows.get(key_of("finn"))
    expect(finn is not None and finn[0] == "ready" and abs(float(finn[1] or -1) - 18) < 0.005 and 'data-family-kind="cap"' in finn[2],
           f"Finn under the family cap: {finn[:2] if finn else None}, expected ready 18 with the cap named")
    outcomes = run(preview, [key_of("finn")])
    finn_invoice = fee_invoice(members["finn"])
    expect(outcomes == {key_of("finn"): "created"} and finn_invoice is not None and finn_invoice[1] == str(payer) and float(finn_invoice[2]) == 18,
           f"Finn's fee under the cap: {outcomes}, invoice {finn_invoice}")
    finn_line = stack.value(f"SELECT description FROM llx_facturedet WHERE fk_facture = {int(finn_invoice[0])} ORDER BY rang LIMIT 1") or ""
    expect(finn_line.startswith("Finn Familie:") and "Familienhöchstbetrag" in finn_line, f"Finn's invoice line: {finn_line!r}")
    return ("family rule 100 % refused, 20 % stored; family of 3 listed and payer on Dolibarr's member card; one invoice of 132 to the payer "
            "(60 in full, 48, and 24 after youth discount) linked to 3 periods, deleted payer reported and skipped; website API: payer other, "
            "no payment link, invoice only for the payer, payment lists all three and marks them paid; cap 150: a child joining later pays 18")


def exits(stack: Stack) -> str:
    """Exits follow the notice period of the statutes, take effect on their last day and end the fee run there."""
    site = stack.notes["website"]
    key, today = site["key"], site["dates"]["today"]
    day = datetime.date.fromisoformat(today)
    browser = stack.browser()

    setup = page_ok(browser.get("/custom/vereine/admin/fees.php"), "fee setup with the exit rule")
    refused = page_ok(browser.submit(setup.form(name="vereineexitrule"), {"exit_months": "30", "exit_at": "year_end", "exit_start_month": "1"}),
                      "a notice period of 30 months")
    expect(stack.const("VEREINE_EXIT_NOTICE_MONTHS") is None and "von 0 bis 24" in html.unescape(refused.text),
           "a notice period of 30 months was stored or not explained")
    page = page_ok(browser.get("/custom/vereine/admin/fees.php"), "fee setup")
    page_ok(browser.submit(page.form(name="vereineexitrule"), {"exit_months": "3", "exit_at": "year_end", "exit_start_month": "1"}), "store 3 months to the end of the year")
    rule = tuple(stack.const(name) for name in ("VEREINE_EXIT_NOTICE_MONTHS", "VEREINE_EXIT_AT", "VEREINE_EXIT_START_MONTH"))
    expect(rule == ("3", "year_end", "1"), f"stored notice rule: {rule}")
    jobs = stack.value("SELECT COUNT(*) FROM llx_cronjob WHERE label = 'VereineCronExits' AND methodename = 'runDue'")
    expect(jobs == "1", f"{jobs} scheduled jobs for exits, expected 1")

    # Notice today with 3 months to the end of the year: the membership ends on 31 December of the year three months from now.
    month = day.month + 3
    last = f"{day.year + (1 if month > 12 else 0)}-12-31"
    members = stack.php_fixture("exitmembers")["members"]

    def tab(person: str) -> Page:
        return page_ok(browser.get(f"/custom/vereine/member_association.php?id={members[person]}"), f"association tab of {person}")

    def plan(person: str, fields: dict) -> None:
        page_ok(browser.submit(tab(person).form(name="vereineplanexit"), fields), f"record the exit of {person}")

    def stored(person: str) -> list[list[str]]:
        return stack.sql(f"SELECT reason, notice_day, last_day, status FROM llx_vereine_member_exit WHERE fk_adherent = {int(members[person])} ORDER BY rowid")

    def status(person: str) -> str | None:
        return stack.value(f"SELECT statut FROM llx_adherent WHERE rowid = {int(members[person])}")

    expect('data-exit-rule="1"' in tab("karl").text, "the association tab does not explain the notice rule")
    suggested_exit = re.search(r'id="exit_last_day" name="exit_last_day" value="(\d{4}-\d{2}-\d{2})"', tab("karl").text)
    expect(suggested_exit is not None and suggested_exit.group(1) == last,
           f"the exit form suggests {suggested_exit.group(1) if suggested_exit else None} as the last day, expected {last} from the rule")
    plan("karl", {"exit_reason": "resignation", "exit_notice_day": today, "exit_last_day": today, "exit_note": "Brief vom Mitglied"})
    expect(stored("karl") == [["resignation", today, last, "planned"]] and status("karl") == "1",
           f"Karl's notice: {stored('karl')}, member status {status('karl')}; expected planned until {last}, still active")
    expect(f'data-exit="planned" data-last-day="{last}"' in tab("karl").text, "Karl's tab does not show the planned exit")

    after = (datetime.date.fromisoformat(last) + datetime.timedelta(days=1)).isoformat()
    preview = page_ok(browser.get(f"/custom/vereine/fees_run.php?dueuntil={after}"), "fee run beyond Karl's last day")
    starts = [row.split(":", 1)[1] for row in re.findall(r'data-fee-row="([^"]+)"', preview.text) if row.split(":", 1)[0] == str(members["karl"])]
    expect(starts and max(starts) <= last and after not in starts and f'data-exit-last-day="{last}"' in preview.text,
           f"Karl's fees until {after}: periods starting {starts}, expected none after {last}")
    status_code, karl = stack.api(f"vereine/members/{members['karl']}/summary", key)
    expect(status_code == 200 and karl["status"] == "active" and karl["membership_ends"] == last, f"Karl through the website API: {karl}")

    plan("xaver", {"exit_reason": "exclusion", "exit_notice_day": today, "exit_last_day": today, "exit_note": "Beschluss des Vorstands"})
    expect(stored("xaver") == [["exclusion", today, today, "done"]] and status("xaver") == "-2",
           f"Xaver's exclusion today: {stored('xaver')}, member status {status('xaver')}; expected done and excluded at once")
    status_code, xaver = stack.api(f"vereine/members/{members['xaver']}/summary", key)
    expect(status_code == 200 and xaver["status"] == "excluded" and xaver["membership_ends"] == today, f"Xaver through the website API: {xaver}")

    # Lena's last day has come: the scheduled job sets her to resiliated, Karl's exit still waits.
    plan("lena", {"exit_reason": "resignation", "exit_notice_day": today, "exit_last_day": today, "exit_note": ""})
    yesterday = (day - datetime.timedelta(days=1)).isoformat()
    stack.sql(f"UPDATE llx_vereine_member_exit SET notice_day = '{yesterday}', last_day = '{yesterday}' WHERE fk_adherent = {int(members['lena'])}")
    expect('name="vereinecarryoutexit"' in tab("lena").text, "a due exit offers no button to take effect now")
    job = stack.php_fixture("runexits")
    expect(job.get("result") == 0 and status("lena") == "0" and stored("lena")[0][3] == "done" and status("karl") == "1" and stored("karl")[0][3] == "planned",
           f"scheduled job: {job}; Lena {status('lena')} {stored('lena')}, Karl {status('karl')} {stored('karl')}")
    logged = stack.value(f"SELECT COUNT(*) FROM llx_vereine_log WHERE action = 'exit_done' AND fk_adherent IN ({int(members['xaver'])}, {int(members['lena'])})")
    expect(logged == "2", f"{logged} exits logged as done, expected 2")

    page_ok(browser.submit(tab("karl").form(name="vereinecancelexit")), "take back Karl's exit")
    status_code, karl = stack.api(f"vereine/members/{members['karl']}/summary", key)
    expect(stored("karl")[0][3] == "cancelled" and status("karl") == "1" and status_code == 200 and karl["membership_ends"] == "",
           f"Karl after taking back: {stored('karl')}, status {status('karl')}, membership ends {karl.get('membership_ends')!r}")
    return (f"30 months refused, 3 months to the year end stored, scheduled job registered; notice today ends on {last}, fee run stops there, "
            "API shows membership_ends; exclusion today takes effect at once; a due exit is carried out by the scheduled job; taking back clears it")


def sepa(stack: Stack) -> str:
    """The fee run requests a SEPA direct debit only for a payer with a valid mandate and puts the pre-notification on the invoice."""
    today = stack.notes["website"]["dates"]["today"]
    browser = stack.browser()
    setup = page_ok(browser.get("/custom/vereine/admin/fees.php"), "fee setup without direct debit module")
    expect('data-sepa-howto="1"' in setup.text and 'data-sepa-module="off"' in setup.text, "the fee setup does not explain that the direct debit module is off")
    preview = page_ok(browser.get(f"/custom/vereine/fees_run.php?dueuntil={today}"), "fee run without direct debit module")
    expect('name="directdebit"' not in preview.text, "the fee run offers direct debits while the module is off")

    fixture = stack.php_fixture("sepamembers")
    members, accounts = fixture["members"], fixture["accounts"]
    setup = page_ok(browser.get("/custom/vereine/admin/fees.php"), "fee setup with direct debit module")
    expect('data-sepa-module="off"' not in setup.text and 'data-sepa-ics="missing"' not in setup.text, "the fee setup still warns after enabling direct debits")
    refused = page_ok(browser.submit(setup.form(name="vereinesepa"), {"sepa_notice_days": "70"}), "70 days of pre-notification")
    expect(stack.const("VEREINE_SEPA_NOTICE_DAYS") is None and "von 1 bis 60" in html.unescape(refused.text), "70 days were stored or not explained")
    page = page_ok(browser.get("/custom/vereine/admin/fees.php"), "fee setup")
    page_ok(browser.submit(page.form(name="vereinesepa"), {"sepa_notice_days": "10"}), "store 10 days of pre-notification")
    expect(stack.const("VEREINE_SEPA_NOTICE_DAYS") == "10", f"stored days: {stack.const('VEREINE_SEPA_NOTICE_DAYS')}")

    preview = page_ok(browser.get(f"/custom/vereine/fees_run.php?dueuntil={today}"), "fee run with direct debit module")
    rows = {}
    for match in re.finditer(r'<tr class="oddeven" data-fee-row="([^"]+)" data-status="([a-z_]+)" data-total="([^"]*)">(.*?)</tr>', preview.text, re.S):
        rows[match.group(1)] = (match.group(2), match.group(3), html.unescape(match.group(4)))
    keys = {person: f"{members[person]}:{today}" for person in members}
    expect(all(rows.get(key, ("",))[0] == "ready" for key in keys.values()), f"the three members are not ready: {[rows.get(key, ('',))[:2] for key in keys.values()]}")
    expect('data-sepa="valid"' in rows[keys["valid"]][2] and "RT-MANDAT-GUELTIG" in rows[keys["valid"]][2], "the valid mandate is not shown")
    expect('data-sepa="expired"' in rows[keys["expired"]][2], "the mandate unused for 40 months is not reported as expired")
    expect("data-sepa" not in rows[keys["none"]][2], "a member without mandate is shown with one")
    expect(re.search(r'name="directdebit" value="1" checked', preview.text) is not None, "the fee run does not offer direct debits")

    fields = [("token", token_of(preview)), ("action", "run"), ("dueuntil", today), ("typeid", "0"), ("directdebit", "1")]
    fields += [("fees[]", key) for key in keys.values()]
    result = page_ok(browser.post("/custom/vereine/fees_run.php", fields), "run the fees with direct debit")
    outcomes = dict((row, outcome) for outcome, row in re.findall(r'data-fee-outcome="([a-z]+)" data-fee-key="([^"]+)"', result.text))
    expect(outcomes == {key: "created" for key in keys.values()} and result.text.count('data-sepa-request="requested"') == 1,
           f"outcome of the run with direct debit: {outcomes}, requested {result.text.count('data-sepa-request=')}")

    invoices = {}
    for person in members:
        invoices[person] = stack.value(f"SELECT ee.fk_target FROM llx_subscription as s INNER JOIN llx_element_element as ee ON ee.fk_source = s.rowid "
                                       f"AND ee.sourcetype = 'subscription' AND ee.targettype = 'facture' WHERE s.fk_adherent = {int(members[person])}")
    ids = ", ".join(str(int(value)) for value in invoices.values() if value)
    requests = stack.sql(f"SELECT pd.fk_facture, pd.amount, pd.fk_societe_rib, pd.traite, f.total_ttc FROM llx_prelevement_demande as pd "
                         f"INNER JOIN llx_facture as f ON f.rowid = pd.fk_facture WHERE pd.fk_facture IN ({ids})")
    expect(len(requests) == 1 and requests[0][0] == invoices["valid"] and float(requests[0][1]) == float(requests[0][4])
           and requests[0][2] == str(accounts["valid"]) and requests[0][3] == "0",
           f"direct debit requests: {requests}; expected one for invoice {invoices['valid']} over its total with account {accounts['valid']}")
    note, mode = stack.sql(f"SELECT f.note_public, p.code FROM llx_facture as f LEFT JOIN llx_c_paiement as p ON p.id = f.fk_mode_reglement "
                           f"WHERE f.rowid = {int(invoices['valid'])}")[0]
    collection = (datetime.date.fromisoformat(today) + datetime.timedelta(days=10)).strftime("%d.%m.%Y")
    expect(mode == "PRE" and "RT-MANDAT-GUELTIG" in note and "AT12ZZZ00000000001" in note and collection in note,
           f"invoice with direct debit: payment mode {mode}, note {note!r}; expected PRE, mandate, creditor id and {collection}")
    other = stack.value(f"SELECT COALESCE(note_public, '') FROM llx_facture WHERE rowid = {int(invoices['expired'])}") or ""
    expect("SEPA" not in other, f"the invoice with the expired mandate carries a pre-notification: {other!r}")
    return ("module off: explained and not offered; 70 days refused, 10 stored; preview: valid mandate named, 40 months unused reported expired, "
            "none without note; run: exactly one direct debit request, for the valid mandate over the invoice total, payment mode PRE, "
            "pre-notification with mandate, creditor id and collection in 10 days")


def applications(stack: Stack) -> str:
    """A website reads the consent texts and sends applications that become members in draft with their consents."""
    key = stack.notes["website"]["key"]
    browser = stack.browser()
    setup = page_ok(browser.get("/custom/vereine/admin/consents.php"), "consent setup")
    expect('data-consents-howto="1"' in setup.text, "the consent setup does not explain consents")
    refused = page_ok(browser.submit(setup.form(name="vereineconsenttext"), {"code": "Fotos!", "label": "Fotos", "text": "Text"}), "a consent text with a bad code")
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_consent_text") == "0" and "Die Kennung besteht" in html.unescape(refused.text),
           "a consent text with a bad code was stored or not explained")
    for fields in ({"code": "fotos", "label": "Fotos auf der Website", "text": "Fotos von Veranstaltungen, auf denen ich zu sehen bin, dürfen auf der Website des Vereins erscheinen."},
                   {"code": "newsletter", "label": "Newsletter", "text": "Ich möchte den Newsletter des Vereins per E-Mail bekommen."},
                   {"code": "fotos", "label": "Fotos auf Website und Social Media", "text": "Fotos von Veranstaltungen, auf denen ich zu sehen bin, dürfen auf der Website und in den sozialen Medien des Vereins erscheinen."}):
        page = page_ok(browser.get("/custom/vereine/admin/consents.php"), "consent setup")
        page_ok(browser.submit(page.form(name="vereineconsenttext"), fields), f"store consent text {fields['code']}")
    stored = stack.sql("SELECT code, version, active FROM llx_vereine_consent_text ORDER BY code, version")
    expect(stored == [["fotos", "1", "0"], ["fotos", "2", "1"], ["newsletter", "1", "1"]], f"consent texts with versions: {stored}")

    status, texts = stack.api("vereine/consents", key)
    expect(status == 200 and [(text["code"], text["version"]) for text in texts] == [("fotos", 2), ("newsletter", 1)],
           f"GET vereine/consents answered HTTP {status}: {texts}")
    status, _ = stack.api("vereine/consents", stack.nobody_key)
    expect(status == 403, f"a user without rights got HTTP {status} for the consent texts")

    form_key = secrets.token_hex(16)
    stack.php_fixture("applicationuser", RT_APPLICATION_KEY=form_key)
    stack.notes["applicationkey"] = form_key
    type_id = int(stack.value("SELECT rowid FROM llx_adherent_type WHERE libelle = 'Beitragspflichtig'"))
    email = "amelie.antrag@runtime-verein.test"
    body = {"external_id": "web-2026-0042", "firstname": "Amelie", "lastname": "Antrag", "email": email, "birth": "2001-04-30",
            "zip": "6020", "town": "Innsbruck", "country_code": "AT", "type_id": type_id, "note": "Ich spiele gern Schach.",
            "consents": [{"code": "fotos", "version": 2}]}
    status, _ = stack.api("vereine/applications", key, method="POST", data=body)
    expect(status == 403, f"the website user without the right to send applications got HTTP {status}")
    status, created = stack.api("vereine/applications", form_key, method="POST", data=body)
    expect(status == 200 and created.get("status") == "draft" and created.get("duplicate") is False, f"POST vereine/applications answered HTTP {status}: {created}")
    member_id = int(created["id"])
    member = stack.sql(f"SELECT statut, firstname, lastname, email, fk_adherent_type, DATE(birth), town FROM llx_adherent WHERE rowid = {member_id}")
    expect(member == [["-1", "Amelie", "Antrag", email, str(type_id), "2001-04-30", "Innsbruck"]], f"member from the application: {member}")
    consents = stack.sql(f"SELECT code, version, given, source FROM llx_vereine_consent WHERE fk_adherent = {member_id}")
    expect(consents == [["fotos", "2", "1", "website"]], f"consents from the application: {consents}")

    status, again = stack.api("vereine/applications", form_key, method="POST", data=body)
    count = stack.value(f"SELECT COUNT(*) FROM llx_adherent WHERE email = '{email}'")
    expect(status == 200 and again.get("id") == member_id and again.get("duplicate") is True and count == "1",
           f"the same application sent again: HTTP {status} {again}, {count} members")
    for change, message in (({"consents": [{"code": "fotos", "version": 1}]}, "the current version is 2"),
                            ({"email": "amelie at runtime"}, "valid e-mail"), ({"lastname": ""}, "lastname are required")):
        status, answer = stack.api("vereine/applications", form_key, method="POST", data={**body, **change, "external_id": ""})
        expect(status == 400 and message in json.dumps(answer), f"application with {change}: HTTP {status} {answer}")
    count = stack.value(f"SELECT COUNT(*) FROM llx_adherent WHERE email = '{email}' OR lastname = 'Antrag'")
    expect(count == "1", f"refused applications created members: {count}")

    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={member_id}"), "association tab of the applicant")
    expect('data-member-consent="fotos" data-state="given" data-version="2"' in tab.text and 'data-member-consent="newsletter" data-state="none"' in tab.text,
           "the member tab does not show the consents of the application")
    page_ok(browser.submit(tab.form(name="vereinewithdrawconsent")), "record the withdrawal of the photo consent")
    events = stack.sql(f"SELECT code, version, given, source FROM llx_vereine_consent WHERE fk_adherent = {member_id} ORDER BY rowid")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={member_id}"), "association tab after the withdrawal")
    expect(events == [["fotos", "2", "1", "website"], ["fotos", "2", "0", "paper"]] and 'data-member-consent="fotos" data-state="withdrawn"' in tab.text,
           f"withdrawal: {events}")
    return ("bad code refused; photos v1 and v2 and newsletter v1 stored, API offers the newest versions; website user without right 403; "
            "application became a member in draft with the photo consent v2 from the website; sent again: same member, duplicate; "
            "outdated version, bad e-mail and missing name refused with 400; withdrawal recorded and shown")


def functions(stack: Stack) -> str:
    """Functions of the association: catalogue, terms of office on the member, and what does not fit on a day."""
    site = stack.notes["website"]
    today = site["dates"]["today"]
    tomorrow = (datetime.date.fromisoformat(today) + datetime.timedelta(days=1)).isoformat()
    browser = stack.browser()

    setup = page_ok(browser.get("/custom/vereine/admin/functions.php"), "function setup")
    codes = re.findall(r'data-function="([a-z_]+)" data-active="1"', setup.text)
    expect(codes == ["obmann", "obmann_stv", "kassier", "kassier_stv", "schriftfuehrung", "schriftfuehrung_stv", "rechnungspruefung"],
           f"suggested functions after enabling: {codes}")
    fields = {"code": "jugendleitung", "label": "Jugendleitung", "min": "0", "max": "1", "position": "80", "active": "1"}
    for change, message in (({"code": "obmann"}, "Diese Kennung gibt es schon"), ({"min": "3", "max": "2"}, "nicht größer als")):
        refused = page_ok(browser.submit(page_ok(browser.get("/custom/vereine/admin/functions.php"), "function setup").form(name="vereinefunction"), {**fields, **change}),
                          f"function with {change}")
        expect(message in html.unescape(refused.text), f"a function with {change} was not refused with an explanation")
    page_ok(browser.submit(page_ok(browser.get("/custom/vereine/admin/functions.php"), "function setup").form(name="vereinefunction"), fields), "store Jugendleitung")
    ids = dict(stack.sql("SELECT code, rowid FROM llx_vereine_function WHERE entity = 1"))
    expect(len(ids) == 8 and "jugendleitung" in ids, f"functions stored: {sorted(ids)}")

    def overview(day: str) -> tuple[dict, list]:
        page = page_ok(browser.get(f"/custom/vereine/functions.php?day={day}"), f"board and functions on {day}")
        holders = {code: int(count) for code, count in re.findall(r'data-function-row="([a-z_]+)" data-holders="(\d+)"', page.text)}
        problems = re.findall(r'data-problem="([a-z_]+)" data-function="([a-z_]*)"', page.text)
        return holders, sorted(problems)

    holders, problems = overview(today)
    expect(("missing", "obmann") in problems and ("missing", "kassier") in problems and ("missing", "rechnungspruefung") in problems,
           f"problems without terms: {problems}")

    members = {**site["members"], **{name: int(stack.value(f"SELECT rowid FROM llx_adherent WHERE firstname = '{first}' AND lastname = '{last}'"))
                                     for name, first, last in (("karl", "Karl", "Austritt"), ("petra", "Petra", "Familie"))}}

    def add(person: str, code: str) -> None:
        tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={members[person]}"), f"association tab of {person}")
        page_ok(browser.submit(tab.form(name="vereineaddfunction"), {"function_id": ids[code], "function_start": today, "function_end": "", "function_note": "Runtime"}),
                f"{person} takes over {code}")

    for person, code in (("paid", "obmann"), ("expired", "kassier"), ("paid", "rechnungspruefung"), ("unpaid", "rechnungspruefung"),
                         ("karl", "jugendleitung"), ("petra", "jugendleitung")):
        add(person, code)
    otto = page_ok(browser.get(f"/custom/vereine/member_association.php?id={members['free']}"), "association tab of a resiliated member")
    expect('name="vereineaddfunction"' not in otto.text, "a resiliated member is offered a function")
    browser.post(f"/custom/vereine/member_association.php?id={members['free']}",
                 [("token", token_of(otto)), ("action", "addfunction"), ("function_id", ids["kassier"]), ("function_start", today)])
    terms = stack.sql("SELECT f.code, t.fk_adherent FROM llx_vereine_function_term as t INNER JOIN llx_vereine_function as f ON f.rowid = t.fk_function ORDER BY t.rowid")
    expect(len(terms) == 6 and str(members["free"]) not in [row[1] for row in terms], f"terms of office: {terms}")

    holders, problems = overview(today)
    expect(holders["obmann"] == 1 and holders["kassier"] == 1 and holders["rechnungspruefung"] == 2 and holders["jugendleitung"] == 2,
           f"holders today: {holders}")
    # The secretary is a required function of the model statutes, so a vacant one is reported (#149).
    expect(problems == [("auditor_on_board", ""), ("missing", "schriftfuehrung"), ("too_many", "jugendleitung")], f"problems today: {problems}")

    term = stack.value(f"SELECT t.rowid FROM llx_vereine_function_term as t INNER JOIN llx_vereine_function as f ON f.rowid = t.fk_function "
                       f"WHERE f.code = 'rechnungspruefung' AND t.fk_adherent = {int(members['paid'])}")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={members['paid']}"), "association tab of the chair")
    page_ok(browser.post(f"/custom/vereine/member_association.php?id={members['paid']}",
                         [("token", token_of(tab)), ("action", "endfunction"), ("term_id", term), ("function_end", today)]), "end the chair's audit term today")
    expect(stack.value(f"SELECT date_end FROM llx_vereine_function_term WHERE rowid = {int(term)}") == today, "the audit term was not ended today")
    _, problems_today = overview(today)
    holders_tomorrow, problems_tomorrow = overview(tomorrow)
    expect(("auditor_on_board", "") in problems_today and holders_tomorrow["rechnungspruefung"] == 1
           and problems_tomorrow == [("missing", "rechnungspruefung"), ("missing", "schriftfuehrung"), ("too_many", "jugendleitung")],
           f"after ending the term: today {problems_today}, tomorrow {holders_tomorrow} {problems_tomorrow}")
    logged = dict(stack.sql("SELECT action, COUNT(*) FROM llx_vereine_log WHERE action LIKE 'function%' GROUP BY action"))
    expect(logged == {"function_start": "6", "function_end": "1"}, f"function log: {logged}")
    expect(denied(stack.browser("rtreader").get("/custom/vereine/admin/functions.php")), "a non-administrator opens the function setup")
    return ("7 functions suggested; taken code and minimum above maximum refused, own function stored; nothing held: chair, treasurer, auditors missing; "
            "terms on the member, none for a resiliated member; today: chair on the board and auditor, too many youth leaders; "
            "audit term ended today still counts today, tomorrow one auditor is missing; log")


def authority(stack: Stack) -> str:
    """New representatives get a report deadline of four weeks, a letter with every detail § 14 (2) VerG asks for, and a note once reported."""
    site = stack.notes["website"]
    today = site["dates"]["today"]
    deadline = (datetime.date.fromisoformat(today) + datetime.timedelta(days=28)).isoformat()
    yesterday = (datetime.date.fromisoformat(today) - datetime.timedelta(days=1)).isoformat()
    members = site["members"]
    karl = int(stack.value("SELECT rowid FROM llx_adherent WHERE firstname = 'Karl' AND lastname = 'Austritt'"))
    browser = stack.browser()
    stack.php_fixture("agenda")

    def overview() -> Page:
        return page_ok(browser.get(f"/custom/vereine/functions.php?day={today}"), "board, functions and reports")

    page = overview()
    reports = re.findall(r'data-report="(\d+)" data-deadline="([^"]+)" data-overdue="(\d)"', page.text)
    expect(len(reports) == 2 and all(report[1] == deadline and report[2] == "0" for report in reports),
           f"reports for the chair and the treasurer of the functions scenario: {reports}, expected deadline {deadline}")

    deputy = stack.value("SELECT rowid FROM llx_vereine_function WHERE code = 'kassier_stv'")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={karl}"), "association tab of Karl")
    page_ok(browser.submit(tab.form(name="vereineaddfunction"), {"function_id": deputy, "function_start": today, "function_end": "", "function_note": ""}),
            "Karl becomes deputy treasurer")
    events = stack.sql(f"SELECT label, DATE(datep), percent FROM llx_actioncomm WHERE elementtype = 'member' AND fk_element = {karl}")
    expect(len(events) == 1 and "Vereinsbeh" in events[0][0] and events[0][1] == deadline and events[0][2] == "0",
           f"agenda event for the new representative: {events}")

    page = overview()
    missing = sorted(int(value) for value in re.findall(r'data-missing="(\d+)"', page.text))
    expect(missing == sorted([int(members["paid"]), int(members["expired"]), karl]), f"representatives with missing details: {missing}")
    stack.sql(f"UPDATE llx_vereine_function_report as r INNER JOIN llx_vereine_function_term as t ON t.rowid = r.fk_term "
              f"SET r.deadline = '{yesterday}' WHERE t.fk_adherent = {int(members['paid'])}")
    page = overview()
    expect(re.search(rf'data-deadline="{yesterday}" data-overdue="1"', page.text) is not None, "a report past its deadline is not marked overdue")

    stack.php_fixture("reportpeople", RT_MEMBERS=f"{members['paid']},{members['expired']},{karl}")
    page = overview()
    expect("data-missing=" not in page.text, "details are still reported missing after completing them")
    browser.post(f"/custom/vereine/functions.php?day={today}", [("token", token_of(page)), ("action", "reportpdf")])
    text = pdf_text(stack, "vereine/authority")
    for word in ("Bezahlt", "Abgelaufen", "Austritt", "Kassier", "Obmann", "Hall", "Tirol", "Musterweg", "6020", "123456789", "bestellt"):
        expect(word in text, f"the report letter lacks {word!r}")

    page = overview()
    page_ok(browser.submit(page.form(name="vereinemarkreported"), {"reported_on": today}), "note the reports as reported")
    open_reports = stack.value("SELECT COUNT(*) FROM llx_vereine_function_report WHERE reported_on IS NULL")
    percent = stack.value(f"SELECT percent FROM llx_actioncomm WHERE elementtype = 'member' AND fk_element = {karl}")
    expect(open_reports == "0" and percent == "100" and "data-report=" not in overview().text,
           f"after noting as reported: {open_reports} open, agenda event at {percent} %")
    browser.post(f"/custom/vereine/functions.php?day={today}", [("token", token_of(overview())), ("action", "reportpdf")])
    expect("bestellt" not in pdf_text(stack, "vereine/authority"), "the letter still marks representatives as new after they were reported")
    logged = stack.value("SELECT COUNT(*) FROM llx_vereine_log WHERE action = 'function_reported'")
    expect(logged == "3", f"{logged} reports logged, expected 3")
    return (f"chair and treasurer due on {deadline}; deputy treasurer with agenda event on the deadline; missing birth date, place of birth "
            "and address reported, overdue marked; letter with names, functions, birth place, address, ZVR number and new ones marked; "
            "noted as reported: no open report, agenda event done, letter without new marks")


def board(stack: Stack) -> str:
    """A website reads the board: names only with consent, or for the board always when it must be disclosed."""
    site = stack.notes["website"]
    key, members = site["key"], site["members"]
    karl = int(stack.value("SELECT rowid FROM llx_adherent WHERE firstname = 'Karl' AND lastname = 'Austritt'"))
    browser = stack.browser()

    def holders() -> dict:
        status, body = stack.api("vereine/board", key)
        expect(status == 200 and isinstance(body, list), f"GET vereine/board answered HTTP {status}: {body}")
        return {function["code"]: [holder["name"] for holder in function["holders"]] for function in body}

    before = holders()
    expect(before["obmann"] == [None] and before["kassier"] == [None] and set(before["rechnungspruefung"]) == {None},
           f"names without any setting: {before}")
    for who, name in ((stack.reader_key, "a user without the website right"), (stack.nobody_key, "a user without rights")):
        status, _ = stack.api("vereine/board", who)
        expect(status == 403, f"{name} got HTTP {status} for the board, expected 403")

    page = page_ok(browser.get("/custom/vereine/admin/consents.php"), "consent setup")
    page_ok(browser.submit(page.form(name="vereineconsenttext"), {"code": "vorstand_website", "label": "Auf der Website genannt werden",
                                                                   "text": "Mein Name und meine Funktion dürfen auf der Website des Vereins stehen."}),
            "store the consent text for the website")
    setup = page_ok(browser.get("/custom/vereine/admin/functions.php"), "function setup")
    expect('data-board-howto="1"' in setup.text, "the function setup does not explain the board on the website")
    page_ok(browser.submit(setup.form(name="vereineboard"), {"board_names": "consent", "board_consent": "vorstand_website"}), "names with consent")
    expect((stack.const("VEREINE_BOARD_NAMES"), stack.const("VEREINE_BOARD_CONSENT")) == ("consent", "vorstand_website"), "the board setting was not stored")

    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={members['paid']}"), "association tab of the chair")
    record = next((form for form in tab.forms() if form.value("action") == "recordconsent" and form.value("consent_code") == "vorstand_website"), None)
    expect(record is not None, "the chair's tab offers no consent to be named on the website")
    page_ok(browser.submit(record), "the chair consents to be named")
    named = holders()
    expect(named["obmann"] == ["Paula Bezahlt"] and named["kassier"] == [None] and named["kassier_stv"] == [None],
           f"names with the chair's consent: {named}")

    page = page_ok(browser.get("/custom/vereine/admin/functions.php"), "function setup")
    page_ok(browser.submit(page.form(name="vereineboard"), {"board_names": "disclosure", "board_consent": "vorstand_website"}), "board always named")
    disclosed = holders()
    expect(disclosed["obmann"] == ["Paula Bezahlt"] and disclosed["kassier"] == ["Emil Abgelaufen"] and disclosed["kassier_stv"] == ["Karl Austritt"]
           and None in disclosed["rechnungspruefung"] and "Nina Neu" not in disclosed["rechnungspruefung"] and set(disclosed["jugendleitung"]) == {None},
           f"names with disclosure of the board: {disclosed}")

    status, summary = stack.api(f"vereine/members/{karl}/summary", key)
    codes = sorted(function["code"] for function in summary.get("functions", [])) if status == 200 else []
    expect(codes == ["jugendleitung", "kassier_stv"], f"Karl's functions in his summary: {summary.get('functions') if status == 200 else status}")
    return ("without setting no names; consent text chosen, the chair consents: only her name; disclosure: the board named, auditors and "
            "youth leaders without consent not; other users 403; a member's own functions in the summary")


def groups(stack: Stack) -> str:
    """A function names a user group; holders with a Dolibarr user join or leave it only after an administrator confirms."""
    site = stack.notes["website"]
    today = site["dates"]["today"]
    members = site["members"]
    emil, paula = int(members["expired"]), int(members["paid"])
    created = stack.php_fixture("groupuser", RT_MEMBER_ID=str(emil))
    group, account = int(created["group"]), int(created["user"])
    browser = stack.browser()

    kassier = stack.value("SELECT rowid FROM llx_vereine_function WHERE code = 'kassier'")
    setup = page_ok(browser.get("/custom/vereine/admin/functions.php"), "function setup")
    link = next((html.unescape(href) for href in re.findall(r'href="([^"]*action=editfunction[^"]*)"', setup.text)
                 if re.search(rf"[?&;]id={kassier}(&|#|$)", html.unescape(href))), None)
    expect(link is not None, "the function setup offers no edit link for the treasurer")
    edit = page_ok(browser.get(link), "edit the treasurer")
    page_ok(browser.submit(edit.form(name="vereinefunction"), {"group_id": str(group)}), "give the treasurer the user group")
    expect(stack.value(f"SELECT fk_usergroup FROM llx_vereine_function WHERE rowid = {int(kassier)}") == str(group), "the user group was not stored on the function")

    def membership() -> str | None:
        return stack.value(f"SELECT COUNT(*) FROM llx_usergroup_user WHERE fk_user = {account} AND fk_usergroup = {group}")

    page = page_ok(browser.get("/custom/vereine/functions.php"), "board and functions with group suggestions")
    expect(f'data-group-change="add:{account}:{group}"' in page.text and membership() == "0",
           "the treasurer's user is not suggested for the group, or was added without confirmation")
    expect(f'data-without-user-member="{paula}"' in page.text, "the chair without Dolibarr user is not listed")
    page_ok(browser.submit(page.form(name="vereineapplygroups")), "confirm the suggestion")
    page = page_ok(browser.get("/custom/vereine/functions.php"), "after confirming")
    expect(membership() == "1" and 'data-group-change=' not in page.text and 'data-right="rtkassier"' in page.text,
           f"after confirming: membership {membership()}, suggestions left: {'data-group-change=' in page.text}")

    two_days = (datetime.date.fromisoformat(today) - datetime.timedelta(days=2)).isoformat()
    yesterday = (datetime.date.fromisoformat(today) - datetime.timedelta(days=1)).isoformat()
    term = stack.value(f"SELECT rowid FROM llx_vereine_function_term WHERE fk_adherent = {emil} AND fk_function = {int(kassier)} AND date_end IS NULL")
    stack.sql(f"UPDATE llx_vereine_function_term SET date_start = '{two_days}' WHERE rowid = {int(term)}")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={emil}"), "association tab of the treasurer")
    page_ok(browser.post(f"/custom/vereine/member_association.php?id={emil}",
                         [("token", token_of(tab)), ("action", "endfunction"), ("term_id", term), ("function_end", yesterday)]), "the treasurer's term ended yesterday")
    page = page_ok(browser.get("/custom/vereine/functions.php"), "suggestions after the term ended")
    expect(f'data-group-change="remove:{account}:{group}"' in page.text and membership() == "1",
           "the user is not suggested for removal, or was removed without confirmation")
    page_ok(browser.submit(page.form(name="vereineapplygroups")), "confirm the removal")
    logged = dict(stack.sql("SELECT action, COUNT(*) FROM llx_vereine_log WHERE action LIKE 'function_group%' GROUP BY action"))
    expect(membership() == "0" and logged == {"function_group_add": "1", "function_group_remove": "1"}, f"after the removal: {membership()}, log {logged}")
    return ("user group on the treasurer; suggestion to add the treasurer's user, nothing before confirming, added after; chair without user listed; "
            "term ended yesterday: removal suggested, nothing before confirming, removed after; log")


def mailing(stack: Stack) -> str:
    """Dolibarr's e-mail campaigns find the board, only members with newsletter consent, and guardians of minors."""
    site = stack.notes["website"]
    members = site["members"]
    campaign = int(stack.php_fixture("mailing")["mailing"])
    jonas = int(stack.value("SELECT rowid FROM llx_adherent WHERE firstname = 'Jonas' AND lastname = 'Jung'"))
    stack.php_fixture("guardian", RT_PARTNER_ID=stack.value(f"SELECT fk_soc FROM llx_adherent WHERE rowid = {jonas}"))
    fee_type = stack.value("SELECT rowid FROM llx_adherent_type WHERE libelle = 'Beitragspflichtig'")
    browser = stack.browser()

    def targets(fields: dict) -> set:
        stack.sql(f"DELETE FROM llx_mailing_cibles WHERE fk_mailing = {campaign}")
        page = page_ok(browser.get(f"/comm/mailing/targetemailing.php?id={campaign}"), "recipients of the campaign")
        expect('name="vereine_purpose"' in page.text, "Dolibarr's campaign offers no recipients of the association")
        page_ok(browser.submit(page.form(name="vereine"), fields), f"add recipients {fields}")
        return {row[0].lower() for row in stack.sql(f"SELECT email FROM llx_mailing_cibles WHERE fk_mailing = {campaign}")}

    board = targets({"vereine_status": "active", "vereine_type": "0", "vereine_function": "board", "vereine_purpose": "info"})
    expect(board == {"paula.bezahlt@runtime-verein.test", "karl.austritt@runtime-verein.test"}, f"recipients for the board: {board}")

    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={members['unpaid']}"), "association tab of Nina")
    record = next((form for form in tab.forms() if form.value("action") == "recordconsent" and form.value("consent_code") == "newsletter"), None)
    expect(record is not None, "Nina's tab offers no newsletter consent")
    page_ok(browser.submit(record), "Nina consents to the newsletter")
    newsletter = targets({"vereine_status": "active", "vereine_type": "0", "vereine_function": "", "vereine_purpose": "newsletter"})
    expect(newsletter == {"nina@runtime-verein.test"}, f"recipients of the newsletter: {newsletter}")

    guardians = targets({"vereine_status": "active", "vereine_type": fee_type, "vereine_function": "", "vereine_purpose": "info", "vereine_guardians": "1"})
    expect("gerda.jung@runtime-verein.test" in guardians and "child.discount@runtime-verein.test" not in guardians and "nina@runtime-verein.test" in guardians,
           f"recipients with guardians for minors: {sorted(guardians)}")
    kinds = dict(stack.sql(f"SELECT LOWER(email), source_type FROM llx_mailing_cibles WHERE fk_mailing = {campaign} AND email LIKE 'gerda%'"))
    expect(kinds.get("gerda.jung@runtime-verein.test") == "contact", f"the guardian is not added as contact: {kinds}")
    return ("board: exactly the chair and the deputy treasurer; newsletter: only the member with consent; minors reached through their "
            "guardian as contact, adults directly")


def statutes(stack: Stack) -> str:
    """Rules of the statutes: the model statutes filled in, checked against the Associations Act, used for elections and applications."""
    today = stack.notes["website"]["dates"]["today"]
    browser = stack.browser()
    page = page_ok(browser.get("/custom/vereine/admin/statutes.php"), "statute setup")
    form = page.form(name="vereinestatutes")
    expect('data-statutes-stored="0"' in page.text and form.value("general_years") == "1" and form.value("invite_days") == "14"
           and form.value("motion_days") == "3" and form.value("proxy") == "1" and form.value("virtual") == "none",
           "the statute setup does not show the model statutes")
    hints = re.findall(r'data-hint="([a-z_]+)" data-function="([a-z_]+)"', page.text)
    expect(("term_missing", "obmann") in hints and ("term_missing", "rechnungspruefung") in hints and all(code != "jugendleitung" for _, code in hints),
           f"hints of the model statutes: {hints}")
    ids = dict(stack.sql("SELECT code, rowid FROM llx_vereine_function WHERE entity = 1"))
    type_id = stack.value("SELECT rowid FROM llx_adherent_type WHERE libelle = 'Beitragspflichtig'")

    def send(changes: dict, drop: tuple = (), channels: list | None = None) -> Page:
        form = page_ok(browser.get("/custom/vereine/admin/statutes.php"), "statute setup").form(name="vereinestatutes")
        if channels is not None:
            drop = drop + ("invite_channels[]",)
        fields = [(name, value) for name, value in form.values() if name not in drop and name not in changes]
        fields += [(name, str(value)) for name, value in changes.items()]
        fields += [("invite_channels[]", channel) for channel in channels or []]
        return page_ok(browser.post(form.url(), fields), f"store statute rules {changes}")

    stored = "SELECT COUNT(*) FROM llx_const WHERE name = 'VEREINE_STATUTE_RULES'"
    for changes, channels, message in (({"general_years": "6"}, None, "mindestens alle fünf Jahre"), ({"motion_days": "14"}, None, "Frist für Anträge"),
                                       ({}, [], "Mindestens einen Weg"), ({f"term_years[{ids['obmann']}]": "25"}, None, "0 bis 20 Jahren")):
        refused = send(dict(changes), channels=channels)
        expect(message in html.unescape(refused.text) and stack.value(stored) == "0", f"statute rules with {changes} {channels} were stored or not explained")

    after = send({"general_years": "2", "min_age": "18", "virtual": "hybrid", "voting_types[]": type_id, f"term_years[{ids['obmann']}]": "4",
                  f"term_years[{ids['kassier']}]": "3", f"term_years[{ids['rechnungspruefung']}]": "2"}, drop=("proxy",), channels=["email"])
    rules = json.loads(stack.value("SELECT value FROM llx_const WHERE name = 'VEREINE_STATUTE_RULES' AND entity = 1"))
    expect(rules["general_years"] == 2 and rules["min_age"] == 18 and rules["virtual"] == "hybrid" and rules["proxy"] is False
           and rules["invite_channels"] == ["email"] and rules["voting_types"] == [int(type_id)] and rules["invite_days"] == 14, f"stored rules: {rules}")
    years = dict(stack.sql("SELECT code, term_years FROM llx_vereine_function WHERE entity = 1"))
    expect(years["obmann"] == "4" and years["kassier"] == "3" and years["rechnungspruefung"] == "2" and years["jugendleitung"] == "0", f"terms of office: {years}")
    hints = re.findall(r'data-hint="([a-z_]+)" data-function="([a-z_]+)"', after.text)
    expect('data-statutes-stored="1"' in after.text and ("term_not_aligned", "kassier") in hints and ("term_missing", "obmann_stv") in hints
           and all(code not in ("obmann", "rechnungspruefung") for _, code in hints), f"hints after storing: {hints}")

    later = (datetime.date.fromisoformat(today) + datetime.timedelta(days=4 * 366)).isoformat()
    due = re.findall(r'data-problem="election_due" data-function="([a-z_]+)"', page_ok(browser.get(f"/custom/vereine/functions.php?day={later}"), "functions in four years").text)
    now = page_ok(browser.get(f"/custom/vereine/functions.php?day={today}"), "functions today")
    expect("obmann" in due and set(due) <= {"obmann", "kassier", "rechnungspruefung"} and 'data-problem="election_due"' not in now.text,
           f"elections due in four years: {due}")

    key = stack.notes["applicationkey"]
    day = datetime.date.fromisoformat(today)
    body = {"firstname": "Ylvie", "lastname": "Jung", "email": "ylvie.jung@runtime-verein.test", "type_id": int(type_id),
            "birth": (day - datetime.timedelta(days=17 * 365)).isoformat()}
    for change, message in (({}, "the statutes admit members from 18 years of age"), ({"birth": ""}, "birth is required")):
        status, answer = stack.api("vereine/applications", key, method="POST", data={**body, **change})
        expect(status == 400 and message in json.dumps(answer), f"application with {change or 'age 17'}: HTTP {status} {answer}")
    expect(stack.value("SELECT COUNT(*) FROM llx_adherent WHERE lastname = 'Jung' AND firstname = 'Ylvie'") == "0", "a refused application created a member")
    status, created = stack.api("vereine/applications", key, method="POST",
                                data={**body, "lastname": "Alt", "email": "ylvie.alt@runtime-verein.test", "birth": (day - datetime.timedelta(days=30 * 365)).isoformat()})
    expect(status == 200 and created.get("status") == "draft", f"an adult's application: HTTP {status} {created}")

    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_log WHERE action = 'statute_rules'") == "1", "storing the rules was not logged once")
    expect(denied(stack.browser("rtreader").get("/custom/vereine/admin/statutes.php")), "a non-administrator opens the statute setup")
    return ("model statutes filled in, board and audit functions without term hinted; general assembly less often than five years, motions not before the invitation, "
            "no invitation channel and a term of 25 years refused; rules and terms stored, term ending between two assemblies hinted; "
            "election due in four years for the chair, not today; applications under 18 and without birth date refused, adult accepted; log")


def letters(stack: Stack) -> str:
    """Letters to the association authority: the responsible authority, one layout for every notice, deadline, agenda and a note once filed."""
    today = stack.notes["website"]["dates"]["today"]
    deadline = (datetime.date.fromisoformat(today) + datetime.timedelta(days=28)).isoformat()
    yesterday = (datetime.date.fromisoformat(today) - datetime.timedelta(days=1)).isoformat()
    browser = stack.browser()

    def page() -> Page:
        return page_ok(browser.get("/custom/vereine/authority.php"), "letters to the authority")

    def write(kind: str, fields: dict, drop: tuple = ()) -> Page:
        return page_ok(browser.submit(page().form(name=f"vereineletter{kind}"), fields, drop=drop), f"write {kind} with {fields}")

    count = "SELECT COUNT(*) FROM llx_vereine_authority_letter"
    start = page()
    expect('<td class="titlefieldcreate fieldrequired"><label for="date_statutes">' in start.text and '<td class="titlefieldcreate"><label for="date_extract">' in start.text,
           "the day of a notice is not marked required, or the optional day of the current extract is")
    expect('data-authority-kind="police"' in start.text and "data-authority-mismatch" not in start.text,
           "for a seat in Innsbruck the Landespolizeidirektion is not named as authority")
    refused = write("statutes", {"date": today})
    expect("Behörde mit Anschrift" in html.unescape(refused.text) and stack.value(count) == "0", "a letter without the authority's address was written")

    page_ok(browser.submit(page().form(name="vereineauthoritypick"), {"authority_code": "bh_innsbruck"}), "take BH Innsbruck")
    expect(stack.const("VEREINE_AUTHORITY") == "Bezirkshauptmannschaft Innsbruck" and "Gilmstra" in (stack.const("VEREINE_AUTHORITY_ADDRESS") or ""),
           "the suggested authority was not taken")
    expect('data-authority-mismatch="1"' in page().text, "a district authority for a seat in Innsbruck is not questioned")
    refused = page_ok(browser.submit(page().form(name="vereineauthority"), {"authority_gz": "VR-2026/42", "authority_email": "bh at tirol"}), "authority with a bad e-mail")
    expect("E-Mail-Adresse der Behörde ist ungültig" in html.unescape(refused.text) and not stack.const("VEREINE_AUTHORITY_GZ"), "a bad e-mail of the authority was stored")
    page_ok(browser.submit(page().form(name="vereineauthority"), {"authority_gz": "VR-2026/42"}), "store the file number")
    expect(stack.const("VEREINE_AUTHORITY_GZ") == "VR-2026/42", "the file number was not stored")
    # Saving the form again keeps the line break of the address instead of a written backslash-n.
    breaks = stack.sql("SELECT LOCATE(CHAR(10), value) > 0, LOCATE(CHAR(92), value) FROM llx_const WHERE name = 'VEREINE_AUTHORITY_ADDRESS' AND entity = 1")
    expect(breaks == [["1", "0"]], f"the authority's address lost its line break when saved again: {breaks}")

    write("statutes", {"date": today})
    rows = stack.sql("SELECT kind, event_date, deadline, filed_on, fk_actioncomm FROM llx_vereine_authority_letter ORDER BY rowid")
    expect(len(rows) == 1 and rows[0][:4] == ["statutes", today, deadline, "NULL"] and rows[0][4] not in ("", "NULL"), f"letters after the change of statutes: {rows}")
    event = stack.sql(f"SELECT DATE(datep), percent FROM llx_actioncomm WHERE id = {int(rows[0][4])}")
    expect(event == [[deadline, "0"]], f"agenda event of the notice: {event}")
    text = pdf_text(stack, "vereine/authority")
    for word in ("Runtime Verein", "Bezirkshauptmannschaft Innsbruck", "Gilmstra", "VR-2026/42", "Generalversammlung", "Statuten", "123456789", "Obmann"):
        expect(word in text, f"the notice of the change of statutes lacks {word!r}")

    refused = write("dissolution", {"date": today, "assets": "1", "liquidator_name": "Anna Abwicklerin"})
    expect("Abwicklers" in html.unescape(refused.text) and stack.value(count) == "1", "a dissolution with assets but without the liquidator's details was written")
    write("dissolution", {"date": today}, drop=("assets",))
    text = pdf_text(stack, "vereine/authority")
    # Single words: a line break may fall between two words of the letter.
    expect("sofortiger" in text and "vorhanden" in text and "Protokollauszug" in text, "the notice of dissolution lacks effect, assets or attachment")

    refused = write("extract", {"extract": "at", "date": ""})
    expect("für den der Auszug gelten soll" in html.unescape(refused.text) and stack.value(count) == "2", "an extract of an earlier day without the day was written")
    write("extract", {"extract": "full", "date": ""})
    text = pdf_text(stack, "vereine/authority")
    expect("Daten" in text and "Vereinsregisterauszugs" in text, "the application for a full extract lacks its wording")
    expect(stack.value("SELECT deadline FROM llx_vereine_authority_letter WHERE kind = 'extract'") == "NULL", "an application got a deadline")

    letter_ids = dict(stack.sql("SELECT kind, rowid FROM llx_vereine_authority_letter"))
    stack.sql(f"UPDATE llx_vereine_authority_letter SET deadline = '{yesterday}' WHERE rowid = {int(letter_ids['dissolution'])}")
    listing = page()
    expect(f'data-letter="{letter_ids["dissolution"]}" data-kind="dissolution" data-deadline="{yesterday}" data-filed="" data-overdue="1"' in listing.text,
           "a letter past its deadline is not marked overdue")
    page_ok(browser.submit(listing.form(name=f"vereinemarkfiled{letter_ids['statutes']}"), {"filed_on": today}), "note the change of statutes as filed")
    expect(stack.value(f"SELECT filed_on FROM llx_vereine_authority_letter WHERE rowid = {int(letter_ids['statutes'])}") == today
           and stack.value(f"SELECT percent FROM llx_actioncomm WHERE id = {int(rows[0][4])}") == "100", "noting as filed did not store the day or finish the agenda event")
    download = browser.get(f"/custom/vereine/authority.php?action=download&id={letter_ids['extract']}&token={token_of(listing)}")
    expect(download.status == 200 and download.text.startswith("%PDF"), f"the letter did not download as PDF (HTTP {download.status})")

    reader = stack.browser("rtreader")
    reader_page = reader.get("/custom/vereine/authority.php")
    if not denied(reader_page):
        expect('name="vereineletterstatutes"' not in reader_page.text, "a user without the right to change members is offered letters")
        reader.post("/custom/vereine/authority.php", [("token", token_of(reader_page)), ("action", "writeletter"), ("kind", "statutes"), ("date", today)])
    expect(stack.value(count) == "3", "a user without the right to change members wrote a letter")
    logged = dict(stack.sql("SELECT action, COUNT(*) FROM llx_vereine_log WHERE action LIKE 'authority_letter%' GROUP BY action"))
    expect(logged == {"authority_letter": "3", "authority_letter_filed": "1"}, f"letter log: {logged}")
    page_ok(browser.submit(page().form(name="vereineauthoritypick"), {"authority_code": "lpd_tirol"}), "take LPD Tirol again")
    expect(stack.const("VEREINE_AUTHORITY") == "Landespolizeidirektion Tirol" and stack.const("VEREINE_AUTHORITY_GZ") == "VR-2026/42",
           "taking a suggestion did not keep the file number")
    return ("LPD named for Innsbruck; no letter without the authority's address; BH Innsbruck taken from the list and questioned, bad e-mail refused, file number stored; "
            "change of statutes with deadline, agenda event and PDF with association, authority, file number and signers; dissolution with assets but no "
            "liquidator refused, without assets written; extract of an earlier day without day refused, full extract without deadline; overdue marked, "
            "filed noted, PDF download; reader cannot write; log")


def statutetext(stack: Stack) -> str:
    """Statutes as text from the rules: fields, check, preview, an uploaded and a generated version with PDF."""
    today = stack.notes["website"]["dates"]["today"]
    browser = stack.browser()
    setup = "/custom/vereine/admin/statutes.php"

    def page() -> Page:
        return page_ok(browser.get(setup), "statute setup")

    start = page()
    problems = re.findall(r'data-text-problem="([a-z_]+)"', start.text)
    expect("activities" in problems and 'data-statute-preview="1"' in start.text, f"problems of the empty text: {problems}")
    purpose = stack.const("VEREINE_PURPOSE") or ""
    shown_purpose = re.search(r'data-association-purpose="(\d)"', start.text)
    expect(shown_purpose is not None and shown_purpose.group(1) == ("1" if purpose else "0") and 'name="VEREINE_PURPOSE"' in start.text,
           f"the statutes page does not offer the purpose of the association (stored: {purpose!r})")
    origins = re.findall(r'data-statute-source="(\d+)"', start.text)
    expect(len(origins) >= 16, f"the table of origins names {len(origins)} paragraphs")
    if not purpose:
        expect("purpose" in problems and 'data-purpose-link="1"' in start.text and "nicht der Zweck, dem das Verm" in html.unescape(start.text),
               "without a purpose of the association the problem does not name it or link to it")
    text_before = stack.const("VEREINE_STATUTE_TEXT")
    refused = page_ok(browser.submit(start.form(name="vereinestatutetext"), {"arrears_months": "0"}), "text with no months for exclusion")
    expect("1 bis 24 Monate" in html.unescape(refused.text) and stack.const("VEREINE_STATUTE_TEXT") == text_before,
           "text fields without months for exclusion were stored")
    page_ok(browser.submit(page().form(name="vereinestatutetext"), {
        "activities": "Turniere und Ligaspiele\nTraining", "funds": "Beitrittsgebühren und Mitgliedsbeiträge\nSponsorgelder", "arrears_months": "3",
        "wording": "bao:a", "asset_purpose": "Förderung des Jugendsports"}), "store the text of the statutes")
    stored = json.loads(stack.const("VEREINE_STATUTE_TEXT") or "{}")
    expect(stored.get("activities") == ["Turniere und Ligaspiele", "Training"] and stored.get("tax") == "bao" and stored.get("asset") == "a" and stored.get("arrears_months") == 3,
           f"stored text fields: {stored}")
    preview = page()
    text = html.unescape(preview.text)
    for words in ("Turniere und Ligaspiele", "Förderung des Jugendsports", "alle zwei Jahre", "18. Lebensjahr", "länger als drei Monate"):
        expect(words in text, f"the preview lacks {words!r}")
    expect('data-section-number="17"' in preview.text, "the preview of a tax-privileged association has no § 17 on the assets")
    expect('class="titlefieldcreate fieldrequired"><label for="general_years">' in preview.text and 'class="fieldrequired"><label for="arrears_months">' in preview.text,
           "required fields of the statutes are not marked bold")
    shown = preview.text[preview.text.index('data-statute-preview="1"'):]
    expect("\\n" not in html.unescape(shown[:shown.index("vereinestatutedraft")]) and shown.count('data-statute-item="1"') >= 4,
           "the preview shows line breaks as \\n or lists not one item per line")

    count = "SELECT COUNT(*) FROM llx_vereine_statute"
    upload = preview.form(name="vereinestatuteupload")
    fields = [(name, value) for name, value in upload.values() if name not in ("decided_on", "valid_from", "note")]
    refused = page_ok(browser.post_multipart(upload.url(), fields + [("decided_on", "2019-03-01"), ("valid_from", ""), ("note", "")],
                                             [("statute_file", "statuten.txt", b"not a pdf")]), "upload of a text file")
    expect("PDF-Datei" in html.unescape(refused.text) and stack.value(count) == "0", "a file that is no PDF was stored as statutes")
    old_pdf = b"%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"
    page_ok(browser.post_multipart(upload.url(), fields + [("decided_on", "2019-03-01"), ("valid_from", ""), ("note", "Gründungsstatuten")],
                                   [("statute_file", "statuten-2019.pdf", old_pdf)]), "upload the existing statutes")
    refused = page_ok(browser.submit(page().form(name="vereinestatuteversion"), {"decided_on": today, "valid_from": "2019-01-01"}), "version valid before its resolution")
    expect("nicht davor liegen" in html.unescape(refused.text) and stack.value(count) == "1", "a version valid before its resolution was stored")
    page_ok(browser.submit(page().form(name="vereinestatuteversion"), {"decided_on": today, "note": "Neue Fassung"}), "store the generated version")
    versions = stack.sql("SELECT version, decided_on, valid_from, source, LENGTH(sha256), content IS NOT NULL FROM llx_vereine_statute ORDER BY version")
    expect(versions == [["1", "2019-03-01", "2019-03-01", "uploaded", "64", "0"], ["2", today, today, "generated", "64", "1"]], f"versions: {versions}")
    listing = page()
    expect('data-statute-version="2" data-source="generated" data-current="1"' in listing.text and 'data-statute-version="1" data-source="uploaded" data-current="0"' in listing.text,
           "the generated version is not the one in force")
    pdf = pdf_text(stack, "vereine/statutes")
    for word in ("Statuten", "Innsbruck", "Training", "Rechnungspr", "Sponsorgelder"):
        expect(word in pdf, f"the generated statutes lack {word!r}")
    first = stack.value("SELECT rowid FROM llx_vereine_statute WHERE version = 1")
    download = browser.get(f"{setup}?action=download&id={first}&token={token_of(listing)}")
    expect(download.status == 200 and download.body == old_pdf, f"the uploaded statutes did not download unchanged (HTTP {download.status})")
    draft = browser.post(setup, [("token", token_of(listing)), ("action", "draftpdf")])
    leftovers = stack.shell("ls /var/www/documents/vereine/statutes").stdout
    expect(draft.status == 200 and draft.body.startswith(b"%PDF") and stack.value(count) == "2" and "entwurf" not in leftovers,
           f"the draft was stored or not delivered (HTTP {draft.status}, files: {leftovers.split()})")
    logged = dict(stack.sql("SELECT action, COUNT(*) FROM llx_vereine_log WHERE action IN ('statute_text', 'statute_version') GROUP BY action"))
    # Two saves of the text: the purpose in the setup scenario (it belongs to the statutes, #150) and the fields here.
    expect(logged == {"statute_text": "2", "statute_version": "2"}, f"statute log: {logged}")
    return ("empty text lacks activities; no months for exclusion refused; activities, funds and tax wording stored; preview with activities, asset purpose, "
            "interval, minimum age, exclusion and § 17; text file refused, existing statutes uploaded as version 1; version valid before its resolution refused; "
            "generated version 2 in force with PDF and hash; upload downloads unchanged; draft PDF not stored; log")


def statutechange(stack: Stack) -> str:
    """Change of the statutes: comparison with the version in force, PDF for the invitation, new version with notice to the authority."""
    today = stack.notes["website"]["dates"]["today"]
    browser = stack.browser()
    setup = "/custom/vereine/admin/statutes.php"

    def page() -> Page:
        return page_ok(browser.get(setup), "statute setup")

    expect('data-statute-change="same"' in page().text, "the text just stored as version 2 is shown as changed")
    page_ok(browser.submit(page().form(name="vereinestatutetext"), {"arrears_months": "6"}), "change the exclusion period")
    changed = page()
    sections = [html.unescape(title) for title in re.findall(r'data-changed-section="([^"]+)"', changed.text)]
    expect('data-statute-change="changed"' in changed.text and sections == ["Beendigung der Mitgliedschaft"] and 'data-change-majority="two_thirds"' in changed.text,
           f"comparison after changing the exclusion period: {sections}")
    pdf = browser.post(setup, [("token", token_of(changed)), ("action", "comparisonpdf")])
    leftovers = stack.shell("ls /var/www/documents/vereine/statutes").stdout
    expect(pdf.status == 200 and pdf.body.startswith(b"%PDF") and "gegenueberstellung" not in leftovers, f"comparison PDF (HTTP {pdf.status}, files: {leftovers.split()})")

    letters = "SELECT COUNT(*) FROM llx_vereine_authority_letter WHERE kind = 'statutes'"
    before = int(stack.value(letters))
    form = changed.form(name="vereinestatuteversion")
    expect(form.value("notify") == "1", "the notice to the authority is not offered for a change")
    page_ok(browser.submit(form, {"decided_on": today, "note": "Ausschluss nach sechs Monaten"}), "store version 3 with notice")
    expect(stack.value("SELECT MAX(version) FROM llx_vereine_statute") == "3" and int(stack.value(letters)) == before + 1
           and stack.value(f"SELECT COUNT(*) FROM llx_vereine_authority_letter WHERE kind = 'statutes' AND event_date = '{today}'") == str(before + 1),
           "the new version or its notice to the authority is missing")
    after = page()
    expect('data-statute-change="same"' in after.text and 'data-statute-version="3" data-source="generated" data-current="1"' in after.text,
           "version 3 is not in force or still differs")
    return ("stored text matches version 2; new exclusion period changes only § 6, majority of the statutes in force; comparison PDF delivered and not kept; "
            "version 3 stored with notice to the authority and in force")


def meetings(stack: Stack) -> str:
    """Meetings: a board meeting reaches exactly the board, a general assembly every active member by e-mail or letter, with deadline and proof."""
    today = datetime.date.fromisoformat(stack.notes["website"]["dates"]["today"])
    browser = stack.browser()
    base = "/custom/vereine/meetings.php"
    mailpit = stack.mailpit()
    count = "SELECT COUNT(*) FROM llx_vereine_meeting"

    def create(fields: dict) -> Page:
        return page_ok(browser.submit(page_ok(browser.get(base), "meetings").form(name="vereinemeeting"), fields), f"create meeting {fields}")

    def received() -> dict:
        found = {}
        for message in mailpit.messages():
            for to in message.get("To") or []:
                found[to["Address"].lower()] = message["ID"]
        return found

    board_day = (today + datetime.timedelta(days=3)).isoformat()
    board = {"kind": "board", "title": "Vorstandssitzung Herbst", "day": board_day, "time": "19:00", "format": "physical", "place": "Vereinsheim"}
    refused = create({**board, "agenda": ""})
    expect("Tagesordnung eintragen" in html.unescape(refused.text) and stack.value(count) == "0", "a meeting without agenda was stored")
    card = create({**board, "agenda": "Begrüßung\nBericht Kassier"})
    board_id = stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting")
    expected = {int(row[0]) for row in stack.sql(
        "SELECT DISTINCT t.fk_adherent FROM llx_vereine_function_term as t INNER JOIN llx_vereine_function as f ON f.rowid = t.fk_function AND f.board = 1 AND f.active = 1 "
        f"INNER JOIN llx_adherent as d ON d.rowid = t.fk_adherent AND d.statut = 1 WHERE t.date_start <= '{board_day}' AND (t.date_end IS NULL OR t.date_end >= '{board_day}')")}
    recipients = {int(member) for member in re.findall(r'data-recipient="(\d+)"', card.text)}
    expect(recipients and recipients == expected, f"board meeting recipients {sorted(recipients)}, board on the day {sorted(expected)}")
    refused = page_ok(browser.submit(card.form(name="vereinemeetinginvite"), {}, drop=("checked",)), "invite without checking the recipients")
    expect("Empfänger geprüft" in html.unescape(refused.text) and stack.value(f"SELECT status FROM llx_vereine_meeting WHERE rowid = {board_id}") == "planned",
           "the board was invited without the recipients checked")
    mailpit.clear()
    page_ok(browser.submit(page_ok(browser.get(f"{base}?id={board_id}"), "board meeting").form(name="vereinemeetinginvite"), {"checked": "1"}), "invite the board")
    ids = ", ".join(str(member) for member in expected)
    board_mails = {row[0].lower() for row in stack.sql(f"SELECT email FROM llx_adherent WHERE rowid IN ({ids}) AND email <> ''")}
    others = {row[0].lower() for row in stack.sql(f"SELECT email FROM llx_adherent WHERE rowid NOT IN ({ids}) AND email <> ''")}
    got = set(received())
    expect(got == board_mails and not got & others, f"board invitation reached {sorted(got)}, board addresses {sorted(board_mails)}")
    proof = stack.sql(f"SELECT fk_adherent, sent_at IS NOT NULL FROM llx_vereine_meeting_invitation WHERE fk_meeting = {board_id}")
    expect({int(row[0]) for row in proof} == expected and all(row[1] == "1" for row in proof)
           and stack.value(f"SELECT status FROM llx_vereine_meeting WHERE rowid = {board_id}") == "invited", f"proof of the board invitation: {proof}")

    general_day = (today + datetime.timedelta(days=7)).isoformat()
    general = {"kind": "general", "title": "Generalversammlung Runtime", "day": general_day, "time": "18:30", "place": "Vereinsheim",
               "access": "Link im Mitgliederbereich", "agenda": "Begrüßung und Beschlussfähigkeit\nBericht des Vorstands\nWahlen\nAllfälliges"}
    refused = create({**general, "format": "physical"})
    expect("erlauben die Statuten" in html.unescape(refused.text), "a general assembly in person was stored although the statutes say hybrid")
    card = create({**general, "format": "hybrid"})
    general_id = stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting")
    expect('data-late="1"' in card.text, "an invitation seven days before a general assembly with 14 days in the statutes is not marked late")
    without = stack.value("SELECT d.rowid FROM llx_adherent as d WHERE d.statut = 1 AND d.email <> '' AND d.lastname REGEXP '^[A-Za-z]+$' "
                          f"AND d.rowid NOT IN ({ids}) ORDER BY d.rowid LIMIT 1")
    lastname = stack.value(f"SELECT lastname FROM llx_adherent WHERE rowid = {int(without)}")
    stack.sql(f"UPDATE llx_adherent SET email = '' WHERE rowid = {int(without)}")
    card = page_ok(browser.get(f"{base}?id={general_id}"), "general assembly")
    active = {int(row[0]) for row in stack.sql("SELECT rowid FROM llx_adherent WHERE statut = 1")}
    rows = re.findall(r'data-recipient="(\d+)" data-channel="([a-z]+)" data-voting="(\d)"', card.text)
    expect({int(row[0]) for row in rows} == active and (str(without), "letter") in {(row[0], row[1]) for row in rows},
           f"general assembly recipients {rows}, active members {sorted(active)}")
    mailpit.clear()
    page_ok(browser.submit(card.form(name="vereinemeetinginvite"), {"checked": "1"}), "invite the general assembly")
    mails = received()
    expected_mails = {row[0].lower() for row in stack.sql("SELECT email FROM llx_adherent WHERE statut = 1 AND email <> ''")}
    expect(set(mails) == expected_mails, f"general assembly invitation reached {sorted(mails)}, active addresses {sorted(expected_mails)}")
    # Members of one family may share an address: every member gets an invitation of their own.
    emailed = [row for row in rows if row[1] == "email"]
    # Plain text e-mails wrap long lines, so words are compared with single spaces.
    texts = [" ".join((mailpit.message(message["ID"]).get("Text") or "").split()) for message in mailpit.messages()]
    notes = sum(1 for text in texts if "nicht stimmberechtigt" in text)
    not_voting = sum(1 for row in emailed if row[2] == "0")
    expect(len(texts) == len(emailed) and notes == not_voting and all("Wahlen" in text and "Anträge" in text for text in texts),
           f"{len(texts)} e-mails for {len(emailed)} members by e-mail, {notes} notes for {not_voting} members without vote, or agenda and motions missing")
    expect(lastname in pdf_text(stack, "vereine/meetings"), f"the letters PDF lacks the member without e-mail ({lastname})")
    expect(stack.value("SELECT COUNT(*) FROM llx_actioncomm WHERE label = 'Generalversammlung Runtime'") == "1", "the general assembly is not in the agenda")
    page_ok(browser.submit(page_ok(browser.get(f"{base}?id={general_id}"), "general assembly").form(name="vereinemeetingheld")), "note the general assembly as held")
    expect(stack.value(f"SELECT status FROM llx_vereine_meeting WHERE rowid = {general_id}") == "held", "the general assembly is not marked held")

    reader_page = stack.browser("rtreader").get(base)
    expect(denied(reader_page) or ('name="vereinemeeting"' not in reader_page.text and 'name="vereinemeetinginvite"' not in reader_page.text),
           "a user without the right to change members is offered a new meeting or an invitation")
    logged = dict(stack.sql("SELECT action, COUNT(*) FROM llx_vereine_log WHERE action LIKE 'meeting%' GROUP BY action"))
    expect(logged == {"meeting_created": "2", "meeting_invited": "2", "meeting_status": "1"}, f"meeting log: {logged}")
    return (f"no agenda refused; board meeting: {len(expected)} board members only, not without checking, e-mails exactly to the board, proof; general assembly "
            "in person refused (statutes hybrid), late invitation marked, every active member invited, member without e-mail in the letters PDF, "
            "note for members without vote, agenda event, held; reader cannot create; log")


def attendance(stack: Stack) -> str:
    """Attendance: proxies only as the statutes allow and never on the board, quorum at any time of the meeting."""
    browser = stack.browser()
    base = "/custom/vereine/meetings.php"
    general_id = stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting WHERE kind = 'general'")
    board_id = stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting WHERE kind = 'board'")

    def card(meeting: str, at: str = "") -> Page:
        return page_ok(browser.get(f"{base}?id={meeting}" + (f"&at={at}" if at else "")), f"meeting {meeting} at {at}")

    def save(meeting: str, rows: dict) -> Page:
        page = card(meeting)
        fields = [("token", token_of(page)), ("action", "saveattendance")]
        for member, row in rows.items():
            fields += [(f"attendance[{member}][{key}]", value) for key, value in row.items()]
        return page_ok(browser.post(f"{base}?id={meeting}", fields), f"store attendance of meeting {meeting}")

    def quorum(page: Page) -> dict:
        match = re.search(r'data-quorum-reached="(\d)" data-votes="(\d+)" data-present="(\d+)" data-represented="(\d+)" data-eligible="(\d+)" data-required="(\d+)"', page.text)
        expect(match is not None, "the meeting shows no quorum")
        return dict(zip(("reached", "votes", "present", "represented", "eligible", "required"), (int(value) for value in match.groups())))

    page = card(general_id)
    rows = re.findall(r'data-attendance="(\d+)" data-state="([a-z]+)" data-voting="(\d)"', page.text)
    voters = [member for member, _, voting in rows if voting == "1"]
    others = [member for member, _, voting in rows if voting == "0"]
    expect(len(voters) >= 4 and others and quorum(page)["reached"] == 0, f"attendance of the general assembly before anyone is recorded: {len(voters)} voting, {len(others)} not")
    count = "SELECT COUNT(*) FROM llx_vereine_meeting_attendance"
    refused = save(general_id, {voters[0]: {"state": "present"}, voters[1]: {"state": "represented", "holder": voters[0]}})
    expect("keine Stimmrechtsübertragung" in html.unescape(refused.text) and stack.value(count) == "0", "a proxy was stored although the statutes do not allow it")

    setup = page_ok(browser.get("/custom/vereine/admin/statutes.php"), "statute setup")
    page_ok(browser.submit(setup.form(name="vereinestatutes"), {"proxy": "1", "general_quorum": "50"}), "allow proxies and ask for half of the voting members")
    required = -(-len(voters) // 2)
    present = voters[:required - 1]
    plan = {member: {"state": "present", "arrived": "18:30"} for member in present}
    plan[present[0]]["left"] = "20:00"
    plan[voters[required - 1]] = {"state": "represented", "holder": present[0]}
    for member, text in ((others[0], "stimmberechtigten Mitglied"), (voters[-1], "muss anwesend sein")):
        holder = present[0] if member == others[0] else voters[-2]
        refused = save(general_id, {**plan, member: {"state": "represented", "holder": holder}})
        expect(text in html.unescape(refused.text) and stack.value(count) == "0", f"a proxy of {member} to {holder} was stored")
    save(general_id, plan)
    expect(stack.value(count) == str(len(rows)), "not every invited member has an attendance row")
    at_seven = quorum(card(general_id, "19:00"))
    expect(at_seven == {"reached": 1, "votes": required, "present": required - 1, "represented": 1, "eligible": len(voters), "required": required},
           f"quorum at 19:00: {at_seven}, expected {required} of {len(voters)}")
    later = quorum(card(general_id, "20:30"))
    expect(later["reached"] == 0 and later["represented"] == 0 and later["present"] == required - 2,
           f"after the proxy holder left at 20:00 the quorum should be gone: {later}")

    board_rows = re.findall(r'data-attendance="(\d+)" data-state="[a-z]+" data-voting="1"', card(board_id).text)
    refused = save(board_id, {board_rows[0]: {"state": "present"}, board_rows[1]: {"state": "represented", "holder": board_rows[0]}})
    expect("keine Vollmacht" in html.unescape(refused.text), "a proxy on the board was accepted")
    half = -(-len(board_rows) // 2)
    save(board_id, {member: {"state": "present"} for member in board_rows[:half]})
    board = quorum(card(board_id))
    expect(board["reached"] == 1 and board["present"] == half and board["represented"] == 0, f"board quorum with half present: {board}")
    logged = stack.value("SELECT COUNT(*) FROM llx_vereine_log WHERE action = 'meeting_attendance'")
    expect(logged == "2", f"{logged} attendance saves logged, expected 2")
    return (f"general assembly with {len(voters)} voting members: proxy refused without the statutes, from a member without vote and to an absent holder; "
            f"with proxies and half needed: {required} votes at 19:00 reach the quorum, gone after the holder left; board: no proxy, half present reaches the quorum; log")


def votes(stack: Stack) -> str:
    """Votes and elections: only with quorum, majorities of the statutes, an election starts the term and its report, a change of statutes stores version and notice."""
    browser = stack.browser()
    base = "/custom/vereine/meetings.php"
    general_id = stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting WHERE kind = 'general'")
    board_id = stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting WHERE kind = 'board'")
    general_day = stack.value(f"SELECT meeting_day FROM llx_vereine_meeting WHERE rowid = {general_id}")
    count = "SELECT COUNT(*) FROM llx_vereine_meeting_vote"

    def vote(meeting: str, fields: dict) -> Page:
        page = page_ok(browser.get(f"{base}?id={meeting}"), f"meeting {meeting}")
        return page_ok(browser.submit(page.form(name="vereinevote"), fields), f"vote {fields}")

    planning = page_ok(browser.get(f"{base}?template=general"), "a new general assembly from the template")
    suggested = re.search(r'id="day" name="day" value="(\d{4}-\d{2}-\d{2})"', planning.text)
    invite_days = int(json.loads(stack.const("VEREINE_STATUTE_RULES") or "{}").get("invite_days", 14))
    earliest = (datetime.date.today() + datetime.timedelta(days=invite_days)).isoformat()
    expect(suggested is not None and suggested.group(1) == earliest,
           f"the new meeting starts on {suggested.group(1) if suggested else None}, expected {earliest} (invitation period {invite_days} days)")
    page = page_ok(browser.get(f"{base}?id={general_id}&at=19:00"), "general assembly at 19:00")
    majorities = re.search(r'data-vote-majorities="1">([^<]+)<', page.text)
    expect(majorities is not None and "Zweidrittelmehrheit" in html.unescape(majorities.group(1)),
           f"the vote form does not name the majorities of the statutes: {majorities.group(1) if majorities else None}")
    votes_at_seven = int(re.search(r'data-votes="(\d+)"', page.text).group(1))
    expect(votes_at_seven >= 3, f"{votes_at_seven} votes at 19:00, the attendance scenario should leave at least three")
    refused = vote(general_id, {"item": "2", "kind": "resolution", "title": "Budget", "yes": "2", "no": "0", "time": ""})
    expect("nicht beschlussfähig" in html.unescape(refused.text) and stack.value(count) == "0", "a vote without quorum was entered")
    refused = vote(general_id, {"item": "2", "kind": "resolution", "title": "Budget", "yes": str(votes_at_seven + 1), "no": "0", "time": "19:00"})
    expect("mehr Stimmen" in html.unescape(refused.text) and stack.value(count) == "0", "more votes than present were entered")
    vote(general_id, {"item": "2", "kind": "resolution", "title": "Budget", "yes": str(votes_at_seven - 1), "no": "1", "time": "19:00"})

    versions = "SELECT COUNT(*) FROM llx_vereine_statute"
    notices = "SELECT COUNT(*) FROM llx_vereine_authority_letter WHERE kind = 'statutes'"
    before = (stack.value(versions), stack.value(notices))
    vote(general_id, {"item": "3", "kind": "statutes", "title": "Ausschluss nach sechs Monaten", "yes": "3", "no": "2", "abstain": "0", "time": "19:00"}
         if votes_at_seven >= 5 else {"item": "3", "kind": "statutes", "title": "Ausschluss nach sechs Monaten", "yes": "1", "no": "1", "time": "19:00"})
    expect((stack.value(versions), stack.value(notices)) == before, "a change of statutes without two thirds stored a version or a notice")
    vote(general_id, {"item": "3", "kind": "statutes", "title": "Ausschluss nach sechs Monaten", "yes": "2", "no": "1", "time": "19:00"})
    expect(int(stack.value(versions)) == int(before[0]) + 1 and int(stack.value(notices)) == int(before[1]) + 1,
           "a change of statutes with two thirds did not store the version and the notice")

    kassier = stack.value("SELECT rowid FROM llx_vereine_function WHERE code = 'kassier'")
    reports = "SELECT COUNT(*) FROM llx_vereine_function_report"
    reports_before = int(stack.value(reports))
    candidate = stack.value("SELECT d.rowid FROM llx_adherent as d WHERE d.statut = 1 AND d.rowid NOT IN (SELECT fk_adherent FROM llx_vereine_function_term) ORDER BY d.rowid LIMIT 1")
    vote(general_id, {"item": "3", "kind": "election", "title": "Wahl Kassier:in", "yes": "2", "no": "0", "time": "19:00", "function_id": kassier, "candidate_id": candidate, "secret": "1"})
    term = stack.sql(f"SELECT date_start, date_end FROM llx_vereine_function_term WHERE fk_function = {kassier} AND fk_adherent = {candidate}")
    years = int(stack.value(f"SELECT term_years FROM llx_vereine_function WHERE rowid = {kassier}") or 0)
    ends = (datetime.date.fromisoformat(general_day).replace(year=datetime.date.fromisoformat(general_day).year + years)
            - datetime.timedelta(days=1)).isoformat() if years > 0 else None
    open_others = stack.value(f"SELECT COUNT(*) FROM llx_vereine_function_term WHERE fk_function = {kassier} AND fk_adherent <> {candidate} "
                              f"AND date_start <= '{general_day}' AND (date_end IS NULL OR date_end >= '{general_day}')")
    expect(term == [[general_day, ends if ends else "NULL"]] and open_others == "0" and int(stack.value(reports)) == reports_before + 1,
           f"election: term {term} (expected end {ends}), other open treasurer terms {open_others}, reports {stack.value(reports)} (before {reports_before})")
    results = re.findall(r'data-vote="\d+" data-kind="([a-z]+)" data-passed="(\d)"', page_ok(browser.get(f"{base}?id={general_id}"), "general assembly").text)
    expect(results == [("resolution", "1"), ("statutes", "0"), ("statutes", "1"), ("election", "1")], f"votes of the general assembly: {results}")

    refused = vote(board_id, {"item": "1", "kind": "statutes", "title": "Statuten", "yes": "1", "no": "0"})
    expect("nur die Generalversammlung" in html.unescape(refused.text), "the board resolved a change of statutes")
    board_page = page_ok(browser.get(f"{base}?id={board_id}"), "board meeting")
    members = re.findall(r'data-attendance="(\d+)"', board_page.text)
    expect(len(members) >= 2, f"a tie needs two board members, the board meeting invited {len(members)}")
    fields = [("token", token_of(board_page)), ("action", "saveattendance")] + [(f"attendance[{member}][state]", "present") for member in members]
    page_ok(browser.post(f"{base}?id={board_id}", fields), "the whole board is present")
    vote(board_id, {"item": "2", "kind": "resolution", "title": "Anschaffung", "yes": "1", "no": "1", "tie": "yes"})
    board_votes = re.findall(r'data-vote="\d+" data-kind="([a-z]+)" data-passed="(\d)"', page_ok(browser.get(f"{base}?id={board_id}"), "board meeting").text)
    expect(board_votes == [("resolution", "1")], f"a tie on the board decided by the chair: {board_votes}")
    logged = stack.value("SELECT COUNT(*) FROM llx_vereine_log WHERE action = 'meeting_vote'")
    expect(logged == "5", f"{logged} votes logged, expected 5")
    return (f"{votes_at_seven} votes at 19:00; no vote without quorum or with more votes than present; resolution passed; change of statutes rejected without, "
            "stored with two thirds (version and notice); secret election of the treasurer starts the term on the day, ends others, writes the report; "
            "board: no change of statutes, tie decided by the chair; log")


def minutes(stack: Stack) -> str:
    """Minutes: roles of a meeting, the draft as PDF, a final version with checksum and signatures, sending it to the board."""
    browser = stack.browser()
    base = "/custom/vereine/meetings.php"
    meeting = stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting WHERE kind = 'general' AND status <> 'planned'")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "general assembly")
    suggested = re.search(r'data-minutes="(\d+)" data-versions="(\d+)" data-suggested="(\d)"', page.text)
    expect(suggested is not None and suggested.group(2) == "0", f"minutes section of the meeting: {suggested.groups() if suggested else None}")
    form = page.form(name="vereinemeetingroles")
    people = re.findall(r'data-attendance="(\d+)"', page.text)
    expect(len(people) >= 2, f"the meeting should have invited several members, found {len(people)}")
    chair, keeper = people[0], people[1]
    page_ok(browser.submit(form, {"chair": chair, "keeper": keeper}), "who presided and who kept the minutes")
    stored = stack.sql(f"SELECT fk_chair, fk_keeper FROM llx_vereine_meeting WHERE rowid = {meeting}")
    expect(stored == [[chair, keeper]], f"stored roles: {stored}, expected {[chair, keeper]}")

    page = page_ok(browser.get(f"{base}?id={meeting}"), "meeting with roles")
    draft = browser.post(f"{base}?id={meeting}", [("token", token_of(page)), ("action", "draft")], follow=False)
    expect(draft.status == 200 and draft.body[:5] == b"%PDF-", f"the draft is no PDF: HTTP {draft.status}")
    text = pdf_bytes_text(draft.body)
    for word in ("Protokoll", "Entwurf", "Anwesend", "Beschl"):
        expect(word in text, f"the draft lacks {word!r}")

    page_ok(browser.submit(page.form(name="vereinemeetingfinalize"), {"approved_on": stack.notes["website"]["dates"]["today"], "note": "Beschluss der Generalversammlung"}), "final version")
    version = stack.sql(f"SELECT rowid, version, approved_on IS NOT NULL, LENGTH(doc_sha) FROM llx_vereine_meeting_minutes WHERE fk_meeting = {meeting}")
    expect(version and version[0][1] == "1" and version[0][2] == "1" and version[0][3] == "64", f"the final version: {version}")
    run = stack.sql(f"SELECT s.rowid, s.status, COUNT(p.rowid) FROM llx_vereine_signature as s"
                    f" LEFT JOIN llx_vereine_signature_person as p ON p.fk_signature = s.rowid"
                    f" WHERE s.kind = 'minutes' AND s.fk_object = {version[0][0]} GROUP BY s.rowid, s.status")
    expect(run and run[0][1] == "open" and run[0][2] == "2", f"the signature run of the minutes: {run}")
    roles = stack.sql(f"SELECT function_code FROM llx_vereine_signature_person WHERE fk_signature = {run[0][0]} ORDER BY function_code")
    expect(roles == [["chair"], ["keeper"]], f"the minutes are signed by those two roles: {roles}")

    # The paper way finishes it: the signed minutes come back as a scan.
    page = page_ok(browser.get(f"{base}?id={meeting}"), "meeting with the final version")
    scanned = b"%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"
    page_ok(browser.post_multipart(f"{base}?id={meeting}", [("token", token_of(page)), ("action", "signscan"), ("signature", run[0][0])],
                                   [("scan_file", "protokoll-unterschrieben.pdf", scanned)]), "upload the signed minutes")
    expect(stack.value(f"SELECT status FROM llx_vereine_signature WHERE rowid = {run[0][0]}") == "done", "the scan did not finish the signature run")

    # Send it to the board: every board member with an e-mail gets exactly the stored PDF.
    mail = stack.mailpit()
    mail.clear()
    page = page_ok(browser.get(f"{base}?id={meeting}"), "meeting before sending")
    page_ok(browser.submit(page.form(name=f"vereinesendboard{version[0][0]}")), "send the minutes to the board")
    sent = stack.value(f"SELECT sent_board IS NOT NULL FROM llx_vereine_meeting_minutes WHERE rowid = {version[0][0]}")
    messages = mail.messages()
    expect(sent == "1" and messages, f"minutes sent: {sent}, {len(messages)} e-mails")
    attachments = mail.attachment_hashes(messages[0]["ID"])
    stored_sha = stack.value(f"SELECT doc_sha FROM llx_vereine_meeting_minutes WHERE rowid = {version[0][0]}")
    expect(any(name.startswith("protokoll-") and digest == stored_sha for name, digest in attachments.items()),
           f"the e-mail carries {list(attachments)} instead of the stored minutes")
    return (f"roles stored, draft as PDF with attendance and resolutions, final version 1 with checksum and a signature run for chair and keeper, "
            f"scan finished it, minutes mailed to the board ({len(messages)} e-mails, PDF identical to the stored version)")


def signatures(stack: Stack) -> str:
    """Signatures: who signs a letter, signing in Dolibarr with the password, the paper way as a scan, a changed document."""
    browser = stack.browser()
    setup = "/custom/vereine/admin/signatures.php"
    base = "/custom/vereine/authority.php"
    page = page_ok(browser.get(setup), "signature setup")
    kinds = re.findall(r'data-rule="([a-z_]+)" data-roles="(\d+)" data-mode="(all|min)"', page.text)
    expect([kind for kind, _, _ in kinds] == ["letter", "minutes", "resolution", "money", "audit_report"], f"kinds of document: {kinds}")
    expect(denied(stack.browser("rtreader").get(setup)), "a non-administrator opens the signature setup")

    # The chair signs letters; the runtime admin gets the chair's member, so it can sign in Dolibarr.
    chair = stack.value("SELECT t.fk_adherent FROM llx_vereine_function_term as t INNER JOIN llx_vereine_function as f ON f.rowid = t.fk_function"
                        " WHERE f.code = 'obmann' AND t.date_end IS NULL ORDER BY t.rowid DESC LIMIT 1")
    expect(chair is not None, "the functions scenario should leave a chair in office")
    stack.sql(f"UPDATE llx_user SET fk_member = {chair} WHERE login = 'admin'")
    page_ok(browser.submit(page.form(name="vereinesignaturerules"), {"rule[letter][roles][]": "obmann", "rule[letter][mode]": "all"},
                            drop=("rule[letter][roles][]",)), "letters are signed by the chair")
    stored = json.loads(stack.const("VEREINE_SIGNATURE_RULES") or "{}")
    expect(stored.get("letter", {}).get("roles") == ["obmann"], f"stored signature rules: {stored.get('letter')}")

    letters = page_ok(browser.get(base), "letters")
    runs = re.findall(r'data-signature="(\d+)" data-status="(\w+)" data-signed="(\d+)" data-needed="(\d+)"', letters.text)
    expect(runs, "a letter written in the letters scenario should have its signature run")
    run, status, signed, needed = runs[0]
    expect(status == "open" and signed == "0" and needed == "1", f"first run: {runs[0]}")

    refused = page_ok(browser.post(base, [("token", token_of(letters)), ("action", "sign"), ("signature", run), ("password", "falsch-" + stack.admin_password)]), "sign with a wrong password")
    expect("Passwort stimmt nicht" in html.unescape(refused.text), "a wrong password signed the letter")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_vereine_signature_person WHERE fk_signature = {run} AND signed_at IS NOT NULL") == "0", "a signature was stored without the password")
    page_ok(browser.post(base, [("token", token_of(letters)), ("action", "sign"), ("signature", run), ("password", stack.admin_password)]), "sign in Dolibarr")
    signed_row = stack.sql(f"SELECT way, signed_at IS NOT NULL FROM llx_vereine_signature_person WHERE fk_signature = {run}")
    state = stack.value(f"SELECT status FROM llx_vereine_signature WHERE rowid = {run}")
    expect(signed_row == [["click", "1"]] and state == "done", f"after signing: {signed_row}, status {state}")
    sheet = pdf_text(stack, "vereine/signatures")
    for word in ("Unterschriftenblatt", "SHA-256", "in Dolibarr"):
        expect(word in sheet, f"the signature sheet lacks {word!r}")

    # A second letter takes the paper way: the signed PDF is uploaded.
    other = [item for item in runs[1:]] or []
    if not other:
        second = page_ok(browser.get(base), "letters for the paper way")
        other = re.findall(r'data-signature="(\d+)" data-status="open"', second.text)
        other = [(identifier, "open", "0", "1") for identifier in other]
    expect(other, "a second open signature run is needed for the paper way")
    paper = other[0][0]
    letters = page_ok(browser.get(base), "letters before the upload")
    scanned = b"%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"
    refused = page_ok(browser.post_multipart(base, [("token", token_of(letters)), ("action", "signscan"), ("signature", paper)], [("scan_file", "scan.txt", scanned)]), "upload a text file")
    expect("Nur eine PDF-Datei" in html.unescape(refused.text), "a file that is no PDF was stored as signed document")
    page_ok(browser.post_multipart(base, [("token", token_of(letters)), ("action", "signscan"), ("signature", paper)], [("scan_file", "unterschrieben.pdf", scanned)]), "upload the signed PDF")
    paper_state = stack.sql(f"SELECT status, scan_name FROM llx_vereine_signature WHERE rowid = {paper}")
    ways = stack.sql(f"SELECT DISTINCT way FROM llx_vereine_signature_person WHERE fk_signature = {paper}")
    expect(paper_state and paper_state[0][0] == "done" and paper_state[0][1].startswith("unterschrieben-") and ways == [["paper"]],
           f"after the upload: {paper_state}, ways {ways}")

    # A document rebuilt after the run started must be signed again.
    name = stack.value(f"SELECT doc_name FROM llx_vereine_signature WHERE rowid = {run}")
    stack.shell(f"echo x >> /var/www/documents/vereine/authority/{name}")
    changed = page_ok(browser.get(base), "letters after the document changed")
    expect('data-signature-changed="1"' in changed.text, "a changed document is not reported")
    return ("5 kinds of document; letters signed by the chair; wrong password refused, signing in Dolibarr stored with the checksum and the sheet built; "
            "paper way: only PDF accepted, scan finishes the run; a changed document asks for new signatures")


def qes(stack: Stack) -> str:
    """ID Austria: the signature service in the setup, two people sign one PDF one after the other, cancel, a way back used twice, a changed PDF."""
    browser = stack.browser()
    setup = "/custom/vereine/admin/signatures.php"
    base = "/custom/vereine/authority.php"
    # A stand-in for PDF-AS beside Dolibarr (tests/runtime/pdfas_stub.php); its signatures are no real ones.
    installed = stack.shell("mkdir -p /var/www/html/custom/pdfas-stub && cp /opt/vereine-tests/pdfas_stub.php /var/www/html/custom/pdfas-stub/index.php"
                            " && cp /opt/vereine-tests/pdfas_sign.php /var/www/html/custom/pdfas-stub/pdfas_sign.php")
    expect(installed.returncode == 0, f"the stand-in for PDF-AS: {installed.stderr}")
    service = "http://127.0.0.1/custom/pdfas-stub/index.php"

    page = page_ok(browser.get(setup), "signature setup with the signature service")
    expect('data-qes-configured="0"' in page.text, "the signature service has to be off until an administrator enters its address")
    refused = page_ok(browser.submit(page.form(name="vereineqessetup"), {"qes_url": "ftp://signatur.example.at"}), "an address that is no web address")
    expect("mit http:// oder https://" in html.unescape(refused.text) and not stack.const("VEREINE_QES_URL"), "an address that is no web address was stored")
    page = page_ok(browser.get(setup), "signature setup")
    page_ok(browser.submit(page.form(name="vereineqessetup"), {"qes_url": service, "qes_connector": "mobilebku"}), "set up the signature service")
    expect(stack.const("VEREINE_QES_URL") == service and stack.const("VEREINE_QES_CONNECTOR") == "mobilebku", "the signature service was not stored")
    page = page_ok(browser.get(setup), "signature setup with the check")
    checked = page_ok(browser.submit(page.form(name="vereineqescheck")), "check the signature service")
    expect("Der Signaturdienst nimmt Dokumente an" in html.unescape(checked.text), "the check did not reach the signature service")

    # Letters: two functions held by two different people today, only with ID Austria.
    terms = stack.sql("SELECT f.code, t.fk_adherent FROM llx_vereine_function_term as t INNER JOIN llx_vereine_function as f ON f.rowid = t.fk_function"
                      " INNER JOIN llx_adherent as a ON a.rowid = t.fk_adherent WHERE f.active = 1 AND a.statut = 1 AND t.date_start <= CURDATE()"
                      " AND (t.date_end IS NULL OR t.date_end >= CURDATE()) ORDER BY t.rowid")
    held: dict[str, set[str]] = {}
    for code, member in terms:
        held.setdefault(code, set()).add(member)
    single = [(code, next(iter(members))) for code, members in held.items() if len(members) == 1]
    pair = next(((one, other) for one in single for other in single if one[1] != other[1]), None)
    expect(pair is not None, f"two functions held by two different people today: {held}")
    roles = [pair[0][0], pair[1][0]]
    form = page_ok(browser.get(setup), "signature rules").form(name="vereinesignaturerules")
    fields = [(name, value) for name, value in form.values() if not name.startswith("rule[letter]")]
    fields += [("rule[letter][roles][]", roles[0]), ("rule[letter][roles][]", roles[1]), ("rule[letter][mode]", "all"),
               ("rule[letter][min]", "2"), ("rule[letter][sign]", "qes")]
    page_ok(browser.post(form.url(), fields), "letters only with ID Austria")
    stored = json.loads(stack.const("VEREINE_SIGNATURE_RULES") or "{}").get("letter", {})
    expect(stored.get("sign") == "qes" and sorted(stored.get("roles", [])) == sorted(roles), f"stored rule for letters: {stored}")

    letter = stack.value("SELECT fk_object FROM llx_vereine_signature WHERE kind = 'letter' ORDER BY rowid LIMIT 1")
    expect(letter is not None, "the letters scenario should leave a letter")
    letters = page_ok(browser.get(base), "letters")
    page_ok(browser.post(base, [("token", token_of(letters)), ("action", "startsign"), ("object", letter)]), "start the signatures of the letter")
    run = stack.value(f"SELECT rowid FROM llx_vereine_signature WHERE kind = 'letter' AND fk_object = {letter} AND status = 'open' ORDER BY rowid DESC LIMIT 1")
    people = stack.sql(f"SELECT fk_adherent FROM llx_vereine_signature_person WHERE fk_signature = {run} ORDER BY rowid")
    expect(run is not None and len(people) == 2, f"{roles} sign the letter: run {run}, {people}")
    signed_count = f"SELECT COUNT(*) FROM llx_vereine_signature_person WHERE fk_signature = {run} AND signed_at IS NOT NULL"

    stack.sql(f"UPDATE llx_user SET fk_member = {people[0][0]} WHERE login = 'admin'")
    letters = page_ok(browser.get(base), "letters to sign with ID Austria")
    expect(f'name="vereinesignqes{run}"' in letters.text and f'name="vereinesign{run}"' not in letters.text and 'data-qes-only="1"' in letters.text,
           "only ID Austria is offered, not the password")
    refused = page_ok(browser.post(base, [("token", token_of(letters)), ("action", "sign"), ("signature", run), ("password", stack.admin_password)]), "sign with the password")
    expect("wird mit ID Austria unterschrieben" in html.unescape(refused.text) and stack.value(signed_count) == "0", "the password signed a letter that needs ID Austria")

    def to_service() -> str:
        page = page_ok(browser.get(base), "letters before signing")
        left = browser.submit(page.form(name=f"vereinesignqes{run}"), follow=False)
        location = left.headers.get("Location", "")
        expect(left.status in (302, 303) and "/custom/pdfas-stub/index.php/confirm?job=" in location, f"the way to the signature service: HTTP {left.status} {location}")
        return location

    back = page_ok(browser.get(to_service() + "&cancel=1"), "cancel on the phone")
    expect("nicht zustande gekommen" in html.unescape(back.text) and stack.value(signed_count) == "0", "cancelling on the phone did not say so, or signed")

    confirmed = browser.get(to_service() + "&name=" + urllib.parse.quote("Erika Muster"), follow=False)
    invoke = confirmed.headers.get("Location", "")
    expect("/custom/vereine/signature.php?qes=" in invoke and "pdfurl=" in invoke, f"the way back to Dolibarr: {invoke}")
    signed = page_ok(browser.get(invoke), "back in Dolibarr after signing")
    expect("Mit ID Austria unterschrieben" in html.unescape(signed.text), "signing with ID Austria was not confirmed")
    first = stack.sql(f"SELECT way, qes_subject FROM llx_vereine_signature_person WHERE fk_signature = {run} AND signed_at IS NOT NULL")
    expect(first == [["qes", "Erika Muster"]], f"the first signature, with the name of its certificate: {first}")
    replay = page_ok(browser.get(invoke), "the same way back a second time")
    expect("keiner offenen Unterschrift" in html.unescape(replay.text) and stack.value(signed_count) == "1", "a way back from the signature service counted twice")

    # The second person signs the PDF the first one signed.
    stack.sql(f"UPDATE llx_user SET fk_member = {people[1][0]} WHERE login = 'admin'")
    confirmed = browser.get(to_service() + "&name=" + urllib.parse.quote("Max Muster"), follow=False)
    page_ok(browser.get(confirmed.headers.get("Location", "")), "the second person signed")
    state = stack.value(f"SELECT status FROM llx_vereine_signature WHERE rowid = {run}")
    expect(state == "done" and stack.value(signed_count) == "2", f"two signatures finish the run: {state}")
    sheet = pdf_text(stack, "vereine/signatures")
    for word in ("Max Muster", "ID Austria"):
        expect(word in sheet, f"the signature sheet lacks {word!r}")

    letters = page_ok(browser.get(base), "letters signed with ID Austria")
    expect(f'data-qes-document="{run}"' in letters.text, "the PDF signed with ID Austria is not offered")
    verify = page_ok(browser.get(f"/custom/vereine/signature.php?signature={run}"), "what the signatures say")
    rows = re.findall(r'data-qes-intact="(\w+)" data-qes-name="([^"]*)" data-qes-certificate="(\w*)"', verify.text)
    expect(rows == [("yes", "Erika Muster", "valid"), ("yes", "Max Muster", "valid")], f"both signatures of one PDF, with MOA-SP: {rows}")
    # PDF-AS without MOA-SP says nothing about certificates; the module still reads the PDF itself.
    stack.shell("touch /tmp/pdfas-stub/no-moa")
    alone = page_ok(browser.get(f"/custom/vereine/signature.php?signature={run}"), "the signatures without MOA-SP")
    rows = re.findall(r'data-qes-intact="(\w+)" data-qes-name="([^"]*)" data-qes-certificate="(\w*)"', alone.text)
    expect(rows == [("yes", "Erika Muster", ""), ("yes", "Max Muster", "")] and 'data-qes-no-certificates="1"' in alone.text,
           f"the signatures without MOA-SP: {rows}")
    link = re.search(r'href="([^"]*signature\.php\?action=download[^"]*)"', verify.text)
    expect(link is not None, "no download of the signed PDF")
    download = browser.get(html.unescape(link.group(1)))
    expect(download.status == 200 and download.body.startswith(b"%PDF-") and download.body.count(b"%VEREINE-TESTSIGNATUR") == 2,
           f"the signed PDF: HTTP {download.status}, {download.body.count(b'%VEREINE-TESTSIGNATUR')} signatures")

    # Changed afterwards: no signature holds any more.
    stack.shell(f"printf X | dd of=/var/www/documents/vereine/signatures/qualifiziert-{run}.pdf bs=1 seek=40 conv=notrunc 2>/dev/null")
    changed = page_ok(browser.get(f"/custom/vereine/signature.php?signature={run}"), "a changed PDF")
    states = re.findall(r'data-qes-intact="(\w+)"', changed.text)
    expect(states == ["no", "no"], f"a PDF changed after signing still counts: {states}")

    # A key store for tests signs at once, without the phone.
    page = page_ok(browser.get(setup), "signature setup")
    page = page_ok(browser.submit(page.form(name="vereineqessetup"), {"qes_url": service, "qes_connector": "jks", "qes_key": "verein"}), "the test key store")
    expect('data-qes-test="1"' in page.text, "the test key store is not marked as a test")
    letters = page_ok(browser.get(base), "letters")
    page_ok(browser.post(base, [("token", token_of(letters)), ("action", "startsign"), ("object", letter)]), "start again")
    again = stack.value(f"SELECT rowid FROM llx_vereine_signature WHERE kind = 'letter' AND fk_object = {letter} AND status = 'open' ORDER BY rowid DESC LIMIT 1")
    page = page_ok(browser.get(base), "letters for the key store")
    page_ok(browser.submit(page.form(name=f"vereinesignqes{again}")), "sign with the test key store")
    at_once = stack.sql(f"SELECT way, qes_subject FROM llx_vereine_signature_person WHERE fk_signature = {again} AND signed_at IS NOT NULL")
    expect(at_once == [["qes", "Testschluessel verein"]], f"the test key store: {at_once}")
    return ("signature service off until set up, wrong address refused, check answered; letters only with ID Austria: password refused; "
            f"cancel on the phone stored nothing; {roles[0]} and {roles[1]} signed one PDF one after the other, a way back used twice refused; "
            "both signatures intact with and without MOA-SP, the PDF downloadable, a change afterwards breaks both; the test key store signs at once")


def minutestexts(stack: Stack) -> str:
    """Agenda templates with required items, a new meeting from a template, texts per item with the real numbers, texts follow a reordered agenda."""
    browser = stack.browser()
    setup = "/custom/vereine/admin/meetings.php"
    base = "/custom/vereine/meetings.php"
    templates = page_ok(browser.get(setup), "meeting templates")
    general_rows = len(re.findall(r'data-template="general" data-item="\d+" data-required="(\d)"', templates.text))
    required = re.findall(r'data-template="general" data-item="\d+" data-required="1"', templates.text)
    expect(general_rows == 13 and len(required) == 3, f"general assembly template: {general_rows} rows, {len(required)} required; expected 10 items and 3 empty rows, 3 required")
    expect(denied(stack.browser("rtreader").get(setup)), "a non-administrator opens the meeting templates")
    page_ok(browser.submit(templates.form(name="vereinetemplates"), {
        "template[board][5][title]": "Kassabericht", "template[board][5][text]": "Kassastand am {datum}\nbei {verein}", "template[board][5][required]": "1"}), "add a required board item")
    stored = stack.sql("SELECT kind, COUNT(*), SUM(mandatory) FROM llx_vereine_meeting_template GROUP BY kind ORDER BY kind")
    expect(stored == [["board", "6", "2"], ["extraordinary", "4", "1"], ["general", "10", "3"]], f"stored templates: {stored}")

    prefilled = page_ok(browser.get(f"{base}?template=board"), "new meeting from the board template")
    form = prefilled.form(name="vereinemeeting")
    agenda = (form.value("agenda") or "").splitlines()
    expect(form.value("kind") == "board", f"the board template chose the kind {form.value('kind')!r}")
    expect(agenda[0].startswith("Begrüßung") and agenda[-1] == "Kassabericht" and len(agenda) == 6, f"agenda from the board template: {agenda}")
    day = stack.notes["website"]["dates"]["today"]
    created = page_ok(browser.submit(form, {"day": day, "time": "19:00", "place": "Vereinsheim", "title": "Vorstandssitzung aus Vorlage"}), "store the meeting from the template")
    new_id = stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting")
    expect('data-missing-items' not in created.text, "a meeting from the template reports missing required items")
    notes = created.form(name="vereinemeetingnotes")
    expect("Kassastand am {datum}" in (notes.value("note[6]") or ""), f"the new meeting does not suggest the template text: {notes.value('note[6]')!r}")
    page_ok(browser.submit(notes, {"note[2]": "Vorbereitet für Punkt zwei"}), "prepare a text")
    reordered = page_ok(browser.get(f"{base}?id={new_id}"), "planned meeting").form(name="vereinemeeting")
    items = (reordered.value("agenda") or "").splitlines()
    page_ok(browser.submit(reordered, {"agenda": "\n".join([items[1]] + items[2:5])}), "reorder the agenda and drop the required items")
    moved = stack.sql(f"SELECT item FROM llx_vereine_meeting_note WHERE fk_meeting = {new_id} AND body = 'Vorbereitet für Punkt zwei'")
    card = page_ok(browser.get(f"{base}?id={new_id}"), "reordered meeting")
    missing = re.search(r'data-missing-items="(\d+)"', card.text)
    expect(moved == [["1"]] and missing is not None and missing.group(1) == "2" and "Kassabericht" in card.text,
           f"text after reordering at item {moved}, missing required items {missing.group(1) if missing else None}")

    general_id = stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting WHERE kind = 'general' AND status <> 'planned'")
    general = page_ok(browser.get(f"{base}?id={general_id}"), "general assembly")
    notes = general.form(name="vereinemeetingnotes")
    page_ok(browser.submit(notes, {"note[1]": "Anwesend {anwesend} von {stimmberechtigt}, {beschlussfaehig}.", "note[2]": "{ergebnis}", "note[3]": ""}), "texts of the general assembly")
    shown = page_ok(browser.get(f"{base}?id={general_id}"), "general assembly with texts")

    def preview(item: int) -> str:
        match = re.search(r'data-note-preview="' + str(item) + r'">(.*?)</div>', shown.text, re.S)
        return html.unescape(match.group(1)) if match else ""

    first, second = preview(1), preview(2)
    expect(re.fullmatch(r"Anwesend \d+ von \d+, (nicht )?beschlussfähig\.", first) is not None, f"item 1 with real numbers: {first!r}")
    expect("Budget: angenommen mit" in second and "{" not in second, f"item 2 with the result of its vote: {second!r}")
    empty = shown.form(name="vereinemeetingnotes").value("note[3]")
    stored = stack.value(f"SELECT COUNT(*) FROM llx_vereine_meeting_note WHERE fk_meeting = {general_id}")
    agenda_items = len(re.findall(r'data-note="\d+"', shown.text))
    expect(empty == "" and 'data-note-preview="3"' not in shown.text and stored == str(agenda_items), f"emptied text: {empty!r}, {stored} texts stored for {agenda_items} items")

    page_ok(browser.submit(page_ok(browser.get(setup), "meeting templates").form(name="vereinetemplatesreset")), "restore the templates")
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_meeting_template") == "0", "restoring the suggested templates kept stored templates")
    return (f"general assembly template with 10 items, 3 required; own required board item stored; new meeting from the template with its texts; "
            f"text follows the reordered agenda, 2 missing required items warned; general assembly texts: {first!r}, result of the vote filled, emptied text stays empty; templates restored")


def resolutions(stack: Stack) -> str:
    """The register: every vote in it, search and filters, wording and validity, a follow-up as a task of Dolibarr, the agenda suggestion."""
    browser = stack.browser()
    base = "/custom/vereine/resolutions.php"
    votes = int(stack.value("SELECT COUNT(*) FROM llx_vereine_meeting_vote"))
    kept = int(stack.value("SELECT COUNT(*) FROM llx_vereine_resolution"))
    expect(votes > 0 and kept == votes, f"{kept} entries in the register for {votes} votes")

    page = page_ok(browser.get(base), "register of resolutions")
    listed = re.findall(r'data-resolution="(\d+)" data-category="([a-z]+)" data-passed="(\d)" data-open="(\d+)"', page.text)
    numbers = re.findall(r'\?id=\d+">(\d{4}-\d+)</a>', page.text)
    expect(len(listed) == votes and len(set(numbers)) == votes,
           f"the register lists {len(listed)} resolutions with the numbers {numbers}, expected {votes} different ones")
    expect(sorted({category for _, category, _, _ in listed}) == ["organe", "sonstiges", "statuten"],
           f"categories the register gave by itself: {sorted({category for _, category, _, _ in listed})}")

    def rows(query: str, what: str) -> list:
        found = page_ok(browser.get(f"{base}?{query}"), what)
        return re.findall(r'data-resolution="(\d+)"', found.text)

    expect(len(rows("search=Budget", "search for Budget")) == 1, "the search for a title found no single resolution")
    expect(len(rows("search=trikots", "search before the wording")) == 0, "a word nobody wrote is already in the register")
    expect(len(rows("category=organe", "elections")) == 1, "the election is not the only resolution about the bodies")
    expect(len(rows("result=rejected", "rejected")) == 1, "exactly one vote of the meetings was rejected")
    expect(len(rows("year=1999", "another year")) == 0, "a year without a meeting listed something")

    # The board resolution gets its wording, its category and a validity.
    entry = stack.value("SELECT rowid FROM llx_vereine_resolution WHERE title = 'Anschaffung'")
    expect(entry is not None, "the board resolution Anschaffung is missing in the register")
    page = page_ok(browser.get(f"{base}?id={entry}"), "the board resolution")
    refused = page_ok(browser.submit(page.form(name="vereineresolution"),
                                     {"wording": "Der Vorstand kauft Trikots.", "category": "finanzen", "valid_from": "2026-12-31", "valid_to": "2026-01-01"}),
                      "a validity that ends before it starts")
    expect("liegt vor" in html.unescape(refused.text) and stack.value(f"SELECT wording IS NULL FROM llx_vereine_resolution WHERE rowid = {entry}") == "1",
           "a validity that ends before it starts was stored")
    page = page_ok(browser.get(f"{base}?id={entry}"), "the board resolution again")
    page_ok(browser.submit(page.form(name="vereineresolution"),
                           {"wording": "Der Vorstand kauft Trikots.", "category": "finanzen", "valid_from": "2026-01-01", "valid_to": "2026-12-31",
                            "note": "Angebot der Beispiel GmbH"}), "wording, category and validity")
    saved = stack.sql(f"SELECT wording, category, valid_from, valid_to FROM llx_vereine_resolution WHERE rowid = {entry}")
    expect(saved == [["Der Vorstand kauft Trikots.", "finanzen", "2026-01-01", "2026-12-31"]], f"stored entry: {saved}")
    expect(len(rows("search=trikots", "search in the wording")) == 1, "the search does not find the resolution by its wording")
    expect(len(rows("category=finanzen", "money matters")) == 1, "the chosen category does not filter")

    # What follows from it: a task with somebody responsible, as a to-do of Dolibarr.
    member = stack.value("SELECT rowid FROM llx_adherent WHERE statut = 1 ORDER BY rowid LIMIT 1")
    page = page_ok(browser.get(f"{base}?id={entry}"), "the resolution before its follow-up")
    refused = page_ok(browser.submit(page.form(name="vereineresolutiontask"), {"label": "Trikots bestellen", "member_id": "0"}),
                      "a follow-up without somebody responsible")
    expect("zuständige" in html.unescape(refused.text) and stack.value("SELECT COUNT(*) FROM llx_vereine_resolution_task") == "0",
           "a follow-up without somebody responsible was stored")
    page = page_ok(browser.get(f"{base}?id={entry}"), "the resolution again")
    page_ok(browser.submit(page.form(name="vereineresolutiontask"),
                           {"label": "Trikots bestellen", "member_id": member, "deadline": "2026-10-01"}), "the follow-up")
    task = stack.sql("SELECT rowid, fk_adherent, deadline, fk_actioncomm IS NOT NULL, done_at IS NULL FROM llx_vereine_resolution_task")
    expect(task and task[0][1] == member and task[0][2] == "2026-10-01" and task[0][3] == "1" and task[0][4] == "1", f"the stored follow-up: {task}")
    event = stack.sql(f"SELECT percent, elementtype, fk_element, datep FROM llx_actioncomm WHERE id = "
                      f"(SELECT fk_actioncomm FROM llx_vereine_resolution_task WHERE rowid = {task[0][0]})")
    expect(event and event[0][0] == "0" and event[0][1] == "member" and event[0][2] == member and event[0][3].startswith("2026-10-01"),
           f"the to-do of Dolibarr for the follow-up: {event}")
    expect(len(rows("open=1", "only open follow-ups")) == 1, "the filter for open follow-ups does not find the resolution")

    # The open follow-up is offered as an item of the next agenda.
    meetings = "/custom/vereine/meetings.php"
    page = page_ok(browser.get(f"{meetings}?template=board"), "a new board meeting from the template")
    expect(f'data-follow="{task[0][0]}"' in page.text, "the new meeting does not offer the open follow-up")
    form = page.form(name="vereinemeeting")
    page_ok(browser.submit(form, {"kind": "board", "title": "Vorstandssitzung mit Folgen", "day": "2026-11-05", "time": "19:00",
                                  "place": "Vereinsheim", "agenda": "Begrüßung", "follow[]": task[0][0]}), "a meeting that takes the follow-up over")
    stored_agenda = stack.value("SELECT agenda FROM llx_vereine_meeting WHERE title = 'Vorstandssitzung mit Folgen'")
    # The client of MariaDB doubles a backslash of its own, and the agenda is stored as JSON.
    items = json.loads(stored_agenda.replace("\\\\", "\\")) if stored_agenda else []
    expect(len(items) == 2 and items[0] == "Begrüßung" and items[1].startswith("Trikots bestellen (offen aus Beschluss"),
           f"the agenda of the new meeting: {items}")

    # The register as CSV, through the link of the page: from Dolibarr 24 on an action in the address needs its token.
    listing = page_ok(browser.get(f"{base}?search=trikots"), "the register filtered for the wording")
    link = re.search(r'href="([^"]*action=export[^"]*)"', listing.text)
    expect(link is not None, "the register offers no export")
    export = browser.get(html.unescape(link.group(1)), follow=False)
    expect(export.status == 200 and "text/csv" in export.headers.get("Content-Type", "") and "beschlussbuch.csv" in export.headers.get("Content-Disposition", ""),
           f"the export answered HTTP {export.status} as {export.headers.get('Content-Type')}")
    csv = export.body.decode("utf-8")
    expect(csv.startswith("\ufeff") and csv.count("\r\n") == 2 and "Der Vorstand kauft Trikots." in csv and "finanzen" not in csv and "Finanzen" in csv,
           f"the CSV of the register: {csv[:200]!r}")
    candidate = stack.value("SELECT fk_adherent FROM llx_vereine_resolution WHERE kind = 'election'")
    card = page_ok(browser.get(f"/custom/vereine/member_association.php?id={candidate}"), "the member who was elected")
    expect(re.search(r'data-member-resolution="\d+" data-passed="1"', card.text) is not None, "the election is not shown at the member")
    other = page_ok(browser.get(f"/custom/vereine/member_association.php?id={member}"), "a member without a resolution")
    expect('data-member-resolution=' not in other.text or member == candidate, "a member gets resolutions that do not concern them")

    # Done: the to-do of Dolibarr is done as well, and the filter for open ones finds nothing.
    page = page_ok(browser.get(f"{base}?id={entry}"), "the resolution with its follow-up")
    page_ok(browser.submit(page.form(name=f"vereineresolutiontaskdone{task[0][0]}")), "the follow-up is done")
    done = stack.sql(f"SELECT done_at IS NOT NULL, (SELECT percent FROM llx_actioncomm WHERE id = fk_actioncomm) FROM llx_vereine_resolution_task WHERE rowid = {task[0][0]}")
    expect(done == [["1", "100"]], f"the follow-up and its to-do after it was done: {done}")
    expect(len(rows("open=1", "only open follow-ups after it was done")) == 0, "a resolution without an open follow-up is still listed as open")
    logged = stack.value("SELECT COUNT(*) FROM llx_vereine_log WHERE action LIKE 'resolution%'")
    expect(int(logged) >= votes + 3, f"{logged} entries in the log for {votes} resolutions, an entry saved and a follow-up opened and done")
    return (f"{votes} votes in the register with their number and category; search by title and wording, filters by organ, result, year and open follow-ups; "
            "wording and validity stored, a validity that ends before it starts refused; follow-up as a to-do of Dolibarr at the member, "
            "offered as an item of the next agenda, done in both places; CSV export")


def resolutiondocs(stack: Stack) -> str:
    """Every resolution as its own PDF: what it rests on, the signature run of its own, and an excerpt of several."""
    browser = stack.browser()
    base = "/custom/vereine/resolutions.php"
    entry = stack.value("SELECT rowid FROM llx_vereine_resolution WHERE title = 'Anschaffung'")
    page = page_ok(browser.get(f"{base}?id={entry}"), "a resolution without its PDF")
    expect('data-resolution-pdf="0"' in page.text, "the resolution has a PDF before anybody built one")

    page_ok(browser.submit(page.form(name="vereineresolutionbuild")), "build the PDF of the resolution")
    page = page_ok(browser.get(f"{base}?id={entry}"), "the resolution with its PDF")
    expect('data-resolution-pdf="1"' in page.text, "the PDF of the resolution was not built")
    link = re.search(r'href="([^"]*action=pdf[^"]*)"', page.text)
    expect(link is not None, "the resolution offers no download of its PDF")
    document = browser.get(html.unescape(link.group(1)), follow=False)
    expect(document.status == 200 and document.body[:5] == b"%PDF-", f"the download of the PDF answered HTTP {document.status}")
    text = pdf_bytes_text(document.body)
    ref = stack.value(f"SELECT ref FROM llx_vereine_resolution WHERE rowid = {entry}")
    for word in (f"Beschluss {ref}", "Der Vorstand kauft Trikots.", "angenommen", "Vorstandssitzung", "Mehrheit"):
        expect(word in text, f"the PDF of the resolution lacks {word!r}; it has {text[:400]!r}")

    # It gets a signature run of its own, with the people the rules name for a resolution.
    page = page_ok(browser.get(f"{base}?id={entry}"), "the resolution before its signature run")
    page_ok(browser.submit(page.form(name=f"vereinestartsignresolution{entry}")), "start the signature run of the resolution")
    run = stack.sql(f"SELECT rowid, status, doc_name FROM llx_vereine_signature WHERE kind = 'resolution' AND fk_object = {entry}")
    expect(len(run) == 1 and run[0][1] == "open" and run[0][2] == f"beschluss-{entry}.pdf", f"the signature run of the resolution: {run}")
    roles = sorted(row[0] for row in stack.sql(f"SELECT function_code FROM llx_vereine_signature_person WHERE fk_signature = {run[0][0]}"))
    expect(roles == ["obmann"] or "obmann" in roles, f"a resolution is signed by these functions: {roles}")

    page = page_ok(browser.get(f"{base}?id={entry}"), "the resolution with an open run")
    page_ok(browser.post(base, [("token", token_of(page)), ("action", "sign"), ("signature", run[0][0]), ("password", stack.admin_password)]),
            "sign the resolution in Dolibarr")
    signed = stack.sql(f"SELECT way, signed_at IS NOT NULL FROM llx_vereine_signature_person WHERE fk_signature = {run[0][0]} AND signed_at IS NOT NULL")
    expect(signed == [["click", "1"]], f"after signing in Dolibarr: {signed}")

    # A money matter is signed by the kind of document that asks for the treasurer as well.
    money = stack.value("SELECT rowid FROM llx_vereine_resolution WHERE kind = 'election'")
    page = page_ok(browser.get(f"{base}?id={money}"), "the election as a money matter")
    page_ok(browser.submit(page.form(name="vereineresolution"), {"money": "1", "wording": "Anschaffung von Trikots"}), "mark it as a money matter")
    expect(stack.value(f"SELECT money FROM llx_vereine_resolution WHERE rowid = {money}") == "1", "the money matter was not stored")
    page = page_ok(browser.get(f"{base}?id={money}"), "the money matter")
    page_ok(browser.submit(page.form(name="vereineresolutionbuild")), "build the PDF of the money matter")
    # Earlier scenarios end the treasurer's term; somebody has to hold it today for the run to name them.
    kassier = stack.value("SELECT rowid FROM llx_vereine_function WHERE code = 'kassier'")
    today = stack.value("SELECT CURDATE()")
    holding = stack.value(f"SELECT COUNT(*) FROM llx_vereine_function_term WHERE fk_function = {kassier} "
                          f"AND date_start <= '{today}' AND (date_end IS NULL OR date_end >= '{today}')")
    if holding == "0":
        free = stack.value(f"SELECT d.rowid FROM llx_adherent as d WHERE d.statut = 1 AND d.rowid NOT IN (SELECT fk_adherent FROM llx_vereine_function_term "
                           f"WHERE date_end IS NULL OR date_end >= '{today}') ORDER BY d.rowid LIMIT 1")
        tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={free}"), "a member without a function")
        page_ok(browser.post(f"/custom/vereine/member_association.php?id={free}", [("token", token_of(tab)), ("action", "addfunction"),
                                                                                  ("function_id", kassier), ("function_start", today)]), "a treasurer for today")
    page = page_ok(browser.get(f"{base}?id={money}"), "the money matter with its PDF")
    page_ok(browser.submit(page.form(name=f"vereinestartsignmoney{money}")), "start the signature run of the money matter")
    money_run = stack.sql(f"SELECT rowid, kind FROM llx_vereine_signature WHERE fk_object = {money} AND kind = 'money'")
    expect(len(money_run) == 1, f"the money matter did not get its own kind of signature run: {money_run}")
    money_roles = sorted(row[0] for row in stack.sql(f"SELECT function_code FROM llx_vereine_signature_person WHERE fk_signature = {money_run[0][0]}"))
    expect("kassier" in money_roles, f"a money matter is signed by {money_roles}, expected the treasurer among them")

    # A PDF built again is a new document, so the run asks for new signatures.
    page = page_ok(browser.get(f"{base}?id={entry}"), "the signed resolution")
    page_ok(browser.submit(page.form(name="vereineresolutionbuild")), "build the PDF again")
    changed = page_ok(browser.get(f"{base}?id={entry}"), "the resolution after the PDF changed")
    expect('data-signature-changed="1"' in changed.text, "a rebuilt PDF is not reported as a changed document")

    # The election names function, person and term of office.
    election = stack.value("SELECT rowid FROM llx_vereine_resolution WHERE kind = 'election'")
    page = page_ok(browser.get(f"{base}?id={election}"), "the election in the register")
    page_ok(browser.submit(page.form(name="vereineresolutionbuild")), "build the PDF of the election")
    election_pdf = pdf_bytes_text(browser.get(f"{base}?action=pdf&id={election}&token={token_of(page)}", follow=False).body)
    expect("Wahl" in election_pdf and "Funktionsperiode" in election_pdf, f"the PDF of an election lacks its term: {election_pdf[:400]!r}")

    # Several resolutions as one excerpt, which is handed over and not kept.
    listing = page_ok(browser.get(base), "the register")
    before = stack.shell("ls /var/www/documents/vereine/resolutions | wc -l").stdout.strip()
    refused = page_ok(browser.post(base, [("token", token_of(listing)), ("action", "excerpt")]), "an excerpt without a choice")
    expect("Wähle zuerst" in html.unescape(refused.text), "an excerpt without a chosen resolution was built")
    excerpt = browser.post(base, [("token", token_of(listing)), ("action", "excerpt"), ("pick[]", entry), ("pick[]", election)], follow=False)
    expect(excerpt.status == 200 and excerpt.body[:5] == b"%PDF-", f"the excerpt answered HTTP {excerpt.status}")
    excerpt_text = pdf_bytes_text(excerpt.body)
    election_ref = stack.value(f"SELECT ref FROM llx_vereine_resolution WHERE rowid = {election}")
    expect("Beschluss-Auszug" in excerpt_text and f"Beschluss {ref}" in excerpt_text and f"Beschluss {election_ref}" in excerpt_text,
           f"the excerpt does not carry both resolutions: {excerpt_text[:400]!r}")
    after = stack.shell("ls /var/www/documents/vereine/resolutions | wc -l").stdout.strip()
    expect(before == after, f"the excerpt stayed on the server: {before} files before, {after} after")
    return ("PDF per resolution with wording, result, majority and quorum; downloaded, signed in Dolibarr and reported as changed after "
            "it was built again; an election names function, person and term; excerpt of two resolutions handed over and not kept")


def circulars(stack: Stack) -> str:
    """Circular resolutions: off unless the statutes allow them, only the board votes, the result lands in the register."""
    browser = stack.browser()
    base = "/custom/vereine/circulars.php"
    setup = "/custom/vereine/admin/statutes.php"
    mail = stack.mailpit()

    # With the switch off there is no circular resolution at all.
    page = page_ok(browser.get(base), "circular resolutions with the switch off")
    expect('data-circular-allowed="0"' in page.text and "Statuten erlauben Umlaufbeschl" in html.unescape(page.text),
           "with the switch off the page does not say that the statutes decide")
    expect('name="vereinecircular"' not in page.text, "the page offers a new circular resolution although the statutes do not allow it")

    # The statutes allow them, and ask that nobody objects to the procedure.
    before = page_ok(browser.get("/custom/vereine/admin/statutes.php"), "the statutes before the switch")
    expect("im Umlaufweg" not in html.unescape(before.text), "the statutes name a circular resolution although the rules forbid it")
    form = page_ok(browser.get(setup), "statute setup").form(name="vereinestatutes")
    page_ok(browser.submit(form, {"circular": "1", "circular_no_objection": "1"}), "the statutes allow circular resolutions")
    after = html.unescape(page_ok(browser.get("/custom/vereine/admin/statutes.php"), "the statutes after the switch").text)
    expect("im Umlaufweg" in after and "widerspricht" in after, "the statutes do not follow the switch for circular resolutions")
    stored = json.loads(stack.const("VEREINE_STATUTE_RULES") or "{}")
    expect(stored.get("circular") is True and stored.get("circular_no_objection") is True, f"stored rules: {stored.get('circular')}, {stored.get('circular_no_objection')}")

    # Everybody of the board gets it by e-mail, with the link into Dolibarr.
    mail.clear()
    page = page_ok(browser.get(base), "circular resolutions with the switch on")
    voters = int(re.search(r'data-circular-voters="(\d+)"', page.text).group(1))
    expect(voters >= 2, f"the board should have at least two members who may vote, found {voters}")
    refused = page_ok(browser.submit(page.form(name="vereinecircular"), {"title": "Trikots", "wording": "Der Vorstand kauft Trikots.", "deadline": "2020-01-01"}),
                      "a deadline in the past")
    expect("Frist" in html.unescape(refused.text) and stack.value("SELECT COUNT(*) FROM llx_vereine_circular") == "0",
           "a circular resolution with a deadline in the past was started")
    page = page_ok(browser.get(base), "circular resolutions before the start")
    deadline = (datetime.date.today() + datetime.timedelta(days=10)).isoformat()
    page_ok(browser.submit(page.form(name="vereinecircular"),
                           {"title": "Trikots im Umlauf", "wording": "Der Vorstand kauft Trikots f\u00fcr 800 Euro.", "deadline": deadline}),
            "start the circular resolution")
    circular = stack.sql("SELECT rowid, status, deadline FROM llx_vereine_circular")
    expect(len(circular) == 1 and circular[0][1] == "open" and circular[0][2] == deadline, f"the circular resolution: {circular}")
    rows = stack.sql(f"SELECT COUNT(*), COUNT(invited_at) FROM llx_vereine_circular_vote WHERE fk_circular = {circular[0][0]}")
    messages = mail.messages()
    expect(rows == [[str(voters), str(len(messages))]] and messages, f"votes {rows}, {len(messages)} e-mails for {voters} board members")
    body = mail.message(messages[0]["ID"])
    expect("circulars.php" in json.dumps(body), "the e-mail carries no link to the circular resolution in Dolibarr")

    # Only the board votes, and only once; the admin is linked to the chair's member by the signatures scenario.
    identifier = circular[0][0]
    page = page_ok(browser.get(f"{base}?id={identifier}"), "the circular resolution")
    expect('name="vereinecircularvote"' in page.text, "the chair is not offered a vote")
    page_ok(browser.submit(page.form(name="vereinecircularvote"), {"choice": "yes"}), "the chair votes yes")
    chair = stack.value("SELECT fk_member FROM llx_user WHERE login = 'admin'")
    mine = stack.sql(f"SELECT choice, voted_at IS NOT NULL FROM llx_vereine_circular_vote WHERE fk_circular = {identifier} AND fk_adherent = {chair}")
    expect(mine == [["yes", "1"]], f"the stored vote of the chair: {mine}")
    again = page_ok(browser.post(f"{base}?id={identifier}", [("token", token_of(page)), ("action", "vote"), ("choice", "no")]), "vote a second time")
    expect("schon abgestimmt" in html.unescape(again.text), "somebody voted twice")
    expect('name="vereinecircularvote"' not in page_ok(browser.get(f"{base}?id={identifier}"), "the circular resolution after the vote").text,
           "the vote is offered again after it was given")

    # Nothing is counted while the deadline runs and votes are missing; a reminder goes to those who are missing.
    refused = page_ok(browser.post(f"{base}?id={identifier}", [("token", token_of(page)), ("action", "close")]), "count too early")
    expect("noch nicht alle" in html.unescape(refused.text) and stack.value(f"SELECT status FROM llx_vereine_circular WHERE rowid = {identifier}") == "open",
           "the result was counted although the deadline runs and votes are missing")
    mail.clear()
    page = page_ok(browser.get(f"{base}?id={identifier}"), "the circular resolution before the reminder")
    page_ok(browser.submit(page.form(name="vereinecircularremind")), "remind the missing board members")
    reminders = mail.messages()
    expect(stack.value(f"SELECT reminded_at IS NOT NULL FROM llx_vereine_circular WHERE rowid = {identifier}") == "1" and reminders,
           f"{len(reminders)} reminders sent")
    expect(all("Erinnerung" in message.get("Subject", "") for message in reminders), f"subjects of the reminders: {[m.get('Subject') for m in reminders]}")

    # The other board members vote, so the result can be counted and lands in the register.
    others = [row[0] for row in stack.sql(f"SELECT fk_adherent FROM llx_vereine_circular_vote WHERE fk_circular = {identifier} AND voted_at IS NULL")]
    expect(others, "every board member has voted already, the test proves nothing")
    for index, member in enumerate(others):
        choice = "yes" if index == 0 else "abstain"
        stack.sql(f"UPDATE llx_vereine_circular_vote SET choice = '{choice}', voted_at = NOW() WHERE fk_circular = {identifier} AND fk_adherent = {member}")
    page = page_ok(browser.get(f"{base}?id={identifier}"), "the circular resolution with every vote")
    expect('data-ready="1"' in page.text, "with every vote given the result is still not ready")
    page_ok(browser.submit(page.form(name="vereinecircularclose")), "count the result")
    decided = stack.sql(f"SELECT status, passed, yes, no, abstain, fk_resolution FROM llx_vereine_circular WHERE rowid = {identifier}")
    expect(decided and decided[0][0] == "decided" and decided[0][1] == "1" and int(decided[0][5]) > 0, f"after counting: {decided}")
    entry = stack.sql(f"SELECT source, organ, passed, wording FROM llx_vereine_resolution WHERE rowid = {decided[0][5]}")
    expect(entry and entry[0][0] == "circular" and entry[0][1] == "board" and entry[0][2] == "1" and "Trikots" in entry[0][3],
           f"the register entry of the circular resolution: {entry}")
    register = page_ok(browser.get(f"/custom/vereine/resolutions.php?id={decided[0][5]}"), "the circular resolution in the register")
    page_ok(browser.submit(register.form(name="vereineresolutionbuild")), "build the PDF of the circular resolution")
    pdf = pdf_bytes_text(browser.get(f"/custom/vereine/resolutions.php?action=pdf&id={decided[0][5]}&token={token_of(register)}", follow=False).body)
    expect("Trikots" in pdf and "Vorstand" in pdf, f"the PDF of a circular resolution: {pdf[:300]!r}")

    # One objection ends a circular resolution where the statutes ask that nobody objects.
    page = page_ok(browser.get(base), "circular resolutions for the objection")
    page_ok(browser.submit(page.form(name="vereinecircular"),
                           {"title": "Zweite Anschaffung", "wording": "Der Vorstand kauft Ausr\u00fcstung.", "deadline": deadline}), "a second circular resolution")
    second = stack.value("SELECT MAX(rowid) FROM llx_vereine_circular")
    page = page_ok(browser.get(f"{base}?id={second}"), "the second circular resolution")
    page_ok(browser.submit(page.form(name="vereinecircularvote"), {"choice": "objection"}), "object to the procedure")
    page = page_ok(browser.get(f"{base}?id={second}"), "the second circular resolution after the objection")
    expect('data-ready="1"' in page.text, "an objection does not end the circular resolution at once")
    page_ok(browser.submit(page.form(name="vereinecircularclose")), "count the objection")
    objected = stack.sql(f"SELECT status, passed, objection, fk_resolution FROM llx_vereine_circular WHERE rowid = {second}")
    expect(objected == [["cancelled", "0", "1", "0"]], f"after the objection: {objected}")
    logged = stack.value("SELECT COUNT(*) FROM llx_vereine_log WHERE action LIKE 'circular%'")
    expect(int(logged) >= 6, f"{logged} entries in the log of the circular resolutions")
    return (f"switch off: no circular resolution, the page says the statutes decide; on: {voters} board members invited by e-mail with the link, "
            "a deadline in the past refused, nobody votes twice, nothing counted while votes are missing, reminder to the missing ones; "
            "result counted, in the register as a resolution of the board and as PDF; one objection ends a circular resolution")


def meetingdocs(stack: Stack) -> str:
    """Documents of a meeting: the count sheet to print, proof of a vote, a signed proxy, and the attachments in the minutes."""
    browser = stack.browser()
    base = "/custom/vereine/meetings.php"
    meeting = stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting WHERE kind = 'general' AND status <> 'planned'")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the general assembly")

    # The count sheet is a working paper: printed before the meeting, not kept on the server.
    refused = page_ok(browser.submit(page.form(name="vereinemeetingsheet"), {"question": ""}), "a count sheet without a question")
    expect("Frage" in html.unescape(refused.text), "a count sheet without a question was built")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the general assembly again")
    sheet = browser.post(f"{base}?id={meeting}", [("token", token_of(page)), ("action", "countsheet"), ("item", "3"),
                                                  ("question", "Wahl Kassier:in"), ("candidates", "Paula Beispiel\nSam Beispiel"), ("rows", "5")], follow=False)
    expect(sheet.status == 200 and sheet.body[:5] == b"%PDF-", f"the count sheet answered HTTP {sheet.status}")
    text = pdf_bytes_text(sheet.body)
    for word in ("Z\u00e4hlliste", "Wahl Kassier:in", "Paula Beispiel", "Wahlleitung", "Enthaltung"):
        expect(word in text, f"the count sheet lacks {word!r}; it has {text[:400]!r}")
    kept = stack.shell(f"ls /var/www/documents/vereine/meetings/{meeting} 2>/dev/null | wc -l").stdout.strip()
    expect(kept in ("0", ""), f"the count sheet stayed on the server: {kept} files")

    # Proof of a vote: only a scan or a photo, kept with its checksum.
    vote = stack.value(f"SELECT MIN(rowid) FROM llx_vereine_meeting_vote WHERE fk_meeting = {meeting}")
    scanned = b"%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the assembly before the upload")
    refused = page_ok(browser.post_multipart(f"{base}?id={meeting}", [("token", token_of(page)), ("action", "updoc"), ("kind", "vote"),
                                                                      ("object", vote), ("label", "Z\u00e4hlliste")],
                                             [("doc_file", "liste.txt", b"kein Scan")]), "upload a text file")
    expect("Nur PDF oder Bild" in html.unescape(refused.text) and stack.value("SELECT COUNT(*) FROM llx_vereine_meeting_document") == "0",
           "a text file was taken as proof of a vote")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the assembly before the second upload")
    page_ok(browser.post_multipart(f"{base}?id={meeting}", [("token", token_of(page)), ("action", "updoc"), ("kind", "vote"),
                                                            ("object", vote), ("label", "Z\u00e4hlliste der Wahl")],
                                   [("doc_file", "zaehlliste.pdf", scanned)]), "upload the filled count sheet")
    stored = stack.sql(f"SELECT kind, fk_vote, fk_adherent, filename, LENGTH(doc_sha) FROM llx_vereine_meeting_document WHERE fk_meeting = {meeting}")
    expect(stored == [["vote", vote, "0", f"vote-{vote}-" + stored[0][3].split("-", 2)[2], "64"]] if stored else False,
           f"the stored proof of the vote: {stored}")
    expect(stack.shell(f"test -f /var/www/documents/vereine/meetings/{meeting}/{stored[0][3]}").returncode == 0,
           f"the file {stored[0][3]} is not below the documents of the meeting")

    # A vote of another meeting takes nothing.
    other = stack.value(f"SELECT MIN(rowid) FROM llx_vereine_meeting_vote WHERE fk_meeting <> {meeting}")
    if other is not None:
        page = page_ok(browser.get(f"{base}?id={meeting}"), "the assembly before the foreign vote")
        refused = page_ok(browser.post_multipart(f"{base}?id={meeting}", [("token", token_of(page)), ("action", "updoc"), ("kind", "vote"), ("object", other)],
                                                 [("doc_file", "fremd.pdf", scanned)]), "upload for a vote of another meeting")
        expect("nicht zu dieser Sitzung" in html.unescape(refused.text), "a vote of another meeting took a document")

    # A signed proxy hangs at the attendance entry of the member who gave it.
    represented = stack.value(f"SELECT fk_adherent FROM llx_vereine_meeting_attendance WHERE fk_meeting = {meeting} AND state = 'represented' LIMIT 1")
    expect(represented is not None, "the attendance scenario should leave a represented member")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the assembly before the proxy")
    page_ok(browser.post_multipart(f"{base}?id={meeting}", [("token", token_of(page)), ("action", "updoc"), ("kind", "proxy"),
                                                            ("object", represented), ("label", "Vollmacht")],
                                   [("doc_file", "vollmacht.jpg", b"\xff\xd8\xff\xe0 kein echtes Bild")]), "upload the signed proxy")
    proxy = stack.sql(f"SELECT kind, fk_adherent, filename FROM llx_vereine_meeting_document WHERE fk_meeting = {meeting} AND kind = 'proxy'")
    expect(len(proxy) == 1 and proxy[0][1] == represented and proxy[0][2].endswith(".jpg"), f"the stored proxy: {proxy}")

    # The documents are listed, downloadable with the right and refused without.
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the assembly with its documents")
    listed = re.findall(r'data-document="(\d+)" data-kind="([a-z]+)"', page.text)
    expect(len(listed) == 2 and sorted(kind for _, kind in listed) == ["proxy", "vote"], f"the documents of the meeting: {listed}")
    expect(f'data-vote-document=' in page.text, "the proof is not shown at the vote it belongs to")
    link = re.search(r'href="([^"]*action=document[^"]*)"', page.text)
    download = browser.get(html.unescape(link.group(1)), follow=False)
    expect(download.status == 200 and download.body[:5] == b"%PDF-", f"the download of a document answered HTTP {download.status}")
    expect(denied(stack.browser("rtnobody").get(html.unescape(link.group(1)))), "somebody without rights downloads the documents of a meeting")
    reader = page_ok(stack.browser("rtreader").get(f"{base}?id={meeting}"), "the assembly as reader")
    expect('name="vereinemeetingdoc"' not in reader.text and 'action=document' in reader.text,
           "the reader may upload documents or cannot see them at all")

    # The minutes name the attachments.
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the assembly before the draft")
    draft = browser.post(f"{base}?id={meeting}", [("token", token_of(page)), ("action", "draft")], follow=False)
    expect(draft.status == 200 and draft.body[:5] == b"%PDF-", f"the draft of the minutes answered HTTP {draft.status}")
    minutes = pdf_bytes_text(draft.body)
    expect("Anlagen" in minutes and "Z\u00e4hlliste der Wahl" in minutes and "Vollmacht" in minutes,
           f"the minutes do not name the attachments: {minutes[-600:]!r}")
    logged = stack.value("SELECT COUNT(*) FROM llx_vereine_log WHERE action = 'meeting_document'")
    expect(logged == "2", f"{logged} documents logged, expected 2")
    return ("count sheet as PDF with question, candidates, columns and signature lines, handed over and not kept; a text file refused, the filled sheet kept "
            "with its checksum at the vote, a vote of another meeting refused; signed proxy at the attendance entry; download with the right, refused without, "
            "reader without upload; the minutes name both attachments")


def mailsending(stack: Stack) -> str:
    """E-mail of the association: its own sender, a failure explained in plain words, failed invitations sent again, a test e-mail."""
    browser = stack.browser()
    base = "/custom/vereine/meetings.php"
    setup = "/custom/vereine/admin/setup.php"
    mail = stack.mailpit()
    port = stack.value("SELECT value FROM llx_const WHERE name = 'MAIN_MAIL_SMTP_PORT'")

    # The mail server is out of reach: nobody is invited, and the page says so in plain words.
    stack.sql("UPDATE llx_const SET value = '1' WHERE name = 'MAIN_MAIL_SMTP_PORT'")
    day = (datetime.date.today() + datetime.timedelta(days=20)).isoformat()
    page_ok(browser.submit(page_ok(browser.get(base), "meetings").form(name="vereinemeeting"),
                           {"kind": "board", "title": "Vorstandssitzung ohne Mailserver", "day": day, "time": "18:00", "format": "physical",
                            "place": "Vereinsheim", "agenda": "Begrüßung\nBudget"}), "a board meeting")
    meeting = stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting")
    page_ok(browser.submit(page_ok(browser.get(f"{base}?id={meeting}"), "the meeting").form(name="vereinemeetinginvite"), {"checked": "1"}),
            "invite while the mail server is out of reach")
    stack.sql(f"UPDATE llx_const SET value = '{port}' WHERE name = 'MAIN_MAIL_SMTP_PORT'")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the meeting after the failed invitation")
    failed = re.search(r'data-invitations-failed="(\d+)"', page.text)
    causes = re.findall(r'data-mail-error="([a-z]+)"', page.text)
    shown = html.unescape(page.text)
    expect(failed is not None and int(failed.group(1)) >= 1 and causes and set(causes) == {"connect"},
           f"the failed invitations are not reported in plain words: failed {failed.group(1) if failed else None}, causes {causes}")
    letters = stack.value(f"SELECT COUNT(*) FROM llx_vereine_meeting_invitation WHERE fk_meeting = {meeting} AND channel <> 'email'")
    headline = "Noch niemand ist eingeladen" if letters == "0" else "Einladungen sind nicht angekommen"
    expect(headline in shown and "nicht erreichbar" in shown, f"the page does not say who was not reached and why (letters: {letters})")
    expect("\\r\\n" not in shown, "the answer of the mail server is shown with escaped line breaks")

    # The association gets its own sender; the failed invitations go out again with it.
    refused = page_ok(browser.submit(page_ok(browser.get(setup), "setup").form(name="vereinemail"), {"VEREINE_MAIL_FROM": "kein-absender"}),
                      "a sender that is no address")
    expect("keine gültige E-Mail-Adresse" in html.unescape(refused.text) and not stack.const("VEREINE_MAIL_FROM"), "a sender that is no address was stored")
    page_ok(browser.submit(page_ok(browser.get(setup), "setup").form(name="vereinemail"), {"VEREINE_MAIL_FROM": "office@runtime-verein.test"}),
            "the sender of the association")
    expect(stack.const("VEREINE_MAIL_FROM") == "office@runtime-verein.test", "the sender of the association was not stored")
    mail.clear()
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the meeting before sending again")
    page_ok(browser.submit(page.form(name="vereinemeetingresend")), "send the failed invitations again")
    proof = stack.sql(f"SELECT sent_at IS NOT NULL, attempts, error IS NULL FROM llx_vereine_meeting_invitation WHERE fk_meeting = {meeting} AND channel = 'email'")
    messages = mail.messages()
    senders = {message.get("From", {}).get("Address") for message in messages}
    expect(proof and all(row == ["1", "2", "1"] for row in proof) and len(messages) == len(proof) and senders == {"office@runtime-verein.test"},
           f"after sending again: proof {proof}, {len(messages)} e-mails from {senders}")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the meeting after sending again")
    expect('data-invitations-failed=' not in page.text and 'data-attempts="2"' in page.text, "the meeting still reports failed invitations")

    # A test e-mail tells at once whether sending works.
    stack.sql("UPDATE llx_user SET email = 'admin@runtime-verein.test' WHERE login = 'admin'")
    mail.clear()
    page_ok(browser.submit(page_ok(browser.get(setup), "setup").form(name="vereinetestmail")), "send the test e-mail")
    test = mail.messages()
    expect(len(test) == 1 and test[0].get("From", {}).get("Address") == "office@runtime-verein.test"
           and any(to.get("Address") == "admin@runtime-verein.test" for to in test[0].get("To", [])),
           f"the test e-mail: {[(m.get('From'), m.get('To')) for m in test]}")
    return ("mail server out of reach: nobody invited, said in plain words without escaped line breaks; a sender that is no address refused, "
            "the own sender stored; failed invitations sent again with it, proof counts two attempts; test e-mail from the own sender")
def itemkinds(stack: Stack) -> str:
    """Kinds of agenda items: most items are reports and discussions, votes only on decisions, the invitation read before it goes out."""
    browser = stack.browser()
    base = "/custom/vereine/meetings.php"
    day = (datetime.date.today() + datetime.timedelta(days=30)).isoformat()
    agenda = "Begrüßung\nBericht des Obmanns\nPlanung des Herbstes\nBudget 2027"
    page_ok(browser.submit(page_ok(browser.get(base), "meetings").form(name="vereinemeeting"),
                           {"kind": "board", "title": "Vorstandssitzung mit Arten", "day": day, "time": "18:00", "format": "physical",
                            "place": "Vereinsheim", "agenda": agenda}), "a board meeting with four items")
    meeting = stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the planned meeting")
    kinds = re.findall(r'data-note="(\d+)" data-stored="\d" data-item-kind="([a-z]+)"', page.text)
    expect(kinds == [("1", "discussion"), ("2", "report"), ("3", "discussion"), ("4", "decision")], f"kinds suggested from the titles: {kinds}")
    expect('data-invitation-preview="1"' in page.text and "Budget 2027" in html.unescape(page.text.split('data-invitation-preview="1"')[1][:4000]),
           "the invitation cannot be read with its agenda before it goes out")

    # The planning is a report, not a discussion: the kind is changed and kept.
    page_ok(browser.submit(page.form(name="vereinemeetingnotes"), {"kind[3]": "report"}), "the third item is a report")
    stored = stack.sql(f"SELECT item, kind FROM llx_vereine_meeting_note WHERE fk_meeting = {meeting} ORDER BY item")
    expect(["3", "report"] in stored, f"stored kinds: {stored}")

    # After inviting, the vote form offers only the decision.
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the meeting before inviting")
    page_ok(browser.submit(page.form(name="vereinemeetinginvite"), {"checked": "1"}), "invite the board")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the invited meeting")
    offered = re.findall(r'<option value="(\d+)" data-vote-item-kind="([a-z]+)"', page.text)
    expect(offered == [("4", "decision")], f"the vote form offers {offered}, expected only the budget")
    shown = html.unescape(page.text)
    expect("Als Beschluss oder Wahl geplant, aber noch nicht abgestimmt" in shown and "4. Budget 2027" in shown,
           "the minutes do not point out the decision that was not voted on")
    expect("Keine Abstimmung." not in shown.split('data-note="2"')[1].split("</tr>")[0], "a report says there was no vote")
    return ("kinds suggested from the titles (discussion, report, decision), changed and kept; the invitation readable with its agenda before it "
            "goes out; the vote form offers only the decision; the minutes point out a decision without a vote and a report does not say 'no vote'")


def agreements(stack: Stack) -> str:
    """Who does what until when: agreements at an agenda item, as to-dos of Dolibarr, in the minutes, suggested again, taken over by a vote; the PDFs carry the logo."""
    browser = stack.browser()
    base = "/custom/vereine/meetings.php"
    meeting = stack.value("SELECT rowid FROM llx_vereine_meeting WHERE title = 'Vorstandssitzung mit Arten'")
    expect(meeting is not None, "the item kinds scenario should leave its board meeting")
    people = [row[0] for row in stack.sql("SELECT rowid FROM llx_adherent WHERE statut = 1 ORDER BY rowid LIMIT 2")]
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the meeting before the agreement")
    refused = page_ok(browser.submit(page.form(name="vereinemeetingagreement"), {"agreement_item": "3", "agreement_label": "Herbstplan schreiben"}),
                      "an agreement without anybody")
    expect("mindestens eine Person" in html.unescape(refused.text), "an agreement without anybody was stored")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the meeting again")
    fields = [("token", token_of(page)), ("action", "addagreement"), ("agreement_item", "3"), ("agreement_label", "Herbstplan schreiben"),
              ("agreement_deadline", "2026-10-31")] + [("agreement_members[]", person) for person in people]
    page_ok(browser.post(f"{base}?id={meeting}", fields), "two people agree to write the plan")
    tasks = stack.sql(f"SELECT fk_resolution, item, fk_adherent, deadline, fk_actioncomm IS NOT NULL FROM llx_vereine_resolution_task WHERE fk_meeting = {meeting} ORDER BY rowid")
    expect(tasks == [["0", "3", people[0], "2026-10-31", "1"], ["0", "3", people[1], "2026-10-31", "1"]], f"the stored agreement: {tasks}")
    events = stack.sql(f"SELECT elementtype, fk_element, percent FROM llx_actioncomm WHERE id IN (SELECT fk_actioncomm FROM llx_vereine_resolution_task WHERE fk_meeting = {meeting}) ORDER BY fk_element")
    expect(sorted(row[1] for row in events) == sorted(people) and all(row[0] == "member" and row[2] == "0" for row in events),
           f"the to-dos of Dolibarr: {events}")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the meeting with its agreement")
    expect(len(re.findall(r'data-agreement="\d+" data-done="0"', page.text)) == 2, "the agreement is not shown at its item")

    # The minutes name it, and the next meeting suggests it while it is open.
    draft = browser.post(f"{base}?id={meeting}", [("token", token_of(page)), ("action", "draft")], follow=False)
    text = pdf_bytes_text(draft.body)
    expect(draft.body[:5] == b"%PDF-" and "Vereinbart:" in text and "Herbstplan schreiben" in text, f"the minutes do not name the agreement: {text[:300]!r}")
    planning = page_ok(browser.get(f"{base}?template=board"), "a new board meeting")
    expect(len(re.findall(r'data-follow="\d+"', planning.text)) >= 2, "the next meeting does not suggest the open agreement")

    # A vote on an item takes its agreement over as a follow-up of the resolution.
    fields = [("token", token_of(page)), ("action", "addagreement"), ("agreement_item", "4"), ("agreement_label", "Budget an Kassierin schicken"),
              ("agreement_members[]", people[0])]
    page_ok(browser.post(f"{base}?id={meeting}", fields), "an agreement on the budget")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the meeting before the vote")
    present = [("token", token_of(page)), ("action", "saveattendance")] + [(f"attendance[{m}][state]", "present") for m in re.findall(r'data-attendance="(\d+)"', page.text)]
    page_ok(browser.post(f"{base}?id={meeting}", present), "the whole board is present")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the meeting with everybody present")
    page_ok(browser.submit(page.form(name="vereinevote"), {"item": "4", "kind": "resolution", "title": "Budget 2027", "yes": "2", "no": "0"}), "vote on the budget")
    taken = stack.value(f"SELECT fk_resolution FROM llx_vereine_resolution_task WHERE fk_meeting = {meeting} AND item = 4")
    resolution = stack.value(f"SELECT rowid FROM llx_vereine_resolution WHERE fk_meeting = {meeting} AND item = 4")
    expect(taken is not None and taken == resolution and resolution not in (None, "0"), f"the agreement was not taken over by the resolution: {taken} / {resolution}")

    # The logo of the association heads every PDF, with page numbers in the foot.
    png = "iVBORw0KGgoAAAANSUhEUgAAAAgAAAAECAIAAAA8r+mnAAAAEUlEQVR4nGM4o6SEFTFQTwIAuf4iAWrHAc4AAAAASUVORK5CYII="
    stack.shell("mkdir -p /var/www/documents/mycompany/logos && echo '" + png + "' | base64 -d > /var/www/documents/mycompany/logos/verein.png")
    stack.sql("DELETE FROM llx_const WHERE name = 'MAIN_INFO_SOCIETE_LOGO'")
    stack.sql("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('MAIN_INFO_SOCIETE_LOGO', 1, 'verein.png', 'chaine', 0)")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the meeting with a logo")
    draft = browser.post(f"{base}?id={meeting}", [("token", token_of(page)), ("action", "draft")], follow=False)
    expect(b"/Subtype /Image" in draft.body, "the minutes carry no image although the association has a logo")
    expect("Seite 1 von" in pdf_bytes_text(draft.body), "the minutes have no page numbers")
    steps = dict(re.findall(r'data-step="([a-z]+)" data-state="([a-z]+)"', page_ok(browser.get(f"{base}?id={meeting}"), "the meeting with its steps").text))
    expect(list(steps) == ["plan", "invite", "meet", "minutes", "close"] and steps["plan"] == "done" and list(steps.values()).count("now") <= 1,
           f"the steps of the meeting: {steps}")

    # The home page tells the member what waits: here the tasks of the agreement.
    stack.sql(f"UPDATE llx_user SET fk_member = {people[0]} WHERE login = 'admin'")
    home = page_ok(browser.get("/index.php?mainmenu=home"), "the home page")
    waiting = re.findall(r'data-box-waiting="([a-z]+)"', home.text)
    expect("tasks" in waiting and "Herbstplan schreiben" in html.unescape(home.text), f"the home page box shows {waiting}")
    return ("agreement without anybody refused; two people agreed with a deadline, each with a to-do of Dolibarr at the member; shown at the item, "
            "named in the minutes, suggested for the next meeting; a vote on the item takes its agreement over; the logo heads the PDF, pages numbered")


def apidocs(stack: Stack) -> str:
    """The API tab lists every endpoint of docs/openapi.json with its rights and the users with an API key, never the key."""
    page = page_ok(stack.browser().get("/custom/vereine/admin/api.php"), "API setup")
    described = json.loads(OPENAPI.read_text(encoding="utf-8"))
    operations = {f"{method.upper()} {path}" for path, item in described["paths"].items() for method in item}
    listed = {html.unescape(value) for value in re.findall(r'data-endpoint="([^"]+)" data-rights="[1-9]', page.text)}
    expect(listed == operations, f"API tab lists {sorted(listed)}, docs/openapi.json describes {sorted(operations)}")
    reader = re.search(r'data-api-user="rtreader" data-admin="0" data-endpoints="(\d+)"', page.text)
    expect(reader is not None and int(reader.group(1)) > 0, "the reader with an API key is not listed with the endpoints it can call")
    expect(stack.reader_key not in page.text and stack.nobody_key not in page.text, "the API tab shows an API key")
    expect(denied(stack.browser("rtreader").get("/custom/vereine/admin/api.php")), "a non-administrator opens the API tab")
    return f"{len(operations)} endpoints with rights listed; reader with key and {reader.group(1)} callable endpoints; no key shown; non-administrator refused"


def openapi(stack: Stack) -> str:
    """Every documented endpoint answered 200 somewhere in the run, and every answer of the module matched docs/openapi.json."""
    missing = sorted(f"{method} {path}" for method, path, status in stack.openapi.operations()
                     if status == "200" and (method, path, status) not in stack.openapi.checked)
    expect(not missing, f"no runtime check compared a successful answer with docs/openapi.json for: {missing}")
    endpoints = len({(method, path) for method, path, _ in stack.openapi.operations()})
    return f"all {endpoints} endpoints answered 200; {len(stack.openapi.checked)} kinds of answers matched docs/openapi.json"


def action_link_for(page: Page, action: str, row_id: str | None) -> str:
    """The link a setup list offers for an action on one row."""
    for href in re.findall(r'href="([^"]*[?&](?:amp;)?action=' + re.escape(action) + r'(?:&[^"]*)?)"', page.text):
        target = html.unescape(href)
        if re.search(r"[?&]id=" + re.escape(str(row_id)) + r"(&|#|$)", target):
            return target
    raise CheckFailed(f"{page.url} offers no link for action={action} on id {row_id}")


def disable(stack: Stack) -> str:
    """Disabling hides pages and API but keeps data and granted rights for the next activation."""
    browser = stack.browser()
    backslash_n = "CONCAT('Zeile eins', CHAR(92), 'nZeile zwei')"
    stack.sql("UPDATE llx_const SET value = CONCAT('Gilmstraße 2', CHAR(92), 'n6020 Innsbruck') WHERE name = 'VEREINE_AUTHORITY_ADDRESS'")
    stack.sql(f"UPDATE llx_const SET value = JSON_SET(value, '$.asset_purpose', {backslash_n}) WHERE name = 'VEREINE_STATUTE_TEXT'")
    stack.sql(f"UPDATE llx_vereine_consent_text SET text = {backslash_n} ORDER BY rowid LIMIT 1")
    broken = stack.value("SELECT (SELECT COUNT(*) FROM llx_const WHERE name IN ('VEREINE_AUTHORITY_ADDRESS', 'VEREINE_STATUTE_TEXT') AND LOCATE(CONCAT(CHAR(92), 'n'), value) > 0)"
                         " + (SELECT COUNT(*) FROM llx_vereine_consent_text WHERE LOCATE(CHAR(92), text) > 0)")
    expect(broken == "3", f"{broken} broken values prepared for the repair, expected 3")
    page_ok(browser.get(module_link(module_list(browser), "reset")), "disable")
    expect(stack.const("MAIN_MODULE_VEREINE") is None, "MAIN_MODULE_VEREINE is still set after disabling")
    expect(stack.value("SELECT COUNT(*) FROM llx_menu WHERE module = 'vereine'") == "0", "menu entries survived disabling")
    expect(denied(browser.get("/custom/vereine/vereineindex.php")), "the overview opens while the module is disabled")
    # Dolibarr answers for a disabled module before the module's code runs; the description does not cover that.
    status, _ = stack.api("vereine/organization", stack.reader_key, check=False)
    expect(status != 200, "the API answers while the module is disabled")
    stack.notes["disabled_api_status"] = status

    page_ok(browser.get(module_link(module_list(browser), "set")), "enable again")
    expect(stack.const("VEREINE_REGISTER_NUMBER") == "123456789", "the association data was lost by disabling")
    expect(stack.const("VEREINE_COUNTRY_PROFILE") is None, "re-enabling brought the country profile back")
    repaired = stack.sql("SELECT LOCATE(CHAR(92), value) = 0 AND value = CONCAT('Gilmstraße 2', CHAR(10), '6020 Innsbruck') FROM llx_const WHERE name = 'VEREINE_AUTHORITY_ADDRESS'"
                         " UNION ALL SELECT JSON_VALUE(value, '$.asset_purpose') = CONCAT('Zeile eins', CHAR(10), 'Zeile zwei') FROM llx_const WHERE name = 'VEREINE_STATUTE_TEXT'"
                         " UNION ALL SELECT COUNT(*) = 0 FROM llx_vereine_consent_text WHERE LOCATE(CHAR(92), text) > 0")
    expect(repaired == [["1"], ["1"], ["1"]], f"line breaks stored as \\n not repaired on activation: {repaired}")
    reader = stack.browser("rtreader")
    page_ok(reader.get("/custom/vereine/vereineindex.php"), "overview for the reader after enabling again")
    status, _ = stack.api("vereine/status", stack.reader_key)
    expect(status == 200, f"the API answers HTTP {status} after enabling again")
    return (f"off: pages refused, API HTTP {stack.notes['disabled_api_status']}; on again: data and the reader's right kept, no country profile; "
            "line breaks stored as \\n repaired in authority address, statute text and consent text")


def php_messages(stack: Stack) -> set:
    """PHP errors, warnings, notices and deprecations raised in module code."""
    found = set()
    log = stack.log()
    for match in PHP_PROBLEM.finditer(log):
        found.add(f"{match.group(3).replace(MODULE_DIR + '/', '')}: {match.group(1)}: {match.group(2)}")
    for line in log.splitlines():
        match = PHP_THROWN.search(line)
        frame = MODULE_FRAME.search(match.group(4)) if match and not match.group(3).startswith(MODULE_DIR) else None
        if frame:
            found.add(f"{frame.group(1)}: {match.group(1)}: {match.group(2)} (thrown in {match.group(3)})")
    return found


SCENARIOS = (
    ("upgrade", "An installation of the previous release upgrades to this package", upgrade, ()),
    ("deploy", "The package deploys through Deploy an external module", deploy, ("upgrade",)),
    ("enable", "Enabling registers rights and menu and removes the old country profile", enable, ("deploy",)),
    ("pages", "Overview, setup, about and the menu entry render", pages, ("enable",)),
    ("setup", "Setup validates, normalises and stores the association", setup, ("pages",)),
    ("access", "Rights decide who sees overview and setup", access, ("setup",)),
    ("api", "REST API answers with the right and refuses without", api, ("setup",)),
    ("partners", "Members and third parties are linked and reconciled", partners, ("access", "api")),
    ("membercard", "Dolibarr's own member card creates and links third parties the module follows", membercard, ("partners",)),
    ("taxprofiles", "Tax profiles: suggestions, legal checks, own profiles and the API", taxprofiles, ("api",)),
    ("taxassign", "Tax profiles on products and invoice lines, and a warning for differing VAT", taxassign, ("taxprofiles",)),
    ("invoicepdf", "The invoice PDF shows tax profile notes and the ZVR number", invoicepdf, ("taxassign",)),
    ("thresholds", "Thresholds of a calendar year as traffic light on overview, home page and API", thresholds, ("invoicepdf",)),
    ("cashregister", "Cash register duty per sphere and the missing 13 % VAT rate", cashregister, ("thresholds",)),
    ("website", "Member summaries for a website: fee status, open invoices, lookup and rights", website, ("cashregister",)),
    ("websiteinvoices", "A member's invoices and PDFs for a website", websiteinvoices, ("website",)),
    ("websitesync", "A website sync gets all members and then only the changed ones", websitesync, ("websiteinvoices",)),
    ("websiteevents", "Webhooks tell a website which member changed, without personal data", websiteevents, ("websitesync",)),
    ("fees", "Fee model on the member type, the fee setup page and the membership fees API", fees, ("websiteevents",)),
    ("feerun", "Fee run: preview, subscription period and linked invoice once, nothing on a second run", feerun, ("fees",)),
    ("discounts", "Discounts by age, with proof and exemptions in the fee run and the website summary", discounts, ("feerun",)),
    ("families", "Families with one payer: shared invoice, discount per further member and cap per fee year", families, ("discounts",)),
    ("exits", "Exits with notice period: planned, carried out on the last day, fee run and API follow", exits, ("families",)),
    ("sepa", "SEPA direct debit from the fee run: mandate check, one request, pre-notification", sepa, ("exits",)),
    ("applications", "Consent texts with versions and membership applications through the API", applications, ("sepa",)),
    ("functions", "Function catalogue, terms of office and what does not fit on a day", functions, ("applications",)),
    ("authority", "Report of new representatives to the association authority: deadline, agenda, letter, noted as reported", authority, ("functions",)),
    ("board", "Board for a website: names with consent or disclosure, functions in the summary", board, ("authority",)),
    ("groups", "User groups through functions, changed only after an administrator confirms", groups, ("board",)),
    ("mailing", "E-mail campaign recipients by function, consent and guardians of minors", mailing, ("groups",)),
    ("statutes", "Rules of the statutes: checked, stored, election due and minimum age for applications", statutes, ("mailing",)),
    ("letters", "Letters to the association authority: responsible authority, notices with deadline, filed", letters, ("statutes",)),
    ("statutetext", "Statutes as text: fields, check, preview, uploaded and generated versions", statutetext, ("letters",)),
    ("statutechange", "Change of the statutes: comparison, PDF, new version with notice to the authority", statutechange, ("statutetext",)),
    ("signatures", "Signatures: who signs, in Dolibarr with the password, on paper as a scan, a changed document", signatures, ("letters", "functions")),
    ("meetings", "Meetings: exactly the board or every member invited by e-mail or letter, with deadline and proof", meetings, ("statutechange",)),
    ("attendance", "Attendance: proxies as the statutes allow, never on the board, quorum at any time", attendance, ("meetings",)),
    ("votes", "Votes and elections: quorum, majorities of the statutes, election starts the term, change of statutes stores the version", votes, ("attendance",)),
    ("minutestexts", "Agenda templates and texts per item: required items, new meeting from a template, real numbers in the texts", minutestexts, ("votes",)),
    ("minutes", "Minutes: roles, draft PDF, final version with signatures, sent to the board", minutes, ("minutestexts", "signatures")),
    ("resolutions", "The register of resolutions: search, wording and validity, follow-ups as to-dos of Dolibarr, agenda suggestion", resolutions, ("minutes",)),
    ("resolutiondocs", "Every resolution as its own PDF, with its signature run and an excerpt of several", resolutiondocs, ("resolutions", "signatures")),
    ("circulars", "Circular resolutions of the board: only when the statutes allow, votes in Dolibarr, result in the register", circulars, ("resolutiondocs",)),
    ("meetingdocs", "Documents of a meeting: count sheet, proof of a vote, signed proxy, attachments in the minutes", meetingdocs, ("circulars",)),
    ("mailsending", "E-mail of the association: own sender, failures in plain words, sent again, test e-mail", mailsending, ("meetingdocs",)),
    ("itemkinds", "Kinds of agenda items: reports and discussions without a vote, the invitation read before it goes out", itemkinds, ("mailsending",)),
    ("agreements", "Who does what until when: agreements per agenda item, to-dos of Dolibarr, taken over by a vote; the logo on the PDFs", agreements, ("itemkinds",)),
    ("qes", "ID Austria: signature service in the setup, two people sign one PDF, cancel, a way back used twice, a changed PDF", qes, ("agreements",)),
    ("apidocs", "API tab: every endpoint with its rights, users with an API key, never the key", apidocs, ("qes",)),
    ("openapi", "Every endpoint answered and every answer matched docs/openapi.json", openapi, ("apidocs",)),
    ("disable", "Disabling keeps data and rights for the next activation", disable, ("partners",)),
)


def wait_http(url: str, seconds: int) -> None:
    deadline = time.time() + seconds
    last = ""
    while time.time() < deadline:
        try:
            with urllib.request.urlopen(url, timeout=5) as response:
                if response.status == 200:
                    return
        except urllib.error.HTTPError as error:
            last = f"HTTP {error.code}"
        except OSError as error:
            last = str(error)
        time.sleep(2)
    raise CheckFailed(f"{url} did not answer within {seconds} s ({last})")


def parse_fixtures(output: str) -> dict:
    start = output.find("{")
    if start < 0:
        raise CheckFailed(f"fixtures.php printed no JSON:\n{output[-1500:]}")
    return json.loads(output[start:output.rfind("}") + 1])
