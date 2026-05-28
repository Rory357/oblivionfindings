import PageShell from '@/components/page-shell';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { ActivityFeed } from '@/components/operations/dashboard/activity-feed';
import { KpiCards } from '@/components/operations/dashboard/kpi-cards';
import { ModulesGrid } from '@/components/operations/dashboard/modules-grid';
import { NeedsAttentionStrip } from '@/components/operations/dashboard/needs-attention';
import { OperationsHero } from '@/components/operations/dashboard/operations-hero';
import { ShiftTimeline } from '@/components/operations/dashboard/shift-timeline';
import { ShiftsBarChart } from '@/components/operations/dashboard/shifts-bar-chart';
import { StatusDonuts } from '@/components/operations/dashboard/status-donuts';
import type {
    ActivityItem,
    AttentionPayload,
    Hero,
    ShiftsPerDay,
    SiteOption,
    TimelineData,
    TopSite,
    Week,
} from '@/components/operations/dashboard/types';

type Stats = {
    active_clients: number;
    total_clients: number;
    new_clients_this_month: number;
    shifts_today_total: number;
    shifts_today: Record<string, number>;
    hours_this_week: number;
    hours_last_week: number;
    timesheets_pending: number;
    timesheets_overdue: number;
    unassigned_shifts: number;
    urgent_unassigned: number;
};

type Metrics = {
    active_clients: {
        value: number;
        delta: number;
        new_mtd: number;
        onboarding: number;
        trend_12wk: number[];
    };
    hours_week: {
        value: number;
        delta_pct: number;
        prev_value: number;
        sparkline: number[];
        avg_shift: number;
        overtime_alerts: number;
    };
    clock_in: {
        adherence_pct: number;
        on_time: number;
        late: number;
        no_show: number;
        avg_late_sec: number;
        delta_pp: number;
    };
    compliance: {
        pct: number;
        current: number;
        expiring_30d: number;
        expired: number;
        target_pct: number;
        current_pct: number;
        expiring_pct: number;
        expired_pct: number;
    };
};

type Props = {
    stats: Stats;
    current_user: { first_name: string; role: string };
    today_label: string;
    week: Week;
    hero: Hero;
    attention: AttentionPayload;
    metrics: Metrics;
    timeline: TimelineData;
    top_sites: TopSite[];
    client_status_breakdown: Record<string, number>;
    shift_status_breakdown: Record<string, number>;
    timesheet_status_breakdown: Record<string, number>;
    weekly_hours_trend: number[];
    shifts_per_day: ShiftsPerDay[];
    recent_activity: ActivityItem[];
    site_options: SiteOption[];
};

export default function OperationsDashboard({
    stats,
    current_user,
    today_label,
    week,
    hero,
    attention,
    metrics,
    timeline,
    top_sites,
    client_status_breakdown,
    shift_status_breakdown,
    shifts_per_day,
    recent_activity,
    site_options,
}: Props) {
    const [siteFilter, setSiteFilter] = useState<number[]>([]);
    const [staffFilter, setStaffFilter] = useState<number[]>([]);
    const [clientFilter, setClientFilter] = useState<number[]>([]);

    const onWeekChange = (anchorIso: string) => {
        router.visit(`/operations?week=${anchorIso}`, { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title="Operations" />
            <PageShell>
                <div className="space-y-5">
                    <OperationsHero
                        firstName={current_user.first_name}
                        todayLabel={today_label}
                        week={week}
                        hero={hero}
                        activeClients={stats.active_clients}
                        siteOptions={site_options}
                        siteFilter={siteFilter}
                        onSiteFilterChange={setSiteFilter}
                        staffFilter={staffFilter}
                        onStaffFilterChange={setStaffFilter}
                        clientFilter={clientFilter}
                        onClientFilterChange={setClientFilter}
                        onWeekChange={onWeekChange}
                    />

                    <NeedsAttentionStrip attention={attention} />

                    <KpiCards metrics={metrics} />

                    <ShiftTimeline timeline={timeline} />

                    <StatusDonuts
                        clientStatus={client_status_breakdown}
                        shiftStatus={shift_status_breakdown}
                    />

                    <section className="grid items-start gap-4 lg:grid-cols-5">
                        <ShiftsBarChart
                            shiftsPerDay={shifts_per_day}
                            topSites={top_sites}
                            deliveredHours={Math.round(stats.hours_this_week)}
                            deliveredDeltaPct={metrics.hours_week.delta_pct}
                            avgShiftHours={metrics.hours_week.avg_shift}
                        />
                        <ActivityFeed items={recent_activity} totalEventsToday={recent_activity.length} />
                    </section>

                    <ModulesGrid openShifts={stats.unassigned_shifts} />
                </div>
            </PageShell>
        </AppLayout>
    );
}
