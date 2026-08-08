import { router, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Building2,
    ClipboardCheck,
    FileText,
    Receipt,
} from 'lucide-react';

import {
    FinanceTabs,
    tabCountBadge,
    type FinanceHubCounts,
    type FinanceTabItem,
} from './finance-tabs';

/**
 * Canonical Purchases & Payables hub tabs. Mirrors the Receivables hub: each
 * sub-area keeps its own route + controller + bespoke hero; the tabs SPA-navigate
 * between them under the shared finance hero so AP reads as one Rostering-grade
 * surface. `requires` mirrors the route's permission gate so a tab a user can't
 * open is never shown (no 403 dead tabs). Add new AP tabs here only.
 */
export type PayablesTabId =
    | 'bills'
    | 'purchase-orders'
    | 'vendors'
    | 'credit-notes'
    | 'payment-runs';

// `can` is the same loosely-typed permission tree the sidebar consumes (auth.can).
type CanTree = Record<string, any> | undefined;
type PayablesTabDef = FinanceTabItem & {
    id: PayablesTabId;
    href: string;
    requires: (can: CanTree) => boolean;
};

// Every AP sub-route is gated `finance.ap.view`, so the whole strip shows for any
// AP-view user; the `requires` checks keep the contract explicit and future-proof.
const apView = (c: CanTree) => !!c?.finance?.ap?.view;

export const PAYABLES_TABS: PayablesTabDef[] = [
    {
        id: 'bills',
        label: 'Bills',
        icon: Receipt,
        tone: 'primary',
        href: '/finance/bills',
        requires: apView,
    },
    {
        id: 'purchase-orders',
        label: 'Purchase orders',
        icon: ClipboardCheck,
        tone: 'info',
        href: '/finance/purchase-orders',
        requires: apView,
    },
    {
        id: 'vendors',
        label: 'Vendors',
        icon: Building2,
        tone: 'violet',
        href: '/finance/vendors',
        requires: apView,
    },
    {
        id: 'credit-notes',
        label: 'Credit notes',
        icon: FileText,
        tone: 'warning',
        href: '/finance/credit-notes',
        requires: apView,
    },
    {
        id: 'payment-runs',
        label: 'Payment runs',
        icon: ArrowLeftRight,
        tone: 'success',
        href: '/finance/payment-runs',
        requires: apView,
    },
];

/**
 * The Purchases & Payables tab strip, rendered in each AP sub-page's PageHero
 * `footer` slot (the Rostering pattern). Tabs SPA-navigate across the AP
 * sub-routes so the hub feels like one surface while each page keeps its own
 * controller + bespoke hero stats/actions. Tabs are filtered to what the user
 * can open (the active tab is always shown). Drop into every AP Index page:
 * `<PageHero … footer={<PayablesTabsFooter active="…" />} />`.
 */
export function PayablesTabsFooter({ active }: { active: PayablesTabId }) {
    const page = usePage();
    const can = (page.props as { auth?: { can?: CanTree } })?.auth?.can;
    const counts =
        (page.props as { financeHubCounts?: FinanceHubCounts | null })
            .financeHubCounts?.['payables'] ?? {};

    const visible = PAYABLES_TABS.filter(
        (t) => t.id === active || t.requires(can),
    );

    const handleTab = (id: string) => {
        if (id === active) return;
        const target = PAYABLES_TABS.find((t) => t.id === id);
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
            ariaLabel="Purchases and payables views"
        />
    );
}

export default PayablesTabsFooter;
