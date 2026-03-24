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
import { Badge } from '@/components/ui/badge';
import { Calendar, Car, ChevronDown, ChevronRight, DollarSign, Globe, GraduationCap, Heart, Pill, Search, ShieldAlert, Star } from 'lucide-react';
import { useMemo, useState } from 'react';
import { HalfMoonGauge, ProgressRing, HorizontalBarChart } from '@/components/fleet-charts';
import { DonutChart } from '@/components/ops-stat-card';

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
        transport_needs?: string[] | null;
        transport_notes?: string | null;
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
    const pageProps = usePage().props as any;
    const { auth, labels } = pageProps;
    const respiteCan = auth?.can?.respite ?? {};
    const consents = pageProps.consents ?? [];
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

    // Timeline filter state
    const [timelineSearch, setTimelineSearch] = useState('');
    const [timelineTypeFilter, setTimelineTypeFilter] = useState('all');

    const eventTypes = useMemo(() => {
        const types = new Set<string>();
        events.forEach((e) => { if (e.type) types.add(e.type); });
        return Array.from(types).sort();
    }, [events]);

    const filteredEvents = useMemo(() => {
        return events.filter((e) => {
            if (timelineTypeFilter !== 'all' && e.type !== timelineTypeFilter) return false;
            if (timelineSearch) {
                const q = timelineSearch.toLowerCase();
                const searchable = [e.subject, e.body, e.type, e.actor?.name].filter(Boolean).join(' ').toLowerCase();
                if (!searchable.includes(q)) return false;
            }
            return true;
        });
    }, [events, timelineSearch, timelineTypeFilter]);

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

                {tab === 'profile' && (() => {
                    const summary = pageProps.care_plans_summary ?? {};
                    const activePlan = summary.active_plan;
                    const risks = pageProps.client_risks ?? [];
                    const incidents = pageProps.client_incidents ?? [];
                    const agreements = pageProps.client_agreements ?? [];
                    const profileConsents = pageProps.consents ?? [];
                    const notes = pageProps.client_progress_notes ?? [];

                    // Parse about me from care plan content
                    const planContent = activePlan?.content ? (typeof activePlan.content === 'string' ? JSON.parse(activePlan.content || '{}') : activePlan.content) : {};
                    const aboutMe = planContent.about_me ?? {};
                    const hasAboutMe = Object.values(aboutMe).some((v: any) => v && String(v).trim());

                    // Goal stats
                    const goals = activePlan?.goals ?? [];
                    const goalsCompleted = goals.filter((g: any) => g.status === 'completed').length;
                    const goalsPct = goals.length > 0 ? Math.round((goalsCompleted / goals.length) * 100) : 0;

                    // Risk donut data
                    const riskCounts: Record<string, number> = {};
                    risks.forEach((r: any) => { riskCounts[r.severity] = (riskCounts[r.severity] ?? 0) + 1; });
                    const riskDonutSegments = Object.entries(riskCounts).map(([sev, count]) => ({
                        label: sev, value: count,
                        color: sev === 'critical' ? '#dc2626' : sev === 'high' ? '#ea580c' : sev === 'medium' ? '#d97706' : '#16a34a',
                    }));

                    // Budget from first active agreement
                    const activeAg = agreements.find((a: any) => a.status === 'active');
                    const budgetPct = activeAg?.total_budget > 0 ? Math.round(((activeAg.budget_used ?? 0) / activeAg.total_budget) * 100) : 0;

                    // Active consents count
                    const activeConsents = profileConsents.filter((c: any) => c.status === 'given' && !c.is_expired).length;

                    // Review countdown
                    const reviewDays = activePlan?.next_review_at ? Math.ceil((new Date(activePlan.next_review_at).getTime() - Date.now()) / 86400000) : null;

                    return (
                        <>
                            {/* Safeguarding Alert */}
                            {client.safeguarding_flag && (
                                <div className="mb-4 flex items-center gap-3 rounded-xl border-2 border-red-300 bg-red-50 p-4">
                                    <ShieldAlert className="h-6 w-6 text-red-600" />
                                    <div>
                                        <p className="text-sm font-bold text-red-800">Safeguarding Alert</p>
                                        <p className="text-xs text-red-700">Active safeguarding concern. Follow protocols.</p>
                                    </div>
                                </div>
                            )}

                            {/* Row 1: Quick Stats */}
                            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                                {/* Care Plan Status */}
                                <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-4">
                                    <p className="text-[10px] font-semibold uppercase tracking-wider text-violet-500">Care Plan</p>
                                    <p className="mt-1 text-lg font-bold text-violet-900">{activePlan ? 'Active' : 'None'}</p>
                                    {reviewDays !== null && (
                                        <p className={`mt-0.5 text-xs ${reviewDays < 0 ? 'font-semibold text-red-600' : 'text-violet-600'}`}>
                                            Review: {reviewDays < 0 ? `${Math.abs(reviewDays)}d overdue` : `${reviewDays}d`}
                                        </p>
                                    )}
                                </div>

                                {/* Goals */}
                                <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-4">
                                    <p className="text-[10px] font-semibold uppercase tracking-wider text-violet-500">Goals</p>
                                    <p className="mt-1 text-lg font-bold text-violet-900">{goalsCompleted}/{goals.length}</p>
                                    <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-violet-200">
                                        <div className="h-full rounded-full bg-violet-600 transition-all" style={{ width: `${goalsPct}%` }} />
                                    </div>
                                </div>

                                {/* Shifts */}
                                <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-4">
                                    <p className="text-[10px] font-semibold uppercase tracking-wider text-violet-500">Shifts</p>
                                    <p className="mt-1 text-lg font-bold text-violet-900">{shifts_summary?.next ? 'Upcoming' : 'None'}</p>
                                    {shifts_summary?.next?.starts_at && (
                                        <p className="mt-0.5 text-xs text-violet-600">{new Date(shifts_summary.next.starts_at).toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short' })}</p>
                                    )}
                                </div>

                                {/* Risk Level */}
                                <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-4">
                                    <p className="text-[10px] font-semibold uppercase tracking-wider text-violet-500">Risk Level</p>
                                    <div className="mt-1 flex items-center gap-2">
                                        <span className={`inline-flex rounded-full px-2.5 py-1 text-sm font-bold ${
                                            client.risk_level === 'critical' ? 'bg-red-100 text-red-700 animate-pulse' :
                                            client.risk_level === 'high' ? 'bg-red-100 text-red-700' :
                                            client.risk_level === 'medium' ? 'bg-amber-100 text-amber-700' :
                                            client.risk_level === 'low' ? 'bg-emerald-100 text-emerald-700' :
                                            'bg-slate-100 text-slate-500'
                                        }`}>
                                            {(client.risk_level ?? 'Not Set').charAt(0).toUpperCase() + (client.risk_level ?? 'not set').slice(1)}
                                        </span>
                                    </div>
                                    <p className="mt-0.5 text-xs text-violet-600">{risks.length} active risk{risks.length !== 1 ? 's' : ''}</p>
                                </div>
                            </div>

                            {/* Row 2: Main Dashboard Grid */}
                            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                                {/* LEFT COLUMN */}
                                <div className="space-y-4 lg:col-span-2">

                                    {/* About Me Card */}
                                    {hasAboutMe && (
                                        <Card className="overflow-hidden border-violet-200">
                                            <div className="bg-gradient-to-r from-violet-500 to-purple-600 px-5 py-3">
                                                <h3 className="text-sm font-semibold text-white">About {client.first_name}</h3>
                                                <p className="text-xs text-violet-200">What matters most to this person</p>
                                            </div>
                                            <CardContent className="space-y-3 p-5">
                                                {aboutMe.dreams && (
                                                    <div className="rounded-lg bg-violet-50 p-3">
                                                        <p className="text-[10px] font-bold uppercase tracking-wider text-violet-500">Dreams &amp; Aspirations</p>
                                                        <p className="mt-1 text-sm text-slate-700">{aboutMe.dreams}</p>
                                                    </div>
                                                )}
                                                <div className="grid gap-3 sm:grid-cols-2">
                                                    {aboutMe.important_to_me && (
                                                        <div className="rounded-lg bg-purple-50 p-3">
                                                            <p className="text-[10px] font-bold uppercase tracking-wider text-purple-500">Important TO Me</p>
                                                            <p className="mt-1 text-sm text-slate-700">{aboutMe.important_to_me}</p>
                                                        </div>
                                                    )}
                                                    {aboutMe.important_for_me && (
                                                        <div className="rounded-lg bg-purple-50 p-3">
                                                            <p className="text-[10px] font-bold uppercase tracking-wider text-purple-500">Important FOR Me</p>
                                                            <p className="mt-1 text-sm text-slate-700">{aboutMe.important_for_me}</p>
                                                        </div>
                                                    )}
                                                </div>
                                                {aboutMe.ideal_day && (
                                                    <div className="rounded-lg bg-violet-50 p-3">
                                                        <p className="text-[10px] font-bold uppercase tracking-wider text-violet-500">My Ideal Day</p>
                                                        <p className="mt-1 text-sm text-slate-700">{aboutMe.ideal_day}</p>
                                                    </div>
                                                )}
                                                <div className="grid gap-3 sm:grid-cols-2">
                                                    {aboutMe.likes && (
                                                        <div className="rounded-lg bg-emerald-50 p-3">
                                                            <p className="text-[10px] font-bold uppercase tracking-wider text-emerald-600">Things I Like</p>
                                                            <p className="mt-1 text-sm text-emerald-800">{aboutMe.likes}</p>
                                                        </div>
                                                    )}
                                                    {aboutMe.dislikes && (
                                                        <div className="rounded-lg bg-red-50 p-3">
                                                            <p className="text-[10px] font-bold uppercase tracking-wider text-red-500">Things I Don't Like</p>
                                                            <p className="mt-1 text-sm text-red-800">{aboutMe.dislikes}</p>
                                                        </div>
                                                    )}
                                                </div>
                                                {aboutMe.how_to_support && (
                                                    <div className="rounded-lg border border-violet-200 bg-white p-3">
                                                        <p className="text-[10px] font-bold uppercase tracking-wider text-violet-500">How to Support Me Best</p>
                                                        <p className="mt-1 text-sm text-slate-700">{aboutMe.how_to_support}</p>
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>
                                    )}

                                    {/* Goals Progress Card */}
                                    {goals.length > 0 && (
                                        <Card className="overflow-hidden">
                                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                                <CardTitle className="text-sm font-semibold">Goals Progress</CardTitle>
                                                {activePlan && (
                                                    <Button variant="ghost" size="sm" className="text-xs text-violet-600" asChild>
                                                        <Link href={`/operations/care-plans/${activePlan.id}`}>View Plan</Link>
                                                    </Button>
                                                )}
                                            </CardHeader>
                                            <CardContent>
                                                <div className="flex items-start gap-6">
                                                    <div className="shrink-0">
                                                        <HalfMoonGauge value={goalsPct} label="Complete" size={140} color="#7c3aed" />
                                                    </div>
                                                    <div className="flex-1">
                                                        <HorizontalBarChart
                                                            items={goals.slice(0, 6).map((g: any) => ({
                                                                label: g.title.length > 25 ? g.title.slice(0, 25) + '...' : g.title,
                                                                value: g.progress_percentage ?? 0,
                                                                maxValue: 100,
                                                                color: g.status === 'completed' ? '#16a34a' : '#7c3aed',
                                                            }))}
                                                        />
                                                    </div>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    )}

                                    {/* Recent Activity Card */}
                                    <Card>
                                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                                            <CardTitle className="text-sm font-semibold">Recent Activity</CardTitle>
                                            <Button variant="ghost" size="sm" className="text-xs text-violet-600" onClick={() => setTab('timeline')}>
                                                View All
                                            </Button>
                                        </CardHeader>
                                        <CardContent>
                                            {notes.length === 0 && incidents.length === 0 ? (
                                                <p className="py-4 text-center text-xs text-muted-foreground">No recent activity</p>
                                            ) : (
                                                <div className="space-y-2">
                                                    {[...notes.slice(0, 3).map((n: any) => ({
                                                        id: 'n' + n.id,
                                                        icon: '\u{1F4DD}',
                                                        text: `${n.author?.name ?? 'Unknown'}: ${(n.content ?? '').slice(0, 80)}${(n.content ?? '').length > 80 ? '...' : ''}`,
                                                        date: n.created_at,
                                                    })), ...incidents.slice(0, 2).map((inc: any) => ({
                                                        id: 'i' + inc.id,
                                                        icon: '\u26A0\uFE0F',
                                                        text: `Incident: ${inc.type ?? 'Unknown'} (${inc.severity ?? ''})`,
                                                        date: inc.occurred_at,
                                                    }))].sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime()).slice(0, 5).map((item) => (
                                                        <div key={item.id} className="flex items-start gap-2 rounded-lg border border-slate-100 bg-slate-50/50 p-2.5 text-sm">
                                                            <span className="shrink-0 text-base">{item.icon}</span>
                                                            <span className="flex-1 text-xs text-slate-700">{item.text}</span>
                                                            <span className="shrink-0 text-[10px] text-muted-foreground">{new Date(item.date).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                </div>

                                {/* RIGHT COLUMN */}
                                <div className="space-y-4">
                                    {/* Profile Summary */}
                                    <Card className="overflow-hidden">
                                        <div className="bg-gradient-to-b from-violet-100 to-white px-4 pb-2 pt-4 text-center">
                                            <Avatar className="mx-auto h-16 w-16 border-2 border-white shadow">
                                                <AvatarImage src={client.avatar ?? ''} />
                                                <AvatarFallback className="bg-violet-200 text-violet-700 text-lg">{getInitials(`${client.first_name} ${client.last_name}`)}</AvatarFallback>
                                            </Avatar>
                                            <h3 className="mt-2 text-sm font-bold">{client.preferred_name || client.first_name} {client.last_name}</h3>
                                            {client.preferred_pronouns && <p className="text-xs text-muted-foreground">{client.preferred_pronouns}</p>}
                                        </div>
                                        <CardContent className="space-y-1.5 px-4 pb-4 pt-2 text-xs">
                                            {client.date_of_birth && <div className="flex justify-between"><span className="text-muted-foreground">DOB</span><span>{new Date(client.date_of_birth).toLocaleDateString('en-NZ')}</span></div>}
                                            {client.phone && <div className="flex justify-between"><span className="text-muted-foreground">Phone</span><span>{client.phone}</span></div>}
                                            {client.email && <div className="flex justify-between"><span className="text-muted-foreground">Email</span><span className="truncate ml-2">{client.email}</span></div>}
                                            {client.city && <div className="flex justify-between"><span className="text-muted-foreground">Location</span><span>{client.suburb ? `${client.suburb}, ` : ''}{client.city}</span></div>}
                                            {(client.ethnicity || client.religion || (client.languages ?? []).length > 0) && (
                                                <>
                                                    <Separator className="my-2" />
                                                    {client.ethnicity && <div className="flex justify-between"><span className="text-muted-foreground">Ethnicity</span><span>{client.ethnicity}</span></div>}
                                                    {(client.languages ?? []).length > 0 && <div className="flex justify-between"><span className="text-muted-foreground">Languages</span><span>{(client.languages ?? []).join(', ')}</span></div>}
                                                    {client.religion && <div className="flex justify-between"><span className="text-muted-foreground">Religion</span><span>{client.religion}</span></div>}
                                                </>
                                            )}
                                        </CardContent>
                                    </Card>

                                    {/* Risk & Safety */}
                                    {risks.length > 0 && (
                                        <Card>
                                            <CardHeader className="pb-2">
                                                <CardTitle className="text-sm font-semibold">Risk &amp; Safety</CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                <div className="flex justify-center">
                                                    <DonutChart segments={riskDonutSegments} size={110} strokeWidth={16} centerLabel="Risks" centerValue={risks.length} />
                                                </div>
                                                <div className="mt-3 flex flex-wrap justify-center gap-2">
                                                    {riskDonutSegments.map((seg) => (
                                                        <div key={seg.label} className="flex items-center gap-1 text-[10px]">
                                                            <div className="h-2 w-2 rounded-full" style={{ backgroundColor: seg.color }} />
                                                            <span className="capitalize">{seg.label}: {seg.value}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                                {incidents.length > 0 && (
                                                    <div className="mt-3 rounded-lg bg-amber-50 p-2 text-center text-xs text-amber-700">
                                                        {incidents.length} recent incident{incidents.length !== 1 ? 's' : ''}
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>
                                    )}

                                    {/* Support Team */}
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm font-semibold">Support Team</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2">
                                            {client.key_worker && (
                                                <div className="flex items-center gap-2 rounded-lg bg-violet-50 p-2">
                                                    <div className="flex h-7 w-7 items-center justify-center rounded-full bg-violet-200 text-xs font-bold text-violet-700">KW</div>
                                                    <div>
                                                        <p className="text-xs font-medium">{client.key_worker.name}</p>
                                                        <p className="text-[10px] text-violet-500">Key Worker</p>
                                                    </div>
                                                </div>
                                            )}
                                            {(client.support_workers ?? []).slice(0, 4).map((sw: any) => (
                                                <div key={sw.id} className="flex items-center gap-2 p-1">
                                                    <div className="flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-[10px] font-bold text-slate-500">SW</div>
                                                    <p className="text-xs">{sw.name}</p>
                                                </div>
                                            ))}
                                            {client.funding_type && (
                                                <div className="mt-2 rounded bg-violet-50 px-2 py-1 text-center text-xs text-violet-600">
                                                    Funding: {client.funding_type}
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>

                                    {/* Service Overview */}
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm font-semibold">Service Overview</CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="flex justify-center">
                                                <ProgressRing value={budgetPct} size={90} color="#7c3aed" label="Budget Used" />
                                            </div>
                                            <div className="mt-3 grid grid-cols-3 gap-2 text-center">
                                                <div className="rounded-lg bg-slate-50 p-2">
                                                    <div className="text-sm font-bold text-violet-600">{activeConsents}</div>
                                                    <div className="text-[9px] uppercase text-muted-foreground">Consents</div>
                                                </div>
                                                <div className="rounded-lg bg-slate-50 p-2">
                                                    <div className="text-sm font-bold text-violet-600">{(documents ?? []).length}</div>
                                                    <div className="text-[9px] uppercase text-muted-foreground">Documents</div>
                                                </div>
                                                <div className="rounded-lg bg-slate-50 p-2">
                                                    <div className="text-sm font-bold text-violet-600">{(assessments ?? []).length}</div>
                                                    <div className="text-[9px] uppercase text-muted-foreground">Assessments</div>
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>
                                </div>
                            </div>

                            {/* Row 3: Health & Needs */}
                            {(client.mobility_needs || client.sensory_needs || client.cognitive_needs || client.dietary_requirements || client.sleep_preferences) && (
                                <div className="mt-4 grid gap-3 sm:grid-cols-3">
                                    {(client.mobility_needs || client.sensory_needs || client.cognitive_needs) && (
                                        <Card className="border-violet-100">
                                            <CardContent className="p-4">
                                                <p className="text-[10px] font-bold uppercase tracking-wider text-violet-500">Health &amp; Support Needs</p>
                                                {client.mobility_needs && <p className="mt-2 text-xs"><span className="font-medium">Mobility:</span> {client.mobility_needs}</p>}
                                                {client.sensory_needs && <p className="mt-1 text-xs"><span className="font-medium">Sensory:</span> {client.sensory_needs}</p>}
                                                {client.cognitive_needs && <p className="mt-1 text-xs"><span className="font-medium">Cognitive:</span> {client.cognitive_needs}</p>}
                                            </CardContent>
                                        </Card>
                                    )}
                                    {client.dietary_requirements && (
                                        <Card className="border-violet-100">
                                            <CardContent className="p-4">
                                                <p className="text-[10px] font-bold uppercase tracking-wider text-violet-500">Dietary Requirements</p>
                                                <p className="mt-2 text-xs">{client.dietary_requirements}</p>
                                            </CardContent>
                                        </Card>
                                    )}
                                    {client.sleep_preferences && (
                                        <Card className="border-violet-100">
                                            <CardContent className="p-4">
                                                <p className="text-[10px] font-bold uppercase tracking-wider text-violet-500">Sleep Preferences</p>
                                                <p className="mt-2 text-xs">{client.sleep_preferences}</p>
                                            </CardContent>
                                        </Card>
                                    )}
                                </div>
                            )}

                            {/* Footer Links */}
                            <Separator className="mt-4" />
                            <div className="mt-3 flex flex-wrap gap-2">
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
                        </>
                    );
                })()}

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
                                    {onboarding.workflow.steps.map((step: any) => {
                                        const stepCategory = /DBS|Health Screening|GDPR|Safeguarding/i.test(step.step_name ?? '')
                                            ? { label: 'Compliance', color: 'bg-purple-100 text-purple-700' }
                                            : /Referral|Assessment|Care Plan|Agreement|Staff|Introduction/i.test(step.step_name ?? '')
                                            ? { label: 'Service', color: 'bg-blue-100 text-blue-700' }
                                            : { label: 'Admin', color: 'bg-slate-100 text-slate-600' };
                                        return (
                                        <div key={step.id} className={`flex items-center justify-between rounded-md border p-3 ${step.status === 'completed' ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900/30 dark:bg-emerald-950/20' : step.due_date && new Date(step.due_date) < new Date() && step.status === 'pending' ? 'border-red-200 bg-red-50/50 dark:border-red-900/30 dark:bg-red-950/20' : ''}`}>
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium">
                                                    {step.step_order}
                                                </div>
                                                <div>
                                                    <div className="flex items-center gap-2 text-sm font-medium">
                                                        <span className={`rounded px-1.5 py-0.5 text-[10px] font-medium ${stepCategory.color}`}>{stepCategory.label}</span>
                                                        {step.step_name}
                                                    </div>
                                                    {step.description && (
                                                        <div className="mt-0.5 text-xs text-slate-500">{step.description}</div>
                                                    )}
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
                                        );
                                    })}
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

                        <Card className="mt-4 border-orange-200 bg-orange-50/30">
                            <CardContent className="flex items-center gap-4 p-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-orange-100 text-orange-600">
                                    <GraduationCap className="h-5 w-5" />
                                </div>
                                <div className="flex-1">
                                    <p className="text-sm font-medium">Staff Preparation</p>
                                    <p className="text-xs text-muted-foreground">Staff training status and induction progress for assigned support workers will be shown here once HR integration is complete.</p>
                                </div>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href="/hr">Open HR</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {tab === 'medical' && (
                    <div className="space-y-4">
                        <Card className="mb-4 border-cyan-200 bg-cyan-50/30">
                            <CardContent className="flex items-center gap-4 p-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-cyan-100 text-cyan-600">
                                    <Pill className="h-5 w-5" />
                                </div>
                                <div className="flex-1">
                                    <p className="text-sm font-medium">Medication Compliance</p>
                                    <p className="text-xs text-muted-foreground">eMAR compliance tracking and medication administration records will be available here.</p>
                                </div>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href="/emar">Open eMAR</Link>
                                </Button>
                            </CardContent>
                        </Card>
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

                {tab === 'progress_notes' && (() => {
                    const pageProps = usePage().props as any;
                    const notes = pageProps.client_progress_notes ?? [];

                    return (
                        <div className="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center justify-between text-base">
                                        <span>Progress Notes ({notes.length})</span>
                                        <Button variant="outline" size="sm" asChild>
                                            <Link href={`/operations/progress-notes?client_id=${client.id}`}>View All</Link>
                                        </Button>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {notes.length === 0 ? (
                                        <p className="text-sm text-muted-foreground text-center py-8">No progress notes yet.</p>
                                    ) : (
                                        <div className="space-y-2">
                                            {notes.map((note: any) => (
                                                <div key={note.id} className={`rounded-lg border p-3 text-sm ${note.is_flagged ? 'border-l-4 border-l-red-400' : ''}`}>
                                                    <div className="flex items-center justify-between mb-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-medium">{note.author?.name ?? 'Unknown'}</span>
                                                            {note.mood_rating && (
                                                                <span className={`inline-flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold text-white ${
                                                                    note.mood_rating >= 7 ? 'bg-emerald-500' : note.mood_rating >= 4 ? 'bg-amber-500' : 'bg-red-500'
                                                                }`}>{note.mood_rating}</span>
                                                            )}
                                                            {note.visibility === 'include_family' && (
                                                                <span className="rounded bg-blue-100 px-1.5 py-0.5 text-[10px] text-blue-700">Family Visible</span>
                                                            )}
                                                        </div>
                                                        <span className="text-xs text-muted-foreground">{new Date(note.created_at).toLocaleDateString('en-NZ')}</span>
                                                    </div>
                                                    {note.goal && (
                                                        <span className="inline-block rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] text-indigo-600 mb-1">Goal: {note.goal.title}</span>
                                                    )}
                                                    <p className="text-muted-foreground">{(note.content ?? '').slice(0, 300)}{(note.content ?? '').length > 300 ? '...' : ''}</p>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    );
                })()}

                {tab === 'service_agreements' && (() => {
                    const pageProps = usePage().props as any;
                    const agreements = pageProps.client_agreements ?? [];

                    return (
                        <div className="space-y-4">
                            {agreements.length === 0 ? (
                                <Card>
                                    <CardContent className="flex flex-col items-center justify-center py-12">
                                        <p className="mb-1 font-medium">No Service Agreements</p>
                                        <p className="mb-4 text-sm text-muted-foreground">Create a service agreement for {client.first_name}.</p>
                                        <Button size="sm" asChild>
                                            <Link href={`/operations/service-agreements/create?client_id=${client.id}`}>New Agreement</Link>
                                        </Button>
                                    </CardContent>
                                </Card>
                            ) : (
                                agreements.map((ag: any) => {
                                    const budgetPct = ag.total_budget > 0 ? Math.round(((ag.budget_used ?? 0) / ag.total_budget) * 100) : 0;
                                    const budgetColor = budgetPct > 90 ? 'bg-red-500' : budgetPct > 70 ? 'bg-amber-500' : 'bg-emerald-500';
                                    return (
                                        <Card key={ag.id} className={`border-l-4 ${ag.status === 'active' ? 'border-l-emerald-500' : 'border-l-slate-300'}`}>
                                            <CardContent className="p-4">
                                                <div className="flex items-center justify-between">
                                                    <div>
                                                        <h3 className="font-medium text-sm">{ag.title}</h3>
                                                        <div className="flex items-center gap-2 mt-1">
                                                            <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium ${ag.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>{ag.status}</span>
                                                            {ag.funding_body && <span className="text-xs text-muted-foreground">{ag.funding_body}</span>}
                                                            {ag.starts_at && <span className="text-xs text-muted-foreground">{new Date(ag.starts_at).toLocaleDateString('en-NZ')} — {ag.ends_at ? new Date(ag.ends_at).toLocaleDateString('en-NZ') : 'Ongoing'}</span>}
                                                        </div>
                                                    </div>
                                                    <Button variant="outline" size="sm" asChild>
                                                        <Link href={`/operations/service-agreements/${ag.id}`}>View</Link>
                                                    </Button>
                                                </div>
                                                {ag.total_budget > 0 && (
                                                    <div className="mt-3">
                                                        <div className="flex items-center justify-between text-xs mb-1">
                                                            <span className="text-muted-foreground">Budget</span>
                                                            <span className="font-medium">${new Intl.NumberFormat('en-NZ').format(ag.budget_used ?? 0)} / ${new Intl.NumberFormat('en-NZ').format(ag.total_budget)}</span>
                                                        </div>
                                                        <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                                                            <div className={`h-full rounded-full ${budgetColor} transition-all`} style={{ width: `${Math.min(budgetPct, 100)}%` }} />
                                                        </div>
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>
                                    );
                                })
                            )}
                        </div>
                    );
                })()}

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
                            <div className="flex flex-wrap items-center gap-2 pt-2">
                                <div className="relative flex-1 min-w-[180px]">
                                    <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                                    <Input
                                        placeholder="Search events..."
                                        value={timelineSearch}
                                        onChange={(e) => setTimelineSearch(e.target.value)}
                                        className="h-8 pl-8 text-xs"
                                    />
                                </div>
                                <Select value={timelineTypeFilter} onValueChange={setTimelineTypeFilter}>
                                    <SelectTrigger className="h-8 w-[160px] text-xs">
                                        <SelectValue placeholder="All types" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All types</SelectItem>
                                        {eventTypes.map((t) => (
                                            <SelectItem key={t} value={t}>{t}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
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

                            {filteredEvents.map((e) => (
                                <div
                                    key={e.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="flex items-center gap-2 text-sm font-medium">
                                            {e.subject || e.type}
                                            {e.type && <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">{e.type}</span>}
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
                            {!filteredEvents.length && (
                                <div className="text-sm text-slate-500 text-center py-4">
                                    {events.length ? 'No events match your filters.' : 'No timeline events yet.'}
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

                {tab === 'documents' && (() => {
                    const grouped = documents.reduce((acc: Record<string, any[]>, d: any) => {
                        const cat = d.category || 'Uncategorised';
                        if (!acc[cat]) acc[cat] = [];
                        acc[cat].push(d);
                        return acc;
                    }, {} as Record<string, any[]>);
                    const categories = Object.keys(grouped).sort();

                    return (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between text-base">
                                <span>Documents</span>
                                <Button size="sm" asChild>
                                    <Link href={`/operations/clients/${client.id}/documents`}>Manage documents</Link>
                                </Button>
                            </CardTitle>
                            {categories.length > 1 && (
                                <p className="text-xs text-muted-foreground">Grouped by category. {categories.length} categories found.</p>
                            )}
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {categories.map((cat) => (
                                <div key={cat}>
                                    {categories.length > 1 && (
                                        <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{cat}</div>
                                    )}
                                    <div className="space-y-2">
                                        {grouped[cat].map((d: any) => {
                                            const isExpired = d.expires_at && new Date(d.expires_at) < new Date();
                                            const isExpiringSoon = d.expires_at && !isExpired && new Date(d.expires_at) < new Date(Date.now() + 30 * 86400000);
                                            return (
                                            <div
                                                key={d.id}
                                                className={`flex items-start justify-between gap-3 rounded-md border p-3 ${isExpired ? 'border-red-200 bg-red-50/50 dark:border-red-900/30 dark:bg-red-950/20' : isExpiringSoon ? 'border-amber-200 bg-amber-50/50 dark:border-amber-900/30 dark:bg-amber-950/20' : ''}`}
                                            >
                                                <div>
                                                    <div className="flex items-center gap-2 text-sm font-medium">
                                                        {d.title || d.original_name}
                                                        {d.portal_visible && (
                                                            <span className="rounded bg-blue-100 px-1.5 py-0.5 text-[10px] text-blue-700">Portal</span>
                                                        )}
                                                    </div>
                                                    <div className="mt-1 flex flex-wrap gap-2 text-xs text-slate-500">
                                                        {d.mime_type && <span>{d.mime_type}</span>}
                                                        {d.expires_at && (
                                                            <span className={isExpired ? 'text-red-600 font-medium' : isExpiringSoon ? 'text-amber-600 font-medium' : ''}>
                                                                {isExpired ? 'Expired' : 'Expires'}: {new Date(d.expires_at).toLocaleDateString('en-NZ')}
                                                            </span>
                                                        )}
                                                    </div>
                                                    {d.notes && (
                                                        <div className="mt-2 text-xs whitespace-pre-wrap text-slate-600">
                                                            {d.notes}
                                                        </div>
                                                    )}
                                                </div>
                                                <a
                                                    href={`/operations/clients/${client.id}/documents/${d.id}/download`}
                                                    className="shrink-0 rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                                >
                                                    Download
                                                </a>
                                            </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            ))}
                            {!documents.length && (
                                <div className="text-sm text-slate-500 text-center py-8">
                                    No documents uploaded.
                                </div>
                            )}
                        </CardContent>
                    </Card>
                    );
                })()}

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

                {tab === 'consents' && (() => {
                    const activeCount = consents.filter((c: any) => c.status === 'given' && !c.is_expired).length;
                    const expiredCount = consents.filter((c: any) => c.is_expired).length;
                    const expiringCount = consents.filter((c: any) => c.is_expiring_soon).length;

                    const STATUS_COLORS: Record<string, string> = {
                        given: 'bg-emerald-100 text-emerald-700',
                        refused: 'bg-red-100 text-red-700',
                        withdrawn: 'bg-slate-100 text-slate-600',
                        expired: 'bg-amber-100 text-amber-700',
                    };

                    return (
                        <div className="space-y-4">
                            {/* Stats */}
                            <div className="grid grid-cols-4 gap-3">
                                <div className="rounded-lg border p-3 text-center">
                                    <div className="text-lg font-bold text-indigo-600">{consents.length}</div>
                                    <div className="text-[10px] uppercase tracking-wide text-muted-foreground">Total</div>
                                </div>
                                <div className="rounded-lg border p-3 text-center">
                                    <div className="text-lg font-bold text-emerald-600">{activeCount}</div>
                                    <div className="text-[10px] uppercase tracking-wide text-muted-foreground">Active</div>
                                </div>
                                <div className="rounded-lg border p-3 text-center">
                                    <div className={`text-lg font-bold ${expiringCount > 0 ? 'text-amber-600' : 'text-slate-400'}`}>{expiringCount}</div>
                                    <div className="text-[10px] uppercase tracking-wide text-muted-foreground">Expiring</div>
                                </div>
                                <div className="rounded-lg border p-3 text-center">
                                    <div className={`text-lg font-bold ${expiredCount > 0 ? 'text-red-600' : 'text-slate-400'}`}>{expiredCount}</div>
                                    <div className="text-[10px] uppercase tracking-wide text-muted-foreground">Expired</div>
                                </div>
                            </div>

                            {/* Consent List */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center justify-between text-base">
                                        <span>Consent Records</span>
                                        <Button size="sm" asChild>
                                            <Link href={`/operations/clients/${client.id}/consents`}>Manage Consents</Link>
                                        </Button>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {consents.length === 0 ? (
                                        <p className="text-sm text-muted-foreground text-center py-8">No consent records. Record the first consent for {client.first_name}.</p>
                                    ) : (
                                        <div className="space-y-2">
                                            {consents.map((c: any) => {
                                                const displayStatus = c.is_expired ? 'expired' : c.status;
                                                return (
                                                    <div key={c.id} className="flex items-center justify-between rounded-lg border p-3">
                                                        <div>
                                                            <div className="flex items-center gap-2">
                                                                <span className="text-sm font-medium">{c.consent_type}</span>
                                                                <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium capitalize ${STATUS_COLORS[displayStatus] ?? 'bg-slate-100 text-slate-600'}`}>{displayStatus}</span>
                                                                {c.capacity_assessed && (
                                                                    <span className="rounded bg-purple-100 px-1.5 py-0.5 text-[10px] text-purple-700">Capacity Assessed</span>
                                                                )}
                                                            </div>
                                                            <div className="mt-0.5 flex gap-3 text-xs text-muted-foreground">
                                                                {c.given_at && <span>Given: {new Date(c.given_at).toLocaleDateString('en-NZ')}</span>}
                                                                {c.expires_at && <span className={c.is_expired ? 'text-red-600 font-medium' : c.is_expiring_soon ? 'text-amber-600 font-medium' : ''}>Expires: {new Date(c.expires_at).toLocaleDateString('en-NZ')}</span>}
                                                                {c.given_method && <span>Method: {c.given_method}</span>}
                                                            </div>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    );
                })()}

                {tab === 'portal' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between text-base">
                                <div className="flex items-center gap-2">
                                    <span>Portal access ({labels?.['client.singular'] ?? 'Client'} / Next of Kin)</span>
                                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600">{portal_users.length}</span>
                                </div>
                                {can.edit && (
                                    <Button size="sm" asChild>
                                        <Link href={`/operations/clients/${client.id}/portal-users`}>Quick Add</Link>
                                    </Button>
                                )}
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
                                        className="flex items-center justify-between rounded-md border p-3"
                                    >
                                        <div>
                                            <div className="flex items-center gap-2 text-sm font-medium">
                                                {u.name}
                                                {u.is_legal_guardian && (
                                                    <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700">Legal Guardian</span>
                                                )}
                                                {u.is_emergency_contact && (
                                                    <span className="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-medium text-red-700">Emergency</span>
                                                )}
                                            </div>
                                            <div className="text-xs text-slate-500">
                                                {u.email}
                                            </div>
                                            {u.relation && (
                                                <div className="mt-0.5 text-xs text-slate-500">
                                                    Relation: {u.relation}
                                                </div>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {u.status === 'active' || u.is_active !== false ? (
                                                <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700">Active</span>
                                            ) : (
                                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500">Inactive</span>
                                            )}
                                        </div>
                                    </div>
                                ))}
                                {!portal_users.length && (
                                    <div className="text-sm text-slate-500 text-center py-8">
                                        No portal users linked. Add a next of kin or family member to get started.
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {tab === 'assignments' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between text-base">
                                <div className="flex items-center gap-2">
                                    <span>Assigned Workers</span>
                                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600">{client.support_workers?.length ?? 0}</span>
                                </div>
                                {can.assign_workers && (
                                    <Button size="sm" asChild>
                                        <Link href={`/operations/clients/${client.id}/assignments`}>Manage Assignments</Link>
                                    </Button>
                                )}
                            </CardTitle>
                            <p className="text-xs text-muted-foreground">
                                Controls which staff can see and work with this {(labels?.['client.singular'] ?? 'Client').toLowerCase()}.
                            </p>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {(client.support_workers ?? []).length > 0 ? (
                                <div className="space-y-2">
                                    {client.support_workers.map((w) => (
                                        <div key={w.id} className="flex items-center justify-between rounded-md border p-3">
                                            <div className="flex items-center gap-3">
                                                <Avatar className="h-8 w-8">
                                                    <AvatarFallback className="text-xs">{getInitials(w.name)}</AvatarFallback>
                                                </Avatar>
                                                <div>
                                                    <div className="text-sm font-medium">{w.name}</div>
                                                    {w.email && <div className="text-xs text-muted-foreground">{w.email}</div>}
                                                </div>
                                            </div>
                                            {client.key_worker?.id === w.id && (
                                                <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-medium text-indigo-700">Key Worker</span>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="text-sm text-slate-500 text-center py-8">
                                    No workers assigned yet.
                                </div>
                            )}
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
    const [expandedId, setExpandedId] = useState<number | null>(null);
    const [showForm, setShowForm] = useState(false);

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
        setShowForm(true);
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
        setShowForm(false);
        form.reset();
    }

    const overdueCount = assessments.filter((a) => a.next_review_at && new Date(a.next_review_at) < new Date()).length;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center justify-between text-base">
                    <div className="flex items-center gap-2">
                        <span>Assessments</span>
                        {overdueCount > 0 && (
                            <span className="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-medium text-red-700">{overdueCount} overdue</span>
                        )}
                    </div>
                    {canEdit && !showForm && (
                        <Button size="sm" onClick={() => { resetForm(); setShowForm(true); }}>
                            New Assessment
                        </Button>
                    )}
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                {canEdit && showForm && (
                    <div className="rounded-md border p-3">
                        <div className="flex items-center justify-between gap-3">
                            <div className="text-sm font-medium">
                                {editingId
                                    ? 'Edit assessment'
                                    : 'Add assessment'}
                            </div>
                            <Button variant="ghost" size="sm" onClick={resetForm}>
                                Cancel
                            </Button>
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
                    {assessments.map((a) => {
                        const isOverdue = a.next_review_at && new Date(a.next_review_at) < new Date();
                        const isExpanded = expandedId === a.id;
                        return (
                        <div key={a.id} className={`rounded-md border p-3 ${isOverdue ? 'border-red-200 bg-red-50/50 dark:border-red-900/30 dark:bg-red-950/20' : ''}`}>
                            <div
                                className="flex items-start justify-between gap-3 cursor-pointer"
                                onClick={() => setExpandedId(isExpanded ? null : a.id)}
                            >
                                <div className="flex items-start gap-2">
                                    <div className="mt-0.5 text-muted-foreground">
                                        {isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                    </div>
                                    <div>
                                        <div className="flex items-center gap-2 text-sm font-medium">
                                            {a.type}
                                            {a.score && <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600">Score: {a.score}</span>}
                                            {isOverdue && (
                                                <span className="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-medium text-red-700">Review overdue</span>
                                            )}
                                        </div>
                                        <div className="mt-0.5 text-xs text-slate-500">
                                            {[
                                                a.assessed_at && `Assessed: ${new Date(a.assessed_at).toLocaleDateString('en-NZ')}`,
                                                a.next_review_at && `Next review: ${new Date(a.next_review_at).toLocaleDateString('en-NZ')}`,
                                            ].filter(Boolean).join(' · ') || '-'}
                                        </div>
                                    </div>
                                </div>

                                {canEdit && (
                                    <div className="flex shrink-0 items-center gap-2" onClick={(ev) => ev.stopPropagation()}>
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            onClick={() => startEdit(a)}
                                        >
                                            Edit
                                        </Button>
                                        <Button
                                            size="sm"
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
                            {isExpanded && a.notes && (
                                <div className="mt-2 ml-6 text-xs whitespace-pre-wrap text-slate-600 border-l-2 border-slate-200 pl-3">
                                    {a.notes}
                                </div>
                            )}
                        </div>
                        );
                    })}

                    {!assessments.length && (
                        <div className="text-sm text-slate-500 text-center py-8">
                            No assessments recorded.
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
