import { router, usePage } from '@inertiajs/react';
import { BarChart3, Building2, LayoutDashboard, Wallet } from 'lucide-react';

import { FinanceTabs, type FinanceTabItem } from './finance-tabs';

/**
 * Canonical Finance Overview hub tabs — the module's home. Merges the four
 * historical dashboards (main, executive, all-sites, cash) into one hub at
 * `/finance`; each tab keeps its own route + controller + bespoke hero (the
 * Ledger/Receivables hub pattern). Every tab is `finance.dashboard`-gated, so
 * the strip is homogeneous; `requires` keeps the contract explicit.
 */
export type OverviewTabId =
    | 'summary'
    | 'executive'
    | 'by-site'
    | 'cash-position';

type CanTree = Record<string, any> | undefined;
type OverviewTabDef = FinanceTabItem & {
    id: OverviewTabId;
    href: string;
    requires: (can: CanTree) => boolean;
};

const dashboard = (c: CanTree) => !!c?.finance?.dashboard;

export const OVERVIEW_TABS: OverviewTabDef[] = [
    {
        id: 'summary',
        label: 'Summary',
        icon: LayoutDashboard,
        tone: 'primary',
        href: '/finance',
        requires: dashboard,
    },
    {
        id: 'executive',
        label: 'Executive',
        icon: BarChart3,
        tone: 'violet',
        href: '/finance/executive-dashboard',
        requires: dashboard,
    },
    {
        id: 'by-site',
        label: 'By site',
        icon: Building2,
        tone: 'info',
        href: '/finance/sites',
        requires: dashboard,
    },
    {
        id: 'cash-position',
        label: 'Cash position',
        icon: Wallet,
        tone: 'success',
        href: '/finance/cash-position',
        requires: dashboard,
    },
];

/**
 * The Overview tab strip, rendered in each overview page's PageHero `footer`
 * slot: `<PageHero … footer={<OverviewTabsFooter active="…" />} />`.
 */
export function OverviewTabsFooter({ active }: { active: OverviewTabId }) {
    const page = usePage();
    const can = (page.props as { auth?: { can?: CanTree } })?.auth?.can;

    const visible = OVERVIEW_TABS.filter(
        (t) => t.id === active || t.requires(can),
    );

    const handleTab = (id: string) => {
        if (id === active) return;
        const target = OVERVIEW_TABS.find((t) => t.id === id);
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
            ariaLabel="Finance overview views"
        />
    );
}

export default OverviewTabsFooter;
