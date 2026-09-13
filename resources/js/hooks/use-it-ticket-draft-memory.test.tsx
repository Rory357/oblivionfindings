import { act, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    draftContextKey,
    draftSnapshotKey,
    type ItDraftContext,
    type ItDraftMetadata,
    type ItDraftSnapshot,
} from './it-ticket-draft-contract';
import {
    clearItTicketDraftMemory,
    itIntakeDraftMemoryRequestIds,
    useItTicketDraftMemory,
} from './use-it-ticket-draft-memory';

vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
        isAxiosError: (value: unknown) =>
            !!value && typeof value === 'object' && 'response' in value,
    },
}));
const post = vi.mocked(axios.post);
const context: ItDraftContext = { purpose: 'public_resolution', ticketId: 1 };
const draftUuid = 'b45f88dc-709c-4254-a3ae-9b4b5992b716';

it('settles a rejected local reply before returning to its active saved-draft generation', () => {
    const scope: ItDraftContext = { purpose: 'public_reply', ticketId: 1 };
    const command = {
        actorId: 8,
        ticketId: 1,
        isInternal: false,
        requestUuid: draftUuid,
        expectedVersion: 2,
        body: 'Rejected reply',
        files: [],
    };
    const initial = options({
        context: scope,
        draft: metadata(scope),
        workingSnapshot: {
            fields: { body: command.body },
            step_index: 0,
            base_ticket_version: 2,
        },
        workingDirty: true,
        pendingComment: command,
        outcomeUnknown: true,
        settledOperationToken: 0,
    });
    const hook = renderHook(useItTicketDraftMemory, { initialProps: initial });
    hook.rerender({
        ...initial,
        pendingComment: undefined,
        outcomeUnknown: false,
        settledOperationToken: 1,
    });
    expect(hook.result.current.notices).toHaveLength(0);
    hook.unmount();
    const next = renderHook(() =>
        useItTicketDraftMemory(
            options({ context: scope, draft: metadata(scope) }),
        ),
    );
    expect(next.result.current.notices).toHaveLength(1);
    expect(next.result.current.notices[0]).toMatchObject({
        kind: 'persisted',
        hasPendingComment: false,
        outcomeUnknown: false,
    });
});
const snapshot = (note = 'The last local character!'): ItDraftSnapshot => ({
    fields: { note, notify_requester: true },
    step_index: 0,
    base_ticket_version: 2,
});
const metadata = (
    scope = context,
    changes: Partial<ItDraftMetadata> = {},
): ItDraftMetadata => ({
    draft_uuid: draftUuid,
    purpose: scope.purpose,
    context_key: draftContextKey(scope),
    audience: ['internal_note', 'technician_intake', 'ticket_edit'].includes(
        scope.purpose,
    )
        ? 'internal'
        : 'public',
    ticket_id: scope.ticketId ?? null,
    request_uuid: scope.requestUuid ?? null,
    revision: 3,
    state: 'active',
    has_content: true,
    saved_at: '2026-09-09T00:00:00Z',
    expires_at: '2026-09-11T00:00:00Z',
    base_ticket_version: 2,
    current_ticket_version: 4,
    files: { ready: 0, pending: 0, cleanup_pending: 0 },
    capabilities: {
        read: true,
        save: true,
        submit: true,
        discard: true,
        start_new: false,
    },
    blocker: null,
    ...changes,
});
const options = (
    changes: Partial<Parameters<typeof useItTicketDraftMemory>[0]> = {},
): Parameters<typeof useItTicketDraftMemory>[0] => ({
    enabled: true,
    actorId: 8,
    context,
    draft: metadata(),
    outcomeUnknown: false,
    ...changes,
});

