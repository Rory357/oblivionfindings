import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import ControlRoomBroadcast from './broadcast';
import BroadcastShow from './broadcast-show';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    url: '/control-room/broadcast',
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
        url: inertia.url,
        props: { auth: { can: { controlRoom: { viewAny: true, alertsManage: true } } } },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    window.history.replaceState(null, '', '/');
});

function listProps(
    overrides: Partial<ComponentProps<typeof ControlRoomBroadcast>> = {},
): ComponentProps<typeof ControlRoomBroadcast> {
    return {
        broadcasts: {
            data: [
                {
                    broadcast_group_id: 'b-1',
                    content: 'Severe weather this afternoon. Secure outdoor furniture.',
                    channels: ['in_app', 'sms'],
                    sent_at: '2026-10-24T08:00:00Z',
                    template_used: 'severe_weather',
                    total_recipients: 18,
                    delivered_count: 12,
                    failed_count: 2,
                    initiated_by: { id: 1, name: 'Jordan Peri' },
                },
            ],
            links: [],
            current_page: 1,
            last_page: 1,
            per_page: 20,
            total: 1,
        },
        roles: ['support_worker', 'shift_lead'],
        roleCounts: { support_worker: 14, shift_lead: 4 },
        totalStaff: 18,
        can: { manage: true },
        ...overrides,
    };
}

describe('Communications › Broadcasts', () => {
    it('lists each broadcast with its audience and delivery kept apart from reading', () => {
        render(<ControlRoomBroadcast {...listProps()} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Communications' })).toBeInTheDocument();
        const row = screen
            .getByRole('link', { name: /Severe weather this afternoon/ })
            .closest('tr')!;
        // 18 sends over 2 channels = 9 people.
        expect(within(row).getByText('9 people')).toBeInTheDocument();
        expect(within(row).getByText('In-app, SMS')).toBeInTheDocument();
        expect(within(row).getByText('12 of 18')).toBeInTheDocument();
        expect(within(row).getByText('Some not delivered')).toBeInTheDocument();
        expect(within(row).getByText('By Jordan Peri · Severe weather template')).toBeInTheDocument();
        expect(screen.getByText(/doesn't mean the person has read it/)).toBeInTheDocument();
        expect(screen.queryByText(/read by|acknowledged/i)).not.toBeInTheDocument();
    });

    it('offers the same row actions on the kebab and right-click', () => {
        render(<ControlRoomBroadcast {...listProps()} />);
        const row = screen
            .getByRole('link', { name: /Severe weather this afternoon/ })
            .closest('tr')!;

        fireEvent.click(within(row).getByRole('button', { name: 'Actions for this broadcast' }));
        const kebab = screen.getAllByRole('menuitem').map((item) => item.textContent);
        expect(kebab).toEqual(['Open broadcast', 'Copy message']);
        fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' });

        fireEvent.contextMenu(row);
        expect(screen.getAllByRole('menuitem').map((item) => item.textContent)).toEqual(kebab);
    });

    it('only lets managers start a new broadcast, which opens the review wizard', () => {
        const { unmount } = render(<ControlRoomBroadcast {...listProps({ can: { manage: false } })} />);
        expect(screen.queryByRole('button', { name: 'New broadcast' })).not.toBeInTheDocument();
        unmount();

        render(<ControlRoomBroadcast {...listProps()} />);
        fireEvent.click(screen.getByRole('button', { name: 'New broadcast' }));
        expect(screen.getByText('What do staff need to know?')).toBeInTheDocument();
    });
});

function showProps(): ComponentProps<typeof BroadcastShow> {
    const at = '2026-10-24T08:00:00Z';
    return {
        summary: {
            broadcast_group_id: 'b-1',
            content: 'Severe weather this afternoon.\nSecure outdoor furniture.',
            template_used: 'severe_weather',
            sent_at: at,
            channels: ['in_app', 'sms'],
            total: 4,
            delivered: 2,
            sent: 1,
            pending: 0,
            failed: 1,
        },
        recipients: [
            { id: 1, channel: 'in_app', status: 'delivered', status_detail: null, sent_at: at, delivered_at: at, target_user: { id: 10, name: 'Aroha Ngata', email: 'aroha@example.test' } },
            { id: 2, channel: 'sms', status: 'failed', status_detail: 'Number not reachable', sent_at: at, delivered_at: null, target_user: { id: 10, name: 'Aroha Ngata', email: 'aroha@example.test' } },
            { id: 3, channel: 'in_app', status: 'delivered', status_detail: null, sent_at: at, delivered_at: at, target_user: { id: 11, name: 'Mere Tane', email: 'mere@example.test' } },
            { id: 4, channel: 'sms', status: 'sent', status_detail: null, sent_at: at, delivered_at: null, target_user: { id: 11, name: 'Mere Tane', email: 'mere@example.test' } },
        ],
    };
}

describe('Communications › one broadcast', () => {
    it('opens on the message, with the record header and a warning not to resend blindly', () => {
        render(<BroadcastShow {...showProps()} />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'Severe weather this afternoon.' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Back' })).toHaveAttribute('href', '/control-room/broadcast');
        expect(screen.getByText(/1 send was not delivered/)).toBeInTheDocument();
        expect(screen.getByText(/reading this page doesn't resend anything/)).toBeInTheDocument();
        expect(screen.getByText('Delivery by channel')).toBeInTheDocument();
    });

    it('shows one row per person with each channel kept separate, and the numbers filter it', () => {
        render(<BroadcastShow {...showProps()} />);

        fireEvent.click(screen.getByRole('button', { name: "Show sends that weren't delivered" }));

        const table = screen.getByRole('table');
        expect(within(table).getByRole('columnheader', { name: 'In-app' })).toBeInTheDocument();
        expect(within(table).getByRole('columnheader', { name: 'SMS' })).toBeInTheDocument();
        expect(within(table).getByText('Aroha Ngata')).toBeInTheDocument();
        expect(within(table).queryByText('Mere Tane')).not.toBeInTheDocument();
        expect(within(table).getByText('Number not reachable')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: "Show sends that aren't confirmed yet" }));
        expect(within(screen.getByRole('table')).getByText('Mere Tane')).toBeInTheDocument();
        expect(within(screen.getByRole('table')).getByText('Sent, not confirmed')).toBeInTheDocument();
    });
});
