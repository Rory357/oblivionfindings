import {
    SafetyDateFilter,
    SafetyRegisterSearch,
    SafetyWorkspaceRail,
} from '@/components/health-safety/workspace-header';
import {
    IncidentDetailDialog,
    type IncidentDetail,
} from '@/components/incidents/incident-detail-dialog';
import {
    incidentOwnerLabel,
    type IncidentOwnership,
} from '@/components/incidents/incident-ownership-panel';
import { IncidentReportDialog } from '@/components/incidents/incident-report-dialog';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
} from '@/components/page';
import {
    EntityFilter,
    ShiftContextMenu,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { incidentWorkspaceHref } from '@/lib/incidents/navigation';
import { fmt, type Tone } from '@/pages/health-safety/components/hs-hero-kit';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Bot,
    Calendar,
    CheckCircle2,
    CircleDot,
    Clock,
    Cpu,
    Eye,
    FileEdit,
    LayoutList,
    ListTodo,
    MoreHorizontal,
    Paperclip,
    Plus,
    RadioTower,
    Search,
    Send,
    ShieldAlert,
    Stethoscope,
    User,
    UserCog,
    Wrench,
    X,
} from 'lucide-react';
import { useState, type MouseEvent as ReactMouseEvent } from 'react';

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
/*  Token maps                                                          */
/* ------------------------------------------------------------------ */

const SEV: Record<string, { tone: Tone; label: string }> = {
    low: { tone: 'success', label: 'Low' },
    medium: { tone: 'warning', label: 'Medium' },
    high: { tone: 'critical', label: 'High' },
    critical: { tone: 'critical', label: 'Critical' },
};

const TONE_TEXT: Record<Tone, string> = {
    success: 'text-status-success',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
    neutral: 'text-muted-foreground',
};
const TONE_BG: Record<Tone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    neutral: 'bg-muted text-muted-foreground',
};
const TONE_DOT: Record<Tone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-muted-foreground',
};

const STATUS: Record<
    string,
    { label: string; cls: string; icon: typeof Clock }
> = {
    draft: { label: 'Draft', cls: 'bg-muted text-foreground', icon: FileEdit },
    submitted: {
        label: 'Submitted',
        cls: 'bg-status-info-bg text-status-info',
        icon: Clock,
    },
    reviewed: {
        label: 'Reviewed',
        cls: 'bg-primary/10 text-primary',
        icon: CheckCircle2,
    },
    closed: {
        label: 'Closed',
        cls: 'bg-status-success-bg text-status-success',
        icon: CheckCircle2,
    },
};

const SOURCE: Record<string, { label: string; icon: typeof User }> = {
    manual: { label: 'Staff-reported', icon: User },
    control_room: { label: 'Control Room', icon: RadioTower },
    sensor: { label: 'Sensor', icon: Cpu },
    automated: { label: 'Automated', icon: Bot },
};

const SEV_ORDER = ['critical', 'high', 'medium', 'low'];

function deltaStr(d: number): string | undefined {
    if (d > 0) return `▲ ${d}`;
    if (d < 0) return `▼ ${Math.abs(d)}`;
    return undefined;
}

