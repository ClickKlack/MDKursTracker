/**
 * announcements.js – Admin-Tab: Nachrichten verwalten
 *
 * Funktionen:
 *  - Liste aller Einträge (Text, Ablaufdatum lokal, abgelaufen-Kennzeichnung)
 *  - Neuen Eintrag anlegen (Formular oben)
 *  - Inline-Bearbeiten und Löschen je Eintrag
 *
 * Zeiten: Server speichert UTC (ISO-8601). Admin gibt lokale Zeit per
 * <input type="datetime-local"> ein – Konvertierung zentral in den
 * Hilfsfunktionen toLocalInput()/fromLocalInput().
 */

import { apiFetch, escHtml, showMessage } from './admin.js';

export async function renderAnnouncements(container) {
    container.innerHTML = `
        <!-- Neuer Eintrag -->
        <div class="section-card">
            <h2 class="section-title">Neue Nachricht anlegen</h2>
            <div id="ann-add-msg"></div>
            <form id="ann-add-form" novalidate>
                <div class="form-group">
                    <label for="ann-body">Nachrichtentext</label>
                    <textarea id="ann-body" required maxlength="2000" rows="3"
                              placeholder="z.B. Heute Abend kein Spätbetrieb"></textarea>
                </div>
                <div class="form-group">
                    <label for="ann-expires">Anzeigen bis (lokale Zeit)</label>
                    <input type="datetime-local" id="ann-expires" required>
                </div>
                <button type="submit" class="btn btn-primary" id="btn-ann-add">
                    Nachricht anlegen
                </button>
            </form>
        </div>

        <!-- Liste -->
        <div class="section-card">
            <h2 class="section-title">Vorhandene Nachrichten</h2>
            <div id="ann-list-msg"></div>
            <div id="ann-list">
                <div style="text-align:center;padding:24px">
                    <div class="spinner"></div>
                </div>
            </div>
        </div>`;

    // Default-Vorschlag: morgen 23:59 lokal
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(23, 59, 0, 0);
    container.querySelector('#ann-expires').value = toLocalInput(tomorrow);

    await loadList(container);
    attachAddForm(container);
}

// --- Liste laden & rendern --------------------------------------------------

async function loadList(container) {
    const listEl = container.querySelector('#ann-list');
    listEl.innerHTML = `<div style="text-align:center;padding:24px"><div class="spinner"></div></div>`;

    let items;
    try {
        items = await apiFetch('/admin-api/announcements');
    } catch (err) {
        listEl.innerHTML = `<div class="error-box">${escHtml(err.message)}</div>`;
        return;
    }

    if (items.length === 0) {
        listEl.innerHTML = `<div class="empty-state">Noch keine Nachrichten angelegt.</div>`;
        return;
    }

    const now = Date.now();
    listEl.innerHTML = `
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nachricht</th>
                    <th>Anzeigen bis</th>
                    <th>Angelegt</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="ann-tbody">
                ${items.map(a => renderRow(a, now)).join('')}
            </tbody>
        </table>`;

    attachRowListeners(container);
}

function renderRow(a, now) {
    const expired = new Date(a.expiresAt).getTime() <= now;
    const expiredBadge = expired
        ? `<span class="badge-manual" title="Ablaufdatum erreicht – wird nicht mehr angezeigt">abgelaufen</span> `
        : '';
    return `
        <tr data-id="${a.id}" id="ann-row-${a.id}">
            <td class="ann-body-cell">${expiredBadge}${escHtml(a.body)}</td>
            <td>${formatDateTime(a.expiresAt)}</td>
            <td>${formatDateTime(a.createdAt)}</td>
            <td class="table-actions">
                <button class="btn btn-ghost btn-sm btn-ann-edit"
                        data-id="${a.id}"
                        data-body="${escHtml(a.body)}"
                        data-expires="${a.expiresAt}">Bearbeiten</button>
                <button class="btn btn-danger btn-sm btn-ann-delete"
                        data-id="${a.id}">Löschen</button>
            </td>
        </tr>`;
}

function renderEditRow(a) {
    return `
        <tr class="edit-row" id="ann-edit-row-${a.id}">
            <td colspan="4">
                <div id="ann-edit-msg-${a.id}"></div>
                <div class="edit-inline-form">
                    <textarea id="ann-edit-body-${a.id}" rows="2" maxlength="2000"
                              style="min-width:240px;flex:2">${escHtml(a.body)}</textarea>
                    <input type="datetime-local" id="ann-edit-expires-${a.id}"
                           value="${toLocalInput(new Date(a.expiresAt))}">
                    <button class="btn btn-primary btn-sm btn-ann-save"   data-id="${a.id}">Speichern</button>
                    <button class="btn btn-ghost   btn-sm btn-ann-cancel" data-id="${a.id}">Abbrechen</button>
                </div>
            </td>
        </tr>`;
}

