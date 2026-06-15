import { router } from '@inertiajs/react';
import { Banknote, ClipboardCheck, Layers } from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type CompensationTab = 'bands' | 'reviews' | 'bonuses';

const TAB_URLS: Record<CompensationTab, string> = {
    bands: '/hr/compensation/bands',
    reviews: '/hr/compensation/reviews',
    bonuses: '/hr/compensation/bonuses',
};

const ITEMS: HrTabItem[] = [
    { id: 'bands', label: 'Salary bands', icon: Layers, tone: 'primary' },
    { id: 'reviews', label: 'Pay reviews', icon: ClipboardCheck, tone: 'info' },
    { id: 'bonuses', label: 'Bonuses', icon: Banknote, tone: 'success' },
];

/**
 * Section-level tab strip shared across the HR Compensation pages so the cluster
 * reads as one hub. Navigates between the existing pages (each renders this strip
 * with its own `active`); standardised on the Rostering TabStrip look.
 */
export function CompensationTabs({ active }: { active: CompensationTab }) {
    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as CompensationTab]);
            }}
            items={ITEMS}
            ariaLabel="Compensation views"
        />
    );
}

export default CompensationTabs;
