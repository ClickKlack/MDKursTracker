/**
 * history.js – View: Erfassungen einsehen
 *
 * Funktionen:
 *  1. Periodenumschalter – ältere Perioden als read-only kennzeichnen
 *  2. Filter: Linie, Wochentagstyp, Datum
 *  3. Liste aller Erfassungen der gewählten Periode (neueste zuerst)
 *  4. Kennzeichnung ob Kursnummer manuell übersteuert
 *  5. Eigene Erfassungen hervorheben (isOwn)
 *  6. Eigene Erfassungen nachträglich bearbeiten (Kurs + Kommentar), nur aktive Periode
 *  7. Eigene Erfassungen löschen (Soft-Delete) mit Undo-Snackbar; gleiche Kriterien wie 6.
 */

import {
    getRecordings, getPeriods, getRecordingRoute,
    putRecording, deleteRecording, restoreRecording,
} from '../api.js';
import { formatTime }                from '../utils/format.js';
import { lineBadgeHtml }             from '../utils/lines.js';
import { escapeHtml, stripStopPrefix } from '../app.js';

/** Aktive Snackbar samt Auto-Commit-Timer/Pending-Item; verhindert Stacking */
let activeSnackbar = null;

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
    // Offene Snackbar beim View-Wechsel sofort committen (Item endgültig entfernen)
    if (activeSnackbar) activeSnackbar.commit();
    currentPeriodId = null;
    activePeriodId  = null;
    filterDebounce  = null;
}

// --- Shell aufbauen ----------------------------------------------------------

function buildShell(periods) {
    const options = periods
        .slice()
        .reverse()
        .map(p => {
            const label    = p.active ? `${escapeHtml(p.name)} (aktuell)` : escapeHtml(p.name);
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

function updateReadOnlyBanner(container) {
    const slot        = container.querySelector('#readonly-banner-slot');
    const isOldPeriod = currentPeriodId !== activePeriodId;
    slot.innerHTML    = isOldPeriod
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

    // isEditable: eigene Erfassungen + aktive Periode
    const isActivePeriod = currentPeriodId === activePeriodId;

    listEl.innerHTML = `
        <p class="text-small text-muted history-count">
            ${recordings.length}&nbsp;Erfassung${recordings.length !== 1 ? 'en' : ''}
        </p>
        <ul class="card-list" role="list" aria-label="Erfassungen">
            ${recordings.map(rec => renderRecordingItem(rec, isActivePeriod)).join('')}
        </ul>`;

    const ul = listEl.querySelector('ul');
    ul.addEventListener('click',   e => handleListClick(e, container));
    ul.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); handleListClick(e, container); }
    });
}

// --- Einzelne Erfassung rendern ----------------------------------------------

