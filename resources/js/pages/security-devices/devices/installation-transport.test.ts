import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installationJSON } from './installation-profile';

beforeEach(() => {
    document.head.innerHTML =
        '<meta name="csrf-token" content="fictional-csrf">';
});
afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
    document.head.innerHTML = '';
});
describe('installation request transport', () => {
    it('uses only the same-origin endpoint with CSRF no caching and no redirect following', async () => {
        const fetcher = vi
            .fn()
            .mockResolvedValue(
                new Response('{"configured":true}', {
                    headers: { 'Content-Type': 'application/json' },
                }),
            );
        vi.stubGlobal('fetch', fetcher);
        await expect(
            installationJSON(34, 'bootstrap', new AbortController().signal, {
                expected_context: 'a'.repeat(64),
            }),
        ).resolves.toEqual({ configured: true });
        expect(fetcher).toHaveBeenCalledWith(
            '/security-devices/devices/34/endpoint/bootstrap',
            expect.objectContaining({
                method: 'POST',
                credentials: 'same-origin',
                redirect: 'error',
                cache: 'no-store',
                headers: expect.objectContaining({
                    'X-CSRF-TOKEN': 'fictional-csrf',
                }),
                body: JSON.stringify({ expected_context: 'a'.repeat(64) }),
            }),
        );
    });
    it('rejects an oversized streamed response and cancels its reader', async () => {
        const cancel = vi.fn();
        const stream = new ReadableStream<Uint8Array>({
            start(controller) {
                controller.enqueue(new Uint8Array(16385));
            },
            cancel,
        });
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    new Response(stream, {
                        headers: { 'Content-Type': 'application/json' },
                    }),
                ),
        );
        await expect(
            installationJSON(
                34,
                'bootstrap-options',
                new AbortController().signal,
            ),
        ).rejects.toThrow('too large');
        expect(cancel).toHaveBeenCalledOnce();
    });
    it('refuses HTML login responses and invalid computer identifiers', async () => {
        const fetcher = vi
            .fn()
            .mockResolvedValue(
                new Response('<html>Sign in</html>', {
                    headers: { 'Content-Type': 'text/html' },
                }),
            );
        vi.stubGlobal('fetch', fetcher);
        await expect(
            installationJSON(
                34,
                'bootstrap-options',
                new AbortController().signal,
            ),
        ).rejects.toThrow('not confirmed');
        await expect(
            installationJSON(
                NaN,
                'bootstrap-options',
                new AbortController().signal,
            ),
        ).rejects.toThrow('Invalid computer');
        expect(fetcher).toHaveBeenCalledOnce();
    });
    it('bounds a stuck request without issuing an automatic replacement', async () => {
        vi.useFakeTimers();
        const fetcher = vi.fn(
            (_url: string, init: RequestInit) =>
                new Promise((_resolve, reject) => {
                    init.signal?.addEventListener(
                        'abort',
                        () => reject(new DOMException('Aborted', 'AbortError')),
                        { once: true },
                    );
                }),
        );
        vi.stubGlobal('fetch', fetcher);
        const request = installationJSON(
            34,
            'bootstrap',
            new AbortController().signal,
            { expected_context: 'a'.repeat(64) },
        );
        const check = expect(request).rejects.toMatchObject({
            name: 'AbortError',
        });
        await vi.advanceTimersByTimeAsync(15001);
        await check;
        expect(fetcher).toHaveBeenCalledOnce();
    });
});
