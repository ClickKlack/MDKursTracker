<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests für lib/hafas_cache.php
 *
 * Die set/get-Tests schreiben in das konfigurierte Cache-Verzeichnis
 * (cache/hafas/ im Projektverzeichnis). Test-Schlüssel werden nach dem
 * Test bereinigt.
 */
class HafasCacheTest extends TestCase
{
    /** Präfix für Test-Keys; vermeidet Kollision mit echten Cache-Einträgen */
    private const TEST_PREFIX = 'phpunit_test';

    /** Gesammelte Cache-Dateipfade zum Aufräumen in tearDown */
    private array $cleanupFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    // -----------------------------------------------------------------------
    // hafas_cache_key – Schlüsselzusammensetzung
    // -----------------------------------------------------------------------

    public function test_cache_key_single_part(): void
    {
        $this->assertSame('nearby', hafas_cache_key('nearby'));
    }

    public function test_cache_key_multiple_parts(): void
    {
        $this->assertSame('nearby|52.123|11.456|10', hafas_cache_key('nearby', '52.123', '11.456', '10'));
    }

    public function test_cache_key_two_parts(): void
    {
        $this->assertSame('departures|7543', hafas_cache_key('departures', '7543'));
    }

    public function test_cache_key_produces_different_results_for_different_inputs(): void
    {
        $key1 = hafas_cache_key('trip', 'abc');
        $key2 = hafas_cache_key('trip', 'def');
        $this->assertNotSame($key1, $key2);
    }

    // -----------------------------------------------------------------------
    // hafas_cache_get / hafas_cache_set – Schreib-Lese-Zyklus
    // -----------------------------------------------------------------------

    public function test_get_returns_null_for_nonexistent_key(): void
    {
        $key = hafas_cache_key(self::TEST_PREFIX, 'nonexistent', uniqid());
        $this->assertNull(hafas_cache_get($key));
    }

    public function test_set_and_get_round_trip_with_array(): void
    {
        $key  = hafas_cache_key(self::TEST_PREFIX, 'round_trip', uniqid());
        $data = [['id' => '7543', 'name' => 'Hasselbachplatz', 'distance' => 42]];

        $this->cleanupFiles[] = hafas_cache_path($key);

        hafas_cache_set($key, $data, 60);
        $result = hafas_cache_get($key);

        $this->assertSame($data, $result);
    }

    public function test_set_and_get_round_trip_with_string(): void
    {
        $key = hafas_cache_key(self::TEST_PREFIX, 'string', uniqid());

        $this->cleanupFiles[] = hafas_cache_path($key);

        hafas_cache_set($key, 'hello', 60);
        $this->assertSame('hello', hafas_cache_get($key));
    }

    public function test_set_and_get_round_trip_with_null_value(): void
    {
        // null als gecachten Wert ist zulässig (leere API-Antwort)
        $key = hafas_cache_key(self::TEST_PREFIX, 'null_value', uniqid());

        $this->cleanupFiles[] = hafas_cache_path($key);

        // null ist serialisierbar; hafas_cache_get gibt null auch für
        // nicht-vorhandene Keys zurück → daher zuerst setzen, dann prüfen
        // ob die Datei existiert
        hafas_cache_set($key, null, 60);
        $this->assertFileExists(hafas_cache_path($key));
    }

    // -----------------------------------------------------------------------
    // Ablauf-Verhalten (TTL)
    // -----------------------------------------------------------------------

    public function test_get_returns_null_for_expired_entry(): void
    {
        $key  = hafas_cache_key(self::TEST_PREFIX, 'expired', uniqid());
        $file = hafas_cache_path($key);
        $this->cleanupFiles[] = $file;

        // Abgelaufenen Eintrag direkt schreiben (expires in der Vergangenheit)
        $dir = hafas_cache_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $entry = serialize(['expires' => time() - 1, 'data' => ['should' => 'not be returned']]);
        file_put_contents($file, $entry);

        $this->assertNull(hafas_cache_get($key));
        // Abgelaufene Datei sollte vom get() bereinigt worden sein
        $this->assertFileDoesNotExist($file);
    }

