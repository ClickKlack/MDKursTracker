/**
 * info.js – View: Über / Info
 *
 * Zeigt Urheber, Beschreibung, Datenschutzhinweis und technische Versionsinfos.
 * Versionsdaten kommen aus dem globalen App-Config-State (via app.js).
 */

import { escapeHtml, checkForSwUpdate, getActiveSwVersion } from '../app.js';

export async function render(container, params, context) {
    const cfg = context.appConfig ?? {};
    const activeSwVersion = await getActiveSwVersion();
    container.innerHTML = buildHtml(cfg, activeSwVersion);
    attachListeners(container);
}

export function destroy() {
    // Keine Ressourcen zu bereinigen
}

// --- HTML aufbauen -----------------------------------------------------------

function buildHtml(cfg, activeSwVersion) {
    const version    = cfg.version        ?? null;
    const swVersion  = cfg.swCacheVersion ?? null;
    const deployedAt = cfg.deployedAt     ?? null;

    const swMismatch = swVersion && activeSwVersion && swVersion !== activeSwVersion;

    const swActiveHtml = activeSwVersion
        ? `${escapeHtml(activeSwVersion)}${swMismatch ? ' <span class="info-sw-stale">(veraltet)</span>' : ''}`
        : '<span class="text-muted">–</span>';

    return `
        <div class="info-page">

            <section class="info-section card">
                <h2 class="info-heading">MDKursTracker</h2>
                <p class="info-text">
                    Eine Progressive Web App zur gemeinschaftlichen Erfassung von
                    Kursnummern der MVB-Straßenbahnen im marego-Verbund (Magdeburg).
                </p>
                <p class="info-text">
                    Fahrgäste erfassen über die App, welche Kursnummer eine Bahn trägt.
                    Durch die Mehrheitsregel über mehrere Erfassungen entsteht ein
                    verlässliches Bild des aktuellen Umlaufs.
                </p>
            </section>

            <section class="info-section card">
                <h2 class="info-heading">Version</h2>
                <dl class="info-dl">
                    <dt>Release</dt>
                    <dd>${version ? escapeHtml(version) : '<span class="text-muted">–</span>'}</dd>
                    <dt>Deployed</dt>
                    <dd>${deployedAt ? escapeHtml(formatDeployDate(deployedAt)) : '<span class="text-muted">–</span>'}</dd>
                    <dt>SW (Server)</dt>
                    <dd>${swVersion ? escapeHtml(swVersion) : '<span class="text-muted">–</span>'}</dd>
                    <dt>SW (aktiv)</dt>
                    <dd>${swActiveHtml}</dd>
                </dl>
                <div class="info-update-row">
                    <button id="btn-check-update" class="btn btn-secondary btn-sm">
                        Auf Updates prüfen
                    </button>
                    <span id="info-update-msg" class="text-small text-muted" aria-live="polite"></span>
                </div>
            </section>

            <section class="info-section card">
                <h2 class="info-heading">Urheber</h2>
                <p class="info-text">Jörg Schönebaum</p>
            </section>

            <section class="info-section card">
                <h2 class="info-heading">Datenschutz</h2>
                <p class="info-text">
                    Diese App ist ein privates, nicht-kommerzielles Werkzeug für
                    einen geschlossenen Nutzerkreis. Es werden keine
                    personenbezogenen Daten erfasst oder gespeichert. Erfassungen
                    sind anonym. Es findet keine Weitergabe an Dritte statt.
                </p>
            </section>

            <section class="info-section card">
                <h2 class="info-heading">Open Source</h2>
                <p class="info-text">
                    Der Quellcode steht unter der
                    <strong>MIT-Lizenz</strong> frei zur Verfügung.
                </p>
                <p class="info-text">
                    <a class="info-link"
                       href="https://codeberg.org/ClickKlack/JSKursTracker"
                       target="_blank" rel="noopener noreferrer">
                        codeberg.org/ClickKlack/JSKursTracker
                    </a>
                </p>
                <p class="info-text">
                    Verbesserungsvorschläge und Fehlerberichte können dort als
                    Issue eingereicht werden.
                </p>
            </section>

        </div>`;
}

// --- Interaktion -------------------------------------------------------------

function attachListeners(container) {
    const btn = container.querySelector('#btn-check-update');
    const msg = container.querySelector('#info-update-msg');
    if (!btn || !msg) return;

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        msg.textContent = 'Wird geprüft…';
        await checkForSwUpdate();
        // Nach dem Check neu auslesen – wenn ein neues SW sofort übernommen hat,
        // hat die Seite sich bereits neu geladen. Andernfalls Stand aktualisieren.
        const active = await getActiveSwVersion();
        if (!container.isConnected) return;
        btn.disabled = false;
        msg.textContent = active ? `Aktiv: ${active}` : 'Kein Update gefunden.';
    });
}

// --- Hilfsfunktionen ---------------------------------------------------------

/**
 * ISO-8601-Zeitstempel (mit Offset) in lesbare deutsche Darstellung umwandeln.
 * Beispiel: "2026-04-12T14:30:00+0200" → "12.04.2026, 14:30 (MESZ)"
 */
function formatDeployDate(isoStr) {
    try {
        const d = new Date(isoStr);
        if (isNaN(d.getTime())) return isoStr;
        return d.toLocaleString('de-DE', {
            day:      '2-digit',
            month:    '2-digit',
            year:     'numeric',
            hour:     '2-digit',
            minute:   '2-digit',
            timeZoneName: 'short',
        });
    } catch {
        return isoStr;
    }
}