function renderRecordingItem(rec, isActivePeriod) {
    const isManual  = rec.manualCourseNumber !== null;
    const planTime  = rec.departurePlanned ? formatTime(rec.departurePlanned) : '–';
    const time      = rec.recordedAt  ? formatTime(rec.recordedAt)    : '–';
    const date      = rec.serviceDate ? formatDateShort(rec.serviceDate) : '–';
    const dayLabel  = DAY_TYPE_LABELS[rec.dayType] ?? rec.dayType;

    const displayCourse = rec.manualCourseNumber ?? rec.courseNumber;

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

    // "Eigene" Erfassung: anderer Stil + Bearbeiten-/Löschen-Button (max. 60 Min. nach Erfassung)
    const ownClass    = rec.isOwn ? ' recording-item--own' : '';
    const ageMs       = rec.recordedAt ? Date.now() - new Date(rec.recordedAt).getTime() : Infinity;
    const isMutable   = rec.isOwn && isActivePeriod && !isManual && ageMs < 60 * 60 * 1000;
    const editBtnHtml = isMutable
        ? `<button class="btn btn-ghost btn-xs btn-edit-recording"
                   aria-label="Diese Erfassung bearbeiten">
               Bearbeiten
           </button>`
        : '';
    const deleteBtnHtml = isMutable
        ? `<button class="btn btn-ghost btn-xs btn-delete-recording"
                   aria-label="Diese Erfassung löschen">
               Löschen
           </button>`
        : '';

    // Kommentar anzeigen wenn vorhanden
    const commentHtml = rec.comment
        ? `<span class="recording-comment text-small text-muted">💬&nbsp;${escapeHtml(rec.comment)}</span>`
        : '';

    const ownBadge = rec.isOwn
        ? `<span class="badge-own" title="Deine Erfassung">Ich</span>`
        : '';

    const ariaLabel = [
        `Linie ${rec.line}`,
        `nach ${rec.direction}`,
        `Kurs ${displayCourse}`,
        isManual ? 'manuell übersteuert' : '',
        rec.isOwn ? 'eigene Erfassung' : '',
        `${dayLabel}, ${date}, Planabfahrt ${planTime} Uhr, Haltestelle ${stripStopPrefix(rec.stop)}`,
        `erfasst ${time} Uhr`,
    ].filter(Boolean).join(', ');

    return `
        <li class="card recording-item${ownClass}"
            role="button"
            tabindex="0"
            data-recording-id="${rec.id}"
            data-stop-id="${escapeHtml(rec.stopId)}"
            aria-expanded="false"
            aria-label="${escapeHtml(ariaLabel)}">
            <div class="recording-top">
                <span class="recording-line">${lineBadgeHtml(rec.line)}</span>
                <span class="recording-direction">${escapeHtml(rec.direction)}</span>
                <span class="recording-course">
                    ${ownBadge}
                    ${manualBadgeHtml}
                    <span class="course-number known">${escapeHtml(displayCourse)}</span>
                </span>
            </div>
            <div class="recording-meta text-small text-muted">
                ${escapeHtml(stripStopPrefix(rec.stop))}
                &nbsp;·&nbsp;${escapeHtml(dayLabel)}
                &nbsp;·&nbsp;${escapeHtml(planTime)}&nbsp;Uhr
                <br>erfasst:&nbsp;${escapeHtml(date)}&nbsp;·&nbsp;${escapeHtml(time)}&nbsp;Uhr
                ${differsHtml}
                ${commentHtml}
            </div>
            ${editBtnHtml || deleteBtnHtml
                ? `<div class="recording-footer">${editBtnHtml}${deleteBtnHtml}</div>`
                : ''}
            <div class="recording-route" hidden></div>
            <div class="recording-edit-panel" hidden></div>
        </li>`;
}

// --- Event-Listener ----------------------------------------------------------

function attachListeners(container) {
    container.querySelector('#period-select')?.addEventListener('change', async e => {
        currentPeriodId = parseInt(e.target.value, 10);
        updateReadOnlyBanner(container);
        await loadAndRender(container);
    });

    const debouncedLoad = () => {
        if (filterDebounce) clearTimeout(filterDebounce);
        filterDebounce = setTimeout(() => loadAndRender(container), 300);
    };

    container.querySelector('#filter-line')?.addEventListener('input',  debouncedLoad);
    container.querySelector('#filter-daytype')?.addEventListener('change', debouncedLoad);
    container.querySelector('#filter-date')?.addEventListener('change',  debouncedLoad);
}

// --- Zentraler Klick-Handler für die Liste ----------------------------------

function handleListClick(e, container) {
    // Bearbeiten-Button
    const editBtn = e.target.closest('.btn-edit-recording');
    if (editBtn) {
        e.stopPropagation();
        const item = editBtn.closest('.recording-item');
        if (item) toggleEditPanel(item);
        return;
    }

    // Löschen-Button (Soft-Delete + Undo-Snackbar)
    const delBtn = e.target.closest('.btn-delete-recording');
    if (delBtn) {
        e.stopPropagation();
        const item = delBtn.closest('.recording-item');
        if (item) handleDeleteClick(item, container);
        return;
    }

    // Laufweg-Toggle (bestehend)
    const item = e.target.closest('[data-recording-id]');
    if (!item) return;

    // Klick innerhalb des Edit-Panels oder Snackbar nicht weiterleiten
    if (e.target.closest('.recording-edit-panel')) return;
    if (item.dataset.pendingDelete === '1') return;

    handleRouteToggle(item);
}

