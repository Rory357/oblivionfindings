import { router, usePage } from '@inertiajs/react';
import { ClipboardCheck, Mail } from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type OnboardingTab = 'checklists' | 'emails';

const TAB_URLS: Record<OnboardingTab, string> = {
    checklists: '/hr/onboarding',
    emails: '/hr/onboarding/emails',
};

type HrCan = {
    onboarding?: { view?: boolean; manage?: boolean };
};

/**
 * Section-level tab strip shared across the Onboarding pages (Checklists +
 * Emails) so the cluster reads as one hub. The Emails template editor is
 * controller-gated on hr.onboarding.manage (stricter than the Checklists hub's
 * hr.onboarding.view), so the Emails tab is filtered on the shared auth.can
 * flags — a view-only user never sees a tab that would 403. The active tab is
 * always shown so the current page never hides its own tab.
 */
export function OnboardingTabs({ active }: { active: OnboardingTab }) {
    const hr = (usePage().props as { auth?: { can?: { hr?: HrCan } } }).auth?.can
        ?.hr;

    const all: Array<{ item: HrTabItem; show: boolean }> = [
        {
            item: {
                id: 'checklists',
                label: 'Checklists',
                icon: ClipboardCheck,
                tone: 'primary',
            },
            show: !!hr?.onboarding?.view,
        },
        {
            item: { id: 'emails', label: 'Emails', icon: Mail, tone: 'info' },
            show: !!hr?.onboarding?.manage,
        },
    ];

    const items: HrTabItem[] = all
        .filter((t) => t.show || t.item.id === active)
        .map((t) => t.item);

    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as OnboardingTab]);
            }}
            items={items}
            ariaLabel="Onboarding views"
            className="mb-6"
        />
    );
}

export default OnboardingTabs;
