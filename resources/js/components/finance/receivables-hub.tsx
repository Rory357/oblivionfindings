import { router, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    BookOpen,
    CalendarRange,
    Coins,
    DollarSign,
    FileText,
    Receipt,
    RefreshCw,
} from 'lucide-react';

import {
    FinanceTabs,
    tabCountBadge,
    type FinanceHubCounts,
    type FinanceTabItem,
} from './finance-tabs';

/**
 * Canonical Sales & Receivables hub tabs. Each sub-area keeps its own route +
 * controller + bespoke hero; the tabs SPA-navigate between them under the shared
 * finance hero so AR reads as one Rostering-grade surface. `requires` mirrors the
 * route's permission gate so a tab a user can't open is never shown (no 403 dead
 * tabs). AR credit notes live in the Payables hub (they're `finance.ap.*`-gated),
 * so they are intentionally absent here. Add new AR tabs here only.
 */
export type ReceivablesTabId =
    | 'invoices'
    | 'quotes'
    | 'recurring-charges'
    | 'billing'
    | 'aged-ar'
    | 'statements'
    | 'price-books'
    | 'allocations';

// `can` is the same loosely-typed permission tree the sidebar consumes (auth.can).
type CanTree = Record<string, any> | undefined;
type ReceivablesTabDef = FinanceTabItem & {
    id: ReceivablesTabId;
    href: string;
    requires: (can: CanTree) => boolean;
};

// Every AR sub-route is gated `finance.ar.view`, so the whole strip shows for any
// AR-view user; the `requires` checks keep the contract explicit and future-proof.
const arView = (c: CanTree) => !!c?.finance?.ar?.view;

export const RECEIVABLES_TABS: ReceivablesTabDef[] = [
    {
        id: 'invoices',
        label: 'Invoices',
        icon: Receipt,
        tone: 'primary',
        href: '/finance/invoices',
        requires: arView,
    },
    {
        id: 'quotes',
        label: 'Quotes',
        icon: FileText,
        tone: 'info',
        href: '/finance/quotes',
        requires: arView,
    },
    {
        id: 'recurring-charges',
        label: 'Recurring charges',
        icon: RefreshCw,
        tone: 'violet',
        href: '/finance/recurring-charges',
        requires: arView,
    },
    {
        id: 'billing',
        label: 'Billing',
        icon: DollarSign,
        tone: 'success',
        href: '/finance/billing',
        requires: arView,
    },
    {
        id: 'aged-ar',
        label: 'Aged AR',
        icon: CalendarRange,
        tone: 'warning',
        href: '/finance/receivables',
        requires: arView,
    },
    {
        id: 'statements',
        label: 'Statements',
        icon: Coins,
        tone: 'info',
        href: '/finance/receivables/statements',
        requires: arView,
    },
    {
        id: 'price-books',
        label: 'Price books',
        icon: BookOpen,
        tone: 'primary',
        href: '/finance/price-books',
        requires: arView,
    },
    {
        id: 'allocations',
        label: 'Allocations',
        icon: ArrowLeftRight,
        tone: 'violet',
        href: '/finance/payment-allocations',
        requires: arView,
    },
];

/**
 * The Sales & Receivables tab strip, rendered in each AR sub-page's PageHero
 * `footer` slot (the Rostering pattern). Tabs SPA-navigate across the AR
 * sub-routes so the hub feels like one surface while each page keeps its own
 * controller + bespoke hero stats/actions. Tabs are filtered to what the user
 * can open (the active tab is always shown). Drop into every AR Index page:
 * `<PageHero … footer={<ReceivablesTabsFooter active="…" />} />`.
 */
export function ReceivablesTabsFooter({
    active,
}: {
    active: ReceivablesTabId;
}) {
    const page = usePage();
    const can = (page.props as { auth?: { can?: CanTree } })?.auth?.can;
    const counts =
        (page.props as { financeHubCounts?: FinanceHubCounts | null })
            .financeHubCounts?.['receivables'] ?? {};

    const visible = RECEIVABLES_TABS.filter(
        (t) => t.id === active || t.requires(can),
    );

    const handleTab = (id: string) => {
        if (id === active) return;
        const target = RECEIVABLES_TABS.find((t) => t.id === id);
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
            ariaLabel="Sales and receivables views"
        />
    );
}

export default ReceivablesTabsFooter;
