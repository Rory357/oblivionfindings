import {
    Bookmark,
    CheckCircle2,
    Circle,
    RefreshCw,
    Sparkles,
    type LucideIcon,
} from 'lucide-react';

import { cn } from '@/lib/utils';

import type { JobBoardScope } from './types';

interface ScopeTabsProps {
    scope: JobBoardScope;
    counts: Record<JobBoardScope, number>;
    onScopeChange: (next: JobBoardScope) => void;
    /** When true, render the coordinator-only "Pending approval" tab. */
    showApprovals?: boolean;
}

const TABS: Array<{
    id: JobBoardScope;
    label: string;
    icon: LucideIcon;
    testId: string;
    coordinatorOnly?: boolean;
}> = [
    {
        id: 'for-you',
        label: 'For you',
        icon: Sparkles,
        testId: 'job-board-scope-for-you-tab',
    },
    {
        id: 'all',
        label: 'All open',
        icon: Circle,
        testId: 'job-board-all-tab',
    },
    {
        id: 'approvals',
        label: 'Pending approval',
        icon: CheckCircle2,
        testId: 'job-board-scope-approvals-tab',
        coordinatorOnly: true,
    },
    {
        id: 'mine',
        label: 'My claims',
        icon: Bookmark,
        testId: 'job-board-my-claims-tab',
    },
    {
        id: 'replacements',
        label: 'Replacements',
        icon: RefreshCw,
        testId: 'job-board-replacements-tab',
    },
];

export function ScopeTabs({
    scope,
    counts,
    onScopeChange,
    showApprovals = false,
}: ScopeTabsProps) {
    const visibleTabs = TABS.filter(
        (tab) => !tab.coordinatorOnly || showApprovals,
    );
    return (
        <nav
            role="tablist"
            aria-label="Job board view"
            className="flex w-max max-w-full gap-1 overflow-x-auto rounded-xl border border-border bg-card p-1 shadow-sm"
        >
            {visibleTabs.map((tab) => {
                const Icon = tab.icon;
                const isActive = scope === tab.id;
                return (
                    <button
                        key={tab.id}
                        type="button"
                        role="tab"
                        data-test={tab.testId}
                        aria-selected={isActive}
                        onClick={() => onScopeChange(tab.id)}
                        className={cn(
                            'inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-3.5 py-2 text-sm font-semibold transition-colors',
                            isActive
                                ? 'bg-accent text-[var(--brand-deep,var(--primary))]'
                                : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                        )}
                    >
                        <Icon
                            className={cn(
                                'h-4 w-4',
                                isActive
                                    ? 'text-primary'
                                    : 'text-muted-foreground/80',
                            )}
                        />
                        <span>{tab.label}</span>
                        <span
                            className={cn(
                                'min-w-[22px] rounded-full px-1.5 py-[1px] text-center text-[11px] font-bold',
                                isActive
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-muted-foreground',
                            )}
                        >
                            {counts[tab.id] ?? 0}
                        </span>
                    </button>
                );
            })}
        </nav>
    );
}

export default ScopeTabs;
