import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    Home,
    Pill,
    ShieldAlert,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { ChecklistConfigProvider } from '@/components/checklists/context';
import { CategoryIcon, StatusBadge } from '@/components/checklists/primitives';
import { RunModal } from '@/components/checklists/run-modal';
import EndOfShiftChecklist, {
    type EndOfShiftBlocker,
} from '@/components/end-of-shift-checklist';
import { StaffHeader } from '@/components/staff-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import useLiveRefresh from '@/hooks/use-live-refresh';
import { useMyDayLabels } from '@/hooks/use-my-day-labels';
import { useUndoableAction } from '@/hooks/use-undoable-action';
import AppLayout from '@/layouts/app-layout';
import { formatRelative, formatTime } from '@/lib/datetime';

import {
    MealLogDialog,
    TimesheetReviewDialog,
    VitalsRecordDialog,
    WriteHandoverDialog,
} from './_dialogs';

import { DatePopover } from './components/date-popover';
import { DigestPanel } from './components/digest-panel';
import { MyDayHero } from './components/my-day-hero';
import { PaperworkPanel } from './components/paperwork-panel';
import { StreamContextMenu } from './components/stream-context-menu';
import { TomorrowPanel } from './components/tomorrow-panel';
import { WhatsNextRail } from './components/whats-next-rail';
import { residentHue, residentInitials } from './lib/resident-hue';
import {
    buildStream,
    isoToHourMinute,
    type StreamItem,
} from './lib/stream-grouping';
import type {
    MyDayActiveRound,
    MyDayActiveSite,
    MyDayHandover,
    MyDayHrTask,
    MyDayLoneWorkerSession,
    MyDayMedDue,
    MyDayNotification,
    MyDayPageProps,
    MyDayPreShiftBriefing,
    MyDayResident,
    MyDayShiftTask,
    MyDayTaskFollowup,
    MyDayTimesheet,
    ShiftChecklistRun,
} from './lib/types';

