/**
 * hafas_log.js – Admin-Tab: HAFAS-Zugriffslog
 */

import { apiFetch, escHtml } from './admin.js';

export async function renderHafasLog(container) {
    const today = new Date().toISOString().slice(0, 10);
    container.innerHTML = buildShell(today);
    attachListeners(container);
    await loadAndRender(container);
}

// --- Shell -------------------------------------------------------------------

function buildShell(today) {
    return `
        <div class="section-card">
            <h2 class="section-title">HAFAS-Log</h2>
            <div class="override-filters">
                <div class="form-group form-group--sm">
                    <label for="hl-date-from">Von</label>
                    <input type="date" id="hl-date-from" value="${today}">
                </div>
                <div class="form-group form-group--sm">
                    <label for="hl-date-to">Bis</label>
                    <input type="date" id="hl-date-to" value="${today}">
                </div>
                <div class="form-group form-group--sm">
                    <label for="hl-endpoint">Endpoint</label>
                    <select id="hl-endpoint">
                        <option value="">Alle</option>
                        <option value="nearby">nearby</option>
                        <option value="stopfinder">stopfinder</option>
                        <option value="departures">departures</option>
                        <option value="trip">trip</option>
                    </select>
                </div>
                <div class="form-group form-group--sm">
                    <label for="hl-status">HTTP-Status</label>
                    <select id="hl-status">
                        <option value="">Alle</option>
                        <option value="200">200 OK</option>
                        <option value="429">429 Rate Limit</option>
                        <option value="500">500 Fehler</option>
                        <option value="503">503 Nicht verfügbar</option>
                    </select>
                </div>
                <div style="align-self:flex-end;padding-bottom:14px">
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

    ['#hl-date-from', '#hl-date-to'].forEach(sel => {
        container.querySelector(sel)
            .addEventListener('keydown', e => { if (e.key === 'Enter') loadAndRender(container); });
    });

    container.addEventListener('click', async e => {
        const btn = e.target.closest('.btn-hl-json');
        if (!btn) return;
        const id = btn.dataset.id;
        btn.style.width = btn.offsetWidth + 'px';
        try {
            const data = await apiFetch(`/admin-api/hafas-cache?id=${id}`);
            await navigator.clipboard.writeText(JSON.stringify(data, null, 2));
            btn.textContent = '✓';
            setTimeout(() => { btn.textContent = 'JSON'; btn.style.width = ''; }, 1500);
        } catch {
            btn.textContent = '✗';
            setTimeout(() => { btn.textContent = 'JSON'; btn.style.width = ''; }, 1500);
        }
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
    if (data.byDay.length === 0 && data.entries.length === 0) {
        return `<div class="section-card">
            <div class="empty-state">Keine Daten für den gewählten Zeitraum.</div>
        </div>`;
    }
    return `
        ${renderByDay(data.byDay)}
        ${renderByEndpoint(data.byEndpoint)}
        ${renderEntries(data.entries)}`;
}

function renderByDay(rows) {
    if (!rows.length) return '';
    const body = rows.map(r => `<tr>
        <td>${escHtml(r.day)}</td>
        <td style="text-align:right">${r.total}</td>
        <td style="text-align:right">${r.realRequests}</td>
        <td style="text-align:right">${r.cacheHits}</td>
        <td style="text-align:right" class="${r.cacheHitPct >= 80 ? 'hl-good' : ''}">${r.cacheHitPct} %</td>
        <td style="text-align:right">${r.avgDurationMs !== null ? r.avgDurationMs + ' ms' : '–'}</td>
        <td style="text-align:right" class="${r.errors > 0 ? 'hl-warn' : ''}">${r.errors}</td>
        <td style="text-align:right" class="${r.rateLimits > 0 ? 'hl-error' : ''}">${r.rateLimits}</td>
        <td style="text-align:right">${r.maxRetryAfter !== null ? r.maxRetryAfter + ' s' : '–'}</td>
    </tr>`).join('');
    return sectionCard('Übersicht pro Tag', `
        <div style="overflow-x:auto">
            <table class="data-table">
                <thead><tr>
                    <th>Tag</th>
                    <th style="text-align:right">Gesamt</th>
                    <th style="text-align:right">Echt</th>
                    <th style="text-align:right">Cache</th>
                    <th style="text-align:right">Cache %</th>
                    <th style="text-align:right">Ø Dauer</th>
                    <th style="text-align:right">Fehler</th>
                    <th style="text-align:right">429</th>
                    <th style="text-align:right">max Retry</th>
                </tr></thead>
                <tbody>${body}</tbody>
            </table>
        </div>`);
}

function renderByEndpoint(rows) {
    if (!rows.length) return '';
    const body = rows.map(r => `<tr>
        <td><code>${escHtml(r.endpoint)}</code></td>
        <td style="text-align:right">${r.total}</td>
        <td style="text-align:right">${r.realRequests}</td>
        <td style="text-align:right">${r.cacheHitPct} %</td>
        <td style="text-align:right">${r.avgDurationMs !== null ? r.avgDurationMs + ' ms' : '–'}</td>
    </tr>`).join('');
    return sectionCard('Aufrufe nach Endpoint', `
        <div style="overflow-x:auto">
            <table class="data-table">
                <thead><tr>
                    <th>Endpoint</th>
                    <th style="text-align:right">Gesamt</th>
                    <th style="text-align:right">Echt</th>
                    <th style="text-align:right">Cache %</th>
                    <th style="text-align:right">Ø Dauer</th>
                </tr></thead>
                <tbody>${body}</tbody>
            </table>
        </div>`);
}

function renderEntries(entries) {
    if (!entries.length) return '';
    const note = entries.length === 500
        ? `<p style="font-size:0.82rem;color:var(--color-text-muted);margin-bottom:10px">
               Maximal 500 Einträge — Filter eingrenzen für mehr Details.
           </p>`
        : '';
    const body = entries.map(e => {
        const dt = e.loggedAt
            ? new Date(e.loggedAt).toLocaleString('de-DE', { timeZone: 'Europe/Berlin' })
            : '–';
        return `<tr>
            <td style="white-space:nowrap;font-variant-numeric:tabular-nums">${escHtml(dt)}</td>
            <td><code>${escHtml(e.endpoint)}</code></td>
            <td class="${e.httpStatus >= 400 ? 'hl-warn' : ''}">${e.httpStatus}</td>
            <td style="text-align:right">${e.cacheHit ? '–' : (e.durationMs + ' ms')}</td>
            <td style="text-align:center">${e.cacheHit ? '✓' : ''}</td>
            <td>${e.retryAfter !== null ? e.retryAfter + ' s' : ''}</td>
            <td class="hl-params" title="${escHtml(formatParams(e.params))}">${escHtml(formatParams(e.params))}</td>
            <td>${e.cacheAvailable
                ? `<button class="btn btn-ghost btn-sm btn-hl-json" data-id="${e.id}">JSON</button>`
                : ''}</td>
        </tr>`;
    }).join('');
    return sectionCard(`Einzeleinträge (${entries.length})`, note + `
        <div style="overflow-x:auto">
            <table class="data-table">
                <thead><tr>
                    <th>Zeit</th>
                    <th>Endpoint</th>
                    <th>Status</th>
                    <th style="text-align:right">Dauer</th>
                    <th style="text-align:center">Cache</th>
                    <th>Retry-After</th>
                    <th>Parameter</th>
                    <th></th>
                </tr></thead>
                <tbody>${body}</tbody>
            </table>
        </div>`);
}

function formatParams(params) {
    if (!params) return '';
    return Object.entries(params).map(([k, v]) => `${k}=${v}`).join(', ');
}

function sectionCard(title, content) {
    return `
        <div class="section-card">
            <h3 class="section-title">${escHtml(title)}</h3>
            ${content}
        </div>`;
}
