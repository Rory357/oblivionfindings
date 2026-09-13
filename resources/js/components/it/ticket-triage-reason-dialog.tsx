import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useEffect, useState } from 'react';

export function TicketTriageReasonDialog({
    open,
    descriptions,
    onConfirm,
    onCancel,
    processing = false,
    error,
    blocked = false,
    reason: controlledReason,
    onReasonChange,
}: {
    open: boolean;
    descriptions: { label: string; value: string }[];
    onConfirm: (reason: string) => void;
    onCancel: () => void;
    processing?: boolean;
    error?: string | null;
    blocked?: boolean;
    reason?: string;
    onReasonChange?: (reason: string) => void;
}) {
    const [localReason, setLocalReason] = useState('');
    const reason = controlledReason ?? localReason;
    const [discard, setDiscard] = useState(false);
    useEffect(() => {
        if (open && controlledReason === undefined) setLocalReason('');
    }, [open, controlledReason]);
    const close = () => {
        if (processing) return;
        if (reason.trim()) setDiscard(true);
        else onCancel();
    };
    return (
        <>
            <Dialog open={open} onOpenChange={(next) => !next && close()}>
                <DialogContent
                    className="max-h-[90vh] min-w-0 overflow-y-auto"
                    style={{
                        maxWidth: 'min(92vw, 720px)',
                        width: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Explain this triage change</DialogTitle>
                        <DialogDescription>
                            Record why this priority or ownership needs to
                            change. The reason is visible to IT and kept in the
                            ticket history.
                        </DialogDescription>
                    </DialogHeader>
                    <dl className="space-y-2 text-sm">
                        {descriptions.map(({ label, value }) => (
                            <div key={label}>
                                <dt className="font-medium">{label}</dt>
                                <dd className="break-words text-muted-foreground">
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <div className="space-y-2">
                        {error && (
                            <p
                                role="alert"
                                className="text-sm text-status-critical"
                            >
                                {error}
                            </p>
                        )}
                        <Label htmlFor="ticket-triage-reason">
                            Reason for change
                        </Label>
                        <Textarea
                            id="ticket-triage-reason"
                            autoFocus
                            required
                            maxLength={1000}
                            value={reason}
                            disabled={processing}
                            onChange={(event) => {
                                if (controlledReason === undefined)
                                    setLocalReason(event.target.value);
                                onReasonChange?.(event.target.value);
                            }}
                            placeholder="Explain the impact or responsibility that needs this change…"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            disabled={processing}
                            variant="ghost"
                            onClick={close}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            disabled={blocked || processing || !reason.trim()}
                            onClick={() => onConfirm(reason.trim())}
                        >
                            {processing ? 'Applying…' : 'Apply change'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    setDiscard(false);
                    onCancel();
                }}
                title="Discard this triage change?"
                description="This change has not been applied to the ticket. Any saved recovery draft remains available."
                confirmText="Discard change"
            />
        </>
    );
}
