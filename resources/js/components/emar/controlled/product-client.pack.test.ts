import { afterEach, expect, it, vi } from 'vitest';
import { recordControlledAction } from './product-client';
afterEach(() => vi.unstubAllGlobals());
it('sends nested exact pack allocations alongside a loss photo without stringifying objects', async () => {
    const fetch = vi.fn().mockResolvedValue({
        ok: true,
        headers: new Headers({ 'Content-Type': 'application/json' }),
        json: async () => ({ success: true }),
    });
    vi.stubGlobal('fetch', fetch);
    await recordControlledAction(
        'loss_report',
        {
            client_medication_id: 1,
            quantity: '2',
            photo: new File(['synthetic'], 'loss.png', { type: 'image/png' }),
            pack_lines: [{ lot_id: 7, revision: 4, quantity: '2' }],
            witnessed_by: 3,
            witness_credential: '123456',
        },
        'same-request',
    );
    const body = fetch.mock.calls[0][1].body as FormData;
    expect(body.get('pack_lines[0][lot_id]')).toBe('7');
    expect(body.get('pack_lines[0][revision]')).toBe('4');
    expect(body.get('pack_lines[0][quantity]')).toBe('2');
    expect(body.get('client_request_uuid')).toBe('same-request');
    expect(body.get('photo')).toBeInstanceOf(File);
});
