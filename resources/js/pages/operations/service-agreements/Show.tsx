import { DonutChart, OPS_COLORS } from '@/components/ops-stat-card';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CalendarDays,
    CheckCircle2,
    Clock,
    DollarSign,
    ExternalLink,
    FileText,
    History,
    Landmark,
    Link2,
    Mail,
    Milestone,
    Pause,
    PenLine,
    Pencil,
    Phone,
    Play,
    Plus,
    Receipt,
    RefreshCw,
    Send,
    ShieldCheck,
    Timer,
    Trash2,
    UserCheck,
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

type Rate = {
    id: number;
    rate_type: string;
    rate: number;
    unit: string;
    effective_from: string | null;
    effective_to: string | null;
    notes: string | null;
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
        rates: Rate[];
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
        // NZ fields
        funding_type: string | null;
        service_level: string | null;
        allocated_hours_per_week: number | null;
        total_hours: number | null;
        hours_used: number | null;
        hours_remaining: number | null;
        hours_utilisation_percent: number | null;
        gst_inclusive: boolean;
        whaikaha_reference: string | null;
        support_needs_level: string | null;
        nasc_assessor_name: string | null;
        nasc_support_package_ref: string | null;
        client_signatory: string | null;
        provider_signatory: string | null;
        funder_contact_name: string | null;
        funder_contact_email: string | null;
        funder_contact_phone: string | null;
        client_id: number;
    };
    budget_summary?: {
        total_budget: number;
        budget_used: number;
        budget_allocated: number;
        budget_remaining: number;
        utilisation_percent: number;
    };
    funding_claims_summary?: {
        draft: number;
        submitted: number;
        approved: number;
        total_claimed: number;
    };
};

const FUNDING_TYPE_LABELS: Record<string, string> = {
    if: 'Individualised Funding (IF)',
    eif: 'Enhanced IF (EIF)',
    flexible_disability: 'Flexible Disability Support',
    residential: 'Residential Support',
    community_participation: 'Community Participation',
    respite: 'Respite',
    day_services: 'Day Services',
    vocational: 'Vocational',
    other: 'Other',
};

const SERVICE_LEVEL_LABELS: Record<string, string> = {
    level_1: 'Level 1',
    level_2: 'Level 2',
    level_3: 'Level 3',
    level_4: 'Level 4',
    community: 'Community',
    flexible: 'Flexible',
};

const SUPPORT_NEEDS_LABELS: Record<string, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    very_high: 'Very High',
    complex: 'Complex',
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
    draft: 'bg-muted text-foreground border-border',
    pending_approval: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    active: 'bg-status-success-bg text-status-success border-status-success/30',
    under_review: 'bg-primary/10 text-primary border-primary',
    renewed: 'bg-status-info-bg text-status-info border-status-info/30',
    expired: 'bg-muted text-muted-foreground border-border',
    terminated: 'bg-status-critical-bg text-status-critical border-status-critical/30',
    suspended: 'bg-status-warning-bg text-status-warning border-status-warning/30',
};

