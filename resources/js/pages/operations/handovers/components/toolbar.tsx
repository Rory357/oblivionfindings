/* Status tabs (cards/list only) + clear-filters + shown count + view switcher. */
import { cn } from '@/lib/utils';
import { GitBranch, LayoutGrid, ListChecks, X } from 'lucide-react';

import type { StatusTab, ViewMode } from './shared';

type TabCounts = {
    total: number;
    draft: number;
    submitted: number;
    acknowledged: number;
};

const TABS: { id: StatusTab; label: string; key: keyof TabCounts }[] = [
    { id: 'all', label: 'All', key: 'total' },
    { id: 'draft', label: 'Drafts', key: 'draft' },
    { id: 'submitted', label: 'Awaiting sign-off', key: 'submitted' },
    { id: 'acknowledged', label: 'Acknowledged', key: 'acknowledged' },
];

const VIEWS: { id: ViewMode; label: string; icon: typeof LayoutGrid }[] = [
    { id: 'cards', label: 'Cards', icon: LayoutGrid },
    { id: 'list', label: 'List', icon: ListChecks },
    { id: 'board', label: 'Board', icon: GitBranch },
];

export function Toolbar({
    tab,
    onTab,
    view,
    onView,
    counts,
    shown,
    total,
    hasFilters,
    onClearFilters,
}: {
    tab: StatusTab;
    onTab: (tab: StatusTab) => void;
    view: ViewMode;
    onView: (view: ViewMode) => void;
    counts: TabCounts;
    shown: number;
    total: number;
    hasFilters: boolean;
    onClearFilters: () => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-3">
            {view !== 'board' ? (
                <div
                    className="flex flex-wrap items-center gap-1 rounded-xl border border-border bg-card p-1"
                    role="tablist"
                    aria-label="Filter by status"
                >
                    {TABS.map((t) => (
                        <button
                            key={t.id}
                            type="button"
                            role="tab"
                            aria-selected={tab === t.id}
                            onClick={() => onTab(t.id)}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[13px] font-semibold transition-colors',
                                tab === t.id
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                            )}
                        >
                            {t.label}
                            <span
                                className={cn(
                                    'rounded-full px-1.5 text-[11px] tabular-nums',
                                    tab === t.id
                                        ? 'bg-primary-foreground/20'
                                        : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {counts[t.key]}
                            </span>
                        </button>
                    ))}
                </div>
            ) : null}

            <div className="ml-auto flex flex-wrap items-center gap-3">
                {hasFilters ? (
                    <button
                        type="button"
                        onClick={onClearFilters}
                        className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <X className="h-3.5 w-3.5" />
                        Clear filters
                    </button>
                ) : null}
                <span className="text-[12.5px] text-muted-foreground tabular-nums">
                    {shown} of {total} shown
                </span>
                <div
                    className="flex items-center gap-1 rounded-xl border border-border bg-card p-1"
                    role="tablist"
                    aria-label="View mode"
                >
                    {VIEWS.map((v) => {
                        const Icon = v.icon;
                        return (
                            <button
                                key={v.id}
                                type="button"
                                role="tab"
                                aria-selected={view === v.id}
                                onClick={() => onView(v.id)}
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-[12.5px] font-semibold transition-colors',
                                    view === v.id
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                                )}
                            >
                                <Icon className="h-3.5 w-3.5" />
                                {v.label}
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
