import { act, renderHook } from '@testing-library/react';
import axios, { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useCatalogueSubmission } from '../use-catalogue-submission';

const storageKey = 'it.catalogue.pending.v1.actor.3.item.12';
const options = () => ({
    actorId: 3,
    itemId: 12,
    schemaVersion: 2,
    onDenied: vi.fn(),
    onSession: vi.fn(),
});
const values = { details: 'Private request text', selections: ['Original'] };
function response(
    body: unknown,
    status: 'committed' | 'not_found' | 'cancelled' = 'committed',
) {
    const input =
        body instanceof FormData
            ? {
                  actor_user_id: Number(body.get('actor_user_id')),
                  idempotency_key: String(body.get('idempotency_key')),
              }
            : (body as { actor_user_id: number; idempotency_key: string });
    return {
        data: {
            status,
            data: {
                viewer_user_id: input.actor_user_id,
                catalog_item_id: 12,
                request_uuid: input.idempotency_key,
                ...(status === 'committed'
                    ? {
                          schema_version: 2,
                          submission_id: 8,
                          result_type: 'ticket',
                          id: 9,
                          reference: 'IT-000009',
                          url: '/it/tickets/9',
                          replayed: true,
                      }
                    : status === 'cancelled'
                      ? { cancelled: true }
                      : { retry_same_command: true }),
            },
        },
    };
}
function rejected(status: number, errors?: Record<string, string[]>) {
    return new AxiosError(
        'Synthetic response',
        undefined,
        undefined,
        undefined,
        {
            status,
            statusText: 'Synthetic',
            data: { errors },
            headers: {},
            config: { headers: {} } as never,
        },
    );
}
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((accept) => {
        resolve = accept;
    });
    return { promise, resolve };
}
beforeEach(() => sessionStorage.clear());
afterEach(() => vi.restoreAllMocks());

it('uploads multipart evidence and retries the original files and fields after a lost result', async () => {
    const transport = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(new Error('Lost response'))
        .mockImplementationOnce(async (_url, body) => response(body));
    const { result } = renderHook(() => useCatalogueSubmission(options()));
    const original = new File(['Original bytes'], 'original.txt', {
        type: 'text/plain',
    });
    const draft = {
        evidence: [original],
        details: 'Original details',
        accepted: false,
        quantity: 2,
        optional: null,
    };
    await act(async () => {
        await result.current.submit(draft, 22, 55);
    });
    expect(result.current.phase).toBe('unknown');
    const first = transport.mock.calls[0][1] as FormData;
    expect(first).toBeInstanceOf(FormData);
    expect(first.get('values[evidence][0]')).toBe(original);
    expect(first.get('values[accepted]')).toBe('0');
    expect(first.get('values[quantity]')).toBe('2');
    expect(first.get('values[optional]')).toBe('');
    expect(first.get('requested_for_user_id')).toBe('55');
    draft.evidence[0] = new File(['Replacement'], 'replacement.txt');
    draft.details = 'New details';
    await act(async () => {
        await result.current.retry();
    });
    const retry = transport.mock.calls[1][1] as FormData;
    expect(retry.get('values[evidence][0]')).toBe(original);
    expect(retry.get('values[details]')).toBe('Original details');
    expect(retry.get('idempotency_key')).toBe(first.get('idempotency_key'));
    expect(result.current.phase).toBe('committed');
    expect(sessionStorage.getItem(storageKey)).toBeNull();
});

it('retains only recovery identity after a file submission unmounts and refuses to resend missing bytes', async () => {
    const transport = vi
        .spyOn(axios, 'post')
        .mockRejectedValue(new Error('Lost'));
    const first = renderHook(() => useCatalogueSubmission(options()));
    await act(async () => {
        await first.result.current.submit(
            { evidence: [new File(['private'], 'private.txt')] },
            22,
        );
    });
    expect(sessionStorage.getItem(storageKey)).not.toContain('private');
    first.unmount();
    const next = renderHook(() => useCatalogueSubmission(options()));
    expect(next.result.current.canRetry).toBe(false);
    await act(async () => {
        await next.result.current.retry();
    });
    expect(transport).toHaveBeenCalledTimes(1);
});

