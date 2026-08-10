import { Copy } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export type CopyToDayShift = {
    id: number;
    starts_at?: string | null;
    client?: string | null;
};

export type CopyToDayDialogProps = {
    open: boolean;
    shift: CopyToDayShift | null;
    onOpenChange: (open: boolean) => void;
    onConfirm: (shift: CopyToDayShift, date: string) => void;
};

function isoDate(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function defaultTargetDate(shift: CopyToDayShift | null): string {
    if (!shift?.starts_at) return isoDate(new Date());
    const source = new Date(shift.starts_at);
    source.setDate(source.getDate() + 1);
    return isoDate(source);
}

export function CopyToDayDialog({
    open,
    shift,
    onOpenChange,
    onConfirm,
}: CopyToDayDialogProps) {
    const initialDate = useMemo(() => defaultTargetDate(shift), [shift]);
    const [date, setDate] = useState<string>(initialDate);

    useEffect(() => {
        if (open) {
            setDate(initialDate);
        }
    }, [open, initialDate]);

    if (!shift) return null;

    const sourceLabel = shift.starts_at
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
                        <Copy className="h-5 w-5 text-primary" />
                        Copy shift to another day
                    </DialogTitle>
                    <DialogDescription>
                        Creates an unassigned draft copy of {sourceLabel} on the
                        date you choose. The copy stays within the same roster
                        period.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-2 py-2">
                    <label
                        htmlFor="copy-to-day-date"
                        className="text-sm font-medium"
                    >
                        Target date
                    </label>
                    <input
                        id="copy-to-day-date"
                        type="date"
                        value={date}
                        onChange={(e) => setDate(e.target.value)}
                        className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none"
                    />
                    {shift.client ? (
                        <div className="text-xs text-muted-foreground">
                            Client: {shift.client}
                        </div>
                    ) : null}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        disabled={!date}
                        onClick={() => {
                            if (!date) return;
                            onConfirm(shift, date);
                        }}
                    >
                        Copy to this day
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default CopyToDayDialog;
