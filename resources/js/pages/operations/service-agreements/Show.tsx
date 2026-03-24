import { DonutChart, OPS_COLORS } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CalendarDays,
    CheckCircle2,
    Clock,
    DollarSign,
    FileText,
    History,
    Milestone,
    Pause,
    Pencil,
    Play,
    RefreshCw,
    Send,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

/* ---------- types ---------- */

type LineItem = {
    id: number;
    item_number: string | null;
    description: string;
    unit_price: number;
    quantity: number | null;
    unit: string;
    budget_allocated: number;
    budget_used: number;
    category: string | null;
    ndis_line_item_code: string | null;
};

type StatusChange = {
    id: number;
    from_status: string | null;
    to_status: string;
    reason: string | null;
    notes: string | null;
    created_at: string;
    user: { id: number; name: string } | null;
};

type Props = {
    agreement: {
        id: number;
        title: string;
        reference_number: string | null;
        status: string;
        agreement_type: string;
        funding_body: string | null;
        funding_reference: string | null;
        starts_at: string | null;
        ends_at: string | null;
        total_budget: number;
        budget_used: number;
        budget_remaining: number;
        budget_utilisation_percent: number;
        hourly_rate: number | null;
        daily_rate: number | null;
        terms: string | null;
        notes: string | null;
        signed_at: string | null;
        signed_by: string | null;
        client: { id: number; first_name: string; last_name: string } | null;
        creator: { id: number; name: string } | null;
        line_items: LineItem[];
        funding_claims_count: number;
        status_changes: StatusChange[];
        nasc_assessment_date: string | null;
        funding_approved_date: string | null;
        signed_date: string | null;
        first_service_date: string | null;
        review_due_date: string | null;
        renewal_date: string | null;
        terminated_at: string | null;
        terminated_reason: string | null;
        suspended_at: string | null;
        suspended_reason: string | null;
        resumed_at: string | null;
    };
};

/* ---------- helpers ---------- */

function formatCurrency(n: number): string {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD', minimumFractionDigits: 2 }).format(n);
}

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatDateTime(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const STATUS_COLORS: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-700 border-slate-200',
    pending_approval: 'bg-amber-50 text-amber-700 border-amber-200',
    active: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    under_review: 'bg-violet-50 text-violet-700 border-violet-200',
    renewed: 'bg-blue-50 text-blue-700 border-blue-200',
    expired: 'bg-slate-100 text-slate-500 border-slate-200',
    terminated: 'bg-red-50 text-red-700 border-red-200',
    suspended: 'bg-amber-50 text-amber-700 border-amber-200',
};

