/**
 * school_holidays.js – Admin-Tab: Schulferien verwalten
 *
 * Funktionen:
 *  - Liste aller Einträge (Name, Von, Bis)
 *  - Neuen Eintrag anlegen (Formular oben)
 *  - Inline-Bearbeiten und Löschen je Eintrag
 */

import { apiFetch, escHtml, showMessage } from './admin.js';

export async function renderSchoolHolidays(container) {
    container.innerHTML = `
        <!-- Neuer Eintrag -->
        <div class="section-card">
            <h2 class="section-title">Neuen Schulferienblock anlegen</h2>
            <div id="sh-add-msg"></div>
            <form id="sh-add-form" novalidate>
                <div class="form-group">
                    <label for="sh-name">Bezeichnung</label>
                    <input type="text" id="sh-name" placeholder="z.B. Sommerferien 2026"
                           required maxlength="100" autocomplete="off">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="sh-from">Von</label>
                        <input type="date" id="sh-from" required>
                    </div>
                    <div class="form-group">
                        <label for="sh-to">Bis</label>
                        <input type="date" id="sh-to" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" id="btn-sh-add">
                    Eintrag anlegen
                </button>
            </form>
        </div>

        <!-- Liste -->
        <div class="section-card">
            <h2 class="section-title">Vorhandene Einträge</h2>
            <div id="sh-list-msg"></div>
            <div id="sh-list">
                <div style="text-align:center;padding:24px">
                    <div class="spinner"></div>
                </div>
            </div>
        </div>`;

    await loadList(container);
    attachAddForm(container);
}

// --- Liste laden & rendern --------------------------------------------------

async function loadList(container) {
    const listEl = container.querySelector('#sh-list');
    listEl.innerHTML = `<div style="text-align:center;padding:24px"><div class="spinner"></div></div>`;

    let holidays;
    try {
        holidays = await apiFetch('/admin-api/school-holidays');
    } catch (err) {
        listEl.innerHTML = `<div class="error-box">${escHtml(err.message)}</div>`;
        return;
    }

    if (holidays.length === 0) {
        listEl.innerHTML = `<div class="empty-state">Noch keine Schulferien eingetragen.</div>`;
        return;
    }

    listEl.innerHTML = `
        <table class="data-table">
            <thead>
                <tr>
                    <th>Bezeichnung</th>
                    <th>Von</th>
                    <th>Bis</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="sh-tbody">
                ${holidays.map(h => renderRow(h)).join('')}
            </tbody>
        </table>`;

    attachRowListeners(container);
}

function renderRow(h) {
    return `
        <tr data-id="${h.id}" id="sh-row-${h.id}">
            <td>${escHtml(h.name)}</td>
            <td>${formatDate(h.dateFrom)}</td>
            <td>${formatDate(h.dateTo)}</td>
            <td class="table-actions">
                <button class="btn btn-ghost btn-sm btn-sh-edit" data-id="${h.id}"
                        data-name="${escHtml(h.name)}"
                        data-from="${h.dateFrom}"
                        data-to="${h.dateTo}">Bearbeiten</button>
                <button class="btn btn-danger btn-sm btn-sh-delete"
                        data-id="${h.id}">Löschen</button>
            </td>
        </tr>`;
}

function renderEditRow(h) {
    return `
        <tr class="edit-row" id="sh-edit-row-${h.id}">
            <td colspan="4">
                <div id="sh-edit-msg-${h.id}"></div>
                <div class="edit-inline-form">
                    <input type="text"  id="sh-edit-name-${h.id}"  value="${escHtml(h.name)}"
                           maxlength="100" placeholder="Bezeichnung" style="min-width:180px;flex:1">
                    <input type="date"  id="sh-edit-from-${h.id}"  value="${h.dateFrom}">
                    <input type="date"  id="sh-edit-to-${h.id}"    value="${h.dateTo}">
                    <button class="btn btn-primary btn-sm btn-sh-save"  data-id="${h.id}">Speichern</button>
                    <button class="btn btn-ghost   btn-sm btn-sh-cancel" data-id="${h.id}">Abbrechen</button>
                </div>
            </td>
        </tr>`;
}

// --- Event-Listener ---------------------------------------------------------

