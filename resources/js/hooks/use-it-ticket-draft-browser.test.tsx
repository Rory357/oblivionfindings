import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    draftContextKey,
    type ItDraftContext,
    type ItDraftMetadata,
    type ItDraftSnapshot,
} from './it-ticket-draft-contract';
import { useItTicketDraft } from './use-it-ticket-draft';
import { clearItTicketDraftMemory } from './use-it-ticket-draft-memory';

const context: ItDraftContext = { purpose: 'public_resolution', ticketId: 9 };
const uuid = '9c3e9caa-4bc9-41ba-8b90-bf654120f167';

it('a consumed reply preserves newer local files even when the text and base version match its submitted snapshot', async () => {
    const replyContext: ItDraftContext = {
        purpose: 'public_reply',
        ticketId: 9,
    };
    const original = new File(['original'], 'original.txt');
    const newer = new File(['newer'], 'newer.txt');
    const command = {
        actorId: 11,
        ticketId: 9,
        requestUuid: uuid,
        isInternal: false,
        expectedVersion: 2,
        body: 'Unchanged text',
        files: [original],
        draft: {
            draft_uuid: '44aa2528-ec15-44ed-a984-b3c2bde3fb87',
            draft_revision: 3,
            draft_actor_user_id: 11,
        },
    };
    const sent = {
        fields: { body: command.body },
        step_index: 0,
        base_ticket_version: 2,
    };
    const working = {
        workingSnapshot: sent,
        workingDirty: true,
        workingFiles: [newer],
        workingPendingComment: command,
        workingSettledOperationToken: 0,
    };
    const base = { enabled: false, actorId: 11, context: replyContext };
    const first = renderHook(() => useItTicketDraft({ ...base, ...working }));
    first.unmount();
    const back = renderHook(
        (options: Partial<Parameters<typeof useItTicketDraft>[0]>) =>
            useItTicketDraft({ ...base, ...options }),
        { initialProps: {} },
    );
    await act(async () => {
        await back.result.current.resumeMemory(
            back.result.current.memoryNotices[0].bufferId,
        );
    });
    back.rerender(working);
    expect(
        back.result.current.registerRecoveredSubmission(sent, command.draft),
    ).toBe(true);
    act(() => {
        expect(
            back.result.current.acknowledgeConsumed(
                {
                    draft_uuid: command.draft.draft_uuid,
                    submitted_revision: 3,
                    revision: 4,
                    state: 'consumed',
                },
                sent,
                { preserveLocal: true },
            ),
        ).toBe(false);
    });
    back.rerender({
        ...working,
        workingPendingComment: null,
        workingSettledOperationToken: 1,
    });
    back.unmount();
    const next = renderHook(() => useItTicketDraft(base));
    expect(next.result.current.memoryNotices).toHaveLength(1);
    let restored: Awaited<ReturnType<typeof next.result.current.resumeMemory>>;
    await act(async () => {
        restored = await next.result.current.resumeMemory(
            next.result.current.memoryNotices[0].bufferId,
        );
    });
    expect(restored!.files).toHaveLength(1);
    expect(restored!.files[0]).toBe(newer);
    expect(restored!.snapshot).toEqual(sent);
    expect(restored!.pendingComment).toBeUndefined();
    expect(restored!.outcomeUnknown).toBe(false);
});

