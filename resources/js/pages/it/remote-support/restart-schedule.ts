import { WORKER_LOCALE, WORKER_TIMEZONE } from '@/lib/datetime';

const formatter = new Intl.DateTimeFormat(WORKER_LOCALE, {
    timeZone: WORKER_TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
});
/** Reject missing/repeated local times rather than guessing an offset during a clock change. */
export function restartTimeUtc(value: string): string | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(
        value,
    );
    if (!match) return null;
    const [, year, month, day, hour, minute, second = '00'] = match;
    const naive = Date.UTC(+year, +month - 1, +day, +hour, +minute, +second);
    const wanted =
        year +
        '-' +
        month +
        '-' +
        day +
        'T' +
        hour +
        ':' +
        minute +
        ':' +
        second;
    const matches: string[] = [];
    for (let offset = -14 * 60; offset <= 14 * 60; offset += 15) {
        const instant = new Date(naive - offset * 60000);
        const parts = Object.fromEntries(
            formatter
                .formatToParts(instant)
                .map((part) => [part.type, part.value]),
        );
        const local =
            parts.year +
            '-' +
            parts.month +
            '-' +
            parts.day +
            'T' +
            parts.hour +
            ':' +
            parts.minute +
            ':' +
            parts.second;
        if (local === wanted)
            matches.push(instant.toISOString().replace('.000Z', 'Z'));
    }
    return matches.length === 1 ? matches[0] : null;
}
