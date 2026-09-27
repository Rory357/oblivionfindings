import { toDatetimeLocal } from '@/lib/datetime';
import type { TransportRecord } from './types';

/** Only future, unstarted plans can change through Transport. Source checks still own saving. */
export function canRescheduleTransport(row: TransportRecord, now = Date.now()) {
    return !!(
        row.can.manage &&
        row.booking &&
        !row.journey &&
        ['pending', 'approved'].includes(row.booking.status) &&
        row.stage !== 'cancelled' &&
        new Date(row.booking.start).getTime() > now
    );
}

/** Shared calendar Date values are Auckland wall time, not UTC instants. */
export function calendarLocal(date: Date) {
    const pad = (value: number) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

const minute = 60_000;
const offsetMinutes = (instant: number) =>
    (Date.parse(`${toDatetimeLocal(instant)}:00Z`) -
        Math.floor(instant / minute) * minute) /
    minute;
const offsetLabel = (offset: number) =>
    `${offset < 0 ? '-' : '+'}${String(Math.floor(Math.abs(offset) / 60)).padStart(2, '0')}:${String(Math.abs(offset) % 60).padStart(2, '0')}`;

/** Resolve Auckland wall time explicitly, independent of the browser timezone.
 * Zero matches is the spring gap; two matches require an explicit offset choice.
 */
export function aucklandTimeChoices(local: string) {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local)) return [];
    const wall = Date.parse(`${local}:00Z`);
    if (!Number.isFinite(wall)) return [];
    const offsets = new Set(
        [-36, 0, 36].map((hours) => offsetMinutes(wall + hours * 60 * minute)),
    );
    return [...offsets]
        .map((offset) => ({
            instant: wall - offset * minute,
            offset: offsetLabel(offset),
        }))
        .filter((choice) => toDatetimeLocal(choice.instant) === local)
        .sort((a, b) => a.instant - b.instant);
}

export function sourceCalendarWindow(start: string, end: string) {
    return {
        start: toDatetimeLocal(start),
        end: toDatetimeLocal(end),
        startOffset: offsetLabel(offsetMinutes(Date.parse(start))),
        endOffset: offsetLabel(offsetMinutes(Date.parse(end))),
    };
}

export function proposedCalendarWindow(
    originalStart: string,
    originalEnd: string,
    target: Date,
    end?: Date,
    mode: 'move' | 'resize' = end ? 'resize' : 'move',
) {
    const start = end
        ? calendarLocal(target)
        : `${calendarLocal(target).slice(0, 10)}T${toDatetimeLocal(originalStart).slice(11)}`;
    const choices = aucklandTimeChoices(start);
    if (choices.length !== 1)
        throw new Error(
            choices.length
                ? 'This departure occurs twice in Auckland. Use Reschedule transport and choose the time offset.'
                : 'This departure falls in Auckland’s daylight-saving gap. Use Reschedule transport and choose another time.',
        );
    const startInstant = choices[0].instant;
    let endInstant: number;
    if (end && mode === 'resize') {
        const ends = aucklandTimeChoices(calendarLocal(end));
        if (ends.length !== 1)
            throw new Error(
                'This return time is ambiguous or unavailable in Auckland. Use Reschedule transport to review the time and offset.',
            );
        endInstant = ends[0].instant;
    } else {
        // Use source instants, not decorated wall Dates: retain real elapsed duration across DST.
        endInstant =
            startInstant + Date.parse(originalEnd) - Date.parse(originalStart);
    }
    return sourceCalendarWindow(
        new Date(startInstant).toISOString(),
        new Date(endInstant).toISOString(),
    );
}
