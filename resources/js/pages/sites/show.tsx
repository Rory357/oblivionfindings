import { ChecklistsWorkspace } from '@/components/checklists/workspace';
import { RaRegisterSection } from '@/components/health-safety/risk-assessments/ra-register-section';
import type { RaPickers, RaRow } from '@/components/health-safety/risk-assessments/types';
import { HorizontalBarChart, ProgressRing } from '@/components/fleet-charts';
import { MissingFieldButton } from '@/components/missing-field-button';
import { DonutChart } from '@/components/ops-stat-card';
import {
    PageHero,
    PageLayout,
    PageTabs,
    type PageTabItem,
} from '@/components/page';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import { Switch } from '@/components/ui/switch';
import { TabsContent } from '@/components/ui/tabs';
import { ApplicableProceduresPanel, type ApplicableProcedure } from '@/components/health-safety/applicable-procedures-panel';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/datetime';
import { formatCurrency } from '@/lib/fleet-utils';
import type { ChecklistsData } from '@/components/checklists/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    Award,
    BedDouble,
    Building2,
    Cake,
    Calendar,
    Car,
    ChevronRight,
    ClipboardCheck,
    ClipboardList,
    Clock,
    Cpu,
    DollarSign,
    Download,
    DoorOpen,
    ExternalLink,
    Eye,
    FileText,
    Flame,
    FolderOpen,
    Fuel,
    GraduationCap,
    HeartPulse,
    Home,
    KeyRound,
    Layers,
    LayoutGrid,
    Link2,
    Lock,
    Mail,
    MapPin,
    Navigation,
    Package,
    Phone,
    Pencil,
    Pill,
    Plus,
    Route,
    Shield,
    ShieldAlert,
    Star,
    StickyNote,
    Trash2,
    Truck,
    User,
    UserCog,
    Users,
    Utensils,
    Warehouse,
} from 'lucide-react';
import { RISK, RiskChip, StatusChip, fmtDueShort } from '@/components/health-safety/hazard-kit';
import {
    lazy,
    Suspense,
    useEffect,
    useMemo,
    useRef,
    useState,
    type ComponentType,
    type ReactNode,
} from 'react';
import {
    formatSiteDocumentFileSize,
    getSiteDocumentCategory,
    getSiteDocumentFileInfo,
    isSiteDocumentExpired,
    isSiteDocumentExpiringSoon,
    type SiteDocumentRecord,
} from './_document-helpers';
import SiteLedgerPanel, {
    type SiteLedgerPanelData,
} from './_ledger-panel';
import {
    AddSiteNoteDialog,
    EditSiteLineDialog,
    EditLocationDialog,
    EditSafetyDialog,
} from './_overview-dialogs';
import { ConfirmAction } from './_confirm-action';
import SiteOverviewMapCard from './_overview-map-card';
import { SiteReadinessPanel, type SiteReadiness } from './_readiness-panel';
import SiteGeofenceDialog, {
    type SiteGeofenceRecord,
} from './_site-geofence-dialog';
import type { CredentialPickerOption } from './_dialog-shared';
import type { VendorRecord } from './vendors/_dialogs';
import type { CredentialRecord } from './credentials/_dialogs';
import type { ContactRecord, ContactTypeKey } from './contacts/_dialogs';
import { getContactType } from './contacts/_helpers';
import type { ClientForPicker, RoomRecord } from './rooms/_dialogs';
import type { ClientRecord } from './clients/_dialogs';
import {
    getClientDisplayName,
    getClientInitials,
    getClientRiskStyle,
    getClientStatusStyle,
} from './clients/_helpers';
import SiteTypePlanBuilderDialog from './plan/_builder-dialog';
import type { BuilderTool } from './plan/_tool-palette';
import { PlanThumbnail, type PlanLayout, type PlanPin } from './plan/_thumbnail';
import type { BuilderMode, Inventory, Taxonomy } from './plan/_types';
const AddVendorDialog = lazy(() =>
    import('./vendors/_dialogs').then((module) => ({
        default: module.AddVendorDialog,
    })),
);
const EditVendorDialog = lazy(() =>
    import('./vendors/_dialogs').then((module) => ({
        default: module.EditVendorDialog,
    })),
);
const ShowVendorDialog = lazy(() =>
    import('./vendors/_dialogs').then((module) => ({
        default: module.ShowVendorDialog,
    })),
);
const DeleteVendorDialog = lazy(() =>
    import('./vendors/_dialogs').then((module) => ({
        default: module.DeleteVendorDialog,
    })),
);

const AddCredentialDialog = lazy(() =>
    import('./credentials/_dialogs').then((module) => ({
        default: module.AddCredentialDialog,
    })),
);
const EditCredentialDialog = lazy(() =>
    import('./credentials/_dialogs').then((module) => ({
        default: module.EditCredentialDialog,
    })),
);
const ShowCredentialDialog = lazy(() =>
    import('./credentials/_dialogs').then((module) => ({
        default: module.ShowCredentialDialog,
    })),
);
const DeleteCredentialDialog = lazy(() =>
    import('./credentials/_dialogs').then((module) => ({
        default: module.DeleteCredentialDialog,
    })),
);
const RemoveTotpDialog = lazy(() =>
    import('./credentials/_dialogs').then((module) => ({
        default: module.RemoveTotpDialog,
    })),
);

const AddContactDialog = lazy(() =>
    import('./contacts/_dialogs').then((module) => ({
        default: module.AddContactDialog,
    })),
);
const EditContactDialog = lazy(() =>
    import('./contacts/_dialogs').then((module) => ({
        default: module.EditContactDialog,
    })),
);
const ShowContactDialog = lazy(() =>
    import('./contacts/_dialogs').then((module) => ({
        default: module.ShowContactDialog,
    })),
);
const DeleteContactDialog = lazy(() =>
    import('./contacts/_dialogs').then((module) => ({
        default: module.DeleteContactDialog,
    })),
);

const MealPlannerSubTabs = lazy(() => import('./meal-planner'));
const SiteCalendarEmbed = lazy(() => import('./calendar/SiteCalendar'));

const AddClientDialog = lazy(() =>
    import('./clients/_dialogs').then((module) => ({
        default: module.AddClientDialog,
    })),
);
const ShowClientDialog = lazy(() =>
    import('./clients/_dialogs').then((module) => ({
        default: module.ShowClientDialog,
    })),
);
const UnlinkClientDialog = lazy(() =>
    import('./clients/_dialogs').then((module) => ({
        default: module.UnlinkClientDialog,
    })),
);
const AssignRoomToClientDialog = lazy(() =>
    import('./rooms/_dialogs').then((module) => ({
        default: module.AssignRoomToClientDialog,
    })),
);

const AddRoomDialog = lazy(() =>
    import('./rooms/_dialogs').then((module) => ({
        default: module.AddRoomDialog,
    })),
);
const EditRoomDialog = lazy(() =>
    import('./rooms/_dialogs').then((module) => ({
        default: module.EditRoomDialog,
    })),
);
const DeleteRoomDialog = lazy(() =>
    import('./rooms/_dialogs').then((module) => ({
        default: module.DeleteRoomDialog,
    })),
);
const ShowRoomDialog = lazy(() =>
    import('./rooms/_dialogs').then((module) => ({
        default: module.ShowRoomDialog,
    })),
);
const AssignClientToRoomDialog = lazy(() =>
    import('./rooms/_dialogs').then((module) => ({
        default: module.AssignClientToRoomDialog,
    })),
);
const UnassignRoomDialog = lazy(() =>
    import('./rooms/_dialogs').then((module) => ({
        default: module.UnassignRoomDialog,
    })),
);


function LazyDialog({ children }: { children: ReactNode }) {
    return <Suspense fallback={null}>{children}</Suspense>;
}

type Site = {
    id: number;
    name: string;
    type: 'head_office' | 'house' | 'facility' | 'residential';
    display_type: string;
    phone?: string | null;
    email?: string | null;
    manager_contact?: Contact | null;
    site_lead_contact?: Contact | null;
    after_hours_contact?: Contact | null;
    primary_site_contact?: Contact | null;
    emergency_plan_location?: string | null;
    medication_storage_location?: string | null;
    notes?: string | null;
    address?: string;
    address_line_1?: string | null;
    address_line_2?: string | null;
    suburb?: string | null;
    city?: string | null;
    postcode?: string | null;
    country?: string | null;
    region?: string | null;
    latitude?: string | null;
    longitude?: string | null;
    access_instructions?: string | null;
    is_active: boolean;
    is_high_risk: boolean;
    is_high_needs: boolean;
    risk_notes?: string | null;
    risk_review_date?: string | null;
    primary_contact?: { id: number; name: string } | null;
    service_contexts?: Array<{
        id: number;
        name: string;
        type?: string;
        is_active: boolean;
        description?: string;
    }>;
};

type Contact = {
    id: number;
    type?: string | null;
    name: string;
    role?: string | null;
    phone?: string | null;
    email?: string | null;
    is_primary: boolean;
    notes?: string | null;
};

type SiteContactType = ContactTypeKey;

type Doc = SiteDocumentRecord;

type AssetLite = {
    id: number;
    name: string;
    asset_tag?: string | null;
    category?: string | null;
    status: string;
    risk_level: string;
    location?: string | null;
    owner: { type: 'site' | 'client'; label: string; id: number };
    updated_at?: string | null;
};

type ClientLite = {
    id: number;
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
    full_name?: string | null;
    status: string;
    profile_photo_url?: string | null;
    date_of_birth?: string | null;
    age?: number | null;
    gender?: string | null;
    risk_level?: string | null;
    safeguarding_flag?: boolean;
    service_start_date?: string | null;
    funding_type?: string | null;
    key_worker?: { id: number; name: string } | null;
    service_context?: { id: number; name: string; type?: string | null } | null;
    room?: { id: number; name: string } | null;
};

type AvailableClient = {
    id: number;
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
    full_name?: string | null;
    status: string;
};

type ClientsSummary = {
    total: number;
    active: number;
    onboarding: number;
    inactive: number;
    high_risk: number;
    safeguarding: number;
};
type ChecklistItem = { key: string; label: string; done: boolean };
type Occupancy = {
    label: string;
    noun: string;
    rooms_total: number;
    rooms_occupied: number;
    vacancies: number;
    percent: number;
};

type TypeSpecificData = {
    rooms?: Array<{
        id: number;
        name: string;
        notes?: string | null;
        is_active?: boolean;
        is_assignable?: boolean;
        sort_order?: number;
        assigned_from?: string | null;
        assigned_until?: string | null;
        assigned_client?: {
            id: number;
            first_name?: string;
            last_name?: string;
            preferred_name?: string | null;
            status?: string | null;
            profile_photo_url?: string | null;
            name?: string | null;
        } | null;
        history?: Array<{
            id: number;
            client: {
                id: number;
                first_name: string;
                last_name: string;
            } | null;
            assigned_from?: string | null;
            assigned_until?: string | null;
            assigned_by?: string | null;
            notes?: string | null;
        }>;
    }>;
    resources?: Array<{
        id: number;
        name: string;
        type: string;
        capacity?: number;
    }>;
    zones?: Array<{ id: number; name: string; type?: string }>;
};

type SiteTypePlanRecord = {
    id: number;
    status: string;
    version: number;
    layout: PlanLayout;
    notes?: string | null;
    pins: PlanPin[];
    published_at?: string | null;
};

type SiteTypePlanSummary = {
    tab_label: string;
    inventory_label: string;
    inventory_href: string;
    status: 'empty' | 'draft' | 'published' | 'draft_over_published';
    draft?: SiteTypePlanRecord | null;
    published?: SiteTypePlanRecord | null;
    has_plan: boolean;
    has_published: boolean;
    has_emergency_layer: boolean;
    has_medication_pin: boolean;
    pin_counts: Record<string, number>;
    inventory?: Inventory | null;
    taxonomy?: Taxonomy | null;
    emergency_pin_kinds?: string[];
};

type RoomsSummary = {
    total: number;
    bedrooms: number;
    communal: number;
    occupied: number;
    available: number;
    occupancy_percent: number;
};

type VendorLite = {
    id: number;
    company_name: string;
    service_type: string;
    contact_name?: string | null;
    phone?: string | null;
    after_hours_phone?: string | null;
    email?: string | null;
    account_number?: string | null;
    notes?: string | null;
    preferred_contact_method?: string | null;
    is_preferred: boolean;
    is_active?: boolean;
};

type CredentialLite = {
    id: number;
    label: string;
    username?: string | null;
    url?: string | null;
    credential_type: string;
    vendor_id?: number | null;
    vendor_name?: string | null;
    notes?: string | null;
    requires_reauth: boolean;
    is_shareable: boolean;
    password_strength?: number | null;
    has_totp: boolean;
    last_rotated_at?: string | null;
};

type StaffRequirement = {
    id: number;
    requirement_name: string;
    category: 'mandatory' | 'recommended' | 'specialist';
    description?: string | null;
    certification_required: boolean;
    expiry_period_months?: number | null;
};

type CoverageRequirement = {
    id: number;
    name: string;
    coverage_type: 'day' | 'evening' | 'overnight' | 'custom';
    day_of_week: 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';
    starts_time: string;
    ends_time: string;
    minimum_staff: number;
    preferred_client?: { id: number; name: string } | null;
    role_requirements?: Array<{ key: string; minimum: number }>;
    allow_overstaffing?: boolean;
    shift_type?: string | null;
    notes?: string | null;
    service_context?: { id: number; name: string; type?: string | null } | null;
};


type SiteFleetData = {
    vehicles: Array<{
        id: number;
        name: string;
        asset_tag: string;
        status: string;
        fleet_status: string | null;
        speed_kph: number | null;
        last_seen_at: string | null;
        consent_blocked: boolean;
        wof_expires_at: string | null;
        registration_expires_at: string | null;
    }>;
    today_bookings: Array<{
        id: number;
        vehicle: { id: number; name: string } | null;
        booked_by: string | null;
        purpose: string | null;
        status: string;
        starts_at: string | null;
        ends_at: string | null;
    }>;
    active_outings: Array<{
        id: number;
        title: string;
        destination: string;
        status: string;
        planned_departure: string | null;
        vehicle: { id: number; name: string } | null;
        driver: { id: number; name: string } | null;
        residents_count: number;
    }>;
    stats: {
        trips_this_month: number;
        distance_this_month: number;
        fuel_cost_this_month: number;
        incidents_this_month: number;
    };
    compliance: Array<{
        vehicle_name: string;
        vehicle_id: number;
        items: Array<{
            type: string;
            expires_at: string;
            days_remaining: number;
            status: string;
        }>;
    }>;
};

type InspectionsSummary = {
    active_schedules: number;
    overdue_schedules: number;
    due_soon_schedules: number;
    failed_records: number;
    schedules: Array<{
        id: number;
        inspection_type: string;
        title: string;
        frequency: string;
        next_due_date: string | null;
        assigned_to_name: string | null;
        is_overdue: boolean;
    }>;
    records: Array<{
        id: number;
        schedule_title: string | null;
        due_date: string | null;
        completed_at: string | null;
        completed_by_name: string | null;
        result: 'pass' | 'fail' | 'partial' | 'na' | null;
        findings: string | null;
    }>;
};

type DrillsSummary = {
    drill_status: 'compliant' | 'due_soon' | 'overdue';
    last_drill_at: string | null;
    next_drill_at: string | null;
    scheduled_count: number;
    open_findings: number;
};

type Props = {
    site: Site;
    clients: ClientLite[];
    availableClients?: AvailableClient[];
    clientsSummary?: ClientsSummary;
    contacts: Contact[];
    documents: Doc[];
    assets: AssetLite[];
    checklist: ChecklistItem[];
    readiness?: SiteReadiness;
    occupancy: Occupancy;
    houseLedger?: SiteLedgerPanelData | null;
    typeSpecificData: TypeSpecificData;
    roomsSummary?: RoomsSummary | null;
    vendors?: VendorLite[];
    credentials?: CredentialLite[];
    credentialTypeOptions?: CredentialPickerOption[];
    staffRequirements?: StaffRequirement[];
    coverageRequirements?: CoverageRequirement[];
    coveragePreview?: Array<{
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
        }>;
    }>;
    credentialCount?: number;
    hardwareCount?: number;
    typePlan?: SiteTypePlanSummary | null;
    integrationStatus?: Array<{ provider: string; status: string }>;
    can_edit: boolean;
    can?: { createAsset?: boolean };
    fleet?: SiteFleetData;
    checklistsData?: ChecklistsData;
    inspectionsSummary?: InspectionsSummary;
    drillsSummary?: DrillsSummary;
    siteNotes?: Array<{
        id: number;
        body: string;
        created_at: string | null;
        created_by: { id: number; name: string } | null;
    }>;
    geofences?: SiteGeofenceRecord[];
};

const typeIcons = {
    head_office: Building2,
    house: Home,
    residential: Home,
    facility: Warehouse,
};

const typeColors = {
    head_office: 'bg-status-info-bg text-status-info border-status-info/30',
    house: 'bg-status-success-bg text-status-success border-status-success/30',
    residential: 'bg-status-success-bg text-status-success border-status-success/30',
    facility:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
};

const emptyInspectionsSummary: InspectionsSummary = {
    active_schedules: 0,
    overdue_schedules: 0,
    due_soon_schedules: 0,
    failed_records: 0,
    schedules: [],
    records: [],
};

const emptyDrillsSummary: DrillsSummary = {
    drill_status: 'overdue',
    last_drill_at: null,
    next_drill_at: null,
    scheduled_count: 0,
    open_findings: 0,
};

const inspectionResultStyles: Record<
    NonNullable<InspectionsSummary['records'][number]['result']>,
    string
> = {
    pass: 'border-status-success/30 bg-status-success-bg text-status-success',
    fail: 'border-status-critical/30 bg-status-critical-bg text-status-critical',
    partial: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    na: 'border-border bg-muted text-muted-foreground',
};

function inspectionTypeLabel(value: string): string {
    return value
        .split('_')
        .filter(Boolean)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

function fallbackTypePlan(site: Site): SiteTypePlanSummary {
    const tabLabel =
        site.type === 'head_office'
            ? 'Office Plan'
            : site.type === 'facility'
              ? 'Facility Plan'
              : 'House Plan';
    const inventoryLabel =
        site.type === 'head_office'
            ? 'Manage resources'
            : site.type === 'facility'
              ? 'Manage zones'
              : 'Manage rooms';
    const inventoryHref =
        site.type === 'head_office'
            ? `/sites/${site.id}/resources`
            : site.type === 'facility'
              ? `/sites/${site.id}/zones`
              : `/sites/${site.id}/rooms`;

    return {
        tab_label: tabLabel,
        inventory_label: inventoryLabel,
        inventory_href: inventoryHref,
        status: 'empty',
        draft: null,
        published: null,
        has_plan: false,
        has_published: false,
        has_emergency_layer: false,
        has_medication_pin: false,
        pin_counts: {},
        inventory: null,
        taxonomy: null,
        emergency_pin_kinds: [],
    };
}

function planStatusLabel(status: SiteTypePlanSummary['status']) {
    if (status === 'draft_over_published') return 'Draft changes';
    if (status === 'draft') return 'Draft';
    if (status === 'published') return 'Published';
    return 'Not started';
}

function bytes(n?: number | null): string {
    if (!n || n <= 0) return '—';
    const kb = n / 1024;
    if (kb < 1024) return `${kb.toFixed(1)} KB`;
    const mb = kb / 1024;
    return `${mb.toFixed(1)} MB`;
}

function ContactRow({
    icon: Icon,
    label,
    value,
    href,
    canFix = false,
    onFix,
    testId,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    value?: string | null;
    href?: string;
    canFix?: boolean;
    onFix?: () => void;
    testId?: string;
}) {
    const content = (
        <div
            className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/40"
            data-test={testId}
        >
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                <Icon className="h-4 w-4" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {label}
                </div>
                <div className="truncate text-sm font-medium">
                    {value || (
                        <MissingFieldButton
                            label={`Add ${label.toLowerCase()}`}
                            onClick={canFix ? onFix : undefined}
                        />
                    )}
                </div>
            </div>
        </div>
    );
    return value && href ? (
        <a href={href} className="block text-foreground hover:text-primary">
            {content}
        </a>
    ) : (
        content
    );
}

function DerivedContactRow({
    icon: Icon,
    label,
    contact,
    emptyCta,
    onAdd,
    onEdit,
    testId,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    contact?: Contact | null;
    emptyCta?: string;
    onAdd?: () => void;
    onEdit?: () => void;
    testId?: string;
}) {
    const phoneHref = contact?.phone ? `tel:${contact.phone}` : undefined;
    const emailHref = contact?.email ? `mailto:${contact.email}` : undefined;

    return (
        <div
            className="group flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/40"
            data-test={testId}
        >
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                <Icon className="h-4 w-4" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {label}
                </div>
                {contact ? (
                    <div className="min-w-0">
                        <div className="truncate text-sm font-medium">
                            {contact.name}
                        </div>
                        <div className="mt-0.5 flex min-w-0 flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
                            {contact.phone && (
                                <a
                                    href={phoneHref}
                                    className="truncate hover:text-primary hover:underline"
                                >
                                    {contact.phone}
                                </a>
                            )}
                            {contact.email && (
                                <a
                                    href={emailHref}
                                    className="truncate hover:text-primary hover:underline"
                                >
                                    {contact.email}
                                </a>
                            )}
                            {!contact.phone && !contact.email && (
                                <span>No phone or email</span>
                            )}
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm text-muted-foreground">
                            Not set
                        </span>
                        {emptyCta && onAdd && (
                            <button
                                type="button"
                                onClick={onAdd}
                                className="text-sm font-medium text-primary hover:underline"
                            >
                                {emptyCta} →
                            </button>
                        )}
                    </div>
                )}
            </div>
            {contact && onEdit && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-8 shrink-0 gap-1 px-2 text-xs opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100"
                    onClick={onEdit}
                    aria-label={`Edit ${label.toLowerCase()} in Contacts`}
                >
                    <Pencil className="h-3.5 w-3.5" />
                    <span className="hidden sm:inline">Edit</span>
                </Button>
            )}
        </div>
    );
}

