import { HorizontalBarChart, ProgressRing } from '@/components/fleet-charts';
import { DonutChart } from '@/components/ops-stat-card';
import PageShell from '@/components/page-shell';
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
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/fleet-utils';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    Award,
    BedDouble,
    Building2,
    Calendar,
    Car,
    ClipboardCheck,
    Clock,
    Cpu,
    DollarSign,
    Download,
    DoorOpen,
    FileText,
    FolderOpen,
    Fuel,
    GraduationCap,
    Home,
    Layers,
    LayoutGrid,
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
    Truck,
    User,
    Users,
    Warehouse,
} from 'lucide-react';
import { useMemo, useState, type ComponentType } from 'react';
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
    EditContactInfoDialog,
    EditLocationDialog,
    EditSafetyDialog,
} from './_overview-dialogs';
import SiteOverviewMapCard from './_overview-map-card';

type Site = {
    id: number;
    name: string;
    type: 'head_office' | 'house' | 'facility';
    display_type: string;
    phone?: string | null;
    email?: string | null;
    manager_name?: string | null;
    manager_phone?: string | null;
    after_hours_phone?: string | null;
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
    status: string;
};
type ChecklistItem = { key: string; label: string; done: boolean };

type TypeSpecificData = {
    rooms?: Array<{
        id: number;
        name: string;
        assigned_client?: { id: number; name: string } | null;
    }>;
    resources?: Array<{
        id: number;
        name: string;
        type: string;
        capacity?: number;
    }>;
    zones?: Array<{ id: number; name: string; type?: string }>;
};

