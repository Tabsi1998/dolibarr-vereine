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

from dolibarr_http import Browser, Page, token_of
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
    web_port: int
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

    def api(self, path: str, key: str | None, check: bool = True) -> tuple[int, object]:
        """GET an API path. Answers of the Vereine API must match docs/openapi.json unless check is off."""
        request = urllib.request.Request(f"{self.url}/api/index.php/{path.lstrip('/')}", method="GET",
                                         headers={"Accept": "application/json", **({"DOLAPIKEY": key} if key else {})})
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
            problems = self.openapi.check("GET", path, status, body)
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

    stack.php_fixture("reset")
    expect(stack.const("MAIN_MODULE_VEREINE") is None and stack.const("VEREINE_REGISTER_NUMBER") is None,
           "the reset after the upgrade test left module state behind")
    return f"{old} -> {stack.module_version}: association data kept; tables, website right and categories in place"


def deploy(stack: Stack) -> str:
    """The package goes in the way an administrator installs it: Deploy an external module."""
    if stack.previous_package is None:
        expect(stack.shell(f"test ! -e {MODULE_DIR}").returncode == 0, "the module exists before the upload")
    files = upload(stack, stack.package)
    return f"{stack.package.name} deployed: {len(files)} files in custom/vereine on Dolibarr {stack.fixtures['dolibarr']}"


