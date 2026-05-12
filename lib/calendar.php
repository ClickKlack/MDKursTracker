<?php
// Wochentagstyp-Berechnung für die Kalenderlogik.
// Gesetzliche Feiertage kommen aus public_holidays.json (gepflegt im Repo),
// die aktive Region wird aus config.php gelesen (Schlüssel 'active_region').
// Schulferien werden aus der DB gelesen (gecacht pro Request).

/**
 * Berechnet den Wochentagstyp für ein gegebenes Datum.
 *
 * Rückgabewerte: 'MO-FR' | 'SA' | 'SO' | 'SF'
 *
 * Feiertage werden als 'SO' behandelt, da sie nach Sonntagsfahrplan fahren
 * und gemeinsam mit Sonntagen ausgewertet werden.
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
 * Prüft, ob das Datum ein gesetzlicher Feiertag in der aktiven Region ist.
 */
function is_public_holiday(DateTimeInterface $date): bool
{
    $holidays = get_public_holidays((int) $date->format('Y'));
    $dateStr  = $date->format('Y-m-d');

    return in_array($dateStr, $holidays, true);
}

/**
 * Gibt alle gesetzlichen Feiertage für ein Jahr zurück (Y-m-d-Strings).
 *
 * Ohne $region wird die aktive Region aus config.php genutzt (Default 'ST').
 * Der Parameter erlaubt Tests, ohne den statischen Cache zu manipulieren.
 */
function get_public_holidays(int $year, ?string $region = null): array
{
    return array_keys(get_public_holiday_names($year, $region));
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
 * Gibt ein Mapping von Datum → Feiertagsname für ein Jahr und eine Region zurück.
 *
 * Single Source of Truth: aus dieser Funktion baut get_public_holidays() seine Liste.
 * Die Daten kommen aus public_holidays.json, die Region aus config.php (Default 'ST').
 */
function get_public_holiday_names(int $year, ?string $region = null): array
{
    $config = load_public_holiday_config();
    $region = $region ?? get_active_region();

    $result = [];

    // Feste Feiertage (Monat/Tag)
    foreach ($config['fixed'] as $entry) {
        if (!holiday_applies_to_region($entry, $region)) continue;
        $date = sprintf('%04d-%02d-%02d', $year, $entry['month'], $entry['day']);
        $result[$date] = $entry['name'];
    }

    // Bewegliche Feiertage (Offset relativ zum Ostersonntag)
    $easter = get_easter($year);
    foreach ($config['easter'] as $entry) {
        if (!holiday_applies_to_region($entry, $region)) continue;
        $date = $easter->modify(sprintf('%+d days', $entry['offset']))->format('Y-m-d');
        $result[$date] = $entry['name'];
    }

    return $result;
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
 * Lädt public_holidays.json einmal pro Request und cached das Ergebnis.
 */
function load_public_holiday_config(): array
{
    static $cache = null;

    if ($cache === null) {
        $path = dirname(__DIR__) . '/public_holidays.json';
        $raw  = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Feiertagskonfiguration nicht gefunden: $path");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['fixed'], $decoded['easter'])) {
            throw new RuntimeException("Feiertagskonfiguration ist ungültig: $path");
        }
        $cache = $decoded;
    }

    return $cache;
}

/**
 * Liest die aktive Region (Bundesland-Code) aus config.php.
 * Default 'ST' (Sachsen-Anhalt), wenn der Schlüssel nicht gesetzt ist.
 */
function get_active_region(): string
{
    static $region = null;

    if ($region === null) {
        $configPath = getenv('APP_ENV') === 'test'
            ? dirname(__DIR__) . '/config.test.php'
            : dirname(__DIR__) . '/config.php';
        $config = is_file($configPath) ? require $configPath : [];
        $region = isset($config['active_region']) && is_string($config['active_region'])
            ? $config['active_region']
            : 'ST';
    }

    return $region;
}

/**
 * Prüft, ob ein Feiertagseintrag in der angegebenen Region gilt.
 * 'ALL' ist Wildcard für alle Bundesländer.
 */
function holiday_applies_to_region(array $entry, string $region): bool
{
    $regions = $entry['regions'] ?? [];
    return in_array('ALL', $regions, true) || in_array($region, $regions, true);
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
