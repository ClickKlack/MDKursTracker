/**
 * departures.js – View: Abfahrtstafel einer Haltestelle
 *
 * Ablauf:
 *  1. stopId aus URL-Params lesen (fehlt → zurück zu #nearby)
 *  2. Abfahrten vom Backend laden (api.js)
 *  3. Liste rendern: Linie, Richtung, Soll/Ist-Zeit, Verspätung, Kursnummer
 *  4. Tap auf Abfahrt → Erfassungsdaten in sessionStorage → #capture (Phase 7)
 *  5. Automatische Aktualisierung alle 30 Sekunden
 */

import { getDepartures }                          from '../api.js';
import { formatTime, calcDelay, getServiceDate }  from '../utils/format.js';
import { lineBadgeHtml }                          from '../utils/lines.js';
import { escapeHtml, stripStopPrefix }            from '../app.js';

/** Laufender Auto-Refresh-Timer */
let refreshTimer = null;

/** Aktuell angezeigter Stop (für Refresh-Aufrufe ohne erneute Params-Übergabe) */
let currentStopId   = null;
let currentStopName = null;

/** Zuletzt geladene Abfahrten (für den Click-Handler via Index) */
let storedDepartures = [];

export async function render(container, params, context) {
    currentStopId   = params.get('stopId');
    currentStopName = params.get('stopName') ?? currentStopId ?? '';

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
    storedDepartures = [];
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

    storedDepartures = departures ?? [];

    if (storedDepartures.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <p>Keine Abfahrten in den nächsten Minuten.</p>
            </div>`;
        return;
    }

    // Zeitstempel der letzten Aktualisierung
    const now = new Date();

    container.innerHTML = `
        <div class="departures-header">
            <span class="text-small text-muted" aria-live="polite">
                Stand: ${formatTime(now.toISOString())} Uhr
            </span>
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
        <ul class="card-list departures-list" role="list" aria-label="Abfahrten">
            ${storedDepartures.map((dep, idx) => renderDepartureItem(dep, idx)).join('')}
        </ul>`;

    // Manueller Refresh-Button
    container.querySelector('#btn-refresh')
        .addEventListener('click', () => loadAndRender(container, false));

    // Tap/Klick auf Abfahrt → Kursnummer erfassen (Phase 7)
    container.querySelector('.departures-list')
        .addEventListener('click',   handleDepartureSelect);
    container.querySelector('.departures-list')
        .addEventListener('keydown', handleDepartureKeydown);
}

// --- Einzelne Abfahrt rendern -----------------------------------------------

function renderDepartureItem(dep, idx) {
    const planned  = formatTime(dep.departurePlanned);
    const delay     = calcDelay(dep.departurePlanned, dep.departureActual);
    const isDelayed = delay !== null && delay > 0;
    const isEarly   = delay !== null && delay < 0;

    // Zeitanzeige: Sollzeit + ggf. Verspätung oder Frühfahrt
    let timeHtml;
    if (isDelayed) {
        timeHtml = `<span class="dep-time">${escapeHtml(planned)}</span>
           <span class="dep-delay time-delayed" aria-label="Verspätung ${delay} Minute${delay !== 1 ? 'n' : ''}">
               +${delay}
           </span>`;
    } else if (isEarly) {
        const absDelay = Math.abs(delay);
        timeHtml = `<span class="dep-time">${escapeHtml(planned)}</span>
           <span class="dep-delay time-early" aria-label="${absDelay} Minute${absDelay !== 1 ? 'n' : ''} zu früh">
               −${absDelay}
           </span>`;
    } else {
        timeHtml = `<span class="dep-time time-ontime">${escapeHtml(planned)}</span>`;
    }

    // Kursnummer-Badge
    let courseHtml;
    if (dep.activeCourseNumber) {
        courseHtml = `
            <span class="course-number known"
                  title="Bekannte Kursnummer (${dep.activeCourseNumber})">
                ${escapeHtml(dep.activeCourseNumber)}
            </span>`;
    } else {
        courseHtml = `
            <span class="course-number unknown"
                  title="Kursnummer noch nicht erfasst">
                ??
            </span>`;
    }

    const ariaLabel = [
        `Linie ${dep.line}`,
        `nach ${dep.direction}`,
        `ab ${planned}`,
        isDelayed ? `+${delay} Min. Verspätung` : isEarly ? `${Math.abs(delay)} Min. zu früh` : 'pünktlich',
        dep.activeCourseNumber ? `Kurs ${dep.activeCourseNumber}` : 'Kurs unbekannt',
    ].join(', ');

    // Kompakte Laufweg-Info: "ab HH:MM · bis HH:MM"
    const journeyParts = [];
    if (dep.journeyStartTime) journeyParts.push(`ab\u00a0${formatTime(dep.journeyStartTime)}`);
    if (dep.journeyEndTime)   journeyParts.push(`bis\u00a0${formatTime(dep.journeyEndTime)}`);
    const journeyMetaHtml = journeyParts.length > 0
        ? `<span class="departure-journey-meta">${escapeHtml(journeyParts.join(' · '))}</span>`
        : '';

    // Hinweis auf ursprüngliche Linie bei Linienwechsel (durchgebundene Fahrt)
    const originalLineHtml = dep.originalLine
        ? `<span class="departure-original-line">vorher Linie\u00a0${escapeHtml(dep.originalLine)}</span>`
        : '';

    return `
        <li class="card departure-item"
            role="button"
            tabindex="0"
            data-idx="${idx}"
            aria-label="${escapeHtml(ariaLabel)}">
            <span class="departure-line">
                ${lineBadgeHtml(dep.line)}
            </span>
            <span class="departure-info">
                <span class="departure-direction">${escapeHtml(dep.direction)}</span>
                ${originalLineHtml}
                ${journeyMetaHtml}
                <span class="departure-times">${timeHtml}</span>
            </span>
            <span class="departure-course">${courseHtml}</span>
        </li>`;
}

// --- Event-Handler ----------------------------------------------------------

function handleDepartureSelect(e) {
    const item = e.target.closest('[data-idx]');
    if (!item) return;

    const dep = storedDepartures[parseInt(item.dataset.idx, 10)];
    if (!dep) return;

    // Alle nötigen Erfassungsdaten in sessionStorage ablegen (für Phase 7)
    const captureData = {
        hafasTripId:      dep.hafasTripId,
        serviceNr:        dep.serviceNr,
        line:             dep.line,
        direction:        dep.direction,
        stopId:           currentStopId,
        stopName:         currentStopName,
        serviceDate:      dep.departurePlanned ? getServiceDate(dep.departurePlanned) : '',
        departurePlanned: dep.departurePlanned,
        departureActual:  dep.departureActual ?? null,
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
