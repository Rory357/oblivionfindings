import { useCallback, useEffect, useRef } from 'react';

import {
    showUndoToast,
    type ShowUndoToastOptions,
    type UndoToastHandle,
} from '@/components/undo-toast';

/* -------------------------------------------------------------------------- */
/*  PR 21 — useUndoableAction                                                 */
/* -------------------------------------------------------------------------- */
/*
 * React wrapper around `showUndoToast` that keeps a single action "in
 * flight" per component. If the worker triggers the same action twice in
 * quick succession we flush the first one (commit it) before starting the
 * second, so no tap silently disappears. On unmount we also flush, so the
 * worker's intent is respected even if they navigate away.
 */

export function useUndoableAction() {
    const activeRef = useRef<UndoToastHandle | null>(null);

    const run = useCallback((opts: ShowUndoToastOptions): UndoToastHandle => {
        // Flush any prior pending action so we don't silently drop the
        // worker's previous tap.
        if (activeRef.current && !activeRef.current.settled) {
            activeRef.current.flush();
        }

        const handle = showUndoToast({
            ...opts,
            onCommit: () => {
                activeRef.current = null;
                opts.onCommit();
            },
            onUndo: () => {
                activeRef.current = null;
                opts.onUndo?.();
            },
        });
        activeRef.current = handle;
        return handle;
    }, []);

    useEffect(
        () => () => {
            // On unmount, commit rather than silently drop — the worker
            // already confirmed the action.
            if (activeRef.current && !activeRef.current.settled) {
                activeRef.current.flush();
            }
            activeRef.current = null;
        },
        [],
    );

    return { run };
}
