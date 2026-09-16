# Vereine für Dolibarr

Ein Modul für Dolibarr ERP & CRM, das Vereine nach österreichischem und
deutschem Recht abbildet. Es baut auf den Dolibarr-Funktionen für Mitglieder,
Spenden, Rechnungen, Bank und SEPA auf, ergänzt, was Vereine in Österreich und
Deutschland darüber hinaus brauchen, und bringt eine REST-API für die
Vereinswebsite mit.

[English description](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/README.md)

> **Beta.** Version 0.2 verknüpft Mitglieder und Geschäftspartner – auf dem
> Fundament von 0.1 (Länderprofil, Registerdaten, Übersicht, API). Steuerprofile,
> Beitragsläufe, Spendenmeldung, Ehrenamtspauschalen, Funktionäre,
> Rechnungslegung und Versammlungen folgen Meilenstein für Meilenstein – siehe
> [Fahrplan](https://github.com/Tabsi1998/dolibarr-vereine/milestones).

## Was das Modul bisher kann

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
- **Mitglieder und Geschäftspartner** (0.2): Ein aktiviertes Mitglied kann
  automatisch seinen Geschäftspartner bekommen; gibt es schon einen mit derselben
  E-Mail (oder Name und PLZ), wird er vorgeschlagen statt doppelt angelegt.
  Partner von Mitgliedern tragen je nach Status die Kategorie *Mitglied* oder
  *Ehemaliges Mitglied*, sind als Kunde gekennzeichnet und bekommen den Kundentyp
  Privatperson, wenn sie noch keinen haben.
- **Abgleichsseite** *Mitglieder > Verein > Mitglieder und Partner*: was nicht
  verknüpft ist oder nicht zusammenpasst – mit Vorschau vor jeder Änderung.
  *Alle auswählen* je Abschnitt; ein Klick in eine Zeile öffnet ein Fenster mit
  den Schritten für diese Zeile (abgleichen, verknüpfen, Mitglied oder
  Geschäftspartner bearbeiten).
- **Reiter *Mitgliedschaft*** am Geschäftspartner und **Reiter *Verein*** an der
  Mitgliedskarte, dazu Erziehungsberechtigte von Minderjährigen als Kontakte in
  der Kategorie *Erziehungsberechtigt*.
- Dolibarrs eigene Knöpfe auf der Mitgliedskarte (*Geschäftspartner anlegen*,
  *Verknüpfung mit Geschäftspartner*) funktionieren ebenso: Das Modul bringt den
  Geschäftspartner sofort in Ordnung.

## Wo das Modul in Dolibarr sitzt

| Stelle in Dolibarr | Was das Modul ergänzt oder nutzt |
| --- | --- |
| *Start > Einstellungen > Unternehmen/Organisation* | Name, Anschrift, E-Mail, Telefon, Website und erster Monat des Rechnungsjahres – das Modul liest sie, es führt keine eigene Kopie |
| *Start > Einstellungen > Module > Vereine* | Einrichtung: Länderprofil und Registerdaten; Über-Seite mit Version und Lizenz |
| *Mitglieder*, *Geschäftspartner*, *Kategorien* (Dolibarr-eigene Module) | Pflicht und werden mit Vereine aktiviert; das Mitglieder-Menü bekommt *Verein*, *Mitglieder und Partner* und für Administratoren *Partner-Einstellungen* |
| *Mitglieder > Verein* | Übersicht mit Vereinsdaten und Prüfungen |
| *Mitglieder > Verein > Mitglieder und Partner* | Abgleich von Mitgliedern und ihren Geschäftspartnern |
| Karte eines Geschäftspartners | Reiter *Mitgliedschaft*; Kategorien *Mitglied* und *Ehemaliges Mitglied* |
| Mitgliedskarte | Reiter *Verein*; *Geschäftspartner anlegen* und *Verknüpfung mit Geschäftspartner* zieht das Modul nach |
| Kontakte des Partners eines Mitglieds | Kategorie *Erziehungsberechtigt* für Minderjährige |
| *Start > Einstellungen > Module > Vereine > Mitglieder und Partner*, auch *Mitglieder > Verein > Partner-Einstellungen* | Automatisch anlegen, Kategorien, Kundentypen (Administratoren) |
| *Benutzer & Gruppen > Berechtigungen* | *Vereinsübersicht und Vereinsdaten lesen* (Übersicht, API); *Mitglieder und Geschäftspartner verknüpfen und abgleichen* (Änderungen im Abgleich) |
| Modul *API REST* | Nötig für `/api/index.php/vereine/...`; die Übersicht warnt, solange es aus ist |

So hängt es zusammen: Die Einrichtung speichert die Vereinsdaten als
Dolibarr-Konstanten; Übersicht und API lesen dieselben Daten über eine Klasse
(`VereineOrganization`). Eine Website sieht also genau das, was die Übersicht
zeigt. Mitgliedsereignisse (aktivieren, austreten, ausschließen, ändern,
löschen) erreichen das Modul über einen Dolibarr-Trigger, der den verknüpften
Geschäftspartner nachzieht; das Mahnwesen-Modul erkennt Mitglieder dann an
Kategorie und Kundentyp.

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
| Dolibarr-Module | Mitglieder, Geschäftspartner, Kategorien | REST-API für die Website-Aufrufe |

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

Ein Update läuft genauso: das neuere ZIP bereitstellen, dann das Modul in der
Modulliste einmal deaktivieren und wieder aktivieren, damit neue Tabellen,
Rechte und Kategorien entstehen. Die Vereinsdaten bleiben erhalten.

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
