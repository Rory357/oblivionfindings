import { ClientEditDialog } from '@/components/client-edit-dialog';
import ClientLocationTab, {
    type ClientLocationData,
} from '@/components/client-location-tab';
import RecentClientsStrip from '@/components/client-profile/recent-clients-strip';
import { type ClientSafety } from '@/components/client-safety-ribbon';
import type { AbcEntryRow } from '@/components/clients/profile/abc-dialog';
import {
    ProfileDialogs,
    type ProfileDialogState,
} from '@/components/clients/profile/dialog-host';
import {
    AlertRibbon,
    ClientProfileHero,
    type HeroAlert,
    type HeroBadge,
    type HeroNextShift,
    type HeroVital,
    type MoreMenuItem,
} from '@/components/clients/profile/hero';
import {
    GroupPillRail,
    TabSearchPalette,
    TierTwoTabs,
    type ProfileNavGroup,
} from '@/components/clients/profile/nav';
import {
    buildAboutTiles,
    OverviewDesignGrid,
} from '@/components/clients/profile/overview-grid';
import { BehaviourAbcTab } from '@/components/clients/profile/tabs/behaviour-abc';
import { type HealthSummary } from '@/components/clinical/health-summary-card';
import { RaRegisterSection } from '@/components/health-safety/risk-assessments/ra-register-section';
import type {
    RaPickers,
    RaRow,
} from '@/components/health-safety/risk-assessments/types';
import PageShell from '@/components/page-shell';
import { ClientPrivacyPanel } from '@/components/privacy/client-privacy-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Ring } from '@/components/wizard/primitives';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { formatDateTime as formatDT } from '@/lib/fleet-utils';
import { ClientClinicalRecordLaunchers } from '@/pages/health-clinical/components/client-clinical-launchers';
import { DailyNoteWizard } from '@/pages/operations/clients/dialogs/daily-note-wizard';
import { QuickNoteDialog } from '@/pages/operations/clients/dialogs/quick-note-dialog';
import {
    canonicalProfileTab,
    CLIENT_TAB_GROUPS,
    groupForTab,
    profileDialogQuery,
    profileDialogStateFromSearch,
    resolveVisibleProfileTab,
    updateClientProfileQuery,
    type ClientTabGroupKey,
} from '@/pages/operations/clients/tabs/_groups';
import { ActionsReviewsTab } from '@/pages/operations/clients/tabs/actions-reviews';
import { clientAppointmentActionAllowed } from '@/pages/operations/clients/tabs/client-appointment-access';
import { CommunicationNotesTab } from '@/pages/operations/clients/tabs/communication-notes';
import {
    DailyNotesTab,
    type ClientDailyNote,
} from '@/pages/operations/clients/tabs/daily-notes';
import { FirstAidTab } from '@/pages/operations/clients/tabs/first-aid-tab';
import { HealthMonitoringTab } from '@/pages/operations/clients/tabs/health-monitoring';
import { IncidentsTab } from '@/pages/operations/clients/tabs/incidents-tab';
import {
    AssessmentsTab,
    ClientCalendarTab,
    PersonalAssetsTab,
    PhotoGalleryTab,
} from '@/pages/operations/clients/tabs/legacy-profile-sections';
import { MarTab } from '@/pages/operations/clients/tabs/mar';
import { RhythmsRoutinesTab } from '@/pages/operations/clients/tabs/rhythms-routines';
import { ClientTimelineTab } from '@/pages/operations/clients/tabs/timeline-tab';
import { useCreateShiftLauncher } from '@/pages/operations/shifts/components/use-create-shift-launcher';
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
    ClipboardList,
    Clock,
    DollarSign,
    FileText,
    Flag,
    FolderOpen,
    Globe,
    GraduationCap,
    Heart,
    HeartPulse,
    Home,
    ListTodo,
    MessageSquare as MsgIcon,
    Navigation,
    Package,
    Phone,
    Pill,
    Plus,
    Send,
    Shield,
    ShieldAlert,
    Stethoscope,
    Target,
    Truck,
    User,
    Users,
    Utensils,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { AuditHistoryTab } from './tabs/audit-history';
import { CareSupportPlanTab } from './tabs/care-support-plan';
import { DocumentsTab } from './tabs/documents';
import { FamilyTreeTab } from './tabs/family-tree';
import { FinanceTab } from './tabs/finance';
import { FoodMealTab } from './tabs/food-meal';
import { GoalsPathTab } from './tabs/goals-path';
import { LeaveExcursionsTab } from './tabs/leave-excursions';
import { PersonalDetailsTab } from './tabs/personal-details';
import { RespiteTab } from './tabs/respite';
import { RiskManagementTab } from './tabs/risk-management';
import { WorkersTab } from './tabs/workers';

export { clientAppointmentActionAllowed } from '@/pages/operations/clients/tabs/client-appointment-access';

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

type ClientTab =
    | {
          key: TabKey;
          label: string;
          icon: typeof User;
          show: boolean;
          count?: number;
          href?: undefined;
      }
    | {
          key: string;
          label: string;
          icon: typeof User;
          show: boolean;
          count?: number;
          href: string;
      };

type ClientNavigationTab = Extract<ClientTab, { href?: undefined }>;

type ClientProfileSectionAccess = Partial<
    Record<
        | 'medical'
        | 'health'
        | 'notes'
        | 'timeline'
        | 'care_plans'
        | 'assessments'
        | 'behaviour'
        | 'finance'
        | 'consents'
        | 'risks'
        | 'incidents'
        | 'first_aid'
        | 'calendar'
        | 'documents'
        | 'portal_access'
        | 'audit'
        | 'privacy'
        | 'respite'
        | 'onboarding'
        | 'daily_living'
        | 'meals'
        | 'agreements'
        | 'family_notes'
        | 'photos'
        | 'personal_assets'
        | 'tracking'
        | 'transport'
        | 'actions_reviews',
        boolean
    >
>;