type VendorLite = {
    id: number;
    company_name: string;
    service_type: string;
    phone?: string | null;
    is_preferred: boolean;
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

type ChecklistsSummary = {
    stats: {
        active_assignments: number;
        scheduled: number;
        in_progress: number;
        overdue: number;
        completed_30d: number;
    };
    assignments: Array<{
        id: number;
        frequency: string;
        start_date: string | null;
        template: {
            id: number;
            name: string;
            description?: string | null;
            frequency?: string | null;
        } | null;
        assigned_to: { id: number; name: string } | null;
    }>;
    recentRuns: Array<{
        id: number;
        status: 'scheduled' | 'in_progress' | 'completed' | 'overdue' | 'skipped';
        scheduled_date: string | null;
        completed_at: string | null;
        completion_percentage: number;
        items_passed: number;
        items_failed: number;
        is_overdue: boolean;
        template: { id: number; name: string } | null;
        completed_by: { id: number; name: string } | null;
    }>;
    availableTemplates: Array<{
        id: number;
        name: string;
        description?: string | null;
        frequency: string;
        items_count: number;
    }>;
    can: { view: boolean; schedule: boolean; run: boolean };
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

type Props = {
    site: Site;
    clients: ClientLite[];
    contacts: Contact[];
    documents: Doc[];
    assets: AssetLite[];
    checklist: ChecklistItem[];
    houseLedger?: SiteLedgerPanelData | null;
    typeSpecificData: TypeSpecificData;
    vendors?: VendorLite[];
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
    integrationStatus?: Array<{ provider: string; status: string }>;
    can_edit: boolean;
    can?: { createAsset?: boolean };
    fleet?: SiteFleetData;
    checklistsSummary?: ChecklistsSummary;
    siteNotes?: Array<{
        id: number;
        body: string;
        created_at: string | null;
        created_by: { id: number; name: string } | null;
    }>;
    geofences?: Array<{
        id: number;
        name: string;
        type: 'circle' | 'polygon';
        shape: any;
        breach_type: 'enter' | 'exit' | 'both';
    }>;
};

const typeIcons = {
    head_office: Building2,
    house: Home,
    facility: Warehouse,
};

const typeColors = {
    head_office: 'bg-status-info-bg text-status-info border-status-info/30',
    house: 'bg-status-success-bg text-status-success border-status-success/30',
    facility:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
};

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
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    value?: string | null;
    href?: string;
}) {
    const content = (
        <div className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/40">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                <Icon className="h-4 w-4" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {label}
                </div>
                <div className="truncate text-sm font-medium">
                    {value || (
                        <span className="font-normal text-muted-foreground italic">
                            Not specified
                        </span>
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

function SiteChecklistsTab({
    siteId,
    summary,
}: {
    siteId: number;
    summary?: ChecklistsSummary;
}) {
    const [assignOpen, setAssignOpen] = useState(false);
    const form = useForm({ template_id: '', frequency: 'monthly' });

    if (!summary) {
        return (
            <Card>
                <CardContent className="py-12 text-center text-sm text-muted-foreground">
                    Loading…
                </CardContent>
            </Card>
        );
    }

    const { stats, assignments, recentRuns, availableTemplates, can } = summary;
    const assignedIds = new Set(
        assignments.map((a) => a.template?.id).filter(Boolean) as number[],
    );
    const unassignedTemplates = availableTemplates.filter(
        (t) => !assignedIds.has(t.id),
    );

    const frequencyLabels: Record<string, string> = {
        once: 'One-time',
        daily: 'Daily',
        weekly: 'Weekly',
        fortnightly: 'Fortnightly',
        monthly: 'Monthly',
        quarterly: 'Quarterly',
    };

    const handleAssign = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/sites/${siteId}/checklists/assign`, {
            preserveScroll: true,
            onSuccess: () => {
                setAssignOpen(false);
                form.reset();
            },
        });
    };

    const startRun = (assignmentId: number) => {
        router.post(
            `/sites/${siteId}/checklists/assignments/${assignmentId}/run`,
            {},
            { preserveScroll: true },
        );
    };

    const formatDate = (v: string | null) =>
        v
            ? new Date(v).toLocaleDateString(undefined, {
                  month: 'short',
                  day: 'numeric',
                  year: 'numeric',
              })
            : '—';

    return (
        <div className="space-y-4">
            {/* Stat strip */}
            <div className="grid gap-3 grid-cols-2 lg:grid-cols-5">
                {[
                    {
                        label: 'Active',
                        value: stats.active_assignments,
                        tone: 'text-foreground',
                    },
                    {
                        label: 'Scheduled',
                        value: stats.scheduled,
                        tone: 'text-foreground',
                    },
                    {
                        label: 'In Progress',
                        value: stats.in_progress,
                        tone: 'text-amber-600 dark:text-amber-400',
                    },
                    {
                        label: 'Overdue',
                        value: stats.overdue,
                        tone:
                            stats.overdue > 0
                                ? 'text-rose-600 dark:text-rose-400'
                                : 'text-foreground',
                    },
                    {
                        label: 'Completed (30d)',
                        value: stats.completed_30d,
                        tone: 'text-emerald-600 dark:text-emerald-400',
                    },
                ].map((s) => (
                    <Card key={s.label}>
                        <CardContent className="p-3">
                            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                {s.label}
                            </p>
                            <p
                                className={`mt-1 text-2xl font-semibold tabular-nums ${s.tone}`}
                            >
                                {s.value}
                            </p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            {/* Assignments */}
            <Card>
                <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-3">
                    <div>
                        <CardTitle className="text-base">
                            Assigned checklists
                        </CardTitle>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            Templates active for this site
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={`/sites/${siteId}/checklists/runs`}>
                                View runs
                            </Link>
                        </Button>
                        {can.schedule && (
                            <Dialog open={assignOpen} onOpenChange={setAssignOpen}>
                                <DialogTrigger asChild>
                                    <Button size="sm">
                                        <Plus className="mr-1 h-4 w-4" />
                                        Assign template
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Assign checklist template
                                        </DialogTitle>
                                    </DialogHeader>
                                    <form
                                        onSubmit={handleAssign}
                                        className="space-y-4"
                                    >
                                        <div>
                                            <Label>Template</Label>
                                            {unassignedTemplates.length === 0 ? (
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    All applicable templates are
                                                    already assigned.{' '}
                                                    <Link
                                                        href="/sites/checklists/templates/create"
                                                        className="text-primary hover:underline"
                                                    >
                                                        Create a new template
                                                    </Link>
                                                </p>
                                            ) : (
                                                <Select
                                                    value={
                                                        form.data.template_id ||
                                                        undefined
                                                    }
                                                    onValueChange={(v) =>
                                                        form.setData(
                                                            'template_id',
                                                            v,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger className="mt-1">
                                                        <SelectValue placeholder="Choose template…" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {unassignedTemplates.map(
                                                            (t) => (
                                                                <SelectItem
                                                                    key={t.id}
                                                                    value={String(
                                                                        t.id,
                                                                    )}
                                                                >
                                                                    {t.name} (
                                                                    {t.items_count}{' '}
                                                                    items)
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            )}
                                        </div>
                                        <div>
                                            <Label>Frequency</Label>
                                            <Select
                                                value={form.data.frequency}
                                                onValueChange={(v) =>
                                                    form.setData('frequency', v)
                                                }
                                            >
                                                <SelectTrigger className="mt-1">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="once">
                                                        One-time
                                                    </SelectItem>
                                                    <SelectItem value="daily">
                                                        Daily
                                                    </SelectItem>
                                                    <SelectItem value="weekly">
                                                        Weekly
                                                    </SelectItem>
                                                    <SelectItem value="fortnightly">
                                                        Fortnightly
                                                    </SelectItem>
                                                    <SelectItem value="monthly">
                                                        Monthly
                                                    </SelectItem>
                                                    <SelectItem value="quarterly">
                                                        Quarterly
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="flex justify-end gap-2 pt-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() => setAssignOpen(false)}
                                            >
                                                Cancel
                                            </Button>
                                            <Button
                                                type="submit"
                                                disabled={
                                                    form.processing ||
                                                    !form.data.template_id
                                                }
                                            >
                                                Assign
                                            </Button>
                                        </div>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>
                </CardHeader>
                <CardContent>
                    {assignments.length === 0 ? (
                        <div className="rounded-lg border border-dashed py-8 text-center">
                            <ClipboardCheck className="mx-auto mb-2 h-10 w-10 text-muted-foreground/50" />
                            <p className="text-sm font-medium">
                                No checklists assigned yet
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                {availableTemplates.length === 0
                                    ? 'No templates are available for this site type.'
                                    : 'Assign a template to start tracking checklists.'}
                            </p>
                            {can.schedule && availableTemplates.length > 0 && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="mt-4"
                                    onClick={() => setAssignOpen(true)}
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    Assign template
                                </Button>
                            )}
                        </div>
                    ) : (
                        <div className="divide-y rounded-lg border">
                            {assignments.map((a) => (
                                <div
                                    key={a.id}
                                    className="flex items-center justify-between gap-3 px-4 py-3"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <p className="truncate text-sm font-medium">
                                                {a.template?.name ?? 'Untitled'}
                                            </p>
                                            <Badge
                                                variant="outline"
                                                className="text-[10px]"
                                            >
                                                {frequencyLabels[a.frequency] ??
                                                    a.frequency}
                                            </Badge>
                                        </div>
                                        {a.template?.description && (
                                            <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                                {a.template.description}
                                            </p>
                                        )}
                                        {a.assigned_to && (
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                Assigned to {a.assigned_to.name}
                                            </p>
                                        )}
                                    </div>
                                    {can.run && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => startRun(a.id)}
                                        >
                                            Start run
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* Recent runs */}
            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="text-base">Recent runs</CardTitle>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Latest scheduled and completed runs
                    </p>
                </CardHeader>
                <CardContent>
                    {recentRuns.length === 0 ? (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            No runs recorded yet.
                        </p>
                    ) : (
                        <div className="divide-y rounded-lg border">
                            {recentRuns.map((r) => {
                                const failed = r.items_failed > 0;
                                const isDone = r.status === 'completed';
                                const tone = r.is_overdue
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : isDone && failed
                                      ? 'text-amber-600 dark:text-amber-400'
                                      : isDone
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-muted-foreground';
                                const label = r.is_overdue
                                    ? 'Overdue'
                                    : isDone
                                      ? 'Completed'
                                      : r.status === 'in_progress'
                                        ? 'In progress'
                                        : 'Scheduled';
                                return (
                                    <Link
                                        key={r.id}
                                        href={`/sites/checklists/runs/${r.id}`}
                                        className="flex items-center justify-between gap-3 px-4 py-3 transition hover:bg-accent/40"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {r.template?.name ?? 'Untitled'}
                                            </p>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {label} · {formatDate(
                                                    isDone
                                                        ? r.completed_at
                                                        : r.scheduled_date,
                                                )}
                                                {r.completed_by && (
                                                    <> · {r.completed_by.name}</>
                                                )}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            {isDone && (
                                                <span
                                                    className={`text-xs tabular-nums ${tone}`}
                                                >
                                                    {r.items_passed}/
                                                    {r.items_passed + r.items_failed}{' '}
                                                    passed
                                                </span>
                                            )}
                                            {!isDone && (
                                                <span
                                                    className={`text-xs tabular-nums ${tone}`}
                                                >
                                                    {Math.round(
                                                        r.completion_percentage,
                                                    )}
                                                    %
                                                </span>
                                            )}
                                        </div>
                                    </Link>
                                );
                            })}
                        </div>
                    )}
                </CardContent>
            </Card>

            <div className="flex justify-end gap-2">
                <Button asChild variant="outline" size="sm">
                    <Link href="/checklists">Open global dashboard</Link>
                </Button>
                <Button asChild size="sm">
                    <Link href={`/sites/${siteId}/checklists`}>
                        Open full checklists page
                    </Link>
                </Button>
            </div>
        </div>
    );
}

export default function SiteShow({
    site,
    clients,
    assets,
    contacts,
    documents,
    checklist,
    houseLedger,
    typeSpecificData,
    vendors = [],
    staffRequirements = [],
    coverageRequirements = [],
    coveragePreview = [],
    credentialCount = 0,
    hardwareCount = 0,
    integrationStatus = [],
    can_edit,
    can: assetCan,
    fleet,
    checklistsSummary,
    siteNotes = [],
    geofences = [],
}: Props) {
    const TypeIcon = typeIcons[site.type];
    const page = usePage<any>();
    const canGlobal = page.props?.auth?.can;
    const canSeeVendorsCredentials = !!(
        canGlobal?.vendors?.view || canGlobal?.credentials?.view
    );

    // Overview-card edit dialogs
    const [contactInfoOpen, setContactInfoOpen] = useState(false);
    const [locationOpen, setLocationOpen] = useState(false);
    const [safetyOpen, setSafetyOpen] = useState(false);
    const [noteOpen, setNoteOpen] = useState(false);

    const handleDeleteNote = (noteId: number) => {
        if (!confirm('Delete this note?')) return;
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

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
            ]}
        >
            <Head title={site.name} />

            <PageShell>
                {/* ── Hero Header ──────────────────────────────── */}
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 p-6 text-white md:p-8">
                    <div className="pointer-events-none absolute -top-16 -right-16 h-64 w-64 rounded-full bg-white/5" />
                    <div className="pointer-events-none absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-white/5" />
                    <div className="pointer-events-none absolute top-1/4 right-1/3 h-24 w-24 rounded-full bg-white/5" />

                    <div className="relative flex flex-col items-center gap-6 md:flex-row md:items-start">
                        {/* Site icon */}
                        <div className="flex h-24 w-24 shrink-0 items-center justify-center rounded-full border-4 border-white/20 bg-white/10 shadow-xl md:h-28 md:w-28">
                            <TypeIcon className="h-12 w-12 text-white md:h-14 md:w-14" />
                        </div>

                        {/* Info */}
                        <div className="flex-1 text-center md:text-left">
                            <h1 className="text-2xl font-bold md:text-3xl">
                                {site.name}
                            </h1>
                            {site.address && (
                                <p className="mt-0.5 flex items-center justify-center gap-1.5 text-sm text-white/60 md:justify-start">
                                    <MapPin className="h-3.5 w-3.5" />
                                    {site.address}
                                </p>
                            )}

                            <div className="mt-3 flex flex-wrap items-center justify-center gap-2 md:justify-start">
                                <Badge className="border-white/20 bg-white/10 text-white/90">
                                    <TypeIcon className="mr-1 h-3 w-3" />
                                    {site.display_type}
                                </Badge>
                                {site.is_high_risk && (
                                    <Badge className="border-status-warning/30 bg-status-warning-bg text-status-warning">
                                        <AlertTriangle className="mr-1 h-3 w-3" />
                                        High Risk
                                    </Badge>
                                )}
                                {site.is_high_needs && (
                                    <Badge className="border-status-warning/30 bg-status-warning-bg text-status-warning">
                                        <AlertCircle className="mr-1 h-3 w-3" />
                                        High Needs
                                    </Badge>
                                )}
                                <Badge
                                    className={
                                        site.is_active
                                            ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                            : 'border-white/20 bg-white/10 text-white/90'
                                    }
                                >
                                    {site.is_active ? 'Active' : 'Inactive'}
                                </Badge>
                                {site.region && (
                                    <Badge className="border-white/20 bg-white/10 text-white/90">
                                        {site.region}
                                    </Badge>
                                )}
                            </div>
                        </div>

                        {/* Right: Actions + KPIs */}
                        <div className="flex flex-col items-center gap-3 md:items-end">
                            <div className="flex flex-wrap gap-2">
                                {can_edit && (
                                    <Button
                                        asChild
                                        size="sm"
                                        variant="outline"
                                        className="border-white/20 bg-white/10 text-white hover:bg-white/20"
                                    >
                                        <Link href={`/sites/${site.id}/edit`}>
                                            Edit
                                        </Link>
                                    </Button>
                                )}
                            </div>

                            {/* KPI Stats */}
                            <div className="hidden gap-6 text-center md:flex">
                                <div>
                                    <p className="text-2xl font-bold">
                                        {clients.length}
                                    </p>
                                    <p className="text-xs text-white/50">
                                        Clients
                                    </p>
                                </div>
                                <div>
                                    <p className="text-2xl font-bold">
                                        {assets.length}
                                    </p>
                                    <p className="text-xs text-white/50">
                                        Assets
                                    </p>
                                </div>
                                <div>
                                    <p className="text-2xl font-bold">
                                        {contacts.length}
                                    </p>
                                    <p className="text-xs text-white/50">
                                        Contacts
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Main Tabs */}
                <Tabs defaultValue="overview" className="space-y-4">
                    <TabsList className="scrollbar-pretty flex h-auto w-full justify-start gap-1 overflow-x-auto rounded-none border-b bg-transparent p-0 pb-1">
                        <TabsTrigger
                            value="overview"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <LayoutGrid className="h-4 w-4" />
                            Overview
                        </TabsTrigger>
                        <TabsTrigger
                            value="clients"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <Users className="h-4 w-4" />
                            Clients ({clients.length})
                        </TabsTrigger>
                        <TabsTrigger
                            value="assets"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <Package className="h-4 w-4" />
                            Assets ({assets.length})
                        </TabsTrigger>
                        <TabsTrigger
                            value="contacts"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <FileText className="h-4 w-4" />
                            Contacts ({contacts.length})
                        </TabsTrigger>
                        <TabsTrigger
                            value="documents"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <FileText className="h-4 w-4" />
                            Documents ({documents.length})
                        </TabsTrigger>
                        <TabsTrigger
                            value="calendar"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <Calendar className="h-4 w-4" />
                            Calendar
                        </TabsTrigger>
                        <TabsTrigger
                            value="checklists"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <ClipboardCheck className="h-4 w-4" />
                            Checklists
                        </TabsTrigger>
                        <TabsTrigger
                            value="hazards"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <ShieldAlert className="h-4 w-4" />
                            Hazards
                        </TabsTrigger>
                        <TabsTrigger
                            value="fleet"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                            onClick={loadFleet}
                        >
                            <Car className="h-4 w-4" />
                            Fleet
                            {fleet && fleet.vehicles.length > 0 && (
                                <Badge
                                    variant="outline"
                                    className="ml-1 px-1.5 py-0 text-xs"
                                >
                                    {fleet.vehicles.length}
                                </Badge>
                            )}
                        </TabsTrigger>
                        <TabsTrigger
                            value="financials"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <DollarSign className="h-4 w-4" />
                            Financials
                        </TabsTrigger>
                        {canSeeVendorsCredentials && (
                            <TabsTrigger
                                value="vendors-credentials"
                                className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                            >
                                <Truck className="h-4 w-4" />
                                Vendors & Credentials
                            </TabsTrigger>
                        )}
                        <TabsTrigger
                            value="hardware"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <Cpu className="h-4 w-4" />
                            Hardware
                            {hardwareCount > 0 && (
                                <Badge
                                    variant="outline"
                                    className="ml-1 px-1.5 py-0 text-xs"
                                >
                                    {hardwareCount}
                                </Badge>
                            )}
                        </TabsTrigger>
                        <TabsTrigger
                            value="type-specific"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            {site.type === 'house' && (
                                <BedDouble className="h-4 w-4" />
                            )}
                            {site.type === 'head_office' && (
                                <DoorOpen className="h-4 w-4" />
                            )}
                            {site.type === 'facility' && (
                                <LayoutGrid className="h-4 w-4" />
                            )}
                            {site.type === 'house'
                                ? 'Rooms'
                                : site.type === 'head_office'
                                  ? 'Resources'
                                  : 'Zones'}
                        </TabsTrigger>
                        <TabsTrigger
                            value="staff-requirements"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <GraduationCap className="h-4 w-4" />
                            Staff Requirements
                            {staffRequirements.length > 0 && (
                                <Badge
                                    variant="outline"
                                    className="ml-1 px-1.5 py-0 text-xs"
                                >
                                    {staffRequirements.length}
                                </Badge>
                            )}
                        </TabsTrigger>
                        <TabsTrigger
                            value="shift-coverage"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <Layers className="h-4 w-4" />
                            Shift Coverage
                            {coverageRequirements.length > 0 && (
                                <Badge
                                    variant="outline"
                                    className="ml-1 px-1.5 py-0 text-xs"
                                >
                                    {coverageRequirements.length}
                                </Badge>
                            )}
                        </TabsTrigger>
                        <TabsTrigger
                            value="service-contexts"
                            className="inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none"
                        >
                            <Layers className="h-4 w-4" />
                            Services
                            {(site.service_contexts ?? []).length > 0 && (
                                <Badge
                                    variant="outline"
                                    className="ml-1 px-1.5 py-0 text-xs"
                                >
                                    {(site.service_contexts ?? []).length}
                                </Badge>
                            )}
                        </TabsTrigger>
                    </TabsList>

                    {/* Overview Tab */}
                    <TabsContent value="overview" className="space-y-4">
                        <div className="grid gap-4 lg:grid-cols-2">
                            {/* Contact Information */}
                            <Card className="overflow-hidden border-border/60 shadow-sm transition-shadow hover:shadow-md">
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
                                            Edit
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
                                    />
                                    <ContactRow
                                        icon={User}
                                        label="Site Lead"
                                        value={
                                            site.primary_contact?.name ||
                                            site.manager_name ||
                                            null
                                        }
                                    />
                                    <ContactRow
                                        icon={Phone}
                                        label="Manager Phone"
                                        value={site.manager_phone}
                                        href={
                                            site.manager_phone
                                                ? `tel:${site.manager_phone}`
                                                : undefined
                                        }
                                    />
                                    <ContactRow
                                        icon={Clock}
                                        label="After Hours"
                                        value={site.after_hours_phone}
                                        href={
                                            site.after_hours_phone
                                                ? `tel:${site.after_hours_phone}`
                                                : undefined
                                        }
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
                                                        <span className="text-muted-foreground italic">
                                                            Not specified
                                                        </span>
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
                                        canManage={can_edit}
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
                                            onClick={() => setSafetyOpen(true)}
                                        >
                                            <Pencil className="h-3 w-3" />
                                            Edit
                                        </Button>
                                    )}
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <div className="rounded-lg border border-border/60 p-3">
                                        <div className="flex items-start gap-2.5">
                                            <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0 text-status-warning" />
                                            <div className="min-w-0 flex-1">
                                                <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                    Emergency Plan
                                                </div>
                                                <div className="mt-1">
                                                    {site.emergency_plan_location || (
                                                        <span className="text-muted-foreground italic">
                                                            Not specified
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="rounded-lg border border-border/60 p-3">
                                        <div className="flex items-start gap-2.5">
                                            <Pill className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                            <div className="min-w-0 flex-1">
                                                <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                    Medication Storage
                                                </div>
                                                <div className="mt-1">
                                                    {site.medication_storage_location || (
                                                        <span className="text-muted-foreground italic">
                                                            Not specified
                                                        </span>
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
                                                            <button
                                                                type="button"
                                                                onClick={() => handleDeleteNote(n.id)}
                                                                className="text-status-critical hover:underline"
                                                            >
                                                                Delete
                                                            </button>
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
                        <Card>
                            <CardHeader>
                                <CardTitle>Clients at this site</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {clients.length === 0 ? (
                                    <div className="text-sm text-muted-foreground">
                                        No clients linked to this site yet.
                                    </div>
                                ) : (
                                    <div className="overflow-hidden rounded-xl border">
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/5">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Client
                                                    </th>
                                                    <th className="px-4 py-3 text-left font-medium">
                                                        Status
                                                    </th>
                                                    <th className="px-4 py-3" />
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {clients.map((c) => (
                                                    <tr
                                                        key={c.id}
                                                        className="border-b last:border-b-0 hover:bg-muted/50"
                                                    >
                                                        <td className="px-4 py-3 font-medium">
                                                            {`${c.first_name} ${c.last_name}`.trim()}
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground">
                                                            {c.status}
                                                        </td>
                                                        <td className="px-4 py-3 text-right">
                                                            <Link
                                                                href={`/clients/${c.id}`}
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
                                            href={`/assets/create?site_id=${site.id}`}
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
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>Site Calendar</CardTitle>
                                <Button asChild>
                                    <Link href={`/sites/${site.id}/calendar`}>
                                        View Full Calendar
                                    </Link>
                                </Button>
                            </CardHeader>
                            <CardContent>
                                <div className="py-8 text-center text-muted-foreground">
                                    <Calendar className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                    <p>Calendar events will appear here</p>
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="mt-4"
                                    >
                                        <Link
                                            href={`/sites/${site.id}/calendar`}
                                        >
                                            Open Calendar
                                        </Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Checklists Tab */}
                    <TabsContent value="checklists">
                        <SiteChecklistsTab
                            siteId={site.id}
                            summary={checklistsSummary}
                        />
                    </TabsContent>

                    {/* Hazards Tab */}
                    <TabsContent value="hazards">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>Hazards Register</CardTitle>
                                <Button asChild>
                                    <Link href={`/sites/${site.id}/hazards`}>
                                        View All Hazards
                                    </Link>
                                </Button>
                            </CardHeader>
                            <CardContent>
                                <div className="py-8 text-center text-muted-foreground">
                                    <ShieldAlert className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                    <p>Logged hazards and risk assessments</p>
                                    <div className="mt-4 flex justify-center gap-2">
                                        <Button asChild variant="outline">
                                            <Link
                                                href={`/sites/${site.id}/hazards`}
                                            >
                                                View Hazards
                                            </Link>
                                        </Button>
                                        <Button asChild>
                                            <Link
                                                href={`/sites/${site.id}/hazards?action=add`}
                                            >
                                                Log Hazard
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
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

                    {/* Vendors & Credentials Tab */}
                    {canSeeVendorsCredentials && (
                        <TabsContent value="vendors-credentials">
                            <div className="space-y-4">
                                <Card>
                                    <CardHeader className="flex flex-row items-center justify-between">
                                        <CardTitle className="text-base">
                                            Vendors ({vendors.length})
                                        </CardTitle>
                                        {canGlobal?.vendors?.view && (
                                            <Button asChild size="sm">
                                                <Link
                                                    href={`/sites/${site.id}/vendors`}
                                                >
                                                    Manage Vendors
                                                </Link>
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
                                            <div className="space-y-2">
                                                {vendors.map((v) => (
                                                    <div
                                                        key={v.id}
                                                        className="flex items-center justify-between rounded-lg border p-2"
                                                    >
                                                        <div>
                                                            <div className="flex items-center gap-2">
                                                                <span className="text-sm font-medium">
                                                                    {
                                                                        v.company_name
                                                                    }
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
                                                            <div className="text-xs text-muted-foreground">
                                                                {v.service_type}
                                                            </div>
                                                        </div>
                                                        {v.phone && (
                                                            <a
                                                                href={`tel:${v.phone}`}
                                                                className="text-sm text-primary hover:text-primary/70"
                                                            >
                                                                {v.phone}
                                                            </a>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader className="flex flex-row items-center justify-between">
                                        <CardTitle className="text-base">
                                            Credentials ({credentialCount})
                                        </CardTitle>
                                        {canGlobal?.credentials?.view && (
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="secondary"
                                            >
                                                <Link
                                                    href={`/sites/${site.id}/credentials`}
                                                >
                                                    Manage Credentials
                                                </Link>
                                            </Button>
                                        )}
                                    </CardHeader>
                                    <CardContent>
                                        {credentialCount === 0 ? (
                                            <p className="text-sm text-muted-foreground">
                                                No credentials stored for this
                                                site.
                                            </p>
                                        ) : (
                                            <p className="text-sm text-muted-foreground">
                                                {credentialCount} credential
                                                {credentialCount !== 1
                                                    ? 's'
                                                    : ''}{' '}
                                                securely stored. Open the
                                                Credentials Vault to view or
                                                manage them.
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>
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

                    {/* Type-Specific Tab */}
                    <TabsContent value="type-specific">
                        <TypeSpecificTab site={site} data={typeSpecificData} />
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
                </Tabs>
            </PageShell>

            <EditContactInfoDialog
                siteId={site.id}
                isOpen={contactInfoOpen}
                onClose={() => setContactInfoOpen(false)}
                initial={{
                    phone: site.phone ?? '',
                    email: site.email ?? '',
                    manager_name: site.manager_name ?? '',
                    manager_phone: site.manager_phone ?? '',
                    after_hours_phone: site.after_hours_phone ?? '',
                }}
            />
            <EditLocationDialog
                siteId={site.id}
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
        </AppLayout>
    );
}

// Sub-components for cleaner code
function ContactsTab({
    site,
    contacts,
    can_edit,
}: {
    site: Site;
    contacts: Contact[];
    can_edit: boolean;
}) {
    const [editingContactId, setEditingContactId] = useState<number | null>(
        null,
    );
    const contactForm = useForm({
        type: 'emergency',
        name: '',
        role: '',
        phone: '',
        email: '',
        is_primary: false,
        notes: '',
    });

    function startEditContact(c: Contact) {
        setEditingContactId(c.id);
        contactForm.setData({
            type: c.type || '',
            name: c.name || '',
            role: c.role || '',
            phone: c.phone || '',
            email: c.email || '',
            is_primary: !!c.is_primary,
            notes: c.notes || '',
        });
    }

    function resetContactForm() {
        setEditingContactId(null);
        contactForm.reset();
    }

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Site contacts</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {contacts.length === 0 ? (
                        <div className="text-sm text-muted-foreground">
                            No contacts yet.
                        </div>
                    ) : (
                        <div className="space-y-2">
                            {contacts.map((c) => (
                                <div
                                    key={c.id}
                                    className="rounded-xl border p-3 text-sm"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <div className="font-medium">
                                                {c.name}{' '}
                                                {c.is_primary && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-2 border-status-success/30 text-status-success"
                                                    >
                                                        Primary
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="text-muted-foreground">
                                                {[c.type, c.role]
                                                    .filter(Boolean)
                                                    .join(' • ') || '—'}
                                            </div>
                                        </div>
                                        {can_edit && (
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    variant="secondary"
                                                    size="sm"
                                                    onClick={() =>
                                                        startEditContact(c)
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                    <div className="mt-2 grid gap-1 text-muted-foreground">
                                        <div>{c.phone || '—'}</div>
                                        <div>{c.email || '—'}</div>
                                        {c.notes && (
                                            <div className="mt-1 whitespace-pre-wrap text-muted-foreground">
                                                {c.notes}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            {can_edit && (
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {editingContactId ? 'Edit contact' : 'Add contact'}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                const url = editingContactId
                                    ? `/sites/${site.id}/contacts/${editingContactId}`
                                    : `/sites/${site.id}/contacts`;
                                const method = editingContactId
                                    ? contactForm.put
                                    : contactForm.post;
                                method(url, {
                                    preserveScroll: true,
                                    onSuccess: () => resetContactForm(),
                                });
                            }}
                            className="space-y-3"
                        >
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <Label>Type</Label>
                                    <Input
                                        value={contactForm.data.type}
                                        onChange={(e) =>
                                            contactForm.setData(
                                                'type',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Role</Label>
                                    <Input
                                        value={contactForm.data.role}
                                        onChange={(e) =>
                                            contactForm.setData(
                                                'role',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Name</Label>
                                    <Input
                                        value={contactForm.data.name}
                                        onChange={(e) =>
                                            contactForm.setData(
                                                'name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Phone</Label>
                                    <Input
                                        value={contactForm.data.phone}
                                        onChange={(e) =>
                                            contactForm.setData(
                                                'phone',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Email</Label>
                                    <Input
                                        value={contactForm.data.email}
                                        onChange={(e) =>
                                            contactForm.setData(
                                                'email',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="flex items-end gap-2">
                                    <input
                                        type="checkbox"
                                        checked={contactForm.data.is_primary}
                                        onChange={(e) =>
                                            contactForm.setData(
                                                'is_primary',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    <span className="text-sm">Primary</span>
                                </div>
                            </div>
                            <div>
                                <Label>Notes</Label>
                                <Textarea
                                    value={contactForm.data.notes}
                                    onChange={(e) =>
                                        contactForm.setData(
                                            'notes',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="flex items-center gap-2">
                                <Button
                                    type="submit"
                                    disabled={contactForm.processing}
                                >
                                    {contactForm.processing
                                        ? 'Saving…'
                                        : editingContactId
                                          ? 'Save changes'
                                          : 'Add contact'}
                                </Button>
                                {editingContactId && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => resetContactForm()}
                                    >
                                        Cancel
                                    </Button>
                                )}
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}
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

function TypeSpecificTab({
    site,
    data,
}: {
    site: Site;
    data: TypeSpecificData;
}) {
    if (site.type === 'house') {
        return (
            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <CardTitle className="flex items-center gap-2">
                        <BedDouble className="h-5 w-5" />
                        Bedrooms
                    </CardTitle>
                    <Button asChild variant="outline" size="sm">
                        <Link href={`/sites/${site.id}/rooms`}>
                            Manage Rooms
                        </Link>
                    </Button>
                </CardHeader>
                <CardContent>
                    {!data.rooms || data.rooms.length === 0 ? (
                        <div className="py-8 text-center text-muted-foreground">
                            <BedDouble className="mx-auto mb-3 h-12 w-12 opacity-50" />
                            <p>No bedrooms configured yet</p>
                            <Button asChild className="mt-4">
                                <Link
                                    href={`/sites/${site.id}/edit`}
                                >
                                    Add Bedrooms
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {data.rooms.map((room) => (
                                <Card key={room.id} className="bg-muted/50">
                                    <CardContent className="p-4">
                                        <div className="font-medium">
                                            {room.name}
                                        </div>
                                        {room.assigned_client ? (
                                            <Badge
                                                variant="outline"
                                                className="mt-2 border-primary/30 text-primary/70"
                                            >
                                                Assigned:{' '}
                                                {room.assigned_client.name}
                                            </Badge>
                                        ) : (
                                            <Badge
                                                variant="outline"
                                                className="mt-2 border-border/30 text-muted-foreground"
                                            >
                                                Available
                                            </Badge>
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
            color: '#ef4444',
        },
        {
            label: 'Exact',
            value: sitePreview?.exact_windows ?? 0,
            color: '#10b981',
        },
        {
            label: 'Overstaffed',
            value: sitePreview?.overstaffed_windows ?? 0,
            color: '#f59e0b',
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
        if (!confirm('Remove this coverage requirement?')) return;
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
                                        color="#6366f1"
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
                                        color="#ef4444"
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
                                                            ? '#ef4444'
                                                            : '#f59e0b',
                                                    maxValue: Math.max(
                                                        1,
                                                        sitePreview.largest_missing_staff,
                                                    ),
                                                }),
                                            )}
                                            heightPerBar={24}
                                            color="#6366f1"
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
                                                            color: '#6366f1',
                                                            maxValue: Math.max(
                                                                alert.required_staff,
                                                                alert.assigned_staff,
                                                            ),
                                                        },
                                                        {
                                                            label: 'Assigned',
                                                            value: alert.assigned_staff,
                                                            color: '#10b981',
                                                            maxValue: Math.max(
                                                                alert.required_staff,
                                                                alert.assigned_staff,
                                                            ),
                                                        },
                                                    ]}
                                                    heightPerBar={22}
                                                    color="#6366f1"
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
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="shrink-0 text-muted-foreground hover:text-status-critical"
                                                    onClick={() =>
                                                        deleteRequirement(
                                                            requirement.id,
                                                        )
                                                    }
                                                >
                                                    Remove
                                                </Button>
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
        if (!confirm('Remove this requirement?')) return;
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
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="shrink-0 text-muted-foreground hover:text-status-critical"
                                                        onClick={() =>
                                                            deleteRequirement(
                                                                req.id,
                                                            )
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
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
