import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { OnCallTab } from './_on-call';
import OnCallHandoversPage from './handovers';
import type { OnCall, OnCallHandoversProps } from './_types';

const routerGet = vi.fn();
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        Link: ({
            href,
            children,
            ...rest
        }: {
            href: string;
            children: React.ReactNode;
        }) => (
            <a href={href} {...rest}>
                {children}
            </a>
        ),
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: {
            ...actual.router,
            get: (...args: unknown[]) => routerGet(...args),
            visit: vi.fn(),
            reload: vi.fn(),
            on: () => () => undefined,
        },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

function onCallFixture(): OnCall {
    return {
        now: {
            name: 'Jordan Peri',
            first_name: 'Jordan',
            until: '2026-11-08T20:00:00Z',
        },
        people: [
            {
                id: 1,
                name: 'Jordan Peri',
                role: 'IT and facilities lead',
                when: 'now',
                from: '2026-11-01T20:00:00Z',
                to: '2026-11-08T20:00:00Z',
                days: [
                    { label: 'Mon', state: 'done' },
                    { label: 'Tue', state: 'done' },
                    { label: 'Wed', state: 'done' },
                    { label: 'Thu', state: 'now' },
                    { label: 'Fri', state: 'upcoming' },
                    { label: 'Sat', state: 'upcoming' },
                    { label: 'Sun', state: 'upcoming' },
                ],
            },
            {
                id: 2,
                name: 'Sam Tuilagi',
                role: 'IT support',
                when: 'next',
                from: '2026-11-08T20:00:00Z',
                to: '2026-11-15T20:00:00Z',
                days: null,
            },
            {
                id: 3,
                name: 'Ana Ruha',
                role: 'Operations manager',
                when: 'backup',
                from: '2026-11-02T20:00:00Z',
                to: '2026-11-08T20:00:00Z',
                days: null,
            },
        ],
        role: 'IT admin',
        handover: {
            from: 'Sam Tuilagi',
            to: 'Jordan Peri',
            at: '2026-11-01T20:00:00Z',
            still_open: 'Kauri House router dropped twice last week.',
            coming_up: 'Firewall update Saturday 6:00 am.',
            good_to_know: 'Spare SD card in the head office cupboard.',
        },
        handover_count: 2,
        waiting: [],
        summary: null,
        delivery: [
            {
                key: 'devices',
                when: 'A device or hub stops answering',
                sub: 'Or a check fails',
                app: true,
                email: true,
                push: false,
            },
        ],
        can: { rostering: true, handover: true, setup: true },
    };
}

describe('Alerts > On call extras (13d)', () => {
    afterEach(() => {
        cleanup();
        routerGet.mockReset();
    });

    it('renders the week day-strip, backup-person card, and All handovers link', () => {
        render(
            <OnCallTab
                data={onCallFixture()}
                now={new Date('2026-11-05T00:00:00Z')}
            />,
        );

        const strip = screen.getByLabelText('On-call week');
        expect(within(strip).getByText('Mon')).toHaveClass('sg-day--done');
        expect(within(strip).getByText('Thu')).toHaveClass('sg-day--now');
        expect(within(strip).getByText('Sun')).not.toHaveClass('sg-day--done');

        expect(
            screen.getByText('Operations manager · backup'),
        ).toBeInTheDocument();
        expect(screen.getByText('Told after an hour')).toBeInTheDocument();

        const allLink = screen.getByRole('link', { name: 'All handovers' });
        expect(allLink).toHaveAttribute(
            'href',
            '/security-devices/alerts/on-call/handovers',
        );
    });

    it('renders the read-only All handovers page with notes and back link', () => {
        const props: OnCallHandoversProps = {
            rows: [
                {
                    id: 12,
                    from: 'Jordan Peri',
                    to: 'Sam Tuilagi',
                    at: '2026-11-04T20:00:00Z',
                    still_open: 'Switch port 8 flapping at Kauri House.',
                    coming_up: null,
                    good_to_know: 'Spare switch on shelf B.',
                },
                {
                    id: 11,
                    from: 'Sam Tuilagi',
                    to: 'Jordan Peri',
                    at: '2026-10-28T20:00:00Z',
                    still_open: null,
                    coming_up: 'Monthly router backup check.',
                    good_to_know: null,
                },
            ],
            meta: { total: 2, page: 1, last_page: 1, from: 1, to: 2 },
            q: '',
        };

        render(<OnCallHandoversPage {...props} />);

        expect(
            screen.getByRole('heading', { name: 'Handover notes' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('From Jordan Peri to Sam Tuilagi'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Switch port 8 flapping at Kauri House.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Monthly router backup check.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Back to On call/i }),
        ).toHaveAttribute('href', '/security-devices/alerts?tab=oncall');
    });
});
