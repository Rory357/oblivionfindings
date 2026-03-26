import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
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
        case 'critical': return 'bg-red-500 text-white';
        case 'high': return 'bg-orange-500 text-white';
        case 'medium': return 'bg-yellow-500 text-black';
        case 'low': return 'bg-green-500 text-white';
        default: return 'bg-gray-500 text-white';
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

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-3xl font-bold text-gray-900">Risk Trends</h1>
                    <p className="text-gray-500 mt-1">Historical risk snapshot analysis</p>
                </div>

                {/* Current Summary */}
                {latest && (
                    <div className="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                        <Card className="border-red-200">
                            <CardContent className="pt-6">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm text-red-600">Critical</p>
                                        <p className="text-3xl font-bold text-red-600">{latest.summary.critical}</p>
                                    </div>
                                    <AlertTriangle className="w-8 h-8 text-red-500" />
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-orange-200">
                            <CardContent className="pt-6">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm text-orange-600">High</p>
                                        <p className="text-3xl font-bold text-orange-600">{latest.summary.high}</p>
                                    </div>
                                    <AlertCircle className="w-8 h-8 text-orange-500" />
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-yellow-200">
                            <CardContent className="pt-6">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm text-yellow-600">Medium</p>
                                        <p className="text-3xl font-bold text-yellow-600">{latest.summary.medium}</p>
                                    </div>
                                    <Shield className="w-8 h-8 text-yellow-500" />
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-green-200">
                            <CardContent className="pt-6">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm text-green-600">Low</p>
                                        <p className="text-3xl font-bold text-green-600">{latest.summary.low}</p>
                                    </div>
                                    <Shield className="w-8 h-8 text-green-500" />
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-purple-200">
                            <CardContent className="pt-6">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm text-purple-600">Above Appetite</p>
                                        <p className="text-3xl font-bold text-purple-600">{latest.summary.above_appetite}</p>
                                    </div>
                                    <TrendingUp className="w-8 h-8 text-purple-500" />
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
                                                    className="w-full rounded-t bg-red-500 transition-all min-w-[12px]"
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
                            <div className="mt-2 flex justify-between text-xs text-gray-500">
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
                                <div key={snap.id} className="flex items-center gap-4 p-3 rounded-lg border hover:bg-gray-50">
                                    <div className="text-sm font-medium text-gray-600 w-28 shrink-0">
                                        {formatDate(snap.snapshot_date)}
                                    </div>
                                    <div className="flex gap-2 flex-wrap">
                                        <Badge className={severityColor('critical')}>{snap.summary.critical} Critical</Badge>
                                        <Badge className={severityColor('high')}>{snap.summary.high} High</Badge>
                                        <Badge className={severityColor('medium')}>{snap.summary.medium} Medium</Badge>
                                        <Badge className={severityColor('low')}>{snap.summary.low} Low</Badge>
                                        {snap.summary.above_appetite > 0 && (
                                            <Badge className="bg-purple-100 text-purple-800">{snap.summary.above_appetite} Above Appetite</Badge>
                                        )}
                                    </div>
                                </div>
                            ))}
                            {displaySnapshots.length === 0 && (
                                <div className="py-8 text-center text-sm text-gray-500">No snapshots recorded yet.</div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
