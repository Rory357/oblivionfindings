import { AlertTriangle } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export type MarkEndedEarlyShift = {
    id: number;
    starts_at?: string | null;
    client?: string | null;
    staff?: string | null;
};

export type MarkEndedEarlyDialogProps = {
    open: boolean;
    shift: MarkEndedEarlyShift | null;
    onOpenChange: (open: boolean) => void;
    onConfirm: (shift: MarkEndedEarlyShift, reason: string) => void;
};

const MIN_LENGTH = 8;

export function MarkEndedEarlyDialog({
    open,
    shift,
    onOpenChange,
    onConfirm,
}: MarkEndedEarlyDialogProps) {
    const [reason, setReason] = useState('');

    useEffect(() => {
        if (open) setReason('');
    }, [open]);

    if (!shift) return null;

    const trimmed = reason.trim();
    const canSubmit = trimmed.length >= MIN_LENGTH;
    const dateLabel = shift.starts_at
        ? new Date(shift.starts_at).toLocaleDateString(undefined, {
              weekday: 'long',
              day: 'numeric',
              month: 'short',
          })
        : 'this shift';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <AlertTriangle className="h-5 w-5 text-status-warning" />
                        Mark shift as ended early
                    </DialogTitle>
                    <DialogDescription>
                        Completes {dateLabel} now and records the reason on the
                        timeline. The shift's draft timesheet will be created
                        for the time elapsed since clock-in.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-2 py-2">
                    <label
                        htmlFor="mark-ended-early-reason"
                        className="text-sm font-medium"
                    >
                        Reason for ending early
                    </label>
                    <textarea
                        id="mark-ended-early-reason"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        rows={4}
                        maxLength={2000}
                        placeholder="e.g. Resident transported to hospital at 14:30; shift ended early."
                        className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none"
                    />
                    <div className="text-xs text-muted-foreground">
                        Minimum {MIN_LENGTH} characters. Recorded on the
                        shift timeline and the final progress note.
                    </div>
                    {shift.staff || shift.client ? (
                        <div className="text-xs text-muted-foreground">
                            {shift.staff
                                ? `Staff: ${shift.staff}`
                                : null}
                            {shift.staff && shift.client ? ' · ' : ''}
                            {shift.client
                                ? `Client: ${shift.client}`
                                : null}
                        </div>
                    ) : null}
                </div>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        disabled={!canSubmit}
                        onClick={() => onConfirm(shift, trimmed)}
                    >
                        End shift now
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default MarkEndedEarlyDialog;
