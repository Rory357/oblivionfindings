import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import {
    responseClock,
    type ResponseClock,
} from '@/components/control-room/live-response/response-clock';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';

/** The fixed safety colour of an alert's urgency bar (never the brand). */
export const URGENCY_BAR: Record<string, string> = {
    critical: 'bg-status-critical',
    high: 'bg-status-warning',
    medium: 'bg-status-info',
    low: 'bg-border',
};

/** The coloured edge that says how urgent an alert is before you read it. */
export function UrgencyBar({
    severity,
    className,
}: {
    severity: string;
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'absolute inset-y-0 left-0 w-1',
                URGENCY_BAR[severity] ?? URGENCY_BAR.low,
                className,
            )}
        />
    );
}

const FINISHED = new Set(['resolved', 'closed', 'dismissed']);

/** Where an alert stands against time, read from its list row. */
export function rowClock(row: AlertWorklistRow, now: number): ResponseClock {
    if (FINISHED.has(row.status)) {
        return {
            mode: 'none',
            tone: 'neutral',
            left: 1,
            short: 'Done',
            words: 'finished',
        };
    }
    return responseClock(
        {
            raisedAt: row.triggered_at,
            deadlineAt: row.sla.next_deadline_at ?? row.next_deadline_at,
            targetStatus: row.sla.status,
        },
        now,
    );
}

/**
 * Who the alert is about and where, person first: "Aroha Tane" ·
 * "Kōwhai House". With no person, the house leads; with neither, the
 * source ("Security devices").
 */
export function whoAndWhere(row: Pick<AlertWorklistRow, 'person' | 'site' | 'source'>): {
    who: string;
    where: string | null;
} {
    if (row.person?.name) return { who: row.person.name, where: row.site?.name ?? null };
    if (row.site?.name) return { who: row.site.name, where: row.source.label };
    return { who: row.source.label, where: null };
}

/** "Sam Kerr is on it" with their initials, or "No one on it yet". */
export function OwnerChip({
    name,
    size = 'sm',
}: {
    name: string | null | undefined;
    size?: 'sm' | 'md';
}) {
    const initials = useInitials();
    if (!name) {
        return (
            <span className="inline-flex items-center gap-1.5 font-semibold text-status-warning">
                <span
                    aria-hidden="true"
                    className={cn(
                        'inline-block rounded-full border-[1.5px] border-dashed border-current',
                        size === 'md' ? 'size-6' : 'size-[18px]',
                    )}
                />
                No one on it yet
            </span>
        );
    }
    return (
        <span className="inline-flex min-w-0 items-center gap-1.5">
            <Avatar className={size === 'md' ? 'size-6' : 'size-[18px]'}>
                <AvatarFallback
                    className={cn(
                        'bg-primary-fill/15 font-semibold text-primary',
                        size === 'md' ? 'text-[10px]' : 'text-[8.5px]',
                    )}
                >
                    {initials(name)}
                </AvatarFallback>
            </Avatar>
            <span className="min-w-0 truncate">
                <span className="font-semibold text-foreground">{name}</span> is on it
            </span>
        </span>
    );
}
