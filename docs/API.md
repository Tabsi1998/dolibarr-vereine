# REST-API

Das Modul ergänzt Schnittstellen unter Dolibarrs REST-API bei
`https://<dolibarr>/api/index.php/vereine/`. Sie brauchen Dolibarrs Modul
*API REST* und einen Benutzer mit dem Recht **Vereinsübersicht und Vereinsdaten
lesen** (`vereine > association > read`). Die Mitglieder-Schnittstellen brauchen
zusätzlich **Mitglieder-Zusammenfassung für die Website über die API lesen**
(`vereine > website > read`).

[`openapi.json`](openapi.json) beschreibt jede Schnittstelle als OpenAPI 3.0.
Die Laufzeit-Tests vergleichen jede Antwort des Moduls damit, in Dolibarr 22, 23
und 24; ein Feld, das dort nicht steht, lässt die Prüfung scheitern.

Anmelden mit dem API-Schlüssel des Benutzers im Header `DOLAPIKEY`. Die API von
einem Server aus aufrufen, nie aus dem Browser: Wer den Schlüssel sieht, handelt
als dieser Benutzer. Seit Dolibarr 24 sind die Login-Schnittstellen
standardmäßig aus, der Schlüssel ist also der Weg hinein.

| Antwort | Bedeutung |
| --- | --- |
| 200 | JSON wie unten beschrieben |
| 400 | Ein Parameter fehlt, liegt außerhalb des Bereichs oder ist falsch geformt, etwa eine ungültige E-Mail-Adresse oder ein Zeitpunkt ohne Zeitzone |
| 401 | Kein oder unbekannter API-Schlüssel |
| 403 | Dem Benutzer fehlt das Recht |
| 404 | Kein solches Mitglied |
| 409 | Mehrere Mitglieder passen |
| 501 | Das Modul Vereine ist deaktiviert |

`api_version` steigt, wenn ein Feld seine Bedeutung ändert oder wegfällt. Neue
Felder können in jeder Version dazukommen; Clients sollen Felder, die sie nicht
kennen, übergehen.

## GET /vereine/status

Modulversion und API-Version – ein günstiger Weg, die Verbindung zu testen.

```json
{
  "module_version": "0.5.8-beta",
  "api_version": 1,
  "country_profile": "AT",
  "country_profile_complete": true,
  "server_time": "2026-09-17T08:00:00Z"
}
```

`server_time` ist Dolibarrs Uhr in UTC – vor einem Website-Abgleich nehmen und
beim nächsten als `changed_since` verwenden (siehe unten). `country_profile` und
`country_profile_complete` sind veraltet: immer `AT` und `true`, sie entfallen
mit 1.0.

## GET /vereine/organization

Der Verein, zum Beispiel für das Impressum einer Website. Name, Anschrift und
Kontakt kommen aus den Unternehmensdaten von Dolibarr, der Rest aus der
Einrichtung des Moduls.

```json
{
  "country_profile": "AT",
  "country_profile_complete": true,
  "name": "THE LION SQUAD",
  "register": { "kind": "ZVR", "number": "123456789", "court": "" },
  "authority": "Landespolizeidirektion Tirol",
  "address": { "street": "Musterweg 1", "zip": "6020", "town": "Innsbruck", "country_code": "AT" },
  "email": "office@example.org",
  "phone": "",
  "url": "https://example.org",
  "founded": "2019-03-01",
  "nonprofit": true,
  "purpose": "Förderung des E-Sports",
  "fiscal_year_start_month": 1
}
```

| Feld | Inhalt |
| --- | --- |
| `country_profile` | Immer `AT`; veraltet, entfällt mit 1.0 |
| `country_profile_complete` | Immer `true`; veraltet, entfällt mit 1.0 |
| `register.kind` | Immer `ZVR` |
| `register.number` | ZVR-Zahl (Ziffern); leer, wenn nicht eingetragen |
| `register.court` | Immer leer; veraltet, entfällt mit 1.0 |
| `authority` | Vereinsbehörde |
| `founded` | `JJJJ-MM-TT` oder leer |
| `fiscal_year_start_month` | 1 bis 12, aus den Unternehmensdaten von Dolibarr |

Jedes Textfeld ist ein String, leer, wenn nicht eingetragen – nie `null`.

## GET /vereine/taxprofiles

Die Steuerprofile des Vereins: Sphäre und USt-Behandlung mit Rechtsgrundlage,
Satz und Rechnungshinweis. Profile mit `standard` hat das Modul vorgeschlagen;
der Verein darf sie geändert haben. Wie eine Tätigkeit eingeordnet wird, bleibt
Entscheidung des Vereins.

