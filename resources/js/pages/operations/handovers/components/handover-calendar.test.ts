import { expect, it } from 'vitest';
import {
    fmtShiftRange,
    handoverCalendarDate,
    ymd,
    type Handover,
} from './shared';
const handover = (date: string | null) =>
    ({ created_at: date, outgoing_shift: null }) as Handover;
it('groups the same instant into the configured worker day across midnight and year boundaries', () => {
    const h = handover('2026-12-31T11:30:00Z');
    expect(ymd(handoverCalendarDate(h, 'Pacific/Auckland'))).toBe('2027-01-01');
    expect(ymd(handoverCalendarDate(h, 'America/New_York'))).toBe('2026-12-31');
});
it('uses the outgoing duty date instead of a late-written handover date', () => {
    const h = {
        ...handover('2026-10-10T05:00:00Z'),
        outgoing_shift: { starts_at: '2026-10-04T12:00:00Z' },
    } as Handover;
    expect(ymd(handoverCalendarDate(h, 'Pacific/Auckland'))).toBe('2026-10-05');
});
it('does not invent today when no valid record date exists', () => {
    expect(
        Number.isNaN(
            handoverCalendarDate(handover(null), 'Pacific/Auckland').getTime(),
        ),
    ).toBe(true);
});
it('shows exact configured shift clock times through the NZ spring-forward transition', () => {
    const shift = {
        id: 1,
        label: '',
        shift_type: null,
        starts_at: '2026-09-26T13:30:00Z',
        ends_at: '2026-09-26T14:30:00Z',
    };
    expect(fmtShiftRange(shift, 'Pacific/Auckland')).toBe('01:30–03:30');
});
