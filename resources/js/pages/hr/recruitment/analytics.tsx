import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { BarChart3, TrendingUp, Filter, Briefcase } from 'lucide-react';

type TimeToHireEntry = { month: string; avg_days: number; count: number };
type SourceEntry = { source: string; total: number; hired: number; active: number; conversion_rate: number };
type PipelineEntry = { stage: string; count: number; percentage: number };
type PositionEntry = { position_title: string; applications: number; days_open: number };

type Props = {
    timeToHire: TimeToHireEntry[];
    sourceEffectiveness: SourceEntry[];
    pipelineConversion: PipelineEntry[];
    openPositions: PositionEntry[];
};

const stageLabels: Record<string, string> = {
    new: 'New',
    screening: 'Screening',
    interview_scheduled: 'Interview Scheduled',
    interview_completed: 'Interview Completed',
    reference_check: 'Reference Check',
    offer_pending: 'Offer Pending',
    offer_sent: 'Offer Sent',
    offer_accepted: 'Offer Accepted',
    hired: 'Hired',
    withdrawn: 'Withdrawn',
    rejected: 'Rejected',
};

export default function RecruitmentAnalytics({ timeToHire, sourceEffectiveness, pipelineConversion, openPositions }: Props) {
    const maxDays = Math.max(...timeToHire.map((t) => t.avg_days), 1);
    const maxSourceTotal = Math.max(...sourceEffectiveness.map((s) => s.total), 1);
    const maxPipelineCount = Math.max(...pipelineConversion.map((p) => p.count), 1);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Recruitment', href: '/hr/recruitment' },
                { title: 'Analytics', href: '/hr/recruitment/analytics' },
            ]}
        >
            <Head title="Recruitment Analytics" />
            <PageShell>
                <PageHeader title="Recruitment Analytics" description="Insights into your recruitment pipeline performance." />

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Time to Hire */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <TrendingUp className="h-5 w-5" /> Time to Hire
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {timeToHire.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No hire data available yet.</p>
                            ) : (
                                <div className="space-y-2">
                                    {timeToHire.map((entry) => (
                                        <div key={entry.month} className="flex items-center gap-3">
                                            <span className="text-xs text-muted-foreground w-16">{entry.month}</span>
                                            <div className="flex-1 bg-muted/30 rounded-full h-6 overflow-hidden">
                                                <div
                                                    className="bg-blue-500/60 h-full rounded-full flex items-center px-2"
                                                    style={{ width: `${Math.max((entry.avg_days / maxDays) * 100, 5)}%` }}
                                                >
                                                    <span className="text-xs font-medium">{entry.avg_days}d</span>
                                                </div>
                                            </div>
                                            <span className="text-xs text-muted-foreground">{entry.count} hires</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Source Effectiveness */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <BarChart3 className="h-5 w-5" /> Source Effectiveness
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {sourceEffectiveness.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No source data available yet.</p>
                            ) : (
                                <div className="space-y-3">
                                    {sourceEffectiveness.map((source) => (
                                        <div key={source.source} className="space-y-1">
                                            <div className="flex items-center justify-between">
                                                <span className="text-sm font-medium capitalize">{source.source.replace(/_/g, ' ')}</span>
                                                <span className="text-xs text-muted-foreground">{source.conversion_rate}% conversion</span>
                                            </div>
                                            <div className="flex-1 bg-muted/30 rounded-full h-4 overflow-hidden">
                                                <div
                                                    className="bg-emerald-500/60 h-full rounded-full"
                                                    style={{ width: `${(source.total / maxSourceTotal) * 100}%` }}
                                                />
                                            </div>
                                            <div className="flex gap-2 text-xs text-muted-foreground">
                                                <span>{source.total} total</span>
                                                <span>{source.hired} hired</span>
                                                <span>{source.active} active</span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Pipeline Conversion Funnel */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Filter className="h-5 w-5" /> Pipeline Funnel
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {pipelineConversion.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No pipeline data available.</p>
                            ) : (
                                <div className="space-y-2">
                                    {pipelineConversion.map((entry) => (
                                        <div key={entry.stage} className="flex items-center gap-3">
                                            <span className="text-xs w-32 truncate">{stageLabels[entry.stage] ?? entry.stage}</span>
                                            <div className="flex-1 bg-muted/30 rounded-full h-5 overflow-hidden">
                                                <div
                                                    className="bg-indigo-500/60 h-full rounded-full flex items-center px-2"
                                                    style={{ width: `${Math.max((entry.count / maxPipelineCount) * 100, 3)}%` }}
                                                >
                                                    <span className="text-xs">{entry.count}</span>
                                                </div>
                                            </div>
                                            <span className="text-xs text-muted-foreground w-12 text-right">{entry.percentage}%</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Open Positions */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Briefcase className="h-5 w-5" /> Open Positions
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {openPositions.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No open positions.</p>
                            ) : (
                                <div className="overflow-hidden rounded-lg border">
                                    <table className="w-full text-sm">
                                        <thead className="bg-muted/50">
                                            <tr>
                                                <th className="px-3 py-2 text-left font-medium">Position</th>
                                                <th className="px-3 py-2 text-right font-medium">Apps</th>
                                                <th className="px-3 py-2 text-right font-medium">Days Open</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {openPositions.map((pos, i) => (
                                                <tr key={i} className="border-t">
                                                    <td className="px-3 py-2">{pos.position_title}</td>
                                                    <td className="px-3 py-2 text-right">
                                                        <Badge variant="secondary">{pos.applications}</Badge>
                                                    </td>
                                                    <td className="px-3 py-2 text-right text-muted-foreground">{pos.days_open}d</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
