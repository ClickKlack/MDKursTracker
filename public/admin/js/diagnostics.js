/**
 * diagnostics.js – Admin-Tab: Diagnose der Kursnummer-Heuristik
 *
 * Vier Abschnitte, von der Ursache zum Symptom:
 *  - Laufweg-Änderungen: Dieselbe Fahrt (Linie, Tagestyp, Halt, Soll-Zeit) hat
 *    plötzlich ein anderes Ziel oder einen anderen Start. Greift auch dann,
 *    wenn die Kursnummer gleich bleibt. Abwechselnd weiterfahrende Kurse
 *    (Linie 10) haben eigene Soll-Zeiten und lösen deshalb nichts aus.
 *  - Fahrplanwechsel: Innerhalb der laufenden Periode hat sich ein Laufweg
 *    geändert. Alte und neue Fahrten liegen dann in derselben Periode und
 *    kollidieren am selben Route-Schlüssel. Handlung: Periode schneiden.
 *  - Kurskonflikte: Schlüssel, die auch die Stichentscheide (Richtung,
 *    Laufwegzeiten) nicht trennen. Diese Abfahrten zeigen dauerhaft "??".
 *    Handlung: manuelle Kursnummer am Trip setzen.
 *  - Aussetzer im Betrieb: Was Nutzer davon tatsächlich zu sehen bekommen,
 *    mit Trefferzähler.
 */

import { apiFetch, escHtml } from './admin.js';

export async function renderDiagnostics(container) {
    container.innerHTML = `<div style="text-align:center;padding:48px"><div class="spinner"></div></div>`;

    let data;
    try {
        data = await apiFetch('/admin-api/diagnostics');
    } catch (err) {
        container.innerHTML = `<div class="error-box">${escHtml(err.message)}</div>`;
        return;
    }

    const s = data.summary;
    container.innerHTML = `
        <div class="section-card">
            <h2 class="section-title">Übersicht</h2>
            <p class="text-muted text-small" style="margin-bottom:12px">
                Periode #${data.period.id} – ${escHtml(data.period.name)} (ab ${escHtml(data.period.startDate)})
            </p>
            <div class="diag-summary">
                ${summaryTile('Laufweg-Änderungen', s.routeChanges, 'Fahrt hat Ziel oder Start gewechselt')}
                ${summaryTile('Fahrplanwechsel', s.scheduleDrift, 'Kursnummern kollidieren zeitversetzt')}
                ${summaryTile('Kurskonflikte', s.courseConflicts, 'Heuristik kann nicht auflösen')}
                ${summaryTile('Aussetzer im Betrieb', s.heuristicMisses,
                    s.missHits > 0 ? `${s.missHits}× von Nutzern gesehen` : 'noch nicht aufgetreten')}
            </div>
        </div>

        <div class="section-card">
            <h2 class="section-title">Laufweg einzelner Fahrten geändert</h2>
            <div id="diag-routechg"></div>
        </div>

        <div class="section-card">
            <h2 class="section-title">Fahrplanwechsel in laufender Periode</h2>
            <div id="diag-drift"></div>
        </div>

        <div class="section-card">
            <h2 class="section-title">Unauflösbare Kurskonflikte</h2>
            <div id="diag-conflicts"></div>
        </div>

        <div class="section-card">
            <h2 class="section-title">Aussetzer im Betrieb</h2>
            <div id="diag-misses"></div>
        </div>`;

    renderRouteChanges(container.querySelector('#diag-routechg'), data.routeChanges ?? []);
    renderDrift(container.querySelector('#diag-drift'), data.scheduleDrift);
    renderConflicts(container.querySelector('#diag-conflicts'), data.courseConflicts);
    renderMisses(container.querySelector('#diag-misses'), data.heuristicMisses);
}

