# Bestellseite Metzgerei Lang

Kunden stellen sich online eine Bestellung zusammen, wählen Abholtag und Uhrzeit, und die Bestellung landet per Mail bei der Metzgerei. Der Kunde bekommt eine Eingangsbestätigung. Bezahlt wird bei Abholung.

Es gibt keine Datenbank, kein Framework und keine externen Bibliotheken. Du brauchst nur einen Webserver mit **PHP 8.1 oder neuer**.

## Testen auf GitHub Pages (ohne eigenen Server)

GitHub Pages kann kein PHP ausführen. Deshalb gibt es einen **Testmodus**: Läuft die Seite unter `*.github.io`, schaltet er sich automatisch ein.

- Oben erscheint ein roter Balken „Testmodus – Bestellungen werden nicht verschickt“.
- Der komplette Ablauf funktioniert: Warenkorb, Termin, Formular, Danke-Seite.
- Beim Absenden geht nichts raus. Stattdessen zeigt die Danke-Seite, wie die Mail an die Metzgerei aussehen würde.

So richtest du es ein:

1. Neues Repo anlegen und den Inhalt des Ordners hochladen. Die Dateien `.nojekyll` und `.gitignore` müssen mit rein.
2. Im Repo unter **Settings → Pages** „Deploy from a branch“ wählen, dann Branch `main` und Ordner `/ (root)`.
3. Nach ca. einer Minute läuft die Seite unter `https://DEIN-NAME.github.io/REPO-NAME/`.

Achtung:
- Bei kostenlosen Accounts ist das Repo öffentlich. Echte Zugangsdaten dürfen nie ins Repo, siehe `config.local.php` unten.
- Auf deinem eigenen Server ist der Testmodus automatisch aus (`"testmodus": "auto"` in `shop.json`). Mit `true` oder `false` kannst du ihn erzwingen.

## Dateien

```
index.html              Die Bestellseite
assets/style.css        Design (Farben/Schriften wie lang-partyservice.de)
assets/app.js           Warenkorb, Terminwahl, Absenden
assets/logo.gif         ← fehlt noch: Logo der Metzgerei hier ablegen
data/shop.json          Sortiment, Preise, Abholzeiten, Betriebsdaten  ← wird gepflegt
api/bestellung.php      Nimmt Bestellungen an, prüft sie und verschickt die Mails
api/config.php          Standardwerte und Platzhalter (darf ins Git)
api/config.local.example.php  Vorlage für die echten Zugangsdaten
api/config.local.php    ← selbst anlegen: echte Adressen und SMTP-Passwort (NICHT ins Git)
api/lib/                Mailversand, Terminlogik, Mail-Vorlagen
```

## Einrichtung

1. **Hochladen:** Den ganzen Ordner auf deinen Server legen, idealerweise auf eine eigene Subdomain (z. B. `bestellen.lang-partyservice.de` per DNS auf deinen Server zeigen lassen). Die Seite muss per HTTPS erreichbar sein.
2. **Logo:** Das Logo als `assets/logo.gif` ablegen. Solange es fehlt, wird ersatzweise „Metzgerei LANG“ als Schriftzug angezeigt.
3. **Mail einrichten:** `api/config.local.example.php` kopieren, als `api/config.local.php` speichern und dort die Werte eintragen: `empfaenger`, `absender` und den SMTP-Zugang des Postfachs. Diese Datei lädst du nur auf den Server hoch, nie zu GitHub. Wichtig: Der Absender muss ein echtes Postfach auf der Domain der Metzgerei sein, über deren Mailserver verschickt wird. Sonst landen die Mails im Spam.
4. **Sortiment pflegen:** `data/shop.json` mit den echten Produkten, Preisen, Öffnungszeiten und Schließtagen füllen (siehe unten).
5. **Testbestellung** an eine eigene Adresse schicken. Wenn etwas hakt, in `config.local.php` vorübergehend `'debug' => true` setzen. Dann steht die technische Fehlermeldung in der Antwort (Browser → Entwicklertools → Netzwerk).
6. **Livegang:**
   - In `shop.json` `"demo_hinweis": false` setzen.
   - In `index.html` die Zeile `<meta name="robots" content="noindex, nofollow">` entfernen.
   - `debug` wieder auf `false` setzen.
   - Auf der Metzgerei-Website den Link bzw. Button „Online vorbestellen“ setzen.

