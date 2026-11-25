<?php
declare(strict_types=1);

/**
 * Mail-Vorlagen (Text + HTML) für
 *  - die Metzgerei (neue Bestellung, druckfreundlich mit Abhak-Spalte)
 *  - den Kunden (Eingangsbestätigung)
 */
final class Vorlagen
{
    private const ROT = '#B3122A';
    private const DUNKEL = '#26211F';
    private const TEXT = '#3A3431';
    private const BRAUN = '#5E4330';
    private const CREME = '#F6F1E8';
    private const SAND = '#E6D9C6';
    private const LINIE = '#E7DFD3';
    private const SERIF = "Georgia,'Times New Roman',serif";
    private const SANS = 'Arial,Helvetica,sans-serif';

    /* =============== Mail an die Metzgerei =============== */
    public static function betrieb(array $b): array
    {
        $k = $b['kunde'];
        $name = $k['vorname'] . ' ' . $k['nachname'];
        $betreff = sprintf('Bestellung %s · Abholung %s %s Uhr · %s', $b['nummer'], Abholung::datumKurz($b['datum']), $b['zeit'], $name);

        // ---- Text ----
        $t = "NEUE BESTELLUNG {$b['nummer']}\n" . str_repeat('=', 44) . "\n\n";
        $t .= 'Abholung:  ' . Abholung::datumLang($b['datum']) . ", {$b['zeit']} Uhr\n\n";
        $t .= "Kunde:     $name\nAdresse:   {$k['strasse']}, {$k['ort']}\nTelefon:   {$k['telefon']}\nE-Mail:    {$k['email']}\n\n";
        $t .= "Positionen:\n" . self::textPositionen($b, true);
        if ($b['anmerkung'] !== '') {
            $t .= "\nAnmerkung des Kunden:\n" . $b['anmerkung'] . "\n";
        }
        $t .= "\n--\nEingegangen am " . $b['eingang']->format('d.m.Y \u\m H:i') . " Uhr über die Online-Bestellseite.\n";
        $t .= "Antworten auf diese Mail gehen direkt an den Kunden.\n";

        // ---- HTML ----
        $tel = preg_replace('/[^\d+]/', '', $k['telefon']);
        $inhalt = self::terminBox($b, 'Abholung')
            . self::abschnitt('Kunde')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:' . self::SANS . ';font-size:15px;color:' . self::TEXT . ';">'
            . self::datenZeile('Name', '<strong>' . self::e($name) . '</strong>')
            . self::datenZeile('Adresse', self::e($k['strasse']) . '<br>' . self::e($k['ort']))
            . self::datenZeile('Telefon', '<a href="tel:' . self::e($tel) . '" style="color:' . self::ROT . ';">' . self::e($k['telefon']) . '</a>')
            . self::datenZeile('E-Mail', '<a href="mailto:' . self::e($k['email']) . '" style="color:' . self::ROT . ';">' . self::e($k['email']) . '</a>')
            . '</table>'
            . self::abschnitt('Positionen')
            . self::htmlPositionen($b, true)
            . ($b['anmerkung'] !== '' ? self::anmerkung($b['anmerkung']) : '');

        $fuss = 'Eingegangen am ' . $b['eingang']->format('d.m.Y \u\m H:i') . ' Uhr über die Online-Bestellseite.<br>'
            . 'Antworten auf diese Mail gehen direkt an ' . self::e($name) . '.';

        $html = self::rahmen('Neue Bestellung', 'Bestellung ' . $b['nummer'], $inhalt, $fuss);
        return [$betreff, $t, $html];
    }

