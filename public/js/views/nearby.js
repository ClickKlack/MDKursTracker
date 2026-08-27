/**
 * nearby.js – View: Haltestellen suchen
 *
 * Drei Reiter: Favoriten | GPS | Name
 * Letzter Reiter wird in localStorage gespeichert.
 */

import { getNearby, getNearbyByName, getUserFavorites, postUserFavorite, deleteUserFavorite }
    from '../api.js';
import { getCurrentPosition } from '../utils/geolocation.js';
import { escapeHtml, stripStopPrefix } from '../app.js';

let favoriteIds = new Set();
let favorites   = [];

/** Aktueller Reiter: 'favorites' | 'gps' | 'name' */
let searchMode = localStorage.getItem('nearby_search_mode') ?? 'gps';
if (!['favorites', 'gps', 'name'].includes(searchMode)) searchMode = 'gps';

// Auto-Suche nach dem Tippen (Name-Reiter)
const AUTO_SEARCH_DELAY_MS   = 550;
const AUTO_SEARCH_MIN_CHARS  = 3;
let   debounceTimer          = null;
/** Monoton steigender Token; ältere Responses werden verworfen */
let   searchToken            = 0;

// Zuletzt aus der Namenssuche geöffnete Haltestellen
const RECENT_STOPS_KEY = 'nearby_recent_stops';
const RECENT_STOPS_CAP = 10;

// Gespeicherter Suchbegriff verfällt nach 5 Minuten
const QUERY_KEY      = 'nearby_name_query';
const QUERY_TIME_KEY = 'nearby_name_query_at';
const QUERY_TTL_MS   = 5 * 60 * 1000;

function cancelAutoSearch() {
    if (debounceTimer) { clearTimeout(debounceTimer); debounceTimer = null; }
}

export async function render(container, params, context) {
    container.innerHTML = buildShell();
    attachTabListeners(container, params, context);

    // Favoriten immer vorab laden (werden in allen Tabs benötigt)
    await loadFavorites();

    if (searchMode === 'favorites') {
        renderFavoritesTab(container);
    } else if (searchMode === 'gps') {
        await runGpsSearch(container, params, context);
    } else {
        const lastQuery = loadNameQuery();
        const inputEl   = container.querySelector('#stop-name-input');
        if (inputEl && lastQuery) {
            inputEl.value = lastQuery;
            inputEl.dispatchEvent(new Event('input'));
            await runNameSearch(container);
        } else {
            renderRecentStops(container);
            inputEl?.focus();
        }
    }
}

export function destroy() {
    cancelAutoSearch();
    searchToken++;  // laufenden Name-Suche-Response invalidieren
    favoriteIds = new Set();
    favorites   = [];
}

// --- Shell ------------------------------------------------------------------

function buildShell() {
    const isFav  = searchMode === 'favorites';
    const isGps  = searchMode === 'gps';
    const isName = searchMode === 'name';

    return `
        <div class="search-toggle" role="tablist" aria-label="Suchmodus wählen">
            <button class="btn-toggle ${isGps  ? 'active' : ''}" id="toggle-gps"
                    role="tab" aria-selected="${isGps}"  aria-controls="nearby-content">
                GPS
            </button>
            <button class="btn-toggle ${isName ? 'active' : ''}" id="toggle-name"
                    role="tab" aria-selected="${isName}" aria-controls="nearby-content">
                Name
            </button>
            <button class="btn-toggle ${isFav  ? 'active' : ''}" id="toggle-favorites"
                    role="tab" aria-selected="${isFav}"  aria-controls="nearby-content">
                Favoriten
            </button>
        </div>

        <div id="name-search-form" ${isName ? '' : 'hidden'}>
            <div class="name-search-row">
                <input type="search"
                       id="stop-name-input"
                       class="form-input"
                       placeholder="Haltestelle suchen…"
                       autocomplete="off"
                       inputmode="search"
                       aria-label="Haltestellenname eingeben">
                <button type="button"
                        class="btn-clear-search"
                        id="btn-clear-search"
                        aria-label="Suche leeren"
                        hidden>×</button>
                <button class="btn btn-primary btn-search" id="btn-search-name">Suchen</button>
            </div>
        </div>

        <div id="nearby-content"></div>`;
}

// --- Tab-Listener -----------------------------------------------------------

