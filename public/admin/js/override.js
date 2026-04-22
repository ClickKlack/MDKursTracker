/**
 * override.js – Admin-Tab: Manuelle Kursnummer-Übersteuerung
 *
 * Ablauf:
 *  1. Perioden laden → Periode wählen
 *  2. Fahrten der gewählten Periode laden (GET /api/trips)
 *  3. Filter: Linie, Wochentagstyp
 *  4. Je Fahrt: aktuelle Kursnummer anzeigen, override setzen oder löschen
 */

import { apiFetch, escHtml, showMessage, getStopNamePrefix, stripStopName } from './admin.js';

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

export async function renderOverride(container) {
    // Perioden für Selector laden
    let periods;
    try {
        periods = await apiFetch('/api/periods');
    } catch (err) {
        container.innerHTML = `<div class="error-box">${escHtml(err.message)}</div>`;
        return;
    }

    const activePeriod = periods.find(p => p.active) ?? periods.at(-1);

    // Perioden-Optionen: neueste zuerst
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
            <h2 class="section-title">Erfassungen</h2>
            <div class="override-filters">
                <div class="form-group">
                    <label for="ov-period">Periode</label>
                    <select id="ov-period">${periodOptions}</select>
                </div>
                <div class="form-group form-group--sm">
                    <label for="ov-daytype">Wochentagstyp</label>
                    <select id="ov-daytype">
                        <option value="">Alle</option>
                        <option value="MO-FR">Mo–Fr</option>
                        <option value="SA">Samstag</option>
                        <option value="SO">So / Feiertag</option>
                        <option value="SF">Schulferien</option>
                    </select>
                </div>
                <div class="form-group form-group--xs">
                    <label for="ov-line">Linie</label>
                    <input type="text" id="ov-line" placeholder="alle"
                           maxlength="5" autocomplete="off" inputmode="numeric">
                </div>
                <div class="form-group">
                    <label for="ov-von">Von</label>
                    <input type="text" id="ov-von" placeholder="alle"
                           list="ov-von-list" autocomplete="off">
                    <datalist id="ov-von-list"></datalist>
                </div>
                <div class="form-group">
                    <label for="ov-nach">Nach</label>
                    <input type="text" id="ov-nach" placeholder="alle"
                           list="ov-nach-list" autocomplete="off">
                    <datalist id="ov-nach-list"></datalist>
                </div>
            </div>
            <div id="ov-msg"></div>
        </div>

        <div class="section-card">
            <div id="ov-table-wrap">
                <div style="text-align:center;padding:24px"><div class="spinner"></div></div>
            </div>
        </div>`;

    stopPrefix = await getStopNamePrefix();
    await loadTrips(container, activePeriod?.id);
    attachFilters(container);

    // Row-Actions nur einmal am stabilen #ov-table-wrap registrieren.
    // renderTable ersetzt nur dessen innerHTML, nicht das Element selbst –
    // dadurch bleibt der delegierte Listener bei jedem Filter-Neurender erhalten.
    const wrap = container.querySelector('#ov-table-wrap');
    attachRowActions(container, wrap);
}

// --- Trips laden & rendern --------------------------------------------------

async function loadTrips(container, periodId) {
    const wrap = container.querySelector('#ov-table-wrap');
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

function updateDatalist(container) {
    const strip = n => stripStopName(n, stopPrefix);
    const vonNames  = [...new Set(allTrips.map(t => t.startStopName).filter(Boolean).map(strip))].sort();
    const nachNames = [...new Set(allTrips.map(t => t.endStopName ?? t.direction).filter(Boolean).map(strip))].sort();
    const vonList  = container.querySelector('#ov-von-list');
    const nachList = container.querySelector('#ov-nach-list');
    if (vonList)  vonList.innerHTML  = vonNames.map(n  => `<option value="${escHtml(n)}">`).join('');
    if (nachList) nachList.innerHTML = nachNames.map(n => `<option value="${escHtml(n)}">`).join('');
}

function renderTable(container) {
    const lineFilter    = container.querySelector('#ov-line')?.value.trim() ?? '';
    const dayTypeFilter = container.querySelector('#ov-daytype')?.value ?? '';
    const vonFilter     = container.querySelector('#ov-von')?.value.trim().toLowerCase() ?? '';
    const nachFilter    = container.querySelector('#ov-nach')?.value.trim().toLowerCase() ?? '';

    const trips = allTrips.filter(t => {
        if (lineFilter    && t.line    !== lineFilter)    return false;
        if (dayTypeFilter && t.dayType !== dayTypeFilter) return false;
        if (vonFilter  && !stripStopName(t.startStopName ?? '', stopPrefix).toLowerCase().includes(vonFilter))                      return false;
        if (nachFilter && !stripStopName(t.endStopName ?? t.direction ?? '', stopPrefix).toLowerCase().includes(nachFilter)) return false;
        return true;
    });

    const toHHMM = s => { const m = (s ?? '').match(/(\d{2}:\d{2})/); return m ? m[1] : ''; };

    trips.sort((a, b) => {
        const la = parseInt(a.line, 10) || 0;
        const lb = parseInt(b.line, 10) || 0;
        if (la !== lb) return la - lb;
        const da = DAY_ORDER[a.dayType] ?? 99;
        const db = DAY_ORDER[b.dayType] ?? 99;
        if (da !== db) return da - db;
        const va = (a.startStopName ?? '').localeCompare(b.startStopName ?? '', 'de');
        if (va !== 0) return va;
        const sta = toHHMM(a.startDeparture);
        const stb = toHHMM(b.startDeparture);
        if (sta !== stb) return sta < stb ? -1 : 1;
        const na = (a.endStopName ?? '').localeCompare(b.endStopName ?? '', 'de');
        if (na !== 0) return na;
        const eta = toHHMM(a.endDeparture);
        const etb = toHHMM(b.endDeparture);
        return eta < etb ? -1 : eta > etb ? 1 : 0;
    });

    const wrap = container.querySelector('#ov-table-wrap');

    if (trips.length === 0) {
        wrap.innerHTML = `<div class="empty-state">Keine Fahrten für diese Filter.</div>`;
        return;
    }

    wrap.innerHTML = `
        <p class="text-small text-muted" style="margin-bottom:10px">
            ${trips.length} Fahrt${trips.length !== 1 ? 'en' : ''}
        </p>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Linie</th>
                    <th>Typ</th>
                    <th>Von</th>
                    <th>Nach</th>
                    <th>Erfassungen</th>
                    <th>Aktiv</th>
                    <th>Übersteuern</th>
                </tr>
            </thead>
            <tbody>
                ${trips.map(t => renderTripRow(t)).join('')}
            </tbody>
        </table>`;
}

function renderTripRow(t) {
    const isManual   = t.manualCourseNumber !== null;
    const courseBadge = t.activeCourseNumber
        ? `<span class="course-badge ${isManual ? 'is-manual' : ''}"
                  title="${isManual ? 'Manuell übersteuert' : 'Per Mehrheitsregel'}"
              >${escHtml(t.activeCourseNumber)}</span>`
        : `<span style="color:var(--color-text-muted);font-size:0.82rem">–</span>`;

    const resetBtn = isManual
        ? `<button class="btn btn-ghost btn-sm btn-ov-reset" data-id="${t.id}"
                   title="Manuelle Übersteuerung zurücksetzen">
               Zurücksetzen
           </button>`
        : '';

    // Erfassungen-Link als Accordion-Button
    const recCount    = t.recordingCount ?? 0;
    const recLabel    = `${recCount} Erfassung${recCount !== 1 ? 'en' : ''}`;
    const recBtnHtml  = recCount > 0
        ? `<button class="btn btn-ghost btn-xs btn-show-recordings"
                   data-id="${t.id}"
                   aria-expanded="false"
                   aria-controls="ov-rec-${t.id}">
               ${escHtml(recLabel)}
           </button>`
        : `<span style="color:var(--color-text-muted);font-size:0.82rem">0 Erfassungen</span>`;

    return `
        <tr id="ov-row-${t.id}">
            <td>
                <strong>${escHtml(t.line)}</strong>
                <br><span class="trip-service-nr" title="Letzte bekannte Service-Nr.">${escHtml(t.serviceNr)}</span>
            </td>
            <td>${escHtml(DAY_LABELS[t.dayType] ?? t.dayType)}</td>
            <td class="trip-origin">
                ${t.startStopName ? escHtml(stripStopName(t.startStopName, stopPrefix)) : '–'}
                ${t.startDeparture ? `<br><span class="trip-time">${escHtml(t.startDeparture)}</span>` : ''}
            </td>
            <td class="trip-destination">
                ${escHtml(stripStopName(t.endStopName ?? t.direction, stopPrefix))}
                ${t.endDeparture ? `<br><span class="trip-time">${escHtml(t.endDeparture)}</span>` : ''}
            </td>
            <td style="text-align:center">${recBtnHtml}</td>
            <td>${courseBadge}</td>
            <td>
                <div style="display:flex;gap:6px;align-items:center">
                    <input type="text" class="override-input" id="ov-input-${t.id}"
                           value="${isManual ? escHtml(t.manualCourseNumber) : ''}"
                           maxlength="2" placeholder="01–99"
                           inputmode="numeric" autocomplete="off"
                           aria-label="Kursnummer für ${escHtml(t.line)} ${escHtml(t.direction)}">
                    <button class="btn btn-primary btn-sm btn-ov-set" data-id="${t.id}">
                        Setzen
                    </button>
                    ${resetBtn}
                </div>
            </td>
        </tr>
        <tr id="ov-rec-${t.id}" class="ov-recordings-row" hidden>
            <td colspan="7" class="ov-recordings-cell">
                <div class="ov-recordings-inner" id="ov-rec-inner-${t.id}">
                    <div class="spinner" style="margin:8px auto"></div>
                </div>
            </td>
        </tr>`;
}

// --- Event-Listener ---------------------------------------------------------

function attachFilters(container) {
    let debounce = null;
    const reload = () => {
        if (debounce) clearTimeout(debounce);
        debounce = setTimeout(() => renderTable(container), 250);
    };

    container.querySelector('#ov-period').addEventListener('change', async e => {
        const periodId = parseInt(e.target.value, 10);
        await loadTrips(container, periodId);
    });

    container.querySelector('#ov-daytype').addEventListener('change', reload);
    container.querySelector('#ov-line').addEventListener('input',    reload);
    container.querySelector('#ov-von').addEventListener('input',     reload);
    container.querySelector('#ov-nach').addEventListener('input',    reload);
}

function attachRowActions(container, wrap) {
    const msgEl = container.querySelector('#ov-msg');

    wrap.addEventListener('click', async e => {
        const btn = e.target.closest('button');
        if (!btn) return;

        const id = parseInt(btn.dataset.id, 10);

        if (btn.classList.contains('btn-ov-set')) {
            await setOverride(container, id, msgEl);
        } else if (btn.classList.contains('btn-ov-reset')) {
            await resetOverride(container, id, msgEl);
        } else if (btn.classList.contains('btn-show-recordings')) {
            await toggleRecordings(id, btn);
        }
    });

    // Enter im Input → Setzen-Button auslösen
    wrap.addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        const input = e.target.closest('.override-input');
        if (!input) return;
        const id = input.id.replace('ov-input-', '');
        wrap.querySelector(`#ov-row-${id} .btn-ov-set`)?.click();
    });
}

