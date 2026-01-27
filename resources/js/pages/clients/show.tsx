import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Separator } from '@/components/ui/separator';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Tabs } from '@/components/ui/tabs';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type Props = {
    client: {
        id: number;
        first_name: string;
        last_name: string;
        avatar?: string | null;
        profile_photo_url?: string | null;
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
        service_context?: { id: number; type: string | null; name: string } | null;
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
    handover: Array<any>;
    shifts_summary?: {
        next: any | null;
        last: any | null;
    };
    onboarding: {
        items: Array<{ key: string; label: string; has_data: boolean; override: boolean; complete: boolean }>;
        completed: number;
        total: number;
        percent: number;
        status: 'complete' | 'incomplete';
    };
    can: {
        edit: boolean;
        assign_workers: boolean;
        create_note?: boolean;
        pin_handover?: boolean;
        manage_onboarding?: boolean;
        create_shift?: boolean;
    };
};

type TabKey =
    | 'profile'
    | 'medical'
    | 'mar'
    | 'support_plan'
    | 'assessments'
    | 'timeline'
    | 'documents'
    | 'portal'
    | 'assignments';

export default function ClientShow({ client, medical, support_plan, assessments, documents, portal_users, events, handover, onboarding, shifts_summary, can }: Props) {
    const name = `${client.first_name} ${client.last_name}`.trim();
    const getInitials = useInitials();
    const photoForm = useForm<{ photo: File | null }>({ photo: null });
    const removePhotoForm = useForm({});

    const tabs: Array<{ key: TabKey; label: string; show: boolean }> = useMemo(
        () => [
            { key: 'profile', label: 'Profile', show: true },
            { key: 'medical', label: 'Medical', show: true },
            { key: 'mar', label: 'MAR', show: true },
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

    const templates = [
        { key: 'note', label: 'Note', body: '' },
        { key: 'progress_note', label: 'Progress note', body: 'Goal/outcome:\n\nWhat happened:\n\nNext steps:' },
        { key: 'handover', label: 'Handover', body: 'Key points for next shift:\n-\n-\n\nRisks/alerts:\n-\n\nActions needed:\n-' },
    ];

    const noteForm = useForm<{ type: string; subject: string; goal: string; body: string; visibility: string; pin: boolean }>({
        type: 'note',
        subject: '',
        goal: '',
        body: '',
        visibility: 'internal',
        pin: false,
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Clients', href: '/clients' },
                { title: name, href: `/clients/${client.id}` },
            ]}
        >
            <Head title={name} />

            <PageShell>
                <PageHeader
                    title={
                        <div className="flex items-center gap-3">
                            <Avatar className="h-10 w-10">
                                <AvatarImage src={client.avatar ?? client.profile_photo_url ?? undefined} alt={name} />
                                <AvatarFallback>{getInitials(name)}</AvatarFallback>
                            </Avatar>
                            <span>{name}</span>
                        </div>
                    }
                    backHref="/clients"
                    description={`${client.status}${client.service_context ? ` • ${client.service_context.name}` : ''}${client.site ? ` • ${client.site.name}` : ''}`}
                    actions={
                        <>
                            {can.edit ? (
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        if (!photoForm.data.photo) return;
                                        photoForm.post(`/clients/${client.id}/photo`, {
                                            forceFormData: true,
                                            preserveScroll: true,
                                        });
                                    }}
                                    className="flex items-center gap-2"
                                >
                                    <Input
                                        type="file"
                                        accept="image/*"
                                        className="hidden"
                                        id="client-photo"
                                        onChange={(e) =>
                                            photoForm.setData(
                                                'photo',
                                                e.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            (document.getElementById(
                                                'client-photo',
                                            ) as HTMLInputElement | null)?.click()
                                        }
                                    >
                                        Change photo
                                    </Button>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={
                                            photoForm.processing ||
                                            !photoForm.data.photo
                                        }
                                    >
                                        Upload
                                    </Button>

                                    {(client as any).profile_photo_path ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={removePhotoForm.processing}
                                            onClick={() =>
                                                removePhotoForm.delete(
                                                    `/clients/${client.id}/photo`,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Remove
                                        </Button>
                                    ) : null}
                                </form>
                            ) : null}

                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/clients/${client.id}/incidents`}>Incidents</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/clients/${client.id}/risks`}>Risks</Link>
                            </Button>
                            {can.edit ? (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={`/clients/${client.id}/edit`}>Edit</Link>
                                </Button>
                            ) : null}
                            {(can.assign_workers || can.edit) ? (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={`/clients/${client.id}/assignments`}>Assign workers</Link>
                                </Button>
                            ) : null}
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/assets?client_id=${client.id}`}>Assets</Link>
                            </Button>
                            {can.edit ? (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={`/assets/create?client_id=${client.id}`}>Add asset</Link>
                                </Button>
                            ) : null}

                        </>
                    }
                />

                <div className="-mx-4 overflow-x-auto px-4">
                    <div className="flex w-max items-center gap-2 pb-1">
                    {tabs
                        .filter((t) => t.show)
                        .map((t) => (
                            <Button
                                key={t.key}
                                variant={tab === t.key ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => {
                                    if (t.key === 'mar') {
                                        window.location.href = `/clients/${client.id}/mar`;
                                        return;
                                    }
                                    setTab(t.key);
                                }}
                            >
                                {t.label}
                            </Button>
                        ))}
                    </div>
                </div>

                {tab === 'profile' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Profile</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="rounded-md border p-3">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <div className="text-sm font-medium">Onboarding checklist</div>
                                        <div className="text-xs text-slate-500">{onboarding.completed}/{onboarding.total} complete • {onboarding.percent}%</div>
                                    </div>
                                    <div className={`rounded-full px-2 py-1 text-xs ${onboarding.status === 'complete' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                                        {onboarding.status === 'complete' ? 'Complete' : 'In progress'}
                                    </div>
                                </div>

                                <Separator className="my-3" />

                                <div className="space-y-2">
                                    {onboarding.items.map((item) => (
                                        <div key={item.key} className="flex flex-col gap-2 rounded-md border p-2 sm:flex-row sm:items-center sm:justify-between">
                                            <div className="flex items-start gap-2">
                                                <div className={`mt-0.5 h-2 w-2 rounded-full ${item.complete ? 'bg-emerald-500' : 'bg-slate-300'}`} />
                                                <div>
                                                    <div className="text-sm font-medium">{item.label}</div>
                                                    <div className="text-xs text-slate-500">
                                                        {item.complete ? (item.has_data ? 'Added' : 'Marked as not applicable') : 'Not completed'}
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-3">
                                                {!item.has_data && (can.manage_onboarding || can.edit) ? (
                                                    <label className="flex cursor-pointer items-center gap-2 text-xs text-slate-600">
                                                        <Checkbox
                                                            checked={item.override}
                                                            onCheckedChange={(v) => {
                                                                router.post(
                                                                    `/clients/${client.id}/onboarding/${item.key}`,
                                                                    { checked: !!v },
                                                                    { preserveScroll: true },
                                                                );
                                                            }}
                                                        />
                                                        Doesn't have this
                                                    </label>
                                                ) : null}

                                                {item.key === 'profile' ? (
                                                    can.edit ? (
                                                        <Button size="sm" variant="outline" asChild>
                                                            <Link href={`/clients/${client.id}/edit`}>Open</Link>
                                                        </Button>
                                                    ) : (
                                                        <Button size="sm" variant="outline" disabled>
                                                            Review
                                                        </Button>
                                                    )
                                                ) : item.key === 'medications' ? (
                                                    <Button size="sm" variant="outline" asChild>
                                                        <Link href={`/clients/${client.id}/medical?section=medications`}>Open</Link>
                                                    </Button>
                                                ) : item.key === 'conditions' ? (
                                                    <Button size="sm" variant="outline" asChild>
                                                        <Link href={`/clients/${client.id}/medical?section=conditions`}>Open</Link>
                                                    </Button>
                                                ) : item.key === 'emergency_contacts' ? (
                                                    <Button size="sm" variant="outline" asChild>
                                                        <Link href={`/clients/${client.id}/medical?section=emergency_contacts`}>Open</Link>
                                                    </Button>
                                                ) : item.key === 'next_of_kin' ? (
                                                    can.edit ? (
                                                        <Button size="sm" variant="outline" asChild>
                                                            <Link href={`/clients/${client.id}/portal-users`}>Open</Link>
                                                        </Button>
                                                    ) : null
                                                ) : item.key === 'documents' ? (
                                                    <Button size="sm" variant="outline" asChild>
                                                        <Link href={`/clients/${client.id}/documents`}>Open</Link>
                                                    </Button>
                                                ) : item.key === 'history' ? (
                                                    <Button size="sm" variant="outline" onClick={() => setTab('support_plan')}>Open</Button>
                                                ) : (
                                                    <Button size="sm" variant="outline" disabled>
                                                        Review
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>

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

                            <div className="rounded-md border p-3">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <div className="text-sm font-medium">Shifts</div>
                                        <div className="text-xs text-slate-500">Next and recent rostered shifts for this client.</div>
                                    </div>
                                    {can.create_shift ? (
                                        <Button size="sm" asChild>
                                            <Link href={`/shifts/create?client_id=${client.id}`}>Create shift</Link>
                                        </Button>
                                    ) : null}
                                </div>

                                <Separator className="my-3" />

                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div className="rounded-md border p-3">
                                        <div className="text-xs text-slate-500">Next shift</div>
                                        {shifts_summary?.next ? (
                                            <div className="mt-1 space-y-1">
                                                <div className="text-sm font-medium">
                                                    {new Date(shifts_summary.next.starts_at).toLocaleString()} – {new Date(shifts_summary.next.ends_at).toLocaleTimeString()}
                                                </div>
                                                <div className="text-xs text-slate-500">
                                                    {shifts_summary.next.staff?.name ? `Staff: ${shifts_summary.next.staff.name}` : 'Staff: —'}
                                                    {shifts_summary.next.location ? ` • ${shifts_summary.next.location}` : ''}
                                                </div>
                                                <div className="mt-2">
                                                    <Button variant="outline" size="sm" asChild>
                                                        <Link href={`/shifts/${shifts_summary.next.id}`}>Open</Link>
                                                    </Button>
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="mt-1 text-sm text-slate-500">No upcoming shifts.</div>
                                        )}
                                    </div>

                                    <div className="rounded-md border p-3">
                                        <div className="text-xs text-slate-500">Most recent shift</div>
                                        {shifts_summary?.last ? (
                                            <div className="mt-1 space-y-1">
                                                <div className="text-sm font-medium">
                                                    {new Date(shifts_summary.last.starts_at).toLocaleString()} – {new Date(shifts_summary.last.ends_at).toLocaleTimeString()}
                                                </div>
                                                <div className="text-xs text-slate-500">
                                                    {shifts_summary.last.staff?.name ? `Staff: ${shifts_summary.last.staff.name}` : 'Staff: —'}
                                                    {shifts_summary.last.location ? ` • ${shifts_summary.last.location}` : ''}
                                                </div>
                                                <div className="mt-2">
                                                    <Button variant="outline" size="sm" asChild>
                                                        <Link href={`/shifts/${shifts_summary.last.id}`}>Open</Link>
                                                    </Button>
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="mt-1 text-sm text-slate-500">No previous shifts yet.</div>
                                        )}
                                    </div>
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
                        <Tabs
                            tabs={[
                                {
                                    key: 'medical_profile',
                                    label: 'Medical profile',
                                    content: (
                                        <Card>
                                            <CardHeader>
                                                <div className="flex items-center justify-between gap-3">
                                                    <CardTitle className="text-base">Medical profile</CardTitle>
                                                    {can.edit ? (
                                                        <Button variant="outline" size="sm" asChild>
                                                            <Link href={`/clients/${client.id}/medical`}>Edit</Link>
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </CardHeader>
                                            <CardContent className="space-y-3 text-sm">
                                                <div>
                                                    <div className="font-medium">Medical history</div>
                                                    <div className="whitespace-pre-wrap text-slate-600">{medical.profile?.medical_history || '-'}</div>
                                                </div>
                                                <div>
                                                    <div className="font-medium">Disabilities</div>
                                                    <div className="whitespace-pre-wrap text-slate-600">{medical.profile?.disabilities || '-'}</div>
                                                </div>
                                                <div>
                                                    <div className="font-medium">Allergies</div>
                                                    <div className="whitespace-pre-wrap text-slate-600">{medical.profile?.allergies || '-'}</div>
                                                </div>
                                                <div>
                                                    <div className="font-medium">Notes</div>
                                                    <div className="whitespace-pre-wrap text-slate-600">{medical.profile?.notes || '-'}</div>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    ),
                                },
                                {
                                    key: 'medications',
                                    label: `Medications${medical.medications.length ? ` (${medical.medications.length})` : ''}`,
                                    content: (
                                        <Card>
                                            <CardHeader>
                                                <div className="flex items-center justify-between gap-3">
                                                    <CardTitle className="text-base">Medications</CardTitle>
                                                    {can.edit ? (
                                                        <Button variant="outline" size="sm" asChild>
                                                            <Link href={`/clients/${client.id}/medical?section=medications`}>Manage</Link>
                                                        </Button>
                                                    ) : null}
                                                </div>
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
                                                        {m.instructions ? <div className="mt-2 whitespace-pre-wrap text-xs text-slate-600">{m.instructions}</div> : null}
                                                    </div>
                                                ))}
                                                {!medical.medications.length ? <div className="text-sm text-slate-500">No medications listed.</div> : null}
                                            </CardContent>
                                        </Card>
                                    ),
                                },
                                {
                                    key: 'conditions',
                                    label: `Conditions${medical.conditions.length ? ` (${medical.conditions.length})` : ''}`,
                                    content: (
                                        <Card>
                                            <CardHeader>
                                                <div className="flex items-center justify-between gap-3">
                                                    <CardTitle className="text-base">Conditions</CardTitle>
                                                    {can.edit ? (
                                                        <Button variant="outline" size="sm" asChild>
                                                            <Link href={`/clients/${client.id}/medical?section=conditions`}>Manage</Link>
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </CardHeader>
                                            <CardContent className="space-y-2">
                                                {medical.conditions.map((c) => (
                                                    <div key={c.id} className="rounded-md border p-3">
                                                        <div className="text-sm font-medium">
                                                            {c.label}
                                                            {c.severity ? <span className="ml-2 text-xs text-slate-500">({c.severity})</span> : null}
                                                        </div>
                                                        {c.notes ? <div className="mt-2 whitespace-pre-wrap text-xs text-slate-600">{c.notes}</div> : null}
                                                    </div>
                                                ))}
                                                {!medical.conditions.length ? <div className="text-sm text-slate-500">No conditions listed.</div> : null}
                                            </CardContent>
                                        </Card>
                                    ),
                                },
                                {
                                    key: 'emergency_contacts',
                                    label: `Emergency contacts${medical.emergency_contacts.length ? ` (${medical.emergency_contacts.length})` : ''}`,
                                    content: (
                                        <Card>
                                            <CardHeader>
                                                <div className="flex items-center justify-between gap-3">
                                                    <CardTitle className="text-base">Emergency contacts</CardTitle>
                                                    {can.edit ? (
                                                        <Button variant="outline" size="sm" asChild>
                                                            <Link href={`/clients/${client.id}/medical?section=emergency_contacts`}>Manage</Link>
                                                        </Button>
                                                    ) : null}
                                                </div>
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
                                                        {e.notes ? <div className="mt-2 whitespace-pre-wrap text-xs text-slate-600">{e.notes}</div> : null}
                                                    </div>
                                                ))}
                                                {!medical.emergency_contacts.length ? <div className="text-sm text-slate-500">No emergency contacts listed.</div> : null}
                                            </CardContent>
                                        </Card>
                                    ),
                                },
                            ]}
                        />
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
                            {handover.length ? (
                                <div className="rounded-md border p-3">
                                    <div className="text-sm font-medium">Pinned handover</div>
                                    <div className="mt-2 space-y-2">
                                        {handover.map((h) => (
                                            <div key={h.id} className="rounded-md border p-3">
                                                <div className="flex items-center justify-between gap-3">
                                                    <div className="text-sm font-medium">{h.subject || 'Handover'}</div>
                                                    <div className="text-xs text-slate-500">{h.occurred_at ? new Date(h.occurred_at).toLocaleString() : ''}</div>
                                                </div>
                                                {h.body && <div className="mt-2 whitespace-pre-wrap text-xs text-slate-600">{h.body}</div>}
                                                <div className="mt-2 flex items-center justify-between gap-2">
                                                    <div className="text-xs text-slate-500">{h.actor?.name ? `By ${h.actor.name}` : ''}</div>
                                                    {can.pin_handover && h.source_id ? (
                                                        <button
                                                            className="text-xs underline"
                                                            onClick={async () => {
                                                                await fetch(`/clients/${client.id}/notes/${h.source_id}/pin`, {
                                                                    method: 'POST',
                                                                    headers: {
                                                                        'X-Requested-With': 'XMLHttpRequest',
                                                                        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content,
                                                                    },
                                                                });
                                                                window.location.reload();
                                                            }}
                                                        >
                                                            Unpin
                                                        </button>
                                                    ) : null}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ) : null}

                            {can.create_note && (
                                <div className="rounded-md border p-3">
                                    <div className="text-sm font-medium">Add note</div>
                                    <div className="mt-3 grid grid-cols-1 gap-3">
                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <div>
                                                <Label>Type</Label>
                                                <Select
                                                    value={noteForm.data.type}
                                                    onValueChange={(v) => {
                                                        noteForm.setData('type', v);
                                                        const tpl = templates.find((t) => t.key === v);
                                                        if (tpl && noteForm.data.body.trim() === '') {
                                                            noteForm.setData('body', tpl.body);
                                                        }
                                                        noteForm.setData('pin', v === 'handover');
                                                    }}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {templates.map((t) => (
                                                            <SelectItem key={t.key} value={t.key}>
                                                                {t.label}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div>
                                                <Label>Subject (optional)</Label>
                                                <Input value={noteForm.data.subject} onChange={(e) => noteForm.setData('subject', e.target.value)} />
                                            </div>
                                        </div>

                                        {noteForm.data.type === 'progress_note' ? (
                                            <div>
                                                <Label>Goal/outcome (optional)</Label>
                                                <Input value={noteForm.data.goal} onChange={(e) => noteForm.setData('goal', e.target.value)} />
                                            </div>
                                        ) : null}
                                        <div>
                                            <Label>Note</Label>
                                            <Textarea
                                                rows={3}
                                                value={noteForm.data.body}
                                                onChange={(e) => noteForm.setData('body', e.target.value)}
                                            />
                                        </div>
                                    </div>
                                    <div className="mt-3 flex flex-wrap items-center gap-3">
                                        <div className="flex items-center gap-2 text-xs">
                                            <Checkbox checked={noteForm.data.visibility === 'portal'} onCheckedChange={(v) => noteForm.setData('visibility', v ? 'portal' : 'internal')} />
                                            <span>Share in portal</span>
                                        </div>
                                        {noteForm.data.type === 'handover' ? (
                                            <div className="flex items-center gap-2 text-xs">
                                                <Checkbox checked={noteForm.data.pin} onCheckedChange={(v) => noteForm.setData('pin', Boolean(v))} />
                                                <span>Pin as handover</span>
                                            </div>
                                        ) : null}

                                        <Button
                                            onClick={() =>
                                                noteForm.post(`/clients/${client.id}/notes`, {
                                                    preserveScroll: true,
                                                    onSuccess: () => noteForm.reset({ type: 'note', subject: '', goal: '', body: '', visibility: 'internal', pin: false }),
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
            </PageShell>
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
