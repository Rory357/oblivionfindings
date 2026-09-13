import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    Calendar,
    CheckCircle,
    Clock,
    Eye,
    List,
    Pencil,
    Rotate3D,
    UserPlus,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import PageShell from '@/components/page-shell';
import { TabStrip } from '@/components/rostering/tab-strip';
import AppLayout from '@/layouts/app-layout';
import {
    cancel as cancelShift,
    complete as completeShift,
    duplicate as duplicateShift,
    reopen as reopenShift,
    index as shiftsIndex,
    show as showShift,
    start as startShift,
} from '@/routes/operations/shifts';

import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import { CreateShiftDialog } from './components/create-shift-dialog';
import { DonutCard } from './components/donut-card';
import { ShiftCalendarView } from './components/shift-calendar-view';
import {
    ShiftContextMenu,
    type ContextMenuItem,
} from './components/shift-context-menu';
import { ShiftDetailDialog } from './components/shift-detail-dialog';
import { ShiftListView } from './components/shift-list-view';
import {
    clientFullName,
    isOpenShift,
    shiftDayKey,
    type ShiftRow,
} from './components/shift-row-types';
import { ShiftsHero } from './components/shifts-hero';
import { useCreateShiftLauncher } from './components/use-create-shift-launcher';

type Filters = {
    from: string;
    to: string;
    status?: string | null;
    statuses?: string[];
    client_id?: string | number | null;
    client_ids?: number[];
    user_id?: string | number | null;
    user_ids?: number[];
    site_id?: string | number | null;
    site_ids?: number[];
    assigned?: string | null;
    q?: string | null;
};

type Client = {
    id: number;
    first_name: string;
    last_name: string;
    service_context_id?: number | null;
    site_id?: number | null;
};

type Staff = { id: number; name: string; email: string };
type Site = { id: number; name: string; type?: string | null };
type ServiceContext = {
    id: number;
    name: string;
    type: string;
    is_active: boolean;
};

type Stats = {
    total: number;
    open: number;
    today: number;
    in_progress: number;
    scheduled: number;
    completed: number;
    draft: number;
    cancelled: number;
    unassigned: number;
    hours: number;
    sites: number;
    staff: number;
};

type Props = {
    shifts: { data: ShiftRow[] };
    filters: Filters;
    clients: Client[];
    staff: Staff[];
    sites: Site[];
    serviceContexts: ServiceContext[];
    defaultServiceContextId: number | null;
    stats: Stats;
    canCreate: boolean;
};

type TabKey = 'all' | 'open' | 'today' | 'unassigned' | 'completed';
type ViewMode = 'list' | 'calendar';

// Format a Date as yyyy-mm-dd using local components. We deliberately avoid
// `toISOString().slice(0, 10)` because that converts to UTC — in any timezone
// east of UTC (e.g. Pacific/Auckland) midnight local rolls back to the previous
// day, which silently shifted the week-anchor by one day.
function toLocalIsoDate(d: Date): string {
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

function todayIsoDate(): string {
    return toLocalIsoDate(new Date());
}

function addDaysIso(iso: string, days: number): string {
    const d = new Date(iso + 'T00:00:00');
    d.setDate(d.getDate() + days);
    return toLocalIsoDate(d);
}

function weekStartFor(iso: string): string {
    const d = new Date(iso + 'T00:00:00');
    const dow = d.getDay(); // 0=Sun..6=Sat
    const monOffset = dow === 0 ? -6 : 1 - dow;
    d.setDate(d.getDate() + monOffset);
    return toLocalIsoDate(d);
}

function weekLabel(fromIso: string, toIso: string): string {
    const a = new Date(fromIso + 'T00:00:00');
    const b = new Date(toIso + 'T00:00:00');
    if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime())) {
        return `${fromIso} → ${toIso}`;
    }
    const aFmt = a.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });
    const bFmt = b.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });
    return `${aFmt} → ${bFmt}`;
}

function daysOfWeek(fromIso: string): string[] {
    const start = weekStartFor(fromIso);
    return [0, 1, 2, 3, 4, 5, 6].map((n) => addDaysIso(start, n));
}

