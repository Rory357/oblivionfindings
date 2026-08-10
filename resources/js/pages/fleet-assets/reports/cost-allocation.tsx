import { FLEET_COLORS, HorizontalBarChart } from '@/components/fleet-charts';
import { FleetEmptyState } from '@/components/fleet-empty-state';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/fleet-utils';
import {
    CompactHeroStat,
    FleetCompactHero,
} from '@/pages/fleet-assets/components/fleet-compact-hero';
import { Head, router } from '@inertiajs/react';
import { Building2, Download, PieChart, Users } from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type SiteRow = {
    id: number;
    name: string;
    vehicles: number;
    fuel_cost: number;
    maintenance_cost: number;
    total: number;
    cost_per_vehicle: number;
};

type ResidentRow = {
    id: number | string;
    name: string;
    house: string;
    trips: number;
    distance_km: number;
    estimated_cost: number;
};

type Props = {
    by_site: SiteRow[];
    by_resident: ResidentRow[];
    days: number;
    stats: {
        total_fleet_cost: number;
        cost_per_vehicle: number;
        cost_per_resident: number;
        cost_per_house: number;
        total_fuel: number;
        total_maintenance: number;
    };
};

/* ------------------------------------------------------------------ */
/*  DonutChart (local, lightweight)                                    */
/* ------------------------------------------------------------------ */

