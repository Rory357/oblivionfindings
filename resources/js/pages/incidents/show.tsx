import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    AlertTriangle,
    ShieldAlert,
    Clock,
    User,
    MapPin,
    FileText,
    CheckCircle2,
    XCircle,
    ChevronDown,
    ChevronUp,
    Plus,
    Paperclip,
    Activity,
    Search,
    Download,
    Trash2,
    Eye,
    Send,
    Lock,
    RotateCcw,
} from 'lucide-react';

type Props = {
    incident: any;
    staff?: Array<{
        id: number;
        name: string;
        email: string;
        role: string;
    }> | null;
    can: {
        update: boolean;
        submit: boolean;
        review: boolean;
        close?: boolean;
        reopen?: boolean;
        templatesManage: boolean;
        followupsManage: boolean;
        followupsComplete: boolean;
        portalManage?: boolean;
    };
    is_editable: boolean;
};

type StaffUser = { id: number; name: string; email?: string; role?: string };

type Followup = {
    id: number;
    assigned_to_user_id: number | null;
    due_at: string | null;
    completed_at: string | null;
    notes: string | null;
    assigned_to?: { id: number; name: string } | null;
};

type CorrectiveAction = {
    description: string;
    assigned_to: string;
    due_date: string;
    status: string;
    completed_at: string | null;
};

const INVESTIGATION_STATUSES = [
    { value: 'not_required', label: 'Not required' },
    { value: 'pending', label: 'Pending' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'completed', label: 'Completed' },
];

const ROOT_CAUSE_CATEGORIES = [
    'Human factors',
    'Equipment / environment',
    'Process / procedure',
    'Training / competency',
    'Communication',
    'Supervision',
    'Other',
];

const WORKFLOW_STEPS = [
    { key: 'draft', label: 'Draft', icon: FileText },
    { key: 'submitted', label: 'Submitted', icon: Send },
    { key: 'reviewed', label: 'Reviewed', icon: CheckCircle2 },
    { key: 'closed', label: 'Closed', icon: Lock },
];

function severityColor(severity: string) {
    switch (severity) {
        case 'high': return { bg: 'bg-status-critical-bg', text: 'text-status-critical', border: 'border-status-critical/30', badge: 'destructive' as const, bar: 'bg-status-critical' };
        case 'medium': return { bg: 'bg-status-warning-bg', text: 'text-status-warning', border: 'border-status-warning/30', badge: 'secondary' as const, bar: 'bg-status-warning' };
        default: return { bg: 'bg-status-success-bg', text: 'text-status-success', border: 'border-status-success/30', badge: 'outline' as const, bar: 'bg-status-success' };
    }
}

function statusBadgeVariant(status: string) {
    switch (status) {
        case 'closed': return 'default' as const;
        case 'reviewed': return 'secondary' as const;
        case 'submitted': return 'outline' as const;
        default: return 'outline' as const;
    }
}

