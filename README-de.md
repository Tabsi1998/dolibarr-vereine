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
- **Mitglieds-Zusammenfassung für die Website** (0.3): Mitgliedschaft,
  Beitragsstand mit Zahlungslink und offene Rechnungen eines Mitglieds, gefunden
  über Mitgliedsnummer oder E-Mail – für einen Website-Benutzer, der sonst nichts
  aus Dolibarr lesen kann. Alle Rechnungen eines Mitglieds mit PDF (0.3.1).
  Abgleich, der nur die seit dem letzten Mal geänderten Mitglieder liest (0.3.2).
  Benachrichtigung per Webhook, die nur die Mitglieds-ID verrät (0.3.3). Beschrieben in
  [docs/openapi.json](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/docs/openapi.json).
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
- **Steuerprofile** (0.2.3, Österreich): Sphäre, Umsatzsteuer-Behandlung, Satz
  und Rechnungshinweis mit Rechtsgrundlage, vorgeschlagene Profile und Prüfung
  von Kombinationen, die das Gesetz ausschließt. Produkte und Rechnungszeilen
  tragen ein Steuerprofil (0.2.5): Der USt-Satz des Produkts folgt ihm, neue
  Rechnungszeilen übernehmen es, und eine Rechnung zeigt Zeilen, deren Satz nicht
  passt. Das Rechnungs-PDF druckt die Hinweise je Zeile und die ZVR-Zahl (0.2.6).
- **Grenzen als Ampel** (0.2.7): Kleinunternehmer-Grenze und § 45a BAO je
  Kalenderjahr aus den Rechnungen, auf der Übersicht, als Startseiten-Widget und
  in der API.
- **Registrierkassen-Check** je Bereich und der fehlende **13-%-Satz** für
  Österreich auf Knopfdruck (0.2.8).
- **Beitragsmodell je Mitgliedsart** (0.3.4): Beginn des Beitragsjahres,
  anteiliger erster Beitrag (seit 0.3.6 monatsweise, vierteljährlich oder
  halbjährlich), Aufnahmegebühr und Beitragsleistung mit
  Steuerprofil, direkt an Dolibarrs Mitgliedsart; der Reiter *Beiträge* zeigt,
  was ein Eintritt heute kostet.
- **Beitragslauf** (0.3.5) unter *Mitglieder > Verein*: Vorschau der fälligen
  Beiträge, dann Beitragsperiode und verknüpfte Rechnung je Mitglied, einmal je
  Periode.
- **Ermäßigungen** (0.3.7): nach Alter, mit Nachweis (zum Beispiel Studierende)
  und Befreiungen (zum Beispiel Ehrenmitglieder), angewandt im Beitragslauf.
- **Familien** (0.3.8): Mitglieder mit demselben Zahler, etwa einem Elternteil,
  bekommen eine gemeinsame Rechnung an den Zahler, mit Rabatt je weiterem
  Mitglied oder Höchstbetrag je Beitragsjahr.
- **Austritt** (0.3.9): Kündigung mit der Frist laut Statuten, Ausschluss, Tod
  oder Streichung; am letzten Tag wird das Mitglied auf ausgetreten gesetzt,
  der Beitragslauf endet dort.
- **SEPA-Lastschrift** (0.3.10): Der Beitragslauf prüft Mandate, meldet
  abgelaufene und fordert die Lastschrift mit Vorankündigung über Dolibarrs
  eigenes Modul an.
- **Einwilligungen und Beitrittsanträge** (0.3.11): Einwilligungstexte mit
  Version, Einwilligung und Widerruf am Mitglied, Beitrittsanträge von der
  Website über die API als Mitglied im Entwurf.
- **Vorstand und Funktionen** (0.4.0): Funktionskatalog mit Vorschlägen für
  Österreich, Funktionsperioden am Mitglied und eine Übersicht, was nicht zum
  Vereinsgesetz passt; neue Vertreter:innen bekommen eine Meldefrist, einen
  Agenda-Termin und das Meldungsschreiben an die Vereinsbehörde als PDF (0.4.1);
  der Vorstand für die Website über die API, Namen nur mit Einwilligung oder wo
  die Offenlegung sie verlangt (0.4.2); Benutzergruppen über Funktionen, geändert
  erst nach Bestätigung durch einen Admin (0.4.3).
- **E-Mail-Kampagnen** (0.4.4): Empfänger nach Mitgliedsstatus, Mitgliedsart,
  Funktion und Einwilligung, Minderjährige über die Erziehungsberechtigten, in
  Dolibarrs eigenen Kampagnen.
- **Regeln der Statuten** (0.5.0): Generalversammlung, Vorstand,
  Funktionsperioden und Mindestalter an einer Stelle, vorbelegt aus dem
  Statutenmuster und gegen das Vereinsgesetz geprüft; „Neuwahl fällig“, wenn eine
  Funktionsperiode abgelaufen ist, Beitrittsanträge unter dem Mindestalter werden
  abgelehnt.
