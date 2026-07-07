import { router, usePage } from '@inertiajs/react';
import { Building2, Download, Landmark, Receipt } from 'lucide-react';

import {
    FinanceTabs,
    tabCountBadge,
    type FinanceHubCounts,
    type FinanceTabItem,
} from './finance-tabs';

/**
 * Canonical Tax & Compliance hub tabs. Mirrors the Ledger/Banking hubs
 * (heterogeneous permissions): GST returns is `finance.tax.view`, IRD filings is
 * `finance.tax.manage`, audit exports is `finance.reports.view`, consolidation is
 * `finance.admin`. The tabs SPA-navigate under the shared finance hero; `requires`
 * mirrors each route's gate so a tab the user can't open is never shown.
 */
export type TaxTabId = 'gst-returns' | 'ird-filings' | 'audit-exports' | 'consolidation';

// `can` is the same loosely-typed permission tree the sidebar consumes (auth.can).
type CanTree = Record<string, any> | undefined;
type TaxTabDef = FinanceTabItem & {
    id: TaxTabId;
    href: string;
    requires: (can: CanTree) => boolean;
};

export const TAX_TABS: TaxTabDef[] = [
    { id: 'gst-returns', label: 'GST returns', icon: Receipt, tone: 'primary', href: '/finance/gst-returns', requires: (c) => !!c?.finance?.tax?.view },
    { id: 'ird-filings', label: 'IRD filing', icon: Landmark, tone: 'info', href: '/finance/ird-filings', requires: (c) => !!c?.finance?.tax?.manage },
    { id: 'audit-exports', label: 'Audit exports', icon: Download, tone: 'success', href: '/finance/audit-exports', requires: (c) => !!c?.finance?.reports?.view },
    { id: 'consolidation', label: 'Consolidation', icon: Building2, tone: 'violet', href: '/finance/consolidation', requires: (c) => !!c?.finance?.admin },
];

/**
 * The Tax & Compliance tab strip, rendered in each tax sub-page's PageHero
 * `footer` slot (the Rostering pattern). Tabs SPA-navigate across the sub-routes so
 * the hub feels like one surface while each page keeps its own controller + bespoke
 * hero. Tabs are filtered to what the user can open (the active tab is always
 * shown). Drop into every tax Index page:
 * `<PageHero … footer={<TaxTabsFooter active="…" />} />`.
 */
export function TaxTabsFooter({ active }: { active: TaxTabId }) {
    const page = usePage();
    const can = (page.props as { auth?: { can?: CanTree } })?.auth?.can;
    const counts =
        (page.props as { financeHubCounts?: FinanceHubCounts | null })
            .financeHubCounts?.['tax'] ?? {};

    const visible = TAX_TABS.filter((t) => t.id === active || t.requires(can));

    const handleTab = (id: string) => {
        if (id === active) return;
        const target = TAX_TABS.find((t) => t.id === id);
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
                badge: tabCountBadge(counts[t.id]),
            }))}
            ariaLabel="Tax and compliance views"
        />
    );
}

export default TaxTabsFooter;
