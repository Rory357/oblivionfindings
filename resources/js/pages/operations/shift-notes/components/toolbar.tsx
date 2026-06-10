/* Status tabs + clear-filters + shown count + Cards/List view switcher. */
import { TabStrip, type RosterTabItem } from '@/components/rostering/tab-strip';
import { cn } from '@/lib/utils';
import {
    CheckCircle2,
    Clock,
    Flag,
    LayoutGrid,
    ListChecks,
    X,
} from 'lucide-react';

import type { StatusTab, ViewMode } from './shared';

export type TabCounts = {
    all: number;
    flagged: number;
    awaiting: number;
    reviewed: number;
};

const TABS: {
    id: StatusTab;
    label: string;
    key: keyof TabCounts;
    icon: RosterTabItem['icon'];
    tone: RosterTabItem['tone'];
}[] = [
    { id: 'all', label: 'All', key: 'all', icon: ListChecks, tone: 'primary' },
    {
        id: 'flagged',
        label: 'Flagged',
        key: 'flagged',
        icon: Flag,
        tone: 'critical',
    },
    {
        id: 'awaiting',
        label: 'Awaiting review',
        key: 'awaiting',
        icon: Clock,
        tone: 'warning',
    },
    {
        id: 'reviewed',
        label: 'Reviewed',
        key: 'reviewed',
        icon: CheckCircle2,
        tone: 'success',
    },
];

const VIEWS: { id: ViewMode; label: string; icon: typeof LayoutGrid }[] = [
    { id: 'cards', label: 'Cards', icon: LayoutGrid },
    { id: 'list', label: 'List', icon: ListChecks },
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
            <TabStrip
                value={tab}
                onChange={(next) => onTab(next as StatusTab)}
                ariaLabel="Shift note status"
                items={TABS.map((t) => ({
                    id: t.id,
                    label: t.label,
                    icon: t.icon,
                    tone: t.tone,
                    badge: counts[t.key],
                }))}
            />

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
