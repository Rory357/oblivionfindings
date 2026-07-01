import { router, usePage } from '@inertiajs/react';
import { DoorOpen, FolderKanban, MessageSquareQuote, UserPlus } from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type LifecycleTab = 'onboarding' | 'offboarding' | 'cases' | 'exit-interviews';

const TAB_URLS: Record<LifecycleTab, string> = {
    onboarding: '/hr/onboarding',
    offboarding: '/hr/offboarding',
    cases: '/hr/cases',
    'exit-interviews': '/hr/exit-interviews',
};

type HrCan = {
    onboarding?: { view?: boolean };
    cases?: { view?: boolean };
    'exit-interviews'?: { view?: boolean; manage?: boolean };
};

/**
 * Section-level tab strip shared across the Employee Lifecycle pages
 * (Onboarding, Offboarding, Cases & disciplinary, Exit interviews) so the
 * cluster reads as one family. The surfaces sit behind DIFFERENT permission
 * gates — Onboarding/Offboarding are hr.onboarding.view, Cases is
 * hr.cases.view, Exit interviews is hr.exit-interviews.view|manage — so tabs
 * are filtered by the shared auth.can flags: a user only sees the views they
 * can open (no 403-on-click). The active tab is always shown so the current
 * page never hides its own tab. Count badges come from the optional
 * `tabCounts` page prop (pages that don't pass it render no badge).
 */
export function LifecycleTabs({ active }: { active: LifecycleTab }) {
    const page = usePage().props as {
        auth?: { can?: { hr?: HrCan } };
        tabCounts?: Partial<Record<LifecycleTab, number>>;
    };
    const hr = page.auth?.can?.hr;
    const counts = page.tabCounts ?? {};

    const badge = (id: LifecycleTab) =>
        typeof counts[id] === 'number' && counts[id] ? counts[id] : undefined;

    const all: Array<{ item: HrTabItem; show: boolean }> = [
        {
            item: { id: 'onboarding', label: 'Onboarding', icon: UserPlus, tone: 'primary', badge: badge('onboarding') },
            show: !!hr?.onboarding?.view,
        },
        {
            item: { id: 'offboarding', label: 'Offboarding', icon: DoorOpen, tone: 'warning', badge: badge('offboarding') },
            show: !!hr?.onboarding?.view,
        },
        {
            item: { id: 'cases', label: 'Cases & disciplinary', icon: FolderKanban, tone: 'critical', badge: badge('cases') },
            show: !!hr?.cases?.view,
        },
        {
            item: {
                id: 'exit-interviews',
                label: 'Exit interviews',
                icon: MessageSquareQuote,
                tone: 'violet',
                badge: badge('exit-interviews'),
            },
            show: !!hr?.['exit-interviews']?.view || !!hr?.['exit-interviews']?.manage,
        },
    ];

    const items: HrTabItem[] = all
        .filter((t) => t.show || t.item.id === active)
        .map((t) => t.item);

    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as LifecycleTab]);
            }}
            items={items}
            ariaLabel="Employee lifecycle views"
            className="-mt-1"
        />
    );
}

export default LifecycleTabs;
