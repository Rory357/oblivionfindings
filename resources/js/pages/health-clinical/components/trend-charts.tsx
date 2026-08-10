/* Shared clinical trend charts — used by the per-client Trends page
 * (ClientTrends.tsx) and the module Trends tab so the two never drift. Line
 * colours come from semantic design tokens (no hardcoded hex). */
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Activity,
    Droplets,
    HeartPulse,
    Scale,
    TrendingUp,
} from 'lucide-react';
import { type ReactNode } from 'react';
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

export type TrendSet<TPoint> = {
    key: string;
    label: string;
    description: string;
    points: TPoint[];
    count: number;
    latest: TPoint | null;
};

type Base = { id: number; recorded_at: string; short_label: string };
export type WeightPoint = Base & { weight_kg: number };
export type PainPoint = Base & { score: number; location: string | null };
export type VitalsPoint = Base & {
    systolic: number;
    diastolic: number;
    pulse: number;
};
export type FluidPoint = Base & {
    amount_ml: number;
    fluid_type: string | null;
};
export type News2Point = Base & { score: number; band: string | null };

export type TrendSetsMap = {
    weight: TrendSet<WeightPoint>;
    pain: TrendSet<PainPoint>;
    vitals: TrendSet<VitalsPoint>;
    fluid_intake: TrendSet<FluidPoint>;
    news2?: TrendSet<News2Point>;
};

function formatDateTime(value: string): string {
    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

type GenericLine = { dataKey: string; name: string; color: string };

export function TrendChartCard<
    TPoint extends { recorded_at: string; short_label: string },
>({
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
    tooltipFormatter?: (
        value: number | string,
        name: string,
        payload: TPoint,
    ) => [string, string];
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
                        {latestLabel ? (
                            <p className="mt-1">{latestLabel}</p>
                        ) : null}
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
                                <CartesianGrid
                                    strokeDasharray="3 3"
                                    className="stroke-muted"
                                />
                                <XAxis
                                    dataKey="short_label"
                                    tick={{ fontSize: 12 }}
                                    className="text-muted-foreground"
                                />
                                <YAxis
                                    tick={{ fontSize: 12 }}
                                    className="text-muted-foreground"
                                />
                                <Tooltip
                                    labelFormatter={(_, payload) => {
                                        const point = payload?.[0]?.payload as
                                            | TPoint
                                            | undefined;
                                        return point
                                            ? formatDateTime(point.recorded_at)
                                            : '';
                                    }}
                                    formatter={(value, name, item) => {
                                        const point = item.payload as TPoint;
                                        if (tooltipFormatter) {
                                            return tooltipFormatter(
                                                value as number | string,
                                                name as string,
                                                point,
                                            );
                                        }
                                        return [String(value), name as string];
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

/** The grid of trend charts. NEWS2 leads when present (module Trends tab). */
export function TrendChartsGrid({ trendSets }: { trendSets: TrendSetsMap }) {
    return (
        <div className="grid gap-6 xl:grid-cols-2">
            {trendSets.news2 ? (
                <TrendChartCard
                    title="NEWS2"
                    description="Early-warning score from recorded vitals."
                    points={trendSets.news2.points}
                    latestLabel={
                        trendSets.news2.latest
                            ? `Latest ${trendSets.news2.latest.score}`
                            : null
                    }
                    emptyLabel="No NEWS2-scored vitals in this date range."
                    lines={[
                        {
                            dataKey: 'score',
                            name: 'NEWS2',
                            color: 'var(--primary)',
                        },
                    ]}
                    tooltipFormatter={(value, _n, p) => [
                        `${value}${p.band ? ` · ${p.band.replace('_', '-')}` : ''}`,
                        'NEWS2',
                    ]}
                    icon={<TrendingUp className="h-4 w-4 text-primary" />}
                />
            ) : null}

            <TrendChartCard
                title="Vitals"
                description="Systolic, diastolic, and pulse observations."
                points={trendSets.vitals.points}
                latestLabel={
                    trendSets.vitals.latest
                        ? `Latest ${trendSets.vitals.latest.systolic}/${trendSets.vitals.latest.diastolic}, pulse ${trendSets.vitals.latest.pulse}`
                        : null
                }
                emptyLabel="No vitals observations in this date range."
                lines={[
                    // Distinct chart-palette hues — NOT --status-* (which alias --primary, collapsing the lines).
                    {
                        dataKey: 'systolic',
                        name: 'Systolic',
                        color: 'var(--chart-1)',
                    },
                    {
                        dataKey: 'diastolic',
                        name: 'Diastolic',
                        color: 'var(--chart-2)',
                    },
                    {
                        dataKey: 'pulse',
                        name: 'Pulse',
                        color: 'var(--chart-4)',
                    },
                ]}
                tooltipFormatter={(value, name) => [String(value), name]}
                icon={<HeartPulse className="h-4 w-4 text-status-info" />}
            />

            <TrendChartCard
                title="Weight"
                description="Weight observations recorded for this client."
                points={trendSets.weight.points}
                latestLabel={
                    trendSets.weight.latest
                        ? `Latest ${trendSets.weight.latest.weight_kg} kg`
                        : null
                }
                emptyLabel="No weight observations in this date range."
                lines={[
                    {
                        dataKey: 'weight_kg',
                        name: 'Weight (kg)',
                        color: 'var(--chart-2)',
                    },
                ]}
                tooltipFormatter={(value) => [
                    `${Number(value).toFixed(1)} kg`,
                    'Weight',
                ]}
                icon={<Scale className="h-4 w-4 text-status-success" />}
            />

            <TrendChartCard
                title="Pain Score"
                description="Pain assessment scores on the 0 to 10 scale."
                points={trendSets.pain.points}
                latestLabel={
                    trendSets.pain.latest
                        ? `Latest ${trendSets.pain.latest.score}/10`
                        : null
                }
                emptyLabel="No pain observations in this date range."
                lines={[
                    {
                        dataKey: 'score',
                        name: 'Pain Score',
                        color: 'var(--chart-3)',
                    },
                ]}
                tooltipFormatter={(value, _name, payload) => [
                    `${Number(value).toFixed(0)}/10${payload.location ? ` · ${payload.location}` : ''}`,
                    'Pain Score',
                ]}
                icon={<Activity className="h-4 w-4 text-status-critical" />}
            />

            <TrendChartCard
                title="Fluid Intake"
                description="Fluid intake amounts for chartable entries."
                points={trendSets.fluid_intake.points}
                latestLabel={
                    trendSets.fluid_intake.latest
                        ? `Latest ${trendSets.fluid_intake.latest.amount_ml} ml`
                        : null
                }
                emptyLabel="No fluid intake observations in this date range."
                lines={[
                    {
                        dataKey: 'amount_ml',
                        name: 'Amount (ml)',
                        color: 'var(--chart-1)',
                    },
                ]}
                tooltipFormatter={(value, _name, payload) => [
                    `${Number(value).toFixed(0)} ml${payload.fluid_type ? ` · ${payload.fluid_type}` : ''}`,
                    'Fluid Intake',
                ]}
                icon={<Droplets className="h-4 w-4 text-status-info" />}
            />
        </div>
    );
}