function summaryTile(label, value, hint) {
    const cls = value > 0 ? 'diag-tile diag-tile--warn' : 'diag-tile';
    return `
        <div class="${cls}">
            <div class="diag-tile-value">${value}</div>
            <div class="diag-tile-label">${escHtml(label)}</div>
            <div class="diag-tile-hint">${escHtml(hint)}</div>
        </div>`;
}

function emptyNote(text) {
    return `<p class="text-muted text-small">${escHtml(text)}</p>`;
}

// --- Laufweg-Änderungen ------------------------------------------------------

function renderRouteChanges(el, findings) {
    if (!findings.length) {
        el.innerHTML = emptyNote('Keine – jede Fahrt behält innerhalb der Periode ihren Laufweg.');
        return;
    }

    el.innerHTML = `
        <p class="text-muted text-small" style="margin-bottom:12px">
            Dieselbe Fahrt – gleiche Linie, gleicher Tagestyp, gleiche Haltestelle
            und Soll-Zeit – endet oder beginnt plötzlich woanders, ohne dass sich
            die Zeiträume überlappen. Abwechselnd weiterfahrende Kurse haben
            eigene Soll-Zeiten und tauchen hier nicht auf.
        </p>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Linie</th><th>Tagestyp</th><th>Fahrt</th>
                    <th>Geändert</th><th>Bisher</th><th>Neu</th><th>Art</th>
                </tr>
            </thead>
            <tbody>
                ${findings.map(c => `
                    <tr>
                        <td><strong>${escHtml(c.line)}</strong></td>
                        <td>${escHtml(c.dayType)}</td>
                        <td>
                            ${escHtml(c.slotHhmm)}<br>
                            <span class="text-muted text-small">${escHtml(c.slotStopName)}</span>
                        </td>
                        <td>
                            <strong>${escHtml(c.changedOn)}</strong><br>
                            <span class="text-muted text-small">
                                ${c.changedSide === 'end' ? 'Ziel' : 'Start'}
                            </span>
                        </td>
                        <td>
                            ${escHtml(c.from.movedStopName)}<br>
                            <span class="text-muted text-small">
                                ${c.from.stopCount} Halte · Kurs ${escHtml(c.from.courses.join('/') || '–')}
                            </span>
                        </td>
                        <td>
                            ${escHtml(c.to.movedStopName)}<br>
                            <span class="text-muted text-small">
                                ${c.to.stopCount} Halte · Kurs ${escHtml(c.to.courses.join('/') || '–')}
                            </span>
                        </td>
                        <td>${c.shortened ? 'Verkürzung' : 'anderes Ziel'}</td>
                    </tr>`).join('')}
            </tbody>
        </table>`;
}

// --- Fahrplanwechsel ---------------------------------------------------------

function renderDrift(el, findings) {
    if (!findings.length) {
        el.innerHTML = emptyNote('Keine Auffälligkeit – alle Laufwege der Periode sind zeitlich konsistent.');
        return;
    }

    el.innerHTML = `
        <p class="text-muted text-small" style="margin-bottom:12px">
            Trips mit verschiedenen Kursnummern am selben Route-Schlüssel, deren
            Erfassungszeiträume sich nicht überlappen. Empfehlung: neue Periode
            zum genannten Datum anlegen.
        </p>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Linie</th><th>Tagestyp</th><th>Wechsel am</th>
                    <th>Bisher</th><th>Neu</th><th>Betroffen</th>
                </tr>
            </thead>
            <tbody>
                ${findings.map(d => `
                    <tr>
                        <td><strong>${escHtml(d.line)}</strong></td>
                        <td>${escHtml(d.dayType)}</td>
                        <td><strong>${escHtml(d.changedOn)}</strong></td>
                        <td>
                            ${escHtml(d.from.endStopName)}<br>
                            <span class="text-muted text-small">
                                ${d.from.stopCount} Halte · ${d.from.tripCount} Trips · bis ${escHtml(d.from.lastSeen)}
                            </span>
                        </td>
                        <td>
                            ${escHtml(d.to.endStopName)}<br>
                            <span class="text-muted text-small">
                                ${d.to.stopCount} Halte · ${d.to.tripCount} Trips · ab ${escHtml(d.to.firstSeen)}
                            </span>
                        </td>
                        <td>
                            ${d.affectedKeys} Schlüssel<br>
                            <span class="text-muted text-small">${escHtml(exampleText(d.examples))}</span>
                        </td>
                    </tr>`).join('')}
            </tbody>
        </table>`;
}

