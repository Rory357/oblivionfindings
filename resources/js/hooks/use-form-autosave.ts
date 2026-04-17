import { useCallback, useEffect, useRef, useState } from 'react';

type Draft<T> = {
    data: T;
    meta: Record<string, unknown>;
    savedAt: number;
};

type Options = {
    key: string;
    debounceMs?: number;
    enabled?: boolean;
};

export function useFormAutosave<T extends Record<string, unknown>>(
    data: T,
    meta: Record<string, unknown>,
    { key, debounceMs = 500, enabled = true }: Options,
) {
    const [savedAt, setSavedAt] = useState<number | null>(null);
    const timerRef = useRef<number | null>(null);
    const latestRef = useRef<{ data: T; meta: Record<string, unknown> }>({ data, meta });
    latestRef.current = { data, meta };

    const writeNow = useCallback(() => {
        if (typeof window === 'undefined') return;
        try {
            const payload: Draft<T> = {
                data: latestRef.current.data,
                meta: latestRef.current.meta,
                savedAt: Date.now(),
            };
            window.localStorage.setItem(key, JSON.stringify(payload));
            setSavedAt(payload.savedAt);
        } catch {
            // Quota or serialisation failure — non-fatal.
        }
    }, [key]);

    useEffect(() => {
        if (!enabled) return;
        if (typeof window === 'undefined') return;
        if (timerRef.current) window.clearTimeout(timerRef.current);
        timerRef.current = window.setTimeout(writeNow, debounceMs);
        return () => {
            if (timerRef.current) window.clearTimeout(timerRef.current);
        };
    }, [data, meta, enabled, writeNow, debounceMs]);

    const load = useCallback((): Draft<T> | null => {
        if (typeof window === 'undefined') return null;
        try {
            const raw = window.localStorage.getItem(key);
            if (!raw) return null;
            return JSON.parse(raw) as Draft<T>;
        } catch {
            return null;
        }
    }, [key]);

    const clear = useCallback(() => {
        if (typeof window === 'undefined') return;
        try {
            window.localStorage.removeItem(key);
            setSavedAt(null);
        } catch {
            // ignore
        }
    }, [key]);

    const flush = useCallback(() => {
        if (timerRef.current) {
            window.clearTimeout(timerRef.current);
            timerRef.current = null;
        }
        writeNow();
    }, [writeNow]);

    return { savedAt, load, clear, flush };
}
