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
    const hex =
        match[1].length === 3
            ? match[1]
                  .split('')
                  .map((c) => c + c)
                  .join('')
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
    return (
        0.2126 * channel(rgb.r) +
        0.7152 * channel(rgb.g) +
        0.0722 * channel(rgb.b)
    );
}

export const INK_FOREGROUND = 'oklch(0.15 0.015 277)';
export const WHITE_FOREGROUND = 'oklch(1 0 0)';

/** Relative luminance of INK_FOREGROUND (≈ #090b12). */
const INK_LUMINANCE = 0.00333;

/** WCAG 2.1 contrast ratio between two relative luminances. */
export function contrastRatio(a: number, b: number): number {
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

/**
 * Pick the ink or white foreground with the higher WCAG contrast against the
 * given hex. A fixed luminance cut-off (the old `> 0.5`) gave white text on
 * mid-light brands such as #ea580c (3.56:1) where ink reaches 5.53:1; the
 * real crossover is near luminance 0.18. Output is oklch, like the tokens.
 */
export function pickForeground(hex: string): string {
    const luminance = relativeLuminance(hex);
    return contrastRatio(1, luminance) >=
        contrastRatio(INK_LUMINANCE, luminance)
        ? WHITE_FOREGROUND
        : INK_FOREGROUND;
}

/**
 * Text for `--primary-foreground`: white unless the brand itself is very
 * light, then ink. It is NOT the text on the floored bands — the `.eh-header`
 * sky and the PageHero band use `--band-foreground`, which is always white
 * (app.css; they redefine primary-foreground inside themselves), because
 * they are dark whatever the brand. Solid fills that carry text use
 * `--primary-fill-foreground`, picked by contrast in app.css.
 */
export function skyForeground(hex: string): string {
    return relativeLuminance(hex) > 0.5 ? INK_FOREGROUND : WHITE_FOREGROUND;
}

/** A brand light enough that `--primary-foreground` turns ink. */
export function isVeryLightBrand(hex: string): boolean {
    return skyForeground(hex) === INK_FOREGROUND;
}

/* ------------------------------------------------------------------ */
/*  Brand contrast guidance (Settings → Branding)                      */
/* ------------------------------------------------------------------ */

type Rgb = [number, number, number];

const srgbToLinear = (v: number) =>
    v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
const linearToSrgb = (v: number) =>
    v <= 0.0031308 ? 12.92 * v : 1.055 * v ** (1 / 2.4) - 0.055;

function hexToRgb(hex: string): Rgb {
    const rgb = parseHex(hex) ?? { r: 124, g: 58, b: 237 };
    return [rgb.r / 255, rgb.g / 255, rgb.b / 255];
}

function rgbToHex(rgb: Rgb): string {
    return `#${rgb
        .map((v) =>
            Math.round(Math.min(1, Math.max(0, v)) * 255)
                .toString(16)
                .padStart(2, '0'),
        )
        .join('')}`;
}

/** sRGB hex → oklch [L 0–1, C, h degrees]. */
export function hexToOklch(hex: string): [number, number, number] {
    const [r, g, b] = hexToRgb(hex).map(srgbToLinear);
    const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
    const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
    const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
    const L = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
    const A = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
    const B = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;
    return [
        L,
        Math.hypot(A, B),
        ((Math.atan2(B, A) * 180) / Math.PI + 360) % 360,
    ];
}

function oklchToLinear(L: number, C: number, h: number): Rgb {
    const a = C * Math.cos((h * Math.PI) / 180);
    const b = C * Math.sin((h * Math.PI) / 180);
    const l = (L + 0.3963377774 * a + 0.2158037573 * b) ** 3;
    const m = (L - 0.1055613458 * a - 0.0638541728 * b) ** 3;
    const s = (L - 0.0894841775 * a - 1.291485548 * b) ** 3;
    return [
        4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
        -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
        -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
    ];
}

/** oklch → sRGB (0–1), gamut-mapped by reducing chroma as browsers do. */
function oklchToRgb(L: number, C: number, h: number): Rgb {
    const inGamut = (c: number) =>
        oklchToLinear(L, c, h).every((v) => v >= -1e-4 && v <= 1 + 1e-4);
    let chroma = C;
    if (!inGamut(chroma)) {
        let lo = 0;
        let hi = C;
        for (let i = 0; i < 24; i++) {
            const mid = (lo + hi) / 2;
            if (inGamut(mid)) lo = mid;
            else hi = mid;
        }
        chroma = lo;
    }
    return oklchToLinear(L, chroma, h).map((v) =>
        linearToSrgb(Math.min(1, Math.max(0, v))),
    ) as Rgb;
}

const luminanceOf = (rgb: Rgb) => {
    const [r, g, b] = rgb.map(srgbToLinear);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};

/** `fg` at `alpha` over `bg`, composited in sRGB like the browser. */
const over = (fg: Rgb, alpha: number, bg: Rgb): Rgb =>
    fg.map((v, i) => v * alpha + bg[i] * (1 - alpha)) as Rgb;

/**
 * Lightest the `.eh-header` sky's bottom stop can be (app.css). The rail's
 * inactive tab labels are 80% sky text on that floor (--eh-floor).
 */
export const SKY_BOTTOM_MAX_L = 0.46;

export type BrandContrastCheckId =
    | 'title'
    | 'subline'
    | 'mid'
    | 'rail'
    | 'heroButton';

export interface BrandContrastCheck {
    id: BrandContrastCheckId;
    label: string;
    ratio: number;
}

export interface BrandContrastReport {
    checks: BrandContrastCheck[];
    /** Every check is at WCAG AA (4.5:1) or better. */
    passes: boolean;
    /** The lightest darker shade of the same hue that passes, if any. */
    suggestion: string | null;
}

export const BRAND_CONTRAST_TARGET = 4.5;

/**
 * Contrast of the brand-dependent header text this colour produces, mirroring
 * app.css: the band text (--band-foreground, always white) on the sky's top
 * stop `oklch(from brand 0.3 c*0.6 h)` (title, 65% subline), its mid stop
 * `oklch(from brand 0.42 c*0.8 h)` (meter figures) and its bottom floor (80%
 * rail tab labels — the corner blooms take the same floor colour), and the
 * hero white button's --primary-strong text (`min(l, 0.5)`) on the white
 * band-foreground pill. Since band text stopped following the brand
 * (2026-10-03) these hold for very light brands too.
 */
export function brandContrastChecks(hex: string): BrandContrastCheck[] {
    const [L, C, h] = hexToOklch(hex);
    const skyText: Rgb = [1, 1, 1];
    const top = oklchToRgb(0.3, C * 0.6, h);
    const mid = oklchToRgb(0.42, C * 0.8, h);
    const bottom = oklchToRgb(Math.min(L, SKY_BOTTOM_MAX_L), C, h);
    const strong = oklchToRgb(Math.min(L, 0.5), C, h);
    const ratio = (a: Rgb, b: Rgb) =>
        contrastRatio(luminanceOf(a), luminanceOf(b));

    return [
        {
            id: 'title',
            label: 'Page header titles',
            ratio: ratio(skyText, top),
        },
        {
            id: 'subline',
            label: 'Page header descriptions',
            ratio: ratio(over(skyText, 0.65, top), top),
        },
        {
            id: 'mid',
            label: 'Page header figures',
            ratio: ratio(skyText, mid),
        },
        {
            id: 'rail',
            label: 'Page header tab labels',
            ratio: ratio(over(skyText, 0.8, bottom), bottom),
        },
        {
            id: 'heroButton',
            label: 'White header button text',
            ratio: ratio(strong, skyText),
        },
    ];
}

/** Darken (same hue and chroma) until every check passes; null if none does. */
export function suggestBrandShade(hex: string): string | null {
    const [L, C, h] = hexToOklch(hex);
    for (let l = L; l >= 0.15; l -= 0.005) {
        const candidate = rgbToHex(oklchToRgb(l, C, h));
        if (
            brandContrastChecks(candidate).every(
                (c) => c.ratio >= BRAND_CONTRAST_TARGET,
            )
        ) {
            return candidate;
        }
    }
    return null;
}

export function brandContrastReport(hex: string): BrandContrastReport {
    const checks = brandContrastChecks(hex);
    const passes = checks.every((c) => c.ratio >= BRAND_CONTRAST_TARGET);
    return {
        checks,
        passes,
        suggestion: passes ? null : suggestBrandShade(hex),
    };
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

    return {
        '--primary': hex,
        '--primary-foreground': skyForeground(hex),
        '--accent': `color-mix(in oklch, ${hex} 15%, transparent)`,
        '--accent-foreground': hex,
        '--ring': hex,
        '--sidebar-primary': hex,
        // The logo tile is a solid brand fill, so its text is picked by contrast.
        '--sidebar-primary-foreground': pickForeground(hex),
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