def enable(stack: Stack) -> str:
    """Enabling from the module list registers rights, menu and the country profile."""
    browser = stack.browser()
    page = module_list(browser)
    expect("Vereine (AT/DE)" in page.text, "the module list does not show the translated module name")
    page_ok(browser.get(module_link(page, "set")), "enable")
    expect(stack.const("MAIN_MODULE_VEREINE") == "1", "MAIN_MODULE_VEREINE is not 1 after enabling")
    for module in ("MAIN_MODULE_ADHERENT", "MAIN_MODULE_SOCIETE", "MAIN_MODULE_CATEGORIE"):
        expect(stack.const(module) == "1", f"{module} was not enabled together with Vereine")
    expect(stack.const("VEREINE_COUNTRY_PROFILE") == "AT",
           f"an Austrian company should start with profile AT, found {stack.const('VEREINE_COUNTRY_PROFILE')!r}")
    rights = stack.sql("SELECT id, perms, subperms FROM llx_rights_def WHERE module = 'vereine' AND entity = 1 ORDER BY id")
    expect(rights == [["49210001", "association", "read"], ["49210002", "partner", "write"], ["49210003", "website", "read"]],
           f"rights after enabling: {rights}")
    menu = sorted(stack.sql("SELECT mainmenu, leftmenu, url FROM llx_menu WHERE module = 'vereine' AND entity = 1"))
    expect(menu == [["members", "vereine", "/vereine/vereineindex.php"], ["members", "vereine_partners", "/vereine/partners.php"],
                    ["members", "vereine_partnersetup", "/vereine/admin/partners.php"]],
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
    return (f"module {stack.module_version} on with Members, third parties and categories; profile AT; "
            "3 rights, 3 menu entries, log table, 3 categories")


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
    expect(form.value("VEREINE_COUNTRY_PROFILE") == "AT", "the setup does not preselect profile AT")
    expect(form.has("VEREINE_AUTHORITY") and not form.has("VEREINE_REGISTER_COURT"),
           "the Austrian setup must ask for the authority, not a register court")
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
    # module sees it; nothing may be stored.
    form = browser.get("/custom/vereine/admin/setup.php").form(name="vereinesetup")
    blocked = browser.submit(form, {"VEREINE_PURPOSE": "Zweck <script>alert(1)</script>"})
    expect(blocked.status == 403, f"a script in the purpose was not refused by Dolibarr (HTTP {blocked.status})")
    expect(not stack.const("VEREINE_PURPOSE"), "a refused request stored a purpose")

    # Markup Dolibarr lets through is the module's to strip and escape.
    form = browser.get("/custom/vereine/admin/setup.php").form(name="vereinesetup")
    saved = browser.submit(form, {
        "VEREINE_REGISTER_NUMBER": " 123 456 789 ",
        "VEREINE_AUTHORITY": "Landespolizeidirektion Tirol",
        "foundedday": "1", "foundedmonth": "3", "foundedyear": "2019",
        "VEREINE_NONPROFIT": "1",
        "VEREINE_PURPOSE": 'Förderung des E-Sports <i>im Verein</i> & "gemeinsam"',
    })
    page_ok(saved, "setup after saving")
    expected = {"VEREINE_REGISTER_NUMBER": "123456789", "VEREINE_AUTHORITY": "Landespolizeidirektion Tirol",
                "VEREINE_FOUNDED": "2019-03-01", "VEREINE_NONPROFIT": "1"}
    stored = {name: stack.const(name) for name in expected}
    expect(stored == expected, f"stored {stored}, expected {expected}")
    purpose = stack.const("VEREINE_PURPOSE") or ""
    expect(purpose.startswith("Förderung des E-Sports"), f"the purpose lost its text or its umlaut: {purpose!r}")
    expect("<i>" not in purpose, f"markup was stored in the purpose: {purpose!r}")

    overview = page_ok(browser.get("/custom/vereine/vereineindex.php"), "overview after saving")
    expect(data_status(overview, "register") == "ok", "the overview still reports the ZVR number as missing")
    expect("123456789" in overview.text, "the overview does not show the stored ZVR number")
    # Dolibarr's input filter drops the quotes; the ampersand must reach the page escaped.
    expect(re.search(r"im Verein &amp; (&quot;)?gemeinsam", overview.text) is not None
           and "im Verein & " not in overview.text, "the overview prints the purpose without escaping")

    form = browser.get("/custom/vereine/admin/setup.php").form(name="vereinesetup")
    page_ok(browser.submit(form, {"VEREINE_COUNTRY_PROFILE": "DE"}), "switch to Germany")
    expect(stack.const("VEREINE_COUNTRY_PROFILE") == "DE", "the profile did not switch to DE")
    expect(not stack.const("VEREINE_REGISTER_NUMBER"), "an Austrian ZVR number survived the switch to Germany")
    german = browser.get("/custom/vereine/admin/setup.php").form(name="vereinesetup")
    expect(german.has("VEREINE_REGISTER_COURT") and not german.has("VEREINE_AUTHORITY"),
           "the German setup must ask for the register court")

    page_ok(browser.submit(german, {"VEREINE_COUNTRY_PROFILE": "AT", "VEREINE_REGISTER_NUMBER": "123456789",
                                    "VEREINE_AUTHORITY": "Landespolizeidirektion Tirol"}), "switch back to Austria")
    expect(stack.const("VEREINE_COUNTRY_PROFILE") == "AT" and stack.const("VEREINE_REGISTER_NUMBER") == "123456789",
           "switching back to Austria did not store profile and ZVR number")
    return "bad ZVR refused and kept on the form, data normalised, markup stripped, AT/DE switch clears the number"


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
    for stream in re.findall(rb"stream\r?\n(.*?)\r?\nendstream", data, re.S):
        try:
            parts.append(zlib.decompress(stream))
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
    expect(paid["fee"] == {"required": True, "status": "paid", "next_due": day_after(dates["paid_until"]), "amount": 50, "payment_url": ""},
           f"fee of the paid member: {paid['fee']}")
    expect(paid["open_invoices"] == [{"id": invoices["open"]["id"], "ref": invoices["open"]["ref"], "type": "standard",
                                      "date": dates["open_invoice"], "due_date": dates["open_invoice"], "total": 60, "remaining": 50,
                                      "status": "overdue", "overdue": True, "payment_url": ""}],
           f"only the validated unpaid invoice with its part payment is open: {paid['open_invoices']}")

    expired = answers["expired"]
    expect(expired["status"] == "active" and expired["paid_until"] == dates["expired_until"] and expired["member_since"] == dates["expired_since"]
           and expired["fee"]["status"] == "due" and expired["fee"]["next_due"] == day_after(dates["expired_until"]),
           f"expired member: {expired}")
    unpaid = answers["unpaid"]
    expect(unpaid["paid_until"] == "" and unpaid["member_since"] == dates["today"]
           and unpaid["fee"] == {"required": True, "status": "due", "next_due": dates["today"], "amount": 50, "payment_url": ""},
           f"member who never paid: {unpaid}")
    free = answers["free"]
    expect(free["type"]["label"] == "Ordentliches Mitglied"
           and free["fee"] == {"required": False, "status": "not_required", "next_due": "", "amount": None, "payment_url": ""},
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
         "total": 20, "remaining": 0, "status": "abandoned", "overdue": False, "payment_url": ""},
        {"id": invoices["paid"]["id"], "ref": invoices["paid"]["ref"], "type": "standard", "date": dates["paid_invoice"],
         "due_date": dates["paid_invoice"], "total": 30, "remaining": 0, "status": "paid", "overdue": False, "payment_url": ""},
        {"id": invoices["open"]["id"], "ref": invoices["open"]["ref"], "type": "standard", "date": dates["open_invoice"],
         "due_date": dates["open_invoice"], "total": 60, "remaining": 50, "status": "overdue", "overdue": True, "payment_url": ""},
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
    expect(stack.const("VEREINE_COUNTRY_PROFILE") == "AT", "re-enabling replaced the chosen country profile")
    reader = stack.browser("rtreader")
    page_ok(reader.get("/custom/vereine/vereineindex.php"), "overview for the reader after enabling again")
    status, _ = stack.api("vereine/status", stack.reader_key)
    expect(status == 200, f"the API answers HTTP {status} after enabling again")
    return f"off: pages refused, API HTTP {stack.notes['disabled_api_status']}; on again: data, profile and the reader's right kept"


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
    ("enable", "Enabling registers rights, menu and the country profile", enable, ("deploy",)),
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
    ("openapi", "Every endpoint answered and every answer matched docs/openapi.json", openapi, ("websitesync",)),
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
