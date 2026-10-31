import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    CommandCentreAttention,
    CommandCentreHero,
    type CommandCentreHeroProps,
} from './command-centre-hero';
const shared = vi.hoisted(() => ({ get: vi.fn(), governance: false }));
vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        preserveState: _state,
        preserveScroll: _scroll,
        ...props
    }: AnchorHTMLAttributes<HTMLAnchorElement> & {
        href: string;
        preserveState?: boolean;
        preserveScroll?: boolean;
    }) => <a href={href} {...props} />,
    router: { get: shared.get },
    usePage: () => ({
        props: { auth: { can: { governance: { view: shared.governance } } } },
    }),
}));
afterEach(() => {
    cleanup();
    vi.useRealTimers();
    shared.get.mockClear();
    shared.governance = false;
});
const baseline = {
    leadingLagging: {
        lagging: {
            incidents: 2,
            ltifr: 0,
            trifr: 0,
            injury_severity_rate: 0,
            days_since_lti: 45,
        },
        leading: {
            near_miss_ratio: 3,
            actions_on_time_pct: 95,
            training_pct: 98,
            open_hazards: 1,
        },
    },
    filters: {
        from: '2026-07-01',
        to: '2026-07-14',
        site: null,
        lens: 'governance',
    },
    sites: [{ id: 3, name: 'Kauri House' }],
    expiring: [],
    worksafePending: 7,
    activeAlerts: 0,
    openSafeguarding: 0,
    fleetUnresolved: 0,
    fleetIncidents30d: 0,
    procedures: { approved: 4, review_due: 0, coverage_gap_categories: 0 },
    orgName: 'Oblivion Care',
} satisfies CommandCentreHeroProps;

