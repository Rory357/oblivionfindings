import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';
import { Minus, TrendingDown, TrendingUp } from 'lucide-react';

type Indicator = {
    id: number;
    indicator_code: string;
    name: string;
    category: string;
    category_label: string;
    unit: string | null;
    target_value: number | null;
    target_direction: 'above' | 'below' | 'equal';
    is_active: boolean;
};

type SnapshotValue = {
    indicator_id: number;
    indicator_code: string;
    value: number;
    status: 'normal' | 'warning' | 'critical';
    trend: 'up' | 'down' | 'stable';
    source_href: string | null;
    source_label: string | null;
};

type Snapshot = {
    id: number;
    period_start: string | null;
    period_end: string | null;
    indicator_values: SnapshotValue[];
    narrative: string | null;
};

type Props = {
    snapshots: Snapshot[];
    indicators: Indicator[];
    sourceHint: string;
};

const statusClasses: Record<SnapshotValue['status'], string> = {
    normal: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
};

function formatPeriod(start: string | null, end: string | null): string {
    if (!start || !end) {
        return 'Current period';
    }

    return `${new Date(start).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    })} - ${new Date(end).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    })}`;
}

function formatColumnDate(date: string | null): string {
    if (!date) {
        return 'Current';
    }

    return new Date(date).toLocaleDateString('en-NZ', {
        month: 'short',
        year: '2-digit',
    });
}

export default function ClinicalTrends({ snapshots, indicators, sourceHint }: Props) {
    const latestSnapshot = snapshots[0] ?? null;
    const activeIndicators = indicators.filter((indicator) => indicator.is_active);
    const historicalSnapshots = snapshots.slice(0, 6).reverse();

    const valueFor = (snapshot: Snapshot | null, indicatorId: number): SnapshotValue | null =>
        snapshot?.indicator_values.find((value) => value.indicator_id === indicatorId) ?? null;

    return (
        <AppLayout>
            <Head title="Clinical Governance Trends" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-foreground">Clinical Governance Trends</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Recent automated snapshot history for the Governance clinical indicators.
                        </p>
                    </div>
                    <Link href="/governance/clinical">
                        <Button variant="outline">Dashboard</Button>
                    </Link>
                </div>

                <Card className="border-status-info/30 bg-status-info-bg">
                    <CardContent className="flex flex-col gap-2 p-4 text-sm text-status-info sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="font-medium">Automated source</p>
                            <p className="text-status-info">{sourceHint}</p>
                        </div>
                        <Badge variant="secondary" className="w-fit bg-white text-status-info">
                            {formatPeriod(latestSnapshot?.period_start ?? null, latestSnapshot?.period_end ?? null)}
                        </Badge>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Current Snapshot</CardTitle>
                        <CardDescription>
                            {latestSnapshot
                                ? formatPeriod(latestSnapshot.period_start, latestSnapshot.period_end)
                                : 'No snapshot recorded yet.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {!latestSnapshot ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                No clinical governance snapshots are available yet.
                            </p>
                        ) : (
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                                {activeIndicators.map((indicator) => {
                                    const latestValue = valueFor(latestSnapshot, indicator.id);

                                    return (
                                        <div key={indicator.id} className="rounded-xl border p-4">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-semibold text-foreground">
                                                        {indicator.name}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {indicator.category_label}
                                                    </p>
                                                </div>
                                                <Badge
                                                    className={cn(
                                                        latestValue ? statusClasses[latestValue.status] : 'bg-muted text-foreground',
                                                    )}
                                                >
                                                    {latestValue?.status ?? 'No data'}
                                                </Badge>
                                            </div>

                                            <div className="mt-3 flex items-end justify-between gap-3">
                                                <div className="flex items-baseline gap-2">
                                                    <span className="text-2xl font-bold text-foreground">
                                                        {latestValue ? latestValue.value : '—'}
                                                    </span>
                                                    {indicator.unit && (
                                                        <span className="text-xs uppercase tracking-wide text-muted-foreground">
                                                            {indicator.unit}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {latestValue?.trend === 'up' ? (
                                                        <TrendingUp className="h-4 w-4" />
                                                    ) : latestValue?.trend === 'down' ? (
                                                        <TrendingDown className="h-4 w-4" />
                                                    ) : (
                                                        <Minus className="h-4 w-4" />
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {historicalSnapshots.length > 0 && activeIndicators.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Historical Performance</CardTitle>
                            <CardDescription>Last {historicalSnapshots.length} recorded monthly snapshots.</CardDescription>
                        </CardHeader>
                        <CardContent className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/40">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-medium">Indicator</th>
                                        {historicalSnapshots.map((snapshot) => (
                                            <th key={snapshot.id} className="px-4 py-3 text-center font-medium whitespace-nowrap">
                                                {formatColumnDate(snapshot.period_end)}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {activeIndicators.map((indicator) => (
                                        <tr key={indicator.id}>
                                            <td className="px-4 py-3">
                                                <div className="font-medium text-foreground">{indicator.name}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    Target {indicator.target_direction === 'below' ? '≤' : indicator.target_direction === 'above' ? '≥' : '='}{' '}
                                                    {indicator.target_value ?? '—'}
                                                </div>
                                            </td>
                                            {historicalSnapshots.map((snapshot) => {
                                                const entry = valueFor(snapshot, indicator.id);

                                                return (
                                                    <td key={snapshot.id} className="px-4 py-3 text-center">
                                                        {entry ? (
                                                            <Badge className={cn(statusClasses[entry.status])}>
                                                                {entry.value}
                                                            </Badge>
                                                        ) : (
                                                            <span className="text-muted-foreground">—</span>
                                                        )}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                )}

                {latestSnapshot?.narrative && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Narrative</CardTitle>
                            <CardDescription>
                                {formatPeriod(latestSnapshot.period_start, latestSnapshot.period_end)}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm leading-6 text-muted-foreground">{latestSnapshot.narrative}</p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
