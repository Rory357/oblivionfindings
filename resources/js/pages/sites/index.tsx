/* eslint-disable no-restricted-syntax -- The floating bulk-action bar and a
 * few caption-row controls are styled-native surfaces bound to semantic
 * tokens. Everything structural rides the shared contracts: the Event
 * Horizon PageHeader (components/page/page-header.tsx) and the
 * EntityCard/EntityTable list surfaces (components/lists/). */
import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDelta,
    PageHeaderMeterDonut,
    PageHeaderMeterSpark,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
    PageLayout,
} from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    Archive,
    ArchiveRestore,
    Building2,
    Calendar,
    Check,
    CheckCircle2,
    ClipboardCheck,
    Eye,
    Home,
    LayoutGrid,
    List,
    Map as MapIcon,
    MapPin,
    Pencil,
    Plus,
    ShieldAlert,
    TrendingUp,
    Warehouse,
    X,
} from 'lucide-react';
import { type ComponentType, useEffect, useMemo, useState } from 'react';

import {
    CounterPill,
    EmptyValue,
    EntityCard,
    EntityCardGrid,
    type EntityCardMetric,
    EntityChip,
    EntityContextMenu,
    type EntityMeridian,
    EntityStatusChip,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
    PersonCell,
    ProgressValue,
    compactMenu,
    useEntityContextMenu,
} from '@/components/lists';
import {
    AddSiteDialog,
    type AddSiteReferenceData,
} from '@/components/sites/add-site-dialog';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type SiteType = 'head_office' | 'house' | 'facility' | 'residential';

type Site = {
    id: number;
    name: string;
    type: SiteType;
    region?: string | null;
    address_line_1?: string | null;
    address_line_2?: string | null;
    suburb?: string | null;
    city?: string | null;
    postcode?: string | null;
    country?: string | null;
    is_active: boolean;
    archived: boolean;
    is_high_risk: boolean;
    is_high_needs: boolean;
    primary_contact?: { id: number | null; name: string } | null;
    active_clients_count?: number;
    rooms_total?: number;
    rooms_occupied?: number;
    vacancies?: number;
    open_hazards_count?: number;
    overdue_checklists_count?: number;
    open_maintenance_count?: number;
    readiness?: {
        score: number;
        critical_done: number;
        critical_total: number;
        is_active_but_incomplete: boolean;
    };
    geofence_status?: 'active' | 'inactive' | 'missing' | 'na';
};

type Filters = {
    q?: string | null;
    type?: string | null;
    status?: string | null;
    region?: string | null;
    risk?: string | null;
    manager_id?: string | null;
    audit?: string | null;
    hazards?: string | null;
    maintenance?: string | null;
    readiness?: string | null;
    service?: string | null;
    show_archived?: boolean;
    archived?: boolean;
};

type Summary = {
    total: number;
    active: number;
    inactive: number;
    incomplete: number;
    hazards: number;
    /** Hazards REPORTED per day, oldest → today (last 7 days). */
    hazards_trend: number[];
    hazards_week: number;
    hazards_week_delta: number;
    overdue: number;
    overdue_checks: number;
    overdue_maintenance: number;
    /** On-time checklist completion over the last 90 days. */
    checks_on_time: {
        on_time: number;
        total: number;
        percent: number | null;
    };
    regions: number;
    beds_total: number;
    beds_occupied: number;
    occupancy_percent: number;
    clients: number;
    archived: number;
};

type SavedViewCounts = {
    at_risk: number;
    audit_overdue: number;
    open_hazards: number;
    open_maintenance: number;
    active_incomplete: number;
    respite: number;
    inactive: number;
    archived: number;
};

type Can = {
    sites?: { create?: boolean; update?: boolean; archive?: boolean };
    calendar?: { create?: boolean };
    hazards?: { create?: boolean };
    checklists?: { run?: boolean };
};

type PageProps = {
    sites: Site[];
    filters: Filters;
    summary: Summary;
    filterOptions: {
        regions: string[];
        managers: { id: number; name: string }[];
        types: { value: string; label: string }[];
        risks: { value: string; label: string }[];
    };
    savedViewCounts: SavedViewCounts;
    addSite: AddSiteReferenceData;
    auth: { user?: { name?: string } | null; can?: Can };
    labels?: Record<string, string>;
};

