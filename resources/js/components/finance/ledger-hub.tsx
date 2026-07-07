import { router, usePage } from '@inertiajs/react';
import {
    Banknote,
    BookOpen,
    Building2,
    CalendarRange,
    Coins,
    Landmark,
    RefreshCw,
} from 'lucide-react';

import {
    FinanceTabs,
    tabCountBadge,
    type FinanceHubCounts,
    type FinanceTabItem,
} from './finance-tabs';

/**
 * Canonical General Ledger hub tabs. Each sub-area keeps its own route + data;
 * the tabs SPA-navigate between them under the shared finance hero so the Ledger
 * hub reads as one Rostering-grade surface. `requires` mirrors the route's
 * permission gate so a tab a user can't open is never shown (no 403 dead tabs).
 * Add new ledger tabs here only.
 */
export type LedgerTabId =
    | 'accounts'
    | 'journals'
    | 'cost-centres'
    | 'fiscal-periods'
    | 'currencies'
    | 'fx-revaluations'
    | 'fixed-assets';

// `can` is the same loosely-typed permission tree the sidebar consumes (auth.can).
type CanTree = Record<string, any> | undefined;
type LedgerTabDef = FinanceTabItem & {
    id: LedgerTabId;
    href: string;
    requires: (can: CanTree) => boolean;
};

export const LEDGER_TABS: LedgerTabDef[] = [
    { id: 'accounts', label: 'Chart of accounts', icon: Landmark, tone: 'primary', href: '/finance/accounts', requires: (c) => !!c?.finance?.ledger?.view },
    { id: 'journals', label: 'Journals', icon: BookOpen, tone: 'info', href: '/finance/journals', requires: (c) => !!c?.finance?.ledger?.view },
    { id: 'cost-centres', label: 'Cost centres', icon: Building2, tone: 'violet', href: '/finance/cost-centres', requires: (c) => !!c?.finance?.admin },
    { id: 'fiscal-periods', label: 'Fiscal periods', icon: CalendarRange, tone: 'warning', href: '/finance/fiscal-periods', requires: (c) => !!c?.finance?.admin },
    { id: 'currencies', label: 'Currencies', icon: Coins, tone: 'success', href: '/finance/currencies', requires: (c) => !!c?.finance?.admin },
    { id: 'fx-revaluations', label: 'FX revaluations', icon: RefreshCw, tone: 'info', href: '/finance/fx-revaluations', requires: (c) => !!c?.finance?.ledger?.manage },
    { id: 'fixed-assets', label: 'Fixed assets', icon: Banknote, tone: 'primary', href: '/finance/fixed-assets', requires: (c) => !!c?.finance?.assets?.view },
];

/**
 * The General Ledger tab strip, rendered in each ledger sub-page's PageHero
 * `footer` slot (the Rostering pattern). Tabs SPA-navigate across the ledger
 * sub-routes so the hub feels like one surface while each page keeps its own
 * controller + bespoke hero stats/actions. Tabs are filtered to what the user
 * can open (the active tab is always shown). Drop into every ledger Index page:
 * `<PageHero … footer={<LedgerTabsFooter active="…" />} />`.
 */
export function LedgerTabsFooter({ active }: { active: LedgerTabId }) {
    const page = usePage();
    const can = (page.props as { auth?: { can?: CanTree } })?.auth?.can;
    const counts =
        (page.props as { financeHubCounts?: FinanceHubCounts | null })
            .financeHubCounts?.['ledger'] ?? {};

    const visible = LEDGER_TABS.filter((t) => t.id === active || t.requires(can));

    const handleTab = (id: string) => {
        if (id === active) return;
        const target = LEDGER_TABS.find((t) => t.id === id);
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
            ariaLabel="General ledger views"
        />
    );
}

export default LedgerTabsFooter;
