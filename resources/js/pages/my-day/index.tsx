import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Bell,
    Calendar,
    CheckCircle2,
    ChevronRight,
    ClipboardList,
    Clock,
    Home,
    ListTodo,
    Menu,
    OctagonAlert,
    Pill,
    Shield,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import ActiveShiftCard, {
    type ActiveShiftSession,
} from '@/components/active-shift-card';
import AlertRow, { type AlertRowItem } from '@/components/alert-row';
import ClockInCard from '@/components/clock-in-card';
import ClockOutBlockerAlert from '@/components/clock-out-blocker-alert';
import type { EndOfShiftBlocker } from '@/components/end-of-shift-checklist';
import HandoverReadCard, {
    type HandoverReadPayload,
} from '@/components/handover-read-card';
import PreShiftBriefingCard, {
    type PreShiftBriefing,
} from '@/components/pre-shift-briefing-card';
import PreviousShiftCard, {
    type PreviousShift,
} from '@/components/previous-shift-card';
import RefreshPill from '@/components/refresh-pill';
import type { StaffBottomNavItem } from '@/components/staff-bottom-nav';
import StaffStatus from '@/components/staff-status';
import type { InlineTimesheet } from '@/components/timesheet-edit-sheet';
import TimesheetReturnBanner from '@/components/timesheet-return-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import useLiveRefresh from '@/hooks/use-live-refresh';
import { useUndoableAction } from '@/hooks/use-undoable-action';
import StaffPageShell from '@/layouts/staff-page-shell';
import { formatTime } from '@/lib/datetime';
import {
    mapIncidentStatus,
    mapMedStatus,
    mapShiftStatus,
    mapTimesheetStatus,
} from '@/lib/status-vocab';

/* -------------------------------------------------------------------------- */
/*  Canonical frontline home — `/my-day`                                      */
/* -------------------------------------------------------------------------- */
/*
 * PR 3 — Single frontline home.
 *
 * This page is the *one* home surface for staff users. It replaces the old
 * `resources/js/pages/my-tasks.tsx` experience; the server now redirects
 * `/my-tasks` → `/my-day` and staff hitting `/dashboard` land here too.
 *
 * Reuses the PR 1 + PR 2 foundation:
 *   - `StaffPageShell` wraps the whole page (header + bottom nav).
 *   - `StaffStatus` renders all worker-facing status chips.
 *   - `status-vocab.ts` collapses backend statuses into the worker vocabulary.
 *
 * Trimmed from the old My Tasks page:
 *   - 6 KPI cards → 3 frontline-relevant summary items
 *     (Shifts today, Meds due, Action needed).
 *   - No breadcrumbs / refresh chrome / raw dashboard framing.
 *   - Header is concise (`StaffHeader`): greeting + date + one primary action.
 *
 * Intentionally NOT in this PR (future PRs own these):
 *   - Clock-in on home
 *   - Guided med round
 *   - Incident wizard
 *   - Full bottom-nav behaviour rewrite
 */

// ---------------------------------------------------------------------------
// Types — mirror the MyTasksController payload (unchanged for this PR)
// ---------------------------------------------------------------------------

interface ShiftClient {
    id: number;
    name: string;
    photo_url: string | null;
}

interface ShiftTaskItem {
    id: number;
    label: string;
    is_completed: boolean;
    completed_at: string | null;
}

interface MyShift {
    id: number;
    starts_at: string;
    ends_at: string;
    actual_starts_at: string | null;
    actual_ends_at: string | null;
    status: string;
    status_state?: string;
    location: string | null;
    service_type: string | null;
    client: ShiftClient;
    tasks: ShiftTaskItem[];
    task_progress: number;
    is_today: boolean;
}

interface MedDue {
    client_id: number;
    client_name: string;
    medication_name: string;
    dose: string;
    scheduled_for: string;
    status: 'overdue' | 'due' | 'upcoming';
    emar_url: string;
}

interface MyTimesheet {
    id: number;
    work_date: string;
    work_date_iso: string | null;
    client_name: string | null;
    client_id: number | null;
    hours: number;
    status: string;
    return_notes: string | null;
    starts_at: string | null;
    ends_at: string | null;
    break_minutes: number;
    mileage_km: number | null;
    notes: string | null;
    can_edit_inline?: boolean;
}

interface MyIncident {
    id: number;
    title: string;
    client_name: string | null;
    severity: string;
    status: string;
    occurred_at: string;
    url: string;
    requires_followup: boolean;
}

