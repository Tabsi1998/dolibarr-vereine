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
import io
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

    def today(self) -> str:
        """Today as the module sees it: PHP in Europe/Vienna. The database runs in UTC, a day apart after midnight (#181)."""
        return self.shell("php -d date.timezone=Europe/Vienna -r 'echo date(\"Y-m-d\");'").stdout.strip()

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


def enable_dolibarr_module(stack: Stack, name: str) -> None:
    """Switch on a module Dolibarr brings itself, the way an administrator does it in the module list."""
    if stack.const("MAIN_MODULE_" + name.replace("mod", "").upper()) == "1":
        return
    browser = stack.browser()
    page = page_ok(browser.get("/admin/modules.php?mode=common"), f"module list for {name}")
    for href in re.findall(r'href="([^"]*modules\.php\?[^"]*)"', page.text):
        target = html.unescape(href)
        if "action=set&" in target + "&" and f"value={name}&" in target + "&":
            page_ok(browser.get(target), f"switch {name} on")
            return
    raise CheckFailed(f"the module list offers no way to switch {name} on")


def vereine_day(day: str) -> str:
    """A day the way the module prints it, so a page can be searched for it."""
    return datetime.date.fromisoformat(day).strftime("%d.%m.%Y")


# A cursor of the change feed is hex and nothing else (#154).
VEREINE_HEX = re.compile(r"^[0-9a-f]+$")


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


# A 1x1 pixel PNG, as a website sends a signature drawn on the screen.
TINY_PNG = ("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")


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
    # A package above PHP's upload_max_filesize never reaches Dolibarr, which then keeps what is installed.
    descriptor = stack.shell(f"sed -n \"s/.*version = '\\([^']*\\)'.*/\\1/p\" {MODULE_DIR}/core/modules/modVereine.class.php | head -1")
    expect(descriptor.stdout.strip() == package_version(package),
           f"{package.name} ({package.stat().st_size} bytes) was not deployed, {descriptor.stdout.strip()!r} is still installed; "
           "check PHP upload_max_filesize and post_max_size")
    files = [line[2:] for line in installed.stdout.splitlines() if line.startswith("./")]
    with zipfile.ZipFile(package) as bundle:
        packaged = sorted(info.filename[len("vereine/"):] for info in bundle.infolist() if not info.is_dir())
    expect(files == packaged, f"deployed files differ from {package.name}: "
                              f"missing {sorted(set(packaged) - set(files))[:5]}, extra {sorted(set(files) - set(packaged))[:5]}")
    return files


# Events other than the ones the module writes itself: history entries (#112) and its webhook trigger.
NOT_LOG_EVENT = "COALESCE(code, '') NOT LIKE 'AC_VEREINE%'"


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
    # PHP keeps the compiled module descriptor for a moment after the files were replaced (opcache),
    # so the about page may still name the version of the release it upgraded from.
    for attempt in range(10):
        about = page_ok(stack.browser().get("/custom/vereine/admin/about.php"), "about after the upgrade")
        if stack.module_version in about.text:
            break
        time.sleep(1)
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
                      ["49210004", "application", "write"], ["49210005", "sync", "read"],
                      ["49210006", "identity", "use"], ["49210007", "donation", "write"]], f"rights after enabling: {rights}")
    menu = sorted(stack.sql("SELECT mainmenu, leftmenu, url FROM llx_menu WHERE module = 'vereine' AND entity = 1"))
    expect(menu == [["members", "vereine", "/vereine/vereineindex.php"], ["members", "vereine_account", "/vereine/account.php"],
                    ["members", "vereine_application", "/vereine/application.php"],
                    ["members", "vereine_applications", "/vereine/applications.php"],
                    ["members", "vereine_archive", "/vereine/archive.php"],
                    ["members", "vereine_assembly", "/vereine/assembly.php"],
                    ["members", "vereine_audit", "/vereine/audit.php"],
                    ["members", "vereine_authority", "/vereine/authority.php"],
                    ["members", "vereine_circulars", "/vereine/circulars.php"],
                    ["members", "vereine_consentform", "/vereine/consents.php"],
                    ["members", "vereine_donations", "/vereine/donations.php"],
                    ["members", "vereine_duties", "/vereine/duties.php"],
                    ["members", "vereine_events", "/vereine/events.php"],
                    ["members", "vereine_feerun", "/vereine/fees_run.php"], ["members", "vereine_functions", "/vereine/functions.php"],
                    ["members", "vereine_honours", "/vereine/honours.php"],
                    ["members", "vereine_inventory", "/vereine/inventory.php"],
                    ["members", "vereine_meetings", "/vereine/meetings.php"],
                    ["members", "vereine_overpayments", "/vereine/overpayments.php"],
                    ["members", "vereine_partners", "/vereine/partners.php"], ["members", "vereine_partnersetup", "/vereine/admin/partners.php"],
                    ["members", "vereine_resolutions", "/vereine/resolutions.php"],
                    ["members", "vereine_statistics", "/vereine/statistics.php"],
                    ["members", "vereine_volunteers", "/vereine/volunteer.php"]],
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
            "4 rights, 14 menu entries, log table, 3 categories")


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
    expect(body == {"module_version": stack.module_version, "api_version": 2, "website_profile_consent": ""}, f"GET vereine/status returned {body}")
    drift = abs((datetime.datetime.now(datetime.timezone.utc)
                 - datetime.datetime.strptime(server_time, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=datetime.timezone.utc)).total_seconds())
    expect(drift < 300, f"server_time {server_time} is {drift:.0f} s away from this computer's clock")
    status, body = stack.api("vereine/organization", stack.reader_key)
    expect(status == 200 and isinstance(body, dict), f"GET vereine/organization answered HTTP {status}: {body}")
    expected = {
        "name": "Runtime Verein", "authority": "Landespolizeidirektion Tirol",
        "register": {"kind": "ZVR", "number": "123456789"}, "founded": "2019-03-01",
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
    # A supplier's line takes no sales profile (#53), but the area of the expense from the product's profile.
    supplier = stack.sql(f"SELECT d.description, e.vereine_taxprofile, e.vereine_expense_sphere FROM llx_facture_fourn_det as d LEFT JOIN "
                         f"llx_facture_fourn_det_extrafields as e ON e.fk_object = d.rowid WHERE d.fk_facture_fourn = {int(data['supplier_invoice'])} ORDER BY d.rowid")
    expect(supplier == [["Getränke Einkauf", "NULL", "harmful"], ["Trikots Einkauf", "NULL", "harmful"]], f"supplier invoice lines: {supplier}")
    hidden = stack.value("SELECT list FROM llx_extrafields WHERE name = 'vereine_taxprofile' AND elementtype = 'facture_fourn_det'")
    expect(hidden == "0", f"the sales profile is still shown on supplier lines: list = {hidden}")

    browser = stack.browser()
    card = page_ok(browser.get(f"/compta/facture/card.php?id={int(data['invoice'])}"), "customer invoice")
    expect('data-taxprofile-warning="1"' in card.text, "the invoice does not report the one line whose VAT differs from its profile")
    text = html.unescape(card.text)
    expect("Getränk Kantine" in text and "Umsatzsteuer passt nicht zum Steuerprofil" in text,
           "the warning does not name the canteen drink in German")
    supplier_card = page_ok(browser.get(f"/fourn/facture/card.php?facid={int(data['supplier_invoice'])}"), "supplier invoice")
    expect("data-taxprofile-warning" not in supplier_card.text, "shirts bought at 20 % and sold at 0 % are reported on the supplier's invoice")

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
            "product's profile; one differing line reported on the invoice; supplier lines get the area of the expense, no sales profile "
            "and no warning; product card offers active profiles only")


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
    expect(paid["fee"] == {"required": True, "status": "paid", "next_due": day_after(dates["paid_until"]), "amount": 50, "discount": {"kind": "none", "label": ""},
                           "payer": "self", "payment_url": "", "mandate": {"status": "off", "signed_on": ""}},
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
           and unpaid["fee"] == {"required": True, "status": "due", "next_due": dates["today"], "amount": 50, "discount": {"kind": "none", "label": ""},
                                 "payer": "self", "payment_url": "", "mandate": {"status": "off", "signed_on": ""}},
           f"member who never paid: {unpaid}")
    free = answers["free"]
    expect(free["type"]["label"] == "Ordentliches Mitglied"
           and free["fee"] == {"required": False, "status": "not_required", "next_due": "", "amount": None, "discount": {"kind": "none", "label": ""},
                               "payer": "self", "payment_url": "", "mandate": {"status": "off", "signed_on": ""}},
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


def websiteprofile(stack: Stack) -> str:
    """The board keeps a website profile per member; the API hands it and the photo out only with the chosen consent."""
    notes = stack.notes["website"]
    key, members = notes["key"], notes["members"]
    paid, free = int(members["paid"]), int(members["free"])
    browser = stack.browser()

    # A consent text for the website profile, chosen in the setup of consents.
    setup = page_ok(browser.get("/custom/vereine/admin/consents.php"), "consent setup")
    page_ok(browser.submit(setup.form(name="vereineconsenttext"), {"code": "profil", "label": "Website-Profil", "text": "Der Verein darf mich auf der Website zeigen."}),
            "create the consent text for the website profile")
    setup = page_ok(browser.get("/custom/vereine/admin/consents.php"), "consent setup with the text")
    expect('data-website-profile-consent=""' in setup.text, "the consent setup does not offer the choice for the website profile")
    page_ok(browser.submit(setup.form(name="vereinewebsiteprofileconsent"), {"website_profile_consent": "profil"}), "choose the consent")
    expect(stack.const("VEREINE_WEBSITE_PROFILE_CONSENT") == "profil", "the consent for the website profile was not stored")
    status, body = stack.api("vereine/status", key)
    expect(status == 200 and body.get("website_profile_consent") == "profil", f"status does not name the consent: {body}")

    # Without the consent the API names it and carries nothing personal.
    status, body = stack.api(f"vereine/members/{paid}/profile", key)
    expect(status == 200 and body == {"consent": "profil", "given": False}, f"profile without consent: {body}")
    status, _ = stack.api(f"vereine/members/{paid}/photo", key, check=False)
    expect(status == 404, f"photo without consent answered HTTP {status}")

    # The board records the consent on the member card and fills the profile.
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={paid}"), "tab Association")
    expect('data-website-profile-consent="missing"' in tab.text and 'data-website-profile-photo="0"' in tab.text,
           "the tab Association does not show the missing consent and the missing photo")
    page_ok(browser.post(f"/custom/vereine/member_association.php?id={paid}", [("token", token_of(tab)), ("action", "recordconsent"),
                                                                                  ("consent_code", "profil"), ("consent_source", "paper")]), "record the consent")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={paid}"), "tab Association with consent")
    expect('data-website-profile-consent="given"' in tab.text, "the tab Association does not show the given consent")
    page_ok(browser.submit(tab.form(name="vereinewebsiteprofile"), {"profile_gamertag": "  LionKing ", "profile_bio": "Spielt TFT.",
                                                                        "profile_games": "TFT, Rocket League; Rocket League", "profile_platforms": "PC"}), "save the profile")
    expect(stack.value(f"SELECT gamertag FROM llx_vereine_member_profile WHERE fk_adherent = {paid}") == "LionKing", "the profile was not stored")
    status, body = stack.api(f"vereine/members/{paid}/profile", key)
    expect(status == 200 and body == {"consent": "profil", "given": True, "gamertag": "LionKing", "bio": "Spielt TFT.",
                                      "games": ["TFT", "Rocket League"], "platforms": ["PC"], "photo": None}, f"profile with consent: {body}")
    logged = stack.value(f"SELECT COUNT(*) FROM llx_vereine_log WHERE fk_adherent = {paid} AND action = 'website_profile'")
    expect(logged == "1", f"the log does not record the saved profile once: {logged}")
    status, body = stack.api(f"vereine/changes?limit=50", key, check=False)
    expect(status in (200, 403), f"changes answered HTTP {status}")

    # A photo on the member card: the API hands it out with its checksum, and the file matches.
    photo = stack.php_fixture("memberphoto", RT_MEMBER=str(paid))
    status, body = stack.api(f"vereine/members/{paid}/profile", key)
    expect(status == 200 and body["photo"] == {"sha256": photo["sha256"], "size": int(photo["size"]), "content_type": "image/png", "updated_at": body["photo"]["updated_at"]},
           f"profile with photo: {body}")
    status, image = stack.api(f"vereine/members/{paid}/photo", key)
    expect(status == 200 and image["sha256"] == photo["sha256"] and image["content_type"] == "image/png" and image["filename"] == "rt-photo.png"
           and hashlib.sha256(base64.b64decode(image["content"])).hexdigest() == photo["sha256"] and image["filesize"] == int(photo["size"]),
           f"photo: {json.dumps({k: v for k, v in image.items() if k != 'content'}) if isinstance(image, dict) else image}")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={paid}"), "tab Association with photo")
    expect('data-website-profile-photo="1"' in tab.text, "the tab Association does not show the photo")

    # Another member without the consent, a reader without the website right, nobody.
    status, body = stack.api(f"vereine/members/{free}/profile", key)
    expect(status == 200 and body == {"consent": "profil", "given": False}, f"profile of a member without consent: {body}")
    status, _ = stack.api(f"vereine/members/{paid}/profile", stack.reader_key, check=False)
    expect(status == 403, f"a reader without the website right got the profile: HTTP {status}")
    status, _ = stack.api(f"vereine/members/{paid}/photo", stack.nobody_key, check=False)
    expect(status == 403, f"a user without rights got the photo: HTTP {status}")
    status, _ = stack.api("vereine/members/999999/profile", key, check=False)
    expect(status == 404, f"an unknown member answered HTTP {status}")

    # Withdrawn: gone again, the profile stays kept for the day the consent comes back.
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={paid}"), "tab Association before the withdrawal")
    page_ok(browser.post(f"/custom/vereine/member_association.php?id={paid}", [("token", token_of(tab)), ("action", "withdrawconsent"),
                                                                                  ("consent_code", "profil"), ("consent_source", "paper")]), "withdraw the consent")
    status, body = stack.api(f"vereine/members/{paid}/profile", key)
    expect(status == 200 and body == {"consent": "profil", "given": False}, f"profile after the withdrawal: {body}")
    status, _ = stack.api(f"vereine/members/{paid}/photo", key, check=False)
    expect(status == 404, f"photo after the withdrawal answered HTTP {status}")
    expect(stack.value(f"SELECT gamertag FROM llx_vereine_member_profile WHERE fk_adherent = {paid}") == "LionKing", "the withdrawal deleted the profile")

    # Leave the setup of consents as found: later scenarios count the consent texts from zero.
    setup = page_ok(browser.get("/custom/vereine/admin/consents.php"), "consent setup at the end")
    page_ok(browser.submit(setup.form(name="vereinewebsiteprofileconsent"), {"website_profile_consent": ""}), "choose no consent again")
    expect(stack.const("VEREINE_WEBSITE_PROFILE_CONSENT") in ("", None, "NULL"), "the consent for the website profile was not cleared")
    stack.sql("DELETE FROM llx_vereine_consent WHERE code = 'profil'")
    stack.sql("DELETE FROM llx_vereine_consent_text WHERE code = 'profil'")
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_consent_text") == "0", "the consent text for the profile was not removed")
    return ("Website profile on the tab Association, the consent chosen in the setup; the API hands profile and photo out only "
            "with that consent, names it otherwise, and a withdrawal closes both")


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
            "address": "Teststraße 1", "zip": "6020", "town": "Innsbruck", "country_code": "AT", "type_id": type_id,
            "note": "Ich spiele gern Schach.",
            "consents": [{"code": "fotos", "version": 2, "granted_at": "2026-09-22T19:30:00+02:00", "form": "Beitrittsformular",
                          "reference": "web-2026-0042"}]}
    status, _ = stack.api("vereine/applications", key, method="POST", data=body)
    expect(status == 403, f"the website user without the right to send applications got HTTP {status}")
    # A signature drawn on the screen comes with the application; too large is refused (#111).
    signature = base64.b64encode(base64.b64decode(TINY_PNG)).decode("ascii")
    status, refused = stack.api("vereine/applications", form_key, method="POST",
                                data={**body, "external_id": "", "signature": base64.b64encode(b"x" * 300000).decode("ascii")})
    expect(status == 400 and "signature" in json.dumps(refused), f"an oversized signature answered HTTP {status}: {refused}")
    status, created = stack.api("vereine/applications", form_key, method="POST", data={**body, "signature": signature})
    expect(status == 200 and created.get("status") == "draft" and created.get("duplicate") is False and created.get("document") is True,
           f"POST vereine/applications answered HTTP {status}: {created}")
    member_id = int(created["id"])
    member = stack.sql(f"SELECT statut, firstname, lastname, email, fk_adherent_type, DATE(birth), town FROM llx_adherent WHERE rowid = {member_id}")
    expect(member == [["-1", "Amelie", "Antrag", email, str(type_id), "2001-04-30", "Innsbruck"]], f"member from the application: {member}")
    consents = stack.sql(f"SELECT code, version, given, source FROM llx_vereine_consent WHERE fk_adherent = {member_id}")
    expect(consents == [["fotos", "2", "1", "website"]], f"consents from the application: {consents}")
    # How the website says the consent was given, for the proof (Art. 7 (1) GDPR) (#109).
    proof = stack.sql(f"SELECT proof_at, proof_form, proof_ref FROM llx_vereine_consent WHERE fk_adherent = {member_id}")
    expect(proof == [["2026-09-22 19:30:00", "Beitrittsformular", "web-2026-0042"]], f"proof of the consent: {proof}")

    # The application leaves the same PDF as on paper, at the documents of the member (#111).
    documents = stack.shell(f"ls /var/www/documents/adherent/*/mitgliedsantrag-*.pdf 2>/dev/null | wc -l").stdout.strip()
    application_pdf = pdf_text(stack, "adherent")
    expect("Elektronisch eingereicht" in application_pdf and "Antrag" in application_pdf, "the application of the website is no document at the member")
    logged = stack.value(f"SELECT COUNT(*) FROM llx_vereine_log WHERE fk_adherent = {member_id} AND action = 'application_pdf' AND message LIKE '%sha256%'")
    expect(logged == "1", f"{logged} entries about the document of the application, expected 1")

    status, again = stack.api("vereine/applications", form_key, method="POST", data=body)
    count = stack.value(f"SELECT COUNT(*) FROM llx_adherent WHERE email = '{email}'")
    expect(status == 200 and again.get("id") == member_id and again.get("duplicate") is True and count == "1" and again.get("document") is False,
           f"the same application sent again: HTTP {status} {again}, {count} members")
    documents_again = stack.shell(f"ls /var/www/documents/adherent/*/mitgliedsantrag-*.pdf 2>/dev/null | wc -l").stdout.strip()
    expect(documents_again == documents, f"the application sent again left another document: {documents} then {documents_again}")
    for change, message in (({"consents": [{"code": "fotos", "version": 1}]}, "the current version is 2"),
                            ({"email": "amelie at runtime"}, "valid e-mail"), ({"lastname": ""}, "lastname are required")):
        status, answer = stack.api("vereine/applications", form_key, method="POST", data={**body, **change, "external_id": ""})
        expect(status == 400 and message in json.dumps(answer), f"application with {change}: HTTP {status} {answer}")
    count = stack.value(f"SELECT COUNT(*) FROM llx_adherent WHERE email = '{email}' OR lastname = 'Antrag'")
    expect(count == "1", f"refused applications created members: {count}")

    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={member_id}"), "association tab of the applicant")
    expect('data-member-consent="fotos" data-state="given" data-version="2"' in tab.text and 'data-member-consent="newsletter" data-state="none"' in tab.text,
           "the member tab does not show the consents of the application")
    expect('data-consent-proof="online"' in tab.text and "Beitrittsformular" in tab.text, "the tab does not show how the consent was given online")
    page_ok(browser.submit(tab.form(name="vereinewithdrawconsent")), "record the withdrawal of the photo consent")
    events = stack.sql(f"SELECT code, version, given, source FROM llx_vereine_consent WHERE fk_adherent = {member_id} ORDER BY rowid")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={member_id}"), "association tab after the withdrawal")
    expect(events == [["fotos", "2", "1", "website"], ["fotos", "2", "0", "paper"]] and 'data-member-consent="fotos" data-state="withdrawn"' in tab.text,
           f"withdrawal: {events}")
    # The member decides over the API: give, withdraw, and the same order twice (#98).
    status, state = stack.api(f"vereine/members/{member_id}/consents", key)
    by_code = {row["code"]: row for row in state} if status == 200 else {}
    expect(status == 200 and by_code.get("fotos", {}).get("state") == "withdrawn" and by_code["fotos"]["can_give"] is True
           and by_code["fotos"]["can_withdraw"] is False and by_code.get("newsletter", {}).get("state") == "none",
           f"GET vereine/members/id/consents answered HTTP {status}: {state}")
    status, _ = stack.api(f"vereine/members/{member_id}/consents", form_key)
    expect(status == 403, f"reading the consents without the website right answered HTTP {status}")
    decision = {"code": "newsletter", "decision": "given", "version": by_code["newsletter"]["current_version"],
                "granted_at": "2026-09-23T10:15:00+02:00", "form": "Mein Konto", "reference": "web-c-77"}
    status, answer = stack.api(f"vereine/members/{member_id}/consents", form_key, method="POST", data={**decision, "version": 99})
    expect(status == 400 and "current version" in json.dumps(answer), f"a consent with a wrong version answered HTTP {status}: {answer}")
    status, answer = stack.api(f"vereine/members/{member_id}/consents", form_key, method="POST", data=decision)
    expect(status == 200 and answer.get("recorded") is True, f"giving the newsletter consent answered HTTP {status}: {answer}")
    events = stack.value(f"SELECT COUNT(*) FROM llx_vereine_consent WHERE fk_adherent = {member_id} AND code = 'newsletter'")
    status, again_answer = stack.api(f"vereine/members/{member_id}/consents", form_key, method="POST", data=decision)
    events_again = stack.value(f"SELECT COUNT(*) FROM llx_vereine_consent WHERE fk_adherent = {member_id} AND code = 'newsletter'")
    expect(status == 200 and again_answer.get("recorded") is False and events_again == events,
           f"the same order twice: HTTP {status} {again_answer}, {events} then {events_again} entries")

    # A new version of the text must not stand in the way of a withdrawal (Art. 7 (3) GDPR).
    page_ok(browser.submit(page_ok(browser.get("/custom/vereine/admin/consents.php"), "consent setup").form(name="vereineconsenttext"),
                           {"code": "newsletter", "label": "Newsletter", "text": "Der Newsletter darf an meine E-Mail-Adresse gehen, dritte Fassung."}),
            "a third version of the newsletter consent")
    status, answer = stack.api(f"vereine/members/{member_id}/consents", form_key, method="POST",
                               data={"code": "newsletter", "decision": "withdrawn", "granted_at": "2026-09-23T11:00:00+02:00", "reference": "web-c-78"})
    expect(status == 200 and answer.get("state") == "withdrawn", f"withdrawing after a new version answered HTTP {status}: {answer}")
    status, state = stack.api(f"vereine/members/{member_id}/consents", key)
    newest = {row["code"]: row for row in state}["newsletter"]["current_version"]
    status, answer = stack.api(f"vereine/members/{member_id}/consents", form_key, method="POST",
                               data={"code": "newsletter", "decision": "given", "version": newest,
                                     "granted_at": "2026-09-23T09:00:00+02:00", "reference": "web-c-79"})
    expect(status == 409, f"a consent from before the withdrawal answered HTTP {status}: {answer}")
    status, _ = stack.api("vereine/members/999999/consents", form_key, method="POST", data=decision)
    expect(status == 404, f"a decision for a member that does not exist answered HTTP {status}")

    # The way of an application: state, the association decides in Dolibarr, the website may take it back (#72).
    status, state = stack.api(f"vereine/applications/{body['external_id']}", form_key)
    expect(status == 200 and state.get("status") == "received" and state.get("member_id") == 0,
           f"GET vereine/applications/external answered HTTP {status}: {state}")
    status, conflict = stack.api("vereine/applications", form_key, method="POST", data={**body, "lastname": "Anders"})
    expect(status == 409, f"the same external_id with other content answered HTTP {status}: {conflict}")
    page = page_ok(browser.get("/custom/vereine/applications.php"), "the applications in Dolibarr")
    rows = re.findall(r'data-application="(\d+)" data-status="([a-z_]+)" data-lookalikes="(\d+)"', page.text)
    expect(any(row[1] == "received" for row in rows), f"the application is not waiting in Dolibarr: {rows}")
    application_id = [row[0] for row in rows if row[1] == "received"][0]
    refused = page_ok(browser.post("/custom/vereine/applications.php", [("token", token_of(page)), ("action", "decide"),
                                                                       ("application", application_id), ("status", "rejected"), ("reason", "")]),
                      "say no without a reason")
    expect("Grund" in html.unescape(refused.text), "an application was rejected without a reason for the person")
    page_ok(browser.submit(page.form(name=f"vereineapplication{application_id}"), {"status": "accepted"}), "take the applicant in")
    member_status = stack.value(f"SELECT statut FROM llx_adherent WHERE rowid = {member_id}")
    status, state = stack.api(f"vereine/applications/{body['external_id']}", form_key)
    expect(member_status == "1" and state.get("status") == "accepted" and state.get("member_id") == member_id,
           f"after taking in: member status {member_status}, application {state}")
    status, answer = stack.api(f"vereine/applications/{body['external_id']}/withdraw", form_key, method="POST")
    expect(status == 409, f"withdrawing an accepted application answered HTTP {status}: {answer}")
    logged = stack.value(f"SELECT COUNT(*) FROM llx_vereine_log WHERE fk_adherent = {member_id} AND action = 'application_decided'")
    expect(logged == "1", f"{logged} entries about the decision, expected 1")

    # A second application that the website takes back again.
    second = {**body, "external_id": "web-2026-0043", "email": "zweite.antrag@runtime-verein.test", "lastname": "Zweitantrag"}
    status, created_second = stack.api("vereine/applications", form_key, method="POST", data=second)
    expect(status == 200 and created_second.get("application_status") == "received", f"the second application answered HTTP {status}: {created_second}")
    status, withdrawn = stack.api(f"vereine/applications/{second['external_id']}/withdraw", form_key, method="POST")
    status_again, withdrawn_again = stack.api(f"vereine/applications/{second['external_id']}/withdraw", form_key, method="POST")
    expect(status == 200 and withdrawn.get("changed") is True and status_again == 200 and withdrawn_again.get("changed") is False,
           f"withdrawing: {withdrawn} then {withdrawn_again}")
    status, _ = stack.api("vereine/applications/gibtsnicht", form_key)
    expect(status == 404, f"an application that does not exist answered HTTP {status}")
    expect(denied(stack.browser("rtnobody").get("/custom/vereine/applications.php")), "a user without rights opens the applications")

    # The paper way: the signed declaration is kept with the documents of the member (#109).
    page_ok(browser.submit(tab.form(name="vereinerecordconsent")), "record a consent on paper")
    event = stack.value(f"SELECT rowid FROM llx_vereine_consent WHERE fk_adherent = {member_id} AND source = 'paper' AND given = 1 ORDER BY rowid DESC LIMIT 1")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={member_id}"), "association tab before the scan")
    scanned = b"%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"
    refused = page_ok(browser.post_multipart(f"/custom/vereine/member_association.php?id={member_id}",
                                             [("token", token_of(tab)), ("action", "consentscan"), ("consent_event", event)],
                                             [("scan_file", "erklaerung.txt", scanned)]), "a scan that is no document")
    expect("Nur PDF" in html.unescape(refused.text), "a file that is no PDF, JPG or PNG was kept as a scan")
    page_ok(browser.post_multipart(f"/custom/vereine/member_association.php?id={member_id}",
                                   [("token", token_of(tab)), ("action", "consentscan"), ("consent_event", event)],
                                   [("scan_file", "erklaerung.pdf", scanned)]), "attach the signed declaration")
    stored = stack.value(f"SELECT scan_name FROM llx_vereine_consent WHERE rowid = {event}")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={member_id}"), "association tab with the scan")
    expect(stored == f"einwilligung-{event}.pdf" and 'data-consent-proof="scan"' in tab.text, f"the scan was stored as {stored!r}")
    download = browser.get(f"/custom/vereine/member_association.php?id={member_id}&action=consentscanget&consent_event={event}&token={token_of(tab)}")
    expect(download.status == 200 and download.body.startswith(b"%PDF"), f"the scan could not be read again: HTTP {download.status}")
    expect(denied(stack.browser("rtnobody").get(f"/custom/vereine/member_association.php?id={member_id}&action=consentscanget&consent_event={event}")),
           "somebody without rights reads the scan of a consent")
    return ("bad code refused; photos v1 and v2 and newsletter v1 stored, API offers the newest versions; website user without right 403; "
            "application became a member in draft with the photo consent v2 from the website, with the moment and the form it was given on; "
            "sent again: same member, duplicate; outdated version, bad e-mail and missing name refused with 400; withdrawal recorded and shown; "
            "signed declaration attached as PDF, kept with the documents of the member, readable again, not for somebody without rights")


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
    events = stack.sql(f"SELECT label, DATE(datep), percent FROM llx_actioncomm WHERE elementtype = 'member' AND fk_element = {karl} AND {NOT_LOG_EVENT}")
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
    percent = stack.value(f"SELECT percent FROM llx_actioncomm WHERE elementtype = 'member' AND fk_element = {karl} AND {NOT_LOG_EVENT}")
    expect(open_reports == "0" and percent == "100" and "data-report=" not in overview().text,
           f"after noting as reported: {open_reports} open, agenda event at {percent} %")
    browser.post(f"/custom/vereine/functions.php?day={today}", [("token", token_of(overview())), ("action", "reportpdf")])
    expect("bestellt" not in pdf_text(stack, "vereine/authority"), "the letter still marks representatives as new after they were reported")
    logged = stack.value("SELECT COUNT(*) FROM llx_vereine_log WHERE action = 'function_reported'")
    expect(logged == "3", f"{logged} reports logged, expected 3")
    return (f"chair and treasurer due on {deadline}; deputy treasurer with agenda event on the deadline; missing birth date, place of birth "
            "and address reported, overdue marked; letter with names, functions, birth place, address, ZVR number and new ones marked; "
            "noted as reported: no open report, agenda event done, letter without new marks")


def duties(stack: Stack) -> str:
    """The calendar of duties: the catalogue of the law, the days of a year, tasks in Dolibarr's agenda and the handover after a change of office (#24)."""
    browser = stack.browser()
    today = stack.today()
    year = int(today[:4])
    base = f"/custom/vereine/duties.php?year={year}"
    expect(denied(stack.browser("rtnobody").get(base)), "a user without rights opens the calendar of duties")

    # Enabling the module filled the catalogue with what Austrian law asks of every association.
    page = page_ok(browser.get(base), "the calendar of duties")
    catalogue = dict(re.findall(r'data-duty-entry="([a-z_]+)" data-duty-active="(\d)"', page.text))
    expect({"account", "audit", "inform", "assembly", "donations", "volunteers", "cash_register", "report"} <= set(catalogue),
           f"the catalogue of the law: {sorted(catalogue)}")
    expect(catalogue["account"] == "1" and catalogue["donations"] == "0",
           "what every association owes is on, what only some do is off until they switch it on")

    # The treasurer switches the report of donations on; its day follows the association's year.
    donations = int(stack.value("SELECT rowid FROM llx_vereine_duty WHERE code = 'donations' AND entity = 1"))
    form = page_ok(browser.get(f"{base}&edit={donations}"), "the report of donations in the catalogue")
    page_ok(browser.submit(form.form(name="vereineduty"), {"active": "1"}), "switch the report of donations on")
    page = page_ok(browser.get(base), "the calendar with the report of donations")
    due = dict(re.findall(r'data-duty-code="([a-z_]+)" data-duty-state="[a-z]+" data-duty-due="([\d-]*)"', page.text))
    expect(due.get("donations") == f"{year + 1}-02-28", f"the report of donations is due end of February: {due.get('donations')}")
    expect("report" not in due, "what follows an event stands in the year's plan although it has no day")
    expect(due.get("account") == f"{year + 1}-05-31", f"the account is due five months after the year: {due.get('account')}")
    expect('data-duty-unplanned="1"' in page.text, "the duties already have tasks although nobody planned them")

    # Planning writes one task per duty as an agenda event of the person who holds the function.
    holding = stack.sql("SELECT t.rowid, t.fk_adherent FROM llx_vereine_function_term as t INNER JOIN llx_vereine_function as f ON f.rowid = t.fk_function"
                        f" WHERE f.code = 'kassier' AND t.date_start <= '{today}' AND (t.date_end IS NULL OR t.date_end >= '{today}')"
                        " ORDER BY t.rowid DESC LIMIT 1")
    expect(len(holding) == 1, f"exactly one treasurer should hold office today: {holding}")
    kassier_term, kassier = int(holding[0][0]), int(holding[0][1])
    page = page_ok(browser.get(base), "the calendar before planning")
    page_ok(browser.submit(page.form(name="vereinedutyplan")), "put the duties into the agenda")
    tasks = stack.sql("SELECT d.code, t.due_on, t.fk_adherent, t.fk_actioncomm FROM llx_vereine_duty_task as t"
                      f" INNER JOIN llx_vereine_duty as d ON d.rowid = t.fk_duty WHERE t.fiscal_year = {year} ORDER BY d.code")
    planned = {row[0]: row for row in tasks}
    expect("donations" in planned and planned["donations"][1][:10] == f"{year + 1}-02-28", f"the tasks of the year: {tasks}")
    expect(int(planned["donations"][2]) == kassier, f"the report of donations went to {planned['donations'][2]}, the treasurer is {kassier}")
    events = {row[0] for row in stack.sql("SELECT id FROM llx_actioncomm WHERE label LIKE 'Vereinspflicht%'")}
    expect(str(planned["donations"][3]) in events, f"no agenda event for the report of donations: {sorted(events)}")

    # Planning again changes nothing.
    page = page_ok(browser.get(base), "the calendar after planning")
    page_ok(browser.submit(page.form(name="vereinedutyplan")), "plan a second time")
    expect(int(stack.value(f"SELECT COUNT(*) FROM llx_vereine_duty_task WHERE fiscal_year = {year}") or 0) == len(tasks),
           "a second planning wrote tasks again")

    # A change of office: the term of the treasurer ended yesterday, somebody else took over.
    yesterday = (datetime.date.fromisoformat(today) - datetime.timedelta(days=1)).isoformat()
    successor = int(stack.value(f"SELECT rowid FROM llx_adherent WHERE statut = 1 AND rowid <> {kassier} ORDER BY rowid LIMIT 1"))
    function_id = stack.value("SELECT rowid FROM llx_vereine_function WHERE code = 'kassier' AND entity = 1")
    # The way a term is ended is what the functions scenario checks; here only its consequence matters.
    stack.sql(f"UPDATE llx_vereine_function_term SET date_end = '{yesterday}' WHERE rowid = {kassier_term}")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={successor}"), "association tab of the successor")
    page_ok(browser.submit(tab.form(name="vereineaddfunction"), {
        "function_id": function_id, "function_start": today, "function_end": "", "function_note": "Runtime"}),
        "the successor takes over as treasurer")
    holders = [int(row[0]) for row in stack.sql("SELECT t.fk_adherent FROM llx_vereine_function_term as t"
                                                f" INNER JOIN llx_adherent as a ON a.rowid = t.fk_adherent WHERE t.fk_function = {function_id}"
                                                f" AND a.statut = 1 AND t.date_start <= '{today}' AND (t.date_end IS NULL OR t.date_end >= '{today}')")]
    expect(holders == [successor], f"the treasurer today should be {successor} alone: {holders}")

    # Nothing moves by itself: the open tasks wait for a word.
    page = page_ok(browser.get(base), "the calendar after the change of office")
    waiting = re.search(r'data-duty-handovers="(\d+)"', page.text)
    expect(waiting is not None and int(waiting.group(1)) > 0, "no handover is offered although the treasurer changed")
    handover_task = int(stack.value(f"SELECT rowid FROM llx_vereine_duty_task WHERE fiscal_year = {year} AND fk_duty = {donations}"))
    expect(int(stack.value(f"SELECT fk_adherent FROM llx_vereine_duty_task WHERE rowid = {handover_task}") or 0) == kassier,
           "the task moved without anybody confirming it")
    page_ok(browser.post(base, [("token", token_of(page)), ("action", "handover"), ("task", str(handover_task))]), "confirm the handover")
    expect(int(stack.value(f"SELECT fk_adherent FROM llx_vereine_duty_task WHERE rowid = {handover_task}") or 0) == successor,
           "the task did not move to the successor")
    event_member = stack.value("SELECT a.fk_element FROM llx_actioncomm as a INNER JOIN llx_vereine_duty_task as t ON t.fk_actioncomm = a.id"
                               f" WHERE t.rowid = {handover_task}")
    expect(int(event_member or 0) == successor, f"the agenda event still hangs on member {event_member}")

    # Ticking a duty off closes its event too.
    page = page_ok(browser.get(base), "the calendar before ticking off")
    page_ok(browser.post(base, [("token", token_of(page)), ("action", "done"), ("task", str(handover_task)), ("day", today)]),
            "tick the report of donations off")
    expect((stack.value(f"SELECT done_on FROM llx_vereine_duty_task WHERE rowid = {handover_task}") or "")[:10] == today,
           "the duty was not noted as done")
    percentage = stack.value("SELECT a.percent FROM llx_actioncomm as a INNER JOIN llx_vereine_duty_task as t ON t.fk_actioncomm = a.id"
                             f" WHERE t.rowid = {handover_task}")
    expect(int(percentage or 0) == 100, f"the agenda event is still open: {percentage}")
    page = page_ok(browser.get(base), "the calendar after ticking off")
    expect('data-duty-code="donations" data-duty-state="done"' in page.text, "the report of donations is not shown as done")

    # The overview names the deadlines of the calendar, not a list of its own.
    overview = page_ok(browser.get("/custom/vereine/vereineindex.php"), "the overview with what is to do")
    expect('data-todo-kind="duty"' in overview.text, "the overview does not name the duties of the calendar")

    # An own duty of the association, and what the catalogue refuses.
    form = page_ok(browser.get(base), "the catalogue for an own duty")
    refused = page_ok(browser.submit(form.form(name="vereineduty"), {
        "duty": "0", "code": "Sponsoren Bericht", "label": "Bericht an Sponsoren", "basis": "calendar", "due_month": "3", "due_day": "15"}),
        "an own duty with a wrong code")
    expect("Kleinbuchstaben" in html.unescape(refused.text), "a code with a blank was not refused with an explanation")
    page_ok(browser.submit(page_ok(browser.get(base), "the catalogue again").form(name="vereineduty"), {
        "duty": "0", "code": "sponsorenbericht", "label": "Bericht an Sponsoren", "function_code": "obmann", "basis": "calendar",
        "due_month": "3", "due_day": "15", "every_years": "1", "lead_days": "30", "active": "1"}), "add an own duty")
    own = stack.sql("SELECT code, standard, due_month, due_day FROM llx_vereine_duty WHERE code = 'sponsorenbericht' AND entity = 1")
    expect(own and own[0][1] == "0" and own[0][2] == "3", f"the own duty was not stored: {own}")
    page = page_ok(browser.get(base), "the calendar with the own duty")
    expect(f'data-duty-code="sponsorenbericht" data-duty-state=' in page.text and f'{year + 1}-03-15' in page.text,
           "the own duty has no day in the year")
    return (f"the catalogue holds the duties of the law, {len(tasks)} of them planned for {year} as agenda events of the right function; "
            "the report of donations falls due end of February, a second planning writes nothing; after a change of office the open task "
            "waits and moves to the successor only when confirmed, together with its agenda event; ticking it off closes the event and "
            "the overview names the deadlines; an own duty of the association is taken, a wrong code refused")


