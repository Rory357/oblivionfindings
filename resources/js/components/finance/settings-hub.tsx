import { router, usePage } from '@inertiajs/react';
import { GitBranch, Link2 } from 'lucide-react';

import { FinanceTabs, type FinanceTabItem } from './finance-tabs';

/**
 * Canonical Finance Settings hub tabs — the home for finance configuration
 * surfaces that aren't tied to a specific ledger/banking workflow. Only the two
 * genuinely standalone admin surfaces live here: accounting integrations
 * (Xero/MYOB) and funding streams. (Fiscal periods, cost centres, currencies and
 * match rules are deliberately NOT here — they are already tabs of the Ledger /
 * Banking hubs, where they belong; duplicating them would fork the concept.)
 *
 * Both tabs are `finance.admin`; `requires` mirrors each route's gate so a tab
 * the user can't open is never shown.
 */
export type SettingsTabId = 'integrations' | 'funding-streams';

// `can` is the same loosely-typed permission tree the sidebar consumes (auth.can).
type CanTree = Record<string, any> | undefined;
type SettingsTabDef = FinanceTabItem & {
    id: SettingsTabId;
    href: string;
    requires: (can: CanTree) => boolean;
};

export const SETTINGS_TABS: SettingsTabDef[] = [
    {
        id: 'integrations',
        label: 'Integrations',
        icon: Link2,
        tone: 'primary',
        href: '/finance/integrations',
        requires: (c) => !!c?.finance?.admin,
    },
    {
        id: 'funding-streams',
        label: 'Funding streams',
        icon: GitBranch,
        tone: 'success',
        href: '/finance/funding-streams',
        requires: (c) => !!c?.finance?.admin,
    },
];

/**
 * The Finance Settings tab strip, rendered in each settings sub-page's PageHero
 * `footer` slot (the Rostering pattern). Tabs SPA-navigate across the sub-routes
 * so the hub feels like one surface while each page keeps its own controller +
 * bespoke hero. Tabs are filtered to what the user can open (the active tab is
 * always shown). Drop into every settings Index page:
 * `<PageHero … footer={<SettingsTabsFooter active="…" />} />`.
 */
export function SettingsTabsFooter({ active }: { active: SettingsTabId }) {
    const page = usePage();
    const can = (page.props as { auth?: { can?: CanTree } })?.auth?.can;

    const visible = SETTINGS_TABS.filter(
        (t) => t.id === active || t.requires(can),
    );

    const handleTab = (id: string) => {
        if (id === active) return;
        const target = SETTINGS_TABS.find((t) => t.id === id);
        if (target) {
            router.visit(target.href, { preserveScroll: false });
        }
    };

    return (
        <FinanceTabs
            value={active}
            onChange={handleTab}
            items={visible.map((t) => ({
                id: t.id,
                label: t.label,
                icon: t.icon,
                tone: t.tone,
            }))}
            ariaLabel="Finance settings views"
        />
    );
}

export default SettingsTabsFooter;
