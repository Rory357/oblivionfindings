import { useCallback, useEffect, useState } from 'react';

export type RecordLoad = 'loading' | 'ready' | 'error' | 'forbidden';

/**
 * One section of the person's record, from its JSON view (the Fleet
 * profile pattern: the page carries the summary, each section loads its
 * own records). Reloads when `refreshKey` changes and on demand. A null
 * `url` loads nothing.
 */
export function useRecordJson<T>(url: string | null, refreshKey = '') {
    const [state, setState] = useState<{
        url: string | null;
        data: T | null;
        load: RecordLoad;
    }>({ url: null, data: null, load: 'loading' });
    const [nonce, setNonce] = useState(0);

    useEffect(() => {
        if (!url) return;
        const controller = new AbortController();
        let cancelled = false;
        setState({ url, data: null, load: 'loading' });
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
                if (!cancelled) setState({ url, data, load: 'ready' });
            })
            .catch((error: unknown) => {
                if (cancelled || (error as Error)?.name === 'AbortError')
                    return;
                const status = (error as { status?: number })?.status;
                setState({
                    url,
                    data: null,
                    load:
                        status === 401 || status === 403 || status === 404
                            ? 'forbidden'
                            : 'error',
                });
            });
        return () => {
            cancelled = true;
            controller.abort();
        };
    }, [url, refreshKey, nonce]);

    const reload = useCallback(() => setNonce((value) => value + 1), []);

    // Never paint another person's data between a URL change and its effect.
    return {
        data: state.url === url ? state.data : null,
        load: state.url === url ? state.load : ('loading' as RecordLoad),
        reload,
    };
}