it.each([
    'matched',
    'different revision',
    'newer text',
    'selected file',
] as const)(
    'hands an acknowledged save to its next comment without losing other work: %s',
    (variant) => {
        const scope: ItDraftContext = { purpose: 'internal_note', ticketId: 1 };
        const saved = {
            fields: { body: 'Saved investigation' },
            step_index: 0,
            base_ticket_version: 2,
        };
        const initial = options({
            context: scope,
            draft: metadata(scope),
            workingSnapshot: saved,
            workingDirty: true,
            pendingSave: { revision: 3, snapshot: saved },
            outcomeUnknown: true,
            settledOperationToken: 0,
        });
        const hook = renderHook(useItTicketDraftMemory, {
            initialProps: initial,
        });
        const files =
            variant === 'selected file'
                ? [new File(['evidence'], 'investigation.txt')]
                : [];
        const working =
            variant === 'newer text'
                ? { ...saved, fields: { body: 'Newer unsaved investigation' } }
                : saved;
        const command = {
            actorId: 8,
            ticketId: 1,
            isInternal: true,
            requestUuid: '560fda43-afaf-4a7b-afad-f928bec51111',
            expectedVersion: 2,
            body: working.fields.body,
            files,
            draft: {
                draft_uuid: draftUuid,
                draft_revision: variant === 'different revision' ? 5 : 4,
                draft_actor_user_id: 8,
            },
        };
        hook.rerender({
            ...initial,
            draft: metadata(scope, { revision: 4 }),
            pendingSave: undefined,
            savedSnapshotKey: draftSnapshotKey(saved),
            workingSnapshot: working,
            workingFiles: files,
            pendingComment: command,
            settledOperationToken: 1,
        });
        expect(hook.result.current.notices).toHaveLength(
            variant === 'matched' ? 0 : 1,
        );
        hook.unmount();
        const recovered = renderHook(() =>
            useItTicketDraftMemory(
                options({
                    context: scope,
                    draft: metadata(scope, { revision: 4 }),
                }),
            ),
        );
        const notices = recovered.result.current.notices;
        expect(notices).toHaveLength(variant === 'matched' ? 1 : 2);
        expect(
            notices.filter((notice) => notice.hasPendingComment),
        ).toHaveLength(1);
        expect(notices.every((notice) => notice.outcomeUnknown)).toBe(true);
        recovered.unmount();
    },
);

const goodTransport = () =>
    post.mockImplementation(async (url, raw) => {
        const input = raw as Record<string, unknown>;
        if (String(url).endsWith('/validate-local-candidate'))
            return {
                status: 200,
                data: {
                    candidate: {
                        kind: 'memory',
                        memory_uuid: input.memory_uuid,
                        candidate_uuid: input.candidate_uuid,
                        actor_user_id: input.actor_user_id,
                        purpose: input.purpose,
                        context_key: input.ticket_id
                            ? `ticket:${input.ticket_id}`
                            : `request:${input.request_uuid}`,
                        base_ticket_version: input.base_ticket_version ?? null,
                        current_ticket_version: input.ticket_id ? 4 : null,
                        authorized: true,
                        capabilities: { submit: !input.ticket_id },
                        blocker: input.ticket_id
                            ? {
                                  code: 'stale_ticket',
                                  message: 'Review the current ticket.',
                              }
                            : null,
                    },
                },
            };
        if (String(url).endsWith('/validate-candidate'))
            return {
                status: 200,
                data: {
                    candidate: {
                        candidate_uuid: input.candidate_uuid,
                        actor_user_id: input.actor_user_id,
                        draft_uuid: draftUuid,
                        purpose: context.purpose,
                        context_key: draftContextKey(context),
                        revision: input.expected_revision,
                        base_ticket_version: input.base_ticket_version ?? null,
                        authorized: true,
                    },
                    draft: metadata(context, {
                        revision: input.expected_revision as number,
                    }),
                },
            };
        return {
            status: 200,
            data: {
                draft: metadata(),
                payload: {
                    fields: {
                        note: 'The older saved server note',
                        notify_requester: true,
                    },
                    step_index: 0,
                },
                attachments: [],
            },
        };
    });
