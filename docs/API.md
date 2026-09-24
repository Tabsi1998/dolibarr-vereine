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
  "module_version": "1.0.0",
  "api_version": 2,
  "server_time": "2026-09-17T08:00:00Z"
}
```

`server_time` ist Dolibarrs Uhr in UTC – vor einem Website-Abgleich nehmen und
beim nächsten als `changed_since` verwenden (siehe unten). `api_version` 2 seit 1.0:
`country_profile`, `country_profile_complete` und `register.court` gibt es nicht mehr.

## GET /vereine/documents

Was der Verein für die **Öffentlichkeit** veröffentlicht hat, im selben Format wie
`GET /vereine/me/documents`. Das PDF kommt von `GET /vereine/documents/{id}/pdf` (optional `revision`).
Veröffentlicht wird unter *Mitglieder > Verein > Vereinsakte*: je Dokument von Hand oder je Dokumentart
automatisch, sobald die unterschriebene Fassung da ist. Ab Werk ist nichts veröffentlicht.

## GET /vereine/events

Veranstaltungen ab heute, die der Verein als **öffentlich** markiert hat, etwa für die Startseite:

```json
[{"id": 4, "label": "Winter-Cup", "day": "2026-12-05", "end_day": "", "timezone": "Europe/Vienna",
  "place": "Vereinsheim", "status": "planned", "visibility": "public",
  "registration": {"kind": "external", "external_ref": "lionsquad.at"}}]
```

`registration` sagt, **wo** man sich anmeldet: `none`, `dolibarr` oder `external` mit der Anwendung, die
die Anmeldung führt. Es gibt genau eine Anmeldestelle; das Modul bucht nie ein zweites Mal. Nie
Teilnehmer, interne Aufgaben oder Geld.

## GET /vereine/statutes

Die Statuten, wenn der Verein sie unter *Einrichtung > Statuten* für die **Öffentlichkeit** freigibt
(Standard: niemand). Optional `day=JJJJ-MM-TT` als Stichtag, sonst heute.

```json
{"state": "in_force",
 "current": {"id": 3, "version": 2, "decided_on": "2026-03-14", "valid_from": "2026-04-20", "valid_to": "",
             "state": "in_force", "source": "generated", "sha256": "9b1d…", "size": 48211},
 "versions": [{"id": 5, "version": 3, "valid_from": "2027-01-01", "state": "future", "…": "…"},
              {"id": 3, "version": 2, "state": "in_force", "…": "…"},
              {"id": 1, "version": 1, "valid_to": "2026-04-19", "state": "repealed", "…": "…"}]}
