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
const baseline: CommandCentreHeroProps = {
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
};

describe('H&S shared header and source-aware attention', () => {
    it.each([null, 0, 45])(
        'keeps the recorded LTI interval and its destination without a safety-clearance claim (%s)',
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
                name: 'View days since lti',
            });
            expect(
                within(meter).getByText(value === null ? '—' : String(value)),
            ).toBeInTheDocument();
            expect(
                within(meter).getByText(
                    value === null
                        ? 'Not confirmed'
                        : 'last readable lost-time injury',
                ),
            ).toBeInTheDocument();
            expect(meter).toHaveAttribute(
                'href',
                '/health-safety/analytics?period=custom&from=2026-07-01&to=2026-07-14&lens=governance',
            );
            expect(screen.queryByText(/LTI-free/i)).not.toBeInTheDocument();
        },
    );
    it('uses the exact backbone pending count instead of capped worklist rows without claiming safety clearance', () => {
        render(
            <>
                <CommandCentreHero {...baseline} />
                <CommandCentreAttention {...baseline} />
            </>,
        );
        expect(
            screen.getByText('7 pending WorkSafe reviews'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('1 pending WorkSafe reviews'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('0 recorded active lone-worker alerts'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('PPE attention unconfirmed'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(
                /all checked in|PPE all current|synced just now|all in review window/,
            ),
        ).not.toBeInTheDocument();
        expect(
            screen
                .getByRole('heading', { level: 1, name: 'Health & Safety' })
                .closest('header'),
        ).toHaveClass('eh-header');
        expect(
            screen.getByRole('link', { name: '7 pending WorkSafe reviews' }),
        ).toHaveAttribute('href', '/health-safety/events');
    });
    it.each([
        ['restricted', true, 'PPE access restricted'],
        ['repair_required', true, 'PPE facts need review'],
        ['intact', true, 'PPE preview incomplete'],
        ['intact', false, 'No matching PPE due in preview'],
    ] as const)(
        'retains %s preview truth and never counts undated source repairs as due equipment',
        (integrity, truncated, label) => {
            const ppeState = {
                source_integrity: integrity,
                truncated,
                confirmed_count: null,
                total: null,
                repair_count: integrity === 'repair_required' ? 1 : null,
                physical_record_count: null,
                displayed_count: 1,
            };
            render(
                <CommandCentreAttention
                    {...baseline}
                    expiring={[{ type: 'ppe_repair' }]}
                    ppeState={ppeState}
                />,
            );
            expect(screen.getByText(label)).toBeInTheDocument();
            expect(
                screen.queryByText(/1 PPE due|No PPE due alerts/),
            ).not.toBeInTheDocument();
        },
    );
    it('uses six meters in the canonical row and retains outcome and prevention destinations through the lens filter', () => {
        const result = render(<CommandCentreHero {...baseline} />);
        expect(screen.getAllByRole('link', { name: /^View /i })).toHaveLength(
            7,
        );
        expect(
            screen.getByRole('link', { name: 'View safeguarding' }),
        ).toHaveAttribute('href', '/safeguarding?tab=open');
        expect(
            screen.getByRole('link', { name: 'View ltifr' }),
        ).toHaveAttribute(
            'href',
            '/health-safety/analytics?period=custom&from=2026-07-01&to=2026-07-14&lens=governance',
        );
        expect(
            screen.getByRole('link', { name: 'View fleet incidents' }),
        ).toHaveAttribute('href', '/fleet-assets/incidents?tab=open');
        result.rerender(
            <CommandCentreHero
                {...baseline}
                filters={{ ...baseline.filters, lens: 'manager' }}
            />,
        );
        expect(screen.getAllByRole('link', { name: /^View /i })).toHaveLength(
            6,
        );
        expect(
            screen.getByRole('link', { name: 'View near-miss ratio' }),
        ).toHaveAttribute(
            'href',
            '/health-safety/analytics?period=custom&from=2026-07-01&to=2026-07-14&lens=manager',
        );
        expect(
            screen.getByRole('link', { name: 'View open hazards' }),
        ).toHaveAttribute('href', '/compliance/hazards?tab=active');
        expect(
            screen.getByRole('link', { name: 'View procedures' }),
        ).toHaveAttribute('href', '/health-safety/procedures?tab=approved');
        expect(
            screen.getByRole('link', {
                name: 'Open full fleet incident register',
            }),
        ).toHaveAttribute('href', '/fleet-assets/incidents');
        expect(
            screen.queryByRole('button', { name: /Export board summary/ }),
        ).not.toBeInTheDocument();
    });
    it('withholds Fleet navigation under both lenses when canonical Fleet counts are restricted, while a readable zero still links', () => {
        const result = render(
            <CommandCentreHero
                {...baseline}
                fleetUnresolved={null}
                fleetIncidents30d={null}
            />,
        );
        expect(
            screen.queryByRole('link', { name: 'View fleet incidents' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Access restricted')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Explain restricted Fleet access',
            }),
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'Fleet access is restricted for the selected sites.',
        );
        result.rerender(
            <CommandCentreHero
                {...baseline}
                filters={{ ...baseline.filters, lens: 'manager' }}
                fleetUnresolved={null}
                fleetIncidents30d={null}
            />,
        );
        expect(
            screen.queryByRole('link', {
                name: 'Open full fleet incident register',
            }),
        ).not.toBeInTheDocument();
        result.rerender(
            <CommandCentreHero
                {...baseline}
                filters={{ ...baseline.filters, lens: 'manager' }}
                fleetIncidents30d={0}
            />,
        );
        expect(
            screen.getByRole('link', {
                name: 'Open full fleet incident register',
            }),
        ).toHaveAttribute('href', '/fleet-assets/incidents');
    });
    it('retains the report callback and exposes only authorised board exports', () => {
        shared.governance = true;
        const onReport = vi.fn();
        render(<CommandCentreHero {...baseline} onReport={onReport} />);
        fireEvent.click(screen.getByRole('button', { name: 'Report' }));
        expect(onReport).toHaveBeenCalledTimes(1);
        fireEvent.click(
            screen.getByRole('button', { name: /Export board summary/ }),
        );
        expect(
            screen.getByRole('link', {
                name: 'Corrective-action traceability',
            }),
        ).toHaveAttribute(
            'href',
            '/health-safety/reports/corrective-action-traceability',
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
            <CommandCentreHero {...baseline} filters={filters} />,
        );
        expect(
            screen.getByRole('link', { name: 'View incidents' }),
        ).toHaveAttribute(
            'href',
            '/incidents?from=2026-09-03&to=2026-09-19&kind=incidents&tab=all&site_id=3',
        );
        expect(
            screen.getByRole('link', { name: 'View safeguarding' }),
        ).toHaveAttribute('href', '/safeguarding?tab=open&site_id=3');
        expect(
            screen.getByRole('link', { name: 'View fleet incidents' }),
        ).toHaveAttribute('href', '/fleet-assets/incidents?tab=open&site_id=3');
        const rateDetail =
            '/health-safety/analytics?period=custom&from=2026-09-03&to=2026-09-19&lens=governance&site_id=3';
        expect(
            screen.getByRole('link', { name: 'View ltifr' }),
        ).toHaveAttribute('href', rateDetail);
        expect(
            screen.getByRole('link', { name: 'View trifr' }),
        ).toHaveAttribute('href', rateDetail);
        expect(
            screen.getAllByText('year basis · per million hours'),
        ).toHaveLength(2);
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
        expect(screen.getByText('due in selected period')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'View open hazards' }),
        ).toHaveAttribute('href', '/compliance/hazards?tab=active&site_id=3');
        expect(
            screen.getByRole('link', { name: 'View procedures' }),
        ).toHaveAttribute(
            'href',
            '/health-safety/procedures?tab=approved&site_id=3',
        );
        expect(
            screen.getByText('year basis · per recordable injury'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Training / audit')).not.toBeInTheDocument();
        const register = screen.getByRole('link', {
            name: 'Open full fleet incident register',
        });
        expect(register).toHaveAttribute(
            'href',
            '/fleet-assets/incidents?site_id=3',
        );
        expect(
            screen.getByText('30-day count · full register'),
        ).toBeInTheDocument();
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
            name: 'Search current safety overview',
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
                    openSafeguarding={null}
                    fleetUnresolved={null}
                    fleetIncidents30d={null}
                />
                <CommandCentreAttention
                    {...baseline}
                    worksafePending={null}
                    activeAlerts={null}
                />
            </>,
        );
        expect(screen.getByText(/Site access unconfirmed/)).toBeInTheDocument();
        expect(
            screen.getByText('WorkSafe review count unconfirmed'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Lone-worker alert count unconfirmed'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('0 pending WorkSafe reviews'),
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
            screen.getByRole('button', { name: 'Cancel', exact: true }),
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
