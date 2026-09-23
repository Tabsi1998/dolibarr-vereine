# Vereine für Dolibarr

Ein Modul für Dolibarr ERP & CRM für **Vereine in Österreich**. Es baut auf den
Dolibarr-Funktionen für Mitglieder, Rechnungen, Bank, SEPA, Agenda und E-Mail
auf, ergänzt, was ein Verein nach dem Vereinsgesetz 2002 darüber hinaus braucht,
und bringt eine REST-API für die Vereinswebsite mit.

> **Beta.** Das Modul wächst Meilenstein für Meilenstein – siehe
> [Fahrplan](https://github.com/Tabsi1998/dolibarr-vereine/milestones).

## Was das Modul kann

### Verein und Steuern

- **Vereinsdaten**, die Dolibarr nicht kennt: ZVR-Zahl, Vereinsbehörde,
  Gründungsdatum, Gemeinnützigkeit und Vereinszweck. Name, Anschrift und Kontakt
  bleiben in den Unternehmensdaten von Dolibarr.
- **Übersicht** unter *Mitglieder > Verein*: Vereinsdaten und was noch fehlt –
  zum Beispiel die ZVR-Zahl, die auf Briefen, Rechnungen und der Website stehen
  muss (§ 18 VerG).
- **Steuerprofile**: Sphäre, Umsatzsteuer-Behandlung, Satz und Rechnungshinweis
  mit Rechtsgrundlage an Produkten und Rechnungszeilen; Warnung, wenn der Satz
  einer Zeile nicht passt; Hinweise und ZVR-Zahl auf dem Rechnungs-PDF.
- **Grenzen als Ampel**: Kleinunternehmer-Grenze und § 45a BAO je Kalenderjahr,
  auf der Übersicht, als Startseiten-Widget und in der API;
  **Registrierkassen-Check** je Bereich und der fehlende **13-%-Satz** auf
  Knopfdruck.
- **Überzahlungen zuordnen**: Wer mehr überweist, als die Rechnung ausmacht, landet
  in einer Liste. Der Mehrbetrag wird mit Vorschau Guthaben, Rückzahlung oder –
  nur wenn er freiwillig und ohne Gegenleistung kam – Spende, genau einmal. Die
  Rechnung bleibt, wie sie ist.
- **Auskunft nach Art. 15 DSGVO**: Anfrage und Prüfung festgehalten, die Kopie der eigenen
  Daten als PDF und JSON, ohne Daten anderer.
- **Vereinsakte**: Fertige Dokumente als PDF/A mit Kennung und QR-Code, eine öffentliche
  Echtheitsprüfung ohne Titel und Inhalt, und die Akte eines Zeitraums als ZIP mit
  Inhaltsverzeichnis und Prüfsummen.
- **Spendenmeldung ans Finanzamt**: Summe je Person und Jahr aus dem Spendenmodul,
  Geburtsdatum verschlüsselt, vbPK über die Abgleichliste des Stammzahlenregisters,
  XML nach dem Schema des Finanzministeriums mit Erst-, Änderungs- und
  Stornomeldung, Protokoll aus der DataBox zurück. Hochladen bleibt ein Klick in
  FinanzOnline.

### Mitglieder und Beiträge

- **Mitglieder und Geschäftspartner** verknüpft: automatisch angelegt oder
  vorgeschlagen statt doppelt, Kategorien *Mitglied* und *Ehemaliges Mitglied*,
  Kundentyp Privatperson; eine **Abgleichsseite** mit Vorschau vor jeder
  Änderung. Erziehungsberechtigte von Minderjährigen als Kontakte.
- **Beitragsmodell je Mitgliedsart**: Beginn des Beitragsjahres, anteiliger
  erster Beitrag, Aufnahmegebühr, Beitragsleistung mit Steuerprofil.
- **Beitragslauf** mit Vorschau: Beitragsperiode und verknüpfte Rechnung je
  Mitglied, einmal je Periode; **Ermäßigungen** nach Alter, mit Nachweis oder als
  Befreiung; **Familien** mit gemeinsamem Zahler, Rabatt oder Höchstbetrag;
  **SEPA-Lastschrift** mit Mandatsprüfung und Vorankündigung.
- **Austritt** mit der Kündigungsfrist der Statuten, Ausschluss, Tod oder
  Streichung, wirksam am letzten Tag.
- **Einwilligungen** mit Textversion, Einwilligung und Widerruf am Mitglied;
  **Beitrittsanträge** von der Website als Mitglied im Entwurf.
- **E-Mail-Kampagnen** nach Status, Mitgliedsart, Funktion und Einwilligung,
  Minderjährige über die Erziehungsberechtigten.

### Vorstand, Statuten und Sitzungen

- **Funktionen**: Katalog mit Vorschlägen, Funktionsperioden, Prüfung gegen das
  Vereinsgesetz; **Meldung an die Vereinsbehörde** mit Frist, Agenda-Termin und
  Schreiben als PDF; Benutzergruppen über Funktionen nach Bestätigung.
- **Statuten**: Regeln an einer Stelle (Generalversammlung, Vorstand,
  Funktionsperioden, Mindestalter); die ganzen **Statuten als Text** nach dem
  Muster des Innenministeriums oder des Finanzministeriums, als Fassungen mit
  PDF; eine **Statutenänderung** zeigt alt neben neu und erstellt die Anzeige an
  die Behörde.
- **Schreiben an die Vereinsbehörde** in einem Layout: Statutenänderung,
  Zustellanschrift, Registerauszug, Auflösung, Errichtung, Fristverlängerung.
- **Sitzungen**: Vorstandssitzungen und Generalversammlungen, genau der Vorstand
  oder alle Mitglieder per E-Mail oder Brief eingeladen, mit Fristen und
  Nachweis; Anwesenheit mit Vollmachten und Beschlussfähigkeit zu jeder Uhrzeit;
  **Abstimmungen und Wahlen** mit den Mehrheiten der Statuten; **Vorlagen** mit
  Pflichtpunkten und Texte je Punkt fürs Protokoll mit den echten Zahlen.
- **Unterschreiben** in Dolibarr, auf Papier oder **mit ID Austria** –
  qualifiziert, rechtlich wie eigenhändig – über einen eigenen Signaturdienst
  (PDF-AS). Einrichtung in [docs/ID-AUSTRIA.md](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/docs/ID-AUSTRIA.md).

- **Freiwilligenpauschale und PRAE**: Einsätze je Person mit Tag, Tätigkeit,
  Art und Betrag. Was über einer Tages-, Monats- oder Jahresgrenze liegt, markiert
  das Modul sofort beim Speichern – nicht erst im Februar. Bestätigte Helferdienste
  der Veranstaltungen werden genau einmal übernommen; am Jahresende gibt es die
  Liste je Person für die Meldungen ans Finanzamt.
- **Persönlicher Zugriff über die API**: Eine App oder Website handelt für eine
  bestimmte Person nur über eine Bindung, die der Verein per einmaliger Einladung
  erzeugt hat – je Anwendung, je Objekt, mit einzeln eingeschalteten Fähigkeiten.
  E-Mail-Adresse und Mitgliedsnummer sind dafür nie ein Nachweis.
- **Signierte Webhooks**: Der Verein trägt ein Ziel ein, das Modul schickt jede
  Änderung dorthin – signiert, erst nach dem Commit und über eine geplante
  Aufgabe, damit ein langsamer Empfänger niemanden aufhält. Mit Wiederholung,
  Schlüsselwechsel und einer Betriebsansicht, in der kein Geheimnis auftaucht.
- **Änderungsfeed für externe Anwendungen**: eine Website oder App liest mit
  einem Cursor nach, was sich geändert hat, und holt nach einer Unterbrechung
  genau den freigegebenen Stand nach. Der Feed sagt nur, *dass* sich etwas
  geändert hat – nie was. Ist der Cursor zu alt, sagt er das ausdrücklich, statt
  eine Lücke zu verschweigen.
- **Generalversammlung Schritt für Schritt**: ein Ablauf je Versammlung, rückwärts
  vom Termin gerechnet – Rechnung, Prüfung, Prüfbericht, fällige Wahlen,
  Tagesordnung, Einladungsfrist, dann Anwesenheit und Abstimmungen, danach
  Protokoll, Beschluss-PDFs, Meldung an die Behörde, Benutzergruppen und das
  Protokoll an die Mitglieder. Jeder Schritt zeigt seinen Stand und führt direkt
  zur richtigen Seite.
- **Veranstaltungen aus Vorlagen**: eine Vorlage ist eine Checkliste mit Phasen
  (Vorbereitung, Durchführung, Nachbereitung), Frist je Punkt und zuständiger
  Funktion. Aus ihr entsteht ein **Projekt in Dolibarr** mit einer Aufgabe je
  Punkt – Budget, Belege und Zeiten bleiben dort, wo Dolibarr sie führt.
  Mitgeliefert sind Turnier und Vereinsfest mit den österreichischen Punkten
  (Anzeige bei der Gemeinde, AKM, Jugendschutz, Versicherung, Registrierkasse) –
  als Erinnerung, nicht als Genehmigung. Die Anmeldung führt entweder Dolibarr
  oder eine externe Anwendung, nie beide.
- **Helferdienste**: Schichten mit Uhrzeit und Plätzen. Mitglieder fragen an, der
  Verein teilt ein, und nach der Veranstaltung wird bestätigt, wer wirklich da war
  – erst das ist eine geleistete Stunde. Doppelbelegung und Überschneidungen
  fängt das Modul ab. Dazu ein **Kurzbericht als PDF** mit Checkliste, Helfer-
  stunden und dem, was auf dem Projekt verrechnet wurde.
- **Fristen und Aufgaben**: ein Katalog der wiederkehrenden Pflichten – die des
  Vereinsgesetzes und der Steuer sind schon drin, eigene kommen dazu. Das Modul
  rechnet für jedes Vereinsjahr die Fristen aus, legt sie als Aufgaben in
  Dolibarrs Kalender bei der zuständigen Person an und erinnert daran; wechselt
  eine Funktion, warten offene Aufgaben auf die Bestätigung der Übergabe.

### Für die Vereinswebsite

- **REST-API**: Vereinsdaten, Vorstand, Steuerprofile, Beiträge, Einwilligungen,
  Beitrittsanträge und eine **Mitglieds-Zusammenfassung** (Mitgliedschaft,
  Beitragsstand mit Zahlungslink, offene Rechnungen mit PDF), dazu ein Abgleich
  nur der geänderten Mitglieder und eine **Webhook-Benachrichtigung** ohne
  persönliche Daten. Beschrieben in [docs/API.md](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/docs/API.md) und
  [docs/openapi.json](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/docs/openapi.json).
- **API-Reiter** in der Einrichtung: jede Schnittstelle mit ihren Rechten und
  einem Beispiel, die Benutzer mit API-Schlüssel und was sie aufrufen dürfen.

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

Neu eingeschaltet? *Einrichtung > Erste Schritte* führt in neun Schritten durch alles, was ein Verein
einstellen sollte, und erkennt an den Daten, was schon erledigt ist.

| Stelle in Dolibarr | Was das Modul ergänzt oder nutzt |
| --- | --- |
| *Start > Einstellungen > Unternehmen/Organisation* | Name, Anschrift, E-Mail, Telefon, Website und erster Monat des Rechnungsjahres – das Modul liest sie, es führt keine eigene Kopie |
| *Start > Einstellungen > Module > Vereine* | Einrichtung mit den Reitern Verein, Partner, Steuerprofile, Beiträge, Funktionen, Statuten, Sitzungsvorlagen, API, Einwilligungen, Spendenmeldung und Über |
| *Mitglieder*, *Geschäftspartner*, *Kategorien* (Dolibarr-eigene Module) | Pflicht und werden mit Vereine aktiviert; das Mitglieder-Menü bekommt *Verein* mit Übersicht, Abgleich, Beitragslauf, Funktionen, Schreiben an die Behörde und Sitzungen |
| Startseite | Widget *Vereine: Grenzen des Jahres* (für Benutzer, die Rechnungen lesen dürfen) |
| Karte eines Geschäftspartners | Reiter *Mitgliedschaft*; Kategorien *Mitglied* und *Ehemaliges Mitglied* |
| Mitgliedskarte | Reiter *Verein*; *Geschäftspartner anlegen* und *Verknüpfung mit Geschäftspartner* zieht das Modul nach |
| Mitgliedsart | Beitragsmodell (Reiter *Beiträge* in der Einrichtung) |
| Produkt- und Leistungskarte, Zeilen von Rechnungen und Lieferantenrechnungen | Zusatzfeld *Steuerprofil*; Rechnungen warnen bei Zeilen, deren Umsatzsteuer nicht zum Profil passt |
| Kundenrechnung | Hinweis bei einer Überzahlung mit Link zum Zuordnen; danach steht dort, wohin der Mehrbetrag ging |
| Rechnungs-PDF (Dolibarr-Vorlagen, unverändert) | Hinweise der Steuerprofile je Zeile und ZVR-Zahl im Hinweisbereich |
| E-Mail-Kampagnen | Empfängerauswahl *Vereine* |
| *Benutzer & Gruppen > Berechtigungen* | *Vereinsübersicht und Vereinsdaten lesen*; *Mitglieder und Geschäftspartner verknüpfen und abgleichen*; *Mitglieder-Zusammenfassung für die Website über die API lesen*; *Beitrittsanträge über die API anlegen*; *Spendenmeldung vorbereiten* (sieht Geburtsdaten und vbPK der Spender:innen) |
| Modul *API REST* | Nötig für `/api/index.php/vereine/...`; die Übersicht warnt, solange es aus ist |

So hängt es zusammen: Die Einrichtung speichert die Vereinsdaten als
Dolibarr-Konstanten; Übersicht und API lesen dieselben Daten über eine Klasse
(`VereineOrganization`). Eine Website sieht also genau das, was die Übersicht
zeigt. Mitgliedsereignisse (aktivieren, austreten, ausschließen, ändern,
löschen) erreichen das Modul über einen Dolibarr-Trigger, der den verknüpften
Geschäftspartner nachzieht.

## Versionen und Updates

Änderungen erscheinen als Releases mit Patchnotes auf der
[Release-Seite](https://github.com/Tabsi1998/dolibarr-vereine/releases),
meist mehrere gemergte Änderungen auf einmal. Versionen unter 1.0.0 sind Betas
(`v0.5.8-beta`, …) und als Pre-Release markiert; 1.0.0 ist das erste stabile
Release. Die Datei zum Installieren heißt immer `module_vereine-x.y.z.zip` –
ohne `-beta`, weil Dolibarr nur diesen Namen annimmt. Wie veröffentlicht wird,
steht in [docs/RELEASES.md](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/docs/RELEASES.md).

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
3. **Vereine (Österreich)** in der Modulliste aktivieren. Das Mitgliedermodul
   wird mit aktiviert.
4. Die Einrichtung des Moduls öffnen und ZVR-Zahl, Vereinsbehörde und
   Vereinszweck eintragen.
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
2. Reiter *Berechtigungen*, Modul *Vereine (Österreich)*: genau **Vereinsübersicht
   und Vereinsdaten lesen** und **Mitglieder-Zusammenfassung für die Website über
   die API lesen** anhaken, für Beitrittsanträge zusätzlich **Beitrittsanträge
   über die API senden**. Sonst nichts – vor allem nicht Mitglieder, Rechnungen
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
