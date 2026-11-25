<?php
declare(strict_types=1);

/**
 * Abholtermine – gleiche Logik wie in assets/app.js (berechneTage),
 * damit der Server jeden gewählten Termin selbst nachprüft.
 */
final class Abholung
{
    private const WOCHENTAGE = ['so', 'mo', 'di', 'mi', 'do', 'fr', 'sa'];

    /** Liefert alle buchbaren Uhrzeiten ("HH:MM") für einen Tag – leer, wenn der Tag nicht buchbar ist. */
    public static function slots(array $cfg, string $datumIso, DateTimeImmutable $jetzt): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datumIso)) {
            return [];
        }
        $tz = $jetzt->getTimezone();
        $tag = DateTimeImmutable::createFromFormat('!Y-m-d', $datumIso, $tz);
        if (!$tag || $tag->format('Y-m-d') !== $datumIso) {
            return [];
        }

        $feste = (isset($cfg['abholtage']) && is_array($cfg['abholtage']) && $cfg['abholtage']) ? $cfg['abholtage'] : null;
        $eigeneZeiten = null;

        if ($feste !== null) {
            // Nur die festgelegten Abholtage sind buchbar
            if (!array_key_exists($datumIso, $feste)) {
                return [];
            }
            if (is_array($feste[$datumIso]) && $feste[$datumIso]) {
                $eigeneZeiten = $feste[$datumIso];
            }
        } else {
            $start = $jetzt->setTime(0, 0);
            if (!empty($cfg['erster_abholtag'])) {
                $erster = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $cfg['erster_abholtag'], $tz);
                if ($erster && $erster > $start) {
                    $start = $erster;
                }
            }
            $ende = $start->modify('+' . (int) ($cfg['tage_im_voraus'] ?? 14) . ' days');
            if ($tag < $start || $tag > $ende) {
                return [];
            }
        }
        if (in_array($datumIso, $cfg['geschlossen'] ?? [], true)) {
            return [];
        }

        $wt = self::WOCHENTAGE[(int) $tag->format('w')];
        $fruehestens = $jetzt->modify('+' . (int) ($cfg['vorlauf_stunden'] ?? 0) . ' hours');
        $slot = max(5, (int) ($cfg['slot_minuten'] ?? 30));

        $slots = [];
        foreach ($eigeneZeiten ?? ($cfg['oeffnungszeiten'][$wt] ?? []) as $intervall) {
            [$von, $bis] = $intervall;
            for ($t = self::minuten($von); $t + $slot <= self::minuten($bis); $t += $slot) {
                $zeitpunkt = $tag->setTime(intdiv($t, 60), $t % 60);
                if ($zeitpunkt >= $fruehestens) {
                    $slots[] = sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
                }
            }
        }
        return $slots;
    }

    /** Ist der Bestellschluss (z. B. "2026-12-20T12:00", deutsche Zeit) vorbei? */
    public static function bestellschlussVorbei(array $cfg, DateTimeImmutable $jetzt): bool
    {
        if (empty($cfg['bestellschluss'])) {
            return false;
        }
        $schluss = new DateTimeImmutable((string) $cfg['bestellschluss'], $jetzt->getTimezone());
        return $jetzt > $schluss;
    }

    /** "Dienstag, 1. Dezember 2026" */
    public static function datumLang(string $datumIso): string
    {
        $tage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        $monate = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $datumIso);
        return $tage[(int) $d->format('w')] . ', ' . (int) $d->format('j') . '. ' . $monate[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
    }

    /** "Di 01.12." */
    public static function datumKurz(string $datumIso): string
    {
        $tage = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $datumIso);
        return $tage[(int) $d->format('w')] . ' ' . $d->format('d.m.');
    }

    private static function minuten(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $h * 60 + $m;
    }
}