    /* =============== Bestätigung an den Kunden =============== */
    public static function kunde(array $b): array
    {
        $k = $b['kunde'];
        $bt = $b['betrieb'];
        $betreff = sprintf('Ihre Bestellung bei der %s (%s)', $bt['name'], $b['nummer']);
        $termin = Abholung::datumLang($b['datum']) . ", {$b['zeit']} Uhr";

        // ---- Text ----
        $t = "Guten Tag {$k['vorname']} {$k['nachname']},\n\n";
        $t .= "vielen Dank für Ihre Bestellung! Sie ist bei uns eingegangen und wir richten alles frisch für Sie her.\n";
        $t .= "Sollte etwas nicht verfügbar sein, melden wir uns bei Ihnen.\n\n";
        $t .= "Bestellnummer: {$b['nummer']}\nAbholung:      $termin\nWo:            {$bt['name']}, {$bt['strasse']}, {$bt['ort']}\n\n";
        $t .= "Ihre Bestellung:\n" . self::textPositionen($b);
        if ($b['anmerkung'] !== '') {
            $t .= "\nIhre Anmerkung:\n" . $b['anmerkung'] . "\n";
        }
        $t .= "\n" . (!$b['mit_preisen'] ? "Abgerechnet wird bei Abholung nach tatsächlichem Gewicht bzw. Stückzahl. " : ($b['ca'] ? "Bei Gewichtsware rechnen wir nach tatsächlichem Gewicht ab. " : '')) . ($bt['zahlung_hinweis'] ?? '') . "\n";
        $t .= "Änderungen oder Fragen? Rufen Sie uns an: {$bt['telefon']}\n\n";
        $t .= "Bis bald!\nIhre {$bt['name']}\n\n--\n{$bt['name']} · {$bt['strasse']} · {$bt['ort']}\nTelefon {$bt['telefon']} · {$bt['email']}\nImpressum: {$bt['impressum']}\n";

        // ---- HTML ----
        $tel = preg_replace('/[^\d+]/', '', $bt['telefon']);
        $p = 'style="font-family:' . self::SERIF . ';font-size:17px;line-height:1.6;color:' . self::TEXT . ';margin:0 0 14px;"';
        $inhalt = '<p ' . $p . '>Guten Tag ' . self::e($k['vorname'] . ' ' . $k['nachname']) . ',</p>'
            . '<p ' . $p . '>vielen Dank für Ihre Bestellung! Sie ist bei uns eingegangen und wir richten alles frisch für Sie her. Sollte etwas nicht verfügbar sein, melden wir uns bei Ihnen.</p>'
            . self::terminBox($b, 'Ihre Abholung', self::e($bt['name']) . ', ' . self::e($bt['strasse']) . ', ' . self::e($bt['ort']))
            . self::abschnitt('Ihre Bestellung')
            . self::htmlPositionen($b, false)
            . ($b['anmerkung'] !== '' ? self::anmerkung($b['anmerkung'], 'Ihre Anmerkung') : '')
            . '<p style="font-family:' . self::SANS . ';font-size:13.5px;line-height:1.55;color:' . self::BRAUN . ';margin:18px 0 0;">'
            . (!$b['mit_preisen'] ? 'Abgerechnet wird bei Abholung nach tatsächlichem Gewicht bzw. Stückzahl. ' : ($b['ca'] ? 'Bei Gewichtsware rechnen wir nach tatsächlichem Gewicht ab. ' : '')) . self::e($bt['zahlung_hinweis'] ?? '') . '<br>'
            . 'Änderungen oder Fragen? Rufen Sie uns an: <a href="tel:' . self::e($tel) . '" style="color:' . self::ROT . ';">' . self::e($bt['telefon']) . '</a></p>'
            . '<p ' . $p . '><br>Bis bald!<br>Ihre ' . self::e($bt['name']) . '</p>';

        $fuss = self::e($bt['name']) . ' · ' . self::e($bt['strasse']) . ' · ' . self::e($bt['ort']) . '<br>'
            . 'Telefon ' . self::e($bt['telefon']) . ' · <a href="mailto:' . self::e($bt['email']) . '" style="color:' . self::BRAUN . ';">' . self::e($bt['email']) . '</a><br>'
            . '<a href="' . self::e($bt['impressum']) . '" style="color:' . self::BRAUN . ';">Impressum</a> · '
            . '<a href="' . self::e($bt['datenschutz']) . '" style="color:' . self::BRAUN . ';">Datenschutz</a>';

        $html = self::rahmen('Bestellung eingegangen', 'Vielen Dank, ' . $k['vorname'] . '!', $inhalt, $fuss);
        return [$betreff, $t, $html];
    }

    /* =============== Bausteine =============== */
    private static function preis(array $z): string
    {
        return $z['preis'] === null ? '' : ($z['ca'] ? 'ca. ' : '') . self::euro($z['preis']);
    }