function CostDonut({
    fuel,
    maintenance,
    other,
    size = 140,
}: {
    fuel: number;
    maintenance: number;
    other?: number;
    size?: number;
}) {
    const strokeWidth = 18;
    const radius = (size - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    const total = fuel + maintenance + (other ?? 0);

    if (total === 0) {
        return (
            <div
                className="flex flex-col items-center justify-center"
                style={{ width: size, height: size }}
            >
                <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
                    <circle
                        cx={size / 2}
                        cy={size / 2}
                        r={radius}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth={strokeWidth}
                        className="text-muted/20"
                    />
                    <text
                        x="50%"
                        y="50%"
                        textAnchor="middle"
                        dominantBaseline="central"
                        className="fill-muted-foreground text-xs"
                    >
                        No data
                    </text>
                </svg>
            </div>
        );
    }

    const segments = [
        { value: fuel, color: FLEET_COLORS.warning, label: 'Fuel' },
        {
            value: maintenance,
            color: FLEET_COLORS.primary,
            label: 'Maintenance',
        },
    ];
    if (other && other > 0) {
        segments.push({
            value: other,
            color: FLEET_COLORS.neutral,
            label: 'Other',
        });
    }

    let offset = 0;
    return (
        <div className="flex flex-col items-center">
            <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={strokeWidth}
                    className="text-muted/10"
                />
                {segments
                    .filter((s) => s.value > 0)
                    .map((seg, i) => {
                        const pct = seg.value / total;
                        const dashLength = pct * circumference;
                        const dashGap = circumference - dashLength;
                        const rotation = (offset / total) * 360 - 90;
                        offset += seg.value;
                        return (
                            <circle
                                key={i}
                                cx={size / 2}
                                cy={size / 2}
                                r={radius}
                                fill="none"
                                stroke={seg.color}
                                strokeWidth={strokeWidth}
                                strokeDasharray={`${dashLength} ${dashGap}`}
                                strokeLinecap="butt"
                                transform={`rotate(${rotation} ${size / 2} ${size / 2})`}
                            />
                        );
                    })}
                <text
                    x="50%"
                    y="46%"
                    textAnchor="middle"
                    dominantBaseline="central"
                    className="fill-foreground"
                    style={{ fontSize: 16, fontWeight: 700 }}
                >
                    {formatCurrency(total)}
                </text>
                <text
                    x="50%"
                    y="62%"
                    textAnchor="middle"
                    dominantBaseline="central"
                    className="fill-muted-foreground"
                    style={{ fontSize: 9 }}
                >
                    Total
                </text>
            </svg>
            <div className="mt-2 space-y-1">
                {segments.map((s, i) => (
                    <div key={i} className="flex items-center gap-2 text-xs">
                        <span
                            className="h-2.5 w-2.5 rounded-full"
                            style={{ backgroundColor: s.color }}
                        />
                        <span className="text-muted-foreground">{s.label}</span>
                        <span className="ml-auto font-medium tabular-nums">
                            {formatCurrency(s.value)}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function CostAllocation({
    by_site: rawSite,
    by_resident: rawResident,
    days,
    stats: rawStats,
}: Props) {
    const bySite = rawSite ?? [];
    const byResident = rawResident ?? [];
    const stats = rawStats ?? {
        total_fleet_cost: 0,
        cost_per_vehicle: 0,
        cost_per_resident: 0,
        cost_per_house: 0,
        total_fuel: 0,
        total_maintenance: 0,
    };

    const handlePeriodChange = (value: string) => {
        router.get(
            '/fleet-assets/reports/cost-allocation',
            { days: value },
            { preserveState: true },
        );
    };

    const handleExport = (tab: string) => {
        window.location.href = `/fleet-assets/reports/cost-allocation?export=csv&tab=${tab}&days=${days}`;
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Reports', href: '/fleet-assets/reports' },
                {
                    title: 'Cost Allocation',
                    href: '/fleet-assets/reports/cost-allocation',
                },
            ]}
        >
            <Head title="Cost Allocation" />
            <PageShell>
                <FleetCompactHero
                    pill={`Fleet reports · last ${days} days`}
                    title="Cost Allocation"
                    backHref="/fleet-assets/reports"
                    backLabel="Reports"
                    stats={
                        <>
                            <CompactHeroStat
                                label="Fleet cost"
                                value={formatCurrency(stats.total_fleet_cost)}
                                tone="neutral"
                            />
                            <CompactHeroStat
                                label="Per vehicle"
                                value={formatCurrency(stats.cost_per_vehicle)}
                                tone="neutral"
                            />
                            <CompactHeroStat
                                label="Per resident"
                                value={formatCurrency(stats.cost_per_resident)}
                                tone="neutral"
                            />
                            <CompactHeroStat
                                label="Per house"
                                value={formatCurrency(stats.cost_per_house)}
                                tone="neutral"
                            />
                        </>
                    }
                />
                <p className="text-sm text-muted-foreground">
                    Analyse fleet costs allocated by house/site and by resident.
                </p>

                {/* Period Selector */}
                <div className="flex items-center gap-3">
                    <PieChart className="h-5 w-5 text-muted-foreground" />
                    <Select
                        value={String(days)}
                        onValueChange={handlePeriodChange}
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="Select period" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="30">Last 30 days</SelectItem>
                            <SelectItem value="90">Last 90 days</SelectItem>
                            <SelectItem value="180">Last 6 months</SelectItem>
                            <SelectItem value="365">Last 12 months</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Main Content */}
                <div className="grid gap-4 lg:grid-cols-[1fr_240px]">
                    {/* Tabs */}
                    <Tabs defaultValue="house" className="space-y-4">
                        <div className="flex items-center justify-between">
                            <TabsList>
                                <TabsTrigger value="house">
                                    By House
                                </TabsTrigger>
                                <TabsTrigger value="resident">
                                    By Resident
                                </TabsTrigger>
                            </TabsList>
                        </div>

                        {/* By House Tab */}
                        <TabsContent value="house" className="space-y-4">
                            <div className="flex justify-end">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => handleExport('house')}
                                >
                                    <Download className="mr-1.5 h-3.5 w-3.5" />{' '}
                                    Export CSV
                                </Button>
                            </div>

                            {bySite.length > 0 ? (
                                <>
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-sm">
                                                Total Cost by House
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <HorizontalBarChart
                                                items={bySite
                                                    .sort(
                                                        (a, b) =>
                                                            b.total - a.total,
                                                    )
                                                    .map((s) => ({
                                                        label: s.name,
                                                        value: s.total,
                                                    }))}
                                                color={FLEET_COLORS.primary}
                                            />
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">
                                                House Cost Breakdown
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
                                                                House
                                                            </th>
                                                            <th className="px-3 py-2 text-right font-medium">
                                                                Vehicles
                                                            </th>
                                                            <th className="px-3 py-2 text-right font-medium">
                                                                Fuel Cost
                                                            </th>
                                                            <th className="px-3 py-2 text-right font-medium">
                                                                Maintenance
                                                            </th>
                                                            <th className="px-3 py-2 text-right font-medium">
                                                                Total
                                                            </th>
                                                            <th className="px-3 py-2 text-right font-medium">
                                                                Cost/Vehicle
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {bySite.map((s) => (
                                                            <tr
                                                                key={s.id}
                                                                className="border-b transition-colors last:border-b-0 hover:bg-muted/30"
                                                            >
                                                                <td className="px-3 py-2">
                                                                    <div className="flex items-center gap-2">
                                                                        <Building2 className="h-3.5 w-3.5 text-muted-foreground" />
                                                                        <span className="font-medium">
                                                                            {
                                                                                s.name
                                                                            }
                                                                        </span>
                                                                    </div>
                                                                </td>
                                                                <td className="px-3 py-2 text-right tabular-nums">
                                                                    {s.vehicles}
                                                                </td>
                                                                <td className="px-3 py-2 text-right tabular-nums">
                                                                    {formatCurrency(
                                                                        s.fuel_cost,
                                                                    )}
                                                                </td>
                                                                <td className="px-3 py-2 text-right tabular-nums">
                                                                    {formatCurrency(
                                                                        s.maintenance_cost,
                                                                    )}
                                                                </td>
                                                                <td className="px-3 py-2 text-right font-medium tabular-nums">
                                                                    {formatCurrency(
                                                                        s.total,
                                                                    )}
                                                                </td>
                                                                <td className="px-3 py-2 text-right text-muted-foreground tabular-nums">
                                                                    {formatCurrency(
                                                                        s.cost_per_vehicle,
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </CardContent>
                                    </Card>
                                </>
                            ) : (
                                <FleetEmptyState
                                    icon={Building2}
                                    title="No cost data"
                                    description="No fleet cost data is available for the selected period."
                                />
                            )}
                        </TabsContent>

                        {/* By Resident Tab */}
                        <TabsContent value="resident" className="space-y-4">
                            <div className="flex justify-end">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => handleExport('resident')}
                                >
                                    <Download className="mr-1.5 h-3.5 w-3.5" />{' '}
                                    Export CSV
                                </Button>
                            </div>

                            {byResident.length > 0 ? (
                                <>
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-sm">
                                                Transport Cost by Resident
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <HorizontalBarChart
                                                items={byResident
                                                    .sort(
                                                        (a, b) =>
                                                            b.estimated_cost -
                                                            a.estimated_cost,
                                                    )
                                                    .slice(0, 15)
                                                    .map((r) => ({
                                                        label: r.name,
                                                        value: r.estimated_cost,
                                                    }))}
                                                color={FLEET_COLORS.accent}
                                            />
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">
                                                Resident Transport Costs
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
                                                                Trips
                                                            </th>
                                                            <th className="px-3 py-2 text-right font-medium">
                                                                Distance (km)
                                                            </th>
                                                            <th className="px-3 py-2 text-right font-medium">
                                                                Est. Cost
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {byResident.map((r) => (
                                                            <tr
                                                                key={r.id}
                                                                className="border-b transition-colors last:border-b-0 hover:bg-muted/30"
                                                            >
                                                                <td className="px-3 py-2 font-medium">
                                                                    {r.name}
                                                                </td>
                                                                <td className="px-3 py-2 text-muted-foreground">
                                                                    {r.house ||
                                                                        '---'}
                                                                </td>
                                                                <td className="px-3 py-2 text-right tabular-nums">
                                                                    {r.trips}
                                                                </td>
                                                                <td className="px-3 py-2 text-right tabular-nums">
                                                                    {r.distance_km.toLocaleString()}
                                                                </td>
                                                                <td className="px-3 py-2 text-right font-medium tabular-nums">
                                                                    {formatCurrency(
                                                                        r.estimated_cost,
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </CardContent>
                                    </Card>
                                </>
                            ) : (
                                <FleetEmptyState
                                    icon={Users}
                                    title="No resident transport data"
                                    description="No resident transport records found for the selected period."
                                />
                            )}
                        </TabsContent>
                    </Tabs>

                    {/* Cost Category Donut */}
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm">
                                    Cost Breakdown
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex justify-center">
                                <CostDonut
                                    fuel={stats.total_fuel}
                                    maintenance={stats.total_maintenance}
                                />
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
