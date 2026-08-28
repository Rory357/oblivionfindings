import { type HrTabItem, HrTabs, useHrTab } from '@/components/hr';
import {
    AmendmentDrawer,
    type ApprovalTimesheet,
    EntriesPane,
    type ExceptionItem,
    type KpiStats,
    type NamedOption,
    NoteDialog,
    type OnNowItem,
    OverviewPane,
    type PaginatedData,
    type RecentActivityItem,
    ReportsPane,
    type TimeCan,
    type TimeDialogMode,
    type TimeEntry,
    TimeEntryDialog,
    type TimeFilters,
    TimeHero,
    type TimeReport,
    type TimesheetRow,
    TimesheetsPane,
    type WeeklyDay,
} from '@/components/hr/time';
import { PageLayout } from '@/components/page';
import {
    ShiftContextMenu,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    CalendarClock,
    FileText,
    History,
    LayoutDashboard,
    List,
    Pencil,
    Pin,
    Star,
    StickyNote,
    Trash2,
} from 'lucide-react';
import {
    type MouseEvent,
    type ReactNode,
    useEffect,
    useMemo,
    useState,
} from 'react';

interface Props {
    entries: PaginatedData<TimeEntry>;
    report: TimeReport | null;
    timesheets: PaginatedData<TimesheetRow>;
    approvalTimesheets: ApprovalTimesheet[];
    pendingApprovalCount: number;
    onNow: OnNowItem[];
    exceptions: ExceptionItem[];
    weeklyTeam: WeeklyDay[];
    recentActivity: RecentActivityItem[];
    teamMembers: NamedOption[];
    staff: NamedOption[];
    filterSites: NamedOption[];
    sites: NamedOption[];
    clients: NamedOption[];
    activeClock: { id: number; clock_in: string; notes: string | null } | null;
    weeklySummary: { total_hours: number };
    kpiStats: KpiStats;
    filters: TimeFilters;
    can: TimeCan;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Timekeeping', href: '/hr/time' },
];

const KNOWN_TABS = ['overview', 'entries', 'timesheets', 'reports'];

/** Build a query string from the active server filters (minus tab). */
function filterQuery(filters: TimeFilters): string {
    const params = new URLSearchParams();
    (
        Object.entries(filters) as [keyof TimeFilters, string | undefined][]
    ).forEach(([k, v]) => {
        if (v && k !== 'tab') params.set(k, String(v));
    });
    return params.toString();
}