## Sortiment (`data/shop.json`)

Jedes Produkt sieht so aus (die `//`-Kommentare dienen nur zur Erklärung, in der echten Datei weglassen):

```json
{
  "id": "rinderhueftsteak",          // eindeutig, nur a-z, 0-9, Bindestrich
  "kategorie": "rind",               // id aus "kategorien"
  "name": "Rinderhüftsteak",
  "artikelnr": "310",                // optional, erscheint nur in der Mail an die Metzgerei
                                     // je Option verschieden: {"heiß": "2032", "kalt": "807"}
  "beschreibung": "Aus der Hüfte …",
  "einheit": "stueck",               // "stueck" oder "kg"
  "preis_kg": 42.90,                 // Kilopreis …
  "stueckgewicht_g": 200,            // … plus ca.-Gewicht → Seite rechnet ca.-Stückpreis
  "min": 1, "schritt": 1, "max": 20,
  "optionen": { "label": "Zuschnitt", "werte": ["natur", "mariniert"] },
  "bild": "assets/produkte/huefte.jpg",   // optional
  "verfuegbar": true                       // false = ausgegraut, nicht bestellbar
}
```

- **Ohne Preis:** Fehlen `preis_kg` und `preis_stueck`, wird kein Preis angezeigt. Stehen im ganzen Sortiment keine Preise, blendet die Seite auch die Summe aus.
- **Gewichtsware:** `"einheit": "kg"` mit `preis_kg`. Dann gelten `min`, `schritt` und `max` in **Gramm** (z. B. 250 / 250 / 5000).
- **Stückware mit festem Preis:** `"einheit": "stueck"` mit `preis_stueck` (optional `"einheit_label": "Paar"`).
- **Stückware nach Gewicht** (Steaks, Schnitzel): `preis_kg` plus `stueckgewicht_g`. Angezeigt und gemailt wird dann ein ca.-Preis.
- **Abholung:**
  - `vorlauf_stunden`: so viele Stunden vorher muss bestellt werden.
  - `tage_im_voraus`: so weit im Voraus ist eine Bestellung möglich.
  - `erster_abholtag`: vorher keine Abholung, z. B. der Eröffnungstag.
  - `oeffnungszeiten`: pro Wochentag eine Liste von Zeitfenstern.
  - `geschlossen`: Feiertage und Urlaub als Datum.

Die Preise rechnet der Server bei jeder Bestellung aus `shop.json` selbst nach. Was im Browser steht, wird nie übernommen.

## Speicher, Cookies, Datenschutz

- **Keine Cookies, kein Tracking, keine externen Schriften.** Der Warenkorb liegt im `localStorage` des Browsers (wird nach 3 Tagen verworfen), die Formulareingaben im `sessionStorage` (weg, sobald der Tab zu ist). Beides ist technisch notwendig für die Funktion, die der Kunde selbst nutzt. Ein Cookie-Banner ist dafür in aller Regel nicht nötig.
- **Auf der Seite werden keine Bestellungen gespeichert,** sie gehen nur per Mail raus. Optional kann `backup_ordner` in `config.php` eine Kopie als JSON ablegen (Ordner außerhalb des Webroots, regelmäßig leeren).
- Die **Datenschutzerklärung** der Metzgerei muss die Bestellseite abdecken: Hosting auf deinem Server, Bestelldaten, Mailversand, `localStorage`. Weil du die Seite für die Metzgerei betreibst, braucht ihr außerdem einen **Auftragsverarbeitungsvertrag** (AVV).
- Spam-Schutz: unsichtbares Honeypot-Feld, Mindestzeit fürs Ausfüllen, Limit pro Anschluss. Die IP wird dabei nur als Hash kurzzeitig im Temp-Ordner gehalten.

## Lokal testen

```bash
php -S 127.0.0.1:8080
# → http://127.0.0.1:8080
```

Lokal ist der Testmodus aus, die Seite schickt also echt an `api/bestellung.php`. Wenn du nur durchklicken willst, setz in `shop.json` `"testmodus": true`.
