import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Clock, FileText, XCircle } from 'lucide-react';
import { useState } from 'react';

type AuditEvent = { event: string; actor_id: number | null; at: string; meta?: Record<string, any> };

type Detail = {
    id: number;
    status: string;
    consent_type: { id: number; name: string; category: string } | null;
    requested_by: { id: number; name: string; email: string } | null;
    recipient: { id: number; name: string; email: string } | null;
    recipient_relationship: string;
    authority_to_consent: 'self' | 'substitute' | 'informational_only';
    purpose: string;
    least_restrictive_justification: string | null;
    data_scope: string | null;
    retention_period_days: number | null;
    withdrawal_method_text: string | null;
    staff_notes: string | null;
    response_notes: string | null;
    sent_at: string | null;
    viewed_at: string | null;
    expires_at: string | null;
    responded_at: string | null;
    is_expired: boolean;
    cancelled_by: { id: number; name: string } | null;
    cancellation_reason: string | null;
    resulting_consent: { id: number; status: string; given_at: string | null; expires_at: string | null } | null;
    resulting_consent_id: number | null;
    audit_trail: AuditEvent[];
    can_cancel: boolean;
};

type Props = {
    client: { id: number; full_name: string };
    request: Detail;
};

const STATUS_STYLES: Record<string, string> = {
    pending: 'bg-status-warning-bg text-status-warning',
    approved: 'bg-status-success-bg text-status-success',
    declined: 'bg-status-critical-bg text-status-critical',
    cancelled: 'bg-muted text-muted-foreground',
    expired: 'bg-muted text-muted-foreground',
};