function attachTabListeners(container, params, context) {
    container.querySelector('#toggle-favorites')?.addEventListener('click', () => {
        setMode('favorites', container);
        container.querySelector('#name-search-form').hidden = true;
        renderFavoritesTab(container);
    });

    container.querySelector('#toggle-gps')?.addEventListener('click', async () => {
        setMode('gps', container);
        container.querySelector('#name-search-form').hidden = true;
        await runGpsSearch(container, params, context);
    });

    container.querySelector('#toggle-name')?.addEventListener('click', async () => {
        setMode('name', container);
        container.querySelector('#name-search-form').hidden = false;
        const nameInput = container.querySelector('#stop-name-input');
        // Steht noch ein Suchbegriff im Feld, dessen Treffer wiederholen – sonst Zuletzt-Liste
        if (nameInput?.value.trim()) {
            await runNameSearch(container);
        } else {
            renderRecentStops(container);
        }
        nameInput?.focus();
    });

    container.querySelector('#btn-search-name')?.addEventListener('click', async () => {
        await runNameSearch(container);
    });

    const inputEl  = container.querySelector('#stop-name-input');
    const clearBtn = container.querySelector('#btn-clear-search');

    inputEl?.addEventListener('keydown', async e => {
        if (e.key === 'Enter') { e.preventDefault(); await runNameSearch(container); }
    });

    // Bei jedem Tastendruck: Clear-Button toggeln + Auto-Suche neu einplanen
    inputEl?.addEventListener('input', () => {
        if (clearBtn) clearBtn.hidden = inputEl.value === '';

        cancelAutoSearch();
        const query = inputEl.value.trim();

        // Leeres Feld: laufende Anfrage verwerfen und Zuletzt-Liste zeigen
        if (query === '') {
            searchToken++;
            clearNameQuery();
            renderRecentStops(container);
            return;
        }

        if (query.length >= AUTO_SEARCH_MIN_CHARS) {
            debounceTimer = setTimeout(() => {
                debounceTimer = null;
                runNameSearch(container);
            }, AUTO_SEARCH_DELAY_MS);
        }
    });

    clearBtn?.addEventListener('click', () => {
        if (!inputEl) return;
        cancelAutoSearch();
        searchToken++;  // laufende Anfrage invalidieren
        inputEl.value = '';
        clearNameQuery();
        renderRecentStops(container);
        clearBtn.hidden = true;
        inputEl.focus();
    });
}

function setMode(mode, container) {
    searchMode = mode;
    localStorage.setItem('nearby_search_mode', mode);
    updateTabHighlight(container);
}

function updateTabHighlight(container) {
    ['favorites', 'gps', 'name'].forEach(m => {
        const btn = container.querySelector(`#toggle-${m}`);
        if (!btn) return;
        btn.classList.toggle('active', searchMode === m);
        btn.setAttribute('aria-selected', String(searchMode === m));
    });
}

// --- Favoriten-Reiter -------------------------------------------------------

async function loadFavorites() {
    if (!localStorage.getItem('user_token')) return;
    try {
        favorites   = await getUserFavorites();
        favoriteIds = new Set(favorites.map(f => f.stopId));
    } catch {
        favorites   = [];
        favoriteIds = new Set();
    }
}

function renderFavoritesTab(container) {
    const contentEl = container.querySelector('#nearby-content');

    if (favorites.length === 0) {
        contentEl.innerHTML = `
            <div class="empty-state">
                <p>Noch keine Favoriten gespeichert.</p>
                <p class="text-muted">Tippe beim GPS- oder Namens-Reiter auf ☆ neben einer Haltestelle.</p>
            </div>`;
        return;
    }

    contentEl.innerHTML = `
        <ul class="fav-list" role="list" aria-label="Meine Favoriten">
            ${favorites.map(f => renderFavItem(f)).join('')}
        </ul>`;

    contentEl.querySelector('ul')?.addEventListener('click', e => handleFavClick(e, container));
}

function renderFavItem(fav) {
    const name = escapeHtml(stripStopPrefix(fav.stopName));
    return `
        <li class="fav-item"
            role="button"
            tabindex="0"
            data-stop-id="${escapeHtml(fav.stopId)}"
            data-stop-name="${escapeHtml(fav.stopName)}"
            aria-label="${name}">
            <span class="fav-icon" aria-hidden="true">⭐</span>
            <span class="fav-name">${name}</span>
            <button class="fav-remove-btn"
                    data-stop-id="${escapeHtml(fav.stopId)}"
                    aria-label="${name} aus Favoriten entfernen"
                    title="Favorit entfernen">×</button>
        </li>`;
}

async function handleFavClick(e, container) {
    const removeBtn = e.target.closest('.fav-remove-btn');
    if (removeBtn) {
        e.stopPropagation();
        const stopId = removeBtn.dataset.stopId;
        try {
            await deleteUserFavorite(stopId);
            favoriteIds.delete(stopId);
            favorites = favorites.filter(f => f.stopId !== stopId);
        } catch { return; }
        renderFavoritesTab(container);
        return;
    }
    const item = e.target.closest('[data-stop-id]');
    if (item) navigateToDepartures(item.dataset.stopId, item.dataset.stopName);
}

// --- GPS-Suche --------------------------------------------------------------

