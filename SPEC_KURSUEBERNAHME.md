# SPEC_KURSUEBERNAHME.md – Kursübernahme unveränderter Linien beim Fahrplanwechsel

> Version: 1.0 | Stand: 2026-09-21
> Ergänzt SPEC.md § 4 (Fahrplanperioden). Ist-Zustand: Einmalskript
> `data_migrations/clone_unchanged_lines.php`. Soll-Zustand: Admin-Funktion
> (§ 6 dieses Dokuments).

---

## 1. Problem

Alle fahrtbezogenen Daten sind strikt periodengebunden (`trips.period_id`).
Nach einem Fahrplanschnitt startet die neue Periode deshalb ohne jedes
Kurswissen – auch für Linien, deren Fahrplan sich gar nicht geändert hat.
Für die Erfasser sieht das aus, als wäre die App zurückgesetzt worden: Alle
Abfahrten stehen wieder ohne Kursnummer da, obwohl die Information vorliegt.

Die Wiederbeschaffung ist teuer. Beim Wechsel am 21.09.2026 betraf das allein
bei drei unveränderten Linien 139 Fahrten, die sonst einzeln neu hätten
erfasst werden müssen.

## 2. Lösungsidee

Ist der Fahrplan einer Linie unverändert, ist der `schedule_fingerprint` ihrer
Fahrten identisch (SHA-256 über normalisierte Stop-ID + HH:MM-Paare, siehe
SPEC.md § 6). Eine Fahrt der alten Periode und ihr Gegenstück in der neuen
Periode sind damit maschinell als **dieselbe Fahrt** erkennbar.

Die Übernahme klont solche Fahrten in die neue Periode. Neue Erfassungen
docken automatisch am Klon an, weil `POST /api/recordings` genau über
`(period_id, schedule_fingerprint, day_type)` auflöst.

**Die Übernahme ist immer eine Annahme, keine Messung.** Sie überträgt die
Aussage „diese Fahrt hatte bisher Kurs X" in einen Fahrplan, der nicht
beobachtet wurde. Alle Festlegungen unten folgen daraus: Die Übernahme muss
erkennbar, korrigierbar und rückbaubar sein.

## 3. Fachliche Regeln

### 3.1 Quell- und Zielperiode

- **Ziel** ist die *neueste* Periode nach `start_date`, **nicht** die aktive.
  Beim Fahrplanschnitt wird die neue Periode oft mit einem Startdatum in der
  Zukunft angelegt; `get_active_period_id()` liefert dann noch die alte.
- **Quelle** ist standardmäßig die direkte Vorgängerperiode. Mehrere Quellen
  in Prioritätsreihenfolge sind zulässig (ältere füllen nur Lücken, je
  `(schedule_fingerprint, day_type)` gewinnt der erste Fund). Sinnvoll etwa,
  wenn zwischendurch ein Baustellenfahrplan lag; dann ist die vorletzte
  Periode die fachlich richtige Quelle.

### 3.2 Was je Fahrt übernommen wird

| Objekt | Regel |
|---|---|
| `trips` | Klon mit `period_id` = Ziel; `path_fingerprint`, `schedule_fingerprint`, `line`, `day_type`, `direction` unverändert |
| `trips.manual_course_number` | wandert mit – eine Admin-Übersteuerung ist eine bewusste Festlegung und bleibt eine |
| `trips.last_hafas_trip_id` | `NULL` – tagesgebunden, wird lazy nachgeführt |
| `trips.service_nr` | **leer** (siehe § 3.4) |
| `route_stops` | vollständig kopiert – ohne sie greift die Heuristik der Abfahrtstafel nicht |
| `recordings` | **genau eine** Seed-Erfassung (siehe § 3.3) |

### 3.3 Seed-Erfassung

Statt alle Erfassungen zu kopieren, entsteht je Klon **eine** Erfassung:

- Vorlage ist die jüngste Erfassung des Quell-Trips mit der aktuell gültigen
  Mehrheitskursnummer; Zeitstempel, Haltestelle und Plan-Abfahrt werden
  unverändert übernommen.
- `user_token` = `NULL` (anonym). Die Übernahme darf keinem Nutzer als seine
  Erfassung zugerechnet werden – sie würde sonst in seinem Verlauf auftauchen
  und dort editierbar sein.
