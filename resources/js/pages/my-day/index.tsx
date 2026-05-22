import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    FileText,
    Home,
    Users,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import EndOfShiftChecklist, {
    type EndOfShiftBlocker,
} from '@/components/end-of-shift-checklist';
import HandoverWriteSheet from '@/components/handover-write-sheet';
import useLiveRefresh from '@/hooks/use-live-refresh';
import { useMyDayLabels } from '@/hooks/use-my-day-labels';
import { useUndoableAction } from '@/hooks/use-undoable-action';
import AppLayout from '@/layouts/app-layout';
import { StaffHeader } from '@/components/staff-header';

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
    MyDayActiveSite,
    MyDayHandover,
    MyDayHrTask,
    MyDayMedDue,
    MyDayNotification,
    MyDayPageProps,
    MyDayPreShiftBriefing,
    MyDayResident,
    MyDayShiftTask,
    MyDayTaskFollowup,
    MyDayTimesheet,
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
}

interface SharedProps extends Partial<MyDayPageProps> {
    auth?: SharedAuth;
    [key: string]: unknown;
}

export default function MyDay() {
    const page = usePage<SharedProps>();
    const props = page.props as MyDayPageProps & { auth?: SharedAuth };
    const auth = props.auth;
    const t = useMyDayLabels();

    const workerFirstName = auth?.user?.first_name ?? auth?.user?.name?.split(' ')[0] ?? 'there';

    // Date popover — anchored to the title.
    const [dateOpen, setDateOpen] = useState(false);

    // Active resident filter (multi-resident sites only).
    const [activeResidentId, setActiveResidentId] = useState<'all' | number>('all');

    // Digest panel tab.
    const [digestTab, setDigestTab] = useState<'handover' | 'alerts' | 'notifs'>('handover');

    // Right-click context menu.
    const [ctxMenu, setCtxMenu] = useState<{ item: StreamItem; x: number; y: number } | null>(null);

    // End-of-shift + outgoing-handover sheets — both reuse the existing
    // components already shipped for the legacy clock-in/active-shift cards.
    const [endShiftOpen, setEndShiftOpen] = useState(false);
    const [handoverWriteOpen, setHandoverWriteOpen] = useState(false);

    // Live refresh — Inertia partial reload every 60s (unless guarded).
    const { lastUpdatedAt, isRefreshing, refreshNow } = useLiveRefresh({ intervalMs: 60_000 });

    // ──────────────────────────────────────────────────────────────────────
    // Derived shapes
    // ──────────────────────────────────────────────────────────────────────

    const activeShift = props.active_shift;
    const site: MyDayActiveSite | null = activeShift?.site ?? null;
    const residents: MyDayResident[] = useMemo(() => site?.residents ?? [], [site]);
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
        const fallbackClientId = activeShift?.client?.id ?? props.shifts?.[0]?.client?.id ?? null;
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
        const m = new Map<number, { tasks: number; meds: number; medsOverdue: number }>();
        residents.forEach((r) => m.set(r.id, { tasks: 0, meds: 0, medsOverdue: 0 }));
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
                residentFilter: activeResidentId === 'all' ? null : activeResidentId,
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
    const medsOverdue = filteredMeds.filter((m) => m.status === 'overdue').length;
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
    const clockedMinutes = openSession?.clocked_minutes ?? 0;
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
        ? isoToHourMinute(openSession?.clock_in_at ?? activeShift?.actual_starts_at)
        : '';
    const liveSinceLabel = !clockedIn
        ? t('hero_not_clocked_in')
        : liveSinceTime
          ? t('hero_live_since', { time: liveSinceTime })
          : t('hero_live_shift');
    const clockedSubLabel = `of ${shiftDurationHours}h`;

    const today = useMemo(() => parsePageDate(props.today ?? props.today_iso ?? null), [props.today, props.today_iso]);
    const shiftIsoDates = useMemo(
        () => (props.shifts ?? []).map((s) => s.starts_at.slice(0, 10)),
        [props.shifts],
    );

    // ──────────────────────────────────────────────────────────────────────
    // Mutations
    // ──────────────────────────────────────────────────────────────────────

    const { run: runUndoable } = useUndoableAction();
    const [pendingTimesheetIds, setPendingTimesheetIds] = useState<Record<number, true>>({});

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

    const handleOpenTimesheets = useCallback(() => {
        router.visit('/operations/timesheets');
    }, []);

    const handleConfirmHandoverRead = useCallback(() => {
        const handoverId = props.handover?.id;
        if (!handoverId) return;
        router.patch(
            `/attendance/handover/${handoverId}/acknowledge`,
            {},
            { preserveScroll: true },
        );
    }, [props.handover?.id]);

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
        router.post(`/my-tasks/shift-task/${taskId}/complete`, {}, { preserveScroll: true });
    }, []);

    const handleGiveMed = useCallback((medId: number) => {
        runUndoable({
            message: t('toast_marking_dose_given'),
            durationMs: 5_000,
            onCommit: () => {
                router.post(
                    `/my-day/medications/${medId}/administer`,
                    {},
                    { preserveScroll: true, only: ['medications_due', 'stats'] as never },
                );
            },
            undoneMessage: t('toast_dose_left_as_due'),
        });
    }, [runUndoable, t]);

    const handleRefuseMed = useCallback((medId: number) => {
        if (!confirm(t('confirm_refuse_dose'))) return;
        router.post(`/my-day/medications/${medId}/refuse`, {}, { preserveScroll: true });
    }, [t]);

    const handleSnoozeMed = useCallback((medId: number) => {
        router.post(
            `/my-day/medications/${medId}/snooze`,
            { minutes: 15 },
            { preserveScroll: true },
        );
    }, []);

    const handleAckAlert = useCallback((alert: MyDayTaskFollowup) => {
        const alertId = alert.meta?.alert_id;
        if (!alertId) return;
        router.post(`/my-day/alerts/${alertId}/ack`, {}, { preserveScroll: true });
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

    const handleTimesheetSubmit = useCallback(
        (ts: MyDayTimesheet) => {
            if (pendingTimesheetIds[ts.id]) return;
            setPendingTimesheetIds((prev) => ({ ...prev, [ts.id]: true }));
            runUndoable({
                message: t('toast_timesheet_sending'),
                durationMs: 5_000,
                onCommit: () => {
                    router.post(
                        `/my-tasks/timesheet/${ts.id}/submit`,
                        {},
                        {
                            preserveScroll: true,
                            onFinish: () => {
                                setPendingTimesheetIds((prev) => {
                                    const next = { ...prev };
                                    delete next[ts.id];
                                    return next;
                                });
                            },
                        },
                    );
                },
                onUndo: () => {
                    setPendingTimesheetIds((prev) => {
                        const next = { ...prev };
                        delete next[ts.id];
                        return next;
                    });
                },
                undoneMessage: t('toast_timesheet_in_draft'),
            });
        },
        [pendingTimesheetIds, runUndoable, t],
    );

    const handleContextMenuAction = useCallback(
        (action: string) => {
            if (!ctxMenu) return;
            const item = ctxMenu.item;
            if (action === 'complete-task' && item.kind === 'task') handleToggleTask(item.data.id);
            if (action === 'give-med' && item.kind === 'med') handleGiveMed(item.data.id);
            if (action === 'snooze-med' && item.kind === 'med') handleSnoozeMed(item.data.id);
            if (action === 'refuse-med' && item.kind === 'med') handleRefuseMed(item.data.id);
            if (action === 'open-emar' && item.kind === 'med') router.visit(item.data.emar_url);
            if (action === 'open-care-plan' && item.kind === 'task' && item.clientId) {
                router.visit(`/clients/${item.clientId}/care`);
            }
            if (action === 'add-note') {
                handleAddNote(item.clientId ?? null);
            }
            setCtxMenu(null);
        },
        [ctxMenu, handleToggleTask, handleGiveMed, handleSnoozeMed, handleRefuseMed, handleAddNote],
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
                { icon: FileText, label: 'My Timesheets', href: '/operations/timesheets' },
            ]}
            search={{ placeholder: t('staff_header_search'), hint: '⌘K' }}
            liveIndicator={{ lastUpdatedAt, isRefreshing, onRefresh: refreshNow }}
            notifications={{ count: props.stats?.notifications_unread ?? 0, href: '/notifications' }}
            action={
                <Button
                    type="button"
                    size="sm"
                    onClick={() => {
                        const shiftId = activeShift?.id ?? props.shifts?.[0]?.id;
                        router.visit(
                            shiftId ? `/incidents/create?shift_id=${shiftId}` : '/incidents/create',
                        );
                    }}
                >
                    <AlertTriangle className="h-3.5 w-3.5" /> {t('btn_report_incident')}
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
                activeResidentId={activeResidentId}
                onResidentChange={setActiveResidentId}
                residentTaskCounts={residentTaskCounts}
                residentNotes={residentNotes}
                liveSinceLabel={liveSinceLabel}
            />

            <div className="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                <WhatsNextRail
                    stream={stream}
                    residents={residents.length > 0 ? residents : singleResident ? [singleResident] : []}
                    activeResidentId={activeResidentId}
                    onToggleTask={handleToggleTask}
                    onGiveMed={handleGiveMed}
                    onSnoozeMed={handleSnoozeMed}
                    onRefuseMed={handleRefuseMed}
                    onAddNote={(item) => handleAddNote(item.clientId ?? null)}
                    onOpenContextMenu={(item, x, y) => setCtxMenu({ item, x, y })}
                />

                <aside className="flex flex-col gap-4">
                    <DigestPanel
                        tab={digestTab}
                        onTabChange={setDigestTab}
                        handover={(props.handover ?? null) as MyDayHandover | null}
                        alertTasks={openItemTasks}
                        incidents={props.incidents ?? []}
                        notifications={(props.notifications ?? []) as MyDayNotification[]}
                        onAckAlert={handleAckAlert}
                        onSnoozeAlert={handleSnoozeAlert}
                        onConfirmHandoverRead={handleConfirmHandoverRead}
                    />
                    <PaperworkPanel
                        timesheets={(props.timesheets ?? []) as MyDayTimesheet[]}
                        hrTasks={(props.hr_tasks ?? []) as MyDayHrTask[]}
                        onSubmitTimesheet={handleTimesheetSubmit}
                    />
                    <TomorrowPanel
                        briefing={(props.next_shift_briefing ?? null) as MyDayPreShiftBriefing | null}
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

            {openSession ? (
                <>
                    <EndOfShiftChecklist
                        session={{
                            id: openSession.id,
                            shift_id: openSession.shift_id,
                            client_name:
                                openSession.client_name
                                ?? activeShift?.client?.name
                                ?? null,
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
                                (openSession.end_of_shift_blockers ?? []) as EndOfShiftBlocker[],
                        }}
                        open={endShiftOpen}
                        onOpenChange={setEndShiftOpen}
                    />
                    <HandoverWriteSheet
                        shiftId={openSession.shift_id ?? null}
                        alreadySubmitted={openSession.handover_submitted ?? false}
                        open={handoverWriteOpen}
                        onOpenChange={setHandoverWriteOpen}
                    />
                </>
            ) : null}
        </AppLayout>
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
