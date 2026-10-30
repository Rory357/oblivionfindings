import { afterEach, expect, it, vi } from 'vitest';
import { api, download, REGISTER, stamp } from './api';

afterEach(() => vi.unstubAllGlobals());

it('shows the worker date and time across daylight saving and UTC date boundaries', () => {
    expect(stamp('2026-09-27T01:00:00Z')).toBe('27 September 2026, 2:00 pm');
    expect(stamp('2026-07-12T20:15:00Z')).toBe('13 July 2026, 8:15 am');
    expect(stamp('2026-09-26T14:30:00Z')).toBe('27 September 2026, 3:30 am');
    expect(stamp('invalid')).toBe('Not recorded');
    expect(stamp(null)).toBe('Not recorded');
});

it('rejects a sign-in page returned in place of an exported report', async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            headers: new Headers({ 'Content-Type': 'text/html' }),
        }),
    );
    await expect(
        download(`${REGISTER}/stocktakes/7/export/pdf`, 'stocktake-7.pdf'),
    ).rejects.toMatchObject({ status: 502 });
});

it('sends register commands to the mounted route with CSRF protection', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
        ok: true,
        headers: new Headers({ 'Content-Type': 'application/json' }),
        json: async () => ({ version: 2 }),
    });
    vi.stubGlobal('fetch', fetchMock);
    document.cookie = 'XSRF-TOKEN=encoded%3Dtoken';
    await expect(
        api('/stocktakes/7', 'PATCH', { action: 'found' }),
    ).resolves.toEqual({ version: 2 });
    expect(fetchMock).toHaveBeenCalledWith(
        `${REGISTER}/stocktakes/7`,
        expect.objectContaining({
            method: 'PATCH',
            credentials: 'same-origin',
            body: '{"action":"found"}',
            headers: expect.objectContaining({
                'X-XSRF-TOKEN': 'encoded=token',
            }),
        }),
    );
});

it('preserves canonical inventory routes and reports a lost session', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValue({ ok: false, status: 419, json: async () => ({}) });
    vi.stubGlobal('fetch', fetchMock);
    await expect(api('/fleet-assets/assets?selection=1')).rejects.toMatchObject(
        {
            status: 419,
            message: 'Your session expired. Reload and sign in again.',
        },
    );
    expect(fetchMock.mock.calls[0][0]).toBe('/fleet-assets/assets?selection=1');
});
