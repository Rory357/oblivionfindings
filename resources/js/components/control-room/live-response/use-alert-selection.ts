import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import type { AlertActionKey } from '@/components/control-room/alert-record/alert-actions';
import type { AlertWorkspaceDetail } from '@/components/control-room/alert-record/alert-sections';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

function alertParam(): number | null {
    if (typeof window === 'undefined') return null;
    const raw = new URLSearchParams(window.location.search).get('alert');
    const id = raw ? Number(raw) : NaN;
    return Number.isFinite(id) && id > 0 ? id : null;
}

/** Reloads that may put a dropped selection back, per alert (no loops). */
const MAX_RESTORES = 3;

/**
 * The selected alert on a Live response view. Selecting a row puts
 * `?alert=<id>` in the address (replaced, so Back still leaves the page) and
 * loads just that alert's details; the first row is selected when nothing
 * is. A row-menu action waits for its alert's details, then opens.
 *
 * The shell loads its deferred props (the inbox) a moment after a page
 * opens by reloading the page's FIRST address, which drops `?alert=` and,
 * on the next refresh, the details. When the address loses the selection
 * it is put back, so the preview never sticks on "loading".
 */
export function useAlertSelection({
    rows,
    detail,
    runAction,
}: {
    rows: AlertWorklistRow[];
    detail: AlertWorkspaceDetail | null;
    runAction: (key: AlertActionKey) => void;
}) {
    const { url } = usePage();
    const [selectedId, setSelectedId] = useState<number | null>(
        () => detail?.alert.id ?? alertParam(),
    );
    const pending = useRef<{ id: number; action: AlertActionKey } | null>(
        null,
    );
    const loading = useRef<number | null>(null);
    const restores = useRef(new Map<number, number>());

    const load = useCallback((id: number) => {
        const next = new URL(window.location.href);
        next.searchParams.set('alert', String(id));
        loading.current = id;
        router.get(
            `${next.pathname}${next.search}`,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                // async: a background refresh never cancels this load.
                async: true,
                only: ['detail'],
                onFinish: () => {
                    if (loading.current === id) loading.current = null;
                },
            },
        );
    }, []);

    const select = useCallback(
        (id: number, action?: AlertActionKey) => {
            setSelectedId(id);
            restores.current.delete(id);
            pending.current = action ? { id, action } : null;
            if (detail?.alert.id === id && alertParam() === id) {
                if (action) {
                    pending.current = null;
                    runAction(action);
                }
                return;
            }
            load(id);
        },
        [detail, load, runAction],
    );

    useEffect(() => {
        const inList = selectedId !== null && rows.some((r) => r.id === selectedId);
        const deepLinked = selectedId !== null && detail?.alert.id === selectedId;

        // Nothing chosen, or the chosen alert left this list (a filter, a
        // refresh): show the top of the list instead.
        if (selectedId === null || (!inList && !deepLinked)) {
            if (rows[0]) select(rows[0].id);
            return;
        }

        // The address lost the selection (see above): put it back.
        if (alertParam() !== selectedId && loading.current !== selectedId) {
            const used = restores.current.get(selectedId) ?? 0;
            if (used >= MAX_RESTORES) return;
            restores.current.set(selectedId, used + 1);
            load(selectedId);
        }
    }, [url, rows, detail, selectedId, select, load]);

    // A row-menu action opens once its alert has loaded.
    useEffect(() => {
        const next = pending.current;
        if (next && detail?.alert.id === next.id) {
            pending.current = null;
            runAction(next.action);
        }
    }, [detail, runAction]);

    const row = rows.find((candidate) => candidate.id === selectedId) ?? null;
    return { selectedId, select, row };
}
