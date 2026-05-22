import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Clock, FileText } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useMyDayLabels } from '@/hooks/use-my-day-labels';
import { cn } from '@/lib/utils';

import type { MyDayHrTask, MyDayTimesheet } from '../lib/types';

interface PaperworkPanelProps {
    timesheets: MyDayTimesheet[];
    hrTasks: MyDayHrTask[];
    onSubmitTimesheet: (timesheet: MyDayTimesheet) => void;
}

export function PaperworkPanel({ timesheets, hrTasks, onSubmitTimesheet }: PaperworkPanelProps) {
    const t = useMyDayLabels();
    const dueCount = timesheets.length + hrTasks.length;
    if (dueCount === 0) {
        return null;
    }
    return (
        <div
            data-test="my-day-paperwork"
            className="overflow-hidden rounded-2xl border border-border bg-card"
        >
            <div className="flex items-center gap-2 border-b border-border px-4 py-3">
                <FileText className="h-3.5 w-3.5 text-muted-foreground" />
                <div className="text-[13px] font-semibold">{t('paperwork_title')}</div>
                <Badge
                    variant="outline"
                    className="ml-auto border-status-warning/30 bg-status-warning-bg text-[10.5px] text-status-warning"
                >
                    {t('paperwork_x_due', { count: dueCount })}
                </Badge>
            </div>
            {timesheets.map((ts) => (
                <TimesheetRow key={ts.id} timesheet={ts} onSubmit={onSubmitTimesheet} />
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
    const t = useMyDayLabels();
    const date = ts.work_date_iso
        ? new Date(ts.work_date_iso).toLocaleDateString([], { day: '2-digit', month: 'short' })
        : ts.work_date;
    // Multi-client timesheets get a richer label than just the primary
    // client name — show the breakdown count so the worker knows what
    // they'll see when they open the review popup.
    const allocations = ts.client_allocations ?? [];
    const isMultiClient = allocations.length > 1;
    const primaryLabel = ts.client_name ?? 'Timesheet';
    const summary = isMultiClient
        ? `${allocations.length} clients · ${ts.hours}h`
        : `${primaryLabel} · ${ts.hours}h`;
    return (
        <div className="border-b border-border px-4 py-2.5 last:border-b-0">
            <div className="flex items-center gap-2">
                <Clock className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                <div className="flex-1 text-[12.5px] font-medium">{summary}</div>
                <div className="text-[11px] text-muted-foreground">{date}</div>
            </div>
            {ts.needs ? (
                <div className="mt-1 pl-[21px] text-[11px] text-muted-foreground">{ts.needs}</div>
            ) : null}
            {isMultiClient ? (
                <div className="mt-1 pl-[21px] text-[10.5px] text-muted-foreground">
                    {allocations
                        .map((a) => `${a.hours.toFixed(2)}h`)
                        .join(' + ')}
                </div>
            ) : null}
            <div className="mt-2 pl-[21px]">
                <Button
                    size="sm"
                    className={cn('w-full')}
                    variant={ts.status === 'returned' ? 'default' : 'secondary'}
                    onClick={() => onSubmit(ts)}
                >
                    {ts.status === 'returned' ? t('ts_fix_and_resubmit') : t('ts_send_for_approval')}
                </Button>
            </div>
        </div>
    );
}

function HrTaskRow({ task }: { task: MyDayHrTask }) {
    const t = useMyDayLabels();
    const Icon = task.kind === 'signature' ? FileText : CheckCircle2;
    const button = (
        <Button size="sm" variant="ghost">
            {t('hr_open')} <ArrowRight className="ml-1 h-2.5 w-2.5" />
        </Button>
    );
    return (
        <div className="flex items-center gap-2.5 border-b border-border px-4 py-2.5 last:border-b-0">
            <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-accent text-primary">
                <Icon className="h-3 w-3" />
            </div>
            <div className="min-w-0 flex-1">
                <div className="text-[12.5px] font-medium">{task.title}</div>
                <div className="text-[10.5px] text-muted-foreground">Due {task.due}</div>
            </div>
            {task.href ? <Link href={task.href}>{button}</Link> : button}
        </div>
    );
}

export default PaperworkPanel;