type IconType = ComponentType<{ className?: string }>;

type ViewKey =
    | 'all'
    | 'at_risk'
    | 'audit_overdue'
    | 'open_hazards'
    | 'active_incomplete'
    | 'inactive'
    | 'archived';

/* ------------------------------------------------------------------ */
/*  Static maps + helpers                                              */
/* ------------------------------------------------------------------ */

const typeIcons: Record<string, IconType> = {
    head_office: Building2,
    house: Home,
    facility: Warehouse,
    residential: Home,
};

const typeLabels: Record<string, string> = {
    head_office: 'Head Office',
    house: 'House',
    facility: 'Facility',
    residential: 'Residential',
};

function addressFor(site: Site): string {
    return [site.address_line_1, site.suburb, site.city, site.postcode]
        .filter((v): v is string => typeof v === 'string' && v.trim() !== '')
        .join(', ');
}

function hazardsOf(s: Site): number {
    return s.open_hazards_count ?? 0;
}

function overdueOf(s: Site): number {
    return (s.overdue_checklists_count ?? 0) + (s.open_maintenance_count ?? 0);
}

/** Status meridian — the record's worst ALERT state (LIST_STYLE_GUIDE.md
 *  §2.1). Record status (Active/Archived/…) never drives it. */
function meridianOf(s: Site): EntityMeridian {
    if (hazardsOf(s) > 0 || s.is_high_risk) return 'critical';
    if (overdueOf(s) > 0 || s.is_high_needs) return 'warning';
    return 'success';
}

/** Drop empty/default keys; coerce booleans to 1 for the query string. */
function cleanFilters(
    f: Record<string, unknown>,
): Record<string, string | number> {
    const out: Record<string, string | number> = {};
    for (const [k, v] of Object.entries(f)) {
        if (v === null || v === undefined || v === '' || v === false) continue;
        if ((k === 'type' || k === 'region' || k === 'risk') && v === 'all')
            continue;
        if (k === 'status' && v === 'active') continue;
        out[k] = v === true ? 1 : (v as string | number);
    }
    return out;
}

/* ------------------------------------------------------------------ */
/*  Small presentational pieces                                        */
/* ------------------------------------------------------------------ */

function RiskChip({ s }: { s: Site }) {
    if (s.is_high_risk) {
        return (
            <EntityStatusChip variant="critical" icon={AlertTriangle}>
                High risk
            </EntityStatusChip>
        );
    }
    if (s.is_high_needs) {
        return (
            <EntityStatusChip variant="warning" icon={AlertCircle}>
                High needs
            </EntityStatusChip>
        );
    }
    return null;
}

function GeoDot({ status }: { status?: Site['geofence_status'] }) {
    if (status === 'active') {
        return (
            <span
                title="Geofence active"
                className="h-2 w-2 shrink-0 rounded-full bg-status-success"
            />
        );
    }
    if (status === 'inactive') {
        return (
            <span
                title="Geofence disabled"
                className="h-2 w-2 shrink-0 rounded-full bg-muted-foreground"
            />
        );
    }
    if (status === 'missing') {
        return (
            <span
                title="Geofence missing — needed for resident tracking"
                className="h-2 w-2 shrink-0 animate-pulse rounded-full bg-status-warning"
            />
        );
    }
    return null;
}

function AlertChips({ s }: { s: Site }) {
    const hazards = hazardsOf(s);
    const overdue = overdueOf(s);
    if (hazards === 0 && overdue === 0) {
        return (
            <EntityStatusChip variant="success" icon={CheckCircle2}>
                All clear
            </EntityStatusChip>
        );
    }
    return (
        <>
            {hazards > 0 ? (
                <EntityStatusChip variant="critical" icon={ShieldAlert}>
                    {hazards} {hazards === 1 ? 'hazard' : 'hazards'}
                </EntityStatusChip>
            ) : null}
            {overdue > 0 ? (
                <EntityStatusChip variant="warning" icon={ClipboardCheck}>
                    {overdue} overdue
                </EntityStatusChip>
            ) : null}
        </>
    );
}