def events(stack: Stack) -> str:
    """Events from templates: one project of Dolibarr with its tasks, the checklist, a template that changes later (#23)."""
    browser = stack.browser()
    today = stack.today()
    day = (datetime.date.fromisoformat(today) + datetime.timedelta(days=90)).isoformat()
    base = "/custom/vereine/events.php"
    setup = "/custom/vereine/admin/events.php"
    expect(denied(stack.browser("rtnobody").get(base)), "a user without rights opens the events")
    expect(denied(stack.browser("rtreader").get(setup)), "a non-administrator opens the event templates")

    # Projects are Dolibarr's own; the module only leans on them.
    enable_dolibarr_module(stack, "modProjet")
    expect(stack.const("MAIN_MODULE_PROJET") == "1", "Dolibarr's project module could not be switched on")

    # Enabling the module brought the suggested templates with their points.
    page = page_ok(browser.get(setup), "the event templates")
    templates = {code: int(count) for code, count in re.findall(r'data-template="([a-z_]+)" data-template-points="(\d+)"', page.text)}
    expect({"turnier", "vereinsfest"} <= set(templates) and templates["turnier"] >= 10, f"the suggested templates: {templates}")
    template_id = int(stack.value("SELECT rowid FROM llx_vereine_event_template WHERE code = 'turnier' AND entity = 1"))
    points = page_ok(browser.get(f"{setup}?template={template_id}"), "the points of the tournament")
    phases = {phase for phase in re.findall(r'data-template-phase="([a-z]+)"', points.text)}
    expect(phases == {"before", "during", "after"}, f"the phases of the tournament: {sorted(phases)}")

    # An event out of the template: one project, one task per point, the days counted from the day.
    form = page_ok(browser.get(base), "the events").form(name="vereineevent")
    refused = page_ok(browser.submit(form, {"template": str(template_id), "label": "Winter-Cup", "event_day": day,
                                            "registration": "external", "external_ref": ""}), "an external sign-up without its reference")
    expect("Kennung" in html.unescape(refused.text), "an external sign-up without its reference was not refused with an explanation")
    form = page_ok(browser.get(base), "the events again").form(name="vereineevent")
    page = page_ok(browser.submit(form, {"template": str(template_id), "label": "Winter-Cup", "event_day": day,
                                         "place": "Telfs", "public": "1", "registration": "dolibarr"}), "an event from the template")
    event_id = int(stack.value("SELECT rowid FROM llx_vereine_event WHERE label = 'Winter-Cup' AND entity = 1"))
    project_id = int(stack.value(f"SELECT fk_projet FROM llx_vereine_event WHERE rowid = {event_id}") or 0)
    expect(project_id > 0, "the event has no project of Dolibarr")
    project = stack.sql(f"SELECT ref, title, public, usage_organize_event FROM llx_projet WHERE rowid = {project_id}")
    expect(project and project[0][1] == "Winter-Cup" and project[0][3] == "1",
           f"the project of the event: {project}; Dolibarr leads the sign-up, so its event organisation is on")
    checklist = stack.sql(f"SELECT phase, label, due_on, fk_task FROM llx_vereine_event_task WHERE fk_event = {event_id} ORDER BY due_on")
    expect(len(checklist) == templates["turnier"], f"{len(checklist)} points for {templates['turnier']} in the template")
    tasks = int(stack.value(f"SELECT COUNT(*) FROM llx_projet_task WHERE fk_projet = {project_id}") or 0)
    expect(tasks == len(checklist), f"{tasks} tasks in the project for {len(checklist)} points")
    announced = [row for row in checklist if "Gemeinde anzeigen" in row[1]]
    expect(announced and announced[0][2][:10] == (datetime.date.fromisoformat(day) - datetime.timedelta(days=42)).isoformat(),
           f"the notice to the municipality is not six weeks before the day: {announced}")

    # The checklist as the page shows it, with its progress.
    page = page_ok(browser.get(f"{base}?id={event_id}"), "the event with its checklist")
    rows = re.findall(r'data-event-task="(\d+)" data-event-phase="([a-z]+)" data-event-state="([a-z]+)"', page.text)
    expect(len(rows) == len(checklist), f"the page shows {len(rows)} points for {len(checklist)}")
    expect('data-event-progress="0"' in page.text, "the event starts with something done")

    # Ticking a point off writes the progress back to the task of the project.
    first_task = int(rows[0][0])
    project_task = int(stack.value(f"SELECT fk_task FROM llx_vereine_event_task WHERE rowid = {first_task}") or 0)
    page_ok(browser.post(f"{base}?id={event_id}", [("token", token_of(page)), ("action", "done"), ("task", str(first_task))]),
            "tick the first point off")
    expect((stack.value(f"SELECT done_on FROM llx_vereine_event_task WHERE rowid = {first_task}") or "")[:10] == today,
           "the point was not noted as done")
    expect(int(stack.value(f"SELECT progress FROM llx_projet_task WHERE rowid = {project_task}") or 0) == 100,
           "the task of the project is still open")

    # A template that changes later leaves an event that already runs alone.
    page_ok(browser.submit(page_ok(browser.get(f"{setup}?template={template_id}"), "the points again").form(name="vereinetemplatetask"), {
        "task_label": "Pokale bestellen", "phase": "before", "function_code": "kassier", "offset_days": "-35", "source": ""}),
        "add a point to the template")
    expect(int(stack.value(f"SELECT COUNT(*) FROM llx_vereine_event_task WHERE fk_event = {event_id}") or 0) == len(checklist),
           "the running event took the new point of the template")
    expect(int(stack.value(f"SELECT COUNT(*) FROM llx_vereine_event_template_task WHERE fk_template = {template_id}") or 0) == len(checklist) + 1,
           "the new point did not reach the template")

    # A point the association thought of itself, next to the ones from the template.
    page = page_ok(browser.get(f"{base}?id={event_id}"), "the event before its own point")
    page_ok(browser.submit(page.form(name="vereineeventtask"), {
        "task_label": "Streaming-Technik testen", "phase": "during", "function_code": "", "due_on": day}), "add an own point")
    own = stack.sql(f"SELECT label, fk_template_task, fk_task FROM llx_vereine_event_task WHERE fk_event = {event_id}"
                    " AND label = 'Streaming-Technik testen'")
    expect(own and own[0][1] in ("", "NULL", None) and int(own[0][2] or 0) > 0,
           f"the own point is missing or hangs on a template: {own}")

    # A second event from the same template gets its own project, and nothing of the first one moves.
    form = page_ok(browser.get(base), "the events for a second one").form(name="vereineevent")
    page_ok(browser.submit(form, {"template": str(template_id), "label": "Sommer-Cup",
                                  "event_day": (datetime.date.fromisoformat(day) + datetime.timedelta(days=120)).isoformat(),
                                  "registration": "external", "external_ref": "lionsapp-42"}), "a second event from the template")
    second = int(stack.value("SELECT rowid FROM llx_vereine_event WHERE label = 'Sommer-Cup' AND entity = 1"))
    second_project = int(stack.value(f"SELECT fk_projet FROM llx_vereine_event WHERE rowid = {second}") or 0)
    expect(second_project > 0 and second_project != project_id, f"the second event shares the project {second_project}")
    # The sign-up is led outside, so Dolibarr's own event organisation stays off: nothing is booked twice (#165).
    expect(stack.value(f"SELECT usage_organize_event FROM llx_projet WHERE rowid = {second_project}") == "0",
           "Dolibarr organises the sign-up although an external application leads it")
    expect(int(stack.value(f"SELECT COUNT(*) FROM llx_vereine_event_task WHERE fk_event = {event_id}") or 0) == len(checklist) + 1,
           "the first event changed when the second one was made")

    # The list shows what is coming, with how far each event has got.
    page = page_ok(browser.get(base), "the list of events")
    listed = re.findall(r'data-event-row="(\d+)" data-event-done="(\d+)" data-event-total="(\d+)"', page.text)
    found = {int(row[0]): (int(row[1]), int(row[2])) for row in listed}
    expect(found.get(event_id) == (1, len(checklist) + 1) and second in found, f"the list of events: {found}")
    return (f"the suggested templates stand with their phases; the Winter-Cup became project {project_id} with {tasks} tasks, the notice "
            "to the municipality six weeks before the day; ticking a point off closed the task of the project; a point added to the "
            "template later left the running event alone; an own point and a second event with its own project, whose sign-up is led "
            "outside so Dolibarr does not organise it as well")


def shifts(stack: Stack) -> str:
    """Helper shifts: places, overlapping times, who really was there, and the short report as PDF (#23)."""
    browser = stack.browser()
    today = stack.today()
    event_id = int(stack.value("SELECT rowid FROM llx_vereine_event WHERE label = 'Winter-Cup' AND entity = 1"))
    day = stack.value(f"SELECT event_day FROM llx_vereine_event WHERE rowid = {event_id}")[:10]
    base = f"/custom/vereine/events.php?id={event_id}"
    page = page_ok(browser.get(base), "the event without shifts")
    expect('data-shifts-none="1"' in page.text, "the event already has shifts")

    # Two shifts of the day, one after the other, each with two places.
    for label, start, end in (("Kassa Vormittag", "08:00", "12:00"), ("Kassa Nachmittag", "12:00", "16:00")):
        page = page_ok(browser.get(base), f"the event before {label}")
        page_ok(browser.submit(page.form(name="vereineshift"), {
            "shift_label": label, "shift_day": day, "start_time": start, "end_time": end, "capacity": "2",
            "shift_function": "kassier"}), f"add the shift {label}")
    page = page_ok(browser.get(base), "the event with its shifts")
    refused = page_ok(browser.submit(page.form(name="vereineshift"), {
        "shift_label": "Unfug", "shift_day": day, "start_time": "16:00", "end_time": "08:00", "capacity": "2"}),
        "a shift that ends before it starts")
    expect("Ende muss nach dem Beginn" in html.unescape(refused.text), "a shift ending before it starts was not refused with an explanation")
    plan = stack.sql(f"SELECT rowid, label, capacity FROM llx_vereine_event_shift WHERE fk_event = {event_id} ORDER BY start_time")
    expect(len(plan) == 2, f"the shifts of the event: {plan}")
    morning, afternoon = int(plan[0][0]), int(plan[1][0])

    # Three members: two fill the morning, the third is refused, and nobody does two shifts at once.
    members = [int(row[0]) for row in stack.sql("SELECT rowid FROM llx_adherent WHERE statut = 1 ORDER BY rowid LIMIT 3")]
    expect(len(members) == 3, f"three members are needed for the shifts: {members}")
    for member in members[:2]:
        page = page_ok(browser.get(base), "the event before somebody is put on")
        page_ok(browser.submit(page.form(name="vereineshiftperson"), {"shift": str(morning), "member": str(member)}),
                f"put member {member} on the morning shift")
    page = page_ok(browser.get(base), "the event with a full shift")
    refused = page_ok(browser.submit(page.form(name="vereineshiftperson"), {"shift": str(morning), "member": str(members[2])}),
                      "a third person on a shift for two")
    expect("voll" in html.unescape(refused.text), "a full shift took a third person without saying anything")
    page = page_ok(browser.get(base), "the event before the same person twice")
    refused = page_ok(browser.submit(page.form(name="vereineshiftperson"), {"shift": str(morning), "member": str(members[0])}),
                      "the same person twice on one shift")
    expect("steht schon" in html.unescape(refused.text), "the same person went on one shift twice")
    taken = stack.sql(f"SELECT fk_adherent, status FROM llx_vereine_event_shift_entry WHERE fk_shift = {morning} ORDER BY rowid")
    expect(len(taken) == 2 and {row[1] for row in taken} == {"confirmed"}, f"who is on the morning shift: {taken}")

    # The afternoon shift takes the third member; the times do not overlap, so that is fine.
    page = page_ok(browser.get(base), "the event before the afternoon shift")
    page_ok(browser.submit(page.form(name="vereineshiftperson"), {"shift": str(afternoon), "member": str(members[2])}),
            "put the third member on the afternoon shift")
    expect(int(stack.value(f"SELECT COUNT(*) FROM llx_vereine_event_shift_entry WHERE fk_shift = {afternoon}") or 0) == 1,
           "the afternoon shift did not take the third member")

    # Being put on a shift is not having been there: the association confirms that afterwards, with hours.
    entry = int(stack.value(f"SELECT rowid FROM llx_vereine_event_shift_entry WHERE fk_shift = {morning} ORDER BY rowid LIMIT 1"))
    expect(stack.value(f"SELECT hours FROM llx_vereine_event_shift_entry WHERE rowid = {entry}") in ("", "NULL", None),
           "somebody who was put on a shift already carries hours")
    page = page_ok(browser.get(base), "the event before somebody is noted as present")
    page_ok(browser.post(base, [("token", token_of(page)), ("action", "entry"), ("entry", str(entry)), ("status", "done"), ("hours", "")]),
            "note the first helper as present")
    done = stack.sql(f"SELECT status, hours FROM llx_vereine_event_shift_entry WHERE rowid = {entry}")
    expect(done and done[0][0] == "done" and float(done[0][1]) == 4.0, f"the confirmed duty: {done}; the shift lasts four hours")

    # The short report holds the checklist, the helpers and the money of the project.
    page = page_ok(browser.get(base), "the event before the report")
    expect('data-event-report="0"' in page.text, "a report exists although nobody built one")
    page_ok(browser.post(base, [("token", token_of(page)), ("action", "report")]), "build the short report")
    page = page_ok(browser.get(base), "the event with the report")
    expect('data-event-report="1"' in page.text, "the report was not built")
    link = re.search(r'href="([^"]*action=reportpdf[^"]*)"', page.text)
    expect(link is not None, "the page offers no link to the report")
    pdf = browser.get(html.unescape(link.group(1)))
    expect(pdf.status == 200 and pdf.body[:4] == b"%PDF", f"the report is no PDF: {pdf.status}, {pdf.body[:8]!r}")
    text = pdf_bytes_text(pdf.body)
    expect("Kurzbericht" in text and "Winter-Cup" in text, f"the report names neither itself nor the event: {text[:200]!r}")
    expect("Stunden" in text and "Helferdienste" in text, f"the report says nothing about the helpers: {text[:400]!r}")

    # What a member sees of their own shifts, and that a member without rights cannot plan.
    expect(denied(stack.browser("rtnobody").get(base)), "a user without rights opens the event")
    return (f"two shifts of four hours each with two places; the morning shift refused a third person and the same person twice, the "
            "afternoon shift took the third member because the times do not overlap; being put on a shift carried no hours until the "
            "association noted the first helper as present with four hours; the short report is a PDF with the checklist, the helpers "
            "and the money of the project")


def assembly(stack: Stack) -> str:
    """The way through a general assembly: the invitation deadline, a due election, the missing audit report, and afterwards the notice to the authority (#127)."""
    browser = stack.browser()
    today = stack.today()
    base = "/custom/vereine/assembly.php"
    expect(denied(stack.browser("rtnobody").get(base)), "a user without rights opens the way through the assembly")

    # A general assembly in thirty days, so the invitation deadline still runs.
    day = (datetime.date.fromisoformat(today) + datetime.timedelta(days=30)).isoformat()
    meeting = int(stack.value("SELECT rowid FROM llx_vereine_meeting WHERE kind = 'general' AND entity = 1 ORDER BY rowid DESC LIMIT 1"))
    stack.sql(f"UPDATE llx_vereine_meeting SET meeting_day = '{day}', status = 'planned', invited_at = NULL WHERE rowid = {meeting}")

    page = page_ok(browser.get(base), "the way through the assembly")
    steps = {code: state for code, state in re.findall(r'data-step="([a-z]+)" data-step-state="([a-z]+)"', page.text)}
    expect(len(steps) == 17, f"the way should hold seventeen steps: {sorted(steps)}")
    phases = re.findall(r'data-assembly-phase="([a-z]+)"', page.text)
    expect(phases == ["before", "meeting", "after"], f"the phases in their order: {phases}")

    # What the acceptance of the issue asks for: the invitation with its deadline, a due election, the
    # audit report that is not signed.
    expect(steps.get("invitation") in ("now", "overdue"), f"the invitation is not what to do now: {steps.get('invitation')}")
    invite_deadline = (datetime.date.fromisoformat(day) - datetime.timedelta(days=int(stack.value(
        "SELECT JSON_EXTRACT(value, '$.invite_days') FROM llx_const WHERE name = 'VEREINE_STATUTE_RULES' AND entity = 1") or 14))).isoformat()
    expect(f'data-step="invitation"' in page.text and vereine_day(invite_deadline) in page.text,
           f"the invitation deadline {invite_deadline} does not stand on the page")
    expect(steps.get("elections") == "now", f"a due election is not shown: {steps.get('elections')}")
    expect(steps.get("auditreport") in ("now", "overdue"), f"the missing audit report is not shown: {steps.get('auditreport')}")
    expect(steps.get("attendance") in ("later", "done") and steps.get("minutes") in ("later", "done"),
           f"what belongs to the day and afterwards is asked for too early: {steps.get('attendance')}, {steps.get('minutes')}")

    # The same points stand where everybody looks.
    overview = page_ok(browser.get("/custom/vereine/vereineindex.php"), "the overview with what is to do")
    expect('data-todo-kind="assembly"' in overview.text, "the overview does not name what the assembly still needs")

    # After the assembly with an election, the authority has to hear of it within four weeks.
    held = (datetime.date.fromisoformat(today) - datetime.timedelta(days=1)).isoformat()
    stack.sql(f"UPDATE llx_vereine_meeting SET meeting_day = '{held}', status = 'held' WHERE rowid = {meeting}")
    reports = int(stack.value("SELECT COUNT(*) FROM llx_vereine_function_report WHERE reported_on IS NULL AND entity = 1") or 0)
    page = page_ok(browser.get(base), "the way after the assembly")
    steps = {code: state for code, state in re.findall(r'data-step="([a-z]+)" data-step-state="([a-z]+)"', page.text)}
    expect(steps.get("attendance") in ("done", "now") and steps.get("minutes") in ("done", "now"),
           f"after the day the meeting and what follows are due: {steps.get('attendance')}, {steps.get('minutes')}")
    expect((reports > 0) == (steps.get("authority") in ("now", "overdue")),
           f"{reports} notices to the authority are open, the step says {steps.get('authority')}")
    progress = re.search(r'data-assembly-progress="(\d+)"', page.text)
    expect(progress is not None and 0 <= int(progress.group(1)) <= 100, f"the way shows no progress: {progress}")
    return (f"seventeen steps in three phases; thirty days ahead the invitation deadline, a due election and the missing audit report "
            f"stand as what to do, the day itself and everything after it as still to come; the overview names them too; after the "
            f"assembly the notice to the authority ({reports} open) and the minutes are what follows")


def changes(stack: Stack) -> str:
    """The change feed: a note per change, a cursor that loses nothing, resync instead of a silent gap (#154)."""
    key = stack.notes["website"]["key"]

    # The feed has a right of its own: the website key may read summaries but must not follow changes.
    status, refused = stack.api("vereine/changes", key)
    expect(status == 403, f"the website key follows the change feed although it may not: HTTP {status}")
    # Enabling the module registered the right; the service that synchronises simply gets it.
    sync_user = int(stack.value("SELECT rowid FROM llx_user WHERE login = 'rtwebsite'"))
    right = int(stack.value("SELECT id FROM llx_rights_def WHERE module = 'vereine' AND perms = 'sync'"
                            " AND subperms = 'read' AND entity = 1") or 0)
    expect(right > 0, "enabling the module did not register the right to follow the change feed")
    stack.sql(f"INSERT INTO llx_user_rights (entity, fk_user, fk_id) SELECT 1, {sync_user}, {right}"
              f" WHERE NOT EXISTS (SELECT 1 FROM llx_user_rights WHERE fk_user = {sync_user} AND fk_id = {right})")
    status, feed = stack.api("vereine/changes", key)
    expect(status == 200 and "events" in feed, f"the feed answered HTTP {status}: {str(feed)[:160]}")
    expect(feed["resync_required"] is False and feed["retention_days"] == 90,
           f"a first read should simply start: {feed['resync_required']}, {feed['retention_days']} days")

    # Everything the module noted so far, read to the end; that is where the reader stands.
    cursor = ""
    seen = []
    for _ in range(50):
        status, page = stack.api(f"vereine/changes?cursor={cursor}&limit=100", key)
        expect(status == 200, f"the feed answered HTTP {status}")
        seen.extend(page["events"])
        cursor = page["next_cursor"]
        if not page["has_more"]:
            break
    expect(seen, "the feed holds nothing although members and invoices were changed")
    kinds = {event["object_type"] for event in seen}
    expect(kinds <= {"membership", "function", "fee", "application", "consent"}, f"kinds in the feed: {sorted(kinds)}")
    body = json.dumps(seen)
    for forbidden in ("firstname", "lastname", "iban", "amount", "email"):
        expect(forbidden not in body, f"the feed carries {forbidden}, which is content and does not belong in it")
    ids = [event["event_id"] for event in seen]
    expect(len(ids) == len(set(ids)), "the feed delivered the same event twice")

    # A change of a member appears once, with a revision of its own; reading again brings nothing new.
    member = int(stack.value("SELECT rowid FROM llx_adherent WHERE statut = 1 ORDER BY rowid LIMIT 1"))
    before = int(stack.value(f"SELECT COUNT(*) FROM llx_vereine_change WHERE object_type = 'membership' AND object_id = {member}") or 0)
    browser = stack.browser()
    card = page_ok(browser.get(f"/adherents/card.php?id={member}&action=edit"), "the member card to change something")
    page_ok(browser.submit(card.form(action_part="card.php"), {"note_public": "Feed-Test " + stack.today()}), "change the member")
    after = int(stack.value(f"SELECT COUNT(*) FROM llx_vereine_change WHERE object_type = 'membership' AND object_id = {member}") or 0)
    expect(after > before, f"changing the member wrote no entry: {before} -> {after}")
    revisions = [int(row[0]) for row in stack.sql("SELECT revision FROM llx_vereine_change WHERE object_type = 'membership'"
                                                  f" AND object_id = {member} ORDER BY revision")]
    expect(revisions == list(range(1, len(revisions) + 1)), f"the revisions of the member count up: {revisions}")

    # The entry only shows up once the safety margin has passed, and then exactly once.
    fresh = []
    for _ in range(20):
        status, page = stack.api(f"vereine/changes?cursor={cursor}&limit=100", key)
        expect(status == 200, f"the feed answered HTTP {status}")
        fresh.extend(page["events"])
        cursor = page["next_cursor"]
        if not page["has_more"] and fresh:
            break
        time.sleep(1)
    expect(any(event["object_type"] == "membership" and event["object_id"] == member for event in fresh),
           f"the change of member {member} never reached the feed: {fresh[:3]}")
    status, again = stack.api(f"vereine/changes?cursor={cursor}&limit=100", key)
    expect(status == 200 and again["events"] == [], f"reading again brought events a second time: {again['events'][:2]}")

    # A cursor from before the retention, and one nobody wrote here.
    old_cursor = stack.shell("php -r \"echo bin2hex('v1|2020-01-01 00:00:00|1');\"").stdout.strip()
    status, stale = stack.api(f"vereine/changes?cursor={old_cursor}", key)
    expect(status == 200 and stale["resync_required"] is True and stale["events"] == [],
           f"an old cursor has to ask for a full reconciliation: {stale}")
    status, bogus = stack.api("vereine/changes?cursor=keincursor", key)
    expect(status == 200 and bogus["resync_required"] is True, f"a cursor nobody wrote here is taken: {bogus}")

    # Only what a kind of object asks for.
    status, only = stack.api("vereine/changes?types=membership&limit=500", key)
    expect(status == 200 and all(event["object_type"] == "membership" for event in only["events"]),
           "the feed answers with kinds nobody asked for")

    # The full reconciliation: ids only, and a mark that says it is complete.
    members = int(stack.value("SELECT COUNT(*) FROM llx_adherent WHERE entity = 1") or 0)
    status, first = stack.api("vereine/changes/snapshot?object_type=membership&limit=2", key)
    expect(status == 200 and len(first["objects"]) == 2 and first["complete"] is False,
           f"a page that is not the last one must not say complete: {first}")
    expect(set(first["objects"][0].keys()) == {"object_type", "object_id"},
           f"the reconciliation carries more than ids: {first['objects'][0]}")
    collected = list(first["objects"])
    after_id = first["next_after"]
    for _ in range(200):
        status, page = stack.api(f"vereine/changes/snapshot?object_type=membership&after={after_id}&limit=50", key)
        expect(status == 200, f"the reconciliation answered HTTP {status}")
        collected.extend(page["objects"])
        after_id = page["next_after"]
        if page["complete"]:
            break
    expect(len(collected) == members, f"the reconciliation counted {len(collected)} of {members} members")
    expect(first["cursor"] == "" or VEREINE_HEX.match(first["cursor"]), f"the reconciliation carries no usable cursor: {first['cursor']}")
    status, wrong = stack.api("vereine/changes/snapshot?object_type=bankverbindung", key)
    expect(wrong is not None and status == 400, f"a kind of object nobody knows answered HTTP {status}")
    return (f"the feed needs its own right, holds {len(seen)} notes without a single name or amount, gives every change of a member "
            "its own revision and delivers it once; an old cursor and one nobody wrote here both ask for a full reconciliation; the "
            f"reconciliation lists {members} members as bare ids and only the last page says complete")


def webhooks(stack: Stack) -> str:
    """Signed webhooks: only https targets, delivery after the commit, backoff, rotation, and a reference receiver that refuses what it should (#155)."""
    browser = stack.browser()
    setup = "/custom/vereine/admin/webhooks.php"
    expect(denied(stack.browser("rtreader").get(setup)), "a non-administrator opens the webhook setup")
    page = page_ok(browser.get(setup), "the webhook setup")
    expect('data-hook-none="1"' in page.text, "there is already a target")

    # Where a delivery may not go.
    sync_user = int(stack.value("SELECT rowid FROM llx_user WHERE login = 'rtwebsite'"))
    for url, message in (("http://verein.test/hook", "https"), ("https://user:pass@verein.test/hook", "Benutzer"),
                         ("https://localhost/hook", "eigenen Netz")):
        form = page_ok(browser.get(setup), f"the setup before {url}").form(name="vereinehook")
        refused = page_ok(browser.submit(form, {"label": "Test", "url": url, "user_id": str(sync_user)}), f"a target at {url}")
        expect(message in html.unescape(refused.text), f"{url} was not refused with an explanation")
    expect(int(stack.value("SELECT COUNT(*) FROM llx_vereine_hook_target") or 0) == 0, "a refused target was stored anyway")

    # A target nobody answers at: that is enough to watch the attempts.
    form = page_ok(browser.get(setup), "the setup for a target").form(name="vereinehook")
    # Nothing answers on that port; the stack has no name service, so a public name would look internal
    # anyway. This is how a receiver beside Dolibarr is set up, with the exception switched on.
    page = page_ok(browser.submit(form, {"label": "LionsAPP", "url": "https://127.0.0.1:9443/hook",
                                         "user_id": str(sync_user), "allow_internal": "1"}),
                   "a target that cannot be reached")
    expect('data-hook-secret="1"' in page.text, "the secret was not shown when the target was made")
    secret = re.search(r"<code>([0-9a-f]{64})</code>", page.text)
    expect(secret is not None, "the secret is not shown as a secret")
    secret = secret.group(1)
    target = int(stack.value("SELECT rowid FROM llx_vereine_hook_target WHERE label = 'LionsAPP'"))
    page = page_ok(browser.get(setup), "the setup again")
    expect(secret not in page.text and 'data-hook-secret="1"' not in page.text, "the secret is shown a second time")
    key_id = stack.value(f"SELECT key_id FROM llx_vereine_hook_target WHERE rowid = {target}")

    # A change of a member becomes a job, but only after the change itself is through.
    member = int(stack.value("SELECT rowid FROM llx_adherent WHERE statut = 1 ORDER BY rowid LIMIT 1"))
    card = page_ok(browser.get(f"/adherents/card.php?id={member}&action=edit"), "the member card")
    page_ok(browser.submit(card.form(action_part="card.php"), {"note_public": "Webhook-Test " + stack.today()}), "change the member")
    for _ in range(15):
        page = page_ok(browser.submit(page_ok(browser.get(setup), "the setup before a run").form(name="vereinehookrun")), "deliver now")
        if int(stack.value(f"SELECT COUNT(*) FROM llx_vereine_hook_delivery WHERE fk_target = {target}") or 0) > 0:
            break
        time.sleep(1)
    jobs = stack.sql(f"SELECT event_id, status, attempts, last_error, next_try FROM llx_vereine_hook_delivery WHERE fk_target = {target}")
    expect(jobs, "the change of the member never became a job")
    expect(jobs[0][1] == "pending" and int(jobs[0][2]) >= 1, f"the job should wait after a failed attempt: {jobs[0]}")
    expect(jobs[0][4] not in ("", "NULL", None), f"a job that failed has no next attempt: {jobs[0]}")
    expect(secret not in jobs[0][3], "the error message carries the secret")
    expect(len({row[0] for row in jobs}) == len(jobs), "the same event became two jobs")

    # The same event stays one job, however often the queue runs.
    before = int(stack.value(f"SELECT COUNT(*) FROM llx_vereine_hook_delivery WHERE fk_target = {target}") or 0)
    page_ok(browser.submit(page_ok(browser.get(setup), "the setup").form(name="vereinehookrun")), "deliver again")
    expect(int(stack.value(f"SELECT COUNT(*) FROM llx_vereine_hook_delivery WHERE fk_target = {target}") or 0) == before,
           "running the queue again wrote the same event a second time")

    # Starting a job again by hand keeps its name and counts from the beginning.
    delivery = int(stack.value(f"SELECT rowid FROM llx_vereine_hook_delivery WHERE fk_target = {target} ORDER BY rowid LIMIT 1"))
    event_id = stack.value(f"SELECT event_id FROM llx_vereine_hook_delivery WHERE rowid = {delivery}")
    page = page_ok(browser.get(setup), "the setup before a retry")
    page_ok(browser.post(setup, [("token", token_of(page)), ("action", "retry"), ("delivery", str(delivery))]), "start the job again")
    after = stack.sql(f"SELECT event_id, status, attempts FROM llx_vereine_hook_delivery WHERE rowid = {delivery}")
    expect(after[0][0] == event_id and after[0][1] == "pending" and int(after[0][2]) == 0,
           f"the job should wait again under its own name: {after}")

    # A target that is switched off delivers nothing, and neither does one whose right was taken away.
    stack.sql(f"UPDATE llx_vereine_hook_target SET active = 0 WHERE rowid = {target}")
    page_ok(browser.submit(page_ok(browser.get(setup), "the setup").form(name="vereinehookrun")), "deliver with the target off")
    expect(stack.value(f"SELECT status FROM llx_vereine_hook_delivery WHERE rowid = {delivery}") == "stopped",
           "a target that is switched off still delivered")
    right = int(stack.value("SELECT id FROM llx_rights_def WHERE module = 'vereine' AND perms = 'sync' AND subperms = 'read' AND entity = 1"))
    stack.sql(f"DELETE FROM llx_user_rights WHERE fk_user = {sync_user} AND fk_id = {right}")
    stack.sql(f"UPDATE llx_vereine_hook_target SET active = 1 WHERE rowid = {target}")
    page = page_ok(browser.get(setup), "the setup without the right")
    expect('data-hook-allowed="0"' in page.text, "the setup does not say that the right is gone")
    page_ok(browser.post(setup, [("token", token_of(page)), ("action", "retry"), ("delivery", str(delivery))]), "start the job again")
    page_ok(browser.submit(page_ok(browser.get(setup), "the setup").form(name="vereinehookrun")), "deliver without the right")
    expect(stack.value(f"SELECT status FROM llx_vereine_hook_delivery WHERE rowid = {delivery}") == "stopped",
           "a delivery went out although the right was taken away")
    stack.sql(f"INSERT INTO llx_user_rights (entity, fk_user, fk_id) VALUES (1, {sync_user}, {right})")

    # A rotation: the new secret signs, the old one is still accepted for a day.
    page = page_ok(browser.get(setup), "the setup before the rotation")
    page = page_ok(browser.post(setup, [("token", token_of(page)), ("action", "rotate"), ("target", str(target))]), "rotate the secret")
    rotated = re.search(r"<code>([0-9a-f]{64})</code>", page.text)
    expect(rotated is not None and rotated.group(1) != secret, "the rotation gave no new secret")
    new_secret, new_key = rotated.group(1), stack.value(f"SELECT key_id FROM llx_vereine_hook_target WHERE rowid = {target}")
    kept = stack.sql(f"SELECT next_key_id, rotate_until FROM llx_vereine_hook_target WHERE rowid = {target}")
    expect(kept[0][0] == key_id and kept[0][1] not in ("", "NULL", None), f"the old key is not kept for the change over: {kept}")

    # The reference receiver, answering real requests: a pipe would prove nothing about a web server.
    receiver = "/tmp/webhook-empfaenger.php"
    poster = "/tmp/hook_post.php"
    stack.run(stack.docker, "cp", str(OPENAPI.parent / "beispiele" / "webhook-empfaenger.php"), f"{stack.web}:{receiver}")
    stack.run(stack.docker, "cp", str(Path(__file__).resolve().parent / "hook_post.php"), f"{stack.web}:{poster}")
    stack.shell("rm -f /tmp/vereine-hook-seen.json /tmp/vereine-hook-log.json")
    rules = "/var/www/html/custom/vereine/class/vereinehookrules.class.php"
    # The body lives in a file, and both sides read that file: a byte that differs would be a test of
    # the test, not of the signature.
    stack.shell(f"php -r \"require '{rules}'; file_put_contents('/tmp/hook-body.json',"
                " VereineHookRules::body(array('event_id' => 'rt-1', 'object_type' => 'membership',"
                " 'object_id' => 7, 'revision' => 1, 'change' => 'updated', 'occurred_at' => '2026-09-23T14:05:11Z')));\"")
    stack.shell("php -r \"file_put_contents('/tmp/hook-body-bad.json', str_replace('7', '8',"
                " file_get_contents('/tmp/hook-body.json')));\"")
    now = int(stack.shell("php -r 'echo time();'").stdout.strip())
    secrets = json.dumps({new_key: new_secret, key_id: secret})
    # Detached, not backgrounded in a shell: when the exec session ends it would take the server with it.
    stack.run(stack.docker, "exec", "-d", "-u", "www-data", stack.web, "sh", "-c",
              f"VEREINE_HOOK_SECRETS='{secrets}' php -S 127.0.0.1:8099 {receiver} >/tmp/hook-server.log 2>&1", check=False)
    url = "http://127.0.0.1:8099/hook"
    for _ in range(20):
        if "no answer" not in stack.shell(f"php {poster} {url} /tmp/hook-body.json leer").stdout:
            break
        time.sleep(1)
    else:
        raise CheckFailed("the reference receiver never came up: " + stack.shell("cat /tmp/hook-server.log").stdout[:300])

    def header_for(when: int, with_secret: str, with_key: str, event: str = "rt-1") -> str:
        """The header the module itself would build, so the test never signs by its own rules."""
        return stack.shell(f"php -r \"require '{rules}'; echo VereineHookRules::header('{with_secret}', '{with_key}',"
                           f" file_get_contents('/tmp/hook-body.json'), {when}, '{event}');\"").stdout.strip()

    def receive(header: str, body_file: str = "/tmp/hook-body.json") -> str:
        """Send a body to the receiver over HTTP and give back what it answered."""
        return stack.shell(f"php {poster} {url} {body_file} '{header}'").stdout.strip()

    genuine = header_for(now, new_secret, new_key)
    first = receive(genuine)
    expect('"ok":true' in first, f"the reference receiver refused a genuine delivery: {first}")
    expect('"reason":"already seen"' in receive(genuine), "the reference receiver took the same event twice")
    tampered = receive(genuine, "/tmp/hook-body-bad.json")
    expect('"reason":"signature"' in tampered, f"changed bytes were not refused: {tampered}")
    wrong_key = receive(header_for(now, new_secret, "99999999", "rt-2"))
    expect('"reason":"key"' in wrong_key, f"an unknown key was not refused: {wrong_key}")
    expired = receive(header_for(now - 3600, new_secret, new_key, "rt-3"))
    expect('"reason":"expired"' in expired, f"an old header was not refused: {expired}")
    old_key_ok = receive(header_for(now, secret, key_id, "rt-4"))
    expect('"ok":true' in old_key_ok, f"the old key was refused during the rotation: {old_key_ok}")
    log = stack.shell("cat /tmp/vereine-hook-log.json").stdout
    expect(log.count('"event_id"') == 2, f"the receiver logged something it should have refused: {log[:200]}")
    stack.shell("pkill -f 'php -S 127.0.0.1:8099' || true")
    return ("only https targets without credentials and outside the network are taken; a change of a member became exactly one job, "
            "a failed attempt waits with a masked error, a repetition stays the same job and a retry keeps its name; a target that is "
            "switched off or whose right was taken away delivers nothing; after a rotation the new secret signs and the old one is "
            "still accepted; the reference receiver takes what is genuine, refuses changed bytes, an unknown key and an old header, "
            "and takes the same event only once")


