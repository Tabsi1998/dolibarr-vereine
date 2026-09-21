# Unterschreiben mit ID Austria: den Signaturdienst einrichten

Wer mit ID Austria unterschreibt, leistet eine **qualifizierte elektronische
Signatur**. Rechtlich steht sie einer eigenhändigen Unterschrift gleich (Art. 25
Abs. 2 eIDAS-Verordnung, § 4 Abs. 1 SVG). Das Modul unterschreibt nicht selbst.
Es schickt das PDF an einen **Signaturdienst**, den der Verein neben Dolibarr
betreibt: **PDF-AS** vom E-Government-Innovationszentrum (EGIZ, Bundeskanzleramt
und TU Graz). PDF-AS ist kostenlos und quelloffen (EUPL 1.2).

Diese Anleitung richtet PDF-AS 5.0.0 mit Docker auf einem eigenen Server ein.

## So läuft eine Unterschrift

1. In Dolibarr klickt die Person bei einem Dokument auf „Mit ID Austria
   unterschreiben“.
2. Dolibarr schickt das PDF an den Signaturdienst. Der Dienst braucht dafür
   **keinen** Zugriff auf Dolibarr.
3. Der Browser springt zum Signaturdienst, von dort zu ID Austria. Die Person
   bestätigt am Handy.
4. Der Browser kommt zurück nach Dolibarr. Dolibarr holt das signierte PDF ab
   (einmalig, nur mit der Prüfsumme des übergebenen PDFs) und prüft selbst,
   dass es das übergebene PDF mit genau einer Signatur mehr ist.
5. Die nächste Person unterschreibt dasselbe PDF. Die Signaturen stehen
   nacheinander darin.

## Was du brauchst

- einen Server mit Docker, etwa denselben wie Dolibarr,
- **eine eigene Subdomain** für den Signaturdienst, zum Beispiel
  `signatur.meinverein.at`, mit HTTPS und **von außen erreichbar**. Der Browser
  der Unterschreibenden (auch am Handy im Mobilnetz) und ID Austria (A-Trust)
  müssen den Dienst erreichen. Eine eigene Subdomain hält PDF-AS sauber von
  Dolibarr getrennt, mit eigenem Zertifikat,
- deinen Reverse Proxy (etwa Nginx Proxy Manager), der schon Dolibarr nach außen
  bringt.

## 1. PDF-AS herunterladen

```bash
mkdir -p /opt/pdf-as && cd /opt/pdf-as
curl -LO https://github.com/a-sit/pdf-as/releases/download/5.0.0/PDF-AS-5.0.0.zip
unzip -j PDF-AS-5.0.0.zip pdf-as-web-5.0.0.war cfg/defaultConfig.zip -d .
```

Das Archiv hat rund 650 MB. Gebraucht werden daraus nur die Webanwendung
(`pdf-as-web-5.0.0.war`) und die Grundeinstellungen (`defaultConfig.zip`).

## 2. Drei Dateien anlegen

`/opt/pdf-as/Dockerfile`:

```dockerfile
FROM tomcat:11.0-jdk17-temurin
RUN rm -rf /usr/local/tomcat/webapps/*
COPY pdf-as-web-5.0.0.war /usr/local/tomcat/webapps/pdf-as-web.war
COPY defaultConfig.zip /tmp/defaultConfig.zip
RUN mkdir -p /opt/pdf-as/conf && cd /opt/pdf-as/conf && jar xf /tmp/defaultConfig.zip && rm /tmp/defaultConfig.zip
COPY pdf-as-web.properties /opt/pdf-as/pdf-as-web.properties
ENV CATALINA_OPTS="-Dpdf-as-web.conf=/opt/pdf-as/pdf-as-web.properties"
```

`/opt/pdf-as/pdf-as-web.properties`. Die beiden Adressen auf deine anpassen:

```properties
pdfas.dir=/opt/pdf-as/conf
# Die öffentliche Adresse des Dienstes, so wie der Browser ihn erreicht
public.url=https://signatur.meinverein.at/pdf-as-web
error.showdetails=false
allow.ext.overwrite=false
ext.overwrite.wl.1=^$
# Unterschreiben mit ID Austria am Handy
mobile.sign.enabled=true
bku.mobile.url=https://service.a-trust.at/mobile/https-security-layer-request/default.aspx
bku.sign.enabled=false
ks.enabled=false
moal.test.enabled=false
# Die JSON-Schnittstelle, über die Dolibarr spricht (ab Werk aus)
soap.sign.enabled=true
soap.verify.enabled=true
soap.sign.with.verify.enabled=false
# Nur zurück zu deinem Dolibarr
whitelist.enabled=true
whitelist.url.01=^https://erp\.meinverein\.at/.*$
request.store=at.gv.egiz.pdfas.web.store.InMemoryRequestStore
sl20.debug.validation.disable=true
sl20.debug.signed.result.enabled=false
sl20.debug.signed.result.required=false
sl20.debug.encryption.enabled=false
sl20.debug.encryption.required=false
sl20.transfermode.filesize=20000000
spring.boot.admin.client.enabled=false
```

