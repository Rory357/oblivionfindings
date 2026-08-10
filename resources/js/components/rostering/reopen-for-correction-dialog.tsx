import { RefreshCcw } from 'lucide-react';
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

export type ReopenForCorrectionShift = {
    id: number;
    starts_at?: string | null;
    client?: string | null;
    staff?: string | null;
};

export type ReopenForCorrectionDialogProps = {
    open: boolean;
    shift: ReopenForCorrectionShift | null;
    onOpenChange: (open: boolean) => void;
    onConfirm: (shift: ReopenForCorrectionShift, reason: string) => void;
};

const MIN_LENGTH = 8;

export function ReopenForCorrectionDialog({
    open,
    shift,
    onOpenChange,
    onConfirm,
}: ReopenForCorrectionDialogProps) {
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
                        <RefreshCcw className="h-5 w-5 text-status-warning" />
                        Reopen completed shift for correction
                    </DialogTitle>
                    <DialogDescription>
                        Reverts {dateLabel} from completed back to scheduled so
                        the actual start/end and any timesheet can be corrected.
                        The reason is recorded on the shift timeline for audit.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-2 py-2">
                    <label
                        htmlFor="reopen-correction-reason"
                        className="text-sm font-medium"
                    >
                        Reason for correction
                    </label>
                    <textarea
                        id="reopen-correction-reason"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        rows={4}
                        maxLength={2000}
                        placeholder="e.g. Clock-out time was wrong; staff actually finished 30 mins later."
                        className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none"
                    />
                    <div className="text-xs text-muted-foreground">
                        Minimum {MIN_LENGTH} characters. Required for
                        completed-shift reopen.
                    </div>
                    {shift.staff || shift.client ? (
                        <div className="text-xs text-muted-foreground">
                            {shift.staff ? `Staff: ${shift.staff}` : null}
                            {shift.staff && shift.client ? ' · ' : ''}
                            {shift.client ? `Client: ${shift.client}` : null}
                        </div>
                    ) : null}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        disabled={!canSubmit}
                        onClick={() => onConfirm(shift, trimmed)}
                    >
                        Reopen for correction
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default ReopenForCorrectionDialog;
