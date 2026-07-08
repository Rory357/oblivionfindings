/* -------------------------------------------------------------------------- */
/*  Worker-facing date/time formatting                                        */
/* -------------------------------------------------------------------------- */
/*
 * PR 20 — one locale, one timezone, one small set of formats for every
 * frontline surface. Anything workers see on `/my-day`, `/meds/today`, the
 * guided med round, the incident wizard, handovers, clock-in, alerts and
 * saved-state chips should go through this helper so shifts, med times and
 * "when did that happen" all read the same way page to page.
 *
 * Patterns:
 *   - Date:          "Fri 17 Apr"
 *   - Time:          "8:00 pm"     (lowercase am/pm, no AM/PM case flip)
 *   - Date + time:   "Fri 17 Apr, 8:00 pm"
 *   - Relative:      "just now", "12m ago", "in 15m", "2h ago", "3d ago"
 *                    (falls back to the date format once past a week)
 *
 * Locale is `en-NZ`, timezone is `Pacific/Auckland`. Inputs may be ISO
 * strings, epoch milliseconds, or `Date` instances; nullable inputs return
 * the fallback ("—") instead of throwing.
 */

export const WORKER_LOCALE = 'en-NZ';
export const WORKER_TIMEZONE = 'Pacific/Auckland';

const DEFAULT_FALLBACK = '—';

type DateInput = string | number | Date | null | undefined;

function toDate(value: DateInput): Date | null {
    if (value === null || value === undefined || value === '') return null;
    const d = value instanceof Date ? value : new Date(value);
    return Number.isNaN(d.getTime()) ? null : d;
}

/**
 * "Fri 17 Apr" — short weekday, day, short month. No year (workers are in
 * the now; year appears only when explicitly needed elsewhere).
 */
export function formatDate(value: DateInput, fallback: string = DEFAULT_FALLBACK): string {
    const d = toDate(value);
    if (!d) return fallback;
    return d
        .toLocaleDateString(WORKER_LOCALE, {
            timeZone: WORKER_TIMEZONE,
            weekday: 'short',
            day: 'numeric',
            month: 'short',
        })
        .replace(/,\s*/g, ' ')
        .trim();
}

/**
 * "8:00 pm" — 12-hour, lowercase am/pm, single space. Consistent across
 * shift times, med times, clock-in/out, handover submitted, etc.
 */
export function formatTime(value: DateInput, fallback: string = DEFAULT_FALLBACK): string {
    const d = toDate(value);
    if (!d) return fallback;
    const raw = d.toLocaleTimeString(WORKER_LOCALE, {
        timeZone: WORKER_TIMEZONE,
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
    // Engines emit "8:00 PM" / "8:00 pm" / "8:00\u202Fpm" — normalise to
    // "8:00 pm" so the same timestamp looks the same on every device.
    return raw
        .replace(/\u202F/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}

/** "Fri 17 Apr, 8:00 pm" — the default frontline timestamp. */
export function formatDateTime(value: DateInput, fallback: string = DEFAULT_FALLBACK): string {
    const d = toDate(value);
    if (!d) return fallback;
    return `${formatDate(d)}, ${formatTime(d)}`;
}

/**
 * "17 April 2026" — long-form date for records, registers and audit views
 * where the year matters (incident logs, hazard registers, respite stays).
 */
export function formatDateLong(value: DateInput, fallback: string = DEFAULT_FALLBACK): string {
    const d = toDate(value);
    if (!d) return fallback;
    return d.toLocaleDateString(WORKER_LOCALE, {
        timeZone: WORKER_TIMEZONE,
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

/** "17 April 2026, 8:00 pm" — long-form timestamp for record views. */
export function formatDateTimeLong(value: DateInput, fallback: string = DEFAULT_FALLBACK): string {
    const d = toDate(value);
    if (!d) return fallback;
    return `${formatDateLong(d)}, ${formatTime(d)}`;
}

/**
 * "2026-07-07T22:00" — a UTC instant expressed as NZ wall time for a
 * `<input type="datetime-local">`. Prefill counterpart of servers that parse
 * datetime-local strings in the worker timezone before storing UTC; slicing
 * the raw ISO string instead shows UTC wall time (12 hours out).
 */
export function toDatetimeLocal(value: DateInput): string {
    const d = toDate(value);
    if (!d) return '';
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: WORKER_TIMEZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(d);
    const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
    const hour = get('hour') === '24' ? '00' : get('hour');
    return `${get('year')}-${get('month')}-${get('day')}T${hour}:${get('minute')}`;
}

/**
 * "12m ago" / "in 15m" / "2h ago" / "3d ago" — calm relative phrasing for
 * freshness chips, alert rows, handover submitted, draft saved.
 *
 * Past/future symmetrical. Falls back to `formatDate` once older than a week
 * so long tails don't read "47d ago".
 */
export function formatRelative(
    value: DateInput,
    now: number = Date.now(),
    fallback: string = DEFAULT_FALLBACK,
): string {
    const d = toDate(value);
    if (!d) return fallback;

    const diffMs = now - d.getTime();
    const abs = Math.abs(diffMs);
    const past = diffMs >= 0;

    const MINUTE = 60_000;
    const HOUR = 60 * MINUTE;
    const DAY = 24 * HOUR;
    const WEEK = 7 * DAY;

    if (abs < MINUTE) return past ? 'just now' : 'in a moment';
    if (abs < HOUR) {
        const m = Math.floor(abs / MINUTE);
        return past ? `${m}m ago` : `in ${m}m`;
    }
    if (abs < DAY) {
        const h = Math.floor(abs / HOUR);
        return past ? `${h}h ago` : `in ${h}h`;
    }
    if (abs < WEEK) {
        const dayCount = Math.floor(abs / DAY);
        return past ? `${dayCount}d ago` : `in ${dayCount}d`;
    }

    return formatDate(d);
}
