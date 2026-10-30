import { WORKER_TIMEZONE } from '@/lib/datetime';
import { rosterInstant, rosterWallTime } from '@/lib/roster-time';

export function shiftWallInput(
    value?: string | null,
    zone = WORKER_TIMEZONE,
): string {
    if (!value) return '';
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/.test(value))
        return value.slice(0, 16);
    if (!Number.isFinite(Date.parse(value))) return '';
    return rosterWallTime(value, zone).slice(0, 16);
}

/** Preserve the precise original instant when an unchanged existing clock is ambiguous. */
export function shiftInputInstant(
    value: string,
    zone = WORKER_TIMEZONE,
    original?: string | null,
): string {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value))
        throw new Error('Choose a date and exact time.');
    if (
        original &&
        /(?:Z|[+-]\d{2}:\d{2})$/.test(original) &&
        shiftWallInput(original, zone) === value
    )
        return new Date(original).toISOString();
    try {
        return rosterInstant(`${value}:00`, zone).toISOString();
    } catch {
        throw new Error(
            'This local time is unavailable or occurs twice during a daylight-saving change. Choose another exact time.',
        );
    }
}

export function shiftWeekday(
    value: string | null | undefined,
    zone = WORKER_TIMEZONE,
): 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun' {
    const day = shiftWallInput(value ?? new Date().toISOString(), zone).slice(
        0,
        10,
    );
    return (
        (['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as const)[
            new Date(`${day}T12:00:00Z`).getUTCDay()
        ] ?? 'mon'
    );
}
