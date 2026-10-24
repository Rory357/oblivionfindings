import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import { severityBadge, targetBadge } from '@/components/control-room/alert-record/alert-labels';
import {
    OwnerChip,
    rowClock,
    UrgencyBar,
    whoAndWhere,
} from '@/components/control-room/live-response/alert-glance';
import { raisedWords, useNow } from '@/components/control-room/live-response/response-clock';
import { TargetLens } from '@/components/control-room/live-response/target-lens';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, Check, UserPlus } from 'lucide-react';

const ACTIVE = new Set(['open', 'ack', 'triaging', 'confirmed']);

/** The alert the "Needs you now" card shows: the top of the list, if it's urgent. */
export function needsYouNow(rows: AlertWorklistRow[]): AlertWorklistRow | null {
    const top = rows[0];
    if (!top || !ACTIVE.has(top.status)) return null;
    const urgent =
        top.severity === 'critical' ||
        top.severity === 'high' ||
        top.sla.status === 'breached' ||
        top.sla.status === 'at_risk';
    return urgent ? top : null;
}

/**
 * "Needs you now" — the most urgent open alert, big, above the list
 * (Stephan, 24 Oct 2026). Person and house, what happened, the countdown
 * to its next target and the one next step. It only appears when the top
 * alert is urgent, so a calm board stays calm.
 */
export function NeedsYouNowCard({
    row,
    onAcknowledge,
    onTake,
}: {
    row: AlertWorklistRow;
    onAcknowledge: () => void;
    onTake: () => void;
}) {
    const now = useNow();
    const clock = rowClock(row, now);
    const { who, where } = whoAndWhere(row);
    const severity = severityBadge(row.severity);
    const target =
        row.sla.status === 'breached' || row.sla.status === 'at_risk'
            ? targetBadge(row.sla.status)
            : null;
    const raised = raisedWords(row.triggered_at, now);
    const critical = row.severity === 'critical' || row.sla.status === 'breached';

    return (
        <Card
            aria-label={`Needs you now: ${who}`}
            className="popup-horizon relative min-w-0 gap-0 overflow-hidden py-0"
        >
            <UrgencyBar severity={row.severity} className="w-1.5" />
            <div className="flex flex-wrap items-center gap-x-6 gap-y-4 py-5 pr-5 pl-7">
                <TargetLens clock={clock} size="lg" label="Next response target" />
                <div className="min-w-[16rem] flex-1">
                    <p
                        className={cn(
                            'text-xs font-bold tracking-[0.08em] uppercase',
                            critical ? 'text-status-critical' : 'text-primary',
                        )}
                    >
                        Needs you now
                        <span className="ml-2 font-semibold tracking-normal normal-case text-muted-foreground">
                            {clock.words}
                        </span>
                    </p>
                    <h2 className="mt-1 text-lg leading-snug font-semibold break-words text-foreground">
                        {who}
                        {where ? (
                            <span className="font-normal text-muted-foreground"> · {where}</span>
                        ) : null}
                    </h2>
                    <p className="mt-0.5 text-base break-words text-foreground">
                        {row.summary}
                    </p>
                    <p className="text-caption mt-2 flex flex-wrap items-center gap-x-2.5 gap-y-1">
                        <StatusBadge variant={severity.tone} size="sm">
                            {severity.label}
                        </StatusBadge>
                        {target ? (
                            <StatusBadge variant={target.tone} size="sm">
                                {target.label}
                            </StatusBadge>
                        ) : null}
                        <OwnerChip name={row.assignee?.name} />
                        {raised ? (
                            <span title={formatDateTime(row.triggered_at)}>{raised}</span>
                        ) : null}
                        <span className="tabular-nums">
                            {row.reference_number ?? `Alert ${row.id}`}
                        </span>
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {row.actions.can_acknowledge ? (
                        <Button type="button" size="lg" onClick={onAcknowledge}>
                            <Check aria-hidden="true" />
                            Acknowledge
                        </Button>
                    ) : null}
                    {row.actions.can_claim && !row.assignee ? (
                        <Button
                            type="button"
                            size="lg"
                            variant={row.actions.can_acknowledge ? 'outline' : 'default'}
                            onClick={onTake}
                        >
                            <UserPlus aria-hidden="true" />
                            Take it
                        </Button>
                    ) : null}
                    <Button
                        asChild
                        size="lg"
                        // With no step to take here, opening it is the next step.
                        variant={
                            row.actions.can_acknowledge || (row.actions.can_claim && !row.assignee)
                                ? 'outline'
                                : 'default'
                        }
                    >
                        <Link href={row.href}>
                            Open
                            <ArrowUpRight aria-hidden="true" />
                        </Link>
                    </Button>
                </div>
            </div>
        </Card>
    );
}
