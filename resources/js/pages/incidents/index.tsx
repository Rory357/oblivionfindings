/**
 * Incidents (approved H&S drawings pg-hsn-1, 1b, 1c, 1d — Stephan 23 Oct
 * 2026): one header with four number boxes and the register filters, the
 * register views as a tier-two strip, then the view itself — every incident
 * with where it is up to (stage dots), the open follow-ups, the near-miss
 * picture, or the site manager's "Needs an owner" queue.
 */
import {
    SafetyDateFilter,
    SafetyRegisterSearch,
    SafetyWorkspaceRail,
} from '@/components/health-safety/workspace-header';
import {
    IncidentDetailDialog,
    incidentWorksafe,
    type IncidentDetail,
} from '@/components/incidents/incident-detail-dialog';
import {
    AssignIncidentOwner,
    type IncidentOwnership,
} from '@/components/incidents/incident-ownership-panel';
import { IncidentReportDialog } from '@/components/incidents/incident-report-dialog';
import {
    dateTimeWithYear,
    dateWithYear,
    daysSince,
    INCIDENT_STATUS_LABELS,
    incidentRowStage,
    incidentTypeLabel,
    plural,
    SEVERITY_LABELS,
    SEVERITY_VARIANT,
    SOURCE_LABELS,
    worksafePending,
} from '@/components/incidents/incident-stages';
import {
    RecordIdentity,
    RecordPerson,
    RecordTable,
    type RecordTableColumn,
} from '@/components/incidents/record-table';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageLayout,
} from '@/components/page';
import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import {
    PageHeaderNumber,
    PageHeaderNumbers,
} from '@/components/page/page-header-strip';
import { EntityFilter } from '@/components/rostering';
import {
    DutyClock,
    Note,
    QuietLine,
    Queue,
    Ring,
    StageDots,
    Story,
    Surface,
    Tally,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { SelectInput } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatTime } from '@/lib/datetime';
import { incidentWorkspaceHref } from '@/lib/incidents/navigation';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    CircleDot,
    Clock,
    Eye,
    FileEdit,
    LayoutList,
    ListTodo,
    MapPin,
    Plus,
    RadioTower,
    Send,
    ShieldAlert,
    SlidersHorizontal,
    User,
    UserPlus,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                               */
/* ------------------------------------------------------------------ */

export type IncidentRow = {
    ownership?: IncidentOwnership;
    id: number;
    ref: string | null;
    occurred_at: string | null;
    type: string;
    description: string | null;
    severity: string;
    status: string;
    source: string;
    interactive: boolean;
    is_notifiable: boolean;
    worksafe_notification_status: string | null;
    potential_severity: string | null;
    investigation_status: string | null;
    control_room_alert_id: number | null;
    requires_followup: boolean;
    attachments_count: number;
    open_followups_count: number;
    client: {
        id: number;
        first_name: string;
        last_name: string;
        site: string | null;
    } | null;
    reporter: { name: string } | null;
};

export type FollowupRow = {
    id: number;
    incident_id: number;
    incident_ref: string | null;
    incident_type: string | null;
    client_name: string | null;
    assigned_to: string | null;
    due_at: string | null;
    overdue: boolean;
    notes: string | null;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
    total?: number;
    from?: number | null;
    to?: number | null;
};

type Filters = {
    owner?: 'all' | 'me' | 'needs_owner';
    q: string;
    tab: string;
    severity: string | null;
    client_id: number | null;
    site_id: number | null;
    source: string | null;
    kind?: 'all' | 'incidents' | 'near_misses';
    from: string | null;
    to: string | null;
    /** 'current' hides archived Sites and removed people (the default); 'all' shows them. */
    records?: 'current' | 'all' | null;
};

type Props = {
    viewer_id: number;
    filters: Filters;
    tab: string;
    tabCounts: Record<string, number>;
    rows: Paginated<IncidentRow | FollowupRow>;
    rowsKind: 'incidents' | 'followups';
    hero: {
        period: {
            reported: { value: number; delta: number };
            open: { value: number };
            investigation: { value: number };
            closed: { value: number; delta: number };
        };
        attention: {
            followups: { value: number; overdue: number };
            review: { value: number };
            worksafe: { value: number };
            alerts: { value: number | null };
        };
    };
    nearMissInsights: {
        trend_pct: number | null;
        ratio: number | null;
        by_potential: Record<string, number>;
    };
    sites?: Array<{ id: number; name: string }> | null;
    clients?: Array<{
        id: number;
        first_name: string;
        last_name: string;
    }> | null;
    reportClients?: Array<{
        id: number;
        first_name: string;
        last_name: string;
        site_id?: number | null;
    }> | null;
    reportStaff?: Array<{ id: number; name: string }> | null;
    report?: 'incident' | 'near_miss' | null;
    reportPrefill?: { client_id: number | null; shift_id: number | null };
    can: {
        create: boolean;
        templatesManage: boolean;
        reportSafeguarding?: boolean;
        followupsManage?: boolean;
    };
    detail: IncidentDetail | null;
};

/* ------------------------------------------------------------------ */
/*  Views                                                              */
/* ------------------------------------------------------------------ */

