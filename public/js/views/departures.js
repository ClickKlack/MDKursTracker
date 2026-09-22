/**
 * departures.js – View: Abfahrtstafel einer Haltestelle
 *
 * Ablauf:
 *  1. stopId aus URL-Params lesen (fehlt → zurück zu #nearby)
 *  2. Abfahrten vom Backend laden (api.js)
 *  3. Liste rendern: Linie, Richtung, Soll/Ist-Zeit, Verspätung, Kursnummer
 *  4. Tap auf Abfahrt → Erfassungsdaten in sessionStorage → #capture (Phase 7)
 *  5. Automatische Aktualisierung alle 30 Sekunden
 *
 * Sortierung: Das Backend liefert nach Ist-Zeit sortiert. Der Toggle in der
 * Kopfzeile kann clientseitig auf Soll-Zeit umstellen – bei starken
 * Verspätungen bleibt eine Fahrt dann an ihrer Fahrplanposition stehen.
 */

import { getDepartures, postRecording }           from '../api.js';
import { formatTime, calcDelay, getServiceDate }  from '../utils/format.js';
import { lineBadgeHtml }                          from '../utils/lines.js';
import { escapeHtml, stripStopPrefix, showActionSnackbar } from '../app.js';

/** Laufender Auto-Refresh-Timer */
let refreshTimer = null;

/** Aktuell angezeigter Stop (für Refresh-Aufrufe ohne erneute Params-Übergabe) */
let currentStopId   = null;
let currentStopName = null;

/** Aktiver View-Container (für Refresh aus Event-Handlern, die kein Closure haben) */
let currentContainer = null;

/** Zuletzt geladene Abfahrten (für den Click-Handler via Index) */
let storedDepartures = [];

/** Sortierung der Tafel: 'actual' = Ist-Zeit (Standard), 'planned' = Soll-Zeit */
const SORT_MODE_KEY = 'departures_sort_mode';
let sortMode = localStorage.getItem(SORT_MODE_KEY) ?? 'actual';
if (!['actual', 'planned'].includes(sortMode)) sortMode = 'actual';

/** Zeitpunkt der letzten erfolgreichen Abfrage (für "Stand:" beim Umsortieren) */
let lastUpdatedAt = null;

export async function render(container, params, context) {
    currentStopId    = params.get('stopId');
    currentStopName  = params.get('stopName') ?? currentStopId ?? '';
    currentContainer = container;

    // Kein stopId → direkt zu #nearby weiterleiten
    if (!currentStopId) {
        window.location.hash = '#nearby';
        return;
    }

    // Stop-Name als View-Titel im Header anzeigen (Präfix entfernen)
    const titleEl = document.getElementById('view-title');
    if (titleEl && currentStopName) {
        titleEl.textContent = stripStopPrefix(currentStopName);
    }

    await loadAndRender(container, /* quiet= */ false);

    // Guard: destroy() wurde während des ersten Ladens aufgerufen
    if (!currentStopId) return;

    // Alten Timer abräumen (falls render() erneut aufgerufen wird)
    if (refreshTimer) clearInterval(refreshTimer);

    // Automatische Aktualisierung alle 30 Sekunden
    refreshTimer = setInterval(() => {
        loadAndRender(container, /* quiet= */ true);
    }, 30_000);
}

export function destroy() {
    if (refreshTimer) {
        clearInterval(refreshTimer);
        refreshTimer = null;
    }
    // Header-Titel zurücksetzen
    const titleEl = document.getElementById('view-title');
    if (titleEl) titleEl.textContent = 'Abfahrten';

    currentStopId    = null;
    currentStopName  = null;
    currentContainer = null;
    storedDepartures = [];
    lastUpdatedAt    = null;
    // sortMode bleibt erhalten – es ist eine Benutzereinstellung
}

// --- Laden & Rendern --------------------------------------------------------

