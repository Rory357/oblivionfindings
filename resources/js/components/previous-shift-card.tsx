import { Link } from '@inertiajs/react';
import { CheckCircle2, Clock, FileText, ListTodo } from 'lucide-react';

import StaffStatus from '@/components/staff-status';
import { Button } from '@/components/ui/button';
import { formatRelative, formatTime } from '@/lib/datetime';
import { show as showShift } from '@/routes/operations/shifts';
import { edit as editTimesheet } from '@/routes/operations/timesheets';

import type { RosterShift } from './roster/types';

export type PreviousShift = RosterShift & {
    handover_sent: boolean;
};

export default function PreviousShiftCard({ shift }: { shift: PreviousShift }) {
    const completedTasks = shift.tasks.filter(
        (task) => task.is_completed,
    ).length;
    const timesheetStatus = shift.timesheet?.status ?? 'draft';

    return (
        <section className="rounded-xl border border-status-success/30 bg-status-success-bg p-4 shadow-sm dark:border-status-success/40 dark:bg-status-success">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <StaffStatus kind="shift" state="completed" size="sm" />
                        <span className="text-sm font-semibold text-status-success dark:text-status-success">
                            Shift wrapped{' '}
                            {formatRelative(
                                shift.actual_ends_at ?? shift.ends_at,
                            )}
                        </span>
                    </div>

                    <h2 className="mt-3 text-lg font-semibold">
                        {shift.client?.name ?? 'Previous shift'}
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {formatTime(shift.starts_at)} -{' '}
                        {formatTime(shift.ends_at)}
                    </p>

                    <div className="mt-4 grid gap-2 sm:grid-cols-3">
                        <div className="rounded-lg border bg-background/80 p-3 text-sm">
                            <div className="flex items-center gap-2 font-medium">
                                <FileText className="h-4 w-4 text-muted-foreground" />
                                Handover
                            </div>
                            <p className="mt-1 text-muted-foreground">
                                {shift.handover_sent ? 'Sent' : 'Missing'}
                            </p>
                        </div>
                        <div className="rounded-lg border bg-background/80 p-3 text-sm">
                            <div className="flex items-center gap-2 font-medium">
                                <Clock className="h-4 w-4 text-muted-foreground" />
                                Timesheet
                            </div>
                            <p className="mt-1 text-muted-foreground capitalize">
                                {timesheetStatus.replace(/_/g, ' ')}
                            </p>
                        </div>
                        <div className="rounded-lg border bg-background/80 p-3 text-sm">
                            <div className="flex items-center gap-2 font-medium">
                                <ListTodo className="h-4 w-4 text-muted-foreground" />
                                Tasks
                            </div>
                            <p className="mt-1 text-muted-foreground">
                                {completedTasks}/{shift.tasks.length}
                            </p>
                        </div>
                    </div>
                </div>

                <div className="flex shrink-0 flex-col gap-2 sm:w-44">
                    <Button asChild>
                        <Link
                            href={
                                shift.timesheet
                                    ? editTimesheet.url(shift.timesheet.id)
                                    : showShift.url(shift.id)
                            }
                        >
                            Review timesheet
                        </Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href="/my-roster">
                            <CheckCircle2 className="mr-2 h-4 w-4" />
                            Full roster
                        </Link>
                    </Button>
                </div>
            </div>
        </section>
    );
}