- `comment` trägt die Herkunft, z.B. *„Kurs aus Fahrplan ‚Netz 17.8.'
  übernommen (Linie unverändert, automatische Startbelegung)."*
- Entfällt, wenn der Quell-Trip eine `manual_course_number` hat – die
  Übersteuerung allein bestimmt dann schon die Anzeige.

**Warum nur eine:** Die aktive Kursnummer ist die Mehrheit aus `recordings`
(SPEC.md § 6). Eine einzelne Seed-Erfassung stellt den Kurs sofort dar, lässt
sich aber von zwei abweichenden echten Erfassungen überstimmen. Würde man alle
Erfassungen kopieren, zementierte man eine Annahme gegen die Beobachtung – und
verdoppelte zugleich fremde Erfassungen in Liste und Statistik der neuen
Periode.

**Warum keine `manual_course_number` als Träger:** Ein Override sperrt die
Mehrheitsregel dauerhaft. Eine falsch übernommene Kursnummer wäre dann nur
noch vom Admin korrigierbar, nicht von den Erfassern.

### 3.4 Leere `service_nr`

Die Fahrtennummer ist `ZI_TA` aus der HAFAS-Journey-ID (Umlaufnummer +
Fahrtabschnitt) und kann beim Fahrplanwechsel netzweit neu vergeben werden.
`/api/departures` nutzt `serviceNr|line|dayType` als Fallback **vor** der
route_stops-Heuristik. Eine mitgenommene alte Nummer könnte dort einer
fremden Fahrt derselben Linie eine Kursnummer andichten – eine falsche
Auskunft, die wie eine sichere aussieht.

Deshalb bleibt `service_nr` bei Klonen leer. Der Lookup fällt auf die
route_stops-Heuristik zurück, die über Halt + Minute + Linie + Tagestyp
entscheidet und damit genau die Annahme prüft, auf der die Übernahme beruht.
Beim ersten echten Erfassen oder Touch wird der Wert automatisch nachgeführt.

Nachgewiesen: Nach der Übernahme vom 21.09.2026 lieferten **139 von 139**
Klonen über `pick_course_for_departure()` die richtige Kursnummer, obwohl im
Test eine abweichende `serviceNr` mitgegeben wurde.

### 3.5 Ausschlusskriterien

Nicht übernommen wird eine Quell-Fahrt, wenn

1. `schedule_fingerprint IS NULL` – nicht auflösbar,
2. keine Kursnummer bekannt ist (weder Override noch Mehrheit) – nichts zu
   übernehmen,
3. ihr `(schedule_fingerprint, day_type)` in der Zielperiode bereits existiert
   – dort wurde schon real erfasst, **Beobachtung schlägt Annahme**,
4. sie explizit ausgeschlossen wurde (§ 3.6).

### 3.6 Manueller Ausschluss einzelner Fahrten

Eine Linie kann „unverändert" sein und trotzdem einzelne verschobene Fahrten
haben. Diese müssen einzeln ausschließbar sein, sonst behauptet die App für
sie eine Kursnummer, die zur neuen Lage nicht passt.

Der Ausschluss muss im Vorschau-/Berichtsschritt **fachlich prüfbar**
angezeigt werden – Trip-ID allein genügt nicht. Nötig sind Linie, Tagestyp,
Starthaltestelle, Abfahrtszeit **in Ortszeit** und Ziel. Beispiel aus dem
Lauf vom 21.09.2026:

| Quell-Trip | Linie | Tagestyp | Start | ab (lokal) | ab (UTC) | Ziel | Kurs |
|---:|:-:|:-:|:--|:-:|:-:|:--|:-:|
| 1833 | 6 | MO-FR | Magdeburg, Diesdorf | 18:25 | 16:25 | Betriebshof Nord | 07 |

Hinweis: In der Datenbank stehen UTC-Zeiten, angezeigt wird Ortszeit.
`CONVERT_TZ()` ist dafür unbrauchbar – die Zeitzonentabellen sind auf den
eingesetzten MariaDB-Instanzen nicht geladen; die Umrechnung gehört nach PHP.

### 3.7 Invarianten nach der Übernahme

Vor dem Commit muss gelten – sonst Rollback:

