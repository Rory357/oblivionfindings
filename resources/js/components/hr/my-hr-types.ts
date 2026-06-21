import type { MyHrActiveClock } from './my-hr-clock-card';
import type { KudosTeammate } from './my-hr-kudos-wizard';

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

/** Shared hero/clock/badge payload returned by `BuildsMyHrShell` (PHP) and
 *  merged into every `/hr/my/*` Inertia page under the `myHr` prop. */
export type MyHrShellData = {
    teammates: KudosTeammate[];
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
