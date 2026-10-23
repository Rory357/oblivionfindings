import { cleanup, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CompliancePanel, LaggingPanel, LeadingPanel } from './dashboard-tabs';

const access = vi.hoisted(() => ({
    compliance: true,
    id: 7,
    participation: true,
}));
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    usePage: () => ({
        props: {
            auth: {
                user: { id: access.id },
                can: {
                    hazards: { view: access.participation },
                    hr: { compliance: { view: access.compliance } },
                },
            },
        },
    }),
}));
beforeEach(() => {
    access.compliance = true;
    access.id = 7;
    access.participation = true;
});
afterEach(cleanup);

describe('Readable lagging measures', () => {
    it.each([null, 0, 45])(
        'keeps the LTI interval neutral and missing rates unconfirmed (%s)',
        (days) => {
            render(
                <LaggingPanel
                    data={{
                        incidents: 0,
                        ltifr: null,
                        trifr: null,
                        injury_severity_rate: null,
                        days_since_lti: days,
                    }}
                />,
            );
            const interval = screen.getByRole('link', {
                name: /Days since LTI/,
            });
            expect(
                within(interval).getByText(days === null ? '—' : String(days)),
            ).toBeInTheDocument();
            expect(
                within(interval).getByText(
                    days === null
                        ? 'Not confirmed'
                        : 'last readable lost-time injury',
                ),
            ).toBeInTheDocument();
            expect(interval).toHaveAttribute('href', '/health-safety/injuries');
            const intervalCard = interval.querySelector('[data-slot="card"]');
            expect(intervalCard).not.toHaveClass('border-l-status-success');
            expect(intervalCard).not.toHaveClass('border-l-status-warning');
            expect(interval).toHaveClass('frontline-tap', 'frontline-focus');
            expect(
                within(screen.getByRole('link', { name: /^LTIFR/ })).getByText(
                    'Not confirmed',
                ),
            ).toBeInTheDocument();
            expect(
                within(screen.getByRole('link', { name: /^TRIFR/ })).getByText(
                    'Not confirmed',
                ),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('link', { name: /^Incidents/ }),
            ).toHaveTextContent('0');
            expect(
                screen
                    .getByRole('link', { name: /^Incidents/ })
                    .querySelector('[data-slot="card"]'),
            ).not.toHaveClass('border-l-status-success');
            expect(
                screen
                    .getByRole('link', { name: /^LTIFR/ })
                    .querySelector('[data-slot="card"]'),
            ).not.toHaveClass('border-l-status-warning');
            expect(
                screen.queryByText(/LTI-free|all clear|no injuries/i),
            ).not.toBeInTheDocument();
        },
    );

    it('preserves positive incidents and rates without granting a safety status to the interval', () => {
        render(
            <LaggingPanel
                data={{
                    incidents: 2,
                    ltifr: 1.5,
                    trifr: 3,
                    injury_severity_rate: 4,
                    days_since_lti: 60,
                }}
            />,
        );
        expect(
            screen.getByRole('link', { name: /^Incidents/ }),
        ).toHaveTextContent('2');
        expect(screen.getByRole('link', { name: /^LTIFR/ })).toHaveTextContent(
            '1.5',
        );
        expect(screen.getByRole('link', { name: /^TRIFR/ })).toHaveTextContent(
            '3',
        );
        expect(
            screen.getByRole('link', { name: /Days since LTI/ }),
        ).toHaveTextContent('60');
        expect(screen.queryByText('Not confirmed')).not.toBeInTheDocument();
        expect(screen.getAllByRole('link')).toHaveLength(4);
        expect(
            screen.getByRole('link', { name: /^Incidents/ }),
        ).toHaveAttribute('href', '/incidents?kind=incidents&tab=all');
    });

    it('carries the selected incident kind, period and Site while keeping injury history untruncated', () => {
        render(
            <LaggingPanel
                data={{
                    incidents: 2,
                    ltifr: 1.5,
                    trifr: 3,
                    injury_severity_rate: 4,
                    days_since_lti: 60,
                }}
                filters={{
                    from: '2026-07-01',
                    to: '2026-07-14',
                    site: 3,
                    lens: 'manager',
                }}
            />,
        );
        const incidentHref = new URL(
            screen
                .getByRole('link', { name: /^Incidents/ })
                .getAttribute('href')!,
            'https://example.test',
        );
        expect(incidentHref.pathname).toBe('/incidents');
        expect(Object.fromEntries(incidentHref.searchParams)).toEqual({
            kind: 'incidents',
            tab: 'all',
            from: '2026-07-01',
            to: '2026-07-14',
            site_id: '3',
        });
        for (const name of [/^LTIFR/, /^TRIFR/, /Days since LTI/]) {
            expect(screen.getByRole('link', { name })).toHaveAttribute(
                'href',
                '/health-safety/injuries?site_id=3',
            );
        }
    });
});

