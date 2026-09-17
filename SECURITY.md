# Sicherheit

Vereine führen in Dolibarr personenbezogene Daten ihrer Mitglieder, Spender und
Freiwilligen – Namen, Anschriften, Geburtsdaten und Bankdaten. Das Modul
behandelt ein Sicherheitsproblem als dringend.

## Ein Problem melden

Kein öffentliches Issue anlegen. Das private
[Formular für Security Advisories](https://github.com/Tabsi1998/dolibarr-vereine/security/advisories/new)
nutzen und das Problem samt Weg zum Nachstellen beschreiben. Nie echte
Mitglieds-, Spender- oder Bankdaten, Zugangsdaten, API-Schlüssel oder
Datenbankauszüge mitschicken.

Eine Antwort kommt binnen sieben Tagen. Ein bestätigtes Problem wird in einer
Fehlerbehebungs-Version behoben und im Changelog genannt, sobald die Behebung
veröffentlicht ist.

## Unterstützte Versionen

Sicherheitskorrekturen gehen in die neueste Version. In der Beta-Phase (0.x) ist
das die neueste Beta; ältere Betas bekommen keine Korrekturen.

## Schutz im Code

- Jede Seite lädt Dolibarr, verweigert bei abgeschaltetem Modul und prüft ein
  Recht oder Administratorstatus, bevor sie etwas tut.
- Formulare tragen Dolibarrs CSRF-Token; Eingaben werden nur über `GETPOST()`
  gelesen.
- Ausgaben werden mit `dol_escape_htmltag()` maskiert.
- Jede API-Schnittstelle prüft Modulzustand und Recht des Benutzers.
- Das Modul führt keine Shell-Befehle aus, wertet keinen Code aus und ruft keine
  anderen Server auf.
- Es ändert nie Dateien von Dolibarr und schreibt nur unter den Dokumentenordner.

`scripts/check-module.sh` setzt diese Regeln bei jeder Änderung durch.