- **Schreiben an die Vereinsbehörde** (0.5.1): Statutenänderung, neue
  Zustellanschrift, Registerauszug, Auflösung, Errichtung und Fristverlängerung in
  einem Layout, mit zuständiger Behörde, Frist in der Agenda und Vermerk
  „eingebracht am“.
- **Statuten als Text** (0.5.2): die ganzen Statuten nach dem Muster des
  Innenministeriums oder, für steuerbegünstigte Vereine, des Finanzministeriums,
  ausgefüllt aus dem Modul; geprüft, als Vorschau und als Fassungen mit PDF,
  bestehende Statuten hochladen. Eine Änderung zeigt alt neben neu, als PDF für
  die Einladung, und erstellt die Anzeige an die Behörde (0.5.3).
- **Sitzungen** (0.5.4): Vorstandssitzungen und Generalversammlungen mit
  Tagesordnung; genau der Vorstand oder alle Mitglieder per E-Mail oder Brief
  eingeladen, mit den Fristen der Statuten und Nachweis je Person. Anwesenheit
  mit Vollmachten, wie es die Statuten erlauben, und Beschlussfähigkeit zu jeder
  Uhrzeit (0.5.5). Abstimmungen und Wahlen mit den Mehrheiten der Statuten; eine
  Wahl setzt die Funktionsperiode samt Meldung, eine Statutenänderung speichert
  Fassung und Anzeige (0.5.6). Tagesordnungen aus Vorlagen mit Pflichtpunkten
  und Texte je Punkt fürs Protokoll, mit den echten Zahlen statt Platzhaltern
  (0.5.7).
- **API-Dokumentation in Dolibarr** (0.5.6): Einrichtungsreiter *API* mit jeder
  Schnittstelle, ihren Rechten und einem Beispiel, den Benutzern mit
  API-Schlüssel und was sie aufrufen können.
- Dolibarrs eigene Knöpfe auf der Mitgliedskarte (*Geschäftspartner anlegen*,
  *Verknüpfung mit Geschäftspartner*) funktionieren ebenso: Das Modul bringt den
  Geschäftspartner sofort in Ordnung.

## Steuerprofile einfach erklärt

Ein Steuerprofil sagt, wie eine Art von Einnahme steuerlich behandelt wird. Drei
Fragen führen hin:

1. **Was für eine Einnahme ist es?** Das bestimmt den Bereich des Vereins.
2. **Fällt Umsatzsteuer an?** Das bestimmt Behandlung und Satz.
3. **Was steht auf der Rechnung?** Ohne Umsatzsteuer erklärt der Hinweis warum.

| Bereich | Was dazugehört (Beispiele des Finanzministeriums) | Umsatzsteuer meist |
| --- | --- | --- |
| Ideeller Bereich | echte Mitgliedsbeiträge, Spenden, Förderungen, kostenlose Vorträge | keine, es wird nichts verkauft |
| Vermögensverwaltung | Zinsen, Vermietung von Räumen | Zinsen keine; Vermietung nach den normalen Regeln |
| Unentbehrlicher Hilfsbetrieb | Einnahmen direkt aus dem Vereinszweck: Theatervorstellung des Theatervereins, Sportunterricht beim Sportverein | keine (Liebhaberei); Sportvereine befreit |
| Entbehrlicher Hilfsbetrieb | passt zum Verein, ist aber nicht nötig: Faschingsball, Sommerfest, Flohmarkt | keine (Liebhaberei) |
| Kleines Vereinsfest | von Mitgliedern getragen, Helfer gratis, höchstens 72 Stunden im Jahr | keine (Liebhaberei), keine Registrierkasse |
| Begünstigungsschädlicher Betrieb | läuft wie ein Geschäft: selbst betriebene Kantine, großes Vereinsfest | ja, bis 55.000 € brutto als Kleinunternehmer befreit |

Das Modul rechnet und warnt, es berät nicht: Ob eine Einnahme wirklich in einen
Bereich fällt, hängt vom Einzelfall ab. Quellen und Stand stehen in
[docs/LEGAL-SOURCES.md](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/docs/LEGAL-SOURCES.md).

## Wo das Modul in Dolibarr sitzt

