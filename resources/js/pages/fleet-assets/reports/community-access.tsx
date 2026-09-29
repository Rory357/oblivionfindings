import {
    FLEET_COLORS,
    HalfMoonGauge,
    HorizontalBarChart,
    MiniBarChart,
} from '@/components/fleet-charts';
import { FleetEmptyState } from '@/components/fleet-empty-state';
import PageShell from '@/components/page-shell';
import { PageHeaderFilterSelect } from '@/components/page/page-header';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/fleet-utils';
import { Head, router } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { FleetReportHeader } from './report-header';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type ResidentRow = {
    id: number | string;
    name: string;
    house: string;
    outings: number;
    transport_trips: number;
    total_hours: number;
    last_outing: string | null;
};

type WeeklyPoint = {
    label: string;
    value: number;
};

type Props = {
    by_resident: ResidentRow[];
    weekly_trend: WeeklyPoint[];
    days: number;
    stats: {
        total_outings: number;
        residents_participating: number;
        avg_hours_per_resident: number;
        total_hours: number;
        access_target_pct: number;
    };
};

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function CommunityAccess({
    by_resident: rawResident,
    weekly_trend: rawTrend,
    days,
    stats: rawStats,
}: Props) {
    const byResident = rawResident ?? [];
    const weeklyTrend = rawTrend ?? [];
    const stats = rawStats ?? {
        total_outings: 0,
        residents_participating: 0,
        avg_hours_per_resident: 0,
        total_hours: 0,
        access_target_pct: 0,
    };

    const handlePeriodChange = (value: string) => {
        router.get(
            '/fleet-assets/reports/community-access',
            { days: value },
            { preserveState: true },
        );
    };

    const handleExport = () => {
        window.location.href = `/fleet-assets/reports/community-access?export=csv&days=${days}`;
    };

    // Gauge color based on target percentage
    const gaugeColor =
        stats.access_target_pct >= 80
            ? FLEET_COLORS.success
            : stats.access_target_pct >= 50
              ? FLEET_COLORS.warning
              : FLEET_COLORS.danger;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Reports', href: '/fleet-assets/reports' },
                {
                    title: 'Community Access',
                    href: '/fleet-assets/reports/community-access',
                },
            ]}
        >
            <Head title="Community Access Analytics" />
            <PageShell>
                <FleetReportHeader
                    title="Community access"
                    description={`Recorded participation and outings · last ${days} days`}
                    onExport={handleExport}
                    filters={
                        <PageHeaderFilterSelect
                            label="Period · Last 30 days"
                            value={String(days)}
                            allValue="30"
                            options={[
                                { value: '30', label: 'Last 30 days' },
                                { value: '90', label: 'Last 90 days' },
                                { value: '180', label: 'Last 6 months' },
                                { value: '365', label: 'Last 12 months' },
                            ]}
                            onChange={handlePeriodChange}
                        />
                    }
                    meters={[
                        {
                            label: 'Outings',
                            value: stats.total_outings,
                            caption: 'Recorded in this period',
                        },
                        {
                            label: 'Residents',
                            value: stats.residents_participating,
                            caption: 'With recorded participation',
                        },
                        {
                            label: 'Average hours per resident',
                            value: stats.avg_hours_per_resident,
                            caption: 'Recorded participation',
                        },
                        {
                            label: 'Community hours',
                            value: stats.total_hours,
                            caption: 'Recorded in this period',
                        },
                    ]}
                />

                {/* Charts Row */}
                <div className="grid gap-4 lg:grid-cols-3">
                    {/* Access Target Gauge */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">
                                Community Access Target
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col items-center pt-0">
                            <HalfMoonGauge
                                value={stats.access_target_pct}
                                label="Meeting Target"
                                sublabel="2+ outings per resident"
                                color={gaugeColor}
                            />
                        </CardContent>
                    </Card>

                    {/* Outings per Resident Bar Chart */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="text-sm">
                                Outings per Resident
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {byResident.length > 0 ? (
                                <HorizontalBarChart
                                    items={byResident
                                        .sort((a, b) => b.outings - a.outings)
                                        .slice(0, 12)
                                        .map((r) => ({
                                            label: r.name,
                                            value: r.outings,
                                            color:
                                                r.outings === 0
                                                    ? FLEET_COLORS.danger
                                                    : undefined,
                                        }))}
                                    color={FLEET_COLORS.primary}
                                />
                            ) : (
                                <p className="py-4 text-center text-sm text-muted-foreground">
                                    No data available.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Weekly Trend */}
                {weeklyTrend.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">
                                Outings per Week
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <MiniBarChart
                                data={weeklyTrend}
                                color={FLEET_COLORS.primary}
                                height={140}
                            />
                        </CardContent>
                    </Card>
                )}

                {/* Resident Table */}
                {byResident.length > 0 ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Resident Community Participation
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div
                                data-fleet-narrow-strategy="horizontal-scroll"
                                className="overflow-x-auto"
                            >
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-xs tracking-wider text-muted-foreground uppercase">
                                            <th className="px-3 py-2 text-left font-medium">
                                                Resident
                                            </th>
                                            <th className="px-3 py-2 text-left font-medium">
                                                House
                                            </th>
                                            <th className="px-3 py-2 text-right font-medium">
                                                Outings
                                            </th>
                                            <th className="px-3 py-2 text-right font-medium">
                                                Transport Trips
                                            </th>
                                            <th className="px-3 py-2 text-right font-medium">
                                                Total Hours
                                            </th>
                                            <th className="px-3 py-2 text-right font-medium">
                                                Last Outing
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {byResident.map((r) => (
                                            <tr
                                                key={r.id}
                                                className={`border-b transition-colors last:border-b-0 hover:bg-muted/30 ${
                                                    r.outings === 0
                                                        ? 'bg-status-critical-bg'
                                                        : ''
                                                }`}
                                            >
                                                <td className="px-3 py-2">
                                                    <div className="flex items-center gap-2">
                                                        {r.outings === 0 && (
                                                            <span
                                                                className="h-2 w-2 shrink-0 rounded-full bg-status-critical"
                                                                title="No outings in period"
                                                            />
                                                        )}
                                                        <span className="font-medium">
                                                            {r.name}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2 text-muted-foreground">
                                                    {r.house || '---'}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    <span
                                                        className={
                                                            r.outings === 0
                                                                ? 'font-semibold text-status-critical dark:text-status-critical'
                                                                : ''
                                                        }
                                                    >
                                                        {r.outings}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {r.transport_trips}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {r.total_hours}
                                                </td>
                                                <td className="px-3 py-2 text-right text-muted-foreground">
                                                    {r.last_outing
                                                        ? formatDate(
                                                              r.last_outing,
                                                          )
                                                        : 'Never'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    <FleetEmptyState
                        icon={Users}
                        title="No community access data"
                        description="No outing or transport records found for the selected period. Create outings to start tracking community participation."
                        actionLabel="Create Outing"
                        actionHref="/fleet-assets/outings/create"
                    />
                )}
            </PageShell>
        </AppLayout>
    );
}
