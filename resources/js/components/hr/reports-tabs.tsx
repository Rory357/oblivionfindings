import { router } from '@inertiajs/react';
import { BarChart3, Bookmark, Settings, Webhook, Wrench } from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type ReportsTab = 'index' | 'builder' | 'saved' | 'automations' | 'webhooks';

const TAB_URLS: Record<ReportsTab, string> = {
    index: '/hr/reports',
    builder: '/hr/reports/builder',
    saved: '/hr/reports/saved',
    automations: '/hr/reports/automations',
    webhooks: '/hr/reports/webhooks',
};

const ITEMS: HrTabItem[] = [
    { id: 'index', label: 'Reports', icon: BarChart3, tone: 'primary' },
    { id: 'builder', label: 'Builder', icon: Wrench, tone: 'info' },
    { id: 'saved', label: 'Saved', icon: Bookmark, tone: 'violet' },
    { id: 'automations', label: 'Automations', icon: Settings, tone: 'success' },
    { id: 'webhooks', label: 'Webhooks', icon: Webhook, tone: 'warning' },
];

/**
 * Section-level tab strip shared across the HR Reports pages so the cluster
 * reads as one hub. Navigates between the existing pages (each renders this strip
 * with its own `active`); standardised on the Rostering TabStrip look.
 */
export function ReportsTabs({ active }: { active: ReportsTab }) {
    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as ReportsTab]);
            }}
            items={ITEMS}
            ariaLabel="Reports views"
        />
    );
}

export default ReportsTabs;
