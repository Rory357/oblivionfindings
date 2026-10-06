import { act, renderHook } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { useStockCommand } from './_requests';
afterEach(() => vi.unstubAllGlobals());
it('retains receipt identity when the witness re-enters a PIN after an unknown response', async () => {
    const fetch = vi
        .fn()
        .mockRejectedValueOnce(new TypeError('connection lost'))
        .mockResolvedValue({
            ok: true,
            json: async () => ({ success: true, lot_ids: [7] }),
        });
    vi.stubGlobal('fetch', fetch);
    const { result } = renderHook(useStockCommand);
    const instruction = {
        action: 'receive',
        client_medication_id: 1,
        packs: [{ quantity: '4', batch_number: 'TEST' }],
        witnessed_by: 5,
    };
    await act(async () => {
        await result.current.run({
            ...instruction,
            witness_credential: '123456',
        });
    });
    expect(result.current.errors.save).toMatch(/Could not confirm/);
    expect(result.current.uncertain).toBe(true);
    await act(async () => {
        await result.current.run({
            ...instruction,
            packs: [{ quantity: '99', batch_number: 'TEST' }],
            witness_credential: '654321',
        });
    });
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(result.current.errors.save).toMatch(
        /earlier save has not been confirmed/,
    );
    await act(async () => {
        await result.current.run({
            ...instruction,
            witness_credential: '654321',
        });
    });
    const first = JSON.parse(fetch.mock.calls[0][1].body),
        retry = JSON.parse(fetch.mock.calls[1][1].body);
    expect(retry.request_uuid).toBe(first.request_uuid);
    expect(retry.witness_credential).toBe('654321');
    expect(result.current.uncertain).toBe(false);
    await act(async () => {
        await result.current.run({
            ...instruction,
            packs: [{ quantity: '5', batch_number: 'TEST' }],
            witness_credential: '654321',
        });
    });
    expect(JSON.parse(fetch.mock.calls[2][1].body).request_uuid).not.toBe(
        first.request_uuid,
    );
});
