import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    calendarPeriod,
    TransportCalendar,
    type TransportCalendarItem,
} from '@/components/fleet-assets/transport/calendar';
import {
    ActionDialog,
    DemandDialog,
    intentTitles,
} from '@/components/fleet-assets/transport/dialogs';
import {
    exportPath,
    inQueue,
    RETURN_LABELS,
    transportPath,
} from '@/components/fleet-assets/transport/model';
import { TransportOverview } from '@/components/fleet-assets/transport/overview';
import {
    PlannerEmptyState,
    TransportPlanner,
} from '@/components/fleet-assets/transport/planner';
import {
    availableIntents,
    TransportQuickView,
} from '@/components/fleet-assets/transport/record-detail';
import '@/components/fleet-assets/transport/transport.css';
import type {
    Filters,
    Intent,
    Person,
    TransportRecord,
    WorkspaceView,
} from '@/components/fleet-assets/transport/types';
import {
    Empty,
    Notice,
    SectionTabs,
    Stage,
} from '@/components/fleet-assets/transport/ui';
import {
    EntityContextMenu,
    EntityKebab,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import type { GroupedProfileNavTab } from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { PageLayout } from '@/components/page/page-layout';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { periodLabel, type CalView } from '@/pages/sites/calendar/_parts';
import { Head, router } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarDays,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    FileText,
    Flag,
    Home,
    Inbox,
    KeyRound,
    LayoutDashboard,
    LayoutGrid,
    List,
    Plus,
    RefreshCw,
    Route,
    Rows3,
    Search,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
const tabs = [
    { key: 'overview', label: 'Overview', icon: LayoutDashboard },
    { key: 'requests', label: 'Requests & approvals', icon: Inbox },
    { key: 'planner', label: 'Planner', icon: ClipboardCheck },
    { key: 'calendar', label: 'Calendar', icon: CalendarDays },
    { key: 'journeys', label: 'Journeys', icon: Route },
    { key: 'returns', label: 'Returns & handovers', icon: KeyRound },
];
const sections: Record<WorkspaceView, GroupedProfileNavTab[]> = {
    overview: [
        { key: 'summary', label: 'At a glance', icon: LayoutDashboard },
        { key: 'attention', label: 'Needs attention', icon: Flag },
        { key: 'departures', label: 'Departure board', icon: Route },
    ],
    requests: [
        { key: 'all', label: 'All requests', icon: Inbox },
        { key: 'assessment', label: 'Needs assessment', icon: ClipboardCheck },
        { key: 'information', label: 'Needs information', icon: Flag },
        { key: 'allocation', label: 'Ready to plan', icon: CalendarDays },
        { key: 'decision', label: 'Booking decisions', icon: CheckCircle2 },
        { key: 'cancelled', label: 'Cancelled', icon: HistoryIcon },
    ],
    planner: [
        { key: 'all', label: 'Build a plan', icon: ClipboardCheck },
        { key: 'planned', label: 'Existing plans', icon: CalendarDays },
    ],
    calendar: [
        { key: 'month', label: 'Month', icon: LayoutGrid },
        { key: 'week', label: 'Week', icon: CalendarDays },
        { key: 'day', label: 'Day', icon: Clock },
        { key: 'agenda', label: 'Agenda', icon: List },
        { key: 'timeline', label: 'Timeline', icon: Rows3 },
    ],
    journeys: [
        { key: 'all', label: 'All journeys', icon: Route },
        { key: 'ready', label: 'Prepare', icon: ClipboardCheck },
        { key: 'depart', label: 'Ready to depart', icon: CheckCircle2 },
        { key: 'travelling', label: 'In progress', icon: Route },
        { key: 'returned', label: 'Returned', icon: Home },
        { key: 'completed', label: 'Completed', icon: CheckCircle2 },
    ],
    returns: [
        { key: 'all', label: 'All returns', icon: KeyRound },
        { key: 'attention', label: 'Outstanding', icon: Flag },
        { key: 'due', label: 'Due back', icon: Clock },
        { key: 'overdue', label: 'Overdue', icon: Flag },
        { key: 'items', label: 'Items to receive', icon: ClipboardCheck },
        { key: 'keys', label: 'Keys to store', icon: KeyRound },
        { key: 'handover', label: 'Handovers to review', icon: Users },
        { key: 'exceptions', label: 'Exceptions', icon: Flag },
        { key: 'complete', label: 'Completed returns', icon: CheckCircle2 },
    ],
};
function HistoryIcon(props: { className?: string }) {
    return <Clock {...props} />;
}
const titles: Record<WorkspaceView, [string, string]> = {
    overview: [
        'Transport at a glance',
        'See what needs attention, what is planned and who acts next.',
    ],
    requests: [
        'Transport requests & booking decisions',
        'Assess the passenger’s needs, then plan and approve the linked Fleet booking.',
    ],
    planner: [
        'Build the transport plan',
        'Choose a request, then the vehicle, people and key arrangements.',
    ],
    calendar: [
        'Transport schedule',
        'The same Fleet bookings, arranged around passenger transport.',
    ],
    journeys: [
        'Passenger journeys',
        'Prepare, depart, confirm passenger return and finish the required handoffs.',
    ],
    returns: [
        'Return work queue',
        'Record the vehicle return, receive outstanding items and confirm where keys are stored.',
    ],
};
export default function TransportWorkspace({
    view,
    records,
    filters,
    sites,
    calendar,
    canManage,
    generatedAt,
    calendarAnchor,
}: {
    view: WorkspaceView;
    records: TransportRecord[];
    filters: Filters;
    sites: Person[];
    calendar: TransportCalendarItem[];
    canManage: boolean;
    generatedAt: string;
    calendarAnchor: string;
}) {
    const params =
        typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search)
            : new URLSearchParams();
    const rawSection =
        params.get('queue') ||
        (view === 'calendar' ? 'week' : sections[view][0].key);
    const section = sections[view].some((s) => s.key === rawSection)
        ? rawSection
        : sections[view][0].key;
    const [query, setQuery] = useState(filters.search),
        [quick, setQuick] = useState<number | null>(null);
    const [action, setAction] = useState<{
            row: TransportRecord;
            intent: Intent;
        } | null>(null),
        [create, setCreate] = useState<{ start?: string } | null>(null);
    const [menu, setMenu] = useState<{
        x: number;
        y: number;
        title: string;
        items: MenuItem[];
    } | null>(null);
    const [dirty, setDirty] = useState(false),
        [pending, setPending] = useState<(() => void) | null>(null);
    const searchReady = useRef(false);
    const guard = useCallback(
        (go: () => void) => {
            if (dirty) setPending(() => go);
            else go();
        },
        [dirty],
    );
    const navigate = useCallback(
        (
            target: WorkspaceView,
            queue?: string,
            next: Filters = filters,
            extra: Record<string, string> = {},
        ) =>
            guard(() => {
                let chosen = next;
                const anchor =
                    extra.day ||
                    (view === 'calendar' ? calendarAnchor : next.from);
                if (target === 'calendar')
                    chosen = {
                        ...next,
                        ...calendarPeriod(
                            (queue || 'week') as CalView,
                            new Date(`${anchor}T12:00:00`),
                        ),
                    };
                router.get(
                    transportPath(target, chosen, {
                        ...(queue ? { queue } : {}),
                        ...(target === 'calendar' ? { day: anchor } : {}),
                        ...extra,
                    }),
                    {},
                    {
                        preserveScroll: target === view,
                        preserveState:
                            target === view && next.search !== filters.search,
                    },
                );
            }),
        [guard, filters, view, calendarAnchor],
    );
    useEffect(() => {
        if (!searchReady.current) {
            searchReady.current = true;
            return;
        }
        const timer = window.setTimeout(() => {
            if (query !== filters.search)
                navigate(view, section, { ...filters, search: query });
        }, 400);
        return () => clearTimeout(timer);
    }, [query, filters, navigate, section, view]);
    useEffect(() => {
        if (!dirty) return;
        const warn = (e: BeforeUnloadEvent) => {
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);
    const refresh = () => router.reload();
    const open = (row: TransportRecord) => setQuick(row.id);
    const plan = (row: TransportRecord) => {
        setQuick(null);
        navigate('planner', row.booking ? 'planned' : 'all', filters, {
            selected: String(row.id),
        });
    };
    const recordActions = (row: TransportRecord): MenuItem[] => [
        ...availableIntents(row).map((intent) => ({
            label: intentTitles[intent],
            icon: ClipboardCheck,
            onClick: async () => {
                try {
                    const response = await fetch(row.links.request, {
                        headers: { Accept: 'application/json' },
                        cache: 'no-store',
                    });
                    if (!response.ok)
                        throw new Error(
                            'This transport action is not available. Refresh the current record.',
                        );
                    const current: TransportRecord = await response.json();
                    if (!availableIntents(current).includes(intent))
                        throw new Error(
                            'This transport changed. Open its current record to see the next step.',
                        );
                    setAction({ row: current, intent });
                } catch (error) {
                    toast.error(
                        error instanceof Error
                            ? error.message
                            : 'The current record could not be loaded.',
                    );
                }
            },
        })),
        {
            label: 'Quick view & available actions',
            icon: Search,
            onClick: () => open(row),
        },
        {
            label: 'Open full record',
            icon: FileText,
            onClick: () => guard(() => router.visit(row.links.request)),
        },
        ...(row.can.manage &&
        (row.stage === 'allocation' ||
            (['pending', 'approved', 'rejected'].includes(
                row.booking?.status || '',
            ) &&
                row.stage !== 'cancelled'))
            ? [
                  {
                      label: row.booking
                          ? 'Change transport plan'
                          : 'Build transport plan',
                      icon: CalendarDays,
                      onClick: () => plan(row),
                  },
              ]
            : []),
        {
            label: 'Export record PDF',
            icon: FileText,
            onClick: () => window.location.assign(exportPath(filters, row.id)),
        },
        ...(row.links.vehicle
            ? [
                  {
                      label: 'Vehicle trip history',
                      icon: Route,
                      onClick: () =>
                          guard(() => router.visit(row.links.vehicle!)),
                  },
              ]
            : []),
    ];
    const heroActions: MenuItem[] = [
        {
            label: 'Request transport',
            icon: Plus,
            onClick: () => setCreate({}),
        },
        {
            label: 'Export this scope PDF',
            icon: FileText,
            onClick: () =>
                window.location.assign(
                    exportPath(filters, undefined, {
                        view,
                        queue: section,
                        stages: params.get('stages') || '',
                    }),
                ),
        },
        { label: 'Refresh source records', icon: RefreshCw, onClick: refresh },
        {
            label: 'All journey records',
            icon: Route,
            onClick: () => router.visit('/fleet-assets/transports'),
        },
        {
            label: 'Outings',
            icon: Users,
            onClick: () => router.visit('/fleet-assets/outings'),
        },
    ];
    const graphStages = params.get('stages')?.split(',');
    const filtered = records.filter((r) =>
        graphStages ? graphStages.includes(r.stage) : inQueue(r, view, section),
    );
    const meters = [
        {
            label: 'Needs a decision',
            count: records.filter((r) =>
                ['assessment', 'information', 'decision'].includes(r.stage),
            ).length,
            caption: 'Assessment and booking approval',
            view: 'requests' as const,
            stages: 'assessment,information,decision',
        },
        {
            label: 'Ready to plan',
            count: records.filter((r) => r.stage === 'allocation').length,
            caption: 'Build a vehicle and team plan',
            view: 'planner' as const,
        },
        {
            label: 'Journeys under way',
            count: records.filter((r) =>
                ['travelling', 'returned'].includes(r.stage),
            ).length,
            caption: 'Passenger progress and handoffs',
            view: 'journeys' as const,
            stages: 'travelling,returned',
        },
        {
            label: 'Return work due',
            count: records.filter(
                (r) => !['not_due', 'complete'].includes(r.return_stage),
            ).length,
            caption: 'Vehicle, items and key storage',
            view: 'returns' as const,
        },
    ];
    const hero = (
        <div
            onContextMenu={(e) => {
                e.preventDefault();
                setMenu({
                    x: e.clientX,
                    y: e.clientY,
                    title: 'Transport workspace',
                    items: heroActions,
                });
            }}
        >
            <PageHeader
                title={tabs.find((t) => t.key === view)!.label}
                icon={tabs.find((t) => t.key === view)!.icon}
                subline={titles[view][1]}
                variant="index"
                actions={
                    <>
                        <PageHeaderSearch
                            placeholder={`Search ${view === 'overview' ? 'transport' : tabs.find((t) => t.key === view)!.label.toLowerCase()}`}
                            value={query}
                            onChange={setQuery}
                        />
                        <PageHeaderPrimaryButton onClick={() => setCreate({})}>
                            <Plus className="size-4" />
                            Request transport
                        </PageHeaderPrimaryButton>
                        <EntityKebab actions={heroActions} />
                    </>
                }
                meters={
                    <div className="tr-meters">
                        {view === 'calendar' && (
                            <PageHeaderMeterBlock
                                label="Viewing"
                                value={`${calendar.length} entries`}
                                ariaLabel="View this date in the day calendar"
                                onClick={() =>
                                    navigate('calendar', 'day', filters, {
                                        day: calendarAnchor,
                                    })
                                }
                            >
                                <div
                                    className="flex items-center gap-3 [&_.eh-meter-big]:text-3xl"
                                    aria-live="polite"
                                    aria-atomic="true"
                                    data-testid="calendar-date-anchor"
                                >
                                    <PageHeaderMeterBig>
                                        {Number(calendarAnchor.slice(8, 10))}
                                    </PageHeaderMeterBig>
                                    <div className="flex min-w-0 flex-col leading-tight">
                                        <span className="text-section-title text-primary-foreground!">
                                            {new Date(
                                                `${calendarAnchor}T12:00:00`,
                                            ).toLocaleDateString('en-NZ', {
                                                month: 'long',
                                            })}
                                        </span>
                                        <span className="text-sm font-semibold tabular-nums">
                                            {calendarAnchor.slice(0, 4)}
                                        </span>
                                    </div>
                                </div>
                                <PageHeaderMeterCaption>
                                    {section === 'week'
                                        ? periodLabel(
                                              'week',
                                              new Date(
                                                  `${calendarAnchor}T12:00:00`,
                                              ),
                                          )
                                        : new Date(
                                              `${calendarAnchor}T12:00:00`,
                                          ).toLocaleDateString('en-NZ', {
                                              weekday: 'long',
                                              day: 'numeric',
                                              month: 'long',
                                              year: 'numeric',
                                          })}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        )}
                        {(view === 'calendar' ? meters.slice(1) : meters).map(
                            (m) => (
                                <PageHeaderMeterBlock
                                    key={m.label}
                                    label={m.label}
                                    onClick={() =>
                                        navigate(
                                            m.view,
                                            m.view === 'returns'
                                                ? 'attention'
                                                : undefined,
                                            filters,
                                            'stages' in m
                                                ? {
                                                      stages: m.stages!,
                                                      selection: m.label,
                                                  }
                                                : {},
                                        )
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {m.count}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {m.caption}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ),
                        )}
                    </div>
                }
                filters={
                    <>
                        <PageHeaderFilterSelect
                            icon={Home}
                            label="Site"
                            value={filters.site}
                            options={[
                                { value: 'all', label: 'All permitted sites' },
                                ...sites.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                })),
                            ]}
                            onChange={(site) =>
                                navigate(view, section, { ...filters, site })
                            }
                        />
                        {view !== 'calendar' ? (
                            <>
                                <DatePicker
                                    id="transport-date-from"
                                    label="From"
                                    value={filters.from}
                                    trigger={
                                        <PageHeaderFilterButton
                                            icon={CalendarDays}
                                            aria-label={`From: ${formatDateOnly(filters.from)}`}
                                        >
                                            From ·{' '}
                                            {formatDateOnly(filters.from)}
                                        </PageHeaderFilterButton>
                                    }
                                    onChange={(from) =>
                                        navigate(view, section, {
                                            ...filters,
                                            from,
                                            to:
                                                filters.to < from
                                                    ? from
                                                    : filters.to,
                                        })
                                    }
                                />
                                <DatePicker
                                    id="transport-date-to"
                                    label="To"
                                    value={filters.to}
                                    trigger={
                                        <PageHeaderFilterButton
                                            icon={CalendarDays}
                                            aria-label={`To: ${formatDateOnly(filters.to)}`}
                                        >
                                            To · {formatDateOnly(filters.to)}
                                        </PageHeaderFilterButton>
                                    }
                                    onChange={(to) =>
                                        navigate(view, section, {
                                            ...filters,
                                            to,
                                            from:
                                                to < filters.from
                                                    ? to
                                                    : filters.from,
                                        })
                                    }
                                />
                            </>
                        ) : (
                            <span className="text-xs text-primary-foreground">
                                Pacific/Auckland · use Previous, Next or Jump to
                                date
                            </span>
                        )}
                    </>
                }
                rail={
                    <PageHeaderRail
                        items={tabs}
                        value={view}
                        onSelect={(target) => navigate(target as WorkspaceView)}
                    />
                }
            />
        </div>
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                {
                    title: 'Transport',
                    href: '/fleet-assets/transports/overview',
                },
            ]}
        >
            <Head
                title={`${tabs.find((t) => t.key === view)!.label} · Transport`}
            />
            <PageLayout hero={hero} className="transport-workspace">
                <div className="tr-stack">
                    <div
                        className="tr-heading"
                        data-testid="transport-workspace-heading"
                    >
                        <div>
                            <h2 className="text-section-title">
                                {titles[view][0]}
                            </h2>
                            <p>
                                {['overview', 'calendar'].includes(view)
                                    ? records.length
                                    : filtered.length}{' '}
                                matching requests ·{' '}
                                {view === 'returns'
                                    ? 'Passenger completion and vehicle return are recorded separately.'
                                    : titles[view][1]}
                            </p>
                        </div>
                        <Button variant="outline" asChild>
                            <a
                                href={exportPath(filters, undefined, {
                                    view,
                                    queue: section,
                                    stages: params.get('stages') || '',
                                })}
                            >
                                <FileText className="size-4" />
                                Export PDF
                            </a>
                        </Button>
                    </div>
                    <SectionTabs
                        label={`${view} sections`}
                        tabs={sections[view]}
                        value={section}
                        onChange={(queue) => navigate(view, queue)}
                    />
                    {view === 'overview' ? (
                        <TransportOverview
                            records={records}
                            section={section}
                            onOpen={open}
                            onDrill={(target, queue, stages, label) =>
                                navigate(
                                    target,
                                    queue,
                                    filters,
                                    stages
                                        ? {
                                              stages: stages.join(','),
                                              selection: label || '',
                                          }
                                        : {},
                                )
                            }
                        />
                    ) : view === 'planner' ? (
                        <TransportPlanner
                            emptyState={
                                <PlannerEmptyState
                                    existing={section === 'planned'}
                                    awaitingAssessment={
                                        records.filter((row) =>
                                            [
                                                'assessment',
                                                'information',
                                            ].includes(row.stage),
                                        ).length
                                    }
                                    scope={`${formatDateOnly(filters.from)} to ${formatDateOnly(filters.to)} · ${sites.find((site) => String(site.id) === filters.site)?.name || 'All permitted sites'}`}
                                    onRequest={() => setCreate({})}
                                    onRequests={() =>
                                        navigate('requests', 'all')
                                    }
                                    onCalendar={() => navigate('calendar')}
                                    onClearFilters={
                                        filters.search || filters.site !== 'all'
                                            ? () =>
                                                  navigate('planner', section, {
                                                      ...filters,
                                                      search: '',
                                                      site: 'all',
                                                  })
                                            : undefined
                                    }
                                />
                            }
                            records={filtered}
                            canManage={canManage}
                            onSaved={refresh}
                            onCalendar={() => navigate('calendar')}
                            selectedId={
                                Number(params.get('selected')) || undefined
                            }
                            onDirty={setDirty}
                        />
                    ) : view === 'calendar' ? (
                        <TransportCalendar
                            events={calendar}
                            records={records}
                            view={section as CalView}
                            day={calendarAnchor}
                            onDayView={(day) =>
                                navigate('calendar', 'day', filters, { day })
                            }
                            onApprovals={() => navigate('requests', 'decision')}
                            onDay={(day) =>
                                navigate('calendar', section, filters, { day })
                            }
                            onOpen={open}
                            recordActions={recordActions}
                            onSaved={refresh}
                            onRequest={(start) => setCreate({ start })}
                        />
                    ) : (
                        <>
                            {graphStages && (
                                <Notice>
                                    Graph selection:{' '}
                                    {params.get('selection') ||
                                        'Selected stages'}{' '}
                                    · {filtered.length} requests.{' '}
                                    <Button
                                        variant="ghost"
                                        onClick={() => navigate(view, 'all')}
                                    >
                                        Show all stages
                                    </Button>
                                </Notice>
                            )}
                            {view === 'returns' && (
                                <Notice>
                                    <strong>1. Record vehicle return</strong> ·
                                    then{' '}
                                    <strong>2. Receive missing items</strong> ·
                                    then <strong>3. Confirm key storage</strong>
                                    . A shift handover is only needed when
                                    another worker takes custody.
                                </Notice>
                            )}
                            {filtered.length ? (
                                <EntityTable
                                    rows={filtered}
                                    rowKey={(r) => r.id}
                                    identity={(r) => ({
                                        icon:
                                            view === 'returns'
                                                ? KeyRound
                                                : Route,
                                        name: r.person,
                                        subline: `${r.reference} · ${r.booking?.vehicle.name || r.site.name}`,
                                    })}
                                    identityLabel="Passenger / vehicle"
                                    identityWidth="1.5fr"
                                    onOpen={open}
                                    actionsFor={recordActions}
                                    onRowContextMenu={(e, r) => {
                                        e.preventDefault();
                                        setMenu({
                                            x: e.clientX,
                                            y: e.clientY,
                                            title: r.person,
                                            items: recordActions(r),
                                        });
                                    }}
                                    columns={[
                                        {
                                            key: 'time',
                                            label:
                                                view === 'returns'
                                                    ? 'Expected / actual return'
                                                    : 'Transport window',
                                            width: '1.1fr',
                                            cell: (r) => (
                                                <span className="min-w-0 text-xs">
                                                    {formatDateTime(
                                                        view === 'returns'
                                                            ? r.booking
                                                                  ?.returned_at ||
                                                                  r.booking
                                                                      ?.end ||
                                                                  r.end ||
                                                                  r.start
                                                            : r.booking
                                                                  ?.start ||
                                                                  r.start,
                                                    )}
                                                    <small
                                                        className="mt-1 block truncate text-muted-foreground"
                                                        title={
                                                            view === 'returns'
                                                                ? undefined
                                                                : r.destination
                                                        }
                                                    >
                                                        {view === 'returns'
                                                            ? r.booking
                                                                  ?.returned_at
                                                                ? 'Actual vehicle return'
                                                                : 'Expected return'
                                                            : r.destination}
                                                    </small>
                                                </span>
                                            ),
                                        },
                                        {
                                            key: 'status',
                                            label: 'Status',
                                            width: '1.1fr',
                                            cell: (r) =>
                                                view === 'returns' ? (
                                                    <span className="text-xs">
                                                        {
                                                            RETURN_LABELS[
                                                                r.return_stage
                                                            ]
                                                        }
                                                    </span>
                                                ) : (
                                                    <Stage row={r} />
                                                ),
                                        },
                                        {
                                            key: 'owner',
                                            label: 'Next person',
                                            width: '1fr',
                                            cell: (r) => (
                                                <span className="text-xs">
                                                    {view === 'returns'
                                                        ? r.return_next_owner
                                                        : r.next_owner}
                                                </span>
                                            ),
                                        },
                                        {
                                            key: 'next',
                                            label: 'Next step',
                                            width: '1.3fr',
                                            cell: (r) => (
                                                <span className="flex items-center gap-2 text-xs font-medium text-primary">
                                                    {view === 'returns'
                                                        ? RETURN_LABELS[
                                                              r.return_stage
                                                          ]
                                                        : r.next_action}
                                                    <ArrowRight className="size-3 shrink-0" />
                                                </span>
                                            ),
                                        },
                                    ]}
                                />
                            ) : (
                                <Empty
                                    onClear={
                                        filters.search
                                            ? () =>
                                                  navigate(view, section, {
                                                      ...filters,
                                                      search: '',
                                                  })
                                            : undefined
                                    }
                                />
                            )}
                            {view === 'journeys' && (
                                <Button
                                    className="self-start"
                                    variant="outline"
                                    asChild
                                >
                                    <a href="/fleet-assets/transports">
                                        All journey records, including earlier
                                        unlinked trips
                                        <ArrowRight className="size-4" />
                                    </a>
                                </Button>
                            )}
                        </>
                    )}
                    <p className="tr-caption">
                        Updated {formatDateTime(generatedAt)} · Pacific/Auckland
                        · Your permitted sites and selected dates.
                    </p>
                </div>
            </PageLayout>
            {menu && (
                <EntityContextMenu
                    {...menu}
                    icon={Route}
                    onClose={() => setMenu(null)}
                />
            )}
            {quick !== null && (
                <TransportQuickView
                    id={quick}
                    onClose={() => setQuick(null)}
                    onIntent={(row, intent) => {
                        setQuick(null);
                        setAction({ row, intent });
                    }}
                    onPlan={plan}
                />
            )}
            {create && (
                <DemandDialog
                    mode="create"
                    start={create.start}
                    onClose={() => setCreate(null)}
                    onSaved={refresh}
                />
            )}
            {action &&
                (action.intent === 'assess' || action.intent === 'respond' ? (
                    <DemandDialog
                        row={action.row}
                        mode={action.intent}
                        onClose={() => setAction(null)}
                        onSaved={refresh}
                    />
                ) : (
                    <ActionDialog
                        row={action.row}
                        intent={action.intent}
                        onClose={() => setAction(null)}
                        onSaved={refresh}
                    />
                ))}
            <ConfirmDialog
                open={pending !== null}
                onClose={() => setPending(null)}
                title="Leave this unsaved plan?"
                description="Your draft will be discarded. Saved Fleet records remain available."
                confirmText="Discard draft"
                onConfirm={() => {
                    const next = pending;
                    setDirty(false);
                    setPending(null);
                    next?.();
                }}
            />
        </AppLayout>
    );
}