def identities(stack: Stack) -> str:
    """Two independent clients on the same contract: an invitation binds, a foreign subject, a used code and a missing ability are refused (#153)."""
    browser = stack.browser()
    setup = "/custom/vereine/admin/identities.php"
    expect(denied(stack.browser("rtreader").get(setup)), "a non-administrator opens the identities")
    page = page_ok(browser.get(setup), "the identities")
    expect('data-identity-none="1"' in page.text, "there is already a binding")

    # Two clients that know nothing of each other, each with its own key and the right to act for people.
    right = int(stack.value("SELECT id FROM llx_rights_def WHERE module = 'vereine' AND perms = 'identity'"
                            " AND subperms = 'use' AND entity = 1") or 0)
    expect(right > 0, "enabling the module did not register the right to act for people")
    clients = {}
    for login in ("rtapp", "rtportal"):
        key = secrets.token_hex(16)
        stack.php_fixture("apiclient", RT_LOGIN=login, RT_CLIENT_KEY=key)
        clients[login] = key

    # Without a binding nothing works, however well the client is authenticated.
    status, refused = stack.api("vereine/identities/me?subject=sub-unbekannt", clients["rtapp"])
    expect(status == 403, f"an unknown subject answered HTTP {status}")

    # An invitation from the association binds one person at one client.
    member = int(stack.value("SELECT rowid FROM llx_adherent WHERE statut = 1 ORDER BY rowid LIMIT 1"))
    page = page_ok(browser.get(setup), "the identities before the invitation")
    page = page_ok(browser.submit(page.form(name="vereineidentityinvite"), {
        "client": "rtapp", "member_id": str(member), "application_id": "0", "capabilities[]": "consents"}),
        "invite the member")
    code = re.search(r"<code>([A-Za-z0-9_-]{30,})</code>", page.text)
    expect(code is not None and 'data-identity-code="1"' in page.text, "the invitation showed no code")
    code = code.group(1)
    expect(code not in page_ok(browser.get(setup), "the identities again").text, "the code is shown a second time")

    # The other client cannot use that invitation, however it asks.
    status, wrong = stack.api(f"vereine/identities/claim?subject=sub-portal&code={code}", clients["rtportal"],
                              method="POST")
    expect(status == 403, f"another client used the invitation: HTTP {status}")
    status, claimed = stack.api(f"vereine/identities/claim?subject=sub-app-1&code={code}", clients["rtapp"], method="POST")
    expect(status == 200 and claimed["member_id"] == member, f"the invitation did not bind: HTTP {status}, {claimed}")
    expect(claimed["capabilities"] == ["consents"] and claimed["application_id"] is None,
           f"the binding carries the wrong abilities: {claimed}")

    # A code works once.
    status, again = stack.api(f"vereine/identities/claim?subject=sub-app-2&code={code}", clients["rtapp"], method="POST")
    expect(status == 403, f"the same code bound a second person: HTTP {status}")

    # What the binding opens, and what it does not.
    status, mine = stack.api("vereine/identities/me?subject=sub-app-1", clients["rtapp"])
    expect(status == 200 and mine["member_id"] == member, f"the binding cannot read itself: {mine}")
    status, consents = stack.api("vereine/me/consents?subject=sub-app-1", clients["rtapp"])
    expect(status == 200, f"the member cannot read their own consents: HTTP {status}")
    status, refused = stack.api("vereine/me/application?subject=sub-app-1", clients["rtapp"])
    expect(status == 403, f"a member binding opened an application: HTTP {status}")

    # The same subject at the other client is a different person, and that client sees nothing.
    status, foreign = stack.api("vereine/identities/me?subject=sub-app-1", clients["rtportal"])
    expect(status == 403, f"the binding of one client worked at another: HTTP {status}")
    status, foreign = stack.api("vereine/me/consents?subject=sub-app-1", clients["rtportal"])
    expect(status == 403, f"another client read the consents of a stranger: HTTP {status}")

    # An applicant: bound to their application, and to nothing else.
    application = int(stack.value("SELECT rowid FROM llx_vereine_application ORDER BY rowid LIMIT 1") or 0)
    expect(application > 0, "there is no application to bind an applicant to")
    page = page_ok(browser.get(setup), "the identities before the applicant")
    page = page_ok(browser.submit(page.form(name="vereineidentityinvite"), {
        "client": "rtportal", "member_id": "0", "application_id": str(application), "capabilities[]": "applications"}),
        "invite the applicant")
    applicant_code = re.search(r"<code>([A-Za-z0-9_-]{30,})</code>", page.text).group(1)
    status, bound = stack.api(f"vereine/identities/claim?subject=sub-antrag&code={applicant_code}", clients["rtportal"],
                              method="POST")
    expect(status == 200 and bound["member_id"] is None and bound["application_id"] == application,
           f"the applicant was bound to a member: {bound}")
    status, own = stack.api("vereine/me/application?subject=sub-antrag", clients["rtportal"])
    expect(status == 200 and own["application_id"] == application, f"the applicant cannot read their own application: {own}")
    status, refused = stack.api("vereine/me/consents?subject=sub-antrag", clients["rtportal"])
    expect(status == 403, f"an applicant read a member's consents: HTTP {status}")

    # An address finds candidates, never a binding.
    email = stack.value("SELECT email FROM llx_adherent WHERE COALESCE(email, '') <> '' ORDER BY rowid LIMIT 1")
    expect(email not in ("", "NULL", None), "no member has an address to search for")
    page = page_ok(browser.get(setup), "the identities before the search")
    found = page_ok(browser.submit(page.form(name="vereineidentitysearch"), {"email": email, "ref": ""}), "search by address")
    expect('data-identity-candidate=' in found.text, "the search found nobody to look at")
    expect(int(stack.value("SELECT COUNT(*) FROM llx_vereine_identity") or 0) == 2,
           "the search made a binding out of a hit")

    # Taking a binding back stops what it allowed, at once.
    identity = int(stack.value("SELECT rowid FROM llx_vereine_identity WHERE subject = 'sub-app-1'"))
    page = page_ok(browser.get(setup), "the identities before the revocation")
    page_ok(browser.post(setup, [("token", token_of(page)), ("action", "revoke"), ("identity", str(identity))]), "revoke")
    status, after = stack.api("vereine/me/consents?subject=sub-app-1", clients["rtapp"])
    expect(status == 403, f"a revoked binding still read consents: HTTP {status}")

    # The service access of API v1 is untouched and still knows nothing about people.
    status, summary = stack.api("vereine/members?limit=1", stack.notes["website"]["key"])
    expect(status == 200, f"the website key lost its own access: HTTP {status}")
    status, refused = stack.api("vereine/identities/me?subject=sub-app-1", stack.notes["website"]["key"])
    expect(status == 403, f"the website key acted for a person: HTTP {status}")
    return ("two clients on the same contract without a special case; an invitation binds one member at one client, "
            "the other client cannot use it and the code works once; a member binding opens the consents and not the "
            "application, an applicant binding opens the application and no member data; an address finds candidates "
            "without binding anybody; a revocation stops the access at once, and the service access of API v1 stays "
            "what it was")


def applicationfields(stack: Stack) -> str:
    """The fields of an application: one list for the PDF and the web, own fields with their switch, the fee in real numbers (#216)."""
    browser = stack.browser()
    setup = "/custom/vereine/admin/application.php"
    key = stack.notes["applicationkey"]
    stack.php_fixture("memberextra")

    # The name stands there, ticked and fixed; the own field can be put on the form as required.
    page = page_ok(browser.get(setup), "application setup")
    expect('data-always="lastname"' in page.text and 'data-always="firstname"' in page.text,
           "the setup does not show that the name is always required")
    expect('data-extra-field="gamertag" data-extra-state="off"' in page.text, "the own field of the member is not offered")
    form = page.form(name="vereineapplicationsetup")
    fields = [(name, value) for name, value in form.values() if name != "required[]" and not name.startswith("extra[")]
    fields += [("required[]", field) for field in ("address", "zip", "town", "email")]
    fields += [("extra[gamertag]", "required")]
    page_ok(browser.post(form.url(), fields), "put the gamer tag on the form as required")
    expect(stack.const("VEREINE_APPLICATION_EXTRAFIELDS") == '{"gamertag":true}',
           f"the own field was not stored: {stack.const('VEREINE_APPLICATION_EXTRAFIELDS')}")
    expect(stack.const("VEREINE_APPLICATION_REQUIRED") == "address,zip,town,email",
           f"the required fields were not stored: {stack.const('VEREINE_APPLICATION_REQUIRED')}")

    # A website asks what the form asks for.
    status, form_fields = stack.api("vereine/applicationform", key)
    expect(status == 200 and form_fields["required"] == ["lastname", "firstname", "address", "zip", "town", "email"],
           f"the web is told other required fields: HTTP {status}, {form_fields}")
    expect(form_fields["fields"] == [{"code": "gamertag", "label": "Gamertag", "required": True, "type": "text", "max_length": 255}],
           f"the web is told other own fields: {form_fields['fields']}")

    # The web follows the same list as the PDF.
    type_id = int(stack.value("SELECT rowid FROM llx_adherent_type WHERE libelle = 'Beitragspflichtig'"))
    body = {"firstname": "Gina", "lastname": "Gamer", "email": "gina.gamer@runtime-verein.test", "type_id": type_id,
            "birth": "1999-05-05", "address": "Teststraße 3", "zip": "6410", "town": "Telfs"}
    for change, message in (({}, "fields.gamertag is required"), ({"address": ""}, "address is required"),
                            ({"fields": {"gamertag": "Gina", "passwort": "x"}}, "fields.passwort is not a field")):
        status, answer = stack.api("vereine/applications", key, method="POST", data={**body, **change})
        expect(status == 400 and message in json.dumps(answer), f"application with {change or 'no gamer tag'}: HTTP {status} {answer}")
    expect(stack.value("SELECT COUNT(*) FROM llx_adherent WHERE lastname = 'Gamer'") == "0", "a refused application created a member")
    status, created = stack.api("vereine/applications", key, method="POST", data={**body, "fields": {"gamertag": " GinaTheLion "}})
    expect(status == 200, f"a complete application was refused: HTTP {status} {created}")
    member = int(stack.value("SELECT rowid FROM llx_adherent WHERE lastname = 'Gamer'"))
    tag = stack.value(f"SELECT gamertag FROM llx_adherent_extrafields WHERE fk_object = {member}")
    expect(tag == "GinaTheLion", f"the gamer tag did not reach the member: {tag!r}")

    # A field made right in the setup: a choice, required; Dolibarr has it, the web learns its options (#226).
    page = page_ok(browser.get(setup), "the setup before a new field")
    page_ok(browser.submit(page.form(name="vereineapplicationnewfield"), {"field_label": "Spielstärke", "field_kind": "select",
                                                                          "field_options": "Anfänger\nFortgeschritten\nProfi", "field_state": "required"}),
            "a new choice on the form")
    made = stack.sql("SELECT type, elementtype FROM llx_extrafields WHERE name = 'spielstaerke'")
    expect(made == [["select", "adherent"]], f"the new field is no choice of the member in Dolibarr: {made}")
    expect(json.loads(stack.const("VEREINE_APPLICATION_EXTRAFIELDS") or "{}").get("spielstaerke") is True, "the new field is not on the form as required")
    status, form_fields = stack.api("vereine/applicationform", key)
    choice = next((field for field in form_fields.get("fields", []) if field["code"] == "spielstaerke"), None)
    expect(choice is not None and choice["type"] == "select" and choice["required"] is True
           and choice.get("options") == [{"code": "anfaenger", "label": "Anfänger"}, {"code": "fortgeschritten", "label": "Fortgeschritten"},
                                         {"code": "profi", "label": "Profi"}], f"the web is told another choice: {choice}")
    level = {**body, "firstname": "Lena", "lastname": "Level", "email": "lena.level@runtime-verein.test"}
    status, answer = stack.api("vereine/applications", key, method="POST", data={**level, "fields": {"gamertag": "Lena", "spielstaerke": "meister"}})
    expect(status == 400 and "fields.spielstaerke must be one of" in json.dumps(answer), f"a wrong option was taken: HTTP {status} {answer}")
    status, created = stack.api("vereine/applications", key, method="POST", data={**level, "fields": {"gamertag": "Lena", "spielstaerke": "profi"}})
    expect(status == 200, f"an application with a right option was refused: HTTP {status} {created}")
    chosen = stack.value("SELECT e.spielstaerke FROM llx_adherent_extrafields as e INNER JOIN llx_adherent as a ON a.rowid = e.fk_object WHERE a.lastname = 'Level'")
    expect(chosen == "profi", f"the choice did not reach the member: {chosen!r}")

    # The printed form: the own field, the fee in real numbers, one month in the singular.
    before = stack.sql(f"SELECT amount, duration FROM llx_adherent_type WHERE rowid = {type_id}")[0]
    extra_row = stack.sql(f"SELECT vereine_fee_start_month, vereine_fee_proration FROM llx_adherent_type_extrafields WHERE fk_object = {type_id}")
    stack.sql(f"UPDATE llx_adherent_type SET amount = 75, duration = '1y' WHERE rowid = {type_id}")
    if extra_row:
        stack.sql(f"UPDATE llx_adherent_type_extrafields SET vereine_fee_start_month = 1, vereine_fee_proration = 'half_year' WHERE fk_object = {type_id}")
    else:
        stack.sql(f"INSERT INTO llx_adherent_type_extrafields (fk_object, vereine_fee_start_month, vereine_fee_proration) VALUES ({type_id}, 1, 'half_year')")
    try:
        page = page_ok(browser.get("/custom/vereine/application.php"), "the blank applications")
        page_ok(browser.submit(page.form(name=f"vereineapplication{type_id}")), "build the blank application")
        blank = pdf_text(stack, "vereine/application")
        expect("Gamertag" in blank, "the own field is missing on the printed form")
        expect("Spielst" in blank and "Anfänger" in blank and "Fortgeschritten" in blank and "Profi" in blank,
               "the choice made in the setup is not on the printed form with its options")
        expect("halbjahresweise" not in blank, "the form still says the fee is prorated by half-year in words")
        expect("75,00" in blank and "37,50" in blank, "the form does not name the fee of both half-years")
        expect("1 Monate" not in blank, "the form still says 1 Monate")
    finally:
        stack.sql(f"UPDATE llx_adherent_type SET amount = {before[0] if before[0] not in ('', 'NULL', None) else 'NULL'},"
                  f" duration = '{before[1]}' WHERE rowid = {type_id}")
        if extra_row:
            month = extra_row[0][0] if extra_row[0][0] not in ("", "NULL", None) else "NULL"
            proration = f"'{extra_row[0][1]}'" if extra_row[0][1] not in ("", "NULL", None) else "NULL"
            stack.sql(f"UPDATE llx_adherent_type_extrafields SET vereine_fee_start_month = {month},"
                      f" vereine_fee_proration = {proration} WHERE fk_object = {type_id}")
        else:
            stack.sql(f"DELETE FROM llx_adherent_type_extrafields WHERE fk_object = {type_id}")
    return ("the name stands fixed and ticked, the gamer tag goes on the form as required; a choice made in the setup became a field of "
            "the member in Dolibarr, the web learned its options, a wrong option was refused and a right one reached the member; "
            "the web is told exactly those fields, "
            "refuses an application without the gamer tag, without a street or with a field the form does not know, and stores "
            "the gamer tag at the member; the printed form carries the own field, 75,00 and 37,50 for the two half-years and no "
            "\"1 Monate\"")


def volunteers(stack: Stack) -> str:
    """Volunteer allowances: over the day marked when stored, the list of the year matches, a confirmed shift is paid once (#7)."""
    browser = stack.browser()
    year = int(stack.today()[:4])
    base = f"/custom/vereine/volunteer.php?year={year}"
    expect(denied(stack.browser("rtnobody").get(base)), "a user without rights opens the volunteer allowances")
    page = page_ok(browser.get(base), "the volunteer allowances")
    expect('data-volunteer-limit="small"' in page.text and 'data-volunteer-limit="prae"' in page.text,
           "the page does not name the limits")

    members = [int(row[0]) for row in stack.sql("SELECT rowid FROM llx_adherent WHERE statut = 1 ORDER BY rowid LIMIT 2")]
    first, second = members[0], members[1]

    def record(member: int, day: str, kind: str, amount: str, activity: str = "Kassa beim Turnier") -> Page:
        form = page_ok(browser.get(base), "the page before recording").form(name="vereinevolunteer")
        return page_ok(browser.submit(form, {"member_id": str(member), "day": day, "activity": activity, "kind": kind,
                                             "amount": amount, "note": ""}), f"record {amount} {kind} on {day}")

    # Within the limit, then over it on the same day: the second entry is marked the moment it is stored.
    record(first, f"{year}-03-14", "small", "20")
    after = record(first, f"{year}-03-14", "small", "15")
    expect("Tagesgrenze" in html.unescape(after.text), "going over the day was not said when it was stored")
    flags = re.findall(r'data-volunteer-flags="([a-z_ ]*)"', page_ok(browser.get(base), "the entries").text)
    expect(any("over_day" in value for value in flags), f"no entry is marked over the day: {flags}")

    # PRAE and an allowance for one person in a year: marked as a case to check.
    record(first, f"{year}-04-02", "prae", "60", "Fahrt zum Auswärtsturnier")
    page = page_ok(browser.get(base), "the list of the year")
    person = re.search(rf'data-volunteer-person="{first}" data-volunteer-total="([\d.]+)" data-volunteer-findings="([a-z_ ]*)"', page.text)
    expect(person is not None and float(person.group(1)) == 95.0, f"the list of the year does not add up for member {first}: {person}")
    expect("mixed" in person.group(2), f"PRAE and an allowance in one year were not pointed out: {person.group(2)}")
    stored = stack.value(f"SELECT SUM(amount) FROM llx_vereine_volunteer WHERE fk_adherent = {first} AND YEAR(duty_day) = {year}")
    expect(abs(float(stored) - 95.0) < 0.001, f"the table holds {stored}, the list says 95")

    # Refused: an amount of nothing, and a day that does not exist.
    refused = record(second, f"{year}-02-30", "small", "0")
    expect("Betrag" in html.unescape(refused.text) and "Tag" in html.unescape(refused.text), "a wrong entry was not refused with an explanation")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_vereine_volunteer WHERE fk_adherent = {second}") == "0", "a refused entry was stored")

    # A confirmed helper shift from the events is offered once and paid once.
    shift_entry = stack.value("SELECT rowid FROM llx_vereine_event_shift_entry WHERE status = 'done' ORDER BY rowid LIMIT 1")
    if shift_entry not in (None, "", "NULL"):
        shift_year = stack.value("SELECT YEAR(s.shift_day) FROM llx_vereine_event_shift_entry as e INNER JOIN llx_vereine_event_shift as s"
                                 f" ON s.rowid = e.fk_shift WHERE e.rowid = {shift_entry}")
        shift_page = f"/custom/vereine/volunteer.php?year={shift_year}"
        page = page_ok(browser.get(shift_page), "the page with the open shifts")
        expect(f'data-volunteer-shift="{shift_entry}"' in page.text, "a confirmed helper shift is not offered for an allowance")
        form = page.form(name=f"vereinevolunteershift{shift_entry}")
        page_ok(browser.submit(form, {"kind": "small", "amount": "25"}), "record the helper shift")
        expect(stack.value(f"SELECT COUNT(*) FROM llx_vereine_volunteer WHERE fk_shift_entry = {shift_entry}") == "1",
               "the helper shift was not recorded")
        page = page_ok(browser.get(shift_page), "the page after recording the shift")
        expect(f'data-volunteer-shift="{shift_entry}"' not in page.text, "a paid helper shift is offered a second time")

    # The list for the reports.
    page = page_ok(browser.get(base), "the page for the list")
    link = re.search(r'href="([^"]*action=csv[^"]*)"', page.text)
    expect(link is not None, "the page offers no list for the reports")
    csv = browser.get(html.unescape(link.group(1)))
    text = csv.body.decode("utf-8-sig")
    expect(csv.status == 200 and text.startswith("Mitglied;Name;Einsatztage") and "95" not in text.split("\n")[0],
           f"the list for the reports is not what it should be: {text[:120]!r}")
    small = float(stack.value(f"SELECT COALESCE(SUM(amount), 0) FROM llx_vereine_volunteer WHERE fk_adherent = {first}"
                              f" AND kind = 'small' AND YEAR(duty_day) = {year}"))
    line = next((row for row in text.splitlines() if row.startswith(f"{first};")), "")
    expect(f"{small:.2f}".replace(".", ",") in line, f"the list says {line!r}, the table holds {small:.2f} for member {first}")
    return ("an allowance over the day was marked the moment it was stored, PRAE and an allowance in one year were pointed out, "
            "the list of the year adds up to what the table holds, a wrong entry was refused, a confirmed helper shift was offered "
            "once and paid once, and the list for the reports comes as CSV")


def volunteerpayout(stack: Stack) -> str:
    """Paying volunteer allowances: a list, refused until the chair and the treasurer signed, then Dolibarr's various payments (#7)."""
    browser = stack.browser()
    year = int(stack.today()[:4])
    base = f"/custom/vereine/volunteer.php?year={year}"
    page = page_ok(browser.get(base), "the allowances before the payout")
    expect('data-volunteer-payable="' in page.text, "no open entry is offered for a payout")
    open_before = int(stack.value(f"SELECT COUNT(*) FROM llx_vereine_volunteer WHERE fk_payout IS NULL AND paid_on IS NULL AND YEAR(duty_day) = {year}"))
    page_ok(browser.submit(page.form(name="vereinevolunteerpayout")), "make the payout list")
    payout = int(stack.value("SELECT MAX(rowid) FROM llx_vereine_volunteer_payout"))
    on_list = int(stack.value(f"SELECT COUNT(*) FROM llx_vereine_volunteer WHERE fk_payout = {payout}"))
    expect(on_list == open_before, f"{on_list} entries on the list, {open_before} were open")
    total = float(stack.value(f"SELECT total FROM llx_vereine_volunteer_payout WHERE rowid = {payout}"))
    summed = float(stack.value(f"SELECT SUM(amount) FROM llx_vereine_volunteer WHERE fk_payout = {payout}"))
    expect(abs(total - summed) < 0.001, f"the list says {total}, its entries add up to {summed}")

    # Nothing goes out before the signatures.
    page = page_ok(browser.get(base), "the payout waiting")
    expect(f'data-volunteer-payout="{payout}" data-volunteer-payout-status="draft"' in page.text
           and 'data-volunteer-waits-signature="1"' in page.text, "the list does not wait for its signatures")
    account = stack.value("SELECT rowid FROM llx_bank_account WHERE clos = 0 ORDER BY rowid LIMIT 1")
    mode = stack.value("SELECT id FROM llx_c_paiement WHERE code = 'VIR' AND active = 1 LIMIT 1")
    refused = page_ok(browser.post(base, [("token", token_of(page)), ("action", "paypayout"), ("payout", str(payout)),
                                          ("bank_account", str(account)), ("payment_mode", str(mode)), ("pay_day", stack.today())]),
                      "pay before the signatures")
    expect("nicht vollständig unterschrieben" in html.unescape(refused.text), "paying before the signatures was not refused")
    expect(stack.value("SELECT COUNT(*) FROM llx_payment_various WHERE label LIKE 'Freiwilligenpauschale%'") == "0",
           "money went out before the signatures")

    # The chair and the treasurer sign, each for themselves.
    page = page_ok(browser.get(base), "the payout before signing")
    cancelled = stack.value("SELECT COUNT(*) FROM llx_vereine_signature WHERE kind <> 'payout' AND status = 'cancelled'")
    page_ok(browser.submit(page.form(name=f"vereinestartsignpayout{payout}")), "ask for the signatures")
    run = int(stack.value(f"SELECT rowid FROM llx_vereine_signature WHERE kind = 'payout' AND fk_object = {payout} ORDER BY rowid DESC LIMIT 1"))
    # Its own kind: asking for the payout's signatures leaves a resolution with the same number alone.
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_signature WHERE kind <> 'payout' AND status = 'cancelled'") == cancelled,
           "asking for the payout's signatures cancelled the run of another document")
    signers = [int(row[0]) for row in stack.sql(f"SELECT fk_adherent FROM llx_vereine_signature_person WHERE fk_signature = {run}")]
    expect(len(signers) >= 2, f"a money matter should need the chair and the treasurer: {signers}")
    linked = stack.value("SELECT fk_member FROM llx_user WHERE login = 'admin'")
    try:
        for signer in signers:
            stack.sql(f"UPDATE llx_user SET fk_member = {signer} WHERE login = 'admin'")
            page = page_ok(browser.get(base), f"the payout for member {signer}")
            page_ok(browser.post(base, [("token", token_of(page)), ("action", "sign"), ("signature", str(run)),
                                        ("password", stack.admin_password)]), f"member {signer} signs")
    finally:
        stack.sql(f"UPDATE llx_user SET fk_member = {linked if linked not in (None, '', 'NULL') else 'NULL'} WHERE login = 'admin'")
    expect(stack.value(f"SELECT status FROM llx_vereine_signature WHERE rowid = {run}") == "done", "the list is not signed through")

    # Now it goes out: one of Dolibarr's various payments per person, and the bank sees it.
    page = page_ok(browser.get(base), "the payout ready to pay")
    expect(f'data-volunteer-payout-payable="1"' in page.text, "a signed list cannot be paid")
    people = int(stack.value(f"SELECT COUNT(DISTINCT fk_adherent) FROM llx_vereine_volunteer WHERE fk_payout = {payout}"))
    page_ok(browser.submit(page.form(name=f"vereinevolunteerpay{payout}"), {"bank_account": str(account), "payment_mode": str(mode)}),
            "pay the list")
    payments = stack.sql("SELECT rowid, amount, fk_bank FROM llx_payment_various WHERE label LIKE 'Freiwilligenpauschale%'")
    expect(len(payments) == people, f"{len(payments)} payments for {people} people")
    expect(all(row[2] not in (None, "", "NULL", "0") for row in payments), "a payment has no bank line")
    paid_total = sum(float(row[1]) for row in payments)
    expect(abs(paid_total - total) < 0.001, f"paid {paid_total}, the list says {total}")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_vereine_volunteer WHERE fk_payout = {payout} AND paid_on IS NULL") == "0",
           "an entry of the paid list is not marked as paid")
    expect(stack.value(f"SELECT status FROM llx_vereine_volunteer_payout WHERE rowid = {payout}") == "paid", "the list is not marked as paid")

    # A paid entry stays: it belongs to the books.
    entry = stack.value(f"SELECT rowid FROM llx_vereine_volunteer WHERE fk_payout = {payout} LIMIT 1")
    page = page_ok(browser.get(base), "the paid list")
    refused = page_ok(browser.post(base, [("token", token_of(page)), ("action", "remove"), ("entry", str(entry))]), "remove a paid entry")
    expect("Buchhaltung" in html.unescape(refused.text), "removing a paid entry was not refused")
    return (f"{on_list} entries went on a list of {total:.2f} euros; paying was refused until the chair and the treasurer had signed; "
            f"then {len(payments)} of Dolibarr's various payments with their bank lines went out, the entries and the list are "
            "marked as paid, and a paid entry cannot be removed")


def overpayments(stack: Stack) -> str:
    """Overpayments: 37,68 paid with 38,00, the 0,32 assigned once to a credit, a refund or a donation (#54)."""
    base = "/custom/vereine/overpayments.php"
    expect(denied(stack.browser("rtnobody").get(base)), "a user without rights opens the overpayments")
    invoices = stack.php_fixture("overpaid")["invoices"]
    browser = stack.browser()
    account = stack.value("SELECT rowid FROM llx_bank_account WHERE clos = 0 ORDER BY rowid LIMIT 1")
    mode = stack.value("SELECT id FROM llx_c_paiement WHERE code = 'VIR' AND active = 1 LIMIT 1")

    # The invoice card asks; Dolibarr's own button converts one excess itself, and the module sees that.
    converted = invoices["dolibarr"]["invoice"]
    card = f"/compta/facture/card.php?facid={converted}"
    page = page_ok(browser.get(card), "the card of an overpaid invoice")
    expect('data-overpayment-hint="0.32"' in page.text, "the invoice card does not ask where the 0,32 go")
    page_ok(browser.post(card, [("token", token_of(page)), ("action", "confirm_converttoreduc"), ("confirm", "yes"), ("facid", str(converted))]),
            "Dolibarr's own conversion")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_societe_remise_except WHERE fk_facture_source = {converted}") == "1",
           "Dolibarr's own button made no credit")

    page = page_ok(browser.get(base + "?show=all"), "the overpayments")
    found = {invoice: (amount, state) for invoice, amount, state
             in re.findall(r'data-overpayment="(\d+)" data-overpayment-amount="([\d.]+)" data-overpayment-state="([a-z]+)"', page.text)}
    for key in ("credit", "refund", "donation"):
        expect(found.get(str(invoices[key]["invoice"])) == ("0.32", "open"), f"{key}: {found.get(str(invoices[key]['invoice']))}")
    expect(found.get(str(converted)) == ("0.32", "dolibarr"), f"the invoice Dolibarr converted: {found.get(str(converted))}")

    # A credit: Dolibarr's own discount of exactly 0,32; the invoice stays at 37,68 and is paid.
    credit = invoices["credit"]["invoice"]
    page = page_ok(browser.get(f"{base}?invoice={credit}"), "the three ways for one invoice")
    expect(all(f'data-overpayment-preview="{kind}"' in page.text for kind in ("credit", "refund", "donation"))
           and "0,32" in html.unescape(page.text), "the ways are not shown with what Dolibarr makes of them")
    page_ok(browser.submit(page.form(name="vereineoverpaymentcredit")), "keep the excess as a credit")
    discount = stack.sql(f"SELECT amount_ttc, description FROM llx_societe_remise_except WHERE fk_facture_source = {credit}")
    expect(len(discount) == 1 and abs(float(discount[0][0]) - 0.32) < 0.001 and discount[0][1] == "(EXCESS RECEIVED)", f"the credit: {discount}")
    invoice = stack.sql(f"SELECT total_ttc, paye FROM llx_facture WHERE rowid = {credit}")[0]
    expect(abs(float(invoice[0]) - 37.68) < 0.001 and invoice[1] == "1", f"the invoice after the credit: {invoice}")

    # Once only: a second way for the same excess is refused, and nothing is made.
    page = page_ok(browser.get(f"{base}?invoice={credit}"), "the assigned excess")
    expect("data-overpayment-way=" not in page.text and 'data-overpayment-state="credit"' in page.text, "an assigned excess still offers a way")
    various = stack.value("SELECT COUNT(*) FROM llx_payment_various")
    refused = page_ok(browser.post(f"{base}?invoice={credit}", [("token", token_of(page)), ("action", "assign"), ("kind", "refund"),
                                                                ("bank_account", account), ("payment_mode", mode), ("pay_day", stack.today())]),
                      "assign the same excess a second time")
    expect("schon zugeordnet" in html.unescape(refused.text), "a second assignment was not refused")
    expect(stack.value("SELECT COUNT(*) FROM llx_payment_various") == various, "a second assignment made a payment")

    # A refund: a various payment of 0,32 leaving the bank; the invoice stays at 37,68.
    refund = invoices["refund"]["invoice"]
    page = page_ok(browser.get(f"{base}?invoice={refund}"), "the refund")
    page_ok(browser.submit(page.form(name="vereineoverpaymentrefund"), {"bank_account": account, "payment_mode": mode}), "refund the excess")
    payment = stack.sql("SELECT p.amount, p.sens, p.fk_bank FROM llx_payment_various as p INNER JOIN llx_vereine_overpayment as o"
                        f" ON o.fk_payment_various = p.rowid WHERE o.fk_facture = {refund}")
    expect(len(payment) == 1 and abs(float(payment[0][0]) - 0.32) < 0.001 and payment[0][1] == "0"
           and payment[0][2] not in (None, "", "NULL", "0"), f"the refund: {payment}")
    invoice = stack.sql(f"SELECT total_ttc, paye FROM llx_facture WHERE rowid = {refund}")[0]
    expect(abs(float(invoice[0]) - 37.68) < 0.001 and invoice[1] == "1", f"the invoice after the refund: {invoice}")
    # Dolibarr's own button cannot convert the refunded excess either.
    card = f"/compta/facture/card.php?facid={refund}"
    page = page_ok(browser.get(card), "the card of the refunded invoice")
    expect('data-overpayment-done="refund"' in page.text, "the card does not say where the excess went")
    page_ok(browser.post(card, [("token", token_of(page)), ("action", "confirm_converttoreduc"), ("confirm", "yes"), ("facid", str(refund))]),
            "Dolibarr's button on a refunded excess")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_societe_remise_except WHERE fk_facture_source = {refund}") == "0",
           "Dolibarr converted an excess that went back")

    # A donation: only when it was given freely, and 0,32 - not the 38,00 of the payment.
    donation = invoices["donation"]["invoice"]
    page = page_ok(browser.get(f"{base}?invoice={donation}"), "the donation")
    refused = page_ok(browser.submit(page.form(name="vereineoverpaymentdonation")), "a donation without the word that it was given freely")
    expect("freiwillig" in html.unescape(refused.text)
           and stack.value(f"SELECT COUNT(*) FROM llx_vereine_overpayment WHERE fk_facture = {donation}") == "0",
           "a donation without the confirmation was not refused")
    page = page_ok(browser.get(f"{base}?invoice={donation}"), "the donation again")
    page_ok(browser.submit(page.form(name="vereineoverpaymentdonation"), {"given_freely": "yes"}), "take the excess as a donation")
    don = stack.sql("SELECT d.amount, d.fk_statut, d.fk_soc FROM llx_don as d INNER JOIN llx_vereine_overpayment as o ON o.fk_don = d.rowid"
                    f" WHERE o.fk_facture = {donation}")
    expect(len(don) == 1 and abs(float(don[0][0]) - 0.32) < 0.001 and don[0][1] == "2", f"the donation: {don}")
    expect(stack.value("SELECT COUNT(*) FROM llx_element_element WHERE sourcetype = 'facture' AND targettype = 'don'"
                       f" AND fk_source = {donation}") == "1", "the donation is not linked to its invoice")

    # The account: the 0,32 count as a donation in the ideal area, the rest stays with the invoice.
    year = stack.today()[:4]
    page = page_ok(browser.get(f"/custom/vereine/account.php?year={year}"), "the account after the donation")
    parts = re.search(rf'data-booking="{invoices["donation"]["line"]}" data-kind="invoice" data-parts="([^"]*)"', page.text)
    shares = dict(part.split("=") for part in parts.group(1).split(";") if part) if parts else {}
    expect(shares.get("ideal") == "0.32" and abs(sum(float(value) for value in shares.values()) - 38.0) < 0.001,
           f"the payment with the donation in the account: {shares}")
    page = page_ok(browser.get(base), "the open overpayments")
    left = re.search(r'data-overpayment-open="(\d+)"', page.text)
    expect(left is not None and all(f'data-overpayment="{invoices[key]["invoice"]}"' not in page.text for key in invoices),
           "an assigned excess is still listed as open")
    return ("37,68 paid with 38,00 showed 0,32 on the card and in the list; Dolibarr's own conversion counted as assigned; "
            "a credit became Dolibarr's discount of 0,32, a refund a various payment of 0,32 with its bank line, a donation of 0,32 "
            "only after the confirmation and linked to the invoice, in the account as ideal; every invoice stayed at 37,68, "
            "and neither the module nor Dolibarr's button assigned an excess twice")


