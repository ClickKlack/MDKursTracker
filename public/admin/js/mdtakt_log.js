/**
 * mdtakt_log.js – Admin-Tab: Aufruf-Protokoll der MD-Takt-API
 *
 * Jeder Aufruf an MD-Takt landet mit Kennzahlen, Request und Response in
 * mdtakt_log – Sichtungen (cron/mdtakt_sync.php) ebenso wie Kursauskünfte.
 * Einträge werden nach mdtakt_log_days (Standard 30) automatisch gelöscht.
 */

import { apiFetch, escHtml } from './admin.js';

// Bekannte Endpunkte (Pfad unter /api/v1/) mit Anzeigenamen
const ENDPOINTS = {
    'collector/sightings':     'Sichtungen',
    'collector/course-lookup': 'Kursauskunft',
};

// Anzeigenamen der endpunktspezifischen stats-Zähler; unbekannte Schlüssel
// erscheinen unter ihrem Namen.
const STAT_LABELS = {
    trips:              'Laufwege',
    waiting:            'waiting',
    unknownFingerprint: 'Laufweg unbek.',
};

// Zähler, die auf ein Problem hinweisen (> 0 wird hervorgehoben)
const STAT_WARN = new Set(['unknownFingerprint']);

export async function renderMdtaktLog(container) {
    const today = new Date().toISOString().slice(0, 10);
    container.innerHTML = buildShell(today);
    attachListeners(container);
    await loadAndRender(container);
}

// --- Shell -------------------------------------------------------------------

function buildShell(today) {
    return `
        <div class="section-card">
            <h2 class="section-title">MD-Takt-Log</h2>
            <div class="override-filters">
                <div class="form-group form-group--sm">
                    <label for="ml-date-from">Von</label>
                    <input type="date" id="ml-date-from" value="${today}">
                </div>
                <div class="form-group form-group--sm">
                    <label for="ml-date-to">Bis</label>
                    <input type="date" id="ml-date-to" value="${today}">
                </div>
                <div class="form-group form-group--sm">
                    <label for="ml-endpoint">Endpoint</label>
                    <select id="ml-endpoint">
                        <option value="">Alle</option>
                        ${Object.entries(ENDPOINTS).map(([v, l]) =>
                            `<option value="${escHtml(v)}">${escHtml(l)}</option>`).join('')}
                    </select>
                </div>
                <div class="form-group form-group--sm">
                    <label for="ml-errors-only">Status</label>
                    <select id="ml-errors-only">
                        <option value="">Alle</option>
                        <option value="1">Nur Fehler</option>
                    </select>
                </div>
                <div style="align-self:flex-end;padding-bottom:14px">
                    <button class="btn btn-primary" id="ml-btn-load">Laden</button>
                </div>
            </div>
        </div>
        <div id="ml-result"></div>`;
}

// --- Listener ----------------------------------------------------------------

function attachListeners(container) {
    container.querySelector('#ml-btn-load')
        .addEventListener('click', () => loadAndRender(container));

    ['#ml-date-from', '#ml-date-to'].forEach(sel => {
        container.querySelector(sel)
            .addEventListener('keydown', e => { if (e.key === 'Enter') loadAndRender(container); });
    });

    container.addEventListener('click', async e => {
        const detailBtn = e.target.closest('.btn-ml-detail');
        if (detailBtn) {
            await toggleDetail(detailBtn);
            return;
        }
        const copyBtn = e.target.closest('.btn-ml-copy');
        if (copyBtn) {
            await copyBody(copyBtn);
        }
    });
}

// --- Laden -------------------------------------------------------------------

async function loadAndRender(container) {
    const result = container.querySelector('#ml-result');
    result.innerHTML = `<div style="padding:24px;text-align:center">
        <div class="spinner" aria-hidden="true"></div></div>`;

    const params = new URLSearchParams({
        date_from: container.querySelector('#ml-date-from').value,
        date_to:   container.querySelector('#ml-date-to').value,
    });
    const endpoint   = container.querySelector('#ml-endpoint').value;
    const errorsOnly = container.querySelector('#ml-errors-only').value;
    if (endpoint)   params.set('endpoint', endpoint);
    if (errorsOnly) params.set('errors_only', errorsOnly);

    let data;
    try {
        data = await apiFetch(`/admin-api/mdtakt-log?${params}`);
    } catch (err) {
        result.innerHTML = `<div class="error-box">${escHtml(err.message)}</div>`;
        return;
    }

    result.innerHTML = renderAll(data);
}

// --- Detail (Request/Response) -----------------------------------------------

// Zuletzt geladene Details je Log-ID – für die Kopier-Buttons
const detailCache = new Map();

async function toggleDetail(btn) {
    const id  = btn.dataset.id;
    const row = btn.closest('tr');
    const next = row.nextElementSibling;

    if (next && next.classList.contains('ml-detail-row')) {
        next.remove();
        btn.textContent = 'Details';
        return;
    }

    btn.disabled = true;
    let detail;
    try {
        detail = detailCache.get(id) ?? await apiFetch(`/admin-api/mdtakt-log/${id}`);
        detailCache.set(id, detail);
    } catch (err) {
        btn.disabled = false;
        btn.textContent = '✗';
        btn.title = err.message;
        return;
    }
    btn.disabled = false;
    btn.textContent = 'Schließen';

    const cols = row.children.length;
    row.insertAdjacentHTML('afterend', `
        <tr class="ml-detail-row">
            <td colspan="${cols}">
                ${detailBlock('Response', detail.response, id, 'response')}
                ${detailBlock('Request', detail.request, id, 'request')}
            </td>
        </tr>`);
}