describe('Leading measures without invented assurance', () => {
    it('preserves supported Site and due-period filters without truncating the annual ratio, and uses the real training destination', () => {
        render(
            <LeadingPanel
                data={{
                    near_miss_ratio: 2,
                    actions_on_time_pct: 50,
                    training_pct: 70,
                    open_hazards: 0,
                }}
                workerParticipation={{
                    pct: 80,
                    committees: 2,
                    source_access: 'readable',
                    viewer_id: 7,
                }}
                siteCount={3}
                filters={{
                    from: '2026-07-01',
                    to: '2026-07-14',
                    site: 3,
                    lens: 'manager',
                }}
            />,
        );
        expect(
            screen.getByRole('link', {
                name: /^Near-miss : recordable injury/,
            }),
        ).toHaveAttribute(
            'href',
            '/incidents?kind=near_misses&tab=all&site_id=3',
        );
        expect(
            screen.getByRole('link', { name: /^Actions closed on time/ }),
        ).toHaveAttribute(
            'href',
            '/health-safety/corrective-actions?tab=all&from=2026-07-01&to=2026-07-14&site_id=3',
        );
        expect(
            screen.getByRole('link', {
                name: /^Sites with recorded representatives/,
            }),
        ).toHaveAttribute(
            'href',
            '/health-safety/worker-participation?tab=representatives&site_id=3',
        );
        expect(
            screen.getByRole('link', { name: /^H&S training/ }),
        ).toHaveAttribute('href', '/hr/compliance');
        expect(
            screen.getByText('readable reports · annual rate window'),
        ).toBeInTheDocument();
    });

    it('keeps the available training value without a forbidden HR destination when the viewer lacks its permission', () => {
        access.compliance = false;
        render(
            <LeadingPanel
                data={{
                    near_miss_ratio: null,
                    actions_on_time_pct: null,
                    training_pct: 70,
                    open_hazards: 0,
                }}
                workerParticipation={{
                    pct: null,
                    committees: 0,
                    source_access: 'readable',
                    viewer_id: 7,
                }}
                siteCount={1}
            />,
        );
        expect(
            screen.getByText('H&S training').closest('[data-slot="card"]'),
        ).toHaveTextContent('70%');
        expect(
            screen.queryByRole('link', { name: /^H&S training/ }),
        ).not.toBeInTheDocument();
        expect(screen.getAllByRole('link')).toHaveLength(3);
    });
    it.each([null, 0, 100])(
        'distinguishes missing values from readable percentages (%s)',
        (value) => {
            render(
                <LeadingPanel
                    data={{
                        near_miss_ratio: value,
                        actions_on_time_pct: value,
                        training_pct: value,
                        open_hazards: 0,
                    }}
                    workerParticipation={{
                        pct: value,
                        committees: 2,
                        source_access: 'readable',
                        viewer_id: 7,
                    }}
                    siteCount={3}
                />,
            );
            const links = screen.getAllByRole('link');
            expect(links).toHaveLength(4);
            for (const link of links) {
                expect(link).toHaveClass('frontline-tap', 'frontline-focus');
                if (value === null) {
                    expect(within(link).getByText('—')).toBeInTheDocument();
                    expect(
                        within(link).getByText('Not confirmed'),
                    ).toBeInTheDocument();
                    const card = link.querySelector('[data-slot="card"]');
                    expect(card).not.toHaveClass('border-l-status-warning');
                    expect(card).not.toHaveClass('border-l-status-success');
                } else {
                    expect(link).toHaveTextContent(String(value));
                    expect(
                        within(link).queryByText('Not confirmed'),
                    ).not.toBeInTheDocument();
                }
            }
            const ratio = screen.getByRole('link', {
                name: /^Near-miss : recordable injury/,
            });
            expect(ratio).toHaveAttribute(
                'href',
                '/incidents?kind=near_misses&tab=all',
            );
            expect(ratio.querySelector('[data-slot="card"]')).not.toHaveClass(
                'border-l-status-success',
            );
            expect(
                screen.queryByText(
                    /strong reporting culture|Training & audit/i,
                ),
            ).not.toBeInTheDocument();
        },
    );
});

