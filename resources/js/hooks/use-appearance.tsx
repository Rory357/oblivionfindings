import { useCallback, useEffect, useState } from 'react';
import { applyPalette, DEFAULT_BRAND_HEX } from '@/lib/derive-palette';

export type Appearance = 'light' | 'dark' | 'system';
export type SidebarDensity = 'comfortable' | 'compact';

/**
 * Keys used in localStorage. Server preferences (via Inertia shared props)
 * win over these on page load; localStorage is the fast-path for immediate
 * subsequent reloads (avoids FOUC while Inertia hydrates).
 */
export const APPEARANCE_STORAGE = {
    theme: 'appearance',
    accent: 'accentColour',
    fontSize: 'fontSize',
    density: 'sidebarDensity',
    reduceMotion: 'reduceMotion',
} as const;

const prefersDark = () => {
    if (typeof window === 'undefined') return false;
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') return;
    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const applyTheme = (appearance: Appearance) => {
    const isDark =
        appearance === 'dark' || (appearance === 'system' && prefersDark());
    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
};

const applyAccent = (hex: string | null | undefined) => {
    if (!hex) return;
    applyPalette(hex);
};

const applyFontSize = (px: number | string | null | undefined) => {
    if (px === null || px === undefined) return;
    const n = typeof px === 'string' ? parseInt(px, 10) : px;
    if (!Number.isFinite(n) || n < 10 || n > 24) return;
    document.documentElement.style.setProperty('--base-font-size', `${n}px`);
};

const applySidebarDensity = (density: SidebarDensity | null | undefined) => {
    if (density !== 'compact' && density !== 'comfortable') return;
    document.documentElement.setAttribute('data-density', density);
};

const applyReduceMotion = (on: boolean) => {
    document.documentElement.classList.toggle('reduce-motion', !!on);
};

const mediaQuery = () => {
    if (typeof window === 'undefined') return null;
    return window.matchMedia('(prefers-color-scheme: dark)');
};

const handleSystemThemeChange = () => {
    const currentAppearance = localStorage.getItem(
        APPEARANCE_STORAGE.theme,
    ) as Appearance;
    applyTheme(currentAppearance || 'system');
};

/**
 * Read value from localStorage with a typed default. SSR-safe.
 */
function readLS(key: string): string | null {
    if (typeof window === 'undefined') return null;
    return localStorage.getItem(key);
}

function readLSBool(key: string): boolean {
    const v = readLS(key);
    return v === 'true';
}

/**
 * Apply every saved appearance preference from localStorage. Called at app
 * startup before first paint. Server-side preferences (via Inertia props)
 * are re-applied by the Appearance page on mount so the server remains the
 * source of truth when it disagrees with the cache.
 */
export function initializeAppearance() {
    const theme = (readLS(APPEARANCE_STORAGE.theme) as Appearance) || 'system';
    applyTheme(theme);

    applyAccent(readLS(APPEARANCE_STORAGE.accent));
    applyFontSize(readLS(APPEARANCE_STORAGE.fontSize));
    applySidebarDensity(
        readLS(APPEARANCE_STORAGE.density) as SidebarDensity | null,
    );
    applyReduceMotion(readLSBool(APPEARANCE_STORAGE.reduceMotion));

    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

/**
 * Backwards-compatible alias for callers that only care about theme init.
 */
export const initializeTheme = initializeAppearance;

export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>('system');

    const updateAppearance = useCallback((mode: Appearance) => {
        setAppearance(mode);
        localStorage.setItem(APPEARANCE_STORAGE.theme, mode);
        setCookie('appearance', mode);
        applyTheme(mode);
    }, []);

    const updateAccent = useCallback((hex: string) => {
        localStorage.setItem(APPEARANCE_STORAGE.accent, hex);
        applyAccent(hex);
    }, []);

    const updateFontSize = useCallback((px: number) => {
        localStorage.setItem(APPEARANCE_STORAGE.fontSize, String(px));
        applyFontSize(px);
    }, []);

    const updateSidebarDensity = useCallback((density: SidebarDensity) => {
        localStorage.setItem(APPEARANCE_STORAGE.density, density);
        applySidebarDensity(density);
    }, []);

    const updateReduceMotion = useCallback((on: boolean) => {
        localStorage.setItem(APPEARANCE_STORAGE.reduceMotion, String(on));
        applyReduceMotion(on);
    }, []);

    const resetAccent = useCallback(() => {
        localStorage.removeItem(APPEARANCE_STORAGE.accent);
        // Force browser to pick up the brand defaults from the Blade-injected
        // <style> block by removing our inline overrides.
        (Object.keys({
            '--primary': 0,
            '--primary-foreground': 0,
            '--accent': 0,
            '--accent-foreground': 0,
            '--ring': 0,
            '--sidebar-primary': 0,
            '--sidebar-primary-foreground': 0,
            '--sidebar-ring': 0,
            '--chart-1': 0,
            '--chart-2': 0,
            '--chart-3': 0,
            '--chart-4': 0,
            '--chart-5': 0,
        }) as string[]).forEach((key) => {
            document.documentElement.style.removeProperty(key);
        });
    }, []);

    useEffect(() => {
        const savedAppearance = localStorage.getItem(
            APPEARANCE_STORAGE.theme,
        ) as Appearance | null;

        // eslint-disable-next-line react-hooks/set-state-in-effect
        updateAppearance(savedAppearance || 'system');

        return () =>
            mediaQuery()?.removeEventListener(
                'change',
                handleSystemThemeChange,
            );
    }, [updateAppearance]);

    return {
        appearance,
        updateAppearance,
        updateAccent,
        updateFontSize,
        updateSidebarDensity,
        updateReduceMotion,
        resetAccent,
        DEFAULT_BRAND_HEX,
    } as const;
}
