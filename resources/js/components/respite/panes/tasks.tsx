/**
 * Tasks pane — respite operational work (procedure tasks, approvals, evidence
 * gates). List + status filters, inline start/complete, and a detail pop-up.
 */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { Check, Clock, Eye, ListChecks, Play, User } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { respiteActions } from '../actions';
import { Empty, FilterChip, PaneHead, SearchBox } from '../pane-kit';
import { fmtDate, Pill, type Tone } from '../shared';
import type { RespiteCan, RespiteTaskRow } from '../types';

const STATUS: Record<string, { label: string; tone: Tone }> = {
    pending: { label: 'Pending', tone: 'neutral' },
    in_progress: { label: 'In progress', tone: 'info' },
    awaiting_approval: { label: 'Awaiting approval', tone: 'warning' },
    approved: { label: 'Approved', tone: 'success' },
    rejected: { label: 'Rejected', tone: 'critical' },
    completed: { label: 'Completed', tone: 'success' },
    skipped: { label: 'Skipped', tone: 'neutral' },
    blocked: { label: 'Blocked', tone: 'critical' },
};
const PRIORITY: Record<string, Tone> = {
    low: 'neutral',
    medium: 'info',
    high: 'warning',
    critical: 'critical',
};

const taskMeta = (s: string) =>
    STATUS[s] ?? { label: s, tone: 'neutral' as Tone };
const isOpen = (s: string) =>
    !['completed', 'approved', 'skipped', 'rejected'].includes(s);

export function TasksPane({
    tasks,
    can,
}: {
    tasks: RespiteTaskRow[];
    can: RespiteCan;
}) {
    const [q, setQ] = useState('');
    const [scope, setScope] = useState('open');
    const [detail, setDetail] = useState<RespiteTaskRow | null>(null);

    const rows = tasks.filter((t) => {
        const scopeOk =
            scope === 'all'
                ? true
                : scope === 'open'
                  ? isOpen(t.status)
                  : scope === 'overdue'
                    ? t.overdue
                    : t.status === 'completed';
        return (
            scopeOk &&
            (q === '' ||
                `${t.title} ${t.assignee ?? ''}`
                    .toLowerCase()
                    .includes(q.toLowerCase()))
        );
    });

    return (
        <div>
            <PaneHead
                icon={ListChecks}
                title="Tasks"
                count={`${tasks.filter((t) => isOpen(t.status)).length} open`}
            />
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <SearchBox
                    value={q}
                    onChange={setQ}
                    placeholder="Search task or assignee…"
                />
                {(
                    [
                        ['open', 'Open'],
                        ['overdue', 'Overdue'],
                        ['completed', 'Completed'],
                        ['all', 'All'],
                    ] as const
                ).map(([k, label]) => (
                    <FilterChip
                        key={k}
                        active={scope === k}
                        onClick={() => setScope(k)}
                    >
                        {label}
                    </FilterChip>
                ))}
            </div>
            <div className="grid gap-2.5">
                {rows.map((t) => (
                    <TaskCard
                        key={t.id}
                        t={t}
                        can={can}
                        onView={() => setDetail(t)}
                    />
                ))}
                {rows.length === 0 ? (
                    <Empty icon={ListChecks} title="No tasks here" />
                ) : null}
            </div>
            <TaskDetail task={detail} onClose={() => setDetail(null)} />
        </div>
    );
}

function TaskCard({
    t,
    can,
    onView,
}: {
    t: RespiteTaskRow;
    can: RespiteCan;
    onView: () => void;
}) {
    const m = taskMeta(t.status);
    return (
        <div
            className={cn(
                'rounded-[14px] border border-l-[3px] border-border bg-card p-4 transition-shadow hover:shadow-sm',
                t.overdue ? 'border-l-status-critical' : 'border-l-transparent',
            )}
        >
            <div className="flex items-start gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[14px] font-bold">{t.title}</span>
                        <Pill tone={m.tone} dot>
                            {m.label}
                        </Pill>
                        <Pill tone={PRIORITY[t.priority] ?? 'neutral'}>
                            {t.priority}
                        </Pill>
                        {t.stopGate ? (
                            <Pill tone="critical">Stop gate</Pill>
                        ) : null}
                    </div>
                    {t.description ? (
                        <p className="mt-1.5 line-clamp-2 text-[12.5px] text-muted-foreground">
                            {t.description}
                        </p>
                    ) : null}
                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <span className="inline-flex items-center gap-1">
                            <User className="h-3.5 w-3.5" />
                            {t.assignee ?? 'Unassigned'}
                        </span>
                        {t.dueAt ? (
                            <span
                                className={cn(
                                    'inline-flex items-center gap-1',
                                    t.overdue && 'text-status-critical',
                                )}
                            >
                                <Clock className="h-3.5 w-3.5" />
                                Due {fmtDate(t.dueAt)}
                            </span>
                        ) : null}
                    </div>
                </div>
                <div className="flex shrink-0 flex-col gap-1.5">
                    {can.tasksManage && t.status === 'pending' ? (
                        <Button
                            size="sm"
                            onClick={() => respiteActions.startTask(t.id)}
                        >
                            <Play className="h-3.5 w-3.5" /> Start
                        </Button>
                    ) : null}
                    {can.tasksManage && t.status === 'in_progress' ? (
                        <Button
                            size="sm"
                            className="bg-status-success text-white hover:bg-status-success/90"
                            onClick={() => respiteActions.completeTask(t.id)}
                        >
                            <Check className="h-3.5 w-3.5" /> Complete
                        </Button>
                    ) : null}
                    <Button size="sm" variant="outline" onClick={onView}>
                        <Eye className="h-3.5 w-3.5" /> View
                    </Button>
                </div>
            </div>
        </div>
    );
}

function TaskDetail({
    task,
    onClose,
}: {
    task: RespiteTaskRow | null;
    onClose: () => void;
}) {
    const rows: [string, ReactNode][] = task
        ? [
              ['Status', taskMeta(task.status).label],
              ['Priority', task.priority],
              ['Type', task.type],
              ['Assignee', task.assignee ?? 'Unassigned'],
              ['Due', fmtDate(task.dueAt)],
              ['Requires approval', task.requiresApproval ? 'Yes' : 'No'],
              ['Stop gate', task.stopGate ? 'Yes' : 'No'],
          ]
        : [];
    return (
        <Dialog open={task != null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                {task ? (
                    <>
                        <DialogTitle className="text-left text-lg">
                            {task.title}
                        </DialogTitle>
                        <DialogDescription className="text-left">
                            {task.description ?? 'Respite task'}
                        </DialogDescription>
                        <dl className="rounded-xl border border-border px-3.5">
                            {rows.map(([k, v], i) => (
                                <div
                                    key={i}
                                    className={`flex justify-between gap-4 py-2 text-[13px] ${i < rows.length - 1 ? 'border-b border-border/60' : ''}`}
                                >
                                    <dt className="text-muted-foreground">
                                        {k}
                                    </dt>
                                    <dd className="text-right font-medium capitalize">
                                        {v}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
