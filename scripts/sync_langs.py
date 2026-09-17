#!/usr/bin/env python3
"""Copy langs/de_DE/vereine.lang to langs/en_US/vereine.lang.

The module is for Austrian associations and speaks German. Dolibarr loads the
language of the user and does not fall back to German, so a user with an English
interface would see language keys instead of texts. en_US is therefore an exact
copy of de_DE; tests/run.php refuses a difference.

Usage:
    python scripts/sync_langs.py
"""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def main() -> int:
    source = ROOT / "langs" / "de_DE" / "vereine.lang"
    target = ROOT / "langs" / "en_US" / "vereine.lang"
    content = source.read_bytes()
    if target.exists() and target.read_bytes() == content:
        print("langs/en_US/vereine.lang is already a copy of de_DE")
        return 0
    target.write_bytes(content)
    print("langs/en_US/vereine.lang copied from de_DE")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
