# Eigene Dokumente mit ODT-Vorlagen

Manche Dokumente gibt es nur in deinem Verein: die Vereinbarung mit einem Vertragsspieler, eine
Ehrenamtsvereinbarung, die Schlüsselübergabe. Solche Vorlagen kann kein Modul mitliefern. Dolibarr
kann sie aber füllen: Du gestaltest die Vorlage selbst in LibreOffice oder Word, speicherst sie als
**ODT**, und Dolibarr setzt beim Erzeugen die Werte des Mitglieds ein.

Das Modul Vereine liefert dafür **zusätzliche Platzhalter** mit: Vereinsdaten, Funktionen des
Mitglieds, Beitrag der Mitgliedsart und Einwilligungen.

## In fünf Schritten

1. **Vorlage schreiben.** Nimm `docs/vorlagen/vereinsvereinbarung.odt` aus diesem Modul als Muster
   oder öffne dein Word-Dokument und speichere es als ODT („Datei – Speichern unter – ODF-Textdokument“).
2. **Platzhalter einsetzen.** In ODT-Vorlagen stehen sie **in geschweiften Klammern**, zum Beispiel
   `{__VEREINE_NAME__}`. Beim Erzeugen stehen dort die echten Werte. (In E-Mail-Vorlagen schreibt man
   dieselben Platzhalter ohne Klammern, also `__VEREINE_NAME__` – das ist Dolibarrs Unterschied, nicht
   unserer.)
3. **Ordner hinterlegen.** Lege die Vorlage in einen Ordner **unter `documents/doctemplates`**, etwa
   `documents/doctemplates/mitglieder`. Ab Dolibarr 24 ist das Pflicht: Vorlagen außerhalb von
   `doctemplates` (oder `ecm`) lehnt Dolibarr mit „BadDirForTemplateFile“ ab. Danach in Dolibarr unter
   *Einstellungen – Module – Mitglieder* (Zahnrad) diesen Ordner als Pfad für ODT-Vorlagen eintragen und
   die Dokumentvorlage **ODT-Vorlagen** („Generic ODT“) aktivieren.
4. **Dokument erzeugen.** Auf der Mitgliedskarte unter *Dokumente* die Vorlage wählen und erzeugen.
5. **Verschicken oder drucken.** Das fertige Dokument liegt bei den Dokumenten des Mitglieds und
   lässt sich von dort per E-Mail versenden.

## Platzhalter des Moduls

### Verein

| Platzhalter | Inhalt |
| --- | --- |
| `__VEREINE_NAME__` | Name des Vereins |
| `__VEREINE_ZVR__` | ZVR-Zahl |
| `__VEREINE_SITZ__` | Sitz (Ort) |
| `__VEREINE_ADRESSE__` | Anschrift |
| `__VEREINE_EMAIL__`, `__VEREINE_WEBSITE__` | Kontakt |
| `__VEREINE_BEHOERDE__` | Zuständige Vereinsbehörde |
| `__VEREINE_GEGRUENDET__` | Gründungstag |
| `__VEREINE_ZWECK__` | Vereinszweck laut Statuten |
| `__VEREINE_OBMANN__`, `__VEREINE_KASSIER__`, `__VEREINE_SCHRIFTFUEHRUNG__` | Wer die Funktion heute innehat |
| `__VEREINE_VORSTAND__` | Der ganze Vorstand mit Funktionen, je Zeile eine Person |
| `__VEREINE_BANKVERBINDUNG__` | IBAN und BIC des Vereinskontos (Einrichtung, Reiter *Mitgliedsantrag*) |

### Mitglied

| Platzhalter | Inhalt |
| --- | --- |
| `__VEREINE_MITGLIED_NUMMER__` | Mitgliedsnummer |
| `__VEREINE_MITGLIED_ART__` | Mitgliedsart |
| `__VEREINE_MITGLIED_BEITRAG__` | Beitrag der Mitgliedsart, etwa „60,00 € je 1 Jahr(e)“ |
| `__VEREINE_MITGLIED_SEIT__` | Mitglied seit |
| `__VEREINE_MITGLIED_FUNKTIONEN__` | Funktionen des Mitglieds heute |
| `__VEREINE_MITGLIED_EINWILLIGUNGEN__` | Einwilligungen mit Stand und Version |

Dazu kommen **alle Platzhalter von Dolibarr** für Mitglieder, etwa `{__MEMBER_FIRSTNAME__}`,
`{__MEMBER_LASTNAME__}`, `{__MEMBER_ADDRESS__}`, `{__MEMBER_ZIP__}`, `{__MEMBER_TOWN__}`,
`{__MEMBER_EMAIL__}`. Die vollständige Liste zeigt Dolibarr selbst beim Bearbeiten einer
E-Mail-Vorlage.

## Ehrlich gesagt

- **PDF aus ODT** braucht LibreOffice am Server. Ohne LibreOffice bekommst du die ODT-Datei zum
  Öffnen und Drucken – das reicht für Unterschreiben auf Papier.
- **Vertragsspieler sind keine Mitglieder.** Führe sie in Dolibarr als Geschäftspartner (private
  Person) mit einer eigenen Kategorie. Dokumente für sie erzeugst du auf der Karte des
  Geschäftspartners; dort füllen sich die Vereinsplatzhalter genauso, die Mitgliedsplatzhalter
  bleiben leer.
- Platzhalter, für die es keinen Wert gibt, bleiben leer – es steht nie „undefined“ im Dokument.
