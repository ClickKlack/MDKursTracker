/**
 * periods.js – Admin-Tab: Fahrplanperioden verwalten
 *
 * Funktionen:
 *  - Alle Perioden auflisten (aktuelle hervorgehoben, Anzahl Erfassungen)
 *  - Name und Startdatum je Periode nachträglich korrigieren
 *  - Fahrplanschnitt: neue Periode mit Bestätigungsdialog anlegen
 */

import { apiFetch, escHtml, showMessage } from './admin.js';

export async function renderPeriods(container) {
    container.innerHTML = `
        <!-- Fahrplanschnitt -->
        <div class="section-card">
            <h2 class="section-title">Neuer Fahrplanschnitt</h2>
            <div id="period-add-msg"></div>
            <form id="period-add-form" novalidate>
                <div class="form-row">
                    <div class="form-group">
                        <label for="period-name">Bezeichnung der neuen Periode</label>
                        <input type="text" id="period-name"
                               placeholder="z.B. Fahrplan 2027/2028"
                               maxlength="100" autocomplete="off" required>
                    </div>
                    <div class="form-group">
                        <label for="period-start">Startdatum</label>
                        <input type="date" id="period-start" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" id="btn-period-add">
                    Fahrplanschnitt durchführen…
                </button>
            </form>
        </div>

        <!-- Liste -->
        <div class="section-card">
            <h2 class="section-title">Vorhandene Perioden</h2>
            <div id="period-list-msg"></div>
            <div id="period-list">
                <div style="text-align:center;padding:24px"><div class="spinner"></div></div>
            </div>
        </div>

        <!-- Bestätigungsdialog (initial versteckt) -->
        <div id="confirm-overlay" class="confirm-overlay" hidden></div>`;

    await loadList(container);
    attachAddForm(container);
}

// --- Liste laden & rendern --------------------------------------------------

async function loadList(container) {
    const listEl = container.querySelector('#period-list');
    listEl.innerHTML = `<div style="text-align:center;padding:24px"><div class="spinner"></div></div>`;

    let periods;
    try {
        periods = await apiFetch('/api/periods');
    } catch (err) {
        listEl.innerHTML = `<div class="error-box">${escHtml(err.message)}</div>`;
        return;
    }

    if (periods.length === 0) {
        listEl.innerHTML = `<div class="empty-state">Keine Perioden vorhanden.</div>`;
        return;
    }

    // Neueste zuerst
    const sorted = periods.slice().reverse();
    listEl.innerHTML = sorted.map(p => renderPeriodCard(p)).join('');
    attachCardListeners(container, listEl);
}

function renderPeriodCard(p) {
    const activeBadge = p.active
        ? `<span class="badge-active">aktuell</span>`
        : '';
    const startStr = formatDate(p.startDate);
    const createdStr = p.createdAt ? formatDate(p.createdAt.slice(0, 10)) : '–';

    return `
        <div class="period-card ${p.active ? 'is-active' : ''}" data-id="${p.id}">
            <div class="period-card-info">
                <div class="period-card-name">${escHtml(p.name)}</div>
                <div class="period-card-meta">
                    Gültig ab ${escHtml(startStr)}
                    &nbsp;·&nbsp; Angelegt ${escHtml(createdStr)}
                    &nbsp;·&nbsp; ${p.recordingCount} Erfassung${p.recordingCount !== 1 ? 'en' : ''}
                </div>
                <div id="period-edit-area-${p.id}"></div>
            </div>
            ${activeBadge}
            <button class="btn btn-ghost btn-sm btn-period-edit"
                    data-id="${p.id}"
                    data-name="${escHtml(p.name)}"
                    data-start="${p.startDate ?? ''}">
                Bearbeiten
            </button>
        </div>`;
}

// --- Inline-Edit je Periode -------------------------------------------------

function attachCardListeners(container, listEl) {
    listEl.addEventListener('click', async e => {
        const btn = e.target.closest('button');
        if (!btn) return;

        if (btn.classList.contains('btn-period-edit')) {
            openPeriodEdit(listEl, btn);
        } else if (btn.classList.contains('btn-period-cancel')) {
            const id = parseInt(btn.dataset.id, 10);
            document.querySelector(`#period-edit-area-${id}`).innerHTML = '';
        } else if (btn.classList.contains('btn-period-save')) {
            await savePeriodEdit(container, btn);
        }
    });
}

