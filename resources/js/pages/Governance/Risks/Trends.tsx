import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { AlertTriangle, AlertCircle, TrendingUp, Shield } from 'lucide-react';

interface Snapshot {
    id: number;
    snapshot_date: string;
    summary: {
        critical: number;
        high: number;
        medium: number;
        low: number;
        above_appetite: number;
    };
    by_category: Record<string, { count: number; avg_score: number }>;
}

interface Props extends PageProps {
    snapshots: Snapshot[];
}

const severityColor = (level: string) => {
    switch (level) {
        case 'critical': return 'bg-status-critical text-white';
        case 'high': return 'bg-status-warning text-white';
        case 'medium': return 'bg-status-warning text-black';
        case 'low': return 'bg-status-success text-white';
        default: return 'bg-muted-foreground/80 text-white';
    }
};

export default function RiskTrends({ auth, snapshots }: Props) {
    const latest = snapshots[0] ?? null;
    const displaySnapshots = snapshots.slice(0, 12);

    const maxCritHigh = Math.max(
        ...displaySnapshots.map((s) => s.summary.critical + s.summary.high),
        1,
    );

    const formatDate = (d: string) =>
        new Date(d).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Risks', href: '/governance/risks' },
                { title: 'Trends', href: '/governance/risks/trends' },
            ]}
        >
            <Head title="Risk Trends" />

            <PageLayout
                hero={
                    <PageHero
                        icon={TrendingUp}
                        title="Risk Trends"
                        description="Historical risk snapshot analysis."
                        stats={[
                            { label: 'Snapshots', value: snapshots.length },
                            { label: 'Critical', value: latest?.summary.critical ?? 0 },
                            { label: 'High', value: latest?.summary.high ?? 0 },
                            { label: 'Above Appetite', value: latest?.summary.above_appetite ?? 0 },
                        ]}
                    />
                }
            >
                {/* Current Summary */}
                {latest && (
                    <div className="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                        <Card className="border-status-critical/30">
                            <CardContent className="pt-6">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm text-status-critical">Critical</p>
                                        <p className="text-3xl font-bold text-status-critical">{latest.summary.critical}</p>
                                    </div>
                                    <AlertTriangle className="w-8 h-8 text-status-critical" />
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-status-warning/30">
                            <CardContent className="pt-6">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm text-status-warning">High</p>
                                        <p className="text-3xl font-bold text-status-warning">{latest.summary.high}</p>
                                    </div>
                                    <AlertCircle className="w-8 h-8 text-status-warning" />
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-status-warning/30">
                            <CardContent className="pt-6">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm text-status-warning">Medium</p>
                                        <p className="text-3xl font-bold text-status-warning">{latest.summary.medium}</p>
                                    </div>
                                    <Shield className="w-8 h-8 text-status-warning" />
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-status-success/30">
                            <CardContent className="pt-6">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm text-status-success">Low</p>
                                        <p className="text-3xl font-bold text-status-success">{latest.summary.low}</p>
                                    </div>
                                    <Shield className="w-8 h-8 text-status-success" />
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-primary">
                            <CardContent className="pt-6">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm text-primary">Above Appetite</p>
                                        <p className="text-3xl font-bold text-primary">{latest.summary.above_appetite}</p>
                                    </div>
                                    <TrendingUp className="w-8 h-8 text-primary" />
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* Historical Trend Bar Chart */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>Critical + High Risk Trend</CardTitle>
                        <CardDescription>Count of critical and high risks per snapshot</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex items-end gap-2 h-40">
                            {displaySnapshots
                                .slice()
                                .reverse()
                                .map((snap) => {
                                    const total = snap.summary.critical + snap.summary.high;
                                    const pct = (total / maxCritHigh) * 100;
                                    return (
                                        <div key={snap.id} className="flex-1 group relative">
                                            <div className="flex flex-col items-center">
                                                <div
                                                    className="w-full rounded-t bg-status-critical transition-all min-w-[12px]"
                                                    style={{ height: `${Math.max(pct, 4)}%` }}
                                                />
                                            </div>
                                            <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover:block whitespace-nowrap rounded bg-popover px-2 py-1 text-xs shadow-md z-10">
                                                {formatDate(snap.snapshot_date)}: {snap.summary.critical}C / {snap.summary.high}H
                                            </div>
                                        </div>
                                    );
                                })}
                        </div>
                        {displaySnapshots.length > 0 && (
                            <div className="mt-2 flex justify-between text-xs text-muted-foreground">
                                <span>{formatDate(displaySnapshots[displaySnapshots.length - 1].snapshot_date)}</span>
                                <span>{formatDate(displaySnapshots[0].snapshot_date)}</span>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* By Category Breakdown */}
                {latest && Object.keys(latest.by_category).length > 0 && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Risk by Category</CardTitle>
                            <CardDescription>Latest snapshot breakdown</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-medium">Category</th>
                                        <th className="px-4 py-3 text-center font-medium">Count</th>
                                        <th className="px-4 py-3 text-center font-medium">Avg Score</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {Object.entries(latest.by_category).map(([cat, data]) => (
                                        <tr key={cat} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-medium capitalize">{cat.replace(/_/g, ' ')}</td>
                                            <td className="px-4 py-3 text-center">{data.count}</td>
                                            <td className="px-4 py-3 text-center">
                                                <Badge className={cn(
                                                    data.avg_score >= 20 ? severityColor('critical') :
                                                    data.avg_score >= 15 ? severityColor('high') :
                                                    data.avg_score >= 10 ? severityColor('medium') :
                                                    severityColor('low'),
                                                )}>
                                                    {data.avg_score.toFixed(1)}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                )}

                {/* Snapshot Timeline */}
                <Card>
                    <CardHeader>
                        <CardTitle>Snapshot Timeline</CardTitle>
                        <CardDescription>Last {displaySnapshots.length} snapshots</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-3">
                            {displaySnapshots.map((snap) => (
                                <div key={snap.id} className="flex items-center gap-4 p-3 rounded-lg border hover:bg-muted">
                                    <div className="text-sm font-medium text-muted-foreground w-28 shrink-0">
                                        {formatDate(snap.snapshot_date)}
                                    </div>
                                    <div className="flex gap-2 flex-wrap">
                                        <Badge className={severityColor('critical')}>{snap.summary.critical} Critical</Badge>
                                        <Badge className={severityColor('high')}>{snap.summary.high} High</Badge>
                                        <Badge className={severityColor('medium')}>{snap.summary.medium} Medium</Badge>
                                        <Badge className={severityColor('low')}>{snap.summary.low} Low</Badge>
                                        {snap.summary.above_appetite > 0 && (
                                            <Badge className="bg-primary/10 text-primary">{snap.summary.above_appetite} Above Appetite</Badge>
                                        )}
                                    </div>
                                </div>
                            ))}
                            {displaySnapshots.length === 0 && (
                                <div className="py-8 text-center text-sm text-muted-foreground">No snapshots recorded yet.</div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
