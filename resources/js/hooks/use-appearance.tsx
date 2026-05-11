import { applyPalette, DEFAULT_BRAND_HEX } from '@/lib/derive-palette';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';
export type SidebarDensity = 'comfortable' | 'compact';

type ServerAppearance = {
    theme?: Appearance | null;
    accent_colour?: string | null;
    font_size?: number | null;
    sidebar_density?: SidebarDensity | null;
    reduce_motion?: boolean | null;
} | null;

interface AppearancePageProps extends Record<string, unknown> {
    appearance?: ServerAppearance;
}

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
    const currentAppearance = localStorage.getItem(APPEARANCE_STORAGE.theme);
    applyTheme(isAppearance(currentAppearance) ? currentAppearance : 'system');
};

let systemThemeListenerAttached = false;

const ensureSystemThemeListener = () => {
    if (systemThemeListenerAttached) return;
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
    systemThemeListenerAttached = true;
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

function isAppearance(value: unknown): value is Appearance {
    return value === 'light' || value === 'dark' || value === 'system';
}

function isSidebarDensity(value: unknown): value is SidebarDensity {
    return value === 'comfortable' || value === 'compact';
}

function resolveTheme(serverAppearance?: ServerAppearance): Appearance {
    if (isAppearance(serverAppearance?.theme)) {
        return serverAppearance.theme;
    }

    const stored = readLS(APPEARANCE_STORAGE.theme);

    return isAppearance(stored) ? stored : 'system';
}

function cachePreference(key: string, value: string | number | boolean | null) {
    if (typeof window === 'undefined') return;

    if (value === null) {
        localStorage.removeItem(key);
        return;
    }

    localStorage.setItem(key, String(value));
}

function applyAppearancePreferences(serverAppearance?: ServerAppearance) {
    const theme = resolveTheme(serverAppearance);
    applyTheme(theme);
    cachePreference(APPEARANCE_STORAGE.theme, theme);
    setCookie('appearance', theme);

    if (serverAppearance && 'accent_colour' in serverAppearance) {
        cachePreference(
            APPEARANCE_STORAGE.accent,
            serverAppearance.accent_colour ?? null,
        );
        if (serverAppearance.accent_colour) {
            applyAccent(serverAppearance.accent_colour);
        }
    } else {
        applyAccent(readLS(APPEARANCE_STORAGE.accent));
    }

    if (
        serverAppearance &&
        typeof serverAppearance.font_size === 'number'
    ) {
        cachePreference(APPEARANCE_STORAGE.fontSize, serverAppearance.font_size);
        applyFontSize(serverAppearance.font_size);
    } else {
        applyFontSize(readLS(APPEARANCE_STORAGE.fontSize));
    }

    if (isSidebarDensity(serverAppearance?.sidebar_density)) {
        cachePreference(
            APPEARANCE_STORAGE.density,
            serverAppearance.sidebar_density,
        );
        applySidebarDensity(serverAppearance.sidebar_density);
    } else {
        applySidebarDensity(
            readLS(APPEARANCE_STORAGE.density) as SidebarDensity | null,
        );
    }

    if (
        serverAppearance &&
        typeof serverAppearance.reduce_motion === 'boolean'
    ) {
        cachePreference(
            APPEARANCE_STORAGE.reduceMotion,
            serverAppearance.reduce_motion,
        );
        applyReduceMotion(serverAppearance.reduce_motion);
    } else {
        applyReduceMotion(readLSBool(APPEARANCE_STORAGE.reduceMotion));
    }

    return theme;
}

/**
 * Apply every saved appearance preference. Server-side preferences from
 * Inertia are authoritative; localStorage is only the anonymous/offline
 * fallback and is refreshed from the server when available.
 */
export function initializeAppearance(serverAppearance?: ServerAppearance) {
    applyAppearancePreferences(serverAppearance);
    ensureSystemThemeListener();
}

/**
 * Backwards-compatible alias for callers that only care about theme init.
 */
export const initializeTheme = initializeAppearance;

export function useAppearance() {
    const page = usePage<AppearancePageProps>();
    const rawServerAppearance = page.props.appearance ?? null;
    const hasServerAppearance = rawServerAppearance !== null;
    const serverTheme = rawServerAppearance?.theme;
    const serverAccent = rawServerAppearance?.accent_colour;
    const serverFontSize = rawServerAppearance?.font_size;
    const serverDensity = rawServerAppearance?.sidebar_density;
    const serverReduceMotion = rawServerAppearance?.reduce_motion;
    const [appearance, setAppearance] = useState<Appearance>(() =>
        resolveTheme(rawServerAppearance),
    );

    const updateAppearance = useCallback(
        (mode: Appearance, opts?: { persist?: boolean }) => {
            setAppearance(mode);
            localStorage.setItem(APPEARANCE_STORAGE.theme, mode);
            setCookie('appearance', mode);
            applyTheme(mode);
            // Auto-persist to the server so the choice survives logout/login
            // and other devices. Callers that are only hydrating from server
            // state pass { persist: false } to avoid a redundant round-trip.
            if (opts?.persist !== false && typeof window !== 'undefined') {
                router.put(
                    '/settings/appearance',
                    { theme: mode },
                    {
                        preserveScroll: true,
                        preserveState: true,
                        only: [],
                    },
                );
            }
        },
        [],
    );

    // Fire-and-forget save of one appearance field. Skipped during server
    // hydration ({ persist: false }) and during accent picker drag (handled
    // separately via debounce in the appearance page).
    const persistField = useCallback(
        (payload: Record<string, unknown>) => {
            if (typeof window === 'undefined') return;
            router.put('/settings/appearance', payload, {
                preserveScroll: true,
                preserveState: true,
                only: [],
            });
        },
        [],
    );

    const updateAccent = useCallback(
        (hex: string, opts?: { persist?: boolean }) => {
            localStorage.setItem(APPEARANCE_STORAGE.accent, hex);
            applyAccent(hex);
            // Accent colour is NOT auto-persisted here — the colour picker
            // fires on every drag, which would flood the server. The
            // appearance page debounces and persists on commit.
            if (opts?.persist === true) {
                persistField({ accent_colour: hex });
            }
        },
        [persistField],
    );

    const updateFontSize = useCallback(
        (px: number, opts?: { persist?: boolean }) => {
            localStorage.setItem(APPEARANCE_STORAGE.fontSize, String(px));
            applyFontSize(px);
            if (opts?.persist !== false) {
                persistField({ font_size: px });
            }
        },
        [persistField],
    );

    const updateSidebarDensity = useCallback(
        (density: SidebarDensity, opts?: { persist?: boolean }) => {
            localStorage.setItem(APPEARANCE_STORAGE.density, density);
            applySidebarDensity(density);
            if (opts?.persist !== false) {
                persistField({ sidebar_density: density });
            }
        },
        [persistField],
    );

    const updateReduceMotion = useCallback(
        (on: boolean, opts?: { persist?: boolean }) => {
            localStorage.setItem(APPEARANCE_STORAGE.reduceMotion, String(on));
            applyReduceMotion(on);
            if (opts?.persist !== false) {
                persistField({ reduce_motion: on });
            }
        },
        [persistField],
    );

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
        const serverAppearance = hasServerAppearance
            ? {
                  theme: serverTheme,
                  accent_colour: serverAccent,
                  font_size: serverFontSize,
                  sidebar_density: serverDensity,
                  reduce_motion: serverReduceMotion,
              }
            : null;

        // eslint-disable-next-line react-hooks/set-state-in-effect
        setAppearance(applyAppearancePreferences(serverAppearance));
        ensureSystemThemeListener();
    }, [
        hasServerAppearance,
        serverTheme,
        serverAccent,
        serverFontSize,
        serverDensity,
        serverReduceMotion,
    ]);

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