// --- Laufweg-Klapp-Logik (bestehend) ----------------------------------------

async function handleRouteToggle(item) {
    const routeEl    = item.querySelector('.recording-route');
    const isExpanded = item.getAttribute('aria-expanded') === 'true';

    if (isExpanded) {
        item.setAttribute('aria-expanded', 'false');
        routeEl.hidden = true;
        return;
    }

    const openItem = item.closest('ul')?.querySelector('[aria-expanded="true"]');
    if (openItem && openItem !== item) {
        openItem.setAttribute('aria-expanded', 'false');
        openItem.querySelector('.recording-route').hidden = true;
    }

    item.setAttribute('aria-expanded', 'true');
    routeEl.hidden   = false;
    routeEl.innerHTML = `<div class="route-loading"><span class="spinner-small" aria-hidden="true"></span> Laufweg wird geladen…</div>`;

    const recordingId = parseInt(item.dataset.recordingId, 10);

    let stops;
    try {
        stops = await getRecordingRoute(recordingId);
    } catch (err) {
        routeEl.innerHTML = `<p class="route-error">${escapeHtml(err.message)}</p>`;
        return;
    }

    routeEl.innerHTML = renderRouteList(stops);
}

function renderRouteList(stops) {
    const knownLines  = stops.map(s => s.line).filter(l => l != null);
    const hasLineChange = new Set(knownLines).size > 1;

    let prevLine = null;
    const rows = stops.map(s => {
        const time = s.departurePlanned ? formatTime(s.departurePlanned) : '–';
        const cls  = s.isRecordingStop ? ' route-stop--recording' : '';

        let lineChangeSep = '';
        if (hasLineChange && s.line != null && s.line !== prevLine) {
            lineChangeSep = `
            <li class="route-line-change" aria-label="Linie ${escapeHtml(s.line)} ab hier">
                <span class="route-line-change-label">Linie</span>
                ${lineBadgeHtml(s.line)}
                <span class="route-line-change-label">ab hier</span>
            </li>`;
        }
        if (s.line != null) prevLine = s.line;

        return lineChangeSep + `
            <li class="route-stop${cls}">
                <span class="route-stop-time">${escapeHtml(time)}</span>
                <span class="route-stop-name">${escapeHtml(stripStopPrefix(s.name))}</span>
                ${s.isRecordingStop ? '<span class="route-stop-marker" aria-label="Erfassungshaltestelle">●</span>' : ''}
            </li>`;
    }).join('');

    return `<ul class="route-list" aria-label="Laufweg">${rows}</ul>`;
}

// --- Inline-Edit-Panel ------------------------------------------------------

function toggleEditPanel(item) {
    const editPanel = item.querySelector('.recording-edit-panel');
    if (!editPanel) return;

    const isOpen = !editPanel.hidden;
    if (isOpen) {
        editPanel.hidden = true;
        editPanel.innerHTML = '';
        return;
    }

    // Laufweg schließen wenn offen
    const routeEl = item.querySelector('.recording-route');
    if (routeEl && !routeEl.hidden) {
        routeEl.hidden = true;
        item.setAttribute('aria-expanded', 'false');
    }

    editPanel.hidden = false;
    renderEditPanel(item, editPanel);
}

