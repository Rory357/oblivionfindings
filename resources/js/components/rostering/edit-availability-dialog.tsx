import { router } from '@inertiajs/react';
import { CalendarCheck, Clock, Loader2, Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Card as GuardrailCard } from '@/components/ui/card';

export type EditAvailabilityBlock = {
    id: number;
    day_of_week: number;
    start_time: string;
    end_time: string;
};

export type EditAvailabilityDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    staff: { id: number; name: string; email: string; role?: string | null } | null;
    blocks: EditAvailabilityBlock[];
    onSaved?: () => void;
};

const DAY_LABELS = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

const DAY_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export function EditAvailabilityDialog({
    open,
    onOpenChange,
    staff,
    blocks,
    onSaved,
}: EditAvailabilityDialogProps) {
    const [day, setDay] = useState<string>('1');
    const [startTime, setStartTime] = useState<string>('09:00');
    const [endTime, setEndTime] = useState<string>('17:00');
    const [submitting, setSubmitting] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (open) {
            setDay('1');
            setStartTime('09:00');
            setEndTime('17:00');
            setError(null);
        }
    }, [open, staff?.id]);

    const grouped = useMemo(() => {
        return DAY_LABELS.map((label, idx) => ({
            label,
            short: DAY_SHORT[idx],
            day: idx,
            blocks: blocks
                .filter((b) => b.day_of_week === idx)
                .sort((a, b) => a.start_time.localeCompare(b.start_time)),
        }));
    }, [blocks]);

    const daysCovered = grouped.filter((g) => g.blocks.length > 0).length;
    const totalBlocks = blocks.length;

    const reload = () =>
        router.reload({
            only: ['staffAvailabilitySummary'],
            preserveScroll: true,
            onSuccess: () => {
                onSaved?.();
            },
        });

    const handleAdd = () => {
        if (!staff) return;
        if (endTime <= startTime) {
            setError('End time must be after start time.');
            return;
        }
        setSubmitting(true);
        setError(null);
        router.post(
            `/staff/${staff.id}/availability`,
            {
                day_of_week: Number(day),
                starts_at: startTime,
                ends_at: endTime,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    reload();
                },
                onError: (errors) => {
                    setError(
                        errors.starts_at ??
                            errors.ends_at ??
                            errors.day_of_week ??
                            'Could not add availability block.',
                    );
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const handleRemove = (block: EditAvailabilityBlock) => {
        if (!staff) return;
        setDeletingId(block.id);
        router.delete(`/staff/${staff.id}/availability/${block.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                reload();
            },
            onFinish: () => setDeletingId(null),
        });
    };

    if (!staff) return null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <CalendarCheck className="h-5 w-5 text-primary" />
                        Edit availability — {staff.name}
                    </DialogTitle>
                    <DialogDescription className="flex flex-wrap items-center gap-2">
                        <span>{staff.email}</span>
                        {staff.role ? (
                            <Badge variant="outline" className="text-xs capitalize">
                                {staff.role.replace(/_/g, ' ')}
                            </Badge>
                        ) : null}
                        <span className="text-xs text-muted-foreground">
                            · {totalBlocks} {totalBlocks === 1 ? 'block' : 'blocks'} ·{' '}
                            {daysCovered} {daysCovered === 1 ? 'day' : 'days'} covered
                        </span>
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="rounded-lg border border-dashed border-border bg-muted/30 p-3">
                        <div className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            <Plus className="h-3 w-3" />
                            Add availability block
                        </div>
                        <div className="grid gap-2 md:grid-cols-[1fr_1fr_1fr_auto]">
                            <div className="space-y-1">
                                <Label className="text-xs">Day</Label>
                                <Select value={day} onValueChange={setDay}>
                                    <SelectTrigger className="h-9 text-sm">
                                        <SelectValue placeholder="Select day" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {DAY_LABELS.map((label, idx) => (
                                            <SelectItem key={label} value={String(idx)}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Start</Label>
                                <Input
                                    type="time"
                                    value={startTime}
                                    onChange={(e) => setStartTime(e.target.value)}
                                    className="h-9 text-sm"
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">End</Label>
                                <Input
                                    type="time"
                                    value={endTime}
                                    onChange={(e) => setEndTime(e.target.value)}
                                    className="h-9 text-sm"
                                />
                            </div>
                            <div className="flex items-end">
                                <Button
                                    type="button"
                                    onClick={handleAdd}
                                    disabled={submitting}
                                    className="h-9 w-full md:w-auto"
                                >
                                    {submitting ? (
                                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                    ) : (
                                        <Plus className="h-3.5 w-3.5" />
                                    )}
                                    Add
                                </Button>
                            </div>
                        </div>
                        {error ? (
                            <p className="mt-2 text-xs text-status-critical">{error}</p>
                        ) : null}
                    </div>

                    <div className="max-h-[55vh] space-y-1.5 overflow-y-auto pr-1">
                        {grouped.map((g) => (
                            <GuardrailCard unstyled
                                key={g.day}
                                className="grid grid-cols-[60px_1fr] items-start gap-3 rounded-md border border-border bg-card px-3 py-2"
                            >
                                <div className="flex flex-col items-center justify-center rounded-md bg-muted/60 px-1 py-1.5 text-center">
                                    <span className="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                                        {g.short}
                                    </span>
                                    <span className="text-[11px] font-semibold text-foreground/80">
                                        {g.blocks.length}
                                        {g.blocks.length === 1 ? ' block' : ' blocks'}
                                    </span>
                                </div>
                                <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
                                    {g.blocks.length === 0 ? (
                                        <span className="text-xs text-muted-foreground/70">
                                            No blocks
                                        </span>
                                    ) : (
                                        g.blocks.map((b) => (
                                            <span
                                                key={b.id}
                                                className="inline-flex items-center gap-1.5 rounded-md border border-border bg-status-success-bg/40 px-2 py-1 text-xs font-semibold text-foreground"
                                            >
                                                <Clock className="h-3 w-3 text-status-success" />
                                                {b.start_time}–{b.end_time}
                                                <Button unstyled
                                                    type="button"
                                                    onClick={() => handleRemove(b)}
                                                    disabled={deletingId === b.id}
                                                    className="ml-0.5 rounded-sm p-0.5 text-muted-foreground transition-colors hover:bg-status-critical-bg hover:text-status-critical disabled:opacity-50"
                                                    aria-label={`Remove ${g.label} ${b.start_time}-${b.end_time}`}
                                                >
                                                    {deletingId === b.id ? (
                                                        <Loader2 className="h-3 w-3 animate-spin" />
                                                    ) : (
                                                        <Trash2 className="h-3 w-3" />
                                                    )}
                                                </Button>
                                            </span>
                                        ))
                                    )}
                                </div>
                            </GuardrailCard>
                        ))}
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Done
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default EditAvailabilityDialog;
