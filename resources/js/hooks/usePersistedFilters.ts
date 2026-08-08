import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';

interface UsePersistedFiltersOptions {
    route: string;
    defaults?: Record<string, any>;
}

const EMPTY_FILTER_DEFAULTS: Record<string, any> = {};

export function usePersistedFilters({
    route: routeName,
    defaults = EMPTY_FILTER_DEFAULTS,
}: UsePersistedFiltersOptions) {
    const params = useMemo(() => {
        const url = new URL(window.location.href);
        const result: Record<string, string> = {};
        url.searchParams.forEach((value, key) => {
            result[key] = value;
        });
        return { ...defaults, ...result };
    }, [defaults]);

    const setFilter = useCallback((key: string, value: any) => {
        const url = new URL(window.location.href);
        if (value === '' || value === null || value === undefined) {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, String(value));
        }
        // Reset to page 1 when filters change
        if (key !== 'page') {
            url.searchParams.delete('page');
        }
        router.get(
            url.pathname + url.search,
            {},
            { preserveState: true, preserveScroll: true },
        );
    }, []);

    const setFilters = useCallback((filters: Record<string, any>) => {
        const url = new URL(window.location.href);
        Object.entries(filters).forEach(([key, value]) => {
            if (value === '' || value === null || value === undefined) {
                url.searchParams.delete(key);
            } else {
                url.searchParams.set(key, String(value));
            }
        });
        url.searchParams.delete('page');
        router.get(
            url.pathname + url.search,
            {},
            { preserveState: true, preserveScroll: true },
        );
    }, []);

    const resetFilters = useCallback(() => {
        const url = new URL(window.location.href);
        const keysToRemove: string[] = [];
        url.searchParams.forEach((_, key) => keysToRemove.push(key));
        keysToRemove.forEach((key) => url.searchParams.delete(key));
        router.get(
            url.pathname,
            {},
            { preserveState: true, preserveScroll: true },
        );
    }, []);

    const activeFilterCount = useMemo(() => {
        return Object.entries(params).filter(
            ([key, value]) =>
                key !== 'page' &&
                value !== '' &&
                value !== null &&
                value !== undefined &&
                value !== defaults[key],
        ).length;
    }, [params, defaults]);

    return {
        filters: params,
        setFilter,
        setFilters,
        resetFilters,
        activeFilterCount,
    };
}
