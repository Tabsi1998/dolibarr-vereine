#!/usr/bin/env bash
# Checks that need PHP but no Dolibarr: syntax, unit tests and the security
# rules every page of the module follows. Runs in the official PHP images
# (scripts/local_check.py) and on GitHub (ci.yml). Each part prints its marker
# line only when it really ran, so a caller can prove nothing was skipped.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if ! command -v php >/dev/null 2>&1; then
  echo "php is not installed; nothing was checked" >&2
  exit 2
fi

fail() {
  echo "$1" >&2
  exit 1
}

# Module code only: never the local test output or a built package.
php_files() {
  find . -type f -name '*.php' \
    -not -path './.git/*' -not -path './.local-testing/*' -not -path './dist/*' -not -path './vendor/*' \
    -print0 | sort -z
}

count=0
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null || fail "PHP syntax error in $file"
  count=$((count + 1))
done < <(php_files)
echo "PHP lint: OK ($count files on PHP $(php -r 'echo PHP_VERSION;'))"

php tests/run.php

# The pages a browser opens. Every one loads Dolibarr, refuses when the module
# is off and checks a right or administrator status before it does anything.
pages=(vereineindex.php partners.php fees_run.php functions.php authority.php admin/functions.php partner_membership.php member_association.php admin/setup.php admin/partners.php admin/taxprofiles.php admin/fees.php admin/consents.php admin/statutes.php admin/about.php)
for page in "${pages[@]}"; do
  grep -qi 'include of main fails' "$page" || fail "$page does not load main.inc.php"
  grep -q "isModEnabled('vereine')" "$page" || fail "$page does not refuse when the module is disabled"
  grep -qE "hasRight\('vereine'|empty\(\\\$user->admin\)" "$page" || fail "$page checks neither a right nor administrator status"
  grep -q 'accessforbidden(' "$page" || fail "$page never calls accessforbidden()"
done
for file in *.php admin/*.php; do
  [ -f "$file" ] || continue
  known=0
  for page in "${pages[@]}"; do
    if [ "$page" = "$file" ]; then known=1; fi
  done
  [ "$known" -eq 1 ] || fail "$file is a page the security contract does not know; add it to pages in scripts/check-module.sh"
done

# Every form posts Dolibarr's CSRF token.
while IFS= read -r -d '' file; do
  # grep exits 1 when a file has no form; with pipefail that must not end the script.
  forms=$( (grep -o '<form' "$file" || true) | wc -l)
  tokens=$( (grep -o 'name="token" value="'"'"'.newToken()' "$file" || true) | wc -l)
  if [ "$forms" -ne "$tokens" ]; then
    fail "$file has $forms forms but $tokens CSRF tokens"
  fi
done < <(php_files)

# Input only through GETPOST*, no code execution, no calls to other servers:
# DoliStore refuses modules that phone home, and a shell call is never needed.
module_code() {
  find . -type f -name '*.php' \
    -not -path './.git/*' -not -path './.local-testing/*' -not -path './dist/*' -not -path './tests/*' \
    -print0
}
# shellcheck disable=SC2016 # the dollar sign belongs to the PHP code searched for
if module_code | xargs -0 grep -nE '\$_(GET|POST|REQUEST|COOKIE)\b'; then
  fail "read request data through GETPOST(), never from the superglobals"
fi
if module_code | xargs -0 grep -nE '\b(eval|exec|shell_exec|system|passthru|popen|proc_open|assert|create_function)[[:space:]]*\('; then
  fail "code execution functions are not allowed in the module"
fi
if module_code | xargs -0 grep -nE '\bbase64_decode[[:space:]]*\(|\b(curl_init|fsockopen|getURLContent)[[:space:]]*\(|file_get_contents[[:space:]]*\([[:space:]]*["'"'"']https?:'; then
  fail "the module must not decode hidden code or call other servers"
fi
if module_code | xargs -0 grep -nE 'DOL_DOCUMENT_ROOT[^;]*(fopen|file_put_contents|mkdir|dol_mkdir)|(fopen|file_put_contents)[[:space:]]*\([^;]*DOL_DOCUMENT_ROOT'; then
  fail "the module writes only below DOL_DATA_ROOT, never into the program folder"
fi

# The API answers only to users with the right, and only while the module is on.
grep -q "private function checkAccess()" class/api_vereine.class.php || fail "the API lost its access check"
public_methods=$(grep -cE '^[[:space:]]*public function (get|post|put|delete)[A-Za-z]*\(' class/api_vereine.class.php || true)
# shellcheck disable=SC2016 # the dollar sign belongs to the PHP code searched for
checked=$(grep -c '\$this->checkAccess();' class/api_vereine.class.php || true)
[ "$public_methods" -eq "$checked" ] || fail "class/api_vereine.class.php has $public_methods endpoints but $checked access checks"
grep -q "^class Vereine extends DolibarrApi" class/api_vereine.class.php \
  || fail "the API class must be named Vereine: Dolibarr 22 and 23 dispatch /vereine only to that name"

echo "Security contracts: OK (${#pages[@]} pages, $public_methods API endpoints)"
