import { router, usePage } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    BarChart3,
    Activity,
    GitBranch,
    LineChart,
    Scale,
    Target,
    TrendingUp,
} from 'lucide-react';

import { FinanceTabs, type FinanceTabItem } from './finance-tabs';

/**
 * Canonical Reports & Planning hub tabs. Every report reads the real GL already;
 * the hub just re-homes them under one Rostering-grade surface. All tabs are
 * `finance.reports.view` (homogeneous), so the whole strip shows for any
 * reports-view user. Add new report tabs here only.
 */
export type ReportTabId =
    | 'profit-loss'
    | 'balance-sheet'
    | 'trial-balance'
    | 'cash-flow'
    | 'aged-receivables'
    | 'aged-payables'
    | 'funding-summary'
    | 'budget-vs-actuals'
    | 'cash-flow-forecast';

// `can` is the same loosely-typed permission tree the sidebar consumes (auth.can).
type CanTree = Record<string, any> | undefined;
type ReportTabDef = FinanceTabItem & {
    id: ReportTabId;
    href: string;
    requires: (can: CanTree) => boolean;
};

const reportsView = (c: CanTree) => !!c?.finance?.reports?.view;

export const REPORT_TABS: ReportTabDef[] = [
    { id: 'profit-loss', label: 'Profit & loss', icon: TrendingUp, tone: 'primary', href: '/finance/reports/profit-loss', requires: reportsView },
    { id: 'balance-sheet', label: 'Balance sheet', icon: Scale, tone: 'info', href: '/finance/reports/balance-sheet', requires: reportsView },
    { id: 'trial-balance', label: 'Trial balance', icon: BarChart3, tone: 'violet', href: '/finance/reports/trial-balance', requires: reportsView },
    { id: 'cash-flow', label: 'Cash flow', icon: Activity, tone: 'success', href: '/finance/reports/cash-flow', requires: reportsView },
    { id: 'aged-receivables', label: 'Aged AR', icon: ArrowDownToLine, tone: 'warning', href: '/finance/reports/aged-receivables', requires: reportsView },
    { id: 'aged-payables', label: 'Aged AP', icon: ArrowUpFromLine, tone: 'critical', href: '/finance/reports/aged-payables', requires: reportsView },
    { id: 'funding-summary', label: 'Funding summary', icon: GitBranch, tone: 'info', href: '/finance/reports/funding-stream-summary', requires: reportsView },
    { id: 'budget-vs-actuals', label: 'Budget vs actuals', icon: Target, tone: 'violet', href: '/finance/reports/budget-vs-actuals', requires: reportsView },
    { id: 'cash-flow-forecast', label: 'Cash-flow forecast', icon: LineChart, tone: 'primary', href: '/finance/cash-flow-forecast', requires: reportsView },
];

/**
 * The Reports & Planning tab strip, rendered in each report sub-page's PageHero
 * `footer` slot (the Rostering pattern). Tabs SPA-navigate across the report
 * sub-routes so the hub feels like one surface while each page keeps its own
 * controller + bespoke hero. Tabs are filtered to what the user can open (the
 * active tab is always shown). Drop into every report page:
 * `<PageHero … footer={<ReportsTabsFooter active="…" />} />`.
 */
export function ReportsTabsFooter({ active }: { active: ReportTabId }) {
    const page = usePage();
    const can = (page.props as { auth?: { can?: CanTree } })?.auth?.can;

    const visible = REPORT_TABS.filter((t) => t.id === active || t.requires(can));

    const handleTab = (id: string) => {
        if (id === active) return;
        const target = REPORT_TABS.find((t) => t.id === id);
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
            ariaLabel="Reports and planning views"
        />
    );
}

export default ReportsTabsFooter;
