<?php
// Wochentagstyp-Berechnung für die Kalenderlogik.
// Feiertage Sachsen-Anhalt sind statisch hinterlegt.
// Schulferien werden aus der DB gelesen (gecacht pro Request).

/**
 * Berechnet den Wochentagstyp für ein gegebenes Datum.
 *
 * Rückgabewerte: 'MO-FR' | 'SA' | 'SO' | 'SF'
 *
 * Feiertage (Sachsen-Anhalt) werden als 'SO' behandelt, da sie nach
 * Sonntagsfahrplan fahren und gemeinsam mit Sonntagen ausgewertet werden.
 */
function getDayType(DateTimeInterface $date, PDO $db): string
{
    $weekday = (int) $date->format('N'); // 1=Mo, 7=So

    // Samstag und Sonntag direkt zurückgeben
    if ($weekday === 6) return 'SA';
    if ($weekday === 7) return 'SO';

    // Gesetzlicher Feiertag → Sonntagsfahrplan
    if (is_public_holiday($date)) return 'SO';

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
 * Gibt den Namen des gesetzlichen Feiertags zurück, oder null falls kein Feiertag.
 */
function get_public_holiday_name(DateTimeInterface $date): ?string
{
    $names = get_public_holiday_names((int) $date->format('Y'));
    return $names[$date->format('Y-m-d')] ?? null;
}

/**
 * Gibt ein Mapping von Datum → Feiertagsname für ein Jahr zurück.
 */
function get_public_holiday_names(int $year): array
{
    $easter = get_easter($year);

    return [
        "$year-01-01" => 'Neujahr',
        "$year-01-06" => 'Heilige Drei Könige',
        "$year-05-01" => 'Tag der Arbeit',
        "$year-05-08" => 'Weltfriedenstag',
        "$year-10-03" => 'Tag der deutschen Einheit',
        "$year-10-31" => 'Reformationstag',
        "$year-12-25" => '1. Weihnachtstag',
        "$year-12-26" => '2. Weihnachtstag',
        $easter->modify('-2 days')->format('Y-m-d')  => 'Karfreitag',
        $easter->modify('+1 days')->format('Y-m-d')  => 'Ostermontag',
        $easter->modify('+39 days')->format('Y-m-d') => 'Christi Himmelfahrt',
        $easter->modify('+50 days')->format('Y-m-d') => 'Pfingstmontag',
    ];
}

/**
 * Gibt den Namen des Schulferienblocks zurück, in dem das Datum liegt, oder null.
 * Schulferien werden aus der DB gelesen.
 */
function get_school_holiday_name(DateTimeInterface $date, PDO $db): ?string
{
    $dateStr = $date->format('Y-m-d');
    $stmt    = $db->prepare(
        'SELECT name FROM ' . tbl('school_holidays') . '
          WHERE date_from <= ? AND date_to >= ?
          LIMIT 1'
    );
    $stmt->execute([$dateStr, $dateStr]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? $row['name'] : null;
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
            'SELECT COUNT(*) FROM ' . tbl('school_holidays') . '
              WHERE date_from <= ? AND date_to >= ?'
        );
        $stmt->execute([$dateStr, $dateStr]);
        $cache[$dateStr] = (int) $stmt->fetchColumn() > 0;
    }

    return $cache[$dateStr];
}
