# Kompatibilität

## Unterstützte Versionen

| Dolibarr | Status | Geprüft durch |
| --- | --- | --- |
| 24.x | Unterstützt | API-Vertrag gegen Zweig 24.0, Laufzeit-Tests auf 24.0.1 |
| 23.x | Unterstützt | API-Vertrag gegen Zweig 23.0, Laufzeit-Tests auf 23.0.4 |
| 22.x | Unterstützt | API-Vertrag gegen Zweig 22.0, Laufzeit-Tests auf 22.0.5 |
| 21.x und älter | Nicht unterstützt | Der Deskriptor verweigert die Aktivierung |

Dolibarr veröffentlicht Sicherheitskorrekturen nur für die letzten zwei
Hauptversionen (derzeit 23 und 24). Das Modul läuft weiter auf 22, Installationen
sollten aber aktualisieren.

PHP 7.4 ist das Syntax-Minimum; jede Datei wird auf 7.4, 8.1, 8.2, 8.3 und 8.4
geprüft, und die Unit-Tests laufen auf jeder dieser Versionen.

## Versionen im Gleichschritt halten

Der unterstützte Bereich steht an mehreren Stellen, die die Release-Prüfung
vergleicht:

- `need_dolibarr_version` und `phpmin` in `core/modules/modVereine.class.php`
- `DOLIBARR_VERSIONS`, `PHP_VERSIONS` und `RUNTIME_IMAGES` in `scripts/local_check.py`
- `SUPPORTED` in `scripts/check_dolibarr_api.py`
- die Matrizen in `.github/workflows/ci.yml`
- die Tabellen in `README.md` und in dieser Datei

Das Minimum anzuheben ändert alle in einem Pull Request.

## Was das Modul von Dolibarr voraussetzt

- Der API-Einstieg leitet `/vereine/...` an eine Klasse namens `Vereine` in
  `class/api_vereine.class.php` weiter. Der alternative Name `VereineApi` wird
  erst ab Dolibarr 24 weitergeleitet (Dolibarr #37282).
- *Externes Modul bereitstellen* nimmt nur Dateinamen an, die auf `-x.y.z.zip`
  enden; eine Beta wird also als `module_vereine-0.5.8.zip` verpackt.
- Die Modulbeschreibung liest Dolibarr aus `README-<sprache>.md`, dann
  `README.md`; das Modul hat nur `README.md`, auf Deutsch, für jede Sprache.
- `SOCIETE_FISCAL_MONTH_START` aus den Unternehmensdaten ist der erste Monat des
  Rechnungsjahres.

`scripts/check_dolibarr_api.py` prüft jeden dieser Punkte im Quellcode von
Dolibarr.