it('recovers a pending reply without requiring its already-consumed saved draft and preserves failed command adoption', async () => {
    const replyContext: ItDraftContext = {
        purpose: 'public_reply',
        ticketId: 9,
    };
    const file = new File(['retained'], 'reply.txt');
    const command = {
        actorId: 11,
        ticketId: 9,
        requestUuid: uuid,
        isInternal: false,
        expectedVersion: 2,
        body: 'Original submitted reply',
        files: [file],
        draft: {
            draft_uuid: '44aa2528-ec15-44ed-a984-b3c2bde3fb87',
            draft_revision: 3,
            draft_actor_user_id: 11,
        },
    };
    const sent = {
        fields: { body: command.body },
        step_index: 0,
        base_ticket_version: 2,
    };
    vi.mocked(axios.request).mockResolvedValue({
        status: 200,
        data: {
            draft: {
                ...metadata(),
                purpose: 'public_reply',
                state: 'consumed',
                capabilities: { ...metadata().capabilities, read: false },
            },
        },
    });
    const first = renderHook(() =>
        useItTicketDraft({
            enabled: true,
            actorId: 11,
            context: replyContext,
            workingSnapshot: sent,
            workingPendingComment: command,
        }),
    );
    await act(async () => {});
    first.unmount();
    const back = renderHook(
        (props: {
            workingPendingComment?: typeof command;
            workingSnapshot?: typeof sent;
        }) =>
            useItTicketDraft({
                enabled: false,
                actorId: 11,
                context: replyContext,
                ...props,
            }),
        { initialProps: {} },
    );
    expect(back.result.current.memoryNotices).toHaveLength(1);
    let restored: Awaited<ReturnType<typeof back.result.current.resumeMemory>>;
    await act(async () => {
        restored = await back.result.current.resumeMemory(
            back.result.current.memoryNotices[0].bufferId,
        );
    });
    expect(restored!.pendingComment?.files[0]).toBe(file);
    expect(restored!.pendingComment?.draft).toEqual(command.draft);
    expect(axios.post).toHaveBeenCalledTimes(1);
    expect(vi.mocked(axios.post).mock.calls[0][0]).toBe(
        '/it/drafts/validate-local-candidate',
    );
    // Host access recheck can fail: until adoption, the same browser copy is still discoverable.
    expect(back.result.current.memoryNotices).toHaveLength(1);
    back.unmount();
    const retry = renderHook(
        (props: {
            workingPendingComment?: typeof command;
            workingSnapshot?: typeof sent;
        }) =>
            useItTicketDraft({
                enabled: false,
                actorId: 11,
                context: replyContext,
                ...props,
            }),
        { initialProps: {} },
    );
    await act(async () => {
        await retry.result.current.resumeMemory(
            retry.result.current.memoryNotices[0].bufferId,
        );
    });
    retry.rerender({ workingPendingComment: command, workingSnapshot: sent });
    expect(retry.result.current.memoryNotices).toHaveLength(0);
    expect(
        retry.result.current.registerRecoveredSubmission(
            { ...sent, fields: { body: 'Newer text' } },
            command.draft,
        ),
    ).toBe(false);
    expect(
        retry.result.current.registerRecoveredSubmission(sent, {
            ...command.draft,
            draft_revision: 4,
        }),
    ).toBe(false);
    expect(
        retry.result.current.registerRecoveredSubmission(sent, command.draft),
    ).toBe(true);
    act(() => {
        expect(
            retry.result.current.acknowledgeConsumed(
                {
                    draft_uuid: command.draft.draft_uuid,
                    submitted_revision: 3,
                    revision: 4,
                    state: 'consumed',
                },
                { ...sent, fields: { body: 'Newer text' } },
            ),
        ).toBe(false);
    });
    retry.unmount();
    const retained = renderHook(() =>
        useItTicketDraft({
            enabled: false,
            actorId: 11,
            context: replyContext,
        }),
    );
    expect(retained.result.current.memoryNotices).toHaveLength(1);
});
const snapshot = (note = 'Latest private browser note'): ItDraftSnapshot => ({
    fields: { note, notify_requester: true },
    step_index: 0,
    base_ticket_version: 2,
});
const metadata = (revision = 3): ItDraftMetadata => ({
    draft_uuid: uuid,
    purpose: context.purpose,
    context_key: draftContextKey(context),
    audience: 'public',
    ticket_id: 9,
    request_uuid: null,
    revision,
    state: 'active',
    has_content: true,
    saved_at: '2026-09-09T10:00:00Z',
    expires_at: '2026-09-12T10:00:00Z',
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
});
beforeEach(() => {
    clearItTicketDraftMemory();
    vi.spyOn(axios, 'request').mockResolvedValue({
        status: 200,
        data: { draft: metadata() },
    });
    vi.spyOn(axios, 'post').mockImplementation(async (url, raw) => {
        const body = raw as Record<string, unknown>;
        if (String(url).endsWith('/validate-local-candidate'))
            return {
                status: 200,
                data: {
                    candidate: {
                        kind: 'memory',
                        memory_uuid: body.memory_uuid,
                        candidate_uuid: body.candidate_uuid,
                        actor_user_id: body.actor_user_id,
                        purpose: body.purpose,
                        context_key: body.ticket_id
                            ? `ticket:${body.ticket_id}`
                            : `request:${body.request_uuid}`,
                        base_ticket_version: body.base_ticket_version ?? null,
                        current_ticket_version: body.ticket_id ? 4 : null,
                        authorized: true,
                        capabilities: { submit: !body.ticket_id },
                        blocker: body.ticket_id
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
                    draft: metadata(Number(body.expected_revision)),
                    candidate: {
                        candidate_uuid: body.candidate_uuid,
                        actor_user_id: body.actor_user_id,
                        draft_uuid: uuid,
                        purpose: context.purpose,
                        context_key: draftContextKey(context),
                        revision: body.expected_revision,
                        base_ticket_version: body.base_ticket_version,
                        authorized: true,
                    },
                },
            };
        return {
            status: 200,
            data: {
                draft: metadata(),
                payload: snapshot('Older saved note'),
                attachments: [],
            },
        };
    });
});
afterEach(() => {
    cleanup();
    clearItTicketDraftMemory();
    vi.restoreAllMocks();
});

describe('Shared draft browser handoff', () => {
    it('recovers direct File objects and unsaved text with persistence disabled after explicit fresh authorization', async () => {
        const intake: ItDraftContext = {
            purpose: 'requester_intake',
            requestUuid: 'd2285a40-65a4-442d-b33d-87b1ba708902',
        };
        const value = {
            fields: { title: 'Last unsubmitted character!', site_id: 7 },
            step_index: 0,
        };
        const file = new File(['Private bytes'], 'local-proof.txt', {
            type: 'text/plain',
        });
        const first = renderHook(() =>
            useItTicketDraft({
                enabled: false,
                actorId: 11,
                context: intake,
                workingSnapshot: value,
                workingDirty: true,
                workingFiles: [file],
            }),
        );
        expect(axios.request).not.toHaveBeenCalled();
        expect(axios.post).not.toHaveBeenCalled();
        first.unmount();
        const back = renderHook(() =>
            useItTicketDraft({
                enabled: false,
                actorId: 11,
                context: intake,
                workingSnapshot: { fields: {}, step_index: 0 },
                workingDirty: false,
            }),
        );
        expect(back.result.current.memoryNotices).toHaveLength(1);
        expect(back.result.current.memoryBlocked).toBe(true);
        expect(JSON.stringify(back.result.current.memoryNotices)).not.toContain(
            'Private',
        );
        await act(async () => {
            const restored = await back.result.current.resumeMemory(
                back.result.current.memoryNotices[0].bufferId,
            );
            expect(restored?.snapshot).toEqual(value);
            expect(restored?.files[0]).toBe(file);
        });
        expect(axios.request).not.toHaveBeenCalled();
        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(back.result.current.draft).toBeNull();
        expect(back.result.current.memoryBlocked).toBe(false);
    });

    it('restores newer text separately from an unconfirmed saved draft command and retries its exact original revision', async () => {
        const first = renderHook(
            ({ value }) =>
                useItTicketDraft({
                    enabled: true,
                    actorId: 11,
                    context,
                    workingSnapshot: value,
                    workingDirty: true,
                }),
            { initialProps: { value: snapshot('Original submitted note') } },
        );
        await waitFor(() =>
            expect(first.result.current.state).toBe('available'),
        );
        vi.mocked(axios.request).mockResolvedValueOnce({
            status: 200,
            data: {
                draft: metadata(),
                payload: snapshot('Saved baseline'),
                attachments: [],
            },
        });
        await act(async () => {
            await first.result.current.resume();
        });
        vi.mocked(axios.request).mockRejectedValueOnce({
            isAxiosError: true,
            response: { status: 503 },
        });
        await act(async () => {
            await first.result.current.save(
                snapshot('Original submitted note'),
            );
        });
        first.rerender({ value: snapshot('Newer text typed during recovery') });
        first.unmount();
        const back = renderHook(() =>
            useItTicketDraft({ enabled: true, actorId: 11, context }),
        );
        await waitFor(() => expect(back.result.current.busy).toBe(false));
        expect(back.result.current.memoryNotices).toHaveLength(1);
        await act(async () => {
            const restored = await back.result.current.resumeMemory(
                back.result.current.memoryNotices[0].bufferId,
            );
            expect(restored?.snapshot.fields.note).toBe(
                'Newer text typed during recovery',
            );
            expect(restored?.snapshot.base_ticket_version).toBe(2);
            expect(restored?.canonicalOutcomeUnknown).toBe(false);
        });
        expect(back.result.current.state).toBe('outcome_unknown');
        expect(back.result.current.retryable).toBe(true);
        vi.mocked(axios.request).mockResolvedValueOnce({
            status: 200,
            data: { draft: metadata(4) },
        });
        await act(async () => {
            expect(await back.result.current.retry()).toBe(true);
        });
        expect(
            vi.mocked(axios.request).mock.calls.at(-1)?.[0].data,
        ).toMatchObject({
            expected_revision: 3,
            base_ticket_version: 2,
            fields: { note: 'Original submitted note' },
        });
        expect(
            back.result.current.isSaved(
                snapshot('Newer text typed during recovery'),
            ),
        ).toBe(false);
    });

    it('honours a false submission capability even when the authorized proof has no blocker message', async () => {
        const first = renderHook(() =>
            useItTicketDraft({
                enabled: false,
                actorId: 11,
                context,
                workingSnapshot: snapshot(),
                workingDirty: true,
            }),
        );
        first.unmount();
        const transport = vi.mocked(axios.post).getMockImplementation()!;
        vi.mocked(axios.post).mockImplementation(async (url, data, config) => {
            const result = await transport(url, data, config);
            const proof = result as {
                data: { candidate: Record<string, unknown> };
            };
            proof.data.candidate.blocker = null;
            return result;
        });
        const back = renderHook(() =>
            useItTicketDraft({ enabled: false, actorId: 11, context }),
        );
        await act(async () => {
            await back.result.current.resumeMemory(
                back.result.current.memoryNotices[0].bufferId,
            );
        });
        expect(back.result.current.memoryBlocked).toBe(true);
        expect(back.result.current.browserBlocker?.message).toContain(
            'cannot currently be submitted',
        );
    });

    it('keeps an unknown canonical submission blocked until deliberate review of a freshly authorized version', async () => {
        const first = renderHook(() =>
            useItTicketDraft({
                enabled: false,
                actorId: 11,
                context,
                workingSnapshot: snapshot(),
                workingDirty: true,
                workingOutcomeUnknown: true,
            }),
        );
        first.unmount();
        const back = renderHook(() =>
            useItTicketDraft({ enabled: false, actorId: 11, context }),
        );
        await act(async () => {
            expect(
                (
                    await back.result.current.resumeMemory(
                        back.result.current.memoryNotices[0].bufferId,
                    )
                )?.canonicalOutcomeUnknown,
            ).toBe(true);
        });
        expect(back.result.current.memoryBlocked).toBe(true);
        act(() => {
            expect(back.result.current.acknowledgeReviewedBrowserWork(3)).toBe(
                false,
            );
        });
        act(() => {
            expect(back.result.current.acknowledgeReviewedBrowserWork(5)).toBe(
                true,
            );
        });
        expect(back.result.current.memoryBlocked).toBe(false);
        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(axios.request).not.toHaveBeenCalled();
    });
});
