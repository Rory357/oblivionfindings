/**
 * Clock-ins from doors (S&D Phase H, decision D15): the payloads the server sends, and the words
 * every screen uses for them (My Day band 19g, pop-up 88, Access › Clock-ins 19e, pop-up 89,
 * Setup › Clock-ins from doors 15n, pop-up 92). One name for one thing: a status reads the same
 * on every screen. Times arrive as instants and are written in NZ time through lib/datetime.
 */
import { formatDate, formatTime } from '@/lib/datetime';

export type ClockinTone = 'critical' | 'warning' | 'info' | 'success' | 'neutral';

export type ClockinOutcome =
    | 'apart'
    | 'no_badge'
    | 'not_rostered'
    | 'not_working'
    | 'not_answered'
    | 'waiting'
    | 'asked'
    | 'confirmed'
    | 'kept'
    | 'now'
    | 'other_way'
    | 'not_yet'
    | 'not_work';

export type ClockinRow = {
    id: number;
    kind: 'rostered' | 'not_rostered';
    person: { id: number; name: string };
    site: { id: number; name: string };
    day: string;
    badge: { at: string; door: string; method: string } | null;
    suggestion: { at: string; answer: 'badge' | 'now' | 'not_working' | null; answered_at: string | null; closed: boolean } | null;
    shift: { starts_at: string; ends_at: string | null } | null;
    clock_in: { at: string; how: string } | null;
    apart_minutes: number | null;
    outcome: ClockinOutcome;
    tone: ClockinTone;
    waiting_for_you: boolean;
    gap_checked_at: string | null;
    review: { kind: 'kept' | 'asked' | 'not_work'; by: string; at: string | null; note: string | null } | null;
    told: { at: string; names: string[] } | null;
};

export type ClockinPanelData = ClockinRow & {
    next_shift: string | null;
    morning: { at: string; text: string }[];
    choices: ('keep' | 'ask' | 'not_work')[];
    can_note: boolean;
};

export type ClockinPlace = { site: string; doors: string[] };

export type ClockinState = 'off' | 'starting' | 'on';

export type AccessClockinsData = {
    state: ClockinState;
    how: { since: string | null; starts_on: string | null; places: ClockinPlace[]; before: number; after: number; badge_time: boolean };
    week: { from: string; to: string; starts: number; badge: number; other_way: number; by_manager: number; none: number } | null;
    rows: ClockinRow[];
    count: number;
    meter: { confirmed: number; starts: number };
    can: { review: boolean; setup: boolean };
};

export type GapChannels = { app: boolean; email: boolean };

export type ClockinDoorOption = {
    id: number;
    name: string;
    site: { id: number; name: string };
    readers: string[];
    counts: boolean;
    starts_on: string | null;
};

export type SetupClockinsData = {
    state: ClockinState;
    since: string | null;
    starts_on: string | null;
    turned_off_at: string | null;
    places: ClockinPlace[];
    notice: { sent_at: string; by: string; by_email: boolean; starts_on: string; told: number } | null;
    today: { badge: number; other_way: number; gaps: number } | null;
    settings: {
        before: number;
        after: number;
        badge_time: boolean;
        gaps: { unconfirmed: GapChannels; no_badge: GapChannels; not_rostered: GapChannels };
    };
    doors: ClockinDoorOption[];
    told_by_site: Record<string, number>;
    me: { name: string; position: string | null };
    earliest_start: string;
    options: { before: number[]; after: number[] };
    /** shared: the window, badge-time switch and gap alerts apply to every Site; only someone who sees every Site changes them. */
    can: { change: boolean; review: boolean; shared: boolean };
};

export type MyDayClockin = {
    id: number;
    site: string;
    door: string;
    place: string;
    method: string;
    badge_at: string;
    shift: { starts_at: string | null; ends_at: string | null };
    badge_time: boolean;
    notice: { body: string; sent_at: string; by: string; by_email: boolean } | null;
};