it('retries the same requested-for identity without placing the person or values in browser storage', async () => {
    const transport = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(new Error('Lost response'))
        .mockImplementation(async (_url, body) => response(body));
    const { result } = renderHook(() => useCatalogueSubmission(options()));
    await act(async () => {
        await result.current.submit(values, 22, 55);
    });
    expect(result.current.phase).toBe('unknown');
    expect(JSON.parse(sessionStorage.getItem(storageKey)!)).not.toHaveProperty(
        'requested_for_user_id',
    );
    await act(async () => {
        await result.current.retry();
    });
    expect(transport.mock.calls[0][1]).toEqual(transport.mock.calls[1][1]);
    expect(transport.mock.calls[1][1]).toMatchObject({
        requested_for_user_id: 55,
    });
    expect(result.current.phase).toBe('committed');
});

it('retains an unfinished requested-for choice after lookup session loss instead of submitting for myself', async () => {
    const transport = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (_url, body) => response(body, 'not_found'));
    const { result } = renderHook(() => useCatalogueSubmission(options()));
    act(() => result.current.pauseForSession(values, 22, null));
    await act(async () => {
        await result.current.recover();
    });
    transport.mockRejectedValueOnce(
        rejected(422, {
            requested_for_user_id: ['Choose the requested-for person.'],
        }),
    );
    await act(async () => {
        await result.current.retry();
    });
    expect(transport.mock.calls[1][1]).toMatchObject({
        requested_for_user_id: null,
    });
    expect(result.current.phase).not.toBe('committed');
});

it('does not submit when the browser cannot retain the recovery identity', async () => {
    const transport = vi.spyOn(axios, 'post');
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new Error('Storage blocked');
    });
    const { result } = renderHook(() => useCatalogueSubmission(options()));
    await act(async () => {
        await result.current.submit(values, 22);
    });
    expect(transport).not.toHaveBeenCalled();
    expect(result.current.editing).toBe(true);
    expect(result.current.recoveryStored).toBe(false);
    expect(result.current.errors.idempotency_key).toContain(
        'has not been sent',
    );
});

it('retains the identity when an entity lookup expires the session before submission', () => {
    const transport = vi.spyOn(axios, 'post');
    const { result } = renderHook(() => useCatalogueSubmission(options()));
    act(() => result.current.pauseForSession(values, 22));
    expect(result.current.phase).toBe('session');
    expect(result.current.recoveryStored).toBe(true);
    expect(JSON.parse(sessionStorage.getItem(storageKey)!)).toEqual(
        result.current.identity,
    );
    expect(sessionStorage.getItem(storageKey)).not.toContain('Private');
    expect(transport).not.toHaveBeenCalled();
});

it('retries the frozen original payload after a lost response and stores only identity', async () => {
    const transport = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(new Error('Lost response'))
        .mockImplementationOnce(async (_url, body) => response(body));
    const { result } = renderHook(() => useCatalogueSubmission(options()));
    const draft = structuredClone(values);
    await act(async () => {
        await result.current.submit(draft, 22);
    });
    expect(result.current.phase).toBe('unknown');
    expect(JSON.parse(sessionStorage.getItem(storageKey)!)).toEqual(
        result.current.identity,
    );
    expect(sessionStorage.getItem(storageKey)).not.toContain('Private');
    draft.details = 'Changed draft';
    draft.selections.push('Changed');
    await act(async () => {
        await result.current.retry();
    });
    expect(transport.mock.calls[1][1]).toEqual(transport.mock.calls[0][1]);
    expect(transport.mock.calls[1][1]).toMatchObject({ values, site_id: 22 });
    expect(result.current.phase).toBe('committed');
    expect(result.current.result?.url).toBe('/it/tickets/9');
    expect(sessionStorage.getItem(storageKey)).toBeNull();
});