function leaveLocal(
    value = snapshot(),
    changes: Partial<Parameters<typeof useItTicketDraftMemory>[0]> = {},
) {
    const form = renderHook(() =>
        useItTicketDraftMemory(
            options({ workingSnapshot: value, workingDirty: true, ...changes }),
        ),
    );
    form.unmount();
}
beforeEach(() => {
    clearItTicketDraftMemory();
    post.mockReset();
    goodTransport();
});

describe('draft memory client lifecycle', () => {
    it('recovers the exact local edit while persistent drafts are disabled, using only permission validation', async () => {
        leaveLocal(snapshot(), { persistenceEnabled: false, draft: null });
        const back = renderHook(() =>
            useItTicketDraftMemory(
                options({ persistenceEnabled: false, draft: null }),
            ),
        );
        expect(back.result.current.notices[0].kind).toBe('memory');
        let recovered!: Awaited<ReturnType<typeof back.result.current.resume>>;
        await act(async () => {
            recovered = await back.result.current.resume(
                back.result.current.notices[0].bufferId,
            );
        });
        expect(recovered?.candidate.snapshot).toEqual(snapshot());
        expect(recovered?.serverResumed).toBeNull();
        expect(recovered?.localAuthorization?.capabilities.submit).toBe(false);
        expect(recovered?.localAuthorization?.current_ticket_version).toBe(4);
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe(
            '/it/drafts/validate-local-candidate',
        );
        expect(post.mock.calls[0][1]).not.toHaveProperty('draft_uuid');
    });

    it('preserves A→B→clear bindings and all original files in local intake, with opaque request discovery', async () => {
        const requestUuid = 'dfdb7f7a-8b96-4c2c-95f5-e57f23594e12';
        const intake: ItDraftContext = {
            purpose: 'requester_intake',
            requestUuid,
        };
        const files = [
            new File(['file1'], 'private-one.txt'),
            new File(['file2'], 'private-two.txt'),
        ];
        const form = renderHook(
            ({ site }) =>
                useItTicketDraftMemory(
                    options({
                        context: intake,
                        persistenceEnabled: false,
                        draft: null,
                        workingDirty: true,
                        workingFiles: files,
                        workingSnapshot: {
                            fields: { title: 'Synthetic issue', site_id: site },
                            step_index: 0,
                        },
                    }),
                ),
            { initialProps: { site: 1 as number | null } },
        );
        expect(itIntakeDraftMemoryRequestIds(8, 'requester_intake')).toEqual(
            [],
        );
        form.rerender({ site: 2 });
        form.rerender({ site: null });
        form.unmount();
        expect(itIntakeDraftMemoryRequestIds(8, 'requester_intake')).toEqual([
            requestUuid,
        ]);
        expect(itIntakeDraftMemoryRequestIds(9, 'requester_intake')).toEqual(
            [],
        );
        const back = renderHook(() =>
            useItTicketDraftMemory(
                options({
                    context: intake,
                    persistenceEnabled: false,
                    draft: null,
                }),
            ),
        );
        let recovered!: Awaited<ReturnType<typeof back.result.current.resume>>;
        await act(async () => {
            recovered = await back.result.current.resume(
                back.result.current.notices[0].bufferId,
            );
        });
        expect(post.mock.calls[0][1]).toMatchObject({
            request_uuid: requestUuid,
            fields: { site_id: null },
            bound_scopes: [{ site_id: 1 }, { site_id: 2 }],
        });
        expect(recovered?.candidate.selectedFiles).toHaveLength(2);
        expect(recovered?.candidate.selectedFiles?.[0]).toBe(files[0]);
        expect(recovered?.candidate.selectedFiles?.[1]).toBe(files[1]);
        expect(recovered?.serverResumed).toBeNull();
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('warns before losing an excessive reference history and preserves all earlier bindings', () => {
        const intake: ItDraftContext = {
            purpose: 'requester_intake',
            requestUuid: 'dfdb7f7a-8b96-4c2c-95f5-e57f23594e12',
        };
        const form = renderHook(
            ({ site }) =>
                useItTicketDraftMemory(
                    options({
                        context: intake,
                        persistenceEnabled: false,
                        draft: null,
                        workingDirty: true,
                        workingSnapshot: {
                            fields: { title: 'Synthetic issue', site_id: site },
                            step_index: 0,
                        },
                    }),
                ),
            { initialProps: { site: 1 } },
        );
        for (let site = 2; site <= 101; site++) form.rerender({ site });
        expect(form.result.current.warning).toMatch(
            /reference limit was reached/,
        );
        expect(post).not.toHaveBeenCalled();
    });
    it('retains the final mounted edit before traversal and performs no network call until explicit Resume', async () => {
        const form = renderHook(
            ({ value }) =>
                useItTicketDraftMemory(
                    options({ workingSnapshot: value, workingDirty: true }),
                ),
            { initialProps: { value: snapshot('Before') } },
        );
        form.rerender({ value: snapshot() });
        expect(post).not.toHaveBeenCalled();
        form.unmount();
        const back = renderHook(() => useItTicketDraftMemory(options()));
        expect(back.result.current.notices).toHaveLength(1);
        expect(JSON.stringify(back.result.current.notices)).not.toContain(
            'local character',
        );
        let recovered!: Awaited<ReturnType<typeof back.result.current.resume>>;
        await act(async () => {
            recovered = await back.result.current.resume(
                back.result.current.notices[0].bufferId,
            );
        });
        expect(recovered?.candidate.snapshot).toEqual(snapshot());
        expect(recovered?.candidate.snapshot.base_ticket_version).toBe(2);
        expect(recovered?.serverResumed?.draft.current_ticket_version).toBe(4);
        expect(recovered?.serverResumed?.payload.fields.note).toBe(
            'The older saved server note',
        );
        expect(post).toHaveBeenCalledTimes(2);
        expect(post.mock.calls[0][1]).toMatchObject({
            actor_user_id: 8,
            expected_revision: 3,
            fields: snapshot().fields,
            base_ticket_version: 2,
        });
        expect(back.result.current.notices).toEqual([]);
    });

    it('replaces only the mounted pre-initialization copy with the latest canonical draft buffer', async () => {
        const form = renderHook(
            ({ initialized }) =>
                useItTicketDraftMemory(
                    options({
                        draft: initialized ? metadata() : null,
                        workingSnapshot: snapshot(
                            initialized
                                ? 'Newest pending text'
                                : 'Initial text',
                        ),
                        workingDirty: true,
                        pendingSave: initialized
                            ? {
                                  snapshot: snapshot('Submitted snapshot'),
                                  revision: 3,
                              }
                            : undefined,
                        outcomeUnknown: initialized,
                    }),
                ),
            { initialProps: { initialized: false } },
        );
        form.rerender({ initialized: true });
        expect(form.result.current.notices).toEqual([]);
        form.unmount();
        const back = renderHook(() => useItTicketDraftMemory(options()));
        expect(back.result.current.notices).toHaveLength(1);
        expect(back.result.current.notices[0].kind).toBe('persisted');
        await act(async () => {
            const recovered = await back.result.current.resume(
                back.result.current.notices[0].bufferId,
            );
            expect(recovered?.candidate.snapshot.fields.note).toBe(
                'Newest pending text',
            );
            expect(recovered?.candidate.pendingSave?.snapshot.fields.note).toBe(
                'Submitted snapshot',
            );
        });
    });

    it('retains original selected files without adoption when the current form mode cannot represent them', async () => {
        const intake: ItDraftContext = {
            purpose: 'requester_intake',
            requestUuid: 'dfdb7f7a-8b96-4c2c-95f5-e57f23594e12',
        };
        leaveLocal(
            { fields: { title: 'Original issue' }, step_index: 0 },
            {
                context: intake,
                persistenceEnabled: false,
                draft: null,
                workingFiles: [new File(['original'], 'private-file.txt')],
            },
        );
        const back = renderHook(() =>
            useItTicketDraftMemory(
                options({ context: intake, acceptSelectedFiles: false }),
            ),
        );
        const id = back.result.current.notices[0].bufferId;
        await act(async () => {
            expect(await back.result.current.resume(id)).toBeNull();
        });
        expect(back.result.current.notices[0].bufferId).toBe(id);
        expect(back.result.current.warning).toMatch(
            /cannot restore the original selected files/,
        );
        expect(post).not.toHaveBeenCalled();
    });

    it('excludes live sibling buffers and makes only the departed form available', async () => {
        const first = renderHook(() =>
            useItTicketDraftMemory(
                options({ workingSnapshot: snapshot(), workingDirty: true }),
            ),
        );
        const second = renderHook(() => useItTicketDraftMemory(options()));
        expect(first.result.current.notices).toEqual([]);
        expect(second.result.current.notices).toEqual([]);
        first.unmount();
        await waitFor(() =>
            expect(second.result.current.notices).toHaveLength(1),
        );
        expect(post).not.toHaveBeenCalled();
    });

    it('preserves cleared fields when they differ from the acknowledged saved snapshot even if form dirty is false', () => {
        leaveLocal(snapshot(''), {
            workingDirty: false,
            savedSnapshotKey: draftSnapshotKey(snapshot('Previously saved')),
        });
        const back = renderHook(() => useItTicketDraftMemory(options()));
        expect(back.result.current.notices).toHaveLength(1);
    });

    it('drops an exact acknowledged mounted copy without claiming to discard the canonical draft', () => {
        const form = renderHook(
            ({ saved }) =>
                useItTicketDraftMemory(
                    options({
                        workingSnapshot: snapshot(),
                        workingDirty: true,
                        savedSnapshotKey: saved,
                    }),
                ),
            { initialProps: { saved: null as string | null } },
        );
        form.rerender({ saved: draftSnapshotKey(snapshot()) });
        form.unmount();
        const back = renderHook(() => useItTicketDraftMemory(options()));
        expect(back.result.current.notices).toEqual([]);
        expect(post).not.toHaveBeenCalled();
    });

    it('does not resurrect explicitly discarded text during immediate unmount, but retains a later deliberate edit', () => {
        const form = renderHook(
            ({ value }) =>
                useItTicketDraftMemory(
                    options({
                        workingSnapshot: snapshot(value),
                        workingDirty: true,
                        persistenceEnabled: false,
                        draft: null,
                    }),
                ),
            { initialProps: { value: 'Discard this text' } },
        );
        act(() => {
            form.result.current.clearCurrentScope();
        });
        form.unmount();
        const back = renderHook(() =>
            useItTicketDraftMemory(
                options({ persistenceEnabled: false, draft: null }),
            ),
        );
        expect(back.result.current.notices).toEqual([]);
        back.unmount();
        const next = renderHook(
            ({ value }) =>
                useItTicketDraftMemory(
                    options({
                        workingSnapshot: snapshot(value),
                        workingDirty: true,
                        persistenceEnabled: false,
                        draft: null,
                    }),
                ),
            { initialProps: { value: 'Discard this text' } },
        );
        act(() => {
            next.result.current.clearCurrentScope();
        });
        next.rerender({ value: 'A deliberate new edit' });
        next.unmount();
        const returned = renderHook(() =>
            useItTicketDraftMemory(
                options({ persistenceEnabled: false, draft: null }),
            ),
        );
        expect(returned.result.current.notices).toHaveLength(1);
    });

    it('clears only the current form on success or deliberate discard, preserving another departed editor in the same context', () => {
        leaveLocal(snapshot('Earlier independent editor'));
        const form = renderHook(() =>
            useItTicketDraftMemory(
                options({
                    workingSnapshot: snapshot('Current form to discard'),
                    workingDirty: true,
                }),
            ),
        );
        const originalId = form.result.current.notices[0].bufferId;
        act(() => {
            form.result.current.clearOwnedWork();
        });
        form.unmount();
        const back = renderHook(() => useItTicketDraftMemory(options()));
        expect(back.result.current.notices).toHaveLength(1);
        expect(back.result.current.notices[0].bufferId).toBe(originalId);
    });

    it('releases only the mounted frozen save after a definitive rejection, retaining newer edits without a previous successful save', async () => {
        leaveLocal(snapshot('Separate abandoned work'));
        const form = renderHook(
            ({ pending, settled }) =>
                useItTicketDraftMemory(
                    options({
                        workingSnapshot: snapshot('Newer editable text'),
                        workingDirty: true,
                        savedSnapshotKey: null,
                        pendingSave: pending
                            ? {
                                  snapshot: snapshot('Submitted text'),
                                  revision: 3,
                              }
                            : undefined,
                        outcomeUnknown: pending,
                        settledOperationToken: settled,
                    }),
                ),
            { initialProps: { pending: true, settled: 0 } },
        );
        form.rerender({ pending: false, settled: 1 });
        expect(form.result.current.warning).toBeNull();
        expect(form.result.current.notices).toHaveLength(1);
        form.unmount();
        const back = renderHook(() => useItTicketDraftMemory(options()));
        expect(back.result.current.notices).toHaveLength(2);
        const notice = back.result.current.notices[1];
        expect(notice.outcomeUnknown).toBe(false);
        expect(notice.hasPendingSave).toBe(false);
        await act(async () => {
            const recovered = await back.result.current.resume(notice.bufferId);
            expect(recovered?.candidate.snapshot.fields.note).toBe(
                'Newer editable text',
            );
        });
    });

    it('does not treat an older successful save as acknowledgement of a frozen operation', () => {
        const form = renderHook(
            ({ pending }) =>
                useItTicketDraftMemory(
                    options({
                        workingSnapshot: snapshot('Newer editable text'),
                        workingDirty: true,
                        savedSnapshotKey: draftSnapshotKey(
                            snapshot('Earlier acknowledged text'),
                        ),
                        pendingSave: pending
                            ? {
                                  snapshot: snapshot('Submitted text'),
                                  revision: 3,
                              }
                            : undefined,
                        outcomeUnknown: pending,
                    }),
                ),
            { initialProps: { pending: true } },
        );
        form.rerender({ pending: false });
        expect(form.result.current.warning).toMatch(/still unconfirmed/);
        form.unmount();
        const back = renderHook(() => useItTicketDraftMemory(options()));
        expect(back.result.current.notices[0].outcomeUnknown).toBe(true);
        expect(back.result.current.notices[0].hasPendingSave).toBe(true);
    });

    it('cancels only the recovery wait and ignores a late response while retaining a retryable local copy', async () => {
        leaveLocal();
        let resolve!: (value: { status: number; data: unknown }) => void;
        post.mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        const back = renderHook(() => useItTicketDraftMemory(options()));
        const id = back.result.current.notices[0].bufferId;
        let pending!: Promise<unknown>;
        act(() => {
            pending = back.result.current.resume(id);
        });
        expect(back.result.current.busy).toBe(true);
        act(() => {
            back.result.current.cancel();
        });
        expect(post.mock.calls[0][2]?.signal?.aborted).toBe(true);
        expect(back.result.current.busy).toBe(false);
        expect(back.result.current.warning).toMatch(/copy remains available/);
        await act(async () => {
            resolve({ status: 200, data: {} });
            expect(await pending).toBeNull();
        });
        expect(back.result.current.notices).toHaveLength(1);
        await act(async () => {
            expect(await back.result.current.resume(id)).not.toBeNull();
        });
    });

    it('requires exact nonce proof before the canonical read and preserves the buffer on malformed proof', async () => {
        leaveLocal();
        post.mockResolvedValueOnce({
            status: 200,
            data: { candidate: { authorized: true }, draft: metadata() },
        });
        const back = renderHook(() => useItTicketDraftMemory(options()));
        await act(async () =>
            expect(
                await back.result.current.resume(
                    back.result.current.notices[0].bufferId,
                ),
            ).toBeNull(),
        );
        expect(post).toHaveBeenCalledTimes(1);
        expect(back.result.current.notices).toHaveLength(1);
        expect(back.result.current.failure).toBe('failed');
    });

    it.each([false, true])(
        'retains unsupported %s editor fields before authorization or adoption',
        async (pendingOnly) => {
            leaveLocal(
                pendingOnly
                    ? {
                          fields: { notify_requester: true },
                          step_index: 0,
                          base_ticket_version: 2,
                      }
                    : snapshot('Unsupported note'),
                pendingOnly
                    ? {
                          pendingSave: {
                              snapshot: snapshot('Earlier pending note'),
                              revision: 3,
                          },
                          outcomeUnknown: true,
                      }
                    : {},
            );
            const back = renderHook(() =>
                useItTicketDraftMemory(
                    options({
                        acceptedFields: pendingOnly ? ['notify_requester'] : [],
                    }),
                ),
            );
            const id = back.result.current.notices[0].bufferId;
            await act(async () => {
                expect(await back.result.current.resume(id)).toBeNull();
            });
            expect(back.result.current.warning).toMatch(
                /matching ticket editor/,
            );
            expect(back.result.current.notices[0].bufferId).toBe(id);
            expect(post).not.toHaveBeenCalled();
        },
    );

    it('checks host value compatibility only after current authorization and preserves an incompatible pending snapshot', async () => {
        leaveLocal(snapshot('Allowed latest note'), {
            pendingSave: {
                snapshot: snapshot('Other editor value'),
                revision: 3,
            },
            outcomeUnknown: true,
        });
        const accepts = vi.fn(
            (value: ItDraftSnapshot) =>
                value.fields.note === 'Allowed latest note',
        );
        const back = renderHook(() =>
            useItTicketDraftMemory(options({ acceptsSnapshot: accepts })),
        );
        const id = back.result.current.notices[0].bufferId;
        post.mockResolvedValueOnce({
            status: 200,
            data: { candidate: { authorized: true } },
        });
        await act(async () => {
            expect(await back.result.current.resume(id)).toBeNull();
        });
        expect(accepts).not.toHaveBeenCalled();
        await act(async () => {
            expect(await back.result.current.resume(id)).toBeNull();
        });
        expect(accepts).toHaveBeenCalledTimes(2);
        expect(back.result.current.warning).toMatch(/matching ticket editor/);
        expect(back.result.current.notices[0].bufferId).toBe(id);
        expect(post).toHaveBeenCalledTimes(2); // malformed proof and fresh proof, no canonical read or adoption
    });

    it('rejects a generation that changes between proof and canonical resume without releasing private candidate data', async () => {
        leaveLocal();
        const transport = post.getMockImplementation()!;
        post.mockImplementation(async (url, data, config) =>
            String(url).endsWith('/resume')
                ? {
                      status: 200,
                      data: {
                          draft: metadata(context, { revision: 4 }),
                          payload: {
                              fields: { note: 'New server note' },
                              step_index: 0,
                          },
                          attachments: [],
                      },
                  }
                : transport(url, data, config),
        );
        const back = renderHook(() => useItTicketDraftMemory(options()));
        await act(async () =>
            expect(
                await back.result.current.resume(
                    back.result.current.notices[0].bufferId,
                ),
            ).toBeNull(),
        );
        expect(back.result.current.notices).toHaveLength(1);
        expect(back.result.current.failure).toBe('conflict');
    });

    it.each([401, 419])(
        'conceals expired-session recovery %s and requires a new authorized attempt',
        async (status) => {
            leaveLocal();
            post.mockRejectedValueOnce({ response: { status } });
            const session = vi.fn();
            const back = renderHook(() =>
                useItTicketDraftMemory(options({ onSessionExpired: session })),
            );
            const id = back.result.current.notices[0].bufferId;
            await act(async () =>
                expect(await back.result.current.resume(id)).toBeNull(),
            );
            expect(back.result.current.failure).toBe('session_expired');
            expect(session).toHaveBeenCalledOnce();
            expect(back.result.current.notices).toHaveLength(1);
            await act(async () =>
                expect(await back.result.current.resume(id)).not.toBeNull(),
            );
        },
    );

    it('purges revoked context and removes another actor’s buffers on actor change', async () => {
        leaveLocal();
        post.mockRejectedValueOnce({ response: { status: 403 } });
        const lost = vi.fn();
        const back = renderHook(() =>
            useItTicketDraftMemory(options({ onAccessLost: lost })),
        );
        await act(async () =>
            expect(
                await back.result.current.resume(
                    back.result.current.notices[0].bufferId,
                ),
            ).toBeNull(),
        );
        expect(lost).toHaveBeenCalledOnce();
        expect(back.result.current.failure).toBe('access_denied');
        expect(back.result.current.notices).toEqual([]);
        back.unmount();
        leaveLocal();
        const newActor = renderHook(
            ({ actorId }) => useItTicketDraftMemory(options({ actorId })),
            { initialProps: { actorId: 9 } },
        );
        expect(newActor.result.current.notices).toEqual([]);
        newActor.rerender({ actorId: 8 });
        expect(newActor.result.current.notices).toEqual([]);
    });

    it('does not let an older still-mounted actor republish or restore content after another actor becomes current', () => {
        const old = renderHook(() =>
            useItTicketDraftMemory(
                options({
                    workingSnapshot: snapshot('Old actor content'),
                    workingDirty: true,
                }),
            ),
        );
        const current = renderHook(() =>
            useItTicketDraftMemory(options({ actorId: 9 })),
        );
        expect(old.result.current.notices).toEqual([]);
        expect(current.result.current.notices).toEqual([]);
        old.unmount();
        current.unmount();
        const back = renderHook(() => useItTicketDraftMemory(options()));
        expect(back.result.current.notices).toEqual([]);
    });

    it('ignores an old response after scope change and aborts its wait', async () => {
        leaveLocal();
        let resolve!: (value: { status: number; data: unknown }) => void;
        post.mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        const back = renderHook(
            ({ actorId }) => useItTicketDraftMemory(options({ actorId })),
            { initialProps: { actorId: 8 } },
        );
        let recovery!: Promise<unknown>;
        act(() => {
            recovery = back.result.current.resume(
                back.result.current.notices[0].bufferId,
            );
        });
        back.rerender({ actorId: 9 });
        expect(post.mock.calls[0][2]?.signal?.aborted).toBe(true);
        await act(async () => {
            resolve({ status: 200, data: {} });
            expect(await recovery).toBeNull();
        });
        expect(back.result.current.notices).toEqual([]);
        expect(back.result.current.warning).toBeNull();
    });

    it('shows capacity rejection while mounted without evicting older browser work', () => {
        for (let index = 0; index < 20; index++)
            leaveLocal(snapshot(`Prior work ${index}`));
        const current = renderHook(() =>
            useItTicketDraftMemory(
                options({
                    workingSnapshot: snapshot('Twenty-first'),
                    workingDirty: true,
                }),
            ),
        );
        expect(current.result.current.warning).toMatch(/memory is full/);
        act(() =>
            expect(current.result.current.ensureLatestRetained()).toMatchObject(
                { status: 'blocked', reason: 'capacity' },
            ),
        );
        expect(current.result.current.notices).toHaveLength(20);
        act(() => {
            expect(
                current.result.current.discardLocal(
                    current.result.current.notices[0].bufferId,
                ),
            ).toBe(true);
        });
        expect(current.result.current.warning).toBeNull();
        expect(current.result.current.notices).toHaveLength(19);
        act(() =>
            expect(current.result.current.ensureLatestRetained()).toEqual({
                status: 'retained',
            }),
        );
    });

    it('does not recover while another canonical request owns the client and does not write during unmount', async () => {
        leaveLocal();
        const back = renderHook(() =>
            useItTicketDraftMemory(options({ canRecover: false })),
        );
        await act(async () =>
            expect(
                await back.result.current.resume(
                    back.result.current.notices[0].bufferId,
                ),
            ).toBeNull(),
        );
        back.unmount();
        expect(post).not.toHaveBeenCalled();
    });
});
