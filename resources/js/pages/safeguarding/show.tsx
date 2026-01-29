import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Shield, User, MapPin, FileText } from 'lucide-react';

type Concern = {
    id: number;
    reference_number: string;
    severity: string;
    status: string;
    concern_type: string;
    abuse_category?: string | null;
    description: string;
    reported_at?: string | null;
    occurred_at?: string | null;
    location?: string | null;
    subject_informed?: boolean | null;
    requires_external_referral?: boolean | null;
    reportedBy?: { name: string } | null;
    assignedTo?: { name: string } | null;
    closedBy?: { name: string } | null;
    site?: { name: string } | null;
    subject?: { first_name?: string; last_name?: string; name?: string } | null;
    subject_name?: string | null;
    allegedPerpetrator?: { name?: string; first_name?: string; last_name?: string } | null;
    alleged_perpetrator_name?: string | null;
    immediate_actions?: string | null;
    closure_summary?: string | null;
    lessons_learned?: string | null;
    investigations?: Array<{ id: number; status?: string | null; started_at?: string | null }>;
    externalReports?: Array<{ id: number; authority_name?: string | null; reported_at?: string | null }>;
    riskAssessments?: Array<{ id: number; risk_level?: string | null; assessed_at?: string | null }>;
};

type Props = {
    concern: Concern;
    canUpdate: boolean;
    canInvestigate: boolean;
    canReportExternal: boolean;
};

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
};

const getSeverityColor = (severity: string) => {
    switch (severity) {
        case 'critical':
            return 'bg-red-100 text-red-800 border-red-200';
        case 'high':
            return 'bg-orange-100 text-orange-800 border-orange-200';
        case 'medium':
            return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'low':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        default:
            return 'bg-slate-100 text-slate-800 border-slate-200';
    }
};

const getStatusColor = (status: string) => {
    switch (status) {
        case 'closed':
            return 'bg-green-100 text-green-800 border-green-200';
        case 'investigating':
            return 'bg-purple-100 text-purple-800 border-purple-200';
        case 'triaged':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'reported':
            return 'bg-slate-100 text-slate-800 border-slate-200';
        default:
            return 'bg-slate-100 text-slate-800 border-slate-200';
    }
};

const displayName = (value?: { first_name?: string; last_name?: string; name?: string } | null, fallback?: string | null) => {
    if (value?.name) return value.name;
    const first = value?.first_name ?? '';
    const last = value?.last_name ?? '';
    const combined = `${first} ${last}`.trim();
    return combined || fallback || 'Unknown';
};

export default function SafeguardingShow({ concern, canUpdate, canInvestigate, canReportExternal }: Props) {
    const subjectName = displayName(concern.subject, concern.subject_name);
    const perpName = displayName(concern.allegedPerpetrator, concern.alleged_perpetrator_name);

    return (
        <AppLayout breadcrumbs={[
            { title: 'Safeguarding', href: '/safeguarding' },
            { title: concern.reference_number, href: `/safeguarding/${concern.id}` },
        ]}>
            <Head title={`Safeguarding ${concern.reference_number}`} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            {concern.severity === 'critical' && <AlertTriangle className="h-5 w-5 text-red-500" />}
                            {concern.reference_number}
                        </h1>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge className={getSeverityColor(concern.severity)}>{concern.severity}</Badge>
                            <Badge className={getStatusColor(concern.status)}>{concern.status.replace(/_/g, ' ')}</Badge>
                            {concern.requires_external_referral && (
                                <Badge variant="outline" className="border-purple-200 bg-purple-50 text-purple-700">
                                    External Referral Required
                                </Badge>
                            )}
                            {concern.subject_informed === false && (
                                <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-700">
                                    Subject Not Informed
                                </Badge>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href="/safeguarding" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Back to list
                        </Link>
                        {canUpdate && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => router.post(`/safeguarding/${concern.id}/mark-subject-informed`)}
                            >
                                Mark Subject Informed
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Shield className="h-5 w-5 text-purple-500" />
                                Concern Summary
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-sm text-slate-600">
                                {concern.concern_type.replace(/_/g, ' ')}
                                {concern.abuse_category ? ` - ${concern.abuse_category}` : ''}
                            </div>
                            <div className="text-sm text-slate-700 whitespace-pre-wrap">{concern.description}</div>
                            {concern.immediate_actions && (
                                <div className="text-sm text-slate-600">
                                    Immediate actions: {concern.immediate_actions}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <User className="h-5 w-5 text-blue-500" />
                                People
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Subject</div>
                                <div className="font-medium">{subjectName}</div>
                            </div>
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Alleged perpetrator</div>
                                <div className="font-medium">{perpName || 'Unknown'}</div>
                            </div>
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Reported by</div>
                                <div className="font-medium">{concern.reportedBy?.name || 'Unknown'}</div>
                            </div>
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Assigned to</div>
                                <div className="font-medium">{concern.assignedTo?.name || 'Unassigned'}</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <MapPin className="h-5 w-5 text-green-500" />
                                Location & Dates
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm text-slate-600">
                            <div>Occurred: {formatDate(concern.occurred_at)}</div>
                            <div>Reported: {formatDate(concern.reported_at)}</div>
                            <div>Location: {concern.location || 'Not recorded'}</div>
                            <div>Site: {concern.site?.name || 'Not set'}</div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FileText className="h-5 w-5 text-amber-500" />
                                Follow-ups
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm text-slate-600">
                            <div>Investigations: {concern.investigations?.length ?? 0}</div>
                            <div>External reports: {concern.externalReports?.length ?? 0}</div>
                            <div>Risk assessments: {concern.riskAssessments?.length ?? 0}</div>
                        </CardContent>
                    </Card>
                </div>

                {(canInvestigate || canReportExternal) && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Actions</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            {canInvestigate && (
                                <Button size="sm" variant="outline" disabled>
                                    Start Investigation (coming soon)
                                </Button>
                            )}
                            {canReportExternal && (
                                <Button size="sm" variant="outline" disabled>
                                    Log External Report (coming soon)
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
