import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CheckCircle,
    Clock,
    FileText,
    User,
} from 'lucide-react';

type Props = {
    request: any;
    staff: Array<{ id: number; name: string }>;
};

export default function ShowDataSubjectRequest({ request: dsr, staff }: Props) {
    const isIdentityVerified =
        dsr.identity_verified === 'verified' ||
        dsr.identity_verified === true ||
        dsr.identity_verified === 1 ||
        dsr.identity_verified === '1';

    const statusLabels: Record<string, string> = {
        received: 'received',
        under_review: 'under review',
        identity_verification: 'pending verification',
        in_progress: 'in progress',
        completed: 'completed',
        rejected: 'rejected',
        withdrawn: 'withdrawn',
    };

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'completed':
                return 'bg-status-success-bg text-status-success';
            case 'in_progress':
                return 'bg-status-info-bg text-status-info';
            case 'received':
            case 'under_review':
            case 'identity_verification':
                return 'bg-status-warning-bg text-status-warning';
            case 'rejected':
                return 'bg-status-critical-bg text-status-critical';
            default:
                return 'bg-muted text-foreground';
        }
    };

    const getRequestTypeLabel = (type: string) => {
        const labels: Record<string, string> = {
            access: 'Right to Access (Art. 15)',
            rectification: 'Right to Rectification (Art. 16)',
            erasure: 'Right to Erasure (Art. 17)',
            restriction: 'Right to Restriction (Art. 18)',
            portability: 'Right to Portability (Art. 20)',
            objection: 'Right to Object (Art. 21)',
            automated_decision: 'Automated Decision Rights (Art. 22)',
        };
        return labels[type] || type;
    };

    const formatDate = (dateString: string) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        });
    };

    const getDaysRemaining = () => {
        const due = new Date(dsr.extended_due_date || dsr.due_date);
        const today = new Date();
        return Math.ceil(
            (due.getTime() - today.getTime()) / (1000 * 60 * 60 * 24),
        );
    };

    const daysRemaining = getDaysRemaining();
    const isOverdue = daysRemaining < 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
                { title: 'Data Subject Requests', href: '/privacy/requests' },
                {
                    title: dsr.reference_number,
                    href: `/privacy/requests/${dsr.id}`,
                },
            ]}
        >
            <Head title={`DSR ${dsr.reference_number}`} />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/privacy/requests"
                        backLabel="Back to List"
                        title={dsr.reference_number}
                        description={getRequestTypeLabel(dsr.request_type)}
                    >
                        <div className="flex flex-wrap gap-2" data-test="privacy-dsr-show">
                            <Badge
                                className={getStatusColor(dsr.status)}
                                data-test="privacy-dsr-status"
                            >
                                {statusLabels[dsr.status] ??
                                    dsr.status?.replace(/_/g, ' ')}
                            </Badge>
                            <Badge
                                variant="outline"
                                className="border-status-info/30 bg-status-info-bg text-status-info"
                            >
                                {getRequestTypeLabel(dsr.request_type)}
                            </Badge>
                            {isOverdue && (
                                <Badge
                                    variant="outline"
                                    className="border-status-critical/30 bg-status-critical-bg text-status-critical"
                                >
                                    <AlertTriangle className="mr-1 h-3 w-3" />
                                    {Math.abs(daysRemaining)} days overdue
                                </Badge>
                            )}
                            {!isOverdue && daysRemaining <= 7 && (
                                <Badge
                                    variant="outline"
                                    className="border-status-warning/30 bg-status-warning-bg text-status-warning"
                                >
                                    <Clock className="mr-1 h-3 w-3" />
                                    {daysRemaining} days remaining
                                </Badge>
                            )}
                            {isIdentityVerified && (
                                <Badge
                                    variant="outline"
                                    className="border-status-success/30 bg-status-success-bg text-status-success"
                                >
                                    <CheckCircle className="mr-1 h-3 w-3" />
                                    Identity Verified
                                </Badge>
                            )}
                        </div>
                    </PageHero>
                }
            >
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <User className="h-5 w-5 text-status-info" />
                                Requester Information
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div>
                                <span className="text-xs text-muted-foreground">
                                    Name
                                </span>
                                <p className="font-medium">
                                    {dsr.subject_name}
                                </p>
                            </div>
                            <div>
                                <span className="text-xs text-muted-foreground">
                                    Email
                                </span>
                                <p className="font-medium">
                                    {dsr.subject_email}
                                </p>
                            </div>
                            {isIdentityVerified && (
                                <div>
                                    <span className="text-xs text-muted-foreground">
                                        Verified By
                                    </span>
                                    <p className="font-medium">
                                        {dsr.verified_by?.name || 'N/A'}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {formatDate(dsr.identity_verified_at)}{' '}
                                        via {dsr.verification_method}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Calendar className="h-5 w-5 text-primary" />
                                Timeline
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div>
                                <span className="text-xs text-muted-foreground">
                                    Received
                                </span>
                                <p className="font-medium">
                                    {formatDate(dsr.received_at)}
                                </p>
                            </div>
                            <div>
                                <span className="text-xs text-muted-foreground">
                                    Due Date
                                </span>
                                <p
                                    className={`font-medium ${isOverdue ? 'text-status-critical' : ''}`}
                                >
                                    {formatDate(
                                        dsr.extended_due_date || dsr.due_date,
                                    )}
                                    {dsr.extension_requested && ' (Extended)'}
                                </p>
                            </div>
                            {dsr.completed_at && (
                                <div>
                                    <span className="text-xs text-muted-foreground">
                                        Completed
                                    </span>
                                    <p className="font-medium">
                                        {formatDate(dsr.completed_at)}
                                    </p>
                                </div>
                            )}
                            {dsr.assigned_to && (
                                <div>
                                    <span className="text-xs text-muted-foreground">
                                        Assigned To
                                    </span>
                                    <p className="font-medium">
                                        {dsr.assigned_to.name}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <FileText className="h-5 w-5 text-status-success" />
                            Request Details
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                            {dsr.request_details ||
                                'No additional details provided.'}
                        </p>
                        {dsr.completion_notes && (
                            <div className="mt-4 border-t pt-4">
                                <span className="text-xs text-muted-foreground">
                                    Completion Notes
                                </span>
                                <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                    {dsr.completion_notes}
                                </p>
                            </div>
                        )}
                        {dsr.rejection_reason && (
                            <div className="mt-4 border-t pt-4">
                                <span className="text-xs text-status-critical">
                                    Rejection Reason
                                </span>
                                <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                    {dsr.rejection_reason}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Legal Basis: {dsr.rejection_legal_basis}
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {dsr.status !== 'completed' && dsr.status !== 'rejected' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Actions</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            {!isIdentityVerified && (
                                <Button
                                    size="sm"
                                    data-test="privacy-dsr-verify"
                                    onClick={() => {
                                        const method = prompt(
                                            'Enter verification method (e.g., ID document, phone verification):',
                                        );
                                        if (method) {
                                            router.post(
                                                `/privacy/requests/${dsr.id}/verify-identity`,
                                                {
                                                    verification_method: method,
                                                },
                                            );
                                        }
                                    }}
                                >
                                    Verify Identity
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="outline"
                                data-test="privacy-dsr-export"
                                onClick={() => {
                                    router.get(
                                        `/privacy/requests/${dsr.id}/export`,
                                        {},
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                Generate Export
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                data-test="privacy-dsr-complete"
                                onClick={() => {
                                    const notes = prompt(
                                        'Enter completion notes:',
                                    );
                                    if (notes !== null) {
                                        router.post(
                                            `/privacy/requests/${dsr.id}/complete`,
                                            {
                                                completion_notes: notes,
                                            },
                                        );
                                    }
                                }}
                            >
                                Mark Complete
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    const reason = prompt(
                                        'Enter extension reason:',
                                    );
                                    const date = prompt(
                                        'Enter new due date (YYYY-MM-DD):',
                                    );
                                    if (reason && date) {
                                        router.post(
                                            `/privacy/requests/${dsr.id}/extend`,
                                            {
                                                extension_reason: reason,
                                                extended_due_date: date,
                                            },
                                        );
                                    }
                                }}
                            >
                                Extend Deadline
                            </Button>
                            <Button
                                size="sm"
                                variant="destructive"
                                onClick={() => {
                                    const reason = prompt(
                                        'Enter rejection reason:',
                                    );
                                    const legalBasis = prompt(
                                        'Enter legal basis for rejection:',
                                    );
                                    if (reason && legalBasis) {
                                        router.post(
                                            `/privacy/requests/${dsr.id}/refuse`,
                                            {
                                                rejection_reason: reason,
                                                rejection_legal_basis:
                                                    legalBasis,
                                            },
                                        );
                                    }
                                }}
                            >
                                Refuse Request
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
