/**
 * products.js – Verkehrsmittel-Auswahl (Straßenbahn / Bus)
 *
 * Gemeinsame Einstellung für Haltestellensuche und Abfahrtstafel:
 * Standard ist nur Straßenbahn, Bus ist zusätzlich wählbar. Mindestens ein
 * Verkehrsmittel bleibt immer aktiv. Gespeichert in localStorage; ist der
 * Speicher nicht verfügbar, gilt die Auswahl nur bis zum Neuladen.
 */

const PRODUCTS_KEY = 'transport_products';

/** Reihenfolge = Reihenfolge der Buttons und im API-Parameter */
const ALL_PRODUCTS = ['tram', 'bus'];

const LABELS = { tram: 'Straßenbahn', bus: 'Bus' };

// Symbole im Stil der übrigen Inline-Icons (Lucide: tram-front, bus-front);
// die Straßenbahn trägt zur besseren Unterscheidung einen Stromabnehmer.
const ICONS = {
    tram: `
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 3V1.5"/><path d="M9 1.5h6"/>
            <rect width="16" height="15" x="4" y="4" rx="2"/>
            <path d="M4 11h16"/><path d="M12 4v7"/>
            <path d="M8 15h.01"/><path d="M16 15h.01"/>
            <path d="m8 19-2 3"/><path d="m18 22-2-3"/>
        </svg>`,
    bus: `
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 6 2 7"/><path d="M10 6h4"/><path d="m22 7-2-1"/>
            <rect width="16" height="16" x="4" y="3" rx="2"/>
            <path d="M4 11h16"/>
            <path d="M8 15h.01"/><path d="M16 15h.01"/>
            <path d="M6 19v2"/><path d="M18 21v-2"/>
        </svg>`,
};

let products = loadProducts();

function loadProducts() {
    try {
        const raw    = JSON.parse(localStorage.getItem(PRODUCTS_KEY) ?? 'null');
        const parsed = Array.isArray(raw) ? ALL_PRODUCTS.filter(p => raw.includes(p)) : [];
        return parsed.length ? parsed : ['tram'];
    } catch {
        return ['tram'];
    }
}

function saveProducts() {
    try {
        localStorage.setItem(PRODUCTS_KEY, JSON.stringify(products));
    } catch { /* z. B. privater Modus – Auswahl gilt bis zum Neuladen */ }
}

/** Aktive Verkehrsmittel, z.B. ['tram'] oder ['tram', 'bus'] */
export function getProducts() {
    return [...products];
}

/** Wert für den API-Parameter "products", z.B. "tram,bus" */
export function productsParam() {
    return products.join(',');
}

/**
 * Schaltet ein Verkehrsmittel um.
 * @returns {boolean} false, wenn das letzte aktive Verkehrsmittel abgewählt werden sollte
 */
export function toggleProduct(product) {
    if (!ALL_PRODUCTS.includes(product)) return false;
    if (products.includes(product)) {
        if (products.length === 1) return false;
        products = products.filter(p => p !== product);
    } else {
        products = ALL_PRODUCTS.filter(p => p === product || products.includes(p));
    }
    saveProducts();
    return true;
}

/** Bezeichnung der aktiven Auswahl für Leertexte, z.B. "Bus" oder "Straßenbahn/Bus" */
export function productsLabel() {
    return products.map(p => LABELS[p]).join('/');
}

/**
 * HTML der Umschalter-Gruppe. Mehrfachauswahl, daher aria-pressed je Button
 * statt einer Tab-Semantik.
 */
export function productToggleHtml() {
    const buttons = ALL_PRODUCTS.map(p => {
        const on = products.includes(p);
        return `
            <button type="button" class="btn-toggle ${on ? 'active' : ''}"
                    data-product="${p}" aria-pressed="${on}"
                    aria-label="${LABELS[p]}" title="${LABELS[p]}">
                ${ICONS[p]}
            </button>`;
    }).join('');

    return `<div class="product-toggle" role="group" aria-label="Verkehrsmittel">${buttons}</div>`;
}

/**
 * Click-Handler an eine mit productToggleHtml() gerenderte Gruppe hängen.
 * Der letzte aktive Button bleibt aktiv und wackelt kurz als Hinweis.
 *
 * @param {Element}  root      Container, der die Gruppe enthält
 * @param {Function} onChange  Wird nach jeder wirksamen Änderung aufgerufen
 */
export function attachProductToggle(root, onChange) {
    const group = root.querySelector('.product-toggle');
    if (!group) return;

    group.addEventListener('click', e => {
        const btn = e.target.closest('[data-product]');
        if (!btn) return;

        if (!toggleProduct(btn.dataset.product)) {
            btn.classList.remove('shake');
            void btn.offsetWidth; // Reflow, damit die Animation erneut startet
            btn.classList.add('shake');
            btn.title = 'Mindestens ein Verkehrsmittel muss ausgewählt sein';
            return;
        }

        group.querySelectorAll('[data-product]').forEach(b => {
            const on = products.includes(b.dataset.product);
            b.classList.toggle('active', on);
            b.setAttribute('aria-pressed', String(on));
            b.title = LABELS[b.dataset.product];
        });
        onChange(getProducts());
    });
}