// --- Event-Listener ---------------------------------------------------------

function attachAddForm(container) {
    container.querySelector('#ann-add-form').addEventListener('submit', async e => {
        e.preventDefault();
        const msgEl = container.querySelector('#ann-add-msg');
        msgEl.innerHTML = '';

        const body    = container.querySelector('#ann-body').value.trim();
        const expires = container.querySelector('#ann-expires').value;

        if (!body) {
            showMessage(msgEl, 'Nachrichtentext darf nicht leer sein.', 'error');
            return;
        }
        if (!expires) {
            showMessage(msgEl, 'Ablaufdatum fehlt.', 'error');
            return;
        }

        const btn = container.querySelector('#btn-ann-add');
        btn.disabled = true;
        try {
            await apiFetch('/admin-api/announcements', {
                method: 'POST',
                body: JSON.stringify({
                    body,
                    expiresAt: fromLocalInput(expires),
                }),
            });
            e.target.reset();
            // Default wiederherstellen
            const t = new Date(); t.setDate(t.getDate() + 1); t.setHours(23, 59, 0, 0);
            container.querySelector('#ann-expires').value = toLocalInput(t);
            showMessage(msgEl, 'Nachricht angelegt.', 'success');
            await loadList(container);
        } catch (err) {
            showMessage(msgEl, err.message, 'error');
        } finally {
            btn.disabled = false;
        }
    });
}

function attachRowListeners(container) {
    const tbody = container.querySelector('#ann-tbody');
    if (!tbody) return;

    tbody.addEventListener('click', async e => {
        const btn = e.target.closest('button');
        if (!btn) return;
        const id = parseInt(btn.dataset.id, 10);

        if (btn.classList.contains('btn-ann-edit')) {
            openEditRow(tbody, btn, id);
        } else if (btn.classList.contains('btn-ann-cancel')) {
            closeEditRow(tbody, id);
        } else if (btn.classList.contains('btn-ann-save')) {
            await saveEdit(container, id);
        } else if (btn.classList.contains('btn-ann-delete')) {
            await deleteAnnouncement(container, id);
        }
    });
}

function openEditRow(tbody, editBtn, id) {
    tbody.querySelectorAll('.edit-row').forEach(r => r.remove());
    const row = tbody.querySelector(`#ann-row-${id}`);
    row.insertAdjacentHTML('afterend', renderEditRow({
        id,
        body:      editBtn.dataset.body,
        expiresAt: editBtn.dataset.expires,
    }));
}

function closeEditRow(tbody, id) {
    tbody.querySelector(`#ann-edit-row-${id}`)?.remove();
}

async function saveEdit(container, id) {
    const msgEl   = document.querySelector(`#ann-edit-msg-${id}`);
    const body    = document.querySelector(`#ann-edit-body-${id}`)?.value.trim() ?? '';
    const expires = document.querySelector(`#ann-edit-expires-${id}`)?.value ?? '';

    if (!body) {
        showMessage(msgEl, 'Nachrichtentext darf nicht leer sein.', 'error');
        return;
    }
    if (!expires) {
        showMessage(msgEl, 'Ablaufdatum fehlt.', 'error');
        return;
    }

    try {
        await apiFetch(`/admin-api/announcements/${id}`, {
            method: 'PUT',
            body: JSON.stringify({
                body,
                expiresAt: fromLocalInput(expires),
            }),
        });
        await loadList(container);
    } catch (err) {
        showMessage(msgEl, err.message, 'error');
    }
}

async function deleteAnnouncement(container, id) {
    if (!confirm('Diese Nachricht wirklich löschen?')) return;
    const msgEl = container.querySelector('#ann-list-msg');
    try {
        await apiFetch(`/admin-api/announcements/${id}`, { method: 'DELETE' });
        await loadList(container);
    } catch (err) {
        showMessage(msgEl, err.message, 'error');
    }
}

// --- Hilfsfunktionen --------------------------------------------------------

/** ISO-UTC → "DD.MM.YYYY HH:MM" in lokaler Zeit */
function formatDateTime(iso) {
    if (!iso) return '–';
    const d = new Date(iso);
    return d.toLocaleString('de-DE', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

/**
 * Date-Objekt → Wert für <input type="datetime-local"> (lokale Zeit, ohne TZ-Suffix).
 */
function toLocalInput(d) {
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/**
 * datetime-local-Wert (lokale Zeit) → ISO-UTC für API.
 * Der Browser parst den lokalen Input bereits als lokale Zeit;
 * Date#toISOString() liefert dann sauberes UTC.
 */
function fromLocalInput(localStr) {
    return new Date(localStr).toISOString();
}
