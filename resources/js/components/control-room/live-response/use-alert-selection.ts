import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import type { AlertActionKey } from '@/components/control-room/alert-record/alert-actions';
import type { AlertWorkspaceDetail } from '@/components/control-room/alert-record/alert-sections';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

function alertParam(): number | null {
    if (typeof window === 'undefined') return null;
    const raw = new URLSearchParams(window.location.search).get('alert');
    const id = raw ? Number(raw) : NaN;
    return Number.isFinite(id) && id > 0 ? id : null;
}

/**
 * The selected alert on a Live response view. Selecting a row puts
 * `?alert=<id>` in the address (replaced, so Back still leaves the page) and
 * loads just that alert's details; the first row is selected when nothing
 * is. A row-menu action waits for its alert's details, then opens.
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
    const [selectedId, setSelectedId] = useState<number | null>(
        () => detail?.alert.id ?? alertParam(),
    );
    const pending = useRef<{ id: number; action: AlertActionKey } | null>(
        null,
    );

    const load = useCallback((id: number) => {
        const url = new URL(window.location.href);
        url.searchParams.set('alert', String(id));
        router.get(
            `${url.pathname}${url.search}`,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                // async: a background refresh never cancels this load.
                async: true,
                only: ['detail'],
            },
        );
    }, []);

    const select = useCallback(
        (id: number, action?: AlertActionKey) => {
            setSelectedId(id);
            pending.current = action ? { id, action } : null;
            if (detail?.alert.id === id) {
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

    // Nothing chosen yet: show the top of the worklist.
    useEffect(() => {
        if (selectedId === null && rows[0]) select(rows[0].id);
    }, [rows, selectedId, select]);

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
