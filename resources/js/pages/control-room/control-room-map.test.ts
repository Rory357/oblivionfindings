import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    deviceMarkerStyle,
    escapeMapHtml,
    mapDeviceActivityLabel,
    oldPositionWords,
    positionAge,
} from './map';

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

    it('never calls a position over 24 hours old live, and says how old it is', () => {
        const now = new Date('2026-10-24T12:00:00Z').getTime();

        expect(positionAge('2026-10-24T11:55:00Z', now)).toEqual({
            old: false,
            words: 'last seen 5 min ago',
        });
        expect(positionAge('2026-10-23T13:00:00Z', now).old).toBe(false);
        expect(positionAge('2026-10-21T12:00:00Z', now)).toEqual({
            old: true,
            words: 'last seen 3 days ago',
        });
        // No time at all can't be shown as current either.
        expect(positionAge(null, now).old).toBe(true);

        expect(
            mapDeviceActivityLabel({ status: 'online', last_seen_at: '2026-10-21T12:00:00Z' }, now),
        ).toBe('Old position');
        expect(
            mapDeviceActivityLabel({ status: 'offline', last_seen_at: '2026-10-21T12:00:00Z' }, now),
        ).toBe('Offline');
        expect(oldPositionWords('2026-10-21T12:00:00Z', now)).toBe(
            'Last seen 3 days ago, so it may not be where it is now.',
        );
        expect(oldPositionWords(null, now)).toMatch(/wasn’t recorded/);
    });

    it('draws an old position faded with a dashed outline, a current one solid', () => {
        expect(deviceMarkerStyle({ css: 'var(--category-hr)' }, true)).toContain('dashed');
        expect(deviceMarkerStyle({ css: 'var(--category-hr)' }, true)).toContain('opacity');
        expect(deviceMarkerStyle({ css: 'var(--category-hr)' }, false)).toContain('solid');
        expect(deviceMarkerStyle({ css: 'var(--category-hr)' }, false)).not.toContain('dashed');
    });
});
