<?php
declare(strict_types=1);
/**
 * Kurzer Selbsttest für die Einrichtung: /api/status.php
 * Zeigt nur, OB Werte gesetzt sind – niemals Passwörter.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$config = require __DIR__ . '/config.php';
$shop = json_decode((string) @file_get_contents($config['shop_json']), true);
echo json_encode([
    'zeitzone'          => date_default_timezone_get(),
    'sortiment_geladen' => is_array($shop) && !empty($shop['produkte']),
    'anzahl_produkte'   => is_array($shop) ? count($shop['produkte'] ?? []) : 0,
    'smtp_host_gesetzt' => $config['smtp']['host'] !== '',
    'smtp_benutzer_gesetzt' => $config['smtp']['benutzer'] !== '',
    'smtp_passwort_gesetzt' => $config['smtp']['passwort'] !== '',
    'absender_gesetzt'  => filter_var($config['absender'], FILTER_VALIDATE_EMAIL) !== false,
    'empfaenger_gesetzt' => filter_var($config['empfaenger'], FILTER_VALIDATE_EMAIL) !== false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
