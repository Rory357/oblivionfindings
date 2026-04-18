import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, ShieldAlert, XCircle } from 'lucide-react';
import { FormEvent, useState } from 'react';

type ConsentType = {
    id: number;
    name: string;
    category: string;
    description: string | null;
    purpose: string | null;
    legal_basis: string | null;
    validity_period_days: number | null;
};

type ReqDetail = {
    id: number;
    status: string;
    consent_type: ConsentType | null;
    requested_by: { id: number; name: string; email: string } | null;
    recipient_relationship: string;
    authority_to_consent: 'self' | 'substitute' | 'informational_only';
    purpose: string;
    least_restrictive_justification: string | null;
    data_scope: string | null;
    retention_period_days: number | null;
    withdrawal_method_text: string | null;
    expires_at: string | null;
    sent_at: string | null;
    viewed_at: string | null;
    responded_at: string | null;
    is_expired: boolean;
    is_actionable: boolean;
};

type Props = {
    client: { id: number; full_name: string };
    request: ReqDetail;
};

export default function PortalConsentRequestShow({ client, request }: Props) {
    const [mode, setMode] = useState<'review' | 'approve' | 'decline'>('review');

    const approveForm = useForm({ response_notes: '', acknowledge_authority: false });
    const declineForm = useForm({ response_notes: '' });

    const submitApprove = (e: FormEvent) => {
        e.preventDefault();
        approveForm.post(`/portal/clients/${client.id}/consent-requests/${request.id}/approve`);
    };

    const submitDecline = (e: FormEvent) => {
        e.preventDefault();
        declineForm.post(`/portal/clients/${client.id}/consent-requests/${request.id}/decline`);
    };

    const authorityText =
        request.authority_to_consent === 'substitute'
            ? `I confirm I am ${client.full_name}'s ${request.recipient_relationship.replace(/_/g, ' ')} and I am authorised to give consent on their behalf under NZ law (PPPR Act 1988 / equivalent).`
            : request.authority_to_consent === 'self'
              ? 'I confirm I am the person the consent is for and I am giving it freely.'
              : 'I am providing this response for information only; I understand my response does not by itself authorise the care action.';

    return (
        <div className="min-h-screen bg-slate-50">
            <Head title={`Consent request — ${client.full_name}`} />

            <div className="mx-auto max-w-3xl px-4 py-8">
                <Link
                    href={`/portal/clients/${client.id}/dashboard`}
                    className="mb-4 inline-flex items-center text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="mr-1 h-4 w-4" />
                    Back to dashboard
                </Link>

                <div className="mb-6">
                    <div className="flex items-center gap-3">
                        <h1 className="text-2xl font-semibold">
                            Consent request for {client.full_name}
                        </h1>
                        <Badge className="bg-slate-200">{request.status}</Badge>
                    </div>
                    {request.expires_at && (
                        <p className="mt-1 text-sm text-muted-foreground">
                            Please respond by {formatDate(request.expires_at)}.
                        </p>
                    )}
                </div>

                {!request.is_actionable && request.status === 'pending' && request.is_expired && (
                    <Card className="mb-6 border-amber-300 bg-amber-50">
                        <CardContent className="flex items-start gap-3 p-4">
                            <ShieldAlert className="mt-0.5 h-5 w-5 text-amber-600" />
                            <div>
                                <div className="font-medium">This request has expired.</div>
                                <p className="text-sm text-muted-foreground">
                                    Please contact the care team if you still want to respond — they can re-issue the
                                    request.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {!request.is_actionable && request.status !== 'pending' && (
                    <Card className="mb-6">
                        <CardContent className="p-4 text-sm text-muted-foreground">
                            You already responded to this request on{' '}
                            {formatDate(request.responded_at ?? '') || 'an earlier date'}.
                        </CardContent>
                    </Card>
                )}

                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>{request.consent_type?.name ?? 'Consent'}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-5 text-sm">
                        {request.consent_type?.description && (
                            <Section label="About this consent type">
                                {request.consent_type.description}
                            </Section>
                        )}

                        <Section label="Why we are asking">{request.purpose}</Section>

                        {request.least_restrictive_justification && (
                            <Section label="Why this is the least-restrictive option">
                                {request.least_restrictive_justification}
                            </Section>
                        )}

                        {request.data_scope && <Section label="Who will see this data">{request.data_scope}</Section>}

                        {request.retention_period_days !== null && (
                            <Section label="How long we keep the data">
                                {request.retention_period_days} days
                            </Section>
                        )}

                        {request.withdrawal_method_text && (
                            <Section label="How you can withdraw later">{request.withdrawal_method_text}</Section>
                        )}

                        {request.consent_type?.legal_basis && (
                            <Section label="Legal basis">{request.consent_type.legal_basis}</Section>
                        )}

                        <Section label="Your rights">
                            You can ask questions, take time, decline, or withdraw later. If you decline, the care
                            team will find another path forward. This does not affect {client.full_name}'s care
                            entitlement.
                        </Section>
                    </CardContent>
                </Card>

                {request.is_actionable && mode === 'review' && (
                    <div className="flex gap-3">
                        <Button className="flex-1" onClick={() => setMode('approve')}>
                            <CheckCircle2 className="mr-2 h-4 w-4" />
                            Approve
                        </Button>
                        <Button className="flex-1" variant="outline" onClick={() => setMode('decline')}>
                            <XCircle className="mr-2 h-4 w-4" />
                            Decline
                        </Button>
                    </div>
                )}

                {request.is_actionable && mode === 'approve' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Approve this consent</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitApprove} className="space-y-4">
                                <div>
                                    <Label htmlFor="response_notes_a">Notes (optional)</Label>
                                    <Textarea
                                        id="response_notes_a"
                                        rows={3}
                                        value={approveForm.data.response_notes}
                                        onChange={(e) => approveForm.setData('response_notes', e.target.value)}
                                        placeholder="Any conditions, context, or follow-up requests."
                                    />
                                </div>

                                <div className="flex items-start gap-2 rounded border border-emerald-200 bg-emerald-50 p-3">
                                    <Checkbox
                                        id="ack"
                                        checked={approveForm.data.acknowledge_authority}
                                        onCheckedChange={(checked) =>
                                            approveForm.setData('acknowledge_authority', checked === true)
                                        }
                                    />
                                    <label htmlFor="ack" className="text-sm">
                                        {authorityText}
                                    </label>
                                </div>

                                {approveForm.errors.acknowledge_authority && (
                                    <p className="text-xs text-red-600">
                                        You must confirm your authority to give consent.
                                    </p>
                                )}

                                <div className="flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setMode('review')}
                                        disabled={approveForm.processing}
                                    >
                                        Back
                                    </Button>
                                    <Button type="submit" disabled={approveForm.processing}>
                                        {approveForm.processing ? 'Recording…' : 'Confirm approval'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {request.is_actionable && mode === 'decline' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Decline this consent</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitDecline} className="space-y-4">
                                <div>
                                    <Label htmlFor="response_notes_d">
                                        Reason for declining (required, min 5 chars)
                                    </Label>
                                    <Textarea
                                        id="response_notes_d"
                                        rows={4}
                                        value={declineForm.data.response_notes}
                                        onChange={(e) => declineForm.setData('response_notes', e.target.value)}
                                        placeholder="Tell the care team why you're declining. They'll work with you on alternatives."
                                    />
                                    {declineForm.errors.response_notes && (
                                        <p className="mt-1 text-xs text-red-600">
                                            {declineForm.errors.response_notes}
                                        </p>
                                    )}
                                </div>

                                <div className="flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setMode('review')}
                                        disabled={declineForm.processing}
                                    >
                                        Back
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={declineForm.processing || declineForm.data.response_notes.length < 5}
                                    >
                                        {declineForm.processing ? 'Submitting…' : 'Decline'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
        </div>
    );
}

function Section({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <div className="text-xs uppercase tracking-wide text-muted-foreground">{label}</div>
            <p className="mt-1 whitespace-pre-wrap">{children}</p>
        </div>
    );
}

function formatDate(iso: string): string {
    try {
        return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'long', year: 'numeric' });
    } catch {
        return iso;
    }
}
