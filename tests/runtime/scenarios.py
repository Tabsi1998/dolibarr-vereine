"""What the runtime checks prove in a running Dolibarr.

scripts/local_check.py starts one Dolibarr per supported version with MariaDB,
runs fixtures.php base, and then calls the scenarios below in order. Each
scenario raises CheckFailed with what went wrong and returns a one-line result.
They use only what an administrator, a user and a website use: the module
upload, the module list, the pages, the REST API - and the database to verify.
"""

from __future__ import annotations

import html
import json
import re
import subprocess
import time
import urllib.error
import urllib.request
import zipfile
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable

from dolibarr_http import Browser, Page

MODULE_DIR = "/var/www/html/custom/vereine"
PHP_PROBLEM = re.compile(r"PHP (Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable fatal error):"
                         r"\s*(.+?) in (/var/www/html/custom/vereine/\S+) on line \d+")


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

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.web_port}"

    @property
    def module_version(self) -> str:
        with zipfile.ZipFile(self.package) as bundle:
            text = bundle.read("vereine/core/modules/modVereine.class.php").decode("utf-8")
        return re.search(r"\$this->version\s*=\s*'([^']+)'", text).group(1)

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

    def php_fixture(self, stage: str) -> dict:
        completed = self.run(self.docker, "exec", "-u", "www-data",
                             "--env", f"RT_READER_PASSWORD={self.reader_password}",
                             "--env", f"RT_NOBODY_PASSWORD={self.nobody_password}",
                             "--env", f"RT_READER_KEY={self.reader_key}",
                             "--env", f"RT_NOBODY_KEY={self.nobody_key}",
                             self.web, "php", "/opt/vereine-tests/fixtures.php", stage, check=False, timeout=600)
        if completed.returncode != 0:
            raise CheckFailed(f"fixtures.php {stage} failed on Dolibarr {self.version}:\n"
                              f"{(completed.stdout + completed.stderr)[-1500:]}")
        return parse_fixtures(completed.stdout)

    def shell(self, command: str) -> subprocess.CompletedProcess:
        return self.run(self.docker, "exec", "-u", "www-data", self.web, "sh", "-c", command, check=False, timeout=120)

    def api(self, path: str, key: str | None) -> tuple[int, object]:
        request = urllib.request.Request(f"{self.url}/api/index.php/{path.lstrip('/')}", method="GET",
                                         headers={"Accept": "application/json", **({"DOLAPIKEY": key} if key else {})})
        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                status, body = response.status, response.read()
        except urllib.error.HTTPError as error:
            status, body = error.code, error.read()
        try:
            return status, json.loads(body.decode("utf-8") or "null")
        except ValueError:
            return status, body.decode("utf-8", errors="replace")

    def log(self) -> str:
        completed = self.run(self.docker, "logs", self.web, check=False, timeout=120)
        return completed.stdout + completed.stderr


def page_ok(page: Page, what: str) -> Page:
    expect(page.status == 200, f"{what}: HTTP {page.status}")
    expect(not page.denied(), f"{what}: access denied")
    problems = page.errors()
    expect(not problems, f"{what}: the page shows {', '.join(problems)}")
    return page


def denied(page: Page) -> bool:
    """Dolibarr's accessforbidden() page, whatever the language."""
    if page.denied():
        return True
    # accessforbidden() prints its reason in <div class="error"> and nothing of the page it refused.
    refused_page_parts = ('name="vereinesetup"', "data-check=", "page-admin-about")
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


def data_status(page: Page, check: str) -> str | None:
    match = re.search(rf'data-check="{re.escape(check)}" data-status="([a-z]+)"', page.text)
    return match.group(1) if match else None


# ------------------------------------------------------------------ scenarios

def deploy(stack: Stack) -> str:
    """The package goes in the way an administrator installs it: Deploy an external module."""
    expect(stack.fixtures.get("dolibarr", "").startswith(stack.version.rsplit(".", 1)[0]),
           f"the container runs Dolibarr {stack.fixtures.get('dolibarr')}, expected {stack.version}")
    expect(stack.shell(f"test ! -e {MODULE_DIR}").returncode == 0, "the module exists before the upload")
    browser = stack.browser()
    page = page_ok(browser.get("/admin/modules.php?mode=deploy"), "deploy page")
    form = page.form(name="forminstall")
    # checkforcompliance asks dolibarr.org for a blacklist; the check runs offline.
    fields = [(name, value) for name, value in form.values() if name != "checkforcompliance"]
    result = browser.post_multipart(form.url(), fields, [("fileinstall", stack.package.name, stack.package.read_bytes())])
    expect(result.status == 200, f"the upload answered HTTP {result.status}")
    expect(not result.errors(), f"the upload page shows {', '.join(result.errors())}")
    installed = stack.shell(f"cd {MODULE_DIR} && find . -type f | sort")
    expect(installed.returncode == 0, f"the upload did not create {MODULE_DIR}:\n{result.text[-800:]}")
    files = [line[2:] for line in installed.stdout.splitlines() if line.startswith("./")]
    with zipfile.ZipFile(stack.package) as bundle:
        packaged = sorted(info.filename[len("vereine/"):] for info in bundle.infolist() if not info.is_dir())
    expect(files == packaged, f"deployed files differ from the package: "
                              f"missing {sorted(set(packaged) - set(files))[:5]}, extra {sorted(set(files) - set(packaged))[:5]}")
    return f"{stack.package.name} deployed: {len(files)} files in custom/vereine on Dolibarr {stack.fixtures['dolibarr']}"


