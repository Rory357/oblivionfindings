/**
 * Site Calendar — portable client logic (ported from the prototype's cal-recur.js).
 *
 * Pure helpers: RRULE <-> preset, .ics generation, Google/Outlook deep links,
 * conflict detection, and the colour-by resolver. No React / DOM coupling beyond
 * the .ics download helper.
 *
 * Recurrence expansion itself happens server-side (SiteCalendarService); these
 * helpers operate on the normalised CalendarItem produced by the events feed.
 */

export type CalendarSourceKey =
    | 'event'
    | 'inspection'
    | 'compliance'
    | 'credential'
    | 'checklist'
    | 'hazard'
    | 'vendor'
    | 'asset'
    | 'meal'
    | 'damage'
    | 'emergency';

export type CalendarStatus =
    | 'scheduled'
    | 'overdue'
    | 'pending'
    | 'approved'
    | 'completed'
    | 'cancelled';

export interface RecurrenceRule {
    freq: 'DAILY' | 'WEEKLY' | 'MONTHLY';
    interval: number;
    count?: number;
    until?: string;
}

export interface CalendarOwner {
    id: number;
    name: string;
}

export interface CalendarSite {
    id: number;
    name: string;
    type: string;
}

/** Normalised calendar entry — matches CalendarItem::toArray() on the backend. */
export interface CalendarItem {
    id: string;
    source: CalendarSourceKey | string;
    group: 'manual' | 'auto';
    title: string;
    start: string | null;
    end: string | null;
    allDay: boolean;
    status: CalendarStatus | string;
    owner: CalendarOwner | null;
    room: string | null;
    ref: string | null;
    site: CalendarSite | null;
    link: string | null;
    editable: boolean;
    eventType?: string | null;
    approvalStatus?: string | null;
    desc?: string | null;
    priority?: string | null;
    recurrence?: RecurrenceRule | null;
    reminders?: number[];
    attendeeIds?: number[];
    seriesId?: number | null;
    isOccurrence?: boolean;
}

export type ColorBy = 'source' | 'status' | 'owner';
export type RecurPreset =
    | 'none'
    | 'DAILY'
    | 'WEEKLY'
    | 'FORTNIGHTLY'
    | 'MONTHLY'
    | 'QUARTERLY';

const pad = (n: number): string => String(n).padStart(2, '0');

/** Parse an ISO-8601 (offset-aware) or naive `YYYY-MM-DDTHH:mm` string to a Date. */
export function parseDT(s: string | null | undefined): Date | null {
    if (!s) return null;
    const d = new Date(s);
    return Number.isNaN(d.getTime()) ? null : d;
}

export const dateKey = (dt: Date): string =>
    `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}`;

/* ---- RRULE <-> preset --------------------------------------------------- */

export const FREQ_LABEL: Record<RecurPreset, string> = {
    none: 'Does not repeat',
    DAILY: 'Daily',
    WEEKLY: 'Weekly',
    FORTNIGHTLY: 'Every 2 weeks',
    MONTHLY: 'Monthly',
    QUARTERLY: 'Every 3 months',
};

export function presetToRule(key: RecurPreset): RecurrenceRule | null {
    switch (key) {
        case 'DAILY':
            return { freq: 'DAILY', interval: 1 };
        case 'WEEKLY':
            return { freq: 'WEEKLY', interval: 1 };
        case 'FORTNIGHTLY':
            return { freq: 'WEEKLY', interval: 2 };
        case 'MONTHLY':
            return { freq: 'MONTHLY', interval: 1 };
        case 'QUARTERLY':
            return { freq: 'MONTHLY', interval: 3 };
        default:
            return null;
    }
}

export function ruleToPreset(
    rule: RecurrenceRule | null | undefined,
): RecurPreset {
    if (!rule) return 'none';
    if (rule.freq === 'DAILY') return 'DAILY';
    if (rule.freq === 'WEEKLY')
        return rule.interval === 2 ? 'FORTNIGHTLY' : 'WEEKLY';
    if (rule.freq === 'MONTHLY')
        return rule.interval === 3 ? 'QUARTERLY' : 'MONTHLY';
    return 'none';
}

