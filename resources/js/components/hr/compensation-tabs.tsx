import { router, usePage } from '@inertiajs/react';
import { Banknote, ClipboardCheck, Heart, Layers } from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type CompensationTab = 'bands' | 'reviews' | 'bonuses' | 'benefits';

const TAB_URLS: Record<CompensationTab, string> = {
    bands: '/hr/compensation/bands',
    reviews: '/hr/compensation/reviews',
    bonuses: '/hr/compensation/bonuses',
    benefits: '/hr/compensation/benefits',
};

type HrCan = {
    compensation?: { view?: boolean };
    benefits?: { view?: boolean };
};

/**
 * Section-level tab strip shared across the Compensation & Benefits hub pages.
 * The Benefits tab sits behind a DIFFERENT gate (hr.benefits.view) from the
 * Salary bands / Pay reviews / Bonuses surfaces (hr.compensation.view), so tabs
 * are filtered by the shared auth.can flags — a user only sees the views they can
 * open (no 403-on-click). The active tab is always shown so the current page
 * never hides its own tab.
 */
export function CompensationTabs({ active }: { active: CompensationTab }) {
    const hr = (usePage().props as { auth?: { can?: { hr?: HrCan } } }).auth?.can
        ?.hr;

    const all: Array<{ item: HrTabItem; show: boolean }> = [
        {
            item: { id: 'bands', label: 'Salary bands', icon: Layers, tone: 'primary' },
            show: !!hr?.compensation?.view,
        },
        {
            item: { id: 'reviews', label: 'Pay reviews', icon: ClipboardCheck, tone: 'info' },
            show: !!hr?.compensation?.view,
        },
        {
            item: { id: 'bonuses', label: 'Bonuses', icon: Banknote, tone: 'success' },
            show: !!hr?.compensation?.view,
        },
        {
            item: { id: 'benefits', label: 'Benefits', icon: Heart, tone: 'violet' },
            show: !!hr?.benefits?.view,
        },
    ];

    const items: HrTabItem[] = all
        .filter((t) => t.show || t.item.id === active)
        .map((t) => t.item);

    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as CompensationTab]);
            }}
            items={items}
            ariaLabel="Compensation views"
        />
    );
}

export default CompensationTabs;
