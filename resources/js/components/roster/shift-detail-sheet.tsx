import { Link } from '@inertiajs/react';
import { Clock, MapPin, UserRound } from 'lucide-react';

import StaffStatus from '@/components/staff-status';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { formatDateTime, formatTime } from '@/lib/datetime';

import type { RosterShift } from './types';

function ShiftDetail({ shift }: { shift: RosterShift }) {
    const completedTasks = shift.tasks.filter(
        (task) => task.is_completed,
    ).length;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <StaffStatus kind="shift" state={shift.status_state} />
                {shift.service_type ? (
                    <span className="rounded-full border px-2.5 py-1 text-sm text-muted-foreground">
                        {shift.service_type}
                    </span>
                ) : null}
            </div>

            <div className="grid gap-3 text-sm">
                <div className="flex gap-2">
                    <UserRound className="mt-0.5 h-4 w-4 text-muted-foreground" />
                    <div>
                        <div className="font-medium">
                            {shift.client?.name ?? 'Unassigned person'}
                        </div>
                        <div className="text-muted-foreground">
                            Person we support
                        </div>
                    </div>
                </div>
                <div className="flex gap-2">
                    <Clock className="mt-0.5 h-4 w-4 text-muted-foreground" />
                    <div>
                        <div className="font-medium">
                            {formatDateTime(shift.starts_at)} -{' '}
                            {formatTime(shift.ends_at)}
                        </div>
                        <div className="text-muted-foreground">
                            Rostered time
                        </div>
                    </div>
                </div>
                {shift.location ? (
                    <div className="flex gap-2">
                        <MapPin className="mt-0.5 h-4 w-4 text-muted-foreground" />
                        <div>
                            <div className="font-medium">{shift.location}</div>
                            <div className="text-muted-foreground">
                                Location
                            </div>
                        </div>
                    </div>
                ) : null}
            </div>

            <div className="rounded-lg border bg-muted/30 p-3">
                <div className="flex items-center justify-between gap-3 text-sm">
                    <span className="font-medium">Shift tasks</span>
                    <span className="text-muted-foreground">
                        {completedTasks}/{shift.tasks.length || 0}
                    </span>
                </div>
                <Progress value={shift.task_progress} className="mt-2 h-2" />
            </div>

            {shift.timesheet ? (
                <div className="rounded-lg border bg-card p-3 text-sm">
                    <div className="font-medium">
                        Timesheet {shift.timesheet.status}
                    </div>
                    {shift.timesheet.return_notes ? (
                        <p className="mt-1 text-muted-foreground">
                            {shift.timesheet.return_notes}
                        </p>
                    ) : null}
                </div>
            ) : null}

            <Button asChild className="w-full">
                <Link href={`/operations/shifts/${shift.id}`}>
                    Open shift page
                </Link>
            </Button>
        </div>
    );
}

export default function ShiftDetailSheet({
    shift,
    open,
    onOpenChange,
}: {
    shift: RosterShift | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const isMobile = useIsMobile();

    if (!shift) {
        return null;
    }

    if (isMobile) {
        return (
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetContent
                    side="bottom"
                    className="max-h-[85svh] overflow-y-auto rounded-t-3xl pb-[max(env(safe-area-inset-bottom,0px),1rem)]"
                >
                    <SheetHeader className="pr-12">
                        <SheetTitle>Shift detail</SheetTitle>
                        <SheetDescription>
                            {shift.client?.name ?? 'Rostered shift'}
                        </SheetDescription>
                    </SheetHeader>
                    <div className="px-4 pb-4">
                        <ShiftDetail shift={shift} />
                    </div>
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Shift detail</DialogTitle>
                    <DialogDescription>
                        {shift.client?.name ?? 'Rostered shift'}
                    </DialogDescription>
                </DialogHeader>
                <ShiftDetail shift={shift} />
            </DialogContent>
        </Dialog>
    );
}
