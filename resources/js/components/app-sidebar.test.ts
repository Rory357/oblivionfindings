import { describe, expect, it } from 'vitest';

import {
    buildNavSearchCatalog,
    governanceActionableTabKeys,
    isIconActive,
    isSubItemActive,
} from './app-sidebar';

describe('app sidebar workforce navigation', () => {
    it('keeps IT destinations in the app sidebar without duplicating tabs or device navigation', () => {
        const catalog = buildNavSearchCatalog({
            can: { it: { view: true, request: true, manage: true } },
        }).filter((item) => item.section === 'IT & Support');
        expect(catalog.map((item) => item.href)).toEqual([
            '/it',
            '/it/knowledge',
            '/it/provisioning',
            '/it/work',
            '/it/problems',
            '/it/changes',
            '/it/major-incidents',
            '/it/reports',
            '/it/setup',
        ]);
    });

    it('keeps requester navigation out of technician workspaces', () => {
        const catalog = buildNavSearchCatalog({
            can: { it: { request: true } },
        }).filter((item) => item.section === 'IT & Support');
        expect(catalog.map((item) => item.href)).toEqual([
            '/it',
            '/it/knowledge',
        ]);
    });

    it.each(['knowledge_author', 'knowledge_review'])(
        'gives %s only the canonical knowledge entry',
        (capability) => {
            const catalog = buildNavSearchCatalog({
                can: { it: { [capability]: true } },
            }).filter((item) => item.section === 'IT & Support');
            expect(catalog.map((item) => item.href)).toEqual(['/it/knowledge']);
            expect(catalog[0].label).toBe('Knowledge & Documentation');
        },
    );

    it('uses the grouped Security & Devices contract in application search', () => {
        const catalog = buildNavSearchCatalog({
            can: {
                securityDevices: {
                    viewAny: true,
                    devicesView: true,
                    groupsManage: true,
                    eventsView: true,
                    maintenanceView: true,
                    integrationsView: true,
                    integrationsManage: true,
                    monitoringManage: true,
                    reportsView: true,
                },
            },
        }).filter((item) => item.section === 'Security & Devices');

        expect([...new Set(catalog.map((item) => item.group))]).toEqual([
            'Overview',
            'Workspaces',
            'Operations',
            'Setup',
        ]);
        expect(catalog.map((item) => item.label)).toEqual([
            'Estate overview',
            'Sites',
            'All devices',
            'Network & IT',
            'Security',
            'Healthcare',
            'Tracking',
            'Facilities & IoT',
            'Monitoring',
            'Maintenance',
            'Discovery & collectors',
            'Integrations',
            'Settings & audit',
        ]);
        expect(catalog.map((item) => item.label)).not.toEqual(
            expect.arrayContaining([
                'Tracking Devices',
                'Smart IoT & Healthcare',
                'IT Infrastructure',
                'Alerts & Events',
                'APIs & Integrations',
            ]),
        );
    });

    it('reaches billing through the Finance hubs and leaves funding with client management', () => {
        const catalog = buildNavSearchCatalog({
            can: {
                clients: { viewAny: true },
                funding: { viewAny: true },
                finance: {
                    dashboard: true,
                    ar: { view: true, manage: true },
                },
            },
        });

        const operationsItems = catalog.filter(
            (item) => item.section === 'Operations',
        );
        const financeItems = catalog.filter(
            (item) => item.section === 'Finance',
        );

        expect(operationsItems).toEqual(
            expect.arrayContaining([
                expect.objectContaining({
                    label: 'Funding',
                    href: '/operations/funding',
                    group: 'Client Management',
                }),
            ]),
        );

        expect(operationsItems.map((item) => item.group)).not.toContain(
            'Time & Billing',
        );
        // None of the AR registers may reappear under Operations.
        for (const label of [
            'Billing',
            'Invoices',
            'Price Books',
            'Quotes',
            'Recurring Charges',
        ]) {
            expect(operationsItems.map((item) => item.label)).not.toContain(
                label,
            );
        }

        // Finance shows ONE entry per hub (2026-09-16 design migration), so the
        // AR registers are reached through Receivables rather than as six
        // sibling links — anti-pattern "One sidebar link per register".
        expect(financeItems).toEqual(
            expect.arrayContaining([
                expect.objectContaining({
                    label: 'Receivables',
                    href: '/finance/invoices',
                }),
                expect.objectContaining({
                    label: 'Overview',
                    href: '/finance',
                }),
            ]),
        );
        expect(financeItems.map((item) => item.label)).not.toContain('Quotes');
    });

    it('groups shift, handover, and time navigation under Workforce instead of Operations', () => {
        const catalog = buildNavSearchCatalog({
            can: {
                clients: { viewAny: true },
                operations: { dashboard: true },
                shifts: { viewAny: true },
                job_board: { viewAny: true, open_count: 2 },
                rostering: { viewAny: true },
                timesheets: { viewAny: true },
            },
        });

        const workforceLabels = [
            'Shifts',
            'Job Board',
            'Rostering',
            'Availability',
            'Handovers',
            'Shift Notes',
            'Timesheets',
            'Attendance',
            'Conflict Queue',
        ];

        expect(
            workforceLabels.map((label) =>
                catalog.find((item) => item.label === label),
            ),
        ).toEqual(
            workforceLabels.map((label) =>
                expect.objectContaining({
                    label,
                    section: 'Workforce',
                    group: 'Workforce',
                }),
            ),
        );

        expect(
            catalog
                .filter((item) => item.section === 'Operations')
                .map((item) => item.label),
        ).not.toEqual(expect.arrayContaining(workforceLabels));

        expect(catalog.find((item) => item.label === 'Shifts')).toMatchObject({
            href: '/operations/shifts',
        });
        expect(
            catalog.find((item) => item.label === 'Handovers'),
        ).toMatchObject({
            href: '/operations/handovers',
        });
        expect(
            catalog.find((item) => item.label === 'Shift Notes'),
        ).toMatchObject({
            href: '/operations/shift-notes',
        });
        expect(
            catalog.find((item) => item.label === 'Conflict Queue'),
        ).toMatchObject({
            href: '/operations/rostering/conflicts',
        });
        expect(
            catalog.find((item) => item.label === 'Attendance'),
        ).toMatchObject({
            href: '/attendance',
        });

        const sectionOrder = [...new Set(catalog.map((item) => item.section))];

        expect(sectionOrder.indexOf('Operations')).toBeLessThan(
            sectionOrder.indexOf('Workforce'),
        );
    });

    it('shows Workforce without keeping an empty Operations section for workforce-only permissions', () => {
        const catalog = buildNavSearchCatalog({
            can: {
                shifts: { viewAny: true },
                rostering: { viewAny: true },
                timesheets: { viewAssigned: true },
            },
        });

        expect(catalog.map((item) => item.section)).toContain('Workforce');
        expect(catalog.map((item) => item.section)).not.toContain('Operations');
    });

    it('shows shift handovers for every workflow capability and hides them from unrelated workforce permissions', () => {
        const workflowCapabilities = [
            { handovers: { viewAny: true } },
            { shifts: { viewAny: true } },
            { shifts: { manageAny: true } },
            { shifts: { viewAssigned: true } },
            { shifts: { update: true } },
            { handovers: { create: true } },
        ];

        for (const can of workflowCapabilities) {
            expect(
                buildNavSearchCatalog({ can }).find(
                    (item) => item.href === '/operations/handovers',
                ),
            ).toMatchObject({
                label: 'Handovers',
                section: 'Workforce',
                group: 'Workforce',
            });
        }

        const unrelatedCapabilities = [
            { timesheets: { viewAssigned: true } },
            { job_board: { claim: true } },
            { shifts: { create: true } },
            { handovers: { update: true } },
        ];

        for (const can of unrelatedCapabilities) {
            expect(
                buildNavSearchCatalog({ can }).some(
                    (item) => item.href === '/operations/handovers',
                ),
            ).toBe(false);
        }
    });

    it('marks workforce routes active under Workforce instead of the generic Operations dashboard', () => {
        const operationsItem = {
            id: 'operations',
            subPanel: true,
        } as any;
        const workforceItem = {
            id: 'workforce',
            subPanel: true,
        } as any;

        const operationsGroups = [
            {
                label: 'Overview',
                items: [
                    {
                        title: 'Dashboard',
                        href: '/operations',
                    },
                ],
            },
        ] as any;
        const workforceGroups = [
            {
                label: 'Workforce',
                items: [
                    {
                        title: 'Conflict Queue',
                        href: '/operations/rostering/conflicts',
                    },
                    {
                        title: 'Handovers',
                        href: '/operations/handovers',
                    },
                    {
                        title: 'Shift Notes',
                        href: '/operations/shift-notes',
                    },
                ],
            },
        ] as any;

        expect(
            isIconActive(
                '/operations/rostering/conflicts',
                operationsItem,
                operationsGroups,
            ),
        ).toBe(false);
        expect(
            isIconActive(
                '/operations/rostering/conflicts',
                workforceItem,
                workforceGroups,
            ),
        ).toBe(true);
        expect(
            isIconActive(
                '/operations/handovers',
                operationsItem,
                operationsGroups,
            ),
        ).toBe(false);
        expect(
            isIconActive(
                '/operations/handovers',
                workforceItem,
                workforceGroups,
            ),
        ).toBe(true);
        expect(
            isIconActive(
                '/operations/shift-notes',
                operationsItem,
                operationsGroups,
            ),
        ).toBe(false);
        expect(
            isIconActive(
                '/operations/shift-notes',
                workforceItem,
                workforceGroups,
            ),
        ).toBe(true);
        expect(
            isIconActive(
                '/operations/clients',
                operationsItem,
                operationsGroups,
            ),
        ).toBe(true);
    });
});

