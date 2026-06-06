import AppLayout from '@/layouts/app-layout';
import { PageHero } from '@/components/page';
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
                return 'bg-status-success-bg text-status-success';
            case 'in_progress':
                return 'bg-status-info-bg text-status-info';
            case 'received':
            case 'under_review':
                return 'bg-status-warning-bg text-status-warning';
            case 'rejected':
                return 'bg-status-critical-bg text-status-critical';
            default:
                return 'bg-muted text-foreground';
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Privacy', href: '/privacy/dashboard' }]}>
            <Head title="Privacy Dashboard" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <PageHero
                    title="Privacy Dashboard"
                    description="Data protection compliance overview and management"
                    icon={<Shield className="h-7 w-7 text-white" />}
                    stats={[
                        { label: 'Pending DSRs', value: dsrStats.pending },
                        { label: 'Open Breaches', value: breachStats.open },
                        { label: 'Active Holds', value: activeHolds },
                        { label: 'Pending PIAs', value: dpiaStats.pending_review },
                    ]}
                />

                {/* Quick Links */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Link href="/privacy/requests" className="block">
                        <Card className="transition-shadow hover:shadow-md">
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                    <FileText className="h-4 w-4 text-status-info" />
                                    Privacy Requests
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{dsrStats.pending}</div>
                                <p className="text-xs text-muted-foreground">pending requests</p>
                                {dsrStats.overdue > 0 && (
                                    <div className="mt-2 flex items-center gap-1 text-xs text-status-critical">
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
                                    <AlertTriangle className="h-4 w-4 text-status-critical" />
                                    Data Breaches
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{breachStats.open}</div>
                                <p className="text-xs text-muted-foreground">open incidents</p>
                                {breachStats.requiring_notification > 0 && (
                                    <div className="mt-2 flex items-center gap-1 text-xs text-status-warning">
                                        <Clock className="h-3 w-3" />
                                        {breachStats.requiring_notification} requiring OPC notification
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </Link>

                    <Link href="/privacy/legal-holds" className="block">
                        <Card className="transition-shadow hover:shadow-md">
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                    <Scale className="h-4 w-4 text-primary" />
                                    Legal Holds
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{activeHolds}</div>
                                <p className="text-xs text-muted-foreground">active holds</p>
                            </CardContent>
                        </Card>
                    </Link>

                    <Link href="/privacy/pia" className="block">
                        <Card className="transition-shadow hover:shadow-md">
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                    <Activity className="h-4 w-4 text-status-success" />
                                    Impact Assessments
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{dpiaStats.pending_review}</div>
                                <p className="text-xs text-muted-foreground">pending review</p>
                                {dpiaStats.high_risk > 0 && (
                                    <div className="mt-2 flex items-center gap-1 text-xs text-status-critical">
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
                                <Shield className="h-5 w-5 text-status-info" />
                                Privacy Request Statistics
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="rounded-lg bg-muted p-4">
                                    <div className="text-2xl font-bold">{dsrStats.total}</div>
                                    <p className="text-xs text-muted-foreground">Total Requests</p>
                                </div>
                                <div className="rounded-lg bg-status-warning-bg p-4">
                                    <div className="text-2xl font-bold text-status-warning">{dsrStats.pending}</div>
                                    <p className="text-xs text-muted-foreground">Pending</p>
                                </div>
                                <div className="rounded-lg bg-status-critical-bg p-4">
                                    <div className="text-2xl font-bold text-status-critical">{dsrStats.overdue}</div>
                                    <p className="text-xs text-muted-foreground">Overdue</p>
                                </div>
                                <div className="rounded-lg bg-status-success-bg p-4">
                                    <div className="text-2xl font-bold text-status-success">{dsrStats.completed_this_month}</div>
                                    <p className="text-xs text-muted-foreground">Completed This Month</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Recent Requests */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between text-base">
                                <span className="flex items-center gap-2">
                                    <FileText className="h-5 w-5 text-status-info" />
                                    Recent Requests
                                </span>
                                <Link href="/privacy/requests" className="text-xs text-status-info hover:underline">
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
                                            className="block rounded-lg border p-3 transition-colors hover:bg-muted"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <div className="font-medium text-sm">{request.reference_number}</div>
                                                    <div className="text-xs text-muted-foreground mt-1">
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
                                    <div className="text-center text-sm text-muted-foreground py-4">
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
                                    <Lock className="h-5 w-5 text-primary" />
                                    Data Retention Policies
                                </span>
                                <Link href="/privacy/retention" className="text-xs text-status-info hover:underline">
                                    Manage
                                </Link>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="rounded-lg bg-muted p-4">
                                    <div className="text-2xl font-bold">{retentionStats.total_policies}</div>
                                    <p className="text-xs text-muted-foreground">Total Policies</p>
                                </div>
                                <div className="rounded-lg bg-status-success-bg p-4">
                                    <div className="text-2xl font-bold text-status-success">{retentionStats.active_policies}</div>
                                    <p className="text-xs text-muted-foreground">Active</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Activity className="h-5 w-5 text-status-success" />
                                Compliance Overview
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                <div className="flex items-center justify-between rounded-lg bg-muted p-3">
                                    <span className="text-sm">Privacy Impact Assessments</span>
                                    <span className="font-semibold">{dpiaStats.total}</span>
                                </div>
                                <div className="flex items-center justify-between rounded-lg bg-muted p-3">
                                    <span className="text-sm">Data Breach Records</span>
                                    <span className="font-semibold">{breachStats.total}</span>
                                </div>
                                <div className="flex items-center justify-between rounded-lg bg-muted p-3">
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
