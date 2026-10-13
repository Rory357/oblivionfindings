import { toDateInput } from '@/lib/datetime';
import { eventTimeInput, eventTimeInstant } from './calendar/event-time';

export const announcementTimeInput = (value?: string | null) =>
    value ? eventTimeInput(value, false) : '';

export const announcementDayInput = (value?: string | null) =>
    value
        ? /^\d{4}-\d{2}-\d{2}$/.test(value)
            ? value
            : toDateInput(value)
        : '';

/** Retain the exact instant when an editor has not changed its displayed minute. */
export function announcementPublishInstant(
    value: string,
    original?: string | null,
): string | null {
    if (!value) return null;
    if (original && announcementTimeInput(original) === value) return original;
    return eventTimeInstant(value, false, original ?? undefined);
}

/** Date pickers mean the end of the selected NZ day, independent of browser zone. */
export function announcementDayEnd(
    value: string,
    original?: string | null,
): string | null {
    if (!value) return null;
    if (
        original &&
        original.includes('T') &&
        announcementDayInput(original) === value
    )
        return original;
    return new Date(
        Date.parse(eventTimeInstant(`${value}T23:59`, false)) + 59_000,
    ).toISOString();
}