describe('app sidebar governance hubs', () => {
    const governanceCatalog = (can: Record<string, unknown>) =>
        buildNavSearchCatalog({ can }).filter(
            (item) => item.section === 'Governance',
        );

    const memberBase = {
        view: true,
        meetings: { view: true },
        resolutions: { view: true, vote: true },
        risks: { view: true },
        compliance: { view: true },
        performance: { view: true },
        strategy: { view: true },
        packs: { view: true },
        actions: { view: true },
        policies: { view: true },
        'ceo-reports': { view: true },
        interests: { view: true, manage: true },
        evaluations: { view: true },
        documents: { view: true },
        clinical: { view: true },
    };

    it('keeps view-only members on the four member destinations', () => {
        const catalog = governanceCatalog({
            governance: { ...memberBase, budgets: { view: true } },
        });

        expect(catalog.map((item) => item.href)).toEqual([
            '/governance/dashboard',
            '/governance/my-work',
            '/governance/calendar',
            '/governance/records',
        ]);
    });

    it('gives a treasurer the Board finance hub, opening a page they act in', () => {
        const catalog = governanceCatalog({
            governance: {
                ...memberBase,
                budgets: { view: true, create: true, submit: true },
                spend: { view: true, request: true },
            },
        });

        expect(catalog.map((item) => item.label)).toEqual([
            'Home',
            'My work',
            'Calendar',
            'Records',
            'Board finance',
        ]);
        expect(
            catalog.find((item) => item.label === 'Board finance')?.href,
        ).toBe('/governance/budgets');
    });

    it('routes the CEO to CEO reports and, as reviewee, to their own review', () => {
        const ceo = {
            view: true,
            'ceo-reports': { view: true, manage: true },
        };

        expect(
            governanceCatalog({
                governance: { ...ceo, performance: { view: true } },
            }).map((item) => [item.label, item.href]),
        ).toEqual([
            ['Home', '/governance/dashboard'],
            ['My work', '/governance/my-work'],
            ['Calendar', '/governance/calendar'],
            ['Records', '/governance/records'],
            ['Meetings', '/governance/ceo-reports'],
        ]);

        const reviewee = governanceCatalog({
            governance: {
                ...ceo,
                performance: { view: true, reviewee: true },
            },
        });
        expect(
            reviewee.find((item) => item.label === 'Strategy & performance')
                ?.href,
        ).toBe('/governance/performance');
    });

    it('never treats hidden navigation as access: no view, no hub', () => {
        const actions = governanceActionableTabKeys({
            governance: { budgets: { approve: true } },
        });
        expect([...actions]).toEqual(['budgets']);
        expect(
            governanceCatalog({
                governance: { budgets: { approve: true } },
            }),
        ).toEqual([]);
    });
});