type Props = {
    profile_section_access?: ClientProfileSectionAccess;
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
        room?: { id: number; name: string; notes?: string | null } | null;
        sleep_target_hours?: number | string | null;
        service_context?: {
            id: number;
            type: string | null;
            name: string;
        } | null;
        transport_needs?: string[] | null;
        transport_notes?: string | null;
        support_workers: Array<{
            id: number;
            name: string;
            email?: string | null;
        }>;
    };
    medical?: {
        profile: any | null;
        medications: Array<any>;
        conditions: Array<any>;
        emergency_contacts: Array<any>;
    };
    support_plan?: any | null;
    assessments?: Array<any>;
    documents?: Array<any>;
    photos?: GalleryPhoto[];
    personal_assets?: PersonalAsset[];
    portal_users?: Array<any>;
    events?: Array<any>;
    handover?: Array<any>;
    timeline_summary?: {
        total?: number;
        loaded?: number;
        has_more?: boolean;
        pinned_handover_total?: number;
        pinned_handover_loaded?: number;
        pinned_handover_has_more?: boolean;
    };
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
        allocation?: {
            allocated?: number;
            used?: number;
            booked?: number;
            remaining?: number;
            period_label?: string | null;
            funding_source?: string | null;
        } | null;
    };
    meal_logs?: {
        today?: Array<any>;
        history?: Array<any>;
        summary?: {
            eaten?: number;
            expected?: number;
            status?: string;
        };
    };
    assignable_workers?: Array<{
        id: number;
        name: string;
        email?: string | null;
    }>;
    health_summary?: HealthSummary | null;
    onboarding?: {
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
    staff_preparation?: {
        summary: {
            assigned: number;
            prepared: number;
            in_progress: number;
            needs_attention: number;
        };
        workers: Array<{
            user_id: number;
            name: string;
            role?: string | null;
            employee_profile_id?: number | null;
            checklist_id?: number | null;
            status: string;
            tasks_total: number;
            tasks_completed: number;
            progress_percentage: number;
            due_date?: string | null;
            is_overdue: boolean;
        }>;
    } | null;
    can: {
        edit: boolean;
        update_client?: boolean;
        assign_workers: boolean;
        view_family_chat?: boolean;
        send_family_chat?: boolean;
        record_medication_administration?: boolean;
        update_risk_level?: boolean;
        navigate_daily_notes?: boolean;
        navigate_care_plans?: boolean;
        navigate_risks?: boolean;
        navigate_medical?: boolean;
        navigate_calendar?: boolean;
        navigate_workers?: boolean;
        navigate_family_portal?: boolean;
        navigate_site?: boolean;
        create_note?: boolean;
        create_daily_note?: boolean;
        create_quick_note?: boolean;
        create_communication_note?: boolean;
        pin_handover?: boolean;
        manage_onboarding?: boolean;
        manage_onboarding_checklist?: boolean;
        create_onboarding_workflow?: boolean;
        manage_onboarding_workflow?: boolean;
        view_hr_onboarding?: boolean;
        manage_family_notes?: boolean;
        create_shift?: boolean;
        record_observation?: boolean;
        record_clinical_observation?: boolean;
        record_event?: boolean;
        create_risks?: boolean;
        update_risks?: boolean;
        delete_risks?: boolean;
        care_plans_view?: boolean;
        care_plans_create?: boolean;
        care_plans_update?: boolean;
        care_plans_delete?: boolean;
        manage_care_plan_goals?: boolean;
        edit_path_plan?: boolean;
    };
    location?: ClientLocationData;
    transport?: {
        stats: {
            transports_30d: number;
            outings_30d: number;
            incidents_30d: number;
        };
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
            shift: {
                id: number;
                starts_at: string | null;
                shift_type: string;
            } | null;
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
    | 'personal_details'
    | 'onboarding'
    | 'medical'
    | 'mar'
    | 'meal_prefs'
    | 'care_plans'
    | 'goals_path'
    | 'observations'
    | 'calendar'
    | 'progress_notes'
    | 'communication_notes'
    | 'rhythms_routines'
    | 'health_monitoring'
    | 'actions_reviews'
    | 'risk_management'
    | 'incidents_accidents'
    | 'first_aid'
    | 'service_agreements'
    | 'support_plan'
    | 'assessments'
    | 'timeline'
    | 'family_tree'
    | 'audit_history'
    | 'documents'
    | 'photos'
    | 'consents'
    | 'consent-requests'
    | 'privacy'
    | 'portal'
    | 'family_notes'
    | 'respite'
    | 'finance'
    | 'leave_excursions'
    | 'personal_assets'
    | 'transport'
    | 'location'
    | 'assignments';

const CLIENT_PROFILE_RESTRICTED_TABS: Partial<
    Record<
        TabKey,
        {
            section: keyof ClientProfileSectionAccess;
            propKeys: readonly string[];
        }
    >
> = {
    onboarding: { section: 'onboarding', propKeys: ['onboarding'] },
    progress_notes: {
        section: 'notes',
        propKeys: ['client_daily_notes'],
    },
    communication_notes: {
        section: 'notes',
        propKeys: ['communication_notes'],
    },
    timeline: { section: 'timeline', propKeys: ['events'] },
    meal_prefs: { section: 'meals', propKeys: ['meal_logs'] },
    rhythms_routines: {
        section: 'daily_living',
        propKeys: ['client_routines'],
    },
    care_plans: {
        section: 'care_plans',
        propKeys: ['care_plans_summary'],
    },
    goals_path: {
        section: 'care_plans',
        propKeys: ['care_plans_summary', 'path_plan'],
    },
    assessments: { section: 'assessments', propKeys: ['assessments'] },
    observations: {
        section: 'behaviour',
        propKeys: ['behaviour_patterns'],
    },
    medical: { section: 'medical', propKeys: ['medical'] },
    mar: { section: 'medical', propKeys: ['emar_summary', 'medical'] },
    health_monitoring: {
        section: 'health',
        propKeys: ['health_monitoring'],
    },
    finance: { section: 'finance', propKeys: ['client_finance'] },
    consents: { section: 'consents', propKeys: ['consents'] },
    'consent-requests': {
        section: 'consents',
        propKeys: ['consent_request_list'],
    },
    risk_management: { section: 'risks', propKeys: ['client_risks'] },
    incidents_accidents: {
        section: 'incidents',
        propKeys: ['client_incidents'],
    },
    first_aid: { section: 'first_aid', propKeys: ['first_aid_records'] },
    calendar: { section: 'calendar', propKeys: ['calendar_events'] },
    transport: { section: 'transport', propKeys: ['transport'] },
    leave_excursions: {
        section: 'daily_living',
        propKeys: ['leave_excursions'],
    },
    personal_assets: {
        section: 'personal_assets',
        propKeys: ['personal_assets'],
    },
    service_agreements: {
        section: 'agreements',
        propKeys: ['client_agreements'],
    },
    documents: { section: 'documents', propKeys: ['documents'] },
    photos: { section: 'photos', propKeys: ['photos'] },
    family_tree: { section: 'portal_access', propKeys: ['next_of_kins'] },
    portal: { section: 'portal_access', propKeys: ['portal_users'] },
    family_notes: { section: 'family_notes', propKeys: ['family_notes'] },
    actions_reviews: {
        section: 'actions_reviews',
        propKeys: ['actions_reviews'],
    },
    location: { section: 'tracking', propKeys: ['location'] },
    audit_history: { section: 'audit', propKeys: ['audit_history'] },
    privacy: { section: 'privacy', propKeys: ['data_subject_requests'] },
    respite: { section: 'respite', propKeys: ['respite'] },
};

export function clientProfileTabHasSectionAccess(
    tab: string,
    access: ClientProfileSectionAccess | null | undefined,
    props: Record<string, unknown>,
): boolean {
    const restriction = CLIENT_PROFILE_RESTRICTED_TABS[tab as TabKey];
    if (!restriction) return true;

    const explicitAccess = access?.[restriction.section];
    if (typeof explicitAccess === 'boolean') return explicitAccess;

    return restriction.propKeys.some((propKey) =>
        Object.prototype.hasOwnProperty.call(props, propKey),
    );
}

type DailyNotesFilter = 'all' | 'flagged' | 'follow_up' | 'drafts';

/* Folded sub-tab constants removed in the profile redesign — every tab is
 * now first-class inside its group (see tabs/_groups.ts). */

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

function isClientNavigationTab(tab: ClientTab): tab is ClientNavigationTab {
    return typeof tab.href === 'undefined';
}

function shiftTypeLabel(value?: string | null) {
    return String(value ?? 'standard').replace(/_/g, ' ');
}

function seriesTimeLabel(startsTime?: string | null, endsTime?: string | null) {
    if (!startsTime || !endsTime) return '';
    const overnight = endsTime <= startsTime;
    return `${startsTime}-${endsTime}${overnight ? ' overnight' : ''}`;
}

function initialDailyNotesFilter(): DailyNotesFilter {
    if (typeof window === 'undefined') return 'all';

    const params = new URLSearchParams(window.location.search);
    if (params.get('flagged') === '1' && params.get('reviewed') === '0') {
        return 'flagged';
    }
    if (params.get('drafts') === '1') return 'drafts';
    if (params.get('follow_up') === '1') return 'follow_up';

    return 'all';
}

function isEditableShortcutTarget(target: EventTarget | null) {
    if (!(target instanceof HTMLElement)) return false;

    return Boolean(
        target.closest(
            'input, textarea, select, [contenteditable="true"], [role="textbox"]',
        ),
    );
}

export default function ClientShow({
    profile_section_access: profileSectionAccessProp,
    client,
    medical = {
        profile: null,
        medications: [],
        conditions: [],
        emergency_contacts: [],
    },
    support_plan = null,
    assessments = [],
    documents = [],
    photos = [],
    personal_assets = [],
    portal_users = [],
    events = [],
    handover = [],
    onboarding = {
        items: [],
        completed: 0,
        total: 0,
        percent: 0,
        status: 'incomplete',
    },
    shifts_summary,
    respite,
    can,
    location,
    transport,
}: Props) {
    const pageProps = usePage().props as any;
    const { auth, labels } = pageProps;
    const profileSectionAccess =
        profileSectionAccessProp ??
        (pageProps.profile_section_access as
            | ClientProfileSectionAccess
            | undefined);
    const canShowProfileTab = useCallback(
        (tabKey: TabKey) =>
            clientProfileTabHasSectionAccess(
                tabKey,
                profileSectionAccess,
                pageProps,
            ),
        [pageProps, profileSectionAccess],
    );
    const safety = pageProps.safety as ClientSafety | null | undefined;
    const createShiftLauncher = useCreateShiftLauncher();
    const nextShiftSummary = shifts_summary?.next ?? null;
    const recurringShiftSeries = useMemo(
        () => shifts_summary?.recurring ?? [],
        [shifts_summary?.recurring],
    );
    const nextShiftTypeLabel = String(
        nextShiftSummary?.shift_type ?? 'standard',
    ).replace('_', ' ');
    const respiteCan = auth?.can?.respite ?? {};
    const consentsCan = auth?.can?.consents ?? {};
    const privacyCan = auth?.can?.privacy ?? {};
    const serviceAgreementsCan = auth?.can?.service_agreements ?? {};
    const canCreateAppointment = clientAppointmentActionAllowed(
        'create',
        auth?.can?.calendar,
    );
    const dataSubjectRequests = pageProps.data_subject_requests ?? [];
    const consents = pageProps.consents ?? [];
    const familyNotesOpenCount = pageProps.family_notes_open_count ?? 0;
    const pendingVisitCount = pageProps.pending_visit_count ?? 0;
    const pendingConsentRequestsCount =
        pageProps.pending_consent_requests_count ?? 0;
    const emarSummary = pageProps.emar_summary ?? null;
    const carePlansSummary = pageProps.care_plans_summary ?? {};
    const workingCarePlan =
        carePlansSummary?.working_plan ??
        carePlansSummary?.review_plan ??
        carePlansSummary?.active_plan ??
        null;
    const staffPreparation = pageProps.staff_preparation ?? null;
    const carePlanGoals = useMemo(
        () => workingCarePlan?.goals ?? [],
        [workingCarePlan?.goals],
    );
    const dailyNoteShiftOptions = useMemo(() => {
        return [nextShiftSummary, ...(recurringShiftSeries ?? [])]
            .filter((shift: any) => shift?.id)
            .map((shift: any) => ({
                id: Number(shift.id),
                label: [
                    shift.starts_at
                        ? formatDateTimeLong(shift.starts_at)
                        : shift.starts_time,
                    shift.shift_type
                        ? shiftTypeLabel(shift.shift_type)
                        : 'Shift',
                ]
                    .filter(Boolean)
                    .join(' - '),
            }));
    }, [nextShiftSummary, recurringShiftSeries]);
    const dailyNoteGoalOptions = useMemo(
        () =>
            carePlanGoals.map((goal: any) => ({
                id: goal.id,
                label: goal.title ?? goal.name ?? `Goal ${goal.id}`,
            })),
        [carePlanGoals],
    );
    const hasClientDailyNotesProp = Object.prototype.hasOwnProperty.call(
        pageProps,
        'client_daily_notes',
    );
    const hasCommunicationNotesProp = Object.prototype.hasOwnProperty.call(
        pageProps,
        'communication_notes',
    );
    const hasHealthMonitoringProp = Object.prototype.hasOwnProperty.call(
        pageProps,
        'health_monitoring',
    );
    const hasClientRoutinesProp = Object.prototype.hasOwnProperty.call(
        pageProps,
        'client_routines',
    );
    const hasActionsReviewsProp = Object.prototype.hasOwnProperty.call(
        pageProps,
        'actions_reviews',
    );
    const clientDailyNotes = useMemo(
        () => pageProps.client_daily_notes ?? [],
        [pageProps.client_daily_notes],
    );
    const dailyNotesSummary = pageProps.daily_notes_summary ?? {};
    const communicationNotes = useMemo(
        () => pageProps.communication_notes ?? [],
        [pageProps.communication_notes],
    );
    const healthMonitoring = pageProps.health_monitoring ?? {};
    const clientRoutines = useMemo(
        () => pageProps.client_routines ?? [],
        [pageProps.client_routines],
    );
    const actionsReviews = pageProps.actions_reviews ?? [];
    const actionsReviewsSummary = pageProps.actions_reviews_summary ?? {};
    const timelineSummary = pageProps.timeline_summary ?? {};
    const progressNotesCan = auth?.can?.progress_notes ?? {};
    const clientAgreements = pageProps.client_agreements ?? [];
    const serviceAgreementOptions = useMemo(
        () =>
            (pageProps.client_agreements ?? []).map((agreement: any) => ({
                value: String(agreement.id),
                label: agreement.title ?? `Agreement #${agreement.id}`,
            })),
        [pageProps.client_agreements],
    );
    const familyNotes = pageProps.family_notes ?? [];
    const name = `${client.first_name} ${client.last_name}`.trim();
    const getInitials = useInitials();
    const photoForm = useForm<{ photo: File | null }>({ photo: null });
    const removePhotoForm = useForm({});

    const tabs: ClientTab[] = useMemo(
        () => [
            { key: 'profile', label: 'Overview', icon: User, show: true },
            {
                key: 'personal_details',
                label: 'Personal Details',
                icon: User,
                show: true,
            },
            {
                key: 'onboarding',
                label: 'Onboarding',
                icon: CheckCircle2,
                show: canShowProfileTab('onboarding'),
                count: onboarding?.total,
            },
            {
                key: 'medical',
                label: 'Medical',
                icon: Heart,
                show: canShowProfileTab('medical'),
            },
            {
                key: 'mar',
                label: 'MAR',
                icon: Pill,
                show: canShowProfileTab('mar'),
            },
            {
                key: 'meal_prefs',
                label: 'Food & Meal',
                icon: Utensils,
                show: canShowProfileTab('meal_prefs'),
            },
            {
                key: 'observations',
                label: 'Behaviour / ABC',
                icon: Stethoscope,
                show: canShowProfileTab('observations'),
            },
            {
                key: 'care_plans',
                label: 'Care & Support Plan',
                icon: Target,
                show: canShowProfileTab('care_plans'),
            },
            {
                key: 'goals_path',
                label: 'Goals Path',
                icon: Target,
                show: canShowProfileTab('goals_path'),
                count: carePlanGoals.length,
            },
            {
                key: 'progress_notes',
                label: 'Daily Notes',
                icon: ClipboardList,
                show: canShowProfileTab('progress_notes'),
                count:
                    dailyNotesSummary?.flagged_open ||
                    dailyNotesSummary?.drafts ||
                    undefined,
            },
            {
                key: 'communication_notes',
                label: 'Communication',
                icon: MsgIcon,
                show: canShowProfileTab('communication_notes'),
                count: communicationNotes.length || undefined,
            },
            {
                key: 'rhythms_routines',
                label: 'Rhythms & Routines',
                icon: Clock,
                show: canShowProfileTab('rhythms_routines'),
                count:
                    clientRoutines.filter((routine: any) =>
                        String(routine.body ?? '').trim(),
                    ).length || undefined,
            },
            {
                key: 'health_monitoring',
                label: 'Health Monitoring',
                icon: Activity,
                show: canShowProfileTab('health_monitoring'),
            },
            {
                key: 'risk_management',
                label: 'Risk Management',
                icon: ShieldAlert,
                show: canShowProfileTab('risk_management'),
                count:
                    (pageProps.client_risks ?? []).filter((r: any) => r.active)
                        .length || undefined,
            },
            {
                key: 'incidents_accidents',
                label: 'Incidents & Accidents',
                icon: AlertTriangle,
                show: canShowProfileTab('incidents_accidents'),
                count: (pageProps.client_incidents ?? []).length || undefined,
            },
            {
                key: 'first_aid',
                label: 'First Aid',
                icon: HeartPulse,
                show: canShowProfileTab('first_aid'),
                count: (pageProps.first_aid_records ?? []).length || undefined,
            },
            {
                key: 'calendar',
                label: 'Appointments',
                icon: Calendar,
                show: canShowProfileTab('calendar'),
            },
            {
                key: 'actions_reviews',
                label: 'Actions & Reviews',
                icon: ListTodo,
                show: canShowProfileTab('actions_reviews'),
                count: actionsReviewsSummary?.has_more
                    ? undefined
                    : actionsReviewsSummary?.open || undefined,
            },
            {
                key: 'service_agreements',
                label: 'Agreements',
                icon: FileText,
                show: canShowProfileTab('service_agreements'),
            },
            {
                key: 'assessments',
                label: 'Assessments',
                icon: BookOpen,
                show: canShowProfileTab('assessments'),
            },
            {
                key: 'timeline',
                label: 'Timeline',
                icon: Activity,
                show: canShowProfileTab('timeline'),
            },
            {
                key: 'family_tree',
                label: 'Family Tree',
                icon: Users,
                show: canShowProfileTab('family_tree'),
            },
            {
                key: 'audit_history',
                label: 'Audit History',
                icon: Shield,
                show: canShowProfileTab('audit_history'),
            },
            {
                key: 'documents',
                label: 'Documents',
                icon: FolderOpen,
                show: canShowProfileTab('documents'),
                count: documents?.length,
            },
            {
                key: 'photos',
                label: 'Photos',
                icon: Camera,
                show: canShowProfileTab('photos'),
                count: photos?.length,
            },
            {
                key: 'personal_assets',
                label: 'Personal Inventory',
                icon: Package,
                show: canShowProfileTab('personal_assets'),
                count: personal_assets?.length,
            },
            {
                key: 'finance',
                label: 'Finance',
                icon: DollarSign,
                show: canShowProfileTab('finance'),
            },
            {
                key: 'leave_excursions',
                label: 'Leave & Excursions',
                icon: CalendarDays,
                show: canShowProfileTab('leave_excursions'),
            },
            {
                key: 'transport',
                label: 'Transport',
                icon: Truck,
                show: canShowProfileTab('transport'),
                count:
                    (transport?.stats?.transports_30d ?? 0) +
                        (transport?.stats?.outings_30d ?? 0) || undefined,
            },
            {
                key: 'consents',
                label: 'Consents',
                icon: Shield,
                show: canShowProfileTab('consents'),
            },
            {
                key: 'privacy',
                label: 'Privacy',
                icon: Shield,
                show: canShowProfileTab('privacy'),
                count: dataSubjectRequests.length || undefined,
            },
            {
                key: 'consent-requests',
                label: 'Consent Requests',
                icon: Send,
                show: canShowProfileTab('consent-requests'),
                count: pendingConsentRequestsCount || undefined,
            },
            {
                key: 'location',
                label: 'Location',
                icon: Navigation,
                show: canShowProfileTab('location'),
            },
            {
                key: 'portal',
                label: 'Family Portal',
                icon: Users,
                show: canShowProfileTab('portal'),
            },
            {
                key: 'family_notes',
                label: 'Family Notes',
                icon: ListTodo,
                show: canShowProfileTab('family_notes'),
                count: familyNotesOpenCount,
            },
            {
                key: 'respite',
                label: 'Respite',
                icon: Calendar,
                show: canShowProfileTab('respite'),
            },
            {
                key: 'assignments',
                label: 'Workers',
                icon: Users,
                show: Boolean(can.navigate_workers),
            },
        ],
        [
            can.navigate_workers,
            canShowProfileTab,
            carePlanGoals.length,
            clientRoutines,
            communicationNotes.length,
            dailyNotesSummary?.drafts,
            dailyNotesSummary?.flagged_open,
            actionsReviewsSummary?.has_more,
            actionsReviewsSummary?.open,
            dataSubjectRequests.length,
            documents?.length,
            photos?.length,
            personal_assets?.length,
            onboarding?.total,
            familyNotesOpenCount,
            pageProps.first_aid_records,
            pageProps.client_incidents,
            pageProps.client_risks,
            pendingConsentRequestsCount,
            transport?.stats?.outings_30d,
            transport?.stats?.transports_30d,
        ],
    );

    // Support ?tab=onboarding deep linking from dashboard
    const requestedInitialTab =
        typeof window !== 'undefined'
            ? (new URLSearchParams(window.location.search).get(
                  'tab',
              ) as TabKey) || 'profile'
            : 'profile';
    const initialTab = canonicalProfileTab(requestedInitialTab) as TabKey;
    const [tab, setTab] = useState<TabKey>(initialTab);
    const [dailyNotesFilter, setDailyNotesFilter] = useState<DailyNotesFilter>(
        () => initialDailyNotesFilter(),
    );

    // ── Redesign shell state: grouped two-tier nav + dialog host ──
    const [openGroup, setOpenGroup] = useState<ClientTabGroupKey>(() =>
        groupForTab(initialTab),
    );
    const [paletteOpen, setPaletteOpen] = useState(false);
    const canOpenProfileDialog = useCallback(
        (key: string) => {
            if (key === 'goal') {
                return Boolean(can.manage_care_plan_goals);
            }
            if (key === 'edit_path_plan') {
                return Boolean(can.edit_path_plan);
            }
            if (key === 'add_onboarding_step') {
                return Boolean(can.manage_onboarding_workflow);
            }
            if (key === 'daily_note') {
                return Boolean(can.create_daily_note);
            }
            if (key === 'quick_note') {
                return Boolean(can.create_quick_note);
            }
            if (key === 'comm_note') {
                return Boolean(can.create_communication_note);
            }
            if (key === 'family_chat') {
                return Boolean(can.view_family_chat);
            }
            if (key === 'emar') {
                return Boolean(can.record_medication_administration);
            }
            if (key === 'edit_profile') {
                return Boolean(can.update_client);
            }
            if (key === 'add_risk') {
                return Boolean(can.create_risks);
            }
            if (key === 'edit_risk') {
                return Boolean(can.update_risks);
            }
            if (key === 'appointment') {
                return canCreateAppointment;
            }

            return true;
        },
        [
            can.edit_path_plan,
            can.create_communication_note,
            can.create_daily_note,
            can.create_quick_note,
            can.create_risks,
            can.manage_care_plan_goals,
            can.manage_onboarding_workflow,
            can.record_medication_administration,
            can.update_client,
            can.update_risks,
            can.view_family_chat,
            canCreateAppointment,
        ],
    );
    const readProfileDialogState = useCallback(
        (search: string) => {
            const state = profileDialogStateFromSearch(search, {
                carePlans: [
                    carePlansSummary?.active_plan,
                    carePlansSummary?.review_plan,
                ].filter((plan): plan is any => Boolean(plan?.id)),
                goals: carePlanGoals as any[],
                dailyNotes: [
                    ...clientDailyNotes,
                    ...communicationNotes,
                ] as any[],
                risks: (pageProps.client_risks ?? []) as any[],
                carePlanContext: { serviceAgreementOptions },
            });

            return state && canOpenProfileDialog(state.key) ? state : null;
        },
        [
            canOpenProfileDialog,
            carePlanGoals,
            carePlansSummary?.active_plan,
            carePlansSummary?.review_plan,
            clientDailyNotes,
            communicationNotes,
            pageProps.client_risks,
            serviceAgreementOptions,
        ],
    );
    const [profileDialog, setProfileDialog] = useState<ProfileDialogState>(
        () =>
            typeof window === 'undefined'
                ? null
                : readProfileDialogState(window.location.search),
    );
    const authorizedProfileDialog =
        profileDialog && canOpenProfileDialog(profileDialog.key)
            ? profileDialog
            : null;
    const editDialogOpen = authorizedProfileDialog?.key === 'edit_profile';
    const quickNoteOpen = authorizedProfileDialog?.key === 'quick_note';
    const dailyNoteOpen = authorizedProfileDialog?.key === 'daily_note';
    const communicationNoteOpen = authorizedProfileDialog?.key === 'comm_note';
    // Bumped when the ABC dialog closes so the lazy-fetched ABC log re-fetches.
    const [abcRefreshToken, setAbcRefreshToken] = useState(0);

    const openProfileDialog = useCallback(
        (key: string, ctx?: Record<string, unknown>) => {
            if (!canOpenProfileDialog(key)) return;

            setProfileDialog({ key, ctx });
            updateClientProfileQuery(profileDialogQuery(key, ctx), 'push');
        },
        [canOpenProfileDialog],
    );
    const closeProfileDialog = useCallback(() => {
        setProfileDialog(null);
        updateClientProfileQuery({ dialog: null, record: null }, 'replace');
    }, []);

    useEffect(() => {
        setOpenGroup(groupForTab(tab));
    }, [tab]);

    // Lazy-load transport data when tab is first opened
    const [transportLoaded, setTransportLoaded] = useState(!!transport);
    const updateProfileQuery = useCallback(
        (
            values: Record<string, string | null>,
            mode: 'push' | 'replace' = 'push',
        ) => updateClientProfileQuery(values, mode),
        [],
    );
    useEffect(() => {
        if (typeof window === 'undefined') return;
        const requested =
            new URLSearchParams(window.location.search).get('tab') ?? 'profile';
        const canonical = canonicalProfileTab(requested);
        if (canonical !== requested) {
            updateProfileQuery({ tab: canonical }, 'replace');
        }
    }, [updateProfileQuery]);
    const handleTabChange = useCallback(
        (newTab: TabKey) => {
            setTab(newTab);
            updateProfileQuery({ tab: newTab });
            if (newTab === 'transport' && !transportLoaded) {
                router.reload({
                    only: ['transport'],
                    onSuccess: () => setTransportLoaded(true),
                });
            }
        },
        [transportLoaded, updateProfileQuery],
    );

    // Browser back/forward should keep the tab state in sync.
    useEffect(() => {
        if (typeof window === 'undefined') return;
        const handlePop = () => {
            const params = new URLSearchParams(window.location.search);
            const next = canonicalProfileTab(
                (params.get('tab') as TabKey) || 'profile',
            ) as TabKey;
            setTab(next);
            setProfileDialog(readProfileDialogState(window.location.search));
        };
        window.addEventListener('popstate', handlePop);
        return () => window.removeEventListener('popstate', handlePop);
    }, [readProfileDialogState]);
    const openDailyNotes = useCallback(
        (filter: DailyNotesFilter = 'all') => {
            setDailyNotesFilter(filter);
            setTab('progress_notes');
            updateProfileQuery({
                tab: 'progress_notes',
                flagged: filter === 'flagged' ? '1' : null,
                reviewed: filter === 'flagged' ? '0' : null,
                drafts: filter === 'drafts' ? '1' : null,
                follow_up: filter === 'follow_up' ? '1' : null,
            });
        },
        [updateProfileQuery],
    );
    useEffect(() => {
        let chordReset: number | undefined;
        let waitingForDailyChord = false;

        const handleShortcut = (event: KeyboardEvent) => {
            if (
                event.defaultPrevented ||
                event.altKey ||
                event.ctrlKey ||
                event.metaKey ||
                quickNoteOpen ||
                dailyNoteOpen ||
                communicationNoteOpen ||
                isEditableShortcutTarget(event.target)
            ) {
                return;
            }

            const key = event.key.toLowerCase();
            if (event.shiftKey && key === 'n' && can.create_daily_note) {
                event.preventDefault();
                openProfileDialog('daily_note');
                return;
            }
            if (!event.shiftKey && key === 'n' && can.create_quick_note) {
                event.preventDefault();
                openProfileDialog('quick_note');
                return;
            }
            if (key === 'g') {
                waitingForDailyChord = true;
                window.clearTimeout(chordReset);
                chordReset = window.setTimeout(() => {
                    waitingForDailyChord = false;
                }, 1200);
                return;
            }
            if (waitingForDailyChord && key === 'd') {
                event.preventDefault();
                waitingForDailyChord = false;
                window.clearTimeout(chordReset);
                openDailyNotes('all');
            }
        };

        window.addEventListener('keydown', handleShortcut);
        return () => {
            window.removeEventListener('keydown', handleShortcut);
            window.clearTimeout(chordReset);
        };
    }, [
        communicationNoteOpen,
        can.create_daily_note,
        can.create_quick_note,
        dailyNoteOpen,
        openDailyNotes,
        openProfileDialog,
        quickNoteOpen,
    ]);

    const respiteBookings = respite?.bookings ?? [];
    const respiteRequests = respite?.requests ?? [];

    // ── Wizard flow context (flows.tsx submits to real endpoints) ──
    const preferredName = client.preferred_name || client.first_name;
    const flowContext = useMemo(() => {
        const staff = new globalThis.Map<string, string>();
        if (client.key_worker?.id) {
            staff.set(String(client.key_worker.id), client.key_worker.name);
        }
        (client.support_workers ?? []).forEach(
            (w: { id: number; name: string }) =>
                staff.set(String(w.id), w.name),
        );
        return {
            clientId: client.id,
            clientLabel: client.site ? `${name} · ${client.site.name}` : name,
            preferredName,
            staffOptions: Array.from(staff, ([value, label]) => ({
                value,
                label,
            })),
            goalOptions: dailyNoteGoalOptions.map(
                (g: { id: number; label: string }) => ({
                    value: String(g.id),
                    label: g.label,
                }),
            ),
            consentTypeOptions: (
                (pageProps.consent_type_options ?? []) as {
                    id: number;
                    name: string;
                }[]
            ).map((t) => ({ value: String(t.id), label: t.name })),
            fundOptions: (
                (pageProps.client_finance?.funds ?? []) as {
                    id: number;
                    name?: string | null;
                    purpose?: string | null;
                }[]
            ).map((f) => ({
                value: String(f.id),
                label: f.name ?? f.purpose ?? `Fund #${f.id}`,
            })),
            carePlanId: workingCarePlan?.id ?? null,
            carePlanTitle: workingCarePlan?.title ?? null,
            onboardingWorkflowId: onboarding?.workflow?.id ?? null,
            canSendFamilyChat: Boolean(can.send_family_chat),
        };
    }, [
        client.id,
        client.key_worker,
        client.site,
        client.support_workers,
        name,
        preferredName,
        dailyNoteGoalOptions,
        pageProps.consent_type_options,
        pageProps.client_finance?.funds,
        workingCarePlan?.id,
        workingCarePlan?.title,
        onboarding?.workflow?.id,
        can.send_family_chat,
    ]);

    // ── Grouped nav registry: 6 groups × first-class tabs ──
    const visibleGroups = useMemo<ProfileNavGroup[]>(() => {
        const groupIcons: Record<
            ClientTabGroupKey,
            React.ComponentType<{ className?: string }>
        > = {
            snapshot: User,
            daily: ClipboardList,
            plans: Target,
            health: Heart,
            operations: Calendar,
            governance: Users,
            other: User,
        };
        return CLIENT_TAB_GROUPS.map((group) => ({
            key: group.key,
            label: group.label,
            icon: groupIcons[group.key],
            tabs: group.tabKeys
                .map((key) => tabs.find((t) => t.key === key))
                .filter((t): t is ClientTab => Boolean(t?.show))
                .map((t) => ({
                    key: t.key,
                    label: t.label,
                    icon: t.icon,
                    count: t.count ?? undefined,
                    href: isClientNavigationTab(t) ? undefined : t.href,
                })),
        })).filter((group) => group.tabs.length > 0);
    }, [tabs]);

    const activeGroup =
        visibleGroups.find((g) => g.key === openGroup) ?? visibleGroups[0];

    useEffect(() => {
        const resolved = resolveVisibleProfileTab(tab, visibleGroups);
        if (resolved === tab) return;

        setTab(resolved as TabKey);
        updateProfileQuery({ tab: resolved }, 'replace');
    }, [tab, updateProfileQuery, visibleGroups]);

    // "/" or ⌘K opens the tab search palette.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (
                (event.key === '/' &&
                    !event.metaKey &&
                    !event.ctrlKey &&
                    !isEditableShortcutTarget(event.target)) ||
                (event.key.toLowerCase() === 'k' &&
                    (event.metaKey || event.ctrlKey))
            ) {
                event.preventDefault();
                setPaletteOpen(true);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const [respondingId, setRespondingId] = useState<number | null>(null);
    const [responseText, setResponseText] = useState('');
    const [assigningId, setAssigningId] = useState<number | null>(null);

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: labels?.['client.plural'] ?? 'Clients',
                    href: '/operations/clients',
                },
                { title: name, href: `/operations/clients/${client.id}` },
            ]}
        >
            <Head title={name} />

            <PageShell>
                {/* ── Hero Header ──────────────────────────────── */}
                {(() => {
                    // ── Identity chips ──
                    const heroChips: {
                        key: string;
                        icon?: React.ComponentType<{ className?: string }>;
                        label: string;
                    }[] = [];
                    if (client.nhi_number)
                        heroChips.push({
                            key: 'nhi',
                            label: `NHI ${client.nhi_number}`,
                        });
                    if (client.site)
                        heroChips.push({
                            key: 'site',
                            icon: Home,
                            label: client.room?.name
                                ? `${client.site.name} · ${client.room.name}`
                                : client.site.name,
                        });
                    if (client.service_start_date)
                        heroChips.push({
                            key: 'since',
                            icon: Clock,
                            label: `Since ${new Date(
                                client.service_start_date,
                            ).toLocaleDateString('en-NZ', {
                                month: 'short',
                                year: 'numeric',
                            })}`,
                        });
                    if (client.key_worker?.name)
                        heroChips.push({
                            key: 'keyworker',
                            icon: User,
                            label: client.key_worker.name,
                        });
                    if (client.funding_type)
                        heroChips.push({
                            key: 'funding',
                            label: client.funding_type,
                        });
                    if (client.service_context)
                        heroChips.push({
                            key: 'service',
                            label: client.service_context.name,
                        });

                    // ── Status badges ──
                    const heroBadges: HeroBadge[] = [];
                    if (client.risk_level)
                        heroBadges.push({
                            key: 'risk',
                            label: `${client.risk_level} risk`,
                            icon: ShieldAlert,
                            tone:
                                client.risk_level === 'critical'
                                    ? 'critical'
                                    : client.risk_level === 'high'
                                      ? 'warning'
                                      : client.risk_level === 'medium'
                                        ? 'info'
                                        : 'success',
                        });
                    if (client.safeguarding_flag)
                        heroBadges.push({
                            key: 'safeguarding',
                            label: 'Safeguarding',
                            icon: Shield,
                            tone: 'critical',
                        });

                    const carePlanSummary =
                        (pageProps as any).care_plans_summary ?? {};
                    const carePlanGoals =
                        carePlanSummary.active_plan?.goals ?? [];
                    const carePlanDone = carePlanGoals.filter(
                        (g: any) => g.status === 'completed',
                    ).length;
                    if (carePlanSummary.active_plan)
                        heroBadges.push({
                            key: 'plan',
                            label: 'Care plan active',
                            icon: Target,
                            tone: 'info',
                        });

                    // ── Identity line ──
                    const age = client.date_of_birth
                        ? Math.floor(
                              (Date.now() -
                                  new Date(client.date_of_birth).getTime()) /
                                  31557600000,
                          )
                        : null;
                    const identityLine = [
                        client.preferred_name && client.preferred_name !== name
                            ? `“${client.preferred_name}”`
                            : null,
                        client.preferred_pronouns,
                        age != null ? `${age}y` : null,
                        client.ethnicity,
                    ]
                        .filter(Boolean)
                        .join(' · ');

                    // ── Vitals strip (real chart data) ──
                    const now = Date.now();
                    const weekAgo = now - 7 * 86400000;
                    const moodRatings = (clientDailyNotes as any[])
                        .filter(
                            (n) =>
                                n.mood_rating != null &&
                                n.occurred_at &&
                                new Date(n.occurred_at).getTime() >= weekAgo,
                        )
                        .map((n) => Number(n.mood_rating));
                    const moodAvg = moodRatings.length
                        ? moodRatings.reduce((a, b) => a + b, 0) /
                          moodRatings.length
                        : null;
                    const seizureEntries =
                        (healthMonitoring?.seizure as any[]) ?? [];
                    const lastSeizure = seizureEntries[0]?.occurred_at
                        ? new Date(seizureEntries[0].occurred_at).getTime()
                        : null;
                    const seizureFreeDays = lastSeizure
                        ? Math.max(
                              0,
                              Math.floor((now - lastSeizure) / 86400000),
                          )
                        : null;
                    const fluidToday = (
                        (healthMonitoring?.fluid as any[]) ?? []
                    )
                        .filter(
                            (e) =>
                                e.direction !== 'out' &&
                                e.occurred_at &&
                                new Date(e.occurred_at).toDateString() ===
                                    new Date().toDateString(),
                        )
                        .reduce((sum, e) => sum + (e.volume_ml ?? 0), 0);
                    const fluidTarget =
                        ((client as any).fluid_intake_min_ml as
                            | number
                            | null) ?? null;
                    const notesThisWeek = (clientDailyNotes as any[]).filter(
                        (n) =>
                            (n.occurred_at ?? n.created_at) &&
                            new Date(n.occurred_at ?? n.created_at).getTime() >=
                                weekAgo,
                    ).length;
                    const mealLogsPayload = (pageProps as any).meal_logs ?? {};
                    const mealSummary = mealLogsPayload.summary ?? {};
                    const mealsEaten =
                        mealSummary.eaten_today != null
                            ? Number(mealSummary.eaten_today)
                            : null;
                    const mealsExpected = Number(
                        mealSummary.expected_today ?? 3,
                    );
                    const mealsOnTrack =
                        mealsEaten != null && mealsEaten >= mealsExpected;
                    const sleepSummary =
                        (healthMonitoring as any)?.sleep_summary ?? {};
                    const sleepAverage =
                        sleepSummary.average_7_nights != null
                            ? Number(sleepSummary.average_7_nights)
                            : null;
                    const sleepTarget = Number(
                        sleepSummary.target_hours ??
                            (client as any).sleep_target_hours ??
                            7,
                    );

                    const heroVitals: HeroVital[] = [
                        {
                            key: 'meals',
                            label: 'Meals',
                            value:
                                mealsEaten != null
                                    ? `${mealsEaten}/${mealsExpected}`
                                    : '—',
                            trend:
                                mealsEaten == null
                                    ? 'flat'
                                    : mealsOnTrack
                                      ? 'up'
                                      : mealsEaten > 0
                                        ? 'flat'
                                        : 'down',
                            detail:
                                mealsEaten == null
                                    ? 'No meals logged today'
                                    : mealsOnTrack
                                      ? 'On track today'
                                      : 'Meals logged today',
                        },
                        {
                            key: 'sleep',
                            label: 'Sleep',
                            value:
                                sleepAverage != null
                                    ? `${sleepAverage.toFixed(1)}h`
                                    : '—',
                            trend:
                                sleepAverage == null
                                    ? 'flat'
                                    : sleepAverage >= sleepTarget
                                      ? 'up'
                                      : 'down',
                            detail: `Target ${sleepTarget}h`,
                        },
                        {
                            key: 'mood',
                            label: 'Mood',
                            value:
                                moodAvg != null
                                    ? `${moodAvg.toFixed(1)}/10`
                                    : '—',
                            trend:
                                moodAvg == null
                                    ? 'flat'
                                    : moodAvg >= 6
                                      ? 'up'
                                      : moodAvg >= 4
                                        ? 'flat'
                                        : 'down',
                            detail: moodRatings.length
                                ? `${moodRatings.length} rating${moodRatings.length > 1 ? 's' : ''} this week`
                                : 'No mood ratings this week',
                        },
                        {
                            key: 'seizures',
                            label: 'Seizures',
                            value:
                                seizureFreeDays != null
                                    ? `${seizureFreeDays}d`
                                    : '—',
                            trend:
                                seizureFreeDays != null && seizureFreeDays >= 14
                                    ? 'up'
                                    : 'flat',
                            detail:
                                seizureFreeDays != null
                                    ? 'days since last seizure'
                                    : 'No seizures recorded',
                        },
                        {
                            key: 'fluids',
                            label: 'Fluids today',
                            value: fluidToday
                                ? `${(fluidToday / 1000).toFixed(1)}L`
                                : '—',
                            trend:
                                fluidTarget && fluidToday
                                    ? fluidToday >= fluidTarget
                                        ? 'up'
                                        : 'down'
                                    : 'flat',
                            detail: fluidTarget
                                ? `Target ${(fluidTarget / 1000).toFixed(1)}L+`
                                : 'No target set',
                        },
                        {
                            key: 'notes',
                            label: 'Notes',
                            value: String(notesThisWeek),
                            trend: notesThisWeek > 0 ? 'up' : 'flat',
                            detail: 'daily notes this week',
                        },
                    ];

                    // ── Next shift tile ──
                    const ns = nextShiftSummary as any;
                    const handoverEvents = (handover as any[]) ?? [];
                    const handoverSnippet =
                        handoverEvents[0]?.body ??
                        handoverEvents[0]?.subject ??
                        null;
                    const heroNextShift: HeroNextShift | null = ns
                        ? (() => {
                              const starts = ns.starts_at
                                  ? new Date(ns.starts_at)
                                  : null;
                              const ends = ns.ends_at
                                  ? new Date(ns.ends_at)
                                  : null;
                              const hoursAway = starts
                                  ? (starts.getTime() - now) / 3600000
                                  : null;
                              return {
                                  when: starts
                                      ? `${starts.toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short' })} ${starts.toLocaleTimeString('en-NZ', { hour: 'numeric', minute: '2-digit' })}${ends ? ` – ${ends.toLocaleTimeString('en-NZ', { hour: 'numeric', minute: '2-digit' })}` : ''}`
                                      : 'Scheduled',
                                  countdown:
                                      hoursAway != null && hoursAway > 0
                                          ? hoursAway < 1
                                              ? 'soon'
                                              : hoursAway < 24
                                                ? `in ${Math.round(hoursAway)}h`
                                                : null
                                          : hoursAway != null && hoursAway > -12
                                            ? 'now'
                                            : null,
                                  staffName: ns.staff?.name ?? null,
                                  typeLabel: nextShiftTypeLabel,
                                  tasksTotal: ns.task_count ?? 0,
                                  tasksDone: Math.max(
                                      0,
                                      (ns.task_count ?? 0) -
                                          (ns.incomplete_task_count ?? 0),
                                  ),
                                  location:
                                      ns.location ?? client.site?.name ?? null,
                                  breakLabel: ns.expected_break_minutes
                                      ? `${ns.expected_break_minutes} min expected`
                                      : null,
                                  medsLabel:
                                      (emarSummary?.active_medications_count ??
                                          0) > 0
                                          ? `${emarSummary.active_medications_count} active med${emarSummary.active_medications_count > 1 ? 's' : ''}`
                                          : null,
                                  handoverSnippet,
                              };
                          })()
                        : null;

                    // ── Safety strip (allergies + critical risks + care flags) ──
                    const heroSafety = {
                        allergies: (safety?.allergies ?? []).map(
                            (a) => a.label,
                        ),
                        alerts: [
                            ...(safety?.critical_risks ?? []).map(
                                (r) => r.label,
                            ),
                            ...(safety?.care_flags ?? []).map((f) => f.label),
                        ],
                    };

                    // ── "Needs attention" ribbon ──
                    const heroAlerts: HeroAlert[] = [];
                    if (
                        progressNotesCan.review &&
                        (dailyNotesSummary.flagged_open ?? 0) > 0
                    )
                        heroAlerts.push({
                            key: 'flagged-notes',
                            tone: 'warning',
                            icon: Flag,
                            label: `${dailyNotesSummary.flagged_open} note${dailyNotesSummary.flagged_open > 1 ? 's' : ''} need review`,
                            onClick: () => openDailyNotes('flagged'),
                        });
                    if ((actionsReviewsSummary.open ?? 0) > 0)
                        heroAlerts.push({
                            key: 'open-actions',
                            tone:
                                (actionsReviewsSummary.critical ?? 0) > 0
                                    ? 'critical'
                                    : 'warning',
                            icon: ListTodo,
                            label: `${actionsReviewsSummary.open}${actionsReviewsSummary.has_more ? '+' : ''} open action${actionsReviewsSummary.open > 1 ? 's' : ''}`,
                            detail:
                                (actionsReviewsSummary.critical ?? 0) > 0
                                    ? `${actionsReviewsSummary.critical} critical${actionsReviewsSummary.has_more ? ' shown' : ''}`
                                    : undefined,
                            onClick: () => handleTabChange('actions_reviews'),
                        });
                    if ((emarSummary?.pending_alerts_count ?? 0) > 0)
                        heroAlerts.push({
                            key: 'med-alerts',
                            tone: 'warning',
                            icon: Pill,
                            label: `${emarSummary.pending_alerts_count} medication alert${emarSummary.pending_alerts_count > 1 ? 's' : ''}`,
                            onClick: () => handleTabChange('mar'),
                        });
                    if (pendingVisitCount > 0)
                        heroAlerts.push({
                            key: 'visits',
                            tone: 'warning',
                            icon: Users,
                            label: `${pendingVisitCount} visit request${pendingVisitCount > 1 ? 's' : ''} pending`,
                            onClick: () =>
                                router.visit(
                                    `/operations/clients/${client.id}/visit-requests`,
                                ),
                        });

                    // ── More menu ──
                    const moreItems: MoreMenuItem[] = [];
                    if (client.phone)
                        moreItems.push({
                            key: 'call',
                            label: 'Call',
                            icon: Phone,
                            detail: client.phone,
                            onSelect: () => {
                                window.location.href = `tel:${client.phone}`;
                            },
                        });
                    if (can.navigate_family_portal)
                        moreItems.push({
                            key: 'visits',
                            label: 'Visit requests',
                            icon: Users,
                            detail: pendingVisitCount
                                ? `${pendingVisitCount} pending`
                                : undefined,
                            onSelect: () =>
                                router.visit(
                                    `/operations/clients/${client.id}/visit-requests`,
                                ),
                        });
                    if (can.navigate_medical) {
                        moreItems.push({
                            key: 'mar',
                            label: 'Full MAR chart',
                            icon: Pill,
                            onSelect: () =>
                                router.visit(
                                    `/operations/clients/${client.id}/mar`,
                                ),
                        });
                        moreItems.push({
                            key: 'medical',
                            label: 'Medical record',
                            icon: Heart,
                            onSelect: () =>
                                router.visit(
                                    `/operations/clients/${client.id}/medical`,
                                ),
                        });
                    }
                    if (can.assign_workers)
                        moreItems.push({
                            key: 'workers',
                            label: 'Manage workers',
                            icon: Users,
                            onSelect: () => handleTabChange('assignments'),
                        });
                    const clientSite = client.site;
                    if (clientSite && can.navigate_site)
                        moreItems.push({
                            key: 'site',
                            label: `Open ${clientSite.name}`,
                            icon: Home,
                            onSelect: () =>
                                router.visit(`/sites/${clientSite.id}`),
                        });
                    moreItems.push({
                        key: 'print',
                        label: 'Print profile',
                        icon: FileText,
                        onSelect: () => window.print(),
                    });

                    return (
                        <>
                            <ClientProfileHero
                                clientId={client.id}
                                name={name}
                                photoUrl={
                                    client.avatar ??
                                    client.profile_photo_url ??
                                    null
                                }
                                initials={getInitials(name)}
                                statusLabel={client.status}
                                statusTone={
                                    client.status === 'active'
                                        ? 'success'
                                        : client.status === 'onboarding'
                                          ? 'warning'
                                          : 'neutral'
                                }
                                identityLine={identityLine}
                                chips={heroChips}
                                badges={heroBadges}
                                vitals={heroVitals}
                                nextShift={heroNextShift}
                                safety={heroSafety}
                                stats={[
                                    {
                                        key: 'plan',
                                        icon: Target,
                                        label: 'Care plan',
                                        value: carePlanSummary.active_plan
                                            ? 'Active'
                                            : '—',
                                    },
                                    {
                                        key: 'goals',
                                        icon: CheckCircle2,
                                        label: 'Goals',
                                        value:
                                            carePlanGoals.length > 0
                                                ? `${carePlanDone}/${carePlanGoals.length}`
                                                : '—',
                                    },
                                    {
                                        key: 'shift',
                                        icon: Clock,
                                        label: 'Next shift',
                                        value: shifts_summary?.next
                                            ? 'Yes'
                                            : '—',
                                    },
                                ]}
                                noteCapabilities={{
                                    dailyNote: Boolean(can.create_daily_note),
                                    quickNote: Boolean(can.create_quick_note),
                                    communicationNote: Boolean(
                                        can.create_communication_note,
                                    ),
                                }}
                                onAddNote={(key) => openProfileDialog(key)}
                                onChat={
                                    can.view_family_chat
                                        ? () => openProfileDialog('family_chat')
                                        : undefined
                                }
                                onEdit={
                                    can.update_client
                                        ? () =>
                                              openProfileDialog('edit_profile')
                                        : undefined
                                }
                                onOpenShift={
                                    can.navigate_calendar
                                        ? () => handleTabChange('calendar')
                                        : undefined
                                }
                                onOpenSafety={
                                    can.navigate_risks
                                        ? () =>
                                              handleTabChange('risk_management')
                                        : undefined
                                }
                                moreItems={moreItems}
                                backLabel={
                                    labels?.['client.plural'] ?? 'Clients'
                                }
                                footer={
                                    <GroupPillRail
                                        groups={visibleGroups}
                                        openGroup={openGroup}
                                        activeTab={tab}
                                        onOpenGroup={(_key, targetTab) =>
                                            handleTabChange(targetTab as TabKey)
                                        }
                                        onSearch={() => setPaletteOpen(true)}
                                    />
                                }
                            />
                            <AlertRibbon alerts={heroAlerts} />

                            {/* Hidden photo upload form */}
                            {can.update_client && (
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
                        </>
                    );
                })()}

                <QuickNoteDialog
                    clientId={client.id}
                    open={quickNoteOpen}
                    onOpenChange={(open) => {
                        if (!open && profileDialog?.key === 'quick_note') {
                            closeProfileDialog();
                        }
                    }}
                    onSubmitted={() => openDailyNotes('all')}
                />
                <DailyNoteWizard
                    clientId={client.id}
                    open={dailyNoteOpen}
                    onOpenChange={(open) => {
                        if (!open && profileDialog?.key === 'daily_note') {
                            closeProfileDialog();
                        }
                    }}
                    shiftOptions={dailyNoteShiftOptions}
                    goalOptions={dailyNoteGoalOptions}
                    onSubmitted={() => openDailyNotes('all')}
                    note={
                        (authorizedProfileDialog?.ctx?.note as
                            | ClientDailyNote
                            | undefined) ?? null
                    }
                />
                <DailyNoteWizard
                    clientId={client.id}
                    open={communicationNoteOpen}
                    onOpenChange={(open) => {
                        if (!open && profileDialog?.key === 'comm_note') {
                            closeProfileDialog();
                        }
                    }}
                    mode="communication"
                    shiftOptions={dailyNoteShiftOptions}
                    goalOptions={dailyNoteGoalOptions}
                    onSubmitted={() => {
                        setTab('communication_notes');
                        updateProfileQuery({ tab: 'communication_notes' });
                    }}
                    note={
                        (authorizedProfileDialog?.ctx?.note as
                            | ClientDailyNote
                            | undefined) ?? null
                    }
                />

                <div className="mt-3">
                    <RecentClientsStrip
                        currentClient={{
                            id: client.id,
                            name,
                            photo: client.profile_photo_url ?? null,
                            house: client.site?.name ?? null,
                        }}
                        currentTab={tab}
                    />
                </div>

                {/* Tier-2 tabs for the open group (group pills live in the hero footer) */}
                <div className="mt-3">
                    <TierTwoTabs
                        tabs={activeGroup?.tabs ?? []}
                        activeTab={tab}
                        onTab={(key) => handleTabChange(key as TabKey)}
                        renderLink={(t, className, inner, tabProps) => (
                            <Link
                                key={t.key}
                                href={t.href!}
                                className={className}
                                {...tabProps}
                            >
                                {inner}
                            </Link>
                        )}
                    />
                </div>

                <TabSearchPalette
                    open={paletteOpen}
                    onClose={() => setPaletteOpen(false)}
                    groups={visibleGroups}
                    onTab={(key) => {
                        const target = visibleGroups
                            .flatMap((g) => g.tabs)
                            .find((t) => t.key === key);
                        if (target?.href) {
                            router.visit(target.href);
                        } else {
                            handleTabChange(key as TabKey);
                        }
                    }}
                />

                <ProfileDialogs
                    dialog={authorizedProfileDialog}
                    onClose={() => {
                        if (profileDialog?.key === 'abc') {
                            setAbcRefreshToken((t) => t + 1);
                            router.reload({
                                only: ['behaviour_patterns'],
                                preserveScroll: true,
                                preserveState: true,
                            });
                        }
                        closeProfileDialog();
                    }}
                    flowContext={flowContext}
                    medications={(medical?.medications ?? []) as any[]}
                />

                {tab === 'profile' &&
                    (() => {
                        const summary = pageProps.care_plans_summary ?? {};
                        const activePlan = summary.active_plan;
                        const risks = pageProps.client_risks ?? [];

                        // Parse about me from care plan content (feeds the
                        // design Overview's About tiles).
                        const planContent = activePlan?.content
                            ? typeof activePlan.content === 'string'
                                ? JSON.parse(activePlan.content || '{}')
                                : activePlan.content
                            : {};
                        const aboutMe = planContent.about_me ?? {};

                        const goals = activePlan?.goals ?? [];

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
                                    <div className="mb-4 flex items-center gap-3 rounded-xl border-2 border-status-critical/30 bg-status-critical-bg p-4">
                                        <ShieldAlert className="h-6 w-6 text-status-critical" />
                                        <div>
                                            <p className="text-sm font-bold text-status-critical">
                                                Safeguarding Alert
                                            </p>
                                            <p className="text-xs text-status-critical">
                                                Active safeguarding concern.
                                                Follow protocols.
                                            </p>
                                        </div>
                                    </div>
                                )}

                                {/* Overview board — design composition (tabs-core OverviewTab).
                                    Legacy depth widgets (house coverage, health summary, …)
                                    continue below so nothing is lost. */}
                                <OverviewDesignGrid
                                    preferredName={preferredName}
                                    aboutTiles={buildAboutTiles(
                                        aboutMe ?? {},
                                        client as any,
                                    )}
                                    notes={clientDailyNotes as any[]}
                                    goals={goals as any[]}
                                    risks={risks as any[]}
                                    activePlan={activePlan ?? null}
                                    reviewDays={reviewDays}
                                    emarSummary={emarSummary}
                                    events={
                                        ((pageProps as any).calendar_events ??
                                            []) as any[]
                                    }
                                    team={
                                        ((client as any).support_workers ??
                                            []) as any[]
                                    }
                                    keyWorkerId={
                                        (client as any).key_worker?.id ?? null
                                    }
                                    keyWorkerName={
                                        (client as any).key_worker?.name ?? null
                                    }
                                    navigationCapabilities={{
                                        dailyNotes: Boolean(
                                            can.navigate_daily_notes,
                                        ),
                                        goals: Boolean(can.navigate_care_plans),
                                        risks: Boolean(can.navigate_risks),
                                        mar: Boolean(can.navigate_medical),
                                        calendar: Boolean(
                                            can.navigate_calendar,
                                        ),
                                    }}
                                    onTab={(key) =>
                                        handleTabChange(key as TabKey)
                                    }
                                    onEditAbout={
                                        can.update_client
                                            ? () =>
                                                  openProfileDialog(
                                                      'edit_profile',
                                                  )
                                            : undefined
                                    }
                                    onRecordDose={
                                        can.record_medication_administration
                                            ? () => openProfileDialog('emar')
                                            : undefined
                                    }
                                    onManageWorkers={
                                        can.assign_workers
                                            ? () =>
                                                  handleTabChange('assignments')
                                            : undefined
                                    }
                                    riskLevelControl={
                                        can.update_risk_level ? (
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
                                                            ? 'bg-status-critical-bg text-status-critical'
                                                            : client.risk_level ===
                                                                'high'
                                                              ? 'bg-status-critical-bg text-status-critical'
                                                              : client.risk_level ===
                                                                  'medium'
                                                                ? 'bg-status-warning-bg text-status-warning'
                                                                : client.risk_level ===
                                                                    'low'
                                                                  ? 'bg-status-success-bg text-status-success'
                                                                  : 'bg-muted text-muted-foreground'
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
                                        ) : undefined
                                    }
                                />
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
                                        <div className="flex items-center gap-2">
                                            {can.manage_onboarding_workflow &&
                                            onboarding.workflow.status !==
                                                'completed' ? (
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        openProfileDialog(
                                                            'add_onboarding_step',
                                                        )
                                                    }
                                                    data-test="onboarding-add-step"
                                                >
                                                    <Plus className="mr-1.5 h-3.5 w-3.5" />
                                                    Add step
                                                </Button>
                                            ) : null}
                                            <Badge
                                                variant={
                                                    onboarding.workflow
                                                        .status === 'completed'
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
                                        const remaining = steps.length - done;
                                        return (
                                            <div className="mt-3 flex flex-wrap items-center gap-5">
                                                {/* Completeness ring (design tabs-daily OnboardingTab) */}
                                                <Ring pct={pct} size={96} />
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-sm font-semibold">
                                                        {pct === 100
                                                            ? 'Onboarding complete'
                                                            : remaining <= 2
                                                              ? 'Almost there'
                                                              : 'In progress'}
                                                    </div>
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        {done}/{steps.length}{' '}
                                                        steps complete
                                                        {remaining > 0
                                                            ? ` · ${remaining} remaining`
                                                            : ''}
                                                    </p>
                                                    <div className="mt-2 h-2 rounded-full bg-muted">
                                                        <div
                                                            className="h-2 rounded-full bg-primary transition-all"
                                                            style={{
                                                                width: `${pct}%`,
                                                            }}
                                                        />
                                                    </div>
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
                                    {can.create_onboarding_workflow && (
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
                                                className={`h-2 w-2 rounded-full ${item.complete ? 'bg-status-success' : 'bg-muted'}`}
                                            />
                                            <div>
                                                <div className="text-sm font-medium">
                                                    {item.label}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {item.complete
                                                        ? item.has_data
                                                            ? 'Added'
                                                            : 'Not applicable'
                                                        : 'Not completed'}
                                                </div>
                                            </div>
                                        </div>
                                        {!item.has_data &&
                                            can.manage_onboarding_checklist && (
                                                <label className="flex cursor-pointer items-center gap-2 text-xs text-muted-foreground">
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
                                                /DBS|Health Screening|Privacy Act 2020|Safeguarding/i.test(
                                                    step.step_name ?? '',
                                                )
                                                    ? {
                                                          label: 'Compliance',
                                                          color: 'bg-primary/10 text-primary',
                                                      }
                                                    : /Referral|Assessment|Care Plan|Agreement|Staff|Introduction/i.test(
                                                            step.step_name ??
                                                                '',
                                                        )
                                                      ? {
                                                            label: 'Service',
                                                            color: 'bg-status-info-bg text-status-info',
                                                        }
                                                      : {
                                                            label: 'Admin',
                                                            color: 'bg-muted text-muted-foreground',
                                                        };
                                            return (
                                                <div
                                                    key={step.id}
                                                    className={`flex items-center justify-between rounded-md border p-3 ${step.status === 'completed' ? 'border-status-success/30 bg-status-success-bg dark:border-status-success/30' : step.due_date && new Date(step.due_date) < new Date() && step.status === 'pending' ? 'border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30' : ''}`}
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
                                                                <div className="mt-0.5 text-xs text-muted-foreground">
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
                                                                                    ? 'font-medium text-status-critical'
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
                                                            can.manage_onboarding_workflow && (
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
                                    can.manage_onboarding_workflow &&
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

                        <Card className="mt-4">
                            <CardHeader>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="flex items-start gap-3">
                                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-status-info-bg text-status-info">
                                            <GraduationCap className="h-5 w-5" />
                                        </div>
                                        <div>
                                            <CardTitle className="text-base">
                                                Staff preparation
                                            </CardTitle>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Canonical HR onboarding and
                                                induction readiness for support
                                                workers assigned to this client.
                                            </p>
                                        </div>
                                    </div>
                                    {can.view_hr_onboarding ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link href="/hr/onboarding">
                                                Open HR onboarding
                                            </Link>
                                        </Button>
                                    ) : null}
                                </div>
                            </CardHeader>
                            <CardContent>
                                {!can.view_hr_onboarding ? (
                                    <p className="text-sm text-muted-foreground">
                                        HR onboarding readiness is available to
                                        authorised HR viewers. Client onboarding
                                        remains available above.
                                    </p>
                                ) : !staffPreparation ||
                                  staffPreparation.summary.assigned === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No support workers are assigned, so
                                        there is no staff-preparation cohort
                                        yet.
                                    </p>
                                ) : (
                                    <div className="space-y-3">
                                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                            {[
                                                [
                                                    'Assigned',
                                                    staffPreparation.summary
                                                        .assigned,
                                                ],
                                                [
                                                    'Prepared',
                                                    staffPreparation.summary
                                                        .prepared,
                                                ],
                                                [
                                                    'In progress',
                                                    staffPreparation.summary
                                                        .in_progress,
                                                ],
                                                [
                                                    'Attention',
                                                    staffPreparation.summary
                                                        .needs_attention,
                                                ],
                                            ].map(([label, value]) => (
                                                <div
                                                    key={String(label)}
                                                    className="rounded-lg border p-3"
                                                >
                                                    <div className="text-lg font-semibold">
                                                        {value}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {label}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                        <div className="divide-y rounded-lg border">
                                            {staffPreparation.workers.map(
                                                (worker) => {
                                                    const statusLabel =
                                                        worker.status ===
                                                        'completed'
                                                            ? 'Prepared'
                                                            : worker.status ===
                                                                'in_progress'
                                                              ? 'In progress'
                                                              : worker.status ===
                                                                  'pending'
                                                                ? 'Pending'
                                                                : worker.status ===
                                                                    'not_linked'
                                                                  ? 'No HR profile'
                                                                  : worker.status ===
                                                                      'not_started'
                                                                    ? 'No checklist'
                                                                    : worker.status.replace(
                                                                          '_',
                                                                          ' ',
                                                                      );
                                                    return (
                                                        <div
                                                            key={worker.user_id}
                                                            className="flex flex-wrap items-center justify-between gap-3 p-3"
                                                        >
                                                            <div className="min-w-0">
                                                                <p className="truncate text-sm font-medium">
                                                                    {
                                                                        worker.name
                                                                    }
                                                                </p>
                                                                <p className="text-xs text-muted-foreground">
                                                                    {worker.role ??
                                                                        'Assigned support worker'}
                                                                    {worker.tasks_total >
                                                                    0
                                                                        ? ` · ${worker.tasks_completed}/${worker.tasks_total} tasks`
                                                                        : ''}
                                                                    {worker.is_overdue
                                                                        ? ' · Overdue'
                                                                        : ''}
                                                                </p>
                                                            </div>
                                                            <div className="flex items-center gap-2">
                                                                <Badge
                                                                    variant={
                                                                        worker.status ===
                                                                        'completed'
                                                                            ? 'secondary'
                                                                            : worker.is_overdue
                                                                              ? 'destructive'
                                                                              : 'outline'
                                                                    }
                                                                    className="capitalize"
                                                                >
                                                                    {
                                                                        statusLabel
                                                                    }
                                                                </Badge>
                                                                {worker.checklist_id ? (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="ghost"
                                                                        asChild
                                                                    >
                                                                        <Link
                                                                            href={`/hr/onboarding/${worker.checklist_id}`}
                                                                        >
                                                                            View
                                                                        </Link>
                                                                    </Button>
                                                                ) : null}
                                                            </div>
                                                        </div>
                                                    );
                                                },
                                            )}
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                )}

                {tab === 'medical' && (
                    <div className="space-y-4">
                        {/* Allergy Alert */}
                        {medical.profile?.allergies &&
                            medical.profile.allergies !== '-' && (
                                <div className="flex items-center gap-3 rounded-xl border-2 border-status-critical/30 bg-status-critical-bg p-4">
                                    <ShieldAlert className="h-6 w-6 shrink-0 text-status-critical" />
                                    <div>
                                        <p className="text-sm font-bold text-status-critical">
                                            Allergies
                                        </p>
                                        <p className="text-sm text-status-critical">
                                            {medical.profile.allergies}
                                        </p>
                                    </div>
                                </div>
                            )}

                        {/* Quick Stats */}
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div className="rounded-xl border bg-primary/10 p-3 text-center">
                                <div className="text-xl font-bold text-primary">
                                    {medical.medications?.length ?? 0}
                                </div>
                                <div className="text-[10px] tracking-wider text-primary uppercase">
                                    Medications
                                </div>
                            </div>
                            <div className="rounded-xl border bg-status-warning-bg p-3 text-center">
                                <div className="text-xl font-bold text-status-warning">
                                    {medical.conditions?.length ?? 0}
                                </div>
                                <div className="text-[10px] tracking-wider text-status-warning uppercase">
                                    Conditions
                                </div>
                            </div>
                            <div className="rounded-xl border bg-status-info-bg p-3 text-center">
                                <div className="text-xl font-bold text-status-info">
                                    {medical.emergency_contacts?.length ?? 0}
                                </div>
                                <div className="text-[10px] tracking-wider text-status-info uppercase">
                                    Emergency Contacts
                                </div>
                            </div>
                            <div className="rounded-xl border bg-status-info-bg p-3 text-center">
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
                                    <Card className="border-status-success/30 bg-status-success-bg">
                                        <CardContent className="p-4">
                                            <div className="mb-2 flex items-center gap-2">
                                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-status-success-bg text-status-success">
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
                                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-status-critical-bg text-status-critical">
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
                                                        className="rounded-lg bg-muted p-3"
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
                                                <div className="mt-3 rounded-lg bg-muted p-3">
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
                                                <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
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
                                                        // eslint-disable-next-line no-restricted-syntax -- Medication rows use status strip styling inside the clinical Card.
                                                        <div
                                                            key={m.id}
                                                            className="flex items-start gap-3 rounded-xl border-l-4 border-l-violet-400 bg-card p-3 shadow-sm"
                                                        >
                                                            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                                                                <Pill className="h-4 w-4 text-primary" />
                                                            </div>
                                                            <div className="flex-1">
                                                                <div className="flex items-center gap-2">
                                                                    <span className="text-sm font-semibold">
                                                                        {m.name}
                                                                    </span>
                                                                    {m.is_controlled && (
                                                                        <Badge className="border-0 bg-status-critical-bg text-[9px] text-status-critical">
                                                                            Controlled
                                                                        </Badge>
                                                                    )}
                                                                    {m.is_prn && (
                                                                        <Badge className="border-0 bg-status-warning-bg text-[9px] text-status-warning">
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
                                                                    <p className="mt-1 text-xs text-muted-foreground">
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
                                                <div className="flex h-6 w-6 items-center justify-center rounded-md bg-status-warning-bg text-status-warning">
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
                                                                                ? 'bg-status-critical-bg text-status-critical'
                                                                                : c.severity ===
                                                                                    'moderate'
                                                                                  ? 'bg-status-warning-bg text-status-warning'
                                                                                  : 'bg-status-success-bg text-status-success'
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
                                                <div className="flex h-6 w-6 items-center justify-center rounded-md bg-status-info-bg text-status-info">
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
                                                            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-status-info-bg text-xs font-bold text-status-info">
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

                {tab === 'mar' && (
                    <MarTab
                        clientId={client.id}
                        clientFirstName={client.first_name}
                        siteName={client.site?.name ?? null}
                        medications={(medical?.medications ?? []) as any[]}
                        allergies={
                            Array.isArray(medical?.profile?.allergies)
                                ? (medical.profile.allergies as string[])
                                : []
                        }
                        emarSummary={emarSummary}
                        onRecordDose={(medicationId) =>
                            openProfileDialog(
                                'emar',
                                medicationId ? { medicationId } : undefined,
                            )
                        }
                    />
                )}

                {tab === 'meal_prefs' && (
                    <FoodMealTab
                        clientId={client.id}
                        canEdit={!!can?.edit}
                        mealLogs={(pageProps as any).meal_logs}
                        onAddPreference={() => openProfileDialog('meal_pref')}
                    />
                )}

                {tab === 'observations' && (
                    <BehaviourAbcTab
                        clientId={client.id}
                        patterns={(pageProps as any).behaviour_patterns as any}
                        canRecord={Boolean(can.record_event)}
                        onNewEntry={() => openProfileDialog('abc')}
                        onOpenEntry={(entry: AbcEntryRow) =>
                            openProfileDialog('abc', { entry })
                        }
                        refreshToken={abcRefreshToken}
                    />
                )}

                {tab === 'care_plans' && (
                    <CareSupportPlanTab
                        client={client as any}
                        summary={carePlansSummary}
                        agreements={clientAgreements}
                        canEdit={Boolean(can.care_plans_update)}
                        canCreate={Boolean(can.care_plans_create)}
                        onCreatePlan={() =>
                            openProfileDialog('care_plan', {
                                serviceAgreementOptions,
                            })
                        }
                        onEditPlan={(plan) =>
                            openProfileDialog('care_plan', {
                                plan,
                                serviceAgreementOptions,
                            })
                        }
                        onGoToGoals={() => handleTabChange('goals_path')}
                    />
                )}

                {tab === 'calendar' && (
                    <div className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex items-center gap-3">
                                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-primary">
                                    <Calendar className="h-[19px] w-[19px]" />
                                </span>
                                <div>
                                    <h2 className="text-lg leading-tight font-semibold">
                                        Appointments
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        Appointments, shifts & reminders
                                    </p>
                                </div>
                            </div>
                            {canCreateAppointment ? (
                                <Button
                                    onClick={() =>
                                        openProfileDialog('appointment')
                                    }
                                    data-test="calendar-new-appointment"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New appointment
                                </Button>
                            ) : null}
                        </div>
                        <ClientCalendarTab
                            clientId={client.id}
                            clientFirstName={client.first_name}
                            initialEvents={
                                (pageProps as any).calendar_events ?? []
                            }
                            canCreate={canCreateAppointment}
                        />
                    </div>
                )}

                {tab === 'progress_notes' && (
                    <DailyNotesTab
                        clientId={client.id}
                        notes={clientDailyNotes}
                        summary={dailyNotesSummary}
                        canReview={Boolean(progressNotesCan.review)}
                        canUpdate={Boolean(progressNotesCan.update)}
                        currentUserId={auth?.user?.id}
                        onCreateDaily={
                            can.create_daily_note
                                ? () => openProfileDialog('daily_note')
                                : undefined
                        }
                        onCreateQuick={
                            can.create_quick_note
                                ? () => openProfileDialog('quick_note')
                                : undefined
                        }
                        onEditNote={(note) =>
                            openProfileDialog('daily_note', { note })
                        }
                        filterPreset={dailyNotesFilter}
                        onFilterChange={setDailyNotesFilter}
                        onShowReviewQueue={() => openDailyNotes('flagged')}
                        isLoading={!hasClientDailyNotesProp}
                    />
                )}

                {tab === 'communication_notes' && (
                    <CommunicationNotesTab
                        notes={communicationNotes}
                        familyNotes={familyNotes}
                        familyNotesOpenCount={familyNotesOpenCount}
                        coverage={{
                            total: dailyNotesSummary.communication,
                            loaded: dailyNotesSummary.communication_loaded,
                            has_more: dailyNotesSummary.communication_has_more,
                        }}
                        onCreate={
                            can.create_communication_note
                                ? () => openProfileDialog('comm_note')
                                : undefined
                        }
                        canReview={Boolean(progressNotesCan.review)}
                        canUpdate={Boolean(progressNotesCan.update)}
                        onMarkReviewed={(noteId) =>
                            router.post(
                                `/operations/clients/${client.id}/daily-notes/${noteId}/review`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                        onClearFlag={(noteId) =>
                            router.post(
                                `/operations/clients/${client.id}/daily-notes/${noteId}/flag`,
                                { is_flagged: false },
                                { preserveScroll: true },
                            )
                        }
                        onEditNote={(note) =>
                            openProfileDialog('comm_note', { note })
                        }
                        isLoading={!hasCommunicationNotesProp}
                    />
                )}

                {tab === 'health_monitoring' && (
                    <div className="space-y-4">
                        <div className="flex flex-wrap items-center justify-end gap-2">
                            <Button
                                variant="outline"
                                onClick={() => openProfileDialog('record_obs')}
                                data-test="health-record-observation"
                            >
                                <Plus className="mr-1.5 h-4 w-4" />
                                Record observation
                            </Button>
                        </div>
                        <ClientClinicalRecordLaunchers client={client} />
                        <HealthMonitoringTab
                            clientId={client.id}
                            data={healthMonitoring}
                            isLoading={!hasHealthMonitoringProp}
                        />
                    </div>
                )}

                {tab === 'rhythms_routines' && (
                    <div className="space-y-4">
                        {can.edit ? (
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                <Button
                                    onClick={() =>
                                        openProfileDialog('edit_rhythms')
                                    }
                                    data-test="rhythms-update-guidance"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Update guidance
                                </Button>
                            </div>
                        ) : null}
                        <RhythmsRoutinesTab
                            clientId={client.id}
                            routines={clientRoutines}
                            canEdit={can.edit}
                            isLoading={!hasClientRoutinesProp}
                        />
                    </div>
                )}

                {tab === 'actions_reviews' && (
                    <div className="space-y-4">
                        {can.create_note ? (
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                <Button
                                    onClick={() =>
                                        openProfileDialog('add_action')
                                    }
                                    data-test="actions-add"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add action
                                </Button>
                            </div>
                        ) : null}
                        <ActionsReviewsTab
                            items={actionsReviews}
                            summary={actionsReviewsSummary}
                            isLoading={!hasActionsReviewsProp}
                        />
                    </div>
                )}

                {tab === 'personal_details' && (
                    <PersonalDetailsTab
                        client={client as any}
                        supportWorkers={(client as any).support_workers ?? []}
                        emergencyContacts={
                            ((pageProps as any).medical?.emergency_contacts ??
                                []) as any
                        }
                        nextOfKins={
                            ((pageProps as any).next_of_kins ?? []) as any
                        }
                        onEdit={
                            can.edit
                                ? () => openProfileDialog('edit_profile')
                                : undefined
                        }
                    />
                )}

                {tab === 'goals_path' && (
                    <GoalsPathTab
                        clientId={client.id}
                        clientName={name}
                        activePlanId={workingCarePlan?.id ?? null}
                        goals={workingCarePlan?.goals ?? []}
                        lifeStory={(client as any).life_story}
                        strengthsAbilities={(client as any).strengths_abilities}
                        interestsHobbies={(client as any).interests_hobbies}
                        pathPlan={(pageProps as any).path_plan ?? null}
                        canManageGoals={Boolean(can.manage_care_plan_goals)}
                        canEditPath={Boolean(can.edit_path_plan)}
                        onAddGoal={() => openProfileDialog('goal')}
                        onManageGoal={(goal) =>
                            openProfileDialog('goal', { goal })
                        }
                        onEditPlan={() => {
                            const pp = ((pageProps as any).path_plan ??
                                {}) as Record<string, unknown>;
                            const toLines = (a: unknown) =>
                                Array.isArray(a) ? a.join('\n') : '';
                            const day = (v: unknown) =>
                                typeof v === 'string' ? v.slice(0, 10) : '';
                            openProfileDialog('edit_path_plan', {
                                values: {
                                    dream: pp.dream ?? '',
                                    north_star: pp.north_star ?? '',
                                    strengths: toLines(pp.strengths),
                                    trusted_people: toLines(pp.trusted_people),
                                    independence_goals: toLines(
                                        pp.independence_goals,
                                    ),
                                    community: pp.community ?? '',
                                    action_steps: toLines(pp.action_steps),
                                    meaningful_outcomes:
                                        pp.meaningful_outcomes ?? '',
                                    life_story:
                                        (client as any).life_story ?? '',
                                    strengths_abilities:
                                        (client as any).strengths_abilities ??
                                        '',
                                    interests_hobbies:
                                        (client as any).interests_hobbies ?? '',
                                    plan_date: day(pp.plan_date),
                                    next_review_at: day(pp.next_review_at),
                                },
                            });
                        }}
                    />
                )}

                {tab === 'risk_management' && (
                    <>
                        <RiskManagementTab
                            clientId={client.id}
                            risks={(pageProps.client_risks ?? []) as any}
                            canCreate={Boolean((can as any).create_risks)}
                            canUpdate={Boolean((can as any).update_risks)}
                            canDelete={Boolean((can as any).delete_risks)}
                            onAddRisk={() => openProfileDialog('add_risk')}
                            onEditRisk={(risk) =>
                                openProfileDialog('edit_risk', { risk })
                            }
                            homeHazards={(pageProps.homeHazards ?? []) as any}
                            homeHazardDetail={
                                (pageProps.homeHazardDetail ?? null) as any
                            }
                            homeName={(pageProps.homeName ?? null) as any}
                            homeSiteId={(pageProps.homeSiteId ?? null) as any}
                            homeProcedures={
                                (pageProps.homeProcedures ?? []) as any
                            }
                        />

                        {Boolean((can as any).view_hs_risk_assessments) && (
                            <div className="mt-8 border-t border-border pt-6">
                                <div className="mb-4 flex items-center gap-2">
                                    <ShieldAlert className="h-5 w-5 text-muted-foreground" />
                                    <div>
                                        <h3 className="text-base font-semibold">
                                            Formal H&amp;S risk assessments
                                        </h3>
                                        <p className="text-xs text-muted-foreground">
                                            ISO 31000 / SafePlus 5×5 assessments
                                            attached to this client — separate
                                            from the care-risk list above.
                                        </p>
                                    </div>
                                </div>
                                <RaRegisterSection
                                    assessments={
                                        (pageProps.hs_risk_assessments ??
                                            []) as RaRow[]
                                    }
                                    pickers={
                                        (pageProps.ra_pickers ?? {
                                            sites: [],
                                            clients: [],
                                            events: [],
                                        }) as RaPickers
                                    }
                                    canManage={Boolean(
                                        (can as any).manage_hs_risk_assessments,
                                    )}
                                    lockedAssessable={{
                                        type: 'client',
                                        id: client.id,
                                        name: `${client.first_name} ${client.last_name}`.trim(),
                                    }}
                                />
                            </div>
                        )}
                    </>
                )}

                {tab === 'incidents_accidents' && (
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-primary">
                                <AlertTriangle className="h-[19px] w-[19px]" />
                            </span>
                            <div>
                                <h2 className="text-lg leading-tight font-semibold">
                                    Incidents & accidents
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {(pageProps.client_incidents ?? []).length}{' '}
                                    recent incident
                                    {(pageProps.client_incidents ?? [])
                                        .length === 1
                                        ? ''
                                        : 's'}
                                </p>
                            </div>
                        </div>
                        <Button
                            onClick={() => openProfileDialog('log_incident')}
                            data-test="incidents-log-incident"
                        >
                            <Plus className="mr-1.5 h-4 w-4" />
                            Log incident
                        </Button>
                    </div>
                )}
                {tab === 'incidents_accidents' && (
                    <IncidentsTab
                        incidents={(pageProps.client_incidents ?? []) as any[]}
                    />
                )}

                {tab === 'first_aid' && (
                    <FirstAidTab
                        records={(pageProps.first_aid_records ?? []) as any[]}
                    />
                )}

                {tab === 'family_tree' && (
                    <div className="space-y-4">
                        {can.edit ? (
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        openProfileDialog('portal_invite')
                                    }
                                >
                                    <Globe className="mr-1.5 h-4 w-4" />
                                    Invite to portal
                                </Button>
                                <Button
                                    onClick={() =>
                                        openProfileDialog('add_relationship')
                                    }
                                    data-test="family-add-relationship"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add relationship
                                </Button>
                            </div>
                        ) : null}
                        <FamilyTreeTab
                            clientName={name}
                            nextOfKins={
                                ((pageProps as any).next_of_kins ?? []) as any
                            }
                            portalUsers={(portal_users ?? []) as any}
                            emergencyContacts={
                                (medical?.emergency_contacts ?? []) as any
                            }
                        />
                    </div>
                )}

                {tab === 'audit_history' && (
                    <AuditHistoryTab
                        entries={
                            ((pageProps as any).audit_history ?? []) as any
                        }
                        canView={Boolean(
                            ((pageProps as any).audit_history ?? []).length >
                                0 || progressNotesCan.update,
                        )}
                    />
                )}

                {tab === 'finance' && (
                    <div className="space-y-4">
                        {flowContext.fundOptions.length > 0 ? (
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                <Button
                                    onClick={() =>
                                        openProfileDialog('transaction')
                                    }
                                    data-test="finance-new-transaction"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New transaction
                                </Button>
                            </div>
                        ) : null}
                        <FinanceTab
                            clientId={client.id}
                            finance={(pageProps as any).client_finance ?? {}}
                        />
                    </div>
                )}

                {tab === 'leave_excursions' && (
                    <LeaveExcursionsTab
                        clientId={client.id}
                        leave={
                            ((pageProps as any).leave_excursions?.leave ??
                                []) as any
                        }
                        excursions={
                            ((pageProps as any).leave_excursions?.excursions ??
                                []) as any
                        }
                        canManage={Boolean(can.edit)}
                        onRequestLeave={() =>
                            openProfileDialog('request_leave')
                        }
                        onPlanExcursion={() =>
                            openProfileDialog('plan_excursion')
                        }
                    />
                )}

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
                                    <div className="rounded-xl border bg-primary/10 p-3 text-center">
                                        <div className="text-xl font-bold text-primary">
                                            {agreements.length}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-primary uppercase">
                                            Total
                                        </div>
                                    </div>
                                    <div className="rounded-xl border bg-status-success-bg p-3 text-center">
                                        <div className="text-xl font-bold text-status-success">
                                            {activeAgs.length}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-status-success uppercase">
                                            Active
                                        </div>
                                    </div>
                                    <div className="rounded-xl border bg-primary/10 p-3 text-center">
                                        <div
                                            className={`text-xl font-bold ${overallPct > 90 ? 'text-status-critical' : overallPct > 70 ? 'text-status-warning' : 'text-primary'}`}
                                        >
                                            {overallPct}%
                                        </div>
                                        <div className="text-[10px] tracking-wider text-primary uppercase">
                                            Budget Used
                                        </div>
                                    </div>
                                    <div className="rounded-xl border p-3 text-center">
                                        <div
                                            className={`text-xl font-bold ${expiringSoon > 0 ? 'text-status-warning' : 'text-muted-foreground'}`}
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
                                    <Card className="border-primary bg-primary/10">
                                        <CardContent className="p-4">
                                            <div className="mb-2 flex items-center justify-between">
                                                <span className="text-sm font-semibold">
                                                    Total Funding Overview
                                                </span>
                                                <span className="text-sm font-bold text-primary">
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
                                            <div className="h-4 w-full overflow-hidden rounded-full bg-primary/20">
                                                <div
                                                    className={`h-full rounded-full transition-all ${overallPct > 90 ? 'bg-status-critical' : overallPct > 70 ? 'bg-status-warning' : 'bg-primary'}`}
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
                                    {serviceAgreementsCan.create && (
                                        <Button
                                            size="sm"
                                            className="gap-1.5 bg-primary hover:bg-primary"
                                            asChild
                                        >
                                            <Link
                                                href={`/operations/service-agreements/create?client_id=${client.id}`}
                                            >
                                                New Agreement
                                            </Link>
                                        </Button>
                                    )}
                                </div>

                                {/* Agreement Cards */}
                                {agreements.length === 0 ? (
                                    <Card className="border-dashed">
                                        <CardContent className="flex flex-col items-center justify-center py-12">
                                            <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/10">
                                                <DollarSign className="h-7 w-7 text-primary" />
                                            </div>
                                            <p className="font-medium">
                                                No Service Agreements
                                            </p>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {serviceAgreementsCan.create
                                                    ? `Create a funding agreement for ${client.first_name}.`
                                                    : 'No agreements are available to view.'}
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
                                                    ? 'bg-status-critical'
                                                    : budgetPct > 70
                                                      ? 'bg-status-warning'
                                                      : 'bg-status-success';
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
                                                                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${ag.status === 'active' ? 'bg-status-success-bg text-status-success' : 'bg-muted text-muted-foreground'}`}
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
                                                                            className={`border-0 text-[9px] capitalize ${ag.status === 'active' ? 'bg-status-success-bg text-status-success' : ag.status === 'draft' ? 'bg-muted text-muted-foreground' : 'bg-status-warning-bg text-status-warning'}`}
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
                                                                            <Badge className="animate-pulse border-0 bg-status-warning-bg text-[9px] text-status-warning">
                                                                                Expiring
                                                                                Soon
                                                                            </Badge>
                                                                        )}
                                                                        {isExpired && (
                                                                            <Badge className="border-0 bg-status-critical-bg text-[9px] text-status-critical">
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
                                                                <div className="h-2.5 w-full overflow-hidden rounded-full bg-muted">
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
                    <div className="space-y-4">
                        {can.edit ? (
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                <Button
                                    onClick={() =>
                                        openProfileDialog('add_assessment')
                                    }
                                    data-test="assessments-add"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add assessment
                                </Button>
                            </div>
                        ) : null}
                        <AssessmentsTab
                            clientId={client.id}
                            assessments={assessments}
                            canEdit={can.edit}
                        />
                    </div>
                )}

                {tab === 'timeline' && (
                    <div className="space-y-4">
                        {can.create_note ? (
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                <Button
                                    onClick={() =>
                                        openProfileDialog('add_note', {
                                            title: 'Add timeline note',
                                        })
                                    }
                                    data-test="timeline-add-note"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add note
                                </Button>
                            </div>
                        ) : null}
                        <ClientTimelineTab
                            clientId={client.id}
                            events={events}
                            handover={handover}
                            summary={timelineSummary}
                            canCreateNote={Boolean(can.create_note)}
                            canPinHandover={Boolean(can.pin_handover)}
                            auth={auth}
                        />
                    </div>
                )}

                {tab === 'documents' && (
                    <div className="space-y-4">
                        {can.edit ? (
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                <Button
                                    onClick={() =>
                                        openProfileDialog('upload_doc', {
                                            title: 'Upload document',
                                        })
                                    }
                                    data-test="documents-upload"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Upload document
                                </Button>
                            </div>
                        ) : null}
                        <DocumentsTab
                            clientId={client.id}
                            clientName={client.first_name ?? name}
                            documents={(documents ?? []) as any}
                        />
                    </div>
                )}

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
                                color: 'bg-status-info-bg text-status-info',
                            },
                            todo: {
                                emoji: '✅',
                                label: 'To-Do',
                                color: 'bg-status-success-bg text-status-success',
                            },
                            request: {
                                emoji: '🙏',
                                label: 'Request',
                                color: 'bg-status-warning-bg text-status-warning',
                            },
                            reminder: {
                                emoji: '⏰',
                                label: 'Reminder',
                                color: 'bg-primary/10 text-primary',
                            },
                        };
                        const PRIORITY_COLORS: Record<string, string> = {
                            low: 'bg-muted text-muted-foreground',
                            normal: 'bg-status-info-bg text-status-info',
                            high: 'bg-status-warning-bg text-status-warning',
                            urgent: 'bg-status-critical-bg text-status-critical',
                        };
                        const STATUS_COLORS: Record<string, string> = {
                            open: 'bg-status-info-bg text-status-info',
                            in_progress:
                                'bg-status-warning-bg text-status-warning',
                            completed:
                                'bg-status-success-bg text-status-success',
                            cancelled: 'bg-muted text-muted-foreground',
                        };

                        return (
                            <div className="space-y-4">
                                {/* Stats */}
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    <div className="rounded-xl border bg-status-info-bg p-3 text-center">
                                        <div className="text-xl font-bold text-status-info">
                                            {openNotes.length}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-status-info uppercase">
                                            Open
                                        </div>
                                    </div>
                                    <div
                                        className={`rounded-xl border p-3 text-center ${urgentCount > 0 ? 'bg-status-critical-bg' : ''}`}
                                    >
                                        <div
                                            className={`text-xl font-bold ${urgentCount > 0 ? 'text-status-critical' : 'text-muted-foreground'}`}
                                        >
                                            {urgentCount}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                            Urgent
                                        </div>
                                    </div>
                                    <div
                                        className={`rounded-xl border p-3 text-center ${overdueCount > 0 ? 'bg-status-warning-bg' : ''}`}
                                    >
                                        <div
                                            className={`text-xl font-bold ${overdueCount > 0 ? 'text-status-warning' : 'text-muted-foreground'}`}
                                        >
                                            {overdueCount}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                            Overdue
                                        </div>
                                    </div>
                                    <div className="rounded-xl border bg-status-success-bg p-3 text-center">
                                        <div className="text-xl font-bold text-status-success">
                                            {completedThisWeek}
                                        </div>
                                        <div className="text-[10px] tracking-wider text-status-success uppercase">
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
                                                    className={`overflow-hidden transition-all hover:shadow-sm ${note.is_overdue ? 'border-status-critical/30 bg-status-critical-bg' : note.status === 'completed' ? 'opacity-60' : ''}`}
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
                                                                        <Badge className="gap-0.5 border-0 bg-status-critical-bg text-[9px] text-status-critical">
                                                                            <AlertTriangle className="h-2.5 w-2.5" />
                                                                            Overdue
                                                                        </Badge>
                                                                    )}
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="border-status-warning/30 bg-status-warning-bg text-[9px] text-status-warning"
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
                                                                    <div className="mt-1 rounded-md border border-primary bg-primary/10 px-2 py-1 text-xs text-primary">
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
                                                                        <p className="text-primary">
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
                                                                        <p className="mt-1 text-xs text-primary">
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
                                                                    <div className="mt-2 rounded-lg border-l-2 border-l-blue-400 bg-status-info-bg p-2">
                                                                        <p className="text-xs">
                                                                            <span className="font-medium">
                                                                                {
                                                                                    note.staff_responded_by_name
                                                                                }
                                                                            </span>{' '}
                                                                            <Badge
                                                                                variant="outline"
                                                                                className="ml-1 border-status-info/30 bg-status-info-bg text-[9px] text-status-info"
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
                                                                        <p className="mt-1 text-xs text-status-success">
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
                                                            {can.manage_family_notes &&
                                                                [
                                                                    'open',
                                                                    'in_progress',
                                                                ].includes(
                                                                    note.status,
                                                                ) && (
                                                                    <div className="flex shrink-0 flex-col gap-1">
                                                                        <Button
                                                                            size="sm"
                                                                            variant="outline"
                                                                            className="h-7 gap-1 text-[10px] text-status-success"
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
                                                                                className="h-7 gap-1 text-[10px] text-status-warning"
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
                                                                                className="h-7 gap-1 text-[10px] text-primary"
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
                                                        {can.manage_family_notes &&
                                                            respondingId ===
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
                                                        {can.manage_family_notes &&
                                                            assigningId ===
                                                                note.id && (
                                                                <div className="mt-3 text-xs text-muted-foreground">
                                                                    <p className="mb-1 font-medium">
                                                                        Assign
                                                                        to
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
                    <RespiteTab
                        clientId={client.id}
                        canCreate={Boolean(respiteCan?.create)}
                        bookings={respiteBookings}
                        requests={respiteRequests}
                        allocation={respite?.allocation ?? null}
                        onNewBooking={() =>
                            openProfileDialog('respite_booking')
                        }
                    />
                )}

                {tab === 'location' && location && (
                    <ClientLocationTab
                        clientId={client.id}
                        clientName={name}
                        clientHouse={client.site?.name ?? ''}
                        clientPhoto={client.profile_photo_url ?? null}
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
                            given: 'bg-status-success-bg text-status-success',
                            refused:
                                'bg-status-critical-bg text-status-critical',
                            withdrawn: 'bg-muted text-muted-foreground',
                            expired: 'bg-status-warning-bg text-status-warning',
                        };

                        return (
                            <div className="space-y-4">
                                {/* Stats */}
                                <div className="grid grid-cols-4 gap-3">
                                    <div className="rounded-lg border p-3 text-center">
                                        <div className="text-lg font-bold text-primary">
                                            {consents.length}
                                        </div>
                                        <div className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                            Total
                                        </div>
                                    </div>
                                    <div className="rounded-lg border p-3 text-center">
                                        <div className="text-lg font-bold text-status-success">
                                            {activeCount}
                                        </div>
                                        <div className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                            Active
                                        </div>
                                    </div>
                                    <div className="rounded-lg border p-3 text-center">
                                        <div
                                            className={`text-lg font-bold ${expiringCount > 0 ? 'text-status-warning' : 'text-muted-foreground'}`}
                                        >
                                            {expiringCount}
                                        </div>
                                        <div className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                            Expiring
                                        </div>
                                    </div>
                                    <div className="rounded-lg border p-3 text-center">
                                        <div
                                            className={`text-lg font-bold ${expiredCount > 0 ? 'text-status-critical' : 'text-muted-foreground'}`}
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
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/operations/clients/${client.id}/consents`}
                                                    >
                                                        Manage Consents
                                                    </Link>
                                                </Button>
                                                {can.edit ? (
                                                    <Button
                                                        size="sm"
                                                        onClick={() =>
                                                            openProfileDialog(
                                                                'consent_record',
                                                            )
                                                        }
                                                        data-test="consents-record"
                                                    >
                                                        <Plus className="mr-1.5 h-3.5 w-3.5" />
                                                        Record consent
                                                    </Button>
                                                ) : null}
                                            </div>
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
                                                                        className={`rounded-full px-2 py-0.5 text-[10px] font-medium capitalize ${STATUS_COLORS[displayStatus] ?? 'bg-muted text-muted-foreground'}`}
                                                                    >
                                                                        {
                                                                            displayStatus
                                                                        }
                                                                    </span>
                                                                    {c.capacity_assessed && (
                                                                        <span className="rounded bg-primary/10 px-1.5 py-0.5 text-[10px] text-primary">
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
                                                                                    ? 'font-medium text-status-critical'
                                                                                    : c.is_expiring_soon
                                                                                      ? 'font-medium text-status-warning'
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

                {tab === 'consent-requests' &&
                    (() => {
                        const requests = ((pageProps as any)
                            .consent_request_list ?? []) as any[];
                        const pending = requests.filter(
                            (r) => r.status === 'pending',
                        ).length;
                        const approved = requests.filter(
                            (r) => r.status === 'approved',
                        ).length;
                        const declined = requests.filter(
                            (r) => r.status === 'declined',
                        ).length;
                        const REQ_TONES: Record<string, string> = {
                            pending: 'bg-status-warning-bg text-status-warning',
                            approved:
                                'bg-status-success-bg text-status-success',
                            declined:
                                'bg-status-critical-bg text-status-critical',
                            cancelled: 'bg-muted text-muted-foreground',
                            expired: 'bg-muted text-muted-foreground',
                        };
                        return (
                            <div className="space-y-4">
                                <div className="grid grid-cols-4 gap-3">
                                    {[
                                        ['Total', requests.length, ''],
                                        [
                                            'Pending',
                                            pending,
                                            'text-status-warning',
                                        ],
                                        [
                                            'Approved',
                                            approved,
                                            'text-status-success',
                                        ],
                                        [
                                            'Declined',
                                            declined,
                                            'text-status-critical',
                                        ],
                                    ].map(([label, value, tone]) => (
                                        <div
                                            key={String(label)}
                                            className="rounded-lg border p-3 text-center"
                                        >
                                            <div
                                                className={`text-lg font-bold ${tone || 'text-primary'}`}
                                            >
                                                {value}
                                            </div>
                                            <div className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                                {label}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center justify-between text-base">
                                            <span>
                                                Consent requests sent to whānau
                                            </span>
                                            {(auth?.can?.consents?.request ??
                                                false) && (
                                                <Button size="sm" asChild>
                                                    <Link
                                                        href={`/operations/clients/${client.id}/consent-requests/create`}
                                                    >
                                                        <Plus className="mr-1.5 h-3.5 w-3.5" />
                                                        New request
                                                    </Link>
                                                </Button>
                                            )}
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {requests.length === 0 ? (
                                            <p className="py-8 text-center text-sm text-muted-foreground">
                                                No consent requests yet — send
                                                one to whānau for a decision on
                                                the portal.
                                            </p>
                                        ) : (
                                            <div className="space-y-2">
                                                {requests.map((r) => (
                                                    <Link
                                                        key={r.id}
                                                        href={`/operations/clients/${client.id}/consent-requests/${r.id}`}
                                                        className="flex items-center justify-between gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/40"
                                                    >
                                                        <div className="min-w-0">
                                                            <div className="truncate text-sm font-semibold">
                                                                {r.consent_type}
                                                            </div>
                                                            <div className="mt-0.5 text-xs text-muted-foreground">
                                                                To{' '}
                                                                {r.recipient ??
                                                                    '—'}
                                                                {r.recipient_relationship
                                                                    ? ` (${r.recipient_relationship})`
                                                                    : ''}
                                                                {r.created_at
                                                                    ? ` · sent ${new Date(r.created_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}`
                                                                    : ''}
                                                                {r.status ===
                                                                    'pending' &&
                                                                r.expires_at
                                                                    ? ` · expires ${new Date(r.expires_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}`
                                                                    : ''}
                                                            </div>
                                                        </div>
                                                        <span
                                                            className={`rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize ${REQ_TONES[r.status] ?? 'bg-muted text-muted-foreground'}`}
                                                        >
                                                            {r.status}
                                                        </span>
                                                    </Link>
                                                ))}
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
                                    <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                                        {portal_users.length}
                                    </span>
                                </div>
                                {can.edit && (
                                    <div className="flex items-center gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <Link
                                                href={`/operations/clients/${client.id}/portal-users`}
                                            >
                                                Manage access
                                            </Link>
                                        </Button>
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                openProfileDialog(
                                                    'portal_invite',
                                                )
                                            }
                                            data-test="portal-invite"
                                        >
                                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                                            Invite
                                        </Button>
                                    </div>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-sm text-muted-foreground">
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
                                                    <span className="rounded-full bg-status-warning-bg px-2 py-0.5 text-[10px] font-medium text-status-warning">
                                                        Legal Guardian
                                                    </span>
                                                )}
                                                {u.is_emergency_contact && (
                                                    <span className="rounded-full bg-status-critical-bg px-2 py-0.5 text-[10px] font-medium text-status-critical">
                                                        Emergency
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {u.email}
                                            </div>
                                            {u.relation && (
                                                <div className="mt-0.5 text-xs text-muted-foreground">
                                                    Relation: {u.relation}
                                                </div>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {u.status === 'active' ||
                                            u.is_active !== false ? (
                                                <span className="rounded-full bg-status-success-bg px-2 py-0.5 text-[10px] font-medium text-status-success">
                                                    Active
                                                </span>
                                            ) : (
                                                <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                                                    Inactive
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                ))}
                                {!portal_users.length && (
                                    <div className="py-8 text-center text-sm text-muted-foreground">
                                        No portal users linked. Add a next of
                                        kin or family member to get started.
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {tab === 'personal_assets' && (
                    <div className="space-y-4">
                        {can.edit ? (
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                <Button
                                    onClick={() =>
                                        openProfileDialog('add_asset')
                                    }
                                    data-test="assets-add-item"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add item
                                </Button>
                            </div>
                        ) : null}
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
                    </div>
                )}

                {tab === 'transport' &&
                    (() => {
                        const ts = transport?.stats ?? {
                            transports_30d: 0,
                            outings_30d: 0,
                            incidents_30d: 0,
                        };
                        const upcoming = transport?.upcoming_outings ?? [];
                        const history = transport?.transport_history ?? [];
                        const medLogs = transport?.medication_logs ?? [];
                        const bookings = ((transport as any)?.bookings ??
                            []) as any[];

                        return (
                            <div className="space-y-6">
                                {/* Header + book-transport workflow */}
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="flex items-center gap-3">
                                        <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-primary">
                                            <Truck className="h-[19px] w-[19px]" />
                                        </span>
                                        <div>
                                            <h2 className="text-lg leading-tight font-semibold">
                                                Transport
                                            </h2>
                                            <p className="text-sm text-muted-foreground">
                                                Bookings, outings & trip log
                                            </p>
                                        </div>
                                    </div>
                                    {can.edit ? (
                                        <Button
                                            onClick={() =>
                                                openProfileDialog(
                                                    'transport_booking',
                                                )
                                            }
                                            data-test="transport-book"
                                        >
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            Book transport
                                        </Button>
                                    ) : null}
                                </div>

                                {/* Scheduled bookings (Book transport workflow) */}
                                {bookings.length > 0 && (
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="flex items-center gap-2 text-base">
                                                <Truck className="h-4 w-4" />{' '}
                                                Scheduled transport
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="space-y-2">
                                                {bookings.map((b: any) => (
                                                    <div
                                                        key={b.id}
                                                        className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm"
                                                    >
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <span className="truncate font-semibold">
                                                                    {b.purpose}
                                                                </span>
                                                                <Badge
                                                                    variant={
                                                                        b.status ===
                                                                        'confirmed'
                                                                            ? 'default'
                                                                            : 'outline'
                                                                    }
                                                                    className="shrink-0 text-[10px] capitalize"
                                                                >
                                                                    {b.status}
                                                                </Badge>
                                                                {b.escort_required ? (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="shrink-0 text-[10px]"
                                                                    >
                                                                        Escort
                                                                    </Badge>
                                                                ) : null}
                                                                {b.return_trip ? (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="shrink-0 text-[10px]"
                                                                    >
                                                                        Return
                                                                    </Badge>
                                                                ) : null}
                                                            </div>
                                                            <div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                                                {b.scheduled_at ? (
                                                                    <span>
                                                                        {formatDateTimeLong(
                                                                            b.scheduled_at,
                                                                        )}
                                                                    </span>
                                                                ) : null}
                                                                {b.destination ? (
                                                                    <span>
                                                                        ·{' '}
                                                                        {
                                                                            b.destination
                                                                        }
                                                                    </span>
                                                                ) : null}
                                                                {b.vehicle ? (
                                                                    <span>
                                                                        ·{' '}
                                                                        {
                                                                            b.vehicle
                                                                        }
                                                                    </span>
                                                                ) : null}
                                                                {b.driver
                                                                    ?.name ? (
                                                                    <span>
                                                                        ·{' '}
                                                                        {
                                                                            b
                                                                                .driver
                                                                                .name
                                                                        }
                                                                    </span>
                                                                ) : null}
                                                            </div>
                                                        </div>
                                                        {can.edit ? (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                className="shrink-0 text-status-critical hover:bg-status-critical-bg hover:text-status-critical"
                                                                onClick={() =>
                                                                    router.delete(
                                                                        `/operations/clients/${client.id}/transport-bookings/${b.id}`,
                                                                        {
                                                                            preserveScroll: true,
                                                                            onSuccess:
                                                                                () =>
                                                                                    router.reload(
                                                                                        {
                                                                                            only: [
                                                                                                'transport',
                                                                                            ],
                                                                                        },
                                                                                    ),
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Cancel
                                                            </Button>
                                                        ) : null}
                                                    </div>
                                                ))}
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}

                                {/* Stats */}
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <Card className="border bg-status-info-bg">
                                        <CardContent className="p-4">
                                            <div className="text-2xl font-bold text-status-info dark:text-status-info">
                                                {ts.transports_30d}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                Transports (30d)
                                            </div>
                                        </CardContent>
                                    </Card>
                                    <Card className="border bg-primary/10 dark:bg-primary/20">
                                        <CardContent className="p-4">
                                            <div className="text-2xl font-bold text-primary dark:text-primary">
                                                {ts.outings_30d}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                Outings (30d)
                                            </div>
                                        </CardContent>
                                    </Card>
                                    <Card
                                        className={`border ${ts.incidents_30d > 0 ? 'bg-status-critical-bg' : 'bg-muted/30'}`}
                                    >
                                        <CardContent className="p-4">
                                            <div
                                                className={`text-2xl font-bold ${ts.incidents_30d > 0 ? 'text-status-critical' : 'text-muted-foreground'}`}
                                            >
                                                {ts.incidents_30d}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                Incidents (30d)
                                            </div>
                                        </CardContent>
                                    </Card>
                                </div>

                                {/* Upcoming Outings */}
                                {upcoming.length > 0 && (
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="flex items-center gap-2 text-base">
                                                <Calendar className="h-4 w-4" />{' '}
                                                Upcoming Outings
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
                                                                <span className="truncate font-semibold">
                                                                    {o.title}
                                                                </span>
                                                                <Badge
                                                                    variant={
                                                                        o.status ===
                                                                        'active'
                                                                            ? 'default'
                                                                            : 'outline'
                                                                    }
                                                                    className="shrink-0 text-[10px]"
                                                                >
                                                                    {o.status}
                                                                </Badge>
                                                            </div>
                                                            <div className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                                                                <span>
                                                                    {
                                                                        o.destination
                                                                    }
                                                                </span>
                                                                {o.vehicle && (
                                                                    <>
                                                                        <span>
                                                                            ·
                                                                        </span>
                                                                        <span>
                                                                            {
                                                                                o
                                                                                    .vehicle
                                                                                    .name
                                                                            }
                                                                        </span>
                                                                    </>
                                                                )}
                                                                {o.residents_count >
                                                                    1 && (
                                                                    <>
                                                                        <span>
                                                                            ·
                                                                        </span>
                                                                        <span>
                                                                            {
                                                                                o.residents_count
                                                                            }{' '}
                                                                            residents
                                                                        </span>
                                                                    </>
                                                                )}
                                                            </div>
                                                        </div>
                                                        {o.planned_departure && (
                                                            <div className="shrink-0 text-right text-xs text-muted-foreground">
                                                                <div>
                                                                    {new Date(
                                                                        o.planned_departure,
                                                                    ).toLocaleDateString(
                                                                        'en-NZ',
                                                                        {
                                                                            day: 'numeric',
                                                                            month: 'short',
                                                                        },
                                                                    )}
                                                                </div>
                                                                <div>
                                                                    {new Date(
                                                                        o.planned_departure,
                                                                    ).toLocaleTimeString(
                                                                        'en-NZ',
                                                                        {
                                                                            hour: '2-digit',
                                                                            minute: '2-digit',
                                                                        },
                                                                    )}
                                                                </div>
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
                                            <Truck className="h-4 w-4" />{' '}
                                            Transport History
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {history.length > 0 ? (
                                            <div className="overflow-x-auto">
                                                <table className="w-full text-xs">
                                                    <thead>
                                                        <tr className="border-b text-left text-muted-foreground">
                                                            <th className="pr-3 pb-2 font-medium">
                                                                Type
                                                            </th>
                                                            <th className="pr-3 pb-2 font-medium">
                                                                From / To
                                                            </th>
                                                            <th className="pr-3 pb-2 font-medium">
                                                                Vehicle
                                                            </th>
                                                            <th className="pr-3 pb-2 font-medium">
                                                                Driver
                                                            </th>
                                                            <th className="pr-3 pb-2 font-medium">
                                                                Date
                                                            </th>
                                                            <th className="pr-3 pb-2 font-medium">
                                                                Duration
                                                            </th>
                                                            <th className="pb-2 font-medium">
                                                                Status
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {history.map((t) => (
                                                            <tr
                                                                key={t.id}
                                                                className="border-b border-border/50 last:border-0"
                                                            >
                                                                <td className="py-2 pr-3">
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="text-[10px] capitalize"
                                                                    >
                                                                        {(
                                                                            t.transport_type ??
                                                                            ''
                                                                        ).replace(
                                                                            /_/g,
                                                                            ' ',
                                                                        )}
                                                                    </Badge>
                                                                </td>
                                                                <td className="py-2 pr-3">
                                                                    <div className="max-w-[140px] truncate">
                                                                        {t.pickup_location ??
                                                                            '—'}
                                                                    </div>
                                                                    <div className="max-w-[140px] truncate text-muted-foreground">
                                                                        →{' '}
                                                                        {t.dropoff_location ??
                                                                            '—'}
                                                                    </div>
                                                                </td>
                                                                <td className="py-2 pr-3">
                                                                    {t.vehicle
                                                                        ?.name ??
                                                                        '—'}
                                                                </td>
                                                                <td className="py-2 pr-3">
                                                                    {t.driver
                                                                        ?.name ??
                                                                        '—'}
                                                                </td>
                                                                <td className="py-2 pr-3 whitespace-nowrap">
                                                                    {t.departed_at
                                                                        ? formatDT(
                                                                              t.departed_at,
                                                                          )
                                                                        : '—'}
                                                                </td>
                                                                <td className="py-2 pr-3 whitespace-nowrap">
                                                                    {t.duration_minutes !=
                                                                    null
                                                                        ? `${Math.round(t.duration_minutes)}m`
                                                                        : '—'}
                                                                </td>
                                                                <td className="py-2">
                                                                    <Badge
                                                                        variant={
                                                                            t.status ===
                                                                            'completed'
                                                                                ? 'default'
                                                                                : t.status ===
                                                                                    'in_progress'
                                                                                  ? 'secondary'
                                                                                  : 'outline'
                                                                        }
                                                                        className="text-[10px]"
                                                                    >
                                                                        {
                                                                            t.status
                                                                        }
                                                                    </Badge>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ) : (
                                            <div className="flex flex-col items-center justify-center py-8 text-muted-foreground">
                                                <Truck className="mb-2 h-8 w-8 opacity-40" />
                                                <p className="text-sm font-medium">
                                                    No transport history
                                                </p>
                                                <p className="text-xs">
                                                    Transport records will
                                                    appear here when this
                                                    resident is transported.
                                                </p>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>

                                {/* Medication Transit Logs */}
                                {medLogs.length > 0 && (
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="flex items-center gap-2 text-base">
                                                <Pill className="h-4 w-4" />{' '}
                                                Medication Transit Log
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="overflow-x-auto">
                                                <table className="w-full text-xs">
                                                    <thead>
                                                        <tr className="border-b text-left text-muted-foreground">
                                                            <th className="pr-3 pb-2 font-medium">
                                                                Medication
                                                            </th>
                                                            <th className="pr-3 pb-2 font-medium">
                                                                Packed
                                                            </th>
                                                            <th className="pr-3 pb-2 font-medium">
                                                                Administered
                                                            </th>
                                                            <th className="pr-3 pb-2 font-medium">
                                                                Returned
                                                            </th>
                                                            <th className="pb-2 font-medium">
                                                                Status
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {medLogs.map((m) => (
                                                            <tr
                                                                key={m.id}
                                                                className="border-b border-border/50 last:border-0"
                                                            >
                                                                <td className="py-2 pr-3">
                                                                    <div className="flex items-center gap-1.5">
                                                                        <span className="font-medium">
                                                                            {
                                                                                m.medication_name
                                                                            }
                                                                        </span>
                                                                        {m.is_controlled_drug && (
                                                                            <Badge
                                                                                variant="destructive"
                                                                                className="px-1 text-[8px]"
                                                                            >
                                                                                CD
                                                                            </Badge>
                                                                        )}
                                                                    </div>
                                                                </td>
                                                                <td className="py-2 pr-3">
                                                                    {m.packed_at ? (
                                                                        <div>
                                                                            <div>
                                                                                {formatDT(
                                                                                    m.packed_at,
                                                                                )}
                                                                            </div>
                                                                            {m.packed_by && (
                                                                                <div className="text-muted-foreground">
                                                                                    by{' '}
                                                                                    {
                                                                                        m.packed_by
                                                                                    }
                                                                                </div>
                                                                            )}
                                                                        </div>
                                                                    ) : (
                                                                        '—'
                                                                    )}
                                                                </td>
                                                                <td className="py-2 pr-3">
                                                                    {m.administered_at ? (
                                                                        <div>
                                                                            <div>
                                                                                {formatDT(
                                                                                    m.administered_at,
                                                                                )}
                                                                            </div>
                                                                            {m.administered_by && (
                                                                                <div className="text-muted-foreground">
                                                                                    by{' '}
                                                                                    {
                                                                                        m.administered_by
                                                                                    }
                                                                                </div>
                                                                            )}
                                                                            {m.is_controlled_drug &&
                                                                                m.witnessed_by && (
                                                                                    <div className="text-muted-foreground">
                                                                                        witnessed:{' '}
                                                                                        {
                                                                                            m.witnessed_by
                                                                                        }
                                                                                    </div>
                                                                                )}
                                                                        </div>
                                                                    ) : (
                                                                        '—'
                                                                    )}
                                                                </td>
                                                                <td className="py-2 pr-3">
                                                                    {m.returned_to_house_at
                                                                        ? formatDT(
                                                                              m.returned_to_house_at,
                                                                          )
                                                                        : '—'}
                                                                </td>
                                                                <td className="py-2">
                                                                    <Badge
                                                                        variant={
                                                                            m.status ===
                                                                            'returned'
                                                                                ? 'default'
                                                                                : m.status ===
                                                                                    'administered'
                                                                                  ? 'secondary'
                                                                                  : 'outline'
                                                                        }
                                                                        className="text-[10px] capitalize"
                                                                    >
                                                                        {
                                                                            m.status
                                                                        }
                                                                    </Badge>
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
                    <WorkersTab
                        client={client}
                        assignableWorkers={
                            (pageProps as any).assignable_workers ?? []
                        }
                        canAssign={Boolean(can.assign_workers)}
                    />
                )}

                {tab === 'privacy' && (
                    <ClientPrivacyPanel
                        requests={dataSubjectRequests}
                        canManage={Boolean(privacyCan?.processRequests)}
                    />
                )}
            </PageShell>

            <ClientEditDialog
                clientId={client.id}
                open={editDialogOpen}
                onOpenChange={(open) => {
                    if (!open && profileDialog?.key === 'edit_profile') {
                        closeProfileDialog();
                    }
                }}
            />

            {createShiftLauncher.dialog}
        </AppLayout>
    );
}
