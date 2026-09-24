# DoliStore: Einreichung von Vereine 1.0

Für die Veröffentlichung auf [DoliStore](https://www.dolistore.com) (kostenlos). Diese Datei ist nicht im
ZIP des Moduls.

## Vorher erledigt

- Modulnummer 492100–492109 im Dolibarr-Wiki reserviert (#2)
- Release 1.0.0 veröffentlicht, ZIP `module_vereine-1.0.0.zip` aus dem GitHub-Release
- Bilder aus [docs/bilder](bilder) (1366 × 860, Testverein mit erfundenen Namen)

## Klickschritte

1. Auf dolistore.com ein Konto anlegen bzw. anmelden (Firma: IT-Tabelander).
2. *Mein Konto > Meine Module > Ein neues Modul einreichen*.
3. Die Felder unten eintragen, das ZIP hochladen, die Bilder in dieser Reihenfolge: `uebersicht`,
   `erste-schritte`, `abstimmungen`, `webportal`, `vereinsakte`, `inventar`, `steuerprofile`, `loeschen`.
4. Preis 0, Lizenz GPL-3.0-or-later, absenden. DoliStore prüft das Modul vor der Freischaltung.

## Felder

| Feld | Inhalt |
| --- | --- |
| Name | Vereine – Vereinsverwaltung für Österreich |
| Kategorie | Mitglieder / Vereine (Members / Foundations) |
| Dolibarr-Versionen | 22, 23, 24 |
| PHP | 7.4 bis 8.4 |
| Sprache der Oberfläche | Deutsch |
| Herausgeber | IT-Tabelander, https://it.tabelander.co.at |
| Quelltext und Fehlermeldungen | https://github.com/Tabsi1998/dolibarr-vereine |
| Preis | 0 (kostenlos) |

### Kurzbeschreibung (Deutsch)

Vereinsverwaltung für österreichische Vereine in Dolibarr: Beiträge, Sitzungen und Beschlüsse, Funktionen
und Meldungen an die Vereinsbehörde, Einnahmen-Ausgaben-Rechnung, Spendenmeldung, Freiwilligenpauschale,
Datenschutz – und eine API für Website und App.

### Beschreibung (Deutsch)

Vereine ergänzt Dolibarr um alles, was ein österreichischer Verein über das Jahr braucht, und nutzt dabei
Dolibarrs eigene Mitglieder, Rechnungen, Bank und Termine.

- Beitragslauf nach Mitgliedsart, Familien und Ermäßigungen, SEPA-Lastschrift
- Sitzungen und Generalversammlung: Einladung mit Frist, Anwesenheit, Vollmachten, Abstimmungen – auch
  über App und Webportal, jede Stimme genau einmal – Protokoll, Beschlussbuch, Unterschriften mit ID Austria
- Funktionen mit Funktionsperiode, Schreiben an die Vereinsbehörde
- Einnahmen-Ausgaben-Rechnung, Rechnungsprüfung, Steuerprofile (Sphären, Umsatzsteuer), Spendenmeldung
  an das Finanzamt, Freiwilligenpauschale
- Vereinsakte mit PDF/A, Kennung und öffentlicher Echtheitsprüfung; Veröffentlichung für Mitglieder
- Datenschutz: Auskunft, Löschen nach dem Austritt mit Fristen je Art der Daten
- REST-API mit OpenAPI-Beschreibung, Änderungsfeed und Webhooks für Website und App; Seite „Mein Verein“
  im Webportal von Dolibarr (ab 23)

Nur für Österreich, Oberfläche auf Deutsch. Kein Ersatz für Rechts- oder Steuerberatung; die Quellen stehen
im Modul.

### Short description (English)

Association management for Austrian associations (Vereine) in Dolibarr: fees, meetings and resolutions,
officers and filings to the authority, cash accounting, donation reporting, privacy, and an API for
websites and apps. User interface in German.

### Description (English)

Vereine adds what an Austrian association needs through its year, built on Dolibarr's own members,
invoices, bank and agenda: fee runs with SEPA, meetings and general assemblies with invitations, attendance,
proxies and votes (also through apps and Dolibarr's web portal, one vote per voting right), minutes and a
register of resolutions with signatures (ID Austria), officers and letters to the association authority,
cash accounting and audit, tax profiles, donation reporting to the tax office, volunteer allowances, an
archive of finished documents as PDF/A with public verification, privacy tools (access requests, erasure
after membership ends), and a REST API with OpenAPI, change feed and webhooks.

Austria only; the user interface is German. Not legal or tax advice.
