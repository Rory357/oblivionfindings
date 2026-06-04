import { Building2, Camera, Eye, Play, PlayCircle, TriangleAlert } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import { catColorVar, initials, relDay, runStatusMeta } from './category';
import { freqLabel, useChecklistConfig } from './context';
import { CategoryIcon, Progress, StatusBadge } from './primitives';
import type { ChecklistRun } from './types';

function actionFor(run: ChecklistRun, canRun: boolean) {
    if (run.status === 'completed') return { label: 'View', Icon: Eye, variant: 'ghost' as const };
    if (!canRun) return { label: 'View', Icon: Eye, variant: 'ghost' as const };
    if (run.status === 'in_progress') return { label: 'Continue', Icon: PlayCircle, variant: 'default' as const };
    return { label: 'Start', Icon: Play, variant: 'outline' as const };
}

export function RunListRow({ run }: { run: ChecklistRun }) {
    const cfg = useChecklistConfig();
    const { categoryMap, today, can, openRun } = cfg;
    const meta = runStatusMeta(run, today);
    const StatusIcon = meta.Icon;
    const started = run.status === 'in_progress';
    const cat = run.template?.category ?? null;
    const tone = cat ? categoryMap[cat]?.tone : undefined;
    const flags = run.template?.flags;
    const action = actionFor(run, can.run);
    const ActionIcon = action.Icon;

    return (
        <div className="group flex items-center gap-3 px-3.5 py-3 transition-colors hover:bg-accent/40">
            <span className="h-9 w-1 shrink-0 rounded-full" style={{ background: catColorVar(tone) }} />
            <CategoryIcon category={cat} box={36} size={18} className="hidden sm:flex" />
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1.5">
                    <span className="truncate text-sm font-semibold">{run.template?.name}</span>
                    {flags?.hazard ? (
                        <span title="Failures raise a hazard" className="shrink-0 text-status-critical">
                            <TriangleAlert className="h-3 w-3" />
                        </span>
                    ) : null}
                    {flags?.photo ? (
                        <span title="Photo evidence" className="shrink-0 text-muted-foreground">
                            <Camera className="h-3 w-3" />
                        </span>
                    ) : null}
                </div>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1">
                        <Building2 className="h-3 w-3" />
                        {run.site?.name}
                    </span>
                    <span className="text-muted-foreground/40">·</span>
                    {freqLabel(cfg, run.template?.frequency)}
                    <span className="text-muted-foreground/40">·</span>
                    <span>{relDay(run.scheduled_date, today)}</span>
                </div>
            </div>
            <div className="hidden items-center gap-1.5 text-xs text-muted-foreground lg:flex">
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-muted text-[9px] font-semibold">
                    {initials(run.assignee)}
                </span>
                <span className="w-20 truncate">{run.assignee}</span>
            </div>
            {started ? (
                <div className="hidden items-center gap-2 md:flex">
                    <Progress value={run.pct} className="w-16" />
                    <span className="w-8 text-xs tabular-nums text-muted-foreground">{run.pct}%</span>
                </div>
            ) : null}
            <div className="hidden shrink-0 sm:block">
                <StatusBadge tone={meta.tone} Icon={StatusIcon}>
                    {meta.label}
                </StatusBadge>
            </div>
            <Button size="sm" variant={action.variant} className="shrink-0" onClick={() => openRun(run.id)}>
                <ActionIcon className="h-3.5 w-3.5" />
                {action.label}
            </Button>
        </div>
    );
}

export function WorklistCard({ run }: { run: ChecklistRun }) {
    const cfg = useChecklistConfig();
    const { today, can, openRun } = cfg;
    const meta = runStatusMeta(run, today);
    const StatusIcon = meta.Icon;
    const started = run.status === 'in_progress';
    const flags = run.template?.flags;
    const action = actionFor(run, can.run);
    const ActionIcon = action.Icon;

    return (
        <div className="flex flex-col rounded-xl border border-border bg-card p-3.5 shadow-sm transition hover:border-primary/40 hover:shadow-sm">
            <div className="flex items-start gap-3">
                <CategoryIcon category={run.template?.category ?? null} box={38} size={19} />
                <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-2">
                        <h4 className="min-w-0 text-sm font-semibold leading-snug">{run.template?.name}</h4>
                        <StatusBadge tone={meta.tone} Icon={StatusIcon}>
                            {meta.label}
                        </StatusBadge>
                    </div>
                    <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                        <span className="inline-flex items-center gap-1">
                            <Building2 className="h-3 w-3" />
                            {run.site?.name}
                        </span>
                        <span>·</span>
                        <span>{freqLabel(cfg, run.template?.frequency)}</span>
                        <span>·</span>
                        <span>{relDay(run.scheduled_date, today)}</span>
                    </div>
                </div>
            </div>
            {started ? (
                <div className="mt-3 flex items-center gap-2">
                    <Progress value={run.pct} className="flex-1" />
                    <span className="text-xs tabular-nums text-muted-foreground">{run.pct}%</span>
                </div>
            ) : null}
            <div className="mt-3 flex items-center justify-between gap-2">
                <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-muted text-[9px] font-semibold">
                        {initials(run.assignee)}
                    </span>
                    {run.assignee}
                </div>
                <div className="flex items-center gap-1.5">
                    {flags?.hazard ? (
                        <span
                            title="Failures raise a hazard"
                            className="flex h-6 w-6 items-center justify-center rounded-md bg-status-critical-bg text-status-critical"
                        >
                            <TriangleAlert className="h-3 w-3" />
                        </span>
                    ) : null}
                    {flags?.photo ? (
                        <span
                            title="Photo evidence"
                            className="flex h-6 w-6 items-center justify-center rounded-md bg-muted text-muted-foreground"
                        >
                            <Camera className="h-3 w-3" />
                        </span>
                    ) : null}
                    <Button size="sm" variant={action.variant} onClick={() => openRun(run.id)}>
                        <ActionIcon className="h-3.5 w-3.5" />
                        {action.label}
                    </Button>
                </div>
            </div>
        </div>
    );
}
