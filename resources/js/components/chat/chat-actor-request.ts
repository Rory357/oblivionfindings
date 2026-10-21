import { ChatError, chatRequest, type ChatRequestOptions } from './api';

/** Bind this request to the account that rendered its controls, never a mutable global. */
export async function chatActorRequest<T>(
    actorId: number,
    path: string,
    options: ChatRequestOptions = {},
): Promise<T> {
    if (!Number.isSafeInteger(actorId) || actorId <= 0)
        throw new ChatError(
            'Your signed-in account could not be verified. Refresh this page before continuing.',
            403,
            'chat_actor_changed',
        );
    const headers = new Headers(options.headers);
    headers.set('X-Chat-Actor', String(actorId));
    return chatRequest<T>(path, { ...options, headers });
}
