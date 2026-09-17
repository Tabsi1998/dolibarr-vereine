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
| `admin/api.php`, `class/vereineapirules.class.php` | Einrichtungsreiter *API*: Schnittstellen aus `docs/openapi.json` mit `x-vereine-rights`, Benutzer mit API-Schlüssel und die Schnittstellen, die ihre Rechte erlauben (Regeln in reinem PHP) |
| `admin/meetings.php`, `class/vereineminutesrules.class.php` | Sitzungsvorlagen je Art mit Pflichtpunkten (`llx_vereine_meeting_template`, sonst die vorgeschlagenen) und Texte je Tagesordnungspunkt (`llx_vereine_meeting_note`) mit Platzhaltern aus Anwesenheit und Abstimmungen, Regeln in reinem PHP; `VereineMeetings::items()` liefert die Texte fürs Protokoll |
| `class/vereineminutes.class.php` | Protokoll einer Sitzung: Entwurf als PDF, Endfassungen in `llx_vereine_meeting_minutes` mit Prüfsumme und Unterschriftslauf, Versand als E-Mail mit PDF an Vorstand oder alle Mitglieder; Vorsitz und Protokollführung an der Sitzung (`fk_chair`, `fk_keeper`) |
| `admin/signatures.php`, `class/vereinesignaturerules.class.php`, `class/vereinesignatures.class.php` | Unterschriften: wer welche Art von Dokument unterschreibt (Regeln in reinem PHP, Vorgaben aus den Musterstatuten), Unterschriftsläufe in `llx_vereine_signature` und `llx_vereine_signature_person` mit eingefrorener Prüfsumme, Unterschrift in Dolibarr mit Passwort (`dol_verifyHash`) oder Scan des unterschriebenen PDFs, Unterschriftenblatt als PDF in `documents/vereine/signatures` |
| `resolutions.php`, `class/vereineresolutionrules.class.php`, `class/vereineresolutions.class.php` | Beschlussbuch: jeder Beschluss aus einer Abstimmung mit Nummer, Organ, Ergebnis und Zahlen in `llx_vereine_resolution` (von `VereineMeetings::saveVote()` selbst geschrieben, alte Abstimmungen beim Aktivieren nachgetragen), nachgetragen werden Wortlaut, Kategorie, Gültigkeit, betroffenes Mitglied und Rechnung; Suche und Filter, CSV-Ausgabe; Folgen in `llx_vereine_resolution_task` als Aufgabe im Dolibarr-Kalender (`ActionComm`, am Mitglied) und als Vorschlag für die nächste Tagesordnung |
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
