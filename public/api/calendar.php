<?php
// GET /api/calendar – Wochentagstyp für ein konkretes Datum.
// Parameter: date (string YYYY-MM-DD, Pflicht)
// Antwort: { date, dayType, name }
//   name = Feiertagsname (FT), Schulferienname (SF) oder null

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/calendar.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Methode nicht erlaubt', 405);
}

$dateStr = trim($_GET['date'] ?? '');

// Datumsformat prüfen: YYYY-MM-DD
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
    json_error('Parameter date ist erforderlich und muss das Format YYYY-MM-DD haben');
}

$date = DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
if ($date === false || $date->format('Y-m-d') !== $dateStr) {
    json_error('Ungültiges Datum');
}

$pdo     = get_db();
$dayType = getDayType($date, $pdo);

// Bezeichnung je nach Typ ermitteln
$name = match ($dayType) {
    'FT' => get_public_holiday_name($date),
    'SF' => get_school_holiday_name($date, $pdo),
    default => null,
};

json_response([
    'date'    => $dateStr,
    'dayType' => $dayType,
    'name'    => $name,
]);