def donations(stack: Stack) -> str:
    """The donation report: date of birth encrypted, vbPK through the register file, XML against the schema, protocol E then A (#6)."""
    year = int(stack.today()[:4]) - 1
    base = f"/custom/vereine/donations.php?year={year}"
    expect(denied(stack.browser("rtnobody").get(base)), "a user without rights opens the donation report")
    expect(denied(stack.browser("rtreader").get(base)), "a reader of the association sees dates of birth of donors")
    stack.php_fixture("donors", RT_YEAR=str(year))
    browser = stack.browser()

    setup = page_ok(browser.get("/custom/vereine/admin/donations.php"), "the setup of the donation report")
    page_ok(browser.submit(setup.form(name="vereinedonationsetup"), {"kind": "SP", "contact": "Kassier 0664 000", "email": "kassa@example.org"}),
            "a sports club")
    expect(stack.const("VEREINE_DONATION_KIND") == "SP", "the kind of body was not stored")

    page = page_ok(browser.get(base), "the donors of last year")
    people = {ref: (total, nxt) for ref, total, nxt in re.findall(r'data-donation-ref="([^"]+)" data-donation-total="([\d.]+)" data-donation-next="([EAS]?)"', page.text)}
    customer = stack.value("SELECT d.refnr FROM llx_vereine_donor as d INNER JOIN llx_societe as s ON s.rowid = d.fk_soc WHERE s.nom = 'Rechnung Kunde'")
    erika = stack.value("SELECT rowid FROM llx_vereine_donor WHERE lastname = 'Beispiel' AND firstname = 'Erika'")
    expect(people.get(customer) == ("75.00", "") and len(people) >= 3, f"two donations of one third party are one donor of 75: {people}")

    # Erika gives her date of birth and gets her own reference number; it is stored encrypted.
    page = browser.get(f"{base}&person={erika}")
    expect(page.status == 200 and not page.denied(),
           f"Erika's data (donor {erika!r}): HTTP {page.status} {html.unescape(re.sub(r'<[^>]+>', ' ', page.text))[:600]}")
    page_ok(browser.submit(page.form(name="vereinedonationdonor"), {"birth": "1980-05-12", "refnr": "SP-1", "given_on": stack.today()}), "Erika's date of birth")
    stored = stack.value(f"SELECT birth FROM llx_vereine_donor WHERE rowid = {erika}") or ""
    expect(stored.startswith("dolcrypt:") and "1980" not in stored, f"the date of birth is not stored encrypted: {stored[:20]}")
    page = page_ok(browser.get(f"{base}&person={erika}"), "Erika's data again")
    expect('value="1980-05-12"' in page.text, "the date of birth does not come back for whoever may see it")
    max_id = stack.value(f"SELECT rowid FROM llx_vereine_donor WHERE refnr = '{customer}'")
    page = page_ok(browser.get(f"{base}&person={max_id}"), "the customer's data")
    page_ok(browser.submit(page.form(name="vereinedonationdonor"), {"birth": "1975-01-02"}), "the customer's date of birth")

    # The register file holds both people with their dates of birth, never the company.
    export = browser.get(f"{base}&action=szrexport&token={token_of(page)}")
    expect(export.status == 200 and "VERSCHLÜSSELTEBPK=BMF+SA" in export.text and "\r\nSP-1;Beispiel;Erika;1980-05-12;" in export.text
           and f"\r\n{customer};Kunde;Max;1975-01-02;" in export.text and "GmbH" not in export.text, f"the register file: {export.text[:600]}")

    # The register answers: Erika found, the customer not.
    vbpk = "Ab3+" * 43
    found = ("KONTAKT=Kassier\r\nVERSCHLÜSSELTEBPK=BMF+SA\r\n\r\nLAUFNR;NACHNAME;VORNAME;GEBDATUM;NAME_VOR_ERSTER_EHE;GEBORT;GESCHLECHT;STAATSANGEHÖRIGKEIT;"
             f"ANSCHRIFTSSTAAT;GEMEINDENAME;PLZ;STRASSE;HAUSNR;REGISTER;VBPK_FÜR_VKZ=BMF+SA;ZUSATZINFO\r\nSP-1;Beispiel;Erika;1980-05-12;;;;;AUT;Telfs;6410;Hauptstraße;12a;ZMR;{vbpk};\r\n")
    missed = f"KONTAKT=Kassier\r\n\r\nLAUFNR;NACHNAME;VORNAME;GEBDATUM\r\n{customer};Kunde;Max;1975-01-02\r\n"
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w") as archive:
        archive.writestr("BPK_XZVR-1_1_20260923-120000_VERSCHL_BPK.csv", found.encode("utf-8"))
        archive.writestr("BPK_XZVR-1_1_20260923-120000_KEINTREFFER.csv", missed.encode("utf-8"))
        archive.writestr("BPK_XZVR-1_1_20260923-120000_STATISTIK.csv", b"Treffer: 1")
    page = page_ok(browser.get(base), "before the answer of the register")
    page_ok(browser.post_multipart(base, [("token", token_of(page)), ("action", "szrimport")], [("szr_file", "BPK_XZVR-1_1_20260923-120000.zip", buffer.getvalue())]),
            "read the answer of the register")
    states = dict(stack.sql("SELECT refnr, vbpk_state FROM llx_vereine_donor WHERE refnr IN ('SP-1', '" + customer + "')"))
    expect(states == {"SP-1": "found", customer: "notfound"} and stack.value("SELECT vbpk FROM llx_vereine_donor WHERE refnr = 'SP-1'") == vbpk,
           f"what the register answered: {states}")

    # The report: one first transmission for Erika, checked against the schema, downloaded as it was written.
    page = page_ok(browser.get(base), "ready to report")
    expect('data-donation-reportable="1"' in page.text, "Erika is not ready to report")
    page_ok(browser.submit(page.form(name="vereinedonationreport")), "write the report")
    report, ref = stack.sql("SELECT rowid, message_ref FROM llx_vereine_donation_report ORDER BY rowid DESC LIMIT 1")[0]
    xml = browser.get(f"{base}&action=xml&report={report}&token={token_of(page)}").text
    expect('<Uebermittlungsart>SP</Uebermittlungsart>' in xml and f"<Zeitraum>{year}</Zeitraum>" in xml and 'Uebermittlungs_Typ="E"' in xml
           and "<RefNr>SP-1</RefNr>" in xml and "<Betrag>100.00</Betrag>" in xml and f"<vbPK>{vbpk}</vbPK>" in xml and customer not in xml,
           f"the report: {xml[:900]}")
    page = page_ok(browser.get(base), "the report waiting")
    expect('data-donation-reportable="0"' in page.text, "a person waiting for the protocol is offered a second time")

    def protocol(info: str, where: str, errors: str = "") -> bytes:
        return ('<?xml version="1.0" encoding="UTF-8"?><SonderausgabenResponse xmlns="https://finanzonline.bmf.gv.at/fon/ws/uebermittlungSonderausgaben">'
                f"<MessageSpec><MessageRefId>{ref}</MessageRefId><EinbringungsTimestamp>2026-02-10T16:08:59</EinbringungsTimestamp><Art>UEB_SA</Art>"
                f"<Uebermittlung>{where}</Uebermittlung><Info>{info}</Info></MessageSpec>{errors}</SonderausgabenResponse>").encode("utf-8")

    # A test transmission counts for nothing; the real one takes the line.
    page_ok(browser.post_multipart(base, [("token", token_of(page)), ("action", "protocol")], [("protocol_file", "test.xml", protocol("OK", "T"))]), "a test protocol")
    expect(stack.value(f"SELECT state FROM llx_vereine_donation_line WHERE fk_report = {report}") == "sent", "a test transmission counted as filed")
    page = page_ok(browser.get(base), "after the test")
    page_ok(browser.post_multipart(base, [("token", token_of(page)), ("action", "protocol")], [("protocol_file", "protokoll.xml", protocol("OK", "P"))]), "the protocol")
    expect(stack.value(f"SELECT state FROM llx_vereine_donation_line WHERE fk_report = {report}") == "ok"
           and stack.value(f"SELECT status FROM llx_vereine_donation_report WHERE rowid = {report}") == "done", "the accepted report is not marked")

    # Another donation of Erika: the next report changes her sum to 120.
    stack.php_fixture("donorsmore", RT_YEAR=str(year))
    page = page_ok(browser.get(base), "after another donation")
    expect(re.search(r'data-donation-ref="SP-1" data-donation-total="120.00" data-donation-next="A"', page.text) is not None, "the changed sum is not offered as a change")
    page_ok(browser.submit(page.form(name="vereinedonationreport")), "write the change")
    report, ref = stack.sql("SELECT rowid, message_ref FROM llx_vereine_donation_report ORDER BY rowid DESC LIMIT 1")[0]
    xml = browser.get(f"{base}&action=xml&report={report}&token={token_of(page)}").text
    expect('Uebermittlungs_Typ="A"' in xml and "<Betrag>120.00</Betrag>" in xml and "<vbPK>" not in xml, f"the change: {xml[:900]}")
    refused = ("<SonderausgabenError><RefNr>SP-1</RefNr><Error><Code>ERR-U-009</Code><Text>Änderung nicht möglich.</Text></Error></SonderausgabenError>")
    page = page_ok(browser.get(base), "the change waiting")
    page_ok(browser.post_multipart(base, [("token", token_of(page)), ("action", "protocol")], [("protocol_file", "protokoll2.xml", protocol("TWOK", "P", refused))]),
            "a partly accepted protocol")
    line = stack.sql(f"SELECT state, error FROM llx_vereine_donation_line WHERE fk_report = {report}")[0]
    expect(line[0] == "failed" and "ERR-U-009" in line[1], f"the refused line: {line}")
    page = page_ok(browser.get(base), "after the refusal")
    expect(re.search(r'data-donation-ref="SP-1" data-donation-total="120.00" data-donation-next="A"', page.text) is not None, "a refused change is not offered again")

    # A company gets a confirmation instead.
    company = stack.value("SELECT rowid FROM llx_vereine_donor WHERE lastname = 'Beispiel GmbH'")
    receipt = browser.get(f"{base}&action=receipt&person={company}&token={token_of(page)}")
    text = pdf_bytes_text(receipt.body)
    expect(receipt.status == 200 and "Beispiel GmbH" in text and "200,00" in text, "the confirmation of the company's donation")
    return (f"donors of {year} linked (two donations of one third party are one donor), the date of birth stored encrypted, the register file "
            "with both people and the answer read from its ZIP (found and not found), a first transmission checked against the schema, a test "
            "protocol counted for nothing, the real one took the line, another donation became a change to 120, a refused change stays open, "
            "and the company got a confirmation")


def setupguide(stack: Stack) -> str:
    """First steps: a fresh installation shows every step open, the association data close step 1, a missing module is explained (#126)."""
    browser = stack.browser()
    start = "/custom/vereine/admin/start.php"
    expect(denied(stack.browser("rtreader").get(start)), "a non-administrator opens the first steps")
    page = page_ok(browser.get(start), "the first steps after enabling")
    states = dict(re.findall(r'data-setup-step="([a-z]+)" data-setup-state="([a-z]+)"', page.text))
    expect(list(states) == ["association", "modules", "statutes", "board", "fees", "consents", "mail", "meetings", "website"],
           f"the steps: {list(states)}")
    expect(states["association"] == "open" and states["statutes"] == "open" and states["website"] == "optional" and states["modules"] == "done",
           f"a fresh installation: {states}")
    overview = page_ok(browser.get("/custom/vereine/vereineindex.php"), "the overview with the hint")
    expect("data-setup-hint=" in overview.text and "/admin/start.php" in overview.text, "the overview does not point at the first steps")

    # The association data close step 1; what the test set is taken back for the scenarios after it.
    before = {name: stack.const(name) for name in ("VEREINE_REGISTER_NUMBER", "VEREINE_PURPOSE", "MAIN_INFO_SOCIETE_NOM")}
    try:
        for name, value in (("VEREINE_REGISTER_NUMBER", "123456789"), ("VEREINE_PURPOSE", "Sport"), ("MAIN_INFO_SOCIETE_NOM", "Runtime Verein")):
            stack.sql(f"DELETE FROM llx_const WHERE name = '{name}' AND entity = 1")
            stack.sql(f"INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('{name}', 1, '{value}', 'chaine', 0)")
        page = page_ok(browser.get(start), "the first steps with the association data")
        expect('data-setup-step="association" data-setup-state="done"' in page.text, "the association data do not close step 1")
    finally:
        for name, value in before.items():
            stack.sql(f"DELETE FROM llx_const WHERE name = '{name}' AND entity = 1")
            if value is not None:
                stack.sql(f"INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('{name}', 1, '{value.replace(chr(39), chr(39) * 2)}', 'chaine', 0)")

    # A required module switched off is named and explained.
    try:
        stack.sql("UPDATE llx_const SET value = '0' WHERE name = 'MAIN_MODULE_CATEGORIE' AND entity = 1")
        page = page_ok(browser.get(start), "the first steps without categories")
        expect('data-setup-step="modules" data-setup-state="open"' in page.text and 'data-setup-module-missing="categorie"' in page.text,
               "a missing required module is not explained")
    finally:
        stack.sql("UPDATE llx_const SET value = '1' WHERE name = 'MAIN_MODULE_CATEGORIE' AND entity = 1")

    # Leaving out and taking back in; the test e-mail reaches the mailbox and closes its step.
    page = page_ok(browser.get(start), "the first steps again")
    page_ok(browser.submit(page.form(name="vereinesetupskipwebsite")), "leave the website out")
    page = page_ok(browser.get(start), "after leaving out")
    expect('data-setup-step="website" data-setup-state="skipped"' in page.text, "a step left out is not shown as left out")
    page_ok(browser.submit(page.form(name="vereinesetupskipwebsite")), "take the website back in")
    address = stack.value("SELECT email FROM llx_user WHERE login = 'admin'")
    try:
        stack.sql("UPDATE llx_user SET email = 'kassier.test@runtime-verein.test' WHERE login = 'admin'")
        page = page_ok(browser.get(start), "the first steps with an address")
        page_ok(browser.submit(page.form(name="vereinesetupmail")), "send the test e-mail")
        arrived = [message for message in stack.mailpit().messages() if "Testnachricht" in (message.get("Subject") or "")
                   and any(to.get("Address") == "kassier.test@runtime-verein.test" for to in message.get("To") or [])]
        expect(arrived, "the test e-mail did not arrive")
        page = page_ok(browser.get(start), "after the test e-mail")
        expect('data-setup-step="mail" data-setup-state="done"' in page.text and 'data-setup-step="website" data-setup-state="optional"' in page.text,
               "the test e-mail does not close its step, or the website stayed left out")
    finally:
        stack.sql(f"UPDATE llx_user SET email = {repr(address) if address not in (None, 'NULL') else 'NULL'} WHERE login = 'admin'")
    return ("a fresh installation showed all nine steps with the association data open and the website optional, the overview pointed at "
            "them; the association data closed step 1, a switched-off required module was named, a step could be left out and taken back, "
            "and the test e-mail arrived and closed its step")


def archive(stack: Stack) -> str:
    """Files of the association: a finished PDF is PDF/A with a code, the public check knows it and nothing more, the export has every file with its checksum (#123)."""
    browser = stack.browser()
    code = stack.value("SELECT code FROM llx_vereine_document WHERE kind = 'account' ORDER BY rowid DESC LIMIT 1")
    expect(code is not None, "the account built in its scenario got no code")
    relpath = stack.value("SELECT f.relpath FROM llx_vereine_document_file as f INNER JOIN llx_vereine_document as d ON d.rowid = f.fk_document"
                          f" WHERE d.code = '{code}' AND f.what = 'built' ORDER BY f.rowid DESC LIMIT 1")
    data = base64.b64decode(stack.shell(f"base64 '/var/www/documents/vereine/{relpath}'").stdout)
    printed = f"{code[:5]}-{code[5:]}"
    expect(b"pdfaid:part" in data, "the account is no PDF/A")
    expect(printed in pdf_bytes_text(data), f"the account does not carry its code {printed}")

    # The public check, without logging in: the kind and the day, never the title.
    anybody = Browser(stack.url)
    check = f"/custom/vereine/public/verify.php?code={printed}"
    page = page_ok(anybody.get(check), "the public check")
    expect('data-verify="genuine"' in page.text and 'data-verify-kind="account"' in page.text, "the public check does not know the account")
    minutes, title = (stack.sql("SELECT code, title FROM llx_vereine_document WHERE kind = 'minutes' ORDER BY rowid LIMIT 1") or [[None, None]])[0]
    if minutes is not None:
        shown = page_ok(anybody.get(f"/custom/vereine/public/verify.php?code={minutes}"), "the public check of minutes").text
        expect('data-verify="genuine"' in shown and html.escape(title) not in shown and title not in shown, "the public check shows the title of minutes")
    unknown = page_ok(anybody.get("/custom/vereine/public/verify.php?code=ZZZZZ-ZZZZZ"), "an unknown code")
    expect('data-verify="unknown"' in unknown.text, "an unknown code is not called unknown")
    matched = page_ok(anybody.post_multipart("/custom/vereine/public/verify.php", [("token", token_of(page)), ("code", printed)],
                                             [("document", "rechnung.pdf", data)]), "compare the file")
    expect('data-verify-file="match"' in matched.text, "the genuine file does not match")
    changed = page_ok(anybody.post_multipart("/custom/vereine/public/verify.php", [("token", token_of(page)), ("code", printed)],
                                             [("document", "rechnung.pdf", data + b"\n%changed")]), "compare a changed file")
    expect('data-verify-file="nomatch"' in changed.text, "a changed file matches")

    # The association switches the check off: it answers nothing.
    own = page_ok(browser.get("/custom/vereine/archive.php"), "the files of the association")
    page_ok(browser.submit(own.form(name="vereinearchivepublic")), "switch the public check off")
    try:
        off = anybody.get(check)
        expect(off.status == 404 and 'data-verify="off"' in off.text and "genuine" not in off.text, f"the switched off check answers: HTTP {off.status}")
    finally:
        own = page_ok(browser.get("/custom/vereine/archive.php"), "the files of the association again")
        page_ok(browser.submit(own.form(name="vereinearchivepublic")), "switch the public check on again")
    expect(stack.const("VEREINE_VERIFY_PUBLIC") == "1", "the public check did not come back on")

    # The export of the year: every file with its checksum, and the table of contents.
    year = stack.today()[:4]
    own = page_ok(browser.get("/custom/vereine/archive.php"), "the export")
    answer = browser.submit(own.form(name="vereinearchiveexport"), {"from": f"{year}-01-01", "to": f"{year}-12-31"})
    expect(answer.status == 200 and answer.body[:2] == b"PK", f"the export is no ZIP: HTTP {answer.status}")
    with zipfile.ZipFile(io.BytesIO(answer.body)) as archived:
        names = archived.namelist()
        sums = dict(reversed(line.split("  ", 1)) for line in archived.read("pruefsummen.sha256").decode("utf-8").splitlines() if line)
        wrong = [name for name, sha in sums.items() if hashlib.sha256(archived.read(name)).hexdigest() != sha]
        index = archived.read("inhaltsverzeichnis.csv").decode("utf-8-sig")
    expect("inhaltsverzeichnis.csv" in names and any("_account_" in name for name in sums), f"the export lacks the account or its table of contents: {names}")
    expect(not wrong and len(sums) == len(names) - 2, f"checksums of the export do not match: {wrong}")
    expect(printed in index and "Einnahmen-Ausgaben-Rechnung" in index, "the table of contents does not name the account with its code")
    return (f"the account is PDF/A and carries its code {printed}; the public check knew it without login, showed no title, called an unknown "
            f"code unknown, matched the genuine file and not a changed one, and answered nothing while switched off; the export of {year} "
            f"held {len(sums)} files whose checksums match, with the table of contents")


