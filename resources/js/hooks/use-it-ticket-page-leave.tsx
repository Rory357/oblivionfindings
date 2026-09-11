import { ConfirmDialog } from '@/components/confirm-dialog';
import type { ThreadDraftState } from '@/components/it/ticket-thread';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/** Guard page handoff without receiving or persisting private reply content. */
export function useItTicketPageLeave({
    ticketId,
    actorId,
    additionalDirty = false,
}: {
    ticketId: number;
    actorId: number | null;
    additionalDirty?: boolean;
}) {
    const scope = `${ticketId}:${actorId}`;
    const [draft, setDraft] = useState<ThreadDraftState & { scope: string }>({
        scope,
        dirty: false,
        busy: false,
    });
    const [leave, setLeave] = useState<{
        scope: string;
        run: () => void;
    } | null>(null);
    const approved = useRef(false);
    const currentDraft =
        draft.scope === scope ? draft : { dirty: false, busy: false };
    const guarded = currentDraft.dirty || currentDraft.busy || additionalDirty;
    const onDraftStateChange = useCallback(
        (next: ThreadDraftState) => {
            setDraft((current) =>
                current.scope === scope &&
                current.dirty === next.dirty &&
                current.busy === next.busy
                    ? current
                    : { scope, ...next },
            );
        },
        [scope],
    );

    useEffect(() => {
        if (!guarded) return;
        const beforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', beforeUnload);
        const remove = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (visit.method !== 'get' || approved.current) return;
            // Section navigation retains the mounted composer; a fresh full
            // page visit to that same ticket still needs a decision.
            if (
                visit.url.origin === window.location.origin &&
                visit.url.pathname === `/it/tickets/${ticketId}` &&
                visit.preserveState === true
            )
                return;
            event.preventDefault();
            setLeave({ scope, run: () => router.visit(visit.url, visit) });
        });
        return () => {
            remove();
            window.removeEventListener('beforeunload', beforeUnload);
        };
    }, [guarded, scope, ticketId]);

    const pending = leave?.scope === scope && guarded ? leave : null;
    return {
        onDraftStateChange,
        confirmation: (
            <ConfirmDialog
                open={pending !== null}
                onClose={() => setLeave(null)}
                onConfirm={() => {
                    if (!pending) return;
                    approved.current = true;
                    try {
                        pending.run();
                    } finally {
                        approved.current = false;
                    }
                }}
                title={
                    currentDraft.busy
                        ? 'Leave while the reply is saving?'
                        : 'Discard unsaved ticket work?'
                }
                description={
                    currentDraft.busy
                        ? 'The request may still finish after you leave. Leaving does not cancel a submitted reply. Check the ticket before sending again.'
                        : 'Your unsent text, selected files and unsaved classification text on this page will be discarded. Cancel to keep editing.'
                }
                confirmText={
                    currentDraft.busy
                        ? 'Leave and check later'
                        : 'Discard and continue'
                }
            />
        ),
    };
}
