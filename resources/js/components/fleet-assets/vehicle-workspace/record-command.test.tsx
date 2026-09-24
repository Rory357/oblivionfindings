import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useVehicleRecordCommand } from './record-command';

type Saved = { id: number };
const isSaved = (value: unknown): value is Saved =>
    !!value &&
    typeof value === 'object' &&
    'id' in value &&
    typeof value.id === 'number';
const response = (status: number, body: unknown) => ({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('vehicle record command recovery', () => {
    it('retries the identical command after an uncertain save, even if a caller supplies different values', async () => {
        const request = vi
            .fn()
            .mockRejectedValueOnce(new Error('connection lost'))
            .mockResolvedValueOnce(response(200, { id: 41 }));
        vi.stubGlobal('fetch', request);
        const { result } = renderHook(() => useVehicleRecordCommand(isSaved));
        await act(async () => {
            await result.current.submit('/vehicle/evidence', { value: 120 });
        });
        expect(result.current.uncertain).toBe(true);
        expect(result.current.locked).toBe(true);
        await act(async () => {
            await result.current.submit('/vehicle/evidence', { value: 999 });
        });
        expect(request.mock.calls[1][1].body).toBe(
            request.mock.calls[0][1].body,
        );
        expect(request.mock.calls[1][1].headers['Idempotency-Key']).toBe(
            request.mock.calls[0][1].headers['Idempotency-Key'],
        );
        expect(result.current.uncertain).toBe(false);
    });

    it('keeps field errors editable, assigning a new command identity only when the rejected payload changes', async () => {
        const request = vi
            .fn()
            .mockResolvedValueOnce(
                response(422, { errors: { expires_on: ['Choose a date.'] } }),
            )
            .mockResolvedValueOnce(response(200, { id: 42 }));
        vi.stubGlobal('fetch', request);
        const { result } = renderHook(() => useVehicleRecordCommand(isSaved));
        await act(async () => {
            await result.current.submit('/vehicle/evidence', {
                expires_on: null,
            });
        });
        expect(result.current.errors.expires_on).toBe('Choose a date.');
        expect(result.current.locked).toBe(false);
        await act(async () => {
            await result.current.submit('/vehicle/evidence', {
                expires_on: '2027-04-01',
            });
        });
        expect(request.mock.calls[1][1].headers['Idempotency-Key']).not.toBe(
            request.mock.calls[0][1].headers['Idempotency-Key'],
        );
    });

    it('requires source refresh after a stale-version conflict and never retries over the newer source', async () => {
        const request = vi
            .fn()
            .mockResolvedValue(response(409, { message: 'Changed' }));
        vi.stubGlobal('fetch', request);
        const { result } = renderHook(() => useVehicleRecordCommand(isSaved));
        await act(async () => {
            await result.current.submit('/vehicle/evidence', {
                expected_current_version_id: 1,
            });
        });
        expect(result.current.requiresReload).toBe(true);
        await act(async () => {
            await result.current.submit('/vehicle/evidence', {
                expected_current_version_id: 2,
            });
        });
        expect(request).toHaveBeenCalledTimes(1);
    });

    it('does not treat a redirected login page or malformed success as a saved record', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(response(200, { html: 'Sign in' })),
        );
        const { result } = renderHook(() => useVehicleRecordCommand(isSaved));
        let saved: Saved | null = null;
        await act(async () => {
            saved = await result.current.submit('/vehicle/evidence', {});
        });
        expect(saved).toBeNull();
        expect(result.current.uncertain).toBe(true);
    });
});
