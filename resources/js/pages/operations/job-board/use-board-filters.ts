import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export type BoardFilters = {
    q?: string;
    status?: string;
    scope?: string;
    date_range?: string;
    skill?: string;
    fit?: string;
    week?: string;
    page?: string;
};

// Search is deliberate. A single read owns its controls until it finishes;
// failed reads retain the requested choices, rather than showing false results.
export function useBoardFilters(server: BoardFilters, actorKey: string) {
    const [draft, setDraft] = useState(server);
    const desired = useRef(server);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const pending = useRef(false);
    const epoch = useRef(0);
    const mounted = useRef(true);
    const cancel = useRef<(() => void) | null>(null);
    const serverKey = JSON.stringify(server);

    useEffect(() => {
        epoch.current += 1;
        cancel.current?.();
        cancel.current = null;
        pending.current = false;
        setLoading(false);
        const loaded = JSON.parse(serverKey) as BoardFilters;
        desired.current = loaded;
        setDraft(loaded);
        setError(null);
        // A newly loaded query or account replaces this search context only.
        // Held write outcomes belong to their separate owning workflow.
    }, [actorKey, serverKey]);

    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            epoch.current += 1;
            cancel.current?.();
        };
    }, []);

    const editSearch = (q: string) => {
        if (pending.current) return;
        desired.current = { ...desired.current, q };
        setDraft(desired.current);
    };

    const visit = (next: BoardFilters) => {
        if (pending.current) return false;
        const q = (next.q ?? '').trim();
        next.q = q;
        desired.current = next;
        setDraft(next);
        if ([...q].length > 255) {
            setError('Use 255 characters or fewer. Your search is retained.');
            return false;
        }

        const requestEpoch = ++epoch.current;
        const active = () => mounted.current && epoch.current === requestEpoch;
        const query: Record<string, string> = {};
        for (const [key, value] of Object.entries(next)) {
            if (value !== undefined && value !== '') query[key] = value;
        }
        let received = false;
        pending.current = true;
        setLoading(true);
        setError(null);
        try {
            router.get('/operations/job-board', query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onCancelToken: (token) => {
                    if (active()) cancel.current = () => token.cancel();
                    else token.cancel();
                },
                onSuccess: () => {
                    if (active()) received = true;
                },
                onError: () => {
                    if (active())
                        setError(
                            'Results were not updated. Your choices are retained; select Search to retry.',
                        );
                },
                onFinish: () => {
                    if (!active()) return;
                    pending.current = false;
                    cancel.current = null;
                    setLoading(false);
                    if (!received)
                        setError(
                            'Results were not updated. Your choices are retained; select Search to retry.',
                        );
                },
            });
        } catch {
            if (active()) {
                pending.current = false;
                setLoading(false);
                setError(
                    'Results were not updated. Your choices are retained; select Search to retry.',
                );
            }
        }
        return true;
    };

    const change = (updates: Partial<BoardFilters> = {}) => {
        const next = { ...desired.current, ...updates };
        delete next.page;
        if (updates.scope === 'mine') delete next.status;
        return visit(next);
    };
    const goPage = (url: string) => {
        if (pending.current) return false;
        try {
            const target = new URL(url, window.location.origin);
            const page = target.searchParams.get('page');
            if (
                target.origin !== window.location.origin ||
                target.pathname !== '/operations/job-board' ||
                !page ||
                !/^[1-9]\d*$/.test(page)
            )
                throw new Error('Invalid Job Board page');
            return visit({ ...desired.current, page });
        } catch {
            setError(
                'Could not open that page. Your choices are retained; select Search to retry.',
            );
            return false;
        }
    };

    return { draft, editSearch, change, goPage, loading, error };
}