def disclosure(stack: Stack) -> str:
    """Access to one's own data: the request kept with its check, a copy as PDF and JSON with the member's rows and nobody else's (#10)."""
    browser = stack.browser()
    member = int(stack.value("SELECT fk_adherent FROM llx_vereine_consent GROUP BY fk_adherent ORDER BY COUNT(*) DESC LIMIT 1"))
    first, last = stack.sql(f"SELECT firstname, lastname FROM llx_adherent WHERE rowid = {member}")[0]
    other = stack.sql(f"SELECT firstname, lastname FROM llx_adherent WHERE rowid <> {member} AND lastname <> '{last}' ORDER BY rowid LIMIT 1")[0]
    tab = f"/custom/vereine/member_association.php?id={member}"
    page = page_ok(browser.get(tab), "the member's tab")
    refused = page_ok(browser.submit(page.form(name="vereinedisclosure"), {"check": ""}), "a request without a check")
    expect("wie du geprüft hast" in html.unescape(refused.text) and stack.value(f"SELECT COUNT(*) FROM llx_vereine_disclosure WHERE fk_adherent = {member}") == "0",
           "a request without a check of the person was taken")
    page = page_ok(browser.get(tab), "the member's tab again")
    answer = browser.submit(page.form(name="vereinedisclosure"), {"check": "id_document", "requested_on": stack.today()})
    expect(answer.status == 200 and answer.body[:2] == b"PK", f"the copy is no ZIP: HTTP {answer.status}")
    with zipfile.ZipFile(io.BytesIO(answer.body)) as copy:
        data = json.loads(copy.read("auskunft.json").decode("utf-8"))
        text = pdf_bytes_text(copy.read("auskunft.pdf"))
    expect(data["sections"]["member"][0]["lastname"] == last and data["sections"]["consents"], f"the copy lacks the member or the consents: {list(data['sections'])}")
    everything = json.dumps(data, ensure_ascii=False)
    expect(f"{other[0]} {other[1]}" not in everything and other[1] not in text, f"the copy names another member: {other}")
    expect("Einwilligungen" in text and last in text, "the PDF does not show the consents of the member")
    kept = stack.sql(f"SELECT identity_check, sha256 FROM llx_vereine_disclosure WHERE fk_adherent = {member}")
    expect(kept == [["id_document", hashlib.sha256(answer.body).hexdigest()]], f"the request was not kept with the checksum of what went out: {kept}")
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_log WHERE action = 'disclosure'") == "1", "handing out the copy was not logged")
    expect(denied(stack.browser("rtnobody").get(tab)), "a user without rights opens the member's tab")
    return (f"a request without a check was refused; the copy for {first} {last} came as ZIP with PDF and JSON, holds the member's data and "
            f"{len(data['sections']['consents'])} consents and no other member; the request was kept with the checksum of the copy and logged")


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
            "birth": (day - datetime.timedelta(days=17 * 365)).isoformat(),
            "address": "Teststraße 2", "zip": "6020", "town": "Innsbruck"}
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
    expect([kind for kind, _, _ in kinds] == ["letter", "minutes", "resolution", "money", "audit_report", "account", "payout"],
           f"kinds of document: {kinds}")
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
    return ("6 kinds of document; letters signed by the chair; wrong password refused, signing in Dolibarr stored with the checksum and the sheet built; "
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
    today = stack.today()
    terms = stack.sql("SELECT f.code, t.fk_adherent FROM llx_vereine_function_term as t INNER JOIN llx_vereine_function as f ON f.rowid = t.fk_function"
                      f" INNER JOIN llx_adherent as a ON a.rowid = t.fk_adherent WHERE f.active = 1 AND a.statut = 1 AND t.date_start <= '{today}'"
                      f" AND (t.date_end IS NULL OR t.date_end >= '{today}') ORDER BY t.rowid")
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


def placeholders(stack: Stack) -> str:
    """Placeholders: the association's data in Dolibarr's e-mail templates, the module's e-mails as templates, one list with examples."""
    browser = stack.browser()
    setup = "/custom/vereine/admin/setup.php"
    base = "/custom/vereine/meetings.php"
    types = ["vereine_invitation", "vereine_minutes", "vereine_circular", "vereine_reminder"]
    page_ok(browser.submit(page_ok(browser.get(setup), "setup").form(name="vereinesetup"), {"VEREINE_REGISTER_NUMBER": "123456789"}), "the ZVR number")
    expect(stack.const("VEREINE_REGISTER_NUMBER") == "123456789", "the ZVR number was not stored")

    # The activation added one standard template per kind of e-mail, with the text the module always sent.
    stored = stack.sql("SELECT type_template, module, active FROM llx_c_email_templates WHERE module = 'vereine' ORDER BY position")
    expect([row[0] for row in stored] == types and all(row[1:] == ["vereine", "1"] for row in stored), f"the standard templates: {stored}")
    page = page_ok(browser.get(setup), "setup with the texts of the e-mails")
    states = dict(re.findall(r'data-mail-template="([a-z_]+)" data-template-state="([a-z]+)"', page.text))
    expect(states == {kind: "unchanged" for kind in types}, f"the templates in the setup: {states}")
    examples = dict(re.findall(r'data-example="(__VEREINE_[A-Z_]+__)">([^<]*)<', page.text))
    listed = set(re.findall(r'data-placeholder="(__VEREINE_[A-Z_]+__)"', page.text))
    expect(examples.get("__VEREINE_ZVR__") == "123456789" and examples.get("__VEREINE_NAME__") and {"__VEREINE_TAGESORDNUNG__", "__VEREINE_UMLAUF_LINK__",
           "__VEREINE_MITGLIED_FUNKTIONEN__"} <= listed, f"the list of placeholders: ZVR {examples.get('__VEREINE_ZVR__')!r}, {len(listed)} listed")

    # Dolibarr's template editor offers the module's kinds and lists its placeholders.
    editor = page_ok(browser.get("/admin/mails_templates.php"), "Dolibarr's e-mail templates")
    expect('value="vereine_invitation"' in editor.text and "__VEREINE_ZVR__" in editor.text and "__VEREINE_TAGESORDNUNG__" in editor.text,
           "Dolibarr's template editor does not know the module's kinds or placeholders")

    # A changed invitation is used for the next invitation, with the module's and Dolibarr's placeholders.
    stack.sql("UPDATE llx_c_email_templates SET topic = 'Einladung zu __VEREINE_SITZUNG_TITEL__ (ZVR __VEREINE_ZVR__)',"
              " content = CONCAT(content, '\\nZVR __VEREINE_ZVR__ - __MYCOMPANY_NAME__') WHERE module = 'vereine' AND type_template = 'vereine_invitation'")
    page = page_ok(browser.get(setup), "setup after the change")
    expect('data-mail-template="vereine_invitation" data-template-state="changed"' in page.text, "a changed template is not shown as changed")
    day = (datetime.date.today() + datetime.timedelta(days=40)).isoformat()
    page_ok(browser.submit(page_ok(browser.get(base), "meetings").form(name="vereinemeeting"),
                           {"kind": "board", "title": "Vorstandssitzung mit Vorlage", "day": day, "time": "18:00", "format": "physical",
                            "place": "Vereinsheim", "agenda": "Begrüßung\nBudget"}), "a board meeting")
    meeting = stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting")
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the planned meeting")
    preview = html.unescape(page.text.split('data-invitation-preview="1"')[1][:4000]) if 'data-invitation-preview="1"' in page.text else ""
    expect(re.search(r"ZVR 123456789 - \S", preview) is not None and "__MYCOMPANY_NAME__" not in preview, "the preview does not show the changed template")
    mail = stack.mailpit()
    mail.clear()
    page_ok(browser.submit(page.form(name="vereinemeetinginvite"), {"checked": "1"}), "invite with the changed template")
    messages = mail.messages()
    subjects = {message.get("Subject") for message in messages}
    expect(messages and subjects == {"Einladung zu Vorstandssitzung mit Vorlage (ZVR 123456789)"}, f"the subjects: {subjects}")
    text = mail.message(messages[0]["ID"]).get("Text", "")
    expect(re.search(r"ZVR 123456789 - \S", text) is not None and "2. Budget" in text and "__" not in text,
           f"the text of the invitation: {text[-300:]!r}")
    return (f"4 standard templates of the module, unchanged; {len(listed)} placeholders listed with examples, ZVR among them; "
            "Dolibarr's editor offers the kinds and lists the placeholders; a changed invitation used for preview and e-mail, "
            "with the ZVR number and Dolibarr's company name filled in")


def taxcheck(stack: Stack) -> str:
    """Older invoice lines without a tax profile: suggestions from facts, a preview, assigning only the profile."""
    browser = stack.browser()
    base = "/custom/vereine/taxcheck.php"
    fee = stack.sql("SELECT d.rowid, YEAR(f.datef), f.rowid FROM llx_facturedet as d INNER JOIN llx_facture as f ON f.rowid = d.fk_facture"
                    " INNER JOIN llx_element_element as ee ON ee.fk_target = f.rowid AND ee.sourcetype = 'subscription' AND ee.targettype = 'facture'"
                    " WHERE f.fk_statut > 0 ORDER BY d.rowid LIMIT 1")
    expect(fee, "the fee scenarios should leave a validated fee invoice")
    line, year, invoice = fee[0]
    # A line from before the tax profiles: the profile is not there.
    stack.sql(f"UPDATE llx_facturedet_extrafields SET vereine_taxprofile = NULL WHERE fk_object = {line}")
    page = page_ok(browser.get(f"{base}?year={year}"), "the check of the tax profiles")
    rows = {row[0]: row[1:] for row in re.findall(r'data-taxcheck-line="(\d+)" data-suggested="(\d+)" data-reason="([a-z]+)" data-certain="(\d)"', page.text)}
    expect(line in rows and rows[line][1] in ("fee", "product") and rows[line][2] == "1", f"the fee line and its suggestion: {rows.get(line)}")
    expect('data-taxcheck-preview="1"' in page.text, "no preview by profile")
    manual = [key for key, value in rows.items() if value[1] == "manual"]
    expect(all(rows[key][2] == "0" for key in manual), "a line to check by hand is ticked")

    before = stack.sql(f"SELECT total_ht, total_tva, total_ttc, fk_statut, paye FROM llx_facture WHERE rowid = {invoice}")
    line_before = stack.sql(f"SELECT total_ht, tva_tx FROM llx_facturedet WHERE rowid = {line}")
    page_ok(browser.post(f"{base}?year={year}", [("token", token_of(page)), ("action", "assign"), (f"line[{line}]", "1"), (f"profile[{line}]", rows[line][0])]),
            "assign the fee line")
    stored = stack.value(f"SELECT vereine_taxprofile FROM llx_facturedet_extrafields WHERE fk_object = {line}")
    expect(stored == rows[line][0], f"the fee line did not get its profile: {stored}")
    expect(stack.sql(f"SELECT total_ht, total_tva, total_ttc, fk_statut, paye FROM llx_facture WHERE rowid = {invoice}") == before
           and stack.sql(f"SELECT total_ht, tva_tx FROM llx_facturedet WHERE rowid = {line}") == line_before, "the invoice changed with its tax profile")
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_log WHERE action = 'tax_profile_set'") == "1", "the assignment is not in the log")
    again = page_ok(browser.get(f"{base}?year={year}"), "the check after assigning")
    expect(f'data-taxcheck-line="{line}"' not in again.text, "an assigned line is still listed")
    refused = page_ok(browser.post(f"{base}?year={year}", [("token", token_of(again)), ("action", "assign"), (f"line[{line}]", "1"),
                                                         (f"profile[{line}]", rows[line][0])]), "assign the same line again")
    expect("Keine Zeile zugeordnet" in html.unescape(refused.text), "a line with a profile was assigned again")
    return (f"{len(rows)} lines of {year} without profile, the fee line suggested and ticked, {len(manual)} left to check by hand; "
            "only the profile field changed, invoice and line as before, logged; a line with a profile is not assigned again")


def vatex(stack: Stack) -> str:
    """0 % with a reason: three codes in Dolibarr's VAT dictionary, the e-invoice reason VATEX-EU-O for not subject to VAT on Dolibarr 24,
    products of a profile at 0 % take their code, and so does the invoice line built from one (#45)."""
    browser = stack.browser()
    setup = "/custom/vereine/admin/taxprofiles.php"
    codes = ("SELECT t.code, t.taux, t.active FROM llx_c_tva as t INNER JOIN llx_c_country as c ON c.rowid = t.fk_pays"
             " WHERE c.code = 'AT' AND t.code LIKE 'AT-%' ORDER BY t.code")
    expect(stack.sql(codes) == [], "the dictionary had the codes before, the test proves nothing")
    page = page_ok(browser.get(setup), "tax profiles without the codes")
    expect('data-vatcodes="missing"' in page.text, "the setup does not offer the codes")
    page_ok(browser.submit(page.form(name="vereinevatcodes")), "add the codes")
    page = page_ok(browser.get(setup), "tax profiles with the codes")
    page_ok(browser.post(setup, [("token", token_of(page)), ("action", "addvatcodes")]), "add the codes again")
    rows = [(code, float(rate), active) for code, rate, active in stack.sql(codes)]
    expect(rows == [("AT-KU", 0.0, "1"), ("AT-NS", 0.0, "1"), ("AT-SP", 0.0, "1")], f"the codes in the dictionary: {rows}")
    column = stack.sql("SHOW COLUMNS FROM llx_c_tva LIKE 'einvoice_vatex'")
    if column:
        reasons = dict(stack.sql("SELECT t.code, COALESCE(t.einvoice_vatex, '') FROM llx_c_tva as t INNER JOIN llx_c_country as c ON c.rowid = t.fk_pays"
                                 " WHERE c.code = 'AT' AND t.code LIKE 'AT-%'"))
        expect(reasons == {"AT-NS": "VATEX-EU-O", "AT-KU": "", "AT-SP": ""} and 'data-vatex="available"' in page.text, f"the reasons kept: {reasons}")
    else:
        expect(not stack.version.startswith("24") and 'data-vatex="unavailable"' in page.text, f"Dolibarr {stack.version} without the VATEX column")

    small = stack.value("SELECT p.rowid FROM llx_product as p INNER JOIN llx_product_extrafields as e ON e.fk_object = p.rowid INNER JOIN llx_vereine_taxprofile as t"
                        " ON t.rowid = e.vereine_taxprofile WHERE t.treatment = 'small_business' ORDER BY p.rowid LIMIT 1")
    fee = stack.value("SELECT p.rowid FROM llx_product as p INNER JOIN llx_product_extrafields as e ON e.fk_object = p.rowid INNER JOIN llx_vereine_taxprofile as t"
                      " ON t.rowid = e.vereine_taxprofile WHERE t.treatment = 'nonbusiness' ORDER BY p.rowid LIMIT 1")
    expect(small not in (None, "") and fee not in (None, ""), f"products for the test: small business {small}, fee {fee}")
    kept = dict(stack.sql(f"SELECT rowid, COALESCE(default_vat_code, '') FROM llx_product WHERE rowid IN ({small}, {fee})"))
    expect(kept == {small: "AT-KU", fee: "AT-NS"}, f"the codes of the products: {kept}")
    line = stack.php_fixture("vatline", RT_PRODUCT_ID=small)
    expect(line["tva_tx"] == 0 and line["vat_src_code"] == "AT-KU", f"the invoice line of the small business product: {line}")
    return ("three codes for 0 % in the dictionary once though added twice, VATEX-EU-O for not subject to VAT where Dolibarr keeps it; "
            "products of the profiles took AT-KU and AT-NS, and the invoice line built from one carries AT-KU")


def audit(stack: Stack) -> str:
    """The audit of the auditors: bookings and invoices of the year with hints, ticked samples, the checklist, the report with signatures."""
    year = int(stack.today()[:4])
    password = "Pruef-" + secrets.token_hex(8)
    data = stack.php_fixture("audit", RT_YEAR=str(year), RT_AUDITOR_PASSWORD=password)
    base = f"/custom/vereine/audit.php?year={year}"

    # Who reads invoices may look, but only an auditor ticks and writes; the admin is linked to no member here.
    stack.sql("UPDATE llx_user SET fk_member = NULL WHERE login = 'admin'")
    page = page_ok(stack.browser().get(base), "the audit for the board")
    expect('data-audit-is-auditor="0"' in page.text and 'name="vereineauditchecklist"' not in page.text, "somebody who is no auditor may fill the audit")
    hints = dict(re.findall(r'data-audit-hint="([a-z]+:\d+)" data-hints="([a-z_ ]+)"', page.text))
    expect("self_dealing" in hints.get(f"supplier:{data['officer_invoice']}", ""), f"the supplier invoice of the chair is no hint: {hints}")
    expect("no_document" in hints.get(f"bank:{data['lines']['no_document']}", "") and "unusual" in hints.get(f"bank:{data['lines']['large']}", ""),
           f"the booking without document or the large donation is no hint: {hints}")
    expect(denied(stack.browser("rtnobody").get(base)), "a user without rights opens the audit")

    auditor = Browser(stack.url)
    auditor.login("rtauditor", password)
    page = page_ok(auditor.get(base), "the audit for the auditor")
    marker = re.search(r'data-audit-auditors="(\d+)" data-audit-is-auditor="(\d)"', page.text)
    expect(marker is not None and marker.group(2) == "1" and data["auditor_name"] in html.unescape(page.text),
           f"the auditor is not recognised: {marker.groups() if marker else None}, name {data['auditor_name']!r} shown: {data['auditor_name'] in html.unescape(page.text)}")
    forms = [form for form in page.forms() if form.value("action") == "check" and form.value("element") == "supplier"
             and form.value("object") == str(data["officer_invoice"])]
    expect(len(forms) == 1, "no way to tick the supplier invoice of the chair")
    page_ok(auditor.submit(forms[0], {"note": "Zustimmung der Kassierin liegt vor"}), "tick the invoice of the chair")
    checked = stack.sql(f"SELECT element, note FROM llx_vereine_audit_check WHERE fiscal_year = {year}")
    expect(checked == [["supplier", "Zustimmung der Kassierin liegt vor"]], f"the ticked sample: {checked}")

    page = page_ok(auditor.get(base), "the audit with a sample")
    refused = page_ok(auditor.submit(page.form(name="vereineauditchecklist"), {"points[accounting][state]": "defect", "points[accounting][text]": ""}),
                      "a deficiency without text")
    expect("was nicht passt" in html.unescape(refused.text), "a deficiency without text was stored")
    changes = {f"points[{point}][state]": "ok" for point in ("accounting", "use", "unusual", "self_dealing", "danger")}
    changes.update({"audit_day": stack.today(), "points[self_dealing][text]": "Miete an den Obmann mit Zustimmung der Kassierin"})
    page_ok(auditor.submit(page_ok(auditor.get(base), "the checklist").form(name="vereineauditchecklist"), changes), "every point in order")
    page = page_ok(auditor.get(base), "the audit after the checklist")
    expect('data-audit-result="confirmed"' in page.text, "the audit is not confirmed with every point in order")

    page_ok(auditor.submit(page.form(name="vereineauditbuild")), "build the report")
    report = pdf_text(stack, "vereine/audit")
    for word in ("Rechnungspr", data["auditor_name"], "gew", str(year), "Ergebnis"):
        expect(word in report, f"the report lacks {word!r}")
    page = page_ok(auditor.get(base), "the audit with its report")
    audit_id = stack.value(f"SELECT rowid FROM llx_vereine_audit WHERE fiscal_year = {year}")
    page_ok(auditor.submit(page.form(name=f"vereinestartsignaudit_report{audit_id}")), "start the signatures of the report")
    signers = stack.sql(f"SELECT p.fk_adherent FROM llx_vereine_signature_person as p INNER JOIN llx_vereine_signature as s ON s.rowid = p.fk_signature"
                        f" WHERE s.kind = 'audit_report' AND s.fk_object = {audit_id}")
    expect([str(data["auditor_member"])] in signers, f"the auditor does not sign the report: {signers}")
    before = stack.value("SELECT COUNT(*) FROM llx_vereine_audit_check")
    admin = stack.browser()
    admin_page = page_ok(admin.get(base), "the audit for the board again")
    expect('action" value="check"' not in admin_page.text, "somebody who is no auditor gets a form to tick")
    # The page has no form for them; a token from another page, as a crafted request would bring one.
    token = token_of(page_ok(admin.get("/custom/vereine/admin/setup.php"), "a page with a token"))
    page_ok(admin.post(base, [("token", token), ("action", "check"), ("element", "bank"), ("object", str(data["lines"]["large"])),
                              ("checked", "1")]), "tick as somebody who is no auditor")
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_audit_check") == before, "somebody who is no auditor ticked a sample")
    return (f"year {year}: the chair's supplier invoice, a booking without document and a large donation as hints; the board may look, "
            "nobody without rights; the auditor ticked a sample, a deficiency needs text, every point in order confirms; "
            "report with auditor and year, signed by the auditor; somebody else cannot tick")


def account(stack: Stack) -> str:
    """The income and expenditure account: the money of the year by area, the check against the bank, the statement of assets, PDF, table, signatures."""
    year = int(stack.today()[:4])
    base = f"/custom/vereine/account.php?year={year}"
    expect(denied(stack.browser("rtreader").get(base)), "somebody who may not read the bank opens the account")
    data = stack.php_fixture("account", RT_YEAR=str(year))
    lines = data["lines"]
    browser = stack.browser()
    page = page_ok(browser.get(base), "the account")

    # The bookings of the audit and of the fixture: paid invoice over two areas, part of the chair's invoice,
    # four bookings without payment, a transfer to the cash box and the initial balance of the cash box.
    sums = {key: float(value) for key, value in re.findall(r'data-account-sum="([a-z]+:[a-z]+)" data-amount="(-?[\d.]+)"', page.text)}
    expect(sums == {"income:ideal": 50.0, "income:harmful": 120.0, "income:unassigned": 5020.0, "expense:ideal": 100.0, "expense:unassigned": 95.0},
           f"sums by area: {sums}")
    bookings = {booking: (kind, dict(part.split("=") for part in parts.split(";") if part))
                for booking, kind, parts in re.findall(r'data-booking="(\d+)" data-kind="([a-z_]+)" data-parts="([^"]*)"', page.text)}
    expect(bookings.get(str(lines["invoice"])) == ("invoice", {"ideal": "50.00", "harmful": "120.00"}), f"the paid invoice: {bookings.get(str(lines['invoice']))}")
    expect(bookings.get(str(lines["supplier"])) == ("supplier", {"ideal": "-100.00"}), f"the part payment to the chair: {bookings.get(str(lines['supplier']))}")
    kinds = {key: bookings.get(str(lines[key]), ("", {}))[0] for key in ("transfer_out", "transfer_in", "cash_opening")}
    expect(kinds == {"transfer_out": "transfer", "transfer_in": "transfer", "cash_opening": "opening"}, f"transfer and initial balance: {kinds}")
    reconciled = re.search(r'data-reconciled="(\d)">(.*?)</div>', page.text)
    expect(reconciled is not None and reconciled.group(1) == "1", f"the account does not agree with the bank: {reconciled.group(2) if reconciled else None}")
    unassigned = re.search(r'data-account-unassigned="(\d+)"', page.text)
    expect(unassigned is not None and unassigned.group(1) == "5" and 'data-account-unassigned-bookings="5"' in page.text,
           f"bookings without area: {unassigned.group(1) if unassigned else None}")
    expect(bookings.get(str(lines["various"]), ("", {}))[0] == "various", f"the various payment: {bookings.get(str(lines['various']))}")
    text = html.unescape(page.text)
    expect("DefaultCashPOSLabel" not in text and "Bargeldkonto für POS" in text and "(CustomerInvoicePayment)" not in text,
           "labels Dolibarr stores as language keys are shown untranslated")

    # The board chooses the area of the various payment and of the large booking without payment; a transfer takes none.
    page_ok(browser.submit(page.form(name="vereineaccountassign"), {f"area[{lines['various']}]": "ideal", f"area[{lines['large']}]": "ideal"}), "choose areas")
    page = page_ok(browser.get(base), "the account with chosen areas")
    sums = {key: float(value) for key, value in re.findall(r'data-account-sum="([a-z]+:[a-z]+)" data-amount="(-?[\d.]+)"', page.text)}
    expect(sums == {"income:ideal": 5050.0, "income:harmful": 120.0, "income:unassigned": 20.0, "expense:ideal": 125.0, "expense:unassigned": 70.0},
           f"sums after choosing areas: {sums}")
    expect(f'data-assign="{lines["various"]}" data-area="ideal"' in page.text and 'data-account-unassigned="3"' in page.text, "the chosen area is not shown")
    page_ok(browser.post(base, [("token", token_of(page)), ("action", "assign"), (f"area[{lines['transfer_out']}]", "ideal"), (f"area[{lines['various']}]", "harmful")]),
            "choose an area for a transfer")
    chosen = stack.sql("SELECT fk_bank, sphere FROM llx_vereine_account_line ORDER BY fk_bank")
    expect(sorted(chosen) == sorted([[str(lines["large"]), "ideal"], [str(lines["various"]), "harmful"]]), f"stored areas: {chosen}")
    page_ok(browser.post(base, [("token", token_of(page_ok(browser.get(base), "the account again"))), ("action", "assign"), (f"area[{lines['various']}]", "ideal")]),
            "choose the area of the various payment again")
    deadline = re.search(r'data-account-deadline="([\d-]+)"', page.text)
    expect(deadline is not None and deadline.group(1) > stack.today(), f"the deadline to make the account: {deadline.group(1) if deadline else None}")
    opened = {key: float(value) for key, value in re.findall(r'data-account-open="([a-z]+)" data-amount="(-?[\d.]+)"', page.text)}
    text = html.unescape(page.text)
    expect(opened.get("payables", 0) >= 200 and f"RT-OBMANN-{year}" in text, f"the rest of the chair's invoice is no debt: {opened}")
    expect(opened.get("receivables", 0) >= 96 and data["open_ref"] in text, f"the open invoice is no claim: {opened}, {data['open_ref']} shown: {data['open_ref'] in text}")

    # The board enters the day it was made and what Dolibarr does not know.
    page_ok(browser.submit(page.form(name="vereineaccount"), {"made_on": stack.today(), "extras[0][label]": "Beamer", "extras[0][amount]": "400",
                                                               "extras[1][label]": "Darlehen Obmann", "extras[1][amount]": "1000", "extras[1][kind]": "debt"}),
            "store the day and further assets")
    page = page_ok(browser.get(base), "the account after storing")
    expect(f'data-account-made="{stack.today()}"' in page.text, "the day the account was made is not shown")
    net = re.search(r'data-account-net="(-?[\d.]+)"', page.text)
    expected = 5095 + opened["receivables"] - opened["payables"] + 400 - 1000
    expect(net is not None and abs(float(net.group(1)) - expected) < 0.01, f"assets {net.group(1) if net else None}, expected {expected:.2f}")
    audit_page = page_ok(browser.get(f"/custom/vereine/audit.php?year={year}"), "the audit with the account")
    expect(f'data-audit-account="{stack.today()}"' in audit_page.text, "the audit does not know when the account was made")

    token = re.search(r'action=csv&amp;token=([^"&]+)', page.text)
    expect(token is not None, "no link to the table")
    table = page_ok(browser.get(f"{base}&action=csv&token={token.group(1)}"), "the bookings as table")
    expect("Ideeller Bereich" in table.text and "Umbuchung zwischen eigenen Konten" in table.text and "Beamer" not in table.text,
           f"the table: {table.text[:300]!r}")

    page_ok(browser.submit(page_ok(browser.get(base), "the account for the PDF").form(name="vereineaccountbuild")), "build the PDF")
    pdf = pdf_text(stack, "vereine/account")
    for word in ("Einnahmen", "Beamer", "Darlehen Obmann", str(year)):
        expect(word in pdf, f"the PDF lacks {word!r}")
    record = stack.value(f"SELECT rowid FROM llx_vereine_account WHERE fiscal_year = {year}")
    page = page_ok(browser.get(base), "the account with its PDF")
    page_ok(browser.submit(page.form(name=f"vereinestartsignaccount{record}")), "start the signatures")
    run = stack.value(f"SELECT rowid FROM llx_vereine_signature WHERE kind = 'account' AND fk_object = {record}")
    signers = stack.sql(f"SELECT fk_adherent FROM llx_vereine_signature_person WHERE fk_signature = {run}")
    expect([str(data["chair"])] in signers, f"the chair does not sign the account: {signers}")

    # The chair reads the bank but may not change it: signs, but stores nothing.
    reader = stack.value("SELECT rowid FROM llx_user WHERE login = 'rtreader'")
    member_before = stack.value(f"SELECT fk_member FROM llx_user WHERE rowid = {reader}")
    right = stack.value("SELECT id FROM llx_rights_def WHERE module = 'banque' AND perms = 'lire' AND entity = 1 ORDER BY id LIMIT 1")
    stack.sql(f"INSERT INTO llx_user_rights (entity, fk_user, fk_id) VALUES (1, {reader}, {right})")
    stack.sql(f"UPDATE llx_user SET fk_member = {data['chair']} WHERE rowid = {reader}")
    try:
        chair = stack.browser("rtreader")
        page = page_ok(chair.get(base), "the account for the chair")
        expect('name="vereineaccount"' not in page.text and 'name="vereineaccountbuild"' not in page.text and 'name="vereineaccountassign"' not in page.text,
               "somebody who may not change the bank gets the forms")
        page_ok(chair.submit(page.form(name=f"vereinesign{run}"), {"password": stack.reader_password}), "the chair signs")
        signed = stack.value(f"SELECT COUNT(*) FROM llx_vereine_signature_person WHERE fk_signature = {run} AND fk_adherent = {data['chair']} AND signed_at IS NOT NULL")
        expect(signed == "1", "the chair could not sign without the right to change the bank")
        page = page_ok(chair.get(base), "the account after signing")
        # No form to store; the token of the table link, as a crafted request would bring one.
        page_ok(chair.post(base, [("token", re.search(r'action=csv&amp;token=([^"&]+)', page.text).group(1)), ("action", "save"), ("made_on", "2000-01-01")]),
                "store as somebody who may not change the bank")
        expect(stack.value(f"SELECT made_on FROM llx_vereine_account WHERE rowid = {record}") == stack.today(), "somebody who may not change the bank stored the account")
    finally:
        stack.sql(f"DELETE FROM llx_user_rights WHERE fk_user = {reader} AND fk_id = {right}")
        stack.sql(f"UPDATE llx_user SET fk_member = {member_before if member_before not in (None, 'NULL') else 'NULL'} WHERE rowid = {reader}")
    return (f"year {year}: income 5190 and expenses 195 by area, the invoice split 50 ideal / 120 business, the transfer and the cash box's initial balance "
            "not counted, agrees with the bank; a various payment and a booking without payment given an area, a transfer takes none; labels translated; "
            "claims, debts and further assets in the statement; day made shown in the audit; table, PDF, "
            "the chair signs without the right to change the bank and stores nothing; nobody without bank rights")


def history(stack: Stack) -> str:
    """What the module does with a member stands in Dolibarr's events of the member; earlier entries become events exactly once (#112)."""
    browser = stack.browser()
    karl = int(stack.value("SELECT rowid FROM llx_adherent WHERE firstname = 'Karl' AND lastname = 'Austritt'"))
    # Karl became deputy treasurer after the agenda was switched on: the entry has its event.
    events = stack.sql(f"SELECT a.label, a.note FROM llx_actioncomm as a INNER JOIN llx_vereine_log as l ON l.fk_actioncomm = a.id"
                       f" WHERE a.elementtype = 'member' AND a.fk_element = {karl} AND l.action = 'function_start'")
    expect(len(events) == 1 and events[0][0].startswith("Funktion"), f"the new function of Karl is no event: {events}")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={karl}"), "association tab of Karl")
    expect('data-log-events="1"' in tab.text and 'data-log="1"' not in tab.text and f"/adherents/agenda.php?id={karl}" in tab.text,
           "the tab still shows the own list instead of Dolibarr's events")
    agenda = page_ok(browser.get(f"/adherents/agenda.php?id={karl}"), "Dolibarr's events of Karl")
    expect(events[0][0] in html.unescape(agenda.text), "Dolibarr's events of the member do not show the entry")

    # Entries from before the agenda, and one from an earlier version, become events once.
    stack.sql(f"INSERT INTO llx_vereine_log (entity, datec, fk_user, action, fk_adherent, fk_soc, message)"
              f" VALUES (1, '2025-03-01 10:00:00', NULL, 'consent_given', {karl}, NULL, 'Altbestand')")
    waiting = int(stack.value("SELECT COUNT(*) FROM llx_vereine_log WHERE fk_actioncomm IS NULL AND (fk_adherent > 0 OR fk_soc > 0)"))
    made = stack.php_fixture("migratelog").get("made")
    expect(waiting > 1 and made == waiting, f"{made} events made for {waiting} earlier entries")
    old = stack.sql("SELECT DATE(a.datep), a.note, a.elementtype FROM llx_actioncomm as a INNER JOIN llx_vereine_log as l ON l.fk_actioncomm = a.id"
                    " WHERE l.message = 'Altbestand'")
    expect(old == [["2025-03-01", "Altbestand", "member"]], f"the earlier entry as event: {old}")
    again = stack.php_fixture("migratelog").get("made")
    events_total = stack.value("SELECT COUNT(*) FROM llx_actioncomm WHERE code LIKE 'AC_VEREINE_LOG_%'")
    linked = stack.value("SELECT COUNT(DISTINCT fk_actioncomm) FROM llx_vereine_log WHERE fk_actioncomm IS NOT NULL")
    expect(again == 0 and events_total == linked, f"a second run made {again} events; {events_total} events for {linked} entries")
    return (f"Karl's new function as event on his card, the tab links to Dolibarr's events; {made} earlier entries became events with "
            "their own date, a second run made none")


def application(stack: Stack) -> str:
    """The application for membership: blank per member type, filled in on Dolibarr's member card, texts and consents from Dolibarr (#108)."""
    browser = stack.browser()
    base = "/custom/vereine/application.php"
    setup = "/custom/vereine/admin/application.php"
    member = int(stack.notes["website"]["members"]["paid"])
    type_id = stack.value(f"SELECT fk_adherent_type FROM llx_adherent WHERE rowid = {member}")
    page = page_ok(browser.get(base), "the blank applications")
    expect('data-application-texts="0"' in page.text, "the page does not say that the texts of the association are missing")
    built = dict(re.findall(r'data-application-type="(\d+)" data-application-pdf="(\d)"', page.text))
    expect(built.get(type_id) == "0", f"applications before building: {built}")

    # What the association writes itself; everything else comes from Dolibarr.
    page_ok(browser.submit(page_ok(browser.get(setup), "application setup").form(name="vereineapplicationsetup"),
                           {"intro": "Bitte im Vereinsheim abgeben, __VEREINE_NAME__, __VEREINE_ADRESSE__.",
                            "privacy": "Fragen zum Datenschutz an __VEREINE_EMAIL__. Die Daten dienen nur der Mitgliederverwaltung.",
                            "privacy_url": "https://runtime-verein.test/datenschutz", "required[]": "birth"}, drop=("required[]",)), "store the texts")
    expect((stack.const("VEREINE_APPLICATION_REQUIRED"), stack.const("VEREINE_APPLICATION_PRIVACY_URL"))
           == ("birth", "https://runtime-verein.test/datenschutz"), "the texts of the application were not stored")

    page = page_ok(browser.get(base), "the applications after the texts")
    expect('data-application-texts="0"' not in page.text, "the page still misses the texts of the association")
    # What the member type includes is written at the member type in Dolibarr and belongs on the form (#204).
    stack.sql(f"UPDATE llx_adherent_type SET note = 'Trainingszeiten, Vereinstrikot und Turnierstartgelder' WHERE rowid = {type_id}")
    page_ok(browser.submit(page.form(name=f"vereineapplication{type_id}")), "build the blank application")
    blank = pdf_text(stack, "vereine/application")
    for word in ("Mitgliedsantrag", "Statuten", "Vereinsheim", "Datenschutz", "Fotos", "ZVR", "pro Jahr", "ndigung",
                 "Aus den Statuten", "Zweck des Vereins", "verpflichtet"):
        expect(word in blank, f"the blank application lacks {word!r}")
    # The free texts of the association understand the placeholders of the module (#206).
    expect("__VEREINE_" not in blank and "runtime-verein.test" in blank,
           "placeholders in the texts of the association were not filled in on the form")
    # Every consent asks once, with one pair of boxes; the statutes are only ticked, there is no "no" (#202, #203).
    expect(blank.count("Ich willige ein") == blank.count("Nein") and blank.count("Nein") > 0, f"the form does not ask every consent exactly once: "
           + f"{blank.count('Ich willige ein')} consents, {blank.count('Nein')} times Nein")
    expect("Ja [" not in blank and "(v1)" not in blank and "Kündigungsfrist beträgt" in blank and re.search(r"Fassung \d", blank) is not None,
           "the form still carries brackets, versions in titles or the setup text for leaving")
    expect("Bezahlt" not in blank, "the blank application carries the data of a member")
    expect("Vereinstrikot" in blank, "what the member type includes is missing on the form")
    # Discounts of the fee model belong on the form, and the mandate when Dolibarr collects by direct debit (#206).
    discounts = stack.value("SELECT COUNT(*) FROM llx_vereine_fee_discount WHERE active = 1")
    expect(int(discounts) == 0 or "rm" in blank.lower(), f"{discounts} discounts are set up but none is named on the form")
    if stack.const("MAIN_MODULE_PRELEVEMENT") == "1":
        expect("SEPA" in blank and "IBAN" in blank, "the mandate is missing on the form although Dolibarr collects by direct debit")

    # Dolibarr's member card offers the template and fills it in.
    card = page_ok(browser.get(f"/adherents/card.php?id={member}"), "member card")
    expect("vereineantrag" in card.text, "Dolibarr's member card does not offer the application template of the module")
    page_ok(browser.post(f"/adherents/card.php?id={member}", [("token", token_of(card)), ("action", "builddoc"), ("model", "vereineantrag")]),
            "build the application of the member")
    filled = pdf_text(stack, "adherent")
    for word in ("Mitgliedsantrag", "Bezahlt", "Vereinsheim"):
        expect(word in filled, f"the application of the member lacks {word!r}")
    logged = stack.value(f"SELECT COUNT(*) FROM llx_vereine_log WHERE fk_adherent = {member} AND action = 'application_pdf'")
    expect(logged == "1", f"{logged} entries about the application of the member, expected 1")

    # A new version of a consent text reaches the form without a change in the code.
    page_ok(browser.submit(page_ok(browser.get("/custom/vereine/admin/consents.php"), "consent setup").form(name="vereineconsenttext"),
                           {"code": "newsletter", "label": "Newsletter", "text": "Der Newsletter des Vereins darf an meine E-Mail-Adresse gehen, neu gefasst."}),
            "a new version of the newsletter consent")
    page_ok(browser.submit(page_ok(browser.get(base), "applications").form(name=f"vereineapplication{type_id}")), "build the blank application again")
    blank = pdf_text(stack, "vereine/application")
    expect("neu gefasst" in blank and "Fassung 2" in blank, "the new version of the consent is not on the form")

    # The declaration of consent: one page per member who is still missing one of the chosen consents (#110).
    forms = "/custom/vereine/consents.php"
    page = page_ok(browser.get(forms), "the declarations of consent")
    codes = re.findall(r'data-consentform-code="([a-z_]+)"', page.text)
    expect("fotos" in codes and "newsletter" in codes, f"consents offered on the form: {codes}")
    built = page_ok(browser.submit(page.form(name="vereineconsentform"), {"codes[]": "fotos"}, drop=("codes[]",)), "build for everybody missing the photo consent")
    pages = re.search(r"erstellt: (\d+) Seite", html.unescape(built.text))
    declaration = pdf_text(stack, "vereine/consent")
    # The note on the withdrawal stands once per member; the title also stands in the foot of every page.
    expect(pages is not None and declaration.count("Art. 7") == int(pages.group(1)) and int(pages.group(1)) > 1,
           f"{pages.group(1) if pages else None} members named, {declaration.count('Art. 7')} pages")
    expect("Fotos" in declaration, "the declaration lacks the text of the consent")
    expect("runtime-verein.test" in declaration, "the declaration does not say where a consent can be withdrawn")
    # Exactly the members whose latest event for the consent is not a consent.
    missing = stack.value("SELECT COUNT(*) FROM llx_adherent as d WHERE d.statut = 1 AND COALESCE((SELECT c.given FROM llx_vereine_consent as c"
                          " WHERE c.fk_adherent = d.rowid AND c.code = 'fotos' ORDER BY c.date_event DESC, c.rowid DESC LIMIT 1), 0) = 0")
    expect(int(pages.group(1)) == int(missing), f"{pages.group(1)} pages for {missing} members who are missing the photo consent")

    one = page_ok(browser.get(f"{forms}?member={member}"), "the declaration for one member")
    expect('data-consentform-scope="member"' in one.text, "the declaration for one member is not limited to them")
    page_ok(browser.submit(one.form(name="vereineconsentform")), "build the declaration for one member")
    declaration = pdf_text(stack, "vereine/consent")
    expect(declaration.count("Art. 7") == 1 and "Bezahlt" in declaration, "the declaration of one member has the wrong pages")

    # Fields to fill in on the screen, per document (#107).
    page_ok(browser.submit(page_ok(browser.get(setup), "application setup").form(name="vereineapplicationsetup"),
                           {"fillable[]": "consent"}, drop=("fillable[]",)), "switch the fields on for the declaration")
    expect(stack.const("VEREINE_PDF_FILLABLE") == "consent", "the documents with fields were not stored")
    page_ok(browser.submit(page_ok(browser.get(f"{forms}?member={member}"), "the declaration again").form(name="vereineconsentform")), "build it with fields")
    filled_form = base64.b64decode(stack.shell("base64 $(ls -t $(find /var/www/documents/vereine/consent -name '*.pdf') | head -1)").stdout)
    expect(b"/Widget" in filled_form and b"consent_1_fotos_ja" in filled_form, "the declaration carries no fields to fill in")
    # With boxes to tick, the brackets of the print form must be gone (#203).
    expect("Ja [" not in pdf_bytes_text(filled_form), "the declaration shows the brackets although it has boxes to tick")
    again = page_ok(browser.get(base), "applications once more")
    listed = re.findall(r'data-application-type="(\d+)"', again.text)
    actions = [form.value("action") for form in again.forms()]
    expect(f'name="vereineapplication{type_id}"' in again.text, f"no way to build for type {type_id}: types {listed}, forms {actions}")
    page_ok(browser.submit(again.form(name=f"vereineapplication{type_id}")), "build the blank application once more")
    printed = base64.b64decode(stack.shell("base64 $(ls -t $(find /var/www/documents/vereine/application -name '*.pdf') | head -1)").stdout)
    expect(b"antrag_lastname" not in printed, "the application carries fields although only the declaration was switched on")

    # The association's own documents from an ODT template of Dolibarr, with the module's placeholders (#113).
    stack.shell("mkdir -p /var/www/documents/doctemplates/mitglieder && cp /var/www/html/custom/vereine/docs/vorlagen/vereinsvereinbarung.odt"
                " /var/www/documents/doctemplates/mitglieder/ && chown -R www-data:www-data /var/www/documents/doctemplates")
    stack.sql("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('ADHERENT_ADDON_PDF_ODT_PATH', 1, 'DOL_DATA_ROOT/doctemplates/mitglieder', 'chaine', 0)"
              " ON DUPLICATE KEY UPDATE value = 'DOL_DATA_ROOT/doctemplates/mitglieder'")
    stack.sql("INSERT INTO llx_document_model (nom, type, entity, libelle, description) SELECT 'generic_member_odt', 'member', 1, 'ODT', 'ADHERENT_ADDON_PDF_ODT_PATH'"
              " FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM llx_document_model WHERE nom = 'generic_member_odt' AND type = 'member' AND entity = 1)")
    card = page_ok(browser.get(f"/adherents/card.php?id={member}"), "member card for the ODT template")
    offered = re.findall(r'value="(generic_member_odt[^"]*)"', card.text) or ["generic_member_odt:vereinsvereinbarung.odt"]
    built = page_ok(browser.post(f"/adherents/card.php?id={member}", [("token", token_of(card)), ("action", "builddoc"), ("model", offered[0])]),
                    "fill the ODT template")
    generated = stack.shell("ls -t $(find /var/www/documents/adherent -name '*.odt' 2>/dev/null) 2>/dev/null | head -1").stdout.strip()
    expect(generated.endswith(".odt"), f"Dolibarr filled no ODT template ({offered[0]}): {generated!r}; answer "
           + repr(re.sub(r"\s+", " ", html.unescape(built.text))[-500:]))
    text = zipfile.ZipFile(io.BytesIO(base64.b64decode(stack.shell(f"base64 '{generated}'").stdout))).read("content.xml").decode("utf-8")
    expect("123456789" in text and "Bezahlt" in text, f"the filled template misses the association or the member: {text[:200]!r}")
    expect("{__VEREINE_MITGLIED_ART__}" not in text and "{__VEREINE_ZVR__}" not in text, "placeholders of the module stayed in the document")
    expect("Beitragspflichtig" in text, "the member type is missing in the filled template")

    reader = stack.browser("rtreader")
    page = page_ok(reader.get(base), "the applications for somebody who may only read members")
    expect(f'name="vereineapplication{type_id}"' not in page.text, "somebody who may not change members gets the way to build")
    expect(denied(reader.get(setup)), "a non-administrator opens the setup of the application")
    expect(denied(stack.browser("rtnobody").get(base)), "a user without rights opens the applications")
    return ("blank application per member type with fee, notice period, statutes and consents; own texts of the association stored and on the form; "
            "Dolibarr's member card builds it filled in and notes it at the member; a new consent version reaches the form; "
            "only who may change members builds, setup for administrators only; declaration of consent with one page per member who is "
            "missing one and one page for a single member; fields to fill in only on the document switched on")


def todo(stack: Stack) -> str:
    """What is to do: deadlines, elections and applications of the association, plus what waits for the person (#124)."""
    browser = stack.browser()
    page = page_ok(browser.get("/custom/vereine/vereineindex.php"), "the overview with what is to do")
    rows = re.findall(r'data-todo-kind="([a-z]+)" data-todo-state="([a-z]+)"', page.text)
    kinds = {kind for kind, _ in rows}
    expect(rows, "the overview shows nothing to do although deadlines and applications are open")
    # The audit of the last year that ended, and the applications waiting for a decision (#72).
    waiting = stack.value("SELECT COUNT(*) FROM llx_vereine_application WHERE status IN ('received', 'in_review')")
    expect((int(waiting) == 0) == ("application" not in kinds), f"{waiting} applications wait, kinds shown: {sorted(kinds)}")
    expect("duty" in kinds, f"the deadlines of the calendar of duties are not named: {sorted(kinds)}")
    total = re.search(r'data-todo="(\d+)"', page.text)
    expect(total is not None and int(total.group(1)) == len(rows), f"the overview counts {total.group(1) if total else None} for {len(rows)} rows")

    # The same list on the home page, as a box.
    home = page_ok(browser.get("/"), "the home page with the box")
    expect('data-box-waiting=' in home.text, "the box does not show what is to do")
    expect(denied(stack.browser("rtnobody").get("/custom/vereine/vereineindex.php")), "a user without rights opens the overview")
    return (f"{len(rows)} open points on the overview: {sorted(kinds)}; the count matches the rows, the home box shows them too, "
            "nobody without rights sees the page")


def sepaonline(stack: Stack) -> str:
    """The SEPA mandate at the member: its state from Dolibarr's data, Dolibarr's own online signature, the invitation (#125)."""
    browser = stack.browser()
    member = int(stack.value("SELECT fk_adherent FROM llx_vereine_application ORDER BY rowid LIMIT 1") or 0)
    member = member or int(stack.value("SELECT rowid FROM llx_adherent WHERE statut = 1 ORDER BY rowid LIMIT 1"))
    payer = stack.value(f"SELECT fk_soc FROM llx_adherent WHERE rowid = {member}")
    mandate = stack.sql(f"SELECT rowid, rum FROM llx_societe_rib WHERE fk_soc = {payer} AND type = 'ban' AND default_rib = 1")
    if not mandate:
        member = int(stack.value("SELECT a.rowid FROM llx_adherent as a INNER JOIN llx_societe_rib as r ON r.fk_soc = a.fk_soc"
                                 " WHERE r.type = 'ban' AND r.default_rib = 1 ORDER BY a.rowid LIMIT 1"))
        payer = stack.value(f"SELECT fk_soc FROM llx_adherent WHERE rowid = {member}")
    tab = f"/custom/vereine/member_association.php?id={member}"

    # Dolibarr's online signature for bank accounts is off at first: the tab says how to switch it on.
    stack.sql("DELETE FROM llx_const WHERE name = 'SOCIETE_RIB_ALLOW_ONLINESIGN'")
    page = page_ok(browser.get(tab), "association tab without the online signature")
    state = re.search(r'data-sepa="([a-z]+)" data-sepa-signed="(\d)"', page.text)
    expect(state is not None and 'data-sepa-online="off"' in page.text,
           f"the tab does not say how the mandate stands: {state.groups() if state else None}")
    expect('name="vereinesepainvite"' not in page.text, "the invitation is offered although Dolibarr's online signature is off")

    # Switched on, Dolibarr's own signature page is offered; the module builds none of its own.
    stack.sql("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('SOCIETE_RIB_ALLOW_ONLINESIGN', 1, '1', 'chaine', 0)")
    stack.sql("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('SOCIETE_RIB_ONLINE_SIGNATURE_SECURITY_TOKEN', 1, 'rt-token', 'chaine', 0)")
    page = page_ok(browser.get(tab), "association tab with the online signature")
    link = re.search(r'value="(https?://[^"]*newonlinesign[^"]*)"', page.text)
    expect(link is not None and "source=societe_rib" in link.group(1) and "securekey=" in link.group(1),
           f"no link to Dolibarr's signature page: {link.group(1) if link else None}")
    expect('name="vereinesepainvite"' in page.text, "the invitation is not offered although Dolibarr's online signature is on")

    # Nothing is sent by opening the tab; only the button sends, and it is noted.
    sent_before = stack.value("SELECT COUNT(*) FROM llx_vereine_log WHERE action = 'sepa_invite'")
    page_ok(browser.get(tab), "association tab opened again")
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_log WHERE action = 'sepa_invite'") == sent_before,
           "opening the tab sent the invitation")
    stack.sql(f"UPDATE llx_societe SET email = 'zahler@runtime-verein.test' WHERE rowid = {payer}")
    mail = stack.mailpit()
    mail.clear()
    page = page_ok(browser.get(tab), "association tab before the invitation")
    page_ok(browser.submit(page.form(name="vereinesepainvite")), "send the link to the payer")
    logged = stack.sql("SELECT message FROM llx_vereine_log WHERE action = 'sepa_invite' ORDER BY rowid DESC LIMIT 1")
    messages = mail.messages()
    body = " ".join((mail.message(messages[0]["ID"]).get("Text") or "").split()) if messages else ""
    expect(logged and logged[0][0] == "zahler@runtime-verein.test" and "newonlinesign" in body,
           f"the invitation was not sent or not noted: {logged}, mail {body[:160]!r}")

    status, summary = stack.api(f"vereine/members/{member}/summary", stack.notes["website"]["key"])
    mandate_state = summary.get("fee", {}).get("mandate", {}) if status == 200 else {}
    expect(status == 200 and mandate_state.get("status") in ("none", "valid", "expired", "off") and "iban" not in json.dumps(summary).lower(),
           f"the summary does not say how the fee is collected, or carries bank data: {mandate_state}")
    return (f"member {member}: mandate state from Dolibarr's own data, the tab explains how to switch the online signature on; "
            "switched on it offers Dolibarr's signature page with its secure key; opening the tab sends nothing, the button sends the "
            "link to the payer and notes it; the summary names the state of the mandate without any bank data")


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
    today = stack.today()
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


