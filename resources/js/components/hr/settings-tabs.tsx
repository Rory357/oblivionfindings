import { router } from '@inertiajs/react';
import { History, Settings2, Webhook } from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type SettingsTab = 'webhooks' | 'custom-fields' | 'audit-log';

const TAB_URLS: Record<SettingsTab, string> = {
    webhooks: '/hr/settings/webhooks',
    'custom-fields': '/hr/settings/custom-fields',
    'audit-log': '/hr/settings/audit-log',
};

const ITEMS: HrTabItem[] = [
    { id: 'webhooks', label: 'Webhooks', icon: Webhook, tone: 'primary' },
    { id: 'custom-fields', label: 'Custom fields', icon: Settings2, tone: 'info' },
    { id: 'audit-log', label: 'Audit log', icon: History, tone: 'violet' },
];

/**
 * Section-level tab strip shared across the HR Settings pages so the cluster
 * reads as one hub. Navigates between the existing pages (each renders this strip
 * with its own `active`); standardised on the Rostering TabStrip look.
 */
export function SettingsTabs({ active }: { active: SettingsTab }) {
    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as SettingsTab]);
            }}
            items={ITEMS}
            ariaLabel="Settings views"
        />
    );
}

export default SettingsTabs;
