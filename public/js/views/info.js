/**
 * info.js – View: Über / Info
 *
 * Zeigt Urheber, Beschreibung, Datenschutzhinweis und technische Versionsinfos.
 * Versionsdaten kommen aus dem globalen App-Config-State (via app.js).
 */

import { escapeHtml } from '../app.js';

export async function render(container, params, context) {
    const cfg = context.appConfig ?? {};
    container.innerHTML = buildHtml(cfg);
}

export function destroy() {
    // Keine Ressourcen zu bereinigen
}

// --- HTML aufbauen -----------------------------------------------------------

function buildHtml(cfg) {
    const version    = cfg.version        ?? null;
    const swVersion  = cfg.swCacheVersion ?? null;
    const deployedAt = cfg.deployedAt     ?? null;

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

            <section class="info-section card">
                <h2 class="info-heading">Version</h2>
                <dl class="info-dl">
                    <dt>Release</dt>
                    <dd>${version ? escapeHtml(version) : '<span class="text-muted">–</span>'}</dd>
                    <dt>Deployed</dt>
                    <dd>${deployedAt ? escapeHtml(formatDeployDate(deployedAt)) : '<span class="text-muted">–</span>'}</dd>
                    <dt>SW-Cache</dt>
                    <dd>${swVersion ? escapeHtml(swVersion) : '<span class="text-muted">–</span>'}</dd>
                </dl>
            </section>

        </div>`;
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
