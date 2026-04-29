import { router } from '@inertiajs/react';
import { Clock, LogIn, LogOut, MapPin, User } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import EndOfShiftChecklist, {
    type EndOfShiftBlocker,
} from '@/components/end-of-shift-checklist';
import type { ShiftTaskListItem } from '@/components/shift-task-list';
import { Button } from '@/components/ui/button';
import { formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';

/* -------------------------------------------------------------------------- */
/*  Frontline clock card — promotes clock in/out onto `/my-day`               */
/* -------------------------------------------------------------------------- */
/*
 * PR 4 — Clock in/out promoted to frontline home.
 *
 * Reuses the existing AttendanceService endpoints:
 *   - POST /attendance/clock-in   (AttendanceController::clockIn)
 *   - POST /attendance/clock-out  (AttendanceController::clockOut)
 *
 * Three visual states, each anchored with `id="clock"` so the bottom-nav
 * "Clock" slot can deep-link here from anywhere on the page.
 *
 *   1. No imminent shift          → renders nothing (the card hides).
 *   2. Not clocked in + shift     → "Start shift" with one dominant CTA.
 *   3. Currently clocked in       → "Shift in progress" + guarded clock-out.
 *
 * Clock-out opens the shared end-of-shift checklist. The legacy bespoke
 * confirmation dialog was retired so every worker sees the same blockers,
 * handover capture, and atomic clock-out request.
 */

type OpenSession = {
    id: number;
    clock_in_at: string | null;
    shift_id: number | null;
    client_name: string | null;
    shift_starts_at: string | null;
    shift_ends_at: string | null;
    location: string | null;
    break_minutes?: number;
    handover_submitted?: boolean;
    tasks?: ShiftTaskListItem[];
    end_of_shift_blockers?: EndOfShiftBlocker[];
};

type ActiveShift = {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    status: string;
    location: string | null;
    client_name: string | null;
};

export type ClockInCardProps = {
    canClock: boolean;
    openSession: OpenSession | null;
    activeShift: ActiveShift | null;
    eligibleShifts?: ActiveShift[];
    eligibleShiftCount: number;
};

function formatElapsed(sinceIso: string, now: number): string {
    const start = new Date(sinceIso).getTime();
    const diffSec = Math.max(0, Math.floor((now - start) / 1000));
    const h = Math.floor(diffSec / 3600);
    const m = Math.floor((diffSec % 3600) / 60);
    const s = diffSec % 60;
    if (h > 0) {
        return `${h}h ${String(m).padStart(2, '0')}m`;
    }
    return `${m}m ${String(s).padStart(2, '0')}s`;
}

export default function ClockInCard({
    canClock,
    openSession,
    activeShift,
    eligibleShifts,
    eligibleShiftCount,
}: ClockInCardProps) {
    const [submitting, setSubmitting] = useState(false);
    const [endOpen, setEndOpen] = useState(false);
    const [now, setNow] = useState<number>(() => Date.now());
    const [pickedShiftId, setPickedShiftId] = useState<number | null>(null);

    // Tick every second while clocked in so the elapsed counter updates live.
    useEffect(() => {
        if (!openSession?.clock_in_at) return;
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, [openSession?.clock_in_at]);

    const elapsed = useMemo(() => {
        if (!openSession?.clock_in_at) return null;
        return formatElapsed(openSession.clock_in_at, now);
    }, [openSession?.clock_in_at, now]);

    const ambiguous =
        !openSession && !activeShift && (eligibleShifts?.length ?? 0) > 1;

    // Early exits: no permission, or nothing to show on home.
    if (!canClock) return null;
    if (!openSession && !activeShift && !ambiguous) return null;

    const handleClockIn = (shiftId?: number) => {
        const targetId = shiftId ?? activeShift?.id;
        if (!targetId) return;
        setSubmitting(true);
        router.post(
            '/attendance/clock-in',
            { shift_id: targetId },
            {
                preserveScroll: true,
                onFinish: () => setSubmitting(false),
            },
        );
    };

    /* ---------------------------------- */
    /*  Clocked-in state                  */
    /* ---------------------------------- */
    if (openSession) {
        const clientLabel = openSession.client_name ?? 'No client linked';
        const scheduledLabel =
            openSession.shift_starts_at && openSession.shift_ends_at
                ? `${formatTime(openSession.shift_starts_at)} – ${formatTime(openSession.shift_ends_at)}`
                : null;

        return (
            <>
                <section
                    id="clock"
                    aria-label="Current shift"
                    className={cn(
                        'scroll-mt-20 rounded-xl border border-status-success/30 bg-status-success-bg p-4 shadow-sm',
                        'dark:border-status-success/40 dark:bg-status-success',
                    )}
                >
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0">
                            <div className="flex items-center gap-2">
                                <span
                                    className="relative flex h-2.5 w-2.5"
                                    aria-hidden
                                >
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-success opacity-75" />
                                    <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-status-success" />
                                </span>
                                <h2 className="text-base font-semibold text-status-success dark:text-status-success">
                                    Shift in progress
                                </h2>
                            </div>
                            <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-status-success dark:text-status-success">
                                <span className="inline-flex items-center gap-1.5 font-medium">
                                    <User className="h-4 w-4" />
                                    {clientLabel}
                                </span>
                                {scheduledLabel && (
                                    <span className="inline-flex items-center gap-1.5">
                                        <Clock className="h-4 w-4" />
                                        {scheduledLabel}
                                    </span>
                                )}
                                {openSession.location && (
                                    <span className="inline-flex items-center gap-1.5">
                                        <MapPin className="h-4 w-4" />
                                        {openSession.location}
                                    </span>
                                )}
                            </div>
                            <div className="mt-3 flex items-baseline gap-2">
                                <span className="text-3xl font-semibold text-status-success tabular-nums dark:text-status-success">
                                    {elapsed ?? '—'}
                                </span>
                                <span className="text-xs text-status-success dark:text-status-success">
                                    since {formatTime(openSession.clock_in_at)}
                                </span>
                            </div>
                        </div>

                        <div className="sm:shrink-0">
                            <Button
                                size="lg"
                                variant="destructive"
                                data-test="clock-out-button"
                                className="h-12 w-full min-w-40 text-base sm:w-auto"
                                onClick={() => setEndOpen(true)}
                                disabled={submitting}
                            >
                                <LogOut className="mr-2 h-5 w-5" />
                                End shift
                            </Button>
                        </div>
                    </div>
                </section>

                <EndOfShiftChecklist
                    session={openSession}
                    open={endOpen}
                    onOpenChange={(open) => {
                        if (!submitting) setEndOpen(open);
                    }}
                />
            </>
        );
    }

    /* ---------------------------------- */
    /*  Not clocked in, multiple shifts   */
    /*  → inline picker                   */
    /* ---------------------------------- */
    if (ambiguous && eligibleShifts) {
        return (
            <section
                id="clock"
                aria-label="Choose shift to start"
                className="scroll-mt-20 rounded-xl border border-primary/30 bg-primary/5 p-4 shadow-sm"
            >
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-base font-semibold">
                        Pick a shift to start
                    </h2>
                    <span className="text-xs text-muted-foreground">
                        {eligibleShiftCount} ready
                    </span>
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                    Pick the shift you're starting and we'll clock you in.
                </p>

                <ul
                    role="radiogroup"
                    aria-label="Eligible shifts"
                    className="mt-3 space-y-2"
                >
                    {eligibleShifts.map((shift) => {
                        const picked = pickedShiftId === shift.id;
                        const label =
                            shift.starts_at && shift.ends_at
                                ? `${formatTime(shift.starts_at)} – ${formatTime(shift.ends_at)}`
                                : null;
                        return (
                            <li key={shift.id}>
                                <Button
                                    type="button"
                                    variant="outline"
                                    role="radio"
                                    aria-checked={picked}
                                    onClick={() => setPickedShiftId(shift.id)}
                                    disabled={submitting}
                                    className={cn(
                                        'h-auto w-full justify-start gap-3 rounded-lg px-3 py-3 text-left font-normal transition-colors',
                                        picked
                                            ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                            : 'border-border bg-background hover:bg-muted',
                                    )}
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border',
                                            picked
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'border-muted-foreground/40',
                                        )}
                                    >
                                        {picked && (
                                            <span className="h-2 w-2 rounded-full bg-primary-foreground" />
                                        )}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-1.5 text-sm font-medium">
                                            <User className="h-4 w-4 text-muted-foreground" />
                                            {shift.client_name ??
                                                'Unassigned client'}
                                        </span>
                                        <span className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                                            {label && (
                                                <span className="inline-flex items-center gap-1">
                                                    <Clock className="h-3.5 w-3.5" />
                                                    {label}
                                                </span>
                                            )}
                                            {shift.location && (
                                                <span className="inline-flex items-center gap-1">
                                                    <MapPin className="h-3.5 w-3.5" />
                                                    {shift.location}
                                                </span>
                                            )}
                                        </span>
                                    </span>
                                </Button>
                            </li>
                        );
                    })}
                </ul>

                <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <a
                        href="/attendance"
                        className="text-xs text-muted-foreground underline-offset-4 hover:underline"
                    >
                        Open attendance page
                    </a>
                    <Button
                        size="lg"
                        className="h-12 w-full min-w-40 text-base sm:w-auto"
                        onClick={() =>
                            handleClockIn(pickedShiftId ?? undefined)
                        }
                        disabled={submitting || pickedShiftId === null}
                        data-test="clock-in-button"
                    >
                        <LogIn className="mr-2 h-5 w-5" />
                        {submitting ? 'Clocking in…' : 'Clock in'}
                    </Button>
                </div>
            </section>
        );
    }

    /* ---------------------------------- */
    /*  Not clocked in, one shift ready   */
    /* ---------------------------------- */
    if (!activeShift) return null;

    const scheduledLabel =
        activeShift.starts_at && activeShift.ends_at
            ? `${formatTime(activeShift.starts_at)} – ${formatTime(activeShift.ends_at)}`
            : null;

    return (
        <section
            id="clock"
            aria-label="Start shift"
            className="scroll-mt-20 rounded-xl border border-primary/30 bg-primary/5 p-4 shadow-sm"
        >
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                    <h2 className="text-base font-semibold">Start shift</h2>
                    <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                        <span className="inline-flex items-center gap-1.5 font-medium">
                            <User className="h-4 w-4" />
                            {activeShift.client_name ?? 'Unassigned client'}
                        </span>
                        {scheduledLabel && (
                            <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                <Clock className="h-4 w-4" />
                                {scheduledLabel}
                            </span>
                        )}
                        {activeShift.location && (
                            <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                <MapPin className="h-4 w-4" />
                                {activeShift.location}
                            </span>
                        )}
                    </div>
                </div>

                <div className="sm:shrink-0">
                    <Button
                        size="lg"
                        className="h-12 w-full min-w-40 text-base sm:w-auto"
                        onClick={() => handleClockIn()}
                        disabled={submitting}
                        data-test="clock-in-button"
                    >
                        <LogIn className="mr-2 h-5 w-5" />
                        {submitting ? 'Clocking in…' : 'Clock in'}
                    </Button>
                </div>
            </div>
        </section>
    );
}
