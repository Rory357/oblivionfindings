import { Repeat } from 'lucide-react';
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
import { cn } from '@/lib/utils';

export type MakeRecurringShift = {
    id: number;
    starts_at?: string | null;
    client?: string | null;
};

export type MakeRecurringDialogProps = {
    open: boolean;
    shift: MakeRecurringShift | null;
    onOpenChange: (open: boolean) => void;
    onConfirm: (
        shift: MakeRecurringShift,
        weekdays: number[],
        endDate: string,
    ) => void;
};

// Mon=1 ... Sun=0 (JS getDay) but the backend `by_weekday` is 0=Sunday … 6=Saturday.
const WEEKDAYS: Array<{ id: number; label: string }> = [
    { id: 1, label: 'Mon' },
    { id: 2, label: 'Tue' },
    { id: 3, label: 'Wed' },
    { id: 4, label: 'Thu' },
    { id: 5, label: 'Fri' },
    { id: 6, label: 'Sat' },
    { id: 0, label: 'Sun' },
];

function isoDate(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

export function MakeRecurringDialog({
    open,
    shift,
    onOpenChange,
    onConfirm,
}: MakeRecurringDialogProps) {
    const defaults = useMemo(() => {
        if (!shift?.starts_at) {
            return {
                weekdays: [] as number[],
                endDate: isoDate(
                    new Date(Date.now() + 28 * 24 * 60 * 60 * 1000),
                ),
            };
        }
        const start = new Date(shift.starts_at);
        const endHint = new Date(start);
        endHint.setMonth(endHint.getMonth() + 1);
        return {
            weekdays: [start.getDay()] as number[],
            endDate: isoDate(endHint),
        };
    }, [shift]);

    const [weekdays, setWeekdays] = useState<number[]>(defaults.weekdays);
    const [endDate, setEndDate] = useState<string>(defaults.endDate);

    useEffect(() => {
        if (open) {
            setWeekdays(defaults.weekdays);
            setEndDate(defaults.endDate);
        }
    }, [open, defaults]);

    if (!shift) return null;

    const canSubmit = weekdays.length > 0 && Boolean(endDate);

    const toggleWeekday = (id: number) => {
        setWeekdays((current) =>
            current.includes(id)
                ? current.filter((x) => x !== id)
                : [...current, id],
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Repeat className="h-5 w-5 text-primary" />
                        Promote to recurring series
                    </DialogTitle>
                    <DialogDescription>
                        Creates a recurring series from this shift using its
                        current time, location, and assigned staff. Choose the
                        weekdays and end date. Future occurrences are not
                        generated automatically — a scheduler tool generates
                        them from the series definition.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-3 py-2">
                    <div>
                        <label className="text-sm font-medium">
                            Repeat on
                        </label>
                        <div className="mt-1 flex flex-wrap gap-1">
                            {WEEKDAYS.map((d) => {
                                const on = weekdays.includes(d.id);
                                return (
                                    <Button unstyled
                                        key={d.id}
                                        type="button"
                                        onClick={() => toggleWeekday(d.id)}
                                        className={cn(
                                            'rounded-full border px-3 py-1 text-xs font-medium',
                                            on
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'border-border bg-background text-foreground hover:bg-accent',
                                        )}
                                    >
                                        {d.label}
                                    </Button>
                                );
                            })}
                        </div>
                    </div>
                    <div>
                        <label
                            htmlFor="make-recurring-end-date"
                            className="text-sm font-medium"
                        >
                            Ends on
                        </label>
                        <input
                            id="make-recurring-end-date"
                            type="date"
                            value={endDate}
                            onChange={(e) => setEndDate(e.target.value)}
                            className="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none"
                        />
                    </div>
                    {shift.client ? (
                        <div className="text-xs text-muted-foreground">
                            Client: {shift.client}
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
                        onClick={() =>
                            canSubmit && onConfirm(shift, weekdays, endDate)
                        }
                    >
                        Create series
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default MakeRecurringDialog;
