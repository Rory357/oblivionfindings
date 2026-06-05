import { useState } from 'react';

import { runStatusMeta } from '../category';
import type { PaneCtx } from '../context';
import { Dropdown, Empty, ViewToggle, type ChecklistView } from '../primitives';
import { RunListRow, WorklistCard } from '../run-cards';
import type { ChecklistRun } from '../types';

const STATUS_OPTIONS = [
    { value: 'all', label: 'All statuses' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'scheduled', label: 'Scheduled' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'completed', label: 'Completed' },
    { value: 'skipped', label: 'Skipped' },
];

export function RunsPane({ ctx }: { ctx: PaneCtx }) {
    const [status, setStatus] = useState('all');
    const [view, setView] = useState<ChecklistView>('board');
    const q = ctx.query.toLowerCase();
    const today = ctx.today;

    const all: ChecklistRun[] = [
        ...ctx.runs,
        ...ctx.history,
        ...ctx.skippedRuns,
    ];
    const filtered = all.filter((r) => {
        const meta = runStatusMeta(r, today);
        const matchQ =
            !q ||
            (r.template?.name ?? '').toLowerCase().includes(q) ||
            (r.site?.name ?? '').toLowerCase().includes(q);
        const matchCat = ctx.cat === 'all' || r.template?.category === ctx.cat;
        const matchS =
            status === 'all' ||
            (status === 'overdue' && meta.label === 'Overdue') ||
            (status === 'completed' && r.status === 'completed') ||
            (status === 'skipped' && r.status === 'skipped') ||
            (status === 'scheduled' && meta.label === 'Scheduled') ||
            (status === 'in_progress' && meta.label === 'In progress');
        return matchQ && matchCat && matchS;
    });

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card px-5 py-3.5 shadow-sm">
                <div>
                    <h3 className="text-base font-semibold">All runs</h3>
                    <p className="text-sm text-muted-foreground">
                        {filtered.length} runs · scheduled, in-progress,
                        overdue, completed &amp; skipped
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Dropdown
                        value={status}
                        onChange={setStatus}
                        options={STATUS_OPTIONS}
                        className="w-40"
                    />
                    <ViewToggle value={view} onChange={setView} />
                </div>
            </div>
            {filtered.length === 0 ? (
                <div className="rounded-xl border border-border bg-card p-2 shadow-sm">
                    <Empty title="No runs match your filters." />
                </div>
            ) : view === 'board' ? (
                <div className="grid gap-2.5 md:grid-cols-2 xl:grid-cols-3">
                    {filtered.map((r) => (
                        <WorklistCard key={`${r.id}-${r.status}`} run={r} />
                    ))}
                </div>
            ) : (
                <div className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    {filtered.map((r) => (
                        <RunListRow key={`${r.id}-${r.status}`} run={r} />
                    ))}
                </div>
            )}
        </div>
    );
}
