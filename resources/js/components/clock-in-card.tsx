import { router } from '@inertiajs/react';
import { Clock, LogIn, LogOut, MapPin, User } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import HandoverWriteForm, {
    emptyHandoverWriteValue,
    type HandoverWriteValue,
} from '@/components/handover-write-form';
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
 * Clock-out opens a confirmation dialog with quick-select break chips so the
 * action cannot be triggered accidentally and break minutes don't rely on a
 * raw number field.
 */

type OpenSession = {
    id: number;
    clock_in_at: string | null;
    shift_id: number | null;
    client_name: string | null;
    shift_starts_at: string | null;
    shift_ends_at: string | null;
    location: string | null;
    handover_submitted?: boolean;
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

const BREAK_CHIPS: ReadonlyArray<{ value: number; label: string }> = [
    { value: 0, label: '0' },
    { value: 15, label: '15' },
    { value: 30, label: '30' },
    { value: 45, label: '45' },
    { value: 60, label: '60' },
];

function formatTime(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleTimeString('en-NZ', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
}

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
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [breakPreset, setBreakPreset] = useState<number | 'custom'>(0);
    const [customBreak, setCustomBreak] = useState('');
    const [now, setNow] = useState<number>(() => Date.now());
    const [pickedShiftId, setPickedShiftId] = useState<number | null>(null);
    const [handoverValue, setHandoverValue] = useState<HandoverWriteValue>(
        emptyHandoverWriteValue,
    );

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

    const breakMinutes = useMemo(() => {
        if (breakPreset === 'custom') {
            const n = Number(customBreak);
            if (!Number.isFinite(n) || n < 0) return 0;
            return Math.min(240, Math.floor(n));
        }
        return breakPreset;
    }, [breakPreset, customBreak]);

    const ambiguous = !openSession && !activeShift && (eligibleShifts?.length ?? 0) > 1;

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

    const handoverEligible =
        !!openSession?.shift_id && !openSession?.handover_submitted;

    const performClockOut = () => {
        if (!openSession) return;
        router.post(
            '/attendance/clock-out',
            {
                session_id: openSession.id,
                break_minutes: breakMinutes,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSubmitting(false);
                    setConfirmOpen(false);
                },
            },
        );
    };

    const handleClockOutConfirm = () => {
        if (!openSession) return;
        setSubmitting(true);

        // Save the short handover first so the outgoing shift's carry-over
        // is captured *before* the attendance session closes. If the worker
        // hasn't answered the shift-rating yet, that's fine — the backend
        // accepts a null rating. A backend failure surfaces via Inertia
        // errors and we don't proceed to clock-out so nothing is lost.
        if (handoverEligible && openSession.shift_id) {
            router.post(
                '/attendance/handover',
                {
                    shift_id: openSession.shift_id,
                    meds_completed: handoverValue.meds_completed,
                    shift_rating: handoverValue.shift_rating,
                    handover_notes: handoverValue.handover_notes,
                    follow_up_needed: handoverValue.follow_up_needed,
                },
                {
                    preserveScroll: true,
                    onSuccess: () => performClockOut(),
                    onError: () => {
                        setSubmitting(false);
                    },
                },
            );
            return;
        }

        performClockOut();
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
                        'scroll-mt-20 rounded-xl border border-emerald-300 bg-emerald-50/80 p-4 shadow-sm',
                        'dark:border-emerald-500/40 dark:bg-emerald-950/30',
                    )}
                >
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0">
                            <div className="flex items-center gap-2">
                                <span className="relative flex h-2.5 w-2.5" aria-hidden>
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                                    <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500" />
                                </span>
                                <h2 className="text-base font-semibold text-emerald-900 dark:text-emerald-100">
                                    Shift in progress
                                </h2>
                            </div>
                            <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-emerald-900/90 dark:text-emerald-100/90">
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
                                <span className="text-3xl font-semibold tabular-nums text-emerald-900 dark:text-emerald-50">
                                    {elapsed ?? '—'}
                                </span>
                                <span className="text-xs text-emerald-800/80 dark:text-emerald-100/70">
                                    since {formatTime(openSession.clock_in_at)}
                                </span>
                            </div>
                        </div>

                        <div className="sm:shrink-0">
                            <Button
                                size="lg"
                                variant="destructive"
                                className="h-12 w-full min-w-40 text-base sm:w-auto"
                                onClick={() => {
                                    setBreakPreset(0);
                                    setCustomBreak('');
                                    setHandoverValue(emptyHandoverWriteValue);
                                    setConfirmOpen(true);
                                }}
                                disabled={submitting}
                            >
                                <LogOut className="mr-2 h-5 w-5" />
                                Clock out
                            </Button>
                        </div>
                    </div>
                </section>

                <AlertDialog
                    open={confirmOpen}
                    onOpenChange={(o) => {
                        if (!submitting) setConfirmOpen(o);
                    }}
                >
                    <AlertDialogContent className="max-h-[90vh] overflow-y-auto">
                        <AlertDialogHeader>
                            <AlertDialogTitle>End this shift?</AlertDialogTitle>
                            <AlertDialogDescription>
                                {clientLabel}
                                {elapsed ? ` · ${elapsed} worked` : ''}. Select any
                                unpaid break you took during the shift.
                            </AlertDialogDescription>
                        </AlertDialogHeader>

                        <div className="space-y-4 py-2">
                            <div className="text-sm font-medium">Break (minutes)</div>
                            <div className="flex flex-wrap gap-2">
                                {BREAK_CHIPS.map((chip) => {
                                    const active = breakPreset === chip.value;
                                    return (
                                        <button
                                            key={chip.value}
                                            type="button"
                                            onClick={() => setBreakPreset(chip.value)}
                                            className={cn(
                                                'min-h-11 min-w-14 rounded-full border px-4 text-sm font-medium transition-colors',
                                                active
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'border-border bg-background hover:bg-muted',
                                            )}
                                            aria-pressed={active}
                                        >
                                            {chip.label}
                                        </button>
                                    );
                                })}
                                <button
                                    type="button"
                                    onClick={() => setBreakPreset('custom')}
                                    className={cn(
                                        'min-h-11 rounded-full border px-4 text-sm font-medium transition-colors',
                                        breakPreset === 'custom'
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-border bg-background hover:bg-muted',
                                    )}
                                    aria-pressed={breakPreset === 'custom'}
                                >
                                    Custom
                                </button>
                            </div>
                            {breakPreset === 'custom' && (
                                <div>
                                    <label className="text-xs text-muted-foreground">
                                        Enter break minutes (0–240)
                                    </label>
                                    <input
                                        type="number"
                                        inputMode="numeric"
                                        min={0}
                                        max={240}
                                        value={customBreak}
                                        onChange={(e) => setCustomBreak(e.target.value)}
                                        className="mt-1 h-11 w-32 rounded-md border border-input bg-background px-3 text-base shadow-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                        autoFocus
                                    />
                                </div>
                            )}

                            {openSession.shift_id ? (
                                <div className="border-t pt-4">
                                    <HandoverWriteForm
                                        value={handoverValue}
                                        onChange={setHandoverValue}
                                        disabled={submitting}
                                        alreadySubmitted={!!openSession.handover_submitted}
                                    />
                                </div>
                            ) : null}
                        </div>

                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={submitting}>
                                Keep shift open
                            </AlertDialogCancel>
                            <AlertDialogAction
                                onClick={(e) => {
                                    e.preventDefault();
                                    handleClockOutConfirm();
                                }}
                                disabled={submitting}
                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                            >
                                {submitting
                                    ? 'Ending…'
                                    : handoverEligible
                                      ? `Finish and clock out (${breakMinutes}m break)`
                                      : `Clock out (${breakMinutes}m break)`}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
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
                    <h2 className="text-base font-semibold">Choose a shift to start</h2>
                    <span className="text-xs text-muted-foreground">
                        {eligibleShiftCount} eligible
                    </span>
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                    Pick the shift you're starting — we'll clock you in.
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
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked={picked}
                                    onClick={() => setPickedShiftId(shift.id)}
                                    disabled={submitting}
                                    className={cn(
                                        'flex w-full items-center gap-3 rounded-lg border px-3 py-3 text-left transition-colors',
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
                                            {shift.client_name ?? 'Unassigned client'}
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
                                </button>
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
                        onClick={() => handleClockIn(pickedShiftId ?? undefined)}
                        disabled={submitting || pickedShiftId === null}
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
                    >
                        <LogIn className="mr-2 h-5 w-5" />
                        {submitting ? 'Clocking in…' : 'Clock in'}
                    </Button>
                </div>
            </div>
        </section>
    );
}
