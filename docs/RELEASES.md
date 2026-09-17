# Releases

## Versionen

| Art | Tag und Modulversion | GitHub | Paket |
| --- | --- | --- | --- |
| Beta | `v0.5.8-beta` | Pre-Release | `module_vereine-0.5.8.zip` |
| Erste Beta eines Meilensteins | `v0.6.0-beta` | Pre-Release | `module_vereine-0.6.0.zip` |
| Stabile Version | `v1.0.0` | Release, als neueste markiert | `module_vereine-1.0.0.zip` |
| Fehlerbehebung | `v1.0.1` | Release, als neueste markiert | `module_vereine-1.0.1.zip` |

Versionen unter 1.0.0 sind immer Betas. Der Paketname trägt kein `-beta`:
Dolibarrs *Externes Modul bereitstellen* nimmt nur Namen an, die auf
`-x.y.z.zip` enden. Die Beta-Kennung steht im Tag, im Titel des Releases und in
der Modulversion, die Dolibarr in der Modulliste zeigt.

Jede zweite Versionszahl hat einen Meilenstein (0.5, 0.6, …). Innerhalb eines
Meilensteins zählt die dritte Zahl hoch (0.5.8, 0.5.9, 0.5.10 …), der erste
Release eines neuen Meilensteins setzt sie auf 0 (0.6.0).

## Arbeitsweise: mehrere Pull Requests je Release

1. **Ein Issue ergibt einen Pull Request**; kleine, zusammengehörige Issues
   dürfen gebündelt werden. Lokale Prüfung zuerst, GitHub CI danach.
2. **Ein Pull Request erhöht die Version nicht.** Er beschreibt seine Änderungen
   in `CHANGELOG.md` unter `## [Unreleased]`.
3. Der Eigentümer merged, wann es passt, auch mehrere Pull Requests hintereinander.
4. **Veröffentlicht wird, wenn es sich lohnt:** nach einigen Pull Requests, am
   Ende eines Meilensteins oder sofort bei einem wichtigen Fehler. Dafür gibt es
   einen kleinen **Release-Pull-Request**:
   - `$this->version` in `core/modules/modVereine.class.php` erhöhen
   - die Einträge unter `Unreleased` in einen Abschnitt
     `## [x.y.z(-beta)] - JJJJ-MM-TT` verschieben und unten den Link ergänzen;
     dieser Abschnitt wird der Text des GitHub-Releases

Die lokale Prüfung (Schritt *release*) achtet darauf:

- Ändert ein Pull Request das Paket, während die Version noch die
  veröffentlichte ist, muss `CHANGELOG.md` unter `Unreleased` etwas stehen haben.
- Ist die Version neu, darf unter `Unreleased` nichts mehr stehen, und sie muss
  höher sein als jede veröffentlichte.

Pull Requests, die nur ändern, was nicht ins Paket kommt – `scripts/`,
`tests/`, `.github/`, `CLAUDE.md`, `CONTRIBUTING.md` – brauchen keinen Eintrag.

## Veröffentlichen

Nach dem Merge des Release-Pull-Requests, auf einem aktuellen `main`:

```bash
python scripts/local_check.py --all       # die vollständige Prüfung genau dieses Commits
python scripts/release.py --check         # alles außer Tag und Veröffentlichung
python scripts/release.py                 # Tag, GitHub-Release, Paket hochladen
```

`release.py` verweigert, solange nicht alles davon gilt:

- `main` ist ausgecheckt, sauber und gleich `origin/main`;
- die Modulversion ist `x.y.z` oder `x.y.z-beta`, unter 1.0.0 eine Beta;
- `CHANGELOG.md` hat einen nicht leeren Abschnitt für sie, datiert heute oder
  früher, und unter `Unreleased` steht nichts mehr;
- weder Tag noch Release dieses Namens gibt es schon, und die Version ist höher
  als jede veröffentlichte;
- `.local-testing/local-check.json` meldet eine vollständige, grüne lokale
  Prüfung für genau diesen Commit, Laufzeit-Tests eingeschlossen.

Danach baut es das Paket aus den eingecheckten Dateien (`git archive`), prüft
es, erstellt den Tag `v<version>`, pusht ihn, legt das GitHub-Release an – ein
Pre-Release für Betas, sonst das neueste Release – mit dem Changelog-Abschnitt
als Text, lädt ZIP und `.sha256` hoch, lädt beides wieder herunter und
vergleicht die Prüfsumme.

## Zweite Bestätigung durch GitHub

`.github/workflows/release-verify.yml` läuft, sobald ein Release veröffentlicht
ist. Es checkt den Tag aus, baut das Paket erneut und schlägt fehl, wenn die
SHA-256 vom hochgeladenen Paket abweicht oder die Pre-Release-Markierung nicht
zur Version passt. Das Paket wird Byte für Byte gleich gebaut; eine Abweichung
heißt, das veröffentlichte ZIP stammt nicht von diesem Tag.
