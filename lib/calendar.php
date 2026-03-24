<?php
// Wochentagstyp-Berechnung für die Kalenderlogik.
// Feiertage Sachsen-Anhalt sind statisch hinterlegt.
// Schulferien werden aus der DB gelesen (gecacht pro Request).

/**
 * Berechnet den Wochentagstyp für ein gegebenes Datum.
 *
 * Rückgabewerte: 'MO-FR' | 'SA' | 'SO' | 'FT' | 'SF'
 */
function getDayType(DateTimeInterface $date, PDO $db): string
{
    $weekday = (int) $date->format('N'); // 1=Mo, 7=So

    // Samstag und Sonntag direkt zurückgeben
    if ($weekday === 6) return 'SA';
    if ($weekday === 7) return 'SO';

    // Werktag: Feiertag prüfen
    if (is_public_holiday($date)) return 'FT';

    // Schulferientag (Mo–Fr, kein Feiertag)
    if (is_school_holiday($date, $db)) return 'SF';

    return 'MO-FR';
}

/**
 * Prüft, ob das Datum ein gesetzlicher Feiertag in Sachsen-Anhalt ist.
 */
function is_public_holiday(DateTimeInterface $date): bool
{
    $holidays = get_public_holidays((int) $date->format('Y'));
    $dateStr = $date->format('Y-m-d');

    return in_array($dateStr, $holidays, true);
}

/**
 * Berechnet alle gesetzlichen Feiertage in Sachsen-Anhalt für ein Jahr.
 * Gibt ein Array von Datumsstrings im Format 'Y-m-d' zurück.
 */
function get_public_holidays(int $year): array
{
    $easter = get_easter($year);

    return [
        // Feste Feiertage
        "$year-01-01", // Neujahr
        "$year-01-06", // Heilige Drei Könige (Sachsen-Anhalt)
        "$year-05-01", // Tag der Arbeit
        "$year-05-08", // Weltfriedenstag (Sachsen-Anhalt, seit 2025)
        "$year-10-03", // Tag der deutschen Einheit
        "$year-10-31", // Reformationstag (Sachsen-Anhalt)
        "$year-12-25", // 1. Weihnachtstag
        "$year-12-26", // 2. Weihnachtstag

        // Bewegliche Feiertage (berechnet aus Ostersonntag)
        $easter->modify('-2 days')->format('Y-m-d'), // Karfreitag
        $easter->modify('+1 days')->format('Y-m-d'), // Ostermontag
        $easter->modify('+39 days')->format('Y-m-d'), // Christi Himmelfahrt
        $easter->modify('+50 days')->format('Y-m-d'), // Pfingstmontag
    ];
}

/**
 * Berechnet den Ostersonntag für ein gegebenes Jahr (Gaußsche Osterformel).
 */
function get_easter(int $year): DateTimeImmutable
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

/**
 * Prüft, ob das Datum innerhalb eines Schulferienintervalls liegt (Mo–Fr).
 * Schulferien werden aus der DB gelesen, Ergebnis wird pro Request gecacht.
 */
function is_school_holiday(DateTimeInterface $date, PDO $db): bool
{
    static $cache = [];

    $dateStr = $date->format('Y-m-d');

    if (!array_key_exists($dateStr, $cache)) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM school_holidays
              WHERE date_from <= ? AND date_to >= ?'
        );
        $stmt->execute([$dateStr, $dateStr]);
        $cache[$dateStr] = (int) $stmt->fetchColumn() > 0;
    }

    return $cache[$dateStr];
}