interface Task {
    id: string;
    type: 'alert' | 'followup' | 'note_followup';
    title: string;
    priority: 'critical' | 'high' | 'medium' | 'low';
    status: string;
    source_url: string;
    due_at: string | null;
    created_at: string;
    meta: {
        source?: string;
        client_name?: string;
        sla_status?: string;
        asset_name?: string;
        alert_id?: number;
        can_ack?: boolean;
        can_snooze?: boolean;
    };
}

type ClockOpenSession = ActiveShiftSession;

interface ClockActiveShift {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    status: string;
    location: string | null;
    client_name: string | null;
    incoming_handover?: HandoverReadPayload | null;
}

interface ClockState {
    can_clock: boolean;
    open_session: ClockOpenSession | null;
    active_shift: ClockActiveShift | null;
    eligible_shifts?: ClockActiveShift[];
    eligible_shift_count: number;
}

interface ActiveRound {
    id: number;
    name: string;
    status: 'pending' | 'in_progress' | string;
    scheduled_time: string;
    given: number;
    total: number;
    completed: number;
    percent: number;
    url: string;
}

interface Props {
    today: string;
    shifts: MyShift[];
    medications_due: MedDue[];
    timesheets: MyTimesheet[];
    incidents: MyIncident[];
    tasks: Task[];
    stats: {
        shifts_today: number;
        meds_due: number;
        meds_overdue: number;
        tasks_open: number;
        timesheets_pending: number;
        incidents_open: number;
        cr_alerts: number;
        notifications_unread: number;
    };
    pending_claims_count: number;
    leave: {
        balances: Array<{
            type: string;
            remaining_hours: number;
            total_hours: number;
        }>;
        pending_requests: number;
    };
    is_manager: boolean;
    manager_data?: {
        team_shifts_today: number;
        unassigned_shifts: number;
        timesheets_pending_approval: number;
        staff_on_today: number;
    };
    clock?: ClockState;
    active_round?: ActiveRound | null;
    next_shift_briefing?: PreShiftBriefing | null;
    previous_shift?: PreviousShift | null;
    labels?: Record<string, string>;
    flash?: {
        clock_out_blockers?: EndOfShiftBlocker[] | null;
    };
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

type OpenItemFilter = 'all' | 'alert' | 'incident' | 'followup';

const priorityOrder: Record<string, number> = {
    critical: 0,
    high: 1,
    medium: 2,
    low: 3,
};

// Map the controller's `MedDue.status` into the worker-facing `med` vocabulary
// via `status-vocab.ts`. Anything not-yet-given from the backend is "Due"; the
// overdue distinction is surfaced separately as a header badge.
function medStatusToWorkerState(
    s: MedDue['status'],
): 'due' | 'given' | 'missed' {
    return mapMedStatus(s) ?? 'due';
}

/* -------------------------------------------------------------------------- */
/*  KPI pill — compact frontline summary item                                 */
/* -------------------------------------------------------------------------- */

function HomeKpi({
    label,
    value,
    icon: Icon,
    tone = 'default',
}: {
    label: string;
    value: number | string;
    icon: typeof Calendar;
    tone?: 'default' | 'warn' | 'danger';
}) {
    const ring =
        tone === 'danger'
            ? 'border-status-critical/30 bg-status-critical-bg dark:border-status-critical/60'
            : tone === 'warn'
              ? 'border-status-warning/30 bg-status-warning-bg dark:border-status-warning/60'
              : 'border-border bg-card';
    const iconTone =
        tone === 'danger'
            ? 'text-status-critical dark:text-status-critical'
            : tone === 'warn'
              ? 'text-status-warning dark:text-status-warning'
              : 'text-muted-foreground';
    return (
        <div
            className={`flex items-center gap-3 rounded-lg border px-3 py-2.5 ${ring}`}
        >
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-background/60">
                <Icon className={`h-4 w-4 ${iconTone}`} />
            </div>
            <div className="min-w-0">
                <div className="text-lg leading-none font-semibold">
                    {value}
                </div>
                <div className="mt-0.5 text-xs text-muted-foreground">
                    {label}
                </div>
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function MyDay({
    today,
    shifts,
    medications_due,
    timesheets,
    incidents,
    tasks,
    stats,
    pending_claims_count,
    leave,
    is_manager,
    manager_data,
    clock,
    active_round,
    next_shift_briefing,
    previous_shift,
    labels,
    flash,
}: Props) {
    const [openItemFilter, setOpenItemFilter] = useState<OpenItemFilter>('all');

    // PR 6 — replace silent `setInterval(router.reload, 60s)` with a guarded,
    // visible refresh. `useLiveRefresh` suppresses the tick while an input is
    // focused, a modal is open, or the tab is hidden, so content never shifts
    // under an actively-interacting worker. The `RefreshPill` surfaces
    // freshness in the header and lets the worker refresh on demand.
    const { lastUpdatedAt, isRefreshing, refreshNow } = useLiveRefresh();

    // PR 21 — wrap "Send" in a 5 s undo window so an accidental tap on a
    // draft timesheet row doesn't immediately commit it for approval. We
    // delay the POST rather than reverse after the fact, so auditability
    // is unchanged: nothing is submitted unless the timer elapses.
    const { run: runUndoable } = useUndoableAction();
    const [pendingTimesheetIds, setPendingTimesheetIds] = useState<
        Record<number, true>
    >({});
    const t = (key: string, fallback: string) => labels?.[key] ?? fallback;
    const flashedClockOutBlockers = flash?.clock_out_blockers ?? [];

    const handleTimesheetSubmit = (tsId: number) => {
        if (pendingTimesheetIds[tsId]) return;
        setPendingTimesheetIds((prev) => ({ ...prev, [tsId]: true }));
        runUndoable({
            message: 'Timesheet sending…',
            durationMs: 5000,
            onCommit: () => {
                // Action endpoint URL is unchanged (see routes/web.php comment).
                router.post(
                    `/my-tasks/timesheet/${tsId}/submit`,
                    {},
                    {
                        preserveScroll: true,
                        onFinish: () => {
                            setPendingTimesheetIds((prev) => {
                                const next = { ...prev };
                                delete next[tsId];
                                return next;
                            });
                        },
                    },
                );
            },
            onUndo: () => {
                setPendingTimesheetIds((prev) => {
                    const next = { ...prev };
                    delete next[tsId];
                    return next;
                });
            },
            undoneMessage: 'Timesheet still in draft.',
        });
    };

    // "Action needed" = CR alerts + open incidents + follow-ups + overdue meds.
    // This is the single number a frontline worker should be looking at; it
    // intentionally excludes routine shift/timesheet counts.
    const actionNeededCount =
        stats.cr_alerts +
        stats.incidents_open +
        (tasks?.filter(
            (t) => t.type === 'followup' || t.type === 'note_followup',
        ).length ?? 0) +
        stats.meds_overdue;

    // Build the unified Open Items list — reused from the old page, trimmed.
    // PR 17 — every row is now rendered by <AlertRow>, so we carry enough
    // metadata (due_at, sla_status, alert_id, can_ack/can_snooze) to let the
    // row render its urgency badge and the right inline actions.
    type OpenItem = AlertRowItem & {
        shift_status?: string;
        incident_status?: string;
    };
    const openItems: OpenItem[] = [];

    tasks.forEach((t) => {
        openItems.push({
            id: `task-${t.id}`,
            type: t.type,
            title: t.title,
            priority: t.priority,
            client_name: t.meta.client_name,
            url: t.source_url,
            time: t.created_at,
            due_at: t.due_at,
            sla_status: t.meta.sla_status,
            alert_id: t.meta.alert_id ?? null,
            can_ack: t.meta.can_ack ?? false,
            can_snooze: t.meta.can_snooze ?? false,
        });
    });

    incidents.forEach((inc) => {
        openItems.push({
            id: `inc-${inc.id}`,
            type: 'incident',
            title: inc.title,
            priority: inc.severity,
            client_name: inc.client_name ?? undefined,
            url: inc.url,
            time: inc.occurred_at,
            incident_status: inc.status,
        });
    });

    const filteredOpenItems =
        openItemFilter === 'all'
            ? openItems
            : openItemFilter === 'incident'
              ? openItems.filter((i) => i.type === 'incident')
              : openItemFilter === 'alert'
                ? openItems.filter((i) => i.type === 'alert')
                : openItems.filter(
                      (i) =>
                          i.type === 'followup' || i.type === 'note_followup',
                  );

    const sortedOpenItems = [...filteredOpenItems].sort((a, b) => {
        const pa = priorityOrder[a.priority] ?? 3;
        const pb = priorityOrder[b.priority] ?? 3;
        if (pa !== pb) return pa - pb;
        return new Date(b.time).getTime() - new Date(a.time).getTime();
    });

    const openItemCounts: Record<OpenItemFilter, number> = {
        all: openItems.length,
        alert: openItems.filter((i) => i.type === 'alert').length,
        incident: openItems.filter((i) => i.type === 'incident').length,
        followup: openItems.filter(
            (i) => i.type === 'followup' || i.type === 'note_followup',
        ).length,
    };

    // Sort meds: overdue first, then due, then upcoming.
    const sortedMeds = [...medications_due].sort((a, b) => {
        const statusOrder = { overdue: 0, due: 1, upcoming: 2 };
        const sd = statusOrder[a.status] - statusOrder[b.status];
        if (sd !== 0) return sd;
        return (
            new Date(a.scheduled_for).getTime() -
            new Date(b.scheduled_for).getTime()
        );
    });

    const greeting = (() => {
        const hr = new Date().getHours();
        if (hr < 12) return 'Good morning';
        if (hr < 18) return 'Good afternoon';
        return 'Good evening';
    })();

    // Bottom nav Clock item reflects the real attendance state:
    //   - clocked in  → "On shift" + pulsing dot, deep-links to clock card
    //   - one eligible shift → "Clock" deep-links to clock card (ready to go)
    //   - multi eligible     → "Pick shift" deep-links to the inline picker
    //   - no context         → "Clock" falls back to /attendance (history + fix)
    // The deep-link hash scroll is smoothed by `scroll-mt` on the card itself.
    const isClockedIn = !!clock?.open_session;
    const hasSingleShift = !!clock?.active_shift;
    const eligibleCount = clock?.eligible_shift_count ?? 0;
    const isAmbiguous = !isClockedIn && !hasSingleShift && eligibleCount > 1;
    const hasClockContext = isClockedIn || hasSingleShift || isAmbiguous;

    const clockLabel = isClockedIn
        ? 'On shift'
        : isAmbiguous
          ? 'Pick shift'
          : 'Clock';

    const bottomNavItems = useMemo<StaffBottomNavItem[]>(() => {
        const clockBadge = isClockedIn ? (
            <span
                aria-hidden
                className="block h-2 w-2 rounded-full bg-status-success"
            />
        ) : isAmbiguous ? (
            <span
                aria-hidden
                className="block h-2 w-2 rounded-full bg-status-warning"
            />
        ) : undefined;

        return [
            { key: 'home', label: 'Home', icon: Home, href: '/my-day' },
            { key: 'meds', label: 'Meds', icon: Pill, href: '/meds/today' },
            {
                key: 'clock',
                label: clockLabel,
                icon: Clock,
                href: hasClockContext ? '/my-day#clock' : '/attendance',
                badge: clockBadge,
            },
            {
                key: 'report',
                label: 'Report',
                icon: ClipboardList,
                href: '/incidents',
            },
            { key: 'more', label: 'More', icon: Menu, href: '/' },
        ];
    }, [clockLabel, hasClockContext, isAmbiguous, isClockedIn]);

    const headerAction = (
        <div className="flex items-center gap-1.5">
            <RefreshPill
                lastUpdatedAt={lastUpdatedAt}
                isRefreshing={isRefreshing}
                onRefresh={refreshNow}
            />
            <Button
                variant="ghost"
                size="icon"
                asChild
                className="relative h-11 w-11"
            >
                <Link
                    href="/notifications"
                    aria-label={
                        stats.notifications_unread > 0
                            ? `Notifications, ${stats.notifications_unread} unread`
                            : 'Notifications'
                    }
                >
                    <Bell aria-hidden className="h-5 w-5" />
                    {stats.notifications_unread > 0 && (
                        <span
                            aria-hidden
                            className="absolute top-1 right-1 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-status-critical px-1 text-[10px] font-bold text-white"
                        >
                            {stats.notifications_unread}
                        </span>
                    )}
                </Link>
            </Button>
        </div>
    );

    return (
        <StaffPageShell
            title={greeting}
            subtitle={today}
            headerAction={headerAction}
            bottomNavItems={bottomNavItems}
        >
            <Head title={t('my_day', 'My Day')} />

            <div className="mx-auto w-full max-w-5xl space-y-5">
                <ClockOutBlockerAlert blockers={flashedClockOutBlockers} />

                {/* ── Shift lifecycle hero slot ─────────────────────────── */}
                {clock?.open_session ? (
                    <ActiveShiftCard session={clock.open_session} />
                ) : next_shift_briefing ? (
                    <PreShiftBriefingCard briefing={next_shift_briefing} />
                ) : previous_shift ? (
                    <PreviousShiftCard shift={previous_shift} />
                ) : (
                    <section className="rounded-xl border bg-card p-4 shadow-sm">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="text-base font-semibold">
                                    No shift today
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    You can still check upcoming work from your
                                    roster.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href="/my-roster">View roster</Link>
                            </Button>
                        </div>
                    </section>
                )}

                {/* ── Handover read prompt (PR 11) ───────────────────────── */}
                {clock?.active_shift?.incoming_handover &&
                    !clock.open_session &&
                    !next_shift_briefing && (
                        <HandoverReadCard
                            handover={clock.active_shift.incoming_handover}
                        />
                    )}

                {/* ── Frontline clock (PR 4) ─────────────────────────────── */}
                {clock && !clock.open_session && (
                    <ClockInCard
                        canClock={clock.can_clock}
                        openSession={clock.open_session}
                        activeShift={clock.active_shift}
                        eligibleShifts={clock.eligible_shifts}
                        eligibleShiftCount={clock.eligible_shift_count}
                    />
                )}

                {/* ── Today's roster mini ───────────────────────────────── */}
                {shifts.length > 0 && (
                    <section className="space-y-2 lg:hidden">
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="text-sm font-semibold">
                                {t('today', 'Today')}
                            </h2>
                            <Button
                                asChild
                                variant="link"
                                className="h-auto p-0 text-sm"
                            >
                                <Link href="/my-roster">View roster</Link>
                            </Button>
                        </div>
                        <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                            {shifts.map((shift) => {
                                const state = mapShiftStatus(
                                    shift.status_state ?? shift.status,
                                );

                                return (
                                    // eslint-disable-next-line no-restricted-syntax -- Shift card composes a primary Link + secondary Care Button, not a plain Card panel.
                                    <div
                                        key={shift.id}
                                        data-test="my-day-shift-card"
                                        className="min-w-52 rounded-lg border bg-card p-3"
                                    >
                                        <Link
                                            href={`/my-roster#shift-${shift.id}`}
                                            data-test="my-day-shift-primary-link"
                                            className="frontline-focus block rounded-md"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="text-sm font-medium">
                                                    {formatTime(
                                                        shift.starts_at,
                                                    )}
                                                </span>
                                                {state ? (
                                                    <StaffStatus
                                                        kind="shift"
                                                        state={state}
                                                        size="sm"
                                                    />
                                                ) : null}
                                            </div>
                                            <div className="mt-1 truncate text-sm text-muted-foreground">
                                                {shift.client.name}
                                            </div>
                                        </Link>
                                        {shift.client?.id ? (
                                            <Button
                                                asChild
                                                variant="outline"
                                                size="sm"
                                                className="mt-3 h-9 w-full justify-center gap-1.5"
                                            >
                                                <Link
                                                    href={`/operations/clients/${shift.client.id}/care`}
                                                    data-test="my-day-shift-care-action"
                                                >
                                                    <Shield className="h-4 w-4" />
                                                    Care
                                                </Link>
                                            </Button>
                                        ) : null}
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                )}

                {pending_claims_count > 0 && (
                    <Link
                        href="/operations/job-board?scope=mine"
                        data-test="pending-claims-link"
                        className="frontline-focus flex items-center justify-between gap-3 rounded-lg border bg-card p-3 text-sm hover:bg-accent lg:hidden"
                    >
                        <div className="min-w-0">
                            <div className="font-semibold">
                                Pending claims ({pending_claims_count})
                            </div>
                            <div className="mt-0.5 truncate text-muted-foreground">
                                Awaiting manager approval
                            </div>
                        </div>
                        <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                    </Link>
                )}

                {/* ── Trimmed KPI strip (3 items only) ───────────────────── */}
                {!clock?.open_session && (
                    <div className="grid grid-cols-3 gap-2 sm:gap-3">
                        <HomeKpi
                            label="Shifts today"
                            value={stats.shifts_today}
                            icon={Calendar}
                        />
                        <HomeKpi
                            label="Meds due"
                            value={stats.meds_due}
                            icon={Pill}
                            tone={stats.meds_overdue > 0 ? 'danger' : 'default'}
                        />
                        <HomeKpi
                            label="Action needed"
                            value={actionNeededCount}
                            icon={AlertTriangle}
                            tone={
                                actionNeededCount > 0
                                    ? stats.meds_overdue > 0 ||
                                      stats.cr_alerts > 0
                                        ? 'danger'
                                        : 'warn'
                                    : 'default'
                            }
                        />
                    </div>
                )}

                {/* ── Manager banner (only if a manager lands here directly) ─ */}
                {is_manager && manager_data && (
                    <Card className="border-status-info/30 bg-status-info-bg dark:border-status-info/30">
                        <CardContent className="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex flex-wrap items-center gap-x-5 gap-y-1.5 text-sm">
                                <div className="flex items-center gap-2">
                                    <Users className="h-4 w-4 text-status-info dark:text-status-info" />
                                    <span className="font-semibold">
                                        {manager_data.staff_on_today}
                                    </span>
                                    <span className="text-muted-foreground">
                                        staff on
                                    </span>
                                </div>
                                <div>
                                    <span className="font-semibold">
                                        {manager_data.unassigned_shifts}
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        unassigned
                                    </span>
                                </div>
                                <div>
                                    <span className="font-semibold">
                                        {
                                            manager_data.timesheets_pending_approval
                                        }
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        to approve
                                    </span>
                                </div>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/operations">
                                    Operations
                                    <ArrowRight className="ml-2 h-4 w-4" />
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
                    <div className="space-y-5">
                        {/* ── Active guided round (PR 9) ─────────────────────────── */}
                        {active_round && (
                            <Link
                                href={active_round.url}
                                aria-label={`${active_round.status === 'in_progress' ? 'Resume' : 'Start'} ${active_round.name}`}
                                className="frontline-focus group block rounded-xl border border-status-success/30 bg-status-success-bg p-4 transition-shadow hover:shadow-sm dark:border-status-success/60"
                            >
                                <div className="flex items-center gap-3">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-status-success text-white">
                                        <Pill className="h-5 w-5" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm leading-tight font-semibold">
                                            {active_round.status ===
                                            'in_progress'
                                                ? `Resume ${active_round.name}`
                                                : `Start ${active_round.name}`}
                                        </p>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {active_round.completed} of{' '}
                                            {active_round.total} done
                                            {active_round.scheduled_time
                                                ? ` · ${active_round.scheduled_time.slice(0, 5)}`
                                                : ''}
                                        </p>
                                        <Progress
                                            value={active_round.percent}
                                            className="mt-2 h-1.5"
                                        />
                                    </div>
                                    <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5" />
                                </div>
                            </Link>
                        )}

                        {/* ── Medications Due ────────────────────────────────────── */}
                        {medications_due.length > 0 && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Pill className="h-4 w-4" />
                                        Meds due
                                        {stats.meds_overdue > 0 && (
                                            <Badge
                                                variant="destructive"
                                                className="ml-1"
                                            >
                                                {stats.meds_overdue} overdue
                                            </Badge>
                                        )}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="pt-0">
                                    <ul className="divide-y">
                                        {sortedMeds.map((med, idx) => (
                                            <li
                                                key={idx}
                                                className="flex items-center justify-between gap-3 py-2.5"
                                            >
                                                <div className="min-w-0">
                                                    <div className="truncate text-sm font-medium">
                                                        <Link
                                                            href={`/operations/clients/${med.client_id}/care`}
                                                            className="hover:underline"
                                                        >
                                                            {med.client_name}
                                                        </Link>
                                                    </div>
                                                    <div className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                                                        <Link
                                                            href={med.emar_url}
                                                            className="truncate hover:underline"
                                                        >
                                                            {
                                                                med.medication_name
                                                            }
                                                        </Link>
                                                        <span>·</span>
                                                        <span className="shrink-0">
                                                            {med.dose}
                                                        </span>
                                                        <span>·</span>
                                                        <span className="shrink-0">
                                                            {formatTime(
                                                                med.scheduled_for,
                                                            )}
                                                        </span>
                                                    </div>
                                                </div>
                                                <StaffStatus
                                                    kind="med"
                                                    state={medStatusToWorkerState(
                                                        med.status,
                                                    )}
                                                    size="sm"
                                                />
                                            </li>
                                        ))}
                                    </ul>
                                </CardContent>
                            </Card>
                        )}

                        {/* ── My Timesheets ──────────────────────────────────────── */}
                        {timesheets.length > 0 && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Clock className="h-4 w-4" />
                                        {t('timesheets', 'My timesheets')}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="pt-0">
                                    <ul className="divide-y">
                                        {timesheets.map((ts) => {
                                            const tState = mapTimesheetStatus(
                                                ts.status,
                                            );
                                            const needsChanges =
                                                tState === 'needs_changes';
                                            return (
                                                <li
                                                    key={ts.id}
                                                    className="space-y-2 py-2.5 text-sm"
                                                >
                                                    <div className="flex items-center justify-between gap-3">
                                                        <div className="min-w-0">
                                                            <div className="flex items-center gap-2">
                                                                <span className="font-medium">
                                                                    {
                                                                        ts.work_date
                                                                    }
                                                                </span>
                                                                {ts.client_name && (
                                                                    <span className="truncate text-muted-foreground">
                                                                        ·{' '}
                                                                        {
                                                                            ts.client_name
                                                                        }
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <div className="mt-0.5 text-xs text-muted-foreground">
                                                                {ts.hours}h
                                                            </div>
                                                        </div>
                                                        <div className="flex shrink-0 items-center gap-2">
                                                            {tState &&
                                                            !needsChanges ? (
                                                                <StaffStatus
                                                                    kind="timesheet"
                                                                    state={
                                                                        tState
                                                                    }
                                                                    size="sm"
                                                                />
                                                            ) : null}
                                                            {ts.status ===
                                                                'draft' && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    disabled={
                                                                        !!pendingTimesheetIds[
                                                                            ts
                                                                                .id
                                                                        ]
                                                                    }
                                                                    onClick={() =>
                                                                        handleTimesheetSubmit(
                                                                            ts.id,
                                                                        )
                                                                    }
                                                                >
                                                                    {pendingTimesheetIds[
                                                                        ts.id
                                                                    ]
                                                                        ? 'Sending…'
                                                                        : 'Send'}
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </div>
                                                    {needsChanges ? (
                                                        <TimesheetReturnBanner
                                                            timesheetId={ts.id}
                                                            returnNote={
                                                                ts.return_notes
                                                            }
                                                            timesheet={
                                                                ts as InlineTimesheet
                                                            }
                                                        />
                                                    ) : null}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </CardContent>
                            </Card>
                        )}

                        {/* ── Open Items ─────────────────────────────────────────── */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <OctagonAlert className="h-4 w-4" />
                                    Open items
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 pt-0">
                                {/* Filter tabs */}
                                <div
                                    role="tablist"
                                    aria-label="Filter open items"
                                    className="-mx-1 flex gap-1 overflow-x-auto rounded-lg border bg-muted/50 p-1"
                                >
                                    {(
                                        [
                                            { key: 'all', label: 'All' },
                                            { key: 'alert', label: 'Alerts' },
                                            {
                                                key: 'incident',
                                                label: 'Incidents',
                                            },
                                            {
                                                key: 'followup',
                                                label: 'Follow-ups',
                                            },
                                        ] as const
                                    ).map((tab) => (
                                        <Button
                                            key={tab.key}
                                            type="button"
                                            variant="ghost"
                                            role="tab"
                                            aria-selected={
                                                openItemFilter === tab.key
                                            }
                                            onClick={() =>
                                                setOpenItemFilter(tab.key)
                                            }
                                            className={`frontline-focus min-h-11 shrink-0 gap-1.5 rounded-md px-3 py-1.5 ${
                                                openItemFilter === tab.key
                                                    ? 'bg-background text-foreground shadow-sm'
                                                    : 'text-muted-foreground hover:text-foreground'
                                            }`}
                                        >
                                            {tab.label}
                                            <span
                                                className={`ml-0.5 inline-flex h-5 min-w-[20px] items-center justify-center rounded-full px-1.5 text-xs font-semibold ${
                                                    openItemFilter === tab.key
                                                        ? 'bg-primary text-primary-foreground'
                                                        : 'bg-muted-foreground/15 text-muted-foreground'
                                                }`}
                                            >
                                                {openItemCounts[tab.key]}
                                            </span>
                                        </Button>
                                    ))}
                                </div>

                                {sortedOpenItems.length === 0 ? (
                                    <div className="flex flex-col items-center py-8 text-center">
                                        <CheckCircle2 className="h-8 w-8 text-status-success" />
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            Nothing to action right now.
                                        </p>
                                    </div>
                                ) : (
                                    <ul className="space-y-2">
                                        {sortedOpenItems.map((item) => {
                                            // PR 17 — let <AlertRow> own layout + actions.
                                            // For shifts/incidents we still pass the
                                            // existing worker-vocab status chip as a
                                            // drop-in `statusChip`, so prior PRs' status
                                            // clarity is preserved.
                                            let statusChip: React.ReactNode =
                                                null;
                                            if (
                                                item.type === 'shift' &&
                                                item.shift_status
                                            ) {
                                                const s = mapShiftStatus(
                                                    item.shift_status,
                                                );
                                                if (s) {
                                                    statusChip = (
                                                        <StaffStatus
                                                            kind="shift"
                                                            state={s}
                                                            size="sm"
                                                        />
                                                    );
                                                }
                                            } else if (
                                                item.type === 'incident'
                                            ) {
                                                const s = mapIncidentStatus(
                                                    item.incident_status ??
                                                        'submitted',
                                                );
                                                if (s) {
                                                    statusChip = (
                                                        <StaffStatus
                                                            kind="incident"
                                                            state={s}
                                                            size="sm"
                                                        />
                                                    );
                                                }
                                            }
                                            return (
                                                <AlertRow
                                                    key={item.id}
                                                    item={{
                                                        ...item,
                                                        statusChip,
                                                    }}
                                                />
                                            );
                                        })}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <aside className="hidden space-y-5 lg:sticky lg:top-20 lg:block lg:self-start">
                        {shifts.length > 0 && (
                            <section className="space-y-2">
                                <div className="flex items-center justify-between gap-2">
                                    <h2 className="text-sm font-semibold">
                                        {t('today', 'Today')}
                                    </h2>
                                    <Button
                                        asChild
                                        variant="link"
                                        className="h-auto p-0 text-sm"
                                    >
                                        <Link href="/my-roster">
                                            View roster
                                        </Link>
                                    </Button>
                                </div>
                                <div className="space-y-2">
                                    {shifts.map((shift) => {
                                        const state = mapShiftStatus(
                                            shift.status_state ?? shift.status,
                                        );

                                        return (
                                            // eslint-disable-next-line no-restricted-syntax -- Shift card composes a primary Link + secondary Care Button, not a plain Card panel.
                                            <div
                                                key={shift.id}
                                                data-test="my-day-shift-card"
                                                className="rounded-lg border bg-card p-3"
                                            >
                                                <Link
                                                    href={`/my-roster#shift-${shift.id}`}
                                                    data-test="my-day-shift-primary-link"
                                                    className="frontline-focus block rounded-md"
                                                >
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span className="text-sm font-medium">
                                                            {formatTime(
                                                                shift.starts_at,
                                                            )}
                                                        </span>
                                                        {state ? (
                                                            <StaffStatus
                                                                kind="shift"
                                                                state={state}
                                                                size="sm"
                                                            />
                                                        ) : null}
                                                    </div>
                                                    <div className="mt-1 truncate text-sm text-muted-foreground">
                                                        {shift.client.name}
                                                    </div>
                                                </Link>
                                                {shift.client?.id ? (
                                                    <Button
                                                        asChild
                                                        variant="outline"
                                                        size="sm"
                                                        className="mt-3 h-9 w-full justify-center gap-1.5"
                                                    >
                                                        <Link
                                                            href={`/operations/clients/${shift.client.id}/care`}
                                                            data-test="my-day-shift-care-action"
                                                        >
                                                            <Shield className="h-4 w-4" />
                                                            Care
                                                        </Link>
                                                    </Button>
                                                ) : null}
                                            </div>
                                        );
                                    })}
                                </div>
                            </section>
                        )}

                        {pending_claims_count > 0 && (
                            <Link
                                href="/operations/job-board?scope=mine"
                                data-test="pending-claims-link"
                                className="frontline-focus flex items-center justify-between gap-3 rounded-lg border bg-card p-3 text-sm hover:bg-accent"
                            >
                                <div className="min-w-0">
                                    <div className="font-semibold">
                                        Pending claims ({pending_claims_count})
                                    </div>
                                    <div className="mt-0.5 truncate text-muted-foreground">
                                        Awaiting manager approval
                                    </div>
                                </div>
                                <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                            </Link>
                        )}

                        <Link
                            href="/hr/my"
                            className="frontline-focus flex items-center justify-between gap-3 rounded-lg border bg-card p-3 text-sm hover:bg-accent"
                        >
                            <div className="min-w-0">
                                <div className="font-semibold">My HR</div>
                                <div className="mt-0.5 truncate text-muted-foreground">
                                    {leave.pending_requests > 0
                                        ? `${leave.pending_requests} leave request${leave.pending_requests === 1 ? '' : 's'} pending`
                                        : `${leave.balances.length} leave balance${leave.balances.length === 1 ? '' : 's'} available`}
                                </div>
                            </div>
                            <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                        </Link>

                        <div className="grid gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/control-room">
                                    <Shield className="mr-2 h-4 w-4" />
                                    Control room
                                </Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/control-room/alerts">
                                    <ListTodo className="mr-2 h-4 w-4" />
                                    All alerts
                                </Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/meds/today">
                                    <Pill className="mr-2 h-4 w-4" />
                                    Meds today
                                </Link>
                            </Button>
                        </div>
                    </aside>
                </div>

                {/* ── My HR mini ────────────────────────────────────────── */}
                <Link
                    href="/hr/my"
                    className="frontline-focus flex items-center justify-between gap-3 rounded-lg border bg-card p-3 text-sm hover:bg-accent lg:hidden"
                >
                    <div className="min-w-0">
                        <div className="font-semibold">My HR</div>
                        <div className="mt-0.5 truncate text-muted-foreground">
                            {leave.pending_requests > 0
                                ? `${leave.pending_requests} leave request${leave.pending_requests === 1 ? '' : 's'} pending`
                                : `${leave.balances.length} leave balance${leave.balances.length === 1 ? '' : 's'} available`}
                        </div>
                    </div>
                    <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                </Link>

                {/* ── Footer quick links ─────────────────────────────────── */}
                <div className="flex flex-wrap gap-2 pt-1 lg:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/control-room">
                            <Shield className="mr-2 h-4 w-4" />
                            Control room
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/control-room/alerts">
                            <ListTodo className="mr-2 h-4 w-4" />
                            All alerts
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/meds/today">
                            <Pill className="mr-2 h-4 w-4" />
                            Meds today
                        </Link>
                    </Button>
                </div>
            </div>
        </StaffPageShell>
    );
}