/** "Mon 19 Oct" for a YYYY-MM-DD calendar day (never shifted by the browser's timezone). */
export function formatDay(value: string | null | undefined, withYear = false): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '');
    if (!match) return '—';
    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
    const words = date
        .toLocaleDateString('en-NZ', { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short', ...(withYear ? { year: 'numeric' } : {}) })
        .replace(/,\s*/g, ' ')
        .trim();
    return words;
}

/** "7:00 am – 3:00 pm". */
export function shiftWords(shift: { starts_at: string | null; ends_at: string | null } | null | undefined): string {
    if (!shift?.starts_at) return '—';
    return shift.ends_at ? `${formatTime(shift.starts_at)} – ${formatTime(shift.ends_at)}` : formatTime(shift.starts_at);
}

/** "60 min before – 30 min after". */
export function windowWords(before: number, after: number): string {
    return `${before} min before – ${after} min after`;
}

export function firstName(name: string): string {
    return name.trim().split(/\s+/)[0] || name;
}

/** "Front door, 6:52 am · card". */
export function badgeWords(badge: ClockinRow['badge']): string {
    if (!badge) return 'No badge';
    return `${badge.door}, ${formatTime(badge.at)} · ${badge.method}`;
}

/** "Kauri House front door": the Site, then the door as UniFi names it. */
export function doorPlace(site: string, door: string): string {
    if (!site || door.toLowerCase().includes(site.toLowerCase())) return door;
    return `${site} ${door.charAt(0).toLowerCase()}${door.slice(1)}`;
}

function list(items: string[]): string {
    if (items.length <= 1) return items[0] ?? '';
    return `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;
}

/** "Head office front door, Kauri House front and back doors". */
export function placesWords(places: ClockinPlace[]): string {
    return places
        .map((place) => {
            const doors = place.doors.map((door) => door.replace(/\s+door$/i, '').toLowerCase());
            const allDoors = place.doors.every((door) => /\s+door$/i.test(door));
            if (place.doors.length > 1 && allDoors) return `${place.site} ${list(doors)} doors`;
            return place.doors.map((door) => doorPlace(place.site, door)).join(', ');
        })
        .join(', ');
}

/** "Head office and Kauri House". */
export function sitesWords(places: { site: string }[]): string {
    return list(places.map((place) => place.site));
}

export type OutcomeWords = { badge: string; note: string | null; next: string };

/** The status, its note and the next step for a row (19e "What happened" and "Next"). */
export function outcomeWords(row: ClockinRow): OutcomeWords {
    const first = firstName(row.person.name);
    const clockIn = row.clock_in ? `${formatTime(row.clock_in.at)} ${row.clock_in.how}` : null;
    const by = row.review ? `${row.review.by}${row.review.at ? ` at ${formatTime(row.review.at)}` : ''}` : null;
    const checkBy = row.shift?.starts_at ? formatTime(new Date(new Date(row.shift.starts_at).getTime() + 15 * 60_000)) : null;
    switch (row.outcome) {
        case 'apart':
            return { badge: `Badge and clock-in ${row.apart_minutes ?? '—'} min apart`, note: clockIn ? `Clocked in ${clockIn}` : null, next: 'Check' };
        case 'no_badge':
            return {
                badge: checkBy ? `No badge, no clock-in by ${checkBy}` : 'No badge, no clock-in',
                note: row.told ? `${list(row.told.names) || 'The house manager'} told at ${formatTime(row.told.at)}` : 'Nobody told: turned off in Setup',
                next: `Ask ${first}`,
            };
        case 'not_rostered':
            return { badge: 'Badged, not on the roster', note: 'Nothing suggested · a swapped shift?', next: 'Check' };
        case 'not_working':
            return {
                badge: 'Said they’re not working',
                note: row.suggestion?.answered_at ? `Answered at ${formatTime(row.suggestion.answered_at)}` : null,
                next: 'Check',
            };
        case 'not_answered':
            return { badge: 'Badged, never clocked in', note: row.suggestion?.at ? `Suggested at ${formatTime(row.suggestion.at)}, not answered` : null, next: 'Check' };
        case 'waiting':
            return { badge: `Waiting for ${first}`, note: row.suggestion?.at ? `Suggested at ${formatTime(row.suggestion.at)}` : null, next: '—' };
        case 'asked':
            return { badge: `Asked ${first}`, note: by ? `By ${by}` : null, next: '—' };
        case 'confirmed':
            return {
                badge: `Confirmed ${row.badge ? formatTime(row.badge.at) : ''}`.trim(),
                note: row.suggestion?.answered_at ? `Tapped at ${formatTime(row.suggestion.answered_at)}` : null,
                next: '—',
            };
        case 'kept':
            return { badge: 'Clock-in kept', note: by ? `By ${by}` : null, next: '—' };
        case 'now':
            return { badge: `Clocked in now instead${row.clock_in ? `, ${formatTime(row.clock_in.at)}` : ''}`, note: 'Their choice', next: '—' };
        case 'other_way':
            return { badge: `Clocked in${row.clock_in ? ` ${formatTime(row.clock_in.at)}` : ''}`, note: row.clock_in?.how ? `${row.clock_in.how.charAt(0).toUpperCase()}${row.clock_in.how.slice(1)}` : null, next: '—' };
        case 'not_work':
            return { badge: 'Not work', note: by ? `By ${by}` : null, next: '—' };
        case 'not_yet':
        default:
            return { badge: 'Not clocked in yet', note: checkBy ? `Checked at ${checkBy}` : null, next: '—' };
    }
}

/** The panel's one-line summary under the name: "Badge and clock-in 39 min apart · Thu 29 Oct 2026". */
export function panelSub(row: ClockinRow): string {
    const day = formatDay(row.day, true);
    const words = row.outcome === 'not_rostered' ? 'Badged in, not on the roster' : outcomeWords(row).badge;
    return `${words} · ${day}`;
}

/**
 * The notice staff get first (pop-up 92), built from the doors ticked, the start day and who
 * signs it, until the person edits it themselves.
 */
export function defaultNotice({ startsOn, places, me }: { startsOn: string; places: ClockinPlace[]; me: { name: string; position: string | null } }): string {
    const phrase = (place: ClockinPlace): string => {
        if (place.doors.length > 0 && place.doors.every((door) => /\s+door$/i.test(door))) {
            const names = place.doors.map((door) => door.replace(/\s+door$/i, '').toLowerCase());
            const joined = names.length > 1 ? `${names.slice(0, -1).join(', ')} or ${names[names.length - 1]}` : names[0];
            return `the ${joined} door at ${place.site}`;
        }
        return place.doors.map((door) => `the ${doorPlace(place.site, door)}`).join(' or ');
    };
    const where = places.length ? places.map(phrase).join(', or ') : 'the doors that count';
    const questions = me.position ? `${me.name}, ${me.position.charAt(0).toLowerCase()}${me.position.slice(1)}` : me.name;
    return (
        `From ${formatDay(startsOn)}, when you badge in at ${where}, near the start of a rostered shift, Oblivion will suggest clocking you in at that time. ` +
        'Nothing happens unless you confirm it, and you can always clock in another way. ' +
        'We use the door, the time and your card, PIN or phone, never your face or fingerprint. ' +
        'If you badge in when you aren’t on the roster, your house manager is told so they can check whether a shift is missing. ' +
        'A clock-in you confirm is kept with your timesheet for 6 years, as the law requires; your name on door events is kept for 90 days. ' +
        'You can see your records and ask for them to be corrected. ' +
        `Questions: ${questions}.`
    );
}

/** "Mon 12 Oct" for an instant, in NZ time. */
export function instantDay(value: string | null | undefined): string {
    return value ? formatDate(value) : '—';
}
