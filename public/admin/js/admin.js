/**
 * admin.js – Einstiegspunkt des Admin-Frontends
 *
 * Aufgaben:
 *  - Session-Status erkennen (geschützten Endpunkt aufrufen)
 *  - Login-Formular anzeigen / verarbeiten
 *  - Dashboard mit Tab-Navigation rendern
 *  - Logout verarbeiten
 */

import { renderSchoolHolidays } from './school_holidays.js';
import { renderPeriods }        from './periods.js';
import { renderOverride }       from './override.js';
import { renderHafasLog }       from './hafas_log.js';
import { renderTrips }          from './trips.js';
import { renderAnnouncements }  from './announcements.js';
import { renderMaintenance }    from './maintenance.js';

// =============================================================================
// API-Helfer (Admin-Kontext; nutzt dieselben Fetch-Konventionen wie api.js)
// =============================================================================

/**
 * Basisimplementierung des Fetch-Wrappers für das Admin-Frontend.
 * Wirft bei HTTP-Fehlern einen Error mit der Backend-Fehlermeldung.
 *
 * @param {string}      url
 * @param {RequestInit} [options]
 * @returns {Promise<any>}
 */
export async function apiFetch(url, options = {}) {
    const defaults = { headers: { 'Content-Type': 'application/json' } };

    let response;
    try {
        response = await fetch(url, { ...defaults, ...options });
    } catch (networkErr) {
        throw new Error(`Netzwerkfehler: ${networkErr.message}`);
    }

    if (response.status === 204) return null;

    const text = await response.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch {
        const err = new Error(
            response.ok
                ? 'Ungültige Serverantwort (kein JSON)'
                : `Server-Fehler ${response.status}`
        );
        err.status = response.status;
        throw err;
    }

    if (!response.ok) {
        const err = new Error(data?.error ?? `HTTP ${response.status}`);
        err.status = response.status;
        throw err;
    }

    return data;
}

/** Minimales HTML-Escaping gegen XSS */
export function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Zeigt eine kurzlebige Erfolgs- oder Fehlermeldung im angegebenen Element.
 * @param {HTMLElement} el
 * @param {string}      message
 * @param {'success'|'error'} type
 */
export function showMessage(el, message, type = 'success') {
    el.className  = type === 'success' ? 'success-box' : 'error-box';
    el.textContent = message;
    el.hidden = false;
    if (type === 'success') {
        setTimeout(() => { el.hidden = true; }, 3000);
    }
}

// --- Stop-Name-Hilfsfunktionen -----------------------------------------------

let _stopPrefixCache = null;

export async function getStopNamePrefix() {
    if (_stopPrefixCache !== null) return _stopPrefixCache;
    try {
        const cfg = await apiFetch('/api/config');
        _stopPrefixCache = cfg.stopNamePrefix ?? '';
    } catch {
        _stopPrefixCache = '';
    }
    return _stopPrefixCache;
}

export function stripStopName(name, prefix) {
    if (!prefix || !name) return name ?? '';
    if (name.startsWith(prefix)) return name.slice(prefix.length).trim();
    return name;
}

// =============================================================================
// Tab-Routing
// =============================================================================

const TABS = {
    'trips':           renderTrips,
    'school-holidays': renderSchoolHolidays,
    'periods':         renderPeriods,
    'override':        renderOverride,
    'hafas-log':       renderHafasLog,
    'maintenance':     renderMaintenance,
    'announcements':   renderAnnouncements,
};

let activeTab = 'trips';

function switchTab(tabName) {
    if (!TABS[tabName]) return;
    activeTab = tabName;

    // Tab-Buttons aktualisieren
    document.querySelectorAll('.tab-btn').forEach(btn => {
        const isActive = btn.dataset.tab === tabName;
        btn.classList.toggle('active', isActive);
        btn.setAttribute('aria-selected', String(isActive));
    });

    const panel = document.getElementById('tab-panel');
    panel.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:center;padding:48px">
            <div class="spinner" aria-hidden="true"></div>
        </div>`;

    TABS[tabName](panel);
}

// =============================================================================
// Login / Logout
// =============================================================================

function showLogin() {
    document.getElementById('login-view').hidden    = false;
    document.getElementById('dashboard-view').hidden = true;
}

function showDashboard() {
    document.getElementById('login-view').hidden    = true;
    document.getElementById('dashboard-view').hidden = false;
    switchTab(activeTab);
}

async function handleLogin(e) {
    e.preventDefault();
    const password = document.getElementById('login-password').value;
    const errorEl  = document.getElementById('login-error');
    const btnLogin = document.getElementById('btn-login');

    errorEl.hidden = true;
    btnLogin.disabled = true;
    btnLogin.textContent = 'Anmelden…';

    try {
        await apiFetch('/admin-api/login', {
            method: 'POST',
            body: JSON.stringify({ password }),
        });
        document.getElementById('login-password').value = '';
        showDashboard();
    } catch (err) {
        errorEl.textContent = err.message === 'Ungültiges Passwort'
            ? 'Ungültiges Passwort.'
            : `Fehler: ${err.message}`;
        errorEl.hidden = false;
    } finally {
        btnLogin.disabled = false;
        btnLogin.textContent = 'Anmelden';
    }
}

async function handleLogout() {
    try {
        await apiFetch('/admin-api/logout', { method: 'POST' });
    } catch {
        // Logout-Fehler ignorieren – Session-Cookie wird ggf. ohnehin ungültig
    }
    showLogin();
}

// =============================================================================
// Initialisierung
// =============================================================================

async function init() {
    // Session-Check: geschützten Endpunkt aufrufen
    // 200 → bereits eingeloggt; 401 → Login-Formular zeigen
    try {
        await apiFetch('/admin-api/school-holidays');
        showDashboard();
    } catch (err) {
        if (err.status === 401) {
            showLogin();
        } else {
            // Unerwarteter Fehler (z.B. Netzwerk) → Login zeigen, Nutzer kann es selbst versuchen
            showLogin();
            console.error('Session-Check fehlgeschlagen:', err);
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Login-Formular
    document.getElementById('login-form')
        .addEventListener('submit', handleLogin);

    // Logout-Button
    document.getElementById('btn-logout')
        .addEventListener('click', handleLogout);

    // Tab-Navigation
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => switchTab(btn.dataset.tab));
    });

    init();
});
