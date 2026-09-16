#!/usr/bin/env bash
# Dolibarr's own coding standard over every PHP file of the module: PHP_CodeSniffer
# with the ruleset of the Dolibarr release below, at the severity Dolibarr's CI
# uses. Tool and ruleset are downloaded at pinned versions and verified by
# SHA-256, so the result cannot change on its own between two runs.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PHPCS_VERSION="4.0.4"
PHPCS_SHA256="4b010cd21d8bc8a17e2504792e3c77ef8259126f24caf7eafe7eddef1fa871e9"
DOLIBARR_REF="24.0.1"
declare -A RULESET_SHA256=(
  [ruleset.xml]="e7209e5354350123a1f3c4f2a48861595d70af09f754ef4ada200ae19d31329d"
  [ruleset.dtd]="f27128abf40733c6e93a9cd7cc6fb940e90a7e289e20847dc465a4ca68dffd93"
  [Sniffs/Dolibarr/CheckIsModEnabledArgumentSniff.php]="93457c75a74c375a6e535ea85c79ef7ab0c43af9db70698bddaeb54dc954cdae"
  [Sniffs/Dolibarr/LanguageOfCommentsSniff.php]="28c2dc953079e35f8c5c37741bb1f6455fc3ce312d978f6d09aaac49485e9668"
)

command -v php >/dev/null 2>&1 || { echo "php is not installed" >&2; exit 2; }
command -v curl >/dev/null 2>&1 || { echo "curl is not installed" >&2; exit 2; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

fetch() {
  local url="$1" target="$2" expected="$3" actual
  mkdir -p "$(dirname "$target")"
  curl --fail --silent --show-error --location --retry 3 "$url" -o "$target"
  actual="$(sha256sum "$target" | cut -d' ' -f1)"
  if [ "$actual" != "$expected" ]; then
    echo "SHA-256 of $url is $actual, expected $expected" >&2
    exit 1
  fi
}

fetch "https://github.com/PHPCSStandards/PHP_CodeSniffer/releases/download/${PHPCS_VERSION}/phpcs.phar" \
  "$WORK/phpcs.phar" "$PHPCS_SHA256"
# The ruleset's own folder is named codesniffer; its custom sniffs resolve from there.
STANDARD="$WORK/dolibarr/dev/setup/codesniffer"
for file in "${!RULESET_SHA256[@]}"; do
  fetch "https://raw.githubusercontent.com/Dolibarr/dolibarr/${DOLIBARR_REF}/dev/setup/codesniffer/${file}" \
    "$STANDARD/$file" "${RULESET_SHA256[$file]}"
done

files=()
while IFS= read -r -d '' file; do
  files+=("$file")
done < <(find . -type f -name '*.php' \
  -not -path './.git/*' -not -path './.local-testing/*' -not -path './dist/*' -not -path './vendor/*' -print0 | sort -z)

set +e
report="$(php "$WORK/phpcs.phar" --standard="$STANDARD/ruleset.xml" --severity=5 --extensions=php \
  --report=emacs --no-colors -d memory_limit=512M "${files[@]}" 2>&1)"
status=$?
set -e

findings="$(printf '%s\n' "$report" | grep -E '^.+:[0-9]+:[0-9]+: (error|warning) - ' || true)"
if [ -n "$findings" ]; then
  printf '%s\n' "$findings" >&2
  echo "Dolibarr coding standard: $(printf '%s\n' "$findings" | wc -l) findings" >&2
  exit 1
fi
if [ "$status" -ne 0 ]; then
  printf '%s\n' "$report" >&2
  echo "phpcs ended with exit code $status without reporting findings" >&2
  exit 1
fi
echo "Coding standard: OK (${#files[@]} files, PHP_CodeSniffer ${PHPCS_VERSION}, Dolibarr ${DOLIBARR_REF} ruleset)"
