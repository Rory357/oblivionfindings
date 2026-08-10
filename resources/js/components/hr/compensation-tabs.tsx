import { router, usePage } from '@inertiajs/react';
import {
    Banknote,
    ClipboardCheck,
    Heart,
    History as HistoryIcon,
    Layers,
    Receipt,
    Settings as SettingsIcon,
} from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type CompensationTab =
    | 'bands'
    | 'reviews'
    | 'bonuses'
    | 'benefits'
    | 'expenses'
    | 'history'
    | 'settings';

const TAB_URLS: Record<CompensationTab, string> = {
    bands: '/hr/compensation/bands',
    reviews: '/hr/compensation/reviews',
    bonuses: '/hr/compensation/bonuses',
    benefits: '/hr/compensation/benefits',
    expenses: '/hr/compensation/expenses',
    history: '/hr/compensation/history',
    settings: '/hr/compensation/settings',
};

type HrCan = {
    compensation?: { view?: boolean };
    benefits?: { view?: boolean };
    expenses?: { view?: boolean };
};

type TabCounts = Partial<Record<CompensationTab, number>>;

/**
 * Section-level tab strip shared across the Compensation & Benefits hub pages.
 * The Benefits + Expenses tabs sit behind DIFFERENT gates (hr.benefits.view /
 * hr.expenses.view) from the Salary bands / Pay reviews / Bonuses / History /
 * Settings surfaces (hr.compensation.view), so tabs are filtered by the shared
 * auth.can flags — a user only sees the views they can open (no 403-on-click).
 * Count badges come from the optional `tabCounts` page prop (pages that don't
 * pass it simply render no badge). The active tab is always shown so the current
 * page never hides its own tab.
 */
export function CompensationTabs({ active }: { active: CompensationTab }) {
    const page = usePage().props as {
        auth?: { can?: { hr?: HrCan } };
        tabCounts?: TabCounts;
    };
    const hr = page.auth?.can?.hr;
    const counts = page.tabCounts ?? {};

    const badge = (id: CompensationTab) =>
        typeof counts[id] === 'number' ? counts[id] : undefined;

    const all: Array<{ item: HrTabItem; show: boolean }> = [
        {
            item: {
                id: 'bands',
                label: 'Salary bands',
                icon: Layers,
                tone: 'primary',
                badge: badge('bands'),
            },
            show: !!hr?.compensation?.view,
        },
        {
            item: {
                id: 'reviews',
                label: 'Pay reviews',
                icon: ClipboardCheck,
                tone: 'info',
                badge: badge('reviews'),
            },
            show: !!hr?.compensation?.view,
        },
        {
            item: {
                id: 'bonuses',
                label: 'Bonuses',
                icon: Banknote,
                tone: 'success',
                badge: badge('bonuses'),
            },
            show: !!hr?.compensation?.view,
        },
        {
            item: {
                id: 'benefits',
                label: 'Benefits',
                icon: Heart,
                tone: 'violet',
                badge: badge('benefits'),
            },
            show: !!hr?.benefits?.view,
        },
        {
            item: {
                id: 'expenses',
                label: 'Expenses',
                icon: Receipt,
                tone: 'warning',
                badge: badge('expenses'),
            },
            show: !!hr?.expenses?.view,
        },
        {
            item: {
                id: 'history',
                label: 'History',
                icon: HistoryIcon,
                tone: 'info',
            },
            show: !!hr?.compensation?.view,
        },
        {
            item: {
                id: 'settings',
                label: 'Settings',
                icon: SettingsIcon,
                tone: 'primary',
            },
            show: !!hr?.compensation?.view,
        },
    ];

    const items: HrTabItem[] = all
        .filter((t) => t.show || t.item.id === active)
        .map((t) => t.item);

    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active)
                    router.visit(TAB_URLS[id as CompensationTab]);
            }}
            items={items}
            ariaLabel="Compensation views"
            className="mb-6"
        />
    );
}

export default CompensationTabs;
