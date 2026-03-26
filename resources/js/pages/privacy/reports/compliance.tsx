import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Head, router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { FileText, Shield, AlertTriangle, Lock, Scale } from 'lucide-react';

type Props = {
    period: string;
    dsrStats: {
        total: number;
        completed: number;
        average_response_days: number;
        by_type: Record<string, number>;
    };
    breachStats: {
        total: number;
        resolved: number;
        ico_notifications: number;
    };
    dpiaStats: {
        total: number;
        approved: number;
        high_risk: number;
    };
    retentionStats: {
        total_policies: number;
        active_policies: number;
    };
    legalHoldStats: {
        total: number;
        active: number;
    };
};

function StatCard({ label, value, color }: { label: string; value: number | string; color?: string }) {
    return (
        <div className="p-4 rounded-lg border bg-white">
            <p className="text-sm text-slate-500">{label}</p>
            <p className={cn('text-2xl font-bold', color)}>{value}</p>
        </div>
    );
}

const periods = [
    { value: 'month', label: 'Month' },
    { value: 'quarter', label: 'Quarter' },
    { value: 'year', label: 'Year' },
];

export default function PrivacyComplianceReport({ period, dsrStats, breachStats, dpiaStats, retentionStats, legalHoldStats }: Props) {
    const switchPeriod = (p: string) => {
        router.get('/privacy/reports/compliance', { period: p }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Data & Privacy', href: '/privacy/dashboard' },
            { title: 'Reports', href: '#' },
            { title: 'Compliance', href: '/privacy/reports/compliance' },
        ]}>
            <Head title="Privacy Compliance Report" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Privacy Compliance Report</h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Comprehensive privacy metrics and compliance status
                        </p>
                    </div>
                    <div className="flex gap-1">
                        {periods.map((p) => (
                            <Button
                                key={p.value}
                                size="sm"
                                variant={period === p.value ? 'default' : 'outline'}
                                onClick={() => switchPeriod(p.value)}
                            >
                                {p.label}
                            </Button>
                        ))}
                    </div>
                </div>

                {/* DSR Stats */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <FileText className="h-5 w-5 text-blue-500" />
                            Data Subject Requests
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                            <StatCard label="Total DSRs" value={dsrStats.total} />
                            <StatCard label="Completed" value={dsrStats.completed} color="text-green-600" />
                            <StatCard label="Avg Response (days)" value={dsrStats.average_response_days} />
                            <StatCard label="Types" value={Object.keys(dsrStats.by_type).length} />
                        </div>
                        {Object.keys(dsrStats.by_type).length > 0 && (
                            <div className="mt-4 flex flex-wrap gap-2">
                                {Object.entries(dsrStats.by_type).map(([type, count]) => (
                                    <span key={type} className="inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs">
                                        <span className="capitalize">{type.replace(/_/g, ' ')}</span>
                                        <span className="font-bold">{count}</span>
                                    </span>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Breach Stats */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <AlertTriangle className="h-5 w-5 text-red-500" />
                            Breach Statistics
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">
                            <StatCard label="Total Breaches" value={breachStats.total} />
                            <StatCard label="Resolved" value={breachStats.resolved} color="text-green-600" />
                            <StatCard label="ICO Notifications" value={breachStats.ico_notifications} color="text-red-600" />
                        </div>
                    </CardContent>
                </Card>

                {/* DPIA Stats */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Shield className="h-5 w-5 text-indigo-500" />
                            Data Protection Impact Assessments
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">
                            <StatCard label="Total DPIAs" value={dpiaStats.total} />
                            <StatCard label="Approved" value={dpiaStats.approved} color="text-green-600" />
                            <StatCard label="High Risk" value={dpiaStats.high_risk} color="text-red-600" />
                        </div>
                    </CardContent>
                </Card>

                {/* Retention Stats */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Lock className="h-5 w-5 text-amber-500" />
                            Retention Policies
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-4">
                            <StatCard label="Total Policies" value={retentionStats.total_policies} />
                            <StatCard label="Active Policies" value={retentionStats.active_policies} color="text-green-600" />
                        </div>
                    </CardContent>
                </Card>

                {/* Legal Hold Stats */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Scale className="h-5 w-5 text-purple-500" />
                            Legal Holds
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-4">
                            <StatCard label="Total Holds" value={legalHoldStats.total} />
                            <StatCard label="Active Holds" value={legalHoldStats.active} color="text-orange-600" />
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
