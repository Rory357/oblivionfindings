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
                    read_count: 5,
                    read_of: 9,
                    initiated_by: { id: 1, name: 'Jordan Peri' },
                },
                {
                    broadcast_group_id: 'b-2',
                    content: 'Power restored at Kōwhai Lodge.',
                    channels: ['email'],
                    sent_at: '2026-10-23T08:00:00Z',
                    template_used: null,
                    total_recipients: 4,
                    delivered_count: 4,
                    failed_count: 0,
                    read_count: 0,
                    read_of: 0,
                    initiated_by: null,
                },
            ],
            links: [],
            current_page: 1,
            last_page: 1,
            per_page: 20,
            total: 2,
        },
        roles: ['support_worker', 'shift_lead'],
        roleCounts: { support_worker: 14, shift_lead: 4 },
        totalStaff: 18,
        can: { manage: true },
        ...overrides,
    };
}

describe('Communications › Broadcasts', () => {
    it('lists each broadcast with its audience, delivery and in-app reads kept apart', () => {
        render(<ControlRoomBroadcast {...listProps()} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Communications' })).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Read' })).toBeInTheDocument();
        const row = screen
            .getByRole('link', { name: /Severe weather this afternoon/ })
            .closest('tr')!;
        // 18 sends over 2 channels = 9 people.
        expect(within(row).getByText('9 people')).toBeInTheDocument();
        expect(within(row).getByText('In-app, SMS')).toBeInTheDocument();
        expect(within(row).getByText('Some not delivered')).toBeInTheDocument();
        expect(within(row).getByText('12 of 18 delivered')).toBeInTheDocument();
        expect(within(row).getByText('· 2 not delivered')).toBeInTheDocument();
        expect(within(row).getByText('5 of 9')).toBeInTheDocument();
        expect(within(row).getByText('By Jordan Peri · Severe weather template')).toBeInTheDocument();
        expect(screen.getByText(/Read means the person opened it, or marked it read, in the app/)).toBeInTheDocument();

        // No in-app copy, so nothing could be read.
        const email = screen.getByRole('link', { name: /Power restored/ }).closest('tr')!;
        expect(within(email).getByText('Not sent in-app')).toBeInTheDocument();
        expect(within(email).queryByText(/not delivered/)).not.toBeInTheDocument();

        // Acknowledgement isn't recorded for broadcasts, so it's never shown.
        expect(screen.queryByText(/acknowledged/i)).not.toBeInTheDocument();
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

const minutesAgo = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString();

function showProps(): ComponentProps<typeof BroadcastShow> {
    const at = '2026-10-24T08:00:00Z';
    const aroha = { id: 10, name: 'Aroha Ngata', email: 'aroha@example.test' };
    const mere = { id: 11, name: 'Mere Tane', email: 'mere@example.test' };
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
            read: 1,
            read_of: 2,
        },
        recipients: [
            { id: 1, channel: 'in_app', status: 'delivered', status_detail: null, sent_at: at, delivered_at: at, read_at: minutesAgo(5), read_tracked: true, target_user: aroha },
            { id: 2, channel: 'sms', status: 'failed', status_detail: 'Number not reachable', sent_at: at, delivered_at: null, read_at: null, read_tracked: false, target_user: aroha },
            { id: 3, channel: 'in_app', status: 'delivered', status_detail: null, sent_at: at, delivered_at: at, read_at: null, read_tracked: true, target_user: mere },
            { id: 4, channel: 'sms', status: 'sent', status_detail: null, sent_at: at, delivered_at: null, read_at: null, read_tracked: false, target_user: mere },
        ],
        history: [
            { kind: 'sent', at, by: 'Jordan Peri', channels: ['in_app', 'sms'], people: 2, force_delivery: false },
            { kind: 'delivered', channel: 'in_app', count: 2, at, last_at: at },
            {
                kind: 'failed',
                channel: 'sms',
                count: 1,
                at,
                last_at: at,
                reasons: [{ reason: 'no_phone', count: 1, detail: null }],
            },
            { kind: 'read', count: 1, of: 2, at: minutesAgo(5), last_at: minutesAgo(5), first_reader: 'Aroha Ngata' },
            { kind: 'waiting', channel: 'sms', count: 1, at },
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
        expect(screen.getByText('Read 1 of 2')).toBeInTheDocument();
        expect(screen.queryByText(/acknowledg/i)).not.toBeInTheDocument();
    });

    it('shows one row per person with each channel kept separate, and the numbers filter it', () => {
        render(<BroadcastShow {...showProps()} />);

        fireEvent.click(screen.getByRole('button', { name: "Show sends that weren't delivered" }));

        const table = screen.getByRole('table');
        expect(
            within(table)
                .getAllByRole('columnheader')
                .map((head) => head.textContent),
        ).toEqual(['Recipient', 'In-app', 'Read', 'SMS']);
        expect(within(table).getByText('Aroha Ngata')).toBeInTheDocument();
        expect(within(table).queryByText('Mere Tane')).not.toBeInTheDocument();
        expect(within(table).getByText('Number not reachable')).toBeInTheDocument();
        expect(within(table).getByText('Read 5 min ago')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: "Show sends that aren't confirmed yet" }));
        expect(within(screen.getByRole('table')).getByText('Mere Tane')).toBeInTheDocument();
        expect(within(screen.getByRole('table')).getByText('Sent, not confirmed')).toBeInTheDocument();
    });

    it('the Read number shows who has not read it in the app yet', () => {
        render(<BroadcastShow {...showProps()} />);

        // With reads recorded, Read replaces the People block (four numbers at most).
        expect(screen.queryByRole('button', { name: 'Show everyone this broadcast went to' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: "Show who hasn't read it yet" }));

        const table = screen.getByRole('table');
        expect(within(table).getByText('Mere Tane')).toBeInTheDocument();
        expect(within(table).queryByText('Aroha Ngata')).not.toBeInTheDocument();
        expect(within(table).getByText('Not yet')).toBeInTheDocument();
    });

    it('keeps the People number when no read is recorded', () => {
        const base = showProps();
        render(
            <BroadcastShow
                {...base}
                summary={{ ...base.summary, read: 0, read_of: 0 }}
                recipients={base.recipients.map((r) => ({ ...r, read_at: null, read_tracked: false }))}
            />,
        );

        expect(screen.getByRole('button', { name: 'Show everyone this broadcast went to' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: "Show who hasn't read it yet" })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Show everyone this broadcast went to' }));
        expect(
            within(screen.getByRole('table'))
                .getAllByRole('columnheader')
                .map((head) => head.textContent),
        ).toEqual(['Recipient', 'In-app', 'SMS']);
    });

    it('has a History tab built from what was recorded, with sends still waiting last', () => {
        render(<BroadcastShow {...showProps()} />);

        const rail = screen.getByRole('tablist', { name: 'Broadcast record' });
        fireEvent.click(within(rail).getByRole('tab', { name: /History/ }));

        const timeline = screen.getByRole('list', { name: 'Broadcast history' });
        const items = within(timeline).getAllByRole('listitem');
        expect(items.map((item) => item.querySelector('p')?.textContent)).toEqual([
            'Sent by Jordan Peri',
            'In-app: delivered to 2 people',
            'SMS: not delivered to 1 person',
            'Read in the app by 1 of 2',
            'SMS: 1 not confirmed yet',
        ]);
        expect(within(items[0]).getByText('To 2 people you can see · by In-app, SMS')).toBeInTheDocument();
        expect(within(items[2]).getByText('No mobile number on their record')).toBeInTheDocument();
        expect(within(items[3]).getByText('First: Aroha Ngata')).toBeInTheDocument();
        expect(within(items[4]).getByText('Now')).toBeInTheDocument();
        expect(window.location.search).toBe('?tab=history');
    });
});
