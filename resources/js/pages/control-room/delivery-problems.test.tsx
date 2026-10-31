import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import DeliveryProblems from './delivery-problems';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    breadcrumbs: [] as Array<{ title: string; href?: string }>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    usePage: () => ({
        url: '/control-room/delivery-problems',
        props: { auth: { can: { controlRoom: { viewAny: true, alertsManage: true } } } },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs,
    }: {
        children: ReactNode;
        breadcrumbs: Array<{ title: string; href?: string }>;
    }) => {
        inertia.breadcrumbs = breadcrumbs;
        return <div>{children}</div>;
    },
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

const minutesAgo = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString();

type Props = ComponentProps<typeof DeliveryProblems>;
type Problem = Props['problems']['data'][number];

function problem(overrides: Partial<Problem> = {}): Problem {
    return {
        id: 1,
        broadcast_group_id: 'g-weather',
        message: 'Severe weather this afternoon.\nSecure outdoor furniture.',
        channel: 'sms',
        outcome: 'failed',
        reason: 'no_phone',
        detail: 'No phone number on record for the recipient.',
        sent_at: minutesAgo(60),
        failed_at: minutesAgo(60),
        person: { id: 10, name: 'Aroha Ngata', site: 'Aurora House' },
        reached_by: [],
        read_at: null,
        ...overrides,
    };
}

function props(overrides: Partial<Props> = {}): Props {
    return {
        problems: {
            data: [
                problem({
                    id: 2,
                    broadcast_group_id: 'g-drill',
                    message: 'Fire drill at 2 pm',
                    channel: 'email',
                    outcome: 'unconfirmed',
                    reason: null,
                    detail: null,
                    sent_at: minutesAgo(30),
                    failed_at: null,
                    person: { id: 11, name: 'Mere Tane', site: null },
                }),
                problem(),
                problem({
                    id: 3,
                    reason: 'sms_not_set_up',
                    detail: 'SMS provider is not configured.',
                    person: { id: 12, name: 'Jordan Peri', site: 'Aurora House' },
                    reached_by: ['in_app'],
                    read_at: minutesAgo(20),
                }),
            ],
            links: [],
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: 3,
            from: 1,
            to: 3,
        },
        stats: {
            failed: 2,
            unconfirmed: 1,
            people: 3,
            oldest: {
                broadcast_group_id: 'g-weather',
                message: 'Severe weather this afternoon.\nSecure outdoor furniture.',
                sent_at: minutesAgo(60),
            },
        },
        filters: { channel: null, outcome: null, period: '7d' },
        thresholdMinutes: 15,
        can: { viewStaff: true },
        ...overrides,
    };
}

function rowOf(name: string) {
    return screen.getByText(name).closest('tr') as HTMLElement;
}