    private static function textPositionen(array $b, bool $mitNr = false): string
    {
        $t = '';
        foreach ($b['zeilen'] as $z) {
            $name = ($mitNr && $z['nr'] !== '' ? $z['nr'] . '  ' : '') . $z['name'];
            $t .= rtrim('  ' . self::pad($z['menge'], 10) . ' ' . self::pad($name, 30) . ' ' . self::pad(self::preis($z), 13, true)) . "\n";
            if ($z['option'] !== '') {
                $t .= '  ' . str_repeat(' ', 11) . '→ ' . $z['option'] . "\n";
            }
            if (($z['notiz'] ?? '') !== '') {
                $t .= '  ' . str_repeat(' ', 11) . '→ Notiz: ' . $z['notiz'] . "\n";
            }
        }
        if (!$b['mit_preisen']) {
            return $t;
        }
        $t .= '  ' . str_repeat('-', 56) . "\n";
        $t .= '  ' . self::pad('Summe', 41) . ' ' . self::pad(($b['ca'] ? 'ca. ' : '') . self::euro($b['summe']), 13, true) . "\n";
        return $t;
    }

    private static function htmlPositionen(array $b, bool $abhaken): string
    {
        $th = 'style="font-family:' . self::SANS . ';font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:' . self::BRAUN . ';font-weight:normal;text-align:left;padding:0 8px 8px 0;border-bottom:2px solid ' . self::DUNKEL . ';"';
        $td = 'font-family:' . self::SANS . ';font-size:15px;color:' . self::TEXT . ';padding:11px 8px 11px 0;border-bottom:1px solid ' . self::LINIE . ';vertical-align:top;';
        $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
        $mitPreis = $b['mit_preisen'];
        $mitNr = $abhaken && (bool) array_filter($b['zeilen'], fn ($z) => ($z['nr'] ?? '') !== '');
        $h .= '<tr><th ' . $th . '>Menge</th>' . ($mitNr ? '<th ' . $th . '>Nr.</th>' : '') . '<th ' . str_replace('style="', 'style="width:60%;', $th) . '>Artikel</th>'
            . ($mitPreis ? '<th ' . str_replace('text-align:left', 'text-align:right', $th) . '>Preis</th>' : '')
            . ($abhaken ? '<th ' . str_replace('padding:0 8px 8px 0', 'padding:0 0 8px 8px', $th) . ' width="22">✓</th>' : '') . '</tr>';
        foreach ($b['zeilen'] as $z) {
            $h .= '<tr>'
                . '<td style="' . $td . 'white-space:nowrap;font-weight:bold;color:' . self::DUNKEL . ';">' . self::e($z['menge']) . '</td>'
                . ($mitNr ? '<td style="' . $td . 'white-space:nowrap;color:' . self::BRAUN . ';">' . self::e($z['nr']) . '</td>' : '')
                . '<td style="' . $td . '">' . self::e($z['name'])
                . ($z['option'] !== '' ? '<br><span style="font-size:13px;color:' . self::BRAUN . ';">' . self::e($z['option']) . '</span>' : '')
                . (($z['notiz'] ?? '') !== '' ? '<br><span style="font-size:13px;color:' . self::DUNKEL . ';background:' . self::SAND . ';padding:1px 5px;"><b>Notiz:</b> ' . self::e($z['notiz']) . '</span>' : '') . '</td>'
                . ($mitPreis ? '<td style="' . $td . 'text-align:right;white-space:nowrap;">' . self::preis($z) . '</td>' : '')
                . ($abhaken ? '<td style="' . $td . 'padding-left:8px;padding-right:0;"><div style="width:16px;height:16px;border:1.5px solid ' . self::BRAUN . ';"></div></td>' : '')
                . '</tr>';
        }
        if (!$mitPreis) {
            return $h . '</table>';
        }
        $h .= '<tr><td colspan="' . ($mitNr ? 3 : 2) . '" style="font-family:' . self::SANS . ';font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:' . self::BRAUN . ';padding:14px 8px 0 0;">Summe' . ($b['ca'] ? ' (ca.)' : '') . '</td>'
            . '<td style="font-family:' . self::SERIF . ';font-size:21px;font-weight:bold;color:' . self::DUNKEL . ';text-align:right;white-space:nowrap;padding:10px 8px 0 0;">' . self::euro($b['summe']) . '</td>'
            . ($abhaken ? '<td></td>' : '') . '</tr>';
        return $h . '</table>';
    }