function titleCase(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
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
                    { title: 'Incidents', href: '/incidents' },
                ]}
            >
                <Head title="Incidents" />
                <PageHeader
                    title="Incidents"
                    icon={ShieldAlert}
                    frontline
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
                <p
                    role="alert"
                    className="text-subtle mt-5 text-status-warning-foreground"
                >
                    Reload to check current access before opening incident
                    information or reporting.
                </p>
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
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
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

    const hasFilters = !!(
        filters.q ||
        filters.severity ||
        filters.client_id ||
        filters.site_id ||
        filters.source ||
        (!!filters.owner && filters.owner !== 'all') ||
        filters.from ||
        filters.to ||
        (!!filters.kind && filters.kind !== 'all')
    );

    /* ---- hero stat clusters ---- */
    const p = hero.period;
    const a = hero.attention;

    /* ---- tabs ---- */
    const TABS: RosterTabItem[] = [
        {
            id: 'all',
            label: 'All',
            icon: LayoutList,
            tone: 'primary',
            badge: tabCounts.all || undefined,
        },
        {
            id: 'open',
            label: 'Open',
            icon: CircleDot,
            tone: 'info',
            badge: tabCounts.open || undefined,
        },
        {
            id: 'investigation',
            label: 'Under investigation',
            icon: Search,
            tone: 'primary',
            badge: tabCounts.investigation || undefined,
        },
        {
            id: 'followups',
            label: 'Open follow-ups',
            icon: ListTodo,
            tone: 'warning',
            badge: tabCounts.followups || undefined,
        },
        {
            id: 'worksafe',
            label: 'WorkSafe-notifiable',
            icon: ShieldAlert,
            tone: 'critical',
            badge: tabCounts.worksafe || undefined,
        },
        {
            id: 'near_misses',
            label: 'Near misses',
            icon: Eye,
            tone: 'success',
            badge: tabCounts.near_misses || undefined,
        },
        {
            id: 'review',
            label: 'Awaiting review',
            icon: Clock,
            tone: 'info',
            badge: tabCounts.review || undefined,
        },
        {
            id: 'closed',
            label: 'Closed',
            icon: CheckCircle2,
            tone: 'success',
            badge: tabCounts.closed || undefined,
        },
    ];

    /* ---- source filter (segmented pill on dark) ---- */
    TABS.push(
        {
            id: 'reported_recent',
            label: 'Occurred in past 30 days',
            icon: Calendar,
            tone: 'primary',
        },
        {
            id: 'closed_recent',
            label: 'Closed in past 30 days',
            icon: CheckCircle2,
            tone: 'success',
        },
        {
            id: 'worksafe_pending',
            label: 'Awaiting WorkSafe notification',
            icon: ShieldAlert,
            tone: 'warning',
        },
    );
    const SOURCE_ITEMS = [
        { key: 'all', label: 'All sources' },
        { key: 'manual', label: 'Staff' },
        { key: 'control_room', label: 'Control Room' },
        { key: 'sensor', label: 'Sensor' },
        { key: 'automated', label: 'Automated' },
    ];

    /* ---- right-click context menu (incident rows) ---- */
    const openRowCtx = (e: ReactMouseEvent, i: IncidentRow) => {
        e.preventDefault();
        e.stopPropagation();
        const sev = SEV[i.severity] ?? SEV.low;
        const clientName = i.client
            ? `${i.client.first_name} ${i.client.last_name}`.trim()
            : 'No client';
        const items: ShiftCtxItem[] = [
            {
                icon: <Eye className="h-3.5 w-3.5" />,
                label: 'View incident',
                sub: `${titleCase(i.type)}${i.occurred_at ? ` · ${formatDateTime(i.occurred_at)}` : ''}`,
                tone: 'primary',
                onClick: () => openDetail(i.id),
            },
            ...(i.status === 'draft'
                ? [
                      {
                          icon: <FileEdit className="h-3.5 w-3.5" />,
                          label: 'Continue draft',
                          sub: 'Edit & submit',
                          onClick: () => openDetail(i.id),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            { sep: true },
            ...(i.client
                ? [
                      {
                          icon: <User className="h-3.5 w-3.5" />,
                          label: 'View client',
                          sub: clientName,
                          onClick: () =>
                              router.visit(
                                  `/operations/clients/${i.client!.id}/care`,
                              ),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            ...(i.control_room_alert_id
                ? [
                      {
                          icon: <RadioTower className="h-3.5 w-3.5" />,
                          label: 'View Control Room alert',
                          onClick: () =>
                              router.visit(
                                  `/control-room/alerts/${i.control_room_alert_id}`,
                              ),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            // Submitting is guided — open the incident so the submit pane shows
            // what's being locked in (no instant POST from a context menu).
            ...(i.status === 'draft'
                ? [
                      { sep: true } satisfies ShiftCtxItem,
                      {
                          icon: <Send className="h-3.5 w-3.5" />,
                          label: 'Submit for review',
                          sub: 'Opens the incident to confirm',
                          onClick: () => openDetail(i.id),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
        ];
        const anchor = e.currentTarget.getBoundingClientRect();
        setCtx({
            x: e.clientX || anchor.left + 12,
            y: e.clientY || anchor.bottom,
            tag: (sev.label ?? i.severity).toUpperCase(),
            meta: `${clientName} · ${titleCase(i.type)}`,
            items,
        });
    };

    const incidentRows =
        rowsKind === 'incidents' ? (rows.data as IncidentRow[]) : [];
    const followupRows =
        rowsKind === 'followups' ? (rows.data as FollowupRow[]) : [];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Incidents', href: '/incidents' },
            ]}
        >
            <Head title="Incidents" />

            <div className="flex min-w-0 flex-col gap-5">
                <PageHeader
                    title="Incidents"
                    icon={ShieldAlert}
                    wrapTitle
                    frontline
                    subline={`Report and follow up safety concerns${a.alerts.value === null ? '' : ` · ${a.alerts.value} incidents linked to active Control Room alerts you can view`}`}
                    mobileSummary={`${p.open.value} open incidents · ${a.followups.value} open follow-ups`}
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
                                <Popover
                                    open={launcherOpen}
                                    onOpenChange={setLauncherOpen}
                                >
                                    <PopoverTrigger asChild>
                                        <PageHeaderPrimaryButton icon={Plus}>
                                            Report
                                        </PageHeaderPrimaryButton>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        align="end"
                                        className="w-72 max-w-[calc(100vw-2rem)] p-1.5"
                                    >
                                        {can.create && (
                                            <>
                                                <Button
                                                    unstyled
                                                    type="button"
                                                    onClick={() =>
                                                        openReport('incident')
                                                    }
                                                    className="frontline-tap frontline-focus flex w-full items-start gap-2.5 rounded-md p-2.5 text-left hover:bg-muted"
                                                >
                                                    <ShieldAlert
                                                        aria-hidden
                                                        className="mt-0.5 size-4 shrink-0 text-status-critical"
                                                    />
                                                    <span>
                                                        <span className="block text-sm font-medium">
                                                            An incident
                                                        </span>
                                                        <span className="block text-xs text-muted-foreground">
                                                            Something happened
                                                            to a client or
                                                            worker.
                                                        </span>
                                                    </span>
                                                </Button>
                                                <Button
                                                    unstyled
                                                    type="button"
                                                    onClick={() =>
                                                        openReport('near_miss')
                                                    }
                                                    className="frontline-tap frontline-focus flex w-full items-start gap-2.5 rounded-md p-2.5 text-left hover:bg-muted"
                                                >
                                                    <Eye
                                                        aria-hidden
                                                        className="mt-0.5 size-4 shrink-0 text-primary"
                                                    />
                                                    <span>
                                                        <span className="block text-sm font-medium">
                                                            A near miss
                                                        </span>
                                                        <span className="block text-xs text-muted-foreground">
                                                            An event that could
                                                            have caused harm.
                                                        </span>
                                                    </span>
                                                </Button>
                                            </>
                                        )}
                                        {can.reportSafeguarding && (
                                            <Button
                                                unstyled
                                                type="button"
                                                onClick={() =>
                                                    router.visit(
                                                        `/safeguarding/create?${new URLSearchParams({ return_to: registerUrl })}`,
                                                    )
                                                }
                                                className="frontline-tap frontline-focus flex w-full items-start gap-2.5 rounded-md p-2.5 text-left hover:bg-muted"
                                            >
                                                <ShieldAlert
                                                    aria-hidden
                                                    className="mt-0.5 size-4 shrink-0 text-status-warning"
                                                />
                                                <span>
                                                    <span className="block text-sm font-medium">
                                                        A safeguarding concern
                                                    </span>
                                                    <span className="block text-xs text-muted-foreground">
                                                        Raise a concern about
                                                        abuse, neglect or
                                                        exploitation.
                                                    </span>
                                                </span>
                                            </Button>
                                        )}
                                    </PopoverContent>
                                </Popover>
                            )}
                        </>
                    }
                    meters={
                        <div className="grid grid-cols-2 gap-px sm:grid-cols-4 xl:grid-cols-7">
                            {[
                                {
                                    label: 'Occurred · 30 days',
                                    value: p.reported.value,
                                    tab: 'reported_recent',
                                    caption:
                                        !filters.from && !filters.to
                                            ? deltaStr(p.reported.delta)
                                                ? `${deltaStr(p.reported.delta)} versus previous 30 days`
                                                : 'No change from previous 30 days'
                                            : 'Within the selected filters',
                                },
                                {
                                    label: 'Open',
                                    value: p.open.value,
                                    tab: 'open',
                                    caption: 'Not yet closed',
                                },
                                {
                                    label: 'Investigating',
                                    value: p.investigation.value,
                                    tab: 'investigation',
                                    caption: 'Pending or underway',
                                },
                                {
                                    label: 'Closed · 30 days',
                                    value: p.closed.value,
                                    tab: 'closed_recent',
                                    caption:
                                        !filters.from && !filters.to
                                            ? deltaStr(p.closed.delta)
                                                ? `${deltaStr(p.closed.delta)} versus previous 30 days`
                                                : 'No change from previous 30 days'
                                            : 'Among matching incidents',
                                },
                                {
                                    label: 'Open follow-ups',
                                    value: a.followups.value,
                                    tab: 'followups',
                                    caption: `${a.followups.overdue} overdue`,
                                },
                                {
                                    label: 'Awaiting review',
                                    value: a.review.value,
                                    tab: 'review',
                                    caption: 'Submitted incidents',
                                },
                                {
                                    label: 'WorkSafe pending',
                                    value: a.worksafe.value,
                                    tab: 'worksafe_pending',
                                    caption: 'Awaiting notification',
                                },
                            ].map((meter) => (
                                <PageHeaderMeterBlock
                                    key={meter.tab}
                                    label={meter.label}
                                    tone={
                                        meter.tab === 'worksafe_pending' &&
                                        meter.value > 0
                                            ? 'critical'
                                            : meter.tab === 'followups' &&
                                                a.followups.overdue > 0
                                              ? 'warning'
                                              : 'brand'
                                    }
                                    onClick={() => setTab(meter.tab)}
                                    pressed={tab === meter.tab}
                                >
                                    <PageHeaderMeterBig>
                                        {fmt(meter.value)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {meter.caption}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ))}
                        </div>
                    }
                    filters={
                        <>
                            <PageHeaderFilterSelect
                                label="Incident view"
                                value={tab}
                                options={TABS.map((item) => ({
                                    value: item.id,
                                    label: `${item.label} (${tabCounts[item.id] ?? 0})`,
                                }))}
                                onChange={setTab}
                            />
                            <PageHeaderFilterSelect
                                label="Incident owner"
                                value={filters.owner ?? 'all'}
                                options={[
                                    {
                                        value: 'all',
                                        label: 'All incident owners',
                                    },
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
                            <PageHeaderFilterSelect
                                label="Report kind"
                                value={filters.kind ?? 'all'}
                                options={[
                                    { value: 'all', label: 'All reports' },
                                    {
                                        value: 'incidents',
                                        label: 'Incidents only',
                                    },
                                    {
                                        value: 'near_misses',
                                        label: 'Near misses only',
                                    },
                                ]}
                                onChange={(value) =>
                                    go({ kind: value as Filters['kind'] })
                                }
                            />
                            <SafetyDateFilter
                                from={filters.from}
                                to={filters.to}
                                onChange={go}
                            />
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
                            {clients?.length ? (
                                <EntityFilter
                                    label="Client"
                                    allLabel="All clients"
                                    items={clients.map((client) => ({
                                        id: client.id,
                                        name: `${client.first_name} ${client.last_name}`.trim(),
                                    }))}
                                    value={filters.client_id}
                                    onChange={(id) => go({ client_id: id })}
                                    onDark
                                />
                            ) : null}
                            <PageHeaderFilterSelect
                                label="Source"
                                value={filters.source ?? 'all'}
                                options={SOURCE_ITEMS.map((item) => ({
                                    value: item.key,
                                    label: item.label,
                                }))}
                                onChange={(key) =>
                                    go({ source: key === 'all' ? null : key })
                                }
                            />
                            <PageHeaderFilterSelect
                                label="Severity"
                                value={filters.severity ?? 'all'}
                                options={[
                                    { value: 'all', label: 'All severities' },
                                    ...SEV_ORDER.map((value) => ({
                                        value,
                                        label: SEV[value].label,
                                    })),
                                ]}
                                onChange={(value) =>
                                    go({
                                        severity:
                                            value === 'all' ? null : value,
                                    })
                                }
                            />
                            {hasFilters && (
                                <PageHeaderGlassButton
                                    icon={X}
                                    onClick={clearFilters}
                                >
                                    Clear filters
                                </PageHeaderGlassButton>
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

                {/* ---- Near-miss insights strip ---- */}
                {tab === 'near_misses' ? (
                    <NearMissInsights insights={nearMissInsights} />
                ) : null}

                {/* ---- Rows ---- */}
                <Card>
                    <CardContent className="p-0">
                        {rowsKind === 'incidents' ? (
                            <IncidentTable
                                rows={incidentRows}
                                onRowCtx={openRowCtx}
                                onOpen={openDetail}
                            />
                        ) : (
                            <FollowupTable
                                rows={followupRows}
                                onOpen={openDetail}
                            />
                        )}
                    </CardContent>
                </Card>

                {rows.last_page > 1 ? (
                    <LaravelPagination links={rows.links} />
                ) : null}
            </div>

            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}

            {detail ? (
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
/*  Near-miss insights                                                 */
/* ------------------------------------------------------------------ */

function NearMissInsights({
    insights,
}: {
    insights: Props['nearMissInsights'];
}) {
    const total = SEV_ORDER.reduce(
        (sum, k) => sum + (insights.by_potential[k] ?? 0),
        0,
    );
    return (
        <div className="flex flex-wrap items-center gap-x-8 gap-y-3 rounded-xl border border-status-success/30 bg-status-success-bg/40 px-4 py-3">
            <div className="flex items-center gap-2">
                <Eye className="h-4 w-4 text-status-success" />
                <span className="text-sm font-semibold text-foreground">
                    Near-miss reporting
                </span>
                <span className="text-xs text-muted-foreground">
                    leading safety indicator
                </span>
            </div>
            <Metric
                label="Reporting trend (30d)"
                value={
                    insights.trend_pct === null
                        ? '—'
                        : `${insights.trend_pct > 0 ? '+' : ''}${insights.trend_pct}%`
                }
                tone={
                    insights.trend_pct !== null && insights.trend_pct >= 0
                        ? 'success'
                        : 'warning'
                }
            />
            <Metric
                label="Near-miss : incident (90d)"
                value={insights.ratio === null ? '—' : `${insights.ratio} : 1`}
                tone="success"
            />
            <div className="min-w-[180px] flex-1">
                <p className="mb-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    What could have happened
                </p>
                {total === 0 ? (
                    <p className="text-xs text-muted-foreground">
                        No near misses with a recorded potential severity yet.
                    </p>
                ) : (
                    <div className="flex flex-col gap-1">
                        {SEV_ORDER.filter(
                            (k) => (insights.by_potential[k] ?? 0) > 0,
                        ).map((k) => {
                            const v = insights.by_potential[k] ?? 0;
                            const tone = SEV[k]?.tone ?? 'neutral';
                            return (
                                <div
                                    key={k}
                                    className="flex items-center gap-2"
                                >
                                    <span className="w-16 text-xs text-muted-foreground capitalize">
                                        {k}
                                    </span>
                                    <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                        <div
                                            className={`h-full rounded-full ${TONE_DOT[tone]}`}
                                            style={{
                                                width: `${Math.round((v / total) * 100)}%`,
                                            }}
                                        />
                                    </div>
                                    <span className="w-6 text-right text-xs text-foreground tabular-nums">
                                        {v}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}

function Metric({
    label,
    value,
    tone,
}: {
    label: string;
    value: string;
    tone: Tone;
}) {
    return (
        <div>
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className={`text-lg font-bold tabular-nums ${TONE_TEXT[tone]}`}>
                {value}
            </p>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Incident table                                                     */
/* ------------------------------------------------------------------ */

export function IncidentTable({
    rows,
    onRowCtx,
    onOpen,
}: {
    rows: IncidentRow[];
    onRowCtx: (e: ReactMouseEvent, i: IncidentRow) => void;
    onOpen: (id: number) => void;
}) {
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <ShieldAlert className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                <p className="font-medium text-muted-foreground">
                    No incidents here
                </p>
                <p className="mt-1 text-sm text-muted-foreground/70">
                    Nothing matches this tab and filters.
                </p>
            </div>
        );
    }
    return (
        <>
            <ul aria-label="Incidents" className="min-w-0 divide-y md:hidden">
                {rows.map((incident) => (
                    <li key={incident.id} className="min-w-0 space-y-3 p-4">
                        <div className="flex items-start justify-between gap-2">
                            <Button
                                unstyled
                                className="frontline-tap frontline-focus min-w-0 rounded-md text-left font-semibold [overflow-wrap:anywhere] text-primary"
                                onClick={() => onOpen(incident.id)}
                                aria-label={`Open incident ${incident.ref ?? 'INC-' + incident.id}`}
                            >
                                {incident.ref ?? `INC-${incident.id}`} ·{' '}
                                {titleCase(incident.type)}
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="frontline-tap shrink-0"
                                aria-label={`Options for ${incident.ref ?? 'INC-' + incident.id}`}
                                onClick={(event) => onRowCtx(event, incident)}
                            >
                                <MoreHorizontal
                                    aria-hidden
                                    className="size-4"
                                />
                            </Button>
                        </div>
                        {incident.description && (
                            <p className="line-clamp-3 text-sm [overflow-wrap:anywhere] text-muted-foreground">
                                {incident.description}
                            </p>
                        )}
                        <dl className="grid grid-cols-2 gap-3 text-sm">
                            <div className="col-span-2">
                                <dt className="text-caption text-muted-foreground">
                                    When
                                </dt>
                                <dd>
                                    {incident.occurred_at
                                        ? formatDateTime(incident.occurred_at)
                                        : 'Time not recorded'}
                                </dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-caption text-muted-foreground">
                                    Client
                                </dt>
                                <dd className="[overflow-wrap:anywhere]">
                                    {incident.client
                                        ? `${incident.client.first_name} ${incident.client.last_name}`
                                        : 'No client recorded'}
                                    {incident.client?.site && (
                                        <span className="block text-xs text-muted-foreground">
                                            {incident.client.site}
                                        </span>
                                    )}
                                </dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-caption text-muted-foreground">
                                    Incident owner
                                </dt>
                                <dd className="[overflow-wrap:anywhere]">
                                    {incident.ownership
                                        ? incidentOwnerLabel(incident.ownership)
                                        : 'Owner not recorded'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-caption text-muted-foreground">
                                    Severity
                                </dt>
                                <dd className="mt-1">
                                    <StatusBadge
                                        status={
                                            incident.type === 'near_miss' &&
                                            incident.potential_severity
                                                ? incident.potential_severity
                                                : incident.severity
                                        }
                                        label={
                                            incident.type === 'near_miss' &&
                                            incident.potential_severity
                                                ? `Potential: ${SEV[incident.potential_severity]?.label ?? incident.potential_severity}`
                                                : (SEV[incident.severity]
                                                      ?.label ??
                                                  incident.severity)
                                        }
                                    />
                                </dd>
                            </div>
                            <div>
                                <dt className="text-caption text-muted-foreground">
                                    Status
                                </dt>
                                <dd className="mt-1">
                                    <StatusBadge
                                        status={incident.status}
                                        label={
                                            STATUS[incident.status]?.label ??
                                            titleCase(incident.status)
                                        }
                                    />
                                </dd>
                            </div>
                            <div className="col-span-2">
                                <dt className="text-caption text-muted-foreground">
                                    Source
                                </dt>
                                <dd>
                                    {SOURCE[incident.source]?.label ??
                                        titleCase(incident.source)}
                                </dd>
                            </div>
                        </dl>
                        <div className="flex flex-wrap gap-3 text-xs">
                            {incident.is_notifiable && (
                                <span className="inline-flex items-center gap-1 text-status-critical">
                                    <ShieldAlert
                                        aria-hidden
                                        className="size-4"
                                    />
                                    WorkSafe-notifiable
                                </span>
                            )}
                            {incident.open_followups_count > 0 && (
                                <span className="inline-flex items-center gap-1">
                                    <ListTodo aria-hidden className="size-4" />
                                    {incident.open_followups_count} open
                                    follow-ups
                                </span>
                            )}
                            {incident.attachments_count > 0 && (
                                <span className="inline-flex items-center gap-1">
                                    <Paperclip aria-hidden className="size-4" />
                                    {incident.attachments_count} attachments
                                </span>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
            <div
                role="region"
                aria-label="Incidents table"
                tabIndex={0}
                className="hidden max-w-full overflow-x-auto overscroll-x-contain focus-visible:ring-2 focus-visible:ring-ring md:block"
            >
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            <th className="px-4 py-2.5">When</th>
                            <th className="px-4 py-2.5">Incident</th>
                            <th className="px-4 py-2.5">Client</th>
                            <th className="px-4 py-2.5">Incident owner</th>
                            <th className="px-4 py-2.5">Severity</th>
                            <th className="px-4 py-2.5">Status</th>
                            <th className="px-4 py-2.5">Source</th>
                            <th className="px-4 py-2.5">Flags</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {rows.map((i) => {
                            const sev = SEV[i.severity] ?? SEV.low;
                            const stat = STATUS[i.status] ?? STATUS.draft;
                            const StatusIcon = stat.icon;
                            const src = SOURCE[i.source] ?? SOURCE.manual;
                            const SrcIcon = src.icon;
                            const clientName = i.client
                                ? `${i.client.first_name} ${i.client.last_name}`.trim()
                                : null;
                            const isNearMiss = i.type === 'near_miss';
                            return (
                                <tr
                                    key={i.id}
                                    onClick={() => onOpen(i.id)}
                                    onContextMenu={(e) => onRowCtx(e, i)}
                                    className="cursor-pointer transition-colors hover:bg-muted/40"
                                >
                                    <td className="px-4 py-3 align-top whitespace-nowrap">
                                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <Calendar className="h-3 w-3" />
                                            {i.occurred_at
                                                ? formatDateTime(i.occurred_at)
                                                : '—'}
                                        </div>
                                        <Button
                                            unstyled
                                            className="frontline-focus mt-0.5 min-h-11 rounded-md text-left text-xs font-medium text-primary"
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                onOpen(i.id);
                                            }}
                                            aria-label={`Open incident ${i.ref ?? 'INC-' + i.id}`}
                                        >
                                            {i.ref ?? `INC-${i.id}`}
                                        </Button>
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        <div className="flex items-center gap-2">
                                            <span
                                                className={`h-2 w-2 shrink-0 rounded-full ${TONE_DOT[sev.tone]}`}
                                            />
                                            <span className="font-medium capitalize">
                                                {titleCase(i.type)}
                                            </span>
                                        </div>
                                        {i.description ? (
                                            <p className="mt-0.5 line-clamp-1 max-w-md text-xs text-muted-foreground">
                                                {i.description}
                                            </p>
                                        ) : null}
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        {clientName ? (
                                            <div className="flex items-center gap-2">
                                                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">
                                                    {clientName
                                                        .split(' ')
                                                        .map((n) => n[0])
                                                        .slice(0, 2)
                                                        .join('')}
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block truncate font-medium">
                                                        {clientName}
                                                    </span>
                                                    {i.client?.site ? (
                                                        <span className="block truncate text-xs text-muted-foreground">
                                                            {i.client.site}
                                                        </span>
                                                    ) : null}
                                                </span>
                                            </div>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        {i.ownership
                                            ? incidentOwnerLabel(i.ownership)
                                            : 'Owner not recorded'}
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        <span
                                            className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${TONE_BG[sev.tone]}`}
                                        >
                                            {isNearMiss && i.potential_severity
                                                ? `Potential: ${SEV[i.potential_severity]?.label ?? i.potential_severity}`
                                                : sev.label}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        <span
                                            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${stat.cls}`}
                                        >
                                            <StatusIcon className="h-3 w-3" />
                                            {stat.label}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        <span
                                            className="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                            title={src.label}
                                        >
                                            <SrcIcon className="h-3.5 w-3.5" />
                                            {src.label}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        <div className="flex items-center gap-1.5 text-muted-foreground">
                                            {i.control_room_alert_id ? (
                                                <RadioTower
                                                    className="h-3.5 w-3.5 text-status-info"
                                                    aria-label="Linked to Control Room alert"
                                                />
                                            ) : null}
                                            {i.investigation_status &&
                                            i.investigation_status !==
                                                'not_required' ? (
                                                <Search
                                                    className="h-3.5 w-3.5 text-primary"
                                                    aria-label="Investigation"
                                                />
                                            ) : null}
                                            {i.is_notifiable ? (
                                                <ShieldAlert
                                                    className="h-3.5 w-3.5 text-status-critical"
                                                    aria-label="WorkSafe-notifiable"
                                                />
                                            ) : null}
                                            {i.open_followups_count > 0 ? (
                                                <span
                                                    className="inline-flex items-center gap-0.5 text-xs text-status-warning"
                                                    aria-label="Open follow-ups"
                                                >
                                                    <ListTodo className="h-3.5 w-3.5" />
                                                    {i.open_followups_count}
                                                </span>
                                            ) : null}
                                            {i.attachments_count > 0 ? (
                                                <span
                                                    className="inline-flex items-center gap-0.5 text-xs"
                                                    aria-label="Attachments"
                                                >
                                                    <Paperclip className="h-3.5 w-3.5" />
                                                    {i.attachments_count}
                                                </span>
                                            ) : null}
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Follow-ups worklist                                                */
/* ------------------------------------------------------------------ */

export function FollowupTable({
    rows,
    onOpen,
}: {
    rows: FollowupRow[];
    onOpen: (id: number) => void;
}) {
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <CheckCircle2 className="mx-auto mb-3 h-10 w-10 text-status-success/50" />
                <p className="font-medium text-muted-foreground">
                    No open follow-ups
                </p>
                <p className="mt-1 text-sm text-muted-foreground/70">
                    No open follow-ups match this view and its filters.
                </p>
            </div>
        );
    }
    return (
        <>
            <ul
                aria-label="Open incident follow-ups"
                className="min-w-0 divide-y md:hidden"
            >
                {rows.map((followup) => (
                    <li key={followup.id} className="min-w-0 space-y-3 p-4">
                        <Button
                            unstyled
                            className="frontline-tap frontline-focus block w-full rounded-md text-left font-semibold [overflow-wrap:anywhere] text-primary"
                            onClick={() => onOpen(followup.incident_id)}
                            aria-label={`Open incident ${followup.incident_ref ?? 'INC-' + followup.incident_id}`}
                        >
                            {followup.incident_ref ??
                                `INC-${followup.incident_id}`}
                            {followup.incident_type
                                ? ` · ${titleCase(followup.incident_type)}`
                                : ''}
                        </Button>
                        <p className="text-sm [overflow-wrap:anywhere]">
                            {followup.notes ?? 'Follow-up task'}
                        </p>
                        <dl className="grid grid-cols-2 gap-3 text-sm">
                            <div className="min-w-0">
                                <dt className="text-caption text-muted-foreground">
                                    Client
                                </dt>
                                <dd className="[overflow-wrap:anywhere]">
                                    {followup.client_name ??
                                        'No client recorded'}
                                </dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-caption text-muted-foreground">
                                    Owner
                                </dt>
                                <dd className="[overflow-wrap:anywhere]">
                                    {followup.assigned_to ?? 'Unassigned'}
                                </dd>
                            </div>
                            <div className="col-span-2">
                                <dt className="text-caption text-muted-foreground">
                                    Due
                                </dt>
                                <dd>
                                    {followup.due_at
                                        ? formatDateTime(followup.due_at)
                                        : 'No due date'}
                                    {followup.overdue && (
                                        <StatusBadge
                                            className="ml-2"
                                            status="overdue"
                                        />
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </li>
                ))}
            </ul>
            <div
                role="region"
                aria-label="Incident follow-ups table"
                tabIndex={0}
                className="hidden max-w-full overflow-x-auto overscroll-x-contain focus-visible:ring-2 focus-visible:ring-ring md:block"
            >
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            <th className="px-4 py-2.5">Follow-up</th>
                            <th className="px-4 py-2.5">Incident</th>
                            <th className="px-4 py-2.5">Client</th>
                            <th className="px-4 py-2.5">Owner</th>
                            <th className="px-4 py-2.5">Due</th>
                            <th className="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {rows.map((f) => (
                            <tr
                                key={f.id}
                                onClick={() => onOpen(f.incident_id)}
                                className="cursor-pointer transition-colors hover:bg-muted/40"
                            >
                                <td className="px-4 py-3 align-top">
                                    <div className="flex items-start gap-2">
                                        <Wrench className="mt-0.5 h-3.5 w-3.5 shrink-0 text-status-warning" />
                                        <span className="line-clamp-2 max-w-md">
                                            {f.notes ?? 'Follow-up task'}
                                        </span>
                                    </div>
                                </td>
                                <td className="px-4 py-3 align-top whitespace-nowrap text-muted-foreground">
                                    <Button
                                        unstyled
                                        className="frontline-focus min-h-11 rounded-md text-left font-medium text-primary"
                                        onClick={(event) => {
                                            event.stopPropagation();
                                            onOpen(f.incident_id);
                                        }}
                                        aria-label={`Open incident ${f.incident_ref ?? 'INC-' + f.incident_id}`}
                                    >
                                        {f.incident_ref ??
                                            `INC-${f.incident_id}`}
                                    </Button>
                                    {f.incident_type ? (
                                        <span className="block text-xs capitalize">
                                            {titleCase(f.incident_type)}
                                        </span>
                                    ) : null}
                                </td>
                                <td className="px-4 py-3 align-top">
                                    {f.client_name ?? '—'}
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                        {f.assigned_to ? (
                                            <UserCog className="h-3.5 w-3.5" />
                                        ) : null}
                                        {f.assigned_to ?? 'Unassigned'}
                                    </span>
                                </td>
                                <td className="px-4 py-3 align-top whitespace-nowrap">
                                    {f.due_at ? (
                                        <span
                                            className={`inline-flex items-center gap-1 text-xs ${f.overdue ? 'font-semibold text-status-critical' : 'text-muted-foreground'}`}
                                        >
                                            <Clock className="h-3.5 w-3.5" />
                                            {formatDateTime(f.due_at)}
                                            {f.overdue ? ' · overdue' : ''}
                                        </span>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">
                                            No due date
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-right align-top">
                                    <span className="inline-flex items-center gap-1 text-xs text-primary">
                                        <Stethoscope className="h-3.5 w-3.5" />{' '}
                                        Open
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}