function MiniOccupancyStat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-md border bg-muted/30 px-3 py-2">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-1 text-lg font-semibold">{value}</p>
        </div>
    );
}

export default function SiteShow({
    site,
    clients,
    availableClients = [],
    clientsSummary,
    assets,
    contacts,
    documents,
    checklist,
    readiness,
    occupancy,
    houseLedger,
    typeSpecificData,
    roomsSummary,
    vendors = [],
    credentials = [],
    credentialTypeOptions = [],
    staffRequirements = [],
    coverageRequirements = [],
    coveragePreview = [],
    credentialCount = 0,
    hardwareCount = 0,
    typePlan = null,
    integrationStatus = [],
    can_edit,
    can: assetCan,
    fleet,
    checklistsData,
    inspectionsSummary = emptyInspectionsSummary,
    drillsSummary = emptyDrillsSummary,
    siteNotes = [],
    geofences = [],
}: Props) {
    const TypeIcon = typeIcons[site.type];
    const page = usePage<any>();
    const canGlobal = page.props?.auth?.can;
    const typePlanSummary = typePlan ?? fallbackTypePlan(site);
    const activePlan = typePlanSummary.draft ?? typePlanSummary.published;
    const canSeeVendorsCredentials = !!(
        canGlobal?.vendors?.view || canGlobal?.credentials?.view
    );

    // Overview-card edit dialogs
    const [contactInfoOpen, setContactInfoOpen] = useState(false);
    const [addContactType, setAddContactType] =
        useState<SiteContactType | null>(null);
    const [editContactId, setEditContactId] = useState<number | null>(null);
    const [locationOpen, setLocationOpen] = useState(false);
    const [siteGeofenceOpen, setSiteGeofenceOpen] = useState(false);
    const [safetyOpen, setSafetyOpen] = useState(false);
    const [noteOpen, setNoteOpen] = useState(false);
    const [planBuilderOpen, setPlanBuilderOpen] = useState(false);
    const [planBuilderFocus, setPlanBuilderFocus] = useState<BuilderTool | undefined>();
    const [planBuilderMode, setPlanBuilderMode] = useState<BuilderMode>('full');
    const initialTabFromQuery =
        typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search).get('tab')
            : null;
    const [activeTab, setActiveTab] = useState(
        initialTabFromQuery ??
            (readiness?.is_active_but_incomplete ? 'readiness' : 'overview'),
    );
    const readinessRef = useRef<HTMLDivElement | null>(null);
    const canManageGeofences = !!(
        canGlobal?.assets?.geofencesManage ?? can_edit
    );
    const siteGeofence =
        geofences.find((geofence) => geofence.asset_id == null) ??
        geofences[0] ??
        null;
    const overviewEditContact =
        editContactId != null
            ? (contacts.find((contact) => contact.id === editContactId) as
                  | ContactRecord
                  | undefined) ?? null
            : null;

    const handleReadinessAction = (action: string) => {
        if (['add_phone', 'add_email'].includes(action)) {
            setContactInfoOpen(true);
            return;
        }
        if (action === 'assign_lead') {
            setActiveTab('overview');
            setAddContactType('site_lead');
            return;
        }
        if (action === 'add_after_hours') {
            setActiveTab('overview');
            setAddContactType('emergency');
            return;
        }
        if (action === 'add_contact') {
            setActiveTab('overview');
            setAddContactType('manager');
            return;
        }
        if (action === 'add_emergency_plan') {
            if (typePlanSummary.has_published) {
                setActiveTab('emergency-plan');
                return;
            }
            setPlanBuilderFocus('assembly_point');
            setActiveTab('type-plan');
            setPlanBuilderOpen(true);
            return;
        }
        if (action === 'add_med_storage') {
            setPlanBuilderFocus('medication_storage');
            setActiveTab('type-plan');
            setPlanBuilderOpen(true);
            return;
        }
        if (action === 'review_hazards') {
            router.visit(`/sites/${site.id}/hazards`);
            return;
        }
        if (action === 'upload_doc') {
            router.visit(`/sites/${site.id}/documents`);
            return;
        }
        if (action === 'configure_rooms') {
            router.visit(`/sites/${site.id}/rooms`);
            return;
        }
        if (action === 'schedule_checklist') {
            router.visit(`/sites/${site.id}/checklists`);
            return;
        }
        if (action === 'configure_geofence') {
            setSiteGeofenceOpen(true);
        }
    };

    const openAddContactWithType = (type: SiteContactType) => {
        setActiveTab('overview');
        setAddContactType(type);
    };

    const openEditContact = (id: number | undefined) => {
        if (id) {
            setActiveTab('overview');
            setEditContactId(id);
        }
    };

    // Vendors & Credentials in-tab dialogs.
    type VendorDialogMode = 'add' | 'edit' | 'show' | 'delete' | null;
    type CredentialDialogMode =
        | 'add'
        | 'edit'
        | 'show'
        | 'delete'
        | 'remove-totp'
        | null;
    const [vendorDialog, setVendorDialog] = useState<{
        mode: VendorDialogMode;
        target: VendorRecord | null;
    }>({ mode: null, target: null });
    const [credentialDialog, setCredentialDialog] = useState<{
        mode: CredentialDialogMode;
        target: CredentialRecord | null;
    }>({ mode: null, target: null });

    const closeVendorDialog = () =>
        setVendorDialog({ mode: null, target: null });
    const closeCredentialDialog = () =>
        setCredentialDialog({ mode: null, target: null });

    // Vendors for this site, shaped for the credential dialog's "Linked vendor" picker.
    const credentialVendorOptions = vendors.map((v) => ({
        id: v.id,
        site_id: site.id,
        company_name: v.company_name,
        service_type: v.service_type,
    }));

    const handleDeleteNote = (noteId: number) => {
        router.delete(`/sites/${site.id}/notes/${noteId}`, {
            preserveScroll: true,
        });
    };

    const formatNoteDate = (iso: string | null) => {
        if (!iso) return '';
        const d = new Date(iso);
        return d.toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    // Fleet tab lazy load
    const [fleetLoaded, setFleetLoaded] = useState(!!fleet);
    const loadFleet = () => {
        if (!fleetLoaded) {
            router.reload({
                only: ['fleet'],
                onSuccess: () => setFleetLoaded(true),
            });
        }
    };

    const TypePlanTabIcon =
        site.type === 'head_office'
            ? Building2
            : site.type === 'facility'
              ? LayoutGrid
              : Home;

    const siteTabs: PageTabItem[] = [
        { value: 'overview', label: 'Overview', icon: LayoutGrid },
        {
            value: 'readiness',
            label: 'Readiness',
            icon: ShieldAlert,
            'data-test': 'site-readiness-tab',
            badge: readiness ? (
                <Badge variant="outline" className="ml-1 px-1.5 py-0 text-xs">
                    {readiness.score}%
                </Badge>
            ) : undefined,
        },
        {
            value: 'clients',
            label: `Clients (${clients.length})`,
            icon: Users,
        },
        {
            value: 'assets',
            label: `Assets (${assets.length})`,
            icon: Package,
        },
        {
            value: 'contacts',
            label: `Contacts (${contacts.length})`,
            icon: FileText,
        },
        {
            value: 'documents',
            label: `Documents (${documents.length})`,
            icon: FileText,
        },
        { value: 'calendar', label: 'Calendar', icon: Calendar },
        {
            value: 'inspections',
            label: 'Inspections',
            icon: ClipboardList,
            badge:
                inspectionsSummary.overdue_schedules > 0 ? (
                    <Badge variant="outline" className="ml-1 px-1.5 py-0 text-xs">
                        {inspectionsSummary.overdue_schedules}
                    </Badge>
                ) : undefined,
        },
        {
            value: 'drills',
            label: 'Drills',
            icon: Flame,
            badge:
                drillsSummary.drill_status !== 'compliant' ? (
                    <Badge variant="outline" className="ml-1 px-1.5 py-0 text-xs">
                        {drillsSummary.drill_status === 'overdue' ? 'Overdue' : 'Due'}
                    </Badge>
                ) : undefined,
        },
        { value: 'checklists', label: 'Checklists', icon: ClipboardCheck },
        { value: 'hazards', label: 'Hazards', icon: ShieldAlert },
        {
            value: 'first_aid',
            label: 'First Aid',
            icon: HeartPulse,
            badge:
                (page.props.firstAidOpenFollowupCount ?? 0) > 0 ? (
                    <Badge variant="outline" className="ml-1 px-1.5 py-0 text-xs">
                        {page.props.firstAidOpenFollowupCount}
                    </Badge>
                ) : undefined,
        },
        {
            value: 'risk_assessments',
            label: 'Risk Assessments',
            icon: Shield,
            badge:
                (page.props.riskAssessments?.length ?? 0) > 0 ? (
                    <Badge variant="outline" className="ml-1 px-1.5 py-0 text-xs">
                        {page.props.riskAssessments.length}
                    </Badge>
                ) : undefined,
        },
        {
            value: 'fleet',
            label: 'Fleet',
            icon: Car,
            badge:
                fleet && fleet.vehicles.length > 0 ? (
                    <Badge variant="outline" className="ml-1 px-1.5 py-0 text-xs">
                        {fleet.vehicles.length}
                    </Badge>
                ) : undefined,
        },
        { value: 'meal-planner', label: 'Meal Planner', icon: Utensils, overflowable: true },
        { value: 'financials', label: 'Financials', icon: DollarSign, overflowable: true },
        {
            value: 'vendors-credentials',
            label: 'Vendors & Credentials',
            icon: Truck,
            overflowable: true,
            hidden: !canSeeVendorsCredentials,
        },
        {
            value: 'hardware',
            label: 'Hardware',
            icon: Cpu,
            overflowable: true,
            badge:
                hardwareCount > 0 ? (
                    <Badge variant="outline" className="ml-1 px-1.5 py-0 text-xs">
                        {hardwareCount}
                    </Badge>
                ) : undefined,
        },
        {
            value: 'type-plan',
            label: typePlanSummary.tab_label,
            icon: TypePlanTabIcon,
            overflowable: true,
        },
        {
            value: 'emergency-plan',
            label: 'Emergency Plan',
            icon: ShieldAlert,
            overflowable: true,
        },
        {
            value: 'staff-requirements',
            label: 'Staff Requirements',
            icon: GraduationCap,
            overflowable: true,
            badge:
                staffRequirements.length > 0 ? (
                    <Badge variant="outline" className="ml-1 px-1.5 py-0 text-xs">
                        {staffRequirements.length}
                    </Badge>
                ) : undefined,
        },
        {
            value: 'shift-coverage',
            label: 'Shift Coverage',
            icon: Layers,
            overflowable: true,
            badge:
                coverageRequirements.length > 0 ? (
                    <Badge variant="outline" className="ml-1 px-1.5 py-0 text-xs">
                        {coverageRequirements.length}
                    </Badge>
                ) : undefined,
        },
        {
            value: 'service-contexts',
            label: 'Services',
            icon: Layers,
            overflowable: true,
            badge:
                (site.service_contexts ?? []).length > 0 ? (
                    <Badge variant="outline" className="ml-1 px-1.5 py-0 text-xs">
                        {(site.service_contexts ?? []).length}
                    </Badge>
                ) : undefined,
        },
    ];
    const publishedEmergencyPins =
        typePlanSummary.published?.pins.filter((pin) =>
            (typePlanSummary.emergency_pin_kinds ?? []).includes(pin.kind),
        ) ?? [];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
            ]}
        >
            <Head title={site.name} />

            <PageLayout
                hero={
                    <PageHero
                        icon={TypeIcon}
                        title={site.name}
                        meta={site.address ? [{ icon: MapPin, label: site.address }] : undefined}
                        badges={[
                            { icon: TypeIcon, label: site.display_type },
                            ...(site.is_high_risk
                                ? ([{ icon: AlertTriangle, label: 'High Risk', tone: 'warning' }] as const)
                                : []),
                            ...(site.is_high_needs
                                ? ([{ icon: AlertCircle, label: 'High Needs', tone: 'warning' }] as const)
                                : []),
                            {
                                label: site.is_active ? 'Active' : 'Inactive',
                                tone: site.is_active ? 'success' : 'default',
                            },
                            ...(readiness?.is_active_but_incomplete
                                ? [
                                      {
                                          icon: AlertCircle,
                                          label: 'Setup incomplete',
                                          tone: 'warning' as const,
                                          onClick: () => {
                                              setActiveTab('readiness');
                                              window.requestAnimationFrame(() => {
                                                  readinessRef.current?.scrollIntoView({
                                                      block: 'start',
                                                      behavior: 'smooth',
                                                  });
                                              });
                                          },
                                      },
                                  ]
                                : []),
                            ...(site.region ? [{ label: site.region }] : []),
                        ]}
                        stats={[
                            { label: 'Clients', value: clients.length },
                            { label: 'Assets', value: assets.length },
                            { label: 'Contacts', value: contacts.length },
                        ]}
                        actions={
                            can_edit ? (
                                <Button asChild size="sm" variant="outline">
                                    <Link href={`/sites/${site.id}/edit`}>Edit</Link>
                                </Button>
                            ) : null
                        }
                    />
                }
                tabs={
                    <PageTabs
                        value={activeTab}
                        onValueChange={(value) => {
                            setActiveTab(value);
                            if (value === 'fleet') loadFleet();
                        }}
                        items={siteTabs}
                    >
                    {/* Readiness Tab */}
                    <TabsContent value="readiness" className="space-y-4">
                        {readiness && (
                            <div ref={readinessRef}>
                                <SiteReadinessPanel
                                    readiness={readiness}
                                    onAction={handleReadinessAction}
                                />
                            </div>
                        )}
                    </TabsContent>

                    {/* Overview Tab */}
                    <TabsContent value="overview" className="space-y-4">
                        <Card className="border-border/60 shadow-sm">
                            <CardContent className="space-y-3 p-4">
                                <div className="grid gap-4 sm:grid-cols-4">
                                    <div>
                                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                            {occupancy.label}
                                        </p>
                                        <p className="mt-1 text-2xl font-semibold">
                                            {occupancy.percent}%
                                        </p>
                                    </div>
                                    <MiniOccupancyStat label={`Total ${occupancy.noun}`} value={occupancy.rooms_total} />
                                    <MiniOccupancyStat label="Occupied" value={occupancy.rooms_occupied} />
                                    <MiniOccupancyStat label="Vacant" value={occupancy.vacancies} />
                                </div>
                                <div
                                    className="h-2 w-full overflow-hidden rounded-full bg-muted"
                                    role="progressbar"
                                    aria-valuemin={0}
                                    aria-valuemax={100}
                                    aria-valuenow={occupancy.percent}
                                    aria-label={`${occupancy.label}: ${occupancy.percent}% occupied`}
                                >
                                    <div
                                        className="h-full rounded-full bg-status-success transition-all"
                                        style={{ width: `${Math.min(100, Math.max(0, occupancy.percent))}%` }}
                                    />
                                </div>
                            </CardContent>
                        </Card>

                        <div className="grid gap-4 lg:grid-cols-2">
                            {/* Contact Information */}
                            <Card
                                className="overflow-hidden border-border/60 shadow-sm transition-shadow hover:shadow-md"
                                data-test="site-contact-information-card"
                            >
                                <CardHeader className="flex flex-row items-center justify-between space-y-0 border-b border-border/60 bg-gradient-to-br from-primary/5 to-transparent">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <Phone className="h-4 w-4" />
                                        </span>
                                        Contact Information
                                    </CardTitle>
                                    {can_edit && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="h-8 gap-1.5 text-xs"
                                            onClick={() => setContactInfoOpen(true)}
                                        >
                                            <Pencil className="h-3 w-3" />
                                            Edit site line
                                        </Button>
                                    )}
                                </CardHeader>
                                <CardContent className="divide-y divide-border/40 p-0 text-sm">
                                    <ContactRow
                                        icon={Phone}
                                        label="Phone"
                                        value={site.phone}
                                        href={
                                            site.phone
                                                ? `tel:${site.phone}`
                                                : undefined
                                        }
                                        canFix={can_edit}
                                        onFix={() => setContactInfoOpen(true)}
                                        testId="site-contact-row-phone"
                                    />
                                    <ContactRow
                                        icon={Mail}
                                        label="Email"
                                        value={site.email}
                                        href={
                                            site.email
                                                ? `mailto:${site.email}`
                                                : undefined
                                        }
                                        canFix={can_edit}
                                        onFix={() => setContactInfoOpen(true)}
                                        testId="site-contact-row-email"
                                    />
                                    <DerivedContactRow
                                        icon={User}
                                        label="Site Lead"
                                        contact={site.site_lead_contact}
                                        emptyCta={can_edit ? 'Add site lead' : undefined}
                                        onAdd={() => openAddContactWithType('site_lead')}
                                        onEdit={() => openEditContact(site.site_lead_contact?.id)}
                                        testId="site-contact-row-site-lead"
                                    />
                                    <DerivedContactRow
                                        icon={Phone}
                                        label="Manager"
                                        contact={site.manager_contact}
                                        emptyCta={can_edit ? 'Add manager' : undefined}
                                        onAdd={() => openAddContactWithType('manager')}
                                        onEdit={() => openEditContact(site.manager_contact?.id)}
                                        testId="site-contact-row-manager"
                                    />
                                    <DerivedContactRow
                                        icon={Clock}
                                        label="After Hours"
                                        contact={site.after_hours_contact}
                                        emptyCta={can_edit ? 'Add after-hours contact' : undefined}
                                        onAdd={() => openAddContactWithType('emergency')}
                                        onEdit={() => openEditContact(site.after_hours_contact?.id)}
                                        testId="site-contact-row-after-hours"
                                    />
                                </CardContent>
                            </Card>

                            {/* Location */}
                            <Card className="overflow-hidden border-border/60 shadow-sm transition-shadow hover:shadow-md">
                                <CardHeader className="flex flex-row items-center justify-between space-y-0 border-b border-border/60 bg-gradient-to-br from-primary/5 to-transparent">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <MapPin className="h-4 w-4" />
                                        </span>
                                        Location
                                    </CardTitle>
                                    {can_edit && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="h-8 gap-1.5 text-xs"
                                            onClick={() => setLocationOpen(true)}
                                            data-test="site-edit-location-button"
                                        >
                                            <Pencil className="h-3 w-3" />
                                            Edit
                                        </Button>
                                    )}
                                </CardHeader>
                                <CardContent className="space-y-4 text-sm">
                                    <div className="rounded-lg border border-border/60 bg-muted/30 p-3">
                                        <div className="flex items-start gap-2.5">
                                            <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                            <div className="min-w-0 flex-1">
                                                <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                    Address
                                                </div>
                                                <div className="mt-1 leading-relaxed">
                                                    {site.address || (
                                                        <MissingFieldButton
                                                            label="Add address"
                                                            onClick={
                                                                can_edit
                                                                    ? () =>
                                                                          setLocationOpen(
                                                                              true,
                                                                          )
                                                                    : undefined
                                                            }
                                                        />
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {site.region && (
                                            <div className="rounded-lg border border-border/60 p-3">
                                                <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                    Region
                                                </div>
                                                <div className="mt-1 font-medium">
                                                    {site.region}
                                                </div>
                                            </div>
                                        )}
                                        {site.latitude && site.longitude && (
                                            <div className="rounded-lg border border-border/60 p-3">
                                                <div className="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                    <Navigation className="h-3 w-3" />
                                                    GPS
                                                </div>
                                                <div className="mt-1 font-mono text-xs">
                                                    {site.latitude},{' '}
                                                    {site.longitude}
                                                </div>
                                            </div>
                                        )}
                                    </div>

                                    {site.access_instructions && (
                                        <div className="rounded-lg border border-status-info/20 bg-status-info-bg/50 p-3">
                                            <div className="flex items-start gap-2.5">
                                                <Shield className="mt-0.5 h-4 w-4 shrink-0 text-status-info" />
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-xs font-medium tracking-wide text-status-info uppercase">
                                                        Access Instructions
                                                    </div>
                                                    <div className="mt-1 leading-relaxed whitespace-pre-wrap">
                                                        {
                                                            site.access_instructions
                                                        }
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    <SiteOverviewMapCard
                                        siteId={site.id}
                                        siteName={site.name}
                                        latitude={site.latitude}
                                        longitude={site.longitude}
                                        geofences={geofences}
                                        canManage={canManageGeofences}
                                        onEditGeofence={() => setSiteGeofenceOpen(true)}
                                    />
                                </CardContent>
                            </Card>

                            {/* Safety & Medication */}
                            <Card className="overflow-hidden border-border/60 shadow-sm transition-shadow hover:shadow-md">
                                <CardHeader className="flex flex-row items-center justify-between space-y-0 border-b border-border/60 bg-gradient-to-br from-primary/5 to-transparent">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <Shield className="h-4 w-4" />
                                        </span>
                                        Safety & Medication
                                    </CardTitle>
                                    {can_edit && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="h-8 gap-1.5 text-xs"
                                            onClick={() => setPlanBuilderOpen(true)}
                                        >
                                            <Pencil className="h-3 w-3" />
                                            Plan
                                        </Button>
                                    )}
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <div className="rounded-lg border border-border/60 p-3">
                                        <div className="flex items-start gap-2.5">
                                            <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center justify-between gap-2">
                                                    <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                        {typePlanSummary.tab_label}
                                                    </div>
                                                    <Badge variant="outline">
                                                        {planStatusLabel(typePlanSummary.status)}
                                                    </Badge>
                                                </div>
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => setActiveTab('type-plan')}
                                                    >
                                                        Open plan
                                                    </Button>
                                                    <Button asChild size="sm" variant="ghost">
                                                        <Link href={typePlanSummary.inventory_href}>
                                                            {typePlanSummary.inventory_label}
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="rounded-lg border border-border/60 p-3">
                                        <div className="flex items-start gap-2.5">
                                            <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0 text-status-warning" />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center justify-between gap-2">
                                                    <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                        Emergency Plan
                                                    </div>
                                                    <Badge variant={typePlanSummary.has_emergency_layer ? 'default' : 'outline'}>
                                                        {typePlanSummary.has_emergency_layer ? 'Ready' : 'Needs pins'}
                                                    </Badge>
                                                </div>
                                                {site.emergency_plan_location && (
                                                    <div className="mt-2 text-muted-foreground">
                                                        Legacy note: {site.emergency_plan_location}
                                                    </div>
                                                )}
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    {typePlanSummary.has_published ? (
                                                        <>
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => setActiveTab('emergency-plan')}
                                                            >
                                                                Open emergency plan
                                                            </Button>
                                                            {typePlanSummary.has_emergency_layer && (
                                                                <Button asChild size="sm" variant="ghost">
                                                                    <Link href={`/sites/${site.id}/emergency-plan.pdf?paper=a4`}>
                                                                        <Download className="mr-1 h-3.5 w-3.5" />
                                                                        Export A4
                                                                    </Link>
                                                                </Button>
                                                            )}
                                                        </>
                                                    ) : (
                                                        <MissingFieldButton
                                                            label="Build plan first"
                                                            onClick={
                                                                can_edit
                                                                    ? () => {
                                                                          setPlanBuilderFocus('assembly_point');
                                                                          setActiveTab('type-plan');
                                                                          setPlanBuilderOpen(true);
                                                                      }
                                                                    : undefined
                                                            }
                                                        />
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="rounded-lg border border-border/60 p-3">
                                        <div className="flex items-start gap-2.5">
                                            <Pill className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center justify-between gap-2">
                                                    <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                        Medication Storage
                                                    </div>
                                                    <Badge variant={typePlanSummary.has_medication_pin ? 'default' : 'outline'}>
                                                        {typePlanSummary.has_medication_pin ? 'Pinned' : 'Not pinned'}
                                                    </Badge>
                                                </div>
                                                {site.medication_storage_location && (
                                                    <div className="mt-2 text-muted-foreground">
                                                        Legacy note: {site.medication_storage_location}
                                                    </div>
                                                )}
                                                <div className="mt-2">
                                                    {typePlanSummary.has_medication_pin ? (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => setActiveTab('type-plan')}
                                                        >
                                                            Open plan
                                                        </Button>
                                                    ) : (
                                                        <MissingFieldButton
                                                            label="Add medication storage"
                                                            onClick={
                                                                can_edit
                                                                    ? () => {
                                                                          setPlanBuilderFocus('medication_storage');
                                                                          setActiveTab('type-plan');
                                                                          setPlanBuilderOpen(true);
                                                                      }
                                                                    : undefined
                                                            }
                                                        />
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {(site.is_high_risk ||
                                        site.is_high_needs) && (
                                        <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg/50 p-3">
                                            <div className="flex items-center gap-2 font-semibold text-status-warning">
                                                <AlertTriangle className="h-4 w-4" />
                                                Risk Information
                                            </div>
                                            {site.risk_notes && (
                                                <div className="mt-2 text-foreground/90">
                                                    {site.risk_notes}
                                                </div>
                                            )}
                                            {site.risk_review_date && (
                                                <div className="mt-2 flex items-center gap-1.5 text-xs text-status-warning">
                                                    <Calendar className="h-3 w-3" />
                                                    Review due:{' '}
                                                    {site.risk_review_date}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Notes */}
                            <Card className="overflow-hidden border-border/60 shadow-sm transition-shadow hover:shadow-md">
                                <CardHeader className="flex flex-row items-center justify-between space-y-0 border-b border-border/60 bg-gradient-to-br from-primary/5 to-transparent">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <StickyNote className="h-4 w-4" />
                                        </span>
                                        Notes
                                    </CardTitle>
                                    {can_edit && (
                                        <Button
                                            size="sm"
                                            onClick={() => setNoteOpen(true)}
                                        >
                                            <Plus className="mr-1 h-3.5 w-3.5" />
                                            New Note
                                        </Button>
                                    )}
                                </CardHeader>
                                <CardContent>
                                    {siteNotes.length === 0 ? (
                                        <div className="flex flex-col items-center justify-center gap-2 py-6 text-center">
                                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                                                <StickyNote className="h-5 w-5 text-muted-foreground" />
                                            </div>
                                            <p className="text-sm text-muted-foreground">
                                                No notes recorded
                                            </p>
                                        </div>
                                    ) : (
                                        <ul className="space-y-2">
                                            {siteNotes.map((n) => (
                                                <li
                                                    key={n.id}
                                                    className="rounded-lg border border-border/60 bg-muted/20 p-3"
                                                >
                                                    <div className="text-sm leading-relaxed whitespace-pre-wrap">
                                                        {n.body}
                                                    </div>
                                                    <div className="mt-2 flex items-center justify-between gap-2 text-xs text-muted-foreground">
                                                        <span>
                                                            {n.created_by?.name ?? 'Unknown'}
                                                            {' · '}
                                                            {formatNoteDate(n.created_at)}
                                                        </span>
                                                        {can_edit && (
                                                            <ConfirmAction
                                                                title="Delete note?"
                                                                description="Delete this site note?"
                                                                confirmLabel="Delete"
                                                                onConfirm={() =>
                                                                    handleDeleteNote(
                                                                        n.id,
                                                                    )
                                                                }
                                                            >
                                                                <button
                                                                    type="button"
                                                                    className="text-status-critical hover:underline"
                                                                >
                                                                    Delete
                                                                </button>
                                                            </ConfirmAction>
                                                        )}
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* Clients Tab */}
                    <TabsContent value="clients">
                        <ClientsTab
                            site={site}
                            clients={clients}
                            availableClients={availableClients}
                            summary={clientsSummary}
                            rooms={typeSpecificData.rooms ?? []}
                            can_edit={can_edit}
                        />
                    </TabsContent>

                    {/* Assets Tab */}
                    <TabsContent value="assets">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>Assets at this site</CardTitle>
                                {assetCan?.createAsset && (
                                    <Button
                                        asChild
                                        variant="secondary"
                                        size="sm"
                                    >
                                        <Link
                                            href={`/fleet-assets/assets/create?site_id=${site.id}`}
                                        >
                                            Add Asset
                                        </Link>
                                    </Button>
                                )}
                            </CardHeader>
                            <CardContent>
                                {assets.length === 0 ? (
                                    <div className="text-sm text-muted-foreground">
                                        No assets linked to this site yet.
                                    </div>
                                ) : (
                                    <div className="overflow-hidden rounded-xl border">
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/5">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Asset
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Owner
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Status
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Risk
                                                    </th>
                                                    <th className="px-4 py-3" />
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {assets.map((a) => (
                                                    <tr
                                                        key={a.id}
                                                        className="border-b last:border-b-0 hover:bg-muted/50"
                                                    >
                                                        <td className="px-4 py-3">
                                                            <div className="font-medium">
                                                                {a.name}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {[
                                                                    a.asset_tag,
                                                                    a.category,
                                                                    a.location,
                                                                ]
                                                                    .filter(
                                                                        Boolean,
                                                                    )
                                                                    .join(
                                                                        ' • ',
                                                                    ) || '—'}
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground">
                                                            <Badge
                                                                variant="outline"
                                                                className={
                                                                    a.owner
                                                                        .type ===
                                                                    'client'
                                                                        ? 'border-primary/30 text-primary/70'
                                                                        : 'border-border/30 text-muted-foreground'
                                                                }
                                                            >
                                                                {a.owner
                                                                    .type ===
                                                                'client'
                                                                    ? `Client: ${a.owner.label}`
                                                                    : 'Site-owned'}
                                                            </Badge>
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground">
                                                            {a.status}
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground">
                                                            {a.risk_level}
                                                        </td>
                                                        <td className="px-4 py-3 text-right">
                                                            <Link
                                                                href={`/assets/${a.id}`}
                                                                className="text-primary/70 hover:text-primary/70"
                                                            >
                                                                View
                                                            </Link>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Contacts Tab */}
                    <TabsContent value="contacts">
                        <ContactsTab
                            site={site}
                            contacts={contacts}
                            can_edit={can_edit}
                        />
                    </TabsContent>

                    {/* Documents Tab */}
                    <TabsContent value="documents">
                        <DocumentsTab
                            site={site}
                            documents={documents}
                            can_edit={can_edit}
                        />
                    </TabsContent>

                    {/* Calendar Tab */}
                    <TabsContent value="calendar">
                        <Suspense
                            fallback={
                                <div className="rounded-md border p-8 text-center text-sm text-muted-foreground">
                                    Loading calendar…
                                </div>
                            }
                        >
                            <SiteCalendarEmbed
                                context="profile"
                                scope="site"
                                site={{ id: site.id, name: site.name, type: site.type }}
                                canCreate={!!(canGlobal?.calendar?.create && can_edit)}
                                canManage={!!(canGlobal?.calendar?.manage && can_edit)}
                                canApprove={!!canGlobal?.calendar?.approve}
                            />
                        </Suspense>
                    </TabsContent>

                    {/* Inspections Tab */}
                    <TabsContent value="drills" className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <Card>
                                <CardContent className="p-4">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Drill compliance
                                    </p>
                                    <p
                                        className={`mt-2 text-lg font-semibold ${
                                            drillsSummary.drill_status === 'overdue'
                                                ? 'text-status-critical'
                                                : drillsSummary.drill_status === 'due_soon'
                                                  ? 'text-status-warning'
                                                  : 'text-status-success'
                                        }`}
                                    >
                                        {drillsSummary.drill_status === 'overdue'
                                            ? 'Overdue'
                                            : drillsSummary.drill_status === 'due_soon'
                                              ? 'Due soon'
                                              : 'Compliant'}
                                    </p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="p-4">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Last completed
                                    </p>
                                    <p className="mt-2 text-sm font-semibold">
                                        {drillsSummary.last_drill_at
                                            ? new Date(drillsSummary.last_drill_at).toLocaleDateString('en-NZ', {
                                                  day: '2-digit',
                                                  month: 'short',
                                                  year: 'numeric',
                                              })
                                            : 'None recorded'}
                                    </p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="p-4">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Next scheduled
                                    </p>
                                    <p className="mt-2 text-sm font-semibold">
                                        {drillsSummary.next_drill_at
                                            ? new Date(drillsSummary.next_drill_at).toLocaleDateString('en-NZ', {
                                                  day: '2-digit',
                                                  month: 'short',
                                                  year: 'numeric',
                                              })
                                            : 'None scheduled'}
                                    </p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="p-4">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Open findings
                                    </p>
                                    <p className="mt-2 text-2xl font-semibold">
                                        {drillsSummary.open_findings}
                                    </p>
                                </CardContent>
                            </Card>
                        </div>

                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between gap-3">
                                <CardTitle>Emergency drills</CardTitle>
                                <div className="flex flex-wrap gap-2">
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={`/health-safety/drills?site_id=${site.id}`}>Open register</Link>
                                    </Button>
                                    {can_edit && (
                                        <Button asChild size="sm">
                                            <Link href={`/health-safety/drills?site_id=${site.id}&schedule=1`}>
                                                Schedule drill
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm text-muted-foreground">
                                    {drillsSummary.scheduled_count > 0
                                        ? `${drillsSummary.scheduled_count} drill${drillsSummary.scheduled_count === 1 ? '' : 's'} scheduled. `
                                        : 'No upcoming drills scheduled. '}
                                    Compliance is graded on the most recent completed drill within 6 months (FENZ evacuation
                                    scheme). Full lifecycle, findings and evidence live on the Emergency Drills register.
                                </p>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="inspections" className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <Card>
                                <CardContent className="p-4">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Active
                                    </p>
                                    <p className="mt-2 text-2xl font-semibold">
                                        {inspectionsSummary.active_schedules}
                                    </p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="p-4">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Overdue
                                    </p>
                                    <p className="mt-2 text-2xl font-semibold text-status-critical">
                                        {inspectionsSummary.overdue_schedules}
                                    </p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="p-4">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Due soon
                                    </p>
                                    <p className="mt-2 text-2xl font-semibold text-status-warning">
                                        {inspectionsSummary.due_soon_schedules}
                                    </p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="p-4">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Failed results
                                    </p>
                                    <p className="mt-2 text-2xl font-semibold">
                                        {inspectionsSummary.failed_records}
                                    </p>
                                </CardContent>
                            </Card>
                        </div>

                        <div className="grid gap-4 xl:grid-cols-2">
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between gap-3">
                                    <CardTitle>Inspection Schedules</CardTitle>
                                    <div className="flex flex-wrap gap-2">
                                        <Button asChild variant="outline" size="sm">
                                            <Link href={`/sites/${site.id}/inspections`}>
                                                Open
                                            </Link>
                                        </Button>
                                        {can_edit && (
                                            <Button asChild size="sm">
                                                <Link href={`/sites/${site.id}/inspections`}>
                                                    Schedule
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    {inspectionsSummary.schedules.length === 0 ? (
                                        <div className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                                            No active inspection schedules.
                                        </div>
                                    ) : (
                                        <div className="divide-y rounded-md border">
                                            {inspectionsSummary.schedules.map((schedule) => (
                                                <div
                                                    key={schedule.id}
                                                    className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                                                >
                                                    <div className="min-w-0">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <p className="font-medium">
                                                                {schedule.title}
                                                            </p>
                                                            {schedule.is_overdue && (
                                                                <Badge className="border-status-critical/30 bg-status-critical-bg text-status-critical">
                                                                    Overdue
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        <p className="mt-1 text-sm text-muted-foreground">
                                                            {inspectionTypeLabel(schedule.inspection_type)}
                                                            {' · '}
                                                            {schedule.frequency}
                                                        </p>
                                                        {schedule.assigned_to_name && (
                                                            <p className="mt-1 text-xs text-muted-foreground">
                                                                {schedule.assigned_to_name}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div className="text-sm font-medium">
                                                        {formatDate(schedule.next_due_date)}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between gap-3">
                                    <CardTitle>Recent Results</CardTitle>
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={`/sites/${site.id}/inspections`}>
                                            Record
                                        </Link>
                                    </Button>
                                </CardHeader>
                                <CardContent>
                                    {inspectionsSummary.records.length === 0 ? (
                                        <div className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                                            No inspection records yet.
                                        </div>
                                    ) : (
                                        <div className="divide-y rounded-md border">
                                            {inspectionsSummary.records.map((record) => (
                                                <div
                                                    key={record.id}
                                                    className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                                                >
                                                    <div className="min-w-0">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <p className="font-medium">
                                                                {record.schedule_title ?? 'Inspection'}
                                                            </p>
                                                            {record.result && (
                                                                <Badge
                                                                    className={
                                                                        inspectionResultStyles[record.result]
                                                                    }
                                                                >
                                                                    {record.result.toUpperCase()}
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        {record.findings && (
                                                            <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                                                {record.findings}
                                                            </p>
                                                        )}
                                                        {record.completed_by_name && (
                                                            <p className="mt-1 text-xs text-muted-foreground">
                                                                {record.completed_by_name}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div className="text-sm font-medium">
                                                        {formatDate(record.completed_at ?? record.due_date)}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* Checklists Tab */}
                    <TabsContent value="checklists">
                        {checklistsData ? (
                            <ChecklistsWorkspace
                                scope={{
                                    mode: 'site',
                                    site: {
                                        id: site.id,
                                        name: site.name,
                                        type: site.type,
                                    },
                                    backHref: `/sites/${site.id}`,
                                }}
                                data={checklistsData}
                                embedded
                            />
                        ) : (
                            <Card>
                                <CardContent className="py-12 text-center text-sm text-muted-foreground">
                                    Loading…
                                </CardContent>
                            </Card>
                        )}
                    </TabsContent>

                    {/* Hazards Tab — compact embed of the scoped register; rows
                        deep-link to /sites/{id}/hazards (the full register + modal). */}
                    <TabsContent value="hazards">
                        {(() => {
                            const hazards = (page.props.siteHazards ?? []) as Array<{
                                id: number;
                                reference_number: string;
                                hazard_label: string;
                                description: string;
                                risk_rating: string;
                                severity: string;
                                status: string;
                                due_date: string | null;
                                overdue: boolean;
                                unassigned: boolean;
                            }>;
                            const openCount = (page.props.siteHazardsOpenCount ?? hazards.length) as number;
                            return (
                                <Card>
                                    <CardHeader className="flex flex-row items-start justify-between gap-3 space-y-0">
                                        <div className="flex items-start gap-3">
                                            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-primary">
                                                <ShieldAlert className="h-5 w-5" />
                                            </span>
                                            <div>
                                                <CardTitle className="text-base">
                                                    Hazards at this home <span className="font-normal text-muted-foreground">· {openCount} open</span>
                                                </CardTitle>
                                                <p className="mt-0.5 text-sm text-muted-foreground">Same register chrome, scoped to {site.name}. Click any row to open it in the register.</p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Button asChild variant="outline" size="sm">
                                                <Link href={`/sites/${site.id}/hazards`}>
                                                    <ExternalLink className="mr-1.5 h-4 w-4" /> View all
                                                </Link>
                                            </Button>
                                            <Button asChild size="sm">
                                                <Link href={`/sites/${site.id}/hazards?action=add`}>
                                                    <Plus className="mr-1.5 h-4 w-4" /> Log hazard
                                                </Link>
                                            </Button>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="space-y-2">
                                        {hazards.length === 0 ? (
                                            <div className="rounded-xl border border-dashed border-border px-4 py-10 text-center">
                                                <ShieldAlert className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                                                <p className="text-sm font-medium text-muted-foreground">No open hazards at {site.name}</p>
                                                <p className="mt-1 text-xs text-muted-foreground/70">Log a hazard to start the register for this home.</p>
                                            </div>
                                        ) : (
                                            hazards.map((h) => {
                                                const tone = RISK[h.risk_rating]?.tone ?? 'neutral';
                                                const dot = tone === 'critical' ? 'bg-status-critical' : tone === 'warning' ? 'bg-status-warning' : tone === 'success' ? 'bg-status-success' : 'bg-muted-foreground';
                                                return (
                                                    <Link
                                                        key={h.id}
                                                        href={`/sites/${site.id}/hazards?hazard=${h.id}`}
                                                        className="flex items-center gap-3 rounded-xl border border-border p-3 transition-colors hover:bg-muted/45 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                                    >
                                                        <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${dot}`} />
                                                        <div className="min-w-0 flex-1">
                                                            <p className="truncate text-sm font-semibold text-foreground">{h.hazard_label}</p>
                                                            <p className="truncate text-xs text-muted-foreground">
                                                                {h.reference_number} · {h.description}
                                                            </p>
                                                        </div>
                                                        <RiskChip rating={h.risk_rating} />
                                                        <StatusChip status={h.status} />
                                                        <span className={`hidden text-xs whitespace-nowrap sm:inline ${h.overdue ? 'font-bold text-status-critical' : 'text-muted-foreground'}`}>Due {fmtDueShort(h.due_date)}</span>
                                                        <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground/50" />
                                                    </Link>
                                                );
                                            })
                                        )}
                                    </CardContent>
                                </Card>
                            );
                        })()}
                        {((page.props.safeWorkProcedures ?? []) as ApplicableProcedure[]).length > 0 ? (
                            <div className="mt-4">
                                <ApplicableProceduresPanel
                                    procedures={(page.props.safeWorkProcedures ?? []) as ApplicableProcedure[]}
                                    subtitle={`Procedures that apply at ${site.name} (and organisation-wide)`}
                                />
                            </div>
                        ) : null}
                    </TabsContent>

                    <TabsContent value="first_aid">
                        {page.props.can?.view_hs_first_aid ? (
                            (() => {
                                const records = (page.props.firstAidRecords ?? []) as Array<{
                                    id: number;
                                    treatment_date: string | null;
                                    treated_person_name: string | null;
                                    treated_person_type: string | null;
                                    injury_illness_type: string | null;
                                    treatment_outcome: string | null;
                                    ambulance_called: boolean;
                                    incident_reported: boolean;
                                    first_aider_name: string | null;
                                    related_incident_id: number | null;
                                    open_followups_count: number;
                                }>;
                                const openFollowups = (page.props.firstAidOpenFollowupCount ?? 0) as number;
                                const humanise = (value: string | null) =>
                                    value
                                        ? value
                                              .replace(/_/g, ' ')
                                              .replace(/^\w/, (c) => c.toUpperCase())
                                        : '—';
                                return (
                                    <Card>
                                        <CardHeader className="flex flex-row items-start justify-between gap-3 space-y-0">
                                            <div className="flex items-start gap-3">
                                                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-primary">
                                                    <HeartPulse className="h-5 w-5" />
                                                </span>
                                                <div>
                                                    <CardTitle className="text-base">
                                                        First aid at this home{' '}
                                                        <span className="font-normal text-muted-foreground">
                                                            · {records.length} {records.length === 1 ? 'record' : 'records'} · {openFollowups} open {openFollowups === 1 ? 'follow-up' : 'follow-ups'}
                                                        </span>
                                                    </CardTitle>
                                                    <p className="mt-0.5 text-sm text-muted-foreground">Latest treatments logged for {site.name}. Click any row to open it in the register.</p>
                                                </div>
                                            </div>
                                            <Button asChild variant="outline" size="sm">
                                                <Link href={`/health-safety/first-aid?site_id=${site.id}`}>
                                                    <ExternalLink className="mr-1.5 h-4 w-4" /> View all
                                                </Link>
                                            </Button>
                                        </CardHeader>
                                        <CardContent className="space-y-2">
                                            {records.length === 0 ? (
                                                <div className="rounded-xl border border-dashed border-border px-4 py-10 text-center">
                                                    <HeartPulse className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                                                    <p className="text-sm font-medium text-muted-foreground">No first aid records for {site.name}</p>
                                                    <p className="mt-1 text-xs text-muted-foreground/70">Treatments logged in the register will appear here.</p>
                                                </div>
                                            ) : (
                                                records.map((r) => (
                                                    <Link
                                                        key={r.id}
                                                        href={`/health-safety/first-aid?site_id=${site.id}&record=${r.id}`}
                                                        className="flex items-center gap-3 rounded-xl border border-border p-3 transition-colors hover:bg-muted/45 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                                    >
                                                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent text-primary">
                                                            <HeartPulse className="h-4 w-4" />
                                                        </span>
                                                        <div className="min-w-0 flex-1">
                                                            <p className="truncate text-sm font-semibold text-foreground">
                                                                {r.treated_person_name || 'Unknown person'}
                                                                {r.treated_person_type ? (
                                                                    <span className="font-normal text-muted-foreground"> · {humanise(r.treated_person_type)}</span>
                                                                ) : null}
                                                            </p>
                                                            <p className="truncate text-xs text-muted-foreground">
                                                                {r.injury_illness_type || 'Treatment'} · {humanise(r.treatment_outcome)}
                                                                {r.treatment_date ? ` · ${formatDate(r.treatment_date)}` : ''}
                                                            </p>
                                                        </div>
                                                        {r.ambulance_called ? (
                                                            <Badge variant="outline" className="shrink-0 border-status-critical/40 text-status-critical">
                                                                Ambulance
                                                            </Badge>
                                                        ) : null}
                                                        {r.related_incident_id === null && (r.incident_reported || r.ambulance_called || r.treatment_outcome === 'sent_to_hospital') ? (
                                                            <Badge variant="outline" className="shrink-0 border-status-warning/40 text-status-warning">
                                                                Reportable
                                                            </Badge>
                                                        ) : null}
                                                        {r.open_followups_count > 0 ? (
                                                            <span className="hidden text-xs whitespace-nowrap text-muted-foreground sm:inline">
                                                                {r.open_followups_count} open
                                                            </span>
                                                        ) : null}
                                                        <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground/50" />
                                                    </Link>
                                                ))
                                            )}
                                        </CardContent>
                                    </Card>
                                );
                            })()
                        ) : (
                            <Card>
                                <CardContent className="py-10 text-center text-muted-foreground">
                                    You don&apos;t have permission to view first aid records.
                                </CardContent>
                            </Card>
                        )}
                    </TabsContent>

                    <TabsContent value="risk_assessments">
                        {page.props.can?.view_hs_risk_assessments ? (
                            <RaRegisterSection
                                assessments={(page.props.riskAssessments ?? []) as RaRow[]}
                                pickers={(page.props.ra_pickers ?? { sites: [], clients: [], events: [] }) as RaPickers}
                                canManage={Boolean(page.props.can?.manage_hs_risk_assessments)}
                                lockedAssessable={{ type: 'site', id: site.id, name: site.name }}
                            />
                        ) : (
                            <Card>
                                <CardContent className="py-10 text-center text-muted-foreground">
                                    You don&apos;t have permission to view risk assessments.
                                </CardContent>
                            </Card>
                        )}
                    </TabsContent>

                    {/* Fleet Tab */}
                    <TabsContent value="fleet" className="space-y-4">
                        {fleet ? (
                            (() => {
                                const fv = fleet.vehicles ?? [];
                                const fb = fleet.today_bookings ?? [];
                                const fo = fleet.active_outings ?? [];
                                const fs = fleet.stats ?? {
                                    trips_this_month: 0,
                                    distance_this_month: 0,
                                    fuel_cost_this_month: 0,
                                    incidents_this_month: 0,
                                };
                                const fc = fleet.compliance ?? [];

                                return (
                                    <>
                                        {/* Stats */}
                                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                            <Card className="border">
                                                <CardContent className="p-4 text-center">
                                                    <Route className="mx-auto mb-1 h-4 w-4 text-status-info" />
                                                    <div className="text-lg font-bold">
                                                        {fs.trips_this_month}
                                                    </div>
                                                    <div className="text-[10px] text-muted-foreground">
                                                        Trips this month
                                                    </div>
                                                </CardContent>
                                            </Card>
                                            <Card className="border">
                                                <CardContent className="p-4 text-center">
                                                    <MapPin className="mx-auto mb-1 h-4 w-4 text-primary" />
                                                    <div className="text-lg font-bold">
                                                        {fs.distance_this_month}{' '}
                                                        <span className="text-xs font-normal text-muted-foreground">
                                                            km
                                                        </span>
                                                    </div>
                                                    <div className="text-[10px] text-muted-foreground">
                                                        Distance this month
                                                    </div>
                                                </CardContent>
                                            </Card>
                                            <Card className="border">
                                                <CardContent className="p-4 text-center">
                                                    <Fuel className="mx-auto mb-1 h-4 w-4 text-status-warning" />
                                                    <div className="text-lg font-bold">
                                                        {formatCurrency(
                                                            fs.fuel_cost_this_month,
                                                        )}
                                                    </div>
                                                    <div className="text-[10px] text-muted-foreground">
                                                        Fuel this month
                                                    </div>
                                                </CardContent>
                                            </Card>
                                            <Card
                                                className={`border ${fs.incidents_this_month > 0 ? 'border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30' : ''}`}
                                            >
                                                <CardContent className="p-4 text-center">
                                                    <AlertTriangle
                                                        className={`mx-auto mb-1 h-4 w-4 ${fs.incidents_this_month > 0 ? 'text-status-critical' : 'text-muted-foreground'}`}
                                                    />
                                                    <div
                                                        className={`text-lg font-bold ${fs.incidents_this_month > 0 ? 'text-status-critical' : ''}`}
                                                    >
                                                        {
                                                            fs.incidents_this_month
                                                        }
                                                    </div>
                                                    <div className="text-[10px] text-muted-foreground">
                                                        Incidents this month
                                                    </div>
                                                </CardContent>
                                            </Card>
                                        </div>

                                        {/* Vehicles at Site */}
                                        <Card>
                                            <CardHeader className="pb-2">
                                                <CardTitle className="flex items-center gap-2 text-base">
                                                    <Car className="h-4 w-4" />{' '}
                                                    Vehicles at Site (
                                                    {fv.length})
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                {fv.length > 0 ? (
                                                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                        {fv.map((v) => (
                                                            <Link
                                                                key={v.id}
                                                                href={`/fleet-assets/vehicles/${v.id}`}
                                                                className="flex items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/50"
                                                            >
                                                                <span
                                                                    className={`h-2.5 w-2.5 shrink-0 rounded-full ${v.fleet_status === 'online' ? 'bg-status-success' : 'bg-muted'}`}
                                                                />
                                                                <div className="min-w-0 flex-1">
                                                                    <div className="truncate text-sm font-medium">
                                                                        {v.name}
                                                                    </div>
                                                                    <div className="text-[10px] text-muted-foreground">
                                                                        {
                                                                            v.asset_tag
                                                                        }
                                                                        {v.consent_blocked
                                                                            ? ' · Location hidden'
                                                                            : v.last_seen_at
                                                                              ? ` · ${new Date(v.last_seen_at).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })}`
                                                                              : ''}
                                                                    </div>
                                                                </div>
                                                                {v.speed_kph !=
                                                                    null &&
                                                                    v.speed_kph >
                                                                        0 && (
                                                                        <span className="shrink-0 text-xs text-muted-foreground">
                                                                            {
                                                                                v.speed_kph
                                                                            }{' '}
                                                                            km/h
                                                                        </span>
                                                                    )}
                                                            </Link>
                                                        ))}
                                                    </div>
                                                ) : (
                                                    <div className="py-6 text-center text-sm text-muted-foreground">
                                                        <Car className="mx-auto mb-2 h-8 w-8 opacity-30" />
                                                        No vehicles assigned to
                                                        this site
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>

                                        {/* Today's Activity */}
                                        {(fb.length > 0 || fo.length > 0) && (
                                            <Card>
                                                <CardHeader className="pb-2">
                                                    <CardTitle className="flex items-center gap-2 text-base">
                                                        <Calendar className="h-4 w-4" />{' '}
                                                        Today's Activity
                                                    </CardTitle>
                                                </CardHeader>
                                                <CardContent className="space-y-3">
                                                    {fb.length > 0 && (
                                                        <div>
                                                            <div className="mb-1.5 text-xs font-semibold text-muted-foreground uppercase">
                                                                Bookings
                                                            </div>
                                                            <div className="space-y-1.5">
                                                                {fb.map((b) => (
                                                                    <Link
                                                                        key={
                                                                            b.id
                                                                        }
                                                                        href={`/fleet-assets/bookings/${b.id}`}
                                                                        className="flex items-center justify-between rounded-md border px-3 py-2 text-sm transition-colors hover:bg-muted/50"
                                                                    >
                                                                        <div className="min-w-0">
                                                                            <span className="font-medium">
                                                                                {b
                                                                                    .vehicle
                                                                                    ?.name ??
                                                                                    'Vehicle'}
                                                                            </span>
                                                                            <span className="text-muted-foreground">
                                                                                {' '}
                                                                                —{' '}
                                                                                {b.purpose ??
                                                                                    'No purpose'}
                                                                            </span>
                                                                        </div>
                                                                        <Badge
                                                                            variant={
                                                                                b.status ===
                                                                                'checked_out'
                                                                                    ? 'default'
                                                                                    : 'outline'
                                                                            }
                                                                            className="ml-2 shrink-0 text-[10px]"
                                                                        >
                                                                            {b.status.replace(
                                                                                /_/g,
                                                                                ' ',
                                                                            )}
                                                                        </Badge>
                                                                    </Link>
                                                                ))}
                                                            </div>
                                                        </div>
                                                    )}
                                                    {fo.length > 0 && (
                                                        <div>
                                                            <div className="mb-1.5 text-xs font-semibold text-muted-foreground uppercase">
                                                                Outings
                                                            </div>
                                                            <div className="space-y-1.5">
                                                                {fo.map((o) => (
                                                                    <Link
                                                                        key={
                                                                            o.id
                                                                        }
                                                                        href={`/fleet-assets/outings/${o.id}`}
                                                                        className="flex items-center justify-between rounded-md border px-3 py-2 text-sm transition-colors hover:bg-muted/50"
                                                                    >
                                                                        <div className="min-w-0">
                                                                            <span className="font-medium">
                                                                                {
                                                                                    o.title
                                                                                }
                                                                            </span>
                                                                            <span className="text-muted-foreground">
                                                                                {' '}
                                                                                →{' '}
                                                                                {
                                                                                    o.destination
                                                                                }
                                                                            </span>
                                                                            {o.residents_count >
                                                                                0 && (
                                                                                <span className="text-muted-foreground">
                                                                                    {' '}
                                                                                    ·{' '}
                                                                                    {
                                                                                        o.residents_count
                                                                                    }{' '}
                                                                                    resident
                                                                                    {o.residents_count !==
                                                                                    1
                                                                                        ? 's'
                                                                                        : ''}
                                                                                </span>
                                                                            )}
                                                                        </div>
                                                                        <Badge
                                                                            variant={
                                                                                o.status ===
                                                                                'active'
                                                                                    ? 'default'
                                                                                    : 'outline'
                                                                            }
                                                                            className="ml-2 shrink-0 text-[10px]"
                                                                        >
                                                                            {
                                                                                o.status
                                                                            }
                                                                        </Badge>
                                                                    </Link>
                                                                ))}
                                                            </div>
                                                        </div>
                                                    )}
                                                </CardContent>
                                            </Card>
                                        )}

                                        {/* Compliance Warnings */}
                                        {fc.length > 0 && (
                                            <Card className="border-status-warning/30 dark:border-status-warning/30">
                                                <CardHeader className="pb-2">
                                                    <CardTitle className="flex items-center gap-2 text-base text-status-warning dark:text-status-warning">
                                                        <AlertTriangle className="h-4 w-4" />{' '}
                                                        Compliance Warnings
                                                    </CardTitle>
                                                </CardHeader>
                                                <CardContent>
                                                    <div className="space-y-2">
                                                        {fc.map((v) => (
                                                            <div
                                                                key={
                                                                    v.vehicle_id
                                                                }
                                                                className="rounded-md border border-status-warning/30 p-3 dark:border-status-warning/30"
                                                            >
                                                                <Link
                                                                    href={`/fleet-assets/vehicles/${v.vehicle_id}`}
                                                                    className="text-sm font-medium text-primary hover:underline"
                                                                >
                                                                    {
                                                                        v.vehicle_name
                                                                    }
                                                                </Link>
                                                                <div className="mt-1.5 flex flex-wrap gap-1.5">
                                                                    {v.items.map(
                                                                        (
                                                                            item,
                                                                            i,
                                                                        ) => (
                                                                            <Badge
                                                                                key={
                                                                                    i
                                                                                }
                                                                                variant={
                                                                                    item.status ===
                                                                                    'expired'
                                                                                        ? 'destructive'
                                                                                        : 'outline'
                                                                                }
                                                                                className={`text-[10px] ${item.status === 'critical' ? 'border-status-critical/30 text-status-critical dark:text-status-critical' : item.status === 'warning' ? 'border-status-warning/30 text-status-warning dark:text-status-warning' : ''}`}
                                                                            >
                                                                                {
                                                                                    item.type
                                                                                }
                                                                                :{' '}
                                                                                {item.status ===
                                                                                'expired'
                                                                                    ? 'EXPIRED'
                                                                                    : `${item.days_remaining}d remaining`}
                                                                            </Badge>
                                                                        ),
                                                                    )}
                                                                </div>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </CardContent>
                                            </Card>
                                        )}
                                    </>
                                );
                            })()
                        ) : (
                            <div className="py-12 text-center text-muted-foreground">
                                <Truck className="mx-auto mb-3 h-10 w-10 opacity-30" />
                                <p className="text-sm">Loading fleet data...</p>
                            </div>
                        )}
                    </TabsContent>

                    {/* Meal Planner Tab */}
                    <TabsContent value="meal-planner" className="space-y-4">
                        <Suspense fallback={<div className="rounded-md border p-8 text-center text-sm text-muted-foreground">Loading meal planner…</div>}>
                            <MealPlannerSubTabs site={{ id: site.id, name: site.name, type: site.type }} />
                        </Suspense>
                    </TabsContent>

                    {/* Vendors & Credentials Tab.
                        Panels are gated independently: a vendor-only user
                        sees just the Vendors panel; a credential-only user
                        sees just Credentials; both-permission users see the
                        side-by-side pair. The grid switches to a single
                        column when only one panel is visible. */}
                    {canSeeVendorsCredentials && (
                        <TabsContent value="vendors-credentials">
                            <div
                                className={
                                    canGlobal?.vendors?.view &&
                                    canGlobal?.credentials?.view
                                        ? 'grid gap-4 md:grid-cols-2'
                                        : 'grid gap-4 md:grid-cols-1'
                                }
                            >
                                {/* ── Vendors panel ─────────────────────── */}
                                {canGlobal?.vendors?.view && (
                                <Card>
                                    <CardHeader className="flex flex-row items-start justify-between gap-2">
                                        <div>
                                            <CardTitle className="text-base">
                                                Vendors ({vendors.length})
                                            </CardTitle>
                                            {canGlobal?.vendors?.view && (
                                                <Link
                                                    href={`/vendors?site_id=${site.id}&tab=vendors`}
                                                    className="text-xs text-muted-foreground hover:text-foreground hover:underline"
                                                >
                                                    Manage vendors →
                                                </Link>
                                            )}
                                        </div>
                                        {canGlobal?.vendors?.manage && (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setVendorDialog({
                                                        mode: 'add',
                                                        target: null,
                                                    })
                                                }
                                            >
                                                <Plus className="mr-1 h-3 w-3" />
                                                Add New
                                            </Button>
                                        )}
                                    </CardHeader>
                                    <CardContent>
                                        {vendors.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">
                                                No vendors registered for this
                                                site.
                                            </p>
                                        ) : (
                                            <div className="space-y-1">
                                                {vendors.map((v) => (
                                                    <div
                                                        key={v.id}
                                                        className="group flex items-center justify-between gap-2 rounded-md border border-transparent px-2 py-1.5 hover:border-border hover:bg-muted/40"
                                                    >
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex items-center gap-2">
                                                                <span className="truncate text-sm font-medium">
                                                                    {v.company_name}
                                                                </span>
                                                                {v.is_preferred && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="border-status-warning/30 text-xs text-status-warning"
                                                                    >
                                                                        Preferred
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            <div className="truncate text-xs text-muted-foreground">
                                                                {v.service_type}
                                                                {v.phone &&
                                                                    ` · ${v.phone}`}
                                                            </div>
                                                        </div>
                                                        <div className="flex items-center gap-0.5 opacity-60 group-hover:opacity-100">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                aria-label="Show vendor"
                                                                onClick={() =>
                                                                    setVendorDialog({
                                                                        mode: 'show',
                                                                        target: v as VendorRecord,
                                                                    })
                                                                }
                                                            >
                                                                <Eye className="h-3.5 w-3.5" />
                                                            </Button>
                                                            {canGlobal?.vendors?.manage && (
                                                                <>
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        aria-label="Edit vendor"
                                                                        onClick={() =>
                                                                            setVendorDialog({
                                                                                mode: 'edit',
                                                                                target: v as VendorRecord,
                                                                            })
                                                                        }
                                                                    >
                                                                        <Pencil className="h-3.5 w-3.5" />
                                                                    </Button>
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        aria-label="Delete vendor"
                                                                        className="text-status-critical hover:text-status-critical"
                                                                        onClick={() =>
                                                                            setVendorDialog({
                                                                                mode: 'delete',
                                                                                target: v as VendorRecord,
                                                                            })
                                                                        }
                                                                    >
                                                                        <Trash2 className="h-3.5 w-3.5" />
                                                                    </Button>
                                                                </>
                                                            )}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                                )}

                                {/* ── Credentials panel ─────────────────── */}
                                {canGlobal?.credentials?.view && (
                                <Card>
                                    <CardHeader className="flex flex-row items-start justify-between gap-2">
                                        <div>
                                            <CardTitle className="text-base">
                                                Credentials ({credentials.length})
                                            </CardTitle>
                                            {canGlobal?.credentials?.view && (
                                                <Link
                                                    href={`/vendors?site_id=${site.id}&tab=credentials`}
                                                    className="text-xs text-muted-foreground hover:text-foreground hover:underline"
                                                >
                                                    Manage credentials →
                                                </Link>
                                            )}
                                        </div>
                                        {canGlobal?.credentials?.manage && (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setCredentialDialog({
                                                        mode: 'add',
                                                        target: null,
                                                    })
                                                }
                                            >
                                                <Plus className="mr-1 h-3 w-3" />
                                                Add New
                                            </Button>
                                        )}
                                    </CardHeader>
                                    <CardContent>
                                        {credentials.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">
                                                No credentials stored for this
                                                site.
                                            </p>
                                        ) : (
                                            <div className="space-y-1">
                                                {credentials.map((c) => (
                                                    <div
                                                        key={c.id}
                                                        className="group flex items-center justify-between gap-2 rounded-md border border-transparent px-2 py-1.5 hover:border-border hover:bg-muted/40"
                                                    >
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex items-center gap-2">
                                                                <Lock className="h-3 w-3 text-muted-foreground" />
                                                                <span className="truncate text-sm font-medium">
                                                                    {c.label}
                                                                </span>
                                                                {c.has_totp && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="border-status-success/30 text-xs text-status-success"
                                                                        title="Authenticator configured"
                                                                    >
                                                                        <KeyRound className="mr-1 h-2.5 w-2.5" />
                                                                        OTP
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            <div className="truncate text-xs text-muted-foreground">
                                                                {c.username
                                                                    ? `${c.username} · `
                                                                    : ''}
                                                                {c.credential_type}
                                                            </div>
                                                        </div>
                                                        <div className="flex items-center gap-0.5 opacity-60 group-hover:opacity-100">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                aria-label="Show credential"
                                                                onClick={() =>
                                                                    setCredentialDialog({
                                                                        mode: 'show',
                                                                        target: c as CredentialRecord,
                                                                    })
                                                                }
                                                            >
                                                                <Eye className="h-3.5 w-3.5" />
                                                            </Button>
                                                            {canGlobal?.credentials?.manage && (
                                                                <>
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        aria-label="Edit credential"
                                                                        onClick={() =>
                                                                            setCredentialDialog({
                                                                                mode: 'edit',
                                                                                target: c as CredentialRecord,
                                                                            })
                                                                        }
                                                                    >
                                                                        <Pencil className="h-3.5 w-3.5" />
                                                                    </Button>
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        aria-label="Delete credential"
                                                                        className="text-status-critical hover:text-status-critical"
                                                                        onClick={() =>
                                                                            setCredentialDialog({
                                                                                mode: 'delete',
                                                                                target: c as CredentialRecord,
                                                                            })
                                                                        }
                                                                    >
                                                                        <Trash2 className="h-3.5 w-3.5" />
                                                                    </Button>
                                                                </>
                                                            )}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                                )}
                            </div>

                            {/* ── Dialog mounts ─────────────────────────── */}
                            {vendorDialog.mode === 'add' && (
                                <LazyDialog>
                                    <AddVendorDialog
                                        siteId={site.id}
                                        lockedSite={{ id: site.id, name: site.name, type: site.type }}
                                        isOpen
                                        onClose={closeVendorDialog}
                                    />
                                </LazyDialog>
                            )}
                            {vendorDialog.mode === 'edit' && (
                                <LazyDialog>
                                    <EditVendorDialog
                                        siteId={site.id}
                                        vendor={vendorDialog.target}
                                        lockedSite={{ id: site.id, name: site.name, type: site.type }}
                                        isOpen
                                        onClose={closeVendorDialog}
                                    />
                                </LazyDialog>
                            )}
                            {vendorDialog.mode === 'show' && (
                                <LazyDialog>
                                    <ShowVendorDialog
                                        vendor={vendorDialog.target}
                                        isOpen
                                        canManage={!!canGlobal?.vendors?.manage}
                                        onClose={closeVendorDialog}
                                        onEdit={() =>
                                            setVendorDialog((prev) => ({
                                                ...prev,
                                                mode: 'edit',
                                            }))
                                        }
                                        onDelete={() =>
                                            setVendorDialog((prev) => ({
                                                ...prev,
                                                mode: 'delete',
                                            }))
                                        }
                                    />
                                </LazyDialog>
                            )}
                            {vendorDialog.mode === 'delete' && (
                                <LazyDialog>
                                    <DeleteVendorDialog
                                        siteId={site.id}
                                        vendor={vendorDialog.target}
                                        isOpen
                                        onClose={closeVendorDialog}
                                    />
                                </LazyDialog>
                            )}

                            {credentialDialog.mode === 'add' && (
                                <LazyDialog>
                                    <AddCredentialDialog
                                        siteId={site.id}
                                        lockedSite={{ id: site.id, name: site.name, type: site.type }}
                                        vendors={credentialVendorOptions}
                                        typeOptions={credentialTypeOptions}
                                        isOpen
                                        onClose={closeCredentialDialog}
                                    />
                                </LazyDialog>
                            )}
                            {credentialDialog.mode === 'edit' && (
                                <LazyDialog>
                                    <EditCredentialDialog
                                        siteId={site.id}
                                        credential={credentialDialog.target}
                                        lockedSite={{ id: site.id, name: site.name, type: site.type }}
                                        vendors={credentialVendorOptions}
                                        typeOptions={credentialTypeOptions}
                                        isOpen
                                        onClose={closeCredentialDialog}
                                    />
                                </LazyDialog>
                            )}
                            {credentialDialog.mode === 'show' && (
                                <LazyDialog>
                                    <ShowCredentialDialog
                                        siteId={site.id}
                                        credential={credentialDialog.target}
                                        isOpen
                                        canManage={!!canGlobal?.credentials?.manage}
                                        canReveal={!!canGlobal?.credentials?.reveal}
                                        onClose={closeCredentialDialog}
                                        onEdit={() =>
                                            setCredentialDialog((prev) => ({
                                                ...prev,
                                                mode: 'edit',
                                            }))
                                        }
                                        onDelete={() =>
                                            setCredentialDialog((prev) => ({
                                                ...prev,
                                                mode: 'delete',
                                            }))
                                        }
                                        onRemoveTotp={() =>
                                            setCredentialDialog((prev) => ({
                                                ...prev,
                                                mode: 'remove-totp',
                                            }))
                                        }
                                        onHistory={() => {
                                            const id = credentialDialog.target?.id;
                                            closeCredentialDialog();
                                            if (id) {
                                                router.visit(
                                                    `/sites/${site.id}/credentials/${id}/audit`,
                                                );
                                            }
                                        }}
                                    />
                                </LazyDialog>
                            )}
                            {credentialDialog.mode === 'delete' && (
                                <LazyDialog>
                                    <DeleteCredentialDialog
                                        siteId={site.id}
                                        credential={credentialDialog.target}
                                        isOpen
                                        onClose={closeCredentialDialog}
                                    />
                                </LazyDialog>
                            )}
                            {credentialDialog.mode === 'remove-totp' && (
                                <LazyDialog>
                                    <RemoveTotpDialog
                                        siteId={site.id}
                                        credential={credentialDialog.target}
                                        isOpen
                                        onClose={closeCredentialDialog}
                                    />
                                </LazyDialog>
                            )}
                        </TabsContent>
                    )}

                    {/* Hardware Tab */}
                    <TabsContent value="hardware">
                        <Card>
                            <CardContent className="p-6">
                                <div className="mb-4 flex items-center justify-between">
                                    <div>
                                        <h3 className="flex items-center gap-2 font-medium">
                                            <Cpu className="h-4 w-4" />
                                            Location Hardware & Configuration
                                        </h3>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {hardwareCount} device
                                            {hardwareCount !== 1
                                                ? 's'
                                                : ''}{' '}
                                            registered
                                            {integrationStatus.length > 0 && (
                                                <>
                                                    {' '}
                                                    · {
                                                        integrationStatus.length
                                                    }{' '}
                                                    integration
                                                    {integrationStatus.length !==
                                                    1
                                                        ? 's'
                                                        : ''}{' '}
                                                    active
                                                </>
                                            )}
                                        </p>
                                    </div>
                                    <Button asChild>
                                        <Link
                                            href={`/sites/${site.id}/hardware`}
                                        >
                                            Manage Hardware
                                        </Link>
                                    </Button>
                                </div>
                                {integrationStatus.length > 0 && (
                                    <div className="mb-4 flex gap-2">
                                        {integrationStatus.map((i) => (
                                            <Badge
                                                key={i.provider}
                                                variant="outline"
                                                className={
                                                    i.status === 'hybrid'
                                                        ? 'border-status-success/30 text-status-success'
                                                        : i.status ===
                                                            'tenant_only'
                                                          ? 'border-status-info/30 text-status-info'
                                                          : 'border-border/30 text-muted-foreground'
                                                }
                                            >
                                                {i.provider
                                                    .charAt(0)
                                                    .toUpperCase() +
                                                    i.provider.slice(1)}
                                                : {i.status.replace('_', ' ')}
                                            </Badge>
                                        ))}
                                    </div>
                                )}
                                {hardwareCount === 0 &&
                                    integrationStatus.length === 0 && (
                                        <div className="py-8 text-center text-muted-foreground">
                                            <Cpu className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                            <p>
                                                No hardware registered for this
                                                site
                                            </p>
                                            <p className="mt-1 text-sm">
                                                Add devices manually or connect
                                                an integration to auto-discover
                                                hardware
                                            </p>
                                        </div>
                                    )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Type Plan Tab */}
                    <TabsContent value="type-plan" className="space-y-4">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between gap-3 space-y-0">
                                <div>
                                    <CardTitle className="flex items-center gap-2">
                                        <TypePlanTabIcon className="h-5 w-5 text-primary" />
                                        {typePlanSummary.tab_label}
                                    </CardTitle>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {typePlanSummary.has_published
                                            ? `Published version ${typePlanSummary.published?.version ?? 1}`
                                            : 'No published plan yet'}
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Badge variant="outline">
                                        {planStatusLabel(typePlanSummary.status)}
                                    </Badge>
                                    {can_edit && (
                                        <Button
                                            onClick={() => {
                                                setPlanBuilderMode('full');
                                                setPlanBuilderFocus(undefined);
                                                setPlanBuilderOpen(true);
                                            }}
                                        >
                                            {activePlan ? (
                                                <Pencil className="mr-2 h-4 w-4" />
                                            ) : (
                                                <Plus className="mr-2 h-4 w-4" />
                                            )}
                                            {activePlan ? 'Edit Plan' : 'Build Plan'}
                                        </Button>
                                    )}
                                </div>
                            </CardHeader>
                            <CardContent>
                                {activePlan ? (
                                    <PlanThumbnail
                                        layout={activePlan.layout}
                                        pins={activePlan.pins}
                                        taxonomy={typePlanSummary.taxonomy ?? null}
                                        className="min-h-[420px]"
                                    />
                                ) : (
                                    <div className="flex min-h-[320px] items-center justify-center rounded-md border border-dashed bg-muted/30 p-8 text-center">
                                        <div className="max-w-sm space-y-3">
                                            <TypePlanTabIcon className="mx-auto h-10 w-10 text-muted-foreground" />
                                            <p className="text-sm text-muted-foreground">
                                                No plan has been started for this site.
                                            </p>
                                            {can_edit && (
                                                <Button
                                                    onClick={() => {
                                                        setPlanBuilderMode('full');
                                                        setPlanBuilderFocus(undefined);
                                                        setPlanBuilderOpen(true);
                                                    }}
                                                >
                                                    <Plus className="mr-2 h-4 w-4" />
                                                    Build Plan
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
                            <TypeSpecificTab
                                site={site}
                                data={typeSpecificData}
                                clientsForRooms={clients.map((c) => ({
                                    id: c.id,
                                    first_name: c.first_name,
                                    last_name: c.last_name,
                                    preferred_name: c.preferred_name,
                                    status: c.status,
                                    profile_photo_url: c.profile_photo_url,
                                    room: c.room ?? null,
                                }))}
                                summary={roomsSummary}
                                can_edit={can_edit}
                            />
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Plan Layers</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <div className="flex items-center justify-between rounded-md border p-3">
                                        <span>Medication storage</span>
                                        <Badge variant={typePlanSummary.has_medication_pin ? 'default' : 'outline'}>
                                            {typePlanSummary.has_medication_pin ? 'Pinned' : 'Not pinned'}
                                        </Badge>
                                    </div>
                                    <div className="flex items-center justify-between rounded-md border p-3">
                                        <span>Emergency layer</span>
                                        <Badge variant={typePlanSummary.has_emergency_layer ? 'default' : 'outline'}>
                                            {typePlanSummary.has_emergency_layer ? 'Ready' : 'Needs pins'}
                                        </Badge>
                                    </div>
                                    <Button asChild variant="outline" className="w-full justify-start">
                                        <Link href={typePlanSummary.inventory_href}>
                                            {typePlanSummary.inventory_label}
                                        </Link>
                                    </Button>
                                    <Button asChild variant="outline" className="w-full justify-start">
                                        <Link href={`/sites/${site.id}/hardware`}>
                                            Manage hardware pins
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    <TabsContent value="emergency-plan" className="space-y-4">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between gap-3 space-y-0">
                                <div>
                                    <CardTitle className="flex items-center gap-2">
                                        <ShieldAlert className="h-5 w-5 text-primary" />
                                        Emergency Plan
                                    </CardTitle>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Exportable from the published {typePlanSummary.tab_label.toLowerCase()}.
                                    </p>
                                </div>
                                {typePlanSummary.has_emergency_layer && (
                                    <Button asChild>
                                        <Link href={`/sites/${site.id}/emergency-plan.pdf?paper=a4`}>
                                            <Download className="mr-2 h-4 w-4" />
                                            Export A4
                                        </Link>
                                    </Button>
                                )}
                            </CardHeader>
                            <CardContent>
                                {typePlanSummary.published ? (
                                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_280px]">
                                        <PlanThumbnail
                                            layout={typePlanSummary.published.layout}
                                            pins={publishedEmergencyPins}
                                            taxonomy={typePlanSummary.taxonomy ?? null}
                                            className="min-h-[420px]"
                                        />
                                        <div className="space-y-3">
                                            <div className="rounded-md border p-3">
                                                <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                    Status
                                                </div>
                                                <Badge className="mt-2" variant={typePlanSummary.has_emergency_layer ? 'default' : 'outline'}>
                                                    {typePlanSummary.has_emergency_layer
                                                        ? 'Ready to export'
                                                        : 'Needs assembly point and exit'}
                                                </Badge>
                                            </div>
                                            <Button asChild variant="outline" className="w-full justify-start">
                                                <Link href={`/sites/${site.id}/emergency-plan`}>
                                                    Open emergency plan page
                                                </Link>
                                            </Button>
                                            {can_edit && (
                                                <Button
                                                    variant="outline"
                                                    className="w-full justify-start"
                                                    onClick={() => {
                                                        setPlanBuilderMode('emergency');
                                                        setPlanBuilderFocus(typePlanSummary.has_emergency_layer ? undefined : 'assembly_point');
                                                        setPlanBuilderOpen(true);
                                                    }}
                                                >
                                                    Edit emergency plan
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                ) : (
                                    <div className="rounded-md border border-dashed bg-muted/30 p-8 text-center text-sm text-muted-foreground">
                                        Publish a site plan before creating the emergency export.
                                        {can_edit && (
                                            <div className="mt-4">
                                                <Button onClick={() => {
                                                    setActiveTab('type-plan');
                                                    setPlanBuilderMode('full');
                                                    setPlanBuilderFocus(undefined);
                                                    setPlanBuilderOpen(true);
                                                }}>
                                                    <Plus className="mr-2 h-4 w-4" />
                                                    Build Plan
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Staff Requirements Tab */}
                    <TabsContent value="staff-requirements">
                        <StaffRequirementsTab
                            site={site}
                            requirements={staffRequirements}
                            can_edit={can_edit}
                        />
                    </TabsContent>
                    <TabsContent value="shift-coverage">
                        <CoverageRequirementsTab
                            site={site}
                            requirements={coverageRequirements}
                            preview={coveragePreview}
                            clients={clients}
                            serviceContexts={site.service_contexts ?? []}
                            can_edit={can_edit}
                        />
                    </TabsContent>

                    {/* Service Contexts Tab */}
                    <TabsContent value="service-contexts">
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle className="flex items-center gap-2">
                                            <Layers className="h-5 w-5 text-primary" />
                                            Service Contexts
                                        </CardTitle>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Services delivered from this site
                                        </p>
                                    </div>
                                    <Link href="/settings/service-contexts">
                                        <Button variant="outline" size="sm">
                                            Manage in Settings
                                        </Button>
                                    </Link>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {(site.service_contexts ?? []).length === 0 ? (
                                    <div className="py-8 text-center text-muted-foreground">
                                        <Layers className="mx-auto mb-2 h-10 w-10 opacity-30" />
                                        <p className="font-medium">
                                            No service contexts linked
                                        </p>
                                        <p className="mt-1 text-sm">
                                            Link service contexts to this site
                                            in Settings → Service Contexts
                                        </p>
                                    </div>
                                ) : (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {(site.service_contexts ?? []).map(
                                            (ctx: any) => (
                                                <div
                                                    key={ctx.id}
                                                    className="space-y-2 rounded-lg border border-l-4 border-l-violet-500 p-4"
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-medium">
                                                            {ctx.name}
                                                        </span>
                                                        {ctx.is_active ? (
                                                            <Badge className="bg-status-success-bg text-xs text-status-success">
                                                                Active
                                                            </Badge>
                                                        ) : (
                                                            <Badge
                                                                variant="outline"
                                                                className="text-xs"
                                                            >
                                                                Inactive
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    {ctx.type && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="text-xs"
                                                        >
                                                            {ctx.type.replace(
                                                                /_/g,
                                                                ' ',
                                                            )}
                                                        </Badge>
                                                    )}
                                                    {ctx.description && (
                                                        <p className="line-clamp-2 text-sm text-muted-foreground">
                                                            {ctx.description}
                                                        </p>
                                                    )}
                                                </div>
                                            ),
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="financials">
                        <SiteLedgerPanel
                            site={site}
                            ledgerData={houseLedger}
                        />
                    </TabsContent>
                    </PageTabs>
                }
            />

            <SiteTypePlanBuilderDialog
                site={site}
                typePlan={typePlanSummary}
                open={planBuilderOpen}
                onOpenChange={(open) => {
                    setPlanBuilderOpen(open);
                    if (!open) {
                        setPlanBuilderFocus(undefined);
                        setPlanBuilderMode('full');
                    }
                }}
                focusTool={planBuilderFocus}
                mode={planBuilderMode}
            />
            <EditSiteLineDialog
                siteId={site.id}
                isOpen={contactInfoOpen}
                onClose={() => setContactInfoOpen(false)}
                initial={{
                    phone: site.phone ?? '',
                    email: site.email ?? '',
                }}
            />
            <EditLocationDialog
                siteId={site.id}
                siteName={site.name}
                isOpen={locationOpen}
                onClose={() => setLocationOpen(false)}
                initial={{
                    address_line_1: site.address_line_1 ?? '',
                    address_line_2: site.address_line_2 ?? '',
                    suburb: site.suburb ?? '',
                    city: site.city ?? '',
                    postcode: site.postcode ?? '',
                    country: site.country ?? '',
                    region: site.region ?? '',
                    latitude: site.latitude ?? '',
                    longitude: site.longitude ?? '',
                    access_instructions: site.access_instructions ?? '',
                }}
                geofences={geofences}
                onOpenGeofence={
                    canManageGeofences
                        ? () => setSiteGeofenceOpen(true)
                        : undefined
                }
            />
            <SiteGeofenceDialog
                siteId={site.id}
                siteName={site.name}
                siteLat={site.latitude}
                siteLng={site.longitude}
                existing={siteGeofence}
                assets={assets}
                isOpen={siteGeofenceOpen}
                onClose={() => setSiteGeofenceOpen(false)}
                onOpenLocation={() => {
                    setSiteGeofenceOpen(false);
                    setLocationOpen(true);
                }}
            />
            <EditSafetyDialog
                siteId={site.id}
                isOpen={safetyOpen}
                onClose={() => setSafetyOpen(false)}
                initial={{
                    emergency_plan_location: site.emergency_plan_location ?? '',
                    medication_storage_location: site.medication_storage_location ?? '',
                }}
            />
            <AddSiteNoteDialog
                siteId={site.id}
                isOpen={noteOpen}
                onClose={() => setNoteOpen(false)}
            />
            {addContactType && (
                <LazyDialog>
                    <AddContactDialog
                        siteId={site.id}
                        isOpen
                        type={addContactType}
                        lockType
                        onClose={() => setAddContactType(null)}
                    />
                </LazyDialog>
            )}
            {overviewEditContact && (
                <LazyDialog>
                    <EditContactDialog
                        siteId={site.id}
                        contact={overviewEditContact}
                        isOpen
                        onClose={() => setEditContactId(null)}
                    />
                </LazyDialog>
            )}
        </AppLayout>
    );
}

// Sub-components for cleaner code
type ContactDialogMode = 'add' | 'edit' | 'show' | 'delete' | null;

function ContactsTab({
    site,
    contacts,
    can_edit,
}: {
    site: Site;
    contacts: Contact[];
    can_edit: boolean;
}) {
    const [dialog, setDialog] = useState<{
        mode: ContactDialogMode;
        target: ContactRecord | null;
    }>({ mode: null, target: null });

    const closeDialog = () => setDialog({ mode: null, target: null });

    return (
        <>
            <Card>
                <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0">
                    <div>
                        <CardTitle className="text-base">
                            Site contacts ({contacts.length})
                        </CardTitle>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Key people for this site — open a card to view full
                            details.
                        </p>
                    </div>
                    {can_edit && (
                        <Button
                            size="sm"
                            onClick={() =>
                                setDialog({ mode: 'add', target: null })
                            }
                        >
                            <Plus className="mr-1 h-4 w-4" />
                            New contact
                        </Button>
                    )}
                </CardHeader>
                <CardContent>
                    {contacts.length === 0 ? (
                        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-10 text-center">
                            <div className="rounded-full bg-muted/40 p-3">
                                <User className="h-6 w-6 text-muted-foreground" />
                            </div>
                            <p className="mt-3 text-sm font-medium">
                                No contacts yet
                            </p>
                            <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                Add team leads, emergency contacts, family /
                                whānau, and other key people for this site.
                            </p>
                            {can_edit && (
                                <Button
                                    size="sm"
                                    className="mt-4"
                                    onClick={() =>
                                        setDialog({
                                            mode: 'add',
                                            target: null,
                                        })
                                    }
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    Add first contact
                                </Button>
                            )}
                        </div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {contacts.map((c) => (
                                <ContactCard
                                    key={c.id}
                                    contact={c}
                                    canEdit={can_edit}
                                    onShow={() =>
                                        setDialog({
                                            mode: 'show',
                                            target: c as ContactRecord,
                                        })
                                    }
                                    onEdit={() =>
                                        setDialog({
                                            mode: 'edit',
                                            target: c as ContactRecord,
                                        })
                                    }
                                    onDelete={() =>
                                        setDialog({
                                            mode: 'delete',
                                            target: c as ContactRecord,
                                        })
                                    }
                                />
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            {dialog.mode === 'add' && (
                <LazyDialog>
                    <AddContactDialog
                        siteId={site.id}
                        isOpen
                        onClose={closeDialog}
                    />
                </LazyDialog>
            )}
            {dialog.mode === 'edit' && (
                <LazyDialog>
                    <EditContactDialog
                        siteId={site.id}
                        contact={dialog.target}
                        isOpen
                        onClose={closeDialog}
                    />
                </LazyDialog>
            )}
            {dialog.mode === 'show' && (
                <LazyDialog>
                    <ShowContactDialog
                        contact={dialog.target}
                        isOpen
                        canManage={can_edit}
                        onClose={closeDialog}
                        onEdit={() =>
                            setDialog((prev) => ({ ...prev, mode: 'edit' }))
                        }
                        onDelete={() =>
                            setDialog((prev) => ({ ...prev, mode: 'delete' }))
                        }
                    />
                </LazyDialog>
            )}
            {dialog.mode === 'delete' && (
                <LazyDialog>
                    <DeleteContactDialog
                        siteId={site.id}
                        contact={dialog.target}
                        isOpen
                        onClose={closeDialog}
                    />
                </LazyDialog>
            )}
        </>
    );
}

function ContactCard({
    contact,
    canEdit,
    onShow,
    onEdit,
    onDelete,
}: {
    contact: Contact;
    canEdit: boolean;
    onShow: () => void;
    onEdit: () => void;
    onDelete: () => void;
}) {
    const type = getContactType(contact.type);
    const Icon = type.icon;
    return (
        <div
            role="button"
            tabIndex={0}
            onClick={onShow}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onShow();
                }
            }}
            className="group relative flex h-full flex-col gap-3 rounded-2xl border bg-card/40 p-4 text-left transition-all hover:border-primary/50 hover:bg-card hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
            <div className="flex items-start gap-3">
                <span className="shrink-0 rounded-xl border bg-background/60 p-2">
                    <Icon className={`h-5 w-5 ${type.accent}`} />
                </span>
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <p className="truncate text-sm font-semibold">
                            {contact.name}
                        </p>
                        {contact.is_primary && (
                            <Badge
                                variant="outline"
                                className="border-status-success/30 text-[10px] text-status-success"
                            >
                                Primary
                            </Badge>
                        )}
                    </div>
                    <p className="truncate text-xs text-muted-foreground">
                        {type.label}
                        {contact.role ? ` · ${contact.role}` : ''}
                    </p>
                </div>
            </div>

            <div className="space-y-1 text-xs text-muted-foreground">
                {contact.phone && (
                    <div className="flex items-center gap-2">
                        <Phone className="h-3 w-3 shrink-0" />
                        <span className="truncate">{contact.phone}</span>
                    </div>
                )}
                {contact.email && (
                    <div className="flex items-center gap-2">
                        <Mail className="h-3 w-3 shrink-0" />
                        <span className="truncate">{contact.email}</span>
                    </div>
                )}
                {!contact.phone && !contact.email && (
                    <p className="italic">No contact details</p>
                )}
            </div>

            {contact.notes && (
                <p className="line-clamp-2 text-xs text-muted-foreground">
                    {contact.notes}
                </p>
            )}

            {canEdit && (
                <div
                    className="mt-auto flex items-center justify-end gap-1 opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100"
                    onClick={(e) => e.stopPropagation()}
                >
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label="Edit contact"
                        onClick={onEdit}
                    >
                        <Pencil className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label="Delete contact"
                        className="text-status-critical hover:text-status-critical"
                        onClick={onDelete}
                    >
                        <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                </div>
            )}
        </div>
    );
}

type ClientDialogMode = 'add' | 'show' | 'unlink' | 'assign-room' | null;

function ClientsTab({
    site,
    clients,
    availableClients,
    summary,
    rooms,
    can_edit,
}: {
    site: Site;
    clients: ClientLite[];
    availableClients: AvailableClient[];
    summary?: ClientsSummary;
    rooms: NonNullable<TypeSpecificData['rooms']>;
    can_edit: boolean;
}) {
    const [dialog, setDialog] = useState<{
        mode: ClientDialogMode;
        target: ClientRecord | null;
    }>({ mode: null, target: null });

    const closeDialog = () => setDialog({ mode: null, target: null });

    const isHouse = site.type === 'house';
    const canAssignRoom = isHouse && can_edit && rooms.length > 0;

    const stats = summary ?? {
        total: clients.length,
        active: clients.filter((c) => c.status === 'active').length,
        onboarding: clients.filter((c) => c.status === 'onboarding').length,
        inactive: clients.filter((c) => c.status === 'inactive').length,
        high_risk: clients.filter((c) => c.risk_level === 'high').length,
        safeguarding: clients.filter((c) => c.safeguarding_flag).length,
    };

    return (
        <>
            <Card>
                <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0">
                    <div>
                        <CardTitle className="text-base">
                            Clients at this site ({stats.total})
                        </CardTitle>
                        <p className="mt-1 text-xs text-muted-foreground">
                            People supported here — open a card for a quick
                            overview or jump to the full profile.
                        </p>
                    </div>
                    {can_edit && (
                        <Button
                            size="sm"
                            onClick={() =>
                                setDialog({ mode: 'add', target: null })
                            }
                        >
                            <Plus className="mr-1 h-4 w-4" />
                            Add client
                        </Button>
                    )}
                </CardHeader>
                <CardContent className="space-y-4">
                    {stats.total > 0 && (
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                            <StatChip label="Total" value={stats.total} />
                            <StatChip
                                label="Active"
                                value={stats.active}
                                tone="success"
                            />
                            <StatChip
                                label="Onboarding"
                                value={stats.onboarding}
                                tone="warning"
                            />
                            <StatChip
                                label="High risk"
                                value={stats.high_risk}
                                tone={
                                    stats.high_risk > 0 ? 'critical' : 'muted'
                                }
                            />
                            <StatChip
                                label="Safeguarding"
                                value={stats.safeguarding}
                                tone={
                                    stats.safeguarding > 0
                                        ? 'critical'
                                        : 'muted'
                                }
                            />
                        </div>
                    )}

                    {clients.length === 0 ? (
                        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-10 text-center">
                            <div className="rounded-full bg-muted/40 p-3">
                                <User className="h-6 w-6 text-muted-foreground" />
                            </div>
                            <p className="mt-3 text-sm font-medium">
                                No clients linked yet
                            </p>
                            <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                Link an existing client from your organisation
                                or quick-create a new one for this site.
                            </p>
                            {can_edit && (
                                <Button
                                    size="sm"
                                    className="mt-4"
                                    onClick={() =>
                                        setDialog({
                                            mode: 'add',
                                            target: null,
                                        })
                                    }
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    Add first client
                                </Button>
                            )}
                        </div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {clients.map((c) => (
                                <ClientCard
                                    key={c.id}
                                    client={c}
                                    canEdit={can_edit}
                                    canAssignRoom={canAssignRoom}
                                    onShow={() =>
                                        setDialog({
                                            mode: 'show',
                                            target: c as ClientRecord,
                                        })
                                    }
                                    onUnlink={() =>
                                        setDialog({
                                            mode: 'unlink',
                                            target: c as ClientRecord,
                                        })
                                    }
                                    onAssignRoom={() =>
                                        setDialog({
                                            mode: 'assign-room',
                                            target: c as ClientRecord,
                                        })
                                    }
                                />
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            {dialog.mode === 'add' && (
                <LazyDialog>
                    <AddClientDialog
                        siteId={site.id}
                        availableClients={availableClients}
                        isOpen
                        onClose={closeDialog}
                    />
                </LazyDialog>
            )}
            {dialog.mode === 'show' && (
                <LazyDialog>
                    <ShowClientDialog
                        client={dialog.target}
                        siteId={site.id}
                        canManage={can_edit}
                        canAssignRoom={canAssignRoom}
                        isOpen
                        onClose={closeDialog}
                        onUnlink={() =>
                            setDialog((prev) => ({ ...prev, mode: 'unlink' }))
                        }
                        onAssignRoom={() =>
                            setDialog((prev) => ({
                                ...prev,
                                mode: 'assign-room',
                            }))
                        }
                    />
                </LazyDialog>
            )}
            {dialog.mode === 'unlink' && (
                <LazyDialog>
                    <UnlinkClientDialog
                        siteId={site.id}
                        client={dialog.target}
                        isOpen
                        onClose={closeDialog}
                    />
                </LazyDialog>
            )}
            {dialog.mode === 'assign-room' && (
                <LazyDialog>
                    <AssignRoomToClientDialog
                        siteId={site.id}
                        client={dialog.target}
                        rooms={rooms as RoomRecord[]}
                        isOpen
                        onClose={closeDialog}
                    />
                </LazyDialog>
            )}
        </>
    );
}

function StatChip({
    label,
    value,
    tone = 'muted',
}: {
    label: string;
    value: number;
    tone?: 'muted' | 'success' | 'warning' | 'critical';
}) {
    const toneCls = {
        muted: 'border-border bg-muted/30 text-foreground',
        success:
            'border-status-success/30 bg-status-success-bg text-status-success',
        warning:
            'border-status-warning/30 bg-status-warning-bg text-status-warning',
        critical:
            'border-status-critical/30 bg-status-critical-bg text-status-critical',
    }[tone];
    return (
        <div className={`rounded-xl border px-3 py-2 ${toneCls}`}>
            <p className="text-xs opacity-80">{label}</p>
            <p className="text-lg font-semibold leading-none">{value}</p>
        </div>
    );
}

function ClientCard({
    client,
    canEdit,
    onShow,
    onUnlink,
    onAssignRoom,
    canAssignRoom,
}: {
    client: ClientLite;
    canEdit: boolean;
    onShow: () => void;
    onUnlink: () => void;
    onAssignRoom?: () => void;
    canAssignRoom: boolean;
}) {
    const status = getClientStatusStyle(client.status);
    const risk = getClientRiskStyle(client.risk_level);
    const displayName = getClientDisplayName(client);
    const profileUrl = `/clients/${client.id}`;

    return (
        <div className="group relative flex h-full flex-col gap-3 rounded-2xl border bg-card/40 p-4 transition-all hover:border-primary/50 hover:bg-card hover:shadow-md">
            <div className="flex items-start gap-3">
                <Avatar
                    className={`size-12 ring-2 ring-offset-2 ring-offset-background ${status.ring}`}
                >
                    {client.profile_photo_url && (
                        <AvatarImage
                            src={client.profile_photo_url}
                            alt={displayName}
                        />
                    )}
                    <AvatarFallback>
                        {getClientInitials(client)}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <p className="truncate text-sm font-semibold">
                            {displayName}
                        </p>
                        {client.safeguarding_flag && (
                            <span
                                title="Safeguarding flag"
                                className="text-status-critical"
                            >
                                <Shield className="h-3.5 w-3.5" />
                            </span>
                        )}
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-1">
                        <Badge
                            variant="outline"
                            className={`text-[10px] ${status.cls}`}
                        >
                            {status.label}
                        </Badge>
                        {client.risk_level && (
                            <Badge
                                variant="outline"
                                className={`text-[10px] ${risk.cls}`}
                            >
                                {risk.label}
                            </Badge>
                        )}
                    </div>
                </div>
                {canEdit && (
                    <button
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            onUnlink();
                        }}
                        aria-label="Unlink client from site"
                        title="Unlink from site"
                        className="rounded-md p-1 text-muted-foreground opacity-0 transition-opacity hover:bg-muted hover:text-status-critical group-hover:opacity-100 group-focus-within:opacity-100"
                    >
                        <Link2 className="h-3.5 w-3.5" />
                    </button>
                )}
            </div>

            <div className="space-y-1 text-xs text-muted-foreground">
                {client.age != null && (
                    <div className="flex items-center gap-2">
                        <Cake className="h-3 w-3 shrink-0" />
                        <span>{client.age} yrs</span>
                    </div>
                )}
                <div className="flex items-center gap-2">
                    <DoorOpen className="h-3 w-3 shrink-0" />
                    {client.room ? (
                        <span className="truncate">
                            Room: {client.room.name}
                            {canAssignRoom && onAssignRoom && (
                                <button
                                    type="button"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        onAssignRoom();
                                    }}
                                    className="ml-2 text-primary underline-offset-2 hover:underline"
                                >
                                    Change
                                </button>
                            )}
                        </span>
                    ) : canAssignRoom && onAssignRoom ? (
                        <button
                            type="button"
                            onClick={(e) => {
                                e.stopPropagation();
                                onAssignRoom();
                            }}
                            className="text-primary underline-offset-2 hover:underline"
                        >
                            Assign room
                        </button>
                    ) : (
                        <span className="italic">No room</span>
                    )}
                </div>
                {client.key_worker?.name && (
                    <div className="flex items-center gap-2">
                        <UserCog className="h-3 w-3 shrink-0" />
                        <span className="truncate">
                            Key worker: {client.key_worker.name}
                        </span>
                    </div>
                )}
                {client.service_context?.name && (
                    <div className="flex items-center gap-2">
                        <Activity className="h-3 w-3 shrink-0" />
                        <span className="truncate">
                            {client.service_context.name}
                        </span>
                    </div>
                )}
            </div>

            <div className="mt-auto flex items-center justify-end gap-2 pt-1">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onShow}
                >
                    View
                </Button>
                <Button asChild size="sm">
                    <Link href={profileUrl}>View full profile</Link>
                </Button>
            </div>
        </div>
    );
}

function DocumentsTab({
    site,
    documents,
    can_edit,
}: {
    site: Site;
    documents: Doc[];
    can_edit: boolean;
}) {
    const groupedDocuments = useMemo(() => {
        return documents.reduce<Record<string, Doc[]>>((groups, document) => {
            const folder = document.folder || 'Unfiled';
            groups[folder] = [...(groups[folder] ?? []), document];

            return groups;
        }, {});
    }, [documents]);

    const folderNames = Object.keys(groupedDocuments).sort((a, b) => {
        if (a === 'Unfiled') return -1;
        if (b === 'Unfiled') return 1;

        return a.localeCompare(b);
    });

    const expiredCount = documents.filter((document) =>
        isSiteDocumentExpired(document.expiry_date),
    ).length;
    const expiringCount = documents.filter((document) =>
        isSiteDocumentExpiringSoon(document.expiry_date),
    ).length;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div className="font-medium">
                        {documents.length}{' '}
                        {documents.length === 1 ? 'document' : 'documents'}
                    </div>
                    {(expiredCount > 0 || expiringCount > 0) && (
                        <div className="mt-1 flex flex-wrap gap-1.5">
                            {expiredCount > 0 && (
                                <Badge className="bg-status-critical-bg text-status-critical">
                                    {expiredCount} expired
                                </Badge>
                            )}
                            {expiringCount > 0 && (
                                <Badge className="bg-status-warning-bg text-status-warning">
                                    {expiringCount} expiring
                                </Badge>
                            )}
                        </div>
                    )}
                </div>
                {can_edit && (
                    <Button asChild className="bg-primary hover:bg-primary">
                        <Link href={`/sites/${site.id}/documents`}>
                            Manage Documents
                        </Link>
                    </Button>
                )}
            </div>

            {documents.length === 0 ? (
                <Card className="border-dashed">
                    <CardContent className="flex flex-col items-center justify-center py-12 text-center">
                        <FileText className="mb-3 h-10 w-10 text-muted-foreground" />
                        <p className="font-medium">No documents uploaded yet</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Site documents will appear here once they are added.
                        </p>
                    </CardContent>
                </Card>
            ) : (
                <div className="space-y-6">
                    {folderNames.map((folder) => (
                        <section key={folder} className="space-y-3">
                            <div className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide">
                                <FolderOpen className="h-4 w-4 text-primary" />
                                <span>{folder}</span>
                                <Badge variant="secondary">
                                    {groupedDocuments[folder].length}
                                </Badge>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                {groupedDocuments[folder].map((document) => (
                                    <SiteDocumentPreviewCard
                                        key={document.id}
                                        siteId={site.id}
                                        document={document}
                                    />
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            )}
        </div>
    );
}

function SiteDocumentPreviewCard({
    siteId,
    document,
}: {
    siteId: number;
    document: Doc;
}) {
    const fileInfo = getSiteDocumentFileInfo(document.original_name);
    const Icon = fileInfo.icon;
    const category = getSiteDocumentCategory(document.category);

    return (
        <Card
            className={`transition-shadow hover:shadow-md ${
                isSiteDocumentExpired(document.expiry_date)
                    ? 'border-status-critical/40'
                    : ''
            }`}
        >
            <CardContent className="p-4">
                <div className="flex flex-col items-center text-center">
                    <div
                        className={`mb-3 flex h-12 w-12 items-center justify-center rounded-xl ${fileInfo.bg}`}
                    >
                        <Icon className={`h-6 w-6 ${fileInfo.color}`} />
                    </div>
                    <a
                        href={`/sites/${siteId}/documents/${document.id}/download`}
                        className="line-clamp-2 text-sm font-medium hover:text-primary"
                    >
                        {document.title || document.original_name}
                    </a>
                    <p className="mt-1 max-w-full truncate text-xs text-muted-foreground">
                        {document.original_name}
                    </p>
                    <div className="mt-2 flex flex-wrap justify-center gap-1.5">
                        {category ? (
                            <Badge
                                variant="secondary"
                                className={`text-[10px] ${category.color}`}
                            >
                                {category.label}
                            </Badge>
                        ) : document.category ? (
                            <Badge variant="secondary" className="text-[10px]">
                                {document.category}
                            </Badge>
                        ) : null}
                        {isSiteDocumentExpired(document.expiry_date) ? (
                            <Badge className="bg-status-critical-bg text-[10px] text-status-critical">
                                Expired
                            </Badge>
                        ) : isSiteDocumentExpiringSoon(
                              document.expiry_date,
                          ) ? (
                            <Badge className="bg-status-warning-bg text-[10px] text-status-warning">
                                Expiring
                            </Badge>
                        ) : null}
                    </div>
                    <div className="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
                        <span>
                            {formatSiteDocumentFileSize(document.size_bytes)}
                        </span>
                        <Button
                            asChild
                            variant="ghost"
                            size="icon"
                            className="h-7 w-7"
                            aria-label="Download document"
                        >
                            <a
                                href={`/sites/${siteId}/documents/${document.id}/download`}
                            >
                                <Download className="h-3.5 w-3.5" />
                            </a>
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

type RoomDialogMode =
    | 'add'
    | 'edit'
    | 'show'
    | 'delete'
    | 'assign'
    | 'unassign'
    | null;

function BedroomsTab({
    site,
    rooms,
    clientsForRooms,
    summary,
    can_edit,
}: {
    site: Site;
    rooms: NonNullable<TypeSpecificData['rooms']>;
    clientsForRooms: ClientForPicker[];
    summary?: RoomsSummary | null;
    can_edit: boolean;
}) {
    const [dialog, setDialog] = useState<{
        mode: RoomDialogMode;
        target: RoomRecord | null;
    }>({ mode: null, target: null });

    const closeDialog = () => setDialog({ mode: null, target: null });

    const stats = summary ?? (() => {
        const assignable = rooms.filter((r) => r.is_assignable !== false);
        const occupied = assignable.filter((r) => r.assigned_client).length;
        return {
            total: rooms.length,
            bedrooms: assignable.length,
            communal: rooms.length - assignable.length,
            occupied,
            available: assignable.length - occupied,
            occupancy_percent:
                assignable.length > 0
                    ? Math.round((occupied / assignable.length) * 100)
                    : 0,
        };
    })();

    return (
        <>
            <Card>
                <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0">
                    <div>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <BedDouble className="h-4 w-4 text-primary" />
                            Rooms ({stats.total})
                        </CardTitle>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Bedrooms can have a client assigned — communal
                            spaces are tracked but cannot have an occupant.
                            Open a card for full history and details.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {can_edit && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    setDialog({ mode: 'add', target: null })
                                }
                            >
                                <Plus className="mr-1 h-4 w-4" />
                                Add room
                            </Button>
                        )}
                    </div>
                </CardHeader>
                <CardContent className="space-y-4">
                    {stats.total > 0 && (
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                            <StatChip
                                label="Bedrooms"
                                value={stats.bedrooms}
                            />
                            <StatChip
                                label="Occupied"
                                value={stats.occupied}
                                tone={
                                    stats.occupied > 0 ? 'success' : 'muted'
                                }
                            />
                            <StatChip
                                label="Available"
                                value={stats.available}
                                tone={
                                    stats.available > 0 ? 'success' : 'muted'
                                }
                            />
                            <StatChip
                                label="Occupancy"
                                value={stats.occupancy_percent}
                                tone={
                                    stats.occupancy_percent >= 90
                                        ? 'warning'
                                        : 'muted'
                                }
                            />
                            <StatChip
                                label="Communal"
                                value={stats.communal}
                            />
                        </div>
                    )}

                    {rooms.length === 0 ? (
                        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-10 text-center">
                            <div className="rounded-full bg-muted/40 p-3">
                                <BedDouble className="h-6 w-6 text-muted-foreground" />
                            </div>
                            <p className="mt-3 text-sm font-medium">
                                No rooms yet
                            </p>
                            <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                Add bedrooms and shared spaces to track who
                                lives where, record respite stays and keep a
                                full assignment history.
                            </p>
                            {can_edit && (
                                <Button
                                    size="sm"
                                    className="mt-4"
                                    onClick={() =>
                                        setDialog({
                                            mode: 'add',
                                            target: null,
                                        })
                                    }
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    Add first room
                                </Button>
                            )}
                        </div>
                    ) : (
                        (() => {
                            const assignableRooms = rooms.filter(
                                (r) => r.is_assignable !== false,
                            );
                            const communalRooms = rooms.filter(
                                (r) => r.is_assignable === false,
                            );
                            const renderCard = (r: typeof rooms[number]) => (
                                <BedroomCard
                                    key={r.id}
                                    room={r as RoomRecord}
                                    canEdit={can_edit}
                                    onShow={() =>
                                        setDialog({
                                            mode: 'show',
                                            target: r as RoomRecord,
                                        })
                                    }
                                    onEdit={() =>
                                        setDialog({
                                            mode: 'edit',
                                            target: r as RoomRecord,
                                        })
                                    }
                                    onDelete={() =>
                                        setDialog({
                                            mode: 'delete',
                                            target: r as RoomRecord,
                                        })
                                    }
                                    onAssign={() =>
                                        setDialog({
                                            mode: 'assign',
                                            target: r as RoomRecord,
                                        })
                                    }
                                    onUnassign={() =>
                                        setDialog({
                                            mode: 'unassign',
                                            target: r as RoomRecord,
                                        })
                                    }
                                />
                            );
                            return (
                                <div className="grid gap-4 lg:grid-cols-2">
                                    <RoomSection
                                        icon={BedDouble}
                                        title="Bedrooms"
                                        count={assignableRooms.length}
                                        accent="primary"
                                        empty={{
                                            label: 'No bedrooms yet',
                                            hint: 'Tick "Assignable to client" when adding a room to make it a bedroom.',
                                            ctaLabel:
                                                can_edit
                                                    ? 'Add bedroom'
                                                    : undefined,
                                            onCta: can_edit
                                                ? () =>
                                                      setDialog({
                                                          mode: 'add',
                                                          target: null,
                                                      })
                                                : undefined,
                                        }}
                                    >
                                        {assignableRooms.map(renderCard)}
                                    </RoomSection>
                                    <RoomSection
                                        icon={Home}
                                        title="Communal spaces"
                                        count={communalRooms.length}
                                        accent="muted"
                                        empty={{
                                            label: 'No communal spaces',
                                            hint: 'Add kitchens, lounges or bathrooms with the "Assignable to client" box unticked.',
                                        }}
                                    >
                                        {communalRooms.map(renderCard)}
                                    </RoomSection>
                                </div>
                            );
                        })()
                    )}

                    <div className="pt-2 text-right">
                        <Link
                            href={`/sites/${site.id}/rooms`}
                            className="text-xs text-muted-foreground hover:text-primary"
                        >
                            Open full bedroom management →
                        </Link>
                    </div>
                </CardContent>
            </Card>

            {dialog.mode === 'add' && (
                <LazyDialog>
                    <AddRoomDialog
                        siteId={site.id}
                        isOpen
                        onClose={closeDialog}
                    />
                </LazyDialog>
            )}
            {dialog.mode === 'edit' && (
                <LazyDialog>
                    <EditRoomDialog
                        siteId={site.id}
                        room={dialog.target}
                        isOpen
                        onClose={closeDialog}
                    />
                </LazyDialog>
            )}
            {dialog.mode === 'delete' && (
                <LazyDialog>
                    <DeleteRoomDialog
                        siteId={site.id}
                        room={dialog.target}
                        isOpen
                        onClose={closeDialog}
                    />
                </LazyDialog>
            )}
            {dialog.mode === 'show' && (
                <LazyDialog>
                    <ShowRoomDialog
                        room={dialog.target}
                        canManage={can_edit}
                        isOpen
                        onClose={closeDialog}
                        onEdit={() =>
                            setDialog((prev) => ({ ...prev, mode: 'edit' }))
                        }
                        onDelete={() =>
                            setDialog((prev) => ({ ...prev, mode: 'delete' }))
                        }
                        onAssign={() =>
                            setDialog((prev) => ({ ...prev, mode: 'assign' }))
                        }
                        onUnassign={() =>
                            setDialog((prev) => ({
                                ...prev,
                                mode: 'unassign',
                            }))
                        }
                    />
                </LazyDialog>
            )}
            {dialog.mode === 'assign' && (
                <LazyDialog>
                    <AssignClientToRoomDialog
                        siteId={site.id}
                        room={dialog.target}
                        clients={clientsForRooms}
                        isOpen
                        onClose={closeDialog}
                    />
                </LazyDialog>
            )}
            {dialog.mode === 'unassign' && (
                <LazyDialog>
                    <UnassignRoomDialog
                        siteId={site.id}
                        room={dialog.target}
                        isOpen
                        onClose={closeDialog}
                    />
                </LazyDialog>
            )}
        </>
    );
}

function RoomSection({
    icon: Icon,
    title,
    count,
    accent,
    empty,
    children,
}: {
    icon: ComponentType<{ className?: string }>;
    title: string;
    count: number;
    accent: 'primary' | 'muted';
    empty: {
        label: string;
        hint: string;
        ctaLabel?: string;
        onCta?: () => void;
    };
    children: React.ReactNode;
}) {
    const accentCls =
        accent === 'primary'
            ? 'border-primary/30 bg-primary/5'
            : 'border-border bg-muted/20';
    const iconCls =
        accent === 'primary' ? 'text-primary' : 'text-muted-foreground';
    return (
        <section className={`flex flex-col gap-3 rounded-2xl border p-3 ${accentCls}`}>
            <div className="flex items-center justify-between gap-2">
                <h4 className="flex items-center gap-2 text-sm font-semibold">
                    <Icon className={`h-4 w-4 ${iconCls}`} />
                    {title}
                    <span className="text-xs font-normal text-muted-foreground">
                        ({count})
                    </span>
                </h4>
            </div>
            {count === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-xl border border-dashed bg-background/40 py-8 text-center">
                    <Icon className={`h-5 w-5 ${iconCls} opacity-60`} />
                    <p className="mt-2 text-xs font-medium">{empty.label}</p>
                    <p className="mt-1 max-w-xs text-[11px] text-muted-foreground">
                        {empty.hint}
                    </p>
                    {empty.ctaLabel && empty.onCta && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="mt-3"
                            onClick={empty.onCta}
                        >
                            <Plus className="mr-1 h-3.5 w-3.5" />
                            {empty.ctaLabel}
                        </Button>
                    )}
                </div>
            ) : (
                <div className="grid gap-3 2xl:grid-cols-2">{children}</div>
            )}
        </section>
    );
}

function BedroomCard({
    room,
    canEdit,
    onShow,
    onEdit,
    onDelete,
    onAssign,
    onUnassign,
}: {
    room: RoomRecord;
    canEdit: boolean;
    onShow: () => void;
    onEdit: () => void;
    onDelete: () => void;
    onAssign: () => void;
    onUnassign: () => void;
}) {
    const occupant = room.assigned_client ?? null;
    const isAssignable = room.is_assignable !== false;
    const occupantName = occupant
        ? (() => {
              const full = `${occupant.first_name ?? ''} ${occupant.last_name ?? ''}`.trim();
              return occupant.preferred_name &&
                  occupant.preferred_name !== occupant.first_name
                  ? `${occupant.preferred_name} (${full})`
                  : full;
          })()
        : null;
    const occupantInitials = occupant
        ? (
              (occupant.first_name?.[0] ?? '') +
              (occupant.last_name?.[0] ?? '')
          ).toUpperCase() || '?'
        : null;

    return (
        <div
            className={`group relative flex h-full flex-col gap-3 rounded-2xl border bg-card/40 p-4 transition-all hover:border-primary/50 hover:bg-card hover:shadow-md ${
                isAssignable ? '' : 'bg-muted/20'
            }`}
        >
            <div className="flex items-start gap-3">
                <span
                    className={`shrink-0 rounded-xl border p-2 ${
                        isAssignable
                            ? 'bg-background/60'
                            : 'bg-muted/40 opacity-70'
                    }`}
                >
                    <BedDouble
                        className={`h-5 w-5 ${
                            isAssignable
                                ? 'text-primary'
                                : 'text-muted-foreground'
                        }`}
                    />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">
                        {room.name}
                    </p>
                    <div className="mt-1 flex flex-wrap items-center gap-1">
                        {!isAssignable ? (
                            <Badge
                                variant="outline"
                                className="border-muted-foreground/30 text-[10px] text-muted-foreground"
                            >
                                Communal
                            </Badge>
                        ) : occupant ? (
                            <Badge
                                variant="outline"
                                className="border-primary/30 text-[10px] text-primary"
                            >
                                Assigned
                            </Badge>
                        ) : (
                            <Badge
                                variant="outline"
                                className="border-status-success/30 text-[10px] text-status-success"
                            >
                                Available
                            </Badge>
                        )}
                        {(room.assigned_from || room.assigned_until) && (
                            <span className="text-[10px] text-muted-foreground">
                                {room.assigned_from && (
                                    <>Since {room.assigned_from}</>
                                )}
                                {room.assigned_until && (
                                    <> · until {room.assigned_until}</>
                                )}
                            </span>
                        )}
                    </div>
                </div>
                {canEdit && (
                    <div
                        className="flex shrink-0 items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label="Edit bedroom"
                            onClick={onEdit}
                        >
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label="Deactivate bedroom"
                            className="text-status-critical hover:text-status-critical"
                            onClick={onDelete}
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                )}
            </div>

            {!isAssignable ? (
                <div className="rounded-lg border border-dashed bg-background/20 px-2 py-2 text-center text-xs text-muted-foreground">
                    Shared space — no client occupant
                </div>
            ) : occupant ? (
                <div className="flex items-center gap-2 rounded-lg border bg-background/40 px-2 py-1.5">
                    <Avatar className="size-8">
                        {occupant.profile_photo_url && (
                            <AvatarImage
                                src={occupant.profile_photo_url}
                                alt={occupantName ?? ''}
                            />
                        )}
                        <AvatarFallback className="text-[10px]">
                            {occupantInitials}
                        </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-xs font-medium">
                            {occupantName}
                        </p>
                        <p className="truncate text-[10px] text-muted-foreground">
                            Occupant
                        </p>
                    </div>
                </div>
            ) : (
                <div className="rounded-lg border border-dashed bg-background/20 px-2 py-2 text-center text-xs text-muted-foreground">
                    No occupant
                </div>
            )}

            {room.notes && (
                <p className="line-clamp-2 text-xs text-muted-foreground">
                    {room.notes}
                </p>
            )}

            <div className="mt-auto flex items-center justify-end gap-2 pt-1">
                {canEdit && isAssignable && occupant && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onUnassign}
                    >
                        Unassign
                    </Button>
                )}
                {canEdit && isAssignable && (
                    <Button type="button" size="sm" onClick={onAssign}>
                        {occupant ? 'Change occupant' : 'Assign client'}
                    </Button>
                )}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onShow}
                >
                    View
                </Button>
            </div>
        </div>
    );
}

function TypeSpecificTab({
    site,
    data,
    clientsForRooms = [],
    summary,
    can_edit = false,
}: {
    site: Site;
    data: TypeSpecificData;
    clientsForRooms?: ClientForPicker[];
    summary?: RoomsSummary | null;
    can_edit?: boolean;
}) {
    if (site.type === 'house') {
        return (
            <BedroomsTab
                site={site}
                rooms={data.rooms ?? []}
                clientsForRooms={clientsForRooms}
                summary={summary}
                can_edit={can_edit}
            />
        );
    }

    if (site.type === 'head_office') {
        return (
            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <CardTitle className="flex items-center gap-2">
                        <DoorOpen className="h-5 w-5" />
                        Rooms & Resources
                    </CardTitle>
                    <Button asChild variant="outline" size="sm">
                        <Link href={`/sites/${site.id}/resources`}>
                            Manage Resources
                        </Link>
                    </Button>
                </CardHeader>
                <CardContent>
                    {!data.resources || data.resources.length === 0 ? (
                        <div className="py-8 text-center text-muted-foreground">
                            <DoorOpen className="mx-auto mb-3 h-12 w-12 opacity-50" />
                            <p>No rooms or resources configured yet</p>
                            <Button asChild className="mt-4">
                                <Link
                                    href={`/sites/${site.id}/edit`}
                                >
                                    Add Resources
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {data.resources.map((resource) => (
                                <Card key={resource.id} className="bg-muted/50">
                                    <CardContent className="p-4">
                                        <div className="font-medium">
                                            {resource.name}
                                        </div>
                                        <div className="mt-1 text-sm text-muted-foreground capitalize">
                                            {resource.type.replace('_', ' ')}
                                        </div>
                                        {resource.capacity && (
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                Capacity: {resource.capacity}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>
        );
    }

    // Facility zones
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle className="flex items-center gap-2">
                    <LayoutGrid className="h-5 w-5" />
                    Areas & Zones
                </CardTitle>
                <Button asChild variant="outline" size="sm">
                    <Link href={`/sites/${site.id}/zones`}>Manage Zones</Link>
                </Button>
            </CardHeader>
            <CardContent>
                {!data.zones || data.zones.length === 0 ? (
                    <div className="py-8 text-center text-muted-foreground">
                        <LayoutGrid className="mx-auto mb-3 h-12 w-12 opacity-50" />
                        <p>No zones configured yet</p>
                        <Button asChild className="mt-4">
                            <Link
                                href={`/sites/${site.id}/edit`}
                            >
                                Add Zones
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {data.zones.map((zone) => (
                            <Card key={zone.id} className="bg-muted/50">
                                <CardContent className="p-4">
                                    <div className="font-medium">
                                        {zone.name}
                                    </div>
                                    {zone.type && (
                                        <div className="mt-1 text-sm text-muted-foreground">
                                            {zone.type}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

const PRESET_REQUIREMENTS = [
    {
        requirement_name: 'First Aid Certificate',
        category: 'mandatory' as const,
        description:
            'Current first aid certificate (NZQA Level 2 or equivalent)',
        certification_required: true,
        expiry_period_months: 24,
    },
    {
        requirement_name: 'Medication Competency',
        category: 'mandatory' as const,
        description: 'Competency assessment for medication administration',
        certification_required: true,
        expiry_period_months: 12,
    },
    {
        requirement_name: 'Manual Handling',
        category: 'mandatory' as const,
        description: 'Safe manual handling and transfer techniques training',
        certification_required: true,
        expiry_period_months: 24,
    },
    {
        requirement_name: 'Positive Behaviour Support',
        category: 'specialist' as const,
        description: 'PBS training for managing challenging behaviours',
        certification_required: true,
        expiry_period_months: 12,
    },
    {
        requirement_name: 'Cultural Safety',
        category: 'mandatory' as const,
        description:
            'Cultural competency training including Te Tiriti o Waitangi awareness',
        certification_required: true,
        expiry_period_months: 36,
    },
    {
        requirement_name: 'Restricted Practices',
        category: 'specialist' as const,
        description: 'Training in use and minimisation of restricted practices',
        certification_required: true,
        expiry_period_months: 12,
    },
];

const categoryConfig = {
    mandatory: {
        label: 'Mandatory',
        color: 'border-status-critical/30 text-status-critical bg-status-critical',
        icon: Shield,
    },
    recommended: {
        label: 'Recommended',
        color: 'border-status-warning/30 text-status-warning bg-status-warning',
        icon: Star,
    },
    specialist: {
        label: 'Specialist',
        color: 'border-primary/30 text-primary/70 bg-primary/10',
        icon: Award,
    },
};

const coverageTypeConfig = {
    day: {
        label: 'Day',
        color: 'border-status-success/30 text-status-success bg-status-success',
    },
    evening: {
        label: 'Evening',
        color: 'border-status-warning/30 text-status-warning bg-status-warning',
    },
    overnight: {
        label: 'Overnight',
        color: 'border-primary/30 text-primary/70 bg-primary/10',
    },
    custom: {
        label: 'Custom',
        color: 'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
    },
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

function coverageTimeLabel(starts: string, ends: string) {
    return `${starts}-${ends}${ends <= starts ? ' overnight' : ''}`;
}

function coverageHealthVariant(missingStaff: number) {
    if (missingStaff > 0) return 'destructive';
    return 'outline';
}

function CoverageRequirementsTab({
    site,
    requirements,
    preview,
    clients,
    serviceContexts,
    can_edit,
}: {
    site: Site;
    requirements: CoverageRequirement[];
    preview: NonNullable<Props['coveragePreview']>;
    clients: ClientLite[];
    serviceContexts: NonNullable<Site['service_contexts']>;
    can_edit: boolean;
}) {
    type CoverageRoleRow = {
        key: 'caregiver' | 'driver' | 'med_competent';
        minimum: number;
    };

    const [dialogOpen, setDialogOpen] = useState(false);
    const form = useForm({
        name: '',
        coverage_type: 'day',
        day_of_week: 'mon',
        starts_time: '07:00',
        ends_time: '15:00',
        minimum_staff: 1,
        service_context_id: '',
        preferred_client_id: '',
        allow_overstaffing: true,
        role_requirements: [
            { key: 'caregiver', minimum: 1 },
        ] as CoverageRoleRow[],
        shift_type: '',
        notes: '',
    });

    const sitePreview = preview[0] ?? null;
    const coverageSegments = [
        {
            label: 'Under-covered',
            value: sitePreview?.under_covered_windows ?? 0,
            color: 'var(--color-status-critical)',
        },
        {
            label: 'Exact',
            value: sitePreview?.exact_windows ?? 0,
            color: 'var(--color-status-success)',
        },
        {
            label: 'Overstaffed',
            value: sitePreview?.overstaffed_windows ?? 0,
            color: 'var(--color-status-warning)',
        },
    ];
    const stableCoverageRate =
        sitePreview && sitePreview.total_windows > 0
            ? Math.round(
                  ((sitePreview.exact_windows +
                      sitePreview.overstaffed_windows) /
                      sitePreview.total_windows) *
                      100,
              )
            : 0;
    const shortageRate =
        sitePreview && sitePreview.total_windows > 0
            ? Math.round(
                  (sitePreview.under_covered_windows /
                      sitePreview.total_windows) *
                      100,
              )
            : 0;

    function applyPreset(type: 'day' | 'evening' | 'overnight') {
        const map = {
            day: {
                name: 'Day coverage',
                starts_time: '07:00',
                ends_time: '15:00',
            },
            evening: {
                name: 'Evening coverage',
                starts_time: '15:00',
                ends_time: '23:00',
            },
            overnight: {
                name: 'Overnight coverage',
                starts_time: '23:00',
                ends_time: '07:00',
            },
        };

        form.setData({
            ...form.data,
            coverage_type: type,
            name: map[type].name,
            starts_time: map[type].starts_time,
            ends_time: map[type].ends_time,
        });
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/sites/${site.id}/coverage-requirements`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.setData('coverage_type', 'day');
                form.setData('day_of_week', 'mon');
                form.setData('starts_time', '07:00');
                form.setData('ends_time', '15:00');
                form.setData('minimum_staff', 1);
                form.setData('preferred_client_id', '');
                form.setData('allow_overstaffing', true);
                form.setData('role_requirements', [
                    { key: 'caregiver', minimum: 1 } as CoverageRoleRow,
                ]);
                setDialogOpen(false);
            },
        });
    }

    function deleteRequirement(id: number) {
        form.delete(`/sites/${site.id}/coverage-requirements/${id}`, {
            preserveScroll: true,
        });
    }

    return (
        <div className="space-y-4">
            <div className="grid gap-4 xl:grid-cols-[1.25fr_0.95fr]">
                <Card className="via-primary/10/70 overflow-hidden border-primary/60 bg-gradient-to-br from-white to-status-info-bg/70">
                    <CardHeader className="pb-3">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <CardTitle className="flex items-center gap-2 text-base text-foreground">
                                    <Layers className="h-4 w-4 text-primary" />
                                    Coverage health
                                </CardTitle>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Demand versus assigned supply for the next
                                    fortnight at {site.name}.
                                </p>
                            </div>
                            <Badge
                                variant={
                                    (sitePreview?.under_covered_windows ?? 0) >
                                    0
                                        ? 'destructive'
                                        : 'secondary'
                                }
                                className={
                                    (sitePreview?.under_covered_windows ?? 0) >
                                    0
                                        ? ''
                                        : 'bg-status-success-bg text-status-success'
                                }
                            >
                                {(sitePreview?.under_covered_windows ?? 0) > 0
                                    ? `${sitePreview?.under_covered_windows ?? 0} windows at risk`
                                    : 'No projected shortages'}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div className="rounded-2xl border border-white/70 bg-white/85 p-4 shadow-sm dark:bg-muted/40">
                                <p className="text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase">
                                    Windows
                                </p>
                                <p className="mt-2 text-3xl font-bold text-foreground">
                                    {sitePreview?.total_windows ?? 0}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Active demand windows
                                </p>
                            </div>
                            <div className="rounded-2xl border border-status-critical/70 bg-white/85 p-4 shadow-sm dark:bg-muted/40">
                                <p className="text-[11px] font-semibold tracking-[0.16em] text-status-critical uppercase">
                                    Under-covered
                                </p>
                                <p className="mt-2 text-3xl font-bold text-status-critical">
                                    {sitePreview?.under_covered_windows ?? 0}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Need action now
                                </p>
                            </div>
                            <div className="rounded-2xl border border-status-success/70 bg-white/85 p-4 shadow-sm dark:bg-muted/40">
                                <p className="text-[11px] font-semibold tracking-[0.16em] text-status-success uppercase">
                                    Exact
                                </p>
                                <p className="mt-2 text-3xl font-bold text-status-success">
                                    {sitePreview?.exact_windows ?? 0}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Demand matched cleanly
                                </p>
                            </div>
                            <div className="rounded-2xl border border-status-warning/70 bg-white/85 p-4 shadow-sm dark:bg-muted/40">
                                <p className="text-[11px] font-semibold tracking-[0.16em] text-status-warning uppercase">
                                    Largest gap
                                </p>
                                <p className="mt-2 text-3xl font-bold text-status-warning">
                                    {sitePreview?.largest_missing_staff ?? 0}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Missing staff in one window
                                </p>
                            </div>
                        </div>

                        <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
                            <div className="rounded-2xl border border-white/70 bg-white/85 p-4 shadow-sm dark:bg-muted/40">
                                <div className="flex items-center gap-6">
                                    <DonutChart
                                        segments={coverageSegments}
                                        size={148}
                                        strokeWidth={18}
                                        centerLabel="windows"
                                        centerValue={
                                            sitePreview?.total_windows ?? 0
                                        }
                                    />
                                    <div className="min-w-0 flex-1 space-y-3">
                                        {coverageSegments.map((segment) => (
                                            <div
                                                key={segment.label}
                                                className="flex items-center justify-between gap-4 text-sm"
                                            >
                                                <span className="flex items-center gap-2 text-foreground">
                                                    <span
                                                        className="h-2.5 w-2.5 rounded-full"
                                                        style={{
                                                            backgroundColor:
                                                                segment.color,
                                                        }}
                                                    />
                                                    {segment.label}
                                                </span>
                                                <span className="font-semibold text-foreground">
                                                    {segment.value}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="rounded-2xl border border-white/70 bg-white/85 p-4 shadow-sm dark:bg-muted/40">
                                    <ProgressRing
                                        value={stableCoverageRate}
                                        size={104}
                                        color="var(--color-primary)"
                                        label="covered"
                                    />
                                    <div className="mt-3 text-center">
                                        <p className="text-sm font-semibold text-foreground">
                                            Stable coverage
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Exact + overstaffed windows
                                        </p>
                                    </div>
                                </div>
                                <div className="rounded-2xl border border-white/70 bg-white/85 p-4 shadow-sm dark:bg-muted/40">
                                    <ProgressRing
                                        value={shortageRate}
                                        size={104}
                                        color="var(--color-status-critical)"
                                        label="risk"
                                    />
                                    <div className="mt-3 text-center">
                                        <p className="text-sm font-semibold text-foreground">
                                            Coverage risk
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Windows below minimum staffing
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {!sitePreview || sitePreview.alerts.length === 0 ? (
                            <div className="rounded-2xl border border-dashed border-status-success/30 bg-status-success-bg p-4 text-sm text-status-success">
                                No projected coverage gaps in the upcoming
                                fortnight for this site.
                            </div>
                        ) : (
                            <div className="grid gap-4 lg:grid-cols-[0.95fr_1.05fr]">
                                <div className="rounded-2xl border border-white/70 bg-white/85 p-4 shadow-sm dark:bg-muted/40">
                                    <p className="text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase">
                                        Largest gaps
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Missing staff by next impacted windows.
                                    </p>
                                    <div className="mt-4">
                                        <HorizontalBarChart
                                            items={sitePreview.alerts.map(
                                                (alert) => ({
                                                    label: `${alert.rule_name} · ${alert.window_label}`,
                                                    value: alert.missing_staff,
                                                    color:
                                                        alert.missing_staff > 1
                                                            ? 'var(--color-status-critical)'
                                                            : 'var(--color-status-warning)',
                                                    maxValue: Math.max(
                                                        1,
                                                        sitePreview.largest_missing_staff,
                                                    ),
                                                }),
                                            )}
                                            heightPerBar={24}
                                            color="var(--color-primary)"
                                        />
                                    </div>
                                </div>

                                <div className="space-y-3">
                                    {sitePreview.alerts.map((alert, index) => (
                                        <div
                                            key={`${alert.rule_name}-${alert.window_label}-${index}`}
                                            className="rounded-2xl border border-white/70 bg-white/85 p-4 shadow-sm dark:bg-muted/40"
                                        >
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <div className="font-medium text-foreground">
                                                        {alert.rule_name}
                                                    </div>
                                                    <div className="text-sm text-muted-foreground">
                                                        {alert.window_label}
                                                    </div>
                                                </div>
                                                <Badge
                                                    variant={coverageHealthVariant(
                                                        alert.missing_staff,
                                                    )}
                                                >
                                                    Missing{' '}
                                                    {alert.missing_staff}
                                                </Badge>
                                            </div>
                                            <div className="mt-3">
                                                <HorizontalBarChart
                                                    items={[
                                                        {
                                                            label: 'Required',
                                                            value: alert.required_staff,
                                                            color: 'var(--color-primary)',
                                                            maxValue: Math.max(
                                                                alert.required_staff,
                                                                alert.assigned_staff,
                                                            ),
                                                        },
                                                        {
                                                            label: 'Assigned',
                                                            value: alert.assigned_staff,
                                                            color: 'var(--color-status-success)',
                                                            maxValue: Math.max(
                                                                alert.required_staff,
                                                                alert.assigned_staff,
                                                            ),
                                                        },
                                                    ]}
                                                    heightPerBar={22}
                                                    color="var(--color-primary)"
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between pb-3">
                        <CardTitle className="text-base">
                            Coverage rules
                        </CardTitle>
                        {can_edit && (
                            <Dialog
                                open={dialogOpen}
                                onOpenChange={setDialogOpen}
                            >
                                <DialogTrigger asChild>
                                    <Button size="sm">
                                        <Plus className="mr-1 h-4 w-4" />
                                        Add rule
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="max-w-xl">
                                    <DialogHeader>
                                        <DialogTitle>
                                            Add coverage requirement
                                        </DialogTitle>
                                    </DialogHeader>
                                    <div className="space-y-2">
                                        <Label className="text-xs text-muted-foreground">
                                            Quick presets
                                        </Label>
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    applyPreset('day')
                                                }
                                            >
                                                Day
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    applyPreset('evening')
                                                }
                                            >
                                                Evening
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    applyPreset('overnight')
                                                }
                                            >
                                                Overnight
                                            </Button>
                                        </div>
                                    </div>
                                    <form
                                        onSubmit={submit}
                                        className="space-y-3"
                                    >
                                        <div>
                                            <Label>Name</Label>
                                            <Input
                                                value={form.data.name}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'name',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <Label>Coverage type</Label>
                                                <Select
                                                    value={
                                                        form.data.coverage_type
                                                    }
                                                    onValueChange={(v) =>
                                                        form.setData(
                                                            'coverage_type',
                                                            v as any,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="day">
                                                            Day
                                                        </SelectItem>
                                                        <SelectItem value="evening">
                                                            Evening
                                                        </SelectItem>
                                                        <SelectItem value="overnight">
                                                            Overnight
                                                        </SelectItem>
                                                        <SelectItem value="custom">
                                                            Custom
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div>
                                                <Label>Day</Label>
                                                <Select
                                                    value={
                                                        form.data.day_of_week
                                                    }
                                                    onValueChange={(v) =>
                                                        form.setData(
                                                            'day_of_week',
                                                            v as any,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {[
                                                            'mon',
                                                            'tue',
                                                            'wed',
                                                            'thu',
                                                            'fri',
                                                            'sat',
                                                            'sun',
                                                        ].map((day) => (
                                                            <SelectItem
                                                                key={day}
                                                                value={day}
                                                            >
                                                                {weekdayLabel(
                                                                    day,
                                                                )}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <Label>Start</Label>
                                                <Input
                                                    type="time"
                                                    value={
                                                        form.data.starts_time
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'starts_time',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <Label>End</Label>
                                                <Input
                                                    type="time"
                                                    value={form.data.ends_time}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'ends_time',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <Label>Minimum staff</Label>
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    max={12}
                                                    value={
                                                        form.data.minimum_staff
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'minimum_staff',
                                                            Number(
                                                                e.target.value,
                                                            ),
                                                        )
                                                    }
                                                    required
                                                />
                                            </div>
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <Label>
                                                    Preferred planning client
                                                </Label>
                                                <Select
                                                    value={String(
                                                        form.data
                                                            .preferred_client_id ||
                                                            'none',
                                                    )}
                                                    onValueChange={(v) =>
                                                        form.setData(
                                                            'preferred_client_id',
                                                            v === 'none'
                                                                ? ''
                                                                : v,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="No preferred client" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="none">
                                                            No preferred client
                                                        </SelectItem>
                                                        {clients.map(
                                                            (client) => (
                                                                <SelectItem
                                                                    key={
                                                                        client.id
                                                                    }
                                                                    value={String(
                                                                        client.id,
                                                                    )}
                                                                >
                                                                    {
                                                                        client.first_name
                                                                    }{' '}
                                                                    {
                                                                        client.last_name
                                                                    }
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div>
                                                <Label>Service context</Label>
                                                <Select
                                                    value={String(
                                                        form.data
                                                            .service_context_id ||
                                                            '',
                                                    )}
                                                    onValueChange={(v) =>
                                                        form.setData(
                                                            'service_context_id',
                                                            v === 'none'
                                                                ? ''
                                                                : v,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Any service" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="none">
                                                            Any service
                                                        </SelectItem>
                                                        {serviceContexts
                                                            .filter(
                                                                (item) =>
                                                                    item.is_active,
                                                            )
                                                            .map((item) => (
                                                                <SelectItem
                                                                    key={
                                                                        item.id
                                                                    }
                                                                    value={String(
                                                                        item.id,
                                                                    )}
                                                                >
                                                                    {item.name}
                                                                </SelectItem>
                                                            ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div>
                                                <Label>Shift type</Label>
                                                <Select
                                                    value={String(
                                                        form.data.shift_type ||
                                                            'any',
                                                    )}
                                                    onValueChange={(v) =>
                                                        form.setData(
                                                            'shift_type',
                                                            v === 'any'
                                                                ? ''
                                                                : v,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Any shift type" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="any">
                                                            Any shift type
                                                        </SelectItem>
                                                        <SelectItem value="standard">
                                                            Standard
                                                        </SelectItem>
                                                        <SelectItem value="sleepover">
                                                            Sleepover
                                                        </SelectItem>
                                                        <SelectItem value="on_call">
                                                            On-call
                                                        </SelectItem>
                                                        <SelectItem value="split">
                                                            Split
                                                        </SelectItem>
                                                        <SelectItem value="travel">
                                                            Travel
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>
                                        <div className="space-y-3 rounded-lg border p-3">
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <div className="text-sm font-medium">
                                                        Role-based demand
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        Define which
                                                        capabilities must be
                                                        present in this coverage
                                                        window.
                                                    </div>
                                                </div>
                                                <label className="flex items-center gap-2 text-sm text-muted-foreground">
                                                    <input
                                                        type="checkbox"
                                                        checked={
                                                            form.data
                                                                .allow_overstaffing
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'allow_overstaffing',
                                                                e.target
                                                                    .checked,
                                                            )
                                                        }
                                                    />
                                                    Allow overstaffing
                                                </label>
                                            </div>
                                            <div className="space-y-2">
                                                {form.data.role_requirements.map(
                                                    (role, index) => (
                                                        <div
                                                            key={`${role.key}-${index}`}
                                                            className="grid gap-2 sm:grid-cols-[1fr_120px_auto]"
                                                        >
                                                            <Select
                                                                value={role.key}
                                                                onValueChange={(
                                                                    value,
                                                                ) => {
                                                                    const next =
                                                                        [
                                                                            ...form
                                                                                .data
                                                                                .role_requirements,
                                                                        ] as CoverageRoleRow[];
                                                                    next[
                                                                        index
                                                                    ] = {
                                                                        key: value as
                                                                            | 'caregiver'
                                                                            | 'driver'
                                                                            | 'med_competent',
                                                                        minimum:
                                                                            next[
                                                                                index
                                                                            ]
                                                                                ?.minimum ??
                                                                            1,
                                                                    };
                                                                    form.setData(
                                                                        'role_requirements',
                                                                        next,
                                                                    );
                                                                }}
                                                            >
                                                                <SelectTrigger>
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="caregiver">
                                                                        Caregiver
                                                                    </SelectItem>
                                                                    <SelectItem value="driver">
                                                                        Driver
                                                                    </SelectItem>
                                                                    <SelectItem value="med_competent">
                                                                        Medication
                                                                        competent
                                                                    </SelectItem>
                                                                </SelectContent>
                                                            </Select>
                                                            <Input
                                                                type="number"
                                                                min={1}
                                                                max={12}
                                                                value={
                                                                    role.minimum
                                                                }
                                                                onChange={(
                                                                    e,
                                                                ) => {
                                                                    const next =
                                                                        [
                                                                            ...form
                                                                                .data
                                                                                .role_requirements,
                                                                        ] as CoverageRoleRow[];
                                                                    next[
                                                                        index
                                                                    ] = {
                                                                        key:
                                                                            next[
                                                                                index
                                                                            ]
                                                                                ?.key ??
                                                                            'caregiver',
                                                                        minimum:
                                                                            Number(
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            ) ||
                                                                            1,
                                                                    };
                                                                    form.setData(
                                                                        'role_requirements',
                                                                        next,
                                                                    );
                                                                }}
                                                            />
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                disabled={
                                                                    form.data
                                                                        .role_requirements
                                                                        .length ===
                                                                    1
                                                                }
                                                                onClick={() =>
                                                                    form.setData(
                                                                        'role_requirements',
                                                                        form.data.role_requirements.filter(
                                                                            (
                                                                                _,
                                                                                rowIndex,
                                                                            ) =>
                                                                                rowIndex !==
                                                                                index,
                                                                        ) as CoverageRoleRow[],
                                                                    )
                                                                }
                                                            >
                                                                Remove
                                                            </Button>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    form.setData(
                                                        'role_requirements',
                                                        [
                                                            ...form.data
                                                                .role_requirements,
                                                            {
                                                                key: 'driver',
                                                                minimum: 1,
                                                            } as CoverageRoleRow,
                                                        ] as CoverageRoleRow[],
                                                    )
                                                }
                                            >
                                                Add role
                                            </Button>
                                        </div>
                                        <div>
                                            <Label>Notes</Label>
                                            <Textarea
                                                value={form.data.notes}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'notes',
                                                        e.target.value,
                                                    )
                                                }
                                                rows={3}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={form.processing}
                                            className="w-full"
                                        >
                                            {form.processing
                                                ? 'Saving…'
                                                : 'Save coverage rule'}
                                        </Button>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {requirements.length === 0 ? (
                            <div className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                No house/site coverage rules configured yet. Add
                                day, evening, or overnight coverage so rostering
                                can detect true staffing gaps.
                            </div>
                        ) : (
                            requirements.map((requirement) => {
                                const config =
                                    coverageTypeConfig[
                                        requirement.coverage_type
                                    ] ?? coverageTypeConfig.custom;
                                return (
                                    <div
                                        key={requirement.id}
                                        className="rounded-lg border p-3"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium">
                                                        {requirement.name}
                                                    </span>
                                                    <Badge
                                                        variant="outline"
                                                        className={config.color}
                                                    >
                                                        {config.label}
                                                    </Badge>
                                                    <Badge variant="outline">
                                                        Need{' '}
                                                        {
                                                            requirement.minimum_staff
                                                        }
                                                    </Badge>
                                                </div>
                                                <div className="mt-1 text-sm text-muted-foreground">
                                                    {weekdayLabel(
                                                        requirement.day_of_week,
                                                    )}{' '}
                                                    ·{' '}
                                                    {coverageTimeLabel(
                                                        requirement.starts_time,
                                                        requirement.ends_time,
                                                    )}
                                                    {requirement.preferred_client
                                                        ? ` · Default ${requirement.preferred_client.name}`
                                                        : ''}
                                                    {requirement.service_context
                                                        ? ` · ${requirement.service_context.name}`
                                                        : ''}
                                                    {requirement.shift_type
                                                        ? ` · ${requirement.shift_type.replace('_', ' ')}`
                                                        : ''}
                                                </div>
                                                {requirement.notes ? (
                                                    <div className="mt-2 text-sm text-muted-foreground">
                                                        {requirement.notes}
                                                    </div>
                                                ) : null}
                                                {requirement.role_requirements
                                                    ?.length ? (
                                                    <div className="mt-2 flex flex-wrap gap-2">
                                                        {requirement.role_requirements.map(
                                                            (role) => (
                                                                <Badge
                                                                    key={`${requirement.id}-${role.key}`}
                                                                    variant="outline"
                                                                >
                                                                    {role.key.replace(
                                                                        '_',
                                                                        ' ',
                                                                    )}{' '}
                                                                    x
                                                                    {
                                                                        role.minimum
                                                                    }
                                                                </Badge>
                                                            ),
                                                        )}
                                                        {!requirement.allow_overstaffing ? (
                                                            <Badge variant="outline">
                                                                No overfill
                                                            </Badge>
                                                        ) : null}
                                                    </div>
                                                ) : null}
                                            </div>
                                            {can_edit ? (
                                                <ConfirmAction
                                                    title="Remove coverage requirement?"
                                                    description={`Remove "${requirement.name}" from this site?`}
                                                    confirmLabel="Remove"
                                                    onConfirm={() =>
                                                        deleteRequirement(
                                                            requirement.id,
                                                        )
                                                    }
                                                >
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="shrink-0 text-muted-foreground hover:text-status-critical"
                                                    >
                                                        Remove
                                                    </Button>
                                                </ConfirmAction>
                                            ) : null}
                                        </div>
                                    </div>
                                );
                            })
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

function StaffRequirementsTab({
    site,
    requirements,
    can_edit,
}: {
    site: Site;
    requirements: StaffRequirement[];
    can_edit: boolean;
}) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const form = useForm({
        requirement_name: '',
        category: 'mandatory',
        description: '',
        certification_required: false,
        expiry_period_months: '' as string | number,
    });

    const grouped = useMemo(() => {
        const groups: Record<
            'mandatory' | 'recommended' | 'specialist',
            StaffRequirement[]
        > = {
            mandatory: [],
            recommended: [],
            specialist: [],
        };
        requirements.forEach((r) => {
            groups[r.category].push(r);
        });
        return groups;
    }, [requirements]);

    function applyPreset(preset: (typeof PRESET_REQUIREMENTS)[0]) {
        form.setData({
            requirement_name: preset.requirement_name,
            category: preset.category,
            description: preset.description,
            certification_required: preset.certification_required,
            expiry_period_months: preset.expiry_period_months,
        });
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/sites/${site.id}/staff-requirements`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setDialogOpen(false);
            },
        });
    }

    function deleteRequirement(id: number) {
        form.delete(`/sites/${site.id}/staff-requirements/${id}`, {
            preserveScroll: true,
        });
    }

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <h3 className="text-lg font-medium">
                    Staff Competency Requirements
                </h3>
                {can_edit && (
                    <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                        <DialogTrigger asChild>
                            <Button size="sm">
                                <Plus className="mr-1 h-4 w-4" />
                                Add Requirement
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="max-w-lg">
                            <DialogHeader>
                                <DialogTitle>Add Staff Requirement</DialogTitle>
                            </DialogHeader>

                            {/* Preset buttons */}
                            <div className="space-y-2">
                                <Label className="text-xs text-muted-foreground">
                                    Quick-add common NZ requirements:
                                </Label>
                                <div className="flex flex-wrap gap-1">
                                    {PRESET_REQUIREMENTS.filter(
                                        (p) =>
                                            !requirements.some(
                                                (r) =>
                                                    r.requirement_name ===
                                                    p.requirement_name,
                                            ),
                                    ).map((p) => (
                                        <Button
                                            key={p.requirement_name}
                                            variant="outline"
                                            size="sm"
                                            className="text-xs"
                                            onClick={() => applyPreset(p)}
                                        >
                                            {p.requirement_name}
                                        </Button>
                                    ))}
                                </div>
                            </div>

                            <form onSubmit={submit} className="mt-2 space-y-3">
                                <div>
                                    <Label>Requirement Name</Label>
                                    <Input
                                        value={form.data.requirement_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'requirement_name',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
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
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="mandatory">
                                                Mandatory
                                            </SelectItem>
                                            <SelectItem value="recommended">
                                                Recommended
                                            </SelectItem>
                                            <SelectItem value="specialist">
                                                Specialist
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Description</Label>
                                    <Textarea
                                        value={form.data.description}
                                        onChange={(e) =>
                                            form.setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                        rows={2}
                                    />
                                </div>
                                <div className="flex items-center gap-3">
                                    <Switch
                                        checked={
                                            form.data.certification_required
                                        }
                                        onCheckedChange={(v) =>
                                            form.setData(
                                                'certification_required',
                                                v,
                                            )
                                        }
                                    />
                                    <Label>Certification Required</Label>
                                </div>
                                <div>
                                    <Label>Expiry Period (months)</Label>
                                    <Input
                                        type="number"
                                        min={1}
                                        value={form.data.expiry_period_months}
                                        onChange={(e) =>
                                            form.setData(
                                                'expiry_period_months',
                                                e.target.value
                                                    ? parseInt(e.target.value)
                                                    : '',
                                            )
                                        }
                                        placeholder="e.g. 12, 24"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                    className="w-full"
                                >
                                    {form.processing
                                        ? 'Adding...'
                                        : 'Add Requirement'}
                                </Button>
                            </form>
                        </DialogContent>
                    </Dialog>
                )}
            </div>

            {requirements.length === 0 ? (
                <Card>
                    <CardContent className="py-8 text-center">
                        <GraduationCap className="mx-auto mb-3 h-12 w-12 text-muted-foreground opacity-50" />
                        <p className="text-muted-foreground">
                            No staff requirements configured for this site
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Add mandatory, recommended, and specialist
                            competency requirements
                        </p>
                    </CardContent>
                </Card>
            ) : (
                (['mandatory', 'recommended', 'specialist'] as const).map(
                    (cat) => {
                        const items = grouped[cat];
                        if (items.length === 0) return null;
                        const config = categoryConfig[cat];
                        const CatIcon = config.icon;
                        return (
                            <Card key={cat}>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <CatIcon className="h-4 w-4" />
                                        {config.label} Requirements
                                        <Badge
                                            variant="outline"
                                            className={config.color}
                                        >
                                            {items.length}
                                        </Badge>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2">
                                        {items.map((req) => (
                                            <div
                                                key={req.id}
                                                className="flex items-start justify-between gap-3 rounded-lg border p-3"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="text-sm font-medium">
                                                            {
                                                                req.requirement_name
                                                            }
                                                        </span>
                                                        {req.certification_required && (
                                                            <Badge
                                                                variant="outline"
                                                                className="border-status-success/30 bg-status-success-bg text-xs text-status-success"
                                                            >
                                                                <Award className="mr-1 h-3 w-3" />
                                                                Certification
                                                            </Badge>
                                                        )}
                                                        {req.expiry_period_months && (
                                                            <Badge
                                                                variant="outline"
                                                                className="border-border/30 text-xs text-muted-foreground"
                                                            >
                                                                Renew every{' '}
                                                                {
                                                                    req.expiry_period_months
                                                                }
                                                                mo
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    {req.description && (
                                                        <p className="mt-1 text-sm text-muted-foreground">
                                                            {req.description}
                                                        </p>
                                                    )}
                                                </div>
                                                {can_edit && (
                                                    <ConfirmAction
                                                        title="Remove staff requirement?"
                                                        description={`Remove "${req.requirement_name}" from this site?`}
                                                        confirmLabel="Remove"
                                                        onConfirm={() =>
                                                            deleteRequirement(
                                                                req.id,
                                                            )
                                                        }
                                                    >
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="shrink-0 text-muted-foreground hover:text-status-critical"
                                                        >
                                                            Remove
                                                        </Button>
                                                    </ConfirmAction>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    },
                )
            )}
        </div>
    );
}
