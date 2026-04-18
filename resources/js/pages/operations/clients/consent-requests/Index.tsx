import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Clock, CheckCircle2, XCircle, FileText, Plus, Send, UserCheck } from 'lucide-react';

type RequestSummary = {
    id: number;
    status: 'pending' | 'approved' | 'declined' | 'cancelled' | 'expired';
    consent_type: { id: number; name: string; category: string } | null;
    requested_by: { id: number; name: string } | null;
    recipient: { id: number; name: string } | null;
    recipient_relationship: string;
    authority_to_consent: 'self' | 'substitute' | 'informational_only';
    sent_at: string | null;
    expires_at: string | null;
    responded_at: string | null;
    is_expired: boolean;
    resulting_consent_id: number | null;
};

type Props = {
    client: { id: number; full_name: string };
    requests: RequestSummary[];
    stats: { total: number; pending: number; approved: number; declined: number };
};

const STATUS_STYLES: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-800',
    approved: 'bg-emerald-100 text-emerald-800',
    declined: 'bg-red-100 text-red-800',
    cancelled: 'bg-slate-100 text-slate-600',
    expired: 'bg-slate-100 text-slate-500',
};

const AUTHORITY_LABEL: Record<string, string> = {
    self: 'Client themselves',
    substitute: 'Substituted consent (authorised)',
    informational_only: 'Informational only — not consent authority',
};

export default function ConsentRequestsIndex({ client, requests = [], stats }: Props) {
    return (
        <AppLayout>
            <Head title={`Consent requests — ${client.full_name}`} />
            <PageShell>
                <PageHeader
                    title="Consent requests"
                    description={`Family-portal consent workflow for ${client.full_name}`}
                    actions={
                        <Button asChild>
                            <Link href={`/operations/clients/${client.id}/consent-requests/create`}>
                                <Plus className="mr-2 h-4 w-4" />
                                New request
                            </Link>
                        </Button>
                    }
                />

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <StatCard label="Total" value={stats.total} icon={FileText} />
                    <StatCard label="Pending" value={stats.pending} icon={Clock} tone="amber" />
                    <StatCard label="Approved" value={stats.approved} icon={CheckCircle2} tone="emerald" />
                    <StatCard label="Declined" value={stats.declined} icon={XCircle} tone="red" />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>All requests</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {requests.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center text-sm text-muted-foreground">
                                <Send className="mb-3 h-10 w-10 opacity-40" />
                                <p>No consent requests yet for this client.</p>
                                <p className="mt-1">
                                    Start one from the client profile when you need a family signatory to approve a
                                    consent (e.g. personal-tracker assignment).
                                </p>
                            </div>
                        ) : (
                            <ul className="divide-y">
                                {requests.map((r) => (
                                    <li key={r.id} className="flex items-start justify-between gap-4 py-3">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={`/operations/clients/${client.id}/consent-requests/${r.id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {r.consent_type?.name ?? 'Consent'}
                                                </Link>
                                                <Badge className={STATUS_STYLES[r.status] ?? 'bg-slate-100'}>{r.status}</Badge>
                                                {r.is_expired && r.status === 'pending' && (
                                                    <Badge className="bg-red-100 text-red-700">overdue</Badge>
                                                )}
                                            </div>
                                            <div className="mt-1 text-sm text-muted-foreground">
                                                <span className="font-medium">To:</span>{' '}
                                                {r.recipient?.name ?? 'Unknown'} ({r.recipient_relationship.replace(/_/g, ' ')})
                                                <span className="mx-2">·</span>
                                                <UserCheck className="mr-1 inline h-3 w-3" />
                                                {AUTHORITY_LABEL[r.authority_to_consent]}
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                Requested by {r.requested_by?.name ?? 'staff'}
                                                {r.sent_at && <> · sent {formatDate(r.sent_at)}</>}
                                                {r.expires_at && <> · expires {formatDate(r.expires_at)}</>}
                                                {r.responded_at && <> · responded {formatDate(r.responded_at)}</>}
                                            </div>
                                        </div>
                                        <div className="flex gap-2">
                                            <Button asChild variant="outline" size="sm">
                                                <Link href={`/operations/clients/${client.id}/consent-requests/${r.id}`}>
                                                    View
                                                </Link>
                                            </Button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}

function StatCard({
    label,
    value,
    icon: Icon,
    tone = 'slate',
}: {
    label: string;
    value: number;
    icon: any;
    tone?: 'slate' | 'amber' | 'emerald' | 'red';
}) {
    const toneClass: Record<string, string> = {
        slate: 'text-slate-600',
        amber: 'text-amber-600',
        emerald: 'text-emerald-600',
        red: 'text-red-600',
    };
    return (
        <Card>
            <CardContent className="flex items-center gap-3 p-4">
                <Icon className={`h-6 w-6 ${toneClass[tone]}`} />
                <div>
                    <div className="text-2xl font-semibold">{value}</div>
                    <div className="text-xs uppercase tracking-wide text-muted-foreground">{label}</div>
                </div>
            </CardContent>
        </Card>
    );
}

function formatDate(iso: string): string {
    try {
        return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
    } catch {
        return iso;
    }
}
