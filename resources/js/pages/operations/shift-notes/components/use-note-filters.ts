import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { NoteFilters } from './note-query';

// Explicit Search/Enter avoids delayed searches escaping into a different week.
// A read in flight owns its controls; failed reads retain choices for retry.
export function useNoteFilters(server: NoteFilters) {
    const [draft, setDraft] = useState(server);
    const desired = useRef(server);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const pending = useRef(false);
    const mounted = useRef(true);
    const cancel = useRef<(() => void) | null>(null);
    const serverKey = JSON.stringify(server);
    useEffect(() => {
        const loaded = JSON.parse(serverKey) as NoteFilters;
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
    const editSearch = (q: string) => {
        if (pending.current) return;
        desired.current = { ...desired.current, q };
        setDraft(desired.current);
    };
    const visit = (next: NoteFilters) => {
        if (pending.current) return false;
        const query = { ...next, q: next.q.trim() };
        desired.current = query;
        setDraft(query);
        if ([...query.q].length > 255) {
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
            router.get(
                '/operations/shift-notes',
                { ...query, flagged: query.flagged ? 1 : 0 },
                {
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
                                    'Could not load notes. Your choices are retained.',
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
                                    'Could not load notes. Your choices are retained; try again.',
                            );
                    },
                },
            );
        } catch {
            pending.current = false;
            setLoading(false);
            setError(
                'Could not load notes. Your choices are retained; try again.',
            );
        }
        return true;
    };
    return {
        draft,
        loading,
        error,
        editSearch,
        change: (updates: Partial<NoteFilters> = {}) =>
            visit({ ...desired.current, ...updates, page: 1 }),
        retry: () => visit(desired.current),
        clear: () =>
            visit({
                ...desired.current,
                q: '',
                author_id: null,
                client_id: null,
                site_id: null,
                type: null,
                date_from: null,
                date_to: null,
                flagged: false,
                status: 'all',
                page: 1,
            }),
    };
}
