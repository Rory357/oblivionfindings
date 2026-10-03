import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    controlledProductReadUrl,
    useControlledProduct,
} from './product-client';
import type { ControlledProductPayload } from './product-types';

function payload(name = 'Selected person'): ControlledProductPayload {
    return {
        medicines: [],
        sites: [],
        people: [],
        witnesses_by_site: {},
        entries: [],
        discrepancies: [],
        losses: [],
        destructions: [],
        requests: [],
        overrides: [],
        current_user_id: 1,
        current_user_name: name,
        on_site_destruction_allowed: false,
        can: {
            view: true,
            record: false,
            manage: false,
            override: false,
            close_loss: false,
        },
        cadence: { configured: false },
    };
}
function response(body: unknown, status = 200) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}
function deferredResponse() {
    let resolve!: (value: Response) => void;
    const promise = new Promise<Response>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}
const scoped =
    '/emar/controlled?view=losses&site_id=8&client_id=42&client_medication_id=17&date=2020-01-01&q=synthetic';
const read =
    '/emar/controlled/product?site_id=8&client_id=42&client_medication_id=17&date=2020-01-01';

beforeEach(() => {
    window.history.replaceState(null, '', '/emar/controlled');
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('controlled product reader scope', () => {
    it('retains only supported reader filters, including encoded NZ dates', () => {
        expect(controlledProductReadUrl(scoped + '&class_review=1')).toBe(read);
        expect(
            controlledProductReadUrl('/emar/controlled?view=register&q=other'),
        ).toBe('/emar/controlled/product');
    });

    it('keeps the person and date on mount, refresh and browser navigation', async () => {
        const fetcher = vi
            .fn<typeof fetch>()
            .mockImplementation(async () => response(payload()));
        vi.stubGlobal('fetch', fetcher);
        const { result, rerender } = renderHook(
            ({ url }) => useControlledProduct(undefined, url),
            { initialProps: { url: scoped } },
        );
        await waitFor(() => expect(result.current.loading).toBe(false));
        expect(fetcher.mock.calls[0][0]).toBe(read);
        await act(async () => {
            await result.current.refresh();
        });
        expect(fetcher.mock.calls[1][0]).toBe(read);
        rerender({
            url: '/emar/controlled?site_id=8&client_id=43&date=2026-09-27',
        });
        await waitFor(() => expect(result.current.loading).toBe(false));
        expect(fetcher.mock.lastCall?.[0]).toBe(
            '/emar/controlled/product?site_id=8&client_id=43&date=2026-09-27',
        );
        rerender({ url: scoped });
        await waitFor(() => expect(result.current.loading).toBe(false));
        expect(fetcher.mock.lastCall?.[0]).toBe(read);
    });

    it('uses popstate for workspaces without an Inertia page URL', async () => {
        window.history.replaceState(null, '', scoped);
        const fetcher = vi
            .fn<typeof fetch>()
            .mockImplementation(async () => response(payload()));
        vi.stubGlobal('fetch', fetcher);
        const { result } = renderHook(() => useControlledProduct());
        await waitFor(() => expect(result.current.loading).toBe(false));
        expect(fetcher.mock.lastCall?.[0]).toBe(read);
        act(() => {
            window.history.replaceState(
                null,
                '',
                '/emar/controlled?client_id=43&date=2026-04-05',
            );
            window.dispatchEvent(new PopStateEvent('popstate'));
        });
        await waitFor(() => expect(result.current.loading).toBe(false));
        expect(fetcher.mock.lastCall?.[0]).toBe(
            '/emar/controlled/product?client_id=43&date=2026-04-05',
        );
    });

    it('hides the old person while a new scope loads and clears denied reads', async () => {
        const pending = deferredResponse();
        const fetcher = vi
            .fn<typeof fetch>()
            .mockResolvedValueOnce(response(payload('First person')))
            .mockReturnValueOnce(pending.promise);
        vi.stubGlobal('fetch', fetcher);
        const { result, rerender } = renderHook(
            ({ url }) => useControlledProduct(undefined, url),
            { initialProps: { url: scoped } },
        );
        await waitFor(() =>
            expect(result.current.payload?.current_user_name).toBe(
                'First person',
            ),
        );
        rerender({ url: '/emar/controlled?client_id=999999999' });
        expect(result.current.payload).toBeNull();
        expect(result.current.loading).toBe(true);
        await act(async () => {
            pending.resolve(response({ message: 'Not found' }, 404));
        });
        await waitFor(() => expect(result.current.loading).toBe(false));
        expect(result.current.payload).toBeNull();
        expect(result.current.error?.status).toBe(404);
    });

    it('refreshes the selected history after an action instead of displaying its unfiltered snapshot', async () => {
        const fetcher = vi
            .fn<typeof fetch>()
            .mockResolvedValueOnce(response(payload('Initial scope')))
            .mockResolvedValueOnce(
                response({
                    message: 'Saved',
                    payload: payload('Unfiltered action snapshot'),
                }),
            )
            .mockResolvedValueOnce(response(payload('Refreshed scope')));
        vi.stubGlobal('fetch', fetcher);
        const { result } = renderHook(() =>
            useControlledProduct(undefined, scoped),
        );
        await waitFor(() => expect(result.current.loading).toBe(false));
        await act(async () => {
            await result.current.act(
                'count',
                { client_medication_id: 17, expected_entry_id: 80 },
                'read-filter-test',
            );
        });
        expect(fetcher.mock.calls[2][0]).toBe(read);
        expect(result.current.payload?.current_user_name).toBe(
            'Refreshed scope',
        );
        expect(
            JSON.parse(String(fetcher.mock.calls[1][1]?.body)),
        ).toMatchObject({ client_medication_id: 17, expected_entry_id: 80 });
    });

    it('does not abort a new person read when an earlier action finishes', async () => {
        const command = deferredResponse();
        const next = deferredResponse();
        const fetcher = vi
            .fn<typeof fetch>()
            .mockResolvedValueOnce(response(payload('First scope')))
            .mockReturnValueOnce(command.promise)
            .mockReturnValueOnce(next.promise);
        vi.stubGlobal('fetch', fetcher);
        const { result, rerender } = renderHook(
            ({ url }) => useControlledProduct(undefined, url),
            { initialProps: { url: scoped } },
        );
        await waitFor(() => expect(result.current.loading).toBe(false));
        let action!: ReturnType<typeof result.current.act>;
        act(() => {
            action = result.current.act(
                'count',
                { client_medication_id: 17 },
                'read-filter-test',
            );
        });
        rerender({ url: '/emar/controlled?client_id=43' });
        await act(async () => {
            command.resolve(
                response({ payload: payload('Old action snapshot') }),
            );
            await action;
        });
        expect(fetcher).toHaveBeenCalledTimes(3);
        expect(fetcher.mock.calls[2][1]?.signal?.aborted).toBe(false);
        expect(result.current.payload).toBeNull();
        await act(async () => {
            next.resolve(response(payload('Next person')));
        });
        await waitFor(() =>
            expect(result.current.payload?.current_user_name).toBe(
                'Next person',
            ),
        );
    });
});
