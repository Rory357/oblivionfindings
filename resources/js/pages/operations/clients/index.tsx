/* eslint-disable no-restricted-syntax -- The floating bulk-action bar and the
 * small avatar mark are styled-native surfaces bound to semantic tokens.
 * Everything structural rides the shared contracts: the Event Horizon
 * PageHeader (components/page/page-header.tsx) and the EntityCard/EntityTable
 * list surfaces (components/lists/). */
import { AssignWorkerDialog } from '@/components/assign-worker-dialog';
import { ClientEditDialog } from '@/components/client-edit-dialog';
import { type ClientSafetySummary } from '@/components/client-safety-ribbon';
import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    type PageHeaderMeterAvatar,
    PageHeaderMeterAvatars,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
    PageLayout,
} from '@/components/page';
import { MultiEntityFilter } from '@/components/rostering/multi-entity-filter';
import AppLayout from '@/layouts/app-layout';
import { DailyNoteWizard } from '@/pages/operations/clients/dialogs/daily-note-wizard';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Archive,
    ArchiveRestore,
    BedDouble,
    Check,
    CheckCircle2,
    ClipboardCheck,
    Download,
    Eye,
    HeartHandshake,
    Home,
    LayoutGrid,
    List,
    NotebookPen,
    Pencil,
    Plus,
    Shield,
    ShieldAlert,
    UserCheck,
    UserRound,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import {
    CounterPill,
    EmptyValue,
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    type EntityMeridian,
    EntityStatusChip,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
    PersonCell,
    compactMenu,
    initialsFromName,
    useEntityContextMenu,
} from '@/components/lists';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { AddClientDialog } from './_create-dialog';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type ClientSite = { id: number; name: string };
type KeyWorker = { id: number; name: string; initials: string };
type Onboarding = {
    completed: number;
    total: number;
    percent: number;
    status: 'complete' | 'incomplete';
};

type Client = {
    id: number;
    nhi_number: string | null;
    first_name: string;
    last_name: string;
    profile_photo_url?: string | null;
    avatar?: string | null;
    status: string;
    age: number | null;
    address: string | null;
    site: ClientSite | null;
    key_worker: KeyWorker | null;
    notes_week: number;
    onboarding: Onboarding;
    has_respite: boolean;
    archived: boolean;
    mine: boolean;
    safety: ClientSafetySummary | null;
};

type TypeFilter = 'all' | 'permanent' | 'respite';

type TabKey =
    | 'all'
    | 'high-risk'
    | 'onboarding'
    | 'safeguarding'
    | 'inactive'
    | 'archived';

type Filters = {
    q: string;
    mine: boolean;
    siteIds: number[];
    type: TypeFilter;
    showArchived: boolean;
};

type Can = {
    clients?: {
        create?: boolean;
        update?: boolean;
        archive?: boolean;
        assignmentsUpdate?: boolean;
    };
    progress_notes?: { create?: boolean };
    timeline?: { create?: boolean };
};

type ClientFormOption = { id: number; name: string; site_id?: number | null };
type ServiceContextOption = { id: number; type?: string | null; name: string };

type PageProps = {
    clients: Client[];
    auth: { user?: { name?: string; role?: string | null } | null; can?: Can };
    labels?: Record<string, string>;
    // Option lists for the in-context "Add client" wizard.
    sites?: ClientFormOption[];
    serviceContexts?: ServiceContextOption[];
    keyWorkers?: ClientFormOption[];
    geofences?: ClientFormOption[];
    defaultServiceContextId?: number | null;
};

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

/** Status meridian — the record's worst ALERT state (LIST_STYLE_GUIDE.md
 *  §2.1). Record status (Active/Respite/…) never drives it. */
function meridianOf(c: Client): EntityMeridian {
    const s = c.safety;
    if (s && (s.safeguarding || s.risk_level === 'critical')) return 'critical';
    if (s && (s.critical_risks_count > 0 || s.allergies_count > 0))
        return 'warning';
    return 'success';
}

