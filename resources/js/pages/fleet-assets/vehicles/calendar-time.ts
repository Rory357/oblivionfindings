import {
    bookingOffset,
    bookingOffsets,
} from '@/components/fleet-assets/vehicle-workspace/booking-time';
import { toDatetimeLocal } from '@/lib/datetime';

export type CalendarTimeProposal = {
    start: string;
    end: string;
    startOffset?: string;
    endOffset?: string;
};

/** Preserve elapsed source duration; every repeated start occurrence is explicit. */
export function calendarMoveChoices(
    start: string,
    end: string,
    target: string,
): CalendarTimeProposal[] {
    const duration = Date.parse(end) - Date.parse(start);
    if (!Number.isFinite(duration) || duration <= 0) return [];
    return bookingOffsets(target).map((offset) => {
        const endsAt = new Date(
            Date.parse(`${target}:00${offset}`) + duration,
        ).toISOString();
        return {
            start: target,
            end: toDatetimeLocal(endsAt),
            startOffset: offset,
            endOffset: bookingOffset(endsAt),
        };
    });
}

/** Shared calendar dates represent the displayed wall clock, not a source instant. */
export function calendarLocal(date: Date): string {
    const pad = (value: number) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function calendarDate(instant: string | Date): Date {
    return new Date(toDatetimeLocal(instant));
}
