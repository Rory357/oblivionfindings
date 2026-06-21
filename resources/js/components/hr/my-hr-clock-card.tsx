/* eslint-disable no-restricted-syntax -- This is the hero's inline "glass" clock
 * panel: it dissolves into the brand gradient (translucent primary-foreground
 * fills, border-left hairline) rather than sitting on a white card, and the
 * clock-in/out + break controls are bespoke pills whose treatment flips by
 * state. shadcn <Button>/<Card> can't express the on-gradient glass without
 * fighting it, so the raw <button>/<div> layout is intentional. Colours stay
 * token-based (primary / primary-foreground) so white-label theming holds. */
import { router } from '@inertiajs/react';
import { Coffee, Loader2, LogIn, LogOut } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { fireConfetti } from '@/lib/confetti';
import { cn } from '@/lib/utils';

import type { MyHrShellData } from './my-hr-types';

export type MyHrActiveClock = {
    id: number;
    clock_in: string;
    notes?: string | null;
} | null;

/** Assumed shift length for the In/Out window track when clocked in (the active
 *  time entry has no scheduled end of its own). Matches the design handoff. */
const SHIFT_WINDOW_SECONDS = 8 * 3600;

function fmtTimer(seconds: number): string {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    const p = (n: number) => String(n).padStart(2, '0');
    return `${p(h)}:${p(m)}:${p(s)}`;
}

