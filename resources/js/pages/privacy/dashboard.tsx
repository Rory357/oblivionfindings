import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Head, Link } from '@inertiajs/react';
import { Shield, AlertTriangle, Clock, FileText, Lock, Scale, Activity } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

type Props = {
    dsrStats: {
        total: number;
        pending: number;
        overdue: number;
        completed_this_month: number;
    };
    recentRequests: any[];
    breachStats: {
        total: number;
        open: number;
        requiring_notification: number;
    };
    activeHolds: number;
    retentionStats: {
        total_policies: number;
        active_policies: number;
    };
    dpiaStats: {
        total: number;
        pending_review: number;
        high_risk: number;
    };
};

export default function PrivacyDashboard({
    dsrStats,
    recentRequests,
    breachStats,
    activeHolds,
    retentionStats,
    dpiaStats,
}: Props) {
    const getStatusColor = (status: string) => {
        switch (status) {
            case 'completed':
                return 'bg-green-100 text-green-800';
            case 'in_progress':
                return 'bg-blue-100 text-blue-800';
            case 'received':
            case 'under_review':
                return 'bg-yellow-100 text-yellow-800';
            case 'rejected':
                return 'bg-red-100 text-red-800';
            default:
                return 'bg-slate-100 text-slate-800';
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Privacy & GDPR', href: '/privacy/dashboard' }]}>
            <Head title="Privacy & GDPR Dashboard" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <FleetHero
                    title="Privacy & GDPR Dashboard"
                    description="Data protection compliance overview and management"
                    icon={<Shield className="h-7 w-7 text-white" />}
                    stats={[
                        { label: 'Pending DSRs', value: dsrStats.pending },
                        { label: 'Open Breaches', value: breachStats.open },
                        { label: 'Active Holds', value: activeHolds },
                        { label: 'Pending DPIAs', value: dpiaStats.pending_review },
                    ]}
                />

                {/* Quick Links */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Link href="/privacy/requests" className="block">
                        <Card className="transition-shadow hover:shadow-md">
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                    <FileText className="h-4 w-4 text-blue-500" />
                                    Data Subject Requests
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{dsrStats.pending}</div>
                                <p className="text-xs text-slate-500">pending requests</p>
                                {dsrStats.overdue > 0 && (
                                    <div className="mt-2 flex items-center gap-1 text-xs text-red-600">
                                        <AlertTriangle className="h-3 w-3" />
                                        {dsrStats.overdue} overdue
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </Link>

                    <Link href="/privacy/breaches" className="block">
                        <Card className="transition-shadow hover:shadow-md">
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                    <AlertTriangle className="h-4 w-4 text-red-500" />
                                    Data Breaches
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{breachStats.open}</div>
                                <p className="text-xs text-slate-500">open incidents</p>
                                {breachStats.requiring_notification > 0 && (
                                    <div className="mt-2 flex items-center gap-1 text-xs text-orange-600">
                                        <Clock className="h-3 w-3" />
                                        {breachStats.requiring_notification} requiring ICO notification
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </Link>

                    <Link href="/privacy/legal-holds" className="block">
                        <Card className="transition-shadow hover:shadow-md">
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                    <Scale className="h-4 w-4 text-purple-500" />
                                    Legal Holds
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{activeHolds}</div>
                                <p className="text-xs text-slate-500">active holds</p>
                            </CardContent>
                        </Card>
                    </Link>

                    <Link href="/privacy/dpia" className="block">
                        <Card className="transition-shadow hover:shadow-md">
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                    <Activity className="h-4 w-4 text-green-500" />
                                    Impact Assessments
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{dpiaStats.pending_review}</div>
                                <p className="text-xs text-slate-500">pending review</p>
                                {dpiaStats.high_risk > 0 && (
                                    <div className="mt-2 flex items-center gap-1 text-xs text-red-600">
                                        <AlertTriangle className="h-3 w-3" />
                                        {dpiaStats.high_risk} high risk
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </Link>
                </div>

                {/* Stats Overview */}
                <div className="grid gap-6 lg:grid-cols-2">
                    {/* DSR Stats */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Shield className="h-5 w-5 text-blue-500" />
                                Data Subject Request Statistics
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="rounded-lg bg-slate-50 p-4">
                                    <div className="text-2xl font-bold">{dsrStats.total}</div>
                                    <p className="text-xs text-slate-500">Total Requests</p>
                                </div>
                                <div className="rounded-lg bg-yellow-50 p-4">
                                    <div className="text-2xl font-bold text-yellow-700">{dsrStats.pending}</div>
                                    <p className="text-xs text-slate-500">Pending</p>
                                </div>
                                <div className="rounded-lg bg-red-50 p-4">
                                    <div className="text-2xl font-bold text-red-700">{dsrStats.overdue}</div>
                                    <p className="text-xs text-slate-500">Overdue</p>
                                </div>
                                <div className="rounded-lg bg-green-50 p-4">
                                    <div className="text-2xl font-bold text-green-700">{dsrStats.completed_this_month}</div>
                                    <p className="text-xs text-slate-500">Completed This Month</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Recent Requests */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between text-base">
                                <span className="flex items-center gap-2">
                                    <FileText className="h-5 w-5 text-blue-500" />
                                    Recent Requests
                                </span>
                                <Link href="/privacy/requests" className="text-xs text-blue-600 hover:underline">
                                    View All
                                </Link>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                {recentRequests.length > 0 ? (
                                    recentRequests.map((request) => (
                                        <Link
                                            key={request.id}
                                            href={`/privacy/requests/${request.id}`}
                                            className="block rounded-lg border p-3 transition-colors hover:bg-slate-50"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <div className="font-medium text-sm">{request.reference_number}</div>
                                                    <div className="text-xs text-slate-500 mt-1">
                                                        {request.request_type?.replace(/_/g, ' ')}
                                                    </div>
                                                </div>
                                                <Badge className={getStatusColor(request.status)}>
                                                    {request.status?.replace(/_/g, ' ')}
                                                </Badge>
                                            </div>
                                        </Link>
                                    ))
                                ) : (
                                    <div className="text-center text-sm text-slate-500 py-4">
                                        No recent requests
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Retention & Compliance */}
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between text-base">
                                <span className="flex items-center gap-2">
                                    <Lock className="h-5 w-5 text-purple-500" />
                                    Data Retention Policies
                                </span>
                                <Link href="/privacy/retention" className="text-xs text-blue-600 hover:underline">
                                    Manage
                                </Link>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="rounded-lg bg-slate-50 p-4">
                                    <div className="text-2xl font-bold">{retentionStats.total_policies}</div>
                                    <p className="text-xs text-slate-500">Total Policies</p>
                                </div>
                                <div className="rounded-lg bg-green-50 p-4">
                                    <div className="text-2xl font-bold text-green-700">{retentionStats.active_policies}</div>
                                    <p className="text-xs text-slate-500">Active</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Activity className="h-5 w-5 text-green-500" />
                                Compliance Overview
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                <div className="flex items-center justify-between rounded-lg bg-slate-50 p-3">
                                    <span className="text-sm">Privacy Impact Assessments</span>
                                    <span className="font-semibold">{dpiaStats.total}</span>
                                </div>
                                <div className="flex items-center justify-between rounded-lg bg-slate-50 p-3">
                                    <span className="text-sm">Data Breach Records</span>
                                    <span className="font-semibold">{breachStats.total}</span>
                                </div>
                                <div className="flex items-center justify-between rounded-lg bg-slate-50 p-3">
                                    <span className="text-sm">Active Legal Holds</span>
                                    <span className="font-semibold">{activeHolds}</span>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
