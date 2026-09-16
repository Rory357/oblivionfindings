import {
    ArrowLeftRight,
    Banknote,
    BarChart3,
    BookOpen,
    Building2,
    CalendarDays,
    CalendarRange,
    Coins,
    CreditCard,
    DollarSign,
    FileSpreadsheet,
    FileText,
    Heart,
    Landmark,
    LayoutDashboard,
    Link2,
    type LucideIcon,
    Percent,
    PieChart,
    Receipt,
    RefreshCw,
    Repeat,
    Scale,
    Settings,
    Sparkles,
    TrendingUp,
    Wallet,
    Wand2,
} from 'lucide-react';

/**
 * Finance hubs — the single source of truth for how finance surfaces are
 * grouped. The sidebar shows ONE entry per hub; each hub's surfaces are the
 * connected-tab rail in the page header (<FinanceSectionRail>), and a view
 * with siblings carries them as a tier-2 strip (<FinanceTierTwoNav>).
 *
 * Every page keeps its own canonical URL, so deep links are unchanged and the
 * server still authorises each page independently — hiding a tab is never the
 * security boundary.
 *
 * Mirrors `lib/governance-sections.ts` deliberately: same shape, same helper
 * names, so the two modules stay one pattern.
 *
 * Two contracts to keep in lockstep when editing this file:
 *  1. Tab keys must equal the keys in
 *     `app/Domain/Finance/Services/FinanceHubCountsService.php` so
 *     `financeHubCounts[section][tab]` feeds the rail's counters unchanged.
 *     (`ledger.accounts` and `banking.accounts` share a key by name only —
 *     counts are keyed hub → tab.)
 *  2. Tab order must match the redirect order in the hub landing controllers
 *     (`LedgerController`, `PayablesController`, `BankingController`,
 *     `TaxController`, `ReportsController`, `SettingsController`), which send
 *     /finance/{hub} to the first view the viewer can open.
 */

type Can = Record<string, any> | null | undefined;

/** A sibling view inside one rail tab (Rule 2 strip under the header). */
export interface FinanceTierTwoTab {
    key: string;
    label: string;
    href: string;
    icon: LucideIcon;
    /** Paths (and their sub-paths) that belong to this sibling. */
    prefixes: string[];
}

export interface FinanceSectionTab {
    key: string;
    label: string;
    href: string;
    icon: LucideIcon;
    /** Paths (and their sub-paths) that belong to this tab. */
    prefixes: string[];
    visible: (can: Can) => boolean;
    /** Sibling views shown as a tier-2 strip when this tab is active. */
    tier2?: FinanceTierTwoTab[];
}

export interface FinanceSection {
    key: string;
    label: string;
    icon: LucideIcon;
    /** Landing URL for the sidebar entry (a hub controller redirect, or a page). */
    href: string;
    tabs: FinanceSectionTab[];
}

const fin = (can: Can) => (can?.finance ?? {}) as Record<string, any>;

const dashboard = (can: Can) => Boolean(fin(can).dashboard);
const ledgerView = (can: Can) => Boolean(fin(can).ledger?.view);
const ledgerManage = (can: Can) => Boolean(fin(can).ledger?.manage);
const assetsView = (can: Can) => Boolean(fin(can).assets?.view);
const apView = (can: Can) => Boolean(fin(can).ap?.view);
const arView = (can: Can) => Boolean(fin(can).ar?.view);
const bankView = (can: Can) => Boolean(fin(can).bank?.view);
const bankManage = (can: Can) => Boolean(fin(can).bank?.manage);
const pettyCashView = (can: Can) => Boolean(fin(can).pettyCash?.view);
const taxView = (can: Can) => Boolean(fin(can).tax?.view);
const taxManage = (can: Can) => Boolean(fin(can).tax?.manage);
const reportsView = (can: Can) => Boolean(fin(can).reports?.view);
const admin = (can: Can) => Boolean(fin(can).admin);