function renderEditPanel(item, panel) {
    const recordingId  = parseInt(item.dataset.recordingId, 10);
    // Aktuelle Kursnummer aus dem DOM auslesen
    const currentCourse = item.querySelector('.course-number')?.textContent?.trim() ?? '';

    // Kurs-Buttons 00–39
    const courseButtons = Array.from({ length: 40 }, (_, i) => {
        const num     = String(i).padStart(2, '0');
        const active  = num === currentCourse ? ' btn-course--active' : '';
        return `<button class="btn-course${active}" data-course="${num}">${num}</button>`;
    }).join('');

    panel.innerHTML = `
        <div class="edit-panel">
            <p class="text-small text-muted edit-panel-title">Kurs ändern:</p>
            <div class="course-buttons-grid">${courseButtons}</div>
            <div class="form-group mt-8">
                <label for="edit-comment-${recordingId}" class="text-small">Kommentar</label>
                <textarea id="edit-comment-${recordingId}"
                          class="form-input edit-comment-input"
                          rows="2"
                          maxlength="500"
                          placeholder="Optionaler Kommentar…"
                          aria-label="Kommentar zu dieser Erfassung"></textarea>
            </div>
            <div id="edit-msg-${recordingId}" class="text-small" aria-live="polite"></div>
            <div class="edit-panel-actions">
                <button class="btn btn-primary btn-sm btn-save-edit"
                        data-recording-id="${recordingId}">Speichern</button>
                <button class="btn btn-ghost btn-sm btn-cancel-edit">Abbrechen</button>
            </div>
        </div>`;

    // Aktuellen Kommentar vorausfüllen
    const commentText = item.querySelector('.recording-comment')?.textContent?.replace(/^💬\s*/, '') ?? '';
    const commentEl   = panel.querySelector(`#edit-comment-${recordingId}`);
    if (commentEl) commentEl.value = commentText;

    // Aktiven Kurs markieren
    let selectedCourse = currentCourse;
    panel.querySelectorAll('.btn-course').forEach(btn => {
        btn.addEventListener('click', () => {
            panel.querySelectorAll('.btn-course').forEach(b => b.classList.remove('btn-course--active'));
            btn.classList.add('btn-course--active');
            selectedCourse = btn.dataset.course;
        });
    });

    panel.querySelector('.btn-cancel-edit')?.addEventListener('click', () => {
        panel.hidden = true;
        panel.innerHTML = '';
    });

    panel.querySelector('.btn-save-edit')?.addEventListener('click', async () => {
        const saveBtn  = panel.querySelector('.btn-save-edit');
        const msgEl    = panel.querySelector(`#edit-msg-${recordingId}`);
        const comment  = commentEl?.value?.trim() || null;

        saveBtn.disabled = true;
        msgEl.textContent = '';

        try {
            await putRecording(recordingId, {
                courseNumber: selectedCourse,
                comment,
            });

            // UI lokal aktualisieren
            const courseEl = item.querySelector('.course-number');
            if (courseEl) courseEl.textContent = selectedCourse;

            const existingComment = item.querySelector('.recording-comment');
            if (comment) {
                if (existingComment) {
                    existingComment.textContent = `💬\u00a0${comment}`;
                } else {
                    // Kommentar-Element nach differsHtml einfügen
                    const metaEl = item.querySelector('.recording-meta');
                    if (metaEl) {
                        const span = document.createElement('span');
                        span.className = 'recording-comment text-small text-muted';
                        span.textContent = `💬\u00a0${comment}`;
                        metaEl.appendChild(span);
                    }
                }
            } else if (existingComment) {
                existingComment.remove();
            }

            panel.hidden = true;
            panel.innerHTML = '';
        } catch (err) {
            msgEl.textContent = `Fehler: ${err.message}`;
            msgEl.className = 'text-small form-error';
            saveBtn.disabled = false;
        }
    });
}

// --- Soft-Delete + Undo-Snackbar --------------------------------------------

