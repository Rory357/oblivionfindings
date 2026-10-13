import { onMedicationRecorded } from '@/lib/medication-record-events';
import { useCallback, useEffect, useState } from 'react';

export type RecordLoad = 'loading' | 'ready' | 'error' | 'forbidden';

/**
 * One section of the person's record, from its JSON view (the Fleet
 * profile pattern: the page carries the summary, each section loads its
 * own records). Reloads when `refreshKey` changes and on demand. A null
 * `url` loads nothing.
 *
 * EA-208 (Lane C9): a reload of the same section keeps what is on screen
 * (`refreshing`), and a failed reload keeps it too, marked `stale` with
 * the time it was loaded — like Meds today. Only a different URL (another
 * person or section) starts empty.
 */
export function useRecordJson<T>(url: string | null, refreshKey = '') {
    const [state, setState] = useState<{
        url: string | null;
        data: T | null;
        load: RecordLoad;
        refreshing: boolean;
        stale: boolean;
        loadedAt: number | null;
    }>({
        url: null,
        data: null,
        load: 'loading',
        refreshing: false,
        stale: false,
        loadedAt: null,
    });
    const [nonce, setNonce] = useState(0);

    useEffect(() => {
        if (!url) return;
        const controller = new AbortController();
        let cancelled = false;
        setState((previous) =>
            previous.url === url && previous.data !== null
                ? { ...previous, refreshing: true }
                : {
                      url,
                      data: null,
                      load: 'loading',
                      refreshing: false,
                      stale: false,
                      loadedAt: null,
                  },
        );
        fetch(url, {
            signal: controller.signal,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        })
            .then(async (response) => {
                if (!response.ok)
                    throw Object.assign(new Error('request failed'), {
                        status: response.status,
                    });
                const data = (await response.json()) as T;
                if (!cancelled)
                    setState({
                        url,
                        data,
                        load: 'ready',
                        refreshing: false,
                        stale: false,
                        loadedAt: Date.now(),
                    });
            })
            .catch((error: unknown) => {
                if (cancelled || (error as Error)?.name === 'AbortError')
                    return;
                const status = (error as { status?: number })?.status;
                const forbidden =
                    status === 401 || status === 403 || status === 404;
                setState((previous) =>
                    // A failed refresh keeps the last records, marked stale.
                    !forbidden && previous.url === url && previous.data !== null
                        ? { ...previous, refreshing: false, stale: true }
                        : {
                              url,
                              data: null,
                              load: forbidden ? 'forbidden' : 'error',
                              refreshing: false,
                              stale: false,
                              loadedAt: null,
                          },
                );
            });
        return () => {
            cancelled = true;
            controller.abort();
        };
    }, [url, refreshKey, nonce]);

    const reload = useCallback(() => setNonce((value) => value + 1), []);
    useEffect(() => {
        const clientId = Number(url?.match(/^\/emar\/clients\/(\d+)\//)?.[1]);
        if (clientId) return onMedicationRecorded(clientId, reload);
    }, [url, reload]);

    // Never paint another person's data between a URL change and its effect.
    return {
        data: state.url === url ? state.data : null,
        load: state.url === url ? state.load : ('loading' as RecordLoad),
        refreshing: state.url === url && state.refreshing,
        stale: state.url === url && state.stale,
        loadedAt: state.url === url ? state.loadedAt : null,
        reload,
    };
}