export const FINANCE_SECTIONS: FinanceSection[] = [
    {
        key: 'overview',
        label: 'Overview',
        icon: LayoutDashboard,
        href: '/finance',
        tabs: [
            {
                key: 'summary',
                label: 'Summary',
                href: '/finance',
                icon: LayoutDashboard,
                prefixes: ['/finance'],
                visible: dashboard,
            },
            {
                key: 'executive',
                label: 'Executive',
                href: '/finance/executive-dashboard',
                icon: TrendingUp,
                prefixes: ['/finance/executive-dashboard'],
                visible: dashboard,
            },
            {
                key: 'by-site',
                label: 'By site',
                href: '/finance/sites',
                icon: Building2,
                prefixes: ['/finance/sites'],
                visible: dashboard,
            },
            {
                key: 'cash-position',
                label: 'Cash position',
                href: '/finance/cash-position',
                icon: Wallet,
                prefixes: ['/finance/cash-position'],
                visible: dashboard,
            },
            {
                // D4: the calendar is a hub view, not a loose sidebar link
                // (the Governance shape).
                key: 'calendar',
                label: 'Calendar',
                href: '/finance/calendar',
                icon: CalendarDays,
                prefixes: ['/finance/calendar'],
                visible: dashboard,
            },
        ],
    },
    {
        key: 'ledger',
        label: 'General ledger',
        icon: BookOpen,
        href: '/finance/ledger',
        tabs: [
            {
                key: 'accounts',
                label: 'Chart of accounts',
                href: '/finance/accounts',
                icon: BookOpen,
                prefixes: ['/finance/accounts'],
                visible: ledgerView,
            },
            {
                key: 'journals',
                label: 'Journals',
                href: '/finance/journals',
                icon: FileText,
                prefixes: ['/finance/journals'],
                visible: ledgerView,
            },
            {
                key: 'fixed-assets',
                label: 'Fixed assets',
                href: '/finance/fixed-assets',
                icon: Landmark,
                prefixes: ['/finance/fixed-assets'],
                visible: assetsView,
            },
            {
                key: 'cost-centres',
                label: 'Cost centres',
                href: '/finance/cost-centres',
                icon: PieChart,
                prefixes: ['/finance/cost-centres'],
                visible: admin,
            },
            {
                key: 'fiscal-periods',
                label: 'Fiscal periods',
                href: '/finance/fiscal-periods',
                icon: CalendarRange,
                prefixes: ['/finance/fiscal-periods'],
                visible: admin,
            },
            {
                key: 'currencies',
                label: 'Currencies',
                href: '/finance/currencies',
                icon: Coins,
                prefixes: ['/finance/currencies'],
                visible: admin,
            },
            {
                key: 'fx-revaluations',
                label: 'FX revaluations',
                href: '/finance/fx-revaluations',
                icon: RefreshCw,
                prefixes: ['/finance/fx-revaluations'],
                visible: ledgerManage,
            },
        ],
    },
    {
        key: 'payables',
        label: 'Payables',
        icon: Receipt,
        href: '/finance/payables',
        tabs: [
            {
                key: 'bills',
                label: 'Bills',
                href: '/finance/bills',
                icon: Receipt,
                prefixes: ['/finance/bills'],
                visible: apView,
            },
            {
                key: 'purchase-orders',
                label: 'Purchase orders',
                href: '/finance/purchase-orders',
                icon: FileText,
                prefixes: ['/finance/purchase-orders'],
                visible: apView,
            },
            {
                key: 'vendors',
                label: 'Vendors',
                href: '/finance/vendors',
                icon: Building2,
                prefixes: ['/finance/vendors'],
                visible: apView,
            },
            {
                key: 'credit-notes',
                label: 'Credit notes',
                href: '/finance/credit-notes',
                icon: FileSpreadsheet,
                prefixes: ['/finance/credit-notes'],
                visible: apView,
            },
            {
                key: 'payment-runs',
                label: 'Payment runs',
                href: '/finance/payment-runs',
                icon: Banknote,
                prefixes: ['/finance/payment-runs'],
                visible: apView,
            },
        ],
    },
    {
        key: 'receivables',
        // The hub lands on Invoices — the list AR works in — while
        // /finance/receivables stays the Aged AR view (D1).
        label: 'Receivables',
        icon: Banknote,
        href: '/finance/invoices',
        tabs: [
            {
                key: 'invoices',
                label: 'Invoices',
                href: '/finance/invoices',
                icon: Receipt,
                prefixes: ['/finance/invoices'],
                visible: arView,
            },
            {
                key: 'quotes',
                label: 'Quotes',
                href: '/finance/quotes',
                icon: FileText,
                prefixes: ['/finance/quotes'],
                visible: arView,
            },
            {
                key: 'recurring-charges',
                label: 'Recurring charges',
                href: '/finance/recurring-charges',
                icon: Repeat,
                prefixes: ['/finance/recurring-charges'],
                visible: arView,
            },
            {
                key: 'billing',
                label: 'Billing',
                href: '/finance/billing',
                icon: DollarSign,
                prefixes: ['/finance/billing'],
                visible: arView,
            },
            {
                // D1: Ageing and Statements are one rail view with a tier-2
                // strip, keeping the rail under the eight-view cap.
                key: 'aged-ar',
                label: 'Aged AR',
                href: '/finance/receivables',
                icon: CalendarRange,
                prefixes: ['/finance/receivables'],
                visible: arView,
                tier2: [
                    {
                        key: 'ageing',
                        label: 'Ageing',
                        href: '/finance/receivables',
                        icon: CalendarRange,
                        prefixes: [
                            '/finance/receivables',
                            '/finance/receivables/aging',
                        ],
                    },
                    {
                        key: 'statements',
                        label: 'Statements',
                        href: '/finance/receivables/statements',
                        icon: Coins,
                        prefixes: ['/finance/receivables/statements'],
                    },
                ],
            },
            {
                key: 'price-books',
                label: 'Price books',
                href: '/finance/price-books',
                icon: BookOpen,
                prefixes: ['/finance/price-books'],
                visible: arView,
            },
            {
                key: 'allocations',
                label: 'Allocations',
                href: '/finance/payment-allocations',
                icon: ArrowLeftRight,
                prefixes: ['/finance/payment-allocations'],
                visible: arView,
            },
        ],
    },
    {
        key: 'banking',
        label: 'Banking',
        icon: Landmark,
        href: '/finance/banking',
        tabs: [
            {
                key: 'accounts',
                label: 'Bank accounts',
                href: '/finance/bank-accounts',
                icon: Landmark,
                prefixes: ['/finance/bank-accounts'],
                visible: bankView,
            },
            {
                key: 'transactions',
                label: 'Transactions',
                href: '/finance/bank-transactions',
                icon: ArrowLeftRight,
                prefixes: ['/finance/bank-transactions'],
                visible: bankView,
            },
            {
                key: 'reconciliation',
                label: 'Reconciliation',
                href: '/finance/bank-reconciliation',
                icon: Scale,
                prefixes: ['/finance/bank-reconciliation'],
                visible: bankView,
            },
            {
                key: 'matching',
                label: 'Matching',
                href: '/finance/payment-matching',
                icon: Sparkles,
                prefixes: ['/finance/payment-matching'],
                visible: bankView,
            },
            {
                key: 'feeds',
                label: 'Feeds',
                href: '/finance/bank-feeds',
                icon: RefreshCw,
                prefixes: ['/finance/bank-feeds'],
                visible: bankManage,
            },
            {
                // D2: Terminals and Batches are a tier-2 strip inside one
                // EFTPOS view.
                key: 'eftpos',
                label: 'EFTPOS',
                href: '/finance/eftpos/terminals',
                icon: CreditCard,
                prefixes: ['/finance/eftpos'],
                visible: bankManage,
                tier2: [
                    {
                        key: 'terminals',
                        label: 'Terminals',
                        href: '/finance/eftpos/terminals',
                        icon: CreditCard,
                        prefixes: ['/finance/eftpos/terminals'],
                    },
                    {
                        key: 'batches',
                        label: 'Batches',
                        href: '/finance/eftpos/batches',
                        icon: FileSpreadsheet,
                        prefixes: ['/finance/eftpos/batches'],
                    },
                ],
            },
            {
                key: 'petty-cash',
                label: 'Petty cash',
                href: '/finance/petty-cash',
                icon: Wallet,
                prefixes: ['/finance/petty-cash'],
                visible: pettyCashView,
            },
        ],
    },
    {
        key: 'tax',
        label: 'Tax & compliance',
        icon: Percent,
        href: '/finance/tax',
        tabs: [
            {
                key: 'gst-returns',
                label: 'GST returns',
                href: '/finance/gst-returns',
                icon: Percent,
                prefixes: ['/finance/gst-returns'],
                visible: taxView,
            },
            {
                key: 'ird-filings',
                label: 'IRD filings',
                href: '/finance/ird-filings',
                icon: FileText,
                prefixes: ['/finance/ird-filings'],
                visible: taxManage,
            },
            {
                key: 'audit-exports',
                label: 'Audit exports',
                href: '/finance/audit-exports',
                icon: FileSpreadsheet,
                prefixes: ['/finance/audit-exports'],
                visible: reportsView,
            },
            {
                // D5: trust/compliance accounting belongs with tax, not as a
                // loose sidebar link.
                key: 'donor-funds',
                label: 'Donor funds',
                href: '/finance/donor-funds',
                icon: Heart,
                prefixes: ['/finance/donor-funds'],
                visible: reportsView,
            },
        ],
    },
    {
        key: 'reports',
        label: 'Reports',
        icon: BarChart3,
        href: '/finance/reports',
        tabs: [
            {
                // D3: the nine reports become five rail views; the statement
                // set and the two ageing reports carry tier-2 strips.
                key: 'statements',
                label: 'Statements',
                href: '/finance/reports/profit-loss',
                icon: FileSpreadsheet,
                prefixes: [
                    '/finance/reports/profit-loss',
                    '/finance/reports/balance-sheet',
                    '/finance/reports/trial-balance',
                    '/finance/reports/cash-flow',
                ],
                visible: reportsView,
                tier2: [
                    {
                        key: 'profit-loss',
                        label: 'Profit & loss',
                        href: '/finance/reports/profit-loss',
                        icon: TrendingUp,
                        prefixes: ['/finance/reports/profit-loss'],
                    },
                    {
                        key: 'balance-sheet',
                        label: 'Balance sheet',
                        href: '/finance/reports/balance-sheet',
                        icon: Scale,
                        prefixes: ['/finance/reports/balance-sheet'],
                    },
                    {
                        key: 'trial-balance',
                        label: 'Trial balance',
                        href: '/finance/reports/trial-balance',
                        icon: BookOpen,
                        prefixes: ['/finance/reports/trial-balance'],
                    },
                    {
                        key: 'cash-flow',
                        label: 'Cash flow',
                        href: '/finance/reports/cash-flow',
                        icon: Wallet,
                        prefixes: ['/finance/reports/cash-flow'],
                    },
                ],
            },
            {
                key: 'ageing',
                label: 'Ageing',
                href: '/finance/reports/aged-receivables',
                icon: CalendarRange,
                prefixes: [
                    '/finance/reports/aged-receivables',
                    '/finance/reports/aged-payables',
                ],
                visible: reportsView,
                tier2: [
                    {
                        key: 'aged-receivables',
                        label: 'Receivables',
                        href: '/finance/reports/aged-receivables',
                        icon: Banknote,
                        prefixes: ['/finance/reports/aged-receivables'],
                    },
                    {
                        key: 'aged-payables',
                        label: 'Payables',
                        href: '/finance/reports/aged-payables',
                        icon: Receipt,
                        prefixes: ['/finance/reports/aged-payables'],
                    },
                ],
            },
            {
                key: 'funding-summary',
                label: 'Funding summary',
                href: '/finance/reports/funding-stream-summary',
                icon: PieChart,
                prefixes: ['/finance/reports/funding-stream-summary'],
                visible: reportsView,
            },
            {
                key: 'budget-vs-actuals',
                label: 'Budget vs actuals',
                href: '/finance/reports/budget-vs-actuals',
                icon: BarChart3,
                prefixes: ['/finance/reports/budget-vs-actuals'],
                visible: reportsView,
            },
            {
                key: 'cash-flow-forecast',
                label: 'Cash-flow forecast',
                href: '/finance/cash-flow-forecast',
                icon: TrendingUp,
                prefixes: ['/finance/cash-flow-forecast'],
                visible: reportsView,
            },
        ],
    },
    {
        key: 'settings',
        label: 'Settings',
        icon: Settings,
        href: '/finance/settings',
        tabs: [
            {
                key: 'integrations',
                label: 'Integrations',
                href: '/finance/integrations',
                icon: Link2,
                prefixes: ['/finance/integrations'],
                visible: admin,
            },
            {
                key: 'funding-streams',
                label: 'Funding streams',
                href: '/finance/funding-streams',
                icon: Coins,
                prefixes: ['/finance/funding-streams'],
                visible: admin,
            },
            {
                // D2: match rules are configuration, so they sit with the
                // other finance settings rather than in the Banking rail.
                key: 'match-rules',
                label: 'Match rules',
                href: '/finance/match-rules',
                icon: Wand2,
                prefixes: ['/finance/match-rules'],
                visible: bankManage,
            },
        ],
    },
];