/** The register's views. The first seven are drawn; the rest stay reachable. */
const VIEWS: { key: string; label: string; icon: GroupedProfileNavTab['icon']; drawn: boolean }[] = [
    { key: 'all', label: 'All', icon: LayoutList, drawn: true },
    { key: 'needs_owner', label: 'Needs an owner', icon: UserPlus, drawn: true },
    { key: 'open', label: 'Open', icon: CircleDot, drawn: true },
    { key: 'review', label: 'Awaiting review', icon: Clock, drawn: true },
    { key: 'followups', label: 'Follow-ups', icon: ListTodo, drawn: true },
    { key: 'near_misses', label: 'Near misses', icon: Eye, drawn: true },
    { key: 'closed', label: 'Closed', icon: CheckCircle2, drawn: true },
    { key: 'investigation', label: 'Under investigation', icon: CircleDot, drawn: false },
    { key: 'worksafe', label: 'WorkSafe-notifiable', icon: ShieldAlert, drawn: false },
    { key: 'reported_recent', label: 'Reported in the past 30 days', icon: Clock, drawn: false },
    { key: 'closed_recent', label: 'Closed in the past 30 days', icon: CheckCircle2, drawn: false },
    { key: 'worksafe_pending', label: 'WorkSafe to notify', icon: ShieldAlert, drawn: false },
];

const SEV_ORDER = ['critical', 'high', 'medium', 'low'];

function clientName(row: IncidentRow): string | null {
    return row.client
        ? `${row.client.first_name} ${row.client.last_name}`.trim()
        : null;
}

function comparedWith(delta: number): string {
    if (delta > 0) return `${delta} more than the 30 days before`;
    if (delta < 0) return `${Math.abs(delta)} fewer than the 30 days before`;
    return 'Same as the 30 days before';
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function IncidentsIndex(props: Props) {
    const { auth } = usePage<SharedData>().props;
    if (
        !Number.isSafeInteger(props.viewer_id) ||
        props.viewer_id !== auth?.user?.id
    ) {
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Home', href: '/dashboard' },
                    { title: 'Health & safety', href: '/health-safety' },
                    { title: 'Incidents', href: '/incidents' },
                ]}
            >
                <Head title="Incidents" />
                <PageLayout
                    hero={
                        <PageHeader
                            title="Incidents"
                            icon={ShieldAlert}
                            wrapTitle
                            subline="Incident information is unavailable for your current account."
                            actions={
                                <PageHeaderPrimaryButton
                                    onClick={() =>
                                        router.get(
                                            '/incidents',
                                            {},
                                            { preserveState: false },
                                        )
                                    }
                                >
                                    Reload incidents
                                </PageHeaderPrimaryButton>
                            }
                        />
                    }
                >
                    <div className="max-w-[54rem]">
                        <Note tone="warning" icon={AlertTriangle}>
                            <p role="alert">
                                Reload to check current access before opening
                                incident information or reporting.
                            </p>
                        </Note>
                    </div>
                </PageLayout>
            </AppLayout>
        );
    }
    return <CurrentActorIncidentsIndex key={props.viewer_id} {...props} />;
}

