import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { Activity, Target, TrendingUp, TrendingDown } from 'lucide-react';

interface Indicator {
    id: number;
    name: string;
    category: string;
    target_value: number;
    target_direction: string;
    unit: string;
    is_active: boolean;
}

interface Snapshot {
    id: number;
    period_start: string;
    period_end: string;
    indicator_values: Array<{ indicator_id: number; value: number }>;
    narrative: string | null;
}

interface Props extends PageProps {
    snapshots: Snapshot[];
    indicators: Indicator[];
}

function isOnTarget(value: number, target: number, direction: string): boolean {
    if (direction === 'above' || direction === 'higher') return value >= target;
    if (direction === 'below' || direction === 'lower') return value <= target;
    return value === target;
}

export default function ClinicalTrends({ auth, snapshots, indicators }: Props) {
    const activeIndicators = indicators.filter((ind) => ind.is_active);
    const latest = snapshots[0] ?? null;
    const recentSnapshots = snapshots.slice(0, 6);

    const getLatestValue = (indicatorId: number): number | null => {
        if (!latest) return null;
        const entry = latest.indicator_values.find((v) => v.indicator_id === indicatorId);
        return entry ? entry.value : null;
    };

    const getSnapshotValue = (snapshot: Snapshot, indicatorId: number): number | null => {
        const entry = snapshot.indicator_values.find((v) => v.indicator_id === indicatorId);
        return entry ? entry.value : null;
    };

    const formatPeriod = (start: string, end: string) => {
        const s = new Date(start).toLocaleDateString('en-NZ', { month: 'short', year: 'numeric' });
        const e = new Date(end).toLocaleDateString('en-NZ', { month: 'short', year: 'numeric' });
        return s === e ? s : `${s} - ${e}`;
    };

    const formatDate = (d: string) =>
        new Date(d).toLocaleDateString('en-NZ', { month: 'short', year: '2-digit' });

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Clinical', href: '/governance/clinical' },
                { title: 'Trends', href: '#' },
            ]}
        >
            <Head title="Clinical Governance Trends" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-3xl font-bold text-gray-900">Clinical Governance Trends</h1>
                    <p className="text-gray-500 mt-1">Indicator performance tracking over time</p>
                </div>

                {/* Current Indicator Values */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Activity className="w-5 h-5" />
                            Current Indicator Values
                        </CardTitle>
                        {latest && (
                            <CardDescription>
                                Period: {formatPeriod(latest.period_start, latest.period_end)}
                            </CardDescription>
                        )}
                    </CardHeader>
                    <CardContent>
                        {!latest ? (
                            <p className="text-center text-gray-500 py-8">No snapshots recorded yet.</p>
                        ) : (
                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                {activeIndicators.map((ind) => {
                                    const value = getLatestValue(ind.id);
                                    const onTarget = value !== null ? isOnTarget(value, ind.target_value, ind.target_direction) : false;
                                    return (
                                        <div
                                            key={ind.id}
                                            className={cn(
                                                'p-4 rounded-lg border',
                                                onTarget ? 'border-green-200 bg-green-50/50' : 'border-red-200 bg-red-50/50',
                                            )}
                                        >
                                            <div className="flex items-start justify-between">
                                                <div>
                                                    <p className="text-sm font-medium text-gray-900">{ind.name}</p>
                                                    <p className="text-xs text-gray-500 capitalize">{ind.category}</p>
                                                </div>
                                                {onTarget ? (
                                                    <TrendingUp className="w-4 h-4 text-green-500" />
                                                ) : (
                                                    <TrendingDown className="w-4 h-4 text-red-500" />
                                                )}
                                            </div>
                                            <div className="mt-2 flex items-baseline gap-2">
                                                <span className="text-2xl font-bold">
                                                    {value !== null ? value : '-'}
                                                </span>
                                                <span className="text-xs text-gray-500">{ind.unit}</span>
                                            </div>
                                            <div className="mt-1 flex items-center gap-1 text-xs text-gray-500">
                                                <Target className="w-3 h-3" />
                                                Target: {ind.target_direction} {ind.target_value} {ind.unit}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Historical Table */}
                {recentSnapshots.length > 0 && activeIndicators.length > 0 && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Historical Performance</CardTitle>
                            <CardDescription>Last {recentSnapshots.length} periods</CardDescription>
                        </CardHeader>
                        <CardContent className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-medium">Indicator</th>
                                        <th className="px-4 py-3 text-center font-medium">Target</th>
                                        {recentSnapshots
                                            .slice()
                                            .reverse()
                                            .map((snap) => (
                                                <th key={snap.id} className="px-4 py-3 text-center font-medium whitespace-nowrap">
                                                    {formatDate(snap.period_end)}
                                                </th>
                                            ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {activeIndicators.map((ind) => (
                                        <tr key={ind.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3">
                                                <div className="font-medium">{ind.name}</div>
                                                <div className="text-xs text-gray-500 capitalize">{ind.category}</div>
                                            </td>
                                            <td className="px-4 py-3 text-center text-xs text-gray-500">
                                                {ind.target_direction} {ind.target_value}
                                            </td>
                                            {recentSnapshots
                                                .slice()
                                                .reverse()
                                                .map((snap) => {
                                                    const val = getSnapshotValue(snap, ind.id);
                                                    const onTarget = val !== null ? isOnTarget(val, ind.target_value, ind.target_direction) : false;
                                                    return (
                                                        <td key={snap.id} className="px-4 py-3 text-center">
                                                            {val !== null ? (
                                                                <Badge className={cn(
                                                                    onTarget
                                                                        ? 'bg-green-100 text-green-800'
                                                                        : 'bg-red-100 text-red-800',
                                                                )}>
                                                                    {val}
                                                                </Badge>
                                                            ) : (
                                                                <span className="text-gray-400">-</span>
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

                {/* Narrative */}
                {latest?.narrative && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Clinical Narrative</CardTitle>
                            <CardDescription>
                                {formatPeriod(latest.period_start, latest.period_end)}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="prose prose-sm max-w-none text-gray-700 whitespace-pre-wrap">
                                {latest.narrative}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
