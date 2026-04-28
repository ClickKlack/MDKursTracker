/**
 * capture.js – View: Kursnummer erfassen
 *
 * Ablauf:
 *  1. Erfassungsdaten aus sessionStorage (pendingCapture) lesen
 *  2. Linie, Richtung, Abfahrtszeit als Kontext anzeigen
 *  3. Schnellbuttons 00–39 + Freitextfeld (00–99) anbieten
 *  4. POST /api/recordings → Bestätigungsfeedback
 *  5. Rückkehr zur Abfahrtstafel (#departures), die frische Daten lädt
 */

import { postRecording, getTrip, postTouchTrip } from '../api.js';
import { formatTime, calcDelay }   from '../utils/format.js';
import { lineBadgeHtml }           from '../utils/lines.js';
import { escapeHtml, stripStopPrefix, showActionSnackbar } from '../app.js';

/** Anzahl Schnellbuttons (01–40, 5 Zeilen à 8) */
const QUICK_COUNT = 40;

export async function render(container, _params, _context) {
    // Daten aus sessionStorage lesen
    const raw = sessionStorage.getItem('pendingCapture');
    if (!raw) {
        window.location.hash = '#nearby';
        return;
    }

    let data;
    try {
        data = JSON.parse(raw);
    } catch {
        sessionStorage.removeItem('pendingCapture');
        window.location.hash = '#nearby';
        return;
    }

    container.innerHTML = buildFormHtml(data);
    attachListeners(container, data);

    // Vorschlag aus der Abfahrtstafel sofort rendern (ohne Roundtrip).
    if (data.suggestedCourseNumber) {
        renderSuggestion(container, {
            number: data.suggestedCourseNumber,
            source: data.suggestedCourseSource ?? 'recorded',
        });
    }

    // Fahrtverlauf asynchron nachladen
    loadTripRoute(container, data);

    // Trip-Mapping per Fingerprint nachführen (best-effort, Fehler ignorieren).
    // Damit erscheint die zugeordnete Kursnummer auf der Abfahrtstafel auch dann,
    // wenn HAFAS für dieselbe Fahrt eine neue tripId/serviceNr ausgegeben hat –
    // ohne dass eine Erfassung gespeichert werden muss. Aktualisiert zudem den
    // Vorschlags-Badge, falls die Antwort einen besseren Wert liefert.
    touchTripMapping(container, data);
}

export function destroy() {
    // Keine Timer o.Ä. zu bereinigen – nur DOM-basierte Listener
}

// --- HTML aufbauen -----------------------------------------------------------

function buildFormHtml(data) {
    const time = data.departurePlanned ? formatTime(data.departurePlanned) : '–';

    return `
        <div class="capture-context card">
            <div class="capture-context-line">
                ${lineBadgeHtml(data.line)}
                <span class="capture-direction">${escapeHtml(data.direction)}</span>
            </div>
            <div class="text-small text-muted" style="margin-top:4px">
                ${escapeHtml(stripStopPrefix(data.stopName ?? ''))}
                &nbsp;·&nbsp;
                Abfahrt ${escapeHtml(time)} Uhr
            </div>
        </div>

        <p class="capture-prompt">Welche Kursnummer hat diese Bahn?</p>

        <div id="capture-suggestion" class="capture-suggestion" hidden></div>

        <div class="course-grid" role="group" aria-label="Kursnummer schnell auswählen">
            ${buildQuickButtons()}
        </div>

        <div class="capture-divider"></div>

        <div class="form-group">
            <label for="course-input">Andere Kursnummer (00–99)</label>
            <input
                type="number"
                id="course-input"
                min="0"
                max="99"
                placeholder="z.B. 25"
                inputmode="numeric"
                autocomplete="off"
            >
        </div>
        <button class="btn btn-primary btn-full" id="btn-submit" disabled>
            Erfassen
        </button>

        <div class="capture-divider"></div>

        <div id="capture-route">
            <div class="route-loading">
                <span class="spinner-small" aria-hidden="true"></span> Laufweg wird geladen…
            </div>
        </div>`;
}

function buildQuickButtons() {
    const buttons = [];
    for (let i = 0; i < QUICK_COUNT; i++) {
        const nr = String(i).padStart(2, '0');
        buttons.push(
            `<button class="course-btn" type="button"
                     data-course="${nr}"
                     aria-label="Kursnummer ${nr}">${nr}</button>`
        );
    }
    return buttons.join('');
}

// --- Event-Handler -----------------------------------------------------------