it('stops waiting without claiming cancellation and ignores a late success', async () => {
    const delayed = deferred<ReturnType<typeof response>>();
    const transport = vi
        .spyOn(axios, 'post')
        .mockReturnValueOnce(delayed.promise)
        .mockImplementationOnce(async (_url, body) => response(body));
    const { result } = renderHook(() => useCatalogueSubmission(options()));
    let sending: Promise<void> | undefined;
    act(() => {
        sending = result.current.submit(values, 22);
    });
    expect(result.current.pending).toBe(true);
    act(() => result.current.stopWaiting());
    expect(transport.mock.calls[0][2]?.signal?.aborted).toBe(true);
    await act(async () => {
        delayed.resolve(response(transport.mock.calls[0][1]));
        await sending;
    });
    expect(result.current.phase).toBe('unknown');
    expect(result.current.result).toBeNull();
    await act(async () => {
        await result.current.recover();
    });
    expect(transport.mock.calls[1][0]).toBe(
        '/it/catalog/12/submissions/recover',
    );
    expect(transport.mock.calls[1][1]).not.toHaveProperty('values');
    expect(result.current.phase).toBe('committed');
});

it('restores the original identity after remount and cancels before allowing a fresh form', async () => {
    const transport = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(new Error('Lost'))
        .mockImplementationOnce(async (_url, body) =>
            response(body, 'not_found'),
        )
        .mockImplementationOnce(async (_url, body) =>
            response(body, 'cancelled'),
        );
    const first = renderHook(() => useCatalogueSubmission(options()));
    await act(async () => {
        await first.result.current.submit(values, 22);
    });
    const original = first.result.current.identity;
    first.unmount();
    const next = renderHook(() =>
        useCatalogueSubmission({ ...options(), schemaVersion: 3 }),
    );
    expect(next.result.current.identity).toEqual(original);
    expect(next.result.current.canRetry).toBe(false);
    await act(async () => {
        await next.result.current.submit(values, 22);
        await next.result.current.retry();
    });
    expect(transport).toHaveBeenCalledTimes(1);
    await act(async () => {
        await next.result.current.recover();
    });
    expect(next.result.current.phase).toBe('not_found');
    expect(next.result.current.editing).toBe(false);
    await act(async () => {
        await next.result.current.cancel();
    });
    expect(next.result.current.phase).toBe('cancelled');
    expect(sessionStorage.getItem(storageKey)).toBeNull();
});

it('shows the committed record when cancellation loses to submission', async () => {
    const transport = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(new Error('Lost'))
        .mockImplementationOnce(async (_url, body) => response(body));
    const { result } = renderHook(() => useCatalogueSubmission(options()));
    await act(async () => {
        await result.current.submit(values, 22);
        await result.current.cancel();
    });
    expect(transport.mock.calls[1][0]).toBe(
        '/it/catalog/12/submissions/cancel',
    );
    expect(result.current.phase).toBe('committed');
    expect(result.current.result?.resultId).toBe(9);
});

it.each([403, 404])(
    'clears private payloads after access denial %s and prevents further commands',
    async (status) => {
        const transport = vi
            .spyOn(axios, 'post')
            .mockRejectedValueOnce(rejected(status));
        const callbacks = options();
        const { result } = renderHook(() => useCatalogueSubmission(callbacks));
        await act(async () => {
            await result.current.submit(values, 22);
        });
        expect(callbacks.onDenied).toHaveBeenCalledOnce();
        expect(result.current.phase).toBe('denied');
        expect(result.current.canRetry).toBe(false);
        await act(async () => {
            await result.current.retry();
            await result.current.recover();
            await result.current.cancel();
        });
        expect(transport).toHaveBeenCalledTimes(1);
    },
);

