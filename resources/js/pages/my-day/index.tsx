import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CalendarCheck,
    CheckCircle2,
    ClipboardCheck,
    HeartPulse,
    Home,
    ListChecks,
    Pill,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { ChecklistConfigProvider } from '@/components/checklists/context';
import { CategoryIcon, StatusBadge } from '@/components/checklists/primitives';
import { RunModal } from '@/components/checklists/run-modal';
import EndOfShiftChecklist, {
    type EndOfShiftBlocker,
} from '@/components/end-of-shift-checklist';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import useLiveRefresh from '@/hooks/use-live-refresh';
import AppLayout from '@/layouts/app-layout';
import { formatRelative, formatTime } from '@/lib/datetime';

import {
    MealLogDialog,
    TimesheetReviewDialog,
    VitalsRecordDialog,
    WriteHandoverDialog,
} from './_dialogs';

import { BeforeYouFinish } from './components/before-you-finish';
import { DayWorkList } from './components/day-work-list';
import { DigestPanel } from './components/digest-panel';
import {
    MyDayHeader,
    type MyDayView,
    type WorkFilter,
} from './components/my-day-header';
import { PaperworkPanel } from './components/paperwork-panel';
import { QuickAddTask } from './components/quick-add-task';
import { RecordCareActions } from './components/record-care-actions';
import { ShiftSummary } from './components/shift-summary';
import { TaskDetailDialog } from './components/task-detail-dialog';
import { TaskHelpInbox } from './components/task-help-inbox';
import { TomorrowPanel } from './components/tomorrow-panel';
import { residentHue, residentInitials } from './lib/resident-hue';
import { buildStream } from './lib/stream-grouping';
import type {
    MyDayActiveRound,
    MyDayActiveSite,
    MyDayFirstAidFollowup,
    MyDayLoneWorkerSession,
    MyDayMedDue,
    MyDayMyTasks,
    MyDayPageProps,
    MyDayPpe,
    MyDayResident,
    MyDayShiftTask,
    MyDayTaskFollowup,
    MyDayTimesheet,
    ShiftChecklistRun,
} from './lib/types';
import { workDueAt, workIsDone } from './lib/work-priority';

/* -------------------------------------------------------------------------- */
/*  /my-day — desktop frontline home                                          */
/* -------------------------------------------------------------------------- */
/*
 * Site-first redesign for the desktop web application. The page is
 * intentionally web-only (≥768 px); no native application surface is part of
 * this product scope.
 *
 * Shared Event Horizon header; direct Today / Handover / My shift views;
 * priority work beside clear shift and recording actions.
 */

interface AuthUser {
    id: number;
    name?: string;
    first_name?: string;
    last_name?: string;
    role?: string;
    initials?: string;
}

interface SharedAuth {
    user?: AuthUser | null;
    can?: {
        timesheets?: { create?: boolean };
        staff?: {
            availabilityUpdateSelf?: boolean;
        };
    };
}

interface SharedProps extends Partial<MyDayPageProps> {
    auth?: SharedAuth;
    /** Worker has `clinical.observations.record` (basic observation types). */
    can_record_observation?: boolean;
    /** Worker has `clinical.observations.recordClinical` (vitals + pain). */
    can_record_clinical?: boolean;
    [key: string]: unknown;
}

