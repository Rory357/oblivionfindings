import { useCallback, useEffect, useState } from 'react';

const SIDEBAR_COOKIE_NAME = 'sidebar_state';
const SIDEBAR_COOKIE_MAX_AGE = 60 * 60 * 24 * 30;
const SIDEBAR_STORAGE_KEY = 'oblivionfindings:sidebar-expanded';

function readCookie(name: string): string | null {
    if (typeof document === 'undefined') return null;

    const value = document.cookie
        .split('; ')
        .find((row) => row.startsWith(`${name}=`));

    return value ? decodeURIComponent(value.split('=')[1] ?? '') : null;
}

function persistSidebarExpanded(expanded: boolean) {
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(SIDEBAR_STORAGE_KEY, String(expanded));
    }

    if (typeof document !== 'undefined') {
        document.cookie = `${SIDEBAR_COOKIE_NAME}=${expanded}; path=/; max-age=${SIDEBAR_COOKIE_MAX_AGE}`;
    }
}

function readInitialExpanded(defaultExpanded: boolean): boolean {
    if (typeof window !== 'undefined') {
        const stored = window.localStorage.getItem(SIDEBAR_STORAGE_KEY);

        if (stored === 'true') return true;
        if (stored === 'false') return false;
    }

    const cookie = readCookie(SIDEBAR_COOKIE_NAME);

    if (cookie === 'true') return true;
    if (cookie === 'false') return false;

    return defaultExpanded;
}

export function useAppSidebarState(defaultExpanded = true) {
    const [expanded, setExpandedState] = useState(() =>
        readInitialExpanded(defaultExpanded),
    );

    useEffect(() => {
        if (typeof window === 'undefined') return;

        const stored = window.localStorage.getItem(SIDEBAR_STORAGE_KEY);

        if (stored === null) {
            setExpandedState(defaultExpanded);
        }
    }, [defaultExpanded]);

    const setExpanded = useCallback(
        (value: boolean | ((current: boolean) => boolean)) => {
            setExpandedState((current) => {
                const next =
                    typeof value === 'function' ? value(current) : value;
                persistSidebarExpanded(next);

                return next;
            });
        },
        [],
    );

    const toggleExpanded = useCallback(() => {
        setExpanded((current) => !current);
    }, [setExpanded]);

    return {
        collapsed: !expanded,
        expanded,
        setExpanded,
        toggleExpanded,
    };
}