1. Jeder Klon liefert dieselbe effektive Kursnummer wie seine Quelle.
2. Jeder Klon hat gleich viele `route_stops` wie seine Quelle.
3. In der Zielperiode existiert kein `(schedule_fingerprint, day_type)`
   doppelt.

## 4. Randfälle

**Ausgeschlossene Fahrt, fremder Kurs.** Fällt eine Fahrt weg, könnten ihre
Halte von Klonen benachbarter Fahrten mitbedient werden – dann bekäme die
geänderte Fahrt den Kurs der Nachbarin. Praktisch tritt das kaum auf, weil
`route_stops` die **steiggenaue** Stop-ID führt, während der Fingerprint auf
Haltestellenebene normalisiert. Am 21.09.2026 teilte die ausgeschlossene
Fahrt keinen einzigen ihrer 36 Route-Schlüssel mit einem Klon. Die Prüfung
gehört trotzdem in die Vorschau (Überschneidungen ausweisen, nicht nur zählen).

**Durchgebundene Linien.** Enthalten die Laufwege der Klone Halte, die laut
`route_stops.line` zu einer anderen Linie gehören (z.B. 13 → 2), entstehen
Heuristik-Einträge für eine Linie, die gar nicht ausgewählt wurde. Muss
gemeldet werden.

**Zielperiode bereits aktiv.** Dann laufen Erfassungen parallel herein. Zwei
Konsequenzen: Schon erfasste Fingerprints werden übersprungen (§ 3.5.3), und
eine gleichzeitige Erfassung kann den Unique-Key verletzen – die Transaktion
bricht ab und schreibt nichts. Ein erneuter Lauf ist unschädlich, weil die
Übernahme idempotent ist.

**Tagestypen.** `FT` und `SF` sind eigene Tagestypen und werden wie `MO-FR`,
`SA`, `SO` behandelt; ein Klon entsteht je Tagestyp.

## 5. Rückbau

Erweist sich eine Übernahme als falsch, müssen die Klone entfernbar sein,
**ohne** echte Erfassungen zu treffen. Das Einmalskript nutzt dafür die leere
`service_nr` als Erkennungsmerkmal: Sie tragen nur Klone, und sie verschwindet,
sobald jemand die Fahrt real erfasst – der Rückbau greift also nur unberührte
Klone. Reihenfolge wegen der Fremdschlüssel: `recordings` → `route_stops` →
`trips`.

Für die produktive Funktion ist das zu implizit. Siehe § 6.4.

## 6. Soll-Zustand: Admin-Funktionalität

### 6.1 Einordnung

Die Übernahme gehört als eigener Schritt **hinter** den Fahrplanschnitt
(SPEC.md § 4.4), nicht hinein: Ob eine Linie unverändert ist, weiß der Admin
oft erst, wenn er den neuen Fahrplan gesehen hat. Nach dem Anlegen einer
Periode sollte das Admin-Frontend darauf hinweisen, dass eine Kursübernahme
möglich ist.

### 6.2 Ablauf im Frontend

1. **Auswahl:** Quellperiode (vorbelegt: Vorgänger), Zielperiode (vorbelegt:
   neueste), Linien per Mehrfachauswahl. Je Linie wird angezeigt, wie viele
   Fahrten mit Kursnummer übernehmbar wären.
2. **Vorschau:** Tabelle aller betroffenen Fahrten mit Linie, Tagestyp, Start,
   Abfahrtszeit (Ortszeit), Ziel, Kursnummer und Herkunft (Mehrheit /
   Übersteuerung); je Zeile eine Checkbox zum Ausschließen. Zusätzlich die
   übersprungenen Fahrten mit Grund sowie die Warnungen aus § 4.
3. **Bestätigung:** Hinweis, dass es sich um eine Annahme handelt und wie sie
   rückgängig gemacht wird.
4. **Ausführung** in einer Transaktion mit den Prüfungen aus § 3.7.
5. **Ergebnis:** Zahlen je Linie, Link zum Rückbau.

### 6.3 API-Skizze

