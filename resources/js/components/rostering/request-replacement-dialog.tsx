import { Users } from 'lucide-react';
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

export type RequestReplacementShift = {
    id: number;
    starts_at?: string | null;
    client?: string | null;
    staff?: string | null;
};

export type RequestReplacementDialogProps = {
    open: boolean;
    shift: RequestReplacementShift | null;
    onOpenChange: (open: boolean) => void;
    onConfirm: (
        shift: RequestReplacementShift,
        payload: { reason: string; notes: string | null },
    ) => void;
};

export function RequestReplacementDialog({
    open,
    shift,
    onOpenChange,
    onConfirm,
}: RequestReplacementDialogProps) {
    const [reason, setReason] = useState('');
    const [notes, setNotes] = useState('');

    useEffect(() => {
        if (open) {
            setReason('');
            setNotes('');
        }
    }, [open]);

    if (!shift) return null;

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
                        <Users className="h-5 w-5 text-primary" />
                        Request replacement
                    </DialogTitle>
                    <DialogDescription>
                        Opens a replacement request for {dateLabel}
                        {shift.client ? ` for ${shift.client}` : ''}
                        {shift.staff ? ` (currently ${shift.staff})` : ''} so
                        eligible staff can pick it up.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-3 py-2">
                    <div className="space-y-1.5">
                        <label
                            htmlFor="replacement-reason"
                            className="text-sm font-medium"
                        >
                            Reason{' '}
                            <span className="text-status-critical">*</span>
                        </label>
                        <input
                            id="replacement-reason"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            maxLength={255}
                            placeholder="e.g. Called in sick"
                            className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none"
                        />
                    </div>
                    <div className="space-y-1.5">
                        <label
                            htmlFor="replacement-notes"
                            className="text-sm font-medium"
                        >
                            Notes (optional)
                        </label>
                        <textarea
                            id="replacement-notes"
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            rows={3}
                            maxLength={2000}
                            placeholder="Anything the covering staff member should know."
                            className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none"
                        />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        disabled={!reason.trim()}
                        onClick={() =>
                            onConfirm(shift, {
                                reason: reason.trim(),
                                notes: notes.trim() === '' ? null : notes.trim(),
                            })
                        }
                    >
                        Request replacement
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default RequestReplacementDialog;
