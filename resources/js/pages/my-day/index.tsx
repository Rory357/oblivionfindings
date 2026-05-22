import { Head, router, usePage } from '@inertiajs/react';
import {
    Calendar,
    FileText,
    Home,
    Mic,
    Plus,
    Users,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import useLiveRefresh from '@/hooks/use-live-refresh';
import { useUndoableAction } from '@/hooks/use-undoable-action';
import AppLayout from '@/layouts/app-layout';
import { StaffHeader } from '@/components/staff-header';

import { DatePopover } from './components/date-popover';
import { DigestPanel } from './components/digest-panel';
import { MyDayHero } from './components/my-day-hero';
import { OpenItemsSection } from './components/open-items-section';
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
 *   • Two-column body: WhatsNextRail | DigestPanel + PaperworkPanel + TomorrowPanel
 *   • OpenItemsSection — open items grid with PageTabs filter
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

    const workerFirstName = auth?.user?.first_name ?? auth?.user?.name?.split(' ')[0] ?? 'there';

    // Date popover — anchored to the title.
    const [dateOpen, setDateOpen] = useState(false);

    // Active resident filter (multi-resident sites only).
    const [activeResidentId, setActiveResidentId] = useState<'all' | number>('all');

    // Digest panel tab.
    const [digestTab, setDigestTab] = useState<'handover' | 'alerts' | 'notifs'>('handover');

    // Right-click context menu.
    const [ctxMenu, setCtxMenu] = useState<{ item: StreamItem; x: number; y: number } | null>(null);

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
    const clockedIn = !!props.clock?.open_session;
    const clockedMinutes = props.clock?.open_session?.clocked_minutes ?? 0;
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
    const liveSinceLabel = activeShift?.actual_starts_at
        ? `Live shift · since ${isoToHourMinute(activeShift.actual_starts_at)}`
        : clockedIn
          ? 'Live shift'
          : 'Not clocked in';
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

    const handleClockToggle = () => {
        router.post(clockedIn ? '/my-tasks/clock/out' : '/my-tasks/clock/in', {}, { preserveScroll: true });
    };

    const handleToggleTask = useCallback((taskId: number) => {
        router.post(`/my-tasks/shift-task/${taskId}/complete`, {}, { preserveScroll: true });
    }, []);

    const handleGiveMed = useCallback((medId: number) => {
        runUndoable({
            message: 'Marking dose given…',
            durationMs: 5_000,
            onCommit: () => {
                router.post(
                    `/my-day/medications/${medId}/administer`,
                    {},
                    { preserveScroll: true, only: ['medications_due', 'stats'] as never },
                );
            },
            undoneMessage: 'Dose left as due.',
        });
    }, [runUndoable]);

    const handleRefuseMed = useCallback((medId: number) => {
        if (!confirm('Mark this dose as refused / not given?')) return;
        router.post(`/my-day/medications/${medId}/refuse`, {}, { preserveScroll: true });
    }, []);

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
        router.post(
            `/my-day/alerts/${alertId}/snooze`,
            { minutes: 5 },
            { preserveScroll: true },
        );
    }, []);

    const handleTimesheetSubmit = useCallback(
        (ts: MyDayTimesheet) => {
            if (pendingTimesheetIds[ts.id]) return;
            setPendingTimesheetIds((prev) => ({ ...prev, [ts.id]: true }));
            runUndoable({
                message: 'Timesheet sending…',
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
                undoneMessage: 'Timesheet still in draft.',
            });
        },
        [pendingTimesheetIds, runUndoable],
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
            setCtxMenu(null);
        },
        [ctxMenu, handleToggleTask, handleGiveMed, handleSnoozeMed, handleRefuseMed],
    );

    // ──────────────────────────────────────────────────────────────────────
    // Header — date popover, global links, live indicator, notifications
    // ──────────────────────────────────────────────────────────────────────

    const header = (
        <StaffHeader
            title="Today"
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
                { icon: FileText, label: 'My Timesheets', href: '/timesheets/mine' },
            ]}
            search={{ placeholder: 'Search…', hint: '⌘K' }}
            liveIndicator={{ lastUpdatedAt, isRefreshing, onRefresh: refreshNow }}
            notifications={{ count: props.stats?.notifications_unread ?? 0, href: '/notifications' }}
            action={
                <>
                    <Button type="button" variant="outline" size="sm">
                        <Mic className="h-3.5 w-3.5" /> Dictate
                    </Button>
                    <Button type="button" size="sm">
                        <Plus className="h-3.5 w-3.5" /> Report incident
                    </Button>
                </>
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
                onClockToggle={handleClockToggle}
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
                    onOpenContextMenu={(item, x, y) => setCtxMenu({ item, x, y })}
                />

                <aside className="flex flex-col gap-4">
                    <DigestPanel
                        tab={digestTab}
                        onTabChange={setDigestTab}
                        handover={(props.handover ?? null) as MyDayHandover | null}
                        alerts={alertTasks}
                        notifications={(props.notifications ?? []) as MyDayNotification[]}
                        onAckAlert={handleAckAlert}
                        onSnoozeAlert={handleSnoozeAlert}
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

            <div id="open-items">
                <OpenItemsSection
                    tasks={openItemTasks}
                    incidents={props.incidents ?? []}
                    onAckAlert={handleAckAlert}
                />
            </div>

            <div className="h-16" />

            {ctxMenu ? (
                <StreamContextMenu
                    menu={ctxMenu}
                    onClose={() => setCtxMenu(null)}
                    onAction={handleContextMenuAction}
                />
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
