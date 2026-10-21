import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import { acquireCallBrowserLease } from './call-recovery-journal';
import { createCallTestLocks } from './call-recovery-test-locks';
import type {
    PrivateDraftSnapshot,
    PrivateSendAttempt,
    PrivateSendBody,
} from './chat-private-draft';
import {
    ChatSendJournal,
    chatSendJournalKey,
    parseChatSendReference,
    type ChatSendReference,
} from './chat-send-journal';
import { ChatSendSession } from './chat-send-session';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
const scope = { actor_id: 11, conversation_id: 71, client_id: null };
const saved: PrivateDraftSnapshot = {
    ...scope,
    boundary: 'a'.repeat(64),
    version: 1,
    state: 'saved',
    updated_at: '2026-10-18 04:00:00',
    pending_send: null,
    draft: {
        content: 'Fictional private draft',
        reply_to_id: null,
        mentions: [],
        uploads: [],
    },
};
function memory() {
    const values = new Map<string, string>();
    return {
        values,
        getItem: (key: string) => values.get(key) ?? null,
        setItem: (key: string, value: string) => {
            values.set(key, value);
        },
        removeItem: (key: string) => {
            values.delete(key);
        },
    };
}
const sessions: ChatSendSession[] = [];
function setup(storage = memory(), locks = createCallTestLocks()) {
    const journal = new ChatSendJournal(
        storage,
        scope.actor_id,
        scope.conversation_id,
    );
    const session = new ChatSendSession(scope, journal, locks);
    sessions.push(session);
    return { session, journal, storage, locks };
}
function outcome(
    mutation: string,
    state: PrivateSendAttempt['state'] = 'pending',
    body?: PrivateSendBody,
): PrivateSendAttempt {
    return {
        actor_id: 11,
        conversation_id: 71,
        mutation_id: mutation,
        state,
        can_retry: state === 'pending',
        message_id: state === 'confirmed' ? 101 : null,
        body:
            state === 'pending'
                ? (body ?? {
                      recoverable_send: true,
                      mutation_id: mutation,
                      draft_version: 1,
                      draft_boundary: saved.boundary,
                      audience_token: null,
                      content: saved.draft!.content,
                      reply_to_id: null,
                      mentions: [],
                      upload_ids: [],
                      captions: [],
                  })
                : null,
    };
}
async function prepared() {
    const context = setup();
    vi.mocked(chatRequest).mockImplementationOnce(async (_path, options) =>
        outcome(JSON.parse(options!.body as string).mutation_id),
    );
    await context.session.prepare(saved, null);
    const mutation = context.session.snapshot().reference!.mutation;
    return { ...context, mutation };
}
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}
describe('message send recovery ownership and explicit intent', () => {
    beforeEach(() => vi.resetAllMocks());
    afterEach(() => {
        sessions.splice(0).forEach((session) => session.dispose());
    });
    it('reports an already committed send when cancellation races its lost acknowledgement', async () => {
        const { session, journal, mutation } = await prepared();
        const delayed = deferred<unknown>();
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(outcome(mutation))
            .mockReturnValueOnce(delayed.promise);
        const sending = session.send();
        await vi.waitFor(() =>
            expect(session.snapshot().phase).toBe('sending'),
        );
        vi.mocked(chatRequest).mockResolvedValueOnce(
            outcome(mutation, 'confirmed'),
        );
        await session.cancel();
        delayed.resolve({ message: { id: 101 } });
        await sending;
        expect(session.snapshot().phase).toBe('confirmed');
        expect(journal.read().status).toBe('empty');
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.filter(([path]) => path.endsWith('/messages')),
        ).toHaveLength(1);
        expect(chatRequest).toHaveBeenCalledTimes(4);
    });
    it('keeps only allowlisted IDs, refuses stale replacement and never reverses cancellation', () => {
        const { journal, storage } = setup();
        const record: ChatSendReference = {
            version: 1,
            actor: 11,
            conversation: 71,
            mutation: '221c1f32-6747-412b-abeb-71ec1e485a9c',
            cancelling: false,
        };
        expect(
            parseChatSendReference(
                { ...record, content: 'Private text' },
                11,
                71,
            ),
        ).toBeNull();
        expect(parseChatSendReference(record, 22, 71)).toBeNull();
        expect(journal.write(record, null)).toBe(true);
        const stop = { ...record, cancelling: true };
        expect(journal.write(stop, record)).toBe(true);
        expect(journal.write(record, stop)).toBe(false);
        expect(journal.clear(record)).toBe(false);
        expect(journal.clear(stop)).toBe(true);
        storage.setItem(chatSendJournalKey(11, 71), '{broken');
        expect(journal.read().status).toBe('unavailable');
        expect(journal.write(record, null)).toBe(false);
    });
    it('prepares without sending, checks before explicit send and clears only a confirmed result', async () => {
        const { session, storage, journal, mutation } = await prepared();
        expect(session.snapshot().phase).toBe('ready');
        expect(chatRequest).toHaveBeenCalledTimes(1);
        expect([...storage.values.values()].join('')).not.toContain(
            saved.draft!.content,
        );
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(outcome(mutation))
            .mockResolvedValueOnce({ message: { id: 101 } })
            .mockResolvedValueOnce(outcome(mutation, 'confirmed'));
        await session.send();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.slice(1)
                .map(([path]) => path),
        ).toEqual([
            `/chat/api/conversations/71/draft/send-attempts/${mutation}`,
            '/chat/api/conversations/71/messages',
            `/chat/api/conversations/71/draft/send-attempts/${mutation}`,
        ]);
        expect(
            JSON.parse(vi.mocked(chatRequest).mock.calls[2][1]!.body as string),
        ).toEqual(outcome(mutation).body);
        expect(session.snapshot().phase).toBe('confirmed');
        expect(journal.read().status).toBe('empty');
        await session.send();
        expect(chatRequest).toHaveBeenCalledTimes(4);
    });
    it('reopens a prepared attempt using a read only and preserves its original identity', async () => {
        const original = await prepared();
        original.session.dispose();
        // Let the browser-owned lock release settle before opening the replacement page.
        await new Promise<void>((resolve) => setTimeout(resolve, 0));
        const { session } = setup(original.storage, original.locks);
        vi.mocked(chatRequest).mockResolvedValueOnce(
            outcome(original.mutation),
        );
        await session.recover();
        expect(session.snapshot()).toMatchObject({
            phase: 'ready',
            reference: { mutation: original.mutation },
        });
        expect(vi.mocked(chatRequest).mock.calls[1][1]?.method).toBeUndefined();
        expect(chatRequest).toHaveBeenCalledTimes(2);
    });
    it('seals a missing reservation after reload before permitting a new attempt', async () => {
        const original = setup();
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new TypeError('Lost response'),
        );
        await original.session.prepare(saved, null);
        const mutation = original.session.snapshot().reference!.mutation;
        original.session.dispose();
        // Let the browser-owned lock release settle before opening the replacement page.
        await new Promise<void>((resolve) => setTimeout(resolve, 0));
        const { session, journal } = setup(original.storage, original.locks);
        vi.mocked(chatRequest).mockResolvedValueOnce(
            outcome(mutation, 'missing'),
        );
        await session.recover();
        expect(session.snapshot().phase).toBe('unknown');
        await session.prepare(saved, null);
        await session.retryPreparation();
        expect(chatRequest).toHaveBeenCalledTimes(2);
        vi.mocked(chatRequest).mockResolvedValueOnce(
            outcome(mutation, 'cancelled'),
        );
        await session.cancel();
        expect(journal.read().status).toBe('empty');
        expect(session.snapshot().phase).toBe('cancelled');
    });
    it('keeps cancellation after a lost response and reload, and never retries sending that reference', async () => {
        const original = await prepared();
        vi.mocked(chatRequest).mockRejectedValueOnce(new TypeError('Offline'));
        await original.session.cancel();
        expect(original.journal.read().record?.cancelling).toBe(true);
        original.session.dispose();
        // Let the browser-owned lock release settle before opening the replacement page.
        await new Promise<void>((resolve) => setTimeout(resolve, 0));
        const { session } = setup(original.storage, original.locks);
        vi.mocked(chatRequest).mockResolvedValueOnce(
            outcome(original.mutation),
        );
        await session.recover();
        expect(session.snapshot().phase).toBe('cancel_pending');
        await session.send();
        await session.retryPreparation();
        expect(chatRequest).toHaveBeenCalledTimes(3);
        vi.mocked(chatRequest).mockResolvedValueOnce(
            outcome(original.mutation, 'cancelled'),
        );
        await session.cancel();
        expect(session.snapshot().phase).toBe('cancelled');
    });
    it('cancels an in-flight preparation and ignores its late successful response', async () => {
        const { session, journal } = setup();
        const delayed = deferred<PrivateSendAttempt>();
        vi.mocked(chatRequest).mockReturnValueOnce(delayed.promise);
        const preparing = session.prepare(saved, null);
        await vi.waitFor(() => expect(chatRequest).toHaveBeenCalledTimes(1));
        const mutation = session.snapshot().reference!.mutation;
        vi.mocked(chatRequest).mockResolvedValueOnce(
            outcome(mutation, 'cancelled'),
        );
        await session.cancel();
        delayed.resolve(outcome(mutation));
        await preparing;
        expect(session.snapshot().phase).toBe('cancelled');
        expect(journal.read().status).toBe('empty');
        expect(vi.mocked(chatRequest).mock.calls[0][1]!.signal!.aborted).toBe(
            true,
        );
    });
    it('allows only one sending tab while calls use a separate lease and owner closure enables read-only recovery', async () => {
        const owner = await prepared();
        const next = setup(owner.storage, owner.locks);
        await next.session.prepare(saved, null);
        expect(next.session.snapshot().phase).toBe('other_tab');
        expect(chatRequest).toHaveBeenCalledTimes(1);
        const call = await acquireCallBrowserLease(
            11,
            new AbortController().signal,
            owner.locks,
        );
        expect(call).not.toBeNull();
        call!.release();
        owner.session.dispose();
        // Let the browser-owned lock release settle before opening the replacement page.
        await new Promise<void>((resolve) => setTimeout(resolve, 0));
        vi.mocked(chatRequest).mockResolvedValueOnce(outcome(owner.mutation));
        await next.session.recover();
        expect(next.session.snapshot().phase).toBe('ready');
        expect(chatRequest).toHaveBeenCalledTimes(2);
    });
    it('rejects changed server intent before dispatching any message', async () => {
        const { session, mutation } = await prepared();
        vi.mocked(chatRequest).mockResolvedValueOnce(
            outcome(mutation, 'pending', {
                ...outcome(mutation).body!,
                content: 'Different message',
            }),
        );
        await session.send();
        expect(session.snapshot().phase).toBe('uncertain');
        expect(chatRequest).toHaveBeenCalledTimes(2);
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(([path]) => path.endsWith('/messages')),
        ).toBe(false);
    });
    it('retains an unconfirmed send after POST failure and confirms it with a read instead of resending', async () => {
        const { session, journal, mutation } = await prepared();
        vi.mocked(chatRequest)
            .mockResolvedValueOnce(outcome(mutation))
            .mockRejectedValueOnce(new TypeError('Lost acknowledgement'));
        await session.send();
        expect(session.snapshot().phase).toBe('uncertain');
        expect(journal.read().status).toBe('saved');
        vi.mocked(chatRequest).mockResolvedValueOnce(
            outcome(mutation, 'confirmed'),
        );
        await session.send();
        expect(session.snapshot().phase).toBe('confirmed');
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.filter(([path]) => path.endsWith('/messages')),
        ).toHaveLength(1);
    });
    it('drops care content on access loss and never adopts another account journal', async () => {
        const { session, journal, mutation, locks } = await prepared();
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new ChatError('Revoked', 403),
        );
        await session.check();
        expect(session.snapshot()).toMatchObject({
            phase: 'revoked',
            attempt: null,
        });
        await session.send();
        const other = new ChatSendSession(
            { ...scope, actor_id: 22 },
            journal,
            locks,
        );
        sessions.push(other);
        await other.recover(outcome(mutation));
        expect(other.snapshot().phase).toBe('storage_error');
        expect(chatRequest).toHaveBeenCalledTimes(2);
    });
    it('does not claim or retain a browser lease for idle send, check or cancellation actions', async () => {
        const first = setup();
        await first.session.send();
        await first.session.check();
        await first.session.cancel();
        await first.session.retryPreparation();
        const second = setup(first.storage, first.locks);
        vi.mocked(chatRequest).mockImplementationOnce(async (_path, options) =>
            outcome(JSON.parse(options!.body as string).mutation_id),
        );
        await second.session.prepare(saved, null);
        expect(second.session.snapshot().phase).toBe('ready');
        expect(chatRequest).toHaveBeenCalledTimes(1);
    });
    it('does not dispatch a new reservation when durable storage is unavailable', async () => {
        const storage = memory();
        storage.setItem = () => {
            throw new Error('Storage unavailable');
        };
        const { session } = setup(storage);
        await session.prepare(saved, null);
        expect(session.snapshot().phase).toBe('storage_error');
        expect(chatRequest).not.toHaveBeenCalled();
    });
    it('still attempts safe server cancellation if storage fails after preparation', async () => {
        const { session, storage, mutation } = await prepared();
        storage.setItem = () => {
            throw new Error('Storage unavailable');
        };
        vi.mocked(chatRequest).mockResolvedValueOnce(
            outcome(mutation, 'cancelled'),
        );
        await session.cancel();
        expect(session.snapshot().phase).toBe('cancelled');
        expect(vi.mocked(chatRequest).mock.calls[1][0]).toBe(
            `/chat/api/conversations/71/draft/send-attempts/${mutation}/cancel`,
        );
    });
});
