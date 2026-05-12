/**
 * notices.js – Banner unterhalb des App-Headers für Admin-Nachrichten und Wartungsstatus.
 *
 * Es gibt zwei Sorten Banner, die parallel angezeigt werden können:
 *  - Nachricht (.notice--info): Admin-Mitteilung mit Ablaufdatum. Per X dismissbar;
 *    Dismissal pro Announcement-ID in localStorage gespeichert (überlebt Reload).
 *  - Wartung (.notice--maintenance): Hinweis auf laufende Wartung. Nicht dismissbar.
 *
 * Daten: GET /api/notices liefert beide Felder in einem Payload.
 * Polling: einmal beim Start + alle 60 s + bei visibilitychange (Tab wieder aktiv).
 *
 * Maintenance-Statuswechsel werden als CustomEvent 'maintenancechange' auf
 * document dispatched, damit Views (capture/history) ihre Schreib-Buttons
 * client-seitig deaktivieren können. Der Server bricht den eigentlichen Call
 * eh mit 503 ab – das ist nur UX-Vorgriff.
 */

import { getNotices } from './api.js';

const POLL_INTERVAL_MS    = 60_000;
const DISMISSED_KEY       = 'dismissed_announcements';
const DISMISSED_CAP       = 50;          // ältere IDs verwerfen, damit der Storage nicht wächst
const CONTAINER_ID        = 'app-notices';

/** Letzter bekannter Status. Wird von isMaintenanceActive() gelesen. */
const state = {
    announcements: [],   // alle aktiven, id DESC – vor Render auf nicht-dismissed gefiltert
    maintenance:   null,
};

let pollTimer = null;

// =============================================================================
// Öffentliche API
// =============================================================================

/**
 * Beim App-Start aufrufen. Lädt den Initialzustand und startet das Polling.
 */
export function initNotices() {
    refresh();
    pollTimer = setInterval(refresh, POLL_INTERVAL_MS);

    // Tab wieder sichtbar → sofort neu pollen, damit der Banner nicht 60 s alt ist
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') refresh();
    });
}

/** True, wenn gerade eine Wartung läuft (laut letztem Poll). */
export function isMaintenanceActive() {
    return state.maintenance !== null;
}

/** Liefert das aktive Maintenance-Objekt oder null. */
export function getMaintenance() {
    return state.maintenance;
}

// =============================================================================
// Polling + State-Update
// =============================================================================

async function refresh() {
    let payload;
    try {
        payload = await getNotices();
    } catch (err) {
        // Offline/Netzwerkfehler nicht eskalieren – Banner bleibt im letzten Stand
        console.warn('Notices konnten nicht geladen werden:', err);
        return;
    }

    const prevMaintenanceId = state.maintenance?.id ?? null;
    state.announcements = Array.isArray(payload.announcements) ? payload.announcements : [];
    state.maintenance   = payload.maintenance ?? null;

    // Maintenance-Statuswechsel an Views melden (capture, history)
    const newMaintenanceId = state.maintenance?.id ?? null;
    if (newMaintenanceId !== prevMaintenanceId) {
        document.dispatchEvent(new CustomEvent('maintenancechange', {
            detail: { maintenance: state.maintenance }
        }));
    }

    render();
}

// =============================================================================
// Rendering
// =============================================================================

function render() {
    const container = document.getElementById(CONTAINER_ID);
    if (!container) return;

    container.innerHTML = '';

    // Wartung zuerst (oben) – wichtigste Information.
    if (state.maintenance) {
        container.appendChild(renderMaintenance(state.maintenance));
    }
    // Jüngste noch nicht dismisste Nachricht – nach Wegklick rückt
    // beim nächsten render() automatisch die nächst-jüngere nach.
    const dismissed = new Set(loadDismissed());
    const next = state.announcements.find(a => !dismissed.has(a.id));
    if (next) {
        container.appendChild(renderAnnouncement(next));
    }

    updateContainerHeight();
}

function renderMaintenance(m) {
    const el = document.createElement('div');
    el.className = 'notice notice--maintenance';
    el.setAttribute('role', 'alert');

    const text = document.createElement('span');
    text.className = 'notice-text';
    text.textContent = m.message;
    el.appendChild(text);

    return el;
}

function renderAnnouncement(a) {
    const el = document.createElement('div');
    el.className = 'notice notice--info';
    el.setAttribute('role', 'status');

    const text = document.createElement('span');
    text.className = 'notice-text';
    text.textContent = a.body;
    el.appendChild(text);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'notice-close';
    close.setAttribute('aria-label', 'Nachricht ausblenden');
    close.textContent = '×';
    close.addEventListener('click', () => {
        addDismissed(a.id);
        // Direkt neu rendern, damit die nächst-jüngere Nachricht sofort
        // erscheint statt erst beim nächsten Poll.
        render();
    });
    el.appendChild(close);

    return el;
}

/**
 * Setzt CSS-Variable --notices-height auf die tatsächliche Bannerhöhe,
 * damit #app-main entsprechend nach unten rutscht.
 */
function updateContainerHeight() {
    const container = document.getElementById(CONTAINER_ID);
    if (!container) return;
    const h = container.offsetHeight;
    document.documentElement.style.setProperty('--notices-height', `${h}px`);
}

// =============================================================================
// Dismissal-Liste im localStorage
// =============================================================================

function loadDismissed() {
    try {
        const raw = localStorage.getItem(DISMISSED_KEY);
        if (!raw) return [];
        const arr = JSON.parse(raw);
        return Array.isArray(arr) ? arr.filter(n => Number.isInteger(n)) : [];
    } catch {
        return [];
    }
}

function isDismissed(id) {
    return loadDismissed().includes(id);
}

function addDismissed(id) {
    const list = loadDismissed();
    if (list.includes(id)) return;
    list.push(id);
    // Cap: ältere IDs (die kleineren) zuerst verwerfen
    if (list.length > DISMISSED_CAP) {
        list.sort((a, b) => a - b);
        list.splice(0, list.length - DISMISSED_CAP);
    }
    try {
        localStorage.setItem(DISMISSED_KEY, JSON.stringify(list));
    } catch {
        // Quota voll – ignorieren, nächstes Reload zeigt den Banner halt erneut
    }
}
