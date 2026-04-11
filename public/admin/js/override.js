/**
 * override.js – Admin-Tab: Manuelle Kursnummer-Übersteuerung
 *
 * Ablauf:
 *  1. Perioden laden → Periode wählen
 *  2. Fahrten der gewählten Periode laden (GET /api/trips)
 *  3. Filter: Linie, Wochentagstyp
 *  4. Je Fahrt: aktuelle Kursnummer anzeigen, override setzen oder löschen
 */

import { apiFetch, escHtml, showMessage } from './admin.js';

const DAY_LABELS = {
    'MO-FR': 'Mo–Fr',
    'SA':    'Sa',
    'SO':    'So/Feiertag',
    'SF':    'SF',
};

/** Alle geladenen Trips der aktuell gewählten Periode */
let allTrips = [];

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
            <h2 class="section-title">Kursnummern übersteuern</h2>
            <div class="override-filters">
                <div class="form-group">
                    <label for="ov-period">Periode</label>
                    <select id="ov-period">${periodOptions}</select>
                </div>
                <div class="form-group">
                    <label for="ov-line">Linie</label>
                    <input type="text" id="ov-line" placeholder="alle"
                           maxlength="5" autocomplete="off" inputmode="numeric">
                </div>
                <div class="form-group">
                    <label for="ov-daytype">Wochentagstyp</label>
                    <select id="ov-daytype">
                        <option value="">Alle</option>
                        <option value="MO-FR">Mo–Fr</option>
                        <option value="SA">Samstag</option>
                        <option value="SO">So / Feiertag</option>
                        <option value="SF">Schulferien</option>
                    </select>
                </div>
            </div>
            <div id="ov-msg"></div>
        </div>

        <div class="section-card">
            <div id="ov-table-wrap">
                <div style="text-align:center;padding:24px"><div class="spinner"></div></div>
            </div>
        </div>`;

    await loadTrips(container, activePeriod?.id);
    attachFilters(container);
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

    renderTable(container);
}

function renderTable(container) {
    const lineFilter    = container.querySelector('#ov-line')?.value.trim() ?? '';
    const dayTypeFilter = container.querySelector('#ov-daytype')?.value ?? '';

    const trips = allTrips.filter(t => {
        if (lineFilter    && t.line    !== lineFilter)    return false;
        if (dayTypeFilter && t.dayType !== dayTypeFilter) return false;
        return true;
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
                    <th>Richtung</th>
                    <th>Typ</th>
                    <th>Erfassungen</th>
                    <th>Aktiv</th>
                    <th>Übersteuern</th>
                </tr>
            </thead>
            <tbody>
                ${trips.map(t => renderTripRow(t)).join('')}
            </tbody>
        </table>`;

    attachRowActions(container, wrap);
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

    return `
        <tr id="ov-row-${t.id}">
            <td><strong>${escHtml(t.line)}</strong></td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                title="${escHtml(t.direction)}">${escHtml(t.direction)}</td>
            <td>${escHtml(DAY_LABELS[t.dayType] ?? t.dayType)}</td>
            <td style="text-align:center">${t.recordingCount}</td>
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

    container.querySelector('#ov-line').addEventListener('input',    reload);
    container.querySelector('#ov-daytype').addEventListener('change', reload);
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
