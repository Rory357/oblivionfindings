import { formatDateTime, formatTime, WORKER_TIMEZONE } from '@/lib/datetime';

/**
 * Times on the Devices screens, in NZ wall-clock time (WRITING_STYLE_GUIDE W3/W4): a time today
 * is "7:41 am", an older one "Tue 27 Oct, 7:41 am"; ages and durations are "3 min ago", "2 h 5 min".
 */

const nzDay = (date: Date) =>
    date.toLocaleDateString('en-NZ', { timeZone: WORKER_TIMEZONE });

export function seenTime(iso: string | null, now: Date = new Date()): string {
    if (!iso) return '—';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '—';
    return nzDay(date) === nzDay(now) ? formatTime(date) : formatDateTime(date);
}

export function durationWords(fromIso: string, now: Date = new Date()): string {
    const minutes = Math.max(
        0,
        Math.floor((now.getTime() - new Date(fromIso).getTime()) / 60_000),
    );
    if (minutes < 1) return 'under 1 min';
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    if (hours < 48) {
        const rest = minutes % 60;
        return rest > 0 && hours < 10 ? `${hours} h ${rest} min` : `${hours} h`;
    }
    const days = Math.floor(hours / 24);
    return `${days} days`;
}

export function agoWords(iso: string, now: Date = new Date()): string {
    const date = new Date(iso);
    const minutes = Math.floor((now.getTime() - date.getTime()) / 60_000);
    if (minutes < 1) return 'just now';
    if (minutes < 60 * 24) return `${durationWords(iso, now)} ago`;
    return `on ${seenTime(iso, now)}`;
}

/** Fill a server line's "{time}" or "{ago}". */
export function timedText(
    line: { text: string; at: string | null } | null | undefined,
    now: Date = new Date(),
): string {
    if (!line) return '';
    if (!line.at) return line.text.replace(/\{time\}|\{ago\}/g, '—');
    const at = line.at;
    return line.text
        .replace('{time}', seenTime(at, now))
        .replace('{ago}', agoWords(at, now));
}