| Stelle in Dolibarr | Was das Modul ergänzt oder nutzt |
| --- | --- |
| *Start > Einstellungen > Unternehmen/Organisation* | Name, Anschrift, E-Mail, Telefon, Website und erster Monat des Rechnungsjahres – das Modul liest sie, es führt keine eigene Kopie |
| *Start > Einstellungen > Module > Vereine* | Einrichtung: Länderprofil und Registerdaten; Steuerprofile; Über-Seite mit Version und Lizenz |
| *Mitglieder*, *Geschäftspartner*, *Kategorien* (Dolibarr-eigene Module) | Pflicht und werden mit Vereine aktiviert; das Mitglieder-Menü bekommt *Verein*, *Mitglieder und Partner* und für Administratoren *Partner-Einstellungen* |
| *Mitglieder > Verein* | Übersicht mit Vereinsdaten, Prüfungen und den Grenzen des Jahres |
| Startseite | Widget *Vereine: Grenzen des Jahres* (für Benutzer, die Rechnungen lesen dürfen) |
| *Mitglieder > Verein > Mitglieder und Partner* | Abgleich von Mitgliedern und ihren Geschäftspartnern |
| Karte eines Geschäftspartners | Reiter *Mitgliedschaft*; Kategorien *Mitglied* und *Ehemaliges Mitglied* |
| Mitgliedskarte | Reiter *Verein*; *Geschäftspartner anlegen* und *Verknüpfung mit Geschäftspartner* zieht das Modul nach |
| Produkt- und Leistungskarte, Zeilen von Rechnungen und Lieferantenrechnungen | Zusatzfeld *Steuerprofil*; Rechnungen warnen bei Zeilen, deren Umsatzsteuer nicht zum Profil passt |
| Rechnungs-PDF (Dolibarr-Vorlagen, unverändert) | Hinweise der Steuerprofile je Zeile und ZVR-Zahl im Hinweisbereich |
| Kontakte des Partners eines Mitglieds | Kategorie *Erziehungsberechtigt* für Minderjährige |
| *Start > Einstellungen > Module > Vereine > Mitglieder und Partner*, auch *Mitglieder > Verein > Partner-Einstellungen* | Automatisch anlegen, Kategorien, Kundentypen (Administratoren) |
| *Benutzer & Gruppen > Berechtigungen* | *Vereinsübersicht und Vereinsdaten lesen* (Übersicht, API); *Mitglieder und Geschäftspartner verknüpfen und abgleichen* (Änderungen im Abgleich); *Mitglieder-Zusammenfassung für die Website über die API lesen* (Mitglieder-Aufrufe der API) |
| Modul *API REST* | Nötig für `/api/index.php/vereine/...`; die Übersicht warnt, solange es aus ist |

So hängt es zusammen: Die Einrichtung speichert die Vereinsdaten als
Dolibarr-Konstanten; Übersicht und API lesen dieselben Daten über eine Klasse
(`VereineOrganization`). Eine Website sieht also genau das, was die Übersicht
zeigt. Mitgliedsereignisse (aktivieren, austreten, ausschließen, ändern,
löschen) erreichen das Modul über einen Dolibarr-Trigger, der den verknüpften
Geschäftspartner nachzieht; das Mahnwesen-Modul erkennt Mitglieder dann an
Kategorie und Kundentyp.

## Versionen und Updates

Änderungen erscheinen als Releases mit Patchnotes auf der
[Release-Seite](https://github.com/Tabsi1998/dolibarr-vereine/releases),
meist mehrere gemergte Änderungen auf einmal. Versionen unter 1.0.0 sind Betas (`v0.1.0-beta`,
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
Website aus aufrufen – nie aus dem Browser, dort wäre der Schlüssel sichtbar.

1. *Start > Benutzer & Gruppen > Neuer Benutzer*: Login zum Beispiel `website`,
   kein Administrator. Der Benutzer meldet sich nie an, ein starkes Passwort
   genügt.
2. Reiter *Berechtigungen*, Modul *Vereine (AT/DE)*: genau **Vereinsübersicht und
   Vereinsdaten lesen** und **Mitglieder-Zusammenfassung für die Website über die
   API lesen** anhaken. Sonst nichts – vor allem nicht Mitglieder, Rechnungen
   oder Geschäftspartner lesen.
3. *Ändern* auf der Benutzerkarte: **API-Schlüssel** erzeugen, speichern und nur
   auf dem Server der Website ablegen, etwa als `DOLIBARR_API_KEY` in der `.env`.
4. Vom Server der Website aus testen:

```bash
curl -H "DOLAPIKEY: <schlüssel>" "https://erp.example.org/api/index.php/vereine/members/lookup?ref=1"
```

Mit diesem Schlüssel lässt sich die Zusammenfassung jedes Mitglieds lesen und
ein Mitglied über seine E-Mail finden. Er gehört behandelt wie ein Passwort: nie
in den Browser, nie in ein Repository. Dolibarrs eigene Aufrufe wie `/members`
oder `/invoices` beantworten diesem Benutzer mit 403.

Rechnungs-PDFs holt die Website über
`/vereine/members/{id}/invoices/{rechnung}/pdf` und gibt sie nur an das Mitglied
weiter, dem die Rechnung gehört – durchreichen, nicht speichern. Eine fremde
Rechnung oder ein Entwurf ergibt 404.

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
