/**
 * app.js – Einstiegspunkt der MDKursTracker-PWA
 *
 * Aufgaben:
 *  - Service Worker registrieren
 *  - Aktive Fahrplanperiode vom Backend laden (globaler State)
 *  - Hash-basierter Router (#nearby, #departures, #capture, #history)
 *  - View-Lifecycle verwalten (render / destroy)
 */

import { getPeriods, getConfig, postUserInit } from './api.js';

// =============================================================================
// Globaler State
// =============================================================================

/** Aktive Fahrplanperiode ({ id, name, startDate, ... }) oder null während Laden */
export let activePeriod = null;

/** Aktuell geladene Haltestelle für den Übergang Nearby → Departures */
export let activeStop = null;

/** Haltestellenpräfix, der in der Anzeige entfernt wird (z.B. "Magdeburg, ") */
let stopNamePrefix = '';

/** Öffentliche App-Konfiguration (stopNamePrefix, version, swCacheVersion, deployedAt) */
export let appConfig = {};

/**
 * Entfernt den konfigurierten Präfix aus einem Haltestellennamen.
 * Beispiel: "Magdeburg, ZOB/Adelheidring" → "ZOB/Adelheidring"
 * @param {string} name
 * @returns {string}
 */
export function stripStopPrefix(name) {
    if (stopNamePrefix && String(name).startsWith(stopNamePrefix)) {
        return name.slice(stopNamePrefix.length);
    }
    return name;
}

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
    info: {
        title: 'Über',
        module: './views/info.js',
    },
    profile: {
        title: 'Profil',
        module: './views/profile.js',
    },
};

/** Aktuell aktives View-Modul (hat render() und destroy()) */
let currentViewModule = null;

// =============================================================================
// Service Worker + Update-Mechanismus
// =============================================================================

/**
 * true, sobald ein neuer SW im Waiting-State erkannt wurde.
 * Wird von profile.js abgefragt, um den Update-Button anzuzeigen.
 */
export let swUpdateWaiting = false;

/** Gespeicherte SW-Registration, um später skipWaiting senden zu können. */
let swReg = null;

/**
 * Löst das Update aus: sendet SKIP_WAITING an den wartenden SW.
 * Die Seite wird nach dem controllerchange-Event neu geladen.
 */
export function applySwUpdate() {
    if (swReg?.waiting) {
        swReg.waiting.postMessage({ type: 'SKIP_WAITING' });
    }
}

/**
 * Prüft sofort auf eine neue sw.js und wendet sie ggf. an.
 * Gibt ein Promise zurück, das auflöst sobald der Check abgeschlossen ist.
 */
export function checkForSwUpdate() {
    return swReg?.update().catch(() => {}) ?? Promise.resolve();
}

/**
 * Gibt die Version des aktuell aktiven Service Workers zurück,
 * indem der Cache-Name ausgelesen wird (z.B. "mdkurstracker-shell-v22" → "v22").
 * Gibt null zurück, wenn kein passender Cache gefunden wird.
 */
export async function getActiveSwVersion() {
    if (!('caches' in window)) return null;
    try {
        const keys = await caches.keys();
        const name = keys.find(k => k.startsWith('mdkurstracker-shell-'));
        return name ? name.replace('mdkurstracker-shell-', '') : null;
    } catch {
        return null;
    }
}

function signalUpdateAvailable() {
    swUpdateWaiting = true;
    // Punkt am Profil-Icon einblenden
    const dot = document.getElementById('profile-nav-dot');
    if (dot) dot.hidden = false;
    // Profile-View informieren (falls gerade offen)
    document.dispatchEvent(new CustomEvent('swupdateavailable'));
}

function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;

    // Erstbesuch: noch kein Controller → kein Reload nach Update-Erkennung
    const isFirstInstall = !navigator.serviceWorker.controller;

    navigator.serviceWorker.register('/sw.js')
        .then(reg => {
            swReg = reg;
            console.debug('SW registriert, Scope:', reg.scope);

            // Sofort auf neue sw.js prüfen (umgeht browser-interne 24h-Throttle)
            reg.update().catch(() => {});

            // Race-Condition: SW steckt bereits im Waiting-State
            if (reg.waiting && !isFirstInstall) {
                signalUpdateAvailable();
                return;
            }

            // Auf neu installierten SW warten
            reg.addEventListener('updatefound', () => {
                const newWorker = reg.installing;
                if (!newWorker) return;
                newWorker.addEventListener('statechange', () => {
                    if (newWorker.state === 'installed' && reg.active && !isFirstInstall) {
                        signalUpdateAvailable();
                    }
                });
            });
        })
        .catch(err => console.warn('SW-Registrierung fehlgeschlagen:', err));

    // Neuer SW hat via clients.claim() übernommen → Seite neu laden
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!isFirstInstall) {
            console.debug('Neuer SW aktiv – Seite wird neu geladen');
            window.location.reload();
        }
    });
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
        await mod.render(main, params, { activePeriod, appConfig });
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
// User-Token
// =============================================================================

/**
 * Stellt sicher, dass ein User-Token in localStorage vorhanden ist.
 * Erzeugt bei Erstbesuch ein neues Token und meldet es beim Backend an.
 */
function initUserToken() {
    let token = localStorage.getItem('user_token');
    if (!token) {
        // UUID v4 ohne Bindestriche generieren
        token = crypto.randomUUID().replace(/-/g, '');
        localStorage.setItem('user_token', token);
    }
    // Silent POST – kein Fehler wenn offline oder Backend nicht erreichbar
    postUserInit().catch(() => {});
}

// =============================================================================
// Einstiegspunkt
// =============================================================================

async function loadConfig() {
    try {
        const cfg = await getConfig();
        stopNamePrefix = cfg.stopNamePrefix ?? '';
        appConfig      = cfg;
    } catch (err) {
        console.warn('Frontend-Config konnte nicht geladen werden:', err);
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    registerServiceWorker();

    // User-Token initialisieren (synchron – kein await, da Backend-Call silent)
    initUserToken();

    // Config und Periode parallel laden – beide werden vor dem ersten Render benötigt
    await Promise.all([loadConfig(), loadActivePeriod()]);

    // Router-Events
    window.addEventListener('hashchange', handleRouteChange);

    // Initiale Route rendern
    await handleRouteChange();
});
