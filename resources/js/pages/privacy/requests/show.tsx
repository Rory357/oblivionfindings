import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Head, Link, router } from '@inertiajs/react';
import { FileText, Clock, AlertTriangle, CheckCircle, User, Calendar } from 'lucide-react';

type Props = {
    request: any;
    staff: Array<{ id: number; name: string }>;
};

export default function ShowDataSubjectRequest({ request: dsr, staff }: Props) {
    const getStatusColor = (status: string) => {
        switch (status) {
            case 'completed':
                return 'bg-green-100 text-green-800';
            case 'in_progress':
                return 'bg-blue-100 text-blue-800';
            case 'pending':
            case 'pending_verification':
                return 'bg-yellow-100 text-yellow-800';
            case 'rejected':
                return 'bg-red-100 text-red-800';
            default:
                return 'bg-slate-100 text-slate-800';
        }
    };

    const getRequestTypeLabel = (type: string) => {
        const labels: Record<string, string> = {
            'access': 'Right to Access (Art. 15)',
            'rectification': 'Right to Rectification (Art. 16)',
            'erasure': 'Right to Erasure (Art. 17)',
            'restriction': 'Right to Restriction (Art. 18)',
            'portability': 'Right to Portability (Art. 20)',
            'objection': 'Right to Object (Art. 21)',
            'automated_decision': 'Automated Decision Rights (Art. 22)',
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
        return Math.ceil((due.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
    };

    const daysRemaining = getDaysRemaining();
    const isOverdue = daysRemaining < 0;

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Data Subject Requests', href: '/privacy/requests' },
            { title: dsr.reference_number, href: `/privacy/requests/${dsr.id}` },
        ]}>
            <Head title={`DSR ${dsr.reference_number}`} />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            {isOverdue && <AlertTriangle className="h-5 w-5 text-red-500" />}
                            {dsr.reference_number}
                        </h1>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge className={getStatusColor(dsr.status)}>
                                {dsr.status?.replace(/_/g, ' ')}
                            </Badge>
                            <Badge variant="outline" className="border-blue-200 bg-blue-50 text-blue-700">
                                {getRequestTypeLabel(dsr.request_type)}
                            </Badge>
                            {isOverdue && (
                                <Badge variant="outline" className="border-red-200 bg-red-50 text-red-700">
                                    <AlertTriangle className="mr-1 h-3 w-3" />
                                    {Math.abs(daysRemaining)} days overdue
                                </Badge>
                            )}
                            {!isOverdue && daysRemaining <= 7 && (
                                <Badge variant="outline" className="border-orange-200 bg-orange-50 text-orange-700">
                                    <Clock className="mr-1 h-3 w-3" />
                                    {daysRemaining} days remaining
                                </Badge>
                            )}
                            {dsr.identity_verified && (
                                <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700">
                                    <CheckCircle className="mr-1 h-3 w-3" />
                                    Identity Verified
                                </Badge>
                            )}
                        </div>
                    </div>
                    <Link href="/privacy/requests" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back to List
                    </Link>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <User className="h-5 w-5 text-blue-500" />
                                Requester Information
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div>
                                <span className="text-xs text-slate-500">Name</span>
                                <p className="font-medium">{dsr.subject_name}</p>
                            </div>
                            <div>
                                <span className="text-xs text-slate-500">Email</span>
                                <p className="font-medium">{dsr.subject_email}</p>
                            </div>
                            {dsr.identity_verified && (
                                <div>
                                    <span className="text-xs text-slate-500">Verified By</span>
                                    <p className="font-medium">{dsr.verified_by?.name || 'N/A'}</p>
                                    <p className="text-xs text-slate-500">
                                        {formatDate(dsr.identity_verified_at)} via {dsr.verification_method}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Calendar className="h-5 w-5 text-purple-500" />
                                Timeline
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div>
                                <span className="text-xs text-slate-500">Received</span>
                                <p className="font-medium">{formatDate(dsr.received_at)}</p>
                            </div>
                            <div>
                                <span className="text-xs text-slate-500">Due Date</span>
                                <p className={`font-medium ${isOverdue ? 'text-red-600' : ''}`}>
                                    {formatDate(dsr.extended_due_date || dsr.due_date)}
                                    {dsr.extension_requested && ' (Extended)'}
                                </p>
                            </div>
                            {dsr.completed_at && (
                                <div>
                                    <span className="text-xs text-slate-500">Completed</span>
                                    <p className="font-medium">{formatDate(dsr.completed_at)}</p>
                                </div>
                            )}
                            {dsr.assigned_to && (
                                <div>
                                    <span className="text-xs text-slate-500">Assigned To</span>
                                    <p className="font-medium">{dsr.assigned_to.name}</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <FileText className="h-5 w-5 text-green-500" />
                            Request Details
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-sm text-slate-600 whitespace-pre-wrap">
                            {dsr.request_details || 'No additional details provided.'}
                        </p>
                        {dsr.completion_notes && (
                            <div className="mt-4 pt-4 border-t">
                                <span className="text-xs text-slate-500">Completion Notes</span>
                                <p className="text-sm text-slate-600 whitespace-pre-wrap mt-1">
                                    {dsr.completion_notes}
                                </p>
                            </div>
                        )}
                        {dsr.rejection_reason && (
                            <div className="mt-4 pt-4 border-t">
                                <span className="text-xs text-red-500">Rejection Reason</span>
                                <p className="text-sm text-slate-600 whitespace-pre-wrap mt-1">
                                    {dsr.rejection_reason}
                                </p>
                                <p className="text-xs text-slate-500 mt-1">
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
                            {!dsr.identity_verified && (
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        const method = prompt('Enter verification method (e.g., ID document, phone verification):');
                                        if (method) {
                                            router.post(`/privacy/requests/${dsr.id}/verify-identity`, {
                                                verification_method: method,
                                            });
                                        }
                                    }}
                                >
                                    Verify Identity
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    const notes = prompt('Enter completion notes:');
                                    if (notes !== null) {
                                        router.post(`/privacy/requests/${dsr.id}/complete`, {
                                            completion_notes: notes,
                                        });
                                    }
                                }}
                            >
                                Mark Complete
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    const reason = prompt('Enter extension reason:');
                                    const date = prompt('Enter new due date (YYYY-MM-DD):');
                                    if (reason && date) {
                                        router.post(`/privacy/requests/${dsr.id}/extend`, {
                                            extension_reason: reason,
                                            extended_due_date: date,
                                        });
                                    }
                                }}
                            >
                                Extend Deadline
                            </Button>
                            <Button
                                size="sm"
                                variant="destructive"
                                onClick={() => {
                                    const reason = prompt('Enter rejection reason:');
                                    const legalBasis = prompt('Enter legal basis for rejection:');
                                    if (reason && legalBasis) {
                                        router.post(`/privacy/requests/${dsr.id}/refuse`, {
                                            rejection_reason: reason,
                                            rejection_legal_basis: legalBasis,
                                        });
                                    }
                                }}
                            >
                                Refuse Request
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
