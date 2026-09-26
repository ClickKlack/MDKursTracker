<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/mdtakt.php';

/**
 * Tests für lib/mdtakt.php (Übertragung an MD-Takt, Fluss 1).
 */
class MdTaktTest extends TestCase
{
    private static function row(int $id, string $fp = 'fpA', int $tripId = 1, string $course = '03'): array
    {
        return [
            'id'                   => $id,
            'trip_id'              => $tripId,
            'schedule_fingerprint' => $fp,
            'stop_id'              => '301968501',
            'line'                 => '9',
            'course_number'        => $course,
            'service_date'         => '2026-06-18',
            'recorded_at'          => '2026-06-18 16:42:35',
            'departure_planned'    => '2026-06-18 16:43:00',
            'departure_actual'     => null,
        ];
    }

    private static function trip(): array
    {
        return [
            'trip' => [
                'id' => 1, 'schedule_fingerprint' => 'fpA', 'line' => '1',
                'direction' => 'Sudenburg', 'day_type' => 'SF', 'service_nr' => '139916_35',
            ],
            'stops' => [
                ['sequence' => 1, 'stop_id' => '300730901', 'stop_name' => 'Magdeburg, A',
                 'line' => '1', 'departure_planned' => '2026-04-15 15:21:00', 'arrival_planned' => null],
                // Linienübergang: ab hier Linie 13
                ['sequence' => 2, 'stop_id' => '300730902', 'stop_name' => 'Magdeburg, B',
                 'line' => '13', 'departure_planned' => '2026-04-15 15:25:00', 'arrival_planned' => '2026-04-15 15:24:00'],
                // Linie unbekannt → Trip-Linie; Endhalt ohne Abfahrtszeit
                ['sequence' => 3, 'stop_id' => '300730903', 'stop_name' => 'Magdeburg, C',
                 'line' => null, 'departure_planned' => null, 'arrival_planned' => '2026-04-15 15:30:00'],
            ],
        ];
    }

    // ── mdtakt_sighting ─────────────────────────────────────────────────────

    public function test_sighting_uses_strict_utc_format(): void
    {
        $s = mdtakt_sighting(self::row(1935));

        $this->assertSame(1935, $s['mdkt_recording_id']);
        $this->assertSame('2026-06-18T16:42:35Z', $s['observed_at']);
        $this->assertSame('2026-06-18T16:43:00Z', $s['departure_planned']);
        $this->assertNull($s['departure_actual']);
        $this->assertSame('2026-06-18', $s['service_date']);
        $this->assertSame('9', $s['line']);
    }

    public function test_sighting_course_number_without_leading_zero(): void
    {
        $this->assertSame('3', mdtakt_sighting(self::row(1, course: '03'))['course_number']);
        $this->assertSame('13', mdtakt_sighting(self::row(1, course: '13'))['course_number']);
        $this->assertSame('0', mdtakt_sighting(self::row(1, course: '00'))['course_number']);
    }

    public function test_sighting_contains_no_personal_fields(): void
    {
        $row = self::row(1) + ['user_token' => 'secret', 'comment' => 'x'];
        $s   = mdtakt_sighting($row);

        $this->assertArrayNotHasKey('user_token', $s);
        $this->assertArrayNotHasKey('comment', $s);
    }

    // ── mdtakt_trip ─────────────────────────────────────────────────────────

    public function test_trip_carries_line_per_stop(): void
    {
        $t = mdtakt_trip(self::trip()['trip'], self::trip()['stops']);

        $this->assertSame(['1', '13', '1'], array_column($t['stops'], 'line'));
        $this->assertSame([1, 2, 3], array_column($t['stops'], 'seq'));
    }

    public function test_trip_omits_null_times(): void
    {
        $stops = mdtakt_trip(self::trip()['trip'], self::trip()['stops'])['stops'];

        $this->assertArrayNotHasKey('arrival_planned', $stops[0]);
        $this->assertSame('2026-04-15T15:24:00Z', $stops[1]['arrival_planned']);
        $this->assertArrayNotHasKey('departure_planned', $stops[2]);
        $this->assertSame('2026-04-15T15:30:00Z', $stops[2]['arrival_planned']);
    }

