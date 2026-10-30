import { fireEvent, render, screen, within } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MessagesChat from './Index';

// Staff messages: Delete in a message's right-click menu must ask first.
// Nothing reaches the server until the confirm button, and the request is
// the same DELETE as before.

type VisitOptions = { onStart?: () => void; onFinish?: () => void };

const router = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn((_url: string, options?: VisitOptions) => {
        options?.onStart?.();
        options?.onFinish?.();
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { labels: {} } }),
    router,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageHero: () => null,
    PageLayout: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

const conversation = {
    id: 5,
    title: 'Rimu House team',
    conversation_type: 'group',
    client: null,
    latest_message: null,
    unread_count: 0,
    participants: [
        { user: { id: 3, name: 'Sam Worker' } },
        { user: { id: 8, name: 'Ana Lead' } },
    ],
};

const renderChat = () =>
    render(
        <MessagesChat
            conversations={[conversation]}
            users={[]}
            currentUserId={3}
            conversation={conversation}
            messages={{
                data: [
                    {
                        id: 77,
                        content: 'See you at handover',
                        created_at: '2026-10-20T01:00:00Z',
                        sender: { id: 3, name: 'Sam Worker' },
                        sender_id: 3,
                        message_type: 'text',
                    },
                ],
            }}
            pinnedMessages={[]}
        />,
    );

const openDeleteFromMenu = () => {
    fireEvent.contextMenu(screen.getByText('See you at handover'));
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
};

const dialog = () => screen.getByRole('alertdialog');

beforeEach(() => {
    router.delete.mockClear();
});

describe('Messages › delete own message', () => {
    it('asks before deleting, says what everyone will see, and Cancel deletes nothing', () => {
        renderChat();
        openDeleteFromMenu();

        expect(router.delete).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Delete this message?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'Everyone in this conversation will see “This message was deleted” in its place.',
        );

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.delete).not.toHaveBeenCalled();
    });

    it('deletes the chosen message once confirmed', () => {
        renderChat();
        openDeleteFromMenu();

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Delete message' }),
        );

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete).toHaveBeenCalledWith(
            '/operations/messages/archive/77',
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