export default function MyDay() {
    const page = usePage<SharedProps>();
    const props = page.props as MyDayPageProps & {
        auth?: SharedAuth;
        can_record_observation?: boolean;
        can_record_clinical?: boolean;
    };
    const auth = props.auth;

    const availabilityHref =
        auth?.user?.id && auth?.can?.staff?.availabilityUpdateSelf
            ? `/staff/${auth.user.id}/availability`
            : null;

    const [view, setView] = useState<MyDayView>('today');
    const [search, setSearch] = useState('');
    const [workFilter, setWorkFilter] = useState<WorkFilter>('all');
    const [addTaskOpen, setAddTaskOpen] = useState(false);
    const [newTaskTime, setNewTaskTime] = useState<number>();
    const openNewTask = (at?: number) => {
        setNewTaskTime(at);
        setAddTaskOpen(true);
    };
    const [openTaskId, setOpenTaskId] = useState<number | null>(null);
    const [savedTasks, setSavedTasks] = useState<
        Record<number, MyDayShiftTask>
    >({});
    const updateTask = (task: MyDayShiftTask) => {
        setSavedTasks((current) => ({ ...current, [task.id]: task }));
        router.reload({
            only: [
                'active_shift',
                'clock',
                'task_creation',
                'help_requests',
                'handover',
                'handover_draft',
                'outgoing_handover',
            ],
            preserveScroll: true,
            onSuccess: () =>
                setSavedTasks((current) => {
                    if ((current[task.id]?.version ?? -1) > (task.version ?? 0))
                        return current;
                    const next = { ...current };
                    delete next[task.id];
                    return next;
                }),
        });
    };

    // Active resident filter (multi-resident sites only).
    const [activeResidentId, setActiveResidentId] = useState<'all' | number>(
        'all',
    );

    // End-of-shift + outgoing-handover sheets — both reuse the existing
    // components already shipped for the legacy clock-in/active-shift cards.
    const [endShiftOpen, setEndShiftOpen] = useState(false);
    const [handoverWriteOpen, setHandoverWriteOpen] = useState(false);

    // Vitals & obs picker flow.
    const [vitalsOpen, setVitalsOpen] = useState(false);
    const [mealLogOpen, setMealLogOpen] = useState(false);

    // Site checklist run modal launched from the active shift.
    const [activeChecklistRun, setActiveChecklistRun] = useState<number | null>(
        null,
    );

    // Per-client timesheet review popup.
    const [timesheetUnderReview, setTimesheetUnderReview] =
        useState<MyDayTimesheet | null>(null);

    // Live refresh — Inertia partial reload every 60s (unless guarded).
    const {
        lastUpdatedAt,
        isRefreshing,
        refreshNow,
        hasError: refreshFailed,
    } = useLiveRefresh({
        intervalMs: 60_000,
    });

    // Wall-clock tick that drives the live CLOCKED counter in the hero. The
    // backend never sends an elapsed-minutes figure, so the headline is
    // computed client-side from the open session's clock_in_at and must
    // re-render on its own — otherwise the timer reads "0h 0m" and never moves.
    const [now, setNow] = useState(() => Date.now());

    // ──────────────────────────────────────────────────────────────────────
    // Derived shapes
    // ──────────────────────────────────────────────────────────────────────

    const activeShift = props.active_shift;
    const activeRound = props.active_round ?? null;
    const site: MyDayActiveSite | null = activeShift?.site ?? null;
    const shiftChecklists = props.shiftChecklists ?? [];
    const canViewShiftChecklists = !!props.checklistConfig?.can.view;
    const canRunShiftChecklists = !!props.checklistConfig?.can.run;
    const residents: MyDayResident[] = useMemo(
        () => site?.residents ?? [],
        [site],
    );
    const singleResident: MyDayResident | null = useMemo(() => {
        if (residents.length === 1) return residents[0];
        if (activeShift?.client) {
            const c = activeShift.client;
            const firstName = c.first_name ?? c.name.split(' ')[0] ?? '';
            const lastName = c.name.split(' ').slice(1).join(' ');
            return {
                id: c.id,
                first_name: firstName,
                name: c.name,
                initials: residentInitials(firstName, lastName),
                hue: residentHue(c.id),
                photo_url: c.photo_url ?? null,
            };
        }
        return null;
    }, [residents, activeShift]);

    // Tasks coming from the active shift only (the prototype's "What's next" stream
    // is the active shift's care plan + meds at the site for today). When there's
    // no active shift we fall back to today's shifts' first one.
    const visibleTasks: MyDayShiftTask[] = useMemo(() => {
        const source = activeShift?.tasks ?? [];
        const byId = new Map(source.map((task) => [task.id, task]));
        Object.values(savedTasks)
            .filter((task) => task.shift_id === activeShift?.id)
            .forEach((task) => {
                const server = byId.get(task.id);
                if (!server || (task.version ?? 0) >= (server.version ?? 0))
                    byId.set(task.id, { ...server, ...task });
            });
        return [...byId.values()].map((task) => ({
            ...task,
            client_id:
                task.task_scope === 'site'
                    ? null
                    : (task.client_id ?? activeShift?.client?.id ?? null),
        }));
    }, [activeShift, savedTasks]);

    const helpRequests = (props.help_requests ?? [])
        .map((task) => {
            const saved = savedTasks[task.id];
            return saved && (saved.version ?? 0) >= (task.version ?? 0)
                ? { ...task, ...saved }
                : task;
        })
        .filter(
            (task) => !task.is_completed && task.help?.status !== 'declined',
        );

    const visibleMeds: MyDayMedDue[] = useMemo(
        () => props.medications_due ?? [],
        [props.medications_due],
    );

    const filteredTasks = useMemo(() => {
        if (activeResidentId === 'all') return visibleTasks;
        return visibleTasks.filter(
            (t) => t.client_id === activeResidentId || t.task_scope === 'site',
        );
    }, [activeResidentId, visibleTasks]);

    const filteredMeds = useMemo(() => {
        if (activeResidentId === 'all') return visibleMeds;
        return visibleMeds.filter((m) => m.client_id === activeResidentId);
    }, [activeResidentId, visibleMeds]);

    const stream = useMemo(
        () =>
            buildStream({
                tasks: filteredTasks,
                meds: filteredMeds,
                residentFilter:
                    activeResidentId === 'all' ? null : activeResidentId,
                fallbackClientId: singleResident?.id ?? null,
                includeSiteTasks: true,
            }),
        [filteredTasks, filteredMeds, activeResidentId, singleResident],
    );

    const overdueMeds = visibleMeds.filter((med) => med.status === 'overdue');
    const attentionTasks = visibleTasks.filter(
        (task) =>
            !task.is_completed &&
            task.follow_through !== 'accepted_help' &&
            ((task.scheduled_for && Date.parse(task.scheduled_for) <= now) ||
                ['requested', 'declined', 'accepted'].includes(
                    task.help?.status ?? '',
                )),
    );
    const openItemTasks = (props.tasks ?? []).filter((t) =>
        ['alert', 'incident', 'followup', 'note_followup'].includes(t.type),
    );
    const openItemsCount =
        openItemTasks.length +
        (props.incidents?.length ?? 0) +
        overdueMeds.length +
        attentionTasks.length +
        helpRequests.filter((task) => task.help?.status === 'requested').length;

    // Clock & shift labels
    const openSession = props.clock?.open_session ?? null;
    const clockedIn = !!openSession;
    const isOnBreak = !!openSession?.is_on_break;

    // CLOCKED headline ticks up from the session's clock-in time. The backend
    // doesn't report elapsed minutes, so derive it from clock_in_at against the
    // ticking `now` above and re-arm a 30s interval while a session is open.
    const clockInAt = openSession?.clock_in_at ?? null;
    useEffect(() => {
        setNow(Date.now());
        const id = setInterval(() => setNow(Date.now()), 30_000);
        return () => clearInterval(id);
    }, [clockInAt]);
    const clockedMinutes = clockInAt
        ? Math.max(
              0,
              Math.floor((now - new Date(clockInAt).getTime()) / 60_000),
          )
        : 0;
    const clockedLabel = `${Math.floor(clockedMinutes / 60)}h ${clockedMinutes % 60}m`;
    const checklistProviderValue = useMemo(() => {
        if (!props.checklistConfig || !site) return null;

        return {
            categories: props.checklistConfig.categories,
            categoryMap: Object.fromEntries(
                props.checklistConfig.categories.map((category) => [
                    category.key,
                    category,
                ]),
            ),
            freqLabels: props.checklistConfig.frequencyLabels,
            typeLabels: props.checklistConfig.typeLabels,
            today: props.checklistConfig.today,
            can: {
                view: props.checklistConfig.can.view,
                run: props.checklistConfig.can.run,
                schedule: false,
                manageTemplates: false,
            },
            scope: {
                mode: 'site' as const,
                site: {
                    id: site.id,
                    name: site.name,
                    type: site.type,
                },
                backHref: '/my-day',
            },
            assignableUsers: [],
            openRun: setActiveChecklistRun,
            openBuilder: () => {},
        };
    }, [props.checklistConfig, site]);

    // ──────────────────────────────────────────────────────────────────────
    // Mutations
    // ──────────────────────────────────────────────────────────────────────

    // PR 4.5 removed the legacy `/my-tasks/clock/{in,out}` shortcuts; the
    // canonical clock flow goes through AttendanceController so the open
    // HrAttendanceSession + draft timesheet are written through the service.
    //
    // Clocking out is a multi-step affair (review tasks, capture break minutes,
    // optionally write the outgoing handover, attach override reason if there
    // are still blockers). Delegating to `EndOfShiftChecklist` keeps the same
    // surface the legacy clock-in/active-shift cards use, so the back-end can
    // trust the payload it receives.
    const handleClockToggle = () => {
        if (clockedIn) {
            setEndShiftOpen(true);
            return;
        }
        const shiftId = activeShift?.id ?? props.shifts?.[0]?.id;
        router.post(
            '/attendance/clock-in',
            shiftId ? { shift_id: shiftId } : {},
            { preserveScroll: true },
        );
    };

    const handleWriteHandover = useCallback(() => {
        if (!openSession?.shift_id) return;
        setHandoverWriteOpen(true);
    }, [openSession?.shift_id]);

    const handleBreakToggle = useCallback(() => {
        if (!openSession?.id) return;
        router.post(
            isOnBreak ? '/attendance/break/end' : '/attendance/break/start',
            { session_id: openSession.id },
            { preserveScroll: true },
        );
    }, [isOnBreak, openSession?.id]);

    // The "Today's timesheet" hero button is the worker's one-click entry
    // into the per-client allocation popup for today's shift. If a draft /
    // returned timesheet already exists, open it locally. Otherwise call the
    // existing ensure-today endpoint; it finds-or-creates the draft and flashes
    // `open_timesheet_id`, which the effect below uses to open the refreshed
    // popup without sending the worker away from /my-day.
    const todaysTimesheet = useMemo<MyDayTimesheet | null>(() => {
        if (!activeShift) return null;
        return (
            (props.timesheets ?? []).find(
                (ts) =>
                    ts.shift_id === activeShift.id &&
                    (ts.status === 'draft' || ts.status === 'returned'),
            ) ?? null
        );
    }, [props.timesheets, activeShift]) as MyDayTimesheet | null;

    const handleOpenTimesheets = useCallback(() => {
        if (!activeShift) {
            setView('shift');
            return;
        }
        if (todaysTimesheet) {
            setTimesheetUnderReview(todaysTimesheet);
            return;
        }
        router.post(
            '/my-tasks/timesheet/ensure-today',
            { shift_id: activeShift?.id },
            {
                preserveScroll: true,
                // ensure-today returns `back()->withErrors(['timesheet' => …])` when
                // there's no shift today. Without this the button looked dead.
                onError: (errors) => {
                    toast.error(
                        errors.timesheet ?? 'No timesheet to open for today.',
                    );
                },
            },
        );
    }, [todaysTimesheet, activeShift]);

    // Inertia flash `open_timesheet_id` is set by /ensure-today after it
    // finds-or-creates a draft for today. When we see it land, look up the
    // matching timesheet in the (now-refreshed) props and pop the review
    // dialog open.
    //
    // The flash prop is a one-shot signal but it survives in `props` until
    // the next Inertia visit. Without a guard the effect re-fires every
    // time the user closes the popup → state change → re-render → reopen.
    // Track the last id we handled in a ref so we open the popup exactly
    // once per ensure-today round-trip.
    const lastHandledFlashId =
        (props as { flash?: { open_timesheet_id?: number } }).flash
            ?.open_timesheet_id ?? null;
    const handledFlashIdRef = useRef<number | null>(null);
    useEffect(() => {
        if (!lastHandledFlashId) return;
        if (handledFlashIdRef.current === lastHandledFlashId) return;
        const fresh = (props.timesheets ?? []).find(
            (ts) => ts.id === lastHandledFlashId,
        );
        if (fresh) {
            handledFlashIdRef.current = lastHandledFlashId;
            setTimesheetUnderReview(fresh as MyDayTimesheet);
        }
    }, [lastHandledFlashId, props.timesheets]);

    const handleConfirmHandoverRead = useCallback(() => {
        const handoverId = props.handover?.id;
        if (!handoverId) return;
        router.patch(
            `/attendance/handover/${handoverId}/acknowledge`,
            {},
            { preserveScroll: true },
        );
    }, [props.handover?.id]);

    // Lone Worker Safety — worker self check-in (the "You're being monitored"
    // card). Both actions POST to the existing coordinator check-in endpoint;
    // the route is auth-only and LoneWorkerController@checkIn authorizes the
    // session's own worker. Success / failure surface via the global flash
    // toaster, so there's no bespoke toast here.
    const loneWorkerSessionId = props.active_lone_worker_session?.id ?? null;
    const handleLoneWorkerCheckIn = useCallback(() => {
        if (!loneWorkerSessionId) return;
        router.post(
            `/health-safety/lone-workers/sessions/${loneWorkerSessionId}/check-in`,
            { status: 'ok' },
            { preserveScroll: true },
        );
    }, [loneWorkerSessionId]);

    const handleLoneWorkerEmergency = useCallback(() => {
        if (!loneWorkerSessionId) return;
        if (
            !confirm(
                'Send an emergency alert? Your coordinator and the Control Room will be notified immediately that you need help.',
            )
        ) {
            return;
        }
        router.post(
            `/health-safety/lone-workers/sessions/${loneWorkerSessionId}/check-in`,
            { status: 'emergency' },
            { preserveScroll: true },
        );
    }, [loneWorkerSessionId]);

    // My PPE — worker self-acknowledges their own issued PPE. The acknowledge-own
    // route is auth-only + ownership-checked, so support workers (no hazards.* perms)
    // can confirm receipt from their own My Day.
    const handleAcknowledgePpe = useCallback((allocationId: number) => {
        router.post(
            `/health-safety/ppe/allocations/${allocationId}/acknowledge-own`,
            {},
            { preserveScroll: true },
        );
    }, []);

    const handleAddNote = useCallback((clientId: number | null | undefined) => {
        if (!clientId) {
            router.visit('/clients');
            return;
        }
        // The clients/{id}/daily-notes endpoint is JSON-only — land the worker
        // on the client profile's Daily Notes tab instead (Inertia page).
        router.visit(`/clients/${clientId}?tab=progress_notes`);
    }, []);

    const handleAckAlert = useCallback((alert: MyDayTaskFollowup) => {
        const alertId = alert.meta?.alert_id;
        if (!alertId) return;
        router.post(
            `/my-day/alerts/${alertId}/ack`,
            {},
            { preserveScroll: true },
        );
    }, []);

    const handleSnoozeAlert = useCallback((alert: MyDayTaskFollowup) => {
        const alertId = alert.meta?.alert_id;
        if (!alertId) return;
        // MyDayActionsController::snoozeAlert reads `window` (15m/1h/shift),
        // not `minutes` — passing the wrong key silently fell through to the
        // 15-minute default. Match the controller contract so the UI and
        // backend agree.
        router.post(
            `/my-day/alerts/${alertId}/snooze`,
            { window: '15m' },
            { preserveScroll: true },
        );
    }, []);

    // PaperworkPanel's submit button now opens the TimesheetReviewDialog so
    // the worker can review (and edit) the per-client allocation breakdown
    // before submitting. The dialog itself owns the POST to
    // `/my-tasks/timesheet/{id}/submit`; we just expose which timesheet is
    // under review.
    const handleTimesheetSubmit = useCallback((ts: MyDayTimesheet) => {
        setTimesheetUnderReview(ts);
    }, []);

    const workItems = stream.filter((item) => {
        if (workFilter === 'tasks' && item.kind !== 'task') return false;
        if (workFilter === 'meds' && item.kind !== 'med') return false;
        if (
            workFilter === 'attention' &&
            (workIsDone(item) ||
                (item.kind === 'task'
                    ? !attentionTasks.some((task) => task.id === item.data.id)
                    : workDueAt(item) > now))
        )
            return false;
        const label =
            item.kind === 'task' ? item.data.label : item.data.medication_name;
        const name =
            residents.find((person) => person.id === item.clientId)?.name ??
            (item.clientId === null ? 'Whole site' : '');
        return (
            !search.trim() ||
            (label + ' ' + name)
                .toLowerCase()
                .includes(search.trim().toLowerCase())
        );
    });
    const clearFilters = () => {
        setActiveResidentId('all');
        setSearch('');
        setWorkFilter('all');
    };
    const helperTaskUnderReview = helpRequests.find(
        (task) => task.id === openTaskId,
    );
    const taskUnderReview =
        visibleTasks.find((task) => task.id === openTaskId) ??
        helperTaskUnderReview;
    const people = residents.length
        ? residents
        : singleResident
          ? [singleResident]
          : [];
    const canAddTask = !!props.task_creation?.can_create;
    const headerTasks = view === 'today' ? filteredTasks : visibleTasks;
    const headerMeds = view === 'today' ? filteredMeds : visibleMeds;
    const digest = (
        <DigestPanel
            mode={view === 'today' ? 'attention' : 'handover'}
            handover={props.handover ?? null}
            alertTasks={openItemTasks}
            incidents={props.incidents ?? []}
            notifications={props.notifications ?? []}
            onAckAlert={handleAckAlert}
            onSnoozeAlert={handleSnoozeAlert}
            onConfirmHandoverRead={handleConfirmHandoverRead}
            onFollowUpAdded={updateTask}
            onOpenTask={setOpenTaskId}
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'My Day', href: '/my-day' },
            ]}
            contentClassName="my-day-desktop w-full p-5"
        >
            <Head title="My Day" />

            <MyDayHeader
                unavailable={props.data_unavailable}
                dateLabel={props.today}
                siteName={site?.name ?? activeShift?.location ?? ''}
                shiftLabel={
                    activeShift
                        ? formatTime(activeShift.starts_at) +
                          ' – ' +
                          formatTime(activeShift.ends_at)
                        : 'No rostered shift'
                }
                clockedIn={clockedIn}
                hasShift={!!activeShift}
                onBreak={isOnBreak}
                residents={people}
                person={activeResidentId}
                onPerson={setActiveResidentId}
                search={search}
                onSearch={setSearch}
                workFilter={workFilter}
                onWorkFilter={setWorkFilter}
                view={view}
                onView={setView}
                taskTotal={headerTasks.length}
                taskDone={
                    headerTasks.filter((task) => task.is_completed).length
                }
                medTotal={headerMeds.length}
                medRecorded={
                    headerMeds.filter((med) =>
                        ['given', 'refused', 'withheld'].includes(med.status),
                    ).length
                }
                attention={openItemsCount}
                unreadHandover={!!props.handover?.unread}
                canAdd={canAddTask}
                onAdd={() => openNewTask()}
            />
            <div className="h-5" aria-hidden="true" />
            {(refreshFailed || !!props.data_unavailable?.length) && (
                <Card role="alert" className="mb-5 border-status-warning/30">
                    <CardContent className="space-y-2 p-4">
                        <h2 className="font-semibold">
                            Some information could not be loaded
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {props.data_unavailable?.length
                                ? props.data_unavailable.join(', ') + '. '
                                : ''}
                            Showing the last information loaded. Refresh to try
                            again.
                        </p>
                        <Button
                            variant="outline"
                            onClick={refreshNow}
                            disabled={isRefreshing}
                        >
                            Retry loading
                        </Button>
                    </CardContent>
                </Card>
            )}
            {(openItemTasks.length > 0 ||
                !!props.incidents?.length ||
                overdueMeds.length > 0 ||
                helpRequests.some(
                    (task) => task.help?.status === 'requested',
                ) ||
                attentionTasks.some((task) => !!task.help)) && (
                <Card className="mb-5 border-status-warning/30 bg-status-warning-bg">
                    <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div>
                            <h2 className="font-semibold text-status-warning">
                                {openItemsCount}{' '}
                                {openItemsCount === 1
                                    ? 'item needs'
                                    : 'items need'}{' '}
                                attention
                                {activeShift ? ' across your shift' : ''}
                            </h2>
                            <p className="mt-1 text-sm">
                                {overdueMeds.length
                                    ? overdueMeds.length +
                                      ' overdue medication doses. '
                                    : ''}
                                {attentionTasks.length
                                    ? `${attentionTasks.length} ${attentionTasks.length === 1 ? 'task needs' : 'tasks need'} attention. `
                                    : ''}
                                Alerts and follow-ups stay visible when you
                                filter a person.
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setView('today');
                                clearFilters();
                                setWorkFilter('attention');
                            }}
                        >
                            Review attention items
                        </Button>
                        {overdueMeds.length > 0 && (
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setView('today');
                                    clearFilters();
                                    setWorkFilter('meds');
                                }}
                            >
                                Open medication list
                            </Button>
                        )}
                    </CardContent>
                </Card>
            )}
            {props.active_lone_worker_session ? (
                <LoneWorkerCheckInCard
                    session={props.active_lone_worker_session}
                    onCheckIn={handleLoneWorkerCheckIn}
                    onEmergency={handleLoneWorkerEmergency}
                />
            ) : null}
            {activeRound ? <ActiveRoundBanner round={activeRound} /> : null}
            <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                <div className="flex min-w-0 flex-col gap-5">
                    {view === 'today' && (
                        <TaskHelpInbox
                            tasks={helpRequests}
                            onOpen={setOpenTaskId}
                        />
                    )}
                    {view === 'today' &&
                        workFilter === 'attention' &&
                        (openItemTasks.length > 0 ||
                            !!props.incidents?.length) &&
                        digest}
                    {view === 'today' && (
                        <DayWorkList
                            items={workItems}
                            residents={people}
                            now={now}
                            canAdd={canAddTask}
                            hasShift={!!activeShift}
                            shift={activeShift}
                            filtered={
                                !!search ||
                                activeResidentId !== 'all' ||
                                workFilter !== 'all'
                            }
                            onClearFilters={clearFilters}
                            onAdd={openNewTask}
                            onOpenTask={setOpenTaskId}
                            onAddNote={handleAddNote}
                        />
                    )}
                    {view === 'handover' && (
                        <>
                            <div className="flex items-center justify-between gap-3">
                                <h2 className="text-section-title">Handover</h2>
                                {openSession?.shift_id && (
                                    <Button onClick={handleWriteHandover}>
                                        Write handover
                                    </Button>
                                )}
                            </div>
                            {digest}
                        </>
                    )}
                    {(view === 'handover' || view === 'shift') &&
                        props.handover_draft &&
                        !(
                            view === 'shift' &&
                            props.outgoing_handover?.id ===
                                props.handover_draft.id
                        ) && (
                            <Card>
                                <CardContent className="flex items-center justify-between gap-4 p-5">
                                    <div>
                                        <h2 className="text-section-title">
                                            Your handover draft
                                        </h2>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Saved, but not sent yet. Review the
                                            notes and choose the incoming shift.
                                        </p>
                                    </div>
                                    <Button asChild>
                                        <Link
                                            href={
                                                props.handover_draft.review_url
                                            }
                                        >
                                            Review and send
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        )}
                    {view === 'shift' && (
                        <>
                            <div>
                                <h2 className="text-section-title">My shift</h2>
                                <p className="text-subtle mt-1">
                                    Your time, handover and next shift.
                                </p>
                            </div>
                            {activeShift && (
                                <BeforeYouFinish
                                    summary={props.outgoing_handover}
                                    timesheet={(props.timesheets ?? []).find(
                                        (sheet) =>
                                            sheet.shift_id === activeShift.id,
                                    )}
                                    workLeft={
                                        visibleTasks.filter(
                                            (task) =>
                                                !task.is_completed &&
                                                task.follow_through !==
                                                    'accepted_help',
                                        ).length +
                                        visibleMeds.filter(
                                            (med) =>
                                                ![
                                                    'given',
                                                    'refused',
                                                    'withheld',
                                                ].includes(med.status),
                                        ).length
                                    }
                                    followedUp={
                                        visibleTasks.filter(
                                            (task) =>
                                                !task.is_completed &&
                                                task.follow_through ===
                                                    'accepted_help',
                                        ).length
                                    }
                                    onNotes={
                                        openSession?.shift_id &&
                                        props.clock?.can_clock
                                            ? handleWriteHandover
                                            : undefined
                                    }
                                    onWork={() => {
                                        clearFilters();
                                        setView('today');
                                    }}
                                    onTime={
                                        auth?.can?.timesheets?.create ||
                                        props.timesheets?.some(
                                            (sheet) =>
                                                sheet.shift_id ===
                                                activeShift.id,
                                        )
                                            ? handleOpenTimesheets
                                            : undefined
                                    }
                                    unavailable={
                                        !!props.data_unavailable?.length ||
                                        refreshFailed
                                    }
                                />
                            )}
                            <div
                                className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3"
                                aria-label="Shift actions"
                            >
                                <Button
                                    variant="outline"
                                    className="h-auto min-h-24 justify-start p-5 text-left whitespace-normal"
                                    asChild
                                >
                                    <Link href="/my-calendar">
                                        <Calendar className="size-5" />
                                        <span>
                                            Open my calendar
                                            <span className="text-subtle mt-1 block">
                                                See when and where you’re
                                                working
                                            </span>
                                        </span>
                                    </Link>
                                </Button>
                                {availabilityHref && (
                                    <Button
                                        variant="outline"
                                        className="h-auto min-h-24 justify-start p-5 text-left whitespace-normal"
                                        asChild
                                    >
                                        <Link href={availabilityHref}>
                                            <CalendarCheck className="size-5" />
                                            <span>
                                                Update my availability
                                                <span className="text-subtle mt-1 block">
                                                    Let the team know when you
                                                    can work
                                                </span>
                                            </span>
                                        </Link>
                                    </Button>
                                )}
                            </div>
                            <PaperworkPanel
                                timesheets={
                                    activeShift
                                        ? (props.timesheets ?? []).filter(
                                              (sheet) =>
                                                  sheet.shift_id !==
                                                  activeShift.id,
                                          )
                                        : (props.timesheets ?? [])
                                }
                                hrTasks={props.hr_tasks ?? []}
                                onSubmitTimesheet={handleTimesheetSubmit}
                            />
                            <TomorrowPanel
                                briefing={props.next_shift_briefing ?? null}
                                heading="Next shift"
                            />
                        </>
                    )}
                </div>
                <aside className="flex min-w-0 flex-col gap-5">
                    <ShiftSummary
                        location={
                            site?.name ??
                            activeShift?.location ??
                            openSession?.location
                        }
                        startsAt={
                            activeShift?.starts_at ??
                            openSession?.shift_starts_at
                        }
                        endsAt={
                            activeShift?.ends_at ?? openSession?.shift_ends_at
                        }
                        clockInAt={clockInAt}
                        clockedIn={clockedIn}
                        onBreak={isOnBreak}
                        elapsed={clockedLabel}
                        hasShift={!!activeShift}
                        canClock={!!props.clock?.can_clock}
                        canReviewTime={
                            !!props.timesheets?.length ||
                            (!!auth?.can?.timesheets?.create && !!activeShift)
                        }
                        onClock={handleClockToggle}
                        onToggleBreak={handleBreakToggle}
                        onReviewTime={handleOpenTimesheets}
                    />
                    {view === 'today' && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-section-title">
                                    From the last shift
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <p className="border-l-2 border-border pl-3 text-sm leading-relaxed text-muted-foreground">
                                    {props.handover?.worker_notes
                                        ? `Notes for ${props.handover.worker_notes.people.map((person) => person.name).join(', ') || 'this shift'}. Open the handover to read each section.`
                                        : props.handover?.summary ||
                                          'No incoming handover is available for this shift.'}
                                </p>
                                {props.handover?.id && (
                                    <Button
                                        variant="outline"
                                        className="frontline-tap w-full"
                                        onClick={() => {
                                            setView('handover');
                                        }}
                                    >
                                        {props.handover.unread
                                            ? 'Read handover'
                                            : 'Open handover'}
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    )}
                    <RecordCareActions
                        people={people}
                        selectedPerson={
                            view === 'today' ? activeResidentId : 'all'
                        }
                        canRecordObservation={
                            !!(
                                props.can_record_observation ||
                                props.can_record_clinical
                            )
                        }
                        onNote={handleAddNote}
                        onMeal={() => setMealLogOpen(true)}
                        onObservation={() => setVitalsOpen(true)}
                        onIncident={() =>
                            router.visit(
                                activeShift
                                    ? '/incidents/create?shift_id=' +
                                          activeShift.id
                                    : '/incidents/create',
                            )
                        }
                    />

                    {props.first_aid_followups?.length ? (
                        <FirstAidFollowupsCard
                            followups={props.first_aid_followups}
                        />
                    ) : null}

                    {(props.my_ppe ?? []).length > 0 ? (
                        <MyPpeCard
                            items={props.my_ppe ?? []}
                            onAcknowledge={handleAcknowledgePpe}
                        />
                    ) : null}

                    {props.myTasks && props.myTasks.total > 0 ? (
                        <MyTasksCard tasks={props.myTasks} />
                    ) : null}

                    {activeShift &&
                    canViewShiftChecklists &&
                    shiftChecklists.length > 0 &&
                    checklistProviderValue ? (
                        <ChecklistConfigProvider value={checklistProviderValue}>
                            <ShiftChecklistsCard
                                runs={shiftChecklists}
                                canRun={canRunShiftChecklists}
                                onOpen={setActiveChecklistRun}
                            />
                        </ChecklistConfigProvider>
                    ) : null}

                    {(props.pending_claims_count ?? 0) > 0 ? (
                        <Card>
                            <CardContent className="p-4">
                                <Link
                                    href="/operations/job-board?scope=mine"
                                    data-test="pending-claims-link"
                                    className="frontline-focus flex items-center justify-between gap-4 rounded-lg"
                                >
                                    <div className="min-w-0">
                                        <div className="font-semibold">
                                            Pending claims (
                                            {props.pending_claims_count})
                                        </div>
                                        <div className="mt-0.5 text-sm text-muted-foreground">
                                            Awaiting manager approval — review
                                            your claimed shifts
                                        </div>
                                    </div>
                                    <ClipboardCheck className="h-5 w-5 shrink-0 text-primary" />
                                </Link>
                            </CardContent>
                        </Card>
                    ) : null}
                </aside>
            </div>

            <div
                className="mt-5 flex items-center justify-end gap-2 text-xs text-muted-foreground"
                aria-live="polite"
            >
                <span>
                    {isRefreshing
                        ? 'Refreshing…'
                        : (refreshFailed
                              ? 'Refresh failed. Last updated '
                              : 'Updated ') + formatRelative(lastUpdatedAt)}
                </span>
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={isRefreshing}
                    onClick={refreshNow}
                >
                    Refresh
                </Button>
            </div>

            {addTaskOpen && activeShift && (
                <QuickAddTask
                    open
                    onOpenChange={setAddTaskOpen}
                    shift={activeShift}
                    siteName={site?.name ?? ''}
                    workerName={auth?.user?.name ?? 'You'}
                    clients={props.task_creation?.clients ?? []}
                    initialPerson={view === 'today' ? activeResidentId : 'all'}
                    initialTime={newTaskTime}
                    onCreated={updateTask}
                />
            )}
            {taskUnderReview && (
                <TaskDetailDialog
                    key={taskUnderReview.id}
                    task={taskUnderReview}
                    actorId={auth?.user?.id}
                    requestedBy={helperTaskUnderReview?.requested_by_name}
                    personName={
                        helperTaskUnderReview?.person_name ??
                        people.find(
                            (person) => person.id === taskUnderReview.client_id,
                        )?.name ??
                        'Whole site'
                    }
                    onClose={() => setOpenTaskId(null)}
                    onSaved={updateTask}
                    onAddNote={() => handleAddNote(taskUnderReview.client_id)}
                />
            )}

            <VitalsRecordDialog
                residents={
                    residents.length > 0
                        ? residents
                        : singleResident
                          ? [singleResident]
                          : []
                }
                shiftId={activeShift?.id ?? null}
                canRecordObservation={props.can_record_observation ?? false}
                canRecordClinical={props.can_record_clinical ?? false}
                open={vitalsOpen}
                onOpenChange={setVitalsOpen}
            />

            <MealLogDialog
                residents={
                    residents.length > 0
                        ? residents
                        : singleResident
                          ? [singleResident]
                          : []
                }
                open={mealLogOpen}
                onOpenChange={setMealLogOpen}
            />

            <TimesheetReviewDialog
                timesheet={timesheetUnderReview}
                open={timesheetUnderReview !== null}
                onOpenChange={(next) => {
                    if (!next) setTimesheetUnderReview(null);
                }}
            />

            {openSession ? (
                <>
                    <EndOfShiftChecklist
                        session={{
                            id: openSession.id,
                            shift_id: openSession.shift_id,
                            site_name: site?.name ?? null,
                            client_name:
                                openSession.client_name ??
                                activeShift?.client?.name ??
                                null,
                            break_minutes: openSession.break_minutes ?? 0,
                            handover_submitted:
                                openSession.handover_submitted ?? false,
                            // `ShiftTaskListItem` requires a concrete
                            // `completed_at`. Our payload may omit it on tasks
                            // that are still open, so default to null.
                            tasks: (openSession.tasks ?? []).map((task) => ({
                                ...task,
                                id: task.id,
                                label: task.label,
                                is_completed: task.is_completed,
                                completed_at: task.completed_at ?? null,
                            })),
                            end_of_shift_blockers:
                                (openSession.end_of_shift_blockers ??
                                    []) as EndOfShiftBlocker[],
                        }}
                        open={endShiftOpen}
                        onOpenChange={setEndShiftOpen}
                        onOpenTask={(id) => {
                            setEndShiftOpen(false);
                            setOpenTaskId(id);
                        }}
                    />
                    <WriteHandoverDialog
                        shiftId={openSession.shift_id ?? null}
                        alreadySubmitted={
                            openSession.handover_submitted ?? false
                        }
                        open={handoverWriteOpen}
                        onOpenChange={setHandoverWriteOpen}
                    />
                </>
            ) : null}

            {activeChecklistRun != null && checklistProviderValue ? (
                <ChecklistConfigProvider value={checklistProviderValue}>
                    <RunModal
                        runId={activeChecklistRun}
                        onClose={() => setActiveChecklistRun(null)}
                    />
                </ChecklistConfigProvider>
            ) : null}
        </AppLayout>
    );
}

