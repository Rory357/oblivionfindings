import { toast } from 'sonner';

/* -------------------------------------------------------------------------- */
/*  PR 21 — Undo toasts for irreversible frontline actions                    */
/* -------------------------------------------------------------------------- */
/*
 * A small, deliberately conservative undo pattern for important actions
 * where an accidental tap should be recoverable — e.g. clocking out or
 * sending a timesheet. We implement it as **delayed commit**: the UI shows
 * a short toast, and the real backend POST only fires when the timer
 * elapses. That keeps auditability trustworthy (nothing is written and
 * then "reversed" behind the worker's back) and it keeps us off the path
 * of inventing a compensating-reversal scheme per action.
 *
 * Sonner is already wired globally in `app.tsx`, so this helper piggybacks
 * on the existing toast infrastructure rather than introducing a second
 * notification system.
 */

export type UndoToastHandle = {
    /** Cancel the pending action — same effect as the worker tapping Undo. */
    cancel: () => void;
    /** Commit the action now instead of waiting for the timer. */
    flush: () => void;
    /** True once the action has either committed or been undone. */
    readonly settled: boolean;
};

export type ShowUndoToastOptions = {
    /** Short, plain-language pending message ("Clocking out…"). */
    message: string;
    /** How long the worker has to tap Undo. Default 5 s. */
    durationMs?: number;
    /** Fires when the timer expires with no Undo — do the real work here. */
    onCommit: () => void;
    /** Optional hook for worker-initiated cancellation. */
    onUndo?: () => void;
    /** Short confirmation message after Undo. Default "Cancelled.". */
    undoneMessage?: string;
};

export function showUndoToast({
    message,
    durationMs = 5000,
    onCommit,
    onUndo,
    undoneMessage = 'Cancelled.',
}: ShowUndoToastOptions): UndoToastHandle {
    let settled = false;

    const handle: UndoToastHandle = {
        get settled() {
            return settled;
        },
        cancel: () => finalise('cancel'),
        flush: () => finalise('commit'),
    };

    const timer = window.setTimeout(() => finalise('commit'), durationMs);

    const toastId = toast(message, {
        duration: durationMs,
        action: {
            label: 'Undo',
            onClick: () => finalise('undo'),
        },
    });

    function finalise(reason: 'commit' | 'undo' | 'cancel') {
        if (settled) return;
        settled = true;
        window.clearTimeout(timer);
        toast.dismiss(toastId);

        if (reason === 'commit') {
            onCommit();
            return;
        }

        // Worker asked to stop, or the caller cancelled (e.g. on unmount).
        if (undoneMessage) toast(undoneMessage);
        onUndo?.();
    }

    return handle;
}