async function handleDeleteClick(item, container) {
    const recordingId = parseInt(item.dataset.recordingId, 10);
    if (!Number.isFinite(recordingId) || item.dataset.pendingDelete === '1') return;

    // Falls noch eine ältere Snackbar offen ist: deren Item endgültig committen,
    // bevor wir ein neues Lösch-Pending starten – kein Stacking, keine Race.
    if (activeSnackbar) activeSnackbar.commit();

    // Optionales offenes Edit-Panel/Laufweg schließen
    const editPanel = item.querySelector('.recording-edit-panel');
    if (editPanel && !editPanel.hidden) {
        editPanel.hidden = true;
        editPanel.innerHTML = '';
    }
    const routeEl = item.querySelector('.recording-route');
    if (routeEl && !routeEl.hidden) {
        routeEl.hidden = true;
        item.setAttribute('aria-expanded', 'false');
    }

    // Optimistisch ausblenden
    item.style.display = 'none';
    item.dataset.pendingDelete = '1';

    try {
        await deleteRecording(recordingId);
    } catch (err) {
        // Rollback der UI bei Backend-Fehler
        item.style.display = '';
        delete item.dataset.pendingDelete;
        showErrorBanner(container, `Löschen fehlgeschlagen: ${err.message}`);
        return;
    }

    showUndoSnackbar(
        container,
        'Erfassung gelöscht.',
        async () => {
            try {
                await restoreRecording(recordingId);
                item.style.display = '';
                delete item.dataset.pendingDelete;
            } catch (err) {
                showErrorBanner(container, `Rückgängig fehlgeschlagen: ${err.message}`);
            }
        },
        () => {
            // Auto-Commit: Item endgültig aus DOM entfernen + Counter pflegen
            item.remove();
            decrementCount(container);
        },
    );
}

/**
 * Zeigt eine Snackbar mit Undo-Button. Ältere Snackbar wird vorher committet.
 * onUndo: Promise-fähiger Callback. Wenn aufgerufen, wird onCommit nicht ausgelöst.
 * onCommit: Wird beim Auto-Dismiss (Timeout) aufgerufen.
 */
function showUndoSnackbar(container, message, onUndo, onCommit) {
    const SNACKBAR_MS = 6000;

    const bar = document.createElement('div');
    bar.className = 'snackbar';
    bar.setAttribute('role', 'status');
    bar.innerHTML = `
        <span class="snackbar-text">${escapeHtml(message)}</span>
        <button type="button" class="snackbar-undo"
                aria-label="Löschen rückgängig machen">Rückgängig</button>`;
    container.appendChild(bar);

    let committed = false;
    const finish = () => {
        if (committed) return;
        committed = true;
        clearTimeout(timer);
        bar.remove();
        if (activeSnackbar?.bar === bar) activeSnackbar = null;
    };

    const timer = setTimeout(() => {
        if (committed) return;
        finish();
        onCommit?.();
    }, SNACKBAR_MS);

    bar.querySelector('.snackbar-undo')?.addEventListener('click', async () => {
        if (committed) return;
        finish();
        await onUndo?.();
    });

    activeSnackbar = {
        bar,
        commit: () => {
            if (committed) return;
            finish();
            onCommit?.();
        },
    };
}

function decrementCount(container) {
    const countEl = container.querySelector('.history-count');
    if (!countEl) return;
    const next = container.querySelectorAll('.recording-item').length;
    countEl.innerHTML = `${next}&nbsp;Erfassung${next !== 1 ? 'en' : ''}`;
}

function showErrorBanner(container, message) {
    const slot = container.querySelector('#recordings-list');
    if (!slot) return;
    const banner = document.createElement('div');
    banner.className = 'error-box';
    banner.setAttribute('role', 'alert');
    banner.textContent = message;
    slot.prepend(banner);
    setTimeout(() => banner.remove(), 4000);
}

// --- Hilfsfunktionen ---------------------------------------------------------

/** "YYYY-MM-DD" → "24.03.2026" */
function formatDateShort(dateStr) {
    const [y, m, d] = dateStr.split('-');
    return `${d}.${m}.${y}`;
}
