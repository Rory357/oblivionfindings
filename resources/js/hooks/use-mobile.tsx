import { useSyncExternalStore } from 'react';

const MOBILE_BREAKPOINT = 768;

let mobileMediaQuery: MediaQueryList | null = null;

function mediaQuery(): MediaQueryList | null {
    if (typeof window === 'undefined') return null;

    mobileMediaQuery ??= window.matchMedia(
        `(max-width: ${MOBILE_BREAKPOINT - 1}px)`,
    );

    return mobileMediaQuery;
}

function mediaQueryListener(callback: () => void) {
    const query = mediaQuery();
    if (!query) return () => undefined;

    query.addEventListener('change', callback);

    return () => {
        query.removeEventListener('change', callback);
    };
}

function isSmallerThanBreakpoint() {
    return mediaQuery()?.matches ?? false;
}

export function useIsMobile() {
    return useSyncExternalStore(
        mediaQueryListener,
        isSmallerThanBreakpoint,
        () => false,
    );
}

// Matches Tailwind's `lg:` breakpoint — used to skip MOUNTING desktop-only
// chrome (like the sidebar) that is merely CSS-hidden below lg.
const DESKTOP_LG_BREAKPOINT = 1024;

let desktopLgMediaQuery: MediaQueryList | null = null;

function desktopLgQuery(): MediaQueryList | null {
    if (typeof window === 'undefined') return null;

    desktopLgMediaQuery ??= window.matchMedia(
        `(min-width: ${DESKTOP_LG_BREAKPOINT}px)`,
    );

    return desktopLgMediaQuery;
}

function desktopLgListener(callback: () => void) {
    const query = desktopLgQuery();
    if (!query) return () => undefined;

    query.addEventListener('change', callback);

    return () => {
        query.removeEventListener('change', callback);
    };
}

export function useIsDesktopLg() {
    return useSyncExternalStore(
        desktopLgListener,
        () => desktopLgQuery()?.matches ?? true,
        () => true,
    );
}
