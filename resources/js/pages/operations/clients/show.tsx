import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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
import { Separator } from '@/components/ui/separator';
import { Tabs } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Globe, Heart, ShieldAlert, Star } from 'lucide-react';
import { useMemo, useState } from 'react';

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-sm font-medium">{value}</p>
        </div>
    );
}

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
        // Identity & Culture
        ethnicity?: string | null;
        preferred_pronouns?: string | null;
        religion?: string | null;
        languages?: string[] | null;
        education_level?: string | null;
        employment_status?: string | null;
        // Interests & Strengths
        interests_hobbies?: string | null;
        strengths_abilities?: string | null;
        life_story?: string | null;
        // Health & Support Needs
        mobility_needs?: string | null;
        sensory_needs?: string | null;
        cognitive_needs?: string | null;
        dietary_requirements?: string | null;
        sleep_preferences?: string | null;
        // Service Details
        service_start_date?: string | null;
        risk_level?: 'low' | 'medium' | 'high' | 'critical' | null;
        safeguarding_flag?: boolean | null;
        key_worker?: { id: number; name: string } | null;
        site: { id: number; name: string } | null;
        service_context?: {
            id: number;
            type: string | null;
            name: string;
        } | null;
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
    respite?: {
        bookings: Array<{
            id: number;
            start_at?: string | null;
            end_at?: string | null;
            status: string;
            shift_id?: number | null;
            coordinator?: { id: number; name: string } | null;
        }>;
        requests: Array<{
            id: number;
            requested_start?: string | null;
            requested_end?: string | null;
            status: string;
        }>;
    };
    onboarding: {
        items: Array<{
            key: string;
            label: string;
            has_data: boolean;
            override: boolean;
            complete: boolean;
        }>;
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
    | 'onboarding'
    | 'medical'
    | 'mar'
    | 'care_plans'
    | 'progress_notes'
    | 'service_agreements'
    | 'support_plan'
    | 'assessments'
    | 'timeline'
    | 'documents'
    | 'consents'
    | 'portal'
    | 'respite'
    | 'assignments';

export default function ClientShow({
    client,
    medical,
    support_plan,
    assessments,
    documents,
    portal_users,
    events,
    handover,
    onboarding,
    shifts_summary,
    respite,
    can,
}: Props) {
    const { auth, labels } = usePage().props as any;
    const respiteCan = auth?.can?.respite ?? {};
    const name = `${client.first_name} ${client.last_name}`.trim();
    const getInitials = useInitials();
    const photoForm = useForm<{ photo: File | null }>({ photo: null });
    const removePhotoForm = useForm({});

    const tabs: Array<{ key: TabKey; label: string; show: boolean }> = useMemo(
        () => [
            { key: 'profile', label: 'Profile', show: true },
            { key: 'onboarding', label: 'Onboarding', show: client.status === 'onboarding' || !!onboarding?.workflow },
            { key: 'medical', label: 'Medical', show: true },
            { key: 'mar', label: 'MAR', show: true },
            { key: 'care_plans', label: 'Care Plans', show: true },
            { key: 'progress_notes', label: 'Progress Notes', show: true },
            { key: 'service_agreements', label: 'Agreements', show: true },
            // Support plan merged into Care Plans tab
            { key: 'assessments', label: 'Assessments', show: true },
            { key: 'timeline', label: 'Timeline', show: true },
            { key: 'documents', label: 'Documents', show: true },
            { key: 'consents', label: 'Consents', show: true },
            { key: 'portal', label: 'Next of Kin / Portal', show: true },
            { key: 'respite', label: 'Respite', show: !!respiteCan?.viewAny },
            {
                key: 'assignments',
                label: 'Assign workers',
                show: can.assign_workers || can.edit,
            },
        ],
        [can.assign_workers, can.edit, respiteCan?.viewAny],
    );

    // Support ?tab=onboarding deep linking from dashboard
    const initialTab = typeof window !== 'undefined'
        ? (new URLSearchParams(window.location.search).get('tab') as TabKey) || 'profile'
        : 'profile';
    const [tab, setTab] = useState<TabKey>(initialTab);

    const templates = [
        { key: 'note', label: 'Note', body: '' },
        {
            key: 'progress_note',
            label: 'Progress note',
            body: 'Goal/outcome:\n\nWhat happened:\n\nNext steps:',
        },
        {
            key: 'handover',
            label: 'Handover',
            body: 'Key points for next shift:\n-\n-\n\nRisks/alerts:\n-\n\nActions needed:\n-',
        },
    ];

    const noteForm = useForm<{
        type: string;
        subject: string;
        goal: string;
        body: string;
        visibility: string;
        pin: boolean;
    }>({
        type: 'note',
        subject: '',
        goal: '',
        body: '',
        visibility: 'internal',
        pin: false,
    });

    const respiteBookings = respite?.bookings ?? [];
    const respiteRequests = respite?.requests ?? [];

    return (
        <AppLayout
            breadcrumbs={[
                { title: labels?.['client.plural'] ?? 'Clients', href: '/clients' },
                { title: name, href: `/operations/clients/${client.id}` },
            ]}
        >
            <Head title={name} />

            <PageShell>
                <PageHeader
                    title={
                        <div className="flex items-center gap-3">
                            <Avatar className="h-25 w-25">
                                <AvatarImage
                                    src={
                                        client.avatar ??
                                        client.profile_photo_url ??
                                        undefined
                                    }
                                    alt={name}
                                />
                                <AvatarFallback>
                                    {getInitials(name)}
                                </AvatarFallback>
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
                                        photoForm.post(
                                            `/operations/clients/${client.id}/photo`,
                                            {
                                                forceFormData: true,
                                                preserveScroll: true,
                                            },
                                        );
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
                                            (
                                                document.getElementById(
                                                    'client-photo',
                                                ) as HTMLInputElement | null
                                            )?.click()
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
                                            disabled={
                                                removePhotoForm.processing
                                            }
                                            onClick={() =>
                                                removePhotoForm.delete(
                                                    `/operations/clients/${client.id}/photo`,
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
                                <Link href={`/operations/clients/${client.id}/incidents`}>
                                    Incidents
                                </Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/operations/clients/${client.id}/risks`}>
                                    Risks
                                </Link>
                            </Button>
                            {can.edit ? (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={`/operations/clients/${client.id}/edit`}>
                                        Edit
                                    </Link>
                                </Button>
                            ) : null}
                            {can.assign_workers || can.edit ? (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={`/operations/clients/${client.id}/assignments`}
                                    >
                                        Assign workers
                                    </Link>
                                </Button>
                            ) : null}
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/assets?client_id=${client.id}`}>
                                    Assets
                                </Link>
                            </Button>
                            {can.edit ? (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={`/assets/create?client_id=${client.id}`}
                                    >
                                        Add asset
                                    </Link>
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
                                    variant={
                                        tab === t.key ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    onClick={() => {
                                        if (t.key === 'mar') {
                                            window.location.href = `/operations/clients/${client.id}/mar`;
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
                    <>
                    {/* Safeguarding Alert Banner */}
                    {client.safeguarding_flag && (
                        <div className="mb-4 flex items-center gap-3 rounded-lg border-2 border-red-300 bg-red-50 p-4">
                            <ShieldAlert className="h-6 w-6 text-red-600" />
                            <div>
                                <p className="text-sm font-bold text-red-800">Safeguarding Alert</p>
                                <p className="text-xs text-red-700">This person has an active safeguarding concern. Check risk assessments and follow safeguarding protocols.</p>
                            </div>
                        </div>
                    )}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Profile</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {/* Onboarding Summary Card */}
                            <div className="rounded-md border p-3">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <div className="text-sm font-medium">Onboarding Progress</div>
                                        <div className="text-xs text-slate-500">
                                            {onboarding?.checklist?.completed ?? onboarding?.completed ?? 0}/{onboarding?.checklist?.total ?? onboarding?.total ?? 0} data items complete • {onboarding?.checklist?.percent ?? onboarding?.percent ?? 0}%
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <div className={`rounded-full px-2 py-1 text-xs ${(onboarding?.checklist?.status ?? onboarding?.status) === 'complete' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                                            {(onboarding?.checklist?.status ?? onboarding?.status) === 'complete' ? 'Complete' : 'In progress'}
                                        </div>
                                        {(client.status === 'onboarding' || onboarding?.workflow) && (
                                            <Button size="sm" variant="outline" onClick={() => setTab('onboarding')}>
                                                View Onboarding
                                            </Button>
                                        )}
                                    </div>
                                </div>
                                {/* Mini progress bar */}
                                <div className="mt-2 h-1.5 rounded-full bg-muted">
                                    <div
                                        className="h-1.5 rounded-full bg-indigo-500 transition-all"
                                        style={{ width: `${onboarding?.checklist?.percent ?? onboarding?.percent ?? 0}%` }}
                                    />
                                </div>
                            </div>

                            <div className="rounded-md border p-3">
                                <div className="text-sm font-medium">
                                    Details
                                </div>
                                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div className="text-sm">
                                        <div className="text-xs text-slate-500">
                                            Preferred name
                                        </div>
                                        <div className="font-medium">
                                            {client.preferred_name || '—'}
                                        </div>
                                    </div>
                                    <div className="text-sm">
                                        <div className="text-xs text-slate-500">
                                            Date of birth
                                        </div>
                                        <div className="font-medium">
                                            {client.date_of_birth || '—'}
                                        </div>
                                    </div>
                                    <div className="text-sm">
                                        <div className="text-xs text-slate-500">
                                            Gender
                                        </div>
                                        <div className="font-medium">
                                            {client.gender || '—'}
                                        </div>
                                    </div>
                                    <div className="text-sm">
                                        <div className="text-xs text-slate-500">
                                            Phone
                                        </div>
                                        <div className="font-medium">
                                            {client.phone || '—'}
                                        </div>
                                    </div>
                                    <div className="text-sm">
                                        <div className="text-xs text-slate-500">
                                            Email
                                        </div>
                                        <div className="font-medium">
                                            {client.email || '—'}
                                        </div>
                                    </div>
                                    <div className="text-sm">
                                        <div className="text-xs text-slate-500">
                                            Funding
                                        </div>
                                        <div className="font-medium">
                                            {client.funding_type || '—'}
                                        </div>
                                    </div>
                                </div>
                                {(client.address_line_1 ||
                                    client.city ||
                                    client.postcode) && (
                                    <div className="mt-3 text-sm">
                                        <div className="text-xs text-slate-500">
                                            Address
                                        </div>
                                        <div className="font-medium">
                                            {[
                                                client.address_line_1,
                                                client.address_line_2,
                                                client.suburb,
                                                client.city,
                                                client.postcode,
                                            ]
                                                .filter(Boolean)
                                                .join(', ')}
                                        </div>
                                    </div>
                                )}
                                {client.funding_notes && (
                                    <div className="mt-3 text-sm">
                                        <div className="text-xs text-slate-500">
                                            Funding notes
                                        </div>
                                        <div className="whitespace-pre-wrap">
                                            {client.funding_notes}
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* Identity & Culture */}
                            {(client.ethnicity || client.preferred_pronouns || client.religion || (client.languages ?? []).length > 0 || client.education_level || client.employment_status) && (
                                <Card className="mt-4">
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-violet-100 text-violet-600">
                                                <Globe className="h-4 w-4" />
                                            </div>
                                            Identity &amp; Culture
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                            {client.ethnicity && <Field label="Ethnicity" value={client.ethnicity} />}
                                            {client.preferred_pronouns && <Field label="Pronouns" value={client.preferred_pronouns} />}
                                            {client.religion && <Field label="Religion / Spirituality" value={client.religion} />}
                                            {(client.languages ?? []).length > 0 && (
                                                <Field label="Languages" value={(client.languages ?? []).join(', ')} />
                                            )}
                                            {client.education_level && <Field label="Education" value={client.education_level} />}
                                            {client.employment_status && <Field label="Employment" value={client.employment_status} />}
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Interests & Strengths */}
                            {(client.interests_hobbies || client.strengths_abilities || client.life_story) && (
                                <Card className="mt-4">
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-100 text-amber-600">
                                                <Star className="h-4 w-4" />
                                            </div>
                                            Interests &amp; Strengths
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        {client.interests_hobbies && (
                                            <div>
                                                <p className="text-xs font-semibold text-amber-600">Interests &amp; Hobbies</p>
                                                <p className="mt-0.5 text-sm">{client.interests_hobbies}</p>
                                            </div>
                                        )}
                                        {client.strengths_abilities && (
                                            <div>
                                                <p className="text-xs font-semibold text-emerald-600">Strengths &amp; Abilities</p>
                                                <p className="mt-0.5 text-sm">{client.strengths_abilities}</p>
                                            </div>
                                        )}
                                        {client.life_story && (
                                            <div>
                                                <p className="text-xs font-semibold text-indigo-600">Life Story</p>
                                                <p className="mt-0.5 text-sm">{client.life_story}</p>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            )}

                            {/* Health & Support Needs */}
                            {(client.mobility_needs || client.sensory_needs || client.cognitive_needs || client.dietary_requirements || client.sleep_preferences) && (
                                <Card className="mt-4">
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-100 text-rose-600">
                                                <Heart className="h-4 w-4" />
                                            </div>
                                            Health &amp; Support Needs
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                            {client.mobility_needs && <Field label="Mobility Needs" value={client.mobility_needs} />}
                                            {client.sensory_needs && <Field label="Sensory Needs" value={client.sensory_needs} />}
                                            {client.cognitive_needs && <Field label="Cognitive Needs" value={client.cognitive_needs} />}
                                            {client.dietary_requirements && <Field label="Dietary Requirements" value={client.dietary_requirements} />}
                                            {client.sleep_preferences && <Field label="Sleep Preferences" value={client.sleep_preferences} />}
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Service Details */}
                            {(client.service_start_date || client.key_worker || client.risk_level || client.funding_type) && (
                                <Card className="mt-4">
                                    <CardHeader>
                                        <CardTitle className="text-base">Service Details</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                            {client.service_start_date && <Field label="Service Start Date" value={client.service_start_date} />}
                                            {client.key_worker && <Field label="Key Worker" value={client.key_worker.name} />}
                                            {client.risk_level && (
                                                <div>
                                                    <p className="text-xs text-muted-foreground">Risk Level</p>
                                                    <span className={`mt-0.5 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${
                                                        client.risk_level === 'low' ? 'bg-emerald-100 text-emerald-800' :
                                                        client.risk_level === 'medium' ? 'bg-amber-100 text-amber-800' :
                                                        client.risk_level === 'high' ? 'bg-red-100 text-red-800' :
                                                        'bg-red-100 text-red-800 animate-pulse'
                                                    }`}>
                                                        {client.risk_level.charAt(0).toUpperCase() + client.risk_level.slice(1)}
                                                    </span>
                                                </div>
                                            )}
                                            {client.funding_type && <Field label="Funding Type" value={client.funding_type} />}
                                        </div>
                                        {client.funding_notes && (
                                            <div className="mt-3">
                                                <p className="text-xs text-muted-foreground">Funding Notes</p>
                                                <p className="mt-0.5 whitespace-pre-wrap text-sm">{client.funding_notes}</p>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            )}

                            <div className="text-sm">
                                <div className="font-medium">
                                    Assigned support workers
                                </div>
                                <div className="mt-2 space-y-2">
                                    {client.support_workers.map((w) => (
                                        <div
                                            key={w.id}
                                            className="rounded-md border p-2"
                                        >
                                            <div className="text-sm font-medium">
                                                {w.name}
                                            </div>
                                            <div className="text-xs text-slate-500">
                                                {w.email}
                                            </div>
                                        </div>
                                    ))}
                                    {!client.support_workers.length && (
                                        <div className="text-sm text-slate-500">
                                            No workers assigned.
                                        </div>
                                    )}
                                </div>
                            </div>

                            <div className="rounded-md border p-3">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <div className="text-sm font-medium">
                                            Shifts
                                        </div>
                                        <div className="text-xs text-slate-500">
                                            Next and recent rostered shifts for
                                            this {(labels?.['client.singular'] ?? 'Client').toLowerCase()}.
                                        </div>
                                    </div>
                                    {can.create_shift ? (
                                        <Button size="sm" asChild>
                                            <Link
                                                href={`/operations/shifts/create?client_id=${client.id}`}
                                            >
                                                Create shift
                                            </Link>
                                        </Button>
                                    ) : null}
                                </div>

                                <Separator className="my-3" />

                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div className="rounded-md border p-3">
                                        <div className="text-xs text-slate-500">
                                            Next shift
                                        </div>
                                        {shifts_summary?.next ? (
                                            <div className="mt-1 space-y-1">
                                                <div className="text-sm font-medium">
                                                    {new Date(
                                                        shifts_summary.next
                                                            .starts_at,
                                                    ).toLocaleString()}{' '}
                                                    –{' '}
                                                    {new Date(
                                                        shifts_summary.next
                                                            .ends_at,
                                                    ).toLocaleTimeString()}
                                                </div>
                                                <div className="text-xs text-slate-500">
                                                    {shifts_summary.next.staff
                                                        ?.name
                                                        ? `Staff: ${shifts_summary.next.staff.name}`
                                                        : 'Staff: —'}
                                                    {shifts_summary.next
                                                        .location
                                                        ? ` • ${shifts_summary.next.location}`
                                                        : ''}
                                                </div>
                                                <div className="mt-2">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={`/operations/shifts/${shifts_summary.next.id}`}
                                                        >
                                                            Open
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="mt-1 text-sm text-slate-500">
                                                No upcoming shifts.
                                            </div>
                                        )}
                                    </div>

                                    <div className="rounded-md border p-3">
                                        <div className="text-xs text-slate-500">
                                            Most recent shift
                                        </div>
                                        {shifts_summary?.last ? (
                                            <div className="mt-1 space-y-1">
                                                <div className="text-sm font-medium">
                                                    {new Date(
                                                        shifts_summary.last
                                                            .starts_at,
                                                    ).toLocaleString()}{' '}
                                                    –{' '}
                                                    {new Date(
                                                        shifts_summary.last
                                                            .ends_at,
                                                    ).toLocaleTimeString()}
                                                </div>
                                                <div className="text-xs text-slate-500">
                                                    {shifts_summary.last.staff
                                                        ?.name
                                                        ? `Staff: ${shifts_summary.last.staff.name}`
                                                        : 'Staff: —'}
                                                    {shifts_summary.last
                                                        .location
                                                        ? ` • ${shifts_summary.last.location}`
                                                        : ''}
                                                </div>
                                                <div className="mt-2">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={`/operations/shifts/${shifts_summary.last.id}`}
                                                        >
                                                            Open
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="mt-1 text-sm text-slate-500">
                                                No previous shifts yet.
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            <div className="flex flex-wrap gap-2">
                                <Link
                                    href={`/operations/clients/${client.id}/medical`}
                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                >
                                    Open medical page
                                </Link>
                                <Link
                                    href={`/operations/clients/${client.id}/documents`}
                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                >
                                    Open documents page
                                </Link>
                                <Link
                                    href={`/operations/clients/${client.id}/portal-users`}
                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                >
                                    Manage portal users
                                </Link>
                            </div>
                        </CardContent>
                    </Card>
                    </>
                )}

                {tab === 'onboarding' && (
                    <div className="space-y-4">
                        {/* Workflow Progress Header */}
                        {onboarding?.workflow ? (
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="text-base">Onboarding Workflow</CardTitle>
                                        <Badge variant={onboarding.workflow.status === 'completed' ? 'secondary' : 'default'} className="capitalize">
                                            {onboarding.workflow.status?.replace('_', ' ')}
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center gap-4 text-sm text-muted-foreground">
                                        {onboarding.workflow.assigned_to && (
                                            <span>Coordinator: <strong>{onboarding.workflow.assigned_to.name}</strong></span>
                                        )}
                                        {onboarding.workflow.started_at && (
                                            <span>Started: {new Date(onboarding.workflow.started_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                                        )}
                                    </div>
                                    {/* Progress bar */}
                                    {(() => {
                                        const steps = onboarding.workflow.steps ?? [];
                                        const done = steps.filter((s: any) => s.status === 'completed' || s.status === 'skipped').length;
                                        const pct = steps.length > 0 ? Math.round((done / steps.length) * 100) : 0;
                                        return (
                                            <div className="mt-3">
                                                <div className="flex justify-between text-xs text-muted-foreground">
                                                    <span>{done}/{steps.length} steps complete</span>
                                                    <span>{pct}%</span>
                                                </div>
                                                <div className="mt-1 h-2 rounded-full bg-muted">
                                                    <div className="h-2 rounded-full bg-indigo-500 transition-all" style={{ width: `${pct}%` }} />
                                                </div>
                                            </div>
                                        );
                                    })()}
                                </CardContent>
                            </Card>
                        ) : (
                            <Card>
                                <CardContent className="flex flex-col items-center justify-center py-8">
                                    <p className="text-sm text-muted-foreground">No onboarding workflow found.</p>
                                    {(can.manage_onboarding || can.edit) && (
                                        <Button size="sm" className="mt-3" onClick={() => {
                                            router.post(`/operations/clients/${client.id}/onboarding-workflow`, {}, { preserveScroll: true });
                                        }}>
                                            Start Onboarding Workflow
                                        </Button>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Data Checklist */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Data Checklist</CardTitle>
                                <p className="text-xs text-muted-foreground">Auto-detected from {(labels?.['client.singular'] ?? 'client').toLowerCase()} profile data</p>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {(onboarding?.checklist?.items ?? onboarding?.items ?? []).map((item: any) => (
                                    <div key={item.key} className="flex items-center justify-between rounded-md border p-2">
                                        <div className="flex items-center gap-2">
                                            <div className={`h-2 w-2 rounded-full ${item.complete ? 'bg-emerald-500' : 'bg-slate-300'}`} />
                                            <div>
                                                <div className="text-sm font-medium">{item.label}</div>
                                                <div className="text-xs text-slate-500">
                                                    {item.complete ? (item.has_data ? 'Added' : 'Not applicable') : 'Not completed'}
                                                </div>
                                            </div>
                                        </div>
                                        {!item.has_data && (can.manage_onboarding || can.edit) && (
                                            <label className="flex cursor-pointer items-center gap-2 text-xs text-slate-600">
                                                <Checkbox
                                                    checked={item.override}
                                                    onCheckedChange={(v) => {
                                                        router.post(`/operations/clients/${client.id}/onboarding/${item.key}`, { checked: !!v }, { preserveScroll: true });
                                                    }}
                                                />
                                                Doesn't have this
                                            </label>
                                        )}
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        {/* Workflow Steps */}
                        {onboarding?.workflow?.steps && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Workflow Steps</CardTitle>
                                    <p className="text-xs text-muted-foreground">Manual steps tracked by staff</p>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {onboarding.workflow.steps.map((step: any) => (
                                        <div key={step.id} className={`flex items-center justify-between rounded-md border p-3 ${step.status === 'completed' ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900/30 dark:bg-emerald-950/20' : step.due_date && new Date(step.due_date) < new Date() && step.status === 'pending' ? 'border-red-200 bg-red-50/50 dark:border-red-900/30 dark:bg-red-950/20' : ''}`}>
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium">
                                                    {step.step_order}
                                                </div>
                                                <div>
                                                    <div className="text-sm font-medium">{step.step_name}</div>
                                                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                                        {step.status === 'completed' && step.completed_by && (
                                                            <span>Completed by {step.completed_by.name}</span>
                                                        )}
                                                        {step.completed_at && (
                                                            <span>{new Date(step.completed_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}</span>
                                                        )}
                                                        {step.due_date && step.status === 'pending' && (
                                                            <span className={new Date(step.due_date) < new Date() ? 'text-red-600 font-medium' : ''}>
                                                                Due: {new Date(step.due_date).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}
                                                            </span>
                                                        )}
                                                        {step.notes && <span className="italic">"{step.notes}"</span>}
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Badge variant={step.status === 'completed' ? 'secondary' : step.status === 'skipped' ? 'outline' : 'default'} className="h-5 text-[10px] capitalize">
                                                    {step.status}
                                                </Badge>
                                                {step.status === 'pending' && (can.manage_onboarding || can.edit) && (
                                                    <div className="flex gap-1">
                                                        <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => {
                                                            router.patch(`/operations/onboarding/${onboarding.workflow.id}/steps/${step.id}`, { status: 'completed' }, { preserveScroll: true });
                                                        }}>
                                                            Complete
                                                        </Button>
                                                        <Button size="sm" variant="ghost" className="h-7 text-xs text-muted-foreground" onClick={() => {
                                                            router.patch(`/operations/onboarding/${onboarding.workflow.id}/steps/${step.id}`, { status: 'skipped' }, { preserveScroll: true });
                                                        }}>
                                                            Skip
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </CardContent>
                                {/* Complete Onboarding Button */}
                                {onboarding.workflow.status === 'in_progress' && (can.manage_onboarding || can.edit) && (() => {
                                    const requiredSteps = onboarding.workflow.steps.filter((s: any) => s.is_required);
                                    const allRequiredDone = requiredSteps.every((s: any) => s.status === 'completed' || s.status === 'skipped');
                                    return allRequiredDone ? (
                                        <div className="border-t p-4">
                                            <Button className="w-full" onClick={() => {
                                                router.post(`/operations/onboarding/${onboarding.workflow.id}/complete`, {}, { preserveScroll: true });
                                            }}>
                                                Complete Onboarding — Set Status to Active
                                            </Button>
                                        </div>
                                    ) : null;
                                })()}
                            </Card>
                        )}
                    </div>
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
                                                    <CardTitle className="text-base">
                                                        Medical profile
                                                    </CardTitle>
                                                    {can.edit ? (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/operations/clients/${client.id}/medical`}
                                                            >
                                                                Edit
                                                            </Link>
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </CardHeader>
                                            <CardContent className="space-y-3 text-sm">
                                                <div>
                                                    <div className="font-medium">
                                                        Medical history
                                                    </div>
                                                    <div className="whitespace-pre-wrap text-slate-600">
                                                        {medical.profile
                                                            ?.medical_history ||
                                                            '-'}
                                                    </div>
                                                </div>
                                                <div>
                                                    <div className="font-medium">
                                                        Disabilities
                                                    </div>
                                                    <div className="whitespace-pre-wrap text-slate-600">
                                                        {medical.profile
                                                            ?.disabilities ||
                                                            '-'}
                                                    </div>
                                                </div>
                                                <div>
                                                    <div className="font-medium">
                                                        Allergies
                                                    </div>
                                                    <div className="whitespace-pre-wrap text-slate-600">
                                                        {medical.profile
                                                            ?.allergies || '-'}
                                                    </div>
                                                </div>
                                                <div>
                                                    <div className="font-medium">
                                                        Notes
                                                    </div>
                                                    <div className="whitespace-pre-wrap text-slate-600">
                                                        {medical.profile
                                                            ?.notes || '-'}
                                                    </div>
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
                                                    <CardTitle className="text-base">
                                                        Medications
                                                    </CardTitle>
                                                    {can.edit ? (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/operations/clients/${client.id}/medical?section=medications`}
                                                            >
                                                                Manage
                                                            </Link>
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </CardHeader>
                                            <CardContent className="space-y-2">
                                                {medical.medications.map(
                                                    (m) => (
                                                        <div
                                                            key={m.id}
                                                            className="rounded-md border p-3"
                                                        >
                                                            <div className="text-sm font-medium">
                                                                {m.name}
                                                            </div>
                                                            <div className="mt-1 text-xs text-slate-500">
                                                                {[
                                                                    m.dosage &&
                                                                        `Dosage: ${m.dosage}`,
                                                                    m.frequency &&
                                                                        `Frequency: ${m.frequency}`,
                                                                    m.route &&
                                                                        `Route: ${m.route}`,
                                                                ]
                                                                    .filter(
                                                                        Boolean,
                                                                    )
                                                                    .join(
                                                                        ' - ',
                                                                    ) || '-'}
                                                            </div>
                                                            {m.instructions ? (
                                                                <div className="mt-2 text-xs whitespace-pre-wrap text-slate-600">
                                                                    {
                                                                        m.instructions
                                                                    }
                                                                </div>
                                                            ) : null}
                                                        </div>
                                                    ),
                                                )}
                                                {!medical.medications.length ? (
                                                    <div className="text-sm text-slate-500">
                                                        No medications listed.
                                                    </div>
                                                ) : null}
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
                                                    <CardTitle className="text-base">
                                                        Conditions
                                                    </CardTitle>
                                                    {can.edit ? (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/operations/clients/${client.id}/medical?section=conditions`}
                                                            >
                                                                Manage
                                                            </Link>
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </CardHeader>
                                            <CardContent className="space-y-2">
                                                {medical.conditions.map((c) => (
                                                    <div
                                                        key={c.id}
                                                        className="rounded-md border p-3"
                                                    >
                                                        <div className="text-sm font-medium">
                                                            {c.label}
                                                            {c.severity ? (
                                                                <span className="ml-2 text-xs text-slate-500">
                                                                    (
                                                                    {c.severity}
                                                                    )
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                        {c.notes ? (
                                                            <div className="mt-2 text-xs whitespace-pre-wrap text-slate-600">
                                                                {c.notes}
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                ))}
                                                {!medical.conditions.length ? (
                                                    <div className="text-sm text-slate-500">
                                                        No conditions listed.
                                                    </div>
                                                ) : null}
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
                                                    <CardTitle className="text-base">
                                                        Emergency contacts
                                                    </CardTitle>
                                                    {can.edit ? (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/operations/clients/${client.id}/medical?section=emergency_contacts`}
                                                            >
                                                                Manage
                                                            </Link>
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </CardHeader>
                                            <CardContent className="space-y-2">
                                                {medical.emergency_contacts.map(
                                                    (e) => (
                                                        <div
                                                            key={e.id}
                                                            className="rounded-md border p-3"
                                                        >
                                                            <div className="text-sm font-medium">
                                                                {e.name}
                                                            </div>
                                                            <div className="mt-1 text-xs text-slate-500">
                                                                {[
                                                                    e.relationship &&
                                                                        `Relationship: ${e.relationship}`,
                                                                    e.phone &&
                                                                        `Phone: ${e.phone}`,
                                                                    e.email &&
                                                                        `Email: ${e.email}`,
                                                                ]
                                                                    .filter(
                                                                        Boolean,
                                                                    )
                                                                    .join(
                                                                        ' - ',
                                                                    ) || '-'}
                                                            </div>
                                                            {e.notes ? (
                                                                <div className="mt-2 text-xs whitespace-pre-wrap text-slate-600">
                                                                    {e.notes}
                                                                </div>
                                                            ) : null}
                                                        </div>
                                                    ),
                                                )}
                                                {!medical.emergency_contacts
                                                    .length ? (
                                                    <div className="text-sm text-slate-500">
                                                        No emergency contacts
                                                        listed.
                                                    </div>
                                                ) : null}
                                            </CardContent>
                                        </Card>
                                    ),
                                },
                            ]}
                        />
                    </div>
                )}

                {tab === 'care_plans' && (() => {
                    const pageProps = usePage().props as any;
                    const summary = pageProps.care_plans_summary ?? {};
                    const activePlan = summary.active_plan;
                    const recentNotes = summary.recent_notes ?? [];
                    const reviewDue = summary.review_due ?? false;

                    return (
                        <div className="space-y-4">
                            {/* Review Due Alert */}
                            {reviewDue && activePlan && (
                                <div className="flex items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                                    <svg className="h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.072 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                                    <span>Care plan review is due. Please review and update the plan.</span>
                                    <Button size="sm" variant="outline" className="ml-auto border-amber-300 text-amber-700 hover:bg-amber-100" asChild>
                                        <Link href={`/operations/care-plans/${activePlan.id}`}>Start Review</Link>
                                    </Button>
                                </div>
                            )}

                            {/* Active Plan Summary */}
                            {activePlan ? (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center justify-between text-base">
                                            <div className="flex items-center gap-2">
                                                <span>{activePlan.title}</span>
                                                <span className="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">Active</span>
                                                <span className="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700">{(activePlan.plan_type ?? '').replace(/_/g, ' ')}</span>
                                            </div>
                                            <div className="flex gap-2">
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={`/operations/care-plans/${activePlan.id}`}>View Full Plan</Link>
                                                </Button>
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={`/operations/care-plans?client_id=${client.id}`}>All Plans ({summary.total_plans ?? 0})</Link>
                                                </Button>
                                            </div>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {/* Goals Progress */}
                                        <div className="mb-4">
                                            <div className="mb-2 flex items-center justify-between text-sm">
                                                <span className="font-medium">Goals Progress</span>
                                                <span className="text-muted-foreground">{activePlan.goals_completed ?? 0} / {activePlan.goals_count ?? 0} completed</span>
                                            </div>
                                            <div className="h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
                                                <div
                                                    className="h-full rounded-full bg-emerald-500 transition-all"
                                                    style={{ width: `${activePlan.goals_count > 0 ? Math.round(((activePlan.goals_completed ?? 0) / activePlan.goals_count) * 100) : 0}%` }}
                                                />
                                            </div>
                                        </div>

                                        {/* Individual Goals */}
                                        {(activePlan.goals ?? []).length > 0 && (
                                            <div className="space-y-2">
                                                {(activePlan.goals ?? []).slice(0, 5).map((goal: any) => (
                                                    <div key={goal.id} className="flex items-center gap-3 rounded border p-2 text-sm">
                                                        <div className={`h-2 w-2 shrink-0 rounded-full ${goal.status === 'completed' ? 'bg-emerald-500' : goal.status === 'in_progress' ? 'bg-blue-500' : 'bg-slate-300'}`} />
                                                        <span className="flex-1 truncate">{goal.title}</span>
                                                        <span className={`text-xs font-medium ${goal.priority === 'critical' ? 'text-red-600' : goal.priority === 'high' ? 'text-amber-600' : 'text-slate-500'}`}>{goal.priority}</span>
                                                        <div className="w-16">
                                                            <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                                                <div className={`h-full rounded-full ${goal.status === 'completed' ? 'bg-emerald-500' : 'bg-indigo-500'}`} style={{ width: `${goal.progress_percentage ?? 0}%` }} />
                                                            </div>
                                                        </div>
                                                        <span className="w-8 text-right text-xs text-muted-foreground">{goal.progress_percentage ?? 0}%</span>
                                                    </div>
                                                ))}
                                            </div>
                                        )}

                                        {/* Dates */}
                                        {(activePlan.starts_at || activePlan.next_review_at) && (
                                            <div className="mt-3 flex gap-4 text-xs text-muted-foreground">
                                                {activePlan.starts_at && <span>Started: {new Date(activePlan.starts_at).toLocaleDateString('en-NZ')}</span>}
                                                {activePlan.next_review_at && (
                                                    <span className={reviewDue ? 'font-medium text-amber-600' : ''}>
                                                        Next Review: {new Date(activePlan.next_review_at).toLocaleDateString('en-NZ')}
                                                    </span>
                                                )}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            ) : (
                                <Card>
                                    <CardContent className="flex flex-col items-center justify-center py-12">
                                        <svg className="mb-3 h-12 w-12 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                        <p className="mb-1 font-medium">No Active Care Plan</p>
                                        <p className="mb-4 text-sm text-muted-foreground">Create a care plan to start tracking goals and progress for {client.first_name}.</p>
                                        <Button size="sm" asChild>
                                            <Link href={`/operations/care-plans/create?client_id=${client.id}`}>Create Care Plan</Link>
                                        </Button>
                                    </CardContent>
                                </Card>
                            )}

                            {/* About Me — from care plan content */}
                            {activePlan && (() => {
                                const content = typeof activePlan.content === 'string' ? JSON.parse(activePlan.content || '{}') : (activePlan.content ?? {});
                                const aboutMe = content.about_me ?? {};
                                const hasAboutMe = Object.values(aboutMe).some((v: any) => v && String(v).trim());
                                if (!hasAboutMe) return null;
                                return (
                                    <Card className="border-rose-200 bg-rose-50/30">
                                        <CardHeader>
                                            <CardTitle className="text-base">About {client.first_name}</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-3">
                                            {aboutMe.dreams && (
                                                <div>
                                                    <p className="text-xs font-semibold text-rose-600">Dreams & Aspirations</p>
                                                    <p className="mt-0.5 text-sm text-slate-700">{aboutMe.dreams}</p>
                                                </div>
                                            )}
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                {aboutMe.important_to_me && (
                                                    <div>
                                                        <p className="text-xs font-semibold text-rose-600">What{"'"}s Important TO Me</p>
                                                        <p className="mt-0.5 text-sm text-slate-700">{aboutMe.important_to_me}</p>
                                                    </div>
                                                )}
                                                {aboutMe.important_for_me && (
                                                    <div>
                                                        <p className="text-xs font-semibold text-rose-600">What{"'"}s Important FOR Me</p>
                                                        <p className="mt-0.5 text-sm text-slate-700">{aboutMe.important_for_me}</p>
                                                    </div>
                                                )}
                                            </div>
                                            {aboutMe.ideal_day && (
                                                <div>
                                                    <p className="text-xs font-semibold text-rose-600">My Ideal Day</p>
                                                    <p className="mt-0.5 text-sm text-slate-700">{aboutMe.ideal_day}</p>
                                                </div>
                                            )}
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                {aboutMe.likes && (
                                                    <div className="rounded-md bg-emerald-50 p-2">
                                                        <p className="text-xs font-semibold text-emerald-700">Things I Like</p>
                                                        <p className="mt-0.5 text-sm text-emerald-800">{aboutMe.likes}</p>
                                                    </div>
                                                )}
                                                {aboutMe.dislikes && (
                                                    <div className="rounded-md bg-red-50 p-2">
                                                        <p className="text-xs font-semibold text-red-700">Things I Don{"'"}t Like</p>
                                                        <p className="mt-0.5 text-sm text-red-800">{aboutMe.dislikes}</p>
                                                    </div>
                                                )}
                                            </div>
                                            {aboutMe.how_to_support && (
                                                <div>
                                                    <p className="text-xs font-semibold text-rose-600">How to Support Me Best</p>
                                                    <p className="mt-0.5 text-sm text-slate-700">{aboutMe.how_to_support}</p>
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                );
                            })()}

                            {/* Recent Progress Notes */}
                            {recentNotes.length > 0 && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center justify-between text-base">
                                            <span>Recent Progress Notes</span>
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={`/operations/progress-notes?client_id=${client.id}`}>View All</Link>
                                            </Button>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-3">
                                            {recentNotes.map((note: any) => (
                                                <div key={note.id} className={`rounded-lg border p-3 text-sm ${note.is_flagged ? 'border-l-4 border-l-red-400' : ''}`}>
                                                    <div className="mb-1 flex items-center justify-between">
                                                        <span className="font-medium">{note.author?.name ?? 'Unknown'}</span>
                                                        <span className="text-xs text-muted-foreground">{new Date(note.created_at).toLocaleDateString('en-NZ')}</span>
                                                    </div>
                                                    {note.goal && (
                                                        <span className="mb-1 inline-block rounded bg-indigo-50 px-1.5 py-0.5 text-xs text-indigo-600">Goal: {note.goal.title}</span>
                                                    )}
                                                    <p className="text-muted-foreground">{(note.content ?? '').slice(0, 200)}{(note.content ?? '').length > 200 ? '...' : ''}</p>
                                                </div>
                                            ))}
                                        </div>
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    );
                })()}

                {tab === 'progress_notes' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between text-base">
                                <span>Progress Notes</span>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={`/operations/progress-notes?client_id=${client.id}`}>View All</Link>
                                </Button>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">
                                Progress notes, observations, and goal updates for {client.first_name}.
                            </p>
                            <div className="mt-3">
                                <Button size="sm" asChild>
                                    <Link href={`/operations/progress-notes?client_id=${client.id}`}>View Progress Notes</Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {tab === 'service_agreements' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between text-base">
                                <span>Service Agreements</span>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={`/operations/service-agreements?client_id=${client.id}`}>View All</Link>
                                </Button>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">
                                Funding agreements, budgets, and service contracts for {client.first_name}.
                            </p>
                            <div className="mt-3 flex gap-2">
                                <Button size="sm" asChild>
                                    <Link href={`/operations/service-agreements?client_id=${client.id}`}>View Agreements</Link>
                                </Button>
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={`/operations/service-agreements/create?client_id=${client.id}`}>New Agreement</Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Support Plan merged into Care Plans tab */}

                {tab === 'assessments' && (
                    <AssessmentsTab
                        clientId={client.id}
                        assessments={assessments}
                        canEdit={can.edit}
                    />
                )}

                {tab === 'timeline' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Timeline
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {handover.length ? (
                                <div className="rounded-md border p-3">
                                    <div className="text-sm font-medium">
                                        Pinned handover
                                    </div>
                                    <div className="mt-2 space-y-2">
                                        {handover.map((h) => (
                                            <div
                                                key={h.id}
                                                className="rounded-md border p-3"
                                            >
                                                <div className="flex items-center justify-between gap-3">
                                                    <div className="text-sm font-medium">
                                                        {h.subject ||
                                                            'Handover'}
                                                    </div>
                                                    <div className="text-xs text-slate-500">
                                                        {h.occurred_at
                                                            ? new Date(
                                                                  h.occurred_at,
                                                              ).toLocaleString()
                                                            : ''}
                                                    </div>
                                                </div>
                                                {h.body && (
                                                    <div className="mt-2 text-xs whitespace-pre-wrap text-slate-600">
                                                        {h.body}
                                                    </div>
                                                )}
                                                <div className="mt-2 flex items-center justify-between gap-2">
                                                    <div className="text-xs text-slate-500">
                                                        {h.actor?.name
                                                            ? `By ${h.actor.name}`
                                                            : ''}
                                                    </div>
                                                    {can.pin_handover &&
                                                    h.source_id ? (
                                                        <button
                                                            className="text-xs underline"
                                                            onClick={async () => {
                                                                await fetch(
                                                                    `/operations/clients/${client.id}/notes/${h.source_id}/pin`,
                                                                    {
                                                                        method: 'POST',
                                                                        headers:
                                                                            {
                                                                                'X-Requested-With':
                                                                                    'XMLHttpRequest',
                                                                                'X-CSRF-TOKEN':
                                                                                    (
                                                                                        document.querySelector(
                                                                                            'meta[name="csrf-token"]',
                                                                                        ) as HTMLMetaElement
                                                                                    )
                                                                                        ?.content,
                                                                            },
                                                                    },
                                                                );
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
                                    <div className="text-sm font-medium">
                                        Add note
                                    </div>
                                    <div className="mt-3 grid grid-cols-1 gap-3">
                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <div>
                                                <Label>Type</Label>
                                                <Select
                                                    value={noteForm.data.type}
                                                    onValueChange={(v) => {
                                                        noteForm.setData(
                                                            'type',
                                                            v,
                                                        );
                                                        const tpl =
                                                            templates.find(
                                                                (t) =>
                                                                    t.key === v,
                                                            );
                                                        if (
                                                            tpl &&
                                                            noteForm.data.body.trim() ===
                                                                ''
                                                        ) {
                                                            noteForm.setData(
                                                                'body',
                                                                tpl.body,
                                                            );
                                                        }
                                                        noteForm.setData(
                                                            'pin',
                                                            v === 'handover',
                                                        );
                                                    }}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {templates.map((t) => (
                                                            <SelectItem
                                                                key={t.key}
                                                                value={t.key}
                                                            >
                                                                {t.label}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div>
                                                <Label>
                                                    Subject (optional)
                                                </Label>
                                                <Input
                                                    value={
                                                        noteForm.data.subject
                                                    }
                                                    onChange={(e) =>
                                                        noteForm.setData(
                                                            'subject',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        </div>

                                        {noteForm.data.type ===
                                        'progress_note' ? (
                                            <div>
                                                <Label>
                                                    Goal/outcome (optional)
                                                </Label>
                                                <Input
                                                    value={noteForm.data.goal}
                                                    onChange={(e) =>
                                                        noteForm.setData(
                                                            'goal',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        ) : null}
                                        <div>
                                            <Label>Note</Label>
                                            <Textarea
                                                rows={3}
                                                value={noteForm.data.body}
                                                onChange={(e) =>
                                                    noteForm.setData(
                                                        'body',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>
                                    <div className="mt-3 flex flex-wrap items-center gap-3">
                                        <div className="flex items-center gap-2 text-xs">
                                            <Checkbox
                                                checked={
                                                    noteForm.data.visibility ===
                                                    'portal'
                                                }
                                                onCheckedChange={(v) =>
                                                    noteForm.setData(
                                                        'visibility',
                                                        v
                                                            ? 'portal'
                                                            : 'internal',
                                                    )
                                                }
                                            />
                                            <span>Share in portal</span>
                                        </div>
                                        {noteForm.data.type === 'handover' ? (
                                            <div className="flex items-center gap-2 text-xs">
                                                <Checkbox
                                                    checked={noteForm.data.pin}
                                                    onCheckedChange={(v) =>
                                                        noteForm.setData(
                                                            'pin',
                                                            Boolean(v),
                                                        )
                                                    }
                                                />
                                                <span>Pin as handover</span>
                                            </div>
                                        ) : null}

                                        <Button
                                            onClick={() =>
                                                noteForm.post(
                                                    `/operations/clients/${client.id}/notes`,
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: () =>
                                                            noteForm.reset({
                                                                type: 'note',
                                                                subject: '',
                                                                goal: '',
                                                                body: '',
                                                                visibility:
                                                                    'internal',
                                                                pin: false,
                                                            }),
                                                    },
                                                )
                                            }
                                            disabled={
                                                noteForm.processing ||
                                                !noteForm.data.body
                                            }
                                        >
                                            Add
                                        </Button>
                                    </div>
                                </div>
                            )}

                            {events.map((e) => (
                                <div
                                    key={e.id}
                                    className="rounded-md border p-3"
                                >
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
                                        {e.actor?.name
                                            ? `By ${e.actor.name}`
                                            : ''}{' '}
                                        {e.site?.name ? `- ${e.site.name}` : ''}
                                    </div>
                                </div>
                            ))}
                            {!events.length && (
                                <div className="text-sm text-slate-500">
                                    No timeline events yet.
                                </div>
                            )}

                            <div className="pt-2">
                                <Link
                                    href={`/operations/clients/${client.id}/timeline`}
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
                            <CardTitle className="text-base">
                                Documents
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {documents.map((d) => (
                                <div
                                    key={d.id}
                                    className="flex items-start justify-between gap-3 rounded-md border p-3"
                                >
                                    <div>
                                        <div className="text-sm font-medium">
                                            {d.title || d.original_name}
                                        </div>
                                        <div className="mt-1 text-xs text-slate-500">
                                            {[
                                                d.category &&
                                                    `Category: ${d.category}`,
                                                d.mime_type && d.mime_type,
                                            ]
                                                .filter(Boolean)
                                                .join(' - ') || '-'}
                                        </div>
                                        {d.notes && (
                                            <div className="mt-2 text-xs whitespace-pre-wrap text-slate-600">
                                                {d.notes}
                                            </div>
                                        )}
                                    </div>
                                    <a
                                        href={`/operations/clients/${client.id}/documents/${d.id}/download`}
                                        className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                    >
                                        Download
                                    </a>
                                </div>
                            ))}
                            {!documents.length && (
                                <div className="text-sm text-slate-500">
                                    No documents uploaded.
                                </div>
                            )}

                            <div className="pt-2">
                                <Link
                                    href={`/operations/clients/${client.id}/documents`}
                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                >
                                    Manage documents
                                </Link>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {tab === 'respite' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Respite</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex flex-wrap items-center gap-2">
                                {respiteCan?.create ? (
                                    <Button size="sm" asChild>
                                        <Link
                                            href={`/respite/requests/create?client_id=${client.id}`}
                                        >
                                            New booking request
                                        </Link>
                                    </Button>
                                ) : null}
                                <Link
                                    href="/respite/requests"
                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                >
                                    View booking requests
                                </Link>
                                <Link
                                    href="/respite/bookings"
                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                >
                                    View approved bookings
                                </Link>
                            </div>

                            <Separator />

                            <div>
                                <div className="text-sm font-medium">
                                    Bookings
                                </div>
                                <div className="mt-2 space-y-2">
                                    {respiteBookings.map((b) => (
                                        <div
                                            key={b.id}
                                            className="rounded-md border p-3"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <div className="text-sm font-medium">
                                                        {formatDateTime(
                                                            b.start_at,
                                                        )}{' '}
                                                        -{' '}
                                                        {formatDateTime(
                                                            b.end_at,
                                                        )}
                                                    </div>
                                                    <div className="mt-1 text-xs text-slate-500">
                                                        Status: {b.status}
                                                        {b.coordinator?.name
                                                            ? ` | Coordinator: ${b.coordinator.name}`
                                                            : ''}
                                                    </div>
                                                    {b.shift_id ? (
                                                        <div className="mt-1 text-xs text-slate-500">
                                                            Shift:{' '}
                                                            <Link
                                                                href={`/operations/shifts/${b.shift_id}`}
                                                                className="text-indigo-500 hover:text-indigo-400"
                                                            >
                                                                View shift
                                                            </Link>
                                                        </div>
                                                    ) : null}
                                                </div>
                                                <Link
                                                    href={`/respite/bookings/${b.id}`}
                                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                                >
                                                    View
                                                </Link>
                                            </div>
                                        </div>
                                    ))}
                                    {!respiteBookings.length && (
                                        <div className="text-sm text-slate-500">
                                            No respite bookings yet.
                                        </div>
                                    )}
                                </div>
                            </div>

                            <Separator />

                            <div>
                                <div className="text-sm font-medium">
                                    Booking Requests
                                </div>
                                <div className="mt-2 space-y-2">
                                    {respiteRequests.map((r) => (
                                        <div
                                            key={r.id}
                                            className="rounded-md border p-3"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <div className="text-sm font-medium">
                                                        {formatDateTime(
                                                            r.requested_start,
                                                        )}{' '}
                                                        -{' '}
                                                        {formatDateTime(
                                                            r.requested_end,
                                                        )}
                                                    </div>
                                                    <div className="mt-1 text-xs text-slate-500">
                                                        Status: {r.status}
                                                    </div>
                                                </div>
                                                <Link
                                                    href={`/respite/requests/${r.id}`}
                                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                                >
                                                    View
                                                </Link>
                                            </div>
                                        </div>
                                    ))}
                                    {!respiteRequests.length && (
                                        <div className="text-sm text-slate-500">
                                            No respite booking requests yet.
                                        </div>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {tab === 'consents' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between text-base">
                                <span>Consents</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">
                                Manage consent records for {client.first_name} — medication consent, data sharing, photography, treatment authorisation, and more.
                            </p>
                            <p className="mt-2 text-xs text-muted-foreground">
                                Consent management is tracked per {(labels?.['client.singular'] ?? 'Client').toLowerCase()} to ensure GDPR and privacy compliance.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {tab === 'portal' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Portal access ({labels?.['client.singular'] ?? 'Client'} / Next of Kin)
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-sm text-slate-600">
                                Portal users can view this {(labels?.['client.singular'] ?? 'Client').toLowerCase()}{"'s"} medical,
                                documents, and timeline, and can query the RAG
                                assistant.
                            </div>
                            <Separator />
                            <div className="space-y-2">
                                {portal_users.map((u) => (
                                    <div
                                        key={u.id}
                                        className="rounded-md border p-3"
                                    >
                                        <div className="text-sm font-medium">
                                            {u.name}
                                        </div>
                                        <div className="text-xs text-slate-500">
                                            {u.email}
                                        </div>
                                        {u.relation && (
                                            <div className="mt-1 text-xs text-slate-500">
                                                Relation: {u.relation}
                                            </div>
                                        )}
                                    </div>
                                ))}
                                {!portal_users.length && (
                                    <div className="text-sm text-slate-500">
                                        No portal users linked.
                                    </div>
                                )}
                            </div>

                            {can.edit && (
                                <div className="pt-2">
                                    <Link
                                        href={`/operations/clients/${client.id}/portal-users`}
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
                            <CardTitle className="text-base">
                                Assignments
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-sm text-slate-600">
                                Assign support workers to this {(labels?.['client.singular'] ?? 'Client').toLowerCase()}. This
                                controls which staff can see the {(labels?.['client.singular'] ?? 'Client').toLowerCase()}.
                            </div>
                            <div className="pt-2">
                                <Link
                                    href={`/operations/clients/${client.id}/assignments`}
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

function SupportPlanTab({
    clientId,
    plan,
    canEdit,
}: {
    clientId: number;
    plan: any | null;
    canEdit: boolean;
}) {
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
                {!canEdit && !plan && (
                    <div className="text-sm text-slate-500">
                        No support plan recorded.
                    </div>
                )}

                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div>
                        <Label>Reviewed at</Label>
                        <Input
                            type="date"
                            value={form.data.reviewed_at}
                            onChange={(e) =>
                                form.setData('reviewed_at', e.target.value)
                            }
                            disabled={!canEdit}
                        />
                    </div>
                    <div>
                        <Label>Next review</Label>
                        <Input
                            type="date"
                            value={form.data.next_review_at}
                            onChange={(e) =>
                                form.setData('next_review_at', e.target.value)
                            }
                            disabled={!canEdit}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Goals</Label>
                        <Textarea
                            rows={4}
                            value={form.data.goals}
                            onChange={(e) =>
                                form.setData('goals', e.target.value)
                            }
                            disabled={!canEdit}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Daily routines</Label>
                        <Textarea
                            rows={4}
                            value={form.data.routines}
                            onChange={(e) =>
                                form.setData('routines', e.target.value)
                            }
                            disabled={!canEdit}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Preferences</Label>
                        <Textarea
                            rows={4}
                            value={form.data.preferences}
                            onChange={(e) =>
                                form.setData('preferences', e.target.value)
                            }
                            disabled={!canEdit}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Communication needs</Label>
                        <Textarea
                            rows={4}
                            value={form.data.communication_needs}
                            onChange={(e) =>
                                form.setData(
                                    'communication_needs',
                                    e.target.value,
                                )
                            }
                            disabled={!canEdit}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Cultural needs</Label>
                        <Textarea
                            rows={3}
                            value={form.data.cultural_needs}
                            onChange={(e) =>
                                form.setData('cultural_needs', e.target.value)
                            }
                            disabled={!canEdit}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <Label>Risk notes</Label>
                        <Textarea
                            rows={3}
                            value={form.data.risk_notes}
                            onChange={(e) =>
                                form.setData('risk_notes', e.target.value)
                            }
                            disabled={!canEdit}
                        />
                    </div>
                </div>

                {canEdit && (
                    <div>
                        <Button
                            onClick={() =>
                                form.put(`/operations/clients/${clientId}/support-plan`, {
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

function AssessmentsTab({
    clientId,
    assessments,
    canEdit,
}: {
    clientId: number;
    assessments: Array<any>;
    canEdit: boolean;
}) {
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
                            <div className="text-sm font-medium">
                                {editingId
                                    ? 'Edit assessment'
                                    : 'Add assessment'}
                            </div>
                            {editingId ? (
                                <Button variant="ghost" onClick={resetForm}>
                                    Cancel
                                </Button>
                            ) : null}
                        </div>
                        <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <Label>Type</Label>
                                <Input
                                    value={form.data.type}
                                    onChange={(e) =>
                                        form.setData('type', e.target.value)
                                    }
                                    placeholder="e.g. WHODAS, risk, medication review"
                                />
                            </div>
                            <div>
                                <Label>Score (optional)</Label>
                                <Input
                                    value={form.data.score}
                                    onChange={(e) =>
                                        form.setData('score', e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <Label>Assessed at</Label>
                                <Input
                                    type="date"
                                    value={form.data.assessed_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'assessed_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div>
                                <Label>Next review</Label>
                                <Input
                                    type="date"
                                    value={form.data.next_review_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'next_review_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="md:col-span-2">
                                <Label>Notes</Label>
                                <Textarea
                                    rows={3}
                                    value={form.data.notes}
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
                                />
                            </div>
                        </div>
                        <div className="mt-3 flex items-center gap-2">
                            <Button
                                onClick={() => {
                                    const url = editingId
                                        ? `/operations/clients/${clientId}/assessments/${editingId}`
                                        : `/operations/clients/${clientId}/assessments`;
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
                                    <div className="text-sm font-medium">
                                        {a.type}
                                    </div>
                                    <div className="mt-1 text-xs text-slate-500">
                                        {[
                                            a.score && `Score: ${a.score}`,
                                            a.assessed_at &&
                                                `Assessed: ${a.assessed_at}`,
                                            a.next_review_at &&
                                                `Next review: ${a.next_review_at}`,
                                        ]
                                            .filter(Boolean)
                                            .join(' • ') || '-'}
                                    </div>
                                    {a.notes && (
                                        <div className="mt-2 text-xs whitespace-pre-wrap text-slate-600">
                                            {a.notes}
                                        </div>
                                    )}
                                </div>

                                {canEdit && (
                                    <div className="flex items-center gap-2">
                                        <Button
                                            variant="secondary"
                                            onClick={() => startEdit(a)}
                                        >
                                            Edit
                                        </Button>
                                        <Button
                                            variant="destructive"
                                            onClick={() =>
                                                form.delete(
                                                    `/operations/clients/${clientId}/assessments/${a.id}`,
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                )
                                            }
                                        >
                                            Delete
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </div>
                    ))}

                    {!assessments.length && (
                        <div className="text-sm text-slate-500">
                            No assessments recorded.
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
