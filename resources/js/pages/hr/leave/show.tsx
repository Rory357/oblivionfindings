import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle, FileText, XCircle } from 'lucide-react';
import { useState } from 'react';

interface LeaveRequest {
    id: number;
    staff_name: string;
    staff_id: number;
    leave_type: string;
    start_date: string;
    end_date: string;
    hours: number;
    status: string;
    reason: string | null;
    reviewed_by: string | null;
    reviewed_at: string | null;
    review_notes: string | null;
    submitted_at: string | null;
    supporting_doc_path: string | null;
}

interface Props {
    request: LeaveRequest;
    can: {
        approve: boolean;
        manage: boolean;
    };
}

const statusColors: Record<string, string> = {
    pending:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    approved:
        'bg-status-success-bg text-status-success border-status-success/30',
    declined:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
    cancelled:
        'bg-muted-foreground/80/10 text-muted-foreground border-border/30',
};

export default function ShowLeave({ request, can }: Props) {
    const [reviewNotes, setReviewNotes] = useState('');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Leave', href: '/hr/leave' },
        { title: `Request #${request.id}`, href: `/hr/leave/${request.id}` },
    ];

    const handleApprove = () => {
        if (confirm('Approve this leave request?')) {
            router.post(`/hr/leave/${request.id}/approve`, {
                review_notes: reviewNotes,
            });
        }
    };

    const handleDecline = () => {
        if (!reviewNotes.trim()) {
            alert('Please provide a reason for declining.');
            return;
        }
        if (confirm('Decline this leave request?')) {
            router.post(`/hr/leave/${request.id}/decline`, {
                review_notes: reviewNotes,
            });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Leave Request #${request.id}`} />
            <div className="mx-auto flex max-w-4xl flex-col gap-6 p-6">
                <div className="flex items-center gap-4">
                    <Link href="/hr/leave">
                        <Button variant="outline" size="icon">
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                    </Link>
                    <h1 className="text-2xl font-bold">
                        Leave Request #{request.id}
                    </h1>
                </div>

                <div className="grid gap-6 md:grid-cols-3">
                    <Card className="md:col-span-2">
                        <CardHeader>
                            <CardTitle>Request Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Staff Member
                                    </p>
                                    <p className="font-medium">
                                        {request.staff_name}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Status
                                    </p>
                                    <Badge
                                        variant="outline"
                                        className={
                                            statusColors[request.status] ||
                                            statusColors.pending
                                        }
                                    >
                                        {request.status
                                            .charAt(0)
                                            .toUpperCase() +
                                            request.status.slice(1)}
                                    </Badge>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Leave Type
                                    </p>
                                    <p className="font-medium capitalize">
                                        {request.leave_type.replace('_', ' ')}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Hours
                                    </p>
                                    <p className="font-medium">
                                        {request.hours} hours
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Start Date
                                    </p>
                                    <p className="font-medium">
                                        {request.start_date}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        End Date
                                    </p>
                                    <p className="font-medium">
                                        {request.end_date}
                                    </p>
                                </div>
                            </div>

                            {request.reason && (
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Reason
                                    </p>
                                    <p className="mt-1 text-sm">
                                        {request.reason}
                                    </p>
                                </div>
                            )}

                            {request.supporting_doc_path && (
                                <div className="flex items-center gap-2">
                                    <FileText className="h-4 w-4 text-muted-foreground" />
                                    <span className="text-sm text-muted-foreground">
                                        Supporting document attached
                                    </span>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Review Info</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Submitted
                                </p>
                                <p className="font-medium">
                                    {request.submitted_at
                                        ? new Date(
                                              request.submitted_at,
                                          ).toLocaleString()
                                        : 'N/A'}
                                </p>
                            </div>
                            {request.reviewed_by && (
                                <>
                                    <div>
                                        <p className="text-sm text-muted-foreground">
                                            Reviewed By
                                        </p>
                                        <p className="font-medium">
                                            {request.reviewed_by}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-sm text-muted-foreground">
                                            Reviewed At
                                        </p>
                                        <p className="font-medium">
                                            {request.reviewed_at
                                                ? new Date(
                                                      request.reviewed_at,
                                                  ).toLocaleString()
                                                : 'N/A'}
                                        </p>
                                    </div>
                                </>
                            )}
                            {request.review_notes && (
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Review Notes
                                    </p>
                                    <p className="mt-1 text-sm">
                                        {request.review_notes}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {can.approve && request.status === 'pending' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Review Request</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="review_notes">
                                    Review Notes
                                </Label>
                                <Textarea
                                    id="review_notes"
                                    value={reviewNotes}
                                    onChange={(e) =>
                                        setReviewNotes(e.target.value)
                                    }
                                    placeholder="Add notes about your decision..."
                                    rows={3}
                                />
                            </div>
                            <div className="flex gap-3">
                                <Button
                                    variant="default"
                                    onClick={handleApprove}
                                    className="bg-status-success hover:bg-status-success"
                                >
                                    <CheckCircle className="mr-2 h-4 w-4" />
                                    Approve
                                </Button>
                                <Button
                                    variant="destructive"
                                    onClick={handleDecline}
                                >
                                    <XCircle className="mr-2 h-4 w-4" />
                                    Decline
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
