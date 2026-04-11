# MDKursTracker

Progressive Web App zur kollektiven Erfassung von **Kursnummern** für
Straßenbahnfahrten im Verkehrsverbund **marego** (Magdeburg / MVB).

## Überblick

Mehrere Nutzer können gleichzeitig Kursnummern erfassen. Alle Einträge landen
in einer gemeinsamen zentralen Datenbank. Der Betreiber wertet zentral aus,
kann Einträge manuell übersteuern und bei einem Fahrplanwechsel eine neue
Erfassungsperiode starten.

## Technologie-Stack

| Schicht | Technologie |
|---|---|
| Frontend | HTML5 + CSS + Vanilla JavaScript (SPA / PWA) |
| Backend | PHP ≥ 8.0 (plain, kein Framework) |
| Datenbank | MariaDB ≥ 10.4 |
| Fahrplandaten | INSA/NASA HAFAS-API |
| Tests | PHPUnit 11 |
| CI | Forgejo Actions (Codeberg) |

## Voraussetzungen

- PHP ≥ 8.0 mit aktivierter `curl`-Extension
- MariaDB ≥ 10.4
- Composer (für Monolog und PHPUnit)
- Webserver mit Unterstützung für `.htaccess` / `mod_rewrite`

## Installation (Entwicklung)

1. Repository klonen
2. Composer-Abhängigkeiten installieren:
   ```bash
   composer install
   ```
3. Datenbank anlegen und Schema einspielen:
   ```bash
   # Ohne Tabellen-Prefix (Standardfall lokal)
   sed 's/%%PREFIX%%//g' DATABASE.sql | mysql -u root -p DATENBANKNAME
   ```
4. Konfigurationsdatei anlegen (außerhalb des Webroots):
   ```bash
   cp config.php.example config.php
   # Dann config.php mit DB-Zugangsdaten, Admin-Passwort-Hash und HAFAS-Parametern befüllen
   ```
5. Webserver-Document-Root auf `public/` zeigen lassen

## Deployment (Produktion)

### Voraussetzungen

- SSH-Zugang zum Produktivserver
- Webserver mit `mod_rewrite` und `AllowOverride All`
- Document Root des Vhosts zeigt auf `public/` innerhalb des Projektverzeichnisses
- PHP ≥ 8.0 mit `curl`-Extension, Composer auf dem Server verfügbar

### Ersteinrichtung

1. Dateien per rsync auf den Server übertragen (ohne Secrets und Runtime-Daten):
   ```bash
   rsync -avz --delete \
     --exclude='.git/' --exclude='config.php' --exclude='vendor/' \
     --exclude='cache/' --exclude='logs/' --exclude='local_scripts/' \
     ./ user@server:/pfad/zum/projekt/
   ```
2. Auf dem Server Abhängigkeiten installieren:
   ```bash
   ssh user@server "cd /pfad/zum/projekt && composer install --no-dev --optimize-autoloader"
   ```
3. Laufzeit-Verzeichnisse anlegen:
   ```bash
   ssh user@server "mkdir -p /pfad/zum/projekt/cache/hafas /pfad/zum/projekt/logs"
   ```
4. Konfiguration anlegen und befüllen:
   ```bash
   ssh user@server "cp /pfad/zum/projekt/config.php.example /pfad/zum/projekt/config.php"
   # Dann config.php auf dem Server mit Produktionswerten befüllen
   ```
5. Datenbankschema einspielen – `%%PREFIX%%` durch den gewünschten Tabellen-Prefix
   ersetzen (leer lassen für keinen Prefix, z.B. `trammd_` für mehrere Instanzen
   auf einer Datenbank):
   ```bash
   sed 's/%%PREFIX%%/PREFIX_/g' DATABASE.sql | mysql -h HOST -u USER -p DATENBANKNAME
   # Ohne Prefix:
   sed 's/%%PREFIX%%//g' DATABASE.sql | mysql -h HOST -u USER -p DATENBANKNAME
   ```

### Reguläre Updates

Dieselben rsync- und composer-Schritte wie oben wiederholen.
`config.php`, `cache/` und `logs/` werden dabei nicht überschrieben.

### Tabellen-Prefix

Sollen mehrere Instanzen dieselbe Datenbank teilen, kann in `config.php`
ein Prefix gesetzt werden:

```php
'db_prefix' => 'meinprefix_',
```

Alle Tabellennamen werden dann automatisch mit diesem Prefix versehen.
Das Schema muss mit demselben Prefix eingespielt worden sein (s.o.).

## Tests

### Unit-Tests (PHPUnit)

```bash
# Alle Tests ausführen
vendor/bin/phpunit

# Mit lesbarer Ausgabe
vendor/bin/phpunit --testdox
```

Tests liegen in `tests/Unit/`, je eine Klasse pro `lib/`-Datei.

Ergänzend empfiehlt sich vor jedem Commit eine manuelle Gesamtprüfung:

```bash
# PHP-Syntaxprüfung
find lib tests public/api public/admin-api -name "*.php" | xargs -I{} php -l {}

# Sicherheitsscan der Abhängigkeiten
composer audit

# Datenbankverbindung und Kalenderlogik händisch prüfen
php -r "require 'vendor/autoload.php'; $pdo = get_db(); echo getDayType(new DateTimeImmutable('today'), $pdo);"
```

### Continuous Integration

Bei jedem Push und Pull Request läuft automatisch via Forgejo Actions:
- PHP-Syntaxprüfung aller `lib/`- und `tests/`-Dateien
- `composer audit` (Sicherheitsscan der Abhängigkeiten)
- PHPUnit-Testsuite

## Verzeichnisstruktur

```
/                    ← Projektverzeichnis (nicht öffentlich)
├── config.php       ← DB-Zugangsdaten, Admin-Hash, HAFAS-Parameter (nicht im Repo)
├── lib/             ← PHP-Bibliotheken
├── logs/            ← Monolog-Logdateien (nicht im Repo)
├── tests/Unit/      ← PHPUnit-Tests
└── public/          ← Webroot (Document Root)
    ├── index.html
    ├── api/
    └── admin-api/
```

Vollständige Dokumentation: [SPEC.md](SPEC.md) | [ARCHITECTURE.md](ARCHITECTURE.md) | [API.md](API.md)

## Lizenz

Dieses Projekt ist unter der MIT-Lizenz veröffentlicht – siehe [LICENSE](LICENSE).
