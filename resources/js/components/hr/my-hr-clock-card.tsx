/* eslint-disable no-restricted-syntax -- The clock-in/out and break controls are
 * bespoke full-width pills whose background flips by clock state (primary →
 * status-critical) and whose layout/typography mirror the design handoff's
 * clock card; the shadcn <Button> can't express them without fighting it. */
import { router } from '@inertiajs/react';
import { Coffee, Loader2, LogIn, LogOut } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { fireConfetti } from '@/lib/confetti';
import { cn } from '@/lib/utils';

export type MyHrActiveClock = {
    id: number;
    clock_in: string;
    notes?: string | null;
} | null;

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

/**
 * The promoted, high-contrast clock card that sits in the My HR hero. Backed by
 * the single shared AttendanceService via the existing `/hr/time/clock-in|out`
 * endpoints (no new clock path). The live timer ticks every second; the break
 * toggle pauses the *displayed* elapsed client-side and submits the accrued
 * `break_minutes` at clock-out, which the controller already accepts.
 */
export function MyHrClockCard({
    activeClock,
    todayTotal,
    siteName,
}: {
    activeClock: MyHrActiveClock;
    todayTotal: number;
    siteName?: string | null;
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

    const liveSince = useMemo(() => {
        if (!clockInMs) return '';
        return new Date(clockInMs).toLocaleTimeString('en-NZ', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        });
    }, [clockInMs]);

    function handleClockIn() {
        setProcessing(true);
        router.post(
            '/hr/time/clock-in',
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
            '/hr/time/clock-out',
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

    const status = isClockedIn
        ? onBreak
            ? 'On break'
            : 'On shift'
        : 'Not clocked in';
    const statusColor = isClockedIn
        ? onBreak
            ? 'text-status-warning'
            : 'text-live'
        : 'text-muted-foreground';
    const sub = isClockedIn
        ? onBreak
            ? 'Paused — tap to resume'
            : `Live since ${liveSince}${siteName ? ` · ${siteName}` : ''}`
        : 'Tap clock in to start your shift';

    return (
        <div className="w-full rounded-[18px] bg-card p-[18px] text-card-foreground shadow-[0_18px_44px_-16px_rgba(0,0,0,0.5)] md:w-[286px]">
            <div className="flex items-center justify-between">
                <div className="inline-flex items-center gap-2">
                    {isClockedIn ? (
                        <span className="relative inline-flex h-2.5 w-2.5">
                            <span
                                className={cn(
                                    'absolute inline-flex h-full w-full animate-ping rounded-full opacity-75 motion-reduce:animate-none',
                                    onBreak ? 'bg-status-warning' : 'bg-live',
                                )}
                            />
                            <span
                                className={cn(
                                    'relative inline-flex h-2.5 w-2.5 rounded-full',
                                    onBreak ? 'bg-status-warning' : 'bg-live',
                                )}
                            />
                        </span>
                    ) : null}
                    <span className={cn('text-[12.5px] font-bold', statusColor)}>
                        {status}
                    </span>
                </div>
                <span className="text-[11px] text-muted-foreground">
                    Today {fmtHM(isClockedIn ? elapsed : Math.round(todayTotal * 3600))}
                </span>
            </div>

            <div className="my-3.5 text-center">
                <div
                    className={cn(
                        'text-[42px] font-bold leading-none tabular-nums',
                        isClockedIn ? 'text-foreground' : 'text-muted-foreground',
                    )}
                >
                    {fmtTimer(isClockedIn ? elapsed : 0)}
                </div>
                <div className="mt-1.5 text-[11px] text-muted-foreground">{sub}</div>
            </div>

            <button
                type="button"
                onClick={isClockedIn ? handleClockOut : handleClockIn}
                disabled={processing}
                className={cn(
                    'mt-3.5 flex w-full items-center justify-center gap-2 rounded-xl px-3 py-3 text-sm font-bold text-white shadow-md transition-colors disabled:opacity-70',
                    isClockedIn
                        ? 'bg-status-critical hover:bg-status-critical/90'
                        : 'bg-primary hover:bg-primary/90',
                )}
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
                className={cn(
                    'mt-2 flex w-full items-center justify-center gap-1.5 rounded-xl border border-border px-3 py-2.5 text-[12.5px] font-semibold transition-colors disabled:opacity-50',
                    onBreak
                        ? 'bg-status-warning-bg text-status-warning'
                        : 'bg-card text-foreground hover:bg-muted',
                )}
            >
                <Coffee className="h-3.5 w-3.5" />
                {onBreak ? 'End break' : 'Start break'}
            </button>
        </div>
    );
}

export default MyHrClockCard;
