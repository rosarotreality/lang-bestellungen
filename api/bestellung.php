<?php
declare(strict_types=1);

/**
 * Metzgerei Lang – Bestellannahme
 *
 * Erwartet die Bestellung als JSON (POST) von assets/app.js,
 * prüft ALLES serverseitig gegen data/shop.json (Preise kommen nie vom Browser),
 * und verschickt:
 *   1. die Bestellung an die Metzgerei (Pflicht – schlägt das fehl, gilt die Bestellung als nicht angekommen)
 *   2. eine Eingangsbestätigung an den Kunden (optional)
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

date_default_timezone_set('Europe/Berlin');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/Mailer.php';
require __DIR__ . '/lib/Abholung.php';
require __DIR__ . '/lib/Vorlagen.php';

function antwort(int $status, array $daten): never
{
    http_response_code($status);
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}

function fehler(int $status, string $text, array $extra = []): never
{
    antwort($status, ['ok' => false, 'fehler' => $text] + $extra);
}

/** Text säubern: Steuerzeichen raus, Länge begrenzen. */
function sauber(mixed $wert, int $max, bool $mehrzeilig = false): string
{
    $s = is_string($wert) ? $wert : '';
    $s = $mehrzeilig
        ? preg_replace('/[^\P{C}\n]/u', '', str_replace("\r\n", "\n", $s))
        : preg_replace('/\p{C}/u', '', $s);
    return mb_substr(trim((string) $s), 0, $max);
}

/** Ganzzahlig in Cent rechnen – identisch zu assets/app.js */
function preisCent(array $p, int $menge): ?int
{
    if (!isset($p['preis_kg']) && !isset($p['preis_stueck'])) {
        return null;   // Artikel ohne Preisangabe
    }
    if ($p['einheit'] === 'kg') {
        return (int) round((int) round($p['preis_kg'] * 100) * $menge / 1000);
    }
    if (isset($p['preis_stueck'])) {
        return (int) round($p['preis_stueck'] * 100) * $menge;
    }
    return (int) round((int) round($p['preis_kg'] * 100) * (int) $p['stueckgewicht_g'] / 1000) * $menge;
}

function mengeText(array $p, int $menge): string
{
    if ($p['einheit'] === 'kg') {
        return $menge >= 1000
            ? rtrim(rtrim(number_format($menge / 1000, 3, ',', ''), '0'), ',') . ' kg'
            : $menge . ' g';
    }
    return $menge . ' ' . ($p['einheit_label'] ?? 'Stück');
}

/** Einfache Bremse gegen Massen-Anfragen, ohne IP-Adressen im Klartext zu speichern. */
function limitPruefen(array $config): void
{
    $max = (int) ($config['limit']['max'] ?? 5);
    $fenster = (int) ($config['limit']['minuten'] ?? 10) * 60;
    $schluessel = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . __DIR__);
    $datei = sys_get_temp_dir() . '/lang-bestellung-' . substr($schluessel, 0, 32) . '.json';

    $fp = @fopen($datei, 'c+');
    if (!$fp) {
        return; // Ohne Schreibrechte lieber bestellen lassen als blockieren.
    }
    flock($fp, LOCK_EX);
    $zeiten = json_decode(stream_get_contents($fp) ?: '[]', true) ?: [];
    $jetzt = time();
    $zeiten = array_values(array_filter($zeiten, fn ($t) => is_int($t) && $t > $jetzt - $fenster));
    if (count($zeiten) >= $max) {
        flock($fp, LOCK_UN);
        fclose($fp);
        fehler(429, 'Von Ihrem Anschluss kamen gerade sehr viele Bestellungen. Bitte warten Sie ein paar Minuten.');
    }
    $zeiten[] = $jetzt;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($zeiten));
    flock($fp, LOCK_UN);
    fclose($fp);
}

/* =========================================================
   1. Anfrage annehmen
   ========================================================= */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    fehler(405, 'Nur POST-Anfragen erlaubt.');
}

$roh = file_get_contents('php://input', false, null, 0, 32768);
$eingabe = json_decode($roh ?: '', true);
if (!is_array($eingabe)) {
    fehler(400, 'Ungültige Anfrage.');
}