def erasure(stack: Stack) -> str:
    """Erasing after the exit: a preview kind by kind, the hold, only what is due goes, a second run does nothing, the name last (#10)."""
    browser = stack.browser()
    key = stack.notes["website"]["key"]
    setup = page_ok(browser.get("/custom/vereine/admin/privacy.php"), "data protection setup")
    expect('data-erasure-kinds="15"' in setup.text and 'data-erasure-kind="bookkeeping" data-erasure-years="7"' in setup.text,
           "the setup does not list the kinds with their periods")
    refused = page_ok(browser.submit(setup.form(name="vereineprivacy"), {"years_consents": "40"}), "40 years for consents")
    expect(stack.const("VEREINE_ERASURE_PERIODS") is None and "0 bis 30" in html.unescape(refused.text), "40 years were stored or not explained")
    setup = page_ok(browser.get("/custom/vereine/admin/privacy.php"), "data protection setup again")
    page_ok(browser.submit(setup.form(name="vereineprivacy"), {"years_tasks": "0"}), "no waiting for tasks")
    expect(json.loads(stack.const("VEREINE_ERASURE_PERIODS") or "{}").get("tasks") == 0, f"stored periods: {stack.const('VEREINE_ERASURE_PERIODS')}")

    fixture = stack.php_fixture("erasuremember")
    member = int(fixture["member"])
    tab = f"/custom/vereine/member_association.php?id={member}"

    def states() -> dict:
        page = page_ok(browser.get(tab), "the former member's tab")
        return dict(re.findall(r'data-erasure-kind="(\w+)" data-erasure-state="(\w+)"', page.text))

    before = states()
    expected = {"identities": "due", "contact": "due", "invitations": "due", "consents": "due", "disclosures": "due", "log": "due",
                "applications": "none", "bookkeeping": "kept", "records": "kept", "name": "waiting"}
    expect(all(before.get(kind) == state for kind, state in expected.items()), f"preview of a member gone four years: {before}")
    member_tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={stack.value('SELECT MIN(rowid) FROM llx_adherent WHERE statut = 1')}"), "an active member's tab")
    expect('data-erasure="member"' in member_tab.text, "an active member is offered for erasing")

    page = page_ok(browser.get(tab), "the tab to hold")
    refused = page_ok(browser.submit(page.form(name="vereineerasurehold"), {"hold_note": ""}), "a hold without a reason")
    expect("warum das Löschen gesperrt" in html.unescape(refused.text), "a hold without a reason was taken")
    page = page_ok(browser.get(tab), "the tab to hold again")
    page_ok(browser.submit(page.form(name="vereineerasurehold"), {"hold_note": "Verfahren beim Schiedsgericht"}), "hold the erasure")
    held = states()
    expect(set(held[kind] for kind in expected if expected[kind] == "due") == {"held"}, f"on hold: {held}")
    page = page_ok(browser.get(tab), "the tab on hold")
    page_ok(browser.post(tab, [("token", token_of(page)), ("action", "confirm_erase"), ("confirm", "yes")]), "erase while on hold")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_vereine_erasure WHERE fk_adherent = {member} AND kind = 'run'") == "0", "a run went through while on hold")
    page_ok(browser.submit(page_ok(browser.get(tab), "the tab to release").form(name="vereineerasurerelease")), "lift the hold")

    page = page_ok(browser.get(f"{tab}&action=erase&token={token_of(page_ok(browser.get(tab), 'the tab to erase'))}"), "the question before erasing")
    expect("rückgängig" in html.unescape(page.text), "no question before erasing")
    last_log = int(stack.value("SELECT IFNULL(MAX(rowid), 0) FROM llx_vereine_log") or 0)
    # Two changes of a member in the same second are one entry of the feed; the erasure counts from its own second on.
    since = stack.value("SELECT DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s')")
    feed = (f"SELECT COUNT(*) FROM llx_vereine_change WHERE object_type = 'membership' AND object_id = {member} AND change_kind = 'updated'"
            f" AND occurred_at >= '{since}'")
    page_ok(browser.post(tab, [("token", token_of(page)), ("action", "confirm_erase"), ("confirm", "yes")]), "erase what is due")
    kept = stack.sql(f"SELECT lastname, IFNULL(email, '-'), IFNULL(address, '-'), IFNULL(birth, '-') FROM llx_adherent WHERE rowid = {member}")
    expect(kept == [["Vergessen", "-", "-", "-"]], f"the member after the first run: {kept}")
    left = {table: stack.value(f"SELECT COUNT(*) FROM llx_vereine_{table} WHERE fk_adherent = {member}") for table in ("identity", "consent", "disclosure", "social")}
    expect(left == {"identity": "0", "consent": "0", "disclosure": "0", "social": "0"}, f"rows left after the first run: {left}")
    expect(stack.value(f"SELECT IFNULL(socialnetworks, '-') FROM llx_adherent WHERE rowid = {member}") == "-", "the member's accounts are still there")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_vereine_meeting_invitation WHERE fk_adherent = {member} AND email IS NOT NULL") == "0"
           and stack.value(f"SELECT name FROM llx_vereine_meeting_invitation WHERE fk_adherent = {member}") == "Emil Vergessen",
           "the invitation kept its address or lost its name")
    expect(stack.shell(f"test -e '{fixture['scan']}'").returncode != 0, "the scan of the consent is still there")
    actions = [row[0] for row in stack.sql(f"SELECT action FROM llx_vereine_log WHERE fk_adherent = {member} AND rowid <= {last_log} ORDER BY rowid")]
    expect(set(actions) == {"erasure_hold"}, f"the log of the member from before the run: {actions}")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_vereine_log WHERE fk_adherent = {member} AND action = 'erasure'") == "1", "the run was not logged")
    run = json.loads(stack.value(f"SELECT done FROM llx_vereine_erasure WHERE fk_adherent = {member} AND kind = 'run'") or "{}")
    expect(set(run) == {"identities", "contact", "invitations", "consents", "disclosures", "log"} and "Vergessen" not in json.dumps(run), f"the run kept: {run}")
    expect(int(stack.value(feed) or 0) > 0, "the change feed did not learn about the erasure")
    status, summary = stack.api(f"vereine/members/{member}/summary", key)
    expect(status == 200, f"the summary of the erased member: HTTP {status} {summary}")

    page = page_ok(browser.get(tab), "the tab after the first run")
    expect('data-erasure-due="0"' in page.text, "something is still due after the run")
    page_ok(browser.post(tab, [("token", token_of(page)), ("action", "confirm_erase"), ("confirm", "yes")]), "erase again")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_vereine_erasure WHERE fk_adherent = {member} AND kind = 'run'") == "1", "a second run without anything due was kept")

    # Once the bookkeeping is past its seven years, the name goes as well.
    stack.sql(f"UPDATE llx_subscription SET dateadh = DATE_SUB(dateadh, INTERVAL 5 YEAR), datef = DATE_SUB(datef, INTERVAL 5 YEAR) WHERE fk_adherent = {member}")
    expect(states().get("name") == "due", "the name is not due once the bookkeeping is past")
    page = page_ok(browser.get(tab), "the tab before the name goes")
    page_ok(browser.post(tab, [("token", token_of(page)), ("action", "confirm_erase"), ("confirm", "yes")]), "erase the name")
    name = stack.sql(f"SELECT lastname, IFNULL(firstname, '-'), IFNULL(login, '-') FROM llx_adherent WHERE rowid = {member}")
    expect(name == [["Anonymisiert", "-", "-"]], f"the name after the second run: {name}")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_subscription WHERE fk_adherent = {member}") == "1", "the fee was touched")
    return (f"15 kinds listed, 40 years refused; the member gone four years ago had {sum(1 for state in before.values() if state == 'due')} kinds due; "
            "on hold nothing went; the run emptied contact data, took the binding back, deleted consent with scan, access record and log, "
            "kept the invitation's name, told the change feed; a second run did nothing; the name went once the fee was past seven years")


# The published release of the Mahnwesen module the integration is tested with, and its published checksum (#17).
MAHNWESEN_RELEASE = ("1.5.0", "de0f8aaa0e4d83efb1575f4c676eaa86e928c729c6b9c4f1ec2db13b577b062f")


def mahnwesen_package() -> Path:
    """The Mahnwesen release, fetched once from its GitHub release and checked against its checksum."""
    version, digest = MAHNWESEN_RELEASE
    target = Path(__file__).resolve().parents[2] / ".local-testing" / "mahnwesen" / f"module_mahnwesen-{version}.zip"
    if not target.is_file() or hashlib.sha256(target.read_bytes()).hexdigest() != digest:
        target.parent.mkdir(parents=True, exist_ok=True)
        url = f"https://github.com/Tabsi1998/dolibarr-mahnwesen/releases/download/v{version}/module_mahnwesen-{version}.zip"
        with urllib.request.urlopen(url, timeout=120) as response:
            target.write_bytes(response.read())
    expect(hashlib.sha256(target.read_bytes()).hexdigest() == digest, f"{target.name} does not match its published checksum")
    return target


def arrears(stack: Stack) -> str:
    """Both modules together: a fee at the last dunning step becomes one proposal for the board, a sale does not; payment, pause
    and late events update it; only a board meeting takes it; nobody is excluded (#17)."""
    browser = stack.browser()
    package = mahnwesen_package()
    page = page_ok(browser.get("/admin/modules.php?mode=deploy"), "deploy page")
    form = page.form(name="forminstall")
    fields = [(name, value) for name, value in form.values() if name != "checkforcompliance"]
    result = browser.post_multipart(form.url(), fields, [("fileinstall", package.name, package.read_bytes())])
    expect(result.status == 200 and not result.errors(), f"uploading {package.name}: HTTP {result.status} {result.errors()}")
    expect(stack.shell("test -f /var/www/html/custom/mahnwesen/core/modules/modMahnwesen.class.php").returncode == 0, f"{package.name} was not deployed")
    stack.php_fixture("mahnwesen")
    people = stack.php_fixture("arrearmembers")
    moritz, nina, sale = people["moritz"], people["nina"], people["sale"]

    def event(invoice: int, kind: str = "", pay: str = "") -> dict:
        extra = {"RT_INVOICE_ID": str(invoice), **({"RT_TYPE": kind} if kind else {}), **({"RT_PAY": pay} if pay else {})}
        return stack.php_fixture("arrearevent", **extra)

    def kept(invoice: int) -> list[list[str]]:
        return stack.sql(f"SELECT state, fk_meeting FROM llx_vereine_arrear WHERE fk_facture = {invoice}")

    for invoice in (moritz["invoice"], sale["invoice"]):
        event(invoice, "MAHNWESEN_CASE_FINAL_STAGE")
    expect(kept(moritz["invoice"]) == [["open", "0"]] and kept(sale["invoice"]) == [],
           f"after the last step: fee {kept(moritz['invoice'])}, sale of the same member {kept(sale['invoice'])}")
    event(moritz["invoice"], "MAHNWESEN_CASE_FINAL_STAGE")
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_arrear") == "1", "the last step again made a second proposal")
    event(nina["invoice"], "MAHNWESEN_CASE_FINAL_STAGE", pay="before")
    expect(kept(nina["invoice"]) == [], "a fee paid before the event still went to the board")
    case = stack.value(f"SELECT fk_case FROM llx_vereine_arrear WHERE fk_facture = {moritz['invoice']}") or "0"
    late = stack.php_fixture("arrearstale", RT_CASE_ID=case, RT_INVOICE_ID=str(moritz["invoice"]))
    expect(late.get("result") == 0 and kept(moritz["invoice"])[0][0] == "open", f"a late close: {late}, then {kept(moritz['invoice'])}")
    arrear = int(stack.value(f"SELECT rowid FROM llx_vereine_arrear WHERE fk_facture = {moritz['invoice']}") or 0)

    index = page_ok(browser.get("/custom/vereine/vereineindex.php"), "overview")
    todo = re.search(r'data-todo-kind="arrear".*?</tr>', index.text, re.S)
    expect(todo is not None and moritz["name"] not in html.unescape(todo.group(0)), "the to-do list does not count the arrear, or names the member")

    page = page_ok(browser.get("/custom/vereine/meetings.php?template=general"), "a new general assembly")
    expect(f'data-arrear="{arrear}"' in page.text, "the meeting form does not offer the arrear")
    refused = page_ok(browser.submit(page.form(name="vereinemeeting"), {"arrear[]": str(arrear)}), "the arrear on a general assembly")
    expect("nur auf die Tagesordnung einer Vorstandssitzung" in html.unescape(refused.text) and kept(moritz["invoice"])[0][1] == "0",
           "the arrear went on the agenda of a general assembly")
    page = page_ok(browser.get("/custom/vereine/meetings.php?template=board"), "a new board meeting")
    page_ok(browser.submit(page.form(name="vereinemeeting"), {"arrear[]": str(arrear), "time": "19:00", "place": "Vereinsheim"}), "the arrear on a board meeting")
    meeting = int(kept(moritz["invoice"])[0][1])
    board = html.unescape(page_ok(browser.get(f"/custom/vereine/meetings.php?id={meeting}"), "the board meeting").text) if meeting else ""
    expect(f"Beitragsrückstand von {moritz['name']}" in board and moritz["ref"] in board, f"the agenda of board meeting {meeting} lacks the arrear")
    expect("data-arrear=" not in page_ok(browser.get("/custom/vereine/meetings.php?template=board"), "the next board meeting").text,
           "the arrear is offered again after it went on an agenda")

    states = []
    for kind in ("MAHNWESEN_CASE_PAUSED", "MAHNWESEN_CASE_RESUMED"):
        event(moritz["invoice"], kind)
        states.append(kept(moritz["invoice"])[0][0])
    event(moritz["invoice"], pay="close")
    states.append(kept(moritz["invoice"])[0][0])
    expect(states == ["paused", "open", "settled"], f"pause, resume, payment: {states}")
    expect(stack.value(f"SELECT statut FROM llx_adherent WHERE rowid = {moritz['member']}") == "1", "dunning took the membership away")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={moritz['member']}"), "Moritz's tab")
    expect('data-arrear-state="settled"' in tab.text, "the member's tab does not show the settled arrear")
    status, summary = stack.api(f"vereine/members/{moritz['member']}/summary", stack.notes["website"]["key"])
    carried = json.dumps(summary).lower()
    expect(status == 200 and "mahn" not in carried and "dunning" not in carried, f"the member summary carries the dunning file: {summary}")
    return (f"Mahnwesen {MAHNWESEN_RELEASE[0]} deployed next to this module; the last step made one proposal for the fee and none for the sale "
            "of the same member, none for a fee paid first, none twice; a late event changed nothing; the to-do list counts without a name; "
            "a general assembly refused it, a board meeting took it; pause, resume and payment followed; the member stayed a member")


def social(stack: Stack) -> str:
    """Channels of the association and accounts of members: two streams and a live stream in order, an own network, the
    application asks, an app confirms, a changed name loses the confirmation, a binding without the ability gets nothing (#233)."""
    browser = stack.browser()
    setup = "/custom/vereine/admin/social.php"
    expect(denied(stack.browser("rtreader").get(setup)), "a non-administrator opens channels and accounts")

    # A network the dictionary lacks.
    page = page_ok(browser.get(setup), "channels and accounts")
    refused = page_ok(browser.submit(page.form(name="vereinesocialnetwork"), {"code": "Steam!", "label": "Steam", "pattern": ""}), "a wrong code")
    expect("Kleinbuchstaben" in html.unescape(refused.text), "a wrong code of a network was taken")
    page = page_ok(browser.get(setup), "channels and accounts again")
    page_ok(browser.submit(page.form(name="vereinesocialnetwork"), {"code": "steam", "label": "Steam", "pattern": "https://steamcommunity.com/id/{socialid}"}), "add Steam")
    expect(stack.value("SELECT active FROM llx_c_socialnetworks WHERE code = 'steam' AND entity = 1") == "1", "Steam is not in Dolibarr's dictionary")

    # Channels: two Twitch streams, a YouTube live stream, a Discord server, one kept from the website.
    for fields in ({"network": "twitch", "label": "Hauptstream", "target": "lionsquad", "position": "10", "stream": "1", "public": "1"},
                   {"network": "twitch", "label": "CS2", "target": "https://www.twitch.tv/lionsquad_cs", "position": "20", "stream": "1", "public": "1"},
                   {"network": "youtube", "label": "Livestream", "target": "lionsquad", "position": "20", "stream": "1", "public": "1"},
                   {"network": "discord", "label": "Community", "target": "https://discord.gg/lionsquad", "position": "30", "public": "1"},
                   {"network": "twitch", "label": "Probe", "target": "lionsquad_test", "position": "5"}):
        form = page_ok(browser.get(setup), "the channel form").form(name="vereinechannel")
        page_ok(browser.submit(form, fields, drop=("stream", "public")), f"add the channel {fields['label']}")
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_channel") == "5", "not every channel was stored")
    status, organization = stack.api("vereine/organization", stack.reader_key)
    channels = organization.get("channels", []) if status == 200 else []
    expect([channel["label"] for channel in channels] == ["Hauptstream", "CS2", "Livestream", "Community"], f"the channels in the API: {channels}")
    expect(channels[0]["url"] == "https://www.twitch.tv/lionsquad" and channels[0]["live_url"] == "https://www.twitch.tv/lionsquad"
           and channels[2]["url"] == "https://www.youtube.com/@lionsquad" and channels[2]["live_url"] == "https://www.youtube.com/@lionsquad/live"
           and channels[3]["stream"] is False and channels[3]["live_url"] == "", f"addresses of the channels: {channels}")
    overview = page_ok(browser.get("/custom/vereine/vereineindex.php"), "overview")
    expect("Hauptstream" in overview.text and "Probe" in overview.text and "nicht öffentlich" in html.unescape(overview.text),
           "the overview does not show every channel")

    # Which accounts the application asks for.
    page = page_ok(browser.get(setup), "accounts to ask")
    page_ok(browser.submit(page.form(name="vereinesocialasked"), {"asked_discord": "required", "asked_twitch": "optional", "asked_steam": "optional"}),
            "ask for Discord, Twitch and Steam")
    expect(json.loads(stack.const("VEREINE_SOCIAL_ASKED") or "{}") == {"discord": "required", "twitch": "optional", "steam": "optional"},
           f"stored: {stack.const('VEREINE_SOCIAL_ASKED')}")
    expect(stack.value("SELECT active FROM llx_c_socialnetworks WHERE code = 'discord' AND entity = 1") == "1", "Discord was not switched on for the member card")
    key = stack.notes["applicationkey"]
    status, form = stack.api("vereine/applicationform", key)
    expect(status == 200 and {account["network"]: account["required"] for account in form["accounts"]} == {"discord": True, "twitch": False, "steam": False},
           f"the web is told other accounts: {form.get('accounts')}")
    type_id = int(stack.value("SELECT rowid FROM llx_adherent_type WHERE libelle = 'Beitragspflichtig'"))
    body = {"firstname": "Sina", "lastname": "Stream", "email": "sina.stream@runtime-verein.test", "type_id": type_id, "birth": "2001-02-03",
            "address": "Teststraße 5", "zip": "6020", "town": "Innsbruck", "fields": {"gamertag": "SinaTV", "spielstaerke": "profi"}}
    for accounts, message in (({"twitch": "sina_tv"}, "accounts.discord is required"), ({"discord": "sina", "myspace": "x"}, "accounts.myspace is not asked")):
        status, answer = stack.api("vereine/applications", key, method="POST", data={**body, "accounts": accounts})
        expect(status == 400 and message in json.dumps(answer), f"application with {accounts}: HTTP {status} {answer}")
    status, created = stack.api("vereine/applications", key, method="POST", data={**body, "accounts": {"discord": "sina#7", "twitch": "sina_tv"}})
    expect(status == 200, f"a complete application was refused: HTTP {status} {created}")
    applicant = int(stack.value("SELECT rowid FROM llx_adherent WHERE lastname = 'Stream'"))
    stored = json.loads(stack.value(f"SELECT socialnetworks FROM llx_adherent WHERE rowid = {applicant}") or "{}")
    expect(stored == {"discord": "sina#7", "twitch": "sina_tv"}, f"the accounts did not reach the member: {stored}")

    # An app links and confirms an account of a member it is bound to.
    client = secrets.token_hex(16)
    stack.php_fixture("apiclient", RT_LOGIN="rtlinks", RT_CLIENT_KEY=client)
    member, other = (int(row[0]) for row in stack.sql("SELECT rowid FROM llx_adherent WHERE statut = 1 ORDER BY rowid LIMIT 2"))
    codes = {}
    for subject, capability, person in (("sub-links", "accounts", member), ("sub-nolinks", "consents", other)):
        page = page_ok(browser.get("/custom/vereine/admin/identities.php"), "the identities")
        page = page_ok(browser.submit(page.form(name="vereineidentityinvite"), {"client": "rtlinks", "member_id": str(person), "application_id": "0",
                                                                                 "capabilities[]": capability}), f"invite for {capability}")
        codes[subject] = re.search(r"<code>([A-Za-z0-9_-]{30,})</code>", page.text).group(1)
        status, bound = stack.api(f"vereine/identities/claim?subject={subject}&code={codes[subject]}", client, method="POST")
        expect(status == 200 and bound["capabilities"] == [capability], f"binding {subject}: HTTP {status} {bound}")
    status, mine = stack.api("vereine/me/accounts?subject=sub-links", client)
    expect(status == 200 and {"discord", "twitch", "steam"} <= {account["network"] for account in mine}, f"the member's accounts: HTTP {status} {mine}")
    status, after = stack.api("vereine/me/accounts/twitch?subject=sub-links", client, method="PUT",
                              data={"handle": "lion_tv", "confirmed": True, "external_id": "98765"})
    twitch = next((account for account in after if account["network"] == "twitch"), {}) if status == 200 else {}
    expect(twitch.get("handle") == "lion_tv" and twitch.get("confirmed") is True and twitch.get("client") == "rtlinks"
           and twitch.get("url") == "https://www.twitch.tv/lion_tv", f"the confirmed account: HTTP {status} {twitch}")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={member}"), "the member's tab")
    expect('data-social-account="twitch" data-social-confirmed="1"' in tab.text, "the member's tab does not show the confirmed account")
    status, refused = stack.api("vereine/me/accounts/myspace?subject=sub-links", client, method="PUT", data={"handle": "x"})
    expect(status == 400, f"an unknown network was taken: HTTP {status}")
    status, refused = stack.api("vereine/me/accounts?subject=sub-nolinks", client)
    expect(status == 403, f"a binding without the ability read accounts: HTTP {status}")

    # Somebody changes the name on the member card: the confirmation no longer holds.
    stack.sql(f"UPDATE llx_adherent SET socialnetworks = JSON_SET(socialnetworks, '$.twitch', 'lion_tv2') WHERE rowid = {member}")
    status, changed = stack.api("vereine/me/accounts?subject=sub-links", client)
    twitch = next((account for account in changed if account["network"] == "twitch"), {})
    expect(twitch.get("handle") == "lion_tv2" and twitch.get("confirmed") is False, f"after the name changed: {twitch}")
    status, cleared = stack.api("vereine/me/accounts/twitch?subject=sub-links", client, method="DELETE")
    twitch = next((account for account in cleared if account["network"] == "twitch"), {}) if status == 200 else {}
    expect(twitch.get("handle") == "" and stack.value(f"SELECT COUNT(*) FROM llx_vereine_social WHERE fk_adherent = {member}") == "0",
           f"unlinking left the account: HTTP {status} {twitch}")

    # Later applications must not need Discord.
    page = page_ok(browser.get(setup), "accounts to ask at the end")
    page_ok(browser.submit(page.form(name="vereinesocialasked"), {"asked_discord": "optional"}), "Discord optional again")
    return ("Steam added; two Twitch streams, a YouTube live stream and a Discord server in the order of the association, the one kept from the "
            "website missing in the API; the application asks Discord (required), Twitch and Steam and the member got them; an app confirmed "
            "Twitch, the tab shows it, a changed name lost it, unlinking removed it; no ability, no accounts")


def honours(stack: Stack) -> str:
    """Honours and statistics: a jubilee of ten years kept once, birthdays only with consent, an award, an honorary member with
    the member type for it, the certificate as PDF, members on a day by group and as a file (#27, #28)."""
    browser = stack.browser()
    base = "/custom/vereine/honours.php"
    expect(denied(stack.browser("rtnobody").get(base)), "a user without rights opens the honours")
    fixture = stack.php_fixture("honourmembers")
    members, year = fixture["members"], fixture["year"]
    page = page_ok(browser.get(base), "honours")
    expect('data-birthdays="off"' in page.text, "birthdays are listed before a purpose of consent is chosen")
    page_ok(browser.submit(page.form(name="vereinehonoursettings"), {"milestones": "25, 10", "birthday_consent": fixture["code"],
                                                                       "honorary_type": str(fixture["type"]), "ages": "14, 18, 26, 40, 60"}), "the settings")
    expect(stack.const("VEREINE_HONOUR_MILESTONES") == "10,25" and stack.const("VEREINE_HONORARY_TYPE") == str(fixture["type"]),
           f"stored: {stack.const('VEREINE_HONOUR_MILESTONES')}, {stack.const('VEREINE_HONORARY_TYPE')}")

    page = page_ok(browser.get(f"{base}?year={year}"), "honours of the year")
    expect(f'data-jubilee="{members["hannah"]}" data-jubilee-years="10" data-jubilee-honoured="0"' in page.text, "Hannah's ten years are not listed")
    expect(f'data-birthday="{members["ben"]}"' in page.text and f'data-birthday="{members["clara"]}"' not in page.text,
           "the birthdays do not follow the consent")
    page_ok(browser.submit(page.form(name=f"vereinejubilee{members['hannah']}"), {"given_on": f"{year}-04-20"}), "honour Hannah's ten years")
    page = page_ok(browser.get(f"{base}?year={year}"), "honours after the jubilee")
    expect(f'data-jubilee="{members["hannah"]}" data-jubilee-years="10" data-jubilee-honoured="1"' in page.text
           and f'name="vereinejubilee{members["hannah"]}"' not in page.text, "the jubilee can be honoured twice")
    page_ok(browser.submit(page.form(name="vereinehonouraward"), {"member": str(members["ben"]), "label": "Turniersieg Frühjahr", "given_on": f"{year}-05-01"}),
            "an award for Ben")
    page = page_ok(browser.get(f"{base}?year={year}"), "honours before the honorary membership")
    page_ok(browser.submit(page.form(name="vereinehonourhonorary"), {"member": str(members["hannah"]), "given_on": f"{year}-04-20"}), "Hannah honorary member")
    kept = stack.sql(f"SELECT kind, years, IFNULL(label, '') FROM llx_vereine_honour WHERE fk_adherent IN ({members['hannah']}, {members['ben']}) ORDER BY rowid")
    expect(kept == [["jubilee", "10", ""], ["award", "0", "Turniersieg Frühjahr"], ["honorary", "0", ""]], f"honours kept: {kept}")
    expect(stack.value(f"SELECT fk_adherent_type FROM llx_adherent WHERE rowid = {members['hannah']}") == str(fixture["type"]),
           "the honorary member did not move to the member type for honorary members")

    honour = stack.value(f"SELECT rowid FROM llx_vereine_honour WHERE fk_adherent = {members['hannah']} AND kind = 'jubilee'")
    page = page_ok(browser.get(f"{base}?year={year}"), "honours before the certificate")
    certificate = browser.get(f"{base}?year={year}&action=certificate&id={honour}&token={token_of(page)}")
    text = pdf_bytes_text(certificate.body) if certificate.body[:4] == b"%PDF" else ""
    expect("Urkunde" in text and "Hannah Ehrung" in text and "10 Jahre" in text, f"the certificate: {text[:300]!r}")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={members['hannah']}"), "Hannah's tab")
    expect('data-member-honour="jubilee"' in tab.text and 'data-member-honour="honorary"' in tab.text, "the member's tab does not show the honours")

    # Members on a day, counted and as a file.
    today = stack.today()
    page = page_ok(browser.get(f"/custom/vereine/statistics.php?day={today}"), "statistics")
    total = int(re.search(r'data-statistics-total="(\d+)"', page.text).group(1))
    active = int(stack.value("SELECT COUNT(*) FROM llx_adherent WHERE statut = 1") or 0)
    expect(total >= 3 and total >= active, f"members on {today}: {total}, active now {active}")
    expect("Ehrenmitglied" in page.text and "männlich" in html.unescape(page.text) and "bis 14" in page.text, "the statistics lack a group")
    csv = browser.get(f"/custom/vereine/statistics.php?day={today}&action=csv&token={token_of(page)}")
    expect(csv.body[:3] == b"\xef\xbb\xbf" and "Mitglieder gesamt;" in csv.body.decode("utf-8") and "Hannah" not in csv.body.decode("utf-8"),
           f"the file: {csv.body[:200]!r}")
    return (f"ten years of Hannah listed and honoured once; birthdays only for Ben who agreed; an award; Hannah honorary member with its "
            f"member type; the certificate names her and the ten years; {total} members on {today} by type, gender, age and division, as CSV without names")


def inventory(stack: Stack) -> str:
    """Equipment of the association in Dolibarr's resources: lend with state, no second loan, late and reminded once a week to the
    borrower only, back with state, the reservation for an event shown (#26)."""
    browser = stack.browser()
    base = "/custom/vereine/inventory.php"
    expect(denied(stack.browser("rtnobody").get(base)), "a user without rights opens the inventory")
    page = page_ok(browser.get(base), "inventory without the module Resources")
    expect('data-inventory="module-off"' in page.text or "Ressourcen" in html.unescape(page.text), "the inventory does not say the module Resources is off")
    fixture = stack.php_fixture("inventory")
    pc, headset = fixture["resources"]["pc"], fixture["resources"]["headset"]
    page = page_ok(browser.get(base), "inventory")
    expect(f'data-resource="{pc}" data-loan-state="available"' in page.text and f'data-resource="{headset}" data-loan-state="available"' in page.text,
           "the equipment is not listed as available")
    expect("LAN-Party" in page.text, "the reservation of the PC for the event is not shown")
    member, email = stack.sql("SELECT rowid, email FROM llx_adherent WHERE statut = 1 AND COALESCE(email, '') <> '' ORDER BY rowid LIMIT 1")[0]
    today = stack.today()
    page_ok(browser.submit(page.form(name="vereinelend"), {"resource": str(headset), "member": member, "issued_on": today, "due_on": today,
                                                          "condition": "vollständig, mit Mikrofon"}), "lend the headset")
    page = page_ok(browser.get(base), "inventory after lending")
    expect(f'data-resource="{headset}" data-loan-state="out"' in page.text, "the headset is not shown as lent")
    refused = page_ok(browser.submit(page.form(name="vereinelend"), {"resource": str(headset), "member": member, "issued_on": today, "due_on": today}),
                      "lend the headset again")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_vereine_loan WHERE fk_resource = {headset}") == "1", "the headset was lent twice")

    # Late: a reminder to the borrower's own address, and not again the next day.
    stack.sql(f"UPDATE llx_vereine_loan SET issued_on = DATE_SUB(issued_on, INTERVAL 20 DAY), due_on = DATE_SUB(due_on, INTERVAL 6 DAY) WHERE fk_resource = {headset}")
    page = page_ok(browser.get(base), "inventory with a late loan")
    expect(f'data-resource="{headset}" data-loan-state="overdue"' in page.text, "the late loan is not shown as overdue")
    todo = re.search(r'data-todo-kind="loan".*?</tr>', page_ok(browser.get("/custom/vereine/vereineindex.php"), "overview").text, re.S)
    name = stack.value(f"SELECT CONCAT(firstname, ' ', lastname) FROM llx_adherent WHERE rowid = {member}")
    expect(todo is not None and name not in html.unescape(todo.group(0)), "the to-do list does not count the late loan, or names the borrower")
    mailpit = stack.mailpit()
    mailpit.clear()
    first = stack.php_fixture("runloans")
    second = stack.php_fixture("runloans")
    reminders = [message for message in mailpit.messages() if "zurückgeben" in (message.get("Subject") or "")]
    addresses = sorted({to["Address"].lower() for message in reminders for to in message.get("To") or []})
    expect(first.get("failed") == 0 and len(reminders) == 1 and addresses == [email.lower()],
           f"reminders: {len(reminders)} to {addresses}, expected one to {email}; jobs {first}, {second}")

    page = page_ok(browser.get(base), "inventory before the return")
    loan = stack.value(f"SELECT rowid FROM llx_vereine_loan WHERE fk_resource = {headset}")
    page_ok(browser.submit(page.form(name=f"vereinegiveback{loan}"), {"returned_on": today, "condition": "Kabel geknickt"}), "take the headset back")
    kept = stack.sql(f"SELECT returned_on IS NOT NULL, condition_out, condition_in FROM llx_vereine_loan WHERE rowid = {loan}")
    expect(kept == [["1", "vollständig, mit Mikrofon", "Kabel geknickt"]], f"the loan after the return: {kept}")
    page = page_ok(browser.get(base), "inventory after the return")
    expect(f'data-resource="{headset}" data-loan-state="available"' in page.text, "the headset is not available again")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={member}"), "the borrower's tab")
    expect('data-member-loan="returned"' in tab.text, "the member's tab does not show the loan")
    return ("PC and headset from Dolibarr's resources, the PC reserved for the LAN-Party; the headset lent with its state, not twice; "
            "late, one reminder to the borrower only, none the next day; the to-do list counts without a name; back with its state, available again")