// --- Einzelerfassungen Accordion -------------------------------------------

async function toggleRecordings(tripId, btn) {
    const recRow   = document.getElementById(`ov-rec-${tripId}`);
    const innerDiv = document.getElementById(`ov-rec-inner-${tripId}`);
    if (!recRow || !innerDiv) return;

    const isOpen = btn.getAttribute('aria-expanded') === 'true';
    if (isOpen) {
        recRow.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        return;
    }

    // Alle anderen offenen Accordion-Zeilen schließen
    document.querySelectorAll('.ov-recordings-row:not([hidden])').forEach(openRow => {
        openRow.hidden = true;
        const openId  = openRow.id.replace('ov-rec-', '');
        const openBtn = document.querySelector(`[aria-controls="ov-rec-${openId}"]`);
        openBtn?.setAttribute('aria-expanded', 'false');
    });

    const ovWrap = document.getElementById('ov-table-wrap');
    if (ovWrap) innerDiv.style.width = ovWrap.clientWidth + 'px';
    innerDiv.innerHTML = `<div class="spinner" style="margin:8px auto"></div>`;

    recRow.hidden = false;
    btn.setAttribute('aria-expanded', 'true');

    let recs;
    try {
        recs = await apiFetch(`/admin-api/trips/${tripId}/recordings`);
    } catch (err) {
        innerDiv.innerHTML = `<p class="error-box" style="margin:8px">${escHtml(err.message)}</p>`;
        return;
    }

    if (!recs || recs.length === 0) {
        innerDiv.innerHTML = `<p style="padding:8px;color:var(--color-text-muted);font-size:0.82rem">Keine Erfassungen.</p>`;
        return;
    }

    const rows = recs.map(r => {
        const dt       = r.recordedAt ? new Date(r.recordedAt).toLocaleString('de-DE', { timeZone: 'Europe/Berlin' }) : '–';
        const userLine = r.userName
            ? `${escHtml(r.userName)} <span class="ov-user-id">${escHtml(r.userDisplayId ?? '')}</span>`
            : (r.userDisplayId ? `<span class="ov-user-id">${escHtml(r.userDisplayId)}</span>` : '–');
        const deviceLine = r.userDevice
            ? `<br><span class="ov-user-device text-small text-muted">${escHtml(r.userDevice)}</span>`
            : '';
        const commentHtml = r.comment
            ? `<br><span class="text-small text-muted">💬 ${escHtml(r.comment)}</span>`
            : '';
        return `
            <tr>
                <td class="text-small">${escHtml(dt)}</td>
                <td class="text-small">${escHtml(r.stopName ?? r.stopId)}</td>
                <td><strong>${escHtml(r.courseNumber)}</strong>${commentHtml}</td>
                <td class="text-small">${userLine}${deviceLine}</td>
            </tr>`;
    }).join('');

    innerDiv.innerHTML = `
        <table class="data-table ov-rec-table">
            <thead>
                <tr>
                    <th>Zeitpunkt</th>
                    <th>Haltestelle</th>
                    <th>Kurs</th>
                    <th>Nutzer</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>`;
}