async function runGpsSearch(container, params, context) {
    const contentEl = container.querySelector('#nearby-content');
    showLoading(contentEl, 'GPS-Position wird ermittelt…',
        'Bitte die Standortabfrage im Browser bestätigen.');

    let position;
    try {
        position = await getCurrentPosition();
    } catch (gpsErr) {
        renderError(contentEl, gpsErr.message, () => runGpsSearch(container, params, context));
        return;
    }

    const { latitude: lat, longitude: lon } = position.coords;
    showLoading(contentEl, 'Haltestellen werden gesucht…');

    let stops;
    try {
        stops = await getNearby(lat, lon, 10);
    } catch (apiErr) {
        renderError(contentEl, `Haltestellen konnten nicht geladen werden: ${apiErr.message}`,
            () => runGpsSearch(container, params, context));
        return;
    }

    if (!stops?.length) {
        contentEl.innerHTML = `
            <div class="empty-state">
                <p>Keine Tramhaltestellen in der Nähe gefunden.</p>
                <p class="text-muted">Bitte auf dem Magdeburger Straßenbahnnetz befinden.</p>
            </div>`;
        return;
    }

    renderStopList(contentEl, stops, true);
}

// --- Name-Suche -------------------------------------------------------------

async function runNameSearch(container) {
    cancelAutoSearch();  // ggf. noch laufenden Debounce-Timer stoppen

    const input     = container.querySelector('#stop-name-input');
    const query     = input?.value?.trim() ?? '';
    const contentEl = container.querySelector('#nearby-content');

    if (!query) {
        searchToken++;  // laufende Anfrage invalidieren
        clearNameQuery();
        renderRecentStops(container);
        input?.focus();
        return;
    }

    const myToken = ++searchToken;
    saveNameQuery(query);
    showLoading(contentEl, 'Haltestellen werden gesucht…');

    let stops;
    try {
        stops = await getNearbyByName(query, 10);
    } catch (err) {
        if (myToken !== searchToken) return;  // veralteter Response
        renderError(contentEl, `Suche fehlgeschlagen: ${err.message}`,
            () => runNameSearch(container));
        return;
    }

    if (myToken !== searchToken) return;  // zwischenzeitlich neue Anfrage gestartet

    if (!stops?.length) {
        contentEl.innerHTML = `
            <div class="empty-state">
                <p>Keine Tramhaltestellen für „${escapeHtml(query)}" gefunden.</p>
                <p class="text-muted">Tipp: Nur den kurzen Haltestellennamen eingeben, z.B. „Hauptbahnhof".</p>
            </div>`;
        return;
    }

    renderStopList(contentEl, stops, false);
}

// --- Suchbegriff mit Verfallszeit -------------------------------------------

function saveNameQuery(query) {
    try {
        localStorage.setItem(QUERY_KEY, query);
        localStorage.setItem(QUERY_TIME_KEY, String(Date.now()));
    } catch {
        // Quota voll – ignorieren, der Begriff wird dann eben nicht gemerkt
    }
}

/** Liefert den gespeicherten Suchbegriff oder '' – abgelaufene werden verworfen */
function loadNameQuery() {
    const query = localStorage.getItem(QUERY_KEY) ?? '';
    if (!query) return '';

    const savedAt = Number(localStorage.getItem(QUERY_TIME_KEY));
    if (!Number.isFinite(savedAt) || Date.now() - savedAt > QUERY_TTL_MS) {
        clearNameQuery();  // fehlender/unbrauchbarer Zeitstempel gilt als abgelaufen
        return '';
    }
    return query;
}

function clearNameQuery() {
    localStorage.removeItem(QUERY_KEY);
    localStorage.removeItem(QUERY_TIME_KEY);
}

// --- Zuletzt geöffnete Haltestellen -----------------------------------------

function loadRecentStops() {
    try {
        const raw = localStorage.getItem(RECENT_STOPS_KEY);
        if (!raw) return [];
        const arr = JSON.parse(raw);
        if (!Array.isArray(arr)) return [];
        return arr
            .filter(s => s && typeof s.id === 'string' && s.id !== ''
                           && typeof s.name === 'string' && s.name !== '')
            .slice(0, RECENT_STOPS_CAP);
    } catch {
        return [];
    }
}

function addRecentStop(stop) {
    if (!stop?.id || !stop?.name) return;

    // Neueste zuerst, Dedupe über die Stop-ID
    const list = loadRecentStops().filter(s => s.id !== stop.id);
    list.unshift({ id: stop.id, name: stop.name });

    try {
        localStorage.setItem(RECENT_STOPS_KEY,
            JSON.stringify(list.slice(0, RECENT_STOPS_CAP)));
    } catch {
        // Quota voll – ignorieren
    }
}

