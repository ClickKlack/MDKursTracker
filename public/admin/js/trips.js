/**
 * trips.js – Admin-Tab: Fahrten (gruppierte Ansicht)
 *
 * Ablauf:
 *  1. Perioden laden → Periode wählen
 *  2. Fahrten der gewählten Periode laden (GET /api/trips)
 *  3. Filter: Linie, Wochentagstyp
 *  4. Trips clientseitig nach (Typ, path_fingerprint, Start, Ende) gruppieren
 *  5. Accordion: Laufweg × Abfahrtszeiten je Trip
 */

import { apiFetch, escHtml, getStopNamePrefix, stripStopName } from './admin.js';

const DAY_LABELS = {
    'MO-FR': 'Mo–Fr',
    'SA':    'Sa',
    'SO':    'So/Feiertag',
    'SF':    'SF',
};

const DAY_ORDER = { 'MO-FR': 0, 'SA': 1, 'SO': 2, 'SF': 3 };

/** Alle geladenen Trips der aktuell gewählten Periode */
let allTrips  = [];
let stopPrefix = '';

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
                <div class="form-group form-group--sm">
                    <label for="tr-daytype">Wochentagstyp</label>
                    <select id="tr-daytype">
                        <option value="">Alle</option>
                        <option value="MO-FR">Mo–Fr</option>
                        <option value="SA">Samstag</option>
                        <option value="SO">So / Feiertag</option>
                        <option value="SF">Schulferien</option>
                    </select>
                </div>
                <div class="form-group form-group--xs">
                    <label for="tr-line">Linie</label>
                    <input type="text" id="tr-line" placeholder="alle"
                           maxlength="5" autocomplete="off" inputmode="numeric">
                </div>
                <div class="form-group">
                    <label for="tr-von">Von</label>
                    <input type="text" id="tr-von" placeholder="alle"
                           list="tr-von-list" autocomplete="off">
                    <datalist id="tr-von-list"></datalist>
                </div>
                <div class="form-group">
                    <label for="tr-nach">Nach</label>
                    <input type="text" id="tr-nach" placeholder="alle"
                           list="tr-nach-list" autocomplete="off">
                    <datalist id="tr-nach-list"></datalist>
                </div>
            </div>
        </div>

        <div class="section-card">
            <div id="tr-table-wrap">
                <div style="text-align:center;padding:24px"><div class="spinner"></div></div>
            </div>
        </div>`;

    stopPrefix = await getStopNamePrefix();
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

    updateDatalist(container);
    renderTable(container);
}

/** Füllt die Datalist-Elemente mit eindeutigen Haltestellennamen (Präfix entfernt). */
function updateDatalist(container) {
    const strip = n => stripStopName(n, stopPrefix);
    const vonNames  = [...new Set(allTrips.map(t => t.startStopName).filter(Boolean).map(strip))].sort();
    const nachNames = [...new Set(allTrips.map(t => t.endStopName ?? t.direction).filter(Boolean).map(strip))].sort();
    const vonList  = container.querySelector('#tr-von-list');
    const nachList = container.querySelector('#tr-nach-list');
    if (vonList)  vonList.innerHTML  = vonNames.map(n  => `<option value="${escHtml(n)}">`).join('');
    if (nachList) nachList.innerHTML = nachNames.map(n => `<option value="${escHtml(n)}">`).join('');
}

/** Bildet Gruppen aus den gefilterten Trips und rendert die Tabelle. */
function renderTable(container) {
    const lineFilter    = container.querySelector('#tr-line')?.value.trim() ?? '';
    const dayTypeFilter = container.querySelector('#tr-daytype')?.value ?? '';
    const vonFilter     = container.querySelector('#tr-von')?.value.trim().toLowerCase() ?? '';
    const nachFilter    = container.querySelector('#tr-nach')?.value.trim().toLowerCase() ?? '';

    const trips = allTrips.filter(t => {
        if (lineFilter    && t.line    !== lineFilter)    return false;
        if (dayTypeFilter && t.dayType !== dayTypeFilter) return false;
        if (vonFilter  && !stripStopName(t.startStopName ?? '', stopPrefix).toLowerCase().includes(vonFilter))                      return false;
        if (nachFilter && !stripStopName(t.endStopName ?? t.direction ?? '', stopPrefix).toLowerCase().includes(nachFilter)) return false;
        return true;
    });

    const wrap = container.querySelector('#tr-table-wrap');

    if (trips.length === 0) {
        wrap.innerHTML = `<div class="empty-state">Keine Fahrten für diese Filter.</div>`;
        return;
    }

    // Gruppen bilden: (line, dayType, path_fingerprint, startStop, endStop)
    const groupMap = new Map();
    for (const t of trips) {
        const key = `${t.line}|${t.dayType}|${t.pathFingerprint ?? ''}|${t.startStopName ?? ''}|${t.endStopName ?? t.direction}`;
        if (!groupMap.has(key)) {
            groupMap.set(key, {
                key,
                line:          t.line,
                dayType:       t.dayType,
                startStopName: t.startStopName ?? '–',
                endStopName:   t.endStopName   ?? t.direction,
                tripIds:       [],
            });
        }
        groupMap.get(key).tripIds.push(t.id);
    }

    const groups = [...groupMap.values()];

    const toHHMM = s => { const m = (s ?? '').match(/(\d{2}:\d{2})/); return m ? m[1] : ''; };

    // Fahrten je Gruppe aufsteigend nach Startzeit sortieren; danach
    // min. Abfahrtszeit pro Gruppe für die Gruppensortierung merken.
    const startById = new Map(trips.map(t => [t.id, t.startDeparture ?? '']));
    const endById   = new Map(trips.map(t => [t.id, t.endDeparture   ?? '']));
    for (const g of groups) {
        g.tripIds.sort((a, b) => {
            const ta = toHHMM(startById.get(a));
            const tb = toHHMM(startById.get(b));
            return ta < tb ? -1 : ta > tb ? 1 : 0;
        });
        g.minStart = toHHMM(startById.get(g.tripIds[0]) ?? '');
        const ends = g.tripIds.map(id => toHHMM(endById.get(id) ?? '')).filter(Boolean).sort();
        g.minEnd = ends[0] ?? '';
    }

    // Gruppen sortieren: Linie numerisch → Typ → Von → Von-Uhrzeit → Nach → Nach-Uhrzeit
    groups.sort((a, b) => {
        const la = parseInt(a.line, 10) || 0;
        const lb = parseInt(b.line, 10) || 0;
        if (la !== lb) return la - lb;
        const da = DAY_ORDER[a.dayType] ?? 99;
        const db = DAY_ORDER[b.dayType] ?? 99;
        if (da !== db) return da - db;
        const va = (a.startStopName ?? '').localeCompare(b.startStopName ?? '', 'de');
        if (va !== 0) return va;
        if (a.minStart !== b.minStart) return a.minStart < b.minStart ? -1 : 1;
        const na = (a.endStopName ?? '').localeCompare(b.endStopName ?? '', 'de');
        if (na !== 0) return na;
        return a.minEnd < b.minEnd ? -1 : a.minEnd > b.minEnd ? 1 : 0;
    });

    wrap.innerHTML = `
        <p class="text-small text-muted" style="margin-bottom:10px">
            ${groups.length} Gruppe${groups.length !== 1 ? 'n' : ''}
            (${trips.length} Fahrt${trips.length !== 1 ? 'en' : ''})
        </p>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Linie</th>
                    <th>Typ</th>
                    <th>Von</th>
                    <th>Nach</th>
                    <th style="text-align:center">Fahrten</th>
                    <th style="width:2.5rem"></th>
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
    const von  = escHtml(stripStopName(g.startStopName, stopPrefix));
    const nach = escHtml(stripStopName(g.endStopName,   stopPrefix));
    return `
        <tr id="${rowId}" class="tr-group-row">
            <td><strong>${escHtml(g.line)}</strong></td>
            <td>${escHtml(DAY_LABELS[g.dayType] ?? g.dayType)}</td>
            <td style="white-space:nowrap">${von}</td>
            <td style="white-space:nowrap">${nach}</td>
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

        // Breite vor dem Einblenden setzen, damit die äußere Tabelle nicht
        // durch den Accordion-Inhalt aufgeweitet wird.
        innerDiv.style.width = wrap.clientWidth + 'px';
        innerDiv.innerHTML = `<div class="spinner" style="margin:8px auto"></div>`;

        detailRow.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        btn.textContent = '▼';

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
                    <td>${escHtml(stripStopName(stop.stopName, stopPrefix))}</td>
                    ${timeCols}
                </tr>`;
    }).join('');

    innerDiv.innerHTML = `
        <div class="tr-detail-wrap">
            <table class="data-table tr-detail-table">
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

    container.querySelector('#tr-daytype').addEventListener('change', reload);
    container.querySelector('#tr-line').addEventListener('input',    reload);
    container.querySelector('#tr-von').addEventListener('input',     reload);
    container.querySelector('#tr-nach').addEventListener('input',    reload);
}
