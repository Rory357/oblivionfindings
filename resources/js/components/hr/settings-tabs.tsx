import { router } from '@inertiajs/react';
import { History, Settings2, Webhook, Workflow } from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type SettingsTab =
    | 'automations'
    | 'webhooks'
    | 'custom-fields'
    | 'audit-log';

const TAB_URLS: Record<SettingsTab, string> = {
    automations: '/hr/settings/automations',
    webhooks: '/hr/settings/webhooks',
    'custom-fields': '/hr/settings/custom-fields',
    'audit-log': '/hr/settings/audit-log',
};

const ITEMS: HrTabItem[] = [
    { id: 'automations', label: 'Automations', icon: Workflow, tone: 'primary' },
    { id: 'webhooks', label: 'Webhooks', icon: Webhook, tone: 'info' },
    { id: 'custom-fields', label: 'Custom fields', icon: Settings2, tone: 'success' },
    { id: 'audit-log', label: 'Audit log', icon: History, tone: 'violet' },
];

/**
 * Section-level tab strip shared across the HR Settings pages so the cluster
 * reads as one hub. The whole settings prefix sits behind hr.settings.manage,
 * so the strip is static (no per-tab gating). Automations + Webhooks were moved
 * here from the Reports hub. Navigates via router.visit; standardised on the
 * Rostering TabStrip look.
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
            className="mb-6"
        />
    );
}

export default SettingsTabs;