```json
[
  {
    "id": 1,
    "code": "MITGLIEDSBEITRAG",
    "label": "Mitgliedsbeitrag (echt)",
    "sphere": "ideal",
    "sphere_basis": "§§ 34 bis 47 BAO",
    "treatment": "nonbusiness",
    "treatment_basis": "kein Leistungsaustausch",
    "rate": 0,
    "note": "Echter Mitgliedsbeitrag ohne Gegenleistung, nicht umsatzsteuerbar.",
    "active": true,
    "standard": true
  }
]
```

| Feld | Inhalt |
| --- | --- |
| `id` | Wert des Zusatzfelds `vereine_taxprofile` an Produkten und Rechnungszeilen (`array_options.options_vereine_taxprofile` in Dolibarrs eigener API) |
| `code` | Feste Kennung aus Großbuchstaben, Ziffern und `_` |
| `sphere` | `ideal`, `assets`, `essential` (§ 45 Abs. 2 BAO), `auxiliary` (§ 45 Abs. 1 BAO), `festival` (§ 45 Abs. 1a BAO), `harmful` (§ 45 Abs. 3 BAO) |
| `treatment` | `nonbusiness`, `hobby` (Liebhaberei), `small_business` (§ 6 Abs. 1 Z 27 UStG), `sport` (§ 6 Abs. 1 Z 14 UStG), `reduced10`, `reduced13`, `standard20` |
| `rate` | USt-Satz in Prozent, eine Zahl |
| `note` | Rechnungshinweis, kann leer sein |
| `active`, `standard` | Wahrheitswerte |

## GET /vereine/thresholds

Grenzen eines Kalenderjahres (`?year=2026`, ohne Angabe das laufende Jahr).
Braucht zusätzlich das Recht, Rechnungen zu lesen. Einnahmen zählen aus
freigegebenen und bezahlten Kundenrechnungen, Gutschriften und
Ersatzrechnungen, jede Zeile über ihr Steuerprofil; Zeilen ohne Profil stehen in
`unassigned` und zählen nicht.

```json
{
  "year": 2026,
  "thresholds": [
    {
      "code": "small_business",
      "year": 2026,
      "limit": 55000,
      "gross": true,
      "tolerance": 10,
      "amount": 60000,
      "remaining": -5000,
      "ratio": 1.0909,
      "status": "tolerance",
      "basis": "§ 6 Abs. 1 Z 27 UStG",
      "source": "https://www.jusline.at/gesetz/ustg/paragraf/6",
      "previous_exceeded": false
    }
  ],
  "unassigned": { "net": 500, "gross": 600, "lines": 1 }
}
```

| Feld | Inhalt |
| --- | --- |
| `code` | `small_business` (§ 6 Abs. 1 Z 27 UStG) oder `harmful_business` (§ 45a BAO) |
| `gross` | Ob Bruttobeträge verglichen werden; bei `harmful_business` vergleicht das Modul brutto, um früh zu warnen |
| `status` | `ok` unter 80 %, `near` bis zur Grenze, `tolerance` darüber innerhalb der Toleranz, `exceeded` |
| `previous_exceeded` | Nur `small_business`: Das Vorjahr lag über der Grenze, die Befreiung gilt also nicht |

`cash_register` sagt je Sphäre, ob eine Registrierkasse nötig ist:

```json
"cash_register": {
  "turnover_limit": 15000,
  "cash_limit": 7500,
  "small_canteen_limit": 45000,
  "small_canteen_days": 52,
  "spheres": [
    { "sphere": "harmful", "turnover": 60000, "cash": 14888.34, "status": "required" }
  ],
  "unassigned_cash": 148.88
}
```

`status` ist `not_relevant` (ideeller Bereich, Vermögensverwaltung), `exempt`
(unentbehrlicher Hilfsbetrieb), `exempt_festival` (kleines Vereinsfest innerhalb
von 72 Stunden im Jahr), `ok`, `near` (beide ab 80 %) oder `required`. Umsatz
zählt brutto; Barumsätze sind Zahlungen des Jahres in bar, mit Karte, Scheck oder
online, aufgeteilt auf die Sphären der bezahlten Rechnung.

Antwortet 400 für ein Jahr vor 2000 oder nach 2100.

## Ein Benutzer für die Website

Die Website bekommt einen eigenen Dolibarr-Benutzer, der genau das lesen kann,
was die Website zeigt. Dolibarrs eigene Schnittstellen wie `/members` oder
`/invoices` beantworten ihm mit 403, weil ihm die Rechte für Mitglieder,
Geschäftspartner und Rechnungen fehlen: Die Mitglieds-Zusammenfassung unten ist
alles, was er von einem Mitglied sieht.

1. *Start > Benutzer & Gruppen > Neuer Benutzer*: Login zum Beispiel `website`,
   kein Administrator. Der Benutzer meldet sich nie an, ein starkes Passwort
   genügt.