/** The card's one metric slot: occupancy. Omitted for administrative
 *  sites (LIST_STYLE_GUIDE.md — no metric when the entity has none). */
function occupancyMetric(s: Site): EntityCardMetric | undefined {
    if (s.type === 'head_office') return undefined;
    const total = s.rooms_total ?? 0;
    if (total === 0) {
        return {
            label: 'Occupancy',
            value: (
                <span className="font-medium text-muted-foreground">
                    No beds configured
                </span>
            ),
            percent: null,
        };
    }
    const occ = s.rooms_occupied ?? 0;
    const pct = Math.round((occ / total) * 100);
    const vac = Math.max(0, total - occ);
    const full = vac === 0;
    return {
        label: 'Occupancy',
        value: (
            <>
                {occ}/{total} beds{' '}
                {full ? (
                    <span className="text-status-warning">· full</span>
                ) : (
                    <span className="text-status-success">· {vac} vacant</span>
                )}
            </>
        ),
        percent: pct,
        tone: full ? 'warning' : 'brand',
    };
}

/* ------------------------------------------------------------------ */
/*  Bulk action bar                                                    */
/* ------------------------------------------------------------------ */

function BulkBar({
    count,
    canArchive,
    onExport,
    onArchive,
    onClear,
}: {
    count: number;
    canArchive: boolean;
    onExport: () => void;
    onArchive: () => void;
    onClear: () => void;
}) {
    return (
        <div className="fixed bottom-5 left-1/2 z-40 flex max-w-[calc(100vw-40px)] -translate-x-1/2 animate-in items-center gap-1.5 rounded-2xl border border-border bg-popover py-2 pr-2.5 pl-4 shadow-xl duration-200 fade-in slide-in-from-bottom-2">
            <span className="inline-flex items-center gap-2 text-sm font-semibold">
                <span className="rounded-full bg-primary px-2 py-0.5 text-xs text-primary-foreground tabular-nums">
                    {count}
                </span>
                {count === 1 ? 'site' : 'sites'} selected
            </span>
            <span className="mx-1 h-6 w-px bg-border" />
            <button
                type="button"
                onClick={onExport}
                className="inline-flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-[12.5px] font-semibold text-foreground hover:bg-muted"
            >
                <TrendingUp className="h-4 w-4" />
                Export
            </button>
            {canArchive ? (
                <button
                    type="button"
                    onClick={onArchive}
                    className="inline-flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-[12.5px] font-semibold text-foreground hover:bg-muted"
                >
                    <Archive className="h-4 w-4" />
                    Archive
                </button>
            ) : null}
            <span className="mx-1 h-6 w-px bg-border" />
            <button
                type="button"
                onClick={onClear}
                className="inline-flex h-8 items-center rounded-lg px-2.5 text-[12.5px] font-semibold text-muted-foreground hover:bg-muted"
            >
                Clear
            </button>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  CSV export (client-side)                                           */
/* ------------------------------------------------------------------ */

function csvCell(value: string | number): string {
    const s = String(value ?? '');
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function exportSitesCsv(rows: Site[]) {
    const header = [
        'Name',
        'Type',
        'Region',
        'Status',
        'Beds',
        'Clients',
        'Site lead',
        'Open hazards',
        'Overdue',
        'Risk',
    ];
    const lines = rows.map((s) => {
        const risk = s.is_high_risk
            ? 'High risk'
            : s.is_high_needs
              ? 'High needs'
              : 'Standard';
        const beds =
            (s.rooms_total ?? 0) > 0
                ? `${s.rooms_occupied ?? 0}/${s.rooms_total}`
                : '';
        return [
            s.name,
            typeLabels[s.type] ?? s.type,
            s.region ?? '',
            s.archived ? 'Archived' : s.is_active ? 'Active' : 'Inactive',
            beds,
            s.active_clients_count ?? 0,
            s.primary_contact?.name ?? '',
            s.open_hazards_count ?? 0,
            overdueOf(s),
            risk,
        ]
            .map(csvCell)
            .join(',');
    });
    const csv = [header.join(','), ...lines].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'sites.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function SitesIndex() {
    const {
        auth,
        filters,
        filterOptions,
        savedViewCounts,
        sites,
        summary,
        labels,
        addSite,
    } = usePage<PageProps>().props;
    const can = auth?.can ?? {};
    const [addSiteOpen, setAddSiteOpen] = useState(false);
    const siteSingular = labels?.['site.singular'] ?? 'Site';
    const sitePlural = labels?.['site.plural'] ?? 'Sites';

    const [layout, setLayout] = useState<'cards' | 'table'>('cards');
    const [groupBy, setGroupBy] = useState<'none' | 'region' | 'type'>('none');
    const [selectMode, setSelectMode] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(() => new Set());
    const [search, setSearch] = useState(filters.q ?? '');
    const ctxMenu = useEntityContextMenu<Site>();

    // Keep the search box in sync when the server echoes a different query.
    useEffect(() => {
        setSearch(filters.q ?? '');
    }, [filters.q]);

    // Leaving select mode clears the selection.
    useEffect(() => {
        if (!selectMode) setSelected(new Set());
    }, [selectMode]);

    const go = (next: Record<string, unknown>) => {
        router.get('/sites', cleanFilters(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    // Merge a partial change onto the current filters (preserves the active view).
    const patchFilters = (patch: Partial<Filters>) =>
        go({ ...filters, ...patch });

    // Debounced live search.
    useEffect(() => {
        const handle = setTimeout(() => {
            if ((filters.q ?? '') !== search) {
                patchFilters({ q: search.trim() || null });
            }
        }, 350);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const activeView: ViewKey = filters.archived
        ? 'archived'
        : filters.status === 'inactive'
          ? 'inactive'
          : filters.risk === 'at_risk'
            ? 'at_risk'
            : filters.audit === 'overdue'
              ? 'audit_overdue'
              : filters.hazards === 'open'
                ? 'open_hazards'
                : filters.readiness === 'incomplete'
                  ? 'active_incomplete'
                  : 'all';

    const selectView = (key: ViewKey) => {
        const base: Record<string, unknown> = {
            q: filters.q,
            type: filters.type,
            region: filters.region,
            show_archived: filters.show_archived,
        };
        if (key === 'at_risk') base.risk = 'at_risk';
        else if (key === 'audit_overdue') base.audit = 'overdue';
        else if (key === 'open_hazards') base.hazards = 'open';
        else if (key === 'active_incomplete') base.readiness = 'incomplete';
        else if (key === 'inactive') base.status = 'inactive';
        else if (key === 'archived') {
            base.show_archived = true;
            base.archived = true;
        }
        go(base);
    };

    const setShowArchived = (on: boolean) => {
        const base: Record<string, unknown> = { ...filters, show_archived: on };
        if (!on) base.archived = false;
        go(base);
    };

    const clearFilters = () => go({ show_archived: filters.show_archived });

    const hasFilters = !!(
        filters.q ||
        filters.type ||
        filters.region ||
        filters.risk ||
        filters.audit ||
        filters.hazards ||
        filters.maintenance ||
        filters.readiness ||
        filters.service ||
        (filters.status && filters.status !== 'active') ||
        filters.archived
    );

    const toggleSel = (id: number) =>
        setSelected((prev) => {
            const n = new Set(prev);
            if (n.has(id)) n.delete(id);
            else n.add(id);
            return n;
        });

    const openSite = (s: Site) => router.visit(`/sites/${s.id}`);

    const toggleActive = (s: Site) =>
        router.patch(
            `/sites/${s.id}/active`,
            { is_active: !s.is_active },
            { preserveScroll: true },
        );
    const restoreSite = (s: Site) =>
        router.patch(`/sites/${s.id}/unarchive`, {}, { preserveScroll: true });

    const bulkArchive = () => {
        if (selected.size === 0) return;
        router.post(
            '/sites/bulk/archive',
            { ids: Array.from(selected) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected(new Set());
                    setSelectMode(false);
                },
            },
        );
    };

    const exportSelected = () =>
        exportSitesCsv(sites.filter((s) => selected.has(s.id)));

    // Per-site action list, shared by the kebab and the right-click menu.
    const actionsFor = (s: Site): MenuItem[] =>
        compactMenu([
            { label: 'Open site', icon: Eye, onClick: () => openSite(s) },
            {
                label: selected.has(s.id) ? 'Deselect site' : 'Select site',
                icon: Check,
                onClick: () => {
                    setSelectMode(true);
                    toggleSel(s.id);
                },
            },
            { separator: true },
            can.sites?.update && {
                label: 'Edit details',
                icon: Pencil,
                onClick: () => router.visit(`/sites/${s.id}/edit`),
            },
            can.calendar?.create && {
                label: 'Add calendar event',
                icon: Calendar,
                onClick: () =>
                    router.visit(`/sites/${s.id}/calendar?action=add`),
            },
            can.hazards?.create && {
                label: 'Report hazard',
                icon: ShieldAlert,
                onClick: () =>
                    router.visit(`/sites/${s.id}/hazards?action=add`),
            },
            can.checklists?.run && {
                label: 'Run checklist',
                icon: ClipboardCheck,
                onClick: () => router.visit(`/sites/${s.id}/checklists/runs`),
            },
            { separator: true },
            s.archived
                ? can.sites?.archive && {
                      label: 'Restore site',
                      icon: ArchiveRestore,
                      onClick: () => restoreSite(s),
                  }
                : can.sites?.update && {
                      label: s.is_active ? 'Mark inactive' : 'Mark active',
                      icon: s.is_active ? X : Check,
                      danger: s.is_active,
                      onClick: () => toggleActive(s),
                  },
        ]);

    const viewItems: PageHeaderRailItem<ViewKey>[] = [
        {
            key: 'all',
            label: `All ${sitePlural.toLowerCase()}`,
            icon: LayoutGrid,
            count: summary.total,
        },
        {
            key: 'at_risk',
            label: 'At risk',
            icon: AlertTriangle,
            count: savedViewCounts.at_risk,
            alert: true,
        },
        {
            key: 'audit_overdue',
            label: 'Audit overdue',
            icon: ClipboardCheck,
            count: savedViewCounts.audit_overdue,
            alert: true,
        },
        {
            key: 'open_hazards',
            label: 'Open hazards',
            icon: ShieldAlert,
            count: savedViewCounts.open_hazards,
            alert: true,
        },
        {
            key: 'active_incomplete',
            label: 'Active incomplete',
            icon: AlertCircle,
            count: savedViewCounts.active_incomplete,
        },
        {
            key: 'inactive',
            label: 'Inactive',
            icon: X,
            count: savedViewCounts.inactive,
        },
        ...(filters.show_archived
            ? [
                  {
                      key: 'archived' as ViewKey,
                      label: 'Archived',
                      icon: Archive,
                      count: savedViewCounts.archived,
                  },
              ]
            : []),
    ];
    const currentViewLabel =
        viewItems.find((v) => v.key === activeView)?.label ??
        `All ${sitePlural.toLowerCase()}`;

    const groups = useMemo(() => {
        if (groupBy === 'none') return null;
        const map = new Map<string, Site[]>();
        for (const s of sites) {
            const key =
                groupBy === 'region'
                    ? s.region || 'No region'
                    : (typeLabels[s.type] ?? s.type);
            if (!map.has(key)) map.set(key, []);
            map.get(key)!.push(s);
        }
        return Array.from(map.entries()).sort((a, b) =>
            a[0].localeCompare(b[0]),
        );
    }, [sites, groupBy]);

    const typeOptions = [
        { value: 'all', label: 'All types' },
        ...filterOptions.types.map((t) => ({ value: t.value, label: t.label })),
    ];
    const regionOptions = [
        { value: 'all', label: 'All regions' },
        ...filterOptions.regions.map((r) => ({ value: r, label: r })),
    ];
    const statusOptions = [
        { value: 'active', label: 'Active only' },
        { value: 'inactive', label: 'Inactive only' },
        { value: 'all', label: 'All statuses' },
    ];

    /* ---------------- Event Horizon header ---------------- */

    const header = (
        <PageHeader
            icon={Building2}
            title={sitePlural}
            titleChip={
                <PageHeaderStatusChip variant="success">
                    {summary.active} active
                </PageHeaderStatusChip>
            }
            subline={`Occupancy, leads and safety · ${summary.regions} ${
                summary.regions === 1 ? 'region' : 'regions'
            } · ${summary.clients} clients supported`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder={`Search ${sitePlural.toLowerCase()}, regions, leads…`}
                    />
                    <PageHeaderGlassButton
                        icon={Check}
                        active={selectMode}
                        onClick={() => setSelectMode((v) => !v)}
                    >
                        {selectMode ? 'Done selecting' : 'Select'}
                    </PageHeaderGlassButton>
                    {can.sites?.create ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setAddSiteOpen(true)}
                        >
                            Add {siteSingular.toLowerCase()}
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label={`Total ${sitePlural.toLowerCase()}`}
                        ariaLabel={`View all ${sitePlural.toLowerCase()}`}
                        onClick={() => selectView('all')}
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {summary.regions}{' '}
                            {summary.regions === 1 ? 'region' : 'regions'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {summary.beds_total > 0 ? (
                        <PageHeaderMeterBlock
                            label="Occupancy"
                            value={`${summary.beds_occupied}/${summary.beds_total} beds`}
                            ariaLabel={`View occupancy by ${siteSingular.toLowerCase()}`}
                            onClick={() => setLayout('table')}
                        >
                            <PageHeaderMeterBar
                                percent={summary.occupancy_percent}
                            />
                            <PageHeaderMeterCaption>
                                {summary.occupancy_percent}% occupied ·{' '}
                                {summary.beds_total - summary.beds_occupied}{' '}
                                available
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                    {summary.checks_on_time.percent !== null ? (
                        <PageHeaderMeterBlock
                            label="Checks on time"
                            ariaLabel="View checklist trends"
                            href="/sites/reports/checklist-trends"
                        >
                            <PageHeaderMeterDonut
                                percent={summary.checks_on_time.percent}
                                caption={
                                    <>
                                        {summary.checks_on_time.on_time} of{' '}
                                        {summary.checks_on_time.total}
                                        <br />
                                        done on time
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Open hazards"
                        value={summary.hazards}
                        tone={summary.hazards > 0 ? 'critical' : 'success'}
                        ariaLabel="View open hazards"
                        onClick={() => selectView('open_hazards')}
                    >
                        <PageHeaderMeterSpark values={summary.hazards_trend} />
                        {summary.hazards_week > 0 ? (
                            <PageHeaderMeterDelta
                                trend={
                                    summary.hazards_week_delta >= 0
                                        ? 'up'
                                        : 'down'
                                }
                                good={summary.hazards_week_delta < 0}
                            >
                                {summary.hazards_week} reported this week
                            </PageHeaderMeterDelta>
                        ) : (
                            <PageHeaderMeterCaption>
                                none reported this week
                            </PageHeaderMeterCaption>
                        )}
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overdue"
                        tone={summary.overdue > 0 ? 'warning' : 'success'}
                        ariaLabel={`View overdue items by ${siteSingular.toLowerCase()}`}
                        onClick={() => setLayout('table')}
                    >
                        <PageHeaderMeterBig>
                            {summary.overdue}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.overdue_checks} checks ·{' '}
                            {summary.overdue_maintenance} maintenance
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Incomplete"
                        ariaLabel={`View active incomplete ${sitePlural.toLowerCase()}`}
                        onClick={() => selectView('active_incomplete')}
                    >
                        <PageHeaderMeterBig>
                            {summary.incomplete}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            profiles to finish
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={Home}
                        label="All types"
                        value={filters.type ?? 'all'}
                        options={typeOptions}
                        onChange={(v) => patchFilters({ type: v })}
                    />
                    <PageHeaderFilterSelect
                        icon={MapIcon}
                        label="All regions"
                        value={filters.region ?? 'all'}
                        options={regionOptions}
                        onChange={(v) => patchFilters({ region: v })}
                    />
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status ?? 'active'}
                        allValue="active"
                        options={statusOptions}
                        onChange={(v) => patchFilters({ status: v })}
                    />
                    <PageHeaderFilterCheck
                        label="Archived"
                        checked={!!filters.show_archived}
                        onChange={setShowArchived}
                    />
                    <PageHeaderViewToggle
                        value={layout}
                        onChange={setLayout}
                        ariaLabel="Layout"
                        options={[
                            {
                                value: 'cards',
                                label: 'Cards',
                                icon: LayoutGrid,
                            },
                            { value: 'table', label: 'Table', icon: List },
                        ]}
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={viewItems}
                    value={activeView}
                    onSelect={selectView}
                    ariaLabel="Saved views"
                />
            }
        />
    );

    /* ---------------- List surfaces ---------------- */

    const renderCards = (items: Site[]) => (
        <EntityCardGrid>
            {items.map((s) => {
                const TypeIcon = typeIcons[s.type] ?? Building2;
                const clientCount = s.active_clients_count ?? 0;
                return (
                    <EntityCard
                        key={s.id}
                        meridian={meridianOf(s)}
                        icon={TypeIcon}
                        name={s.name}
                        subline={addressFor(s) || 'No address'}
                        sublineIcon={MapPin}
                        actions={actionsFor(s)}
                        onOpen={() => openSite(s)}
                        onContextMenu={(e) => ctxMenu.open(e, s)}
                        selectMode={selectMode}
                        selected={selected.has(s.id)}
                        onToggleSelect={() => toggleSel(s.id)}
                        muted={!s.is_active || s.archived}
                        chips={
                            <>
                                {!s.is_active || s.archived ? (
                                    <EntityStatusChip variant="neutral">
                                        {s.archived ? 'Archived' : 'Inactive'}
                                    </EntityStatusChip>
                                ) : null}
                                <EntityChip icon={TypeIcon}>
                                    {typeLabels[s.type] ?? s.type}
                                </EntityChip>
                                {s.region ? (
                                    <EntityChip outline>{s.region}</EntityChip>
                                ) : null}
                                <RiskChip s={s} />
                            </>
                        }
                        metric={occupancyMetric(s)}
                        alerts={<AlertChips s={s} />}
                        footer={{
                            personName: s.primary_contact?.name ?? null,
                            personIcon: Building2,
                            primary: s.primary_contact?.name ?? 'No site lead',
                            secondary: `Site lead${
                                clientCount > 0
                                    ? ` · ${clientCount} ${clientCount === 1 ? 'client' : 'clients'}`
                                    : ''
                            }`,
                            trailing: <GeoDot status={s.geofence_status} />,
                        }}
                    />
                );
            })}
        </EntityCardGrid>
    );

    const tableColumns: EntityTableColumn<Site>[] = [
        {
            key: 'type',
            label: 'Type',
            width: '0.9fr',
            cell: (s) => {
                const TypeIcon = typeIcons[s.type] ?? Building2;
                return (
                    <EntityChip icon={TypeIcon}>
                        {typeLabels[s.type] ?? s.type}
                    </EntityChip>
                );
            },
        },
        {
            key: 'capacity',
            label: 'Capacity',
            width: '1fr',
            cell: (s) => {
                const total = s.rooms_total ?? 0;
                if (s.type === 'head_office' || total === 0)
                    return <EmptyValue />;
                const occ = s.rooms_occupied ?? 0;
                const vac = Math.max(0, total - occ);
                return (
                    <ProgressValue
                        percent={Math.round((occ / total) * 100)}
                        tone={vac === 0 ? 'warning' : 'brand'}
                    >
                        {occ}/{total}{' '}
                        <span
                            className={
                                vac > 0
                                    ? 'text-status-success'
                                    : 'text-status-warning'
                            }
                        >
                            · {vac} vac
                        </span>
                    </ProgressValue>
                );
            },
        },
        {
            key: 'clients',
            label: 'Clients',
            width: '0.5fr',
            cell: (s) =>
                (s.active_clients_count ?? 0) > 0 ? (
                    <span className="text-muted-foreground tabular-nums">
                        {s.active_clients_count}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'lead',
            label: 'Site lead',
            width: '1.1fr',
            cell: (s) => <PersonCell name={s.primary_contact?.name} />,
        },
        {
            key: 'hazards',
            label: 'Hazards',
            width: '0.55fr',
            cell: (s) =>
                hazardsOf(s) > 0 ? (
                    <CounterPill tone="critical">{hazardsOf(s)}</CounterPill>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'overdue',
            label: 'Overdue',
            width: '0.55fr',
            cell: (s) =>
                overdueOf(s) > 0 ? (
                    <CounterPill tone="warning">{overdueOf(s)}</CounterPill>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'risk',
            label: 'Risk',
            width: '0.8fr',
            cell: (s) =>
                s.is_high_risk || s.is_high_needs ? (
                    <RiskChip s={s} />
                ) : (
                    <span className="text-xs text-muted-foreground">
                        Standard
                    </span>
                ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.7fr',
            cell: (s) => (
                <StatusBadge
                    variant={s.is_active ? 'success' : 'neutral'}
                    className="rounded-[8px]"
                    label={
                        s.archived
                            ? 'Archived'
                            : s.is_active
                              ? 'Active'
                              : 'Inactive'
                    }
                />
            ),
        },
    ];

    const renderTable = (items: Site[]) => (
        <EntityTable
            rows={items}
            rowKey={(s) => s.id}
            identityLabel={siteSingular}
            identity={(s) => ({
                icon: typeIcons[s.type] ?? Building2,
                name: s.name,
                subline: addressFor(s) || s.region || 'No address',
                extra: <GeoDot status={s.geofence_status} />,
            })}
            columns={tableColumns}
            actionsFor={actionsFor}
            onOpen={openSite}
            onRowContextMenu={(e, s) => ctxMenu.open(e, s)}
            mutedFor={(s) => !s.is_active || s.archived}
            selectMode={selectMode}
            selectedKeys={selected}
            onToggleSelect={(s) => toggleSel(s.id)}
        />
    );

    const captionControls = (
        <>
            <Select
                value={groupBy}
                onValueChange={(v) => setGroupBy(v as typeof groupBy)}
            >
                <SelectTrigger className="h-7 w-[132px] text-xs">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="none">No grouping</SelectItem>
                    <SelectItem value="region">Group: Region</SelectItem>
                    <SelectItem value="type">Group: Type</SelectItem>
                </SelectContent>
            </Select>
            {hasFilters ? (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={clearFilters}
                    className="text-xs text-muted-foreground"
                >
                    <X className="h-3.5 w-3.5" />
                    Clear filters
                </Button>
            ) : null}
        </>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: sitePlural, href: '/sites' },
            ]}
        >
            <Head title={sitePlural} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${sites.length} of ${
                            activeView === 'archived'
                                ? summary.archived
                                : summary.total
                        } shown${selectMode ? ' · tap to select' : ''}`}
                        right={captionControls}
                    />

                    {sites.length === 0 ? (
                        <EmptyState
                            icon={Building2}
                            title={`No ${sitePlural.toLowerCase()} match your filters`}
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : `Add a ${siteSingular.toLowerCase()} to get started.`
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={clearFilters}
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : groups ? (
                        <div className="flex flex-col gap-5">
                            {groups.map(([key, items]) => (
                                <div key={key} className="flex flex-col gap-3">
                                    <div className="flex items-center gap-2.5">
                                        <span className="text-[13px] font-semibold tracking-tight">
                                            {key}
                                        </span>
                                        <span className="rounded-[6px] bg-muted px-2 py-0.5 text-[11px] font-bold text-muted-foreground tabular-nums">
                                            {items.length}
                                        </span>
                                        <span className="h-px flex-1 bg-border" />
                                    </div>
                                    {layout === 'table'
                                        ? renderTable(items)
                                        : renderCards(items)}
                                </div>
                            ))}
                        </div>
                    ) : layout === 'table' ? (
                        renderTable(sites)
                    ) : (
                        renderCards(sites)
                    )}
                </div>
            </PageLayout>

            {selectMode && selected.size > 0 ? (
                <BulkBar
                    count={selected.size}
                    canArchive={!!can.sites?.archive}
                    onExport={exportSelected}
                    onArchive={bulkArchive}
                    onClear={() => setSelected(new Set())}
                />
            ) : null}

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={typeIcons[ctxMenu.ctx.record.type] ?? Building2}
                    title={ctxMenu.ctx.record.name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {can.sites?.create ? (
                <AddSiteDialog
                    isOpen={addSiteOpen}
                    onClose={() => setAddSiteOpen(false)}
                    {...addSite}
                    onSaved={() =>
                        router.reload({
                            only: ['sites', 'summary', 'savedViewCounts'],
                        })
                    }
                />
            ) : null}
        </AppLayout>
    );
}
