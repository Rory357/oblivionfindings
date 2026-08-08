import { router } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';

interface UseFiltersOptions<T> {
    route: string;
    initial: T;
    preserveState?: boolean;
    preserveScroll?: boolean;
    replace?: boolean;
    debounceMs?: number;
}

interface UseFiltersReturn<T> {
    filters: T;
    updateFilter: <K extends keyof T>(key: K, value: T[K]) => void;
    updateFilters: (updates: Partial<T>) => void;
    resetFilters: () => void;
    setFilters: (filters: T) => void;
    isPending: boolean;
}

export function useFilters<T extends Record<string, any>>({
    route,
    initial,
    preserveState = true,
    preserveScroll = true,
    replace = true,
    debounceMs = 0,
}: UseFiltersOptions<T>): UseFiltersReturn<T> {
    const [filters, setFilters] = useState<T>(initial);
    const [isPending, setIsPending] = useState(false);
    const debounceTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const applyFilters = useCallback(
        (newFilters: T) => {
            setIsPending(true);

            router.get(
                route,
                // Remove null/undefined/empty values from URL
                Object.fromEntries(
                    Object.entries(newFilters).filter(([_, v]) => {
                        if (v === null || v === undefined) return false;
                        if (typeof v === 'string' && v === '') return false;
                        return true;
                    }),
                ),
                {
                    preserveState,
                    preserveScroll,
                    replace,
                    onFinish: () => setIsPending(false),
                },
            );
        },
        [route, preserveState, preserveScroll, replace],
    );

    const updateFilter = useCallback(
        <K extends keyof T>(key: K, value: T[K]) => {
            const newFilters = { ...filters, [key]: value };
            setFilters(newFilters);

            if (debounceMs > 0) {
                if (debounceTimer.current) {
                    clearTimeout(debounceTimer.current);
                }
                debounceTimer.current = setTimeout(() => {
                    applyFilters(newFilters);
                }, debounceMs);
            } else {
                applyFilters(newFilters);
            }
        },
        [filters, applyFilters, debounceMs],
    );

    const updateFilters = useCallback(
        (updates: Partial<T>) => {
            const newFilters = { ...filters, ...updates };
            setFilters(newFilters);

            if (debounceMs > 0) {
                if (debounceTimer.current) {
                    clearTimeout(debounceTimer.current);
                }
                debounceTimer.current = setTimeout(() => {
                    applyFilters(newFilters);
                }, debounceMs);
            } else {
                applyFilters(newFilters);
            }
        },
        [filters, applyFilters, debounceMs],
    );

    const resetFilters = useCallback(() => {
        setFilters(initial);
        applyFilters(initial);
    }, [initial, applyFilters]);

    return {
        filters,
        updateFilter,
        updateFilters,
        resetFilters,
        setFilters,
        isPending,
    };
}

export default useFilters;
