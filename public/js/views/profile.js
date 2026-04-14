/**
 * profile.js – View: Eigenes Profil
 *
 * Die Display-ID wird direkt aus dem localStorage-Token berechnet
 * (SHA-256 → Base36, gleicher Algorithmus wie server-seitig).
 * So ist der View sofort verfügbar, ohne auf einen API-Call zu warten.
 * Der Name wird anschließend leise vom Server nachgeladen.
 */

import { postUserInit, getUserProfile, putUserProfile } from '../api.js';
import { escapeHtml } from '../app.js';

export async function render(container) {
    const token     = localStorage.getItem('user_token') ?? '';
    const displayId = token ? await deriveDisplayId(token) : '–';

    container.innerHTML = `
        <div class="profile-view">
            <div class="card profile-card">
                <h2 class="section-title">Mein Profil</h2>
                <div class="profile-fields">
                    <div class="form-group">
                        <label class="form-label text-muted text-small">Deine ID</label>
                        <div class="profile-display-id" aria-label="Deine anonyme ID">
                            ${escapeHtml(displayId)}
                        </div>
                        <p class="text-small text-muted profile-id-hint">
                            Diese ID ist nur dir und dem Admin sichtbar.
                        </p>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="profile-name">Name (freiwillig)</label>
                        <input type="text"
                               id="profile-name"
                               class="form-input"
                               value=""
                               placeholder="Wird geladen…"
                               maxlength="100"
                               autocomplete="name"
                               disabled>
                        <div id="profile-msg" role="status" aria-live="polite" style="min-height:1.2em"></div>
                    </div>
                    <p id="profile-created" class="text-small text-muted profile-created" hidden></p>
                </div>
            </div>
            <div class="card profile-info-card">
                <p class="text-small text-muted">
                    Du wirst <strong>anonym</strong> erfasst – kein Konto, kein Passwort.
                    Dein Gerät wird über einen zufälligen Schlüssel (im Browser gespeichert)
                    erkannt. Der Name ist freiwillig und hilft mir nur, dich bei Rückfragen
                    zu kontaktieren.
                </p>
            </div>
        </div>`;

    // Name und createdAt leise im Hintergrund laden
    loadNameFromServer(container, token);
}

export function destroy() {}

// --- Display-ID client-seitig berechnen (SHA-256 → Base36) ------------------
// Gleicher Algorithmus wie PHP derive_display_id() in lib/user_helpers.php

async function deriveDisplayId(token) {
    const msgBuffer  = new TextEncoder().encode(token);
    const hashBuffer = await crypto.subtle.digest('SHA-256', msgBuffer);
    const hashArray  = Array.from(new Uint8Array(hashBuffer));
    const hashHex    = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    const hex = hashHex.slice(0, 6);  // erste 6 Hex-Zeichen = 24 Bit
    const dec = parseInt(hex, 16);
    return dec.toString(36).toUpperCase().padStart(5, '0');
}

// --- Name vom Server nachladen ----------------------------------------------

async function loadNameFromServer(container, token) {
    const nameEl    = container.querySelector('#profile-name');
    const createdEl = container.querySelector('#profile-created');
    if (!nameEl) return;

    // Sicherstellen dass der Token registriert ist (idempotent)
    if (token) {
        postUserInit().catch(() => {});
    }

    try {
        const profile = await getUserProfile();
        if (!nameEl.isConnected) return; // View inzwischen verlassen
        nameEl.value       = profile.name ?? '';
        nameEl.placeholder = 'z.B. Max Mustermann';
        nameEl.disabled    = false;

        if (profile.createdAt && createdEl) {
            const date = new Date(profile.createdAt.replace(' ', 'T') + 'Z')
                .toLocaleDateString('de-DE');
            createdEl.textContent = `Anonym seit ${date}`;
            createdEl.hidden = false;
        }
    } catch {
        // Fehler beim Nachladen = kein Problem, Eingabe trotzdem freischalten
        if (!nameEl.isConnected) return;
        nameEl.placeholder = 'z.B. Max Mustermann';
        nameEl.disabled    = false;
    }

    attachListeners(container);
}

// --- Auto-Save beim Verlassen des Feldes ------------------------------------

function attachListeners(container) {
    const nameEl = container.querySelector('#profile-name');
    const msgEl  = container.querySelector('#profile-msg');
    if (!nameEl || !msgEl) return;

    let lastSavedValue = nameEl.value;
    let debounceTimer  = null;

    async function save() {
        const value = nameEl.value.trim();
        if (value === lastSavedValue) return;

        if (debounceTimer) { clearTimeout(debounceTimer); debounceTimer = null; }

        msgEl.textContent = 'Wird gespeichert…';
        msgEl.className   = 'text-small text-muted';

        try {
            await putUserProfile(value || null);
            lastSavedValue    = value;
            msgEl.textContent = 'Gespeichert.';
            msgEl.className   = 'form-success text-small';
            setTimeout(() => {
                if (msgEl.textContent === 'Gespeichert.') msgEl.textContent = '';
            }, 2500);
        } catch (err) {
            msgEl.textContent = `Fehler: ${err.message}`;
            msgEl.className   = 'form-error text-small';
        }
    }

    // Speichern 1,5 s nach Tippende
    nameEl.addEventListener('input', () => {
        if (debounceTimer) clearTimeout(debounceTimer);
        debounceTimer = setTimeout(save, 1500);
    });

    // Sofort speichern beim Verlassen des Feldes
    nameEl.addEventListener('blur', () => {
        if (debounceTimer) { clearTimeout(debounceTimer); debounceTimer = null; }
        save();
    });
}
