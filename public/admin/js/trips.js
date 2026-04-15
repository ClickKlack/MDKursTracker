/**
 * trips.js – Admin-Tab: Fahrten (gruppierte Ansicht)
 *
 * Ablauf:
 *  1. Perioden laden → Periode wählen
 *  2. Fahrten der gewählten Periode laden (GET /api/trips)
 *  3. Filter: Linie, Wochentagstyp
 *  4. Trips clientseitig nach (Typ, ZI-Stamm, Start, Ende) gruppieren
 *  5. Accordion: Laufweg × Abfahrtszeiten je Trip
 */

import { apiFetch, escHtml } from './admin.js';

const DAY_LABELS = {
    'MO-FR': 'Mo–Fr',
    'SA':    'Sa',
    'SO':    'So/Feiertag',
    'SF':    'SF',
};

/** Alle geladenen Trips der aktuell gewählten Periode */
let allTrips = [];

export async function renderTrips(container) {
    // Perioden für Selector laden
    let periods;
    try {
        periods = await apiFetch('/api/periods');
    } catch (err) {
        container.innerHTML = `<div class="error-box">${escHtml(err.message)}</div>`;
        return;
    }

    const activePeriod = periods.find(p => p.active) ?? periods.at(-1);

    const periodOptions = periods
        .slice()
        .reverse()
        .map(p => {
            const label = p.active ? `${p.name} (aktuell)` : p.name;
            const sel   = p.id === activePeriod?.id ? ' selected' : '';
            return `<option value="${p.id}"${sel}>${escHtml(label)}</option>`;
        })
        .join('');

    container.innerHTML = `
        <div class="section-card">
            <h2 class="section-title">Fahrten</h2>
            <div class="override-filters">
                <div class="form-group">
                    <label for="tr-period">Periode</label>
                    <select id="tr-period">${periodOptions}</select>
                </div>
                <div class="form-group">
                    <label for="tr-line">Linie</label>
                    <input type="text" id="tr-line" placeholder="alle"
                           maxlength="5" autocomplete="off" inputmode="numeric">
                </div>
                <div class="form-group">
                    <label for="tr-daytype">Wochentagstyp</label>
                    <select id="tr-daytype">
                        <option value="">Alle</option>
                        <option value="MO-FR">Mo–Fr</option>
                        <option value="SA">Samstag</option>
                        <option value="SO">So / Feiertag</option>
                        <option value="SF">Schulferien</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="section-card">
            <div id="tr-table-wrap">
                <div style="text-align:center;padding:24px"><div class="spinner"></div></div>
            </div>
        </div>`;

    await loadTrips(container, activePeriod?.id);
    attachFilters(container);

    const wrap = container.querySelector('#tr-table-wrap');
    attachAccordion(wrap);
}

// --- Trips laden & rendern --------------------------------------------------

async function loadTrips(container, periodId) {
    const wrap = container.querySelector('#tr-table-wrap');
    wrap.innerHTML = `<div style="text-align:center;padding:24px"><div class="spinner"></div></div>`;

    try {
        const params = new URLSearchParams({ period_id: periodId });
        allTrips = await apiFetch(`/api/trips?${params}`);
    } catch (err) {
        wrap.innerHTML = `<div class="error-box">${escHtml(err.message)}</div>`;
        return;
    }

    renderTable(container);
}

/** Bildet Gruppen aus den gefilterten Trips und rendert die Tabelle. */
function renderTable(container) {
    const lineFilter    = container.querySelector('#tr-line')?.value.trim() ?? '';
    const dayTypeFilter = container.querySelector('#tr-daytype')?.value ?? '';

    const trips = allTrips.filter(t => {
        if (lineFilter    && t.line    !== lineFilter)    return false;
        if (dayTypeFilter && t.dayType !== dayTypeFilter) return false;
        return true;
    });

    const wrap = container.querySelector('#tr-table-wrap');

    if (trips.length === 0) {
        wrap.innerHTML = `<div class="empty-state">Keine Fahrten für diese Filter.</div>`;
        return;
    }

    // Gruppen bilden: (dayType, ZI-Stamm, startStop, endStop)
    const groupMap = new Map();
    for (const t of trips) {
        const stem = t.serviceNr.split('_')[0];
        const key  = `${t.dayType}|${stem}|${t.startStopName ?? ''}|${t.endStopName ?? t.direction}`;
        if (!groupMap.has(key)) {
            groupMap.set(key, {
                key,
                dayType:       t.dayType,
                stem,
                startStopName: t.startStopName ?? '–',
                endStopName:   t.endStopName   ?? t.direction,
                tripIds:       [],
            });
        }
        groupMap.get(key).tripIds.push(t.id);
    }

    const groups = [...groupMap.values()];

    wrap.innerHTML = `
        <p class="text-small text-muted" style="margin-bottom:10px">
            ${groups.length} Gruppe${groups.length !== 1 ? 'n' : ''}
            (${trips.length} Fahrt${trips.length !== 1 ? 'en' : ''})
        </p>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Typ</th>
                    <th>Stamm</th>
                    <th>Von</th>
                    <th>Nach</th>
                    <th style="text-align:center">Fahrten</th>
                    <th style="width:2rem"></th>
                </tr>
            </thead>
            <tbody>
                ${groups.map((g, i) => renderGroupRow(g, i)).join('')}
            </tbody>
        </table>`;
}

