import { router } from '@inertiajs/react';
import { BarChart3, Briefcase, LayoutGrid, ListChecks, Users } from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type RecruitmentTab =
    | 'pipeline'
    | 'board'
    | 'jobs'
    | 'analytics'
    | 'kits';

const TAB_URLS: Record<RecruitmentTab, string> = {
    pipeline: '/hr/recruitment',
    board: '/hr/recruitment/kanban',
    jobs: '/hr/recruitment/jobs',
    analytics: '/hr/recruitment/analytics',
    kits: '/hr/recruitment/kits',
};

const ITEMS: HrTabItem[] = [
    { id: 'pipeline', label: 'Pipeline', icon: Users, tone: 'primary' },
    { id: 'board', label: 'Board', icon: LayoutGrid, tone: 'info' },
    { id: 'jobs', label: 'Jobs', icon: Briefcase, tone: 'violet' },
    { id: 'analytics', label: 'Analytics', icon: BarChart3, tone: 'success' },
    { id: 'kits', label: 'Interview kits', icon: ListChecks, tone: 'warning' },
];

/**
 * Section-level tab strip shared across the Recruitment pages so the whole
 * cluster reads as one hub. Navigates between the existing pages (each renders
 * this strip with its own `active`); standardised on the Rostering TabStrip look.
 */
export function RecruitmentTabs({ active }: { active: RecruitmentTab }) {
    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as RecruitmentTab]);
            }}
            items={ITEMS}
            ariaLabel="Recruitment views"
            className="mb-6"
        />
    );
}

export default RecruitmentTabs;