def enable(stack: Stack) -> str:
    """Enabling from the module list registers rights, menu and the country profile."""
    browser = stack.browser()
    page = module_list(browser)
    expect("Vereine (AT/DE)" in page.text, "the module list does not show the translated module name")
    page_ok(browser.get(module_link(page, "set")), "enable")
    expect(stack.const("MAIN_MODULE_VEREINE") == "1", "MAIN_MODULE_VEREINE is not 1 after enabling")
    expect(stack.const("MAIN_MODULE_ADHERENT") == "1", "the Members module is not enabled")
    expect(stack.const("VEREINE_COUNTRY_PROFILE") == "AT",
           f"an Austrian company should start with profile AT, found {stack.const('VEREINE_COUNTRY_PROFILE')!r}")
    right = stack.sql("SELECT id, perms, subperms FROM llx_rights_def WHERE module = 'vereine' AND entity = 1")
    expect(right == [["49210001", "association", "read"]], f"rights after enabling: {right}")
    menu = stack.sql("SELECT mainmenu, leftmenu, url FROM llx_menu WHERE module = 'vereine' AND entity = 1")
    expect(menu == [["members", "vereine", "/vereine/vereineindex.php"]], f"menu entries after enabling: {menu}")
    granted = stack.php_fixture("rights")
    expect(granted.get("right") == 49210001, f"granting the right returned {granted}")
    return f"module {stack.module_version} on, Members on, profile AT, right 49210001, menu under Members"


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
    return "overview with checks, setup, about and the Members menu entry render in German"


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
    nobody = stack.browser("rtnobody")
    expect(denied(nobody.get("/custom/vereine/vereineindex.php")), "a user without the right opens the overview")
    members = nobody.get("/adherents/index.php?mainmenu=members&leftmenu=")
    expect("/custom/vereine/vereineindex.php" not in members.text, "the menu entry shows for a user without the right")
    return "reader: overview yes, setup and about no; without the right: neither page nor menu entry"


def api(stack: Stack) -> str:
    """The REST API answers the reader, refuses others, and returns the stored association."""
    status, body = stack.api("vereine/status", stack.reader_key)
    expect(status == 200, f"GET vereine/status answered HTTP {status}: {body}")
    expect(body == {"module_version": stack.module_version, "api_version": 1, "country_profile": "AT",
                    "country_profile_complete": True}, f"GET vereine/status returned {body}")
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


def disable(stack: Stack) -> str:
    """Disabling hides pages and API but keeps data and granted rights for the next activation."""
    browser = stack.browser()
    page_ok(browser.get(module_link(module_list(browser), "reset")), "disable")
    expect(stack.const("MAIN_MODULE_VEREINE") is None, "MAIN_MODULE_VEREINE is still set after disabling")
    expect(stack.value("SELECT COUNT(*) FROM llx_menu WHERE module = 'vereine'") == "0", "menu entries survived disabling")
    expect(denied(browser.get("/custom/vereine/vereineindex.php")), "the overview opens while the module is disabled")
    status, _ = stack.api("vereine/organization", stack.reader_key)
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
    for match in PHP_PROBLEM.finditer(stack.log()):
        found.add(f"{match.group(3).replace(MODULE_DIR + '/', '')}: {match.group(1)}: {match.group(2)}")
    return found


SCENARIOS = (
    ("deploy", "The package deploys through Deploy an external module", deploy, ()),
    ("enable", "Enabling registers rights, menu and the country profile", enable, ("deploy",)),
    ("pages", "Overview, setup, about and the menu entry render", pages, ("enable",)),
    ("setup", "Setup validates, normalises and stores the association", setup, ("pages",)),
    ("access", "Rights decide who sees overview and setup", access, ("setup",)),
    ("api", "REST API answers with the right and refuses without", api, ("setup",)),
    ("disable", "Disabling keeps data and rights for the next activation", disable, ("access", "api")),
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