export function ruleToText(rule: RecurrenceRule | null | undefined): string {
    if (!rule) return 'Does not repeat';
    const preset = ruleToPreset(rule);
    let s = FREQ_LABEL[preset] ?? 'Repeats';
    if (rule.until) {
        const until = parseDT(rule.until);
        if (until) {
            s += ` · until ${until.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' })}`;
        }
    } else if (rule.count) {
        s += ` · ${rule.count} times`;
    }
    return s;
}

export function toRRULE(
    rule: RecurrenceRule | null | undefined,
): string | null {
    if (!rule) return null;
    let s = `FREQ=${rule.freq}`;
    if (rule.interval && rule.interval > 1) s += `;INTERVAL=${rule.interval}`;
    if (rule.count) s += `;COUNT=${rule.count}`;
    if (rule.until) {
        const until = parseDT(rule.until);
        if (until) s += `;UNTIL=${dateKey(until).replace(/-/g, '')}T000000Z`;
    }
    return s;
}

/* ---- Conflict detection (mirrors SiteCalendarService::hasConflict) ------ */

export function overlaps(aS: Date, aE: Date, bS: Date, bE: Date): boolean {
    return aS < bE && bS < aE;
}

/**
 * Soft conflicts for a timed item: same-room clashes, plus vendor / room /
 * vehicle bookings that overlap in time. All-day and cancelled items never clash.
 *
 * When `externalBusyCounts` is set (the admin's `conflict_policy`), pulled external
 * busy blocks (source `external`) that overlap in time also count — regardless of
 * room — so a new entry warns against a clash on a two-way-synced resource calendar.
 */
export function findConflicts(
    item: CalendarItem,
    all: CalendarItem[],
    opts: { externalBusyCounts?: boolean } = {},
): CalendarItem[] {
    const s = parseDT(item.start);
    if (!s || item.allDay) return [];
    const e = parseDT(item.end) ?? new Date(s.getTime() + 30 * 60000);

    return all.filter((o) => {
        if (o.id === item.id || (item.seriesId && o.seriesId === item.seriesId))
            return false;
        if (o.allDay || o.status === 'cancelled') return false;
        const os = parseDT(o.start);
        if (!os) return false;
        const oe = parseDT(o.end) ?? new Date(os.getTime() + 30 * 60000);
        const sameRoom = !!item.room && !!o.room && item.room === o.room;
        const bookingLike = item.source === 'vendor';
        const externalBusy =
            !!opts.externalBusyCounts && o.source === 'external';
        return (
            overlaps(s, e, os, oe) && (sameRoom || bookingLike || externalBusy)
        );
    });
}

/* ---- .ics generation (Google / Outlook / Apple) ------------------------- */

function icsStamp(dt: Date, allDay = false): string {
    if (allDay) {
        return `${dt.getFullYear()}${pad(dt.getMonth() + 1)}${pad(dt.getDate())}`;
    }
    // UTC instant — valid across importers.
    return (
        `${dt.getUTCFullYear()}${pad(dt.getUTCMonth() + 1)}${pad(dt.getUTCDate())}` +
        `T${pad(dt.getUTCHours())}${pad(dt.getUTCMinutes())}${pad(dt.getUTCSeconds())}Z`
    );
}

function escIcs(s: string | null | undefined): string {
    return String(s ?? '')
        .replace(/\\/g, '\\\\')
        .replace(/;/g, '\\;')
        .replace(/,/g, '\\,')
        .replace(/\n/g, '\\n');
}

export function itemToVEVENT(item: CalendarItem): string {
    const s = parseDT(item.start);
    if (!s) return '';
    const e = parseDT(item.end);
    const siteName = item.site?.name ?? 'Site';
    const lines = [
        'BEGIN:VEVENT',
        `UID:${item.id}-${item.source}@oblivionfindings.calendar`,
        `DTSTAMP:${icsStamp(new Date())}`,
    ];

    if (item.allDay) {
        lines.push(`DTSTART;VALUE=DATE:${icsStamp(s, true)}`);
    } else {
        lines.push(`DTSTART:${icsStamp(s)}`);
        if (e) lines.push(`DTEND:${icsStamp(e)}`);
    }

    const rrule = toRRULE(item.recurrence);
    if (rrule) lines.push(`RRULE:${rrule}`);

    lines.push(`SUMMARY:${escIcs(item.title)}`);
    if (item.room)
        lines.push(`LOCATION:${escIcs(`${item.room} · ${siteName}`)}`);
    if (item.desc) lines.push(`DESCRIPTION:${escIcs(item.desc)}`);

    for (const m of item.reminders ?? []) {
        lines.push(
            'BEGIN:VALARM',
            'ACTION:DISPLAY',
            'DESCRIPTION:Reminder',
            `TRIGGER:-PT${m}M`,
            'END:VALARM',
        );
    }

    lines.push('END:VEVENT');
    return lines.join('\r\n');
}

