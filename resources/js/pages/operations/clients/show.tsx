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
import { TimelineInteractions } from '@/components/timeline-interactions';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Activity, BookOpen, Calendar, Camera, Car, CheckCircle2, ChevronDown, ChevronRight, Clock, ClipboardList, DollarSign, FileText, FolderOpen, Globe, GraduationCap, Heart, Home, Mail, MapPin, Pencil, Phone, Pill, Search, Shield, ShieldAlert, Star, Target, User, Users } from 'lucide-react';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
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
    photos: GalleryPhoto[];
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
    | 'calendar'
    | 'progress_notes'
    | 'service_agreements'
    | 'support_plan'
    | 'assessments'
    | 'timeline'
    | 'documents'
    | 'photos'
    | 'consents'
    | 'portal'
    | 'respite'
    | 'assignments';

type GalleryPhoto = {
    id: number;
    url: string;
    thumbnail_url?: string | null;
    caption?: string | null;
    tags?: string[] | null;
    visibility: string;
    status: string;
    original_name: string;
    uploaded_by?: string | null;
    created_at: string;
};

export default function ClientShow({
    client,
    medical,
    support_plan,
    assessments,
    documents,
    photos,
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

    const tabs: Array<{ key: TabKey; label: string; icon: typeof User; show: boolean; count?: number }> = useMemo(
        () => [
            { key: 'profile', label: 'Overview', icon: User, show: true },
            { key: 'onboarding', label: 'Onboarding', icon: CheckCircle2, show: client.status === 'onboarding' || !!onboarding?.workflow, count: onboarding?.total },
            { key: 'medical', label: 'Medical', icon: Heart, show: true },
            { key: 'mar', label: 'MAR', icon: Pill, show: true },
            { key: 'care_plans', label: 'Care Plans', icon: Target, show: true },
            { key: 'calendar', label: 'Calendar', icon: Calendar, show: true },
            { key: 'progress_notes', label: 'Progress Notes', icon: ClipboardList, show: true },
            { key: 'service_agreements', label: 'Agreements', icon: FileText, show: true },
            { key: 'assessments', label: 'Assessments', icon: BookOpen, show: true },
            { key: 'timeline', label: 'Timeline', icon: Activity, show: true },
            { key: 'documents', label: 'Documents', icon: FolderOpen, show: true, count: documents?.length },
            { key: 'photos', label: 'Photos', icon: Camera, show: true, count: photos?.length },
            { key: 'consents', label: 'Consents', icon: Shield, show: true },
            { key: 'portal', label: 'Family Portal', icon: Users, show: true },
            { key: 'respite', label: 'Respite', icon: Calendar, show: !!respiteCan?.viewAny },
            { key: 'assignments', label: 'Workers', icon: Users, show: can.assign_workers || can.edit },
        ],
        [can.assign_workers, can.edit, respiteCan?.viewAny, documents?.length, photos?.length, onboarding?.total],
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
    const [selectedEmotions, setSelectedEmotions] = useState<string[]>([]);
    const [showApptForm, setShowApptForm] = useState(false);
    const [apptData, setApptData] = useState({ title: '', appointment_type: 'gp_visit', starts_at: '', ends_at: '', location: '', provider_name: '', description: '', share_with_family: true });
    const [calendarEvent, setCalendarEvent] = useState<any>(null);

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
                {/* ── Hero Header ──────────────────────────────── */}
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 p-6 text-white md:p-8">
                    <div className="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-white/5" />
                    <div className="pointer-events-none absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-white/5" />
                    <div className="pointer-events-none absolute right-1/3 top-1/4 h-24 w-24 rounded-full bg-white/5" />

                    <div className="relative flex flex-col items-center gap-6 md:flex-row md:items-start">
                        {/* Avatar */}
                        <Avatar className="h-24 w-24 shrink-0 border-4 border-white/20 shadow-xl md:h-28 md:w-28">
                            <AvatarImage src={client.avatar ?? client.profile_photo_url ?? undefined} alt={name} />
                            <AvatarFallback className="bg-white/10 text-2xl font-bold text-white md:text-3xl">
                                {getInitials(name)}
                            </AvatarFallback>
                        </Avatar>

                        {/* Info */}
                        <div className="flex-1 text-center md:text-left">
                            <h1 className="text-2xl font-bold md:text-3xl">{name}</h1>
                            {client.preferred_name && client.preferred_name !== name && (
                                <p className="mt-0.5 text-sm text-white/60">Preferred: {client.preferred_name}</p>
                            )}
                            {client.nhi_number && (
                                <p className="mt-0.5 text-sm text-white/60">NHI: {client.nhi_number}</p>
                            )}

                            <div className="mt-3 flex flex-wrap items-center justify-center gap-2 md:justify-start">
                                <Badge className={client.status === 'active' ? 'bg-emerald-400/20 text-emerald-100 border-emerald-300/30' : client.status === 'onboarding' ? 'bg-amber-400/20 text-amber-100 border-amber-300/30' : 'bg-white/10 text-white/90 border-white/20'}>
                                    {client.status}
                                </Badge>
                                {client.funding_type && (
                                    <Badge className="bg-white/10 text-white/90 border-white/20">{client.funding_type}</Badge>
                                )}
                                {client.service_context && (
                                    <Badge className="bg-white/10 text-white/90 border-white/20">{client.service_context.name}</Badge>
                                )}
                                {client.site && (
                                    <Badge className="bg-white/10 text-white/90 border-white/20">
                                        <Home className="mr-1 h-3 w-3" />{client.site.name}
                                    </Badge>
                                )}
                                {client.risk_level && client.risk_level !== 'low' && (
                                    <Badge className={client.risk_level === 'critical' ? 'bg-red-400/20 text-red-100 border-red-300/30' : client.risk_level === 'high' ? 'bg-orange-400/20 text-orange-100 border-orange-300/30' : 'bg-yellow-400/20 text-yellow-100 border-yellow-300/30'}>
                                        <ShieldAlert className="mr-1 h-3 w-3" />{client.risk_level} risk
                                    </Badge>
                                )}
                                {client.safeguarding_flag && (
                                    <Badge className="bg-red-400/20 text-red-100 border-red-300/30">
                                        <Shield className="mr-1 h-3 w-3" />Safeguarding
                                    </Badge>
                                )}
                            </div>

                            {client.service_start_date && (
                                <p className="mt-2 flex items-center justify-center gap-1.5 text-sm text-white/60 md:justify-start">
                                    <Clock className="h-3.5 w-3.5" />
                                    Since {new Date(client.service_start_date).toLocaleDateString('en-NZ', { month: 'short', year: 'numeric' })}
                                </p>
                            )}
                        </div>

                        {/* Right: Actions + KPIs */}
                        <div className="flex flex-col items-center gap-3 md:items-end">
                            <div className="flex flex-wrap gap-2">
                                {client.phone && (
                                    <a href={`tel:${client.phone}`}>
                                        <Button size="sm" variant="outline" className="border-white/20 bg-white/10 text-white hover:bg-white/20">
                                            <Phone className="mr-1.5 h-3.5 w-3.5" />Call
                                        </Button>
                                    </a>
                                )}
                                <Button size="sm" variant="outline" className="border-white/20 bg-white/10 text-white hover:bg-white/20" asChild>
                                    <Link href={`/operations/clients/${client.id}/visit-requests`}>
                                        <Users className="mr-1.5 h-3.5 w-3.5" />Visits
                                        {(() => { const pc = (usePage().props as any).pending_visit_count; return pc > 0 ? <span className="ml-1 rounded-full bg-amber-400 px-1.5 py-0.5 text-[10px] font-bold text-amber-900">{pc}</span> : null; })()}
                                    </Link>
                                </Button>
                                {can.edit && (
                                    <Button size="sm" variant="outline" className="border-white/20 bg-white/10 text-white hover:bg-white/20" asChild>
                                        <Link href={`/operations/clients/${client.id}/edit`}>
                                            <Pencil className="mr-1.5 h-3.5 w-3.5" />Edit
                                        </Link>
                                    </Button>
                                )}
                            </div>

                            {/* KPI Stats */}
                            <div className="hidden gap-6 text-center md:flex">
                                <div>
                                    <p className="text-2xl font-bold">{(() => { const summary = (pageProps as any).care_plans_summary ?? {}; return summary.active_plan ? 'Active' : '—'; })()}</p>
                                    <p className="text-xs text-white/50">Care Plan</p>
                                </div>
                                <div>
                                    <p className="text-2xl font-bold">{(() => { const summary = (pageProps as any).care_plans_summary ?? {}; const goals = summary.active_plan?.goals ?? []; const done = goals.filter((g: any) => g.status === 'completed').length; return goals.length > 0 ? `${done}/${goals.length}` : '—'; })()}</p>
                                    <p className="text-xs text-white/50">Goals</p>
                                </div>
                                <div>
                                    <p className="text-2xl font-bold">{shifts_summary?.next ? 'Yes' : '—'}</p>
                                    <p className="text-xs text-white/50">Next Shift</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Hidden photo upload form */}
                    {can.edit && (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (!photoForm.data.photo) return;
                                photoForm.post(`/operations/clients/${client.id}/photo`, { forceFormData: true, preserveScroll: true });
                            }}
                            className="hidden"
                        >
                            <Input type="file" accept="image/*" id="client-photo" onChange={(e) => photoForm.setData('photo', e.target.files?.[0] ?? null)} />
                        </form>
                    )}
                </div>

                <div className="-mx-4 mt-4 overflow-x-auto border-b px-4">
                    <div className="flex w-max items-center gap-1 pb-0">
                        {tabs
                            .filter((t) => t.show)
                            .map((t) => {
                                const Icon = t.icon;
                                const isActive = tab === t.key;
                                return (
                                    <button
                                        key={t.key}
                                        onClick={() => {
                                            if (t.key === 'mar') {
                                                window.location.href = `/operations/clients/${client.id}/mar`;
                                                return;
                                            }
                                            if (t.key === 'calendar') {
                                                window.location.href = `/operations/clients/${client.id}/calendar`;
                                                return;
                                            }
                                            setTab(t.key);
                                        }}
                                        className={`inline-flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors ${
                                            isActive
                                                ? 'border-primary text-primary'
                                                : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground'
                                        }`}
                                    >
                                        <Icon className="h-3.5 w-3.5" />
                                        {t.label}
                                        {t.count != null && t.count > 0 && (
                                            <span className={`ml-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold leading-none ${
                                                isActive ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'
                                            }`}>
                                                {t.count}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
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

                                {/* Risk Level — clickable dropdown */}
                                <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-4">
                                    <p className="text-[10px] font-semibold uppercase tracking-wider text-violet-500">Risk Level</p>
                                    <div className="mt-1">
                                        <Select
                                            value={client.risk_level ?? ''}
                                            onValueChange={(v) => router.patch(`/operations/clients/${client.id}/quick-update`, { risk_level: v }, { preserveScroll: true })}
                                        >
                                            <SelectTrigger className={`h-8 w-full border-0 text-sm font-bold shadow-none ${
                                                client.risk_level === 'critical' ? 'bg-red-100 text-red-700' :
                                                client.risk_level === 'high' ? 'bg-red-100 text-red-700' :
                                                client.risk_level === 'medium' ? 'bg-amber-100 text-amber-700' :
                                                client.risk_level === 'low' ? 'bg-emerald-100 text-emerald-700' :
                                                'bg-slate-100 text-slate-500'
                                            } rounded-full px-3`}>
                                                <SelectValue placeholder="Set level..." />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="low">Low</SelectItem>
                                                <SelectItem value="medium">Medium</SelectItem>
                                                <SelectItem value="high">High</SelectItem>
                                                <SelectItem value="critical">Critical</SelectItem>
                                            </SelectContent>
                                        </Select>
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
                                    {/* Quick Contact */}
                                    <Card>
                                        <CardContent className="space-y-2 p-4 text-xs">
                                            {client.date_of_birth && <div className="flex justify-between"><span className="text-muted-foreground">DOB</span><span>{new Date(client.date_of_birth).toLocaleDateString('en-NZ')}{(() => { const b = new Date(client.date_of_birth!); const age = Math.floor((Date.now() - b.getTime()) / 31557600000); return ` (${age}y)`; })()}</span></div>}
                                            {client.phone && <div className="flex justify-between"><span className="text-muted-foreground">Phone</span><a href={`tel:${client.phone}`} className="text-primary hover:underline">{client.phone}</a></div>}
                                            {client.email && <div className="flex justify-between"><span className="text-muted-foreground">Email</span><a href={`mailto:${client.email}`} className="truncate ml-2 text-primary hover:underline">{client.email}</a></div>}
                                            {client.city && <div className="flex justify-between"><span className="text-muted-foreground">Location</span><span>{client.suburb ? `${client.suburb}, ` : ''}{client.city}</span></div>}
                                            {client.key_worker && <div className="flex justify-between"><span className="text-muted-foreground">Key Worker</span><span>{client.key_worker.name}</span></div>}
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
                        {/* Allergy Alert */}
                        {medical.profile?.allergies && medical.profile.allergies !== '-' && (
                            <div className="flex items-center gap-3 rounded-xl border-2 border-red-300 bg-red-50 p-4">
                                <ShieldAlert className="h-6 w-6 shrink-0 text-red-600" />
                                <div>
                                    <p className="text-sm font-bold text-red-800">Allergies</p>
                                    <p className="text-sm text-red-700">{medical.profile.allergies}</p>
                                </div>
                            </div>
                        )}

                        {/* Quick Stats */}
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                                <div className="text-xl font-bold text-violet-700">{medical.medications?.length ?? 0}</div>
                                <div className="text-[10px] uppercase tracking-wider text-violet-500">Medications</div>
                            </div>
                            <div className="rounded-xl border bg-gradient-to-br from-amber-50 to-yellow-50 p-3 text-center">
                                <div className="text-xl font-bold text-amber-700">{medical.conditions?.length ?? 0}</div>
                                <div className="text-[10px] uppercase tracking-wider text-amber-500">Conditions</div>
                            </div>
                            <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-cyan-50 p-3 text-center">
                                <div className="text-xl font-bold text-blue-700">{medical.emergency_contacts?.length ?? 0}</div>
                                <div className="text-[10px] uppercase tracking-wider text-blue-500">Emergency Contacts</div>
                            </div>
                            <div className="rounded-xl border bg-gradient-to-br from-cyan-50 to-teal-50 p-3 text-center">
                                <Button variant="outline" size="sm" className="gap-1.5 text-xs" asChild>
                                    <Link href="/emar"><Pill className="h-3.5 w-3.5" /> Open eMAR</Link>
                                </Button>
                            </div>
                        </div>

                        {/* Main Grid */}
                        <div className="grid gap-4 lg:grid-cols-3">
                            {/* Left Column — Profile + Medications */}
                            <div className="space-y-4 lg:col-span-2">
                                {/* GP Card */}
                                {(medical.profile?.gp_name || medical.profile?.gp_practice) && (
                                    <Card className="border-emerald-200 bg-emerald-50/30">
                                        <CardContent className="p-4">
                                            <div className="flex items-center gap-2 mb-2">
                                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600">
                                                    <Heart className="h-4 w-4" />
                                                </div>
                                                <span className="text-sm font-semibold">GP / Primary Care</span>
                                            </div>
                                            <div className="grid gap-2 sm:grid-cols-3 text-sm">
                                                {medical.profile.gp_name && <div><p className="text-[10px] uppercase text-muted-foreground">Doctor</p><p className="font-medium">{medical.profile.gp_name}</p></div>}
                                                {medical.profile.gp_practice && <div><p className="text-[10px] uppercase text-muted-foreground">Practice</p><p className="font-medium">{medical.profile.gp_practice}</p></div>}
                                                {medical.profile.gp_phone && <div><p className="text-[10px] uppercase text-muted-foreground">Phone</p><p className="font-medium">{medical.profile.gp_phone}</p></div>}
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}

                                {/* Medical Profile */}
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center justify-between text-base">
                                            <div className="flex items-center gap-2">
                                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-red-100 text-red-600">
                                                    <FileText className="h-4 w-4" />
                                                </div>
                                                Medical Profile
                                            </div>
                                            {can.edit && <Button variant="outline" size="sm" asChild><Link href={`/operations/clients/${client.id}/medical`}>Edit</Link></Button>}
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            {[
                                                { label: 'Medical History', value: medical.profile?.medical_history },
                                                { label: 'Disabilities', value: medical.profile?.disabilities },
                                                { label: 'Blood Type', value: medical.profile?.blood_type },
                                                { label: 'Hospital Preference', value: medical.profile?.hospital_preference },
                                            ].filter(f => f.value && f.value !== '-').map(f => (
                                                <div key={f.label} className="rounded-lg bg-slate-50 p-3">
                                                    <p className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">{f.label}</p>
                                                    <p className="mt-1 text-sm">{f.value}</p>
                                                </div>
                                            ))}
                                        </div>
                                        {medical.profile?.notes && medical.profile.notes !== '-' && (
                                            <div className="mt-3 rounded-lg bg-slate-50 p-3">
                                                <p className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Notes</p>
                                                <p className="mt-1 text-sm whitespace-pre-wrap">{medical.profile.notes}</p>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>

                                {/* Medications */}
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center justify-between text-base">
                                            <div className="flex items-center gap-2">
                                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-violet-100 text-violet-600">
                                                    <Pill className="h-4 w-4" />
                                                </div>
                                                Medications
                                                <Badge variant="secondary" className="text-[10px]">{medical.medications?.length ?? 0}</Badge>
                                            </div>
                                            {can.edit && <Button variant="outline" size="sm" asChild><Link href={`/operations/clients/${client.id}/medical?section=medications`}>Manage</Link></Button>}
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {(medical.medications ?? []).length === 0 ? (
                                            <p className="py-4 text-center text-sm text-muted-foreground">No medications listed.</p>
                                        ) : (
                                            <div className="space-y-2">
                                                {medical.medications.map((m: any) => (
                                                    <div key={m.id} className="flex items-start gap-3 rounded-xl border-l-4 border-l-violet-400 bg-white p-3 shadow-sm">
                                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-100">
                                                            <Pill className="h-4 w-4 text-violet-600" />
                                                        </div>
                                                        <div className="flex-1">
                                                            <div className="flex items-center gap-2">
                                                                <span className="text-sm font-semibold">{m.name}</span>
                                                                {m.is_controlled && <Badge className="border-0 bg-red-100 text-red-700 text-[9px]">Controlled</Badge>}
                                                                {m.is_prn && <Badge className="border-0 bg-amber-100 text-amber-700 text-[9px]">PRN</Badge>}
                                                            </div>
                                                            <div className="mt-0.5 flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                                                                {m.dosage && <span>{m.dosage}</span>}
                                                                {m.frequency && <span>{m.frequency}</span>}
                                                                {m.route && <span>{m.route}</span>}
                                                            </div>
                                                            {m.instructions && <p className="mt-1 text-xs text-slate-600">{m.instructions}</p>}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>

                            {/* Right Column — Conditions + Emergency Contacts */}
                            <div className="space-y-4">
                                {/* Conditions */}
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="flex items-center justify-between text-sm font-semibold">
                                            <div className="flex items-center gap-2">
                                                <div className="flex h-6 w-6 items-center justify-center rounded-md bg-amber-100 text-amber-600">
                                                    <ShieldAlert className="h-3.5 w-3.5" />
                                                </div>
                                                Conditions
                                            </div>
                                            {can.edit && <Button variant="ghost" size="sm" className="h-6 text-xs" asChild><Link href={`/operations/clients/${client.id}/medical?section=conditions`}>Manage</Link></Button>}
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {(medical.conditions ?? []).length === 0 ? (
                                            <p className="py-4 text-center text-xs text-muted-foreground">No conditions listed.</p>
                                        ) : (
                                            <div className="space-y-2">
                                                {medical.conditions.map((c: any) => (
                                                    <div key={c.id} className="rounded-lg border p-2.5">
                                                        <div className="flex items-center justify-between">
                                                            <span className="text-xs font-medium">{c.label}</span>
                                                            {c.severity && (
                                                                <Badge className={`border-0 text-[9px] ${
                                                                    c.severity === 'severe' ? 'bg-red-100 text-red-700' :
                                                                    c.severity === 'moderate' ? 'bg-amber-100 text-amber-700' :
                                                                    'bg-emerald-100 text-emerald-700'
                                                                }`}>{c.severity}</Badge>
                                                            )}
                                                        </div>
                                                        {c.notes && <p className="mt-1 text-[11px] text-muted-foreground">{c.notes}</p>}
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>

                                {/* Emergency Contacts */}
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="flex items-center justify-between text-sm font-semibold">
                                            <div className="flex items-center gap-2">
                                                <div className="flex h-6 w-6 items-center justify-center rounded-md bg-blue-100 text-blue-600">
                                                    <Heart className="h-3.5 w-3.5" />
                                                </div>
                                                Emergency Contacts
                                            </div>
                                            {can.edit && <Button variant="ghost" size="sm" className="h-6 text-xs" asChild><Link href={`/operations/clients/${client.id}/medical?section=emergency_contacts`}>Manage</Link></Button>}
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {(medical.emergency_contacts ?? []).length === 0 ? (
                                            <p className="py-4 text-center text-xs text-muted-foreground">No emergency contacts listed.</p>
                                        ) : (
                                            <div className="space-y-2">
                                                {medical.emergency_contacts.map((e: any) => (
                                                    <div key={e.id} className="flex items-start gap-2.5 rounded-lg border p-2.5">
                                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700">
                                                            {(e.name ?? '?').charAt(0)}
                                                        </div>
                                                        <div className="flex-1 text-xs">
                                                            <div className="flex items-center gap-1.5">
                                                                <span className="font-medium">{e.name}</span>
                                                                {e.relationship && <Badge variant="outline" className="h-4 px-1 text-[9px]">{e.relationship}</Badge>}
                                                            </div>
                                                            {e.phone && <p className="mt-0.5 text-muted-foreground">{e.phone}</p>}
                                                            {e.email && <p className="text-muted-foreground">{e.email}</p>}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </div>
                )}

                {tab === 'care_plans' && (() => {
                    const pageProps = usePage().props as any;
                    const summary = pageProps.care_plans_summary ?? {};
                    const activePlan = summary.active_plan;
                    const recentNotes = summary.recent_notes ?? [];
                    const reviewDue = summary.review_due ?? false;
                    const goals = activePlan?.goals ?? [];
                    const goalsCompleted = goals.filter((g: any) => g.status === 'completed').length;
                    const goalsInProgress = goals.filter((g: any) => g.status === 'in_progress').length;
                    const goalsPct = goals.length > 0 ? Math.round((goalsCompleted / goals.length) * 100) : 0;
                    const avgProgress = goals.length > 0 ? Math.round(goals.reduce((s: number, g: any) => s + (g.progress_percentage ?? 0), 0) / goals.length) : 0;

                    // Build sparkline data from goal progress values (simulates progress over time)
                    const sparklineData = goals.length > 0
                        ? goals.map((g: any) => g.progress_percentage ?? 0).sort((a: number, b: number) => a - b)
                        : [0, 0, 0];

                    const content = activePlan?.content ? (typeof activePlan.content === 'string' ? JSON.parse(activePlan.content || '{}') : activePlan.content) : {};
                    const aboutMe = content.about_me ?? {};
                    const hasAboutMe = Object.values(aboutMe).some((v: any) => v && String(v).trim());
                    const reviewDays = activePlan?.next_review_at ? Math.ceil((new Date(activePlan.next_review_at).getTime() - Date.now()) / 86400000) : null;

                    return (
                        <div className="space-y-4">
                            {/* Review Due Alert */}
                            {reviewDue && activePlan && (
                                <div className="flex items-center gap-3 rounded-xl border-2 border-amber-300 bg-gradient-to-r from-amber-50 to-orange-50 p-4">
                                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-600">
                                        <ShieldAlert className="h-5 w-5" />
                                    </div>
                                    <div className="flex-1">
                                        <p className="text-sm font-semibold text-amber-800">Care Plan Review Overdue</p>
                                        <p className="text-xs text-amber-700">This plan is due for review. Please update goals and strategies.</p>
                                    </div>
                                    <Button size="sm" className="bg-amber-600 hover:bg-amber-700 text-white" asChild>
                                        <Link href={`/operations/care-plans/${activePlan.id}`}>Start Review</Link>
                                    </Button>
                                </div>
                            )}

                            {activePlan ? (
                                <>
                                    {/* Quick Stats */}
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                        <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                                            <div className="text-2xl font-bold text-violet-700">{goalsPct}%</div>
                                            <div className="text-[10px] uppercase tracking-wider text-violet-500">Overall Progress</div>
                                        </div>
                                        <div className="rounded-xl border bg-gradient-to-br from-emerald-50 to-green-50 p-3 text-center">
                                            <div className="text-2xl font-bold text-emerald-700">{goalsCompleted}/{goals.length}</div>
                                            <div className="text-[10px] uppercase tracking-wider text-emerald-500">Goals Completed</div>
                                        </div>
                                        <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-indigo-50 p-3 text-center">
                                            <div className="text-2xl font-bold text-blue-700">{goalsInProgress}</div>
                                            <div className="text-[10px] uppercase tracking-wider text-blue-500">In Progress</div>
                                        </div>
                                        <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                                            <div className={`text-2xl font-bold ${reviewDays !== null && reviewDays < 0 ? 'text-red-600' : 'text-violet-700'}`}>
                                                {reviewDays !== null ? (reviewDays < 0 ? `${Math.abs(reviewDays)}d` : `${reviewDays}d`) : '—'}
                                            </div>
                                            <div className="text-[10px] uppercase tracking-wider text-violet-500">{reviewDays !== null && reviewDays < 0 ? 'Overdue' : 'Until Review'}</div>
                                        </div>
                                    </div>

                                    {/* Main Grid */}
                                    <div className="grid gap-4 lg:grid-cols-3">
                                        {/* Left: Goals + Progress Chart */}
                                        <div className="space-y-4 lg:col-span-2">
                                            {/* Progress Stream Card */}
                                            <Card className="overflow-hidden">
                                                <div className="bg-gradient-to-r from-violet-500 to-purple-600 px-5 py-3">
                                                    <div className="flex items-center justify-between">
                                                        <div>
                                                            <h3 className="text-sm font-semibold text-white">{activePlan.title}</h3>
                                                            <p className="text-xs text-violet-200">{(activePlan.plan_type ?? '').replace(/_/g, ' ')} · Version {activePlan.version ?? 1}</p>
                                                        </div>
                                                        <Button size="sm" className="bg-white text-violet-700 font-semibold hover:bg-violet-100 shadow-sm" asChild>
                                                            <Link href={`/operations/care-plans/${activePlan.id}`}>View Full Plan</Link>
                                                        </Button>
                                                    </div>
                                                </div>
                                                <CardContent className="p-5">
                                                    {/* Goal Progress Bar Chart */}
                                                    <div className="mb-4">
                                                        <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Goal Progress</p>
                                                        <div className="space-y-3">
                                                            {[...goals].sort((a: any, b: any) => (b.progress_percentage ?? 0) - (a.progress_percentage ?? 0)).map((g: any) => (
                                                                <div key={g.id}>
                                                                    <div className="mb-1 flex items-center justify-between">
                                                                        <span className="text-xs font-medium truncate max-w-[70%]">{g.title}</span>
                                                                        <span className={`text-xs font-bold tabular-nums ${g.status === 'completed' ? 'text-emerald-600' : 'text-violet-600'}`}>{g.progress_percentage ?? 0}%</span>
                                                                    </div>
                                                                    <div className="relative h-3 w-full overflow-hidden rounded-full bg-slate-100">
                                                                        <div
                                                                            className={`h-full rounded-full transition-all ${g.status === 'completed' ? 'bg-gradient-to-r from-emerald-400 to-emerald-500' : 'bg-gradient-to-r from-violet-400 to-purple-500'}`}
                                                                            style={{ width: `${g.progress_percentage ?? 0}%` }}
                                                                        />
                                                                    </div>
                                                                </div>
                                                            ))}
                                                        </div>
                                                        {/* Summary row */}
                                                        <div className="mt-4 flex items-center justify-between rounded-xl bg-violet-50 px-4 py-3">
                                                            <div className="flex items-center gap-4">
                                                                <span className="flex items-center gap-1.5 text-xs"><span className="h-3 w-3 rounded-full bg-gradient-to-r from-emerald-400 to-emerald-500" /> Completed: {goalsCompleted}</span>
                                                                <span className="flex items-center gap-1.5 text-xs"><span className="h-3 w-3 rounded-full bg-gradient-to-r from-violet-400 to-purple-500" /> In Progress: {goalsInProgress}</span>
                                                                <span className="flex items-center gap-1.5 text-xs"><span className="h-3 w-3 rounded-full bg-slate-300" /> Not Started: {goals.length - goalsCompleted - goalsInProgress}</span>
                                                            </div>
                                                            <span className="text-xs font-bold text-violet-700">Avg: {avgProgress}%</span>
                                                        </div>
                                                    </div>

                                                    {/* Removed duplicate goal bars — already shown in gradient bars above */}
                                                    <div className="hidden">
                                                    </div>
                                                </CardContent>
                                            </Card>

                                            {/* About Me */}
                                            {hasAboutMe && (
                                                <Card className="overflow-hidden border-violet-200">
                                                    <div className="bg-gradient-to-r from-rose-400 to-pink-500 px-5 py-2.5">
                                                        <h3 className="text-sm font-semibold text-white">About {client.first_name}</h3>
                                                    </div>
                                                    <CardContent className="space-y-3 p-4">
                                                        {aboutMe.dreams && (
                                                            <div className="rounded-lg bg-violet-50 p-3">
                                                                <p className="text-[10px] font-bold uppercase tracking-wider text-violet-500">Dreams & Aspirations</p>
                                                                <p className="mt-1 text-sm">{aboutMe.dreams}</p>
                                                            </div>
                                                        )}
                                                        <div className="grid gap-3 sm:grid-cols-2">
                                                            {aboutMe.likes && (
                                                                <div className="rounded-lg bg-emerald-50 p-3">
                                                                    <p className="text-[10px] font-bold uppercase tracking-wider text-emerald-600">Things I Like</p>
                                                                    <p className="mt-1 text-sm">{aboutMe.likes}</p>
                                                                </div>
                                                            )}
                                                            {aboutMe.dislikes && (
                                                                <div className="rounded-lg bg-red-50 p-3">
                                                                    <p className="text-[10px] font-bold uppercase tracking-wider text-red-500">Things I Don{"'"}t Like</p>
                                                                    <p className="mt-1 text-sm">{aboutMe.dislikes}</p>
                                                                </div>
                                                            )}
                                                        </div>
                                                        {aboutMe.how_to_support && (
                                                            <div className="rounded-lg border border-violet-200 bg-white p-3">
                                                                <p className="text-[10px] font-bold uppercase tracking-wider text-violet-500">How to Support Me</p>
                                                                <p className="mt-1 text-sm">{aboutMe.how_to_support}</p>
                                                            </div>
                                                        )}
                                                    </CardContent>
                                                </Card>
                                            )}
                                        </div>

                                        {/* Right: Notes + Plan Info */}
                                        <div className="space-y-4">
                                            {/* Plan Info */}
                                            <Card>
                                                <CardHeader className="pb-2">
                                                    <CardTitle className="text-sm font-semibold">Plan Details</CardTitle>
                                                </CardHeader>
                                                <CardContent className="space-y-2 text-xs">
                                                    <div className="flex justify-between"><span className="text-muted-foreground">Status</span><Badge className="border-0 bg-emerald-100 text-emerald-700 text-[10px]">Active</Badge></div>
                                                    <div className="flex justify-between"><span className="text-muted-foreground">Type</span><span className="capitalize">{(activePlan.plan_type ?? '').replace(/_/g, ' ')}</span></div>
                                                    {activePlan.starts_at && <div className="flex justify-between"><span className="text-muted-foreground">Started</span><span>{new Date(activePlan.starts_at).toLocaleDateString('en-NZ')}</span></div>}
                                                    {activePlan.next_review_at && <div className="flex justify-between"><span className="text-muted-foreground">Next Review</span><span className={reviewDue ? 'font-semibold text-red-600' : ''}>{new Date(activePlan.next_review_at).toLocaleDateString('en-NZ')}</span></div>}
                                                    <div className="flex justify-between"><span className="text-muted-foreground">Total Plans</span><span>{summary.total_plans ?? 0}</span></div>
                                                    <div className="pt-2">
                                                        <Button variant="outline" size="sm" className="w-full text-xs" asChild>
                                                            <Link href={`/operations/care-plans?client_id=${client.id}`}>View All Plans</Link>
                                                        </Button>
                                                    </div>
                                                </CardContent>
                                            </Card>

                                            {/* Recent Notes */}
                                            <Card>
                                                <CardHeader className="pb-2">
                                                    <CardTitle className="flex items-center justify-between text-sm font-semibold">
                                                        <span>Recent Notes</span>
                                                        <Button variant="ghost" size="sm" className="h-6 text-[10px] text-violet-600" asChild>
                                                            <Link href={`/operations/progress-notes?client_id=${client.id}`}>View All</Link>
                                                        </Button>
                                                    </CardTitle>
                                                </CardHeader>
                                                <CardContent>
                                                    {recentNotes.length === 0 ? (
                                                        <p className="py-4 text-center text-xs text-muted-foreground">No notes yet.</p>
                                                    ) : (
                                                        <div className="space-y-2">
                                                            {recentNotes.slice(0, 4).map((note: any) => (
                                                                <div key={note.id} className={`rounded-lg border p-2.5 text-xs ${note.is_flagged ? 'border-l-4 border-l-red-400' : ''}`}>
                                                                    <div className="flex items-center justify-between">
                                                                        <span className="font-medium">{note.author?.name ?? 'Unknown'}</span>
                                                                        <span className="text-[10px] text-muted-foreground">{new Date(note.created_at).toLocaleDateString('en-NZ')}</span>
                                                                    </div>
                                                                    {note.goal && <span className="mt-0.5 inline-block rounded bg-violet-50 px-1 py-0.5 text-[9px] text-violet-600">{note.goal.title}</span>}
                                                                    <p className="mt-0.5 text-muted-foreground line-clamp-2">{note.content}</p>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    )}
                                                </CardContent>
                                            </Card>
                                        </div>
                                    </div>
                                </>
                            ) : (
                                <Card className="border-dashed">
                                    <CardContent className="flex flex-col items-center justify-center py-16">
                                        <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-violet-50">
                                            <Heart className="h-8 w-8 text-violet-400" />
                                        </div>
                                        <p className="font-medium">No Active Care Plan</p>
                                        <p className="mt-1 max-w-sm text-center text-sm text-muted-foreground">Create a care plan to start tracking goals and progress for {client.first_name}.</p>
                                        <Button size="sm" className="mt-4 bg-violet-600 hover:bg-violet-700" asChild>
                                            <Link href={`/operations/care-plans/create?client_id=${client.id}`}>Create Care Plan</Link>
                                        </Button>
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    );
                })()}

                {/* Calendar tab navigates to dedicated page */}

                {false && (() => {
                    const apptTypes = [
                        { value: 'gp_visit', label: 'GP Visit' },
                        { value: 'specialist', label: 'Specialist' },
                        { value: 'therapy', label: 'Therapy' },
                        { value: 'activity', label: 'Activity' },
                        { value: 'reminder', label: 'Reminder' },
                        { value: 'other', label: 'Other' },
                    ];

                    const submitAppointment = () => {
                        if (!apptData.title || !apptData.starts_at) return;
                        fetch(`/clients/${client.id}/calendar/appointments`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as any)?.content ?? '' },
                            body: JSON.stringify(apptData),
                        }).then(r => r.json()).then(() => {
                            setShowApptForm(false);
                            setApptData({ title: '', appointment_type: 'gp_visit', starts_at: '', ends_at: '', location: '', provider_name: '', description: '', share_with_family: true });
                            router.reload({ only: ['events'] });
                        });
                    };

                    return (
                        <div className="space-y-4">
                            <div className="flex items-center justify-between">
                                <div className="flex flex-wrap gap-3 text-xs">
                                    <div className="flex items-center gap-1.5"><div className="h-3 w-3 rounded-full bg-blue-500" />Shifts</div>
                                    <div className="flex items-center gap-1.5"><div className="h-3 w-3 rounded-full bg-green-500" />Family Visits</div>
                                    <div className="flex items-center gap-1.5"><div className="h-3 w-3 rounded-full bg-amber-500" />GP Visits</div>
                                    <div className="flex items-center gap-1.5"><div className="h-3 w-3 rounded-full bg-purple-500" />Specialist</div>
                                    <div className="flex items-center gap-1.5"><div className="h-3 w-3 rounded-full bg-pink-500" />Therapy</div>
                                    <div className="flex items-center gap-1.5"><div className="h-3 w-3 rounded-full bg-cyan-500" />Activities</div>
                                </div>
                                <Button size="sm" className="gap-1.5" onClick={() => setShowApptForm(!showApptForm)}>
                                    <Calendar className="h-3.5 w-3.5" />{showApptForm ? 'Cancel' : 'Schedule Appointment'}
                                </Button>
                            </div>

                            {/* Appointment Form */}
                            {showApptForm && (
                                <Card className="border-primary/20">
                                    <CardContent className="p-4 space-y-3">
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <div className="space-y-1">
                                                <Label className="text-xs">Title *</Label>
                                                <Input className="h-8 text-xs" placeholder="GP Visit - Dr. Patel" value={apptData.title} onChange={e => setApptData({ ...apptData, title: e.target.value })} />
                                            </div>
                                            <div className="space-y-1">
                                                <Label className="text-xs">Type</Label>
                                                <Select value={apptData.appointment_type} onValueChange={v => setApptData({ ...apptData, appointment_type: v })}>
                                                    <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        {apptTypes.map(t => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="space-y-1">
                                                <Label className="text-xs">Provider</Label>
                                                <Input className="h-8 text-xs" placeholder="Dr. Patel" value={apptData.provider_name} onChange={e => setApptData({ ...apptData, provider_name: e.target.value })} />
                                            </div>
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <div className="space-y-1">
                                                <Label className="text-xs">Start *</Label>
                                                <Input type="datetime-local" className="h-8 text-xs" value={apptData.starts_at} onChange={e => setApptData({ ...apptData, starts_at: e.target.value })} />
                                            </div>
                                            <div className="space-y-1">
                                                <Label className="text-xs">End</Label>
                                                <Input type="datetime-local" className="h-8 text-xs" value={apptData.ends_at} onChange={e => setApptData({ ...apptData, ends_at: e.target.value })} />
                                            </div>
                                            <div className="space-y-1">
                                                <Label className="text-xs">Location</Label>
                                                <Input className="h-8 text-xs" placeholder="Riverside Medical Centre" value={apptData.location} onChange={e => setApptData({ ...apptData, location: e.target.value })} />
                                            </div>
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="text-xs">Notes</Label>
                                            <Textarea className="min-h-[60px] text-xs" value={apptData.description} onChange={e => setApptData({ ...apptData, description: e.target.value })} />
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <label className="flex items-center gap-2 text-xs">
                                                <Checkbox checked={apptData.share_with_family} onCheckedChange={v => setApptData({ ...apptData, share_with_family: !!v })} />
                                                Share with family
                                            </label>
                                            <Button size="sm" onClick={submitAppointment} disabled={!apptData.title || !apptData.starts_at}>
                                                Save Appointment
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Calendar */}
                            <Card>
                                <CardContent className="p-2 md:p-4">
                                    <FullCalendar
                                        plugins={[dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin]}
                                        initialView="dayGridMonth"
                                        headerToolbar={{
                                            left: 'prev,next today',
                                            center: 'title',
                                            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
                                        }}
                                        events={(info, successCallback) => {
                                            fetch(`/clients/${client.id}/calendar/events?start=${info.startStr}&end=${info.endStr}`)
                                                .then(r => r.json())
                                                .then(data => successCallback(data))
                                                .catch(() => successCallback([]));
                                        }}
                                        selectable={true}
                                        select={(info) => {
                                            setApptData({
                                                ...apptData,
                                                starts_at: info.start.toISOString().slice(0, 16),
                                                ends_at: info.end ? info.end.toISOString().slice(0, 16) : '',
                                            });
                                            setShowApptForm(true);
                                        }}
                                        eventClick={(info) => {
                                            setCalendarEvent({
                                                title: info.event.title,
                                                start: info.event.start,
                                                end: info.event.end,
                                                ...info.event.extendedProps,
                                            });
                                        }}
                                        height="auto"
                                        nowIndicator={true}
                                        eventTimeFormat={{ hour: '2-digit', minute: '2-digit', hour12: false }}
                                    />
                                </CardContent>
                            </Card>

                            {/* Event Detail Panel */}
                            {calendarEvent && (
                                <Card className="border-primary/20">
                                    <CardContent className="p-4">
                                        <div className="flex items-start justify-between">
                                            <div>
                                                <h3 className="text-sm font-semibold">{calendarEvent.title}</h3>
                                                <p className="mt-1 text-xs text-muted-foreground capitalize">{calendarEvent.type?.replace('_', ' ')} {calendarEvent.appointment_type ? `— ${calendarEvent.appointment_type.replace('_', ' ')}` : ''}</p>
                                                {calendarEvent.start && (
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {new Date(calendarEvent.start).toLocaleString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                                        {calendarEvent.end && ` — ${new Date(calendarEvent.end).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })}`}
                                                    </p>
                                                )}
                                                {calendarEvent.location && <p className="mt-1 text-xs"><strong>Location:</strong> {calendarEvent.location}</p>}
                                                {calendarEvent.provider_name && <p className="mt-0.5 text-xs"><strong>Provider:</strong> {calendarEvent.provider_name}</p>}
                                                {calendarEvent.staff_name && <p className="mt-0.5 text-xs"><strong>Staff:</strong> {calendarEvent.staff_name}</p>}
                                                {calendarEvent.status && <Badge variant="outline" className="mt-1 text-[9px] capitalize">{calendarEvent.status}</Badge>}
                                                {calendarEvent.description && <p className="mt-2 text-sm text-muted-foreground">{calendarEvent.description}</p>}
                                                {calendarEvent.notes && <p className="mt-2 text-sm text-muted-foreground">{calendarEvent.notes}</p>}
                                            </div>
                                            <Button size="sm" variant="ghost" onClick={() => setCalendarEvent(null)}>Close</Button>
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
                    const flaggedCount = notes.filter((n: any) => n.is_flagged).length;
                    const familyCount = notes.filter((n: any) => n.visibility === 'include_family').length;

                    const EMOTIONS: Array<{ key: string; emoji: string; label: string; color: string }> = [
                        { key: 'happy', emoji: '😊', label: 'Happy', color: 'bg-emerald-100 border-emerald-300 text-emerald-700' },
                        { key: 'calm', emoji: '😌', label: 'Calm', color: 'bg-sky-100 border-sky-300 text-sky-700' },
                        { key: 'excited', emoji: '🤩', label: 'Excited', color: 'bg-amber-100 border-amber-300 text-amber-700' },
                        { key: 'tired', emoji: '😴', label: 'Tired', color: 'bg-indigo-100 border-indigo-300 text-indigo-700' },
                        { key: 'anxious', emoji: '😰', label: 'Anxious', color: 'bg-orange-100 border-orange-300 text-orange-700' },
                        { key: 'sad', emoji: '😢', label: 'Sad', color: 'bg-blue-100 border-blue-300 text-blue-700' },
                        { key: 'frustrated', emoji: '😤', label: 'Frustrated', color: 'bg-red-100 border-red-300 text-red-700' },
                        { key: 'confused', emoji: '😕', label: 'Confused', color: 'bg-purple-100 border-purple-300 text-purple-700' },
                    ];

                    const EMOTION_MAP = Object.fromEntries(EMOTIONS.map(e => [e.key, e]));

                    // Time-based emotion analysis
                    const now = new Date();
                    const weekAgo = new Date(now.getTime() - 7 * 86400000);
                    const monthAgo = new Date(now.getTime() - 30 * 86400000);

                    const getTopEmotion = (noteList: any[]) => {
                        const counts: Record<string, number> = {};
                        noteList.forEach((n: any) => {
                            (n.emotions ?? []).forEach((e: string) => { counts[e] = (counts[e] || 0) + 1; });
                        });
                        const top = Object.entries(counts).sort(([, a], [, b]) => b - a)[0];
                        return top ? { key: top[0], count: top[1] } : null;
                    };

                    const weekNotes = notes.filter((n: any) => new Date(n.created_at) >= weekAgo);
                    const monthNotes = notes.filter((n: any) => new Date(n.created_at) >= monthAgo);
                    const topWeek = getTopEmotion(weekNotes);
                    const topMonth = getTopEmotion(monthNotes);

                    // Full emotion counts for the chart (all time)
                    const emotionCounts: Record<string, number> = {};
                    notes.forEach((n: any) => {
                        (n.emotions ?? []).forEach((e: string) => {
                            emotionCounts[e] = (emotionCounts[e] || 0) + 1;
                        });
                    });

                    const NOTE_TYPE_STYLES: Record<string, { border: string; bg: string; label: string }> = {
                        general: { border: 'border-l-violet-400', bg: 'bg-violet-50', label: 'General' },
                        goal_update: { border: 'border-l-indigo-400', bg: 'bg-indigo-50', label: 'Goal Update' },
                        observation: { border: 'border-l-blue-400', bg: 'bg-blue-50', label: 'Observation' },
                        handover: { border: 'border-l-cyan-400', bg: 'bg-cyan-50', label: 'Handover' },
                        incident: { border: 'border-l-red-400', bg: 'bg-red-50', label: 'Incident' },
                    };

                    const toggleEmotion = (key: string) => {
                        setSelectedEmotions(prev => prev.includes(key) ? prev.filter(e => e !== key) : [...prev, key]);
                    };

                    return (
                        <div className="space-y-4">
                            {/* Stats */}
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                                    <div className="text-xl font-bold text-violet-700">{notes.length}</div>
                                    <div className="text-[10px] uppercase tracking-wider text-violet-500">Total Notes</div>
                                </div>
                                <div className="rounded-xl border bg-gradient-to-br from-emerald-50 to-green-50 p-3 text-center">
                                    <div className="text-lg font-bold text-emerald-700">
                                        {topWeek ? `${EMOTION_MAP[topWeek.key]?.emoji ?? ''} ${EMOTION_MAP[topWeek.key]?.label ?? topWeek.key}` : '—'}
                                    </div>
                                    <div className="text-[10px] uppercase tracking-wider text-emerald-500">This Week</div>
                                </div>
                                <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-sky-50 p-3 text-center">
                                    <div className="text-lg font-bold text-blue-700">
                                        {topMonth ? `${EMOTION_MAP[topMonth.key]?.emoji ?? ''} ${EMOTION_MAP[topMonth.key]?.label ?? topMonth.key}` : '—'}
                                    </div>
                                    <div className="text-[10px] uppercase tracking-wider text-blue-500">This Month</div>
                                </div>
                                <div className="rounded-xl border p-3 text-center">
                                    <div className={`text-xl font-bold ${flaggedCount > 0 ? 'text-red-600' : 'text-slate-400'}`}>{flaggedCount}</div>
                                    <div className="text-[10px] uppercase tracking-wider text-muted-foreground">Flagged</div>
                                </div>
                                <div className="rounded-xl border p-3 text-center">
                                    <div className="text-xl font-bold text-blue-600">{familyCount}</div>
                                    <div className="text-[10px] uppercase tracking-wider text-muted-foreground">Family Visible</div>
                                </div>
                            </div>

                            {/* Emotion Trends Chart */}
                            {Object.keys(emotionCounts).length > 0 && (
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-sm">Emotion Trends</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-2">
                                            {EMOTIONS.filter(e => emotionCounts[e.key]).sort((a, b) => (emotionCounts[b.key] || 0) - (emotionCounts[a.key] || 0)).map(emotion => {
                                                const count = emotionCounts[emotion.key] || 0;
                                                const maxCount = Math.max(...Object.values(emotionCounts));
                                                const pct = maxCount > 0 ? (count / maxCount) * 100 : 0;
                                                return (
                                                    <div key={emotion.key} className="flex items-center gap-3">
                                                        <span className="w-6 text-center text-lg">{emotion.emoji}</span>
                                                        <span className="w-20 text-xs font-medium">{emotion.label}</span>
                                                        <div className="flex-1">
                                                            <div className="h-5 overflow-hidden rounded-full bg-muted">
                                                                <div
                                                                    className={`h-full rounded-full transition-all ${emotion.color.split(' ')[0]}`}
                                                                    style={{ width: `${pct}%` }}
                                                                />
                                                            </div>
                                                        </div>
                                                        <span className="w-8 text-right text-xs font-semibold text-muted-foreground">{count}</span>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Add Note Form */}
                            <Card className="overflow-hidden border-violet-200">
                                <div className="bg-gradient-to-r from-violet-500 to-purple-600 px-4 py-2.5">
                                    <h3 className="text-sm font-semibold text-white">Add Progress Note</h3>
                                </div>
                                <CardContent className="p-4">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-1">
                                            <Label className="text-xs">Note Type</Label>
                                            <Select defaultValue="general" onValueChange={(v) => {
                                                const el = document.getElementById('pn-type') as HTMLInputElement;
                                                if (el) el.value = v;
                                            }}>
                                                <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="general">General</SelectItem>
                                                    <SelectItem value="goal_update">Goal Update</SelectItem>
                                                    <SelectItem value="observation">Observation</SelectItem>
                                                    <SelectItem value="handover">Handover</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <input id="pn-type" type="hidden" defaultValue="general" />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="text-xs">Visibility</Label>
                                            <Select defaultValue="staff_only" onValueChange={(v) => {
                                                const el = document.getElementById('pn-vis') as HTMLInputElement;
                                                if (el) el.value = v;
                                            }}>
                                                <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="staff_only">Staff Only</SelectItem>
                                                    <SelectItem value="include_family">Family Visible</SelectItem>
                                                    <SelectItem value="private">Private</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <input id="pn-vis" type="hidden" defaultValue="staff_only" />
                                        </div>
                                    </div>

                                    {/* Emotion Picker */}
                                    <div className="mt-3">
                                        <Label className="text-xs">How is {client.preferred_name || client.first_name} feeling?</Label>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {EMOTIONS.map(emotion => {
                                                const isSelected = selectedEmotions.includes(emotion.key);
                                                return (
                                                    <button
                                                        key={emotion.key}
                                                        type="button"
                                                        onClick={() => toggleEmotion(emotion.key)}
                                                        className={`inline-flex items-center gap-1.5 rounded-full border-2 px-3 py-1.5 text-xs font-medium transition-all ${
                                                            isSelected
                                                                ? `${emotion.color} shadow-sm scale-105`
                                                                : 'border-border bg-card text-muted-foreground hover:border-primary/30'
                                                        }`}
                                                    >
                                                        <span className="text-base">{emotion.emoji}</span>
                                                        {emotion.label}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>

                                    <div className="mt-3">
                                        <Textarea id="pn-content" className="min-h-[80px] text-sm" placeholder="Write your progress note here..." />
                                    </div>
                                    <div className="mt-3 flex items-center justify-between">
                                        <p className="text-[10px] text-muted-foreground">Notes are saved immediately and visible to the care team.</p>
                                        <Button size="sm" className="gap-1.5 bg-violet-600 hover:bg-violet-700" onClick={() => {
                                            const content = (document.getElementById('pn-content') as HTMLTextAreaElement)?.value;
                                            const noteType = (document.getElementById('pn-type') as HTMLInputElement)?.value || 'general';
                                            const vis = (document.getElementById('pn-vis') as HTMLInputElement)?.value || 'staff_only';
                                            if (!content?.trim()) return;
                                            router.post('/operations/progress-notes', {
                                                client_id: client.id,
                                                content,
                                                note_type: noteType,
                                                emotions: selectedEmotions.length > 0 ? selectedEmotions : null,
                                                visibility: vis,
                                            } as any, {
                                                preserveScroll: true,
                                                onSuccess: () => {
                                                    (document.getElementById('pn-content') as HTMLTextAreaElement).value = '';
                                                    setSelectedEmotions([]);
                                                },
                                            });
                                        }}>
                                            Save Note
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Header */}
                            <div className="flex items-center justify-between">
                                <span className="text-sm font-medium">Recent Notes ({notes.length})</span>
                                <Button size="sm" variant="outline" className="gap-1.5 text-xs" asChild>
                                    <Link href={`/operations/progress-notes?client_id=${client.id}`}>View All Notes</Link>
                                </Button>
                            </div>

                            {/* Notes list */}
                            {notes.length === 0 ? (
                                <Card className="border-dashed">
                                    <CardContent className="flex flex-col items-center justify-center py-12">
                                        <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-violet-50">
                                            <FileText className="h-7 w-7 text-violet-400" />
                                        </div>
                                        <p className="font-medium">No Progress Notes</p>
                                        <p className="mt-1 text-sm text-muted-foreground">Notes from shifts and care activities will appear here.</p>
                                    </CardContent>
                                </Card>
                            ) : (
                                <div className="space-y-2">
                                    {notes.slice(0, 5).map((note: any) => {
                                        const typeStyle = NOTE_TYPE_STYLES[note.note_type] ?? NOTE_TYPE_STYLES.general;
                                        return (
                                            <Card key={note.id} className={`overflow-hidden border-l-4 ${note.is_flagged ? 'border-l-red-500 bg-red-50/30' : typeStyle.border}`}>
                                                <CardContent className="p-4">
                                                    <div className="flex items-start justify-between gap-3">
                                                        <div className="flex items-start gap-3">
                                                            {/* Avatar */}
                                                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-violet-100 text-xs font-bold text-violet-700">
                                                                {(note.author?.name ?? '?').split(' ').map((w: string) => w[0]).join('').slice(0, 2)}
                                                            </div>
                                                            <div>
                                                                <div className="flex items-center gap-2">
                                                                    <span className="text-sm font-semibold">{note.author?.name ?? 'Unknown'}</span>
                                                                    <Badge className={`border-0 text-[9px] ${typeStyle.bg} ${typeStyle.border.replace('border-l-', 'text-').replace('-400', '-700')}`}>{typeStyle.label}</Badge>
                                                                    {(note.emotions ?? []).length > 0 && (note.emotions ?? []).map((e: string) => (
                                                                        <span key={e} className="text-sm" title={EMOTION_MAP[e]?.label ?? e}>{EMOTION_MAP[e]?.emoji ?? e}</span>
                                                                    ))}
                                                                    {note.visibility === 'include_family' && (
                                                                        <Badge className="border-0 bg-blue-100 text-blue-700 text-[9px]">Family</Badge>
                                                                    )}
                                                                    {note.is_flagged && (
                                                                        <Badge className="border-0 bg-red-100 text-red-700 text-[9px]">Flagged</Badge>
                                                                    )}
                                                                </div>
                                                                {note.goal && (
                                                                    <span className="mt-0.5 inline-block rounded bg-violet-50 px-1.5 py-0.5 text-[10px] text-violet-600">Goal: {note.goal.title}</span>
                                                                )}
                                                                <p className="mt-1 text-xs text-slate-600 leading-relaxed">{(note.content ?? '').slice(0, 300)}{(note.content ?? '').length > 300 ? '...' : ''}</p>
                                                            </div>
                                                        </div>
                                                        <span className="shrink-0 text-[10px] text-muted-foreground">{new Date(note.created_at).toLocaleDateString('en-NZ')}</span>
                                                    </div>
                                                </CardContent>
                                            </Card>
                                        );
                                    })}
                                    {notes.length > 5 && (
                                        <div className="flex justify-center pt-2">
                                            <Button size="sm" variant="outline" className="gap-1.5 text-xs" asChild>
                                                <Link href={`/operations/progress-notes?client_id=${client.id}`}>
                                                    View all {notes.length} notes
                                                </Link>
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    );
                })()}

                {tab === 'service_agreements' && (() => {
                    const pageProps = usePage().props as any;
                    const agreements = pageProps.client_agreements ?? [];
                    const activeAgs = agreements.filter((a: any) => a.status === 'active');
                    const totalBudget = agreements.reduce((s: number, a: any) => s + (a.total_budget ?? 0), 0);
                    const totalUsed = agreements.reduce((s: number, a: any) => s + (a.budget_used ?? 0), 0);
                    const overallPct = totalBudget > 0 ? Math.round((totalUsed / totalBudget) * 100) : 0;
                    const expiringSoon = agreements.filter((a: any) => a.ends_at && new Date(a.ends_at).getTime() - Date.now() < 30 * 86400000 && new Date(a.ends_at) > new Date()).length;

                    return (
                        <div className="space-y-4">
                            {/* Stats */}
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                                    <div className="text-xl font-bold text-violet-700">{agreements.length}</div>
                                    <div className="text-[10px] uppercase tracking-wider text-violet-500">Total</div>
                                </div>
                                <div className="rounded-xl border bg-gradient-to-br from-emerald-50 to-green-50 p-3 text-center">
                                    <div className="text-xl font-bold text-emerald-700">{activeAgs.length}</div>
                                    <div className="text-[10px] uppercase tracking-wider text-emerald-500">Active</div>
                                </div>
                                <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                                    <div className={`text-xl font-bold ${overallPct > 90 ? 'text-red-600' : overallPct > 70 ? 'text-amber-600' : 'text-violet-700'}`}>{overallPct}%</div>
                                    <div className="text-[10px] uppercase tracking-wider text-violet-500">Budget Used</div>
                                </div>
                                <div className="rounded-xl border p-3 text-center">
                                    <div className={`text-xl font-bold ${expiringSoon > 0 ? 'text-amber-600' : 'text-slate-400'}`}>{expiringSoon}</div>
                                    <div className="text-[10px] uppercase tracking-wider text-muted-foreground">Expiring Soon</div>
                                </div>
                            </div>

                            {/* Overall Budget Bar */}
                            {totalBudget > 0 && (
                                <Card className="border-violet-200 bg-violet-50/30">
                                    <CardContent className="p-4">
                                        <div className="flex items-center justify-between mb-2">
                                            <span className="text-sm font-semibold">Total Funding Overview</span>
                                            <span className="text-sm font-bold text-violet-700">
                                                ${new Intl.NumberFormat('en-NZ').format(totalUsed)} / ${new Intl.NumberFormat('en-NZ').format(totalBudget)} NZD
                                            </span>
                                        </div>
                                        <div className="h-4 w-full overflow-hidden rounded-full bg-violet-200">
                                            <div className={`h-full rounded-full transition-all ${overallPct > 90 ? 'bg-red-500' : overallPct > 70 ? 'bg-amber-500' : 'bg-violet-600'}`} style={{ width: `${Math.min(overallPct, 100)}%` }} />
                                        </div>
                                        <div className="mt-1 flex justify-between text-[10px] text-muted-foreground">
                                            <span>Remaining: ${new Intl.NumberFormat('en-NZ').format(totalBudget - totalUsed)}</span>
                                            <span>{overallPct}% utilised</span>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Header */}
                            <div className="flex items-center justify-between">
                                <span className="text-sm font-medium">Agreements ({agreements.length})</span>
                                <Button size="sm" className="gap-1.5 bg-violet-600 hover:bg-violet-700" asChild>
                                    <Link href={`/operations/service-agreements/create?client_id=${client.id}`}>New Agreement</Link>
                                </Button>
                            </div>

                            {/* Agreement Cards */}
                            {agreements.length === 0 ? (
                                <Card className="border-dashed">
                                    <CardContent className="flex flex-col items-center justify-center py-12">
                                        <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-violet-50">
                                            <DollarSign className="h-7 w-7 text-violet-400" />
                                        </div>
                                        <p className="font-medium">No Service Agreements</p>
                                        <p className="mt-1 text-sm text-muted-foreground">Create a funding agreement for {client.first_name}.</p>
                                    </CardContent>
                                </Card>
                            ) : (
                                <div className="space-y-3">
                                    {agreements.map((ag: any) => {
                                        const budgetPct = ag.total_budget > 0 ? Math.round(((ag.budget_used ?? 0) / ag.total_budget) * 100) : 0;
                                        const budgetColor = budgetPct > 90 ? 'bg-red-500' : budgetPct > 70 ? 'bg-amber-500' : 'bg-emerald-500';
                                        const isExpiring = ag.ends_at && new Date(ag.ends_at).getTime() - Date.now() < 30 * 86400000 && new Date(ag.ends_at) > new Date();
                                        const isExpired = ag.ends_at && new Date(ag.ends_at) < new Date();
                                        return (
                                            <Card key={ag.id} className={`overflow-hidden border-l-4 transition-all hover:shadow-sm ${ag.status === 'active' ? 'border-l-emerald-500' : 'border-l-slate-300'}`}>
                                                <CardContent className="p-4">
                                                    <div className="flex items-start justify-between gap-3">
                                                        <div className="flex items-start gap-3">
                                                            <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${ag.status === 'active' ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-500'}`}>
                                                                <DollarSign className="h-5 w-5" />
                                                            </div>
                                                            <div>
                                                                <div className="flex items-center gap-2 flex-wrap">
                                                                    <span className="text-sm font-semibold">{ag.title}</span>
                                                                    <Badge className={`border-0 text-[9px] capitalize ${ag.status === 'active' ? 'bg-emerald-100 text-emerald-700' : ag.status === 'draft' ? 'bg-slate-100 text-slate-600' : 'bg-amber-100 text-amber-700'}`}>{ag.status}</Badge>
                                                                    {ag.funding_body && <Badge variant="outline" className="text-[9px]">{ag.funding_body}</Badge>}
                                                                    {isExpiring && <Badge className="border-0 bg-amber-100 text-amber-700 text-[9px] animate-pulse">Expiring Soon</Badge>}
                                                                    {isExpired && <Badge className="border-0 bg-red-100 text-red-700 text-[9px]">Expired</Badge>}
                                                                </div>
                                                                <div className="mt-0.5 flex gap-3 text-xs text-muted-foreground">
                                                                    {ag.reference_number && <span>Ref: {ag.reference_number}</span>}
                                                                    {ag.starts_at && <span>{new Date(ag.starts_at).toLocaleDateString('en-NZ')} — {ag.ends_at ? new Date(ag.ends_at).toLocaleDateString('en-NZ') : 'Ongoing'}</span>}
                                                                    {ag.hourly_rate && <span>${ag.hourly_rate}/hr</span>}
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <Button variant="outline" size="sm" className="shrink-0 text-xs" asChild>
                                                            <Link href={`/operations/service-agreements/${ag.id}`}>View</Link>
                                                        </Button>
                                                    </div>
                                                    {ag.total_budget > 0 && (
                                                        <div className="mt-3">
                                                            <div className="flex items-center justify-between text-xs mb-1">
                                                                <span className="text-muted-foreground">Budget Utilisation</span>
                                                                <span className="font-semibold">${new Intl.NumberFormat('en-NZ').format(ag.budget_used ?? 0)} / ${new Intl.NumberFormat('en-NZ').format(ag.total_budget)} ({budgetPct}%)</span>
                                                            </div>
                                                            <div className="h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
                                                                <div className={`h-full rounded-full ${budgetColor} transition-all`} style={{ width: `${Math.min(budgetPct, 100)}%` }} />
                                                            </div>
                                                        </div>
                                                    )}
                                                </CardContent>
                                            </Card>
                                        );
                                    })}
                                </div>
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

                            {/* Visual Timeline */}
                            {filteredEvents.length === 0 ? (
                                <div className="flex flex-col items-center py-12 text-center">
                                    <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-violet-50">
                                        <Clock className="h-7 w-7 text-violet-400" />
                                    </div>
                                    <p className="font-medium">{events.length ? 'No events match your filters' : 'No timeline events yet'}</p>
                                    <p className="mt-1 text-sm text-muted-foreground">Events will appear here as care is delivered.</p>
                                </div>
                            ) : (
                                <div className="relative ml-4">
                                    {/* Vertical line */}
                                    <div className="absolute left-3 top-0 bottom-0 w-0.5 bg-gradient-to-b from-violet-300 via-violet-200 to-transparent" />

                                    <div className="space-y-0">
                                        {filteredEvents.map((e, idx) => {
                                            const TYPE_STYLES: Record<string, { dot: string; bg: string; icon: string }> = {
                                                note: { dot: 'bg-violet-500', bg: 'bg-violet-50', icon: '📝' },
                                                progress_note: { dot: 'bg-indigo-500', bg: 'bg-indigo-50', icon: '🎯' },
                                                handover: { dot: 'bg-blue-500', bg: 'bg-blue-50', icon: '🤝' },
                                                incident: { dot: 'bg-red-500', bg: 'bg-red-50', icon: '⚠️' },
                                                shift: { dot: 'bg-emerald-500', bg: 'bg-emerald-50', icon: '📋' },
                                                medication: { dot: 'bg-cyan-500', bg: 'bg-cyan-50', icon: '💊' },
                                                assessment: { dot: 'bg-amber-500', bg: 'bg-amber-50', icon: '📊' },
                                            };
                                            const style = TYPE_STYLES[e.type] ?? { dot: 'bg-slate-400', bg: 'bg-slate-50', icon: '📌' };

                                            // Date grouping
                                            const eventDate = e.occurred_at ? new Date(e.occurred_at).toLocaleDateString('en-NZ', { weekday: 'long', day: 'numeric', month: 'long' }) : '';
                                            const prevDate = idx > 0 && filteredEvents[idx - 1].occurred_at ? new Date(filteredEvents[idx - 1].occurred_at).toLocaleDateString('en-NZ', { weekday: 'long', day: 'numeric', month: 'long' }) : '';
                                            const showDateHeader = eventDate !== prevDate;

                                            return (
                                                <div key={e.id}>
                                                    {showDateHeader && (
                                                        <div className="relative mb-2 mt-4 flex items-center pl-8 first:mt-0">
                                                            <div className="absolute left-0 flex h-6 w-6 items-center justify-center rounded-full border-2 border-white bg-violet-200">
                                                                <div className="h-2 w-2 rounded-full bg-violet-500" />
                                                            </div>
                                                            <span className="text-xs font-semibold text-violet-600">{eventDate}</span>
                                                        </div>
                                                    )}
                                                    <div className="relative flex gap-3 pb-4 pl-8">
                                                        {/* Dot on timeline */}
                                                        <div className={`absolute left-0 top-1 flex h-6 w-6 items-center justify-center rounded-full border-2 border-white ${style.dot} shadow-sm`}>
                                                            <span className="text-[10px]">{style.icon}</span>
                                                        </div>
                                                        {/* Event card */}
                                                        <div className={`flex-1 rounded-xl border ${style.bg} p-3 transition-all hover:shadow-sm`}>
                                                            <div className="flex items-start justify-between gap-2">
                                                                <div>
                                                                    <div className="flex items-center gap-2">
                                                                        <span className="text-sm font-medium">{e.subject || e.type}</span>
                                                                        <Badge variant="outline" className="h-4 px-1.5 text-[9px] capitalize">{e.type}</Badge>
                                                                    </div>
                                                                    {e.actor?.name && (
                                                                        <p className="mt-0.5 text-[11px] text-muted-foreground">
                                                                            {e.actor.name}{e.site?.name ? ` · ${e.site.name}` : ''}
                                                                        </p>
                                                                    )}
                                                                </div>
                                                                <span className="shrink-0 text-[10px] text-muted-foreground">
                                                                    {e.occurred_at ? new Date(e.occurred_at).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' }) : ''}
                                                                </span>
                                                            </div>
                                                            {e.body && (
                                                                <p className="mt-1.5 text-xs leading-relaxed text-slate-600 whitespace-pre-wrap">{e.body.length > 250 ? e.body.slice(0, 250) + '...' : e.body}</p>
                                                            )}
                                                            {e.meta?.emotions && (e.meta.emotions as string[]).length > 0 && (
                                                                <div className="mt-1.5 flex flex-wrap gap-1">
                                                                    {(e.meta.emotions as string[]).map((em: string) => {
                                                                        const emojiMap: Record<string, string> = { happy: '😊', calm: '😌', excited: '🤩', tired: '😴', anxious: '😰', sad: '😢', frustrated: '😤', confused: '😕' };
                                                                        const colorMap: Record<string, string> = { happy: 'bg-emerald-100 text-emerald-700', calm: 'bg-sky-100 text-sky-700', excited: 'bg-amber-100 text-amber-700', tired: 'bg-indigo-100 text-indigo-700', anxious: 'bg-orange-100 text-orange-700', sad: 'bg-blue-100 text-blue-700', frustrated: 'bg-red-100 text-red-700', confused: 'bg-purple-100 text-purple-700' };
                                                                        return (
                                                                            <span key={em} className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ${colorMap[em] ?? 'bg-muted'}`}>
                                                                                {emojiMap[em] ?? em} {em}
                                                                            </span>
                                                                        );
                                                                    })}
                                                                </div>
                                                            )}
                                                            {(e.comments?.length > 0 || e.reactions?.length > 0 || can.create_note) && (
                                                                <TimelineInteractions
                                                                    eventId={e.id}
                                                                    comments={e.comments ?? []}
                                                                    reactions={e.reactions ?? []}
                                                                    currentUserId={(auth as any)?.user?.id}
                                                                    commentUrl={`/clients/${client.id}/timeline/${e.id}/comments`}
                                                                    deleteCommentUrl={`/clients/${client.id}/timeline/comments`}
                                                                    likeCommentUrl={`/clients/${client.id}/timeline/comments`}
                                                                    reactUrl={`/clients/${client.id}/timeline/${e.id}/react`}
                                                                    canComment={can.create_note}
                                                                    canReact={true}
                                                                    showStaffBadge={true}
                                                                />
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}

                            <div className="flex justify-center pt-2">
                                <Button variant="outline" size="sm" className="gap-1.5 text-xs" asChild>
                                    <Link href={`/operations/clients/${client.id}/timeline`}>View Full Timeline</Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {tab === 'documents' && (() => {
                    const FILE_ICONS: Record<string, { color: string; bg: string }> = {
                        pdf: { color: 'text-red-600', bg: 'bg-red-100' },
                        doc: { color: 'text-blue-600', bg: 'bg-blue-100' },
                        docx: { color: 'text-blue-600', bg: 'bg-blue-100' },
                        xls: { color: 'text-emerald-600', bg: 'bg-emerald-100' },
                        xlsx: { color: 'text-emerald-600', bg: 'bg-emerald-100' },
                        jpg: { color: 'text-amber-600', bg: 'bg-amber-100' },
                        jpeg: { color: 'text-amber-600', bg: 'bg-amber-100' },
                        png: { color: 'text-amber-600', bg: 'bg-amber-100' },
                    };
                    const getFileStyle = (name?: string) => {
                        const ext = (name ?? '').split('.').pop()?.toLowerCase() ?? '';
                        return FILE_ICONS[ext] ?? { color: 'text-violet-600', bg: 'bg-violet-100' };
                    };
                    const CAT_COLORS: Record<string, string> = {
                        care_plan: 'bg-violet-100 text-violet-700', assessment: 'bg-blue-100 text-blue-700',
                        medical: 'bg-red-100 text-red-700', legal: 'bg-amber-100 text-amber-700',
                        policy: 'bg-emerald-100 text-emerald-700', consent: 'bg-purple-100 text-purple-700',
                    };

                    const grouped = (documents ?? []).reduce((acc: Record<string, any[]>, d: any) => {
                        const folder = d.folder || 'Unfiled';
                        if (!acc[folder]) acc[folder] = [];
                        acc[folder].push(d);
                        return acc;
                    }, {} as Record<string, any[]>);

                    return (
                        <div className="space-y-4">
                            {/* Header */}
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-medium">{(documents ?? []).length} documents</span>
                                    {(documents ?? []).some((d: any) => d.expires_at && new Date(d.expires_at) < new Date()) && (
                                        <Badge className="border-0 bg-red-100 text-red-700 text-[10px]">Has expired</Badge>
                                    )}
                                </div>
                                <Button size="sm" className="gap-1.5 bg-violet-600 hover:bg-violet-700" asChild>
                                    <Link href={`/operations/clients/${client.id}/documents`}>Manage Documents</Link>
                                </Button>
                            </div>

                            {/* Grid */}
                            {(documents ?? []).length === 0 ? (
                                <Card className="border-dashed">
                                    <CardContent className="flex flex-col items-center justify-center py-12">
                                        <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-violet-50">
                                            <FolderOpen className="h-7 w-7 text-violet-400" />
                                        </div>
                                        <p className="font-medium">No Documents</p>
                                        <p className="mt-1 text-sm text-muted-foreground">Upload documents for {client.first_name}.</p>
                                    </CardContent>
                                </Card>
                            ) : (
                                Object.entries(grouped).map(([folder, docs]) => (
                                    <div key={folder}>
                                        <div className="mb-2 flex items-center gap-2">
                                            <FolderOpen className="h-4 w-4 text-amber-500" />
                                            <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{folder}</span>
                                            <Badge variant="secondary" className="text-[10px]">{(docs as any[]).length}</Badge>
                                        </div>
                                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                            {(docs as any[]).map((d: any) => {
                                                const fi = getFileStyle(d.original_name);
                                                const expired = d.expires_at && new Date(d.expires_at) < new Date();
                                                const expiring = d.expires_at && !expired && new Date(d.expires_at).getTime() - Date.now() < 30 * 86400000;
                                                return (
                                                    <a key={d.id} href={`/operations/clients/${client.id}/documents/${d.id}/download`}
                                                        className={`group rounded-xl border bg-white p-4 text-center transition-all hover:shadow-md hover:-translate-y-0.5 ${expired ? 'border-red-200' : expiring ? 'border-amber-200' : ''}`}>
                                                        <div className={`mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-xl ${fi.bg}`}>
                                                            <FileText className={`h-6 w-6 ${fi.color}`} />
                                                        </div>
                                                        <p className="text-xs font-medium leading-tight line-clamp-2">{d.title || d.original_name}</p>
                                                        <div className="mt-1.5 flex items-center justify-center gap-1">
                                                            {d.portal_visible && <Globe className="h-3 w-3 text-blue-500" />}
                                                            {expired && <Badge className="h-4 border-0 bg-red-100 px-1 text-[8px] text-red-600">Expired</Badge>}
                                                            {expiring && <Badge className="h-4 border-0 bg-amber-100 px-1 text-[8px] text-amber-600">Expiring</Badge>}
                                                            {d.category && <Badge className={`h-4 border-0 px-1 text-[8px] ${CAT_COLORS[d.category] ?? 'bg-slate-100 text-slate-600'}`}>{d.category.replace(/_/g, ' ')}</Badge>}
                                                        </div>
                                                    </a>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    );
                })()}

                {tab === 'photos' && (
                    <PhotoGalleryTab clientId={client.id} photos={photos} canEdit={can.edit} />
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

function PhotoGalleryTab({ clientId, photos, canEdit }: { clientId: number; photos: GalleryPhoto[]; canEdit: boolean }) {
    const [showUpload, setShowUpload] = useState(false);
    const photoForm = useForm<{ photo: File | null; caption: string; visibility: string }>({
        photo: null,
        caption: '',
        visibility: 'family',
    });
    const submitPhoto = (e: React.FormEvent) => {
        e.preventDefault();
        if (!photoForm.data.photo) return;
        photoForm.post(`/operations/clients/${clientId}/gallery-photos`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { setShowUpload(false); photoForm.reset(); },
        });
    };
    const deletePhoto = (photoId: number) => {
        if (!confirm('Delete this photo?')) return;
        router.delete(`/operations/clients/${clientId}/gallery-photos/${photoId}`, { preserveScroll: true });
    };
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center justify-between text-base">
                    <span>Photo Gallery</span>
                    {canEdit && (
                        <Button size="sm" onClick={() => setShowUpload(!showUpload)}>
                            {showUpload ? 'Cancel' : 'Upload Photo'}
                        </Button>
                    )}
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {showUpload && (
                    <form onSubmit={submitPhoto} className="space-y-3 rounded-lg border bg-muted/30 p-4">
                        <div>
                            <Label>Photo *</Label>
                            <Input
                                type="file"
                                accept="image/*"
                                onChange={(e) => photoForm.setData('photo', e.target.files?.[0] ?? null)}
                            />
                        </div>
                        <div>
                            <Label>Caption</Label>
                            <Input
                                value={photoForm.data.caption}
                                onChange={(e) => photoForm.setData('caption', e.target.value)}
                                placeholder="Add a caption..."
                            />
                        </div>
                        <div>
                            <Label>Visibility</Label>
                            <Select value={photoForm.data.visibility} onValueChange={(v) => photoForm.setData('visibility', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="staff_only">Staff Only</SelectItem>
                                    <SelectItem value="family">Family & Staff</SelectItem>
                                    <SelectItem value="all_portal_users">All Portal Users</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <Button type="submit" disabled={photoForm.processing || !photoForm.data.photo}>
                            {photoForm.processing ? 'Uploading...' : 'Upload'}
                        </Button>
                    </form>
                )}

                {photos.length > 0 ? (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                        {photos.map((p) => (
                            <div key={p.id} className="group relative overflow-hidden rounded-lg border bg-card">
                                <a href={p.url} target="_blank" rel="noopener noreferrer">
                                    <img
                                        src={p.thumbnail_url || p.url}
                                        alt={p.caption || p.original_name}
                                        className="aspect-square w-full object-cover"
                                        loading="lazy"
                                    />
                                </a>
                                <div className="p-2">
                                    {p.caption && <p className="text-xs font-medium line-clamp-2">{p.caption}</p>}
                                    <div className="mt-1 flex items-center gap-1 flex-wrap">
                                        <Badge className="h-4 border-0 px-1 text-[8px] bg-slate-100 text-slate-600">{p.visibility.replace(/_/g, ' ')}</Badge>
                                        {p.status === 'pending_approval' && <Badge className="h-4 border-0 px-1 text-[8px] bg-amber-100 text-amber-600">Pending</Badge>}
                                    </div>
                                    <p className="mt-1 text-[10px] text-muted-foreground">{p.uploaded_by} &middot; {new Date(p.created_at).toLocaleDateString()}</p>
                                </div>
                                {canEdit && (
                                    <button
                                        onClick={() => deletePhoto(p.id)}
                                        className="absolute top-1 right-1 rounded-full bg-black/50 p-1 text-white opacity-0 transition-opacity group-hover:opacity-100 hover:bg-red-600"
                                        title="Delete photo"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" className="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="text-sm text-muted-foreground text-center py-12">
                        No photos yet. Upload the first one!
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
