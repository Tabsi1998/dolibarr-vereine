# Geheime Wahl: Bedrohungsmodell und Architekturvorschlag (#162)

Stand: Entwurf zur unabhängigen Sicherheitsprüfung, 24. September 2026. **Nichts davon ist aktiviert.**
Geheime Abstimmungen über Apps bleiben gesperrt (`VereineBallotErrorSecret`), bis dieses Dokument
geprüft, das Verfahren entschieden und ein synthetischer Testlauf bestanden ist.

## Worum es geht

Offene Abstimmungen (#160, #161) speichern zu jedem Stimmrecht die Stimme. Bei einer **geheimen** Wahl
darf niemand – auch nicht der Vorstand, die Administration oder wer die Datenbank betreibt – aus den
gespeicherten Daten ablesen können, wer wie gestimmt hat. Gleichzeitig muss weiter gelten:

- jedes Stimmrecht zählt genau einmal, egal über welchen Weg (App, Webportal, Stimmzettel);
- die Zahl der Stimmen passt zur Zahl der genutzten Stimmrechte;
- ein Verbindungsabbruch führt weder zu einer doppelten Stimme noch zu einer verlorenen.

Zwei Tabellen ohne gemeinsamen Schlüssel reichen dafür **nicht**: Zeitstempel, Reihenfolge der Zeilen,
Protokolle und Sicherungen verbinden sie wieder.

## Beteiligte und was sie sehen

| Rolle | Sieht heute | Darf bei geheimer Wahl nicht sehen |
| --- | --- | --- |
| Mitglied | eigene Stimmrechte, eigene Stimme | Stimmen anderer |
| App-Betreiber (Client) | Anfragen der gebundenen Person, auch die gewählte Option | – (sieht die Option zwangsläufig beim Absenden) |
| Wahlleitung / Vorstand in Dolibarr | wer ein Stimmrecht genutzt hat | wer wie gestimmt hat |
| Dolibarr-Administration | alle Tabellen, Protokolle, Sicherungen | Zuordnung Person → Stimme |
| Datenbank-/Hosting-Betrieb | Datenbank, Binärprotokolle, Sicherungen | Zuordnung Person → Stimme |
| Externer Wahldienst (falls genutzt) | anonyme Stimmzettel | Identität der Wählenden |

## Bedrohungen

1. **Verknüpfung über Zeit**: Stimmrecht „genutzt um 19:03:12“ und Stimme „eingegangen um 19:03:12“.
   Bei wenigen Wählenden reicht schon die Minute.
2. **Verknüpfung über Reihenfolge**: fortlaufende Zeilennummern in beiden Tabellen.
3. **Protokolle**: Webserver-Log (URL, IP, Zeit), Dolibarr-Log (`dol_syslog`), Modul-Log (`VereineLog`),
   Änderungsfeed, Webhooks, MySQL-Binärlog, Sicherungen.
4. **Wiederholungsschutz**: Eine `external_id` der App, die mit der Stimme gespeichert wird, verbindet
   App-Anfrage und Stimme.
5. **Kleine Gruppen**: Stimmen alle gleich, ist das Ergebnis selbst die Offenlegung. Das ist keine
   technische Schwäche, muss aber im Nachweis stehen.
6. **Zwang und Stimmenkauf**: Wer die Stimme am eigenen Gerät zeigen kann, kann sie verkaufen. Ein
   Online-Verfahren ohne Wiederwahl-Möglichkeit schützt davor nicht; das wird nicht behauptet.
7. **Kompromittiertes Endgerät / App**: Die App sieht die Wahl. Dagegen hilft kein Server-Verfahren.
8. **Kollusion** von Wahlleitung und Administration: kann jede rein serverseitige Trennung aufheben.
9. **Zwischenergebnisse**: Zählstände vor Ende beeinflussen die Wahl.

## Verfahren im Vergleich

| Verfahren | Schutz | Aufwand | Bewertung |
| --- | --- | --- | --- |
| A. Zwei Tabellen im Modul, Zeit nur als Tag, Stimmen in zufälliger Reihenfolge ausgezählt | gegen Wahlleitung ja; gegen Administration/Hosting **nein** (Binärlog, Sicherungen) | klein | nur als „Vertraulichkeit gegenüber dem Vorstand“, **nicht** als geheime Wahl |
| B. Eigene Kryptografie im Modul (z. B. blinde Signaturen) | theoretisch hoch | groß, fehleranfällig | **abgelehnt** – keine selbst erfundene Kryptografie |
| C. Externer, geprüfter Wahldienst über eine Adapter-Grenze | hoch, soweit der Dienst es nachweist | mittel | **Vorschlag** |
| D. Geheime Wahl nur auf Papier, das Modul zählt Stimmrechte und nimmt das Ergebnis als Summe | hoch (Papier) | klein | **sofort möglich**, heute schon mit offenen Stimmzetteln des Vorstands abbildbar |

## Architekturvorschlag (C, mit D als Rückfall)

1. **Das Modul bleibt Herr der Stimmrechte**: Wer stimmberechtigt ist, Vollmachten, Anwesenheit und der
   einmalige Verbrauch des Stimmrechts laufen wie bei offenen Abstimmungen (#160, #161).
2. **Beim Verbrauch** gibt das Modul der Person **einen einmaligen, anonymen Stimmzettel-Code** des
   externen Dienstes aus (vom Dienst vorab als Stapel erzeugt, ohne Bezug zu Personen). Das Modul
   speichert nur: Stimmrecht genutzt (Tag, nicht Uhrzeit) – **nicht**, welcher Code an wen ging.
   Die Codes werden in zufälliger Reihenfolge aus dem Stapel gezogen und danach gelöscht.
3. **Die Stimme** gibt die Person mit dem Code direkt beim Dienst ab. Das Modul sieht sie nie.
4. **Nach dem Schließen** holt das Modul nur die **Summen je Option** und die Zahl der eingelösten Codes.
   Die Auswertung (#163) prüft: eingelöste Codes ≤ genutzte Stimmrechte; Abweichungen stehen im Nachweis.
5. **Wiederholungsschutz ohne Verknüpfung**: Ein zweiter Abruf desselben Stimmrechts gibt *denselben*
   Code nur aus, solange er im Speicher der laufenden Anfrage liegt; danach „schon genutzt“. Ein
   verlorener Code ist ein verlorenes Stimmrecht – das ist die dokumentierte, bewusste Grenze
   (Alternative: Papier-Stimmzettel am Ort).
6. **Adapter-Grenze**: eine Schnittstelle `SecretBallotProvider` mit drei Aufrufen – Codes für eine
   Abstimmung anlegen, Summen abholen, Abstimmung schließen. Kein bestimmter Anbieter ist fest eingebaut.
7. **Keine Protokolle mit Bezug**: Für diese Anfragen schreibt das Modul weder `VereineLog` noch
   Änderungsfeed noch Webhook mit Uhrzeit; `dol_syslog` bekommt nur „Stimmrecht genutzt“ ohne Person.
   Webserver-Logs liegen außerhalb des Moduls – das muss der Betrieb regeln (Hinweis im Handbuch).
8. **Bei Ausfall des Dienstes**: Die Abstimmung bleibt geschlossen bzw. wird abgesagt; **niemals**
   stiller Wechsel auf offene Speicherung.

## Offene Punkte für die Prüfung

- Welcher Dienst erfüllt die Anforderungen (Anonymität, Nachweis, Datenschutz in der EU)?
- Reicht „Tag statt Uhrzeit“ beim Stimmrecht, oder muss auch der Tag entfallen?
- Soll eine Quittung für die Person möglich sein, ohne die Stimme zu zeigen?
- Wie werden Papier-Stimmzettel und Codes in derselben Wahl gemischt, ohne dass kleine Gruppen
  auffallen? Vorschlag: gemischter Betrieb nur mit mindestens zehn Stimmrechten je Weg.
- Vollmachten: Der Vertreter bekommt je vertretenem Stimmrecht einen eigenen Code.

## Abnahme vor Aktivierung (aus #162)

- [ ] Dieses Modell unabhängig geprüft, Verfahren entschieden
- [ ] Negativtest: keine Person-Stimme-Verbindung in Datenbank, Logs, Exporten, Testsicherung
- [ ] Zwei Clients, parallele Abgabe, Wiederholung und Antwortverlust ohne Doppelstimme
- [ ] Ergebnis vor Ende verborgen; Auszählung passt zu genutzten Stimmrechten
- [ ] Nicht unterstützte Betriebsarten gesperrt; synthetische Testwahl und Ablaufbeschreibung bestanden