```

- Nur **beschlossene** Fassungen, nie der Text, an dem der Vorstand noch arbeitet.
- `state`: `in_force` (eine Fassung gilt), `none` (noch keine gilt), `ambiguous` (zwei Fassungen beginnen am
  selben Tag – dann nennt die Antwort keine, statt eine zu erraten), `not_published`.
- Das PDF einer Fassung: `GET /vereine/statutes/{id}/pdf`, geprüft gegen ihre Prüfsumme; fehlt die Datei
  oder passt sie nicht, kommt `500`.
- Für Mitglieder freigegeben: `GET /vereine/me/statutes?subject=…` und `GET /vereine/me/statutes/{id}/pdf`
  mit der Fähigkeit `documents`, solange die Person aktives Mitglied ist.

Wann eine Statutenänderung wirksam wird, trägt der Verein als „gültig ab“ ein: in der Regel, wenn die
Vereinsbehörde nicht binnen vier Wochen widerspricht oder vorher zustimmt.

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
| `register.kind` | Immer `ZVR` |
| `register.number` | ZVR-Zahl (Ziffern); leer, wenn nicht eingetragen |
| `authority` | Vereinsbehörde |
| `founded` | `JJJJ-MM-TT` oder leer |
| `fiscal_year_start_month` | 1 bis 12, aus den Unternehmensdaten von Dolibarr |

Jedes Textfeld ist ein String, leer, wenn nicht eingetragen – nie `null`.

`channels` listet die **öffentlichen Kanäle** des Vereins in der Reihenfolge, die er unter
*Einrichtung > Vereine > Kanäle und Konten* festlegt, auch mehrere je Netzwerk:

```json
"channels": [
  {"network": "twitch", "network_label": "Twitch", "label": "Hauptstream", "target": "lionsquad",
   "url": "https://www.twitch.tv/lionsquad", "stream": true, "live_url": "https://www.twitch.tv/lionsquad"},
  {"network": "youtube", "network_label": "Youtube", "label": "Livestream", "target": "lionsquad",
   "url": "https://www.youtube.com/@lionsquad", "stream": true, "live_url": "https://www.youtube.com/@lionsquad/live"},
  {"network": "discord", "network_label": "Discord", "label": "Community", "target": "https://discord.gg/lionsquad",
   "url": "https://discord.gg/lionsquad", "stream": false, "live_url": ""}
]
```

`url` ist leer, wenn aus einem Namen keine Adresse zu bauen ist (etwa ein Discord-Name ohne Einladung).
`live_url` gibt es für Kanäle, auf denen gestreamt wird, bei Twitch, YouTube und Kick.

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
| `fee.mandate` | Stand des SEPA-Mandats des Zahlers: `off` (Dolibarrs Lastschrift-Modul aus), `none`, `valid` oder `expired`, dazu der Tag der Unterschrift. **Nie Kontodaten.** Unterschrieben wird über Dolibarrs eigene Online-Unterschrift, angestoßen am Mitglied in Dolibarr |
| `consents` | Die erteilten Einwilligungen, jede mit der gezeigten Version; nicht angekreuzte Zwecke fehlen. Je Einwilligung dürfen `granted_at` (Zeitpunkt der Zustimmung, ISO 8601), `form` (Formular oder Seite) und `reference` (Kennung des Vorgangs) mitkommen – der Nachweis nach Art. 7 Abs. 1 DSGVO. Keine IP-Adresse |
| `signature` | Freiwillig: die am Bildschirm gezeichnete Unterschrift als PNG, base64 kodiert, höchstens 200000 Bytes. Sie steht dann im Antrags-PDF. Das ist eine einfache elektronische Signatur (Art. 25 eIDAS), keine qualifizierte |

Antwortet 400 mit jedem Problem in der Meldung, 403 ohne das Recht.
`document` sagt, ob der Mitgliedsantrag als PDF bei den Dokumenten des Mitglieds abgelegt wurde – mit dem Vermerk, dass er über die Website kam, und mit der Unterschrift, wenn eine mitgeschickt wurde. Scheitert nur das PDF, bleibt der Antrag trotzdem bestehen; das Modulprotokoll hält die Prüfsumme fest.
Einwilligungen von Kindern: Bei einem Online-Formular, das sich direkt an Kinder
richtet, kann ein Kind in Österreich ab 14 Jahren selbst einwilligen (§ 4 Abs. 4
DSG); bei Jüngeren die Eltern fragen.


## GET /vereine/applicationform

Welche Felder der Antrag verlangt – damit eine Website genau das fragt, was der gedruckte Antrag
des Vereins fragt. Braucht dasselbe Recht wie das Senden eines Antrags.

```json
{"required": ["lastname", "firstname", "address", "zip", "town", "email"],
 "fields": [{"code": "gamertag", "label": "Gamertag", "required": true, "type": "text", "max_length": 255},
            {"code": "spielstaerke", "label": "Spielstärke", "required": false, "type": "select",
             "options": [{"code": "anfaenger", "label": "Anfänger"}, {"code": "profi", "label": "Profi"}]}]}
```

- `required`: die Pflichtfelder aus *Einrichtung > Vereine > Mitgliedsantrag*. Vor- und Nachname
  sind immer dabei; die Anschrift ist ab Werk angehakt.
- `fields`: eigene Felder des Vereins (Zusatzfelder am Mitglied), die er auf den Antrag gestellt
  hat, in ihrer Reihenfolge, je mit Pflicht-Schalter und `type`: `text`, `textarea` (je mit
  `max_length`), `number`, `date` (JJJJ-MM-TT), `boolean`, `select` oder `multi` (je mit `options`).
  Eine Website zeigt damit jedes Feld passend, ohne es fest einzubauen – der Verein legt neue unter
  *Einrichtung > Vereine > Mitgliedsantrag* an.

`POST /vereine/applications` lehnt einen Antrag mit **derselben** Liste ab: fehlt ein
Pflichtfeld, kommt `400` mit z. B. `address is required` oder `fields.gamertag is required`.
`accounts` nennt die Konten, die der Antrag abfragt, etwa
`[{"network": "discord", "label": "Discord", "required": true}]`. Sie gehen als
`"accounts": {"discord": "name#1234", "twitch": "name_tv"}` mit und landen in Dolibarrs eigenem Feld am
Mitglied; ein Netzwerk, das der Antrag nicht abfragt, wird abgewiesen (`accounts.x is not asked by the form`).

Eigene Felder gehen als `"fields": {"gamertag": "…", "spielstaerke": "profi"}` mit und landen am
Mitglied: eine Mehrfachauswahl als Liste von Kürzeln, Ja/Nein als `true`/`false`, ein Datum als
`JJJJ-MM-TT`. Ein Feld, das der Antrag nicht kennt, oder ein Wert, der nicht zur Art passt, wird
abgewiesen (z. B. `fields.spielstaerke must be one of: anfaenger, profi`), nicht still gespeichert.

## GET /vereine/applications/{external_id}

Wo ein Beitrittsantrag steht. Braucht das Recht, Beitrittsanträge anzulegen.

```json
{ "external_id": "web-2026-0042", "status": "accepted", "received_at": "2026-09-23T10:15:00+02:00",
  "decided_at": "2026-09-24T18:00:00+02:00", "reason": "", "member_id": 57, "member_ref": "57" }