async function loadAndRender(container, quiet) {
    if (!quiet) {
        container.innerHTML = `
            <div class="loading-indicator" aria-live="polite">
                <div class="spinner" aria-hidden="true"></div>
                <p>Abfahrten werden geladen…</p>
            </div>`;
    }

    let departures;
    try {
        departures = await getDepartures(currentStopId, 20);
    } catch (err) {
        // Bei Quiet-Refresh: Fehlermeldung unterhalb der bestehenden Liste einfügen
        if (quiet) return;
        // Guard: View wurde während des API-Calls zerstört
        if (!currentStopId) return;
        container.innerHTML = `
            <div class="error-box" role="alert">
                Abfahrten konnten nicht geladen werden: ${escapeHtml(err.message)}
            </div>
            <div class="mt-16">
                <button class="btn btn-secondary btn-full" id="btn-retry-dep">
                    Erneut versuchen
                </button>
            </div>`;
        container.querySelector('#btn-retry-dep')
            .addEventListener('click', () => loadAndRender(container, false));
        return;
    }

    // Guard: View wurde während des API-Calls zerstört (destroy() setzt currentStopId = null)
    if (!currentStopId) return;

    storedDepartures = departures ?? [];
    lastUpdatedAt    = new Date();

    if (storedDepartures.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <p>Keine Abfahrten in den nächsten Minuten.</p>
            </div>`;
        return;
    }

    renderBoard(container);
}

/**
 * Tafel aus den bereits geladenen Daten rendern.
 *
 * Wird sowohl nach einem Ladevorgang als auch beim Umschalten der Sortierung
 * aufgerufen – letzteres kommt ohne Netzwerk-Request aus. Der Zeitstempel
 * stammt deshalb aus lastUpdatedAt und nicht aus der aktuellen Uhrzeit.
 */
function renderBoard(container) {
    // Reihenfolge von storedDepartures selbst anpassen: data-idx und die
    // Event-Handler indizieren direkt in dieses Array.
    storedDepartures = sortDepartures(storedDepartures, sortMode);

    const byActual = sortMode === 'actual';
    const stand    = lastUpdatedAt ?? new Date();

    container.innerHTML = `
        <div class="departures-header">
            <span class="text-small text-muted" aria-live="polite">
                Stand: ${formatTime(stand.toISOString())} Uhr
            </span>
            <div class="departures-actions">
                <div class="sort-toggle" role="group" aria-label="Sortierung der Abfahrten">
                    <button type="button" class="btn-toggle ${byActual ? 'active' : ''}"
                            id="toggle-sort-actual" aria-pressed="${byActual}"
                            title="Nach tatsächlicher Abfahrtszeit sortieren">Ist</button>
                    <button type="button" class="btn-toggle ${byActual ? '' : 'active'}"
                            id="toggle-sort-planned" aria-pressed="${!byActual}"
                            title="Nach Fahrplanzeit sortieren">Soll</button>
                </div>
                <button class="btn-icon" id="btn-refresh" aria-label="Abfahrten jetzt aktualisieren"
                        title="Aktualisieren">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
                        <path d="M21 3v5h-5"/>
                        <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
                        <path d="M8 16H3v5"/>
                    </svg>
                </button>
            </div>
        </div>
        <ul class="card-list departures-list" role="list" aria-label="Abfahrten">
            ${storedDepartures.map((dep, idx) => renderDepartureItem(dep, idx)).join('')}
        </ul>`;

    // Sortier-Toggle
    container.querySelector('#toggle-sort-actual')
        .addEventListener('click', () => setSortMode('actual', container));
    container.querySelector('#toggle-sort-planned')
        .addEventListener('click', () => setSortMode('planned', container));

    // Manueller Refresh-Button
    container.querySelector('#btn-refresh')
        .addEventListener('click', () => loadAndRender(container, false));

    // Tap/Klick auf Abfahrt → Kursnummer erfassen (Phase 7)
    container.querySelector('.departures-list')
        .addEventListener('click',   handleDepartureSelect);
    container.querySelector('.departures-list')
        .addEventListener('keydown', handleDepartureKeydown);
}

// --- Sortierung -------------------------------------------------------------

/**
 * Abfahrten nach Ist- oder Soll-Zeit sortieren.
 *
 * Spiegelt hafas_sort_departures() aus lib/hafas.php: Effektivzeit mit
 * Fallback auf die jeweils andere Zeit, Einträge ganz ohne Zeit ans Ende
 * (\uFFFF sortiert nach jedem ISO-String). ISO-8601-UTC-Strings sind
 * lexikographisch vergleichbar, Array#sort ist stabil.
 */
function sortDepartures(list, mode) {
    const effective = mode === 'planned'
        ? d => d.departurePlanned ?? d.departureActual  ?? '\uFFFF'
        : d => d.departureActual  ?? d.departurePlanned ?? '\uFFFF';

    return [...list].sort((a, b) => {
        const ka = effective(a);
        const kb = effective(b);
        return ka < kb ? -1 : ka > kb ? 1 : 0;
    });
}

function setSortMode(mode, container) {
    if (mode === sortMode) return;
    sortMode = mode;
    try {
        localStorage.setItem(SORT_MODE_KEY, mode);
    } catch { /* z. B. Speicher voll oder privater Modus – Sortierung gilt trotzdem */ }
    renderBoard(container);
}

// --- Einzelne Abfahrt rendern -----------------------------------------------

function renderDepartureItem(dep, idx) {
    const planned  = formatTime(dep.departurePlanned);
    const delay     = calcDelay(dep.departurePlanned, dep.departureActual);
    const isDelayed = delay !== null && delay > 0;
    const isEarly   = delay !== null && delay < 0;
    // Echtzeit-Daten liegen nur vor, wenn HAFAS dTimeR geliefert hat (departureActual != null).
    // Ohne Echtzeit darf die Sollzeit nicht als "pünktlich" gerendert werden.
    const hasRealtime = dep.departureActual != null;
    // Live-Badge wird bei jeder Abfahrt mit Echtzeit ganz hinten angefügt
    // (pünktlich, verspätet, zu früh). Bei Ausfall und ohne Echtzeit weglassen.
    const liveBadgeHtml = (hasRealtime && !dep.cancelled)
        ? `<span class="dep-live-badge" aria-label="Live-Daten verfügbar">Live</span>`
        : '';

    // Zeitanzeige: Sollzeit + ggf. Verspätung/Frühfahrt + Live-Badge
    let timeHtml;
    if (dep.cancelled) {
        // Ausgefallene Fahrt: Sollzeit rot und durchgestrichen, dahinter Ausfall-Badge
        timeHtml = `<span class="dep-time dep-time-cancelled">${escapeHtml(planned)}</span>
           <span class="dep-cancelled-badge" aria-label="Fahrt ausgefallen">Ausfall</span>`;
    } else if (isDelayed) {
        timeHtml = `<span class="dep-time">${escapeHtml(planned)}</span>
           <span class="dep-delay time-delayed" aria-label="Verspätung ${delay} Minute${delay !== 1 ? 'n' : ''}">
               +${delay}
           </span>${liveBadgeHtml}`;
    } else if (isEarly) {
        const absDelay = Math.abs(delay);
        timeHtml = `<span class="dep-time">${escapeHtml(planned)}</span>
           <span class="dep-delay time-early" aria-label="${absDelay} Minute${absDelay !== 1 ? 'n' : ''} zu früh">
               −${absDelay}
           </span>${liveBadgeHtml}`;
    } else if (hasRealtime) {
        // Echtzeit liegt vor und Fahrt ist pünktlich → Sollzeit grün + Live-Badge
        timeHtml = `<span class="dep-time time-ontime">${escapeHtml(planned)}</span>${liveBadgeHtml}`;
    } else {
        // Keine Live-Daten → neutrale Sollzeit, kein "pünktlich"-Stil
        timeHtml = `<span class="dep-time">${escapeHtml(planned)}</span>`;
    }

    // Kursnummer-Badge – Quelle bestimmt Stil und Tooltip:
    //   manual    → grün  (admin-überschrieben)
    //   recorded  → blau  (Mehrheit aus Erfassungen)
    //   heuristic → orange (route_stops-Fallback, unsicher)
    //   null      → grau  ("noch nicht erfasst")
    // Bestätigen-Button: nur bei nicht-ausgefallenen Fahrten mit bekanntem Kurs.
    // Der Button löst eine reguläre Erfassung aus (POST /api/recordings),
    // ohne dass die Capture-Maske geöffnet werden muss. Der Slot-Container
    // wird immer gerendert (auch leer), damit die Kursnummer-Badges aller
    // Zeilen vertikal bündig untereinander stehen.
    const showConfirm = dep.activeCourseNumber && !dep.cancelled;
    const confirmBtnHtml = showConfirm ? `
        <button type="button" class="course-confirm-btn"
                data-confirm-idx="${idx}"
                aria-label="Kurs ${escapeHtml(dep.activeCourseNumber)} bestätigen"
                title="Kurs ${escapeHtml(dep.activeCourseNumber)} bestätigen">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="3"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
        </button>` : '';
    const confirmSlot = `<span class="course-confirm-slot">${confirmBtnHtml}</span>`;

    let badgeHtml;
    if (dep.activeCourseNumber) {
        const source = dep.courseSource ?? 'recorded';
        const cls = source === 'manual'    ? 'manual'
                  : source === 'heuristic' ? 'heuristic'
                  :                          'known';
        const title = source === 'manual'    ? `Manuelle Kursnummer (${dep.activeCourseNumber})`
                    : source === 'heuristic' ? `Vermutete Kursnummer aus Plan-Daten (${dep.activeCourseNumber})`
                    :                          `Bekannte Kursnummer (${dep.activeCourseNumber})`;
        badgeHtml = `
            <span class="course-number ${cls}" title="${escapeHtml(title)}">
                ${escapeHtml(dep.activeCourseNumber)}
            </span>`;
    } else {
        badgeHtml = `
            <span class="course-number unknown"
                  title="Kursnummer noch nicht erfasst">
                ??
            </span>`;
    }
    const courseHtml = confirmSlot + badgeHtml;

    const ariaLabel = [
        `Linie ${dep.line}`,
        `nach ${dep.direction}`,
        dep.cancelled ? 'Fahrt ausgefallen' : `ab ${planned}`,
        !dep.cancelled && dep.additionalStop && 'Zusatzhalt wegen Umleitung',
        !dep.cancelled && dep.partiallyCancelled && 'Fahrt teilweise ausgefallen',
        !dep.cancelled && (isDelayed
            ? `+${delay} Min. Verspätung`
            : isEarly
                ? `${Math.abs(delay)} Min. zu früh`
                : hasRealtime ? 'pünktlich (Live)' : 'planmäßig'),
        !dep.cancelled && (dep.activeCourseNumber
            ? (dep.courseSource === 'heuristic' ? `Kurs ${dep.activeCourseNumber} (vermutet)` : `Kurs ${dep.activeCourseNumber}`)
            : 'Kurs unbekannt'),
    ].filter(Boolean).join(', ');

    // Hinweis auf ursprüngliche Linie bei Linienwechsel (durchgebundene Fahrt)
    const originalLineHtml = dep.originalLine
        ? `<span class="departure-original-line">vorher Linie\u00a0${escapeHtml(dep.originalLine)}</span>`
        : '';

    // Störungshinweise: Ein Zusatzhalt ist eine reale, erfassbare Abfahrt an
    // einer Haltestelle, die diese Linie planmäßig nicht bedient (Umleitung).
    // Ohne Markierung ist sie von einer planmäßigen Abfahrt nicht zu
    // unterscheiden. Teilausfall heißt: die Bahn fährt hier, endet aber
    // vorzeitig oder überspringt einen Abschnitt.
    const disruptionBadges = [];
    if (dep.additionalStop && !dep.cancelled) {
        disruptionBadges.push(
            `<span class="dep-note-badge" title="Umleitungshalt – diese Linie hält hier planmäßig nicht">Zusatzhalt</span>`
        );
    }
    if (dep.partiallyCancelled && !dep.cancelled) {
        disruptionBadges.push(
            `<span class="dep-note-badge" title="Die Fahrt endet vorzeitig oder überspringt einen Abschnitt">Teilausfall</span>`
        );
    }
    const disruptionHtml = disruptionBadges.length
        ? `<span class="departure-notes">${disruptionBadges.join('')}</span>`
        : '';

    return `
        <li class="card departure-item${dep.cancelled ? ' departure-cancelled' : ''}"
            role="${dep.cancelled ? 'listitem' : 'button'}"
            tabindex="${dep.cancelled ? '-1' : '0'}"
            data-idx="${idx}"
            aria-label="${escapeHtml(ariaLabel)}"
            ${dep.cancelled ? 'aria-disabled="true"' : ''}>
            <span class="departure-line">
                ${lineBadgeHtml(dep.line)}
            </span>
            <span class="departure-info">
                <span class="departure-direction">${escapeHtml(dep.direction)}</span>
                ${originalLineHtml}
                ${disruptionHtml}
                <span class="departure-times">${timeHtml}</span>
            </span>
            <span class="departure-course">${courseHtml}</span>
        </li>`;
}

// --- Event-Handler ----------------------------------------------------------

function handleDepartureSelect(e) {
    // Defensiv: View nicht mehr aktiv (z. B. durch Zombie-Timer nach destroy())
    if (!currentStopId) return;

    // Bestätigen-Button hat Vorrang vor dem Capture-Flow der Card.
    // Klick auf den Button löst eine direkte Erfassung aus, ohne #capture zu öffnen.
    const confirmBtn = e.target.closest('[data-confirm-idx]');
    if (confirmBtn) {
        const dep = storedDepartures[parseInt(confirmBtn.dataset.confirmIdx, 10)];
        if (dep) handleConfirmCourse(dep, confirmBtn);
        return;
    }

    const item = e.target.closest('[data-idx]');
    if (!item) return;

    const dep = storedDepartures[parseInt(item.dataset.idx, 10)];
    if (!dep) return;

    // Ausgefallene Fahrten können nicht erfasst werden
    if (dep.cancelled) return;

    // Alle nötigen Erfassungsdaten in sessionStorage ablegen (für Phase 7).
    // stopId: Lang-ID des konkreten Bahnsteigs aus dem HAFAS-Departure (z.B.
    // "300754301"). Fallback auf die Suchanfrage-ID, wenn HAFAS keine
    // extId mitliefert. Lang-Form ist nötig, damit der Heuristik-Lookup in
    // /api/trips/touch gegen route_stops.stop_id (Lang-ID) matchen kann.
    const captureData = {
        hafasTripId:           dep.hafasTripId,
        serviceNr:             dep.serviceNr,
        line:                  dep.line,
        direction:             dep.direction,
        // Lang-ID des konkreten Bahnsteigs (Heuristik-Lookup gegen route_stops)
        stopId:                dep.stopId ?? currentStopId,
        // Kurz-ID der Haltestelle aus der ursprünglichen Suche (HAFAS-departures
        // braucht diese Form). Wird beim Rücksprung in die Abfahrtstafel benutzt.
        searchStopId:          currentStopId,
        stopName:              currentStopName,
        serviceDate:           dep.departurePlanned ? getServiceDate(dep.departurePlanned) : '',
        departurePlanned:      dep.departurePlanned,
        departureActual:       dep.departureActual ?? null,
        // Start-/Endzeit der Gesamtfahrt – Stichentscheid für den heuristischen
        // Lookup in /api/trips/touch, wenn mehrere Trips auf denselben
        // Route-Schlüssel fallen.
        journeyStartTime:      dep.journeyStartTime ?? null,
        journeyEndTime:        dep.journeyEndTime ?? null,
        // Vorschlag aus der Abfahrtstafel mitgeben – Capture-View zeigt ihn
        // sofort an, ohne auf den Touch-Aufruf warten zu müssen.
        suggestedCourseNumber: dep.activeCourseNumber ?? null,
        suggestedCourseSource: dep.courseSource ?? null,
    };
    sessionStorage.setItem('pendingCapture', JSON.stringify(captureData));
    window.location.hash = '#capture';
}

function handleDepartureKeydown(e) {
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        handleDepartureSelect(e);
    }
}

/**
 * Direkte Bestätigung der angezeigten Kursnummer (Issue #13).
 * Sendet eine reguläre Erfassung über POST /api/recordings – das Backend
 * lädt den Trip selbst, berechnet path-/schedule-Fingerprint und legt
 * recording + route_stops an. Identisch zum Erfassungs-Flow in capture.js,
 * nur ohne Zwischenschritt durch die Folgemaske.
 */
async function handleConfirmCourse(dep, btnEl) {
    if (btnEl.disabled) return;
    btnEl.disabled = true;
    btnEl.classList.add('is-pending');

    try {
        const res = await postRecording({
            hafasTripId:      dep.hafasTripId,
            serviceNr:        dep.serviceNr,
            line:             dep.line,
            direction:        dep.direction,
            // Lang-ID des konkreten Bahnsteigs (entspricht pendingCapture.stopId)
            stopId:           dep.stopId ?? currentStopId,
            serviceDate:      getServiceDate(dep.departurePlanned),
            departurePlanned: dep.departurePlanned,
            departureActual:  dep.departureActual ?? null,
            courseNumber:     dep.activeCourseNumber,
        });

        // Erfolgs-Toast überlebt den Re-Render, weil er an document.body hängt.
        // Liste danach leise neu laden – damit eine bisher heuristische Quelle
        // auf "recorded" wechselt und der Refresh-Timer nicht zwischenfunkt.
        const replaced = (res?.replacedRecordingIds?.length ?? 0) > 0;
        showActionSnackbar(
            `Kurs ${dep.activeCourseNumber} bestätigt${replaced ? ' · vorherige Erfassung ersetzt' : ''}`
        );
        if (currentContainer) {
            await loadAndRender(currentContainer, /* quiet= */ true);
        }
    } catch (err) {
        showActionSnackbar(`Bestätigung fehlgeschlagen: ${err.message}`, /* isError= */ true);
        btnEl.disabled = false;
        btnEl.classList.remove('is-pending');
    }
}