function FollowupCreator({
    incidentId,
    staff,
}: {
    incidentId: number;
    staff: StaffUser[];
}) {
    const form = useForm({
        assigned_to_user_id: '__unassigned__',
        due_at: '',
        notes: '',
    });

    const [submitting, setSubmitting] = useState(false);

    return (
        <div className="rounded-lg border-2 border-dashed border-muted-foreground/20 p-4 bg-muted/20">
            <div className="flex items-center gap-2 mb-3">
                <Plus className="h-4 w-4 text-muted-foreground" />
                <span className="text-sm font-medium">Add new follow-up</span>
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="space-y-1.5">
                    <Label className="text-xs">Assign to</Label>
                    <Select
                        value={form.data.assigned_to_user_id}
                        onValueChange={(v) =>
                            form.setData('assigned_to_user_id', v)
                        }
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Select staff" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__unassigned__">
                                Unassigned
                            </SelectItem>
                            {staff.map((u) => (
                                <SelectItem key={u.id} value={String(u.id)}>
                                    {u.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="space-y-1.5">
                    <Label className="text-xs">Due</Label>
                    <Input
                        type="datetime-local"
                        value={form.data.due_at}
                        onChange={(e) => form.setData('due_at', e.target.value)}
                    />
                </div>

                <div className="flex items-end">
                    <Button
                        className="w-full"
                        disabled={submitting}
                        onClick={() => {
                            const payload = {
                                ...form.data,
                                assigned_to_user_id:
                                    form.data.assigned_to_user_id ===
                                    '__unassigned__'
                                        ? null
                                        : form.data.assigned_to_user_id || null,
                            };

                            setSubmitting(true);

                            router.post(
                                `/incidents/${incidentId}/followups`,
                                payload,
                                {
                                    preserveScroll: true,
                                    onFinish: () => setSubmitting(false),
                                    onSuccess: () => form.reset(),
                                },
                            );
                        }}
                    >
                        <Plus className="mr-1.5 h-4 w-4" />
                        Add follow-up
                    </Button>
                </div>
            </div>

            <div className="mt-3 space-y-1.5">
                <Label className="text-xs">Notes</Label>
                <Textarea
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    placeholder="Optional follow-up notes..."
                    rows={2}
                />
            </div>
        </div>
    );
}

function FollowupList({
    incidentId,
    followups,
    canManage,
    canComplete,
}: {
    incidentId: number;
    followups: Followup[];
    canManage: boolean;
    canComplete: boolean;
}) {
    const { auth } = usePage().props as any;
    const userId = auth?.user?.id;

    const completeForm = useForm<{ notes: string }>({ notes: '' });
    const [completingId, setCompletingId] = useState<number | null>(null);

    const statusFor = (f: Followup) => {
        if (f.completed_at) return 'Completed';
        if (f.due_at) {
            const due = new Date(f.due_at);
            if (due.getTime() < Date.now()) return 'Overdue';
        }
        return 'Open';
    };

    const statusStyle = (status: string) => {
        switch (status) {
            case 'Completed': return { dot: 'bg-status-success', bg: 'bg-status-success-bg border-status-success/30', text: 'text-status-success' };
            case 'Overdue': return { dot: 'bg-status-critical', bg: 'bg-status-critical-bg border-status-critical/30', text: 'text-status-critical' };
            default: return { dot: 'bg-status-info', bg: 'bg-status-info-bg border-status-info/30', text: 'text-status-info' };
        }
    };

    return (
        <div className="space-y-3">
            {followups.map((f) => {
                const status = statusFor(f);
                const style = statusStyle(status);
                const canCompleteThis =
                    !f.completed_at &&
                    (canManage ||
                        (canComplete && f.assigned_to_user_id === userId));

                return (
                    <div key={f.id} className={`rounded-lg border p-4 ${style.bg}`}>
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <span className={`h-2 w-2 rounded-full ${style.dot}`} />
                                    <span className={`text-sm font-semibold ${style.text}`}>{status}</span>
                                    {f.assigned_to?.name ? (
                                        <span className="text-xs text-muted-foreground flex items-center gap-1">
                                            <User className="h-3 w-3" />
                                            {f.assigned_to.name}
                                        </span>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">Unassigned</span>
                                    )}
                                </div>
                                <div className="mt-1.5 flex items-center gap-3 text-xs text-muted-foreground">
                                    {f.due_at && (
                                        <span className="flex items-center gap-1">
                                            <Clock className="h-3 w-3" />
                                            Due: {new Date(f.due_at).toLocaleString()}
                                        </span>
                                    )}
                                    {!f.due_at && <span>No due date</span>}
                                    {f.completed_at && (
                                        <span className="flex items-center gap-1">
                                            <CheckCircle2 className="h-3 w-3" />
                                            Completed: {new Date(f.completed_at).toLocaleString()}
                                        </span>
                                    )}
                                </div>
                            </div>

                            {canCompleteThis ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={completingId === f.id}
                                    onClick={() => {
                                        setCompletingId(f.id);
                                        router.post(
                                            `/incidents/${incidentId}/followups/${f.id}/complete`,
                                            completeForm.data,
                                            {
                                                preserveScroll: true,
                                                onFinish: () =>
                                                    setCompletingId(null),
                                                onSuccess: () =>
                                                    completeForm.reset(),
                                            },
                                        );
                                    }}
                                >
                                    <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                                    Mark complete
                                </Button>
                            ) : null}
                        </div>

                        {f.notes ? (
                            <div className="mt-3 rounded-md bg-white/60 p-2.5 text-sm whitespace-pre-wrap border">
                                {f.notes}
                            </div>
                        ) : null}
                    </div>
                );
            })}

            {!followups.length ? (
                <div className="flex flex-col items-center justify-center py-8 text-muted-foreground">
                    <CheckCircle2 className="h-8 w-8 mb-2 opacity-30" />
                    <p className="text-sm">No follow-ups yet.</p>
                </div>
            ) : null}
        </div>
    );
}

function investigationStatusVariant(status: string | null) {
    switch (status) {
        case 'completed': return 'default';
        case 'in_progress': return 'secondary';
        case 'pending': return 'outline';
        default: return 'outline';
    }
}

export default function IncidentShow({
    incident,
    staff,
    can,
    is_editable,
}: Props) {
    const { labels } = usePage().props as any;
    const clientName = incident.client
        ? `${incident.client.first_name} ${incident.client.last_name}`
        : (labels?.['client.singular'] ?? 'Client');

    // Core incident details are only editable while in draft (audit guardrail).
    const allowCoreEdit =
        !!can.update &&
        incident.status === 'draft' &&
        (is_editable || can.review);

    // Managers can still update review notes / portal visibility after submission.
    const allowManagerFields =
        !!can.update && (!!can.review || !!can.portalManage);

    const form = useForm({
        type: incident.type || 'other',
        severity: incident.severity || 'low',
        occurred_at: incident.occurred_at
            ? incident.occurred_at.slice(0, 16)
            : '',
        description: incident.description || '',
        requires_followup: !!incident.requires_followup,
        immediate_action_taken: incident.immediate_action_taken || '',
        witnesses: incident.witnesses || '',
        review_notes: incident.review_notes || '',
        portal_visible: !!incident.portal_visible,
        // Near-miss
        potential_severity: incident.potential_severity || '',
        potential_consequence: incident.potential_consequence || '',
        // Injury
        injured_person_name: incident.injured_person_name || '',
        injured_person_role: incident.injured_person_role || '',
        injured_person_age: incident.injured_person_age || '',
        injury_body_part: incident.injury_body_part || '',
        injury_nature: incident.injury_nature || '',
        injury_classification: incident.injury_classification || '',
        medical_treatment_type: incident.medical_treatment_type || '',
        // WorkSafe
        is_notifiable: !!incident.is_notifiable,
        // Investigation
        investigation_status: incident.investigation_status || '',
        investigation_assigned_to: incident.investigation_assigned_to ? String(incident.investigation_assigned_to) : '',
        root_cause_category: incident.root_cause_category || '',
        root_cause_description: incident.root_cause_description || '',
        contributing_factors: incident.contributing_factors || '',
        lessons_learned: incident.lessons_learned || '',
    });

    const upload = useForm<{ file: File | null }>({ file: null });

    const closeForm = useForm({
        closed_outcome: incident.closed_outcome || 'Resolved',
        closed_notes: incident.closed_notes || '',
    });
    const [closeOpen, setCloseOpen] = useState(false);

    const reopenForm = useForm({
        reopened_reason: '',
    });
    const [reopenOpen, setReopenOpen] = useState(false);

    const [saving, setSaving] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [investigationOpen, setInvestigationOpen] = useState(true);

    const normalizedStaff = useMemo(() => (staff || []) as any, [staff]);

    // Corrective actions state
    const correctiveActions: CorrectiveAction[] = incident.corrective_actions || [];
    const [newAction, setNewAction] = useState({ description: '', assigned_to: '', due_date: '' });

    const addCorrectiveAction = () => {
        if (!newAction.description.trim()) return;
        const updated = [...correctiveActions, { ...newAction, status: 'open', completed_at: null }];
        router.put(`/incidents/${incident.id}`, { corrective_actions: updated }, { preserveScroll: true });
        setNewAction({ description: '', assigned_to: '', due_date: '' });
    };

    const completeCorrectiveAction = (index: number) => {
        const updated = correctiveActions.map((a, i) =>
            i === index ? { ...a, status: 'completed', completed_at: new Date().toISOString() } : a,
        );
        router.put(`/incidents/${incident.id}`, { corrective_actions: updated }, { preserveScroll: true });
    };

    const hasInjuryDetails = !!(
        incident.injured_person_name ||
        incident.injured_person_role ||
        incident.injured_person_age ||
        incident.injury_body_part ||
        incident.injury_nature ||
        incident.injury_classification ||
        incident.medical_treatment_type
    );

    const isNearMiss = incident.type === 'near_miss';
    const sev = severityColor(incident.severity);

    const currentStepIndex = WORKFLOW_STEPS.findIndex((s) => s.key === incident.status);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Incidents', href: '/incidents' },
                {
                    title: `Incident #${incident.id}`,
                    href: `/incidents/${incident.id}`,
                },
            ]}
        >
            <Head title={`Incident #${incident.id}`} />

            <div className="mx-auto max-w-5xl space-y-6 pb-8">
                {/* Header card with severity bar */}
                <Card className="overflow-hidden">
                    <div className={`h-1.5 ${sev.bar}`} />
                    <CardContent className="pt-5 pb-5">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div className="flex items-start gap-4">
                                <div className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ${sev.bg} ${sev.text}`}>
                                    <AlertTriangle className="h-6 w-6" />
                                </div>
                                <div>
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <h1 className="text-xl font-bold tracking-tight">
                                            Incident #{incident.id}
                                        </h1>
                                        <Badge variant={statusBadgeVariant(incident.status)} className="capitalize">
                                            {incident.status}
                                        </Badge>
                                        <Badge variant={sev.badge} className="capitalize">
                                            {incident.severity}
                                        </Badge>
                                        {incident.is_notifiable && (
                                            <Badge variant="destructive" className="gap-1">
                                                <ShieldAlert className="h-3 w-3" />
                                                WorkSafe notifiable
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                        <Link
                                            className="font-medium text-primary hover:underline"
                                            href={`/clients/${incident.client_id}`}
                                        >
                                            {clientName}
                                        </Link>
                                        <span className="flex items-center gap-1">
                                            <Activity className="h-3.5 w-3.5" />
                                            {incident.type === 'near_miss' ? 'Near miss' : incident.type}
                                        </span>
                                        {incident.occurred_at && (
                                            <span className="flex items-center gap-1">
                                                <Clock className="h-3.5 w-3.5" />
                                                {new Date(incident.occurred_at).toLocaleString()}
                                            </span>
                                        )}
                                        <span className="flex items-center gap-1">
                                            {incident.shift_id ? 'Shift-linked' : 'Standalone'}
                                        </span>
                                    </div>
                                    {!is_editable && !can.review ? (
                                        <div className="mt-1.5 text-xs text-muted-foreground bg-muted rounded px-2 py-1 inline-block">
                                            This incident is read-only for the reporter.
                                        </div>
                                    ) : null}
                                </div>
                            </div>

                            <div className="flex flex-wrap items-center gap-2">
                                {can.templatesManage && (
                                    <Link
                                        href="/incidents/templates"
                                        className="rounded-md border px-3 py-2 text-xs font-medium hover:bg-muted transition-colors"
                                    >
                                        Templates
                                    </Link>
                                )}

                                <Link
                                    href={`/clients/${incident.client_id}/incidents`}
                                    className="rounded-md border px-3 py-2 text-xs font-medium hover:bg-muted transition-colors"
                                >
                                    Client incidents
                                </Link>

                                {can.submit && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/incidents/${incident.id}/submit`,
                                            )
                                        }
                                    >
                                        <Send className="mr-1.5 h-3.5 w-3.5" />
                                        Submit
                                    </Button>
                                )}

                                {can.review && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/incidents/${incident.id}/review`,
                                                {
                                                    review_notes:
                                                        form.data.review_notes,
                                                },
                                            )
                                        }
                                    >
                                        <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                                        Mark reviewed
                                    </Button>
                                )}

                                {can.close && incident.status === 'reviewed' && (
                                    <Button
                                        size="sm"
                                        onClick={() => setCloseOpen(true)}
                                    >
                                        <Lock className="mr-1.5 h-3.5 w-3.5" />
                                        Close incident
                                    </Button>
                                )}

                                {can.reopen && incident.status === 'closed' && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setReopenOpen(true)}
                                    >
                                        <RotateCcw className="mr-1.5 h-3.5 w-3.5" />
                                        Reopen incident
                                    </Button>
                                )}
                            </div>
                        </div>

                        {/* Status timeline */}
                        <div className="mt-6 pt-4 border-t">
                            <div className="flex items-center justify-between">
                                {WORKFLOW_STEPS.map((step, index) => {
                                    const Icon = step.icon;
                                    const isActive = step.key === incident.status;
                                    const isPast = index < currentStepIndex;
                                    const isFuture = index > currentStepIndex;

                                    return (
                                        <div key={step.key} className="flex items-center flex-1 last:flex-none">
                                            <div className="flex flex-col items-center gap-1.5">
                                                <div className={`flex h-9 w-9 items-center justify-center rounded-full border-2 transition-colors ${
                                                    isActive
                                                        ? `${sev.bar} border-transparent text-white`
                                                        : isPast
                                                            ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                                            : 'border-muted bg-muted/50 text-muted-foreground'
                                                }`}>
                                                    {isPast ? <CheckCircle2 className="h-4 w-4" /> : <Icon className="h-4 w-4" />}
                                                </div>
                                                <span className={`text-xs font-medium ${isActive ? 'text-foreground' : isFuture ? 'text-muted-foreground' : 'text-muted-foreground'}`}>
                                                    {step.label}
                                                </span>
                                            </div>
                                            {index < WORKFLOW_STEPS.length - 1 && (
                                                <div className={`mx-2 h-0.5 flex-1 rounded ${isPast ? 'bg-status-success' : 'bg-muted'}`} />
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Details card */}
                <Card className="overflow-hidden">
                    <CardHeader className="border-b bg-muted/30 pb-4">
                        <div className="flex items-center gap-2">
                            <FileText className="h-4 w-4 text-muted-foreground" />
                            <CardTitle className="text-base">Incident details</CardTitle>
                        </div>
                    </CardHeader>

                    <CardContent className="pt-5 space-y-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div className="space-y-1.5">
                                <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Type</Label>
                                <Input
                                    value={form.data.type}
                                    onChange={(e) =>
                                        form.setData('type', e.target.value)
                                    }
                                    disabled={!allowCoreEdit}
                                />
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Severity</Label>
                                <Select
                                    value={form.data.severity}
                                    onValueChange={(v) =>
                                        form.setData('severity', v)
                                    }
                                    disabled={!allowCoreEdit}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {['low', 'medium', 'high'].map((s) => (
                                            <SelectItem key={s} value={s}>
                                                {s}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Occurred at</Label>
                                <Input
                                    type="datetime-local"
                                    value={form.data.occurred_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'occurred_at',
                                            e.target.value,
                                        )
                                    }
                                    disabled={!allowCoreEdit}
                                />
                            </div>
                        </div>

                        <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                            <Checkbox
                                checked={!!form.data.requires_followup}
                                onCheckedChange={(v) =>
                                    form.setData('requires_followup', !!v)
                                }
                                disabled={!allowCoreEdit}
                            />
                            <Label>Requires follow-up</Label>
                        </div>

                        <div className="space-y-1.5">
                            <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Description</Label>
                            <Textarea
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                disabled={!allowCoreEdit}
                                rows={4}
                            />
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Immediate action taken</Label>
                                <Textarea
                                    value={form.data.immediate_action_taken}
                                    onChange={(e) =>
                                        form.setData(
                                            'immediate_action_taken',
                                            e.target.value,
                                        )
                                    }
                                    disabled={!allowCoreEdit}
                                    rows={3}
                                />
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Witnesses</Label>
                                <Textarea
                                    value={form.data.witnesses}
                                    onChange={(e) =>
                                        form.setData('witnesses', e.target.value)
                                    }
                                    disabled={!allowCoreEdit}
                                    rows={3}
                                />
                            </div>
                        </div>

                        <div className={`flex items-start gap-4 rounded-lg border p-4 ${form.data.is_notifiable ? 'border-status-critical/30 bg-status-critical-bg' : 'bg-muted/30'}`}>
                            <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${form.data.is_notifiable ? 'bg-status-critical-bg text-status-critical' : 'bg-muted text-muted-foreground'}`}>
                                <ShieldAlert className="h-4 w-4" />
                            </div>
                            <div className="flex items-center gap-3 flex-1">
                                <Checkbox
                                    checked={!!form.data.is_notifiable}
                                    onCheckedChange={(v) =>
                                        form.setData('is_notifiable', !!v)
                                    }
                                    disabled={!allowCoreEdit && !allowManagerFields}
                                />
                                <div>
                                    <Label className="font-medium">Notifiable event</Label>
                                    <div className="text-xs text-muted-foreground">This incident must be reported to WorkSafe NZ</div>
                                </div>
                            </div>
                        </div>

                        {can.review && (
                            <div className="space-y-1.5">
                                <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Review notes</Label>
                                <Textarea
                                    value={form.data.review_notes}
                                    onChange={(e) =>
                                        form.setData(
                                            'review_notes',
                                            e.target.value,
                                        )
                                    }
                                    rows={3}
                                />
                            </div>
                        )}

                        {can.portalManage && (
                            <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                                <Checkbox
                                    checked={!!form.data.portal_visible}
                                    onCheckedChange={(v) =>
                                        form.setData('portal_visible', !!v)
                                    }
                                />
                                <div>
                                    <Label>Visible in portal</Label>
                                    <div className="text-xs text-muted-foreground">Only shows once reviewed</div>
                                </div>
                            </div>
                        )}

                        {(allowCoreEdit || allowManagerFields) && (
                            <div className="flex items-center justify-end border-t pt-4">
                                <Button
                                    disabled={saving}
                                    onClick={() => {
                                        setSaving(true);
                                        router.put(
                                            `/incidents/${incident.id}`,
                                            form.data,
                                            {
                                                onFinish: () =>
                                                    setSaving(false),
                                            },
                                        );
                                    }}
                                >
                                    {saving ? 'Saving...' : 'Save changes'}
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Near-miss details */}
                {isNearMiss && (
                    <Card className="overflow-hidden border-status-warning/30">
                        <CardHeader className="border-b border-status-warning/30 bg-status-warning-bg pb-4">
                            <div className="flex items-center gap-2">
                                <AlertTriangle className="h-4 w-4 text-status-warning" />
                                <CardTitle className="text-base text-status-warning">Near-miss details</CardTitle>
                            </div>
                        </CardHeader>
                        <CardContent className="bg-status-warning-bg pt-5 space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Potential severity</Label>
                                    <Select
                                        value={form.data.potential_severity || '__none__'}
                                        onValueChange={(v) => form.setData('potential_severity', v === '__none__' ? '' : v)}
                                        disabled={!allowCoreEdit}
                                    >
                                        <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select...</SelectItem>
                                            {['low','medium','high','critical'].map((s) => (
                                                <SelectItem key={s} value={s}>{s}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Potential consequence</Label>
                                <Textarea
                                    value={form.data.potential_consequence}
                                    onChange={(e) => form.setData('potential_consequence', e.target.value)}
                                    disabled={!allowCoreEdit}
                                    rows={3}
                                />
                            </div>

                            {allowCoreEdit && (
                                <div className="flex items-center justify-end border-t border-status-warning/30 pt-4">
                                    <Button
                                        disabled={saving}
                                        onClick={() => {
                                            setSaving(true);
                                            router.put(`/incidents/${incident.id}`, form.data, { onFinish: () => setSaving(false) });
                                        }}
                                    >
                                        {saving ? 'Saving...' : 'Save changes'}
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Injury details */}
                {(hasInjuryDetails || allowCoreEdit) && (
                    <Card className="overflow-hidden">
                        <CardHeader className="border-b bg-muted/30 pb-4">
                            <div className="flex items-center gap-2">
                                <Activity className="h-4 w-4 text-muted-foreground" />
                                <CardTitle className="text-base">Injury details</CardTitle>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-5 space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div className="space-y-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Injured person name</Label>
                                    <Input
                                        value={form.data.injured_person_name}
                                        onChange={(e) => form.setData('injured_person_name', e.target.value)}
                                        disabled={!allowCoreEdit}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Role</Label>
                                    <Select
                                        value={form.data.injured_person_role || '__none__'}
                                        onValueChange={(v) => form.setData('injured_person_role', v === '__none__' ? '' : v)}
                                        disabled={!allowCoreEdit}
                                    >
                                        <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select...</SelectItem>
                                            {[
                                                { value: 'staff', label: 'Staff' },
                                                { value: 'client', label: 'Client' },
                                                { value: 'visitor', label: 'Visitor' },
                                                { value: 'contractor', label: 'Contractor' },
                                            ].map((r) => (
                                                <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Age</Label>
                                    <Input
                                        type="number"
                                        value={form.data.injured_person_age}
                                        onChange={(e) => form.setData('injured_person_age', e.target.value)}
                                        disabled={!allowCoreEdit}
                                    />
                                </div>
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Body part</Label>
                                    <Input
                                        value={form.data.injury_body_part}
                                        onChange={(e) => form.setData('injury_body_part', e.target.value)}
                                        disabled={!allowCoreEdit}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Nature of injury</Label>
                                    <Select
                                        value={form.data.injury_nature || '__none__'}
                                        onValueChange={(v) => form.setData('injury_nature', v === '__none__' ? '' : v)}
                                        disabled={!allowCoreEdit}
                                    >
                                        <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select...</SelectItem>
                                            {['fracture','burn','laceration','sprain','bruising','concussion','poisoning','other'].map((n) => (
                                                <SelectItem key={n} value={n}>{n}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Injury classification</Label>
                                    <Select
                                        value={form.data.injury_classification || '__none__'}
                                        onValueChange={(v) => form.setData('injury_classification', v === '__none__' ? '' : v)}
                                        disabled={!allowCoreEdit}
                                    >
                                        <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select...</SelectItem>
                                            {[
                                                { value: 'minor', label: 'Minor' },
                                                { value: 'moderate', label: 'Moderate' },
                                                { value: 'serious', label: 'Serious' },
                                                { value: 'notifiable', label: 'Notifiable' },
                                            ].map((c) => (
                                                <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Medical treatment</Label>
                                    <Select
                                        value={form.data.medical_treatment_type || '__none__'}
                                        onValueChange={(v) => form.setData('medical_treatment_type', v === '__none__' ? '' : v)}
                                        disabled={!allowCoreEdit}
                                    >
                                        <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select...</SelectItem>
                                            {[
                                                { value: 'none', label: 'None' },
                                                { value: 'first_aid', label: 'First aid' },
                                                { value: 'medical_centre', label: 'Medical centre' },
                                                { value: 'hospital', label: 'Hospital' },
                                                { value: 'ambulance', label: 'Ambulance' },
                                            ].map((m) => (
                                                <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            {allowCoreEdit && (
                                <div className="flex items-center justify-end border-t pt-4">
                                    <Button
                                        disabled={saving}
                                        onClick={() => {
                                            setSaving(true);
                                            router.put(`/incidents/${incident.id}`, form.data, { onFinish: () => setSaving(false) });
                                        }}
                                    >
                                        {saving ? 'Saving...' : 'Save changes'}
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* WorkSafe notification */}
                {incident.is_notifiable && (
                    <Card className="overflow-hidden border-status-critical/30">
                        <div className="h-1.5 bg-status-critical" />
                        <CardHeader className="border-b border-status-critical/30 bg-status-critical-bg pb-4">
                            <div className="flex items-center gap-2">
                                <ShieldAlert className="h-4 w-4 text-status-critical" />
                                <CardTitle className="text-base text-status-critical">WorkSafe NZ notification</CardTitle>
                            </div>
                        </CardHeader>
                        <CardContent className="bg-status-critical-bg pt-5 space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div className="space-y-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Notification status</Label>
                                    <div>
                                        <Badge variant={
                                            incident.worksafe_notification_status === 'acknowledged' ? 'default' :
                                            incident.worksafe_notification_status === 'notified' ? 'secondary' :
                                            incident.worksafe_notification_status === 'pending' ? 'outline' :
                                            'outline'
                                        }>
                                            {incident.worksafe_notification_status || 'Not started'}
                                        </Badge>
                                    </div>
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">WorkSafe reference</Label>
                                    <div className="text-sm font-mono">{incident.worksafe_reference || '-'}</div>
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Notified at</Label>
                                    <div className="text-sm">
                                        {incident.worksafe_notified_at
                                            ? new Date(incident.worksafe_notified_at).toLocaleString()
                                            : '-'}
                                    </div>
                                </div>
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="rounded-lg border border-status-critical/30 bg-white p-3 space-y-1">
                                    <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Site preserved</Label>
                                    <div className="text-sm font-medium">{incident.site_preserved ? 'Yes' : 'No'}</div>
                                </div>
                                {incident.site_preservation_released_at && (
                                    <div className="rounded-lg border border-status-critical/30 bg-white p-3 space-y-1">
                                        <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Preservation released</Label>
                                        <div className="text-sm">
                                            {new Date(incident.site_preservation_released_at).toLocaleString()}
                                            {incident.site_preservation_released_by && ` by ${incident.site_preservation_released_by}`}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Investigation */}
                <Card className="overflow-hidden">
                    <CardHeader
                        className="border-b bg-muted/30 pb-4 cursor-pointer hover:bg-muted/50 transition-colors"
                        onClick={() => setInvestigationOpen(!investigationOpen)}
                    >
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Search className="h-4 w-4 text-muted-foreground" />
                                <CardTitle className="text-base">
                                    Investigation
                                </CardTitle>
                                {incident.investigation_status && (
                                    <Badge
                                        variant={investigationStatusVariant(incident.investigation_status) as any}
                                    >
                                        {INVESTIGATION_STATUSES.find((s) => s.value === incident.investigation_status)?.label || incident.investigation_status}
                                    </Badge>
                                )}
                            </div>
                            <div className="text-muted-foreground">
                                {investigationOpen ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
                            </div>
                        </div>
                    </CardHeader>
                    {investigationOpen && (
                        <CardContent className="pt-5 space-y-5">
                            {(can.update) && (
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Investigation status</Label>
                                        <Select
                                            value={form.data.investigation_status || '__none__'}
                                            onValueChange={(v) => form.setData('investigation_status', v === '__none__' ? '' : v)}
                                        >
                                            <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">Select...</SelectItem>
                                                {INVESTIGATION_STATUSES.map((s) => (
                                                    <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Assigned to</Label>
                                        <Select
                                            value={form.data.investigation_assigned_to || '__none__'}
                                            onValueChange={(v) => form.setData('investigation_assigned_to', v === '__none__' ? '' : v)}
                                        >
                                            <SelectTrigger><SelectValue placeholder="Select staff" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">Unassigned</SelectItem>
                                                {normalizedStaff.map((u: StaffUser) => (
                                                    <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                            )}

                            <div className="space-y-1.5">
                                <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Root cause category</Label>
                                {can.update ? (
                                    <Select
                                        value={form.data.root_cause_category || '__none__'}
                                        onValueChange={(v) => form.setData('root_cause_category', v === '__none__' ? '' : v)}
                                    >
                                        <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select...</SelectItem>
                                            {ROOT_CAUSE_CATEGORIES.map((c) => (
                                                <SelectItem key={c} value={c}>{c}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    <div className="text-sm">{incident.root_cause_category || '-'}</div>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Root cause description</Label>
                                <Textarea
                                    value={form.data.root_cause_description}
                                    onChange={(e) => form.setData('root_cause_description', e.target.value)}
                                    disabled={!can.update}
                                    rows={3}
                                />
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Contributing factors</Label>
                                <Textarea
                                    value={form.data.contributing_factors}
                                    onChange={(e) => form.setData('contributing_factors', e.target.value)}
                                    disabled={!can.update}
                                    rows={3}
                                />
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Lessons learned</Label>
                                <Textarea
                                    value={form.data.lessons_learned}
                                    onChange={(e) => form.setData('lessons_learned', e.target.value)}
                                    disabled={!can.update}
                                    rows={3}
                                />
                            </div>

                            {can.update && (
                                <div className="flex items-center justify-end border-t pt-4">
                                    <Button
                                        disabled={saving}
                                        onClick={() => {
                                            setSaving(true);
                                            router.put(`/incidents/${incident.id}`, form.data, { onFinish: () => setSaving(false) });
                                        }}
                                    >
                                        {saving ? 'Saving...' : 'Save changes'}
                                    </Button>
                                </div>
                            )}

                            {/* Corrective actions */}
                            <div className="space-y-3 border-t pt-5">
                                <div className="flex items-center gap-2">
                                    <CheckCircle2 className="h-4 w-4 text-muted-foreground" />
                                    <Label className="text-sm font-semibold">Corrective actions</Label>
                                    <Badge variant="outline" className="ml-auto">{correctiveActions.length}</Badge>
                                </div>

                                {correctiveActions.map((action, index) => (
                                    <div key={index} className={`rounded-lg border p-4 ${action.status === 'completed' ? 'bg-status-success-bg border-status-success/30' : 'bg-white'}`}>
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    {action.status === 'completed' ? (
                                                        <CheckCircle2 className="h-4 w-4 text-status-success shrink-0" />
                                                    ) : (
                                                        <div className="h-4 w-4 rounded-full border-2 border-muted-foreground/30 shrink-0" />
                                                    )}
                                                    <span className={`text-sm font-medium ${action.status === 'completed' ? 'line-through text-muted-foreground' : ''}`}>
                                                        {action.description}
                                                    </span>
                                                </div>
                                                <div className="mt-1.5 ml-6 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                    {action.assigned_to && (
                                                        <span className="flex items-center gap-1">
                                                            <User className="h-3 w-3" />
                                                            {action.assigned_to}
                                                        </span>
                                                    )}
                                                    {action.due_date && (
                                                        <span className="flex items-center gap-1">
                                                            <Clock className="h-3 w-3" />
                                                            Due: {action.due_date}
                                                        </span>
                                                    )}
                                                    {action.completed_at && (
                                                        <span className="flex items-center gap-1">
                                                            <CheckCircle2 className="h-3 w-3" />
                                                            Completed: {new Date(action.completed_at).toLocaleDateString()}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            {can.update && action.status !== 'completed' && (
                                                <Button size="sm" variant="outline" onClick={() => completeCorrectiveAction(index)}>
                                                    <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                                                    Complete
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                ))}

                                {!correctiveActions.length && (
                                    <div className="flex flex-col items-center justify-center py-6 text-muted-foreground">
                                        <CheckCircle2 className="h-8 w-8 mb-2 opacity-30" />
                                        <p className="text-sm">No corrective actions.</p>
                                    </div>
                                )}

                                {can.update && (
                                    <div className="rounded-lg border-2 border-dashed border-muted-foreground/20 p-4 bg-muted/20">
                                        <div className="flex items-center gap-2 mb-3">
                                            <Plus className="h-4 w-4 text-muted-foreground" />
                                            <span className="text-sm font-medium">Add corrective action</span>
                                        </div>
                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Description</Label>
                                                <Input
                                                    value={newAction.description}
                                                    onChange={(e) => setNewAction({ ...newAction, description: e.target.value })}
                                                    placeholder="Action required..."
                                                />
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Assigned to</Label>
                                                <Input
                                                    value={newAction.assigned_to}
                                                    onChange={(e) => setNewAction({ ...newAction, assigned_to: e.target.value })}
                                                    placeholder="Person responsible"
                                                />
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Due date</Label>
                                                <Input
                                                    type="date"
                                                    value={newAction.due_date}
                                                    onChange={(e) => setNewAction({ ...newAction, due_date: e.target.value })}
                                                />
                                            </div>
                                        </div>
                                        <div className="flex justify-end mt-3">
                                            <Button size="sm" onClick={addCorrectiveAction} disabled={!newAction.description.trim()}>
                                                <Plus className="mr-1.5 h-3.5 w-3.5" />
                                                Add action
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    )}
                </Card>

                {/* Follow-ups */}
                <Card className="overflow-hidden">
                    <CardHeader className="border-b bg-muted/30 pb-4">
                        <div className="flex items-center gap-2">
                            <Activity className="h-4 w-4 text-muted-foreground" />
                            <CardTitle className="text-base">Follow-ups</CardTitle>
                            <Badge variant="outline" className="ml-auto">
                                {(incident.followups || []).length}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5 space-y-4">
                        {can.followupsManage ? (
                            <FollowupCreator
                                incidentId={incident.id}
                                staff={normalizedStaff}
                            />
                        ) : null}

                        <FollowupList
                            incidentId={incident.id}
                            followups={(incident.followups || []) as any}
                            canManage={!!can.followupsManage}
                            canComplete={!!can.followupsComplete}
                        />
                    </CardContent>
                </Card>

                {/* Attachments */}
                <Card className="overflow-hidden">
                    <CardHeader className="border-b bg-muted/30 pb-4">
                        <div className="flex items-center gap-2">
                            <Paperclip className="h-4 w-4 text-muted-foreground" />
                            <CardTitle className="text-base">Attachments</CardTitle>
                            <Badge variant="outline" className="ml-auto">
                                {(incident.attachments || []).length}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5 space-y-4">
                        {allowCoreEdit ? (
                            <div className="rounded-lg border-2 border-dashed border-muted-foreground/20 p-4 bg-muted/20">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                    <div className="flex-1 space-y-1.5">
                                        <Label className="text-xs">Upload a file</Label>
                                        <Input
                                            type="file"
                                            onChange={(e) =>
                                                upload.setData(
                                                    'file',
                                                    e.target.files?.[0] ?? null,
                                                )
                                            }
                                        />
                                    </div>
                                    <Button
                                        disabled={uploading || !upload.data.file}
                                        onClick={() => {
                                            if (!upload.data.file) return;

                                            setUploading(true);
                                            router.post(
                                                `/incidents/${incident.id}/attachments`,
                                                { file: upload.data.file },
                                                {
                                                    forceFormData: true,
                                                    onFinish: () =>
                                                        setUploading(false),
                                                    onSuccess: () => upload.reset(),
                                                },
                                            );
                                        }}
                                    >
                                        <Paperclip className="mr-1.5 h-3.5 w-3.5" />
                                        {uploading ? 'Uploading...' : 'Upload'}
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <div className="text-sm text-muted-foreground bg-muted/30 rounded-lg p-3">
                                Attachments can only be changed while the incident is editable.
                            </div>
                        )}

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            {(incident.attachments || []).map((a: any) => (
                                <div
                                    key={a.id}
                                    className="flex items-center gap-3 rounded-lg border p-3 bg-white hover:bg-muted/30 transition-colors"
                                >
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                        <FileText className="h-5 w-5" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm font-medium">
                                            {a.original_name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {a.size
                                                ? `${Math.round(a.size / 1024)} KB`
                                                : ''}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-1.5 shrink-0">
                                        {can.portalManage && (
                                            <div className="flex items-center gap-1.5 rounded border px-2 py-1">
                                                <Checkbox
                                                    checked={!!a.portal_visible}
                                                    onCheckedChange={(v) =>
                                                        router.patch(
                                                            `/incidents/${incident.id}/attachments/${a.id}`,
                                                            {
                                                                portal_visible:
                                                                    !!v,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                />
                                                <span className="text-xs text-muted-foreground">
                                                    Portal
                                                </span>
                                            </div>
                                        )}
                                        <Link
                                            href={`/incidents/${incident.id}/attachments/${a.id}/download`}
                                            className="rounded-md border p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
                                            title="Download"
                                        >
                                            <Download className="h-4 w-4" />
                                        </Link>
                                        {allowCoreEdit ? (
                                            <button
                                                className="rounded-md border p-1.5 text-muted-foreground hover:bg-status-critical-bg hover:text-status-critical hover:border-status-critical/30 transition-colors"
                                                onClick={() =>
                                                    router.delete(
                                                        `/incidents/${incident.id}/attachments/${a.id}`,
                                                    )
                                                }
                                                title="Remove"
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </button>
                                        ) : null}
                                    </div>
                                </div>
                            ))}
                        </div>

                        {!(incident.attachments || []).length && (
                            <div className="flex flex-col items-center justify-center py-8 text-muted-foreground">
                                <Paperclip className="h-8 w-8 mb-2 opacity-30" />
                                <p className="text-sm">No attachments.</p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Close dialog */}
                <Dialog open={closeOpen} onOpenChange={setCloseOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <div className="flex items-center gap-3">
                                <div className={`flex h-10 w-10 items-center justify-center rounded-xl ${sev.bg} ${sev.text}`}>
                                    <Lock className="h-5 w-5" />
                                </div>
                                <div>
                                    <DialogTitle>Close incident #{incident.id}</DialogTitle>
                                    <DialogDescription className="mt-0.5 text-sm text-muted-foreground">
                                        This action can be reversed by reopening later.
                                    </DialogDescription>
                                </div>
                            </div>
                        </DialogHeader>

                        <div className="space-y-4 py-2">
                            <div className="space-y-1.5">
                                <Label className="text-sm font-medium">Outcome</Label>
                                <Input
                                    value={closeForm.data.closed_outcome}
                                    onChange={(e) =>
                                        closeForm.setData(
                                            'closed_outcome',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. Resolved, No further action required"
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-sm font-medium">Closure notes (optional)</Label>
                                <Textarea
                                    value={closeForm.data.closed_notes}
                                    onChange={(e) =>
                                        closeForm.setData(
                                            'closed_notes',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Any final notes or observations..."
                                    rows={3}
                                />
                            </div>
                            <div className="rounded-lg border bg-muted/30 p-3 text-xs text-muted-foreground">
                                If this incident required follow-ups, they must be completed before closing.
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setCloseOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={() =>
                                    closeForm.post(
                                        `/incidents/${incident.id}/close`,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => setCloseOpen(false),
                                        },
                                    )
                                }
                                disabled={
                                    closeForm.processing ||
                                    !closeForm.data.closed_outcome?.trim()
                                }
                            >
                                <Lock className="mr-1.5 h-3.5 w-3.5" />
                                Close incident
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Reopen dialog */}
                <Dialog open={reopenOpen} onOpenChange={setReopenOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-status-warning-bg text-status-warning">
                                    <RotateCcw className="h-5 w-5" />
                                </div>
                                <div>
                                    <DialogTitle>Reopen incident #{incident.id}</DialogTitle>
                                    <DialogDescription className="mt-0.5 text-sm text-muted-foreground">
                                        This action is recorded in the audit trail.
                                    </DialogDescription>
                                </div>
                            </div>
                        </DialogHeader>

                        <div className="space-y-4 py-2">
                            <div className="space-y-1.5">
                                <Label className="text-sm font-medium">Reason for reopening</Label>
                                <Textarea
                                    value={reopenForm.data.reopened_reason}
                                    onChange={(e) =>
                                        reopenForm.setData(
                                            'reopened_reason',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Why does this incident need to be reopened?"
                                    rows={3}
                                />
                            </div>
                            <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-xs text-status-warning">
                                Reopening will change the incident status back to reviewed, allowing further updates.
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setReopenOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={() =>
                                    reopenForm.post(
                                        `/incidents/${incident.id}/reopen`,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                setReopenOpen(false),
                                        },
                                    )
                                }
                                disabled={
                                    reopenForm.processing ||
                                    !reopenForm.data.reopened_reason?.trim()
                                }
                            >
                                <RotateCcw className="mr-1.5 h-3.5 w-3.5" />
                                Reopen
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
