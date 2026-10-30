/* P05 has one book-review wizard and one server workflow. Older dashboard entry
 * points navigate to it, preserving the fixed person when supplied. */
import type { ClientOption } from './report-error-modal';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';

export function MedicationReviewModal({ open, onClose, initialClientId }: {
    open: boolean;
    onClose: () => void;
    clients: ClientOption[];
    initialClientId?: number | null;
}) {
    useEffect(() => {
        if (!open) return;
        onClose();
        router.get('/emar/reviews', { book: '1', ...(initialClientId ? { client_id: initialClientId } : {}) });
    }, [open, onClose, initialClientId]);
    return null;
}
