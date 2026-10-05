import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { InfoCard } from '@/components/wizard/primitives';
import { AlertTriangle, ExternalLink } from 'lucide-react';
import { useEffect, useState, type MutableRefObject } from 'react';
import type { AttendanceOutcome } from './use-attendance-command';

export function AttendanceCommandFeedback({
    outcome,
}: {
    outcome: AttendanceOutcome | null;
}) {
    if (!outcome || outcome.status === 'confirmed') return null;
    return (
        <div role="alert" className="mb-4">
            <InfoCard
                icon={AlertTriangle}
                tone={outcome.status === 'unknown' ? 'warn' : 'crit'}
            >
                <p>{outcome.message}</p>
                {outcome.status === 'unknown' && (
                    <Button
                        asChild
                        variant="outline"
                        className="frontline-tap mt-3"
                    >
                        <a
                            href="/attendance"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <ExternalLink className="size-4" /> Check attendance
                            in a new tab
                        </a>
                    </Button>
                )}
            </InfoCard>
        </div>
    );
}

export function useAttendanceClose({
    onClose,
    dirty,
    pending,
    busy,
    outcome,
}: {
    onClose: () => void;
    dirty: boolean;
    pending: boolean;
    busy: MutableRefObject<boolean>;
    outcome: AttendanceOutcome | null;
}) {
    const [confirm, setConfirm] = useState(false);
    const unknown = outcome?.status === 'unknown';
    const requestClose = () => {
        if (busy.current || pending) return;
        if (outcome?.status !== 'confirmed' && (dirty || unknown))
            setConfirm(true);
        else onClose();
    };
    return {
        requestClose,
        dialog: (
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => {
                    if (!busy.current) onClose();
                }}
                title={
                    unknown
                        ? 'Close an unconfirmed result?'
                        : 'Discard your entries?'
                }
                description={
                    unknown
                        ? 'The action may already have saved. Check attendance before starting it again. Closing removes the entries kept in this form.'
                        : 'Your entries in this form will be discarded. Keep editing to review and save them.'
                }
                confirmText={unknown ? 'Close form' : 'Discard entries'}
                cancelText="Keep this form open"
                processing={pending}
            />
        ),
    };
}

export function useAttendanceValidationFocus(
    errors: Record<string, string>,
    step: number,
    formId: string,
) {
    useEffect(() => {
        if (!Object.keys(errors).length) return;
        const form = document.getElementById(formId);
        const target =
            form?.querySelector<HTMLElement>('[aria-invalid="true"]') ??
            form?.querySelector<HTMLElement>(
                'input:not([disabled]),textarea:not([disabled]),button:not([disabled])',
            );
        target?.focus();
    }, [errors, step, formId]);
}
