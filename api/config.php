<?php
/**
 * Einstellungen – Metzgerei Lang Bestellseite
 *
 * Auf dem Server (Coolify) kommen alle Mail-Daten aus UMGEBUNGSVARIABLEN.
 * In Coolify: Anwendung → "Environment Variables". Siehe ANLEITUNG-COOLIFY.md.
 * In dieser Datei stehen nur Standardwerte – sie enthält KEINE Passwörter und darf ins Git.
 *
 *   SMTP_HOST         z. B. smtp-relay.brevo.com
 *   SMTP_PORT         587
 *   SMTP_SICHERHEIT   tls | ssl | keine
 *   SMTP_BENUTZER     SMTP-Login (bei Brevo: der Login aus "SMTP & API")
 *   SMTP_PASSWORT     SMTP-Schlüssel / Passwort
 *   MAIL_EMPFAENGER   wohin die Bestellungen gehen
 *   MAIL_ABSENDER     Absenderadresse (muss beim Mail-Dienst bestätigt sein)
 *   MAIL_ANTWORT_AN   wohin Kunden auf ihre Bestätigung antworten
 *   DEBUG             1 = technische Fehlermeldungen anzeigen (nur zum Einrichten)
 */

$env = static function (string $name, $standard = null) {
    $wert = getenv($name);
    if ($wert === false || $wert === '') {
        $wert = $_SERVER[$name] ?? $_ENV[$name] ?? null;
    }
    return ($wert === null || $wert === '') ? $standard : $wert;
};

$config = [

    /* ---- Wohin gehen neue Bestellungen? ---- */
    'empfaenger'      => $env('MAIL_EMPFAENGER', 'pirmin.lang@hotmail.de'),
    'empfaenger_name' => 'Metzgerei Lang – Bestellungen',

    /* ---- Absender aller Mails (bei Brevo als Absender bestätigt) ---- */
    'absender'        => $env('MAIL_ABSENDER', 'bestellung@bestellen-lang.de'),
    'absender_name'   => $env('MAIL_ABSENDER_NAME', 'Metzgerei Lang'),

    /* An diese Adresse gehen Antworten von Kunden auf ihre Bestätigungsmail. */
    'antwort_an'      => $env('MAIL_ANTWORT_AN', 'info@lang-partyservice.de'),

    /* Bekommt der Kunde eine Eingangsbestätigung per Mail? */
    'kunden_bestaetigung' => $env('KUNDEN_BESTAETIGUNG', '1') !== '0',

    /* ---- Versand: 'smtp' (empfohlen) oder 'mail' ---- */
    'versand' => $env('MAIL_VERSAND', 'smtp'),

    'smtp' => [
        'host'       => $env('SMTP_HOST', 'smtp-relay.brevo.com'),
        'port'       => (int) $env('SMTP_PORT', 587),
        'sicherheit' => $env('SMTP_SICHERHEIT', 'tls'),
        'benutzer'   => $env('SMTP_BENUTZER', ''),
        'passwort'   => $env('SMTP_PASSWORT', ''),
        'timeout'    => 15,
    ],

    /* ---- Sortiment & Abholzeiten ---- */
    'shop_json' => __DIR__ . '/../data/shop.json',

    /* ---- Optional: Kopie jeder Bestellung als JSON-Datei (Ordner außerhalb des Webordners). null = aus. ---- */
    'backup_ordner' => $env('BACKUP_ORDNER', null),

    /* ---- Spam-Schutz: max. Bestellungen pro Anschluss (IP) in X Minuten ---- */
    'limit' => ['max' => 5, 'minuten' => 10],

    /* Nur zum Einrichten: technische Fehlermeldungen werden mit ausgegeben. */
    'debug' => $env('DEBUG', '0') === '1',
];

/* Für lokale Tests ohne Coolify: api/config.local.php überschreibt einzelne Werte (nicht ins Git!). */
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace_recursive($config, require __DIR__ . '/config.local.php');
}

return $config;
