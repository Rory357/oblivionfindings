import { ConfirmDialog } from '@/components/confirm-dialog';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/** Keep unsaved settings in memory until the user reviews leaving. */
export function useSettingsLeaveConfirmation(
    dirty: boolean,
    title = 'Discard unsaved settings?',
    description = 'Your unsaved entries will be discarded. A save already sent may still finish; review the saved settings before retrying it. Cancel to keep editing.',
    onDiscard?: () => void,
) {
    const [pending, setPending] = useState<{ run: () => void } | null>(null);
    const approved = useRef(false);
    const discardAndRun = (run: () => void) => {
        approved.current = true;
        try {
            onDiscard?.();
            run();
        } finally {
            approved.current = false;
        }
    };
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
        const remove = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (approved.current || visit.method !== 'get') return;
            event.preventDefault();
            setPending({ run: () => router.visit(visit.url, visit) });
        });
        return () => {
            window.removeEventListener('beforeunload', unload);
            remove();
        };
    }, [dirty]);

    return {
        request,
        discardAndRun,
        confirmation: (
            <ConfirmDialog
                open={dirty && pending !== null}
                onClose={() => setPending(null)}
                onConfirm={() => {
                    if (!dirty || !pending) return;
                    discardAndRun(pending.run);
                }}
                title={title}
                description={description}
                confirmText="Discard and continue"
            />
        ),
    };
}
