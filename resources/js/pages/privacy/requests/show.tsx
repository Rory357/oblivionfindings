import { PageHero, PageLayout } from '@/components/page';
import {
    dayAfter,
    PrivacyFieldDialog,
} from '@/components/privacy/privacy-field-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { toDateInput } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CheckCircle,
    Clock,
    FileText,
    User,
} from 'lucide-react';
import { useState } from 'react';

type Props = {
    request: any;
    staff: Array<{ id: number; name: string }>;
};

type RequestAction = 'verify' | 'complete' | 'extend' | 'refuse';

export default function ShowDataSubjectRequest({ request: dsr, staff }: Props) {
    const [action, setAction] = useState<RequestAction | null>(null);
    const closeAction = () => setAction(null);
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
            access: 'Access · IPP 6',
            rectification: 'Correction · IPP 7',
            erasure: 'Deletion',
            restriction: 'Restriction of use',
            portability: 'Portability',
            objection: 'Objection',
            automated_decision: 'Automated decision',
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
                { title: 'Privacy', href: '/privacy/dashboard' },
                { title: 'Privacy Requests', href: '/privacy/requests' },
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
                        <div
                            className="flex flex-wrap gap-2"
                            data-test="privacy-dsr-show"
                        >
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
                                    onClick={() => setAction('verify')}
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
                                onClick={() => setAction('complete')}
                            >
                                Mark Complete
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                data-test="privacy-dsr-extend"
                                onClick={() => setAction('extend')}
                            >
                                Extend Deadline
                            </Button>
                            <Button
                                size="sm"
                                variant="destructive"
                                data-test="privacy-dsr-refuse"
                                onClick={() => setAction('refuse')}
                            >
                                Refuse Request
                            </Button>
                        </CardContent>
                    </Card>
                )}

                {/* Effects below follow DataSubjectRequestLifecycleService:
                    verify moves the request to in progress; complete and
                    refuse are final (no transition leaves either status);
                    none of the four contacts the requester. */}
                <PrivacyFieldDialog
                    open={action === 'verify'}
                    onClose={closeAction}
                    title={
                        dsr.subject_name
                            ? `Verify ${dsr.subject_name}'s identity?`
                            : "Verify the requester's identity?"
                    }
                    description="Record how you confirmed who they are. The request moves to in progress with your name and today's date, so it can then be completed or refused."
                    fields={[
                        {
                            name: 'verification_method',
                            label: 'How you checked their identity',
                            kind: 'text',
                            required: 'Say how you checked their identity.',
                            maxLength: 255,
                            placeholder:
                                'For example, passport sighted in person',
                        },
                    ]}
                    submitLabel="Verify identity"
                    variant="default"
                    url={`/privacy/requests/${dsr.id}/verify-identity`}
                />
                <PrivacyFieldDialog
                    open={action === 'complete'}
                    onClose={closeAction}
                    title="Mark this request as complete?"
                    description="The request is closed as completed, with your name and today's date. It can't be reopened. The requester isn't told automatically, so make sure they've had your response."
                    fields={[
                        {
                            name: 'completion_notes',
                            label: 'Completion notes',
                            kind: 'textarea',
                            placeholder: 'How the request was answered',
                        },
                    ]}
                    submitLabel="Mark complete"
                    variant="default"
                    url={`/privacy/requests/${dsr.id}/complete`}
                />
                <PrivacyFieldDialog
                    open={action === 'extend'}
                    onClose={closeAction}
                    title="Extend the due date for this request?"
                    description={`It's currently due ${formatDate(dsr.extended_due_date || dsr.due_date)}. The due date changes to the day you choose and your reason is kept with the request. The requester isn't told automatically.`}
                    fields={[
                        {
                            name: 'extended_due_date',
                            label: 'New due date',
                            kind: 'date',
                            required: 'Choose the new due date.',
                            // Server rule: after:today.
                            min: dayAfter(toDateInput(new Date())),
                            minMessage: 'Choose a date after today.',
                        },
                        {
                            name: 'extension_reason',
                            label: 'Reason for the extension',
                            kind: 'textarea',
                            required: 'Add the reason for the extension.',
                        },
                    ]}
                    submitLabel="Extend due date"
                    variant="default"
                    url={`/privacy/requests/${dsr.id}/extend`}
                />
                <PrivacyFieldDialog
                    open={action === 'refuse'}
                    onClose={closeAction}
                    title="Refuse this request?"
                    description={
                        <>
                            <p>
                                The request is closed as refused. Your reason,
                                the legal basis, your name and today's date are
                                kept on the record.
                            </p>
                            <p>
                                It can't be reopened or changed afterwards. The
                                requester isn't told automatically, so send them
                                the decision yourself.
                            </p>
                        </>
                    }
                    fields={[
                        {
                            name: 'rejection_reason',
                            label: 'Reason for refusing',
                            kind: 'textarea',
                            required: 'Add the reason for refusing.',
                        },
                        {
                            name: 'rejection_legal_basis',
                            label: 'Legal basis',
                            kind: 'text',
                            required: 'Add the legal basis for refusing.',
                            // The column holds 255 characters; the server
                            // rule doesn't cap it yet, so a longer value fails
                            // at the database instead of with a message.
                            maxLength: 255,
                            placeholder:
                                "The Privacy Act 2020 section you're relying on",
                        },
                    ]}
                    submitLabel="Refuse request"
                    variant="destructive"
                    url={`/privacy/requests/${dsr.id}/refuse`}
                />
            </PageLayout>
        </AppLayout>
    );
}