function ShiftChecklistsCard({
    runs,
    canRun,
    onOpen,
}: {
    runs: ShiftChecklistRun[];
    canRun: boolean;
    onOpen: (runId: number) => void;
}) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-3 space-y-0 pb-3">
                <CardTitle className="flex items-center gap-2 text-base">
                    <ClipboardCheck className="h-4 w-4 text-primary" />
                    Checklists due this shift
                </CardTitle>
                <StatusBadge tone="warning">{runs.length}</StatusBadge>
            </CardHeader>
            <CardContent className="space-y-2">
                {runs.map((run) => (
                    <ShiftChecklistRow
                        key={run.id}
                        run={run}
                        canRun={canRun}
                        onOpen={onOpen}
                    />
                ))}
            </CardContent>
        </Card>
    );
}

function ShiftChecklistRow({
    run,
    canRun,
    onOpen,
}: {
    run: ShiftChecklistRun;
    canRun: boolean;
    onOpen: (runId: number) => void;
}) {
    const canExecute = canRun && run.can_run;
    const status = run.is_overdue
        ? { label: 'Overdue', tone: 'critical' as const }
        : run.status === 'in_progress'
          ? { label: 'In progress', tone: 'warning' as const }
          : { label: 'Due', tone: 'warning' as const };

    return (
        // eslint-disable-next-line no-restricted-syntax -- Checklist row is a compact repeated row inside a Card list.
        <div className="flex items-center gap-3 rounded-lg border bg-card p-3">
            <CategoryIcon
                category={run.template?.category ?? null}
                box={36}
                size={18}
            />
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="truncate text-sm font-medium">
                        {run.template?.name ?? 'Checklist'}
                    </p>
                    <StatusBadge tone={status.tone}>{status.label}</StatusBadge>
                </div>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {run.pct}% complete
                </p>
            </div>
            <Button type="button" size="sm" onClick={() => onOpen(run.id)}>
                <CheckCircle2 className="h-4 w-4" />
                {!canExecute
                    ? 'View'
                    : run.status === 'in_progress'
                      ? 'Continue'
                      : 'Complete'}
            </Button>
        </div>
    );
}

