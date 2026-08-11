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
