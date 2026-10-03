import { ConfirmDialog } from '@/components/confirm-dialog';
import { useEffect, useState } from 'react';

export function useDraftClose(
    dirty: boolean,
    busy: boolean,
    onClose: () => void,
) {
    const [confirm, setConfirm] = useState(false);
    useEffect(() => {
        if (!dirty) return;
        const keepDraft = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', keepDraft);
        return () => window.removeEventListener('beforeunload', keepDraft);
    }, [dirty]);
    return {
        requestClose: () => {
            if (busy) return;
            if (dirty) setConfirm(true);
            else onClose();
        },
        confirmation: (
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={onClose}
                title="Discard your unsaved entries?"
                description="These entries have not been confirmed as saved. Stay here to keep them and retry."
                confirmText="Discard entries"
                cancelText="Keep editing"
            />
        ),
    };
}
