import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type Props = {
    client: {
        id: number;
        first_name: string;
        last_name: string;
        preferred_name?: string | null;
        date_of_birth?: string | null;
        gender?: string | null;
        status: string;
        phone?: string | null;
        email?: string | null;
        address_line_1?: string | null;
        address_line_2?: string | null;
        suburb?: string | null;
        city?: string | null;
        postcode?: string | null;
        funding_type?: string | null;
        funding_notes?: string | null;
        site: { id: number; name: string } | null;
        support_workers: Array<{ id: number; name: string; email: string }>;
    };
    medical: {
        profile: any | null;
        medications: Array<any>;
        conditions: Array<any>;
        emergency_contacts: Array<any>;
    };
    support_plan: any | null;
    assessments: Array<any>;
    documents: Array<any>;
    portal_users: Array<any>;
    events: Array<any>;
    can: {
        edit: boolean;
        assign_workers: boolean;
        create_note?: boolean;
    };
};

type TabKey =
    | 'profile'
    | 'medical'
    | 'support_plan'
    | 'assessments'
    | 'timeline'
    | 'documents'
    | 'portal'
    | 'assignments';

export default function ClientShow({ client, medical, support_plan, assessments, documents, portal_users, events, can }: Props) {
    const name = `${client.first_name} ${client.last_name}`.trim();

    const tabs: Array<{ key: TabKey; label: string; show: boolean }> = useMemo(
        () => [
            { key: 'profile', label: 'Profile', show: true },
            { key: 'medical', label: 'Medical', show: true },
            { key: 'support_plan', label: 'Support plan', show: true },
            { key: 'assessments', label: 'Assessments', show: true },
            { key: 'timeline', label: 'Timeline', show: true },
            { key: 'documents', label: 'Documents', show: true },
            { key: 'portal', label: 'Next of Kin / Portal', show: true },
            { key: 'assignments', label: 'Assign workers', show: can.assign_workers || can.edit },
        ],
        [can.assign_workers, can.edit],
    );

    const [tab, setTab] = useState<TabKey>('profile');

    const noteForm = useForm<{ subject: string; body: string }>({
        subject: '',
        body: '',
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Clients', href: '/clients' },
                { title: name, href: `/clients/${client.id}` },
            ]}
        >
            <Head title={name} />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{name}</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Status: <span className="text-slate-700">{client.status}</span>
                            {client.site ? (
                                <>
                                    <span className="mx-2">•</span>
                                    Site: <span className="text-slate-700">{client.site.name}</span>
                                </>
                            ) : null}
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {can.edit && (
                            <Link
                                href={`/clients/${client.id}/edit`}
                                className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                            >
                                Edit
                            </Link>
                        )}
                        {(can.assign_workers || can.edit) && (
                            <Link
                                href={`/clients/${client.id}/assignments`}
                                className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                            >
                                Assign workers
                            </Link>
                        )}
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    {tabs
                        .filter((t) => t.show)
                        .map((t) => (
                            <Button
                                key={t.key}
                                variant={tab === t.key ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => setTab(t.key)}
                            >
                                {t.label}
                            </Button>
                        ))}
                </div>

                {tab === 'profile' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Profile</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="rounded-md border p-3">
                                <div className="text-sm font-medium">Details</div>
                                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div className="text-sm">
                                        <div className="text-xs text-slate-500">Preferred name</div>
                                        <div className="font-medium">{client.preferred_name || '—'}</div>
                                    </div>
                                    <div className="text-sm">
                                        <div className="text-xs text-slate-500">Date of birth</div>
                                        <div className="font-medium">{client.date_of_birth || '—'}</div>
                                    </div>
                                    <div className="text-sm">
                                        <div className="text-xs text-slate-500">Gender</div>
                                        <div className="font-medium">{client.gender || '—'}</div>
                                    </div>
                                    <div className="text-sm">
                                        <div className="text-xs text-slate-500">Phone</div>
                                        <div className="font-medium">{client.phone || '—'}</div>
                                    </div>
                                    <div className="text-sm">
                                        <div className="text-xs text-slate-500">Email</div>
                                        <div className="font-medium">{client.email || '—'}</div>
                                    </div>
                                    <div className="text-sm">
                                        <div className="text-xs text-slate-500">Funding</div>
                                        <div className="font-medium">{client.funding_type || '—'}</div>
                                    </div>
                                </div>
                                {(client.address_line_1 || client.city || client.postcode) && (
                                    <div className="mt-3 text-sm">
                                        <div className="text-xs text-slate-500">Address</div>
                                        <div className="font-medium">
                                            {[client.address_line_1, client.address_line_2, client.suburb, client.city, client.postcode]
                                                .filter(Boolean)
                                                .join(', ')}
                                        </div>
                                    </div>
                                )}
                                {client.funding_notes && (
                                    <div className="mt-3 text-sm">
                                        <div className="text-xs text-slate-500">Funding notes</div>
                                        <div className="whitespace-pre-wrap">{client.funding_notes}</div>
                                    </div>
                                )}
                            </div>

                            <div className="text-sm">
                                <div className="font-medium">Assigned support workers</div>
                                <div className="mt-2 space-y-2">
                                    {client.support_workers.map((w) => (
                                        <div key={w.id} className="rounded-md border p-2">
                                            <div className="text-sm font-medium">{w.name}</div>
                                            <div className="text-xs text-slate-500">{w.email}</div>
                                        </div>
                                    ))}
                                    {!client.support_workers.length && (
                                        <div className="text-sm text-slate-500">No workers assigned.</div>
                                    )}
                                </div>
                            </div>

                            <Separator />

                            <div className="flex flex-wrap gap-2">
                                <Link
                                    href={`/clients/${client.id}/medical`}
                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                >
                                    Open medical page
                                </Link>
                                <Link
                                    href={`/clients/${client.id}/documents`}
                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                >
                                    Open documents page
                                </Link>
                                <Link
                                    href={`/clients/${client.id}/portal-users`}
                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                >
                                    Manage portal users
                                </Link>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {tab === 'medical' && (
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Medical profile</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div>
                                    <div className="font-medium">Medical history</div>
                                    <div className="text-slate-600 whitespace-pre-wrap">{medical.profile?.medical_history || '-'}</div>
                                </div>
                                <div>
                                    <div className="font-medium">Disabilities</div>
                                    <div className="text-slate-600 whitespace-pre-wrap">{medical.profile?.disabilities || '-'}</div>
                                </div>
                                <div>
                                    <div className="font-medium">Allergies</div>
                                    <div className="text-slate-600 whitespace-pre-wrap">{medical.profile?.allergies || '-'}</div>
                                </div>
                                <div>
                                    <div className="font-medium">Notes</div>
                                    <div className="text-slate-600 whitespace-pre-wrap">{medical.profile?.notes || '-'}</div>
                                </div>

                                {can.edit && (
                                    <div>
                                        <Link
                                            href={`/clients/${client.id}/medical`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            Edit medical
                                        </Link>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Medications</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {medical.medications.map((m) => (
                                    <div key={m.id} className="rounded-md border p-3">
                                        <div className="text-sm font-medium">{m.name}</div>
                                        <div className="mt-1 text-xs text-slate-500">
                                            {[m.dosage && `Dosage: ${m.dosage}`, m.frequency && `Frequency: ${m.frequency}`, m.route && `Route: ${m.route}`]
                                                .filter(Boolean)
                                                .join(' - ') || '-'}
                                        </div>
                                        {m.instructions && <div className="mt-2 text-xs text-slate-600 whitespace-pre-wrap">{m.instructions}</div>}
                                    </div>
                                ))}
                                {!medical.medications.length && <div className="text-sm text-slate-500">No medications listed.</div>}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Conditions</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {medical.conditions.map((c) => (
                                    <div key={c.id} className="rounded-md border p-3">
                                        <div className="text-sm font-medium">
                                            {c.label}
                                            {c.severity ? <span className="ml-2 text-xs text-slate-500">({c.severity})</span> : null}
                                        </div>
                                        {c.notes && <div className="mt-2 text-xs text-slate-600 whitespace-pre-wrap">{c.notes}</div>}
                                    </div>
                                ))}
                                {!medical.conditions.length && <div className="text-sm text-slate-500">No conditions listed.</div>}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Emergency contacts</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {medical.emergency_contacts.map((e) => (
                                    <div key={e.id} className="rounded-md border p-3">
                                        <div className="text-sm font-medium">{e.name}</div>
                                        <div className="mt-1 text-xs text-slate-500">
                                            {[e.relationship && `Relationship: ${e.relationship}`, e.phone && `Phone: ${e.phone}`, e.email && `Email: ${e.email}`]
                                                .filter(Boolean)
                                                .join(' - ') || '-'}
                                        </div>
                                        {e.notes && <div className="mt-2 text-xs text-slate-600 whitespace-pre-wrap">{e.notes}</div>}
                                    </div>
                                ))}
                                {!medical.emergency_contacts.length && <div className="text-sm text-slate-500">No emergency contacts listed.</div>}
                            </CardContent>
                        </Card>
                    </div>
                )}

                {tab === 'support_plan' && (
                    <SupportPlanTab clientId={client.id} plan={support_plan} canEdit={can.edit} />
                )}

                {tab === 'assessments' && (
                    <AssessmentsTab clientId={client.id} assessments={assessments} canEdit={can.edit} />
                )}

                {tab === 'timeline' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Timeline</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {can.create_note && (
                                <div className="rounded-md border p-3">
                                    <div className="text-sm font-medium">Add note</div>
                                    <div className="mt-3 grid grid-cols-1 gap-3">
                                        <div>
                                            <Label>Subject (optional)</Label>
                                            <Input
                                                value={noteForm.data.subject}
                                                onChange={(e) => noteForm.setData('subject', e.target.value)}
                                            />
                                        </div>
                                        <div>
                                            <Label>Note</Label>
                                            <Textarea
                                                rows={3}
                                                value={noteForm.data.body}
                                                onChange={(e) => noteForm.setData('body', e.target.value)}
                                            />
                                        </div>
                                    </div>
                                    <div className="mt-3">
                                        <Button
                                            onClick={() =>
                                                noteForm.post(`/clients/${client.id}/notes`, {
                                                    preserveScroll: true,
                                                    onSuccess: () => noteForm.reset(),
                                                })
                                            }
                                            disabled={noteForm.processing || !noteForm.data.body}
                                        >
                                            Add
                                        </Button>
                                    </div>
                                </div>
                            )}

                            {events.map((e) => (
                                <div key={e.id} className="rounded-md border p-3">
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="text-sm font-medium">{e.subject || e.type}</div>
                                        <div className="text-xs text-slate-500">{e.occurred_at ? new Date(e.occurred_at).toLocaleString() : ''}</div>
                                    </div>
                                    {e.body && <div className="mt-1 text-xs text-slate-600 whitespace-pre-wrap">{e.body}</div>}
                                    <div className="mt-2 text-xs text-slate-500">
                                        {e.actor?.name ? `By ${e.actor.name}` : ''} {e.site?.name ? `- ${e.site.name}` : ''}
                                    </div>
                                </div>
                            ))}
                            {!events.length && <div className="text-sm text-slate-500">No timeline events yet.</div>}

                            <div className="pt-2">
                                <Link
                                    href={`/clients/${client.id}/timeline`}
                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                >
                                    Open full timeline
                                </Link>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {tab === 'documents' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Documents</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {documents.map((d) => (
                                <div key={d.id} className="flex items-start justify-between gap-3 rounded-md border p-3">
                                    <div>
                                        <div className="text-sm font-medium">{d.title || d.original_name}</div>
                                        <div className="mt-1 text-xs text-slate-500">
                                            {[d.category && `Category: ${d.category}`, d.mime_type && d.mime_type].filter(Boolean).join(' - ') || '-'}
                                        </div>
                                        {d.notes && <div className="mt-2 text-xs text-slate-600 whitespace-pre-wrap">{d.notes}</div>}
                                    </div>
                                    <a
                                        href={`/clients/${client.id}/documents/${d.id}/download`}
                                        className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                    >
                                        Download
                                    </a>
                                </div>
                            ))}
                            {!documents.length && <div className="text-sm text-slate-500">No documents uploaded.</div>}

                            <div className="pt-2">
                                <Link
                                    href={`/clients/${client.id}/documents`}
                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                >
                                    Manage documents
                                </Link>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {tab === 'portal' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Portal access (Client / Next of Kin)</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-sm text-slate-600">
                                Portal users can view this client’s medical, documents, and timeline, and can query the RAG assistant.
                            </div>
                            <Separator />
                            <div className="space-y-2">
                                {portal_users.map((u) => (
                                    <div key={u.id} className="rounded-md border p-3">
                                        <div className="text-sm font-medium">{u.name}</div>
                                        <div className="text-xs text-slate-500">{u.email}</div>
                                        {u.relation && <div className="mt-1 text-xs text-slate-500">Relation: {u.relation}</div>}
                                    </div>
                                ))}
                                {!portal_users.length && <div className="text-sm text-slate-500">No portal users linked.</div>}
                            </div>

                            {can.edit && (
                                <div className="pt-2">
                                    <Link
                                        href={`/clients/${client.id}/portal-users`}
                                        className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                    >
                                        Manage portal users
                                    </Link>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {tab === 'assignments' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Assignments</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-sm text-slate-600">
                                Assign support workers to this client. This controls which staff can see the client.
                            </div>
                            <div className="pt-2">
                                <Link
                                    href={`/clients/${client.id}/assignments`}
                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                >
                                    Open assignments
                                </Link>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}

function SupportPlanTab({ clientId, plan, canEdit }: { clientId: number; plan: any | null; canEdit: boolean }) {
    const form = useForm<{
        goals: string;
        routines: string;
        preferences: string;
        communication_needs: string;
        cultural_needs: string;
        risk_notes: string;
        reviewed_at: string;
        next_review_at: string;
    }>({
        goals: plan?.goals ?? '',
        routines: plan?.routines ?? '',
        preferences: plan?.preferences ?? '',
        communication_needs: plan?.communication_needs ?? '',
        cultural_needs: plan?.cultural_needs ?? '',
        risk_notes: plan?.risk_notes ?? '',
        reviewed_at: plan?.reviewed_at ?? '',
        next_review_at: plan?.next_review_at ?? '',
    });

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Support plan</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                {!canEdit && !plan && <div className="text-sm text-slate-500">No support plan recorded.</div>}

                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div>
                        <Label>Reviewed at</Label>
                        <Input type="date" value={form.data.reviewed_at} onChange={(e) => form.setData('reviewed_at', e.target.value)} disabled={!canEdit} />
                    </div>
                    <div>
                        <Label>Next review</Label>
                        <Input type="date" value={form.data.next_review_at} onChange={(e) => form.setData('next_review_at', e.target.value)} disabled={!canEdit} />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Goals</Label>
                        <Textarea rows={4} value={form.data.goals} onChange={(e) => form.setData('goals', e.target.value)} disabled={!canEdit} />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Daily routines</Label>
                        <Textarea rows={4} value={form.data.routines} onChange={(e) => form.setData('routines', e.target.value)} disabled={!canEdit} />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Preferences</Label>
                        <Textarea rows={4} value={form.data.preferences} onChange={(e) => form.setData('preferences', e.target.value)} disabled={!canEdit} />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Communication needs</Label>
                        <Textarea rows={4} value={form.data.communication_needs} onChange={(e) => form.setData('communication_needs', e.target.value)} disabled={!canEdit} />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Cultural needs</Label>
                        <Textarea rows={3} value={form.data.cultural_needs} onChange={(e) => form.setData('cultural_needs', e.target.value)} disabled={!canEdit} />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Risk notes</Label>
                        <Textarea rows={3} value={form.data.risk_notes} onChange={(e) => form.setData('risk_notes', e.target.value)} disabled={!canEdit} />
                    </div>
                </div>

                {canEdit && (
                    <div>
                        <Button
                            onClick={() =>
                                form.put(`/clients/${clientId}/support-plan`, {
                                    preserveScroll: true,
                                })
                            }
                            disabled={form.processing}
                        >
                            Save support plan
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function AssessmentsTab({ clientId, assessments, canEdit }: { clientId: number; assessments: Array<any>; canEdit: boolean }) {
    const [editingId, setEditingId] = useState<number | null>(null);

    const form = useForm<{
        type: string;
        score: string;
        assessed_at: string;
        next_review_at: string;
        notes: string;
    }>({
        type: '',
        score: '',
        assessed_at: '',
        next_review_at: '',
        notes: '',
    });

    function startEdit(a: any) {
        setEditingId(a.id);
        form.setData({
            type: a.type ?? '',
            score: a.score ?? '',
            assessed_at: a.assessed_at ?? '',
            next_review_at: a.next_review_at ?? '',
            notes: a.notes ?? '',
        });
    }

    function resetForm() {
        setEditingId(null);
        form.reset();
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Assessments</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                {canEdit && (
                    <div className="rounded-md border p-3">
                        <div className="flex items-center justify-between gap-3">
                            <div className="text-sm font-medium">{editingId ? 'Edit assessment' : 'Add assessment'}</div>
                            {editingId ? (
                                <Button variant="ghost" onClick={resetForm}>
                                    Cancel
                                </Button>
                            ) : null}
                        </div>
                        <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <Label>Type</Label>
                                <Input value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} placeholder="e.g. WHODAS, risk, medication review" />
                            </div>
                            <div>
                                <Label>Score (optional)</Label>
                                <Input value={form.data.score} onChange={(e) => form.setData('score', e.target.value)} />
                            </div>
                            <div>
                                <Label>Assessed at</Label>
                                <Input type="date" value={form.data.assessed_at} onChange={(e) => form.setData('assessed_at', e.target.value)} />
                            </div>
                            <div>
                                <Label>Next review</Label>
                                <Input type="date" value={form.data.next_review_at} onChange={(e) => form.setData('next_review_at', e.target.value)} />
                            </div>
                            <div className="md:col-span-2">
                                <Label>Notes</Label>
                                <Textarea rows={3} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                            </div>
                        </div>
                        <div className="mt-3 flex items-center gap-2">
                            <Button
                                onClick={() => {
                                    const url = editingId
                                        ? `/clients/${clientId}/assessments/${editingId}`
                                        : `/clients/${clientId}/assessments`;
                                    const method = editingId ? 'put' : 'post';
                                    // @ts-ignore
                                    form[method](url, {
                                        preserveScroll: true,
                                        onSuccess: () => resetForm(),
                                    });
                                }}
                                disabled={form.processing || !form.data.type}
                            >
                                Save
                            </Button>
                        </div>
                    </div>
                )}

                <div className="space-y-2">
                    {assessments.map((a) => (
                        <div key={a.id} className="rounded-md border p-3">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <div className="text-sm font-medium">{a.type}</div>
                                    <div className="mt-1 text-xs text-slate-500">
                                        {[a.score && `Score: ${a.score}`, a.assessed_at && `Assessed: ${a.assessed_at}`, a.next_review_at && `Next review: ${a.next_review_at}`]
                                            .filter(Boolean)
                                            .join(' • ') || '-'}
                                    </div>
                                    {a.notes && <div className="mt-2 text-xs text-slate-600 whitespace-pre-wrap">{a.notes}</div>}
                                </div>

                                {canEdit && (
                                    <div className="flex items-center gap-2">
                                        <Button variant="secondary" onClick={() => startEdit(a)}>
                                            Edit
                                        </Button>
                                        <Button
                                            variant="destructive"
                                            onClick={() =>
                                                form.delete(`/clients/${clientId}/assessments/${a.id}`, {
                                                    preserveScroll: true,
                                                })
                                            }
                                        >
                                            Delete
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </div>
                    ))}

                    {!assessments.length && <div className="text-sm text-slate-500">No assessments recorded.</div>}
                </div>
            </CardContent>
        </Card>
    );
}
