import { router } from '@inertiajs/react';

import {
    LeaveHero,
    type LeaveHeroStat,
    type LeaveNeedChip,
} from './leave-hero';

/** Server-computed hero payload shared by every leave hub surface. */
export type HubHero = {
    site_count: number;
    awaiting_my_decision: number;
    on_leave_today: number;
    upcoming_7d: number;
    absence_rate: number;
    overdue_count: number;
    roster_conflicts: number;
    mix: Array<{ type: string; count: number }>;
};

/**
 * The Leave & Absence command band, wired to the shared server `hero` payload
 * and the standard hub navigation. Mounted identically on the hub index,
 * Balances and Reports so the brand band reads as one surface across tabs.
 * Pass `onRequestLeave` on the hub index to open the in-page wizard; on the
 * other tabs it falls back to navigating to the hub with the wizard open.
 */
export function LeaveHubHero({
    hero,
    can,
    onRequestLeave,
}: {
    hero: HubHero;
    can: { approve?: boolean; manage?: boolean; create?: boolean };
    onRequestLeave?: () => void;
}) {
    const stats: LeaveHeroStat[] = [
        {
            label: 'Awaiting your decision',
            value: hero.awaiting_my_decision,
            amber: hero.awaiting_my_decision > 0,
            onClick: () => router.visit('/hr/leave?tab=approvals&seg=mine'),
        },
        {
            label: 'On leave today',
            value: hero.on_leave_today,
            onClick: () => router.visit('/hr/leave?tab=calendar'),
        },
        {
            label: 'Upcoming · 7d',
            value: hero.upcoming_7d,
            onClick: () => router.visit('/hr/leave?tab=calendar'),
        },
        {
            label: 'Absence rate',
            value: `${hero.absence_rate}%`,
            onClick: () => router.visit('/hr/leave/reports'),
        },
    ];

    const needs: LeaveNeedChip[] = [];
    if (can.approve) {
        if (hero.awaiting_my_decision > 0)
            needs.push({
                key: 'awaiting',
                label: `${hero.awaiting_my_decision} awaiting your decision`,
                onClick: () => router.visit('/hr/leave?tab=approvals&seg=mine'),
            });
        if (hero.overdue_count > 0)
            needs.push({
                key: 'overdue',
                label: `${hero.overdue_count} overdue past SLA`,
                onClick: () => router.visit('/hr/leave?tab=approvals&seg=all'),
            });
        if (hero.roster_conflicts > 0)
            needs.push({
                key: 'conflicts',
                label: `${hero.roster_conflicts} roster conflicts`,
                onClick: () => router.visit('/hr/leave?tab=approvals&seg=all'),
            });
    }

    const coverageLegend = [
        {
            label: 'On leave today',
            value: hero.on_leave_today,
            color: 'oklch(0.82 0.10 277)',
        },
        {
            label: 'Upcoming · 7d',
            value: hero.upcoming_7d,
            color: 'oklch(0.86 0.13 90)',
        },
        {
            label: 'Roster conflicts',
            value: hero.roster_conflicts,
            color: 'oklch(0.70 0.16 25)',
        },
    ];

    return (
        <LeaveHero
            siteCount={hero.site_count}
            stats={stats}
            needs={needs}
            mix={hero.mix}
            coveragePct={Math.max(
                0,
                Math.min(100, Math.round(100 - hero.absence_rate)),
            )}
            coverageLegend={coverageLegend}
            canCreate={!!can.create}
            handlers={{
                onRequestLeave: can.create
                    ? (onRequestLeave ??
                      (() => router.visit('/hr/leave?new=1')))
                    : undefined,
                onReviewApprovals: () =>
                    router.visit('/hr/leave?tab=approvals'),
                onOpenCalendar: () => router.visit('/hr/leave?tab=calendar'),
                onExport: can.manage
                    ? () => {
                          window.location.href = '/hr/leave/export';
                      }
                    : undefined,
            }}
        />
    );
}

export default LeaveHubHero;