function attachListeners(container, data) {
    const input  = container.querySelector('#course-input');
    const btnSub = container.querySelector('#btn-submit');

    /** Aktuell gewählte Kursnummer (zweistellig, z.B. "07") oder null */
    let selectedCourse = null;

    /**
     * Kursnummer setzen, Buttons und Input synchronisieren.
     * @param {string} nr  zweistellig, z.B. "07"
     */
    function selectCourse(nr) {
        selectedCourse = nr;
        highlightButton(container, nr);
        // Input-Wert ohne führende Null darstellen (natürlicher als "07")
        input.value = String(parseInt(nr, 10));
        btnSub.disabled = false;
    }

    // Schnellbuttons (Klick auf Grid via Event Delegation)
    container.querySelector('.course-grid').addEventListener('click', e => {
        const btn = e.target.closest('.course-btn');
        if (!btn) return;
        selectCourse(btn.dataset.course);
        input.focus();
    });

    // Vorschlags-Badge: Klick übernimmt den Wert wie ein Schnellbutton
    container.querySelector('#capture-suggestion').addEventListener('click', e => {
        const btn = e.target.closest('.course-number');
        if (!btn || !btn.dataset.course) return;
        selectCourse(btn.dataset.course);
        input.focus();
    });

    // Freitext-Input
    input.addEventListener('input', () => {
        const val = input.value.trim();
        const n   = parseInt(val, 10);

        if (val === '' || isNaN(n) || n < 0 || n > 99) {
            selectedCourse = null;
            const padded = (!isNaN(n) && n >= 0 && n <= 99) ? String(n).padStart(2, '0') : null;
            highlightButton(container, padded);
            btnSub.disabled = true;
        } else {
            selectedCourse = String(n).padStart(2, '0');
            highlightButton(container, selectedCourse);
            btnSub.disabled = false;
        }
    });

    // Absenden per Button-Klick
    btnSub.addEventListener('click', async () => {
        if (!selectedCourse) return;
        await submitRecording(data, selectedCourse, btnSub);
    });

    // Absenden per Enter im Input-Feld
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !btnSub.disabled) {
            e.preventDefault();
            btnSub.click();
        }
    });

}

// --- Vorschlags-Badge --------------------------------------------------------

/**
 * Rendert oder ersetzt den Vorschlags-Badge über der Kursnummer-Grid.
 * @param {HTMLElement} container
 * @param {{number:string, source:'manual'|'recorded'|'heuristic'}} suggestion
 */
function renderSuggestion(container, suggestion) {
    const el = container.querySelector('#capture-suggestion');
    if (!el) return;

    const cls = suggestion.source === 'manual'    ? 'manual'
              : suggestion.source === 'heuristic' ? 'heuristic'
              :                                     'known';
    const label = suggestion.source === 'manual'    ? 'Manueller Wert'
                : suggestion.source === 'heuristic' ? 'Vermutet (Plan-Daten)'
                :                                     'Bekannter Wert';
    const title = suggestion.source === 'heuristic'
        ? 'Vermutete Kursnummer aus Plan-Daten – bitte prüfen'
        : 'Vorschlag übernehmen';

    el.innerHTML = `
        <span class="text-small text-muted">${escapeHtml(label)}:</span>
        <button type="button" class="course-number ${cls}"
                data-course="${escapeHtml(suggestion.number)}"
                title="${escapeHtml(title)}"
                aria-label="Vorschlag ${escapeHtml(suggestion.number)} übernehmen">
            ${escapeHtml(suggestion.number)}
        </button>`;
    el.hidden = false;
}

// --- Trip-Mapping nachführen -------------------------------------------------

async function touchTripMapping(container, data) {
    if (!data.hafasTripId || !data.serviceNr || !data.line) return;
    try {
        const res = await postTouchTrip({
            hafasTripId:      data.hafasTripId,
            serviceNr:        data.serviceNr,
            line:             data.line,
            stopId:           data.stopId,
            departurePlanned: data.departurePlanned,
        });

        // Vorschlag aktualisieren, wenn Touch einen Wert liefert, der nicht
        // identisch zum bereits angezeigten ist.
        if (res?.matched && res.activeCourseNumber) {
            const newSource = res.courseSource ?? 'recorded';
            if (data.suggestedCourseNumber !== res.activeCourseNumber
                || data.suggestedCourseSource !== newSource) {
                renderSuggestion(container, {
                    number: res.activeCourseNumber,
                    source: newSource,
                });
            }
        } else if (!res?.matched && res?.heuristicCourseNumber
                   && !data.suggestedCourseNumber) {
            // Abfahrtstafel hatte keinen Treffer, aber die Heuristik findet
            // doch noch einen – nachträglich anbieten.
            renderSuggestion(container, {
                number: res.heuristicCourseNumber,
                source: res.heuristicCourseSource ?? 'heuristic',
            });
        }
    } catch (err) {
        // Best-effort – Fehler dürfen die Erfassung nicht stören
        console.warn('[capture] touchTripMapping fehlgeschlagen:', err.message);
    }
}

