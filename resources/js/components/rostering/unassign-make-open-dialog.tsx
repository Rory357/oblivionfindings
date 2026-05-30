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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

export type UnassignMakeOpenShift = {
    id: number;
    starts_at?: string | null;
    client?: string | null;
    staff?: string | null;
};

export type UnassignMakeOpenDialogProps = {
    open: boolean;
    shift: UnassignMakeOpenShift | null;
    onOpenChange: (open: boolean) => void;
    onConfirm: (shift: UnassignMakeOpenShift, reason: string | null) => void;
};

export function UnassignMakeOpenDialog({
    open,
    shift,
    onOpenChange,
    onConfirm,
}: UnassignMakeOpenDialogProps) {
    const [reason, setReason] = useState('');

    useEffect(() => {
        if (open) setReason('');
    }, [open]);

    if (!shift) return null;

    const dateLabel = shift.starts_at
        ? new Date(shift.starts_at).toLocaleDateString(undefined, {
              weekday: 'long',
              day: 'numeric',
              month: 'short',
          })
        : 'this shift';
    const trimmedReason = reason.trim();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <AlertTriangle className="h-5 w-5 text-status-warning" />
                        Unassign and make open
                    </DialogTitle>
                    <DialogDescription>
                        You are about to unassign this shift.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-3 py-2">
                    <div className="rounded-md border border-status-warning/25 bg-status-warning-bg px-3 py-2 text-sm text-status-warning">
                        {dateLabel}
                        {shift.client ? ` · ${shift.client}` : ''}
                        {shift.staff ? ` · currently ${shift.staff}` : ''}
                    </div>
                    <div className="space-y-1.5">
                        <Label
                            htmlFor="unassign-make-open-reason"
                            className="text-sm font-medium"
                        >
                            Reason (optional)
                        </Label>
                        <Textarea
                            id="unassign-make-open-reason"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={4}
                            maxLength={2000}
                            placeholder="e.g. Staff called in sick"
                        />
                        <p className="text-xs text-muted-foreground">
                            Not mandatory, but useful for audit and roster
                            handover.
                        </p>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={() =>
                            onConfirm(
                                shift,
                                trimmedReason === '' ? null : trimmedReason,
                            )
                        }
                    >
                        Unassign and make open
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default UnassignMakeOpenDialog;
