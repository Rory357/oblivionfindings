import { router, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Banknote,
    CheckCircle2,
    CreditCard,
    Landmark,
    Link2,
    Radio,
    SlidersHorizontal,
} from 'lucide-react';

import { FinanceTabs, type FinanceTabItem } from './finance-tabs';

/**
 * Canonical Banking & Cash hub tabs. Mirrors the Ledger hub (heterogeneous
 * permissions): accounts/transactions/reconciliation/matching are `finance.bank.view`,
 * feeds/eftpos/match-rules are `finance.bank.manage`, petty cash is
 * `finance.petty_cash.view` (camelCase `pettyCash` in the auth.can tree). The tabs
 * SPA-navigate under the shared finance hero; `requires` mirrors each route's gate so
 * a tab the user can't open is never shown (no 403 dead tabs). Add new tabs here only.
 */
export type BankingTabId =
    | 'accounts'
    | 'transactions'
    | 'reconciliation'
    | 'matching'
    | 'feeds'
    | 'eftpos'
    | 'petty-cash'
    | 'match-rules';

// `can` is the same loosely-typed permission tree the sidebar consumes (auth.can).
type CanTree = Record<string, any> | undefined;
type BankingTabDef = FinanceTabItem & {
    id: BankingTabId;
    href: string;
    requires: (can: CanTree) => boolean;
};

const bankView = (c: CanTree) => !!c?.finance?.bank?.view;
const bankManage = (c: CanTree) => !!c?.finance?.bank?.manage;
const pettyCashView = (c: CanTree) => !!c?.finance?.pettyCash?.view;

export const BANKING_TABS: BankingTabDef[] = [
    { id: 'accounts', label: 'Accounts', icon: Landmark, tone: 'primary', href: '/finance/bank-accounts', requires: bankView },
    { id: 'transactions', label: 'Transactions', icon: ArrowLeftRight, tone: 'info', href: '/finance/bank-transactions', requires: bankView },
    { id: 'reconciliation', label: 'Reconciliation', icon: CheckCircle2, tone: 'success', href: '/finance/bank-reconciliation', requires: bankView },
    { id: 'matching', label: 'Matching', icon: Link2, tone: 'violet', href: '/finance/payment-matching', requires: bankView },
    { id: 'feeds', label: 'Feeds', icon: Radio, tone: 'warning', href: '/finance/bank-feeds', requires: bankManage },
    { id: 'eftpos', label: 'EFTPOS', icon: CreditCard, tone: 'info', href: '/finance/eftpos/terminals', requires: bankManage },
    { id: 'petty-cash', label: 'Petty cash', icon: Banknote, tone: 'primary', href: '/finance/petty-cash', requires: pettyCashView },
    { id: 'match-rules', label: 'Match rules', icon: SlidersHorizontal, tone: 'violet', href: '/finance/match-rules', requires: bankManage },
];

/**
 * The Banking & Cash tab strip, rendered in each banking sub-page's PageHero
 * `footer` slot (the Rostering pattern). Tabs SPA-navigate across the banking
 * sub-routes so the hub feels like one surface while each page keeps its own
 * controller + bespoke hero. Tabs are filtered to what the user can open (the
 * active tab is always shown). Drop into every banking Index page:
 * `<PageHero … footer={<BankingTabsFooter active="…" />} />`.
 */
export function BankingTabsFooter({ active }: { active: BankingTabId }) {
    const page = usePage();
    const can = (page.props as { auth?: { can?: CanTree } })?.auth?.can;

    const visible = BANKING_TABS.filter((t) => t.id === active || t.requires(can));

    const handleTab = (id: string) => {
        if (id === active) return;
        const target = BANKING_TABS.find((t) => t.id === id);
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
            ariaLabel="Banking and cash views"
        />
    );
}

export default BankingTabsFooter;
