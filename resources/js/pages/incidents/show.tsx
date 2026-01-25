import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

type Props = {
    incident: any;
    staff?: Array<{ id: number; name: string; email: string; role: string } > | null;
    can: { update: boolean; submit: boolean; review: boolean; templatesManage: boolean; followupsManage: boolean; followupsComplete: boolean; portalManage?: boolean };
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

function FollowupCreator({ incidentId, staff }: { incidentId: number; staff: StaffUser[] }) {
    const form = useForm({
        assigned_to_user_id: '',
        due_at: '',
        notes: '',
    });

    return (
        <div className="rounded-md border p-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="space-y-1">
                    <Label>Assign to</Label>
                    <Select
                        value={form.data.assigned_to_user_id}
                        onValueChange={(v) => form.setData('assigned_to_user_id', v)}
                    >
                        <SelectTrigger><SelectValue placeholder="Select staff" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">Unassigned</SelectItem>
                            {staff.map((u) => (
                                <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
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
                        disabled={form.processing}
                        onClick={() =>
                            form.post(`/incidents/${incidentId}/followups`, {
                                onSuccess: () => form.reset(),
                            })
                        }
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
                const canCompleteThis = !f.completed_at && (canManage || (canComplete && f.assigned_to_user_id === userId));

                return (
                    <div key={f.id} className="rounded-md border p-3">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0">
                                <div className="text-sm font-medium">
                                    {status}
                                    {f.assigned_to?.name ? (
                                        <span className="text-slate-500"> • Assigned to {f.assigned_to.name}</span>
                                    ) : (
                                        <span className="text-slate-500"> • Unassigned</span>
                                    )}
                                </div>
                                <div className="mt-1 text-xs text-slate-500">
                                    {f.due_at ? `Due: ${new Date(f.due_at).toLocaleString()}` : 'No due date'}
                                    {f.completed_at ? ` • Completed: ${new Date(f.completed_at).toLocaleString()}` : ''}
                                </div>
                            </div>

                            {canCompleteThis ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={completeForm.processing}
                                    onClick={() =>
                                        completeForm.post(`/incidents/${incidentId}/followups/${f.id}/complete`, {
                                            preserveScroll: true,
                                            onSuccess: () => completeForm.reset(),
                                        })
                                    }
                                >
                                    Mark complete
                                </Button>
                            ) : null}
                        </div>

                        {f.notes ? (
                            <div className="mt-2 whitespace-pre-wrap text-sm">{f.notes}</div>
                        ) : (
                            <div className="mt-2 text-sm text-slate-500">No notes.</div>
                        )}
                    </div>
                );
            })}

            {!followups.length ? <div className="text-sm text-slate-500">No follow-ups yet.</div> : null}
        </div>
    );
}

