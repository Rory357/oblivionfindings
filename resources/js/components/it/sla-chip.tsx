/* The SLA chip — one component for the queue rows and the workspace header
 * so "how long is left" always reads the same way. Tone comes from the
 * server's sla_state verdict (the only place waiting-pauses are known);
 * the countdown ticks locally once a minute with no re-fetch. Always icon +
 * text, never colour alone (WCAG). */
import { StatusBadge } from '@/components/ui/status-badge';
import { CirclePause, Clock, Timer, TimerOff } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface SlaFields {
    status: string;
    sla_state: string;
    first_response_due_at: string | null;
    resolution_due_at: string | null;
    first_responded_at: string | null;
}

const SETTLED = ['resolved', 'closed'];

/** Re-render once a minute so live countdowns stay honest without polling. */
export function useMinuteTick(enabled = true): number {
    const [tick, setTick] = useState(0);
    useEffect(() => {
        if (!enabled) return;
        const id = window.setInterval(() => setTick((t) => t + 1), 60_000);
        return () => window.clearInterval(id);
    }, [enabled]);
    return tick;
}

/** "38m", "3h 20m", "4d" — compact spans for chip copy. */
function formatSpan(minutes: number): string {
    const m = Math.max(1, Math.round(minutes));
    if (m < 60) return `${m}m`;
    if (m < 48 * 60) {
        const h = Math.floor(m / 60);
        const rest = m % 60;
        return rest ? `${h}h ${rest}m` : `${h}h`;
    }
    return `${Math.round(m / (60 * 24))}d`;
}

/**
 * Which clock is live (response until the first public agent reply,
 * resolution after) and how long is left on it. Hidden once met or settled;
 * "paused" while the ball is with the requester.
 */
export function SlaChip({ ticket }: { ticket: SlaFields }) {
    const live =
        !SETTLED.includes(ticket.status) &&
        ticket.sla_state !== 'met' &&
        ticket.status !== 'waiting';
    useMinuteTick(live);

    if (SETTLED.includes(ticket.status) || ticket.sla_state === 'met')
        return null;

    const due = ticket.first_responded_at
        ? ticket.resolution_due_at
        : (ticket.first_response_due_at ?? ticket.resolution_due_at);
    const clock =
        ticket.first_responded_at || !ticket.first_response_due_at
            ? 'Resolution'
            : 'Response';
    if (!due) return null; // legacy row — no targets stamped

    if (ticket.status === 'waiting') {
        return (
            <StatusBadge variant="neutral" size="sm">
                <CirclePause className="mr-1 h-3 w-3" /> Clock paused — with
                requester
            </StatusBadge>
        );
    }

    const remaining = (new Date(due).getTime() - Date.now()) / 60_000;

    if (ticket.sla_state === 'breached') {
        return (
            <StatusBadge variant="critical" size="sm">
                <TimerOff className="mr-1 h-3 w-3" />
                {clock} overdue
                {remaining < 0 ? ` ${formatSpan(-remaining)}` : ''}
            </StatusBadge>
        );
    }
    if (ticket.sla_state === 'at_risk') {
        return (
            <StatusBadge variant="warning" size="sm">
                <Timer className="mr-1 h-3 w-3" />
                {remaining > 0
                    ? `${clock} due in ${formatSpan(remaining)}`
                    : `${clock} due now`}
            </StatusBadge>
        );
    }

    // ok — a banked waiting-pause can leave the raw stamp in the past while
    // the effective (server-side) clock is still fine; don't shout overdue.
    return (
        <StatusBadge variant="neutral" size="sm">
            <Clock className="mr-1 h-3 w-3" />
            {remaining > 0
                ? `${clock} due in ${formatSpan(remaining)}`
                : 'On track'}
        </StatusBadge>
    );
}
