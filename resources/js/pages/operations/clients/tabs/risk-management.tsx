import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    AlertOctagon,
    CalendarClock,
    Pencil,
    Plus,
    ShieldAlert,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';

export type ClientRiskItem = {
    id: number;
    label?: string | null;
    severity?: string | null;
    controls?: string | null;
    review_date?: string | null;
    active?: boolean;
};

type RiskManagementTabProps = {
    clientId: number;
    risks?: ClientRiskItem[];
    canCreate?: boolean;
    canUpdate?: boolean;
    canDelete?: boolean;
};

const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low'] as const;

const SEVERITY_STYLES: Record<string, { badge: string; ring: string }> = {
    critical: {
        badge: 'bg-status-critical-bg text-status-critical',
        ring: 'border-status-critical/30',
    },
    high: {
        badge: 'bg-status-warning-bg text-status-warning',
        ring: 'border-status-warning/30',
    },
    medium: {
        badge: 'bg-status-info-bg text-status-info',
        ring: 'border-status-info/30',
    },
    low: {
        badge: 'bg-status-success-bg text-status-success',
        ring: 'border-status-success/30',
    },
};

function severityStyle(severity?: string | null) {
    const key = String(severity ?? '').toLowerCase();
    return (
        SEVERITY_STYLES[key] ?? {
            badge: 'bg-muted text-muted-foreground',
            ring: '',
        }
    );
}

function reviewState(value?: string | null): 'overdue' | 'soon' | null {
    if (!value) return null;
    const ts = new Date(value).getTime();
    if (Number.isNaN(ts)) return null;
    const delta = ts - Date.now();
    if (delta < 0) return 'overdue';
    if (delta < 30 * 86400000) return 'soon';
    return null;
}

function reviewLabel(value?: string | null): string {
    if (!value) return '—';
    try {
        return new Intl.DateTimeFormat('en-NZ', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }).format(new Date(value));
    } catch {
        return value;
    }
}

type EditorMode = 'create' | 'edit' | null;

type FormState = {
    label: string;
    severity: string;
    controls: string;
    review_date: string;
    active: boolean;
};

const EMPTY_FORM: FormState = {
    label: '',
    severity: 'medium',
    controls: '',
    review_date: '',
    active: true,
};