export default function ConsentRequestShow({ client, request }: Props) {
    const [cancelOpen, setCancelOpen] = useState(false);
    const [reason, setReason] = useState('');

    const confirmCancel = () => {
        router.post(
            `/operations/clients/${client.id}/consent-requests/${request.id}/cancel`,
            { reason },
            { onSuccess: () => setCancelOpen(false) },
        );
    };

    return (
        <AppLayout>
            <Head title={`Consent request #${request.id}`} />
            <PageShell>
                <PageHeader
                    title={request.consent_type?.name ?? 'Consent request'}
                    description={`For ${client.full_name}`}
                    actions={
                        <Button asChild variant="outline">
                            <Link href={`/operations/clients/${client.id}/consent-requests`}>
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to list
                            </Link>
                        </Button>
                    }
                />

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>Status</CardTitle>
                                <Badge className={STATUS_STYLES[request.status]}>{request.status}</Badge>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                <Row label="Sent" value={formatDateTime(request.sent_at)} />
                                <Row label="Expires" value={formatDateTime(request.expires_at)} />
                                <Row label="Viewed by recipient" value={formatDateTime(request.viewed_at) ?? 'Not yet'} />
                                <Row label="Responded" value={formatDateTime(request.responded_at) ?? '—'} />
                                {request.is_expired && request.status === 'pending' && (
                                    <div className="mt-2 rounded border border-status-critical/30 bg-status-critical-bg p-2 text-xs text-status-critical">
                                        This request has passed its expiry and will auto-close on the next sweep.
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>What was asked</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4 text-sm">
                                <Field label="Purpose" body={request.purpose} />
                                {request.least_restrictive_justification && (
                                    <Field
                                        label="Least-restrictive justification"
                                        body={request.least_restrictive_justification}
                                    />
                                )}
                                {request.data_scope && <Field label="Data scope" body={request.data_scope} />}
                                {request.retention_period_days !== null && (
                                    <Field
                                        label="Data retention"
                                        body={`${request.retention_period_days} days`}
                                    />
                                )}
                                {request.withdrawal_method_text && (
                                    <Field label="Withdrawal method" body={request.withdrawal_method_text} />
                                )}
                            </CardContent>
                        </Card>

                        {request.response_notes && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Response from recipient</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="whitespace-pre-wrap text-sm">{request.response_notes}</p>
                                </CardContent>
                            </Card>
                        )}

                        {request.cancellation_reason && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Cancellation</CardTitle>
                                </CardHeader>
                                <CardContent className="text-sm">
                                    <p>Cancelled by {request.cancelled_by?.name ?? 'staff'}</p>
                                    <p className="mt-1 text-muted-foreground">
                                        Reason: {request.cancellation_reason}
                                    </p>
                                </CardContent>
                            </Card>
                        )}

                        {request.staff_notes && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Staff notes (internal)</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="whitespace-pre-wrap text-sm text-muted-foreground">
                                        {request.staff_notes}
                                    </p>
                                </CardContent>
                            </Card>
                        )}

                        <Card>
                            <CardHeader>
                                <CardTitle>Audit trail</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {request.audit_trail.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">No events recorded.</p>
                                ) : (
                                    <ul className="space-y-2 text-sm">
                                        {request.audit_trail.map((a, i) => (
                                            <li key={i} className="flex items-start gap-2">
                                                <Clock className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                                <div>
                                                    <span className="font-medium capitalize">{a.event}</span>
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        {formatDateTime(a.at)}
                                                    </span>
                                                    {a.actor_id && (
                                                        <span className="ml-2 text-xs text-muted-foreground">
                                                            actor #{a.actor_id}
                                                        </span>
                                                    )}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Parties</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <Row label="Requested by" value={request.requested_by?.name ?? '—'} />
                                <Row label="Recipient" value={request.recipient?.name ?? '—'} />
                                <Row
                                    label="Relationship"
                                    value={request.recipient_relationship.replace(/_/g, ' ')}
                                />
                                <Row label="Authority" value={authorityLabel(request.authority_to_consent)} />
                            </CardContent>
                        </Card>

                        {request.resulting_consent && (
                            <Card className="border-status-success/30 bg-status-success-bg">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <CheckCircle2 className="h-5 w-5 text-status-success" />
                                        Consent recorded
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2 text-sm">
                                    <Row label="Consent" value={`#${request.resulting_consent.id}`} />
                                    <Row label="Status" value={request.resulting_consent.status} />
                                    <Row label="Given" value={formatDateTime(request.resulting_consent.given_at)} />
                                    <Row
                                        label="Expires"
                                        value={formatDateTime(request.resulting_consent.expires_at) ?? 'No expiry'}
                                    />
                                    <Button asChild className="mt-2 w-full" variant="outline">
                                        <Link
                                            href={`/operations/clients/${client.id}/consents`}
                                        >
                                            <FileText className="mr-2 h-4 w-4" />
                                            View consent record
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        )}

                        {request.can_cancel && (
                            <Button variant="destructive" onClick={() => setCancelOpen(true)} className="w-full">
                                <XCircle className="mr-2 h-4 w-4" />
                                Cancel this request
                            </Button>
                        )}
                    </div>
                </div>

                <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Cancel consent request?</DialogTitle>
                            <DialogDescription>
                                The recipient will no longer be able to respond. Recorded with reason in the audit
                                trail.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-2">
                            <Label htmlFor="reason">Reason (required)</Label>
                            <Textarea
                                id="reason"
                                rows={3}
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                placeholder="Why is this being cancelled?"
                            />
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setCancelOpen(false)}>
                                Keep active
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={confirmCancel}
                                disabled={reason.trim().length < 5}
                            >
                                Cancel request
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </AppLayout>
    );
}

function Row({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="flex items-start justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right font-medium">{value ?? '—'}</span>
        </div>
    );
}

function Field({ label, body }: { label: string; body: string }) {
    return (
        <div>
            <div className="text-xs uppercase tracking-wide text-muted-foreground">{label}</div>
            <p className="mt-1 whitespace-pre-wrap">{body}</p>
        </div>
    );
}

function formatDateTime(iso: string | null): string | null {
    if (!iso) return null;
    try {
        return new Date(iso).toLocaleString('en-NZ', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return iso;
    }
}

function authorityLabel(a: 'self' | 'substitute' | 'informational_only'): string {
    return a === 'substitute' ? 'Authorised to consent' : a === 'self' ? 'Client themselves' : 'Informational only';
}
