# Aufbau

## Grundsätze

Diese Grundsätze gelten für jedes Issue und jeden Pull Request (#118):

1. **Zwei Wege für jedes Dokument:** sauber als PDF erzeugen → auf Papier prüfen
   oder unterschreiben → hochladen → fertig; **oder** komplett in Dolibarr mit
   digitaler Unterschrift. Beide Wege enden im selben fertigen Dokument mit
   Verlauf.
2. **Die Rolle entscheidet, nicht die Person:** Was ein **Organ** tut – der
   Vorstand als Vorstand (Beschlüsse, Umlaufbeschlüsse, Annahme von Anträgen,
   Unterschriften für den Verein), die Rechnungsprüfer als Prüfer, die
   Wahlleitung – passiert **nur in Dolibarr**. Was jemand **als Mitglied** tut –
   in der Generalversammlung abstimmen und wählen, eigene Daten, Einwilligungen,
   Antrag, Anmeldungen – geht auch über die Website-API oder das Webportal,
   **auch für Vorstandsmitglieder**, denn sie sind ebenfalls Mitglieder.
3. **Alles über Rechte einstellbar**, was die API anbietet; die Website
   entscheidet nie selbst.
4. **Dolibarr-Bordmittel zuerst:** Ereignisse, Dokumentvorlagen,
   Online-Unterschrift, Benutzergruppen, Freigaben – erweitern statt nachbauen.
   Das Modul ändert keine Dateien von Dolibarr und schreibt nur unter den
   Dokumentenordner.
5. **Für österreichische Vereine:** Das Modul ist öffentlich und passt für jeden
   Verein in Österreich; nichts ist auf einen Verein zugeschnitten (etwa
   Webportal und eigene Website, beides möglich, #25).
6. **Ehrlich zur Rechtswirkung**, Quellen in [LEGAL-SOURCES.md](LEGAL-SOURCES.md),
   Erklärungen in Alltagsdeutsch.
7. **Was ein Organ beschließt oder prüft, wird unterschrieben** – wer, sagen die
   Statuten (#119). Musterstatuten des Innenministeriums: schriftliche
   Ausfertigungen Obmann/Obfrau und Schriftführung, Geldangelegenheiten
   Obmann/Obfrau und Kassier; die Schriftführung führt die Protokolle. Wichtige
   Dokumente mit ID Austria (#120).

Sprache: Alles, was Menschen lesen – Oberfläche, PDFs, Doku, Changelog,
Release-Texte, Issues – ist Deutsch. Code (Namen, Kommentare) bleibt Englisch,
wie es Dolibarrs Code-Standard verlangt.

## Dolibarr erweitern, nicht nachbauen

Mitglieder, Mitgliedsarten, Beitragsperioden, Spenden, Rechnungen, Bankkonten,
SEPA-Lastschriften, Veranstaltungen, die Agenda, Dokumente, E-Mail-Vorlagen,
geplante Aufgaben und die REST-API gibt es in Dolibarr schon. Das Modul nutzt
diese Objekte und ergänzt nur, was ein österreichischer Verein zusätzlich
braucht.

Gesetzliche Beträge und Fristen gehören in Daten mit Gültigkeitszeitraum und
Rechtsquelle, nicht in Code, damit eine geänderte Grenze ein Datenupdate ist.

## Dateien

| Pfad | Inhalt |
| --- | --- |
| `core/modules/modVereine.class.php` | Deskriptor: Nummer 492100, Rechte, Menü, Abhängigkeiten |
| `class/vereineassociationrules.class.php` | Eingaberegeln der Vereinsdaten: ZVR-Zahl, Vereinszweck, Gründungsdatum; reines PHP |
| `class/vereineorganization.class.php` | Vereinsdaten und Prüfungen, reines PHP; `load()` liest aus Dolibarr |
| `class/api_vereine.class.php` | REST-API-Klasse `Vereine` |
| `class/vereinepartnerrules.class.php` | Entscheidungen zu Mitglied und Geschäftspartner, reines PHP |
| `class/vereinepartnerservice.class.php` | Verknüpft, legt an und gleicht Mitglieder und Geschäftspartner in Dolibarr ab |
| `class/vereinelog.class.php` | Das nur ergänzte Protokoll `llx_vereine_log` |
| `core/triggers/interface_99_modVereine_VereineTriggers.class.php` | Mitgliedsereignisse ziehen den Geschäftspartner nach |
| `partners.php`, `partner_membership.php`, `member_association.php`, `admin/partners.php` | Abgleich, Reiter am Geschäftspartner, Reiter am Mitglied, Partner-Einstellungen |
| `class/actions_vereine.class.php` | Hooks auf Dolibarrs Mitgliedskarte, Rechnungskarten und Rechnungs-PDFs |
| `class/vereinetaxrules.class.php` | Sphären, USt-Behandlungen, Prüfung und Vorschläge der Steuerprofile, reines PHP |
| `class/vereinetaxprofiles.class.php`, `admin/taxprofiles.php` | Steuerprofile in `llx_vereine_taxprofile` und ihr Einrichtungsreiter |
| `class/vereinefeerules.class.php` | Nächste Beitragsperiode und Betrag eines Mitglieds: Beitragsjahr, anteiliger Beitrag, Aufnahmegebühr; reines PHP |
| `class/vereinefeediscounts.class.php` | Welche Ermäßigung ein Mitglied auf einen Beitrag bekommt, reines PHP |
| `class/vereinefeediscountstore.class.php` | Ermäßigungsregeln in `llx_vereine_fee_discount` und die Mitgliedsfelder für Befreiung und Nachweis |
| `class/vereinefeefamilies.class.php` | Zahler, Familienregel (Rabatt je weiterem Mitglied, Höchstbetrag je Beitragsjahr) und Aufteilung eines Höchstbetrags, reines PHP |
| `class/vereinefeefamilystore.class.php` | Mitgliedsfeld `vereine_fee_payer`, die Konstanten der Familienregel, Familien und was ihnen verrechnet wurde |
| `class/vereinefunctionrules.class.php` | Vorgeschlagene Funktionen, Inhaber an einem Tag und Probleme: fehlt, zu viele, Vorstand mit weniger als zwei, Prüfer im Vorstand; reines PHP |
| `class/vereinefunctions.class.php`, `admin/functions.php`, `functions.php` | Funktionskatalog (`llx_vereine_function`), Funktionsperioden (`llx_vereine_function_term`), Meldungen an die Vereinsbehörde (`llx_vereine_function_report`, Agenda-Termin, Schreiben als PDF in `documents/vereine/authority`, Mitgliedsfeld `vereine_birth_place`), Benutzergruppen über Funktionen (`fk_usergroup`, Vorschläge aus Perioden und `llx_usergroup_user`, angewandt mit `User::SetInGroup`/`RemoveFromGroup` erst nach Bestätigung durch einen Administrator), Einrichtungsreiter und Übersicht *Vorstand und Funktionen* |
| `authority.php`, `class/vereineauthorityrules.class.php`, `class/vereineauthorityletters.class.php` | Schreiben an die Vereinsbehörde: Regeln in reinem PHP (Arten, Fristen, zuständige Behörde nach Sitz, Liste für Tirol), ein PDF-Layout für jedes Schreiben samt Meldung der Vertreter, Tabelle `llx_vereine_authority_letter` mit Frist, Agenda-Termin und Tag der Einbringung |
| `volunteer.php`, `class/vereinevolunteerrules.class.php`, `class/vereinevolunteers.class.php` | Freiwilligenpauschale und PRAE: Einsätze in `llx_vereine_volunteer` (Mitglied, Tag, Tätigkeit, Art, Betrag, optional der bestätigte Helferdienst, den ein Unique-Key nur einmal zulässt). Die Grenzen stehen als datierte Tabelle mit Rechtsgrundlage in den Regeln; geprüft wird beim Speichern mit den übrigen Einsätzen derselben Person (Tag, Monat, Kalenderjahr, PRAE neben Pauschale). Markiert wird, nichts verboten. Jahresliste je Person und CSV für die Meldungen. Erfassen dürfen Administratoren, Kassier und Obmann. Ausbezahlt wird über `llx_vereine_volunteer_payout`: Liste als PDF, Unterschriftslauf der eigenen Art `payout` (#119, ab Werk wie Geldangelegenheiten; eigene Art, damit sich Läufe nie mit denen eines Geld-Beschlusses gleicher Nummer treffen), erst danach je Person eine `PaymentVarious` von Dolibarr mit Bankzeile; alles oder nichts in einer Transaktion |
| `overpayments.php`, `class/vereineoverpaymentrules.class.php`, `class/vereineoverpayments.class.php` | Überzahlungen auf Kundenrechnungen (#54): Standard-, Ersatz- und Situationsrechnungen, deren Zahlungen plus verrechnete Gutschriften und Anzahlungen über dem Betrag liegen, gerechnet wie Dolibarr beim Umwandeln. `llx_vereine_overpayment` hält je Rechnung eine Zuordnung (Unique-Key auf Rechnung) und das Dolibarr-Objekt dahinter: `DiscountAbsolute` „(EXCESS RECEIVED)“ ohne USt wie `confirm_converttoreduc`, eine `PaymentVarious` mit Bankzeile für die Rückzahlung, oder ein `Don` (bestätigt, bezahlt ohne Bankzeile, weil das Geld mit der Rechnungszahlung kam, mit der Rechnung verknüpft). Danach `Facture::setPaid()`, damit Dolibarrs eigener Knopf denselben Betrag nicht noch einmal umwandelt; ein Guthaben, das Dolibarr selbst angelegt hat, gilt als zugeordnet. Alles in einer Transaktion. Hinweis auf der Rechnungskarte über `formConfirm`, offener Punkt in der Vereinsübersicht, und in der Einnahmen-Ausgaben-Rechnung zählt ein gespendeter Mehrbetrag bei der Zahlung, die ihn brachte, zum ideellen Bereich |
| `donations.php`, `admin/donations.php`, `class/vereinedonationrules.class.php`, `class/vereinedonations.class.php`, `xsd/UebermittlungSonderausgaben_2.xsd` | Spendenmeldung an das Finanzamt (#6). Die Spenden bleiben in Dolibarrs Modul *Spenden* (`llx_don`, bezahlt = Status 2, Jahr = `datedon`). `llx_vereine_donation_gift` ordnet jede bezahlte Spende einer Person in `llx_vereine_donor` zu (gleicher Geschäftspartner, sonst gleicher Vor- und Nachname, sonst neu; von Hand umhängbar). Die Person hält Namen, Geburtsdatum (mit `dolEncrypt()`, ohne Schlüssel wird es nicht gespeichert), Referenznummer (eindeutig je Mandant) und vbPK SA. Abgleich im Batch-Format des Stammzahlenregisters (SZR-Dokumentation 2.3): Datei-Header, Leerzeile, feste Spalten; eingelesen werden `VERSCHL_BPK`, `KEINTREFFER`, `NICHT_EINDEUTIG` und `ERROR` aus dem ZIP. `llx_vereine_donation_report` und `_line` halten jede Übermittlung: was das Finanzamt hat, ist die letzte angenommene Zeile (S = nichts); daraus folgt E, A oder S. Das XML entsteht mit DOM und wird vor dem Speichern gegen das XSD des BMF (Stand 24.07.2024) geprüft; das Protokoll der DataBox wird über die MessageRefId zugeordnet, ein Testprotokoll ändert keine Zeile. Eigene Berechtigung `donation write` |
| `admin/start.php`, `class/vereinesetupguiderules.class.php`, `class/vereinesetupguide.class.php` | Erste Schritte (#126): neun Schritte in `VereineSetupGuideRules::STEPS`, jeder mit einer Regel, die ihn aus Fakten der Installation als erledigt erkennt (Firmendaten und ZVR, Pflichtmodule, Statuten, besetzte Funktionen und ein Vorstands-Benutzer mit Mitglied, Mitgliedsarten, Einwilligungstexte, versandte Testnachricht, Unterschriftsregeln oder Sitzungsvorlagen, API-Benutzer mit Website-Recht). Eigene Einstellungen nur für ausgelassene Schritte, den ausgeblendeten Hinweis und den Tag der Testnachricht. Ein neuer Schritt ist ein Eintrag in `STEPS`, eine Regel in `done()` und seine Texte |
| `archive.php`, `public/verify.php`, `class/vereinearchiverules.class.php`, `class/vereinearchive.class.php` | Vereinsakte (#123). `VereinePdf::instance(true)` baut fertige Dokumente als PDF/A-3b (gleiche TCPDF/TCPDI-Klasse, gleiches Format wie Dolibarr, Schriften eingebettet). Vor dem Bauen bekommt ein Dokument eine Kennung (10 Zeichen ohne 0/O/1/I; dieselbe beim Neubauen desselben Objekts), `VereinePdf::finish()` druckt sie mit Prüf-URL und QR-Code in jede Fußzeile. `llx_vereine_document` hält Kennung, Art, Objekt und Titel, `llx_vereine_document_file` jede Fassung mit SHA-256 (wie erstellt, QES-signiert, Scan – die Signaturen melden ihre Kopien). `public/verify.php` ist die einzige Seite ohne Login (`NOLOGIN`, Vertrag in `check-module.sh`): Art, Datum, Unterzeichnende und Prüfsummen, nie der Titel; ein hochgeladenes PDF wird nur gehasht; `VEREINE_VERIFY_PUBLIC=0` schaltet sie ab. Der Export legt Dokumente mit Kennung, Statutenfassungen und Behördenschreiben eines Zeitraums mit `inhaltsverzeichnis.csv` und `pruefsummen.sha256` in ein ZIP |
| `class/vereinedisclosurerules.class.php`, `class/vereinedisclosure.class.php`, Reiter *Verein* der Mitgliedskarte | Auskunft (#10, Art. 15 DSGVO). `VereineDisclosure::collect()` liest je Abschnitt (`VereineDisclosureRules::SECTIONS`) nur Zeilen, in denen das Mitglied selbst steht; Log ohne Nachrichtentext; Geburtsdatum als Spender nur mit dem Recht Spendenmeldung. `make()` schreibt PDF und JSON (`format: vereine-auskunft-1`) in ein ZIP im Temp-Ordner, das nach dem Herunterladen gelöscht wird; `llx_vereine_disclosure` hält Anfragetag, Art der Identitätsprüfung, Ausgabe und SHA-256 des ZIP. Neue Daten des Moduls bekommen einen Abschnitt in `SECTIONS` und eine Abfrage in `collect()` |
| `class/vereineerasurerules.class.php`, `class/vereineerasure.class.php`, `admin/privacy.php`, Reiter *Verein* der Mitgliedskarte | Löschen nach dem Austritt (#10). `VereineErasureRules::CATEGORIES` ist das Dateninventar: je Art Aktion (`blank`, `delete`, `anonymize`, `keep`), Fristbeginn (Austritt, Jahresende des letzten Eintrags, einzelner Eintrag), Jahre und ob der Verein sie ändern darf (`VEREINE_ERASURE_PERIODS`). `plan()` rechnet je Art den Stand (fällig, wartet, bleibt, gesperrt); der Name wartet, solange `NEED_NAME`-Arten aufbewahrt werden, und bleibt bei Funktionären. `VereineErasure::carryOut()` führt nur Fällige in einer Transaktion aus, widerruft Bindungen über `VereineAccess::revoke()`, löscht Dateien erst nach dem Commit und löst `MEMBER_MODIFY` aus, damit Änderungsfeed und Webhooks folgen. `llx_vereine_erasure` hält Durchgänge (Anzahlen je Art, JSON) und Sperren mit Grund. Neue personenbezogene Daten des Moduls bekommen eine Art in `CATEGORIES` und eine Zählung in `found()` |
| `class/vereinearrearrules.class.php`, `class/vereinearrears.class.php`, Trigger, `meetings.php` | Mahnwesen-Anbindung (#17). Die Ereignisse des Mahnwesen-Moduls (`MAHNWESEN_CASE_*`, Vertrag Version 1, Objekt `mahnwesen_event`) kommen als Dolibarr-Trigger. `VereineArrears::onEvent()` liest Fall und Profil über `DunningManager::getCase()` und `resolveProfile()` neu und die Rechnung aus Dolibarr; `VereineArrearRules::decide()` entscheidet: nur eine per `element_element` mit einem Mitgliedsbeitrag verknüpfte Rechnung, nur Profile mit letztem Schritt `membership_review`, ältere Revisionen werden ignoriert. `llx_vereine_arrear` hat je Fall eine Zeile (eindeutig über `entity, fk_case`). Ein Fehler beim Schreiben gibt -1 zurück, damit das Mahnwesen die Meldung erneut zustellt. Der Vorschlag kommt nur über das Häkchen im Formular einer Vorstandssitzung auf die Tagesordnung; eine andere Sitzungsart lehnt `meetings.php` ab. Mitgliedszusammenfassung und Änderungsfeed bekommen nichts aus der Mahnakte |
| `class/vereinesocialrules.class.php`, `class/vereinesocial.class.php`, `admin/social.php` | Kanäle und Konten (#233). Netzwerke sind Dolibarrs Wörterbuch `llx_c_socialnetworks`; eigene Netzwerke fügt `addNetwork()` dort ein. Kanäle des Vereins in `llx_vereine_channel` (mehrere je Netzwerk, Reihenfolge, öffentlich), in `VereineOrganization::build()` als `channels`. Welche Konten Antrag und App abfragen: `VEREINE_SOCIAL_ASKED`; abgefragte Netzwerke werden im Wörterbuch aktiv, damit die Mitgliedskarte sie zeigt. Die Namen stehen in Dolibarrs eigenem Feld `llx_adherent.socialnetworks`; `llx_vereine_social` hält nur Bestätigungen einer Anwendung (Name, Kennung beim Netzwerk, Anwendung, Zeitpunkt). Eine Bestätigung gilt, solange der Name gleich ist (`stillConfirmed()`). Die API `me/accounts` braucht die Fähigkeit `accounts` der Bindung (#153) |
| `class/vereinehonourrules.class.php`, `class/vereinehonours.class.php`, `honours.php`, `statistics.php` | Ehrungen und Statistik (#27, #28). Mitglied seit wie in der Zusammenfassung (erste Beitragsperiode oder Validierung). Jubiläen aus `VEREINE_HONOUR_MILESTONES`, Geburtstage nur mit der Einwilligung `VEREINE_HONOUR_BIRTHDAY_CONSENT` (letzte Entscheidung je Mitglied zählt). `llx_vereine_honour` hält vergebene Ehrungen (jubilee, award, honorary); Ehrenmitgliedschaft setzt die Mitgliedsart `VEREINE_HONORARY_TYPE` und löst `MEMBER_MODIFY` aus. Die Statistik zählt Mitglieder an einem Tag (`memberOn()`: begonnen und nicht beendet; Ende aus dem Austritt oder der letzten Änderung) nach Art, Geschlecht, Altersgruppe (`VEREINE_STATISTICS_AGES`) und Mitgliederkategorie, nie mit Namen; CSV mit BOM und ohne Formeln |
| `class/vereineloanrules.class.php`, `class/vereineloans.class.php`, `inventory.php` | Inventar und Ausleihe (#26). Geräte sind Dolibarrs Ressourcen (`llx_resource`, Modul Ressourcen); Reservierungen sind Dolibarrs Verknüpfung `llx_element_resources` (element_type `action`, resource_type `dolresource`). `llx_vereine_loan` hält Ausgabe, Rückgabetag, Rückgabe und Zustand; offen heißt `returned_on IS NULL`, je Gerät höchstens eine offene Ausleihe. Der geplante Auftrag `VereineCronLoans` erinnert an Überfälliges einmal in `REMIND_DAYS` Tagen, nur an die Adresse der Person |
| `class/vereinepublicationrules.class.php`, `class/vereinepublications.class.php`, `archive.php` | Dokumente veröffentlichen (#156, #157). `llx_vereine_publication` = eine Fassung (`llx_vereine_document_file`) eines Dokuments für eine Zielgruppe (board, members, public), mit Rücknahme. `VereineArchive::addFile()` liefert die Fassung, `published()` gibt sie an `onFile()` weiter: Regeln `VEREINE_PUBLISH_RULES` je Dokumentart veröffentlichen nur signed/scan. `catalog()` wählt je Dokument die neueste sichtbare Fassung; `pdf()` liefert die Bytes nur, wenn ihre SHA-256 der gespeicherten entspricht. Akteur: Öffentlichkeit, aktives Mitglied, Vorstand heute (`actorFor()`). API `documents`, `documents/{id}/pdf`, `me/documents`, `me/documents/{id}/pdf` |
| `class/vereinestatuteversionrules.class.php`, `VereineStatutes::published()` | Statuten über die API (#158). `onDay()` bestimmt je Fassung (`llx_vereine_statute`, nur beschlossene) den Stand am Stichtag und die geltende; gleicher Beginn zweier Fassungen = `ambiguous`, keine geraten. Freigabe `VEREINE_STATUTES_AUDIENCE` (leer, members, public) mit derselben Sichtbarkeit wie #156; PDF nur bei passender SHA-256 |
| `class/vereinemotionrules.class.php`, `class/vereinemeetingportal.class.php` | Sitzungen über die API (#159). Sichtbar ist eine Sitzung nur über `llx_vereine_meeting_invitation` (Status invited, held, cancelled). `llx_vereine_meeting_response` = Zu-/Absage (keine Anwesenheit), eindeutig je Sitzung und Person. `llx_vereine_motion` = Antrag, eindeutig je Anwendung und `external_id`, mit Fingerabdruck gegen Wiederverwendung, `late` nach `motion_days` der Statuten. `decide()` nimmt an (hängt einen Tagesordnungspunkt an) oder lehnt ab. Fähigkeit `meetings` der Bindung |
| `class/vereineprofilerules.class.php`, `class/vereineprofiles.class.php` | Eigene Daten über die API (#164). Allowlist `FIELDS` (Kontaktdaten); `version()` = Prüfsumme der Felder, ein Auftrag mit anderem Stand ist ein Konflikt. `llx_vereine_profile_request` hält Änderungen und Kündigungen (eindeutig je Anwendung und `external_id`, Fingerabdruck), Begründung für das Mitglied und interne Notiz getrennt. Sofort übernommen nur Felder aus `VEREINE_PROFILE_DIRECT` (nie E-Mail); Übernehmen schreibt per SQL und löst `MEMBER_MODIFY` aus. Die Kündigung läuft über `VereineExits::plan()` (Grund resignation, Ende nach der Kündigungsregel). Fähigkeit `profile` |
| `class/portal/vereine.controller.class.php` | Seite „Mein Verein“ im Webportal von Dolibarr (#25), eingehängt über den Hook `initController` (Kontext `webportaldao`, ab Dolibarr 23) und `printTopMenu` (Kontext `webportal`). Nutzt `VereinePublications`, `VereineMeetingPortal`, `VereineBallots` wie die API; Fähigkeiten aus `VEREINE_PORTAL_CAPABILITIES` (`VereinePortal`) |
| `llx_vereine_ballot_result` | Auswertungen (#163): Schnappschuss als JSON, Nachweis-PDF (`VereineBallots::directory()`, Archivart `ballot`), Status provisional/confirmed/superseded. `confirm()` nimmt nur eine vorläufige Auswertung (bedingtes UPDATE) und ruft in derselben Transaktion `VereineMeetings::applyVote()` – dieselbe Wirkung wie eine von Hand eingetragene Abstimmung |
| `class/vereineballots.class.php` | Abstimmungen einer Generalversammlung (#160, #161): `llx_vereine_ballot` (Regeln als JSON beim Freigeben eingefroren), `_option` (feste Codes), `_right` (beim Öffnen eingefroren, je eingeladenem Mitglied, Halter = Mitglied oder Vollmacht), `_vote`. Stimmrecht wird mit einem bedingten UPDATE (`used_at IS NULL`) verbraucht, dazu UNIQUE(fk_right) und UNIQUE(fk_ballot, client, external_id). Über die App nur mit Anwesenheit laut `VereineAttendanceRules::presentAt()`. Regeln in `VereineBallotRules` |
| `class/vereinepublications.class.php` | Veröffentlichte Dokumente (#156, #157, #239): Zielgruppen board/members/public und `person` (`fk_adherent`). `VereinePublicationRules::pick()` gibt je Dokument die engste Zielgruppe, dann die neueste Fassung. Gekürzte Fassung = `llx_vereine_document_file.what = 'excerpt'` mit `fk_parent`. Änderungsfeed-Typ `document` (created/updated/revoked) in derselben Transaktion. Eine unterschriebene Fassung geht per Regel erst hinaus, wenn der Unterschriftslauf `done` ist |
| `class/vereineeventportal.class.php` | Veranstaltungen über die API (#165). `llx_vereine_event.public` 0 intern, 1 öffentlich, 2 nur Mitglieder (`VereineEventRules::visibility()`). Genau eine Anmeldestelle je Veranstaltung (`registration` none/dolibarr/external mit `external_ref`); das Modul bucht nie selbst mit. Helferdienste über `VereineShifts::signUp()` als `requested`, Quelle = Anwendung; Rückzug nur unbestätigt. Fähigkeit `events` |
| `admin/identities.php`, `class/vereineidentityrules.class.php`, `class/vereineaccess.class.php` | Verifizierte externe Identitäten: Bindungen in `llx_vereine_identity` (Anwendung, Kennung dort, Mandant; Mitglied, Partner und Antrag als getrennte, optionale Bezüge; Nachweis, Fähigkeiten, Widerruf), einmalige Einladungen in `llx_vereine_identity_invite` (nur der Hash des Codes, eine Stunde gültig, verfällt beim ersten Einlösen). `VereineAccess::check()` ist die eine Stelle, die über persönliche Aufrufe entscheidet: Recht der Anwendung, Bindung bei genau dieser Anwendung und diesem Mandanten, nicht widerrufen, Fähigkeit eingeschaltet, eigenes Objekt. Regeln in reinem PHP. Eigenes Recht `vereine:identity:use`; der Dienstzugang von API v1 bleibt unverändert |
| `admin/webhooks.php`, `class/vereinehookrules.class.php`, `class/vereinehooks.class.php` | Signierte Webhooks: Ziele in `llx_vereine_hook_target` (Adresse, Benutzer, Schlüssel samt Zweitschlüssel während einer Rotation, eigener Cursor auf dem Änderungsfeed), Aufträge in `llx_vereine_hook_delivery` (ein Auftrag je Ziel und Ereignis, Unique-Key). Die geplante Aufgabe `VereineHooks::runDue()` macht aus dem Feed Aufträge und stellt zu – nie innerhalb einer Fachtransaktion. Signatur HMAC-SHA-256 über Version, Zustellzeitpunkt und Body-Bytes; Wiederholung mit wachsenden Pausen, Adressprüfung (nur https, kein internes Netz ohne Ausnahme, Auflösung vor jedem Versuch) und Rechteprüfung des hinterlegten Benutzers vor jedem Versuch. Referenz-Empfänger in `docs/beispiele/webhook-empfaenger.php`, bewusst nicht im Paket |
| `class/vereinechangerules.class.php`, `class/vereinechanges.class.php` | Änderungsfeed für externe Anwendungen: ein Vermerk je Änderung in `llx_vereine_change` (Objektart, ID, Revision, Änderungsart, Zeitpunkt – nie der Inhalt), geschrieben in derselben Transaktion wie die Änderung, damit ein Rollback ihn mitnimmt. Der Cursor (Zeitpunkt, Zeile) ist undurchsichtig und hält einen Sicherheitsabstand von wenigen Sekunden ein, damit keine noch offene Transaktion hinter einen bestätigten Stand rutscht. Aufbewahrung 90 Tage; ein älterer Cursor führt zu `resync_required` statt zu einer stillen Lücke. Der Vollabgleich liefert nur IDs und markiert seinen Abschluss. Eigenes Recht `vereine:sync:read` |
| `assembly.php`, `class/vereineassemblyrules.class.php`, `class/vereineassembly.class.php` | Der geführte Ablauf einer Generalversammlung: 17 Schritte in den Phasen vorher, in der Sitzung und nachher, jeder mit Stand (erledigt, überfällig, jetzt dran, später, nicht nötig) und Frist. Die Regeln sind reines PHP; die Fakten liest `VereineAssembly::facts()` dort, wo sie ohnehin stehen – Einnahmen-Ausgaben-Rechnung, Rechnungsprüfung samt Unterschrift, Funktionsperioden, Sitzung mit Tagesordnung, Anwesenheit und Abstimmungen, Protokoll, Beschlussbuch, Behördenschreiben und Benutzergruppen. Offene Schritte erscheinen in „Was ist zu tun?“ |
| `events.php`, `admin/events.php`, `class/vereineeventrules.class.php`, `class/vereineevents.class.php` | Veranstaltungen aus Vorlagen: Vorlagen (`llx_vereine_event_template`) mit Punkten je Phase und Abstand in Tagen zum Veranstaltungstag (`llx_vereine_event_template_task`, beim Aktivieren mit Turnier und Vereinsfest befüllt); eine Veranstaltung (`llx_vereine_event`) wird ein Projekt von Dolibarr (`Project`, `usage_organize_event` nur wenn Dolibarr die Anmeldung führt) mit einer `Task` je Punkt der Checkliste (`llx_vereine_event_task`, Abhaken schreibt `progress` zurück); Fristen- und Fortschrittsrechnung in reinem PHP. Eine später geänderte Vorlage lässt laufende Veranstaltungen unberührt |
| `class/vereineshiftrules.class.php`, `class/vereineshifts.class.php` | Helferdienste einer Veranstaltung: Schichten (`llx_vereine_event_shift`) mit Tag, Uhrzeit, Plätzen und zuständiger Funktion, Einträge je Person (`llx_vereine_event_shift_entry`) mit den Ständen angefragt, eingeteilt, war da und abgesagt; Kapazität, Überschneidung und Stundenrechnung in reinem PHP. Nur „war da“ ist eine geleistete Stunde und damit die Grundlage für #7. Woher eine Anfrage kam (`source`), bleibt erhalten, damit nichts doppelt zählt (#165). Der Kurzbericht einer Veranstaltung entsteht in `VereineEvents::buildReport()` als PDF in `documents/vereine/veranstaltungen` |
| `duties.php`, `class/vereinedutyrules.class.php`, `class/vereineduties.class.php` | Fristen- und Aufgabenkalender: Katalog der wiederkehrenden Pflichten in `llx_vereine_duty` (beim Aktivieren mit den Pflichten aus VerG, EStG und BAO befüllt, jede mit zuständiger Funktion und Herleitung der Frist), Aufgaben je Vereinsjahr in `llx_vereine_duty_task` mit Agenda-Termin (`ActionComm`) und Dolibarrs eigener Erinnerung (`llx_actioncomm_reminder`); Fristenrechnung in reinem PHP (Vereinsjahr, Monate danach, fester Kalendertag, Wiederholung), Übergabe offener Aufgaben nach Funktionswechsel erst nach Bestätigung |
| `admin/api.php`, `class/vereineapirules.class.php` | Einrichtungsreiter *API*: Schnittstellen aus `docs/openapi.json` mit `x-vereine-rights`, Benutzer mit API-Schlüssel und die Schnittstellen, die ihre Rechte erlauben (Regeln in reinem PHP) |
| `admin/meetings.php`, `class/vereineminutesrules.class.php` | Sitzungsvorlagen je Art mit Pflichtpunkten (`llx_vereine_meeting_template`, sonst die vorgeschlagenen) und Texte je Tagesordnungspunkt (`llx_vereine_meeting_note`) mit Platzhaltern aus Anwesenheit und Abstimmungen, Regeln in reinem PHP; `VereineMeetings::items()` liefert die Texte fürs Protokoll |
| `class/vereineminutes.class.php` | Protokoll einer Sitzung: Entwurf als PDF, Endfassungen in `llx_vereine_meeting_minutes` mit Prüfsumme und Unterschriftslauf, Versand als E-Mail mit PDF an Vorstand oder alle Mitglieder; Vorsitz und Protokollführung an der Sitzung (`fk_chair`, `fk_keeper`) |
| `admin/signatures.php`, `class/vereinesignaturerules.class.php`, `class/vereinesignatures.class.php` | Unterschriften: wer welche Art von Dokument unterschreibt (Regeln in reinem PHP, Vorgaben aus den Musterstatuten), Unterschriftsläufe in `llx_vereine_signature` und `llx_vereine_signature_person` mit eingefrorener Prüfsumme, Unterschrift in Dolibarr mit Passwort (`dol_verifyHash`) oder Scan des unterschriebenen PDFs, Unterschriftenblatt als PDF in `documents/vereine/signatures` |
| `resolutions.php`, `class/vereineresolutionrules.class.php`, `class/vereineresolutions.class.php` | Beschlussbuch: jeder Beschluss aus einer Abstimmung mit Nummer, Organ, Ergebnis und Zahlen in `llx_vereine_resolution` (von `VereineMeetings::saveVote()` selbst geschrieben, alte Abstimmungen beim Aktivieren nachgetragen), nachgetragen werden Wortlaut, Kategorie, Gültigkeit, betroffenes Mitglied und Rechnung; Suche und Filter, CSV-Ausgabe; Folgen in `llx_vereine_resolution_task` als Aufgabe im Dolibarr-Kalender (`ActionComm`, am Mitglied) und als Vorschlag für die nächste Tagesordnung |
| `circulars.php`, `class/vereinecircularrules.class.php`, `class/vereinecirculars.class.php` | Umlaufbeschlüsse des Vorstands: nur mit dem Schalter `circular` in den Regeln der Statuten (aus, weil das Vereinsgesetz sie nicht regelt), Antrag mit Frist in `llx_vereine_circular`, Stimmen der Vorstandsmitglieder in `llx_vereine_circular_vote` (E-Mail mit Link über `CMailFile`, Erinnerung), Ergebnis mit einfacher Mehrheit ohne Stichentscheid, Widerspruch beendet den Umlauf, wenn die Statuten das verlangen; Eintrag im Beschlussbuch über `VereineResolutions::addFromCircular()` |
| `class/vereinemeetingdocrules.class.php`, `class/vereinemeetingdocs.class.php` | Unterlagen einer Sitzung: Nachweis je Abstimmung, unterschriebene Vollmacht je Anwesenheitseintrag und sonstige Unterlagen in `llx_vereine_meeting_document` mit Prüfsumme, Dateien unter `documents/vereine/meetings/<id>` (nur PDF oder Bild, höchstens 10 MB, nichts wird überschrieben); Zählliste als PDF wird ausgeliefert und nicht behalten; das Protokoll nennt sie als Anlagen |
| `class/vereineresolutiondocs.class.php` | Beschluss als PDF: ein Beschluss je Dokument (`documents/vereine/resolutions/beschluss-<id>.pdf`) mit Organ, Sitzung, Wortlaut, Stimmen, nötiger Mehrheit, Beschlussfähigkeit zur Uhrzeit der Abstimmung und der Funktionsperiode einer Wahl; Unterschriftslauf der Art `resolution` über `VereineSignatures`; Beschluss-Auszug mehrerer Beschlüsse wird ausgeliefert und nicht behalten |
| Vorschlagswerte aus den Einstellungen | Die Regeln rechnen, die Formulare zeigen nur: `VereineFunctionRules::endOfTerm()` (Ende einer Funktionsperiode, auch bei einer Wahl über `VereineFunctions::addTerm()`), `VereineExitRules::lastDay()` (letzter Tag eines Austritts), `VereineMeetingRules::addDays()` mit der Einladungsfrist (Tag einer neuen Sitzung), `VereineVoteRules::majority()` (nötige Mehrheit im Abstimmungsformular). Eingetragenes gewinnt immer |
| `class/vereinemailrules.class.php`, `class/vereinemail.class.php` | E-Mails des Vereins auf einem Weg: Absender `VEREINE_MAIL_FROM` (sonst Dolibarrs `MAIN_MAIL_EMAIL_FROM`), Versand über `CMailFile`, Antworten des Mailservers in Klartext übersetzt (Absender abgelehnt, Empfänger, Anmeldung, nicht erreichbar), Test-E-Mail in der Einrichtung; gescheiterte Einladungen werden mit `VereineMeetings::resend()` erneut gesendet und zählen ihre Versuche |
| Arten der Tagesordnungspunkte | `VereineMinutesRules::ITEM_KINDS` (Bericht, Besprechung, Beschluss, Wahl), Vorschlag aus der Überschrift mit `kindOf()`, gespeichert als `kind` in `llx_vereine_meeting_note` und beim Umsortieren der Tagesordnung mitgenommen; abgestimmt wird nur bei Beschluss und Wahl (`votes()`); die Einladungsvorschau nutzt `VereineMeetings::invitationText()` |
| `class/vereinepdf.class.php` | Gemeinsamer Kopf und Fuß der PDFs: Logo aus Dolibarr (`mycompany/logos`), Name, ZVR-Zahl, Anschrift, Seite x von y; Kopf und Fuß werden nach dem Inhalt auf jede Seite in freigehaltene Ränder gezeichnet |
| `class/vereinewaiting.class.php`, `core/boxes/box_vereine_waiting.php` | Startseite „Wartet auf mich“: offene Umlaufbeschlüsse, fehlende Unterschriften und Aufgaben des mit dem Benutzer verknüpften Mitglieds |
| Vereinbarungen | Aufgaben an einem Tagesordnungspunkt ohne Beschluss liegen in `llx_vereine_resolution_task` mit `fk_meeting` und `item` (`fk_resolution` 0); eine Abstimmung auf demselben Punkt übernimmt sie (`VereineResolutions::attachAgreements()`) |
| Schritte einer Sitzung | `VereineMeetingRules::steps()` (Planen, Einladen, In der Sitzung, Protokoll, Abschließen) mit genau einem Schritt „jetzt dran“ |
| `class/vereinetextrepair.class.php` | Reparatur beim Aktivieren: Zeilenumbrüche, die die Textfelder vor 0.5.3 als `\n` gespeichert haben |
| `class/vereinevoterules.class.php` | Abstimmungen und Wahlen: Arten, Mehrheit laut Statuten, Ergebnis mit Stimmengleichheit und Stichentscheid, reines PHP; gespeichert in `llx_vereine_meeting_vote`; Wirkung über `VereineFunctions::addTerm()` und `VereineStatutes::saveVersion()` |
| `class/vereineattendancerules.class.php` | Anwesenheit, Vollmachten (laut Statuten, nie im Vorstand, Bevollmächtigte anwesend) und Beschlussfähigkeit zu einer Uhrzeit, reines PHP; gespeichert in `llx_vereine_meeting_attendance` |
| `meetings.php`, `class/vereinemeetingrules.class.php`, `class/vereinemeetings.class.php` | Sitzungen: Regeln in reinem PHP (Formen, die die Statuten erlauben, Fristen, Empfänger: genau der Vorstand oder jedes aktive Mitglied, E-Mail oder Brief), Tabellen `llx_vereine_meeting` und `llx_vereine_meeting_invitation` als Nachweis, E-Mail über `CMailFile`, Briefe als PDF, Agenda-Termin |
| `class/vereinestatutetext.class.php` | Text der Statuten, reines PHP: Textfelder, Prüfung und die Abschnitte der Musterstatuten von Innen- und Finanzministerium, gefüllt mit Regeln, Funktionen, Mitgliedsarten und Austrittsregel; Fassungen in `llx_vereine_statute` mit PDF und Prüfsumme über `VereineStatutes` |
| `class/vereinestatuterules.class.php`, `class/vereinestatutes.class.php`, `admin/statutes.php` | Regeln der Statuten: Vorgaben aus den Musterstatuten in reinem PHP, Prüfungen und Hinweise; gespeichert als JSON in der Konstante `VEREINE_STATUTE_RULES`, Funktionsperioden in `llx_vereine_function.term_years`; Einrichtungsreiter *Statuten* |
| `class/vereinemailingrules.class.php`, `core/modules/mailings/vereine.modules.php` | Empfänger von Dolibarrs E-Mail-Kampagnen nach Status, Art, Funktion und Einwilligung, Minderjährige über Erziehungsberechtigte (Regeln in reinem PHP und die Auswahlklasse `mailing_vereine`, die Dolibarr in `core/modules/mailings` des Moduls findet) |
| `sql/update_*.sql` | Spalten für bestehende Tabellen; Dolibarr führt sie bei jeder Aktivierung aus und übergeht vorhandene Spalten |
| `class/vereineconsentrules.class.php` | Einwilligungstexte, aktuelle Einwilligung je Zweck, Prüfung eines Beitrittsantrags, reines PHP |
| `class/vereineconsents.class.php`, `admin/consents.php` | Einwilligungstexte mit Versionen (`llx_vereine_consent_text`), Einwilligungen und Widerrufe (`llx_vereine_consent`, nur ergänzt), Anträge nach externer Kennung (`llx_vereine_application`) |
| `class/vereinesepa.class.php`, `class/vereinesepastore.class.php` | Mandatsstand und Vorankündigung (reines PHP); Mandate und letzte Einzüge aus Dolibarr |
| `class/vereineexitrules.class.php` | Austrittsgründe und letzter Tag nach der Kündigungsregel der Statuten, reines PHP |
| `class/vereineexits.class.php` | Austritte in `llx_vereine_member_exit`, die Konstanten der Kündigungsregel, die geplante Aufgabe `runDue` |
| `class/vereinefeerun.class.php`, `fees_run.php` | Beitragslauf: Vorschau der fälligen Beiträge, Beitragsperioden und verknüpfte Rechnungen, eine je Zahler und Beginntag, letzte Beitragsrechnungen |
| `class/vereinefeemodel.class.php`, `admin/fees.php` | Beitragsmodell als Zusatzfelder von Dolibarrs Mitgliedsart (`vereine_fee_start_month`, `vereine_fee_proration`, `vereine_admission_fee`, `vereine_fee_product`; das Häkchen `vereine_fee_prorated` von 0.3.4 und 0.3.5 wird beim Aktivieren zu `month`) und sein Einrichtungsreiter |
| `class/vereinetaxassign.class.php` | Zusatzfeld `vereine_taxprofile` an Produkten und Rechnungszeilen, USt-Satz des Produkts, Profile der Zeilen, Abweichungen |
| `class/vereinethresholds.class.php` | Grenzen mit Gültigkeit und Ampel, reines PHP |
| `class/vereinecashregister.class.php` | Registrierkassenpflicht je Sphäre und Aufteilung von Barumsätzen auf Sphären, reines PHP |
| `class/vereinemembersummary.class.php` | Mitgliedschaftsstatus, Beitragsstand und Termine einer Mitglieds-Zusammenfassung, reines PHP |
| `class/vereinememberreport.class.php` | Liest eine Mitglieds-Zusammenfassung und ihre offenen Rechnungen; findet Mitglieder über Nummer oder E-Mail |
| `class/vereinewebsiteevents.class.php` | Löst `VEREINE_MEMBER_CHANGED` nur mit der Mitglieds-ID aus, für Dolibarrs Webhooks |
| `docs/openapi.json` | OpenAPI-3.0-Beschreibung jeder Schnittstelle; `tests/run.php` vergleicht sie mit der API-Klasse, `tests/runtime/openapi.py` mit den Antworten |
| `class/vereinethresholdreport.class.php`, `core/boxes/box_vereine_thresholds.php` | Einnahmen je Steuerprofil aus Rechnungen; Startseiten-Widget |
| `js/partners.js` | *Alle auswählen* und das Zeilenfenster der Abgleichsseite |
| `sql/` | Tabellen, beim Aktivieren angelegt und beim Deaktivieren behalten |
| `lib/vereine.lib.php` | Gemeinsame Hilfsfunktionen der Seiten |
| `vereineindex.php` | Übersicht unter Mitglieder |
| `admin/setup.php`, `admin/about.php` | Einrichtung und Über-Seite |
| `langs/de_DE/vereine.lang` | Alle Texte; `langs/en_US/vereine.lang` ist eine genaue Kopie, damit Dolibarr mit englischer Oberfläche Deutsch zeigt |
| `tests/run.php` | Tests ohne Dolibarr |
| `tests/runtime/` | Tests im laufenden Dolibarr 22, 23 und 24 |
| `scripts/` | Prüfungen, Paketbau, Release |

Logik, die sich ohne Dolibarr testen lässt, bleibt in Klassen aus reinem PHP;
Seiten und API lesen nur Eingaben, rufen diese Klassen auf und zeigen an.

## Daten

Die Vereinsdaten sind Dolibarr-Konstanten je Mandant (`VEREINE_REGISTER_NUMBER`,
`VEREINE_AUTHORITY`, `VEREINE_FOUNDED`, `VEREINE_NONPROFIT`, `VEREINE_PURPOSE`).
Deaktivieren behält sie. `VEREINE_COUNTRY_PROFILE` und `VEREINE_REGISTER_COURT`
aus Versionen vor 0.5.8 löscht die Aktivierung.

`llx_vereine_log` hält fest, was das Modul geändert hat, von wem und wann.
Nichts im Modul ändert oder löscht eine Zeile.

## Mitglieder und Geschäftspartner

- Dolibarr verknüpft ein Mitglied mit einem Geschäftspartner
  (`llx_adherent.fk_soc`; `Adherent::setThirdPartyId` löst jedes andere
  Mitglied). Eine Familie, die für mehrere Mitglieder zahlt, kann sich darüber
  also keinen Geschäftspartner teilen; stattdessen nennt das Mitgliedsfeld
  `vereine_fee_payer` den Zahler (siehe Beiträge).
- Geschäftspartner entstehen mit Dolibarrs `Societe::create_from_member` und
  werden mit `Adherent::setThirdPartyId` verknüpft; einzelne Felder ändern sich
  über `CommonObject::setValueFrom`, Kategorien über `Categorie::add_type`.
- Die Kategorien *Mitglied*, *Ehemaliges Mitglied* (Kunde) und
  *Erziehungsberechtigt* (Kontakt) entstehen beim Aktivieren und werden über die
  Kennungen in `VEREINE_CATEGORY_MEMBER`, `VEREINE_CATEGORY_FORMER`,
  `VEREINE_CATEGORY_GUARDIAN` wiedergefunden; der Verein darf sie umbenennen.
- Erziehungsberechtigte sind eine Kontakt-Kategorie, keine Kontaktrolle am
  Geschäftspartner: Rollen bietet Dolibarr nur hinter der versteckten Option
  `MAIN_SUPPORT_SHARED_CONTACT_BETWEEN_THIRDPARTIES`, die es selbst als instabil
  bezeichnet.
- Ein Trigger lässt nie die Aktion am Mitglied scheitern. Probleme landen als
  `partner_error` im Protokoll.
- Dolibarrs Mitgliedskarte verknüpft einen Geschäftspartner ohne Trigger
  (`Societe::create_from_member` und `Adherent::setThirdPartyId` schreiben
  `fk_soc` mit einfachem SQL). Der Hook `doActions` merkt sich die Verknüpfung
  vor Dolibarrs Aktion; `addMoreActionsButtons` läuft später in derselben Anfrage
  mit neu geladenem Mitglied und ruft `VereinePartnerService::onMemberCardLink`
  auf. Dolibarrs Aktionen werden nicht ersetzt, und das Ansehen der Karte ändert
  nichts.
- Rechnungs-PDFs: Der Hook `beforePDFCreation` ergänzt im Speicher die Hinweise
  der Steuerprofile und die ZVR-Zahl in `note_public` der Rechnung, die
  Dolibarrs Vorlagen drucken; `afterPDFCreation` stellt den Hinweis zurück. Keine
  Vorlage wird geändert, nichts wird gespeichert.
- Die Abgleichsseite druckt die Schritte jeder Zeile als echte Formulare und
  Links (mit CSRF-Token) nach ihrem Hauptformular, weil Formulare nicht
  verschachtelt sein dürfen; `js/partners.js` zeigt sie nur in einem
  jQuery-UI-Fenster und hakt *Alle auswählen* an. Die Laufzeit-Tests senden diese
  Formulare genau wie ein Browser.

## Beiträge

- Das Beitragsmodell sind vier Zusatzfelder von Dolibarrs Mitgliedsart (siehe
  Dateien); Betrag und Dauer bleiben Dolibarrs eigene. `VereineFeeRules`
  berechnet Periode und Betrag in reinem PHP.
- Ein Beitragslauf legt je Mitglied und Periode an, was Dolibarrs Mitgliedskarte
  bei *Neues Abonnement* mit *Rechnung erstellen* anlegt
  (`Adherent::subscriptionComplementaryActions`): eine Beitragsperiode mit
  `Adherent::subscription()` und eine freigegebene Rechnung, deren
  `linked_objects` die Periode enthalten. Periode und Rechnung entstehen in einer
  Transaktion. Die Rechnungszeile ist die Beitragsleistung der Mitgliedsart
  (Dolibarrs `ADHERENT_PRODUCT_ID_FOR_SUBSCRIPTIONS`, wenn die Art keine hat) mit
  dem Bruttobetrag, damit das Steuerprofil der Leistung auf die Zeile kommt.
- `llx_subscription` hat einen eindeutigen Schlüssel auf Mitglied und Beginntag,
  und eine angelegte Periode ist nicht mehr fällig: Ein zweiter Lauf für
  denselben Tag legt nichts an.

- Ermäßigungen sind Regeln in `llx_vereine_fee_discount` (nach dem Alter am
  ersten Tag einer Periode oder mit Nachweis) und Mitgliedsfelder
  (`vereine_fee_exempt`, `vereine_fee_exempt_reason`, `vereine_fee_proof`,
  `vereine_fee_proof_until`). `VereineFeeDiscounts` wählt höchstens eine je
  Beitrag: Befreiung, gültiger Nachweis, Alter. Die Ermäßigung senkt den Betrag
  der Mitgliedsart vor der anteiligen Berechnung; eine Befreiung entfällt auch
  die Aufnahmegebühr. Ein Beitrag von 0 speichert die Periode ohne Rechnung, was
  die Mitglieds-Zusammenfassung als bezahlt zählt.
- Familien: Das Mitgliedsfeld `vereine_fee_payer` (Zusatzfeld vom Typ Verweis auf
  einen Geschäftspartner) nennt, wer die Beitragsrechnungen bekommt; ohne es der
  eigene Geschäftspartner des Mitglieds. Aktive Mitglieder mit demselben Zahler
  sind eine Familie. Die Familienregel (`VEREINE_FEE_FAMILY_MODE`,
  `VEREINE_FEE_FAMILY_VALUE`) kommt nach der eigenen Ermäßigung und zählt nur
  Mitglieder, die einen Beitrag zahlen:
  - `percent`: Das Mitglied mit dem höchsten Jahresbeitrag nach eigener
    Ermäßigung am ersten Tag der Periode zahlt voll (gleiche Beiträge: die
    niedrigste Mitglieds-ID), jedes andere den Prozentsatz weniger, vor der
    anteiligen Berechnung;
  - `cap`: Die Beiträge einer Familie, deren Perioden im selben Beitragsjahr
    beginnen (ab dem Beginnmonat der Mitgliedsart), teilen sich, was vom
    Höchstbetrag nach den schon gespeicherten Perioden dieses Jahres übrig ist,
    außer solchen mit aufgegebener Beitragsrechnung.
- Beiträge für denselben Zahler mit demselben Beginntag sind eine Transaktion:
  jede Beitragsperiode, dann eine Rechnung mit `linked_objects['subscription']`
  mit allen (Dolibarr 22 bis 24 nehmen dort eine Liste an) und einer Zeile je
  Mitglied, benannt, wenn die Rechnung mehrere Mitglieder oder einen Zahler
  betrifft. Perioden laufen von der ältesten an, eine gescheiterte Rechnung
  stoppt also die späteren Perioden ihrer Mitglieder.

- Austritte: `llx_vereine_member_exit` hält Grund, Tag der Kündigung oder
  Entscheidung, letzten Tag und Status (`planned`, `done`, `cancelled`). Der
  letzte Tag einer Kündigung folgt der Kündigungsregel
  (`VEREINE_EXIT_NOTICE_MONTHS`, `VEREINE_EXIT_AT`, `VEREINE_EXIT_START_MONTH`);
  die anderen Gründe nehmen den eingetragenen Tag. Am letzten Tag läuft
  `Adherent::exclude()` (Ausschluss) oder `Adherent::resiliate()` (alle anderen)
  – sofort, wenn der Tag da ist, sonst über die geplante Aufgabe
  `VereineCronExits` (Dolibarrs Modul *Geplante Aufgaben*) oder den Knopf im
  Reiter des Mitglieds. Der Beitragslauf legt keine Periode an, die nach dem
  letzten Tag beginnt.

- SEPA-Lastschrift: Das Mandat ist das Standard-Bankkonto des Zahlers in
  Dolibarr (`llx_societe_rib`, Typ `ban`, `rum`, `date_rum`). `VereineSepa`
  zählt es 36 Monate nach der Unterschrift oder dem letzten verarbeiteten Einzug
  als abgelaufen (`llx_prelevement_demande.date_traite`). Mit aktivem Modul
  `prelevement` schreibt der Beitragslauf die Vorankündigung in `note_public` der
  Rechnung, setzt die Zahlungsart `PRE` und ruft nach dem Commit
  `CommonInvoice::demande_prelevement()` mit dem Bankkonto des Mandats auf; ein
  gescheiterter Einzugsauftrag lässt die Rechnung, wie sie ist. Bankauftrag und
  Datei bleiben Dolibarrs eigene.

### Beitragsrechnungen erkennen

Eine Kundenrechnung ist eine Beitragsrechnung, wenn `llx_element_element` sie
mit einer Beitragsperiode verknüpft:

```sql
SELECT ee.fk_target AS invoice_id, s.fk_adherent AS member_id, s.dateadh, s.datef
FROM llx_element_element AS ee
INNER JOIN llx_subscription AS s ON s.rowid = ee.fk_source
WHERE ee.sourcetype = 'subscription' AND ee.targettype = 'facture'
```

Dolibarr schreibt diese Verknüpfung selbst, wenn eine Beitragsrechnung auf der
Mitgliedskarte entsteht, und der Beitragslauf schreibt sie genauso. Andere
Module – etwa ein Mahnwesen, das Mitgliedsbeiträge anders behandelt als
Verkäufe – brauchen also nichts von diesem Modul, um sie zu lesen. Die
Website-API zeigt sie als `fee` an jeder Rechnung.

## Regeln für jede Änderung

- Nichts ändert sich still: Läufe und Berichte zeigen zuerst eine Vorschau und
  werden protokolliert.
- Besonders schützenswerte Personendaten (Geburtsdaten, vbPK) bekommen ein
  eigenes Recht und werden verschlüsselt gespeichert.
- Funktionen neuerer Dolibarr-Versionen werden erkannt, nicht aus einer
  Versionsnummer geschlossen.
- Texte stehen nur in `langs/de_DE/vereine.lang`; `langs/en_US/vereine.lang`
  ist eine genaue Kopie (`python scripts/sync_langs.py`, geprüft von
  `tests/run.php`).
