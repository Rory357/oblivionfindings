import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import { HalfMoonGauge, HorizontalBarChart, MiniBarChart, FLEET_COLORS } from '@/components/fleet-charts';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/fleet-utils';
import { Head, router } from '@inertiajs/react';
import { Calendar, Clock, Download, MapPin, Target, Users } from 'lucide-react';

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

export default function CommunityAccess({ by_resident: rawResident, weekly_trend: rawTrend, days, stats: rawStats }: Props) {
    const byResident = rawResident ?? [];
    const weeklyTrend = rawTrend ?? [];
    const stats = rawStats ?? { total_outings: 0, residents_participating: 0, avg_hours_per_resident: 0, total_hours: 0, access_target_pct: 0 };

    const handlePeriodChange = (value: string) => {
        router.get('/fleet-assets/reports/community-access', { days: value }, { preserveState: true });
    };

    const handleExport = () => {
        window.location.href = `/fleet-assets/reports/community-access?export=csv&days=${days}`;
    };

    // Gauge color based on target percentage
    const gaugeColor = stats.access_target_pct >= 80
        ? FLEET_COLORS.success
        : stats.access_target_pct >= 50
        ? FLEET_COLORS.warning
        : FLEET_COLORS.danger;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Reports', href: '/fleet-assets/reports' },
                { title: 'Community Access', href: '/fleet-assets/reports/community-access' },
            ]}
        >
            <Head title="Community Access Analytics" />
            <PageShell>
                <FleetHero
                    title="Community Access Analytics"
                    description="Track resident community participation, outings, and transport usage for MSD/MOH compliance."
                    backHref="/fleet-assets/reports"
                    backLabel="Back to Reports"
                />

                {/* Period Selector + Export */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Calendar className="h-5 w-5 text-muted-foreground" />
                        <Select value={String(days)} onValueChange={handlePeriodChange}>
                            <SelectTrigger className="w-48"><SelectValue placeholder="Select period" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="30">Last 30 days</SelectItem>
                                <SelectItem value="90">Last 90 days</SelectItem>
                                <SelectItem value="180">Last 6 months</SelectItem>
                                <SelectItem value="365">Last 12 months</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <Button variant="outline" size="sm" onClick={handleExport}>
                        <Download className="mr-1.5 h-3.5 w-3.5" /> Export CSV
                    </Button>
                </div>

                {/* KPI Cards */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <FleetStatCard label="Total Outings" value={stats.total_outings} icon={MapPin} subtitle={`Last ${days} days`} />
                    <FleetStatCard label="Residents Participating" value={stats.residents_participating} icon={Users} />
                    <FleetStatCard label="Avg Hours / Resident" value={stats.avg_hours_per_resident} icon={Clock} subtitle="Hours in community" />
                    <FleetStatCard label="Community Hours" value={stats.total_hours} icon={Target} subtitle="Total hours" />
                </div>

                {/* Charts Row */}
                <div className="grid gap-4 lg:grid-cols-3">
                    {/* Access Target Gauge */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Community Access Target</CardTitle>
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
                            <CardTitle className="text-sm">Outings per Resident</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {byResident.length > 0 ? (
                                <HorizontalBarChart
                                    items={byResident
                                        .sort((a, b) => b.outings - a.outings)
                                        .slice(0, 12)
                                        .map(r => ({
                                            label: r.name,
                                            value: r.outings,
                                            color: r.outings === 0 ? FLEET_COLORS.danger : undefined,
                                        }))}
                                    color={FLEET_COLORS.primary}
                                />
                            ) : (
                                <p className="py-4 text-center text-sm text-muted-foreground">No data available.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Weekly Trend */}
                {weeklyTrend.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Outings per Week</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <MiniBarChart data={weeklyTrend} color={FLEET_COLORS.primary} height={140} />
                        </CardContent>
                    </Card>
                )}

                {/* Resident Table */}
                {byResident.length > 0 ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Resident Community Participation</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                            <th className="px-3 py-2 text-left font-medium">Resident</th>
                                            <th className="px-3 py-2 text-left font-medium">House</th>
                                            <th className="px-3 py-2 text-right font-medium">Outings</th>
                                            <th className="px-3 py-2 text-right font-medium">Transport Trips</th>
                                            <th className="px-3 py-2 text-right font-medium">Total Hours</th>
                                            <th className="px-3 py-2 text-right font-medium">Last Outing</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {byResident.map((r) => (
                                            <tr
                                                key={r.id}
                                                className={`border-b last:border-b-0 transition-colors hover:bg-muted/30 ${
                                                    r.outings === 0 ? 'bg-status-critical-bg' : ''
                                                }`}
                                            >
                                                <td className="px-3 py-2">
                                                    <div className="flex items-center gap-2">
                                                        {r.outings === 0 && (
                                                            <span className="h-2 w-2 rounded-full bg-status-critical shrink-0" title="No outings in period" />
                                                        )}
                                                        <span className="font-medium">{r.name}</span>
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2 text-muted-foreground">{r.house || '---'}</td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    <span className={r.outings === 0 ? 'text-status-critical dark:text-status-critical font-semibold' : ''}>
                                                        {r.outings}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">{r.transport_trips}</td>
                                                <td className="px-3 py-2 text-right tabular-nums">{r.total_hours}</td>
                                                <td className="px-3 py-2 text-right text-muted-foreground">
                                                    {r.last_outing ? formatDate(r.last_outing) : 'Never'}
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