def documents(stack: Stack) -> str:
    """Publishing documents: nothing by default, a signed revision goes out by itself for members, by hand for the public, once;
    withdrawn is gone; the app of a member and the website get exactly what is theirs; a changed file is an error (#156, #157)."""
    browser = stack.browser()
    base = "/custom/vereine/archive.php"
    documents_ = stack.sql("SELECT d.rowid, d.kind FROM llx_vereine_document d WHERE EXISTS (SELECT 1 FROM llx_vereine_document_file f WHERE f.fk_document = d.rowid)"
                           " AND d.kind <> 'statute' ORDER BY d.rowid LIMIT 2")
    expect(len(documents_) == 2, f"the files have too few documents for the test: {documents_}")
    (members_doc, members_kind), (public_doc, public_kind) = ((int(row[0]), row[1]) for row in documents_)
    expect(stack.value("SELECT COUNT(*) FROM llx_vereine_publication") == "0", "something was published before anybody said so")

    # The app of an active member, bound with the ability documents; another binding without it.
    client = secrets.token_hex(16)
    stack.php_fixture("apiclient", RT_LOGIN="rtdocs", RT_CLIENT_KEY=client)
    member, other = (int(row[0]) for row in stack.sql("SELECT rowid FROM llx_adherent WHERE statut = 1 ORDER BY rowid LIMIT 2"))
    for subject, capability, person in (("sub-docs", "documents", member), ("sub-nodocs", "consents", other)):
        page = page_ok(browser.get("/custom/vereine/admin/identities.php"), "the identities")
        page = page_ok(browser.submit(page.form(name="vereineidentityinvite"), {"client": "rtdocs", "member_id": str(person), "application_id": "0",
                                                                                 "capabilities[]": capability}), f"invite for {capability}")
        code = re.search(r"<code>([A-Za-z0-9_-]{30,})</code>", page.text).group(1)
        status, bound = stack.api(f"vereine/identities/claim?subject={subject}&code={code}", client, method="POST")
        expect(status == 200, f"binding {subject}: HTTP {status} {bound}")
    status, mine = stack.api("vereine/me/documents?subject=sub-docs", client)
    expect(status == 200 and mine == [], f"the member sees documents nobody published: HTTP {status} {mine}")
    status, refused = stack.api("vereine/me/documents?subject=sub-nodocs", client)
    expect(status == 403, f"a binding without the ability read documents: HTTP {status}")

    # A rule: the kind goes out for members by itself once signed.
    page = page_ok(browser.get(base), "the files")
    page_ok(browser.submit(page.form(name="vereinepublishrules"), {f"audience_{members_kind}": "members", f"auto_{members_kind}": "1"}), "the rule")
    signed = stack.php_fixture("signedcopy", RT_DOCUMENT_ID=str(members_doc))
    status, mine = stack.api("vereine/me/documents?subject=sub-docs", client)
    entry = next((row for row in mine if row["document_id"] == members_doc), None) if status == 200 else None
    expect(entry is not None and entry["what"] == "signed" and entry["sha256"] == signed["sha256"] and entry["audience"] == "members",
           f"the signed revision did not go out for members: HTTP {status} {mine}")
    status, pdf = stack.api(f"vereine/me/documents/{members_doc}/pdf?subject=sub-docs", client)
    delivered = base64.b64decode(pdf["content"]) if status == 200 else b""
    expect(hashlib.sha256(delivered).hexdigest() == signed["sha256"] == pdf.get("sha256"), f"the PDF for the member: HTTP {status}")
    status, public = stack.api("vereine/documents", stack.reader_key)
    expect(status == 200 and all(row["document_id"] != members_doc for row in public), f"a document for members is public: {public}")
    status, _ = stack.api(f"vereine/documents/{members_doc}/pdf", stack.reader_key)
    expect(status == 404, f"the public got a document for members: HTTP {status}")

    # By hand for the public, twice: one publication.
    for _ in range(2):
        page = page_ok(browser.get(base), "the files before publishing")
        page_ok(browser.submit(page.form(name=f"vereinepublish{public_doc}"), {"audience": "public"}), "publish for the public")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_vereine_publication WHERE fk_document = {public_doc}") == "1", "publishing twice made two publications")
    status, public = stack.api("vereine/documents", stack.reader_key)
    expect(status == 200 and [row["document_id"] for row in public] == [public_doc], f"the public list: {public}")
    status, pdf = stack.api(f"vereine/documents/{public_doc}/pdf", stack.reader_key)
    expect(status == 200 and hashlib.sha256(base64.b64decode(pdf["content"])).hexdigest() == public[0]["sha256"], f"the public PDF: HTTP {status}")

    # Withdrawn: gone at once, still in the files.
    publication = stack.value(f"SELECT rowid FROM llx_vereine_publication WHERE fk_document = {public_doc} AND withdrawn_at IS NULL")
    page = page_ok(browser.get(base), "the files before withdrawing")
    page_ok(browser.submit(page.form(name=f"vereinewithdraw{publication}")), "withdraw")
    status, public = stack.api("vereine/documents", stack.reader_key)
    status_pdf, _ = stack.api(f"vereine/documents/{public_doc}/pdf", stack.reader_key)
    expect(status == 200 and public == [] and status_pdf == 404, f"after withdrawing: {public}, PDF HTTP {status_pdf}")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_vereine_document_file WHERE fk_document = {public_doc}") != "0", "withdrawing removed the file from the files")

    # A file changed on the disk is an error, never other bytes.
    stack.shell(f"printf 'x' >> '{signed['file']}'")
    status, broken = stack.api(f"vereine/me/documents/{members_doc}/pdf?subject=sub-docs", client)
    expect(status == 500, f"a changed file was handed out: HTTP {status}")
    return ("nothing published by default; the rule sent the signed revision to members and the app got exactly those bytes, the public nothing; "
            "published for the public by hand once though twice, withdrawn and gone, still in the files; a changed file is an error; no ability, no documents")


def statuteapi(stack: Stack) -> str:
    """The statutes through the API: nothing unless published, for members only the app, for the public the website; the version in
    force, a future one, two from the same day ambiguous, a missing file an error (#158)."""
    browser = stack.browser()
    setup = "/custom/vereine/admin/statutes.php"
    today = datetime.date.fromisoformat(stack.today())
    page = page_ok(browser.get(setup), "statutes")
    decided = (today - datetime.timedelta(days=40)).isoformat()
    page_ok(browser.submit(page.form(name="vereinestatuteversion"), {"decided_on": decided, "valid_from": decided, "note": "API-Test"}, drop=("notify",)),
            "a version in force")
    newest = stack.sql("SELECT rowid, version, filename FROM llx_vereine_statute ORDER BY version DESC LIMIT 1")[0]
    # Earlier checks leave versions that begin today; a day between ours and theirs has exactly one in force.
    probe = (today - datetime.timedelta(days=20)).isoformat()
    status, public = stack.api("vereine/statutes", stack.reader_key)
    expect(status == 200 and public == {"state": "not_published", "current": None, "versions": []}, f"statutes before publishing: {public}")

    client = secrets.token_hex(16)
    stack.php_fixture("apiclient", RT_LOGIN="rtstatutes", RT_CLIENT_KEY=client)
    member = int(stack.value("SELECT rowid FROM llx_adherent WHERE statut = 1 ORDER BY rowid LIMIT 1"))
    page = page_ok(browser.get("/custom/vereine/admin/identities.php"), "the identities")
    page = page_ok(browser.submit(page.form(name="vereineidentityinvite"), {"client": "rtstatutes", "member_id": str(member), "application_id": "0",
                                                                             "capabilities[]": "documents"}), "invite")
    code = re.search(r"<code>([A-Za-z0-9_-]{30,})</code>", page.text).group(1)
    status, bound = stack.api(f"vereine/identities/claim?subject=sub-statutes&code={code}", client, method="POST")
    expect(status == 200, f"binding: HTTP {status} {bound}")

    # For members: the app reads them, the website does not.
    page = page_ok(browser.get(setup), "statutes before the audience")
    page_ok(browser.submit(page.form(name="vereinestatuteaudience"), {"audience": "members"}), "for members")
    status, mine = stack.api(f"vereine/me/statutes?subject=sub-statutes&day={probe}", client)
    expect(status == 200 and mine["state"] == "in_force" and mine["current"]["id"] == int(newest[0]), f"the member's statutes: HTTP {status} {mine}")
    status, public = stack.api(f"vereine/statutes?day={probe}", stack.reader_key)
    expect(public["state"] == "not_published", f"statutes for members were public: {public}")
    status, pdf = stack.api(f"vereine/me/statutes/{newest[0]}/pdf?subject=sub-statutes", client)
    expect(status == 200 and hashlib.sha256(base64.b64decode(pdf["content"])).hexdigest() == mine["current"]["sha256"], f"the member's PDF: HTTP {status}")

    # For the public: the website reads them; a future version and two from the same day.
    page = page_ok(browser.get(setup), "statutes before the public")
    page_ok(browser.submit(page.form(name="vereinestatuteaudience"), {"audience": "public"}), "for the public")
    future = (today + datetime.timedelta(days=60)).isoformat()
    directory = stack.php_fixture("statutedir")["dir"]
    copied = f"statuten-v98-{future}.pdf"
    stack.shell(f"cp '{directory}/{newest[2]}' '{directory}/{copied}'")
    sha = stack.value(f"SELECT sha256 FROM llx_vereine_statute WHERE rowid = {newest[0]}")
    stack.sql(f"INSERT INTO llx_vereine_statute (entity, version, decided_on, valid_from, source, filename, sha256, note, datec) VALUES (1, 98, '{stack.today()}', '{future}',"
              f" 'uploaded', '{copied}', '{sha}', 'Test', NOW())")
    status, public = stack.api(f"vereine/statutes?day={probe}", stack.reader_key)
    states = {row["version"]: row["state"] for row in public.get("versions", [])}
    expect(status == 200 and public["state"] == "in_force" and states.get(98) == "future" and states.get(int(newest[1])) == "in_force",
           f"with a future version: {public}")
    status, pdf = stack.api(f"vereine/statutes/{newest[0]}/pdf", stack.reader_key)
    expect(status == 200, f"the public PDF: HTTP {status}")
    stack.sql(f"UPDATE llx_vereine_statute SET valid_from = (SELECT valid_from FROM (SELECT valid_from FROM llx_vereine_statute WHERE rowid = {newest[0]}) AS v) WHERE version = 98")
    status, public = stack.api(f"vereine/statutes?day={probe}", stack.reader_key)
    expect(public["state"] == "ambiguous" and public["current"] is None, f"two versions from the same day: {public}")
    stack.shell(f"rm -f '{directory}/{copied}'")
    status, broken = stack.api("vereine/statutes/" + stack.value("SELECT rowid FROM llx_vereine_statute WHERE version = 98") + "/pdf", stack.reader_key)
    expect(status == 500, f"a missing file was answered with HTTP {status}")
    stack.sql("DELETE FROM llx_vereine_statute WHERE version = 98")
    page = page_ok(browser.get(setup), "statutes at the end")
    page_ok(browser.submit(page.form(name="vereinestatuteaudience"), {"audience": ""}), "nobody again")
    return ("nothing before publishing; for members the app read the version in force and its PDF, the website nothing; for the public the website "
            "read them with a future version; two from the same day ambiguous without a guess; a missing file an error")


def meetingapi(stack: Stack) -> str:
    """Meetings through the API: only the ones the person is invited to, an answer that is no attendance, a motion once and late
    after the deadline, the board decides, cancelled means no more answers (#159)."""
    browser = stack.browser()
    base = "/custom/vereine/meetings.php"
    today = datetime.date.fromisoformat(stack.today())

    def create(template: str, fields: dict) -> int:
        page = page_ok(browser.get(f"{base}?template={template}"), f"a new {template} meeting")
        page_ok(browser.submit(page.form(name="vereinemeeting"), fields), f"store the {template} meeting")
        meeting = int(stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting") or 0)
        page_ok(browser.submit(page_ok(browser.get(f"{base}?id={meeting}"), "the meeting").form(name="vereinemeetinginvite"), {"checked": "1"}), "invite")
        return meeting

    general = create("general", {"day": (today + datetime.timedelta(days=30)).isoformat(), "time": "18:00", "format": "hybrid", "place": "Vereinsheim",
                                 "access": "https://meet.example.org/gv", "title": "Generalversammlung API"})
    board = create("board", {"day": (today + datetime.timedelta(days=10)).isoformat(), "time": "19:00", "format": "physical", "place": "Vereinsheim",
                             "title": "Vorstandssitzung API"})
    expect(stack.value(f"SELECT status FROM llx_vereine_meeting WHERE rowid = {general}") == "invited", "the general assembly was not invited")
    member = stack.value(f"SELECT i.fk_adherent FROM llx_vereine_meeting_invitation i WHERE i.fk_meeting = {general} AND i.fk_adherent NOT IN "
                         f"(SELECT fk_adherent FROM llx_vereine_meeting_invitation WHERE fk_meeting = {board}) ORDER BY i.fk_adherent LIMIT 1")
    expect(member not in (None, ""), "every member invited to the assembly is on the board")
    client = secrets.token_hex(16)
    stack.php_fixture("apiclient", RT_LOGIN="rtmeet", RT_CLIENT_KEY=client)
    for subject, capability in (("sub-meet", "meetings"), ("sub-nomeet", "consents")):
        page = page_ok(browser.get("/custom/vereine/admin/identities.php"), "the identities")
        page = page_ok(browser.submit(page.form(name="vereineidentityinvite"), {"client": "rtmeet", "member_id": member, "application_id": "0",
                                                                                 "capabilities[]": capability}), f"invite for {capability}")
        code = re.search(r"<code>([A-Za-z0-9_-]{30,})</code>", page.text).group(1)
        status, bound = stack.api(f"vereine/identities/claim?subject={subject}&code={code}", client, method="POST")
        expect(status == 200, f"binding {subject}: HTTP {status} {bound}")
    status, refused = stack.api("vereine/me/meetings?subject=sub-nomeet", client)
    expect(status == 403, f"a binding without the ability read meetings: HTTP {status}")

    status, mine = stack.api("vereine/me/meetings?subject=sub-meet", client)
    ids = [meeting["id"] for meeting in mine] if status == 200 else []
    expect(general in ids and board not in ids, f"the member's meetings: {ids}, assembly {general}, board {board}")
    assembly = next(meeting for meeting in mine if meeting["id"] == general)
    motion_days = int(json.loads(stack.const("VEREINE_STATUTE_RULES") or "{}").get("motion_days", 3))
    expect(assembly["access"] == "https://meet.example.org/gv" and assembly["agenda"] and assembly["motion_deadline"]
           == (today + datetime.timedelta(days=30 - motion_days)).isoformat(), f"the assembly for the member: {assembly}")

    # An answer twice: one answer, no attendance.
    attendance = f"SELECT COUNT(*) FROM llx_vereine_meeting_attendance WHERE fk_meeting = {general} AND fk_adherent = {member}"
    before = stack.value(attendance)
    for _ in range(2):
        status, answered = stack.api(f"vereine/me/meetings/{general}/response?subject=sub-meet", client, method="PUT", data={"response": "yes"})
    expect(status == 200 and answered["response"] == "yes" and stack.value(f"SELECT COUNT(*) FROM llx_vereine_meeting_response WHERE fk_meeting = {general}") == "1"
           and stack.value(attendance) == before, f"the answer: HTTP {status}, attendance {before} -> {stack.value(attendance)}")
    status, _ = stack.api(f"vereine/me/meetings/{board}/response?subject=sub-meet", client, method="PUT", data={"response": "yes"})
    expect(status == 404, f"an answer to a board meeting the member is not invited to: HTTP {status}")

    # A motion once; the same id with another text is refused; after the deadline it is late.
    motion = {"external_id": "app-motion-1", "title": "Neue Sparte Valorant", "text": "Die Generalversammlung möge eine Sparte Valorant gründen."}
    for _ in range(2):
        status, sent = stack.api(f"vereine/me/meetings/{general}/motions?subject=sub-meet", client, method="POST", data=motion)
    expect(status == 200 and sent["status"] == "received" and sent["late"] is False
           and stack.value(f"SELECT COUNT(*) FROM llx_vereine_motion WHERE fk_meeting = {general}") == "1", f"the motion: HTTP {status} {sent}")
    status, _ = stack.api(f"vereine/me/meetings/{general}/motions?subject=sub-meet", client, method="POST", data={**motion, "text": "anders"})
    expect(status == 409, f"another motion under the same id: HTTP {status}")
    stack.sql(f"UPDATE llx_vereine_meeting SET meeting_day = '{(today + datetime.timedelta(days=1)).isoformat()}' WHERE rowid = {general}")
    status, late = stack.api(f"vereine/me/meetings/{general}/motions?subject=sub-meet", client, method="POST",
                             data={"external_id": "app-motion-2", "title": "Spät", "text": ""})
    expect(status == 200 and late["late"] is True, f"a motion after the deadline: HTTP {status} {late}")
    stack.sql(f"UPDATE llx_vereine_meeting SET meeting_day = '{(today + datetime.timedelta(days=30)).isoformat()}' WHERE rowid = {general}")

    # The board decides in Dolibarr; accepted, it is the last item of the agenda.
    first = stack.value(f"SELECT rowid FROM llx_vereine_motion WHERE external_id = 'app-motion-1'")
    page = page_ok(browser.get(f"{base}?id={general}"), "the assembly with motions")
    expect(f'data-motion="{first}" data-motion-status="received"' in page.text and 'data-responses="1-0-0"' in page.text, "the meeting page does not show the motions")
    page_ok(browser.submit(page.form(name=f"vereinemotion{first}accepted")), "accept the motion")
    status, mine = stack.api("vereine/me/meetings?subject=sub-meet", client)
    assembly = next(meeting for meeting in mine if meeting["id"] == general)
    accepted = next(row for row in assembly["motions"] if row["external_id"] == "app-motion-1")
    expect(accepted["status"] == "accepted" and "Neue Sparte Valorant" in assembly["agenda"][-1], f"after accepting: {accepted}, agenda {assembly['agenda']}")

    # Cancelled: no more answers.
    stack.sql(f"UPDATE llx_vereine_meeting SET status = 'cancelled' WHERE rowid = {general}")
    status, _ = stack.api(f"vereine/me/meetings/{general}/response?subject=sub-meet", client, method="PUT", data={"response": "no"})
    expect(status == 409, f"an answer to a cancelled meeting: HTTP {status}")
    return ("the member saw the assembly with its access and deadline, never the board meeting; answered twice, one answer, no attendance; "
            "a motion once, another under the same id refused, a late one kept as late; accepted in Dolibarr it closed the agenda; cancelled, no more answers")


def profileapi(stack: Stack) -> str:
    """Own data through the API: read, a change of the address at once, an e-mail address that waits for the board, a conflict instead of
    overwriting, nothing else writable, the notice of the exit on the day of the rule (#164)."""
    browser = stack.browser()
    setup = "/custom/vereine/admin/identities.php"
    page = page_ok(browser.get(setup), "the identities")
    page_ok(browser.submit(page.form(name="vereineprofiledirect"), {"direct_address": "1", "direct_zip": "1", "direct_town": "1"}), "address at once")
    expect(stack.const("VEREINE_PROFILE_DIRECT") == "address,zip,town", f"stored: {stack.const('VEREINE_PROFILE_DIRECT')}")
    member = stack.value("SELECT rowid FROM llx_adherent WHERE statut = 1 AND rowid NOT IN (SELECT fk_adherent FROM llx_vereine_member_exit WHERE status = 'planned')"
                         " ORDER BY rowid DESC LIMIT 1")
    client = secrets.token_hex(16)
    stack.php_fixture("apiclient", RT_LOGIN="rtprofile", RT_CLIENT_KEY=client)
    page = page_ok(browser.get(setup), "the identities before the invitation")
    page = page_ok(browser.submit(page.form(name="vereineidentityinvite"), {"client": "rtprofile", "member_id": member, "application_id": "0",
                                                                             "capabilities[]": "profile"}), "invite")
    code = re.search(r"<code>([A-Za-z0-9_-]{30,})</code>", page.text).group(1)
    status, bound = stack.api(f"vereine/identities/claim?subject=sub-profile&code={code}", client, method="POST")
    expect(status == 200, f"binding: HTTP {status} {bound}")
    status, profile = stack.api("vereine/me/profile?subject=sub-profile", client)
    expect(status == 200 and profile["member_id"] == int(member) and profile["direct"] == ["address", "zip", "town"], f"the own data: HTTP {status} {profile}")

    # The address changes at once, twice the same request is one.
    change = {"external_id": "app-change-1", "version": profile["version"], "changes": {"address": "Neue Gasse 7", "zip": "6020", "town": "Innsbruck"}}
    for _ in range(2):
        status, applied = stack.api("vereine/me/profile/changes?subject=sub-profile", client, method="POST", data=change)
    expect(status == 200 and applied["status"] == "applied" and stack.value(f"SELECT address FROM llx_adherent WHERE rowid = {member}") == "Neue Gasse 7"
           and stack.value("SELECT COUNT(*) FROM llx_vereine_profile_request WHERE external_id = 'app-change-1'") == "1", f"the change: HTTP {status} {applied}")
    # The old version is now a conflict; the status and the third party cannot be written.
    status, _ = stack.api("vereine/me/profile/changes?subject=sub-profile", client, method="POST",
                          data={"external_id": "app-change-2", "version": profile["version"], "changes": {"town": "Hall"}})
    expect(status == 409, f"a change on an old version: HTTP {status}")
    status, profile = stack.api("vereine/me/profile?subject=sub-profile", client)
    status, refused = stack.api("vereine/me/profile/changes?subject=sub-profile", client, method="POST",
                                data={"external_id": "app-change-3", "version": profile["version"], "changes": {"statut": "-2", "fk_soc": "1"}})
    expect(status == 400 and stack.value(f"SELECT statut FROM llx_adherent WHERE rowid = {member}") == "1", f"status written through the app: HTTP {status}")

    # The own website profile, kept through the application (#260).
    status, own = stack.api("vereine/me/website-profile?subject=sub-profile", client)
    expect(status == 200 and set(own) == {"consent", "given", "gamertag", "bio", "games", "platforms"}, f"the own website profile: HTTP {status} {own}")
    status, own = stack.api("vereine/me/website-profile?subject=sub-profile", client, method="PUT",
                            data={"gamertag": "AppLöwe", "bio": "Aus der App.", "games": ["TFT", "Rocket League"], "platforms": "PC"})
    kept = stack.sql(f"SELECT gamertag, games, platforms FROM llx_vereine_member_profile WHERE fk_adherent = {member}")
    expect(status == 200 and own["games"] == ["TFT", "Rocket League"] and own["platforms"] == ["PC"] and kept == [["AppLöwe", "TFT, Rocket League", "PC"]],
           f"kept through the app: {kept}")
    status, own = stack.api("vereine/me/website-profile?subject=sub-profile", client, method="PUT", data={"bio": "Nur der Text neu."})
    expect(status == 200 and own["gamertag"] == "AppLöwe" and own["bio"] == "Nur der Text neu.", f"a change of one field: HTTP {status} {own}")
    status, refused = stack.api("vereine/me/website-profile?subject=sub-profile", client, method="PUT", data={"gamertag": "x" * 41})
    expect(status == 400 and "gamertag" in str(refused) and stack.value(f"SELECT gamertag FROM llx_vereine_member_profile WHERE fk_adherent = {member}") == "AppLöwe",
           f"a gamertag too long: HTTP {status} {refused}")

    # A new e-mail address waits for the board; the board rejects it with a word for the member and a note of its own.
    old_email = stack.value(f"SELECT email FROM llx_adherent WHERE rowid = {member}")
    status, waiting = stack.api("vereine/me/profile/changes?subject=sub-profile", client, method="POST",
                                data={"external_id": "app-change-4", "version": profile["version"], "changes": {"email": "neu.adresse@runtime-verein.test"}})
    expect(status == 200 and waiting["status"] == "received" and stack.value(f"SELECT email FROM llx_adherent WHERE rowid = {member}") == old_email,
           f"a new e-mail address was taken at once: {waiting}")
    request = stack.value("SELECT rowid FROM llx_vereine_profile_request WHERE external_id = 'app-change-4'")
    tab = page_ok(browser.get(f"/custom/vereine/member_association.php?id={member}"), "the member's tab")
    expect(f'data-profile-request="{request}" data-profile-outdated="0"' in tab.text, "the member's tab does not show the waiting change")
    page_ok(browser.submit(tab.form(name=f"vereineprofilerequest{request}"), {"reason": "Bitte persönlich bestätigen", "note": "intern: Anruf offen"},
                           button=("action", "rejectprofile")), "reject the change")
    status, requests = stack.api("vereine/me/profile/changes?subject=sub-profile", client)
    rejected = next(row for row in requests if row["external_id"] == "app-change-4")
    expect(rejected["status"] == "rejected" and rejected["reason"] == "Bitte persönlich bestätigen" and "intern" not in json.dumps(requests),
           f"the member's view of the rejected change: {rejected}")

    # The notice of the exit ends on the day of the rule.
    status, notice = stack.api("vereine/me/exit?subject=sub-profile", client, method="POST", data={"external_id": "app-exit-1", "wished_last_day": "2000-01-01"})
    stored = stack.sql(f"SELECT reason, notice_day, last_day, status FROM llx_vereine_member_exit WHERE fk_adherent = {member} ORDER BY rowid DESC LIMIT 1")
    expect(status == 200 and stored and stored[0][0] == "resignation" and notice["last_day"] == stored[0][2] and notice["wished_too_early"] is True
           and notice["notice_day"] == stack.today(), f"the notice: HTTP {status} {notice}, stored {stored}")
    status, again = stack.api("vereine/me/exit?subject=sub-profile", client, method="POST", data={"external_id": "app-exit-1", "wished_last_day": "2000-01-01"})
    expect(status == 200 and again["last_day"] == notice["last_day"], f"the same notice again: HTTP {status}")
    status, _ = stack.api("vereine/me/exit?subject=sub-profile", client, method="POST", data={"external_id": "app-exit-2"})
    expect(status == 409, f"a second notice: HTTP {status}")
    if stored[0][3] == "planned":
        stack.sql(f"UPDATE llx_vereine_member_exit SET status = 'cancelled' WHERE fk_adherent = {member} AND status = 'planned'")
    return (f"address changed at once and once; an old version a conflict; status and third party not writable; a new e-mail address waited for "
            f"the board, rejected with a reason and without the internal note; the notice ends on {notice['last_day']} by the rule, a second one refused")


def ballotapi(stack: Stack) -> str:
    """Ballots of a general assembly through two applications and on paper: rules frozen at release, rights at opening, a proxy votes
    for the represented member, whoever left cannot vote, one right counts once whichever way, the same request twice is one (#160, #161)."""
    browser = stack.browser()
    base = "/custom/vereine/meetings.php"
    today = stack.today()
    page = page_ok(browser.get(f"{base}?template=general"), "a new general assembly")
    page_ok(browser.submit(page.form(name="vereinemeeting"), {"day": (datetime.date.fromisoformat(today) + datetime.timedelta(days=30)).isoformat(), "time": "18:00",
                                                               "format": "hybrid", "place": "Vereinsheim", "access": "https://meet.example.org/abstimmung",
                                                               "title": "Generalversammlung Abstimmung"}), "store the assembly")
    meeting = int(stack.value("SELECT MAX(rowid) FROM llx_vereine_meeting") or 0)
    page_ok(browser.submit(page_ok(browser.get(f"{base}?id={meeting}"), "the assembly").form(name="vereinemeetinginvite"), {"checked": "1"}), "invite")
    # The invitation went out in time; the assembly is today.
    stack.sql(f"UPDATE llx_vereine_meeting SET meeting_day = '{today}' WHERE rowid = {meeting}")
    voters = [int(row[0]) for row in stack.sql(f"SELECT i.fk_adherent FROM llx_vereine_meeting_invitation i INNER JOIN llx_adherent a ON a.rowid = i.fk_adherent"
                                                f" WHERE i.fk_meeting = {meeting} AND i.voting = 1 AND a.statut = 1 AND i.fk_adherent NOT IN"
                                                " (SELECT fk_adherent FROM llx_vereine_member_exit WHERE status <> 'cancelled') ORDER BY i.fk_adherent LIMIT 4")]
    expect(len(voters) == 4, f"too few voting members for the test: {voters}")
    anna, ben, carla, emil = voters
    page = page_ok(browser.get(f"{base}?id={meeting}"), "the assembly before the attendance")
    page_ok(browser.submit(page.form(name="vereineattendance"), {
        f"attendance[{anna}][state]": "present", f"attendance[{anna}][arrived]": "00:00",
        f"attendance[{ben}][state]": "represented", f"attendance[{ben}][holder]": str(anna),
        f"attendance[{carla}][state]": "present", f"attendance[{carla}][left]": "00:00",
        f"attendance[{emil}][state]": "present", f"attendance[{emil}][arrived]": "00:00"}), "the attendance")
    expect(stack.value(f"SELECT fk_holder FROM llx_vereine_meeting_attendance WHERE fk_meeting = {meeting} AND fk_adherent = {ben}") == str(anna),
           "the proxy of the attendance was not stored")

    ballots = f"/custom/vereine/ballots.php?meeting={meeting}"
    page = page_ok(browser.get(ballots), "the ballots")
    page_ok(browser.submit(page.form(name="vereineballot"), {"item": "1", "kind": "resolution", "question": "Entlastung des Vorstands"}), "prepare a ballot")
    ballot = int(stack.value(f"SELECT MAX(rowid) FROM llx_vereine_ballot WHERE fk_meeting = {meeting}") or 0)
    expect(ballot > 0, "no ballot was prepared")

    clients = {"rtvote1": secrets.token_hex(16), "rtvote2": secrets.token_hex(16)}
    for login, key in clients.items():
        stack.php_fixture("apiclient", RT_LOGIN=login, RT_CLIENT_KEY=key)
    for login, subject, person in (("rtvote1", "sub-anna", anna), ("rtvote2", "sub-anna-2", anna), ("rtvote1", "sub-ben", ben), ("rtvote1", "sub-carla", carla),
                                   ("rtvote2", "sub-emil", emil)):
        page = page_ok(browser.get("/custom/vereine/admin/identities.php"), "the identities")
        page = page_ok(browser.submit(page.form(name="vereineidentityinvite"), {"client": login, "member_id": str(person), "application_id": "0",
                                                                                 "capabilities[]": "votes"}), f"invite {subject}")
        code = re.search(r"<code>([A-Za-z0-9_-]{30,})</code>", page.text).group(1)
        status, _ = stack.api(f"vereine/identities/claim?subject={subject}&code={code}", clients[login], method="POST")
        expect(status == 200, f"binding {subject}: HTTP {status}")

    def mine(login: str, subject: str) -> dict:
        status, listed = stack.api(f"vereine/me/ballots?subject={subject}", clients[login])
        expect(status == 200, f"the ballots of {subject}: HTTP {status}")
        return next((row for row in listed if row["id"] == ballot), {})

    def vote(login: str, subject: str, data: dict) -> tuple[int, object]:
        return stack.api(f"vereine/me/ballots/{ballot}/votes?subject={subject}", clients[login], method="POST", data=data)

    expect(mine("rtvote1", "sub-anna") == {}, "a draft is visible in the application")
    page_ok(browser.submit(page_ok(browser.get(ballots), "the ballots").form(name=f"vereineballotrelease{ballot}")), "release")
    rules = json.loads(stack.value(f"SELECT rules FROM llx_vereine_ballot WHERE rowid = {ballot}") or "{}")
    expect(rules.get("majority") == "simple" and rules.get("proxy") is True and rules.get("day") == today, f"the frozen rules: {rules}")
    shown = mine("rtvote1", "sub-anna")
    expect(shown.get("status") == "released" and shown.get("rights") == [] and [o["code"] for o in shown.get("options", [])] == ["yes", "no", "abstain"],
           f"the released ballot: {shown}")
    status, _ = vote("rtvote1", "sub-anna", {"right_id": 1, "option": "yes"})
    expect(status in (404, 409), f"a vote before opening: HTTP {status}")

    page_ok(browser.submit(page_ok(browser.get(ballots), "the ballots").form(name=f"vereineballotopen{ballot}")), "open")
    # Proxies are frozen at opening: Ben coming himself later changes nothing for this ballot.
    stack.sql(f"UPDATE llx_vereine_meeting_attendance SET state = 'present', fk_holder = 0, arrived = '00:00' WHERE fk_meeting = {meeting} AND fk_adherent = {ben}")
    shown = mine("rtvote1", "sub-anna")
    rights = {row["for"]: row for row in shown.get("rights", [])}
    expect(shown.get("status") == "open" and set(rights) == {"self", "proxy"} and rights["proxy"]["state"] == "open", f"Anna's rights: {shown.get('rights')}")
    represented = mine("rtvote1", "sub-ben").get("rights", [])
    expect(represented == [{"right_id": 0, "for": "self", "name": "", "state": "none", "reason": "represented", "option": ""}], f"Ben's rights: {represented}")

    own, proxy = rights["self"]["right_id"], rights["proxy"]["right_id"]
    for _ in range(2):
        status, after = vote("rtvote1", "sub-anna", {"right_id": own, "option": "yes", "external_id": "anna-1"})
    expect(status == 200, f"Anna's vote: HTTP {status} {after}")
    status, _ = vote("rtvote2", "sub-anna-2", {"right_id": own, "option": "no", "external_id": "anna-2"})
    expect(status == 409, f"the same right through the second application: HTTP {status}")
    status, _ = vote("rtvote1", "sub-anna", {"right_id": proxy, "option": "maybe"})
    expect(status == 400, f"an option the ballot does not have: HTTP {status}")
    status, after = vote("rtvote1", "sub-anna", {"right_id": proxy, "option": "no", "external_id": "anna-for-ben"})
    expect(status == 200 and {row["for"]: row["option"] for row in after["rights"]} == {"self": "yes", "proxy": "no"}, f"Anna for Ben: HTTP {status}")
    status, _ = vote("rtvote1", "sub-ben", {"right_id": proxy, "option": "yes"})
    expect(status == 404, f"Ben used the right his proxy holds: HTTP {status}")
    carla_right = mine("rtvote1", "sub-carla")["rights"][0]["right_id"]
    status, refused = vote("rtvote1", "sub-carla", {"right_id": carla_right, "option": "yes"})
    expect(status == 409, f"Carla voted although she left: HTTP {status} {refused}")

    # The board enters Emil's paper ballot; his application finds the right used.
    emil_right = mine("rtvote2", "sub-emil")["rights"][0]["right_id"]
    page = page_ok(browser.get(ballots), "the ballots before the paper ballot")
    page_ok(browser.submit(page.form(name=f"vereineballotpaper{ballot}"), {"right": str(emil_right), "option": "abstain"}), "enter a paper ballot")
    status, _ = vote("rtvote2", "sub-emil", {"right_id": emil_right, "option": "yes"})
    expect(status == 409, f"a paper ballot and then the application: HTTP {status}")
    counted = stack.sql(f"SELECT channel, COUNT(*) FROM llx_vereine_ballot_vote WHERE fk_ballot = {ballot} GROUP BY channel ORDER BY channel")
    expect(counted == [["app", "2"], ["paper", "1"]], f"the votes kept: {counted}")

    page_ok(browser.submit(page_ok(browser.get(ballots), "the ballots").form(name=f"vereineballotclose{ballot}")), "close")
    status, _ = vote("rtvote1", "sub-carla", {"right_id": carla_right, "option": "yes"})
    expect(status == 409, f"a vote after closing: HTTP {status}")
    page = page_ok(browser.get(ballots), "the closed ballot")
    counts = dict(re.findall(r'data-ballot-count="([a-z0-9]+)">[^<]*?: (\d+)<', page.text))
    expect(counts == {"yes": "1", "no": "1", "abstain": "1"} and 'data-ballot-valid="2"' in page.text, f"the count: {counts}")
    return ("released with frozen rules, opened with frozen rights: Anna voted for herself once though sent twice and for Ben by his proxy, "
            "the second application found her right used, Ben could not vote himself, Carla who left was refused, Emil's paper ballot "
            "counted once; closed: yes 1, no 1, abstain 1, two valid votes")


def ballotresult(stack: Stack) -> str:
    """Counting ballots: a snapshot with a proof as PDF in the files, nothing follows before whoever chairs confirms, confirming twice
    makes one vote of the meeting, one entry in the register and one term of office; members see the result only once confirmed (#163)."""
    browser = stack.browser()
    meeting = int(stack.value("SELECT MAX(fk_meeting) FROM llx_vereine_ballot") or 0)
    first = int(stack.value(f"SELECT MIN(rowid) FROM llx_vereine_ballot WHERE fk_meeting = {meeting}") or 0)
    ballots = f"/custom/vereine/ballots.php?meeting={meeting}"
    client = secrets.token_hex(16)
    stack.php_fixture("apiclient", RT_LOGIN="rtcount", RT_CLIENT_KEY=client)
    anna = int(stack.value(f"SELECT fk_holder FROM llx_vereine_ballot_right WHERE fk_ballot = {first} AND reason = 'proxy' LIMIT 1") or 0)
    page = page_ok(browser.get("/custom/vereine/admin/identities.php"), "the identities")
    page = page_ok(browser.submit(page.form(name="vereineidentityinvite"), {"client": "rtcount", "member_id": str(anna), "application_id": "0", "capabilities[]": "votes"}), "invite")
    code = re.search(r"<code>([A-Za-z0-9_-]{30,})</code>", page.text).group(1)
    status, _ = stack.api(f"vereine/identities/claim?subject=sub-count&code={code}", client, method="POST")
    expect(status == 200, f"binding: HTTP {status}")

    def seen(ballot: int) -> dict:
        status, listed = stack.api("vereine/me/ballots?subject=sub-count", client)
        expect(status == 200, f"the ballots: HTTP {status}")
        return next((row for row in listed if row["id"] == ballot), {})

    # The first ballot, closed: counted, a proof in the files, still provisional.
    votes = f"SELECT COUNT(*) FROM llx_vereine_meeting_vote WHERE fk_meeting = {meeting}"
    before = stack.value(votes)
    page_ok(browser.submit(page_ok(browser.get(ballots), "the ballots").form(name=f"vereineballotevaluate{first}")), "count")
    counted = stack.sql(f"SELECT rowid, status, filename, doc_sha FROM llx_vereine_ballot_result WHERE fk_ballot = {first}")
    expect(len(counted) == 1 and counted[0][1] == "provisional" and counted[0][2] and len(counted[0][3]) == 64, f"the count: {counted}")
    result_id, filename, sha = counted[0][0], counted[0][2], counted[0][3]
    data = base64.b64decode(stack.shell(f"base64 '/var/www/documents/vereine/ballots/{filename}'").stdout)
    text = pdf_bytes_text(data)
    expect(hashlib.sha256(data).hexdigest() == sha and "Entlastung des Vorstands" in text and "Nachweis der Abstimmung" in text,
           "the proof is not the PDF of the count")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_vereine_document WHERE kind = 'ballot' AND fk_object = {result_id}") == "1", "the proof is not in the files")
    expect(stack.value(votes) == before and seen(first).get("result") is None, "a provisional count went into the meeting or to the members")
    # Counted again with a reason: the first count stays with its proof.
    page = page_ok(browser.get(ballots), "the ballots before counting again")
    page_ok(browser.submit(page.form(name=f"vereineballotreevaluate{first}"), {"reason": "Anwesenheit berichtigt"}), "count again")
    states = stack.sql(f"SELECT revision, status, reason FROM llx_vereine_ballot_result WHERE fk_ballot = {first} ORDER BY revision")
    expect(states == [["1", "superseded", ""], ["2", "provisional", "Anwesenheit berichtigt"]], f"counted again: {states}")
    page_ok(browser.submit(page_ok(browser.get(ballots), "the ballots before confirming").form(name=f"vereineballotconfirm{first}")), "confirm")
    again = stack.php_fixture("ballotconfirm", RT_BALLOT_ID=str(first))
    expect(again["result"] == 2 and stack.value(votes) == str(int(before) + 1), f"confirming twice: {again}, votes {stack.value(votes)} after {before}")
    result = seen(first).get("result") or {}
    expect(result.get("revision") == 2 and result.get("counts", {}).get("yes") == 1 and result.get("valid") == 2, f"the members' result: {result}")

    # An election: nothing before it is confirmed, then one term.
    agenda = page_ok(browser.get(f"/custom/vereine/meetings.php?id={meeting}"), "the assembly")
    voting = [int(row[0]) for row in stack.sql(f"SELECT fk_adherent FROM llx_vereine_meeting_invitation WHERE fk_meeting = {meeting} AND voting = 1")]
    changes = {}
    for member in voting:
        changes[f"attendance[{member}][state]"] = "present"
        changes[f"attendance[{member}][arrived]"] = "00:00"
        changes[f"attendance[{member}][left]"] = ""
    page_ok(browser.submit(agenda.form(name="vereineattendance"), changes), "everybody is there")
    function = stack.value("SELECT rowid FROM llx_vereine_function WHERE entity = 1 AND active = 1 AND represents = 0 ORDER BY rowid LIMIT 1")
    terms = f"SELECT COUNT(*) FROM llx_vereine_function_term WHERE fk_function = {function} AND fk_adherent = {anna}"
    terms_before = stack.value(terms)
    page = page_ok(browser.get(ballots), "the ballots before the election")
    page_ok(browser.submit(page.form(name="vereineballot"), {"item": "1", "kind": "election", "question": "Wahl Laufzeit", "function_id": str(function),
                                                             "candidates[]": str(anna), "consent": "1"}), "prepare the election")
    election = int(stack.value(f"SELECT MAX(rowid) FROM llx_vereine_ballot WHERE fk_meeting = {meeting}") or 0)
    for step in ("release", "open"):
        page_ok(browser.submit(page_ok(browser.get(ballots), f"before {step}").form(name=f"vereineballot{step}{election}")), step)
    rights = [row for row in seen(election).get("rights", []) if row["state"] == "open"]
    for right in rights:
        status, _ = stack.api(f"vereine/me/ballots/{election}/votes?subject=sub-count", client, method="POST", data={"right_id": right["right_id"], "option": f"c{anna}"})
        expect(status == 200, f"a vote in the election: HTTP {status}")
    for step in ("close", "evaluate"):
        page_ok(browser.submit(page_ok(browser.get(ballots), f"before {step}").form(name=f"vereineballot{step}{election}")), step)
    outcome = stack.value(f"SELECT status FROM llx_vereine_ballot_result WHERE fk_ballot = {election}")
    expect(outcome == "provisional" and stack.value(terms) == terms_before, "the election changed the functions before it was confirmed")
    page_ok(browser.submit(page_ok(browser.get(ballots), "the election before confirming").form(name=f"vereineballotconfirm{election}")), "confirm the election")
    stack.php_fixture("ballotconfirm", RT_BALLOT_ID=str(election))
    expect(stack.value(terms) == str(int(terms_before) + 1), f"the term of the elected member: {stack.value(terms)} after {terms_before}")
    won = seen(election).get("result") or {}
    expect(won.get("outcome") == "passed" and won.get("winner") == f"c{anna}", f"the election for the members: {won}")
    return ("counted with a proof as PDF/A in the files, provisional and invisible to members; counted again with a reason, the first count kept; "
            "confirmed twice: one vote of the meeting; an election made its term only when confirmed, once")


def portal(stack: Stack) -> str:
    """Dolibarr's web portal with the page of the association (#25): switched on per ability, the member logged in there sees the same
    documents and ballots as through the API, never somebody else's, and a vote cast through an application cannot be cast again there."""
    browser = stack.browser()
    setup = "/custom/vereine/admin/identities.php"
    page = page_ok(browser.get(setup), "the identities")
    if stack.version.startswith("22"):
        expect('data-portal-supported="0"' in page.text and 'name="vereineportal"' not in page.text, "Dolibarr 22 offers a portal setting it cannot honour")
        return "Dolibarr 22 has no place for pages of modules in its web portal: the setup says so and offers nothing"
    page_ok(browser.submit(page.form(name="vereineportal"), {"portal_documents": "1", "portal_votes": "1"}), "switch the portal on")
    expect(stack.const("VEREINE_PORTAL_CAPABILITIES") == "documents,votes", f"stored: {stack.const('VEREINE_PORTAL_CAPABILITIES')}")
    enable_dolibarr_module(stack, "modWebPortal")

    # A member of the assembly of the ballots, with a third party the portal account belongs to.
    meeting = int(stack.value("SELECT MAX(fk_meeting) FROM llx_vereine_ballot") or 0)
    member = stack.value(f"SELECT a.rowid FROM llx_adherent a INNER JOIN llx_vereine_meeting_invitation i ON i.fk_adherent = a.rowid AND i.fk_meeting = {meeting}"
                         " WHERE a.statut = 1 AND a.fk_soc > 0 AND i.voting = 1 AND a.fk_soc NOT IN (SELECT fk_soc FROM llx_adherent WHERE rowid <> a.rowid AND fk_soc > 0)"
                         " ORDER BY a.rowid LIMIT 1")
    expect(member not in (None, ""), "no voting member of the assembly with a third party of its own")
    account = stack.php_fixture("portalmember", RT_MEMBER_ID=member)

    # A ballot open now; the member votes through an application first.
    ballots = f"/custom/vereine/ballots.php?meeting={meeting}"
    page = page_ok(browser.get(f"/custom/vereine/meetings.php?id={meeting}"), "the assembly")
    page_ok(browser.submit(page.form(name="vereineattendance"), {f"attendance[{member}][state]": "present", f"attendance[{member}][arrived]": "00:00",
                                                                  f"attendance[{member}][left]": ""}), "the member is there")
    page = page_ok(browser.get(ballots), "the ballots")
    page_ok(browser.submit(page.form(name="vereineballot"), {"item": "1", "kind": "resolution", "question": "Portal und App"}), "prepare a ballot")
    ballot = int(stack.value(f"SELECT MAX(rowid) FROM llx_vereine_ballot WHERE fk_meeting = {meeting}") or 0)
    for step in ("release", "open"):
        page_ok(browser.submit(page_ok(browser.get(ballots), f"before {step}").form(name=f"vereineballot{step}{ballot}")), step)
    client = secrets.token_hex(16)
    stack.php_fixture("apiclient", RT_LOGIN="rtportalapp", RT_CLIENT_KEY=client)
    page = page_ok(browser.get(setup), "the identities before the invitation")
    form = page.form(name="vereineidentityinvite")
    fields = [(name, value) for name, value in form.values() if name not in ("client", "member_id", "application_id", "capabilities[]")]
    fields += [("client", "rtportalapp"), ("member_id", member), ("application_id", "0"), ("capabilities[]", "documents"), ("capabilities[]", "votes")]
    page = page_ok(browser.post(form.url(), fields), "invite")
    code = re.search(r"<code>([A-Za-z0-9_-]{30,})</code>", page.text).group(1)
    status, _ = stack.api(f"vereine/identities/claim?subject=sub-portal&code={code}", client, method="POST")
    expect(status == 200, f"binding: HTTP {status}")
    status, listed = stack.api("vereine/me/ballots?subject=sub-portal", client)
    right = next(row for row in listed if row["id"] == ballot)["rights"][0]["right_id"] if status == 200 else 0
    status, _ = stack.api(f"vereine/me/ballots/{ballot}/votes?subject=sub-portal", client, method="POST", data={"right_id": right, "option": "yes"})
    expect(status == 200, f"the vote through the application: HTTP {status}")

    # The portal: log in as the member, the page of the association.
    visitor = Browser(stack.url)
    login = page_ok(visitor.get("/public/webportal/index.php"), "the login of the web portal")
    page_ok(visitor.post("/public/webportal/index.php", [("token", token_of(login)), ("action_login", "login"), ("login", account["login"]),
                                                         ("password", account["password"])]), "log in")
    mine = page_ok(visitor.get("/public/webportal/index.php?controller=vereine"), "the page of the association")
    seen = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html.unescape(re.sub(r"(?s)<(script|style)[^>]*>.*?</>", " ", mine.text))))[:900]
    expect(f'data-vereine-portal="{member}"' in mine.text and 'data-vereine-portal-section="meetings"' not in mine.text,
           f"the page is not the member's, or shows meetings that are switched off; the portal showed: {seen}")
    status, documents = stack.api("vereine/me/documents?subject=sub-portal", client)
    shown = sorted(int(found) for found in re.findall(r'data-vereine-portal-document="(\d+)"', mine.text))
    expect(status == 200 and shown == sorted(row["document_id"] for row in documents), f"documents: portal {shown}, API {[row['document_id'] for row in documents]}")
    others = [int(row[0]) for row in stack.sql(f"SELECT DISTINCT fk_document FROM llx_vereine_publication WHERE audience = 'person' AND fk_adherent <> {member}")]
    expect(not set(others) & set(shown), "the portal shows a document of somebody else")
    expect(f'data-vereine-portal-right="{right}" data-vereine-portal-right-state="used"' in mine.text, "the portal does not show the vote cast through the application")
    # The portal's links carry its token; a form for a used right it does not print, so the request is made by hand.
    token = re.search(r"[?&;]token=([0-9a-zA-Z]+)", html.unescape(mine.text)).group(1)
    refused = page_ok(visitor.post("/public/webportal/index.php?controller=vereine", [("token", token), ("action", "vote"), ("ballot", str(ballot)),
                                                                                       ("right", str(right)), ("option", "no")]), "vote again in the portal")
    expect("schon abgestimmt" in html.unescape(refused.text), "the portal does not say that the right was used")
    votes = stack.value(f"SELECT COUNT(*) FROM llx_vereine_ballot_vote WHERE fk_ballot = {ballot}")
    expect(votes == "1" and stack.value(f"SELECT option_code FROM llx_vereine_ballot_vote WHERE fk_ballot = {ballot}") == "yes", f"votes after the portal: {votes}")
    # Consents, own data and events in the portal as well (#257).
    page = page_ok(browser.get(setup), "the identities for more of the portal")
    page_ok(browser.submit(page.form(name="vereineportal"), {"portal_documents": "1", "portal_votes": "1", "portal_consents": "1", "portal_profile": "1",
                                                              "portal_events": "1", "portal_meetings": "1", "portal_accounts": "1"}),
            "switch consents, own data, events, meetings and accounts on")
    mine = page_ok(visitor.get("/public/webportal/index.php?controller=vereine"), "the page with everything")
    code = re.search(r'name="vereineportalgive([a-z0-9_]+)"', mine.text) or re.search(r'name="vereineportalwithdraw([a-z0-9_]+)"', mine.text)
    expect(code is not None, "the portal offers no consent to give or withdraw")
    purpose = code.group(1)
    if f'name="vereineportalgive{purpose}"' in mine.text:
        page_ok(visitor.submit(mine.form(name=f"vereineportalgive{purpose}")), "agree in the portal")
        mine = page_ok(visitor.get("/public/webportal/index.php?controller=vereine"), "the page after agreeing")
    page_ok(visitor.submit(mine.form(name=f"vereineportalwithdraw{purpose}")), "withdraw in the portal")
    last = stack.sql(f"SELECT given, source, proof_form FROM llx_vereine_consent WHERE fk_adherent = {member} AND code = '{purpose}' ORDER BY rowid DESC LIMIT 1")
    expect(last == [["0", "website", "Webportal von Dolibarr"]], f"the withdrawal through the portal: {last}")
    mine = page_ok(visitor.get("/public/webportal/index.php?controller=vereine"), "the page before a change of the data")
    page_ok(visitor.submit(mine.form(name="vereineportalprofile"), {"town": "Portalstadt"}), "ask for a change of the town")
    asked = stack.sql(f"SELECT status, payload FROM llx_vereine_profile_request WHERE fk_adherent = {member} AND client = 'webportal' ORDER BY rowid DESC LIMIT 1")
    expect(asked and "Portalstadt" in asked[0][1], f"the change through the portal: {asked}")
    mine = page_ok(visitor.get("/public/webportal/index.php?controller=vereine"), "the page before the website profile")
    page_ok(visitor.submit(mine.form(name="vereineportalwebsite"), {"gamertag": "PortalLöwe", "games": "Valorant, TFT"}), "keep the website profile in the portal")
    kept = stack.sql(f"SELECT gamertag, games FROM llx_vereine_member_profile WHERE fk_adherent = {member}")
    expect(kept == [["PortalLöwe", "Valorant, TFT"]], f"the website profile kept in the portal: {kept}")
    shift = stack.value(f"SELECT s.rowid FROM llx_vereine_event_shift s INNER JOIN llx_vereine_event e ON e.rowid = s.fk_event WHERE e.label = 'LAN Mitglieder'"
                        f" AND s.label = 'Kassa'")
    mine = page_ok(visitor.get("/public/webportal/index.php?controller=vereine"), "the page before a shift")
    expect(f'name="vereineportalshift{shift}"' in mine.text, "the portal does not offer the shift of the members' event")
    page_ok(visitor.submit(mine.form(name=f"vereineportalshift{shift}")), "ask for the shift in the portal")
    entry = stack.sql(f"SELECT status, source FROM llx_vereine_event_shift_entry WHERE fk_shift = {shift} AND fk_adherent = {member}")
    expect(entry == [["requested", "webportal"]], f"the shift asked for in the portal: {entry}")
    mine = page_ok(visitor.get("/public/webportal/index.php?controller=vereine"), "the page before taking the shift back")
    page_ok(visitor.submit(mine.form(name=f"vereineportalshift{shift}")), "take the request back")
    expect(stack.value(f"SELECT status FROM llx_vereine_event_shift_entry WHERE fk_shift = {shift} AND fk_adherent = {member}") == "cancelled",
           "the request was not taken back")

    # Accounts, statutes and a motion in the portal (#258).
    mine = page_ok(visitor.get("/public/webportal/index.php?controller=vereine"), "the page with accounts, statutes and motions")
    network = re.search(r'name="vereineportalaccount([a-z0-9_]+)"', mine.text)
    expect(network is not None, "the portal offers no account")
    page_ok(visitor.submit(mine.form(name=f"vereineportalaccount{network.group(1)}"), {"handle": "PortalGamer"}), "set an account in the portal")
    kept = json.loads(stack.value(f"SELECT IFNULL(socialnetworks, '{{}}') FROM llx_adherent WHERE rowid = {member}") or "{}")
    expect(kept.get(network.group(1)) == "PortalGamer" and stack.value(f"SELECT COUNT(*) FROM llx_vereine_social WHERE fk_adherent = {member}"
                                                                        f" AND network = '{network.group(1)}' AND handle = 'PortalGamer'") == "0",
           f"the account set in the portal: {kept}, confirmed only by an application")
    statute = re.search(r'data-vereine-portal-statute="(\d+)"', mine.text)
    if 'data-vereine-portal-statutes="not_published"' not in mine.text:
        expect(statute is not None, "the portal shows no version of the published statutes")
        link = re.search(r'href="([^"]*action=statute[^"]*)"', mine.text)
        pdf = visitor.get(html.unescape(link.group(1)))
        expect(pdf.status == 200 and pdf.text.startswith("%PDF"), f"the statutes as PDF: HTTP {pdf.status}")
    motion = re.search(r'name="vereineportalmotion(\d+)"', mine.text)
    expect(motion is not None, "the portal offers no motion for a general assembly")
    page_ok(visitor.submit(mine.form(name=f"vereineportalmotion{motion.group(1)}"), {"title": "Antrag aus dem Portal", "text": "Bitte mehr LAN-Partys."}),
            "a motion through the portal")
    expect(stack.value(f"SELECT COUNT(*) FROM llx_vereine_motion WHERE fk_meeting = {motion.group(1)} AND fk_adherent = {member} AND client = 'webportal'"
                       " AND title = 'Antrag aus dem Portal'") == "1", "the motion through the portal was not kept")

    # Switched off: no page, no menu entry.
    page = page_ok(browser.get(setup), "the identities before switching off")
    page_ok(browser.submit(page.form(name="vereineportal"), {}, drop=("portal_documents", "portal_votes", "portal_consents", "portal_profile", "portal_events",
                                                                       "portal_meetings", "portal_accounts")), "switch the portal off")
    gone = visitor.get("/public/webportal/index.php?controller=vereine")
    expect("data-vereine-portal=" not in gone.text, "the page stays after switching the portal off")
    page_ok(browser.submit(page_ok(browser.get(ballots), "the ballots").form(name=f"vereineballotcancel{ballot}")), "call the ballot off")
    return ("the member logged in to the web portal saw the documents the API gives, none of somebody else, and the vote cast through the "
            "application; voting again in the portal counted nothing; a consent withdrawn, a change of the town asked for, a shift asked for "
            "and taken back, an account set, the statutes read and a motion made through the portal, as through an application; switched off, the page is gone")