// --- Fahrtverlauf laden & rendern --------------------------------------------

async function loadTripRoute(container, data) {
    const routeEl = container.querySelector('#capture-route');
    if (!routeEl) return;

    let stops;
    try {
        stops = await getTrip(data.hafasTripId);
    } catch (err) {
        routeEl.innerHTML = `<p class="route-error">${escapeHtml(err.message)}</p>`;
        return;
    }

    if (!stops || stops.length === 0) {
        routeEl.innerHTML = '';
        return;
    }

    // Erfassungshaltestelle ermitteln: ID + Planzeit
    const planTime = data.departurePlanned ? data.departurePlanned.slice(11, 16) : null; // "HH:MM"
    const recordingStopIdx = stops.findIndex(s => {
        if (s.stopId === data.stopId) return true;
        // Zeitabgleich als Fallback (unterschiedliche ID-Formate)
        if (planTime && s.departurePlanned) {
            return s.departurePlanned.slice(11, 16) === planTime;
        }
        return false;
    });

    const knownLines = stops.map(s => s.line).filter(l => l != null);
    const hasLineChange = new Set(knownLines).size > 1;

    let prevLine = null;
    const rows = stops.map((s, idx) => {
        const time     = s.departurePlanned ? formatTime(s.departurePlanned) : '–';
        const isStop   = idx === recordingStopIdx;
        const cls      = isStop ? ' route-stop--recording' : '';

        const delay    = calcDelay(s.departurePlanned, s.departureActual);
        const isLate   = delay !== null && delay > 0;
        const isEarly  = delay !== null && delay < 0;
        let delayHtml  = '';
        if (isLate) {
            delayHtml = ` <span class="dep-delay time-delayed">+${delay}</span>`;
        } else if (isEarly) {
            delayHtml = ` <span class="dep-delay time-early">−${Math.abs(delay)}</span>`;
        }

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
                <span class="route-stop-delay">${delayHtml}</span>
                <span class="route-stop-name">${escapeHtml(stripStopPrefix(s.stop))}</span>
                ${isStop ? '<span class="route-stop-marker" aria-label="Erfassungshaltestelle">●</span>' : ''}
            </li>`;
    }).join('');

    routeEl.innerHTML = `<ul class="route-list" aria-label="Laufweg">${rows}</ul>`;

    // Kein automatisches Scrollen – die Erfassungselemente oben bleiben im Fokus
}

/** Schnellbutton-Hervorhebung setzen. nr = null → alle deselektiert. */
function highlightButton(container, nr) {
    container.querySelectorAll('.course-btn').forEach(btn => {
        btn.classList.toggle('selected', btn.dataset.course === nr);
    });
}

// --- POST /api/recordings ----------------------------------------------------

async function submitRecording(data, courseNumber, btnSub) {
    btnSub.disabled = true;

    try {
        await postRecording({
            hafasTripId:      data.hafasTripId,
            serviceNr:        data.serviceNr,
            line:             data.line,
            direction:        data.direction,
            stopId:           data.stopId,
            serviceDate:      data.serviceDate,
            departurePlanned: data.departurePlanned,
            departureActual:  data.departureActual ?? null,
            courseNumber,
        });

        // Erfolg: sessionStorage leeren, Snackbar zeigen, zurück zur Abfahrtstafel.
        // Die Snackbar hängt an document.body und überlebt die Navigation – damit
        // sieht der Nutzer die Bestätigung auch noch in der Abfahrtstafel.
        sessionStorage.removeItem('pendingCapture');
        showActionSnackbar(`Kurs ${courseNumber} gespeichert`);

        // searchStopId: HAFAS-Kurz-ID der Haltestelle (Eingabe für /api/departures).
        // data.stopId: Lang-ID des Bahnsteigs (nur für die Erfassung relevant).
        // Fallback auf data.stopId für ältere pendingCapture-Datensätze.
        const stopId   = encodeURIComponent(data.searchStopId ?? data.stopId ?? '');
        const stopName = data.stopName ? encodeURIComponent(data.stopName) : '';
        window.location.hash = `#departures?stopId=${stopId}&stopName=${stopName}`;

    } catch (err) {
        showActionSnackbar(`Erfassung fehlgeschlagen: ${err.message}`, /* isError= */ true);
        // Button wieder freigeben, damit Nutzer es erneut versuchen kann
        btnSub.disabled = false;
    }
}
