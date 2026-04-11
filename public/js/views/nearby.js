/**
 * nearby.js – View: Haltestellen in der Nähe
 *
 * Ablauf:
 *  1. GPS-Position ermitteln (geolocation.js)
 *  2. Nahegelegene Tramhaltestellen vom Backend laden (api.js)
 *  3. Liste rendern, sortiert nach Entfernung (kommt bereits sortiert von der API)
 *  4. Tap auf Haltestelle → #departures?stopId=…&stopName=…
 */

import { getNearby }             from '../api.js';
import { getCurrentPosition }    from '../utils/geolocation.js';
import { escapeHtml, stripStopPrefix } from '../app.js';

export async function render(container, params, context) {
    showLoading(container, 'GPS-Position wird ermittelt…',
        'Bitte die Standortabfrage im Browser bestätigen.');

    // GPS-Position holen
    let position;
    try {
        position = await getCurrentPosition();
    } catch (gpsErr) {
        renderError(container, gpsErr.message, () => render(container, params, context));
        return;
    }

    const { latitude: lat, longitude: lon } = position.coords;

    showLoading(container, 'Haltestellen werden gesucht…');

    // API aufrufen
    let stops;
    try {
        stops = await getNearby(lat, lon, 10);
    } catch (apiErr) {
        renderError(
            container,
            `Haltestellen konnten nicht geladen werden: ${apiErr.message}`,
            () => render(container, params, context)
        );
        return;
    }

    if (!stops || stops.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <p>Keine Tramhaltestellen in der Nähe gefunden.</p>
                <p class="text-muted">Bitte auf dem Magdeburger Straßenbahnnetz befinden.</p>
            </div>`;
        return;
    }

    // Haltestellenliste rendern
    container.innerHTML = `
        <p class="nearby-meta text-small text-muted">
            ${stops.length} Haltestelle${stops.length !== 1 ? 'n' : ''} in der Nähe
        </p>
        <ul class="card-list" role="list" aria-label="Haltestellen in der Nähe">
            ${stops.map(stop => `
                <li class="card stop-item"
                    role="button"
                    tabindex="0"
                    data-stop-id="${escapeHtml(stop.id)}"
                    data-stop-name="${escapeHtml(stop.name)}"
                    aria-label="${escapeHtml(stripStopPrefix(stop.name))}, ${formatDistance(stop.distance)}">
                    <span class="stop-name">${escapeHtml(stripStopPrefix(stop.name))}</span>
                    <span class="stop-distance">${formatDistance(stop.distance)}</span>
                </li>`
            ).join('')}
        </ul>`;

    // Klick und Tastatur-Aktivierung
    const list = container.querySelector('[role="list"]');
    list.addEventListener('click',   handleStopSelect);
    list.addEventListener('keydown', handleStopKeydown);
}

export function destroy() {
    // Listener hängen am container, der beim nächsten render() neu gesetzt wird.
    // Keine persistenten Ressourcen (Timer, Observer) vorhanden.
}

// --- Event-Handler ----------------------------------------------------------

function handleStopSelect(e) {
    const item = e.target.closest('[data-stop-id]');
    if (!item) return;
    navigateToDepartures(item.dataset.stopId, item.dataset.stopName);
}

function handleStopKeydown(e) {
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        handleStopSelect(e);
    }
}

function navigateToDepartures(stopId, stopName) {
    const params = new URLSearchParams({ stopId, stopName });
    window.location.hash = `#departures?${params}`;
}

// --- Hilfsfunktionen --------------------------------------------------------

function formatDistance(meters) {
    if (meters >= 1000) {
        return `${(meters / 1000).toFixed(1)} km`;
    }
    return `${meters} m`;
}

function showLoading(container, message, hint = '') {
    container.innerHTML = `
        <div class="loading-indicator" aria-live="polite" aria-label="${escapeHtml(message)}">
            <div class="spinner" aria-hidden="true"></div>
            <p>${escapeHtml(message)}</p>
            ${hint ? `<p class="text-small text-muted">${escapeHtml(hint)}</p>` : ''}
        </div>`;
}

function renderError(container, message, onRetry) {
    container.innerHTML = `
        <div class="error-box" role="alert">${escapeHtml(message)}</div>
        <div class="mt-16">
            <button class="btn btn-secondary btn-full" id="btn-retry">
                Erneut versuchen
            </button>
        </div>`;
    container.querySelector('#btn-retry').addEventListener('click', onRetry);
}