function fmtHM(seconds: number): string {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    if (h === 0) return `${m}m`;
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

function fmtClock(ms: number): string {
    return new Date(ms).toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

/**
 * The promoted, high-contrast clock panel that sits in the My HR hero's right
 * column (and wraps below the greeting on narrow viewports). It is a glass panel
 * over the brand gradient, not a white card. Backed by the single shared
 * AttendanceService via the existing self-service `/hr/my/time/clock-in|out`
 * endpoints (MyHrController — no new clock path, no `timesheets.*` permission
 * gate, and the system that owns the `HrTimeEntry` the hero reads as its active
 * clock, so clocking out reliably clears the banner). The live timer ticks
 * every second; the break toggle pauses the
 * *displayed* elapsed client-side and submits the accrued `break_minutes` at
 * clock-out, which the controller already accepts. When clocked out it shows a
 * calm "Next shift" block instead of the timer.
 */
export function MyHrClockCard({
    activeClock,
    todayTotal,
    siteName,
    nextShift,
}: {
    activeClock: MyHrActiveClock;
    todayTotal: number;
    siteName?: string | null;
    nextShift?: MyHrShellData['nextShift'];
}) {
    const isClockedIn = !!activeClock;
    const [processing, setProcessing] = useState(false);
    const [now, setNow] = useState(() => Date.now());
    const [onBreak, setOnBreak] = useState(false);
    const [breakAccum, setBreakAccum] = useState(0); // seconds
    const [breakStart, setBreakStart] = useState<number | null>(null);

    const clockInMs = useMemo(
        () => (activeClock ? Date.parse(activeClock.clock_in) : null),
        [activeClock],
    );

    // 1s tick while clocked in.
    useEffect(() => {
        if (!isClockedIn) return;
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, [isClockedIn]);

    // Reset break bookkeeping whenever the active session changes.
    useEffect(() => {
        setOnBreak(false);
        setBreakAccum(0);
        setBreakStart(null);
    }, [activeClock?.id]);

    const elapsed = useMemo(() => {
        if (!clockInMs) return 0;
        let e = Math.floor((now - clockInMs) / 1000) - breakAccum;
        if (onBreak && breakStart) e -= Math.floor((now - breakStart) / 1000);
        return Math.max(0, e);
    }, [clockInMs, now, breakAccum, onBreak, breakStart]);

    // Shift-window track: In = clock-in time, Out = +8h, knob = now within it.
    const progPct = clockInMs
        ? Math.max(0, Math.min(100, (elapsed / SHIFT_WINDOW_SECONDS) * 100))
        : 0;
    const shiftStart = clockInMs ? fmtClock(clockInMs) : '';
    const shiftEnd = clockInMs
        ? fmtClock(clockInMs + SHIFT_WINDOW_SECONDS * 1000)
        : '';

    // Clocked-out "Next shift" block.
    const nextShiftStart = nextShift?.starts_at
        ? new Date(nextShift.starts_at)
        : null;
    const nextShiftTime = nextShiftStart
        ? nextShiftStart.toLocaleTimeString('en-NZ', {
              hour: '2-digit',
              minute: '2-digit',
              hour12: false,
          })
        : '—';
    const nextShiftDay = nextShiftStart
        ? nextShiftStart.toLocaleDateString('en-NZ', {
              weekday: 'short',
              day: 'numeric',
              month: 'long',
          })
        : null;
    const nextShiftSite =
        nextShift?.location ?? nextShift?.service_context_name ?? siteName ?? null;

    function handleClockIn() {
        setProcessing(true);
        router.post(
            '/hr/my/time/clock-in',
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success('Clocked in 🌿', {
                        description: 'Have a good shift. Your timer is running.',
                    }),
                onError: () => toast.error('Could not clock in'),
                onFinish: () => setProcessing(false),
            },
        );
    }

    function handleClockOut() {
        const totalBreak =
            breakAccum +
            (onBreak && breakStart
                ? Math.floor((Date.now() - breakStart) / 1000)
                : 0);
        const breakMinutes = Math.round(totalBreak / 60);
        const worked = elapsed;
        setProcessing(true);
        router.post(
            '/hr/my/time/clock-out',
            { break_minutes: breakMinutes },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Clocked out — ka pai! 👋', {
                        description: `That's ${fmtHM(worked)} today. Timesheet draft saved & synced to My Day.`,
                    });
                    fireConfetti();
                    setOnBreak(false);
                    setBreakAccum(0);
                    setBreakStart(null);
                },
                onError: () => toast.error('Could not clock out'),
                onFinish: () => setProcessing(false),
            },
        );
    }

    function toggleBreak() {
        if (!isClockedIn) return;
        if (onBreak && breakStart) {
            setBreakAccum((a) => a + Math.floor((Date.now() - breakStart) / 1000));
            setOnBreak(false);
            setBreakStart(null);
            toast.info('Back from break ☕', { description: 'Timer running again.' });
        } else {
            setOnBreak(true);
            setBreakStart(Date.now());
            toast.info('On break ☕', { description: 'Take five — timer paused.' });
        }
    }

    const statusLabel = isClockedIn
        ? onBreak
            ? 'On break'
            : 'On shift'
        : 'Off the clock';

    return (
        <div className="flex w-full flex-col justify-center border-primary-foreground/15 bg-gradient-to-b from-primary-foreground/[0.16] to-primary-foreground/[0.04] p-8 shadow-[inset_1px_0_0_rgba(255,255,255,0.18)] md:w-[348px] md:flex-none md:border-l">
            {/* status row */}
            <div className="flex items-center justify-between">
                <div className="inline-flex items-center gap-2">
                    {isClockedIn ? (
                        <span className="relative inline-flex h-2.5 w-2.5">
                            {!onBreak ? (
                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary-foreground opacity-75 motion-reduce:animate-none" />
                            ) : null}
                            <span
                                className={cn(
                                    'relative inline-flex h-2.5 w-2.5 rounded-full',
                                    onBreak
                                        ? 'bg-[color:var(--hr-amber)]'
                                        : 'bg-primary-foreground',
                                )}
                            />
                        </span>
                    ) : (
                        <span className="h-2.5 w-2.5 rounded-full border-2 border-primary-foreground/50" />
                    )}
                    <span className="text-[11px] font-bold uppercase tracking-[0.1em] text-primary-foreground/85">
                        {statusLabel}
                    </span>
                </div>
                {isClockedIn ? (
                    <span className="text-[11px] font-semibold text-primary-foreground/65">
                        Today · {fmtHM(elapsed)}
                    </span>
                ) : null}
            </div>

            {/* clocked-in: live timer + shift-window track */}
            {isClockedIn ? (
                <>
                    <div className="mt-3 text-[52px] font-bold leading-none tracking-tight tabular-nums">
                        {fmtTimer(elapsed)}
                    </div>
                    <div className="relative mb-[7px] mt-[22px] h-1.5 rounded-full bg-primary-foreground/20">
                        <div
                            className="absolute inset-y-0 left-0 rounded-full bg-primary-foreground"
                            style={{ width: `${progPct}%` }}
                        />
                        <div
                            className="absolute top-1/2 h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-primary-foreground shadow-[0_0_0_4px_color-mix(in_oklch,var(--primary)_60%,transparent),0_2px_7px_rgba(0,0,0,0.35)]"
                            style={{ left: `${progPct}%` }}
                        />
                    </div>
                    <div className="flex justify-between text-[10.5px] font-semibold text-primary-foreground/60">
                        <span>In · {shiftStart}</span>
                        <span>Out · {shiftEnd}</span>
                    </div>
                </>
            ) : (
                /* clocked-out: calm "Next shift" block */
                <div className="pb-0.5 pt-1">
                    <div className="text-[10px] font-bold uppercase tracking-[0.09em] text-primary-foreground/55">
                        Next shift
                    </div>
                    <div className="mt-[7px] text-4xl font-bold leading-none tracking-tight tabular-nums">
                        {nextShiftTime}
                    </div>
                    <div className="mt-2 text-[12.5px] text-primary-foreground/75">
                        {nextShiftDay
                            ? `${nextShiftDay}${nextShiftSite ? ` · ${nextShiftSite}` : ''}`
                            : 'No upcoming shift scheduled'}
                    </div>
                </div>
            )}

            {/* controls */}
            <div className="mt-[22px] flex gap-2.5">
                <button
                    type="button"
                    onClick={isClockedIn ? handleClockOut : handleClockIn}
                    disabled={processing}
                    className="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-primary-foreground px-3 py-3.5 text-sm font-bold text-primary transition-opacity hover:opacity-90 disabled:opacity-70"
                >
                    {processing ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                    ) : isClockedIn ? (
                        <LogOut className="h-4 w-4" />
                    ) : (
                        <LogIn className="h-4 w-4" />
                    )}
                    {isClockedIn ? 'Clock out' : 'Clock in'}
                </button>
                <button
                    type="button"
                    onClick={toggleBreak}
                    disabled={!isClockedIn}
                    aria-label={onBreak ? 'End break' : 'Start break'}
                    title={onBreak ? 'End break' : 'Break'}
                    className={cn(
                        'inline-flex w-[46px] flex-none items-center justify-center rounded-xl border border-primary-foreground/25 text-primary-foreground transition-colors disabled:opacity-40',
                        onBreak
                            ? 'bg-[color:var(--hr-amber-soft)]'
                            : 'bg-primary-foreground/10 hover:bg-primary-foreground/20',
                    )}
                >
                    <Coffee className="h-4 w-4" />
                </button>
            </div>
        </div>
    );
}

export default MyHrClockCard;
