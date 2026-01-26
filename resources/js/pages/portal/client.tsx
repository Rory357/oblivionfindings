import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { toast } from 'sonner';

type Props = {
    client: { id: number; first_name: string; last_name: string; avatar?: string | null; profile_photo_url?: string | null };
    profile: any | null;
    medications: Array<any>;
    conditions: Array<any>;
    emergency_contacts: Array<any>;
    documents: Array<any>;
    incidents: Array<any>;
    events: Array<any>;
    rag_answer?: { text: string | null; sources?: Array<any> } | null;
    can?: { viewIncidents: boolean; downloadIncidentAttachments: boolean };
};

export default function PortalClient({
    client,
    profile,
    medications,
    conditions,
    emergency_contacts,
    documents,
    incidents,
    events,
    rag_answer,
    can,
}: Props) {
    const form = useForm({ question: '' });
    const getInitials = useInitials();

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        form.post(`/portal/clients/${client.id}/rag/ask`, {
            preserveScroll: true,
            onError: (errors) => {
                const msg =
                    (errors as any)?.question ||
                    'Something went wrong while generating an answer.';
                toast.error(msg);
            },
        });
    };

    const name = `${client.first_name} ${client.last_name}`.trim();

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                { title: name, href: `/portal/clients/${client.id}` },
            ]}
        >
            <Head title={`Portal - ${name}`} />

            <div className="flex items-center gap-3 rounded-md border p-4" data-test="portal-client-header">
                <Avatar className="h-10 w-10">
                    <AvatarImage src={(client as any).avatar ?? (client as any).profile_photo_url ?? undefined} alt={name} />
                    <AvatarFallback>{getInitials(name)}</AvatarFallback>
                </Avatar>
                <div>
                    <div className="text-lg font-semibold">{name}</div>
                    <div className="text-sm text-muted-foreground">Client portal</div>
                </div>
            </div>

            <div className="space-y-4">
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    {/* LEFT COLUMN */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Medical profile
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div>
                                <div className="font-medium">
                                    Medical history
                                </div>
                                <div className="whitespace-pre-wrap text-slate-600">
                                    {profile?.medical_history || '-'}
                                </div>
                            </div>

                            <div>
                                <div className="font-medium">Disabilities</div>
                                <div className="whitespace-pre-wrap text-slate-600">
                                    {profile?.disabilities || '-'}
                                </div>
                            </div>

                            <div>
                                <div className="font-medium">Allergies</div>
                                <div className="whitespace-pre-wrap text-slate-600">
                                    {profile?.allergies || '-'}
                                </div>
                            </div>

                            <div>
                                <div className="font-medium">Notes</div>
                                <div className="whitespace-pre-wrap text-slate-600">
                                    {profile?.notes || '-'}
                                </div>
                            </div>

                            <Separator />

                            <div>
                                <div className="font-medium">Medications</div>
                                <div className="mt-2 space-y-2">
                                    {medications.map((m) => (
                                        <div
                                            key={m.id}
                                            className="rounded-md border p-2"
                                        >
                                            <div className="text-sm font-medium">
                                                {m.name}
                                            </div>
                                            <div className="text-xs text-slate-500">
                                                {[
                                                    m.dosage &&
                                                        `Dosage: ${m.dosage}`,
                                                    m.frequency &&
                                                        `Frequency: ${m.frequency}`,
                                                    m.route &&
                                                        `Route: ${m.route}`,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' - ') || '-'}
                                            </div>
                                            {m.instructions && (
                                                <div className="mt-1 text-xs whitespace-pre-wrap text-slate-600">
                                                    {m.instructions}
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                    {!medications.length && (
                                        <div className="text-xs text-slate-500">
                                            No medications listed.
                                        </div>
                                    )}
                                </div>
                            </div>

                            <Separator />

                            <div>
                                <div className="font-medium">Conditions</div>
                                <div className="mt-2 space-y-2">
                                    {conditions.map((c) => (
                                        <div
                                            key={c.id}
                                            className="rounded-md border p-2"
                                        >
                                            <div className="text-sm font-medium">
                                                {c.label}
                                                {c.severity && (
                                                    <span className="ml-2 text-xs text-slate-500">
                                                        ({c.severity})
                                                    </span>
                                                )}
                                            </div>
                                            {c.notes && (
                                                <div className="mt-1 text-xs whitespace-pre-wrap text-slate-600">
                                                    {c.notes}
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                    {!conditions.length && (
                                        <div className="text-xs text-slate-500">
                                            No conditions listed.
                                        </div>
                                    )}
                                </div>
                            </div>

                            <Separator />

                            <div>
                                <div className="font-medium">
                                    Emergency contacts
                                </div>
                                <div className="mt-2 space-y-2">
                                    {emergency_contacts.map((e) => (
                                        <div
                                            key={e.id}
                                            className="rounded-md border p-2"
                                        >
                                            <div className="text-sm font-medium">
                                                {e.name}
                                            </div>
                                            <div className="text-xs text-slate-500">
                                                {[
                                                    e.relationship &&
                                                        `Relationship: ${e.relationship}`,
                                                    e.phone &&
                                                        `Phone: ${e.phone}`,
                                                    e.email &&
                                                        `Email: ${e.email}`,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' - ') || '-'}
                                            </div>
                                            {e.notes && (
                                                <div className="mt-1 text-xs whitespace-pre-wrap text-slate-600">
                                                    {e.notes}
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                    {!emergency_contacts.length && (
                                        <div className="text-xs text-slate-500">
                                            No emergency contacts listed.
                                        </div>
                                    )}
                                </div>
                            </div>

                            <Separator />

                            <div>
                                <div className="font-medium">Documents</div>
                                <div className="mt-2 space-y-2">
                                    {documents.map((d) => (
                                        <div
                                            key={d.id}
                                            className="flex items-start justify-between gap-3 rounded-md border p-2"
                                        >
                                            <div>
                                                <div className="text-sm font-medium">
                                                    {d.title || d.original_name}
                                                </div>
                                                <div className="text-xs text-slate-500">
                                                    {[
                                                        d.category &&
                                                            `Category: ${d.category}`,
                                                        d.mime_type &&
                                                            d.mime_type,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' - ') || '-'}
                                                </div>
                                                {d.notes && (
                                                    <div className="mt-1 text-xs whitespace-pre-wrap text-slate-600">
                                                        {d.notes}
                                                    </div>
                                                )}
                                            </div>
                                            <a
                                                href={`/portal/clients/${client.id}/documents/${d.id}/download`}
                                                className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                            >
                                                Download
                                            </a>
                                        </div>
                                    ))}
                                    {!documents.length && (
                                        <div className="text-xs text-slate-500">
                                            No documents uploaded.
                                        </div>
                                    )}
                                </div>
                            </div>

                            {can?.viewIncidents ? (
                                <>
                                    <Separator />

                                    <div>
                                        <div className="font-medium">
                                            Incident reports
                                        </div>
                                        <div className="mt-2 space-y-2">
                                            {incidents.map((i) => (
                                                <div
                                                    key={i.id}
                                                    className="rounded-md border p-2"
                                                >
                                                    <div className="flex items-start justify-between gap-3">
                                                        <div>
                                                            <div className="text-sm font-medium">
                                                                {i.type}{' '}
                                                                <span className="ml-2 text-xs text-slate-500">
                                                                    ({i.severity})
                                                                </span>
                                                            </div>
                                                            <div className="text-xs text-slate-500">
                                                                {i.occurred_at
                                                                    ? new Date(
                                                                          i.occurred_at,
                                                                      ).toLocaleString()
                                                                    : ''}
                                                            </div>
                                                        </div>
                                                    </div>

                                                    {i.description && (
                                                        <div className="mt-2 text-xs whitespace-pre-wrap text-slate-600">
                                                            {i.description}
                                                        </div>
                                                    )}

                                                    {i.immediate_action_taken && (
                                                        <div className="mt-2 text-xs whitespace-pre-wrap text-slate-600">
                                                            <span className="font-medium text-slate-700">
                                                                Immediate action:
                                                            </span>{' '}
                                                            {i.immediate_action_taken}
                                                        </div>
                                                    )}

                                                    {!!(i.attachments || []).length && (
                                                        <div className="mt-3 space-y-2">
                                                            <div className="text-xs font-medium text-slate-600">
                                                                Attachments
                                                            </div>
                                                            {(i.attachments || []).map((a: any) => (
                                                                <div
                                                                    key={a.id}
                                                                    className="flex items-center justify-between gap-3 rounded-md border p-2"
                                                                >
                                                                    <div className="min-w-0">
                                                                        <div className="truncate text-xs font-medium">
                                                                            {a.original_name}
                                                                        </div>
                                                                    </div>
                                                                    {a.download_url ? (
                                                                        <a
                                                                            href={a.download_url}
                                                                            className="rounded-md border px-3 py-1 text-xs hover:bg-muted"
                                                                        >
                                                                            Download
                                                                        </a>
                                                                    ) : null}
                                                                </div>
                                                            ))}
                                                        </div>
                                                    )}
                                                </div>
                                            ))}
                                            {!incidents.length && (
                                                <div className="text-xs text-slate-500">
                                                    No shared incidents.
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </>
                            ) : null}
                        </CardContent>
                    </Card>

                    {/* RIGHT COLUMN */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Ask about {name}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <form onSubmit={submit} className="space-y-2">
                                <Label htmlFor="q">Question</Label>
                                <Input
                                    id="q"
                                    value={form.data.question}
                                    onChange={(e) =>
                                        form.setData('question', e.target.value)
                                    }
                                    placeholder="e.g., What happened at the last appointment?"
                                />

                                <Button
                                    type="submit"
                                    disabled={
                                        form.processing ||
                                        !form.data.question.trim()
                                    }
                                >
                                    Ask
                                </Button>
                            </form>

                            {rag_answer?.text && (
                                <div className="rounded-md border bg-white p-3 text-sm whitespace-pre-wrap">
                                    {rag_answer.text}
                                </div>
                            )}

                            {!!rag_answer?.sources?.length && (
                                <div className="rounded-md border bg-white p-3">
                                    <div className="text-xs font-medium text-slate-600">
                                        Sources
                                    </div>
                                    <div className="mt-2 space-y-2">
                                        {rag_answer.sources.map((s, idx) => (
                                            <div
                                                key={idx}
                                                className="rounded-md border p-2"
                                            >
                                                <div className="text-xs text-slate-500">
                                                    {s.filename ||
                                                        s.file_id ||
                                                        'Source'}
                                                </div>
                                                <div className="mt-1 text-xs whitespace-pre-wrap text-slate-600">
                                                    {s.text}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            <div className="text-xs text-slate-500">
                                Answers are generated from the client timeline +
                                medical details.
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Recent timeline
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {events.map((e) => (
                            <div key={e.id} className="rounded-md border p-3">
                                <div className="flex items-center justify-between gap-3">
                                    <div className="text-sm font-medium">
                                        {e.subject || e.type}
                                    </div>
                                    <div className="text-xs text-slate-500">
                                        {e.occurred_at
                                            ? new Date(
                                                  e.occurred_at,
                                              ).toLocaleString()
                                            : ''}
                                    </div>
                                </div>
                                {e.body && (
                                    <div className="mt-1 text-xs whitespace-pre-wrap text-slate-600">
                                        {e.body}
                                    </div>
                                )}
                                <div className="mt-2 text-xs text-slate-500">
                                    {e.actor?.name ? `By ${e.actor.name}` : ''}{' '}
                                    {e.site?.name ? `- ${e.site.name}` : ''}
                                </div>
                            </div>
                        ))}
                        {!events.length && (
                            <div className="text-sm text-slate-500">
                                No timeline events yet.
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