```

| Feld | Inhalt |
| --- | --- |
| `status` | `received`, `in_review`, `accepted`, `rejected` oder `withdrawn` |
| `reason` | Nur bei einer Ablehnung: der Grund, der für die Person bestimmt ist. Interne Anmerkungen sind nie enthalten |
| `member_id`, `member_ref` | Erst nach der Aufnahme gefüllt |

Aufgenommen oder abgelehnt wird **nur in Dolibarr**, nie über die API.

## POST /vereine/applications/{external_id}/withdraw

Die Website zieht ihren eigenen Antrag zurück, solange der Verein nicht entschieden hat.

```json
{ "external_id": "web-2026-0042", "status": "withdrawn", "changed": true }
```

- Nochmals geschickt: `changed: false`, der Antrag bleibt zurückgezogen.
- Hat der Verein schon aufgenommen oder abgelehnt: 409. Eine bestehende Mitgliedschaft endet über den Austritt, nicht hier.
- Derselbe `external_id` mit **anderem Inhalt** wird beim Anlegen mit 409 abgelehnt – nichts wird stillschweigend überschrieben.

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

## GET /vereine/changes

Der Änderungsfeed: Er sagt, **dass** sich etwas geändert hat, nie **was**. Ein Eintrag nennt
Objektart, ID, Revision, Änderungsart und Zeitpunkt – keine Namen, Beträge, Rechnungsinhalte,
Unterschriften oder Stimmen. Die aktuellen Daten liest der Client über die fachlichen
Endpunkte, die selbst entscheiden, was er sehen darf.

Braucht das eigene Recht **„Änderungsfeed verfolgen"** (`vereine:sync:read`). Das ist bewusst
nicht dasselbe wie das Recht auf Mitglieds-Zusammenfassungen: ein Dienst, der abgleicht, ist
kein Mitglied, das seine eigenen Daten liest.

| Parameter | Bedeutung |
| --- | --- |
| `cursor` | Stand des Lesers. Leer beginnt am Anfang. Undurchsichtig – unverändert zurückgeben. |
| `limit` | Ereignisse je Seite, 1 bis 500, ohne Angabe 100. |
| `types` | Objektarten mit Komma getrennt: `membership`, `function`, `fee`, `application`, `consent`. |

```json
{
  "events": [
    {"event_id": "9f2c…", "object_type": "membership", "object_id": 42, "revision": 3,
     "change": "updated", "occurred_at": "2026-09-23T14:05:11Z"}
  ],
  "next_cursor": "76312d…",
  "resync_required": false,
  "has_more": false,
  "retention_days": 90
}
```

**Reihenfolge und Lücken.** Gelesen wird nach dem Zeitpunkt des Eintrags und, bei gleichem
Zeitpunkt, nach der Zeile. Einträge der letzten Sekunden hält der Feed zurück: eine noch offene
Transaktion kann eine kleinere Zeilennummer haben als eine, die früher fertig wurde, und ohne
diesen Sicherheitsabstand würde sie hinter einem bereits bestätigten Cursor auftauchen. Der
Abstand macht den Feed nicht perfekt – deshalb bleibt ein regelmäßiger Vollabgleich Pflicht.

**Zurückgerollte Änderungen** erscheinen nie: der Vermerk entsteht in derselben Transaktion wie
die Änderung selbst und verschwindet mit ihr.

**Wiederholung.** Dieselbe Änderung behält dieselbe `event_id`. Ein Client, der einen Eintrag
schon kennt, darf ihn verwerfen.

**`resync_required`.** Liegt der Cursor vor der Aufbewahrung (`retention_days`) oder stammt er
nicht von diesem Verein, antwortet der Feed mit `resync_required: true` und **ohne** Ereignisse.
Dann ist ein Vollabgleich fällig – eine stille Lücke gibt es nicht.

## GET /vereine/changes/snapshot

Der Vollabgleich. Eine Seite führt die IDs **einer** Objektart auf, sonst nichts.

| Parameter | Bedeutung |
| --- | --- |
| `object_type` | `membership`, `function`, `fee`, `application`, `consent` oder `document`. |
| `after` | Weiter nach dieser ID, 0 zum Beginnen. |
| `limit` | Objekte je Seite, 1 bis 500, ohne Angabe 100. |

```json
{
  "object_type": "membership",
  "objects": [{"object_type": "membership", "object_id": 42}],
  "next_after": 42,
  "complete": true,
  "cursor": "76312d…"
}
```

**`complete` ist die Abschlussmarkierung.** Erst wenn sie `true` ist, hat der Client alles
gesehen. Ein abgebrochener Abgleich ist **kein** Beweis, dass fehlende Objekte gelöscht wurden –
wer vorher aufräumt, löscht Daten, die es noch gibt.

Der mitgelieferte `cursor` ist der Stand zu Beginn des Abgleichs. Damit liest der Client danach
lückenlos im Feed weiter.

**Was der Feed nicht kann.** Änderungen, die niemand beobachtet – etwa eine Regel, die erst zu
einem Datum wirkt, oder eine Korrektur direkt in der Datenbank – erzeugen keinen Eintrag. Genau
dafür ist der regelmäßige Vollabgleich da.

## Wer handelt? Dienstzugang und persönlicher Zugriff

Die API kennt zwei Arten, zu fragen, und hält sie bewusst auseinander.

**Der Dienstzugang** (API v1, alle Endpunkte oben): Ein technischer Benutzer mit API-Schlüssel –
etwa der Server der Vereinswebsite – liest mit dem Recht *Mitglieds-Zusammenfassungen für eine
Website* Daten **mehrerer** Mitglieder. Das ist eine **Server-zu-Server-Vertrauensgrenze**: Der
Verein vertraut diesem Server. Er beweist aber **nicht**, welcher Mensch gerade vor dem Bildschirm
sitzt. Wer diesen Zugang nutzt, muss selbst dafür sorgen, dass ein eingeloggtes Mitglied nur
seine eigenen Daten sieht. Daran ändert sich nichts, und durch das Update bekommt kein
bestehender Schlüssel ein zusätzliches Recht.

**Der persönliche Zugriff** (neu, `identities/*` und `me/*`): Hier handelt eine Anwendung für
**eine bestimmte Person**, und das Modul prüft bei jedem Aufruf selbst, ob sie das darf:

1. Die Anwendung hat das Recht **„Für Personen handeln"** (`vereine:identity:use`). Das ist ein
   eigenes Recht – weder der Dienstzugang noch das Recht für den Änderungsfeed schließt es ein.
2. Für die Kennung (`subject`), unter der die Anwendung die Person führt, gibt es eine
   **Bindung** – bei **genau dieser Anwendung** und in **diesem Mandanten**.
3. Die Bindung ist nicht widerrufen.
4. Die Bindung trägt die **Fähigkeit**, um die es geht (`consents`, `applications`, `documents`,
   `votes`). Alle sind aus, bis der Verein sie einschaltet.
5. Das Objekt ist **das eigene**: das gebundene Mitglied oder der gebundene Antrag. Wer nach einem
   fremden fragt, wird abgewiesen, nicht umgeleitet.

**Was nie als Nachweis gilt.** Ein `verified=true` der Anwendung, eine E-Mail-Adresse oder eine
Mitgliedsnummer. Eine Familie teilt sich eine Adresse, eine Nummer lässt sich raten. Beides
liefert in der Verwaltung nur **Kandidaten**, die ein Mensch ansieht.

**Wie eine Bindung entsteht.** Der Verein erzeugt unter *Einrichtung > Vereine > Externe
Identitäten* eine **Einladung**: einen Code, der genau einmal gezeigt wird, eine Stunde gilt,
beim ersten Einlösen verfällt und bei einer anderen Anwendung wertlos ist. Gespeichert wird nur
sein Hash. Zwei gleichzeitige Einlöseversuche: einer gewinnt, der andere bekommt „schon
eingelöst".

**Antragsteller** werden an ihren Antrag gebunden, nicht an ein Mitglied. So eine Bindung öffnet
den Stand des eigenen Antrags und sonst nichts – auch keine Mitgliedsrechte. Erst die Aufnahme
macht jemanden zum Mitglied; dann bindet der Verein neu.

**Familienzahler** sind nicht automatisch gesetzliche Vertretung. Wer für jemand anderen handeln
soll, bekommt dafür eine eigene, ausdrückliche Bindung.

**Widerruf** wirkt sofort: der nächste Aufruf wird abgewiesen.

**Vertrauensannahmen.** Die Anwendung authentisiert die Person selbst (Login, Passkey, was sie
eben hat) und hält ihre Kennung stabil. Das Modul betreibt bewusst keine eigene OAuth-Plattform.
Es prüft, ob die Anwendung für Personen handeln darf und ob die Bindung passt – mehr kann ein
Server, der die Person nicht selbst sieht, nicht prüfen, und das sagt diese Seite offen.

### POST /vereine/identities/claim

`subject` und `code`. Antwort: die Bindung. Abgewiesen (403) bei fremdem, eingelöstem oder
abgelaufenem Code und wenn es für die Kennung bei dieser Anwendung schon eine Bindung gibt.

### GET /vereine/identities/me

`subject`. Antwort: gebundenes Mitglied oder gebundener Antrag, Fähigkeiten, Nachweis.

```json
{"subject":"auth0|61f2c9","member_id":42,"application_id":null,
 "capabilities":["consents"],"proof":"invitation","linked_at":"2026-09-23T14:05:11Z"}
```

### GET /vereine/me/consents

`subject`, braucht die Fähigkeit `consents`. Die eigenen Einwilligungen, im selben Format wie
`GET /vereine/members/{id}/consents`.

### GET /vereine/me/application

`subject`, braucht die Fähigkeit `applications`. Der Stand des eigenen Beitrittsantrags.

### GET /vereine/me/documents

`subject`, Fähigkeit `documents`. Was der Verein für die Person veröffentlicht hat: öffentliche
Dokumente, die für Mitglieder, solange sie aktives Mitglied ist, die für den Vorstand, solange sie
ihm angehört, und die **nur für sie** (`audience` `person`). Wer eine Person vertritt, etwa Eltern,
sieht deren Dokumente über eine eigene Bindung für diese Person, die der Vorstand einlädt.

Je Dokument **eine** Fassung: die der engsten Zielgruppe, in der die Person ist (für sie persönlich vor
Vorstand vor Mitgliedern vor Öffentlichkeit), und darin die neueste. So sieht der Vorstand das
Original, auch wenn für Mitglieder eine gekürzte Fassung veröffentlicht ist.

```json
[{"document_id": 12, "revision": 31, "derived_from": 0, "code": "QZLAS-8TMMD", "kind": "minutes", "title": "Protokoll Generalversammlung 2026",
  "date": "2026-09-24T18:02:11+00:00", "what": "signed", "sha256": "3f9c…", "size": 81234, "audience": "members"}]
```

`what` `excerpt` ist eine **gekürzte Fassung**: eine eigene Datei mit eigener Prüfsumme, `derived_from`
nennt die Fassung, aus der sie abgeleitet ist. Das Original bleibt unverändert. Ob sich ein Dokument
geändert hat, sagt `sha256`: Eine Anwendung lädt das PDF nur, wenn sich die Prüfsumme geändert hat.
Veröffentlichen, Ersetzen und Zurückziehen meldet der Änderungsfeed als `document` (`revoked`, wenn
nichts mehr veröffentlicht ist).

### GET /vereine/me/documents/{id}/pdf

`subject`, Fähigkeit `documents`, optional `revision`. Antwort wie beim Rechnungs-PDF, dazu `sha256`:
`{"filename", "content_type", "filesize", "sha256", "content"}` mit dem PDF base64-kodiert, unverändert
samt Unterschriften. Bei jedem Abruf wird neu geprüft, ob die Person es sehen darf und ob es noch
veröffentlicht ist. Sonst kommt `404`, ob es das Dokument gibt oder nicht. Fehlt die Datei oder passt
ihre Prüfsumme nicht mehr zur Vereinsakte, kommt `500` statt anderer Bytes.

### GET /vereine/me/meetings

`subject`, Fähigkeit `meetings`. Jede Sitzung, zu der die Person **eingeladen** wurde, neueste zuerst.
Eine Vorstandssitzung sieht nur, wer zu ihr eingeladen ist, nie ein Mitglied allein wegen der
Mitgliedschaft.

```json
[{"id": 7, "kind": "general", "title": "Generalversammlung 2026", "day": "2026-10-24", "time": "18:00",
  "timezone": "Europe/Vienna", "format": "hybrid", "place": "Vereinsheim", "access": "https://…",
  "status": "invited", "agenda": ["Begrüßung", "…"], "voting": true, "response": "yes",
  "responded_at": "2026-09-24T18:02:11+00:00", "motion_deadline": "2026-10-21", "motions": []}]
```

`access` bekommt nur, wer eingeladen ist, und nur bei online oder hybrid. `motion_deadline` ist der
letzte Tag für rechtzeitige Anträge laut Statuten; bei Vorstandssitzungen leer.

### PUT /vereine/me/meetings/{id}/response

`subject`, Fähigkeit `meetings`, Body `{"response": "yes" | "no" | "maybe"}`. Zu- oder Absage, keine
Anwesenheit und keine Stimme; dieselbe Antwort nochmal ändert nichts. Nach der Sitzung oder bei Absage
kommt `409`.

### POST /vereine/me/meetings/{id}/motions

`subject`, Fähigkeit `meetings`, Body `{"external_id": "app-123", "title": "…", "text": "…"}`. Ein Antrag
zur Tagesordnung einer Generalversammlung. Dieselbe `external_id` mit demselben Inhalt antwortet mit dem
schon eingegangenen Antrag, mit anderem Inhalt `409`. Nach der Frist bleibt er als `late: true` stehen.
Ob er auf die Tagesordnung kommt, entscheidet der Vorstand in Dolibarr (`status`: received, accepted,
rejected); angenommen steht er als letzter Punkt auf der Tagesordnung.

### GET /vereine/me/profile

`subject`, Fähigkeit `profile`. Die eigenen Daten: Name, Geburtsdatum (nur lesen), Anschrift, Telefon,
E-Mail, Mitgliedsart, Status, ein geplanter oder vollzogener Austritt. Dazu `version` (der Stand der
Kontaktdaten) und `direct` (Felder, die der Verein sofort übernimmt).

### POST /vereine/me/profile/changes

`subject`, Fähigkeit `profile`, Body
`{"external_id": "app-42", "version": "…aus GET me/profile…", "changes": {"address": "…", "zip": "…", "town": "…"}}`.
Nur `address`, `zip`, `town`, `country_code`, `phone`, `phone_mobile`, `email`; alles andere wird abgewiesen.
Hat sich der Stand seither geändert, kommt `409` statt eines stillen Überschreibens. Felder aus `direct`
werden sofort übernommen (`status: applied`), sonst entscheidet der Vorstand in Dolibarr
(`received` → `applied` oder `rejected` mit `reason`). Eine neue E-Mail-Adresse braucht immer den Vorstand.
Dieselbe `external_id` mit demselben Inhalt antwortet mit demselben Auftrag.

### GET /vereine/me/profile/changes

`subject`, Fähigkeit `profile`. Eigene Änderungswünsche und Kündigung mit Stand; interne Notizen des
Vorstands nie.

### POST /vereine/me/exit

`subject`, Fähigkeit `profile`, Body `{"external_id": "app-exit-1", "wished_last_day": "2026-12-31"}`
(Wunsch optional). Die Kündigung geht mit heutigem Eingang ein; die Mitgliedschaft endet am Tag, den die
Kündigungsregel des Vereins ergibt (`last_day`), oder später, wenn später gewünscht. Ein früherer Wunsch
wird nicht übernommen (`wished_too_early: true`). Ist schon ein Austritt geplant, kommt `409`.

### GET /vereine/me/ballots

`subject`, Fähigkeit `votes`. Abstimmungen der Generalversammlungen, zu denen die Person eingeladen ist,
ab der Freigabe durch die Versammlungsleitung:

```json
[{"id": 3, "meeting_id": 12, "meeting": "Generalversammlung 2026", "day": "2026-10-10", "item": 4, "kind": "resolution",
  "question": "Entlastung des Vorstands", "status": "open", "closes": "19:30", "timezone": "Europe/Vienna",
  "options": [{"code": "yes", "label": "Ja"}, {"code": "no", "label": "Nein"}, {"code": "abstain", "label": "Enthaltung"}],
  "rights": [{"right_id": 41, "for": "self", "name": "", "state": "open", "reason": "own", "option": ""},
             {"right_id": 42, "for": "proxy", "name": "Anna Muster", "state": "used", "reason": "proxy", "option": "yes"}]}]
```

Eine Abstimmung gehört immer zu einem Tagesordnungspunkt einer Generalversammlung; eine Umfrage allein ist
keine Versammlung. `status`: `released` (angekündigt), `open`, `closed`, `evaluated`, `cancelled`.

`rights` sind die Stimmrechte, die die Person nutzen kann: das eigene und die von Mitgliedern, die ihr
eine schriftliche Vollmacht gegeben haben. Sie werden beim **Öffnen** festgehalten – aus Einladung
(stimmberechtigt oder nicht), Mitgliedschaft am Versammlungstag und den Vollmachten der Anwesenheitsliste.
Spätere Änderungen gelten für diese Abstimmung nicht. `reason`: `own`, `proxy`, `represented` (hat eine
Vollmacht gegeben, der Vertreter stimmt), `no_voting_right`, `not_member`. Ein offener Beitrag nimmt kein
Stimmrecht; das müssten die Statuten sagen.

Wahlen: je Kandidat:in eine Option `c<Nummer>`, bei nur einer Kandidatur zusätzlich `no`; immer
`abstain`. Die Codes ändern sich nie. Mehrere Plätze in einem Wahlgang und automatische Stichwahlen gibt
es nicht: je Platz ein Wahlgang, eine Stichwahl als neue Abstimmung. Geheime Wahlen folgen (#162).

### POST /vereine/me/ballots/{id}/votes

`subject`, Fähigkeit `votes`, Body:

```json
{"right_id": 41, "option": "yes", "external_id": "app-vote-7f3a"}
```

Zählt nur, solange die Abstimmung offen ist (und vor `closes`) und die Person **laut Anwesenheitsliste in
der Versammlung** ist. Jedes Stimmrecht zählt **einmal**, egal über welche Anwendung oder ob der Vorstand
einen Stimmzettel einträgt. Dieselbe `external_id` beantwortet dieselbe Anfrage (`200`), auch nach einem
Verbindungsabbruch; dieselbe Stimme ohne `external_id` nochmal ändert nichts. Antwort: die Abstimmung
danach.

| Code | Bedeutung |
| --- | --- |
| `400` | `right_id`/`option` fehlen oder die Option gibt es nicht (`option`) |
| `404` | Die Abstimmung oder das Stimmrecht gehört nicht zur Person |
| `409` | `not_open`, `closed`, `channel`, `used` (schon abgestimmt, auf welchem Weg auch immer), `not_present`, `external_id` (andere Stimme unter derselben Kennung) |

Öffnen, Schließen, Auszählen und Absagen geschehen nur in Dolibarr durch die Versammlungsleitung; keine
Anwendung kann das.

`result` ist `null`, bis die Versammlungsleitung das Ergebnis in Dolibarr **bestätigt** hat; danach:

```json
{"revision": 1, "outcome": "passed", "passed": true, "counts": {"yes": 41, "no": 3, "abstain": 2}, "valid": 44, "abstain": 2, "winner": ""}
```

`outcome`: `passed`, `rejected`, `no_quorum` (nicht beschlussfähig beim Öffnen), `no_majority` (Wahl: niemand
über der Hälfte der gültigen Stimmen – eine Stichwahl ist eine neue Abstimmung). `winner` ist bei einer Wahl
der Code der gewählten Person.

### GET /vereine/me/events

`subject`, Fähigkeit `events`. Öffentliche Veranstaltungen und die für Mitglieder (in Dolibarr „nur für
Mitglieder“), ab heute, je mit `shifts`: `id`, `label`, `day`, `start`, `end`, `capacity`, `taken`
(bestätigt oder geleistet), `full` und `mine` (eigener Stand: leer, `requested`, `confirmed`, `done`,
`cancelled`).

### PUT /vereine/me/events/{id}/shifts/{shift}

`subject`, Fähigkeit `events`. Fragt den Helferdienst an (`requested`); der Vorstand bestätigt ihn in
Dolibarr. Nochmal anfragen ändert nichts. Voll, vorbei, abgesagt oder überschneidend mit einem anderen
Dienst der Person: `409`. Selbst eingetragen ist kein Nachweis für die Freiwilligenpauschale; das zählt erst,
was der Verein als geleistet bestätigt.

### DELETE /vereine/me/events/{id}/shifts/{shift}

`subject`, Fähigkeit `events`. Zieht eine noch **nicht bestätigte** Anfrage zurück. Einen bestätigten
Dienst sagt die Person beim Verein ab (`409`).

### GET /vereine/me/accounts

`subject`, braucht die Fähigkeit `accounts`. Die Konten der Person: jedes Netzwerk, das der Verein
abfragt, und jedes weitere, bei dem sie einen Namen hat.

```json
[{"network": "discord", "label": "Discord", "asked": "required", "handle": "lion#1234", "url": "",
  "confirmed": false, "confirmed_at": "", "client": ""},
 {"network": "twitch", "label": "Twitch", "asked": "optional", "handle": "lion_tv",
  "url": "https://www.twitch.tv/lion_tv", "confirmed": true, "confirmed_at": "2026-09-24T18:02:11+00:00", "client": "app"}]
```

### PUT /vereine/me/accounts/{network}

`subject`, Fähigkeit `accounts`, Body `{"handle": "lion_tv", "confirmed": true, "external_id": "98765"}`.
Setzt den Namen. `confirmed: true` heißt: Die Anwendung hat das Konto beim Netzwerk geprüft, etwa
nach dem Login mit Twitch oder Discord (OAuth). `external_id` ist die Kennung des Kontos beim Netzwerk.
Der Verein sieht das Konto als bestätigt, bis jemand den Namen ändert. Ohne `confirmed` fällt eine
ältere Bestätigung weg. Antwort: die Konten danach. Ein Netzwerk, das Dolibarr nicht kennt, ergibt 400.

### DELETE /vereine/me/accounts/{network}

`subject`, Fähigkeit `accounts`. Entfernt Namen und Bestätigung, etwa wenn die Person das Konto in
der App löst. Antwort: die Konten danach.

## Signierte Webhooks

Ein Webhook ist ein **Hinweis zum Nachlesen, kein Beweis**. Er sagt, dass sich etwas geändert
hat; die Daten holt der Empfänger danach über die API. Und auch bei lauter erfolgreichen
Zustellungen bleibt der regelmäßige Vollabgleich (`GET /vereine/changes/snapshot`) Pflicht –
ein Hinweis kann immer verloren gehen.

Eingerichtet wird das unter **Einrichtung > Vereine > Webhooks**: Adresse, der Benutzer, unter
dessen Freigabe zugestellt wird, und wahlweise die Objektarten. Das Geheimnis wird **genau
einmal** gezeigt.

### Wann zugestellt wird

Nie während einer Fachtransaktion. Eine geplante Aufgabe (alle fünf Minuten) macht aus den
Änderungsvermerken des Feeds (#154) Zustellaufträge und schickt sie los. Damit gilt:

- Was zurückgerollt wurde, stand nie im Feed und wird nie zugestellt.
- Ein langsamer oder toter Empfänger hält niemanden im Verein auf.
- Der Commit ist durch, bevor der Empfänger nachliest.

### Der Body

```json
{"version":"v1","event_id":"9f2c…","object_type":"membership","object_id":42,
 "revision":3,"change":"updated","occurred_at":"2026-09-23T14:05:11Z"}
```

Mehr nicht: keine Namen, Beträge, Dokumente, Bankdaten oder Stimmen.

### Die Signatur

Header `Vereine-Signature`, zum Beispiel:

```
v1=6f1c…a3,t=1790000000,k=a1b2c3d4,e=9f2c…
```

| Teil | Bedeutung |
| --- | --- |
| `v1` | HMAC-SHA-256, hexadezimal, über `v1.<t>.<Body-Bytes>` |
| `t` | Zeitpunkt **dieses Versuchs**, Sekunden seit 1970 |
| `k` | Kennung des Schlüssels – bei einer Rotation gibt es zwei |
| `e` | Ereignis-ID, **bleibt über Wiederholungen gleich** |

Ein Empfänger prüft in dieser Reihenfolge: Header lesbar → Schlüssel bekannt → Zeitpunkt
höchstens **300 Sekunden** alt → Signatur stimmt (in konstanter Zeit vergleichen) → Ereignis-ID
noch nicht gesehen. **Die Bytes signieren, nicht das geparste JSON.**

### Wiederholungen

Zustellung ist *mindestens einmal*. Empfänger **müssen** nach `event_id` deduplizieren. Schlägt
ein Versuch fehl, wartet der Auftrag 30 s, 2 min, 10 min, 30 min, 1 h, 3 h, 6 h – nach acht
Versuchen bleibt er liegen und kann von Hand erneut angestoßen werden, mit **derselben**
Ereignis-ID und neuer Signatur.

### Schlüsselwechsel

*Schlüssel wechseln* erzeugt ein neues Geheimnis; ab sofort wird damit signiert. Der alte
Schlüssel bleibt **24 Stunden** gültig, damit der Empfänger in Ruhe umstellen kann. In dieser
Zeit kennt der Empfänger zwei Schlüssel und entscheidet über `k`.

### Wohin zugestellt wird

Nur **https**, ohne Benutzer und Passwort in der Adresse, ohne Weiterleitungen, mit
Zertifikatsprüfung. Adressen im eigenen Netz (localhost, private Bereiche, die
Metadaten-Adresse einer Cloud) sind gesperrt, außer die Ausnahme ist für dieses Ziel bewusst
gesetzt. Der Name wird **vor jedem Versuch neu aufgelöst**, damit ein Name, der gestern
öffentlich zeigte, heute nicht ins interne Netz führt.

Vor jedem Versuch wird geprüft, ob der hinterlegte Benutzer den Änderungsfeed noch verfolgen
darf. Ist das Recht weg oder das Ziel abgeschaltet, wird der Auftrag gestoppt statt zugestellt.

### Referenz-Empfänger

`docs/beispiele/webhook-empfaenger.php` ist ein vollständiger, kurzer Empfänger zum Abschreiben.
Er liegt bewusst **nicht** im Installationspaket: er gehört auf den Server des Empfängers, nicht
in ein Dolibarr, das ihn dann als Seite ausliefern würde.

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