export function RiskManagementTab({
    clientId,
    risks,
    canCreate = false,
    canUpdate = false,
    canDelete = false,
}: RiskManagementTabProps) {
    const list = useMemo(() => risks ?? [], [risks]);

    const [statusFilter, setStatusFilter] = useState<
        'all' | 'active' | 'inactive'
    >('active');
    const [severityFilter, setSeverityFilter] = useState<string>('all');

    const [editorMode, setEditorMode] = useState<EditorMode>(null);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const [submitting, setSubmitting] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);

    const filtered = useMemo(() => {
        return list
            .filter((r) => {
                if (statusFilter === 'active' && !r.active) return false;
                if (statusFilter === 'inactive' && r.active) return false;
                if (
                    severityFilter !== 'all' &&
                    String(r.severity ?? '').toLowerCase() !== severityFilter
                ) {
                    return false;
                }
                return true;
            })
            .sort((a, b) => {
                if ((a.active ? 1 : 0) !== (b.active ? 1 : 0)) {
                    return a.active ? -1 : 1;
                }
                const ai = SEVERITY_ORDER.indexOf(
                    String(a.severity ?? '').toLowerCase() as any,
                );
                const bi = SEVERITY_ORDER.indexOf(
                    String(b.severity ?? '').toLowerCase() as any,
                );
                const aiVal = ai === -1 ? SEVERITY_ORDER.length : ai;
                const biVal = bi === -1 ? SEVERITY_ORDER.length : bi;
                if (aiVal !== biVal) return aiVal - biVal;
                return String(a.label ?? '').localeCompare(
                    String(b.label ?? ''),
                );
            });
    }, [list, statusFilter, severityFilter]);

    const activeRisks = list.filter((r) => r.active);
    const criticalActive = activeRisks.filter(
        (r) => String(r.severity ?? '').toLowerCase() === 'critical',
    );
    const reviewOverdue = activeRisks.filter(
        (r) => reviewState(r.review_date) === 'overdue',
    );
    const reviewSoon = activeRisks.filter(
        (r) => reviewState(r.review_date) === 'soon',
    );

    const openCreate = () => {
        setEditingId(null);
        setForm(EMPTY_FORM);
        setEditorMode('create');
    };

    const openEdit = (risk: ClientRiskItem) => {
        setEditingId(risk.id);
        setForm({
            label: risk.label ?? '',
            severity: String(risk.severity ?? 'medium').toLowerCase(),
            controls: risk.controls ?? '',
            review_date: risk.review_date ?? '',
            active: Boolean(risk.active),
        });
        setEditorMode('edit');
    };

    const closeEditor = () => {
        setEditorMode(null);
        setEditingId(null);
        setForm(EMPTY_FORM);
        setSubmitting(false);
    };

    const submit = () => {
        if (submitting) return;
        if (!form.label.trim()) return;
        setSubmitting(true);
        const payload = {
            label: form.label.trim(),
            severity: form.severity,
            controls: form.controls,
            review_date: form.review_date || null,
            active: form.active,
        };
        const opts = {
            preserveScroll: true,
            onFinish: () => setSubmitting(false),
            onSuccess: closeEditor,
        };
        if (editorMode === 'create') {
            router.post(`/operations/clients/${clientId}/risks`, payload, opts);
        } else if (editorMode === 'edit' && editingId) {
            router.put(
                `/operations/clients/${clientId}/risks/${editingId}`,
                payload,
                opts,
            );
        }
    };

    const remove = (risk: ClientRiskItem) => {
        if (deletingId) return;
        if (
            !window.confirm(
                `Remove the risk “${risk.label ?? 'Unlabelled risk'}” from this client?`,
            )
        ) {
            return;
        }
        setDeletingId(risk.id);
        router.delete(`/operations/clients/${clientId}/risks/${risk.id}`, {
            preserveScroll: true,
            onFinish: () => setDeletingId(null),
        });
    };

    return (
        <div className="space-y-4">
            {/* Stat strip */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div className="rounded-xl border bg-primary/10 p-3 text-center">
                    <div className="text-xl font-bold text-primary">
                        {activeRisks.length}
                    </div>
                    <div className="text-[10px] tracking-wider text-primary uppercase">
                        Active risks
                    </div>
                </div>
                <div className="rounded-xl border bg-status-critical-bg p-3 text-center">
                    <div className="text-xl font-bold text-status-critical">
                        {criticalActive.length}
                    </div>
                    <div className="text-[10px] tracking-wider text-status-critical uppercase">
                        Critical
                    </div>
                </div>
                <div className="rounded-xl border bg-status-warning-bg p-3 text-center">
                    <div className="text-xl font-bold text-status-warning">
                        {reviewOverdue.length}
                    </div>
                    <div className="text-[10px] tracking-wider text-status-warning uppercase">
                        Reviews overdue
                    </div>
                </div>
                <div className="rounded-xl border bg-status-info-bg p-3 text-center">
                    <div className="text-xl font-bold text-status-info">
                        {reviewSoon.length}
                    </div>
                    <div className="text-[10px] tracking-wider text-status-info uppercase">
                        Review in 30 d
                    </div>
                </div>
            </div>

            {/* Filter + add bar */}
            <Card className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center">
                <Select
                    value={statusFilter}
                    onValueChange={(v) =>
                        setStatusFilter(v as 'all' | 'active' | 'inactive')
                    }
                >
                    <SelectTrigger className="h-9 w-full text-xs sm:w-[140px]">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="active">Active only</SelectItem>
                        <SelectItem value="inactive">Inactive only</SelectItem>
                        <SelectItem value="all">All risks</SelectItem>
                    </SelectContent>
                </Select>
                <Select
                    value={severityFilter}
                    onValueChange={setSeverityFilter}
                >
                    <SelectTrigger className="h-9 w-full text-xs sm:w-[160px]">
                        <SelectValue placeholder="Any severity" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Any severity</SelectItem>
                        {SEVERITY_ORDER.map((s) => (
                            <SelectItem key={s} value={s}>
                                {s.charAt(0).toUpperCase() + s.slice(1)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <div className="flex-1" />
                {canCreate ? (
                    <Button
                        size="sm"
                        className="gap-1.5"
                        onClick={openCreate}
                    >
                        <Plus className="h-4 w-4" />
                        Add risk
                    </Button>
                ) : null}
            </Card>

            {/* Risk cards */}
            {filtered.length === 0 ? (
                <EmptyState
                    icon={list.length === 0 ? ShieldCheck : AlertOctagon}
                    title={
                        list.length === 0
                            ? 'No risks recorded'
                            : 'No risks match your filters'
                    }
                    description={
                        list.length === 0
                            ? 'Add a risk to start building this client’s safety profile.'
                            : 'Try widening the status or severity filters.'
                    }
                    action={
                        list.length === 0 && canCreate ? (
                            <Button size="sm" onClick={openCreate}>
                                <Plus className="mr-1 h-4 w-4" />
                                Add risk
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <div className="space-y-2">
                    {filtered.map((risk) => {
                        const sty = severityStyle(risk.severity);
                        const review = reviewState(risk.review_date);
                        return (
                            <Card
                                key={risk.id}
                                className={cn(
                                    sty.ring,
                                    !risk.active && 'opacity-60',
                                )}
                            >
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <ShieldAlert className="h-4 w-4 text-muted-foreground" />
                                                    <span className="font-semibold">
                                                        {risk.label ??
                                                            'Unlabelled risk'}
                                                    </span>
                                                    <Badge
                                                        className={cn(
                                                            'border-0 text-[10px]',
                                                            sty.badge,
                                                        )}
                                                    >
                                                        {String(
                                                            risk.severity ??
                                                                'unknown',
                                                        )}
                                                    </Badge>
                                                    {!risk.active ? (
                                                        <Badge
                                                            variant="secondary"
                                                            className="text-[10px]"
                                                        >
                                                            Inactive
                                                        </Badge>
                                                    ) : null}
                                                    {review === 'overdue' ? (
                                                        <Badge className="border-0 bg-status-warning-bg text-[10px] text-status-warning">
                                                            Review overdue
                                                        </Badge>
                                                    ) : null}
                                                    {review === 'soon' ? (
                                                        <Badge className="border-0 bg-status-info-bg text-[10px] text-status-info">
                                                            Review soon
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                                <div className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                                                    <CalendarClock className="h-3 w-3" />
                                                    Next review:{' '}
                                                    {reviewLabel(
                                                        risk.review_date,
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-1">
                                                {canUpdate ? (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            openEdit(risk)
                                                        }
                                                    >
                                                        <Pencil className="mr-1 h-3 w-3" />
                                                        Edit
                                                    </Button>
                                                ) : null}
                                                {canDelete ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={
                                                            deletingId ===
                                                            risk.id
                                                        }
                                                        onClick={() =>
                                                            remove(risk)
                                                        }
                                                    >
                                                        <Trash2 className="h-3 w-3" />
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </div>
                                    </CardTitle>
                                </CardHeader>
                                {risk.controls ? (
                                    <CardContent className="pt-0">
                                        <div className="text-xs text-muted-foreground">
                                            Controls
                                        </div>
                                        <div className="mt-1 text-sm whitespace-pre-wrap">
                                            {risk.controls}
                                        </div>
                                    </CardContent>
                                ) : null}
                            </Card>
                        );
                    })}
                </div>
            )}

            <Dialog
                open={editorMode !== null}
                onOpenChange={(open) => {
                    if (!open) closeEditor();
                }}
            >
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editorMode === 'create'
                                ? 'Add risk'
                                : 'Edit risk'}
                        </DialogTitle>
                        <DialogDescription>
                            Capture the hazard, severity, mitigating controls,
                            and the next scheduled review.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="space-y-1">
                            <Label htmlFor="risk-label">Label</Label>
                            <Input
                                id="risk-label"
                                value={form.label}
                                onChange={(e) =>
                                    setForm((f) => ({
                                        ...f,
                                        label: e.target.value,
                                    }))
                                }
                                placeholder="e.g. Falls risk in bathroom"
                            />
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="risk-severity">Severity</Label>
                                <Select
                                    value={form.severity}
                                    onValueChange={(v) =>
                                        setForm((f) => ({
                                            ...f,
                                            severity: v,
                                        }))
                                    }
                                >
                                    <SelectTrigger id="risk-severity">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {SEVERITY_ORDER.map((s) => (
                                            <SelectItem key={s} value={s}>
                                                {s.charAt(0).toUpperCase() +
                                                    s.slice(1)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="risk-review-date">
                                    Next review date
                                </Label>
                                <Input
                                    id="risk-review-date"
                                    type="date"
                                    value={form.review_date}
                                    onChange={(e) =>
                                        setForm((f) => ({
                                            ...f,
                                            review_date: e.target.value,
                                        }))
                                    }
                                />
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="risk-controls">Controls</Label>
                            <Textarea
                                id="risk-controls"
                                rows={4}
                                value={form.controls}
                                onChange={(e) =>
                                    setForm((f) => ({
                                        ...f,
                                        controls: e.target.value,
                                    }))
                                }
                                placeholder="Equipment, staff training, environmental adjustments…"
                            />
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="risk-active"
                                checked={form.active}
                                onCheckedChange={(v) =>
                                    setForm((f) => ({
                                        ...f,
                                        active: Boolean(v),
                                    }))
                                }
                            />
                            <Label
                                htmlFor="risk-active"
                                className="text-sm font-normal"
                            >
                                Active
                            </Label>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={closeEditor}>
                            Cancel
                        </Button>
                        <Button
                            onClick={submit}
                            disabled={submitting || !form.label.trim()}
                        >
                            {submitting
                                ? 'Saving…'
                                : editorMode === 'create'
                                  ? 'Add risk'
                                  : 'Save risk'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export default RiskManagementTab;