describe('H&S shared header and source-aware attention', () => {
    it.each([null, 0, 45])(
        'keeps the recorded lost-time interval and its destination without a safety-clearance claim (%s)',
        (value) => {
            render(
                <CommandCentreHero
                    {...baseline}
                    leadingLagging={{
                        ...baseline.leadingLagging,
                        lagging: {
                            ...baseline.leadingLagging.lagging,
                            days_since_lti: value,
                        },
                    }}
                />,
            );
            const meter = screen.getByRole('link', {
                name: 'View days since a lost-time injury',
            });
            expect(
                within(meter).getByText(value === null ? '—' : String(value)),
            ).toBeInTheDocument();
            if (value === null)
                expect(within(meter).getByText('Not confirmed')).toBeInTheDocument();
            else
                expect(within(meter).queryByText('Not confirmed')).not.toBeInTheDocument();
            expect(meter).toHaveAttribute(
                'href',
                '/health-safety/analytics?period=custom&from=2026-07-01&to=2026-07-14&lens=governance',
            );
            expect(screen.queryByText(/LTI-free|all clear/i)).not.toBeInTheDocument();
        },
    );
    it('uses the exact pending WorkSafe count and never shows unconfirmed PPE as zero', () => {
        render(
            <>
                <CommandCentreHero {...baseline} />
                <CommandCentreAttention {...baseline} />
            </>,
        );
        const worksafe = screen.getByRole('link', { name: /WorkSafe reviews to do/ });
        expect(within(worksafe).getByText('7')).toBeInTheDocument();
        expect(worksafe).toHaveAttribute('href', '/health-safety/events?tab=worksafe_pending');
        // No PPE state from the source: a dash and its reason, never a zero.
        const ppe = screen.getByRole('link', { name: /Protective equipment \(PPE\) due/ });
        expect(within(ppe).getByText('—')).toBeInTheDocument();
        expect(within(ppe).getByText('Not confirmed')).toBeInTheDocument();
        // Zeros fold into one line that says they only cover readable records.
        expect(
            screen.getByText(
                'None in the records you can see: Lone-worker alerts active, Safety data sheets to review, Drills due',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/all checked in|PPE all current|synced just now|all in review window|^Nothing/),
        ).not.toBeInTheDocument();
        expect(
            screen
                .getByRole('heading', { level: 1, name: 'Health and safety' })
                .closest('header'),
        ).toHaveClass('eh-header');
    });
    it.each([
        ['restricted', true, '—', 'Access restricted'],
        ['repair_required', true, '0', 'Facts need review'],
        ['intact', true, '0', 'More records than shown'],
    ] as const)(
        'keeps %s PPE truth on show and never counts undated source repairs as due equipment',
        (integrity, truncated, value, note) => {
            render(
                <CommandCentreAttention
                    {...baseline}
                    expiring={[{ type: 'ppe_repair' }]}
                    ppeState={{
                        source_integrity: integrity,
                        truncated,
                        confirmed_count: null,
                        total: null,
                        repair_count: integrity === 'repair_required' ? 1 : null,
                        physical_record_count: null,
                        displayed_count: 1,
                    }}
                />,
            );
            const ppe = screen.getByRole('link', { name: /Protective equipment \(PPE\) due/ });
            expect(within(ppe).getByText(value)).toBeInTheDocument();
            expect(within(ppe).getByText(note)).toBeInTheDocument();
            expect(within(ppe).queryByText('1')).not.toBeInTheDocument();
        },
    );
    it('folds an intact, complete PPE zero into the records-you-can-see line', () => {
        render(
            <CommandCentreAttention
                {...baseline}
                expiring={[{ type: 'ppe_repair' }]}
                ppeState={{
                    source_integrity: 'intact',
                    truncated: false,
                    confirmed_count: 0,
                    total: 0,
                    repair_count: null,
                    physical_record_count: null,
                    displayed_count: 0,
                }}
            />,
        );
        expect(
            screen.queryByRole('link', { name: /Protective equipment/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(/^None in the records you can see: .*Protective equipment \(PPE\) due$/),
        ).toBeInTheDocument();
    });
    it('shows four linked numbers for each lens and keeps their destinations', () => {
        const result = render(<CommandCentreHero {...baseline} />);
        const analytics =
            '/health-safety/analytics?period=custom&from=2026-07-01&to=2026-07-14&lens=governance';
        expect(screen.getByRole('link', { name: 'View incidents' })).toHaveAttribute(
            'href',
            '/incidents?from=2026-07-01&to=2026-07-14&kind=incidents&tab=all',
        );
        for (const name of [
            'View lost-time injury rate',
            'View recordable injury rate',
            'View days since a lost-time injury',
        ])
            expect(screen.getByRole('link', { name })).toHaveAttribute('href', analytics);
        result.rerender(
            <CommandCentreHero
                {...baseline}
                filters={{ ...baseline.filters, lens: 'manager' }}
            />,
        );
        const managerAnalytics = analytics.replace('lens=governance', 'lens=manager');
        expect(
            screen.getByRole('link', { name: 'View near misses per injury' }),
        ).toHaveAttribute('href', managerAnalytics);
        expect(screen.getByRole('link', { name: 'View training' })).toHaveAttribute(
            'href',
            managerAnalytics,
        );
        expect(
            screen.getByRole('link', { name: 'View actions on time' }),
        ).toHaveAttribute(
            'href',
            '/health-safety/corrective-actions?from=2026-07-01&to=2026-07-14&tab=all',
        );
        expect(
            screen.getByRole('link', { name: 'View open hazards' }),
        ).toHaveAttribute('href', '/compliance/hazards?tab=active');
        expect(screen.queryByRole('link', { name: 'View incidents' })).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Board reports/ }),
        ).not.toBeInTheDocument();
    });
    it('retains the report callback and exposes only authorised board exports', () => {
        shared.governance = true;
        const onReport = vi.fn();
        render(<CommandCentreHero {...baseline} onReport={onReport} />);
        fireEvent.click(screen.getByRole('button', { name: 'Report something' }));
        expect(onReport).toHaveBeenCalledTimes(1);
        fireEvent.click(
            screen.getByRole('button', { name: /Board reports/ }),
        );
        expect(
            screen.getByRole('link', {
                name: 'Actions and their safety reviews',
            }),
        ).toHaveAttribute(
            'href',
            '/health-safety/reports?view=action-traceability',
        );
    });
    it('carries a nondefault Site and custom period only to destinations that consume those filters', () => {
        const filters = {
            from: '2026-09-03',
            to: '2026-09-19',
            site: 3,
            lens: 'governance',
        };
        const result = render(
            <>
                <CommandCentreHero {...baseline} filters={filters} />
                <CommandCentreAttention {...baseline} activeAlerts={2} site={3} />
            </>,
        );
        expect(screen.getByRole('link', { name: 'View incidents' })).toHaveAttribute(
            'href',
            '/incidents?from=2026-09-03&to=2026-09-19&kind=incidents&tab=all&site_id=3',
        );
        const rateDetail =
            '/health-safety/analytics?period=custom&from=2026-09-03&to=2026-09-19&lens=governance&site_id=3';
        expect(
            screen.getByRole('link', { name: 'View lost-time injury rate' }),
        ).toHaveAttribute('href', rateDetail);
        expect(
            screen.getByRole('link', { name: 'View recordable injury rate' }),
        ).toHaveAttribute('href', rateDetail);
        // Current-state registers keep the Site but get no occurrence dates.
        expect(
            screen.getByRole('link', { name: /WorkSafe reviews to do/ }),
        ).toHaveAttribute('href', '/health-safety/events?tab=worksafe_pending&site_id=3');
        expect(
            screen.getByRole('link', { name: /Lone-worker alerts active/ }),
        ).toHaveAttribute('href', '/health-safety/lone-workers?site_id=3');
        result.rerender(
            <CommandCentreHero
                {...baseline}
                filters={{ ...filters, lens: 'manager' }}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'View actions on time' }),
        ).toHaveAttribute(
            'href',
            '/health-safety/corrective-actions?from=2026-09-03&to=2026-09-19&tab=all&site_id=3',
        );
        expect(
            screen.getByRole('link', { name: 'View open hazards' }),
        ).toHaveAttribute('href', '/compliance/hazards?tab=active&site_id=3');
    });
    it('keeps the overview search inside the shared header and sends its value to the current preview filter', () => {
        const onChange = vi.fn();
        render(
            <CommandCentreHero
                {...baseline}
                search={{ value: '', onChange }}
            />,
        );
        const field = screen.getByRole('searchbox', {
            name: 'Search this overview',
        });
        expect(field.closest('header')).toHaveClass('eh-header');
        fireEvent.change(field, { target: { value: 'Kauri fall' } });
        expect(onChange).toHaveBeenCalledWith('Kauri fall');
    });
    it('shows missing counts as unconfirmed and never silently selects all sites for a withdrawn selected site', () => {
        render(
            <>
                <CommandCentreHero
                    {...baseline}
                    filters={{ ...baseline.filters, site: 999 }}
                />
                <CommandCentreAttention
                    {...baseline}
                    worksafePending={null}
                    activeAlerts={null}
                />
            </>,
        );
        expect(screen.getByText(/Site access unconfirmed/)).toBeInTheDocument();
        for (const name of [/WorkSafe reviews to do/, /Lone-worker alerts active/]) {
            const link = screen.getByRole('link', { name });
            expect(within(link).getByText('—')).toBeInTheDocument();
            expect(within(link).getByText('Not confirmed')).toBeInTheDocument();
        }
        expect(
            screen.queryByText(/None in the records you can see: WorkSafe/),
        ).not.toBeInTheDocument();
    });
    it('applies an inclusive 30-day NZ range at a UTC day boundary and preserves the selected site, lens and view', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-11T11:30:00Z'));
        render(
            <CommandCentreHero
                {...baseline}
                filters={{ ...baseline.filters, site: 3, lens: 'manager' }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Custom range' }));
        fireEvent.click(screen.getByRole('button', { name: '30 days' }));
        expect(shared.get).toHaveBeenCalledWith(
            '/health-safety',
            { from: '2026-09-13', to: '2026-10-12', site: 3, lens: 'manager' },
            { preserveScroll: true, preserveState: true },
        );
    });
    it('refuses an incomplete calendar range and refreshes it when authoritative filters change', () => {
        const result = render(<CommandCentreHero {...baseline} />);
        fireEvent.click(screen.getByRole('button', { name: 'Set dates' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Wed 8 July 2026' }),
        );
        expect(
            within(
                screen.getByRole('group', { name: 'Safety overview period' }),
            ).getByRole('status'),
        ).toHaveTextContent('Starts 8 Jul 2026 · choose the end day');
        expect(
            screen.getByRole('button', { name: 'Apply range' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Apply range' }));
        expect(shared.get).not.toHaveBeenCalled();
        result.rerender(
            <CommandCentreHero
                {...baseline}
                filters={{
                    ...baseline.filters,
                    from: '2026-10-01',
                    to: '2026-10-12',
                }}
            />,
        );
        expect(screen.getByText('October 2026')).toBeInTheDocument();
        expect(
            within(
                screen.getByRole('group', { name: 'Safety overview period' }),
            ).getByRole('status'),
        ).toHaveTextContent('1 Oct 2026 – 12 Oct 2026 · inclusive');
        fireEvent.click(screen.getByRole('button', { name: 'Apply range' }));
        expect(shared.get).toHaveBeenCalledWith(
            '/health-safety',
            { from: '2026-10-01', to: '2026-10-12', lens: 'governance' },
            { preserveScroll: true, preserveState: true },
        );
    });

    it('applies a selected calendar range without changing the current Site or lens', () => {
        render(
            <CommandCentreHero
                {...baseline}
                filters={{ ...baseline.filters, site: 3, lens: 'manager' }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Set dates' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Wed 8 July 2026' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Fri 17 July 2026' }),
        );
        expect(
            within(
                screen.getByRole('group', { name: 'Safety overview period' }),
            ).getByRole('status'),
        ).toHaveTextContent('8 Jul 2026 – 17 Jul 2026 · inclusive');
        fireEvent.click(screen.getByRole('button', { name: 'Apply range' }));
        expect(shared.get).toHaveBeenCalledExactlyOnceWith(
            '/health-safety',
            { from: '2026-07-08', to: '2026-07-17', site: 3, lens: 'manager' },
            { preserveScroll: true, preserveState: true },
        );
    });

    it('allows an explicit one-day range and cancels a partial selection without changing the applied period', () => {
        render(<CommandCentreHero {...baseline} />);
        fireEvent.click(screen.getByRole('button', { name: 'Set dates' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Wed 8 July 2026' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel' }),
        );
        expect(shared.get).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Set dates' }));
        expect(
            within(
                screen.getByRole('group', { name: 'Safety overview period' }),
            ).getByRole('status'),
        ).toHaveTextContent('1 Jul 2026 – 14 Jul 2026 · inclusive');
        fireEvent.click(
            screen.getByRole('button', { name: 'Wed 8 July 2026' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Wed 8 July 2026' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Apply range' }));
        expect(shared.get).toHaveBeenCalledExactlyOnceWith(
            '/health-safety',
            { from: '2026-07-08', to: '2026-07-08', lens: 'governance' },
            { preserveScroll: true, preserveState: true },
        );
    });

    it('keeps date-only endpoints while selecting a range across months', () => {
        render(
            <CommandCentreHero
                {...baseline}
                filters={{ ...baseline.filters, site: 3 }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Set dates' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Fri 31 July 2026' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Next month' }));
        expect(screen.getByText('August 2026')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Sun 2 August 2026' }),
        );
        expect(
            within(
                screen.getByRole('group', { name: 'Safety overview period' }),
            ).getByRole('status'),
        ).toHaveTextContent('31 Jul 2026 – 2 Aug 2026 · inclusive');
        fireEvent.click(screen.getByRole('button', { name: 'Apply range' }));
        expect(shared.get).toHaveBeenCalledExactlyOnceWith(
            '/health-safety',
            {
                from: '2026-07-31',
                to: '2026-08-02',
                site: 3,
                lens: 'governance',
            },
            { preserveScroll: true, preserveState: true },
        );
    });
});