def documentmore(stack: Stack) -> str:
    """Documents, the second part: a shortened version as a file of its own derived from the original, a document for one person only,
    publishing and withdrawing in the change feed, a signed copy out only once every signature is there (#239)."""
    browser = stack.browser()
    base = "/custom/vereine/archive.php?year=0"
    plain = stack.sql("SELECT d.rowid FROM llx_vereine_document d WHERE d.kind <> 'statute' AND EXISTS (SELECT 1 FROM llx_vereine_document_file f WHERE f.fk_document = d.rowid)"
                      " AND NOT EXISTS (SELECT 1 FROM llx_vereine_document_file f WHERE f.fk_document = d.rowid AND f.what <> 'built')"
                      " AND NOT EXISTS (SELECT 1 FROM llx_vereine_publication p WHERE p.fk_document = d.rowid AND p.withdrawn_at IS NULL) ORDER BY d.rowid DESC LIMIT 3")
    expect(len(plain) == 3, f"the files have too few documents for the test: {plain}")
    doc, personal, signed_doc = (int(row[0]) for row in plain)
    original_id, original_sha = stack.sql(f"SELECT rowid, sha256 FROM llx_vereine_document_file WHERE fk_document = {doc} ORDER BY rowid DESC LIMIT 1")[0]
    feed_from = int(stack.value("SELECT COALESCE(MAX(rowid), 0) FROM llx_vereine_change"))

    # A shortened version: a PDF of its own, marked as derived; a Word file and the original itself are refused.
    excerpt = b"%PDF-1.4\n% gekuerzte Fassung fuer Mitglieder\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"
    for name, content in (("gekuerzt.docx", b"PK\x03\x04 kein PDF"), ("gekuerzt.pdf", excerpt)):
        page = page_ok(browser.get(base), "the files before the shortened version")
        page_ok(browser.post_multipart(base, [("token", token_of(page)), ("action", "excerpt"), ("document", str(doc))], [("excerpt", name, content)]),
                f"upload {name}")
    rows = stack.sql(f"SELECT rowid, sha256, fk_parent FROM llx_vereine_document_file WHERE fk_document = {doc} AND what = 'excerpt'")
    expect(len(rows) == 1 and rows[0][1] == hashlib.sha256(excerpt).hexdigest() and rows[0][2] == original_id,
           f"the shortened version is not one file derived from the original: {rows}")
    excerpt_id = int(rows[0][0])
    expect(stack.value(f"SELECT sha256 FROM llx_vereine_document_file WHERE rowid = {original_id}") == original_sha, "the original changed")
    code = stack.value(f"SELECT code FROM llx_vereine_document WHERE rowid = {doc}")
    check = page_ok(browser.get(f"/custom/vereine/public/verify.php?code={code}"), "the check of the code")
    expect('data-verify-what="excerpt"' in check.text and original_sha[:16] in check.text, "the check does not show the shortened version as derived")

    # The shortened version for members, the original for the board; one document for one person only.
    client = secrets.token_hex(16)
    stack.php_fixture("apiclient", RT_LOGIN="rtdocmore", RT_CLIENT_KEY=client)
    member, other = (int(row[0]) for row in stack.sql("SELECT rowid FROM llx_adherent WHERE statut = 1 AND rowid NOT IN (SELECT t.fk_adherent FROM llx_vereine_function_term t"
                                                      " INNER JOIN llx_vereine_function f ON f.rowid = t.fk_function WHERE f.board = 1) ORDER BY rowid LIMIT 2"))
    for subject, person in (("sub-more-1", member), ("sub-more-2", other)):
        page = page_ok(browser.get("/custom/vereine/admin/identities.php"), "the identities")
        page = page_ok(browser.submit(page.form(name="vereineidentityinvite"), {"client": "rtdocmore", "member_id": str(person), "application_id": "0",
                                                                                 "capabilities[]": "documents"}), "invite")
        invite = re.search(r"<code>([A-Za-z0-9_-]{30,})</code>", page.text).group(1)
        status, _ = stack.api(f"vereine/identities/claim?subject={subject}&code={invite}", client, method="POST")
        expect(status == 200, f"binding {subject}: HTTP {status}")
    for document, changes in ((doc, {"audience": "members", "file": str(excerpt_id)}), (doc, {"audience": "board"}),
                              (personal, {"audience": "person", "member": str(member)})):
        page = page_ok(browser.get(base), "the files before publishing")
        page_ok(browser.submit(page.form(name=f"vereinepublish{document}"), changes), f"publish {changes}")
    status, first = stack.api("vereine/me/documents?subject=sub-more-1", client)
    status_other, second = stack.api("vereine/me/documents?subject=sub-more-2", client)
    mine = {row["document_id"]: row for row in first} if status == 200 else {}
    theirs = {row["document_id"]: row for row in second} if status_other == 200 else {}
    expect(doc in mine and mine[doc]["revision"] == excerpt_id and mine[doc]["what"] == "excerpt" and mine[doc]["derived_from"] == int(original_id),
           f"the member does not get the shortened version: {mine.get(doc)}")
    expect(personal in mine and mine[personal]["audience"] == "person" and personal not in theirs and doc in theirs,
           f"the document for one person: {sorted(mine)} / {sorted(theirs)}")
    status, public = stack.api("vereine/documents", stack.reader_key)
    expect(status == 200 and all(row["document_id"] not in (doc, personal) for row in public), "a document for members or one person is public")
    status, _ = stack.api(f"vereine/me/documents/{personal}/pdf?subject=sub-more-2", client)
    expect(status == 404, f"another member got the document for one person: HTTP {status}")
    status, pdf = stack.api(f"vereine/me/documents/{doc}/pdf?subject=sub-more-1", client)
    expect(status == 200 and base64.b64decode(pdf["content"]) == excerpt, f"the PDF of the shortened version: HTTP {status}")

    # Withdrawn: the person's document is gone; the feed said published, and revoked when nothing is left.
    publication = stack.value(f"SELECT rowid FROM llx_vereine_publication WHERE fk_document = {personal} AND withdrawn_at IS NULL")
    page = page_ok(browser.get(base), "the files before withdrawing")
    page_ok(browser.submit(page.form(name=f"vereinewithdraw{publication}")), "withdraw the document for one person")
    status, first = stack.api("vereine/me/documents?subject=sub-more-1", client)
    expect(status == 200 and all(row["document_id"] != personal for row in first), "the withdrawn document is still there")
    feed = stack.sql(f"SELECT object_id, change_kind FROM llx_vereine_change WHERE rowid > {feed_from} AND object_type = 'document' ORDER BY rowid")
    expect([str(doc), "created"] in feed and [str(doc), "updated"] in feed and [str(personal), "created"] in feed and feed[-1] == [str(personal), "revoked"],
           f"the change feed: {feed}")
    stack.sql(f"UPDATE llx_vereine_publication SET withdrawn_at = NOW(), reason = 'withdrawn' WHERE fk_document = {doc} AND withdrawn_at IS NULL")

    # A signed copy waits while the run still needs a signature.
    counted = stack.php_fixture("signedrun", RT_DOCUMENT_ID=str(signed_doc))
    expect(counted["open"] == counted["before"] and counted["done"] == counted["before"] + 1, f"a signed copy went out before every signature: {counted}")
    stack.sql(f"UPDATE llx_vereine_publication SET withdrawn_at = NOW(), reason = 'withdrawn' WHERE fk_document = {signed_doc} AND withdrawn_at IS NULL")
    return ("a shortened version as a file of its own, derived from the original that stayed; members got it, the board the original; a document for "
            "one person reached only that person and went with its withdrawal; the feed said published and revoked; a signed copy waited for the last signature")


def eventapi(stack: Stack) -> str:
    """Events through the API: public ones for the website, members' ones for the app, never internal ones; a shift asked for once,
    withdrawn while unconfirmed, a confirmed one not; one place of registration (#165)."""
    browser = stack.browser()
    base = "/custom/vereine/events.php"
    today = datetime.date.fromisoformat(stack.today())
    day = (today + datetime.timedelta(days=21)).isoformat()
    ids = {}
    for label, visibility, registration, ref in (("LAN intern", "0", "none", ""), ("LAN Mitglieder", "2", "none", ""),
                                                 ("LAN offen", "1", "external", "lionsquad.at")):
        page = page_ok(browser.get(base), "the events")
        page_ok(browser.submit(page.form(name="vereineevent"), {"label": label, "event_day": day, "place": "Vereinsheim", "public": visibility,
                                                                "registration": registration, "external_ref": ref}), f"create {label}")
        ids[label] = int(stack.value(f"SELECT rowid FROM llx_vereine_event WHERE label = '{label}' ORDER BY rowid DESC LIMIT 1") or 0)
    members_event = ids["LAN Mitglieder"]
    page = page_ok(browser.get(f"{base}?id={members_event}"), "the members' event")
    expect('data-event-visibility="members"' in page.text, "the event is not marked for members")
    for label, start, end in (("Aufbau", "08:00", "10:00"), ("Kassa", "09:00", "12:00")):
        page = page_ok(browser.get(f"{base}?id={members_event}"), "the event before a shift")
        page_ok(browser.submit(page.form(name="vereineshift"), {"shift_label": label, "shift_day": day, "start_time": start, "end_time": end, "capacity": "1"}),
                f"add the shift {label}")
    shifts = {row[1]: int(row[0]) for row in stack.sql(f"SELECT rowid, label FROM llx_vereine_event_shift WHERE fk_event = {members_event}")}

    status, public = stack.api("vereine/events", stack.reader_key)
    labels = {row["label"]: row for row in public} if status == 200 else {}
    expect("LAN offen" in labels and "LAN Mitglieder" not in labels and "LAN intern" not in labels
           and labels["LAN offen"]["registration"] == {"kind": "external", "external_ref": "lionsquad.at"}, f"the public events: {public}")

    client = secrets.token_hex(16)
    stack.php_fixture("apiclient", RT_LOGIN="rtevents", RT_CLIENT_KEY=client)
    member = stack.value("SELECT rowid FROM llx_adherent WHERE statut = 1 ORDER BY rowid LIMIT 1")
    page = page_ok(browser.get("/custom/vereine/admin/identities.php"), "the identities")
    page = page_ok(browser.submit(page.form(name="vereineidentityinvite"), {"client": "rtevents", "member_id": member, "application_id": "0",
                                                                             "capabilities[]": "events"}), "invite")
    code = re.search(r"<code>([A-Za-z0-9_-]{30,})</code>", page.text).group(1)
    status, bound = stack.api(f"vereine/identities/claim?subject=sub-events&code={code}", client, method="POST")
    expect(status == 200, f"binding: HTTP {status} {bound}")
    status, mine = stack.api("vereine/me/events?subject=sub-events", client)
    seen = {row["label"]: row for row in mine} if status == 200 else {}
    expect("LAN Mitglieder" in seen and "LAN offen" in seen and "LAN intern" not in seen and len(seen["LAN Mitglieder"]["shifts"]) == 2,
           f"the member's events: {list(seen)}")

    path = f"vereine/me/events/{members_event}/shifts/{shifts['Aufbau']}?subject=sub-events"
    for _ in range(2):
        status, event = stack.api(path, client, method="PUT")
    entries = stack.value(f"SELECT COUNT(*) FROM llx_vereine_event_shift_entry WHERE fk_shift = {shifts['Aufbau']} AND fk_adherent = {member} AND status <> 'cancelled'")
    mine_now = next(row["mine"] for row in event["shifts"] if row["id"] == shifts["Aufbau"]) if status == 200 else ""
    expect(status == 200 and mine_now == "requested" and entries == "1", f"asked twice: HTTP {status}, {mine_now}, {entries} entries")
    status, _ = stack.api(f"vereine/me/events/{members_event}/shifts/{shifts['Kassa']}?subject=sub-events", client, method="PUT")
    expect(status == 409, f"an overlapping shift was taken: HTTP {status}")
    status, event = stack.api(path, client, method="DELETE")
    expect(status == 200 and next(row["mine"] for row in event["shifts"] if row["id"] == shifts["Aufbau"]) == "cancelled", f"withdrawn: HTTP {status}")
    status, event = stack.api(path, client, method="PUT")
    expect(status == 200 and next(row["mine"] for row in event["shifts"] if row["id"] == shifts["Aufbau"]) == "requested", f"asked again after withdrawing: HTTP {status}")
    stack.sql(f"UPDATE llx_vereine_event_shift_entry SET status = 'confirmed' WHERE fk_shift = {shifts['Aufbau']} AND fk_adherent = {member} AND status = 'requested'")
    status, _ = stack.api(path, client, method="DELETE")
    expect(status == 409, f"a confirmed shift was withdrawn through the app: HTTP {status}")
    status, _ = stack.api(f"vereine/me/events/{ids['LAN intern']}/shifts/{shifts['Aufbau']}?subject=sub-events", client, method="PUT")
    expect(status == 404, f"a shift through an internal event: HTTP {status}")
    return ("the website saw only the public event with its external registration; the app saw public and members' events, never the internal one; "
            "a shift asked twice is one request, an overlapping one refused, withdrawn while unconfirmed and asked again, a confirmed one not")


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
    ("setupguide", "First steps: every step open after enabling, association data close step 1, a missing module explained", setupguide, ("pages",)),
    ("setup", "Setup validates, normalises and stores the association", setup, ("setupguide",)),
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
    ("websiteprofile", "The website profile of a member and its photo leave only with the chosen consent", websiteprofile, ("website",)),
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
    ("history", "History of a member in Dolibarr's events: new entries and earlier ones exactly once", history, ("authority",)),
    ("application", "Application for membership and declaration of consent as PDF, with fields to fill in", application, ("history",)),
    ("sepaonline", "SEPA mandate at the member: state, Dolibarr's own online signature, invitation", sepaonline, ("application",)),
    ("todo", "What is to do: deadlines, elections and applications of the association and of the person", todo, ("sepaonline",)),
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
    ("placeholders", "Placeholders: association data in Dolibarr's e-mail templates, the module's e-mails as templates, one list with examples", placeholders, ("qes",)),
    ("taxcheck", "Older invoice lines without a tax profile: suggestions from facts, a preview, assigning only the profile", taxcheck, ("placeholders",)),
    ("vatex", "0 % with a reason: codes in the VAT dictionary, VATEX for not subject to VAT, products and lines take them", vatex, ("taxcheck",)),
    ("audit", "The audit of the auditors: bookings and invoices with hints, samples, checklist, report with signatures", audit, ("vatex",)),
    ("account", "Income and expenditure account: the money of the year by area, agreeing with the bank, statement of assets, PDF, signatures", account, ("audit",)),
    ("duties", "The calendar of duties: catalogue of the law, days of the year, agenda tasks, handover after a change of office", duties, ("account",)),
    ("events", "Events from templates: a project of Dolibarr with its tasks, the checklist, a template that changes later", events, ("duties",)),
    ("shifts", "Helper shifts: places, overlapping times, confirmed duties, and the short report as PDF", shifts, ("events",)),
    ("assembly", "The way through a general assembly: deadlines before, the day itself, and what follows from it", assembly, ("shifts", "minutes")),
    ("changes", "The change feed: notes without content, a cursor that loses nothing, resync instead of a silent gap", changes, ("assembly",)),
    ("webhooks", "Signed webhooks: https targets only, delivery after the commit, backoff, rotation, reference receiver", webhooks, ("changes",)),
    ("identities", "Verified external identities: two clients, one contract, bindings that open exactly their own object", identities, ("webhooks",)),
    ("applicationfields", "The fields of an application: one list for PDF and web, own fields, the fee in real numbers", applicationfields, ("identities", "application")),
    ("volunteers", "Volunteer allowances: marked over the limit when stored, the list of the year, a helper shift paid once", volunteers, ("shifts",)),
    ("volunteerpayout", "Paying volunteer allowances: a list, refused until signed, then Dolibarr's various payments", volunteerpayout, ("volunteers", "signatures")),
    ("overpayments", "Overpayments: 37,68 paid with 38,00, the 0,32 assigned once to a credit, a refund or a donation", overpayments, ("account", "volunteerpayout")),
    ("donations", "Donation report: date of birth encrypted, vbPK from the register file, XML against the schema, protocol, E then A", donations, ("overpayments",)),
    ("archive", "Files of the association: PDF/A with a code, a public check that shows no title, the export with checksums", archive, ("donations",)),
    ("disclosure", "Access to one's own data: request with its check, a copy with the member's rows and nobody else's", disclosure, ("archive",)),
    ("social", "Channels of the association and accounts of members: order, own network, application, confirmation by an app", social,
     ("identities", "applicationfields")),
    ("honours", "Honours and statistics: jubilee once, birthdays with consent, honorary member, certificate, members on a day as a file", honours, ("social",)),
    ("inventory", "Equipment and loans: lend with state, no second loan, late reminder once to the borrower, return, reservation", inventory, ("honours",)),
    ("documents", "Publishing documents: rule for signed ones, by hand for the public, withdrawn gone, app and website see theirs", documents, ("inventory",)),
    ("statuteapi", "Statutes through the API: published or not, in force, future, ambiguous, missing file", statuteapi, ("documents",)),
    ("meetingapi", "Meetings through the API: only invited ones, answer, motion once and late, board decides, cancelled", meetingapi, ("statuteapi",)),
    ("profileapi", "Own data through the API: change at once or for the board, conflict, nothing else writable, notice of the exit", profileapi, ("meetingapi",)),
    ("eventapi", "Events through the API: public for the website, members' for the app, shifts asked and withdrawn", eventapi, ("profileapi",)),
    ("documentmore", "Documents: a shortened version derived from the original, one person only, the change feed, signed copies when complete",
     documentmore, ("eventapi",)),
    ("ballotapi", "Ballots of a general assembly: two applications and paper, proxies, frozen rights, one right counts once", ballotapi, ("documentmore",)),
    ("ballotresult", "Counting ballots: proof as PDF, provisional until confirmed, confirmed once, an election's term once", ballotresult, ("ballotapi",)),
    ("portal", "Dolibarr's web portal: the page of the association, the same documents and ballots as the API, no second vote", portal, ("ballotresult",)),
    ("apidocs", "API tab: every endpoint with its rights, users with an API key, never the key", apidocs, ("account", "duties")),
    ("openapi", "Every endpoint answered and every answer matched docs/openapi.json", openapi, ("apidocs",)),
    ("erasure", "Erasing after the exit: preview, hold, only what is due, the name last", erasure, ("disclosure", "openapi")),
    ("disable", "Disabling keeps data and rights for the next activation", disable, ("partners",)),
    ("arrears", "With the Mahnwesen module: one proposal per fee at the last step, updated by payment and pause, board only", arrears, ("erasure", "disable")),
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