    public function test_trip_maps_day_type(): void
    {
        $this->assertSame('MO-FR', mdtakt_trip(self::trip()['trip'], [])['day_type']);
        $this->assertSame('MO-FR', mdtakt_day_type('MO-FR'));
        $this->assertSame('SA', mdtakt_day_type('SA'));
        $this->assertSame('SO', mdtakt_day_type('SO'));
        $this->assertSame('SO', mdtakt_day_type('FT'));
    }

    public function test_trip_omits_empty_service_nr(): void
    {
        $trip = self::trip()['trip'];
        $this->assertSame('139916_35', mdtakt_trip($trip, [])['service_nr']);

        $trip['service_nr'] = '';
        $this->assertArrayNotHasKey('service_nr', mdtakt_trip($trip, []));
    }

    // ── mdtakt_chunks ───────────────────────────────────────────────────────

    public function test_chunks_respect_sighting_limit(): void
    {
        $rows   = array_map(fn($i) => self::row($i), range(1, 7));
        $blocks = mdtakt_chunks($rows, 3, 200);

        $this->assertSame([3, 3, 1], array_map('count', $blocks));
        $this->assertSame(range(1, 7), array_column(array_merge(...$blocks), 'id'));
    }

    public function test_chunks_respect_trip_limit(): void
    {
        // fpA, fpB, fpA, fpC – bei max. 2 Laufwegen muss fpC in einen neuen Block
        $rows = [self::row(1, 'fpA'), self::row(2, 'fpB'), self::row(3, 'fpA'), self::row(4, 'fpC')];

        $blocks = mdtakt_chunks($rows, 500, 2);

        $this->assertSame([[1, 2, 3], [4]], array_map(fn($b) => array_column($b, 'id'), $blocks));
    }

    public function test_chunks_empty(): void
    {
        $this->assertSame([], mdtakt_chunks([]));
    }

    // ── mdtakt_build_body ───────────────────────────────────────────────────

    public function test_body_sends_each_fingerprint_once(): void
    {
        // Zwei Trip-IDs mit demselben Fingerprint (z.B. aus zwei Perioden)
        $trips = [1 => self::trip(), 2 => self::trip()];
        $rows  = [self::row(1, 'fpA', 1), self::row(2, 'fpA', 2)];
        $rows[1]['recorded_at'] = '2026-06-17 08:00:00';

        $body = mdtakt_build_body($rows, $trips, '2026-06-23T01:00:00Z');

        $this->assertCount(1, $body['trips']);
        $this->assertCount(2, $body['sightings']);
        $this->assertSame('2026-06-17T08:00:00Z', $body['sync']['since']);
        $this->assertSame('2026-06-23T01:00:00Z', $body['sync']['generated_at']);
    }

    // ── mdtakt_accepted_ids ─────────────────────────────────────────────────

    public function test_accepted_ids_by_outcome(): void
    {
        $data = ['results' => [
            ['mdkt_recording_id' => 1, 'outcome' => 'created', 'match' => 'matched'],
            ['mdkt_recording_id' => 2, 'outcome' => 'updated', 'match' => 'waiting'],
            ['mdkt_recording_id' => 3, 'outcome' => 'unchanged'],
            ['mdkt_recording_id' => 4, 'outcome' => 'unknown_fingerprint'],
            ['mdkt_recording_id' => 5],
        ]];

        $this->assertSame([1, 2, 3], mdtakt_accepted_ids($data));
        $this->assertSame([], mdtakt_accepted_ids([]));
    }

    // ── mdtakt_sightings_summary / mdtakt_log_context ───────────────────────

    public function test_sightings_summary_counts_results(): void
    {
        $body = ['trips' => [[], []], 'sightings' => [[], [], []]];
        $data = ['results' => [
            ['mdkt_recording_id' => 1, 'outcome' => 'created', 'match' => 'waiting'],
            ['mdkt_recording_id' => 2, 'outcome' => 'unchanged', 'match' => 'matched'],
            ['mdkt_recording_id' => 3, 'outcome' => 'unknown_fingerprint'],
        ]];

        $this->assertSame([
            'itemsSent' => 3,
            'itemsOk'   => 2,
            'stats'     => ['trips' => 2, 'waiting' => 1, 'unknownFingerprint' => 1],
        ], mdtakt_sightings_summary($body, $data));
    }

