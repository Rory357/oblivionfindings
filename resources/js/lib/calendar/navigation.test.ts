import { expect, it } from 'vitest';
import {
    calendarHref,
    calendarLocation,
    calendarReturn,
    drillCalendarHref,
} from './navigation';

it('matches the server byte limit for accented and emoji search context', () => {
    for (const [accepted, rejected] of [
        ['é'.repeat(100), 'é'.repeat(101)],
        ['🧭'.repeat(50), '🧭'.repeat(51)],
    ]) {
        expect(
            calendarLocation('/calendar?q=' + encodeURIComponent(accepted)).q,
        ).toBe(accepted);
        expect(
            calendarLocation('/calendar?q=' + encodeURIComponent(rejected)).q,
        ).toBe('');
    }
});

it('restores an actual browsed date, visible layers, Site and search through a drill link', () => {
    const origin = calendarHref('/calendar', {
        date: new Date(2025, 3, 6, 12),
        view: 'week',
        sources: new Set(['event', 'drill']),
        siteId: 2,
        q: 'Assembly review',
        color: 'owner',
        density: 'compact',
    });
    const link = drillCalendarHref(
        '/health-safety/drills/8?section=findings&finding=12&action=resolve&return_to=%2Fcalendar',
        origin,
    )!;
    const parsed = new URL(link, 'https://example.test');
    expect(parsed.searchParams.get('section')).toBe('findings');
    expect(parsed.searchParams.get('finding')).toBe('12');
    expect(parsed.searchParams.has('action')).toBe(false);
    expect(calendarLocation(parsed.searchParams.get('return_to')!)).toEqual({
        date: '2025-04-06',
        view: 'week',
        sources: ['event', 'drill'],
        siteId: 2,
        q: 'Assembly review',
        color: 'owner',
        density: 'compact',
    });
});
it('preserves a deliberately empty layer selection and the actual profile Calendar tab', () => {
    const href = calendarHref('/sites/2?tab=calendar', {
        date: new Date(2026, 9, 8, 12),
        view: 'agenda',
        sources: [],
        siteId: 'all',
        q: '',
        color: 'source',
        density: 'comfortable',
    });
    expect(href).toContain('tab=calendar');
    expect(calendarLocation(href).sources).toEqual([]);
    expect(calendarReturn('/sites/2?tab=drills')).toBeNull();
});
it('rejects forged paths and drops impossible days, invalid display settings and commands', () => {
    expect(
        calendarReturn(
            '/calendar?date=2026-02-30&view=delete&sources=drill,../private&site_id=-2&q[]=private&action=create&return_to=%2Fusers&density=huge',
        ),
    ).toBe('/calendar');
    for (const value of [
        'https://outside.test/calendar',
        '//outside.test/calendar',
        '/calendar#execute',
        '/sites/2/../3/calendar',
        '/calendar\\outside',
        '/sites/calendar',
    ])
        expect(calendarReturn(value)).toBeNull();
    expect(drillCalendarHref('/health-safety/procedures/8', '/calendar')).toBe(
        '/health-safety/procedures/8',
    );
});
