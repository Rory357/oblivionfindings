import { describe, expect, it } from 'vitest';

import {
    BRAND_CONTRAST_TARGET,
    BRAND_PRESETS,
    brandContrastChecks,
    brandContrastReport,
    contrastRatio,
    derivePalette,
    hexToOklch,
    INK_FOREGROUND,
    isVeryLightBrand,
    pickForeground,
    relativeLuminance,
    skyForeground,
    WHITE_FOREGROUND,
} from './derive-palette';

/** sRGB hex of INK_FOREGROUND, oklch(0.15 0.015 277). */
const INK_HEX = '#090b12';

const contrastOf = (hex: string, foreground: string) =>
    contrastRatio(
        relativeLuminance(hex),
        foreground === WHITE_FOREGROUND ? 1 : relativeLuminance(INK_HEX),
    );

describe('pickForeground — WCAG contrast, not a luminance cut-off', () => {
    it.each([
        // The five brand presets.
        [
            'nz-health-default',
            BRAND_PRESETS['nz-health-default'].hex,
            WHITE_FOREGROUND,
        ],
        ['high-contrast', BRAND_PRESETS['high-contrast'].hex, WHITE_FOREGROUND],
        ['warm', BRAND_PRESETS.warm.hex, INK_FOREGROUND],
        ['cool', BRAND_PRESETS.cool.hex, INK_FOREGROUND],
        ['forest', BRAND_PRESETS.forest.hex, INK_FOREGROUND],
        // Settings → Branding theme presets that are not brand presets.
        ['ocean blue', '#2563eb', WHITE_FOREGROUND],
        ['warm rose', '#f43f5e', INK_FOREGROUND],
    ])(
        '%s (%s) gets the higher-contrast foreground',
        (_name, hex, expected) => {
            expect(pickForeground(hex)).toBe(expected);
        },
    );

    it('keeps every brand preset at WCAG AA (4.5:1) or better', () => {
        for (const { hex } of Object.values(BRAND_PRESETS)) {
            expect(contrastOf(hex, pickForeground(hex))).toBeGreaterThanOrEqual(
                4.5,
            );
        }
    });

    it.each([
        ['#ffffff', INK_FOREGROUND],
        ['#fff', INK_FOREGROUND],
        ['#000000', WHITE_FOREGROUND],
        // #767676 is the classic 4.54:1-on-white grey; ink only reaches 4.33.
        ['#767676', WHITE_FOREGROUND],
        ['#808080', INK_FOREGROUND],
        ['#facc15', INK_FOREGROUND],
    ])('edge colour %s → %s', (hex, expected) => {
        expect(pickForeground(hex)).toBe(expected);
    });

    it('never picks the lower-contrast option across the grey ramp', () => {
        for (let v = 0; v <= 255; v += 5) {
            const hex = `#${v.toString(16).padStart(2, '0').repeat(3)}`;
            const picked = pickForeground(hex);
            const other =
                picked === WHITE_FOREGROUND ? INK_FOREGROUND : WHITE_FOREGROUND;
            expect(contrastOf(hex, picked)).toBeGreaterThanOrEqual(
                contrastOf(hex, other),
            );
        }
    });

    it('matches the luminance of the ink token it returns', () => {
        // INK_HEX is the 8-bit rounding of the oklch ink, so allow ±0.0005.
        expect(relativeLuminance(INK_HEX)).toBeCloseTo(0.00333, 3);
    });
});

describe('skyForeground — --primary-foreground (not band text)', () => {
    it('stays white for mid-light brands', () => {
        expect(skyForeground(BRAND_PRESETS.warm.hex)).toBe(WHITE_FOREGROUND);
        expect(skyForeground(BRAND_PRESETS.cool.hex)).toBe(WHITE_FOREGROUND);
        expect(skyForeground(BRAND_PRESETS.forest.hex)).toBe(WHITE_FOREGROUND);
    });

    it('only switches to ink for very light brands', () => {
        expect(skyForeground('#facc15')).toBe(INK_FOREGROUND);
        expect(skyForeground('#ffffff')).toBe(INK_FOREGROUND);
    });
});

describe('brandContrastReport — Settings → Branding guidance', () => {
    const minRatio = (hex: string) =>
        Math.min(...brandContrastChecks(hex).map((c) => c.ratio));

    it.each([
        ...Object.values(BRAND_PRESETS).map((p) => p.hex),
        // Settings → Branding theme presets.
        '#7c3aed',
        '#2563eb',
        '#059669',
        '#f43f5e',
    ])('keeps shipped preset %s readable in the header', (hex) => {
        expect(brandContrastReport(hex).passes).toBe(true);
    });

    it('passes a dark brand without a suggestion', () => {
        const report = brandContrastReport(BRAND_PRESETS['high-contrast'].hex);
        expect(report.passes).toBe(true);
        expect(report.suggestion).toBeNull();
    });

    it.each(['#facc15', '#fde68a', '#e0f2fe', '#f5f5f4', '#ffffff'])(
        'keeps every header check readable for very light brand %s (band text is always white)',
        (hex) => {
            expect(isVeryLightBrand(hex)).toBe(true);
            expect(minRatio(hex)).toBeGreaterThanOrEqual(BRAND_CONTRAST_TARGET);
            expect(brandContrastReport(hex).passes).toBe(true);
        },
    );

    it.each(['#facc15', '#ffffff', '#ea580c', '#0891b2', '#059669', '#0ea5e9'])(
        'suggests a darker shade of %s that passes every check',
        (hex) => {
            const { passes, suggestion } = brandContrastReport(hex);
            if (passes) return;
            expect(suggestion).not.toBeNull();
            expect(minRatio(suggestion!)).toBeGreaterThanOrEqual(
                BRAND_CONTRAST_TARGET,
            );
            expect(hexToOklch(suggestion!)[0]).toBeLessThan(hexToOklch(hex)[0]);
        },
    );

    it('round-trips hex through oklch', () => {
        const [L, C, h] = hexToOklch('#7c3aed');
        expect(L).toBeCloseTo(0.541, 2);
        expect(C).toBeCloseTo(0.247, 2);
        expect(h).toBeCloseTo(293, 0);
    });
});

describe('derivePalette', () => {
    it('keeps the sky foreground but picks the logo-tile foreground by contrast', () => {
        const palette = derivePalette('#ea580c');
        expect(palette['--primary']).toBe('#ea580c');
        expect(palette['--primary-foreground']).toBe(WHITE_FOREGROUND);
        expect(palette['--sidebar-primary-foreground']).toBe(INK_FOREGROUND);
    });
});
