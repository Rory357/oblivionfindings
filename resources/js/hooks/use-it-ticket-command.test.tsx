import { act, cleanup, renderHook } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useItTicketCommand } from './use-it-ticket-command';

function payload(title = 'Synthetic request') {
    const body = new FormData();
    body.append('title', title);
    body.append(
        'attachments[]',
        new File(['synthetic file'], 'fixture.txt', { type: 'text/plain' }),
    );
    return body;
}

function committed(
    requestId: string | null,
    overrides: Record<string, unknown> = {},
) {
    return {
        status: 201,
        data: {
            status: 'committed',
            data: {
                id: 9,
                reference: 'IT-000009',
                url: '/it/tickets/9',
                request_uuid: requestId,
                replayed: false,
                ...overrides,
            },
        },
    };
}

function rejected(status?: number, data: unknown = {}) {
    return {
        isAxiosError: true,
        response: status ? { status, data } : undefined,
    };
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
}

describe('useItTicketCommand', () => {
    beforeEach(() => {
        sessionStorage.clear();
        vi.spyOn(axios, 'post');
        vi.spyOn(axios, 'get');
    });
    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('submits multipart once and only accepts a matching canonical committed result', async () => {
        const { result } = renderHook(() => useItTicketCommand());
        const requestId = result.current.requestId;
        const pending = deferred<ReturnType<typeof committed>>();
        vi.mocked(axios.post).mockReturnValueOnce(pending.promise);
        const body = payload();
        body.append('request_uuid', 'untrusted-supplied-identity');
        let submission!: Promise<unknown>;
        await act(async () => {
            submission = result.current.submit(body);
            expect(await result.current.submit(body)).toBeNull();
        });
        expect(axios.post).toHaveBeenCalledTimes(1);
        const [url, sent, options] = vi.mocked(axios.post).mock.calls[0];
        expect(url).toBe('/it/tickets');
        expect((sent as FormData).getAll('request_uuid')).toEqual([requestId]);
        expect(options?.headers).toMatchObject({
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        });
        expect(options?.headers).not.toHaveProperty('Content-Type');
        expect(result.current.canEdit).toBe(false);
        await act(async () => {
            pending.resolve(committed(requestId));
            await submission;
        });
        expect(result.current.state).toBe('committed');
        expect(result.current.result?.url).toBe('/it/tickets/9');
    });

    it.each([
        ['missing envelope', { status: 200, data: '<html>Login page</html>' }],
        ['missing identity', committed(null)],
        [
            'external URL',
            committed('match', { url: 'https://elsewhere.test/it/tickets/9' }),
        ],
        ['wrong record URL', committed('match', { url: '/it/tickets/10' })],
        ['missing reference', committed('match', { reference: null })],
        ['non-success HTTP', { ...committed('match'), status: 500 }],
    ])('retains uncertain work for %s', async (_label, response) => {
        const { result } = renderHook(() => useItTicketCommand());
        if (
            typeof response.data === 'object' &&
            response.data.data.request_uuid === 'match'
        )
            response.data.data.request_uuid = result.current.requestId;
        vi.mocked(axios.post).mockResolvedValueOnce(response);
        await act(async () => {
            expect(await result.current.submit(payload())).toBeNull();
        });
        expect(result.current.state).toBe('outcome_unknown');
        expect(result.current.result).toBeNull();
        expect(result.current.canEdit).toBe(false);
        expect(result.current.canRetry).toBe(true);
    });

    it('retries the original frozen body and file after timeout and recovery 404', async () => {
        const { result } = renderHook(() => useItTicketCommand());
        const requestId = result.current.requestId;
        const body = payload('Original');
        vi.mocked(axios.post).mockRejectedValueOnce(rejected());
        await act(async () => {
            await result.current.submit(body);
        });
        body.set('title', 'Mutated original');
        body.delete('attachments[]');
        await act(async () => {
            await result.current.submit(
                payload('New body cannot replace frozen submission'),
            );
        });
        expect(axios.post).toHaveBeenCalledTimes(1);
        vi.mocked(axios.get).mockRejectedValueOnce(rejected(404));
        await act(async () => {
            await result.current.recover();
        });
        expect(result.current.state).toBe('outcome_unknown');
        expect(result.current.message).toContain('may still be running');
        vi.mocked(axios.post).mockResolvedValueOnce(committed(requestId));
        await act(async () => {
            await result.current.retry();
        });
        const retryBody = vi.mocked(axios.post).mock.calls[1][1] as FormData;
        expect(retryBody.get('title')).toBe('Original');
        expect(retryBody.get('attachments[]')).toBeInstanceOf(File);
        expect(retryBody.get('request_uuid')).toBe(requestId);
        expect(axios.get).toHaveBeenCalledWith(
            `/it/ticket-commands/${requestId}`,
            expect.any(Object),
        );
        expect(result.current.state).toBe('committed');
    });

    it('allows correction after first 422 but never treats a later 422 as proof against a lost commit', async () => {
        const { result } = renderHook(() => useItTicketCommand());
        const requestId = result.current.requestId;
        vi.mocked(axios.post).mockRejectedValueOnce(
            rejected(422, {
                errors: {
                    title: ['Enter a title.'],
                    'attachments.0': ['File is too large.'],
                },
            }),
        );
        await act(async () => {
            await result.current.submit(payload(''));
        });
        expect(result.current.canEdit).toBe(true);
        expect(result.current.fieldErrors).toEqual({
            title: 'Enter a title.',
            'attachments.0': 'File is too large.',
        });
        vi.mocked(axios.post).mockRejectedValueOnce(rejected(500));
        await act(async () => {
            await result.current.submit(payload('Corrected'));
        });
        expect(result.current.requestId).toBe(requestId);
        vi.mocked(axios.post).mockRejectedValueOnce(
            rejected(422, {
                errors: { title: ['New validation requirement.'] },
            }),
        );
        await act(async () => {
            await result.current.retry();
        });
        expect(result.current.state).toBe('outcome_unknown');
        expect(result.current.canEdit).toBe(false);
        expect(
            (vi.mocked(axios.post).mock.calls[2][1] as FormData).get('title'),
        ).toBe('Corrected');
    });

    it.each([401, 419])(
        'requires session recovery after %s and preserves the exact retry',
        async (status) => {
            const { result } = renderHook(() => useItTicketCommand());
            const requestId = result.current.requestId;
            vi.mocked(axios.post).mockRejectedValueOnce(rejected(status));
            await act(async () => {
                await result.current.submit(payload());
            });
            expect(result.current.state).toBe('session_expired');
            expect(result.current.canEdit).toBe(false);
            vi.mocked(axios.post).mockResolvedValueOnce(committed(requestId));
            await act(async () => {
                await result.current.retry();
            });
            expect(result.current.state).toBe('committed');
        },
    );

    it.each([
        { status: 403, data: {}, operation: 'recover' },
        {
            status: 404,
            data: { code: 'access_unavailable' },
            operation: 'recover',
        },
        { status: 403, data: {}, operation: 'submit' },
        {
            status: 404,
            data: { code: 'access_unavailable' },
            operation: 'submit',
        },
    ])(
        'purges retained payload for $operation access denial ($status)',
        async ({ status, data, operation }) => {
            const { result } = renderHook(() => useItTicketCommand());
            vi.mocked(axios.post).mockRejectedValueOnce(
                operation === 'recover'
                    ? rejected(500)
                    : rejected(status, data),
            );
            await act(async () => {
                await result.current.submit(payload('Private synthetic draft'));
            });
            vi.mocked(axios.get).mockRejectedValueOnce(rejected(status, data));
            await act(async () => {
                await result.current.recover();
                await result.current.retry();
            });
            expect(result.current.state).toBe('access_denied');
            expect(result.current.canRetry).toBe(false);
            expect(result.current.canRecover).toBe(false);
            expect(result.current.result).toBeNull();
            expect(axios.post).toHaveBeenCalledTimes(1);
        },
    );

    it('requires original-result recovery after conflict without resubmission', async () => {
        const { result } = renderHook(() => useItTicketCommand());
        const requestId = result.current.requestId;
        vi.mocked(axios.post).mockRejectedValueOnce(rejected(409));
        await act(async () => {
            await result.current.submit(payload());
            await result.current.retry();
        });
        expect(result.current.state).toBe('conflict');
        expect(result.current.canRetry).toBe(false);
        vi.mocked(axios.get).mockResolvedValueOnce(
            committed(requestId, { replayed: true }),
        );
        await act(async () => {
            await result.current.recover();
        });
        expect(result.current.result?.replayed).toBe(true);
        expect(axios.post).toHaveBeenCalledTimes(1);
    });

    it('stops only HTTP waiting and ignores late completion from a cancelled generation', async () => {
        const { result } = renderHook(() => useItTicketCommand());
        const requestId = result.current.requestId;
        const pending = deferred<ReturnType<typeof committed>>();
        vi.mocked(axios.post).mockReturnValueOnce(pending.promise);
        let promise!: Promise<unknown>;
        await act(async () => {
            promise = result.current.submit(payload());
        });
        const signal = vi.mocked(axios.post).mock.calls[0][2]?.signal;
        act(() => result.current.cancelWait());
        expect(signal?.aborted).toBe(true);
        expect(result.current.message).toContain('does not cancel');
        expect(result.current.requestId).toBe(requestId);
        expect(result.current.canRetry).toBe(true);
        await act(async () => {
            pending.resolve(committed(requestId));
            await promise;
        });
        expect(result.current.state).toBe('outcome_unknown');
        expect(result.current.result).toBeNull();
    });

    it('unmount aborts an operation; explicit discard starts a fresh command without stale overwrites', async () => {
        const { result, unmount } = renderHook(() => useItTicketCommand());
        const oldId = result.current.requestId;
        const pending = deferred<ReturnType<typeof committed>>();
        vi.mocked(axios.post).mockReturnValueOnce(pending.promise);
        let promise!: Promise<unknown>;
        await act(async () => {
            promise = result.current.submit(payload());
        });
        const firstSignal = vi.mocked(axios.post).mock.calls[0][2]?.signal;
        act(() => result.current.reset('discard'));
        expect(firstSignal?.aborted).toBe(true);
        expect(result.current.requestId).not.toBe(oldId);
        await act(async () => {
            pending.resolve(committed(oldId));
            await promise;
        });
        expect(result.current.state).toBe('idle');
        const next = deferred<ReturnType<typeof committed>>();
        vi.mocked(axios.post).mockReturnValueOnce(next.promise);
        await act(async () => {
            promise = result.current.submit(payload());
        });
        const secondSignal = vi.mocked(axios.post).mock.calls[1][2]?.signal;
        unmount();
        expect(secondSignal?.aborted).toBe(true);
        next.resolve(committed(result.current.requestId));
        expect(await promise).toBeNull();
    });

    it('uses cryptographic fallback and fails closed when secure randomness is unavailable', async () => {
        vi.stubGlobal('crypto', {
            getRandomValues: (bytes: Uint8Array) => {
                bytes.fill(3);
                return bytes;
            },
        });
        const fallback = renderHook(() => useItTicketCommand());
        expect(fallback.result.current.requestId).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
        );
        fallback.unmount();
        vi.stubGlobal('crypto', {});
        const unavailable = renderHook(() => useItTicketCommand());
        expect(unavailable.result.current.state).toBe('unavailable');
        await act(async () => {
            await unavailable.result.current.submit(payload());
        });
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('automatically keeps only the submitted actor-scoped UUID and resumes without any draft or retry payload', async () => {
        const first = renderHook(() => useItTicketCommand({ actorId: 230 }));
        const requestId = first.result.current.requestId;
        vi.mocked(axios.post).mockRejectedValueOnce(rejected(500));
        await act(async () => {
            await first.result.current.submit(
                payload('Private title and file'),
            );
        });
        expect(sessionStorage.length).toBe(1);
        act(() =>
            expect(first.result.current.retainPendingReference()).toBe(true),
        );
        expect(sessionStorage.length).toBe(1);
        expect(
            sessionStorage.getItem('it.pending-ticket-command.v1.actor.230'),
        ).toBe(requestId);
        first.unmount();

        const second = renderHook(() => useItTicketCommand({ actorId: 230 }));
        expect(second.result.current.requestId).toBe(requestId);
        expect(second.result.current.canRecover).toBe(true);
        expect(second.result.current.canRetry).toBe(false);
        expect(second.result.current.canEdit).toBe(false);
        expect(second.result.current.canForgetReference).toBe(true);
        await act(async () => {
            await second.result.current.retry();
            await second.result.current.submit(
                payload('Cannot overwrite recovery'),
            );
        });
        expect(axios.post).toHaveBeenCalledTimes(1);
        vi.mocked(axios.get).mockRejectedValueOnce(rejected(404));
        await act(async () => {
            await second.result.current.recover();
        });
        expect(second.result.current.state).toBe('outcome_unknown');
        expect(sessionStorage.length).toBe(1);
        expect(second.result.current.message).toContain('may still be running');
        vi.mocked(axios.get).mockResolvedValueOnce(
            committed(requestId, { viewer_user_id: 230 }),
        );
        await act(async () => {
            await second.result.current.recover();
        });
        expect(axios.get).toHaveBeenLastCalledWith(
            `/it/ticket-commands/${requestId}`,
            expect.objectContaining({ withCredentials: true }),
        );
        expect(second.result.current.result?.reference).toBe('IT-000009');
        expect(sessionStorage.length).toBe(0);
    });

    it('retains the original identity before a pending send unmounts and requires recovery after remount', async () => {
        const pending = deferred<ReturnType<typeof committed>>();
        vi.mocked(axios.post).mockReturnValueOnce(pending.promise);
        const first = renderHook(() => useItTicketCommand({ actorId: 230 }));
        const requestId = first.result.current.requestId;
        let submission!: Promise<unknown>;
        await act(async () => {
            submission = first.result.current.submit(
                payload('Unsent private title'),
            );
        });
        expect(
            sessionStorage.getItem('it.pending-ticket-command.v1.actor.230'),
        ).toBe(requestId);
        expect(sessionStorage.length).toBe(1);
        first.unmount();
        const second = renderHook(() => useItTicketCommand({ actorId: 230 }));
        expect(second.result.current.requestId).toBe(requestId);
        expect(second.result.current.state).toBe('outcome_unknown');
        expect(second.result.current.canEdit).toBe(false);
        expect(second.result.current.canRetry).toBe(false);
        expect(second.result.current.canRecover).toBe(true);
        await act(async () => {
            pending.resolve(committed(requestId, { viewer_user_id: 230 }));
            await submission;
        });
        expect(sessionStorage.length).toBe(1);
        expect(axios.post).toHaveBeenCalledTimes(1);
    });

    it.each([422, 419, 500])(
        'clears the automatic marker only for definitive first-attempt validation (%s)',
        async (status) => {
            vi.mocked(axios.post).mockRejectedValueOnce(rejected(status));
            const { result } = renderHook(() =>
                useItTicketCommand({ actorId: 230 }),
            );
            await act(async () => {
                await result.current.submit(payload());
            });
            expect(
                sessionStorage.getItem(
                    'it.pending-ticket-command.v1.actor.230',
                ),
            ).toBe(status === 422 ? null : result.current.requestId);
        },
    );

    it('does not let a later validation response clear a previously interrupted session submission', async () => {
        vi.mocked(axios.post)
            .mockRejectedValueOnce(rejected(419))
            .mockRejectedValueOnce(rejected(422));
        const { result } = renderHook(() =>
            useItTicketCommand({ actorId: 230 }),
        );
        await act(async () => {
            await result.current.submit(payload());
        });
        await act(async () => {
            await result.current.retry();
        });
        expect(result.current.state).toBe('outcome_unknown');
        expect(result.current.canEdit).toBe(false);
        expect(
            sessionStorage.getItem('it.pending-ticket-command.v1.actor.230'),
        ).toBe(result.current.requestId);
    });

    it('isolates another signed-in actor and aborts old receipt results when the actor changes', async () => {
        const requestId = crypto.randomUUID();
        sessionStorage.setItem(
            'it.pending-ticket-command.v1.actor.230',
            requestId,
        );
        const other = renderHook(() => useItTicketCommand({ actorId: 231 }));
        expect(other.result.current.state).toBe('idle');
        expect(other.result.current.requestId).not.toBe(requestId);
        act(() => other.result.current.reset('discard'));
        expect(
            sessionStorage.getItem('it.pending-ticket-command.v1.actor.230'),
        ).toBe(requestId);
        other.unmount();
        const current = renderHook(
            ({ actorId }) => useItTicketCommand({ actorId }),
            { initialProps: { actorId: 230 } },
        );
        const pending = deferred<ReturnType<typeof committed>>();
        vi.mocked(axios.get).mockReturnValueOnce(pending.promise);
        let recovery!: Promise<unknown>;
        await act(async () => {
            recovery = current.result.current.recover();
        });
        const signal = vi.mocked(axios.get).mock.calls[0][1]?.signal;
        current.rerender({ actorId: 231 });
        expect(signal?.aborted).toBe(true);
        await act(async () => {
            pending.resolve(committed(requestId));
            await recovery;
        });
        expect(current.result.current.result).toBeNull();
        expect(current.result.current.state).toBe('idle');
        expect(current.result.current.requestId).not.toBe(requestId);
    });

    it.each([403, 404])(
        'clears the pending marker when authorized recovery loses access (%s)',
        async (status) => {
            sessionStorage.setItem(
                'it.pending-ticket-command.v1.actor.230',
                crypto.randomUUID(),
            );
            const { result } = renderHook(() =>
                useItTicketCommand({ actorId: 230 }),
            );
            vi.mocked(axios.get).mockRejectedValueOnce(
                rejected(status, { code: 'access_unavailable' }),
            );
            await act(async () => {
                await result.current.recover();
            });
            expect(result.current.state).toBe('access_denied');
            expect(result.current.retainPendingReference()).toBe(false);
            expect(sessionStorage.length).toBe(0);
        },
    );

    it('retains a resumed reference across session expiry and unmount, and forgets it only after explicit reset', async () => {
        const requestId = crypto.randomUUID();
        sessionStorage.setItem(
            'it.pending-ticket-command.v1.actor.230',
            requestId,
        );
        const first = renderHook(() => useItTicketCommand({ actorId: 230 }));
        vi.mocked(axios.get).mockRejectedValueOnce(rejected(419));
        await act(async () => {
            await first.result.current.recover();
        });
        expect(first.result.current.state).toBe('session_expired');
        expect(first.result.current.canRetry).toBe(false);
        first.unmount();
        expect(sessionStorage.length).toBe(1);
        const next = renderHook(() => useItTicketCommand({ actorId: 230 }));
        act(() => next.result.current.reset('new'));
        expect(sessionStorage.length).toBe(0);
        expect(next.result.current.state).toBe('idle');
        expect(next.result.current.requestId).not.toBe(requestId);
    });

    it('freezes the original actor and requires the same viewer identity on submit and recovery', async () => {
        const { result } = renderHook(() =>
            useItTicketCommand({ actorId: 230 }),
        );
        const requestId = result.current.requestId;
        const body = payload();
        body.set('actor_user_id', '231');
        vi.mocked(axios.post).mockResolvedValueOnce(
            committed(requestId, { viewer_user_id: 231 }),
        );
        await act(async () => {
            expect(await result.current.submit(body)).toBeNull();
        });
        expect(
            (vi.mocked(axios.post).mock.calls[0][1] as FormData).get(
                'actor_user_id',
            ),
        ).toBe('230');
        expect(result.current.state).toBe('outcome_unknown');
        vi.mocked(axios.get).mockResolvedValueOnce(committed(requestId));
        await act(async () => {
            expect(await result.current.recover()).toBeNull();
        });
        expect(vi.mocked(axios.get).mock.calls[0][1]).toMatchObject({
            params: { actor_user_id: 230 },
        });
        vi.mocked(axios.get).mockResolvedValueOnce(
            committed(requestId, { viewer_user_id: 230 }),
        );
        await act(async () => {
            expect(await result.current.recover()).not.toBeNull();
        });
        expect(result.current.state).toBe('committed');
    });

    it('reports unavailable reference storage without clearing the uncertain in-memory submission', async () => {
        const { result } = renderHook(() =>
            useItTicketCommand({ actorId: 230 }),
        );
        vi.mocked(axios.post).mockRejectedValueOnce(rejected(500));
        await act(async () => {
            await result.current.submit(payload());
        });
        const requestId = result.current.requestId;
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('Storage disabled', 'SecurityError');
        });
        expect(result.current.retainPendingReference()).toBe(false);
        expect(result.current.requestId).toBe(requestId);
        expect(result.current.canRetry).toBe(true);
    });
});
