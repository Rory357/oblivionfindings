import type { MyHrActiveClock } from './my-hr-clock-card';

/** A teammate in the shell-hosted "Give recognition" wizard directory. */
export type KudosTeammate = {
    id: number;
    name: string;
    initials: string;
    role: string | null;
    site: string | null;
};

/** One day-cell event in the hero footer calendar (shift / approved leave /
 *  public holiday). Mirrors the `events` payload from `BuildsMyHrShell`. */
export type MyHrCalendarEvent = {
    type: 'shift' | 'leave' | 'holiday';
    title: string;
    /** Display time window, e.g. '07:00 – 15:00'. Omitted for all-day items. */
    time?: string | null;
    site?: string | null;
    /** e.g. 'With Mere & Tomas'. */
    colleagues?: string | null;
    /** e.g. 'Approved' | 'Public holiday — no shift'. */
    note?: string | null;
    /** Shift / leave id, so day & agenda menu actions can route to the record. */
    ref_id?: number | null;
};

/** A month of calendar events, keyed by ISO date ('YYYY-MM-DD'). `month` is the
 *  anchor month ('YYYY-MM'); `events` spans the visible 6-week grid (so leading
 *  / trailing days of adjacent months carry their dots too). */
export type MyHrCalendarFeed = {
    month: string;
    events: Record<string, MyHrCalendarEvent[]>;
};

/** One person who reacted to a shout-out (the `you` flag marks the viewer). */
export type MyHrReactor = {
    id: number;
    name: string;
    initials: string;
    you: boolean;
};

/** One message in a shout-out reply thread. */
export type MyHrShoutoutReply = {
    id: number;
    user_id: number;
    name: string;
    initials: string;
    you: boolean;
    body: string;
    created_at: string | null;
};

/** The giver / recipient of a shout-out. */
export type MyHrShoutoutParty = {
    id: number;
    name: string;
    initials: string;
    role: string | null;
    you: boolean;
};

/** A peer-recognition shout-out (an `HrKudos` with its emoji reactions + reply
 *  thread), as served by `BuildsMyHrOverview::myHrShoutouts`. */
export type MyHrShoutout = {
    id: number;
    giver: MyHrShoutoutParty;
    recipient: MyHrShoutoutParty;
    category: string;
    message: string;
    created_at: string | null;
    reactions: {
        heart: MyHrReactor[];
        party: MyHrReactor[];
        hands: MyHrReactor[];
    };
    replies: MyHrShoutoutReply[];
};

/** Emoji reaction keys + their display glyphs, in display order. */
export const MY_HR_REACTIONS = [
    { key: 'heart', emoji: '❤️' },
    { key: 'party', emoji: '🎉' },
    { key: 'hands', emoji: '🙌' },
] as const;

export type MyHrReactionKey = (typeof MY_HR_REACTIONS)[number]['key'];

/** Human label for a kudos category key. */
export const MY_HR_KUDOS_LABELS: Record<string, string> = {
    teamwork: 'Teamwork',
    innovation: 'Innovation',
    leadership: 'Leadership',
    customer_focus: 'Customer Focus',
    going_above: 'Going Above & Beyond',
    other: 'Recognition',
};

/** Shared hero/clock/badge payload returned by `BuildsMyHrShell` (PHP) and
 *  merged into every `/hr/my/*` Inertia page under the `myHr` prop. */
export type MyHrShellData = {
    teammates: KudosTeammate[];
    /** Kudos value + impact maps (FeedService consts) for the shared recognition
     *  wizard hosted by the shell. */
    kudosCategories: Record<string, string>;
    kudosImpacts: Record<string, string>;
    profile: {
        name: string;
        first_name: string;
        initials: string;
        position_title: string | null;
        site_name: string | null;
        avatar: string | null;
    };
    activeClock: MyHrActiveClock;
    todayTotal: number;
    weekly: {
        total_hours: number;
        daily_hours: Record<string, number>;
        target_hours: number;
    };
    nextShift: {
        id: number;
        starts_at: string | null;
        ends_at: string | null;
        location: string | null;
        service_context_name: string | null;
    } | null;
    counts: {
        pendingLeave: number;
        docsToSign: number;
        policiesDue: number;
        onesToAck: number;
        kudosThisMonth: number;
    };
    /** Current-month feed for the hero footer calendar. Other months are
     *  fetched on demand from `GET /hr/my/calendar?month=YYYY-MM`. */
    calendar: MyHrCalendarFeed;
};
