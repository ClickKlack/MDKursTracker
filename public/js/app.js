/**
 * app.js – Einstiegspunkt der MDKursTracker-PWA
 *
 * Aufgaben:
 *  - Service Worker registrieren
 *  - Aktive Fahrplanperiode vom Backend laden (globaler State)
 *  - Hash-basierter Router (#nearby, #departures, #capture, #history)
 *  - View-Lifecycle verwalten (render / destroy)
 */

import { getPeriods } from './api.js';

// =============================================================================
// Globaler State
// =============================================================================

/** Aktive Fahrplanperiode ({ id, name, startDate, ... }) oder null während Laden */
export let activePeriod = null;

/** Aktuell geladene Haltestelle für den Übergang Nearby → Departures */
export let activeStop = null;

// =============================================================================
// View-Definitionen
// =============================================================================

/**
 * Bekannte Views mit Titel und Modul-Pfad.
 * Neue Views in späteren Phasen hier eintragen.
 */
const VIEWS = {
    nearby: {
        title: 'Haltestellen in der Nähe',
        module: './views/nearby.js',
    },
    departures: {
        title: 'Abfahrten',
        module: './views/departures.js',
    },
    capture: {
        title: 'Kursnummer erfassen',
        module: './views/capture.js',
    },
    history: {
        title: 'Erfassungen',
        module: './views/history.js',
    },
};

/** Aktuell aktives View-Modul (hat render() und destroy()) */
let currentViewModule = null;

// =============================================================================
// Service Worker
// =============================================================================

function registerServiceWorker() {
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js')
            .then(reg => console.debug('SW registriert, Scope:', reg.scope))
            .catch(err => console.warn('SW-Registrierung fehlgeschlagen:', err));
    }
}

// =============================================================================
// Aktive Periode laden
// =============================================================================

async function loadActivePeriod() {
    try {
        const periods = await getPeriods();
        // Aktive Periode: active === true (höchste id, vom Backend markiert)
        activePeriod = periods.find(p => p.active) ?? periods.at(-1) ?? null;
        updatePeriodBadge();
    } catch (err) {
        console.error('Perioden konnten nicht geladen werden:', err);
        // Kein showError hier – Periode ist nicht zwingend für alle Views nötig
    }
}

function updatePeriodBadge() {
    const badge = document.getElementById('period-badge');
    if (badge) {
        badge.textContent = activePeriod?.name ?? '';
    }
}

// =============================================================================
// Router
// =============================================================================

/**
 * Hash parsen: z.B. "#departures?stopId=foo" → { view: "departures", params: URLSearchParams }
 */
function parseHash() {
    const hash = window.location.hash.slice(1) || 'nearby';
    const [viewName, queryString] = hash.split('?');
    return {
        view: viewName,
        params: new URLSearchParams(queryString ?? ''),
    };
}

async function handleRouteChange() {
    const { view, params } = parseHash();

    // Navigation-Highlighting aktualisieren
    document.querySelectorAll('.nav-item').forEach(el => {
        el.classList.toggle('active', el.dataset.view === view);
    });

    // View-Titel setzen
    const title = VIEWS[view]?.title ?? 'MDKursTracker';
    const titleEl = document.getElementById('view-title');
    if (titleEl) titleEl.textContent = title;

    // Altes View-Modul abräumen
    if (currentViewModule?.destroy) {
        currentViewModule.destroy();
    }
    currentViewModule = null;

    // View-Modul laden und rendern
    const main = document.getElementById('app-main');
    const viewDef = VIEWS[view];

    if (!viewDef) {
        renderNotFound(main, view);
        return;
    }

    showLoading(main);

    try {
        const mod = await import(viewDef.module);
        currentViewModule = mod;
        await mod.render(main, params, { activePeriod });
    } catch (err) {
        // Modul noch nicht implementiert (404) oder Laufzeitfehler
        if (err?.message?.includes('Failed to fetch') || err instanceof TypeError) {
            renderPlaceholder(main, viewDef.title);
        } else {
            renderError(main, `View konnte nicht geladen werden: ${err.message}`);
        }
        console.warn(`View "${view}" nicht ladbar:`, err);
    }
}

// =============================================================================
// Hilfsfunktionen für den View-Container
// =============================================================================

function showLoading(container) {
    container.innerHTML = `
        <div class="loading-indicator" aria-label="Lädt…">
            <div class="spinner" aria-hidden="true"></div>
            <p>Lädt…</p>
        </div>`;
}

function renderError(container, message) {
    container.innerHTML = `<div class="error-box">${escapeHtml(message)}</div>`;
}

function renderNotFound(container, viewName) {
    container.innerHTML = `
        <div class="empty-state">
            <p>Unbekannte Ansicht: <strong>${escapeHtml(viewName)}</strong></p>
        </div>`;
}

/** Platzhalter für noch nicht implementierte Views (Phasen 6–8) */
function renderPlaceholder(container, title) {
    container.innerHTML = `
        <div class="empty-state">
            <p><strong>${escapeHtml(title)}</strong></p>
            <p class="text-muted mt-8">Diese Ansicht wird in einer späteren Phase implementiert.</p>
        </div>`;
}

/** Einfaches HTML-Escaping gegen XSS */
export function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// =============================================================================
// Einstiegspunkt
// =============================================================================

document.addEventListener('DOMContentLoaded', async () => {
    registerServiceWorker();

    // Aktive Periode laden (parallel zum ersten Route-Render möglich,
    // aber Views benötigen sie – daher await)
    await loadActivePeriod();

    // Router-Events
    window.addEventListener('hashchange', handleRouteChange);

    // Initiale Route rendern
    await handleRouteChange();
});