describe('Participation source access', () => {
    it("does not treat another actor's retained denial as the current coverage status", () => {
        render(
            <LeadingPanel
                data={{
                    near_miss_ratio: null,
                    actions_on_time_pct: null,
                    training_pct: null,
                    open_hazards: 0,
                }}
                workerParticipation={{
                    pct: 97,
                    committees: 999,
                    source_access: 'restricted',
                    viewer_id: 8,
                }}
                siteCount={3}
            />,
        );
        const card = screen
            .getByText('Sites with recorded representatives')
            .closest('[data-slot="card"]')!;
        expect(card).toHaveTextContent('Not confirmed');
        expect(card).not.toHaveTextContent('Access restricted');
        expect(card).not.toHaveTextContent('97');
        expect(card).not.toHaveTextContent('999');
        expect(
            screen.queryByRole('link', {
                name: /^Sites with recorded representatives/,
            }),
        ).not.toBeInTheDocument();
    });
    it.each(['account', 'permission'] as const)(
        'clears the retained coverage, committee count and destination on a shared %s change',
        (change) => {
            const props = {
                data: {
                    near_miss_ratio: null,
                    actions_on_time_pct: null,
                    training_pct: null,
                    open_hazards: 0,
                },
                workerParticipation: {
                    pct: 97,
                    committees: 999,
                    source_access: 'readable' as const,
                    viewer_id: 7,
                },
                siteCount: 3,
            };
            const view = render(<LeadingPanel {...props} />);
            expect(
                screen.getByRole('link', {
                    name: /^Sites with recorded representatives/,
                }),
            ).toHaveTextContent('97%');
            if (change === 'account') access.id = 8;
            else access.participation = false;
            view.rerender(<LeadingPanel {...props} />);
            const card = screen
                .getByText('Sites with recorded representatives')
                .closest('[data-slot="card"]')!;
            expect(card).not.toHaveTextContent('97');
            expect(card).not.toHaveTextContent('999');
            expect(card).toHaveTextContent(
                change === 'permission' ? 'Access restricted' : 'Not confirmed',
            );
            expect(
                screen.queryByRole('link', {
                    name: /^Sites with recorded representatives/,
                }),
            ).not.toBeInTheDocument();
        },
    );
    it.each(['restricted', 'unavailable', undefined] as const)(
        'masks retained positive figures and the participation destination when its source is %s',
        (source) => {
            render(
                <LeadingPanel
                    data={{
                        near_miss_ratio: null,
                        actions_on_time_pct: null,
                        training_pct: null,
                        open_hazards: 0,
                    }}
                    workerParticipation={{
                        pct: 97,
                        committees: 999,
                        source_access: source,
                        viewer_id: 7,
                    }}
                    siteCount={3}
                />,
            );
            const card = screen
                .getByText('Sites with recorded representatives')
                .closest('[data-slot="card"]')!;
            expect(card).toHaveTextContent(
                source === 'restricted' ? 'Access restricted' : 'Not confirmed',
            );
            expect(card).not.toHaveTextContent('97%');
            expect(card).not.toHaveTextContent('999');
            expect(
                screen.queryByRole('link', {
                    name: /^Sites with recorded representatives/,
                }),
            ).not.toBeInTheDocument();
            expect(card).not.toHaveClass('border-l-status-success');
        },
    );
});