    public function test_sightings_summary_without_response_has_null_results(): void
    {
        $sum = mdtakt_sightings_summary(['trips' => [[]], 'sightings' => [[]]], null);

        $this->assertSame(1, $sum['itemsSent']);
        $this->assertNull($sum['itemsOk']);
        $this->assertSame(1, $sum['stats']['trips']);
        $this->assertNull($sum['stats']['waiting']);
        $this->assertNull($sum['stats']['unknownFingerprint']);
    }

    public function test_log_context_is_script_name_in_cli(): void
    {
        $this->assertSame(basename($_SERVER['SCRIPT_NAME']), mdtakt_log_context());
    }

    // ── mdtakt_send_block ───────────────────────────────────────────────────

    /**
     * PDO-Mock, der die markierten (id, course)-Paare mitschreibt.
     */
    private function recordingPdo(array &$marked): PDO
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(function (array $p) use (&$marked) {
            $marked[] = $p;
            return true;
        });
        $stmt->method('rowCount')->willReturn(1);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        return $pdo;
    }

    public function test_send_block_marks_only_accepted_with_sent_course(): void
    {
        $marked = [];
        $pdo    = $this->recordingPdo($marked);
        $rows   = [self::row(1, 'fpA', 1, '03'), self::row(2, 'fpA', 1, '05')];
        $send   = fn(array $body) => ['status' => 200, 'error' => null, 'data' => ['results' => [
            ['mdkt_recording_id' => 1, 'outcome' => 'created', 'match' => 'waiting'],
            ['mdkt_recording_id' => 2, 'outcome' => 'unknown_fingerprint'],
        ]]];
        $stats = ['blocks' => 0, 'sent' => 0, 'accepted' => 0, 'waiting' => 0,
                  'unknownFingerprint' => 0, 'rejected' => 0, 'failed' => false];

        $this->assertTrue(mdtakt_send_block($pdo, $rows, [1 => self::trip()], $send, $stats));
        $this->assertSame([[1, '03']], $marked);
        $this->assertSame(1, $stats['accepted']);
        $this->assertSame(1, $stats['waiting']);
        $this->assertSame(1, $stats['unknownFingerprint']);
    }

    public function test_send_block_bisects_on_422(): void
    {
        $marked = [];
        $pdo    = $this->recordingPdo($marked);
        $rows   = array_map(fn($i) => self::row($i), range(1, 4));

        // Sichtung 3 ist fehlerhaft: jeder Block, der sie enthält, wird abgewiesen
        $send = function (array $body): array {
            $ids = array_column($body['sightings'], 'mdkt_recording_id');
            if (in_array(3, $ids, true)) {
                return ['status' => 422, 'data' => null, 'error' => 'validation'];
            }
            return ['status' => 200, 'error' => null, 'data' => ['results' => array_map(
                fn($id) => ['mdkt_recording_id' => $id, 'outcome' => 'created'],
                $ids
            )]];
        };
        $stats = ['blocks' => 0, 'sent' => 0, 'accepted' => 0, 'waiting' => 0,
                  'unknownFingerprint' => 0, 'rejected' => 0, 'failed' => false];

        $this->assertTrue(mdtakt_send_block($pdo, $rows, [1 => self::trip()], $send, $stats));
        $this->assertSame([1, 2, 4], array_column($marked, 0));
        $this->assertSame(1, $stats['rejected']);
    }

    public function test_send_block_aborts_on_server_error(): void
    {
        $marked = [];
        $pdo    = $this->recordingPdo($marked);
        $send   = fn(array $body) => ['status' => 503, 'data' => null, 'error' => 'down'];
        $stats  = ['blocks' => 0, 'sent' => 0, 'accepted' => 0, 'waiting' => 0,
                   'unknownFingerprint' => 0, 'rejected' => 0, 'failed' => false];

        $this->assertFalse(mdtakt_send_block($pdo, [self::row(1)], [1 => self::trip()], $send, $stats));
        $this->assertSame([], $marked);
    }

    // ── Konfiguration ───────────────────────────────────────────────────────

    public function test_not_configured_in_tests(): void
    {
        $this->assertFalse(mdtakt_configured());
        $this->assertSame(30, mdtakt_config()['graceMinutes']);
    }
}
