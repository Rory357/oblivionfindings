import ClientLocationTab, {
    type ClientLocationData,
} from '@/components/client-location-tab';
import ClientSafetyRibbon, {
    type ClientSafety,
} from '@/components/client-safety-ribbon';
import HealthSummaryCard, {
    type HealthSummary,
} from '@/components/clinical/health-summary-card';
import ClientObservationsTab from '@/components/clinical/client-observations-tab';
import {
    HalfMoonGauge,
    HorizontalBarChart,
    ProgressRing,
} from '@/components/fleet-charts';
import { DonutChart } from '@/components/ops-stat-card';
import PageShell from '@/components/page-shell';
import { TimelineInteractions } from '@/components/timeline-interactions';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { Textarea } from '@/components/ui/textarea';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/date-format';
import { formatDateTime as formatDT, formatRelativeTime, formatDuration, severityVariant } from '@/lib/fleet-utils';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    BookOpen,
    Calendar,
    CalendarDays,
    Camera,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Clock,
    DollarSign,
    FileText,
    FolderOpen,
    Globe,
    GraduationCap,
    Heart,
    Home,
    ListTodo,
    MapPin,
    MessageSquare as MsgIcon,
    Package,
    Pencil,
    Phone,
    Pill,
    Plus,
    Search,
    Shield,
    ShieldAlert,
    Stethoscope,
    Target,
    Trash2,
    Truck,
    User,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-sm font-medium">{value}</p>
        </div>
    );
}

type AssetLocation = {
    id: number;
    name: string;
    type: string;
    rooms: Array<{ id: number; name: string }>;
};

type AvailableTracker = {
    id: number;
    name: string;
    status: string;
    serial?: string | null;
    site_id?: number | null;
    last_seen_at?: string | null;
    battery?: number | null;
};

type AssetTracker = {
    id: number;
    name: string;
    status: string;
    last_seen_at?: string | null;
    battery?: number | null;
    lat?: number | null;
    lng?: number | null;
    speed?: number | null;
};

type PersonalAsset = {
    id: number;
    name: string;
    category?: string | null;
    description?: string | null;
    serial_number?: string | null;
    estimated_value?: string | null;
    condition?: string | null;
    location?: string | null;
    site_id?: number | null;
    site_name?: string | null;
    room_id?: number | null;
    room_name?: string | null;
    tracker_hardware_id?: number | null;
    tracker?: AssetTracker | null;
    photo_url?: string | null;
    acquired_at?: string | null;
    notes?: string | null;
    status: string;
    ownership?: string | null;
    funding_source?: string | null;
    return_required?: boolean;
    return_by?: string | null;
    last_serviced_at?: string | null;
    next_service_due?: string | null;
    service_provider?: string | null;
    warranty_expires_at?: string | null;
    insurance_reference?: string | null;
    disposed_at?: string | null;
    disposal_reason?: string | null;
    portal_visible?: boolean;
    is_service_overdue?: boolean;
    is_warranty_expired?: boolean;
    is_warranty_expiring_soon?: boolean;
    is_return_overdue?: boolean;
    recorded_by?: string | null;
    created_at: string;
};

type ClientShiftSummary = {
    id: number;
    starts_at?: string | null;
    ends_at?: string | null;
    actual_starts_at?: string | null;
    actual_ends_at?: string | null;
    status: string;
    shift_type?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    expected_break_minutes?: number | null;
    location?: string | null;
    service_context?: { id: number; name: string; type?: string | null } | null;
    staff?: { id: number; name: string; email: string } | null;
    task_count?: number;
    incomplete_task_count?: number;
    form_submission_count?: number;
    medication_administration_count?: number;
    timesheet_count?: number;
    handover_count?: number;
};

type ClientRecurringSeriesSummary = {
    id: number;
    status: string;
    shift_type?: string | null;
    weekdays: string[];
    starts_time?: string | null;
    ends_time?: string | null;
    next_starts_at?: string | null;
    location?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    service_context?: { id: number; name: string; type?: string | null } | null;
    staff?: { id: number; name: string; email?: string | null } | null;
    remaining_occurrences_count: number;
    open_occurrences_count: number;
    active_replacements_count: number;
};

type ClientSiteCoverageSummary = {
    site_id: number;
    site_name: string;
    total_windows: number;
    under_covered_windows: number;
    exact_windows: number;
    overstaffed_windows: number;
    largest_missing_staff: number;
    alerts: Array<{
        rule_name: string;
        window_label: string;
        required_staff: number;
        assigned_staff: number;
        missing_staff: number;
        coverage_state: string;
        starts_at?: string | null;
        ends_at?: string | null;
    }>;
};

type Props = {
    client: {
        id: number;
        nhi_number?: string | null;
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
    personal_assets: PersonalAsset[];
    portal_users: Array<any>;
    events: Array<any>;
    handover: Array<any>;
    shifts_summary?: {
        next: ClientShiftSummary | null;
        last: ClientShiftSummary | null;
        recurring?: ClientRecurringSeriesSummary[];
    };
    site_coverage?: ClientSiteCoverageSummary | null;
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
        checklist?: any;
        workflow?: any | null;
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
        record_observation?: boolean;
        record_clinical_observation?: boolean;
        record_event?: boolean;
    };
    location?: ClientLocationData;
    transport?: {
        stats: { transports_30d: number; outings_30d: number; incidents_30d: number };
        upcoming_outings: Array<{
            id: number;
            title: string;
            destination: string;
            status: string;
            planned_departure: string | null;
            planned_return: string | null;
            vehicle: { id: number; name: string } | null;
            driver: { id: number; name: string } | null;
            residents_count: number;
        }>;
        transport_history: Array<{
            id: number;
            transport_type: string;
            pickup_location: string | null;
            dropoff_location: string | null;
            departed_at: string | null;
            arrived_at: string | null;
            duration_minutes: number | null;
            status: string;
            vehicle: { id: number; name: string } | null;
            driver: { id: number; name: string } | null;
            shift: { id: number; starts_at: string | null; shift_type: string } | null;
        }>;
        medication_logs: Array<{
            id: number;
            medication_name: string;
            is_controlled_drug: boolean;
            packed_at: string | null;
            packed_by: string | null;
            administered_at: string | null;
            administered_by: string | null;
            witnessed_by: string | null;
            returned_to_house_at: string | null;
            status: string;
        }>;
    };
};

type TabKey =
    | 'profile'
    | 'onboarding'
    | 'medical'
    | 'mar'
    | 'care_plans'
    | 'observations'
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
    | 'family_notes'
    | 'respite'
    | 'personal_assets'
    | 'transport'
    | 'location'
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

function weekdayLabel(code: string) {
    const labels: Record<string, string> = {
        mon: 'Mon',
        tue: 'Tue',
        wed: 'Wed',
        thu: 'Thu',
        fri: 'Fri',
        sat: 'Sat',
        sun: 'Sun',
    };

    return labels[code] ?? code;
}

function shiftTypeLabel(value?: string | null) {
    return String(value ?? 'standard').replace(/_/g, ' ');
}

function seriesTimeLabel(startsTime?: string | null, endsTime?: string | null) {
    if (!startsTime || !endsTime) return '';
    const overnight = endsTime <= startsTime;
    return `${startsTime}-${endsTime}${overnight ? ' overnight' : ''}`;
}