async function setOverride(container, tripId, msgEl) {
    const input = document.querySelector(`#ov-input-${tripId}`);
    const val   = (input?.value ?? '').trim();

    // Validierung: zweistellig 01–99
    if (!/^(0[1-9]|[1-9][0-9])$/.test(val)) {
        showMessage(msgEl, 'Kursnummer muss zweistellig im Format 01–99 sein.', 'error');
        input?.focus();
        return;
    }

    try {
        await apiFetch(`/admin-api/trips/${tripId}/override`, {
            method: 'PUT',
            body: JSON.stringify({ courseNumber: val }),
        });
        // Lokales Trip-Objekt aktualisieren (kein erneuter API-Call nötig)
        const trip = allTrips.find(t => t.id === tripId);
        if (trip) {
            trip.manualCourseNumber = val;
            trip.activeCourseNumber = val;
        }
        showMessage(msgEl, `Kurs ${val} für Fahrt #${tripId} gesetzt.`, 'success');
        renderTable(container);
    } catch (err) {
        showMessage(msgEl, err.message, 'error');
    }
}

async function resetOverride(container, tripId, msgEl) {
    if (!confirm('Manuelle Übersteuerung für diese Fahrt zurücksetzen?')) return;

    try {
        await apiFetch(`/admin-api/trips/${tripId}/override`, { method: 'DELETE' });
        const trip = allTrips.find(t => t.id === tripId);
        if (trip) {
            trip.manualCourseNumber = null;
            // activeCourseNumber wird jetzt wieder per Mehrheitsregel bestimmt –
            // wir setzen hier null und zeigen damit "–" bis zum nächsten Reload
            trip.activeCourseNumber = null;
        }
        showMessage(msgEl, `Übersteuerung für Fahrt #${tripId} zurückgesetzt.`, 'success');
        renderTable(container);
    } catch (err) {
        showMessage(msgEl, err.message, 'error');
    }
}