function CurrentActorIncidentsIndex({
    filters,
    tab,
    tabCounts,
    rows,
    rowsKind,
    hero,
    nearMissInsights,
    sites,
    clients,
    reportClients,
    reportStaff,
    report,
    reportPrefill,
    can,
    detail,
}: Props) {
    const [reportMode, setReportMode] = useState<
        'incident' | 'near_miss' | null
    >(report ?? null);
    const [launcherOpen, setLauncherOpen] = useState(false);
    const openReport = (m: 'incident' | 'near_miss') => {
        setLauncherOpen(false);
        setReportMode(m);
    };

    const go = (next: Partial<Filters>) =>
        router.get(
            '/incidents',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const setTab = (id: string) =>
        router.get(
            '/incidents',
            { ...filters, tab: id },
            { preserveScroll: true },
        );

    const needsOwnerView = filters.owner === 'needs_owner';
    const activeView = needsOwnerView ? 'needs_owner' : tab;
    const selectView = (key: string) => {
        if (key === activeView) return;
        router.get(
            '/incidents',
            key === 'needs_owner'
                ? { ...filters, owner: 'needs_owner', tab: 'all' }
                : {
                      ...filters,
                      tab: key,
                      owner: needsOwnerView ? 'all' : filters.owner,
                  },
            { preserveScroll: true },
        );
    };

    const registerUrl = usePage().url;
    const reportReturnParams = new URLSearchParams(registerUrl.split('?')[1]?.split('#')[0] ?? '');
    const reportReturnTo = reportReturnParams.getAll('return_to').length === 1
        && reportReturnParams.get('return_to') === '/my-day' ? '/my-day' : null;
    // New entry points open the canonical page; the legacy query remains readable
    // until all cross-module producers have moved to the dedicated destination.
    const openDetail = (id: number) =>
        router.visit(incidentWorkspaceHref(id, registerUrl));
    const closeDetail = () =>
        router.get(
            '/incidents',
            { ...filters },
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );

    const clearFilters = () =>
        router.get(
            '/incidents',
            { tab },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const moreActive = [
        !!filters.kind && filters.kind !== 'all',
        !!filters.client_id,
        !!filters.source,
        !!filters.severity,
        filters.records === 'all',
    ].filter(Boolean).length;
    const hasFilters = !!(
        filters.q ||
        filters.severity ||
        filters.client_id ||
        filters.site_id ||
        filters.source ||
        (!!filters.owner && filters.owner !== 'all') ||
        filters.from ||
        filters.to ||
        (!!filters.kind && filters.kind !== 'all') ||
        filters.records === 'all'
    );

    const p = hero.period;
    const a = hero.attention;
    const datesFiltered = !!(filters.from || filters.to);

    const views: GroupedProfileNavTab[] = VIEWS.filter(
        (view) => view.drawn || view.key === activeView,
    ).map((view) => ({
        key: view.key,
        label: view.label,
        icon: view.icon,
        count:
            view.key === 'needs_owner'
                ? needsOwnerView
                    ? tabCounts.all
                    : undefined
                : tabCounts[view.key],
    }));

    const incidentActions = (i: IncidentRow): MenuItem[] =>
        compactMenu([
            {
                label: 'View incident',
                icon: Eye,
                onClick: () => openDetail(i.id),
            },
            i.status === 'draft' && {
                label: 'Continue draft',
                icon: FileEdit,
                onClick: () => openDetail(i.id),
            },
            { separator: true },
            i.client && {
                label: 'View client',
                icon: User,
                onClick: () =>
                    router.visit(`/operations/clients/${i.client!.id}/care`),
            },
            i.control_room_alert_id && {
                label: 'View Control Room alert',
                icon: RadioTower,
                onClick: () =>
                    router.visit(
                        `/control-room/alerts/${i.control_room_alert_id}`,
                    ),
            },
            // Submitting is guided — open the incident so the submit pane shows
            // what's being locked in (no instant POST from a context menu).
            i.status === 'draft' && { separator: true },
            i.status === 'draft' && {
                label: 'Submit for review',
                icon: Send,
                onClick: () => openDetail(i.id),
            },
        ]);

    const incidentRows =
        rowsKind === 'incidents' ? (rows.data as IncidentRow[]) : [];
    const followupRows =
        rowsKind === 'followups' ? (rows.data as FollowupRow[]) : [];
    const pager = (
        <>
            <p className="text-subtle">
                {rows.total !== undefined
                    ? `Showing ${rows.data.length} of ${rows.total}`
                    : `Showing ${rows.data.length}`}
            </p>
            {rows.last_page > 1 ? <LaravelPagination links={rows.links} /> : null}
        </>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & safety', href: '/health-safety' },
                { title: 'Incidents', href: '/incidents' },
            ]}
        >
            <Head title="Incidents" />
            <PageLayout
                hero={
                    <PageHeader
                        title="Incidents"
                        icon={ShieldAlert}
                        wrapTitle
                        subline="Report incidents and follow them through until they’re closed"
                        actions={
                            <>
                                <SafetyRegisterSearch
                                    key={filters.q}
                                    query={filters.q ?? ''}
                                    onSearch={(q) => go({ q })}
                                    placeholder="Search incidents"
                                />
                                {can.templatesManage && (
                                    <PageHeaderGlassButton
                                        onClick={() =>
                                            router.visit('/incidents/templates')
                                        }
                                    >
                                        Templates
                                    </PageHeaderGlassButton>
                                )}
                                {(can.create || can.reportSafeguarding) && (
                                    <ReportLauncher
                                        open={launcherOpen}
                                        onOpenChange={setLauncherOpen}
                                        canCreate={can.create}
                                        canSafeguarding={!!can.reportSafeguarding}
                                        onReport={openReport}
                                        onSafeguarding={() =>
                                            router.visit(
                                                `/safeguarding/create?${new URLSearchParams({ return_to: registerUrl })}`,
                                            )
                                        }
                                    />
                                )}
                            </>
                        }
                        strip={
                            <PageHeaderNumbers>
                                <PageHeaderNumber
                                    label="Reported · 30 days"
                                    value={p.reported.value}
                                    caption={
                                        datesFiltered
                                            ? 'Within the selected filters'
                                            : comparedWith(p.reported.delta)
                                    }
                                    onClick={() => setTab('reported_recent')}
                                    pressed={tab === 'reported_recent'}
                                />
                                <PageHeaderNumber
                                    label="Closed · 30 days"
                                    value={p.closed.value}
                                    caption={
                                        datesFiltered
                                            ? 'Among matching incidents'
                                            : comparedWith(p.closed.delta)
                                    }
                                    onClick={() => setTab('closed_recent')}
                                    pressed={tab === 'closed_recent'}
                                />
                                <PageHeaderNumber
                                    label="WorkSafe to notify"
                                    value={a.worksafe.value}
                                    caption={
                                        a.worksafe.value > 0
                                            ? 'Notification due'
                                            : 'None waiting'
                                    }
                                    tone={
                                        a.worksafe.value > 0
                                            ? 'warning'
                                            : undefined
                                    }
                                    onClick={() => setTab('worksafe_pending')}
                                    pressed={tab === 'worksafe_pending'}
                                />
                                {a.alerts.value !== null ? (
                                    <PageHeaderNumber
                                        label="Control room alerts"
                                        value={a.alerts.value}
                                        caption="Linked and still active"
                                        tone={
                                            a.alerts.value > 0
                                                ? 'critical'
                                                : undefined
                                        }
                                        href="/control-room/alerts"
                                    />
                                ) : null}
                            </PageHeaderNumbers>
                        }
                        filters={
                            <>
                                {sites?.length ? (
                                    <EntityFilter
                                        label="Site"
                                        allLabel="All sites"
                                        items={sites}
                                        value={filters.site_id}
                                        onChange={(id) => go({ site_id: id })}
                                        onDark
                                    />
                                ) : null}
                                <PageHeaderFilterSelect
                                    label="Any owner"
                                    value={filters.owner ?? 'all'}
                                    options={[
                                        { value: 'all', label: 'Any owner' },
                                        { value: 'me', label: 'My incidents' },
                                        {
                                            value: 'needs_owner',
                                            label: 'Needs an owner',
                                        },
                                    ]}
                                    onChange={(value) =>
                                        go({ owner: value as Filters['owner'] })
                                    }
                                />
                                <SafetyDateFilter
                                    from={filters.from}
                                    to={filters.to}
                                    onChange={go}
                                />
                                <MoreFilters
                                    active={moreActive}
                                    filters={filters}
                                    tab={tab}
                                    tabCounts={tabCounts}
                                    clients={clients}
                                    onTab={setTab}
                                    onChange={go}
                                />
                                {hasFilters && (
                                    <PageHeaderFilterButton
                                        icon={X}
                                        onClick={clearFilters}
                                    >
                                        Clear filters
                                    </PageHeaderFilterButton>
                                )}
                            </>
                        }
                        rail={
                            <SafetyWorkspaceRail
                                hub="concerns"
                                current="incidents"
                            />
                        }
                    />
                }
                tabs={
                    <TierTwoTabs
                        tabs={views}
                        activeTab={activeView}
                        onTab={selectView}
                        testIdPrefix="incident-register"
                        ariaLabel="Incident views"
                        panelId="incident-register-panel"
                        // Views carry no href, so the strip renders its own buttons.
                        renderLink={() => null}
                    />
                }
            >
                <div
                    id="incident-register-panel"
                    role="tabpanel"
                    aria-label={
                        VIEWS.find((view) => view.key === activeView)?.label ??
                        'Incidents'
                    }
                    className="grid min-w-0 gap-5"
                >
                    {needsOwnerView ? (
                        <NeedsOwnerQueue
                            rows={incidentRows}
                            detail={detail}
                            filters={filters}
                            url={registerUrl}
                            onOpen={openDetail}
                        />
                    ) : rowsKind === 'followups' ? (
                        <FollowupTable
                            rows={followupRows}
                            onOpen={openDetail}
                            overdue={a.followups.overdue}
                            footer={pager}
                        />
                    ) : tab === 'near_misses' ? (
                        <>
                            <NearMissInsights insights={nearMissInsights} />
                            <IncidentTable
                                rows={incidentRows}
                                actionsFor={incidentActions}
                                onOpen={openDetail}
                                variant="near_miss"
                                footer={pager}
                            />
                        </>
                    ) : (
                        <IncidentTable
                            rows={incidentRows}
                            actionsFor={incidentActions}
                            onOpen={openDetail}
                            footer={pager}
                        />
                    )}
                </div>
            </PageLayout>

            {detail && !needsOwnerView ? (
                <IncidentDetailDialog
                    detail={detail}
                    open
                    onClose={closeDetail}
                />
            ) : null}

            {reportMode && reportClients ? (
                <IncidentReportDialog
                    open
                    mode={reportMode}
                    entryContext="incidents"
                    clients={reportClients}
                    sites={sites ?? []}
                    staff={reportStaff ?? []}
                    canManageFollowups={can.followupsManage ?? false}
                    defaults={{
                        ...reportPrefill,
                        site_id:
                            reportClients.find(
                                (client) =>
                                    client.id === reportPrefill?.client_id,
                            )?.site_id ?? null,
                    }}
                    doneLabel={reportReturnTo ? 'Back to My Day' : undefined}
                    onClose={() => {
                        setReportMode(null);
                        if (reportReturnTo) router.visit(reportReturnTo);
                    }}
                    onOpenIncident={(url) => {
                        setReportMode(null);
                        const target = new URL(url, window.location.origin);
                        const match = target.pathname.match(/^\/incidents\/([1-9]\d*)$/);
                        router.visit(reportReturnTo && target.origin === window.location.origin
                            && match && Number.isSafeInteger(Number(match[1]))
                            ? incidentWorkspaceHref(Number(match[1]), reportReturnTo) : url);
                    }}
                />
            ) : null}
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Header parts                                                       */
/* ------------------------------------------------------------------ */

function ReportLauncher({
    open,
    onOpenChange,
    canCreate,
    canSafeguarding,
    onReport,
    onSafeguarding,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    canCreate: boolean;
    canSafeguarding: boolean;
    onReport: (mode: 'incident' | 'near_miss') => void;
    onSafeguarding: () => void;
}) {
    const choice =
        'frontline-tap frontline-focus flex w-full items-start gap-2.5 rounded-md p-2.5 text-left hover:bg-muted';
    return (
        <Popover open={open} onOpenChange={onOpenChange}>
            <PopoverTrigger asChild>
                <PageHeaderPrimaryButton icon={Plus}>
                    Report incident
                </PageHeaderPrimaryButton>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                className="w-72 max-w-[calc(100vw-2rem)] p-1.5"
            >
                {canCreate && (
                    <>
                        <Button
                            unstyled
                            type="button"
                            onClick={() => onReport('incident')}
                            className={choice}
                        >
                            <ShieldAlert
                                aria-hidden
                                className="mt-0.5 size-4 shrink-0 text-status-critical"
                            />
                            <span>
                                <span className="block text-sm font-medium">
                                    An incident
                                </span>
                                <span className="text-caption block">
                                    Something happened to a client or worker.
                                </span>
                            </span>
                        </Button>
                        <Button
                            unstyled
                            type="button"
                            onClick={() => onReport('near_miss')}
                            className={choice}
                        >
                            <Eye
                                aria-hidden
                                className="mt-0.5 size-4 shrink-0 text-primary"
                            />
                            <span>
                                <span className="block text-sm font-medium">
                                    A near miss
                                </span>
                                <span className="text-caption block">
                                    An event that could have caused harm.
                                </span>
                            </span>
                        </Button>
                    </>
                )}
                {canSafeguarding && (
                    <Button
                        unstyled
                        type="button"
                        onClick={onSafeguarding}
                        className={choice}
                    >
                        <ShieldAlert
                            aria-hidden
                            className="mt-0.5 size-4 shrink-0 text-status-warning"
                        />
                        <span>
                            <span className="block text-sm font-medium">
                                A safeguarding concern
                            </span>
                            <span className="text-caption block">
                                Raise a concern about abuse, neglect or
                                exploitation.
                            </span>
                        </span>
                    </Button>
                )}
            </PopoverContent>
        </Popover>
    );
}

/** The four quieter register filters, plus every register view, in one place. */
function MoreFilters({
    active,
    filters,
    tab,
    tabCounts,
    clients,
    onTab,
    onChange,
}: {
    active: number;
    filters: Filters;
    tab: string;
    tabCounts: Record<string, number>;
    clients: Props['clients'];
    onTab: (tab: string) => void;
    onChange: (next: Partial<Filters>) => void;
}) {
    const [open, setOpen] = useState(false);
    const views = VIEWS.filter((view) => view.key !== 'needs_owner');
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <PageHeaderFilterButton icon={SlidersHorizontal} active={active > 0}>
                    {active > 0 ? `More filters · ${active}` : 'More filters'}
                </PageHeaderFilterButton>
            </PopoverTrigger>
            <PopoverContent align="start" className="grid w-80 max-w-[calc(100vw-2rem)] gap-3">
                <FilterField label="Incident view">
                    <SelectInput
                        ariaLabel="Incident view"
                        placeholder="Incident view"
                        value={tab}
                        onChange={(value) => {
                            setOpen(false);
                            onTab(value);
                        }}
                        options={views.map((view) => ({
                            value: view.key,
                            label: `${view.label} (${tabCounts[view.key] ?? 0})`,
                        }))}
                    />
                </FilterField>
                <FilterField label="Report kind">
                    <SelectInput
                        ariaLabel="Report kind"
                        placeholder="All reports"
                        value={filters.kind ?? 'all'}
                        onChange={(value) => onChange({ kind: value as Filters['kind'] })}
                        options={[
                            { value: 'all', label: 'All reports' },
                            { value: 'incidents', label: 'Incidents only' },
                            { value: 'near_misses', label: 'Near misses only' },
                        ]}
                    />
                </FilterField>
                {clients?.length ? (
                    <FilterField label="Client">
                        <EntityFilter
                            label="Client"
                            allLabel="All clients"
                            items={clients.map((client) => ({
                                id: client.id,
                                name: `${client.first_name} ${client.last_name}`.trim(),
                            }))}
                            value={filters.client_id}
                            onChange={(id) => onChange({ client_id: id })}
                        />
                    </FilterField>
                ) : null}
                <FilterField label="Source">
                    <SelectInput
                        ariaLabel="Source"
                        placeholder="All sources"
                        value={filters.source ?? 'all'}
                        onChange={(key) => onChange({ source: key === 'all' ? null : key })}
                        options={[
                            { value: 'all', label: 'All sources' },
                            ...Object.entries(SOURCE_LABELS).map(([value, label]) => ({ value, label })),
                        ]}
                    />
                </FilterField>
                <FilterField label="Records">
                    <SelectInput
                        ariaLabel="Records"
                        placeholder="Current records"
                        value={filters.records ?? 'current'}
                        onChange={(value) =>
                            onChange({ records: value === 'all' ? 'all' : null })
                        }
                        options={[
                            { value: 'current', label: 'Current records' },
                            {
                                value: 'all',
                                label: 'Include archived Sites and removed people',
                            },
                        ]}
                    />
                </FilterField>
                <FilterField label="Severity">
                    <SelectInput
                        ariaLabel="Severity"
                        placeholder="All severities"
                        value={filters.severity ?? 'all'}
                        onChange={(value) => onChange({ severity: value === 'all' ? null : value })}
                        options={[
                            { value: 'all', label: 'All severities' },
                            ...SEV_ORDER.map((value) => ({ value, label: SEVERITY_LABELS[value] })),
                        ]}
                    />
                </FilterField>
            </PopoverContent>
        </Popover>
    );
}

function FilterField({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <span className="text-caption font-semibold">{label}</span>
            {children}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Incident table                                                     */
/* ------------------------------------------------------------------ */

function ownerCell(row: IncidentRow) {
    const ownership = row.ownership;
    if (!ownership)
        return <span className="text-muted-foreground">Owner not recorded</span>;
    if (ownership.state === 'unassigned')
        return <StatusBadge variant="warning">Needs an owner</StatusBadge>;
    if (ownership.state === 'replacement_needed')
        return <StatusBadge variant="warning">Owner needs replacing</StatusBadge>;
    const name = ownership.owner?.name ?? ownership.last_owner_name;
    return name ? (
        <RecordPerson name={name} />
    ) : (
        <span className="text-muted-foreground">Owner not recorded</span>
    );
}

function severityCell(row: IncidentRow) {
    const potential = row.type === 'near_miss' && row.potential_severity;
    const key = potential ? row.potential_severity! : row.severity;
    const label = SEVERITY_LABELS[key] ?? key;
    return (
        <StatusBadge variant={SEVERITY_VARIANT[key] ?? 'neutral'}>
            {potential ? `Potential: ${label}` : label}
        </StatusBadge>
    );
}

export function IncidentTable({
    rows,
    actionsFor,
    onOpen,
    variant = 'stages',
    footer,
}: {
    rows: IncidentRow[];
    actionsFor: (row: IncidentRow) => MenuItem[];
    onOpen: (id: number) => void;
    variant?: 'stages' | 'near_miss';
    footer?: ReactNode;
}) {
    const nearMiss = variant === 'near_miss';
    const columns: RecordTableColumn<IncidentRow>[] = [
        {
            key: 'incident',
            label: 'Incident',
            className: 'min-w-[16rem] max-w-[22rem]',
            cell: (i) => (
                <RecordIdentity
                    title={
                        <Link
                            href={`/incidents/${i.id}`}
                            onClick={(event) => {
                                event.preventDefault();
                                event.stopPropagation();
                                onOpen(i.id);
                            }}
                            aria-label={`Open incident ${i.ref ?? `INC-${i.id}`}`}
                            className="rounded-sm text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {incidentTypeLabel(i.type)}
                        </Link>
                    }
                    sub={
                        <span className="line-clamp-2">
                            {i.ref ?? `INC-${i.id}`}
                            {i.description ? ` · ${i.description}` : ''}
                        </span>
                    }
                />
            ),
        },
        {
            key: 'when',
            label: 'When',
            nowrap: true,
            cell: (i) =>
                i.occurred_at ? (
                    <RecordIdentity
                        title={formatDate(i.occurred_at)}
                        sub={`${formatTime(i.occurred_at)} · ${
                            nearMiss && i.source === 'manual'
                                ? 'Reported by staff'
                                : (SOURCE_LABELS[i.source] ?? incidentTypeLabel(i.source))
                        }`}
                    />
                ) : (
                    <span className="text-muted-foreground">Time not recorded</span>
                ),
        },
        {
            key: 'client',
            label: 'Client',
            nowrap: true,
            cell: (i) =>
                clientName(i) ? (
                    <RecordIdentity title={clientName(i)} sub={i.client?.site ?? undefined} />
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        { key: 'owner', label: 'Owner', nowrap: true, cell: ownerCell },
        { key: 'severity', label: 'Severity', nowrap: true, cell: severityCell },
        ...(nearMiss
            ? ([
                  {
                      key: 'status',
                      label: 'Status',
                      nowrap: true,
                      cell: (i) => (
                          <StatusBadge
                              status={i.status}
                              label={INCIDENT_STATUS_LABELS[i.status] ?? incidentTypeLabel(i.status)}
                          />
                      ),
                  },
                  {
                      key: 'flags',
                      label: 'Flags',
                      cell: (i) => {
                          const flags = [
                              i.open_followups_count > 0 ? plural(i.open_followups_count, 'follow-up') : null,
                              i.control_room_alert_id ? 'Control room alert' : null,
                              i.is_notifiable ? 'WorkSafe-notifiable' : null,
                              i.attachments_count > 0 ? plural(i.attachments_count, 'attachment') : null,
                          ].filter(Boolean);
                          return flags.length ? flags.join(' · ') : <span className="text-muted-foreground">—</span>;
                      },
                  },
              ] satisfies RecordTableColumn<IncidentRow>[])
            : ([
                  {
                      key: 'stage',
                      label: 'Where it’s up to',
                      nowrap: true,
                      cell: (i) => {
                          const stage = incidentRowStage(i);
                          return (
                              <StageDots
                                  stage={stage.stage}
                                  of={6}
                                  label={stage.label}
                                  note={stage.note}
                                  tone={stage.tone}
                              />
                          );
                      },
                  },
              ] satisfies RecordTableColumn<IncidentRow>[])),
    ];
    return (
        <RecordTable
            title={nearMiss ? 'Near misses' : 'Incidents'}
            sub={nearMiss ? undefined : 'Newest first · click a row to open it, right-click for more'}
            ariaLabel="Incidents table"
            rows={rows}
            rowKey={(i) => i.id}
            onOpen={(i) => onOpen(i.id)}
            actionsFor={actionsFor}
            menuTitle={(i) => `${i.ref ?? `INC-${i.id}`} · ${incidentTypeLabel(i.type)}`}
            menuIcon={ShieldAlert}
            columns={columns}
            empty={
                <p className="sg-quiet">
                    <Ring tone="none" />
                    <span>No incidents match this view and its filters.</span>
                </p>
            }
            footer={rows.length ? footer : undefined}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Follow-ups worklist                                                */
/* ------------------------------------------------------------------ */

export function FollowupTable({
    rows,
    onOpen,
    overdue,
    footer,
}: {
    rows: FollowupRow[];
    onOpen: (id: number) => void;
    overdue?: number;
    footer?: ReactNode;
}) {
    return (
        <RecordTable
            title="Open follow-ups"
            sub={`${overdue !== undefined ? `${overdue} overdue · ` : ''}soonest first · click a row to open its incident`}
            ariaLabel="Incident follow-ups table"
            rows={rows}
            rowKey={(f) => f.id}
            onOpen={(f) => onOpen(f.incident_id)}
            actionsFor={(f) => [
                {
                    label: 'Open incident',
                    icon: Eye,
                    onClick: () => onOpen(f.incident_id),
                },
            ]}
            menuTitle={(f) => f.notes ?? 'Follow-up task'}
            menuIcon={ListTodo}
            columns={[
                {
                    key: 'task',
                    label: 'Follow-up',
                    className: 'min-w-[16rem]',
                    cell: (f) => (
                        <b className="font-semibold text-foreground">
                            {f.notes ?? 'Follow-up task'}
                        </b>
                    ),
                },
                {
                    key: 'incident',
                    label: 'Incident',
                    nowrap: true,
                    cell: (f) => (
                        <RecordIdentity
                            title={
                                <Link
                                    href={`/incidents/${f.incident_id}`}
                                    onClick={(event) => {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        onOpen(f.incident_id);
                                    }}
                                    aria-label={`Open incident ${f.incident_ref ?? `INC-${f.incident_id}`}`}
                                    className="rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {f.incident_ref ?? `INC-${f.incident_id}`}
                                </Link>
                            }
                            sub={f.incident_type ? incidentTypeLabel(f.incident_type) : undefined}
                        />
                    ),
                },
                {
                    key: 'client',
                    label: 'Client',
                    nowrap: true,
                    cell: (f) => f.client_name ?? <span className="text-muted-foreground">—</span>,
                },
                {
                    key: 'owner',
                    label: 'Owner',
                    nowrap: true,
                    cell: (f) =>
                        f.assigned_to ? (
                            <RecordPerson name={f.assigned_to} />
                        ) : (
                            <span className="text-muted-foreground">Unassigned</span>
                        ),
                },
                {
                    key: 'due',
                    label: 'Due',
                    nowrap: true,
                    cell: (f) =>
                        !f.due_at ? (
                            <span className="text-muted-foreground">No due date</span>
                        ) : f.overdue ? (
                            <StatusBadge variant="critical">
                                {dateTimeWithYear(f.due_at)} · overdue
                            </StatusBadge>
                        ) : (
                            <RecordIdentity title={dateWithYear(f.due_at)} sub={formatTime(f.due_at)} />
                        ),
                },
            ]}
            empty={
                <p className="sg-quiet">
                    <Ring tone="none" />
                    <span>No open follow-ups match this view and its filters.</span>
                </p>
            }
            footer={rows.length ? footer : undefined}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Near misses                                                        */
/* ------------------------------------------------------------------ */

function NearMissInsights({
    insights,
}: {
    insights: Props['nearMissInsights'];
}) {
    const counts = SEV_ORDER.map((key) => ({
        key,
        label: SEVERITY_LABELS[key],
        value: insights.by_potential[key] ?? 0,
    }));
    const total = counts.reduce((sum, item) => sum + item.value, 0);
    const max = Math.max(1, ...counts.map((item) => item.value));
    return (
        <>
            <Tally
                title="Near misses reported"
                sub="Near misses warn us before someone gets hurt"
                items={[
                    {
                        value:
                            insights.trend_pct === null
                                ? '—'
                                : `${insights.trend_pct > 0 ? '+' : ''}${insights.trend_pct}%`,
                        label: 'Change in reports · 30 days',
                        tone:
                            insights.trend_pct === null
                                ? 'none'
                                : insights.trend_pct >= 0
                                  ? 'success'
                                  : 'warning',
                    },
                    {
                        value: insights.ratio === null ? '—' : `${insights.ratio} : 1`,
                        label: 'Near misses for each incident · 90 days',
                        tone: insights.ratio === null ? 'none' : 'success',
                    },
                ]}
                calm=""
            />
            <Surface title="What could have happened">
                {total === 0 ? (
                    <p className="sg-quiet">
                        <Ring tone="none" />
                        <span>No near misses with a recorded potential severity yet.</span>
                    </p>
                ) : (
                    <ul
                        aria-label="Near misses by what could have happened"
                        className="mx-[18px] mt-3 mb-4 grid h-40 grid-cols-4 items-end gap-6 border-b border-border"
                    >
                        {counts.map((item) => (
                            <li
                                key={item.key}
                                className="flex h-full flex-col items-center justify-end gap-1.5"
                                title={`${item.label}: ${plural(item.value, 'near miss', 'near misses')}`}
                            >
                                <span className="text-sm font-semibold text-foreground tabular-nums">
                                    {item.value}
                                </span>
                                <span
                                    aria-hidden="true"
                                    className="w-10 max-w-full rounded-t-[4px] bg-[var(--chart-1)]"
                                    style={{ height: `${Math.max(item.value ? 4 : 0, (item.value / max) * 100)}%` }}
                                />
                                <span className="sr-only">
                                    {item.label}: {plural(item.value, 'near miss', 'near misses')}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                {total > 0 ? (
                    <div aria-hidden="true" className="mx-[18px] mb-4 grid grid-cols-4 gap-6 text-center">
                        {counts.map((item) => (
                            <span key={item.key} className="text-caption">
                                {item.label}
                            </span>
                        ))}
                    </div>
                ) : null}
            </Surface>
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Needs an owner — the site manager's queue                         */
/* ------------------------------------------------------------------ */

function NeedsOwnerQueue({
    rows,
    detail,
    filters,
    url,
    onOpen,
}: {
    rows: IncidentRow[];
    detail: IncidentDetail | null;
    filters: Filters;
    url: string;
    onOpen: (id: number) => void;
}) {
    const queue = [...rows].reverse(); // oldest first
    const requested = Number(new URLSearchParams(url.split('?')[1] ?? '').get('incident'));
    const selectedId = queue.some((row) => row.id === requested)
        ? requested
        : (queue[0]?.id ?? null);
    const selectedRow = queue.find((row) => row.id === selectedId) ?? null;
    const loaded = detail && detail.id === selectedId ? detail : null;
    const asked = useRef<number | null>(null);
    const select = (id: number) => {
        asked.current = id;
        router.get(
            '/incidents',
            { ...filters, incident: id },
            { preserveState: true, preserveScroll: true, replace: true, only: ['detail'] },
        );
    };
    useEffect(() => {
        // Load the chosen incident's details beside the queue (the register's detail prop).
        if (selectedId !== null && !loaded && asked.current !== selectedId) select(selectedId);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedId, loaded]);

    if (!queue.length) {
        return (
            <QuietLine
                title="Needs an owner"
                text="Every incident you can see has an owner."
            />
        );
    }
    return (
        <div className="grid min-w-0 items-start gap-5 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
            <Queue
                title="Needs an owner"
                sub="Oldest first"
                selected={selectedId}
                onSelect={(key) => select(Number(key))}
                items={queue.map((row) => {
                    const days = daysSince(row.occurred_at);
                    return {
                        key: row.id,
                        title: `${incidentTypeLabel(row.type)}${clientName(row) ? ` — ${clientName(row)}` : ''}`,
                        tone: ['high', 'critical'].includes(row.severity) ? ('critical' as const) : ('warning' as const),
                        age: days !== null ? plural(days, 'day') : undefined,
                        sub: [
                            row.ref ?? `INC-${row.id}`,
                            row.client?.site,
                            row.occurred_at ? `${formatDate(row.occurred_at)}, ${formatTime(row.occurred_at)}` : null,
                        ]
                            .filter(Boolean)
                            .join(' · '),
                        badges: (
                            <>
                                {severityCell(row)}
                                {worksafePending(row.is_notifiable, row.worksafe_notification_status) ? (
                                    <StatusBadge variant="warning">WorkSafe to notify</StatusBadge>
                                ) : null}
                                {row.ownership?.state === 'replacement_needed' ? (
                                    <StatusBadge variant="warning">Owner needs replacing</StatusBadge>
                                ) : null}
                            </>
                        ),
                    };
                })}
            />
            <div className="grid min-w-0 gap-5">
                {selectedRow && loaded ? (
                    <QueueDetail row={selectedRow} detail={loaded} onOpen={onOpen} />
                ) : selectedRow ? (
                    <QuietLine
                        title={incidentTypeLabel(selectedRow.type)}
                        text="Loading this incident…"
                        action={{ label: 'Open incident', onClick: () => onOpen(selectedRow.id) }}
                    />
                ) : null}
            </div>
        </div>
    );
}

function QueueDetail({
    row,
    detail: d,
    onOpen,
}: {
    row: IncidentRow;
    detail: IncidentDetail;
    onOpen: (id: number) => void;
}) {
    const [assigning, setAssigning] = useState(false);
    const reference = d.ref ?? `INC-${d.id}`;
    const ownership = d.ownership;
    const waited = daysSince(d.submitted_at ?? d.occurred_at);
    const worksafe = incidentWorksafe(d);
    const replacing = ownership?.state === 'replacement_needed';
    const known = d.submitted_at ?? d.occurred_at;
    return (
        <>
            <Surface tone="critical" aria-label="Chosen incident">
                <div className="flex flex-wrap items-center gap-4 px-5 py-4">
                    <span
                        aria-hidden="true"
                        className="grid size-[46px] shrink-0 place-items-center rounded-full bg-status-critical-bg text-status-critical ring-2 ring-status-critical/60 ring-offset-2 ring-offset-card"
                    >
                        <UserPlus className="size-5" />
                    </span>
                    <div className="grid min-w-0 flex-1 gap-0.5">
                        <span className="text-caption flex items-center gap-1.5 font-bold tracking-wide text-status-critical-foreground uppercase">
                            <Ring tone="critical" />
                            {replacing ? 'Owner needs replacing' : 'Needs an owner'}
                            {waited !== null ? ` · waiting ${plural(waited, 'day')}` : ''}
                        </span>
                        <b className="text-section-title">
                            {incidentTypeLabel(d.type)}
                            {d.client ? ` — ${`${d.client.first_name} ${d.client.last_name}`.trim()}` : ''}
                        </b>
                        <span className="text-subtle">
                            {reference} · {d.client?.site ?? 'No person linked'}
                            {d.reporter?.name ? (
                                <>
                                    {' '}· reported by{' '}
                                    <b className="font-semibold text-foreground">{d.reporter.name}</b>
                                </>
                            ) : null}
                        </span>
                    </div>
                    <div className="flex shrink-0 flex-wrap gap-2">
                        <Button variant="outline" size="sm" onClick={() => onOpen(row.id)}>
                            Open incident
                        </Button>
                        {ownership?.can_assign ? (
                            <Button size="sm" onClick={() => setAssigning(true)}>
                                {replacing ? 'Choose a new owner' : 'Assign someone'}
                            </Button>
                        ) : null}
                    </div>
                </div>
                <p className="flex items-center gap-2.5 border-t border-dashed border-border bg-muted/50 px-5 py-2.5 text-subtle">
                    <Ring tone="none" />
                    The owner finishes the manager review; then Health &amp; safety
                    accepts it for the safety review
                </p>
            </Surface>
            {worksafe.notifiable ? (
                <DutyClock
                    tone={worksafe.notified ? 'warning' : 'critical'}
                    big={(worksafe.notified ? daysSince(worksafe.notifiedAt) : daysSince(known)) ?? '—'}
                    unit="days"
                    kicker="WorkSafe NZ notifiable event"
                    title={worksafe.notified ? 'Notified — awaiting acknowledgement' : 'Not notified yet'}
                    sub={worksafe.text}
                />
            ) : null}
            <Story
                title="What happened"
                facts={[
                    ...(d.client?.site ? [{ icon: MapPin, text: d.client.site }] : []),
                    ...(d.occurred_at
                        ? [
                              {
                                  icon: Clock,
                                  text: (
                                      <>
                                          <b>{formatDate(d.occurred_at)}</b>, {formatTime(d.occurred_at)}
                                      </>
                                  ),
                              },
                          ]
                        : []),
                ]}
                paras={[
                    { h: 'What happened', t: d.description || 'Not recorded' },
                    { h: 'What staff did straight away', t: d.immediate_action_taken || 'Not recorded' },
                ]}
            />
            {assigning && ownership ? (
                <AssignIncidentOwner
                    incidentId={d.id}
                    reference={reference}
                    ownership={ownership}
                    onClose={() => setAssigning(false)}
                />
            ) : null}
        </>
    );
}
