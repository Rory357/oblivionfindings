import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Clock,
    FileText,
    MapPin,
    Pill,
    UserRound,
} from 'lucide-react';

import StaffStatus from '@/components/staff-status';
import { Button } from '@/components/ui/button';
import { formatRelative, formatTime } from '@/lib/datetime';

import type { HandoverReadPayload } from './handover-read-card';
import type { RosterShift } from './roster/types';

type ShiftMedication = {
    medication_name: string;
    dose: string;
    scheduled_for: string;
    emar_url: string;
};

export type PreShiftBriefing = RosterShift & {
    minutes_until_start: number | null;
    incoming_handover: HandoverReadPayload | null;
    medications_due_during_shift: ShiftMedication[];
    what_to_know: string | null;
};

export default function PreShiftBriefingCard({
    briefing,
}: {
    briefing: PreShiftBriefing;
}) {
    const startsSoon =
        briefing.minutes_until_start !== null &&
        briefing.minutes_until_start >= 0
            ? `Starts in ${briefing.minutes_until_start}m`
            : briefing.starts_at
              ? `Started ${formatRelative(briefing.starts_at)}`
              : 'Upcoming shift';
    const medications = briefing.medications_due_during_shift ?? [];
    const notes = briefing.what_to_know?.trim();
    const canStart =
        briefing.minutes_until_start === null ||
        briefing.minutes_until_start <= 240;

    return (
        <section className="rounded-xl border border-primary/30 bg-primary/5 p-4 shadow-sm">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0 space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <StaffStatus
                            kind="shift"
                            state={briefing.status_state}
                            size="sm"
                        />
                        <span className="text-sm font-semibold">
                            {startsSoon}
                        </span>
                    </div>

                    <div>
                        <h2 className="text-lg font-semibold">
                            Before you start
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {formatTime(briefing.starts_at)} -{' '}
                            {formatTime(briefing.ends_at)}
                        </p>
                    </div>

                    <div className="grid gap-2 text-sm sm:grid-cols-2">
                        <span className="inline-flex min-w-0 items-center gap-2">
                            <UserRound className="h-4 w-4 shrink-0 text-muted-foreground" />
                            <span className="truncate">
                                {briefing.client?.name ?? 'Person we support'}
                            </span>
                        </span>
                        {briefing.location ? (
                            <span className="inline-flex min-w-0 items-center gap-2">
                                <MapPin className="h-4 w-4 shrink-0 text-muted-foreground" />
                                <span className="truncate">
                                    {briefing.location}
                                </span>
                            </span>
                        ) : null}
                    </div>

                    {notes ? (
                        <div className="rounded-lg border bg-background/80 p-3 text-sm">
                            <div className="mb-1 flex items-center gap-2 font-medium">
                                <FileText className="h-4 w-4 text-muted-foreground" />
                                What to know
                            </div>
                            <p className="line-clamp-3 whitespace-pre-wrap text-muted-foreground">
                                {notes}
                            </p>
                        </div>
                    ) : null}

                    <div className="grid gap-2 sm:grid-cols-2">
                        <div className="rounded-lg border bg-background/80 p-3 text-sm">
                            <div className="flex items-center gap-2 font-medium">
                                <Clock className="h-4 w-4 text-muted-foreground" />
                                Tasks
                            </div>
                            <p className="mt-1 text-muted-foreground">
                                {briefing.tasks.length} planned for this shift
                            </p>
                        </div>
                        <div className="rounded-lg border bg-background/80 p-3 text-sm">
                            <div className="flex items-center gap-2 font-medium">
                                <Pill className="h-4 w-4 text-muted-foreground" />
                                Meds during shift
                            </div>
                            {medications.length > 0 ? (
                                <p className="mt-1 text-muted-foreground">
                                    {medications.length} scheduled
                                </p>
                            ) : (
                                <p className="mt-1 text-muted-foreground">
                                    None scheduled
                                </p>
                            )}
                        </div>
                    </div>

                    {briefing.incoming_handover ? (
                        <div className="rounded-lg border bg-background/80 p-3 text-sm">
                            <div className="font-medium">
                                Incoming handover ready
                            </div>
                            <p className="mt-1 line-clamp-2 text-muted-foreground">
                                {briefing.incoming_handover.handover_notes ||
                                    'Read the handover before clocking in.'}
                            </p>
                        </div>
                    ) : null}
                </div>

                <div className="flex shrink-0 flex-col gap-2 lg:w-44">
                    {canStart ? (
                        <Button asChild size="lg" className="h-12">
                            <a href="#clock">
                                Start shift
                                <ArrowRight className="ml-2 h-4 w-4" />
                            </a>
                        </Button>
                    ) : (
                        <Button size="lg" className="h-12" disabled>
                            Starts later
                        </Button>
                    )}
                    <Button asChild variant="outline">
                        <Link href="/my-roster">View roster</Link>
                    </Button>
                </div>
            </div>
        </section>
    );
}