2. Reiter *Berechtigungen*, Modul *Vereine (Österreich)*: **Vereinsübersicht und
   Vereinsdaten lesen** und **Mitglieder-Zusammenfassung für die Website über die
   API lesen** anhaken. Sonst nichts.
3. *Ändern* auf der Benutzerkarte: **API-Schlüssel** erzeugen, speichern und nur
   auf dem Server der Website ablegen, etwa als `DOLIBARR_API_KEY` in der `.env`.
4. Vom Server der Website aus testen:

```bash
curl --fail -H "DOLAPIKEY: $DOLIBARR_API_KEY" \
  "https://erp.example.org/api/index.php/vereine/members/lookup?ref=1"
```

Wer den Schlüssel hat, kann die Zusammenfassung jedes Mitglieds lesen und
Mitglieder über ihre E-Mail-Adresse finden. Behandeln wie ein Passwort: nie in
den Browser, nie in ein Repository.

## GET /vereine/membershipfees

Die verwendeten Mitgliedsarten mit ihrem Beitrag, für „Mitglied werden“ auf
einer Website. Braucht das Website-Recht.

```json
[
  {
    "id": 2,
    "label": "Ordentliches Mitglied",
    "description": "Für alle, die mitspielen.",
    "for": "both",
    "subscription_required": true,
    "amount": 60,
    "amount_editable": false,
    "duration": { "value": 1, "unit": "y" },
    "year_starts_month": 1,
    "prorated": true,
    "proration": "month",
    "admission_fee": 20,
    "currency": "EUR"
  }
]
```

| Feld | Inhalt |
| --- | --- |
| `description` | Öffentliche Beschreibung der Mitgliedsart, als reiner Text |
| `for` | `natural` (Personen), `legal` (Firmen und Vereine) oder `both` |
| `amount` | Beitrag je Periode; `null`, wenn die Art keinen festlegt oder kein Abonnement braucht |
| `amount_editable` | Das Mitglied darf einen anderen Betrag zahlen |
| `duration` | Länge einer Periode: `unit` `y` Jahre, `m` Monate, `w` Wochen, `d` Tage |
| `year_starts_month` | Monat, in dem das Beitragsjahr beginnt, `0`, wenn jedes Mitglied ab dem Eintritt zahlt |
| `proration` | Wie ein Eintritt während des Beitragsjahres zahlt: `none` den vollen Betrag; `month`, `quarter` oder `half_year` die restlichen Monate, Quartale oder Halbjahre des Beitragsjahres, das des Eintritts voll gezählt. `half_year`: Eintritt im ersten Halbjahr zahlt voll, im zweiten die Hälfte |
| `prorated` | `true`, außer `proration` ist `none`; aus Version 0.3.4 behalten |
| `admission_fee` | Einmal, mit dem ersten Beitrag; `0`, wenn es keine gibt |

Beispiel: `amount` 60, Jahr ab Jänner, anteilig, Eintritt am 15. März – der erste
Beitrag deckt März bis Dezember, 10 von 12 Monaten, also 50 plus Aufnahmegebühr.

## GET /vereine/board

Die Funktionen des Vereins mit ihren heutigen Inhabern, in der Reihenfolge des
Funktionskatalogs – für eine Vorstandsseite. Braucht das Recht, die
Mitglieds-Zusammenfassung für die Website zu lesen.

```json
[
  {
    "code": "obmann", "label": "Obmann/Obfrau", "board": true, "represents": true, "auditor": false,
    "holders": [{ "name": "Paula Beispiel", "since": "2026-09-17" }]
  },
  {
    "code": "rechnungspruefung", "label": "Rechnungsprüfer:in", "board": false, "represents": false, "auditor": true,
    "holders": [{ "name": null, "since": "2025-03-01" }, { "name": null, "since": "2025-03-01" }]
  }
]
```

Ein `name` ist `null`, außer die Website darf ihn zeigen, eingestellt unter
*Einrichtung > Vereine > Funktionen*:

- **nur mit Einwilligung** (Standard): Die letzte Einwilligung des Inhabers zum
  gewählten Einwilligungstext ist erteilt;
- **Vorstand immer mit Namen**: für Funktionen des Vorstands auch ohne
  Einwilligung, für eine Website, die den Vorstand offenlegen muss (§ 25 Abs. 2
  MedienG, Websites, die über die Darstellung des Vereins hinausgehen); andere
  Funktionen brauchen weiter die Einwilligung.

Eine leere Liste `holders` heißt, die Funktion ist unbesetzt. Wo `name` `null`
ist, die Funktion ohne Namen zeigen.

## GET /vereine/consents

