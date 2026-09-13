import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Clock, FileText } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { useMyDayLabels } from '@/hooks/use-my-day-labels';
import { formatDateOnly, formatDurationMinutes } from '@/lib/datetime';

import type { MyDayHrTask, MyDayTimesheet } from '../lib/types';

interface PaperworkPanelProps {
    timesheets: MyDayTimesheet[];
    hrTasks: MyDayHrTask[];
    onSubmitTimesheet: (timesheet: MyDayTimesheet) => void;
}

export function PaperworkPanel({
    timesheets,
    hrTasks,
    onSubmitTimesheet,
}: PaperworkPanelProps) {
    const t = useMyDayLabels();
    const dueCount =
        timesheets.filter((sheet) => sheet.status !== 'submitted').length +
        hrTasks.length;
    if (timesheets.length + hrTasks.length === 0) {
        return null;
    }
    return (
        <div
            data-test="my-day-paperwork"
            className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm"
        >
            <div className="flex items-center gap-3 border-b border-border p-5">
                <FileText className="h-3.5 w-3.5 text-muted-foreground" />
                <div className="text-section-title">
                    Timesheets and paperwork
                </div>
                <StatusBadge
                    variant={dueCount ? 'warning' : 'success'}
                    className="ml-auto"
                >
                    {dueCount
                        ? t('paperwork_x_due', { count: dueCount })
                        : 'Up to date'}
                </StatusBadge>
            </div>
            {timesheets.map((ts) => (
                <TimesheetRow
                    key={ts.id}
                    timesheet={ts}
                    onSubmit={onSubmitTimesheet}
                />
            ))}
            {hrTasks.map((task) => (
                <HrTaskRow key={task.id} task={task} />
            ))}
        </div>
    );
}

function TimesheetRow({
    timesheet: ts,
    onSubmit,
}: {
    timesheet: MyDayTimesheet;
    onSubmit: (timesheet: MyDayTimesheet) => void;
}) {
    const date = ts.work_date_iso
        ? formatDateOnly(ts.work_date_iso)
        : ts.work_date;
    // Multi-client timesheets get a richer label than just the primary
    // client name — show the breakdown count so the worker knows what
    // they'll see when they open the review popup.
    const allocations = ts.client_allocations ?? [];
    const isMultiClient = allocations.length > 1;
    const primaryLabel = ts.client_name ?? 'Timesheet';
    const hoursLabel = ts.clock_running ? 'planned (draft)' : 'paid time';
    const duration = formatDurationMinutes(ts.paid_minutes ?? ts.hours * 60);
    const summary = isMultiClient
        ? `${allocations.length} people · ${duration} ${hoursLabel}`
        : `${ts.site_name ?? primaryLabel} · ${duration} ${hoursLabel}`;
    return (
        <div className="flex flex-wrap items-center justify-between gap-4 border-b border-border p-5 last:border-b-0">
            <div className="flex min-w-0 items-start gap-3">
                <Clock className="mt-1 size-5 shrink-0 text-primary" />
                <div className="space-y-1">
                    <p className="font-semibold">{summary}</p>
                    <p className="text-subtle">{date}</p>
                    {ts.needs && <p className="text-subtle">{ts.needs}</p>}
                    <StatusBadge
                        variant={
                            ts.status === 'returned'
                                ? 'warning'
                                : ts.status === 'submitted'
                                  ? 'success'
                                  : 'neutral'
                        }
                    >
                        {ts.status === 'returned'
                            ? 'Needs your changes'
                            : ts.status === 'submitted'
                              ? 'Sent for approval'
                              : ts.clock_running
                                ? 'Draft · still clocked in'
                                : 'Ready to review'}
                    </StatusBadge>
                </div>
            </div>
            <Button
                className="frontline-tap"
                variant={ts.status === 'returned' ? 'default' : 'outline'}
                onClick={() => onSubmit(ts)}
            >
                {ts.status === 'returned'
                    ? 'Review requested changes'
                    : ts.status === 'submitted'
                      ? 'View submitted timesheet'
                      : 'Review people and time'}
                <ArrowRight className="size-4" />
            </Button>
        </div>
    );
}

function HrTaskRow({ task }: { task: MyDayHrTask }) {
    const t = useMyDayLabels();
    const Icon = task.kind === 'signature' ? FileText : CheckCircle2;

    return (
        <div className="flex flex-wrap items-center gap-3 border-b border-border p-5 last:border-b-0">
            <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-accent text-primary">
                <Icon className="h-3 w-3" />
            </div>
            <div className="min-w-0 flex-1">
                <div className="text-sm font-medium">{task.title}</div>
                <div className="text-subtle">Due {task.due}</div>
            </div>
            {task.href ? (
                <Button asChild variant="outline" className="frontline-tap">
                    <Link href={task.href}>
                        {t('hr_open')}
                        <ArrowRight className="size-4" />
                    </Link>
                </Button>
            ) : (
                <span className="text-subtle">Ask your team leader</span>
            )}
        </div>
    );
}

export default PaperworkPanel;
