import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Clock,
    FileText,
    MapPin,
    Pill,
    UserRound,
} from 'lucide-react';

import StaffStatus from '@/components/staff-status';
import { Button } from '@/components/ui/button';
import { useMyDayLabels } from '@/hooks/use-my-day-labels';
import { cn } from '@/lib/utils';
import { formatRelative, formatTime } from '@/lib/datetime';

import type { HandoverReadPayload } from './handover-read-card';
import type { RosterShift } from './roster/types';
import { Card as GuardrailCard } from '@/components/ui/card';

// Match the 5-minute grace window used by Shift::isLate so the card and the
// status pill agree on when a worker is "late".
const LATE_THRESHOLD_MINUTES = 5;

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
    const t = useMyDayLabels();
    const minutesUntil = briefing.minutes_until_start;
    const minutesLate =
        minutesUntil !== null && minutesUntil < 0 ? Math.abs(minutesUntil) : 0;
    const isLate = minutesLate >= LATE_THRESHOLD_MINUTES;
    const startsSoon =
        minutesUntil !== null && minutesUntil >= 0
            ? t('starts_in_minutes', { minutes: minutesUntil })
            : briefing.starts_at
              ? `Started ${formatRelative(briefing.starts_at)}`
              : 'Upcoming shift';
    const medications = briefing.medications_due_during_shift ?? [];
    const notes = briefing.what_to_know?.trim();
    const canStart =
        briefing.minutes_until_start === null ||
        briefing.minutes_until_start <= 240;

    return (
        <section
            className={cn(
                'rounded-xl border p-4 shadow-sm transition-colors',
                isLate
                    ? 'border-status-critical/40 bg-status-critical-bg ring-1 ring-status-critical/30'
                    : 'border-primary/30 bg-primary/5',
            )}
            role={isLate ? 'alert' : undefined}
        >
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0 space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <StaffStatus
                            kind="shift"
                            state={isLate ? 'late' : briefing.status_state}
                            size="sm"
                        />
                        <span className="text-sm font-semibold">
                            {startsSoon}
                        </span>
                        {isLate ? (
                            <span className="inline-flex items-center gap-1 rounded-full bg-status-critical/10 px-2 py-0.5 text-xs font-semibold text-status-critical">
                                <AlertTriangle className="h-3 w-3" />
                                {t('started_minutes_late', {
                                    minutes: minutesLate,
                                })}
                            </span>
                        ) : null}
                    </div>

                    <div>
                        <h2 className="text-lg font-semibold">
                            {t('before_you_start')}
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
                        <GuardrailCard unstyled className="rounded-lg border bg-background/80 p-3 text-sm">
                            <div className="mb-1 flex items-center gap-2 font-medium">
                                <FileText className="h-4 w-4 text-muted-foreground" />
                                {t('what_to_know')}
                            </div>
                            <p className="line-clamp-3 whitespace-pre-wrap text-muted-foreground">
                                {notes}
                            </p>
                        </GuardrailCard>
                    ) : null}

                    <div className="grid gap-2 sm:grid-cols-2">
                        <GuardrailCard unstyled className="rounded-lg border bg-background/80 p-3 text-sm">
                            <div className="flex items-center gap-2 font-medium">
                                <Clock className="h-4 w-4 text-muted-foreground" />
                                {t('tasks')}
                            </div>
                            <p className="mt-1 text-muted-foreground">
                                {t('tasks_planned', {
                                    count: briefing.tasks.length,
                                })}
                            </p>
                        </GuardrailCard>
                        <GuardrailCard unstyled className="rounded-lg border bg-background/80 p-3 text-sm">
                            <div className="flex items-center gap-2 font-medium">
                                <Pill className="h-4 w-4 text-muted-foreground" />
                                {t('meds_during_shift')}
                            </div>
                            {medications.length > 0 ? (
                                <p className="mt-1 text-muted-foreground">
                                    {t('scheduled_count', {
                                        count: medications.length,
                                    })}
                                </p>
                            ) : (
                                <p className="mt-1 text-muted-foreground">
                                    {t('none_scheduled')}
                                </p>
                            )}
                        </GuardrailCard>
                    </div>

                    {briefing.incoming_handover ? (
                        <GuardrailCard unstyled className="rounded-lg border bg-background/80 p-3 text-sm">
                            <div className="font-medium">
                                {t('incoming_handover')}
                            </div>
                            <p className="mt-1 line-clamp-2 text-muted-foreground">
                                {briefing.incoming_handover.handover_notes ||
                                    t('read_handover_hint')}
                            </p>
                        </GuardrailCard>
                    ) : null}
                </div>

                <div className="flex shrink-0 flex-col gap-2 lg:w-44">
                    {canStart ? (
                        <Button asChild size="lg" className="h-12">
                            <a href="#clock">
                                {t('start_shift')}
                                <ArrowRight className="ml-2 h-4 w-4" />
                            </a>
                        </Button>
                    ) : (
                        <Button size="lg" className="h-12" disabled>
                            {t('starts_later')}
                        </Button>
                    )}
                    <Button asChild variant="outline">
                        <Link href="/my-roster">{t('view_roster')}</Link>
                    </Button>
                </div>
            </div>
        </section>
    );
}