describe('Compliance previews without certification claims', () => {
    it('keeps standing pending WorkSafe work scoped to its Site without adding selected-period dates', () => {
        render(<CompliancePanel site={3} expiring={[]} worksafePending={2} />);
        expect(
            screen.getByRole('link', { name: 'Open event register' }),
        ).toHaveAttribute(
            'href',
            '/health-safety/events?tab=worksafe_pending&site_id=3',
        );
        expect(
            screen.getByRole('link', { name: 'Open chemical register' }),
        ).toHaveAttribute('href', '/health-safety/substances?site_id=3');
        expect(
            screen.getByRole('link', { name: 'Open drill register' }),
        ).toHaveAttribute('href', '/health-safety/drills?site_id=3');
        expect(
            screen.getByRole('link', { name: 'Open First Aid register' }),
        ).toHaveAttribute('href', '/health-safety/first-aid?site_id=3');
    });
    it.each([null, 0])(
        'keeps unknown or empty notification work distinct without implying clearance (%s)',
        (pending) => {
            render(<CompliancePanel expiring={[]} worksafePending={pending} />);
            expect(screen.getAllByText('Not confirmed')).toHaveLength(
                pending === null ? 3 : 2,
            );
            if (pending === 0)
                expect(
                    screen.getByText(
                        '0 readable records awaiting notification',
                    ),
                ).toBeInTheDocument();
            expect(
                screen.getByText('No SDS reviews in preview'),
            ).toBeInTheDocument();
            expect(
                screen.getByText('No drills in preview'),
            ).toBeInTheDocument();
            expect(
                screen.queryByText(
                    /^(Certified|Cover OK|SDS current|Drills current)$/,
                ),
            ).not.toBeInTheDocument();
            expect(
                screen.getByText(
                    'Certification evidence is not included in this overview.',
                ),
            ).toBeInTheDocument();
            expect(
                screen.getByText(
                    'Shift coverage and current first-aider certification are not included in this overview.',
                ),
            ).toBeInTheDocument();
            for (const link of screen.getAllByRole('link'))
                expect(link).toHaveClass('frontline-tap', 'frontline-focus');
            expect(
                screen.getByRole('link', { name: 'Open event register' }),
            ).toHaveAttribute(
                'href',
                '/health-safety/events?tab=worksafe_pending',
            );
            expect(
                screen.getByRole('link', { name: 'Open chemical register' }),
            ).toHaveAttribute('href', '/health-safety/substances');
            expect(
                screen.getByRole('link', { name: 'Open drill register' }),
            ).toHaveAttribute('href', '/health-safety/drills');
            expect(
                screen.getByRole('link', { name: 'Open First Aid register' }),
            ).toHaveAttribute('href', '/health-safety/first-aid');
        },
    );

    it('retains readable pending work and preview counts without treating previews as complete registers', () => {
        render(
            <CompliancePanel
                expiring={[
                    { type: 'sds' },
                    { type: 'sds' },
                    { type: 'drill' },
                    { type: 'other' },
                ]}
                worksafePending={2}
            />,
        );
        expect(
            screen.getByText('2 readable records awaiting notification'),
        ).toHaveClass('text-status-warning');
        expect(
            screen.getByText('2 SDS reviews in preview'),
        ).toBeInTheDocument();
        expect(screen.getByText('1 drill in preview')).toBeInTheDocument();
        expect(screen.getAllByText('Not confirmed')).toHaveLength(2);
        expect(
            screen.getByText(
                'The preview is not a complete register or a compliance assessment.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(
                /^(Certified|Cover OK|SDS current|Drills current)$/,
            ),
        ).not.toBeInTheDocument();
    });
});
