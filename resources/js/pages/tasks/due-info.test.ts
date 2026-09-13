import { afterEach, expect, it, vi } from 'vitest';
import { dueInfo } from './types';

afterEach(() => vi.useRealTimers());

it('shows the actual remaining hours and minutes for a same-day approval deadline', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-10T09:30:00+12:00'));
    expect(
        dueInfo({ dueAt: '2026-09-10T12:00:00+12:00', overdue: false }).label,
    ).toBe('Due in 2h');
    expect(
        dueInfo({ dueAt: '2026-09-10T09:45:00+12:00', overdue: false }).label,
    ).toBe('Due in 15m');
    expect(
        dueInfo({ dueAt: '2026-09-09T23:00:00Z', overdue: false }).label,
    ).toBe('Due in 1h');
});

it('retains overdue and undated task meaning', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-10T09:30:00+12:00'));
    expect(
        dueInfo({ dueAt: '2026-09-10T08:30:00+12:00', overdue: true }).label,
    ).toBe('Overdue');
    expect(dueInfo({ dueAt: null, overdue: false }).label).toBe('—');
});
