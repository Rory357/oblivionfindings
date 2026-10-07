import { afterEach, expect, it, vi } from 'vitest';
import { computeCoverageGaps } from './note-rail';
import {
    fmtClock,
    noteCalendarDate,
    noteDate,
    ymd,
    type CatalogueShift,
    type ShiftNote,
} from './shared';
const shift: CatalogueShift = {
    id: 1,
    client_id: 4,
    site_id: 2,
    user_id: 7,
    shift_type: null,
    label: 'Morning support',
    starts_at: '2026-10-04T18:00:00Z',
    ends_at: '2026-10-05T02:00:00Z',
    staff: null,
};
afterEach(() => vi.useRealTimers());
it('uses the worker Monday even when the UTC shift begins on Sunday', () => {
    expect(ymd(noteCalendarDate(shift.starts_at, 'Pacific/Auckland'))).toBe(
        '2026-10-05',
    );
    expect(fmtClock(shift.starts_at, 'Pacific/Auckland')).toBe('7:00am');
});
it('uses an explicit alternative worker timezone', () => {
    expect(ymd(noteCalendarDate(shift.starts_at, 'Pacific/Honolulu'))).toBe(
        '2026-10-04',
    );
    expect(fmtClock(shift.starts_at, 'Pacific/Honolulu')).toBe('8:00am');
});
it('does not turn a missing record date into today', () => {
    expect(
        Number.isNaN(
            noteDate({ shift: null, created_at: null } as ShiftNote).getTime(),
        ),
    ).toBe(true);
});
it('uses the scoped full-week note identities when the displayed page is empty', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-07T01:00:00Z'));
    expect(
        computeCoverageGaps(
            [shift],
            [],
            new Date('2026-10-05T12:00:00'),
            [1],
            'Pacific/Auckland',
        ),
    ).toEqual([]);
});
it('does not suggest a current unfinished shift as missing documentation', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-04T19:00:00Z'));
    expect(
        computeCoverageGaps(
            [shift],
            [],
            new Date('2026-10-05T12:00:00'),
            [],
            'Pacific/Auckland',
        ),
    ).toEqual([]);
});
it('retains earlier writable shifts without a visible note, excluding other weeks', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-14T01:00:00Z'));
    expect(
        computeCoverageGaps(
            [
                shift,
                {
                    ...shift,
                    id: 2,
                    starts_at: '2026-10-11T11:00:00Z',
                    ends_at: '2026-10-11T19:00:00Z',
                },
            ],
            [],
            new Date('2026-10-05T12:00:00'),
            [],
            'Pacific/Auckland',
        ).map((item) => item.shift.id),
    ).toEqual([1]);
});
