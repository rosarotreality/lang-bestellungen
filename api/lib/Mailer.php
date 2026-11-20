<?php
declare(strict_types=1);

/**
 * Schlanker Mailversand ohne externe Bibliotheken.
 * Versendet multipart-Mails (Text + HTML, UTF-8) per SMTP (STARTTLS/SSL, AUTH LOGIN)
 * oder – falls gewünscht – über PHPs mail().
 */
final class Mailer
{
    public function __construct(private array $config) {}

    /**
     * @throws RuntimeException wenn der Versand fehlschlägt
     */
    public function senden(
        string $an,
        string $anName,
        string $betreff,
        string $text,
        string $html,
        ?string $antwortAn = null,
        ?string $antwortName = null
    ): void {
        $von = $this->config['absender'];
        $vonName = $this->config['absender_name'] ?? '';

        foreach ([$an, $von] as $adresse) {
            if (!filter_var($adresse, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Ungültige E-Mail-Adresse: ' . $adresse);
            }
        }
        if ($antwortAn !== null && !filter_var($antwortAn, FILTER_VALIDATE_EMAIL)) {
            $antwortAn = null;
        }

        $grenze = 'grenze_' . bin2hex(random_bytes(12));
        $domain = substr(strrchr($von, '@') ?: '@localhost', 1);

        $kopf = [
            'Date'         => date(DATE_RFC2822),
            'From'         => self::adresse($von, $vonName),
            'To'           => self::adresse($an, $anName),
            'Subject'      => self::kodieren($betreff),
            'Message-ID'   => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $grenze . '"',
            'X-Mailer'     => 'Lang-Bestellseite',
        ];
        if ($antwortAn) {
            $kopf['Reply-To'] = self::adresse($antwortAn, $antwortName ?? '');
        }

        $rumpf = '--' . $grenze . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(self::crlf($text)) . "\r\n"
            . '--' . $grenze . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(self::crlf($html)) . "\r\n"
            . '--' . $grenze . "--\r\n";

        if (($this->config['versand'] ?? 'smtp') === 'mail') {
            $this->perMail($an, $kopf, $rumpf, $von);
            return;
        }

        $nachricht = '';
        foreach ($kopf as $name => $wert) {
            $nachricht .= $name . ': ' . $wert . "\r\n";
        }
        $this->perSmtp($von, $an, $nachricht . "\r\n" . $rumpf);
    }

    /* ---------- PHP mail() ---------- */
    private function perMail(string $an, array $kopf, string $rumpf, string $von): void
    {
        $empfaenger = $kopf['To'];
        $betreff = $kopf['Subject'];
        unset($kopf['To'], $kopf['Subject']);
        $zeilen = [];
        foreach ($kopf as $name => $wert) {
            $zeilen[] = $name . ': ' . $wert;
        }
        if (!mail($empfaenger, $betreff, $rumpf, implode("\r\n", $zeilen), '-f' . $von)) {
            throw new RuntimeException('mail() hat den Versand abgelehnt.');
        }
    }

    /* ---------- SMTP ---------- */
    private function perSmtp(string $von, string $an, string $daten): void
    {
        $s = $this->config['smtp'] ?? [];
        $host = (string) ($s['host'] ?? '');
        $port = (int) ($s['port'] ?? 587);
        $sicherheit = $s['sicherheit'] ?? 'tls';
        $timeout = (int) ($s['timeout'] ?? 15);
        $helo = $s['helo'] ?? (substr(strrchr($von, '@') ?: '@localhost', 1));

        $kontext = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
        ]]);
        $ziel = ($sicherheit === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($ziel, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $kontext);
        if (!$fp) {
            throw new RuntimeException("SMTP-Verbindung zu $ziel fehlgeschlagen: $errstr ($errno)");
        }
        stream_set_timeout($fp, $timeout);

        try {
            $this->erwarte($fp, [220]);
            $this->befehl($fp, 'EHLO ' . $helo, [250]);

            if ($sicherheit === 'tls') {
                $this->befehl($fp, 'STARTTLS', [220]);
                $methode = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                    | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
                if (!stream_socket_enable_crypto($fp, true, $methode)) {
                    throw new RuntimeException('STARTTLS fehlgeschlagen.');
                }
                $this->befehl($fp, 'EHLO ' . $helo, [250]);
            }

            if (!empty($s['benutzer'])) {
                $this->befehl($fp, 'AUTH LOGIN', [334]);
                $this->befehl($fp, base64_encode((string) $s['benutzer']), [334], 'AUTH Benutzer');
                $this->befehl($fp, base64_encode((string) ($s['passwort'] ?? '')), [235], 'AUTH Passwort');
            }

            $this->befehl($fp, 'MAIL FROM:<' . $von . '>', [250]);
            $this->befehl($fp, 'RCPT TO:<' . $an . '>', [250, 251]);
            $this->befehl($fp, 'DATA', [354]);

            // Punkt am Zeilenanfang verdoppeln (RFC 5321, 4.5.2)
            $daten = preg_replace('/^\./m', '..', $daten);
            $this->schreibe($fp, $daten . "\r\n.\r\n");
            $this->erwarte($fp, [250]);

            try {
                $this->befehl($fp, 'QUIT', [221]);
            } catch (RuntimeException) {
                // Mail ist bereits angenommen – Fehler beim Abmelden ist egal.
            }
        } finally {
            fclose($fp);
        }
    }

    private function befehl($fp, string $zeile, array $codes, ?string $logName = null): string
    {
        $this->schreibe($fp, $zeile . "\r\n");
        try {
            return $this->erwarte($fp, $codes);
        } catch (RuntimeException $e) {
            throw new RuntimeException(($logName ?? strtok($zeile, ' ')) . ': ' . $e->getMessage());
        }
    }

    private function schreibe($fp, string $daten): void
    {
        $laenge = strlen($daten);
        for ($geschrieben = 0; $geschrieben < $laenge; $geschrieben += $n) {
            $n = fwrite($fp, substr($daten, $geschrieben));
            if ($n === false || $n === 0) {
                throw new RuntimeException('Schreiben zum SMTP-Server fehlgeschlagen.');
            }
        }
    }

    private function erwarte($fp, array $codes): string
    {
        $antwort = '';
        while (($zeile = fgets($fp, 1024)) !== false) {
            $antwort .= $zeile;
            if (strlen($zeile) < 4 || $zeile[3] === ' ') {
                break;
            }
        }
        if ($antwort === '') {
            $meta = stream_get_meta_data($fp);
            throw new RuntimeException($meta['timed_out'] ? 'Zeitüberschreitung' : 'Keine Antwort vom Server');
        }
        $code = (int) substr($antwort, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('Unerwartete Antwort: ' . trim($antwort));
        }
        return $antwort;
    }

    /* ---------- Helfer ---------- */
    private static function ohneUmbruch(string $s): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
    }

    private static function kodieren(string $s): string
    {
        $s = self::ohneUmbruch($s);
        return preg_match('/[^\x20-\x7E]/', $s) ? mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n") : $s;
    }

    private static function adresse(string $email, string $name): string
    {
        $email = self::ohneUmbruch($email);
        $name = self::ohneUmbruch($name);
        if ($name === '') {
            return $email;
        }
        $name = preg_match('/[^\x20-\x7E]/', $name)
            ? mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n")
            : '"' . addcslashes($name, '"\\') . '"';
        return $name . ' <' . $email . '>';
    }

    private static function crlf(string $s): string
    {
        return str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $s);
    }
}