function normalisePath(url: string): string {
    let path = url.split(/[?#]/)[0] ?? '/';
    if (/^https?:\/\//.test(path)) {
        try {
            path = new URL(path).pathname;
        } catch {
            // keep the raw path
        }
    }
    const trimmed = path.replace(/\/+$/, '');
    return trimmed.length > 0 ? trimmed : '/';
}

function prefixMatch(prefix: string, path: string): boolean {
    return path === prefix || path.startsWith(`${prefix}/`);
}

/**
 * How specifically a tab claims a path: the length of its longest matching
 * prefix, or 0 when it doesn't match. Finance nests URLs (`/finance` is the
 * parent of every other path, `/finance/receivables/statements` sits under
 * `/finance/receivables`), so the most specific claim has to win.
 */
function tabMatchLength(
    tab: Pick<FinanceSectionTab, 'prefixes'>,
    path: string,
): number {
    let best = 0;
    for (const prefix of tab.prefixes) {
        if (prefixMatch(prefix, path) && prefix.length > best) {
            best = prefix.length;
        }
    }
    return best;
}

export function visibleSectionTabs(
    section: FinanceSection,
    can: Can,
): FinanceSectionTab[] {
    return section.tabs.filter((tab) => tab.visible(can));
}

/** The hub (and tab) a URL belongs to, or null outside the hubs. */
export function financeSectionForUrl(
    url: string,
): { section: FinanceSection; tab: FinanceSectionTab } | null {
    const path = normalisePath(url);
    let best: { section: FinanceSection; tab: FinanceSectionTab } | null = null;
    let bestLength = 0;

    for (const section of FINANCE_SECTIONS) {
        for (const tab of section.tabs) {
            const length = tabMatchLength(tab, path);
            if (length > bestLength) {
                bestLength = length;
                best = { section, tab };
            }
        }
    }

    return best;
}

/** The active sibling inside a tier-2 strip, or null when the tab has none. */
export function financeTierTwoForUrl(
    tab: FinanceSectionTab,
    url: string,
): FinanceTierTwoTab | null {
    if (!tab.tier2?.length) return null;
    const path = normalisePath(url);
    let best: FinanceTierTwoTab | null = null;
    let bestLength = 0;

    for (const sibling of tab.tier2) {
        const length = tabMatchLength(sibling, path);
        if (length > bestLength) {
            bestLength = length;
            best = sibling;
        }
    }

    return best ?? tab.tier2[0];
}

/**
 * Sidebar matching: a nav item whose href is a hub's landing URL or one of its
 * tabs stays lit anywhere inside that hub (e.g. "Payables" stays lit on a bill
 * record).
 */
export function financeHubContainsUrl(
    itemHref: string,
    currentUrl: string,
): boolean {
    const itemPath = normalisePath(itemHref);
    const hub = FINANCE_SECTIONS.find(
        (section) =>
            normalisePath(section.href) === itemPath ||
            section.tabs.some((tab) => tab.href === itemPath),
    );
    if (!hub) return false;

    // The winning hub for the current URL, so nested finance paths light one
    // entry only (`/finance/accounts` is Ledger, not Overview).
    const match = financeSectionForUrl(currentUrl);
    return match?.section.key === hub.key;
}
