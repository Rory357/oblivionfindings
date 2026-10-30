import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import {
    parsePrivateDraftSnapshot,
    parsePrivateSendAttempt,
    PrivateChatDraftSession,
    type PrivateChatDraft,
    type PrivateDraftSnapshot,
    type PrivateSendAttempt,
} from './chat-private-draft';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
const scope = { actor_id: 11, conversation_id: 71, client_id: null };
const draft = (content = 'Unfinished note'): PrivateChatDraft => ({
    content,
    reply_to_id: null,
    mentions: [],
    uploads: [],
});
const snapshot = (
    version = 0,
    value: PrivateChatDraft | null = null,
): PrivateDraftSnapshot => ({
    ...scope,
    pending_send: null,
    boundary: 'a'.repeat(64),
    version,
    state: value ? 'saved' : 'empty',
    draft: value,
    updated_at: version ? '2026-10-18 04:00:00' : null,
});
const saved = (version: number, value: PrivateChatDraft | null) => ({
    ...snapshot(version, value),
    applied_version: version,
});
const pending = (): PrivateSendAttempt => ({
    actor_id: scope.actor_id,
    conversation_id: scope.conversation_id,
    mutation_id: '48b1d722-f698-48bd-a43f-0694bec66ca3',
    state: 'pending',
    message_id: null,
    can_retry: true,
    body: {
        recoverable_send: true,
        mutation_id: '48b1d722-f698-48bd-a43f-0694bec66ca3',
        draft_version: 1,
        draft_boundary: 'a'.repeat(64),
        audience_token: null,
        content: 'Original message',
        reply_to_id: null,
        mentions: [],
        upload_ids: [],
        captions: [],
    },
});
function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (error: Error) => void;
    const promise = new Promise<T>((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
}
async function ready() {
    const session = new PrivateChatDraftSession(scope);
    vi.mocked(chatRequest).mockResolvedValueOnce(snapshot());
    await session.load();
    return session;
}
describe('protected private draft recovery', () => {
    beforeEach(() => vi.resetAllMocks());
    it('validates the exact pending send identity, body and current draft boundary', () => {
        const attempt = pending();
        expect(
            parsePrivateSendAttempt(attempt, scope, attempt.mutation_id),
        ).toEqual(attempt);
        for (const patch of [
            { actor_id: 22 },
            { mutation_id: 'another-message' },
            { body: null },
            { state: 'confirmed' },
            { message_id: 5 },
        ])
            expect(
                parsePrivateSendAttempt(
                    { ...attempt, ...patch },
                    scope,
                    attempt.mutation_id,
                ),
            ).toBeNull();
        for (const patch of [
            { recoverable_send: false },
            { mutation_id: 'f03c0773-9b2b-426d-b4d0-b46cfdb2c4bd' },
            { draft_version: 0 },
            {
                upload_ids: ['48b1d722-f698-48bd-a43f-0694bec66ca3'],
                captions: [],
            },
        ])
            expect(
                parsePrivateSendAttempt(
                    { ...attempt, body: { ...attempt.body, ...patch } },
                    scope,
                ),
            ).toBeNull();
        expect(
            parsePrivateDraftSnapshot(
                { ...snapshot(1, draft()), pending_send: attempt },
                scope,
            )?.pending_send,
        ).toEqual(attempt);
        expect(
            parsePrivateDraftSnapshot(
                {
                    ...snapshot(1, draft()),
                    boundary: 'b'.repeat(64),
                    pending_send: attempt,
                },
                scope,
            ),
        ).toBeNull();
        expect(
            parsePrivateSendAttempt(
                {
                    ...attempt,
                    state: 'confirmed',
                    body: null,
                    can_retry: false,
                },
                scope,
            ),
        ).toMatchObject({ state: 'confirmed', message_id: null });
    });
    it('restores a pending send before allowing further edits or draft saves and retains newer local work for review', async () => {
        const session = new PrivateChatDraftSession(
            scope,
            draft('Newer unsaved text'),
        );
        vi.mocked(chatRequest).mockResolvedValueOnce({
            ...snapshot(1, draft('Original message')),
            pending_send: pending(),
        });
        await session.load();
        expect(session.snapshot()).toMatchObject({
            phase: 'pending_send',
            local: draft('Newer unsaved text'),
        });
        session.edit(draft('Accidental typing while pending'));
        await session.save();
        expect(chatRequest).toHaveBeenCalledTimes(1);
        expect(session.snapshot().local).toEqual(draft('Newer unsaved text'));
        vi.mocked(chatRequest).mockResolvedValueOnce(
            snapshot(1, draft('Original message')),
        );
        await session.load();
        expect(session.snapshot()).toMatchObject({
            phase: 'conflict',
            local: draft('Newer unsaved text'),
        });
    });
    it('keeps a changed-audience attempt recoverable without exposing its earlier text', async () => {
        const session = new PrivateChatDraftSession(
            scope,
            draft('Earlier context'),
        );
        vi.mocked(chatRequest).mockResolvedValueOnce({
            ...snapshot(1),
            state: 'scope_changed',
            pending_send: {
                ...pending(),
                state: 'scope_changed',
                body: null,
                can_retry: false,
            },
        });
        await session.load();
        expect(session.snapshot()).toMatchObject({
            phase: 'pending_send',
            local: null,
            dirty: false,
        });
        vi.mocked(chatRequest).mockResolvedValueOnce({
            ...snapshot(1),
            state: 'scope_changed',
        });
        await session.load();
        expect(session.snapshot().phase).toBe('scope_changed');
    });
    it('keeps unsaved typing on a refresh of the unchanged base and rejects older server reads', async () => {
        const session = new PrivateChatDraftSession(scope);
        vi.mocked(chatRequest).mockResolvedValueOnce(
            snapshot(2, draft('Saved')),
        );
        await session.load();
        session.edit(draft('Still typing'));
        vi.mocked(chatRequest).mockResolvedValueOnce(
            snapshot(2, draft('Saved')),
        );
        await session.load();
        expect(session.snapshot()).toMatchObject({
            phase: 'ready',
            local: draft('Still typing'),
            dirty: true,
        });
        vi.mocked(chatRequest).mockResolvedValueOnce(
            snapshot(1, draft('Stale server result')),
        );
        await session.load();
        expect(session.snapshot()).toMatchObject({
            phase: 'unavailable',
            local: draft('Still typing'),
            remote: { version: 2 },
        });
    });
    it('restores only a verified account, conversation and client snapshot', async () => {
        const session = new PrivateChatDraftSession(scope);
        const value = draft('  📝 @Māia Worker\nReview  \n');
        value.mentions = [{ user_id: 22, start: 4, length: 12 }];
        vi.mocked(chatRequest).mockResolvedValueOnce(snapshot(1, value));
        await session.load();
        expect(session.snapshot()).toMatchObject({
            phase: 'ready',
            local: value,
            dirty: false,
        });
        expect(chatRequest).toHaveBeenCalledWith(
            '/chat/api/conversations/71/draft',
            expect.objectContaining({ headers: { 'X-Chat-Actor': '11' } }),
        );
        for (const patch of [
            { actor_id: 22 },
            { conversation_id: 72 },
            { client_id: 1 },
            { draft: null },
            { version: -1 },
            { state: 'empty' },
            { applied_version: 2 },
        ])
            expect(
                parsePrivateDraftSnapshot(
                    { ...snapshot(1, value), ...patch },
                    scope,
                ),
            ).toBeNull();
    });
    it('rejects malformed nested references rather than adopting partial draft content', () => {
        for (const change of [
            { uploads: [{ id: '../private', caption: '' }] },
            { mentions: [{ user_id: 22, start: -1, length: 4 }] },
            { reply_to_id: 0 },
            { content: 'x'.repeat(10001) },
            {
                uploads: [
                    {
                        id: 'b2c8cc12-a824-425f-bb39-cc68a8e08f98',
                        caption: 'x'.repeat(1001),
                    },
                ],
            },
        ])
            expect(
                parsePrivateDraftSnapshot(
                    snapshot(1, { ...draft(), ...change }),
                    scope,
                ),
            ).toBeNull();
    });
    it('retains typing during the initial read without overwriting an existing saved draft', async () => {
        const session = new PrivateChatDraftSession(scope);
        const read = deferred<PrivateDraftSnapshot>();
        vi.mocked(chatRequest).mockReturnValueOnce(read.promise);
        const loading = session.load();
        session.edit(draft('Typed while checking'));
        read.resolve(snapshot(2, draft('Other device')));
        await loading;
        expect(session.snapshot()).toMatchObject({
            phase: 'conflict',
            local: draft('Typed while checking'),
            remote: { draft: draft('Other device') },
        });
        await session.save();
        expect(chatRequest).toHaveBeenCalledTimes(1);
        session.resolve('saved');
        expect(session.snapshot()).toMatchObject({
            phase: 'ready',
            local: draft('Other device'),
            dirty: false,
        });
    });
    it('does not overwrite a newer cleared draft with older tab memory', async () => {
        const session = new PrivateChatDraftSession(
            scope,
            draft('Old tab text'),
        );
        vi.mocked(chatRequest).mockResolvedValueOnce(snapshot(3));
        await session.load();
        expect(session.snapshot().phase).toBe('conflict');
        await session.save();
        expect(chatRequest).toHaveBeenCalledTimes(1);
        session.resolve('this_tab');
        vi.mocked(chatRequest).mockResolvedValueOnce(
            saved(4, draft('Old tab text')),
        );
        await session.save();
        expect(
            JSON.parse(vi.mocked(chatRequest).mock.calls[1][1]!.body as string)
                .version,
        ).toBe(3);
        expect(session.snapshot().dirty).toBe(false);
    });
    it('keeps newer typing when an older save completes and bases the next save on the acknowledged version', async () => {
        const session = await ready();
        session.edit(draft('First'));
        const write = deferred<PrivateDraftSnapshot>();
        vi.mocked(chatRequest).mockReturnValueOnce(write.promise);
        const saving = session.save();
        session.edit(draft('Newer typing'));
        write.resolve(saved(1, draft('First')));
        await saving;
        expect(session.snapshot()).toMatchObject({
            phase: 'ready',
            local: draft('Newer typing'),
            dirty: true,
        });
        vi.mocked(chatRequest).mockResolvedValueOnce(
            saved(2, draft('Newer typing')),
        );
        await session.save();
        const body = JSON.parse(
            vi.mocked(chatRequest).mock.calls[2][1]!.body as string,
        );
        expect(body).toMatchObject({
            version: 1,
            draft: draft('Newer typing'),
        });
    });
    it('retries identical uncertain save bytes while preserving subsequent local edits', async () => {
        const session = await ready();
        session.edit(draft('Submitted'));
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new TypeError('Network interrupted'),
        );
        await session.save();
        expect(session.snapshot().phase).toBe('uncertain');
        const original = vi.mocked(chatRequest).mock.calls[1][1]!.body;
        session.edit(draft('Typed afterwards'));
        await session.load();
        expect(chatRequest).toHaveBeenCalledTimes(2);
        vi.mocked(chatRequest).mockResolvedValueOnce(
            saved(1, draft('Submitted')),
        );
        await session.save();
        expect(vi.mocked(chatRequest).mock.calls[2][1]!.body).toBe(original);
        expect(session.snapshot()).toMatchObject({
            phase: 'ready',
            local: draft('Typed afterwards'),
            dirty: true,
        });
    });
    it('treats an invalid acknowledgement as uncertain and retains the original command', async () => {
        const session = await ready();
        session.edit(draft());
        vi.mocked(chatRequest).mockResolvedValueOnce({
            ...saved(1, draft()),
            actor_id: 22,
        });
        await session.save();
        const original = vi.mocked(chatRequest).mock.calls[1][1]!.body;
        expect(session.snapshot().phase).toBe('uncertain');
        vi.mocked(chatRequest).mockResolvedValueOnce(saved(1, draft()));
        await session.save();
        expect(vi.mocked(chatRequest).mock.calls[2][1]!.body).toBe(original);
    });
    it('requires review when an exact retry reports a later server version', async () => {
        const session = await ready();
        session.edit(draft('This device'));
        vi.mocked(chatRequest).mockResolvedValueOnce({
            ...snapshot(2, draft('Later device')),
            applied_version: 1,
        });
        await session.save();
        expect(session.snapshot()).toMatchObject({
            phase: 'conflict',
            local: draft('This device'),
            remote: { draft: draft('Later device') },
        });
        await session.save();
        expect(chatRequest).toHaveBeenCalledTimes(2);
    });
    it('fetches the conflict before allowing an explicit replacement and never blindly retries it', async () => {
        const session = await ready();
        session.edit(draft('This device'));
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new ChatError(
                'Newer draft saved',
                409,
                'chat_draft_version_changed',
            ),
        );
        await session.save();
        expect(session.snapshot()).toMatchObject({
            phase: 'conflict',
            remote: null,
        });
        session.resolve('this_tab');
        await session.save();
        expect(chatRequest).toHaveBeenCalledTimes(2);
        vi.mocked(chatRequest).mockResolvedValueOnce(
            snapshot(4, draft('Other device')),
        );
        await session.load();
        expect(session.snapshot().phase).toBe('conflict');
        session.resolve('this_tab');
        vi.mocked(chatRequest).mockResolvedValueOnce(
            saved(5, draft('This device')),
        );
        await session.save();
        expect(
            JSON.parse(vi.mocked(chatRequest).mock.calls[3][1]!.body as string)
                .version,
        ).toBe(4);
    });
    it('withholds changed-audience drafts and requires explicit discard before a replacement', async () => {
        const session = new PrivateChatDraftSession(
            scope,
            draft('Old audience'),
        );
        vi.mocked(chatRequest).mockResolvedValueOnce({
            ...snapshot(1),
            state: 'scope_changed',
        });
        await session.load();
        session.edit(draft('Accidental replacement'));
        await session.save();
        expect(session.snapshot()).toMatchObject({
            phase: 'scope_changed',
            local: null,
        });
        expect(chatRequest).toHaveBeenCalledTimes(1);
        vi.mocked(chatRequest).mockResolvedValueOnce(saved(2, null));
        await session.discardChangedScope();
        expect(
            JSON.parse(vi.mocked(chatRequest).mock.calls[1][1]!.body as string),
        ).toMatchObject({ version: 1, draft: null });
        expect(session.snapshot()).toMatchObject({
            phase: 'ready',
            local: null,
            dirty: false,
        });
    });
    it('clears care content on access revocation and rejects subsequent editing or recovery', async () => {
        const session = await ready();
        session.edit(draft('Private text'));
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new ChatError('Access changed', 403),
        );
        await session.save();
        session.edit(draft('Stale typing'));
        await session.load();
        await session.save();
        expect(session.snapshot()).toMatchObject({
            phase: 'revoked',
            local: null,
            remote: null,
            dirty: false,
        });
        expect(chatRequest).toHaveBeenCalledTimes(2);
    });
    it('ignores a late read after disposal and drops all account-owned memory', async () => {
        const session = new PrivateChatDraftSession(
            scope,
            draft('Private text'),
        );
        const read = deferred<PrivateDraftSnapshot>();
        vi.mocked(chatRequest).mockReturnValueOnce(read.promise);
        const loading = session.load();
        session.dispose();
        read.resolve(snapshot(1, draft('Late private text')));
        await loading;
        expect(session.snapshot()).toMatchObject({
            phase: 'revoked',
            local: null,
            remote: null,
        });
        expect(vi.mocked(chatRequest).mock.calls[0][1]!.signal!.aborted).toBe(
            true,
        );
    });
    it('coalesces overlapping saves into one command without persisting browser care data', async () => {
        const storage = vi.spyOn(Storage.prototype, 'setItem');
        const session = await ready();
        session.edit(draft());
        const write = deferred<PrivateDraftSnapshot>();
        vi.mocked(chatRequest).mockReturnValueOnce(write.promise);
        const saving = session.save();
        await session.save();
        expect(chatRequest).toHaveBeenCalledTimes(2);
        write.resolve(saved(1, draft()));
        await saving;
        expect(storage).not.toHaveBeenCalled();
        storage.mockRestore();
    });
    it('flushes newer typing after the in-flight autosave before returning a sendable snapshot', async () => {
        const session = await ready();
        session.edit(draft('Earlier text'));
        const write = deferred<PrivateDraftSnapshot>();
        vi.mocked(chatRequest)
            .mockReturnValueOnce(write.promise)
            .mockResolvedValueOnce(saved(2, draft('Latest text')));
        const saving = session.save();
        session.edit(draft('Latest text'));
        const flushing = session.flush();
        write.resolve(saved(1, draft('Earlier text')));
        await saving;
        expect((await flushing)?.draft?.content).toBe('Latest text');
        expect(session.snapshot().dirty).toBe(false);
    });
    it('releases a pending flush on account disposal without allowing a send', async () => {
        const session = await ready();
        session.edit(draft());
        const write = deferred<PrivateDraftSnapshot>();
        vi.mocked(chatRequest).mockReturnValueOnce(write.promise);
        const saving = session.save();
        const flushing = session.flush();
        session.dispose();
        expect(await flushing).toBeNull();
        write.resolve(saved(1, draft()));
        await saving;
    });
});
