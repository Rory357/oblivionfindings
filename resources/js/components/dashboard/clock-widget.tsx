import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { Loader2, LogIn, LogOut } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

type ClockWidgetProps = {
    activeClock: { id: number; clock_in: string; notes: string | null } | null;
    todayTotal: number;
};

function formatElapsed(seconds: number): string {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
}

function formatHours(hours: number): string {
    const h = Math.floor(hours);
    const m = Math.round((hours - h) * 60);
    if (h === 0) return `${m}m`;
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

export function ClockWidget({ activeClock, todayTotal }: ClockWidgetProps) {
    const [processing, setProcessing] = useState(false);
    const [elapsed, setElapsed] = useState(0);

    const clockInTime = useMemo(() => {
        if (!activeClock) return null;
        return new Date(activeClock.clock_in);
    }, [activeClock]);

    // Live elapsed counter
    useEffect(() => {
        if (!clockInTime) {
            setElapsed(0);
            return;
        }

        const update = () => {
            setElapsed(Math.floor((Date.now() - clockInTime.getTime()) / 1000));
        };
        update();
        const interval = setInterval(update, 1000);
        return () => clearInterval(interval);
    }, [clockInTime]);

    function handleClockIn() {
        setProcessing(true);
        router.post(
            '/hr/time/clock-in',
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Clocked in successfully'),
                onError: () => toast.error('Failed to clock in'),
                onFinish: () => setProcessing(false),
            },
        );
    }

    function handleClockOut() {
        setProcessing(true);
        router.post(
            '/hr/time/clock-out',
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Clocked out successfully'),
                onError: () => toast.error('Failed to clock out'),
                onFinish: () => setProcessing(false),
            },
        );
    }

    const isClockedIn = !!activeClock;

    return (
        <div className="flex flex-col items-center gap-3">
            {/* Status indicator */}
            <div className="flex items-center gap-2">
                {isClockedIn ? (
                    <>
                        <span className="relative flex h-2.5 w-2.5">
                            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                            <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500" />
                        </span>
                        <span className="text-sm font-medium text-emerald-300">Clocked In</span>
                    </>
                ) : (
                    <>
                        <span className="h-2.5 w-2.5 rounded-full bg-white/30" />
                        <span className="text-sm text-white/60">Not Clocked In</span>
                    </>
                )}
            </div>

            {/* Timer display */}
            <div className="text-center">
                {isClockedIn ? (
                    <div className="font-mono text-3xl font-bold tracking-wider tabular-nums">
                        {formatElapsed(elapsed)}
                    </div>
                ) : (
                    <div className="text-2xl font-semibold text-white/40">--:--:--</div>
                )}
                {todayTotal > 0 && (
                    <p className="mt-1 text-xs text-white/50">
                        Today: {formatHours(todayTotal)}
                    </p>
                )}
            </div>

            {/* Clock button */}
            {isClockedIn ? (
                <Button
                    onClick={handleClockOut}
                    disabled={processing}
                    variant="destructive"
                    size="lg"
                    className="gap-2 rounded-full px-8 bg-red-500 hover:bg-red-600 shadow-md"
                >
                    {processing ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                        <LogOut className="h-4 w-4" />
                    )}
                    Clock Out
                </Button>
            ) : (
                <Button
                    onClick={handleClockIn}
                    disabled={processing}
                    size="lg"
                    className="gap-2 rounded-full bg-white px-8 text-primary font-semibold hover:bg-white/90 shadow-md"
                >
                    {processing ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                        <LogIn className="h-4 w-4" />
                    )}
                    Clock In
                </Button>
            )}
        </div>
    );
}