describe('Communications › Delivery problems', () => {
    it('opens on the Delivery problems tab and says what "not confirmed" means', () => {
        render(<DeliveryProblems {...props()} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Communications' })).toBeInTheDocument();
        expect(inertia.breadcrumbs.map((crumb) => crumb.title)).toEqual([
            'Home',
            'Control Room',
            'Communications',
        ]);
        const rail = screen.getByRole('tablist', { name: 'Communications views' });
        expect(within(rail).getByRole('tab', { name: /Delivery problems/ })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(
            screen.getByText(/had no result 15 minutes after sending · newest first/),
        ).toBeInTheDocument();
        expect(screen.getByText(/A send that isn't confirmed may still arrive/)).toBeInTheDocument();
    });

    it('leads each row with the person and house, links the message and says what to do next', () => {
        render(<DeliveryProblems {...props()} />);

        const aroha = rowOf('Aroha Ngata');
        expect(within(aroha).getByText('Aurora House')).toBeInTheDocument();
        expect(
            within(aroha).getByRole('link', { name: 'Severe weather this afternoon.' }),
        ).toHaveAttribute('href', '/control-room/broadcast/g-weather?tab=recipients');
        expect(within(aroha).getByText('SMS')).toBeInTheDocument();
        expect(within(aroha).getByText('Not delivered')).toBeInTheDocument();
        expect(within(aroha).getByText('No mobile number on their record')).toBeInTheDocument();
        expect(within(aroha).getByText('sent 1 hour ago')).toBeInTheDocument();
        expect(
            within(aroha).getByText(
                'Add a mobile number to their record. Reach them another way for now.',
            ),
        ).toBeInTheDocument();
        expect(within(aroha).getByRole('link', { name: 'Open staff record' })).toHaveAttribute(
            'href',
            '/staff/10',
        );

        const mere = rowOf('Mere Tane');
        expect(within(mere).getByText('Not confirmed')).toBeInTheDocument();
        expect(within(mere).getByText('sent 30 min ago')).toBeInTheDocument();
        expect(
            within(mere).getByText('Check delivery on the broadcast before sending it again.'),
        ).toBeInTheDocument();

        // Reached another way and read: nothing more to do.
        const jordan = rowOf('Jordan Peri');
        expect(within(jordan).getByText("SMS isn't set up")).toBeInTheDocument();
        expect(
            within(jordan).getByText('They read it in the app 20 min ago. Nothing more needed.'),
        ).toBeInTheDocument();
    });

    it('header numbers filter the list, and Oldest opens its broadcast', () => {
        render(<DeliveryProblems {...props({ filters: { channel: 'sms', outcome: null, period: '7d' } })} />);

        fireEvent.click(screen.getByRole('button', { name: "Show sends that weren't delivered" }));
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/control-room/delivery-problems',
            { channel: 'sms', outcome: 'failed' },
            expect.any(Object),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Show sends with no result yet' }));
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/control-room/delivery-problems',
            { channel: 'sms', outcome: 'unconfirmed' },
            expect.any(Object),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Show every delivery problem' }));
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/control-room/delivery-problems',
            { channel: 'sms' },
            expect.any(Object),
        );
        expect(
            screen.getByRole('link', { name: 'Open the broadcast with the oldest problem' }),
        ).toHaveAttribute('href', '/control-room/broadcast/g-weather?tab=recipients');
    });

    it('rows keep the kebab and right-click with the same actions; staff record only with permission', () => {
        const { unmount } = render(<DeliveryProblems {...props()} />);
        const row = rowOf('Aroha Ngata');

        fireEvent.click(within(row).getByRole('button', { name: 'Actions for Aroha Ngata' }));
        const kebab = screen.getAllByRole('menuitem').map((item) => item.textContent);
        expect(kebab).toEqual(['Open broadcast', 'Open staff record', 'Copy message']);
        fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' });

        fireEvent.contextMenu(row);
        expect(screen.getAllByRole('menuitem').map((item) => item.textContent)).toEqual(kebab);
        unmount();

        render(<DeliveryProblems {...props({ can: { viewStaff: false } })} />);
        const without = rowOf('Aroha Ngata');
        expect(within(without).queryByRole('link', { name: 'Open staff record' })).toBeNull();
        fireEvent.contextMenu(without);
        expect(screen.getAllByRole('menuitem').map((item) => item.textContent)).toEqual([
            'Open broadcast',
            'Copy message',
        ]);
    });

    it('never offers a resend', () => {
        render(<DeliveryProblems {...props()} />);
        expect(screen.queryByRole('button', { name: /resend|send again|retry/i })).toBeNull();
    });

    it('says plainly when there are no problems, and offers clearing filters when filtered', () => {
        const empty = {
            problems: { ...props().problems, data: [], total: 0, from: null, to: null },
            stats: { failed: 0, unconfirmed: 0, people: 0, oldest: null },
        };
        const { unmount } = render(<DeliveryProblems {...props(empty)} />);
        expect(screen.getByText('No delivery problems')).toBeInTheDocument();
        expect(screen.queryByText(/may still arrive/)).toBeNull();
        expect(screen.queryByRole('link', { name: /oldest problem/ })).toBeNull();
        unmount();

        render(
            <DeliveryProblems
                {...props({ ...empty, filters: { channel: 'email', outcome: 'failed', period: '30d' } })}
            />,
        );
        expect(screen.getByText('Nothing matches these filters')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/control-room/delivery-problems',
            {},
            expect.any(Object),
        );
    });
});