function ActiveRoundBanner({ round }: { round: MyDayActiveRound }) {
    const verb = round.status === 'in_progress' ? 'Resume' : 'Start';
    const scheduled = round.scheduled_time
        ? round.scheduled_time.slice(0, 5)
        : null;

    return (
        <a
            href={round.url}
            aria-label={`${verb} ${round.name}`}
            className="frontline-focus group block rounded-xl border border-status-success/30 bg-status-success-bg p-4 transition-shadow hover:shadow-sm"
        >
            <div className="flex items-start gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-status-success text-white">
                    <Pill className="h-5 w-5" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold text-status-success">
                        {verb} {round.name}
                    </div>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {round.completed} of {round.total} done
                        {scheduled ? ` · ${scheduled}` : ''}
                    </p>
                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-status-success/15">
                        <div
                            className="h-full rounded-full bg-status-success"
                            style={{
                                width: `${Math.max(0, Math.min(100, round.percent))}%`,
                            }}
                        />
                    </div>
                </div>
            </div>
        </a>
    );
}

/**
 * Worker-facing Lone Worker Safety card (the cross-module half of the redesign).
 * Shown only when the signed-in worker is the subject of a live session. One tap
 * = "I'm OK"; a second, critical-tone affordance = "I need help" (confirmed).
 * Both POST to the existing check-in endpoint — no register/wizard/hero here.
 */
