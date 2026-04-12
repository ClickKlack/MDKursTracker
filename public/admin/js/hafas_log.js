/**
 * hafas_log.js – Admin-Tab: HAFAS-Zugriffslog
 *
 * Zeigt:
 *  - Filter: Datum-Von/Bis, Endpoint, HTTP-Status
 *  - Aggregation: pro Tag (Anfragen, Cache-Hit %, Ø Antwortzeit, Fehler)
 *  - Aggregation: pro Tag & HTTP-Status
 *  - Aggregation: pro Endpoint
 *  - Einzeleinträge (max. 500, neueste zuerst)
 */

import { apiFetch, escHtml } from './admin.js';

export async function renderHafasLog(container) {
    // Standard: heute
    const today = new Date().toISOString().slice(0, 10);

    container.innerHTML = buildShell(today);
    attachListeners(container);
    await loadAndRender(container);
}

// --- Shell -------------------------------------------------------------------

function buildShell(today) {
    return `
        <div class="hafaslog-filters">
            <div class="form-row">
                <div class="form-group">
                    <label for="hl-date-from">Von</label>
                    <input type="date" id="hl-date-from" value="${today}">
                </div>
                <div class="form-group">
                    <label for="hl-date-to">Bis</label>
                    <input type="date" id="hl-date-to" value="${today}">
                </div>
                <div class="form-group">
                    <label for="hl-endpoint">Endpoint</label>
                    <select id="hl-endpoint">
                        <option value="">Alle</option>
                        <option value="nearby">nearby</option>
                        <option value="departures">departures</option>
                        <option value="trip">trip</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="hl-status">HTTP-Status</label>
                    <select id="hl-status">
                        <option value="">Alle</option>
                        <option value="200">200 OK</option>
                        <option value="429">429 Rate Limit</option>
                        <option value="500">500 Fehler</option>
                        <option value="503">503 Nicht verfügbar</option>
                    </select>
                </div>
                <div class="form-group form-group--btn">
                    <button class="btn btn-primary" id="hl-btn-load">Laden</button>
                </div>
            </div>
        </div>
        <div id="hl-result"></div>`;
}

// --- Listener ----------------------------------------------------------------

function attachListeners(container) {
    container.querySelector('#hl-btn-load')
        .addEventListener('click', () => loadAndRender(container));

    // Enter in Datums-Feldern
    ['#hl-date-from', '#hl-date-to'].forEach(sel => {
        container.querySelector(sel)
            .addEventListener('keydown', e => { if (e.key === 'Enter') loadAndRender(container); });
    });
}

// --- Laden -------------------------------------------------------------------

async function loadAndRender(container) {
    const result = container.querySelector('#hl-result');
    result.innerHTML = `<div style="padding:24px;text-align:center">
        <div class="spinner" aria-hidden="true"></div></div>`;

    const dateFrom = container.querySelector('#hl-date-from').value;
    const dateTo   = container.querySelector('#hl-date-to').value;
    const endpoint = container.querySelector('#hl-endpoint').value;
    const status   = container.querySelector('#hl-status').value;

    const params = new URLSearchParams({ date_from: dateFrom, date_to: dateTo });
    if (endpoint) params.set('endpoint', endpoint);
    if (status)   params.set('status', status);

    let data;
    try {
        data = await apiFetch(`/admin-api/hafas-log?${params}`);
    } catch (err) {
        result.innerHTML = `<div class="error-box">${escHtml(err.message)}</div>`;
        return;
    }

    result.innerHTML = renderAll(data);
}

// --- Render ------------------------------------------------------------------

function renderAll(data) {
    const totalEntries = data.entries.length;
    const hasData = data.byDay.length > 0 || totalEntries > 0;

    if (!hasData) {
        return `<div class="empty-state"><p>Keine Daten für den gewählten Zeitraum.</p></div>`;
    }

    return `
        ${renderByDay(data.byDay)}
        ${renderByDayStatus(data.byDayStatus)}
        ${renderByEndpoint(data.byEndpoint)}
        ${renderEntries(data.entries)}`;
}