Die Einwilligungstexte, denen eine Person jetzt zustimmen kann – einer je Zweck,
in der neuesten Version. Der Verein schreibt sie unter *Einrichtung > Vereine >
Einwilligungen*; ein geänderter Text wird eine neue Version. Braucht das Recht,
die Mitglieds-Zusammenfassung für die Website zu lesen oder Beitrittsanträge
anzulegen.

```json
[
  { "code": "fotos", "label": "Fotos auf der Website", "version": 2, "text": "Fotos von Veranstaltungen, ..." },
  { "code": "newsletter", "label": "Newsletter", "version": 1, "text": "Ich möchte den Newsletter ..." }
]
```

`text` neben dem Ankreuzfeld zeigen und `code` und `version` mit dem Antrag
zurückschicken. Ein Antrag mit einer älteren Version bekommt 400: die Texte
direkt vor dem Anzeigen des Formulars neu lesen.

## POST /vereine/applications

Ein Beitrittsantrag von der Website. Er legt ein Mitglied **im Entwurf** mit den
erteilten Einwilligungen an; der Verein prüft und gibt das Mitglied in Dolibarr
frei, die Website kann das nie. Dem Formular einen eigenen API-Benutzer mit nur
den Rechten *Vereinsübersicht und Vereinsdaten lesen* und *Beitrittsanträge über
die API anlegen* geben.

```json
{
  "external_id": "web-2026-0042",
  "firstname": "Amelie",
  "lastname": "Beispiel",
  "email": "amelie@example.org",
  "birth": "2001-04-30",
  "address": "Hauptplatz 1",
  "zip": "6020",
  "town": "Innsbruck",
  "country_code": "AT",
  "type_id": 2,
  "note": "Ich spiele gern Schach.",
  "consents": [{ "code": "fotos", "version": 2 }]
}
```

Antwort:

```json
{ "id": 57, "ref": "57", "status": "draft", "duplicate": false, "document": true }
```

