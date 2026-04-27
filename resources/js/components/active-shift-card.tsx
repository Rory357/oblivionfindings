import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Clock,
    FileText,
    MapPin,
    Pill,
    Siren,
    UserRound,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import BreakControl from '@/components/break-control';
import EndOfShiftChecklist, {
    type EndOfShiftBlocker,
} from '@/components/end-of-shift-checklist';
import HandoverWriteSheet from '@/components/handover-write-sheet';
import ShiftTaskList, {
    type ShiftTaskListItem,
} from '@/components/shift-task-list';
import StaffStatus from '@/components/staff-status';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { formatTime } from '@/lib/datetime';

export interface ActiveShiftSession {
    id: number;
    clock_in_at: string | null;
    shift_id: number | null;
    client_name: string | null;
    client_photo_url?: string | null;
    shift_starts_at: string | null;
    shift_ends_at: string | null;
    location: string | null;
    service_type?: string | null;
    break_started_at?: string | null;
    break_minutes?: number;
    break_count?: number;
    is_on_break?: boolean;
    tasks?: ShiftTaskListItem[];
    task_progress?: number;
    handover_submitted?: boolean;
    end_of_shift_blockers?: EndOfShiftBlocker[];
    end_of_shift_ready?: boolean;
    quick_action_urls?: {
        incident: string;
        emar: string;
        escalate: string;
    };
}

function formatElapsed(sinceIso: string, now: number): string {
    const start = new Date(sinceIso).getTime();
    const diffSec = Math.max(0, Math.floor((now - start) / 1000));
    const h = Math.floor(diffSec / 3600);
    const m = Math.floor((diffSec % 3600) / 60);
    if (h > 0) return `${h}h ${String(m).padStart(2, '0')}m`;
    return `${m}m`;
}