`/opt/pdf-as/docker-compose.yml`:

```yaml
services:
  pdf-as:
    build: .
    restart: unless-stopped
    ports:
      # Heimnetz-Adresse dieses Servers: dort holt der Reverse Proxy den Dienst ab.
      - "192.168.1.10:8095:8080"
```

Läuft der Reverse Proxy direkt auf diesem Server und nicht in Docker, genügt
`"127.0.0.1:8095:8080"`. Ein Proxy in Docker (etwa Nginx Proxy Manager) erreicht
`127.0.0.1` des Servers nicht, er braucht die Heimnetz-Adresse.

## 3. Starten

```bash
cd /opt/pdf-as
docker compose up -d --build
curl -s -o /dev/null -w "%{http_code}\n" http://192.168.1.10:8095/pdf-as-web/v3/api-docs   # 200
```

## 4. Adresse und Reverse Proxy

**DNS.** Für `signatur` einen Eintrag anlegen wie für die Dolibarr-Adresse:
gleicher Typ, gleiches Ziel. Bei Cloudflare am sichersten mit grauer Wolke
(„nur DNS“). Die orange Wolke geht meist auch, aber Cloudflares Bot-Schutz kann
die Rückmeldung von ID Austria an den Dienst aufhalten. Hängt die Rückkehr nach
dem Bestätigen am Handy, zuerst hier auf Grau stellen.

**Router.** Nichts Neues: Wenn Dolibarr schon von außen erreichbar ist, gehen die
Ports 80 und 443 bereits an den Reverse Proxy.

**Nginx Proxy Manager**, neuer *Proxy Host*:

| Feld | Wert |
| --- | --- |
| Domain Names | `signatur.meinverein.at` |
| Scheme | `http` |
| Forward Hostname / IP | Heimnetz-Adresse des Servers mit PDF-AS, etwa `192.168.1.10` |
| Forward Port | `8095` |
| Block Common Exploits | an |
| SSL | Let's Encrypt-Zertifikat anfordern, *Force SSL* und *HTTP/2* an |

Der Pfad `/pdf-as-web` bleibt beim Weiterleiten erhalten. Den Aufbau von
Adressen übernimmt PDF-AS selbst über `public.url`, weitere Kopfzeilen braucht
es nicht. Prüfen von außen, etwa am Handy ohne WLAN:
`https://signatur.meinverein.at/pdf-as-web/v3/api-docs` zeigt Text statt eines
Fehlers.

**Dolibarr muss den Dienst unter derselben Adresse erreichen.** Dolibarr ruft
`https://signatur.meinverein.at/...` vom Server aus auf, und das signierte PDF
holt es nur von genau dieser Adresse. Viele Router leiten die eigene öffentliche
Adresse aber nicht ins Heimnetz zurück. Auf dem Dolibarr-Server prüfen:

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://signatur.meinverein.at/pdf-as-web/v3/api-docs   # 200
```

Kommt keine 200, die Adresse im Heimnetz auf den Reverse Proxy zeigen lassen:

- Dolibarr direkt auf dem Server: in `/etc/hosts` die Zeile
  `192.168.1.10 signatur.meinverein.at` (Adresse des Reverse Proxy),
- Dolibarr in Docker: in einer `docker-compose.override.yml` neben seiner
  Compose-Datei, damit ein Update sie nicht überschreibt:

  ```yaml
  services:
    dolibarr:
      extra_hosts:
        - "signatur.meinverein.at:192.168.1.10"
  ```

  Den Dienstnamen (`dolibarr`) an deine Compose-Datei anpassen.

**Absichern (nach dem ersten erfolgreichen Test, wahlweise).** Die Schnittstelle
unter `/pdf-as-web/api/` und die Abholung `/pdf-as-web/PDFData` braucht nur
Dolibarr. Im Nginx Proxy Manager unter *Advanced* beschränkst du sie auf das
Heimnetz. Das geht nur, wenn Dolibarr über die Heimnetz-Adresse kommt, siehe
oben:

```nginx
location ~ ^/pdf-as-web/(api/|PDFData) {
    allow 192.168.1.0/24;
    deny all;
    proxy_pass http://192.168.1.10:8095;
}
```

Die Browser der Unterschreibenden und ID Austria brauchen diese Pfade nicht.

## 5. In Dolibarr eintragen

*Einrichtung – Vereine – Unterschriften*, Abschnitt „Signaturdienst für ID Austria“:

- **Adresse:** `https://signatur.meinverein.at/pdf-as-web`
- **Signieren mit:** ID Austria am Handy
- **Verbindung prüfen:** meldet „Der Signaturdienst nimmt Dokumente an“.

