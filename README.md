# Bestellseite Metzgerei Lang – Weihnachten 2026

Kunden stellen sich online ihre Weihnachtsbestellung zusammen, wählen Abholtag und Uhrzeit, und die Bestellung geht per Mail an die Metzgerei (Kunde bekommt eine Eingangsbestätigung). Bezahlt wird bei Abholung.

Kein Framework, keine Datenbank. Läuft als Docker-Container (PHP 8.3 + Apache) – gebaut für **Coolify**. Schritt-für-Schritt-Anleitung: **ANLEITUNG-COOLIFY.md**.

## Dateien

```
index.html                Die Bestellseite
assets/                   Design, Logo, Script
data/shop.json            Sortiment, Abholzeiten, Hinweise  ← hier wird gepflegt
api/bestellung.php        Nimmt Bestellungen an, prüft sie, verschickt die Mails
api/status.php            Selbsttest: zeigt, ob alle Einstellungen gesetzt sind (ohne Passwörter)
api/config.php            Standardwerte – Mail-Zugangsdaten kommen aus Coolify (Umgebungsvariablen)
api/lib/                  Mailversand, Terminlogik, Mail-Vorlagen
Dockerfile, docker/       Bauanleitung für den Container
```

## Häufige Änderungen in `data/shop.json`

Nach jeder Änderung: Datei auf GitHub aktualisieren → Coolify baut automatisch neu (oder in Coolify auf „Redeploy“ klicken).

**Feste Abholtage** (z. B. nur 22.–24.12.):
```json
"abholtage": { "2026-12-22": [], "2026-12-23": [], "2026-12-24": [["07:00", "11:00"]] },
```
Leere Liste `[]` = normale Öffnungszeiten des Wochentags. Eigene Zeiten als `[["von", "bis"]]`.
Solange `abholtage` leer ist (`{}`), gilt: ab `erster_abholtag` für `tage_im_voraus` Tage, zu den `oeffnungszeiten`.

**Bestellschluss:** `"bestellschluss": "2026-12-20T12:00"` (deutsche Zeit). Danach ist die Bestellung gesperrt und die Seite zeigt die Telefonnummer. `null` = kein Bestellschluss.

**Vorlauf:** `"vorlauf_stunden": 24` – so viele Stunden vorher muss bestellt werden.

**Hinweise** über dem Sortiment: Liste `"hinweise"`.

**Produkte:**
```json
{ "id": "rinderbraten", "kategorie": "rind", "name": "Rinderbraten",
  "einheit": "kg", "min": 500, "schritt": 250, "max": 10000,
  "notiz_hinweis": "Welches Teilstück? Für wie viele Personen?" }
```
- `einheit`: `"kg"` (Mengen in Gramm) oder `"stueck"` (optional `"einheit_label": "Paar"` / `"Becher"`)
- `optionen`: Auswahlfeld, z. B. `{ "label": "Ausführung", "werte": ["ganze Gans", "Gänsebrust", "Gänsekeule"] }`
- `notiz_hinweis`: Platzhaltertext im Notizfeld der Warenkorbzeile
- `verfuegbar: false` = ausgegraut, nicht bestellbar
- **Preise** (noch nicht drin): `"preis_kg": 24.90` oder `"preis_stueck": 3.20`. Sobald Preise da sind, zeigt die Seite automatisch Preise und Summe.

## Speicher, Cookies, Datenschutz

- Keine Cookies, kein Tracking, keine externen Schriften. Der Warenkorb liegt im `localStorage` des Browsers (3 Tage), Formulareingaben im `sessionStorage`.
- Bestellungen werden nicht auf dem Server gespeichert, sie gehen nur per Mail raus (Versand über Brevo).
- Die Datenschutzerklärung, auf die die Seite verlinkt, muss die Bestellseite abdecken (Hosting, Bestelldaten, Mailversand über Brevo). Wer die Seite für die Metzgerei betreibt, braucht mit ihr einen Auftragsverarbeitungsvertrag (AVV).

## Lokal testen

```bash
SMTP_HOST=… SMTP_BENUTZER=… SMTP_PASSWORT=… php -S 127.0.0.1:8080
```
Auf `*.github.io` schaltet sich automatisch ein Testmodus ein (nichts wird verschickt).