/* -------------------------------------------------------------------------- */
/*  /my-day — desktop frontline home                                          */
/* -------------------------------------------------------------------------- */
/*
 * Site-first redesign that replaces the original mobile-first /my-day. The
 * page is intentionally web-only (≥768 px); native iOS/Android apps own the
 * mobile surface.
 *
 * Top-down composition:
 *   • AppLayout (default experience) with the AppSidebar
 *   • Extended StaffHeader (date popover + global links + search + live + bell)
 *   • MyDayHero (gradient banner with avatar stack, badges-with-popovers, stats,
 *     quick actions, resident PageTabs footer)
 *   • Two-column body: WhatsNextRail | DigestPanel (handover/needs-you/updates)
 *     + PaperworkPanel + TomorrowPanel. The Digest "Needs you" tab absorbs
 *     the open items (alerts/incidents/follow-ups) that used to sit in a
 *     separate full-width grid below the rail.
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
    const t = useMyDayLabels();

    const workerFirstName =
        auth?.user?.first_name ?? auth?.user?.name?.split(' ')[0] ?? 'there';
    const availabilityHref =
        auth?.user?.id && auth?.can?.staff?.availabilityUpdateSelf
            ? `/staff/${auth.user.id}/availability`
            : null;

    // Date popover — anchored to the title.
    const [dateOpen, setDateOpen] = useState(false);

    // Active resident filter (multi-resident sites only).
    const [activeResidentId, setActiveResidentId] = useState<'all' | number>(
        'all',
    );

    // Digest panel tab.
    const [digestTab, setDigestTab] = useState<
        'handover' | 'alerts' | 'notifs'
    >('handover');

    // Right-click context menu.
    const [ctxMenu, setCtxMenu] = useState<{
        item: StreamItem;
        x: number;
        y: number;
    } | null>(null);

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
    const { lastUpdatedAt, isRefreshing, refreshNow } = useLiveRefresh({
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
        const tasks = activeShift?.tasks ?? props.shifts?.[0]?.tasks ?? [];
        const fallbackClientId =
            activeShift?.client?.id ?? props.shifts?.[0]?.client?.id ?? null;
        return tasks.map((task) => ({
            ...task,
            client_id: task.client_id ?? fallbackClientId ?? undefined,
        }));
    }, [activeShift, props.shifts]);

    const visibleMeds: MyDayMedDue[] = useMemo(
        () => props.medications_due ?? [],
        [props.medications_due],
    );

    const residentTaskCounts = useMemo(() => {
        const m = new Map<
            number,
            { tasks: number; meds: number; medsOverdue: number }
        >();
        residents.forEach((r) =>
            m.set(r.id, { tasks: 0, meds: 0, medsOverdue: 0 }),
        );
        visibleTasks.forEach((task) => {
            if (task.client_id == null) return;
            const entry = m.get(task.client_id);
            if (entry) entry.tasks += 1;
        });
        visibleMeds.forEach((med) => {
            const entry = m.get(med.client_id);
            if (entry) {
                entry.meds += 1;
                if (med.status === 'overdue') entry.medsOverdue += 1;
            }
        });
        return m;
    }, [residents, visibleTasks, visibleMeds]);

    const filteredTasks = useMemo(() => {
        if (activeResidentId === 'all') return visibleTasks;
        return visibleTasks.filter((t) => t.client_id === activeResidentId);
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
            }),
        [filteredTasks, filteredMeds, activeResidentId, singleResident],
    );

    const residentNotes = useMemo(() => {
        const m = new Map<number, string | null | undefined>();
        residents.forEach((r) => m.set(r.id, r.care_note_preview));
        return m;
    }, [residents]);

    const tasksDone = filteredTasks.filter((t) => t.is_completed).length;
    const medsGiven = filteredMeds.filter((m) => m.status === 'given').length;
    const medsOverdue = filteredMeds.filter(
        (m) => m.status === 'overdue',
    ).length;
    const overdueMeds = visibleMeds.filter((m) => m.status === 'overdue');
    const openItemTasks = (props.tasks ?? []).filter((t) =>
        ['alert', 'incident', 'followup', 'note_followup'].includes(t.type),
    );
    const alertTasks = openItemTasks.filter((t) => t.type === 'alert');
    const openItemsCount =
        openItemTasks.length + (props.incidents?.length ?? 0) + medsOverdue;

    // Clock & shift labels
    const openSession = props.clock?.open_session ?? null;
    const clockedIn = !!openSession;
    const isOnBreak = !!openSession?.is_on_break;

    // CLOCKED headline ticks up from the session's clock-in time. The backend
    // doesn't report elapsed minutes, so derive it from clock_in_at against the
    // ticking `now` above and re-arm a 30s interval while a session is open.
    const clockInAt = openSession?.clock_in_at ?? null;
    useEffect(() => {
        if (!clockInAt) return;
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
    const shiftStartLabel = isoToHourMinute(activeShift?.starts_at);
    const shiftEndLabel = isoToHourMinute(activeShift?.ends_at);
    const shiftDurationHours = activeShift
        ? Math.round(
              (new Date(activeShift.ends_at).getTime() -
                  new Date(activeShift.starts_at).getTime()) /
                  3_600_000,
          )
        : 8;
    // "Live shift · since X" only makes sense when this worker has an open
    // attendance session. X should be THIS session's clock-in time — the
    // shift's `actual_starts_at` is the historical first-start (set by the
    // earliest session on the shift, including a previous worker's), so it
    // misleadingly survives clock-out and ends up showing a time hours before
    // the current worker actually arrived. Prefer the open session's
    // clock_in_at and only fall back to actual_starts_at when the session
    // hasn't reported one yet.
    const liveSinceTime = clockedIn
        ? isoToHourMinute(
              openSession?.clock_in_at ?? activeShift?.actual_starts_at,
          )
        : '';
    const liveSinceLabel = !clockedIn
        ? t('hero_not_clocked_in')
        : liveSinceTime
          ? t('hero_live_since', { time: liveSinceTime })
          : t('hero_live_shift');
    const clockedSubLabel = `of ${shiftDurationHours}h`;

    const today = useMemo(
        () => parsePageDate(props.today ?? props.today_iso ?? null),
        [props.today, props.today_iso],
    );
    const shiftIsoDates = useMemo(
        () => (props.shifts ?? []).map((s) => s.starts_at.slice(0, 10)),
        [props.shifts],
    );
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

    const { run: runUndoable } = useUndoableAction();

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
        const todayIso = props.today_iso;
        if (!todayIso) return null;
        return (
            (props.timesheets ?? []).find(
                (ts) =>
                    ts.work_date_iso === todayIso &&
                    (ts.status === 'draft' || ts.status === 'returned'),
            ) ?? null
        );
    }, [props.timesheets, props.today_iso]) as MyDayTimesheet | null;

    const handleOpenTimesheets = useCallback(() => {
        if (todaysTimesheet) {
            setTimesheetUnderReview(todaysTimesheet);
            return;
        }
        router.post(
            '/my-tasks/timesheet/ensure-today',
            {},
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
    }, [todaysTimesheet]);

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

    const handleAddNote = useCallback((clientId: number | null | undefined) => {
        if (!clientId) {
            router.visit('/clients');
            return;
        }
        // The clients/{id}/daily-notes endpoint is JSON-only — land the worker
        // on the client profile's Daily Notes tab instead (Inertia page).
        router.visit(`/clients/${clientId}?tab=progress_notes`);
    }, []);

    const handleToggleTask = useCallback((taskId: number) => {
        router.post(
            `/my-tasks/shift-task/${taskId}/complete`,
            {},
            { preserveScroll: true },
        );
    }, []);

    // A medications_due row addresses a single dose occurrence by medication id
    // (the route-model-bound URL param) + scheduled_for (the slot). The same
    // ClientMedication can appear twice in the rail (e.g. 09:00 + 13:00), so
    // scheduled_for is what tells the endpoint which dose was acted on.
    const handleGiveMed = useCallback(
        (medicationId: number, scheduledFor: string) => {
            runUndoable({
                message: t('toast_marking_dose_given'),
                durationMs: 5_000,
                onCommit: () => {
                    router.post(
                        `/my-day/medications/${medicationId}/administer`,
                        { scheduled_for: scheduledFor },
                        {
                            preserveScroll: true,
                            only: ['medications_due', 'stats'] as never,
                            // Surface a server rejection (e.g. controlled drug needs a
                            // witness, or the dose is outside its time window) instead
                            // of silently leaving the row unchanged.
                            onError: (errors) => {
                                const message = Object.values(errors)[0];
                                toast.error(
                                    typeof message === 'string'
                                        ? message
                                        : t('toast_dose_record_failed'),
                                );
                            },
                        },
                    );
                },
                undoneMessage: t('toast_dose_left_as_due'),
            });
        },
        [runUndoable, t],
    );

    const handleRefuseMed = useCallback(
        (medicationId: number, scheduledFor: string) => {
            if (!confirm(t('confirm_refuse_dose'))) return;
            const reason = window.prompt(
                t('prompt_refuse_dose_reason'),
                t('default_refuse_dose_reason'),
            );
            if (reason === null) return;
            const trimmedReason = reason.trim();
            if (!trimmedReason) return;
            router.post(
                `/my-day/medications/${medicationId}/refuse`,
                {
                    scheduled_for: scheduledFor,
                    reason_code: 'refused',
                    reason: trimmedReason,
                },
                {
                    preserveScroll: true,
                    onError: (errors) => {
                        const message = Object.values(errors)[0];
                        toast.error(
                            typeof message === 'string'
                                ? message
                                : t('toast_dose_record_failed'),
                        );
                    },
                },
            );
        },
        [t],
    );

    const handleSnoozeMed = useCallback(
        (medicationId: number, scheduledFor: string) => {
            router.post(
                `/my-day/medications/${medicationId}/snooze`,
                { minutes: 15, scheduled_for: scheduledFor },
                { preserveScroll: true },
            );
        },
        [],
    );

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

    const handleContextMenuAction = useCallback(
        (action: string) => {
            if (!ctxMenu) return;
            const item = ctxMenu.item;
            if (action === 'complete-task' && item.kind === 'task')
                handleToggleTask(item.data.id);
            if (action === 'give-med' && item.kind === 'med')
                handleGiveMed(item.data.medication_id, item.data.scheduled_for);
            if (action === 'snooze-med' && item.kind === 'med')
                handleSnoozeMed(
                    item.data.medication_id,
                    item.data.scheduled_for,
                );
            if (action === 'refuse-med' && item.kind === 'med')
                handleRefuseMed(
                    item.data.medication_id,
                    item.data.scheduled_for,
                );
            if (action === 'open-emar' && item.kind === 'med')
                router.visit(item.data.emar_url);
            if (
                action === 'open-care-plan' &&
                item.kind === 'task' &&
                item.clientId
            ) {
                router.visit(`/clients/${item.clientId}?tab=care_plans`);
            }
            if (action === 'add-note') {
                handleAddNote(item.clientId ?? null);
            }
            setCtxMenu(null);
        },
        [
            ctxMenu,
            handleToggleTask,
            handleGiveMed,
            handleSnoozeMed,
            handleRefuseMed,
            handleAddNote,
        ],
    );

    // ──────────────────────────────────────────────────────────────────────
    // Header — date popover, global links, live indicator, notifications
    // ──────────────────────────────────────────────────────────────────────

    const header = (
        <StaffHeader
            title={t('today')}
            subtitle={today.label}
            titleChevron
            titleOpen={dateOpen}
            onTitleClick={() => setDateOpen((v) => !v)}
            titlePopover={
                dateOpen ? (
                    <DatePopover
                        anchor={today.date}
                        shiftDates={shiftIsoDates}
                        onSelect={() => setDateOpen(false)}
                        onClose={() => setDateOpen(false)}
                    />
                ) : null
            }
            globalLinks={[
                { icon: Users, label: 'Clients', href: '/clients' },
                { icon: Home, label: 'Sites & Locations', href: '/sites' },
                { icon: Calendar, label: 'My Calendar', href: '/my-calendar' },
                {
                    icon: FileText,
                    label: 'My Timesheets',
                    href: '/operations/timesheets',
                },
            ]}
            search={{ placeholder: t('staff_header_search'), hint: '⌘K' }}
            liveIndicator={{
                lastUpdatedAt,
                isRefreshing,
                onRefresh: refreshNow,
            }}
            notifications={{
                count: props.stats?.notifications_unread ?? 0,
                href: '/notifications',
            }}
            action={
                <Button
                    type="button"
                    size="sm"
                    onClick={() => {
                        const shiftId =
                            activeShift?.id ?? props.shifts?.[0]?.id;
                        router.visit(
                            shiftId
                                ? `/incidents/create?shift_id=${shiftId}`
                                : '/incidents/create',
                        );
                    }}
                >
                    <AlertTriangle className="h-3.5 w-3.5" />{' '}
                    {t('btn_report_incident')}
                </Button>
            }
        />
    );

    return (
        <AppLayout header={header} contentClassName="w-full px-7 py-5">
            <Head title="My Day" />

            <MyDayHero
                workerFirstName={workerFirstName}
                site={site}
                singleResident={singleResident}
                activeShiftId={activeShift?.id ?? null}
                shiftStartLabel={shiftStartLabel}
                shiftEndLabel={shiftEndLabel}
                shiftDurationHours={shiftDurationHours}
                clockedLabel={clockedLabel}
                clockedSubLabel={clockedSubLabel}
                tasksDone={tasksDone}
                totalTasks={filteredTasks.length}
                medsGiven={medsGiven}
                totalMeds={filteredMeds.length}
                medsOverdue={medsOverdue}
                openItemsCount={openItemsCount}
                overdueMeds={overdueMeds}
                openItems={openItemTasks}
                clockedIn={clockedIn}
                isOnBreak={isOnBreak}
                handoverSubmitted={openSession?.handover_submitted ?? false}
                onClockToggle={handleClockToggle}
                onBreakToggle={handleBreakToggle}
                onOpenTimesheet={handleOpenTimesheets}
                onWriteHandover={handleWriteHandover}
                onOpenVitals={() => setVitalsOpen(true)}
                onOpenMeal={() => setMealLogOpen(true)}
                activeResidentId={activeResidentId}
                onResidentChange={setActiveResidentId}
                residentTaskCounts={residentTaskCounts}
                residentNotes={residentNotes}
                liveSinceLabel={liveSinceLabel}
                availabilityHref={availabilityHref}
            />

            <div className="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                <WhatsNextRail
                    stream={stream}
                    residents={
                        residents.length > 0
                            ? residents
                            : singleResident
                              ? [singleResident]
                              : []
                    }
                    activeResidentId={activeResidentId}
                    onToggleTask={handleToggleTask}
                    onGiveMed={handleGiveMed}
                    onSnoozeMed={handleSnoozeMed}
                    onRefuseMed={handleRefuseMed}
                    onAddNote={(item) => handleAddNote(item.clientId ?? null)}
                    onOpenContextMenu={(item, x, y) =>
                        setCtxMenu({ item, x, y })
                    }
                />

                <aside className="flex flex-col gap-4">
                    {props.active_lone_worker_session ? (
                        <LoneWorkerCheckInCard
                            session={props.active_lone_worker_session}
                            onCheckIn={handleLoneWorkerCheckIn}
                            onEmergency={handleLoneWorkerEmergency}
                        />
                    ) : null}

                    {activeRound ? (
                        <ActiveRoundBanner round={activeRound} />
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

                    <DigestPanel
                        tab={digestTab}
                        onTabChange={setDigestTab}
                        handover={
                            (props.handover ?? null) as MyDayHandover | null
                        }
                        alertTasks={openItemTasks}
                        incidents={props.incidents ?? []}
                        notifications={
                            (props.notifications ?? []) as MyDayNotification[]
                        }
                        onAckAlert={handleAckAlert}
                        onSnoozeAlert={handleSnoozeAlert}
                        onConfirmHandoverRead={handleConfirmHandoverRead}
                    />
                    <PaperworkPanel
                        timesheets={
                            (props.timesheets ?? []) as MyDayTimesheet[]
                        }
                        hrTasks={(props.hr_tasks ?? []) as MyDayHrTask[]}
                        onSubmitTimesheet={handleTimesheetSubmit}
                    />
                    <TomorrowPanel
                        briefing={
                            (props.next_shift_briefing ??
                                null) as MyDayPreShiftBriefing | null
                        }
                    />
                </aside>
            </div>

            <div className="h-16" />

            {ctxMenu ? (
                <StreamContextMenu
                    menu={ctxMenu}
                    onClose={() => setCtxMenu(null)}
                    onAction={handleContextMenuAction}
                />
            ) : null}

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
                {!canRun
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

            <dl className="mt-3 space-y-1.5 text-xs">
                {session.site ? (
                    <div className="flex items-center gap-2 text-muted-foreground">
                        <Home className="h-3.5 w-3.5 shrink-0" />
                        <span className="truncate">{session.site.name}</span>
                    </div>
                ) : null}
                {session.expected_end_at ? (
                    <div className="flex items-center gap-2 text-muted-foreground">
                        <Calendar className="h-3.5 w-3.5 shrink-0" />
                        <span>Until {formatTime(session.expected_end_at)}</span>
                    </div>
                ) : null}
                <div className="flex items-center gap-2 text-muted-foreground">
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
                </div>
            </dl>

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