function renderByDay(rows) {
    if (!rows.length) return '';
    const head = `<tr>
        <th>Tag</th><th>Gesamt</th><th>Echt</th><th>Cache</th>
        <th>Cache %</th><th>Ø ms</th><th>Fehler</th><th>429</th><th>max Retry-After</th>
    </tr>`;
    const body = rows.map(r => `<tr>
        <td>${escHtml(r.day)}</td>
        <td>${r.total}</td>
        <td>${r.realRequests}</td>
        <td>${r.cacheHits}</td>
        <td class="${r.cacheHitPct >= 80 ? 'hl-good' : ''}">${r.cacheHitPct} %</td>
        <td>${r.avgDurationMs ?? '–'}</td>
        <td class="${r.errors > 0 ? 'hl-warn' : ''}">${r.errors}</td>
        <td class="${r.rateLimits > 0 ? 'hl-error' : ''}">${r.rateLimits}</td>
        <td>${r.maxRetryAfter !== null ? r.maxRetryAfter + ' s' : '–'}</td>
    </tr>`).join('');
    return section('Übersicht pro Tag', `<table class="hl-table">${head}${body}</table>`);
}

function renderByDayStatus(rows) {
    if (!rows.length) return '';
    const head = `<tr><th>Tag</th><th>HTTP-Status</th><th>Anzahl</th></tr>`;
    const body = rows.map(r => `<tr>
        <td>${escHtml(r.day)}</td>
        <td class="${r.httpStatus >= 400 ? 'hl-warn' : ''}">${r.httpStatus}</td>
        <td>${r.count}</td>
    </tr>`).join('');
    return section('Anfragen nach Tag & Status', `<table class="hl-table">${head}${body}</table>`);
}

function renderByEndpoint(rows) {
    if (!rows.length) return '';
    const head = `<tr><th>Endpoint</th><th>Gesamt</th><th>Echt</th><th>Cache %</th><th>Ø ms</th></tr>`;
    const body = rows.map(r => `<tr>
        <td><code>${escHtml(r.endpoint)}</code></td>
        <td>${r.total}</td>
        <td>${r.realRequests}</td>
        <td>${r.cacheHitPct} %</td>
        <td>${r.avgDurationMs ?? '–'}</td>
    </tr>`).join('');
    return section('Aufrufe nach Endpoint', `<table class="hl-table">${head}${body}</table>`);
}

function formatParams(params) {
    if (!params) return '';
    const parts = Object.entries(params).map(([k, v]) => `${k}=${v}`);
    return escHtml(parts.join(', '));
}

function renderEntries(entries) {
    if (!entries.length) return '';
    const head = `<tr>
        <th>Zeit (UTC)</th><th>Endpoint</th><th>Status</th>
        <th>ms</th><th>Cache</th><th>Retry-After</th><th>Parameter</th>
    </tr>`;
    const body = entries.map(e => `<tr>
        <td class="hl-mono">${escHtml(e.loggedAt)}</td>
        <td><code>${escHtml(e.endpoint)}</code></td>
        <td class="${e.httpStatus >= 400 ? 'hl-warn' : ''}">${e.httpStatus}</td>
        <td class="${e.durationMs > 3000 ? 'hl-warn' : ''}">${e.cacheHit ? '–' : e.durationMs}</td>
        <td>${e.cacheHit ? '✓' : ''}</td>
        <td>${e.retryAfter !== null ? e.retryAfter + ' s' : ''}</td>
        <td class="hl-mono hl-params">${formatParams(e.params)}</td>
    </tr>`).join('');
    const note = entries.length === 500 ? `<p class="hl-note">Maximal 500 Einträge angezeigt – Filter eingrenzen.</p>` : '';
    return section(`Einzeleinträge (${entries.length})`, note + `<table class="hl-table">${head}${body}</table>`);
}

function section(title, content) {
    return `<section class="hl-section">
        <h3 class="hl-section-title">${escHtml(title)}</h3>
        ${content}
    </section>`;
}
