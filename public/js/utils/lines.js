/**
 * lines.js – Linienfarben und -varianten für MVB-Straßenbahnlinien (Magdeburg)
 *
 * Je Linie werden Farbe und Darstellungsvariante konfiguriert.
 * Die Textfarbe (weiß/schwarz) wird bei inline automatisch berechnet.
 *
 * Varianten:
 *   inline  – Hintergrund in Linienfarbe, Schrift weiß oder schwarz (auto)
 *   outline – weißer Hintergrund, Rahmen und Schrift in Linienfarbe
 */

/**
 * Konfigurationstabelle: Linienbezeichnung → { color, variant }
 * Hier anpassen, ergänzen oder entfernen.
 *
 * @type {Record<string, { color: string, variant: 'inline'|'outline' }>}
 */
const LINE_CONFIG = {
    '1':  { color: '#c7135d', variant: 'inline'  },
    '2':  { color: '#3c64ad', variant: 'inline'  },
    '3':  { color: '#ffcd2a', variant: 'inline'  },
    '4':  { color: '#78c14f', variant: 'inline'  },
    '5':  { color: '#b86f2e', variant: 'inline'  },
    '6':  { color: '#523f95', variant: 'inline'  },
    '8':  { color: '#f89c2c', variant: 'outline' },
    '9':  { color: '#0e7563', variant: 'inline'  },
    '10': { color: '#008ac0', variant: 'inline'  },
    '13': { color: '#363932', variant: 'inline'  },
};

/**
 * HTML-String für einen Linien-Badge.
 * Farbe und Variante werden aus LINE_CONFIG gelesen.
 *
 * @param {string} line  Linienbezeichnung (z.B. "6")
 * @returns {string}
 */
export function lineBadgeHtml(line) {
    const cfg = LINE_CONFIG[String(line)];

    let style;
    if (!cfg) {
        // Linie nicht in der Tabelle → CSS-Defaultfarbe aus app.css
        style = '';
    } else if (cfg.variant === 'outline') {
        style = ` style="background:#fff;border-color:${cfg.color};color:${cfg.color}"`;
    } else {
        style = ` style="background:${cfg.color};border-color:${cfg.color};color:${contrastColor(cfg.color)}"`;
    }

    return `<span class="line-badge"${style}>${escHtml(line)}</span>`;
}

// =============================================================================
// Interne Hilfsfunktionen
// =============================================================================

/**
 * Relative Luminanz nach WCAG 2.1 (0 = schwarz, 1 = weiß).
 * @param {string} hex  6-stelliger Hex-Farbwert mit führendem '#'
 * @returns {number}
 */
function luminance(hex) {
    const r = parseInt(hex.slice(1, 3), 16) / 255;
    const g = parseInt(hex.slice(3, 5), 16) / 255;
    const b = parseInt(hex.slice(5, 7), 16) / 255;
    const lin = c => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
    return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

/**
 * Gibt '#fff' oder '#000' zurück – je nachdem was auf dem Hintergrund
 * besser lesbar ist (Schwellwert 0.179 ≈ WCAG AA).
 * @param {string} hex
 * @returns {string}
 */
function contrastColor(hex) {
    return luminance(hex) > 0.179 ? '#000' : '#fff';
}

/** Minimales HTML-Escaping für Inline-Verwendung */
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
