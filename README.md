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

## Voraussetzungen

- PHP ≥ 8.0 mit aktivierter `curl`-Extension
- MariaDB ≥ 10.4
- Composer (für Monolog-Logging)
- Webserver mit Unterstützung für `.htaccess` / `mod_rewrite`

## Installation (Entwicklung)

1. Repository klonen
2. Composer-Abhängigkeiten installieren:
   ```bash
   composer install
   ```
3. Datenbank anlegen und Schema einspielen:
   ```bash
   mysql -u root -p < DATABASE.sql
   ```
4. Konfigurationsdatei anlegen (außerhalb des Webroots):
   ```bash
   cp config.php.example config.php
   # Dann config.php mit DB-Zugangsdaten und Admin-Passwort-Hash befüllen
   ```
5. Webserver-Document-Root auf `public/` zeigen lassen

## Verzeichnisstruktur

```
/                    ← Projektverzeichnis (nicht öffentlich)
├── config.php       ← DB-Zugangsdaten + Admin-Passwort-Hash (nicht im Repo)
├── lib/             ← PHP-Bibliotheken
├── logs/            ← Monolog-Logdateien (nicht im Repo)
└── public/          ← Webroot (Document Root)
    ├── index.html
    ├── api/
    └── admin-api/
```

Vollständige Dokumentation: [SPEC.md](SPEC.md) | [ARCHITECTURE.md](ARCHITECTURE.md) | [API.md](API.md)

## Lizenz

Dieses Projekt ist unter der MIT-Lizenz veröffentlicht – siehe [LICENSE](LICENSE).
