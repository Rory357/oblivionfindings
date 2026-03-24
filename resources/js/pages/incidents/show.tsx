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
                            {incident.type} • {incident.severity} •{' '}
                            {incident.status}
                            {incident.shift_id ? (
                                <span className="ml-2">• Shift-linked</span>
                            ) : (
                                <span className="ml-2">• Standalone</span>
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
</Dialog>
            </div>
        </AppLayout>
    );
}
