import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ControlRoomMessaging from './messaging';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    url: '/control-room/messaging',
    chat: false,
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
        props: {
            chatSharedExperience: inertia.chat,
            auth: { can: { controlRoom: { viewAny: true, alertsManage: true } } },
        },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/chat/chat-launcher', () => ({
    ChatLauncher: ({ label }: { label: string }) => <span>{label}</span>,
}));

const fetchMock = vi.fn();

beforeEach(() => {
    fetchMock.mockResolvedValue({
        ok: true,
        json: async () => ({
            messages: [
                {
                    id: 5,
                    direction: 'inbound',
                    content: 'Front gate is open. Garden clear.',
                    sender_name: 'Mere Tane',
                    sent_at: '2026-10-24T08:00:00Z',
                    delivered_at: '2026-10-24T08:01:00Z',
                    status: 'sent',
                },
            ],
        }),
    });
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    vi.unstubAllGlobals();
    inertia.chat = false;
});

function props(
    overrides: Partial<ComponentProps<typeof ControlRoomMessaging>> = {},
): ComponentProps<typeof ControlRoomMessaging> {
    return {
        threads: [
            {
                id: 'alert-418',
                type: 'alert',
                alert_id: 418,
                user_id: null,
                title: 'Geofence breach #418',
                last_message: 'Front gate is open. Garden clear.',
                last_message_at: '2026-10-24T08:00:00Z',
                unread_count: 2,
                message_count: 3,
            },
            {
                id: 'user-12',
                type: 'direct',
                alert_id: null,
                user_id: 12,
                title: 'Rāwiri Kingi',
                last_message: 'On my way to Aurora House now',
                last_message_at: '2026-10-24T07:50:00Z',
                unread_count: 0,
                message_count: 1,
            },
        ],
        staff: [
            { id: 11, name: 'Mere Tane' },
            { id: 12, name: 'Rāwiri Kingi' },
        ],
        can: { manage: true },
        ...overrides,
    };
}

describe('Communications › Conversations', () => {
    it('opens on the Communications header with numbers that filter the list', () => {
        render(<ControlRoomMessaging {...props()} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Communications' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /^Geofence breach #418/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /^Rāwiri Kingi/ })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Show alert-linked' }));
        expect(screen.getByRole('button', { name: /^Geofence breach #418/ })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Rāwiri Kingi/ })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Show direct' }));
        expect(screen.queryByRole('button', { name: /^Geofence breach #418/ })).not.toBeInTheDocument();
    });

    it('links to the shared Chat only when it is switched on', () => {
        const { unmount } = render(<ControlRoomMessaging {...props()} />);
        expect(screen.queryByRole('link', { name: 'Open Chat' })).not.toBeInTheDocument();
        unmount();

        inertia.chat = true;
        render(<ControlRoomMessaging {...props()} />);
        expect(screen.getByRole('link', { name: 'Open Chat' })).toHaveAttribute('href', '/chat');
    });

    it('opens an alert-linked conversation and asks who to send to before sending', async () => {
        render(<ControlRoomMessaging {...props()} />);

        fireEvent.click(screen.getByRole('button', { name: /^Geofence breach #418/ }));

        expect(await screen.findByText('Front gate is open. Garden clear.', { selector: 'p' })).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledWith(
            '/control-room/messaging/thread?alert_id=418',
            expect.anything(),
        );
        expect(screen.getByRole('link', { name: 'Open alert #418' })).toHaveAttribute(
            'href',
            '/control-room/alerts/418',
        );
        expect(screen.getByRole('combobox', { name: 'Send to' })).toBeInTheDocument();
        fireEvent.change(screen.getByRole('textbox', { name: 'Message' }), {
            target: { value: 'Thanks, on my way' },
        });
        expect(screen.getByRole('button', { name: 'Send' })).toBeDisabled();
    });

    it('keeps read-only staff out of sending and starting conversations', async () => {
        render(<ControlRoomMessaging {...props({ can: { manage: false } })} />);

        expect(screen.queryByRole('button', { name: 'New conversation' })).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /^Rāwiri Kingi/ }));
        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(screen.queryByRole('textbox', { name: 'Message' })).not.toBeInTheDocument();
        expect(screen.getByText(/Sending messages needs Control Room manage access/)).toBeInTheDocument();
    });

    it('starts a new conversation in a kit pop-up that needs a staff member', () => {
        render(<ControlRoomMessaging {...props()} />);

        fireEvent.click(screen.getByRole('button', { name: 'New conversation' }));

        const dialog = screen.getByRole('dialog', { name: 'New conversation' });
        expect(within(dialog).getByRole('button', { name: 'Start conversation' })).toBeDisabled();
        fireEvent.change(within(dialog).getByLabelText('Link to an alert'), {
            target: { value: 'abc' },
        });
        expect(within(dialog).getByText('Enter the alert number, for example 418.')).toBeInTheDocument();
    });
});
