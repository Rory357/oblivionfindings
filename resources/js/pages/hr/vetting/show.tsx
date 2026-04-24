import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Head, Link, router } from '@inertiajs/react';
import { Shield, User, Calendar, FileText, CheckCircle, AlertTriangle, Edit, Clock } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type StaffBackgroundCheck = {
    id: number;
    check_type: string;
    status: string;
    reference_number: string | null;
    check_date: string | null;
    issue_date: string | null;
    expires_at: string | null;
    verified_at: string | null;
    created_at: string | null;
    notes: string | null;
    disclosures_present: boolean;
    disclosure_details: string | null;
    risk_decision: string | null;
    risk_assessor?: { id: number; name: string } | null;
    user: {
        id: number;
        name: string;
        email?: string;
    };
    verified_by?: {
        id: number;
        name: string;
    } | null;
};

type Props = {
    check: StaffBackgroundCheck;
    can: {
        manage: boolean;
        viewDisclosures?: boolean;
    };
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

const getStatusColor = (status: string) => {
    switch (status) {
        case 'clear':
            return 'bg-status-success-bg text-status-success border-status-success/30';
        case 'pending':
        case 'requested':
            return 'bg-status-info-bg text-status-info border-status-info/30';
        case 'renewal_due':
            return 'bg-status-warning-bg text-status-warning border-status-warning/30';
        case 'flagged':
        case 'adverse':
            return 'bg-status-critical-bg text-status-critical border-status-critical/30';
        default:
            return 'bg-muted text-foreground border-border';
    }
};

const isExpired = (expiresAt: string | null) => {
    if (!expiresAt) return false;
    return new Date(expiresAt) < new Date();
};

const isExpiringSoon = (expiresAt: string | null) => {
    if (!expiresAt) return false;
    const d = new Date(expiresAt);
    const now = new Date();
    const thirtyDays = 30 * 24 * 60 * 60 * 1000;
    return d.getTime() - now.getTime() < thirtyDays && d.getTime() > now.getTime();
};

export default function VettingShow({ check, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Vetting', href: '/hr/compliance/vetting' },
        { title: `${check.user.name} - ${check.check_type.replace(/_/g, ' ')}`, href: `/hr/compliance/vetting/${check.id}` },
    ];

    const expired = isExpired(check.expires_at);
    const expiringSoon = isExpiringSoon(check.expires_at);
    const consentRecorded = typeof check.notes === 'string' && check.notes.includes('[Consent recorded');
    const canViewDisclosures = Boolean(can.viewDisclosures ?? can.manage);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Vetting: ${check.user.name}`} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-lg font-semibold">
                            <Shield className="h-5 w-5 text-muted-foreground" />
                            {check.check_type.replace(/_/g, ' ')}
                        </h1>
                        <div className="mt-1 text-sm text-muted-foreground">
                            {check.user.name}
                        </div>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge className={getStatusColor(check.status)}>
                                {check.status.replace(/_/g, ' ')}
                            </Badge>
                            {expired && (
                                <Badge className="bg-status-critical-bg text-status-critical border-status-critical/30">
                                    <AlertTriangle className="mr-1 h-3 w-3" />
                                    Expired
                                </Badge>
                            )}
                            {expiringSoon && !expired && (
                                <Badge className="bg-status-warning-bg text-status-warning border-status-warning/30">
                                    <Clock className="mr-1 h-3 w-3" />
                                    Expiring Soon
                                </Badge>
                            )}
                            {consentRecorded && (
                                <Badge variant="outline" className="border-status-success/30 bg-status-success-bg text-status-success">
                                    <CheckCircle className="mr-1 h-3 w-3" />
                                    Consent Recorded
                                </Badge>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href="/hr/compliance/vetting" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Back to list
                        </Link>
                        {can.manage && (
                            <Link href={`/hr/compliance/vetting/${check.id}/edit`}>
                                <Button size="sm" variant="outline">
                                    <Edit className="mr-1.5 h-4 w-4" />
                                    Edit
                                </Button>
                            </Link>
                        )}
                        {can.manage && (check.status === 'pending' || check.status === 'requested') && (
                            <Button
                                size="sm"
                                onClick={() => {
                                    if (confirm('Mark this check as cleared?')) {
                                        router.post(`/hr/compliance/vetting/${check.id}/clear`);
                                    }
                                }}
                            >
                                <CheckCircle className="mr-1.5 h-4 w-4" />
                                Mark Cleared
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <User className="h-5 w-5 text-status-info" />
                                Staff Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm">
                                <div className="text-xs text-muted-foreground">Name</div>
                                <div className="font-medium">{check.user.name}</div>
                            </div>
                            {check.user.email && (
                                <div className="text-sm">
                                    <div className="text-xs text-muted-foreground">Email</div>
                                    <div>{check.user.email}</div>
                                </div>
                            )}
                            <div className="text-sm">
                                <div className="text-xs text-muted-foreground">Verified By</div>
                                <div className="font-medium">{check.verified_by?.name || 'Not verified'}</div>
                            </div>
                            {check.risk_assessor?.name && (
                                <div className="text-sm">
                                    <div className="text-xs text-muted-foreground">Risk Assessor</div>
                                    <div className="font-medium">{check.risk_assessor.name}</div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FileText className="h-5 w-5 text-status-success" />
                                Check Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm">
                                <div className="text-xs text-muted-foreground">Check Type</div>
                                <div className="font-medium capitalize">{check.check_type.replace(/_/g, ' ')}</div>
                            </div>
                            <div className="text-sm">
                                <div className="text-xs text-muted-foreground">Reference Number</div>
                                <div className="font-medium">{check.reference_number || 'Not assigned'}</div>
                            </div>
                            <div className="text-sm">
                                <div className="text-xs text-muted-foreground">Status</div>
                                <div>
                                    <Badge className={getStatusColor(check.status)}>
                                        {check.status.replace(/_/g, ' ')}
                                    </Badge>
                                </div>
                            </div>
                            {check.risk_decision && (
                                <div className="text-sm">
                                    <div className="text-xs text-muted-foreground">Risk Decision</div>
                                    <div className="font-medium capitalize">{check.risk_decision.replace(/_/g, ' ')}</div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Calendar className="h-5 w-5 text-primary" />
                                Key Dates
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <div className="text-xs text-muted-foreground">Requested</div>
                                    <div>{formatDate(check.created_at)}</div>
                                </div>
                                <div>
                                    <div className="text-xs text-muted-foreground">Completed</div>
                                    <div>{formatDate(check.verified_at)}</div>
                                </div>
                                <div>
                                    <div className="text-xs text-muted-foreground">Issued</div>
                                    <div>{formatDate(check.issue_date || check.check_date)}</div>
                                </div>
                                <div>
                                    <div className="text-xs text-muted-foreground">Expires</div>
                                    <div className={
                                        expired
                                            ? 'font-semibold text-status-critical'
                                            : expiringSoon
                                                ? 'font-medium text-status-warning'
                                                : ''
                                    }>
                                        {formatDate(check.expires_at)}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Shield className="h-5 w-5 text-status-warning" />
                                Consent Information
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm">
                                <div className="text-xs text-muted-foreground">Consent Recorded</div>
                                <div className="font-medium">
                                    {consentRecorded ? (
                                        <span className="flex items-center gap-1 text-status-success">
                                            <CheckCircle className="h-4 w-4" /> Yes
                                        </span>
                                    ) : (
                                        <span className="text-status-critical">No</span>
                                    )}
                                </div>
                            </div>
                            <div className="text-xs text-muted-foreground">
                                Consent events are logged in notes.
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {check.notes && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-sm text-foreground whitespace-pre-wrap">{check.notes}</div>
                        </CardContent>
                    </Card>
                )}

                {canViewDisclosures && (check.disclosures_present || check.disclosure_details) && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AlertTriangle className="h-5 w-5 text-status-critical" />
                                Disclosure Information
                            </CardTitle>
                            <div className="text-xs text-status-critical">
                                Sensitive - access restricted
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm">
                                <div className="text-xs text-muted-foreground">Disclosures Present</div>
                                <div className="font-medium">{check.disclosures_present ? 'Yes' : 'No'}</div>
                            </div>
                            {check.disclosure_details && (
                                <div className="text-sm">
                                    <div className="text-xs text-muted-foreground">Disclosure Details</div>
                                    <div className="rounded-md border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-foreground whitespace-pre-wrap">
                                        {check.disclosure_details}
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {can.manage && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Actions</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            {expired && (
                                <Button
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    if (confirm('Request a renewal for this check?')) {
                                            router.post(`/hr/compliance/vetting/${check.id}/renew`);
                                    }
                                }}
                            >
                                    Request Renewal
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="outline"
                                className="text-status-critical border-status-critical/30 hover:bg-status-critical-bg"
                                onClick={() => {
                                    if (confirm('Are you sure you want to delete this vetting record?')) {
                                        router.delete(`/hr/compliance/vetting/${check.id}`);
                                    }
                                }}
                            >
                                Delete Record
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
