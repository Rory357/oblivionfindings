import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';

interface HeatmapCell {
    score: number;
    count: number;
    color: string;
}

interface TrendPoint {
    month: string;
    new_risks: number;
}

interface Props extends PageProps {
    heatmap: HeatmapCell[][];
    trend: TrendPoint[];
}

export default function RiskHeatmap({ auth, heatmap, trend }: Props) {
    const impactLabels = [
        'Insignificant',
        'Minor',
        'Moderate',
        'Major',
        'Catastrophic',
    ];
    const likelihoodLabels = [
        'Almost Certain',
        'Likely',
        'Possible',
        'Unlikely',
        'Rare',
    ];

    const getRiskLevel = (score: number) => {
        if (score >= 20) return 'Critical';
        if (score >= 15) return 'High';
        if (score >= 10) return 'Medium';
        return 'Low';
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Risks', href: '/governance/risks' },
                { title: 'Heatmap', href: '/governance/risks/heatmap' },
            ]}
        >
            <Head title="Risk Heatmap" />

            <PageLayout
                hero={
                    <PageHero
                        icon={AlertTriangle}
                        title="Risk Heatmap"
                        description="Visual representation of risk distribution across likelihood and impact."
                        stats={[
                            {
                                label: 'Total Risks',
                                value: heatmap
                                    .flat()
                                    .reduce((sum, c) => sum + c.count, 0),
                            },
                            { label: 'Period', value: '12 mo' },
                        ]}
                    />
                }
            >
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Heatmap */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Inherent Risk Matrix</CardTitle>
                            <CardDescription>
                                Likelihood × Impact = Risk Score
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <div className="min-w-[500px]">
                                    {/* Header row for Impact */}
                                    <div className="flex">
                                        <div className="w-24"></div>
                                        <div className="mb-2 flex-1 text-center text-sm font-medium text-muted-foreground">
                                            IMPACT →
                                        </div>
                                    </div>

                                    {/* Matrix */}
                                    <div className="flex">
                                        {/* Likelihood labels */}
                                        <div className="flex w-24 flex-col justify-around pr-2 text-xs text-muted-foreground">
                                            <div className="flex h-12 items-center justify-end">
                                                <span className="-rotate-90 whitespace-nowrap">
                                                    LIKELIHOOD ↓
                                                </span>
                                            </div>
                                            {likelihoodLabels.map(
                                                (label, i) => (
                                                    <div
                                                        key={i}
                                                        className="flex h-16 items-center justify-end text-right"
                                                    >
                                                        {label}
                                                    </div>
                                                ),
                                            )}
                                        </div>

                                        {/* Grid */}
                                        <div className="flex-1">
                                            {/* Impact header */}
                                            <div className="flex">
                                                {impactLabels.map(
                                                    (label, i) => (
                                                        <div
                                                            key={i}
                                                            className="flex-1 py-2 text-center text-xs text-muted-foreground"
                                                        >
                                                            {label}
                                                        </div>
                                                    ),
                                                )}
                                            </div>

                                            {/* Cells */}
                                            {heatmap.map((row, rowIndex) => (
                                                <div
                                                    key={rowIndex}
                                                    className="flex"
                                                >
                                                    {row.map(
                                                        (cell, colIndex) => (
                                                            <div
                                                                key={colIndex}
                                                                className={cn(
                                                                    'flex h-16 flex-1 cursor-pointer flex-col items-center justify-center border border-white text-white transition-opacity hover:opacity-80',
                                                                    cell.count ===
                                                                        0 &&
                                                                        'opacity-30',
                                                                )}
                                                                style={{
                                                                    backgroundColor:
                                                                        cell.color,
                                                                }}
                                                                title={`Score: ${cell.score}, Risks: ${cell.count}`}
                                                            >
                                                                <span className="text-lg font-bold">
                                                                    {cell.score}
                                                                </span>
                                                                {cell.count >
                                                                    0 && (
                                                                    <span className="text-xs">
                                                                        {
                                                                            cell.count
                                                                        }{' '}
                                                                        risks
                                                                    </span>
                                                                )}
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Legend */}
                            <div className="mt-6 flex items-center justify-center gap-4">
                                <div className="flex items-center gap-2">
                                    <div
                                        className="h-4 w-4 rounded"
                                        style={{ backgroundColor: '#16a34a' }}
                                    ></div>
                                    <span className="text-sm text-muted-foreground">
                                        Low (1-9)
                                    </span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <div
                                        className="h-4 w-4 rounded"
                                        style={{ backgroundColor: '#ca8a04' }}
                                    ></div>
                                    <span className="text-sm text-muted-foreground">
                                        Medium (10-14)
                                    </span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <div
                                        className="h-4 w-4 rounded"
                                        style={{ backgroundColor: '#ea580c' }}
                                    ></div>
                                    <span className="text-sm text-muted-foreground">
                                        High (15-19)
                                    </span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <div
                                        className="h-4 w-4 rounded"
                                        style={{ backgroundColor: '#dc2626' }}
                                    ></div>
                                    <span className="text-sm text-muted-foreground">
                                        Critical (20-25)
                                    </span>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Stats & Trend */}
                    <div className="space-y-6">
                        {/* Risk Distribution */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Risk Distribution</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-3">
                                    {heatmap.flat().reduce(
                                        (acc, cell) => {
                                            const level = getRiskLevel(
                                                cell.score,
                                            );
                                            acc[level] =
                                                (acc[level] || 0) + cell.count;
                                            return acc;
                                        },
                                        {} as Record<string, number>,
                                    ) &&
                                        Object.entries(
                                            heatmap.flat().reduce(
                                                (acc, cell) => {
                                                    const level = getRiskLevel(
                                                        cell.score,
                                                    );
                                                    acc[level] =
                                                        (acc[level] || 0) +
                                                        cell.count;
                                                    return acc;
                                                },
                                                {} as Record<string, number>,
                                            ),
                                        )
                                            .sort((a, b) => {
                                                const order = [
                                                    'Critical',
                                                    'High',
                                                    'Medium',
                                                    'Low',
                                                ];
                                                return (
                                                    order.indexOf(a[0]) -
                                                    order.indexOf(b[0])
                                                );
                                            })
                                            .map(([level, count]) => (
                                                <div
                                                    key={level}
                                                    className="flex items-center justify-between"
                                                >
                                                    <span className="text-sm">
                                                        {level}
                                                    </span>
                                                    <Badge
                                                        className={cn(
                                                            level ===
                                                                'Critical' &&
                                                                'bg-status-critical-bg text-status-critical',
                                                            level === 'High' &&
                                                                'bg-status-warning-bg text-status-warning',
                                                            level ===
                                                                'Medium' &&
                                                                'bg-status-warning-bg text-status-warning',
                                                            level === 'Low' &&
                                                                'bg-status-success-bg text-status-success',
                                                        )}
                                                    >
                                                        {count}
                                                    </Badge>
                                                </div>
                                            ))}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Trend Chart */}
                        <Card>
                            <CardHeader>
                                <CardTitle>New Risks (12 Months)</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    {trend.slice(-6).map((point) => (
                                        <div
                                            key={point.month}
                                            className="flex items-center gap-2"
                                        >
                                            <span className="w-16 text-xs text-muted-foreground">
                                                {point.month}
                                            </span>
                                            <div className="h-4 flex-1 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-status-info"
                                                    style={{
                                                        width: `${Math.min(100, point.new_risks * 10)}%`,
                                                    }}
                                                />
                                            </div>
                                            <span className="w-6 text-right text-xs font-medium">
                                                {point.new_risks}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
