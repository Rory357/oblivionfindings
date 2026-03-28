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
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

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
        <div className="rounded-md border p-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="space-y-1">
                    <Label>Assign to</Label>
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
                            {/* Radix Select disallows empty-string values */}
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

                <div className="space-y-1">
                    <Label>Due</Label>
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
                        Add follow-up
                    </Button>
                </div>
            </div>

            <div className="mt-3 space-y-1">
                <Label>Notes</Label>
                <Textarea
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
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

    return (
        <div className="space-y-2">
            {followups.map((f) => {
                const status = statusFor(f);
                const canCompleteThis =
                    !f.completed_at &&
                    (canManage ||
                        (canComplete && f.assigned_to_user_id === userId));

                return (
                    <div key={f.id} className="rounded-md border p-3">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0">
                                <div className="text-sm font-medium">
                                    {status}
                                    {f.assigned_to?.name ? (
                                        <span className="text-slate-500">
                                            {' '}
                                            • Assigned to {f.assigned_to.name}
                                        </span>
                                    ) : (
                                        <span className="text-slate-500">
                                            {' '}
                                            • Unassigned
                                        </span>
                                    )}
                                </div>
                                <div className="mt-1 text-xs text-slate-500">
                                    {f.due_at
                                        ? `Due: ${new Date(f.due_at).toLocaleString()}`
                                        : 'No due date'}
                                    {f.completed_at
                                        ? ` • Completed: ${new Date(f.completed_at).toLocaleString()}`
                                        : ''}
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
                                    Mark complete
                                </Button>
                            ) : null}
                        </div>

                        {f.notes ? (
                            <div className="mt-2 text-sm whitespace-pre-wrap">
                                {f.notes}
                            </div>
                        ) : (
                            <div className="mt-2 text-sm text-slate-500">
                                No notes.
                            </div>
                        )}
                    </div>
                );
            })}

            {!followups.length ? (
                <div className="text-sm text-slate-500">No follow-ups yet.</div>
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

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            Incident #{incident.id}
                        </h1>
                        <div className="mt-1 text-sm text-slate-500">
                            <Link
                                className="underline"
                                href={`/clients/${incident.client_id}`}
                            >
                                {clientName}
                            </Link>
                            <span className="mx-2">•</span>
                            {incident.type === 'near_miss' ? 'Near miss' : incident.type} • {incident.severity} •{' '}
                            {incident.status}
                            {incident.shift_id ? (
                                <span className="ml-2">• Shift-linked</span>
                            ) : (
                                <span className="ml-2">• Standalone</span>
                            )}
                            {incident.is_notifiable && (
                                <Badge variant="destructive" className="ml-2">WorkSafe notifiable</Badge>
                            )}
                        </div>
                        {!is_editable && !can.review ? (
                            <div className="mt-1 text-xs text-slate-500">
                                This incident is read-only for the reporter.
                            </div>
                        ) : null}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {can.templatesManage && (
                            <Link
                                href="/incidents/templates"
                                className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                            >
                                Templates
                            </Link>
                        )}

                        <Link
                            href={`/clients/${incident.client_id}/incidents`}
                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
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
                                Mark reviewed
                            </Button>
                        )}

                        {can.close && incident.status === 'reviewed' && (
                            <Button
                                size="sm"
                                onClick={() => setCloseOpen(true)}
                            >
                                Close incident
                            </Button>
                        )}

                        {can.reopen && incident.status === 'closed' && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setReopenOpen(true)}
                            >
                                Reopen incident
                            </Button>
                        )}
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Details</CardTitle>
                    </CardHeader>

                    <CardContent className="space-y-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div className="space-y-1">
                                <Label>Type</Label>
                                <Input
                                    value={form.data.type}
                                    onChange={(e) =>
                                        form.setData('type', e.target.value)
                                    }
                                    disabled={!allowCoreEdit}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label>Severity</Label>
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

                            <div className="space-y-1">
                                <Label>Occurred at</Label>
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

                        <div className="flex items-center gap-2">
                            <Checkbox
                                checked={!!form.data.requires_followup}
                                onCheckedChange={(v) =>
                                    form.setData('requires_followup', !!v)
                                }
                                disabled={!allowCoreEdit}
                            />
                            <Label>Requires follow-up</Label>
                        </div>

                        <div className="space-y-1">
                            <Label>Description</Label>
                            <Textarea
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                disabled={!allowCoreEdit}
                            />
                        </div>

                        <div className="space-y-1">
                            <Label>Immediate action taken</Label>
                            <Textarea
                                value={form.data.immediate_action_taken}
                                onChange={(e) =>
                                    form.setData(
                                        'immediate_action_taken',
                                        e.target.value,
                                    )
                                }
                                disabled={!allowCoreEdit}
                            />
                        </div>

                        <div className="space-y-1">
                            <Label>Witnesses</Label>
                            <Textarea
                                value={form.data.witnesses}
                                onChange={(e) =>
                                    form.setData('witnesses', e.target.value)
                                }
                                disabled={!allowCoreEdit}
                            />
                        </div>

                        <div className="flex items-center gap-2">
                            <Checkbox
                                checked={!!form.data.is_notifiable}
                                onCheckedChange={(v) =>
                                    form.setData('is_notifiable', !!v)
                                }
                                disabled={!allowCoreEdit && !allowManagerFields}
                            />
                            <div>
                                <Label>Notifiable event</Label>
                                <div className="text-xs text-slate-500">This incident must be reported to WorkSafe NZ</div>
                            </div>
                        </div>

                        {can.review && (
                            <div className="space-y-1">
                                <Label>Review notes</Label>
                                <Textarea
                                    value={form.data.review_notes}
                                    onChange={(e) =>
                                        form.setData(
                                            'review_notes',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        )}

                        {can.portalManage && (
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    checked={!!form.data.portal_visible}
                                    onCheckedChange={(v) =>
                                        form.setData('portal_visible', !!v)
                                    }
                                />
                                <Label>
                                    Visible in portal (only shows once reviewed)
                                </Label>
                            </div>
                        )}

                        {(allowCoreEdit || allowManagerFields) && (
                            <div className="flex items-center justify-end">
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
                                    Save
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Near-miss details */}
                {isNearMiss && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Near-miss details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label>Potential severity</Label>
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
                            <div className="space-y-1">
                                <Label>Potential consequence</Label>
                                <Textarea
                                    value={form.data.potential_consequence}
                                    onChange={(e) => form.setData('potential_consequence', e.target.value)}
                                    disabled={!allowCoreEdit}
                                />
                            </div>

                            {allowCoreEdit && (
                                <div className="flex items-center justify-end">
                                    <Button
                                        disabled={saving}
                                        onClick={() => {
                                            setSaving(true);
                                            router.put(`/incidents/${incident.id}`, form.data, { onFinish: () => setSaving(false) });
                                        }}
                                    >
                                        Save
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Injury details */}
                {(hasInjuryDetails || allowCoreEdit) && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Injury details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div className="space-y-1">
                                    <Label>Injured person name</Label>
                                    <Input
                                        value={form.data.injured_person_name}
                                        onChange={(e) => form.setData('injured_person_name', e.target.value)}
                                        disabled={!allowCoreEdit}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label>Role</Label>
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
                                <div className="space-y-1">
                                    <Label>Age</Label>
                                    <Input
                                        type="number"
                                        value={form.data.injured_person_age}
                                        onChange={(e) => form.setData('injured_person_age', e.target.value)}
                                        disabled={!allowCoreEdit}
                                    />
                                </div>
                            </div>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label>Body part</Label>
                                    <Input
                                        value={form.data.injury_body_part}
                                        onChange={(e) => form.setData('injury_body_part', e.target.value)}
                                        disabled={!allowCoreEdit}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label>Nature of injury</Label>
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
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label>Injury classification</Label>
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
                                <div className="space-y-1">
                                    <Label>Medical treatment</Label>
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
                                <div className="flex items-center justify-end">
                                    <Button
                                        disabled={saving}
                                        onClick={() => {
                                            setSaving(true);
                                            router.put(`/incidents/${incident.id}`, form.data, { onFinish: () => setSaving(false) });
                                        }}
                                    >
                                        Save
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* WorkSafe notification */}
                {incident.is_notifiable && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">WorkSafe NZ notification</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div className="space-y-1">
                                    <Label>Notification status</Label>
                                    <div className="text-sm">
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
                                <div className="space-y-1">
                                    <Label>WorkSafe reference</Label>
                                    <div className="text-sm">{incident.worksafe_reference || '-'}</div>
                                </div>
                                <div className="space-y-1">
                                    <Label>Notified at</Label>
                                    <div className="text-sm">
                                        {incident.worksafe_notified_at
                                            ? new Date(incident.worksafe_notified_at).toLocaleString()
                                            : '-'}
                                    </div>
                                </div>
                            </div>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label>Site preserved</Label>
                                    <div className="text-sm">{incident.site_preserved ? 'Yes' : 'No'}</div>
                                </div>
                                {incident.site_preservation_released_at && (
                                    <div className="space-y-1">
                                        <Label>Preservation released</Label>
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
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Investigation
                            {incident.investigation_status && (
                                <Badge
                                    variant={investigationStatusVariant(incident.investigation_status)}
                                    className="ml-2"
                                >
                                    {INVESTIGATION_STATUSES.find((s) => s.value === incident.investigation_status)?.label || incident.investigation_status}
                                </Badge>
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {(can.update) && (
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label>Investigation status</Label>
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
                                <div className="space-y-1">
                                    <Label>Assigned to</Label>
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

                        <div className="space-y-1">
                            <Label>Root cause category</Label>
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

                        <div className="space-y-1">
                            <Label>Root cause description</Label>
                            <Textarea
                                value={form.data.root_cause_description}
                                onChange={(e) => form.setData('root_cause_description', e.target.value)}
                                disabled={!can.update}
                            />
                        </div>

                        <div className="space-y-1">
                            <Label>Contributing factors</Label>
                            <Textarea
                                value={form.data.contributing_factors}
                                onChange={(e) => form.setData('contributing_factors', e.target.value)}
                                disabled={!can.update}
                            />
                        </div>

                        <div className="space-y-1">
                            <Label>Lessons learned</Label>
                            <Textarea
                                value={form.data.lessons_learned}
                                onChange={(e) => form.setData('lessons_learned', e.target.value)}
                                disabled={!can.update}
                            />
                        </div>

                        {can.update && (
                            <div className="flex items-center justify-end">
                                <Button
                                    disabled={saving}
                                    onClick={() => {
                                        setSaving(true);
                                        router.put(`/incidents/${incident.id}`, form.data, { onFinish: () => setSaving(false) });
                                    }}
                                >
                                    Save
                                </Button>
                            </div>
                        )}

                        {/* Corrective actions */}
                        <div className="space-y-2">
                            <Label className="text-sm font-medium">Corrective actions</Label>

                            {correctiveActions.map((action, index) => (
                                <div key={index} className="flex items-start justify-between rounded-md border p-3">
                                    <div className="min-w-0">
                                        <div className="text-sm font-medium">
                                            {action.description}
                                        </div>
                                        <div className="mt-1 text-xs text-slate-500">
                                            {action.assigned_to && `Assigned to: ${action.assigned_to}`}
                                            {action.due_date && ` • Due: ${action.due_date}`}
                                            {action.completed_at && ` • Completed: ${new Date(action.completed_at).toLocaleDateString()}`}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Badge variant={action.status === 'completed' ? 'default' : 'outline'}>
                                            {action.status === 'completed' ? 'Completed' : 'Open'}
                                        </Badge>
                                        {can.update && action.status !== 'completed' && (
                                            <Button size="sm" variant="outline" onClick={() => completeCorrectiveAction(index)}>
                                                Complete
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ))}

                            {!correctiveActions.length && (
                                <div className="text-sm text-slate-500">No corrective actions.</div>
                            )}

                            {can.update && (
                                <div className="rounded-md border p-3 space-y-2">
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                        <div className="space-y-1">
                                            <Label>Description</Label>
                                            <Input
                                                value={newAction.description}
                                                onChange={(e) => setNewAction({ ...newAction, description: e.target.value })}
                                                placeholder="Action required..."
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label>Assigned to</Label>
                                            <Input
                                                value={newAction.assigned_to}
                                                onChange={(e) => setNewAction({ ...newAction, assigned_to: e.target.value })}
                                                placeholder="Person responsible"
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label>Due date</Label>
                                            <Input
                                                type="date"
                                                value={newAction.due_date}
                                                onChange={(e) => setNewAction({ ...newAction, due_date: e.target.value })}
                                            />
                                        </div>
                                    </div>
                                    <div className="flex justify-end">
                                        <Button size="sm" onClick={addCorrectiveAction} disabled={!newAction.description.trim()}>
                                            Add corrective action
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Follow-ups</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
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

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Attachments</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {allowCoreEdit ? (
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                                <div className="flex-1 space-y-1">
                                    <Label>Upload</Label>
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
                                    Upload
                                </Button>
                            </div>
                        ) : (
                            <div className="text-sm text-slate-500">
                                Attachments can only be changed while the
                                incident is editable.
                            </div>
                        )}

                        <div className="space-y-2">
                            {(incident.attachments || []).map((a: any) => (
                                <div
                                    key={a.id}
                                    className="flex items-center justify-between rounded-md border p-3"
                                >
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-medium">
                                            {a.original_name}
                                        </div>
                                        <div className="mt-1 text-xs text-slate-500">
                                            {a.size
                                                ? `${Math.round(a.size / 1024)} KB`
                                                : ''}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {can.portalManage && (
                                            <div className="flex items-center gap-2 rounded-md border px-2 py-1">
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
                                                <span className="text-xs text-slate-600">
                                                    Portal
                                                </span>
                                            </div>
                                        )}
                                        <Link
                                            href={`/incidents/${incident.id}/attachments/${a.id}/download`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            Download
                                        </Link>
                                        {allowCoreEdit ? (
                                            <button
                                                className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                                onClick={() =>
                                                    router.delete(
                                                        `/incidents/${incident.id}/attachments/${a.id}`,
                                                    )
                                                }
                                            >
                                                Remove
                                            </button>
                                        ) : null}
                                    </div>
                                </div>
                            ))}

                            {!(incident.attachments || []).length && (
                                <div className="text-sm text-slate-500">
                                    No attachments.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Dialog open={closeOpen} onOpenChange={setCloseOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Close incident</DialogTitle>
                        </DialogHeader>

                        <div className="space-y-3">
                            <div className="grid gap-2">
                                <Label>Outcome</Label>
                                <Input
                                    value={closeForm.data.closed_outcome}
                                    onChange={(e) =>
                                        closeForm.setData(
                                            'closed_outcome',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label>Closure notes (optional)</Label>
                                <Textarea
                                    value={closeForm.data.closed_notes}
                                    onChange={(e) =>
                                        closeForm.setData(
                                            'closed_notes',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="text-xs text-slate-500">
                                Tip: If this incident required follow-ups, they must be completed before closing.
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
                                Close
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog open={reopenOpen} onOpenChange={setReopenOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Reopen incident</DialogTitle>
                        </DialogHeader>

                        <div className="space-y-3">
                            <div className="grid gap-2">
                                <Label>Reason for reopening</Label>
                                <Textarea
                                    value={reopenForm.data.reopened_reason}
                                    onChange={(e) =>
                                        reopenForm.setData(
                                            'reopened_reason',
                                            e.target.value,
                                        )
                                    }
                                />
                                <div className="text-xs text-slate-500">
                                    This action is logged in the audit trail.
                                </div>
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
                                Reopen
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