function renderGroupRow(g, idx) {
    const rowId    = `tr-grp-${idx}`;
    const detailId = `tr-det-${idx}`;
    return `
        <tr id="${rowId}" class="tr-group-row">
            <td>${escHtml(DAY_LABELS[g.dayType] ?? g.dayType)}</td>
            <td class="trip-service-nr">${escHtml(g.stem)}</td>
            <td>${escHtml(g.startStopName)}</td>
            <td>${escHtml(g.endStopName)}</td>
            <td style="text-align:center">${g.tripIds.length}</td>
            <td>
                <button class="btn btn-ghost btn-xs btn-tr-expand"
                        data-idx="${idx}"
                        data-trip-ids="${escHtml(g.tripIds.join(','))}"
                        aria-expanded="false"
                        aria-controls="${detailId}"
                        title="Aufklappen">▶</button>
            </td>
        </tr>
        <tr id="${detailId}" class="tr-detail-row" hidden>
            <td colspan="6" class="ov-recordings-cell">
                <div class="ov-recordings-inner" id="tr-det-inner-${idx}">
                    <div class="spinner" style="margin:8px auto"></div>
                </div>
            </td>
        </tr>`;
}

// --- Accordion --------------------------------------------------------------

function attachAccordion(wrap) {
    wrap.addEventListener('click', async e => {
        const btn = e.target.closest('.btn-tr-expand');
        if (!btn) return;

        const idx      = btn.dataset.idx;
        const tripIds  = btn.dataset.tripIds;
        const detailRow   = document.getElementById(`tr-det-${idx}`);
        const innerDiv    = document.getElementById(`tr-det-inner-${idx}`);
        if (!detailRow || !innerDiv) return;

        const isOpen = btn.getAttribute('aria-expanded') === 'true';

        // Alle anderen Accordion-Zeilen schließen
        wrap.querySelectorAll('.tr-detail-row:not([hidden])').forEach(openRow => {
            openRow.hidden = true;
            const openIdx = openRow.id.replace('tr-det-', '');
            const openBtn = wrap.querySelector(`[aria-controls="tr-det-${openIdx}"]`);
            if (openBtn) {
                openBtn.setAttribute('aria-expanded', 'false');
                openBtn.textContent = '▶';
            }
        });

        if (isOpen) return; // War offen → nur schließen

        detailRow.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        btn.textContent = '▼';
        innerDiv.innerHTML = `<div class="spinner" style="margin:8px auto"></div>`;

        let detail;
        try {
            detail = await apiFetch(`/admin-api/trip-group-detail?trip_ids=${encodeURIComponent(tripIds)}`);
        } catch (err) {
            innerDiv.innerHTML = `<p class="error-box" style="margin:8px">${escHtml(err.message)}</p>`;
            return;
        }

        renderDetail(innerDiv, detail);
    });
}

/**
 * Rendert die Matrix-Tabelle im aufgeklappten Accordion.
 *
 * Struktur:
 *   stops[]  – { stopId, stopName, sequence }
 *   trips[]  – { id, serviceNr, activeCourseNumber, manualCourseNumber,
 *                departures: { stopId: "HH:MM" } }
 */
function renderDetail(innerDiv, detail) {
    if (!detail || detail.trips.length === 0) {
        innerDiv.innerHTML = `<p style="padding:8px;color:var(--color-text-muted);font-size:0.82rem">Keine Fahrtdaten vorhanden.</p>`;
        return;
    }

    const { stops, trips } = detail;

    // Kopfzeile: Kursnummer + HAFAS-Nr je Trip
    const headerCols = trips.map(t => {
        const isManual = t.manualCourseNumber !== null;
        const badge    = t.activeCourseNumber
            ? `<span class="course-badge ${isManual ? 'is-manual' : ''}"
                      title="${isManual ? 'Manuell übersteuert' : 'Per Mehrheitsregel'}"
                  >${escHtml(t.activeCourseNumber)}</span>`
            : `<span style="color:var(--color-text-muted)">–</span>`;
        return `<th style="text-align:center;min-width:80px">
                    ${badge}
                    <br><span class="trip-service-nr" style="font-size:0.72rem">${escHtml(t.serviceNr)}</span>
                </th>`;
    }).join('');

    // Datenzeilen: je Haltestelle eine Zeile
    const dataRows = stops.map(stop => {
        const timeCols = trips.map(t => {
            const time = t.departures[stop.stopId];
            return `<td style="text-align:center;font-variant-numeric:tabular-nums">
                        ${time ? escHtml(time) : '<span style="color:var(--color-text-muted)">–</span>'}
                    </td>`;
        }).join('');
        return `<tr>
                    <td class="text-small">${escHtml(stop.stopName)}</td>
                    ${timeCols}
                </tr>`;
    }).join('');

    innerDiv.innerHTML = `
        <div style="overflow-x:auto;padding:4px 0 8px">
            <table class="data-table ov-rec-table tr-detail-table">
                <thead>
                    <tr>
                        <th>Haltestelle</th>
                        ${headerCols}
                    </tr>
                </thead>
                <tbody>
                    ${dataRows}
                </tbody>
            </table>
        </div>`;
}

// --- Event-Listener ---------------------------------------------------------

function attachFilters(container) {
    let debounce = null;
    const reload = () => {
        if (debounce) clearTimeout(debounce);
        debounce = setTimeout(() => renderTable(container), 250);
    };

    container.querySelector('#tr-period').addEventListener('change', async e => {
        const periodId = parseInt(e.target.value, 10);
        await loadTrips(container, periodId);
    });

    container.querySelector('#tr-line').addEventListener('input',    reload);
    container.querySelector('#tr-daytype').addEventListener('change', reload);
}
