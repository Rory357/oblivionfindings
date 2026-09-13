import { describe, expect, it, vi } from 'vitest';
import {
    draftContextKey,
    type ItDraftContext,
} from './it-ticket-draft-contract';
import {
    createItDraftMemoryStore,
    type ItDraftMemoryAuthorizationResponse,
    type ItDraftMemoryCandidate,
    type ItLocalDraftMemoryCandidate,
    type ItPersistedDraftMemoryCandidate,
} from './it-ticket-draft-memory';

const context: ItDraftContext = { purpose: 'public_resolution', ticketId: 1 };
const draftUuid = '726d8762-c946-478f-811d-0e6eb4916347';
const otherUuid = 'a7c3e73e-502b-4ddf-a2c4-3a899d384520';

it('retains an immutable pending reply separately from newer text and counts shared File bytes once', () => {
    const memory = createItDraftMemoryStore({
        maxEntries: 2,
        maxBytes: 100000,
    });
    const file = new File(['x'.repeat(12000)], 'original.txt');
    const pendingComment = {
        actorId: 8,
        ticketId: 1,
        requestUuid: draftUuid,
        isInternal: false,
        expectedVersion: 3,
        body: 'Original submitted text',
        files: [file],
        draft: {
            draft_uuid: otherUuid,
            draft_revision: 2,
            draft_actor_user_id: 8,
        },
    };
    const value: ItLocalDraftMemoryCandidate = {
        kind: 'memory',
        memoryUuid: otherUuid,
        actorId: 8,
        context: { purpose: 'public_reply', ticketId: 1 },
        revision: 0,
        snapshot: {
            fields: { body: 'Newer unsent text' },
            step_index: 0,
            base_ticket_version: 3,
        },
        selectedFiles: [file],
        pendingComment,
        outcomeUnknown: true,
    };
    const retained = memory.retain(value);
    expect(retained.status).toBe('stored');
    if (retained.status !== 'stored') throw new Error('Missing fixture');
    expect(retained.notice.hasPendingComment).toBe(true);
    expect(JSON.stringify(retained.notice)).not.toContain('Original');
    expect(memory.usage().bytes).toBeGreaterThan(12000);
    expect(memory.usage().bytes).toBeLessThan(24000);
    const newer = {
        ...value,
        snapshot: { ...value.snapshot, fields: { body: 'Last keystroke' } },
    };
    expect(memory.retain(newer, retained.notice.bufferId).status).toBe(
        'stored',
    );
    expect(
        memory.retain(
            {
                ...newer,
                pendingComment: { ...pendingComment, body: 'Changed command' },
            },
            retained.notice.bufferId,
        ).status,
    ).toBe('frozen');
    expect(
        memory.retain(
            {
                ...newer,
                pendingComment: {
                    ...pendingComment,
                    files: [new File(['x'.repeat(12000)], 'original.txt')],
                },
            },
            retained.notice.bufferId,
        ).status,
    ).toBe('frozen');
    expect(
        memory.retain({
            ...newer,
            pendingComment: { ...pendingComment, isInternal: true },
        }).status,
    ).toBe('invalid');
    expect(memory.retain({ ...newer, outcomeUnknown: false }).status).toBe(
        'invalid',
    );
});
const candidate = (
    overrides: Partial<ItPersistedDraftMemoryCandidate> = {},
): ItPersistedDraftMemoryCandidate => ({
    actorId: 8,
    context,
    draftUuid,
    revision: 2,
    snapshot: {
        fields: { note: 'Synthetic last local edit', notify_requester: true },
        step_index: 0,
        base_ticket_version: 3,
    },
    outcomeUnknown: false,
    ...overrides,
});
const store = () =>
    createItDraftMemoryStore({ maxEntries: 20, maxBytes: 1000000 });
