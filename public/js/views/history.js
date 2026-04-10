/**
 * history.js – View: Erfassungen einsehen
 *
 * Funktionen:
 *  1. Periodenumschalter – ältere Perioden als read-only kennzeichnen
 *  2. Filter: Linie, Wochentagstyp, Datum
 *  3. Liste aller Erfassungen der gewählten Periode (neueste zuerst)
 *  4. Kennzeichnung ob Kursnummer manuell übersteuert
 */

import { getRecordings, getPeriods } from '../api.js';
import { formatTime }                from '../utils/format.js';
import { lineBadgeHtml }             from '../utils/lines.js';
import { escapeHtml }                from '../app.js';

const DAY_TYPE_LABELS = {
    'MO-FR': 'Mo–Fr',
    'SA':    'Sa',
    'SO':    'So / Feiertag',
    'SF':    'Schulferien',
};

/** ID der angezeigten Periode */
let currentPeriodId = null;
/** ID der wirklich aktiven (neuesten) Periode */
let activePeriodId  = null;
/** Debounce-Timer für Filtereingaben */
let filterDebounce  = null;

export async function render(container, params, context) {
    activePeriodId  = context.activePeriod?.id ?? null;
    currentPeriodId = activePeriodId;

    let periods;
    try {
        periods = await getPeriods();
        // activePeriodId aus context ggf. aus Perioden ergänzen
        if (activePeriodId === null) {
            activePeriodId  = periods.find(p => p.active)?.id ?? periods.at(-1)?.id ?? null;
            currentPeriodId = activePeriodId;
        }
    } catch (err) {
        container.innerHTML = `
            <div class="error-box" role="alert">
                Perioden konnten nicht geladen werden: ${escapeHtml(err.message)}
            </div>`;
        return;
    }

    container.innerHTML = buildShell(periods);
    attachListeners(container);
    await loadAndRender(container);
}

export function destroy() {
    if (filterDebounce) clearTimeout(filterDebounce);
    currentPeriodId = null;
    activePeriodId  = null;
    filterDebounce  = null;
}

// --- Shell aufbauen ----------------------------------------------------------

function buildShell(periods) {
    // Neueste Periode zuerst in der Auswahlliste
    const options = periods
        .slice()
        .reverse()
        .map(p => {
            const label = p.active
                ? `${escapeHtml(p.name)} (aktuell)`
                : escapeHtml(p.name);
            const selected = p.id === currentPeriodId ? ' selected' : '';
            return `<option value="${p.id}"${selected}>${label}</option>`;
        })
        .join('');

    return `
        <div class="history-controls">
            <div class="form-group">
                <label for="period-select">Fahrplanperiode</label>
                <select id="period-select">${options}</select>
            </div>
            <div id="readonly-banner-slot"></div>
            <div class="history-filters">
                <div class="form-group">
                    <label for="filter-line">Linie</label>
                    <input type="text" id="filter-line" placeholder="z.B. 6"
                           maxlength="5" autocomplete="off" inputmode="numeric">
                </div>
                <div class="form-group">
                    <label for="filter-daytype">Typ</label>
                    <select id="filter-daytype">
                        <option value="">Alle</option>
                        <option value="MO-FR">Mo–Fr</option>
                        <option value="SA">Samstag</option>
                        <option value="SO">So / Feiertag</option>
                        <option value="SF">Schulferien</option>
                    </select>
                </div>
                <div class="form-group history-filter-date">
                    <label for="filter-date">Datum</label>
                    <input type="date" id="filter-date">
                </div>
            </div>
        </div>
        <div id="recordings-list"></div>`;
}

/** Read-only-Banner einblenden oder ausblenden */
function updateReadOnlyBanner(container) {
    const slot      = container.querySelector('#readonly-banner-slot');
    const isOldPeriod = currentPeriodId !== activePeriodId;
    slot.innerHTML  = isOldPeriod
        ? `<div class="history-readonly-banner" role="status">
               Ältere Periode – nur Ansicht, keine neuen Erfassungen möglich
           </div>`
        : '';
}

// --- Laden & Rendern ---------------------------------------------------------

