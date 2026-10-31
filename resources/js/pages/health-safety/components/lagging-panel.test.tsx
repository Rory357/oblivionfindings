/* The overview's Harm & injuries, Prevention and Duties panels (approved H&S drawings, 23 Oct). These keep
 * Codex's source-truth checks on the redrawn panels: a missing figure is a dash with a reason, never a zero;
 * restricted or another person's denial never shows retained figures or a link; nothing claims clearance. */
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

const CLEARANCE = /LTI-free|all clear|no injuries|^Nothing/i;

describe('Harm & injuries', () => {
    it.each([null, 0, 45])(
        'keeps the lost-time interval neutral and missing rates unconfirmed (%s)',
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
                name: /Days since a lost-time injury/,
            });
            expect(
                within(interval).getByText(days === null ? '—' : String(days)),
            ).toBeInTheDocument();
            expect(
                within(interval).getByText(
                    days === null
                        ? 'Not confirmed'
                        : 'Since the last one you can see',
                ),
            ).toBeInTheDocument();
            expect(interval).toHaveAttribute('href', '/health-safety/injuries');
            for (const name of [/Lost-time injury rate/, /Recordable injury rate/]) {
                const rate = screen.getByRole('link', { name });
                expect(within(rate).getByText('—')).toBeInTheDocument();
                expect(within(rate).getByText('Not confirmed')).toBeInTheDocument();
            }
            // A recorded zero stays a visible fact, not a folded "nothing" sentence.
            expect(
                within(screen.getByRole('link', { name: /Incidents/ })).getByText('0'),
            ).toBeInTheDocument();
            expect(screen.queryByText(CLEARANCE)).not.toBeInTheDocument();
        },
    );

    it('shows positive incidents and rates without granting the interval a safety status', () => {
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
        expect(screen.getByRole('link', { name: /Incidents/ })).toHaveTextContent('2');
        expect(
            screen.getByRole('link', { name: /Lost-time injury rate/ }),
        ).toHaveTextContent('1.5');
        expect(
            screen.getByRole('link', { name: /Recordable injury rate/ }),
        ).toHaveTextContent('3');
        expect(
            screen.getByRole('link', { name: /Days since a lost-time injury/ }),
        ).toHaveTextContent('60');
        expect(screen.queryByText('Not confirmed')).not.toBeInTheDocument();
        expect(screen.getAllByRole('link')).toHaveLength(4);
        expect(screen.getByRole('link', { name: /Incidents/ })).toHaveAttribute(
            'href',
            '/incidents?kind=incidents&tab=all',
        );
        expect(screen.queryByText(CLEARANCE)).not.toBeInTheDocument();
    });

    it('carries the incident kind, period and Site, and keeps injury history to the Site only', () => {
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
            screen.getByRole('link', { name: /Incidents/ }).getAttribute('href')!,
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
        for (const name of [
            /Lost-time injury rate/,
            /Recordable injury rate/,
            /Days since a lost-time injury/,
        ]) {
            expect(screen.getByRole('link', { name })).toHaveAttribute(
                'href',
                '/health-safety/injuries?site_id=3',
            );
        }
    });
});

