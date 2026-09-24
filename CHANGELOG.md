# Changelog

Alle nennenswerten Änderungen am Modul Vereine. Das Format folgt
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/); Versionen folgen
[Semantic Versioning](https://semver.org/lang/de/), `-beta` kennzeichnet
Vorabversionen. Der Abschnitt einer Version ist der Text ihres GitHub-Releases.

## [Unreleased]

### Neu

- **Webportal: mehr für Mitglieder** (#257): „Mein Verein“ im Webportal von Dolibarr zeigt jetzt auch
  **Einwilligungen** (lesen, einwilligen, widerrufen – mit Nachweis „Webportal“), **Meine Daten**
  (Kontaktdaten ändern – sofort oder über den Vorstand, wie eingestellt –, frühere Anträge mit Stand und
  den **Austritt** erklären) und **Veranstaltungen und Helferdienste** (mithelfen, unbestätigte Anfrage
  zurückziehen). Jeder Bereich ist unter *Externe Identitäten > Webportal* einzeln einschaltbar und nutzt
  dieselben Dienste wie eine App.
- **Webportal: Konten, Statuten, Anträge** (#258): **Meine Konten** (Discord, Twitch, YouTube & Co. eintragen,
  ändern, entfernen – bestätigen kann weiterhin nur eine App), die veröffentlichten **Statuten** als PDF
  und **Anträge zur Generalversammlung** im Bereich Sitzungen, mit der Frist der Statuten.

## [1.0.0] - 2026-09-24

Die erste stabile Version: alles, was ein österreichischer Verein über das Jahr braucht – Beiträge, Sitzungen und Generalversammlung mit Abstimmungen, Funktionen und Meldungen an die Behörde, Rechnungslegung, Spendenmeldung, Freiwilligenpauschale, Vereinsakte und Datenschutz – mit einer stabilen API (Version 2) für Website, App und das Webportal von Dolibarr, und einem bebilderten Handbuch.

Nach dem Update ist nichts zu tun. Wer eine eigene Website anbindet und `country_profile`, `country_profile_complete` oder `register.court` liest: diese Felder gibt es nicht mehr.

### Geändert

- **API-Version 2** (#252): `GET /vereine/status` und `GET /vereine/organization` liefern die seit
  0.5 veralteten Felder `country_profile`, `country_profile_complete` und `register.court` nicht mehr –
  das Modul ist nur für österreichische Vereine. `api_version` ist jetzt `2`. Wer eine eigene Website
  anbindet: Liest sie eines dieser Felder, bitte streichen; alle anderen Felder bleiben gleich.

## [0.11.0-beta] - 2026-09-24

Mitglieder und ihr Verein, auch unterwegs: Ehrungen, Jubiläen und Mitgliederstatistik, Geräte und Ausleihe, veröffentlichte Dokumente und Statuten, Sitzungen mit Zu-/Absage und Anträgen, eigene Daten und Austritt, Veranstaltungen mit Helferdiensten, Abstimmungen in der Generalversammlung über App oder Stimmzettel mit Nachweis – über die API und im Webportal von Dolibarr. Dazu 0 % mit Begründung für E-Rechnungen.

Nach dem Update das Modul einmal aus- und wieder einschalten: neue Tabellen, Menüpunkte und Webportal-Anbindung.

### Neu

- **Ehrungen und Jubiläen** (#27): *Mitglieder > Verein > Ehrungen und Jubiläen* zeigt je Jahr, wer ein
  Jubiläum der Mitgliedschaft erreicht (Jahre wählt der Verein, ab Werk 10, 20, 25, 30, 40, 50) und wer
  Geburtstag hat, runde Geburtstage hervorgehoben. Geburtstage stehen nur da, wenn das Mitglied der
  gewählten Einwilligung zugestimmt hat. Ehrungen hältst du fest: Jubiläum, Auszeichnung (etwa ein
  Turniersieg) oder Ehrenmitgliedschaft. Ein Ehrenmitglied wechselt in die Mitgliedsart, die der Verein
  dafür wählt, etwa eine ohne Beitrag. Zu jeder Ehrung gibt es eine **Urkunde als PDF** mit Linien für
  die Unterschriften; der Reiter *Verein* des Mitglieds nennt seine Ehrungen.
- **Webportal: „Mein Verein“** (#25): Ab Dolibarr 23 zeigt Dolibarrs eigenes Webportal angemeldeten
  Mitgliedern die Seite „Mein Verein“ mit Dokumenten, Sitzungen (mit Zu-/Absage) und Abstimmungen – je
  nachdem, was der Verein unter *Einrichtung > Identitäten > Webportal* einschaltet. Die Seite nutzt dieselben
  Dienste wie die API: dieselben Dokumente, dieselben Stimmrechte; eine über eine App abgegebene Stimme
  zählt im Portal nicht nochmal. Das Portal bekommt keine Verwaltungsrechte und keinen API-Schlüssel.
  Dolibarr 22 bietet dafür keinen Erweiterungspunkt; dort sagt die Einrichtung das und bietet nichts an.
- **0 % mit Begründung für E-Rechnungen** (#45): Unter *Steuerprofile* legt „Codes anlegen“ drei eigene
  0-%-Codes im Umsatzsteuer-Wörterbuch an: `AT-NS` nicht steuerbar, `AT-KU` Kleinunternehmer, `AT-SP`
  Sportverein. Ab Dolibarr 24 trägt `AT-NS` den EU-Befreiungsgrund `VATEX-EU-O`; für Kleinunternehmer und
  Sportvereine hat die offizielle Liste keinen Code – dort bleibt der Rechnungshinweis des Profils der Grund.
  Produkte mit einem solchen Steuerprofil übernehmen den Code (ohne Preisänderung), neue Rechnungszeilen
  ebenso. So können E-Rechnungs-Module die Zeilen richtig kennzeichnen.
- **Auswertung von Abstimmungen** (#163): Nach dem Schließen wertet die Versammlungsleitung aus. Die
  Auswertung hält Frage, Regeln, Stimmrechte, Stimmen je Antwort, die Beschlussfähigkeit beim Öffnen und
  das Ergebnis fest und erzeugt einen **Nachweis als PDF/A** mit Code und Prüfsumme in der Vereinsakte – ohne
  die Stimme einer Person. Die Auswertung ist vorläufig; erst **„Ergebnis bestätigen“** trägt sie ins
  Beschlussbuch ein und löst die Folgen aus (Funktionsperiode, neue Statutenfassung, Meldung an die Behörde),
  genau einmal, auch bei doppeltem Klick. Neu auswerten geht vor der Bestätigung mit Grund; die frühere
  Auswertung bleibt mit ihrem Nachweis. Mitglieder sehen das Ergebnis in der App erst nach der Bestätigung.
- **Abstimmungen in der App** (#160, #161): Zu einem Tagesordnungspunkt einer Generalversammlung legt der
  Vorstand unter *Sitzung > Abstimmungen in der App* eine Abstimmung an – Beschluss, Statutenänderung,
  Auflösung oder Wahl mit Kandidat:innen (mit deren Zustimmung). **Freigeben** hält Mehrheit,
  Vollmachtsregel und Statutenfassung fest; **Öffnen** am Versammlungstag hält die Stimmrechte fest:
  eingeladen und stimmberechtigt, Mitglied am Versammlungstag, Vollmachten aus der Anwesenheitsliste.
  Ein offener Beitrag nimmt kein Stimmrecht. Mitglieder stimmen über ihre App ab (`GET /vereine/me/ballots`,
  `POST /vereine/me/ballots/{id}/votes`, Fähigkeit *votes*), aber nur, solange sie laut Anwesenheitsliste
  in der Versammlung sind; wer eine Vollmacht hält, stimmt auch für die vertretene Person. Der Vorstand kann
  Stimmzettel eintragen. **Jedes Stimmrecht zählt genau einmal** – egal über welche App oder auf Papier;
  dieselbe Anfrage nach einem Verbindungsabbruch zählt nicht doppelt. Nach dem Schließen zeigt die Seite
  die Stimmen. Auswertung mit Nachweis-PDF (#163) und geheime Wahlen (#162) folgen.
- **Dokumente, zweiter Teil** (#239): In der Vereinsakte lässt sich je Dokument eine **gekürzte
  Fassung** hochladen, etwa ein Protokoll ohne Personalangelegenheiten für die Mitglieder. Sie ist eine
  eigene Datei mit eigener Prüfsumme und zeigt – auch bei der öffentlichen Echtheitsprüfung –, aus
  welcher Fassung sie abgeleitet ist; das Original bleibt unverändert. Ein Dokument kann **nur für eine
  Person** veröffentlicht werden (etwa eine persönliche Bestätigung); die App sieht es nur für diese
  Person, und es steht in ihrer Datenauskunft. Wer mehrere Veröffentlichungen eines Dokuments sehen
  darf, bekommt die der engsten Zielgruppe: der Vorstand das Original, Mitglieder die gekürzte Fassung.
  Veröffentlichen, Ersetzen und Zurückziehen meldet der Änderungsfeed als `document`.
- Eine **mit ID Austria unterschriebene Fassung** geht erst dann automatisch hinaus, wenn alle
  Unterschriften da sind, die der Unterschriftslauf verlangt; bisher schon nach der ersten (#239, #151).
- **Veranstaltungen in der App und auf der Website** (#165): Eine Veranstaltung ist jetzt intern, **nur für
  Mitglieder** oder öffentlich. `GET /vereine/events` liefert der Website die öffentlichen, mit der
  einen Stelle, bei der man sich anmeldet (keine, Dolibarr oder eine genannte externe Anwendung); nie
  Teilnehmer, Aufgaben oder Geld. Eine App mit der Fähigkeit *events* sieht dazu die Veranstaltungen für
  Mitglieder samt Helferdiensten, fragt einen Dienst an (der Vorstand bestätigt in Dolibarr) und zieht ihn
  zurück, solange er nicht bestätigt ist. Überschneidende Dienste werden abgewiesen.
- Wer bei einem Helferdienst schon einmal abgesagt war, kann wieder eingetragen werden; bisher scheiterte
  das an einem zweiten Eintrag für dieselbe Person (#165).
- **Eigene Daten in der App** (#164): Mit der Fähigkeit *profile* liest eine App die Daten der Person,
  beantragt Änderungen der Kontaktdaten (nur Anschrift, Telefon, E-Mail – nie Mitgliedsart, Status,
  Funktionen oder Bankdaten) und erklärt den Austritt. Unter *Externe Identitäten* wählt der Verein,
  was sofort übernommen wird; alles andere, eine neue E-Mail-Adresse immer, entscheidet der Vorstand im
  Reiter *Verein* mit einer Begründung für das Mitglied und einer Notiz nur für sich. Wer auf einem
  veralteten Stand ändert, bekommt einen Konflikt statt eines stillen Überschreibens. Der Austritt endet
  am Tag der Kündigungsregel; die App bekommt diesen Tag zurück.
- **Sitzungen in der App** (#159): Eine App mit der neuen Fähigkeit *meetings* zeigt der Person jede
  Sitzung, zu der sie eingeladen ist – nie eine Vorstandssitzung nur wegen der Mitgliedschaft –, mit
  Tagesordnung, Ort oder Zugang, Stimmrecht und Antragsfrist. Die Person sagt zu oder ab (keine
  Anwesenheit, keine Stimme) und stellt Anträge zur Tagesordnung einer Generalversammlung: genau einmal,
  nach der Frist der Statuten als verspätet gekennzeichnet. Auf der Sitzung in Dolibarr siehst du die
  Rückmeldungen und nimmst Anträge an (dann stehen sie auf der Tagesordnung) oder lehnst sie ab.
- **Statuten über die API** (#158): Unter *Einrichtung > Statuten* gibst du die Statuten für Mitglieder
  oder die Öffentlichkeit frei (Standard: niemand). `GET /vereine/statutes` (Website) und
  `GET /vereine/me/statutes` (App) nennen jede beschlossene Fassung mit ihrem Stand am Stichtag
  (gilt, kommt noch, aufgehoben) und die geltende, samt PDF, geprüft gegen die Prüfsumme. Beginnen zwei
  Fassungen am selben Tag, sagt die Antwort das, statt eine zu erraten; der Entwurf ist nie dabei.
- **Dokumente veröffentlichen** (#156, #157): In der Vereinsakte gibst du ein fertiges Dokument frei
  für den **Vorstand**, die **Mitglieder** oder die **Öffentlichkeit** (nur Administratoren). Je
  Dokumentart kannst du festlegen, dass die unterschriebene Fassung von selbst hinausgeht, etwa das
  Protokoll der Generalversammlung für Mitglieder; Entwürfe nie. Ab Werk ist nichts veröffentlicht.
  Dieselbe Fassung zweimal ergibt eine Veröffentlichung, eine neuere ersetzt die ältere, Zurückziehen
  wirkt sofort, und die Datei bleibt in der Vereinsakte. Über die API holt eine App mit der Fähigkeit
  *documents* die Dokumente der Person (`me/documents`), die Website die öffentlichen (`documents`),
  jeweils als PDF, unverändert samt Unterschriften und geprüft gegen die Prüfsumme der Vereinsakte.
- **Inventar und Ausleihe** (#26): Die Geräte des Vereins (PCs, Konsolen, Headsets, Zelte, Kassen) sind
  Dolibarrs Ressourcen. *Mitglieder > Verein > Inventar und Ausleihe* zeigt, was gerade wer hat, gibt
  ein Gerät mit Rückgabetag und Zustand aus und nimmt es mit Zustand zurück. Ein verliehenes Gerät lässt
  sich nicht ein zweites Mal ausgeben. Ist etwas überfällig, erinnert ein geplanter Auftrag die Person
  einmal pro Woche per E-Mail, und zwar nur sie. *Zu erledigen* zählt überfällige Geräte ohne Namen.
  Reservierungen für Veranstaltungen macht Dolibarr am Termin selbst; die Liste zeigt die nächste.
  Auskunft und Löschen nach dem Austritt berücksichtigen die Ausleihen.
- **Mitgliederstatistik** (#28): *Mitglieder > Verein > Mitgliederstatistik* zählt die Mitglieder an
  einem Stichtag nach Mitgliedsart, Geschlecht, Altersgruppe und Sparte (Kategorien der Mitglieder),
  ohne Namen. Als CSV-Datei für Meldungen an Dach- und Fachverbände; die Altersgrenzen stellt der Verein ein.

## [0.10.0-beta] - 2026-09-24

Vereinsakte, Datenschutz und Anbindungen: fertige Dokumente als PDF/A mit Kennung und öffentlicher Echtheitsprüfung, Auskunft nach Art. 15 DSGVO und Löschen nach dem Austritt mit Fristen je Art der Daten, Beitragsrückstände aus dem Mahnwesen als Vorschlag für den Vorstand, Kanäle des Vereins und Konten der Mitglieder bei Discord, Twitch, YouTube & Co., eigene Felder im Antrag, geführte Einrichtung und ein Handbuch.

Nach dem Update das Modul einmal aus- und wieder einschalten: neue Tabellen und Reiter.

### Neu

- **Erste Schritte** (#126): Die Einrichtung beginnt mit einem neuen Reiter, der einen Verein in
  neun Schritten einrichtet – Vereinsdaten, Dolibarr-Module (Pflicht und wahlweise, jeweils mit
  Zweck), Statuten, Funktionen und Vorstand, Mitgliedsarten und Beiträge, Einwilligungen,
  E-Mail-Versand mit Testnachricht, Sitzungen und Unterschriften, Website und API. Jeder Schritt
  führt zur Seite, auf der die Einstellung schon wohnt, und gilt erst als erledigt, wenn die Daten
  da sind. Was ein Verein nicht braucht, lässt er aus. Übersicht und Einrichtung zeigen, wie weit
  die Einrichtung ist, bis alles erledigt ist oder der Hinweis ausgeblendet wird.
- **Eigene Felder im Antrag dynamisch** (#226): Unter *Einrichtung – Mitgliedsantrag* legst du
  beliebig viele eigene Felder direkt an – Text, langer Text, Zahl, Datum, Ja/Nein, Auswahl oder
  Mehrfachauswahl mit ihren Möglichkeiten, gleich als freiwillig oder Pflicht auf dem Antrag. Jedes
  wird ein normales Zusatzfeld des Mitglieds in Dolibarr. Die API sagt einer Website zu jedem Feld
  Art, Optionen und Länge, sodass sie es ohne feste Programmierung zeigen kann, und prüft beim
  Absenden nach Art. Im PDF steht jedes Feld passend: Linie, Datum, Kästchen, eine Auswahl mit einem
  Kästchen je Möglichkeit.
- **Vereinsakte, PDF/A und Echtheitsprüfung** (#123): Fertige Dokumente – Protokolle, Beschlüsse,
  Prüfberichte, Einnahmen-Ausgaben-Rechnungen, Auszahlungslisten – entstehen als **PDF/A**, damit
  sie in zwanzig Jahren noch lesbar sind, und tragen unten auf jeder Seite eine **Kennung mit
  QR-Code**. Jede Fassung (wie erstellt, mit ID Austria unterschrieben, unterschriebenes Papier als
  Scan) wird mit ihrer Prüfsumme festgehalten. Eine **öffentliche Prüfseite** sagt zu einer
  Kennung, ob es das Dokument gibt, welcher Art es ist, von wann, wer unterschrieben hat und welche
  Prüfsummen es hat – ohne Titel und Inhalt – und vergleicht auf Wunsch eine mitgebrachte Datei. Der
  Verein kann sie abschalten. Unter *Mitglieder > Verein > Vereinsakte* gibt es alle fertigen
  Dokumente eines Zeitraums, dazu Statuten und Schreiben an die Behörde, als **ZIP mit
  Inhaltsverzeichnis und Prüfsummen**, etwa bei einem Vorstandswechsel.
- **Auskunft über gespeicherte Daten** (#10, erster Teil): Auf dem Reiter *Verein* der
  Mitgliedskarte hältst du eine Anfrage nach Art. 15 DSGVO fest – wann sie kam und wie du geprüft
  hast, dass sie von der Person selbst kommt – und bekommst die Kopie ihrer Daten als ZIP mit PDF
  zum Lesen und JSON zum Mitnehmen: Stammdaten, eigene Felder, Beiträge, Rechnungen, Einwilligungen,
  Anträge, Funktionen, Austritt, App-Verbindungen, Sitzungen, eigene Stimmen, Unterschriften,
  Aufgaben, Helferdienste, Freiwilligenpauschale, Spenden und die Vorgänge des Moduls. Stimmen und
  Einträge anderer stehen nicht darin. Die Kopie wird nicht aufbewahrt, nur Tag, Prüfung, Ausgabe
  und Prüfsumme; die Frist von einem Monat steht dabei.
- **Löschen nach dem Austritt** (#10, zweiter Teil): Auf dem Reiter *Verein* eines ehemaligen
  Mitglieds zeigt eine Liste je Art der Daten, was noch gespeichert ist und wann es fällig wird:
  Kontaktdaten und App-Verbindungen gleich nach dem Austritt, E-Mail-Adressen in Einladungen ein
  Jahr nach der Sitzung, Einwilligungen, Anträge und das Modul-Protokoll nach drei Jahren,
  Freiwilligenpauschale und Spenderdaten sieben Jahre nach dem Jahresende. Beiträge, Rechnungen
  und Vereinsunterlagen bleiben unverändert; der Name wird erst durch „Anonymisiert“ ersetzt, wenn
  nichts Aufbewahrtes ihn mehr braucht, und nie bei jemandem, der eine Funktion hatte. Gelöscht
  wird nur nach Klick und Rückfrage; offene Rechnungen oder eine Sperre mit Grund halten alles
  an. Jeder Durchgang wird mit Anzahlen festgehalten, ohne die Daten selbst. Die Fristen und ihre
  Gründe stehen unter *Einstellungen > Datenschutz*; wo der Verein wählen darf, lassen sie sich ändern.
- **Zusammenarbeit mit dem Mahnwesen-Modul** (#17): Erreicht eine Beitragsrechnung dort die letzte
  Stufe „Mitgliedschaft prüfen“, legt Vereine genau einen Vorschlag für die nächste
  Vorstandssitzung an. Beitragsrechnung heißt: Dolibarr hat sie mit einem Mitgliedsbeitrag
  verknüpft; ein Verkauf an dasselbe Mitglied zählt nicht. Beim Anlegen einer Vorstandssitzung
  hakst du den Rückstand an, dann steht er auf der Tagesordnung. Bei einer Generalversammlung
  lehnt das Modul das ab, weil deren Einladung an alle geht. Zahlung, Pause und Wiederaufnahme im
  Mahnwesen ändern den Stand, doppelte und verspätete Meldungen werden erkannt, und eine vor der
  Meldung bezahlte Rechnung kommt gar nicht erst zum Vorstand. Niemand wird automatisch
  ausgeschlossen; *Zu erledigen* zählt die Rückstände ohne Namen. Ohne Mahnwesen ändert sich nichts.
- **Kanäle und Konten** (#233): Unter *Einrichtung > Vereine > Kanäle und Konten* trägt der Verein
  seine Kanäle ein, also Discord, Twitch, YouTube und alles andere, auch mehrere je Netzwerk (etwa
  zwei Streams), in eigener Reihenfolge. Bei Twitch, YouTube und Kick baut das Modul die Adresse und
  den Link zum Livestream selbst. Die öffentlichen Kanäle stehen in der Übersicht und in
  `GET /vereine/organization`. Für Mitglieder wählt der Verein, welche Konten der Antrag (Web und PDF)
  abfragt, optional oder als Pflicht. Die Netzwerke kommen aus Dolibarrs Wörterbuch; was fehlt, etwa
  Steam oder eine Riot ID, legt er dazu an. Die Namen landen auf der Mitgliedskarte. Eine angebundene
  App verknüpft Konten über `me/accounts` und bestätigt sie nach dem Login beim Netzwerk. Der Reiter
  *Verein* zeigt „bestätigt“, bis jemand den Namen ändert. Auskunft und Löschen berücksichtigen die
  Konten.
- **Handbuch** (#10): [docs/HANDBUCH.md](docs/HANDBUCH.md) erklärt, wer im Verein welche Rechte
  braucht, wie das Vereinsjahr im Modul abläuft, und bringt einen Textbaustein für das Verzeichnis der
  Verarbeitungstätigkeiten, dazu Auskunft, Löschen, externe Anwendungen und was nach einer
  Wiederherstellung aus der Sicherung zu tun ist.

## [0.9.0-beta] - 2026-09-23

Spenden & Ehrenamt: Freiwilligenpauschale und PRAE mit Grenzen, Jahresliste und Auszahlung über
Dolibarr nach den Unterschriften, Überzahlungen auf Rechnungen sauber als Guthaben, Rückzahlung
oder Spende zugeordnet, und die Spendenmeldung ans Finanzamt mit vbPK und XML nach dem Schema des
Finanzministeriums. Dazu ein Mitgliedsantrag, der aussieht wie ein Formular (#6, #7, #54, #202).

**Nach dem Update** das Modul unter *Einrichtung > Module* einmal aus- und wieder einschalten,
damit die neuen Tabellen, Menüpunkte und die Berechtigung *Spendenmeldung vorbereiten* angelegt
werden. Für Spenden aus Überzahlungen und für die Spendenmeldung muss das Dolibarr-Modul
*Spenden* eingeschaltet sein.

### Neu

- **Freiwilligenpauschale und PRAE** (#7): Ein neuer Menüpunkt *Mitglieder > Verein >
  Freiwilligenpauschale*. Kassier und Obmann erfassen Einsätze mit Tag, Tätigkeit, Art (kleine
  oder große Pauschale, PRAE) und Betrag.
- **Überschreitungen fallen sofort auf**: Liegt ein Einsatz über der Grenze des Tages, des Monats
  (PRAE) oder des Kalenderjahres, sagt das Modul es beim Speichern – nicht erst im Februar. Auch
  PRAE und Pauschale für dieselbe Person im selben Jahr werden als „bitte prüfen" markiert.
  Verboten wird nichts, denn was darüber liegt, ist nicht verboten, nur nicht mehr steuerfrei.
- **Grenzen als datierte Tabelle** mit Rechtsgrundlage (§ 3 Abs. 1 Z 42 und Z 16c EStG, Stand
  2024): ändert sich das Gesetz, kommt eine Zeile dazu, und frühere Jahre behalten ihre Zahlen.
- **Helferdienste werden übernommen**: Bestätigte Einsätze der Veranstaltungen („war da") stehen
  zum Erfassen bereit – ein Klick mit dem Betrag, und jeder Helferdienst lässt sich nur einmal
  abrechnen.
- **Jahresliste je Person** mit Einsatztagen, Summen je Art und Hinweisen, als CSV für die
  Meldungen ans Finanzamt. Abschicken bleibt Handarbeit.
- **Auszahlen mit Unterschriften** (#7): Offene Einsätze kommen auf eine Auszahlungsliste als PDF.
  Die wird unterschrieben wie eine Geldangelegenheit – ab Werk von Obmann/Obfrau und Kassier:in,
  einstellbar als eigene Dokumentart *Auszahlung Freiwilligenpauschale* unter *Einrichtung –
  Unterschriften*. Vorher geht kein Cent hinaus. Danach legt ein Klick die Zahlungen als **sonstige Zahlungen in
  Dolibarr** an, eine je Person, vom gewählten Bankkonto. Bank, Abgleich und
  Einnahmen-Ausgaben-Rechnung sehen sie wie jede andere Zahlung. Eine Liste, die nach dem
  Unterschreiben geändert wurde, lässt sich nicht auszahlen; eine noch nicht ausbezahlte Liste
  lässt sich zurücknehmen. Ausbezahlte Einsätze bleiben – sie gehören zur Buchhaltung.
- **Überzahlungen zuordnen** (#54): Neuer Menüpunkt *Mitglieder > Verein > Überzahlungen*. Hat
  jemand 38,00 € auf eine Rechnung über 37,68 € überwiesen, zeigt das Modul die 0,32 € auf der
  Rechnung und in einer Liste (Filter nach Jahr, Rechnung oder Geschäftspartner). Du wählst mit
  Vorschau: **Guthaben** (Dolibarrs eigenes Guthaben, wie der Knopf auf der Rechnung),
  **Rückzahlung** (sonstige Zahlung vom Bankkonto) oder – nur nach der Bestätigung, dass er
  freiwillig und ohne Gegenleistung gegeben wurde – **Spende** über genau den Mehrbetrag im
  Spendenmodul, mit der Rechnung verknüpft. Die Rechnung bleibt unverändert und gilt danach als
  bezahlt. Derselbe Mehrbetrag lässt sich nicht zweimal zuordnen, auch nicht über Dolibarrs
  eigenen Knopf. Die Einnahmen-Ausgaben-Rechnung zählt einen gespendeten Mehrbetrag zum ideellen
  Bereich; die Vereinsübersicht meldet offene Überzahlungen.
- **Spendenmeldung an das Finanzamt** (#6): Neuer Menüpunkt *Mitglieder > Verein > Spendenmeldung*
  mit eigener Berechtigung, weil dort Geburtsdaten stehen. Die Spenden bleiben im Spendenmodul
  von Dolibarr; das Modul fasst sie je Person und Jahr zusammen. **Geburtsdatum** wird
  verschlüsselt gespeichert (ohne Dolibarr-Schlüssel gar nicht). Die **vbPK** kommt über die
  Abgleichliste für das Stammzahlenregister (herunterladen, in FinanzOnline hochladen, Ergebnis
  als ZIP einlesen) oder händisch. Die **Meldung als XML** enthält nur, was das Finanzamt noch
  nicht hat – Erstmeldung, Änderung mit neuer Jahressumme oder Storno – und wird vor dem
  Herunterladen gegen das Schema des Finanzministeriums geprüft. Das **Protokoll aus der
  DataBox** (OK, teilweise OK, abgelehnt) wird eingelesen; ein Testprotokoll zählt nicht als
  gemeldet, abgelehnte Zeilen stehen mit Grund da und kommen in die nächste Meldung. Für Firmen
  gibt es eine **Spendenbestätigung** als PDF. Einstellungen unter *Einrichtung – Spendenmeldung*:
  Art der Einrichtung (z. B. SP für Sport, GM für andere gemeinnützige Vereine), Steuernummer,
  Kontakt für das Stammzahlenregister. Hochladen in FinanzOnline bleibt ein Klick bei euch.


### Geändert

- **Mitgliedsantrag und Einwilligungserklärung sehen aus wie Formulare** (#202): Jedes Feld hat
  eine Schreiblinie, auch im ausfüllbaren PDF – gedruckt sieht das Blatt aus wie am Bildschirm.
  Ja/Nein sind zwei gezeichnete Kästchen, die Statuten nur ein Kästchen zum Anhaken (ein „Nein“
  gibt es beim Antrag nicht). Die Fassung einer Einwilligung steht klein daneben statt „(v2)“ im
  Titel. Angaben zur Person stehen in zwei Spalten, bekannte Werte fett auf der Linie. Der
  Austritt ist ein Satz („Austritt: schriftlich zum Monatsende, die Kündigungsfrist beträgt
  1 Monat.“), die Bankverbindung steht beim Beitrag. Untertitel und Fußzeile, die den Seitenkopf
  wiederholt haben, entfallen; der Seitenkopf aller PDFs zeigt dafür Telefon und Website.
  Überschriften stehen nie mehr allein am Seitenende, der Unterschriftenblock bleibt beisammen.

### Behoben

- **„Was wartet auf mich“ führt zur richtigen Seite**: Unterschriften für die
  Einnahmen-Ausgaben-Rechnung und den Prüfbericht verlinkten bisher auf die Sitzungen.

## [0.8.0-beta] - 2026-09-23

Veranstaltungen & Aufgaben: Fristenkalender des Vereins, Veranstaltungen aus Vorlagen mit
Helferdiensten, die Generalversammlung Schritt für Schritt – und die Grundlage für Apps und
Websites: Änderungsfeed, signierte Webhooks und persönlicher Zugriff über verifizierte
Identitäten. Dazu ein Mitgliedsantrag, der für PDF und Web dieselben Felder verlangt
(#23, #24, #127, #153, #154, #155, #216).

**Nach dem Update** das Modul unter *Einrichtung > Module* einmal aus- und wieder einschalten,
damit die neuen Tabellen, Menüpunkte, Rechte und die geplante Aufgabe für die Webhooks
angelegt werden.

### Neu

- **Mitgliedsantrag: eine Liste für PDF und Web** (#216): Was der gedruckte Antrag als Pflicht
  markiert, verlangt jetzt auch ein Antrag über die Website – und umgekehrt. Vor- und Nachname
  sind immer Pflicht und stehen auch so im PDF; die **Anschrift (Straße, PLZ, Ort) ist ab Werk
  Pflicht**, weil das Mitgliederverzeichnis sie braucht. Eine bewusst gespeicherte Auswahl bleibt.
- **Eigene Felder auf dem Antrag**: Zusatzfelder am Mitglied, etwa ein *Gamertag*, lassen sich
  unter *Einrichtung > Vereine > Mitgliedsantrag* auf den Antrag stellen – freiwillig oder als
  Pflicht. Sie stehen im PDF, werden beim Web-Antrag geprüft und landen direkt am Mitglied. Eine
  Website fragt über `GET /vereine/applicationform`, welche Felder es gibt.
- **Der Beitrag in echten Zahlen**: Statt „Im Eintrittsjahr wird der Beitrag halbjahresweise
  anteilig verrechnet" – was sich wie „jedes halbe Jahr" las – steht jetzt z. B. „Eintritt
  1. Januar bis 30. Juni: 75,00 € · Eintritt 1. Juli bis 31. Dezember: 37,50 € · ab dem folgenden
  Beitragsjahr: 75,00 €". Gerechnet mit derselben Regel wie der Beitragslauf.
- **„1 Monat"** statt „1 Monate" in allen Kündigungstexten.
- **Persönlicher Zugriff über die API** (#153): Eine App, eine Website oder Dolibarrs Portal kann
  jetzt für **eine bestimmte Person** handeln – aber nur über eine Bindung, die der Verein
  ausdrücklich erzeugt hat. Neue Endpunkte `POST /vereine/identities/claim`,
  `GET /vereine/identities/me`, `GET /vereine/me/consents` und `GET /vereine/me/application`.
- **Eine Stelle entscheidet**: Darf die Anwendung überhaupt für Personen handeln? Gibt es eine
  Bindung bei genau dieser Anwendung und in diesem Mandanten? Ist sie nicht widerrufen, trägt
  sie die Fähigkeit, und geht es um das eigene Objekt? Fremdes wird abgewiesen, nicht umgeleitet.
- **Einladung statt Vermutung**: Unter *Einrichtung > Vereine > Externe Identitäten* erzeugt der
  Verein einen Code, der einmal gezeigt wird, eine Stunde gilt und beim ersten Einlösen verfällt.
  E-Mail-Adresse und Mitgliedsnummer liefern nur Kandidaten – eine Familie teilt sich eine
  Adresse, und eine Nummer lässt sich raten.
- **Antragsteller** werden an ihren Antrag gebunden, nicht an ein Mitglied, und bekommen dadurch
  keine Mitgliedsrechte.
- **Alle Fähigkeiten sind aus**, bis der Verein sie einschaltet. Der bisherige Dienstzugang
  (API v1) bleibt unverändert, bekommt durch das Update kein zusätzliches Recht, und seine
  Vertrauensgrenze steht jetzt ausdrücklich in `docs/API.md`.
- **Signierte Webhooks** (#155): Unter *Einrichtung > Vereine > Webhooks* trägt der Verein ein
  Ziel ein; eine geplante Aufgabe schickt jede Änderung des Feeds dorthin. **Nie während einer
  Fachtransaktion**: ein zurückgerollter Vorgang wird nie zugestellt, und ein langsamer oder
  toter Empfänger hält niemanden im Verein auf.
- **Jede Zustellung ist signiert** (HMAC-SHA-256 über Version, Zustellzeitpunkt und die Bytes
  des Body). Eine Wiederholung behält die Ereignis-ID und bekommt eine neue Signatur, damit ein
  Empfänger eine Wiederholung von einer Aufzeichnung unterscheiden kann.
- **Wiederholung mit wachsenden Pausen** (30 s bis 6 h, acht Versuche), Betriebsansicht mit
  Rückstand, letzter erfolgreicher Zustellung und maskierten Fehlern – Geheimnisse tauchen
  weder in der Ansicht noch im Protokoll auf. Ein liegengebliebener Auftrag lässt sich von Hand
  erneut anstoßen, mit derselben Ereignis-ID.
- **Schlüsselwechsel** auf Knopfdruck: der neue Schlüssel signiert sofort, der alte gilt noch
  24 Stunden, damit der Empfänger in Ruhe umstellen kann. Das Geheimnis wird genau einmal
  gezeigt.
- **Nur https**, ohne Zugangsdaten in der Adresse, ohne Weiterleitungen, mit Zertifikatsprüfung;
  Adressen im eigenen Netz nur mit ausdrücklicher Ausnahme, und der Name wird vor jedem Versuch
  neu aufgelöst. Vor jedem Versuch wird geprüft, ob der hinterlegte Benutzer den Feed überhaupt
  noch verfolgen darf.
- **Referenz-Empfänger zum Abschreiben** in `docs/beispiele/webhook-empfaenger.php`.
- **Änderungsfeed für externe Anwendungen** (#154): Zwei neue Endpunkte,
  `GET /vereine/changes` und `GET /vereine/changes/snapshot`. Eine Website oder App liest mit
  einem Cursor nach, was sich geändert hat, und holt nach einer Unterbrechung genau den
  freigegebenen Stand nach.
- **Der Feed sagt nur, dass sich etwas geändert hat, nie was**: Objektart, ID, Revision,
  Änderungsart und Zeitpunkt – keine Namen, Beträge, Rechnungsinhalte, Unterschriften oder
  Stimmen. Die Daten selbst liest der Client über die fachlichen Endpunkte, die weiterhin selbst
  prüfen, was er sehen darf.
- **Keine stillen Lücken**: Ein zurückgerollter Vorgang erzeugt keinen Eintrag, dieselbe
  Änderung behält dieselbe Kennung, und ein Cursor, der älter ist als die Aufbewahrung
  (90 Tage), führt zu `resync_required` statt zu einem lautlosen Sprung.
- **Vollabgleich mit Abschlussmarkierung**: Erst wenn `complete` auf `true` steht, hat der
  Client alles gesehen. Ein abgebrochener Abgleich ist kein Beweis für Löschungen – so räumt
  niemand versehentlich Daten weg, die es noch gibt.
- **Eigenes Recht** `vereine:sync:read`: ein Dienst, der abgleicht, ist nicht dasselbe wie ein
  Mitglied, das seine eigenen Daten liest.
- **Generalversammlung Schritt für Schritt** (#127): Ein neuer Menüpunkt *Mitglieder > Verein >
  Generalversammlung* zeigt den ganzen Ablauf einer Versammlung, rückwärts vom Termin gerechnet.
  17 Schritte in drei Phasen – vorher, in der Sitzung, nachher – jeder mit Stand und Frist.
- **Vorher**: Einnahmen-Ausgaben-Rechnung (§ 21 Abs. 1 VerG), Rechnungsprüfung (Abs. 2),
  unterschriebener Prüfbericht, fällige Wahlen aus den Funktionsperioden, vollständige
  Tagesordnung, fristgerechte Einladung laut Statuten, Frist für Anträge, Wahlnachweise.
- **Nachher**: Protokoll und Unterschriften, Beschlüsse als PDF, Meldung neuer
  vertretungsbefugter Personen binnen vier Wochen (§ 14 Abs. 2 VerG), Anzeige einer
  Statutenänderung, Benutzergruppen der neuen Funktionen, Protokoll an die Mitglieder.
- **Nichts wird doppelt geführt**: Jeder Schritt liest nur, was das Modul ohnehin weiß, und
  verlinkt auf die Seite, wo er erledigt wird. Eine zu spät versandte Einladung bleibt ein
  Mangel dieser Versammlung – das Modul redet sie nicht schön.
- Was offen ist, steht auch in **„Was ist zu tun?"** auf der Vereinsübersicht.
- **Helferdienste je Veranstaltung** (#23): Schichten mit Tag, Uhrzeit, Anzahl der Plätze und
  zuständiger Funktion. Mitglieder fragen selbst an, der Vorstand teilt ein – und nach der
  Veranstaltung wird bestätigt, wer wirklich da war, samt Stunden. **Erst das ist eine
  geleistete Stunde**; eine Anmeldung allein begründet nichts (wichtig für die
  Freiwilligenpauschale, #7).
- **Das Modul passt auf**: eine volle Schicht nimmt niemanden mehr, niemand steht zweimal auf
  derselben Schicht, und niemand wird zu zwei Schichten zur selben Zeit eingeteilt.
- **Kurzbericht als PDF**: Checkliste mit Haken, Helferschichten mit bestätigten Stunden und
  das, was auf das Projekt verrechnet wurde – mit dem klaren Hinweis, dass die
  Einnahmen-Ausgaben-Rechnung Zahlungen zählt und die Beträge daher abweichen können.
- **Veranstaltungen aus Vorlagen** (#23): Ein neuer Menüpunkt *Mitglieder > Verein >
  Veranstaltungen*. Eine Vorlage ist eine Checkliste mit Phasen – Vorbereitung, Durchführung,
  Nachbereitung –, mit einer Frist je Punkt (gerechnet ab dem Veranstaltungstag) und der
  zuständigen Funktion. Mitgeliefert sind **Turnier** und **Vereinsfest**.
- **Jede Veranstaltung wird ein Projekt in Dolibarr**, jeder Punkt der Checkliste eine Aufgabe
  darin. Budget, Belege und Zeiten bleiben dort, wo Dolibarr sie ohnehin führt; das Modul legt
  keine zweite Projektverwaltung an. Abhaken setzt die Dolibarr-Aufgabe auf 100 %.
- **Österreichische Punkte sind schon drin**: Anzeige bei der Gemeinde, AKM bei Musik,
  Jugendschutz, Veranstaltungshaftpflicht, Registrierkasse bei Bareinnahmen, dazu Helferplan
  und Nachbereitung. Jeder Punkt nennt seine Quelle – als Erinnerung, nicht als Genehmigung.
- **Anmeldung nur an einer Stelle**: entweder Dolibarrs eigene Veranstaltungsorganisation oder
  eine externe Anwendung mit ihrer Kennung. So zählt keine Anmeldung doppelt.
- **Vorlagen wirken nie rückwirkend**: Wer eine Vorlage später ändert, ändert damit keine
  Veranstaltung, die schon läuft. Eigene Punkte lassen sich jederzeit ergänzen.
- **Fristen und Aufgaben des Vereins** (#24): Ein neuer Menüpunkt *Mitglieder > Verein >
  Fristen und Aufgaben* führt alle wiederkehrenden Pflichten an einer Stelle. Der Katalog
  ist beim Aktivieren schon gefüllt: Einnahmen-Ausgaben-Rechnung, Rechnungsprüfung,
  Information der Mitglieder, ordentliche Generalversammlung, Spendenmeldung,
  Freiwilligenpauschale, Jahresbeleg der Registrierkasse und die Anzeige eines
  Organwechsels – jede mit der zuständigen Funktion und der Fundstelle im Gesetz.
- **Die Fristen rechnet das Modul selbst aus**: aus dem Vereinsjahr (auch wenn es nicht im
  Jänner beginnt), aus dem Tag, an dem die Rechnung erstellt wurde, oder aus einem festen
  Kalendertag. Ende Februar ist wirklich Ende Februar, auch im Schaltjahr.
- **Termine im Dolibarr-Kalender**: Ein Klick auf *Termine anlegen* schreibt für jede Pflicht
  eine Aufgabe in Dolibarrs Agenda – bei der Person, die die Funktion heute innehat, mit
  Dolibarrs eigener E-Mail-Erinnerung. Ein zweiter Klick legt nichts doppelt an.
- **Übergabe nach einem Funktionswechsel**: Wechselt die Kassierin, bleiben ihre offenen
  Aufgaben stehen, bis jemand die Übergabe bestätigt. Erst dann wandern Aufgabe und
  Kalendertermin zur Nachfolge – von selbst verschiebt sich nichts.
- **Eigene Pflichten**: Was nicht im Gesetz steht, aber jedes Jahr ansteht – etwa der Bericht
  an die Sponsoren –, lässt sich aufnehmen, samt Wiederholung (auch alle paar Jahre) und
  Vorlaufzeit für die Erinnerung. Pflichten aus dem Gesetz lassen sich abschalten, aber
  nicht löschen.
- **„Was ist zu tun?“** (#124): Die Vereinsübersicht und die Startseiten-Box zeigen jetzt
  auf einen Blick, was offen ist – Dokumente, die du unterschreiben musst, offene
  Umlaufbeschlüsse und Aufgaben, dazu die Fristen des Vereins: Behördenmeldung nach einer
  Bestellung, Einnahmen-Ausgaben-Rechnung, Rechnungsprüfung, nicht besetzte Funktionen,
  abgelaufene Funktionsperioden und Beitrittsanträge, die auf eine Entscheidung warten.
  Überfälliges steht oben und rot, jeder Punkt führt direkt zur richtigen Stelle.

## [0.7.1-beta] - 2026-09-23

Mitgliedsantrag und Einwilligungserklärung: aufgeräumtes Layout, ein Ja/Nein statt zwei, und
alles kommt aus Dolibarr und den Statuten (#203, #204, #206).

### Neu

- **Der Antrag holt sich noch mehr selbst** (#206): Einleitung und Datenschutz-Text verstehen
  jetzt dieselben Platzhalter wie die E-Mail-Vorlagen (`__VEREINE_NAME__`,
  `__VEREINE_ADRESSE__`, `__VEREINE_EMAIL__` …) – Stammdaten ändern, fertig.
- **Ermäßigungen stehen auf dem Antrag**: was im Beitragsmodell eingerichtet ist, etwa
  „Ermäßigung „Jugend“ (bis 18 Jahre): 50 %“, dazu die Familienermäßigung.
- **SEPA-Abschnitt auf dem Antrag**: Ist Dolibarrs Lastschrift aktiv, gibt es Felder für
  Kontoinhaber:in und IBAN, den Ermächtigungstext mit Gläubiger-ID und eine eigene
  Unterschriftszeile – so geht das Mandat auch auf Papier.
- **Einwilligungserklärung nennt den Kontakt** für den Widerruf (E-Mail oder Anschrift des
  Vereins) statt nur „formlos beim Verein“.

### Neu

- **Der Mitgliedsantrag holt sich mehr aus Dolibarr** (#204): Was die Mitgliedschaft umfasst
  (etwa Trikot, Trainingszeiten, Startgelder), steht an der Mitgliedsart in Dolibarr und
  erscheint als „Enthalten in der Mitgliedschaft“. Dazu ein Abschnitt „Aus den Statuten“ mit
  Vereinszweck, den Pflichten der Mitglieder im Wortlaut der Statuten und der geltenden
  Fassung („Es gilt die Fassung 3 der Statuten, gültig ab …“). Nichts davon muss doppelt
  gepflegt werden.

### Behoben

- **Mitgliedsantrag und Einwilligungserklärung sehen besser aus** (#203): Bei ausfüllbaren
  Formularen stand je Einwilligung zweimal Ja/Nein – einmal in Klammern, einmal als
  Ankreuzfeld. Jetzt gibt es genau eines: Klammern beim reinen Drucken, Ankreuzfelder beim
  Ausfüllen am Bildschirm.
- Der Beitrag steht in klarem Deutsch da („75,00 € pro Jahr“ statt „je 1 Jahr(e)“), die
  Kündigungsfrist ist beschriftet („Kündigung: 1 Monat zum Monatsende“).
- Eine Einwilligung wird nicht mehr mitten im Text umgebrochen: Titel, Text und Ankreuzfeld
  bleiben zusammen auf einer Seite.
- Zum Ausfüllen gibt es feine graue Linien statt Unterstrich-Reihen.
- Fehlt der Datenschutz-Text, sagt die Einrichtung es deutlich und schlägt einen Text vor,
  den der Verein übernehmen oder anpassen kann.

## [0.7.0-beta] - 2026-09-23

Mitglieder: Antrag, Einwilligungen und eigene Dokumente. Der Meilenstein v0.7 ist komplett –
Mitgliedsantrag und Einwilligungserklärung als PDF, Nachweis je Einwilligung, Beitrittsanträge
mit Stand, Einwilligungen und Antragsstand über die API, eigene ODT-Vorlagen und das
SEPA-Mandat am Mitglied.

### Neu

- **SEPA-Mandat am Mitglied** (#125): Der Reiter *Verein* zeigt jetzt, wie der Beitrag
  eingezogen wird – Mandat gültig, abgelaufen oder keines –, dazu Mandatsreferenz, Tag der
  Unterschrift und das online unterschriebene Mandat, wenn es eines gibt.
- **Online unterschreiben über Dolibarr**: Ist Dolibarrs Online-Unterschrift für Bankkonten
  eingeschaltet, steht der Link zu Dolibarrs eigener Unterschriftsseite am Mitglied, und ein
  Klick schickt ihn dem Zahler per E-Mail (mit Vermerk im Protokoll). Das Modul baut keine
  eigene Unterschriftsseite und behauptet nichts: Der Stand kommt immer aus Dolibarrs Daten,
  nie aus einer Rückkehr im Browser.
- Die API nennt in der Mitglieds-Zusammenfassung den Stand des Mandats (`fee.mandate`) –
  ohne IBAN und ohne Bankdaten. Der Beitragslauf fordert wie bisher nur mit gültigem Mandat
  eine Lastschrift an.

### Neu

- **Beitrittsanträge mit Stand** (#72): Neuer Menüpunkt *Mitglieder – Verein –
  Beitrittsanträge*. Jeder Antrag hat einen Stand – eingegangen, in Prüfung, aufgenommen,
  abgelehnt, zurückgezogen. Aufgenommen oder abgelehnt wird **nur in Dolibarr**; die
  Aufnahme macht aus dem Entwurf ein gültiges Mitglied. Bei einer Ablehnung gibt es einen
  Grund für die Person, getrennt von internen Anmerkungen. Die Seite warnt, wenn es schon
  jemanden mit gleichem Namen oder gleicher E-Mail gibt.
- Über die API: `GET /vereine/applications/{external_id}` zeigt den Stand,
  `POST /vereine/applications/{external_id}/withdraw` zieht den eigenen Antrag zurück,
  solange der Verein nicht entschieden hat. Derselbe `external_id` mit anderem Inhalt wird
  mit 409 abgelehnt statt still überschrieben.

- **Einwilligungen über die API** (#98): `GET /vereine/members/{id}/consents` zeigt je
  Zweck den Stand, die zugestimmte und die aktuelle Textversion und was jetzt möglich ist.
  `POST /vereine/members/{id}/consents` erteilt oder widerruft. Zustimmen geht nur mit der
  Version, die der Person gezeigt wurde; widerrufen geht immer, auch bei neuer Fassung
  (Art. 7 Abs. 3 DSGVO). Derselbe Auftrag nochmals geschickt wird einmal gespeichert, und
  eine Zustimmung, die älter ist als ein gespeicherter Widerruf, wird abgelehnt.
- **Eigene Dokumente über Dolibarrs ODT-Vorlagen** (#113): Das Modul liefert Platzhalter
  für eigene Vorlagen – Vereinsdaten samt Bankverbindung, Mitgliedsnummer, Mitgliedsart,
  Beitrag, Mitglied seit, Funktionen und Einwilligungen. Dazu eine Beispielvorlage
  (`docs/vorlagen/vereinsvereinbarung.odt`) und eine Anleitung Schritt für Schritt
  (`docs/ODT-VORLAGEN.md`), etwa für die Vereinbarung mit einem Vertragsspieler.
- Wissenswert aus der Anleitung: Ab Dolibarr 24 müssen ODT-Vorlagen unter
  `documents/doctemplates` liegen, sonst lehnt Dolibarr sie ab. In ODT-Vorlagen stehen die
  Platzhalter in geschweiften Klammern, in E-Mail-Vorlagen ohne.

### Neu

- **Online-Antrag als PDF am Mitglied** (#111): Kommt ein Beitrittsantrag über die Website,
  legt das Modul denselben Antrag als PDF bei den Dokumenten des Mitglieds ab – mit dem
  Vermerk „Elektronisch eingereicht am … über die Website“, den gesendeten Daten und den
  Einwilligungen. Die Prüfsumme steht im Modulprotokoll. Der Vorstand sieht damit dasselbe
  Dokument wie bei einem Antrag auf Papier.
- **Unterschrift freiwillig über die API**: Die Website darf die am Bildschirm gezeichnete
  Unterschrift als PNG mitschicken (höchstens 200 kB); sie steht dann im Antrag. Das ist
  eine einfache elektronische Signatur (Art. 25 eIDAS), keine qualifizierte – die gibt es
  nur über ID Austria. Ein zu großes oder falsches Bild wird mit 400 abgelehnt.
- Derselbe Antrag nochmals geschickt erzeugt kein zweites Dokument; scheitert nur das PDF,
  bleibt der Antrag trotzdem bestehen.

## [0.6.2-beta] - 2026-09-22

Mitgliedsantrag und Einwilligungserklärung als PDF aus Dolibarr (#108, #110, #107), Nachweis
je Einwilligung (#109) und der Verlauf am Mitglied in Dolibarrs Ereignissen (#112).

### Neu

- **Mitgliedsantrag als PDF** (#108): Unter *Mitglieder – Verein – Mitgliedsantrag* gibt
  es den leeren Antrag zum Ausdrucken, je Mitgliedsart einen. Für ein bestimmtes Mitglied
  erstellt ihn Dolibarr auf der Mitgliedskarte unter *Dokumente* mit der Vorlage
  „Mitgliedsantrag des Vereins“, schon ausgefüllt und von dort auch per E-Mail versendbar.
  Er holt sich alles aus Dolibarr: Beitrag und Beitrittsgebühr aus der Mitgliedsart, die
  Kündigungsfrist aus der Austrittsregel, die Einwilligungen in ihrer aktuellen Fassung,
  Verein, ZVR und Bankverbindung aus den Stammdaten.
- **Einwilligungserklärung drucken** (#110): Unter *Mitglieder – Verein –
  Einwilligungserklärung* wählst du die Einwilligungen und bekommst ein PDF – entweder für
  ein Mitglied (Link am Reiter *Verein* des Mitglieds) oder für alle aktiven Mitglieder,
  denen eine gewählte Einwilligung noch fehlt, mit einer Seite je Mitglied. Dazu der
  Hinweis auf den jederzeitigen Widerruf (Art. 7 Abs. 3 DSGVO) und die Unterschrift, bei
  Minderjährigen die der Erziehungsberechtigten.
- **Am Bildschirm ausfüllbare Formulare** (#107): Mitgliedsantrag und
  Einwilligungserklärung können Formularfelder zum Ausfüllen und ein leeres Feld zum
  Unterschreiben bekommen, einstellbar je Dokument im Reiter *Mitgliedsantrag*. Eine
  qualifizierte elektronische Signatur ist das nicht – die gibt es nur über ID Austria.
- **Nachweis je Einwilligung** (#109): Am Mitglied steht jetzt auch, *wie* eingewilligt
  wurde. Über die Website: Zeitpunkt der Zustimmung, Formular bzw. Seite und die Kennung
  des Vorgangs – ohne IP-Adresse. Auf Papier: die unterschriebene Erklärung als Scan
  (PDF, JPG oder PNG), abgelegt bei den Dokumenten des Mitglieds, dazu wer sie eingetragen
  hat. Damit lässt sich eine Einwilligung nachweisen (Art. 7 Abs. 1 DSGVO). Die API nimmt
  die Angaben beim Beitrittsantrag entgegen (`granted_at`, `form`, `reference`); sie stehen
  in `docs/openapi.json`.
- Neuer Reiter *Mitgliedsantrag* in der Einrichtung: Einleitung, Datenschutz-Information
  mit Link, Pflichtfelder, ausfüllbare Dokumente und das Konto für die Fußzeile.

### Geändert

- **Verlauf am Mitglied in Dolibarrs Ereignissen** (#112): Was das Vereine-Modul mit einem
  Mitglied oder Geschäftspartner tut – Einwilligung, Funktion, Beitragslauf, Austritt und
  so weiter – steht jetzt als automatisches Ereignis unter *Ereignisse* des Mitglieds bzw.
  Geschäftspartners, neben Dolibarrs eigenen Einträgen. Die eigene Liste am Reiter *Verein*
  weicht einem Link dorthin. Ohne Dolibarrs Modul Agenda bleibt die eigene Liste wie bisher.
  Frühere Einträge werden beim nächsten Aktivieren einmal als Ereignisse mit ihrem
  ursprünglichen Zeitpunkt übernommen, ohne Doppelte.

### Behoben

- **Das ZIP wird jetzt komprimiert** und ist damit rund ein Viertel so groß. Vorher wuchs
  es über 2 MB – und genau dort liegt PHPs Standardgrenze für Uploads
  (`upload_max_filesize`). Dolibarr nahm das Paket dann stillschweigend nicht an, und beim
  Update blieb die alte Fassung stehen. Der Laufzeit-Test prüft jetzt nach jedem Einspielen
  die installierte Version und sagt es deutlich, wenn ein Paket nicht angenommen wurde.

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das legt die
Menüpunkte *Mitgliedsantrag* und *Einwilligungserklärung*, die Dokumentvorlage und die
Spalten für den Nachweis an und übernimmt den bisherigen Verlauf als Ereignisse.

## [0.6.1-beta] - 2026-09-22

Einnahmen-Ausgaben-Rechnung: Bereich für sonstige Zahlungen und andere Buchungen ohne
Rechnung wählen, der Hinweis nennt den Grund (#190).

### Neu

- **Bereich für Buchungen ohne Rechnung wählen** (#190): Sonstige Zahlungen, Löhne,
  Abgaben, Spesen, Darlehen und Buchungen ohne Zahlung haben keine Rechnungszeile mit
  Steuerprofil. Auf der Seite *Einnahmen und Ausgaben* wählt der Vorstand für jede
  dieser Buchungen den Bereich. Solange nichts gewählt ist, bleibt sie „nicht
  zugeordnet“. An der Buchung in Dolibarr ändert sich nichts.

### Behoben

- Der Hinweis auf nicht zugeordnete Buchungen nennt jetzt den Grund: Rechnungszeilen
  ohne Steuerprofil, Lieferantenrechnungszeilen ohne „Ausgabe für Bereich“ oder
  Buchungen ohne Rechnung. Vorher schickte er auch bei sonstigen Zahlungen zur
  Profil-Nachpflege, wo sie sich nicht zuordnen lassen (#190).
- Konten und Buchungstexte, die Dolibarr als Übersetzungsschlüssel speichert, erscheinen
  übersetzt, etwa die Kassa des Kassenmoduls („DefaultCashPOSLabel“) (#190).

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das legt
die Tabelle für die gewählten Bereiche an.

## [0.6.0-beta] - 2026-09-22

Einnahmen-Ausgaben-Rechnung mit Vermögensübersicht nach § 21 VerG (#8). Damit ist der
Meilenstein v0.6 „Rechnungsprüfung & Rechnungslegung“ komplett.

### Neu

- **Einnahmen-Ausgaben-Rechnung mit Vermögensübersicht** (#8): Unter *Mitglieder –
  Verein – Einnahmen und Ausgaben* rechnet das Modul je Vereinsjahr aus, was auf den
  Bank- und Kassakonten ein- und ausgegangen ist. Bezahlte Rechnungen werden nach den
  Steuerprofilen ihrer Zeilen auf die Bereiche aufgeteilt, bezahlte Lieferantenrechnungen
  nach „Ausgabe für Bereich“, auch bei Teilzahlungen auf den Cent. Mitgliedsbeiträge und
  Spenden zählen zum ideellen Bereich. Umbuchungen zwischen eigenen Konten und der
  Anfangsstand eines neuen Kontos zählen nicht. Was sich nicht zuordnen lässt, steht als
  „nicht zugeordnet“ da, geraten wird nie. Ein Abgleich zeigt, ob Anfangsstand plus
  Einnahmen minus Ausgaben den Endstand der Konten ergibt.
- **Vermögensübersicht am Jahresende**: Kontostände, offene Forderungen und
  Verbindlichkeiten zum Stichtag und selbst eingetragene Werte wie Geräte oder ein
  Darlehen. Dazu die Frist von fünf Monaten (§ 21 Abs. 1 VerG), eine Warnung ab
  1 Mio. und 3 Mio. Euro (§ 22 VerG), alle Buchungen als CSV-Tabelle und ein PDF zum
  Unterschreiben. Es unterschreiben Obmann/Obfrau und Kassier:in, auch ohne das Recht,
  die Bank zu ändern.
- **Rechnungsprüfung**: Die Prüfseite zeigt, wann die Einnahmen-Ausgaben-Rechnung
  erstellt wurde und bis wann zu prüfen ist; der Prüfbericht nennt Einnahmen,
  Ausgaben, Ergebnis und Vermögen.

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das legt
die Tabelle und den Menüpunkt *Einnahmen und Ausgaben* an. Sehen darf die Seite, wer
das Modul und die Bank lesen darf; eintragen und das PDF erstellen, wer die Bank
ändern darf.

## [0.5.17-beta] - 2026-09-22

Rechnungsprüfung in Dolibarr: Prüfbereich je Vereinsjahr mit Hinweisen, Stichproben und
Prüfliste nach § 21 VerG, dazu der Bericht der Rechnungsprüfer als PDF (#114, #117).

### Neu

- **Rechnungsprüfung in Dolibarr** (#117): Unter *Mitglieder – Verein –
  Rechnungsprüfung* sehen die Rechnungsprüfer je Vereinsjahr alle Bankbuchungen,
  Kunden- und Lieferantenrechnungen. Hinweise zeigen, wo genauer hinzuschauen
  ist: Buchungen ohne Beleg, ungewöhnlich hohe Beträge und Geschäfte mit einem
  Vorstandsmitglied (§ 6 Abs. 4 VerG). Die Prüfer haken Stichproben mit
  Anmerkung ab und füllen die Prüfliste nach § 21 Abs. 3 VerG aus: Rechnungslegung,
  Mittelverwendung, ungewöhnliche Einnahmen und Ausgaben, Insichgeschäfte,
  Gefahren für den Verein. Wer ein Rechnungsprüfer ist, kommt aus den Funktionen;
  andere dürfen mitlesen, aber nichts abhaken. An Rechnungen und Bank ändert das
  Modul nichts.
- **Bericht der Rechnungsprüfer als PDF** (#114): mit Vorstand, Prüfern samt
  Wahltag und Funktionsperiode, Prüfungstag, Unterlagen, Ergebnis je Punkt und
  Unterschriftszeilen. Unterschreiben geht auf Papier, in Dolibarr oder mit
  ID Austria, wie bei den anderen Dokumenten.

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das legt
die Tabellen der Prüfung und den Menüpunkt *Rechnungsprüfung* an. Danach der
Benutzergruppe der Rechnungsprüfer Leserechte geben: Modul Vereine lesen, dazu
Rechnungen, Lieferantenrechnungen und Bank nur lesen. Die Prüfer brauchen einen
Benutzer, der mit ihrem Mitglied verknüpft ist.

## [0.5.16-beta] - 2026-09-22

Steuerprofile sauber: Lieferantenrechnungen ohne falsche Warnung, ein eigener Bereich für
Ausgaben, und Rechnungszeilen ohne Profil lassen sich prüfen und nachtragen (#53, #57).

### Behoben

- **Lieferantenrechnungen ohne falsche Steuerwarnung** (#53): Ein Steuerprofil
  beschreibt, was der Verein verkauft. Eine Lieferantenrechnung hat ihre eigene
  Umsatzsteuer: Ein Trikot, das der Verein als Kleinunternehmer mit 0 % verkauft,
  kauft er mit 20 % ein. Lieferantenzeilen bekommen deshalb kein Verkaufsprofil
  mehr und werden nicht mehr dagegen geprüft. Stattdessen gibt es das Feld
  **„Ausgabe für Bereich“** (ideell, Vermögensverwaltung, Hilfsbetrieb, Fest,
  Betrieb). Es wird aus dem Produkt vorgeschlagen und ist für die spätere
  Einnahmen-Ausgaben-Rechnung gedacht. Frühere Werte bleiben erhalten,
  ausgeblendet.

### Neu

- **Steuerprofile prüfen und nachtragen** (#57): Rechnungszeilen aus der Zeit vor
  den Steuerprofilen fehlen bei der Ampel. Die neue Seite zeigt je Jahr alle
  Zeilen ohne Profil mit einem Vorschlag und einer Vorschau (Anzahl und Summe je
  Profil). Angehakt sind nur eindeutige Fälle: Das Produkt hat ein Profil, die
  Rechnung gehört zu einem Mitgliedsbeitrag, oder die Gutschrift gehört zu einer
  Zeile mit Profil. Alles andere prüfst du selbst. Geändert wird nur die
  Zuordnung, nie Betrag, Umsatzsteuer oder Status, und jede Zuordnung steht im
  Protokoll. Dazu kommen Produkte ohne Profil und Zeilen, deren Umsatzsteuer
  nicht zum Profil passt. Erreichbar über den Hinweis „nicht zugeordnet“ in der
  Vereinsübersicht und über die Steuerprofile.

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das legt
an Lieferantenzeilen das Feld „Ausgabe für Bereich“ an und blendet das frühere
Verkaufsprofil dort aus. Danach über den Hinweis „nicht zugeordnet“ in der
Vereinsübersicht oder über die Steuerprofile die Seite „Steuerprofile prüfen“ öffnen.

## [0.5.15-beta] - 2026-09-22

Platzhalter für Vereinsdaten in allen E-Mail-Vorlagen von Dolibarr; Einladung, Protokoll und
Umlaufbeschluss als Vorlagen zum Ändern (#152).

### Neu

- **Platzhalter für Vereinsdaten** (#152): `__VEREINE_ZVR__`, `__VEREINE_NAME__`,
  `__VEREINE_OBMANN__`, `__VEREINE_VORSTAND__` und weitere funktionieren in
  jeder E-Mail-Vorlage von Dolibarr (*Einrichtung – E-Mails – Vorlagen*). In
  Dolibarrs E-Mails an ein Mitglied gibt es `__VEREINE_MITGLIED_FUNKTIONEN__`,
  und die Texte je Punkt im Protokoll kennen die Vereinsdaten auch.
- **Einladung, Protokoll und Umlaufbeschluss als Dolibarr-Vorlagen**: Das Modul
  legt je eine Vorlage mit dem bisherigen Text an. Ändere sie in Dolibarrs
  Vorlagen-Editor; beim Versand setzt das Modul Sitzung, Tagesordnung, Frist und
  Link ein. Einladungsbrief und Vorschau zeigen denselben Text wie die E-Mail.
- **Eine Liste aller Platzhalter** mit Erklärung und einem Beispiel aus deinem
  Verein: in der Einrichtung bei den E-Mails, bei den Sitzungsvorlagen und bei
  den Texten je Punkt. Ein Klick setzt den Platzhalter dort ein, wo du zuletzt
  geschrieben hast.

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das legt
die vier E-Mail-Vorlagen des Moduls mit dem bisherigen Text an. Ändern kannst du sie
unter *Einrichtung – E-Mails – Vorlagen*; die Liste der Platzhalter steht unter
*Einrichtung – Vereine – Verein* bei den E-Mails.

## [0.5.14-beta] - 2026-09-21

Unterschreiben mit ID Austria: qualifiziert, rechtlich wie eigenhändig, über einen
eigenen Signaturdienst des Vereins (#120).

### Neu

- **Unterschreiben mit ID Austria** (#120): Für Dokumente, bei denen es zählt,
  unterschreibt jede Person mit ihrer ID Austria – eine qualifizierte
  elektronische Signatur, rechtlich gleich wie eigenhändig unterschrieben. Unter
  *Einrichtung – Unterschriften* stellst du je Dokumentart ein, wie
  unterschrieben wird: in Dolibarr mit dem Passwort, nur mit ID Austria oder
  beides; Papier geht weiterhin immer. Wer dran ist, klickt „Mit ID Austria
  unterschreiben“, bestätigt am Handy und landet wieder in Dolibarr. Mehrere
  Personen unterschreiben nacheinander dasselbe PDF. Dolibarr prüft selbst, dass
  jedes zurückgekommene PDF das übergebene mit genau einer Signatur mehr ist.
  „Signaturen prüfen“ zeigt, wer unterschrieben hat (Name im Zertifikat) und ob
  das PDF seit jeder Unterschrift unverändert ist; amtlich prüfen lässt es sich
  unter signaturpruefung.gv.at.
- Dafür braucht der Verein einen **Signaturdienst**: PDF-AS, kostenlos, vom
  E-Government-Innovationszentrum, auf dem eigenen Server neben Dolibarr. Die
  Anleitung mit Docker steht in
  [docs/ID-AUSTRIA.md](https://github.com/Tabsi1998/dolibarr-vereine/blob/main/docs/ID-AUSTRIA.md),
  geprüft mit PDF-AS 5.0.0. Seine Adresse trägst du in der Einrichtung ein,
  „Verbindung prüfen“ sagt, ob er Dokumente annimmt. Ohne Adresse ist ID Austria aus, und das Modul verbindet sich mit
  keinem anderen Server. Dolibarr schickt dem Dienst das PDF selbst, der Dienst
  braucht keinen Zugriff auf Dolibarr.

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das
ergänzt die Spalte für den Namen im Zertifikat. Ohne eingetragenen
Signaturdienst ändert sich nichts: ID Austria bleibt aus, alles andere
unterschreibt wie bisher.

## [0.5.13-beta] - 2026-09-21

Sitzungen, die sich verständlich führen lassen: E-Mails, die ankommen, Punkte ohne
Beschluss, Vereinbarungen, Schritte, eine Startseiten-Kachel und PDFs mit Logo
(#168, #169, #170, #171, #173, #175).

### Behoben

- **E-Mails, die nicht ankommen, werden verständlich** (#168): Lehnt der
  Mailserver eine Einladung ab, steht jetzt in Klartext da, warum – etwa „Der
  Mailserver lässt den Absender noreply@… nicht zu; er erlaubt nur office@…" –
  und was zu tun ist. Die Originalantwort bleibt aufklappbar darunter, mit echten
  Zeilenumbrüchen statt `\r\n`. Eine Sitzung, bei der keine Einladung
  angekommen ist, sagt das deutlich, statt wie erledigt auszusehen.

### Neu

- **Wer macht was bis wann** (#170): Bei einer Sitzung legt ihr fest, wer sich
  um etwas kümmert – ohne Abstimmung, als *Vereinbarung*: Punkt wählen, eine
  oder mehrere Personen, was und bis wann. Jede Person bekommt eine Aufgabe in
  ihrem Dolibarr-Kalender, das Protokoll nennt die Vereinbarung beim Punkt, und
  die nächste Sitzung schlägt sie vor, solange sie offen ist. Wird über den
  Punkt doch abgestimmt, wird die Vereinbarung zur Folge des Beschlusses.
- **Sitzung Schritt für Schritt** (#171): Oben auf jeder Sitzung stehen die fünf
  Schritte – Planen, Einladen, In der Sitzung, Protokoll, Abschließen – mit
  „erledigt", „jetzt dran" oder „noch nicht" und einem Satz, was davon Pflicht
  ist (etwa die Einladungsfrist aus euren Statuten). Dazu „Begriffe kurz
  erklärt" zum Aufklappen. Vor dem Sitzungstag steht bei der Anwesenheit keine
  rote Warnung mehr, sondern ein ruhiger Hinweis.
- **Startseite: Wartet auf mich** (#173): Eine neue Kachel auf Dolibarrs
  Startseite zeigt, was auf dich wartet – offene Umlaufbeschlüsse zum
  Abstimmen, fehlende Unterschriften und deine Aufgaben, jeweils mit Link.
- **Am Handy abstimmen** (#173): Beim Umlaufbeschluss stehen Ja, Nein und
  Enthaltung als große Knöpfe da. Vor dem Start zeigt die Seite, wer im Vorstand
  keinen eigenen Dolibarr-Zugang hat und deshalb nicht abstimmen kann.
- **PDFs mit Logo** (#175): Protokoll, Beschluss, Beschluss-Auszug, Zählliste
  und Unterschriftenblatt haben jetzt einen gemeinsamen Kopf – das Logo des
  Vereins aus Dolibarr rechts oben, links Name, ZVR-Zahl und Anschrift – sowie
  „Seite x von y" in der Fußzeile und klare Überschriften mit Linie.
- **Eigener Absender für die E-Mails des Vereins** (#168) unter *Einrichtung –
  Allgemein – E-Mails des Vereins*, für Einladungen, Protokolle,
  Umlaufbeschlüsse und Erinnerungen. Viele Mailserver erlauben nur die Adresse,
  mit der sich Dolibarr anmeldet – die gehört dann hier hinein.
- **Test-E-Mail an mich** direkt daneben, damit du vor dem Einladen weißt, ob
  der Versand klappt.
- **Gescheiterte Einladungen erneut senden** mit einem Knopf, nachdem der
  Absender stimmt; der Nachweis zählt die Versuche.

### Geändert

- **Nicht jeder Tagesordnungspunkt ist ein Beschluss** (#169): Jeder Punkt hat
  jetzt eine *Art* – Bericht, Besprechung, Beschluss oder Wahl. Vorgeschlagen
  wird sie aus der Überschrift („Bericht …" ist ein Bericht, „Budget …" ein
  Beschluss, „Begrüßung" eine Besprechung) und lässt sich bei den Texten für das
  Protokoll ändern.
  - Das Abstimmungsformular bietet nur noch Beschluss- und Wahlpunkte an – nicht
    mehr die Begrüßung.
  - Im Protokoll steht bei einem Bericht oder einer Besprechung nicht mehr
    „Keine Abstimmung."; ein geplanter Beschluss ohne Abstimmung wird dagegen
    angezeigt, bevor das Protokoll fertig ist.
  - Jeder Text stand bisher zweimal da (Feld und Vorschau). Die Vorschau zeigt
    sich jetzt nur, wenn Platzhalter wie `{anwesend}` darin stehen.
- **Einladung vorher lesen** (#169): Vor dem Versand lässt sich die
  Einladungs-E-Mail genau so aufklappen, wie sie hinausgeht – mit Tagesordnung,
  Ort und Fristen.

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das
ergänzt die Spalten für Art und Vereinbarungen der Tagesordnungspunkte und die
Versuche der Einladungen und meldet die neue Startseiten-Kachel an. Danach bei
*Einrichtung – Allgemein – E-Mails des Vereins* den Absender prüfen und eine
Test-E-Mail senden. Damit die Kachel etwas zeigt, muss dein Benutzer mit deinem
Mitglied verknüpft sein.

## [0.5.12-beta] - 2026-09-21

Statuten, die zum Verein passen: der Vorstand nach dem Funktionskatalog, der
Zweck bei den Statuten und Geldangelegenheiten mit dem Kassier (#149, #150,
#151).

### Behoben

- **Statuten: der Vorstand wird nicht mehr starr festgeschrieben** (#149): Bisher
  stand dort „Der Vorstand besteht aus sechs Mitgliedern, und zwar aus: …" –
  auch dann, wenn es die Stellvertretungen gar nicht gibt. Jetzt richtet sich
  der Satz nach dem Funktionskatalog: Funktionen mit Mindestanzahl 1 sind
  Vorstandsmitglieder, alles andere kommt als *„und bei Bedarf …"* dazu, und
  eine Zahl steht nur dort, wo sie wirklich feststeht. Das Musterstatut des
  Innenministeriums (sechs Mitglieder mit Stellvertretungen) lässt sich damit
  genauso abbilden wie ein Verein mit drei Pflichtfunktionen. Der Absatz zur
  Vertretung im Verhinderungsfall steht nur noch dort, wo es Stellvertretungen
  gibt; die **Schriftführung** ist im Vorschlag jetzt Pflichtfunktion.
- **Geldangelegenheiten brauchen den Kassier** (#151): Neue Dokumentart
  *Geldangelegenheit*, vorbelegt mit Obmann/Obfrau und Kassier:in – wie es § 13
  Abs. 2 der Musterstatuten verlangt. Ein Beschluss lässt sich als *Beschluss
  mit Geldwirkung* kennzeichnen; sein PDF holt dann diese Unterschriften und
  sagt es auch im Dokument.

### Geändert

- **Der Zweck wird dort eingetragen, wo er hingehört** (#150): bei den Statuten,
  nicht mehr unter *Allgemein*. Dort steht er weiterhin, aber nur zum Nachlesen
  mit einem Link – gespeichert wird er an genau einer Stelle.
- **Neue Tabelle „Woher die Paragrafen kommen"** am Reiter *Statuten*: Sie nennt
  für jeden Paragrafen die Einstellung, aus der er entsteht. Ein Test hält die
  Tabelle mit den erzeugten Abschnitten in Schritt.

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das
ergänzt am Beschlussbuch, ob ein Beschluss eine Geldangelegenheit ist. Der Zweck
bleibt, wie er ist – er wird jetzt am Reiter *Statuten* bearbeitet. Ein bereits
eingerichteter Funktionskatalog bleibt unverändert: Soll die Schriftführung bei
dir Pflicht sein, setze ihre Mindestanzahl unter *Funktionen* auf 1.

## [0.5.11-beta] - 2026-09-18

Unterlagen zu Abstimmungen und Wahlen, und Einstellungen, die im Formular
durchschlagen (#115, #144, #145).

### Behoben

- **Umlaufbeschlüsse standen nicht in den Statuten** (#144): Der Schalter *Die
  Statuten erlauben Umlaufbeschlüsse im Vorstand* wirkte nur im Modul; der
  erzeugte Statuten-Text hat den Umlaufbeschluss nicht erwähnt. Jetzt bekommt
  der Abschnitt *Vorstand* den passenden Absatz – samt dem Zusatz, dass niemand
  widersprechen darf, wenn die Statuten das verlangen. Ein neuer Test geht jede
  Regel der Statuten durch und schlägt an, sobald eine Regel im erzeugten Text
  nichts ändert; so fällt eine solche Lücke künftig sofort auf.

### Neu

- **Unterlagen einer Sitzung und Zählliste** (#115): Was eine Abstimmung
  nachweist, hängt jetzt an der Abstimmung.
  - **Zählliste als PDF** zum Ausdrucken vor der Sitzung: Frage oder Funktion
    mit den Kandidaten, Spalten für Ja, Nein, Enthaltung und Vermerk, so viele
    Zeilen zum Zählen wie du brauchst, Unterschriften für Wahlleitung und
    Stimmzählung. Sie wird ausgeliefert und nicht behalten – ausgefüllt kommt
    sie als Nachweis wieder herauf.
  - **Nachweise hochladen** (PDF oder Bild, höchstens 10 MB): am Beschluss die
    ausgefüllte Zählliste oder der Scan der Stimmzettel, am Anwesenheitseintrag
    die unterschriebene **Vollmacht**, dazu sonstige Unterlagen der Sitzung.
    Gespeichert wird mit Prüfsumme in den Dokumenten der Sitzung; nichts wird
    überschrieben, eine Datei anderer Art abgelehnt.
  - Herunterladen darf nur, wer die Sitzungen sehen darf; hochladen nur, wer
    auch Abstimmungen einträgt.
  - Das **Protokoll** nennt die Unterlagen als *Anlagen* (#21).

### Geändert

- **Einstellungen schlagen jetzt im Formular durch** (#145): Was in der
  Einrichtung steht, wird vorgeschlagen, statt dass du es im Kopf ausrechnest.
  - **Funktionsperiode**: Wird eine Funktion ohne End-Datum eingetragen, endet
    sie automatisch nach den Jahren aus dem Funktionskatalog – am Tag vor dem
    Jahrestag. Das gilt auch für eine **Wahl in der Versammlung**. Ein
    eingetragenes Ende gewinnt immer.
  - **Austritt**: Der *letzte Tag* steht auf dem Tag, den die Austrittsregel
    ergibt, statt auf heute.
  - **Neue Sitzung**: Der Tag steht auf dem frühesten Termin, der die
    Einladungsfrist einhält.
  - **Abstimmung**: Das Formular nennt die nötige Mehrheit je Art der
    Abstimmung, bevor etwas eingetragen wird.
  - **Umlaufbeschluss**: Die Frist steht auf zwei Wochen.

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das
legt die Tabelle für die Unterlagen einer Sitzung an. Alle Daten und
Einstellungen bleiben. Bereits eingetragene Funktionsperioden ändert das Update
nicht: Der Vorschlag aus dem Funktionskatalog gilt für neue Einträge und Wahlen.

## [0.5.10-beta] - 2026-09-18

Beschlüsse an einer Stelle: Beschlussbuch, Beschluss als PDF und
Umlaufbeschlüsse im Vorstand (#22, #122, #121).

### Neu

- **Umlaufbeschluss im Vorstand** (#121): Der Vorstand beschließt zwischen
  zwei Sitzungen – sauber festgehalten statt per WhatsApp.
  - **Nur wenn die Statuten es erlauben**: neuer Schalter in den Regeln der
    Statuten, standardmäßig aus. Das Vereinsgesetz regelt Umlaufbeschlüsse
    nicht, und die Musterstatuten des Innenministeriums sehen sie nicht vor –
    es gilt allein, was in deinen Statuten steht. Verlangen sie, dass niemand
    dem Umlaufverfahren widerspricht, lässt sich auch das eintragen: ein
    Widerspruch beendet den Umlauf.
  - **Antrag mit Wortlaut und Frist** (längstens 90 Tage). Stimmberechtigt sind
    die Vorstandsmitglieder des Tages; jedes bekommt eine E-Mail mit dem Link
    und stimmt **in Dolibarr** ab (ja, nein, Enthaltung) – der Vorstand
    entscheidet als Organ, deshalb nicht über die Website.
  - **Erinnerung** an alle, die noch nicht abgestimmt haben. Das Ergebnis wird
    festgestellt, sobald alle abgestimmt haben oder die Frist vorbei ist:
    einfache Mehrheit der gültigen Stimmen, Stimmengleichheit gilt als
    abgelehnt (bei einem Umlauf führt niemand den Vorsitz, also gibt es keinen
    Stichentscheid).
  - Der Beschluss landet im **Beschlussbuch** (#22) und kann dort als PDF
    unterschrieben werden (#122).
- **Beschluss als PDF** (#122): Jeder Beschluss lässt sich als eigenes
  Dokument erzeugen – mit Verein und ZVR-Zahl, Organ, Sitzung, Tag und
  Tagesordnungspunkt, Wortlaut, Ergebnis mit Stimmen und nötiger Mehrheit,
  Beschlussfähigkeit zur Uhrzeit der Abstimmung und, bei einer Wahl, Funktion,
  Person und Funktionsperiode. So kannst du einen einzelnen Beschluss
  weitergeben, etwa an die Bank oder an einen Fördergeber, ohne das ganze
  Protokoll.
  - **Unterschrieben** wird er wie jedes Dokument (#119): auf Papier mit Scan
    zurück oder in Dolibarr mit dem eigenen Passwort. Wer unterschreibt, sagt
    die Einstellung *Unterschriften* (vorbelegt Obmann/Obfrau und
    Schriftführung). Wird das PDF neu erzeugt, verlangt der Lauf neue
    Unterschriften.
  - **Beschluss-Auszug**: mehrere Beschlüsse in einem Dokument, ausgewählt in
    der Liste des Beschlussbuchs.
- **Beschlussbuch** (#22): Der neue Menüpunkt *Beschlussbuch* zeigt jeden
  Beschluss an einer Stelle.
  - Jede Abstimmung einer Sitzung legt ihren Eintrag selbst an, mit Nummer
    (Jahr und laufende Nummer), Organ, Tagesordnungspunkt, Ergebnis und den
    gezählten Stimmen. Die Zahlen bleiben, wie sie in der Sitzung gezählt
    wurden. Abstimmungen aus früheren Versionen werden beim Aktivieren
    nachgetragen.
  - Nachtragen kannst du **Wortlaut**, **Kategorie** (Finanzen, Mitglieder,
    Organe, Statuten, Veranstaltungen, Sonstiges), **ab wann und bis wann** der
    Beschluss gilt, das betroffene **Mitglied** und die **Rechnung** in
    Dolibarr. Eine eigene Ausgabenfreigabe gibt es nicht: Freigabe und Zahlung
    macht der Kassier in Dolibarr, der Beschluss verweist nur darauf.
  - **Suchen und filtern** nach Nummer, Titel, Wortlaut und Vermerk sowie nach
    Jahr, Organ, Kategorie, Ergebnis und offenen Folgen; die Liste lässt sich
    als CSV herunterladen.
  - **Folgen eines Beschlusses**: eine Aufgabe mit zuständiger Person und
    Frist. Sie wird als Aufgabe im Dolibarr-Kalender der zuständigen Person
    angelegt und erscheint am Mitglied; als erledigt gilt sie an beiden
    Stellen. Offene Folgen werden bei einer neuen Sitzung als
    Tagesordnungspunkt vorgeschlagen.
  - Am Mitglied listet der Reiter *Verein* die Beschlüsse, die es betreffen
    (Wahl, Aufnahme, Ehrung, Ausschluss).

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das
legt die Tabellen des Beschlussbuchs, seiner Folgen und der Umlaufbeschlüsse an
und trägt die Abstimmungen früherer Versionen im Beschlussbuch nach. Alle Daten
und Einstellungen bleiben. Umlaufbeschlüsse sind danach noch aus: einschalten in
*Einrichtung – Statuten*, wenn deine Statuten sie erlauben.

## [0.5.9-beta] - 2026-09-17

Unterschriften und das Protokoll als PDF (#119, #21).

### Neu

- **Protokoll als PDF** (#21): Eine Sitzung bekommt *Vorsitz* und
  *Protokollführung* (vorgeschlagen aus den Funktionen) und den Abschnitt
  *Protokoll*.
  - **Entwurf** jederzeit als PDF: Kopf mit Verein und ZVR-Zahl, Sitzungsart,
    Tag, Uhrzeit, Form und Ort, Anwesenheit mit Vollmachten und
    Beschlussfähigkeit, jeder Tagesordnungspunkt mit seinem Text und seinen
    Abstimmungen, die Beschlüsse als Liste, Unterschriftszeilen.
  - **Endfassung** mit Tag der Genehmigung und wie genehmigt: eingefroren, mit
    Prüfsumme gespeichert und sofort im Unterschriftslauf (#119), unterschrieben
    von Vorsitz und Protokollführung. Eine weitere Fassung bekommt die nächste
    Nummer; eine fertige Fassung ändert sich nie.
  - **Versand** der Endfassung als E-Mail mit PDF an den Vorstand oder an alle
    aktiven Mitglieder, mit Vermerk, wann das geschehen ist.
- **Unterschriften** (#119): Einrichtungsreiter *Unterschriften* legt je Art von
  Dokument fest, welche Funktionen unterschreiben und wie viele Unterschriften
  nötig sind – vorbelegt wie in den Musterstatuten (schriftliche Ausfertigungen:
  Obmann/Obfrau und Schriftführung; Prüfbericht: die Rechnungsprüfer).
- **Zwei Wege**, wie im Grundsatz: das PDF ausdrucken, unterschreiben und den
  Scan hochladen – oder in Dolibarr mit dem eigenen Passwort unterschreiben.
  Festgehalten werden Person, Funktion, Zeitpunkt, Weg und die Prüfsumme des
  Dokuments; ein neu erzeugtes Dokument verlangt neue Unterschriften.
- **Unterschriftenblatt** als PDF mit Dokument, Prüfsumme, allen Unterschriften
  und einem ehrlichen Hinweis zur Rechtswirkung.
- Angewandt auf die **Schreiben an die Vereinsbehörde**: Ein geschriebenes
  Schreiben startet seinen Unterschriftslauf, die Liste zeigt den Stand.

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das
legt die Tabellen der Unterschriften und der Protokoll-Fassungen an und ergänzt
an der Sitzung, wer den Vorsitz hatte und wer das Protokoll geführt hat.

## [0.5.8-beta] - 2026-09-17

Vereine ist jetzt ein Modul nur für österreichische Vereine, ganz auf Deutsch,
mit einer neuen Arbeitsweise für Releases (#128).

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

### Update

Neues ZIP bereitstellen, dann das Modul einmal deaktivieren und aktivieren. Das
löscht die Einstellungen des alten Länderprofils und gibt dem Webhook-Ereignis
seinen deutschen Namen. Vereinsdaten, ZVR-Zahl und alle anderen Einstellungen
bleiben. Das Modul heißt in der Modulliste jetzt „Vereine (Österreich)“.

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

[Unreleased]: https://github.com/Tabsi1998/dolibarr-vereine/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v1.0.0
[0.11.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.11.0-beta
[0.10.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.10.0-beta
[0.9.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.9.0-beta
[0.8.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.8.0-beta
[0.7.1-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.7.1-beta
[0.7.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.7.0-beta
[0.6.2-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.6.2-beta
[0.6.1-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.6.1-beta
[0.6.0-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.6.0-beta
[0.5.17-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.17-beta
[0.5.16-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.16-beta
[0.5.15-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.15-beta
[0.5.14-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.14-beta
[0.5.13-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.13-beta
[0.5.12-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.12-beta
[0.5.11-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.11-beta
[0.5.10-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.10-beta
[0.5.9-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.9-beta
[0.5.8-beta]: https://github.com/Tabsi1998/dolibarr-vereine/releases/tag/v0.5.8-beta
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
