import { usePage } from '@inertiajs/react';
import { Coffee, Loader2, LogIn, LogOut } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AttendanceBreakFeedback } from '@/components/attendance-break-feedback';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { useAttendanceBreak } from '@/hooks/use-attendance-break';
import { formatDateTimeInZone, formatDurationMinutes, WORKER_TIMEZONE } from '@/lib/datetime';
import type { SharedData } from '@/types';
import { myHrBreakContext, readMyHrAttendance } from './my-hr-attendance';
import { MyHrClockFeedback } from './my-hr-clock-feedback';
import type { MyHrClockExpectation } from './my-hr-clock-result';
import type { MyHrShellData } from './my-hr-types';
import { useMyHrClock } from './use-my-hr-clock';

/** Retained legacy projection shape; its ID is never an attendance session ID. */
export type MyHrActiveClock = {
    id: number;
    clock_in: string;
    notes?: string | null;
} | null;

function timer(seconds: number): string {
    return [Math.floor(seconds / 3600), Math.floor((seconds % 3600) / 60), seconds % 60]
        .map((part) => String(part).padStart(2, '0')).join(':');
}

/** The existing My HR clock panel uses recorded attendance and the existing self-service routes. */
export function MyHrClockCard({
    attendance,
    siteName,
    nextShift,
}: {
    attendance?: unknown;
    siteName?: string | null;
    nextShift?: MyHrShellData['nextShift'];
}) {
    const { auth } = usePage<SharedData>().props;
    const read = readMyHrAttendance(attendance, auth?.user?.id);
    const timesheetGrants = auth?.can?.timesheets as { create?: boolean } | undefined;
    const canClock = timesheetGrants?.create === true ||
        auth?.can?.shifts?.viewAssigned === true ||
        auth?.can?.shifts?.update === true ||
        auth?.can?.shifts?.manageAny === true;
    const session = read?.state === 'open' ? read.session : null;
    const entry = read?.state === 'entry_only' ? read.time_entry : null;
    const timezone = read?.timezone ?? WORKER_TIMEZONE;
    let clockContext: MyHrClockExpectation | null = null;
    // A confirmed legacy closure can refresh to idle for an HR-only worker.
    // Keeping that read identity does not grant the clock-in button below.
    if (read?.state === 'none')
        clockContext = { actorId: read.actor_id, target: { kind: 'idle', shiftId: null } };
    else if (session && canClock)
        clockContext = { actorId: read!.actor_id, target: { kind: 'attendance_session', id: session.id, shiftId: session.shift_id } };
    else if (entry)
        clockContext = { actorId: read!.actor_id, target: { kind: 'orphan_time_entry', id: entry.id, shiftId: entry.shift_id } };

    const clock = useMyHrClock(clockContext);
    const breaks = useAttendanceBreak(myHrBreakContext(read), (raw) => {
        if (!raw || typeof raw !== 'object') return null;
        const props = raw as SharedData & { myHr?: { attendance?: unknown } };
        const refreshed = readMyHrAttendance(props.myHr?.attendance, props.auth?.user?.id);
        return refreshed?.state === 'open' ? myHrBreakContext(refreshed) : null;
    });
    const breakHeld = breaks.pending || breaks.refreshing ||
        (breaks.outcome !== null && breaks.outcome.status !== 'rejected' &&
            (breaks.outcome.status !== 'confirmed' || !breaks.outcome.refreshed));
    const [now, setNow] = useState(() => Date.now());
    const [breakOverride, setBreakOverride] = useState('');
    const entryKey = entry ? `${read!.actor_id}:${entry.id}:${entry.shift_id}` : null;
    useEffect(() => setBreakOverride(''), [entryKey]);
    const tickingKey = session ? `session:${session.id}` : entry ? `entry:${entry.id}` : null;
    useEffect(() => {
        if (tickingKey === null) return;
        setNow(Date.now());
        const handle = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(handle);
    }, [tickingKey]);

    const recorded = session ?? entry;
    const runningBreakSeconds = session?.is_on_break && session.break_started_at
        ? Math.max(0, Math.floor((now - Date.parse(session.break_started_at)) / 1000)) : 0;
    const elapsed = recorded
        ? Math.max(0, Math.floor((now - Date.parse(recorded.clock_in_at)) / 1000) -
            recorded.break_minutes * 60 - runningBreakSeconds) : 0;
    const unavailable = !read || read.state === 'unavailable';
    const status = unavailable ? 'Clock record unavailable'
        : entry ? 'Older time entry'
        : session ? session.is_on_break ? 'On break' : 'On shift' : 'Off the clock';
    const clockLabel = entry ? 'Close time entry' : session ? 'Clock out' : 'Clock in';
    const canCloseSession = read?.state === 'open' && read.can_clock_out;

    function submitClock() {
        if ((!canClock && !entry) || (session && !canCloseSession) ||
            clock.isBlocked() || breakHeld || (session && breaks.blocksSession(session.id))) return;
        void clock.submit(entry && breakOverride.trim() ? Number(breakOverride) : undefined);
    }
    function submitBreak() {
        if (clock.isBlocked() || breaks.isBlocked()) return;
        void breaks.submit();
    }

    return (
        <section aria-label="My HR clock"
            className="flex w-full flex-col justify-center gap-4 border-primary-foreground/15 bg-primary-foreground/10 p-6 md:w-[348px] md:flex-none md:border-l">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <StatusBadge variant={unavailable || entry ? 'warning' : session ? 'info' : 'neutral'}>
                    {status}
                </StatusBadge>
                {recorded && (
                    <span className="text-caption text-primary-foreground/85">
                        {entry ? 'This entry' : 'This session'} · {formatDurationMinutes(Math.floor(elapsed / 60))}
                    </span>
                )}
            </div>
            {recorded ? (
                <div className="space-y-2">
                    <p className="text-section-title tabular-nums" aria-label="Elapsed work estimate">{timer(elapsed)}</p>
                    <p className="text-caption text-primary-foreground/85">
                        Clocked in {formatDateTimeInZone(recorded.clock_in_at, timezone)} ({timezone})
                    </p>
                    <p className="text-caption text-primary-foreground/85">
                        Recorded breaks: {formatDurationMinutes(recorded.break_minutes)}
                        {session?.is_on_break ? ' · Current break is still running' : ''}
                    </p>
                    <p className="text-caption text-primary-foreground/85">
                        Live estimate. Saved hours are confirmed {entry ? 'when you close this entry' : 'when you clock out'}.
                    </p>
                </div>
            ) : unavailable ? (
                <p className="text-subtle text-primary-foreground/85">
                    Refresh My HR to check your clock record before another action.
                </p>
            ) : (
                <div className="space-y-2">
                    <p className="text-caption text-primary-foreground/85">Next shift</p>
                    <p className="text-section-title">
                        {nextShift?.starts_at
                            ? formatDateTimeInZone(nextShift.starts_at, timezone)
                            : 'No upcoming shift scheduled'}
                    </p>
                    {nextShift?.starts_at && (
                        <p className="text-caption text-primary-foreground/85">
                            {nextShift.location ?? nextShift.service_context_name ?? siteName}
                        </p>
                    )}
                </div>
            )}
            {entry && (
                <div className="space-y-2 rounded-md bg-card p-3 text-card-foreground">
                    <p className="text-subtle">
                        This time entry has no attendance session. Closing it will keep its recorded breaks unless you change them below.
                    </p>
                    <Label htmlFor="my-hr-entry-breaks">Change break minutes (optional)</Label>
                    <Input id="my-hr-entry-breaks" type="number" inputMode="numeric" min={0} max={240} step={1}
                        className="frontline-tap" value={breakOverride}
                        placeholder={String(entry.break_minutes)}
                        disabled={clock.blocked || breakHeld}
                        onChange={(event) => setBreakOverride(event.target.value)} />
                    <p className="text-caption">Leave blank to keep {entry.break_minutes} recorded minutes.</p>
                </div>
            )}
            {!unavailable && clockContext && (canClock || entry) ? (
                <div className="flex flex-wrap gap-2">
                    {(!session || canCloseSession) && <Button unstyled className="frontline-tap inline-flex flex-1 items-center justify-center gap-2 rounded-md bg-primary-foreground px-3 py-3 font-semibold text-primary-strong"
                        disabled={clock.blocked || breakHeld} onClick={submitClock}>
                        {clock.pending ? <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                            : recorded ? <LogOut className="size-4" aria-hidden="true" /> : <LogIn className="size-4" aria-hidden="true" />}
                        {clock.pending ? 'Saving…' : clockLabel}
                    </Button>}
                    {session && read?.can_break && (
                        <Button variant="outline" className="frontline-tap"
                            disabled={clock.blocked || breaks.blocked || breaks.refreshing} onClick={submitBreak}>
                            <Coffee aria-hidden="true" />
                            {breaks.pending ? 'Saving…' : session.is_on_break ? 'End break' : 'Start break'}
                        </Button>
                    )}
                    {session && !canCloseSession && (
                        <div className="space-y-2">
                            <p className="text-subtle text-primary-foreground/85">
                                Clock-out is unavailable for this record right now. Check the current record; if it remains unavailable, ask your manager to review it.
                            </p>
                            <Button variant="outline" className="frontline-tap" asChild>
                                <a href="/hr/my">Check current clock record</a>
                            </Button>
                        </div>
                    )}
                </div>
            ) : unavailable ? (
                <Button variant="outline" className="frontline-tap" asChild>
                    <a href="/hr/my">Check current clock record</a>
                </Button>
            ) : (
                <p className="text-subtle text-primary-foreground/85">Clock actions are not available with your current access.</p>
            )}
            <MyHrClockFeedback command={clock} timezone={timezone} />
            <AttendanceBreakFeedback command={breaks} checkHref="/hr/my" />
        </section>
    );
}

export default MyHrClockCard;
