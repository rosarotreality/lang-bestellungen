<?php
/**
 * Kopieren als api/config.local.php und ausfüllen.
 * Diese Datei kommt NUR auf den Server – niemals ins Git / auf GitHub.
 * Alles, was hier nicht steht, kommt aus config.php.
 */
return [
    'empfaenger' => 'bestellung@lang-partyservice.de',
    'absender'   => 'bestellung@lang-partyservice.de',
    'antwort_an' => 'info@lang-partyservice.de',

    'smtp' => [
        'host'       => 'smtp.example.com',
        'port'       => 587,
        'sicherheit' => 'tls',
        'benutzer'   => 'bestellung@lang-partyservice.de',
        'passwort'   => 'HIER-PASSWORT-EINTRAGEN',
    ],

    // 'debug' => true,   // nur beim Einrichten
];
