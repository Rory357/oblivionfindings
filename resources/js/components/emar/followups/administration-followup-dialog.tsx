import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import { MedicationFollowupDialog } from './followup-dialog';
import type { MedicationFollowup } from './types';

/** Thin legacy/person adapter; the canonical dialog owns every clinical entry. */
export function AdministrationFollowupDialog({
    administrationId,
    onClose,
}: {
    administrationId: number;
    onClose: () => void;
}) {
    const [id, setId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        axios
            .post<MedicationFollowup>(
                `/medication-followups/administrations/${administrationId}/prepare`,
                { type: 'effect' },
                { signal: controller.signal },
            )
            .then((result) => setId(result.data.id))
            .catch((failure) => {
                if (!controller.signal.aborted)
                    setError(
                        failure.response?.status === 404
                            ? 'This dose is no longer available for an effect check.'
                            : 'Couldn’t open this follow-up. Try again.',
                    );
            });
        return () => controller.abort();
    }, [administrationId, attempt]);
    if (id)
        return (
            <MedicationFollowupDialog
                id={id}
                onClose={onClose}
                onSaved={() => router.reload({ preserveScroll: true })}
            />
        );
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="frontline-dialog max-h-[88vh] overflow-y-auto"
                style={{
                    width: 'min(92vw, 480px)',
                    maxWidth: 'min(92vw, 480px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>Medication effect check</DialogTitle>
                    <DialogDescription>
                        Opening the dose follow-up.
                    </DialogDescription>
                </DialogHeader>
                {error ? (
                    <ErrorState
                        message={error}
                        onRetry={() => {
                            setError(null);
                            setAttempt((n) => n + 1);
                        }}
                    />
                ) : (
                    <LoadingState message="Opening follow-up…" />
                )}
            </DialogContent>
        </Dialog>
    );
}