it.each([401, 419])(
    'conceals the session-expired form and recovers without resending values %s',
    async (status) => {
        const transport = vi
            .spyOn(axios, 'post')
            .mockRejectedValueOnce(rejected(status))
            .mockImplementationOnce(async (_url, body) =>
                response(body, 'not_found'),
            )
            .mockImplementationOnce(async (_url, body) => response(body));
        const callbacks = options();
        const { result } = renderHook(() => useCatalogueSubmission(callbacks));
        await act(async () => {
            await result.current.submit(values, 22);
        });
        expect(callbacks.onSession).toHaveBeenCalledOnce();
        expect(result.current.phase).toBe('session');
        await act(async () => {
            await result.current.recover();
        });
        expect(transport.mock.calls[1][1]).not.toHaveProperty('values');
        expect(result.current.phase).toBe('not_found');
        await act(async () => {
            await result.current.retry();
        });
        expect(transport.mock.calls[2][1]).toEqual(transport.mock.calls[0][1]);
        expect(result.current.phase).toBe('committed');
    },
);

it('invalidates an outstanding response if session loss is reported by an entity picker', async () => {
    const delayed = deferred<ReturnType<typeof response>>();
    const transport = vi
        .spyOn(axios, 'post')
        .mockReturnValueOnce(delayed.promise);
    const callbacks = options();
    const { result } = renderHook(() => useCatalogueSubmission(callbacks));
    let sending: Promise<void> | undefined;
    act(() => {
        sending = result.current.submit(values, 22);
    });
    act(() => result.current.pauseForSession(values, 22));
    await act(async () => {
        delayed.resolve(response(transport.mock.calls[0][1]));
        await sending;
    });
    expect(result.current.phase).toBe('session');
    expect(result.current.result).toBeNull();
    expect(transport.mock.calls[0][2]?.signal?.aborted).toBe(true);
});

it('allows correction after a definitive validation response but keeps uncertain retries locked', async () => {
    vi.spyOn(axios, 'post')
        .mockRejectedValueOnce(
            rejected(422, { 'values.details': ['More detail required'] }),
        )
        .mockRejectedValueOnce(new Error('Lost'))
        .mockRejectedValueOnce(
            rejected(422, { 'values.details': ['Unavailable'] }),
        );
    const { result } = renderHook(() => useCatalogueSubmission(options()));
    await act(async () => {
        await result.current.submit(values, 22);
    });
    expect(result.current.phase).toBe('validation');
    expect(result.current.errors['values.details']).toBe(
        'More detail required',
    );
    expect(sessionStorage.getItem(storageKey)).toBeNull();
    await act(async () => {
        await result.current.submit({ details: 'Corrected' }, 22);
    });
    await act(async () => {
        await result.current.retry();
    });
    expect(result.current.phase).toBe('conflict');
    expect(result.current.editing).toBe(false);
    expect(sessionStorage.getItem(storageKey)).not.toBeNull();
});

it('rejects a success bound to another account and does not clear the recovery marker', async () => {
    vi.spyOn(axios, 'post').mockImplementationOnce(async (_url, body) => {
        const saved = response(body);
        saved.data.data.viewer_user_id = 4;
        return saved;
    });
    const { result } = renderHook(() => useCatalogueSubmission(options()));
    await act(async () => {
        await result.current.submit(values, 22);
    });
    expect(result.current.phase).toBe('unknown');
    expect(result.current.result).toBeNull();
    expect(sessionStorage.getItem(storageKey)).not.toBeNull();
});

it('adopts a pending identity created by an earlier view instead of submitting another command', async () => {
    const transport = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (_url, body) => response(body, 'cancelled'));
    const { result } = renderHook(() => useCatalogueSubmission(options()));
    const previous = {
        ...result.current.identity,
        requestUuid: 'a0000000-0000-4000-8000-000000000000',
    };
    sessionStorage.setItem(storageKey, JSON.stringify(previous));
    await act(async () => {
        await result.current.submit(values, 22);
    });
    expect(transport).not.toHaveBeenCalled();
    expect(result.current.identity).toEqual(previous);
    expect(result.current.canRetry).toBe(false);
    await act(async () => {
        await result.current.cancel();
    });
    expect(transport.mock.calls[0][1]).toMatchObject({
        idempotency_key: previous.requestUuid,
    });
});
