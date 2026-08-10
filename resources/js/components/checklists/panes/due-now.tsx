import {
    AlertTriangle,
    CalendarClock,
    CalendarDays,
    Search,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';

import { cn } from '@/lib/utils';

import { Card as GuardrailCard } from '@/components/ui/card';
import type { PaneCtx } from '../context';
import {
    CountBadge,
    Empty,
    ViewToggle,
    type ChecklistView,
} from '../primitives';
import { RunListRow, WorklistCard } from '../run-cards';
import type { ChecklistRun } from '../types';

const GROUP_BG: Record<string, string> = {
    critical: 'bg-status-critical-bg text-status-critical',
    warning: 'bg-status-warning-bg text-status-warning',
    info: 'bg-status-info-bg text-status-info',
};

export function DueNowPane({ ctx }: { ctx: PaneCtx }) {
    const [view, setView] = useState<ChecklistView>('board');
    const q = ctx.query.toLowerCase();
    const today = ctx.today;

    const match = (r: ChecklistRun) => {
        const matchQ =
            !q ||
            (r.template?.name ?? '').toLowerCase().includes(q) ||
            (r.site?.name ?? '').toLowerCase().includes(q);
        const matchCat = ctx.cat === 'all' || r.template?.category === ctx.cat;
        return matchQ && matchCat;
    };

    const groups: {
        key: string;
        label: string;
        tone: string;
        Icon: LucideIcon;
        items: ChecklistRun[];
    }[] = [
        {
            key: 'overdue',
            label: 'Overdue',
            tone: 'critical',
            Icon: AlertTriangle,
            items: ctx.runs
                .filter((r) => r.scheduled_date && r.scheduled_date < today)
                .filter(match),
        },
        {
            key: 'today',
            label: 'Due today',
            tone: 'warning',
            Icon: CalendarClock,
            items: ctx.runs
                .filter((r) => r.scheduled_date === today)
                .filter(match),
        },
        {
            key: 'soon',
            label: 'Coming up',
            tone: 'info',
            Icon: CalendarDays,
            items: ctx.runs
                .filter((r) => r.scheduled_date && r.scheduled_date > today)
                .filter(match),
        },
    ];
    const empty = groups.every((g) => g.items.length === 0);

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    Your worklist — start or finish the checklists waiting on
                    you
                </p>
                <ViewToggle value={view} onChange={setView} />
            </div>
            {empty ? (
                <GuardrailCard
                    unstyled
                    className="rounded-xl border border-border bg-card p-2 shadow-sm"
                >
                    <Empty
                        Icon={Search}
                        title="Nothing matches your search."
                        sub="Try a different term or category."
                    />
                </GuardrailCard>
            ) : null}
            {groups.map((g) =>
                g.items.length === 0 ? null : (
                    <div key={g.key}>
                        <div className="mb-2 flex items-center gap-2">
                            <span
                                className={cn(
                                    'flex h-6 w-6 items-center justify-center rounded-md',
                                    GROUP_BG[g.tone],
                                )}
                            >
                                <g.Icon className="h-3.5 w-3.5" />
                            </span>
                            <h3 className="text-sm font-semibold">{g.label}</h3>
                            <CountBadge>{g.items.length}</CountBadge>
                        </div>
                        {view === 'board' ? (
                            <div className="grid gap-2.5 md:grid-cols-2 xl:grid-cols-3">
                                {g.items.map((r) => (
                                    <WorklistCard key={r.id} run={r} />
                                ))}
                            </div>
                        ) : (
                            <GuardrailCard
                                unstyled
                                className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card shadow-sm"
                            >
                                {g.items.map((r) => (
                                    <RunListRow key={r.id} run={r} />
                                ))}
                            </GuardrailCard>
                        )}
                    </div>
                ),
            )}
        </div>
    );
}