// Honeypot ausgefüllt → Bot. Freundlich "ok" sagen, aber nichts tun.
if (!empty($eingabe['website'])) {
    antwort(200, ['ok' => true, 'bestellnummer' => 'L-000000-0000', 'bestaetigung_gesendet' => false]);
}
// Menschen brauchen ein paar Sekunden für das Formular.
if ((int) ($eingabe['dauer'] ?? 0) < 3000) {
    fehler(400, 'Das ging etwas zu schnell. Bitte versuchen Sie es noch einmal.');
}

$shop = json_decode((string) @file_get_contents($config['shop_json']), true);
if (!is_array($shop) || empty($shop['produkte'])) {
    error_log('[Bestellseite] shop.json fehlt oder ist ungültig: ' . $config['shop_json']);
    fehler(500, 'Die Bestellung kann gerade nicht verarbeitet werden.');
}

$produkte = [];
foreach ($shop['produkte'] as $p) {
    $istKg = ($p['einheit'] ?? '') === 'kg';
    $p += ['min' => $istKg ? 250 : 1, 'schritt' => $istKg ? 250 : 1, 'max' => $istKg ? 5000 : 50];
    $produkte[$p['id']] = $p;
}

/* =========================================================
   2. Prüfen
   ========================================================= */
$felder = [];

// --- Positionen ---
$positionen = $eingabe['positionen'] ?? null;
if (!is_array($positionen) || count($positionen) === 0) {
    fehler(422, 'Ihr Warenkorb ist leer.');
}
if (count($positionen) > 60) {
    fehler(422, 'Zu viele Positionen. Für Großbestellungen rufen Sie uns bitte an.');
}

$zeilen = [];
$summe = 0;
$ca = false;
foreach ($positionen as $pos) {
    $p = $produkte[(string) ($pos['id'] ?? '')] ?? null;
    if (!$p || ($p['verfuegbar'] ?? true) === false) {
        fehler(422, 'Ein Produkt in Ihrem Warenkorb ist leider nicht mehr verfügbar. Bitte laden Sie die Seite neu.');
    }
    $werte = $p['optionen']['werte'] ?? [];
    $option = (string) ($pos['option'] ?? '');
    if ($werte ? !in_array($option, $werte, true) : $option !== '') {
        fehler(422, 'Bitte prüfen Sie die Ausführung von „' . $p['name'] . '“.');
    }
    $menge = $pos['menge'] ?? null;
    if (!is_int($menge) || $menge < $p['min'] || $menge > $p['max'] || ($menge - $p['min']) % $p['schritt'] !== 0) {
        fehler(422, 'Ungültige Menge bei „' . $p['name'] . '“.');
    }
    $preis = preisCent($p, $menge);
    $istCa = isset($p['preis_kg']);
    $zeilen[] = [
        'menge'  => mengeText($p, $menge),
        'name'   => $p['name'],
        'option' => $option,
        'preis'  => $preis,
        'ca'     => $istCa,
        'nr'     => is_array($p['artikelnr'] ?? null) ? (string) ($p['artikelnr'][$option] ?? '') : (string) ($p['artikelnr'] ?? ''),
        'roh'    => ['id' => $p['id'], 'option' => $option, 'menge' => $menge],
    ];
    $summe += $preis ?? 0;
    $ca = $ca || $istCa;
}

// --- Kunde ---
$kunde = [
    'vorname'  => sauber($eingabe['kunde']['vorname'] ?? '', 60),
    'nachname' => sauber($eingabe['kunde']['nachname'] ?? '', 60),
    'telefon'  => sauber($eingabe['kunde']['telefon'] ?? '', 30),
    'email'    => sauber($eingabe['kunde']['email'] ?? '', 120),
];
$anmerkung = sauber($eingabe['anmerkung'] ?? '', 500, true);