function detailBlock(title, body, id, kind) {
    const text = body === null ? '–' : formatBody(body);
    return `
        <div class="ml-detail">
            <div class="ml-detail-head">
                <strong>${escHtml(title)}</strong>
                ${body !== null
                    ? `<button class="btn btn-ghost btn-sm btn-ml-copy" data-id="${id}" data-kind="${kind}">Kopieren</button>`
                    : ''}
            </div>
            <pre class="ml-json">${escHtml(text)}</pre>
        </div>`;
}

function formatBody(body) {
    return typeof body === 'string' ? body : JSON.stringify(body, null, 2);
}

async function copyBody(btn) {
    const detail = detailCache.get(btn.dataset.id);
    if (!detail) return;
    btn.style.width = btn.offsetWidth + 'px';
    try {
        await navigator.clipboard.writeText(formatBody(detail[btn.dataset.kind]));
        btn.textContent = '✓';
    } catch {
        btn.textContent = '✗';
    }
    setTimeout(() => { btn.textContent = 'Kopieren'; btn.style.width = ''; }, 1500);
}

// --- Render ------------------------------------------------------------------

function renderAll(data) {
    if (data.byDay.length === 0 && data.entries.length === 0) {
        return `<div class="section-card">
            <div class="empty-state">Keine Aufrufe im gewählten Zeitraum.</div>
        </div>`;
    }
    return `
        ${renderByDay(data.byDay)}
        ${renderEntries(data.entries)}`;
}

function renderByDay(rows) {
    if (!rows.length) return '';
    const body = rows.map(r => `<tr>
        <td>${escHtml(r.day)}</td>
        <td>${endpointLabel(r.endpoint)}</td>
        <td style="text-align:right">${r.calls}</td>
        <td style="text-align:right" class="${r.errors > 0 ? 'hl-warn' : ''}">${r.errors}</td>
        <td style="text-align:right">${r.itemsSent}</td>
        <td style="text-align:right">${r.itemsOk}</td>
        <td>${formatStats(r.stats)}</td>
        <td style="text-align:right">${r.avgDurationMs} ms</td>
    </tr>`).join('');
    return sectionCard('Übersicht pro Tag', `
        <div style="overflow-x:auto">
            <table class="data-table">
                <thead><tr>
                    <th>Tag</th>
                    <th>Endpoint</th>
                    <th style="text-align:right">Aufrufe</th>
                    <th style="text-align:right">Fehler</th>
                    <th style="text-align:right">Gesendet</th>
                    <th style="text-align:right">OK</th>
                    <th>Details</th>
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
        const ok = e.httpStatus >= 200 && e.httpStatus < 300;
        return `<tr>
            <td style="white-space:nowrap;font-variant-numeric:tabular-nums">${escHtml(dt)}</td>
            <td style="white-space:nowrap"><code>${escHtml(e.method)}</code> ${endpointLabel(e.endpoint)}</td>
            <td class="hl-params" title="${escHtml(e.context ?? '')}">${escHtml(e.context ?? '')}</td>
            <td class="${ok ? '' : 'hl-error'}">${e.cacheHit ? 'Cache' : (e.httpStatus === 0 ? 'Netz' : e.httpStatus)}</td>
            <td style="text-align:right">${e.cacheHit ? '–' : e.durationMs + ' ms'}</td>
            <td style="text-align:right">${e.itemsSent}</td>
            <td style="text-align:right">${e.itemsOk ?? '–'}</td>
            <td>${formatStats(e.stats)}</td>
            <td class="hl-params" title="${escHtml(e.error ?? '')}">${escHtml(e.error ?? '')}</td>
            <td><button class="btn btn-ghost btn-sm btn-ml-detail" data-id="${e.id}">Details</button></td>
        </tr>`;
    }).join('');
    return sectionCard(`Aufrufe (${entries.length})`, note + `
        <div style="overflow-x:auto">
            <table class="data-table">
                <thead><tr>
                    <th>Zeit</th>
                    <th>Aufruf</th>
                    <th>Kontext</th>
                    <th>Status</th>
                    <th style="text-align:right">Dauer</th>
                    <th style="text-align:right">Gesendet</th>
                    <th style="text-align:right">OK</th>
                    <th>Details</th>
                    <th>Fehler</th>
                    <th></th>
                </tr></thead>
                <tbody>${body}</tbody>
            </table>
        </div>`);
}

function endpointLabel(endpoint) {
    const label = ENDPOINTS[endpoint];
    return label
        ? `<span title="${escHtml(endpoint)}">${escHtml(label)}</span>`
        : `<code>${escHtml(endpoint)}</code>`;
}

/** stats-Objekt als "Laufwege 3 · waiting 1"; null-Werte entfallen. */
function formatStats(stats) {
    if (!stats) return '';
    return Object.entries(stats)
        .filter(([, v]) => v !== null)
        .map(([k, v]) => {
            const text = `${escHtml(STAT_LABELS[k] ?? k)} ${escHtml(String(v))}`;
            return STAT_WARN.has(k) && v > 0 ? `<span class="hl-warn">${text}</span>` : text;
        })
        .join(' · ');
}

function sectionCard(title, content) {
    return `
        <div class="section-card">
            <h3 class="section-title">${escHtml(title)}</h3>
            ${content}
        </div>`;
}
