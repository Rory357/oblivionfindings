import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { Activity, Droplets, HeartPulse, Scale } from 'lucide-react';
import { type ReactNode, useState } from 'react';

type ClientRef = {
    id: number;
    first_name: string;
    last_name: string;
};

type Filters = {
    date_from: string;
    date_to: string;
};

type WeightPoint = {
    id: number;
    recorded_at: string;
    short_label: string;
    weight_kg: number;
};

type PainPoint = {
    id: number;
    recorded_at: string;
    short_label: string;
    score: number;
    location: string | null;
};

type VitalsPoint = {
    id: number;
    recorded_at: string;
    short_label: string;
    systolic: number;
    diastolic: number;
    pulse: number;
};

type FluidPoint = {
    id: number;
    recorded_at: string;
    short_label: string;
    amount_ml: number;
    fluid_type: string | null;
};

type TrendSet<TPoint> = {
    key: string;
    label: string;
    description: string;
    points: TPoint[];
    count: number;
    latest: TPoint | null;
};

type Props = {
    client: ClientRef;
    filters: Filters;
    trend_sets: {
        weight: TrendSet<WeightPoint>;
        pain: TrendSet<PainPoint>;
        vitals: TrendSet<VitalsPoint>;
        fluid_intake: TrendSet<FluidPoint>;
    };
    has_chartable_data: boolean;
    chartable_observation_count: number;
};

