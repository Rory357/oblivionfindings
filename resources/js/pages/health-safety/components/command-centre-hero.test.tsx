import { cleanup, render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { get: vi.fn() },
    usePage: () => ({
        props: { auth: { can: { governance: { view: false } } } },
    }),
}));

import { CommandCentreHero } from './command-centre-hero';

afterEach(cleanup);

describe('CommandCentreHero WorkSafe count', () => {
    it('uses the exact backbone pending count instead of the capped worklist rows', () => {
        const props = {
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
            procedures: {
                approved: 4,
                review_due: 0,
                coverage_gap_categories: 0,
            },
            orgName: 'Oblivion Care',
        } as unknown as ComponentProps<typeof CommandCentreHero>;

        render(<CommandCentreHero {...props} />);

        expect(
            screen.getByText('WorkSafe notifiable · 7 awaiting'),
        ).toBeInTheDocument();
        expect(screen.getByText('7 WorkSafe-notifiable')).toBeInTheDocument();
        expect(
            screen.queryByText('WorkSafe notifiable · 1 awaiting'),
        ).not.toBeInTheDocument();
    });
});
