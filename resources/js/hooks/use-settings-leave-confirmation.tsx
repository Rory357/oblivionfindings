import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    draftHistoryNavigation,
    registerDraftHistoryGuard,
} from '@/lib/draft-history';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/** Keep unsaved settings in memory until the user reviews leaving. */
export function useSettingsLeaveConfirmation(
    dirty: boolean,
    title = 'Discard unsaved settings?',
    description = 'Your unsaved entries will be discarded. A save already sent may still finish; review the saved settings before retrying it. Cancel to keep editing.',
    onDiscard?: () => void,
    saving = false,
) {
    const [pending, setPending] = useState<{
        run: () => void;
        cancel?: () => void;
    } | null>(null);
    const approved = useRef(false);
    const preserveAndRun = (run: () => void) => {
        approved.current = true;
        try {
            run();
        } finally {
            approved.current = false;
        }
    };
    const discardAndRun = (run: () => void) =>
        preserveAndRun(() => {
            onDiscard?.();
            run();
        });
    const request = useCallback(
        (run: () => void) => {
            if (dirty) setPending({ run });
            else run();
        },
        [dirty],
    );
    useEffect(() => {
        if (!dirty) setPending(null);
    }, [dirty]);

    useEffect(() => {
        if (!dirty) return;
        const unload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', unload);
        const removeHistory = registerDraftHistoryGuard((run, cancel) =>
            setPending({ run, cancel }),
        );
        const remove = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (approved.current || visit.method !== 'get') return;
            event.preventDefault();
            setPending({ run: () => router.visit(visit.url, visit) });
        });
        return () => {
            window.removeEventListener('beforeunload', unload);
            removeHistory();
            remove();
        };
    }, [dirty]);

    return {
        request,
        historyProtectionAvailable: draftHistoryNavigation() !== null,
        discardAndRun,
        preserveAndRun,
        confirmation: (
            <ConfirmDialog
                open={dirty && pending !== null}
                onClose={() => {
                    if (saving) return;
                    pending?.cancel?.();
                    setPending(null);
                }}
                onConfirm={() => {
                    if (saving || !dirty || !pending) return;
                    discardAndRun(pending.run);
                }}
                title={saving ? 'Saving your changes…' : title}
                description={
                    saving
                        ? 'Please wait for the current save to finish. Your writing stays here.'
                        : description
                }
                processing={saving ? true : undefined}
                confirmText="Discard and continue"
            />
        ),
    };
}