function defaultDateRange(): Filters {
    const now = new Date();
    const from = new Date(now);
    from.setDate(now.getDate() - 29);

    const toDateInput = (value: Date) => {
        const year = value.getFullYear();
        const month = String(value.getMonth() + 1).padStart(2, '0');
        const day = String(value.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    };

    return {
        date_from: toDateInput(from),
        date_to: toDateInput(now),
    };
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

type GenericLine = {
    dataKey: string;
    name: string;
    color: string;
};

function TrendChartCard<TPoint extends { recorded_at: string; short_label: string }>({
    title,
    description,
    points,
    latestLabel,
    emptyLabel,
    lines,
    tooltipFormatter,
    icon,
}: {
    title: string;
    description: string;
    points: TPoint[];
    latestLabel?: string | null;
    emptyLabel: string;
    lines: GenericLine[];
    tooltipFormatter?: (value: number | string, name: string, payload: TPoint) => [string, string];
    icon: ReactNode;
}) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <CardTitle className="flex items-center gap-2 text-base">
                            {icon} {title}
                        </CardTitle>
                        <CardDescription>{description}</CardDescription>
                    </div>
                    <div className="text-right text-xs text-muted-foreground">
                        <p>{points.length} entries</p>
                        {latestLabel ? <p className="mt-1">{latestLabel}</p> : null}
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                {points.length === 0 ? (
                    <div className="flex h-[260px] items-center justify-center rounded-lg border border-dashed text-sm text-muted-foreground">
                        {emptyLabel}
                    </div>
                ) : (
                    <div className="h-[260px]">
                        <ResponsiveContainer width="100%" height="100%">
                            <LineChart data={points}>
                                <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                                <XAxis
                                    dataKey="short_label"
                                    tick={{ fontSize: 12 }}
                                    className="text-muted-foreground"
                                />
                                <YAxis tick={{ fontSize: 12 }} className="text-muted-foreground" />
                                <Tooltip
                                    labelFormatter={(_, payload) => {
                                        const point = payload?.[0]?.payload as TPoint | undefined;
                                        return point ? formatDateTime(point.recorded_at) : '';
                                    }}
                                    formatter={(value, name, item) => {
                                        const point = item.payload as TPoint;
                                        if (tooltipFormatter) {
                                            return tooltipFormatter(value as number | string, name, point);
                                        }

                                        return [String(value), name];
                                    }}
                                />
                                <Legend />
                                {lines.map((line) => (
                                    <Line
                                        key={line.dataKey}
                                        type="monotone"
                                        dataKey={line.dataKey}
                                        name={line.name}
                                        stroke={line.color}
                                        strokeWidth={2}
                                        dot={{ r: 3 }}
                                        activeDot={{ r: 5 }}
                                    />
                                ))}
                            </LineChart>
                        </ResponsiveContainer>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function ClientTrends({
    client,
    filters,
    trend_sets,
    has_chartable_data,
    chartable_observation_count,
}: Props) {
    const [localFilters, setLocalFilters] = useState<Filters>(filters);
    const clientName = `${client.first_name} ${client.last_name}`;

    const applyFilters = () => {
        router.get(
            `/health-clinical/clients/${client.id}/trends`,
            localFilters,
            {
                replace: true,
            },
        );
    };

    const resetFilters = () => {
        const next = defaultDateRange();

        setLocalFilters(next);
        router.get(
            `/health-clinical/clients/${client.id}/trends`,
            {},
            {
                replace: true,
            },
        );
    };

    return (
        <AppLayout>
            <Head title={`Observation Trends — ${clientName}`} />

            <div className="mx-auto max-w-7xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Observation Trends</h1>
                        <p className="text-sm text-muted-foreground">
                            {clientName} with chartable observation data over time.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href={`/health-clinical/clients/${client.id}/summary`}>
                            <Button variant="outline" size="sm">Health Summary</Button>
                        </Link>
                        <Link href={`/operations/clients/${client.id}`}>
                            <Button variant="outline" size="sm">Client Profile</Button>
                        </Link>
                        <Link href={`/health-clinical/observations?client_id=${client.id}`}>
                            <Button variant="outline" size="sm">Observation Register</Button>
                        </Link>
                    </div>
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Date Range</CardTitle>
                        <CardDescription>
                            Last 30 days is the default window for this trends view.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div>
                                <Label htmlFor="date_from" className="text-xs">From</Label>
                                <Input
                                    id="date_from"
                                    type="date"
                                    value={localFilters.date_from}
                                    onChange={(event) =>
                                        setLocalFilters((current) => ({
                                            ...current,
                                            date_from: event.target.value,
                                        }))
                                    }
                                />
                            </div>
                            <div>
                                <Label htmlFor="date_to" className="text-xs">To</Label>
                                <Input
                                    id="date_to"
                                    type="date"
                                    value={localFilters.date_to}
                                    onChange={(event) =>
                                        setLocalFilters((current) => ({
                                            ...current,
                                            date_to: event.target.value,
                                        }))
                                    }
                                />
                            </div>
                            <div className="flex items-end gap-2">
                                <Button size="sm" onClick={applyFilters}>
                                    Apply
                                </Button>
                                <Button size="sm" variant="ghost" onClick={resetFilters}>
                                    Reset
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {!has_chartable_data ? (
                    <Card>
                        <CardContent className="flex h-[220px] flex-col items-center justify-center gap-2 text-center">
                            <Activity className="h-8 w-8 text-muted-foreground" />
                            <h2 className="text-lg font-semibold">No chartable observations in this range</h2>
                            <p className="max-w-lg text-sm text-muted-foreground">
                                Try a wider date range or review the observation register for non-chartable entries such as general notes or sleep logs.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-6 xl:grid-cols-2">
                        <TrendChartCard
                            title="Weight"
                            description="Weight observations recorded for this client."
                            points={trend_sets.weight.points}
                            latestLabel={trend_sets.weight.latest ? `Latest ${trend_sets.weight.latest.weight_kg} kg` : null}
                            emptyLabel="No weight observations in this date range."
                            lines={[
                                { dataKey: 'weight_kg', name: 'Weight (kg)', color: '#059669' },
                            ]}
                            tooltipFormatter={(value) => [`${Number(value).toFixed(1)} kg`, 'Weight']}
                            icon={<Scale className="h-4 w-4 text-emerald-600" />}
                        />

                        <TrendChartCard
                            title="Pain Score"
                            description="Pain assessment scores on the 0 to 10 scale."
                            points={trend_sets.pain.points}
                            latestLabel={trend_sets.pain.latest ? `Latest ${trend_sets.pain.latest.score}/10` : null}
                            emptyLabel="No pain observations in this date range."
                            lines={[
                                { dataKey: 'score', name: 'Pain Score', color: '#dc2626' },
                            ]}
                            tooltipFormatter={(value, _name, payload) => [
                                `${Number(value).toFixed(0)}/10${payload.location ? ` · ${payload.location}` : ''}`,
                                'Pain Score',
                            ]}
                            icon={<Activity className="h-4 w-4 text-rose-600" />}
                        />

                        <TrendChartCard
                            title="Vitals"
                            description="Systolic, diastolic, and pulse observations."
                            points={trend_sets.vitals.points}
                            latestLabel={trend_sets.vitals.latest
                                ? `Latest ${trend_sets.vitals.latest.systolic}/${trend_sets.vitals.latest.diastolic}, pulse ${trend_sets.vitals.latest.pulse}`
                                : null}
                            emptyLabel="No vitals observations in this date range."
                            lines={[
                                { dataKey: 'systolic', name: 'Systolic', color: '#2563eb' },
                                { dataKey: 'diastolic', name: 'Diastolic', color: '#0f766e' },
                                { dataKey: 'pulse', name: 'Pulse', color: '#f59e0b' },
                            ]}
                            tooltipFormatter={(value, name) => [String(value), name]}
                            icon={<HeartPulse className="h-4 w-4 text-blue-600" />}
                        />

                        <TrendChartCard
                            title="Fluid Intake"
                            description="Fluid intake amounts for chartable entries."
                            points={trend_sets.fluid_intake.points}
                            latestLabel={trend_sets.fluid_intake.latest
                                ? `Latest ${trend_sets.fluid_intake.latest.amount_ml} ml`
                                : null}
                            emptyLabel="No fluid intake observations in this date range."
                            lines={[
                                { dataKey: 'amount_ml', name: 'Amount (ml)', color: '#0891b2' },
                            ]}
                            tooltipFormatter={(value, _name, payload) => [
                                `${Number(value).toFixed(0)} ml${payload.fluid_type ? ` · ${payload.fluid_type}` : ''}`,
                                'Fluid Intake',
                            ]}
                            icon={<Droplets className="h-4 w-4 text-cyan-600" />}
                        />
                    </div>
                )}

                <Card>
                    <CardContent className="flex items-center justify-between gap-3 p-4">
                        <div>
                            <p className="text-sm font-medium">Chartable observations in range</p>
                            <p className="text-xs text-muted-foreground">
                                {chartable_observation_count} entries across weight, pain, vitals, and fluid intake.
                            </p>
                        </div>
                        <Link href={`/health-clinical/observations?client_id=${client.id}&date_from=${filters.date_from}&date_to=${filters.date_to}`}>
                            <Button size="sm" variant="outline">
                                View matching register entries
                            </Button>
                        </Link>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
