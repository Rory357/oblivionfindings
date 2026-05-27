import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ExternalLink,
    RefreshCcw,
    UserMinus,
} from 'lucide-react';

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

import type { GridConflictPeer } from './week-grid-pane';

export type ResolveConflictShift = GridConflictPeer;

export type ResolveConflictDialogProps = {
    open: boolean;
    shift: ResolveConflictShift | null;
    peers?: ResolveConflictShift[];
    onOpenChange: (open: boolean) => void;
    onUnassign: (shift: ResolveConflictShift) => void;
    onReassign: (shift: ResolveConflictShift) => void;
    onOpenQueue: () => void;
};

function fmtTime(iso: string): string {
    return new Date(iso).toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

function fmtDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    });
}

function statusLabel(status: ResolveConflictShift['status']): string {
    if (status === 'in_progress') return 'In progress';
    return status.charAt(0).toUpperCase() + status.slice(1);
}

export function ResolveConflictDialog({
    open,
    shift,
    peers = [],
    onOpenChange,
    onUnassign,
    onReassign,
    onOpenQueue,
}: ResolveConflictDialogProps) {
    if (!shift) return null;

    const entries = [shift, ...peers];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <AlertTriangle className="h-5 w-5 text-status-critical" />
                        Resolve overlap
                    </DialogTitle>
                    <DialogDescription>
                        One staff member is rostered across overlapping shifts.
                        Reassign or unassign one shift, or open the full
                        conflict queue for deeper review.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3">
                    {entries.map((entry, index) => (
                        <ConflictShiftCard
                            key={`${entry.id}-${index}`}
                            shift={entry}
                            primary={index === 0}
                            onUnassign={onUnassign}
                            onReassign={onReassign}
                        />
                    ))}
                </div>

                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={onOpenQueue}>
                        Open conflict queue
                    </Button>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ConflictShiftCard({
    shift,
    primary,
    onUnassign,
    onReassign,
}: {
    shift: ResolveConflictShift;
    primary: boolean;
    onUnassign: (shift: ResolveConflictShift) => void;
    onReassign: (shift: ResolveConflictShift) => void;
}) {
    return (
        <article
            className={cn(
                'rounded-md border p-3',
                primary
                    ? 'border-status-critical/35 bg-status-critical-bg/40'
                    : 'border-border bg-card',
            )}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-bold">
                            {shift.client ?? 'Shift'}
                        </span>
                        <span className="rounded bg-background/80 px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground uppercase">
                            {primary ? 'Selected' : 'Overlaps'}
                        </span>
                        <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground uppercase">
                            {statusLabel(shift.status)}
                        </span>
                    </div>
                    <div className="mt-1 text-xs text-muted-foreground">
                        {shift.staff ?? 'Staff'} · {fmtDate(shift.starts_at)} ·{' '}
                        {fmtTime(shift.starts_at)}-{fmtTime(shift.ends_at)}
                    </div>
                </div>

                <div className="flex flex-wrap justify-end gap-1.5">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => onReassign(shift)}
                    >
                        <RefreshCcw className="mr-1 h-3.5 w-3.5" />
                        Reassign
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => onUnassign(shift)}
                    >
                        <UserMinus className="mr-1 h-3.5 w-3.5" />
                        Unassign
                    </Button>
                    {shift.href ? (
                        <Button type="button" size="sm" variant="ghost" asChild>
                            <Link href={shift.href}>
                                <ExternalLink className="mr-1 h-3.5 w-3.5" />
                                Open
                            </Link>
                        </Button>
                    ) : null}
                </div>
            </div>
        </article>
    );
}

export default ResolveConflictDialog;