function statusBadge(status: string) {
    const cls = STATUS_COLORS[status] ?? 'bg-muted text-muted-foreground border-border';
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
                    <Milestone className="h-4 w-4 text-primary" />
                    Agreement Lifecycle
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="flex items-center gap-1">
                    {TIMELINE_STEPS.map((step, idx) => {
                        const isCurrent = step.key === status;
                        const isPast = currentIdx >= 0 && idx < currentIdx;

                        let dotCls = 'bg-muted text-muted-foreground border-border';
                        if (isCurrent) dotCls = 'bg-primary text-white border-primary ring-2 ring-ring';
                        else if (isPast) dotCls = 'bg-status-success text-white border-status-success/30';

                        let lineCls = 'bg-muted';
                        if (isPast || (currentIdx >= 0 && idx < currentIdx)) lineCls = 'bg-status-success';

                        return (
                            <div key={step.key} className="flex flex-1 flex-col items-center">
                                <div className="flex w-full items-center">
                                    {idx > 0 && <div className={`h-0.5 flex-1 ${lineCls}`} />}
                                    <div className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full border text-xs font-bold ${dotCls}`}>
                                        {isPast ? <CheckCircle2 className="h-4 w-4" /> : idx + 1}
                                    </div>
                                    {idx < TIMELINE_STEPS.length - 1 && <div className={`h-0.5 flex-1 ${idx < currentIdx ? 'bg-status-success' : 'bg-muted'}`} />}
                                </div>
                                <span className={`mt-1.5 text-center text-[10px] leading-tight ${isCurrent ? 'font-semibold text-primary' : isPast ? 'text-status-success' : 'text-muted-foreground'}`}>
                                    {step.label}
                                </span>
                            </div>
                        );
                    })}
                </div>

                {isTerminal && (
                    <div className="mt-3 flex items-center justify-center gap-2">
                        <div className={`rounded-full px-3 py-1 text-xs font-medium ${status === 'terminated' ? 'bg-status-critical-bg text-status-critical' : status === 'suspended' ? 'bg-status-warning-bg text-status-warning' : 'bg-muted text-muted-foreground'}`}>
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
    const [reason, setReason] = useState('');
    const [notes, setNotes] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    function doTransition() {
        if (!transition) return;
        setProcessing(true);
        router.post(`/operations/service-agreements/${agreementId}/transition`, {
            status: transition.toStatus,
            reason,
            notes,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setReason('');
                setNotes('');
                setErrors({});
                onOpenChange(false);
            },
            onError: (errs: any) => setErrors(errs),
            onFinish: () => setProcessing(false),
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
                <div className="space-y-4">
                    <div>
                        <Label htmlFor="reason">Reason</Label>
                        <Textarea
                            id="reason"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="Why is this transition being made?"
                            rows={2}
                        />
                        {errors.reason && <p className="mt-1 text-xs text-status-critical">{errors.reason}</p>}
                    </div>
                    <div>
                        <Label htmlFor="notes">Notes</Label>
                        <Textarea
                            id="notes"
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            placeholder="Any additional notes..."
                            rows={2}
                        />
                        {errors.notes && <p className="mt-1 text-xs text-status-critical">{errors.notes}</p>}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="button" disabled={processing} variant={transition.variant === 'destructive' ? 'destructive' : 'default'} onClick={doTransition}>
                            {processing ? 'Processing...' : transition.label}
                        </Button>
                    </DialogFooter>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/* ---------- Line Item Dialog ---------- */

const UNIT_OPTIONS = ['hour', 'night', 'day', 'km', 'trip', 'flat'] as const;

function LineItemDialog({
    open,
    onOpenChange,
    agreementId,
    lineItem,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    agreementId: number;
    lineItem: LineItem | null;
}) {
    const isEdit = lineItem !== null;
    const form = useForm({
        description: lineItem?.description ?? '',
        unit_price: lineItem?.unit_price?.toString() ?? '',
        unit: lineItem?.unit ?? 'hour',
        quantity: lineItem?.quantity?.toString() ?? '',
        budget_allocated: lineItem?.budget_allocated?.toString() ?? '',
        category: lineItem?.category ?? '',
        ndis_line_item_code: lineItem?.ndis_line_item_code ?? '',
    });

    // Reset form when lineItem changes
    const [prevItem, setPrevItem] = useState<LineItem | null>(null);
    if (lineItem !== prevItem) {
        setPrevItem(lineItem);
        form.setData({
            description: lineItem?.description ?? '',
            unit_price: lineItem?.unit_price?.toString() ?? '',
            unit: lineItem?.unit ?? 'hour',
            quantity: lineItem?.quantity?.toString() ?? '',
            budget_allocated: lineItem?.budget_allocated?.toString() ?? '',
            category: lineItem?.category ?? '',
            ndis_line_item_code: lineItem?.ndis_line_item_code ?? '',
        });
    }

    // Auto-calculate budget_allocated
    const unitPrice = parseFloat(form.data.unit_price) || 0;
    const quantity = parseFloat(form.data.quantity) || 0;
    const autoAllocated = (unitPrice * quantity).toFixed(2);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        const payload = {
            ...form.data,
            budget_allocated: form.data.budget_allocated || autoAllocated,
        };

        if (isEdit) {
            router.put(`/operations/service-agreements/${agreementId}/line-items/${lineItem.id}`, payload, {
                preserveScroll: true,
                onSuccess: () => { form.reset(); onOpenChange(false); },
            });
        } else {
            router.post(`/operations/service-agreements/${agreementId}/line-items`, payload, {
                preserveScroll: true,
                onSuccess: () => { form.reset(); onOpenChange(false); },
            });
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit Line Item' : 'Add Line Item'}</DialogTitle>
                    <DialogDescription>{isEdit ? 'Update the line item details.' : 'Add a new line item to this agreement.'}</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <Label htmlFor="li-description">Description *</Label>
                        <Input id="li-description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="Service description" />
                        {form.errors.description && <p className="mt-1 text-xs text-status-critical">{form.errors.description}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <Label htmlFor="li-unit-price">Unit Price *</Label>
                            <div className="relative">
                                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">$</span>
                                <Input id="li-unit-price" className="pl-7" type="number" step="0.01" min="0" value={form.data.unit_price} onChange={(e) => form.setData('unit_price', e.target.value)} />
                            </div>
                            {form.errors.unit_price && <p className="mt-1 text-xs text-status-critical">{form.errors.unit_price}</p>}
                        </div>
                        <div>
                            <Label htmlFor="li-unit">Unit *</Label>
                            <Select value={form.data.unit} onValueChange={(v) => form.setData('unit', v)}>
                                <SelectTrigger id="li-unit"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {UNIT_OPTIONS.map((u) => <SelectItem key={u} value={u}>{u}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <Label htmlFor="li-quantity">Quantity</Label>
                            <Input id="li-quantity" type="number" step="0.01" min="0" value={form.data.quantity} onChange={(e) => form.setData('quantity', e.target.value)} />
                        </div>
                        <div>
                            <Label htmlFor="li-budget">Budget Allocated</Label>
                            <div className="relative">
                                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">$</span>
                                <Input id="li-budget" className="pl-7" type="number" step="0.01" min="0" value={form.data.budget_allocated || autoAllocated} onChange={(e) => form.setData('budget_allocated', e.target.value)} placeholder={autoAllocated} />
                            </div>
                            {unitPrice > 0 && quantity > 0 && <p className="mt-1 text-[10px] text-muted-foreground">Auto: ${autoAllocated}</p>}
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <Label htmlFor="li-category">Category</Label>
                            <Input id="li-category" value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} placeholder="e.g. Core Support" />
                        </div>
                        <div>
                            <Label htmlFor="li-ndis">Funding / Contract Reference</Label>
                            <Input id="li-ndis" value={form.data.ndis_line_item_code} onChange={(e) => form.setData('ndis_line_item_code', e.target.value)} placeholder="Optional" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>{form.processing ? 'Saving...' : isEdit ? 'Update' : 'Add'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/* ---------- Rate Dialog ---------- */

const RATE_TYPE_OPTIONS = [
    { value: 'weekday', label: 'Weekday' },
    { value: 'evening', label: 'Evening' },
    { value: 'weekend', label: 'Weekend' },
    { value: 'public_holiday', label: 'Public Holiday' },
    { value: 'sleepover', label: 'Sleepover' },
    { value: 'active_night', label: 'Active Night' },
    { value: 'overtime', label: 'Overtime' },
    { value: 'travel', label: 'Travel' },
    { value: 'mileage', label: 'Mileage' },
] as const;

function RateDialog({
    open,
    onOpenChange,
    agreementId,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    agreementId: number;
}) {
    const form = useForm({
        rate_type: 'weekday',
        rate: '',
        unit: 'hour',
        effective_from: '',
        effective_to: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        router.post(`/operations/service-agreements/${agreementId}/rates`, form.data, {
            preserveScroll: true,
            onSuccess: () => { form.reset(); onOpenChange(false); },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add Rate</DialogTitle>
                    <DialogDescription>Define a rate for this service agreement.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <Label htmlFor="rate-type">Rate Type *</Label>
                        <Select value={form.data.rate_type} onValueChange={(v) => form.setData('rate_type', v)}>
                            <SelectTrigger id="rate-type"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                {RATE_TYPE_OPTIONS.map((rt) => <SelectItem key={rt.value} value={rt.value}>{rt.label}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <Label htmlFor="rate-amount">Rate *</Label>
                            <div className="relative">
                                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">$</span>
                                <Input id="rate-amount" className="pl-7" type="number" step="0.01" min="0" value={form.data.rate} onChange={(e) => form.setData('rate', e.target.value)} />
                            </div>
                            {form.errors.rate && <p className="mt-1 text-xs text-status-critical">{form.errors.rate}</p>}
                        </div>
                        <div>
                            <Label htmlFor="rate-unit">Unit *</Label>
                            <Select value={form.data.unit} onValueChange={(v) => form.setData('unit', v)}>
                                <SelectTrigger id="rate-unit"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {UNIT_OPTIONS.map((u) => <SelectItem key={u} value={u}>{u}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <Label htmlFor="rate-from">Effective From</Label>
                            <Input id="rate-from" type="date" value={form.data.effective_from} onChange={(e) => form.setData('effective_from', e.target.value)} />
                        </div>
                        <div>
                            <Label htmlFor="rate-to">Effective To</Label>
                            <Input id="rate-to" type="date" value={form.data.effective_to} onChange={(e) => form.setData('effective_to', e.target.value)} />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>{form.processing ? 'Saving...' : 'Add Rate'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/* ---------- Main Component ---------- */

export default function ServiceAgreementShow({ agreement: ag, budget_summary }: Props) {
    const bs = budget_summary ?? { total_budget: ag.total_budget, budget_used: ag.budget_used, budget_allocated: 0, budget_remaining: ag.budget_remaining, utilisation_percent: ag.budget_utilisation_percent };
    const utilPct = bs.utilisation_percent;
    const [dialogOpen, setDialogOpen] = useState(false);
    const [activeTransition, setActiveTransition] = useState<TransitionDef | null>(null);
    const [rejectDialogOpen, setRejectDialogOpen] = useState(false);
    const transitions = getTransitions(ag.status);
    const rejectForm = useForm({ reason: '' });

    // Line Item CRUD state
    const [lineItemDialogOpen, setLineItemDialogOpen] = useState(false);
    const [editingLineItem, setEditingLineItem] = useState<LineItem | null>(null);
    const [deleteLineItemDialogOpen, setDeleteLineItemDialogOpen] = useState(false);
    const [deletingLineItemId, setDeletingLineItemId] = useState<number | null>(null);

    // Rate CRUD state
    const [rateDialogOpen, setRateDialogOpen] = useState(false);
    const [deleteRateDialogOpen, setDeleteRateDialogOpen] = useState(false);
    const [deletingRateId, setDeletingRateId] = useState<number | null>(null);

    function openTransition(t: TransitionDef) {
        setActiveTransition(t);
        setDialogOpen(true);
    }

    const milestoneDates = [
        { label: 'NASC Assessment', value: ag.nasc_assessment_date, icon: <FileText className="h-4 w-4 text-status-info" /> },
        { label: 'Funding Approved', value: ag.funding_approved_date, icon: <DollarSign className="h-4 w-4 text-status-success" /> },
        { label: 'Signed', value: ag.signed_date, icon: <ShieldCheck className="h-4 w-4 text-primary" /> },
        { label: 'First Service', value: ag.first_service_date, icon: <Play className="h-4 w-4 text-status-info" /> },
        { label: 'Review Due', value: ag.review_due_date, icon: <Clock className="h-4 w-4 text-status-warning" /> },
        { label: 'Renewal', value: ag.renewal_date, icon: <RefreshCw className="h-4 w-4 text-primary" /> },
    ];

    return (
        <AppLayout>
            <Head title={ag.title} />
            <PageHero variant="compact" title={ag.title} description={ag.client ? `${ag.client.first_name} ${ag.client.last_name}` : ''} backHref="/operations/service-agreements" />
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
                        {ag.status === 'pending_approval' ? (
                            <>
                                <Button
                                    size="sm"
                                    className="bg-status-success hover:bg-status-success"
                                    onClick={() => router.post(`/operations/service-agreements/${ag.id}/approve`)}
                                >
                                    <ShieldCheck className="mr-1.5 h-3.5 w-3.5" />
                                    Approve
                                </Button>
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    onClick={() => setRejectDialogOpen(true)}
                                >
                                    <XCircle className="mr-1.5 h-3.5 w-3.5" />
                                    Reject
                                </Button>
                            </>
                        ) : (
                            transitions.map((t) => (
                                <Button key={t.toStatus} size="sm" variant={t.variant} onClick={() => openTransition(t)}>
                                    {t.icon}
                                    {t.label}
                                </Button>
                            ))
                        )}
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
                            <CalendarDays className="h-4 w-4 text-primary" />
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
                        {/* NASC Details */}
                        {(ag.nasc_assessor_name || ag.nasc_support_package_ref || ag.support_needs_level) && (
                            <div className="mt-3 grid gap-3 sm:grid-cols-3">
                                {ag.nasc_assessor_name && (
                                    <div className="flex items-center gap-3 rounded-lg border border-status-info/30 bg-status-info-bg p-3">
                                        <UserCheck className="h-4 w-4 text-status-info" />
                                        <div>
                                            <div className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">NASC Assessor</div>
                                            <div className="text-sm font-medium">{ag.nasc_assessor_name}</div>
                                        </div>
                                    </div>
                                )}
                                {ag.nasc_support_package_ref && (
                                    <div className="flex items-center gap-3 rounded-lg border border-status-info/30 bg-status-info-bg p-3">
                                        <FileText className="h-4 w-4 text-status-info" />
                                        <div>
                                            <div className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Package Ref</div>
                                            <div className="text-sm font-medium">{ag.nasc_support_package_ref}</div>
                                        </div>
                                    </div>
                                )}
                                {ag.support_needs_level && (
                                    <div className="flex items-center gap-3 rounded-lg border border-status-info/30 bg-status-info-bg p-3">
                                        <ShieldCheck className="h-4 w-4 text-status-info" />
                                        <div>
                                            <div className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Support Needs</div>
                                            <div className="text-sm font-medium">{SUPPORT_NEEDS_LABELS[ag.support_needs_level] ?? ag.support_needs_level}</div>
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Terminated/Suspended info */}
                        {ag.terminated_at && (
                            <div className="mt-3 rounded-lg border border-status-critical/30 bg-status-critical-bg p-3">
                                <div className="flex items-center gap-2 text-sm font-medium text-status-critical">
                                    <XCircle className="h-4 w-4" /> Terminated on {formatDateTime(ag.terminated_at)}
                                </div>
                                {ag.terminated_reason && <p className="mt-1 text-xs text-status-critical">{ag.terminated_reason}</p>}
                            </div>
                        )}
                        {ag.suspended_at && !ag.resumed_at && (
                            <div className="mt-3 rounded-lg border border-status-warning/30 bg-status-warning-bg p-3">
                                <div className="flex items-center gap-2 text-sm font-medium text-status-warning">
                                    <AlertTriangle className="h-4 w-4" /> Suspended on {formatDateTime(ag.suspended_at)}
                                </div>
                                {ag.suspended_reason && <p className="mt-1 text-xs text-status-warning">{ag.suspended_reason}</p>}
                            </div>
                        )}
                        {ag.resumed_at && (
                            <div className="mt-3 rounded-lg border border-status-success/30 bg-status-success-bg p-3">
                                <div className="flex items-center gap-2 text-sm font-medium text-status-success">
                                    <Play className="h-4 w-4" /> Resumed on {formatDateTime(ag.resumed_at)}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Funding Details */}
                {(ag.funding_type || ag.service_level || ag.whaikaha_reference || (ag.total_hours != null && Number(ag.total_hours) > 0)) && (
                    <Card className="mt-4">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <Landmark className="h-4 w-4 text-primary" />
                                Funding Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {/* Left: details */}
                                <div className="space-y-3 sm:col-span-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        {ag.funding_type && (
                                            <span className="inline-flex items-center rounded-full border border-primary bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary">
                                                {FUNDING_TYPE_LABELS[ag.funding_type] ?? ag.funding_type}
                                            </span>
                                        )}
                                        {ag.service_level && (
                                            <span className="inline-flex items-center rounded-full border border-primary bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary">
                                                {SERVICE_LEVEL_LABELS[ag.service_level] ?? ag.service_level}
                                            </span>
                                        )}
                                        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${ag.gst_inclusive ? 'border-status-success/30 bg-status-success-bg text-status-success' : 'border-border bg-muted text-muted-foreground'}`}>
                                            {ag.gst_inclusive ? 'GST Inclusive' : 'GST Exclusive'}
                                        </span>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        {ag.allocated_hours_per_week != null && Number(ag.allocated_hours_per_week) > 0 && (
                                            <div className="rounded-lg border bg-muted/30 p-3">
                                                <div className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Hours / Week</div>
                                                <div className="text-lg font-semibold tabular-nums">{ag.allocated_hours_per_week}</div>
                                            </div>
                                        )}
                                        {ag.total_hours != null && Number(ag.total_hours) > 0 && (
                                            <div className="rounded-lg border bg-muted/30 p-3">
                                                <div className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Total Hours</div>
                                                <div className="text-lg font-semibold tabular-nums">{ag.total_hours}</div>
                                            </div>
                                        )}
                                        {ag.hours_remaining != null && (
                                            <div className="rounded-lg border bg-muted/30 p-3">
                                                <div className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Hours Remaining</div>
                                                <div className="text-lg font-semibold tabular-nums text-status-success">{ag.hours_remaining}</div>
                                            </div>
                                        )}
                                        {ag.whaikaha_reference && (
                                            <div className="rounded-lg border bg-muted/30 p-3">
                                                <div className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Whaikaha Ref</div>
                                                <div className="text-sm font-medium">{ag.whaikaha_reference}</div>
                                            </div>
                                        )}
                                    </div>
                                </div>
                                {/* Right: hours donut */}
                                {ag.total_hours != null && Number(ag.total_hours) > 0 && (
                                    <div className="flex flex-col items-center justify-center">
                                        {(() => {
                                            const hoursUsed = ag.hours_used ?? 0;
                                            const hoursTotal = Number(ag.total_hours);
                                            const hoursRemaining = Math.max(hoursTotal - hoursUsed, 0);
                                            const hoursPct = ag.hours_utilisation_percent ?? (hoursTotal > 0 ? Math.round((hoursUsed / hoursTotal) * 100) : 0);
                                            return (
                                                <>
                                                    <DonutChart
                                                        segments={[
                                                            {
                                                                label: 'Used',
                                                                value: hoursUsed,
                                                                color: hoursPct > 90 ? OPS_COLORS.danger : hoursPct > 70 ? OPS_COLORS.warning : OPS_COLORS.primary,
                                                            },
                                                            { label: 'Remaining', value: hoursRemaining, color: '#e2e8f0' },
                                                        ]}
                                                        centerValue={`${hoursPct}%`}
                                                        centerLabel="HOURS"
                                                        size={110}
                                                        strokeWidth={14}
                                                    />
                                                    <p className="mt-1.5 text-[10px] text-muted-foreground">{hoursUsed} / {hoursTotal} hrs</p>
                                                </>
                                            );
                                        })()}
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Budget + Line Items */}
                <div className="mt-4 grid gap-4 lg:grid-cols-3">
                    {/* Budget gauge */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <DollarSign className="h-4 w-4 text-status-success" />
                                Budget
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-col items-center gap-3">
                                <DonutChart
                                    segments={[
                                        {
                                            label: 'Used',
                                            value: bs.budget_used,
                                            color: utilPct > 90 ? OPS_COLORS.danger : utilPct > 70 ? OPS_COLORS.warning : OPS_COLORS.primary,
                                        },
                                        { label: 'Remaining', value: bs.budget_remaining > 0 ? bs.budget_remaining : 0, color: '#e2e8f0' },
                                    ]}
                                    centerValue={`${utilPct}%`}
                                    centerLabel="USED"
                                    size={130}
                                    strokeWidth={16}
                                />
                                {/* Progress bar */}
                                <div className="w-full">
                                    <div className="mb-1 flex justify-between text-[10px] text-muted-foreground">
                                        <span>{formatCurrency(bs.budget_used)} used</span>
                                        <span>{formatCurrency(bs.total_budget)} total</span>
                                    </div>
                                    <div className="h-2.5 w-full rounded-full bg-muted">
                                        <div
                                            className={`h-2.5 rounded-full transition-all ${utilPct > 90 ? 'bg-status-critical' : utilPct > 70 ? 'bg-status-warning' : 'bg-status-success'}`}
                                            style={{ width: `${Math.min(utilPct, 100)}%` }}
                                        />
                                    </div>
                                </div>
                                <div className="w-full space-y-1 text-xs">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Total Budget</span>
                                        <span className="font-medium">{formatCurrency(bs.total_budget)}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Used</span>
                                        <span className={`font-medium ${utilPct > 90 ? 'text-status-critical' : utilPct > 70 ? 'text-status-warning' : ''}`}>{formatCurrency(bs.budget_used)}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Allocated (Line Items)</span>
                                        <span className="font-medium">{formatCurrency(bs.budget_allocated)}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Remaining</span>
                                        <span className="font-medium text-status-success">{formatCurrency(bs.budget_remaining)}</span>
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
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                    <FileText className="h-4 w-4 text-primary" />
                                    Line Items ({ag.line_items?.length ?? 0})
                                </CardTitle>
                                <Button size="sm" variant="outline" onClick={() => { setEditingLineItem(null); setLineItemDialogOpen(true); }}>
                                    <Plus className="mr-1.5 h-3.5 w-3.5" /> Add Line Item
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {!ag.line_items || ag.line_items.length === 0 ? (
                                <p className="py-4 text-center text-xs text-muted-foreground">No line items added yet.</p>
                            ) : (
                                <div className="space-y-2">
                                    {ag.line_items.map((item) => {
                                        const itemPct = item.budget_allocated > 0 ? Math.round((item.budget_used / item.budget_allocated) * 100) : 0;
                                        const barColor = itemPct > 90 ? 'bg-status-critical' : itemPct > 70 ? 'bg-status-warning' : 'bg-status-success';
                                        return (
                                            <div key={item.id} className="rounded-lg border p-3 space-y-2">
                                                <div className="flex items-start justify-between">
                                                    <div>
                                                        <div className="text-sm font-medium">{item.description}</div>
                                                        <div className="text-[11px] text-muted-foreground">
                                                            {formatCurrency(item.unit_price)}/{item.unit}
                                                            {item.quantity != null && <span className="ml-1">x {item.quantity}</span>}
                                                            {item.category && <span className="ml-2 text-status-info">{item.category}</span>}
                                                            {item.ndis_line_item_code && <span className="ml-2 text-primary">#{item.ndis_line_item_code}</span>}
                                                        </div>
                                                    </div>
                                                    <div className="flex items-start gap-2">
                                                        <div className="text-right">
                                                            <div className={`text-sm font-semibold tabular-nums ${itemPct > 90 ? 'text-status-critical' : itemPct > 70 ? 'text-status-warning' : 'text-foreground'}`}>{itemPct}%</div>
                                                            <div className="text-[10px] text-muted-foreground">{formatCurrency(item.budget_used)} / {formatCurrency(item.budget_allocated)}</div>
                                                        </div>
                                                        <div className="flex gap-1">
                                                            <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => { setEditingLineItem(item); setLineItemDialogOpen(true); }}>
                                                                <Pencil className="h-3 w-3" />
                                                            </Button>
                                                            <Button size="icon" variant="ghost" className="h-7 w-7 text-status-critical hover:text-status-critical" onClick={() => { setDeletingLineItemId(item.id); setDeleteLineItemDialogOpen(true); }}>
                                                                <Trash2 className="h-3 w-3" />
                                                            </Button>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="h-1.5 w-full rounded-full bg-muted">
                                                    <div className={`h-1.5 rounded-full transition-all ${barColor}`} style={{ width: `${Math.min(itemPct, 100)}%` }} />
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Rates Section */}
                <Card className="mt-4">
                    <CardHeader className="pb-2">
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <DollarSign className="h-4 w-4 text-status-success" />
                                Rate Structure ({ag.rates?.length ?? 0})
                            </CardTitle>
                            <Button size="sm" variant="outline" onClick={() => setRateDialogOpen(true)}>
                                <Plus className="mr-1.5 h-3.5 w-3.5" /> Add Rate
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {!ag.rates || ag.rates.length === 0 ? (
                            <p className="py-4 text-center text-xs text-muted-foreground">No rates defined yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-xs text-muted-foreground">
                                            <th className="pb-2 pr-4 font-medium">Type</th>
                                            <th className="pb-2 pr-4 font-medium">Rate</th>
                                            <th className="pb-2 pr-4 font-medium">Unit</th>
                                            <th className="pb-2 pr-4 font-medium">Effective From</th>
                                            <th className="pb-2 pr-4 font-medium">Effective To</th>
                                            <th className="pb-2 font-medium"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {ag.rates.map((rate) => (
                                            <tr key={rate.id} className="border-b last:border-0">
                                                <td className="py-2 pr-4 capitalize">{rate.rate_type.replace(/_/g, ' ')}</td>
                                                <td className="py-2 pr-4 tabular-nums">{formatCurrency(rate.rate)}</td>
                                                <td className="py-2 pr-4">{rate.unit}</td>
                                                <td className="py-2 pr-4">{formatDate(rate.effective_from)}</td>
                                                <td className="py-2 pr-4">{formatDate(rate.effective_to)}</td>
                                                <td className="py-2">
                                                    <Button size="icon" variant="ghost" className="h-7 w-7 text-status-critical hover:text-status-critical" onClick={() => { setDeletingRateId(rate.id); setDeleteRateDialogOpen(true); }}>
                                                        <Trash2 className="h-3 w-3" />
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Audit Trail */}
                <Card className="mt-4">
                    <CardHeader className="pb-2">
                        <CardTitle className="flex items-center gap-2 text-sm font-medium">
                            <History className="h-4 w-4 text-primary" />
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
                                        <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                            <ArrowRight className="h-4 w-4 text-primary" />
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

                {/* Signatories */}
                {(ag.client_signatory || ag.provider_signatory || ag.funder_contact_name) && (
                    <Card className="mt-4">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <PenLine className="h-4 w-4 text-primary" />
                                Signatories & Contacts
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-3 sm:grid-cols-3">
                                {ag.client_signatory && (
                                    <div className="rounded-lg border bg-muted/30 p-3">
                                        <div className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Client Signatory</div>
                                        <div className="text-sm font-medium">{ag.client_signatory}</div>
                                    </div>
                                )}
                                {ag.provider_signatory && (
                                    <div className="rounded-lg border bg-muted/30 p-3">
                                        <div className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Provider Signatory</div>
                                        <div className="text-sm font-medium">{ag.provider_signatory}</div>
                                    </div>
                                )}
                                {ag.funder_contact_name && (
                                    <div className="rounded-lg border bg-muted/30 p-3">
                                        <div className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Funder Contact</div>
                                        <div className="text-sm font-medium">{ag.funder_contact_name}</div>
                                        {ag.funder_contact_email && (
                                            <div className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                                                <Mail className="h-3 w-3" /> {ag.funder_contact_email}
                                            </div>
                                        )}
                                        {ag.funder_contact_phone && (
                                            <div className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                                <Phone className="h-3 w-3" /> {ag.funder_contact_phone}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Related Records */}
                <Card className="mt-4">
                    <CardHeader className="pb-2">
                        <CardTitle className="flex items-center gap-2 text-sm font-medium">
                            <Link2 className="h-4 w-4 text-primary" />
                            Related Records
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 sm:grid-cols-3">
                            {/* Funding Claims */}
                            <div className="rounded-lg border p-4">
                                <div className="flex items-center gap-2">
                                    <Receipt className="h-4 w-4 text-primary" />
                                    <h4 className="text-sm font-semibold">Funding Claims</h4>
                                </div>
                                <p className="mt-2 text-2xl font-bold tabular-nums">{ag.funding_claims_count ?? 0}</p>
                                <p className="text-[10px] text-muted-foreground">total claims</p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Button asChild size="sm" variant="outline" className="h-7 text-xs">
                                        <Link href={`/operations/funding/claims?agreement_id=${ag.id}`}>
                                            <ExternalLink className="mr-1 h-3 w-3" /> View Claims
                                        </Link>
                                    </Button>
                                    <Button asChild size="sm" className="h-7 bg-primary text-xs hover:bg-primary">
                                        <Link href={`/operations/funding/claims/create?agreement_id=${ag.id}`}>
                                            <Plus className="mr-1 h-3 w-3" /> Create Claim
                                        </Link>
                                    </Button>
                                </div>
                            </div>

                            {/* Linked Shifts */}
                            <div className="rounded-lg border border-dashed p-4">
                                <div className="flex items-center gap-2">
                                    <Timer className="h-4 w-4 text-muted-foreground" />
                                    <h4 className="text-sm font-semibold text-muted-foreground">Linked Shifts</h4>
                                </div>
                                <p className="mt-2 text-xs text-muted-foreground">
                                    Shift integration coming soon &mdash; shifts will automatically track budget usage.
                                </p>
                            </div>

                            {/* Invoices */}
                            <div className="rounded-lg border p-4">
                                <div className="flex items-center gap-2">
                                    <DollarSign className="h-4 w-4 text-status-success" />
                                    <h4 className="text-sm font-semibold">Invoices</h4>
                                </div>
                                <p className="mt-2 text-xs text-muted-foreground">View related invoices for this client.</p>
                                <div className="mt-3">
                                    <Button asChild size="sm" variant="outline" className="h-7 text-xs">
                                        <Link href={`/finance/invoices?client_id=${ag.client_id ?? ag.client?.id}`}>
                                            <ExternalLink className="mr-1 h-3 w-3" /> View Invoices
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        </div>
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

                {/* Line Item Dialog */}
                <LineItemDialog
                    open={lineItemDialogOpen}
                    onOpenChange={(v) => { setLineItemDialogOpen(v); if (!v) setEditingLineItem(null); }}
                    agreementId={ag.id}
                    lineItem={editingLineItem}
                />

                {/* Delete Line Item Confirmation */}
                <Dialog open={deleteLineItemDialogOpen} onOpenChange={setDeleteLineItemDialogOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Delete Line Item</DialogTitle>
                            <DialogDescription>Are you sure you want to delete this line item? This action cannot be undone.</DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDeleteLineItemDialogOpen(false)}>Cancel</Button>
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() => {
                                    if (deletingLineItemId) {
                                        router.delete(`/operations/service-agreements/${ag.id}/line-items/${deletingLineItemId}`, {
                                            preserveScroll: true,
                                            onSuccess: () => { setDeleteLineItemDialogOpen(false); setDeletingLineItemId(null); },
                                        });
                                    }
                                }}
                            >
                                Delete
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Rate Dialog */}
                <RateDialog open={rateDialogOpen} onOpenChange={setRateDialogOpen} agreementId={ag.id} />

                {/* Delete Rate Confirmation */}
                <Dialog open={deleteRateDialogOpen} onOpenChange={setDeleteRateDialogOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Delete Rate</DialogTitle>
                            <DialogDescription>Are you sure you want to delete this rate? This action cannot be undone.</DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDeleteRateDialogOpen(false)}>Cancel</Button>
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() => {
                                    if (deletingRateId) {
                                        router.delete(`/operations/service-agreements/${ag.id}/rates/${deletingRateId}`, {
                                            preserveScroll: true,
                                            onSuccess: () => { setDeleteRateDialogOpen(false); setDeletingRateId(null); },
                                        });
                                    }
                                }}
                            >
                                Delete
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Reject Dialog */}
                <Dialog open={rejectDialogOpen} onOpenChange={setRejectDialogOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Reject Agreement</DialogTitle>
                            <DialogDescription>
                                Return this agreement to <strong>draft</strong> status. Provide a reason so the author can make changes.
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                rejectForm.post(`/operations/service-agreements/${ag.id}/reject`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        rejectForm.reset();
                                        setRejectDialogOpen(false);
                                    },
                                });
                            }}
                            className="space-y-4"
                        >
                            <div>
                                <Label htmlFor="reject-reason">Reason for Rejection</Label>
                                <Textarea
                                    id="reject-reason"
                                    value={rejectForm.data.reason}
                                    onChange={(e) => rejectForm.setData('reason', e.target.value)}
                                    placeholder="Why is this agreement being returned?"
                                    rows={3}
                                />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setRejectDialogOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" variant="destructive" disabled={rejectForm.processing}>
                                    {rejectForm.processing ? 'Rejecting...' : 'Reject & Return to Draft'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </AppLayout>
    );
}
