# Changelog

Alle nennenswerten Änderungen am Modul Vereine. Das Format folgt
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/); Versionen folgen
[Semantic Versioning](https://semver.org/lang/de/), `-beta` kennzeichnet
Vorabversionen. Der Abschnitt einer Version ist der Text ihres GitHub-Releases.

## [Unreleased]

### Geändert

- Neue Arbeitsweise: Mehrere Pull Requests werden gesammelt und gemeinsam
  veröffentlicht. Ein Pull Request trägt seine Änderungen hier unter
  „Unreleased“ ein; die Version steigt erst im Release-Pull-Request
  (`docs/RELEASES.md`) (#131).
- **Nur noch Österreich:** Das Modul ist für österreichische Vereine. Die
  Einrichtung fragt kein Land und kein Registergericht mehr, die Vereinsdaten
  kennen nur die ZVR-Zahl, die Sitzungsvorlagen nur die Generalversammlung.
  Beim Aktivieren werden die Einstellungen des alten Länderprofils gelöscht
  (#129).
- **Alles auf Deutsch:** README, Doku, Changelog, API-Beschreibung,
  Issue-Vorlagen und das Webhook-Ereignis. Die englische Sprachdatei ist eine
  genaue Kopie der deutschen, damit Dolibarr mit englischer Oberfläche Deutsch
  statt Schlüssel zeigt. Die frühere deutsche Kopie der README entfällt (#130).
- **Grundsätze** für jede weitere Arbeit in `docs/ARCHITECTURE.md`: zwei Wege je
  Dokument, Organe arbeiten in Dolibarr, Mitglieder auch über die API,
  Dolibarr-Bordmittel zuerst, Unterschriften laut Statuten (#118).

### Veraltet

- API: `country_profile` (immer `AT`), `country_profile_complete` (immer
  `true`) und `register.court` (immer leer) bleiben bis 1.0 für bestehende
  Websites und entfallen dann (#129).

## [0.5.7-beta] - 2026-09-17

Sitzungsvorlagen und Texte fürs Protokoll (#20), dazu zwei Korrekturen aus der
Praxis (#105, #106).

### Neu

- Einrichtungsreiter *Sitzungsvorlagen*: eine Tagesordnung je Sitzungsart mit
  einem Text fürs Protokoll je Punkt und **Pflichtpunkten**. Vorbelegt für
  Österreich (Generalversammlung, Rechnungsprüfer) und damals noch Deutschland.
  Standardmäßig Pflicht: die Beschlussfähigkeit und der Bericht des Vorstands
  über Tätigkeit und Finanzen (§ 20 VerG).
- **Neue Sitzung aus der Vorlage:** Die Tagesordnung kommt aus der Vorlage; eine
  Sitzung warnt, solange ein Pflichtpunkt fehlt.
- **Texte fürs Protokoll** je Tagesordnungspunkt, vor der Sitzung vorbereitet
  und in ihr ergänzt. Platzhalter wie `{anwesend}`, `{quorum}` und `{ergebnis}`
  erscheinen mit den echten Zahlen der Sitzung, zum Zeitpunkt der ersten
  Abstimmung zum Punkt, eine spätere Ankunft zählt also; ein Punkt ohne
  Abstimmung zählt zu Beginn oder zur letzten Abstimmung davor. Ein geleerter
  Text bleibt leer; Texte folgen ihrem Punkt, wenn die Tagesordnung umgestellt
  wird.

### Behoben

- Briefe und Texte zeigten Zeilenumbrüche als `\n`, wenn sie vor 0.5.3
  gespeichert wurden, etwa die Anschrift der Behörde in der Anzeige einer
  Statutenänderung. Das Aktivieren des Moduls repariert gespeicherte Anschrift,
  Vereinszweck, Statutentext, Einwilligungstexte und Hinweise der Steuerprofile
  (#105).
- Die Statuten-Seite meldete „Zweck fehlt“ so, dass es nach dem Zweck für das
  Vermögen klang. Sie nennt jetzt den Vereinszweck, verlinkt sein Feld und zeigt
  ihn auf der Seite (#106).

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das
legt die Tabellen für Vorlagen und Texte an und repariert gespeicherte
Zeilenumbrüche.

## [0.5.6-beta] - 2026-09-17

Abstimmungen und Wahlen (#96, letzter Teil von #9) und die API-Dokumentation in
Dolibarr (#99).

### Neu

- Sitzungen bekommen Abstimmungen zu ihren Tagesordnungspunkten: Beschluss,
  Wahl, Statutenänderung und freiwillige Auflösung, mit Ja, Nein, Enthaltungen
  und bei geheimer Abstimmung nur dem Ergebnis.
- **Nur bei Beschlussfähigkeit** zum Zeitpunkt der Abstimmung und nie mit mehr
  Stimmen als anwesend und vertreten.
- **Mehrheiten laut Statuten:** einfache Mehrheit, die Mehrheit für
  Statutenänderung und Auflösung; Enthaltungen sind keine abgegebenen Stimmen.
  Bei Stimmengleichheit im Vorstand entscheidet der Vorsitz, wo die Statuten das
  vorsehen. Statutenänderung und Auflösung nur in einer Generalversammlung.
- **Eine gewonnene Wahl** beginnt die Funktionsperiode am Sitzungstag, beendet
  andere Perioden einer Funktion mit einer Person und erstellt die Meldung der
  Vertreter an die Vereinsbehörde.
- **Eine beschlossene Statutenänderung** speichert den aktuellen Text als
  Fassung und erstellt die Anzeige an die Vereinsbehörde.
- Einrichtungsreiter *API*:
  - Schritt für Schritt eine Website anbinden, mit Warnung, solange Dolibarrs
    Modul REST-API aus ist, und Link zu Dolibarrs API-Explorer
  - **jede Schnittstelle** des Moduls mit Methode und Pfad, Zweck, nötigen
    Rechten und einem `curl`-Beispiel, gelesen aus `docs/openapi.json` im Paket,
    die Liste ist also nie veraltet
  - **Benutzer mit API-Schlüssel**: ihre Rechte für diese Schnittstellen, direkt
    oder über Gruppen, und welche Schnittstellen sie aufrufen können;
    Administratoren werden hervorgehoben; der Schlüssel selbst wird nie gezeigt
  - **Webhooks**: das Ereignis, was es trägt und wie es sicher eingerichtet wird
- `docs/openapi.json` nennt die Rechte jeder Schnittstelle (`x-vereine-rights`).

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das
legt die Tabelle der Abstimmungen an.

## [0.5.5-beta] - 2026-09-17

Anwesenheit, Vollmachten und Beschlussfähigkeit (#95, zweiter Teil von #9), das
Layout der Statuten (#101) und fett gedruckte Pflichtfelder (#102).

### Neu

- Sitzungen, zu denen eingeladen wurde, bekommen eine Anwesenheitsliste der
  Eingeladenen: anwesend mit Ankunfts- und Gehzeit, entschuldigt, abwesend oder
  mit schriftlicher Vollmacht vertreten.
- **Vollmachten** nur, wo die Statuten sie erlauben, von einem stimmberechtigten
  Mitglied an ein anderes anwesendes stimmberechtigtes Mitglied, und nie im
  Vorstand: Vorstandsfunktionen werden persönlich ausgeübt.
- **Beschlussfähigkeit**, zu jeder Uhrzeit der Sitzung: bei einer
  Generalversammlung die Stimmen der Anwesenden plus ihrer Vollmachten gegen den
  Anteil laut Statuten (oder ohne Rücksicht auf die Zahl der Anwesenden), beim
  Vorstand die Anwesenden gegen den Anteil laut Statuten. Geht eine
  bevollmächtigte Person, zählt die Vollmacht nicht mehr.

### Behoben

- Vorschau der Statuten und Gegenüberstellung einer Änderung zeigten Listen in
  einer Zeile mit geschriebenem `\n`. Listen stehen jetzt ein Punkt je Zeile,
  eingerückt, und Absatznummern hängen vor ihrem Text, auch im PDF.
- Der Zweck, dem das Vermögen bei Auflösung zufällt, fasst bis 1000 Zeichen in
  einem größeren Feld, der Empfänger bis 500.
- Pflichtfelder sind fett, wie überall in Dolibarr: Regeln und Text der
  Statuten, Beitragseinstellungen, Schreiben an die Behörde, Austritt und
  Funktion am Mitglied, Tag der Meldung (#102).

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das
legt die Tabelle der Anwesenheit an.

## [0.5.4-beta] - 2026-09-17

Sitzungen und ihre Einladungen (#94, erster Teil von #9).

### Neu

- *Mitglieder > Verein > Sitzungen*: Vorstandssitzungen, ordentliche und
  außerordentliche Generalversammlungen mit Tag, Uhrzeit, Ort, Form und
  Tagesordnung. Eine Generalversammlung nimmt nur die Form, die die Statuten
  erlauben (Präsenz, virtuell oder hybrid, § 1 VirtGesG); virtuelle Teilnahme
  braucht ihre Zugangsdaten (§ 2 Abs. 2 VirtGesG).
- **Empfänger, vor dem Senden gezeigt:** Eine Vorstandssitzung lädt genau die
  Vorstandsmitglieder am Sitzungstag ein, eine Generalversammlung jedes aktive
  Mitglied, samt Stimmrecht laut Statuten. Senden braucht die Bestätigung, dass
  die Empfänger geprüft sind.
- **Fristen laut Statuten:** Einladung bis und Anträge bis; eine Einladung nach
  der Frist der Statuten wird markiert. Die Sitzungsliste warnt, wenn die letzte
  ordentliche Generalversammlung länger zurückliegt, als Statuten oder fünf Jahre
  erlauben (§ 5 Abs. 2 VerG).
- **Einladung:** per E-Mail über Dolibarrs Mailversand, mit Tagesordnung, Frist
  für Anträge und einem Hinweis für Mitglieder ohne Stimmrecht; ein PDF mit
  Briefen für Mitglieder ohne E-Mail oder wenn die Statuten keine E-Mail
  erlauben; ein Termin in der Agenda. Für jede Person bleiben Tag, Weg und ein
  etwaiger Sendefehler als Nachweis.
- Sitzungen lassen sich als abgehalten oder abgesagt markieren.

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das
legt die Tabellen der Sitzungen und den Menüeintrag an.

## [0.5.3-beta] - 2026-09-17

Statutenänderung (#89).

### Neu

- Einrichtungsreiter *Statuten*, Abschnitt *Statutenänderung*: die geltende
  Fassung gegenüber dem Text, wie er jetzt eingestellt ist, Abschnitt für
  Abschnitt, alt neben neu. Die Mehrheit kommt aus den geltenden Statuten; ein
  Beschlussentwurf und was nach dem Beschluss passiert, werden erklärt (§ 14
  Abs. 1 mit §§ 12 und 13 VerG).
- Die Gegenüberstellung als PDF, zum Mitschicken mit der Einladung zur
  Generalversammlung.
- Eine nach dem Beschluss gespeicherte Fassung erstellt die Anzeige der Änderung
  an die Vereinsbehörde mit ihrer Frist von vier Wochen, wo es schon eine Fassung
  gab.

### Behoben

- Textfelder zeigten Zeilenumbrüche als `\n`, und erneutes Speichern speicherte
  diesen Text: Einwilligungstexte, Vereinszweck, Hinweise der Steuerprofile, die
  Anschrift der Behörde und die Listen der Statuten. Sie behalten ihre
  Zeilenumbrüche jetzt. Ein mit `\n` erneut gespeicherter Text musste damals
  einmal von Hand korrigiert werden; seit 0.5.7 repariert das die Aktivierung.

### Update

Neues ZIP bereitstellen. Nichts neu aktivieren. Einwilligungstexte und die
Anschrift der Behörde auf ein geschriebenes `\n` durchsehen und es durch einen
Zeilenumbruch ersetzen.

## [0.5.2-beta] - 2026-09-17

Statuten als Text aus ihren Regeln, mit Fassungen (#88).

### Neu

- Einrichtungsreiter *Statuten*, neue Abschnitte:
  - **Text der Statuten:** Tätigkeitsbereich, Zweigvereine, Tätigkeiten und
    Mittel als Listen (mit den Vorschlägen des Finanzministeriums), weitere
    Aufnahmebedingungen, juristische Personen als Mitglieder, Ausschluss wegen
    nicht bezahlter Beiträge und die Formulierung zum Vermögen bei Auflösung:
    ohne Steuerbegünstigung, für §§ 34 ff. BAO (vier Formulierungen) oder für
    abzugsfähige Spenden nach § 4a EStG (vier Vorschläge).
  - **Prüfung** in einfachen Worten: fehlender Zweck, fehlende Tätigkeiten oder
    Mittel, Vorstand ohne oder mit unterschiedlichen Funktionsperioden, weniger
    als zwei Rechnungsprüfer, fehlender Zweck oder Empfänger des Vermögens, ein
    gemeinnütziger Verein ohne Vermögensbindung.
  - **Vorschau** der ganzen Statuten: das Muster des Innenministeriums (April
    2024), für steuerbegünstigte Vereine mit den Ergänzungen des
    Finanzministeriums (Vereinsrichtlinien Rz 867, 2025). Name, Sitz, Zweck,
    Regeln, Vorstand und Rechnungsprüfer aus dem Funktionskatalog,
    Mitgliedsarten und Austrittsregel kommen aus dem Modul.
  - **Fassungen:** Entwurf als PDF, der aktuelle Text als Fassung mit dem Tag des
    Beschlusses gespeichert, oder bestehende Statuten als PDF hochgeladen. Jede
    Fassung behält ihr PDF und ihre Prüfsumme; die geltende ist markiert.

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das
legt die Tabelle der Fassungen an.

## [0.5.1-beta] - 2026-09-17

Schreiben an die Vereinsbehörde in einem Layout (#87).

### Neu

- *Mitglieder > Verein > Schreiben an die Behörde*:
  - **Zuständige Behörde** mit Anschrift, E-Mail und Geschäftszahl (GZ). Die
    Seite nennt die Landespolizeidirektion, wo sie für den Sitz zuständig ist
    (§ 9 VerG mit § 8 SPG), und hinterfragt einen anderen Eintrag. Die acht
    Bezirkshauptmannschaften Tirols und die LPD Tirol lassen sich aus einer Liste
    übernehmen.
  - **Schreiben** aus Dolibarr ausgefüllt, als PDF: Statutenänderung (§ 14 Abs. 1
    VerG), neue Zustellanschrift (§ 14 Abs. 3), Registerauszug – aktuell, mit den
    Daten nach § 16 Abs. 1 Z 8 oder für einen früheren Tag (§ 17), freiwillige
    Auflösung mit oder ohne Abwickler (§ 28 Abs. 2), Errichtung (§ 11) und eine
    längere Frist für die erste Bestellung (§ 2 Abs. 3).
  - Anzeigen bekommen ihre Frist von vier Wochen, auch in Dolibarrs Agenda;
    überfällige Schreiben werden markiert, „eingebracht am“ schließt den
    Agenda-Termin ab.
- Dasselbe Layout für alle Schreiben: Briefkopf mit ZVR-Zahl, Behörde,
  Geschäftszahl, Titel mit Paragraph, Unterschriftszeilen der Vertreter,
  Beilagen. Die Meldung der Vertreter unter *Vorstand und Funktionen* nutzt es
  ebenfalls.

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das
legt die Tabelle der Schreiben und den Menüeintrag an.

## [0.5.0-beta] - 2026-09-17

Regeln der Statuten an einer Stelle (#86, erster Teil von #81).

### Neu

- Einrichtungsreiter *Statuten* mit dem, was die Statuten festlegen, vorbelegt aus
  den Musterstatuten des Innenministeriums (April 2024):
  - Mitgliedschaft: Mindestalter, Mitgliedsarten mit Stimmrecht in der
    Generalversammlung; die Austrittsregel aus dem Reiter *Beiträge* wird gezeigt.
  - Generalversammlung: wie oft (mindestens alle fünf Jahre, § 5 Abs. 2 VerG),
    Einladung Tage vorher und per Brief, E-Mail oder Website, Tage für Anträge,
    Stimmübertragung, Beschlussfähigkeit, Mehrheiten für Statutenänderung und
    Auflösung, virtuelle oder hybride Versammlungen, wo die Statuten sie
    vorsehen (§ 1 VirtGesG).
  - Vorstand: Beschlussfähigkeit und ob der Vorsitz bei Stimmengleichheit
    entscheidet.
  - Funktionsperiode je Funktion in Jahren (§ 3 Abs. 2 Z 8 VerG).
- Prüfungen in einfachen Worten: Werte, die das Vereinsgesetz nicht erlaubt,
  werden nicht gespeichert; Hinweise zeigen Vorstands- und Prüffunktionen ohne
  Funktionsperiode und Perioden, die zwischen zwei Generalversammlungen enden.
- *Vorstand und Funktionen* zeigt „Wahl fällig“, wenn eine Funktionsperiode laut
  Statuten abgelaufen ist.
- Beitrittsanträge über die API werden unter dem Mindestalter der Statuten
  abgelehnt, und ohne Geburtsdatum, solange es eines gibt.

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das
ergänzt die Funktionsperiode an den Funktionen (`sql/update_0.5.0.sql`).

## [0.4.4-beta] - 2026-09-17

Empfänger von E-Mail-Kampagnen (#19).

### Neu

- Dolibarrs E-Mail-Kampagnen bekommen die Empfänger *Vereinsmitglieder nach
  Status, Mitgliedsart, Funktion und Einwilligung*: aktive, Entwürfe, ehemalige
  oder alle Mitglieder; eine Mitgliedsart; der Vorstand oder eine heute besetzte
  Funktion.
- Zweck: Informationen des Vereins wie eine Einladung brauchen keine
  Einwilligung; ein Newsletter oder ein anderer Zweck erreicht nur Mitglieder,
  deren letzte Einwilligung zu diesem Text erteilt ist. Der Newsletter ist
  vorausgewählt, wenn es einen solchen Text gibt.
- *Minderjährige über ihre Erziehungsberechtigten erreichen*: Mitglieder unter 18
  bekommen statt ihrer eigenen Adresse die Kontakte ihrer Erziehungsberechtigten.
- Senden, Abmelden und Statistik bleiben Dolibarrs eigene.

### Update

Neues ZIP bereitstellen. Nichts neu aktivieren.

## [0.4.3-beta] - 2026-09-17

Funktionäre Teil 4 (#79): Benutzergruppen über Funktionen, erst nach Bestätigung.

### Neu

- Einrichtungsreiter *Funktionen*: Eine Funktion kann eine Dolibarr-Benutzergruppe
  nennen, zum Beispiel der Kassier die Gruppe mit Rechten auf Rechnungen und Bank.
- *Mitglieder > Vorstand und Funktionen*, Abschnitt *Rechte über Funktionen*: Das
  Modul schlägt vor, Inhaber mit Dolibarr-Benutzer in die Gruppe ihrer Funktion
  aufzunehmen und nach dem Ende wieder zu entfernen. Nichts ändert sich, bevor ein
  Administrator bestätigt; Gruppen ohne Funktion und Benutzer ohne Mitglied
  bleiben unberührt.
- Inhaber ohne Dolibarr-Benutzer werden aufgelistet, und welcher Benutzer über
  welche Funktion welche Rechte hat, für die Rechnungsprüfer.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das ergänzt die Spalte der Benutzergruppe an den Funktionen.

## [0.4.2-beta] - 2026-09-17

Funktionäre Teil 3 (#78): der Vorstand für eine Website.

### Neu

- `GET /vereine/board`: die Funktionen mit ihren heutigen Inhabern. Ein Name kommt
  nur mit Einwilligung des Inhabers (ein in der Einrichtung gewählter
  Einwilligungstext), oder für den Vorstand immer, wenn die Website ihn offenlegen
  muss (§ 25 Abs. 2 MedienG); andere Namen bleiben `null`.
- Einrichtungsreiter *Funktionen*, Abschnitt *Vorstand auf der Website*: Namen
  nur mit Einwilligung oder Vorstand immer mit Namen, und der Einwilligungstext,
  mit einer Erklärung der Offenlegungspflicht.
- Die Mitglieds-Zusammenfassung listet die eigenen Funktionen des Mitglieds
  (`functions`); eine beginnende oder endende Funktion zählt als Änderung für
  `changed_since`.

### Update

Neues ZIP bereitstellen. Nichts neu aktivieren.

## [0.4.1-beta] - 2026-09-17

Funktionäre Teil 2 (#77): Meldung an die Vereinsbehörde binnen vier Wochen.

### Neu

- Eine neue Periode einer Funktion, die den Verein vertritt, bekommt eine
  Meldefrist von vier Wochen (§ 14 Abs. 2 VerG) und, mit Dolibarrs Agenda, einen
  Termin an diesem Tag, verknüpft mit dem Mitglied.
- *Mitglieder > Vorstand und Funktionen*, Abschnitt *Meldung an die
  Vereinsbehörde*: offene Meldungen mit Frist und Überfällig-Markierung, fehlendes
  Geburtsdatum, fehlender Geburtsort oder fehlende Anschrift je Vertreter, das
  Meldungsschreiben als PDF mit jedem Vertreter am Tag (neue markiert) und *Als
  gemeldet vermerken*, was auch die Agenda-Termine abschließt.
- Mitgliedsfeld *Geburtsort*, das die Meldung braucht.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das legt die Tabelle der Meldungen und das Mitgliedsfeld an.

## [0.4.0-beta] - 2026-09-17

Funktionäre Teil 1 (#76): Funktionskatalog und Funktionsperioden.

### Neu

- Einrichtungsreiter *Funktionen*: Die üblichen Funktionen österreichischer
  Vereine werden vorgeschlagen – Obmann/Obfrau, Kassier, Schriftführung mit ihren
  Stellvertretungen, Rechnungsprüfer –, jede mit Vorstand, vertritt den Verein,
  Prüfer und wie viele nötig sind. Der Verein ändert sie, schaltet sie ab und
  ergänzt eigene.
- Reiter *Verein* am Mitglied, Abschnitt *Funktionen*: eine Funktion ab einem
  Tag übernehmen, beenden; beendete Perioden bleiben.
- *Mitglieder > Vorstand und Funktionen*: wer an einem Tag welche Funktion hat,
  und was nicht passt – eine unbesetzte oder zu oft besetzte Funktion, ein
  Vorstand mit weniger als zwei Personen, ein Prüfer im Vorstand (§ 5 VerG).

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das legt die Tabellen der Funktionen, die vorgeschlagenen Funktionen
und den Menüeintrag an.

## [0.3.11-beta] - 2026-09-17

Beiträge Teil 6 (#65): Einwilligungen mit Textversion und Beitrittsanträge über
die API.

### Neu

- Einrichtungsreiter *Einwilligungen*: Einwilligungstexte je Zweck, zum Beispiel
  Fotos auf der Website oder der Newsletter. Ein geänderter Text wird eine neue
  Version; frühere Einwilligungen behalten die zugestimmte Version.
- Reiter *Verein* am Mitglied, Abschnitt *Einwilligungen*: Stand je Zweck mit
  Version, Zeitpunkt und Quelle (Website, Papier, Mitgliedskarte), eine
  Einwilligung auf Papier eintragen, einen Widerruf eintragen, und der ganze
  Verlauf. Nichts wird geändert oder gelöscht.
- Website-API: `GET /vereine/consents` mit den anzuzeigenden Texten, und
  `POST /vereine/applications`, das ein Mitglied im Entwurf mit seinen
  Einwilligungen anlegt – einmal je `external_id` – und das neue Recht
  *Beitrittsanträge über die API anlegen* braucht.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das legt die Tabellen der Einwilligungen und Anträge und das neue
Recht an.

## [0.3.10-beta] - 2026-09-17

Beiträge Teil 4 (#63): SEPA-Lastschrift aus dem Beitragslauf.

### Neu

- Der Beitragslauf prüft das Mandat des Zahlers – das Standard-Bankkonto des
  Geschäftspartners mit Mandatsreferenz und Unterschriftsdatum – und meldet ein
  36 Monate nicht genutztes Mandat als abgelaufen.
- Mit Dolibarrs Modul *Lastschriftaufträge* bietet der Beitragslauf *Lastschrift
  anfordern, wo ein gültiges Mandat besteht*: Die Rechnung bekommt die
  Zahlungsart Lastschrift, eine Vorankündigung mit Betrag, frühestem Einzugstag,
  Mandatsreferenz und Gläubiger-ID, und einen Einzugsauftrag, den Dolibarr wie
  gewohnt zum Auftrag an die Bank macht.
- Einrichtungsreiter *Beiträge*, Abschnitt *SEPA-Lastschrift*: wie es
  funktioniert, eine Warnung, wenn Modul oder Gläubiger-ID fehlen, und die Tage
  der Vorankündigung (14, wenn nicht anders eingestellt).

### Update

Neues ZIP bereitstellen. Nichts neu aktivieren; zum Nutzen Dolibarrs Modul
*Lastschriftaufträge* aktivieren.

## [0.3.9-beta] - 2026-09-17

Beiträge Teil 5 (#64): Austritt mit Grund und der Kündigungsfrist der Statuten.

### Neu

- Einrichtungsreiter *Beiträge*, Abschnitt *Austritt laut Statuten*:
  Kündigungsfrist in Monaten und wann die Kündigung wirkt – an jedem Tag, zum
  Ende eines Monats, eines Quartals oder des Vereinsjahres, das in jedem Monat
  beginnen darf.
- Reiter *Verein* am Mitglied, Abschnitt *Austritt*: Kündigung, Ausschluss, Tod
  oder Streichung, mit dem Tag der Kündigung oder Entscheidung und einer internen
  Notiz. Bei einer Kündigung berechnet das Modul den letzten Tag der
  Mitgliedschaft; das Mitglied bleibt bis dahin aktiv, und der Austritt lässt sich
  zurücknehmen.
- Am letzten Tag wird das Mitglied auf gekündigt gesetzt, bei einem Ausschluss
  auf ausgeschlossen: sofort, wenn der Tag gekommen ist, sonst über die geplante
  Aufgabe *Vereine: fällige Austritte* (Dolibarrs Modul *Geplante Aufgaben*) oder
  den Knopf *Jetzt wirksam machen*.
- Der Beitragslauf legt keine Periode an, die nach dem letzten Tag beginnt, und
  nennt den geplanten Austritt.
- Die Mitglieds-Zusammenfassung der Website-API hat `membership_ends`, und ein
  eingetragener, vollzogener oder zurückgenommener Austritt zählt als Änderung für
  `changed_since`.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das legt die Tabelle der Austritte und die geplante Aufgabe an.

## [0.3.8-beta] - 2026-09-17

Beiträge Teil 3b (#62): Familien mit einem Zahler.

### Neu

- Dolibarrs Mitgliedskarte bekommt *Beiträge zahlt*: der Geschäftspartner, der
  die Beitragsrechnungen des Mitglieds bekommt, zum Beispiel ein Elternteil – der
  Geschäftspartner eines anderen Mitglieds oder einer, der kein Mitglied ist.
  Mitglieder mit demselben Zahler sind eine Familie; jedes Mitglied behält seinen
  eigenen Geschäftspartner oder keinen.
- Einrichtungsreiter *Beiträge*, Abschnitt *Familien*: die Familienregel – kein
  Rabatt, ein Rabatt in Prozent für jedes Mitglied nach dem mit dem höchsten
  Beitrag, oder ein Höchstbetrag je Beitragsjahr, der zählt, was in diesem Jahr
  schon verrechnet wurde – und die Liste der Familien.
- Der Beitragslauf stellt Beiträge einer Familie mit demselben Beginntag auf eine
  Rechnung an den Zahler, mit einer Zeile je Mitglied, verknüpft mit jeder
  Beitragsperiode. Die Familienregel kommt nach der eigenen Ermäßigung des
  Mitglieds; die Vorschau nennt sie und den Zahler und meldet einen Zahler, den es
  nicht mehr gibt.
- Die Mitglieds-Zusammenfassung der Website-API sagt, wer die Beitragsrechnungen
  bekommt (`fee.payer`: `self` oder `other`), und bietet keine Online-Zahlung des
  Beitrags, wenn ein Zahler sie bekommt. Das Bezahlen einer Familienrechnung zählt
  für jedes Mitglied darauf als Änderung, für `changed_since` und für Webhooks.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das legt das Mitgliedsfeld *Beiträge zahlt* an.

## [0.3.7-beta] - 2026-09-17

Beiträge Teil 3a (#62): Ermäßigungen nach Alter, mit Nachweis, und Befreiungen.

### Neu

- Einrichtungsreiter *Beiträge*, Abschnitt *Ermäßigungen*: Regeln nach dem Alter
  am ersten Tag einer Beitragsperiode (zum Beispiel „Jugend bis 17: 50 % weniger“)
  oder mit einem Nachweis wie einem Studierendenausweis, als Prozent weniger, als
  fester niedrigerer Betrag je Periode oder beitragsfrei, für alle oder eine
  Mitgliedsart. Regeln lassen sich ändern und abschalten.
- Dolibarrs Mitgliedskarte bekommt *Vom Beitrag befreit* mit Grund (zum Beispiel
  Ehrenmitglieder) und *Ermäßigung mit Nachweis* mit dem Tag, bis zu dem der
  Nachweis gilt.
- Der Beitragslauf wendet höchstens eine Ermäßigung je Beitrag an – Befreiung,
  dann gültiger Nachweis, dann Alter –, nennt sie in der Vorschau und auf der
  Rechnungszeile und meldet einen abgelaufenen Nachweis oder ein fehlendes
  Geburtsdatum. Ein befreites Mitglied bekommt die Beitragsperiode ohne Rechnung
  und ohne Aufnahmegebühr.
- Die Mitglieds-Zusammenfassung der Website-API zeigt den Betrag des Mitglieds
  nach der Ermäßigung und die Ermäßigung (`fee.discount`).

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das legt die Tabelle der Ermäßigungen und die Mitgliedsfelder an.

## [0.3.6-beta] - 2026-09-17

Beiträge: anteilig nach Monat, Quartal oder Halbjahr (#68).

### Geändert

- *Anteilig beim Eintritt* an der Mitgliedsart ist eine Auswahl statt eines
  Häkchens: nicht anteilig, nach Monaten, nach Quartalen oder nach Halbjahren.
  Nach Halbjahren zahlt ein Eintritt im ersten Halbjahr des Beitragsjahres den
  vollen Betrag und im zweiten die Hälfte; nach Quartalen die restlichen
  Quartale. Teile zählen ab Beginn des Beitragsjahres, der Teil des Eintritts
  voll. Eine Beitragsperiode, die sich nicht in Quartale oder Halbjahre teilen
  lässt, wird nach Monaten berechnet.
- Ein gesetztes Häkchen aus 0.3.4 und 0.3.5 wird beim Aktivieren zu „nach
  Monaten“; das alte Feld wird danach entfernt.
- Einrichtungsreiter *Beiträge*, Beitragslauf und `GET /vereine/membershipfees`
  zeigen die Art der anteiligen Berechnung (`proration`); `prorated` bleibt für
  bestehende Websites.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das ersetzt das Häkchen durch die Auswahl und behält die Einstellung.

## [0.3.5-beta] - 2026-09-17

Beiträge Teil 2 (#61 und #14): der Beitragslauf.

### Neu

- *Mitglieder > Verein > Beitragslauf*: eine Vorschau jedes bis zu einem Tag
  fälligen Beitrags, je Mitgliedsart oder für alle, mit Periode, Betrag,
  Aufnahmegebühr und Grund in einfachen Worten („anteilig, 4 von 12 Monaten“,
  „erster Beitrag“). Mitglieder ohne Geschäftspartner, Mitgliedsarten ohne Betrag
  und ein Rückstand mehrerer Perioden werden markiert.
- Das Anlegen der gewählten Beiträge erzeugt je Mitglied und Periode, was
  Dolibarrs Mitgliedskarte bei „Neues Abonnement – Rechnung erstellen“ erzeugt:
  eine Beitragsperiode und eine damit verknüpfte freigegebene Rechnung, mit der
  Beitragsleistung der Mitgliedsart und ihrem Steuerprofil. Fehlende
  Geschäftspartner lassen sich im selben Schritt anlegen. Ein zweiter Lauf für
  dieselbe Periode legt nichts an; eine spätere Periode entsteht erst, wenn die
  früheren da sind.
- Die Seite listet die letzten Beitragsrechnungen mit ihrem heutigen Stand,
  auch die auf der Mitgliedskarte angelegten, und das Protokoll des Moduls hält
  jede Beitragsrechnung und jeden Lauf fest.
- Beitragsrechnungen sind für andere Module an Dolibarrs eigener Verknüpfung von
  Rechnung und Beitragsperiode erkennbar, beschrieben in `docs/ARCHITECTURE.md`;
  die Website-API markiert sie mit `fee` an jeder Rechnung.
- Anlegen braucht Dolibarrs Rechte, Abonnements zu erfassen und Rechnungen
  anzulegen und freizugeben.

### Geändert

- Die Mitglieds-Zusammenfassung zählt eine Beitragsperiode nur dann als bezahlt,
  wenn sie keine Beitragsrechnung hat oder diese bezahlt ist. Dolibarr behandelt
  eine Periode als bezahlt, sobald sie erfasst ist; nach einem Beitragslauf hätte
  die Website also „bezahlt bis 31. Dezember“ für eine unbezahlte Rechnung
  gezeigt. Der Beitragsstatus ist jetzt `invoiced`, bis die Rechnung bezahlt ist.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das ergänzt den Menüeintrag *Beitragslauf*.

## [0.3.4-beta] - 2026-09-17

Beiträge Teil 1 (#60): das Beitragsmodell einer Mitgliedsart.

### Neu

- Dolibarrs Karte der Mitgliedsart bekommt vier Felder: den Monat, in dem das
  Beitragsjahr beginnt (leer für „ab Eintritt“, Jänner für das Kalenderjahr,
  September für eine Saison), ob ein erster Beitrag während des Beitragsjahres
  nach Monaten anteilig ist, eine Aufnahmegebühr und die Beitragsleistung, deren
  Steuerprofil USt und Rechnungshinweis bestimmt (leer: Dolibarrs
  Abonnement-Leistung).
- Einrichtungsreiter *Beiträge*: jede Mitgliedsart mit ihrem Beitrag, eine
  Erklärung jeder Einstellung in einfachen Worten und was ein Eintritt heute
  kosten würde, zum Beispiel „17. September bis 31. Dezember: 20,00 € (anteilig,
  4 von 12 Monaten), dazu Aufnahmegebühr 20,00 €“. Er warnt vor Mitgliedsarten
  ohne Betrag und Beitragsleistungen ohne Steuerprofil.
- `GET /vereine/membershipfees` für „Mitglied werden“ auf einer Website.
- Die Berechnung ist reines PHP mit Unit-Tests; der Beitragslauf (#61) nutzt sie.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das ergänzt die Felder an der Karte der Mitgliedsart.

## [0.3.3-beta] - 2026-09-17

Website-API Teil 4 von 4 (#52 und #16): Benachrichtigungen ohne persönliche Daten.

### Neu

- Neues Ereignis `VEREINE_MEMBER_CHANGED` für Dolibarrs Webhooks: Wenn sich ein
  Mitglied, eine Beitragsperiode, eine Kundenrechnung des Geschäftspartners des
  Mitglieds oder eine Zahlung darauf ändert, bekommt ein Webhook-Ziel die
  Mitglieds-ID, die Ursache und den Zeitpunkt – nie Geburtsdatum, Anschrift oder
  Notizen, die Dolibarrs eigene Mitglieder-Webhooks senden und in ihrem Verlauf
  behalten würden.
- Ein Ereignis je Mitglied je Aktion; ein scheiternder Webhook stoppt die
  Änderung nie.
- `docs/API.md` erklärt die Einrichtung in Dolibarr und auf der Website.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das listet das neue Ereignis für Webhook-Ziele.

## [0.3.2-beta] - 2026-09-17

Website-API Teil 3 (#51): ein Abgleich, der nur geänderte Mitglieder liest.

### Neu

- `GET /vereine/members`: Zusammenfassungen aller Mitglieder nach ID, geblättert
  mit `?limit=` und `?page=`. Mit `?changed_since=` nur die Mitglieder, deren
  Zusammenfassung sich zu oder nach diesem Zeitpunkt geändert hat – auch wenn sich
  nur eine Beitragsperiode, eine Rechnung oder eine Zahlung geändert hat, oder
  wenn ein Beitrag durch das Datum fällig oder eine Rechnung überfällig wurde.
- Jede Mitglieds-Zusammenfassung trägt `updated_at`, den Zeitpunkt der letzten
  Änderung.
- `GET /vereine/status` liefert `server_time`, Dolibarrs Uhr, als Beginn eines
  Abgleichs.
- `docs/API.md` listet, was als Änderung zählt, was ein Abgleich nicht bemerkt
  (Löschungen, eine auf eine unbezahlte Rechnung angerechnete Gutschrift, der
  Zahlungsdienst) und wie man abgleicht, ohne etwas zu verlieren.

### Update

Neues ZIP bereitstellen. Deaktivieren und Aktivieren ist nicht nötig.

## [0.3.1-beta] - 2026-09-17

Website-API Teil 2 (#50): die Rechnungen eines Mitglieds und ihre PDFs.

### Neu

- `GET /vereine/members/{id}/invoices`: alle freigegebenen Rechnungen des
  Geschäftspartners des Mitglieds, neueste zuerst, mit Art, Datum, Fälligkeit,
  Betrag, offenem Betrag, Status (offen, überfällig, bezahlt, aufgegeben) und
  Zahlungslink; `?limit=` und `?page=` blättern.
- `GET /vereine/members/{id}/invoices/{invoice}/pdf`: das PDF einer Rechnung des
  Mitglieds, base64-kodiert. Die Rechnung eines anderen Mitglieds, ein Entwurf
  oder eine unbekannte Rechnung ergibt 404. Ein nie erzeugtes PDF wird so erzeugt,
  wie Dolibarr es auf der Rechnungskarte erzeugt.
- Beide brauchen nur das Recht *Mitglieder-Zusammenfassung für die Website über
  die API lesen*; der Website-Benutzer kann Rechnungen oder Dokumente weiter nicht
  über Dolibarrs eigene API lesen.

### Geändert

- Die offenen Rechnungen in der Mitglieds-Zusammenfassung tragen zusätzlich `id`,
  `type` und `status`, damit die Website ihre PDFs holen kann.

### Update

Neues ZIP bereitstellen. Deaktivieren und Aktivieren ist nicht nötig.

## [0.3.0-beta] - 2026-09-17

Website-API Teil 1 (#49 und #16): Mitglieds-Zusammenfassungen für die Website des
Vereins.

### Neu

- `GET /vereine/members/{id}/summary`: Mitgliedsnummer, Name, Mitgliedsart,
  Status, Mitglied seit, bezahlt bis, der Beitrag (bezahlt oder fällig, nächste
  Fälligkeit, Betrag der Mitgliedsart, Zahlungslink) und die offenen Rechnungen
  des Geschäftspartners des Mitglieds mit offenem Betrag, Fälligkeit und
  Zahlungslink. Zahlungslinks erscheinen nur, wenn Dolibarr einen
  Online-Zahlungsdienst wie Stripe oder PayPal hat.
- `GET /vereine/members/lookup?ref=` oder `?email=`: dieselbe Zusammenfassung,
  gefunden über Mitgliedsnummer oder E-Mail-Adresse (ohne Unterschied zwischen
  Groß- und Kleinschreibung); 404, wenn niemand passt, 409, wenn mehrere Mitglieder
  die E-Mail-Adresse teilen.
- Neues Recht *Mitglieder-Zusammenfassung für die Website über die API lesen*. Ein
  Website-Benutzer mit nur diesem Recht und *Vereinsübersicht und Vereinsdaten
  lesen* liest die Zusammenfassungen, aber nicht Dolibarrs eigene Schnittstellen
  für Mitglieder, Geschäftspartner oder Rechnungen. Die Zusammenfassung enthält nie
  Geburtsdatum, Anschrift, Telefon, E-Mail, Notizen oder Bankdaten.
- `docs/openapi.json` beschreibt jede Schnittstelle des Moduls; die
  Laufzeit-Tests vergleichen jede Antwort damit. `docs/API.md` erklärt Schritt für
  Schritt, wie der Website-Benutzer eingerichtet wird.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das registriert das neue Recht.

## [0.2.8-beta] - 2026-09-17

Registrierkassen-Check und der 13-%-Satz, Teil 5 von 5 (#3).

### Neu

- Die Übersicht sagt je Sphäre in einfachen Worten, ob eine Registrierkasse nötig
  ist: Unentbehrliche Hilfsbetriebe und kleine Vereinsfeste brauchen keine (§ 3
  Barumsatzverordnung 2015); andere Betriebe brauchen eine ab 15.000 Euro Umsatz,
  wenn mehr als 7.500 Euro davon bar oder mit Karte bezahlt werden (§ 131b BAO),
  ab dem vierten Monat nach dem Voranmeldungszeitraum, in dem beides erstmals
  überschritten wurde. Die Ausnahme für kleine Vereinskantinen (höchstens 52 Tage,
  45.000 Euro ab 2026) wird erklärt.
- Barumsätze zählen Zahlungen bar, mit Karte, Scheck oder online, aufgeteilt auf
  die Sphären der bezahlten Rechnung; das Modul sagt, dass Barverkäufe ohne
  Rechnung in Dolibarr fehlen und dass es selbst keine Registrierkasse ist.
- `GET /vereine/thresholds` liefert den Registrierkassen-Check als
  `cash_register`.
- Der Reiter *Steuerprofile* bietet an, den ermäßigten Satz von 13 % (§ 10 Abs. 3
  UStG) in Dolibarrs USt-Tabelle für Österreich zu ergänzen, die nur 0, 10 und
  20 % mitbringt. Zweimal ergänzen ändert nichts.

### Update

Neues ZIP bereitstellen. Deaktivieren und Aktivieren ist nicht nötig.

## [0.2.7-beta] - 2026-09-16

Grenzen und Ampel, Teil 4 von 5 (#3).

### Neu

- Die Übersicht zeigt die Grenzen eines Kalenderjahres als Ampel, mit einem Satz
  in einfachen Worten: die Kleinunternehmergrenze (35.000 Euro netto bis 2024,
  55.000 Euro brutto mit 10 % Toleranz ab 2025) und den Umsatz
  begünstigungsschädlicher Betriebe (§ 45a BAO: 40.000 Euro bis 2023, 100.000 Euro
  ab 2024). Frühere Jahre lassen sich öffnen; Registrierkassenpflicht und die 72
  Stunden kleiner Feste werden genannt und später geprüft.
- Einnahmen zählen aus freigegebenen und bezahlten Rechnungen, Gutschriften und
  Ersatzrechnungen nach Rechnungsdatum, jede Zeile über ihr Steuerprofil.
  Entwürfe, aufgegebene und Anzahlungsrechnungen zählen nicht; Zeilen ohne
  Steuerprofil werden gemeldet, nicht gezählt. Einnahmen von Hilfsbetrieben
  (Liebhaberei) und aus der Sportbefreiung zählen nicht zur
  Kleinunternehmergrenze, wie das Finanzministerium sagt. Für § 45a BAO vergleicht
  das Modul brutto, um eher früh als spät zu warnen.
- Eine im Vorjahr überschrittene Kleinunternehmergrenze wird gemeldet.
- Startseiten-Widget *Vereine: Grenzen des Jahres* und REST-API
  `GET /vereine/thresholds?year=`, beide nur für Benutzer, die Rechnungen lesen
  dürfen.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das registriert das Startseiten-Widget.

## [0.2.6-beta] - 2026-09-16

Rechnungs-PDFs, Teil 3 von 5 (#3).

### Neu

- Rechnungs-PDFs drucken die Rechnungshinweise der Steuerprofile der Zeilen im
  Hinweisbereich, mit den Zeilen, für die sie gelten, zum Beispiel „Zeile 1:
  Echter Mitgliedsbeitrag ohne Gegenleistung, nicht umsatzsteuerbar.“ Derselbe
  Hinweis auf mehreren Zeilen wird einmal gedruckt.
- Rechnungs-PDFs drucken die ZVR-Zahl des Vereins (damals in Deutschland
  Registernummer und Registergericht).
- Beides lässt sich abschalten: Reiter *Steuerprofile* und Einrichtung des
  Vereins. Beides ist standardmäßig an.
- Dolibarrs PDF-Vorlagen bleiben, wie sie sind: Der Text wird nur für das PDF an
  den öffentlichen Hinweis der Rechnung angehängt und danach zurückgestellt;
  nichts wird gespeichert.

### Geändert

- Der Befreiungsgrund für E-Rechnungen (VATEX) wanderte in ein eigenes Issue: In
  Dolibarr 24 ist er eine Spalte der USt-Tabelle, die kein Kerncode liest, ein
  Code je Satz.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das registriert den PDF-Hook. Ein Rechnungs-PDF neu erzeugen, um die
Hinweise zu sehen.

## [0.2.5-beta] - 2026-09-16

Steuerprofile an Produkten und Rechnungen, Teil 2 von 5 (#3).

### Neu

- Zusatzfeld *Steuerprofil* an Produkten und Leistungen, Kundenrechnungszeilen
  und Lieferantenrechnungszeilen. Die Liste bietet nur aktive Profile. Dolibarrs
  REST-API zeigt es als `options_vereine_taxprofile`; `GET /vereine/taxprofiles`
  liefert jetzt die passende `id`.
- Der USt-Satz eines Produkts folgt seinem Steuerprofil, wenn das Produkt
  angelegt oder gespeichert wird. Der Satz ändert sich über Dolibarrs
  Preisaktualisierung, der Bruttopreis wird also neu berechnet. Mit mehreren
  Preisstufen sagt das Modul nur, welcher Satz zu setzen ist.
- Eine neue Rechnungszeile mit Produkt übernimmt das Steuerprofil des Produkts;
  eine Zeile ohne Produkt kann eines wählen.
- Kunden- und Lieferantenrechnungen listen in einfachen Worten die Zeilen, deren
  USt-Satz nicht zum Steuerprofil passt. Das Modul ändert nie eine Rechnung.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das ergänzt das Zusatzfeld und die Rechnungs-Hooks. Bestehende
Produkte und Rechnungen behalten ihre Sätze; an einem Produkt ein Steuerprofil
wählen, damit sein Satz folgt.

## [0.2.4-beta] - 2026-09-16

Steuerprofile in einfachen Worten.

### Geändert

- Die Einrichtung der Steuerprofile beginnt mit drei Fragen (welche Art von
  Einnahme, fällt USt an, was steht auf der Rechnung) und erklärt jeden Bereich
  eines Vereins und jede USt-Behandlung in Alltagssprache, mit den Beispielen der
  Broschüre „Vereine und Steuern“ des Finanzministeriums.
- Bereiche und USt-Behandlungen heißen zuerst nach dem, was sie bedeuten, der
  Fachbegriff kommt danach; die Rechtsgrundlage ist ein kleiner Link für die
  Steuerberatung.
- Ablehnungen sagen in einfachen Worten, warum eine Kombination nicht geht, und
  nennen am Ende die Rechtsgrundlage.

### Update

Neues ZIP bereitstellen. Deaktivieren und Aktivieren ist nicht nötig: Nur Texte
und die Einrichtungsseite haben sich geändert. Profilnamen und Rechnungshinweise,
die der Verein schon hat, bleiben, wie sie sind.

## [0.2.3-beta] - 2026-09-16

Steuerprofile für österreichische Vereine, Teil 1 von 5 (#3).

### Neu

- Sphären eines österreichischen Vereins (ideeller Bereich,
  Vermögensverwaltung, unentbehrlicher und entbehrlicher Hilfsbetrieb, kleines
  Vereinsfest, begünstigungsschädlicher Betrieb) und USt-Behandlungen (nicht
  umsatzsteuerbar ohne Gegenleistung oder als Liebhaberei, befreit als
  Kleinunternehmer oder Sportverein, 10 %, 13 %, 20 %), jeweils mit
  Rechtsgrundlage.
- Steuerprofile: Sphäre, Behandlung, Satz und Rechnungshinweis. Neun
  vorgeschlagene Profile kommen mit der Aktivierung; die Sportbefreiung und 10 %
  ohne Liebhaberei beginnen inaktiv. Erneutes Aktivieren ersetzt nie ein Profil,
  das der Verein geändert hat.
- Einrichtungsreiter *Steuerprofile* zum Anlegen, Bearbeiten und Ein- und
  Ausschalten. Der Satz folgt aus der Behandlung. Kombinationen, die das Gesetz
  ausschließt, werden abgelehnt: 10 % oder die Sportbefreiung in einem
  begünstigungsschädlichen Betrieb, eine Befreiung ohne Rechnungshinweis.
- REST-API `GET /vereine/taxprofiles`.
- Rechtsquellen für Sphären, Sätze und Befreiungen in `docs/LEGAL-SOURCES.md`, im
  Gesetz selbst gelesen.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das legt die Tabelle der Steuerprofile und die vorgeschlagenen Profile
an. Daten bleiben.

## [0.2.2-beta] - 2026-09-16

Mitgliedskarte und Modul arbeiten zusammen.

### Behoben

- Ein Geschäftspartner, der mit *Geschäftspartner anlegen* auf Dolibarrs
  Mitgliedskarte angelegt oder dort in *Verknüpfung mit Geschäftspartner* gewählt
  wird, bekommt jetzt sofort Mitglieds-Kategorie, Kunden-Kennzeichen und
  Kundentyp, und das Protokoll des Moduls hält es fest. Dolibarr schreibt diese
  Verknüpfung ohne Trigger, bis dahin brachte nur die Abgleichsseite einen solchen
  Geschäftspartner in Ordnung. Das Lösen der Verknüpfung nimmt dem
  Geschäftspartner ohne Mitglied die Mitglieds-Kategorien.

### Neu

- Reiter *Verein* an der Mitgliedskarte: der verknüpfte Geschäftspartner mit
  Kategorien, Kunden-Kennzeichen und Kundentyp, was nicht zum Mitglied passt,
  offene Rechnungen, Erziehungsberechtigte und das Protokoll des Moduls, mit
  *Mit dem Mitglied abgleichen*. Ohne Geschäftspartner verweist er auf die
  Abgleichsseite.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das registriert den neuen Reiter und den Hook der Mitgliedskarte.
Daten und Einstellungen bleiben.

## [0.2.1-beta] - 2026-09-16

Die Abgleichsseite lässt sich schneller abarbeiten.

### Neu

- *Alle auswählen* im Kopf jedes Abschnitts mit einem Sammelschritt. Zeilen, deren
  Mitglied einen vorgeschlagenen Geschäftspartner hat, bleiben ohne Häkchen.
- Ein Klick auf eine Zeile oder ihren Knopf *Aktionen* öffnet ein Fenster mit den
  Schritten für diese Zeile: anlegen, verknüpfen oder abgleichen (immer über die
  Vorschau), Mitglied bearbeiten, Geschäftspartner bearbeiten,
  Erziehungsberechtigte ergänzen. Nichts passiert, bevor ein Schritt gewählt ist,
  und nur Schritte, für die der Benutzer die Rechte hat, werden angeboten. Das
  Bearbeiten eines Mitglieds kehrt nach dem Speichern zum Abgleich zurück;
  Dolibarrs Formular für Geschäftspartner endet auf der Karte des
  Geschäftspartners.
- Menüeintrag *Mitglieder > Verein > Partner-Einstellungen* für Administratoren:
  dieselbe Einrichtungsseite wie in der Modulliste.

### Geändert

- Der Abgleich nennt die Spalte *Geschäftspartner* wie der Rest der Seite.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das ergänzt den neuen Menüeintrag. Daten und Einstellungen bleiben.

## [0.2.0-beta] - 2026-09-16

Mitglieder und Geschäftspartner arbeiten zusammen.

### Neu

- Ein freigegebenes Mitglied bekommt automatisch einen Geschäftspartner, wenn die
  neue Einstellung an ist. Dolibarrs eigenes `create_from_member` legt ihn an; wenn
  ein bestehender Geschäftspartner schon der des Mitglieds sein könnte (gleiche
  E-Mail oder gleicher Name und gleiche PLZ), wird nichts angelegt, und die
  Abgleichsseite schlägt ihn stattdessen vor.
- Geschäftspartner von Mitgliedern folgen dem Mitgliedsstatus: Kategorie
  „Mitglied“, solange aktiv, „Ehemaliges Mitglied“ nach Austritt oder Ausschluss,
  wahlweise eine Unterkategorie je Mitgliedsart. Das Kunden-Kennzeichen wird
  gesetzt; der Kundentyp wird nur ausgefüllt, wenn er leer ist, und sonst
  gemeldet, weil das Mahnwesen-Modul Spesen nach Kundentyp verrechnet.
- Abgleichsseite *Mitglieder > Verein > Mitglieder und Partner*: Mitglieder ohne
  Geschäftspartner (mit Vorschlägen zum Verknüpfen), Geschäftspartner, die nicht
  passen, abweichende E-Mail oder Anschrift, Geschäftspartner in der
  Mitglieds-Kategorie ohne aktive Mitgliedschaft, Minderjährige ohne
  Erziehungsberechtigte, mehrere Geschäftspartner mit der E-Mail eines Mitglieds.
  Jede Sammeländerung zeigt eine Vorschau und läuft erst nach Bestätigung.
- Reiter *Mitgliedschaft* an der Karte des Geschäftspartners: Mitglied, Art,
  Status, Mitglied seit, bezahlt bis, Kategorien, offene Rechnungen,
  Erziehungsberechtigte und das Protokoll des Moduls.
- Erziehungsberechtigte minderjähriger Mitglieder sind Kontakte am
  Geschäftspartner des Mitglieds in der neuen Kontakt-Kategorie
  „Erziehungsberechtigt“.
- Einrichtungsreiter *Mitglieder und Partner* und das Recht *Mitglieder und
  Geschäftspartner verknüpfen und abgleichen*.
- Das Protokoll des Moduls (`llx_vereine_log`), das nur ergänzt wird.
- Die Übersicht meldet offene Punkte zwischen Mitgliedern und Geschäftspartnern.

### Geändert

- Das Modul braucht jetzt Dolibarrs Module Geschäftspartner und Kategorien; sie
  werden mit Vereine aktiviert.
- Die Laufzeit-Tests aktualisieren eine Installation des vorigen Releases auf
  das neue Paket in Dolibarr 22, 23 und 24.

### Update

Neues ZIP bereitstellen, dann das Modul in der Modulliste einmal deaktivieren und
aktivieren: Das legt die Protokolltabelle, das neue Recht und die Kategorien an.
Die Vereinsdaten bleiben. Bestehende Mitglieder werden auf der Abgleichsseite
verknüpft.

## [0.1.0-beta] - 2026-09-16

Erste Vorabversion: das Fundament, auf dem jede spätere Version aufbaut.

### Neu

- Länderprofil Österreich, und Deutschland als Vorschau (seit 0.5.8 entfernt).
  Eine neue Installation beginnt mit dem Profil des Landes des Unternehmens.
- Einrichtungsseite für die Vereinsdaten, die Dolibarr nicht kennt: ZVR-Zahl oder
  VR-Registernummer mit Registergericht, Vereinsbehörde, Gründungsdatum,
  Gemeinnützigkeit und Vereinszweck. Eingaben werden geprüft; ein geändertes
  Länderprofil leert eine nicht mehr passende Registernummer.
- Übersicht unter *Mitglieder > Verein* mit den Vereinsdaten und Prüfungen auf
  fehlende Unternehmensdaten, ein nicht passendes Land, eine fehlende
  Registernummer und eine abgeschaltete REST-API.
- REST-API-Schnittstellen `GET /vereine/organization` und `GET /vereine/status`,
  die nur Benutzern mit dem neuen Recht *Vereinsübersicht und Vereinsdaten lesen*
  antworten.
- Englische und deutsche Übersetzungen.
- Lokale Prüfungen und GitHub-Workflows: PHP 7.4 bis 8.4, Dolibarrs Code-Standard,
  der API-Vertrag mit Dolibarr 22/23/24, ein reproduzierbares Paket und
  Laufzeit-Tests, die das Paket über *Externes Modul bereitstellen* in echte
  Installationen von Dolibarr 22, 23 und 24 einspielen.
- Release-Werkzeuge: Pakete werden lokal gebaut und veröffentlicht und von GitHub
  gegen den getaggten Commit erneut geprüft.

[Unreleased]: https://github.com/Tabsi1998/dolibarr-vereine/compare/v0.5.7-beta...HEAD
[0.5.7-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.7-beta
[0.5.6-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.6-beta
[0.5.5-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.5-beta
[0.5.4-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.4-beta
[0.5.3-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.3-beta
[0.5.2-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.2-beta
[0.5.1-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.1-beta
[0.5.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.0-beta
[0.4.4-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.4.4-beta
[0.4.3-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.4.3-beta
[0.4.2-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.4.2-beta
[0.4.1-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.4.1-beta
[0.4.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.4.0-beta
[0.3.11-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.11-beta
[0.3.10-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.10-beta
[0.3.9-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.9-beta
[0.3.8-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.8-beta
[0.3.7-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.7-beta
[0.3.6-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.6-beta
[0.3.5-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.5-beta
[0.3.4-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.4-beta
[0.3.3-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.3-beta
[0.3.2-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.2-beta
[0.3.1-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.1-beta
[0.3.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.3.0-beta
[0.2.8-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.8-beta
[0.2.7-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.7-beta
[0.2.6-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.6-beta
[0.2.5-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.5-beta
[0.2.4-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.4-beta
[0.2.3-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.3-beta
[0.2.2-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.2-beta
[0.2.1-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.1-beta
[0.2.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.2.0-beta
[0.1.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.1.0-beta