export default function TimeIndex({
    entries,
    report,
    timesheets,
    approvalTimesheets,
    pendingApprovalCount,
    onNow,
    exceptions,
    weeklyTeam,
    recentActivity,
    staff,
    filterSites,
    sites,
    clients,
    activeClock,
    weeklySummary,
    kpiStats,
    filters,
    can,
}: Props) {
    const canMutate = !!can.approveAny;
    const canReadTeam = canMutate || !!can.reportAny;
    const isManager = canReadTeam;
    const [tab, setTab] = useHrTab(
        filters.tab && KNOWN_TABS.includes(filters.tab)
            ? filters.tab
            : 'overview',
    );
    const activeTab = KNOWN_TABS.includes(tab) ? tab : 'overview';

    const [dialogMode, setDialogMode] = useState<TimeDialogMode | null>(null);
    const [dialogEntry, setDialogEntry] = useState<TimeEntry | null>(null);
    const [drawerEntry, setDrawerEntry] = useState<TimeEntry | null>(null);
    const [noteEntry, setNoteEntry] = useState<TimeEntry | null>(null);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

    // tab pin / default-view persistence
    const [pins, setPins] = useState<string[]>([]);
    const [defaultTab, setDefaultTab] = useState<string>('');

    useEffect(() => {
        try {
            const sp = new URLSearchParams(window.location.search);
            const sd = window.localStorage.getItem('hrTime.defaultTab');
            if (sd && KNOWN_TABS.includes(sd)) {
                setDefaultTab(sd);
                if (!sp.get('tab') && sd !== tab) setTab(sd);
            }
            const rawPins = window.localStorage.getItem('hrTime.pinnedTabs');
            if (rawPins) {
                const parsed: unknown = JSON.parse(rawPins);
                if (Array.isArray(parsed))
                    setPins(
                        parsed.filter(
                            (p): p is string =>
                                typeof p === 'string' && KNOWN_TABS.includes(p),
                        ),
                    );
            }
        } catch {
            /* ignore malformed storage */
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Timekeeping soft-refresh: while a manager is watching the Overview tab and the
    // page is visible, poll the live props every 30s so "on now" elapsed times,
    // exceptions and recent activity stay current without a manual reload. Paused
    // when the tab is hidden or a dialog/drawer is open (avoid yanking state).
    useEffect(() => {
        if (!isManager || activeTab !== 'overview') return;
        const tick = () => {
            if (document.hidden || dialogMode || drawerEntry) return;
            router.reload({
                only: [
                    'entries',
                    'onNow',
                    'recentActivity',
                    'kpiStats',
                    'exceptions',
                    'weeklyTeam',
                ],
                preserveState: true,
                preserveScroll: true,
            });
        };
        const id = window.setInterval(tick, 30000);
        return () => window.clearInterval(id);
    }, [isManager, activeTab, dialogMode, drawerEntry]);

    // keyboard shortcuts
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const el = e.target as HTMLElement;
            if (el && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) return;
            if (dialogMode || drawerEntry) return;
            if (e.key === 'n' && canMutate) {
                e.preventDefault();
                openDialog('add');
            } else if (e.key === 'b' && can.clockOnBehalf) {
                e.preventDefault();
                openDialog('behalf');
            } else if (e.key === '/') {
                const input = document.querySelector<HTMLInputElement>(
                    'input[placeholder^="Search staff"]',
                );
                if (input) {
                    e.preventDefault();
                    input.focus();
                }
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [dialogMode, drawerEntry, canMutate, can.clockOnBehalf]);

    function openDialog(mode: TimeDialogMode, entry: TimeEntry | null = null) {
        setDialogEntry(entry);
        setDialogMode(mode);
    }

    function closeDialog() {
        setDialogMode(null);
        setDialogEntry(null);
    }

    function applyFilter(key: string, value: string | null) {
        router.get(
            '/hr/time',
            { ...filters, tab, [key]: value || undefined },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    }

    function clearFilters() {
        router.get(
            '/hr/time',
            { tab: 'entries', scope: filters.scope },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    }

    function viewPersonEntries(name: string) {
        router.get(
            '/hr/time',
            { ...filters, tab: 'entries', q: name },
            { preserveState: true, replace: true },
        );
        setTab('entries');
    }

    /** Minimal entry for a Correct dialog launched from on-now / exceptions. */
    function correctSeed(seed: {
        id: number;
        user_id: number;
        user_name: string;
        clock_in: string;
        entry_date: string;
        can_mutate: boolean;
    }): TimeEntry {
        const inList = entries.data.find((e) => e.id === seed.id);
        if (inList) return inList;
        return {
            id: seed.id,
            user_id: seed.user_id,
            user_name: seed.user_name,
            initials: '',
            site_name: null,
            entry_date: seed.entry_date,
            clock_in: seed.clock_in,
            clock_in_short: '',
            clock_out: null,
            clock_out_short: null,
            break_minutes: 0,
            total_hours: null,
            entry_type: 'clock',
            can_mutate: seed.can_mutate,
            is_attendance_backed: true,
            status: 'active',
            pay_type: 'standard',
            is_sleepover: false,
            is_on_call: false,
            is_public_holiday: false,
            sleepover_disturbances: [],
            break_compliance_met: null,
            mileage_km: null,
            notes: null,
            project_code: null,
            cost_centre: null,
            approved_by: null,
            amended_by: null,
            amendment_reason: null,
            amendment_count: 0,
            client_name: null,
            shift: null,
        };
    }

    /* ---- context menus ---- */
    function rowMenu(e: TimeEntry, ev: MouseEvent) {
        ev.preventDefault();
        const items: ShiftCtxState['items'] = [];
        if (
            can.editEntry &&
            e.can_mutate &&
            !e.is_attendance_backed &&
            e.status !== 'approved' &&
            e.status !== 'voided'
        ) {
            items.push({
                icon: <Pencil className="h-4 w-4" />,
                label: 'Amend entry',
                kbd: 'E',
                onClick: () => openDialog('edit', e),
            });
        }
        if (
            can.editEntry &&
            e.can_mutate &&
            (!e.clock_out || e.is_attendance_backed)
        ) {
            items.push({
                icon: <CalendarClock className="h-4 w-4" />,
                label: 'Correct clock-out',
                onClick: () => openDialog('correct', e),
            });
        }
        if (e.amendment_count > 0) {
            items.push({
                icon: <History className="h-4 w-4" />,
                label: 'Amendment history',
                onClick: () => setDrawerEntry(e),
            });
        }
        if (can.editEntry && e.can_mutate) {
            items.push({
                icon: <StickyNote className="h-4 w-4" />,
                label: 'Add note',
                onClick: () => setNoteEntry(e),
            });
        }
        items.push({
            icon: <List className="h-4 w-4" />,
            label: 'View this person’s entries',
            onClick: () => viewPersonEntries(e.user_name),
        });
        if (
            can.manage &&
            e.can_mutate &&
            !e.is_attendance_backed &&
            e.status !== 'approved' &&
            e.status !== 'voided'
        ) {
            items.push({ sep: true });
            items.push({
                icon: <Trash2 className="h-4 w-4" />,
                label: 'Void entry',
                tone: 'critical',
                onClick: () => openDialog('void', e),
            });
        }
        setCtx({
            x: ev.clientX,
            y: ev.clientY,
            tag: 'Entry',
            meta: e.user_name,
            items,
        });
    }

    function personMenu(p: OnNowItem, ev: MouseEvent) {
        ev.preventDefault();
        const items: ShiftCtxState['items'] = [];
        if (can.editEntry && p.can_mutate) {
            items.push({
                icon: <CalendarClock className="h-4 w-4" />,
                label: 'Correct / close clock-out',
                onClick: () =>
                    openDialog(
                        'correct',
                        correctSeed({ ...p, user_name: p.name }),
                    ),
            });
        }
        items.push({
            icon: <List className="h-4 w-4" />,
            label: 'View this person’s entries',
            onClick: () => viewPersonEntries(p.name),
        });
        setCtx({
            x: ev.clientX,
            y: ev.clientY,
            tag: 'On now',
            meta: p.name,
            items,
        });
    }

    function exceptionMenu(e: ExceptionItem, ev: MouseEvent) {
        ev.preventDefault();
        setCtx({
            x: ev.clientX,
            y: ev.clientY,
            tag: 'Exception',
            meta: e.title,
            items: [
                {
                    icon: can.editEntry && e.can_mutate ? (
                        <AlertTriangle className="h-4 w-4" />
                    ) : (
                        <List className="h-4 w-4" />
                    ),
                    label: !can.editEntry || !e.can_mutate
                        ? 'View entries'
                        : e.action === 'correct'
                          ? 'Correct clock-out'
                          : e.action === 'edit'
                            ? 'Amend entry'
                            : 'View entries',
                    onClick: () => runException(e),
                },
            ],
        });
    }

    function runException(e: ExceptionItem) {
        if ((!can.editEntry || !e.can_mutate) && e.entry_id) {
            viewPersonEntries(e.user_name ?? e.title);
            return;
        }

        if (
            e.action === 'correct' &&
            e.entry_id &&
            e.clock_in &&
            e.entry_date
        ) {
            openDialog(
                'correct',
                correctSeed({
                    id: e.entry_id,
                    user_id: e.user_id ?? 0,
                    user_name: e.user_name ?? e.title,
                    clock_in: e.clock_in,
                    entry_date: e.entry_date,
                    can_mutate: e.can_mutate,
                }),
            );
        } else if (e.action === 'edit' && e.entry_id) {
            const inList = entries.data.find((x) => x.id === e.entry_id);
            if (inList) openDialog('edit', inList);
            else viewPersonEntries(e.title);
        } else {
            viewPersonEntries(e.user_name ?? e.title);
        }
    }

    /* ---- tabs ---- */
    const allTabItems: HrTabItem[] = [
        {
            id: 'overview',
            label: 'Overview',
            icon: LayoutDashboard,
            tone: 'primary',
            badge:
                kpiStats.exceptions_count > 0
                    ? kpiStats.exceptions_count
                    : undefined,
        },
        {
            id: 'entries',
            label: 'Time entries',
            icon: List,
            tone: 'violet',
            badge: entries.total,
        },
        {
            id: 'timesheets',
            label: 'Shift timesheets',
            icon: FileText,
            tone: 'success',
            badge: pendingApprovalCount > 0 ? pendingApprovalCount : undefined,
        },
        {
            id: 'reports',
            label: 'Reports',
            icon: BarChart3,
            tone: 'info',
        },
    ];
    const tabItems = allTabItems.filter(
        (item) => item.id !== 'reports' || !!can.reportAny,
    );

    const exportHref = `/hr/time/export?${filterQuery({ ...filters, tab: undefined })}`;
    const pdfHref = `/hr/time/report/pdf?scope=${filters.scope ?? 'team'}`;

    const orderedTabs = [
        ...tabItems.filter((t) => pins.includes(t.id)),
        ...tabItems.filter((t) => !pins.includes(t.id)),
    ];

    const setDefaultView = (id: string) => {
        setDefaultTab(id);
        try {
            window.localStorage.setItem('hrTime.defaultTab', id);
        } catch {
            /* ignore */
        }
    };
    const togglePin = (id: string) => {
        setPins((prev) => {
            const next = prev.includes(id)
                ? prev.filter((p) => p !== id)
                : [...prev, id];
            try {
                window.localStorage.setItem(
                    'hrTime.pinnedTabs',
                    JSON.stringify(next),
                );
            } catch {
                /* ignore */
            }
            return next;
        });
    };

    const tabDecorations: Record<string, ReactNode> = {};
    tabItems.forEach((t) => {
        const isDefault = defaultTab === t.id;
        const isPinned = pins.includes(t.id);
        if (isDefault || isPinned) {
            tabDecorations[t.id] = (
                <span className="ml-0.5 inline-flex items-center gap-0.5">
                    {isDefault ? (
                        <Star className="h-3 w-3 fill-current text-status-warning" />
                    ) : null}
                    {isPinned ? <Pin className="h-3 w-3" /> : null}
                </span>
            );
        }
    });

    const openTabMenu = (id: string, ev: MouseEvent) => {
        ev.preventDefault();
        const item = tabItems.find((t) => t.id === id);
        setCtx({
            x: ev.clientX,
            y: ev.clientY,
            tag: 'Tab',
            meta: item?.label ?? '',
            items: [
                {
                    icon: <Star className="h-4 w-4" />,
                    label:
                        defaultTab === id
                            ? 'Default view'
                            : 'Set as default view',
                    tone: defaultTab === id ? 'primary' : undefined,
                    onClick: () => setDefaultView(id),
                },
                {
                    icon: <Pin className="h-4 w-4" />,
                    label: pins.includes(id) ? 'Unpin tab' : 'Pin tab',
                    onClick: () => togglePin(id),
                },
            ],
        });
    };

    /* ---- hero alert chips ---- */
    const alerts = useMemo(() => {
        const byKind = (kind: string) =>
            exceptions.filter((e) => e.kind === kind).length;
        const chips: { key: string; label: string; onClick: () => void }[] = [];
        const missed = byKind('missed_clock_out');
        const breaks = byKind('break_fail');
        const ot = byKind('overtime');
        if (missed > 0)
            chips.push({
                key: 'missed',
                label: `${missed} missed clock-out${missed === 1 ? '' : 's'}`,
                onClick: () => setTab('overview'),
            });
        if (breaks > 0)
            chips.push({
                key: 'breaks',
                label: `${breaks} break ${breaks === 1 ? 'fail' : 'fails'}`,
                onClick: () => setTab('overview'),
            });
        if (ot > 0)
            chips.push({
                key: 'ot',
                label: `${ot} over 40h`,
                onClick: () => setTab('overview'),
            });
        return chips;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [exceptions]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Timekeeping" />

            <PageLayout
                hero={
                    <TimeHero
                        teamName="the team"
                        isManager={isManager}
                        kpi={kpiStats}
                        onNow={onNow}
                        weekly={weeklyTeam}
                        alerts={alerts}
                        selfHoursWeek={weeklySummary.total_hours}
                        isOnClock={!!activeClock}
                        handlers={{
                            onAddEntry: canMutate
                                ? () => openDialog('add')
                                : undefined,
                            onClockOnBehalf: can.clockOnBehalf
                                ? () => openDialog('behalf')
                                : undefined,
                            onReviewTimesheets: () =>
                                router.visit('/operations/timesheets'),
                            onExport: can.reportAny
                                ? () => {
                                      window.location.href = exportHref;
                                  }
                                : undefined,
                            onStatOnNow: () => setTab('overview'),
                            onStatHours: () => setTab('entries'),
                            onStatApproval: () => setTab('timesheets'),
                            onStatExceptions: () => setTab('overview'),
                            onViewAllOnNow: () => setTab('overview'),
                        }}
                    />
                }
            >
                {isManager ? (
                    <HrTabs
                        value={activeTab}
                        onChange={setTab}
                        items={orderedTabs}
                        ariaLabel="Timekeeping views"
                        className="mb-6"
                        decorations={tabDecorations}
                        onItemContextMenu={openTabMenu}
                    />
                ) : null}

                {activeTab === 'overview' && isManager ? (
                    <OverviewPane
                        exceptions={exceptions}
                        weekly={weeklyTeam}
                        teamHoursWeek={kpiStats.team_hours_week}
                        onNow={onNow}
                        onNowCount={kpiStats.clocked_in_now}
                        recent={recentActivity}
                        onException={runException}
                        onExceptionContext={exceptionMenu}
                        onPersonContext={personMenu}
                        onActivityClick={(r) => viewPersonEntries(r.user_name)}
                    />
                ) : null}

                {activeTab === 'entries' || !isManager ? (
                    <EntriesPane
                        entries={entries}
                        filters={filters}
                        sites={filterSites}
                        can={can}
                        onAdd={canMutate ? () => openDialog('add') : undefined}
                        onFilter={applyFilter}
                        onRowContext={rowMenu}
                        onAmendments={(e) => setDrawerEntry(e)}
                        onClearFilters={clearFilters}
                    />
                ) : null}

                {activeTab === 'timesheets' && isManager ? (
                    <TimesheetsPane
                        timesheets={timesheets}
                        canApproveAny={!!can.approveAny}
                    />
                ) : null}

                {activeTab === 'reports' && can.reportAny ? (
                    <ReportsPane
                        report={report}
                        exportHref={exportHref}
                        pdfHref={pdfHref}
                    />
                ) : null}
            </PageLayout>

            {canMutate ? (
                <TimeEntryDialog
                    mode={dialogMode}
                    entry={dialogEntry}
                    staff={staff}
                    sites={sites}
                    clients={clients}
                    onClose={closeDialog}
                />
            ) : null}

            <AmendmentDrawer
                entryId={drawerEntry?.id ?? null}
                staffName={drawerEntry?.user_name ?? ''}
                subtitle={
                    drawerEntry
                        ? `${drawerEntry.entry_date} · ${drawerEntry.clock_in_short}–${drawerEntry.clock_out_short ?? '·'}`
                        : ''
                }
                onClose={() => setDrawerEntry(null)}
            />

            <NoteDialog entry={noteEntry} onClose={() => setNoteEntry(null)} />

            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}
        </AppLayout>
    );
}