function statusOf(c: Client): { variant: StatusVariant; label: string } {
    if (c.has_respite || c.status === 'respite')
        return { variant: 'warning', label: 'Respite' };
    if (c.status === 'active') return { variant: 'success', label: 'Active' };
    return { variant: 'neutral', label: 'Inactive' };
}

function fullName(c: Client): string {
    return `${c.first_name} ${c.last_name}`;
}

/** ONE muted sub-line: id · age (LIST_STYLE_GUIDE.md identity row). */
function clientSubline(c: Client): string {
    const parts = [
        c.nhi_number ? `NHI ${c.nhi_number}` : null,
        c.age != null ? `${c.age} yrs` : null,
    ].filter(Boolean);
    return parts.length ? parts.join(' · ') : 'No NHI recorded';
}

const TAB_PREDICATES: Record<TabKey, (c: Client) => boolean> = {
    all: () => true,
    'high-risk': (c) => meridianOf(c) === 'critical',
    onboarding: (c) => c.onboarding.status !== 'complete',
    safeguarding: (c) => !!c.safety?.safeguarding,
    inactive: (c) => c.status === 'inactive',
    archived: (c) => c.archived,
};

function csvCell(value: string | number): string {
    const s = String(value ?? '');
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function exportClientsCsv(rows: Client[]) {
    const header = [
        'NHI',
        'First name',
        'Last name',
        'Age',
        'Status',
        'Home',
        'Key worker',
        'Onboarding %',
        'Address',
    ];
    const lines = rows.map((c) =>
        [
            c.nhi_number ?? '',
            c.first_name,
            c.last_name,
            c.age ?? '',
            statusOf(c).label,
            c.site?.name ?? '',
            c.key_worker?.name ?? '',
            c.onboarding.percent,
            c.address ?? '',
        ]
            .map(csvCell)
            .join(','),
    );
    const csv = [header.join(','), ...lines].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'clients.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

/* ------------------------------------------------------------------ */
/*  Small presentational pieces                                        */
/* ------------------------------------------------------------------ */

/** The person mark — circular photo, or brand-tinted initials disc. */
function ClientMark({ c, size = 40 }: { c: Client; size?: number }) {
    const photo = c.avatar ?? c.profile_photo_url;
    return (
        <span
            style={{ width: size, height: size, fontSize: size * 0.3 }}
            className="flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-primary/15 font-bold text-primary"
        >
            {photo ? (
                <img
                    src={photo}
                    alt=""
                    className="h-full w-full object-cover"
                />
            ) : (
                initialsFromName(fullName(c))
            )}
        </span>
    );
}

/** Alert/safety chips — the fixed status pairs; honest "All clear". */
function SafetyChips({ c }: { c: Client }) {
    const s = c.safety;
    if (!s?.has_any) {
        return (
            <EntityStatusChip variant="success" icon={CheckCircle2}>
                All clear
            </EntityStatusChip>
        );
    }
    return (
        <>
            {s.safeguarding ? (
                <EntityStatusChip variant="critical" icon={Shield}>
                    Safeguarding
                </EntityStatusChip>
            ) : null}
            {s.critical_risks_count > 0 ? (
                <EntityStatusChip
                    variant={
                        s.risk_level === 'critical' ? 'critical' : 'warning'
                    }
                    icon={ShieldAlert}
                >
                    {s.critical_risks_count === 1 && s.top_risk
                        ? `Risk: ${s.top_risk}`
                        : `${s.critical_risks_count} critical risks`}
                </EntityStatusChip>
            ) : null}
            {s.allergies_count > 0 ? (
                <EntityStatusChip variant="critical" icon={AlertTriangle}>
                    {s.allergies_count === 1 && s.top_allergy
                        ? `Allergy: ${s.top_allergy}`
                        : `${s.allergies_count} allergies`}
                </EntityStatusChip>
            ) : null}
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Bulk action bar                                                    */
/* ------------------------------------------------------------------ */

function BulkBar({
    count,
    total,
    onSelectAll,
    onExport,
    onClear,
}: {
    count: number;
    total: number;
    onSelectAll: () => void;
    onExport: () => void;
    onClear: () => void;
}) {
    return (
        <div className="fixed bottom-5 left-1/2 z-40 flex max-w-[calc(100vw-40px)] -translate-x-1/2 animate-in flex-wrap items-center gap-1.5 rounded-2xl border border-border bg-popover py-2 pr-2.5 pl-4 shadow-xl duration-200 fade-in slide-in-from-bottom-2">
            <span className="inline-flex items-center gap-2 text-sm font-semibold whitespace-nowrap">
                <span className="rounded-full bg-primary px-2 py-0.5 text-xs text-primary-foreground tabular-nums">
                    {count}
                </span>
                selected
            </span>
            <button
                type="button"
                onClick={onSelectAll}
                className="inline-flex h-8 items-center rounded-lg px-2.5 text-[12.5px] font-semibold text-muted-foreground hover:bg-muted"
            >
                Select all {total}
            </button>
            <span className="mx-1 h-6 w-px bg-border" />
            <button
                type="button"
                onClick={onExport}
                className="inline-flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-[12.5px] font-semibold text-foreground hover:bg-muted"
            >
                <Download className="size-4" />
                Export
            </button>
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
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function ClientsIndex() {
    const {
        clients,
        auth,
        labels,
        sites = [],
        serviceContexts = [],
        keyWorkers = [],
        geofences = [],
        defaultServiceContextId = null,
    } = usePage<PageProps>().props;
    const can = auth?.can?.clients ?? {};
    const canCreate = !!can.create;
    const canUpdate = !!can.update;
    const canArchive = !!can.archive;
    const canAddNote = !!(
        auth?.can?.progress_notes?.create || auth?.can?.timeline?.create
    );

    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const clientPlural = labels?.['client.plural'] ?? 'Clients';

    const [filters, setFilters] = useState<Filters>({
        q: '',
        mine: false,
        siteIds: [],
        type: 'all',
        showArchived: false,
    });
    const [tab, setTab] = useState<TabKey>('all');
    const [view, setView] = useState<'cards' | 'table'>('cards');
    const [selectMode, setSelectMode] = useState(false);
    const [addOpen, setAddOpen] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(() => new Set());
    const ctxMenu = useEntityContextMenu<Client>();
    const [editingClientId, setEditingClientId] = useState<number | null>(null);
    const [assignClient, setAssignClient] = useState<{
        id: number;
        name: string;
    } | null>(null);
    const [noteClient, setNoteClient] = useState<{
        id: number;
        name: string;
        nhi: string | null;
    } | null>(null);

    const setFilter = <K extends keyof Filters>(key: K, value: Filters[K]) =>
        setFilters((f) => ({ ...f, [key]: value }));

    // Leaving select mode clears the selection.
    useEffect(() => {
        if (!selectMode) setSelected(new Set());
    }, [selectMode]);

    // The Archived tab only exists while "Archived" is on; don't strand its view.
    useEffect(() => {
        if (!filters.showArchived && tab === 'archived') setTab('all');
    }, [filters.showArchived, tab]);

    const stats = useMemo(() => {
        const live = clients.filter((c) => !c.archived);
        const siteIds = new Set(
            live.map((c) => c.site?.id).filter((v): v is number => v != null),
        );
        return {
            total: live.length,
            active: live.filter((c) => c.status === 'active').length,
            respite: live.filter((c) => c.has_respite).length,
            incomplete: live.filter((c) => c.onboarding.status !== 'complete')
                .length,
            safeguarding: live.filter((c) => c.safety?.safeguarding).length,
            highRisk: live.filter((c) => meridianOf(c) === 'critical').length,
            inactive: live.filter((c) => c.status === 'inactive').length,
            archived: clients.filter((c) => c.archived).length,
            sites: siteIds.size,
        };
    }, [clients]);

    const siteOptions = useMemo(() => {
        const map = new Map<number, string>();
        for (const c of clients) {
            if (c.site && !map.has(c.site.id)) map.set(c.site.id, c.site.name);
        }
        return Array.from(map.entries())
            .map(([id, name]) => ({ id, name }))
            .sort((a, b) => a.name.localeCompare(b.name));
    }, [clients]);

    /** Unique key workers across live clients, busiest first. */
    const keyWorkerLoad = useMemo(() => {
        const map = new Map<
            number,
            { id: number; name: string; count: number }
        >();
        for (const c of clients) {
            if (c.archived || !c.key_worker) continue;
            const entry = map.get(c.key_worker.id);
            if (entry) entry.count += 1;
            else
                map.set(c.key_worker.id, {
                    id: c.key_worker.id,
                    name: c.key_worker.name,
                    count: 1,
                });
        }
        return Array.from(map.values()).sort((a, b) => b.count - a.count);
    }, [clients]);

    const keyWorkerFaces: PageHeaderMeterAvatar[] = keyWorkerLoad
        .slice(0, 6)
        .map((kw) => ({
            id: kw.id,
            name: kw.name,
            detail: `${kw.count} ${kw.count === 1 ? 'client' : 'clients'}`,
        }));

    const filtered = useMemo(() => {
        const q = filters.q.trim().toLowerCase();
        const tabPred = TAB_PREDICATES[tab] ?? TAB_PREDICATES.all;
        return clients.filter((c) => {
            if (!tabPred(c)) return false;
            if (tab !== 'archived' && c.archived && !filters.showArchived)
                return false;
            if (filters.mine && !c.mine) return false;
            if (filters.type === 'permanent' && c.has_respite) return false;
            if (filters.type === 'respite' && !c.has_respite) return false;
            if (
                filters.siteIds.length &&
                !(c.site && filters.siteIds.includes(c.site.id))
            )
                return false;
            if (q) {
                const hay =
                    `${c.first_name} ${c.last_name} ${c.nhi_number ?? ''} ${c.site?.name ?? ''} ${c.status} ${c.key_worker?.name ?? ''}`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }, [clients, filters, tab]);

    const hasFilters = !!(
        filters.q.trim() ||
        filters.mine ||
        filters.type !== 'all' ||
        filters.siteIds.length
    );
    const clearFilters = () =>
        setFilters((f) => ({
            ...f,
            q: '',
            mine: false,
            siteIds: [],
            type: 'all',
        }));

    const toggleSel = (id: number) =>
        setSelected((prev) => {
            const n = new Set(prev);
            if (n.has(id)) n.delete(id);
            else n.add(id);
            return n;
        });
    const selectAllVisible = () =>
        setSelected(new Set(filtered.map((c) => c.id)));

    const openClient = (c: Client) =>
        router.visit(`/operations/clients/${c.id}`);

    const archiveClient = (c: Client) =>
        router.delete(`/operations/clients/${c.id}`, { preserveScroll: true });
    const restoreClient = (c: Client) =>
        router.patch(
            `/operations/clients/${c.id}/restore`,
            {},
            { preserveScroll: true },
        );

    const exportSelected = () => {
        const rows = clients.filter((c) => selected.has(c.id));
        exportClientsCsv(rows);
        toast.success(
            `Exported ${rows.length} ${rows.length === 1 ? 'client' : 'clients'} to CSV.`,
        );
    };

    // Per-client action list, shared by the kebab and the right-click menu.
    const actionsFor = (c: Client): MenuItem[] => {
        if (c.archived) {
            return compactMenu([
                canArchive && {
                    label: 'Restore client',
                    icon: ArchiveRestore,
                    onClick: () => restoreClient(c),
                },
            ]);
        }
        return compactMenu([
            {
                label: 'View full profile',
                icon: Eye,
                onClick: () => openClient(c),
            },
            {
                label: selected.has(c.id) ? 'Deselect client' : 'Select client',
                icon: Check,
                onClick: () => {
                    setSelectMode(true);
                    toggleSel(c.id);
                },
            },
            canAddNote && {
                label: 'Add daily note',
                icon: NotebookPen,
                onClick: () =>
                    setNoteClient({
                        id: c.id,
                        name: fullName(c),
                        nhi: c.nhi_number,
                    }),
            },
            { separator: true },
            can.assignmentsUpdate && {
                label: 'Assign workers',
                icon: UserCheck,
                onClick: () => setAssignClient({ id: c.id, name: fullName(c) }),
            },
            canUpdate && {
                label: 'Edit details',
                icon: Pencil,
                onClick: () => setEditingClientId(c.id),
            },
            { separator: true },
            canArchive && {
                label: 'Archive client',
                icon: Archive,
                danger: true,
                onClick: () => archiveClient(c),
            },
        ]);
    };

    const railItems: PageHeaderRailItem<TabKey>[] = [
        {
            key: 'all',
            label: `All ${clientPlural.toLowerCase()}`,
            icon: LayoutGrid,
            count: stats.total,
        },
        {
            key: 'high-risk',
            label: 'High risk',
            icon: ShieldAlert,
            count: stats.highRisk,
            alert: true,
        },
        {
            key: 'onboarding',
            label: 'Onboarding',
            icon: ClipboardCheck,
            count: stats.incomplete,
        },
        {
            key: 'safeguarding',
            label: 'Safeguarding',
            icon: Shield,
            count: stats.safeguarding,
            alert: true,
        },
        {
            key: 'inactive',
            label: 'Inactive',
            icon: X,
            count: stats.inactive,
        },
        ...(filters.showArchived
            ? [
                  {
                      key: 'archived' as TabKey,
                      label: 'Archived',
                      icon: Archive,
                      count: stats.archived,
                  },
              ]
            : []),
    ];
    const currentViewLabel =
        railItems.find((t) => t.key === tab)?.label ?? railItems[0].label;

    /* ---------------- Event Horizon header ---------------- */

    const header = (
        <PageHeader
            icon={HeartHandshake}
            title={clientPlural}
            titleChip={
                <PageHeaderStatusChip variant="success">
                    {stats.active} active
                </PageHeaderStatusChip>
            }
            subline={`Care profiles, safety and key workers · ${stats.sites} ${
                stats.sites === 1 ? 'home' : 'homes'
            } · ${stats.respite} on respite`}
            actions={
                <>
                    <PageHeaderSearch
                        value={filters.q}
                        onChange={(v) => setFilter('q', v)}
                        placeholder="Search name, NHI, key worker…"
                    />
                    <PageHeaderGlassButton
                        icon={Check}
                        active={selectMode}
                        onClick={() => setSelectMode((v) => !v)}
                    >
                        {selectMode ? 'Done selecting' : 'Select'}
                    </PageHeaderGlassButton>
                    {canCreate ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setAddOpen(true)}
                        >
                            Add {clientSingular.toLowerCase()}
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label={`Total ${clientPlural.toLowerCase()}`}
                        ariaLabel={`View all ${clientPlural.toLowerCase()}`}
                        onClick={() => setTab('all')}
                    >
                        <PageHeaderMeterBig>{stats.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {stats.sites}{' '}
                            {stats.sites === 1 ? 'home' : 'homes'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {keyWorkerLoad.length > 0 ? (
                        <PageHeaderMeterBlock
                            label="Key workers"
                            value={keyWorkerLoad.length}
                            ariaLabel="View key workers in the table"
                            onClick={() => setView('table')}
                        >
                            <PageHeaderMeterAvatars
                                people={keyWorkerFaces}
                                overflow={Math.max(
                                    0,
                                    keyWorkerLoad.length -
                                        keyWorkerFaces.length,
                                )}
                            />
                            <PageHeaderMeterCaption>
                                supporting active {clientPlural.toLowerCase()}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="High risk"
                        tone={stats.highRisk > 0 ? 'critical' : 'success'}
                        ariaLabel={`View high-risk ${clientPlural.toLowerCase()}`}
                        onClick={() => setTab('high-risk')}
                    >
                        <PageHeaderMeterBig>
                            {stats.highRisk}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {stats.highRisk > 0
                                ? 'safeguarding or critical risk'
                                : 'none flagged high risk'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Safeguarding"
                        tone={stats.safeguarding > 0 ? 'critical' : 'success'}
                        ariaLabel="View safeguarding flags"
                        onClick={() => setTab('safeguarding')}
                    >
                        <PageHeaderMeterBig>
                            {stats.safeguarding}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {stats.safeguarding > 0
                                ? 'flags to review'
                                : 'no active flags'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Onboarding"
                        ariaLabel="View onboarding in progress"
                        onClick={() => setTab('onboarding')}
                    >
                        <PageHeaderMeterBig>
                            {stats.incomplete}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            profiles to finish
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Respite"
                        ariaLabel="View respite stays"
                        onClick={() => setFilter('type', 'respite')}
                    >
                        <PageHeaderMeterBig>{stats.respite}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            on respite stays
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={BedDouble}
                        label="All types"
                        value={filters.type}
                        options={[
                            { value: 'all', label: 'All types' },
                            { value: 'permanent', label: 'Permanent only' },
                            { value: 'respite', label: 'Respite only' },
                        ]}
                        onChange={(v) => setFilter('type', v as TypeFilter)}
                    />
                    <MultiEntityFilter
                        label="Home"
                        allLabel="All homes"
                        pluralLabel="homes"
                        items={siteOptions}
                        value={filters.siteIds}
                        onChange={(next) => setFilter('siteIds', next)}
                        onDark
                        className="box-border h-[23px] rounded-[8px] px-2 py-0 text-[11.5px]"
                    />
                    <PageHeaderFilterCheck
                        label={`My ${clientPlural.toLowerCase()}`}
                        checked={filters.mine}
                        onChange={(v) => setFilter('mine', v)}
                    />
                    <PageHeaderFilterCheck
                        label="Archived"
                        checked={filters.showArchived}
                        onChange={(v) => setFilter('showArchived', v)}
                    />
                    <PageHeaderViewToggle
                        value={view}
                        onChange={setView}
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
                    items={railItems}
                    value={tab}
                    onSelect={setTab}
                    ariaLabel="Saved views"
                />
            }
        />
    );

    /* ---------------- List surfaces ---------------- */

    const renderCards = (items: Client[]) => (
        <EntityCardGrid>
            {items.map((c) => {
                const status = statusOf(c);
                return (
                    <EntityCard
                        key={c.id}
                        meridian={meridianOf(c)}
                        mark={<ClientMark c={c} />}
                        name={fullName(c)}
                        subline={clientSubline(c)}
                        actions={actionsFor(c)}
                        onOpen={c.archived ? undefined : () => openClient(c)}
                        onContextMenu={(e) => ctxMenu.open(e, c)}
                        selectMode={selectMode}
                        selected={selected.has(c.id)}
                        onToggleSelect={() => toggleSel(c.id)}
                        muted={c.archived || c.status === 'inactive'}
                        chips={
                            <>
                                {c.archived ? (
                                    <EntityStatusChip variant="neutral">
                                        Archived
                                    </EntityStatusChip>
                                ) : (
                                    <EntityStatusChip variant={status.variant}>
                                        {status.label}
                                    </EntityStatusChip>
                                )}
                                <EntityChip icon={Home}>
                                    {c.site?.name ?? 'No home'}
                                </EntityChip>
                            </>
                        }
                        alerts={<SafetyChips c={c} />}
                        footer={{
                            personName: c.key_worker?.name ?? null,
                            primary: c.key_worker?.name ?? 'No key worker',
                            secondary: `Key worker · ${c.notes_week} ${
                                c.notes_week === 1 ? 'note' : 'notes'
                            } this week`,
                        }}
                    />
                );
            })}
        </EntityCardGrid>
    );

    const tableColumns: EntityTableColumn<Client>[] = [
        {
            key: 'home',
            label: 'Home',
            width: '1.1fr',
            cell: (c) =>
                c.site ? (
                    <EntityChip icon={Home}>{c.site.name}</EntityChip>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.75fr',
            cell: (c) => {
                const status = statusOf(c);
                return (
                    <StatusBadge
                        variant={c.archived ? 'neutral' : status.variant}
                        className="rounded-[8px]"
                        label={c.archived ? 'Archived' : status.label}
                    />
                );
            },
        },
        {
            key: 'risks',
            label: 'Risks',
            width: '0.5fr',
            cell: (c) =>
                (c.safety?.critical_risks_count ?? 0) > 0 ? (
                    <CounterPill
                        tone={
                            c.safety?.risk_level === 'critical'
                                ? 'critical'
                                : 'warning'
                        }
                    >
                        {c.safety?.critical_risks_count}
                    </CounterPill>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'allergies',
            label: 'Allergies',
            width: '0.55fr',
            cell: (c) =>
                (c.safety?.allergies_count ?? 0) > 0 ? (
                    <CounterPill tone="critical">
                        {c.safety?.allergies_count}
                    </CounterPill>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'safeguarding',
            label: 'Safeguarding',
            width: '0.85fr',
            cell: (c) =>
                c.safety?.safeguarding ? (
                    <EntityStatusChip variant="critical" icon={Shield}>
                        Flagged
                    </EntityStatusChip>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'keyworker',
            label: 'Key worker',
            width: '1.1fr',
            cell: (c) => <PersonCell name={c.key_worker?.name} />,
        },
        {
            key: 'notes',
            label: 'Notes / wk',
            width: '0.55fr',
            cell: (c) =>
                c.notes_week > 0 ? (
                    <span className="text-muted-foreground tabular-nums">
                        {c.notes_week}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
    ];

    const renderTable = (items: Client[]) => (
        <EntityTable
            rows={items}
            rowKey={(c) => c.id}
            identityLabel={clientSingular}
            identity={(c) => ({
                mark: <ClientMark c={c} size={30} />,
                name: fullName(c),
                subline: clientSubline(c),
            })}
            columns={tableColumns}
            actionsFor={actionsFor}
            onOpen={(c) => {
                if (!c.archived) openClient(c);
            }}
            onRowContextMenu={(e, c) => ctxMenu.open(e, c)}
            mutedFor={(c) => c.archived || c.status === 'inactive'}
            selectMode={selectMode}
            selectedKeys={selected}
            onToggleSelect={(c) => toggleSel(c.id)}
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: clientPlural, href: '/operations/clients' },
            ]}
        >
            <Head title={clientPlural} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${filtered.length} of ${
                            tab === 'archived' ? stats.archived : stats.total
                        } shown${selectMode ? ' · tap to select' : ''}`}
                        right={
                            hasFilters ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={clearFilters}
                                    className="text-xs text-muted-foreground"
                                >
                                    <X className="size-3.5" />
                                    Clear filters
                                </Button>
                            ) : undefined
                        }
                    />

                    {filtered.length === 0 ? (
                        <EmptyState
                            icon={Users}
                            title={`No ${clientPlural.toLowerCase()} match this view`}
                            description={
                                hasFilters
                                    ? 'Try a different view or clear your filters.'
                                    : `Add a ${clientSingular.toLowerCase()} to get started.`
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={clearFilters}
                                    >
                                        <X className="size-3.5" />
                                        Clear filters
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : view === 'table' ? (
                        renderTable(filtered)
                    ) : (
                        renderCards(filtered)
                    )}
                </div>
            </PageLayout>

            {selectMode && selected.size > 0 ? (
                <BulkBar
                    count={selected.size}
                    total={filtered.length}
                    onSelectAll={selectAllVisible}
                    onExport={exportSelected}
                    onClear={() => setSelected(new Set())}
                />
            ) : null}

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={UserRound}
                    title={fullName(ctxMenu.ctx.record)}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            <ClientEditDialog
                clientId={editingClientId}
                open={editingClientId !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) setEditingClientId(null);
                }}
                siteSingular={labels?.['site.singular'] ?? 'Site'}
            />

            <AssignWorkerDialog
                client={assignClient}
                open={assignClient !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) setAssignClient(null);
                }}
            />

            {noteClient ? (
                <DailyNoteWizard
                    clientId={noteClient.id}
                    open
                    onOpenChange={(isOpen) => {
                        if (!isOpen) setNoteClient(null);
                    }}
                />
            ) : null}

            <AddClientDialog
                isOpen={addOpen}
                onClose={() => setAddOpen(false)}
                sites={sites}
                serviceContexts={serviceContexts}
                keyWorkers={keyWorkers}
                geofences={geofences}
                defaultServiceContextId={defaultServiceContextId}
                clientSingular={clientSingular}
            />
        </AppLayout>
    );
}
