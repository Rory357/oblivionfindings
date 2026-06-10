import { describe, expect, it } from 'vitest';

import { buildNavSearchCatalog, isIconActive } from './app-sidebar';

describe('app sidebar workforce navigation', () => {
    it('moves billing navigation into Finance and leaves funding with client management', () => {
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

        const operationsItems = catalog.filter((item) => item.section === 'Operations');
        const financeItems = catalog.filter((item) => item.section === 'Finance');

        expect(operationsItems).toEqual(
            expect.arrayContaining([
                expect.objectContaining({
                    label: 'Funding',
                    href: '/operations/funding',
                    group: 'Client Management',
                }),
            ]),
        );

        expect(operationsItems.map((item) => item.group)).not.toContain('Time & Billing');
        expect(operationsItems.map((item) => item.label)).not.toEqual(
            expect.arrayContaining(['Billing', 'Invoices', 'Price Books', 'Quotes', 'Recurring Charges']),
        );

        expect(financeItems).toEqual(
            expect.arrayContaining([
                expect.objectContaining({ label: 'Billing', href: '/finance/billing' }),
                expect.objectContaining({ label: 'Invoices', href: '/finance/invoices' }),
                expect.objectContaining({ label: 'Price Books', href: '/finance/price-books' }),
                expect.objectContaining({ label: 'Quotes', href: '/finance/quotes' }),
                expect.objectContaining({ label: 'Recurring Charges', href: '/finance/recurring-charges' }),
            ]),
        );
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
