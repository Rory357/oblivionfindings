import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    readDraftMetadata,
    readDraftPayload,
    type ItDraftContext,
    type ItDraftMetadata,
    type ItDraftSnapshot,
} from './it-ticket-draft-contract';
import { useItTicketDraft } from './use-it-ticket-draft';
import { clearItTicketDraftMemory } from './use-it-ticket-draft-memory';

const requestUuid = 'd2285a40-65a4-442d-b33d-87b1ba708902';
const uuid = '9c3e9caa-4bc9-41ba-8b90-bf654120f167';
const nextUuid = 'f024eb01-fa17-4cdb-a057-179d056973f6';
const context: ItDraftContext = { purpose: 'requester_intake', requestUuid };
const snapshot = (title = 'Private proposal'): ItDraftSnapshot => ({
    fields: { title, site_id: 7 },
    step_index: 1,
});
function metadata(overrides: Partial<ItDraftMetadata> = {}): ItDraftMetadata {
    return {
        draft_uuid: uuid,
        purpose: context.purpose,
        context_key: `request:${requestUuid}`,
        audience: 'public',
        ticket_id: null,
        request_uuid: requestUuid,
        revision: 0,
        state: 'active',
        has_content: false,
        saved_at: null,
        expires_at: '2026-10-01T00:00:00.000Z',
        base_ticket_version: null,
        current_ticket_version: null,
        files: { ready: 0, pending: 0, cleanup_pending: 0 },
        capabilities: {
            read: true,
            save: true,
            submit: true,
            discard: true,
            start_new: false,
        },
        blocker: null,
        ...overrides,
    };
}
const response = (draft = metadata(), extra: Record<string, unknown> = {}) => ({
    status: 200,
    data: { draft, ...extra },
});
const failure = (status?: number, data: unknown = {}) => ({
    isAxiosError: true,
    response: status ? { status, data } : undefined,
});
function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (error: unknown) => void;
    const promise = new Promise<T>((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { resolve, reject, promise };
}
async function mounted(initial = metadata()) {
    vi.mocked(axios.request).mockResolvedValueOnce(response(initial));
    const hook = renderHook(() =>
        useItTicketDraft({ enabled: true, actorId: 11, context }),
    );
    await waitFor(() => expect(hook.result.current.busy).toBe(false));
    return hook;
}

describe('IT draft response boundary', () => {
    it('normalizes PHP empty fields, numeric IDs and booleans without accepting another audience or arbitrary fields', () => {
        expect(
            readDraftPayload({ fields: [], step_index: 0 }, 'public_reply'),
        ).toEqual({ fields: {}, step_index: 0 });
        expect(
            readDraftPayload(
                {
                    fields: {
                        site_id: '7',
                        is_organisation_wide: 0,
                        watchers: ['2', '3'],
                    },
                    step_index: 2,
                },
                'technician_intake',
            ),
        ).toEqual({
            fields: {
                site_id: 7,
                is_organisation_wide: false,
                watchers: [2, 3],
            },
            step_index: 2,
        });
        expect(
            readDraftPayload(
                {
                    fields: { body: 'private', is_internal: true },
                    step_index: 0,
                },
                'public_reply',
            ),
        ).toBeNull();
        expect(
            readDraftMetadata(metadata({ audience: 'internal' }), context),
        ).toBeNull();
        expect(
            readDraftMetadata(
                metadata({
                    blocker: {
                        code: 'request_committed',
                        message: 'Saved',
                        recovery_url: 'https://external.invalid',
                    },
                }),
                context,
            ),
        ).toBeNull();
    });
});

describe('useItTicketDraft', () => {
    beforeEach(() => {
        clearItTicketDraftMemory();
        vi.spyOn(axios, 'request');
    });
    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('makes no requests or browser content storage while disabled', async () => {
        const stored = vi.spyOn(Storage.prototype, 'setItem');
        const { result } = renderHook(() =>
            useItTicketDraft({ actorId: 11, context }),
        );
        await act(async () => {
            expect(await result.current.check()).toBeNull();
            expect(await result.current.save(snapshot())).toBe(false);
        });
        expect(result.current.state).toBe('disabled');
        expect(axios.request).not.toHaveBeenCalled();
        expect(stored).not.toHaveBeenCalled();
    });

    it('checks metadata without hydration and requires explicit resume before overwriting existing work', async () => {
        const saved = metadata({ revision: 3, has_content: true });
        const { result } = await mounted(saved);
        expect(result.current.state).toBe('available');
        expect(result.current.reviewed).toBeNull();
        expect(result.current.attachments).toEqual([]);
        expect(vi.mocked(axios.request).mock.calls[0][0]).toMatchObject({
            url: '/it/drafts/context',
            data: {
                actor_user_id: 11,
                purpose: 'requester_intake',
                request_uuid: requestUuid,
            },
        });
        await act(async () => {
            expect(await result.current.save(snapshot())).toBe(false);
        });
        expect(axios.request).toHaveBeenCalledTimes(1);
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(saved, {
                payload: snapshot('Saved text'),
                attachments: [],
            }),
        );
        await act(async () => {
            expect((await result.current.resume())?.payload.fields.title).toBe(
                'Saved text',
            );
        });
        expect(result.current.isSaved(snapshot('Saved text'))).toBe(true);
    });

    it('freezes an exact save retry and never labels newer local text saved', async () => {
        const { result } = await mounted();
        const original = snapshot();
        vi.mocked(axios.request).mockRejectedValueOnce(failure());
        await act(async () => {
            expect(await result.current.save(original)).toBe(false);
        });
        original.fields.title = 'Newer private work';
        expect(result.current.state).toBe('outcome_unknown');
        expect(result.current.retryable).toBe(true);
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(metadata({ revision: 1, has_content: true })),
        );
        await act(async () => {
            expect(await result.current.retry()).toBe(true);
        });
        const first = vi.mocked(axios.request).mock.calls[1][0];
        const second = vi.mocked(axios.request).mock.calls[2][0];
        expect(second.data).toEqual(first.data);
        expect(second.data).toMatchObject({
            expected_revision: 0,
            fields: { title: 'Private proposal' },
        });
        expect(result.current.isSaved(snapshot())).toBe(true);
        expect(result.current.isSaved(original)).toBe(false);
        expect(result.current.submissionReference(original)).toBeNull();
    });

    it('does not adopt a stale revision until saved content has been explicitly reviewed', async () => {
        const { result } = await mounted();
        const current = metadata({ revision: 2, has_content: true });
        vi.mocked(axios.request).mockRejectedValueOnce(
            failure(409, { code: 'draft_conflict', current }),
        );
        await act(async () => {
            await result.current.save(snapshot());
        });
        expect(result.current.draft?.revision).toBe(0);
        expect(result.current.current?.revision).toBe(2);
        await act(async () => {
            expect(await result.current.retry()).toBe(false);
            expect(await result.current.save(snapshot())).toBe(false);
        });
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(current, {
                payload: snapshot('Other editor'),
                attachments: [],
            }),
        );
        await act(async () => {
            await result.current.review();
        });
        expect(result.current.draft?.revision).toBe(0);
        expect(result.current.reviewed?.payload.fields.title).toBe(
            'Other editor',
        );
        const calls = vi.mocked(axios.request).mock.calls.length;
        act(() => {
            expect(result.current.adoptReviewed()).toBe(true);
        });
        expect(axios.request).toHaveBeenCalledTimes(calls);
        expect(result.current.draft?.revision).toBe(2);
        expect(result.current.isSaved(snapshot())).toBe(false);
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(metadata({ revision: 3, has_content: true })),
        );
        await act(async () => {
            await result.current.save(snapshot());
        });
        expect(
            vi.mocked(axios.request).mock.calls.at(-1)?.[0].data,
        ).toMatchObject({ expected_revision: 2, fields: snapshot().fields });
    });

    it('rejects HTML or a mismatched generation instead of announcing a save', async () => {
        const { result } = await mounted();
        vi.mocked(axios.request).mockResolvedValueOnce({
            status: 200,
            data: '<html>Sign in</html>',
        });
        await act(async () => {
            expect(await result.current.save(snapshot())).toBe(false);
        });
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(metadata({ draft_uuid: nextUuid, revision: 1 })),
        );
        await act(async () => {
            expect(await result.current.retry()).toBe(false);
        });
        expect(result.current.state).toBe('outcome_unknown');
        expect(result.current.isSaved(snapshot())).toBe(false);
    });

    it('retains exact pending work across session expiry and requires a current actor check before retry', async () => {
        const { result } = await mounted();
        vi.mocked(axios.request).mockRejectedValueOnce(failure(419));
        await act(async () => {
            await result.current.save(snapshot());
        });
        expect(result.current.state).toBe('session_expired');
        await act(async () => {
            expect(await result.current.retry()).toBe(false);
        });
        vi.mocked(axios.request).mockResolvedValueOnce(response());
        await act(async () => {
            await result.current.check();
        });
        expect(result.current.retryable).toBe(true);
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(metadata({ revision: 1, has_content: true })),
        );
        await act(async () => {
            expect(await result.current.retry()).toBe(true);
        });
        expect(
            vi.mocked(axios.request).mock.calls.at(-1)?.[0].data,
        ).toMatchObject({ actor_user_id: 11, expected_revision: 0 });
    });

    it('conceals a previously reviewed server payload when the session expires', async () => {
        const { result } = await mounted(
            metadata({ revision: 2, has_content: true }),
        );
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(metadata({ revision: 2, has_content: true }), {
                payload: snapshot('Private review'),
                attachments: [],
            }),
        );
        await act(async () => {
            await result.current.review();
        });
        expect(result.current.reviewed?.payload.fields.title).toBe(
            'Private review',
        );
        vi.mocked(axios.request).mockRejectedValueOnce(failure(419));
        await act(async () => {
            await result.current.check();
        });
        expect(result.current.state).toBe('session_expired');
        expect(result.current.reviewed).toBeNull();
        expect(result.current.current).toBeNull();
    });

    it('purges private client state on permission loss and ignores late responses from an old actor', async () => {
        const pending = deferred<ReturnType<typeof response>>();
        vi.mocked(axios.request)
            .mockReturnValueOnce(pending.promise)
            .mockResolvedValueOnce(response());
        const lost = vi.fn();
        const { result, rerender } = renderHook(
            ({ actorId }) =>
                useItTicketDraft({
                    enabled: true,
                    actorId,
                    context,
                    onAccessLost: lost,
                }),
            { initialProps: { actorId: 11 } },
        );
        rerender({ actorId: 12 });
        await waitFor(() => expect(result.current.state).toBe('ready'));
        await act(async () => {
            pending.resolve(
                response(metadata({ revision: 9, has_content: true })),
            );
            await pending.promise;
        });
        expect(result.current.draft?.revision).toBe(0);
        vi.mocked(axios.request).mockRejectedValueOnce(failure(403));
        await act(async () => {
            await result.current.save(snapshot());
        });
        expect(result.current.state).toBe('access_denied');
        expect(result.current.draft).toBeNull();
        expect(result.current.attachments).toEqual([]);
        expect(lost).toHaveBeenCalledOnce();
    });

    it('cancels the wait without forgetting a potentially committed mutation', async () => {
        const { result } = await mounted();
        vi.mocked(axios.request).mockImplementationOnce(
            (config) =>
                new Promise((_resolve, reject) =>
                    config.signal?.addEventListener?.('abort', () =>
                        reject(failure()),
                    ),
                ),
        );
        let saving!: Promise<boolean>;
        act(() => {
            saving = result.current.save(snapshot());
        });
        await act(async () => {
            result.current.cancel();
            await saving;
        });
        expect(result.current.state).toBe('outcome_unknown');
        expect(result.current.retryable).toBe(true);
    });

    it('reconciles a lost start-new acknowledgement by context without replaying into a new generation', async () => {
        const ended = metadata({
            revision: 4,
            state: 'discarded',
            capabilities: {
                read: false,
                save: false,
                submit: false,
                discard: false,
                start_new: true,
            },
        });
        const { result } = await mounted(ended);
        vi.mocked(axios.request).mockRejectedValueOnce(failure());
        await act(async () => {
            expect(await result.current.startNew()).toBe(false);
        });
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(metadata({ draft_uuid: nextUuid, revision: 5 })),
        );
        await act(async () => {
            expect(await result.current.retry()).toBe(false);
        });
        expect(result.current.state).toBe('available');
        expect(result.current.draft?.draft_uuid).toBe(nextUuid);
        expect(
            vi
                .mocked(axios.request)
                .mock.calls.filter(([config]) =>
                    config.url?.endsWith('/start-new'),
                ),
        ).toHaveLength(1);
        await act(async () => {
            expect(await result.current.save(snapshot())).toBe(false);
        });
    });

    it('keeps a visible warning when starting a new generation leaves old file cleanup pending', async () => {
        const { result } = await mounted(
            metadata({
                state: 'discarded',
                revision: 4,
                capabilities: {
                    read: false,
                    save: false,
                    submit: false,
                    discard: false,
                    start_new: true,
                },
            }),
        );
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(
                metadata({
                    draft_uuid: nextUuid,
                    revision: 5,
                    cleanup: { deleted: 0, failed: 1 },
                }),
            ),
        );
        await act(async () => {
            expect(await result.current.startNew()).toBe(true);
        });
        expect(result.current.state).toBe('ready');
        expect(result.current.message).toContain(
            'Previous private-file cleanup is still pending',
        );
    });

    it('rejects a later revision from a concurrent command and preserves the original base ticket version', async () => {
        const ticketContext: ItDraftContext = {
            purpose: 'public_resolution',
            ticketId: 9,
        };
        const ticketDraft = metadata({
            purpose: 'public_resolution',
            ticket_id: 9,
            request_uuid: null,
            context_key: 'ticket:9',
            base_ticket_version: 3,
            current_ticket_version: 4,
        });
        vi.mocked(axios.request).mockResolvedValueOnce(response(ticketDraft));
        const { result } = renderHook(() =>
            useItTicketDraft({
                enabled: true,
                actorId: 11,
                context: ticketContext,
            }),
        );
        await waitFor(() => expect(result.current.state).toBe('ready'));
        vi.mocked(axios.request).mockResolvedValueOnce(
            response({ ...ticketDraft, revision: 4 }),
        );
        await act(async () => {
            expect(
                await result.current.save({
                    fields: { note: 'Resolved', notify_requester: false },
                    step_index: 0,
                    base_ticket_version: 3,
                }),
            ).toBe(false);
        });
        expect(result.current.state).toBe('conflict');
        expect(result.current.draft?.revision).toBe(0);
        expect(
            vi.mocked(axios.request).mock.calls.at(-1)?.[0].data,
        ).toMatchObject({ base_ticket_version: 3, expected_revision: 0 });
    });

    it('rejects disallowed names and oversize selections without losing saved text or files, then accepts a corrected name', async () => {
        const existing = {
            id: 91,
            upload_uuid: requestUuid,
            name: 'existing.txt',
            mime: 'text/plain',
            size: 5,
            state: 'ready',
            download_url: '/it/attachments/91',
        };
        const saved = metadata({
            revision: 3,
            has_content: true,
            files: { ready: 1, pending: 0, cleanup_pending: 0 },
        });
        const { result } = await mounted(saved);
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(saved, { payload: snapshot(), attachments: [existing] }),
        );
        await act(async () => {
            await result.current.resume();
        });
        expect(result.current.isSaved(snapshot())).toBe(true);
        vi.mocked(axios.request).mockClear();

        for (const name of [
            'details.exe',
            'details.txt.html',
            'details.svg',
            'details',
        ]) {
            await act(async () => {
                expect(
                    await result.current.upload(
                        new File(['text'], name, { type: 'text/plain' }),
                    ),
                ).toBe(false);
            });
            expect(result.current.message).toContain(
                'Your draft and existing files have been kept',
            );
        }
        await act(async () => {
            expect(
                await result.current.upload(
                    new File(
                        [new Uint8Array(10 * 1024 * 1024 + 1)],
                        'large.txt',
                    ),
                ),
            ).toBe(false);
        });
        expect(result.current.message).toContain('each no larger than 10 MB');
        expect(axios.request).not.toHaveBeenCalled();
        expect(result.current.draft?.revision).toBe(3);
        expect(result.current.attachments).toEqual([existing]);
        expect(result.current.isSaved(snapshot())).toBe(true);

        const corrected = new File(['Approved file'], 'details.TXT', {
            type: 'text/plain',
        });
        vi.mocked(axios.request).mockImplementationOnce(async (config) =>
            response(
                {
                    ...saved,
                    revision: 5,
                    files: { ready: 2, pending: 0, cleanup_pending: 0 },
                },
                {
                    attachment: {
                        ...existing,
                        id: 92,
                        upload_uuid: (config.data as FormData).get(
                            'upload_uuid',
                        ),
                        name: corrected.name,
                        size: corrected.size,
                        download_url: '/it/attachments/92',
                    },
                },
            ),
        );
        await act(async () => {
            expect(await result.current.upload(corrected)).toBe(true);
        });
        expect(result.current.attachments).toHaveLength(2);
        expect(result.current.attachments[0]).toEqual(existing);
        expect(result.current.isSaved(snapshot())).toBe(true);
    });

    it('replaces an earlier server attachment error when a later local file selection is rejected', async () => {
        const { result } = await mounted();
        vi.mocked(axios.request).mockRejectedValueOnce(
            failure(422, {
                errors: {
                    attachment: ['The previous content type was rejected.'],
                    description: ['Keep this separate field error.'],
                },
            }),
        );
        await act(async () => {
            await result.current.upload(new File(['bytes'], 'reported.txt'));
        });
        expect(result.current.errors.attachment).toBe(
            'The previous content type was rejected.',
        );
        await act(async () => {
            expect(
                await result.current.upload(
                    new File(
                        [new Uint8Array(10 * 1024 * 1024 + 1)],
                        'large.txt',
                    ),
                ),
            ).toBe(false);
        });
        expect(result.current.errors.attachment).toBeUndefined();
        expect(result.current.errors.description).toBe(
            'Keep this separate field error.',
        );
        expect(result.current.message).toContain('each no larger than 10 MB');
    });

    it('retains the same File and upload UUID on retry without claiming text saved', async () => {
        const { result } = await mounted();
        const file = new File(['file bytes'], 'proof.txt', {
            type: 'text/plain',
        });
        vi.mocked(axios.request).mockRejectedValueOnce(
            failure(503, { code: 'draft_upload_unconfirmed' }),
        );
        await act(async () => {
            await result.current.upload(file);
        });
        const original = vi.mocked(axios.request).mock.calls.at(-1)?.[0]
            .data as FormData;
        const attachment = {
            id: 91,
            upload_uuid: original.get('upload_uuid'),
            name: file.name,
            mime: file.type,
            size: file.size,
            state: 'ready',
            download_url: '/it/attachments/91',
        };
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(
                metadata({
                    revision: 2,
                    has_content: true,
                    files: { ready: 1, pending: 0, cleanup_pending: 0 },
                }),
                { attachment },
            ),
        );
        await act(async () => {
            expect(await result.current.retry()).toBe(true);
        });
        const retried = vi.mocked(axios.request).mock.calls.at(-1)?.[0]
            .data as FormData;
        expect(retried.get('upload_uuid')).toBe(original.get('upload_uuid'));
        expect(retried.get('attachment')).toBe(file);
        expect(retried.get('expected_revision')).toBe('0');
        expect(result.current.attachments).toHaveLength(1);
        expect(result.current.isSaved(snapshot())).toBe(false);
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(
                metadata({
                    revision: 3,
                    files: { ready: 0, pending: 0, cleanup_pending: 1 },
                }),
                {
                    removed_attachment_id: 91,
                    cleanup: { deleted: 0, failed: 1 },
                },
            ),
        );
        await act(async () => {
            expect(await result.current.remove(91)).toBe(true);
        });
        expect(result.current.attachments).toEqual([]);
        expect(result.current.message).toContain('cleanup is still pending');
    });

    it('keeps the exact selected file after reviewing and adopting its reserved upload', async () => {
        const { result } = await mounted();
        const file = new File(['still needed'], 'pending.txt', {
            type: 'text/plain',
        });
        vi.mocked(axios.request).mockRejectedValueOnce(
            failure(503, { code: 'draft_upload_unconfirmed' }),
        );
        await act(async () => {
            await result.current.upload(file);
        });
        const sent = vi.mocked(axios.request).mock.calls.at(-1)?.[0]
            .data as FormData;
        const reserved = {
            id: 92,
            upload_uuid: sent.get('upload_uuid'),
            name: file.name,
            mime: file.type,
            size: file.size,
            state: 'reserved',
            download_url: null,
        };
        const current = metadata({
            revision: 1,
            has_content: true,
            files: { ready: 0, pending: 1, cleanup_pending: 0 },
        });
        vi.mocked(axios.request).mockResolvedValueOnce(response(current));
        await act(async () => {
            await result.current.check();
        });
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(current, {
                payload: { fields: [], step_index: 0 },
                attachments: [reserved],
            }),
        );
        await act(async () => {
            await result.current.review();
        });
        act(() => {
            expect(result.current.adoptReviewed()).toBe(true);
        });
        expect(result.current.retryable).toBe(true);
        expect(result.current.canReleaseUpload).toBe(true);
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(
                metadata({
                    revision: 2,
                    has_content: true,
                    files: { ready: 1, pending: 0, cleanup_pending: 0 },
                }),
                {
                    attachment: {
                        ...reserved,
                        state: 'ready',
                        download_url: '/it/attachments/92',
                    },
                },
            ),
        );
        await act(async () => {
            expect(await result.current.retry()).toBe(true);
        });
        const retried = vi.mocked(axios.request).mock.calls.at(-1)?.[0]
            .data as FormData;
        expect(retried.get('upload_uuid')).toBe(sent.get('upload_uuid'));
        expect(retried.get('attachment')).toBe(file);
        expect(retried.get('expected_revision')).toBe('0');
        expect(result.current.canReleaseUpload).toBe(false);
    });

    it('requires an explicit release before replacing an upload removed elsewhere', async () => {
        const { result } = await mounted();
        const file = new File(['same bytes'], 'removed.txt', {
            type: 'text/plain',
        });
        vi.mocked(axios.request).mockRejectedValueOnce(
            failure(409, { code: 'draft_attachment_removed' }),
        );
        await act(async () => {
            await result.current.upload(file);
        });
        const first = vi.mocked(axios.request).mock.calls.at(-1)?.[0]
            .data as FormData;
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(metadata({ revision: 3 }), {
                payload: { fields: [], step_index: 0 },
                attachments: [],
            }),
        );
        await act(async () => {
            await result.current.review();
        });
        act(() => {
            result.current.adoptReviewed();
        });
        await act(async () => {
            expect(await result.current.upload(file)).toBe(false);
        });
        act(() => {
            expect(result.current.releasePendingUpload()).toBe(true);
        });
        vi.mocked(axios.request).mockRejectedValueOnce(
            failure(503, { code: 'draft_upload_unconfirmed' }),
        );
        await act(async () => {
            await result.current.upload(file);
        });
        const replacement = vi.mocked(axios.request).mock.calls.at(-1)?.[0]
            .data as FormData;
        expect(replacement.get('upload_uuid')).not.toBe(
            first.get('upload_uuid'),
        );
        expect(replacement.get('expected_revision')).toBe('3');
    });

    it('does not clear a newer local edit or accept another generation commit', async () => {
        const { result } = await mounted();
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(metadata({ revision: 1, has_content: true })),
        );
        await act(async () => {
            await result.current.save(snapshot());
        });
        expect(result.current.submissionReference(snapshot())).toEqual({
            draft_uuid: uuid,
            draft_revision: 1,
            draft_actor_user_id: 11,
        });
        const ack = {
            draft_uuid: uuid,
            submitted_revision: 1,
            revision: 2,
            state: 'consumed',
        };
        act(() => {
            expect(
                result.current.acknowledgeConsumed(
                    { ...ack, draft_uuid: nextUuid },
                    snapshot(),
                ),
            ).toBe(false);
        });
        expect(result.current.state).toBe('ready');
        act(() => {
            expect(
                result.current.acknowledgeConsumed(ack, snapshot('Newer text')),
            ).toBe(false);
        });
        expect(result.current.state).toBe('terminal');
        expect(result.current.message).toContain(
            'newer local changes remain unsaved',
        );
        expect(result.current.submissionReference(snapshot())).toBeNull();
    });

    it('keeps validation recoverable without reusing a rejected frozen snapshot', async () => {
        const { result } = await mounted();
        vi.mocked(axios.request).mockRejectedValueOnce(
            failure(422, {
                errors: { 'fields.site_id': ['Choose an approved Site.'] },
            }),
        );
        await act(async () => {
            await result.current.save(snapshot());
        });
        expect(result.current.errors).toEqual({
            'fields.site_id': 'Choose an approved Site.',
        });
        expect(result.current.retryable).toBe(false);
        vi.mocked(axios.request).mockResolvedValueOnce(
            response(metadata({ revision: 1, has_content: true })),
        );
        await act(async () => {
            expect(
                await result.current.save({
                    fields: { title: 'Corrected', site_id: 9 },
                    step_index: 1,
                }),
            ).toBe(true);
        });
        expect(
            vi.mocked(axios.request).mock.calls.at(-1)?.[0].data,
        ).toMatchObject({ expected_revision: 0, fields: { site_id: 9 } });
    });
});