if ($kunde['vorname'] === '') {
    $felder['vorname'] = 'Bitte geben Sie Ihren Vornamen an.';
}
if ($kunde['nachname'] === '') {
    $felder['nachname'] = 'Bitte geben Sie Ihren Nachnamen an.';
}
if (!preg_match('~^[0-9+()/\-\s]{6,30}$~', $kunde['telefon']) || strlen(preg_replace('/\D/', '', $kunde['telefon'])) < 6) {
    $felder['telefon'] = 'Bitte geben Sie eine gültige Telefonnummer an.';
}
if (!filter_var($kunde['email'], FILTER_VALIDATE_EMAIL)) {
    $felder['email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse an.';
}
if ($felder) {
    fehler(422, 'Bitte prüfen Sie Ihre Angaben.', ['felder' => $felder]);
}

// --- Abholtermin ---
$jetzt = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
$datum = (string) ($eingabe['abholung']['datum'] ?? '');
$zeit = (string) ($eingabe['abholung']['zeit'] ?? '');
if (!in_array($zeit, Abholung::slots($shop['abholung'] ?? [], $datum, $jetzt), true)) {
    fehler(409, 'Der gewählte Abholtermin ist leider nicht mehr verfügbar. Bitte wählen Sie einen neuen Termin.');
}

/* =========================================================
   3. Bestellung anlegen & versenden
   ========================================================= */
limitPruefen($config);

$zeichen = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$code = '';
for ($i = 0; $i < 4; $i++) {
    $code .= $zeichen[random_int(0, strlen($zeichen) - 1)];
}

$bestellung = [
    'nummer'    => 'L-' . $jetzt->format('ymd') . '-' . $code,
    'eingang'   => $jetzt,
    'datum'     => $datum,
    'zeit'      => $zeit,
    'kunde'     => $kunde,
    'anmerkung' => $anmerkung,
    'zeilen'    => $zeilen,
    'summe'     => $summe,
    'ca'        => $ca,
    'mit_preisen' => (bool) array_filter($shop['produkte'], fn ($x) => isset($x['preis_kg']) || isset($x['preis_stueck'])),
    'betrieb'   => $shop['betrieb'],
];

// Optionale Sicherungskopie (vor dem Versand, damit nichts verloren geht)
if (!empty($config['backup_ordner'])) {
    $ordner = rtrim((string) $config['backup_ordner'], '/');
    if (is_dir($ordner) && is_writable($ordner)) {
        $kopie = $bestellung;
        $kopie['eingang'] = $jetzt->format(DATE_ATOM);
        @file_put_contents($ordner . '/' . $bestellung['nummer'] . '.json', json_encode($kopie, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    } else {
        error_log('[Bestellseite] Backup-Ordner nicht beschreibbar: ' . $ordner);
    }
}

$mailer = new Mailer($config);
$name = $kunde['vorname'] . ' ' . $kunde['nachname'];

// 3a. An die Metzgerei – muss klappen
try {
    [$betreff, $text, $html] = Vorlagen::betrieb($bestellung);
    $mailer->senden($config['empfaenger'], $config['empfaenger_name'] ?? '', $betreff, $text, $html, $kunde['email'], $name);
} catch (Throwable $e) {
    error_log('[Bestellseite] Versand an Metzgerei fehlgeschlagen (' . $bestellung['nummer'] . '): ' . $e->getMessage());
    fehler(502, 'Ihre Bestellung konnte gerade nicht übermittelt werden. Bitte versuchen Sie es in ein paar Minuten noch einmal.',
        !empty($config['debug']) ? ['debug' => $e->getMessage()] : []);
}

// 3b. Bestätigung an den Kunden – Fehler hier verhindern die Bestellung nicht
$bestaetigt = false;
if (!empty($config['kunden_bestaetigung'])) {
    try {
        [$betreff, $text, $html] = Vorlagen::kunde($bestellung);
        $mailer->senden($kunde['email'], $name, $betreff, $text, $html, $config['antwort_an'] ?? null, $shop['betrieb']['name'] ?? null);
        $bestaetigt = true;
    } catch (Throwable $e) {
        error_log('[Bestellseite] Kundenbestätigung fehlgeschlagen (' . $bestellung['nummer'] . '): ' . $e->getMessage());
    }
}

antwort(200, [
    'ok' => true,
    'bestellnummer' => $bestellung['nummer'],
    'bestaetigung_gesendet' => $bestaetigt,
]);
