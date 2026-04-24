/**
 * Palette derivation helpers — turn a single user-picked brand colour into a
 * coherent set of CSS custom properties.
 *
 * We rely on modern CSS: `--primary` stores the hex directly, and every
 * downstream token (category tints, ring, hover states) is derived in
 * app.css via `oklch(from var(--primary) …)` / `color-mix(in oklch, …)`.
 *
 * The one thing CSS can't easily do is pick a readable foreground (black vs
 * white) against an arbitrary hex, so we compute that here.
 */

export interface PaletteVars {
    '--primary': string;
    '--primary-foreground': string;
    '--accent': string;
    '--accent-foreground': string;
    '--ring': string;
    '--sidebar-primary': string;
    '--sidebar-primary-foreground': string;
    '--sidebar-ring': string;
    '--chart-1': string;
    '--chart-2': string;
    '--chart-3': string;
    '--chart-4': string;
    '--chart-5': string;
}

const HEX_RE = /^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;

function parseHex(input: string): { r: number; g: number; b: number } | null {
    const match = input.trim().match(HEX_RE);
    if (!match) return null;
    let hex = match[1];
    if (hex.length === 3) {
        hex = hex
            .split('')
            .map((c) => c + c)
            .join('');
    }
    const num = parseInt(hex, 16);
    return {
        r: (num >> 16) & 0xff,
        g: (num >> 8) & 0xff,
        b: num & 0xff,
    };
}

function normaliseHex(input: string): string {
    const match = input.trim().match(HEX_RE);
    if (!match) return '#7c3aed';
    const hex = match[1].length === 3
        ? match[1].split('').map((c) => c + c).join('')
        : match[1];
    return `#${hex.toLowerCase()}`;
}

/**
 * Relative luminance per WCAG 2.1. Returns 0..1.
 * Used to choose a readable foreground colour.
 */
export function relativeLuminance(hex: string): number {
    const rgb = parseHex(hex);
    if (!rgb) return 0.5;
    const channel = (c: number) => {
        const v = c / 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * channel(rgb.r) + 0.7152 * channel(rgb.g) + 0.0722 * channel(rgb.b);
}

/**
 * Pick black or white foreground for highest contrast against the given hex.
 * We output oklch strings to stay consistent with the rest of the token set.
 */
export function pickForeground(hex: string): string {
    return relativeLuminance(hex) > 0.5 ? 'oklch(0.15 0.015 277)' : 'oklch(1 0 0)';
}

/**
 * Derive all brand-dependent palette variables from a single brand hex.
 *
 * `--primary` is the hex itself (browser parses on read). `--ring`,
 * `--accent`, and chart slots are computed via CSS color-space functions at
 * resolve-time — we only need to pass strings that the browser accepts.
 */
export function derivePalette(brandHex: string): PaletteVars {
    const hex = normaliseHex(brandHex);
    const fg = pickForeground(hex);

    return {
        '--primary': hex,
        '--primary-foreground': fg,
        '--accent': `color-mix(in oklch, ${hex} 15%, transparent)`,
        '--accent-foreground': hex,
        '--ring': hex,
        '--sidebar-primary': hex,
        '--sidebar-primary-foreground': fg,
        '--sidebar-ring': `color-mix(in oklch, ${hex} 70%, white 30%)`,
        '--chart-1': hex,
        '--chart-2': `oklch(from ${hex} l c calc(h + 150))`,
        '--chart-3': `oklch(from ${hex} l c calc(h + 60))`,
        '--chart-4': `oklch(from ${hex} l c calc(h + 210))`,
        '--chart-5': `oklch(from ${hex} calc(l + 0.12) c calc(h + 30))`,
    };
}

/**
 * Apply a palette to the document by writing each CSS variable onto
 * documentElement. Returns a `revert()` callback that restores previous values.
 */
export function applyPalette(hex: string): () => void {
    const palette = derivePalette(hex);
    const root = document.documentElement;
    const previous: Partial<PaletteVars> = {};

    (Object.keys(palette) as (keyof PaletteVars)[]).forEach((key) => {
        previous[key] = root.style.getPropertyValue(key) as string;
        root.style.setProperty(key, palette[key]);
    });

    return () => {
        (Object.keys(palette) as (keyof PaletteVars)[]).forEach((key) => {
            const prev = previous[key];
            if (prev) {
                root.style.setProperty(key, prev);
            } else {
                root.style.removeProperty(key);
            }
        });
    };
}

export const DEFAULT_BRAND_HEX = '#7c3aed';

export const BRAND_PRESETS = {
    'nz-health-default': { hex: '#7c3aed', label: 'NZ Health Default' },
    'high-contrast': { hex: '#111827', label: 'High Contrast' },
    warm: { hex: '#ea580c', label: 'Warm Orange' },
    cool: { hex: '#0891b2', label: 'Cool Teal' },
    forest: { hex: '#059669', label: 'Forest Green' },
} as const;