export function buildICS(
    items: CalendarItem[],
    calName = 'Site Calendar',
): string {
    return [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Oblivion Findings//Site Calendar//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        `X-WR-CALNAME:${escIcs(calName)}`,
        ...items.map(itemToVEVENT).filter(Boolean),
        'END:VCALENDAR',
    ].join('\r\n');
}

export function downloadICS(
    items: CalendarItem[],
    filename = 'site-calendar.ics',
    calName?: string,
): void {
    const blob = new Blob([buildICS(items, calName)], {
        type: 'text/calendar;charset=utf-8',
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
}

/** Google Calendar "render event" deep link for a single item. */
export function googleLink(item: CalendarItem): string {
    const s = parseDT(item.start);
    if (!s) return 'https://calendar.google.com/calendar/render';
    const e = parseDT(item.end) ?? new Date(s.getTime() + 60 * 60000);
    const siteName = item.site?.name ?? '';
    const dates = item.allDay
        ? `${icsStamp(s, true)}/${icsStamp(new Date(s.getTime() + 86400000), true)}`
        : `${icsStamp(s)}/${icsStamp(e)}`;
    const params = new URLSearchParams({
        action: 'TEMPLATE',
        text: item.title,
        dates,
        details: item.desc ?? '',
        location: item.room ? `${item.room}, ${siteName}` : siteName,
    });
    const rrule = toRRULE(item.recurrence);
    if (rrule) params.append('recur', `RRULE:${rrule}`);
    return `https://calendar.google.com/calendar/render?${params.toString()}`;
}

/** Outlook compose deep link for a single item. */
export function outlookLink(item: CalendarItem): string {
    const s = parseDT(item.start);
    if (!s) return 'https://outlook.office.com/calendar/0/deeplink/compose';
    const e = parseDT(item.end) ?? new Date(s.getTime() + 60 * 60000);
    const siteName = item.site?.name ?? '';
    const params = new URLSearchParams({
        path: '/calendar/action/compose',
        rru: 'addevent',
        subject: item.title,
        startdt: s.toISOString(),
        enddt: e.toISOString(),
        body: item.desc ?? '',
        location: item.room ? `${item.room}, ${siteName}` : siteName,
    });
    return `https://outlook.office.com/calendar/0/deeplink/compose?${params.toString()}`;
}

/* ---- colour-by resolver ------------------------------------------------- */

const STATUS_TONE: Record<string, string> = {
    scheduled: 'info',
    overdue: 'critical',
    pending: 'warning',
    approved: 'success',
    completed: 'neutral',
    cancelled: 'neutral',
};

/** CSS custom properties (--c label/dot, --cb chip fill, --cl chip border). */
export function colorVars(
    item: CalendarItem,
    mode: ColorBy,
): React.CSSProperties {
    if (mode === 'status') {
        const tone = STATUS_TONE[item.status] ?? 'neutral';
        const c = `var(--status-${tone})`;
        return {
            ['--c' as string]: c,
            ['--cb' as string]: `var(--status-${tone}-bg)`,
            ['--cl' as string]: `color-mix(in oklch, ${c} 30%, transparent)`,
        };
    }
    if (mode === 'owner') {
        const hue = ((item.owner?.id ?? 0) * 57) % 360;
        return {
            ['--c' as string]: `oklch(0.5 0.16 ${hue})`,
            ['--cb' as string]: `oklch(0.95 0.04 ${hue})`,
            ['--cl' as string]: `oklch(0.86 0.07 ${hue})`,
        };
    }
    // default: by source
    return {
        ['--c' as string]: `var(--src-${item.source})`,
        ['--cb' as string]: `var(--src-${item.source}-bg)`,
        ['--cl' as string]: `var(--src-${item.source}-ln)`,
    };
}
