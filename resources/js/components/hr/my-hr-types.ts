import type { MyHrActiveClock } from './my-hr-clock-card';

/** Shared hero/clock/badge payload returned by `BuildsMyHrShell` (PHP) and
 *  merged into every `/hr/my/*` Inertia page under the `myHr` prop. */
export type MyHrShellData = {
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
};
