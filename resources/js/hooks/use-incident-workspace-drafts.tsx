import { ConfirmDialog } from '@/components/confirm-dialog';
import { useSafetyHistoryGuard } from '@/hooks/use-safety-history-guard';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

export type IncidentDraftState = { dirty: boolean; busy: boolean; uncertain?: boolean };

/** In-memory recovery only: do not persist sensitive follow-up text in browser storage. */
export function useIncidentWorkspaceDrafts(incidentId: number) {
    const [followup, setFollowup] = useState<IncidentDraftState>({
        dirty: false,
        busy: false,
    });
    const [correctiveAction, setCorrectiveAction] =
        useState<IncidentDraftState>({ dirty: false, busy: false });
    const [recordingWindow, setRecordingWindow] =
        useState<IncidentDraftState>({ dirty: false, busy: false });
    const [recordingReference, setRecordingReference] =
        useState<IncidentDraftState>({ dirty: false, busy: false });
    const [pending, setPending] = useState<{ run: () => void } | null>(null);
    const approved = useRef(false);
    const guarded =
        followup.dirty ||
        followup.busy ||
        correctiveAction.dirty ||
        correctiveAction.busy ||
        recordingWindow.dirty ||
        recordingWindow.busy ||
        !!recordingWindow.uncertain ||
        recordingReference.dirty ||
        recordingReference.busy ||
        !!recordingReference.uncertain;
    const busy = followup.busy || correctiveAction.busy || recordingWindow.busy || recordingReference.busy;
    const uncertain = !!recordingWindow.uncertain || !!recordingReference.uncertain;
    const onFollowupDraft = useCallback(
        (next: IncidentDraftState) => setFollowup(next),
        [],
    );
    const onCorrectiveActionDraft = useCallback(
        (next: IncidentDraftState) => setCorrectiveAction(next),
        [],
    );
    const onRecordingWindowDraft = useCallback(
        (next: IncidentDraftState) => setRecordingWindow(next),
        [],
    );
    const onRecordingReferenceDraft = useCallback(
        (next: IncidentDraftState) => setRecordingReference(next),
        [],
    );
    const request = useCallback((run: () => void) => {
        if (guarded) setPending({ run });
        else run();
    }, [guarded]);
    const historyRecovery = useSafetyHistoryGuard(guarded, request);

    useEffect(() => {
        if (!guarded) setPending(null);
    }, [guarded]);

    useEffect(() => {
        if (!guarded) return;
        const unload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', unload);
        const remove = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (approved.current || visit.method !== 'get') return;
            if (
                visit.url.origin === window.location.origin &&
                visit.url.pathname === `/incidents/${incidentId}` &&
                visit.preserveState === true
            )
                return;
            event.preventDefault();
            setPending({ run: () => router.visit(visit.url, visit) });
        });
        return () => {
            remove();
            window.removeEventListener('beforeunload', unload);
        };
    }, [guarded, incidentId]);

    return {
        onFollowupDraft,
        onCorrectiveActionDraft,
        onRecordingWindowDraft,
        onRecordingReferenceDraft,
        request,
        confirmation: (
            <>
            <ConfirmDialog
                open={guarded && pending !== null}
                onClose={() => setPending(null)}
                onConfirm={() => {
                    if (!guarded || !pending) return;
                    const run = pending.run;
                    setPending(null);
                    approved.current = true;
                    try {
                        run();
                    } finally {
                        approved.current = false;
                    }
                }}
                title={
                    uncertain
                        ? 'Leave before this submission is confirmed?'
                        : busy
                        ? 'Leave while incident work is saving?'
                        : 'Discard unsaved incident work?'
                }
                description={
                    uncertain
                        ? 'Your evidence submission may already be recorded. The original submission is retained only in this tab. Cancel to check its result before sending again.'
                        : busy
                        ? 'The request may still finish after you leave. Check the incident before sending again.'
                        : 'Your unsaved incident entries will be discarded. Cancel to keep editing.'
                }
                confirmText={
                    busy || uncertain ? 'Leave and check later' : 'Discard and continue'
                }
                frontline
            />
            {historyRecovery}
            </>
        ),
    };
}
