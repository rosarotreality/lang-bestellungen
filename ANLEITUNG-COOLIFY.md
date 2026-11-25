# Bestellseite mit Coolify online bringen

Bereits erledigt: Domain `bestellen-lang.de` zeigt auf den Server (169.58.47.236), Brevo ist eingerichtet, Absender `bestellung@bestellen-lang.de` ist bestätigt.

Du brauchst: den Brevo-SMTP-Login und den SMTP-Schlüssel.

---

## Teil 1 – Dateien auf GitHub

1. ZIP entpacken.
2. Auf github.com oben rechts **+** → **New repository**.
   - Name: `bestellseite-lang`
   - **Private** ist ok, Coolify greift über die GitHub-App zu.
   - Keine Häkchen bei README, .gitignore oder Lizenz → **Create repository**.
3. Auf der leeren Repo-Seite **uploading an existing file** anklicken.
4. Den entpackten Ordner öffnen. Auf dem Mac vorher **Cmd + Shift + .** drücken, damit die versteckten Dateien `.gitignore` und `.dockerignore` sichtbar werden.
5. **Alles im Ordner** markieren (nicht den Ordner selbst) und ins Browserfenster ziehen: `Dockerfile`, `index.html`, `README.md`, `ANLEITUNG-COOLIFY.md` und die Ordner `api`, `assets`, `data`, `docker`.
6. Unten **Commit changes** klicken.
7. Kontrolle: Auf der Startseite des Repos liegen `Dockerfile` und `index.html` ganz oben, nicht in einem Unterordner.

## Teil 2 – GitHub mit Coolify verbinden (nur falls noch nicht geschehen)

1. In Coolify links **Sources** → **+ Add** → **GitHub App**.
2. **Register Now** → du landest bei GitHub → **Create GitHub App** → zurück in Coolify.
3. **Install Repositories on GitHub** → **Only select repositories** → `bestellseite-lang` wählen → **Install**.

Wenn deine GitHub-App schon eingerichtet ist, aber nur für bestimmte Repos: Auf GitHub unter Settings → Applications → (deine Coolify-App) → Configure das neue Repo `bestellseite-lang` hinzufügen.

## Teil 3 – Anwendung in Coolify anlegen

1. Links **Projects** → **+ Add** → Name `Metzgerei Lang` → speichern. Dann die Umgebung **production** öffnen.
2. **+ New** (bzw. „+ Add New Resource“) anklicken.
3. **Private Repository (with GitHub App)** wählen.
4. Deine GitHub-App wählen. Falls gefragt: deinen Contabo-Server wählen.
5. Repository `bestellseite-lang` wählen, Branch `main`.
6. **Build Pack: Dockerfile** auswählen (nicht Nixpacks!) → **Continue**.

## Teil 4 – Einstellungen (Seite „Configuration“ → „General“)

1. **Domains:** Den automatisch erzeugten Eintrag löschen und stattdessen einfügen:
   ```
   https://bestellen-lang.de,https://www.bestellen-lang.de
   ```
   Wichtig: mit `https://` und ohne Leerzeichen. Dann holt Coolify das SSL-Zertifikat automatisch.
2. **Ports Exposes:** `80`
3. **Base Directory:** `/` – **Dockerfile Location:** `/Dockerfile` (Standardwerte, nichts ändern).
4. Oben **Save**.

## Teil 5 – Mail-Zugangsdaten (Umgebungsvariablen)

1. Links im Menü der Anwendung **Environment Variables** öffnen.
2. Jede Zeile als eigene Variable anlegen (**+ Add**, Name links, Wert rechts, speichern).
   Wenn es einen Umschalter **Developer view** gibt, kannst du alle Zeilen auf einmal einfügen und speichern.

```
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=587
SMTP_SICHERHEIT=tls
SMTP_BENUTZER=HIER_DEN_BREVO_LOGIN
SMTP_PASSWORT=HIER_DEN_SMTP_SCHLUESSEL
MAIL_ABSENDER=bestellung@bestellen-lang.de
MAIL_EMPFAENGER=pirmin.lang@hotmail.de
MAIL_ANTWORT_AN=info@lang-partyservice.de
```

- `SMTP_BENUTZER` ist der Login aus Brevo → SMTP & API → Reiter SMTP (meist `…@smtp-brevo.com`), **nicht** deine normale Mailadresse.
- `SMTP_PASSWORT` ist der SMTP-Schlüssel (`xsmtpsib-…`), **nicht** dein Brevo-Passwort.
- **Tipp für den ersten Test:** `MAIL_EMPFAENGER` erst auf deine eigene Mailadresse setzen. Wenn alles klappt, auf `pirmin.lang@hotmail.de` ändern und neu deployen.
- Falls es bei einer Variablen Häkchen wie „Build Variable“ oder „Available at Buildtime“ gibt: nicht nötig, kann aus bleiben.

## Teil 6 – Starten

1. Oben rechts **Deploy** klicken.
2. Im Log mitlesen. Nach 1–3 Minuten sollte die Anwendung als **Running** (grün) angezeigt werden.
3. `https://bestellen-lang.de/api/status.php` aufrufen. Dort muss stehen:
   - `"sortiment_geladen": true`
   - `"smtp_benutzer_gesetzt": true`
   - `"smtp_passwort_gesetzt": true`
   - `"empfaenger_gesetzt": true`
   - `"zeitzone": "Europe/Berlin"`
4. `https://bestellen-lang.de` öffnen und eine Testbestellung mit deiner eigenen Mailadresse als Kunde abschicken.
5. Ankommen müssen zwei Mails: die Bestellung beim Empfänger und die Bestätigung beim Kunden. **Bei Hotmail auch im Junk-Ordner nachsehen** und den Absender als sicher markieren.

## Teil 7 – Link für die Metzgerei

Der Link für Kunden und für die Website der Metzgerei ist:

```
https://bestellen-lang.de
```

## Später: Änderungen

- Sortiment, Abholtage, Bestellschluss: `data/shop.json` auf GitHub bearbeiten (Datei anklicken → Stift-Symbol → ändern → **Commit changes**). Coolify baut dann normalerweise automatisch neu. Falls nicht: in Coolify auf **Redeploy** klicken.
- Mail-Daten ändern: in Coolify unter Environment Variables ändern, speichern, dann **Redeploy** bzw. **Restart**.

## Wenn etwas nicht klappt

| Problem | Lösung |
|---|---|
| Seite lädt nicht / „no available server“ | Ports Exposes muss `80` sein. Domain muss mit `https://` eingetragen sein. Nach DNS-Änderungen kann es bis zu ein paar Stunden dauern. |
| Browser warnt vor unsicherem Zertifikat | Ein paar Minuten warten und neu laden. Coolify holt das Zertifikat erst, wenn die Domain auf den Server zeigt. |
| „Ihre Bestellung konnte gerade nicht übermittelt werden“ | `status.php` prüfen. Dann Variable `DEBUG=1` setzen, Redeploy, nochmal bestellen: Die genaue Fehlermeldung steht dann im Browser unter Entwicklertools → Netzwerk → `bestellung.php` → Antwort. Danach `DEBUG` wieder löschen. Häufigster Fehler: Login oder Schlüssel vertauscht. |
| Mail kommt nicht an | Junk-Ordner prüfen. In Brevo unter **Transactional** → **Logs** sieht man, ob die Mail rausging. |
| Build schlägt fehl | Prüfen, ob `Dockerfile` und der Ordner `docker` ganz oben im Repo liegen und Build Pack auf **Dockerfile** steht. |
