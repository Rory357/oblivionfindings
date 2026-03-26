import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

interface Props extends PageProps {
    committee: any;
    committeeType: string;
    risks: any[];
    metrics: any;
    generatedAt: string;
}

const getSeverityColor = (score: number) => {
    if (score >= 20) return 'bg-red-500 text-white';
    if (score >= 15) return 'bg-orange-500 text-white';
    if (score >= 10) return 'bg-yellow-500 text-black';
    return 'bg-green-500 text-white';
};

function StatCard({ label, value, color }: { label: string; value: number | string; color?: string }) {
    return (
        <div className="p-4 rounded-lg border bg-white">
            <p className="text-sm text-gray-500">{label}</p>
            <p className={cn('text-3xl font-bold', color)}>{value}</p>
        </div>
    );
}

function renderMetrics(committeeType: string, metrics: any) {
    if (!metrics) return <p className="text-gray-500 text-sm">No metrics available.</p>;

    switch (committeeType) {
        case 'audit_risk':
            return (
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <StatCard label="Audit Findings" value={metrics.audit_findings ?? 0} />
                    <StatCard label="Open Items" value={metrics.open_items ?? 0} color="text-orange-600" />
                    <StatCard label="Overdue Actions" value={metrics.overdue_actions ?? 0} color="text-red-600" />
                    <StatCard label="Compliance Rate" value={`${metrics.compliance_rate ?? 0}%`} color="text-green-600" />
                </div>
            );
        case 'people':
            return (
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <StatCard label="Headcount" value={metrics.headcount ?? 0} />
                    <StatCard label="Turnover" value={`${metrics.turnover ?? 0}%`} />
                    <StatCard label="Training Completion" value={`${metrics.training_completion ?? 0}%`} color="text-green-600" />
                    <StatCard label="Vacancies" value={metrics.vacancies ?? 0} color="text-amber-600" />
                </div>
            );
        case 'finance':
            return (
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <StatCard label="Budget Total" value={metrics.budget_total ?? '-'} />
                    <StatCard label="Actual Spend" value={metrics.actual_spend ?? '-'} />
                    <StatCard label="Variance" value={metrics.variance ?? '-'} />
                    <StatCard label="Cash Position" value={metrics.cash_position ?? '-'} />
                </div>
            );
        default:
            return (
                <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                    {Object.entries(metrics).map(([key, val]) => (
                        <StatCard key={key} label={key.replace(/_/g, ' ')} value={String(val)} />
                    ))}
                </div>
            );
    }
}

export default function CommitteeReport({ auth, committee, committeeType, risks, metrics, generatedAt }: Props) {
    const committeeName = committee?.name ?? committeeType.replace(/_/g, ' ').replace(/\b\w/g, (c: string) => c.toUpperCase());
    const sortedRisks = [...risks].sort((a, b) => (b.residual_score ?? 0) - (a.residual_score ?? 0));

    const formatDate = (d: string) =>
        new Date(d).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Reports', href: '/governance/reports' },
                { title: 'Committee', href: '#' },
            ]}
        >
            <Head title={`${committeeName} Report`} />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-3xl font-bold text-gray-900">{committeeName} Report</h1>
                    <p className="text-gray-500 mt-1">Committee performance and risk overview</p>
                </div>

                {/* Metrics */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>Key Metrics</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {renderMetrics(committeeType, metrics)}
                    </CardContent>
                </Card>

                {/* Risks */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>Assigned Risks</CardTitle>
                        <CardDescription>{sortedRisks.length} risks by residual score</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {sortedRisks.length === 0 ? (
                            <p className="text-center text-gray-500 py-8">No risks assigned to this committee.</p>
                        ) : (
                            <div className="space-y-2">
                                {sortedRisks.map((risk) => (
                                    <div key={risk.id} className="flex items-center justify-between p-3 rounded-lg border hover:bg-gray-50">
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium text-gray-900">{risk.title}</span>
                                                {risk.risk_reference && <Badge variant="outline">{risk.risk_reference}</Badge>}
                                            </div>
                                            <p className="text-xs text-gray-500 mt-1 capitalize">{risk.category?.replace(/_/g, ' ')}</p>
                                        </div>
                                        <Badge className={getSeverityColor(risk.residual_score ?? 0)}>
                                            {risk.residual_score ?? '-'}
                                        </Badge>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <p className="text-sm text-gray-400 text-right">
                    Generated: {formatDate(generatedAt)}
                </p>
            </div>
        </AppLayout>
    );
}
