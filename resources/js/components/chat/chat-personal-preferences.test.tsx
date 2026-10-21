import { Button } from '@/components/ui/button';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import {
    ChatPreferencesProvider,
    defaultChatPreferences,
    useChatPreferences,
    type ChatPreferencesSnapshot,
} from './chat-personal-preferences';

vi.mock('./api', async (original) => ({
    ...(await original<typeof import('./api')>()),
    chatRequest: vi.fn(),
}));
const snapshot = (actor = 1): ChatPreferencesSnapshot => ({
    actor_id: actor,
    version: 'a'.repeat(64),
    values: { ...defaultChatPreferences },
});
function Probe() {
    const preferences = useChatPreferences();
    return (
        <>
            <p>
                {preferences.loaded ? preferences.values.text_size : 'Loading'}
            </p>
            <p role="alert">{preferences.error}</p>
            <p>{preferences.values.sending}</p>
            <Button
                disabled={preferences.saving}
                onClick={() => void preferences.save({ text_size: 'large' })}
            >
                Large
            </Button>
            <Button onClick={() => void preferences.refresh()}>
                Review latest
            </Button>
        </>
    );
}
const view = (actor = 1) => (
    <ChatPreferencesProvider actorId={actor} enabled>
        <Probe />
    </ChatPreferencesProvider>
);
describe('personal Chat preferences account and save boundaries', () => {
    beforeEach(() => {
        vi.mocked(chatRequest).mockReset().mockResolvedValue(snapshot());
    });
    it('uses newlines while saved sending preferences are still unknown', async () => {
        let complete!: (value: ChatPreferencesSnapshot) => void;
        vi.mocked(chatRequest).mockImplementation(
            () =>
                new Promise((resolve) => {
                    complete = resolve;
                }),
        );
        render(view());
        expect(screen.getByText('enter_newline')).toBeInTheDocument();
        await act(async () => complete(snapshot()));
        expect(screen.getByText('enter_sends')).toBeInTheDocument();
    });
    it('binds reads and changes to the displayed actor and reviewed version', async () => {
        vi.mocked(chatRequest).mockImplementation(async (_, options) =>
            options?.method === 'PATCH'
                ? ({
                      ...snapshot(),
                      version: 'b'.repeat(64),
                      values: { ...defaultChatPreferences, text_size: 'large' },
                  } as never)
                : (snapshot() as never),
        );
        render(view());
        await screen.findByText('normal');
        fireEvent.click(screen.getByText('Large'));
        await screen.findByText('large');
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/personal-preferences',
            expect.objectContaining({
                method: 'PATCH',
                headers: { 'X-Chat-Actor': '1' },
                body: JSON.stringify({
                    version: 'a'.repeat(64),
                    values: { text_size: 'large' },
                }),
            }),
        );
    });
    it('keeps a failed change unconfirmed and prevents another write until a fresh review', async () => {
        let reads = 0;
        vi.mocked(chatRequest).mockImplementation(async (_, options) => {
            if (options?.method === 'PATCH')
                throw new ChatError('Changed in another window.', 409);
            reads++;
            return (
                reads > 1
                    ? {
                          ...snapshot(),
                          version: 'b'.repeat(64),
                          values: {
                              ...defaultChatPreferences,
                              text_size: 'extra_large',
                          },
                      }
                    : snapshot()
            ) as never;
        });
        render(view());
        await screen.findByText('normal');
        fireEvent.click(screen.getByText('Large'));
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'Review the latest',
            ),
        );
        fireEvent.click(screen.getByText('Large'));
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.filter(
                    ([, options]) => options?.method === 'PATCH',
                ),
        ).toHaveLength(1);
        fireEvent.click(screen.getByText('Review latest'));
        await screen.findByText('extra_large');
        expect(screen.getByRole('alert')).toBeEmptyDOMElement();
    });
    it('ignores an old account’s late write after the provider is replaced', async () => {
        let finish!: (value: ChatPreferencesSnapshot) => void;
        vi.mocked(chatRequest).mockImplementation(async (_, options) => {
            if (options?.method === 'PATCH')
                return new Promise((resolve) => {
                    finish = resolve;
                }) as never;
            return snapshot(
                Number(
                    (options?.headers as Record<string, string>)[
                        'X-Chat-Actor'
                    ],
                ),
            ) as never;
        });
        const mounted = render(view());
        await screen.findByText('normal');
        fireEvent.click(screen.getByText('Large'));
        mounted.rerender(view(2));
        await screen.findByText('normal');
        await act(async () =>
            finish({
                ...snapshot(),
                values: { ...defaultChatPreferences, text_size: 'large' },
            }),
        );
        expect(screen.queryByText('large')).not.toBeInTheDocument();
        expect(screen.getByText('normal')).toBeInTheDocument();
    });
    it('rejects a response for another actor without applying its preferences', async () => {
        vi.mocked(chatRequest).mockResolvedValue(snapshot(9));
        render(view());
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'could not be verified',
            ),
        );
        expect(screen.getByText('Loading')).toBeInTheDocument();
    });
    it('clears loaded settings when account access is rejected', async () => {
        render(view());
        await screen.findByText('normal');
        vi.mocked(chatRequest).mockRejectedValue(
            new ChatError('Account changed.', 403),
        );
        fireEvent.click(screen.getByText('Review latest'));
        await screen.findByText('Loading');
        expect(screen.queryByText('normal')).not.toBeInTheDocument();
    });
});