export default function IncidentShow({ incident, staff, can, is_editable }: Props) {
    const { auth } = usePage().props as any;
    const meId = auth?.user?.id;

    const clientName = incident.client ? `${incident.client.first_name} ${incident.client.last_name}` : 'Client';

    const allowEdit = can.update && (is_editable || can.review); // managers can always edit
    const form = useForm({
        type: incident.type || 'other',
        severity: incident.severity || 'low',
        occurred_at: incident.occurred_at ? incident.occurred_at.slice(0, 16) : '',
        description: incident.description || '',
        requires_followup: !!incident.requires_followup,
        immediate_action_taken: incident.immediate_action_taken || '',
        witnesses: incident.witnesses || '',
        review_notes: incident.review_notes || '',
        portal_visible: !!incident.portal_visible,
    });

    const upload = useForm<{ file: File | null }>({ file: null });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Incidents', href: '/incidents' },
                { title: `Incident #${incident.id}`, href: `/incidents/${incident.id}` },
            ]}
        >
            <Head title={`Incident #${incident.id}`} />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Incident #{incident.id}</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            <Link className="underline" href={`/clients/${incident.client_id}`}>{clientName}</Link>
                            <span className="mx-2">•</span>
                            {incident.type} • {incident.severity} • {incident.status}
                            {incident.shift_id ? <span className="ml-2">• Shift-linked</span> : <span className="ml-2">• Standalone</span>}
                        </div>
                        {!is_editable && !can.review ? (
                            <div className="mt-1 text-xs text-slate-500">
                                This incident is read-only for the reporter.
                            </div>
                        ) : null}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {can.templatesManage && (
                            <Link href="/incidents/templates" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                Templates
                            </Link>
                        )}
                        <Link href={`/clients/${incident.client_id}/incidents`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Client incidents
                        </Link>

                        {can.submit && (
                            <Button size="sm" variant="outline" onClick={() => router.post(`/incidents/${incident.id}/submit`)}>
                                Submit
                            </Button>
                        )}

                        {can.review && (
                            <Button size="sm" variant="outline" onClick={() => router.post(`/incidents/${incident.id}/review`, { review_notes: form.data.review_notes })}>
                                Mark reviewed
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
                                <Input value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} disabled={!allowEdit} />
                            </div>

                            <div className="space-y-1">
                                <Label>Severity</Label>
                                <Select value={form.data.severity} onValueChange={(v) => form.setData('severity', v)} disabled={!allowEdit}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {['low', 'medium', 'high'].map((s) => (
                                            <SelectItem key={s} value={s}>{s}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1">
                                <Label>Occurred at</Label>
                                <Input type="datetime-local" value={form.data.occurred_at} onChange={(e) => form.setData('occurred_at', e.target.value)} disabled={!allowEdit} />
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            <Checkbox checked={!!form.data.requires_followup} onCheckedChange={(v) => form.setData('requires_followup', !!v)} disabled={!allowEdit} />
                            <Label>Requires follow-up</Label>
                        </div>

                        <div className="space-y-1">
                            <Label>Description</Label>
                            <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} disabled={!allowEdit} />
                        </div>

                        <div className="space-y-1">
                            <Label>Immediate action taken</Label>
                            <Textarea value={form.data.immediate_action_taken} onChange={(e) => form.setData('immediate_action_taken', e.target.value)} disabled={!allowEdit} />
                        </div>

                        <div className="space-y-1">
                            <Label>Witnesses</Label>
                            <Textarea value={form.data.witnesses} onChange={(e) => form.setData('witnesses', e.target.value)} disabled={!allowEdit} />
                        </div>

                        {can.review && (
                            <div className="space-y-1">
                                <Label>Review notes</Label>
                                <Textarea value={form.data.review_notes} onChange={(e) => form.setData('review_notes', e.target.value)} />
                            </div>
                        )}

                        {can.portalManage && (
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    checked={!!form.data.portal_visible}
                                    onCheckedChange={(v) => form.setData('portal_visible', !!v)}
                                />
                                <Label>Visible in portal (only shows once reviewed)</Label>
                            </div>
                        )}

                        {allowEdit && (
                            <div className="flex items-center justify-end">
                                <Button disabled={form.processing} onClick={() => form.put(`/incidents/${incident.id}`)}>Save</Button>
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
                            <FollowupCreator incidentId={incident.id} staff={(staff || []) as any} />
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
                        {(allowEdit && (is_editable || can.review)) ? (
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                                <div className="flex-1 space-y-1">
                                    <Label>Upload</Label>
                                    <Input
                                        type="file"
                                        onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                                    />
                                </div>
                                <Button
                                    disabled={upload.processing || !upload.data.file}
                                    onClick={() =>
                                        upload.post(`/incidents/${incident.id}/attachments`, {
                                            forceFormData: true,
                                            onSuccess: () => upload.reset(),
                                        })
                                    }
                                >
                                    Upload
                                </Button>
                            </div>
                        ) : (
                            <div className="text-sm text-slate-500">Attachments can only be changed while the incident is editable.</div>
                        )}

                        <div className="space-y-2">
                            {(incident.attachments || []).map((a: any) => (
                                <div key={a.id} className="flex items-center justify-between rounded-md border p-3">
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-medium">{a.original_name}</div>
                                        <div className="mt-1 text-xs text-slate-500">{a.size ? `${Math.round(a.size / 1024)} KB` : ''}</div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {can.portalManage && (
                                            <div className="flex items-center gap-2 rounded-md border px-2 py-1">
                                                <Checkbox
                                                    checked={!!a.portal_visible}
                                                    onCheckedChange={(v) =>
                                                        router.patch(
                                                            `/incidents/${incident.id}/attachments/${a.id}`,
                                                            { portal_visible: !!v },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                />
                                                <span className="text-xs text-slate-600">Portal</span>
                                            </div>
                                        )}
                                        <Link
                                            href={`/incidents/${incident.id}/attachments/${a.id}/download`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            Download
                                        </Link>
                                        {allowEdit && (is_editable || can.review) ? (
                                            <button
                                                className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                                onClick={() => router.delete(`/incidents/${incident.id}/attachments/${a.id}`)}
                                            >
                                                Remove
                                            </button>
                                        ) : null}
                                    </div>
                                </div>
                            ))}
                            {!(incident.attachments || []).length && (
                                <div className="text-sm text-slate-500">No attachments.</div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}


function FollowupComposer({ incidentId, staff }: { incidentId: number; staff: Array<{ id: number; name: string; email: string; role: string }> }) {
    const form = useForm({
        assigned_to_user_id: '',
        due_at: '',
        notes: '',
    });

    return (
        <div className="rounded-md border p-3">
            <div className="text-sm font-medium">Create follow-up</div>
            <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="space-y-1">
                    <Label>Assign to</Label>
                    <Select value={form.data.assigned_to_user_id} onValueChange={(v) => form.setData('assigned_to_user_id', v)}>
                        <SelectTrigger><SelectValue placeholder="Unassigned" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">Unassigned</SelectItem>
                            {staff.map((u) => (
                                <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="space-y-1">
                    <Label>Due at</Label>
                    <Input type="datetime-local" value={form.data.due_at} onChange={(e) => form.setData('due_at', e.target.value)} />
                </div>

                <div className="space-y-1">
                    <Label>Notes</Label>
                    <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                </div>
            </div>

            <div className="mt-3 flex items-center justify-end">
                <Button
                    size="sm"
                    disabled={form.processing}
                    onClick={() =>
                        form.post(`/incidents/${incidentId}/followups`, {
                            onSuccess: () => form.reset(),
                        })
                    }
                >
                    Add follow-up
                </Button>
            </div>
        </div>
    );
}