function statusBadge(status: string) {
    const cls = STATUS_COLORS[status] ?? 'bg-slate-100 text-slate-600 border-slate-200';
    return (
        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize ${cls}`}>
            {status.replace(/_/g, ' ')}
        </span>
    );
}

/* ---------- Status Timeline ---------- */

const TIMELINE_STEPS = [
    { key: 'draft', label: 'Draft' },
    { key: 'pending_approval', label: 'Pending Approval' },
    { key: 'active', label: 'Active' },
    { key: 'under_review', label: 'Under Review' },
    { key: 'renewed', label: 'Renewed' },
];

const TERMINAL_STATUSES = ['expired', 'terminated', 'suspended'];

function StatusTimeline({ status }: { status: string }) {
    const currentIdx = TIMELINE_STEPS.findIndex((s) => s.key === status);
    const isTerminal = TERMINAL_STATUSES.includes(status);

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="flex items-center gap-2 text-sm font-medium">
                    <Milestone className="h-4 w-4 text-violet-500" />
                    Agreement Lifecycle
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="flex items-center gap-1">
                    {TIMELINE_STEPS.map((step, idx) => {
                        const isCurrent = step.key === status;
                        const isPast = currentIdx >= 0 && idx < currentIdx;

                        let dotCls = 'bg-slate-200 text-slate-400 border-slate-300';
                        if (isCurrent) dotCls = 'bg-violet-500 text-white border-violet-600 ring-2 ring-violet-200';
                        else if (isPast) dotCls = 'bg-emerald-500 text-white border-emerald-600';

                        let lineCls = 'bg-slate-200';
                        if (isPast || (currentIdx >= 0 && idx < currentIdx)) lineCls = 'bg-emerald-400';

                        return (
                            <div key={step.key} className="flex flex-1 flex-col items-center">
                                <div className="flex w-full items-center">
                                    {idx > 0 && <div className={`h-0.5 flex-1 ${lineCls}`} />}
                                    <div className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full border text-xs font-bold ${dotCls}`}>
                                        {isPast ? <CheckCircle2 className="h-4 w-4" /> : idx + 1}
                                    </div>
                                    {idx < TIMELINE_STEPS.length - 1 && <div className={`h-0.5 flex-1 ${idx < currentIdx ? 'bg-emerald-400' : 'bg-slate-200'}`} />}
                                </div>
                                <span className={`mt-1.5 text-center text-[10px] leading-tight ${isCurrent ? 'font-semibold text-violet-700' : isPast ? 'text-emerald-600' : 'text-muted-foreground'}`}>
                                    {step.label}
                                </span>
                            </div>
                        );
                    })}
                </div>

                {isTerminal && (
                    <div className="mt-3 flex items-center justify-center gap-2">
                        <div className={`rounded-full px-3 py-1 text-xs font-medium ${status === 'terminated' ? 'bg-red-100 text-red-700' : status === 'suspended' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'}`}>
                            {status === 'terminated' && <XCircle className="mr-1 inline h-3 w-3" />}
                            {status === 'suspended' && <Pause className="mr-1 inline h-3 w-3" />}
                            {status === 'expired' && <Clock className="mr-1 inline h-3 w-3" />}
                            {status.replace(/_/g, ' ').toUpperCase()}
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

/* ---------- Transition Buttons ---------- */

type TransitionDef = { label: string; toStatus: string; icon: React.ReactNode; variant: 'default' | 'outline' | 'destructive' };

function getTransitions(status: string): TransitionDef[] {
    switch (status) {
        case 'draft':
            return [{ label: 'Submit for Approval', toStatus: 'pending_approval', icon: <Send className="mr-1.5 h-3.5 w-3.5" />, variant: 'default' }];
        case 'pending_approval':
            return [
                { label: 'Approve', toStatus: 'active', icon: <ShieldCheck className="mr-1.5 h-3.5 w-3.5" />, variant: 'default' },
                { label: 'Return to Draft', toStatus: 'draft', icon: <RefreshCw className="mr-1.5 h-3.5 w-3.5" />, variant: 'outline' },
            ];
        case 'active':
            return [
                { label: 'Start Review', toStatus: 'under_review', icon: <FileText className="mr-1.5 h-3.5 w-3.5" />, variant: 'outline' },
                { label: 'Suspend', toStatus: 'suspended', icon: <Pause className="mr-1.5 h-3.5 w-3.5" />, variant: 'outline' },
                { label: 'Terminate', toStatus: 'terminated', icon: <XCircle className="mr-1.5 h-3.5 w-3.5" />, variant: 'destructive' },
            ];
        case 'under_review':
            return [
                { label: 'Renew', toStatus: 'renewed', icon: <RefreshCw className="mr-1.5 h-3.5 w-3.5" />, variant: 'default' },
                { label: 'Approve Changes', toStatus: 'active', icon: <ShieldCheck className="mr-1.5 h-3.5 w-3.5" />, variant: 'outline' },
            ];
        case 'suspended':
            return [
                { label: 'Resume', toStatus: 'active', icon: <Play className="mr-1.5 h-3.5 w-3.5" />, variant: 'default' },
                { label: 'Terminate', toStatus: 'terminated', icon: <XCircle className="mr-1.5 h-3.5 w-3.5" />, variant: 'destructive' },
            ];
        default:
            return [];
    }
}

/* ---------- TransitionDialog ---------- */

function TransitionDialog({
    open,
    onOpenChange,
    transition,
    agreementId,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    transition: TransitionDef | null;
    agreementId: number;
}) {
    const form = useForm({ status: transition?.toStatus ?? '', reason: '', notes: '' });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/operations/service-agreements/${agreementId}/transition`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    }

    if (!transition) return null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{transition.label}</DialogTitle>
                    <DialogDescription>
                        Transition this agreement to <strong>{transition.toStatus.replace(/_/g, ' ')}</strong>. Provide a reason or notes for the audit trail.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <input type="hidden" name="status" value={transition.toStatus} />
                    <div>
                        <Label htmlFor="reason">Reason</Label>
                        <Textarea
                            id="reason"
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                            placeholder="Why is this transition being made?"
                            rows={2}
                        />
                        {form.errors.reason && <p className="mt-1 text-xs text-red-500">{form.errors.reason}</p>}
                    </div>
                    <div>
                        <Label htmlFor="notes">Notes</Label>
                        <Textarea
                            id="notes"
                            value={form.data.notes}
                            onChange={(e) => form.setData('notes', e.target.value)}
                            placeholder="Any additional notes..."
                            rows={2}
                        />
                        {form.errors.notes && <p className="mt-1 text-xs text-red-500">{form.errors.notes}</p>}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing} variant={transition.variant === 'destructive' ? 'destructive' : 'default'}>
                            {form.processing ? 'Processing...' : transition.label}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/* ---------- Main Component ---------- */

export default function ServiceAgreementShow({ agreement: ag }: Props) {
    const utilPct = ag.budget_utilisation_percent;
    const [dialogOpen, setDialogOpen] = useState(false);
    const [activeTransition, setActiveTransition] = useState<TransitionDef | null>(null);
    const transitions = getTransitions(ag.status);

    function openTransition(t: TransitionDef) {
        setActiveTransition(t);
        setDialogOpen(true);
    }

    const milestoneDates = [
        { label: 'NASC Assessment', value: ag.nasc_assessment_date, icon: <FileText className="h-4 w-4 text-blue-500" /> },
        { label: 'Funding Approved', value: ag.funding_approved_date, icon: <DollarSign className="h-4 w-4 text-emerald-500" /> },
        { label: 'Signed', value: ag.signed_date, icon: <ShieldCheck className="h-4 w-4 text-violet-500" /> },
        { label: 'First Service', value: ag.first_service_date, icon: <Play className="h-4 w-4 text-teal-500" /> },
        { label: 'Review Due', value: ag.review_due_date, icon: <Clock className="h-4 w-4 text-amber-500" /> },
        { label: 'Renewal', value: ag.renewal_date, icon: <RefreshCw className="h-4 w-4 text-indigo-500" /> },
    ];

    return (
        <AppLayout>
            <Head title={ag.title} />
            <PageHeader title={ag.title} description={ag.client ? `${ag.client.first_name} ${ag.client.last_name}` : ''} backHref="/operations/service-agreements" />
            <PageShell>
                {/* Header Row */}
                <div className="flex flex-wrap items-center gap-2">
                    {statusBadge(ag.status)}
                    <Badge variant="outline">{ag.agreement_type.toUpperCase()}</Badge>
                    {ag.reference_number && <span className="text-xs text-muted-foreground">#{ag.reference_number}</span>}
                    {ag.funding_body && <span className="text-xs text-muted-foreground">{ag.funding_body}</span>}
                    {ag.starts_at && (
                        <span className="flex items-center gap-1 text-xs text-muted-foreground">
                            <CalendarDays className="h-3 w-3" /> {formatDate(ag.starts_at)} — {formatDate(ag.ends_at)}
                        </span>
                    )}
                    <div className="ml-auto flex gap-2">
                        {transitions.map((t) => (
                            <Button key={t.toStatus} size="sm" variant={t.variant} onClick={() => openTransition(t)}>
                                {t.icon}
                                {t.label}
                            </Button>
                        ))}
                        <Button asChild size="sm" variant="outline">
                            <Link href={`/operations/service-agreements/${ag.id}/edit`}>
                                <Pencil className="mr-1.5 h-3.5 w-3.5" /> Edit
                            </Link>
                        </Button>
                    </div>
                </div>

                {/* Status Timeline */}
                <div className="mt-6">
                    <StatusTimeline status={ag.status} />
                </div>

                {/* Milestone Dates */}
                <Card className="mt-4">
                    <CardHeader className="pb-2">
                        <CardTitle className="flex items-center gap-2 text-sm font-medium">
                            <CalendarDays className="h-4 w-4 text-violet-500" />
                            Milestone Dates
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {milestoneDates.map((m) => (
                                <div key={m.label} className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                                    {m.icon}
                                    <div>
                                        <div className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">{m.label}</div>
                                        <div className={`text-sm font-medium ${m.value ? '' : 'text-muted-foreground/50'}`}>
                                            {m.value ? formatDate(m.value) : 'Not set'}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                        {/* Terminated/Suspended info */}
                        {ag.terminated_at && (
                            <div className="mt-3 rounded-lg border border-red-200 bg-red-50 p-3">
                                <div className="flex items-center gap-2 text-sm font-medium text-red-700">
                                    <XCircle className="h-4 w-4" /> Terminated on {formatDateTime(ag.terminated_at)}
                                </div>
                                {ag.terminated_reason && <p className="mt-1 text-xs text-red-600">{ag.terminated_reason}</p>}
                            </div>
                        )}
                        {ag.suspended_at && !ag.resumed_at && (
                            <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3">
                                <div className="flex items-center gap-2 text-sm font-medium text-amber-700">
                                    <AlertTriangle className="h-4 w-4" /> Suspended on {formatDateTime(ag.suspended_at)}
                                </div>
                                {ag.suspended_reason && <p className="mt-1 text-xs text-amber-600">{ag.suspended_reason}</p>}
                            </div>
                        )}
                        {ag.resumed_at && (
                            <div className="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                                <div className="flex items-center gap-2 text-sm font-medium text-emerald-700">
                                    <Play className="h-4 w-4" /> Resumed on {formatDateTime(ag.resumed_at)}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Budget + Line Items */}
                <div className="mt-4 grid gap-4 lg:grid-cols-3">
                    {/* Budget gauge */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <DollarSign className="h-4 w-4 text-emerald-500" />
                                Budget
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-col items-center gap-3">
                                <DonutChart
                                    segments={[
                                        {
                                            label: 'Used',
                                            value: ag.budget_used,
                                            color: utilPct > 90 ? OPS_COLORS.danger : utilPct > 70 ? OPS_COLORS.warning : OPS_COLORS.primary,
                                        },
                                        { label: 'Remaining', value: ag.budget_remaining, color: '#e2e8f0' },
                                    ]}
                                    centerValue={`${utilPct}%`}
                                    centerLabel="Used"
                                    size={130}
                                    strokeWidth={16}
                                />
                                <div className="w-full space-y-1 text-xs">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Total Budget</span>
                                        <span className="font-medium">{formatCurrency(ag.total_budget)}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Used</span>
                                        <span className="font-medium">{formatCurrency(ag.budget_used)}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Remaining</span>
                                        <span className="font-medium text-emerald-600">{formatCurrency(ag.budget_remaining)}</span>
                                    </div>
                                    {ag.hourly_rate != null && Number(ag.hourly_rate) > 0 && (
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Hourly Rate</span>
                                            <span>{formatCurrency(ag.hourly_rate)}</span>
                                        </div>
                                    )}
                                    {ag.daily_rate != null && Number(ag.daily_rate) > 0 && (
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Daily Rate</span>
                                            <span>{formatCurrency(ag.daily_rate)}</span>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Line Items */}
                    <Card className="lg:col-span-2">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <FileText className="h-4 w-4 text-violet-500" />
                                Line Items ({ag.line_items?.length ?? 0})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {!ag.line_items || ag.line_items.length === 0 ? (
                                <p className="py-4 text-center text-xs text-muted-foreground">No line items added yet.</p>
                            ) : (
                                <div className="space-y-2">
                                    <div className="grid grid-cols-12 gap-2 text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                                        <div className="col-span-4">Description</div>
                                        <div className="col-span-2 text-right">Unit Price</div>
                                        <div className="col-span-1 text-center">Unit</div>
                                        <div className="col-span-2 text-right">Allocated</div>
                                        <div className="col-span-2 text-right">Used</div>
                                        <div className="col-span-1 text-right">%</div>
                                    </div>
                                    {ag.line_items.map((item) => {
                                        const itemPct = item.budget_allocated > 0 ? Math.round((item.budget_used / item.budget_allocated) * 100) : 0;
                                        return (
                                            <div key={item.id} className="grid grid-cols-12 items-center gap-2 rounded-md border px-2 py-1.5">
                                                <div className="col-span-4">
                                                    <div className="text-xs font-medium">{item.description}</div>
                                                    {item.ndis_line_item_code && <div className="text-[10px] text-muted-foreground">{item.ndis_line_item_code}</div>}
                                                </div>
                                                <div className="col-span-2 text-right text-xs tabular-nums">{formatCurrency(item.unit_price)}</div>
                                                <div className="col-span-1 text-center text-[10px] text-muted-foreground">{item.unit}</div>
                                                <div className="col-span-2 text-right text-xs tabular-nums">{formatCurrency(item.budget_allocated)}</div>
                                                <div className="col-span-2 text-right text-xs tabular-nums">{formatCurrency(item.budget_used)}</div>
                                                <div className="col-span-1 text-right text-xs tabular-nums">{itemPct}%</div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Audit Trail */}
                <Card className="mt-4">
                    <CardHeader className="pb-2">
                        <CardTitle className="flex items-center gap-2 text-sm font-medium">
                            <History className="h-4 w-4 text-violet-500" />
                            Status Audit Trail
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {!ag.status_changes || ag.status_changes.length === 0 ? (
                            <p className="py-4 text-center text-xs text-muted-foreground">No status changes recorded yet.</p>
                        ) : (
                            <div className="space-y-3">
                                {ag.status_changes.map((sc) => (
                                    <div key={sc.id} className="flex items-start gap-3 rounded-lg border bg-muted/20 p-3">
                                        <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-violet-100">
                                            <ArrowRight className="h-4 w-4 text-violet-600" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                {sc.from_status ? statusBadge(sc.from_status) : <span className="text-xs text-muted-foreground">-</span>}
                                                <ArrowRight className="h-3 w-3 text-muted-foreground" />
                                                {statusBadge(sc.to_status)}
                                            </div>
                                            <div className="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-muted-foreground">
                                                <span>{sc.user?.name ?? 'System'}</span>
                                                <span>&middot;</span>
                                                <span>{formatDateTime(sc.created_at)}</span>
                                            </div>
                                            {sc.reason && (
                                                <p className="mt-1.5 text-xs">
                                                    <span className="font-medium text-muted-foreground">Reason:</span> {sc.reason}
                                                </p>
                                            )}
                                            {sc.notes && (
                                                <p className="mt-0.5 text-xs">
                                                    <span className="font-medium text-muted-foreground">Notes:</span> {sc.notes}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Terms & Notes */}
                {(ag.terms || ag.notes) && (
                    <div className="mt-4 grid gap-4 md:grid-cols-2">
                        {ag.terms && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">Terms & Conditions</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="whitespace-pre-wrap text-xs">{ag.terms}</p>
                                </CardContent>
                            </Card>
                        )}
                        {ag.notes && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">Notes</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="whitespace-pre-wrap text-xs">{ag.notes}</p>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                )}

                {/* Transition Dialog */}
                <TransitionDialog open={dialogOpen} onOpenChange={setDialogOpen} transition={activeTransition} agreementId={ag.id} />
            </PageShell>
        </AppLayout>
    );
}
