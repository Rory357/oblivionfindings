import { router, usePage } from '@inertiajs/react';
import { BookOpen, GraduationCap } from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type TrainingTab = 'dashboard' | 'catalog';

const TAB_URLS: Record<TrainingTab, string> = {
    dashboard: '/hr/training',
    catalog: '/hr/training/catalog',
};

type HrCan = {
    training?: { view?: boolean };
};

/**
 * Section-level tab strip for the standalone Training hub (pulled out of the
 * Compliance hub in S7): Dashboard (staff training records — overdue / due-soon
 * / by-site) + Catalog (the course library). Both sit behind hr.training.view;
 * the active tab is always shown so the current page never hides its own tab.
 * (Assignments isn't a built page, so the hub honestly carries 2 tabs.)
 */
export function TrainingTabs({ active }: { active: TrainingTab }) {
    const hr = (usePage().props as { auth?: { can?: { hr?: HrCan } } }).auth?.can
        ?.hr;

    const canView = !!hr?.training?.view;

    const all: Array<{ item: HrTabItem; show: boolean }> = [
        {
            item: { id: 'dashboard', label: 'Dashboard', icon: GraduationCap, tone: 'primary' },
            show: canView,
        },
        {
            item: { id: 'catalog', label: 'Catalog', icon: BookOpen, tone: 'info' },
            show: canView,
        },
    ];

    const items: HrTabItem[] = all
        .filter((t) => t.show || t.item.id === active)
        .map((t) => t.item);

    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as TrainingTab]);
            }}
            items={items}
            ariaLabel="Training views"
        />
    );
}

export default TrainingTabs;