describe('finance sidebar highlighting', () => {
    const HUBS = [
        '/finance',
        '/finance/ledger',
        '/finance/payables',
        '/finance/invoices',
        '/finance/banking',
        '/finance/tax',
        '/finance/reports',
        '/finance/settings',
    ];

    // The sidebar lights every entry whose match score is positive — there is
    // no single winner — and Overview lives at /finance, a prefix of every
    // other finance URL. Without the finance-hub branch in matchScore the
    // generic "starts with" rule lit Overview on top of the real hub on every
    // finance page.
    it.each([
        ['/finance', '/finance'],
        ['/finance/calendar', '/finance'],
        ['/finance/bills', '/finance/payables'],
        ['/finance/bills/12', '/finance/payables'],
        ['/finance/accounts', '/finance/ledger'],
        ['/finance/journals/4', '/finance/ledger'],
        ['/finance/invoices', '/finance/invoices'],
        ['/finance/receivables/statements', '/finance/invoices'],
        ['/finance/bank-accounts', '/finance/banking'],
        ['/finance/eftpos/batches/9', '/finance/banking'],
        ['/finance/petty-cash', '/finance/banking'],
        ['/finance/gst-returns', '/finance/tax'],
        ['/finance/donor-funds/3', '/finance/tax'],
        ['/finance/reports/balance-sheet', '/finance/reports'],
        ['/finance/cash-flow-forecast', '/finance/reports'],
        ['/finance/integrations', '/finance/settings'],
        ['/finance/match-rules', '/finance/settings'],
    ])('lights exactly one hub on %s', (url, expected) => {
        const lit = HUBS.filter((href) => isSubItemActive(url, href));

        expect(lit).toEqual([expected]);
    });

    it('lights no finance hub outside the module', () => {
        expect(HUBS.filter((href) => isSubItemActive('/governance/meetings', href))).toEqual([]);
        expect(HUBS.filter((href) => isSubItemActive('/dashboard', href))).toEqual([]);
    });
});
