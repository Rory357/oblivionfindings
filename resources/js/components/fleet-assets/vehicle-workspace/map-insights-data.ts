import { useCallback, useEffect, useState } from 'react';

export type JsonLoad = 'loading' | 'ready' | 'error' | 'unavailable';

/**
 * Load one of the vehicle workspace's JSON views. Data is never cached by the
 * browser (it can include driver-attributed information); the last result
 * stays on screen while a new filter loads.
 */
export function useWorkspaceJson<T>(url: string | null) {
    const [data, setData] = useState<T | null>(null);
    const [load, setLoad] = useState<JsonLoad>('loading');
    const [attempt, setAttempt] = useState(0);
    const [refreshing, setRefreshing] = useState(false);
    const reload = useCallback(() => {
        setRefreshing(true);
        setAttempt((value) => value + 1);
    }, []);
    useEffect(() => {
        if (!url) return;
        const controller = new AbortController();
        setRefreshing(true);
        fetch(url, {
            signal: controller.signal,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        })
            .then(async (response) => {
                if ([401, 403, 404].includes(response.status)) {
                    setLoad('unavailable');
                    return;
                }
                if (!response.ok) throw new Error(String(response.status));
                setData((await response.json()) as T);
                setLoad('ready');
            })
            .catch((error: unknown) => {
                if ((error as Error)?.name !== 'AbortError') setLoad('error');
            })
            .finally(() => {
                if (!controller.signal.aborted) setRefreshing(false);
            });
        return () => controller.abort();
    }, [url, attempt]);

    return { data, setData, load, reload, refreshing };
}

/** A per-tab choice kept for this browser session when storage allows. */
export function useSessionValue<T extends string>(
    key: string,
    initial: T,
    allowed: readonly T[],
): [T, (next: T) => void] {
    const [value, setValue] = useState<T>(() => {
        try {
            const saved = window.sessionStorage.getItem(key);
            return saved !== null &&
                (allowed as readonly string[]).includes(saved)
                ? (saved as T)
                : initial;
        } catch {
            return initial;
        }
    });
    const update = useCallback(
        (next: T) => {
            setValue(next);
            try {
                window.sessionStorage.setItem(key, next);
            } catch {
                // Storage can be unavailable (private windows); the choice still applies now.
            }
        },
        [key],
    );

    return [value, update];
}

/** Re-render on a steady beat so relative times (age, deadlines) stay current. */
export function useNow(intervalMs = 30_000): number {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), intervalMs);
        return () => window.clearInterval(timer);
    }, [intervalMs]);
    return now;
}