| Feld | Inhalt |
| --- | --- |
| `external_id` | Die eigene Kennung des Antrags auf der Website, bis 64 Buchstaben, Ziffern und `. _ : -`. Nochmals mit derselben Kennung geschickt – etwa nach einem Netzwerkfehler – kommt das beim ersten Mal angelegte Mitglied mit `duplicate: true` zurück; nichts Neues entsteht |
| `morphy`, `company` | `phy` (Standard) oder `mor` mit dem Namen der juristischen Person |
| `firstname`, `lastname`, `email` | Pflicht |
| `type_id` | Pflicht, eine aktive Mitgliedsart aus [`GET /vereine/membershipfees`](#get-vereinemembershipfees), offen für diese Art von Person |
| `birth` | `JJJJ-MM-TT`, freiwillig; Ermäßigungen nach Alter brauchen es. Pflicht, solange die Statuten ein Mindestalter festlegen (Einrichtungsreiter *Statuten*): Jüngere werden mit 400 abgelehnt |
| `note` | Nachricht der antragstellenden Person, gespeichert als private Notiz am Mitglied |
| `consents` | Die erteilten Einwilligungen, jede mit der gezeigten Version; nicht angekreuzte Zwecke fehlen. Je Einwilligung dürfen `granted_at` (Zeitpunkt der Zustimmung, ISO 8601), `form` (Formular oder Seite) und `reference` (Kennung des Vorgangs) mitkommen – der Nachweis nach Art. 7 Abs. 1 DSGVO. Keine IP-Adresse |
| `signature` | Freiwillig: die am Bildschirm gezeichnete Unterschrift als PNG, base64 kodiert, höchstens 200000 Bytes. Sie steht dann im Antrags-PDF. Das ist eine einfache elektronische Signatur (Art. 25 eIDAS), keine qualifizierte |

Antwortet 400 mit jedem Problem in der Meldung, 403 ohne das Recht.
`document` sagt, ob der Mitgliedsantrag als PDF bei den Dokumenten des Mitglieds abgelegt wurde – mit dem Vermerk, dass er über die Website kam, und mit der Unterschrift, wenn eine mitgeschickt wurde. Scheitert nur das PDF, bleibt der Antrag trotzdem bestehen; das Modulprotokoll hält die Prüfsumme fest.
Einwilligungen von Kindern: Bei einem Online-Formular, das sich direkt an Kinder
richtet, kann ein Kind in Österreich ab 14 Jahren selbst einwilligen (§ 4 Abs. 4
DSG); bei Jüngeren die Eltern fragen.


## GET /vereine/members/{id}/consents

Der Stand je Zweck für dieses Mitglied, für eine Seite „Meine Einwilligungen“. Braucht das Recht, die Mitglieds-Zusammenfassung zu lesen.

```json
[
  { "code": "fotos", "label": "Fotos auf der Website", "state": "given", "version": 2, "current_version": 2,
    "moment": "2026-09-22T19:30:00+02:00", "can_give": false, "can_withdraw": true }
]
```

| Feld | Inhalt |
| --- | --- |
| `state` | `none` (noch nie entschieden), `given` oder `withdrawn` |
| `version` | Die Version, der das Mitglied zugestimmt hat |
| `current_version` | Die aktuelle Version des Textes; `0`, wenn der Zweck nicht mehr aktiv ist |
| `can_give`, `can_withdraw` | Was jetzt möglich ist – so muss die Website nicht selbst rechnen |

## POST /vereine/members/{id}/consents

Erteilen oder widerrufen. Braucht das Recht, Beitrittsanträge anzulegen.

```json
{ "code": "newsletter", "decision": "given", "version": 1,
  "granted_at": "2026-09-23T10:15:00+02:00", "form": "Mein Konto", "reference": "web-c-77" }
```

Antwort:

```json
{ "code": "newsletter", "state": "given", "version": 1, "recorded": true }
```

- **Zustimmen** geht nur mit der Version, die der Person gezeigt wurde. Eine ältere Version wird mit 400 abgelehnt – niemand stimmt still einer neuen Fassung zu.
- **Widerrufen** braucht keine Version und funktioniert auch dann, wenn es inzwischen eine neue Fassung gibt (Art. 7 Abs. 3 DSGVO).
- `reference` macht den Auftrag wiederholbar: derselbe Auftrag nochmals geschickt antwortet mit `recorded: false` und legt keinen zweiten Eintrag an.
- Eine Zustimmung, die älter ist als ein schon gespeicherter Widerruf, wird mit 409 abgelehnt; der Widerruf bleibt.
- Für welche Person eine Website handeln darf, bindet erst die geprüfte Client-Zuordnung (#153); bis dahin ist die Website dafür verantwortlich, nach dem richtigen Mitglied zu fragen.

## GET /vereine/members

Zusammenfassungen aller Mitglieder nach ID, für eine Website, die eine eigene
Kopie führt. `?limit=` (1 bis 100, Standard 100) und `?page=` (ab 0) blättern.

Mit `?changed_since=2026-09-17T08:00:00Z` kommen nur die Mitglieder zurück,
deren Zusammenfassung sich zu oder nach diesem Zeitpunkt geändert hat. Der
Zeitpunkt braucht eine Zeitzone (`Z` oder eine Verschiebung wie `+02:00`); alles
andere ergibt 400.

Eine Zusammenfassung zählt als geändert – und ihr `updated_at` rückt vor –, wenn

- sich das Mitglied ändert: Status, Mitgliedsart, Geschäftspartner, Name, Nummer,
  bezahlt bis;
- sich seine Mitgliedsart ändert, zum Beispiel der Beitrag;
- eine Beitragsperiode des Mitglieds dazukommt oder sich ändert;
- eine Rechnung seines Geschäftspartners oder eine Beitragsrechnung, die mit
  einer seiner Beitragsperioden verknüpft ist (Familienrechnung eines Zahlers),
  freigegeben, geändert, bezahlt oder aufgegeben wird;
- eine Zahlung auf eine solche Rechnung dazukommt oder sich ändert;
- sich die Beitragsfelder des Mitglieds ändern: Befreiung, Nachweis, Zahler
  (*Beiträge zahlt*);
- ein Austritt eingetragen, vollzogen oder zurückgenommen wird;
- eine Funktion des Mitglieds beginnt oder endet;
- ein Beitrag allein durch das Datum fällig oder eine Rechnung überfällig wird:
  Die Zusammenfassung ändert sich um Mitternacht (Serverzeit) des Tages nach der
  Periode oder dem Fälligkeitsdatum.

Nicht bemerkt, deshalb lohnt sich ab und zu ein vollständiger Abgleich, etwa
einmal pro Nacht:

- gelöschte Mitglieder, Beitragsperioden, Rechnungen oder Zahlungen;
- eine Gutschrift oder Anzahlung, die auf eine weiter offene Rechnung angerechnet
  wird;
- Ermäßigungsregeln und die Familienregel in der Beitragseinrichtung, die den
  Betrag für viele Mitglieder auf einmal ändern;
- Ein- oder Ausschalten eines Online-Zahlungsdienstes (die Zahlungslinks).

Ein Abgleich, der nichts verliert:

1. `server_time` aus `GET /vereine/status` nehmen und merken.
2. `GET /vereine/members?changed_since=<die beim letzten Mal gemerkte Zeit>`
   lesen, Seite für Seite, bis eine Seite leer ist.
3. Nach der letzten Seite die neue Zeit aus Schritt 1 für den nächsten Abgleich
   merken.

Der erste Abgleich lässt `changed_since` weg und liest jedes Mitglied.

## GET /vereine/members/{id}/summary

Was eine Website einem Mitglied über die Mitgliedschaft zeigt. `{id}` ist die ID
des Mitglieds in Dolibarr (die Zahl in der Adresse der Mitgliedskarte).

```json
{
  "id": 12,
  "ref": "12",
  "firstname": "Paula",
  "lastname": "Beispiel",
  "company": "",
  "type": { "id": 2, "label": "Ordentliches Mitglied" },
  "status": "active",
  "member_since": "2023-01-01",
  "paid_until": "2026-12-31",
  "functions": [{ "code": "kassier", "label": "Kassier:in", "since": "2026-03-01" }],
  "membership_ends": "",
  "currency": "EUR",
  "fee": {
    "required": true,
    "status": "paid",
    "next_due": "2027-01-01",
    "amount": 50,
    "discount": { "kind": "none", "label": "" },
    "payer": "self",
    "payment_url": ""
  },
  "open_invoices": [
    {
      "id": 31,
      "ref": "FA2608-0003",
      "type": "standard",
      "date": "2026-08-01",
      "due_date": "2026-08-15",
      "total": 60,
      "remaining": 50,
      "status": "overdue",
      "overdue": true,
      "payment_url": "https://erp.example.org/public/payment/newpayment.php?source=invoice&ref=FA2608-0003&securekey=...",
      "fee": true
    }
  ],
  "updated_at": "2026-09-17T06:12:40Z"
}
```

| Feld | Inhalt |
| --- | --- |
| `ref` | Mitgliedsnummer (Dolibarrs Referenz des Mitglieds) |
| `company` | Name einer juristischen Person; leer bei natürlichen Personen |
| `status` | `draft` (noch nicht freigegeben), `active`, `terminated` (in Dolibarr gekündigt), `excluded` |
| `member_since` | Beginn der ersten Beitragsperiode oder Tag der Freigabe, je nachdem, was früher ist; leer bei Entwürfen |
| `paid_until` | Ende der letzten bezahlten Beitragsperiode, der ganze Tag eingeschlossen: eine Periode ohne Beitragsrechnung (auf der Mitgliedskarte als bezahlt erfasst) oder mit bezahlter Beitragsrechnung. Leer, wenn das Mitglied nie bezahlt hat |
| `functions` | Funktionen, die das Mitglied heute hat, jede mit `code`, `label` und `since`; die eigenen Daten des Mitglieds, also ohne Einwilligung |
| `membership_ends` | Letzter Tag der Mitgliedschaft nach einem eingetragenen Austritt: Kündigung mit der Frist der Statuten, Ausschluss, Tod oder Streichung. `status` bleibt bis zu diesem Tag `active`. Leer ohne Austritt oder nach zurückgenommenem Austritt |
| `fee.required` | Ob die Mitgliedsart ein Abonnement braucht |
| `fee.status` | `paid` (eine bezahlte Periode deckt heute ab), `invoiced` (die Periode für heute hat eine noch nicht bezahlte Beitragsrechnung, siehe `open_invoices`), `due` (nie bezahlt oder die letzte Periode ist vorbei), `not_required` (Mitgliedsart ohne Abonnement), `inactive` (Entwurf, gekündigt oder ausgeschlossen) |
| `fee.next_due` | Der Tag nach `paid_until`; der Tag der Freigabe, wenn das Mitglied nie bezahlt hat; leer bei `not_required` und `inactive` |
| `fee.amount` | Betrag einer ganzen Periode für dieses Mitglied: der Betrag der Mitgliedsart nach der Ermäßigung des Mitglieds am `next_due`, vor einem Familienrabatt; `null`, wenn die Art keinen festlegt oder kein Abonnement braucht |
| `fee.discount` | `kind` `none`, `exempt` (label ist der Grund), `proof` oder `age` (label ist der Name der Ermäßigung) |
| `fee.payer` | `self`, wenn der eigene Geschäftspartner des Mitglieds die Beitragsrechnungen bekommt; `other`, wenn ein anderer Geschäftspartner als Zahler eingetragen ist, etwa ein Elternteil für die Familie. Die Rechnungen des Zahlers stehen nicht beim Mitglied, und `fee.status` folgt ihnen trotzdem: `invoiced`, bis der Zahler bezahlt hat |
| `fee.payment_url` | Dolibarrs Online-Zahlungsseite für den Beitrag, nur solange der Beitrag `due` ist, `fee.payer` `self` ist und ein Online-Zahlungsdienst (Stripe, PayPal oder einer aus einem Modul) eingerichtet ist; sonst leer. Ein `invoiced` Beitrag wird über den Zahlungslink seiner Rechnung bezahlt |
| `open_invoices` | Freigegebene, unbezahlte Rechnungen des Geschäftspartners des Mitglieds, älteste zuerst, höchstens 50: normale Rechnungen, Ersatz- und Anzahlungsrechnungen. Leer, wenn das Mitglied keinen Geschäftspartner hat. Eine Familienrechnung erscheint bei dem Mitglied, dessen Geschäftspartner der Zahler ist. Jede Rechnung wie in [`members/{id}/invoices`](#get-vereinemembersidinvoices) |
| `updated_at` | Wann sich zuletzt etwas in der Zusammenfassung geändert hat, in UTC; was zählt, steht bei [`GET /vereine/members`](#get-vereinemembers) |

Datumsangaben sind `JJJJ-MM-TT` oder leer, Beträge Zahlen in `currency`. Die
Zusammenfassung enthält nie Geburtsdatum, Anschrift, Telefon, E-Mail, Notizen,
Bankdaten oder Mahnstufen. Dolibarrs Mitgliedsstatus „Abonnement überfällig“ ist
`status: active` mit `fee.status: due`.

Antwortet 404, wenn es kein Mitglied mit dieser ID gibt.

## GET /vereine/members/lookup

Dieselbe Zusammenfassung, gefunden über Mitgliedsnummer oder E-Mail-Adresse –
um ein Website-Konto mit Dolibarr zu verknüpfen.

| Aufruf | Antwort |
| --- | --- |
| `?ref=12` | das Mitglied mit dieser Mitgliedsnummer |
| `?email=paula@example.org` | das Mitglied mit dieser E-Mail-Adresse, ohne Unterschied zwischen Groß- und Kleinschreibung |
| keins oder beides, oder eine ungültige E-Mail-Adresse | 400 – Dolibarrs API prüft das Format von `email` selbst, also Leerzeichen vorher entfernen |
| niemand passt | 404 |
| mehrere Mitglieder teilen sich die E-Mail-Adresse | 409 – das Konto stattdessen über die Mitgliedsnummer verknüpfen |

Mitglieder einmal beim Verknüpfen des Kontos suchen, die `id` speichern und die
Zusammenfassung danach über die ID lesen.

## GET /vereine/members/{id}/invoices

Alle freigegebenen Rechnungen des Geschäftspartners des Mitglieds, neueste
zuerst – für eine Rechnungsliste auf der Website. Entwürfe fehlen; ein Mitglied
ohne Geschäftspartner hat keine. `?limit=` (1 bis 100, Standard 100) und `?page=`
(ab 0) blättern; eine Seite nach der letzten ist eine leere Liste.

```json
[
  {
    "id": 31,
    "ref": "FA2608-0003",
    "type": "standard",
    "date": "2026-08-01",
    "due_date": "2026-08-15",
    "total": 60,
    "remaining": 50,
    "status": "overdue",
    "overdue": true,
    "payment_url": "",
    "fee": true
  }
]
```

| Feld | Inhalt |
| --- | --- |
| `id` | Rechnungs-ID, für das PDF unten |
| `type` | `standard`, `replacement`, `credit_note` (negative Beträge) oder `deposit` |
| `total` | Betrag mit USt |
| `remaining` | Was nach Zahlungen, Gutschriften und Anzahlungen noch offen ist; 0 bei bezahlten und aufgegebenen Rechnungen |
| `status` | `open`, `overdue` (Fälligkeit vorbei), `paid`, `abandoned` |
| `overdue` | `true` genau dann, wenn `status` `overdue` ist |
| `fee` | Eine Beitragsrechnung: mit einer Beitragsperiode verknüpft, egal ob aus einem Beitragslauf oder von der Mitgliedskarte |
| `payment_url` | Dolibarrs Online-Zahlungsseite für eine offene oder überfällige Rechnung außer einer Gutschrift, nur mit Online-Zahlungsdienst; sonst leer |

Antwortet 400 für limit oder page außerhalb des Bereichs und 404, wenn es kein
Mitglied mit dieser ID gibt.

## GET /vereine/members/{id}/invoices/{invoice}/pdf

Das PDF einer Rechnung des Mitglieds, base64-kodiert, so wie Dolibarrs eigener
Dokument-Download antwortet:

```json
{
  "filename": "FA2608-0003.pdf",
  "content_type": "application/pdf",
  "filesize": 48213,
  "content": "JVBERi0xLjcK..."
}
```

- Die Rechnung muss zum Geschäftspartner des Mitglieds gehören und freigegeben
  sein. Die Rechnung eines anderen Mitglieds, ein Entwurf oder eine unbekannte
  Rechnung ergibt 404 – dieselbe Antwort, damit niemand erfährt, ob eine fremde
  Rechnung existiert.
- Ein nie erzeugtes PDF wird mit der Vorlage der Rechnung erzeugt, in der
  Sprache des Geschäftspartners, wenn Dolibarr mehrere Sprachen nutzt, und
  gespeichert wie ein auf der Rechnungskarte erzeugtes PDF. Antwortet 500, wenn
  das scheitert.
- Die Website darf das PDF nur dem Mitglied geben, dem es gehört: Es trägt Name
  und Anschrift des Mitglieds. Durchreichen, nicht speichern.

## Benachrichtigung über Webhooks

Statt alle paar Minuten zu fragen, kann eine Website erfahren, wann sich die
Zusammenfassung eines Mitglieds geändert haben kann. Dolibarrs eigene Webhooks
würden das ganze Mitglied schicken – Geburtsdatum, Anschrift, Notizen –, und
Dolibarr 23 und 24 behalten das im Webhook-Verlauf. Das Modul löst deshalb ein
eigenes Ereignis `VEREINE_MEMBER_CHANGED` aus, das nur die Mitglieds-ID und die
Ursache trägt:

```json
{
  "triggercode": "VEREINE_MEMBER_CHANGED",
  "object": {
    "id": 12,
    "element": "vereine_member_event",
    "member_id": 12,
    "cause": "PAYMENT_CUSTOMER_CREATE",
    "occurred_at": "2026-09-17T08:00:00Z",
    "context": []
  }
}
```

Einrichten:

1. Dolibarrs Modul *Webhooks* aktivieren.
2. Ein Webhook-Ziel anlegen: die URL der Website, das Ereignis
   *Vereine: Mitglieds-Zusammenfassung geändert (für Website-Webhooks)*
   (`VEREINE_MEMBER_CHANGED`) und sonst keines, Status *automatisch*.
3. Typ *Nicht blockierend* (Dolibarr 22) oder *Keine Prüfung des Ergebnisses*
   (23 und 24). Dolibarr sendet, während der Benutzer wartet; das Modul lässt eine
   Änderung nie an einem scheiternden Webhook scheitern, eine langsame Website
   bremst Dolibarr aber trotzdem.

Auf der Website:

- Dolibarr signiert nichts. Ein langes zufälliges Token in die Ziel-URL schreiben
  und Aufrufe ohne dieses Token ablehnen. Ein gefälschtes Ereignis kann nicht mehr,
  als die Website eine Zusammenfassung neu lesen zu lassen.
- Die Zusammenfassung ein paar Sekunden nach dem Ereignis über die API lesen,
  nicht sofort: Dolibarr sendet, solange seine Datenbank-Transaktion noch offen
  ist, ein sofortiges Lesen kann also den alten Stand sehen. Ein 404 heißt, das
  Mitglied wurde gelöscht.

| `cause` | Was passiert ist |
| --- | --- |
| `MEMBER_CREATE`, `MEMBER_VALIDATE`, `MEMBER_MODIFY`, `MEMBER_RESILIATE`, `MEMBER_EXCLUDE`, `MEMBER_DELETE` | Das Mitglied |
| `MEMBER_SUBSCRIPTION_CREATE`, `MEMBER_SUBSCRIPTION_MODIFY`, `MEMBER_SUBSCRIPTION_DELETE` | Eine Beitragsperiode des Mitglieds |
| `BILL_VALIDATE`, `BILL_UNVALIDATE`, `BILL_MODIFY`, `BILL_PAYED`, `BILL_UNPAYED`, `BILL_CANCEL`, `BILL_DELETE` | Eine Kundenrechnung des Geschäftspartners des Mitglieds oder eine Beitragsrechnung, die mit einer Beitragsperiode des Mitglieds verknüpft ist – das Bezahlen einer Familienrechnung meldet also jedes Mitglied darauf (Entwürfe nur, wenn sie wieder einer wurden) |
| `PAYMENT_CUSTOMER_CREATE`, `PAYMENT_CUSTOMER_DELETE` | Eine Zahlung auf eine solche Rechnung |

Eine Aktion löst ein Ereignis je Mitglied aus, auch wenn Dolibarr mehrere Dinge
auf einmal meldet (eine Zahlung, die zugleich die Rechnung schließt). Nicht
gemeldet: eine geänderte Mitgliedsart, ein Beitrag, der durch das Datum fällig
wird, eine Rechnung, die durch das Datum überfällig wird, und der
Online-Zahlungsdienst – den nächtlichen Abgleich mit
[`GET /vereine/members`](#get-vereinemembers) beibehalten.

## Beispiel

```bash
curl --fail -H "DOLAPIKEY: $DOLIBARR_API_KEY" \
  https://erp.example.org/api/index.php/vereine/organization
```

Die Schnittstellen stehen auch in Dolibarrs API-Explorer unter
`/api/index.php/explorer`, sobald das Modul aktiv ist, und im Einrichtungsreiter
*API* des Moduls mit den Rechten, die jede braucht (`x-vereine-rights` in
`docs/openapi.json`: jedes Recht einer inneren Liste genügt, jede Liste ist
nötig), und den Benutzern mit API-Schlüssel.