export default function ClientShow({
    client,
    medical,
    support_plan,
    assessments,
    documents,
    photos,
    personal_assets,
    portal_users,
    events,
    handover,
    onboarding,
    shifts_summary,
    site_coverage,
    respite,
    health_summary,
    can,
    location,
    transport,
}: Props) {
    const pageProps = usePage().props as any;
    const { auth, labels } = pageProps;
    const safety = pageProps.safety as ClientSafety | null | undefined;
    const nextShiftSummary = shifts_summary?.next ?? null;
    const recurringShiftSeries = shifts_summary?.recurring ?? [];
    const siteCoverageSummary = site_coverage ?? null;
    const nextShiftTypeLabel = String(
        nextShiftSummary?.shift_type ?? 'standard',
    ).replace('_', ' ');
    const siteCoverageSegments = [
        {
            label: 'Under-covered',
            value: siteCoverageSummary?.under_covered_windows ?? 0,
            color: '#ef4444',
        },
        {
            label: 'Exact',
            value: siteCoverageSummary?.exact_windows ?? 0,
            color: '#10b981',
        },
        {
            label: 'Overstaffed',
            value: siteCoverageSummary?.overstaffed_windows ?? 0,
            color: '#f59e0b',
        },
    ];
    const siteCoverageRate =
        siteCoverageSummary && siteCoverageSummary.total_windows > 0
            ? Math.round(
                  ((siteCoverageSummary.exact_windows +
                      siteCoverageSummary.overstaffed_windows) /
                      siteCoverageSummary.total_windows) *
                      100,
              )
            : 0;
    const siteCoverageRiskRate =
        siteCoverageSummary && siteCoverageSummary.total_windows > 0
            ? Math.round(
                  (siteCoverageSummary.under_covered_windows /
                      siteCoverageSummary.total_windows) *
                      100,
              )
            : 0;
    const respiteCan = auth?.can?.respite ?? {};
    const consents = pageProps.consents ?? [];
    const familyNotesOpenCount = pageProps.family_notes_open_count ?? 0;
    const pendingVisitCount = pageProps.pending_visit_count ?? 0;
    const emarSummary = pageProps.emar_summary ?? null;
    const carePlansSummary = pageProps.care_plans_summary ?? {};
    const clientProgressNotes = pageProps.client_progress_notes ?? [];
    const clientAgreements = pageProps.client_agreements ?? [];
    const familyNotes = pageProps.family_notes ?? [];
    const name = `${client.first_name} ${client.last_name}`.trim();
    const getInitials = useInitials();
    const photoForm = useForm<{ photo: File | null }>({ photo: null });
    const removePhotoForm = useForm({});

    const tabs: Array<{
        key: TabKey;
        label: string;
        icon: typeof User;
        show: boolean;
        count?: number;
    }> = useMemo(
        () => [
            { key: 'profile', label: 'Overview', icon: User, show: true },
            {
                key: 'onboarding',
                label: 'Onboarding',
                icon: CheckCircle2,
                show: client.status === 'onboarding' || !!onboarding?.workflow,
                count: onboarding?.total,
            },
            { key: 'medical', label: 'Medical', icon: Heart, show: true },
            { key: 'mar', label: 'MAR', icon: Pill, show: true },
            {
                key: 'observations',
                label: 'Observations',
                icon: Stethoscope,
                show:
                    can.record_observation ||
                    can.record_clinical_observation ||
                    can.record_event,
            },
            {
                key: 'care_plans',
                label: 'Care Plans',
                icon: Target,
                show: true,
            },
            { key: 'calendar', label: 'Calendar', icon: Calendar, show: true },
            {
                key: 'progress_notes',
                label: 'Progress Notes',
                icon: ClipboardList,
                show: true,
            },
            {
                key: 'service_agreements',
                label: 'Agreements',
                icon: FileText,
                show: true,
            },
            {
                key: 'assessments',
                label: 'Assessments',
                icon: BookOpen,
                show: true,
            },
            { key: 'timeline', label: 'Timeline', icon: Activity, show: true },
            {
                key: 'documents',
                label: 'Documents',
                icon: FolderOpen,
                show: true,
                count: documents?.length,
            },
            {
                key: 'photos',
                label: 'Photos',
                icon: Camera,
                show: true,
                count: photos?.length,
            },
            {
                key: 'personal_assets',
                label: 'Personal Assets',
                icon: Package,
                show: true,
                count: personal_assets?.length,
            },
            {
                key: 'transport',
                label: 'Transport',
                icon: Truck,
                show: true,
                count: (transport?.stats?.transports_30d ?? 0) + (transport?.stats?.outings_30d ?? 0) || undefined,
            },
            { key: 'consents', label: 'Consents', icon: Shield, show: true },
            { key: 'location', label: 'Location', icon: MapPin, show: true },
            { key: 'portal', label: 'Family Portal', icon: Users, show: true },
            {
                key: 'family_notes',
                label: 'Family Notes',
                icon: ListTodo,
                show: true,
                count: familyNotesOpenCount,
            },
            {
                key: 'respite',
                label: 'Respite',
                icon: Calendar,
                show: !!respiteCan?.viewAny,
            },
            {
                key: 'assignments',
                label: 'Workers',
                icon: Users,
                show: can.assign_workers || can.edit,
            },
        ],
        [
            can.assign_workers,
            can.edit,
            can.record_observation,
            can.record_clinical_observation,
            can.record_event,
            respiteCan?.viewAny,
            documents?.length,
            photos?.length,
            personal_assets?.length,
            onboarding?.total,
            familyNotesOpenCount,
            transport?.stats?.outings_30d,
            transport?.stats?.transports_30d,
        ],
    );

    // Support ?tab=onboarding deep linking from dashboard
    const initialTab =
        typeof window !== 'undefined'
            ? (new URLSearchParams(window.location.search).get(
                  'tab',
              ) as TabKey) || 'profile'
            : 'profile';
    const [tab, setTab] = useState<TabKey>(initialTab);

    // Lazy-load transport data when tab is first opened
    const [transportLoaded, setTransportLoaded] = useState(!!transport);
    const handleTabChange = useCallback((newTab: TabKey) => {
        setTab(newTab);
        if (newTab === 'transport' && !transportLoaded) {
            router.reload({ only: ['transport'], onSuccess: () => setTransportLoaded(true) });
        }
    }, [transportLoaded]);

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
    const [respondingId, setRespondingId] = useState<number | null>(null);
    const [responseText, setResponseText] = useState('');
    const [assigningId, setAssigningId] = useState<number | null>(null);
    const [apptData, setApptData] = useState({
        title: '',
        appointment_type: 'gp_visit',
        starts_at: '',
        ends_at: '',
        location: '',
        provider_name: '',
        description: '',
        share_with_family: true,
    });
    const [calendarEvent, setCalendarEvent] = useState<any>(null);

    const eventTypes = useMemo(() => {
        const types = new Set<string>();
        events.forEach((e) => {
            if (e.type) types.add(e.type);
        });
        return Array.from(types).sort();
    }, [events]);

    const filteredEvents = useMemo(() => {
        return events.filter((e) => {
            if (timelineTypeFilter !== 'all' && e.type !== timelineTypeFilter)
                return false;
            if (timelineSearch) {
                const q = timelineSearch.toLowerCase();
                const searchable = [e.subject, e.body, e.type, e.actor?.name]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase();
                if (!searchable.includes(q)) return false;
            }
            return true;
        });
    }, [events, timelineSearch, timelineTypeFilter]);

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: labels?.['client.plural'] ?? 'Clients',
                    href: '/clients',
                },
                { title: name, href: `/operations/clients/${client.id}` },
            ]}
        >
            <Head title={name} />

            <PageShell>
                {/* ── Hero Header ──────────────────────────────── */}
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 p-6 text-white md:p-8">
                    <div className="pointer-events-none absolute -top-16 -right-16 h-64 w-64 rounded-full bg-white/5" />
                    <div className="pointer-events-none absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-white/5" />
                    <div className="pointer-events-none absolute top-1/4 right-1/3 h-24 w-24 rounded-full bg-white/5" />

                    <div className="relative flex flex-col items-center gap-6 md:flex-row md:items-start">
                        {/* Avatar */}
                        <Avatar className="h-24 w-24 shrink-0 border-4 border-white/20 shadow-xl md:h-28 md:w-28">
                            <AvatarImage
                                src={
                                    client.avatar ??
                                    client.profile_photo_url ??
                                    undefined
                                }
                                alt={name}
                            />
                            <AvatarFallback className="bg-white/10 text-2xl font-bold text-white md:text-3xl">
                                {getInitials(name)}
                            </AvatarFallback>
                        </Avatar>

                        {/* Info */}
                        <div className="flex-1 text-center md:text-left">
                            <h1 className="text-2xl font-bold md:text-3xl">
                                {name}
                            </h1>
                            {client.preferred_name &&
                                client.preferred_name !== name && (
                                    <p className="mt-0.5 text-sm text-white/60">
                                        Preferred: {client.preferred_name}
                                    </p>
                                )}
                            {client.nhi_number && (
                                <p className="mt-0.5 text-sm text-white/60">
                                    NHI: {client.nhi_number}
                                </p>
                            )}

                            <div className="mt-3 flex flex-wrap items-center justify-center gap-2 md:justify-start">
                                <Badge
                                    className={
                                        client.status === 'active'
                                            ? 'border-emerald-300/30 bg-emerald-400/20 text-emerald-100'
                                            : client.status === 'onboarding'
                                              ? 'border-amber-300/30 bg-amber-400/20 text-amber-100'
                                              : 'border-white/20 bg-white/10 text-white/90'
                                    }
                                >
                                    {client.status}
                                </Badge>
                                {client.funding_type && (
                                    <Badge className="border-white/20 bg-white/10 text-white/90">
                                        {client.funding_type}
                                    </Badge>
                                )}
                                {client.service_context && (
                                    <Badge className="border-white/20 bg-white/10 text-white/90">
                                        {client.service_context.name}
                                    </Badge>
                                )}
                                {client.site && (
                                    <Badge className="border-white/20 bg-white/10 text-white/90">
                                        <Home className="mr-1 h-3 w-3" />
                                        {client.site.name}
                                    </Badge>
                                )}
                                {client.risk_level &&
                                    client.risk_level !== 'low' && (
                                        <Badge
                                            className={
                                                client.risk_level === 'critical'
                                                    ? 'border-red-300/30 bg-red-400/20 text-red-100'
                                                    : client.risk_level ===
                                                        'high'
                                                      ? 'border-orange-300/30 bg-orange-400/20 text-orange-100'
                                                      : 'border-yellow-300/30 bg-yellow-400/20 text-yellow-100'
                                            }
                                        >
                                            <ShieldAlert className="mr-1 h-3 w-3" />
                                            {client.risk_level} risk
                                        </Badge>
                                    )}
                                {client.safeguarding_flag && (
                                    <Badge className="border-red-300/30 bg-red-400/20 text-red-100">
                                        <Shield className="mr-1 h-3 w-3" />
                                        Safeguarding
                                    </Badge>
                                )}
                            </div>

                            {client.service_start_date && (
                                <p className="mt-2 flex items-center justify-center gap-1.5 text-sm text-white/60 md:justify-start">
                                    <Clock className="h-3.5 w-3.5" />
                                    Since{' '}
                                    {new Date(
                                        client.service_start_date,
                                    ).toLocaleDateString('en-NZ', {
                                        month: 'short',
                                        year: 'numeric',
                                    })}
                                </p>
                            )}
                        </div>

                        {/* Right: Actions + KPIs */}
                        <div className="flex flex-col items-center gap-3 md:items-end">
                            <div className="flex flex-wrap gap-2">
                                {client.phone && (
                                    <a href={`tel:${client.phone}`}>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="border-white/20 bg-white/10 text-white hover:bg-white/20"
                                        >
                                            <Phone className="mr-1.5 h-3.5 w-3.5" />
                                            Call
                                        </Button>
                                    </a>
                                )}
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="border-white/20 bg-white/10 text-white hover:bg-white/20"
                                    asChild
                                >
                                    <Link
                                        href={`/operations/clients/${client.id}/visit-requests`}
                                    >
                                        <Users className="mr-1.5 h-3.5 w-3.5" />
                                        Visits
                                        {pendingVisitCount > 0 ? (
                                                <span className="ml-1 rounded-full bg-amber-400 px-1.5 py-0.5 text-[10px] font-bold text-amber-900">
                                                    {pendingVisitCount}
                                                </span>
                                        ) : null}
                                    </Link>
                                </Button>
                                {can.edit && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="border-white/20 bg-white/10 text-white hover:bg-white/20"
                                        asChild
                                    >
                                        <Link
                                            href={`/operations/clients/${client.id}/edit`}
                                        >
                                            <Pencil className="mr-1.5 h-3.5 w-3.5" />
                                            Edit
                                        </Link>
                                    </Button>
                                )}
                            </div>

                            {/* KPI Stats */}
                            <div className="hidden gap-6 text-center md:flex">
                                <div>
                                    <p className="text-2xl font-bold">
                                        {(() => {
                                            const summary =
                                                (pageProps as any)
                                                    .care_plans_summary ?? {};
                                            return summary.active_plan
                                                ? 'Active'
                                                : '—';
                                        })()}
                                    </p>
                                    <p className="text-xs text-white/50">
                                        Care Plan
                                    </p>
                                </div>
                                <div>
                                    <p className="text-2xl font-bold">
                                        {(() => {
                                            const summary =
                                                (pageProps as any)
                                                    .care_plans_summary ?? {};
                                            const goals =
                                                summary.active_plan?.goals ??
                                                [];
                                            const done = goals.filter(
                                                (g: any) =>
                                                    g.status === 'completed',
                                            ).length;
                                            return goals.length > 0
                                                ? `${done}/${goals.length}`
                                                : '—';
                                        })()}
                                    </p>
                                    <p className="text-xs text-white/50">
                                        Goals
                                    </p>
                                </div>
                                <div>
                                    <p className="text-2xl font-bold">
                                        {shifts_summary?.next ? 'Yes' : '—'}
                                    </p>
                                    <p className="text-xs text-white/50">
                                        Next Shift
                                    </p>
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
                                photoForm.post(
                                    `/operations/clients/${client.id}/photo`,
                                    {
                                        forceFormData: true,
                                        preserveScroll: true,
                                    },
                                );
                            }}
                            className="hidden"
                        >
                            <Input
                                type="file"
                                accept="image/*"
                                id="client-photo"
                                onChange={(e) =>
                                    photoForm.setData(
                                        'photo',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </form>
                    )}
                </div>

                <ClientSafetyRibbon safety={safety} className="mt-4" />

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
                                        onClick={() => handleTabChange(t.key)}
                                        className={`inline-flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors ${
                                            isActive
                                                ? 'border-primary text-primary'
                                                : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground'
                                        }`}
                                    >
                                        <Icon className="h-3.5 w-3.5" />
                                        {t.label}
                                        {t.count != null && t.count > 0 && (
                                            <span
                                                className={`ml-0.5 rounded-full px-1.5 py-0.5 text-[10px] leading-none font-semibold ${
                                                    isActive
                                                        ? 'bg-primary/10 text-primary'
                                                        : 'bg-muted text-muted-foreground'
                                                }`}
                                            >
                                                {t.count}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                    </div>
                </div>

                {tab === 'profile' &&
                    (() => {
                        const summary = pageProps.care_plans_summary ?? {};
                        const activePlan = summary.active_plan;
                        const risks = pageProps.client_risks ?? [];
                        const incidents = pageProps.client_incidents ?? [];
                        const agreements = pageProps.client_agreements ?? [];
                        const profileConsents = pageProps.consents ?? [];
                        const notes = pageProps.client_progress_notes ?? [];

                        // Parse about me from care plan content
                        const planContent = activePlan?.content
                            ? typeof activePlan.content === 'string'
                                ? JSON.parse(activePlan.content || '{}')
                                : activePlan.content
                            : {};
                        const aboutMe = planContent.about_me ?? {};
                        const hasAboutMe = Object.values(aboutMe).some(
                            (v: any) => v && String(v).trim(),
                        );

                        // Goal stats
                        const goals = activePlan?.goals ?? [];
                        const goalsCompleted = goals.filter(
                            (g: any) => g.status === 'completed',
                        ).length;
                        const goalsPct =
                            goals.length > 0
                                ? Math.round(
                                      (goalsCompleted / goals.length) * 100,
                                  )
                                : 0;

                        // Risk donut data
                        const riskCounts: Record<string, number> = {};
                        risks.forEach((r: any) => {
                            riskCounts[r.severity] =
                                (riskCounts[r.severity] ?? 0) + 1;
                        });
                        const riskDonutSegments = Object.entries(
                            riskCounts,
                        ).map(([sev, count]) => ({
                            label: sev,
                            value: count,
                            color:
                                sev === 'critical'
                                    ? '#dc2626'
                                    : sev === 'high'
                                      ? '#ea580c'
                                      : sev === 'medium'
                                        ? '#d97706'
                                        : '#16a34a',
                        }));

                        // Budget from first active agreement
                        const activeAg = agreements.find(
                            (a: any) => a.status === 'active',
                        );
                        const budgetPct =
                            activeAg?.total_budget > 0
                                ? Math.round(
                                      ((activeAg.budget_used ?? 0) /
                                          activeAg.total_budget) *
                                          100,
                                  )
                                : 0;

                        // Active consents count
                        const activeConsents = profileConsents.filter(
                            (c: any) => c.status === 'given' && !c.is_expired,
                        ).length;

                        // Review countdown
                        const reviewDays = activePlan?.next_review_at
                            ? Math.ceil(
                                  (new Date(
                                      activePlan.next_review_at,
                                  ).getTime() -
                                      Date.now()) /
                                      86400000,
                              )
                            : null;

                        return (
                            <>
                                {/* Safeguarding Alert */}
                                {client.safeguarding_flag && (
                                    <div className="mb-4 flex items-center gap-3 rounded-xl border-2 border-red-300 bg-red-50 p-4">
                                        <ShieldAlert className="h-6 w-6 text-red-600" />
                                        <div>
                                            <p className="text-sm font-bold text-red-800">
                                                Safeguarding Alert
                                            </p>
                                            <p className="text-xs text-red-700">
                                                Active safeguarding concern.
                                                Follow protocols.
                                            </p>
                                        </div>
                                    </div>
                                )}

                                {/* Row 1: Quick Stats */}
                                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                                    {/* Care Plan Status */}
                                    <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-4">
                                        <p className="text-[10px] font-semibold tracking-wider text-violet-500 uppercase">
                                            Care Plan
                                        </p>
                                        <p className="mt-1 text-lg font-bold text-violet-900">
                                            {activePlan ? 'Active' : 'None'}
                                        </p>
                                        {reviewDays !== null && (
                                            <p
                                                className={`mt-0.5 text-xs ${reviewDays < 0 ? 'font-semibold text-red-600' : 'text-violet-600'}`}
                                            >
                                                Review:{' '}
                                                {reviewDays < 0
                                                    ? `${Math.abs(reviewDays)}d overdue`
                                                    : `${reviewDays}d`}
                                            </p>
                                        )}
                                    </div>

                                    {/* Goals */}
                                    <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-4">
                                        <p className="text-[10px] font-semibold tracking-wider text-violet-500 uppercase">
                                            Goals
                                        </p>
                                        <p className="mt-1 text-lg font-bold text-violet-900">
                                            {goalsCompleted}/{goals.length}
                                        </p>
                                        <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-violet-200">
                                            <div
                                                className="h-full rounded-full bg-violet-600 transition-all"
                                                style={{
                                                    width: `${goalsPct}%`,
                                                }}
                                            />
                                        </div>
                                    </div>

                                    {/* Shifts */}
                                    <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-4">
                                        <p className="text-[10px] font-semibold tracking-wider text-violet-500 uppercase">
                                            Shifts
                                        </p>
                                        <p className="mt-1 text-lg font-bold text-violet-900">
                                            {nextShiftSummary
                                                ? 'Upcoming'
                                                : 'None'}
                                        </p>
                                        {nextShiftSummary?.starts_at && (
                                            <p className="mt-0.5 text-xs text-violet-600">
                                                {new Date(
                                                    nextShiftSummary.starts_at,
                                                ).toLocaleDateString('en-NZ', {
                                                    weekday: 'short',
                                                    day: 'numeric',
                                                    month: 'short',
                                                })}
                                            </p>
                                        )}
                                        {nextShiftSummary && (
                                            <div className="mt-2 space-y-1 text-xs text-violet-700">
                                                <p className="font-medium capitalize">
                                                    {nextShiftTypeLabel}
                                                    {nextShiftSummary
                                                        .service_context?.name
                                                        ? ` • ${nextShiftSummary.service_context.name}`
                                                        : ''}
                                                </p>
                                                {nextShiftSummary.staff
                                                    ?.name && (
                                                    <p>
                                                        {
                                                            nextShiftSummary
                                                                .staff.name
                                                        }
                                                    </p>
                                                )}
                                                {nextShiftSummary.location && (
                                                    <p>
                                                        {
                                                            nextShiftSummary.location
                                                        }
                                                    </p>
                                                )}
                                                <p>
                                                    {nextShiftSummary.incomplete_task_count ??
                                                        0}{' '}
                                                    incomplete tasks
                                                    {' • '}
                                                    {nextShiftSummary.medication_administration_count ??
                                                        0}{' '}
                                                    meds
                                                    {' • '}
                                                    {nextShiftSummary.form_submission_count ??
                                                        0}{' '}
                                                    forms
                                                </p>
                                            </div>
                                        )}
                                    </div>

                                    {/* Risk Level — clickable dropdown */}
                                    <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-4">
                                        <p className="text-[10px] font-semibold tracking-wider text-violet-500 uppercase">
                                            Risk Level
                                        </p>
                                        <div className="mt-1">
                                            <Select
                                                value={client.risk_level ?? ''}
                                                onValueChange={(v) =>
                                                    router.patch(
                                                        `/operations/clients/${client.id}/quick-update`,
                                                        { risk_level: v },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    className={`h-8 w-full border-0 text-sm font-bold shadow-none ${
                                                        client.risk_level ===
                                                        'critical'
                                                            ? 'bg-red-100 text-red-700'
                                                            : client.risk_level ===
                                                                'high'
                                                              ? 'bg-red-100 text-red-700'
                                                              : client.risk_level ===
                                                                  'medium'
                                                                ? 'bg-amber-100 text-amber-700'
                                                                : client.risk_level ===
                                                                    'low'
                                                                  ? 'bg-emerald-100 text-emerald-700'
                                                                  : 'bg-slate-100 text-slate-500'
                                                    } rounded-full px-3`}
                                                >
                                                    <SelectValue placeholder="Set level..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="low">
                                                        Low
                                                    </SelectItem>
                                                    <SelectItem value="medium">
                                                        Medium
                                                    </SelectItem>
                                                    <SelectItem value="high">
                                                        High
                                                    </SelectItem>
                                                    <SelectItem value="critical">
                                                        Critical
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <p className="mt-0.5 text-xs text-violet-600">
                                            {risks.length} active risk
                                            {risks.length !== 1 ? 's' : ''}
                                        </p>
                                    </div>
                                </div>

                                {client.site && siteCoverageSummary ? (
                                    <Card className="mt-4 overflow-hidden border-indigo-200/70 bg-gradient-to-br from-white via-indigo-50/80 to-cyan-50/70">
                                        <CardHeader className="pb-3">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div>
                                                    <CardTitle className="text-base text-slate-950">
                                                        House coverage
                                                    </CardTitle>
                                                    <p className="mt-1 text-xs text-slate-600">
                                                        Demand versus assigned
                                                        supply for{' '}
                                                        {
                                                            siteCoverageSummary.site_name
                                                        }{' '}
                                                        over the next fortnight.
                                                    </p>
                                                </div>
                                                <div className="flex flex-wrap gap-2">
                                                    <Badge
                                                        variant={
                                                            siteCoverageSummary.under_covered_windows >
                                                            0
                                                                ? 'destructive'
                                                                : 'secondary'
                                                        }
                                                        className={
                                                            siteCoverageSummary.under_covered_windows >
                                                            0
                                                                ? ''
                                                                : 'bg-emerald-100 text-emerald-800'
                                                        }
                                                    >
                                                        {siteCoverageSummary.under_covered_windows >
                                                        0
                                                            ? `${siteCoverageSummary.under_covered_windows} at risk`
                                                            : 'Fully covered'}
                                                    </Badge>
                                                    <Button
                                                        asChild
                                                        size="sm"
                                                        variant="outline"
                                                    >
                                                        <Link
                                                            href={`/sites/${siteCoverageSummary.site_id}`}
                                                        >
                                                            View site
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </div>
                                        </CardHeader>
                                        <CardContent className="grid gap-4 lg:grid-cols-[1.1fr_0.9fr_1fr]">
                                            <div className="rounded-2xl border border-white/70 bg-white/80 p-4 shadow-sm">
                                                <div className="flex items-center gap-6">
                                                    <DonutChart
                                                        segments={
                                                            siteCoverageSegments
                                                        }
                                                        size={144}
                                                        strokeWidth={18}
                                                        centerLabel="windows"
                                                        centerValue={
                                                            siteCoverageSummary.total_windows
                                                        }
                                                    />
                                                    <div className="space-y-3 text-sm">
                                                        {siteCoverageSegments.map(
                                                            (segment) => (
                                                                <div
                                                                    key={
                                                                        segment.label
                                                                    }
                                                                    className="flex items-center justify-between gap-4"
                                                                >
                                                                    <span className="flex items-center gap-2 text-slate-700">
                                                                        <span
                                                                            className="h-2.5 w-2.5 rounded-full"
                                                                            style={{
                                                                                backgroundColor:
                                                                                    segment.color,
                                                                            }}
                                                                        />
                                                                        {
                                                                            segment.label
                                                                        }
                                                                    </span>
                                                                    <span className="font-semibold text-slate-950">
                                                                        {
                                                                            segment.value
                                                                        }
                                                                    </span>
                                                                </div>
                                                            ),
                                                        )}
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                                                <div className="rounded-2xl border border-white/70 bg-white/80 p-4 shadow-sm">
                                                    <HalfMoonGauge
                                                        value={siteCoverageRate}
                                                        label="Covered windows"
                                                        sublabel="exact + overstaffed"
                                                        size={150}
                                                        color="#6366f1"
                                                    />
                                                </div>
                                                <div className="rounded-2xl border border-white/70 bg-white/80 p-4 shadow-sm">
                                                    <div className="flex items-start justify-between gap-2">
                                                        <div>
                                                            <p className="text-[11px] font-semibold tracking-[0.16em] text-slate-500 uppercase">
                                                                Coverage risk
                                                            </p>
                                                            <p className="mt-1 text-2xl font-bold text-slate-950">
                                                                {
                                                                    siteCoverageRiskRate
                                                                }
                                                                %
                                                            </p>
                                                        </div>
                                                        <ProgressRing
                                                            value={
                                                                siteCoverageRiskRate
                                                            }
                                                            size={72}
                                                            color={
                                                                siteCoverageSummary.under_covered_windows >
                                                                0
                                                                    ? '#ef4444'
                                                                    : '#10b981'
                                                            }
                                                            label={
                                                                siteCoverageSummary.under_covered_windows >
                                                                0
                                                                    ? 'risk'
                                                                    : 'ok'
                                                            }
                                                        />
                                                    </div>
                                                    <p className="mt-2 text-xs text-slate-600">
                                                        Share of projected
                                                        windows below minimum
                                                        staffing. Largest single
                                                        gap:{' '}
                                                        <span className="font-semibold text-slate-950">
                                                            {
                                                                siteCoverageSummary.largest_missing_staff
                                                            }{' '}
                                                            staff
                                                        </span>
                                                        .
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="rounded-2xl border border-white/70 bg-white/80 p-4 shadow-sm">
                                                <div className="flex items-start justify-between gap-2">
                                                    <div>
                                                        <p className="text-[11px] font-semibold tracking-[0.16em] text-slate-500 uppercase">
                                                            Next risk windows
                                                        </p>
                                                        <p className="mt-1 text-sm text-slate-600">
                                                            The next
                                                            under-covered
                                                            periods affecting
                                                            this house.
                                                        </p>
                                                    </div>
                                                    <Button
                                                        asChild
                                                        size="sm"
                                                        variant="secondary"
                                                    >
                                                        <Link href="/operations/rostering/conflicts">
                                                            Open queue
                                                        </Link>
                                                    </Button>
                                                </div>

                                                {siteCoverageSummary.alerts
                                                    .length === 0 ? (
                                                    <div className="mt-4 rounded-xl border border-dashed border-emerald-200 bg-emerald-50/70 p-4 text-sm text-emerald-800">
                                                        No projected shortages
                                                        for this site right now.
                                                    </div>
                                                ) : (
                                                    <div className="mt-4 space-y-3">
                                                        {siteCoverageSummary.alerts.map(
                                                            (alert, index) => (
                                                                <div
                                                                    key={`${alert.rule_name}-${index}`}
                                                                    className="rounded-xl border border-slate-200/80 bg-slate-50/80 p-3"
                                                                >
                                                                    <div className="flex items-center justify-between gap-2">
                                                                        <div className="min-w-0">
                                                                            <p className="truncate text-sm font-semibold text-slate-950">
                                                                                {
                                                                                    alert.rule_name
                                                                                }
                                                                            </p>
                                                                            <p className="text-xs text-slate-600">
                                                                                {
                                                                                    alert.window_label
                                                                                }
                                                                            </p>
                                                                        </div>
                                                                        <Badge variant="destructive">
                                                                            Missing{' '}
                                                                            {
                                                                                alert.missing_staff
                                                                            }
                                                                        </Badge>
                                                                    </div>
                                                                    <div className="mt-3">
                                                                        <HorizontalBarChart
                                                                            items={[
                                                                                {
                                                                                    label: 'Required',
                                                                                    value: alert.required_staff,
                                                                                    color: '#6366f1',
                                                                                    maxValue:
                                                                                        Math.max(
                                                                                            alert.required_staff,
                                                                                            alert.assigned_staff,
                                                                                        ),
                                                                                },
                                                                                {
                                                                                    label: 'Assigned',
                                                                                    value: alert.assigned_staff,
                                                                                    color: '#10b981',
                                                                                    maxValue:
                                                                                        Math.max(
                                                                                            alert.required_staff,
                                                                                            alert.assigned_staff,
                                                                                        ),
                                                                                },
                                                                            ]}
                                                                            heightPerBar={
                                                                                22
                                                                            }
                                                                            color="#6366f1"
                                                                        />
                                                                    </div>
                                                                    {alert.starts_at &&
                                                                    alert.ends_at ? (
                                                                        <div className="mt-3">
                                                                            <Button
                                                                                asChild
                                                                                size="sm"
                                                                                variant="outline"
                                                                            >
                                                                                <Link
                                                                                    href={`/operations/shifts/create?site_id=${siteCoverageSummary.site_id}&starts_at=${encodeURIComponent(alert.starts_at)}&ends_at=${encodeURIComponent(alert.ends_at)}`}
                                                                                >
                                                                                    Create
                                                                                    cover
                                                                                    shift
                                                                                </Link>
                                                                            </Button>
                                                                        </div>
                                                                    ) : null}
                                                                </div>
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        </CardContent>
                                    </Card>
                                ) : null}

                                {recurringShiftSeries.length > 0 && (
                                    <Card className="mt-4 border-violet-200/70 bg-gradient-to-br from-violet-50/80 via-white to-fuchsia-50/70">
                                        <CardHeader className="pb-3">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <CardTitle className="text-base text-violet-950">
                                                        Recurring support
                                                    </CardTitle>
                                                    <p className="mt-1 text-xs text-violet-700">
                                                        Active recurring roster
                                                        patterns for this
                                                        client.
                                                    </p>
                                                </div>
                                                <Badge
                                                    variant="secondary"
                                                    className="bg-violet-100 text-violet-800"
                                                >
                                                    {
                                                        recurringShiftSeries.length
                                                    }{' '}
                                                    pattern
                                                    {recurringShiftSeries.length !==
                                                    1
                                                        ? 's'
                                                        : ''}
                                                </Badge>
                                            </div>
                                        </CardHeader>
                                        <CardContent className="grid gap-3 lg:grid-cols-3">
                                            {recurringShiftSeries.map(
                                                (series) => (
                                                    <div
                                                        key={series.id}
                                                        className="rounded-xl border border-violet-200/70 bg-white/80 p-4"
                                                    >
                                                        <div className="flex flex-wrap gap-2">
                                                            <Badge
                                                                variant="outline"
                                                                className="capitalize"
                                                            >
                                                                {shiftTypeLabel(
                                                                    series.shift_type,
                                                                )}
                                                            </Badge>
                                                            {series.open_occurrences_count >
                                                            0 ? (
                                                                <Badge className="bg-amber-100 text-amber-800">
                                                                    {
                                                                        series.open_occurrences_count
                                                                    }{' '}
                                                                    open
                                                                </Badge>
                                                            ) : null}
                                                            {series.active_replacements_count >
                                                            0 ? (
                                                                <Badge className="bg-blue-100 text-blue-800">
                                                                    {
                                                                        series.active_replacements_count
                                                                    }{' '}
                                                                    replacement
                                                                </Badge>
                                                            ) : null}
                                                        </div>

                                                        <div className="mt-3 space-y-1">
                                                            <div className="text-sm font-semibold text-violet-950">
                                                                {series.weekdays
                                                                    .map(
                                                                        weekdayLabel,
                                                                    )
                                                                    .join(', ')}
                                                                {series.starts_time &&
                                                                series.ends_time
                                                                    ? ` · ${seriesTimeLabel(series.starts_time, series.ends_time)}`
                                                                    : ''}
                                                            </div>
                                                            <div className="text-xs text-violet-700">
                                                                {series
                                                                    .service_context
                                                                    ?.name ??
                                                                    'No service context'}
                                                                {series.location
                                                                    ? ` · ${series.location}`
                                                                    : ''}
                                                            </div>
                                                            <div className="text-xs text-violet-700">
                                                                {series.staff
                                                                    ?.name
                                                                    ? `Primary staff ${series.staff.name}`
                                                                    : 'Open recurring pattern'}
                                                            </div>
                                                            <div className="text-xs text-violet-700">
                                                                {series.next_starts_at
                                                                    ? `Next ${new Date(
                                                                          series.next_starts_at,
                                                                      ).toLocaleDateString(
                                                                          'en-NZ',
                                                                          {
                                                                              weekday:
                                                                                  'short',
                                                                              day: 'numeric',
                                                                              month: 'short',
                                                                          },
                                                                      )}`
                                                                    : 'No future occurrence'}
                                                                {' • '}
                                                                {
                                                                    series.remaining_occurrences_count
                                                                }{' '}
                                                                remaining
                                                            </div>
                                                        </div>

                                                        <div className="mt-3">
                                                            <Link
                                                                href={`/operations/shifts/series/${series.id}`}
                                                                className="text-xs font-medium text-violet-700 underline underline-offset-4"
                                                            >
                                                                Open recurring
                                                                series
                                                            </Link>
                                                        </div>
                                                    </div>
                                                ),
                                            )}
                                        </CardContent>
                                    </Card>
                                )}

                                {/* Health Summary Card */}
                                {(can.record_observation || can.record_clinical_observation || can.record_event) && health_summary && (
                                    <div className="mt-4">
                                        <HealthSummaryCard summary={health_summary as HealthSummary} />
                                    </div>
                                )}

                                {/* Row 2: Main Dashboard Grid */}
                                <div className="mt-4 grid gap-4 lg:grid-cols-3">
                                    {/* LEFT COLUMN */}
                                    <div className="space-y-4 lg:col-span-2">
                                        {/* About Me Card */}
                                        {hasAboutMe && (
                                            <Card className="overflow-hidden border-violet-200">
                                                <div className="bg-gradient-to-r from-violet-500 to-purple-600 px-5 py-3">
                                                    <h3 className="text-sm font-semibold text-white">
                                                        About{' '}
                                                        {client.first_name}
                                                    </h3>
                                                    <p className="text-xs text-violet-200">
                                                        What matters most to
                                                        this person
                                                    </p>
                                                </div>
                                                <CardContent className="space-y-3 p-5">
                                                    {aboutMe.dreams && (
                                                        <div className="rounded-lg bg-violet-50 p-3">
                                                            <p className="text-[10px] font-bold tracking-wider text-violet-500 uppercase">
                                                                Dreams &amp;
                                                                Aspirations
                                                            </p>
                                                            <p className="mt-1 text-sm text-slate-700">
                                                                {aboutMe.dreams}
                                                            </p>
                                                        </div>
                                                    )}
                                                    <div className="grid gap-3 sm:grid-cols-2">
                                                        {aboutMe.important_to_me && (
                                                            <div className="rounded-lg bg-purple-50 p-3">
                                                                <p className="text-[10px] font-bold tracking-wider text-purple-500 uppercase">
                                                                    Important TO
                                                                    Me
                                                                </p>
                                                                <p className="mt-1 text-sm text-slate-700">
                                                                    {
                                                                        aboutMe.important_to_me
                                                                    }
                                                                </p>
                                                            </div>
                                                        )}
                                                        {aboutMe.important_for_me && (
                                                            <div className="rounded-lg bg-purple-50 p-3">
                                                                <p className="text-[10px] font-bold tracking-wider text-purple-500 uppercase">
                                                                    Important
                                                                    FOR Me
                                                                </p>
                                                                <p className="mt-1 text-sm text-slate-700">
                                                                    {
                                                                        aboutMe.important_for_me
                                                                    }
                                                                </p>
                                                            </div>
                                                        )}
                                                    </div>
                                                    {aboutMe.ideal_day && (
                                                        <div className="rounded-lg bg-violet-50 p-3">
                                                            <p className="text-[10px] font-bold tracking-wider text-violet-500 uppercase">
                                                                My Ideal Day
                                                            </p>
                                                            <p className="mt-1 text-sm text-slate-700">
                                                                {
                                                                    aboutMe.ideal_day
                                                                }
                                                            </p>
                                                        </div>
                                                    )}
                                                    <div className="grid gap-3 sm:grid-cols-2">
                                                        {aboutMe.likes && (
                                                            <div className="rounded-lg bg-emerald-50 p-3">
                                                                <p className="text-[10px] font-bold tracking-wider text-emerald-600 uppercase">
                                                                    Things I
                                                                    Like
                                                                </p>
                                                                <p className="mt-1 text-sm text-emerald-800">
                                                                    {
                                                                        aboutMe.likes
                                                                    }
                                                                </p>
                                                            </div>
                                                        )}
                                                        {aboutMe.dislikes && (
                                                            <div className="rounded-lg bg-red-50 p-3">
                                                                <p className="text-[10px] font-bold tracking-wider text-red-500 uppercase">
                                                                    Things I
                                                                    Don't Like
                                                                </p>
                                                                <p className="mt-1 text-sm text-red-800">
                                                                    {
                                                                        aboutMe.dislikes
                                                                    }
                                                                </p>
                                                            </div>
                                                        )}
                                                    </div>
                                                    {aboutMe.how_to_support && (
                                                        <div className="rounded-lg border border-violet-200 bg-white p-3">
                                                            <p className="text-[10px] font-bold tracking-wider text-violet-500 uppercase">
                                                                How to Support
                                                                Me Best
                                                            </p>
                                                            <p className="mt-1 text-sm text-slate-700">
                                                                {
                                                                    aboutMe.how_to_support
                                                                }
                                                            </p>
                                                        </div>
                                                    )}
                                                </CardContent>
                                            </Card>
                                        )}

                                        {/* Goals Progress Card */}
                                        {goals.length > 0 && (
                                            <Card className="overflow-hidden">
                                                <CardHeader className="flex flex-row items-center justify-between pb-2">
                                                    <CardTitle className="text-sm font-semibold">
                                                        Goals Progress
                                                    </CardTitle>
                                                    {activePlan && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="text-xs text-violet-600"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/operations/care-plans/${activePlan.id}`}
                                                            >
                                                                View Plan
                                                            </Link>
                                                        </Button>
                                                    )}
                                                </CardHeader>
                                                <CardContent>
                                                    <div className="flex items-start gap-6">
                                                        <div className="shrink-0">
                                                            <HalfMoonGauge
                                                                value={goalsPct}
                                                                label="Complete"
                                                                size={140}
                                                                color="#7c3aed"
                                                            />
                                                        </div>
                                                        <div className="flex-1">
                                                            <HorizontalBarChart
                                                                items={goals
                                                                    .slice(0, 6)
                                                                    .map(
                                                                        (
                                                                            g: any,
                                                                        ) => ({
                                                                            label:
                                                                                g
                                                                                    .title
                                                                                    .length >
                                                                                25
                                                                                    ? g.title.slice(
                                                                                          0,
                                                                                          25,
                                                                                      ) +
                                                                                      '...'
                                                                                    : g.title,
                                                                            value:
                                                                                g.progress_percentage ??
                                                                                0,
                                                                            maxValue: 100,
                                                                            color:
                                                                                g.status ===
                                                                                'completed'
                                                                                    ? '#16a34a'
                                                                                    : '#7c3aed',
                                                                        }),
                                                                    )}
                                                            />
                                                        </div>
                                                    </div>
                                                </CardContent>
                                            </Card>
                                        )}

                                        {/* Recent Activity Card */}
                                        <Card>
                                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                                <CardTitle className="text-sm font-semibold">
                                                    Recent Activity
                                                </CardTitle>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-xs text-violet-600"
                                                    onClick={() =>
                                                        setTab('timeline')
                                                    }
                                                >
                                                    View All
                                                </Button>
                                            </CardHeader>
                                            <CardContent>
                                                {notes.length === 0 &&
                                                incidents.length === 0 ? (
                                                    <p className="py-4 text-center text-xs text-muted-foreground">
                                                        No recent activity
                                                    </p>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {[
                                                            ...notes
                                                                .slice(0, 3)
                                                                .map(
                                                                    (
                                                                        n: any,
                                                                    ) => ({
                                                                        id:
                                                                            'n' +
                                                                            n.id,
                                                                        icon: '\u{1F4DD}',
                                                                        text: `${n.author?.name ?? 'Unknown'}: ${(n.content ?? '').slice(0, 80)}${(n.content ?? '').length > 80 ? '...' : ''}`,
                                                                        date: n.created_at,
                                                                    }),
                                                                ),
                                                            ...incidents
                                                                .slice(0, 2)
                                                                .map(
                                                                    (
                                                                        inc: any,
                                                                    ) => ({
                                                                        id:
                                                                            'i' +
                                                                            inc.id,
                                                                        icon: '\u26A0\uFE0F',
                                                                        text: `Incident: ${inc.type ?? 'Unknown'} (${inc.severity ?? ''})`,
                                                                        date: inc.occurred_at,
                                                                    }),
                                                                ),
                                                        ]
                                                            .sort(
                                                                (a, b) =>
                                                                    new Date(
                                                                        b.date,
                                                                    ).getTime() -
                                                                    new Date(
                                                                        a.date,
                                                                    ).getTime(),
                                                            )
                                                            .slice(0, 5)
                                                            .map((item) => (
                                                                <div
                                                                    key={
                                                                        item.id
                                                                    }
                                                                    className="flex items-start gap-2 rounded-lg border border-slate-100 bg-slate-50/50 p-2.5 text-sm"
                                                                >
                                                                    <span className="shrink-0 text-base">
                                                                        {
                                                                            item.icon
                                                                        }
                                                                    </span>
                                                                    <span className="flex-1 text-xs text-slate-700">
                                                                        {
                                                                            item.text
                                                                        }
                                                                    </span>
                                                                    <span className="shrink-0 text-[10px] text-muted-foreground">
                                                                        {new Date(
                                                                            item.date,
                                                                        ).toLocaleDateString(
                                                                            'en-NZ',
                                                                            {
                                                                                day: 'numeric',
                                                                                month: 'short',
                                                                            },
                                                                        )}
                                                                    </span>
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
                                                {client.date_of_birth && (
                                                    <div className="flex justify-between">
                                                        <span className="text-muted-foreground">
                                                            DOB
                                                        </span>
                                                        <span>
                                                            {new Date(
                                                                client.date_of_birth,
                                                            ).toLocaleDateString(
                                                                'en-NZ',
                                                            )}
                                                            {(() => {
                                                                const b =
                                                                    new Date(
                                                                        client.date_of_birth!,
                                                                    );
                                                                const age =
                                                                    Math.floor(
                                                                        (Date.now() -
                                                                            b.getTime()) /
                                                                            31557600000,
                                                                    );
                                                                return ` (${age}y)`;
                                                            })()}
                                                        </span>
                                                    </div>
                                                )}
                                                {client.phone && (
                                                    <div className="flex justify-between">
                                                        <span className="text-muted-foreground">
                                                            Phone
                                                        </span>
                                                        <a
                                                            href={`tel:${client.phone}`}
                                                            className="text-primary hover:underline"
                                                        >
                                                            {client.phone}
                                                        </a>
                                                    </div>
                                                )}
                                                {client.email && (
                                                    <div className="flex justify-between">
                                                        <span className="text-muted-foreground">
                                                            Email
                                                        </span>
                                                        <a
                                                            href={`mailto:${client.email}`}
                                                            className="ml-2 truncate text-primary hover:underline"
                                                        >
                                                            {client.email}
                                                        </a>
                                                    </div>
                                                )}
                                                {(client.address_line_1 ||
                                                    client.city) && (
                                                    <div className="flex justify-between gap-2">
                                                        <span className="shrink-0 text-muted-foreground">
                                                            Address
                                                        </span>
                                                        <span className="text-right">
                                                            {client.address_line_1 && (
                                                                <>
                                                                    {
                                                                        client.address_line_1
                                                                    }
                                                                    <br />
                                                                </>
                                                            )}
                                                            {client.address_line_2 && (
                                                                <>
                                                                    {
                                                                        client.address_line_2
                                                                    }
                                                                    <br />
                                                                </>
                                                            )}
                                                            {[
                                                                client.suburb,
                                                                client.city,
                                                                client.postcode,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(', ')}
                                                        </span>
                                                    </div>
                                                )}
                                                {!client.address_line_1 &&
                                                    client.city && (
                                                        <div className="flex justify-between">
                                                            <span className="text-muted-foreground">
                                                                Location
                                                            </span>
                                                            <span>
                                                                {client.suburb
                                                                    ? `${client.suburb}, `
                                                                    : ''}
                                                                {client.city}
                                                            </span>
                                                        </div>
                                                    )}
                                                {client.gender && (
                                                    <div className="flex justify-between">
                                                        <span className="text-muted-foreground">
                                                            Gender
                                                        </span>
                                                        <span className="capitalize">
                                                            {client.gender}
                                                            {client.preferred_pronouns
                                                                ? ` (${client.preferred_pronouns})`
                                                                : ''}
                                                        </span>
                                                    </div>
                                                )}
                                                {client.key_worker && (
                                                    <div className="flex justify-between">
                                                        <span className="text-muted-foreground">
                                                            Key Worker
                                                        </span>
                                                        <span>
                                                            {
                                                                client
                                                                    .key_worker
                                                                    .name
                                                            }
                                                        </span>
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>

                                        {/* Risk & Safety */}
                                        {risks.length > 0 && (
                                            <Card>
                                                <CardHeader className="pb-2">
                                                    <CardTitle className="text-sm font-semibold">
                                                        Risk &amp; Safety
                                                    </CardTitle>
                                                </CardHeader>
                                                <CardContent>
                                                    <div className="flex justify-center">
                                                        <DonutChart
                                                            segments={
                                                                riskDonutSegments
                                                            }
                                                            size={110}
                                                            strokeWidth={16}
                                                            centerLabel="Risks"
                                                            centerValue={
                                                                risks.length
                                                            }
                                                        />
                                                    </div>
                                                    <div className="mt-3 flex flex-wrap justify-center gap-2">
                                                        {riskDonutSegments.map(
                                                            (seg) => (
                                                                <div
                                                                    key={
                                                                        seg.label
                                                                    }
                                                                    className="flex items-center gap-1 text-[10px]"
                                                                >
                                                                    <div
                                                                        className="h-2 w-2 rounded-full"
                                                                        style={{
                                                                            backgroundColor:
                                                                                seg.color,
                                                                        }}
                                                                    />
                                                                    <span className="capitalize">
                                                                        {
                                                                            seg.label
                                                                        }
                                                                        :{' '}
                                                                        {
                                                                            seg.value
                                                                        }
                                                                    </span>
                                                                </div>
                                                            ),
                                                        )}
                                                    </div>
                                                    {incidents.length > 0 && (
                                                        <div className="mt-3 rounded-lg bg-amber-50 p-2 text-center text-xs text-amber-700">
                                                            {incidents.length}{' '}
                                                            recent incident
                                                            {incidents.length !==
                                                            1
                                                                ? 's'
                                                                : ''}
                                                        </div>
                                                    )}
                                                </CardContent>
                                            </Card>
                                        )}

                                        {/* Support Team */}
                                        <Card>
                                            <CardHeader className="pb-2">
                                                <CardTitle className="text-sm font-semibold">
                                                    Support Team
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent className="space-y-2">
                                                {client.key_worker && (
                                                    <div className="flex items-center gap-2 rounded-lg bg-violet-50 p-2">
                                                        <div className="flex h-7 w-7 items-center justify-center rounded-full bg-violet-200 text-xs font-bold text-violet-700">
                                                            KW
                                                        </div>
                                                        <div>
                                                            <p className="text-xs font-medium">
                                                                {
                                                                    client
                                                                        .key_worker
                                                                        .name
                                                                }
                                                            </p>
                                                            <p className="text-[10px] text-violet-500">
                                                                Key Worker
                                                            </p>
                                                        </div>
                                                    </div>
                                                )}
                                                {(client.support_workers ?? [])
                                                    .slice(0, 4)
                                                    .map((sw: any) => (
                                                        <div
                                                            key={sw.id}
                                                            className="flex items-center gap-2 p-1"
                                                        >
                                                            <div className="flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-[10px] font-bold text-slate-500">
                                                                SW
                                                            </div>
                                                            <p className="text-xs">
                                                                {sw.name}
                                                            </p>
                                                        </div>
                                                    ))}
                                                {client.funding_type && (
                                                    <div className="mt-2 rounded bg-violet-50 px-2 py-1 text-center text-xs text-violet-600">
                                                        Funding:{' '}
                                                        {client.funding_type}
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>

                                        {/* Service Overview */}
                                        <Card>
                                            <CardHeader className="pb-2">
                                                <CardTitle className="text-sm font-semibold">
                                                    Service Overview
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                <div className="flex justify-center">
                                                    <ProgressRing
                                                        value={budgetPct}
                                                        size={90}
                                                        color="#7c3aed"
                                                        label="Budget Used"
                                                    />
                                                </div>
                                                <div className="mt-3 grid grid-cols-4 gap-2 text-center">
                                                    <div className="rounded-lg bg-slate-50 p-2">
                                                        <div className="text-sm font-bold text-violet-600">
                                                            {activeConsents}
                                                        </div>
                                                        <div className="text-[9px] text-muted-foreground uppercase">
                                                            Consents
                                                        </div>
                                                    </div>
                                                    <div className="rounded-lg bg-slate-50 p-2">
                                                        <div className="text-sm font-bold text-violet-600">
                                                            {
                                                                (
                                                                    documents ??
                                                                    []
                                                                ).length
                                                            }
                                                        </div>
                                                        <div className="text-[9px] text-muted-foreground uppercase">
                                                            Documents
                                                        </div>
                                                    </div>
                                                    <div className="rounded-lg bg-slate-50 p-2">
                                                        <div className="text-sm font-bold text-violet-600">
                                                            {
                                                                (
                                                                    assessments ??
                                                                    []
                                                                ).length
                                                            }
                                                        </div>
                                                        <div className="text-[9px] text-muted-foreground uppercase">
                                                            Assessments
                                                        </div>
                                                    </div>
                                                    <div
                                                        className="cursor-pointer rounded-lg bg-slate-50 p-2 transition-colors hover:bg-violet-50"
                                                        onClick={() =>
                                                            setTab(
                                                                'personal_assets',
                                                            )
                                                        }
                                                    >
                                                        <div className="text-sm font-bold text-violet-600">
                                                            {
                                                                (
                                                                    personal_assets ??
                                                                    []
                                                                ).filter(
                                                                    (
                                                                        a: PersonalAsset,
                                                                    ) =>
                                                                        a.status ===
                                                                        'active',
                                                                ).length
                                                            }
                                                        </div>
                                                        <div className="text-[9px] text-muted-foreground uppercase">
                                                            Assets
                                                        </div>
                                                    </div>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </div>
                                </div>

                                {/* Row 3: Health & Needs */}
                                {(client.mobility_needs ||
                                    client.sensory_needs ||
                                    client.cognitive_needs ||
                                    client.dietary_requirements ||
                                    client.sleep_preferences) && (
                                    <div className="mt-4 grid gap-3 sm:grid-cols-3">
                                        {(client.mobility_needs ||
                                            client.sensory_needs ||
                                            client.cognitive_needs) && (
                                            <Card className="border-violet-100">
                                                <CardContent className="p-4">
                                                    <p className="text-[10px] font-bold tracking-wider text-violet-500 uppercase">
                                                        Health &amp; Support
                                                        Needs
                                                    </p>
                                                    {client.mobility_needs && (
                                                        <p className="mt-2 text-xs">
                                                            <span className="font-medium">
                                                                Mobility:
                                                            </span>{' '}
                                                            {
                                                                client.mobility_needs
                                                            }
                                                        </p>
                                                    )}
                                                    {client.sensory_needs && (
                                                        <p className="mt-1 text-xs">
                                                            <span className="font-medium">
                                                                Sensory:
                                                            </span>{' '}
                                                            {
                                                                client.sensory_needs
                                                            }
                                                        </p>
                                                    )}
                                                    {client.cognitive_needs && (
                                                        <p className="mt-1 text-xs">
                                                            <span className="font-medium">
                                                                Cognitive:
                                                            </span>{' '}
                                                            {
                                                                client.cognitive_needs
                                                            }
                                                        </p>
                                                    )}
                                                </CardContent>
                                            </Card>
                                        )}
                                        {client.dietary_requirements && (
                                            <Card className="border-violet-100">
                                                <CardContent className="p-4">
                                                    <p className="text-[10px] font-bold tracking-wider text-violet-500 uppercase">
                                                        Dietary Requirements
                                                    </p>
                                                    <p className="mt-2 text-xs">
                                                        {
                                                            client.dietary_requirements
                                                        }
                                                    </p>
                                                </CardContent>
                                            </Card>
                                        )}
                                        {client.sleep_preferences && (
                                            <Card className="border-violet-100">
                                                <CardContent className="p-4">
                                                    <p className="text-[10px] font-bold tracking-wider text-violet-500 uppercase">
                                                        Sleep Preferences
                                                    </p>
                                                    <p className="mt-2 text-xs">
                                                        {
                                                            client.sleep_preferences
                                                        }
                                                    </p>
                                                </CardContent>
                                            </Card>
                                        )}
                                    </div>
                                )}

                                {/* Row 4: Identity & Culture */}
                                {(client.ethnicity ||
                                    client.preferred_pronouns ||
                                    client.religion ||
                                    (client.languages &&
                                        client.languages.length > 0) ||
                                    client.education_level ||
                                    client.employment_status ||
                                    client.gender) && (
                                    <div className="mt-4">
                                        <Card className="border-violet-100">
                                            <CardContent className="p-4">
                                                <p className="mb-3 text-[10px] font-bold tracking-wider text-violet-500 uppercase">
                                                    Identity &amp; Culture
                                                </p>
                                                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                    {client.gender && (
                                                        <div>
                                                            <p className="text-[10px] text-muted-foreground">
                                                                Gender
                                                            </p>
                                                            <p className="text-xs font-medium capitalize">
                                                                {client.gender}
                                                                {client.preferred_pronouns
                                                                    ? ` (${client.preferred_pronouns})`
                                                                    : ''}
                                                            </p>
                                                        </div>
                                                    )}
                                                    {!client.gender &&
                                                        client.preferred_pronouns && (
                                                            <div>
                                                                <p className="text-[10px] text-muted-foreground">
                                                                    Pronouns
                                                                </p>
                                                                <p className="text-xs font-medium">
                                                                    {
                                                                        client.preferred_pronouns
                                                                    }
                                                                </p>
                                                            </div>
                                                        )}
                                                    {client.ethnicity && (
                                                        <div>
                                                            <p className="text-[10px] text-muted-foreground">
                                                                Ethnicity
                                                            </p>
                                                            <p className="text-xs font-medium">
                                                                {
                                                                    client.ethnicity
                                                                }
                                                            </p>
                                                        </div>
                                                    )}
                                                    {client.religion && (
                                                        <div>
                                                            <p className="text-[10px] text-muted-foreground">
                                                                Religion
                                                            </p>
                                                            <p className="text-xs font-medium">
                                                                {
                                                                    client.religion
                                                                }
                                                            </p>
                                                        </div>
                                                    )}
                                                    {client.languages &&
                                                        client.languages
                                                            .length > 0 && (
                                                            <div>
                                                                <p className="text-[10px] text-muted-foreground">
                                                                    Languages
                                                                </p>
                                                                <div className="mt-0.5 flex flex-wrap gap-1">
                                                                    {client.languages.map(
                                                                        (
                                                                            lang: string,
                                                                        ) => (
                                                                            <Badge
                                                                                key={
                                                                                    lang
                                                                                }
                                                                                variant="secondary"
                                                                                className="text-[10px]"
                                                                            >
                                                                                {
                                                                                    lang
                                                                                }
                                                                            </Badge>
                                                                        ),
                                                                    )}
                                                                </div>
                                                            </div>
                                                        )}
                                                    {client.education_level && (
                                                        <div>
                                                            <p className="text-[10px] text-muted-foreground">
                                                                Education
                                                            </p>
                                                            <p className="text-xs font-medium capitalize">
                                                                {
                                                                    client.education_level
                                                                }
                                                            </p>
                                                        </div>
                                                    )}
                                                    {client.employment_status && (
                                                        <div>
                                                            <p className="text-[10px] text-muted-foreground">
                                                                Employment
                                                            </p>
                                                            <p className="text-xs font-medium capitalize">
                                                                {
                                                                    client.employment_status
                                                                }
                                                            </p>
                                                        </div>
                                                    )}
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </div>
                                )}

                                {/* Row 5: Interests & Strengths */}
                                {(client.interests_hobbies ||
                                    client.strengths_abilities ||
                                    client.life_story) && (
                                    <div className="mt-4">
                                        <Card className="border-violet-100">
                                            <CardContent className="p-4">
                                                <p className="mb-3 text-[10px] font-bold tracking-wider text-violet-500 uppercase">
                                                    Interests &amp; Strengths
                                                </p>
                                                <div className="space-y-3">
                                                    {client.interests_hobbies && (
                                                        <div>
                                                            <p className="text-[10px] font-medium text-muted-foreground">
                                                                Interests &amp;
                                                                Hobbies
                                                            </p>
                                                            <p className="mt-0.5 text-xs">
                                                                {
                                                                    client.interests_hobbies
                                                                }
                                                            </p>
                                                        </div>
                                                    )}
                                                    {client.strengths_abilities && (
                                                        <div>
                                                            <p className="text-[10px] font-medium text-muted-foreground">
                                                                Strengths &amp;
                                                                Abilities
                                                            </p>
                                                            <p className="mt-0.5 text-xs">
                                                                {
                                                                    client.strengths_abilities
                                                                }
                                                            </p>
                                                        </div>
                                                    )}
                                                    {client.life_story && (
                                                        <div>
                                                            <p className="text-[10px] font-medium text-muted-foreground">
                                                                Life Story
                                                            </p>
                                                            <p className="mt-0.5 text-xs">
                                                                {
                                                                    client.life_story
                                                                }
                                                            </p>
                                                        </div>
                                                    )}
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </div>
                                )}

                                {/* Row 6: Transport */}
                                {((client.transport_needs &&
                                    client.transport_needs.length > 0) ||
                                    client.transport_notes) && (
                                    <div className="mt-4">
                                        <Card className="border-violet-100">
                                            <CardContent className="p-4">
                                                <p className="mb-2 text-[10px] font-bold tracking-wider text-violet-500 uppercase">
                                                    Transport
                                                </p>
                                                {client.transport_needs &&
                                                    client.transport_needs
                                                        .length > 0 && (
                                                        <div className="mb-2 flex flex-wrap gap-1">
                                                            {client.transport_needs.map(
                                                                (
                                                                    need: string,
                                                                ) => (
                                                                    <Badge
                                                                        key={
                                                                            need
                                                                        }
                                                                        variant="secondary"
                                                                        className="text-[10px] capitalize"
                                                                    >
                                                                        {need}
                                                                    </Badge>
                                                                ),
                                                            )}
                                                        </div>
                                                    )}
                                                {client.transport_notes && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {client.transport_notes}
                                                    </p>
                                                )}
                                            </CardContent>
                                        </Card>
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
                                        <CardTitle className="text-base">
                                            Onboarding Workflow
                                        </CardTitle>
                                        <Badge
                                            variant={
                                                onboarding.workflow.status ===
                                                'completed'
                                                    ? 'secondary'
                                                    : 'default'
                                            }
                                            className="capitalize"
                                        >
                                            {onboarding.workflow.status?.replace(
                                                '_',
                                                ' ',
                                            )}
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center gap-4 text-sm text-muted-foreground">
                                        {onboarding.workflow.assigned_to && (
                                            <span>
                                                Coordinator:{' '}
                                                <strong>
                                                    {
                                                        onboarding.workflow
                                                            .assigned_to.name
                                                    }
                                                </strong>
                                            </span>
                                        )}
                                        {onboarding.workflow.started_at && (
                                            <span>
                                                Started:{' '}
                                                {new Date(
                                                    onboarding.workflow
                                                        .started_at,
                                                ).toLocaleDateString('en-NZ', {
                                                    day: 'numeric',
                                                    month: 'short',
                                                    year: 'numeric',
                                                })}
                                            </span>
                                        )}
                                    </div>
                                    {/* Progress bar */}
                                    {(() => {
                                        const steps =
                                            onboarding.workflow.steps ?? [];
                                        const done = steps.filter(
                                            (s: any) =>
                                                s.status === 'completed' ||
                                                s.status === 'skipped',
                                        ).length;
                                        const pct =
                                            steps.length > 0
                                                ? Math.round(
                                                      (done / steps.length) *
                                                          100,
                                                  )
                                                : 0;
                                        return (
                                            <div className="mt-3">
                                                <div className="flex justify-between text-xs text-muted-foreground">
                                                    <span>
                                                        {done}/{steps.length}{' '}
                                                        steps complete
                                                    </span>
                                                    <span>{pct}%</span>
                                                </div>
                                                <div className="mt-1 h-2 rounded-full bg-muted">
                                                    <div
                                                        className="h-2 rounded-full bg-indigo-500 transition-all"
                                                        style={{
                                                            width: `${pct}%`,
                                                        }}
                                                    />
                                                </div>
                                            </div>
                                        );
                                    })()}
                                </CardContent>
                            </Card>
                        ) : (
                            <Card>
                                <CardContent className="flex flex-col items-center justify-center py-8">
                                    <p className="text-sm text-muted-foreground">
                                        No onboarding workflow found.
                                    </p>
                                    {(can.manage_onboarding || can.edit) && (
                                        <Button
                                            size="sm"
                                            className="mt-3"
                                            onClick={() => {
                                                router.post(
                                                    `/operations/clients/${client.id}/onboarding-workflow`,
                                                    {},
                                                    { preserveScroll: true },
                                                );
                                            }}
                                        >
                                            Start Onboarding Workflow
                                        </Button>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Data Checklist */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Data Checklist
                                </CardTitle>
                                <p className="text-xs text-muted-foreground">
                                    Auto-detected from{' '}
                                    {(
                                        labels?.['client.singular'] ?? 'client'
                                    ).toLowerCase()}{' '}
                                    profile data
                                </p>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {(
                                    onboarding?.checklist?.items ??
                                    onboarding?.items ??
                                    []
                                ).map((item: any) => (
                                    <div
                                        key={item.key}
                                        className="flex items-center justify-between rounded-md border p-2"
                                    >
                                        <div className="flex items-center gap-2">
                                            <div
                                                className={`h-2 w-2 rounded-full ${item.complete ? 'bg-emerald-500' : 'bg-slate-300'}`}
                                            />
                                            <div>
                                                <div className="text-sm font-medium">
                                                    {item.label}
                                                </div>
                                                <div className="text-xs text-slate-500">
                                                    {item.complete
                                                        ? item.has_data
                                                            ? 'Added'
                                                            : 'Not applicable'
                                                        : 'Not completed'}
                                                </div>
                                            </div>
                                        </div>
                                        {!item.has_data &&
                                            (can.manage_onboarding ||
                                                can.edit) && (
                                                <label className="flex cursor-pointer items-center gap-2 text-xs text-slate-600">
                                                    <Checkbox
                                                        checked={item.override}
                                                        onCheckedChange={(
                                                            v,
                                                        ) => {
                                                            router.post(
                                                                `/operations/clients/${client.id}/onboarding/${item.key}`,
                                                                {
                                                                    checked:
                                                                        !!v,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
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
                                    <CardTitle className="text-base">
                                        Workflow Steps
                                    </CardTitle>
                                    <p className="text-xs text-muted-foreground">
                                        Manual steps tracked by staff
                                    </p>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {onboarding.workflow.steps.map(
                                        (step: any) => {
                                            const stepCategory =
                                                /DBS|Health Screening|GDPR|Safeguarding/i.test(
                                                    step.step_name ?? '',
                                                )
                                                    ? {
                                                          label: 'Compliance',
                                                          color: 'bg-purple-100 text-purple-700',
                                                      }
                                                    : /Referral|Assessment|Care Plan|Agreement|Staff|Introduction/i.test(
                                                            step.step_name ??
                                                                '',
                                                        )
                                                      ? {
                                                            label: 'Service',
                                                            color: 'bg-blue-100 text-blue-700',
                                                        }
                                                      : {
                                                            label: 'Admin',
                                                            color: 'bg-slate-100 text-slate-600',
                                                        };
                                            return (
                                                <div
                                                    key={step.id}
                                                    className={`flex items-center justify-between rounded-md border p-3 ${step.status === 'completed' ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900/30 dark:bg-emerald-950/20' : step.due_date && new Date(step.due_date) < new Date() && step.status === 'pending' ? 'border-red-200 bg-red-50/50 dark:border-red-900/30 dark:bg-red-950/20' : ''}`}
                                                >
                                                    <div className="flex items-center gap-3">
                                                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium">
                                                            {step.step_order}
                                                        </div>
                                                        <div>
                                                            <div className="flex items-center gap-2 text-sm font-medium">
                                                                <span
                                                                    className={`rounded px-1.5 py-0.5 text-[10px] font-medium ${stepCategory.color}`}
                                                                >
                                                                    {
                                                                        stepCategory.label
                                                                    }
                                                                </span>
                                                                {step.step_name}
                                                            </div>
                                                            {step.description && (
                                                                <div className="mt-0.5 text-xs text-slate-500">
                                                                    {
                                                                        step.description
                                                                    }
                                                                </div>
                                                            )}
                                                            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                                                {step.status ===
                                                                    'completed' &&
                                                                    step.completed_by && (
                                                                        <span>
                                                                            Completed
                                                                            by{' '}
                                                                            {
                                                                                step
                                                                                    .completed_by
                                                                                    .name
                                                                            }
                                                                        </span>
                                                                    )}
                                                                {step.completed_at && (
                                                                    <span>
                                                                        {new Date(
                                                                            step.completed_at,
                                                                        ).toLocaleDateString(
                                                                            'en-NZ',
                                                                            {
                                                                                day: 'numeric',
                                                                                month: 'short',
                                                                            },
                                                                        )}
                                                                    </span>
                                                                )}
                                                                {step.due_date &&
                                                                    step.status ===
                                                                        'pending' && (
                                                                        <span
                                                                            className={
                                                                                new Date(
                                                                                    step.due_date,
                                                                                ) <
                                                                                new Date()
                                                                                    ? 'font-medium text-red-600'
                                                                                    : ''
                                                                            }
                                                                        >
                                                                            Due:{' '}
                                                                            {new Date(
                                                                                step.due_date,
                                                                            ).toLocaleDateString(
                                                                                'en-NZ',
                                                                                {
                                                                                    day: 'numeric',
                                                                                    month: 'short',
                                                                                },
                                                                            )}
                                                                        </span>
                                                                    )}
                                                                {step.notes && (
                                                                    <span className="italic">
                                                                        "
                                                                        {
                                                                            step.notes
                                                                        }
                                                                        "
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <Badge
                                                            variant={
                                                                step.status ===
                                                                'completed'
                                                                    ? 'secondary'
                                                                    : step.status ===
                                                                        'skipped'
                                                                      ? 'outline'
                                                                      : 'default'
                                                            }
                                                            className="h-5 text-[10px] capitalize"
                                                        >
                                                            {step.status}
                                                        </Badge>
                                                        {step.status ===
                                                            'pending' &&
                                                            (can.manage_onboarding ||
                                                                can.edit) && (
                                                                <div className="flex gap-1">
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        className="h-7 text-xs"
                                                                        onClick={() => {
                                                                            router.patch(
                                                                                `/operations/onboarding/${onboarding.workflow.id}/steps/${step.id}`,
                                                                                {
                                                                                    status: 'completed',
                                                                                },
                                                                                {
                                                                                    preserveScroll: true,
                                                                                },
                                                                            );
                                                                        }}
                                                                    >
                                                                        Complete
                                                                    </Button>
                                                                    <Button
                                                                        size="sm"
                                                                        variant="ghost"
                                                                        className="h-7 text-xs text-muted-foreground"
                                                                        onClick={() => {
                                                                            router.patch(
                                                                                `/operations/onboarding/${onboarding.workflow.id}/steps/${step.id}`,
                                                                                {
                                                                                    status: 'skipped',
                                                                                },
                                                                                {
                                                                                    preserveScroll: true,
                                                                                },
                                                                            );
                                                                        }}
                                                                    >
                                                                        Skip
                                                                    </Button>
                                                                </div>
                                                            )}
                                                    </div>
                                                </div>
                                            );
                                        },
                                    )}
                                </CardContent>
                                {/* Complete Onboarding Button */}
                                {onboarding.workflow.status === 'in_progress' &&
                                    (can.manage_onboarding || can.edit) &&
                                    (() => {
                                        const requiredSteps =
                                            onboarding.workflow.steps.filter(
                                                (s: any) => s.is_required,
                                            );
                                        const allRequiredDone =
                                            requiredSteps.every(
                                                (s: any) =>
                                                    s.status === 'completed' ||
                                                    s.status === 'skipped',
                                            );
                                        return allRequiredDone ? (
                                            <div className="border-t p-4">
                                                <Button
                                                    className="w-full"
                                                    onClick={() => {
                                                        router.post(
                                                            `/operations/onboarding/${onboarding.workflow.id}/complete`,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }}
                                                >
                                                    Complete Onboarding — Set
                                                    Status to Active
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
                                    <p className="text-sm font-medium">
                                        Staff Preparation
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Staff training status and induction
                                        progress for assigned support workers
                                        will be shown here once HR integration
                                        is complete.
                                    </p>
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
                        {medical.profile?.allergies &&
                            medical.profile.allergies !== '-' && (
                                <div className="flex items-center gap-3 rounded-xl border-2 border-red-300 bg-red-50 p-4">
                                    <ShieldAlert className="h-6 w-6 shrink-0 text-red-600" />
                                    <div>
                                        <p className="text-sm font-bold text-red-800">
                                            Allergies
                                        </p>
                                        <p className="text-sm text-red-700">
                                            {medical.profile.allergies}
                                        </p>
                                    </div>
                                </div>
                            )}

                        {/* Quick Stats */}
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                                <div className="text-xl font-bold text-violet-700">
                                    {medical.medications?.length ?? 0}
                                </div>
                                <div className="text-[10px] tracking-wider text-violet-500 uppercase">
                                    Medications
                                </div>
                            </div>
                            <div className="rounded-xl border bg-gradient-to-br from-amber-50 to-yellow-50 p-3 text-center">
                                <div className="text-xl font-bold text-amber-700">
                                    {medical.conditions?.length ?? 0}
                                </div>
                                <div className="text-[10px] tracking-wider text-amber-500 uppercase">
                                    Conditions
                                </div>
                            </div>
                            <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-cyan-50 p-3 text-center">
                                <div className="text-xl font-bold text-blue-700">
                                    {medical.emergency_contacts?.length ?? 0}
                                </div>
                                <div className="text-[10px] tracking-wider text-blue-500 uppercase">
                                    Emergency Contacts
                                </div>
                            </div>
                            <div className="rounded-xl border bg-gradient-to-br from-cyan-50 to-teal-50 p-3 text-center">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="gap-1.5 text-xs"
                                    asChild
                                >
                                    <Link href="/emar">
                                        <Pill className="h-3.5 w-3.5" /> Open
                                        eMAR
                                    </Link>
                                </Button>
                            </div>
                        </div>

                        {/* Main Grid */}
                        <div className="grid gap-4 lg:grid-cols-3">
                            {/* Left Column — Profile + Medications */}
                            <div className="space-y-4 lg:col-span-2">
                                {/* GP Card */}
                                {(medical.profile?.gp_name ||
                                    medical.profile?.gp_practice) && (
                                    <Card className="border-emerald-200 bg-emerald-50/30">
                                        <CardContent className="p-4">
                                            <div className="mb-2 flex items-center gap-2">
                                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600">
                                                    <Heart className="h-4 w-4" />
                                                </div>
                                                <span className="text-sm font-semibold">
                                                    GP / Primary Care
                                                </span>
                                            </div>
                                            <div className="grid gap-2 text-sm sm:grid-cols-3">
                                                {medical.profile.gp_name && (
                                                    <div>
                                                        <p className="text-[10px] text-muted-foreground uppercase">
                                                            Doctor
                                                        </p>
                                                        <p className="font-medium">
                                                            {
                                                                medical.profile
                                                                    .gp_name
                                                            }
                                                        </p>
                                                    </div>
                                                )}
                                                {medical.profile
                                                    .gp_practice && (
                                                    <div>
                                                        <p className="text-[10px] text-muted-foreground uppercase">
                                                            Practice
                                                        </p>
                                                        <p className="font-medium">
                                                            {
                                                                medical.profile
                                                                    .gp_practice
                                                            }
                                                        </p>
                                                    </div>
                                                )}
                                                {medical.profile.gp_phone && (
                                                    <div>
                                                        <p className="text-[10px] text-muted-foreground uppercase">
                                                            Phone
                                                        </p>
                                                        <p className="font-medium">
                                                            {
                                                                medical.profile
                                                                    .gp_phone
                                                            }
                                                        </p>
                                                    </div>
                                                )}
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
                                            {can.edit && (
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
                                            )}
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            {[
                                                {
                                                    label: 'Medical History',
                                                    value: medical.profile
                                                        ?.medical_history,
                                                },
                                                {
                                                    label: 'Disabilities',
                                                    value: medical.profile
                                                        ?.disabilities,
                                                },
                                                {
                                                    label: 'Blood Type',
                                                    value: medical.profile
                                                        ?.blood_type,
                                                },
                                                {
                                                    label: 'Hospital Preference',
                                                    value: medical.profile
                                                        ?.hospital_preference,
                                                },
                                            ]
                                                .filter(
                                                    (f) =>
                                                        f.value &&
                                                        f.value !== '-',
                                                )
                                                .map((f) => (
                                                    <div
                                                        key={f.label}
                                                        className="rounded-lg bg-slate-50 p-3"
                                                    >
                                                        <p className="text-[10px] font-bold tracking-wider text-muted-foreground uppercase">
                                                            {f.label}
                                                        </p>
                                                        <p className="mt-1 text-sm">
                                                            {f.value}
                                                        </p>
                                                    </div>
                                                ))}
                                        </div>
                                        {medical.profile?.notes &&
                                            medical.profile.notes !== '-' && (
                                                <div className="mt-3 rounded-lg bg-slate-50 p-3">
                                                    <p className="text-[10px] font-bold tracking-wider text-muted-foreground uppercase">
                                                        Notes
                                                    </p>
                                                    <p className="mt-1 text-sm whitespace-pre-wrap">
                                                        {medical.profile.notes}
                                                    </p>
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
                                                <Badge
                                                    variant="secondary"
                                                    className="text-[10px]"
                                                >
                                                    {medical.medications
                                                        ?.length ?? 0}
                                                </Badge>
                                            </div>
                                            {can.edit && (
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
                                            )}
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {(medical.medications ?? []).length ===
                                        0 ? (
                                            <p className="py-4 text-center text-sm text-muted-foreground">
                                                No medications listed.
                                            </p>
                                        ) : (
                                            <div className="space-y-2">
                                                {medical.medications.map(
                                                    (m: any) => (
                                                        <div
                                                            key={m.id}
                                                            className="flex items-start gap-3 rounded-xl border-l-4 border-l-violet-400 bg-white p-3 shadow-sm"
                                                        >
                                                            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-100">
                                                                <Pill className="h-4 w-4 text-violet-600" />
                                                            </div>
                                                            <div className="flex-1">
                                                                <div className="flex items-center gap-2">
                                                                    <span className="text-sm font-semibold">
                                                                        {m.name}
                                                                    </span>
                                                                    {m.is_controlled && (
                                                                        <Badge className="border-0 bg-red-100 text-[9px] text-red-700">
                                                                            Controlled
                                                                        </Badge>
                                                                    )}
                                                                    {m.is_prn && (
                                                                        <Badge className="border-0 bg-amber-100 text-[9px] text-amber-700">
                                                                            PRN
                                                                        </Badge>
                                                                    )}
                                                                </div>
                                                                <div className="mt-0.5 flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                                                                    {m.dosage && (
                                                                        <span>
                                                                            {
                                                                                m.dosage
                                                                            }
                                                                        </span>
                                                                    )}
                                                                    {m.frequency && (
                                                                        <span>
                                                                            {
                                                                                m.frequency
                                                                            }
                                                                        </span>
                                                                    )}
                                                                    {m.route && (
                                                                        <span>
                                                                            {
                                                                                m.route
                                                                            }
                                                                        </span>
                                                                    )}
                                                                </div>
                                                                {m.instructions && (
                                                                    <p className="mt-1 text-xs text-slate-600">
                                                                        {
                                                                            m.instructions
                                                                        }
                                                                    </p>
                                                                )}
                                                            </div>
                                                        </div>
                                                    ),
                                                )}
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
                                            {can.edit && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-6 text-xs"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/operations/clients/${client.id}/medical?section=conditions`}
                                                    >
                                                        Manage
                                                    </Link>
                                                </Button>
                                            )}
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {(medical.conditions ?? []).length ===
                                        0 ? (
                                            <p className="py-4 text-center text-xs text-muted-foreground">
                                                No conditions listed.
                                            </p>
                                        ) : (
                                            <div className="space-y-2">
                                                {medical.conditions.map(
                                                    (c: any) => (
                                                        <div
                                                            key={c.id}
                                                            className="rounded-lg border p-2.5"
                                                        >
                                                            <div className="flex items-center justify-between">
                                                                <span className="text-xs font-medium">
                                                                    {c.label}
                                                                </span>
                                                                {c.severity && (
                                                                    <Badge
                                                                        className={`border-0 text-[9px] ${
                                                                            c.severity ===
                                                                            'severe'
                                                                                ? 'bg-red-100 text-red-700'
                                                                                : c.severity ===
                                                                                    'moderate'
                                                                                  ? 'bg-amber-100 text-amber-700'
                                                                                  : 'bg-emerald-100 text-emerald-700'
                                                                        }`}
                                                                    >
                                                                        {
                                                                            c.severity
                                                                        }
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            {c.notes && (
                                                                <p className="mt-1 text-[11px] text-muted-foreground">
                                                                    {c.notes}
                                                                </p>
                                                            )}
                                                        </div>
                                                    ),
                                                )}
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
                                            {can.edit && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-6 text-xs"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/operations/clients/${client.id}/medical?section=emergency_contacts`}
                                                    >
                                                        Manage
                                                    </Link>
                                                </Button>
                                            )}
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {(medical.emergency_contacts ?? [])
                                            .length === 0 ? (
                                            <p className="py-4 text-center text-xs text-muted-foreground">
                                                No emergency contacts listed.
                                            </p>
                                        ) : (
                                            <div className="space-y-2">
                                                {medical.emergency_contacts.map(
                                                    (e: any) => (
                                                        <div
                                                            key={e.id}
                                                            className="flex items-start gap-2.5 rounded-lg border p-2.5"
                                                        >
                                                            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700">
                                                                {(
                                                                    e.name ??
                                                                    '?'
                                                                ).charAt(0)}
                                                            </div>
                                                            <div className="flex-1 text-xs">
                                                                <div className="flex items-center gap-1.5">
                                                                    <span className="font-medium">
                                                                        {e.name}
                                                                    </span>
                                                                    {e.relationship && (
                                                                        <Badge
                                                                            variant="outline"
                                                                            className="h-4 px-1 text-[9px]"
                                                                        >
                                                                            {
                                                                                e.relationship
                                                                            }
                                                                        </Badge>
                                                                    )}
                                                                </div>
                                                                {e.phone && (
                                                                    <p className="mt-0.5 text-muted-foreground">
                                                                        {
                                                                            e.phone
                                                                        }
                                                                    </p>
                                                                )}
                                                                {e.email && (
                                                                    <p className="text-muted-foreground">
                                                                        {
                                                                            e.email
                                                                        }
                                                                    </p>
                                                                )}
                                                            </div>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </div>
                )}

                {tab === 'mar' &&
                    (() => {
                        const meds = medical?.medications ?? [];
                        const activeMeds = meds.filter(
                            (m: any) => m.active !== false,
                        );
                        const ceasedMeds = meds.filter(
                            (m: any) => m.active === false,
                        );
                        const prnMeds = activeMeds.filter((m: any) => m.is_prn);
                        const scheduledMeds = activeMeds.filter(
                            (m: any) => !m.is_prn,
                        );
                        const controlledMeds = activeMeds.filter(
                            (m: any) => m.controlled_drug,
                        );
                        const allergies = medical?.profile?.allergies ?? [];
                        const hasAllergies =
                            Array.isArray(allergies) && allergies.length > 0;

                        return (
                            <div className="space-y-4">
                                {/* Allergy Banner */}
                                {hasAllergies && (
                                    <div className="flex items-center gap-3 rounded-xl border-2 border-red-300 bg-gradient-to-r from-red-50 to-rose-50 p-4">
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-100 text-red-600">
                                            <AlertTriangle className="h-5 w-5" />
                                        </div>
                                        <div className="flex-1">
                                            <p className="text-sm font-semibold text-red-800">
                                                Allergies
                                            </p>
                                            <div className="mt-1 flex flex-wrap gap-1.5">
                                                {allergies.map((a: string) => (
                                                    <Badge
                                                        key={a}
                                                        className="border-0 bg-red-200/60 text-xs font-semibold text-red-800"
                                                    >
                                                        {a}
                                                    </Badge>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {/* Alerts Banner */}
                                {emarSummary &&
                                    emarSummary.pending_alerts_count > 0 && (
                                        <div className="flex items-center gap-3 rounded-xl border-2 border-amber-300 bg-gradient-to-r from-amber-50 to-orange-50 p-4">
                                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-600">
                                                <AlertTriangle className="h-5 w-5" />
                                            </div>
                                            <div className="flex-1">
                                                <p className="text-sm font-semibold text-amber-800">
                                                    {
                                                        emarSummary.pending_alerts_count
                                                    }{' '}
                                                    Active Medication Alert
                                                    {emarSummary.pending_alerts_count !==
                                                    1
                                                        ? 's'
                                                        : ''}
                                                </p>
                                                <p className="text-xs text-amber-700">
                                                    Review alerts in the full
                                                    eMAR dashboard.
                                                </p>
                                            </div>
                                            <Button
                                                size="sm"
                                                className="bg-amber-600 text-white hover:bg-amber-700"
                                                asChild
                                            >
                                                <Link
                                                    href={`/operations/clients/${client.id}/mar`}
                                                >
                                                    Review
                                                </Link>
                                            </Button>
                                        </div>
                                    )}

                                {/* Stats */}
                                {emarSummary && (
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                        <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-sky-50 p-4 text-center">
                                            <div className="text-3xl font-bold text-blue-700">
                                                {
                                                    emarSummary.active_medications_count
                                                }
                                            </div>
                                            <div className="text-[10px] tracking-wider text-blue-500 uppercase">
                                                Active Medications
                                            </div>
                                        </div>
                                        <div className="rounded-xl border bg-gradient-to-br from-emerald-50 to-green-50 p-4 text-center">
                                            <div className="text-sm font-bold text-emerald-700">
                                                {emarSummary.last_administration
                                                    ? new Date(
                                                          emarSummary.last_administration,
                                                      ).toLocaleDateString(
                                                          'en-NZ',
                                                          {
                                                              day: 'numeric',
                                                              month: 'short',
                                                              hour: '2-digit',
                                                              minute: '2-digit',
                                                          },
                                                      )
                                                    : '—'}
                                            </div>
                                            <div className="text-[10px] tracking-wider text-emerald-500 uppercase">
                                                Last Administration
                                            </div>
                                        </div>
                                        <div
                                            className={`rounded-xl border p-4 text-center ${controlledMeds.length > 0 ? 'bg-gradient-to-br from-rose-50 to-pink-50' : ''}`}
                                        >
                                            <div
                                                className={`text-3xl font-bold ${controlledMeds.length > 0 ? 'text-rose-700' : 'text-slate-400'}`}
                                            >
                                                {controlledMeds.length}
                                            </div>
                                            <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                                Controlled Drugs
                                            </div>
                                        </div>
                                        <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-4 text-center">
                                            <div className="text-sm font-bold text-violet-700">
                                                {emarSummary.next_review_date
                                                    ? new Date(
                                                          emarSummary.next_review_date,
                                                      ).toLocaleDateString(
                                                          'en-NZ',
                                                          {
                                                              day: 'numeric',
                                                              month: 'short',
                                                              year: 'numeric',
                                                          },
                                                      )
                                                    : 'Not scheduled'}
                                            </div>
                                            <div className="text-[10px] tracking-wider text-violet-500 uppercase">
                                                Next Review
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {/* Action Buttons */}
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        className="gap-1.5 bg-blue-600 hover:bg-blue-700"
                                        asChild
                                    >
                                        <Link
                                            href={`/operations/clients/${client.id}/mar`}
                                        >
                                            <Pill className="h-3.5 w-3.5" />
                                            Daily MAR
                                        </Link>
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="gap-1.5"
                                        asChild
                                    >
                                        <Link
                                            href={`/emar/mar?client_id=${client.id}`}
                                        >
                                            <ClipboardList className="h-3.5 w-3.5" />
                                            eMAR Dashboard
                                        </Link>
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="gap-1.5"
                                        asChild
                                    >
                                        <Link
                                            href={`/emar/controlled?client_id=${client.id}`}
                                        >
                                            <Shield className="h-3.5 w-3.5" />
                                            Controlled Drugs
                                        </Link>
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="gap-1.5"
                                        asChild
                                    >
                                        <Link
                                            href={`/emar/reviews?client_id=${client.id}`}
                                        >
                                            <BookOpen className="h-3.5 w-3.5" />
                                            Reviews
                                        </Link>
                                    </Button>
                                </div>

                                {/* Scheduled Medications */}
                                {scheduledMeds.length > 0 && (
                                    <Card className="overflow-hidden">
                                        <div className="bg-gradient-to-r from-blue-500 to-indigo-600 px-5 py-3">
                                            <div className="flex items-center justify-between">
                                                <div>
                                                    <h3 className="text-sm font-semibold text-white">
                                                        Scheduled Medications
                                                    </h3>
                                                    <p className="text-xs text-blue-200">
                                                        {scheduledMeds.length}{' '}
                                                        medication
                                                        {scheduledMeds.length !==
                                                        1
                                                            ? 's'
                                                            : ''}{' '}
                                                        on regular schedule
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <CardContent className="p-0">
                                            <div className="divide-y">
                                                {scheduledMeds.map((m: any) => (
                                                    <div
                                                        key={m.id}
                                                        className="flex items-center gap-4 px-5 py-3.5 transition-colors hover:bg-muted/30"
                                                    >
                                                        <div
                                                            className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${m.controlled_drug ? 'bg-rose-100 text-rose-600' : 'bg-blue-100 text-blue-600'}`}
                                                        >
                                                            <Pill className="h-5 w-5" />
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <span className="text-sm font-semibold">
                                                                    {m.name}
                                                                </span>
                                                                {m.controlled_drug && (
                                                                    <Badge className="gap-0.5 border-0 bg-rose-100 text-[9px] text-rose-700">
                                                                        <Shield className="h-2.5 w-2.5" />
                                                                        Controlled
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            <div className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                                                                {m.dosage && (
                                                                    <span className="font-medium text-foreground/70">
                                                                        {
                                                                            m.dosage
                                                                        }
                                                                    </span>
                                                                )}
                                                                {m.route && (
                                                                    <span>
                                                                        {
                                                                            m.route
                                                                        }
                                                                    </span>
                                                                )}
                                                                {m.form && (
                                                                    <span>
                                                                        {m.form}
                                                                    </span>
                                                                )}
                                                                {m.frequency && (
                                                                    <span className="text-blue-600">
                                                                        {
                                                                            m.frequency
                                                                        }
                                                                    </span>
                                                                )}
                                                            </div>
                                                            {m.instructions && (
                                                                <p className="mt-1 line-clamp-1 text-xs text-muted-foreground">
                                                                    {
                                                                        m.instructions
                                                                    }
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}

                                {/* PRN Medications */}
                                {prnMeds.length > 0 && (
                                    <Card className="overflow-hidden">
                                        <div className="bg-gradient-to-r from-indigo-500 to-purple-600 px-5 py-3">
                                            <div className="flex items-center justify-between">
                                                <div>
                                                    <h3 className="text-sm font-semibold text-white">
                                                        PRN (As Needed)
                                                    </h3>
                                                    <p className="text-xs text-indigo-200">
                                                        {prnMeds.length}{' '}
                                                        medication
                                                        {prnMeds.length !== 1
                                                            ? 's'
                                                            : ''}{' '}
                                                        available as needed
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <CardContent className="p-0">
                                            <div className="divide-y">
                                                {prnMeds.map((m: any) => (
                                                    <div
                                                        key={m.id}
                                                        className="flex items-center gap-4 px-5 py-3.5 transition-colors hover:bg-muted/30"
                                                    >
                                                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600">
                                                            <Pill className="h-5 w-5" />
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <span className="text-sm font-semibold">
                                                                    {m.name}
                                                                </span>
                                                                <Badge className="border-0 bg-indigo-100 text-[9px] text-indigo-700">
                                                                    PRN
                                                                </Badge>
                                                                {m.controlled_drug && (
                                                                    <Badge className="gap-0.5 border-0 bg-rose-100 text-[9px] text-rose-700">
                                                                        <Shield className="h-2.5 w-2.5" />
                                                                        Controlled
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            <div className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                                                                {m.dosage && (
                                                                    <span className="font-medium text-foreground/70">
                                                                        {
                                                                            m.dosage
                                                                        }
                                                                    </span>
                                                                )}
                                                                {m.route && (
                                                                    <span>
                                                                        {
                                                                            m.route
                                                                        }
                                                                    </span>
                                                                )}
                                                                {m.form && (
                                                                    <span>
                                                                        {m.form}
                                                                    </span>
                                                                )}
                                                            </div>
                                                            {m.prn_reason && (
                                                                <p className="mt-1 text-xs text-indigo-600">
                                                                    Indication:{' '}
                                                                    {
                                                                        m.prn_reason
                                                                    }
                                                                </p>
                                                            )}
                                                            {m.instructions && (
                                                                <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                                                                    {
                                                                        m.instructions
                                                                    }
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}

                                {/* Ceased Medications */}
                                {ceasedMeds.length > 0 && (
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm text-muted-foreground">
                                                Ceased Medications (
                                                {ceasedMeds.length})
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="p-0">
                                            <div className="divide-y">
                                                {ceasedMeds.map((m: any) => (
                                                    <div
                                                        key={m.id}
                                                        className="flex items-center gap-4 px-5 py-2.5 opacity-50"
                                                    >
                                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400">
                                                            <Pill className="h-4 w-4" />
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex items-center gap-2">
                                                                <span className="text-sm font-medium line-through">
                                                                    {m.name}
                                                                </span>
                                                                <Badge className="border-0 bg-slate-100 text-[9px] text-slate-500">
                                                                    Ceased
                                                                </Badge>
                                                            </div>
                                                            <div className="mt-0.5 text-xs text-muted-foreground">
                                                                {[
                                                                    m.dosage,
                                                                    m.route,
                                                                    m.form,
                                                                ]
                                                                    .filter(
                                                                        Boolean,
                                                                    )
                                                                    .join(
                                                                        ' · ',
                                                                    )}
                                                            </div>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}

                                {/* Empty state */}
                                {meds.length === 0 && (
                                    <Card className="border-dashed">
                                        <CardContent className="flex flex-col items-center justify-center py-16">
                                            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-50">
                                                <Pill className="h-8 w-8 text-blue-400" />
                                            </div>
                                            <p className="font-medium">
                                                No Medications
                                            </p>
                                            <p className="mt-1 max-w-sm text-center text-sm text-muted-foreground">
                                                No medications recorded for{' '}
                                                {client.first_name}. Add
                                                medications through the medical
                                                tab or eMAR system.
                                            </p>
                                            <Button
                                                size="sm"
                                                className="mt-4"
                                                asChild
                                            >
                                                <Link
                                                    href={`/emar/medications?client_id=${client.id}`}
                                                >
                                                    Add Medication
                                                </Link>
                                            </Button>
                                        </CardContent>
                                    </Card>
                                )}
                            </div>
                        );
                    })()}

                {tab === 'observations' && (
                    <ClientObservationsTab
                        clientId={client.id}
                        canRecordObservation={Boolean(
                            can.record_observation ||
                                can.record_clinical_observation,
                        )}
                        canRecordClinical={Boolean(
                            can.record_clinical_observation,
                        )}
                        canRecordEvent={Boolean(can.record_event)}
                    />
                )}

                {tab === 'care_plans' &&
                    (() => {
                        const summary = carePlansSummary;
                        const activePlan = summary.active_plan;
                        const recentNotes = summary.recent_notes ?? [];
                        const reviewDue = summary.review_due ?? false;
                        const goals = activePlan?.goals ?? [];
                        const goalsCompleted = goals.filter(
                            (g: any) => g.status === 'completed',
                        ).length;
                        const goalsInProgress = goals.filter(
                            (g: any) => g.status === 'in_progress',
                        ).length;
                        const goalsPct =
                            goals.length > 0
                                ? Math.round(
                                      (goalsCompleted / goals.length) * 100,
                                  )
                                : 0;
                        const avgProgress =
                            goals.length > 0
                                ? Math.round(
                                      goals.reduce(
                                          (s: number, g: any) =>
                                              s + (g.progress_percentage ?? 0),
                                          0,
                                      ) / goals.length,
                                  )
                                : 0;

                        // Build sparkline data from goal progress values (simulates progress over time)
                        const sparklineData =
                            goals.length > 0
                                ? goals
                                      .map(
                                          (g: any) =>
                                              g.progress_percentage ?? 0,
                                      )
                                      .sort((a: number, b: number) => a - b)
                                : [0, 0, 0];

                        const content = activePlan?.content
                            ? typeof activePlan.content === 'string'
                                ? JSON.parse(activePlan.content || '{}')
                                : activePlan.content
                            : {};
                        const aboutMe = content.about_me ?? {};
                        const hasAboutMe = Object.values(aboutMe).some(
                            (v: any) => v && String(v).trim(),
                        );
                        const reviewDays = activePlan?.next_review_at
                            ? Math.ceil(
                                  (new Date(
                                      activePlan.next_review_at,
                                  ).getTime() -
                                      Date.now()) /
                                      86400000,
                              )
                            : null;

                        return (
                            <div className="space-y-4">
                                {/* Review Due Alert */}
                                {reviewDue && activePlan && (
                                    <div className="flex items-center gap-3 rounded-xl border-2 border-amber-300 bg-gradient-to-r from-amber-50 to-orange-50 p-4">
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-600">
                                            <ShieldAlert className="h-5 w-5" />
                                        </div>
                                        <div className="flex-1">
                                            <p className="text-sm font-semibold text-amber-800">
                                                Care Plan Review Overdue
                                            </p>
                                            <p className="text-xs text-amber-700">
                                                This plan is due for review.
                                                Please update goals and
                                                strategies.
                                            </p>
                                        </div>
                                        <Button
                                            size="sm"
                                            className="bg-amber-600 text-white hover:bg-amber-700"
                                            asChild
                                        >
                                            <Link
                                                href={`/operations/care-plans/${activePlan.id}`}
                                            >
                                                Start Review
                                            </Link>
                                        </Button>
                                    </div>
                                )}

                                {activePlan ? (
                                    <>
                                        {/* Quick Stats */}
                                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                            <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                                                <div className="text-2xl font-bold text-violet-700">
                                                    {goalsPct}%
                                                </div>
                                                <div className="text-[10px] tracking-wider text-violet-500 uppercase">
                                                    Overall Progress
                                                </div>
                                            </div>
                                            <div className="rounded-xl border bg-gradient-to-br from-emerald-50 to-green-50 p-3 text-center">
                                                <div className="text-2xl font-bold text-emerald-700">
                                                    {goalsCompleted}/
                                                    {goals.length}
                                                </div>
                                                <div className="text-[10px] tracking-wider text-emerald-500 uppercase">
                                                    Goals Completed
                                                </div>
                                            </div>
                                            <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-indigo-50 p-3 text-center">
                                                <div className="text-2xl font-bold text-blue-700">
                                                    {goalsInProgress}
                                                </div>
                                                <div className="text-[10px] tracking-wider text-blue-500 uppercase">
                                                    In Progress
                                                </div>
                                            </div>
                                            <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                                                <div
                                                    className={`text-2xl font-bold ${reviewDays !== null && reviewDays < 0 ? 'text-red-600' : 'text-violet-700'}`}
                                                >
                                                    {reviewDays !== null
                                                        ? reviewDays < 0
                                                            ? `${Math.abs(reviewDays)}d`
                                                            : `${reviewDays}d`
                                                        : '—'}
                                                </div>
                                                <div className="text-[10px] tracking-wider text-violet-500 uppercase">
                                                    {reviewDays !== null &&
                                                    reviewDays < 0
                                                        ? 'Overdue'
                                                        : 'Until Review'}
                                                </div>
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
                                                                <h3 className="text-sm font-semibold text-white">
                                                                    {
                                                                        activePlan.title
                                                                    }
                                                                </h3>
                                                                <p className="text-xs text-violet-200">
                                                                    {(
                                                                        activePlan.plan_type ??
                                                                        ''
                                                                    ).replace(
                                                                        /_/g,
                                                                        ' ',
                                                                    )}{' '}
                                                                    · Version{' '}
                                                                    {activePlan.version ??
                                                                        1}
                                                                </p>
                                                            </div>
                                                            <Button
                                                                size="sm"
                                                                className="bg-white font-semibold text-violet-700 shadow-sm hover:bg-violet-100"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={`/operations/care-plans/${activePlan.id}`}
                                                                >
                                                                    View Full
                                                                    Plan
                                                                </Link>
                                                            </Button>
                                                        </div>
                                                    </div>
                                                    <CardContent className="p-5">
                                                        {/* Goal Progress Bar Chart */}
                                                        <div className="mb-4">
                                                            <p className="mb-3 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                                Goal Progress
                                                            </p>
                                                            <div className="space-y-3">
                                                                {[...goals]
                                                                    .sort(
                                                                        (
                                                                            a: any,
                                                                            b: any,
                                                                        ) =>
                                                                            (b.progress_percentage ??
                                                                                0) -
                                                                            (a.progress_percentage ??
                                                                                0),
                                                                    )
                                                                    .map(
                                                                        (
                                                                            g: any,
                                                                        ) => (
                                                                            <div
                                                                                key={
                                                                                    g.id
                                                                                }
                                                                            >
                                                                                <div className="mb-1 flex items-center justify-between">
                                                                                    <span className="max-w-[70%] truncate text-xs font-medium">
                                                                                        {
                                                                                            g.title
                                                                                        }
                                                                                    </span>
                                                                                    <span
                                                                                        className={`text-xs font-bold tabular-nums ${g.status === 'completed' ? 'text-emerald-600' : 'text-violet-600'}`}
                                                                                    >
                                                                                        {g.progress_percentage ??
                                                                                            0}

                                                                                        %
                                                                                    </span>
                                                                                </div>
                                                                                <div className="relative h-3 w-full overflow-hidden rounded-full bg-slate-100">
                                                                                    <div
                                                                                        className={`h-full rounded-full transition-all ${g.status === 'completed' ? 'bg-gradient-to-r from-emerald-400 to-emerald-500' : 'bg-gradient-to-r from-violet-400 to-purple-500'}`}
                                                                                        style={{
                                                                                            width: `${g.progress_percentage ?? 0}%`,
                                                                                        }}
                                                                                    />
                                                                                </div>
                                                                            </div>
                                                                        ),
                                                                    )}
                                                            </div>
                                                            {/* Summary row */}
                                                            <div className="mt-4 flex items-center justify-between rounded-xl bg-violet-50 px-4 py-3">
                                                                <div className="flex items-center gap-4">
                                                                    <span className="flex items-center gap-1.5 text-xs">
                                                                        <span className="h-3 w-3 rounded-full bg-gradient-to-r from-emerald-400 to-emerald-500" />{' '}
                                                                        Completed:{' '}
                                                                        {
                                                                            goalsCompleted
                                                                        }
                                                                    </span>
                                                                    <span className="flex items-center gap-1.5 text-xs">
                                                                        <span className="h-3 w-3 rounded-full bg-gradient-to-r from-violet-400 to-purple-500" />{' '}
                                                                        In
                                                                        Progress:{' '}
                                                                        {
                                                                            goalsInProgress
                                                                        }
                                                                    </span>
                                                                    <span className="flex items-center gap-1.5 text-xs">
                                                                        <span className="h-3 w-3 rounded-full bg-slate-300" />{' '}
                                                                        Not
                                                                        Started:{' '}
                                                                        {goals.length -
                                                                            goalsCompleted -
                                                                            goalsInProgress}
                                                                    </span>
                                                                </div>
                                                                <span className="text-xs font-bold text-violet-700">
                                                                    Avg:{' '}
                                                                    {
                                                                        avgProgress
                                                                    }
                                                                    %
                                                                </span>
                                                            </div>
                                                        </div>

                                                        {/* Removed duplicate goal bars — already shown in gradient bars above */}
                                                        <div className="hidden"></div>
                                                    </CardContent>
                                                </Card>

                                                {/* About Me */}
                                                {hasAboutMe && (
                                                    <Card className="overflow-hidden border-violet-200">
                                                        <div className="bg-gradient-to-r from-rose-400 to-pink-500 px-5 py-2.5">
                                                            <h3 className="text-sm font-semibold text-white">
                                                                About{' '}
                                                                {
                                                                    client.first_name
                                                                }
                                                            </h3>
                                                        </div>
                                                        <CardContent className="space-y-3 p-4">
                                                            {aboutMe.dreams && (
                                                                <div className="rounded-lg bg-violet-50 p-3">
                                                                    <p className="text-[10px] font-bold tracking-wider text-violet-500 uppercase">
                                                                        Dreams &
                                                                        Aspirations
                                                                    </p>
                                                                    <p className="mt-1 text-sm">
                                                                        {
                                                                            aboutMe.dreams
                                                                        }
                                                                    </p>
                                                                </div>
                                                            )}
                                                            <div className="grid gap-3 sm:grid-cols-2">
                                                                {aboutMe.likes && (
                                                                    <div className="rounded-lg bg-emerald-50 p-3">
                                                                        <p className="text-[10px] font-bold tracking-wider text-emerald-600 uppercase">
                                                                            Things
                                                                            I
                                                                            Like
                                                                        </p>
                                                                        <p className="mt-1 text-sm">
                                                                            {
                                                                                aboutMe.likes
                                                                            }
                                                                        </p>
                                                                    </div>
                                                                )}
                                                                {aboutMe.dislikes && (
                                                                    <div className="rounded-lg bg-red-50 p-3">
                                                                        <p className="text-[10px] font-bold tracking-wider text-red-500 uppercase">
                                                                            Things
                                                                            I
                                                                            Don
                                                                            {
                                                                                "'"
                                                                            }
                                                                            t
                                                                            Like
                                                                        </p>
                                                                        <p className="mt-1 text-sm">
                                                                            {
                                                                                aboutMe.dislikes
                                                                            }
                                                                        </p>
                                                                    </div>
                                                                )}
                                                            </div>
                                                            {aboutMe.how_to_support && (
                                                                <div className="rounded-lg border border-violet-200 bg-white p-3">
                                                                    <p className="text-[10px] font-bold tracking-wider text-violet-500 uppercase">
                                                                        How to
                                                                        Support
                                                                        Me
                                                                    </p>
                                                                    <p className="mt-1 text-sm">
                                                                        {
                                                                            aboutMe.how_to_support
                                                                        }
                                                                    </p>
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
                                                        <CardTitle className="text-sm font-semibold">
                                                            Plan Details
                                                        </CardTitle>
                                                    </CardHeader>
                                                    <CardContent className="space-y-2 text-xs">
                                                        <div className="flex justify-between">
                                                            <span className="text-muted-foreground">
                                                                Status
                                                            </span>
                                                            <Badge className="border-0 bg-emerald-100 text-[10px] text-emerald-700">
                                                                Active
                                                            </Badge>
                                                        </div>
                                                        <div className="flex justify-between">
                                                            <span className="text-muted-foreground">
                                                                Type
                                                            </span>
                                                            <span className="capitalize">
                                                                {(
                                                                    activePlan.plan_type ??
                                                                    ''
                                                                ).replace(
                                                                    /_/g,
                                                                    ' ',
                                                                )}
                                                            </span>
                                                        </div>
                                                        {activePlan.starts_at && (
                                                            <div className="flex justify-between">
                                                                <span className="text-muted-foreground">
                                                                    Started
                                                                </span>
                                                                <span>
                                                                    {new Date(
                                                                        activePlan.starts_at,
                                                                    ).toLocaleDateString(
                                                                        'en-NZ',
                                                                    )}
                                                                </span>
                                                            </div>
                                                        )}
                                                        {activePlan.next_review_at && (
                                                            <div className="flex justify-between">
                                                                <span className="text-muted-foreground">
                                                                    Next Review
                                                                </span>
                                                                <span
                                                                    className={
                                                                        reviewDue
                                                                            ? 'font-semibold text-red-600'
                                                                            : ''
                                                                    }
                                                                >
                                                                    {new Date(
                                                                        activePlan.next_review_at,
                                                                    ).toLocaleDateString(
                                                                        'en-NZ',
                                                                    )}
                                                                </span>
                                                            </div>
                                                        )}
                                                        <div className="flex justify-between">
                                                            <span className="text-muted-foreground">
                                                                Total Plans
                                                            </span>
                                                            <span>
                                                                {summary.total_plans ??
                                                                    0}
                                                            </span>
                                                        </div>
                                                        <div className="pt-2">
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                className="w-full text-xs"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={`/operations/care-plans?client_id=${client.id}`}
                                                                >
                                                                    View All
                                                                    Plans
                                                                </Link>
                                                            </Button>
                                                        </div>
                                                    </CardContent>
                                                </Card>

                                                {/* Recent Notes */}
                                                <Card>
                                                    <CardHeader className="pb-2">
                                                        <CardTitle className="flex items-center justify-between text-sm font-semibold">
                                                            <span>
                                                                Recent Notes
                                                            </span>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                className="h-6 text-[10px] text-violet-600"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={`/operations/progress-notes?client_id=${client.id}`}
                                                                >
                                                                    View All
                                                                </Link>
                                                            </Button>
                                                        </CardTitle>
                                                    </CardHeader>
                                                    <CardContent>
                                                        {recentNotes.length ===
                                                        0 ? (
                                                            <p className="py-4 text-center text-xs text-muted-foreground">
                                                                No notes yet.
                                                            </p>
                                                        ) : (
                                                            <div className="space-y-2">
                                                                {recentNotes
                                                                    .slice(0, 4)
                                                                    .map(
                                                                        (
                                                                            note: any,
                                                                        ) => (
                                                                            <div
                                                                                key={
                                                                                    note.id
                                                                                }
                                                                                className={`rounded-lg border p-2.5 text-xs ${note.is_flagged ? 'border-l-4 border-l-red-400' : ''}`}
                                                                            >
                                                                                <div className="flex items-center justify-between">
                                                                                    <span className="font-medium">
                                                                                        {note
                                                                                            .author
                                                                                            ?.name ??
                                                                                            'Unknown'}
                                                                                    </span>
                                                                                    <span className="text-[10px] text-muted-foreground">
                                                                                        {new Date(
                                                                                            note.created_at,
                                                                                        ).toLocaleDateString(
                                                                                            'en-NZ',
                                                                                        )}
                                                                                    </span>
                                                                                </div>
                                                                                {note.goal && (
                                                                                    <span className="mt-0.5 inline-block rounded bg-violet-50 px-1 py-0.5 text-[9px] text-violet-600">
                                                                                        {
                                                                                            note
                                                                                                .goal
                                                                                                .title
                                                                                        }
                                                                                    </span>
                                                                                )}
                                                                                <p className="mt-0.5 line-clamp-2 text-muted-foreground">
                                                                                    {
                                                                                        note.content
                                                                                    }
                                                                                </p>
                                                                            </div>
                                                                        ),
                                                                    )}
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
                                            <p className="font-medium">
                                                No Active Care Plan
                                            </p>
                                            <p className="mt-1 max-w-sm text-center text-sm text-muted-foreground">
                                                Create a care plan to start
                                                tracking goals and progress for{' '}
                                                {client.first_name}.
                                            </p>
                                            <Button
                                                size="sm"
                                                className="mt-4 bg-violet-600 hover:bg-violet-700"
                                                asChild
                                            >
                                                <Link
                                                    href={`/operations/care-plans/create?client_id=${client.id}`}
                                                >
                                                    Create Care Plan
                                                </Link>
                                            </Button>
                                        </CardContent>
                                    </Card>
                                )}
                            </div>
                        );
                    })()}

                {tab === 'calendar' && (
                    <ClientCalendarTab
                        clientId={client.id}
                        clientFirstName={client.first_name}
                        initialEvents={(pageProps as any).calendar_events ?? []}
                    />
                )}

                {tab === 'progress_notes' &&
                    (() => {
                        const notes = clientProgressNotes;
                        const flaggedCount = notes.filter(
                            (n: any) => n.is_flagged,
                        ).length;
                        const familyCount = notes.filter(
                            (n: any) => n.visibility === 'include_family',
                        ).length;

                        const EMOTIONS: Array<{
                            key: string;
                            emoji: string;
                            label: string;
                            color: string;
                        }> = [
                            {
                                key: 'happy',
                                emoji: '😊',
                                label: 'Happy',
                                color: 'bg-emerald-100 border-emerald-300 text-emerald-700',
                            },
                            {
                                key: 'calm',
                                emoji: '😌',
                                label: 'Calm',
                                color: 'bg-sky-100 border-sky-300 text-sky-700',
                            },
                            {
                                key: 'excited',
                                emoji: '🤩',
                                label: 'Excited',
                                color: 'bg-amber-100 border-amber-300 text-amber-700',
                            },
                            {
                                key: 'tired',
                                emoji: '😴',
                                label: 'Tired',
                                color: 'bg-indigo-100 border-indigo-300 text-indigo-700',
                            },
                            {
                                key: 'anxious',
                                emoji: '😰',
                                label: 'Anxious',
                                color: 'bg-orange-100 border-orange-300 text-orange-700',
                            },
                            {
                                key: 'sad',
                                emoji: '😢',
                                label: 'Sad',
                                color: 'bg-blue-100 border-blue-300 text-blue-700',
                            },
                            {
                                key: 'frustrated',
                                emoji: '😤',
                                label: 'Frustrated',
                                color: 'bg-red-100 border-red-300 text-red-700',
                            },
                            {
                                key: 'confused',
                                emoji: '😕',
                                label: 'Confused',
                                color: 'bg-purple-100 border-purple-300 text-purple-700',
                            },
                        ];

                        const EMOTION_MAP = Object.fromEntries(
                            EMOTIONS.map((e) => [e.key, e]),
                        );

                        // Time-based emotion analysis
                        const now = new Date();
                        const weekAgo = new Date(now.getTime() - 7 * 86400000);
                        const monthAgo = new Date(
                            now.getTime() - 30 * 86400000,
                        );

                        const getTopEmotion = (noteList: any[]) => {
                            const counts: Record<string, number> = {};
                            noteList.forEach((n: any) => {
                                (n.emotions ?? []).forEach((e: string) => {
                                    counts[e] = (counts[e] || 0) + 1;
                                });
                            });
                            const top = Object.entries(counts).sort(
                                ([, a], [, b]) => b - a,
                            )[0];
                            return top ? { key: top[0], count: top[1] } : null;
                        };

                        const weekNotes = notes.filter(
                            (n: any) => new Date(n.created_at) >= weekAgo,
                        );
                        const monthNotes = notes.filter(
                            (n: any) => new Date(n.created_at) >= monthAgo,
                        );
                        const topWeek = getTopEmotion(weekNotes);
                        const topMonth = getTopEmotion(monthNotes);

                        // Full emotion counts for the chart (all time)
                        const emotionCounts: Record<string, number> = {};
                        notes.forEach((n: any) => {
                            (n.emotions ?? []).forEach((e: string) => {
                                emotionCounts[e] = (emotionCounts[e] || 0) + 1;
                            });
                        });

                        const NOTE_TYPE_STYLES: Record<
                            string,
                            { border: string; bg: string; label: string }
                        > = {
                            general: {
                                border: 'border-l-violet-400',
                                bg: 'bg-violet-50',
                                label: 'General',
                            },
                            goal_update: {
                                border: 'border-l-indigo-400',
                                bg: 'bg-indigo-50',
                                label: 'Goal Update',
                            },
                            observation: {
                                border: 'border-l-blue-400',
                                bg: 'bg-blue-50',
                                label: 'Observation',
                            },
                            handover: {
                                border: 'border-l-cyan-400',
                                bg: 'bg-cyan-50',
                                label: 'Handover',
                            },
                            incident: {
                                border: 'border-l-red-400',
                                bg: 'bg-red-50',
                                label: 'Incident',
                            },
                        };

                        const toggleEmotion = (key: string) => {
                            setSelectedEmotions((prev) =>
                                prev.includes(key)
                                    ? prev.filter((e) => e !== key)
                                    : [...prev, key],
                            );
                        };

                        return (
                            <div className="space-y-4">
                                {/* Stats */}
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                    <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                                        <div className="text-xl font-bold text-violet-700">
                                            {notes.length}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-violet-500 uppercase">
                                            Total Notes
                                        </div>
                                    </div>
                                    <div className="rounded-xl border bg-gradient-to-br from-emerald-50 to-green-50 p-3 text-center">
                                        <div className="text-lg font-bold text-emerald-700">
                                            {topWeek
                                                ? `${EMOTION_MAP[topWeek.key]?.emoji ?? ''} ${EMOTION_MAP[topWeek.key]?.label ?? topWeek.key}`
                                                : '—'}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-emerald-500 uppercase">
                                            This Week
                                        </div>
                                    </div>
                                    <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-sky-50 p-3 text-center">
                                        <div className="text-lg font-bold text-blue-700">
                                            {topMonth
                                                ? `${EMOTION_MAP[topMonth.key]?.emoji ?? ''} ${EMOTION_MAP[topMonth.key]?.label ?? topMonth.key}`
                                                : '—'}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-blue-500 uppercase">
                                            This Month
                                        </div>
                                    </div>
                                    <div className="rounded-xl border p-3 text-center">
                                        <div
                                            className={`text-xl font-bold ${flaggedCount > 0 ? 'text-red-600' : 'text-slate-400'}`}
                                        >
                                            {flaggedCount}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                            Flagged
                                        </div>
                                    </div>
                                    <div className="rounded-xl border p-3 text-center">
                                        <div className="text-xl font-bold text-blue-600">
                                            {familyCount}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                            Family Visible
                                        </div>
                                    </div>
                                </div>

                                {/* Emotion Trends Chart */}
                                {Object.keys(emotionCounts).length > 0 && (
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm">
                                                Emotion Trends
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="space-y-2">
                                                {EMOTIONS.filter(
                                                    (e) => emotionCounts[e.key],
                                                )
                                                    .sort(
                                                        (a, b) =>
                                                            (emotionCounts[
                                                                b.key
                                                            ] || 0) -
                                                            (emotionCounts[
                                                                a.key
                                                            ] || 0),
                                                    )
                                                    .map((emotion) => {
                                                        const count =
                                                            emotionCounts[
                                                                emotion.key
                                                            ] || 0;
                                                        const maxCount =
                                                            Math.max(
                                                                ...Object.values(
                                                                    emotionCounts,
                                                                ),
                                                            );
                                                        const pct =
                                                            maxCount > 0
                                                                ? (count /
                                                                      maxCount) *
                                                                  100
                                                                : 0;
                                                        return (
                                                            <div
                                                                key={
                                                                    emotion.key
                                                                }
                                                                className="flex items-center gap-3"
                                                            >
                                                                <span className="w-6 text-center text-lg">
                                                                    {
                                                                        emotion.emoji
                                                                    }
                                                                </span>
                                                                <span className="w-20 text-xs font-medium">
                                                                    {
                                                                        emotion.label
                                                                    }
                                                                </span>
                                                                <div className="flex-1">
                                                                    <div className="h-5 overflow-hidden rounded-full bg-muted">
                                                                        <div
                                                                            className={`h-full rounded-full transition-all ${emotion.color.split(' ')[0]}`}
                                                                            style={{
                                                                                width: `${pct}%`,
                                                                            }}
                                                                        />
                                                                    </div>
                                                                </div>
                                                                <span className="w-8 text-right text-xs font-semibold text-muted-foreground">
                                                                    {count}
                                                                </span>
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
                                        <h3 className="text-sm font-semibold text-white">
                                            Add Progress Note
                                        </h3>
                                    </div>
                                    <CardContent className="p-4">
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="space-y-1">
                                                <Label className="text-xs">
                                                    Note Type
                                                </Label>
                                                <Select
                                                    defaultValue="general"
                                                    onValueChange={(v) => {
                                                        const el =
                                                            document.getElementById(
                                                                'pn-type',
                                                            ) as HTMLInputElement;
                                                        if (el) el.value = v;
                                                    }}
                                                >
                                                    <SelectTrigger className="h-8 text-xs">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="general">
                                                            General
                                                        </SelectItem>
                                                        <SelectItem value="goal_update">
                                                            Goal Update
                                                        </SelectItem>
                                                        <SelectItem value="observation">
                                                            Observation
                                                        </SelectItem>
                                                        <SelectItem value="handover">
                                                            Handover
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                                <input
                                                    id="pn-type"
                                                    type="hidden"
                                                    defaultValue="general"
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <Label className="text-xs">
                                                    Visibility
                                                </Label>
                                                <Select
                                                    defaultValue="staff_only"
                                                    onValueChange={(v) => {
                                                        const el =
                                                            document.getElementById(
                                                                'pn-vis',
                                                            ) as HTMLInputElement;
                                                        if (el) el.value = v;
                                                    }}
                                                >
                                                    <SelectTrigger className="h-8 text-xs">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="staff_only">
                                                            Staff Only
                                                        </SelectItem>
                                                        <SelectItem value="include_family">
                                                            Family Visible
                                                        </SelectItem>
                                                        <SelectItem value="private">
                                                            Private
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                                <input
                                                    id="pn-vis"
                                                    type="hidden"
                                                    defaultValue="staff_only"
                                                />
                                            </div>
                                        </div>

                                        {/* Emotion Picker */}
                                        <div className="mt-3">
                                            <Label className="text-xs">
                                                How is{' '}
                                                {client.preferred_name ||
                                                    client.first_name}{' '}
                                                feeling?
                                            </Label>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                {EMOTIONS.map((emotion) => {
                                                    const isSelected =
                                                        selectedEmotions.includes(
                                                            emotion.key,
                                                        );
                                                    return (
                                                        <button
                                                            key={emotion.key}
                                                            type="button"
                                                            onClick={() =>
                                                                toggleEmotion(
                                                                    emotion.key,
                                                                )
                                                            }
                                                            className={`inline-flex items-center gap-1.5 rounded-full border-2 px-3 py-1.5 text-xs font-medium transition-all ${
                                                                isSelected
                                                                    ? `${emotion.color} scale-105 shadow-sm`
                                                                    : 'border-border bg-card text-muted-foreground hover:border-primary/30'
                                                            }`}
                                                        >
                                                            <span className="text-base">
                                                                {emotion.emoji}
                                                            </span>
                                                            {emotion.label}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </div>

                                        <div className="mt-3">
                                            <Textarea
                                                id="pn-content"
                                                className="min-h-[80px] text-sm"
                                                placeholder="Write your progress note here..."
                                            />
                                        </div>
                                        <div className="mt-3 flex items-center justify-between">
                                            <p className="text-[10px] text-muted-foreground">
                                                Notes are saved immediately and
                                                visible to the care team.
                                            </p>
                                            <Button
                                                size="sm"
                                                className="gap-1.5 bg-violet-600 hover:bg-violet-700"
                                                onClick={() => {
                                                    const content = (
                                                        document.getElementById(
                                                            'pn-content',
                                                        ) as HTMLTextAreaElement
                                                    )?.value;
                                                    const noteType =
                                                        (
                                                            document.getElementById(
                                                                'pn-type',
                                                            ) as HTMLInputElement
                                                        )?.value || 'general';
                                                    const vis =
                                                        (
                                                            document.getElementById(
                                                                'pn-vis',
                                                            ) as HTMLInputElement
                                                        )?.value ||
                                                        'staff_only';
                                                    if (!content?.trim())
                                                        return;
                                                    router.post(
                                                        '/operations/progress-notes',
                                                        {
                                                            client_id:
                                                                client.id,
                                                            content,
                                                            note_type: noteType,
                                                            emotions:
                                                                selectedEmotions.length >
                                                                0
                                                                    ? selectedEmotions
                                                                    : null,
                                                            visibility: vis,
                                                        } as any,
                                                        {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                (
                                                                    document.getElementById(
                                                                        'pn-content',
                                                                    ) as HTMLTextAreaElement
                                                                ).value = '';
                                                                setSelectedEmotions(
                                                                    [],
                                                                );
                                                            },
                                                        },
                                                    );
                                                }}
                                            >
                                                Save Note
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>

                                {/* Header */}
                                <div className="flex items-center justify-between">
                                    <span className="text-sm font-medium">
                                        Recent Notes ({notes.length})
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="gap-1.5 text-xs"
                                        asChild
                                    >
                                        <Link
                                            href={`/operations/progress-notes?client_id=${client.id}`}
                                        >
                                            View All Notes
                                        </Link>
                                    </Button>
                                </div>

                                {/* Notes list */}
                                {notes.length === 0 ? (
                                    <Card className="border-dashed">
                                        <CardContent className="flex flex-col items-center justify-center py-12">
                                            <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-violet-50">
                                                <FileText className="h-7 w-7 text-violet-400" />
                                            </div>
                                            <p className="font-medium">
                                                No Progress Notes
                                            </p>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                Notes from shifts and care
                                                activities will appear here.
                                            </p>
                                        </CardContent>
                                    </Card>
                                ) : (
                                    <div className="space-y-2">
                                        {notes.slice(0, 5).map((note: any) => {
                                            const typeStyle = (NOTE_TYPE_STYLES[
                                                note.note_type
                                            ] ?? NOTE_TYPE_STYLES.general)!;
                                            return (
                                                <Card
                                                    key={note.id}
                                                    className={`overflow-hidden border-l-4 ${note.is_flagged ? 'border-l-red-500 bg-red-50/30' : typeStyle.border}`}
                                                >
                                                    <CardContent className="p-4">
                                                        <div className="flex items-start justify-between gap-3">
                                                            <div className="flex items-start gap-3">
                                                                {/* Avatar */}
                                                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-violet-100 text-xs font-bold text-violet-700">
                                                                    {(
                                                                        note
                                                                            .author
                                                                            ?.name ??
                                                                        '?'
                                                                    )
                                                                        .split(
                                                                            ' ',
                                                                        )
                                                                        .map(
                                                                            (
                                                                                w: string,
                                                                            ) =>
                                                                                w[0],
                                                                        )
                                                                        .join(
                                                                            '',
                                                                        )
                                                                        .slice(
                                                                            0,
                                                                            2,
                                                                        )}
                                                                </div>
                                                                <div>
                                                                    <div className="flex items-center gap-2">
                                                                        <span className="text-sm font-semibold">
                                                                            {note
                                                                                .author
                                                                                ?.name ??
                                                                                'Unknown'}
                                                                        </span>
                                                                        <Badge
                                                                            className={`border-0 text-[9px] ${typeStyle.bg} ${typeStyle.border.replace('border-l-', 'text-').replace('-400', '-700')}`}
                                                                        >
                                                                            {
                                                                                typeStyle.label
                                                                            }
                                                                        </Badge>
                                                                        {(
                                                                            note.emotions ??
                                                                            []
                                                                        )
                                                                            .length >
                                                                            0 &&
                                                                            (
                                                                                note.emotions ??
                                                                                []
                                                                            ).map(
                                                                                (
                                                                                    e: string,
                                                                                ) => (
                                                                                    <span
                                                                                        key={
                                                                                            e
                                                                                        }
                                                                                        className="text-sm"
                                                                                        title={
                                                                                            EMOTION_MAP[
                                                                                                e
                                                                                            ]
                                                                                                ?.label ??
                                                                                            e
                                                                                        }
                                                                                    >
                                                                                        {EMOTION_MAP[
                                                                                            e
                                                                                        ]
                                                                                            ?.emoji ??
                                                                                            e}
                                                                                    </span>
                                                                                ),
                                                                            )}
                                                                        {note.visibility ===
                                                                            'include_family' && (
                                                                            <Badge className="border-0 bg-blue-100 text-[9px] text-blue-700">
                                                                                Family
                                                                            </Badge>
                                                                        )}
                                                                        {note.is_flagged && (
                                                                            <Badge className="border-0 bg-red-100 text-[9px] text-red-700">
                                                                                Flagged
                                                                            </Badge>
                                                                        )}
                                                                    </div>
                                                                    {note.goal && (
                                                                        <span className="mt-0.5 inline-block rounded bg-violet-50 px-1.5 py-0.5 text-[10px] text-violet-600">
                                                                            Goal:{' '}
                                                                            {
                                                                                note
                                                                                    .goal
                                                                                    .title
                                                                            }
                                                                        </span>
                                                                    )}
                                                                    <p className="mt-1 text-xs leading-relaxed text-slate-600">
                                                                        {(
                                                                            note.content ??
                                                                            ''
                                                                        ).slice(
                                                                            0,
                                                                            300,
                                                                        )}
                                                                        {(
                                                                            note.content ??
                                                                            ''
                                                                        )
                                                                            .length >
                                                                        300
                                                                            ? '...'
                                                                            : ''}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                            <span className="shrink-0 text-[10px] text-muted-foreground">
                                                                {new Date(
                                                                    note.created_at,
                                                                ).toLocaleDateString(
                                                                    'en-NZ',
                                                                )}
                                                            </span>
                                                        </div>
                                                    </CardContent>
                                                </Card>
                                            );
                                        })}
                                        {notes.length > 5 && (
                                            <div className="flex justify-center pt-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="gap-1.5 text-xs"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/operations/progress-notes?client_id=${client.id}`}
                                                    >
                                                        View all {notes.length}{' '}
                                                        notes
                                                    </Link>
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })()}

                {tab === 'service_agreements' &&
                    (() => {
                        const agreements = clientAgreements;
                        const activeAgs = agreements.filter(
                            (a: any) => a.status === 'active',
                        );
                        const totalBudget = agreements.reduce(
                            (s: number, a: any) => s + (a.total_budget ?? 0),
                            0,
                        );
                        const totalUsed = agreements.reduce(
                            (s: number, a: any) => s + (a.budget_used ?? 0),
                            0,
                        );
                        const overallPct =
                            totalBudget > 0
                                ? Math.round((totalUsed / totalBudget) * 100)
                                : 0;
                        const expiringSoon = agreements.filter(
                            (a: any) =>
                                a.ends_at &&
                                new Date(a.ends_at).getTime() - Date.now() <
                                    30 * 86400000 &&
                                new Date(a.ends_at) > new Date(),
                        ).length;

                        return (
                            <div className="space-y-4">
                                {/* Stats */}
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                                        <div className="text-xl font-bold text-violet-700">
                                            {agreements.length}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-violet-500 uppercase">
                                            Total
                                        </div>
                                    </div>
                                    <div className="rounded-xl border bg-gradient-to-br from-emerald-50 to-green-50 p-3 text-center">
                                        <div className="text-xl font-bold text-emerald-700">
                                            {activeAgs.length}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-emerald-500 uppercase">
                                            Active
                                        </div>
                                    </div>
                                    <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                                        <div
                                            className={`text-xl font-bold ${overallPct > 90 ? 'text-red-600' : overallPct > 70 ? 'text-amber-600' : 'text-violet-700'}`}
                                        >
                                            {overallPct}%
                                        </div>
                                        <div className="text-[10px] tracking-wider text-violet-500 uppercase">
                                            Budget Used
                                        </div>
                                    </div>
                                    <div className="rounded-xl border p-3 text-center">
                                        <div
                                            className={`text-xl font-bold ${expiringSoon > 0 ? 'text-amber-600' : 'text-slate-400'}`}
                                        >
                                            {expiringSoon}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                            Expiring Soon
                                        </div>
                                    </div>
                                </div>

                                {/* Overall Budget Bar */}
                                {totalBudget > 0 && (
                                    <Card className="border-violet-200 bg-violet-50/30">
                                        <CardContent className="p-4">
                                            <div className="mb-2 flex items-center justify-between">
                                                <span className="text-sm font-semibold">
                                                    Total Funding Overview
                                                </span>
                                                <span className="text-sm font-bold text-violet-700">
                                                    $
                                                    {new Intl.NumberFormat(
                                                        'en-NZ',
                                                    ).format(totalUsed)}{' '}
                                                    / $
                                                    {new Intl.NumberFormat(
                                                        'en-NZ',
                                                    ).format(totalBudget)}{' '}
                                                    NZD
                                                </span>
                                            </div>
                                            <div className="h-4 w-full overflow-hidden rounded-full bg-violet-200">
                                                <div
                                                    className={`h-full rounded-full transition-all ${overallPct > 90 ? 'bg-red-500' : overallPct > 70 ? 'bg-amber-500' : 'bg-violet-600'}`}
                                                    style={{
                                                        width: `${Math.min(overallPct, 100)}%`,
                                                    }}
                                                />
                                            </div>
                                            <div className="mt-1 flex justify-between text-[10px] text-muted-foreground">
                                                <span>
                                                    Remaining: $
                                                    {new Intl.NumberFormat(
                                                        'en-NZ',
                                                    ).format(
                                                        totalBudget - totalUsed,
                                                    )}
                                                </span>
                                                <span>
                                                    {overallPct}% utilised
                                                </span>
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}

                                {/* Header */}
                                <div className="flex items-center justify-between">
                                    <span className="text-sm font-medium">
                                        Agreements ({agreements.length})
                                    </span>
                                    <Button
                                        size="sm"
                                        className="gap-1.5 bg-violet-600 hover:bg-violet-700"
                                        asChild
                                    >
                                        <Link
                                            href={`/operations/service-agreements/create?client_id=${client.id}`}
                                        >
                                            New Agreement
                                        </Link>
                                    </Button>
                                </div>

                                {/* Agreement Cards */}
                                {agreements.length === 0 ? (
                                    <Card className="border-dashed">
                                        <CardContent className="flex flex-col items-center justify-center py-12">
                                            <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-violet-50">
                                                <DollarSign className="h-7 w-7 text-violet-400" />
                                            </div>
                                            <p className="font-medium">
                                                No Service Agreements
                                            </p>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                Create a funding agreement for{' '}
                                                {client.first_name}.
                                            </p>
                                        </CardContent>
                                    </Card>
                                ) : (
                                    <div className="space-y-3">
                                        {agreements.map((ag: any) => {
                                            const budgetPct =
                                                ag.total_budget > 0
                                                    ? Math.round(
                                                          ((ag.budget_used ??
                                                              0) /
                                                              ag.total_budget) *
                                                              100,
                                                      )
                                                    : 0;
                                            const budgetColor =
                                                budgetPct > 90
                                                    ? 'bg-red-500'
                                                    : budgetPct > 70
                                                      ? 'bg-amber-500'
                                                      : 'bg-emerald-500';
                                            const isExpiring =
                                                ag.ends_at &&
                                                new Date(ag.ends_at).getTime() -
                                                    Date.now() <
                                                    30 * 86400000 &&
                                                new Date(ag.ends_at) >
                                                    new Date();
                                            const isExpired =
                                                ag.ends_at &&
                                                new Date(ag.ends_at) <
                                                    new Date();
                                            return (
                                                <Card
                                                    key={ag.id}
                                                    className={`overflow-hidden border-l-4 transition-all hover:shadow-sm ${ag.status === 'active' ? 'border-l-emerald-500' : 'border-l-slate-300'}`}
                                                >
                                                    <CardContent className="p-4">
                                                        <div className="flex items-start justify-between gap-3">
                                                            <div className="flex items-start gap-3">
                                                                <div
                                                                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${ag.status === 'active' ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-500'}`}
                                                                >
                                                                    <DollarSign className="h-5 w-5" />
                                                                </div>
                                                                <div>
                                                                    <div className="flex flex-wrap items-center gap-2">
                                                                        <span className="text-sm font-semibold">
                                                                            {
                                                                                ag.title
                                                                            }
                                                                        </span>
                                                                        <Badge
                                                                            className={`border-0 text-[9px] capitalize ${ag.status === 'active' ? 'bg-emerald-100 text-emerald-700' : ag.status === 'draft' ? 'bg-slate-100 text-slate-600' : 'bg-amber-100 text-amber-700'}`}
                                                                        >
                                                                            {
                                                                                ag.status
                                                                            }
                                                                        </Badge>
                                                                        {ag.funding_body && (
                                                                            <Badge
                                                                                variant="outline"
                                                                                className="text-[9px]"
                                                                            >
                                                                                {
                                                                                    ag.funding_body
                                                                                }
                                                                            </Badge>
                                                                        )}
                                                                        {isExpiring && (
                                                                            <Badge className="animate-pulse border-0 bg-amber-100 text-[9px] text-amber-700">
                                                                                Expiring
                                                                                Soon
                                                                            </Badge>
                                                                        )}
                                                                        {isExpired && (
                                                                            <Badge className="border-0 bg-red-100 text-[9px] text-red-700">
                                                                                Expired
                                                                            </Badge>
                                                                        )}
                                                                    </div>
                                                                    <div className="mt-0.5 flex gap-3 text-xs text-muted-foreground">
                                                                        {ag.reference_number && (
                                                                            <span>
                                                                                Ref:{' '}
                                                                                {
                                                                                    ag.reference_number
                                                                                }
                                                                            </span>
                                                                        )}
                                                                        {ag.starts_at && (
                                                                            <span>
                                                                                {new Date(
                                                                                    ag.starts_at,
                                                                                ).toLocaleDateString(
                                                                                    'en-NZ',
                                                                                )}{' '}
                                                                                —{' '}
                                                                                {ag.ends_at
                                                                                    ? new Date(
                                                                                          ag.ends_at,
                                                                                      ).toLocaleDateString(
                                                                                          'en-NZ',
                                                                                      )
                                                                                    : 'Ongoing'}
                                                                            </span>
                                                                        )}
                                                                        {ag.hourly_rate && (
                                                                            <span>
                                                                                $
                                                                                {
                                                                                    ag.hourly_rate
                                                                                }
                                                                                /hr
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                className="shrink-0 text-xs"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={`/operations/service-agreements/${ag.id}`}
                                                                >
                                                                    View
                                                                </Link>
                                                            </Button>
                                                        </div>
                                                        {ag.total_budget >
                                                            0 && (
                                                            <div className="mt-3">
                                                                <div className="mb-1 flex items-center justify-between text-xs">
                                                                    <span className="text-muted-foreground">
                                                                        Budget
                                                                        Utilisation
                                                                    </span>
                                                                    <span className="font-semibold">
                                                                        $
                                                                        {new Intl.NumberFormat(
                                                                            'en-NZ',
                                                                        ).format(
                                                                            ag.budget_used ??
                                                                                0,
                                                                        )}{' '}
                                                                        / $
                                                                        {new Intl.NumberFormat(
                                                                            'en-NZ',
                                                                        ).format(
                                                                            ag.total_budget,
                                                                        )}{' '}
                                                                        (
                                                                        {
                                                                            budgetPct
                                                                        }
                                                                        %)
                                                                    </span>
                                                                </div>
                                                                <div className="h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
                                                                    <div
                                                                        className={`h-full rounded-full ${budgetColor} transition-all`}
                                                                        style={{
                                                                            width: `${Math.min(budgetPct, 100)}%`,
                                                                        }}
                                                                    />
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
                                <div className="relative min-w-[180px] flex-1">
                                    <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                                    <Input
                                        placeholder="Search events..."
                                        value={timelineSearch}
                                        onChange={(e) =>
                                            setTimelineSearch(e.target.value)
                                        }
                                        className="h-8 pl-8 text-xs"
                                    />
                                </div>
                                <Select
                                    value={timelineTypeFilter}
                                    onValueChange={setTimelineTypeFilter}
                                >
                                    <SelectTrigger className="h-8 w-[160px] text-xs">
                                        <SelectValue placeholder="All types" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All types
                                        </SelectItem>
                                        {eventTypes.map((t) => (
                                            <SelectItem key={t} value={t}>
                                                {t}
                                            </SelectItem>
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
                                                        onSuccess: () => {
                                                            noteForm.reset();
                                                            noteForm.setData({
                                                                type: 'note',
                                                                subject: '',
                                                                goal: '',
                                                                body: '',
                                                                visibility:
                                                                    'internal',
                                                                pin: false,
                                                            });
                                                        },
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
                                    <p className="font-medium">
                                        {events.length
                                            ? 'No events match your filters'
                                            : 'No timeline events yet'}
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Events will appear here as care is
                                        delivered.
                                    </p>
                                </div>
                            ) : (
                                <div className="relative ml-4">
                                    {/* Vertical line */}
                                    <div className="absolute top-0 bottom-0 left-3 w-0.5 bg-gradient-to-b from-violet-300 via-violet-200 to-transparent" />

                                    <div className="space-y-0">
                                        {filteredEvents.map((e, idx) => {
                                            const TYPE_STYLES: Record<
                                                string,
                                                {
                                                    dot: string;
                                                    bg: string;
                                                    icon: string;
                                                }
                                            > = {
                                                note: {
                                                    dot: 'bg-violet-500',
                                                    bg: 'bg-violet-50',
                                                    icon: '📝',
                                                },
                                                progress_note: {
                                                    dot: 'bg-indigo-500',
                                                    bg: 'bg-indigo-50',
                                                    icon: '🎯',
                                                },
                                                handover: {
                                                    dot: 'bg-blue-500',
                                                    bg: 'bg-blue-50',
                                                    icon: '🤝',
                                                },
                                                incident: {
                                                    dot: 'bg-red-500',
                                                    bg: 'bg-red-50',
                                                    icon: '⚠️',
                                                },
                                                shift: {
                                                    dot: 'bg-emerald-500',
                                                    bg: 'bg-emerald-50',
                                                    icon: '📋',
                                                },
                                                medication: {
                                                    dot: 'bg-cyan-500',
                                                    bg: 'bg-cyan-50',
                                                    icon: '💊',
                                                },
                                                assessment: {
                                                    dot: 'bg-amber-500',
                                                    bg: 'bg-amber-50',
                                                    icon: '📊',
                                                },
                                            };
                                            const style = TYPE_STYLES[
                                                e.type
                                            ] ?? {
                                                dot: 'bg-slate-400',
                                                bg: 'bg-slate-50',
                                                icon: '📌',
                                            };

                                            // Date grouping
                                            const eventDate = e.occurred_at
                                                ? new Date(
                                                      e.occurred_at,
                                                  ).toLocaleDateString(
                                                      'en-NZ',
                                                      {
                                                          weekday: 'long',
                                                          day: 'numeric',
                                                          month: 'long',
                                                      },
                                                  )
                                                : '';
                                            const prevDate =
                                                idx > 0 &&
                                                filteredEvents[idx - 1]
                                                    .occurred_at
                                                    ? new Date(
                                                          filteredEvents[
                                                              idx - 1
                                                          ].occurred_at,
                                                      ).toLocaleDateString(
                                                          'en-NZ',
                                                          {
                                                              weekday: 'long',
                                                              day: 'numeric',
                                                              month: 'long',
                                                          },
                                                      )
                                                    : '';
                                            const showDateHeader =
                                                eventDate !== prevDate;

                                            return (
                                                <div key={e.id}>
                                                    {showDateHeader && (
                                                        <div className="relative mt-4 mb-2 flex items-center pl-8 first:mt-0">
                                                            <div className="absolute left-0 flex h-6 w-6 items-center justify-center rounded-full border-2 border-white bg-violet-200">
                                                                <div className="h-2 w-2 rounded-full bg-violet-500" />
                                                            </div>
                                                            <span className="text-xs font-semibold text-violet-600">
                                                                {eventDate}
                                                            </span>
                                                        </div>
                                                    )}
                                                    <div className="relative flex gap-3 pb-4 pl-8">
                                                        {/* Dot on timeline */}
                                                        <div
                                                            className={`absolute top-1 left-0 flex h-6 w-6 items-center justify-center rounded-full border-2 border-white ${style.dot} shadow-sm`}
                                                        >
                                                            <span className="text-[10px]">
                                                                {style.icon}
                                                            </span>
                                                        </div>
                                                        {/* Event card */}
                                                        <div
                                                            className={`flex-1 rounded-xl border ${style.bg} p-3 transition-all hover:shadow-sm`}
                                                        >
                                                            <div className="flex items-start justify-between gap-2">
                                                                <div>
                                                                    <div className="flex items-center gap-2">
                                                                        <span className="text-sm font-medium">
                                                                            {e.subject ||
                                                                                e.type}
                                                                        </span>
                                                                        <Badge
                                                                            variant="outline"
                                                                            className="h-4 px-1.5 text-[9px] capitalize"
                                                                        >
                                                                            {
                                                                                e.type
                                                                            }
                                                                        </Badge>
                                                                    </div>
                                                                    {e.actor
                                                                        ?.name && (
                                                                        <p className="mt-0.5 text-[11px] text-muted-foreground">
                                                                            {
                                                                                e
                                                                                    .actor
                                                                                    .name
                                                                            }
                                                                            {e
                                                                                .site
                                                                                ?.name
                                                                                ? ` · ${e.site.name}`
                                                                                : ''}
                                                                        </p>
                                                                    )}
                                                                </div>
                                                                <span className="shrink-0 text-[10px] text-muted-foreground">
                                                                    {e.occurred_at
                                                                        ? new Date(
                                                                              e.occurred_at,
                                                                          ).toLocaleTimeString(
                                                                              'en-NZ',
                                                                              {
                                                                                  hour: '2-digit',
                                                                                  minute: '2-digit',
                                                                              },
                                                                          )
                                                                        : ''}
                                                                </span>
                                                            </div>
                                                            {e.body && (
                                                                <p className="mt-1.5 text-xs leading-relaxed whitespace-pre-wrap text-slate-600">
                                                                    {e.body
                                                                        .length >
                                                                    250
                                                                        ? e.body.slice(
                                                                              0,
                                                                              250,
                                                                          ) +
                                                                          '...'
                                                                        : e.body}
                                                                </p>
                                                            )}
                                                            {e.meta?.emotions &&
                                                                (
                                                                    e.meta
                                                                        .emotions as string[]
                                                                ).length >
                                                                    0 && (
                                                                    <div className="mt-1.5 flex flex-wrap gap-1">
                                                                        {(
                                                                            e
                                                                                .meta
                                                                                .emotions as string[]
                                                                        ).map(
                                                                            (
                                                                                em: string,
                                                                            ) => {
                                                                                const emojiMap: Record<
                                                                                    string,
                                                                                    string
                                                                                > =
                                                                                    {
                                                                                        happy: '😊',
                                                                                        calm: '😌',
                                                                                        excited:
                                                                                            '🤩',
                                                                                        tired: '😴',
                                                                                        anxious:
                                                                                            '😰',
                                                                                        sad: '😢',
                                                                                        frustrated:
                                                                                            '😤',
                                                                                        confused:
                                                                                            '😕',
                                                                                    };
                                                                                const colorMap: Record<
                                                                                    string,
                                                                                    string
                                                                                > =
                                                                                    {
                                                                                        happy: 'bg-emerald-100 text-emerald-700',
                                                                                        calm: 'bg-sky-100 text-sky-700',
                                                                                        excited:
                                                                                            'bg-amber-100 text-amber-700',
                                                                                        tired: 'bg-indigo-100 text-indigo-700',
                                                                                        anxious:
                                                                                            'bg-orange-100 text-orange-700',
                                                                                        sad: 'bg-blue-100 text-blue-700',
                                                                                        frustrated:
                                                                                            'bg-red-100 text-red-700',
                                                                                        confused:
                                                                                            'bg-purple-100 text-purple-700',
                                                                                    };
                                                                                return (
                                                                                    <span
                                                                                        key={
                                                                                            em
                                                                                        }
                                                                                        className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ${colorMap[em] ?? 'bg-muted'}`}
                                                                                    >
                                                                                        {emojiMap[
                                                                                            em
                                                                                        ] ??
                                                                                            em}{' '}
                                                                                        {
                                                                                            em
                                                                                        }
                                                                                    </span>
                                                                                );
                                                                            },
                                                                        )}
                                                                    </div>
                                                                )}
                                                            {(e.comments
                                                                ?.length > 0 ||
                                                                e.reactions
                                                                    ?.length >
                                                                    0 ||
                                                                can.create_note) && (
                                                                <TimelineInteractions
                                                                    eventId={
                                                                        e.id
                                                                    }
                                                                    comments={
                                                                        e.comments ??
                                                                        []
                                                                    }
                                                                    reactions={
                                                                        e.reactions ??
                                                                        []
                                                                    }
                                                                    currentUserId={
                                                                        (
                                                                            auth as any
                                                                        )?.user
                                                                            ?.id
                                                                    }
                                                                    commentUrl={`/clients/${client.id}/timeline/${e.id}/comments`}
                                                                    deleteCommentUrl={`/clients/${client.id}/timeline/comments`}
                                                                    likeCommentUrl={`/clients/${client.id}/timeline/comments`}
                                                                    reactUrl={`/clients/${client.id}/timeline/${e.id}/react`}
                                                                    canComment={
                                                                        can.create_note
                                                                    }
                                                                    canReact={
                                                                        true
                                                                    }
                                                                    showStaffBadge={
                                                                        true
                                                                    }
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
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="gap-1.5 text-xs"
                                    asChild
                                >
                                    <Link
                                        href={`/clients/${client.id}/timeline`}
                                    >
                                        View Full Timeline
                                    </Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {tab === 'documents' &&
                    (() => {
                        const FILE_ICONS: Record<
                            string,
                            { color: string; bg: string }
                        > = {
                            pdf: { color: 'text-red-600', bg: 'bg-red-100' },
                            doc: { color: 'text-blue-600', bg: 'bg-blue-100' },
                            docx: { color: 'text-blue-600', bg: 'bg-blue-100' },
                            xls: {
                                color: 'text-emerald-600',
                                bg: 'bg-emerald-100',
                            },
                            xlsx: {
                                color: 'text-emerald-600',
                                bg: 'bg-emerald-100',
                            },
                            jpg: {
                                color: 'text-amber-600',
                                bg: 'bg-amber-100',
                            },
                            jpeg: {
                                color: 'text-amber-600',
                                bg: 'bg-amber-100',
                            },
                            png: {
                                color: 'text-amber-600',
                                bg: 'bg-amber-100',
                            },
                        };
                        const getFileStyle = (name?: string) => {
                            const ext =
                                (name ?? '').split('.').pop()?.toLowerCase() ??
                                '';
                            return (
                                FILE_ICONS[ext] ?? {
                                    color: 'text-violet-600',
                                    bg: 'bg-violet-100',
                                }
                            );
                        };
                        const CAT_COLORS: Record<string, string> = {
                            care_plan: 'bg-violet-100 text-violet-700',
                            assessment: 'bg-blue-100 text-blue-700',
                            medical: 'bg-red-100 text-red-700',
                            legal: 'bg-amber-100 text-amber-700',
                            policy: 'bg-emerald-100 text-emerald-700',
                            consent: 'bg-purple-100 text-purple-700',
                        };

                        const grouped = (documents ?? []).reduce(
                            (acc: Record<string, any[]>, d: any) => {
                                const folder = d.folder || 'Unfiled';
                                if (!acc[folder]) acc[folder] = [];
                                acc[folder].push(d);
                                return acc;
                            },
                            {} as Record<string, any[]>,
                        );

                        return (
                            <div className="space-y-4">
                                {/* Header */}
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-medium">
                                            {(documents ?? []).length} documents
                                        </span>
                                        {(documents ?? []).some(
                                            (d: any) =>
                                                d.expires_at &&
                                                new Date(d.expires_at) <
                                                    new Date(),
                                        ) && (
                                            <Badge className="border-0 bg-red-100 text-[10px] text-red-700">
                                                Has expired
                                            </Badge>
                                        )}
                                    </div>
                                    <Button
                                        size="sm"
                                        className="gap-1.5 bg-violet-600 hover:bg-violet-700"
                                        asChild
                                    >
                                        <Link
                                            href={`/operations/clients/${client.id}/documents`}
                                        >
                                            Manage Documents
                                        </Link>
                                    </Button>
                                </div>

                                {/* Grid */}
                                {(documents ?? []).length === 0 ? (
                                    <Card className="border-dashed">
                                        <CardContent className="flex flex-col items-center justify-center py-12">
                                            <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-violet-50">
                                                <FolderOpen className="h-7 w-7 text-violet-400" />
                                            </div>
                                            <p className="font-medium">
                                                No Documents
                                            </p>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                Upload documents for{' '}
                                                {client.first_name}.
                                            </p>
                                        </CardContent>
                                    </Card>
                                ) : (
                                    Object.entries(grouped).map(
                                        ([folder, docs]) => (
                                            <div key={folder}>
                                                <div className="mb-2 flex items-center gap-2">
                                                    <FolderOpen className="h-4 w-4 text-amber-500" />
                                                    <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        {folder}
                                                    </span>
                                                    <Badge
                                                        variant="secondary"
                                                        className="text-[10px]"
                                                    >
                                                        {(docs as any[]).length}
                                                    </Badge>
                                                </div>
                                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                                    {(docs as any[]).map(
                                                        (d: any) => {
                                                            const fi =
                                                                getFileStyle(
                                                                    d.original_name,
                                                                );
                                                            const expired =
                                                                d.expires_at &&
                                                                new Date(
                                                                    d.expires_at,
                                                                ) < new Date();
                                                            const expiring =
                                                                d.expires_at &&
                                                                !expired &&
                                                                new Date(
                                                                    d.expires_at,
                                                                ).getTime() -
                                                                    Date.now() <
                                                                    30 *
                                                                        86400000;
                                                            return (
                                                                <a
                                                                    key={d.id}
                                                                    href={`/operations/clients/${client.id}/documents/${d.id}/download`}
                                                                    className={`group rounded-xl border bg-white p-4 text-center transition-all hover:-translate-y-0.5 hover:shadow-md ${expired ? 'border-red-200' : expiring ? 'border-amber-200' : ''}`}
                                                                >
                                                                    <div
                                                                        className={`mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-xl ${fi.bg}`}
                                                                    >
                                                                        <FileText
                                                                            className={`h-6 w-6 ${fi.color}`}
                                                                        />
                                                                    </div>
                                                                    <p className="line-clamp-2 text-xs leading-tight font-medium">
                                                                        {d.title ||
                                                                            d.original_name}
                                                                    </p>
                                                                    <div className="mt-1.5 flex items-center justify-center gap-1">
                                                                        {d.portal_visible && (
                                                                            <Globe className="h-3 w-3 text-blue-500" />
                                                                        )}
                                                                        {expired && (
                                                                            <Badge className="h-4 border-0 bg-red-100 px-1 text-[8px] text-red-600">
                                                                                Expired
                                                                            </Badge>
                                                                        )}
                                                                        {expiring && (
                                                                            <Badge className="h-4 border-0 bg-amber-100 px-1 text-[8px] text-amber-600">
                                                                                Expiring
                                                                            </Badge>
                                                                        )}
                                                                        {d.category && (
                                                                            <Badge
                                                                                className={`h-4 border-0 px-1 text-[8px] ${CAT_COLORS[d.category] ?? 'bg-slate-100 text-slate-600'}`}
                                                                            >
                                                                                {d.category.replace(
                                                                                    /_/g,
                                                                                    ' ',
                                                                                )}
                                                                            </Badge>
                                                                        )}
                                                                    </div>
                                                                </a>
                                                            );
                                                        },
                                                    )}
                                                </div>
                                            </div>
                                        ),
                                    )
                                )}
                            </div>
                        );
                    })()}

                {tab === 'photos' && (
                    <PhotoGalleryTab
                        clientId={client.id}
                        photos={photos}
                        canEdit={can.edit}
                    />
                )}

                {tab === 'family_notes' &&
                    (() => {
                        const openNotes = familyNotes.filter((n: any) =>
                            ['open', 'in_progress'].includes(n.status),
                        );
                        const urgentCount = openNotes.filter(
                            (n: any) => n.priority === 'urgent',
                        ).length;
                        const overdueCount = openNotes.filter(
                            (n: any) => n.is_overdue,
                        ).length;
                        const completedThisWeek = familyNotes.filter(
                            (n: any) =>
                                n.status === 'completed' &&
                                n.completed_at &&
                                new Date(n.completed_at) >=
                                    new Date(Date.now() - 7 * 86400000),
                        ).length;
                        const upcomingShifts = shifts_summary?.next
                            ? [shifts_summary.next]
                            : [];

                        const NOTE_TYPES: Record<
                            string,
                            { emoji: string; label: string; color: string }
                        > = {
                            note: {
                                emoji: '📝',
                                label: 'Note',
                                color: 'bg-blue-100 text-blue-700',
                            },
                            todo: {
                                emoji: '✅',
                                label: 'To-Do',
                                color: 'bg-emerald-100 text-emerald-700',
                            },
                            request: {
                                emoji: '🙏',
                                label: 'Request',
                                color: 'bg-amber-100 text-amber-700',
                            },
                            reminder: {
                                emoji: '⏰',
                                label: 'Reminder',
                                color: 'bg-purple-100 text-purple-700',
                            },
                        };
                        const PRIORITY_COLORS: Record<string, string> = {
                            low: 'bg-slate-100 text-slate-600',
                            normal: 'bg-blue-100 text-blue-700',
                            high: 'bg-orange-100 text-orange-700',
                            urgent: 'bg-red-100 text-red-700',
                        };
                        const STATUS_COLORS: Record<string, string> = {
                            open: 'bg-blue-100 text-blue-700',
                            in_progress: 'bg-amber-100 text-amber-700',
                            completed: 'bg-emerald-100 text-emerald-700',
                            cancelled: 'bg-gray-100 text-gray-600',
                        };

                        return (
                            <div className="space-y-4">
                                {/* Stats */}
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-sky-50 p-3 text-center">
                                        <div className="text-xl font-bold text-blue-700">
                                            {openNotes.length}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-blue-500 uppercase">
                                            Open
                                        </div>
                                    </div>
                                    <div
                                        className={`rounded-xl border p-3 text-center ${urgentCount > 0 ? 'bg-gradient-to-br from-red-50 to-rose-50' : ''}`}
                                    >
                                        <div
                                            className={`text-xl font-bold ${urgentCount > 0 ? 'text-red-700' : 'text-slate-400'}`}
                                        >
                                            {urgentCount}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                            Urgent
                                        </div>
                                    </div>
                                    <div
                                        className={`rounded-xl border p-3 text-center ${overdueCount > 0 ? 'bg-gradient-to-br from-orange-50 to-amber-50' : ''}`}
                                    >
                                        <div
                                            className={`text-xl font-bold ${overdueCount > 0 ? 'text-orange-700' : 'text-slate-400'}`}
                                        >
                                            {overdueCount}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                            Overdue
                                        </div>
                                    </div>
                                    <div className="rounded-xl border bg-gradient-to-br from-emerald-50 to-green-50 p-3 text-center">
                                        <div className="text-xl font-bold text-emerald-700">
                                            {completedThisWeek}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-emerald-500 uppercase">
                                            Done This Week
                                        </div>
                                    </div>
                                </div>

                                {/* Notes list */}
                                {familyNotes.length === 0 ? (
                                    <Card className="border-dashed">
                                        <CardContent className="flex flex-col items-center justify-center py-12">
                                            <span className="mb-3 text-4xl">
                                                📝
                                            </span>
                                            <p className="font-medium">
                                                No family notes yet
                                            </p>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                Notes and to-dos from family
                                                members will appear here.
                                            </p>
                                        </CardContent>
                                    </Card>
                                ) : (
                                    <div className="space-y-2">
                                        {familyNotes.map((note: any) => {
                                            const typeInfo = (NOTE_TYPES[
                                                note.note_type
                                            ] ?? NOTE_TYPES.note)!;
                                            return (
                                                <Card
                                                    key={note.id}
                                                    className={`overflow-hidden transition-all hover:shadow-sm ${note.is_overdue ? 'border-red-300 bg-red-50/20' : note.status === 'completed' ? 'opacity-60' : ''}`}
                                                >
                                                    <CardContent className="p-4">
                                                        <div className="flex items-start justify-between gap-3">
                                                            <div className="min-w-0 flex-1">
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <span className="text-sm font-semibold">
                                                                        {
                                                                            note.title
                                                                        }
                                                                    </span>
                                                                    <span
                                                                        className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ${typeInfo.color}`}
                                                                    >
                                                                        {
                                                                            typeInfo.emoji
                                                                        }{' '}
                                                                        {
                                                                            typeInfo.label
                                                                        }
                                                                    </span>
                                                                    {note.priority !==
                                                                        'normal' && (
                                                                        <Badge
                                                                            className={`border-0 text-[9px] ${PRIORITY_COLORS[note.priority]}`}
                                                                        >
                                                                            {
                                                                                note.priority
                                                                            }
                                                                        </Badge>
                                                                    )}
                                                                    <Badge
                                                                        className={`border-0 text-[9px] capitalize ${STATUS_COLORS[note.status]}`}
                                                                    >
                                                                        {note.status.replace(
                                                                            '_',
                                                                            ' ',
                                                                        )}
                                                                    </Badge>
                                                                    {note.is_overdue && (
                                                                        <Badge className="gap-0.5 border-0 bg-red-100 text-[9px] text-red-700">
                                                                            <AlertTriangle className="h-2.5 w-2.5" />
                                                                            Overdue
                                                                        </Badge>
                                                                    )}
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="border-amber-200 bg-amber-50 text-[9px] text-amber-700"
                                                                    >
                                                                        Family
                                                                    </Badge>
                                                                </div>
                                                                {note.due_date && (
                                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                                        <Calendar className="mr-1 inline h-3 w-3" />
                                                                        Due:{' '}
                                                                        {new Date(
                                                                            note.due_date +
                                                                                'T00:00:00',
                                                                        ).toLocaleDateString(
                                                                            'en-NZ',
                                                                            {
                                                                                weekday:
                                                                                    'short',
                                                                                day: 'numeric',
                                                                                month: 'short',
                                                                            },
                                                                        )}
                                                                        {note.due_time
                                                                            ? ` at ${note.due_time}`
                                                                            : ''}
                                                                    </p>
                                                                )}
                                                                {note.description && (
                                                                    <p className="mt-1.5 text-sm text-muted-foreground">
                                                                        {note
                                                                            .description
                                                                            .length >
                                                                        200
                                                                            ? note.description.slice(
                                                                                  0,
                                                                                  200,
                                                                              ) +
                                                                              '...'
                                                                            : note.description}
                                                                    </p>
                                                                )}
                                                                {note.assigned_shift && (
                                                                    <div className="mt-1 rounded-md border border-violet-200 bg-violet-50/50 px-2 py-1 text-xs text-violet-700">
                                                                        <p className="font-medium">
                                                                            📋
                                                                            Assigned
                                                                            to{' '}
                                                                            {String(
                                                                                note
                                                                                    .assigned_shift
                                                                                    .shift_type ??
                                                                                    'standard',
                                                                            ).replace(
                                                                                /_/g,
                                                                                ' ',
                                                                            )}{' '}
                                                                            shift
                                                                        </p>
                                                                        <p className="text-violet-600">
                                                                            {note
                                                                                .assigned_shift
                                                                                .staff_name ??
                                                                                'Unassigned'}
                                                                            {note
                                                                                .assigned_shift
                                                                                .service_context
                                                                                ? ` · ${note.assigned_shift.service_context}`
                                                                                : ''}
                                                                            {note
                                                                                .assigned_shift
                                                                                .location
                                                                                ? ` · ${note.assigned_shift.location}`
                                                                                : ''}
                                                                        </p>
                                                                    </div>
                                                                )}
                                                                {!note.assigned_shift &&
                                                                    note.assigned_shift_date && (
                                                                        <p className="mt-1 text-xs text-violet-600">
                                                                            📋
                                                                            Assigned
                                                                            to
                                                                            shift
                                                                            on{' '}
                                                                            {
                                                                                note.assigned_shift_date
                                                                            }
                                                                        </p>
                                                                    )}
                                                                {note.staff_response && (
                                                                    <div className="mt-2 rounded-lg border-l-2 border-l-blue-400 bg-blue-50/50 p-2">
                                                                        <p className="text-xs">
                                                                            <span className="font-medium">
                                                                                {
                                                                                    note.staff_responded_by_name
                                                                                }
                                                                            </span>{' '}
                                                                            <Badge
                                                                                variant="outline"
                                                                                className="ml-1 border-blue-200 bg-blue-50 text-[9px] text-blue-700"
                                                                            >
                                                                                Staff
                                                                            </Badge>
                                                                        </p>
                                                                        <p className="mt-0.5 text-sm">
                                                                            {
                                                                                note.staff_response
                                                                            }
                                                                        </p>
                                                                    </div>
                                                                )}
                                                                {note.status ===
                                                                    'completed' &&
                                                                    note.completed_by_name && (
                                                                        <p className="mt-1 text-xs text-emerald-600">
                                                                            <CheckCircle2 className="mr-1 inline h-3 w-3" />
                                                                            Completed
                                                                            by{' '}
                                                                            {
                                                                                note.completed_by_name
                                                                            }
                                                                        </p>
                                                                    )}
                                                                <p className="mt-1 text-[10px] text-muted-foreground">
                                                                    By{' '}
                                                                    {
                                                                        note.creator_name
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {new Date(
                                                                        note.created_at,
                                                                    ).toLocaleDateString(
                                                                        'en-NZ',
                                                                    )}
                                                                </p>
                                                            </div>

                                                            {/* Staff actions */}
                                                            {[
                                                                'open',
                                                                'in_progress',
                                                            ].includes(
                                                                note.status,
                                                            ) && (
                                                                <div className="flex shrink-0 flex-col gap-1">
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        className="h-7 gap-1 text-[10px] text-emerald-600"
                                                                        onClick={() =>
                                                                            router.post(
                                                                                `/clients/${client.id}/family-notes/${note.id}/status`,
                                                                                {
                                                                                    status: 'completed',
                                                                                },
                                                                                {
                                                                                    preserveScroll: true,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        <Check className="h-3 w-3" />
                                                                        Done
                                                                    </Button>
                                                                    {note.status ===
                                                                        'open' && (
                                                                        <Button
                                                                            size="sm"
                                                                            variant="outline"
                                                                            className="h-7 gap-1 text-[10px] text-amber-600"
                                                                            onClick={() =>
                                                                                router.post(
                                                                                    `/clients/${client.id}/family-notes/${note.id}/status`,
                                                                                    {
                                                                                        status: 'in_progress',
                                                                                    },
                                                                                    {
                                                                                        preserveScroll: true,
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            <Clock className="h-3 w-3" />
                                                                            Start
                                                                        </Button>
                                                                    )}
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        className="h-7 gap-1 text-[10px]"
                                                                        onClick={() => {
                                                                            setRespondingId(
                                                                                respondingId ===
                                                                                    note.id
                                                                                    ? null
                                                                                    : note.id,
                                                                            );
                                                                            setResponseText(
                                                                                '',
                                                                            );
                                                                        }}
                                                                    >
                                                                        <MsgIcon className="h-3 w-3" />
                                                                        Reply
                                                                    </Button>
                                                                    {!note.assigned_to_shift_id && (
                                                                        <Button
                                                                            size="sm"
                                                                            variant="outline"
                                                                            className="h-7 gap-1 text-[10px] text-violet-600"
                                                                            onClick={() =>
                                                                                setAssigningId(
                                                                                    assigningId ===
                                                                                        note.id
                                                                                        ? null
                                                                                        : note.id,
                                                                                )
                                                                            }
                                                                        >
                                                                            <ListTodo className="h-3 w-3" />
                                                                            Shift
                                                                        </Button>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>

                                                        {/* Response form */}
                                                        {respondingId ===
                                                            note.id && (
                                                            <div className="mt-3 flex gap-2">
                                                                <Input
                                                                    className="h-8 text-xs"
                                                                    placeholder="Write a response..."
                                                                    value={
                                                                        responseText
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setResponseText(
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                />
                                                                <Button
                                                                    size="sm"
                                                                    className="h-8"
                                                                    disabled={
                                                                        !responseText.trim()
                                                                    }
                                                                    onClick={() => {
                                                                        router.post(
                                                                            `/clients/${client.id}/family-notes/${note.id}/respond`,
                                                                            {
                                                                                staff_response:
                                                                                    responseText,
                                                                            },
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        );
                                                                        setRespondingId(
                                                                            null,
                                                                        );
                                                                    }}
                                                                >
                                                                    Send
                                                                </Button>
                                                            </div>
                                                        )}

                                                        {/* Assign to shift */}
                                                        {assigningId ===
                                                            note.id && (
                                                            <div className="mt-3 text-xs text-muted-foreground">
                                                                <p className="mb-1 font-medium">
                                                                    Assign to
                                                                    upcoming
                                                                    shift:
                                                                </p>
                                                                {(() => {
                                                                    const clientShifts =
                                                                        (
                                                                            events ??
                                                                            []
                                                                        )
                                                                            .filter(
                                                                                (
                                                                                    e: any,
                                                                                ) =>
                                                                                    e.type ===
                                                                                        'shift' &&
                                                                                    new Date(
                                                                                        e.occurred_at,
                                                                                    ) >
                                                                                        new Date(),
                                                                            )
                                                                            .slice(
                                                                                0,
                                                                                5,
                                                                            );
                                                                    return clientShifts.length >
                                                                        0 ? (
                                                                        <div className="flex flex-wrap gap-1">
                                                                            {clientShifts.map(
                                                                                (
                                                                                    s: any,
                                                                                ) => (
                                                                                    <Button
                                                                                        key={
                                                                                            s.id
                                                                                        }
                                                                                        size="sm"
                                                                                        variant="outline"
                                                                                        className="h-7 text-[10px]"
                                                                                        onClick={() => {
                                                                                            router.post(
                                                                                                `/clients/${client.id}/family-notes/${note.id}/assign-shift`,
                                                                                                {
                                                                                                    shift_id:
                                                                                                        s.shift_id ||
                                                                                                        s.id,
                                                                                                },
                                                                                                {
                                                                                                    preserveScroll: true,
                                                                                                },
                                                                                            );
                                                                                            setAssigningId(
                                                                                                null,
                                                                                            );
                                                                                        }}
                                                                                    >
                                                                                        {new Date(
                                                                                            s.occurred_at,
                                                                                        ).toLocaleDateString(
                                                                                            'en-NZ',
                                                                                            {
                                                                                                weekday:
                                                                                                    'short',
                                                                                                day: 'numeric',
                                                                                                month: 'short',
                                                                                            },
                                                                                        )}
                                                                                    </Button>
                                                                                ),
                                                                            )}
                                                                        </div>
                                                                    ) : (
                                                                        <p>
                                                                            No
                                                                            upcoming
                                                                            shifts
                                                                            found.
                                                                        </p>
                                                                    );
                                                                })()}
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

                {tab === 'location' && location && (
                    <ClientLocationTab
                        clientId={client.id}
                        clientName={name}
                        location={location}
                    />
                )}

                {tab === 'consents' &&
                    (() => {
                        const activeCount = consents.filter(
                            (c: any) => c.status === 'given' && !c.is_expired,
                        ).length;
                        const expiredCount = consents.filter(
                            (c: any) => c.is_expired,
                        ).length;
                        const expiringCount = consents.filter(
                            (c: any) => c.is_expiring_soon,
                        ).length;

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
                                        <div className="text-lg font-bold text-indigo-600">
                                            {consents.length}
                                        </div>
                                        <div className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                            Total
                                        </div>
                                    </div>
                                    <div className="rounded-lg border p-3 text-center">
                                        <div className="text-lg font-bold text-emerald-600">
                                            {activeCount}
                                        </div>
                                        <div className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                            Active
                                        </div>
                                    </div>
                                    <div className="rounded-lg border p-3 text-center">
                                        <div
                                            className={`text-lg font-bold ${expiringCount > 0 ? 'text-amber-600' : 'text-slate-400'}`}
                                        >
                                            {expiringCount}
                                        </div>
                                        <div className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                            Expiring
                                        </div>
                                    </div>
                                    <div className="rounded-lg border p-3 text-center">
                                        <div
                                            className={`text-lg font-bold ${expiredCount > 0 ? 'text-red-600' : 'text-slate-400'}`}
                                        >
                                            {expiredCount}
                                        </div>
                                        <div className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                            Expired
                                        </div>
                                    </div>
                                </div>

                                {/* Consent List */}
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center justify-between text-base">
                                            <span>Consent Records</span>
                                            <Button size="sm" asChild>
                                                <Link
                                                    href={`/operations/clients/${client.id}/consents`}
                                                >
                                                    Manage Consents
                                                </Link>
                                            </Button>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {consents.length === 0 ? (
                                            <p className="py-8 text-center text-sm text-muted-foreground">
                                                No consent records. Record the
                                                first consent for{' '}
                                                {client.first_name}.
                                            </p>
                                        ) : (
                                            <div className="space-y-2">
                                                {consents.map((c: any) => {
                                                    const displayStatus =
                                                        c.is_expired
                                                            ? 'expired'
                                                            : c.status;
                                                    return (
                                                        <div
                                                            key={c.id}
                                                            className="flex items-center justify-between rounded-lg border p-3"
                                                        >
                                                            <div>
                                                                <div className="flex items-center gap-2">
                                                                    <span className="text-sm font-medium">
                                                                        {
                                                                            c.consent_type
                                                                        }
                                                                    </span>
                                                                    <span
                                                                        className={`rounded-full px-2 py-0.5 text-[10px] font-medium capitalize ${STATUS_COLORS[displayStatus] ?? 'bg-slate-100 text-slate-600'}`}
                                                                    >
                                                                        {
                                                                            displayStatus
                                                                        }
                                                                    </span>
                                                                    {c.capacity_assessed && (
                                                                        <span className="rounded bg-purple-100 px-1.5 py-0.5 text-[10px] text-purple-700">
                                                                            Capacity
                                                                            Assessed
                                                                        </span>
                                                                    )}
                                                                </div>
                                                                <div className="mt-0.5 flex gap-3 text-xs text-muted-foreground">
                                                                    {c.given_at && (
                                                                        <span>
                                                                            Given:{' '}
                                                                            {new Date(
                                                                                c.given_at,
                                                                            ).toLocaleDateString(
                                                                                'en-NZ',
                                                                            )}
                                                                        </span>
                                                                    )}
                                                                    {c.expires_at && (
                                                                        <span
                                                                            className={
                                                                                c.is_expired
                                                                                    ? 'font-medium text-red-600'
                                                                                    : c.is_expiring_soon
                                                                                      ? 'font-medium text-amber-600'
                                                                                      : ''
                                                                            }
                                                                        >
                                                                            Expires:{' '}
                                                                            {new Date(
                                                                                c.expires_at,
                                                                            ).toLocaleDateString(
                                                                                'en-NZ',
                                                                            )}
                                                                        </span>
                                                                    )}
                                                                    {c.given_method && (
                                                                        <span>
                                                                            Method:{' '}
                                                                            {
                                                                                c.given_method
                                                                            }
                                                                        </span>
                                                                    )}
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
                                    <span>
                                        Portal access (
                                        {labels?.['client.singular'] ??
                                            'Client'}{' '}
                                        / Next of Kin)
                                    </span>
                                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600">
                                        {portal_users.length}
                                    </span>
                                </div>
                                {can.edit && (
                                    <Button size="sm" asChild>
                                        <Link
                                            href={`/operations/clients/${client.id}/portal-users`}
                                        >
                                            Quick Add
                                        </Link>
                                    </Button>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-sm text-slate-600">
                                Portal users can view this{' '}
                                {(
                                    labels?.['client.singular'] ?? 'Client'
                                ).toLowerCase()}
                                {"'s"} medical, documents, and timeline, and can
                                query the RAG assistant.
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
                                                    <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700">
                                                        Legal Guardian
                                                    </span>
                                                )}
                                                {u.is_emergency_contact && (
                                                    <span className="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-medium text-red-700">
                                                        Emergency
                                                    </span>
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
                                            {u.status === 'active' ||
                                            u.is_active !== false ? (
                                                <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700">
                                                    Active
                                                </span>
                                            ) : (
                                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500">
                                                    Inactive
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                ))}
                                {!portal_users.length && (
                                    <div className="py-8 text-center text-sm text-slate-500">
                                        No portal users linked. Add a next of
                                        kin or family member to get started.
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {tab === 'personal_assets' && (
                    <PersonalAssetsTab
                        clientId={client.id}
                        assets={personal_assets}
                        canEdit={can.edit}
                        firstName={client.first_name}
                        locations={(pageProps as any).asset_locations ?? []}
                        clientSiteId={client.site?.id ?? null}
                        availableTrackers={
                            (pageProps as any).available_trackers ?? []
                        }
                    />
                )}

                {tab === 'transport' && (() => {
                    const ts = transport?.stats ?? { transports_30d: 0, outings_30d: 0, incidents_30d: 0 };
                    const upcoming = transport?.upcoming_outings ?? [];
                    const history = transport?.transport_history ?? [];
                    const medLogs = transport?.medication_logs ?? [];

                    return (
                        <div className="space-y-6">
                            {/* Stats */}
                            <div className="grid gap-3 sm:grid-cols-3">
                                <Card className="border bg-blue-50/50 dark:bg-blue-950/20">
                                    <CardContent className="p-4">
                                        <div className="text-2xl font-bold text-blue-700 dark:text-blue-400">{ts.transports_30d}</div>
                                        <div className="text-xs text-muted-foreground">Transports (30d)</div>
                                    </CardContent>
                                </Card>
                                <Card className="border bg-purple-50/50 dark:bg-purple-950/20">
                                    <CardContent className="p-4">
                                        <div className="text-2xl font-bold text-purple-700 dark:text-purple-400">{ts.outings_30d}</div>
                                        <div className="text-xs text-muted-foreground">Outings (30d)</div>
                                    </CardContent>
                                </Card>
                                <Card className={`border ${ts.incidents_30d > 0 ? 'bg-red-50/50 dark:bg-red-950/20' : 'bg-muted/30'}`}>
                                    <CardContent className="p-4">
                                        <div className={`text-2xl font-bold ${ts.incidents_30d > 0 ? 'text-red-600' : 'text-muted-foreground'}`}>{ts.incidents_30d}</div>
                                        <div className="text-xs text-muted-foreground">Incidents (30d)</div>
                                    </CardContent>
                                </Card>
                            </div>

                            {/* Upcoming Outings */}
                            {upcoming.length > 0 && (
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <Calendar className="h-4 w-4" /> Upcoming Outings
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-2">
                                            {upcoming.map((o) => (
                                                <Link
                                                    key={o.id}
                                                    href={`/fleet-assets/outings/${o.id}`}
                                                    className="flex items-center justify-between rounded-lg border p-3 text-sm transition-colors hover:bg-muted/50"
                                                >
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-semibold truncate">{o.title}</span>
                                                            <Badge variant={o.status === 'active' ? 'default' : 'outline'} className="text-[10px] shrink-0">{o.status}</Badge>
                                                        </div>
                                                        <div className="flex items-center gap-2 text-xs text-muted-foreground mt-0.5">
                                                            <span>{o.destination}</span>
                                                            {o.vehicle && <><span>·</span><span>{o.vehicle.name}</span></>}
                                                            {o.residents_count > 1 && <><span>·</span><span>{o.residents_count} residents</span></>}
                                                        </div>
                                                    </div>
                                                    {o.planned_departure && (
                                                        <div className="shrink-0 text-right text-xs text-muted-foreground">
                                                            <div>{new Date(o.planned_departure).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}</div>
                                                            <div>{new Date(o.planned_departure).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })}</div>
                                                        </div>
                                                    )}
                                                </Link>
                                            ))}
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Transport History */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Truck className="h-4 w-4" /> Transport History
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {history.length > 0 ? (
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-xs">
                                                <thead>
                                                    <tr className="border-b text-left text-muted-foreground">
                                                        <th className="pb-2 pr-3 font-medium">Type</th>
                                                        <th className="pb-2 pr-3 font-medium">From / To</th>
                                                        <th className="pb-2 pr-3 font-medium">Vehicle</th>
                                                        <th className="pb-2 pr-3 font-medium">Driver</th>
                                                        <th className="pb-2 pr-3 font-medium">Date</th>
                                                        <th className="pb-2 pr-3 font-medium">Duration</th>
                                                        <th className="pb-2 font-medium">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {history.map((t) => (
                                                        <tr key={t.id} className="border-b border-border/50 last:border-0">
                                                            <td className="py-2 pr-3">
                                                                <Badge variant="outline" className="text-[10px] capitalize">{(t.transport_type ?? '').replace(/_/g, ' ')}</Badge>
                                                            </td>
                                                            <td className="py-2 pr-3">
                                                                <div className="max-w-[140px] truncate">{t.pickup_location ?? '—'}</div>
                                                                <div className="max-w-[140px] truncate text-muted-foreground">→ {t.dropoff_location ?? '—'}</div>
                                                            </td>
                                                            <td className="py-2 pr-3">{t.vehicle?.name ?? '—'}</td>
                                                            <td className="py-2 pr-3">{t.driver?.name ?? '—'}</td>
                                                            <td className="py-2 pr-3 whitespace-nowrap">{t.departed_at ? formatDT(t.departed_at) : '—'}</td>
                                                            <td className="py-2 pr-3 whitespace-nowrap">
                                                                {t.duration_minutes != null ? `${Math.round(t.duration_minutes)}m` : '—'}
                                                            </td>
                                                            <td className="py-2">
                                                                <Badge
                                                                    variant={t.status === 'completed' ? 'default' : t.status === 'in_progress' ? 'secondary' : 'outline'}
                                                                    className="text-[10px]"
                                                                >{t.status}</Badge>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    ) : (
                                        <div className="flex flex-col items-center justify-center py-8 text-muted-foreground">
                                            <Truck className="mb-2 h-8 w-8 opacity-40" />
                                            <p className="text-sm font-medium">No transport history</p>
                                            <p className="text-xs">Transport records will appear here when this resident is transported.</p>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Medication Transit Logs */}
                            {medLogs.length > 0 && (
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <Pill className="h-4 w-4" /> Medication Transit Log
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-xs">
                                                <thead>
                                                    <tr className="border-b text-left text-muted-foreground">
                                                        <th className="pb-2 pr-3 font-medium">Medication</th>
                                                        <th className="pb-2 pr-3 font-medium">Packed</th>
                                                        <th className="pb-2 pr-3 font-medium">Administered</th>
                                                        <th className="pb-2 pr-3 font-medium">Returned</th>
                                                        <th className="pb-2 font-medium">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {medLogs.map((m) => (
                                                        <tr key={m.id} className="border-b border-border/50 last:border-0">
                                                            <td className="py-2 pr-3">
                                                                <div className="flex items-center gap-1.5">
                                                                    <span className="font-medium">{m.medication_name}</span>
                                                                    {m.is_controlled_drug && (
                                                                        <Badge variant="destructive" className="text-[8px] px-1">CD</Badge>
                                                                    )}
                                                                </div>
                                                            </td>
                                                            <td className="py-2 pr-3">
                                                                {m.packed_at ? (
                                                                    <div>
                                                                        <div>{formatDT(m.packed_at)}</div>
                                                                        {m.packed_by && <div className="text-muted-foreground">by {m.packed_by}</div>}
                                                                    </div>
                                                                ) : '—'}
                                                            </td>
                                                            <td className="py-2 pr-3">
                                                                {m.administered_at ? (
                                                                    <div>
                                                                        <div>{formatDT(m.administered_at)}</div>
                                                                        {m.administered_by && <div className="text-muted-foreground">by {m.administered_by}</div>}
                                                                        {m.is_controlled_drug && m.witnessed_by && (
                                                                            <div className="text-muted-foreground">witnessed: {m.witnessed_by}</div>
                                                                        )}
                                                                    </div>
                                                                ) : '—'}
                                                            </td>
                                                            <td className="py-2 pr-3">
                                                                {m.returned_to_house_at ? formatDT(m.returned_to_house_at) : '—'}
                                                            </td>
                                                            <td className="py-2">
                                                                <Badge
                                                                    variant={m.status === 'returned' ? 'default' : m.status === 'administered' ? 'secondary' : 'outline'}
                                                                    className="text-[10px] capitalize"
                                                                >{m.status}</Badge>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    );
                })()}

                {tab === 'assignments' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center justify-between text-base">
                                <div className="flex items-center gap-2">
                                    <span>Assigned Workers</span>
                                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600">
                                        {client.support_workers?.length ?? 0}
                                    </span>
                                </div>
                                {can.assign_workers && (
                                    <Button size="sm" asChild>
                                        <Link
                                            href={`/operations/clients/${client.id}/assignments`}
                                        >
                                            Manage Assignments
                                        </Link>
                                    </Button>
                                )}
                            </CardTitle>
                            <p className="text-xs text-muted-foreground">
                                Controls which staff can see and work with this{' '}
                                {(
                                    labels?.['client.singular'] ?? 'Client'
                                ).toLowerCase()}
                                .
                            </p>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {(client.support_workers ?? []).length > 0 ? (
                                <div className="space-y-2">
                                    {client.support_workers.map((w) => (
                                        <div
                                            key={w.id}
                                            className="flex items-center justify-between rounded-md border p-3"
                                        >
                                            <div className="flex items-center gap-3">
                                                <Avatar className="h-8 w-8">
                                                    <AvatarFallback className="text-xs">
                                                        {getInitials(w.name)}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <div>
                                                    <div className="text-sm font-medium">
                                                        {w.name}
                                                    </div>
                                                    {w.email && (
                                                        <div className="text-xs text-muted-foreground">
                                                            {w.email}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                            {client.key_worker?.id === w.id && (
                                                <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-medium text-indigo-700">
                                                    Key Worker
                                                </span>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="py-8 text-center text-sm text-slate-500">
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
                                form.put(
                                    `/operations/clients/${clientId}/support-plan`,
                                    {
                                        preserveScroll: true,
                                    },
                                )
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

const ASSESSMENT_TYPES: Record<
    string,
    {
        label: string;
        icon: string;
        border: string;
        bg: string;
        gradient: string;
    }
> = {
    interrai: {
        label: 'InterRAI',
        icon: '\u{1F3E5}',
        border: 'border-l-blue-400',
        bg: 'bg-blue-100',
        gradient: 'from-blue-50 to-sky-50',
    },
    whodas: {
        label: 'WHODAS 2.0',
        icon: '\u{1F4CA}',
        border: 'border-l-violet-400',
        bg: 'bg-violet-100',
        gradient: 'from-violet-50 to-purple-50',
    },
    risk: {
        label: 'Risk Assessment',
        icon: '\u26A0\uFE0F',
        border: 'border-l-red-400',
        bg: 'bg-red-100',
        gradient: 'from-red-50 to-rose-50',
    },
    medication_review: {
        label: 'Medication Review',
        icon: '\u{1F48A}',
        border: 'border-l-emerald-400',
        bg: 'bg-emerald-100',
        gradient: 'from-emerald-50 to-green-50',
    },
    honos: {
        label: 'HoNOS',
        icon: '\u{1F9E0}',
        border: 'border-l-amber-400',
        bg: 'bg-amber-100',
        gradient: 'from-amber-50 to-yellow-50',
    },
    functional: {
        label: 'Functional Assessment',
        icon: '\u{1F3C3}',
        border: 'border-l-cyan-400',
        bg: 'bg-cyan-100',
        gradient: 'from-cyan-50 to-teal-50',
    },
    nasc: {
        label: 'Needs Assessment (NASC)',
        icon: '\u{1F4CB}',
        border: 'border-l-indigo-400',
        bg: 'bg-indigo-100',
        gradient: 'from-indigo-50 to-blue-50',
    },
    behaviour_support: {
        label: 'Behaviour Support',
        icon: '\u{1F91D}',
        border: 'border-l-pink-400',
        bg: 'bg-pink-100',
        gradient: 'from-pink-50 to-rose-50',
    },
    other: {
        label: 'Other',
        icon: '\u{1F4DD}',
        border: 'border-l-slate-400',
        bg: 'bg-slate-100',
        gradient: 'from-slate-50 to-gray-50',
    },
};

function getTypeStyle(type: string): {
    label: string;
    icon: string;
    border: string;
    bg: string;
    gradient: string;
} {
    if (ASSESSMENT_TYPES[type]) return ASSESSMENT_TYPES[type]!;
    const lower = (type ?? '').toLowerCase();
    if (lower.includes('interrai')) return ASSESSMENT_TYPES.interrai!;
    if (lower.includes('whodas')) return ASSESSMENT_TYPES.whodas!;
    if (lower.includes('risk')) return ASSESSMENT_TYPES.risk!;
    if (lower.includes('medication') || lower.includes('med review'))
        return ASSESSMENT_TYPES.medication_review!;
    if (lower.includes('honos')) return ASSESSMENT_TYPES.honos!;
    if (lower.includes('functional')) return ASSESSMENT_TYPES.functional!;
    if (lower.includes('nasc') || lower.includes('needs'))
        return ASSESSMENT_TYPES.nasc!;
    if (lower.includes('behaviour') || lower.includes('behavior'))
        return ASSESSMENT_TYPES.behaviour_support!;
    return { ...ASSESSMENT_TYPES.other!, label: type || 'Assessment' };
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
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [customType, setCustomType] = useState('');

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
        const knownKey = Object.keys(ASSESSMENT_TYPES).find(
            (k) => k === a.type,
        );
        if (knownKey) {
            form.setData({
                type: knownKey,
                score: a.score ?? '',
                assessed_at: a.assessed_at ?? '',
                next_review_at: a.next_review_at ?? '',
                notes: a.notes ?? '',
            });
            setCustomType('');
        } else {
            form.setData({
                type: 'other',
                score: a.score ?? '',
                assessed_at: a.assessed_at ?? '',
                next_review_at: a.next_review_at ?? '',
                notes: a.notes ?? '',
            });
            setCustomType(a.type ?? '');
        }
    }

    function resetForm() {
        setEditingId(null);
        setShowForm(false);
        setCustomType('');
        form.reset();
    }

    function submitForm() {
        const submitType =
            form.data.type === 'other' && customType.trim()
                ? customType.trim()
                : form.data.type;
        const url = editingId
            ? `/operations/clients/${clientId}/assessments/${editingId}`
            : `/operations/clients/${clientId}/assessments`;
        const method = editingId ? 'put' : 'post';
        const data = { ...form.data, type: submitType };
        // @ts-ignore
        router[method](url, data, {
            preserveScroll: true,
            onSuccess: () => resetForm(),
        });
    }

    const now = new Date();
    const overdueCount = assessments.filter(
        (a) => a.next_review_at && new Date(a.next_review_at) < now,
    ).length;
    const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
    const completedThisMonth = assessments.filter(
        (a) => a.assessed_at && new Date(a.assessed_at) >= startOfMonth,
    ).length;
    const nextDue = assessments
        .filter((a) => a.next_review_at && new Date(a.next_review_at) >= now)
        .sort(
            (a, b) =>
                new Date(a.next_review_at).getTime() -
                new Date(b.next_review_at).getTime(),
        )[0];
    const nextDueDays = nextDue
        ? Math.ceil(
              (new Date(nextDue.next_review_at).getTime() - now.getTime()) /
                  86400000,
          )
        : null;

    return (
        <div className="space-y-4">
            {/* Stats Grid */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                    <div className="text-2xl font-bold text-violet-700">
                        {assessments.length}
                    </div>
                    <div className="text-[10px] tracking-wider text-violet-500 uppercase">
                        Total Assessments
                    </div>
                </div>
                <div className="rounded-xl border bg-gradient-to-br from-red-50 to-rose-50 p-3 text-center">
                    <div
                        className={`text-2xl font-bold ${overdueCount > 0 ? 'text-red-600' : 'text-slate-400'}`}
                    >
                        {overdueCount}
                    </div>
                    <div className="text-[10px] tracking-wider text-red-500 uppercase">
                        Overdue Reviews
                    </div>
                </div>
                <div className="rounded-xl border bg-gradient-to-br from-emerald-50 to-green-50 p-3 text-center">
                    <div className="text-2xl font-bold text-emerald-700">
                        {completedThisMonth}
                    </div>
                    <div className="text-[10px] tracking-wider text-emerald-500 uppercase">
                        This Month
                    </div>
                </div>
                <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-sky-50 p-3 text-center">
                    <div className="text-2xl font-bold text-blue-700">
                        {nextDueDays !== null ? `${nextDueDays}d` : '\u2014'}
                    </div>
                    <div className="text-[10px] tracking-wider text-blue-500 uppercase">
                        Next Due
                    </div>
                </div>
            </div>

            {/* Overdue Alert Banner */}
            {overdueCount > 0 && (
                <div className="flex items-center gap-3 rounded-xl border-2 border-amber-300 bg-gradient-to-r from-amber-50 to-orange-50 p-4">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-600">
                        <ShieldAlert className="h-5 w-5" />
                    </div>
                    <div className="flex-1">
                        <p className="text-sm font-semibold text-amber-800">
                            {overdueCount} Assessment Review
                            {overdueCount !== 1 ? 's' : ''} Overdue
                        </p>
                        <p className="text-xs text-amber-700">
                            These assessments are past their scheduled review
                            date.
                        </p>
                    </div>
                </div>
            )}

            {/* Form with Gradient Header */}
            {canEdit && showForm && (
                <Card className="overflow-hidden border-violet-200">
                    <div className="bg-gradient-to-r from-violet-500 to-purple-600 px-4 py-2.5">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-white">
                                {editingId
                                    ? 'Edit Assessment'
                                    : 'Record Assessment'}
                            </h3>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-white hover:bg-white/20 hover:text-white"
                                onClick={resetForm}
                            >
                                Cancel
                            </Button>
                        </div>
                    </div>
                    <CardContent className="p-4">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <Label>Type</Label>
                                <Select
                                    value={form.data.type}
                                    onValueChange={(v) => {
                                        form.setData('type', v);
                                        if (v !== 'other') setCustomType('');
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select type..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(ASSESSMENT_TYPES).map(
                                            ([key, t]) => (
                                                <SelectItem
                                                    key={key}
                                                    value={key}
                                                >
                                                    <span className="flex items-center gap-2">
                                                        <span>{t.icon}</span>{' '}
                                                        {t.label}
                                                    </span>
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                            {form.data.type === 'other' && (
                                <div>
                                    <Label>Custom Type</Label>
                                    <Input
                                        value={customType}
                                        onChange={(e) =>
                                            setCustomType(e.target.value)
                                        }
                                        placeholder="e.g. Sensory Profile"
                                    />
                                </div>
                            )}
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
                            <div className="sm:col-span-2">
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
                                className="bg-violet-600 text-white hover:bg-violet-700"
                                onClick={submitForm}
                                disabled={
                                    form.processing ||
                                    !form.data.type ||
                                    (form.data.type === 'other' &&
                                        !customType.trim())
                                }
                            >
                                Save
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Header Row */}
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium">
                    All Assessments ({assessments.length})
                </span>
                {canEdit && !showForm && (
                    <Button
                        size="sm"
                        className="gap-1.5 bg-violet-600 text-white hover:bg-violet-700"
                        onClick={() => {
                            resetForm();
                            setShowForm(true);
                        }}
                    >
                        <Plus className="h-3.5 w-3.5" /> New Assessment
                    </Button>
                )}
            </div>

            {/* List Items or Empty State */}
            {assessments.length === 0 ? (
                <Card className="border-dashed">
                    <CardContent className="flex flex-col items-center justify-center py-12">
                        <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-violet-50">
                            <ClipboardList className="h-7 w-7 text-violet-400" />
                        </div>
                        <p className="font-medium">No Assessments Recorded</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Clinical assessments and reviews will appear here.
                        </p>
                        {canEdit && (
                            <Button
                                size="sm"
                                className="mt-4 gap-1.5"
                                onClick={() => {
                                    resetForm();
                                    setShowForm(true);
                                }}
                            >
                                <Plus className="h-3.5 w-3.5" /> Record First
                                Assessment
                            </Button>
                        )}
                    </CardContent>
                </Card>
            ) : (
                <div className="space-y-3">
                    {assessments.map((a) => {
                        const isOverdue =
                            a.next_review_at &&
                            new Date(a.next_review_at) < now;
                        const isExpanded = expandedId === a.id;
                        const typeStyle = getTypeStyle(a.type);
                        return (
                            <Card
                                key={a.id}
                                className={`overflow-hidden border-l-4 ${typeStyle.border} ${isOverdue ? 'bg-red-50/30 dark:bg-red-950/20' : ''}`}
                            >
                                <CardContent className="p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex items-start gap-3">
                                            <div
                                                className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${typeStyle.bg} text-lg`}
                                            >
                                                {typeStyle.icon}
                                            </div>
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="text-sm font-semibold">
                                                        {typeStyle.label}
                                                    </span>
                                                    {a.score && (
                                                        <Badge className="border-0 bg-violet-100 text-xs font-bold text-violet-700">
                                                            Score: {a.score}
                                                        </Badge>
                                                    )}
                                                    {isOverdue && (
                                                        <Badge className="border-0 bg-red-100 text-[9px] font-medium text-red-700">
                                                            Review Overdue
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                                    {a.assessed_at && (
                                                        <span className="flex items-center gap-1">
                                                            <Calendar className="h-3 w-3" />
                                                            {new Date(
                                                                a.assessed_at,
                                                            ).toLocaleDateString(
                                                                'en-NZ',
                                                            )}
                                                        </span>
                                                    )}
                                                    {a.next_review_at && (
                                                        <span
                                                            className={`flex items-center gap-1 ${isOverdue ? 'font-medium text-red-600' : ''}`}
                                                        >
                                                            <Clock className="h-3 w-3" />
                                                            Review:{' '}
                                                            {new Date(
                                                                a.next_review_at,
                                                            ).toLocaleDateString(
                                                                'en-NZ',
                                                            )}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                        {canEdit && (
                                            <div className="flex shrink-0 items-center gap-1">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => startEdit(a)}
                                                >
                                                    <Pencil className="h-3.5 w-3.5" />
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-red-500 hover:text-red-700"
                                                    onClick={() =>
                                                        setDeletingId(a.id)
                                                    }
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                    {a.notes && (
                                        <div className="mt-2 ml-12">
                                            <button
                                                className="text-xs text-violet-600 hover:underline"
                                                onClick={() =>
                                                    setExpandedId(
                                                        isExpanded
                                                            ? null
                                                            : a.id,
                                                    )
                                                }
                                            >
                                                {isExpanded
                                                    ? 'Hide notes'
                                                    : 'Show notes'}
                                            </button>
                                            {isExpanded && (
                                                <div className="mt-1.5 border-l-2 border-violet-200 pl-3 text-xs whitespace-pre-wrap text-slate-600">
                                                    {a.notes}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            )}

            {/* Delete Confirmation Dialog */}
            <Dialog
                open={deletingId !== null}
                onOpenChange={(open) => {
                    if (!open) setDeletingId(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete Assessment</DialogTitle>
                    </DialogHeader>
                    <p className="text-sm text-muted-foreground">
                        Are you sure you want to delete this assessment? This
                        action cannot be undone.
                    </p>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeletingId(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (deletingId) {
                                    router.delete(
                                        `/operations/clients/${clientId}/assessments/${deletingId}`,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                setDeletingId(null),
                                        },
                                    );
                                }
                            }}
                        >
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function PhotoGalleryTab({
    clientId,
    photos,
    canEdit,
}: {
    clientId: number;
    photos: GalleryPhoto[];
    canEdit: boolean;
}) {
    const [showUpload, setShowUpload] = useState(false);
    const photoForm = useForm<{
        photo: File | null;
        caption: string;
        visibility: string;
    }>({
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
            onSuccess: () => {
                setShowUpload(false);
                photoForm.reset();
            },
        });
    };
    const deletePhoto = (photoId: number) => {
        if (!confirm('Delete this photo?')) return;
        router.delete(
            `/operations/clients/${clientId}/gallery-photos/${photoId}`,
            { preserveScroll: true },
        );
    };
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center justify-between text-base">
                    <span>Photo Gallery</span>
                    {canEdit && (
                        <Button
                            size="sm"
                            onClick={() => setShowUpload(!showUpload)}
                        >
                            {showUpload ? 'Cancel' : 'Upload Photo'}
                        </Button>
                    )}
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {showUpload && (
                    <form
                        onSubmit={submitPhoto}
                        className="space-y-3 rounded-lg border bg-muted/30 p-4"
                    >
                        <div>
                            <Label>Photo *</Label>
                            <Input
                                type="file"
                                accept="image/*"
                                onChange={(e) =>
                                    photoForm.setData(
                                        'photo',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </div>
                        <div>
                            <Label>Caption</Label>
                            <Input
                                value={photoForm.data.caption}
                                onChange={(e) =>
                                    photoForm.setData('caption', e.target.value)
                                }
                                placeholder="Add a caption..."
                            />
                        </div>
                        <div>
                            <Label>Visibility</Label>
                            <Select
                                value={photoForm.data.visibility}
                                onValueChange={(v) =>
                                    photoForm.setData('visibility', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="staff_only">
                                        Staff Only
                                    </SelectItem>
                                    <SelectItem value="family">
                                        Family & Staff
                                    </SelectItem>
                                    <SelectItem value="all_portal_users">
                                        All Portal Users
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <Button
                            type="submit"
                            disabled={
                                photoForm.processing || !photoForm.data.photo
                            }
                        >
                            {photoForm.processing ? 'Uploading...' : 'Upload'}
                        </Button>
                    </form>
                )}

                {photos.length > 0 ? (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                        {photos.map((p) => (
                            <div
                                key={p.id}
                                className="group relative overflow-hidden rounded-lg border bg-card"
                            >
                                <a
                                    href={p.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <img
                                        src={p.thumbnail_url || p.url}
                                        alt={p.caption || p.original_name}
                                        className="aspect-square w-full object-cover"
                                        loading="lazy"
                                    />
                                </a>
                                <div className="p-2">
                                    {p.caption && (
                                        <p className="line-clamp-2 text-xs font-medium">
                                            {p.caption}
                                        </p>
                                    )}
                                    <div className="mt-1 flex flex-wrap items-center gap-1">
                                        <Badge className="h-4 border-0 bg-slate-100 px-1 text-[8px] text-slate-600">
                                            {p.visibility.replace(/_/g, ' ')}
                                        </Badge>
                                        {p.status === 'pending_approval' && (
                                            <Badge className="h-4 border-0 bg-amber-100 px-1 text-[8px] text-amber-600">
                                                Pending
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="mt-1 text-[10px] text-muted-foreground">
                                        {p.uploaded_by} &middot;{' '}
                                        {new Date(
                                            p.created_at,
                                        ).toLocaleDateString()}
                                    </p>
                                </div>
                                {canEdit && (
                                    <button
                                        onClick={() => deletePhoto(p.id)}
                                        className="absolute top-1 right-1 rounded-full bg-black/50 p-1 text-white opacity-0 transition-opacity group-hover:opacity-100 hover:bg-red-600"
                                        title="Delete photo"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            className="h-3 w-3"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="2"
                                        >
                                            <line
                                                x1="18"
                                                y1="6"
                                                x2="6"
                                                y2="18"
                                            />
                                            <line
                                                x1="6"
                                                y1="6"
                                                x2="18"
                                                y2="18"
                                            />
                                        </svg>
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="py-12 text-center text-sm text-muted-foreground">
                        No photos yet. Upload the first one!
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

const ASSET_CATEGORIES: Record<
    string,
    { label: string; color: string; icon: string }
> = {
    mobility_aid: {
        label: 'Mobility Aid',
        color: 'bg-blue-100 text-blue-700',
        icon: '♿',
    },
    electronics: {
        label: 'Electronics',
        color: 'bg-violet-100 text-violet-700',
        icon: '📱',
    },
    furniture: {
        label: 'Furniture',
        color: 'bg-amber-100 text-amber-700',
        icon: '🪑',
    },
    clothing: {
        label: 'Clothing',
        color: 'bg-pink-100 text-pink-700',
        icon: '👕',
    },
    medical_equipment: {
        label: 'Medical Equipment',
        color: 'bg-red-100 text-red-700',
        icon: '🩺',
    },
    personal_care: {
        label: 'Personal Care',
        color: 'bg-teal-100 text-teal-700',
        icon: '🧴',
    },
    entertainment: {
        label: 'Entertainment',
        color: 'bg-indigo-100 text-indigo-700',
        icon: '🎮',
    },
    transport: {
        label: 'Transport',
        color: 'bg-emerald-100 text-emerald-700',
        icon: '🚗',
    },
    other: { label: 'Other', color: 'bg-slate-100 text-slate-600', icon: '📦' },
};

const CONDITION_COLORS: Record<string, string> = {
    new: 'bg-emerald-100 text-emerald-700',
    good: 'bg-blue-100 text-blue-700',
    fair: 'bg-amber-100 text-amber-700',
    poor: 'bg-red-100 text-red-700',
};

const STATUS_CONFIG: Record<
    string,
    { label: string; color: string; dot: string }
> = {
    active: {
        label: 'Active',
        color: 'bg-emerald-100 text-emerald-700',
        dot: 'bg-emerald-500',
    },
    in_repair: {
        label: 'In Repair',
        color: 'bg-amber-100 text-amber-700',
        dot: 'bg-amber-500',
    },
    lost: {
        label: 'Lost',
        color: 'bg-red-100 text-red-700',
        dot: 'bg-red-500',
    },
    damaged: {
        label: 'Damaged',
        color: 'bg-orange-100 text-orange-700',
        dot: 'bg-orange-500',
    },
    disposed: {
        label: 'Disposed',
        color: 'bg-slate-100 text-slate-600',
        dot: 'bg-slate-400',
    },
    returned: {
        label: 'Returned',
        color: 'bg-purple-100 text-purple-700',
        dot: 'bg-purple-500',
    },
};

const OWNERSHIP_CONFIG: Record<string, { label: string; color: string }> = {
    client: { label: 'Client Owned', color: 'bg-sky-100 text-sky-700' },
    provider: {
        label: 'Provider Owned',
        color: 'bg-violet-100 text-violet-700',
    },
    funded: { label: 'Funded', color: 'bg-emerald-100 text-emerald-700' },
    loaned: { label: 'On Loan', color: 'bg-amber-100 text-amber-700' },
};

function PersonalAssetsTab({
    clientId,
    assets,
    canEdit,
    firstName,
    locations,
    clientSiteId,
    availableTrackers,
}: {
    clientId: number;
    assets: PersonalAsset[];
    canEdit: boolean;
    firstName: string;
    locations: AssetLocation[];
    clientSiteId: number | null;
    availableTrackers: AvailableTracker[];
}) {
    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [search, setSearch] = useState('');
    const [filterCategory, setFilterCategory] = useState('all');
    const [filterStatus, setFilterStatus] = useState('all');
    const [sortBy, setSortBy] = useState<
        'name' | 'value' | 'acquired' | 'added'
    >('added');
    const [groupByCategory, setGroupByCategory] = useState(false);

    const form = useForm<{
        name: string;
        category: string;
        description: string;
        serial_number: string;
        estimated_value: string;
        condition: string;
        location: string;
        site_id: string;
        room_id: string;
        tracker_hardware_id: string;
        photo: File | null;
        acquired_at: string;
        notes: string;
        status: string;
        ownership: string;
        funding_source: string;
        return_required: boolean;
        return_by: string;
        last_serviced_at: string;
        next_service_due: string;
        service_provider: string;
        warranty_expires_at: string;
        insurance_reference: string;
        portal_visible: boolean;
    }>({
        name: '',
        category: '',
        description: '',
        serial_number: '',
        estimated_value: '',
        condition: '',
        location: '',
        site_id: clientSiteId ? String(clientSiteId) : '',
        room_id: '',
        tracker_hardware_id: '',
        photo: null,
        acquired_at: '',
        notes: '',
        status: 'active',
        ownership: 'client',
        funding_source: '',
        return_required: false,
        return_by: '',
        last_serviced_at: '',
        next_service_due: '',
        service_provider: '',
        warranty_expires_at: '',
        insurance_reference: '',
        portal_visible: false,
    });

    const resetForm = () => {
        form.reset();
        setShowForm(false);
        setEditingId(null);
    };

    const startEdit = (a: PersonalAsset) => {
        form.setData({
            name: a.name,
            category: a.category ?? '',
            description: a.description ?? '',
            serial_number: a.serial_number ?? '',
            estimated_value: a.estimated_value ?? '',
            condition: a.condition ?? '',
            location: a.location ?? '',
            site_id: a.site_id
                ? String(a.site_id)
                : clientSiteId
                  ? String(clientSiteId)
                  : '',
            room_id: a.room_id ? String(a.room_id) : '',
            tracker_hardware_id: a.tracker_hardware_id
                ? String(a.tracker_hardware_id)
                : '',
            photo: null,
            acquired_at: a.acquired_at ?? '',
            notes: a.notes ?? '',
            status: a.status ?? 'active',
            ownership: a.ownership ?? 'client',
            funding_source: a.funding_source ?? '',
            return_required: a.return_required ?? false,
            return_by: a.return_by ?? '',
            last_serviced_at: a.last_serviced_at ?? '',
            next_service_due: a.next_service_due ?? '',
            service_provider: a.service_provider ?? '',
            warranty_expires_at: a.warranty_expires_at ?? '',
            insurance_reference: a.insurance_reference ?? '',
            portal_visible: a.portal_visible ?? false,
        });
        setEditingId(a.id);
        setShowForm(true);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editingId) {
            router.post(
                `/operations/clients/${clientId}/personal-assets/${editingId}`,
                {
                    ...form.data,
                    _method: 'PUT',
                },
                {
                    preserveScroll: true,
                    onSuccess: () => resetForm(),
                    forceFormData: true,
                },
            );
        } else {
            form.post(`/operations/clients/${clientId}/personal-assets`, {
                preserveScroll: true,
                onSuccess: () => resetForm(),
                forceFormData: true,
            });
        }
    };

    const changeStatus = (assetId: number, newStatus: string) => {
        router.patch(
            `/operations/clients/${clientId}/personal-assets/${assetId}/status`,
            { status: newStatus },
            { preserveScroll: true },
        );
    };

    // Computed stats
    const activeAssets = assets.filter((a) => a.status === 'active');
    const totalValue = activeAssets.reduce(
        (sum, a) => sum + (parseFloat(a.estimated_value ?? '0') || 0),
        0,
    );
    const needsAttention = assets.filter(
        (a) =>
            a.is_service_overdue ||
            a.is_warranty_expired ||
            a.is_warranty_expiring_soon ||
            a.is_return_overdue ||
            a.condition === 'poor',
    ).length;
    const categories = new Set(assets.map((a) => a.category).filter(Boolean));

    // Filter & sort
    const filtered = assets
        .filter((a) => {
            if (filterCategory !== 'all' && a.category !== filterCategory)
                return false;
            if (filterStatus !== 'all' && a.status !== filterStatus)
                return false;
            if (search) {
                const q = search.toLowerCase();
                return (
                    a.name.toLowerCase().includes(q) ||
                    (a.description ?? '').toLowerCase().includes(q) ||
                    (a.serial_number ?? '').toLowerCase().includes(q) ||
                    (a.location ?? '').toLowerCase().includes(q) ||
                    (a.site_name ?? '').toLowerCase().includes(q) ||
                    (a.room_name ?? '').toLowerCase().includes(q)
                );
            }
            return true;
        })
        .sort((a, b) => {
            if (sortBy === 'name') return a.name.localeCompare(b.name);
            if (sortBy === 'value')
                return (
                    (parseFloat(b.estimated_value ?? '0') || 0) -
                    (parseFloat(a.estimated_value ?? '0') || 0)
                );
            if (sortBy === 'acquired')
                return (b.acquired_at ?? '').localeCompare(a.acquired_at ?? '');
            return (b.created_at ?? '').localeCompare(a.created_at ?? '');
        });

    // Group by category
    const grouped = groupByCategory
        ? filtered.reduce(
              (acc: Record<string, PersonalAsset[]>, a) => {
                  const key = a.category || 'other';
                  if (!acc[key]) acc[key] = [];
                  acc[key].push(a);
                  return acc;
              },
              {} as Record<string, PersonalAsset[]>,
          )
        : { all: filtered };

    const renderAssetCard = (a: PersonalAsset) => {
        const cat = ASSET_CATEGORIES[a.category ?? ''];
        const stat = (STATUS_CONFIG[a.status] ?? STATUS_CONFIG.active)!;
        const own = OWNERSHIP_CONFIG[a.ownership ?? 'client'];
        const hasAlerts =
            a.is_service_overdue ||
            a.is_warranty_expired ||
            a.is_warranty_expiring_soon ||
            a.is_return_overdue;

        return (
            <Card
                key={a.id}
                className={`group relative overflow-hidden transition-all hover:shadow-md ${hasAlerts ? 'border-amber-300' : ''} ${a.status !== 'active' ? 'opacity-75' : ''}`}
            >
                {/* Photo or category icon header */}
                {a.photo_url ? (
                    <div className="relative h-36 overflow-hidden bg-slate-100">
                        <img
                            src={a.photo_url}
                            alt={a.name}
                            className="h-full w-full object-cover"
                        />
                        <div className="absolute top-2 left-2 flex gap-1">
                            <span
                                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ${stat.color} shadow-sm`}
                            >
                                <span
                                    className={`h-1.5 w-1.5 rounded-full ${stat.dot}`}
                                />
                                {stat.label}
                            </span>
                        </div>
                        {a.portal_visible && (
                            <div className="absolute top-2 right-2">
                                <span className="rounded-full bg-blue-600 px-1.5 py-0.5 text-[9px] font-medium text-white shadow-sm">
                                    Portal
                                </span>
                            </div>
                        )}
                    </div>
                ) : (
                    <div
                        className={`relative flex h-20 items-center justify-center ${cat ? cat.color.replace('text-', 'bg-').split(' ')[0] : 'bg-slate-50'}`}
                    >
                        <span className="text-3xl">{cat?.icon ?? '📦'}</span>
                        <div className="absolute top-2 left-2 flex gap-1">
                            <span
                                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ${stat.color} shadow-sm`}
                            >
                                <span
                                    className={`h-1.5 w-1.5 rounded-full ${stat.dot}`}
                                />
                                {stat.label}
                            </span>
                        </div>
                        {a.portal_visible && (
                            <div className="absolute top-2 right-2">
                                <span className="rounded-full bg-blue-600 px-1.5 py-0.5 text-[9px] font-medium text-white shadow-sm">
                                    Portal
                                </span>
                            </div>
                        )}
                    </div>
                )}

                {/* Alert banner */}
                {hasAlerts && (
                    <div className="flex flex-wrap gap-1.5 border-b border-amber-200 bg-amber-50 px-3 py-1.5">
                        {a.is_service_overdue && (
                            <span className="text-[10px] font-medium text-amber-700">
                                Service overdue
                            </span>
                        )}
                        {a.is_warranty_expired && (
                            <span className="text-[10px] font-medium text-red-700">
                                Warranty expired
                            </span>
                        )}
                        {a.is_warranty_expiring_soon &&
                            !a.is_warranty_expired && (
                                <span className="text-[10px] font-medium text-amber-700">
                                    Warranty expiring soon
                                </span>
                            )}
                        {a.is_return_overdue && (
                            <span className="text-[10px] font-medium text-red-700">
                                Return overdue
                            </span>
                        )}
                    </div>
                )}

                <CardContent className="space-y-2.5 pt-3">
                    <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                            <h4 className="truncate text-sm font-semibold">
                                {a.name}
                            </h4>
                            <div className="mt-1 flex flex-wrap gap-1">
                                {cat && (
                                    <Badge
                                        className={`border-0 text-[10px] ${cat.color}`}
                                    >
                                        {cat.icon} {cat.label}
                                    </Badge>
                                )}
                                {a.condition && (
                                    <Badge
                                        className={`border-0 text-[10px] ${CONDITION_COLORS[a.condition] ?? 'bg-slate-100 text-slate-600'}`}
                                    >
                                        {a.condition}
                                    </Badge>
                                )}
                                {own && a.ownership !== 'client' && (
                                    <Badge
                                        className={`border-0 text-[10px] ${own.color}`}
                                    >
                                        {own.label}
                                    </Badge>
                                )}
                            </div>
                        </div>
                        {canEdit && (
                            <div className="flex shrink-0 gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 w-7 p-0"
                                    onClick={() => startEdit(a)}
                                >
                                    <Pencil className="h-3.5 w-3.5" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 w-7 p-0 text-red-500 hover:text-red-700"
                                    onClick={() => {
                                        if (
                                            confirm(
                                                `Remove "${a.name}" from personal assets?`,
                                            )
                                        ) {
                                            router.delete(
                                                `/operations/clients/${clientId}/personal-assets/${a.id}`,
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                </Button>
                            </div>
                        )}
                    </div>

                    {a.description && (
                        <p className="line-clamp-2 text-xs text-muted-foreground">
                            {a.description}
                        </p>
                    )}

                    <div className="space-y-1 text-xs text-muted-foreground">
                        {a.estimated_value &&
                            parseFloat(a.estimated_value) > 0 && (
                                <div className="flex items-center gap-1.5">
                                    <DollarSign className="h-3 w-3" />
                                    <span className="font-medium text-slate-700">
                                        $
                                        {parseFloat(
                                            a.estimated_value,
                                        ).toLocaleString('en-NZ', {
                                            minimumFractionDigits: 2,
                                        })}
                                    </span>
                                </div>
                            )}
                        {(a.site_name || a.room_name || a.location) && (
                            <div className="flex items-center gap-1.5">
                                <MapPin className="h-3 w-3" />
                                <span>
                                    {[a.site_name, a.room_name]
                                        .filter(Boolean)
                                        .join(' · ') || a.location}
                                </span>
                            </div>
                        )}
                        {a.serial_number && (
                            <div className="flex items-center gap-1.5">
                                <FileText className="h-3 w-3" />
                                <span className="font-mono text-[10px]">
                                    {a.serial_number}
                                </span>
                            </div>
                        )}
                        {a.funding_source && (
                            <div className="flex items-center gap-1.5">
                                <DollarSign className="h-3 w-3" />
                                <span>Funded by {a.funding_source}</span>
                            </div>
                        )}
                        {a.next_service_due && (
                            <div
                                className={`flex items-center gap-1.5 ${a.is_service_overdue ? 'font-medium text-amber-700' : ''}`}
                            >
                                <Clock className="h-3 w-3" />
                                <span>
                                    Service {a.is_service_overdue ? 'was' : ''}{' '}
                                    due{' '}
                                    {new Date(
                                        a.next_service_due,
                                    ).toLocaleDateString('en-NZ')}
                                </span>
                            </div>
                        )}
                        {a.warranty_expires_at && (
                            <div
                                className={`flex items-center gap-1.5 ${a.is_warranty_expired ? 'font-medium text-red-600' : a.is_warranty_expiring_soon ? 'font-medium text-amber-700' : ''}`}
                            >
                                <Shield className="h-3 w-3" />
                                <span>
                                    Warranty{' '}
                                    {a.is_warranty_expired
                                        ? 'expired'
                                        : 'expires'}{' '}
                                    {new Date(
                                        a.warranty_expires_at,
                                    ).toLocaleDateString('en-NZ')}
                                </span>
                            </div>
                        )}
                        {a.return_required && a.return_by && (
                            <div
                                className={`flex items-center gap-1.5 ${a.is_return_overdue ? 'font-medium text-red-600' : ''}`}
                            >
                                <AlertTriangle className="h-3 w-3" />
                                <span>
                                    Return by{' '}
                                    {new Date(a.return_by).toLocaleDateString(
                                        'en-NZ',
                                    )}
                                </span>
                            </div>
                        )}
                        {a.acquired_at && (
                            <div className="flex items-center gap-1.5">
                                <Calendar className="h-3 w-3" />
                                <span>
                                    Acquired{' '}
                                    {new Date(a.acquired_at).toLocaleDateString(
                                        'en-NZ',
                                    )}
                                </span>
                            </div>
                        )}
                    </div>

                    {/* Tracker info */}
                    {a.tracker && (
                        <div className="space-y-1 rounded-lg border border-sky-200 bg-gradient-to-r from-sky-50 to-blue-50 p-2">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-1.5">
                                    <span className="text-xs">📡</span>
                                    <span className="text-[11px] font-medium text-sky-800">
                                        {a.tracker.name}
                                    </span>
                                </div>
                                <span
                                    className={`inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[9px] font-medium ${a.tracker.status === 'online' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}
                                >
                                    <span
                                        className={`h-1.5 w-1.5 rounded-full ${a.tracker.status === 'online' ? 'bg-emerald-500' : 'bg-slate-400'}`}
                                    />
                                    {a.tracker.status}
                                </span>
                            </div>
                            <div className="flex flex-wrap gap-2 text-[10px] text-sky-700">
                                {a.tracker.battery != null && (
                                    <span>Battery: {a.tracker.battery}%</span>
                                )}
                                {a.tracker.speed != null && (
                                    <span>Speed: {a.tracker.speed} km/h</span>
                                )}
                                {a.tracker.last_seen_at && (
                                    <span>
                                        Seen:{' '}
                                        {new Date(
                                            a.tracker.last_seen_at,
                                        ).toLocaleString('en-NZ', {
                                            day: 'numeric',
                                            month: 'short',
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        })}
                                    </span>
                                )}
                            </div>
                        </div>
                    )}

                    {a.notes && (
                        <p className="line-clamp-2 rounded-lg bg-slate-50 p-2 text-[11px] text-slate-600">
                            {a.notes}
                        </p>
                    )}

                    {/* Quick status actions */}
                    {canEdit && a.status === 'active' && (
                        <div className="flex flex-wrap gap-1 pt-1 opacity-0 transition-opacity group-hover:opacity-100">
                            <button
                                onClick={() => changeStatus(a.id, 'in_repair')}
                                className="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700 transition-colors hover:bg-amber-100"
                            >
                                In Repair
                            </button>
                            <button
                                onClick={() => changeStatus(a.id, 'lost')}
                                className="rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-medium text-red-700 transition-colors hover:bg-red-100"
                            >
                                Lost
                            </button>
                            <button
                                onClick={() => changeStatus(a.id, 'damaged')}
                                className="rounded-full bg-orange-50 px-2 py-0.5 text-[10px] font-medium text-orange-700 transition-colors hover:bg-orange-100"
                            >
                                Damaged
                            </button>
                        </div>
                    )}
                    {canEdit && a.status === 'in_repair' && (
                        <div className="flex flex-wrap gap-1 pt-1">
                            <button
                                onClick={() => changeStatus(a.id, 'active')}
                                className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700 transition-colors hover:bg-emerald-100"
                            >
                                Repaired
                            </button>
                            <button
                                onClick={() => changeStatus(a.id, 'disposed')}
                                className="rounded-full bg-slate-50 px-2 py-0.5 text-[10px] font-medium text-slate-600 transition-colors hover:bg-slate-100"
                            >
                                Dispose
                            </button>
                        </div>
                    )}
                    {canEdit &&
                        (a.status === 'lost' || a.status === 'damaged') && (
                            <div className="flex flex-wrap gap-1 pt-1">
                                <button
                                    onClick={() => changeStatus(a.id, 'active')}
                                    className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700 transition-colors hover:bg-emerald-100"
                                >
                                    Found / Restored
                                </button>
                                <button
                                    onClick={() =>
                                        changeStatus(a.id, 'disposed')
                                    }
                                    className="rounded-full bg-slate-50 px-2 py-0.5 text-[10px] font-medium text-slate-600 transition-colors hover:bg-slate-100"
                                >
                                    Dispose
                                </button>
                            </div>
                        )}

                    <div className="flex items-center justify-between pt-0.5 text-[10px] text-muted-foreground">
                        {a.recorded_by && <span>By {a.recorded_by}</span>}
                        {a.created_at && (
                            <span>
                                {new Date(a.created_at).toLocaleDateString(
                                    'en-NZ',
                                )}
                            </span>
                        )}
                    </div>
                </CardContent>
            </Card>
        );
    };

    return (
        <div className="space-y-4">
            {/* Gradient stat cards */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                    <div className="text-xl font-bold text-violet-700">
                        {activeAssets.length}
                    </div>
                    <div className="text-[10px] tracking-wider text-violet-500 uppercase">
                        Active Items
                    </div>
                </div>
                <div className="rounded-xl border bg-gradient-to-br from-emerald-50 to-teal-50 p-3 text-center">
                    <div className="text-xl font-bold text-emerald-700">
                        $
                        {totalValue > 0
                            ? totalValue.toLocaleString('en-NZ', {
                                  minimumFractionDigits: 0,
                                  maximumFractionDigits: 0,
                              })
                            : '0'}
                    </div>
                    <div className="text-[10px] tracking-wider text-emerald-500 uppercase">
                        Est. Value (NZD)
                    </div>
                </div>
                <div
                    className={`rounded-xl border p-3 text-center ${needsAttention > 0 ? 'bg-gradient-to-br from-amber-50 to-orange-50' : 'bg-gradient-to-br from-slate-50 to-gray-50'}`}
                >
                    <div
                        className={`text-xl font-bold ${needsAttention > 0 ? 'text-amber-700' : 'text-slate-400'}`}
                    >
                        {needsAttention}
                    </div>
                    <div
                        className={`text-[10px] tracking-wider uppercase ${needsAttention > 0 ? 'text-amber-500' : 'text-slate-400'}`}
                    >
                        Needs Attention
                    </div>
                </div>
                <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-sky-50 p-3 text-center">
                    <div className="text-xl font-bold text-blue-700">
                        {categories.size}
                    </div>
                    <div className="text-[10px] tracking-wider text-blue-500 uppercase">
                        Categories
                    </div>
                </div>
            </div>

            {/* Toolbar: search, filters, sort, add button */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-1 flex-wrap items-center gap-2">
                    <div className="relative flex-1 sm:max-w-xs">
                        <Search className="absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search assets..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="h-9 pl-9"
                        />
                    </div>
                    <Select
                        value={filterCategory}
                        onValueChange={setFilterCategory}
                    >
                        <SelectTrigger className="h-9 w-[140px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Categories</SelectItem>
                            {Object.entries(ASSET_CATEGORIES).map(([k, v]) => (
                                <SelectItem key={k} value={k}>
                                    {v.icon} {v.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filterStatus}
                        onValueChange={setFilterStatus}
                    >
                        <SelectTrigger className="h-9 w-[130px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            {Object.entries(STATUS_CONFIG).map(([k, v]) => (
                                <SelectItem key={k} value={k}>
                                    {v.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={sortBy}
                        onValueChange={(v) => setSortBy(v as any)}
                    >
                        <SelectTrigger className="h-9 w-[120px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="added">Newest</SelectItem>
                            <SelectItem value="name">Name</SelectItem>
                            <SelectItem value="value">Value</SelectItem>
                            <SelectItem value="acquired">Acquired</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button
                        variant={groupByCategory ? 'default' : 'outline'}
                        size="sm"
                        className="h-9 gap-1.5 text-xs"
                        onClick={() => setGroupByCategory(!groupByCategory)}
                    >
                        <Package className="h-3.5 w-3.5" />
                        Group
                    </Button>
                </div>
                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-9 gap-1.5 text-xs"
                        onClick={() => window.print()}
                    >
                        <FileText className="h-3.5 w-3.5" />
                        Print Register
                    </Button>
                    {canEdit && (
                        <Button
                            size="sm"
                            className="h-9 gap-1.5 bg-violet-600 hover:bg-violet-700"
                            onClick={() => {
                                resetForm();
                                setShowForm(true);
                            }}
                        >
                            <Plus className="h-3.5 w-3.5" />
                            Add Asset
                        </Button>
                    )}
                </div>
            </div>

            {/* Add/Edit form */}
            {showForm && canEdit && (
                <Card className="border-violet-200">
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-violet-100 text-violet-600">
                                <Package className="h-4 w-4" />
                            </div>
                            {editingId ? 'Edit Asset' : 'Add Personal Asset'}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            {/* Basic Info */}
                            <div>
                                <p className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    Basic Information
                                </p>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <Label>Name *</Label>
                                        <Input
                                            value={form.data.name}
                                            onChange={(e) =>
                                                form.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. Wheelchair, PlayStation, TV"
                                        />
                                        {form.errors.name && (
                                            <p className="mt-1 text-xs text-red-600">
                                                {form.errors.name}
                                            </p>
                                        )}
                                    </div>
                                    <div>
                                        <Label>Category</Label>
                                        <Select
                                            value={form.data.category}
                                            onValueChange={(v) =>
                                                form.setData('category', v)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select category" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(
                                                    ASSET_CATEGORIES,
                                                ).map(([k, v]) => (
                                                    <SelectItem
                                                        key={k}
                                                        value={k}
                                                    >
                                                        {v.icon} {v.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Condition</Label>
                                        <Select
                                            value={form.data.condition}
                                            onValueChange={(v) =>
                                                form.setData('condition', v)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select condition" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="new">
                                                    New
                                                </SelectItem>
                                                <SelectItem value="good">
                                                    Good
                                                </SelectItem>
                                                <SelectItem value="fair">
                                                    Fair
                                                </SelectItem>
                                                <SelectItem value="poor">
                                                    Poor
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Serial / Model Number</Label>
                                        <Input
                                            value={form.data.serial_number}
                                            onChange={(e) =>
                                                form.setData(
                                                    'serial_number',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Estimated Value (NZD)</Label>
                                        <Input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value={form.data.estimated_value}
                                            onChange={(e) =>
                                                form.setData(
                                                    'estimated_value',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="0.00"
                                        />
                                    </div>
                                    <div>
                                        <Label>Site / Location</Label>
                                        <Select
                                            value={form.data.site_id}
                                            onValueChange={(v) => {
                                                form.setData('site_id', v);
                                                form.setData('room_id', '');
                                            }}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select site" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {locations.map((s) => (
                                                    <SelectItem
                                                        key={s.id}
                                                        value={String(s.id)}
                                                    >
                                                        {s.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Room</Label>
                                        {(() => {
                                            const selectedSite = locations.find(
                                                (s) =>
                                                    String(s.id) ===
                                                    form.data.site_id,
                                            );
                                            const rooms =
                                                selectedSite?.rooms ?? [];
                                            return rooms.length > 0 ? (
                                                <Select
                                                    value={form.data.room_id}
                                                    onValueChange={(v) =>
                                                        form.setData(
                                                            'room_id',
                                                            v,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select room" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {rooms.map((r) => (
                                                            <SelectItem
                                                                key={r.id}
                                                                value={String(
                                                                    r.id,
                                                                )}
                                                            >
                                                                {r.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            ) : (
                                                <Input
                                                    disabled
                                                    placeholder={
                                                        form.data.site_id
                                                            ? 'No rooms at this site'
                                                            : 'Select a site first'
                                                    }
                                                />
                                            );
                                        })()}
                                    </div>
                                    <div>
                                        <Label>Acquired Date</Label>
                                        <Input
                                            type="date"
                                            value={form.data.acquired_at}
                                            onChange={(e) =>
                                                form.setData(
                                                    'acquired_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Photo</Label>
                                        <Input
                                            type="file"
                                            accept="image/*"
                                            onChange={(e) =>
                                                form.setData(
                                                    'photo',
                                                    e.target.files?.[0] ?? null,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            {/* Ownership & Funding */}
                            <div>
                                <p className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    Ownership & Funding
                                </p>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <Label>Ownership</Label>
                                        <Select
                                            value={form.data.ownership}
                                            onValueChange={(v) =>
                                                form.setData('ownership', v)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(
                                                    OWNERSHIP_CONFIG,
                                                ).map(([k, v]) => (
                                                    <SelectItem
                                                        key={k}
                                                        value={k}
                                                    >
                                                        {v.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Funding Source</Label>
                                        <Input
                                            value={form.data.funding_source}
                                            onChange={(e) =>
                                                form.setData(
                                                    'funding_source',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. NASC, MOH, Family"
                                        />
                                    </div>
                                    <div className="flex items-end gap-4">
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                checked={
                                                    form.data.return_required
                                                }
                                                onCheckedChange={(v) =>
                                                    form.setData(
                                                        'return_required',
                                                        !!v,
                                                    )
                                                }
                                                id="return_required"
                                            />
                                            <Label
                                                htmlFor="return_required"
                                                className="text-sm"
                                            >
                                                Return required
                                            </Label>
                                        </div>
                                    </div>
                                    {form.data.return_required && (
                                        <div>
                                            <Label>Return By</Label>
                                            <Input
                                                type="date"
                                                value={form.data.return_by}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'return_by',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    )}
                                </div>
                            </div>

                            <Separator />

                            {/* Service & Warranty */}
                            <div>
                                <p className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    Service & Warranty
                                </p>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <Label>Last Serviced</Label>
                                        <Input
                                            type="date"
                                            value={form.data.last_serviced_at}
                                            onChange={(e) =>
                                                form.setData(
                                                    'last_serviced_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Next Service Due</Label>
                                        <Input
                                            type="date"
                                            value={form.data.next_service_due}
                                            onChange={(e) =>
                                                form.setData(
                                                    'next_service_due',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Service Provider</Label>
                                        <Input
                                            value={form.data.service_provider}
                                            onChange={(e) =>
                                                form.setData(
                                                    'service_provider',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. Enable NZ"
                                        />
                                    </div>
                                    <div>
                                        <Label>Warranty Expires</Label>
                                        <Input
                                            type="date"
                                            value={
                                                form.data.warranty_expires_at
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'warranty_expires_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>Insurance Reference</Label>
                                        <Input
                                            value={
                                                form.data.insurance_reference
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'insurance_reference',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label>GPS Tracker</Label>
                                        <Select
                                            value={
                                                form.data.tracker_hardware_id ||
                                                'none'
                                            }
                                            onValueChange={(v) =>
                                                form.setData(
                                                    'tracker_hardware_id',
                                                    v === 'none' ? '' : v,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="No tracker assigned" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">
                                                    None
                                                </SelectItem>
                                                {availableTrackers.map((t) => (
                                                    <SelectItem
                                                        key={t.id}
                                                        value={String(t.id)}
                                                    >
                                                        {t.name}
                                                        {t.serial
                                                            ? ` (${t.serial})`
                                                            : ''}{' '}
                                                        — {t.status}
                                                        {t.battery != null
                                                            ? ` ${t.battery}%`
                                                            : ''}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="flex items-end">
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                checked={
                                                    form.data.portal_visible
                                                }
                                                onCheckedChange={(v) =>
                                                    form.setData(
                                                        'portal_visible',
                                                        !!v,
                                                    )
                                                }
                                                id="portal_visible"
                                            />
                                            <Label
                                                htmlFor="portal_visible"
                                                className="text-sm"
                                            >
                                                Visible on family portal
                                            </Label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            {/* Description & Notes */}
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <Label>Description</Label>
                                    <Textarea
                                        rows={3}
                                        value={form.data.description}
                                        onChange={(e) =>
                                            form.setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Brief description of the item"
                                    />
                                </div>
                                <div>
                                    <Label>Notes</Label>
                                    <Textarea
                                        rows={3}
                                        value={form.data.notes}
                                        onChange={(e) =>
                                            form.setData(
                                                'notes',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Any additional notes"
                                    />
                                </div>
                            </div>

                            <div className="flex gap-2">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                    className="bg-violet-600 hover:bg-violet-700"
                                >
                                    {editingId ? 'Update Asset' : 'Add Asset'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={resetForm}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}

            {/* Asset grid */}
            {assets.length === 0 && !showForm ? (
                <Card className="border-dashed">
                    <CardContent className="flex flex-col items-center justify-center py-12">
                        <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-violet-50">
                            <Package className="h-7 w-7 text-violet-400" />
                        </div>
                        <p className="font-medium">No Personal Assets</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Track {firstName}'s belongings like wheelchairs,
                            electronics, and other items.
                        </p>
                        {canEdit && (
                            <Button
                                size="sm"
                                className="mt-3 gap-1.5 bg-violet-600 hover:bg-violet-700"
                                onClick={() => setShowForm(true)}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Add First Asset
                            </Button>
                        )}
                    </CardContent>
                </Card>
            ) : filtered.length === 0 ? (
                <Card className="border-dashed">
                    <CardContent className="flex flex-col items-center justify-center py-8">
                        <Search className="mb-2 h-8 w-8 text-slate-300" />
                        <p className="text-sm text-muted-foreground">
                            No assets match your filters
                        </p>
                        <Button
                            variant="link"
                            size="sm"
                            onClick={() => {
                                setSearch('');
                                setFilterCategory('all');
                                setFilterStatus('all');
                            }}
                        >
                            Clear filters
                        </Button>
                    </CardContent>
                </Card>
            ) : groupByCategory ? (
                <div className="space-y-4">
                    {Object.entries(grouped).map(([catKey, catAssets]) => {
                        const catConfig = ASSET_CATEGORIES[catKey];
                        return (
                            <div key={catKey}>
                                <div className="mb-2 flex items-center gap-2">
                                    <span className="text-lg">
                                        {catConfig?.icon ?? '📦'}
                                    </span>
                                    <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        {catConfig?.label ?? 'Other'}
                                    </span>
                                    <Badge
                                        variant="secondary"
                                        className="text-[10px]"
                                    >
                                        {catAssets.length}
                                    </Badge>
                                </div>
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {catAssets.map(renderAssetCard)}
                                </div>
                            </div>
                        );
                    })}
                </div>
            ) : (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {filtered.map(renderAssetCard)}
                </div>
            )}
        </div>
    );
}

// ─── Calendar Tab ────────────────────────────────────
const CAL_STYLES = `
.fc { --fc-border-color: transparent; --fc-today-bg-color: transparent; --fc-neutral-bg-color: transparent; --fc-page-bg-color: transparent; --fc-non-business-color: transparent; font-family: inherit; }
.fc .fc-scrollgrid, .fc .fc-scrollgrid-section > td, .fc .fc-scrollgrid-section > th { border: none !important; }
.fc table, .fc th, .fc td { border: none !important; }
.fc .fc-col-header { margin-bottom: 0.25rem; }
.fc .fc-col-header-cell { padding: 0.5rem 0; vertical-align: middle; }
.fc .fc-col-header-cell-cushion { display: flex; flex-direction: column; align-items: center; gap: 4px; text-decoration: none !important; padding: 0.375rem 0.75rem; border-radius: 1rem; }
.fc .fc-col-header-cell-cushion .fc-col-header-cell-content, .fc .fc-col-header-cell-cushion { font-weight: 500; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; color: hsl(var(--muted-foreground) / 0.6); }
.fc .fc-day-today .fc-col-header-cell-cushion { background: hsl(var(--primary)); color: white !important; border-radius: 1rem; font-weight: 700; }
.fc .fc-timegrid-axis-cushion, .fc .fc-timegrid-slot-label-cushion { font-size: 0.7rem; font-weight: 500; color: hsl(var(--muted-foreground) / 0.45); padding-right: 0.75rem; }
.fc .fc-timegrid-slot { height: 2.5em; }
.fc .fc-timegrid-slot-lane { border-top: 1px dotted rgba(139, 92, 246, 0.12) !important; }
.fc .fc-timegrid-slot-minor { border-top: 1px dotted rgba(139, 92, 246, 0.06) !important; }
.fc .fc-timegrid-col { border-right: 1px dotted rgba(139, 92, 246, 0.1) !important; }
.fc .fc-timegrid-col:last-child { border-right: none !important; }
.fc .fc-timegrid-divider, .fc .fc-timegrid-axis, .fc .fc-timegrid-body, .fc .fc-timegrid-slots td, .fc .fc-timegrid-slot-label { border: none !important; }
.fc .fc-timegrid-slots tr:not(:first-child) .fc-timegrid-slot-lane { border-top: 1px solid hsl(var(--border) / 0.1) !important; }
.fc .fc-event, .fc .fc-event-mirror { border: none !important; border-radius: 0.5rem !important; cursor: pointer; transition: all 0.15s ease; overflow: hidden; }
.fc .fc-event:hover { transform: scale(1.01); z-index: 10 !important; box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
.fc .fc-timegrid-event { border-radius: 0.5rem !important; margin: 1px 3px; min-height: 1.25em; border-left: 3px solid rgba(0,0,0,0.15) !important; }
.fc .fc-timegrid-event .fc-event-main { padding: 0.2rem 0.4rem; font-size: 0.7rem; line-height: 1.3; }
.fc .fc-daygrid-event { border-radius: 0.375rem !important; padding: 1px 6px; margin: 1px 2px; font-size: 0.7rem; line-height: 1.4; }
.fc .fc-daygrid-body { border: none !important; }
.fc .fc-scrollgrid-section-header td { border-bottom: 1px solid hsl(var(--border) / 0.15) !important; }
.fc .fc-highlight { background: hsl(var(--primary) / 0.06) !important; border: 2px dashed hsl(var(--primary) / 0.25) !important; border-radius: 0.625rem; }
.fc .fc-now-indicator-line { border-color: #ef4444 !important; border-width: 2px !important; z-index: 4; }
.fc .fc-now-indicator-arrow { border-color: #ef4444 !important; border-width: 5px !important; }
.fc .fc-day-today { background: hsl(var(--primary) / 0.02) !important; }
.fc .fc-daygrid-day-number { font-weight: 700; font-size: 0.85rem; padding: 0.375rem; color: hsl(var(--foreground)); }
.fc .fc-day-today .fc-daygrid-day-number { background: hsl(var(--primary)); color: white; border-radius: 9999px; width: 1.75rem; height: 1.75rem; display: inline-flex; align-items: center; justify-content: center; margin: 0.25rem; }
.fc .fc-daygrid-day { border-right: 1px dotted rgba(139, 92, 246, 0.1) !important; border-bottom: 1px dotted rgba(139, 92, 246, 0.1) !important; min-height: 5rem; }
.fc .fc-more-link { font-size: 0.7rem; font-weight: 600; color: hsl(var(--primary)); padding: 2px 4px; }
.fc .fc-popover { background: white !important; border: 1px solid #e2e8f0 !important; border-radius: 0.75rem !important; box-shadow: 0 10px 40px rgba(0,0,0,0.2) !important; z-index: 9999 !important; overflow: hidden; }
.fc .fc-popover-header { background: #f1f5f9 !important; padding: 0.625rem 0.75rem !important; font-weight: 600 !important; font-size: 0.875rem !important; color: #1e293b !important; border-bottom: 1px solid #e2e8f0 !important; }
.fc .fc-popover-body { padding: 0.5rem !important; max-height: 300px; overflow-y: auto; background: white !important; }
.fc .fc-popover-body .fc-daygrid-event { margin: 2px 0 !important; }
.fc .fc-popover-close { color: #64748b !important; font-size: 1.25rem !important; }
.dark .fc .fc-popover { background: #1e293b !important; border-color: #334155 !important; }
.dark .fc .fc-popover-header { background: #0f172a !important; color: #e2e8f0 !important; border-bottom-color: #334155 !important; }
.dark .fc .fc-popover-body { background: #1e293b !important; }
.fc .fc-list { border: 1px solid hsl(var(--border) / 0.2) !important; border-radius: 1rem; overflow: hidden; }
.fc .fc-list-event:hover td { background-color: hsl(var(--accent)); }
.fc .fc-list-day-cushion { background: hsl(var(--muted) / 0.15); font-weight: 600; }
.fc .fc-daygrid-day-events { max-height: 6rem; overflow: hidden; }
.calendar-context-menu { position: fixed; z-index: 99999; min-width: 200px; background: white; border: 1px solid #e2e8f0; border-radius: 0.75rem; box-shadow: 0 10px 40px rgba(0,0,0,0.2); padding: 0.375rem; }
.calendar-context-menu button { display: flex; align-items: center; gap: 0.5rem; width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem; transition: background 0.1s; text-align: left; border: none; background: none; cursor: pointer; color: #1e293b; }
.calendar-context-menu button:hover { background: #f1f5f9; }
.calendar-context-menu hr { margin: 0.25rem 0; border-color: #e2e8f0; }
.dark .calendar-context-menu { background: #1e293b; border-color: #334155; }
.dark .calendar-context-menu button { color: #e2e8f0; }
.dark .calendar-context-menu button:hover { background: #334155; }
`;

const CAL_CATEGORIES = [
    {
        dot: 'bg-blue-500',
        label: 'Shifts',
        icon: CalendarDays,
        bg: 'bg-blue-50 dark:bg-blue-950/40',
    },
    {
        dot: 'bg-green-500',
        label: 'Family Visits',
        icon: Users,
        bg: 'bg-green-50 dark:bg-green-950/40',
    },
    {
        dot: 'bg-pink-500',
        label: 'Medications',
        icon: Pill,
        bg: 'bg-pink-50 dark:bg-pink-950/40',
    },
    {
        dot: 'bg-amber-500',
        label: 'GP Visits',
        icon: Stethoscope,
        bg: 'bg-amber-50 dark:bg-amber-950/40',
    },
    {
        dot: 'bg-purple-500',
        label: 'Specialist',
        icon: Heart,
        bg: 'bg-purple-50 dark:bg-purple-950/40',
    },
    {
        dot: 'bg-cyan-500',
        label: 'Activities',
        icon: Calendar,
        bg: 'bg-cyan-50 dark:bg-cyan-950/40',
    },
    {
        dot: 'bg-violet-400',
        label: 'Family Notes',
        icon: ListTodo,
        bg: 'bg-violet-50 dark:bg-violet-950/40',
    },
];

const CAL_APPT_TYPES = [
    { value: 'gp_visit', label: 'GP Visit' },
    { value: 'specialist', label: 'Specialist' },
    { value: 'therapy', label: 'Therapy' },
    { value: 'activity', label: 'Activity' },
    { value: 'reminder', label: 'Reminder' },
    { value: 'other', label: 'Other' },
];

type CalViewKey = 'dayGridMonth' | 'timeGridWeek' | 'timeGridDay' | 'listWeek';
const CAL_VIEWS: { key: CalViewKey; label: string }[] = [
    { key: 'dayGridMonth', label: 'Month' },
    { key: 'timeGridWeek', label: 'Week' },
    { key: 'timeGridDay', label: 'Day' },
    { key: 'listWeek', label: 'List' },
];

function pad2(n: number) {
    return String(n).padStart(2, '0');
}
function toLocalISO(d: Date) {
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}T${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

function renderCalEventContent(eventInfo: {
    event: any;
    view: any;
    timeText: string;
}) {
    const props = eventInfo.event.extendedProps;
    const isTime = eventInfo.view.type.includes('timeGrid');
    const isDay = eventInfo.view.type === 'timeGridDay';
    return (
        <div className="flex h-full flex-col overflow-hidden">
            <span
                className={`truncate leading-tight font-bold ${isDay ? 'text-sm' : 'text-xs'}`}
            >
                {eventInfo.event.title}
            </span>
            {isTime && (
                <span
                    className={`truncate opacity-70 ${isDay ? 'text-xs' : 'text-[10px]'}`}
                >
                    {eventInfo.timeText}
                </span>
            )}
            {isTime && props.location && (
                <span className="mt-auto flex items-center gap-0.5 truncate text-[10px] opacity-50">
                    <MapPin className="h-2.5 w-2.5 shrink-0" />
                    {props.location}
                </span>
            )}
        </div>
    );
}

function ClientCalendarTab({
    clientId,
    clientFirstName,
    initialEvents = [],
}: {
    clientId: number;
    clientFirstName: string;
    initialEvents?: any[];
}) {
    const calRef = useRef<FullCalendar>(null);
    const [currentView, setCurrentView] = useState<CalViewKey>('timeGridWeek');
    const [calTitle, setCalTitle] = useState('');
    const [ctxMenu, setCtxMenu] = useState<{
        x: number;
        y: number;
        date: Date;
    } | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [calForm, setCalForm] = useState({
        title: '',
        appointment_type: 'gp_visit',
        starts_at: '',
        ends_at: '',
        location: '',
        provider_name: '',
        description: '',
        share_with_family: true,
    });
    const [detail, setDetail] = useState<any>(null);
    const [calEvents, setCalEvents] = useState<any[]>(initialEvents);

    useEffect(() => {
        const close = () => setCtxMenu(null);
        document.addEventListener('click', close);
        return () => document.removeEventListener('click', close);
    }, []);

    const goToday = useCallback(() => calRef.current?.getApi().today(), []);
    const goPrev = useCallback(() => calRef.current?.getApi().prev(), []);
    const goNext = useCallback(() => calRef.current?.getApi().next(), []);
    const changeView = useCallback((view: CalViewKey) => {
        calRef.current?.getApi().changeView(view);
        setCurrentView(view);
    }, []);

    // Fetch new events when navigating to different date ranges
    const fetchEvents = useCallback(
        async (info: any, successCallback: any, failureCallback: any) => {
            // First try AJAX fetch, fall back to initial events
            try {
                const token = (
                    document.querySelector(
                        'meta[name="csrf-token"]',
                    ) as HTMLMetaElement | null
                )?.content;
                const res = await fetch(
                    `/clients/${clientId}/calendar/events?start=${info.startStr}&end=${info.endStr}`,
                    {
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                        },
                    },
                );
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();
                successCallback(Array.isArray(data) ? data : []);
            } catch (e) {
                console.error('Calendar fetch error (using server data):', e);
                // Fall back to server-provided initial events
                successCallback(calEvents);
            }
        },
        [clientId, calEvents],
    );

    const submitAppointment = async () => {
        if (!calForm.title.trim() || !calForm.starts_at) return;
        const token = (
            document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null
        )?.content;
        await fetch(`/clients/${clientId}/calendar/appointments`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
            credentials: 'same-origin',
            body: JSON.stringify(calForm),
        });
        setCreateOpen(false);
        calRef.current?.getApi().refetchEvents();
    };

    const openCreateFromCtx = () => {
        if (ctxMenu) {
            const end = new Date(ctxMenu.date);
            end.setHours(end.getHours() + 1);
            setCalForm({
                ...calForm,
                starts_at: toLocalISO(ctxMenu.date),
                ends_at: toLocalISO(end),
                title: '',
                description: '',
                location: '',
                provider_name: '',
                appointment_type: 'gp_visit',
                share_with_family: true,
            });
        }
        setCtxMenu(null);
        setCreateOpen(true);
    };

    return (
        <div className="space-y-4">
            <style dangerouslySetInnerHTML={{ __html: CAL_STYLES }} />

            <div className="flex gap-5">
                {/* Sidebar */}
                <div className="hidden w-52 shrink-0 space-y-3 lg:block">
                    <Card className="overflow-hidden">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-semibold">
                                {clientFirstName}'s Calendar
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-0.5 pb-4">
                            {CAL_CATEGORIES.map((cat) => {
                                const Icon = cat.icon;
                                return (
                                    <div
                                        key={cat.label}
                                        className={`flex items-center gap-3 rounded-lg px-3 py-2 ${cat.bg}`}
                                    >
                                        <span
                                            className={`h-2.5 w-2.5 rounded-full ${cat.dot}`}
                                        />
                                        <Icon className="h-3.5 w-3.5 opacity-50" />
                                        <span className="text-sm font-medium">
                                            {cat.label}
                                        </span>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                </div>

                {/* Main */}
                <div className="min-w-0 flex-1">
                    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-3">
                            <h2 className="text-xl font-bold tracking-tight">
                                {calTitle}
                            </h2>
                            <div className="flex items-center">
                                <button
                                    onClick={goPrev}
                                    className="inline-flex h-8 w-8 items-center justify-center rounded-full transition-colors hover:bg-muted"
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                </button>
                                <button
                                    onClick={goNext}
                                    className="inline-flex h-8 w-8 items-center justify-center rounded-full transition-colors hover:bg-muted"
                                >
                                    <ChevronRight className="h-4 w-4" />
                                </button>
                            </div>
                            <button
                                onClick={goToday}
                                className="rounded-full border px-4 py-1 text-sm font-semibold shadow-sm transition-colors hover:bg-accent"
                            >
                                Today
                            </button>
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                size="sm"
                                className="gap-1.5"
                                onClick={() => {
                                    setCalForm({
                                        ...calForm,
                                        starts_at: toLocalISO(new Date()),
                                        ends_at: '',
                                        title: '',
                                    });
                                    setCreateOpen(true);
                                }}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Schedule
                            </Button>
                            <div className="inline-flex items-center gap-1 rounded-full border bg-muted/20 p-1">
                                {CAL_VIEWS.map((v) => (
                                    <button
                                        key={v.key}
                                        onClick={() => changeView(v.key)}
                                        className={`rounded-full px-3 py-1 text-xs font-semibold transition-all ${currentView === v.key ? 'bg-foreground text-background shadow' : 'text-muted-foreground hover:text-foreground'}`}
                                    >
                                        {v.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>

                    <div
                        className="overflow-hidden rounded-2xl border bg-card shadow-sm"
                        onContextMenu={(e) => {
                            const target = e.target as HTMLElement;
                            if (
                                !target.closest(
                                    '.fc-timegrid-slot-lane, .fc-daygrid-day, .fc-timegrid-col',
                                )
                            )
                                return;
                            e.preventDefault();
                            setCtxMenu({
                                x: e.clientX,
                                y: e.clientY,
                                date: new Date(),
                            });
                        }}
                    >
                        <FullCalendar
                            ref={calRef}
                            plugins={[
                                dayGridPlugin,
                                timeGridPlugin,
                                listPlugin,
                                interactionPlugin,
                            ]}
                            initialView="timeGridWeek"
                            headerToolbar={false}
                            events={fetchEvents}
                            eventClick={(info) =>
                                setDetail({
                                    title: info.event.title,
                                    start: info.event.start,
                                    end: info.event.end,
                                    ...info.event.extendedProps,
                                })
                            }
                            datesSet={(arg) => {
                                setCalTitle(arg.view.title);
                                setCurrentView(arg.view.type as CalViewKey);
                            }}
                            select={(arg) => {
                                setCalForm({
                                    ...calForm,
                                    starts_at: toLocalISO(arg.start),
                                    ends_at: toLocalISO(arg.end),
                                    title: '',
                                    description: '',
                                    location: '',
                                    provider_name: '',
                                    appointment_type: 'gp_visit',
                                    share_with_family: true,
                                });
                                setCreateOpen(true);
                                calRef.current?.getApi().unselect();
                            }}
                            height="auto"
                            timeZone="local"
                            slotMinTime="00:00:00"
                            slotMaxTime="24:00:00"
                            scrollTime="07:00:00"
                            allDaySlot={true}
                            nowIndicator={true}
                            eventContent={renderCalEventContent}
                            selectable={true}
                            selectMirror={true}
                            businessHours={{
                                daysOfWeek: [1, 2, 3, 4, 5],
                                startTime: '06:00',
                                endTime: '22:00',
                            }}
                            slotDuration="00:30:00"
                            dayMaxEvents={4}
                            moreLinkClick="popover"
                            eventMaxStack={3}
                            slotEventOverlap={false}
                            eventOverlap={false}
                            stickyHeaderDates={true}
                            firstDay={1}
                            eventTimeFormat={{
                                hour: '2-digit',
                                minute: '2-digit',
                                meridiem: false,
                            }}
                        />
                    </div>
                </div>
            </div>

            {/* Context Menu */}
            {ctxMenu && (
                <div
                    className="calendar-context-menu"
                    style={{ top: ctxMenu.y, left: ctxMenu.x }}
                    onClick={(e) => e.stopPropagation()}
                >
                    <button onClick={openCreateFromCtx}>
                        <Plus className="h-4 w-4 text-primary" />
                        <span>Schedule Appointment</span>
                    </button>
                    <hr />
                    <button
                        onClick={() => {
                            setCtxMenu(null);
                            changeView('timeGridDay');
                        }}
                    >
                        <Calendar className="h-4 w-4 text-muted-foreground" />
                        <span>View Day</span>
                    </button>
                </div>
            )}

            {/* Event Detail */}
            {detail && (
                <Card className="border-primary/20">
                    <CardContent className="p-4">
                        <div className="flex items-start justify-between">
                            <div>
                                <h3 className="text-sm font-semibold">
                                    {detail.title}
                                </h3>
                                <p className="mt-1 text-xs text-muted-foreground capitalize">
                                    {detail.type?.replace(/_/g, ' ')}
                                    {detail.appointment_type
                                        ? ` — ${detail.appointment_type.replace(/_/g, ' ')}`
                                        : ''}
                                </p>
                                {detail.start && (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {new Date(detail.start).toLocaleString(
                                            'en-NZ',
                                            {
                                                weekday: 'short',
                                                day: 'numeric',
                                                month: 'short',
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            },
                                        )}
                                        {detail.end
                                            ? ` — ${new Date(detail.end).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })}`
                                            : ''}
                                    </p>
                                )}
                                {detail.location && (
                                    <p className="mt-1 text-xs">
                                        <MapPin className="mr-1 inline h-3 w-3" />
                                        {detail.location}
                                    </p>
                                )}
                                {detail.provider_name && (
                                    <p className="mt-0.5 text-xs">
                                        <Stethoscope className="mr-1 inline h-3 w-3" />
                                        {detail.provider_name}
                                    </p>
                                )}
                                {detail.staff_name && (
                                    <p className="mt-0.5 text-xs">
                                        <Users className="mr-1 inline h-3 w-3" />
                                        {detail.staff_name}
                                    </p>
                                )}
                                {detail.medication_name && (
                                    <p className="mt-0.5 text-xs">
                                        <Pill className="mr-1 inline h-3 w-3" />
                                        {detail.medication_name}
                                        {detail.dosage
                                            ? ` — ${detail.dosage}`
                                            : ''}
                                    </p>
                                )}
                                {detail.description && (
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {detail.description}
                                    </p>
                                )}
                                {detail.notes && (
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {detail.notes}
                                    </p>
                                )}
                            </div>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setDetail(null)}
                            >
                                Close
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Create Appointment Dialog */}
            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Schedule Appointment</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label>Title *</Label>
                                <Input
                                    value={calForm.title}
                                    onChange={(e) =>
                                        setCalForm({
                                            ...calForm,
                                            title: e.target.value,
                                        })
                                    }
                                    placeholder="GP Visit - Dr. Patel"
                                    autoFocus
                                />
                            </div>
                            <div>
                                <Label>Type</Label>
                                <Select
                                    value={calForm.appointment_type}
                                    onValueChange={(v) =>
                                        setCalForm({
                                            ...calForm,
                                            appointment_type: v,
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CAL_APPT_TYPES.map((t) => (
                                            <SelectItem
                                                key={t.value}
                                                value={t.value}
                                            >
                                                {t.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label>Start *</Label>
                                <Input
                                    type="datetime-local"
                                    value={calForm.starts_at}
                                    onChange={(e) =>
                                        setCalForm({
                                            ...calForm,
                                            starts_at: e.target.value,
                                        })
                                    }
                                />
                            </div>
                            <div>
                                <Label>End</Label>
                                <Input
                                    type="datetime-local"
                                    value={calForm.ends_at}
                                    onChange={(e) =>
                                        setCalForm({
                                            ...calForm,
                                            ends_at: e.target.value,
                                        })
                                    }
                                />
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label>Location</Label>
                                <Input
                                    value={calForm.location}
                                    onChange={(e) =>
                                        setCalForm({
                                            ...calForm,
                                            location: e.target.value,
                                        })
                                    }
                                    placeholder="Riverside Medical Centre"
                                />
                            </div>
                            <div>
                                <Label>Provider</Label>
                                <Input
                                    value={calForm.provider_name}
                                    onChange={(e) =>
                                        setCalForm({
                                            ...calForm,
                                            provider_name: e.target.value,
                                        })
                                    }
                                    placeholder="Dr. Patel"
                                />
                            </div>
                        </div>
                        <div>
                            <Label>Notes</Label>
                            <Textarea
                                value={calForm.description}
                                onChange={(e) =>
                                    setCalForm({
                                        ...calForm,
                                        description: e.target.value,
                                    })
                                }
                                rows={2}
                            />
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={calForm.share_with_family}
                                onCheckedChange={(v) =>
                                    setCalForm({
                                        ...calForm,
                                        share_with_family: !!v,
                                    })
                                }
                            />
                            Share with family portal
                        </label>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setCreateOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={
                                !calForm.title.trim() || !calForm.starts_at
                            }
                            onClick={submitAppointment}
                        >
                            <Plus className="mr-2 h-4 w-4" />
                            Create
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
