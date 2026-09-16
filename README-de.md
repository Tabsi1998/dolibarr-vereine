# Vereine für Dolibarr

Ein Modul für Dolibarr ERP & CRM, das Vereine nach österreichischem und
deutschem Recht abbildet. Es baut auf den Dolibarr-Funktionen für Mitglieder,
Spenden, Rechnungen, Bank und SEPA auf, ergänzt, was Vereine in Österreich und
Deutschland darüber hinaus brauchen, und bringt eine REST-API für die
Vereinswebsite mit.

[English description](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/README.md)

> **Beta.** Version 0.1 legt das Fundament: Länderprofil, Registerdaten, eine
> Übersicht mit Prüfungen und die ersten API-Aufrufe. Steuerprofile,
> Beitragsläufe, Spendenmeldung, Ehrenamtspauschalen, Funktionäre,
> Rechnungslegung und Versammlungen folgen Meilenstein für Meilenstein – siehe
> [Fahrplan](https://github.com/Tabsi1998/dolibarr-vereine/milestones).

## Was Version 0.1 kann

- **Länderprofil** Österreich (vollständig) oder Deutschland (Vorschau bis 1.1).
- **Vereinsdaten**, die Dolibarr nicht kennt: ZVR-Zahl (Österreich) bzw.
  Registernummer und Registergericht (Deutschland), Vereinsbehörde,
  Gründungsdatum, Gemeinnützigkeit und Vereinszweck. Name, Anschrift und Kontakt
  bleiben in den Unternehmensdaten von Dolibarr.
- **Übersicht** unter *Mitglieder > Verein*: Vereinsdaten und Prüfungen, was
  noch fehlt – zum Beispiel die ZVR-Zahl, die auf Briefen, Rechnungen und der
  Website stehen muss (§ 18 VerG).
- **REST-API** für Websites: `GET /api/index.php/vereine/organization` und
  `GET /api/index.php/vereine/status`. Details in [docs/API.md](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/docs/API.md).

## Wo das Modul in Dolibarr sitzt

| Stelle in Dolibarr | Was das Modul ergänzt oder nutzt |
| --- | --- |
| *Start > Einstellungen > Unternehmen/Organisation* | Name, Anschrift, E-Mail, Telefon, Website und erster Monat des Rechnungsjahres – das Modul liest sie, es führt keine eigene Kopie |
| *Start > Einstellungen > Module > Vereine* | Einrichtung: Länderprofil und Registerdaten; Über-Seite mit Version und Lizenz |
| *Mitglieder* (Dolibarr-eigenes Modul) | Pflicht und wird mit Vereine aktiviert; das linke Menü bekommt den Eintrag *Verein* |
| *Mitglieder > Verein* | Übersicht mit Vereinsdaten und Prüfungen |
| *Benutzer & Gruppen > Berechtigungen* | *Vereine: Vereinsübersicht und Vereinsdaten lesen* – für die Übersicht und die API |
| Modul *API REST* | Nötig für `/api/index.php/vereine/...`; die Übersicht warnt, solange es aus ist |

So hängt es zusammen: Die Einrichtung speichert die Vereinsdaten als
Dolibarr-Konstanten; Übersicht und API lesen dieselben Daten über eine Klasse
(`VereineOrganization`). Eine Website sieht also genau das, was die Übersicht
zeigt.

## Versionen und Updates

Jede gemergte Änderung wird als Release mit Patchnotes auf der
[Release-Seite](https://github.com/Tabsi1998/dolibarr-vereine/releases)
veröffentlicht. Versionen unter 1.0.0 sind Betas (`v0.1.0-beta`,
`v0.1.1-beta`, …) und als Pre-Release markiert; 1.0.0 ist das erste stabile
Release. Die Datei zum Installieren heißt immer `module_vereine-x.y.z.zip` –
ohne `-beta`, weil Dolibarr nur diesen Namen annimmt.

## Voraussetzungen

| | Mindestens | Getestet |
| --- | --- | --- |
| Dolibarr | 22.0 | 22.0.5, 23.0.4, 24.0.1 |
| PHP | 7.4 | 7.4, 8.1, 8.2, 8.3, 8.4 |
| Dolibarr-Module | Mitglieder | REST-API für die Website-Aufrufe |

## Installation

1. `module_vereine-x.y.z.zip` bei den
   [Releases](https://github.com/Tabsi1998/dolibarr-vereine/releases)
   herunterladen.
2. In Dolibarr *Start > Einstellungen > Module > Externes Modul bereitstellen*
   öffnen und das ZIP hochladen. Nicht umbenennen: Dolibarr nimmt nur den
   Originalnamen an.
3. **Vereine (AT/DE)** in der Modulliste aktivieren. Das Mitgliedermodul wird
   mit aktiviert.
4. Die Einrichtung des Moduls öffnen, das Länderprofil wählen und die
   Registerdaten eintragen.
5. Benutzern das Recht *Vereinsübersicht und Vereinsdaten lesen* geben.

Ein Update läuft genauso: das neuere ZIP bereitstellen und das Modul neu
aktivieren. Die Vereinsdaten bleiben erhalten.

## Die API von einer Website aus nutzen

Für die Website einen eigenen Dolibarr-Benutzer mit nur den nötigen Rechten
anlegen, für ihn einen API-Schlüssel erzeugen und die API vom Server der
Website aus aufrufen – nie aus dem Browser, dort wäre der Schlüssel sichtbar:

```bash
curl -H "DOLAPIKEY: <schlüssel>" https://erp.example.org/api/index.php/vereine/organization
```

## Keine Steuer- oder Rechtsberatung

Das Modul rechnet und warnt. Wie eine Tätigkeit steuerlich eingeordnet wird,
entscheidet der Verein, am besten mit seiner Steuerberatung.

## Hilfe und Mitarbeit

Probleme und Ideen bitte im
[Issue-Tracker](https://github.com/Tabsi1998/dolibarr-vereine/issues) melden.
Sicherheitsprobleme über eine private
[Security Advisory](https://github.com/Tabsi1998/dolibarr-vereine/security/advisories/new),
siehe [SECURITY.md](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/SECURITY.md). Beiträge sind willkommen, siehe
[CONTRIBUTING.md](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/CONTRIBUTING.md).

## Lizenz

Copyright (C) 2026 IT-Tabelander. Freie Software unter der GNU General Public
License v3.0 oder später, siehe [LICENSE](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/LICENSE).