function exampleText(examples) {
    if (!examples || !examples.length) return '';
    const e = examples[0];
    return `z. B. ${e.hhmm} – Kurs ${e.oldCourse} → ${e.newCourse}`;
}

// --- Kurskonflikte -----------------------------------------------------------

function renderConflicts(el, findings) {
    if (!findings.length) {
        el.innerHTML = emptyNote('Keine – jeder Route-Schlüssel liefert eine eindeutige Kursnummer.');
        return;
    }

    el.innerHTML = `
        <p class="text-muted text-small" style="margin-bottom:12px">
            Hier stimmen Richtung und Laufwegzeiten überein, die Kursnummern aber
            nicht. Die Stichentscheide greifen nicht mehr – diese Abfahrten
            bleiben ohne Kursnummer, bis am Trip ein Override gesetzt wird.
        </p>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Linie</th><th>Tagestyp</th><th>Zeit</th>
                    <th>Haltestelle</th><th>Richtung</th><th>Kurse</th><th>Trips</th>
                </tr>
            </thead>
            <tbody>
                ${findings.map(c => `
                    <tr>
                        <td><strong>${escHtml(c.line)}</strong></td>
                        <td>${escHtml(c.dayType)}</td>
                        <td>${escHtml(c.hhmm)}</td>
                        <td>${escHtml(c.stopName)}</td>
                        <td>${escHtml(c.direction ?? '–')}</td>
                        <td><strong>${escHtml(c.courses.join(' / '))}</strong></td>
                        <td class="text-small">${escHtml(c.tripIds.join(', '))}</td>
                    </tr>`).join('')}
            </tbody>
        </table>`;
}

// --- Aussetzer im Betrieb ----------------------------------------------------

function renderMisses(el, rows) {
    if (!rows.length) {
        el.innerHTML = emptyNote('Noch kein Aussetzer protokolliert – bislang hat kein Nutzer eine widersprüchliche Abfahrt geöffnet.');
        return;
    }

    el.innerHTML = `
        <p class="text-muted text-small" style="margin-bottom:12px">
            Tatsächlich ausgelieferte Abfahrten ohne Kursnummer, obwohl Daten
            vorlagen. Der Zähler zeigt, wie oft das passiert ist.
        </p>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Treffer</th><th>Linie</th><th>Tagestyp</th><th>Zeit</th>
                    <th>Haltestelle</th><th>Richtung</th><th>Kurse</th><th>Zuletzt</th>
                </tr>
            </thead>
            <tbody>
                ${rows.map(m => `
                    <tr>
                        <td><strong>${m.hitCount}×</strong></td>
                        <td>${escHtml(m.line)}</td>
                        <td>${escHtml(m.dayType)}</td>
                        <td>${escHtml(m.hhmm)}</td>
                        <td>${escHtml(m.stopName)}</td>
                        <td>${escHtml(m.direction ?? '–')}</td>
                        <td>${escHtml(m.courses.join(' / '))}</td>
                        <td class="text-small">${escHtml(formatDateTime(m.lastSeen))}</td>
                    </tr>`).join('')}
            </tbody>
        </table>`;
}

/** "2026-08-17 12:59:35" (UTC aus der DB) → lokale Kurzform. */
function formatDateTime(value) {
    if (!value) return '–';
    const d = new Date(value.replace(' ', 'T') + 'Z');
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString('de-DE', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}