async function loadAndRender(container) {
    const listEl = container.querySelector('#recordings-list');
    if (!listEl) return;

    listEl.innerHTML = `
        <div class="loading-indicator" aria-live="polite">
            <div class="spinner" aria-hidden="true"></div>
            <p>Erfassungen werden geladen…</p>
        </div>`;

    // Filterparameter aus Formular lesen
    const line    = container.querySelector('#filter-line')?.value.trim()  ?? '';
    const dayType = container.querySelector('#filter-daytype')?.value      ?? '';
    const date    = container.querySelector('#filter-date')?.value         ?? '';

    const filters = {};
    if (currentPeriodId != null) filters.period_id = currentPeriodId;
    if (line)                    filters.line       = line;
    if (dayType)                 filters.day_type   = dayType;
    if (date)                    { filters.date_from = date; filters.date_to = date; }

    let recordings;
    try {
        recordings = await getRecordings(filters);
    } catch (err) {
        listEl.innerHTML = `
            <div class="error-box" role="alert">
                Erfassungen konnten nicht geladen werden: ${escapeHtml(err.message)}
            </div>
            <div class="mt-16">
                <button class="btn btn-secondary btn-full" id="btn-retry-hist">
                    Erneut versuchen
                </button>
            </div>`;
        listEl.querySelector('#btn-retry-hist')
            ?.addEventListener('click', () => loadAndRender(container));
        return;
    }

    if (recordings.length === 0) {
        listEl.innerHTML = `
            <div class="empty-state">
                <p>Keine Erfassungen für die gewählten Filter.</p>
            </div>`;
        return;
    }

    listEl.innerHTML = `
        <p class="text-small text-muted history-count">
            ${recordings.length}&nbsp;Erfassung${recordings.length !== 1 ? 'en' : ''}
        </p>
        <ul class="card-list" role="list" aria-label="Erfassungen">
            ${recordings.map(renderRecordingItem).join('')}
        </ul>`;
}

// --- Einzelne Erfassung rendern ----------------------------------------------

function renderRecordingItem(rec) {
    const isManual = rec.manualCourseNumber !== null;
    const time     = rec.recordedAt  ? formatTime(rec.recordedAt)    : '–';
    const date     = rec.serviceDate ? formatDateShort(rec.serviceDate) : '–';
    const dayLabel = DAY_TYPE_LABELS[rec.dayType] ?? rec.dayType;

    // Anzuzeigende Kursnummer: manuelle Übersteuerung hat Vorrang
    const displayCourse = rec.manualCourseNumber ?? rec.courseNumber;

    // Falls aktive Kursnummer von der eigenen Erfassung abweicht → Hinweis
    const differsHtml = (
        !isManual &&
        rec.activeCourseNumber !== null &&
        rec.activeCourseNumber !== rec.courseNumber
    )
        ? `<span class="recording-active-hint text-muted">
               (aktiv:&nbsp;${escapeHtml(rec.activeCourseNumber)})
           </span>`
        : '';

    const manualBadgeHtml = isManual
        ? `<span class="badge-manual" title="Kursnummer manuell durch Admin übersteuert">M</span>`
        : '';

    const ariaLabel = [
        `Linie ${rec.line}`,
        `nach ${rec.direction}`,
        `Kurs ${displayCourse}`,
        isManual ? 'manuell übersteuert' : '',
        `${dayLabel}, ${date}, Haltestelle ${rec.stop}`,
        `erfasst ${time} Uhr`,
    ].filter(Boolean).join(', ');

    return `
        <li class="card recording-item" aria-label="${escapeHtml(ariaLabel)}">
            <div class="recording-top">
                <span class="recording-line">${lineBadgeHtml(rec.line)}</span>
                <span class="recording-direction">${escapeHtml(rec.direction)}</span>
                <span class="recording-course">
                    <span class="course-number known">${escapeHtml(displayCourse)}</span>
                    ${manualBadgeHtml}
                </span>
            </div>
            <div class="recording-meta text-small text-muted">
                ${escapeHtml(rec.stop)}
                &nbsp;·&nbsp;${escapeHtml(dayLabel)}
                &nbsp;·&nbsp;${escapeHtml(date)}
                &nbsp;·&nbsp;${escapeHtml(time)}&nbsp;Uhr
                ${differsHtml}
            </div>
        </li>`;
}

// --- Event-Listener ----------------------------------------------------------

function attachListeners(container) {
    // Periodenumschalter
    container.querySelector('#period-select')?.addEventListener('change', async e => {
        currentPeriodId = parseInt(e.target.value, 10);
        updateReadOnlyBanner(container);
        await loadAndRender(container);
    });

    // Filter – Reload mit Debounce (300 ms, damit beim Tippen nicht zu viele Anfragen)
    const debouncedLoad = () => {
        if (filterDebounce) clearTimeout(filterDebounce);
        filterDebounce = setTimeout(() => loadAndRender(container), 300);
    };

    container.querySelector('#filter-line')?.addEventListener('input',  debouncedLoad);
    container.querySelector('#filter-daytype')?.addEventListener('change', debouncedLoad);
    container.querySelector('#filter-date')?.addEventListener('change',  debouncedLoad);
}

// --- Hilfsfunktionen ---------------------------------------------------------

/** "YYYY-MM-DD" → "24.03.2026" */
function formatDateShort(dateStr) {
    const [y, m, d] = dateStr.split('-');
    return `${d}.${m}.${y}`;
}
