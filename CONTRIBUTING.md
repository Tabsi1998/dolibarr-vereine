# Mitarbeit

Beiträge sind willkommen – Code, Korrekturen an Texten und Rechtsregeln,
Fehlermeldungen. Nie echte Mitglieds-, Spender- oder Bankdaten, Zugangsdaten
oder Datenbankauszüge in Issues, Tests oder Screenshots verwenden.

## Wie die Arbeit organisiert ist

- Jede Änderung beginnt mit einem Issue, beschriftet mit seiner Art (`bug`,
  `enhancement`, `documentation`, `ci`, `release`, `security`) und seinem Bereich
  (`bereich: ...`), zugeordnet zu einem Meilenstein.
- Ein Branch je Issue: `feat/<issue>-<thema>`, `fix/<issue>-<thema>`,
  `docs/<issue>-<thema>`, `ci/<issue>-<thema>`.
- Pull Requests schließen ihre Issues (`Closes #12`), nichts wird direkt auf
  `main` gepusht. Commits im üblichen Stil: `feat:`, `fix:`, `docs:`, `ci:`,
  `chore:`, `release:`.
- Mehrere Pull Requests werden gesammelt veröffentlicht; ein Pull Request erhöht
  die Version nicht (siehe [docs/RELEASES.md](docs/RELEASES.md)).

## Prüfungen

Vor einem Pull Request die lokalen Prüfungen laufen lassen; GitHub führt einen
Teil davon als zweite Bestätigung erneut aus.

```bash
python scripts/local_check.py            # alles, was GitHub prüft, plus die Laufzeit-Tests
python scripts/local_check.py --all      # dazu die zusätzlichen Prüfungen
python scripts/local_check.py --list     # die Schritte, ohne sie auszuführen
```

Sie brauchen Docker, Git und Python 3.10 oder neuer; Details in `CLAUDE.md`.

## Regeln für Code

- PHP-7.4-Syntax ist das Minimum; es gilt Dolibarrs Code-Standard (Tabulatoren,
  englische Kommentare ohne Umlaute, Doc-Blöcke). Namen und Kommentare im Code
  bleiben Englisch.
- Nie Dateien von Dolibarr ändern; Hooks, Trigger und die eigenen Klassen des
  Moduls verwenden.
- Eingaben mit `GETPOST()` lesen, Ausgaben maskieren, auf jeder Seite und jeder
  Schnittstelle Rechte prüfen, in jedes Formular das CSRF-Token.
- Logik, die ohne Dolibarr läuft, gehört in Klassen aus reinem PHP mit Tests in
  `tests/run.php`.
- Texte stehen in `langs/de_DE/vereine.lang`; danach
  `python scripts/sync_langs.py`, damit `langs/en_US/vereine.lang` die genaue
  Kopie bleibt.
- Ein gesetzlicher Betrag oder eine Frist nennt seine Quelle in
  `docs/LEGAL-SOURCES.md`.
- Sichtbare Änderungen bekommen einen Eintrag in `CHANGELOG.md` unter
  `Unreleased`, auf Deutsch.

## Lizenz

Mit einem Beitrag stimmst du zu, dass er unter der GNU General Public License
v3.0 oder später veröffentlicht wird.