export default function ActiveShiftCard({
    session,
}: {
    session: ActiveShiftSession;
}) {
    const [now, setNow] = useState(() => Date.now());
    const [endOpen, setEndOpen] = useState(false);
    const [noteOpen, setNoteOpen] = useState(false);

    useEffect(() => {
        if (!session.clock_in_at) return;
        const id = setInterval(() => setNow(Date.now()), 30_000);
        return () => clearInterval(id);
    }, [session.clock_in_at]);

    const tasks = session.tasks ?? [];
    const completedTasks = tasks.filter((task) => task.is_completed).length;
    const blockers = session.end_of_shift_blockers ?? [];
    const taskBlocker = blockers.find(
        (blocker) => blocker.key === 'tasks_pending',
    );
    const medBlocker = blockers.find(
        (blocker) => blocker.key === 'meds_unsigned',
    );
    const incidentBlocker = blockers.find(
        (blocker) => blocker.key === 'incidents_draft',
    );
    const handoverBlocker = blockers.find(
        (blocker) => blocker.key === 'handover_missing',
    );
    const elapsed = session.clock_in_at
        ? formatElapsed(session.clock_in_at, now)
        : '0m';
    const scheduledLabel =
        session.shift_starts_at && session.shift_ends_at
            ? `${formatTime(session.shift_starts_at)} - ${formatTime(session.shift_ends_at)}`
            : null;
    const clientInitials = (session.client_name ?? 'PS')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');

    const remainingLabel = useMemo(() => {
        if (!session.shift_ends_at) return null;
        const end = new Date(session.shift_ends_at).getTime();
        const diffMin = Math.floor((end - now) / 60_000);
        if (diffMin < 0) return 'Past rostered end';
        if (diffMin < 60) return `${diffMin}m remaining`;
        return `${Math.floor(diffMin / 60)}h ${diffMin % 60}m remaining`;
    }, [now, session.shift_ends_at]);

    return (
        <section
            id="clock"
            className="scroll-mt-20 rounded-xl border border-primary/30 bg-primary/5 p-4 shadow-sm"
        >
            <div className="flex flex-col gap-5">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex min-w-0 gap-3">
                        <Avatar className="size-14 border bg-background">
                            <AvatarImage
                                src={session.client_photo_url ?? undefined}
                                alt=""
                            />
                            <AvatarFallback className="text-sm font-semibold">
                                {clientInitials || (
                                    <UserRound className="h-5 w-5" />
                                )}
                            </AvatarFallback>
                        </Avatar>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <StaffStatus
                                    kind="shift"
                                    state={
                                        session.is_on_break
                                            ? 'on-break'
                                            : 'active'
                                    }
                                    size="sm"
                                />
                                {session.service_type ? (
                                    <span className="rounded-full border bg-background/80 px-2 py-0.5 text-xs text-muted-foreground">
                                        {session.service_type}
                                    </span>
                                ) : null}
                            </div>

                            <h2 className="mt-3 text-xl font-semibold">
                                Active shift
                            </h2>
                            <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                <span className="inline-flex items-center gap-1.5 font-medium">
                                    <UserRound className="h-4 w-4" />
                                    {session.client_name ?? 'Person we support'}
                                </span>
                                {session.location ? (
                                    <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                        <MapPin className="h-4 w-4" />
                                        {session.location}
                                    </span>
                                ) : null}
                                {scheduledLabel ? (
                                    <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                        <Clock className="h-4 w-4" />
                                        {scheduledLabel}
                                    </span>
                                ) : null}
                            </div>
                        </div>
                    </div>

                    <div className="rounded-lg border bg-background/80 px-4 py-3 lg:min-w-44">
                        <div className="text-2xl font-semibold tabular-nums">
                            {elapsed}
                        </div>
                        <div className="mt-1 text-xs text-muted-foreground">
                            {remainingLabel ?? 'On shift'}
                        </div>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-4">
                    <div className="rounded-lg border bg-background/80 p-3">
                        <div className="text-sm font-medium">Tasks</div>
                        <div className="mt-1 text-sm text-muted-foreground">
                            {taskBlocker
                                ? `${taskBlocker.count} open`
                                : `${completedTasks}/${tasks.length}`}
                        </div>
                    </div>
                    <div className="rounded-lg border bg-background/80 p-3">
                        <div className="text-sm font-medium">Meds</div>
                        <div className="mt-1 text-sm text-muted-foreground">
                            {medBlocker
                                ? `${medBlocker.count} unsigned`
                                : 'Signed'}
                        </div>
                    </div>
                    <div className="rounded-lg border bg-background/80 p-3">
                        <div className="text-sm font-medium">Incidents</div>
                        <div className="mt-1 text-sm text-muted-foreground">
                            {incidentBlocker
                                ? `${incidentBlocker.count} draft`
                                : '0 draft'}
                        </div>
                    </div>
                    <div className="rounded-lg border bg-background/80 p-3">
                        <div className="text-sm font-medium">Handover</div>
                        <div className="mt-1 text-sm text-muted-foreground">
                            {session.handover_submitted || !handoverBlocker
                                ? 'Done'
                                : 'Needed'}
                        </div>
                    </div>
                </div>

                <div>
                    <div className="mb-2 flex items-center justify-between gap-3">
                        <h3 className="text-sm font-semibold">Shift tasks</h3>
                        <span className="text-xs text-muted-foreground">
                            {session.task_progress ?? 100}%
                        </span>
                    </div>
                    <Progress
                        value={session.task_progress ?? 100}
                        className="mb-3 h-2"
                    />
                    <ShiftTaskList tasks={tasks} />
                </div>

                <BreakControl
                    sessionId={session.id}
                    isOnBreak={!!session.is_on_break}
                    breakStartedAt={session.break_started_at ?? null}
                    breakMinutes={session.break_minutes ?? 0}
                />

                <div className="grid gap-2 sm:grid-cols-4">
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={() => setEndOpen(true)}
                    >
                        <FileText className="mr-2 h-4 w-4" />
                        End shift
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setNoteOpen(true)}
                        disabled={!session.shift_id}
                    >
                        <FileText className="mr-2 h-4 w-4" />
                        Add note
                    </Button>
                    <Button asChild variant="outline">
                        <Link
                            href={
                                session.quick_action_urls?.incident ??
                                '/incidents'
                            }
                        >
                            <AlertTriangle className="mr-2 h-4 w-4" />
                            Incident
                        </Link>
                    </Button>
                    <Button asChild>
                        <Link
                            href={
                                session.quick_action_urls?.emar ?? '/meds/today'
                            }
                        >
                            <Pill className="mr-2 h-4 w-4" />
                            eMAR
                            <ArrowRight className="ml-2 h-4 w-4" />
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-2 sm:grid-cols-2">
                    <Button asChild variant="outline">
                        <Link
                            href={
                                session.quick_action_urls?.escalate ??
                                '/control-room'
                            }
                        >
                            <Siren className="mr-2 h-4 w-4" />
                            Escalate
                        </Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href="/my-roster">
                            <FileText className="mr-2 h-4 w-4" />
                            Roster
                        </Link>
                    </Button>
                </div>
            </div>

            <div className="fixed inset-x-0 bottom-[calc(3.75rem+env(safe-area-inset-bottom,0px))] z-30 border-t bg-background/95 px-3 py-2 shadow-lg backdrop-blur lg:hidden">
                <Button
                    type="button"
                    className="h-12 w-full"
                    variant="destructive"
                    onClick={() => setEndOpen(true)}
                >
                    <FileText className="mr-2 h-4 w-4" />
                    End shift
                </Button>
            </div>

            <EndOfShiftChecklist
                session={session}
                open={endOpen}
                onOpenChange={setEndOpen}
            />
            <HandoverWriteSheet
                shiftId={session.shift_id}
                alreadySubmitted={session.handover_submitted}
                open={noteOpen}
                onOpenChange={setNoteOpen}
            />
        </section>
    );
}
