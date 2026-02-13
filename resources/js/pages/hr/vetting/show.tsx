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
    issued_at: string | null;
    expires_at: string | null;
    requested_at: string | null;
    completed_at: string | null;
    consent_given: boolean;
    consent_date: string | null;
    notes: string | null;
    disclosure_level: string | null;
    disclosure_details: string | null;
    user: {
        id: number;
        name: string;
        email?: string;
    };
    verifiedBy: {
        id: number;
        name: string;
    } | null;
};

type Props = {
    check: StaffBackgroundCheck;
    can: {
        manage: boolean;
        viewDisclosures: boolean;
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

const formatDateTime = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const getStatusColor = (status: string) => {
    switch (status) {
        case 'current':
        case 'cleared':
            return 'bg-green-100 text-green-800 border-green-200';
        case 'pending':
        case 'in_progress':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'expiring':
            return 'bg-amber-100 text-amber-800 border-amber-200';
        case 'expired':
            return 'bg-red-100 text-red-800 border-red-200';
        case 'not_started':
            return 'bg-slate-100 text-slate-800 border-slate-200';
        case 'failed':
            return 'bg-red-100 text-red-800 border-red-200';
        default:
            return 'bg-slate-100 text-slate-800 border-slate-200';
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
        { title: 'Vetting', href: '/hr/vetting' },
        { title: `${check.user.name} - ${check.check_type.replace(/_/g, ' ')}`, href: `/hr/vetting/${check.id}` },
    ];

    const expired = isExpired(check.expires_at);
    const expiringSoon = isExpiringSoon(check.expires_at);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Vetting: ${check.user.name}`} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-lg font-semibold">
                            <Shield className="h-5 w-5 text-slate-500" />
                            {check.check_type.replace(/_/g, ' ')}
                        </h1>
                        <div className="mt-1 text-sm text-slate-500">
                            {check.user.name}
                        </div>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge className={getStatusColor(check.status)}>
                                {check.status.replace(/_/g, ' ')}
                            </Badge>
                            {expired && (
                                <Badge className="bg-red-100 text-red-800 border-red-200">
                                    <AlertTriangle className="mr-1 h-3 w-3" />
                                    Expired
                                </Badge>
                            )}
                            {expiringSoon && !expired && (
                                <Badge className="bg-amber-100 text-amber-800 border-amber-200">
                                    <Clock className="mr-1 h-3 w-3" />
                                    Expiring Soon
                                </Badge>
                            )}
                            {check.consent_given && (
                                <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700">
                                    <CheckCircle className="mr-1 h-3 w-3" />
                                    Consent Given
                                </Badge>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href="/hr/vetting" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Back to list
                        </Link>
                        {can.manage && (
                            <Link href={`/hr/vetting/${check.id}/edit`}>
                                <Button size="sm" variant="outline">
                                    <Edit className="mr-1.5 h-4 w-4" />
                                    Edit
                                </Button>
                            </Link>
                        )}
                        {can.manage && (check.status === 'pending' || check.status === 'in_progress') && (
                            <Button
                                size="sm"
                                onClick={() => {
                                    if (confirm('Mark this check as cleared?')) {
                                        router.post(`/hr/vetting/${check.id}/clear`);
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
                                <User className="h-5 w-5 text-blue-500" />
                                Staff Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Name</div>
                                <div className="font-medium">{check.user.name}</div>
                            </div>
                            {check.user.email && (
                                <div className="text-sm">
                                    <div className="text-xs text-slate-500">Email</div>
                                    <div>{check.user.email}</div>
                                </div>
                            )}
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Verified By</div>
                                <div className="font-medium">{check.verifiedBy?.name || 'Not verified'}</div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FileText className="h-5 w-5 text-green-500" />
                                Check Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Check Type</div>
                                <div className="font-medium capitalize">{check.check_type.replace(/_/g, ' ')}</div>
                            </div>
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Reference Number</div>
                                <div className="font-medium">{check.reference_number || 'Not assigned'}</div>
                            </div>
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Status</div>
                                <div>
                                    <Badge className={getStatusColor(check.status)}>
                                        {check.status.replace(/_/g, ' ')}
                                    </Badge>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Calendar className="h-5 w-5 text-purple-500" />
                                Key Dates
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <div className="text-xs text-slate-500">Requested</div>
                                    <div>{formatDate(check.requested_at)}</div>
                                </div>
                                <div>
                                    <div className="text-xs text-slate-500">Completed</div>
                                    <div>{formatDate(check.completed_at)}</div>
                                </div>
                                <div>
                                    <div className="text-xs text-slate-500">Issued</div>
                                    <div>{formatDate(check.issued_at)}</div>
                                </div>
                                <div>
                                    <div className="text-xs text-slate-500">Expires</div>
                                    <div className={
                                        expired
                                            ? 'font-semibold text-red-600'
                                            : expiringSoon
                                                ? 'font-medium text-amber-600'
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
                                <Shield className="h-5 w-5 text-amber-500" />
                                Consent Information
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Consent Given</div>
                                <div className="font-medium">
                                    {check.consent_given ? (
                                        <span className="flex items-center gap-1 text-green-600">
                                            <CheckCircle className="h-4 w-4" /> Yes
                                        </span>
                                    ) : (
                                        <span className="text-red-600">No</span>
                                    )}
                                </div>
                            </div>
                            {check.consent_date && (
                                <div className="text-sm">
                                    <div className="text-xs text-slate-500">Consent Date</div>
                                    <div>{formatDate(check.consent_date)}</div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {check.notes && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-sm text-slate-700 whitespace-pre-wrap">{check.notes}</div>
                        </CardContent>
                    </Card>
                )}

                {can.viewDisclosures && (check.disclosure_level || check.disclosure_details) && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AlertTriangle className="h-5 w-5 text-red-500" />
                                Disclosure Information
                            </CardTitle>
                            <div className="text-xs text-red-500">
                                Sensitive - access restricted
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {check.disclosure_level && (
                                <div className="text-sm">
                                    <div className="text-xs text-slate-500">Disclosure Level</div>
                                    <div className="font-medium capitalize">{check.disclosure_level.replace(/_/g, ' ')}</div>
                                </div>
                            )}
                            {check.disclosure_details && (
                                <div className="text-sm">
                                    <div className="text-xs text-slate-500">Disclosure Details</div>
                                    <div className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-slate-700 whitespace-pre-wrap">
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
                            {check.status === 'expired' && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        if (confirm('Request a renewal for this check?')) {
                                            router.post(`/hr/vetting/${check.id}/renew`);
                                        }
                                    }}
                                >
                                    Request Renewal
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="outline"
                                className="text-red-600 border-red-200 hover:bg-red-50"
                                onClick={() => {
                                    if (confirm('Are you sure you want to delete this vetting record?')) {
                                        router.delete(`/hr/vetting/${check.id}`);
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