function LoneWorkerCheckInCard({
    session,
    onCheckIn,
    onEmergency,
}: {
    session: MyDayLoneWorkerSession;
    onCheckIn: () => void;
    onEmergency: () => void;
}) {
    const state: 'calm' | 'overdue' | 'emergency' =
        session.status === 'emergency'
            ? 'emergency'
            : session.status === 'overdue' || session.is_check_in_overdue
              ? 'overdue'
              : 'calm';

    const tone = {
        calm: {
            ring: 'border-status-info/30',
            bg: 'bg-status-info-bg',
            fg: 'text-status-info',
            medallion: 'bg-status-info',
        },
        overdue: {
            ring: 'border-status-warning/30',
            bg: 'bg-status-warning-bg',
            fg: 'text-status-warning',
            medallion: 'bg-status-warning',
        },
        emergency: {
            ring: 'border-status-critical/30',
            bg: 'bg-status-critical-bg',
            fg: 'text-status-critical',
            medallion: 'bg-status-critical',
        },
    }[state];

    const subline =
        state === 'emergency'
            ? 'Emergency alerted — the Control Room has been notified.'
            : state === 'overdue'
              ? "Check-in overdue — tap I'm OK to confirm you're safe."
              : 'Lone worker safety is watching this shift.';

    const Icon = state === 'calm' ? ShieldCheck : ShieldAlert;

    return (
        <section
            aria-label="Lone worker safety check-in"
            className={`rounded-xl border ${tone.ring} ${tone.bg} p-4`}
        >
            <div className="flex items-start gap-3">
                <div
                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${tone.medallion} text-white`}
                >
                    <Icon className="h-5 w-5" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className={`text-sm font-semibold ${tone.fg}`}>
                        You're being monitored
                    </div>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {subline}
                    </p>
                </div>
            </div>

            <ul className="mt-3 space-y-1.5 text-xs">
                {session.site ? (
                    <li className="flex items-center gap-2 text-muted-foreground">
                        <Home className="h-3.5 w-3.5 shrink-0" />
                        <span className="truncate">{session.site.name}</span>
                    </li>
                ) : null}
                {session.expected_end_at ? (
                    <li className="flex items-center gap-2 text-muted-foreground">
                        <Calendar className="h-3.5 w-3.5 shrink-0" />
                        <span>Until {formatTime(session.expected_end_at)}</span>
                    </li>
                ) : null}
                <li className="flex items-center gap-2 text-muted-foreground">
                    <CheckCircle2 className="h-3.5 w-3.5 shrink-0" />
                    {state === 'overdue' ? (
                        <span className={tone.fg}>
                            Check-in overdue
                            {session.next_check_in_at
                                ? ` · was due ${formatRelative(session.next_check_in_at)}`
                                : ''}
                        </span>
                    ) : session.next_check_in_at ? (
                        <span>
                            Next check-in {formatTime(session.next_check_in_at)}{' '}
                            · {formatRelative(session.next_check_in_at)}
                        </span>
                    ) : (
                        <span>Check in any time</span>
                    )}
                </li>
            </ul>

            <div className="mt-3 flex gap-2">
                <Button type="button" className="flex-1" onClick={onCheckIn}>
                    <CheckCircle2 className="h-4 w-4" />
                    I'm OK
                </Button>
                <Button
                    type="button"
                    variant="destructive"
                    className="flex-1"
                    onClick={onEmergency}
                >
                    <AlertTriangle className="h-4 w-4" />I need help
                </Button>
            </div>
        </section>
    );
}

/**
 * Read-only "First-aid follow-ups assigned to me" card (the cross-module half
 * of the First Aid Register redesign). Lists the signed-in worker's open
 * follow-ups — re-check a wound, lodge the ACC45, call whānau — each row a
 * one-tap deep-link into the register's record modal. No write affordances
 * live here; completing a follow-up happens on the record itself.
 */
function FirstAidFollowupsCard({
    followups,
}: {
    followups: MyDayFirstAidFollowup[];
}) {
    const overdueCount = followups.filter((f) => f.is_overdue).length;

    return (
        <section
            aria-label="First-aid follow-ups assigned to me"
            className="rounded-xl border border-status-warning/30 bg-status-warning-bg p-4"
        >
            <div className="flex items-start gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-status-warning text-white">
                    <HeartPulse className="h-5 w-5" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold text-status-warning">
                        First-aid follow-ups
                    </div>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {overdueCount > 0
                            ? `${overdueCount} overdue · ${followups.length} assigned to you`
                            : `${followups.length} assigned to you`}
                    </p>
                </div>
            </div>

            <ul className="mt-3 space-y-2">
                {followups.map((item) => (
                    <li key={item.id}>
                        {/* eslint-disable-next-line no-restricted-syntax -- custom card-row selector (tone-by-overdue), not a shadcn Button */}
                        <button
                            type="button"
                            onClick={() => router.visit(item.url)}
                            className={`flex w-full flex-col gap-1 rounded-lg border p-2.5 text-left transition-colors ${
                                item.is_overdue
                                    ? 'border-status-critical/30 bg-status-critical-bg hover:bg-status-critical-bg/70'
                                    : 'border-border bg-card hover:bg-muted'
                            }`}
                        >
                            <span className="line-clamp-2 text-xs font-medium text-foreground">
                                {item.notes || 'Follow-up required'}
                            </span>
                            <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-muted-foreground">
                                {item.treated_person_name ? (
                                    <span className="truncate">
                                        {item.treated_person_name}
                                    </span>
                                ) : null}
                                {item.site_name ? (
                                    <span className="flex items-center gap-1">
                                        <Home className="h-3 w-3 shrink-0" />
                                        <span className="truncate">
                                            {item.site_name}
                                        </span>
                                    </span>
                                ) : null}
                                {item.due_at ? (
                                    <span
                                        className={`flex items-center gap-1 ${
                                            item.is_overdue
                                                ? 'font-medium text-status-critical'
                                                : ''
                                        }`}
                                    >
                                        <Calendar className="h-3 w-3 shrink-0" />
                                        {item.is_overdue
                                            ? 'Overdue · '
                                            : 'Due '}
                                        {formatRelative(item.due_at)}
                                    </span>
                                ) : null}
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * "My tasks" — the signed-in user's open work items from the company-wide
 * /tasks aggregator (assigned=me), capped to 8 rows. Read-only: each row
 * deep-links into the owning module; completing an item happens there. The
 * footer links into All Tasks pre-filtered to the user's own queue.
 */
function MyTasksCard({ tasks }: { tasks: MyDayMyTasks }) {
    const overdueCount = tasks.items.filter((t) => t.overdue).length;

    return (
        <section
            aria-label="Other assigned work"
            className="rounded-xl border border-border bg-card p-4"
        >
            <div className="flex items-start gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <ListChecks className="h-5 w-5" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold text-foreground">
                        Other assigned work
                    </div>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {overdueCount > 0
                            ? `${overdueCount} overdue · ${tasks.total} assigned to you`
                            : `${tasks.total} assigned to you`}
                    </p>
                </div>
            </div>

            <ul className="mt-3 space-y-2">
                {tasks.items.map((item) => (
                    <li key={item.id}>
                        {/* eslint-disable-next-line no-restricted-syntax -- custom card-row selector (tone-by-overdue), not a shadcn Button */}
                        <button
                            type="button"
                            onClick={() => item.link && router.visit(item.link)}
                            className={`flex w-full flex-col gap-1 rounded-lg border p-2.5 text-left transition-colors ${
                                item.overdue
                                    ? 'border-status-critical/30 bg-status-critical-bg hover:bg-status-critical-bg/70'
                                    : 'border-border bg-card hover:bg-muted'
                            }`}
                        >
                            <span className="flex items-center gap-2">
                                {item.ref ? (
                                    <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] font-medium text-muted-foreground">
                                        {item.ref}
                                    </span>
                                ) : null}
                                <span className="line-clamp-1 text-xs font-medium text-foreground">
                                    {item.title}
                                </span>
                            </span>
                            <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-muted-foreground">
                                <span className="truncate">
                                    {item.sourceLabel}
                                </span>
                                {item.dueAt ? (
                                    <span
                                        className={`flex items-center gap-1 ${
                                            item.overdue
                                                ? 'font-medium text-status-critical'
                                                : ''
                                        }`}
                                    >
                                        <Calendar className="h-3 w-3 shrink-0" />
                                        {item.overdue ? 'Overdue · ' : 'Due '}
                                        {formatRelative(item.dueAt)}
                                    </span>
                                ) : null}
                            </span>
                        </button>
                    </li>
                ))}
            </ul>

            {/* eslint-disable-next-line no-restricted-syntax -- inline text link into All Tasks, not a shadcn Button */}
            <button
                type="button"
                onClick={() => router.visit('/tasks?assigned=me')}
                className="mt-3 w-full text-left text-xs font-medium text-primary hover:underline"
            >
                View all in All Tasks →
            </button>
        </section>
    );
}

/**
 * "Your PPE needs attention" — the worker's own active allocations awaiting
 * acknowledgement or an RPE fit-test. One-tap acknowledge posts to the auth-only,
 * ownership-checked acknowledge-own endpoint.
 */
function MyPpeCard({
    items,
    onAcknowledge,
}: {
    items: MyDayPpe[];
    onAcknowledge: (allocationId: number) => void;
}) {
    return (
        <section
            aria-label="My PPE"
            className="rounded-xl border border-status-warning/30 bg-status-warning-bg p-4"
        >
            <div className="flex items-start gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-status-warning text-white">
                    <ShieldCheck className="h-5 w-5" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold text-status-warning">
                        Your PPE needs attention
                    </div>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Confirm you've received and understand the equipment
                        issued to you.
                    </p>
                </div>
            </div>

            <ul className="mt-3 space-y-2">
                {items.map((it) => (
                    <li
                        key={it.id}
                        className="rounded-lg border border-border bg-card/60 p-2.5"
                    >
                        <div className="flex items-center justify-between gap-2">
                            <div className="min-w-0">
                                <div className="truncate text-[13px] font-semibold">
                                    {it.type_name}
                                </div>
                                <div className="truncate text-[11px] text-muted-foreground">
                                    {[it.serial_number, it.site]
                                        .filter(Boolean)
                                        .join(' · ') || '—'}
                                </div>
                            </div>
                            {it.acknowledged ? (
                                <span className="inline-flex shrink-0 items-center gap-1 text-[11px] font-semibold text-status-success">
                                    <CheckCircle2 className="h-3.5 w-3.5" />
                                    Acknowledged
                                </span>
                            ) : (
                                <Button
                                    type="button"
                                    size="sm"
                                    className="shrink-0"
                                    onClick={() => onAcknowledge(it.id)}
                                >
                                    <CheckCircle2 className="h-3.5 w-3.5" />
                                    Acknowledge
                                </Button>
                            )}
                        </div>
                        {it.fit_test_required && !it.fit_test_completed ? (
                            <div className="mt-1.5 flex items-center gap-1.5 text-[11px] font-medium text-status-critical">
                                <AlertTriangle className="h-3 w-3 shrink-0" />
                                Fit-test required before use (AS/NZS 1715) — see
                                your coordinator.
                            </div>
                        ) : null}
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** Parse the controller's "l, j F Y" today label back to a Date for the calendar anchor. */
function parsePageDate(label: string | null): { date: Date; label: string } {
    if (label) {
        const parsed = new Date(label);
        if (!Number.isNaN(parsed.getTime())) {
            return { date: parsed, label };
        }
    }
    const now = new Date();
    return {
        date: now,
        label:
            label ??
            now.toLocaleDateString(undefined, {
                weekday: 'long',
                month: 'long',
                day: 'numeric',
                year: 'numeric',
            }),
    };
}
