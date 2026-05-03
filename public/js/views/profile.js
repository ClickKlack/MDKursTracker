/**
 * profile.js – View: Eigenes Profil
 *
 * Die Display-ID wird direkt aus dem localStorage-Token berechnet
 * (SHA-256 → Base36, gleicher Algorithmus wie server-seitig).
 * So ist der View sofort verfügbar, ohne auf einen API-Call zu warten.
 * Der Name wird anschließend leise vom Server nachgeladen.
 */

import { postUserInit, getUserProfile, putUserProfile } from '../api.js';
import { escapeHtml, swUpdateWaiting, applySwUpdate, showActionSnackbar } from '../app.js';

export async function render(container) {
    const token     = localStorage.getItem('user_token') ?? '';
    const displayId = token ? await deriveDisplayId(token) : '–';

    container.innerHTML = `
        <div class="profile-view">
            <div id="profile-update-card" class="card profile-update-card${swUpdateWaiting ? '' : ' hidden'}">
                <div class="profile-update-inner">
                    <div>
                        <strong>Update verfügbar</strong>
                        <p class="text-small text-muted" style="margin:2px 0 0">
                            Eine neue Version der App ist bereit.
                        </p>
                    </div>
                    <button id="btn-sw-update" class="btn btn-primary btn-sm">
                        Jetzt aktualisieren
                    </button>
                </div>
            </div>
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
            <div class="card profile-advanced-card">
                <h2 class="section-title">Erweitert</h2>
                <div class="form-group">
                    <p class="text-small text-muted profile-id-hint" style="margin-top:0">
                        Sicherungskopie deines App-Tokens. Falls du Browser-Daten
                        löschen musst, kannst du den Token später per DevTools wieder
                        in <code>localStorage.user_token</code> eintragen.
                    </p>
                    <div class="profile-advanced-actions">
                        <button id="btn-copy-token" type="button" class="btn btn-secondary btn-sm">
                            Token in Zwischenablage kopieren
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <p class="text-small text-muted profile-id-hint" style="margin-top:0">
                        Identität von einem anderen Gerät übernehmen: kopiere dort
                        zuerst den Token in die Zwischenablage und tippe dann hier
                        auf den Button. Deine aktuelle Identität auf diesem Gerät
                        wird dabei ersetzt.
                    </p>
                    <div class="profile-advanced-actions">
                        <button id="btn-paste-token" type="button" class="btn btn-secondary btn-sm">
                            Token aus Zwischenablage einfügen
                        </button>
                    </div>
                </div>
            </div>
        </div>`;

    // Update-Button verdrahten
    container.querySelector('#btn-sw-update')?.addEventListener('click', () => {
        applySwUpdate();
    });

    // Token-Kopieren-Button: liest user_token aus localStorage und schiebt ihn
    // in die Zwischenablage. Fallback über prompt(), wenn die Clipboard-API
    // nicht verfügbar ist (z. B. unsicherer Kontext ohne HTTPS).
    container.querySelector('#btn-copy-token')?.addEventListener('click', async () => {
        const t = localStorage.getItem('user_token') ?? '';
        if (!t) {
            showActionSnackbar('Kein Token vorhanden', /* isError= */ true);
            return;
        }
        try {
            await navigator.clipboard.writeText(t);
            showActionSnackbar('Token kopiert');
        } catch {
            window.prompt('Token (manuell kopieren):', t);
        }
    });

    // Token-Einfügen-Button: übernimmt eine andere Identität. Liest aus
    // Zwischenablage (Fallback prompt()), validiert, zeigt die Ziel-Display-ID
    // zur Bestätigung und lädt nach dem Schreiben neu, damit alle Views den
    // neuen Token verwenden.
    container.querySelector('#btn-paste-token')?.addEventListener('click', async () => {
        let raw = '';
        try {
            raw = (await navigator.clipboard.readText()) ?? '';
        } catch {
            // Clipboard verweigert (kein HTTPS, keine Erlaubnis): manuell erfragen
        }
        if (!raw) {
            raw = window.prompt('Token einfügen:', '') ?? '';
        }

        const normalized = normalizeToken(raw);
        if (!normalized) {
            showActionSnackbar('Kein gültiger Token', /* isError= */ true);
            return;
        }

        const currentToken = localStorage.getItem('user_token') ?? '';
        if (normalized === currentToken) {
            showActionSnackbar('Token ist bereits aktiv');
            return;
        }

        const newDisplayId = await deriveDisplayId(normalized);
        const ok = window.confirm(
            `Identität wechseln zu ID ${newDisplayId}?\n\n`
            + 'Der bisherige Token auf diesem Gerät wird ersetzt. '
            + 'Stelle sicher, dass du den alten Token notiert hast, '
            + 'falls du zurückwechseln möchtest.'
        );
        if (!ok) return;

        localStorage.setItem('user_token', normalized);
        window.location.reload();
    });

    // Falls das Update erst nach dem Rendern verfügbar wird
    const onUpdate = () => {
        const card = container.querySelector('#profile-update-card');
        if (card) card.classList.remove('hidden');
    };
    document.addEventListener('swupdateavailable', onUpdate);
    container._removeUpdateListener = () =>
        document.removeEventListener('swupdateavailable', onUpdate);

    // Name und createdAt leise im Hintergrund laden
    loadNameFromServer(container, token);
}

export function destroy() {
    // Event-Listener aufräumen, falls View verlassen wird
    const container = document.getElementById('app-main');
    container?._removeUpdateListener?.();
}

// --- Token-Validierung (spiegelt get_request_token() im Backend) ------------

/**
 * Normalisiert einen rohen Token-String: trimmt Whitespace, entfernt
 * Bindestriche, lowercased – und gibt ihn nur zurück, wenn er danach
 * 32 Hex-Zeichen lang ist. Sonst null.
 */
function normalizeToken(raw) {
    const cleaned = String(raw).trim().replace(/-/g, '').toLowerCase();
    return /^[0-9a-f]{32}$/.test(cleaned) ? cleaned : null;
}

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
