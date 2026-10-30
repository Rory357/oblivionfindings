import { describe, expect, it } from 'vitest';

import {
    FINANCE_SECTIONS,
    financeHubContainsUrl,
    financeSectionForUrl,
    financeTierTwoForUrl,
    isFinanceHubHref,
    visibleSectionTabs,
} from './finance-sections';

const controller = {
    finance: {
        dashboard: true,
        ledger: { view: true, manage: true },
        ap: { view: true, manage: true },
        ar: { view: true, manage: true },
        bank: { view: true, manage: true },
        tax: { view: true, manage: true },
        assets: { view: true, manage: true },
        pettyCash: { view: true, manage: true },
        reports: { view: true },
        admin: true,
    },
};

describe('Finance hubs', () => {
    it('groups every finance surface into one of eight hubs', () => {
        expect(FINANCE_SECTIONS).toHaveLength(8);
        const hrefs = FINANCE_SECTIONS.flatMap((section) =>
            visibleSectionTabs(section, controller).map((tab) => tab.href),
        );
        expect(hrefs).toEqual(
            expect.arrayContaining([
                '/finance',
                '/finance/executive-dashboard',
                '/finance/sites',
                '/finance/cash-position',
                '/finance/calendar',
                '/finance/accounts',
                '/finance/journals',
                '/finance/fixed-assets',
                '/finance/cost-centres',
                '/finance/fiscal-periods',
                '/finance/currencies',
                '/finance/fx-revaluations',
                '/finance/bills',
                '/finance/purchase-orders',
                '/finance/vendors',
                '/finance/credit-notes',
                '/finance/payment-runs',
                '/finance/vehicle-reviews',
                '/finance/invoices',
                '/finance/quotes',
                '/finance/recurring-charges',
                '/finance/billing',
                '/finance/receivables',
                '/finance/price-books',
                '/finance/payment-allocations',
                '/finance/bank-accounts',
                '/finance/bank-transactions',
                '/finance/bank-reconciliation',
                '/finance/payment-matching',
                '/finance/bank-feeds',
                '/finance/eftpos/terminals',
                '/finance/petty-cash',
                '/finance/gst-returns',
                '/finance/ird-filings',
                '/finance/audit-exports',
                '/finance/donor-funds',
                '/finance/reports/profit-loss',
                '/finance/reports/aged-receivables',
                '/finance/reports/funding-stream-summary',
                '/finance/reports/budget-vs-actuals',
                '/finance/cash-flow-forecast',
                '/finance/integrations',
                '/finance/funding-streams',
                '/finance/match-rules',
            ]),
        );
        expect(new Set(hrefs).size).toBe(hrefs.length);
    });

    it('keeps every rail at or under the eight-view cap', () => {
        for (const section of FINANCE_SECTIONS) {
            expect(section.tabs.length).toBeLessThanOrEqual(8);
        }
    });

    it('resolves the hub and tab for sub-pages and records', () => {
        expect(financeSectionForUrl('/finance/bills/12')).toMatchObject({
            section: { key: 'payables' },
            tab: { key: 'bills' },
        });
        expect(
            financeSectionForUrl('http://app.test/finance/accounts?type=asset'),
        ).toMatchObject({ section: { key: 'ledger' }, tab: { key: 'accounts' } });
        expect(
            financeSectionForUrl('/finance/sites/4/financial-dashboard'),
        ).toMatchObject({ section: { key: 'overview' }, tab: { key: 'by-site' } });
        expect(financeSectionForUrl('/finance/eftpos/batches/9')).toMatchObject({
            section: { key: 'banking' },
            tab: { key: 'eftpos' },
        });
        expect(financeSectionForUrl('/governance/meetings')).toBeNull();
        expect(financeSectionForUrl('/financex')).toBeNull();
    });

    it('lets the most specific prefix win over the /finance catch-all', () => {
        // Overview's Summary tab is rooted at /finance, so every other finance
        // path has to out-claim it.
        expect(financeSectionForUrl('/finance')).toMatchObject({
            tab: { key: 'summary' },
        });
        expect(financeSectionForUrl('/finance/journals')).toMatchObject({
            section: { key: 'ledger' },
        });
        // Statements is nested under the Aged AR view's own prefix.
        expect(
            financeSectionForUrl('/finance/receivables/statements'),
        ).toMatchObject({ section: { key: 'receivables' }, tab: { key: 'aged-ar' } });
    });

    it('resolves the active sibling in a tier-2 strip', () => {
        const receivables = FINANCE_SECTIONS.find(
            (section) => section.key === 'receivables',
        )!;
        const agedAr = receivables.tabs.find((tab) => tab.key === 'aged-ar')!;
        expect(
            financeTierTwoForUrl(agedAr, '/finance/receivables/statements')?.key,
        ).toBe('statements');
        expect(financeTierTwoForUrl(agedAr, '/finance/receivables')?.key).toBe(
            'ageing',
        );
        expect(
            financeTierTwoForUrl(agedAr, '/finance/receivables/aging')?.key,
        ).toBe('ageing');

        const reports = FINANCE_SECTIONS.find(
            (section) => section.key === 'reports',
        )!;
        const statements = reports.tabs.find(
            (tab) => tab.key === 'statements',
        )!;
        expect(
            financeTierTwoForUrl(statements, '/finance/reports/balance-sheet')
                ?.key,
        ).toBe('balance-sheet');

        const invoices = receivables.tabs.find((tab) => tab.key === 'invoices')!;
        expect(financeTierTwoForUrl(invoices, '/finance/invoices')).toBeNull();
    });

    it('keeps a hub sidebar entry active across its sibling views', () => {
        expect(financeHubContainsUrl('/finance/payables', '/finance/bills/3')).toBe(
            true,
        );
        expect(
            financeHubContainsUrl('/finance/invoices', '/finance/price-books'),
        ).toBe(true);
        expect(financeHubContainsUrl('/finance/banking', '/finance/petty-cash')).toBe(
            true,
        );
        // Overview is rooted at /finance but must not claim other hubs' pages.
        expect(financeHubContainsUrl('/finance', '/finance/accounts')).toBe(false);
        expect(financeHubContainsUrl('/finance', '/finance/calendar')).toBe(true);
        expect(financeHubContainsUrl('/finance/payables', '/finance/invoices')).toBe(
            false,
        );
        expect(financeHubContainsUrl('/governance/meetings', '/finance/bills')).toBe(
            false,
        );
    });

    it('lights exactly one hub entry on every finance page', () => {
        // The sidebar lights every item whose match score is positive, so two
        // hubs claiming one URL means two lit entries. Overview sits at
        // /finance, a prefix of every other finance URL, which is how it used
        // to light on top of the real hub on all 88 pages.
        const hubHrefs = FINANCE_SECTIONS.map((section) => section.href);
        const pages = [
            '/finance',
            '/finance/calendar',
            '/finance/bills',
            '/finance/bills/12',
            '/finance/accounts',
            '/finance/invoices',
            '/finance/receivables/statements',
            '/finance/bank-accounts',
            '/finance/eftpos/batches/9',
            '/finance/gst-returns',
            '/finance/donor-funds/3',
            '/finance/reports/balance-sheet',
            '/finance/cash-flow-forecast',
            '/finance/match-rules',
            '/finance/integrations',
        ];

        for (const page of pages) {
            const lit = hubHrefs.filter((href) =>
                financeHubContainsUrl(href, page),
            );
            expect(lit, `hubs lit on ${page}`).toHaveLength(1);
        }
    });

    it('recognises every hub landing URL and tab href as a hub entry', () => {
        for (const section of FINANCE_SECTIONS) {
            expect(isFinanceHubHref(section.href)).toBe(true);
            for (const tab of section.tabs) {
                expect(isFinanceHubHref(tab.href)).toBe(true);
            }
        }
        expect(isFinanceHubHref('/governance/meetings')).toBe(false);
        expect(isFinanceHubHref('/dashboard')).toBe(false);
    });

    it('fails closed: views without the permission are hidden', () => {
        const treasurer = { finance: { reports: { view: true } } };
        const tax = FINANCE_SECTIONS.find((section) => section.key === 'tax')!;
        expect(visibleSectionTabs(tax, treasurer).map((tab) => tab.key)).toEqual([
            'audit-exports',
            'donor-funds',
        ]);
        const payables = FINANCE_SECTIONS.find(
            (section) => section.key === 'payables',
        )!;
        expect(visibleSectionTabs(payables, treasurer)).toEqual([]);
        expect(visibleSectionTabs(payables, null)).toEqual([]);

        // Match rules moved to Settings but kept their bank.manage gate (D2).
        const settings = FINANCE_SECTIONS.find(
            (section) => section.key === 'settings',
        )!;
        expect(
            visibleSectionTabs(settings, {
                finance: { bank: { manage: true } },
            }).map((tab) => tab.key),
        ).toEqual(['match-rules']);
    });
});