const binding = { actorId: 8, context };
const response = (
    value: ItDraftMemoryCandidate & {
        candidateUuid: string;
        authorizationRevision?: number;
    },
    proof: Record<string, unknown> = {},
): ItDraftMemoryAuthorizationResponse => ({
    status: 'response',
    httpStatus: 200,
    body: {
        candidate: {
            candidate_uuid: value.candidateUuid,
            actor_user_id: value.actorId,
            draft_uuid: value.draftUuid,
            purpose: value.context.purpose,
            context_key: draftContextKey(value.context),
            revision: value.authorizationRevision ?? value.revision,
            base_ticket_version: value.snapshot.base_ticket_version ?? null,
            authorized: true,
            ...proof,
        },
        draft: {
            draft_uuid: value.draftUuid,
            purpose: value.context.purpose,
            context_key: draftContextKey(value.context),
            audience: [
                'internal_note',
                'ticket_edit',
                'technician_intake',
            ].includes(value.context.purpose)
                ? 'internal'
                : 'public',
            ticket_id: value.context.ticketId ?? null,
            request_uuid: value.context.requestUuid ?? null,
            revision: value.authorizationRevision ?? value.revision,
            state: 'active',
            has_content: true,
            saved_at: '2026-09-09T00:00:00Z',
            expires_at: '2026-09-11T00:00:00Z',
            base_ticket_version: value.snapshot.base_ticket_version ?? null,
            current_ticket_version: value.snapshot.base_ticket_version ?? null,
            files: {
                ready: 0,
                pending: value.pendingUpload ? 1 : 0,
                cleanup_pending: 0,
            },
            capabilities: {
                read: true,
                save: true,
                submit: !value.pendingUpload,
                discard: true,
                start_new: false,
            },
            blocker: null,
        },
    },
});
const retained = (memory: ReturnType<typeof store>, value = candidate()) => {
    const result = memory.retain(value);
    expect(result.status).toBe('stored');
    if (result.status !== 'stored') throw new Error('Fixture not retained');
    return result.notice;
};

