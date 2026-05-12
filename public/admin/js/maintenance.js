/**
 * maintenance.js – Admin-Tab: Wartung starten/beenden + Historie
 *
 * Status:
 *  - Aktive Wartung: Karte mit Startzeit, "Beenden"-Button. Beim Klick bricht
 *    der Server (Endpoint POST /admin-api/maintenance/end) mit UTC_TIMESTAMP
 *    ab und protokolliert die Endzeit.
 *  - Keine Wartung: Formular mit Textfeld (Default-Vorschlag) + "Starten".
 *  - Historie der letzten 20 Vorgänge (Start/Ende lokal formatiert).
 */

import { apiFetch, escHtml, showMessage } from './admin.js';

const DEFAULT_MESSAGE = 'Wartungsarbeiten – Erfassen, Bearbeiten und Löschen sind gerade nicht möglich. Wir sind gleich wieder da.';

export async function renderMaintenance(container) {
    container.innerHTML = `<div style="text-align:center;padding:48px"><div class="spinner"></div></div>`;

    let payload;
    try {
        payload = await apiFetch('/admin-api/maintenance');
    } catch (err) {
        container.innerHTML = `<div class="error-box">${escHtml(err.message)}</div>`;
        return;
    }

    container.innerHTML = `
        <div class="section-card" id="maint-status-card">
            <h2 class="section-title">Wartungsstatus</h2>
            <div id="maint-msg"></div>
            <div id="maint-status"></div>
        </div>

        <div class="section-card">
            <h2 class="section-title">Letzte 20 Wartungen</h2>
            <div id="maint-history"></div>
        </div>`;

    renderStatus(container, payload);
    renderHistory(container, payload.history ?? []);
}

// --- Status-Karte (aktiv / inaktiv) -----------------------------------------

function renderStatus(container, payload) {
    const statusEl = container.querySelector('#maint-status');

    if (payload.active) {
        statusEl.innerHTML = `
            <div class="notice notice--maintenance" style="margin-bottom:12px;border-radius:6px">
                <strong>Wartung läuft.</strong>
            </div>
            <p><strong>Hinweistext:</strong></p>
            <pre class="maint-message">${escHtml(payload.active.message)}</pre>
            <p class="text-muted text-small" style="margin-top:8px">
                Gestartet: ${formatDateTime(payload.active.startedAt)}
            </p>
            <button type="button" class="btn btn-danger" id="btn-maint-end">
                Wartung beenden
            </button>`;

        statusEl.querySelector('#btn-maint-end').addEventListener('click', async () => {
            if (!confirm('Wartung jetzt beenden? Nutzer können danach wieder erfassen.')) return;
            await endMaintenance(container);
        });
    } else {
        statusEl.innerHTML = `
            <p class="text-muted" style="margin-bottom:12px">Aktuell läuft keine Wartung.</p>
            <form id="maint-start-form" novalidate>
                <div class="form-group">
                    <label for="maint-message">Hinweistext für den Wartungs-Banner</label>
                    <textarea id="maint-message" rows="3" maxlength="500" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary" id="btn-maint-start">
                    Wartung starten
                </button>
            </form>`;

        statusEl.querySelector('#maint-message').value = DEFAULT_MESSAGE;
        statusEl.querySelector('#maint-start-form').addEventListener('submit', async e => {
            e.preventDefault();
            await startMaintenance(container);
        });
    }
}

async function startMaintenance(container) {
    const msgEl   = container.querySelector('#maint-msg');
    const message = container.querySelector('#maint-message').value.trim();
    const btn     = container.querySelector('#btn-maint-start');

    if (!message) {
        showMessage(msgEl, 'Hinweistext darf nicht leer sein.', 'error');
        return;
    }

    btn.disabled = true;
    try {
        await apiFetch('/admin-api/maintenance', {
            method: 'POST',
            body: JSON.stringify({ message }),
        });
        await renderMaintenance(container);
    } catch (err) {
        showMessage(msgEl, err.message, 'error');
    } finally {
        btn.disabled = false;
    }
}

async function endMaintenance(container) {
    const msgEl = container.querySelector('#maint-msg');
    const btn   = container.querySelector('#btn-maint-end');
    btn.disabled = true;
    try {
        await apiFetch('/admin-api/maintenance/end', { method: 'POST' });
        await renderMaintenance(container);
    } catch (err) {
        showMessage(msgEl, err.message, 'error');
    } finally {
        if (document.body.contains(btn)) btn.disabled = false;
    }
}

// --- Historie ---------------------------------------------------------------

function renderHistory(container, history) {
    const el = container.querySelector('#maint-history');

    if (history.length === 0) {
        el.innerHTML = `<div class="empty-state">Noch keine Wartungen protokolliert.</div>`;
        return;
    }

    el.innerHTML = `
        <table class="data-table">
            <thead>
                <tr>
                    <th>Start</th>
                    <th>Ende</th>
                    <th>Dauer</th>
                    <th>Hinweistext</th>
                </tr>
            </thead>
            <tbody>
                ${history.map(h => `
                    <tr>
                        <td>${formatDateTime(h.startedAt)}</td>
                        <td>${h.endedAt ? formatDateTime(h.endedAt) : '<em>läuft</em>'}</td>
                        <td>${formatDuration(h.startedAt, h.endedAt)}</td>
                        <td>${escHtml(h.message)}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>`;
}

// --- Hilfsfunktionen --------------------------------------------------------

function formatDateTime(iso) {
    if (!iso) return '–';
    return new Date(iso).toLocaleString('de-DE', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

function formatDuration(startedAt, endedAt) {
    if (!startedAt) return '–';
    const start = new Date(startedAt).getTime();
    const end   = endedAt ? new Date(endedAt).getTime() : Date.now();
    let s = Math.max(0, Math.round((end - start) / 1000));
    const h = Math.floor(s / 3600); s -= h * 3600;
    const m = Math.floor(s / 60);   s -= m * 60;
    if (h > 0) return `${h} h ${m} min`;
    if (m > 0) return `${m} min`;
    return `${s} s`;
}