```
GET  /admin-api/course-transfer/preview?from=4&to=5&lines=6,8,10
     → { sourcePeriod, targetPeriod, candidates: [ { tripId, line, dayType,
         originStop, departureLocal, departureUtc, direction, course,
         courseSource, stopCount } ], skipped: [ { tripId, reason, … } ],
         warnings: { foreignLines: [...], sharedRouteKeys: [...] } }

POST /admin-api/course-transfer
     { from: 4, to: 5, lines: ["6"], excludeTripIds: [1833] }
     → { clonedTrips, copiedStops, seedRecordings, perLine: {...} }

DELETE /admin-api/course-transfer?period=5[&line=6]
     → { removedTrips, removedStops, removedRecordings }
```

Alle drei hinter `require_admin()`. `POST` und `DELETE` protokollieren über
Monolog mit Perioden, Linien und Trefferzahlen.

### 6.4 Notwendige Schemaerweiterung

Die Erkennung „ist ein Klon" über leere `service_nr` und die Herkunft über
den Kommentartext sind Behelfe des Einmalskripts. Produktiv sollte die
Herkunft explizit sein:

- `recordings.origin ENUM('user','transfer') NOT NULL DEFAULT 'user'` –
  oder alternativ `transferred_from_recording_id INT NULL`, das zusätzlich
  die Quelle nachweist.
- Damit wird der Rückbau exakt (`origin = 'transfer'` statt
  `service_nr = ''`), und die Herkunft ist in der Erfassungsliste sauber
  darstellbar.

Offen zu entscheiden: ob die App die Quelle auch den Erfassern zeigt.
`courseSource` kennt bisher `manual`, `recorded`, `heuristic`; ein Wert
`transferred` würde ehrlich machen, dass der Kurs übernommen und nicht
beobachtet ist – und lädt zugleich zum Nacherfassen ein.

### 6.5 Abgrenzung

Nicht Teil dieser Funktion: automatisches Erkennen, welche Linien unverändert
sind. Das ist eine fachliche Aussage des Betreibers. Die Diagnose
(`lib/diagnostics.php`) kann sie im Nachhinein plausibilisieren –
`detect_course_conflicts()` sollte nach einer Übernahme 0 liefern.

## 7. Abweichungen in SPEC.md (zu korrigieren)

- **§ 4.1** sagt „Es gibt keine Übernahme von Altdaten in die neue Periode."
  Das gilt nach diesem Dokument nur noch als Grundregel; die Kursübernahme
  ist die benannte Ausnahme.
- **§ 4.2** sagt, die aktive Periode sei die mit dem höchsten `id`-Wert.
  Tatsächlich ist es die neueste, deren `start_date` erreicht ist
  (`select_active_period_id()` in `lib/db.php`, so auch in DATABASE.sql
  beschrieben). Für dieses Dokument ist der Unterschied wesentlich – siehe
  § 3.1.

## 8. Referenz-Implementierung

`data_migrations/clone_unchanged_lines.php` (gitignored, lokal). Trockenlauf
ohne Argumente, Echtlauf mit `--apply`; Schalter `--lines`, `--exclude`,
`--from`, `--keep-service-nr`, `--report`, `--sql`. Schreibt einen
Markdown-Bericht und ein äquivalentes SQL-Skript inklusive Rückbau-Block.

Läuft wahlweise lokal gegen einen Prod-Snapshot (`local_scripts/sync_prod_db.sh`)
oder direkt auf dem Server – dort liest es die Live-Daten, was den Umweg über
Snapshot-IDs erspart:

```bash
scp data_migrations/clone_unchanged_lines.php hetzner-stolpersteine:/tmp/
ssh hetzner-stolpersteine "php /tmp/clone_unchanged_lines.php \
  /usr/home/hgcbdy/public_html/strassenbahn-magdeburg.de/tracker \
  --lines=6 --exclude=1833 --apply --report=/tmp/x.md --sql=/tmp/x.sql"
```

**Durchgeführte Läufe:**

| Datum | Periode | Linien | Ergebnis |
|---|---|---|---|
| 2026-09-20 | #4 „Netz 17.8." → #5 „Netz 21.9." | 8, 10 | 52 Trips, 1368 route_stops, 52 Seed-Erfassungen |
| 2026-09-21 | #4 → #5 | 6 (ohne Trip 1833) | 83 Trips, 1795 route_stops, 83 Seed-Erfassungen; 4 Fahrten übersprungen, weil bereits real erfasst |
