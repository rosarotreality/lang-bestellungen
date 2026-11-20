<?php
/**
 * Einstellungen – Metzgerei Lang Bestellseite
 *
 * Hier stehen nur Platzhalter und Standardwerte – diese Datei darf ins Git.
 *
 * ECHTE ADRESSEN UND DAS SMTP-PASSWORT GEHÖREN IN api/config.local.php
 * (Vorlage: config.local.example.php kopieren und umbenennen).
 * config.local.php überschreibt die Werte hier und ist per .gitignore vom Repo ausgeschlossen.
 */

$config = [

    /* ---- Wohin gehen neue Bestellungen? ---- */
    'empfaenger'      => 'bestellung@lang-partyservice.de',   // PLATZHALTER – Postfach wird noch angelegt
    'empfaenger_name' => 'Metzgerei Lang – Bestellungen',

    /* ---- Absender aller Mails ----
       Muss zum SMTP-Konto unten gehören (gleiche Domain), sonst landen die Mails im Spam. */
    'absender'        => 'bestellung@lang-partyservice.de',   // PLATZHALTER
    'absender_name'   => 'Metzgerei Lang',

    /* An diese Adresse gehen Antworten von Kunden auf ihre Bestätigungsmail. */
    'antwort_an'      => 'info@lang-partyservice.de',

    /* Bekommt der Kunde eine Eingangsbestätigung per Mail? */
    'kunden_bestaetigung' => true,

    /* ---- Versand ----
       'smtp' (empfohlen): über das Mailkonto der Metzgerei
       'mail': PHPs mail() – nur wenn dein Server dafür sauber eingerichtet ist (SPF/DKIM) */
    'versand' => 'smtp',

    'smtp' => [
        'host'       => 'smtp.example.com',                  // PLATZHALTER – z. B. smtp.ionos.de, smtp.strato.de, w0123456.kasserver.com
        'port'       => 587,                                 // 587 = STARTTLS, 465 = SSL
        'sicherheit' => 'tls',                               // 'tls' (STARTTLS), 'ssl' oder 'keine' (nur lokal zum Testen)
        'benutzer'   => 'bestellung@lang-partyservice.de',   // PLATZHALTER
        'passwort'   => 'HIER-PASSWORT-EINTRAGEN',           // PLATZHALTER
        'timeout'    => 15,
    ],

    /* ---- Sortiment & Abholzeiten ---- */
    'shop_json' => __DIR__ . '/../data/shop.json',

    /* ---- Optional: Kopie jeder Bestellung als JSON-Datei speichern ----
       Sicherheitsnetz, falls eine Mail mal nicht ankommt.
       Ordner MUSS außerhalb des öffentlichen Webordners liegen, z. B. '/home/max/bestellungen-lang'.
       null = ausgeschaltet. Gespeicherte Bestellungen regelmäßig löschen (Datenschutz). */
    'backup_ordner' => null,

    /* ---- Spam-Schutz: max. Bestellungen pro Anschluss (IP) in X Minuten ---- */
    'limit' => ['max' => 5, 'minuten' => 10],

    /* Nur zum Einrichten auf true: technische Fehlermeldungen werden dann mit ausgegeben. */
    'debug' => false,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace_recursive($config, require __DIR__ . '/config.local.php');
}

return $config;