function openPeriodEdit(listEl, btn) {
    // Alle anderen offenen Edits schließen
    listEl.querySelectorAll('[id^="period-edit-area-"]').forEach(el => el.innerHTML = '');

    const id = parseInt(btn.dataset.id, 10);
    const editArea = document.querySelector(`#period-edit-area-${id}`);

    editArea.innerHTML = `
        <div id="period-edit-msg-${id}" style="margin-top:8px"></div>
        <div class="edit-inline-form" style="margin-top:8px">
            <input type="text"  id="period-edit-name-${id}"
                   value="${escHtml(btn.dataset.name)}"
                   maxlength="100" placeholder="Bezeichnung" style="flex:1;min-width:160px">
            <input type="date"  id="period-edit-start-${id}"
                   value="${btn.dataset.start}">
            <button class="btn btn-primary btn-sm btn-period-save" data-id="${id}">Speichern</button>
            <button class="btn btn-ghost   btn-sm btn-period-cancel" data-id="${id}">Abbrechen</button>
        </div>`;
}

async function savePeriodEdit(container, btn) {
    const id    = parseInt(btn.dataset.id, 10);
    const msgEl = document.querySelector(`#period-edit-msg-${id}`);
    const name  = document.querySelector(`#period-edit-name-${id}`)?.value.trim() ?? '';
    const start = document.querySelector(`#period-edit-start-${id}`)?.value ?? '';

    if (!name) {
        showMessage(msgEl, 'Bezeichnung darf nicht leer sein.', 'error');
        return;
    }

    const body = {};
    if (name)  body.name      = name;
    if (start) body.startDate = start;

    try {
        await apiFetch(`/admin-api/periods/${id}`, {
            method: 'PUT',
            body: JSON.stringify(body),
        });
        await loadList(container);
    } catch (err) {
        showMessage(msgEl, err.message, 'error');
    }
}

// --- Fahrplanschnitt mit Bestätigungsdialog ---------------------------------

function attachAddForm(container) {
    container.querySelector('#period-add-form').addEventListener('submit', e => {
        e.preventDefault();
        const msgEl = container.querySelector('#period-add-msg');
        msgEl.innerHTML = '';

        const name  = container.querySelector('#period-name').value.trim();
        const start = container.querySelector('#period-start').value;

        if (!name || !start) {
            showMessage(msgEl, 'Bezeichnung und Startdatum sind Pflichtfelder.', 'error');
            return;
        }

        showConfirm(container, name, start, msgEl);
    });
}

function showConfirm(container, name, start, msgEl) {
    const overlay = container.querySelector('#confirm-overlay');
    overlay.innerHTML = `
        <div class="confirm-dialog" role="dialog" aria-modal="true"
             aria-labelledby="confirm-title">
            <h2 class="confirm-title" id="confirm-title">Fahrplanschnitt durchführen?</h2>
            <div class="confirm-body">
                Neue Periode: <strong>${escHtml(name)}</strong><br>
                Gültig ab: <strong>${formatDate(start)}</strong>
            </div>
            <div class="confirm-warning">
                Ab diesem Zeitpunkt beginnt die Erfassung bei Null –
                alle Altdaten bleiben erhalten und sind weiterhin auswertbar.
            </div>
            <div class="confirm-actions">
                <button class="btn btn-ghost"   id="btn-confirm-cancel">Abbrechen</button>
                <button class="btn btn-primary"  id="btn-confirm-ok">Ja, Fahrplanschnitt durchführen</button>
            </div>
        </div>`;
    overlay.hidden = false;

    overlay.querySelector('#btn-confirm-cancel').addEventListener('click', () => {
        overlay.hidden = true;
        overlay.innerHTML = '';
    });

    overlay.querySelector('#btn-confirm-ok').addEventListener('click', async () => {
        overlay.hidden = true;
        overlay.innerHTML = '';
        await createPeriod(container, name, start, msgEl);
    });
}

async function createPeriod(container, name, start, msgEl) {
    const btn = container.querySelector('#btn-period-add');
    btn.disabled = true;
    try {
        await apiFetch('/admin-api/periods', {
            method: 'POST',
            body: JSON.stringify({ name, startDate: start }),
        });
        container.querySelector('#period-add-form').reset();
        showMessage(msgEl, `Neue Periode „${name}" angelegt. App nutzt ab sofort diese Periode.`, 'success');
        await loadList(container);
    } catch (err) {
        showMessage(msgEl, err.message, 'error');
    } finally {
        btn.disabled = false;
    }
}

// --- Hilfsfunktionen --------------------------------------------------------

/** "YYYY-MM-DD" → "24.03.2026" */
function formatDate(str) {
    if (!str) return '–';
    const [y, m, d] = str.split('-');
    return `${d}.${m}.${y}`;
}
