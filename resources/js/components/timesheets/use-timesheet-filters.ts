import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
export type TimesheetFilters = {
    tab: string;
    from: string | null;
    to: string | null;
    client_id: number | null;
    staff_id: number | null;
    search: string;
    page: number;
};

// Explicit Search/Enter avoids delayed searches escaping into a different week.
// A read in flight owns its controls; failed reads retain choices for retry.
export function useTimesheetFilters(server: TimesheetFilters) {
    const [draft, setDraft] = useState(server);
    const desired = useRef(server);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const pending = useRef(false);
    const mounted = useRef(true);
    const cancel = useRef<(() => void) | null>(null);
    const serverKey = JSON.stringify(server);
    useEffect(() => {
        const loaded = JSON.parse(serverKey) as TimesheetFilters;
        desired.current = loaded;
        setDraft(loaded);
        setError(null);
    }, [serverKey]);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            cancel.current?.();
        };
    }, []);
    const editSearch = (search: string) => {
        if (pending.current) return;
        desired.current = { ...desired.current, search };
        setDraft(desired.current);
    };
    const visit = (next: TimesheetFilters) => {
        if (pending.current) return false;
        const query = { ...next, search: next.search.trim() };
        desired.current = query;
        setDraft(query);
        if ([...query.search].length > 255) {
            setError(
                'Use 255 characters or fewer for your search. Your choices are retained.',
            );
            return false;
        }
        pending.current = true;
        setLoading(true);
        setError(null);
        let received = false;
        try {
            router.get('/operations/timesheets', query, {
                preserveState: true,
                preserveScroll: true,
                onCancelToken: (token) => {
                    cancel.current = () => token.cancel();
                },
                onSuccess: () => {
                    received = true;
                },
                onError: (errors) => {
                    if (mounted.current)
                        setError(
                            Object.values(errors).join(' ') ||
                                'Could not load timesheets. Your choices are retained.',
                        );
                },
                onFinish: () => {
                    if (!mounted.current) return;
                    pending.current = false;
                    cancel.current = null;
                    setLoading(false);
                    if (!received)
                        setError(
                            (current) =>
                                current ??
                                'Could not load timesheets. Your choices are retained; try again.',
                        );
                },
            });
        } catch {
            pending.current = false;
            setLoading(false);
            setError(
                'Could not load timesheets. Your choices are retained; try again.',
            );
        }
        return true;
    };
    return {
        draft,
        loading,
        error,
        editSearch,
        change: (updates: Partial<TimesheetFilters> = {}) =>
            visit({ ...desired.current, ...updates, page: 1 }),
        retry: () => visit(desired.current),
        page: (page: number) =>
            visit({
                ...desired.current,
                page:
                    desired.current.search.trim() === server.search ? page : 1,
            }),
        clear: () =>
            visit({
                tab: 'all',
                from: null,
                to: null,
                client_id: null,
                staff_id: null,
                search: '',
                page: 1,
            }),
    };
}
