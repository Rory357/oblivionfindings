import { fireEvent, render, screen, within } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PortalMessages from './messages';

// Family portal messages: Delete in a message's right-click menu must ask
// first. Nothing reaches the server until the confirm button, and the
// request is the same DELETE as before.

type VisitOptions = { onStart?: () => void; onFinish?: () => void };

const router = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    visit: vi.fn(),
    delete: vi.fn((_url: string, options?: VisitOptions) => {
        options?.onStart?.();
        options?.onFinish?.();
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: {} }),
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

const renderChat = () =>
    render(
        <PortalMessages
            client={{ id: 4, first_name: 'Mere', last_name: 'Tane' }}
            conversations={[
                {
                    id: 6,
                    title: 'Mere’s family',
                    participants: [{ id: 21, name: 'Hine Tane' }],
                    updated_at: '2026-10-20T01:00:00Z',
                },
            ]}
            supportWorkers={[]}
            currentUserId={21}
            activeConversation={{
                id: 6,
                title: 'Mere’s family',
                participants: [{ id: 21, name: 'Hine Tane' }],
            }}
            activeMessages={[
                {
                    id: 88,
                    content: 'Can Mere call me tonight?',
                    sender_id: 21,
                    sender_type: 'family',
                    sender: { id: 21, name: 'Hine Tane' },
                    created_at: '2026-10-20T01:00:00Z',
                },
            ]}
            pinnedMessages={[]}
        />,
    );

const openDeleteFromMenu = () => {
    fireEvent.contextMenu(screen.getByText('Can Mere call me tonight?'));
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
};

const dialog = () => screen.getByRole('alertdialog');

beforeEach(() => {
    router.delete.mockClear();
});

describe('Family portal › delete own message', () => {
    it('asks before deleting in plain words, and Cancel deletes nothing', () => {
        renderChat();
        openDeleteFromMenu();

        expect(router.delete).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Delete this message?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'Everyone in this chat will see “This message was deleted” instead.',
        );
        expect(dialog()).not.toHaveTextContent(/audit/i);

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
            '/portal/clients/4/messages/archive/88',
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
