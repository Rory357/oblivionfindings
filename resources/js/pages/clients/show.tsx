import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type Props = {
    client: {
        id: number;
        first_name: string;
        last_name: string;
        status: string;
        site: { id: number; name: string } | null;
        support_workers: Array<{ id: number; name: string; email: string }>;
    };
    medical: {
        profile: any | null;
        medications: Array<any>;
        conditions: Array<any>;
        emergency_contacts: Array<any>;
    };
    documents: Array<any>;
    portal_users: Array<any>;
    events: Array<any>;
    can: {
        edit: boolean;
        assign_workers: boolean;
    };
};

type TabKey = 'profile' | 'medical' | 'timeline' | 'documents' | 'portal' | 'assignments';

export default function ClientShow({ client, medical, documents, portal_users, events, can }: Props) {
    const name = `${client.first_name} ${client.last_name}`.trim();

    const tabs: Array<{ key: TabKey; label: string; show: boolean }> = useMemo(
        () => [
            { key: 'profile', label: 'Profile', show: true },
            { key: 'medical', label: 'Medical', show: true },
            { key: 'timeline', label: 'Timeline', show: true },
            { key: 'documents', label: 'Documents', show: true },
            { key: 'portal', label: 'Next of Kin / Portal', show: true },
            { key: 'assignments', label: 'Assign workers', show: can.assign_workers || can.edit },
        ],
        [can.assign_workers, can.edit],
    );

    const [tab, setTab] = useState<TabKey>('profile');

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

                {tab === 'timeline' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Timeline</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
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