function attachAddForm(container) {
    container.querySelector('#sh-add-form').addEventListener('submit', async e => {
        e.preventDefault();
        const msgEl = container.querySelector('#sh-add-msg');
        msgEl.innerHTML = '';

        const name = container.querySelector('#sh-name').value.trim();
        const from = container.querySelector('#sh-from').value;
        const to   = container.querySelector('#sh-to').value;

        if (!name || !from || !to) {
            showMessage(msgEl, 'Alle Felder ausfüllen.', 'error');
            return;
        }
        if (to < from) {
            showMessage(msgEl, '"Bis" darf nicht vor "Von" liegen.', 'error');
            return;
        }

        const btn = container.querySelector('#btn-sh-add');
        btn.disabled = true;
        try {
            await apiFetch('/admin-api/school-holidays', {
                method: 'POST',
                body: JSON.stringify({ name, dateFrom: from, dateTo: to }),
            });
            // Formular zurücksetzen und Liste neu laden
            e.target.reset();
            showMessage(msgEl, 'Eintrag angelegt.', 'success');
            await loadList(container);
        } catch (err) {
            showMessage(msgEl, err.message, 'error');
        } finally {
            btn.disabled = false;
        }
    });
}

function attachRowListeners(container) {
    const tbody = container.querySelector('#sh-tbody');
    if (!tbody) return;

    tbody.addEventListener('click', async e => {
        const btn = e.target.closest('button');
        if (!btn) return;
        const id = parseInt(btn.dataset.id, 10);

        if (btn.classList.contains('btn-sh-edit')) {
            openEditRow(tbody, btn, id);
        } else if (btn.classList.contains('btn-sh-cancel')) {
            closeEditRow(tbody, id);
        } else if (btn.classList.contains('btn-sh-save')) {
            await saveEdit(container, tbody, id);
        } else if (btn.classList.contains('btn-sh-delete')) {
            await deleteHoliday(container, id);
        }
    });
}

function openEditRow(tbody, editBtn, id) {
    // Bereits offene Edit-Rows schließen
    tbody.querySelectorAll('.edit-row').forEach(r => r.remove());
    // Daten aus data-Attributen lesen
    const row = tbody.querySelector(`#sh-row-${id}`);
    row.insertAdjacentHTML('afterend', renderEditRow({
        id,
        name:     editBtn.dataset.name,
        dateFrom: editBtn.dataset.from,
        dateTo:   editBtn.dataset.to,
    }));
}

function closeEditRow(tbody, id) {
    tbody.querySelector(`#sh-edit-row-${id}`)?.remove();
}

async function saveEdit(container, tbody, id) {
    const msgEl = document.querySelector(`#sh-edit-msg-${id}`);
    const name  = document.querySelector(`#sh-edit-name-${id}`)?.value.trim() ?? '';
    const from  = document.querySelector(`#sh-edit-from-${id}`)?.value ?? '';
    const to    = document.querySelector(`#sh-edit-to-${id}`)?.value ?? '';

    if (!name || !from || !to) {
        showMessage(msgEl, 'Alle Felder ausfüllen.', 'error');
        return;
    }
    if (to < from) {
        showMessage(msgEl, '"Bis" darf nicht vor "Von" liegen.', 'error');
        return;
    }

    try {
        await apiFetch(`/admin-api/school-holidays/${id}`, {
            method: 'PUT',
            body: JSON.stringify({ name, dateFrom: from, dateTo: to }),
        });
        await loadList(container);
    } catch (err) {
        showMessage(msgEl, err.message, 'error');
    }
}

async function deleteHoliday(container, id) {
    if (!confirm('Diesen Schulferienblock wirklich löschen?')) return;
    const msgEl = container.querySelector('#sh-list-msg');
    try {
        await apiFetch(`/admin-api/school-holidays/${id}`, { method: 'DELETE' });
        await loadList(container);
    } catch (err) {
        showMessage(msgEl, err.message, 'error');
    }
}

// --- Hilfsfunktionen --------------------------------------------------------

/** "YYYY-MM-DD" → "24.03.2026" */
function formatDate(str) {
    if (!str) return '–';
    const [y, m, d] = str.split('-');
    return `${d}.${m}.${y}`;
}
