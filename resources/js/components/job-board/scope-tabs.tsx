import {
    Bookmark,
    CheckCircle2,
    Circle,
    RefreshCw,
    Sparkles,
    type LucideIcon,
} from 'lucide-react';

import { PageHeaderRail } from '@/components/page/page-header';

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
        <PageHeaderRail
            items={visibleTabs.map((tab) => ({
                key: tab.id,
                label: tab.label,
                icon: tab.icon,
                count: counts[tab.id] ?? 0,
                testId: tab.testId,
            }))}
            value={scope}
            onSelect={onScopeChange}
            ariaLabel="Job board view"
        />
    );
}
export default ScopeTabs;
