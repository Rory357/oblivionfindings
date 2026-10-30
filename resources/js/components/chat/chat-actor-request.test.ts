import { beforeEach, expect, it, vi } from 'vitest';
import { chatRequest } from './api';
import { chatActorRequest } from './chat-actor-request';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
beforeEach(() => {
    vi.mocked(chatRequest).mockReset();
});

it('pins the rendered actor without mutating caller headers or the exact retry body', async () => {
    const headers = new Headers({
        'X-Chat-Actor': '99',
        'X-Request-Id': 'retry-reference',
    });
    const body = new FormData();
    body.set('mutation_id', 'same-message-reference');
    const signal = new AbortController().signal;
    const progress = vi.fn();
    const response = { id: 301 };
    vi.mocked(chatRequest).mockResolvedValue(response);
    expect(
        await chatActorRequest(7, '/chat/api/conversations/71/messages', {
            headers,
            body,
            method: 'POST',
            signal,
            onUploadProgress: progress,
        }),
    ).toBe(response);
    const options = vi.mocked(chatRequest).mock.calls[0][1]!;
    expect(new Headers(options.headers).get('X-Chat-Actor')).toBe('7');
    expect(new Headers(options.headers).get('X-Request-Id')).toBe(
        'retry-reference',
    );
    expect(headers.get('X-Chat-Actor')).toBe('99');
    expect(options.body).toBe(body);
    expect(options.signal).toBe(signal);
    expect(options.onUploadProgress).toBe(progress);
});

it.each([0, -1, NaN, Infinity, 1.5, Number.MAX_SAFE_INTEGER + 1])(
    'rejects invalid actor %s before making a request',
    async (actor) => {
        await expect(
            chatActorRequest(actor, '/chat/api/conversations/71/messages'),
        ).rejects.toMatchObject({ status: 403, code: 'chat_actor_changed' });
        expect(chatRequest).not.toHaveBeenCalled();
    },
);