describe('Prevention: Sites with a health and safety rep', () => {
    const empty = {
        near_miss_ratio: null,
        actions_on_time_pct: null,
        training_pct: null,
        open_hazards: 0,
    };

    it('shows readable coverage and opens representatives for the selected Site', () => {
        render(
            <LeadingPanel
                data={empty}
                workerParticipation={{
                    pct: 80,
                    committees: 2,
                    source_access: 'readable',
                    viewer_id: 7,
                }}
                filters={{ from: '2026-07-01', to: '2026-07-14', site: 3, lens: 'manager' }}
            />,
        );
        expect(
            screen.getByText('Sites with a health and safety rep — 80%'),
        ).toBeInTheDocument();
        expect(screen.getByText(/2 committees/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Open representatives/ }),
        ).toHaveAttribute(
            'href',
            '/health-safety/worker-participation?tab=representatives&site_id=3',
        );
    });

    it('shows a dash with a reason, not a zero, when readable coverage is missing', () => {
        render(
            <LeadingPanel
                data={empty}
                workerParticipation={{
                    pct: null,
                    committees: 0,
                    source_access: 'readable',
                    viewer_id: 7,
                }}
            />,
        );
        expect(screen.getByText('—')).toBeInTheDocument();
        expect(screen.getByText('Not confirmed')).toBeInTheDocument();
        expect(screen.queryByText(/0%/)).not.toBeInTheDocument();
    });

    it("does not treat another person's retained denial as this viewer's access", () => {
        render(
            <LeadingPanel
                data={empty}
                workerParticipation={{
                    pct: 97,
                    committees: 999,
                    source_access: 'restricted',
                    viewer_id: 8,
                }}
            />,
        );
        const card = screen
            .getByText('Sites with a health and safety rep')
            .closest('section')!;
        expect(card).toHaveTextContent('Not confirmed');
        expect(card).not.toHaveTextContent('Access restricted');
        expect(card).not.toHaveTextContent('97');
        expect(card).not.toHaveTextContent('999');
        expect(
            screen.queryByRole('link', { name: /Open representatives/ }),
        ).not.toBeInTheDocument();
    });

    it.each(['account', 'permission'] as const)(
        'clears retained coverage, committees and the link after a shared %s change',
        (change) => {
            const props = {
                data: empty,
                workerParticipation: {
                    pct: 97,
                    committees: 999,
                    source_access: 'readable' as const,
                    viewer_id: 7,
                },
            };
            const view = render(<LeadingPanel {...props} />);
            expect(
                screen.getByText('Sites with a health and safety rep — 97%'),
            ).toBeInTheDocument();
            if (change === 'account') access.id = 8;
            else access.participation = false;
            view.rerender(<LeadingPanel {...props} />);
            const card = screen
                .getByText('Sites with a health and safety rep')
                .closest('section')!;
            expect(card).not.toHaveTextContent('97');
            expect(card).not.toHaveTextContent('999');
            expect(card).toHaveTextContent(
                change === 'permission' ? 'Access restricted' : 'Not confirmed',
            );
            expect(
                screen.queryByRole('link', { name: /Open representatives/ }),
            ).not.toBeInTheDocument();
        },
    );

    it.each(['restricted', 'unavailable', undefined] as const)(
        'masks retained figures and the link when the source is %s',
        (source) => {
            render(
                <LeadingPanel
                    data={empty}
                    workerParticipation={{
                        pct: 97,
                        committees: 999,
                        source_access: source,
                        viewer_id: 7,
                    }}
                />,
            );
            const card = screen
                .getByText('Sites with a health and safety rep')
                .closest('section')!;
            expect(card).toHaveTextContent(
                source === 'restricted' ? 'Access restricted' : 'Not confirmed',
            );
            expect(card).not.toHaveTextContent('97');
            expect(card).not.toHaveTextContent('999');
            expect(
                screen.queryByRole('link', { name: /Open representatives/ }),
            ).not.toBeInTheDocument();
        },
    );
});

describe('Duties: work on record, never a compliance claim', () => {
    it('opens each register for the selected Site, without adding period dates', () => {
        render(<CompliancePanel site={3} expiring={[]} worksafePending={2} />);
        expect(
            screen.getByRole('link', { name: /^WorkSafe notifiable/ }),
        ).toHaveAttribute(
            'href',
            '/health-safety/events?tab=worksafe_pending&site_id=3',
        );
        expect(
            screen.getByRole('link', { name: /^Hazardous substances/ }),
        ).toHaveAttribute('href', '/health-safety/substances?site_id=3');
        expect(
            screen.getByRole('link', { name: /^Fire safety/ }),
        ).toHaveAttribute('href', '/health-safety/drills?site_id=3');
        expect(
            screen.getByRole('link', { name: /^First-aid cover/ }),
        ).toHaveAttribute('href', '/health-safety/first-aid?site_id=3');
    });

    it.each([null, 0])(
        'keeps unknown and empty notification work distinct without implying clearance (%s)',
        (pending) => {
            render(<CompliancePanel expiring={[]} worksafePending={pending} />);
            expect(screen.getAllByText('Not confirmed')).toHaveLength(
                pending === null ? 3 : 2,
            );
            if (pending === 0)
                expect(screen.getByText('None you can see')).toBeInTheDocument();
            expect(screen.getByText('No reviews in this preview')).toBeInTheDocument();
            expect(screen.getByText('No drills in this preview')).toBeInTheDocument();
            expect(
                screen.queryByText(
                    /^(Certified|Cover OK|SDS current|Drills current|Nothing waiting)$/,
                ),
            ).not.toBeInTheDocument();
            expect(
                screen.getByText('This shows work on record. None of it proves we meet the regulations.'),
            ).toBeInTheDocument();
            expect(
                screen.getByText('Certification evidence isn’t shown here'),
            ).toBeInTheDocument();
            expect(
                screen.getByText(/Doesn’t show who is on shift or whose first-aid certificate is current/),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('link', { name: /^WorkSafe notifiable/ }),
            ).toHaveAttribute('href', '/health-safety/events?tab=worksafe_pending');
            expect(
                screen.getByRole('link', { name: /^Hazardous substances/ }),
            ).toHaveAttribute('href', '/health-safety/substances');
        },
    );

    it('shows readable pending work and due previews without treating them as complete registers', () => {
        render(
            <CompliancePanel
                expiring={[{ type: 'sds' }, { type: 'sds' }, { type: 'drill' }, { type: 'other' }]}
                worksafePending={2}
            />,
        );
        expect(screen.getByText('2 not notified yet')).toBeInTheDocument();
        expect(screen.getByText('Safety data sheet review due')).toBeInTheDocument();
        expect(screen.getByText('Drill due')).toBeInTheDocument();
        expect(screen.getAllByText('Not confirmed')).toHaveLength(2);
        expect(
            screen.getByText('Not the full register, and not a compliance check · Open chemical register'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/^(Certified|Cover OK|SDS current|Drills current)$/),
        ).not.toBeInTheDocument();
    });
});