    private static function terminBox(array $b, string $label, ?string $ort = null): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 6px;">'
            . '<tr><td style="background:' . self::CREME . ';border-left:4px solid ' . self::ROT . ';padding:16px 18px;">'
            . '<div style="font-family:' . self::SANS . ';font-size:11px;letter-spacing:2px;text-transform:uppercase;color:' . self::BRAUN . ';">' . self::e($label) . '</div>'
            . '<div style="font-family:' . self::SERIF . ';font-size:21px;line-height:1.35;color:' . self::DUNKEL . ';margin-top:4px;">'
            . self::e(Abholung::datumLang($b['datum'])) . '<br><strong>' . self::e($b['zeit']) . ' Uhr</strong></div>'
            . ($ort ? '<div style="font-family:' . self::SANS . ';font-size:14px;color:' . self::TEXT . ';margin-top:6px;">' . $ort . '</div>' : '')
            . '<div style="font-family:' . self::SANS . ';font-size:13px;color:' . self::BRAUN . ';margin-top:8px;">Bestellnummer <strong style="color:' . self::DUNKEL . ';letter-spacing:1px;">' . self::e($b['nummer']) . '</strong></div>'
            . '</td></tr></table>';
    }

    private static function abschnitt(string $titel): string
    {
        return '<div style="font-family:' . self::SERIF . ';font-size:20px;color:' . self::DUNKEL . ';margin:26px 0 4px;">' . self::e($titel) . '</div>'
            . '<div style="width:44px;height:3px;background:' . self::ROT . ';margin:0 0 14px;line-height:3px;font-size:0;">&nbsp;</div>';
    }

    private static function datenZeile(string $label, string $wert): string
    {
        return '<tr><td style="padding:4px 14px 4px 0;width:80px;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:' . self::BRAUN . ';vertical-align:top;">' . self::e($label) . '</td>'
            . '<td style="padding:4px 0;">' . $wert . '</td></tr>';
    }

    private static function anmerkung(string $text, string $titel = 'Anmerkung des Kunden'): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:22px;"><tr><td style="background:' . self::SAND . ';padding:14px 16px;">'
            . '<div style="font-family:' . self::SANS . ';font-size:11px;letter-spacing:2px;text-transform:uppercase;color:' . self::BRAUN . ';margin-bottom:4px;">' . self::e($titel) . '</div>'
            . '<div style="font-family:' . self::SANS . ';font-size:15px;line-height:1.5;color:' . self::DUNKEL . ';">' . nl2br(self::e($text)) . '</div>'
            . '</td></tr></table>';
    }

    private static function rahmen(string $kicker, string $titel, string $inhalt, string $fuss): string
    {
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e($titel) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . self::CREME . ';">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::CREME . ';"><tr><td align="center" style="padding:24px 10px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;">'
            . '<tr><td style="background:' . self::DUNKEL . ';padding:24px 28px 22px;border-bottom:4px solid ' . self::ROT . ';">'
            . '<div style="font-family:' . self::SANS . ';font-size:11px;letter-spacing:3px;text-transform:uppercase;color:' . self::SAND . ';">Metzgerei Lang · ' . self::e($kicker) . '</div>'
            . '<div style="font-family:' . self::SERIF . ';font-size:27px;line-height:1.25;color:#ffffff;margin-top:6px;font-weight:bold;">' . self::e($titel) . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:26px 28px 30px;">' . $inhalt . '</td></tr>'
            . '<tr><td style="background:' . self::CREME . ';padding:16px 28px;font-family:' . self::SANS . ';font-size:12px;line-height:1.6;color:' . self::BRAUN . ';">' . $fuss . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** Auffüllen mit Leerzeichen, UTF-8-sicher (mb_str_pad gibt es erst ab PHP 8.3) */
    private static function pad(string $s, int $laenge, bool $links = false): string
    {
        $fehlt = max(0, $laenge - mb_strlen($s));
        return $links ? str_repeat(' ', $fehlt) . $s : $s . str_repeat(' ', $fehlt);
    }

    private static function euro(int $cent): string
    {
        return number_format($cent / 100, 2, ',', '.') . ' €';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
