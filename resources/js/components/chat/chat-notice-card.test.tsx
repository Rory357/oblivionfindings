import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { chatRequest } from './api';
import { ChatNoticeCard, ChatNoticePreviews } from './chat-notice-card';

const preferences = vi.hoisted(() => ({
    loaded: true,
    values: { show_previews: true },
}));
vi.mock('./chat-personal-preferences', () => ({
    useChatPreferences: () => preferences,
}));
vi.mock('./api', () => ({ chatRequest: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));
const id = '11111111-1111-4111-8111-111111111111';
const second = '22222222-2222-4222-8222-222222222222';
const card = (key = id, mark = vi.fn()) => (
    <ChatNoticeCard
        key={key}
        id={key}
        createdAt="2026-10-18T08:00:00Z"
        unread
        onMarkRead={mark}
    />
);
const view = (actorId = 1, ids = [id]) => (
    <ChatNoticePreviews actorId={actorId} notificationIds={ids}>
        {ids.map((key) => card(key))}
    </ChatNoticePreviews>
);
describe('shared in-app Chat notification cards', () => {
    it('identifies a missed call and opens its opaque history link without exposing caller details', () => {
        render(
            <ChatNoticeCard
                id={id}
                eventKey="chat.call.missed"
                createdAt="2026-10-18T08:00:00Z"
                unread
                onMarkRead={vi.fn()}
            />,
        );
        expect(screen.getByRole('article')).toHaveAccessibleName(
            'Missed Chat call',
        );
        expect(
            screen.getByText('Open Chat to review your missed calls.'),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open Chat' })).toHaveAttribute(
            'href',
            `/chat/notifications/${id}`,
        );
    });
    beforeEach(() => {
        preferences.loaded = true;
        preferences.values.show_previews = true;
        vi.mocked(chatRequest)
            .mockReset()
            .mockResolvedValue({
                actor_id: 1,
                previews: { [id]: 'Rohan: Current authorised message' },
            });
    });
    it('loads one actor-bound batch and provides a canonical deep link without marking anything read', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            actor_id: 1,
            previews: {
                [id]: 'First current message',
                [second]: 'Second current message',
            },
        });
        render(view(1, [id, second]));
        await screen.findByText('First current message');
        expect(screen.getByText('Second current message')).toBeInTheDocument();
        expect(chatRequest).toHaveBeenCalledTimes(1);
        const [path, options] = vi.mocked(chatRequest).mock.calls[0];
        expect(
            new URL(path, 'https://example.test').searchParams.getAll('ids[]'),
        ).toEqual([id, second]);
        expect(options?.headers).toEqual({ 'X-Chat-Actor': '1' });
        expect(
            screen.getAllByRole('link', { name: 'Open Chat' })[0],
        ).toHaveAttribute('href', `/chat/notifications/${id}`);
    });
    it('does not fetch private text while preferences are unknown or previews are off', async () => {
        preferences.loaded = false;
        const mounted = render(view());
        expect(chatRequest).not.toHaveBeenCalled();
        preferences.loaded = true;
        preferences.values.show_previews = false;
        mounted.rerender(view());
        expect(chatRequest).not.toHaveBeenCalled();
        preferences.values.show_previews = true;
        mounted.rerender(view());
        await screen.findByText('Rohan: Current authorised message');
        preferences.values.show_previews = false;
        mounted.rerender(view());
        expect(
            screen.queryByText('Rohan: Current authorised message'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Open Chat to read the message.'),
        ).toBeInTheDocument();
    });
    it('clears old text after failed or revoked revalidation', async () => {
        render(view());
        await screen.findByText('Rohan: Current authorised message');
        vi.mocked(chatRequest).mockRejectedValue(new Error('Access changed'));
        act(() => window.dispatchEvent(new Event('oblivion:chat-refresh')));
        await waitFor(() =>
            expect(
                screen.queryByText('Rohan: Current authorised message'),
            ).not.toBeInTheDocument(),
        );
        expect(
            screen.getByText('Open Chat to read the message.'),
        ).toBeInTheDocument();
    });
    it('ignores a previous account’s late preview', async () => {
        let finish!: (data: unknown) => void;
        vi.mocked(chatRequest).mockImplementation(async (_, options) =>
            (options?.headers as Record<string, string>)['X-Chat-Actor'] === '1'
                ? new Promise((resolve) => {
                      finish = resolve;
                  })
                : ({ actor_id: 2, previews: { [id]: null } } as never),
        );
        const mounted = render(view());
        mounted.rerender(view(2));
        await act(async () =>
            finish({
                actor_id: 1,
                previews: { [id]: 'Previous account private content' },
            }),
        );
        expect(
            screen.queryByText('Previous account private content'),
        ).not.toBeInTheDocument();
    });
    it('discards a response for another actor', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            actor_id: 9,
            previews: { [id]: 'Wrong account content' },
        });
        render(view());
        await waitFor(() => expect(chatRequest).toHaveBeenCalled());
        expect(
            screen.queryByText('Wrong account content'),
        ).not.toBeInTheDocument();
    });
    it('clears hidden text and waits for fresh permission before showing it on return', async () => {
        const visibility = vi.spyOn(document, 'visibilityState', 'get');
        try {
            render(view());
            await screen.findByText('Rohan: Current authorised message');
            visibility.mockReturnValue('hidden');
            act(() => document.dispatchEvent(new Event('visibilitychange')));
            expect(
                screen.queryByText('Rohan: Current authorised message'),
            ).not.toBeInTheDocument();
            vi.mocked(chatRequest).mockResolvedValue({
                actor_id: 1,
                previews: { [id]: null },
            });
            visibility.mockReturnValue('visible');
            act(() => document.dispatchEvent(new Event('visibilitychange')));
            await waitFor(() => expect(chatRequest).toHaveBeenCalledTimes(2));
            expect(
                screen.queryByText('Rohan: Current authorised message'),
            ).not.toBeInTheDocument();
            expect(
                screen.getByText('Open Chat to read the message.'),
            ).toBeVisible();
        } finally {
            visibility.mockRestore();
        }
    });
    it('marks a notification read only through the separate explicit control', () => {
        const mark = vi.fn();
        render(card(id, mark));
        expect(mark).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Mark as read' }));
        expect(mark).toHaveBeenCalledTimes(1);
    });
});
