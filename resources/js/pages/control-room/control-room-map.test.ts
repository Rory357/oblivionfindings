import { afterEach, describe, expect, it, vi } from 'vitest';

import { escapeMapHtml, mapDeviceActivityLabel } from './map';

describe('Control Room governed map presentation', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('escapes provider and operator text before Leaflet popup HTML is built', () => {
        expect(escapeMapHtml('<img src=x onerror="steal()"> & unsafe')).toBe(
            '&lt;img src=x onerror=&quot;steal()&quot;&gt; &amp; unsafe',
        );
    });

    it('describes signal freshness without inventing movement', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-03T12:00:00Z'));

        expect(
            mapDeviceActivityLabel({
                status: 'online',
                last_seen_at: '2026-08-03T11:59:00Z',
            }),
        ).toBe('Recently updated');
        expect(
            mapDeviceActivityLabel({
                status: 'online',
                last_seen_at: '2026-08-03T11:30:00Z',
            }),
        ).toBe('Reporting');
        expect(
            mapDeviceActivityLabel({
                status: 'offline',
                last_seen_at: '2026-08-03T11:59:00Z',
            }),
        ).toBe('Offline');
    });
});