export default function ShiftsIndex({
    shifts,
    filters,
    clients,
    staff,
    sites,
    serviceContexts,
    defaultServiceContextId,
    stats,
    canCreate,
}: Props) {
    const page = usePage().props as any;
    const labels = page?.labels;
    const auth = page?.auth;
    const userName: string | undefined = auth?.user?.name?.split(' ')?.[0];
    const canEdit = auth?.can?.shifts?.update;
    const shiftPlural = labels?.['shift.plural'] ?? 'Shifts';

    const todayKey = todayIsoDate();
    const days = useMemo(() => daysOfWeek(filters.from), [filters.from]);

    const [tab, setTab] = useState<TabKey>('all');
    const [viewMode, setViewMode] = useState<ViewMode>('list');
    const [dense, setDense] = useState(false);
    const [createOpen, setCreateOpen] = useState(false);
    const [createDefaults, setCreateDefaults] = useState<{
        starts_at?: string;
        ends_at?: string;
        client_id?: number | null;
        site_id?: number | null;
    }>({});
    const [viewShift, setViewShift] = useState<ShiftRow | null>(null);
    const [editShiftRow, setEditShiftRow] = useState<ShiftRow | null>(null);
    const [contextMenu, setContextMenu] = useState<{
        shift: ShiftRow;
        x: number;
        y: number;
    } | null>(null);
    const [coverShift, setCoverShift] = useState<ShiftRow | null>(null);
    const [broadcastingCoverIds, setBroadcastingCoverIds] = useState<
        Set<number>
    >(() => new Set());
    const [broadcastedCoverIds, setBroadcastedCoverIds] = useState<Set<number>>(
        () => new Set(),
    );
    const [toast, setToast] = useState<string | null>(null);
    const createLauncher = useCreateShiftLauncher();

    function openEdit(shift: ShiftRow) {
        setViewShift(null);
        setEditShiftRow(shift);
    }

    function notify(msg: string) {
        setToast(msg);
        window.setTimeout(() => setToast(null), 2400);
    }

    function openCreate(defaults: typeof createDefaults = {}) {
        setCreateDefaults(defaults);
        setCreateOpen(true);
    }

    // The standalone create page was retired; deep links (?create=1 plus any
    // coverage-gap params) now open the shared inline dialog here, hydrated via
    // the launcher fetch so coverage context + reservation token come through.
    useEffect(() => {
        if (typeof window === 'undefined') return;
        const sp = new URLSearchParams(window.location.search);
        if (!sp.has('create')) return;
        createLauncher.openWith({
            site_id: sp.get('site_id'),
            coverage_rule_id: sp.get('coverage_rule_id'),
            client_id: sp.get('client_id'),
            starts_at: sp.get('starts_at'),
            ends_at: sp.get('ends_at'),
            coverage_rule_name: sp.get('coverage_rule_name'),
            coverage_required_staff: sp.get('coverage_required_staff'),
            coverage_missing_staff: sp.get('coverage_missing_staff'),
            coverage_role_shortages: sp.get('coverage_role_shortages'),
            coverage_reservation_token: sp.get('coverage_reservation_token'),
            open_shift: sp.get('open_shift') === '1',
            repeat_weekly: sp.get('repeat_weekly') === '1',
            repeat_end_date: sp.get('repeat_end_date'),
            shift_type: sp.get('shift_type'),
            return_to: sp.get('return_to'),
        });
        // Drop the trigger so a refresh / back-button doesn't reopen it.
        sp.delete('create');
        const qs = sp.toString();
        window.history.replaceState(
            {},
            '',
            window.location.pathname + (qs ? `?${qs}` : ''),
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function gotoWeek(iso: string) {
        const start = weekStartFor(iso);
        const end = addDaysIso(start, 6);
        router.get(
            shiftsIndex.url({
                query: { ...filters, from: start, to: end },
            }),
            {},
            { preserveState: false, replace: true },
        );
    }

    function handleHeroFilter<
        K extends 'statuses' | 'site_ids' | 'user_ids' | 'client_ids' | 'q',
    >(key: K, value: string | string[] | number[]) {
        // Clear the legacy single-key counterparts so we don't apply both at once.
        const cleared: Partial<Filters> = {};
        if (key === 'statuses') cleared.status = null;
        if (key === 'site_ids') cleared.site_id = null;
        if (key === 'user_ids') cleared.user_id = null;
        if (key === 'client_ids') cleared.client_id = null;

        const next = { ...filters, ...cleared, [key]: value } as Filters;
        router.get(
            shiftsIndex.url({ query: next as any }),
            {},
            { preserveState: true, replace: true },
        );
    }

    // Client-side tab + (already server-filtered) refinement
    const tabFiltered = useMemo(() => {
        const list = shifts.data ?? [];
        if (tab === 'open') return list.filter(isOpenShift);
        if (tab === 'today')
            return list.filter((s) => shiftDayKey(s.starts_at) === todayKey);
        if (tab === 'unassigned') return list.filter((s) => !s.staff);
        if (tab === 'completed')
            return list.filter((s) => s.status === 'completed');
        return list;
    }, [shifts.data, tab, todayKey]);

    // Donut breakdowns
    const shiftBreakdown = useMemo(
        () =>
            [
                {
                    key: 'scheduled',
                    label: 'Scheduled',
                    value: Math.max(0, stats.scheduled - stats.open),
                    color: 'var(--primary)',
                },
                {
                    key: 'in_progress',
                    label: 'In progress',
                    value: stats.in_progress,
                    color: 'var(--status-warning)',
                },
                {
                    key: 'open',
                    label: 'Open',
                    value: stats.open,
                    color: 'var(--status-critical)',
                },
                {
                    key: 'completed',
                    label: 'Completed',
                    value: stats.completed,
                    color: 'var(--status-success)',
                },
                {
                    key: 'draft',
                    label: 'Draft',
                    value: stats.draft,
                    color: 'var(--muted-foreground)',
                },
            ].filter((s) => s.value > 0),
        [stats],
    );

    const openBreakdown = useMemo(() => {
        const openShifts = (shifts.data ?? []).filter(isOpenShift);
        const unfilledHours = openShifts.reduce((a, s) => {
            const start = new Date(s.starts_at).getTime();
            const end = new Date(s.ends_at).getTime();
            if (!start || !end || end <= start) return a;
            return a + (end - start) / 3_600_000;
        }, 0);
        return [
            {
                key: 'open',
                label: 'Open shifts',
                value: stats.open,
                color: 'var(--status-critical)',
            },
            {
                key: 'unfilled-hours',
                label: 'Unfilled hours',
                value: Math.round(unfilledHours),
                color: 'var(--status-warning)',
            },
        ].filter((s) => s.value > 0);
    }, [shifts.data, stats.open]);

    const todayBreakdown = useMemo(() => {
        const todayList = (shifts.data ?? []).filter(
            (s) => shiftDayKey(s.starts_at) === todayKey,
        );
        return [
            {
                key: 'in_progress',
                label: 'On now',
                value: todayList.filter((s) => s.status === 'in_progress')
                    .length,
                color: 'var(--status-warning)',
            },
            {
                key: 'scheduled',
                label: 'Upcoming',
                value: todayList.filter(
                    (s) => s.status === 'scheduled' && s.staff,
                ).length,
                color: 'var(--primary)',
            },
            {
                key: 'open',
                label: 'Open',
                value: todayList.filter(isOpenShift).length,
                color: 'var(--status-critical)',
            },
            {
                key: 'completed',
                label: 'Completed',
                value: todayList.filter((s) => s.status === 'completed').length,
                color: 'var(--status-success)',
            },
        ].filter((s) => s.value > 0);
    }, [shifts.data, todayKey]);

    function openShiftMenu(shift: ShiftRow, e: React.MouseEvent) {
        e.preventDefault();
        e.stopPropagation();
        setContextMenu({ shift, x: e.clientX, y: e.clientY });
    }

    function patchShift(url: string, msg: string) {
        router.patch(
            url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => notify(msg),
            },
        );
    }

    function broadcastCoverRequest(shift: ShiftRow) {
        setBroadcastingCoverIds((current) => new Set(current).add(shift.id));
        router.post(
            `/operations/shifts/${shift.id}/broadcast`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    setBroadcastedCoverIds((current) =>
                        new Set(current).add(shift.id),
                    );
                    notify('Cover broadcast requested');
                },
                onFinish: () => {
                    setBroadcastingCoverIds((current) => {
                        const next = new Set(current);
                        next.delete(shift.id);
                        return next;
                    });
                },
            },
        );
    }

    function buildMenuItems(shift: ShiftRow): ContextMenuItem[] {
        const isOpen = isOpenShift(shift);
        const inProg = shift.status === 'in_progress';
        const completed = shift.status === 'completed';
        const cancelled = shift.status === 'cancelled';
        const draft = shift.status === 'draft';

        const items: ContextMenuItem[] = [
            {
                type: 'header',
                label: `${clientFullName(shift.client)} · ${new Date(shift.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`,
            },
            {
                label: 'View shift',
                icon: Eye,
                onClick: () => setViewShift(shift),
                shortcut: '↵',
            },
            {
                label: 'Edit shift',
                icon: Pencil,
                onClick: () => openEdit(shift),
                disabled: !canEdit || completed || cancelled,
            },
            { type: 'separator' },
        ];

        if (isOpen) {
            items.push({
                label: 'Assign staff',
                icon: UserPlus,
                onClick: () => openEdit(shift),
                disabled: !canEdit,
            });
            items.push({
                label: 'Find replacement',
                icon: Users,
                onClick: () => router.visit(showShift.url(shift.id)),
            });
        } else {
            items.push({
                label: 'Reassign staff',
                icon: Users,
                onClick: () => openEdit(shift),
                disabled: completed || cancelled || !canEdit,
            });
            items.push({
                label: 'Find replacement',
                icon: UserPlus,
                onClick: () => router.visit(showShift.url(shift.id)),
                disabled: completed || cancelled,
            });
        }
        items.push({ type: 'separator' });

        if (inProg) {
            items.push({
                label: 'Mark complete',
                icon: CheckCircle,
                onClick: () =>
                    patchShift(
                        completeShift.url(shift.id),
                        'Shift marked complete',
                    ),
            });
        } else if (shift.status === 'scheduled' && shift.staff) {
            items.push({
                label: 'Start shift',
                icon: CheckCircle,
                onClick: () =>
                    patchShift(startShift.url(shift.id), 'Shift started'),
            });
        }
        items.push({
            label: 'Duplicate to next week',
            icon: List,
            onClick: () => {
                const targetDate = addDaysIso(shiftDayKey(shift.starts_at), 7);
                router.post(
                    duplicateShift.url(shift.id),
                    { date: targetDate },
                    {
                        preserveScroll: true,
                        onSuccess: () => notify('Duplicated to next week'),
                    },
                );
            },
            disabled: !canEdit,
        });
        items.push({ type: 'separator' });

        items.push({
            label: 'Open in full view',
            icon: Eye,
            onClick: () => router.visit(showShift.url(shift.id)),
        });

        items.push({ type: 'separator' });
        if (draft || cancelled) {
            items.push({
                label: 'Reopen / restore',
                icon: Rotate3D,
                onClick: () =>
                    patchShift(reopenShift.url(shift.id), 'Shift reopened'),
            });
        } else if (!completed) {
            items.push({
                label: 'Cancel shift',
                icon: X,
                destructive: true,
                onClick: () =>
                    patchShift(
                        cancelShift.url(shift.id),
                        `${clientFullName(shift.client)} · cancelled`,
                    ),
            });
        }
        return items;
    }

    const statusOptions = [
        { value: 'scheduled', label: 'Scheduled' },
        { value: 'in_progress', label: 'In progress' },
        { value: 'completed', label: 'Completed' },
        { value: 'draft', label: 'Draft' },
        { value: 'cancelled', label: 'Cancelled' },
    ];
    const siteItems = sites.map((s) => ({
        id: s.id,
        name: s.name,
        description: s.type ?? null,
    }));
    const staffItems = staff.map((s) => ({
        id: s.id,
        name: s.name,
        description: s.email ?? null,
    }));
    const clientItems = clients.map((c) => ({
        id: c.id,
        name: `${c.first_name} ${c.last_name}`.trim(),
        description: null,
    }));

    const heroStatusFilter = filters.statuses?.length
        ? filters.statuses
        : filters.status
          ? [String(filters.status)]
          : [];
    const heroSiteIds = filters.site_ids?.length
        ? filters.site_ids
        : filters.site_id != null
          ? [Number(filters.site_id)]
          : [];
    const heroUserIds = filters.user_ids?.length
        ? filters.user_ids
        : filters.user_id != null
          ? [Number(filters.user_id)]
          : [];
    const heroClientIds = filters.client_ids?.length
        ? filters.client_ids
        : filters.client_id != null
          ? [Number(filters.client_id)]
          : [];
    const coverAwareShifts = useMemo(
        () =>
            tabFiltered.map((shift) => ({
                ...shift,
                cover_requested:
                    Boolean(shift.cover_requested) ||
                    broadcastingCoverIds.has(shift.id) ||
                    broadcastedCoverIds.has(shift.id),
            })),
        [broadcastedCoverIds, broadcastingCoverIds, tabFiltered],
    );

    return (
        <AppLayout
            breadcrumbs={[{ title: shiftPlural, href: shiftsIndex.url() }]}
        >
            <Head title={shiftPlural} />
            <PageShell>
                <ShiftsHero
                    greetingName={userName}
                    weekLabel={weekLabel(filters.from, filters.to)}
                    stats={{
                        total: stats.total,
                        open: stats.open,
                        today: stats.today,
                        in_progress: stats.in_progress,
                        hours: stats.hours,
                        sites: stats.sites,
                        staff: stats.staff,
                        unassigned: stats.unassigned,
                    }}
                    filters={{
                        statuses: heroStatusFilter,
                        site_ids: heroSiteIds,
                        user_ids: heroUserIds,
                        client_ids: heroClientIds,
                        q: (filters.q as string) ?? '',
                    }}
                    onChangeFilter={(key, value) =>
                        handleHeroFilter(key as any, value as any)
                    }
                    statusOptions={statusOptions}
                    siteItems={siteItems}
                    staffItems={staffItems}
                    clientItems={clientItems}
                    canCreate={canCreate}
                    onCreate={() => openCreate()}
                    onPrevWeek={() => gotoWeek(addDaysIso(filters.from, -7))}
                    onNextWeek={() => gotoWeek(addDaysIso(filters.from, 7))}
                    weekStart={
                        new Date(weekStartFor(filters.from) + 'T00:00:00')
                    }
                    onPickWeek={(d) => {
                        const yyyy = d.getFullYear();
                        const mm = String(d.getMonth() + 1).padStart(2, '0');
                        const dd = String(d.getDate()).padStart(2, '0');
                        gotoWeek(`${yyyy}-${mm}-${dd}`);
                    }}
                />

                <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <DonutCard
                        tone="primary"
                        title="All shifts"
                        subtitle="This week breakdown"
                        segments={shiftBreakdown}
                        centerValue={stats.total}
                        centerLabel="shifts"
                        cta="View all shifts"
                        active={tab === 'all'}
                        onClick={() => setTab('all')}
                    />
                    <DonutCard
                        tone="warning"
                        title="Open shifts"
                        subtitle="Need cover this week"
                        segments={openBreakdown}
                        centerValue={stats.open}
                        centerLabel="open"
                        cta={
                            stats.open > 0 ? 'Find cover' : 'All shifts covered'
                        }
                        active={tab === 'open'}
                        onClick={() => setTab('open')}
                    />
                    <DonutCard
                        tone="success"
                        title="Today"
                        subtitle="What's happening now"
                        segments={todayBreakdown}
                        centerValue={stats.today}
                        centerLabel="today"
                        cta="View today's shifts"
                        active={tab === 'today'}
                        onClick={() => setTab('today')}
                    />
                </div>

                <GuardrailCard
                    unstyled
                    className="overflow-hidden rounded-xl border border-border bg-card"
                >
                    <div className="flex items-center justify-between px-2">
                        <TabStrip
                            value={tab}
                            onChange={(next) => setTab(next as TabKey)}
                            ariaLabel="Shift views"
                            className="border-0 bg-transparent shadow-none"
                            items={[
                                {
                                    id: 'all',
                                    label: 'All shifts',
                                    icon: List,
                                    tone: 'primary',
                                    badge: stats.total,
                                },
                                {
                                    id: 'open',
                                    label: 'Open',
                                    icon: AlertCircle,
                                    tone: 'warning',
                                    badge: stats.open,
                                },
                                {
                                    id: 'today',
                                    label: 'Today',
                                    icon: Clock,
                                    tone: 'success',
                                    badge: stats.today,
                                },
                                {
                                    id: 'unassigned',
                                    label: 'Unassigned',
                                    icon: UserPlus,
                                    tone: 'info',
                                    badge: stats.unassigned,
                                },
                                {
                                    id: 'completed',
                                    label: 'Completed',
                                    icon: CheckCircle,
                                    tone: 'success',
                                    badge: stats.completed,
                                },
                            ]}
                        />
                        <div className="ml-2 hidden items-center gap-1 border-l border-border pr-2 pl-2 md:flex">
                            <GuardrailButton
                                unstyled
                                type="button"
                                onClick={() => setViewMode('list')}
                                className={[
                                    'inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-medium transition',
                                    viewMode === 'list'
                                        ? 'bg-muted text-foreground'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                ].join(' ')}
                                aria-label="List view"
                            >
                                <List className="h-4 w-4" /> List
                            </GuardrailButton>
                            <GuardrailButton
                                unstyled
                                type="button"
                                onClick={() => setViewMode('calendar')}
                                className={[
                                    'inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-medium transition',
                                    viewMode === 'calendar'
                                        ? 'bg-muted text-foreground'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                ].join(' ')}
                                aria-label="Calendar view"
                            >
                                <Calendar className="h-4 w-4" /> Calendar
                            </GuardrailButton>
                        </div>
                    </div>

                    <div className="bg-muted/30 px-4 py-4 md:px-5 md:py-5">
                        {viewMode === 'list' ? (
                            <ShiftListView
                                shifts={coverAwareShifts}
                                todayKey={todayKey}
                                dense={dense}
                                onShiftClick={(s) => setViewShift(s)}
                                onAssignOpen={(s) => openEdit(s)}
                                onFindCover={(s) => setCoverShift(s)}
                                onContextMenu={openShiftMenu}
                                onEditClick={(s) => openEdit(s)}
                                onCreateOnDay={(date) =>
                                    openCreate({
                                        starts_at: `${date}T09:00`,
                                        ends_at: `${date}T17:00`,
                                    })
                                }
                            />
                        ) : (
                            <ShiftCalendarView
                                shifts={tabFiltered}
                                days={days}
                                todayKey={todayKey}
                                onShiftClick={(s) => setViewShift(s)}
                                onContextMenu={openShiftMenu}
                                onCreateOnDay={(date) =>
                                    openCreate({
                                        starts_at: `${date}T09:00`,
                                        ends_at: `${date}T17:00`,
                                    })
                                }
                            />
                        )}
                    </div>

                    <div className="flex items-center justify-between border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
                        <span>
                            Showing {tabFiltered.length} of {stats.total} shifts
                        </span>
                        <div className="flex items-center gap-2">
                            <span>
                                Density:{' '}
                                <span className="font-medium text-foreground">
                                    {dense ? 'Compact' : 'Comfortable'}
                                </span>
                            </span>
                            <GuardrailButton
                                unstyled
                                type="button"
                                onClick={() => setDense((x) => !x)}
                                className="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                                aria-label="Toggle density"
                            >
                                <Rotate3D className="h-3 w-3" />
                            </GuardrailButton>
                        </div>
                    </div>
                </GuardrailCard>

                {contextMenu ? (
                    <ShiftContextMenu
                        x={contextMenu.x}
                        y={contextMenu.y}
                        items={buildMenuItems(contextMenu.shift)}
                        onClose={() => setContextMenu(null)}
                    />
                ) : null}

                {toast ? (
                    <div className="fixed bottom-6 left-1/2 z-50 inline-flex -translate-x-1/2 items-center gap-2 rounded-lg bg-foreground px-3 py-2 text-xs text-background shadow-lg">
                        <CheckCircle className="h-4 w-4" />
                        {toast}
                    </div>
                ) : null}

                <CreateShiftDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    clients={clients}
                    staff={staff}
                    sites={sites}
                    serviceContexts={serviceContexts}
                    defaultServiceContextId={defaultServiceContextId}
                    defaultStartsAt={createDefaults.starts_at}
                    defaultEndsAt={createDefaults.ends_at}
                    defaultClientId={createDefaults.client_id ?? null}
                    defaultSiteId={createDefaults.site_id ?? null}
                />

                {createLauncher.dialog}

                <CreateShiftDialog
                    key={editShiftRow ? `edit-${editShiftRow.id}` : 'edit-none'}
                    open={!!editShiftRow}
                    onClose={() => setEditShiftRow(null)}
                    clients={clients}
                    staff={staff}
                    sites={sites}
                    serviceContexts={serviceContexts}
                    defaultServiceContextId={defaultServiceContextId}
                    initialShift={
                        editShiftRow
                            ? {
                                  id: editShiftRow.id,
                                  starts_at: editShiftRow.starts_at,
                                  ends_at: editShiftRow.ends_at,
                                  status: editShiftRow.status,
                                  shift_type: editShiftRow.shift_type ?? null,
                                  location: editShiftRow.location ?? null,
                                  is_sleepover: editShiftRow.is_sleepover,
                                  is_on_call: editShiftRow.is_on_call,
                                  notes: editShiftRow.notes ?? null,
                                  expected_break_minutes:
                                      editShiftRow.expected_break_minutes ??
                                      null,
                                  service_context_id:
                                      editShiftRow.service_context_id ?? null,
                                  coverage_roles:
                                      editShiftRow.coverage_roles ?? [],
                                  tasks: editShiftRow.tasks ?? [],
                                  client: editShiftRow.client
                                      ? { id: editShiftRow.client.id }
                                      : null,
                                  staff: editShiftRow.staff
                                      ? { id: editShiftRow.staff.id }
                                      : null,
                                  site: editShiftRow.site
                                      ? {
                                            id: editShiftRow.site.id,
                                            name: editShiftRow.site.name,
                                        }
                                      : null,
                              }
                            : null
                    }
                />

                <ShiftDetailDialog
                    open={!!viewShift}
                    shift={viewShift}
                    onClose={() => setViewShift(null)}
                    onEdit={viewShift ? () => openEdit(viewShift) : undefined}
                    onAct={(action) => {
                        if (!viewShift) return;
                        if (action === 'assign') {
                            openEdit(viewShift);
                        } else if (action === 'start') {
                            patchShift(
                                startShift.url(viewShift.id),
                                'Shift started',
                            );
                            setViewShift(null);
                        } else if (action === 'complete') {
                            patchShift(
                                completeShift.url(viewShift.id),
                                'Shift marked complete',
                            );
                            setViewShift(null);
                        } else if (action === 'timesheet') {
                            router.visit(showShift.url(viewShift.id));
                        }
                    }}
                />
                <ConfirmDialog
                    open={!!coverShift}
                    title="Broadcast cover request?"
                    description={
                        coverShift
                            ? `Notify eligible staff that ${clientFullName(coverShift.client)} needs cover for this shift.`
                            : 'Notify eligible staff that this shift needs cover.'
                    }
                    confirmText="Broadcast cover"
                    variant="default"
                    onClose={() => setCoverShift(null)}
                    onConfirm={() => {
                        if (coverShift) {
                            broadcastCoverRequest(coverShift);
                        }
                    }}
                />
            </PageShell>
        </AppLayout>
    );
}