    public function test_expired_entry_is_removed_from_filesystem(): void
    {
        $key  = hafas_cache_key(self::TEST_PREFIX, 'cleanup', uniqid());
        $file = hafas_cache_path($key);
        $this->cleanupFiles[] = $file;

        $dir = hafas_cache_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($file, serialize(['expires' => time() - 10, 'data' => 'stale']));

        hafas_cache_get($key); // Soll abgelaufene Datei löschen
        $this->assertFileDoesNotExist($file);
    }

    // -----------------------------------------------------------------------
    // Beschädigte Cache-Dateien
    // -----------------------------------------------------------------------

    public function test_get_returns_null_for_corrupt_file(): void
    {
        $key  = hafas_cache_key(self::TEST_PREFIX, 'corrupt', uniqid());
        $file = hafas_cache_path($key);
        $this->cleanupFiles[] = $file;

        $dir = hafas_cache_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($file, 'this is not valid serialized data!!!');

        $this->assertNull(hafas_cache_get($key));
    }

    public function test_get_returns_null_for_missing_expires_key(): void
    {
        $key  = hafas_cache_key(self::TEST_PREFIX, 'no_expires', uniqid());
        $file = hafas_cache_path($key);
        $this->cleanupFiles[] = $file;

        $dir = hafas_cache_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Gültiges Serialisierungsformat, aber fehlendes 'expires'-Feld
        file_put_contents($file, serialize(['data' => 'something']));

        $this->assertNull(hafas_cache_get($key));
    }

    // -----------------------------------------------------------------------
    // hafas_cache_gc – Garbage Collection
    // -----------------------------------------------------------------------

    public function test_gc_removes_expired_files(): void
    {
        $dir = sys_get_temp_dir() . '/hafas_gc_test_' . uniqid();
        mkdir($dir, 0755, true);

        // Abgelaufene Datei anlegen
        $expired = $dir . '/expired.cache';
        file_put_contents($expired, serialize(['expires' => time() - 10, 'data' => 'old']));

        hafas_cache_gc($dir);

        $this->assertFileDoesNotExist($expired);
        rmdir($dir);
    }

    public function test_gc_keeps_valid_files(): void
    {
        $dir = sys_get_temp_dir() . '/hafas_gc_test_' . uniqid();
        mkdir($dir, 0755, true);

        $valid = $dir . '/valid.cache';
        file_put_contents($valid, serialize(['expires' => time() + 3600, 'data' => 'fresh']));

        hafas_cache_gc($dir);

        $this->assertFileExists($valid);
        unlink($valid);
        rmdir($dir);
    }

    public function test_gc_removes_corrupt_files(): void
    {
        $dir = sys_get_temp_dir() . '/hafas_gc_test_' . uniqid();
        mkdir($dir, 0755, true);

        $corrupt = $dir . '/corrupt.cache';
        file_put_contents($corrupt, 'not valid serialized data');

        hafas_cache_gc($dir);

        $this->assertFileDoesNotExist($corrupt);
        rmdir($dir);
    }

    public function test_gc_handles_nonexistent_directory_silently(): void
    {
        // Soll keine Exception werfen
        hafas_cache_gc('/tmp/hafas_does_not_exist_' . uniqid());
        $this->assertTrue(true); // Kein Fehler = Test bestanden
    }

    // -----------------------------------------------------------------------
    // hafas_cache_path – Dateipfad-Konsistenz
    // -----------------------------------------------------------------------

    public function test_cache_path_same_key_returns_same_path(): void
    {
        $key = hafas_cache_key('trip', 'abc123');
        $this->assertSame(hafas_cache_path($key), hafas_cache_path($key));
    }

    public function test_cache_path_different_keys_return_different_paths(): void
    {
        $path1 = hafas_cache_path(hafas_cache_key('trip', 'abc'));
        $path2 = hafas_cache_path(hafas_cache_key('trip', 'def'));
        $this->assertNotSame($path1, $path2);
    }

    public function test_cache_path_ends_with_dot_cache(): void
    {
        $path = hafas_cache_path('anykey');
        $this->assertStringEndsWith('.cache', $path);
    }
}
