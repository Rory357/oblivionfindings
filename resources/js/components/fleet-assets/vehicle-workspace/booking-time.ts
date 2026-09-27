import { toDatetimeLocal, WORKER_TIMEZONE } from '@/lib/datetime';

const offsetFormatter = new Intl.DateTimeFormat('en', {
    timeZone: WORKER_TIMEZONE,
    timeZoneName: 'longOffset',
});
export function bookingOffset(instant: string): string {
    const date = new Date(instant);
    if (!Number.isFinite(date.getTime())) return '';
    return (
        offsetFormatter
            .formatToParts(date)
            .find((part) => part.type === 'timeZoneName')
            ?.value.replace('GMT', '') || '+00:00'
    );
}

/** Zero choices is a skipped spring time; two choices is a repeated autumn time. */
export function bookingOffsets(local: string): string[] {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local)) return [];
    const nominal = Date.parse(`${local}:00Z`);
    if (!Number.isFinite(nominal)) return [];
    const candidates = new Set(
        [-86400000, 0, 86400000].map((delta) =>
            bookingOffset(new Date(nominal + delta).toISOString()),
        ),
    );
    return [...candidates]
        .filter((offset) => toDatetimeLocal(`${local}:00${offset}`) === local)
        .sort(
            (a, b) =>
                Date.parse(`${local}:00${a}`) - Date.parse(`${local}:00${b}`),
        );
}