describe('canonical draft memory handoff', () => {
    it('atomically initializes a mounted local draft at capacity, retaining the original if replacement fails', () => {
        const memory = createItDraftMemoryStore({
            maxEntries: 1,
            maxBytes: 200,
        });
        const local: ItLocalDraftMemoryCandidate = {
            actorId: 8,
            context,
            kind: 'memory',
            memoryUuid: otherUuid,
            revision: 0,
            snapshot: candidate().snapshot,
            outcomeUnknown: false,
        };
        const result = memory.retain(local);
        if (result.status !== 'stored')
            throw new Error('Missing local fixture');
        const tooLarge = candidate({
            snapshot: {
                ...candidate().snapshot,
                fields: { note: 'x'.repeat(500) },
            },
        });
        expect(
            memory.initializeOwnedDraft(result.notice.bufferId, tooLarge)
                .status,
        ).toBe('capacity');
        expect(memory.discover(8, context)[0]).toMatchObject({
            kind: 'memory',
            memoryUuid: otherUuid,
        });
        expect(
            memory.initializeOwnedDraft(result.notice.bufferId, candidate())
                .status,
        ).toBe('stored');
        expect(memory.discover(8, context)).toHaveLength(1);
        expect(memory.discover(8, context)[0]).toMatchObject({
            kind: 'persisted',
            draftUuid,
        });
    });

    it('authorizes a local generation without invented canonical draft metadata and preserves the stale base version', async () => {
        const memory = store();
        const local: ItLocalDraftMemoryCandidate = {
            actorId: 8,
            context,
            kind: 'memory',
            memoryUuid: otherUuid,
            revision: 0,
            snapshot: candidate().snapshot,
            outcomeUnknown: false,
            boundScopes: [],
        };
        const result = memory.retain(local);
        if (result.status !== 'stored')
            throw new Error('Missing local fixture');
        expect(result.notice).toMatchObject({
            kind: 'memory',
            memoryUuid: otherUuid,
            draftUuid: null,
            revision: 0,
        });
        const proof = await memory.authorizeResume(
            result.notice.bufferId,
            binding,
            async (value) => ({
                status: 'response',
                httpStatus: 200,
                body: {
                    candidate: {
                        kind: 'memory',
                        memory_uuid: otherUuid,
                        candidate_uuid: value.candidateUuid,
                        actor_user_id: 8,
                        purpose: context.purpose,
                        context_key: draftContextKey(context),
                        base_ticket_version: 3,
                        current_ticket_version: 4,
                        authorized: true,
                        capabilities: { submit: false },
                        blocker: {
                            code: 'stale_ticket',
                            message: 'Review the current ticket.',
                        },
                        private_extra: 'Never echo this',
                    },
                },
            }),
        );
        if (proof.status !== 'authorized')
            throw new Error('Missing local authorization');
        expect(proof.localAuthorization?.current_ticket_version).toBe(4);
        expect(proof.localAuthorization?.capabilities.submit).toBe(false);
        expect(JSON.stringify(proof)).not.toContain('private_extra');
        expect(memory.adopt(proof.permit, binding)).toEqual(local);
    });

    it('counts and retains original local selections without generating staged upload identities', () => {
        const memory = store();
        const files = [
            new File(['one'], 'private-one.txt'),
            new File(['two'], 'private-two.txt'),
        ];
        const reply = { purpose: 'public_reply' as const, ticketId: 1 };
        const result = memory.retain({
            kind: 'memory',
            memoryUuid: otherUuid,
            revision: 0,
            actorId: 8,
            context: reply,
            snapshot: { fields: { body: 'Unsubmitted reply' }, step_index: 0 },
            outcomeUnknown: false,
            selectedFiles: files,
        });
        expect(result.status).toBe('stored');
        if (result.status !== 'stored')
            throw new Error('Missing local fixture');
        expect(result.notice.selectedFileCount).toBe(2);
        expect(result.notice.hasPendingUpload).toBe(false);
        expect(JSON.stringify(result.notice)).not.toContain('private-');
        expect(memory.usage().bytes).toBeGreaterThan(
            files.reduce((sum, file) => sum + file.size, 0),
        );
    });

    it('keeps historical reference snapshots private and rejects invalid or excessive scope history without eviction', () => {
        const memory = store();
        const original = candidate({
            boundScopes: [{ site_id: 1 }, { site_id: 2, asset_id: 3 }],
        });
        const item = retained(memory, original);
        expect(JSON.stringify(item)).not.toContain('site_id');
        expect(
            memory.retain(
                candidate({
                    boundScopes: Array.from({ length: 101 }, (_, index) => ({
                        site_id: index + 1,
                    })),
                }),
                item.bufferId,
            ),
        ).toEqual({ status: 'invalid' });
        expect(memory.discover(8, context)).toEqual([item]);
    });
    it('discovers metadata only and reveals the exact private candidate only after fresh proof and one-use adoption', async () => {
        const memory = store();
        const item = retained(memory);
        expect(memory.discover(8, context)).toEqual([item]);
        expect(JSON.stringify(item)).not.toContain('Synthetic');
        expect(memory.adopt('not-authorized', binding)).toBeNull();
        const checked = vi.fn(async (value) => response(value));
        const authorization = await memory.authorizeResume(
            item.bufferId,
            binding,
            checked,
        );
        expect(authorization.status).toBe('authorized');
        expect(JSON.stringify(authorization)).not.toContain('Synthetic');
        expect(checked).toHaveBeenCalledOnce();
        expect(memory.discover(8, context)).toHaveLength(1);
        if (authorization.status !== 'authorized')
            throw new Error('Missing proof');
        expect(memory.adopt(authorization.permit, binding)).toEqual(
            candidate(),
        );
        expect(memory.adopt(authorization.permit, binding)).toBeNull();
        expect(memory.usage()).toEqual({ entries: 0, bytes: 0 });
    });

    it.each([
        { candidate_uuid: otherUuid },
        { actor_user_id: 9 },
        { draft_uuid: otherUuid },
        { purpose: 'internal_note' },
        { context_key: 'ticket:2' },
        { revision: 3 },
        { base_ticket_version: 4 },
        { authorized: false },
    ])(
        'rejects mismatched candidate proof without disclosing or discarding content %#',
        async (proof) => {
            const memory = store();
            const item = retained(memory);
            expect(
                await memory.authorizeResume(
                    item.bufferId,
                    binding,
                    async (value) => response(value, proof),
                ),
            ).toEqual({ status: 'failed' });
            expect(memory.discover(8, context)).toHaveLength(1);
        },
    );

    it('requires HTTP200 and matching currently readable canonical metadata', async () => {
        const memory = store();
        const item = retained(memory);
        expect(
            await memory.authorizeResume(
                item.bufferId,
                binding,
                async (value) =>
                    ({
                        ...response(value),
                        httpStatus: 202,
                    }) as ItDraftMemoryAuthorizationResponse,
            ),
        ).toEqual({ status: 'failed' });
        expect(
            await memory.authorizeResume(
                item.bufferId,
                binding,
                async (value) => {
                    const result = response(value);
                    if (result.status === 'response') {
                        const body = result.body as {
                            draft: { revision: number };
                        };
                        body.draft.revision = 3;
                    }
                    return result;
                },
            ),
        ).toEqual({ status: 'failed' });
        expect(memory.discover(8, context)).toHaveLength(1);
    });

    it('never calls candidate authorization for another actor, ticket or audience', async () => {
        const memory = store();
        const item = retained(memory);
        const checked = vi.fn();
        for (const scope of [
            { actorId: 9, context },
            {
                actorId: 8,
                context: { purpose: 'public_resolution' as const, ticketId: 2 },
            },
            {
                actorId: 8,
                context: { purpose: 'internal_note' as const, ticketId: 1 },
            },
        ]) {
            expect(memory.discover(scope.actorId, scope.context)).toEqual([]);
            expect(
                await memory.authorizeResume(item.bufferId, scope, checked),
            ).toEqual({ status: 'unavailable' });
        }
        expect(checked).not.toHaveBeenCalled();
        expect(memory.discover(8, context)).toHaveLength(1);
    });

    it('conceals session expiry and conflicts but purges a denied exact candidate only', async () => {
        const memory = store();
        const item = retained(memory);
        const other = retained(memory, candidate({ draftUuid: otherUuid }));
        for (const status of [
            'session_expired',
            'conflict',
            'failed',
        ] as const) {
            expect(
                await memory.authorizeResume(
                    item.bufferId,
                    binding,
                    async () => ({ status }),
                ),
            ).toEqual({ status });
            expect(memory.discover(8, context)).toHaveLength(2);
        }
        expect(
            await memory.authorizeResume(item.bufferId, binding, async () => ({
                status: 'denied',
            })),
        ).toEqual({ status: 'denied' });
        expect(memory.discover(8, context)).toEqual([other]);
    });

    it('does not expose content after it changes while fresh authorization is pending', async () => {
        const memory = store();
        const item = retained(memory);
        let release!: (decision: ItDraftMemoryAuthorizationResponse) => void;
        let sent!: ItDraftMemoryCandidate & { candidateUuid: string };
        const pending = memory.authorizeResume(
            item.bufferId,
            binding,
            (value) => {
                sent = value;
                return new Promise((resolve) => {
                    release = resolve;
                });
            },
        );
        memory.retain(
            candidate({
                snapshot: {
                    fields: { note: 'Newer local edit' },
                    step_index: 0,
                    base_ticket_version: 3,
                },
            }),
            item.bufferId,
        );
        release(response(sent));
        expect(await pending).toEqual({ status: 'changed' });
        expect(memory.discover(8, context)).toHaveLength(1);
    });

    it('invalidates old permits on fresh checks, replacement and actor purge', async () => {
        const memory = store();
        const item = retained(memory);
        const first = await memory.authorizeResume(
            item.bufferId,
            binding,
            async (value) => response(value),
        );
        const second = await memory.authorizeResume(
            item.bufferId,
            binding,
            async (value) => response(value),
        );
        if (first.status !== 'authorized' || second.status !== 'authorized')
            throw new Error('Missing proof');
        expect(memory.adopt(first.permit, binding)).toBeNull();
        memory.purgeActor(8);
        expect(memory.adopt(second.permit, binding)).toBeNull();
        expect(memory.discover(8, context)).toEqual([]);
    });

    it('ignores stale denial after a later authorization and actor purge during a request', async () => {
        const memory = store();
        const item = retained(memory);
        let release!: (decision: ItDraftMemoryAuthorizationResponse) => void;
        const first = memory.authorizeResume(
            item.bufferId,
            binding,
            () =>
                new Promise((resolve) => {
                    release = resolve;
                }),
        );
        const second = await memory.authorizeResume(
            item.bufferId,
            binding,
            async (value) => response(value),
        );
        release({ status: 'denied' });
        expect(await first).toEqual({ status: 'changed' });
        expect(second.status).toBe('authorized');
        const pending = memory.authorizeResume(
            item.bufferId,
            binding,
            () =>
                new Promise((resolve) => {
                    release = resolve;
                }),
        );
        memory.purgeActor(8);
        release({ status: 'session_expired' });
        expect(await pending).toEqual({ status: 'changed' });
        expect(memory.discover(8, context)).toEqual([]);
    });

    it('isolates caller and authorization copies while preserving the exact pending File and upload UUID', async () => {
        const memory = store();
        const file = new File(['synthetic'], 'private-local-file.txt');
        const replyContext = { purpose: 'internal_note' as const, ticketId: 1 };
        const source = candidate({
            context: replyContext,
            snapshot: {
                fields: { body: 'Original private note' },
                step_index: 0,
                base_ticket_version: 3,
            },
            pendingUpload: { file, uploadUuid: otherUuid, revision: 1 },
            outcomeUnknown: true,
        });
        const item = retained(memory, source);
        source.snapshot.fields.body = 'Changed caller';
        const authorization = await memory.authorizeResume(
            item.bufferId,
            { actorId: 8, context: replyContext },
            async (value) => {
                expect(value.snapshot.fields.body).toBe(
                    'Original private note',
                );
                const result = response(value);
                value.snapshot.fields.body = 'Changed authorization copy';
                return result;
            },
        );
        if (authorization.status !== 'authorized')
            throw new Error('Missing proof');
        const adopted = memory.adopt(authorization.permit, {
            actorId: 8,
            context: replyContext,
        });
        expect(adopted?.snapshot.fields.body).toBe('Original private note');
        expect(adopted?.pendingUpload).toEqual({
            file,
            uploadUuid: otherUuid,
            revision: 1,
        });
        expect(adopted?.pendingUpload?.file).toBe(file);
        expect(adopted?.outcomeUnknown).toBe(true);
        expect(JSON.stringify(item)).not.toContain(file.name);
    });

    it('never silently evicts an existing buffer or loses it on capacity rejection', () => {
        const memory = createItDraftMemoryStore({
            maxEntries: 1,
            maxBytes: 180,
        });
        const item = retained(memory);
        expect(memory.retain(candidate({ draftUuid: otherUuid }))).toEqual({
            status: 'capacity',
        });
        expect(
            memory.retain(
                candidate({
                    snapshot: {
                        fields: { note: 'x'.repeat(300) },
                        step_index: 0,
                    },
                }),
                item.bufferId,
            ),
        ).toEqual({ status: 'capacity' });
        expect(memory.discover(8, context)).toEqual([item]);
        expect(
            memory.discardBuffer(item.bufferId, { actorId: 9, context }),
        ).toBe(false);
        expect(memory.discardBuffer(item.bufferId, binding)).toBe(true);
        expect(memory.retain(candidate({ draftUuid: otherUuid })).status).toBe(
            'stored',
        );
    });

    it('uses secure random bytes when randomUUID is unavailable', () => {
        const descriptor = Object.getOwnPropertyDescriptor(
            globalThis.crypto,
            'randomUUID',
        );
        Object.defineProperty(globalThis.crypto, 'randomUUID', {
            configurable: true,
            value: undefined,
        });
        try {
            const item = retained(store());
            expect(item.bufferId).toMatch(
                /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
            );
        } finally {
            if (descriptor)
                Object.defineProperty(
                    globalThis.crypto,
                    'randomUUID',
                    descriptor,
                );
            else
                delete (globalThis.crypto as { randomUUID?: () => string })
                    .randomUUID;
        }
    });

    it('refuses to overwrite an unknown original outcome or reuse a buffer identity across scopes', () => {
        const memory = store();
        const item = retained(memory, candidate({ outcomeUnknown: true }));
        expect(memory.retain(candidate(), item.bufferId)).toEqual({
            status: 'frozen',
        });
        expect(memory.retain(candidate({ actorId: 9 }), item.bufferId)).toEqual(
            { status: 'scope_changed' },
        );
        expect(
            memory.retain(candidate({ draftUuid: otherUuid }), item.bufferId),
        ).toEqual({ status: 'scope_changed' });
        expect(memory.discover(8, context)).toEqual([item]);
    });

    it('retains newer typed text separately from an immutable unconfirmed save and counts both snapshots', async () => {
        const memory = store();
        const original = candidate();
        const frozen = {
            snapshot: structuredClone(original.snapshot),
            revision: 2,
        };
        const pending = candidate({
            pendingSave: frozen,
            outcomeUnknown: true,
        });
        const item = retained(memory, pending);
        const before = memory.usage().bytes;
        expect(before).toBeGreaterThan(
            new TextEncoder().encode(JSON.stringify(original.snapshot)).length,
        );
        const newer = candidate({
            snapshot: {
                fields: {
                    note: 'Newer text typed before the save failure was known',
                },
                step_index: 0,
                base_ticket_version: 3,
            },
            pendingSave: frozen,
            outcomeUnknown: true,
        });
        expect(memory.retain(newer, item.bufferId).status).toBe('stored');
        frozen.snapshot.fields.note = 'Changed caller intent';
        expect(memory.retain(newer, item.bufferId)).toEqual({
            status: 'frozen',
        });
        const authorization = await memory.authorizeResume(
            item.bufferId,
            binding,
            async (value) => response(value),
        );
        if (authorization.status !== 'authorized')
            throw new Error('Missing proof');
        const adopted = memory.adopt(authorization.permit, binding);
        expect(adopted?.snapshot.fields.note).toBe(
            'Newer text typed before the save failure was known',
        );
        expect(adopted?.pendingSave).toEqual({
            snapshot: original.snapshot,
            revision: 2,
        });
        expect(adopted?.outcomeUnknown).toBe(true);
    });

    it('requires an explicit reviewed authorization revision while preserving original retry and ticket versions', async () => {
        const memory = store();
        const original = candidate({
            outcomeUnknown: true,
            pendingSave: { snapshot: candidate().snapshot, revision: 2 },
        });
        const item = retained(memory, original);
        const currentResponse = async (
            value: ItDraftMemoryCandidate & { candidateUuid: string },
        ) => response({ ...value, authorizationRevision: 3 });
        expect(
            await memory.authorizeResume(
                item.bufferId,
                binding,
                currentResponse,
            ),
        ).toEqual({ status: 'failed' });
        const authorization = await memory.authorizeResume(
            item.bufferId,
            { ...binding, explicitlyReviewedRevision: 3 },
            async (value) => {
                expect(value.authorizationRevision).toBe(3);
                expect(value.revision).toBe(2);
                expect(value.pendingSave?.revision).toBe(2);
                return response(value);
            },
        );
        if (authorization.status !== 'authorized')
            throw new Error('Missing proof');
        expect(memory.adopt(authorization.permit, binding)).toEqual(original);
    });

    it('rejects a pending save if its extra bytes exceed capacity and keeps the previous buffer', () => {
        const memory = createItDraftMemoryStore({
            maxEntries: 1,
            maxBytes: 180,
        });
        const item = retained(memory);
        expect(
            memory.retain(
                candidate({
                    pendingSave: {
                        snapshot: candidate().snapshot,
                        revision: 2,
                    },
                    outcomeUnknown: true,
                }),
                item.bufferId,
            ),
        ).toEqual({ status: 'capacity' });
        expect(memory.discover(8, context)).toEqual([item]);
    });

    it('consumes only the acknowledged exact snapshot and explicitly purges only the selected generation/context', () => {
        const memory = store();
        retained(memory);
        const newer = retained(
            memory,
            candidate({
                snapshot: {
                    fields: { note: 'Newer unsaved note' },
                    step_index: 0,
                    base_ticket_version: 3,
                },
            }),
        );
        const generation = retained(
            memory,
            candidate({ draftUuid: otherUuid }),
        );
        const otherActor = retained(memory, candidate({ actorId: 9 }));
        memory.acknowledgeConsumed(candidate());
        expect(memory.discover(8, context)).toEqual([newer, generation]);
        memory.purgeGeneration(8, context, draftUuid);
        expect(memory.discover(8, context)).toEqual([generation]);
        memory.purgeContext(8, context);
        expect(memory.discover(8, context)).toEqual([]);
        expect(memory.discover(9, context)).toEqual([otherActor]);
        memory.clear();
        expect(memory.usage()).toEqual({ entries: 0, bytes: 0 });
    });

    it('rejects invalid fields, forbidden resolution files and absent resource bounds', () => {
        const memory = store();
        expect(
            memory.retain(
                candidate({
                    snapshot: {
                        fields: { body: 'Wrong audience field' },
                        step_index: 0,
                    },
                }),
            ),
        ).toEqual({ status: 'invalid' });
        expect(
            memory.retain(
                candidate({
                    pendingUpload: {
                        file: new File(['x'], 'x.txt'),
                        uploadUuid: otherUuid,
                        revision: 1,
                    },
                }),
            ),
        ).toEqual({ status: 'invalid' });
        expect(() =>
            createItDraftMemoryStore({ maxEntries: 0, maxBytes: 1 }),
        ).toThrow('explicit positive bounds');
        expect(memory.usage()).toEqual({ entries: 0, bytes: 0 });
    });
});