Dann stellst du oben bei den Dokumentarten unter „Wie unterschreiben“ ein, wo
ID Austria gilt: „nur mit ID Austria“ oder „beides“.

## Zuerst mit einem Testschlüssel ausprobieren (optional)

Ein Testschlüssel am Server signiert sofort, ohne Handy. Das ist **keine**
qualifizierte Signatur einer Person, nur ein Probelauf.

```bash
cd /opt/pdf-as
docker run --rm -v /opt/pdf-as:/w eclipse-temurin:17-jre keytool -genkeypair -keystore /w/test.p12 \
  -storetype PKCS12 -alias test -keyalg RSA -keysize 2048 -dname "CN=Testschluessel Verein,C=AT" \
  -validity 365 -storepass 123456 -keypass 123456
```

In `docker-compose.yml` bei `pdf-as` ergänzen:

```yaml
    volumes:
      - ./test.p12:/opt/pdf-as/test.p12:ro
```

In `pdf-as-web.properties` ergänzen, dann `docker compose up -d --build`:

```properties
ksl.test.enabled=true
ksl.test.file=/opt/pdf-as/test.p12
ksl.test.type=PKCS12
ksl.test.pass=123456
ksl.test.key.alias=test
ksl.test.key.pass=123456
```

In Dolibarr „Signieren mit: Testschlüssel“ und „Name des Testschlüssels: test“
wählen. Danach wieder auf „ID Austria am Handy“ stellen, `ksl.test.enabled=false`
setzen und die Zeilen unter `volumes` entfernen.

## Signaturen prüfen

Bei einem signierten Dokument zeigt „Signaturen prüfen“, was Dolibarr selbst aus
dem PDF liest: wer unterschrieben hat (Name im Zertifikat) und ob das PDF seit
jeder Unterschrift unverändert ist. Diese Prüfung braucht auf dem
Dolibarr-Server PHP 8.

Ob ein Zertifikat gültig und qualifiziert ist, sagt PDF-AS nur mit einem zweiten
Dienst, **MOA-SP**. Den brauchst du nicht. Amtlich und kostenlos prüft das jede
Person selbst: das PDF herunterladen und unter
[signaturpruefung.gv.at](https://www.signaturpruefung.gv.at) hochladen.

## Was wir geprüft haben – und was nicht

Mit PDF-AS 5.0.0 im Docker-Container (September 2026) geprüft:

- die Schnittstelle nimmt das PDF an,
- für ID Austria kommt die Weiterleitungsadresse zurück, und die Seite dort
  führt zur Anmeldung bei ID Austria (A-Trust),
- zwei Signaturen mit Testschlüssel nacheinander,
- Dolibarr liest beide Signaturen samt Namen und erkennt ein nachträglich
  verändertes PDF.

Die Bestätigung mit einer echten ID Austria am Handy ließ sich ohne echte
Person nicht prüfen. Das ist der erste Test auf deinem Server. Ob die Whitelist
fremde Rücksprung-Adressen abweist, zeigt sich ebenfalls erst dort. Die
Einstellungen für DNS, Reverse Proxy und die Beschränkung auf das Heimnetz sind
Empfehlungen und hängen von deinem Netz ab.

## Quellen

- [PDF-AS bei A-SIT](https://technology.a-sit.at/en/pdf-as/),
  [Quellcode und Versionen](https://github.com/a-sit/pdf-as)
- EGIZ: „Anbindung externer Webanwendung an PDF-AS-WEB 5.0“, Version 1.2 vom
  30.04.2025
- [Art. 25 eIDAS-Verordnung](https://eur-lex.europa.eu/legal-content/DE/TXT/?uri=CELEX:32014R0910), § 4 SVG