function renderRecentStops(container) {
    const contentEl = container.querySelector('#nearby-content');
    if (!contentEl) return;

    const recent = loadRecentStops();

    if (recent.length === 0) {
        contentEl.innerHTML = `
            <div class="empty-state">
                <p>Noch keine Haltestellen geöffnet.</p>
                <p class="text-muted">Oben nach einer Haltestelle suchen – die zuletzt
                   geöffneten erscheinen dann hier.</p>
            </div>`;
        return;
    }

    contentEl.innerHTML = `
        <p class="nearby-meta text-small text-muted">Zuletzt geöffnet</p>
        <ul class="card-list" role="list" aria-label="Zuletzt geöffnete Haltestellen">
            ${recent.map(stop => renderStopItem(stop, false)).join('')}
        </ul>`;

    attachStopListHandlers(contentEl);
}

// --- Haltestellenliste (GPS + Name) -----------------------------------------

function renderStopList(contentEl, stops, showDistance) {
    contentEl.innerHTML = `
        <p class="nearby-meta text-small text-muted">
            ${stops.length} Haltestelle${stops.length !== 1 ? 'n' : ''} gefunden
        </p>
        <ul class="card-list" role="list" aria-label="Haltestellen">
            ${stops.map(stop => renderStopItem(stop, showDistance)).join('')}
        </ul>`;

    attachStopListHandlers(contentEl);
}

/** Delegierte Listener für Haltestellenlisten (Suchtreffer + Zuletzt-Liste) */
function attachStopListHandlers(contentEl) {
    const ul = contentEl.querySelector('ul');
    ul?.addEventListener('click',   e => handleStopClick(e));
    ul?.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); handleStopClick(e); }
    });
}

function renderStopItem(stop, showDistance) {
    const name  = stripStopPrefix(stop.name);
    const isFav = favoriteIds.has(stop.id);

    const distHtml = showDistance && stop.distance != null
        ? `<span class="stop-distance">${formatDistance(stop.distance)}</span>`
        : '';

    const starHtml = `
        <button class="btn-star ${isFav ? 'btn-star--active' : ''}"
                data-stop-id="${escapeHtml(stop.id)}"
                data-stop-name="${escapeHtml(stop.name)}"
                aria-label="${isFav ? 'Favorit entfernen' : 'Als Favorit speichern'}"
                title="${isFav ? 'Favorit entfernen' : 'Als Favorit speichern'}">
            ${isFav ? '⭐' : '☆'}
        </button>`;

    return `
        <li class="card stop-item"
            role="button"
            tabindex="0"
            data-stop-id="${escapeHtml(stop.id)}"
            data-stop-name="${escapeHtml(stop.name)}"
            aria-label="${escapeHtml(name)}${showDistance && stop.distance != null ? ', ' + formatDistance(stop.distance) : ''}">
            <div class="stop-item-row">
                <span class="stop-name">${escapeHtml(name)}</span>
                <span class="stop-actions">
                    ${distHtml}
                    ${starHtml}
                </span>
            </div>
        </li>`;
}

async function handleStopClick(e) {
    const starBtn = e.target.closest('.btn-star');
    if (starBtn) {
        e.stopPropagation();
        const stopId   = starBtn.dataset.stopId;
        const stopName = starBtn.dataset.stopName;
        const isFav    = favoriteIds.has(stopId);
        try {
            if (isFav) {
                await deleteUserFavorite(stopId);
                favoriteIds.delete(stopId);
                favorites = favorites.filter(f => f.stopId !== stopId);
            } else {
                await postUserFavorite(stopId, stopName);
                favoriteIds.add(stopId);
                favorites.push({ stopId, stopName });
            }
        } catch { return; }

        // Stern im aktuellen Item aktualisieren
        const nowFav = favoriteIds.has(stopId);
        starBtn.textContent = nowFav ? '⭐' : '☆';
        starBtn.classList.toggle('btn-star--active', nowFav);
        starBtn.setAttribute('aria-label', nowFav ? 'Favorit entfernen' : 'Als Favorit speichern');
        return;
    }

    const item = e.target.closest('[data-stop-id]');
    if (!item) return;

    // Nur aus der Namenssuche geöffnete Haltestellen merken (nicht GPS/Favoriten)
    if (searchMode === 'name') {
        addRecentStop({ id: item.dataset.stopId, name: item.dataset.stopName });
    }
    navigateToDepartures(item.dataset.stopId, item.dataset.stopName);
}

// --- Hilfsfunktionen --------------------------------------------------------

function navigateToDepartures(stopId, stopName) {
    window.location.hash = `#departures?${new URLSearchParams({ stopId, stopName })}`;
}

function formatDistance(meters) {
    return meters >= 1000 ? `${(meters / 1000).toFixed(1)} km` : `${meters} m`;
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
            <button class="btn btn-secondary btn-full" id="btn-retry">Erneut versuchen</button>
        </div>`;
    container.querySelector('#btn-retry')?.addEventListener('click', onRetry);
}
