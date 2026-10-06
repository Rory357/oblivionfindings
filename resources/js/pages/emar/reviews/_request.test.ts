import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reviewRequest, ReviewRequestError } from './_request';

beforeEach(() => {
    document.head.innerHTML =
        '<meta name="csrf-token" content="synthetic-csrf">';
});
afterEach(() => {
    vi.unstubAllGlobals();
    document.head.innerHTML = '';
});
describe('confirmed review saves and recoverable failures', () => {
    it('uses same-origin JSON/CSRF and requires saved:true', async () => {
        const fetch = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ saved: true, review_id: 8 }), {
                status: 200,
            }),
        );
        vi.stubGlobal('fetch', fetch);
        await expect(
            reviewRequest(
                '/emar/reviews/8',
                { revision: 1, scheduled_date: '2026-10-20' },
                'put',
            ),
        ).resolves.toEqual({ saved: true, review_id: 8 });
        expect(fetch).toHaveBeenCalledWith(
            '/emar/reviews/8',
            expect.objectContaining({
                method: 'PUT',
                credentials: 'same-origin',
                headers: expect.objectContaining({
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': 'synthetic-csrf',
                }),
            }),
        );
    });
    it('stages a private source with FormData and does not set a multipart boundary manually', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValue(
                new Response(JSON.stringify({ saved: true }), { status: 200 }),
            );
        vi.stubGlobal('fetch', fetch);
        const file = new File(['synthetic'], 'synthetic.pdf', {
            type: 'application/pdf',
        });
        await reviewRequest('/emar/reviews/8/complete', {
            revision: 1,
            source: file,
            items: '[]',
        });
        const options = fetch.mock.calls[0][1];
        expect(options.body).toBeInstanceOf(FormData);
        expect(options.body.get('source')).toBe(file);
        expect(options.headers['Content-Type']).toBeUndefined();
    });
    it.each([403, 404, 409, 419, 503])(
        'keeps a %s failure in the form without reporting saved',
        async (status) => {
            vi.stubGlobal(
                'fetch',
                vi
                    .fn()
                    .mockResolvedValue(
                        new Response(
                            JSON.stringify({ message: 'Synthetic failure' }),
                            { status },
                        ),
                    ),
            );
            const failure = await reviewRequest('/emar/reviews/8/complete', {
                revision: 1,
            }).catch((problem) => problem);
            expect(failure).toBeInstanceOf(ReviewRequestError);
            expect(failure.status).toBe(status);
            expect(failure.errors._request).toBeTruthy();
        },
    );
    it('retains server field errors', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(
                    JSON.stringify({
                        errors: { owner_id: ['Choose a current owner.'] },
                    }),
                    { status: 422 },
                ),
            ),
        );
        await expect(
            reviewRequest('/emar/reviews', { owner_id: 99 }),
        ).rejects.toMatchObject({
            errors: { owner_id: 'Choose a current owner.' },
            status: 422,
        });
    });
    it('does not treat a redirected sign-in page as a successful save', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    new Response('<html>Sign in</html>', { status: 200 }),
                ),
        );
        await expect(
            reviewRequest('/emar/reviews', { request_uuid: 'synthetic' }),
        ).rejects.toBeInstanceOf(ReviewRequestError);
    });
    it('keeps a network failure recoverable', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockRejectedValue(new TypeError('Synthetic network failure')),
        );
        await expect(
            reviewRequest('/emar/reviews', { request_uuid: 'synthetic' }),
        ).rejects.toMatchObject({
            status: null,
            errors: { _request: expect.stringContaining('retained') },
        });
    });
});
